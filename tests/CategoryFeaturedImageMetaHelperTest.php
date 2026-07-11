<?php
declare(strict_types=1);

namespace {
    if ( ! function_exists( 'get_post_mime_type' ) ) {
        function get_post_mime_type( $id ): string { return 'image/jpeg'; }
    }
    if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
        function get_post_thumbnail_id( $id ): int { return (int) ( $GLOBALS['_tmw_test_thumbnail_ids'][ (int) $id ] ?? 0 ); }
    }
    if ( ! function_exists( 'wp_update_post' ) ) {
        function wp_update_post( array $data ): int { $GLOBALS['_tmw_wp_update_post_calls'][] = $data; return (int) ( $data['ID'] ?? 0 ); }
    }
}

namespace TMWSEO\Engine\Tests {

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Media\CategoryFeaturedImageMetaHelper;

require_once dirname( __DIR__ ) . '/includes/media/class-category-featured-image-meta-helper.php';

final class CategoryFeaturedImageMetaHelperTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['_tmw_test_posts']     = [];
        $GLOBALS['_tmw_test_post_meta'] = [];
        $GLOBALS['_tmw_wp_update_post_calls'] = [];
    }

    public function test_fills_empty_category_featured_image_meta_from_rank_math_focus_keyword(): void {
        $post       = $this->post( 10, 'tmw_category_page', 'Fallback Title' );
        $attachment = $this->attachment( 55, 'Big Boob Cam' );

        $GLOBALS['_tmw_test_posts'][10] = $post;
        $GLOBALS['_tmw_test_posts'][55] = $attachment;
        $GLOBALS['_tmw_test_post_meta'][10]['rank_math_focus_keyword'] = 'Big Boob Cam, extra keyword';

        CategoryFeaturedImageMetaHelper::maybe_fill_featured_image_meta( $post, 55 );

        $this->assertSame( 'Big Boob Cam webcam category preview image', $GLOBALS['_tmw_test_post_meta'][55]['_wp_attachment_image_alt'] );
        $this->assertSame( [
            'ID'           => 55,
            'post_excerpt' => 'Big Boob Cam category preview on Top Models Webcam.',
            'post_content' => 'Featured image for the Big Boob Cam category page on Top Models Webcam, used to represent the category in search, social previews, and archive browsing.',
        ], $GLOBALS['_tmw_wp_update_post_calls'][0] );
    }

    public function test_does_not_overwrite_existing_operator_image_meta(): void {
        $post       = $this->post( 10, 'tmw_category_page', 'Big Boob Cam' );
        $attachment = $this->attachment( 55, 'Big Boob Cam', 'Operator caption', 'Operator description' );

        $GLOBALS['_tmw_test_posts'][10] = $post;
        $GLOBALS['_tmw_test_posts'][55] = $attachment;
        $GLOBALS['_tmw_test_post_meta'][10]['rank_math_focus_keyword'] = 'Big Boob Cam';
        $GLOBALS['_tmw_test_post_meta'][55]['_wp_attachment_image_alt'] = 'Operator alt text';

        CategoryFeaturedImageMetaHelper::maybe_fill_featured_image_meta( $post, 55 );

        $this->assertSame( 'Operator alt text', $GLOBALS['_tmw_test_post_meta'][55]['_wp_attachment_image_alt'] );
        $this->assertSame( [], $GLOBALS['_tmw_wp_update_post_calls'] );
    }

    public function test_ignores_non_category_pages(): void {
        $post       = $this->post( 10, 'model', 'Big Boob Cam' );
        $attachment = $this->attachment( 55, 'Big Boob Cam' );

        $GLOBALS['_tmw_test_posts'][10] = $post;
        $GLOBALS['_tmw_test_posts'][55] = $attachment;

        CategoryFeaturedImageMetaHelper::maybe_fill_featured_image_meta( $post, 55 );

        $this->assertArrayNotHasKey( 55, $GLOBALS['_tmw_test_post_meta'] );
        $this->assertSame( [], $GLOBALS['_tmw_wp_update_post_calls'] );
    }

    private function post( int $id, string $type, string $title ): \WP_Post {
        $post = new \WP_Post();
        $post->ID = $id;
        $post->post_type = $type;
        $post->post_title = $title;
        return $post;
    }

    private function attachment( int $id, string $title, string $caption = '', string $description = '' ): \WP_Post {
        $post = $this->post( $id, 'attachment', $title );
        $post->post_excerpt = $caption;
        $post->post_content = $description;
        return $post;
    }
}

}
