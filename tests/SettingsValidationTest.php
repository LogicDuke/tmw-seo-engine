<?php
/**
 * Test: Admin::sanitize_settings() — numeric clamping and type validation (FINDING-10 fix).
 *
 * Verifies that invalid, out-of-range, or malformed input values are
 * silently corrected to valid defaults rather than stored verbatim.
 *
 * Previously these fields used only max(0, intval(x)) so a value like
 * 999999 for keyword_max_kd (which has a 0-100 scale) would be stored
 * as-is, silently corrupting behaviour. Now each field has min+max clamping.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Admin;

class SettingsValidationTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_tmw_test_options']    = [];
        $GLOBALS['_tmw_test_transients'] = [];
        // Pre-populate required dependencies for sanitize_settings
        require_once TMWSEO_ENGINE_PATH . 'includes/services/class-trust-policy.php';
        require_once TMWSEO_ENGINE_PATH . 'includes/admin/class-admin-ui.php';
    }

    /** Thin wrapper that calls Admin::sanitize_settings with only keyword fields set. */
    private function sanitize( array $fields ): array {
        // sanitize_settings preserves existing and merges — start clean
        update_option( 'tmwseo_engine_settings', [] );
        return Admin::sanitize_settings( $fields );
    }

    // ── keyword_max_kd (0–100) ────────────────────────────────────────────────

    public function test_keyword_max_kd_clamps_above_100(): void {
        $result = $this->sanitize( [ 'keyword_max_kd' => 9999 ] );
        $this->assertLessThanOrEqual( 100, $result['keyword_max_kd'],
            'keyword_max_kd must never exceed 100' );
    }

    public function test_keyword_max_kd_clamps_below_zero(): void {
        $result = $this->sanitize( [ 'keyword_max_kd' => -50 ] );
        $this->assertGreaterThanOrEqual( 0, $result['keyword_max_kd'],
            'keyword_max_kd must never be negative' );
    }

    public function test_keyword_max_kd_string_input_coerced_to_zero(): void {
        $result = $this->sanitize( [ 'keyword_max_kd' => 'abc' ] );
        $this->assertSame( 0, $result['keyword_max_kd'],
            'Non-numeric string should coerce to 0' );
    }

    public function test_keyword_max_kd_valid_value_passes_through(): void {
        $result = $this->sanitize( [ 'keyword_max_kd' => 45 ] );
        $this->assertSame( 45, $result['keyword_max_kd'] );
    }

    // ── keyword_min_volume (0–100000) ─────────────────────────────────────────

    public function test_keyword_min_volume_clamps_above_max(): void {
        $result = $this->sanitize( [ 'keyword_min_volume' => 9999999 ] );
        $this->assertLessThanOrEqual( 100000, $result['keyword_min_volume'],
            'keyword_min_volume must not exceed 100000' );
    }

    public function test_keyword_min_volume_clamps_below_zero(): void {
        $result = $this->sanitize( [ 'keyword_min_volume' => -1 ] );
        $this->assertGreaterThanOrEqual( 0, $result['keyword_min_volume'] );
    }

    public function test_keyword_min_volume_valid_passes_through(): void {
        $result = $this->sanitize( [ 'keyword_min_volume' => 150 ] );
        $this->assertSame( 150, $result['keyword_min_volume'] );
    }

    // ── keyword_new_limit (1–5000) ────────────────────────────────────────────

    public function test_keyword_new_limit_clamps_above_max(): void {
        $result = $this->sanitize( [ 'keyword_new_limit' => 100000 ] );
        $this->assertLessThanOrEqual( 5000, $result['keyword_new_limit'] );
    }

    public function test_keyword_new_limit_clamps_zero_to_minimum_one(): void {
        $result = $this->sanitize( [ 'keyword_new_limit' => 0 ] );
        $this->assertGreaterThanOrEqual( 1, $result['keyword_new_limit'],
            'keyword_new_limit must be at least 1 — zero would disable all keyword ingestion' );
    }

    public function test_keyword_new_limit_valid_passes_through(): void {
        $result = $this->sanitize( [ 'keyword_new_limit' => 300 ] );
        $this->assertSame( 300, $result['keyword_new_limit'] );
    }

    // ── keyword_kd_batch_limit (1–1000) ───────────────────────────────────────

    public function test_keyword_kd_batch_limit_clamps_above_1000(): void {
        $result = $this->sanitize( [ 'keyword_kd_batch_limit' => 9000 ] );
        $this->assertLessThanOrEqual( 1000, $result['keyword_kd_batch_limit'] );
    }

    public function test_keyword_kd_batch_limit_minimum_one(): void {
        $result = $this->sanitize( [ 'keyword_kd_batch_limit' => 0 ] );
        $this->assertGreaterThanOrEqual( 1, $result['keyword_kd_batch_limit'] );
    }

    // ── keyword_pages_per_day (1–100) ─────────────────────────────────────────

    public function test_keyword_pages_per_day_clamps_above_100(): void {
        $result = $this->sanitize( [ 'keyword_pages_per_day' => 9999 ] );
        $this->assertLessThanOrEqual( 100, $result['keyword_pages_per_day'] );
    }

    public function test_keyword_pages_per_day_minimum_one(): void {
        $result = $this->sanitize( [ 'keyword_pages_per_day' => -5 ] );
        $this->assertGreaterThanOrEqual( 1, $result['keyword_pages_per_day'] );
    }

    // ── Boolean fields ────────────────────────────────────────────────────────

    public function test_safe_mode_defaults_off_when_not_in_input(): void {
        $result = $this->sanitize( [] );
        // absent = unchecked = 0
        $this->assertSame( 0, $result['safe_mode'] );
    }

    public function test_debug_mode_false_when_not_in_input(): void {
        $result = $this->sanitize( [] );
        $this->assertSame( 0, $result['debug_mode'] );
    }

    public function test_model_discovery_disabled_by_default(): void {
        $result = $this->sanitize( [] );
        $this->assertSame( 0, $result['model_discovery_enabled'],
            'model_discovery_enabled must default OFF — scraping is opt-in only' );
    }

    // ── OpenAI mode allowlist ─────────────────────────────────────────────────

    public function test_openai_mode_invalid_value_falls_back_to_hybrid(): void {
        $result = $this->sanitize( [ 'openai_mode' => 'turbo-godmode' ] );
        $this->assertContains( $result['openai_mode'], [ 'quality', 'bulk', 'hybrid' ],
            'openai_mode must be restricted to the allowlist' );
    }

    public function test_openai_mode_valid_passes_through(): void {
        $result = $this->sanitize( [ 'openai_mode' => 'quality' ] );
        $this->assertSame( 'quality', $result['openai_mode'] );
    }
}
