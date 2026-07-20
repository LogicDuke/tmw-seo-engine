<?php
/**
 * TMW SEO Engine — Twitter/X Platform Parser Tests
 *
 * Covers the 'twitter' platform slug added in v5.0.0:
 *   - Registry: entry exists, correct fields
 *   - Parser positives: x.com and twitter.com handles extracted correctly
 *   - Parser rejections: reserved paths, multi-segment paths, tweet URLs,
 *     invalid handle format, wrong host
 *   - Structured result shape: success/failure key contracts
 *
 * All tests use only public methods of PlatformProfiles and PlatformRegistry.
 * No subclassing of final classes. No Reflection.
 *
 * @package TMWSEO\Engine\Tests
 * @since   5.0.0
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Platform\PlatformProfiles;
use TMWSEO\Engine\Platform\PlatformRegistry;

class TwitterParserTest extends TestCase {

    // =========================================================================
    // A. Registry — entry exists and has correct shape
    // =========================================================================

    /** @test */
    public function test_twitter_registry_entry_exists(): void {
        $entry = PlatformRegistry::get( 'twitter' );
        $this->assertIsArray( $entry, 'PlatformRegistry::get("twitter") must return an array' );
    }

    /** @test */
    public function test_twitter_registry_slug_is_twitter(): void {
        $entry = PlatformRegistry::get( 'twitter' );
        $this->assertSame( 'twitter', $entry['slug'] ?? null );
    }

    /** @test */
    public function test_twitter_registry_pattern_uses_x_com(): void {
        $entry = PlatformRegistry::get( 'twitter' );
        $this->assertStringContainsString( 'x.com', (string) ( $entry['profile_url_pattern'] ?? '' ) );
    }

    /** @test */
    public function test_twitter_registry_pattern_contains_username_placeholder(): void {
        $entry = PlatformRegistry::get( 'twitter' );
        $this->assertStringContainsString( '{username}', (string) ( $entry['profile_url_pattern'] ?? '' ) );
    }

    /** @test */
    public function test_twitter_registry_priority_is_positive_integer(): void {
        $entry = PlatformRegistry::get( 'twitter' );
        $this->assertIsInt( $entry['priority'] ?? null );
        $this->assertGreaterThan( 0, $entry['priority'] );
    }

    /** @test */
    public function test_twitter_slug_in_get_slugs(): void {
        $this->assertContains( 'twitter', PlatformRegistry::get_slugs() );
    }

    // =========================================================================
    // B. Parser positives — handles correctly extracted
    // =========================================================================

    /** @test */
    public function test_x_com_simple_handle(): void {
        $this->assertSame(
            'Anisyia',
            PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/Anisyia' )
        );
    }

    /** @test */
    public function test_twitter_com_simple_handle(): void {
        $this->assertSame(
            'AishaDupont',
            PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://twitter.com/AishaDupont' )
        );
    }

    /** @test */
    public function test_x_com_www_prefix(): void {
        $this->assertSame(
            'Anisyia',
            PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://www.x.com/Anisyia' )
        );
    }

    /** @test */
    public function test_twitter_com_www_prefix(): void {
        $this->assertSame(
            'AishaDupont',
            PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://www.twitter.com/AishaDupont' )
        );
    }

    /** @test */
    public function test_at_prefix_stripped(): void {
        $this->assertSame(
            'Anisyia',
            PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/@Anisyia' )
        );
    }

    /** @test */
    public function test_underscore_in_handle(): void {
        $this->assertSame(
            'Ohh_Aisha',
            PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/Ohh_Aisha' )
        );
    }

    /** @test */
    public function test_http_scheme(): void {
        $this->assertSame(
            'Anisyia',
            PlatformProfiles::extract_username_from_profile_url( 'twitter', 'http://x.com/Anisyia' )
        );
    }

    /** @test */
    public function test_trailing_slash_does_not_break_extraction(): void {
        $this->assertSame(
            'Anisyia',
            PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/Anisyia/' )
        );
    }

    /** @test */
    public function test_all_numeric_handle(): void {
        // Twitter does not allow all-numeric usernames but some legacy accounts exist.
        // As long as it's 1-15 alphanum chars our regex accepts it.
        $this->assertSame(
            '123456',
            PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/123456' )
        );
    }

    /** @test */
    public function test_handle_exactly_15_chars(): void {
        $handle = 'AbCdEfGhIjKlMnO'; // 15 chars
        $this->assertSame(
            $handle,
            PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/' . $handle )
        );
    }

    // =========================================================================
    // C. Parser rejections — must return empty string
    // =========================================================================

    /** @test */
    public function test_rejects_home_path(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/home' ) );
    }

    /** @test */
    public function test_rejects_explore_path(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/explore' ) );
    }

    /** @test */
    public function test_rejects_notifications_path(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/notifications' ) );
    }

    /** @test */
    public function test_rejects_messages_path(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/messages' ) );
    }

    /** @test */
    public function test_rejects_search_path(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/search?q=test' ) );
    }

    /** @test */
    public function test_rejects_i_path(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/i/flow/login' ) );
    }

    /** @test */
    public function test_rejects_settings_path(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/settings/profile' ) );
    }

    /** @test */
    public function test_rejects_login_path(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/login' ) );
    }

    /** @test */
    public function test_rejects_logout_path(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/logout' ) );
    }

    /** @test */
    public function test_rejects_hashtag_path(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/hashtag/webcam' ) );
    }

    /** @test */
    public function test_rejects_privacy_path(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/privacy' ) );
    }

    /** @test */
    public function test_rejects_tos_path(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/tos' ) );
    }

    /** @test */
    public function test_rejects_about_path(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/about' ) );
    }

    /** @test */
    public function test_rejects_download_path(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/download' ) );
    }

    /** @test */
    public function test_rejects_status_reserved_segment(): void {
        // 'status' as the sole path segment is reserved
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/status' ) );
    }

    /** @test */
    public function test_rejects_tweet_url_with_status_in_path(): void {
        // /Anisyia/status/123456789 is a tweet, not a profile
        $this->assertSame(
            '',
            PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/Anisyia/status/123456789' )
        );
    }

    /** @test */
    public function test_rejects_two_segment_following_page(): void {
        $this->assertSame(
            '',
            PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/Anisyia/following' )
        );
    }

    /** @test */
    public function test_rejects_two_segment_likes_page(): void {
        $this->assertSame(
            '',
            PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/Anisyia/likes' )
        );
    }

    /** @test */
    public function test_rejects_two_segment_media_page(): void {
        $this->assertSame(
            '',
            PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/Anisyia/media' )
        );
    }

    /** @test */
    public function test_rejects_handle_with_hyphen(): void {
        // Twitter does not allow hyphens in handles
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/invalid-handle' ) );
    }

    /** @test */
    public function test_rejects_handle_with_dot(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/handle.dot' ) );
    }

    /** @test */
    public function test_rejects_handle_too_long(): void {
        $this->assertSame(
            '',
            PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/ThisHandleIsWayTooLong1' )
        );
    }

    /** @test */
    public function test_rejects_empty_path(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com' ) );
    }

    /** @test */
    public function test_rejects_root_slash_only(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com/' ) );
    }

    /** @test */
    public function test_rejects_wrong_host(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://facebook.com/Anisyia' ) );
    }

    /** @test */
    public function test_rejects_lookalike_x_com(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://evilx.com/Anisyia' ) );
    }

    /** @test */
    public function test_rejects_lookalike_twitter_com(): void {
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://nottwitter.com/handle' ) );
    }

    /** @test */
    public function test_rejects_x_com_in_subdomain_of_other_host(): void {
        // x.com.evil.net should NOT match x.com
        $this->assertSame( '', PlatformProfiles::extract_username_from_profile_url( 'twitter', 'https://x.com.evil.net/Anisyia' ) );
    }

    // =========================================================================
    // D. Structured result shape — parse_url_for_platform_structured()
    // =========================================================================

    /** @test */
    public function test_structured_success_shape_x_com(): void {
        $result = PlatformProfiles::parse_url_for_platform_structured( 'twitter', 'https://x.com/Anisyia' );

        $this->assertTrue( $result['success'], 'success must be true' );
        $this->assertSame( 'Anisyia', $result['username'] );
        $this->assertSame( 'twitter', $result['normalized_platform'] );
        $this->assertStringContainsString( 'Anisyia', $result['normalized_url'] );
        $this->assertStringContainsString( 'x.com', $result['normalized_url'] );
        $this->assertSame( '', $result['reject_reason'] );
    }

    /** @test */
    public function test_structured_success_shape_twitter_com(): void {
        $result = PlatformProfiles::parse_url_for_platform_structured( 'twitter', 'https://twitter.com/AishaDupont' );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'AishaDupont', $result['username'] );
        // Canonical output URL must use x.com, NOT twitter.com
        $this->assertStringContainsString( 'x.com', $result['normalized_url'] );
        $this->assertStringNotContainsString( 'twitter.com', $result['normalized_url'] );
    }

    /** @test */
    public function test_structured_reserved_path_returns_extraction_failed(): void {
        $result = PlatformProfiles::parse_url_for_platform_structured( 'twitter', 'https://x.com/home' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( '', $result['username'] );
        $this->assertSame( 'extraction_failed', $result['reject_reason'] );
    }

    /** @test */
    public function test_structured_tweet_url_returns_extraction_failed(): void {
        $result = PlatformProfiles::parse_url_for_platform_structured( 'twitter', 'https://x.com/Anisyia/status/123456789' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'extraction_failed', $result['reject_reason'] );
    }

    /** @test */
    public function test_structured_wrong_host_returns_host_mismatch(): void {
        $result = PlatformProfiles::parse_url_for_platform_structured( 'twitter', 'https://evilx.com/Anisyia' );

        $this->assertFalse( $result['success'] );
        $this->assertSame( 'host_mismatch', $result['reject_reason'] );
    }

    /** @test */
    public function test_structured_result_always_has_all_five_keys(): void {
        $result = PlatformProfiles::parse_url_for_platform_structured( 'twitter', 'https://x.com/home' );

        foreach ( [ 'success', 'username', 'normalized_platform', 'normalized_url', 'reject_reason' ] as $key ) {
            $this->assertArrayHasKey( $key, $result, "Key '{$key}' must always be present" );
        }
    }

    /** @test */
    public function test_canonical_output_url_is_x_com_even_for_twitter_com_input(): void {
        $result = PlatformProfiles::parse_url_for_platform_structured( 'twitter', 'https://twitter.com/Anisyia' );

        $this->assertTrue( $result['success'] );
        $this->assertStringStartsWith( 'https://x.com/', $result['normalized_url'] );
    }

    // =========================================================================
    // E. Non-interference — twitter parser does not affect other platforms
    // =========================================================================

    /** @test */
    public function test_chaturbate_unaffected_by_twitter_changes(): void {
        $this->assertSame(
            'janedoe',
            PlatformProfiles::extract_username_from_profile_url( 'chaturbate', 'https://chaturbate.com/janedoe' )
        );
    }

    /** @test */
    public function test_fansly_unaffected_by_twitter_changes(): void {
        $this->assertSame(
            'janedoe',
            PlatformProfiles::extract_username_from_profile_url( 'fansly', 'https://fansly.com/janedoe/posts' )
        );
    }

    /** @test */
    public function test_stripchat_unaffected_by_twitter_changes(): void {
        $this->assertSame(
            'sweetmodel',
            PlatformProfiles::extract_username_from_profile_url( 'stripchat', 'https://stripchat.com/sweetmodel' )
        );
    }
}
