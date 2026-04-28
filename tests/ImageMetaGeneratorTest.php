<?php
/**
 * TMW SEO Engine — Image Meta Generator Tests (v2 role-aware)
 *
 * Coverage:
 *   T1   Primary alt contains "verified live webcam model profile photo"
 *   T2   Banner alt contains "banner image"; NOT "profile photo"
 *   T3   Front alt contains "profile preview image"; NOT "profile photo" / "banner"
 *   T4   Back alt contains "profile preview image"; NOT "profile photo" / "banner"
 *   T5   Front and back alts differ from each other
 *   T6   Secondary role produces generic "live webcam model image" suffix
 *   T7   Unknown/invalid role falls back to primary template
 *   T8   IMAGE_META_VERSION constant equals 2
 *   T9   v1 alt detected by matches_v1_alt
 *   T10  v1 title detected by matches_v1_model_title
 *   T11  v1 caption detected by matches_v1_caption
 *   T12  v1 description detected by matches_v1_description
 *   T13  User-customised alt NOT flagged as v1
 *   T14  User-customised description NOT flagged as v1
 *   T15  v1 primary alt differs from v2 front alt (upgrade would change the value)
 *   T16  platform_label maps all known slugs
 *   T17  platform_label ucwords unknown slug
 *   T18  sanitise_role rejects invalid input
 *   T19  sanitise_role accepts all 5 valid values
 *   T20  resolve_secondary_keywords returns >= 2 entries (fallback guarantee)
 *   T21  resolve_secondary_keywords: kws[0] != kws[1] on fallback path
 *   T22  No comma-separated list in front alt
 *   T23  No comma-separated list in back alt
 *   T24  Banner alt differs from all other four roles
 *   T25  Video post uses screenshot template for all roles
 *   T26  Non-model non-video post uses site-name fallback template
 *   T27  Model name present in all role alts
 *   T28  Front title differs from primary title
 *   T29  Back title differs from front title
 *   T30  Front caption differs from primary caption
 *
 * Technique: private helpers are exercised via ReflectionMethod::invokeArgs()
 * which bypasses access modifiers while still enforcing type-safety in PHP 8.x.
 * The type-safety requirement means WP_Post fixtures MUST extend \WP_Post.
 * See "WP_Post fixture" block below.
 *
 * Note on meta-dependent paths:
 *   resolve_secondary_keywords() reads post meta (tmw_keyword_pack,
 *   rank_math_focus_keyword) and post tags.  The bootstrap provides no-op stubs
 *   for get_post_meta (always returns empty), so these sources return nothing in
 *   the unit test environment.  Tests T20/T21 exercise the static fallback path
 *   only; integration with a real DB would additionally validate the data-driven
 *   path (platform label, additional[], rank_math_focus_keyword).
 *
 * @package TMWSEO\Engine\Tests
 */
declare(strict_types=1);

// ── Global stubs needed by Image_Meta_Generator ───────────────────────────────
// These MUST be in the global namespace so they are found via PHP's
// function fall-through from any namespaced caller.
namespace {
    if ( ! function_exists( 'get_bloginfo' ) ) {
        function get_bloginfo( string $key = '' ): string {
            return $key === 'name' ? 'Top Models Webcam' : '';
        }
    }

    if ( ! function_exists( 'get_post_mime_type' ) ) {
        function get_post_mime_type( $id ): string {
            return 'image/jpeg';
        }
    }

    if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
        function get_post_thumbnail_id( $id ): int {
            return 0;
        }
    }

    if ( ! function_exists( 'wp_attachment_is_image' ) ) {
        function wp_attachment_is_image( $id ): bool {
            return (int) $id > 0;
        }
    }

    if ( ! function_exists( 'wp_update_post' ) ) {
        function wp_update_post( array $data ): int {
            // Record the call so tests can inspect it via $GLOBALS if needed.
            $GLOBALS['_tmw_wp_update_post_calls'][] = $data;
            return (int) ( $data['ID'] ?? 0 );
        }
    }

    if ( ! function_exists( 'remove_action' ) ) {
        function remove_action( string $hook, $callback, int $priority = 10 ): bool {
            return true;
        }
    }

    if ( ! function_exists( 'get_the_tags' ) ) {
        function get_the_tags( int $id ) {
            // Tests override via $GLOBALS['_tmw_test_tags'][$id].
            return $GLOBALS['_tmw_test_tags'][ $id ] ?? false;
        }
    }

    if ( ! function_exists( 'is_serialized' ) ) {
        function is_serialized( $data ): bool {
            return false;
        }
    }

    if ( ! function_exists( 'maybe_unserialize' ) ) {
        function maybe_unserialize( $data ) {
            return $data;
        }
    }

    // Load classes required by the test (not pulled in by bootstrap).
    require_once dirname( __DIR__ ) . '/includes/media/class-image-meta-hooks.php';
    require_once dirname( __DIR__ ) . '/includes/media/class-image-meta-generator.php';
}

// ── Test suite ────────────────────────────────────────────────────────────────
namespace TMWSEO\Engine\Tests {

    use PHPUnit\Framework\TestCase;
    use TMWSEO\Engine\Media\Image_Meta_Generator;
    use ReflectionMethod;

    // ── WP_Post fixture ───────────────────────────────────────────────────────
    // Image_Meta_Generator methods are type-hinted \WP_Post (global namespace).
    // PHP 8.x enforces parameter types even via Reflection::invokeArgs().
    // The bootstrap defines \WP_Post with only `public int $ID = 0`.
    // We extend it here to add the additional properties the generator reads.
    //
    // class_exists('WP_Post') in a namespace block resolves against the GLOBAL
    // \WP_Post (which the bootstrap already defines), so the guard must use
    // __NAMESPACE__ to target the namespaced class specifically.
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

    // ── Reflection helper ─────────────────────────────────────────────────────
    function call_private( string $method, array $args = [] ) {
        $ref = new ReflectionMethod( Image_Meta_Generator::class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( null, $args );
    }

    // ── Test class ────────────────────────────────────────────────────────────
    class ImageMetaGeneratorTest extends TestCase {

        protected function setUp(): void {
            $GLOBALS['_tmw_test_tags']           = [];
            $GLOBALS['_tmw_wp_update_post_calls'] = [];
        }

        // ── Fixtures ──────────────────────────────────────────────────────────

        private function model_post( array $props = [] ): WP_Post {
            return new WP_Post( array_merge( [
                'ID'         => 1,
                'post_title' => 'Anisyia',
                'post_type'  => 'model',
            ], $props ) );
        }

        private function video_post( array $props = [] ): WP_Post {
            return new WP_Post( array_merge( [
                'ID'         => 2,
                'post_title' => 'Hot Show',
                'post_type'  => 'tmw_video',
            ], $props ) );
        }

        private function generic_post( array $props = [] ): WP_Post {
            return new WP_Post( array_merge( [
                'ID'         => 3,
                'post_title' => 'Some Page',
                'post_type'  => 'page',
            ], $props ) );
        }

        private function fake_attachment( array $props = [] ): WP_Post {
            return new WP_Post( array_merge( [
                'ID'           => 99,
                'post_type'    => 'attachment',
                'post_title'   => '',
                'post_excerpt' => '',
                'post_content' => '',
                'post_name'    => 'anisyia-photo',
            ], $props ) );
        }

        /** Calls private build_meta_text(). */
        private function build( WP_Post $attachment, WP_Post $parent, string $role ): array {
            return call_private( 'build_meta_text', [ $attachment, $parent, $role ] );
        }

        // ── T1  Primary alt ───────────────────────────────────────────────────

        public function test_primary_alt_contains_profile_photo(): void {
            $meta = $this->build( $this->fake_attachment(), $this->model_post(), 'primary' );
            $this->assertStringContainsString(
                'verified live webcam model profile photo',
                $meta['alt']
            );
        }

        // ── T2  Banner alt ────────────────────────────────────────────────────

        public function test_banner_alt_contains_banner_image(): void {
            $meta = $this->build( $this->fake_attachment(), $this->model_post(), 'banner' );
            $this->assertStringContainsString( 'banner image', $meta['alt'] );
            $this->assertStringNotContainsString( 'profile photo', $meta['alt'] );
        }

        // ── T3  Front alt ─────────────────────────────────────────────────────

        public function test_front_alt_contains_profile_preview_image(): void {
            $meta = $this->build( $this->fake_attachment(), $this->model_post(), 'front' );
            $this->assertStringContainsString( 'profile preview image', $meta['alt'] );
            $this->assertStringNotContainsString( 'profile photo', $meta['alt'] );
            $this->assertStringNotContainsString( 'banner', $meta['alt'] );
        }

        // ── T4  Back alt ──────────────────────────────────────────────────────

        public function test_back_alt_contains_profile_preview_image(): void {
            $meta = $this->build( $this->fake_attachment(), $this->model_post(), 'back' );
            $this->assertStringContainsString( 'profile preview image', $meta['alt'] );
            $this->assertStringNotContainsString( 'profile photo', $meta['alt'] );
            $this->assertStringNotContainsString( 'banner', $meta['alt'] );
        }

        // ── T5  Front and back differ ─────────────────────────────────────────

        public function test_front_and_back_alts_differ(): void {
            $att    = $this->fake_attachment();
            $parent = $this->model_post();
            $front  = $this->build( $att, $parent, 'front' );
            $back   = $this->build( $att, $parent, 'back' );
            $this->assertNotSame( $front['alt'], $back['alt'],
                'Front and back alts must not be identical (different SEO signals required)' );
        }

        // ── T6  Secondary role ────────────────────────────────────────────────

        public function test_secondary_alt_is_generic_webcam_image(): void {
            $meta = $this->build( $this->fake_attachment(), $this->model_post(), 'secondary' );
            $this->assertStringContainsString( 'live webcam model image', $meta['alt'] );
            $this->assertStringNotContainsString( 'profile photo', $meta['alt'] );
            $this->assertStringNotContainsString( 'profile preview image', $meta['alt'] );
        }

        // ── T7  Invalid role falls back to primary ────────────────────────────

        public function test_invalid_role_falls_back_to_primary(): void {
            $primary_meta = $this->build( $this->fake_attachment(), $this->model_post(), 'primary' );
            $invalid_meta = $this->build( $this->fake_attachment(), $this->model_post(), 'GARBAGE' );
            $this->assertSame( $primary_meta['alt'], $invalid_meta['alt'],
                'sanitise_role() maps unknown input to primary, templates should match' );
        }

        // ── T8  VERSION constant ──────────────────────────────────────────────

        public function test_image_meta_version_is_2(): void {
            $this->assertSame( 2, Image_Meta_Generator::IMAGE_META_VERSION );
        }

        // ── T9  v1 alt detection ──────────────────────────────────────────────

        public function test_v1_alt_is_detected(): void {
            $v1 = 'Anisyia — verified live webcam model profile photo';
            $this->assertTrue(
                (bool) call_private( 'matches_v1_alt', [ $v1 ] ),
                'v1 alt suffix must be detected'
            );
        }

        // ── T10 v1 title detection ────────────────────────────────────────────

        public function test_v1_model_title_is_detected(): void {
            $v1 = 'Anisyia | Live Cam Model | Top Models Webcam';
            $this->assertTrue(
                (bool) call_private( 'matches_v1_model_title', [ $v1 ] )
            );
        }

        // ── T11 v1 caption detection ──────────────────────────────────────────

        public function test_v1_caption_is_detected(): void {
            $v1 = 'Profile photo of Anisyia, live webcam model on Top Models Webcam';
            $this->assertTrue(
                (bool) call_private( 'matches_v1_caption', [ $v1 ] )
            );
        }

        // ── T12 v1 description detection (NEW) ───────────────────────────────

        public function test_v1_description_is_detected(): void {
            $v1 = 'Featured profile image for Anisyia, a live cam model available on Top Models Webcam. Browse model profile, shows, and streaming schedule.';
            $this->assertTrue(
                (bool) call_private( 'matches_v1_description', [ $v1 ] ),
                'v1 description must be detected so Flipbox images can be upgraded'
            );
        }

        // ── T13 User-customised alt is NOT flagged ────────────────────────────

        public function test_custom_alt_not_flagged_as_v1(): void {
            $custom = 'Anisyia posing in studio — private shoot 2024';
            $this->assertFalse(
                (bool) call_private( 'matches_v1_alt', [ $custom ] ),
                'Custom alt must not trigger v1 overwrite path'
            );
        }

        // ── T14 User-customised description is NOT flagged ────────────────────

        public function test_custom_description_not_flagged_as_v1(): void {
            $custom = 'Anisyia is a top-rated cam model with 10 years of experience.';
            $this->assertFalse(
                (bool) call_private( 'matches_v1_description', [ $custom ] ),
                'Custom description must not trigger v1 overwrite path'
            );
        }

        // Also verify an empty description is not falsely detected
        public function test_empty_description_not_flagged_as_v1(): void {
            $this->assertFalse(
                (bool) call_private( 'matches_v1_description', [ '' ] )
            );
        }

        // ── T15 v1 primary alt differs from v2 front alt ─────────────────────

        public function test_v1_primary_alt_differs_from_v2_front_alt(): void {
            $att    = $this->fake_attachment();
            $parent = $this->model_post();
            $v1_alt = $this->build( $att, $parent, 'primary' )['alt'];
            $v2_alt = $this->build( $att, $parent, 'front' )['alt'];
            $this->assertNotSame( $v1_alt, $v2_alt,
                'Upgrading a v1 (primary) alt to v2 front must change the stored value' );
        }

        // ── T16 platform_label ────────────────────────────────────────────────

        public function test_platform_label_maps_known_slugs(): void {
            $cases = [
                'livejasmin'  => 'LiveJasmin',
                'chaturbate'  => 'Chaturbate',
                'stripchat'   => 'Stripchat',
                'myfreecams'  => 'MyFreeCams',
                'cam4'        => 'CAM4',
                'camsoda'     => 'CamSoda',
                'bongacams'   => 'BongaCams',
                'streamate'   => 'Streamate',
                'flirt4free'  => 'Flirt4Free',
                'imlive'      => 'ImLive',
            ];
            foreach ( $cases as $slug => $expected ) {
                $this->assertSame(
                    $expected,
                    call_private( 'platform_label', [ $slug ] ),
                    "platform_label('{$slug}') should return '{$expected}'"
                );
            }
        }

        // ── T17 platform_label ucwords unknown ────────────────────────────────

        public function test_platform_label_ucwords_unknown(): void {
            $this->assertSame( 'My Custom Platform', call_private( 'platform_label', [ 'my_custom_platform' ] ) );
            $this->assertSame( '', call_private( 'platform_label', [ '' ] ) );
        }

        // ── T18 sanitise_role rejects invalid ─────────────────────────────────

        public function test_sanitise_role_rejects_invalid(): void {
            $this->assertSame( 'primary', call_private( 'sanitise_role', [ 'INVALID' ] ) );
            $this->assertSame( 'primary', call_private( 'sanitise_role', [ '' ] ) );
            $this->assertSame( 'primary', call_private( 'sanitise_role', [ 'PRIMARY' ] ) ); // case-sensitive
        }

        // ── T19 sanitise_role accepts all valid values ────────────────────────

        public function test_sanitise_role_accepts_valid_values(): void {
            foreach ( [ 'primary', 'banner', 'front', 'back', 'secondary' ] as $role ) {
                $this->assertSame( $role, call_private( 'sanitise_role', [ $role ] ) );
            }
        }

        // ── T20 resolve_secondary_keywords >= 2 entries ───────────────────────

        public function test_resolve_secondary_keywords_returns_at_least_two(): void {
            // Bootstrap stubs return empty for all get_post_meta calls.
            // Static fallbacks guarantee at least four entries.
            $kws = call_private( 'resolve_secondary_keywords', [ 999 ] );
            $this->assertGreaterThanOrEqual( 2, count( $kws ),
                'Static fallbacks must guarantee at least 2 candidates' );
        }

        // ── T21 kws[0] != kws[1] on fallback path ────────────────────────────

        public function test_resolve_secondary_keywords_fallback_first_two_differ(): void {
            $kws = call_private( 'resolve_secondary_keywords', [ 888 ] );
            $this->assertNotSame( $kws[0] ?? '', $kws[1] ?? '',
                'Front (kws[0]) and back (kws[1]) keywords must differ even on fallback path' );
        }

        // ── T22/T23 No comma-separated stuffing ───────────────────────────────

        public function test_front_alt_has_no_comma(): void {
            $meta = $this->build( $this->fake_attachment(), $this->model_post(), 'front' );
            $this->assertStringNotContainsString( ',', $meta['alt'] );
        }

        public function test_back_alt_has_no_comma(): void {
            $meta = $this->build( $this->fake_attachment(), $this->model_post(), 'back' );
            $this->assertStringNotContainsString( ',', $meta['alt'] );
        }

        // ── T24 Banner differs from all other roles ───────────────────────────

        public function test_banner_alt_differs_from_all_other_roles(): void {
            $att    = $this->fake_attachment();
            $parent = $this->model_post();
            $banner = $this->build( $att, $parent, 'banner' )['alt'];
            foreach ( [ 'primary', 'front', 'back', 'secondary' ] as $role ) {
                $other = $this->build( $att, $parent, $role )['alt'];
                $this->assertNotSame( $banner, $other,
                    "Banner alt must differ from {$role} alt" );
            }
        }

        // ── T25 Video post ignores role, always screenshot ───────────────────

        public function test_video_post_uses_screenshot_template_for_all_roles(): void {
            $att   = $this->fake_attachment();
            $video = $this->video_post();
            foreach ( [ 'primary', 'front', 'back', 'banner' ] as $role ) {
                $meta = $this->build( $att, $video, $role );
                $this->assertStringContainsString(
                    'webcam show screenshot',
                    $meta['alt'],
                    "Video post role={$role} must use screenshot template"
                );
            }
        }

        // ── T26 Non-model non-video uses site-name fallback ───────────────────

        public function test_generic_post_uses_site_name_fallback(): void {
            $meta = $this->build( $this->fake_attachment(), $this->generic_post(), 'primary' );
            $this->assertStringContainsString( 'Top Models Webcam', $meta['alt'] );
            $this->assertStringNotContainsString( 'profile photo', $meta['alt'] );
        }

        // ── T27 Model name present in all role alts ───────────────────────────

        public function test_model_name_in_all_role_alts(): void {
            $att    = $this->fake_attachment();
            $parent = $this->model_post( [ 'post_title' => 'Anisyia' ] );
            foreach ( [ 'primary', 'banner', 'front', 'back', 'secondary' ] as $role ) {
                $this->assertStringContainsString(
                    'Anisyia',
                    $this->build( $att, $parent, $role )['alt'],
                    "Model name must appear in {$role} alt"
                );
            }
        }

        // ── T28 Front title differs from primary ──────────────────────────────

        public function test_front_title_differs_from_primary_title(): void {
            $att    = $this->fake_attachment();
            $parent = $this->model_post();
            $this->assertNotSame(
                $this->build( $att, $parent, 'primary' )['title'],
                $this->build( $att, $parent, 'front' )['title']
            );
        }

        // ── T29 Back title differs from front ─────────────────────────────────

        public function test_back_title_differs_from_front_title(): void {
            $att    = $this->fake_attachment();
            $parent = $this->model_post();
            $this->assertNotSame(
                $this->build( $att, $parent, 'front' )['title'],
                $this->build( $att, $parent, 'back' )['title']
            );
        }

        // ── T30 Front caption differs from primary ────────────────────────────

        public function test_front_caption_differs_from_primary_caption(): void {
            $att    = $this->fake_attachment();
            $parent = $this->model_post();
            $this->assertNotSame(
                $this->build( $att, $parent, 'primary' )['caption'],
                $this->build( $att, $parent, 'front' )['caption']
            );
        }

        // ── Extra: front and back descriptions differ ─────────────────────────

        public function test_front_and_back_descriptions_differ(): void {
            $att    = $this->fake_attachment();
            $parent = $this->model_post();
            $this->assertNotSame(
                $this->build( $att, $parent, 'front' )['description'],
                $this->build( $att, $parent, 'back' )['description']
            );
        }

        // ── Extra: front description is NOT the v1 "Featured profile image" ───

        public function test_front_description_is_not_v1_pattern(): void {
            $meta = $this->build( $this->fake_attachment(), $this->model_post(), 'front' );
            $this->assertFalse(
                (bool) call_private( 'matches_v1_description', [ $meta['description'] ] ),
                'v2 front description must not match the v1 primary description pattern'
            );
        }

        public function test_back_description_is_not_v1_pattern(): void {
            $meta = $this->build( $this->fake_attachment(), $this->model_post(), 'back' );
            $this->assertFalse(
                (bool) call_private( 'matches_v1_description', [ $meta['description'] ] ),
                'v2 back description must not match the v1 primary description pattern'
            );
        }
    }
}
