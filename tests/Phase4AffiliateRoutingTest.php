<?php
/**
 * TMW SEO Engine — Phase 4 Affiliate Routing + Config-Model Alignment Tests
 *
 * Replaces the previous version which injected settings via raw update_option(),
 * bypassing the admin sanitizer. All tests here go through sanitize_affiliate_networks()
 * or sanitize_platform_affiliate_settings() as the real admin form submit would.
 *
 * Covers:
 *   A. Config-model alignment (the Phase 4 fix)
 *   B. build_affiliate_url_for_target two-source lookup
 *   C. sanitize_and_validate_entry new fields
 *   D. get_routed_url routing decisions
 *   E. Schema sameAs uses url not get_routed_url
 *   F. Trust boundary
 *
 * @package TMWSEO\Engine\Tests
 * @since   Phase 4 + config alignment
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Tests;

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Model\VerifiedLinks;
use TMWSEO\Engine\Platform\AffiliateLinkBuilder;
use TMWSEO\Engine\Admin\Admin;

// ── Helpers ───────────────────────────────────────────────────────────────────

function p4_sanitize_entry( array $raw ): array|false {
    $r = new \ReflectionClass( VerifiedLinks::class );
    $m = $r->getMethod( 'sanitize_and_validate_entry' );
    $m->setAccessible( true );
    return $m->invoke( null, $raw );
}

function p4_valid_entry( array $overrides = [] ): array {
    return array_merge( [
        'url'          => 'https://fansly.com/aishadupont/posts',
        'type'         => 'fansly',
        'is_active'    => '1',
        'promoted_from'=> 'research',
    ], $overrides );
}

/**
 * Inject tmwseo_affiliate_networks through the real sanitize_affiliate_networks path.
 */
function p4_set_networks( array $raw ): void {
    $r = new \ReflectionClass( Admin::class );
    $m = $r->getMethod( 'sanitize_affiliate_networks' );
    $m->setAccessible( true );
    update_option( 'tmwseo_affiliate_networks', $m->invoke( null, $raw ) );
}

/**
 * Inject tmwseo_platform_affiliate_settings through the real sanitizer.
 */
function p4_set_platform_settings( array $raw ): void {
    $r = new \ReflectionClass( Admin::class );
    $m = $r->getMethod( 'sanitize_platform_affiliate_settings' );
    $m->setAccessible( true );
    update_option( 'tmwseo_platform_affiliate_settings', $m->invoke( null, $raw ) );
}

function p4_clear(): void {
    delete_option( 'tmwseo_affiliate_networks' );
    delete_option( 'tmwseo_platform_affiliate_settings' );
}

// ── Test suite ────────────────────────────────────────────────────────────────

class Phase4AffiliateRoutingTest extends TestCase {

    protected function setUp(): void    { p4_clear(); }
    protected function tearDown(): void { p4_clear(); }

    // ── A. Config-model alignment ─────────────────────────────────────────────

    public function test_sanitize_networks_strips_empty_slug_rows(): void {
        $r = new \ReflectionClass( Admin::class );
        $m = $r->getMethod( 'sanitize_affiliate_networks' );
        $m->setAccessible( true );
        $result = $m->invoke( null, [
            ['slug' => '', 'label' => 'Empty', 'enabled' => '1', 'template' => 'https://x.com'],
        ] );
        $this->assertEmpty( $result );
    }

    public function test_sanitize_networks_preserves_valid_row(): void {
        $r = new \ReflectionClass( Admin::class );
        $m = $r->getMethod( 'sanitize_affiliate_networks' );
        $m->setAccessible( true );
        $result = $m->invoke( null, [
            'crack_revenue' => [
                'slug' => 'crack_revenue', 'label' => 'Crack Revenue',
                'enabled' => '1',
                'template' => 'https://go.crackrevenue.com/?url={encoded_profile_url}',
                'campaign' => 'tmw', 'source' => 'dir', 'subaffid' => '',
            ],
        ] );
        $this->assertArrayHasKey( 'crack_revenue', $result );
        $this->assertSame( 1, $result['crack_revenue']['enabled'] );
        $this->assertSame( 'tmw', $result['crack_revenue']['campaign'] );
    }

    public function test_sanitize_platform_settings_strips_non_registry_keys(): void {
        $r = new \ReflectionClass( Admin::class );
        $m = $r->getMethod( 'sanitize_platform_affiliate_settings' );
        $m->setAccessible( true );
        $result = $m->invoke( null, [
            'crack_revenue' => ['enabled' => '1', 'template' => 'https://aff.example.com'],
        ] );
        $this->assertArrayNotHasKey( 'crack_revenue', $result,
            'crack_revenue is not a PlatformRegistry slug — must be stripped from platform settings' );
    }

    public function test_get_configurable_keys_returns_enabled_network_entries(): void {
        p4_set_networks( [
            'crack_revenue' => [
                'slug' => 'crack_revenue', 'label' => 'Crack Revenue',
                'enabled' => '1', 'template' => 'https://go.crackrevenue.com/?url={encoded_profile_url}',
            ],
        ] );
        $keys = AffiliateLinkBuilder::get_configurable_network_keys();
        $this->assertArrayHasKey( 'crack_revenue', $keys );
        $this->assertSame( 'Crack Revenue', $keys['crack_revenue'] );
    }

    public function test_get_configurable_keys_excludes_disabled_entries(): void {
        p4_set_networks( [
            'disabled_net' => [
                'slug' => 'disabled_net', 'label' => 'Disabled',
                'enabled' => '0', 'template' => 'https://example.com',
            ],
        ] );
        $this->assertArrayNotHasKey( 'disabled_net', AffiliateLinkBuilder::get_configurable_network_keys() );
    }

    public function test_get_configurable_keys_merges_both_sources(): void {
        p4_set_networks( [
            'crack_revenue' => [
                'slug' => 'crack_revenue', 'label' => 'Crack Revenue',
                'enabled' => '1', 'template' => 'https://go.crackrevenue.com/?url={encoded_profile_url}',
            ],
        ] );
        p4_set_platform_settings( [
            'fansly' => ['enabled' => '1', 'template' => 'https://fansly.aff.example.com/?url={encoded_profile_url}'],
        ] );
        $keys = AffiliateLinkBuilder::get_configurable_network_keys();
        $this->assertArrayHasKey( 'crack_revenue', $keys );
        $this->assertArrayHasKey( 'fansly', $keys );
    }

    public function test_get_configurable_keys_is_alpha_sorted(): void {
        p4_set_networks( [
            'zzz_net' => ['slug' => 'zzz_net', 'label' => 'ZZZ', 'enabled' => '1', 'template' => 'https://zzz.com'],
            'aaa_net' => ['slug' => 'aaa_net', 'label' => 'AAA', 'enabled' => '1', 'template' => 'https://aaa.com'],
        ] );
        $labels = array_values( AffiliateLinkBuilder::get_configurable_network_keys() );
        $sorted = $labels;
        sort( $sorted );
        $this->assertSame( $sorted, $labels );
    }

    // ── B. build_affiliate_url_for_target two-source lookup ───────────────────

    public function test_empty_target_url_returns_empty(): void {
        $this->assertSame( '', AffiliateLinkBuilder::build_affiliate_url_for_target( '', 'some_net' ) );
    }

    public function test_empty_network_key_returns_target_url(): void {
        $url = 'https://fansly.com/aishadupont/posts';
        $this->assertSame( $url, AffiliateLinkBuilder::build_affiliate_url_for_target( $url, '' ) );
    }

    public function test_unconfigured_key_returns_target_url(): void {
        $url = 'https://fansly.com/aishadupont/posts';
        $this->assertSame( $url, AffiliateLinkBuilder::build_affiliate_url_for_target( $url, 'no_such_key_xyz' ) );
    }

    public function test_disabled_network_returns_target_url(): void {
        p4_set_networks( [
            'testnet' => ['slug' => 'testnet', 'label' => 'Test', 'enabled' => '0', 'template' => 'https://aff.example.com/?url={encoded_profile_url}'],
        ] );
        $url = 'https://fansly.com/aishadupont/posts';
        $this->assertSame( $url, AffiliateLinkBuilder::build_affiliate_url_for_target( $url, 'testnet' ) );
    }

    public function test_enabled_network_key_builds_routed_url(): void {
        p4_set_networks( [
            'testnet' => [
                'slug' => 'testnet', 'label' => 'Test', 'enabled' => '1',
                'template' => 'https://aff.example.com/go?dest={encoded_profile_url}&cmp=tmw',
            ],
        ] );
        $target = 'https://fansly.com/aishadupont/posts';
        $result = AffiliateLinkBuilder::build_affiliate_url_for_target( $target, 'testnet' );
        $this->assertStringContainsString( 'aff.example.com', $result );
        $this->assertStringContainsString( rawurlencode( $target ), $result );
    }

    public function test_enabled_platform_slug_builds_routed_url(): void {
        p4_set_platform_settings( [
            'fansly' => ['enabled' => '1', 'template' => 'https://fanslyaff.example.com/?url={encoded_profile_url}'],
        ] );
        $target = 'https://fansly.com/aishadupont/posts';
        $result = AffiliateLinkBuilder::build_affiliate_url_for_target( $target, 'fansly' );
        $this->assertStringContainsString( 'fanslyaff.example.com', $result );
    }

    public function test_network_settings_take_precedence_over_platform_settings(): void {
        p4_set_networks( [
            'fansly' => [
                'slug' => 'fansly', 'label' => 'Fansly override', 'enabled' => '1',
                'template' => 'https://NETWORK.example.com/?url={encoded_profile_url}',
            ],
        ] );
        p4_set_platform_settings( [
            'fansly' => ['enabled' => '1', 'template' => 'https://PLATFORM.example.com/?url={encoded_profile_url}'],
        ] );
        $result = AffiliateLinkBuilder::build_affiliate_url_for_target( 'https://fansly.com/test', 'fansly' );
        $this->assertStringContainsString( 'NETWORK.example.com', $result );
        $this->assertStringNotContainsString( 'PLATFORM.example.com', $result );
    }

    // ── C. sanitize_and_validate_entry new fields ─────────────────────────────

    public function test_source_url_preserved(): void {
        $entry = p4_sanitize_entry( p4_valid_entry( ['source_url' => 'https://www.pornhub.com/model/aishadupont'] ) );
        $this->assertIsArray( $entry );
        $this->assertSame( 'https://www.pornhub.com/model/aishadupont', $entry['source_url'] ?? '' );
    }

    public function test_source_url_absent_when_empty(): void {
        $entry = p4_sanitize_entry( p4_valid_entry( ['source_url' => ''] ) );
        $this->assertIsArray( $entry );
        $this->assertArrayNotHasKey( 'source_url', $entry );
    }

    public function test_outbound_type_valid_values_preserved(): void {
        foreach ( ['direct_profile', 'personal_site', 'website', 'social'] as $ot ) {
            $entry = p4_sanitize_entry( p4_valid_entry( ['outbound_type' => $ot] ) );
            $this->assertIsArray( $entry );
            $this->assertSame( $ot, $entry['outbound_type'] ?? "missing:$ot" );
        }
    }

    public function test_outbound_type_invalid_absent(): void {
        $entry = p4_sanitize_entry( p4_valid_entry( ['outbound_type' => 'invalid'] ) );
        $this->assertIsArray( $entry );
        $this->assertArrayNotHasKey( 'outbound_type', $entry );
    }

    public function test_use_affiliate_true_stored(): void {
        $entry = p4_sanitize_entry( p4_valid_entry( ['use_affiliate' => '1', 'affiliate_network' => 'testnet'] ) );
        $this->assertIsArray( $entry );
        $this->assertTrue( (bool) ( $entry['use_affiliate'] ?? false ) );
        $this->assertSame( 'testnet', $entry['affiliate_network'] ?? '' );
    }

    public function test_use_affiliate_false_not_stored(): void {
        $entry = p4_sanitize_entry( p4_valid_entry( ['use_affiliate' => '0'] ) );
        $this->assertIsArray( $entry );
        $this->assertArrayNotHasKey( 'use_affiliate', $entry );
    }

    public function test_affiliate_network_preserved_when_routing_off(): void {
        // Preserve key for re-enable without retyping.
        $entry = p4_sanitize_entry( p4_valid_entry( ['use_affiliate' => '0', 'affiliate_network' => 'crack_revenue'] ) );
        $this->assertIsArray( $entry );
        $this->assertSame( 'crack_revenue', $entry['affiliate_network'] ?? '' );
    }

    public function test_invalid_url_rejects(): void {
        $this->assertFalse( p4_sanitize_entry( ['url' => 'not-a-url', 'type' => 'fansly'] ) );
    }

    public function test_invalid_type_rejects(): void {
        $this->assertFalse( p4_sanitize_entry( ['url' => 'https://fansly.com/x', 'type' => 'not_real'] ) );
    }

    // ── D. get_routed_url ─────────────────────────────────────────────────────

    public function test_get_routed_no_affiliate_flag(): void {
        $link = ['url' => 'https://fansly.com/aishadupont/posts'];
        $this->assertSame( $link['url'], VerifiedLinks::get_routed_url( $link ) );
    }

    public function test_get_routed_empty_network(): void {
        $link = ['url' => 'https://fansly.com/aishadupont/posts', 'use_affiliate' => true, 'affiliate_network' => ''];
        $this->assertSame( $link['url'], VerifiedLinks::get_routed_url( $link ) );
    }

    public function test_get_routed_unconfigured_network_falls_back(): void {
        $link = ['url' => 'https://fansly.com/aishadupont/posts', 'use_affiliate' => true, 'affiliate_network' => 'not_configured_xyz'];
        $this->assertSame( $link['url'], VerifiedLinks::get_routed_url( $link ) );
    }

    public function test_get_routed_enabled_network_routes(): void {
        p4_set_networks( [
            'testnet' => [
                'slug' => 'testnet', 'label' => 'Test', 'enabled' => '1',
                'template' => 'https://aff.example.com/click?dest={encoded_profile_url}',
            ],
        ] );
        $target = 'https://fansly.com/aishadupont/posts';
        $routed = VerifiedLinks::get_routed_url( ['url' => $target, 'use_affiliate' => true, 'affiliate_network' => 'testnet'] );
        $this->assertStringContainsString( 'aff.example.com', $routed );
    }

    // ── E. Schema sameAs uses url not get_routed_url ──────────────────────────

    public function test_schema_urls_does_not_call_get_routed_url(): void {
        $src = file_get_contents( ( new \ReflectionClass( VerifiedLinks::class ) )->getFileName() );
        $this->assertIsString( $src );
        if ( preg_match( '/public static function get_schema_urls.*?(?=\n\s+\/\/\s+──)/s', $src, $m ) ) {
            $this->assertStringNotContainsString( 'get_routed_url', $m[0],
                'get_schema_urls must not call get_routed_url — sameAs always uses plain outbound url' );
        }
    }

    // ── F. Trust boundary ─────────────────────────────────────────────────────

    public function test_source_url_never_in_routed_output(): void {
        $link = ['url' => 'https://aishadupont.com/about', 'source_url' => 'https://www.pornhub.com/model/aishadupont', 'use_affiliate' => false];
        $this->assertSame( 'https://aishadupont.com/about', VerifiedLinks::get_routed_url( $link ) );
        $this->assertStringNotContainsString( 'pornhub', VerifiedLinks::get_routed_url( $link ) );
    }

    public function test_network_key_present_but_flag_off_does_not_route(): void {
        p4_set_networks( [
            'testnet' => ['slug' => 'testnet', 'label' => 'Test', 'enabled' => '1', 'template' => 'https://aff.example.com/?url={encoded_profile_url}'],
        ] );
        $link = ['url' => 'https://fansly.com/aishadupont/posts', 'affiliate_network' => 'testnet'];
        $this->assertSame( $link['url'], VerifiedLinks::get_routed_url( $link ) );
    }
}
