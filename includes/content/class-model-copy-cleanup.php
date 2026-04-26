<?php
/**
 * Model Copy Cleanup — deterministic final-pass cleanup for generated model
 * page content (v5.8.8-model-copy-cleanup).
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
 *
 *   C. Specific heading rewrites (configured below in HEADING_REWRITES).
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
			'replace' => 'Before You Click',
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

		// Part D targeted rewrites (sentence-level) before the family cap so
		// the rewritten sentences don't count.
		$body = self::apply_targeted_rewrites( $body );

		// Part C heading rewrites.
		$body = self::rewrite_headings( $body );

		// Part E + Part F + Part B2/B4: paragraph dedup across the body.
		$body = self::remove_duplicate_paragraphs( $body );

		// Part D + Part B1: cap the review-pass family at 2 combined occurrences.
		$body = self::cap_review_pass_language( $body );

		// Part B5: simplify adjacent same-level headings sharing a prefix.
		$body = self::simplify_adjacent_repeated_headings( $body );

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

	// ─── Stage 2: heading rewrites (Part C + Part B3) ───────────────────────

	private static function rewrite_headings( string $html ): string {
		return (string) preg_replace_callback(
			'#<(h[1-6])([^>]*)>(.*?)</\1>#is',
			static function ( array $m ): string {
				$tag    = $m[1];
				$attrs  = $m[2];
				$inner  = trim( wp_strip_tags_safe( $m[3] ) );
				if ( $inner === '' ) {
					return $m[0];
				}
				$rewritten = self::rewrite_heading_text( $inner );
				if ( $rewritten === $inner ) {
					return $m[0];
				}
				return '<' . $tag . $attrs . '>' . $rewritten . '</' . $tag . '>';
			},
			$html
		);
	}

	private static function rewrite_heading_text( string $text ): string {
		$text = trim( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		foreach ( self::HEADING_REWRITES as $rule ) {
			$out = preg_replace( $rule['pattern'], $rule['replace'], $text, 1 );
			if ( is_string( $out ) && $out !== $text ) {
				$text = trim( $out );
				// One rewrite per heading is enough.
				break;
			}
		}
		// Tidy stray punctuation/whitespace artefacts.
		$text = (string) preg_replace( '#\\s{2,}#u', ' ', $text );
		$text = (string) preg_replace( '#[\s,;:]+$#u', '', $text );
		return trim( $text );
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
