<?php
/**
 * CategoryContextBuilder — Stage 1 of the universal category pipeline.
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
	 * Assemble a normalized context from raw parts. Pure — no WP calls.
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

		$related = [];
		foreach ( (array) ( $parts['related_categories'] ?? [] ) as $rel ) {
			$rel = trim( (string) $rel );
			if ( $rel !== '' && self::lc( $rel ) !== self::lc( $name ) ) {
				$related[] = $rel;
			}
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
	 * WordPress wrapper — gathers real data for a tmw_category_page post.
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
		}
		if ( $term && function_exists( 'get_terms' ) ) {
			$siblings = get_terms( [
				'taxonomy'   => $term->taxonomy,
				'exclude'    => [ (int) $term->term_id ],
				'hide_empty' => true,
				'number'     => 6,
				'fields'     => 'names',
			] );
			if ( is_array( $siblings ) ) {
				$parts['related_categories'] = array_map( 'strval', $siblings );
			}
		}

		return self::build_from_parts( $parts );
	}

	/** Resolve the category term linked to a tmw_category_page draft. */
	private static function resolve_linked_term( $post ) {
		if ( ! function_exists( 'get_post_meta' ) || ! function_exists( 'get_term' ) ) {
			return null;
		}
		foreach ( [ '_tmwseo_category_term_id', '_tmw_category_term_id' ] as $meta_key ) {
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

	private static function nullable_count( $value ): ?int {
		if ( $value === null || $value === '' ) { return null; }
		if ( is_numeric( $value ) && (int) $value >= 0 ) { return (int) $value; }
		return null;
	}

	private static function lc( string $s ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}
}
