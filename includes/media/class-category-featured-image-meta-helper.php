<?php
/**
 * CategoryFeaturedImageMetaHelper — keyword-aware SEO metadata for category
 * page featured images.
 *
 * v5.9.5 rewrite. During category generation/regeneration the featured
 * attachment receives natural, keyword-aware:
 *   - alt text        (describes the image, primary keyword, at most ONE
 *                      supporting keyword);
 *   - attachment title (concise, primary keyword + context — no lists);
 *   - caption         (one readable sentence, one supporting keyword);
 *   - description     (one/two sentences, primary + up to two different
 *                      supporting keywords — no stuffing, no claims about the
 *                      people shown).
 *
 * Safety rules:
 *   - only the attachment currently assigned as THIS category page's featured
 *     image is ever touched;
 *   - shared attachments (used as the featured image of any OTHER post, or
 *     attached to a different parent) are never globally overwritten — for
 *     shared attachments only EMPTY fields are filled, which cannot damage
 *     other pages;
 *   - manually written values are never overwritten: a non-empty field is only
 *     refreshed when it matches a known plugin-generated pattern (the generic
 *     "<kw> category image" style values documented in the audit PDF);
 *   - the helper writes attachment fields only — never content, Rank Math
 *     robots, canonical, slugs, or indexing state.
 *
 * @package TMWSEO\Engine\Media
 */
namespace TMWSEO\Engine\Media;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryFeaturedImageMetaHelper {

	private const POST_TYPE = 'tmw_category_page';

	public static function init(): void {
		add_action( 'set_post_thumbnail', [ __CLASS__, 'on_set_post_thumbnail' ], 11, 2 );
		add_action( 'save_post_' . self::POST_TYPE, [ __CLASS__, 'on_save_category_page' ], 30, 3 );
	}

	public static function on_set_post_thumbnail( int $post_id, int $thumb_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type ) {
			return;
		}

		self::maybe_fill_featured_image_meta( $post, $thumb_id );
	}

	public static function on_save_category_page( int $post_id, \WP_Post $post, bool $update ): void {
		if ( self::POST_TYPE !== $post->post_type ) {
			return;
		}

		$thumb_id = (int) get_post_thumbnail_id( $post_id );
		if ( $thumb_id <= 0 ) {
			return;
		}

		self::maybe_fill_featured_image_meta( $post, $thumb_id );
	}

	/**
	 * Hook entry point (fill-empty behaviour, no keyword pack in scope).
	 */
	public static function maybe_fill_featured_image_meta( \WP_Post $post, int $attachment_id ): void {
		self::apply_meta( $post, $attachment_id, self::keyword_context_for_post( $post ), false );
	}

	/**
	 * Generation entry point — called by ContentEngine after a category page
	 * is generated/regenerated with the fresh keyword pack. Also refreshes
	 * prior plugin-generated generic values.
	 *
	 * @param array<string,mixed> $keyword_pack
	 * @return array<string,mixed> Report of what was written/skipped.
	 */
	public static function apply_for_category_generation( \WP_Post $post, array $keyword_pack = [] ): array {
		if ( self::POST_TYPE !== $post->post_type ) {
			return [ 'skipped' => 'not_category_page' ];
		}

		$attachment_id = (int) get_post_thumbnail_id( (int) $post->ID );
		if ( $attachment_id <= 0 ) {
			return [ 'skipped' => 'no_featured_image' ];
		}

		$context = self::keyword_context_for_post( $post, $keyword_pack );

		return self::apply_meta( $post, $attachment_id, $context, true );
	}

	// ── Core write logic ─────────────────────────────────────────────────────

	/**
	 * @param array{primary:string,supporting:string[]} $context
	 * @return array<string,mixed>
	 */
	private static function apply_meta( \WP_Post $post, int $attachment_id, array $context, bool $refresh_generated ): array {
		$report = [
			'attachment_id' => $attachment_id,
			'shared'        => false,
			'written'       => [],
			'skipped'       => [],
		];

		if ( self::POST_TYPE !== $post->post_type || $attachment_id <= 0 ) {
			$report['skipped'][] = 'invalid_scope';
			return $report;
		}

		$attachment = get_post( $attachment_id );
		if (
			! $attachment instanceof \WP_Post
			|| 'attachment' !== $attachment->post_type
			|| 0 !== strpos( (string) get_post_mime_type( $attachment_id ), 'image/' )
		) {
			$report['skipped'][] = 'not_an_image_attachment';
			return $report;
		}

		$primary = trim( (string) ( $context['primary'] ?? '' ) );
		if ( '' === $primary ) {
			$report['skipped'][] = 'no_primary_keyword';
			return $report;
		}
		$supporting = array_values( array_filter( array_map( 'strval', (array) ( $context['supporting'] ?? [] ) ), 'strlen' ) );

		$is_shared = self::attachment_is_shared( $attachment_id, (int) $post->ID, $attachment );
		$report['shared'] = $is_shared;

		$current_alt         = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		$current_title       = trim( (string) $attachment->post_title );
		$current_caption     = trim( (string) $attachment->post_excerpt );
		$current_description = trim( (string) $attachment->post_content );

		$new_alt         = self::build_alt( $primary, $supporting );
		$new_title       = self::build_title( $primary );
		$new_caption     = self::build_caption( $primary, $supporting );
		$new_description = self::build_description( $primary, $supporting );

		// Overwrite policy per field:
		//  - empty                    → always fill;
		//  - plugin-generated generic → refresh only during generation AND
		//                               only when the attachment is not shared;
		//  - anything else            → manual value, never touched.
		$writable = static function ( string $current, string $primary_kw ) use ( $is_shared, $refresh_generated ): bool {
			if ( '' === $current ) {
				return true;
			}
			if ( $is_shared || ! $refresh_generated ) {
				return false;
			}
			return self::is_plugin_generated_value( $current, $primary_kw );
		};

		$updates = [ 'ID' => $attachment_id ];

		if ( $writable( $current_caption, $primary ) && strcasecmp( $current_caption, $new_caption ) !== 0 ) {
			$updates['post_excerpt'] = $new_caption;
			$report['written'][]     = 'caption';
		} else {
			$report['skipped'][] = 'caption:' . ( '' === $current_caption ? 'unchanged' : 'manual_or_shared' );
		}

		if ( $writable( $current_description, $primary ) && strcasecmp( $current_description, $new_description ) !== 0 ) {
			$updates['post_content'] = $new_description;
			$report['written'][]     = 'description';
		} else {
			$report['skipped'][] = 'description:' . ( '' === $current_description ? 'unchanged' : 'manual_or_shared' );
		}

		// Attachment title: filename-derived titles count as overwritable.
		$title_is_filename = self::title_looks_like_filename( $current_title, $attachment );
		if ( ( $writable( $current_title, $primary ) || ( $title_is_filename && ! $is_shared && $refresh_generated ) )
			&& strcasecmp( $current_title, $new_title ) !== 0 ) {
			$updates['post_title'] = $new_title;
			$report['written'][]   = 'title';
		} else {
			$report['skipped'][] = 'title:manual_or_shared';
		}

		if ( count( $updates ) > 1 ) {
			wp_update_post( $updates );
		}

		if ( $writable( $current_alt, $primary ) && strcasecmp( $current_alt, $new_alt ) !== 0 ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $new_alt );
			$report['written'][] = 'alt';
		} else {
			$report['skipped'][] = 'alt:' . ( '' === $current_alt ? 'unchanged' : 'manual_or_shared' );
		}

		if ( ! empty( $report['written'] ) ) {
			error_log( sprintf(
				'[TMW-CATEGORY-IMAGE-META] post_id=%d attachment_id=%d shared=%d written=%s',
				(int) $post->ID,
				$attachment_id,
				$is_shared ? 1 : 0,
				implode( ',', $report['written'] )
			) );
		}

		return $report;
	}

	// ── Text builders ────────────────────────────────────────────────────────

	/** Alt: image description + primary keyword (+ at most one supporting term). */
	private static function build_alt( string $primary, array $supporting ): string {
		$extra = self::pick_natural_supporting( $supporting, $primary );
		if ( '' !== $extra ) {
			return sprintf( 'Webcam performer featured in the %s category, representing %s listings', $primary, $extra );
		}
		return sprintf( 'Webcam performer featured in the %s category', $primary );
	}

	/** Title: concise, primary keyword + natural context, no keyword list. */
	private static function build_title( string $primary ): string {
		return sprintf( '%s – Featured Category Image', $primary );
	}

	/** Caption: one readable sentence with one supporting keyword where natural. */
	private static function build_caption( string $primary, array $supporting ): string {
		$extra = self::pick_natural_supporting( $supporting, $primary );
		if ( '' !== $extra ) {
			return sprintf( 'Browse %s profiles and %s on Top Models Webcam.', $primary, $extra );
		}
		return sprintf( 'Browse %s model profiles and videos on Top Models Webcam.', $primary );
	}

	/** Description: 1–2 sentences, primary + up to two DIFFERENT supporting terms. */
	private static function build_description( string $primary, array $supporting ): string {
		$first  = self::pick_natural_supporting( $supporting, $primary );
		$second = self::pick_natural_supporting( $supporting, $primary, [ $first ] );

		if ( '' !== $first && '' !== $second ) {
			return sprintf(
				'Featured image for the %s directory on Top Models Webcam. The category also covers %s and %s listings.',
				$primary,
				$first,
				$second
			);
		}
		if ( '' !== $first ) {
			return sprintf(
				'Featured image for the %s directory on Top Models Webcam, including %s listings.',
				$primary,
				$first
			);
		}
		return sprintf(
			'Featured image for the %s directory on Top Models Webcam, covering the related model and video listings.',
			$primary
		);
	}

	/**
	 * Pick one supporting keyword that reads naturally next to the primary
	 * (not a duplicate/substring of it, and not already excluded).
	 *
	 * @param string[] $supporting
	 * @param string[] $exclude
	 */
	private static function pick_natural_supporting( array $supporting, string $primary, array $exclude = [] ): string {
		$primary_lc = strtolower( $primary );
		$exclude_lc = array_map( 'strtolower', array_filter( array_map( 'strval', $exclude ) ) );

		foreach ( $supporting as $term ) {
			$term = trim( $term );
			if ( '' === $term ) {
				continue;
			}
			$term_lc = strtolower( $term );
			if ( $term_lc === $primary_lc || in_array( $term_lc, $exclude_lc, true ) ) {
				continue;
			}
			// Skip supporting terms that are a plain substring of the primary
			// (or vice versa) — they add nothing to an image description.
			if ( strpos( $primary_lc, $term_lc ) !== false || strpos( $term_lc, $primary_lc ) !== false ) {
				continue;
			}
			return $term;
		}
		return '';
	}

	// ── Safety / detection helpers ───────────────────────────────────────────

	/**
	 * A value is "plugin-generated" (and therefore refreshable) when it matches
	 * a known template this plugin has written in the past. Anything else is
	 * treated as manual and is never overwritten.
	 */
	private static function is_plugin_generated_value( string $value, string $primary ): bool {
		$value_lc   = strtolower( trim( $value ) );
		$primary_lc = strtolower( trim( $primary ) );

		$patterns = [
			$primary_lc . ' category image',
			$primary_lc . ' category preview on top models webcam.',
			$primary_lc . ' webcam category preview image',
			$primary_lc . ' – featured category image',
			'featured image for the ' . $primary_lc . ' category page on top models webcam',
			'featured image for the ' . $primary_lc . ' directory on top models webcam',
			'webcam performer featured in the ' . $primary_lc . ' category',
			'browse ' . $primary_lc . ' model profiles and videos on top models webcam.',
			'browse ' . $primary_lc . ' profiles and ',
			$primary_lc . ' category preview and archive browsing',
		];

		foreach ( $patterns as $pattern ) {
			if ( $pattern !== '' && strpos( $value_lc, $pattern ) === 0 ) {
				return true;
			}
		}
		// Also treat bare "<something> category image" values as generated.
		if ( preg_match( '/^[a-z0-9 \-]{2,60} category image$/', $value_lc ) ) {
			return true;
		}
		return false;
	}

	/** Attachment titles derived from the upload filename are overwritable. */
	private static function title_looks_like_filename( string $title, \WP_Post $attachment ): bool {
		if ( '' === $title ) {
			return true;
		}
		$file = (string) get_attached_file( (int) $attachment->ID );
		$base = strtolower( pathinfo( $file, PATHINFO_FILENAME ) );
		$norm = strtolower( str_replace( [ '-', '_' ], [ '-', '-' ], $title ) );
		$norm = str_replace( ' ', '-', $norm );
		return '' !== $base && ( $norm === $base || str_replace( '-', '', $norm ) === str_replace( '-', '', $base ) );
	}

	/**
	 * Shared when the attachment is the featured image of any OTHER post, or
	 * is attached (post_parent) to a different post.
	 */
	private static function attachment_is_shared( int $attachment_id, int $post_id, \WP_Post $attachment ): bool {
		$parent = (int) $attachment->post_parent;
		if ( $parent > 0 && $parent !== $post_id ) {
			return true;
		}

		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) ) {
			$postmeta_table = ( isset( $wpdb->postmeta ) && is_string( $wpdb->postmeta ) && '' !== $wpdb->postmeta )
				? $wpdb->postmeta
				: $wpdb->prefix . 'postmeta';
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$postmeta_table} WHERE meta_key = '_thumbnail_id' AND meta_value = %d AND post_id != %d",
				$attachment_id,
				$post_id
			) );
			if ( $count > 0 ) {
				return true;
			}
		}

		return false;
	}

	// ── Keyword context ──────────────────────────────────────────────────────

	/**
	 * @param array<string,mixed> $keyword_pack
	 * @return array{primary:string,supporting:string[]}
	 */
	private static function keyword_context_for_post( \WP_Post $post, array $keyword_pack = [] ): array {
		$primary = trim( (string) ( $keyword_pack['primary'] ?? '' ) );
		if ( '' === $primary ) {
			$primary = self::resolve_keyword( $post );
		}

		$supporting = [];
		foreach ( [ 'rankmath_additional', 'additional', 'content_terms' ] as $key ) {
			if ( ! empty( $keyword_pack[ $key ] ) && is_array( $keyword_pack[ $key ] ) ) {
				foreach ( $keyword_pack[ $key ] as $term ) {
					$term = trim( (string) $term );
					if ( '' !== $term ) {
						$supporting[] = $term;
					}
				}
			}
		}

		// No pack in scope (hook path) — read the stored pack / Rank Math CSV.
		if ( empty( $supporting ) ) {
			$stored_raw = (string) get_post_meta( (int) $post->ID, '_tmwseo_keyword_pack', true );
			$stored     = '' !== $stored_raw ? json_decode( $stored_raw, true ) : null;
			if ( is_array( $stored ) ) {
				foreach ( [ 'rankmath_additional', 'additional', 'content_terms' ] as $key ) {
					if ( ! empty( $stored[ $key ] ) && is_array( $stored[ $key ] ) ) {
						foreach ( $stored[ $key ] as $term ) {
							$term = trim( (string) $term );
							if ( '' !== $term ) {
								$supporting[] = $term;
							}
						}
					}
				}
			}
		}
		if ( empty( $supporting ) ) {
			$csv   = (string) get_post_meta( (int) $post->ID, 'rank_math_focus_keyword', true );
			$parts = array_values( array_filter( array_map( 'trim', explode( ',', $csv ) ), 'strlen' ) );
			array_shift( $parts ); // primary
			$supporting = $parts;
		}

		// Dedupe, keep order.
		$seen = [];
		$supporting = array_values( array_filter( $supporting, static function ( string $term ) use ( &$seen ): bool {
			$key = strtolower( $term );
			if ( isset( $seen[ $key ] ) ) {
				return false;
			}
			$seen[ $key ] = true;
			return true;
		} ) );

		return [
			'primary'    => $primary,
			'supporting' => $supporting,
		];
	}

	private static function resolve_keyword( \WP_Post $post ): string {
		$raw = (string) get_post_meta( (int) $post->ID, 'rank_math_focus_keyword', true );
		if ( '' === trim( $raw ) ) {
			$raw = (string) get_post_meta( (int) $post->ID, '_rank_math_focus_keyword', true );
		}

		$parts   = preg_split( '/\s*,\s*/', $raw );
		$keyword = is_array( $parts ) ? (string) ( $parts[0] ?? '' ) : $raw;
		$keyword = trim( wp_strip_all_tags( $keyword ) );

		if ( '' === $keyword ) {
			$keyword = trim( wp_strip_all_tags( (string) $post->post_title ) );
		}

		return preg_replace( '/\s+/', ' ', $keyword ) ?: '';
	}
}
