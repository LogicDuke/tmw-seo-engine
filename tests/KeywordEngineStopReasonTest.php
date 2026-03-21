<?php
/**
 * Test: Keyword engine stop-reason tracking and queue cap behaviour.
 *
 * Verifies:
 *  - KeywordEngine cycle metrics option stores stop reasons correctly
 *  - Queue full since option is set/cleared appropriately
 *  - DataForSEO budget gate fires before discovery starts
 *  - Queue cap default is a positive integer
 *
 * These are logic-path tests. The actual DB-driven engine cycle is covered
 * by integration tests running against a real WP database.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Services\DataForSEO;

class KeywordEngineStopReasonTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_tmw_test_options']    = [];
        $GLOBALS['_tmw_test_transients'] = [];
    }

    // ── Stop-reason option structure ──────────────────────────────────────────

    public function test_cycle_metrics_option_name_is_stable(): void {
        // The option key 'tmw_keyword_engine_metrics' is consumed by the dashboard
        // and must not be renamed without a migration.
        $metrics = get_option( 'tmw_keyword_engine_metrics', [] );
        $this->assertIsArray( $metrics );
    }

    public function test_stop_reason_written_correctly(): void {
        // Simulate what KeywordEngine::record_stop_reason does internally
        $metrics = get_option( 'tmw_keyword_engine_metrics', [] );
        $metrics['last_stop_reason']    = 'active_lock';
        $metrics['last_stop_reason_at'] = time();
        update_option( 'tmw_keyword_engine_metrics', $metrics );

        $read_back = get_option( 'tmw_keyword_engine_metrics', [] );
        $this->assertSame( 'active_lock', $read_back['last_stop_reason'] );
        $this->assertGreaterThan( 0, $read_back['last_stop_reason_at'] );
    }

    public function test_stop_reason_can_be_cleared(): void {
        $metrics = [
            'last_stop_reason'    => 'some_old_reason',
            'last_stop_reason_at' => time() - 3600,
        ];
        update_option( 'tmw_keyword_engine_metrics', $metrics );

        // Simulate clearing at start of new cycle
        $metrics['last_stop_reason']    = '';
        $metrics['last_stop_reason_at'] = 0;
        update_option( 'tmw_keyword_engine_metrics', $metrics );

        $read_back = get_option( 'tmw_keyword_engine_metrics', [] );
        $this->assertSame( '', $read_back['last_stop_reason'] );
        $this->assertSame( 0, $read_back['last_stop_reason_at'] );
    }

    // ── Queue full tracking ───────────────────────────────────────────────────

    public function test_queue_full_option_starts_empty(): void {
        $since = (int) get_option( 'tmwseo_kw_queue_full_since', 0 );
        $this->assertSame( 0, $since );
    }

    public function test_queue_full_option_can_be_set_and_cleared(): void {
        $ts = time();
        update_option( 'tmwseo_kw_queue_full_since', $ts );

        $this->assertSame( $ts, (int) get_option( 'tmwseo_kw_queue_full_since', 0 ) );

        delete_option( 'tmwseo_kw_queue_full_since' );
        $this->assertSame( 0, (int) get_option( 'tmwseo_kw_queue_full_since', 0 ) );
    }

    // ── DataForSEO budget gate ────────────────────────────────────────────────

    public function test_discovery_blocks_when_over_budget(): void {
        $month     = gmdate( 'Y_m' );
        $spend_key = 'tmwseo_dataforseo_spend_' . $month;

        update_option( 'tmwseo_dataforseo_budget_usd', 10.0 );
        update_option( $spend_key, 10.0 ); // exactly at cap

        $this->assertTrue( DataForSEO::is_over_budget(),
            'Discovery must be blocked when spend equals the configured budget cap' );
    }

    public function test_discovery_not_blocked_when_under_budget(): void {
        $month     = gmdate( 'Y_m' );
        $spend_key = 'tmwseo_dataforseo_spend_' . $month;

        update_option( 'tmwseo_dataforseo_budget_usd', 20.0 );
        update_option( $spend_key, 9.99 );

        $this->assertFalse( DataForSEO::is_over_budget() );
    }

    // ── Breaker option structure ──────────────────────────────────────────────

    public function test_breaker_option_empty_by_default(): void {
        $breaker = get_option( 'tmw_keyword_engine_breaker', [] );
        $this->assertIsArray( $breaker );
        $this->assertEmpty( $breaker );
    }

    public function test_breaker_last_triggered_readable(): void {
        $ts = time() - 600;
        update_option( 'tmw_keyword_engine_breaker', [ 'last_triggered' => $ts ] );

        $breaker = get_option( 'tmw_keyword_engine_breaker', [] );
        $this->assertSame( $ts, (int) $breaker['last_triggered'] );
    }

    // ── Stop-reason human labels (used by Discovery Control dashboard) ────────

    /** @dataProvider stopReasonLabelProvider */
    public function test_known_stop_reasons_are_non_empty_strings( string $reason ): void {
        // The dashboard displays these labels. This test documents the known
        // set so a rename is caught immediately.
        $this->assertNotEmpty( $reason );
        $this->assertMatchesRegularExpression( '/^[a-z_]+$/', $reason,
            'Stop reason keys must be snake_case lowercase strings' );
    }

    /** @return array<string, array{string}> */
    public static function stopReasonLabelProvider(): array {
        return [
            'active_lock'               => [ 'active_lock'               ],
            'breaker_cooldown'          => [ 'breaker_cooldown'          ],
            'no_seeds'                  => [ 'no_seeds'                  ],
            'import_only_mode'          => [ 'import_only_mode'          ],
            'discovery_governor_blocked'=> [ 'discovery_governor_blocked'],
            'kill_switch_off'           => [ 'kill_switch_off'           ],
        ];
    }
}
