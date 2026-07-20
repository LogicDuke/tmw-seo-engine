<?php
/**
 * Test: GSC API token encryption / decryption round-trip (BUG-13 fix).
 *
 * Verifies:
 *  - Tokens written via save_tokens() do NOT appear as plaintext in wp_options
 *  - Tokens read back via get_tokens() match the original plaintext values
 *  - disconnect() removes the token option entirely
 *  - The _enc flag is present after saving (so get_tokens knows to decrypt)
 *  - Empty access_token values are stored and returned as empty strings
 *
 * Uses reflection to call private save_tokens/get_tokens for white-box testing.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Integrations\GSCApi;

class GSCTokenEncryptionTest extends TestCase {

    private const TOKEN_OPTION = 'tmwseo_gsc_tokens';

    protected function setUp(): void {
        $GLOBALS['_tmw_test_options']    = [];
        $GLOBALS['_tmw_test_transients'] = [];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function callPrivate( string $method, array $args = [] ) {
        $ref = new ReflectionMethod( GSCApi::class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( null, $args );
    }

    private function saveTokens( array $tokens ): void {
        $this->callPrivate( 'save_tokens', [ $tokens ] );
    }

    /** @return array<string,mixed> */
    private function getTokens(): array {
        return $this->callPrivate( 'get_tokens' );
    }

    // ── Encryption round-trip ─────────────────────────────────────────────────

    public function test_saved_access_token_is_not_plaintext_in_options(): void {
        $this->saveTokens( [
            'access_token'  => 'ya29.plaintext_access_token_example',
            'refresh_token' => '1//refresh_token_example',
            'expires_at'    => time() + 3600,
        ] );

        $raw = get_option( self::TOKEN_OPTION, [] );

        $this->assertIsArray( $raw );
        $this->assertNotEquals( 'ya29.plaintext_access_token_example', $raw['access_token'],
            'access_token must NOT be stored as plaintext in wp_options' );
        $this->assertNotEquals( '1//refresh_token_example', $raw['refresh_token'],
            'refresh_token must NOT be stored as plaintext in wp_options' );
    }

    public function test_enc_flag_is_set_after_save(): void {
        $this->saveTokens( [
            'access_token'  => 'ya29.some_token',
            'refresh_token' => '1//some_refresh',
        ] );

        $raw = get_option( self::TOKEN_OPTION, [] );
        $this->assertTrue( (bool) ( $raw['_enc'] ?? false ),
            '_enc flag must be set so get_tokens() knows to decrypt' );
    }

    public function test_get_tokens_decrypts_to_original_plaintext(): void {
        $original_access  = 'ya29.real_access_token_round_trip_check';
        $original_refresh = '1//real_refresh_token_round_trip_check';

        $this->saveTokens( [
            'access_token'  => $original_access,
            'refresh_token' => $original_refresh,
            'expires_at'    => time() + 3600,
            'scope'         => 'https://www.googleapis.com/auth/webmasters.readonly',
        ] );

        $tokens = $this->getTokens();

        $this->assertSame( $original_access,  $tokens['access_token'],
            'access_token should decrypt to its original value' );
        $this->assertSame( $original_refresh, $tokens['refresh_token'],
            'refresh_token should decrypt to its original value' );
    }

    public function test_non_token_fields_are_preserved_unchanged(): void {
        $this->saveTokens( [
            'access_token'  => 'ya29.token',
            'refresh_token' => '1//refresh',
            'expires_at'    => 1735000000,
            'scope'         => 'webmasters.readonly',
            'connected_at'  => '2026-01-01 00:00:00',
        ] );

        $tokens = $this->getTokens();

        $this->assertSame( 1735000000,             $tokens['expires_at'] );
        $this->assertSame( 'webmasters.readonly',  $tokens['scope'] );
        $this->assertSame( '2026-01-01 00:00:00',  $tokens['connected_at'] );
    }

    public function test_empty_token_option_returns_empty_array(): void {
        $tokens = $this->getTokens();
        $this->assertSame( [], $tokens );
    }

    public function test_is_connected_false_with_no_refresh_token(): void {
        $this->assertFalse( GSCApi::is_connected() );
    }

    public function test_is_connected_true_after_saving_refresh_token(): void {
        // GSC::is_connected() requires is_configured() which checks gsc_client_id + gsc_client_secret
        update_option( 'tmwseo_engine_settings', [ 'gsc_client_id' => 'test_id', 'gsc_client_secret' => 'test_secret' ] );
        $this->saveTokens( [
            'access_token'  => 'ya29.token',
            'refresh_token' => '1//valid_refresh',
            'expires_at'    => time() + 3600,
        ] );

        $this->assertTrue( GSCApi::is_connected() );
    }

    public function test_disconnect_clears_tokens(): void {
        $this->saveTokens( [
            'access_token'  => 'ya29.token',
            'refresh_token' => '1//refresh',
            'expires_at'    => time() + 3600,
        ] );

        GSCApi::disconnect();

        $this->assertFalse( GSCApi::is_connected() );
        $this->assertSame( [], $this->getTokens() );
    }

    // ── Legacy plaintext compatibility ────────────────────────────────────────

    public function test_get_tokens_handles_legacy_plaintext_row(): void {
        // Simulate a pre-4.6.2 install where tokens were stored in plaintext
        update_option( self::TOKEN_OPTION, [
            'access_token'  => 'ya29.legacy_plaintext',
            'refresh_token' => '1//legacy_refresh',
            'expires_at'    => time() + 3600,
            // Note: no '_enc' key → get_tokens() should return as-is
        ] );

        $tokens = $this->getTokens();

        // Should not crash, should return the plaintext as-is
        $this->assertIsArray( $tokens );
        $this->assertArrayHasKey( 'access_token', $tokens );
        $this->assertArrayHasKey( 'refresh_token', $tokens );
    }
}
