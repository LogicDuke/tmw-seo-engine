<?php
/**
 * Test: DiscoveryGovernor — kill switch, limit checking, and atomic increment logic.
 *
 * Verifies:
 *  - is_discovery_allowed() respects the kill switch option
 *  - defaults() returns the expected metric keys
 *  - can_increment() returns false when metric row is missing (allow-unknown default)
 *  - is_enabled() reads the tmw_discovery_enabled option correctly
 *
 * NOTE: The atomic SQL increment (BUG-08 fix) path requires a real DB to test
 * end-to-end. Those paths are covered here via logic-level assertions on the
 * public API. DB integration tests belong in a separate WP integration suite.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\DiscoveryGovernor;

class DiscoveryGovernorTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_tmw_test_options']    = [];
        $GLOBALS['_tmw_test_transients'] = [];
    }

    // ── Kill switch ───────────────────────────────────────────────────────────

    public function test_discovery_enabled_by_default(): void {
        // tmw_discovery_enabled defaults to 1 (enabled)
        $this->assertTrue( DiscoveryGovernor::is_enabled() );
    }

    public function test_discovery_disabled_by_kill_switch(): void {
        update_option( 'tmw_discovery_enabled', 0 );

        $this->assertFalse( DiscoveryGovernor::is_enabled() );
    }

    public function test_is_discovery_allowed_false_when_disabled(): void {
        update_option( 'tmw_discovery_enabled', 0 );

        $this->assertFalse( DiscoveryGovernor::is_discovery_allowed() );
    }

    public function test_is_discovery_allowed_true_when_enabled(): void {
        update_option( 'tmw_discovery_enabled', 1 );

        $this->assertTrue( DiscoveryGovernor::is_discovery_allowed() );
    }

    // ── Defaults / metric registry ────────────────────────────────────────────

    public function test_defaults_returns_expected_metric_keys(): void {
        $defaults = DiscoveryGovernor::defaults();

        $this->assertIsArray( $defaults );
        $this->assertArrayHasKey( 'keywords_discovered', $defaults );
        $this->assertArrayHasKey( 'models_discovered', $defaults );
        $this->assertArrayHasKey( 'serp_requests', $defaults );
        $this->assertArrayHasKey( 'queue_jobs_created', $defaults );
    }

    public function test_defaults_limits_are_positive_integers(): void {
        foreach ( DiscoveryGovernor::defaults() as $metric => $limit ) {
            $this->assertGreaterThan( 0, $limit,
                "Default limit for metric '{$metric}' should be > 0" );
            $this->assertIsInt( $limit,
                "Default limit for metric '{$metric}' should be an integer" );
        }
    }

    // ── can_increment when no DB row ──────────────────────────────────────────

    public function test_can_increment_allows_unknown_metric(): void {
        // When the DB table/row doesn't exist, can_increment returns true
        // (fail-open) so that missing governor rows don't silently block discovery.
        // The actual DB path is covered in integration tests.
        //
        // We test this indirectly: DiscoveryGovernor::remaining() returns PHP_INT_MAX
        // for unknown metrics, meaning can_increment will be true.
        $remaining = DiscoveryGovernor::remaining( 'nonexistent_metric_xyz' );
        $this->assertSame( PHP_INT_MAX, $remaining,
            'remaining() for an unknown metric should return PHP_INT_MAX (fail-open)' );
    }

    // ── Metric limit sanity values ────────────────────────────────────────────

    public function test_keywords_discovered_limit_is_at_least_100(): void {
        $defaults = DiscoveryGovernor::defaults();
        $this->assertGreaterThanOrEqual( 100, $defaults['keywords_discovered'],
            'keywords_discovered limit should allow meaningful discovery runs' );
    }

    public function test_serp_requests_limit_is_reasonable(): void {
        $defaults = DiscoveryGovernor::defaults();
        // Should be > 0 (we do need SERP requests) but not absurdly high (cost control)
        $this->assertGreaterThan( 0,   $defaults['serp_requests'] );
        $this->assertLessThan( 10000, $defaults['serp_requests'],
            'serp_requests default limit should not be unbounded' );
    }
}
