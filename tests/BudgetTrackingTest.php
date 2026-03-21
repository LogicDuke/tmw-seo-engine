<?php
/**
 * Test: Budget tracking for DataForSEO and AI Router.
 *
 * Verifies:
 *  - get_monthly_budget_stats() returns correct structure and defaults
 *  - is_over_budget() correctly gates when spend >= budget
 *  - Budget tracking stores values in the expected option keys
 *  - AI Router get_month_spend() and is_over_budget() behave correctly
 *  - Budget cap of 0 means unlimited (is_over_budget() → false)
 *
 * These tests run against the WP-stub in-memory option store, so they
 * validate the logic path completely without a real DB or WP install.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\AI\AIRouter;

class BudgetTrackingTest extends TestCase {

    private string $month;
    private string $dfs_spend_key;
    private string $dfs_calls_key;
    private string $ai_spend_key;

    protected function setUp(): void {
        // Reset all test options before each test
        $GLOBALS['_tmw_test_options'] = [];
        $GLOBALS['_tmw_test_transients'] = [];

        $this->month         = gmdate( 'Y_m' );
        $this->dfs_spend_key = 'tmwseo_dataforseo_spend_' . $this->month;
        $this->dfs_calls_key = 'tmwseo_dataforseo_calls_' . $this->month;
        $this->ai_spend_key  = 'tmwseo_ai_spend_' . $this->month;
    }

    // ── DataForSEO budget ─────────────────────────────────────────────────────

    public function test_dfs_budget_stats_returns_correct_structure(): void {
        $stats = DataForSEO::get_monthly_budget_stats();

        $this->assertIsArray( $stats );
        $this->assertArrayHasKey( 'month', $stats );
        $this->assertArrayHasKey( 'budget_usd', $stats );
        $this->assertArrayHasKey( 'spent_usd', $stats );
        $this->assertArrayHasKey( 'calls', $stats );
        $this->assertArrayHasKey( 'remaining_usd', $stats );
        $this->assertArrayHasKey( 'over_budget', $stats );
    }

    public function test_dfs_budget_stats_defaults_to_zero_spend(): void {
        $stats = DataForSEO::get_monthly_budget_stats();

        $this->assertSame( 0.0, $stats['spent_usd'] );
        $this->assertSame( 0,   $stats['calls'] );
        $this->assertFalse( $stats['over_budget'] );
    }

    public function test_dfs_is_over_budget_false_when_under_cap(): void {
        // Budget $20, spend $5
        update_option( 'tmwseo_engine_settings', [ 'tmwseo_dataforseo_budget_usd' => 20.0 ] );
        update_option( $this->dfs_spend_key, 5.0 );

        $this->assertFalse( DataForSEO::is_over_budget() );
    }

    public function test_dfs_is_over_budget_true_when_at_cap(): void {
        update_option( 'tmwseo_engine_settings', [ 'tmwseo_dataforseo_budget_usd' => 20.0 ] );
        update_option( $this->dfs_spend_key, 20.0 );

        $this->assertTrue( DataForSEO::is_over_budget() );
    }

    public function test_dfs_is_over_budget_true_when_exceeds_cap(): void {
        update_option( 'tmwseo_engine_settings', [ 'tmwseo_dataforseo_budget_usd' => 10.0 ] );
        update_option( $this->dfs_spend_key, 12.5 );

        $this->assertTrue( DataForSEO::is_over_budget() );
    }

    public function test_dfs_budget_zero_means_unlimited(): void {
        // Budget of 0 = unlimited — is_over_budget must always return false
        update_option( 'tmwseo_engine_settings', [ 'tmwseo_dataforseo_budget_usd' => 0.0 ] );
        update_option( $this->dfs_spend_key, 999.99 );

        $this->assertFalse( DataForSEO::is_over_budget(),
            'Budget cap of 0 should mean unlimited — is_over_budget() must return false' );
    }

    public function test_dfs_remaining_calculated_correctly(): void {
        update_option( 'tmwseo_engine_settings', [ 'tmwseo_dataforseo_budget_usd' => 20.0 ] );
        update_option( $this->dfs_spend_key, 7.5 );

        $stats = DataForSEO::get_monthly_budget_stats();

        $this->assertEqualsWithDelta( 12.5, $stats['remaining_usd'], 0.0001 );
        $this->assertFalse( $stats['over_budget'] );
    }

    public function test_dfs_remaining_clamps_at_zero_not_negative(): void {
        update_option( 'tmwseo_engine_settings', [ 'tmwseo_dataforseo_budget_usd' => 10.0 ] );
        update_option( $this->dfs_spend_key, 15.0 );

        $stats = DataForSEO::get_monthly_budget_stats();

        $this->assertGreaterThanOrEqual( 0.0, $stats['remaining_usd'],
            'remaining_usd should never be negative' );
    }

    public function test_dfs_not_configured_when_credentials_empty(): void {
        // No settings written → credentials empty → not configured
        $this->assertFalse( DataForSEO::is_configured() );
    }

    public function test_dfs_is_configured_when_credentials_present(): void {
        update_option( 'tmwseo_engine_settings', [
            'dataforseo_login'    => 'user@example.com',
            'dataforseo_password' => 'secret123',
        ] );

        $this->assertTrue( DataForSEO::is_configured() );
    }

    // ── AI Router budget ──────────────────────────────────────────────────────

    public function test_ai_router_get_month_spend_returns_zero_initially(): void {
        $this->assertSame( 0.0, AIRouter::get_month_spend() );
    }

    public function test_ai_router_get_month_spend_reads_option(): void {
        update_option( $this->ai_spend_key, 4.75 );

        $this->assertEqualsWithDelta( 4.75, AIRouter::get_month_spend(), 0.0001 );
    }

    public function test_ai_router_is_over_budget_false_when_under_cap(): void {
        update_option( 'tmwseo_engine_settings', [ 'tmwseo_openai_budget_usd' => 20.0 ] );
        update_option( $this->ai_spend_key, 5.0 );

        $this->assertFalse( AIRouter::is_over_budget() );
    }

    public function test_ai_router_is_over_budget_true_when_at_cap(): void {
        update_option( 'tmwseo_engine_settings', [ 'tmwseo_openai_budget_usd' => 20.0 ] );
        update_option( $this->ai_spend_key, 20.0 );

        $this->assertTrue( AIRouter::is_over_budget() );
    }

    public function test_ai_router_budget_zero_means_unlimited(): void {
        update_option( 'tmwseo_engine_settings', [ 'tmwseo_openai_budget_usd' => 0.0 ] );
        update_option( $this->ai_spend_key, 9999.0 );

        $this->assertFalse( AIRouter::is_over_budget(),
            'AI budget cap of 0 should mean unlimited' );
    }

    public function test_ai_router_token_stats_structure(): void {
        $stats = AIRouter::get_token_stats();

        $this->assertIsArray( $stats );
        $this->assertArrayHasKey( 'month', $stats );
        $this->assertArrayHasKey( 'spend_usd', $stats );
        $this->assertArrayHasKey( 'budget_usd', $stats );
        $this->assertArrayHasKey( 'remaining', $stats );
        $this->assertArrayHasKey( 'over_budget', $stats );
        $this->assertArrayHasKey( 'tokens', $stats );
    }
}
