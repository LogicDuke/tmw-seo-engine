<?php
/**
 * CategoryDraftComposer — Stage 5 of the universal category pipeline.
 *
 * Renders the planned sections into visitor-facing HTML from the universal
 * sentence library. Composition rules:
 *
 *  - A sentence that still contains an unresolved {{placeholder}} after
 *    substitution is DROPPED, never rendered — a sentence can therefore
 *    never claim data the category does not have.
 *  - Keyword placeholders ({{kw1}}, {{kw2}}) draw from the body-use queue;
 *    each body-use keyword is consumed at most once page-wide and at most
 *    two exact keywords can ever share a sentence.
 *  - Counts render as qualitative scale phrases derived from real counts,
 *    never as raw numbers that go stale.
 *  - All variant picks are deterministic from the plan's per-section seeds.
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 * @since   5.9.7
 */

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryDraftComposer {

	/** @var array|null */
	private static $library = null;

	/** Load (and cache) the universal section library. */
	public static function library(): array {
		if ( self::$library !== null ) { return self::$library; }
		$dir  = defined( 'TMWSEO_ENGINE_DATA_DIR' ) ? TMWSEO_ENGINE_DATA_DIR : dirname( __DIR__, 3 ) . '/data';
		$file = rtrim( (string) $dir, '/\\' ) . '/category-universal-sections.json';
		$raw  = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		$data = $raw !== '' ? json_decode( $raw, true ) : null;
		self::$library = is_array( $data ) ? $data : [ 'purposes' => [], 'intent_clarity' => [], 'scale_phrases' => [] ];
		return self::$library;
	}

	/** Test helper — reset the cache. */
	public static function reset_library_cache(): void {
		self::$library = null;
	}

	/**
	 * Compose the draft body HTML (sections only, FAQs are appended by the
	 * pipeline from the FAQ planner).
	 *
	 * @param array<string,mixed> $context      Stage 1 output.
	 * @param array<string,mixed> $plan         Stage 4 output.
	 * @param array<string,mixed> $keyword_plan Stage 3 output.
	 * @param array<string,mixed> $avoid        Cross-category cooldown sets: {
	 *     @type string[] $variants  "section:variant_id" ids used by recent pages.
	 *     @type string[] $sentences crc32 ids of sentence templates used recently.
	 * }
	 * @return array{html:string,sections:array<string,string>,used_keywords:string[],dropped_sentences:int,variant_ids:string[],sentence_ids:string[],intent_sections:string[],internal_links:array<int,array{href:string,anchor:string}>}
	 */
	public static function compose( array $context, array $plan, array $keyword_plan, array $avoid = [] ): array {
		$library = self::library();
		$intent  = (string) ( $plan['intent'] ?? 'broad_discovery' );

		// v5.9.16 — build the category semantic profile once and hang it on the
		// context so base_values() can expose subject/descriptor/format tokens
		// and the section loop can source category-specific sentences from it.
		if ( empty( $context['__semantic_profile'] ) ) {
			$context['__semantic_profile'] = CategorySemanticProfile::build(
				$context + [ 'intent' => $intent ],
				$keyword_plan
			);
		}
		$profile = (array) $context['__semantic_profile'];

		$avoid_variants  = array_fill_keys( array_map( 'strval', (array) ( $avoid['variants'] ?? [] ) ), true );
		$avoid_sentences = array_fill_keys( array_map( 'strval', (array) ( $avoid['sentences'] ?? [] ) ), true );
		$variant_ids     = [];
		$sentence_ids    = [];
		$intent_sections = [];

		// v5.9.9: only keywords with the 'body' role feed the sentence
		// queue — heading-role keywords are already placed by the planner's
		// heading assignment and never double-drawn here.
		$roles    = (array) ( $keyword_plan['roles'] ?? [] );
		$kw_queue = [];
		foreach ( (array) ( $keyword_plan['body_use'] ?? [] ) as $kw ) {
			if ( ( $roles[ $kw ] ?? 'body' ) === 'body' ) { $kw_queue[] = (string) $kw; }
		}
		$used_kws = [];
		$dropped  = 0;

		$clarity_id = '';
		$links      = [];
		$values     = self::base_values( $context, $intent, $plan, $avoid_sentences, $clarity_id, $links );
		if ( $clarity_id !== '' ) {
			foreach ( explode( ',', $clarity_id ) as $cid ) {
				$cid = trim( $cid );
				if ( $cid !== '' ) { $sentence_ids[] = $cid; }
			}
		}
		$links_used = [];

		$html            = '';
		$section_html    = [];
		$prev_used_kw    = false;
		foreach ( (array) ( $plan['sections'] ?? [] ) as $section ) {
			if ( $section === 'faq' ) {
				$section_html['faq'] = ''; // slot — filled by the pipeline
				continue;
			}
			$seed = (int) ( $plan['variant_seeds'][ $section ] ?? 0 );

			// v5.9.16 — category-semantic sentences are the PRIMARY source of
			// paragraph meaning. When the profile yields frames for this
			// section, wrap them as a single synthetic variant so the existing
			// keyword-queue / dedupe-glue / link-resolution machinery below runs
			// unchanged. The static JSON library is used only as a fallback when
			// the profile can't produce frames (degenerate/empty subject), so
			// nothing regresses and no boilerplate is emitted while a real
			// category subject exists.
			// v5.9.16 — category-semantic sentences are the PRIMARY source of
			// paragraph meaning, selected cooldown-aware: the paragraph variant
			// is the category-identity pick UNLESS a recent page already used
			// that variant id for this section, in which case the next unused
			// variant is taken. This spreads same-class categories across the
			// structural variants under a populated uniqueness store instead of
			// colliding by pigeonhole. The static JSON library is used only as a
			// fallback when the profile can't produce frames.
			$sem_count = CategorySemanticSections::variant_count( $section, $profile );
			if ( $sem_count > 0 ) {
				$base_index = CategorySemanticSections::picked_index( $section, $profile, $seed );
				$sem_index  = $base_index;
				// The differentiation store records each section's variant as
				// "<section>:<variant-id>" (see $variant_ids below), and the
				// avoid set is built from those recorded ids. Check the SAME
				// composite form here so the cross-page cooldown genuinely
				// skips a variant a recent page used — an earlier build compared
				// the bare id and silently never matched, letting same-class
				// categories collide under a populated store.
				for ( $off = 0; $off < $sem_count; $off++ ) {
					$candidate_index = ( $base_index + $off ) % $sem_count;
					$candidate_vid   = $section . ':sem_' . $section . '_' . $candidate_index;
					if ( ! isset( $avoid_variants[ $candidate_vid ] ) ) { $sem_index = $candidate_index; break; }
				}
				$semantic_slots    = CategorySemanticSections::slots_at( $section, $profile, $sem_index );
				$variants          = [ [ 'id' => 'sem_' . $section . '_' . $sem_index, 'sentences' => $semantic_slots ] ];
				$from_intent_pool  = false;
			} else {
				$purpose = $library['purposes'][ $section ] ?? null;
				if ( ! is_array( $purpose ) ) { continue; }
				[ $variants, $from_intent_pool ] = self::variants_for( $purpose, $intent );
				if ( empty( $variants ) ) { continue; }
			}

			// Cross-category cooldown (v5.9.9): choose the SEEDED pick among
			// the variants no recent page used. Taking the first fresh
			// candidate (the old rule) made consecutive pages converge on
			// one variant whenever the pool ran low, which produced the
			// exact-paragraph collisions the uniqueness guard then had to
			// reject. Seeding within the fresh subset keeps pages apart
			// even under cooldown pressure; when every variant is inside
			// the window, fall back to the plain seeded pick and let the
			// uniqueness guard verify the render.
			$n     = count( $variants );
			$fresh = [];
			for ( $i = 0; $i < $n; $i++ ) {
				$candidate = $variants[ $i ];
				$vid       = $section . ':' . (string) ( $candidate['id'] ?? $i );
				if ( ! isset( $avoid_variants[ $vid ] ) ) { $fresh[] = $candidate; }
			}
			$variant = ! empty( $fresh )
				? $fresh[ $seed % count( $fresh ) ]
				: $variants[ $seed % $n ];
			$variant_ids[] = $section . ':' . (string) ( $variant['id'] ?? $seed % $n );
			if ( $from_intent_pool ) { $intent_sections[] = $section; }

			// Keyword spacing: a section directly after one that consumed a
			// body-use keyword renders without keywords, so the same family
			// never lands in consecutive sections/paragraphs.
			$section_kw_queue = $prev_used_kw ? [] : $kw_queue;
			$kw_before        = count( $used_kws );

			$paragraph = '';
			$sentence_index = 0;
			$heading_for_glue = (string) ( $plan['headings'][ $section ] ?? '' );
			foreach ( (array) ( $variant['sentences'] ?? [] ) as $sentence_entry ) {
				$candidates = self::alternate_candidates( $sentence_entry, $seed, $sentence_index, $avoid_sentences );
				$sentence_index++;
				$sentence = null;
				$sentence_template = '';
				foreach ( $candidates as $candidate_template ) {
					$try_queue = $section_kw_queue;
					$try_used  = $used_kws;
					$try_links = $links_used;
					$candidate_sentence = self::resolve_sentence( (string) $candidate_template, $values, $try_queue, $try_used, $links, $try_links );
					if ( $candidate_sentence === null ) { continue; }
					if ( $paragraph === '' && $heading_for_glue !== '' && self::heading_glue_is_dump( $heading_for_glue, $candidate_sentence, self::duplicate_guard_keywords( $keyword_plan ) ) ) { continue; }
					$sentence = $candidate_sentence; $sentence_template = (string) $candidate_template; $section_kw_queue = $try_queue; $used_kws = $try_used; $links_used = $try_links; break;
				}
				if ( $sentence === null ) { $dropped++; continue; }
				if ( ! preg_match( '/^\s*\{\{[a-z0-9_]+\}\}\s*$/i', (string) $sentence_template ) ) {
					$sentence_ids[] = (string) crc32( (string) $sentence_template );
				}
				$paragraph     .= ( $paragraph === '' ? '' : ' ' ) . $sentence;
			}

			$section_used_kw = count( $used_kws ) > $kw_before;
			if ( ! $prev_used_kw ) {
				$kw_queue = $section_kw_queue; // consumed items stay consumed
			}
			$prev_used_kw = $section_used_kw;
			if ( trim( $paragraph ) === '' ) { continue; }

			$block = '';
			$heading = (string) ( $plan['headings'][ $section ] ?? '' );
			if ( $heading !== '' ) {
				$block .= '<h2>' . self::esc( $heading ) . '</h2>';
			}
			$block .= '<p>' . $paragraph . '</p>';

			$section_html[ $section ] = $block;
			$html                    .= $block;
		}

		return [
			'html'              => $html,
			'sections'          => $section_html,
			'used_keywords'     => $used_kws,
			'dropped_sentences' => $dropped,
			'variant_ids'       => $variant_ids,
			'sentence_ids'      => array_values( array_unique( $sentence_ids ) ),
			'intent_sections'   => $intent_sections,
			'internal_links'    => array_values( $links_used ),
		];
	}

	/** @param string|array $entry @return string[] */
	private static function alternate_candidates( $entry, int $seed, int $index, array $avoid_sentences = [] ): array {
		if ( ! is_array( $entry ) ) { return [ (string) $entry ]; }
		$entry = array_values( $entry );
		if ( empty( $entry ) ) { return [ '' ]; }
		$n = count( $entry );
		$start = CategoryContentPlanner::seed( $seed . '/' . $index ) % $n;
		$out = [];
		for ( $i = 0; $i < $n; $i++ ) {
			$candidate = (string) $entry[ ( $start + $i ) % $n ];
			if ( ! isset( $avoid_sentences[ (string) crc32( $candidate ) ] ) ) { $out[] = $candidate; }
		}
		for ( $i = 0; $i < $n; $i++ ) {
			$candidate = (string) $entry[ ( $start + $i ) % $n ];
			if ( ! in_array( $candidate, $out, true ) ) { $out[] = $candidate; }
		}
		return $out;
	}

	private static function has_duplicate_tracked_phrase( string $text, array $keywords ): bool {
		if ( ! class_exists( CategoryQualityGuard::class ) ) { return false; }
		return ! empty( CategoryQualityGuard::duplicate_tracked_phrases( $text, $keywords ) );
	}

	/**
	 * Heading-to-first-sentence keyword-dump test. The old check rejected the
	 * glued heading+sentence whenever any tracked keyword appeared twice across
	 * the pair. For a category whose subject IS a single word (a one-word
	 * primary such as a bare trait name), that word legitimately appears once in
	 * the heading and once in the first sentence — natural prose, not stuffing —
	 * yet the old test dropped the whole sentence, starving the page of words.
	 *
	 * A genuine dump is either (a) a MULTI-WORD tracked phrase echoed across the
	 * heading and the sentence (e.g. the heading's exact keyword phrase repeated
	 * verbatim to open the paragraph), or (b) a tracked keyword appearing 2+
	 * times WITHIN the sentence itself (in-sentence stuffing). A single-token
	 * keyword appearing once in the heading and once in the sentence is allowed.
	 * This narrows a false positive; it does not relax dump protection — every
	 * real dump the old check caught is still caught.
	 *
	 * @param string[] $keywords
	 */
	private static function heading_glue_is_dump( string $heading, string $sentence, array $keywords ): bool {
		// (b) in-sentence stuffing of any tracked keyword.
		if ( self::has_duplicate_tracked_phrase( $sentence, $keywords ) ) { return true; }
		// (a) a multi-word tracked phrase echoed across heading + sentence.
		foreach ( $keywords as $kw ) {
			$kw = (string) $kw;
			if ( $kw === '' ) { continue; }
			if ( count( preg_split( '/\s+/', trim( $kw ) ) ?: [] ) < 2 ) { continue; } // single-token: allowed once per side
			if ( self::has_duplicate_tracked_phrase( $heading . ' ' . $sentence, [ $kw ] ) ) { return true; }
		}
		return false;
	}


	/** @return string[] */
	private static function duplicate_guard_keywords( array $keyword_plan ): array {
		$tracked = ! empty( $keyword_plan['density_tracking'] )
			? (array) $keyword_plan['density_tracking']
			: array_merge( [ (string) ( $keyword_plan['primary'] ?? '' ) ], (array) ( $keyword_plan['rankmath_tracking'] ?? [] ), (array) ( $keyword_plan['body_use'] ?? [] ) );
		return array_values( array_unique( array_filter( array_map( 'strval', $tracked ) ) ) );
	}

	/**
	 * A sentence entry may be a plain template or an array of alternates.
	 * Alternates multiply surface variety (especially for opening sentences)
	 * while staying deterministic per category.
	 *
	 * @param string|array $entry
	 */
	private static function pick_alternate( $entry, int $seed, int $index, array $avoid_sentences = [] ): string {
		if ( ! is_array( $entry ) ) { return (string) $entry; }
		$entry = array_values( $entry );
		if ( empty( $entry ) ) { return ''; }
		$n     = count( $entry );
		$start = CategoryContentPlanner::seed( $seed . '/' . $index ) % $n;
		// v5.9.9: seeded pick among the alternates no recent page rendered
		// (first-fresh selection converged consecutive pages onto the same
		// alternate under cooldown pressure). Fall back to seeded when the
		// whole slot is inside the window.
		$fresh = [];
		foreach ( $entry as $candidate ) {
			$candidate = (string) $candidate;
			if ( ! isset( $avoid_sentences[ (string) crc32( $candidate ) ] ) ) { $fresh[] = $candidate; }
		}
		if ( ! empty( $fresh ) ) {
			return $fresh[ $start % count( $fresh ) ];
		}
		return (string) $entry[ $start ];
	}

	/**
	 * Resolve one sentence template. Returns null when a placeholder cannot
	 * be resolved (the "never invent" rule) or when the sentence would carry
	 * more than two exact keywords.
	 *
	 * @param array<string,string> $values
	 * @param string[]             $kw_queue (by reference semantics via array)
	 * @param string[]             $used_kws
	 */
	private static function resolve_sentence( string $template, array $values, array &$kw_queue, array &$used_kws, array $links = [], array &$links_used = [] ): ?string {
		$kw_slots = 0;
		if ( strpos( $template, '{{kw1}}' ) !== false ) { $kw_slots++; }
		if ( strpos( $template, '{{kw2}}' ) !== false ) { $kw_slots++; }
		if ( $kw_slots > CategoryKeywordPlanner::MAX_EXACTS_PER_SENTENCE ) { return null; }
		if ( $kw_slots > count( $kw_queue ) ) { return null; }

		$local = $values;
		if ( $kw_slots >= 1 ) { $local['kw1'] = (string) array_shift( $kw_queue ); }
		if ( $kw_slots >= 2 ) { $local['kw2'] = (string) array_shift( $kw_queue ); }

		// Internal-link placeholders (v5.9.9): a {{*_link}} placeholder is
		// substituted with an opaque token BEFORE escaping and swapped for a
		// real <a href> anchor AFTER escaping, so the anchor markup survives
		// while every other character is escaped. A link placeholder whose
		// destination is unverified resolves to nothing — the sentence is
		// dropped, never rendered with a raw or invented URL.
		$sentence_links = [];
		foreach ( $links as $key => $link ) {
			$ph = '{{' . $key . '}}';
			if ( strpos( $template, $ph ) === false ) { continue; }
			if ( empty( $link['href'] ) || empty( $link['anchor'] ) ) {
				$local[ $key ] = ''; // unresolvable → sentence dropped below
				continue;
			}
			$token                    = '@@TMWLNK' . count( $sentence_links ) . '@@';
			$sentence_links[ $token ] = $link;
			$local[ $key ]            = $token;
		}

		$sentence = preg_replace_callback( '/\{\{([a-z0-9_]+)\}\}/i', static function ( $m ) use ( $local ) {
			$key = strtolower( (string) $m[1] );
			return array_key_exists( $key, $local ) && trim( (string) $local[ $key ] ) !== ''
				? (string) $local[ $key ]
				: '{{unresolved}}';
		}, $template );

		if ( $sentence === null || strpos( $sentence, '{{unresolved}}' ) !== false ) {
			// Put unconsumed keywords back — the sentence was dropped.
			if ( $kw_slots >= 2 && isset( $local['kw2'] ) ) { array_unshift( $kw_queue, $local['kw2'] ); }
			if ( $kw_slots >= 1 && isset( $local['kw1'] ) ) { array_unshift( $kw_queue, $local['kw1'] ); }
			return null;
		}

		if ( $kw_slots >= 1 && isset( $local['kw1'] ) ) { $used_kws[] = $local['kw1']; }
		if ( $kw_slots >= 2 && isset( $local['kw2'] ) ) { $used_kws[] = $local['kw2']; }

		$sentence = self::esc( trim( $sentence ) );

		foreach ( $sentence_links as $token => $link ) {
			$anchor = self::anchor_html( (string) $link['href'], (string) $link['anchor'] );
			if ( strpos( $sentence, $token ) !== false ) {
				$sentence                    = str_replace( $token, $anchor, $sentence );
				$links_used[ $link['href'] . '|' . $link['anchor'] ] = [
					'href'   => (string) $link['href'],
					'anchor' => (string) $link['anchor'],
				];
			}
		}
		$sentence = (string) preg_replace( '/(<\/a>)(?=[\p{L}\p{N}])/u', '$1 ', $sentence );

		return $sentence;
	}

	/** Build a safe internal anchor element (never nofollow — these are internal links). */
	public static function anchor_html( string $href, string $anchor ): string {
		$href = function_exists( 'esc_url' ) ? esc_url( $href ) : htmlspecialchars( $href, ENT_QUOTES );
		return '<a href="' . $href . '">' . self::esc( $anchor ) . '</a>';
	}

	/**
	 * Static placeholder values from context. Empty string = unresolvable.
	 *
	 * @return array<string,string>
	 */
	private static function base_values( array $context, string $intent, array $plan, array $avoid_sentences = [], string &$clarity_id = '', array &$links = [] ): array {
		$library = self::library();

		$seed    = (int) ( $plan['seed'] ?? 0 );
		$clarity = '';
		$clarity_pool_resolved = [];
		$pool    = array_values( (array) ( $library['intent_clarity'][ $intent ] ?? [] ) );
		if ( ! empty( $pool ) ) {
			// The clarity sentence is cross-page cooldown-aware like every
			// other alternate: rotate from the seeded position to the first
			// alternate no recent page rendered.
			$n       = count( $pool );
			$clarity = self::pick_string( $pool[ $seed % $n ], $seed );
			for ( $i = 0; $i < $n; $i++ ) {
				$candidate = self::pick_string( $pool[ ( $seed + $i ) % $n ], $seed );
				if ( ! isset( $avoid_sentences[ (string) crc32( $candidate ) ] ) ) { $clarity = $candidate; break; }
			}
			$clarity_id = (string) crc32( $clarity );

			// v5.9.16 — resolve up to three DISTINCT intent-clarity clauses so
			// the pinned category-semantic sections (intro, expectations,
			// discovery_advice) can each carry one intent-specific,
			// factually-safe sentence. This gives every page three paragraphs
			// that satisfy the intent-specificity contract on top of the
			// category-specific prose, for every intent the classifier can
			// assign — not just the ones whose vocabulary happens to overlap a
			// generic frame. Resolution is cross-page cooldown-aware: two
			// categories of the SAME intent otherwise share all three clauses
			// (a fixed pool per intent), which shows up as shared sentence
			// templates under a populated store. Preferring clauses no recent
			// page rendered spreads same-intent categories across the pool; a
			// deterministic seeded rotation fills any remainder so the three
			// stay distinct even when the fresh subset is exhausted.
			$seen = [];
			// Pass 1: seeded rotation over clauses NOT in the recent-cooldown set.
			for ( $i = 0; $i < $n && count( $clarity_pool_resolved ) < 3; $i++ ) {
				$candidate = self::pick_string( $pool[ ( $seed + $i ) % $n ], $seed + $i );
				$key       = (string) crc32( $candidate );
				if ( isset( $seen[ $key ] ) ) { continue; }
				if ( isset( $avoid_sentences[ $key ] ) ) { continue; }
				$seen[ $key ] = true;
				$clarity_pool_resolved[] = $candidate;
			}
			// Pass 2: fill any remaining slots from the full pool (still distinct).
			for ( $i = 0; $i < $n && count( $clarity_pool_resolved ) < 3; $i++ ) {
				$candidate = self::pick_string( $pool[ ( $seed + $i ) % $n ], $seed + $i );
				$key       = (string) crc32( $candidate );
				if ( isset( $seen[ $key ] ) ) { continue; }
				$seen[ $key ] = true;
				$clarity_pool_resolved[] = $candidate;
			}
			// Record the crc of each RESOLVED clarity clause (not just the
			// first) so the cross-page cooldown can steer same-intent
			// categories toward fresh clauses next time. $clarity_id carries a
			// comma-joined list the composer splits into individual sentence
			// ids.
			$clarity_ids = [];
			foreach ( $clarity_pool_resolved as $resolved_clause ) {
				$clarity_ids[] = (string) crc32( $resolved_clause );
			}
			if ( ! empty( $clarity_ids ) ) {
				$clarity_id = implode( ',', array_values( array_unique( array_merge( [ $clarity_id ], $clarity_ids ) ) ) );
			}
		}
		// (v5.9.9 context builder). A link placeholder only resolves when a
		// verified URL exists; the plain name placeholder keeps working.
		$related       = [];
		foreach ( (array) ( $context['related_categories'] ?? [] ) as $rel ) {
			if ( is_array( $rel ) ) {
				$related[] = [ 'name' => (string) ( $rel['name'] ?? '' ), 'url' => (string) ( $rel['url'] ?? '' ) ];
			} else {
				$related[] = [ 'name' => (string) $rel, 'url' => '' ];
			}
		}

		$models_url = (string) ( $context['models_url'] ?? '' );
		$videos_url = (string) ( $context['videos_url'] ?? '' );

		$models_anchor = self::pick_string( [ 'webcam model directory', 'full model directory', 'model directory' ], $seed );
		$videos_anchor = self::pick_string( [ 'webcam video directory', 'full video directory', 'video directory' ], $seed + 3 );

		$links = [
			'models_link'    => [ 'href' => $models_url, 'anchor' => $models_url !== '' ? $models_anchor : '' ],
			'videos_link'    => [ 'href' => $videos_url, 'anchor' => $videos_url !== '' ? $videos_anchor : '' ],
			'related_1_link' => [ 'href' => (string) ( $related[0]['url'] ?? '' ), 'anchor' => (string) ( $related[0]['name'] ?? '' ) ],
			'related_2_link' => [ 'href' => (string) ( $related[1]['url'] ?? '' ), 'anchor' => (string) ( $related[1]['name'] ?? '' ) ],
		];

		// v5.9.16 — category-semantic tokens. These carry the category's
		// MEANING (its subject/theme, a descriptor, the delivery format) so
		// sentences state something specific about THIS category instead of
		// name-only boilerplate. Derived deterministically from the category's
		// own data; empty-safe (a missing profile just leaves the tokens blank
		// and the sentence falls back like any other unresolved slot).
		$profile    = (array) ( $context['__semantic_profile'] ?? [] );
		$subject    = (string) ( $profile['subject'] ?? '' );
		$subject_t  = (string) ( $profile['subject_title'] ?? ( $subject !== '' ? ucwords( $subject ) : '' ) );
		$descriptor = '';
		$dterms     = (array) ( $profile['descriptor_terms'] ?? [] );
		if ( ! empty( $dterms ) ) { $descriptor = (string) $dterms[ $seed % count( $dterms ) ]; }
		$format     = '';
		$fterms     = (array) ( $profile['format_terms'] ?? [] );
		if ( ! empty( $fterms ) ) { $format = (string) $fterms[ $seed % count( $fterms ) ]; }

		return [
			'category_name'   => (string) ( $context['category_name'] ?? '' ),
			'primary_keyword' => (string) ( $context['primary_keyword'] ?? '' ),
			'site_name'       => (string) ( $context['site_name'] ?? '' ),
			'related_1'       => (string) ( $related[0]['name'] ?? '' ),
			'related_2'       => (string) ( $related[1]['name'] ?? '' ),
			'model_scale'     => self::scale_phrase( 'models', $context['model_count'] ?? null, $seed ),
			'video_scale'     => self::scale_phrase( 'videos', $context['video_count'] ?? null, $seed + 1 ),
			'intent_clarity'  => $clarity,
			'intent_clarity_1' => (string) ( $clarity_pool_resolved[0] ?? $clarity ),
			'intent_clarity_2' => (string) ( $clarity_pool_resolved[1] ?? '' ),
			'intent_clarity_3' => (string) ( $clarity_pool_resolved[2] ?? '' ),
			'subject'         => $subject,
			'subject_title'   => $subject_t,
			'descriptor'      => $descriptor !== '' ? $descriptor : $subject,
			'format'          => $format !== '' ? $format : 'live',
		];
	}

	/** Qualitative scale phrase from a real count; empty when unknown/zero. */
	public static function scale_phrase( string $kind, $count, int $seed = 0 ): string {
		if ( $count === null || ! is_numeric( $count ) ) { return ''; }
		$count   = (int) $count;
		$library = self::library();
		$bank    = (array) ( $library['scale_phrases'][ $kind ] ?? [] );
		if ( $count <= 0 ) { return self::pick_string( $bank['none'] ?? '', $seed ); }
		if ( $count <= 5 ) { return self::pick_string( $bank['few'] ?? '', $seed ); }
		if ( $count <= 20 ) { return self::pick_string( $bank['some'] ?? '', $seed ); }
		return self::pick_string( $bank['many'] ?? '', $seed );
	}

	/** A library value may be a string or an array of alternates. */
	private static function pick_string( $value, int $seed ): string {
		if ( is_array( $value ) ) {
			$value = array_values( $value );
			return empty( $value ) ? '' : (string) $value[ $seed % count( $value ) ];
		}
		return (string) $value;
	}

	/** @return array{0:array<int,array<string,mixed>>,1:bool} [pool, from_intent_pool] */
	private static function variants_for( array $purpose, string $intent ): array {
		$variants = (array) ( $purpose['variants'] ?? [] );
		if ( isset( $variants[ $intent ] ) && is_array( $variants[ $intent ] ) && ! empty( $variants[ $intent ] ) ) {
			return [ array_values( $variants[ $intent ] ), true ];
		}
		if ( isset( $variants['default'] ) && is_array( $variants['default'] ) ) {
			return [ array_values( $variants['default'] ), false ];
		}
		return [ [], false ];
	}

	private static function esc( string $s ): string {
		return function_exists( 'esc_html' ) ? esc_html( $s ) : htmlspecialchars( $s, ENT_QUOTES );
	}
}
