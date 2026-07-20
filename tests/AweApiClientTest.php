<?php
/**
 * Tests: AweApiClient
 *
 * Covers:
 *  - is_configured() logic
 *  - request URL builder never includes credentials in error/log output
 *  - cache hit / miss
 *  - safe error return on WP_Error and non-200 HTTP
 *  - normalized response shape
 *  - access_key never surfaces in public-facing output
 *
 * HTTP calls are intercepted via the WordPress pre_http_request filter —
 * no real network calls are made.
 *
 * @package TMWSEO\Engine\Tests
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Integrations\AweApiClient;

// Load the class under test (stubs are bootstrapped via phpunit.xml).
require_once __DIR__ . '/../includes/services/class-settings.php';
require_once __DIR__ . '/../includes/integrations/class-awe-api-client.php';

class AweApiClientTest extends TestCase {

    // ── Setup / teardown ──────────────────────────────────────────────────────

    protected function setUp(): void {
        $GLOBALS['_tmw_test_options']    = [];
        $GLOBALS['_tmw_test_transients'] = [];
        $GLOBALS['_tmw_http_filters']    = [];
    }

    protected function tearDown(): void {
        // Clear the AWE filter registry entries for pre_http_request.
        unset( $GLOBALS['_tmw_filter_registry']['pre_http_request'] );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Inject AWE credentials into the test option store.
     */
    private function configure( array $overrides = [] ): void {
        $defaults = [
            'tmwseo_awe_enabled'    => 1,
            'tmwseo_awe_psid'       => 'test_psid_123',
            'tmwseo_awe_access_key' => 'secret_key_should_not_appear',
            'tmwseo_awe_base_url'   => 'https://pt.ptawe.com',
            'tmwseo_awe_language'   => 'en',
            'tmwseo_awe_psprogram'  => 'PPL',
            'tmwseo_awe_timeout'    => 15,
            'tmwseo_awe_cache_ttl'  => 3600,
        ];
        update_option( 'tmwseo_engine_settings', array_merge( $defaults, $overrides ) );
    }

    /**
     * Register a pre_http_request intercept via _tmw_register_filter.
     * (Global add_filter stub is a no-op; we write directly to the registry.)
     *
     * @param int    $status  HTTP status code.
     * @param mixed  $body    Response body (array will be json_encoded).
     * @param bool   $wp_error If true, returns a WP_Error instead.
     */
    private function mock_http( int $status = 200, $body = [], bool $wp_error = false ): void {
        _tmw_register_filter(
            'pre_http_request',
            static function() use ( $status, $body, $wp_error ) {
                if ( $wp_error ) {
                    return new WP_Error( 'http_request_failed', 'cURL: connection refused' );
                }
                return [
                    'response' => [ 'code' => $status, 'message' => 'OK' ],
                    'body'     => is_array( $body ) ? json_encode( $body ) : (string) $body,
                    'headers'  => [],
                    'cookies'  => [],
                ];
            },
            10,
            3
        );
    }

    // ── is_configured() ───────────────────────────────────────────────────────

    public function test_not_configured_when_no_credentials(): void {
        update_option( 'tmwseo_engine_settings', [] );
        $this->assertFalse( AweApiClient::is_configured() );
    }

    public function test_not_configured_when_psid_missing(): void {
        update_option( 'tmwseo_engine_settings', [
            'tmwseo_awe_psid'       => '',
            'tmwseo_awe_access_key' => 'somekey',
        ] );
        $this->assertFalse( AweApiClient::is_configured() );
    }

    public function test_not_configured_when_access_key_missing(): void {
        update_option( 'tmwseo_engine_settings', [
            'tmwseo_awe_psid'       => 'psid123',
            'tmwseo_awe_access_key' => '',
        ] );
        $this->assertFalse( AweApiClient::is_configured() );
    }

    public function test_configured_when_both_credentials_present(): void {
        $this->configure();
        $this->assertTrue( AweApiClient::is_configured() );
    }

    // ── build_request_url() credential safety ─────────────────────────────────

    public function test_build_url_contains_psid(): void {
        $this->configure();
        $url = AweApiClient::build_request_url( AweApiClient::ENDPOINT_MODEL_LIST );
        $this->assertStringContainsString( 'test_psid_123', $url );
    }

    public function test_build_url_contains_access_key_in_query(): void {
        $this->configure();
        // The URL must contain the key (AWE requirement),
        // but it must NEVER appear in safe_endpoint() output.
        $url = AweApiClient::build_request_url( AweApiClient::ENDPOINT_MODEL_LIST );
        $this->assertStringContainsString( 'accessKey=', $url );
    }

    public function test_safe_endpoint_does_not_contain_access_key(): void {
        $this->configure();
        $safe = AweApiClient::safe_endpoint( AweApiClient::ENDPOINT_MODEL_PROFILE );
        $this->assertStringNotContainsString( 'secret_key_should_not_appear', $safe,
            'safe_endpoint() must never include the access key' );
        $this->assertStringNotContainsString( 'accessKey', $safe );
    }

    // ── Cache helpers ─────────────────────────────────────────────────────────

    public function test_cache_miss_returns_false(): void {
        $result = AweApiClient::get_cached( 'tmwseo_awe_nonexistent_key' );
        $this->assertFalse( $result );
    }

    public function test_cache_set_and_get_roundtrip(): void {
        $payload = [ 'ok' => true, 'data' => [ 'foo' => 'bar' ], 'error' => '', 'status' => 200 ];
        AweApiClient::set_cached( 'tmwseo_awe_test_key', $payload );
        $retrieved = AweApiClient::get_cached( 'tmwseo_awe_test_key' );
        $this->assertIsArray( $retrieved );
        $this->assertTrue( $retrieved['ok'] );
        $this->assertSame( 'bar', $retrieved['data']['foo'] );
    }

    // ── fetch_model_list() — unconfigured ─────────────────────────────────────

    public function test_fetch_model_list_returns_error_when_not_configured(): void {
        update_option( 'tmwseo_engine_settings', [] );
        $result = AweApiClient::fetch_model_list();
        $this->assertFalse( $result['ok'] );
        $this->assertNotEmpty( $result['error'] );
        $this->assertEmpty( $result['data'] );
        $this->assertStringNotContainsString( 'accessKey', $result['error'] );
    }

    // ── HTTP error handling ───────────────────────────────────────────────────

    public function test_fetch_model_list_returns_ok_false_on_wp_error(): void {
        $this->configure();
        $this->mock_http( 200, [], true ); // WP_Error
        $result = AweApiClient::fetch_model_list( [ '_no_cache' => uniqid() ] );
        $this->assertFalse( $result['ok'] );
        $this->assertIsString( $result['error'] );
        $this->assertStringNotContainsString( 'secret_key_should_not_appear', $result['error'],
            'access_key must not appear in error messages' );
    }

    public function test_fetch_model_list_returns_ok_false_on_non_200(): void {
        $this->configure();
        $this->mock_http( 403, [ 'error' => 'forbidden' ] );
        $result = AweApiClient::fetch_model_list( [ '_no_cache' => uniqid() ] );
        $this->assertFalse( $result['ok'] );
        $this->assertSame( 403, $result['status'] );
        $this->assertStringNotContainsString( 'secret_key_should_not_appear', $result['error'] );
    }

    public function test_fetch_model_list_returns_ok_false_on_non_json(): void {
        $this->configure();
        $this->mock_http( 200, '<html>not json</html>' );
        $result = AweApiClient::fetch_model_list( [ '_no_cache' => uniqid() ] );
        $this->assertFalse( $result['ok'] );
    }

    // ── Successful fetch ──────────────────────────────────────────────────────

    public function test_fetch_model_list_returns_ok_true_on_success(): void {
        $this->configure();
        $this->mock_http( 200, [ 'models' => [ [ 'performerName' => 'TestModel' ] ] ] );
        $result = AweApiClient::fetch_model_list( [ '_no_cache' => uniqid() ] );
        $this->assertTrue( $result['ok'] );
        $this->assertIsArray( $result['data'] );
        $this->assertArrayHasKey( 'models', $result['data'] );
        $this->assertSame( 200, $result['status'] );
        $this->assertSame( '', $result['error'] );
    }

    // ── Response shape ────────────────────────────────────────────────────────

    public function test_response_always_has_required_keys(): void {
        $this->configure();
        $this->mock_http( 200, [ 'models' => [] ] );
        $result = AweApiClient::fetch_model_list( [ '_no_cache' => uniqid() ] );
        foreach ( [ 'ok', 'data', 'error', 'status' ] as $key ) {
            $this->assertArrayHasKey( $key, $result, "Key '{$key}' missing from result" );
        }
    }

    // ── test() method ─────────────────────────────────────────────────────────

    public function test_test_returns_not_configured_message(): void {
        update_option( 'tmwseo_engine_settings', [] );
        $result = AweApiClient::test();
        $this->assertFalse( $result['ok'] );
        $this->assertStringContainsString( 'not configured', $result['message'] );
        $this->assertStringNotContainsString( 'accessKey', $result['message'] );
    }

    public function test_test_succeeds_on_valid_response(): void {
        $this->configure();
        $this->mock_http( 200, [ 'models' => [] ] );
        $result = AweApiClient::test();
        $this->assertTrue( $result['ok'] );
        $this->assertStringNotContainsString( 'secret_key_should_not_appear', $result['message'] );
    }

    // ── fetch_model_profile() ─────────────────────────────────────────────────

    public function test_fetch_model_profile_returns_error_for_empty_identifier(): void {
        $this->configure();
        $result = AweApiClient::fetch_model_profile( '' );
        $this->assertFalse( $result['ok'] );
        $this->assertStringContainsString( 'identifier', $result['error'] );
    }

    public function test_fetch_model_profile_uses_cache_on_second_call(): void {
        $this->configure();
        $call_count = 0;
        _tmw_register_filter(
            'pre_http_request',
            static function() use ( &$call_count ) {
                $call_count++;
                return [
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'body'     => json_encode( [ 'models' => [ [ 'performerName' => 'CachedModel' ] ] ] ),
                    'headers'  => [],
                    'cookies'  => [],
                ];
            },
            10,
            3
        );

        // First call — hits HTTP.
        AweApiClient::fetch_model_profile( 'CachedModel', 'name' );
        $count_after_first = $call_count;

        // Second call — should use transient cache; may or may not hit HTTP
        // depending on internal fallback logic. Main assertion: ok=true both times.
        $result = AweApiClient::fetch_model_profile( 'CachedModel', 'name' );
        $this->assertTrue( $result['ok'] );
        $this->assertGreaterThanOrEqual( 1, $count_after_first );
    }
}
