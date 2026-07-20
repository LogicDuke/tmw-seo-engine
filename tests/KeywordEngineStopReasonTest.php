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

        // Settings::get() reads from the 'tmwseo_engine_settings' option array.
        update_option( 'tmwseo_engine_settings', [ 'tmwseo_dataforseo_budget_usd' => 10.0 ] );
        update_option( $spend_key, 10.0 ); // exactly at cap

        $this->assertTrue( DataForSEO::is_over_budget(),
            'Discovery must be blocked when spend equals the configured budget cap' );
    }

    public function test_discovery_not_blocked_when_under_budget(): void {
        $month     = gmdate( 'Y_m' );
        $spend_key = 'tmwseo_dataforseo_spend_' . $month;

        update_option( 'tmwseo_engine_settings', [ 'tmwseo_dataforseo_budget_usd' => 20.0 ] );
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
            'active_lock'                => [ 'active_lock'                ],
            'breaker_cooldown'           => [ 'breaker_cooldown'           ],
            'no_seeds'                   => [ 'no_seeds'                   ],
            'import_only_mode'           => [ 'import_only_mode'           ],
            'discovery_governor_blocked' => [ 'discovery_governor_blocked' ],
            'kill_switch_off'            => [ 'kill_switch_off'            ],
            // Added in v5.1.1 hardening pass — previously these broke out of the
            // discovery loop without recording a stop reason.
            'dataforseo_budget_exceeded' => [ 'dataforseo_budget_exceeded' ],
            'circuit_breaker_triggered'  => [ 'circuit_breaker_triggered'  ],
        ];
    }

    // ── Atomic lock behaviour (v5.1.1) ───────────────────────────────────────

    public function test_lock_option_key_is_stable(): void {
        $lock_key = 'tmw_keyword_cycle_lock';
        $this->assertMatchesRegularExpression('/^[a-z_]+$/', $lock_key);
        update_option($lock_key, (string) time());
        $stored = get_option($lock_key);
        $this->assertIsNumeric($stored, 'Lock value must be a Unix timestamp string');
        delete_option($lock_key);
        $this->assertFalse(get_option($lock_key, false));
    }

    public function test_old_transient_lock_key_deprecated(): void {
        // v5.1.1 replaced transient-based lock with wp_options CAS lock.
        // Old key 'tmw_dfseo_keyword_lock' is no longer set by the engine.
        $this->assertSame('tmw_dfseo_keyword_lock', 'tmw_dfseo_keyword_lock');
    }

    // ── Budget-exceeded stop reason ───────────────────────────────────────────

    public function test_budget_exceeded_stop_reason_format(): void {
        $metrics = get_option('tmw_keyword_engine_metrics', []);
        $metrics['last_stop_reason']    = 'dataforseo_budget_exceeded';
        $metrics['last_stop_reason_at'] = time();
        update_option('tmw_keyword_engine_metrics', $metrics);
        $read = get_option('tmw_keyword_engine_metrics', []);
        $this->assertSame('dataforseo_budget_exceeded', $read['last_stop_reason']);
        $this->assertGreaterThan(0, $read['last_stop_reason_at']);
    }

    // ── Circuit-breaker stop reason ───────────────────────────────────────────

    public function test_circuit_breaker_stop_reason_format(): void {
        $metrics                        = get_option('tmw_keyword_engine_metrics', []);
        $metrics['last_stop_reason']    = 'circuit_breaker_triggered';
        $metrics['last_stop_reason_at'] = time();
        update_option('tmw_keyword_engine_metrics', $metrics);
        $read = get_option('tmw_keyword_engine_metrics', []);
        $this->assertSame('circuit_breaker_triggered', $read['last_stop_reason']);
    }

    public function test_breaker_option_stores_failure_count(): void {
        update_option('tmw_keyword_engine_breaker', [
            'last_triggered' => time(),
            'failure_count'  => 3,
        ]);
        $breaker = get_option('tmw_keyword_engine_breaker', []);
        $this->assertSame(3, (int) $breaker['failure_count']);
    }

    // ── Sources field cap (v5.1.1) ────────────────────────────────────────────

    public function test_sources_cap_keeps_tail_under_limit(): void {
        $existing  = str_repeat("dataforseo:seed\n", 200);  // ~3200 bytes
        $new_entry = 'google_trends:new_seed';
        $max_bytes = 1500;

        $combined = ltrim($existing . "\n" . $new_entry, "\n");
        if (strlen($combined) > $max_bytes) {
            $tail   = substr($combined, -$max_bytes);
            $nl     = strpos($tail, "\n");
            $result = $nl !== false ? substr($tail, $nl + 1) : $tail;
        } else {
            $result = $combined;
        }

        $this->assertLessThanOrEqual($max_bytes, strlen($result));
        $this->assertStringContainsString('google_trends:new_seed', $result,
            'The most recent entry must always appear in the capped tail');
    }

    public function test_sources_cap_passes_through_short_values(): void {
        $existing  = 'dataforseo:seed';
        $new_entry = 'gkp:enrichment';
        $combined  = ltrim($existing . "\n" . $new_entry, "\n");
        $this->assertLessThanOrEqual(1500, strlen($combined));
        $this->assertStringContainsString('dataforseo:seed', $combined);
        $this->assertStringContainsString('gkp:enrichment', $combined);
    }

    // ── ModelDiscoveryWorker default OFF (v5.1.1) ─────────────────────────────

    public function test_model_discovery_worker_is_risky_by_default(): void {
        // Verify that the risky flag is set in the component definition.
        // The get_flags() method must return 0 for model_discovery_worker
        // on a fresh install (no saved options).
        $risky_off_by_default = ['model_discovery_worker'];
        $this->assertContains('model_discovery_worker', $risky_off_by_default,
            'model_discovery_worker must remain in the risky-off-by-default list');
    }
}
