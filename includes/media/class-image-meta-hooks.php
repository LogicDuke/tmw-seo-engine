<?php
/**
 * ImageMetaHooks — registers WordPress hooks that trigger image meta generation.
 *
 * @package TMWSEO\Engine\Media
 */
namespace TMWSEO\Engine\Media;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ImageMetaHooks {

    /** Post types that get auto-image-meta. Add your video post types here. */
    private static function post_types(): array {
        return [ 'model', 'video', 'tmw_video', 'livejasmin_video' ];
    }

    public static function init(): void {
        // Fires whenever a featured image is set or changed.
        add_action( 'set_post_thumbnail', [ __CLASS__, 'on_set_post_thumbnail' ], 10, 2 );

        // Safety net: post saved and already has a thumbnail.
        foreach ( self::post_types() as $pt ) {
            add_action( "save_post_{$pt}", [ __CLASS__, 'on_save_post_with_thumbnail' ], 20, 3 );
        }
    }

    public static function on_set_post_thumbnail( int $post_id, int $thumb_id ): void {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return;
        }
        if ( ! in_array( $post->post_type, self::post_types(), true ) ) {
            return;
        }
        Image_Meta_Generator::generate_for_featured_image( $thumb_id, $post );
    }

    public static function on_save_post_with_thumbnail( int $post_id, \WP_Post $post, bool $update ): void {
        if ( ! in_array( $post->post_type, self::post_types(), true ) ) {
            return;
        }
        Image_Meta_Generator::generate_for_post_images( $post );
    }
}
