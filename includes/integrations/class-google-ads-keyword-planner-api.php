<?php
/**
 * TMW SEO Engine — Google Ads Keyword Planner API Integration
 *
 * Provides keyword ideas and metrics enrichment via the Google Ads API
 * KeywordPlanIdeaService and KeywordPlanService.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IMPORTANT — FEATURE GATE:
 * This integration is only active when 'google_ads_enabled' = 1 in Settings.
 * Default is 0 (OFF). All five credential fields must also be non-empty.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * CREDENTIALS REQUIRED (Settings → Google Ads):
 *  - Developer token   (from Google Ads Manager Account)
 *  - Client ID         (OAuth2 client ID)
 *  - Client Secret     (OAuth2 client secret)
 *  - Refresh Token     (generated via OAuth2 flow)
 *  - Customer ID       (your Google Ads account ID, no dashes)
 *
 * ENDPOINTS:
 *  POST https://googleads.googleapis.com/{version}/customers/{customer_id}:generateKeywordIdeas
 *  POST https://googleads.googleapis.com/{version}/customers/{customer_id}/googleAds:searchStream
 *
 * CSV IMPORT FALLBACK:
 *  When the API is not configured, the existing import pipeline already handles
 *  Keyword Planner CSV exports. This class provides the live API path only.
 *
 * @package TMWSEO\Engine\Integrations
 * @since   4.4.0
 */

namespace TMWSEO\Engine\Integrations;

use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GoogleAdsKeywordPlannerApi {

    private const TOKEN_ENDPOINT    = 'https://oauth2.googleapis.com/token';
    private const ADS_API_VERSION   = 'v18';
    private const ADS_API_HOST      = 'https://googleads.googleapis.com';
    private const TOKEN_CACHE_KEY   = 'tmwseo_google_ads_access_token';
    private const CACHE_TTL         = 55 * MINUTE_IN_SECONDS; // access tokens last 60 min

    // ── Configuration ───────────────────────────────────────────────────────

    public static function is_enabled(): bool {
        return (bool) Settings::get( 'google_ads_enabled', 0 );
    }

    public static function is_configured(): bool {
        $stored = get_option( 'tmwseo_engine_settings', [] );
        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        $enabled_raw = $stored['google_ads_enabled'] ?? Settings::get( 'google_ads_enabled', 0 );
        $is_enabled  = in_array( $enabled_raw, [ 1, '1', true, 'true', 'yes', 'on' ], true );

        if ( ! $is_enabled ) {
            Logs::info( 'google-ads', '[TMW-SEO-AUTO] Google Ads is_configured=false (integration disabled)', [
                'google_ads_enabled' => $enabled_raw,
            ] );
            return false;
        }

        $required = [
            'google_ads_developer_token',
            'google_ads_client_id',
            'google_ads_client_secret',
            'google_ads_refresh_token',
            'google_ads_customer_id',
        ];

        $missing = [];
        foreach ( $required as $key ) {
            $value = (string) ( $stored[ $key ] ?? Settings::get( $key, '' ) );

            if ( $key === 'google_ads_customer_id' ) {
                $value = self::sanitize_customer_id( $value );
            } else {
                $value = trim( $value );
            }

            if ( $value === '' ) {
                $missing[] = $key;
            }
        }

        if ( ! empty( $missing ) ) {
            Logs::warn( 'google-ads', '[TMW-SEO-AUTO] Google Ads is_configured=false (missing required settings)', [
                'missing_keys' => $missing,
            ] );
            return false;
        }

        Logs::info( 'google-ads', '[TMW-SEO-AUTO] Google Ads is_configured=true (all required settings present)' );
        return true;
    }

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Generate keyword ideas for a seed keyword.
     * Returns items shaped for the KeywordIdeaProviderInterface pipeline.
     *
     * @param string $seed  Seed keyword to expand.
     * @param int    $limit Maximum results to return.
     * @return array{ok:bool,items?:array<int,array<string,mixed>>,error?:string}
     */
    public static function keyword_ideas( string $seed, int $limit = 200 ): array {
        if ( ! self::is_configured() ) {
            return [ 'ok' => false, 'error' => 'google_ads_not_configured' ];
        }

        $cache_key = 'tmwseo_gads_ideas_' . md5( $seed . '|' . $limit );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) { return $cached; }

        $access_token = self::get_access_token();
        if ( $access_token === null ) {
            return [ 'ok' => false, 'error' => 'google_ads_token_failed' ];
        }

        $request_result = self::request_keyword_ideas( $seed, $limit, $access_token );
        if ( ! (bool) ( $request_result['ok'] ?? false ) ) {
            return [ 'ok' => false, 'error' => (string) ( $request_result['error'] ?? 'google_ads_request_failed' ) ];
        }

        $data  = (array) ( $request_result['data'] ?? [] );
        $items = self::parse_keyword_ideas( (array) ( $data['results'] ?? [] ) );

        $result = [ 'ok' => true, 'items' => $items ];
        set_transient( $cache_key, $result, HOUR_IN_SECONDS );

        Logs::info( 'google-ads', 'Keyword ideas fetched', [
            'seed'  => $seed,
            'count' => count( $items ),
        ] );

        return $result;
    }

    /**
     * Read-only debug helper for admin testing screens.
     * This method does not write transients or options.
     *
     * @param string $seed  Seed keyword.
     * @param int    $limit Maximum idea count.
     * @return array<string,mixed>
     */
    public static function keyword_ideas_debug( string $seed, int $limit = 200 ): array {
        if ( ! self::is_configured() ) {
            return [
                'ok'                 => false,
                'error'              => 'google_ads_not_configured',
                'message'            => 'Google Ads integration is not configured or is disabled.',
                'http_status'        => null,
                'google_ads_error_code' => null,
                'raw_response'       => null,
            ];
        }

        $access_token = self::get_access_token( false );
        if ( $access_token === null ) {
            return [
                'ok'                 => false,
                'error'              => 'google_ads_token_failed',
                'message'            => 'Failed to obtain Google Ads OAuth access token.',
                'http_status'        => null,
                'google_ads_error_code' => null,
                'raw_response'       => null,
            ];
        }

        $request_result = self::request_keyword_ideas( $seed, $limit, $access_token );
        if ( ! (bool) ( $request_result['ok'] ?? false ) ) {
            return [
                'ok'                    => false,
                'error'                 => (string) ( $request_result['error'] ?? 'google_ads_request_failed' ),
                'message'               => (string) ( $request_result['message'] ?? 'Google Ads request failed.' ),
                'http_status'           => isset( $request_result['http_status'] ) ? (int) $request_result['http_status'] : null,
                'google_ads_error_code' => (string) ( $request_result['google_ads_error_code'] ?? '' ),
                'diagnostic_hints'      => (array) ( $request_result['diagnostic_hints'] ?? [] ),
                'raw_response'          => $request_result['raw_response'] ?? null,
            ];
        }

        $data = (array) ( $request_result['data'] ?? [] );
        return [
            'ok'                    => true,
            'items'                 => self::parse_keyword_ideas( (array) ( $data['results'] ?? [] ) ),
            'raw_response'          => $data,
            'http_status'           => (int) ( $request_result['http_status'] ?? 200 ),
            'google_ads_error_code' => null,
            'message'               => '',
        ];
    }

    /**
     * Enrich an array of keywords with volume + CPC data from Keyword Planner.
     * Returns a map of keyword => [ 'volume' => int, 'cpc' => float, 'competition' => float ].
     *
     * Used by: candidate metrics enrichment before opportunity scoring.
     *
     * @param string[] $keywords
     * @return array<string,array{volume:int,cpc:float,competition:float}>
     */
    public static function enrich_metrics( array $keywords ): array {
        if ( ! self::is_configured() || empty( $keywords ) ) {
            return [ 'metrics' => [] ];
        }

        $cache_key = 'tmwseo_gads_metrics_' . md5( implode( '|', $keywords ) );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) { return [ 'metrics' => $cached ]; }

        $access_token = self::get_access_token();
        if ( $access_token === null ) { return [ 'metrics' => [] ]; }

        $raw_customer_id       = (string) Settings::get( 'google_ads_customer_id', '' );
        $customer_id           = self::sanitize_customer_id( $raw_customer_id );
        $endpoint              = self::ads_api_base() . "/customers/{$customer_id}:generateKeywordIdeas";
        $login_customer_id_raw = (string) Settings::get( 'google_ads_login_customer_id', '' );
        $login_customer_id     = self::sanitize_customer_id( $login_customer_id_raw );

        $body = wp_json_encode( [
            'keywordSeed'        => [ 'keywords' => array_values( array_slice( $keywords, 0, 200 ) ) ],
            'language'           => 'languageConstants/1000',
            'keywordPlanNetwork' => 'GOOGLE_SEARCH',
            'pageSize'           => min( 1000, count( $keywords ) * 2 ),
        ] );

        $headers = [
            'Authorization'   => 'Bearer ' . $access_token,
            'developer-token' => (string) Settings::get( 'google_ads_developer_token', '' ),
            'Content-Type'    => 'application/json',
        ];
        if ( $login_customer_id !== '' ) {
            $headers['login-customer-id'] = $login_customer_id;
        }

        $diag = [
            'endpoint'              => $endpoint,
            'api_version'           => self::ADS_API_VERSION,
            'sanitized_customer_id' => $customer_id,
            'customer_id_raw_set'   => $raw_customer_id !== '',
            'login_customer_id_set' => $login_customer_id !== '',
            'login_customer_id_val' => $login_customer_id !== '' ? $login_customer_id : '(empty)',
            'keyword_count'         => count( $keywords ),
        ];

        Logs::info( 'google-ads', 'GenerateKeywordIdeas metrics request', $diag );

        $resp = wp_safe_remote_post( $endpoint, [
            'timeout' => 30,
            'headers' => $headers,
            'body'    => $body ?: '',
        ] );

        if ( is_wp_error( $resp ) ) {
            Logs::warn( 'google-ads', 'Google Ads Keyword Planner metrics request failed', array_merge( $diag, [
                'error' => $resp->get_error_message(),
            ] ) );
            return [ 'metrics' => [] ];
        }

        $http_code = (int) wp_remote_retrieve_response_code( $resp );
        $raw_body = (string) wp_remote_retrieve_body( $resp );
        if ( $http_code !== 200 ) {
            $error_reason = 'google_ads_http_' . $http_code;
            $diagnostic_message = '';
            if ( $http_code === 404 ) {
                $error_reason = 'google_ads_http_404_pending_or_unavailable';
                $diagnostic_message = 'Google Ads Keyword Planner request failed. Credentials may be valid, but API access may still be pending approval.';
                Logs::warn( 'google-ads', $diagnostic_message, array_merge( $diag, [
                    'http_code' => $http_code,
                    'body' => substr( $raw_body, 0, 500 ),
                ] ) );
            } else {
                Logs::warn( 'google-ads', 'Google Ads Keyword Planner metrics request returned non-200 response', array_merge( $diag, [
                    'http_code' => $http_code,
                    'body' => substr( $raw_body, 0, 500 ),
                ] ) );
            }

            return [
                'metrics' => [],
                'error_reason' => $error_reason,
                'diagnostic_message' => $diagnostic_message,
            ];
        }

        $data    = json_decode( $raw_body, true );
        $results = (array) ( $data['results'] ?? [] );
        $map     = [];

        foreach ( $results as $result ) {
            if ( ! is_array( $result ) ) { continue; }
            $kw     = strtolower( trim( (string) ( $result['text'] ?? '' ) ) );
            $metrics= (array) ( $result['keywordIdeaMetrics'] ?? [] );
            if ( $kw === '' ) { continue; }

            $map[ $kw ] = [
                'volume'      => (int) ( $metrics['avgMonthlySearches'] ?? 0 ),
                'cpc'         => (float) ( $metrics['averageCpc']['micros'] ?? 0 ) / 1_000_000,
                'competition' => self::competition_to_float( (string) ( $metrics['competition'] ?? 'UNKNOWN' ) ),
            ];
        }

        // Filter to requested keywords only.
        $out = [];
        foreach ( $keywords as $kw ) {
            $nk = strtolower( trim( $kw ) );
            if ( isset( $map[ $nk ] ) ) { $out[ $kw ] = $map[ $nk ]; }
        }

        set_transient( $cache_key, $out, HOUR_IN_SECONDS );
        return [ 'metrics' => $out ];
    }

    // ── OAuth2 Access Token ──────────────────────────────────────────────────

    /**
     * Get or refresh the Google Ads access token.
     * Returns null if the refresh fails.
     */
    private static function get_access_token( bool $allow_cache_write = true ): ?string {
        $cached = get_transient( self::TOKEN_CACHE_KEY );
        if ( is_string( $cached ) && $cached !== '' ) { return $cached; }

        $resp = wp_safe_remote_post( self::TOKEN_ENDPOINT, [
            'timeout' => 20,
            'body'    => [
                'grant_type'    => 'refresh_token',
                'client_id'     => (string) Settings::get( 'google_ads_client_id', '' ),
                'client_secret' => (string) Settings::get( 'google_ads_client_secret', '' ),
                'refresh_token' => (string) Settings::get( 'google_ads_refresh_token', '' ),
            ],
        ] );

        if ( is_wp_error( $resp ) ) {
            Logs::warn( 'google-ads', 'Token refresh failed', [ 'error' => $resp->get_error_message() ] );
            return null;
        }

        $data  = json_decode( wp_remote_retrieve_body( $resp ), true );
        $token = (string) ( $data['access_token'] ?? '' );

        if ( $token === '' ) {
            Logs::warn( 'google-ads', 'Token refresh returned empty access_token', [
                'response' => substr( wp_remote_retrieve_body( $resp ), 0, 300 ),
            ] );
            return null;
        }

        if ( $allow_cache_write ) {
            set_transient( self::TOKEN_CACHE_KEY, $token, self::CACHE_TTL );
        }
        return $token;
    }

    /**
     * Normalize a Google Ads customer ID for API usage.
     */
    private static function sanitize_customer_id( string $customer_id ): string {
        $customer_id = str_replace( '-', '', trim( $customer_id ) );
        return preg_replace( '/[^0-9]/', '', $customer_id ) ?? '';
    }

    /**
     * @return array<string,mixed>
     */
    private static function request_keyword_ideas( string $seed, int $limit, string $access_token ): array {
        $raw_customer_id = (string) Settings::get( 'google_ads_customer_id', '' );
        $customer_id     = self::sanitize_customer_id( $raw_customer_id );
        $endpoint        = self::ads_api_base() . "/customers/{$customer_id}:generateKeywordIdeas";

        $location_code = (int) ( Settings::get( 'dataforseo_location_code', '2840' ) ?: 2840 );
        $geo_target = "geoTargetConstants/{$location_code}";

        $request_body = [
            'keywordSeed'        => [ 'keywords' => [ $seed ] ],
            'language'           => 'languageConstants/1000',
            'geoTargetConstants' => [ $geo_target ],
            'keywordPlanNetwork' => 'GOOGLE_SEARCH',
            'pageSize'           => min( 1000, max( 1, $limit ) ),
        ];
        $body = wp_json_encode( $request_body );

        // Build headers — include login-customer-id when configured (required for MCC/manager accounts).
        $login_customer_id_raw = (string) Settings::get( 'google_ads_login_customer_id', '' );
        $login_customer_id     = self::sanitize_customer_id( $login_customer_id_raw );
        $dev_token             = (string) Settings::get( 'google_ads_developer_token', '' );

        $headers = [
            'Authorization'   => 'Bearer ' . $access_token,
            'developer-token' => $dev_token,
            'Content-Type'    => 'application/json',
        ];
        if ( $login_customer_id !== '' ) {
            $headers['login-customer-id'] = $login_customer_id;
        }

        // Diagnostic context — logged on every request for 404 forensics.
        $diag = [
            'endpoint'              => $endpoint,
            'api_version'           => self::ADS_API_VERSION,
            'customer_id_raw_set'   => $raw_customer_id !== '',
            'customer_id_sanitized' => $customer_id,
            'login_customer_id_set' => $login_customer_id !== '',
            'login_customer_id_val' => $login_customer_id !== '' ? $login_customer_id : '(not configured)',
            'dev_token_present'     => $dev_token !== '',
            'dev_token_prefix'      => $dev_token !== '' ? substr( $dev_token, 0, 6 ) . '...' : '(empty)',
            'refresh_token_present' => trim( (string) Settings::get( 'google_ads_refresh_token', '' ) ) !== '',
            'access_token_present'  => $access_token !== '',
            'geo_target'            => $geo_target,
            'language'              => 'languageConstants/1000',
            'seed'                  => $seed,
            'limit'                 => $limit,
            'body_keys'             => array_keys( $request_body ),
        ];

        Logs::info( 'google-ads', 'GenerateKeywordIdeas request (diagnostic)', $diag );

        $resp = wp_safe_remote_post( $endpoint, [
            'timeout' => 30,
            'headers' => $headers,
            'body'    => $body ?: '',
        ] );

        if ( is_wp_error( $resp ) ) {
            Logs::warn( 'google-ads', 'Keyword ideas WP HTTP error', array_merge( $diag, [
                'wp_error' => $resp->get_error_message(),
            ] ) );
            return [
                'ok'      => false,
                'error'   => 'google_ads_request_failed',
                'message' => $resp->get_error_message(),
            ];
        }

        $http_code = (int) wp_remote_retrieve_response_code( $resp );
        $raw_body  = wp_remote_retrieve_body( $resp );
        $data      = json_decode( $raw_body, true );
        if ( ! is_array( $data ) ) {
            $data = [];
        }

        Logs::info( 'google-ads', 'GenerateKeywordIdeas response', [
            'http_code'             => $http_code,
            'customer_id_sanitized' => $customer_id,
            'login_customer_id_set' => $login_customer_id !== '',
            'body_length'           => strlen( $raw_body ),
            'body_preview'          => substr( $raw_body, 0, 800 ),
        ] );

        if ( $http_code !== 200 ) {
            $error   = 'google_ads_http_' . $http_code;
            $message = (string) ( $data['error']['message'] ?? 'Google Ads HTTP error.' );
            $gads_error_code = (string) ( $data['error']['status'] ?? '' );

            // Provide structured diagnostic guidance for 404 specifically.
            $diagnostic_hints = [];
            if ( $http_code === 404 ) {
                $error = 'google_ads_http_404_pending_or_unavailable';
                if ( $login_customer_id === '' ) {
                    $diagnostic_hints[] = 'login-customer-id header is NOT set. If your developer token belongs to an MCC/manager account, this header is required.';
                }
                if ( strlen( $customer_id ) < 7 || strlen( $customer_id ) > 12 ) {
                    $diagnostic_hints[] = 'Customer ID length looks unusual (' . strlen( $customer_id ) . ' digits). Expected 7-10 digits.';
                }
                $diagnostic_hints[] = sprintf( 'API version is %s. If deprecated, update ADS_API_VERSION.', self::ADS_API_VERSION );
                $diagnostic_hints[] = 'If the developer token is pending approval or in test mode, the Keyword Planner endpoint returns 404.';
                $message = 'Google Ads Keyword Planner 404. See diagnostic_hints in log for possible causes.';
            }

            Logs::warn( 'google-ads', 'Keyword ideas HTTP error (diagnostic)', [
                'http_code'             => $http_code,
                'gads_error_code'       => $gads_error_code,
                'gads_error_message'    => $message,
                'customer_id_sanitized' => $customer_id,
                'login_customer_id_set' => $login_customer_id !== '',
                'dev_token_prefix'      => $dev_token !== '' ? substr( $dev_token, 0, 6 ) . '...' : '(empty)',
                'geo_target'            => $geo_target,
                'api_version'           => self::ADS_API_VERSION,
                'diagnostic_hints'      => $diagnostic_hints,
                'response_body'         => substr( $raw_body, 0, 1200 ),
            ] );

            return [
                'ok'                    => false,
                'error'                 => $error,
                'http_status'           => $http_code,
                'raw_response'          => $data,
                'message'               => $message,
                'google_ads_error_code' => $gads_error_code,
                'diagnostic_hints'      => $diagnostic_hints,
            ];
        }

        return [
            'ok'          => true,
            'http_status' => $http_code,
            'data'        => $data,
        ];
    }

    // ── Parsers ─────────────────────────────────────────────────────────────

    /**
     * @param array<int,mixed> $results
     * @return array<int,array<string,mixed>>
     */
    private static function parse_keyword_ideas( array $results ): array {
        $items = [];
        foreach ( $results as $result ) {
            if ( ! is_array( $result ) ) { continue; }
            $kw      = trim( (string) ( $result['text'] ?? '' ) );
            $metrics = (array) ( $result['keywordIdeaMetrics'] ?? [] );
            if ( $kw === '' ) { continue; }

            $volume = (int) ( $metrics['avgMonthlySearches'] ?? 0 );
            $cpc    = (float) ( $metrics['averageCpc']['micros'] ?? 0 ) / 1_000_000;
            $low_cpc  = (float) ( $metrics['lowTopOfPageBidMicros'] ?? 0 ) / 1_000_000;
            $high_cpc = (float) ( $metrics['highTopOfPageBidMicros'] ?? 0 ) / 1_000_000;
            $competition_enum = (string) ( $metrics['competition'] ?? 'UNKNOWN' );
            $comp   = self::competition_to_float( (string) ( $metrics['competition'] ?? 'UNKNOWN' ) );

            $items[] = [
                'keyword'                => $kw,
                '_tmw_relationship_type' => 'suggestion',
                '_tmw_volume_source'     => 'google_keyword_planner',
                '_tmw_cpc_source'        => 'google_keyword_planner',
                'keyword_info'           => [
                    'search_volume'      => $volume,
                    'cpc'                => $cpc,
                    'competition'        => $comp,
                    'competition_label'  => strtoupper( $competition_enum ),
                    'low_top_of_page_bid'=> $low_cpc,
                    'high_top_of_page_bid'=> $high_cpc,
                    'keyword_difficulty' => null, // Planner doesn't provide KD
                ],
            ];
        }
        return $items;
    }

    /** Convert Keyword Planner competition enum to 0.0–1.0 float. */
    private static function competition_to_float( string $competition ): float {
        return match ( strtoupper( $competition ) ) {
            'HIGH'   => 0.85,
            'MEDIUM' => 0.50,
            'LOW'    => 0.20,
            default  => 0.0,
        };
    }

    private static function ads_api_base(): string {
        return self::ADS_API_HOST . '/' . self::ADS_API_VERSION;
    }
}
