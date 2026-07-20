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

	/** How many middle sections a plan carries (v5.9.9: raised so the
	 * visible word count lands in the 650-850 target band without filler). */
	private const MIN_MIDDLE = 6;
	private const MAX_MIDDLE = 7;

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
	 * @param array<string,mixed> $context      Stage 1 output.
	 * @param string              $intent       Stage 2 intent.
	 * @param int                 $salt         Regeneration salt (0 on first attempt).
	 * @param array<string,mixed> $keyword_plan Stage 3 output (heading keyword roles).
	 * @return array{seed:int,sections:string[],headings:array<string,string>,heading_keyword_map:array<string,array{heading:string,keyword:string}>,variant_seeds:array<string,int>}
	 */
	public static function plan( array $context, string $intent, int $salt = 0, array $keyword_plan = [] ): array {
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

		// v5.9.10 — heading-host guarantee. Every heading-role keyword
		// (primary + up to three supporting) needs its own TOPICAL host
		// section. When the seeded middle selection carries fewer topical
		// sections than heading roles need, promote the missing topical
		// sections into the plan (replacing the lowest-scored non-topical,
		// non-pinned picks) so no supporting keyword loses its H2 merely
		// because the section lottery skipped a host. Deterministic: the
		// promotion order is the fixed topical list.
		$topical_all   = [ 'expectations', 'discovery_advice', 'browse_listings', 'compare_profiles' ];
		$heading_roles = 1; // primary always takes one topical H2
		foreach ( (array) ( $keyword_plan['roles'] ?? [] ) as $role ) {
			if ( in_array( $role, [ 'heading_h2', 'heading_secondary', 'heading_tertiary' ], true ) ) { $heading_roles++; }
		}
		$hosts_needed = min( $heading_roles, count( $topical_all ) );
		$have_topical = count( array_intersect( $middle, $topical_all ) );
		if ( $have_topical < $hosts_needed ) {
			$missing = array_values( array_diff( $topical_all, $middle ) );
			// Replace non-topical, non-pinned sections from the END of the
			// scored pick order (the weakest picks) first.
			for ( $i = count( $middle ) - 1; $i >= 0 && $have_topical < $hosts_needed && ! empty( $missing ); $i-- ) {
				$candidate = $middle[ $i ];
				if ( in_array( $candidate, $topical_all, true ) ) { continue; }
				if ( in_array( $candidate, $pinned, true ) ) { continue; }
				$middle[ $i ] = (string) array_shift( $missing );
				$have_topical++;
			}
			// Pool still short (middle smaller than hosts_needed): append.
			while ( $have_topical < $hosts_needed && ! empty( $missing ) ) {
				$middle[] = (string) array_shift( $missing );
				$have_topical++;
			}
		}

		// Deterministic order shuffle of the middle sections, with one
		// coherence constraint: 'expectations' (context-setting) always
		// precedes 'compare_profiles' and 'discovery_advice' when present.
		$middle = self::seeded_order( $middle, $seed );
		$middle = self::enforce_coherence( $middle );

		// v5.9.9 structure contract: opening → body sections → related
		// navigation (internal links) → conclusion → FAQ LAST. The closing
		// paragraph is the page's conclusion and must precede the FAQ; no
		// orphan paragraph may follow the final FAQ answer.
		$sections = array_merge( [ 'intro' ], $middle, [ 'related_navigation', 'closing', 'faq' ] );

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

		$heading_keyword_map = self::assign_keyword_headings( $headings, $sections, $context, $keyword_plan, $seed );

		return [
			'seed'                => $seed,
			'salt'                => $salt,
			'intent'              => $intent,
			'sections'            => $sections,
			'headings'            => $headings,
			'heading_keyword_map' => $heading_keyword_map,
			'variant_seeds'       => $variant_seeds,
		];
	}

	/**
	 * Keyword → heading assignment (v5.9.9).
	 *
	 * Guarantees, using the library's heading_templates pools:
	 *  - exactly ONE H2 carries the exact primary keyword (requirement:
	 *    "at least one H2 must contain the exact primary keyword", plus
	 *    "the primary keyword must not appear in every heading");
	 *  - when heading-safe supporting keywords exist, ONE additional
	 *    heading carries one supporting keyword (role heading_h2) and,
	 *    when a second heading-safe term exists and enough sections are
	 *    planned, one more heading carries it (role heading_secondary);
	 *  - a keyword-bearing heading is only attached to sections whose
	 *    content genuinely answers that keyword's intent (the topical
	 *    sections: expectations, discovery_advice, browse_listings,
	 *    compare_profiles) — never to safety/availability boilerplate.
	 *
	 * @param array<string,string> $headings by reference — updated in place.
	 * @return array<string,array{heading:string,keyword:string,role:string}> section => assignment
	 */
	private static function assign_keyword_headings( array &$headings, array $sections, array $context, array $keyword_plan, int $seed ): array {
		$library   = CategoryDraftComposer::library();
		$templates = (array) ( $library['heading_templates'] ?? [] );
		$primary   = trim( (string) ( $keyword_plan['primary'] ?? $context['primary_keyword'] ?? '' ) );
		$map       = [];

		// Sections whose copy is about the category topic itself — the only
		// legitimate hosts for a keyword heading.
		$topical = array_values( array_intersect(
			$sections,
			[ 'expectations', 'discovery_advice', 'browse_listings', 'compare_profiles' ]
		) );
		if ( $primary === '' || empty( $topical ) ) { return $map; }

		// 1. Primary keyword H2 — reuse an existing heading when it already
		// contains the exact primary (common when the category name IS the
		// primary keyword); otherwise rewrite the first topical heading from
		// the primary template pool.
		$primary_pattern = CategoryFinalValidator::exact_keyword_pattern( $primary );
		$has_primary     = '';
		$primary_pool    = array_values( (array) ( $templates['primary'] ?? [] ) );
		foreach ( $topical as $section ) {
			$heading = (string) ( $headings[ $section ] ?? '' );
			if ( $heading === '' || preg_match( $primary_pattern, $heading ) !== 1 ) { continue; }
			if ( $has_primary === '' ) {
				$has_primary = $section;
				continue;
			}
			$headings[ $section ] = self::non_primary_heading( $section, $seed );
		}
		if ( $has_primary === '' ) {
			$pool = $primary_pool;
			if ( ! empty( $pool ) ) {
				$section              = $topical[0];
				$template             = (string) $pool[ $seed % count( $pool ) ];
				$headings[ $section ] = str_replace( '{{primary_keyword}}', $primary, $template );
				$has_primary          = $section;
			}
		}
		if ( $has_primary !== '' ) {
			$map[ $has_primary ] = [ 'heading' => $headings[ $has_primary ], 'keyword' => $primary, 'role' => 'primary_h2' ];
		}

		// 2. Supporting keyword headings from the assigned roles (v5.9.10:
		// heading_tertiary joins the two existing roles so up to THREE
		// supporting keywords each carry one topical H2). A heading-role
		// keyword that finds no host (or whose rendered heading would
		// duplicate the primary H2 or an already-used heading) is recorded
		// under the reserved '__unplaced' key — the pipeline demotes it to
		// an FAQ question so its subheading placement is never silently lost.
		$roles     = (array) ( $keyword_plan['roles'] ?? [] );
		$hosts     = array_values( array_diff( $topical, [ $has_primary ] ) );
		$unplaced  = [];
		$used_head = [ strtolower( (string) ( $headings[ $has_primary ] ?? '' ) ) => true ];
		foreach ( $roles as $kw => $role ) {
			if ( ! in_array( $role, [ 'heading_h2', 'heading_secondary', 'heading_tertiary' ], true ) ) { continue; }
			$pool = array_values( (array) ( $templates['supporting'] ?? [] ) );
			if ( empty( $hosts ) || empty( $pool ) ) { $unplaced[] = (string) $kw; continue; }
			$section = (string) array_shift( $hosts );
			$heading = '';
			$n       = count( $pool );
			$start   = self::seed( $seed . '~' . $kw ) % $n;
			for ( $i = 0; $i < $n; $i++ ) {
				$candidate = str_replace( '{{kw}}', self::title_case( (string) $kw ), (string) $pool[ ( $start + $i ) % $n ] );
				if ( isset( $used_head[ strtolower( $candidate ) ] ) ) { continue; }
				$heading = $candidate;
				break;
			}
			if ( $heading === '' ) { $unplaced[] = (string) $kw; array_unshift( $hosts, $section ); continue; }
			$used_head[ strtolower( $heading ) ] = true;
			$headings[ $section ] = $heading;
			$map[ $section ]      = [ 'heading' => $heading, 'keyword' => (string) $kw, 'role' => $role ];
		}
		if ( ! empty( $unplaced ) ) {
			$map['__unplaced'] = [ 'heading' => '', 'keyword' => implode( '|', $unplaced ), 'role' => 'unplaced' ];
		}

		return $map;
	}

	/** Non-primary topical heading fallback used when duplicate primary H2s are demoted. */
	private static function non_primary_heading( string $section, int $seed ): string {
		$pools = [
			'expectations'      => [ 'What This Page Covers', 'Setting the Right Expectations' ],
			'discovery_advice'  => [ 'Reading a Page Before You Commit', 'Checks Worth Making First' ],
			'browse_listings'   => [ 'Building a Better Shortlist', 'Browsing the Listings Carefully' ],
			'compare_profiles' => [ 'Comparing Profiles With Care', 'Making the Final Comparison' ],
		];
		$pool = $pools[ $section ] ?? [ 'Details Worth Checking' ];
		return $pool[ self::seed( $seed . '~non-primary~' . $section ) % count( $pool ) ];
	}

	/** Title-case a keyword for heading use ("free live cams" → "Free Live Cams"; common acronyms fully capitalised). */
	private static function title_case( string $kw ): string {
		$cased = (string) preg_replace_callback( '/\b\p{Ll}/u', static function ( $m ) {
			return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $m[0], 'UTF-8' ) : strtoupper( $m[0] );
		}, $kw );
		return (string) preg_replace_callback( '/\b(Tv|Hd|Vr|Bbw|Milf|Joi|Pov|Bdsm)\b/u', static function ( $m ) {
			return strtoupper( $m[0] );
		}, $cased );
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
