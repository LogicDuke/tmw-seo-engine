<?php
/**
 * Google Indexing API — requests immediate crawl when a post is published.
 *
 * Setup:
 *   1. Create a Google Cloud service account with Indexing API enabled.
 *   2. Add the service account email as a verified owner in GSC.
 *   3. Paste the service account JSON key into Settings.
 *
 * @package TMWSEO\Engine\Integrations
 */
namespace TMWSEO\Engine\Integrations;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Logs;

class GoogleIndexingAPI {

    const API_URL          = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
    const TOKEN_URL        = 'https://oauth2.googleapis.com/token';
    const SCOPE            = 'https://www.googleapis.com/auth/indexing';
    const TOKEN_OPTION     = 'tmwseo_indexing_api_token';
    const LOG_OPTION       = 'tmwseo_indexing_api_log';

    // Post types that should trigger indexing pings
    private static function eligible_post_types(): array {
        $custom = (string) Settings::get( 'indexing_api_post_types', 'model,video,tmw_video' );
        return array_values( array_filter( array_map( 'trim', explode( ',', $custom ) ) ) );
    }

    // ── WordPress hooks ────────────────────────────────────────────────────

    public static function init(): void {
        add_action( 'transition_post_status', [ __CLASS__, 'on_post_status_change' ], 10, 3 );
        // CLI command to bulk-submit existing posts
        add_action( 'wp_ajax_tmwseo_indexing_submit', [ __CLASS__, 'handle_ajax_submit' ] );
    }

    public static function on_post_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
        if ( $new_status !== 'publish' ) return;
        if ( ! in_array( $post->post_type, self::eligible_post_types(), true ) ) return;
        if ( ! self::is_configured() ) return;

        // Debounce: skip if pinged within last hour
        $last = (int) get_post_meta( $post->ID, '_tmwseo_indexing_pinged_at', true );
        if ( $last > 0 && ( time() - $last ) < HOUR_IN_SECONDS ) return;

        $url = get_permalink( $post->ID );
        if ( ! $url ) return;

        self::submit_url( $url, 'URL_UPDATED' );
        update_post_meta( $post->ID, '_tmwseo_indexing_pinged_at', time() );
    }

    // ── AJAX handler (manual submit from Tools page) ──────────────────────

    public static function handle_ajax_submit(): void {
        check_ajax_referer( 'tmwseo_indexing_submit', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( $post_id <= 0 ) wp_send_json_error( 'Invalid post_id' );

        $url = get_permalink( $post_id );
        if ( ! $url ) wp_send_json_error( 'No permalink' );

        $result = self::submit_url( $url, 'URL_UPDATED' );
        if ( $result['ok'] ) {
            update_post_meta( $post_id, '_tmwseo_indexing_pinged_at', time() );
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    // ── Core submit ────────────────────────────────────────────────────────

    /**
     * Submits a URL to the Indexing API.
     *
     * @param string $type  URL_UPDATED | URL_DELETED
     */
    public static function submit_url( string $url, string $type = 'URL_UPDATED' ): array {
        if ( ! self::is_configured() ) {
            return [ 'ok' => false, 'error' => 'indexing_api_not_configured' ];
        }

        $token = self::get_access_token();
        if ( ! $token ) {
            return [ 'ok' => false, 'error' => 'indexing_api_token_failed' ];
        }

        $resp = wp_remote_post( self::API_URL, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [ 'url' => $url, 'type' => $type ] ),
        ] );

        $result = self::parse_response( $resp, $url, $type );
        self::log_result( $url, $type, $result );

        return $result;
    }

    // ── Configuration ──────────────────────────────────────────────────────

    public static function is_configured(): bool {
        $json = trim( (string) Settings::get( 'google_indexing_service_account_json', '' ) );
        return $json !== '' && strpos( $json, '"private_key"' ) !== false;
    }

    // ── Token via service account JWT ─────────────────────────────────────

    private static function get_access_token(): string {
        $cached = get_transient( self::TOKEN_OPTION );
        if ( $cached ) return (string) $cached;

        $raw = trim( (string) Settings::get( 'google_indexing_service_account_json', '' ) );
        $sa  = json_decode( $raw, true );
        if ( ! is_array( $sa ) || empty( $sa['private_key'] ) || empty( $sa['client_email'] ) ) {
            return '';
        }

        $jwt = self::build_jwt( $sa['client_email'], $sa['private_key'] );
        if ( $jwt === '' ) return '';

        $resp = wp_remote_post( self::TOKEN_URL, [
            'timeout' => 15,
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
        ] );

        if ( is_wp_error( $resp ) ) {
            Logs::error( 'indexing_api', 'JWT token exchange failed', [ 'error' => $resp->get_error_message() ] );
            return '';
        }

        $body  = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
        $token = (string) ( $body['access_token'] ?? '' );
        if ( $token === '' ) return '';

        $expires = (int) ( $body['expires_in'] ?? 3600 ) - 60;
        set_transient( self::TOKEN_OPTION, $token, max( 60, $expires ) );

        return $token;
    }

    /**
     * Builds a signed JWT for service account auth.
     * Uses openssl if available (required), otherwise returns ''.
     */
    private static function build_jwt( string $client_email, string $private_key ): string {
        if ( ! function_exists( 'openssl_sign' ) ) {
            Logs::error( 'indexing_api', 'openssl_sign() not available — cannot sign JWT.' );
            return '';
        }

        $now = time();

        $header  = self::base64url_encode( wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
        $payload = self::base64url_encode( wp_json_encode( [
            'iss'   => $client_email,
            'sub'   => $client_email,
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ] ) );

        $signing_input = $header . '.' . $payload;
        $signature     = '';

        $key = openssl_pkey_get_private( $private_key );
        if ( ! $key ) {
            Logs::error( 'indexing_api', 'Failed to load service account private key.' );
            return '';
        }

        if ( ! openssl_sign( $signing_input, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
            return '';
        }

        return $signing_input . '.' . self::base64url_encode( $signature );
    }

    private static function base64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    // ── Response + logging ─────────────────────────────────────────────────

    private static function parse_response( $resp, string $url, string $type ): array {
        if ( is_wp_error( $resp ) ) {
            return [ 'ok' => false, 'error' => $resp->get_error_message(), 'url' => $url ];
        }

        $code = (int) wp_remote_retrieve_response_code( $resp );
        $raw  = (string) wp_remote_retrieve_body( $resp );
        $json = json_decode( $raw, true );

        if ( $code === 200 ) {
            return [
                'ok'         => true,
                'url'        => $url,
                'type'       => $type,
                'notify_time'=> $json['urlNotificationMetadata']['latestUpdate']['notifyTime'] ?? '',
            ];
        }

        Logs::error( 'indexing_api', 'Submit failed', [
            'url'  => $url,
            'code' => $code,
            'body' => substr( $raw, 0, 300 ),
        ] );

        return [ 'ok' => false, 'error' => 'api_error', 'code' => $code, 'url' => $url, 'body' => $raw ];
    }

    private static function log_result( string $url, string $type, array $result ): void {
        $log = (array) get_option( self::LOG_OPTION, [] );
        array_unshift( $log, [
            'url'    => $url,
            'type'   => $type,
            'ok'     => $result['ok'],
            'error'  => $result['error'] ?? '',
            'ts'     => current_time( 'mysql' ),
        ] );
        $log = array_slice( $log, 0, 200 );
        update_option( self::LOG_OPTION, $log, false );
    }
}
