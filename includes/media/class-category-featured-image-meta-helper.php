<?php
/**
 * CategoryFeaturedImageMetaHelper — fills missing SEO metadata for category page featured images.
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

    public static function maybe_fill_featured_image_meta( \WP_Post $post, int $attachment_id ): void {
        if ( self::POST_TYPE !== $post->post_type || $attachment_id <= 0 ) {
            return;
        }

        $attachment = get_post( $attachment_id );
        if (
            ! $attachment instanceof \WP_Post
            || 'attachment' !== $attachment->post_type
            || 0 !== strpos( (string) get_post_mime_type( $attachment_id ), 'image/' )
        ) {
            return;
        }

        $keyword = self::resolve_keyword( $post );
        if ( '' === $keyword ) {
            return;
        }

        $current_alt         = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
        $current_caption     = trim( (string) $attachment->post_excerpt );
        $current_description = trim( (string) $attachment->post_content );
        $updates             = [ 'ID' => $attachment_id ];

        if ( '' === $current_caption ) {
            $updates['post_excerpt'] = sprintf( '%s category preview on Top Models Webcam.', $keyword );
        }

        if ( '' === $current_description ) {
            $updates['post_content'] = sprintf( 'Featured image for the %s category page on Top Models Webcam, used to represent the category in search, social previews, and archive browsing.', $keyword );
        }

        if ( count( $updates ) > 1 ) {
            wp_update_post( $updates );
        }

        if ( '' === $current_alt ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', sprintf( '%s webcam category preview image', $keyword ) );
        }

        if ( '' === $current_alt || count( $updates ) > 1 ) {
            error_log( sprintf( '[TMW-CATEGORY-IMAGE-META] post_id=%d attachment_id=%d filled_empty_fields_only=1', (int) $post->ID, $attachment_id ) );
        }
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
