<?php
/**
 * Google Search Console API v1 — real OAuth2 client.
 *
 * Flow:
 *   1. Admin enters Google Cloud client_id + client_secret in Settings.
 *   2. Clicks "Connect GSC" → redirected to Google consent screen.
 *   3. Google redirects back with ?code=...
 *   4. Plugin exchanges code for access + refresh tokens (stored encrypted).
 *   5. All subsequent requests use auto-refreshed access token.
 *
 * @package TMWSEO\Engine\Integrations
 */
namespace TMWSEO\Engine\Integrations;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Logs;

class GSCApi {

    const TOKEN_OPTION       = 'tmwseo_gsc_tokens';
    // FINDING-09: The OOB OAuth flow (urn:ietf:wg:oauth:2.0:oob) was deprecated by Google
    // in Jan 2023 and no longer works for new OAuth clients. The actual redirect URI is
    // returned by get_redirect_uri() below, which uses a proper callback URL. This constant
    // was unused dead code — removed to avoid misleading future developers.
    // const OAUTH_REDIRECT_URI = 'urn:ietf:wg:oauth:2.0:oob'; — REMOVED
    const AUTH_URL           = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL          = 'https://oauth2.googleapis.com/token';
    const API_BASE           = 'https://www.googleapis.com/webmasters/v3';
    const SCOPE              = 'https://www.googleapis.com/auth/webmasters.readonly';

    // ── Configuration check ────────────────────────────────────────────────

    public static function is_configured(): bool {
        $id     = trim( (string) Settings::get( 'gsc_client_id', '' ) );
        $secret = trim( (string) Settings::get( 'gsc_client_secret', '' ) );
        return $id !== '' && $secret !== '';
    }

    public static function is_connected(): bool {
        if ( ! self::is_configured() ) return false;
        $tokens = self::get_tokens();
        return ! empty( $tokens['refresh_token'] );
    }

    // ── OAuth2 helpers ─────────────────────────────────────────────────────

    /**
     * Returns the URL to send the admin to for Google consent.
     */
    public static function get_auth_url(): string {
        $redirect_uri = self::get_redirect_uri();
        return self::AUTH_URL . '?' . http_build_query( [
            'client_id'     => Settings::get( 'gsc_client_id', '' ),
            'redirect_uri'  => $redirect_uri,
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => wp_create_nonce( 'tmwseo_gsc_oauth' ),
        ] );
    }

    public static function get_redirect_uri(): string {
        return admin_url( 'admin.php?page=tmwseo-settings&tmwseo_gsc_callback=1' );
    }

    /**
     * Exchanges an auth code for tokens and stores them.
     */
    public static function handle_oauth_callback( string $code, string $state ): array {
        if ( ! wp_verify_nonce( $state, 'tmwseo_gsc_oauth' ) ) {
            return [ 'ok' => false, 'error' => 'invalid_state' ];
        }

        $resp = wp_remote_post( self::TOKEN_URL, [
            'body' => [
                'code'          => $code,
                'client_id'     => Settings::get( 'gsc_client_id', '' ),
                'client_secret' => Settings::get( 'gsc_client_secret', '' ),
                'redirect_uri'  => self::get_redirect_uri(),
                'grant_type'    => 'authorization_code',
            ],
        ] );

        return self::process_token_response( $resp );
    }

    /**
     * Refreshes the access token using the stored refresh token.
     */
    public static function refresh_access_token(): array {
        $tokens = self::get_tokens();
        if ( empty( $tokens['refresh_token'] ) ) {
            return [ 'ok' => false, 'error' => 'no_refresh_token' ];
        }

        $resp = wp_remote_post( self::TOKEN_URL, [
            'body' => [
                'refresh_token' => $tokens['refresh_token'],
                'client_id'     => Settings::get( 'gsc_client_id', '' ),
                'client_secret' => Settings::get( 'gsc_client_secret', '' ),
                'grant_type'    => 'refresh_token',
            ],
        ] );

        return self::process_token_response( $resp, $tokens['refresh_token'] );
    }

    public static function disconnect(): void {
        delete_option( self::TOKEN_OPTION );
    }

    // ── Data API ───────────────────────────────────────────────────────────

    /**
     * Fetches search analytics for a site property.
     *
     * @param string   $site_url      e.g. 'sc-domain:example.com'
     * @param string   $start_date    Y-m-d
     * @param string   $end_date      Y-m-d
     * @param string[] $dimensions    e.g. ['query', 'page']
     * @param int      $row_limit
     * @return array{ok:bool, rows?:array, error?:string}
     */
    public static function search_analytics(
        string $site_url,
        string $start_date,
        string $end_date,
        array  $dimensions = [ 'query' ],
        int    $row_limit = 500
    ): array {
        $token = self::get_valid_access_token();
        if ( ! $token ) {
            return [ 'ok' => false, 'error' => 'no_access_token' ];
        }

        $url  = self::API_BASE . '/sites/' . rawurlencode( $site_url ) . '/searchAnalytics/query';
        $body = [
            'startDate'  => $start_date,
            'endDate'    => $end_date,
            'dimensions' => $dimensions,
            'rowLimit'   => min( 25000, max( 1, $row_limit ) ),
        ];

        $resp = wp_remote_post( $url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $resp ) ) {
            return [ 'ok' => false, 'error' => $resp->get_error_message() ];
        }

        $code = (int) wp_remote_retrieve_response_code( $resp );

        // Token expired mid-session — refresh and retry once
        if ( $code === 401 ) {
            $refresh = self::refresh_access_token();
            if ( ! $refresh['ok'] ) {
                return [ 'ok' => false, 'error' => 'token_refresh_failed' ];
            }
            $token = $refresh['access_token'] ?? '';
            if ( $token === '' ) {
                return [ 'ok' => false, 'error' => 'empty_refreshed_token' ];
            }
            $resp = wp_remote_post( $url, [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode( $body ),
            ] );
            if ( is_wp_error( $resp ) ) {
                return [ 'ok' => false, 'error' => $resp->get_error_message() ];
            }
            $code = (int) wp_remote_retrieve_response_code( $resp );
        }

        $raw  = (string) wp_remote_retrieve_body( $resp );
        $json = json_decode( $raw, true );

        if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
            Logs::error( 'gsc', 'Bad response', [ 'code' => $code, 'body' => substr( $raw, 0, 400 ) ] );
            return [ 'ok' => false, 'error' => 'bad_response', 'code' => $code, 'body' => $raw ];
        }

        $rows = $json['rows'] ?? [];
        return [ 'ok' => true, 'rows' => is_array( $rows ) ? $rows : [] ];
    }

    /**
     * Returns the list of verified sites for the connected account.
     */
    public static function list_sites(): array {
        $token = self::get_valid_access_token();
        if ( ! $token ) {
            return [ 'ok' => false, 'error' => 'no_access_token' ];
        }

        $resp = wp_remote_get( self::API_BASE . '/sites', [
            'timeout' => 15,
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        ] );

        if ( is_wp_error( $resp ) ) {
            return [ 'ok' => false, 'error' => $resp->get_error_message() ];
        }

        $raw  = (string) wp_remote_retrieve_body( $resp );
        $json = json_decode( $raw, true );

        $entries = $json['siteEntry'] ?? [];
        return [ 'ok' => true, 'sites' => is_array( $entries ) ? $entries : [] ];
    }

    // ── Top keyword metrics for a page (used by RankingProbabilityOrchestrator) ──

    /**
     * Returns aggregated GSC metrics for a specific page URL over the last 90 days.
     *
     * @return array{ok:bool, clicks:int, impressions:int, ctr:float, position:float}
     */
    public static function page_metrics( string $page_url ): array {
        $site_url = self::get_site_url();
        if ( $site_url === '' ) {
            return [ 'ok' => false, 'error' => 'gsc_site_url_not_set', 'clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'position' => 0.0 ];
        }

        $end   = date( 'Y-m-d' );
        $start = date( 'Y-m-d', strtotime( '-90 days' ) );

        $cache_key = 'tmwseo_gsc_page_' . md5( $page_url . $start . $end );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false && is_array( $cached ) ) {
            return $cached;
        }

        $res = self::search_analytics( $site_url, $start, $end, [ 'page' ], 5000 );
        if ( ! $res['ok'] ) {
            return array_merge( [ 'clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'position' => 0.0 ], $res );
        }

        $norm_url = rtrim( strtolower( $page_url ), '/' );
        $result   = [ 'ok' => true, 'clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'position' => 0.0 ];

        foreach ( $res['rows'] as $row ) {
            $row_url = rtrim( strtolower( (string) ( $row['keys'][0] ?? '' ) ), '/' );
            if ( $row_url === $norm_url ) {
                $result = [
                    'ok'          => true,
                    'clicks'      => (int) ( $row['clicks'] ?? 0 ),
                    'impressions' => (int) ( $row['impressions'] ?? 0 ),
                    'ctr'         => round( (float) ( $row['ctr'] ?? 0 ) * 100, 2 ),
                    'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
                ];
                break;
            }
        }

        set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );
        return $result;
    }

    /**
     * Returns top queries for a specific page (used by SERP Weakness + Content Brief).
     */
    public static function page_top_queries( string $page_url, int $limit = 20 ): array {
        $site_url = self::get_site_url();
        if ( $site_url === '' ) return [];

        $end   = date( 'Y-m-d' );
        $start = date( 'Y-m-d', strtotime( '-90 days' ) );

        $cache_key = 'tmwseo_gsc_queries_' . md5( $page_url . $limit );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false && is_array( $cached ) ) return $cached;

        // Filter by page using dimensionFilterGroups
        $token = self::get_valid_access_token();
        if ( ! $token ) return [];

        $url  = self::API_BASE . '/sites/' . rawurlencode( $site_url ) . '/searchAnalytics/query';
        $body = [
            'startDate'  => $start,
            'endDate'    => $end,
            'dimensions' => [ 'query' ],
            'rowLimit'   => $limit,
            'dimensionFilterGroups' => [ [
                'filters' => [ [
                    'dimension'  => 'page',
                    'operator'   => 'equals',
                    'expression' => $page_url,
                ] ],
            ] ],
        ];

        $resp = wp_remote_post( $url, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $resp ) ) return [];
        $raw  = (string) wp_remote_retrieve_body( $resp );
        $json = json_decode( $raw, true );
        $rows = $json['rows'] ?? [];
        if ( ! is_array( $rows ) ) return [];

        $queries = [];
        foreach ( $rows as $row ) {
            $queries[] = [
                'query'       => $row['keys'][0] ?? '',
                'clicks'      => (int) ( $row['clicks'] ?? 0 ),
                'impressions' => (int) ( $row['impressions'] ?? 0 ),
                'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
            ];
        }

        set_transient( $cache_key, $queries, 6 * HOUR_IN_SECONDS );
        return $queries;
    }

    // ── Token management ───────────────────────────────────────────────────

    private static function get_valid_access_token(): string {
        $tokens = self::get_tokens();
        if ( empty( $tokens ) ) return '';

        $expires_at = (int) ( $tokens['expires_at'] ?? 0 );
        // Refresh if expired or expiring within 5 min
        if ( time() >= $expires_at - 300 ) {
            $refresh = self::refresh_access_token();
            if ( $refresh['ok'] ) {
                return $refresh['access_token'] ?? '';
            }
            return '';
        }

        return (string) ( $tokens['access_token'] ?? '' );
    }

    private static function get_tokens(): array {
        $raw = get_option( self::TOKEN_OPTION, [] );
        if ( ! is_array( $raw ) ) {
            return [];
        }

        // FIX BUG-13: Decrypt sensitive token fields if stored encrypted.
        // Supports both legacy plaintext (pre-fix) and new encrypted format transparently.
        if ( ! empty( $raw['_enc'] ) ) {
            $raw['access_token']  = self::decrypt_field( (string) ( $raw['access_token']  ?? '' ) );
            $raw['refresh_token'] = self::decrypt_field( (string) ( $raw['refresh_token'] ?? '' ) );
        }

        return $raw;
    }

    private static function save_tokens( array $tokens ): void {
        // FIX BUG-13: Encrypt sensitive OAuth credential fields before writing to wp_options.
        // The previous implementation stored access_token and refresh_token as plaintext,
        // accessible to any plugin with DB read access or via phpMyAdmin.
        if ( ! empty( $tokens['access_token'] ) ) {
            $tokens['access_token']  = self::encrypt_field( $tokens['access_token'] );
        }
        if ( ! empty( $tokens['refresh_token'] ) ) {
            $tokens['refresh_token'] = self::encrypt_field( $tokens['refresh_token'] );
        }
        $tokens['_enc'] = true; // marker so get_tokens() knows to decrypt

        update_option( self::TOKEN_OPTION, $tokens, false );
    }

    /**
     * Encrypt a string using sodium_crypto_secretbox with a key derived from wp_salt().
     * Falls back to base64 if sodium is unavailable (PHP < 7.2 — uncommon but safe).
     */
    private static function encrypt_field( string $plaintext ): string {
        if ( $plaintext === '' ) {
            return '';
        }

        if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
            // Fallback: base64 is not encryption but at least not trivially readable
            return 'b64:' . base64_encode( $plaintext );
        }

        $key   = self::derive_key();
        $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $box   = sodium_crypto_secretbox( $plaintext, $nonce, $key );

        return 'enc:' . base64_encode( $nonce . $box );
    }

    /**
     * Decrypt a field encrypted by encrypt_field().
     */
    private static function decrypt_field( string $ciphertext ): string {
        if ( $ciphertext === '' ) {
            return '';
        }

        if ( strpos( $ciphertext, 'b64:' ) === 0 ) {
            return (string) base64_decode( substr( $ciphertext, 4 ) );
        }

        if ( strpos( $ciphertext, 'enc:' ) !== 0 || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
            // Not in our format — return as-is (handles legacy plaintext rows)
            return $ciphertext;
        }

        $decoded = base64_decode( substr( $ciphertext, 4 ) );
        if ( $decoded === false || strlen( $decoded ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
            return '';
        }

        $nonce      = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $box        = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $key        = self::derive_key();
        $plaintext  = sodium_crypto_secretbox_open( $box, $nonce, $key );

        return $plaintext === false ? '' : $plaintext;
    }

    /**
     * Derive a 32-byte encryption key from WordPress's salt + AUTH_KEY.
     * This ties the key to the WordPress installation without storing it separately.
     */
    private static function derive_key(): string {
        $salt = wp_salt( 'auth' );
        $auth = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'tmwseo_fallback_key_no_auth_key_defined';
        return substr( hash( 'sha256', $salt . $auth . 'tmwseo_gsc_v1', true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
    }

    private static function process_token_response( $resp, string $keep_refresh = '' ): array {
        if ( is_wp_error( $resp ) ) {
            return [ 'ok' => false, 'error' => $resp->get_error_message() ];
        }

        $code = (int) wp_remote_retrieve_response_code( $resp );
        $raw  = (string) wp_remote_retrieve_body( $resp );
        $json = json_decode( $raw, true );

        if ( $code < 200 || $code >= 300 || empty( $json['access_token'] ) ) {
            Logs::error( 'gsc', 'Token exchange failed', [ 'code' => $code, 'body' => substr( $raw, 0, 300 ) ] );
            return [ 'ok' => false, 'error' => 'token_exchange_failed', 'body' => $raw ];
        }

        $tokens = [
            'access_token'  => $json['access_token'],
            'refresh_token' => $json['refresh_token'] ?? $keep_refresh,
            'expires_at'    => time() + (int) ( $json['expires_in'] ?? 3600 ),
            'scope'         => $json['scope'] ?? '',
            'connected_at'  => current_time( 'mysql' ),
        ];

        self::save_tokens( $tokens );

        return array_merge( [ 'ok' => true ], $tokens );
    }

    private static function get_site_url(): string {
        return trim( (string) Settings::get( 'gsc_site_url', '' ) );
    }
}
