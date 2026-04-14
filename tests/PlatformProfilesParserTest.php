<?php
/**
 * TMW SEO Engine — Platform Profiles Parser Tests
 *
 * Tests for the safe platform URL parsing architecture (v2).
 *
 * Design rules this file verifies:
 *   1. No generic "first path segment" fallback can produce a username.
 *   2. Multi-segment paths only parse when the full expected pattern matches.
 *   3. Lookalike domains (evilfansly.com, notstripchat.com, etc.) are rejected.
 *   4. www / non-www and trailing-slash variants produce correct usernames.
 *   5. Structured parse results carry the correct success/reject_reason fields.
 *   6. Every registered platform has a pattern and all expected slugs are present.
 *
 * PHPUnit bootstrap (defined in phpunit.xml) runs before this file and defines
 * ABSPATH, loads PlatformRegistry and various admin stubs.
 *
 * PlatformProfiles is NOT loaded by the default bootstrap; this file loads it
 * directly using __DIR__ so no constant dependency exists.
 *
 * No custom TMWSEO\Engine\Logs stub is defined here. The parsing methods tested
 * below (extract_username_from_profile_url, parse_url_for_platform_structured)
 * do not invoke Logs in any executed code path, so no stub is required. If the
 * bootstrap transitively loads the real Logs class that is equally fine — this
 * file declares nothing inside the TMWSEO\Engine namespace.
 *
 * @package TMWSEO\Engine\Tests
 */

declare(strict_types=1);

// Load PlatformProfiles if not already available.
// The bootstrap loads PlatformRegistry but not PlatformProfiles.
if ( ! class_exists( 'TMWSEO\\Engine\\Platform\\PlatformProfiles' ) ) {
    require_once __DIR__ . '/../includes/platform/class-platform-profiles.php';
}

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Platform\PlatformProfiles;
use TMWSEO\Engine\Platform\PlatformRegistry;

/**
 * Exhaustive parser tests for PlatformProfiles.
 *
 * Groups:
 *   A. Simple /{username} platforms — positive extractions
 *   B. Multi-segment /prefix/{username} platforms — PR #419 danger zone
 *   C. Special-case explicit handlers (myfreecams, flirt4free, carrd, fansly, etc.)
 *   D. Dangerous path-prefix inputs — must return ''
 *   E. Dangerous lookalike-host inputs — must be rejected with host_mismatch
 *   F. parse_url_for_platform_structured() structured result shape
 *   G. PlatformRegistry sanity — slugs and patterns present
 */
class PlatformProfilesParserTest extends TestCase {

    // =========================================================================
    // A. Simple /{username} platforms — positive extractions
    // =========================================================================

    public function test_chaturbate_simple(): void {
        $this->assertSame( 'janedoe', PlatformProfiles::extract_username_from_profile_url( 'chaturbate', 'https://chaturbate.com/janedoe' ) );
    }

    public function test_chaturbate_trailing_slash(): void {
        $this->assertSame( 'janedoe', PlatformProfiles::extract_username_from_profile_url( 'chaturbate', 'https://chaturbate.com/janedoe/' ) );
    }

    public function test_chaturbate_www(): void {
        $this->assertSame( 'janedoe', PlatformProfiles::extract_username_from_profile_url( 'chaturbate', 'https://www.chaturbate.com/janedoe' ) );
    }

    public function test_chaturbate_http(): void {
        $this->assertSame( 'janedoe', PlatformProfiles::extract_username_from_profile_url( 'chaturbate', 'http://chaturbate.com/janedoe' ) );
    }

    public function test_stripchat_simple(): void {
        $this->assertSame( 'sweetmodel', PlatformProfiles::extract_username_from_profile_url( 'stripchat', 'https://stripchat.com/sweetmodel' ) );
    }

    public function test_stripchat_trailing_slash(): void {
        $this->assertSame( 'sweetmodel', PlatformProfiles::extract_username_from_profile_url( 'stripchat', 'https://stripchat.com/sweetmodel/' ) );
    }

    public function test_stripchat_locale_subdomain(): void {
        // True subdomains of stripchat.com are allowed (locale variants)
        $this->assertSame( 'sweetmodel', PlatformProfiles::extract_username_from_profile_url( 'stripchat', 'https://es.stripchat.com/sweetmodel' ) );
    }

    public function test_camsoda(): void {
        $this->assertSame( 'rosiemodel', PlatformProfiles::extract_username_from_profile_url( 'camsoda', 'https://www.camsoda.com/rosiemodel' ) );
    }

    public function test_camsoda_no_www(): void {
        $this->assertSame( 'rosiemodel', PlatformProfiles::extract_username_from_profile_url( 'camsoda', 'https://camsoda.com/rosiemodel' ) );
    }

    public function test_bonga(): void {
        $this->assertSame( 'milamodel', PlatformProfiles::extract_username_from_profile_url( 'bonga', 'https://bongacams.com/milamodel' ) );
    }

    public function test_cam4(): void {
        $this->assertSame( 'alicestar', PlatformProfiles::extract_username_from_profile_url( 'cam4', 'https://www.cam4.com/alicestar' ) );
    }

    public function test_cam4_no_www(): void {
        $this->assertSame( 'alicestar', PlatformProfiles::extract_username_from_profile_url( 'cam4', 'https://cam4.com/alicestar' ) );
    }

    public function test_sinparty(): void {
        $this->assertSame( 'bella99', PlatformProfiles::extract_username_from_profile_url( 'sinparty', 'https://sinparty.com/bella99' ) );
    }

    public function test_revealme(): void {
        $this->assertSame( 'hotmodel', PlatformProfiles::extract_username_from_profile_url( 'revealme', 'https://revealme.com/hotmodel' ) );
    }

    public function test_linktree(): void {
        $this->assertSame( 'janelinks', PlatformProfiles::extract_username_from_profile_url( 'linktree', 'https://linktr.ee/janelinks' ) );
    }

    public function test_allmylinks(): void {
        $this->assertSame( 'mylinks', PlatformProfiles::extract_username_from_profile_url( 'allmylinks', 'https://allmylinks.com/mylinks' ) );
    }

    public function test_beacons(): void {
        $this->assertSame( 'janepage', PlatformProfiles::extract_username_from_profile_url( 'beacons', 'https://beacons.ai/janepage' ) );
    }

    public function test_solo_to(): void {
        $this->assertSame( 'mysolo', PlatformProfiles::extract_username_from_profile_url( 'solo_to', 'https://solo.to/mysolo' ) );
    }

    public function test_camscom(): void {
        $this->assertSame( 'star2024', PlatformProfiles::extract_username_from_profile_url( 'camscom', 'https://www.cams.com/star2024' ) );
    }

    public function test_camscom_no_www(): void {
        $this->assertSame( 'star2024', PlatformProfiles::extract_username_from_profile_url( 'camscom', 'https://cams.com/star2024' ) );
    }

    // =========================================================================
    // B. Multi-segment /prefix/{username} platforms — PR #419 danger zone
    // =========================================================================

    public function test_imlive_full_path(): void {
        // Pattern: https://imlive.com/live-sex-chats/cam-girls/video-chats/{username}/
        $this->assertSame(
            'SophieLive',
            PlatformProfiles::extract_username_from_profile_url(
                'imlive',
                'https://imlive.com/live-sex-chats/cam-girls/video-chats/SophieLive/'
            )
        );
    }

    public function test_imlive_no_trailing_slash(): void {
        $this->assertSame(
            'SophieLive',
            PlatformProfiles::extract_username_from_profile_url(
                'imlive',
                'https://imlive.com/live-sex-chats/cam-girls/video-chats/SophieLive'
            )
        );
    }

    public function test_jerkmate_cam_prefix(): void {
        // Pattern: https://jerkmatelive.com/cam/{username}
        $this->assertSame( 'modelname', PlatformProfiles::extract_username_from_profile_url( 'jerkmate', 'https://jerkmatelive.com/cam/modelname' ) );
    }

    public function test_jerkmate_trailing_slash(): void {
        $this->assertSame( 'modelname', PlatformProfiles::extract_username_from_profile_url( 'jerkmate', 'https://jerkmatelive.com/cam/modelname/' ) );
    }

    public function test_xtease_cam_prefix(): void {
        // Pattern: https://xtease.com/cam/{username}
        $this->assertSame( 'xmodel', PlatformProfiles::extract_username_from_profile_url( 'xtease', 'https://xtease.com/cam/xmodel' ) );
    }

    public function test_olecams_webcam_prefix(): void {
        // Pattern: https://www.olecams.com/webcam/{username}
        $this->assertSame( 'olestar', PlatformProfiles::extract_username_from_profile_url( 'olecams', 'https://www.olecams.com/webcam/olestar' ) );
    }

    public function test_olecams_no_www(): void {
        $this->assertSame( 'olestar', PlatformProfiles::extract_username_from_profile_url( 'olecams', 'https://olecams.com/webcam/olestar' ) );
    }

    public function test_camera_prive_us_room(): void {
        // Pattern: https://cameraprive.com/us/room/{username}
        $this->assertSame( 'privemodel', PlatformProfiles::extract_username_from_profile_url( 'camera_prive', 'https://cameraprive.com/us/room/privemodel' ) );
    }

    public function test_camera_prive_trailing_slash(): void {
        $this->assertSame( 'privemodel', PlatformProfiles::extract_username_from_profile_url( 'camera_prive', 'https://cameraprive.com/us/room/privemodel/' ) );
    }

    public function test_camirada_webcam_prefix(): void {
        // Pattern: https://camirada.com/webcam/{username}
        $this->assertSame( 'camgirl', PlatformProfiles::extract_username_from_profile_url( 'camirada', 'https://camirada.com/webcam/camgirl' ) );
    }

    public function test_xcams_locale_path(): void {
        // Pattern: https://www.xcams.com/fr/chat/{username}/
        $this->assertSame( 'xcamsmodel', PlatformProfiles::extract_username_from_profile_url( 'xcams', 'https://www.xcams.com/fr/chat/xcamsmodel/' ) );
    }

    public function test_xcams_no_www(): void {
        $this->assertSame( 'xcamsmodel', PlatformProfiles::extract_username_from_profile_url( 'xcams', 'https://xcams.com/fr/chat/xcamsmodel/' ) );
    }

    public function test_xlovecam_locale_path(): void {
        // Pattern: https://www.xlovecam.com/nl/chat/{username}/
        $this->assertSame( 'lovemodel', PlatformProfiles::extract_username_from_profile_url( 'xlovecam', 'https://www.xlovecam.com/nl/chat/lovemodel/' ) );
    }

    public function test_xlovecam_no_www_no_trailing_slash(): void {
        $this->assertSame( 'lovemodel', PlatformProfiles::extract_username_from_profile_url( 'xlovecam', 'https://xlovecam.com/nl/chat/lovemodel' ) );
    }

    public function test_streamate_cam_prefix(): void {
        // Pattern: https://www.streamate.com/cam/{username}/
        $this->assertSame( 'streamstar', PlatformProfiles::extract_username_from_profile_url( 'streamate', 'https://www.streamate.com/cam/streamstar/' ) );
    }

    public function test_livefreefun_cam_prefix(): void {
        // Pattern: https://livefreefun.org/cam/{username}
        $this->assertSame( 'funmodel', PlatformProfiles::extract_username_from_profile_url( 'livefreefun', 'https://livefreefun.org/cam/funmodel' ) );
    }

    public function test_royal_cams_cam_prefix(): void {
        // Pattern: https://royalcamslive.com/cam/{username}
        $this->assertSame( 'royalqueen', PlatformProfiles::extract_username_from_profile_url( 'royal_cams', 'https://royalcamslive.com/cam/royalqueen' ) );
    }

    public function test_slut_roulette_cams_prefix(): void {
        // Pattern: https://slutroulette.com/cams/{username}
        $this->assertSame( 'roulette99', PlatformProfiles::extract_username_from_profile_url( 'slut_roulette', 'https://slutroulette.com/cams/roulette99' ) );
    }

    public function test_sweepsex_cam_prefix(): void {
        // Pattern: https://sweepsex.com/cam/{username}
        $this->assertSame( 'sweepmodel', PlatformProfiles::extract_username_from_profile_url( 'sweepsex', 'https://sweepsex.com/cam/sweepmodel' ) );
    }

    public function test_delhi_sex_chat_model_prefix(): void {
        // Pattern: https://www.dscgirls.live/model/{username}
        $this->assertSame( 'delhigirl', PlatformProfiles::extract_username_from_profile_url( 'delhi_sex_chat', 'https://www.dscgirls.live/model/delhigirl' ) );
    }

    // =========================================================================
    // C. Special-case explicit handlers
    // =========================================================================

    public function test_myfreecams_fragment(): void {
        $this->assertSame( 'MyModel', PlatformProfiles::extract_username_from_profile_url( 'myfreecams', 'https://www.myfreecams.com/#MyModel' ) );
    }

    public function test_myfreecams_no_fragment_returns_empty(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'myfreecams', 'https://www.myfreecams.com/' ) );
    }

    public function test_flirt4free_query_param(): void {
        $this->assertSame( 'flirtgirl', PlatformProfiles::extract_username_from_profile_url( 'flirt4free', 'https://www.flirt4free.com/?model=flirtgirl' ) );
    }

    public function test_flirt4free_query_param_no_www(): void {
        $this->assertSame( 'flirtgirl', PlatformProfiles::extract_username_from_profile_url( 'flirt4free', 'https://flirt4free.com/?model=flirtgirl' ) );
    }

    public function test_flirt4free_path_variant(): void {
        // All three prefix segments (/videos/girls/models/) are required
        $this->assertSame( 'flirtgirl', PlatformProfiles::extract_username_from_profile_url( 'flirt4free', 'https://flirt4free.com/videos/girls/models/flirtgirl/' ) );
    }

    public function test_flirt4free_path_variant_no_trailing_slash(): void {
        $this->assertSame( 'flirtgirl', PlatformProfiles::extract_username_from_profile_url( 'flirt4free', 'https://www.flirt4free.com/videos/girls/models/flirtgirl' ) );
    }

    public function test_sakuralive_query_string(): void {
        $this->assertSame( 'SakuraUser', PlatformProfiles::extract_username_from_profile_url( 'sakuralive', 'https://www.sakuralive.com/preview.shtml?SakuraUser' ) );
    }

    public function test_carrd_subdomain(): void {
        $this->assertSame( 'janecam', PlatformProfiles::extract_username_from_profile_url( 'carrd', 'https://janecam.carrd.co/' ) );
    }

    public function test_carrd_no_trailing_slash(): void {
        $this->assertSame( 'janecam', PlatformProfiles::extract_username_from_profile_url( 'carrd', 'https://janecam.carrd.co' ) );
    }

    public function test_carrd_bare_root_returns_empty(): void {
        // carrd.co itself has no username subdomain
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'carrd', 'https://carrd.co/' ) );
    }

    public function test_carrd_www_returns_empty(): void {
        // www is a reserved subdomain — not a valid username
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'carrd', 'https://www.carrd.co/' ) );
    }

    public function test_carrd_multi_level_subdomain_returns_empty(): void {
        // foo.bar.carrd.co — two-level subdomain is ambiguous; reject it
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'carrd', 'https://foo.bar.carrd.co/' ) );
    }

    public function test_fansly_with_posts(): void {
        $this->assertSame( 'fanslymodel', PlatformProfiles::extract_username_from_profile_url( 'fansly', 'https://fansly.com/fanslymodel/posts' ) );
    }

    public function test_fansly_bare_username(): void {
        // Bare /{username} without /posts — common SERP form; must be accepted
        $this->assertSame( 'fanslymodel', PlatformProfiles::extract_username_from_profile_url( 'fansly', 'https://fansly.com/fanslymodel' ) );
    }

    public function test_fansly_trailing_slash(): void {
        $this->assertSame( 'fanslymodel', PlatformProfiles::extract_username_from_profile_url( 'fansly', 'https://fansly.com/fanslymodel/' ) );
    }

    public function test_livejasmin_canonical(): void {
        // Pattern: https://www.livejasmin.com/en/chat/{username}
        $this->assertSame( 'jasminstar', PlatformProfiles::extract_username_from_profile_url( 'livejasmin', 'https://www.livejasmin.com/en/chat/jasminstar' ) );
    }

    public function test_livejasmin_no_www(): void {
        $this->assertSame( 'jasminstar', PlatformProfiles::extract_username_from_profile_url( 'livejasmin', 'https://livejasmin.com/en/chat/jasminstar' ) );
    }

    public function test_livejasmin_trailing_slash(): void {
        $this->assertSame( 'jasminstar', PlatformProfiles::extract_username_from_profile_url( 'livejasmin', 'https://www.livejasmin.com/en/chat/jasminstar/' ) );
    }

    // =========================================================================
    // D. Dangerous path-prefix inputs — must return '' (never a bogus username)
    // =========================================================================

    public function test_imlive_wrong_path_returns_empty(): void {
        // /cam/ does not match imlive's required multi-segment path
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'imlive', 'https://imlive.com/cam/SophieLive' ) );
    }

    public function test_imlive_prefix_segment_not_extracted(): void {
        // Generic fallback would extract 'live-sex-chats' — must return ''
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'imlive', 'https://imlive.com/live-sex-chats' ) );
    }

    public function test_xcams_wrong_path_returns_empty(): void {
        // /cam/ does not match xcams' required /fr/chat/ prefix
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'xcams', 'https://xcams.com/cam/xcamsmodel' ) );
    }

    public function test_xcams_locale_prefix_not_extracted(): void {
        // Generic fallback would extract 'fr' — must return ''
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'xcams', 'https://xcams.com/fr' ) );
    }

    public function test_xlovecam_locale_prefix_not_extracted(): void {
        // Generic fallback would extract 'nl' — must return ''
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'xlovecam', 'https://xlovecam.com/nl' ) );
    }

    public function test_camera_prive_us_prefix_not_extracted(): void {
        // Generic fallback would extract 'us' — must return ''
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'camera_prive', 'https://cameraprive.com/us/room' ) );
    }

    public function test_camera_prive_wrong_locale_returns_empty(): void {
        // /fr/room/ is not the canonical /us/room/ path — must return ''
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'camera_prive', 'https://cameraprive.com/fr/room/privemodel' ) );
    }

    public function test_olecams_cam_prefix_returns_empty(): void {
        // /cam/ instead of /webcam/ must not match
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'olecams', 'https://olecams.com/cam/olestar' ) );
    }

    public function test_streamate_prefix_alone_returns_empty(): void {
        // /cam with no username segment must return ''
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'streamate', 'https://www.streamate.com/cam' ) );
    }

    public function test_livejasmin_wrong_path_returns_empty(): void {
        // /en/girl/chat/ is not the canonical /en/chat/ path
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'livejasmin', 'https://www.livejasmin.com/en/girl/chat/jasminstar' ) );
    }

    public function test_fansly_reserved_word_not_extracted(): void {
        // 'live' is in the reserved list — must not be extracted as a username
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'fansly', 'https://fansly.com/live' ) );
    }

    public function test_flirt4free_wrong_path_segment_returns_empty(): void {
        // Second-level must be 'models', not 'cams' — must return ''
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'flirt4free', 'https://flirt4free.com/videos/girls/cams/flirtgirl/' ) );
    }

    public function test_wrong_domain_returns_empty(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'stripchat', 'https://chaturbate.com/janedoe' ) );
    }

    public function test_empty_url_returns_empty(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'chaturbate', '' ) );
    }

    public function test_garbage_url_returns_empty(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'chaturbate', 'not-a-url-at-all' ) );
    }

    public function test_unknown_platform_returns_empty(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'nonexistent_platform_xyz', 'https://chaturbate.com/janedoe' ) );
    }

    // =========================================================================
    // E. Dangerous lookalike-host inputs — must be rejected
    // =========================================================================

    /**
     * evilfansly.com contains 'fansly.com' as a substring but is NOT a subdomain.
     * strpos()-based checks would incorrectly pass it; host_equals_or_subdomain_of() rejects it.
     */
    public function test_lookalike_evilfansly_returns_empty(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'fansly', 'https://evilfansly.com/user' ) );
    }

    /**
     * notstripchat.com contains 'stripchat.com' as a substring but is NOT a subdomain.
     */
    public function test_lookalike_notstripchat_returns_empty(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'stripchat', 'https://notstripchat.com/user' ) );
    }

    /**
     * notchaturbate.com must not match the chaturbate platform.
     */
    public function test_lookalike_notchaturbate_returns_empty(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'chaturbate', 'https://notchaturbate.com/user' ) );
    }

    /**
     * foo.carrd.co.evil.com contains '.carrd.co' as a substring in the middle but
     * does NOT end with '.carrd.co' — suffix check correctly rejects it.
     */
    public function test_lookalike_carrd_evil_domain_returns_empty(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'carrd', 'https://foo.carrd.co.evil.com/' ) );
    }

    /**
     * Structured result for a lookalike host must report host_mismatch.
     */
    public function test_lookalike_structured_reports_host_mismatch(): void {
        $result = PlatformProfiles::parse_url_for_platform_structured( 'fansly', 'https://evilfansly.com/user' );
        $this->assertFalse( $result['success'] );
        $this->assertSame( 'host_mismatch', $result['reject_reason'] );
        $this->assertSame( '', $result['username'] );
    }

    public function test_lookalike_stripchat_structured_host_mismatch(): void {
        $result = PlatformProfiles::parse_url_for_platform_structured( 'stripchat', 'https://notstripchat.com/user' );
        $this->assertFalse( $result['success'] );
        $this->assertSame( 'host_mismatch', $result['reject_reason'] );
    }

    public function test_lookalike_carrd_evil_domain_structured_host_mismatch(): void {
        $result = PlatformProfiles::parse_url_for_platform_structured( 'carrd', 'https://foo.carrd.co.evil.com/janecam' );
        $this->assertFalse( $result['success'] );
        $this->assertSame( 'host_mismatch', $result['reject_reason'] );
    }

    // =========================================================================
    // F. parse_url_for_platform_structured() — structured result shape
    // =========================================================================

    public function test_structured_success(): void {
        $result = PlatformProfiles::parse_url_for_platform_structured( 'chaturbate', 'https://chaturbate.com/janedoe' );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'janedoe', $result['username'] );
        $this->assertSame( 'chaturbate', $result['normalized_platform'] );
        $this->assertNotEmpty( $result['normalized_url'] );
        $this->assertSame( '', $result['reject_reason'] );
    }

    public function test_structured_result_always_has_all_five_keys(): void {
        // Both success and failure cases must always carry all five keys
        $cases = [
            [ 'chaturbate', 'https://chaturbate.com/janedoe' ],       // success
            [ 'stripchat',  'https://chaturbate.com/janedoe' ],       // host_mismatch
            [ 'xcams',      'https://xcams.com/cam/xcamsmodel' ],      // extraction_failed
            [ 'fakeplatform', 'https://fakeplatform.com/user' ],      // unknown_platform
        ];
        foreach ( $cases as [ $platform, $url ] ) {
            $result = PlatformProfiles::parse_url_for_platform_structured( $platform, $url );
            $this->assertArrayHasKey( 'success',             $result, "Missing 'success' for $platform / $url" );
            $this->assertArrayHasKey( 'username',            $result, "Missing 'username' for $platform / $url" );
            $this->assertArrayHasKey( 'normalized_platform', $result, "Missing 'normalized_platform' for $platform / $url" );
            $this->assertArrayHasKey( 'normalized_url',      $result, "Missing 'normalized_url' for $platform / $url" );
            $this->assertArrayHasKey( 'reject_reason',       $result, "Missing 'reject_reason' for $platform / $url" );
        }
    }

    public function test_structured_host_mismatch(): void {
        $result = PlatformProfiles::parse_url_for_platform_structured( 'stripchat', 'https://chaturbate.com/janedoe' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( '', $result['username'] );
        $this->assertSame( 'host_mismatch', $result['reject_reason'] );
    }

    public function test_structured_extraction_failed(): void {
        // Host is correct (xcams.com) but path doesn't match /fr/chat/ prefix
        $result = PlatformProfiles::parse_url_for_platform_structured( 'xcams', 'https://xcams.com/cam/xcamsmodel' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( '', $result['username'] );
        $this->assertSame( 'extraction_failed', $result['reject_reason'] );
    }

    public function test_structured_unknown_platform(): void {
        $result = PlatformProfiles::parse_url_for_platform_structured( 'fakeplatform', 'https://fakeplatform.com/user' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'unknown_platform', $result['reject_reason'] );
    }

    public function test_structured_imlive_full_path(): void {
        $result = PlatformProfiles::parse_url_for_platform_structured(
            'imlive',
            'https://imlive.com/live-sex-chats/cam-girls/video-chats/SophieLive/'
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'SophieLive', $result['username'] );
        $this->assertStringContainsString( 'SophieLive', $result['normalized_url'] );
    }

    public function test_structured_imlive_wrong_path_is_extraction_failed(): void {
        // Host is correct but wrong path — must be extraction_failed, NOT host_mismatch
        $result = PlatformProfiles::parse_url_for_platform_structured( 'imlive', 'https://imlive.com/cam/SophieLive' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'extraction_failed', $result['reject_reason'] );
    }

    public function test_structured_carrd(): void {
        $result = PlatformProfiles::parse_url_for_platform_structured( 'carrd', 'https://janecam.carrd.co/' );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'janecam', $result['username'] );
    }

    public function test_structured_myfreecams_fragment(): void {
        $result = PlatformProfiles::parse_url_for_platform_structured( 'myfreecams', 'https://www.myfreecams.com/#MyModel' );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'MyModel', $result['username'] );
    }

    // =========================================================================
    // G. PlatformRegistry sanity
    // =========================================================================

    /**
     * Every registered platform must have a non-empty profile_url_pattern
     * containing the {username} placeholder. This is the foundation of the
     * safe pattern-regex approach.
     */
    public function test_all_platforms_have_valid_pattern(): void {
        foreach ( PlatformRegistry::get_platforms() as $platform ) {
            $slug    = $platform['slug'] ?? '(unknown)';
            $pattern = $platform['profile_url_pattern'] ?? '';

            $this->assertNotEmpty( $pattern, "Platform $slug must have a profile_url_pattern" );
            $this->assertStringContainsString(
                '{username}',
                $pattern,
                "Platform $slug pattern must contain {username}: $pattern"
            );
        }
    }

    /**
     * Confirm all expected platform slugs are registered (no accidental removals).
     */
    public function test_expected_platform_slugs_are_registered(): void {
        $slugs = PlatformRegistry::get_slugs();

        $required = [
            'linktree', 'allmylinks', 'beacons', 'solo_to', 'carrd',
            'livejasmin', 'fansly', 'stripchat', 'chaturbate', 'myfreecams',
            'camsoda', 'bonga', 'cam4', 'imlive', 'streamate', 'flirt4free',
            'jerkmate', 'camscom', 'sinparty', 'xtease', 'olecams',
            'camera_prive', 'camirada', 'delhi_sex_chat', 'livefreefun',
            'revealme', 'royal_cams', 'sakuralive', 'slut_roulette',
            'sweepsex', 'xcams', 'xlovecam',
        ];

        foreach ( $required as $slug ) {
            $this->assertContains( $slug, $slugs, "Platform slug '$slug' must be registered" );
        }
    }
}
