<?php
/**
 * Image_Meta_Generator — auto-fills ALT, title, caption, and description
 * for featured images on model and video posts.
 *
 * Rules:
 * - Only runs once per attachment (flagged via _tmwseo_image_meta_generated).
 * - Never overwrites fields that already have a manually set value.
 * - Generates SFW, brand-safe text only.
 *
 * @package TMWSEO\Engine\Media
 */
namespace TMWSEO\Engine\Media;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Image_Meta_Generator {

    /**
     * Main entry point. Called when a featured image is set or when a
     * post is saved and already has a thumbnail.
     *
     * @param int       $attachment_id
     * @param \WP_Post  $parent_post
     */
    public static function generate_for_featured_image( int $attachment_id, \WP_Post $parent_post ): void {
        // Only real image attachments
        $attachment = get_post( $attachment_id );
        if (
            ! $attachment
            || 'attachment' !== $attachment->post_type
            || 0 !== strpos( (string) get_post_mime_type( $attachment_id ), 'image/' )
        ) {
            return;
        }

        $already_generated = (bool) get_post_meta( $attachment_id, '_tmwseo_image_meta_generated', true );

        // Current values
        $current_alt     = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
        $current_title   = $attachment->post_title;
        $current_caption = $attachment->post_excerpt;
        $current_content = $attachment->post_content;

        // If already ran and user has kept values, respect them
        if ( $already_generated && ( $current_alt || $current_title || $current_caption || $current_content ) ) {
            return;
        }

        $meta   = self::build_meta_text( $attachment, $parent_post );
        $update = [ 'ID' => $attachment_id ];

        if ( empty( $current_title ) && ! empty( $meta['title'] ) ) {
            $update['post_title'] = $meta['title'];
        }
        if ( empty( $current_caption ) && ! empty( $meta['caption'] ) ) {
            $update['post_excerpt'] = $meta['caption'];
        }
        if ( empty( $current_content ) && ! empty( $meta['description'] ) ) {
            $update['post_content'] = $meta['description'];
        }

        if ( count( $update ) > 1 ) {
            // Prevent save_post hook re-entry
            remove_action( 'save_post', [ ImageMetaHooks::class, 'on_save_post_with_thumbnail' ], 20 );
            wp_update_post( $update );
            add_action( 'save_post', [ ImageMetaHooks::class, 'on_save_post_with_thumbnail' ], 20, 3 );
        }

        if ( empty( $current_alt ) && ! empty( $meta['alt'] ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $meta['alt'] );
        }

        update_post_meta( $attachment_id, '_tmwseo_image_meta_generated', 1 );
    }

    // ── Text generation ────────────────────────────────────────────────────

    /**
     * Builds SFW, SEO-friendly alt/title/caption/description for an image.
     *
     * @return array{alt:string,title:string,caption:string,description:string}
     */
    private static function build_meta_text( \WP_Post $attachment, \WP_Post $parent_post ): array {
        $site_name  = get_bloginfo( 'name' );
        $post_title = trim( strip_tags( $parent_post->post_title ) );
        $file_title = preg_replace( '/\.[^.]+$/', '', (string) $attachment->post_name );
        $base       = $post_title ?: ucwords( str_replace( [ '-', '_' ], ' ', $file_title ) );

        $is_video = self::is_video_post( $parent_post );
        $is_model = ( $parent_post->post_type === 'model' );

        if ( $is_model ) {
            $alt         = sprintf( '%s — live webcam model profile photo', $base );
            $title       = sprintf( '%s | Live Cam Model | %s', $base, $site_name );
            $caption     = sprintf( 'Profile photo of %s, live webcam model on %s', $base, $site_name );
            $description = sprintf(
                'Featured profile image for %s, a live cam model available on %s. Browse model profile, shows, and streaming schedule.',
                $base,
                $site_name
            );
        } elseif ( $is_video ) {
            $alt         = sprintf( '%s — webcam show screenshot', $base );
            $title       = sprintf( '%s | Webcam Show | %s', $base, $site_name );
            $caption     = sprintf( 'Screenshot from %s\'s webcam show on %s', $base, $site_name );
            $description = sprintf(
                'Preview image for "%s", a live cam show on %s.',
                $base,
                $site_name
            );
        } else {
            // Generic fallback
            $alt         = sprintf( '%s — %s', $base, $site_name );
            $title       = sprintf( '%s | %s', $base, $site_name );
            $caption     = $base;
            $description = sprintf( 'Image related to %s on %s.', $base, $site_name );
        }

        return [
            'alt'         => sanitize_text_field( $alt ),
            'title'       => sanitize_text_field( $title ),
            'caption'     => sanitize_text_field( $caption ),
            'description' => sanitize_text_field( $description ),
        ];
    }

    private static function is_video_post( \WP_Post $post ): bool {
        $video_types = [ 'video', 'tmw_video', 'livejasmin_video' ];
        return in_array( $post->post_type, $video_types, true );
    }
}
