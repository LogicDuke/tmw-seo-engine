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
	 * @return array{html:string,sections:array<string,string>,used_keywords:string[],dropped_sentences:int,variant_ids:string[],sentence_ids:string[],intent_sections:string[]}
	 */
	public static function compose( array $context, array $plan, array $keyword_plan, array $avoid = [] ): array {
		$library = self::library();
		$intent  = (string) ( $plan['intent'] ?? 'broad_discovery' );

		$avoid_variants  = array_fill_keys( array_map( 'strval', (array) ( $avoid['variants'] ?? [] ) ), true );
		$avoid_sentences = array_fill_keys( array_map( 'strval', (array) ( $avoid['sentences'] ?? [] ) ), true );
		$variant_ids     = [];
		$sentence_ids    = [];
		$intent_sections = [];

		$kw_queue = array_values( (array) ( $keyword_plan['body_use'] ?? [] ) );
		$used_kws = [];
		$dropped  = 0;

		$clarity_id = '';
		$values     = self::base_values( $context, $intent, $plan, $avoid_sentences, $clarity_id );
		if ( $clarity_id !== '' ) { $sentence_ids[] = $clarity_id; }

		$html            = '';
		$section_html    = [];
		$prev_used_kw    = false;
		foreach ( (array) ( $plan['sections'] ?? [] ) as $section ) {
			if ( $section === 'faq' ) {
				$section_html['faq'] = ''; // slot — filled by the pipeline
				continue;
			}
			$purpose = $library['purposes'][ $section ] ?? null;
			if ( ! is_array( $purpose ) ) { continue; }

			[ $variants, $from_intent_pool ] = self::variants_for( $purpose, $intent );
			if ( empty( $variants ) ) { continue; }

			$seed = (int) ( $plan['variant_seeds'][ $section ] ?? 0 );

			// Cross-category cooldown: rotate from the seeded position and
			// take the first variant no recent page used. If every variant
			// is inside the window, fall back to the seeded pick — sentence
			// alternates still differ, and the uniqueness guard verifies.
			$n       = count( $variants );
			$variant = $variants[ $seed % $n ];
			for ( $i = 0; $i < $n; $i++ ) {
				$candidate = $variants[ ( $seed + $i ) % $n ];
				$vid       = $section . ':' . (string) ( $candidate['id'] ?? ( $seed + $i ) % $n );
				if ( ! isset( $avoid_variants[ $vid ] ) ) { $variant = $candidate; break; }
			}
			$variant_ids[] = $section . ':' . (string) ( $variant['id'] ?? $seed % $n );
			if ( $from_intent_pool ) { $intent_sections[] = $section; }

			// Keyword spacing: a section directly after one that consumed a
			// body-use keyword renders without keywords, so the same family
			// never lands in consecutive sections/paragraphs.
			$section_kw_queue = $prev_used_kw ? [] : $kw_queue;
			$kw_before        = count( $used_kws );

			$paragraph = '';
			$sentence_index = 0;
			foreach ( (array) ( $variant['sentences'] ?? [] ) as $sentence_entry ) {
				$sentence_template = self::pick_alternate( $sentence_entry, $seed, $sentence_index, $avoid_sentences );
				$sentence_index++;
				$sentence = self::resolve_sentence( (string) $sentence_template, $values, $section_kw_queue, $used_kws );
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
		];
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
		// Prefer an alternate no recent page rendered; fall back to seeded.
		for ( $i = 0; $i < $n; $i++ ) {
			$candidate = (string) $entry[ ( $start + $i ) % $n ];
			if ( ! isset( $avoid_sentences[ (string) crc32( $candidate ) ] ) ) { return $candidate; }
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
	private static function resolve_sentence( string $template, array $values, array &$kw_queue, array &$used_kws ): ?string {
		$kw_slots = 0;
		if ( strpos( $template, '{{kw1}}' ) !== false ) { $kw_slots++; }
		if ( strpos( $template, '{{kw2}}' ) !== false ) { $kw_slots++; }
		if ( $kw_slots > CategoryKeywordPlanner::MAX_EXACTS_PER_SENTENCE ) { return null; }
		if ( $kw_slots > count( $kw_queue ) ) { return null; }

		$local = $values;
		if ( $kw_slots >= 1 ) { $local['kw1'] = (string) array_shift( $kw_queue ); }
		if ( $kw_slots >= 2 ) { $local['kw2'] = (string) array_shift( $kw_queue ); }

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

		return self::esc( trim( $sentence ) );
	}

	/**
	 * Static placeholder values from context. Empty string = unresolvable.
	 *
	 * @return array<string,string>
	 */
	private static function base_values( array $context, string $intent, array $plan, array $avoid_sentences = [], string &$clarity_id = '' ): array {
		$library = self::library();

		$seed    = (int) ( $plan['seed'] ?? 0 );
		$clarity = '';
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
		}

		$related = (array) ( $context['related_categories'] ?? [] );

		return [
			'category_name'   => (string) ( $context['category_name'] ?? '' ),
			'primary_keyword' => (string) ( $context['primary_keyword'] ?? '' ),
			'site_name'       => (string) ( $context['site_name'] ?? '' ),
			'models_url'      => (string) ( $context['models_url'] ?? '' ),
			'videos_url'      => (string) ( $context['videos_url'] ?? '' ),
			'related_1'       => (string) ( $related[0] ?? '' ),
			'related_2'       => (string) ( $related[1] ?? '' ),
			'model_scale'     => self::scale_phrase( 'models', $context['model_count'] ?? null, $seed ),
			'video_scale'     => self::scale_phrase( 'videos', $context['video_count'] ?? null, $seed + 1 ),
			'intent_clarity'  => $clarity,
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
