<?php
/**
 * CategoryContextBuilder â€” Stage 1 of the universal category pipeline.
 *
 * Builds a plain context array from real, available data only. Every field
 * is either verified at build time or absent; downstream stages treat an
 * absent field as "do not mention". Nothing here invents counts, schedules,
 * platforms, filters, or availability.
 *
 * The builder is WordPress-optional: build_from_parts() is pure and fully
 * unit-testable, while build_for_post() gathers the parts from WP when the
 * environment provides it.
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 * @since   5.9.7
 */

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryContextBuilder {

	/**
	 * Assemble a normalized context from raw parts. Pure â€” no WP calls.
	 *
	 * Recognized parts (all optional except category_name):
	 *   category_id, category_name, category_slug, description,
	 *   primary_keyword, approved_keywords[], keywords_source,
	 *   model_count (int|null), video_count (int|null),
	 *   related_categories[] (verified names only),
	 *   models_url, videos_url, site_name,
	 *   verified_flags[] (e.g. 'no_account_browsing','filters','schedules')
	 *
	 * @param array<string,mixed> $parts
	 * @return array<string,mixed>
	 */
	public static function build_from_parts( array $parts ): array {
		$name = trim( (string) ( $parts['category_name'] ?? '' ) );
		$slug = trim( (string) ( $parts['category_slug'] ?? '' ) );
		if ( $slug === '' && $name !== '' ) {
			$slug = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $name ) ?: '' );
			$slug = trim( $slug, '-' );
		}

		$primary = trim( (string) ( $parts['primary_keyword'] ?? '' ) );
		if ( $primary === '' ) {
			$primary = $name;
		}

		$approved = [];
		$seen     = [];
		foreach ( (array) ( $parts['approved_keywords'] ?? [] ) as $kw ) {
			$kw = trim( (string) $kw );
			if ( $kw === '' ) { continue; }
			$key = self::lc( $kw );
			if ( isset( $seen[ $key ] ) ) { continue; }
			$seen[ $key ] = true;
			$approved[]   = $kw;
		}

		// v5.9.12 — stored Rank Math chips travel VERBATIM. When present,
		// these exact phrases (the analyzer's own additional keywords) are
		// what the planner must activate and place; nothing may substitute,
		// reorder, or drop them.
		$stored_chips = [];
		foreach ( (array) ( $parts['stored_chips'] ?? [] ) as $kw ) {
			$kw = trim( (string) $kw );
			if ( $kw !== '' ) { $stored_chips[] = $kw; }
		}

		// Related categories: plain names (legacy) or {name,url} pairs
		// (v5.9.9). A URL is kept only when it is verified internal â€” same
		// host as the site's own models/videos URLs or protocol-relative to
		// them; anything else is stripped so the composer never links out.
		$home_host = self::host_of( (string) ( $parts['models_url'] ?? '' ) );
		if ( $home_host === '' ) { $home_host = self::host_of( (string) ( $parts['videos_url'] ?? '' ) ); }
		$related = [];
		foreach ( (array) ( $parts['related_categories'] ?? [] ) as $rel ) {
			if ( is_array( $rel ) ) {
				$rel_name = trim( (string) ( $rel['name'] ?? '' ) );
				$rel_url  = trim( (string) ( $rel['url'] ?? '' ) );
			} else {
				$rel_name = trim( (string) $rel );
				$rel_url  = '';
			}
			if ( $rel_name === '' || self::lc( $rel_name ) === self::lc( $name ) ) { continue; }
			if ( $rel_url !== '' && ( $home_host === '' || self::host_of( $rel_url ) !== $home_host ) ) {
				$rel_url = ''; // not verified internal â†’ name only, never a link
			}
			$related[] = [ 'name' => $rel_name, 'url' => $rel_url ];
		}

		$model_count = self::nullable_count( $parts['model_count'] ?? null );
		$video_count = self::nullable_count( $parts['video_count'] ?? null );

		return [
			'category_id'        => (int) ( $parts['category_id'] ?? 0 ),
			'category_name'      => $name,
			'category_slug'      => $slug,
			'description'        => trim( (string) ( $parts['description'] ?? '' ) ),
			'primary_keyword'    => $primary,
			'approved_keywords'  => $approved,
			'stored_chips'       => $stored_chips,
			'keywords_source'    => trim( (string) ( $parts['keywords_source'] ?? '' ) ),
			'model_count'        => $model_count,
			'video_count'        => $video_count,
			'related_categories' => array_slice( $related, 0, 6 ),
			'models_url'         => trim( (string) ( $parts['models_url'] ?? '' ) ),
			'videos_url'         => trim( (string) ( $parts['videos_url'] ?? '' ) ),
			'site_name'          => trim( (string) ( $parts['site_name'] ?? '' ) ),
			'verified_flags'     => self::with_evidence_flags(
				array_values( array_filter( array_map( 'strval', (array) ( $parts['verified_flags'] ?? [] ) ) ) ),
				$model_count,
				$video_count
			),
		];
	}

	/**
	 * Evidence-derived flags (v5.9.8): a known model/video count is itself
	 * the verification for qualitative scale wording about that side. These
	 * flags are DERIVED from real data, never asserted by callers.
	 *
	 * @param string[] $flags
	 * @return string[]
	 */
	private static function with_evidence_flags( array $flags, $model_count, $video_count ): array {
		if ( $model_count !== null ) { $flags[] = 'model_scale'; }
		if ( $video_count !== null ) { $flags[] = 'video_scale'; }
		return array_values( array_unique( $flags ) );
	}

	/**
	 * WordPress wrapper â€” gathers real data for a tmw_category_page post.
	 *
	 * @param \WP_Post            $post
	 * @param array<string,mixed> $keyword_pack build_category_keyword_pack() output.
	 */
	public static function build_for_post( $post, array $keyword_pack ): array {
		$parts = [
			'category_id'   => (int) $post->ID,
			'category_name' => trim( (string) $post->post_title ),
			'category_slug' => trim( (string) $post->post_name ),
		];

		$parts['primary_keyword'] = trim( (string) ( $keyword_pack['primary'] ?? '' ) );

		$approved = [];
		foreach ( [ 'rankmath_additional', 'additional', 'content_terms' ] as $key ) {
			if ( ! empty( $keyword_pack[ $key ] ) && is_array( $keyword_pack[ $key ] ) ) {
				foreach ( $keyword_pack[ $key ] as $kw ) { $approved[] = (string) $kw; }
			}
		}
		$parts['approved_keywords'] = $approved;
		// v5.9.15 — every active Rank Math extra is an enforced chip,
		// regardless of source. Stored CSV, approved-pool first generation,
		// and regenerated/stale CSV paths all converge on the same contract:
		// the active extras in rankmath_additional are planned, placed, and
		// validated as exact phrases before anything is persisted.
		if ( ! empty( $keyword_pack['rankmath_additional'] ) && is_array( $keyword_pack['rankmath_additional'] ) ) {
			$parts['stored_chips'] = array_values( array_filter( array_map( 'strval', $keyword_pack['rankmath_additional'] ), 'strlen' ) );
		}
		$parts['keywords_source']   = (string) ( $keyword_pack['content_terms_source'] ?? ( isset( $keyword_pack['sources']['category_pool'] ) ? 'category_db_approved' : '' ) );

		if ( function_exists( 'get_bloginfo' ) ) {
			$parts['site_name'] = (string) get_bloginfo( 'name' );
		}
		if ( function_exists( 'home_url' ) ) {
			$parts['models_url'] = (string) home_url( '/webcam-models/' );
			$parts['videos_url'] = (string) home_url( '/videos/' );
		}

		// Real term data: counts + related sibling terms, only when the linked
		// term genuinely resolves. Counts stay null (unknown) otherwise.
		$term = self::resolve_linked_term( $post );
		if ( $term && isset( $term->count ) ) {
			$parts['model_count'] = (int) $term->count;
			$video_count = self::count_videos_for_term( $term );
			if ( $video_count !== null ) {
				$parts['video_count'] = $video_count;
			}
		}
		if ( $term && function_exists( 'get_terms' ) ) {
			$siblings = get_terms( [
				'taxonomy'   => $term->taxonomy,
				'exclude'    => [ (int) $term->term_id ],
				'hide_empty' => true,
				'number'     => 6,
			] );
			if ( is_array( $siblings ) ) {
				// v5.9.9: carry the VERIFIED archive URL of each sibling so
				// related-category mentions render as real internal anchor
				// links. get_term_link() resolving is the verification; a
				// sibling whose link fails stays a plain (unlinked) name.
				$related = [];
				foreach ( $siblings as $sibling ) {
					if ( ! is_object( $sibling ) || ! isset( $sibling->name ) ) { continue; }
					$url = '';
					if ( function_exists( 'get_term_link' ) ) {
						$link = get_term_link( $sibling );
						if ( is_string( $link ) && $link !== '' && ( ! function_exists( 'is_wp_error' ) || ! is_wp_error( $link ) ) ) {
							$url = $link;
						}
					}
					$related[] = [ 'name' => (string) $sibling->name, 'url' => $url ];
				}
				$parts['related_categories'] = $related;
			}
		}

		return self::build_from_parts( $parts );
	}

	/** Resolve the category term linked to a tmw_category_page draft. */
	private static function resolve_linked_term( $post ) {
		if ( ! function_exists( 'get_post_meta' ) || ! function_exists( 'get_term' ) ) {
			return null;
		}
		foreach ( [ '_tmwseo_term_id', '_tmwseo_category_term_id', '_tmwseo_target_term_id', 'target_term_id', '_tmw_category_term_id' ] as $meta_key ) {
			$term_id = (int) get_post_meta( (int) $post->ID, $meta_key, true );
			if ( $term_id > 0 ) {
				$term = get_term( $term_id );
				if ( $term && ! is_wp_error( $term ) ) {
					return $term;
				}
			}
		}
		if ( function_exists( 'get_term_by' ) ) {
			foreach ( [ 'category', 'post_tag' ] as $tax ) {
				$term = get_term_by( 'slug', (string) $post->post_name, $tax );
				if ( $term && ! is_wp_error( $term ) ) {
					return $term;
				}
			}
		}
		return null;
	}

	/** Count video posts linked to the resolved term when WP query helpers are available. */
	private static function count_videos_for_term( $term ): ?int {
		if ( ! isset( $term->term_id, $term->taxonomy ) || ! class_exists( '\WP_Query' ) ) { return null; }
		$query = new \WP_Query( [
			'post_type'      => [ 'tmw_video', 'video' ],
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'no_found_rows'  => false,
			'tax_query'      => [ [
				'taxonomy' => (string) $term->taxonomy,
				'field'    => 'term_id',
				'terms'    => [ (int) $term->term_id ],
			] ],
		] );
		if ( isset( $query->found_posts ) && is_numeric( $query->found_posts ) ) {
			return max( 0, (int) $query->found_posts );
		}
		return null;
	}

	/** Lowercased host of a URL ('' when unparsable). */
	private static function host_of( string $url ): string {
		$host = parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) ? self::lc( $host ) : '';
	}

	private static function nullable_count( $value ): ?int {
		if ( $value === null || $value === '' ) { return null; }
		if ( is_numeric( $value ) && (int) $value >= 0 ) { return (int) $value; }
		return null;
	}

	private static function lc( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}
}
