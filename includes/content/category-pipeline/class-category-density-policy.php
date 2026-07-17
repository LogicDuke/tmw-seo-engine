<?php
/**
 * CategoryDensityPolicy — universal primary-keyword density system (v5.9.11).
 *
 * Replaces the fixed 3-5 exact-primary-use contract with a dynamic target
 * calculated AFTER final HTML composition, from runtime inputs only:
 * final visible word count, the tracked Rank Math keyword set, and the
 * configured density boundaries. No category name or slug ever enters
 * this class.
 *
 * Rank Math contract (verified in the shipped analyzer source, not
 * assumed): the density test counts the COMBINED matches of ALL stored
 * focus keywords — it builds one alternation regex from every keyword's
 * word sequence (words joined by non-word separators, case-insensitive)
 * and reports matches/wordCount*100 as "the Focus Keyword and combination
 * appears N times". Score buckets in the shipped build:
 *
 *   density < 0.5          → "low"  (fail)
 *   0.5  <= density < 0.75 → "fair" (the audited orange 0.73)
 *   0.76 <= density < 1.0  → "good"
 *   1.0  <= density <= 2.5 → "best"
 *   density > 2.5          → "high" (fail)
 *
 * Policy: target the "best" band floor (1.0) as the minimum and stay well
 * under the 2.5 fail ceiling (2.2, matching the existing family budget) —
 * conservative and natural at every article length. Both bounds are
 * filterable for future tuning without code changes.
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 * @since   5.9.11
 */

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryDensityPolicy {

	/** Combined-keyword density floor (percent) — Rank Math "best" band start. */
	public const MIN_DENSITY = 1.0;

	/** Combined-keyword density ceiling (percent) — safely under Rank Math's 2.5 fail line. */
	public const MAX_DENSITY = 2.2;

	/** Structural floor for the exact PRIMARY phrase regardless of length (first paragraph + H2 + body). */
	public const PRIMARY_STRUCTURAL_MIN = 3;

	/**
	 * v5.9.13 — FINAL-DOCUMENT CONTEXT (root cause of the divergent live
	 * densities). The pipeline composes and validates a FRAGMENT, but the
	 * persisted post_content is prefix (existing content before the AI
	 * marker) + fragment + suffix (preserved affiliate CTA block), and
	 * Rank Math analyzes THAT full document with its own tokenizer. The
	 * engine therefore validated 1.0%+ on ~600 self-counted words while
	 * the live editor reported 0.87% on 690 Rank Math-counted words.
	 *
	 * When a final context is set (production generation paths only —
	 * tests that don't set it keep the historical behavior), evaluate()
	 * computes word count and combined matches with the Rank Math-
	 * faithful analyzer over prefix + fragment + suffix, so the pipeline
	 * targets and validates the exact number the editor will display.
	 */
	private static string $context_prefix = '';
	private static string $context_suffix = '';
	private static bool   $context_set    = false;

	public static function set_final_context( string $prefix_html, string $suffix_html ): void {
		self::$context_prefix = $prefix_html;
		self::$context_suffix = $suffix_html;
		self::$context_set    = true;
	}

	public static function clear_final_context(): void {
		self::$context_prefix = '';
		self::$context_suffix = '';
		self::$context_set    = false;
	}

	public static function has_final_context(): bool {
		return self::$context_set;
	}

	/**
	 * Visible word count of rendered HTML — the same counting the final
	 * validator uses, so policy and validation can never disagree.
	 */
	public static function word_count( string $html ): int {
		return str_word_count( CategoryQualityGuard::visible( $html ) );
	}

	/**
	 * Rank Math-faithful combined match count for a tracked keyword set.
	 *
	 * One alternation regex, longest keyword first (a position consumed by
	 * a longer phrase is never also counted for a shorter one — matching
	 * the analyzer's single-regex behavior), each keyword's words joined
	 * by one-or-more non-word separators, boundary-guarded so a partial
	 * token never counts ("cam" never matches inside "webcam").
	 *
	 * @param string   $html    Final rendered HTML.
	 * @param string[] $tracked Stored Rank Math keywords (primary first).
	 * @return int
	 */
	public static function combined_matches( string $html, array $tracked ): int {
		$pattern = self::combined_pattern( $tracked );
		if ( $pattern === '' ) { return 0; }
		return (int) preg_match_all( $pattern, CategoryQualityGuard::visible( $html ) );
	}

	/** Exact-phrase count for one keyword (boundary-guarded, separator-tolerant). */
	public static function keyword_matches( string $html, string $keyword ): int {
		$keyword = trim( $keyword );
		if ( $keyword === '' ) { return 0; }
		return (int) preg_match_all( self::keyword_pattern( $keyword ), CategoryQualityGuard::visible( $html ) );
	}

	/** @param string[] $tracked */
	public static function combined_pattern( array $tracked ): string {
		$alternatives = [];
		$seen         = [];
		foreach ( $tracked as $kw ) {
			$kw  = trim( (string) $kw );
			$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $kw, 'UTF-8' ) : strtolower( $kw );
			if ( $kw === '' || isset( $seen[ $key ] ) ) { continue; }
			$seen[ $key ]   = true;
			$alternatives[] = $kw;
		}
		if ( empty( $alternatives ) ) { return ''; }
		// Longest first: the analyzer's alternation consumes each position once.
		usort( $alternatives, static function ( $a, $b ) {
			return strlen( (string) $b ) <=> strlen( (string) $a );
		} );
		$parts = array_map( [ self::class, 'phrase_body' ], $alternatives );
		return '/(?<![\p{L}\p{N}])(?:' . implode( '|', $parts ) . ')(?![\p{L}\p{N}])/iu';
	}

	public static function keyword_pattern( string $keyword ): string {
		return '/(?<![\p{L}\p{N}])' . self::phrase_body( $keyword ) . '(?![\p{L}\p{N}])/iu';
	}

	/** Keyword words joined by one-or-more non-word separators (Rank Math's word-sequence join). */
	private static function phrase_body( string $keyword ): string {
		$words = preg_split( '/\s+/', trim( $keyword ) ) ?: [];
		$words = array_map( static function ( $w ) { return preg_quote( (string) $w, '/' ); }, $words );
		return implode( '[\s\W]+', array_filter( $words, 'strlen' ) );
	}

	/**
	 * Dynamic targets from the FINAL word count — never from a fixed
	 * per-article assumption and never from the category identity.
	 *
	 * @return array{word_count:int,min_density:float,max_density:float,min_count:int,max_count:int}
	 */
	public static function targets( int $word_count ): array {
		$min_density = self::MIN_DENSITY;
		$max_density = self::MAX_DENSITY;
		if ( function_exists( 'apply_filters' ) ) {
			$min_density = (float) apply_filters( 'tmwseo_category_min_combined_density', $min_density );
			$max_density = (float) apply_filters( 'tmwseo_category_max_combined_density', $max_density );
		}
		$min_count = (int) ceil( $word_count * $min_density / 100 );
		$min_count = max( 1, $min_count );
		$max_count = (int) floor( $word_count * $max_density / 100 );
		// A degenerate tiny article can invert the bounds; keep them sane.
		if ( $max_count < $min_count ) { $max_count = $min_count; }
		return [
			'word_count'  => $word_count,
			'min_density' => $min_density,
			'max_density' => $max_density,
			'min_count'   => $min_count,
			'max_count'   => $max_count,
		];
	}

	/**
	 * Full density evaluation of a rendered page.
	 *
	 * @param string   $html    Final rendered HTML (FAQ included).
	 * @param string[] $tracked Stored Rank Math keywords (primary first).
	 * @return array{
	 *   word_count:int, combined_count:int, density:float,
	 *   min_density:float, max_density:float, min_count:int, max_count:int,
	 *   needed:int, excess:int, status:string
	 * }
	 */
	public static function evaluate( string $html, array $tracked ): array {
		if ( self::$context_set && class_exists( '\\TMWSEO\\Engine\\Content\\RankMathChipAnalyzer' ) ) {
			// Production paths: count exactly like the shipped Rank Math
			// analyzer, over the exact document it will analyze.
			$full     = self::$context_prefix . "\n" . $html . "\n" . self::$context_suffix;
			$words    = \TMWSEO\Engine\Content\RankMathChipAnalyzer::word_count( $full );
			$rm       = \TMWSEO\Engine\Content\RankMathChipAnalyzer::combined_density( $full, $tracked );
			$combined = (int) $rm['matches'];
			$targets  = self::targets( $words );
			$density  = (float) $rm['density'];
		} else {
			$words    = self::word_count( $html );
			$targets  = self::targets( $words );
			$combined = self::combined_matches( $html, $tracked );
			$density  = $words > 0 ? round( ( $combined / $words ) * 100, 2 ) : 0.0;
		}

		$status = 'within';
		if ( $combined < $targets['min_count'] ) { $status = 'below'; }
		if ( $combined > $targets['max_count'] ) { $status = 'above'; }

		return [
			'word_count'     => $words,
			'combined_count' => $combined,
			'density'        => $density,
			'min_density'    => $targets['min_density'],
			'max_density'    => $targets['max_density'],
			'min_count'      => $targets['min_count'],
			'max_count'      => $targets['max_count'],
			'needed'         => max( 0, $targets['min_count'] - $combined ),
			'excess'         => max( 0, $combined - $targets['max_count'] ),
			'status'         => $status,
		];
	}
}
