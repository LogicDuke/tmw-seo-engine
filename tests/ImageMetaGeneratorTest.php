<?php
/**
 * TMW SEO Engine — Image Meta Generator Tests (v2.1 real-key mapping)
 *
 * Coverage:
 *   T1   Primary alt contains "verified live webcam model profile photo"
 *   T2   Banner alt contains "banner image"; NOT "profile photo"
 *   T3   Front alt contains "profile preview image"
 *   T4   Back alt contains "profile preview image"
 *   T5   Front and back alts differ
 *   T6   Secondary role — "live webcam model image"
 *   T7   Invalid role falls back to primary
 *   T8   IMAGE_META_VERSION === 2
 *   T9   v1 alt detected
 *   T10  v1 title detected
 *   T11  v1 caption detected
 *   T12  v1 description detected
 *   T13  Custom alt NOT flagged as v1
 *   T14  Custom description NOT flagged as v1
 *   T15  v1 primary alt differs from v2 front alt
 *   T16  platform_label maps all known slugs
 *   T17  platform_label ucwords unknown / empty
 *   T18  sanitise_role rejects invalid
 *   T19  sanitise_role accepts all 5 valid values
 *   T20  resolve_secondary_keywords >= 2 entries
 *   T21  kws[0] != kws[1] on fallback path
 *   T22  No comma in front alt
 *   T23  No comma in back alt
 *   T24  Banner alt differs from all other roles
 *   T25  Video post — screenshot template for all roles
 *   T26  Non-model non-video — site-name fallback
 *   T27  Model name in all role alts
 *   T28  Front title != primary title
 *   T29  Back title != front title
 *   T30  Front caption != primary caption
 *   T31  Front and back descriptions differ
 *   T32  Front description NOT v1 pattern
 *   T33  Back description NOT v1 pattern
 *
 *   Wildcard / real-key mapping (v2.1)
 *   T34  role_from_key_segments: "flipbox_front_image"  → front
 *   T35  role_from_key_segments: "flipbox_back_image"   → back
 *   T36  role_from_key_segments: "model_front_img"      → front
 *   T37  role_from_key_segments: "model_back_img"       → back
 *   T38  role_from_key_segments: "tmw_banner_image"     → banner
 *   T39  role_from_key_segments: "thumbnail_image"      → secondary (no front/back/banner)
 *   T40  role_from_key_segments: "front_image"          → front (plain key, no _id)
 *   T41  role_from_key_segments: "back_image"           → back  (plain key, no _id)
 *
 *   extract_attachment_ids value formats (v2.1)
 *   T42  integer → [id]
 *   T43  numeric string → [id]
 *   T44  array ['ID' => 123] → [123]
 *   T45  array ['id' => 123] → [123]
 *   T46  URL string returns [] (no false digit extraction)
 *   T47  array with URL value skips URL, returns []
 *
 * @package TMWSEO\Engine\Tests
 */
declare(strict_types=1);

// ── Global stubs needed by Image_Meta_Generator ───────────────────────────────
namespace {
    if ( ! function_exists( 'get_bloginfo' ) ) {
        function get_bloginfo( string $key = '' ): string {
            return $key === 'name' ? 'Top Models Webcam' : '';
        }
    }
    if ( ! function_exists( 'get_post_mime_type' ) ) {
        function get_post_mime_type( $id ): string { return 'image/jpeg'; }
    }
    if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
        function get_post_thumbnail_id( $id ): int { return 0; }
    }
    if ( ! function_exists( 'wp_attachment_is_image' ) ) {
        function wp_attachment_is_image( $id ): bool { return (int) $id > 0; }
    }
    if ( ! function_exists( 'wp_update_post' ) ) {
        function wp_update_post( array $data ): int {
            $GLOBALS['_tmw_wp_update_post_calls'][] = $data;
            return (int) ( $data['ID'] ?? 0 );
        }
    }
    if ( ! function_exists( 'remove_action' ) ) {
        function remove_action( string $hook, $cb, int $p = 10 ): bool { return true; }
    }
    if ( ! function_exists( 'get_the_tags' ) ) {
        function get_the_tags( int $id ) {
            return $GLOBALS['_tmw_test_tags'][ $id ] ?? false;
        }
    }
    if ( ! function_exists( 'is_serialized' ) ) {
        function is_serialized( $data ): bool { return false; }
    }
    if ( ! function_exists( 'maybe_unserialize' ) ) {
        function maybe_unserialize( $data ) { return $data; }
    }
    if ( ! function_exists( 'get_attached_file' ) ) {
        function get_attached_file( int $id ): string|false { return false; }
    }

    require_once dirname( __DIR__ ) . '/includes/media/class-image-meta-hooks.php';
    require_once dirname( __DIR__ ) . '/includes/media/class-image-meta-generator.php';
}

// ── Test suite ────────────────────────────────────────────────────────────────
namespace TMWSEO\Engine\Tests {

    use PHPUnit\Framework\TestCase;
    use TMWSEO\Engine\Media\Image_Meta_Generator;
    use ReflectionMethod;

    // ── WP_Post fixture ───────────────────────────────────────────────────────
    // class_exists('WP_Post') in a namespace block resolves against the GLOBAL
    // \WP_Post (already defined by bootstrap), so we use __NAMESPACE__ to check
    // for the namespaced subclass specifically.
    if ( ! class_exists( __NAMESPACE__ . '\WP_Post' ) ) {
        class WP_Post extends \WP_Post {
            public string $post_title   = '';
            public string $post_type    = 'post';
            public string $post_content = '';
            public string $post_excerpt = '';
            public string $post_name    = '';
            public string $post_status  = 'publish';

            public function __construct( array $props = [] ) {
                foreach ( $props as $k => $v ) {
                    $this->$k = $v;
                }
            }
        }
    }

    function call_private( string $method, array $args = [] ) {
        $ref = new ReflectionMethod( Image_Meta_Generator::class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( null, $args );
    }

    class ImageMetaGeneratorTest extends TestCase {

        protected function setUp(): void {
            $GLOBALS['_tmw_test_tags']            = [];
            $GLOBALS['_tmw_wp_update_post_calls'] = [];
        }

        // ── Fixtures ──────────────────────────────────────────────────────────

        private function model_post( array $props = [] ): WP_Post {
            return new WP_Post( array_merge( [ 'ID' => 1, 'post_title' => 'Anisyia', 'post_type' => 'model' ], $props ) );
        }
        private function video_post( array $props = [] ): WP_Post {
            return new WP_Post( array_merge( [ 'ID' => 2, 'post_title' => 'Hot Show', 'post_type' => 'tmw_video' ], $props ) );
        }
        private function generic_post( array $props = [] ): WP_Post {
            return new WP_Post( array_merge( [ 'ID' => 3, 'post_title' => 'Some Page', 'post_type' => 'page' ], $props ) );
        }
        private function fake_attachment( array $props = [] ): WP_Post {
            return new WP_Post( array_merge( [
                'ID' => 99, 'post_type' => 'attachment',
                'post_title' => '', 'post_excerpt' => '', 'post_content' => '',
                'post_name' => 'anisyia-photo',
            ], $props ) );
        }
        private function build( WP_Post $att, WP_Post $parent, string $role ): array {
            return call_private( 'build_meta_text', [ $att, $parent, $role ] );
        }

        // ── T1–T7  Role alt templates ─────────────────────────────────────────

        public function test_primary_alt_contains_profile_photo(): void {
            $meta = $this->build( $this->fake_attachment(), $this->model_post(), 'primary' );
            $this->assertStringContainsString( 'verified live webcam model profile photo', $meta['alt'] );
        }
        public function test_banner_alt_contains_banner_image(): void {
            $meta = $this->build( $this->fake_attachment(), $this->model_post(), 'banner' );
            $this->assertStringContainsString( 'banner image', $meta['alt'] );
            $this->assertStringNotContainsString( 'profile photo', $meta['alt'] );
        }
        public function test_front_alt_contains_profile_preview_image(): void {
            $meta = $this->build( $this->fake_attachment(), $this->model_post(), 'front' );
            $this->assertStringContainsString( 'profile preview image', $meta['alt'] );
            $this->assertStringNotContainsString( 'profile photo', $meta['alt'] );
            $this->assertStringNotContainsString( 'banner', $meta['alt'] );
        }
        public function test_back_alt_contains_profile_preview_image(): void {
            $meta = $this->build( $this->fake_attachment(), $this->model_post(), 'back' );
            $this->assertStringContainsString( 'profile preview image', $meta['alt'] );
            $this->assertStringNotContainsString( 'profile photo', $meta['alt'] );
        }
        public function test_front_and_back_alts_differ(): void {
            $att = $this->fake_attachment(); $p = $this->model_post();
            $this->assertNotSame(
                $this->build( $att, $p, 'front' )['alt'],
                $this->build( $att, $p, 'back' )['alt'],
                'Front and back alts must not be identical'
            );
        }
        public function test_secondary_alt_is_generic_webcam_image(): void {
            $meta = $this->build( $this->fake_attachment(), $this->model_post(), 'secondary' );
            $this->assertStringContainsString( 'live webcam model image', $meta['alt'] );
            $this->assertStringNotContainsString( 'profile photo', $meta['alt'] );
            $this->assertStringNotContainsString( 'profile preview image', $meta['alt'] );
        }
        public function test_invalid_role_falls_back_to_primary(): void {
            $att = $this->fake_attachment(); $p = $this->model_post();
            $this->assertSame(
                $this->build( $att, $p, 'primary' )['alt'],
                $this->build( $att, $p, 'GARBAGE' )['alt']
            );
        }

        // ── T8  VERSION ───────────────────────────────────────────────────────

        public function test_image_meta_version_is_2(): void {
            $this->assertSame( 2, Image_Meta_Generator::IMAGE_META_VERSION );
        }

        // ── T9–T14  v1 pattern detection ──────────────────────────────────────

        public function test_v1_alt_is_detected(): void {
            $this->assertTrue( (bool) call_private( 'matches_v1_alt',
                [ 'Anisyia — verified live webcam model profile photo' ] ) );
        }
        public function test_v1_model_title_is_detected(): void {
            $this->assertTrue( (bool) call_private( 'matches_v1_model_title',
                [ 'Anisyia | Live Cam Model | Top Models Webcam' ] ) );
        }
        public function test_v1_caption_is_detected(): void {
            $this->assertTrue( (bool) call_private( 'matches_v1_caption',
                [ 'Profile photo of Anisyia, live webcam model on Top Models Webcam' ] ) );
        }
        public function test_v1_description_is_detected(): void {
            $this->assertTrue( (bool) call_private( 'matches_v1_description',
                [ 'Featured profile image for Anisyia, a live cam model available on Top Models Webcam. Browse model profile, shows, and streaming schedule.' ] ),
                'v1 description must be detected so Flipbox images can be upgraded'
            );
        }
        public function test_custom_alt_not_flagged_as_v1(): void {
            $this->assertFalse( (bool) call_private( 'matches_v1_alt', [ 'Anisyia in studio 2024' ] ) );
        }
        public function test_custom_description_not_flagged_as_v1(): void {
            $this->assertFalse( (bool) call_private( 'matches_v1_description',
                [ 'Anisyia is a top-rated cam model with 10 years of experience.' ] ) );
        }
        public function test_empty_description_not_flagged_as_v1(): void {
            $this->assertFalse( (bool) call_private( 'matches_v1_description', [ '' ] ) );
        }

        // ── T15  Upgrade path ─────────────────────────────────────────────────

        public function test_v1_primary_alt_differs_from_v2_front_alt(): void {
            $att = $this->fake_attachment(); $p = $this->model_post();
            $this->assertNotSame(
                $this->build( $att, $p, 'primary' )['alt'],
                $this->build( $att, $p, 'front' )['alt']
            );
        }

        // ── T16–T17  platform_label ───────────────────────────────────────────

        public function test_platform_label_maps_known_slugs(): void {
            $cases = [
                'livejasmin' => 'LiveJasmin', 'chaturbate' => 'Chaturbate',
                'stripchat'  => 'Stripchat',  'myfreecams' => 'MyFreeCams',
                'cam4'       => 'CAM4',        'camsoda'    => 'CamSoda',
                'bongacams'  => 'BongaCams',   'streamate'  => 'Streamate',
                'flirt4free' => 'Flirt4Free',  'imlive'     => 'ImLive',
            ];
            foreach ( $cases as $slug => $expected ) {
                $this->assertSame( $expected, call_private( 'platform_label', [ $slug ] ),
                    "platform_label('{$slug}') should return '{$expected}'" );
            }
        }
        public function test_platform_label_ucwords_unknown(): void {
            $this->assertSame( 'My Custom Platform', call_private( 'platform_label', [ 'my_custom_platform' ] ) );
            $this->assertSame( '', call_private( 'platform_label', [ '' ] ) );
        }

        // ── T18–T19  sanitise_role ────────────────────────────────────────────

        public function test_sanitise_role_rejects_invalid(): void {
            $this->assertSame( 'primary', call_private( 'sanitise_role', [ 'INVALID' ] ) );
            $this->assertSame( 'primary', call_private( 'sanitise_role', [ '' ] ) );
            $this->assertSame( 'primary', call_private( 'sanitise_role', [ 'PRIMARY' ] ) );
        }
        public function test_sanitise_role_accepts_valid_values(): void {
            foreach ( [ 'primary', 'banner', 'front', 'back', 'secondary' ] as $role ) {
                $this->assertSame( $role, call_private( 'sanitise_role', [ $role ] ) );
            }
        }

        // ── T20–T21  resolve_secondary_keywords ───────────────────────────────

        public function test_resolve_secondary_keywords_returns_at_least_two(): void {
            $kws = call_private( 'resolve_secondary_keywords', [ 999 ] );
            $this->assertGreaterThanOrEqual( 2, count( $kws ) );
        }
        public function test_resolve_secondary_keywords_fallback_first_two_differ(): void {
            $kws = call_private( 'resolve_secondary_keywords', [ 888 ] );
            $this->assertNotSame( $kws[0] ?? '', $kws[1] ?? '' );
        }

        // ── T22–T23  No keyword stuffing ──────────────────────────────────────

        public function test_front_alt_has_no_comma(): void {
            $this->assertStringNotContainsString( ',', $this->build( $this->fake_attachment(), $this->model_post(), 'front' )['alt'] );
        }
        public function test_back_alt_has_no_comma(): void {
            $this->assertStringNotContainsString( ',', $this->build( $this->fake_attachment(), $this->model_post(), 'back' )['alt'] );
        }

        // ── T24  Banner differs from all ──────────────────────────────────────

        public function test_banner_alt_differs_from_all_other_roles(): void {
            $att = $this->fake_attachment(); $p = $this->model_post();
            $banner = $this->build( $att, $p, 'banner' )['alt'];
            foreach ( [ 'primary', 'front', 'back', 'secondary' ] as $role ) {
                $this->assertNotSame( $banner, $this->build( $att, $p, $role )['alt'],
                    "Banner must differ from {$role}" );
            }
        }

        // ── T25–T27  Post-type branch tests ───────────────────────────────────

        public function test_video_post_uses_screenshot_template_for_all_roles(): void {
            $att = $this->fake_attachment(); $v = $this->video_post();
            foreach ( [ 'primary', 'front', 'back', 'banner' ] as $role ) {
                $this->assertStringContainsString( 'webcam show screenshot',
                    $this->build( $att, $v, $role )['alt'], "Video role={$role}" );
            }
        }
        public function test_generic_post_uses_site_name_fallback(): void {
            $meta = $this->build( $this->fake_attachment(), $this->generic_post(), 'primary' );
            $this->assertStringContainsString( 'Top Models Webcam', $meta['alt'] );
            $this->assertStringNotContainsString( 'profile photo', $meta['alt'] );
        }
        public function test_model_name_in_all_role_alts(): void {
            $att = $this->fake_attachment(); $p = $this->model_post( [ 'post_title' => 'Anisyia' ] );
            foreach ( [ 'primary', 'banner', 'front', 'back', 'secondary' ] as $role ) {
                $this->assertStringContainsString( 'Anisyia', $this->build( $att, $p, $role )['alt'],
                    "Name must appear in {$role} alt" );
            }
        }

        // ── T28–T33  Title / caption / description role differentiation ────────

        public function test_front_title_differs_from_primary_title(): void {
            $att = $this->fake_attachment(); $p = $this->model_post();
            $this->assertNotSame( $this->build($att,$p,'primary')['title'], $this->build($att,$p,'front')['title'] );
        }
        public function test_back_title_differs_from_front_title(): void {
            $att = $this->fake_attachment(); $p = $this->model_post();
            $this->assertNotSame( $this->build($att,$p,'front')['title'], $this->build($att,$p,'back')['title'] );
        }
        public function test_front_caption_differs_from_primary_caption(): void {
            $att = $this->fake_attachment(); $p = $this->model_post();
            $this->assertNotSame( $this->build($att,$p,'primary')['caption'], $this->build($att,$p,'front')['caption'] );
        }
        public function test_front_and_back_descriptions_differ(): void {
            $att = $this->fake_attachment(); $p = $this->model_post();
            $this->assertNotSame( $this->build($att,$p,'front')['description'], $this->build($att,$p,'back')['description'] );
        }
        public function test_front_description_is_not_v1_pattern(): void {
            $meta = $this->build( $this->fake_attachment(), $this->model_post(), 'front' );
            $this->assertFalse( (bool) call_private( 'matches_v1_description', [ $meta['description'] ] ),
                'v2 front description must NOT match v1 primary pattern' );
        }
        public function test_back_description_is_not_v1_pattern(): void {
            $meta = $this->build( $this->fake_attachment(), $this->model_post(), 'back' );
            $this->assertFalse( (bool) call_private( 'matches_v1_description', [ $meta['description'] ] ),
                'v2 back description must NOT match v1 primary pattern' );
        }

        // ── T34–T41  role_from_key_segments (wildcard role derivation) ─────────

        public function test_flipbox_front_image_maps_to_front(): void {
            $this->assertSame( 'front', call_private( 'role_from_key_segments', [ 'flipbox_front_image' ] ),
                'flipbox_front_image must map to role front' );
        }
        public function test_flipbox_back_image_maps_to_back(): void {
            $this->assertSame( 'back', call_private( 'role_from_key_segments', [ 'flipbox_back_image' ] ),
                'flipbox_back_image must map to role back' );
        }
        public function test_model_front_img_maps_to_front(): void {
            $this->assertSame( 'front', call_private( 'role_from_key_segments', [ 'model_front_img' ] ) );
        }
        public function test_model_back_img_maps_to_back(): void {
            $this->assertSame( 'back', call_private( 'role_from_key_segments', [ 'model_back_img' ] ) );
        }
        public function test_tmw_banner_image_maps_to_banner(): void {
            $this->assertSame( 'banner', call_private( 'role_from_key_segments', [ 'tmw_banner_image' ] ) );
        }
        public function test_thumbnail_image_maps_to_secondary(): void {
            // 'thumbnail_image' has no front/back/banner segment → secondary
            $this->assertSame( 'secondary', call_private( 'role_from_key_segments', [ 'thumbnail_image' ] ) );
        }
        public function test_plain_front_image_maps_to_front(): void {
            // 'front_image' without _id suffix
            $this->assertSame( 'front', call_private( 'role_from_key_segments', [ 'front_image' ] ) );
        }
        public function test_plain_back_image_maps_to_back(): void {
            // 'back_image' without _id suffix
            $this->assertSame( 'back', call_private( 'role_from_key_segments', [ 'back_image' ] ) );
        }

        // ── T42–T47  extract_attachment_ids value formats ──────────────────────

        public function test_extract_integer(): void {
            $this->assertSame( [ 123 ], call_private( 'extract_attachment_ids', [ 123 ] ) );
        }
        public function test_extract_numeric_string(): void {
            $this->assertSame( [ 456 ], call_private( 'extract_attachment_ids', [ '456' ] ) );
        }
        public function test_extract_array_with_uppercase_id_key(): void {
            $this->assertSame( [ 789 ], call_private( 'extract_attachment_ids', [ [ 'ID' => 789 ] ] ),
                'Array with key ID must return the attachment id directly' );
        }
        public function test_extract_array_with_lowercase_id_key(): void {
            $this->assertSame( [ 321 ], call_private( 'extract_attachment_ids', [ [ 'id' => 321 ] ] ),
                'Array with key id (lowercase) must return the attachment id directly' );
        }
        public function test_extract_url_string_returns_empty(): void {
            // A URL must not have its digits extracted as fake IDs.
            $url = 'https://example.com/wp-content/uploads/2024/01/photo.jpg';
            $this->assertSame( [], call_private( 'extract_attachment_ids', [ $url ] ),
                'URL string must return empty array, not false digit matches' );
        }
        public function test_extract_array_containing_url_skips_url(): void {
            // Array with mixed int ID and a URL string — URL should be skipped.
            $mixed = [ 99, 'https://example.com/wp-content/uploads/photo.jpg' ];
            $result = call_private( 'extract_attachment_ids', [ $mixed ] );
            $this->assertContains( 99, $result );
            // The URL should not contribute any extra IDs.
            $this->assertCount( 1, $result, 'URL in array must not produce extra IDs' );
        }
        // ── T48–T55  Secondary pattern detection ──────────────────────────────

        public function test_secondary_alt_is_detected(): void {
            $v2_secondary = 'Anisyia — live webcam model image';
            $this->assertTrue(
                (bool) call_private( 'matches_secondary_alt', [ $v2_secondary ] ),
                'Old secondary alt must be detected so --force can upgrade it'
            );
        }

        public function test_secondary_caption_is_detected(): void {
            $v2_caption = "Image from Anisyia's profile on Top Models Webcam";
            $this->assertTrue(
                (bool) call_private( 'matches_secondary_caption', [ $v2_caption ] )
            );
        }

        public function test_secondary_description_is_detected(): void {
            $v2_desc = 'Profile image for Anisyia, a live cam model on Top Models Webcam.';
            $this->assertTrue(
                (bool) call_private( 'matches_secondary_description', [ $v2_desc ] )
            );
        }

        public function test_wp_auto_title_front_is_detected(): void {
            // "Anisyia front" — WordPress title derived from filename
            $this->assertTrue( (bool) call_private( 'matches_generated_title', [ 'Anisyia front' ] ) );
        }

        public function test_wp_auto_title_back_is_detected(): void {
            $this->assertTrue( (bool) call_private( 'matches_generated_title', [ 'Anisyia Back' ] ) );
        }

        public function test_v2_front_title_is_detected(): void {
            $this->assertTrue( (bool) call_private( 'matches_generated_title',
                [ 'Anisyia | Profile Preview | Top Models Webcam' ] ) );
        }

        public function test_v2_back_title_is_detected(): void {
            $this->assertTrue( (bool) call_private( 'matches_generated_title',
                [ 'Anisyia | Webcam Model Info | Top Models Webcam' ] ) );
        }

        public function test_custom_title_not_detected_as_generated(): void {
            $this->assertFalse( (bool) call_private( 'matches_generated_title',
                [ 'My custom portrait title 2024' ] ),
                'Fully custom title must NOT be flagged as auto-generated'
            );
        }

        // ── T56–T62  Per-field overwrite guards ───────────────────────────────

        public function test_is_overwritable_alt_true_for_empty(): void {
            $this->assertTrue( (bool) call_private( 'is_overwritable_alt', [ '' ] ) );
        }

        public function test_is_overwritable_alt_true_for_v1_pattern(): void {
            $this->assertTrue( (bool) call_private( 'is_overwritable_alt',
                [ 'Anisyia — verified live webcam model profile photo' ] ) );
        }

        public function test_is_overwritable_alt_true_for_secondary_pattern(): void {
            $this->assertTrue( (bool) call_private( 'is_overwritable_alt',
                [ 'Anisyia — live webcam model image' ] ),
                'Old secondary alt must be overwritable by --force'
            );
        }

        public function test_is_overwritable_alt_false_for_custom(): void {
            $this->assertFalse( (bool) call_private( 'is_overwritable_alt',
                [ 'Anisyia in a red dress — custom portrait' ] ),
                'Custom alt must NOT be overwritable'
            );
        }

        public function test_is_overwritable_caption_true_for_secondary_pattern(): void {
            $this->assertTrue( (bool) call_private( 'is_overwritable_caption',
                [ "Image from Anisyia's profile on Top Models Webcam" ] ),
                'Old secondary caption must be overwritable'
            );
        }

        public function test_is_overwritable_description_true_for_secondary_pattern(): void {
            $this->assertTrue( (bool) call_private( 'is_overwritable_description',
                [ 'Profile image for Anisyia, a live cam model on Top Models Webcam.' ] ),
                'Old secondary description must be overwritable'
            );
        }

        public function test_is_overwritable_title_true_for_wp_auto_front(): void {
            $this->assertTrue( (bool) call_private( 'is_overwritable_title', [ 'Anisyia front' ] ),
                'WP auto-title "Anisyia front" must be overwritable'
            );
        }

        // ── Combined: secondary-pattern fields produce correct new front/back text ──

        public function test_v2_front_alt_differs_from_secondary_alt(): void {
            // After upgrade, the front alt must not be the old secondary alt.
            $att    = $this->fake_attachment();
            $parent = $this->model_post();
            $front_alt = $this->build( $att, $parent, 'front' )['alt'];
            $this->assertNotSame( 'Anisyia — live webcam model image', $front_alt,
                'Upgraded front alt must not be the old secondary alt'
            );
        }

        public function test_v2_back_alt_differs_from_secondary_alt(): void {
            $att    = $this->fake_attachment();
            $parent = $this->model_post();
            $back_alt = $this->build( $att, $parent, 'back' )['alt'];
            $this->assertNotSame( 'Anisyia — live webcam model image', $back_alt,
                'Upgraded back alt must not be the old secondary alt'
            );
        }

        public function test_custom_caption_not_overwritable(): void {
            $this->assertFalse( (bool) call_private( 'is_overwritable_caption',
                [ 'My hand-written caption about Anisyia' ] ),
                'Custom caption must NOT be overwritable'
            );
        }

        public function test_custom_description_not_overwritable(): void {
            $this->assertFalse( (bool) call_private( 'is_overwritable_description',
                [ 'Fully custom description written by the editor.' ] ),
                'Custom description must NOT be overwritable'
            );
        }
    }
}
