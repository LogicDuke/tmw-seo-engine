<?php
/**
 * CategoryDifferentiationScorer — Stage 7 of the universal category pipeline.
 *
 * Scores a new draft against recently generated category pages (and prior
 * content for the same category) with no external API:
 *
 *  - body similarity: Jaccard over 5-gram word shingles of the visible text,
 *    with the category name / primary keyword masked so structural sameness
 *    is measured rather than topical overlap;
 *  - opening similarity: first-sentence shingle overlap;
 *  - heading-sequence similarity: normalized heading list overlap;
 *  - FAQ-question overlap.
 *
 * A rolling store of recent generations lives in one option
 * (tmwseo_cat_diff_recent) holding compact shingle fingerprints — never full
 * content. WordPress storage is optional; comparisons can also be passed in
 * directly (used by tests and by the pipeline for same-category history).
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 * @since   5.9.7
 */

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryDifferentiationScorer {

	public const OPTION_KEY = 'tmwseo_cat_diff_recent';

	/** Max stored recent generations. */
	private const STORE_LIMIT = 12;

	/** Max shingle hashes kept per fingerprint. */
	private const SHINGLE_LIMIT = 500;

	/** Similarity above this fails differentiation. */
	public const MAX_BODY_SIMILARITY    = 0.45;
	public const MAX_HEADING_SIMILARITY = 0.60;
	public const MAX_FAQ_OVERLAP        = 0.50;
	public const MAX_OPENING_SIMILARITY = 0.60;

	/**
	 * Build a compact fingerprint for a draft.
	 *
	 * @param string   $html
	 * @param string[] $mask_terms Category name/primary keyword to mask out.
	 * @return array{slug:string,shingles:int[],headings:string[],faq:string[],opening:int[]}
	 */
	public static function fingerprint( string $html, array $mask_terms = [], string $slug = '' ): array {
		$visible = CategoryQualityGuard::visible( $html );
		foreach ( $mask_terms as $term ) {
			$term = trim( (string) $term );
			if ( $term === '' ) { continue; }
			$visible = (string) preg_replace( '/(?<![\p{L}\p{N}])' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}])/iu', 'topicterm', $visible );
		}

		$headings = [];
		if ( preg_match_all( '/<h[23][^>]*>(.*?)<\/h[23]>/isu', $html, $m ) ) {
			foreach ( $m[1] as $h ) {
				$h = self::normalize( CategoryQualityGuard::visible( (string) $h ) );
				foreach ( $mask_terms as $term ) {
					$h = str_replace( self::normalize( (string) $term ), 'topicterm', $h );
				}
				if ( $h !== '' ) { $headings[] = $h; }
			}
		}

		// FAQ questions ≈ h3 headings after the FAQ h2; approximate by taking
		// headings that end with a question mark.
		$faq = array_values( array_filter( $headings, static function ( string $h ): bool {
			return substr( $h, -1 ) === '?';
		} ) );

		$sentences = preg_split( '/(?<=[.!?])\s+/u', trim( $visible ) ) ?: [];
		$opening   = self::shingles( (string) ( $sentences[0] ?? '' ) );

		return [
			'slug'     => $slug,
			'shingles' => array_slice( self::shingles( $visible ), 0, self::SHINGLE_LIMIT ),
			'headings' => $headings,
			'faq'      => $faq,
			'opening'  => $opening,
		];
	}

	/**
	 * Score a fingerprint against comparison fingerprints.
	 *
	 * @param array               $fp
	 * @param array<int,array>    $comparisons
	 * @return array{max_body:float,max_heading:float,max_faq:float,max_opening:float,worst_source:string,per_source:array<int,array<string,mixed>>,passed:bool}
	 */
	public static function score( array $fp, array $comparisons ): array {
		$per        = [];
		$max_body   = 0.0;
		$max_head   = 0.0;
		$max_faq    = 0.0;
		$max_open   = 0.0;
		$worst      = '';

		foreach ( $comparisons as $cmp ) {
			if ( ! is_array( $cmp ) ) { continue; }
			$body = self::jaccard( (array) ( $fp['shingles'] ?? [] ), (array) ( $cmp['shingles'] ?? [] ) );
			$head = self::list_overlap( (array) ( $fp['headings'] ?? [] ), (array) ( $cmp['headings'] ?? [] ) );
			$faq  = self::list_overlap( (array) ( $fp['faq'] ?? [] ), (array) ( $cmp['faq'] ?? [] ) );
			$open = self::jaccard( (array) ( $fp['opening'] ?? [] ), (array) ( $cmp['opening'] ?? [] ) );

			$per[] = [
				'source'  => (string) ( $cmp['slug'] ?? '' ),
				'body'    => round( $body, 3 ),
				'heading' => round( $head, 3 ),
				'faq'     => round( $faq, 3 ),
				'opening' => round( $open, 3 ),
			];

			if ( $body > $max_body ) { $max_body = $body; $worst = (string) ( $cmp['slug'] ?? '' ); }
			$max_head = max( $max_head, $head );
			$max_faq  = max( $max_faq, $faq );
			$max_open = max( $max_open, $open );
		}

		$passed = $max_body <= self::MAX_BODY_SIMILARITY
			&& $max_head <= self::MAX_HEADING_SIMILARITY
			&& $max_faq <= self::MAX_FAQ_OVERLAP
			&& $max_open <= self::MAX_OPENING_SIMILARITY;

		return [
			'max_body'     => round( $max_body, 3 ),
			'max_heading'  => round( $max_head, 3 ),
			'max_faq'      => round( $max_faq, 3 ),
			'max_opening'  => round( $max_open, 3 ),
			'worst_source' => $worst,
			'per_source'   => $per,
			'passed'       => $passed,
		];
	}

	/** Load stored recent fingerprints, excluding a slug (same-category history is compared separately). */
	public static function recent_fingerprints( string $exclude_slug = '' ): array {
		if ( ! function_exists( 'get_option' ) ) { return []; }
		$stored = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $stored ) ) { return []; }
		return array_values( array_filter( $stored, static function ( $fp ) use ( $exclude_slug ): bool {
			return is_array( $fp ) && (string) ( $fp['slug'] ?? '' ) !== $exclude_slug;
		} ) );
	}

	/** Persist a fingerprint into the rolling store. */
	public static function remember( array $fp ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) { return; }
		$stored = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $stored ) ) { $stored = []; }
		$slug   = (string) ( $fp['slug'] ?? '' );
		$stored = array_values( array_filter( $stored, static function ( $item ) use ( $slug ): bool {
			return is_array( $item ) && (string) ( $item['slug'] ?? '' ) !== $slug;
		} ) );
		$stored[] = $fp;
		if ( count( $stored ) > self::STORE_LIMIT ) {
			$stored = array_slice( $stored, -self::STORE_LIMIT );
		}
		update_option( self::OPTION_KEY, $stored, false );
	}

	// ── primitives ─────────────────────────────────────────────────────────

	/** @return int[] crc32 hashes of 5-gram word shingles */
	public static function shingles( string $text, int $n = 5 ): array {
		$text  = strtolower( preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text ) ?: '' );
		$words = preg_split( '/\s+/u', trim( $text ) ) ?: [];
		$out   = [];
		$count = count( $words );
		if ( $count < $n ) {
			return $count > 0 ? [ crc32( implode( ' ', $words ) ) ] : [];
		}
		for ( $i = 0; $i <= $count - $n; $i++ ) {
			$out[] = crc32( implode( ' ', array_slice( $words, $i, $n ) ) );
		}
		return array_values( array_unique( $out ) );
	}

	/** @param int[] $a @param int[] $b */
	public static function jaccard( array $a, array $b ): float {
		if ( empty( $a ) || empty( $b ) ) { return 0.0; }
		$a  = array_unique( $a );
		$b  = array_unique( $b );
		$in = count( array_intersect( $a, $b ) );
		$un = count( $a ) + count( $b ) - $in;
		return $un > 0 ? $in / $un : 0.0;
	}

	/** @param string[] $a @param string[] $b */
	public static function list_overlap( array $a, array $b ): float {
		if ( empty( $a ) || empty( $b ) ) { return 0.0; }
		$in = count( array_intersect( $a, $b ) );
		return $in / max( 1, min( count( $a ), count( $b ) ) );
	}

	private static function normalize( string $s ): string {
		return strtolower( trim( preg_replace( '/\s+/u', ' ', $s ) ?: '' ) );
	}
}
