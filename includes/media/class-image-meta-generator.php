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
        self::generate_for_attachment( $attachment_id, $parent_post, true );
    }

    /**
     * Generate metadata for all relevant images connected to a post.
     */
    public static function generate_for_post_images( \WP_Post $parent_post ): void {
        $ids = self::get_post_image_attachment_ids( $parent_post );
        if ( empty( $ids ) ) {
            return;
        }

        foreach ( $ids as $index => $attachment_id ) {
            self::generate_for_attachment( $attachment_id, $parent_post, $index === 0 );
        }
    }

    /**
     * @param int      $attachment_id
     * @param \WP_Post $parent_post
     * @param bool     $is_primary
     */
    private static function generate_for_attachment( int $attachment_id, \WP_Post $parent_post, bool $is_primary = false ): void {
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

        $meta   = self::build_meta_text( $attachment, $parent_post, $is_primary );
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
    private static function build_meta_text( \WP_Post $attachment, \WP_Post $parent_post, bool $is_primary ): array {
        $site_name  = get_bloginfo( 'name' );
        $post_title = trim( strip_tags( $parent_post->post_title ) );
        $file_title = preg_replace( '/\.[^.]+$/', '', (string) $attachment->post_name );
        $base       = $post_title ?: ucwords( str_replace( [ '-', '_' ], ' ', $file_title ) );

        $is_video = self::is_video_post( $parent_post );
        $is_model = ( $parent_post->post_type === 'model' );

        if ( $is_model ) {
            $alt_subject = $is_primary && $post_title !== '' ? $post_title : $base;
            $alt         = sprintf( '%s — verified live webcam model profile photo', $alt_subject );
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

    /**
     * @return int[]
     */
    private static function get_post_image_attachment_ids( \WP_Post $post ): array {
        $post_id = (int) $post->ID;
        $ids     = [];

        $thumbnail_id = (int) get_post_thumbnail_id( $post_id );
        if ( $thumbnail_id > 0 ) {
            $ids[] = $thumbnail_id;
        }

        if ( $post->post_type === 'model' ) {
            $meta_keys = [
                'banner_image_id',
                '_banner_image_id',
                'vertical_banner_image_id',
                '_vertical_banner_image_id',
                'banner_focus_image_id',
                '_banner_focus_image_id',
                'front_image_id',
                '_front_image_id',
                'back_image_id',
                '_back_image_id',
                'model_banner_image_id',
                '_model_banner_image_id',
                'model_front_image_id',
                '_model_front_image_id',
                'model_back_image_id',
                '_model_back_image_id',
            ];

            foreach ( $meta_keys as $meta_key ) {
                $ids = array_merge( $ids, self::extract_attachment_ids( get_post_meta( $post_id, $meta_key, true ) ) );
            }

            $all_meta = get_post_meta( $post_id );
            if ( is_array( $all_meta ) ) {
                foreach ( $all_meta as $meta_key => $values ) {
                    $key = strtolower( (string) $meta_key );
                    if ( ! preg_match( '/(image|banner|front|back|photo)/', $key ) ) {
                        continue;
                    }
                    if ( is_array( $values ) ) {
                        foreach ( $values as $value ) {
                            $ids = array_merge( $ids, self::extract_attachment_ids( $value ) );
                        }
                    }
                }
            }
        }

        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( $id ) => $id > 0 ) ) );

        return array_values( array_filter( $ids, 'wp_attachment_is_image' ) );
    }

    /**
     * @param mixed $value
     * @return int[]
     */
    private static function extract_attachment_ids( $value ): array {
        if ( is_array( $value ) ) {
            $out = [];
            foreach ( $value as $item ) {
                $out = array_merge( $out, self::extract_attachment_ids( $item ) );
            }
            return $out;
        }

        if ( is_numeric( $value ) ) {
            return [ (int) $value ];
        }

        if ( ! is_string( $value ) ) {
            return [];
        }

        $raw = trim( $value );
        if ( $raw === '' ) {
            return [];
        }

        if ( is_serialized( $raw ) ) {
            $decoded = maybe_unserialize( $raw );
            if ( $decoded !== $raw ) {
                return self::extract_attachment_ids( $decoded );
            }
        }

        if ( strpos( $raw, '[' ) === 0 || strpos( $raw, '{' ) === 0 ) {
            $json = json_decode( $raw, true );
            if ( is_array( $json ) ) {
                return self::extract_attachment_ids( $json );
            }
        }

        preg_match_all( '/\d+/', $raw, $matches );
        if ( empty( $matches[0] ) ) {
            return [];
        }

        return array_map( 'intval', $matches[0] );
    }
}
