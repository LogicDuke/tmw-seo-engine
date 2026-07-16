<?php
/**
 * CategoryFaqPlanner â€” Stage 9 of the universal category pipeline.
 *
 * Selects 3-5 FAQs from the intent-tagged universal FAQ library:
 *
 *  - buckets are ranked by intent weight (falling back to a 'default'
 *    weight), with a deterministic per-category jitter so equally weighted
 *    buckets do not resolve identically for every category;
 *  - one variant per chosen bucket, seeded by slug;
 *  - answers pass through factual-safety analysis â€” a variant carrying an
 *    unsupported claim is skipped in favor of the next variant;
 *  - the exact category name may appear at most twice across the FAQ block
 *    (questions never stuff the name â€” the library has none).
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 * @since   5.9.7
 */

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryFaqPlanner {

	private const MIN_FAQ = 3;
	private const MAX_FAQ = 5;

	/** @var array|null */
	private static $library = null;

	public static function library(): array {
		if ( self::$library !== null ) { return self::$library; }
		$dir  = defined( 'TMWSEO_ENGINE_DATA_DIR' ) ? TMWSEO_ENGINE_DATA_DIR : dirname( __DIR__, 3 ) . '/data';
		$file = rtrim( (string) $dir, '/\\' ) . '/category-universal-faq.json';
		$raw  = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		$data = $raw !== '' ? json_decode( $raw, true ) : null;
		self::$library = is_array( $data ) ? $data : [ 'buckets' => [] ];
		return self::$library;
	}

	public static function reset_library_cache(): void {
		self::$library = null;
	}

	/**
	 * Plan the FAQ set.
	 *
	 * Buckets are tiered: intent-tier buckets rank ahead of generic ones,
	 * and at most CategoryFaqReuseGuard::MAX_GENERIC_PER_PAGE generic
	 * buckets appear per page. Variants used by recent pages (the cooldown
	 * window) are excluded up front by CategoryFaqReuseGuard; when a bucket
	 * has no safe unused variant left, it is skipped entirely â€” the page
	 * carries one fewer FAQ rather than a repeated answer.
	 *
	 * {{category_name}} / {{primary_keyword}} placeholders in questions and
	 * answers are resolved from context; a variant that still carries an
	 * unresolved placeholder afterwards is skipped.
	 *
	 * @param array<string,mixed> $context  Stage 1 output.
	 * @param string              $intent   Stage 2 intent.
	 * @param int                 $salt     Regeneration salt.
	 * @param string[]            $used_ids "bucket:variant" ids inside the cooldown window.
	 * @param string[]            $keyword_questions Supporting keywords that must each
	 *                            carry ONE FAQ question (role faq_heading / demoted
	 *                            heading roles). Rank Math counts H2-H6, so the FAQ
	 *                            H3 question is the keyword's subheading placement.
	 * @return array<int,array{id:string,vid:string,q:string,a:string,bucket:string,tier:string}>
	 */
	public static function plan( array $context, string $intent, int $salt = 0, array $used_ids = [], array $keyword_questions = [] ): array {
		$library = self::library();
		$buckets = CategoryFaqReuseGuard::eligible( (array) ( $library['buckets'] ?? [] ), $used_ids );
		$relaxed_ids = $used_ids;
		while ( ! empty( $relaxed_ids ) && self::eligible_variant_count( $buckets ) < self::MIN_FAQ ) {
			array_shift( $relaxed_ids );
			$buckets = CategoryFaqReuseGuard::eligible( (array) ( $library['buckets'] ?? [] ), $relaxed_ids );
		}
		if ( empty( $buckets ) && empty( $keyword_questions ) ) { return []; }

		$slug = (string) ( $context['category_slug'] ?? '' );
		$seed = CategoryContentPlanner::seed( $slug . '|faq|' . $intent . '|' . $salt );

		$bucket_keys = CategoryFaqReuseGuard::rank_buckets( $buckets, $intent, $seed );
		$count       = self::MIN_FAQ + ( $salt % 3 ); // full 3-5 range across salts

		$values = [
			'category_name'   => (string) ( $context['category_name'] ?? '' ),
			'primary_keyword' => (string) ( $context['primary_keyword'] ?? '' ),
		];

		$faqs         = [];
		$generic_used = 0;

		// ── v5.9.10: keyword questions FIRST — each listed supporting
		// keyword gets exactly one question whose text carries the exact
		// phrase. Templates are universal ({{kw}}/{{kw_title}}); answers
		// are conservative and category-agnostic, so no factual risk is
		// introduced. Deterministic template choice per keyword; the used
		// template index is offset per keyword so two keyword questions on
		// one page never share a template.
		$kq_templates = array_values( (array) ( $library['keyword_questions'] ?? [] ) );
		$kq_used_tpl  = [];
		// Cross-page cooldown: template indices used by recent pages (their
		// vids carry the "keyword_questions:IDX:" prefix) are avoided so two
		// nearby pages never share a keyword-question skeleton. When every
		// template is inside the window, the recent set is ignored rather
		// than dropping the keyword's question (termination guaranteed).
		$kq_recent = [];
		foreach ( $used_ids as $uid ) {
			if ( preg_match( '/^keyword_questions:(\d+):/', (string) $uid, $km ) ) { $kq_recent[ (int) $km[1] ] = true; }
		}
		if ( count( $kq_recent ) >= count( $kq_templates ) ) { $kq_recent = []; }
		foreach ( array_values( array_unique( array_filter( array_map( 'strval', $keyword_questions ) ) ) ) as $ki => $kw ) {
			if ( empty( $kq_templates ) ) { break; }
			$n     = count( $kq_templates );
			$start = CategoryContentPlanner::seed( $slug . '|kwfaq|' . $kw . '|' . $salt ) % $n;
			$order = [];
			for ( $i = 0; $i < $n; $i++ ) {
				$idx = ( $start + $i ) % $n;
				if ( ! isset( $kq_recent[ $idx ] ) ) { $order[] = $idx; }
			}
			for ( $i = 0; $i < $n; $i++ ) {
				$idx = ( $start + $i ) % $n;
				if ( isset( $kq_recent[ $idx ] ) ) { $order[] = $idx; }
			}
			foreach ( $order as $idx ) {
				if ( isset( $kq_used_tpl[ $idx ] ) ) { continue; }
				$tpl = (array) $kq_templates[ $idx ];
				$kv  = $values + [ 'kw' => $kw, 'kw_title' => self::title_case_kw( $kw ) ];
				$q   = self::resolve( trim( (string) ( $tpl['q'] ?? '' ) ), $kv );
				// Each template carries several answer variants; the seeded
				// pick per category keeps two pages that DO share a template
				// (full cooldown pressure) from also sharing the answer text.
				$a_raw = $tpl['a'] ?? '';
				if ( is_array( $a_raw ) ) {
					$a_pool = array_values( array_filter( array_map( 'strval', $a_raw ), 'strlen' ) );
					$a_raw  = ! empty( $a_pool )
						? $a_pool[ CategoryContentPlanner::seed( $slug . '|kwfaq-a|' . $kw . '|' . $idx . '|' . $salt ) % count( $a_pool ) ]
						: '';
				}
				$a = self::resolve( trim( (string) $a_raw ), $kv );
				if ( $q === '' || $a === '' || strpos( $q . $a, '{{' ) !== false ) { continue; }
				if ( ! empty( CategoryFactualSafety::analyze( '<p>' . $a . '</p>', (array) ( $context['verified_flags'] ?? [] ) ) ) ) { continue; }
				$kq_used_tpl[ $idx ] = true;
				$faqs[] = [
					'id'     => 'kwq-' . $idx,
					'vid'    => 'keyword_questions:' . $idx . ':' . md5( strtolower( $kw ) ),
					'q'      => $q,
					'a'      => $a,
					'bucket' => 'keyword_questions',
					'tier'   => 'keyword',
				];
				break;
			}
		}
		$count = max( $count, min( self::MAX_FAQ, count( $faqs ) + 2 ) );
		foreach ( $bucket_keys as $bucket_key ) {
			if ( count( $faqs ) >= $count ) { break; }
			$bucket     = $buckets[ $bucket_key ];
			$is_generic = CategoryFaqReuseGuard::is_generic( (array) $bucket );
			if ( $is_generic && $generic_used >= CategoryFaqReuseGuard::MAX_GENERIC_PER_PAGE ) { continue; }

			$variants = array_values( (array) ( $bucket['variants'] ?? [] ) );
			$n        = count( $variants );
			if ( $n < 1 ) { continue; }

			$start = CategoryContentPlanner::seed( $slug . '^' . $bucket_key . '^' . $salt ) % $n;
			for ( $i = 0; $i < $n; $i++ ) {
				$variant = $variants[ ( $start + $i ) % $n ];
				$q = self::resolve( trim( (string) ( $variant['q'] ?? '' ) ), $values );
				$a = self::resolve( trim( (string) ( $variant['a'] ?? '' ) ), $values );
				if ( $q === '' || $a === '' ) { continue; }
				if ( strpos( $q . $a, '{{' ) !== false ) { continue; }
				if ( ! empty( CategoryFactualSafety::analyze( '<p>' . $a . '</p>', (array) ( $context['verified_flags'] ?? [] ) ) ) ) { continue; }
				$faqs[] = [
					'id'     => (string) ( $variant['id'] ?? $bucket_key ),
					'vid'    => $bucket_key . ':' . (string) ( $variant['id'] ?? '' ),
					'q'      => $q,
					'a'      => $a,
					'bucket' => $bucket_key,
					'tier'   => $is_generic ? 'generic' : 'intent',
				];
				if ( $is_generic ) { $generic_used++; }
				break;
			}
		}

		if ( count( $faqs ) < self::MIN_FAQ && ! empty( $relaxed_ids ) ) {
			array_shift( $relaxed_ids );
			return self::plan( $context, $intent, $salt, $relaxed_ids, $keyword_questions );
		}

		return $faqs;
	}

	/** Title-case a keyword for question text ("free live cams" → "Free Live Cams"). */
	private static function title_case_kw( string $kw ): string {
		$cased = (string) preg_replace_callback( '/\b\p{Ll}/u', static function ( $m ) {
			return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $m[0], 'UTF-8' ) : strtoupper( $m[0] );
		}, $kw );
		return (string) preg_replace_callback( '/\b(Tv|Hd|Vr|Bbw|Milf|Joi|Pov|Bdsm)\b/u', static function ( $m ) {
			return strtoupper( $m[0] );
		}, $cased );
	}

	/** @param array<string,array<string,mixed>> $buckets */
	private static function eligible_variant_count( array $buckets ): int {
		$count = 0;
		foreach ( $buckets as $bucket ) {
			$count += count( (array) ( $bucket['variants'] ?? [] ) );
		}
		return $count;
	}

	/** Resolve context placeholders in FAQ text; unresolved markers survive for the caller's skip check. */
	private static function resolve( string $text, array $values ): string {
		return (string) preg_replace_callback( '/\{\{([a-z0-9_]+)\}\}/i', static function ( $m ) use ( $values ) {
			$key = strtolower( (string) $m[1] );
			return array_key_exists( $key, $values ) && trim( (string) $values[ $key ] ) !== ''
				? (string) $values[ $key ]
				: '{{unresolved}}';
		}, $text );
	}

	/**
	 * Render planned FAQs as HTML.
	 *
	 * @param array<int,array{q:string,a:string}> $faqs
	 */
	public static function render( array $faqs ): string {
		if ( empty( $faqs ) ) { return ''; }
		$esc  = static function ( string $s ): string {
			return function_exists( 'esc_html' ) ? esc_html( $s ) : htmlspecialchars( $s, ENT_QUOTES );
		};
		$html = '<h2>Frequently Asked Questions</h2>';
		$seen = [];
		foreach ( $faqs as $faq ) {
			$q = trim( (string) ( $faq['q'] ?? '' ) );
			$a = trim( (string) ( $faq['a'] ?? '' ) );
			if ( $q === '' || $a === '' ) { continue; }
			$key = strtolower( $q );
			if ( isset( $seen[ $key ] ) ) { continue; }
			$seen[ $key ] = true;
			$html .= '<h3>' . $esc( $q ) . '</h3><p>' . $esc( $a ) . '</p>';
		}
		return $html;
	}
}
