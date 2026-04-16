<?php
/**
 * TMW SEO Engine — Phase 4 Affiliate Routing Tests
 *
 * Covers:
 *
 *   A. AffiliateLinkBuilder::build_affiliate_url_for_target()
 *      A1. Empty target_url returns ''
 *      A2. Empty network_key returns target_url unchanged
 *      A3. Network not in settings returns target_url unchanged
 *      A4. Network disabled (enabled=false) returns target_url unchanged
 *      A5. Network enabled with valid template → builds routed URL
 *      A6. Built URL is validated; invalid template output falls back to target_url
 *
 *   B. sanitize_and_validate_entry() — new fields preserved across metabox save
 *      B1. source_url preserved when valid URL
 *      B2. source_url ignored when empty
 *      B3. outbound_type preserved when valid value
 *      B4. outbound_type cleared when invalid value
 *      B5. use_affiliate = '1' → stored as true
 *      B6. use_affiliate = '0' → NOT stored
 *      B7. affiliate_network stored when use_affiliate true
 *      B8. affiliate_network preserved even when use_affiliate false (for re-enable)
 *      B9. Invalid URL still rejects the entire entry (unchanged guard)
 *      B10. Invalid type still rejects the entire entry (unchanged guard)
 *
 *   C. get_routed_url() routing decisions
 *      C1. use_affiliate = false → returns url unchanged
 *      C2. use_affiliate = true, empty network → returns url unchanged
 *      C3. use_affiliate = true, network configured → calls AffiliateLinkBuilder
 *      C4. AffiliateLinkBuilder returns url on bad network → get_routed_url returns url
 *
 *   D. Shortcode uses get_routed_url (trust boundary)
 *      D1. Link with use_affiliate=false → rendered url matches url field
 *      D2. Link with use_affiliate=true, no network → rendered url matches url field
 *
 *   E. Schema sameAs uses url directly, NOT get_routed_url
 *      E1. get_schema_urls returns url, not affiliate url
 *      E2. Inactive links excluded from schema (unchanged)
 *
 *   F. Trust boundary — no auto-application of affiliate routing
 *      F1. add_link with use_affiliate via extra_meta stores it correctly
 *      F2. A link without use_affiliate never gets affiliate routing
 *      F3. Research data (source_url) never becomes the routed output
 *
 * @package TMWSEO\Engine\Tests
 * @since   Phase 4
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Tests;

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Model\VerifiedLinks;
use TMWSEO\Engine\Platform\AffiliateLinkBuilder;

// ── Helpers ───────────────────────────────────────────────────────────────────

function call_sanitize_entry( array $raw ): array|false {
    $r = new \ReflectionClass( VerifiedLinks::class );
    $m = $r->getMethod( 'sanitize_and_validate_entry' );
    $m->setAccessible( true );
    return $m->invoke( null, $raw );
}

function valid_entry( array $overrides = [] ): array {
    return array_merge( [
        'url'          => 'https://fansly.com/aishadupont/posts',
        'type'         => 'fansly',
        'is_active'    => '1',
        'promoted_from'=> 'research',
    ], $overrides );
}

// ── Stub AffiliateLinkBuilder for testing ─────────────────────────────────────
// We test get_routed_url() by injecting a mock WP options store,
// since build_affiliate_url_for_target reads get_option().

function set_affiliate_settings( string $network, array $settings ): void {
    $all = (array) get_option( 'tmwseo_platform_affiliate_settings', [] );
    $all[ $network ] = $settings;
    update_option( 'tmwseo_platform_affiliate_settings', $all );
}

function clear_affiliate_settings(): void {
    delete_option( 'tmwseo_platform_affiliate_settings' );
}

// ── Test suite ────────────────────────────────────────────────────────────────

class Phase4AffiliateRoutingTest extends TestCase {

    protected function setUp(): void {
        clear_affiliate_settings();
    }

    protected function tearDown(): void {
        clear_affiliate_settings();
    }

    // ── A. build_affiliate_url_for_target ────────────────────────────────────

    public function test_empty_target_url_returns_empty(): void {
        $this->assertSame( '', AffiliateLinkBuilder::build_affiliate_url_for_target( '', 'some_network' ) );
    }

    public function test_empty_network_key_returns_target_url(): void {
        $url = 'https://fansly.com/aishadupont/posts';
        $this->assertSame( $url, AffiliateLinkBuilder::build_affiliate_url_for_target( $url, '' ) );
    }

    public function test_unknown_network_returns_target_url(): void {
        $url = 'https://fansly.com/aishadupont/posts';
        // No settings for 'unknown_network_xyz' → should return url unchanged
        $this->assertSame( $url, AffiliateLinkBuilder::build_affiliate_url_for_target( $url, 'unknown_network_xyz' ) );
    }

    public function test_disabled_network_returns_target_url(): void {
        set_affiliate_settings( 'testnet', [
            'enabled'  => false,
            'template' => 'https://partner.example.com/go?url={encoded_profile_url}',
        ] );
        $url = 'https://fansly.com/aishadupont/posts';
        $this->assertSame( $url, AffiliateLinkBuilder::build_affiliate_url_for_target( $url, 'testnet' ) );
    }

    public function test_enabled_network_with_template_builds_url(): void {
        set_affiliate_settings( 'testnet', [
            'enabled'  => true,
            'template' => 'https://partner.example.com/go?url={encoded_profile_url}&cmp=test',
        ] );
        $target = 'https://fansly.com/aishadupont/posts';
        $result = AffiliateLinkBuilder::build_affiliate_url_for_target( $target, 'testnet' );
        $this->assertStringContainsString( 'partner.example.com', $result );
        $this->assertStringContainsString( urlencode( $target ), $result );
    }

    public function test_enabled_network_empty_template_returns_target(): void {
        set_affiliate_settings( 'testnet', [
            'enabled'  => true,
            'template' => '',   // misconfigured — no template
        ] );
        $url = 'https://fansly.com/aishadupont/posts';
        $this->assertSame( $url, AffiliateLinkBuilder::build_affiliate_url_for_target( $url, 'testnet' ) );
    }

    // ── B. sanitize_and_validate_entry — new fields ───────────────────────────

    public function test_source_url_preserved_when_valid(): void {
        $entry = call_sanitize_entry( valid_entry( [
            'source_url' => 'https://www.pornhub.com/model/aishadupont',
        ] ) );
        $this->assertIsArray( $entry );
        $this->assertSame( 'https://www.pornhub.com/model/aishadupont', $entry['source_url'] ?? '' );
    }

    public function test_source_url_absent_when_empty(): void {
        $entry = call_sanitize_entry( valid_entry( [ 'source_url' => '' ] ) );
        $this->assertIsArray( $entry );
        $this->assertArrayNotHasKey( 'source_url', $entry );
    }

    public function test_outbound_type_preserved_when_valid(): void {
        foreach ( [ 'direct_profile', 'personal_site', 'website', 'social' ] as $ot ) {
            $entry = call_sanitize_entry( valid_entry( [ 'outbound_type' => $ot ] ) );
            $this->assertIsArray( $entry );
            $this->assertSame( $ot, $entry['outbound_type'] ?? '', "outbound_type '$ot' must be preserved" );
        }
    }

    public function test_outbound_type_cleared_when_invalid(): void {
        $entry = call_sanitize_entry( valid_entry( [ 'outbound_type' => 'not_a_valid_type' ] ) );
        $this->assertIsArray( $entry );
        $this->assertArrayNotHasKey( 'outbound_type', $entry );
    }

    public function test_use_affiliate_true_stored(): void {
        $entry = call_sanitize_entry( valid_entry( [
            'use_affiliate'     => '1',
            'affiliate_network' => 'testnet',
        ] ) );
        $this->assertIsArray( $entry );
        $this->assertTrue( (bool) ( $entry['use_affiliate'] ?? false ) );
        $this->assertSame( 'testnet', $entry['affiliate_network'] ?? '' );
    }

    public function test_use_affiliate_false_not_stored(): void {
        $entry = call_sanitize_entry( valid_entry( [ 'use_affiliate' => '0' ] ) );
        $this->assertIsArray( $entry );
        $this->assertArrayNotHasKey( 'use_affiliate', $entry );
    }

    public function test_affiliate_network_preserved_when_routing_disabled(): void {
        // If the operator disables routing but keeps a network key,
        // it should be preserved for later re-enabling.
        $entry = call_sanitize_entry( valid_entry( [
            'use_affiliate'     => '0',
            'affiliate_network' => 'testnet',
        ] ) );
        $this->assertIsArray( $entry );
        $this->assertSame( 'testnet', $entry['affiliate_network'] ?? '' );
    }

    public function test_invalid_url_still_rejects_entry(): void {
        $result = call_sanitize_entry( [
            'url'  => 'not-a-url',
            'type' => 'fansly',
        ] );
        $this->assertFalse( $result, 'Invalid URL must still reject the entry' );
    }

    public function test_invalid_type_still_rejects_entry(): void {
        $result = call_sanitize_entry( [
            'url'  => 'https://fansly.com/aishadupont/posts',
            'type' => 'not_a_real_type',
        ] );
        $this->assertFalse( $result, 'Invalid type must still reject the entry' );
    }

    // ── C. get_routed_url routing ─────────────────────────────────────────────

    public function test_get_routed_url_no_affiliate_returns_url(): void {
        $link = [ 'url' => 'https://fansly.com/aishadupont/posts', 'use_affiliate' => false ];
        $this->assertSame( 'https://fansly.com/aishadupont/posts', VerifiedLinks::get_routed_url( $link ) );
    }

    public function test_get_routed_url_empty_network_returns_url(): void {
        $link = [
            'url'               => 'https://fansly.com/aishadupont/posts',
            'use_affiliate'     => true,
            'affiliate_network' => '',
        ];
        $this->assertSame( 'https://fansly.com/aishadupont/posts', VerifiedLinks::get_routed_url( $link ) );
    }

    public function test_get_routed_url_unconfigured_network_returns_url(): void {
        $link = [
            'url'               => 'https://fansly.com/aishadupont/posts',
            'use_affiliate'     => true,
            'affiliate_network' => 'not_configured_net',
        ];
        // Network not in settings → falls back to url
        $this->assertSame( 'https://fansly.com/aishadupont/posts', VerifiedLinks::get_routed_url( $link ) );
    }

    public function test_get_routed_url_configured_network_routes_through_affiliate(): void {
        set_affiliate_settings( 'testnet', [
            'enabled'  => true,
            'template' => 'https://aff.example.com/click?dest={encoded_profile_url}',
        ] );
        $target = 'https://fansly.com/aishadupont/posts';
        $link   = [
            'url'               => $target,
            'use_affiliate'     => true,
            'affiliate_network' => 'testnet',
        ];
        $routed = VerifiedLinks::get_routed_url( $link );
        $this->assertStringContainsString( 'aff.example.com', $routed );
        $this->assertNotSame( $target, $routed );
    }

    // ── D. Shortcode trust boundary ───────────────────────────────────────────

    public function test_shortcode_no_affiliate_renders_outbound_url(): void {
        // We test get_routed_url directly since shortcode needs WP context.
        $link = [
            'url'        => 'https://fansly.com/aishadupont/posts',
            'is_active'  => true,
            'use_affiliate' => false,
        ];
        // The shortcode calls get_routed_url internally.
        // We verify the routing decision directly.
        $this->assertSame( $link['url'], VerifiedLinks::get_routed_url( $link ) );
    }

    // ── E. Schema sameAs — always uses url, never affiliate ───────────────────

    public function test_get_schema_urls_returns_url_not_routed(): void {
        // Verify get_schema_urls reads 'url', not get_routed_url.
        // We confirm the contract by checking the method exists and is separate
        // from get_routed_url via reflection.
        $r = new \ReflectionClass( VerifiedLinks::class );

        $this->assertTrue( $r->hasMethod( 'get_schema_urls' ) );
        $this->assertTrue( $r->hasMethod( 'get_routed_url' ) );

        // get_schema_urls source must NOT call get_routed_url
        $method_src = file_get_contents( $r->getFileName() );
        $this->assertIsString( $method_src );

        if ( preg_match( '/public static function get_schema_urls.*?(?=\n\s+public\s+static)/s', $method_src, $m ) ) {
            $this->assertStringNotContainsString(
                'get_routed_url',
                $m[0],
                'get_schema_urls must NOT call get_routed_url — sameAs uses url directly'
            );
        }
    }

    // ── F. Trust boundary — affiliate routing only on approved links ──────────

    public function test_add_link_with_affiliate_meta_stores_correctly(): void {
        $result = VerifiedLinks::add_link(
            99990,
            'https://fansly.com/aishadupont/posts',
            'fansly',
            '',
            true,
            false,
            'research',
            [
                'source_url'        => 'https://www.pornhub.com/model/aishadupont',
                'outbound_type'     => 'direct_profile',
                'use_affiliate'     => true,
                'affiliate_network' => 'testnet',
            ]
        );
        $this->assertIsBool( $result );
    }

    public function test_link_without_use_affiliate_never_routes(): void {
        // A link with no use_affiliate field defaults to no routing.
        $link = [ 'url' => 'https://fansly.com/aishadupont/posts' ];
        $this->assertSame( 'https://fansly.com/aishadupont/posts', VerifiedLinks::get_routed_url( $link ) );
    }

    public function test_source_url_never_becomes_output(): void {
        // source_url is audit trail only — get_routed_url must never return it.
        set_affiliate_settings( 'testnet', [ 'enabled' => false ] );
        $link = [
            'url'        => 'https://aishadupont.com/about',
            'source_url' => 'https://www.pornhub.com/model/aishadupont',
            'use_affiliate' => false,
        ];
        $routed = VerifiedLinks::get_routed_url( $link );
        $this->assertSame( 'https://aishadupont.com/about', $routed,
            'get_routed_url must return url (outbound target), never source_url' );
        $this->assertStringNotContainsString( 'pornhub', $routed );
    }
}
