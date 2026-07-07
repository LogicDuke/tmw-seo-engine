<?php
/**
 * Model Copy Cleanup — deterministic final-pass cleanup for generated model
 * page content (v5.8.10-final-model-copy-polish).
 *
 * Runs at every model-content save site IMMEDIATELY AFTER
 * ModelResearchEvidence::prepend_sections() so it sees the evidence block at
 * the top and explicitly preserves it without modification.
 *
 * Responsibilities (all deterministic — no LLM calls):
 *   B. Final cleanup
 *      1. Cap "review pass" / "latest review pass" / "operator review" /
 *         "latest operator review" combined occurrences to 2 per page.
 *      2. Remove duplicate paragraphs and paragraphs with highly similar
 *         openings (≥ ~60 normalised chars match).
 *      3. Simplify keyword-stuffed headings (Part C).
 *      4. Remove repeated checklist intro paragraphs.
 *      5. Strip a shared keyword-phrase prefix from adjacent same-level
 *         headings (e.g. two consecutive H2s starting with "Live Cam Show").
 *      6. (v5.8.10) Drop the second of two adjacent headings (any H2/H3/H4
 *         level) when their normalised inner text is identical — preserves
 *         the body content beneath the dropped heading verbatim.
 *      7. (v5.8.10) Remove keyword-stuffed cam-show sentence inside any
 *         paragraph: "This is (especially) useful when (you are) researching
 *         {model} cam show." — model-name-agnostic.
 *      8. (v5.8.10) Drop redundant Official Links explanatory paragraphs:
 *           - "Official platform profiles are listed here ..."
 *           - "Verified destinations grouped by platform family ..."
 *         Real anchors and grouped link blocks are never touched.
 *
 *   C. Specific heading rewrites (configured below in HEADING_REWRITES) plus
 *      v5.8.10 model-keyword heading rewrites (configured in
 *      MODEL_KEYWORD_HEADING_REWRITES) that turn standalone "{Model} LiveJasmin"
 *      / "{Model} Webcam Chat" / "{Model} Live Cam" headings into neutral
 *      human headings ("LiveJasmin Access Notes" / "Webcam Chat Notes" /
 *      "Live Cam Access"). Works for any model name.
 *
 *   D. Review-pass language cleanup (worked into the cap in B1, plus targeted
 *      rewrites of two known robotic templates from the audit corpus).
 *
 *   E. Duplicate paragraph cleanup — handled by B2.
 *
 *   F. Link section overlap reduction — handled by B2 (paragraph dedup runs
 *      across the whole document, including the verified-link sections, and
 *      only removes standalone explanatory <p> paragraphs that contain no
 *      <a> link, no <ul>/<ol>/<li>, no <table>, and no <h*> tag).
 *
 * Hard guarantees:
 *   - Evidence block (between MARKER_START..MARKER_END) is split out before
 *     cleanup and restored verbatim afterwards.
 *   - Verified-link rendering is preserved: paragraphs with anchors are never
 *     removed, never rewritten, never reordered.
 *   - The manual table of contents is never touched (TOC has no <p> bodies
 *     and is wrapped in its own structural HTML).
 *
 * @package TMWSEO\Engine\Content
 * @since   5.8.8
 * @updated 5.8.10-final-model-copy-polish
 */

namespace TMWSEO\Engine\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ModelCopyCleanup {

	/** Combined cap across the four review-pass family phrases. */
	const REVIEW_PASS_CAP = 2;

	/** Heading-rewrite rules. Order matters: most specific first. */
	const HEADING_REWRITES = [
		// Part C explicit examples ────────────────────────────────────────
		[
			'pattern' => '#^Features\s+and\s+Platform\s+Experience\b.*$#iu',
			'replace' => 'Features and Platform Experience',
		],
		[
			'pattern' => '#^Feature\s+check\s+for\s+how\s+to\s+watch\s+live\s+webcam\s+shows\b.*$#iu',
			'replace' => 'Live Show Feature Check',
		],
		[
			'pattern' => '#^Before\s+You\s+Click\b.*$#iu',
			'replace' => 'Before You Start a Session',
		],
		[
			'pattern' => '#^Where\s+Are\s+the\s+Official\s+Links\s+and\s+Other\s+Profiles\??\s*$#iu',
			'replace' => 'Official Links and Profiles',
		],
		// General keyword-stuffed heading patterns ────────────────────────
		// "X for {Model}" tail
		[
			'pattern' => '#^(.+?)\s+for\s+[^,<>]+?\s+and\s+how\s+to\s+(?:join|watch|find|access)\b.*$#iu',
			'replace' => '$1',
		],
		// trailing " and how to ..."
		[
			'pattern' => '#^(.{6,}?)\s+and\s+how\s+to\s+(?:join|watch|find|access)\b.*$#iu',
			'replace' => '$1',
		],
		// trailing " for {Model}" when followed by extra clauses
		[
			'pattern' => '#^(.{6,}?)\s+for\s+[^,<>]+?\s*[,:]\s+.+$#iu',
			'replace' => '$1',
		],
	];

	/**
	 * Specific paragraph-level rewrites (Part D worked examples).
	 * Run BEFORE the cap because they replace the whole sentence and the
	 * replacement no longer carries the family phrase.
	 */
	const TARGETED_REWRITES = [
		// "Where shown as non-active, the latest operator review marked that
		// destination ..."  →  "..., that destination is not currently treated
		// as a live-room entry."
		[
			'pattern' => '#Where\s+shown\s+as\s+non-active,\s*the\s+latest\s+operator\s+review\s+marked\s+that\s+destination[^.<]*\.#iu',
			'replace' => 'Where shown as non-active, that destination is not currently treated as a live-room entry.',
		],
		[
			'pattern' => '#This\s+section\s+covers\s+([^.<]+?)\s+as\s+part\s+of\s+the\s+verified\s+platform\s+and\s+access\s+information\s+on\s+this\s+page\.#iu',
			'replace' => 'For $1, start with the confirmed live profile first, then use the other listed profiles for updates or backup checks.',
		],
		[
			'pattern' => '#This\s+section\s+covers\s+([^.<]+?)\.#iu',
			'replace' => 'For $1, use the confirmed live profile first and keep the other listed profiles as backup.',
		],
	];

	/**
	 * v5.8.10 — sentence-level patterns that get DELETED from the body wherever
	 * they appear (inside any paragraph, with or without a leading space).
	 *
	 * These are keyword-stuffed connectors that add no value to the reader and
	 * trip Rank Math keyword-stuffing checks. Removing the sentence leaves the
	 * surrounding paragraph readable; if the paragraph becomes empty after
	 * removal, the empty <p> is dropped in a follow-up step.
	 *
	 * Each pattern is model-name-agnostic — `{model}` is matched generically
	 * as one or more non-period word-ish tokens before "cam show".
	 */
	const SENTENCE_DELETIONS = [
		// "This is especially useful when you are researching {model} cam show."
		// "This is especially useful when researching {model} cam show."
		// "This is useful when you are researching {model} cam show."
		// (any leading whitespace/single-space is also consumed so the host
		//  paragraph doesn't end up with a doubled space.)
		'#\s*This\s+is(?:\s+especially)?\s+useful\s+when(?:\s+you\s+are)?\s+researching\s+[^.<]*?cam\s+show\.\s*#iu',
		// "This also helps with {keyword} checks." / "This also helps when checking {keyword} ..."
		'#\s*This\s+also\s+helps\s+with\s+[^.<]*?\bchecks?\.\s*#iu',
		'#\s*This\s+also\s+helps\s+when\s+checking\s+[^.<]*?(?:\.\s*|$)#iu',
		// Repeated routing checklist lines that should not recur outside the
		// first routing section.
		'#\s*Start\s+with\s+the\s+confirmed\s+live\s+profile\s+when\s+you\s+want\s+room\s+entry[^.]*\.\s*#iu',
		'#\s*Keep\s+one\s+alternate\s+listed\s+profile\s+ready[^.]*\.\s*#iu',
		'#\s*Use\s+listed\s+profiles\s+to\s+avoid\s+copycat\s+pages[^.]*\.\s*#iu',
	];

	/**
	 * v5.8.10 — exact paragraph bodies that should be dropped from Official
	 * Links sections. These are explanatory paragraphs that repeat the same
	 * verified-destination idea already stated earlier in the page.
	 *
	 * Matched by NORMALISED text (lowercase, alphanumerics + spaces only) so
	 * variations in punctuation/case never escape the rule. Real link anchors,
	 * grouped link headings, and platform-family headings are NEVER touched —
	 * the existing paragraph-dedup guard already skips paragraphs containing
	 * <a>/<ul>/<ol>/<li>/<table>/<h*> tags.
	 */
	const OFFICIAL_LINKS_DROP_PARAGRAPHS = [
		'official platform profiles are listed here if you want to compare the rooms directly before choosing a watch link',
		'verified destinations grouped by platform family so each link reflects its real purpose',
		'verified profiles grouped by platform family so each link reflects its real purpose',
		'listed profiles grouped by platform family so each link reflects its real purpose',
	];

	/** Repetitive link-language phrases to soften after first strong usage. */
	const REPETITIVE_LINK_PHRASES = [
		'official profile links',
		'verified links',
		'verified destinations',
		'official links',
	];

	/** Low-value filler fragments that can be removed when non-essential. */
	const WEAK_FILLER_PATTERNS = [
		'#This makes it easier to decide where to start\.?#iu',
		'#That gives visitors a clearer path before clicking\.?#iu',
		'#The practical value is[^.]*\.?#iu',
		'#This keeps the experience focused[^.]*\.?#iu',
	];

	/** Remove internal operational labels from visitor-facing copy. */
	const INTERNAL_LABEL_REWRITES = [
		'#\bTruth-first\s+routing:\s*#iu' => '',
		'#\bDecision\s+clarity:\s*#iu'    => '',
		'#\bFair\s+platform\s+testing:\s*#iu' => '',
		'#\bIdentity\s+safety:\s*#iu'     => '',
		'#\bBackup\s+strategy:\s*#iu'     => '',
		'#\bVerification\s+notes:\s*#iu'  => '',
		'#\bLive-room\s+priority:\s*#iu'  => '',
		'#\bBackup\s+option:\s*#iu'       => '',
		'#\bPractical\s+focus:\s*#iu'     => '',
		'#\bPlatform\s+checks:\s*#iu'     => '',
		'#\bAvoid\s+copycat\s+pages:\s*#iu' => '',
	];

	/** Page-level routing/status repetition budget and soft rewrites. */
	const ROUTING_REPETITION_BUDGETS = [
		'verified'                  => 3,
		'destination'               => 2,
		'active live-room destination' => 1,
		'non-active'                => 1,
		'status can change'         => 2,
		'recheck'                   => 2,
		'backup checks'             => 2,
		'live-room button'          => 3,
		'start with the confirmed live profile first' => 1,
	];

	/**
	 * v5.8.10 — model-keyword heading rewrites.
	 *
	 * Replace standalone keyword-stuffed headings of the form
	 * "<model name> Livejasmin", "<model name> LiveJasmin",
	 * "<model name> Webcam Chat", "<model name> Live Cam" with neutral human
	 * headings. Works for any model name — when a model name is supplied to
	 * cleanup() it is matched explicitly, otherwise a generic "1–4 word name
	 * token" pattern is used as a fallback.
	 *
	 *   "{Model} Livejasmin"   → "LiveJasmin Access Notes"
	 *   "{Model} LiveJasmin"   → "LiveJasmin Access Notes"
	 *   "{Model} Webcam Chat"  → "Webcam Chat Notes"
	 *   "{Model} Live Cam"     → "Live Cam Access"
	 *
	 * Each rule is keyed by the trailing keyword phrase and the replacement
	 * heading text. The cleanup() entry point assembles the full anchored
	 * pattern at runtime depending on whether $model_name is provided.
	 */
	const MODEL_KEYWORD_HEADING_REWRITES = [
		// Order matters: longer/more-specific suffixes first so "Live Cam"
		// doesn't accidentally consume what should be a "Webcam Chat" or
		// "LiveJasmin" match.
		[ 'suffix' => 'Webcam Chat', 'replace' => 'Webcam Chat Notes'   ],
		[ 'suffix' => 'LiveJasmin',  'replace' => 'LiveJasmin Access Notes' ],
		[ 'suffix' => 'Livejasmin',  'replace' => 'LiveJasmin Access Notes' ],
		[ 'suffix' => 'Live Cam',    'replace' => 'Live Cam Access'     ],
	];

	// ─── Public API ─────────────────────────────────────────────────────────

	/**
	 * Final-pass cleanup. Idempotent — running it twice produces the same
	 * output as running it once.
	 */
	public static function cleanup( string $html, string $model_name = '' ): string {
		if ( $html === '' || trim( $html ) === '' ) {
			return $html;
		}

		[ $evidence_block, $body ] = self::split_off_evidence_block( $html );

		// Part D targeted rewrites + v5.8.10 sentence deletions before the
		// family cap so the rewritten/removed sentences don't count.
		$body = self::apply_targeted_rewrites( $body );
		$body = self::rewrite_keyword_check_fillers( $body );
		$body = self::apply_sentence_deletions( $body );
		$body = self::dedupe_latest_check_sentences( $body );
		$body = self::drop_official_links_explanatory_paragraphs( $body );
		$body = self::rewrite_internal_safety_labels( $body );
		$body = self::soften_repetitive_link_language( $body );
		$body = self::cleanup_repeated_openers( $body );
		$body = self::apply_routing_repetition_budget( $body );
		$body = self::repair_grammar_artifacts( $body );
		$body = self::compact_faq_answers( $body );
		$body = self::remove_weak_evidence_padding( $body );

		// Part C heading rewrites (model-aware in v5.8.10).
		$body = self::rewrite_headings( $body, $model_name );

		// v5.8.10 — drop the second of two adjacent identical headings AFTER
		// rewrites so e.g. <h2>Before You Start a Session with {Name}</h2><h3>Before You Start a Session with {Name}</h3>
		// (produced by the rewrite pass) collapses into one heading.
		$body = self::remove_duplicate_adjacent_headings( $body );
		$body = self::remove_duplicate_adjacent_heading_prefix( $body, 'Before You Start a Session' );

		// Part E + Part F + Part B2/B4: paragraph dedup across the body.
		$body = self::remove_duplicate_paragraphs( $body );

		// Part D + Part B1: cap the review-pass family at 2 combined occurrences.
		$body = self::cap_review_pass_language( $body );

		// Part B5: simplify adjacent same-level headings sharing a prefix.
		$body = self::simplify_adjacent_repeated_headings( $body );

		// v5.8.10 — drop any paragraphs that became empty as a side-effect of
		// sentence deletions above. Runs last so we catch all empties.
		$body = self::drop_empty_paragraphs( $body );

		// Final whitespace tidy.
		$body = (string) preg_replace( "/\n{3,}/", "\n\n", $body );
		$body = trim( $body );

		if ( $evidence_block !== '' ) {
			$separator = ( $body === '' || $body[0] === "\n" ) ? '' : "\n\n";
			return $evidence_block . $separator . $body;
		}

		return $body;
	}

	// ─── Stage 0: evidence block isolation ──────────────────────────────────

	/**
	 * Split off the evidence block so cleanup never touches its contents.
	 *
	 * @return array{0:string,1:string} [evidence_block_with_trailing_ws, body]
	 */
	private static function split_off_evidence_block( string $html ): array {
		$start = ModelResearchEvidence::MARKER_START;
		$end   = ModelResearchEvidence::MARKER_END;
		$pattern = '#(' . preg_quote( $start, '#' ) . '.*?' . preg_quote( $end, '#' ) . ')\s*#s';

		if ( preg_match( $pattern, $html, $m, PREG_OFFSET_CAPTURE ) === 1 ) {
			$block = $m[1][0];
			$body  = substr( $html, 0, $m[0][1] ) . substr( $html, $m[0][1] + strlen( $m[0][0] ) );
			return [ $block, ltrim( $body ) ];
		}

		return [ '', $html ];
	}

	// ─── Stage 1: targeted rewrites (Part D) ────────────────────────────────

	private static function apply_targeted_rewrites( string $html ): string {
		foreach ( self::TARGETED_REWRITES as $rule ) {
			$html = (string) preg_replace( $rule['pattern'], $rule['replace'], $html );
		}
		return $html;
	}

	private static function rewrite_internal_safety_labels( string $html ): string {
		foreach ( self::INTERNAL_LABEL_REWRITES as $pattern => $replace ) {
			$html = (string) preg_replace( $pattern, $replace, $html );
		}
		return $html;
	}

	// ─── Stage 2: heading rewrites (Part C + Part B3) ───────────────────────

	private static function rewrite_headings( string $html, string $model_name = '' ): string {
		return (string) preg_replace_callback(
			'#<(h[1-6])([^>]*)>(.*?)</\1>#is',
			static function ( array $m ) use ( $model_name ): string {
				$tag    = $m[1];
				$attrs  = $m[2];
				$inner  = trim( wp_strip_tags_safe( $m[3] ) );
				if ( $inner === '' ) {
					return $m[0];
				}
				$rewritten = self::rewrite_heading_text( $inner, $model_name );
				if ( $rewritten === $inner ) {
					return $m[0];
				}
				return '<' . $tag . $attrs . '>' . $rewritten . '</' . $tag . '>';
			},
			$html
		);
	}

	private static function rewrite_heading_text( string $text, string $model_name = '' ): string {
		$text = trim( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		// v5.8.10 — model-keyword heading rewrites run FIRST so that exact
		// matches like "Anisyia Livejasmin" don't get partially eaten by a
		// generic HEADING_REWRITES rule and end up as e.g. "Anisyia".
		$model_rewrite = self::apply_model_keyword_heading_rewrite( $text, $model_name );
		if ( $model_rewrite !== null ) {
			$text = $model_rewrite;
		} else {
			foreach ( self::HEADING_REWRITES as $rule ) {
				$out = preg_replace( $rule['pattern'], $rule['replace'], $text, 1 );
				if ( is_string( $out ) && $out !== $text ) {
					$text = trim( $out );
					// One rewrite per heading is enough.
					break;
				}
			}
		}

		if ( preg_match( '#^Before\s+You\s+Start\s+a\s+Session\s*$#iu', $text ) && trim( $model_name ) !== '' ) {
			$text = 'Before You Start a Session with ' . trim( $model_name );
		}

		// Tidy stray punctuation/whitespace artefacts.
		$text = (string) preg_replace( '#\\s{2,}#u', ' ', $text );
		$text = (string) preg_replace( '#[\s,;:]+$#u', '', $text );
		return trim( $text );
	}

	/**
	 * v5.8.10 — model-keyword heading rewrite.
	 *
	 * Matches a heading whose body is exactly "<model-name-token> <suffix>"
	 * (case-insensitive, whitespace-tolerant). Returns the neutral
	 * replacement heading if matched, or null if no rule applied.
	 *
	 * Two matching modes:
	 *   1. If $model_name is supplied, the head segment must equal that exact
	 *      model name (whitespace-flexible, case-insensitive). This is the
	 *      precise path and is preferred whenever we have the post title.
	 *   2. Fallback (no $model_name supplied): the head segment may be 1–4
	 *      "name-shaped" word tokens (letters, digits, apostrophes, hyphens,
	 *      diacritics). This covers regenerated content where the model name
	 *      isn't threaded through.
	 *
	 * In both modes, the heading must START with the name, have the suffix
	 * directly after it, and end immediately after the suffix — extra trailing
	 * words ("LiveJasmin and Stripchat") are NOT consumed by this rule and
	 * fall through to the generic HEADING_REWRITES rules instead.
	 */
	private static function apply_model_keyword_heading_rewrite( string $text, string $model_name ): ?string {
		$head_pattern = self::build_model_name_pattern( $model_name );
		if ( $head_pattern === '' ) {
			return null;
		}
		foreach ( self::MODEL_KEYWORD_HEADING_REWRITES as $rule ) {
			$suffix = preg_quote( (string) $rule['suffix'], '#' );
			// Allow flexible internal whitespace inside the suffix (e.g.
			// "Webcam  Chat").
			$suffix_flex = (string) preg_replace( '#\\\\\s#', '\\s+', $suffix );
			$pattern = '#^' . $head_pattern . '\s+' . $suffix_flex . '\s*$#iu';
			if ( preg_match( $pattern, $text ) === 1 ) {
				return (string) $rule['replace'];
			}
		}
		return null;
	}

	/**
	 * Build the head-segment regex for the model-keyword heading rewrites.
	 *
	 * If $model_name is non-empty, returns the literal model-name pattern
	 * (whitespace-flexible). Otherwise returns a generic "1–4 name-shaped
	 * tokens" pattern.
	 */
	private static function build_model_name_pattern( string $model_name ): string {
		$name = trim( $model_name );
		if ( $name !== '' ) {
			$quoted = preg_quote( $name, '#' );
			$flex   = (string) preg_replace( '#\\\\\s+#', '\\s+', $quoted );
			return '(?:' . $flex . ')';
		}
		// Fallback: 1–4 name-shaped tokens. Each token is letters + optional
		// apostrophe/hyphen suffixes; tokens are separated by single spaces.
		$token = "[\\p{L}\\p{N}][\\p{L}\\p{N}'\\-]{0,30}";
		return '(?:' . $token . '(?:\\s+' . $token . '){0,3})';
	}

	// ─── Stage 3: paragraph dedup (Part E / B2 / B4 / F) ────────────────────

	/**
	 * Remove duplicate or near-duplicate <p> paragraphs.
	 *
	 * Only acts on standalone <p> blocks that:
	 *   - contain no <a> tag (preserves verified link rendering),
	 *   - contain no <ul>/<ol>/<li>/<table>/<h\d> tag,
	 *   - have a meaningful body (≥ 6 normalised words).
	 *
	 * Matching strategy:
	 *   - exact normalised text match → drop the duplicate,
	 *   - first 60 normalised characters match → drop as near-duplicate.
	 */
	private static function remove_duplicate_paragraphs( string $html ): string {
		if ( strpos( $html, '<p' ) === false ) {
			return $html;
		}

		$seen_full   = []; // normalised body → true
		$seen_prefix = []; // first-60-normalised-chars → true

		return (string) preg_replace_callback(
			'#<p\b[^>]*>(.*?)</p>#is',
			static function ( array $m ) use ( &$seen_full, &$seen_prefix ): string {
				$inner = $m[1];

				// Skip paragraphs that hold link rendering or structural markup.
				if ( preg_match( '#<(?:a|ul|ol|li|table|tr|td|th|h[1-6])\\b#i', $inner ) ) {
					return $m[0];
				}

				$norm = self::normalise_paragraph_text( $inner );
				if ( $norm === '' ) {
					return $m[0];
				}

				// Need ≥ 6 words to be considered for dedup; very short paragraphs
				// (one-liners like "See below.") are left alone.
				if ( str_word_count( $norm ) < 6 ) {
					return $m[0];
				}

				if ( isset( $seen_full[ $norm ] ) ) {
					return ''; // exact duplicate — drop
				}

				$prefix = substr( $norm, 0, 60 );
				if ( $prefix !== '' && isset( $seen_prefix[ $prefix ] ) ) {
					return ''; // near-duplicate opening — drop
				}

				$seen_full[ $norm ]     = true;
				$seen_prefix[ $prefix ] = true;
				return $m[0];
			},
			$html
		);
	}

	private static function normalise_paragraph_text( string $html ): string {
		$text = wp_strip_tags_safe( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = strtolower( $text );
		$text = (string) preg_replace( '#\\s+#u', ' ', $text );
		$text = (string) preg_replace( '#[^a-z0-9 ]+#u', '', $text );
		return trim( $text );
	}

	// ─── Stage 4: review-pass family cap (Part D / B1) ──────────────────────

	/**
	 * Cap combined occurrences of the review-pass language family at
	 * REVIEW_PASS_CAP. After the cap, replace remaining occurrences with
	 * neutral alternatives.
	 *
	 * Order: longer phrases first so "latest review pass" is consumed before
	 * "review pass".
	 */
	private static function cap_review_pass_language( string $html ): string {
		$family = [
			// pattern (case-insensitive)            => replacement after cap
			'#\\blatest\\s+operator\\s+review\\b#iu' => 'latest check',
			'#\\blatest\\s+review\\s+pass\\b#iu'     => 'latest check',
			'#\\boperator\\s+review\\b#iu'           => 'manual check',
			'#\\breview\\s+pass\\b#iu'               => 'check',
		];

		$count = 0;
		foreach ( $family as $pattern => $replacement ) {
			$html = (string) preg_replace_callback(
				$pattern,
				static function ( array $m ) use ( &$count, $replacement ): string {
					$count++;
					if ( $count <= self::REVIEW_PASS_CAP ) {
						return $m[0]; // keep original wording within cap
					}
					return self::match_case( $m[0], $replacement );
				},
				$html
			);
		}

		return $html;
	}

	/**
	 * Match the leading-letter case of $original onto $replacement so
	 * "Review pass" → "Check" (not "check") at the start of a sentence.
	 */
	private static function match_case( string $original, string $replacement ): string {
		if ( $original === '' || $replacement === '' ) {
			return $replacement;
		}
		$first = $original[0];
		if ( ctype_upper( $first ) ) {
			return ucfirst( $replacement );
		}
		return $replacement;
	}

	// ─── Stage 1b (v5.8.10): sentence-level deletions ───────────────────────

	/**
	 * Remove keyword-stuffed connector sentences from the body wherever they
	 * appear. Patterns are anchored on sentence boundaries so we never eat
	 * partial words. Surrounding paragraph text is preserved; if a paragraph
	 * is left empty by deletion, drop_empty_paragraphs() removes the empty
	 * <p> wrapper at the end of the cleanup pipeline.
	 */
	private static function apply_sentence_deletions( string $html ): string {
		foreach ( self::SENTENCE_DELETIONS as $pattern ) {
			$html = (string) preg_replace( $pattern, ' ', $html );
		}
		// Tidy any double-spaces introduced by the deletion (single-line only,
		// don't touch newlines).
		$html = (string) preg_replace( '#[ \t]{2,}#', ' ', $html );
		// Tidy " ." or " ," artefacts left behind when a deletion sits between
		// the host sentence and its closing punctuation.
		$html = (string) preg_replace( '#\s+([.,])#', '$1', $html );
		return $html;
	}

	/**
	 * Rewrite mechanical secondary-keyword filler into practical guidance.
	 * Keeps the keyword phrase and removes "checks" slot-filler wording.
	 */
	private static function rewrite_keyword_check_fillers( string $html ): string {
		if ( strpos( $html, '<p' ) === false ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'#(<p\b[^>]*>)(.*?)(</p>)#is',
			static function ( array $m ): string {
				$inner = $m[2];
				if ( preg_match( '#<(?:a|ul|ol|li|table|tr|td|th|h[1-6])\b#i', $inner ) ) {
					return $m[0];
				}
				if ( preg_match( '#</?(?:strong|em|span|br|code|mark|small|sup|sub|b|i)\b#i', $inner ) ) {
					return $m[0];
				}
				$text = trim( wp_strip_tags_safe( $inner ) );
				if ( $text === '' ) {
					return $m[0];
				}

				$text = preg_replace(
					'#This\s+also\s+helps\s+with\s+([^.<]+?)\s+checks?\.?#iu',
					'For $1 searches, focus on room freshness, chat readability, and mobile usability before joining.',
					$text
				) ?? $text;
				$text = preg_replace(
					'#This\s+also\s+helps\s+when\s+checking\s+([^.<]+?)\s+across\s+listed\s+profiles\.?#iu',
					'When checking $1 links, use the grouped profiles to separate live access from fan, social, and link-hub pages.',
					$text
				) ?? $text;
				$text = preg_replace(
					'#This\s+also\s+helps\s+when\s+checking\s+([^.<]+?)\.?#iu',
					'For $1 searches, focus on room freshness, chat readability, and mobile usability before joining.',
					$text
				) ?? $text;

				$text = trim( preg_replace( '#\s{2,}#u', ' ', $text ) ?? $text );
				return $text === '' ? '' : $m[1] . $text . $m[3];
			},
			$html
		);
	}

	/**
	 * Keep the first "Latest check" style profile-count sentence only.
	 *
	 * v5.8.11-final-copy: extended detection so phrases that bypass the old
	 * "Latest check:" / "profile links found" / "live profile confirmed"
	 * triggers no longer slip through unchecked. Newly recognised phrasings:
	 *   - "latest grouped link check"
	 *   - "latest automated review"
	 *   - "latest review"
	 *   - "grouped link check"
	 * The primary fix lives in TemplateContent (so the duplicates aren't
	 * generated in the first place); this widens the safety net.
	 */
	private static function dedupe_latest_check_sentences( string $html ): string {
		if ( strpos( $html, '<p' ) === false ) {
			return $html;
		}
		$seen_latest = false;
		return (string) preg_replace_callback(
			'#(<p\b[^>]*>)(.*?)(</p>)#is',
			static function ( array $m ) use ( &$seen_latest ): string {
				$inner = $m[2];
				if ( preg_match( '#<(?:a|ul|ol|li|table|tr|td|th|h[1-6])\b#i', $inner ) ) {
					return $m[0];
				}
				$text = trim( wp_strip_tags_safe( $inner ) );
				if ( $text === '' ) {
					return $m[0];
				}
				$is_latest = preg_match( '#\bLatest\s+check:\s*\d+\s+profile\s+links?\s+found\b#iu', $text ) === 1
					|| preg_match( '#\bprofile\s+links?\s+found\b#iu', $text ) === 1
					|| preg_match( '#\blive\s+profile\s+confirmed\b#iu', $text ) === 1
					|| preg_match( '#\blatest\s+grouped\s+link\s+check\b#iu', $text ) === 1
					|| preg_match( '#\blatest\s+automated\s+review\b#iu', $text ) === 1
					|| preg_match( '#\bgrouped\s+link\s+check\b#iu', $text ) === 1
					|| preg_match( '#\blatest\s+review\b#iu', $text ) === 1;
				if ( ! $is_latest ) {
					return $m[0];
				}
				if ( ! $seen_latest ) {
					$seen_latest = true;
					return $m[0];
				}
				$text = preg_replace( '#\bLatest\s+check:\s*[^.]*\.\s*#iu', '', $text ) ?? $text;
				$text = preg_replace( '#\b\d+\s+profile\s+links?\s+found[^.]*\.\s*#iu', '', $text ) ?? $text;
				$text = preg_replace( '#\bwith\s+\d+\s+live\s+profile[s]?\s+confirmed[^.]*\.\s*#iu', '', $text ) ?? $text;
				$text = preg_replace( '#[^.]*\blatest\s+grouped\s+link\s+check\b[^.]*\.\s*#iu', '', $text ) ?? $text;
				$text = preg_replace( '#[^.]*\blatest\s+automated\s+review\b[^.]*\.\s*#iu', '', $text ) ?? $text;
				$text = preg_replace( '#[^.]*\bgrouped\s+link\s+check\b[^.]*\.\s*#iu', '', $text ) ?? $text;
				$text = preg_replace( '#[^.]*\blatest\s+review\b[^.]*\.\s*#iu', '', $text ) ?? $text;
				$text = trim( preg_replace( '#\s{2,}#u', ' ', $text ) ?? $text );
				return $text === '' ? '' : $m[1] . $text . $m[3];
			},
			$html
		);
	}

	// ─── Stage 1c (v5.8.10): Official Links explanatory paragraph drop ──────

	/**
	 * Drop standalone <p>...</p> blocks whose normalised text exactly matches
	 * one of the OFFICIAL_LINKS_DROP_PARAGRAPHS entries.
	 *
	 * Skips any paragraph that:
	 *   - contains a link anchor (<a>),
	 *   - contains list/table/heading markup,
	 * so the verified-link rendering, grouped link headings, and platform-
	 * family blocks are never touched.
	 */
	private static function drop_official_links_explanatory_paragraphs( string $html ): string {
		if ( strpos( $html, '<p' ) === false ) {
			return $html;
		}

		$drop_set = [];
		foreach ( self::OFFICIAL_LINKS_DROP_PARAGRAPHS as $needle ) {
			$drop_set[ $needle ] = true;
		}

		return (string) preg_replace_callback(
			'#<p\b[^>]*>(.*?)</p>#is',
			static function ( array $m ) use ( $drop_set ): string {
				$inner = $m[1];

				// Never touch link rendering or structural markup.
				if ( preg_match( '#<(?:a|ul|ol|li|table|tr|td|th|h[1-6])\\b#i', $inner ) ) {
					return $m[0];
				}

				$norm = self::normalise_paragraph_text( $inner );
				if ( $norm === '' ) {
					return $m[0];
				}

				if ( isset( $drop_set[ $norm ] ) ) {
					return ''; // drop the whole <p>
				}

				return $m[0];
			},
			$html
		);
	}

	/**
	 * Soften repetitive verified/offical link language after first use.
	 */
	private static function soften_repetitive_link_language( string $html ): string {
		if ( strpos( $html, '<p' ) === false ) {
			return $html;
		}
		$seen = [];
		return (string) preg_replace_callback(
			'#(<p\b[^>]*>)(.*?)(</p>)#is',
			static function ( array $m ) use ( &$seen ): string {
				$open  = $m[1];
				$inner = $m[2];
				$close = $m[3];
				if ( preg_match( '#<(?:a|ul|ol|li|table|tr|td|th|h[1-6])\\b#i', $inner ) ) {
					return $m[0];
				}
				if ( preg_match( '#</?(?:strong|em|span|br|code|mark|small|sup|sub|b|i)\b#i', $inner ) ) {
					return $m[0];
				}
				$text = wp_strip_tags_safe( $inner );
				foreach ( self::REPETITIVE_LINK_PHRASES as $phrase ) {
					$key = strtolower( $phrase );
					if ( stripos( $text, $phrase ) === false ) {
						continue;
					}
					$seen[ $key ] = ( $seen[ $key ] ?? 0 ) + 1;
					if ( $seen[ $key ] <= 1 ) {
						continue;
					}
					$replacement = ( $phrase === 'official profile links' ) ? 'listed profiles' : 'the links below';
					$text = (string) preg_replace( '#\b' . preg_quote( $phrase, '#' ) . '\b#iu', $replacement, $text, 1 );
				}
				return $open . trim( $text ) . $close;
			},
			$html
		);
	}

	/**
	 * Rewrite repeated "page about page" openers into direct user-action wording.
	 */
	private static function cleanup_repeated_openers( string $html ): string {
		if ( strpos( $html, '<p' ) === false ) {
			return $html;
		}
		return (string) preg_replace_callback(
			'#(<p\b[^>]*>)(.*?)(</p>)#is',
			static function ( array $m ): string {
				$open  = $m[1];
				$inner = $m[2];
				$close = $m[3];
				if ( preg_match( '#<(?:a|ul|ol|li|table|tr|td|th|h[1-6])\\b#i', $inner ) ) {
					return $m[0];
				}
				if ( preg_match( '#</?(?:strong|em|span|br|code|mark|small|sup|sub|b|i)\b#i', $inner ) ) {
					return $m[0];
				}
				$text = trim( wp_strip_tags_safe( $inner ) );
				if ( $text === '' ) {
					return $m[0];
				}

				$had_page_opener = false;
				if ( preg_match( '#^Use this page to\s+#iu', $text ) === 1 ) {
					$text = (string) preg_replace( '#^Use this page to\s+#iu', '', $text, 1 );
					$had_page_opener = true;
				} elseif ( preg_match( '#^Use this page(?: as [^:]+)?[:,-]?\s*#iu', $text ) === 1 ) {
					$text = (string) preg_replace( '#^Use this page(?: as [^:]+)?[:,-]?\s*#iu', '', $text, 1 );
					$had_page_opener = true;
				} elseif ( preg_match( '#^This page includes\s+#iu', $text ) === 1 ) {
					$text = (string) preg_replace( '#^This page includes\s+#iu', '', $text, 1 );
					$had_page_opener = true;
				} elseif ( preg_match( '#^This page helps visitors\s+#iu', $text ) === 1 ) {
					$text = (string) preg_replace( '#^This page helps visitors\s+#iu', 'Visitors ', $text, 1 );
					$had_page_opener = true;
				} elseif ( preg_match( '#^This guide helps(?: visitors| users)?\s+#iu', $text ) === 1 ) {
					$text = (string) preg_replace( '#^This guide helps(?: visitors| users)?\s+#iu', '', $text, 1 );
					$had_page_opener = true;
				} elseif ( stripos( $text, 'This section' ) === 0 ) {
					$text = (string) preg_replace( '#^This section\s+#iu', '', $text, 1 );
				}

				if ( $had_page_opener && self::is_routing_or_watch_context( $text ) ) {
					$text = 'Start with the live-room button, then ' . ltrim( $text );
				}

				$text = trim( $text );
				if ( preg_match( '#^This page routes you through (?:checked destination links|listed profile links).*(?:search friction)\.?$#iu', $text ) === 1 ) {
					return '';
				}
				if ( $text === '' ) {
					return '';
				}

				return $open . ucfirst( $text ) . $close;
			},
			$html
		);
	}

	/**
	 * Keep FAQ answers short by removing repeated verification explanation tails.
	 */
	private static function compact_faq_answers( string $html ): string {
		return (string) preg_replace_callback(
			'#(<h3[^>]*>.*?\?</h3>\s*<p\b[^>]*>)(.*?)(</p>)#is',
			static function ( array $m ): string {
				if ( preg_match( '#<(?:a|strong|em|span|br|code|mark|small|sup|sub|b|i)\b#i', $m[2] ) ) {
					return $m[0];
				}
				$answer = trim( wp_strip_tags_safe( $m[2] ) );
				$answer = (string) preg_replace( '#\bThese (?:links|destinations|profiles) are (?:verified|official)[^.]*\.\s*#iu', '', $answer, 1 );
				$sentences = preg_split( '#(?<=[.!?])\s+#', $answer ) ?: [];
				$sentences = array_values( array_filter( array_map( 'trim', $sentences ), 'strlen' ) );
				if ( count( $sentences ) > 2 ) {
					$sentences = array_slice( $sentences, 0, 2 );
				}
				$compact = implode( ' ', $sentences );
				return $m[1] . $compact . $m[3];
			},
			$html
		);
	}

	/**
	 * Apply a page-level repetition budget to common routing/status language.
	 */
	private static function apply_routing_repetition_budget( string $html ): string {
		if ( strpos( $html, '<p' ) === false ) {
			return $html;
		}
		$counts = [];
		return (string) preg_replace_callback(
			'#(<p\b[^>]*>)(.*?)(</p>)#is',
			static function ( array $m ) use ( &$counts ): string {
				$open  = $m[1];
				$inner = $m[2];
				$close = $m[3];
				if ( preg_match( '#<(?:a|ul|ol|li|table|tr|td|th|h[1-6])\b#i', $inner ) ) {
					return $m[0];
				}
				if ( preg_match( '#</?(?:strong|em|span|br|code|mark|small|sup|sub|b|i)\b#i', $inner ) ) {
					return $m[0];
				}
				$text = trim( wp_strip_tags_safe( $inner ) );
				if ( $text === '' ) {
					return $m[0];
				}

				$text = self::soften_routing_terms( $text );
				foreach ( self::ROUTING_REPETITION_BUDGETS as $term => $limit ) {
					$pattern = '#\b' . preg_quote( $term, '#' ) . '\b#iu';
					$occ = preg_match_all( $pattern, $text );
					if ( ! is_int( $occ ) || $occ <= 0 ) {
						continue;
					}
					$current_count = (int) ( $counts[ $term ] ?? 0 );
					$allowed = $limit - $current_count;
					$counts[ $term ] = $current_count + $occ;
					if ( $allowed >= $occ ) {
						continue;
					}
					if ( $allowed <= 0 ) {
						$replacement = self::routing_budget_replacement( $term );
						if ( $replacement !== '' ) {
							$text = (string) preg_replace( $pattern, $replacement, $text );
						}
						continue;
					}
				}
				$text = trim( (string) preg_replace( '#\s{2,}#u', ' ', $text ) );
				$text = trim( (string) preg_replace( '#\s+([,.;:])#u', '$1', $text ) );
				if ( $text === '' ) {
					return '';
				}
				return $open . $text . $close;
			},
			$html
		);
	}

	private static function soften_routing_terms( string $text ): string {
		$replacements = [
			'#\bchecked destination links\b#iu'             => 'listed profile links',
			'#\bchecked destinations\b#iu'                  => 'listed profiles',
			'#\bverified non-live destinations\b#iu'        => 'other profiles',
			'#\bactive live-room destination\b#iu'          => 'live profile',
			'#\blive-room destination\b#iu'                 => 'live profile',
			'#\bdestinations\b#iu'                          => 'profiles',
			'#\bdestination\b#iu'                           => 'profile',
		];
		foreach ( $replacements as $pattern => $replace ) {
			$text = (string) preg_replace( $pattern, $replace, $text );
		}
		return $text;
	}

	private static function routing_budget_replacement( string $term ): string {
		$map = [
			'verified' => 'checked',
			'destination' => 'profile',
			'active live-room destination' => 'live profile',
			'non-active' => 'currently non-live',
			'status can change' => 'availability may shift',
			'recheck' => 'double-check',
			'backup checks' => 'fallback checks',
			'live-room button' => 'live profile link',
			'start with the confirmed live profile first' => 'start with the confirmed live profile',
		];
		return (string) ( $map[ $term ] ?? '' );
	}

	private static function repair_grammar_artifacts( string $html ): string {
		$repairs = [
			'#\bor,\s*but\b#iu' => 'or but',
			'#\band,\s*but\b#iu' => 'and but',
			'#\bor,\s*[.]\b#iu' => '.',
			'#\band,\s*[.]\b#iu' => '.',
			'#\bfor follow,\s*support,\s*or,\b#iu' => 'for following or support',
			'#,\s*,+#u' => ', ',
			'#,\s*\.#u' => '.',
			'#\b(or|and)\s+but\b#iu' => 'but',
		];
		foreach ( $repairs as $pattern => $replace ) {
			$html = (string) preg_replace( $pattern, $replace, $html );
		}
		$html = (string) preg_replace( '#\s{2,}#u', ' ', $html );
		return $html;
	}

	/**
	 * Remove weak filler when it does not add a concrete claim.
	 */
	private static function remove_weak_evidence_padding( string $html ): string {
		if ( strpos( $html, '<p' ) === false ) {
			return $html;
		}
		return (string) preg_replace_callback(
			'#(<p\b[^>]*>)(.*?)(</p>)#is',
			static function ( array $m ): string {
				$open  = $m[1];
				$inner = $m[2];
				$close = $m[3];
				if ( preg_match( '#<(?:a|ul|ol|li|table|tr|td|th|h[1-6])\\b#i', $inner ) ) {
					return $m[0];
				}
				if ( preg_match( '#</?(?:strong|em|span|br|code|mark|small|sup|sub|b|i)\b#i', $inner ) ) {
					return $m[0];
				}
				$text = trim( wp_strip_tags_safe( $inner ) );
				foreach ( self::WEAK_FILLER_PATTERNS as $pattern ) {
					$text = (string) preg_replace( $pattern, '', $text );
				}
				$text = trim( preg_replace( '#\s{2,}#', ' ', $text ) ?? $text );
				return $text === '' ? '' : $open . $text . $close;
			},
			$html
		);
	}

	private static function is_routing_or_watch_context( string $text ): bool {
		return preg_match( '#\b(?:live-room|watch|join|route|routing|profile|destination|platform|button|room|links?)\b#iu', $text ) === 1;
	}

	// ─── Stage 2b (v5.8.10): adjacent duplicate heading drop ────────────────

	/**
	 * Drop the SECOND of two adjacent headings (any H2..H4 levels, possibly
	 * mixed) whose normalised inner text is identical.
	 *
	 * "Adjacent" here means: nothing but whitespace between the closing tag
	 * of the first heading and the opening tag of the second. Paragraphs,
	 * lists, or any other content between the two headings disqualifies the
	 * pair from this rule. The body content beneath the dropped heading is
	 * preserved verbatim — only the heading TAG is removed.
	 *
	 * Example:
	 *   <h2>Before You Start a Session with {Name}</h2><h3>Before You Start a Session with {Name}</h3><p>...</p>
	 * becomes:
	 *   <h2>Before You Start a Session with {Name}</h2><p>...</p>
	 *
	 * Iterative: re-runs until no more pairs collapse, so a chain of three
	 * identical adjacent headings collapses to one.
	 */
	private static function remove_duplicate_adjacent_headings( string $html ): string {
		$pattern = '#(<(h[2-4])([^>]*)>(.*?)</\2>)(\s*)<(h[2-4])([^>]*)>(.*?)</\6>#is';

		$guard = 0;
		while ( $guard++ < 50 ) {
			$replaced = preg_replace_callback(
				$pattern,
				static function ( array $m ): string {
					$first_full   = $m[1];
					$between_ws   = $m[5];
					$first_text   = self::normalise_heading_text_for_compare( $m[4] );
					$second_text  = self::normalise_heading_text_for_compare( $m[8] );

					if ( $first_text === '' || $second_text === '' ) {
						return $m[0];
					}
					if ( $first_text !== $second_text ) {
						return $m[0];
					}

					// Drop the second heading; keep the first verbatim and the
					// whitespace between, so any following content stays
					// attached cleanly.
					return $first_full . $between_ws;
				},
				$html
			);

			if ( ! is_string( $replaced ) || $replaced === $html ) {
				break;
			}
			$html = $replaced;
		}

		return $html;
	}

	/**
	 * Normalise a heading's inner text for adjacent-duplicate comparison.
	 * Lowercases, decodes entities, strips tags, collapses whitespace, and
	 * strips trailing punctuation so "Before You Start a Session" and "Before you start a session:"
	 * compare equal.
	 */
	private static function remove_duplicate_adjacent_heading_prefix( string $html, string $heading_prefix ): string {
		$prefix_pattern = preg_quote( $heading_prefix, '#' );
		$pattern = '#(<(h[2-4])([^>]*)>\s*(' . $prefix_pattern . '[^<]*)</\2>)(\s*)<(h[2-4])([^>]*)>\s*(' . $prefix_pattern . '[^<]*)</\6>#iu';

		$guard = 0;
		while ( $guard++ < 50 ) {
			$changed = false;
			$html = (string) preg_replace_callback(
				$pattern,
				static function ( array $m ) use ( &$changed ): string {
					$changed = true;
					return $m[1] . $m[5];
				},
				$html
			);

			if ( ! $changed ) {
				break;
			}
		}

		return $html;
	}

	private static function normalise_heading_text_for_compare( string $inner ): string {
		$text = wp_strip_tags_safe( $inner );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = strtolower( $text );
		$text = (string) preg_replace( '#\\s+#u', ' ', $text );
		$text = (string) preg_replace( '#[\s,;:.!?]+$#u', '', $text );
		return trim( $text );
	}

	// ─── Stage 6 (v5.8.10): empty-paragraph cleanup ─────────────────────────

	/**
	 * Drop any <p>...</p> whose inner content is empty or whitespace-only
	 * after the deletion stages have run. Idempotent — has no effect when
	 * there are no empty paragraphs.
	 */
	private static function drop_empty_paragraphs( string $html ): string {
		if ( strpos( $html, '<p' ) === false ) {
			return $html;
		}
		// Use ~ as the delimiter so the literal "&#160;" inside the pattern
		// doesn't close it prematurely. Keeps the rule readable and the
		// (string) cast safe — preg_replace returns null on malformed
		// patterns, which would silently obliterate the document.
		$out = preg_replace(
			'~<p\b[^>]*>\s*(?:&nbsp;|&#160;)?\s*</p>\s*~i',
			'',
			$html
		);
		return is_string( $out ) ? $out : $html;
	}

	// ─── Stage 5: adjacent heading keyword dedup (Part B5) ──────────────────

	/**
	 * If two adjacent same-level headings share a leading 2+ word phrase,
	 * strip the shared prefix from the SECOND heading.
	 *
	 * Example:
	 *   <h2>Live Cam Show Schedule</h2> <h2>Live Cam Show Highlights</h2>
	 *   → <h2>Live Cam Show Schedule</h2> <h2>Highlights</h2>
	 */
	private static function simplify_adjacent_repeated_headings( string $html ): string {
		return (string) preg_replace_callback(
			'#<(h[2-6])([^>]*)>([^<]+)</\1>(\\s*(?:<p[^>]*>.*?</p>\\s*)*)<\1([^>]*)>([^<]+)</\1>#is',
			static function ( array $m ): string {
				$tag         = $m[1];
				$first_attr  = $m[2];
				$first_text  = trim( $m[3] );
				$between     = $m[4];
				$second_attr = $m[5];
				$second_text = trim( $m[6] );

				$shared = self::shared_leading_phrase( $first_text, $second_text );
				if ( $shared === '' ) {
					return $m[0];
				}

				$trimmed = ltrim( substr( $second_text, strlen( $shared ) ) );
				$trimmed = ltrim( $trimmed, " :,;-—–" );
				if ( $trimmed === '' || str_word_count( $trimmed ) < 1 ) {
					// Don't empty out the heading; leave it alone.
					return $m[0];
				}

				return '<' . $tag . $first_attr . '>' . $first_text . '</' . $tag . '>'
					. $between
					. '<' . $tag . $second_attr . '>' . ucfirst( $trimmed ) . '</' . $tag . '>';
			},
			$html
		);
	}

	/**
	 * Return the longest leading shared word-phrase (≥ 2 words) between two
	 * heading texts. Empty string if no shared 2-word prefix.
	 */
	private static function shared_leading_phrase( string $a, string $b ): string {
		$wa = preg_split( '#\\s+#u', strtolower( trim( $a ) ) ) ?: [];
		$wb = preg_split( '#\\s+#u', strtolower( trim( $b ) ) ) ?: [];
		$max = min( count( $wa ), count( $wb ) );
		$shared = 0;
		for ( $i = 0; $i < $max; $i++ ) {
			if ( $wa[ $i ] === $wb[ $i ] ) {
				$shared++;
			} else {
				break;
			}
		}
		if ( $shared < 2 ) {
			return '';
		}
		// Reconstruct from the original second heading so casing is preserved
		// for the substring we'll strip.
		$words = preg_split( '#\\s+#u', trim( $b ) ) ?: [];
		return implode( ' ', array_slice( $words, 0, $shared ) );
	}
}

// Tiny shim: tests don't load wp_strip_all_tags from WordPress core.
if ( ! function_exists( __NAMESPACE__ . '\\wp_strip_tags_safe' ) ) {
	/**
	 * Strip HTML tags safely, even when WP core is not loaded.
	 */
	function wp_strip_tags_safe( string $s ): string {
		if ( function_exists( '\\wp_strip_all_tags' ) ) {
			return (string) \wp_strip_all_tags( $s );
		}
		return trim( strip_tags( $s ) );
	}
}
