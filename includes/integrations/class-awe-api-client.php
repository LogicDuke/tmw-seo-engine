<?php
/**
 * AWE / AWEmpire Direct API Client.
 *
 * Sends authorized requests to the AWE API on behalf of TMW SEO Engine.
 * Credentials are read exclusively from TMW SEO Engine settings —
 * this class has no dependency on WPS LiveJasmin or any other plugin.
 *
 * Security invariants:
 *  - accessKey is NEVER logged, included in error messages, or printed.
 *  - Error reports include only the endpoint path, not the full query string.
 *  - All public methods return ['ok'=>bool,...] and never throw.
 *  - Caching uses WordPress transients keyed by a hash — no credentials in keys.
 *
 * TODO (future phase): optional import of WPS LiveJasmin psid/accessKey
 * when TMW AWE credentials are empty and WPS is active. Not implemented here.
 *
 * @package TMWSEO\Engine\Integrations
 * @since   5.7.0
 */

namespace TMWSEO\Engine\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Logs;

class AweApiClient {

	// ── Known endpoint paths (no credentials) ─────────────────────────────────
	const ENDPOINT_MODEL_LIST    = '/api/modelList/v2/';
	const ENDPOINT_MODEL_PROFILE = '/api/model/v2/';
	const ENDPOINT_FEED          = '/api/feed/v2/';

	// ── Transient prefix ──────────────────────────────────────────────────────
	private const CACHE_PREFIX = 'tmwseo_awe_';

	// ── Configuration helpers ─────────────────────────────────────────────────

	/**
	 * Returns true when both psid and access_key are configured.
	 */
	public static function is_configured(): bool {
		return self::psid() !== '' && self::access_key() !== '';
	}

	/**
	 * Returns true when the connector is enabled AND configured.
	 */
	public static function is_active(): bool {
		return (bool) Settings::get( 'tmwseo_awe_enabled', 0 ) && self::is_configured();
	}

	// ── Public API ─────────────────────────────────────────────────────────────

	/**
	 * Lightweight connectivity test.
	 *
	 * Fires a cheap request (model list, limit=1) and reports the result.
	 *
	 * @return array{ok:bool,status:int|null,message:string}
	 */
	public static function test(): array {
		if ( ! self::is_configured() ) {
			return [
				'ok'      => false,
				'status'  => null,
				'message' => 'AWE connector is not configured — psid and/or access_key missing.',
			];
		}

		$url  = self::build_request_url( self::ENDPOINT_MODEL_LIST, [ 'limit' => 1 ] );
		$resp = self::remote_get( $url );

		if ( ! $resp['ok'] ) {
			return [
				'ok'      => false,
				'status'  => $resp['status'] ?? null,
				'message' => $resp['error'],
			];
		}

		return [
			'ok'      => true,
			'status'  => $resp['status'],
			'message' => 'AWE API connection successful.',
		];
	}

	/**
	 * Fetch a list of models from AWE.
	 *
	 * @param array<string,mixed> $params  Extra query params (limit, offset, …).
	 * @return array{ok:bool,data:array,error:string,status:int|null}
	 */
	public static function fetch_model_list( array $params = [] ): array {
		if ( ! self::is_configured() ) {
			return self::not_configured();
		}

		$cache_key = self::cache_key( 'list', $params );
		$cached    = self::get_cached( $cache_key );
		if ( $cached !== false ) {
			return array_merge( $cached, [ '_cached' => true ] );
		}

		$url    = self::build_request_url( self::ENDPOINT_MODEL_LIST, $params );
		$result = self::remote_get( $url );

		if ( $result['ok'] ) {
			self::set_cached( $cache_key, $result );
		}

		return $result;
	}

	/**
	 * Fetch a model/performer profile from AWE.
	 *
	 * @param string $identifier  Performer name, performer ID, or slug.
	 * @param string $id_type     'name' | 'id' | 'username'
	 * @return array{ok:bool,data:array,error:string,status:int|null}
	 */
	public static function fetch_model_profile( string $identifier, string $id_type = 'name' ): array {
		if ( ! self::is_configured() ) {
			return self::not_configured();
		}
		if ( $identifier === '' ) {
			return self::error( 'identifier_empty', null );
		}

		$params    = self::profile_params( $identifier, $id_type );
		$cache_key = self::cache_key( 'profile', $params );
		$cached    = self::get_cached( $cache_key );
		if ( $cached !== false ) {
			return array_merge( $cached, [ '_cached' => true ] );
		}

		// Try dedicated profile endpoint first; fall back to model list with name filter.
		$url    = self::build_request_url( self::ENDPOINT_MODEL_PROFILE, $params );
		$result = self::remote_get( $url );

		// If profile endpoint is 404/unsupported, try model list with name search.
		if ( ! $result['ok'] && in_array( $result['status'] ?? null, [ 404, 400, 405 ], true ) ) {
			$list_params = [ 'modelName' => $identifier, 'limit' => 5 ];
			$url         = self::build_request_url( self::ENDPOINT_MODEL_LIST, $list_params );
			$result      = self::remote_get( $url );
		}

		if ( $result['ok'] ) {
			self::set_cached( $cache_key, $result );
		}

		return $result;
	}

	/**
	 * Build an authorized AWE request URL.
	 *
	 * The access key is added to the query string (AWE requirement) but
	 * is NEVER passed to any logging or error-reporting function.
	 * Callers that need to report errors should use only the endpoint path.
	 *
	 * @param string              $endpoint  Path component only (e.g. '/api/model/v2/').
	 * @param array<string,mixed> $extra     Additional query params (no credentials).
	 * @return string  Full URL with credentials — handle with care.
	 */
	public static function build_request_url( string $endpoint, array $extra = [] ): string {
		$base = rtrim( (string) Settings::get( 'tmwseo_awe_base_url', 'https://pt.ptawe.com' ), '/' );

		$params = array_merge(
			[
				'psid'       => self::psid(),
				'accessKey'  => self::access_key(),
				'psprogram'  => (string) Settings::get( 'tmwseo_awe_psprogram', 'PPL' ),
				'language'   => (string) Settings::get( 'tmwseo_awe_language', 'en' ),
			],
			$extra
		);

		return $base . $endpoint . '?' . http_build_query( $params );
	}

	/**
	 * Return the endpoint path only — safe for logging and error messages.
	 */
	public static function safe_endpoint( string $endpoint ): string {
		return $endpoint . ' [credentials redacted]';
	}

	// ── Cache helpers ──────────────────────────────────────────────────────────

	/**
	 * @return mixed  Cached value or false on miss.
	 */
	public static function get_cached( string $cache_key ) {
		return get_transient( $cache_key );
	}

	/**
	 * Store a result in the transient cache.
	 */
	public static function set_cached( string $cache_key, array $data ): void {
		$ttl = max( 60, (int) Settings::get( 'tmwseo_awe_cache_ttl', 3600 ) );
		set_transient( $cache_key, $data, $ttl );
	}

	/**
	 * Invalidate all AWE transient caches for a given scope.
	 */
	public static function flush_cache( string $scope = '' ): void {
		// WordPress does not support prefix-based transient deletion,
		// so we delete known scope keys only when provided.
		if ( $scope !== '' ) {
			delete_transient( self::CACHE_PREFIX . $scope );
		}
	}

	// ── HTTP transport ─────────────────────────────────────────────────────────

	/**
	 * Execute an authorized GET to the supplied URL.
	 *
	 * The URL contains credentials. This method ensures they are NEVER
	 * passed to any log line or error string.
	 *
	 * @return array{ok:bool,data:array,error:string,status:int|null}
	 */
	private static function remote_get( string $url ): array {
		// Extract endpoint path for safe reporting (before using $url in wp_remote_get).
		$endpoint_path = (string) parse_url( $url, PHP_URL_PATH );

		$timeout  = max( 5, (int) Settings::get( 'tmwseo_awe_timeout', 15 ) );
		$response = wp_remote_get( $url, [
			'timeout'    => $timeout,
			'user-agent' => 'TMW-SEO-Engine/' . ( defined( 'TMWSEO_ENGINE_VERSION' ) ? TMWSEO_ENGINE_VERSION : 'unknown' ),
		] );

		if ( is_wp_error( $response ) ) {
			// Log endpoint path only — never $url.
			if ( class_exists( Logs::class ) ) {
				Logs::error( 'awe_api', 'WP HTTP error on ' . $endpoint_path, [ 'msg' => $response->get_error_message() ] );
			}
			return self::error( $response->get_error_message(), null );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$json   = json_decode( $raw, true );

		if ( $status < 200 || $status >= 300 ) {
			if ( class_exists( Logs::class ) ) {
				Logs::error( 'awe_api', "HTTP {$status} on {$endpoint_path}", [] );
			}
			return self::error( "HTTP {$status} on {$endpoint_path}", $status );
		}

		if ( ! is_array( $json ) ) {
			if ( class_exists( Logs::class ) ) {
				Logs::error( 'awe_api', "Non-JSON response on {$endpoint_path}", [ 'snippet' => substr( $raw, 0, 200 ) ] );
			}
			return self::error( "Non-JSON response from {$endpoint_path}", $status );
		}

		return [
			'ok'     => true,
			'data'   => $json,
			'error'  => '',
			'status' => $status,
		];
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	private static function psid(): string {
		return trim( (string) Settings::get( 'tmwseo_awe_psid', '' ) );
	}

	private static function access_key(): string {
		return trim( (string) Settings::get( 'tmwseo_awe_access_key', '' ) );
	}

	/**
	 * Build profile query params for the given identifier type.
	 *
	 * @param string $identifier
	 * @param string $id_type  'name' | 'id' | 'username'
	 * @return array<string,mixed>
	 */
	private static function profile_params( string $identifier, string $id_type ): array {
		switch ( $id_type ) {
			case 'id':
				return [ 'performerId' => $identifier ];
			case 'username':
				return [ 'username' => $identifier, 'limit' => 1 ];
			default: // name
				return [ 'modelName' => $identifier, 'limit' => 1 ];
		}
	}

	/**
	 * Generate a stable, credential-free cache key.
	 *
	 * @param array<string,mixed> $params
	 */
	private static function cache_key( string $scope, array $params ): string {
		// Exclude auth params from cache key so the hash is safe to log/inspect.
		$safe_params = array_diff_key( $params, array_flip( [ 'psid', 'accessKey', 'psprogram' ] ) );
		return self::CACHE_PREFIX . $scope . '_' . md5( serialize( $safe_params ) );
	}

	/**
	 * Return a not-configured error response.
	 *
	 * @return array{ok:bool,data:array,error:string,status:null}
	 */
	private static function not_configured(): array {
		return self::error( 'AWE connector not configured — psid or access_key missing.', null );
	}

	/**
	 * Build a safe error response.
	 *
	 * @return array{ok:bool,data:array,error:string,status:int|null}
	 */
	private static function error( string $message, ?int $status ): array {
		return [
			'ok'     => false,
			'data'   => [],
			'error'  => $message,
			'status' => $status,
		];
	}
}
