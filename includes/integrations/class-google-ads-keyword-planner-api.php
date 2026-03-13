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
 *  POST https://googleads.googleapis.com/v16/customers/{customer_id}:generateKeywordIdeas
 *  POST https://googleads.googleapis.com/v16/customers/{customer_id}/googleAds:searchStream
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
    private const ADS_API_BASE      = 'https://googleads.googleapis.com/v16';
    private const TOKEN_CACHE_KEY   = 'tmwseo_google_ads_access_token';
    private const CACHE_TTL         = 55 * MINUTE_IN_SECONDS; // access tokens last 60 min

    // ── Configuration ───────────────────────────────────────────────────────

    public static function is_enabled(): bool {
        return (bool) Settings::get( 'google_ads_enabled', 0 );
    }

    public static function is_configured(): bool {
        if ( ! self::is_enabled() ) { return false; }
        $required = [
            'google_ads_developer_token',
            'google_ads_client_id',
            'google_ads_client_secret',
            'google_ads_refresh_token',
            'google_ads_customer_id',
        ];
        foreach ( $required as $key ) {
            if ( trim( (string) Settings::get( $key, '' ) ) === '' ) {
                return false;
            }
        }
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
            return [];
        }

        $cache_key = 'tmwseo_gads_metrics_' . md5( implode( '|', $keywords ) );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) { return $cached; }

        $access_token = self::get_access_token();
        if ( $access_token === null ) { return []; }

        $customer_id = preg_replace( '/[^0-9]/', '', (string) Settings::get( 'google_ads_customer_id', '' ) );
        $endpoint    = self::ADS_API_BASE . "/customers/{$customer_id}:generateKeywordIdeas";

        $body = wp_json_encode( [
            'keywordSeed'        => [ 'keywords' => array_values( array_slice( $keywords, 0, 200 ) ) ],
            'language'           => 'languageConstants/1000',
            'keywordPlanNetwork' => 'GOOGLE_SEARCH',
            'pageSize'           => min( 1000, count( $keywords ) * 2 ),
        ] );

        $resp = wp_safe_remote_post( $endpoint, [
            'timeout' => 30,
            'headers' => [
                'Authorization'   => 'Bearer ' . $access_token,
                'developer-token' => (string) Settings::get( 'google_ads_developer_token', '' ),
                'Content-Type'    => 'application/json',
            ],
            'body'    => $body ?: '',
        ] );

        if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) {
            return [];
        }

        $data    = json_decode( wp_remote_retrieve_body( $resp ), true );
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
        return $out;
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
     * @return array<string,mixed>
     */
    private static function request_keyword_ideas( string $seed, int $limit, string $access_token ): array {
        $customer_id = preg_replace( '/[^0-9]/', '', (string) Settings::get( 'google_ads_customer_id', '' ) );
        $endpoint    = self::ADS_API_BASE . "/customers/{$customer_id}:generateKeywordIdeas";

        $location_code = (int) ( Settings::get( 'dataforseo_location_code', '2840' ) ?: 2840 );
        $geo_target = "geoTargetConstants/{$location_code}";

        $body = wp_json_encode( [
            'keywordSeed'        => [ 'keywords' => [ $seed ] ],
            'language'           => 'languageConstants/1000',
            'geoTargetConstants' => [ $geo_target ],
            'keywordPlanNetwork' => 'GOOGLE_SEARCH',
            'pageSize'           => min( 1000, max( 1, $limit ) ),
        ] );

        Logs::info( 'google-ads', 'GenerateKeywordIdeas request', [
            'endpoint'    => $endpoint,
            'customer_id' => $customer_id,
            'seed'        => $seed,
            'limit'       => $limit,
        ] );

        $resp = wp_safe_remote_post( $endpoint, [
            'timeout' => 30,
            'headers' => [
                'Authorization'   => 'Bearer ' . $access_token,
                'developer-token' => (string) Settings::get( 'google_ads_developer_token', '' ),
                'Content-Type'    => 'application/json',
            ],
            'body' => $body ?: '',
        ] );

        if ( is_wp_error( $resp ) ) {
            Logs::warn( 'google-ads', 'Keyword ideas request failed', [
                'endpoint'    => $endpoint,
                'customer_id' => $customer_id,
                'seed'  => $seed,
                'error' => $resp->get_error_message(),
            ] );
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
            'endpoint'    => $endpoint,
            'customer_id' => $customer_id,
            'http_code'   => $http_code,
            'body'        => substr( $raw_body, 0, 500 ),
        ] );

        if ( $http_code !== 200 ) {
            Logs::warn( 'google-ads', 'Keyword ideas HTTP error', [
                'seed'      => $seed,
                'http_code' => $http_code,
                'body'      => substr( $raw_body, 0, 500 ),
            ] );

            return [
                'ok'                    => false,
                'error'                 => 'google_ads_http_' . $http_code,
                'http_status'           => $http_code,
                'raw_response'          => $data,
                'message'               => (string) ( $data['error']['message'] ?? 'Google Ads HTTP error.' ),
                'google_ads_error_code' => (string) ( $data['error']['status'] ?? '' ),
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
}
