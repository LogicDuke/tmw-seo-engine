<?php
/**
 * CategoryContentPlanner — Stage 4 of the universal category pipeline.
 *
 * Selects a category-specific subset and order of reusable section purposes
 * from the universal library. Selection is deterministic — seeded by slug,
 * primary keyword, intent, and a regeneration salt — so the same inputs
 * always plan the same page, while different categories plan different
 * pages (different sections, different order, different heading wording).
 *
 * No category is ever handled by name; variation comes from the seed and
 * from intent-weighted relevance, never from per-category branches.
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 * @since   5.9.7
 */

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryContentPlanner {

	/** How many middle sections a plan carries. */
	private const MIN_MIDDLE = 4;
	private const MAX_MIDDLE = 5;

	/**
	 * Intent → middle-section relevance weights. Sections absent for an
	 * intent inherit the 'default' weight; weight 0 removes a section for
	 * that intent. The planner never uses every section every time.
	 *
	 * @var array<string,array<string,int>>
	 */
	private const RELEVANCE = [
		'default' => [
			'expectations'      => 6,
			'browse_listings'   => 5,
			'public_vs_private' => 4,
			'compare_profiles'  => 5,
			'live_vs_recorded'  => 3,
			'discovery_advice'  => 5,
			'privacy_safety'    => 3,
			'platform_links'    => 4,
			'availability'      => 3,
			'first_time'        => 2,
			'returning_visitors'=> 1,
		],
		'free_access_pricing' => [
			'expectations'      => 9,
			'public_vs_private' => 9,
			'availability'      => 4,
			'privacy_safety'    => 4,
			'live_vs_recorded'  => 2,
		],
		'body_type' => [
			'discovery_advice'  => 7,
			'compare_profiles'  => 7,
			'expectations'      => 6,
			'public_vs_private' => 3,
		],
		'appearance_trait' => [
			'discovery_advice'  => 7,
			'compare_profiles'  => 7,
			'expectations'      => 6,
			'live_vs_recorded'  => 4,
		],
		'ethnicity_regional' => [
			'discovery_advice'  => 7,
			'expectations'      => 6,
			'compare_profiles'  => 6,
			'availability'      => 4,
		],
		'interaction_style' => [
			'public_vs_private' => 8,
			'discovery_advice'  => 7,
			'compare_profiles'  => 6,
			'platform_links'    => 5,
		],
		'content_format' => [
			'live_vs_recorded'  => 9,
			'browse_listings'   => 6,
			'availability'      => 5,
		],
		'age_style' => [
			'expectations'      => 7,
			'discovery_advice'  => 7,
			'compare_profiles'  => 6,
		],
		'language_location' => [
			'compare_profiles'  => 7,
			'availability'      => 6,
			'discovery_advice'  => 6,
		],
		'activity_fetish' => [
			'expectations'      => 8,
			'public_vs_private' => 7,
			'privacy_safety'    => 6,
			'compare_profiles'  => 5,
		],
		'broad_discovery' => [
			'browse_listings'   => 7,
			'discovery_advice'  => 7,
			'first_time'        => 5,
			'expectations'      => 4,
		],
	];

	/**
	 * Build the section plan.
	 *
	 * @param array<string,mixed> $context Stage 1 output.
	 * @param string              $intent  Stage 2 intent.
	 * @param int                 $salt    Regeneration salt (0 on first attempt).
	 * @return array{seed:int,sections:string[],headings:array<string,string>,variant_seeds:array<string,int>}
	 */
	public static function plan( array $context, string $intent, int $salt = 0 ): array {
		$slug    = (string) ( $context['category_slug'] ?? '' );
		$primary = (string) ( $context['primary_keyword'] ?? '' );
		$seed    = self::seed( $slug . '|' . $primary . '|' . $intent . '|' . $salt );

		$weights = self::RELEVANCE['default'];
		if ( isset( self::RELEVANCE[ $intent ] ) ) {
			foreach ( self::RELEVANCE[ $intent ] as $section => $w ) {
				$weights[ $section ] = $w;
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'tmwseo_category_section_weights', $weights, $intent, $context );
			if ( is_array( $filtered ) && ! empty( $filtered ) ) { $weights = $filtered; }
		}

		// Deterministic jitter so equal-weight sections don't tie the same
		// way for every category: each section's effective weight gets a
		// per-category nudge in [0,8].
		$scored = [];
		foreach ( $weights as $section => $weight ) {
			if ( $weight <= 0 ) { continue; }
			$jitter             = self::seed( $slug . '#' . $section . '#' . $salt ) % 15;
			$scored[ $section ] = ( $weight * 10 ) + $jitter;
		}
		arsort( $scored );

		$middle_count = self::MIN_MIDDLE + ( $seed % ( self::MAX_MIDDLE - self::MIN_MIDDLE + 1 ) );

		// 'expectations' and 'discovery_advice' carry intent-specific pools
		// for every intent; pinning them (plus the intent-pooled intro and
		// closing) structurally guarantees the specificity minimum of three
		// materially intent-specific paragraphs per page.
		$pinned = [];
		foreach ( [ 'expectations', 'discovery_advice' ] as $pin ) {
			if ( ( $weights[ $pin ] ?? 0 ) > 0 ) { $pinned[] = $pin; }
		}
		$middle = $pinned;
		foreach ( array_keys( $scored ) as $section ) {
			if ( count( $middle ) >= $middle_count ) { break; }
			if ( ! in_array( $section, $middle, true ) ) { $middle[] = $section; }
		}

		// Deterministic order shuffle of the middle sections, with one
		// coherence constraint: 'expectations' (context-setting) always
		// precedes 'compare_profiles' and 'discovery_advice' when present.
		$middle = self::seeded_order( $middle, $seed );
		$middle = self::enforce_coherence( $middle );

		$sections = array_merge( [ 'intro' ], $middle, [ 'related_navigation', 'faq', 'closing' ] );

		// Pick a heading wording per section (deterministic, per category).
		$headings      = [];
		$variant_seeds = [];
		$name_headings = 0;
		foreach ( $sections as $section ) {
			$variant_seeds[ $section ] = self::seed( $slug . '@' . $section . '@' . $salt );
			$heading = self::pick_heading( $section, $context, $variant_seeds[ $section ], $name_headings );
			if ( $heading !== '' ) {
				$headings[ $section ] = $heading;
				if ( stripos( $heading, (string) ( $context['category_name'] ?? "\x00" ) ) !== false ) {
					$name_headings++;
				}
			}
		}

		return [
			'seed'          => $seed,
			'salt'          => $salt,
			'intent'        => $intent,
			'sections'      => $sections,
			'headings'      => $headings,
			'variant_seeds' => $variant_seeds,
		];
	}

	/** Deterministic heading choice from the library, capping category-name headings at 2. */
	private static function pick_heading( string $section, array $context, int $seed, int $name_headings_so_far ): string {
		$library = CategoryDraftComposer::library();
		$purpose = $library['purposes'][ $section ] ?? null;
		if ( ! is_array( $purpose ) ) { return ''; }
		$headings = (array) ( $purpose['headings'] ?? [] );
		if ( empty( $headings ) ) { return ''; }

		$name = (string) ( $context['category_name'] ?? '' );
		$idx  = $seed % count( $headings );

		// Cap: at most 2 headings per page may contain the exact category name.
		for ( $i = 0; $i < count( $headings ); $i++ ) {
			$candidate = (string) $headings[ ( $idx + $i ) % count( $headings ) ];
			$uses_name = strpos( $candidate, '{{category_name}}' ) !== false;
			if ( $uses_name && $name_headings_so_far >= 2 ) { continue; }
			if ( $uses_name && $name === '' ) { continue; }
			return str_replace( '{{category_name}}', $name, $candidate );
		}
		return str_replace( '{{category_name}}', $name, (string) $headings[ $idx ] );
	}

	/** @param string[] $sections */
	private static function seeded_order( array $sections, int $seed ): array {
		$decorated = [];
		foreach ( $sections as $i => $section ) {
			$decorated[ $section ] = self::seed( $seed . ':' . $section ) % 1000;
		}
		asort( $decorated );
		return array_keys( $decorated );
	}

	/** @param string[] $middle */
	private static function enforce_coherence( array $middle ): array {
		$pos = array_flip( $middle );
		foreach ( [ 'compare_profiles', 'discovery_advice' ] as $later ) {
			if ( isset( $pos['expectations'], $pos[ $later ] ) && $pos['expectations'] > $pos[ $later ] ) {
				// swap
				$i = $pos['expectations'];
				$j = $pos[ $later ];
				$middle[ $i ] = $later;
				$middle[ $j ] = 'expectations';
				$pos = array_flip( $middle );
			}
		}
		return $middle;
	}

	public static function seed( string $input ): int {
		return abs( crc32( $input ) );
	}
}
