<?php
/**
 * AWE Profile Evidence Extractor.
 *
 * Fetches AWE model/profile API data for a given post and normalizes it
 * into a structured evidence array suitable for admin review.
 *
 * Security invariants:
 *  - Normalized evidence is admin-review material only — never front-end output.
 *  - Raw bio candidates are flagged but not auto-published.
 *  - admin_debug_inspect() strips credentials and truncates raw text.
 *  - Access key never appears in any output from this class.
 *
 * Storage model (per approval):
 *  - Full raw AWE payload is stored in a short-lived transient (admin debug only).
 *  - Normalized evidence is stored as post meta for traceability.
 *  - Reviewed bio summary is a separate operator-written field.
 *
 * @package TMWSEO\Engine\Integrations
 * @since   5.7.0
 */

namespace TMWSEO\Engine\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TMWSEO\Engine\Services\Settings;

class AweProfileEvidence {

	// ── Post meta keys ─────────────────────────────────────────────────────────
	// These supplement the existing _tmwseo_bio_* keys defined in ModelHelper.
	const META_AWE_FETCHED_AT       = '_tmwseo_awe_evidence_fetched_at';
	const META_AWE_AVAILABLE_FIELDS = '_tmwseo_awe_available_fields';
	const META_AWE_CONFIDENCE       = '_tmwseo_awe_evidence_confidence';
	const META_AWE_EVIDENCE_HASH    = '_tmwseo_bio_raw_evidence_hash';

	// ── Transient key for short-lived debug payload ────────────────────────────
	private const DEBUG_TRANSIENT_TTL = 300; // 5 minutes
	private const DEBUG_TRANSIENT_PFX = 'tmwseo_awe_debug_';

	/**
	 * Bio-like field names we probe for in AWE responses.
	 * Order is significance: earlier = stronger signal.
	 */
	private const BIO_FIELD_CANDIDATES = [
		'bio',
		'description',
		'about',
		'introduction',
		'profileText',
		'modelDescription',
		'performerDescription',
		'turnOns',
	];

	/**
	 * All profile field names we probe for, including non-bio.
	 */
	private const ALL_PROBE_FIELDS = [
		'performerName', 'performerId', 'modelName', 'username',
		'bio', 'description', 'about', 'introduction', 'profileText',
		'modelDescription', 'performerDescription', 'turnOns',
		'tags', 'categories', 'profileImage', 'targetUrl', 'detailsUrl',
		'online', 'status', 'isOnline',
	];

	// ── Public API ─────────────────────────────────────────────────────────────

	/**
	 * Fetch AWE evidence for a model post and store normalized meta.
	 *
	 * Does NOT write bio_summary or bio_review_status — those remain
	 * under operator control.
	 *
	 * @param int    $post_id
	 * @param string $performer_name
	 * @param string $platform_url  Optional verified LiveJasmin URL hint.
	 * @return array{ok:bool,evidence:array,error:string}
	 */
	public static function fetch_evidence( int $post_id, string $performer_name, string $platform_url = '' ): array {
		if ( ! AweApiClient::is_configured() ) {
			return [ 'ok' => false, 'evidence' => [], 'error' => 'AWE connector not configured.' ];
		}
		if ( $performer_name === '' ) {
			return [ 'ok' => false, 'evidence' => [], 'error' => 'performer_name is required.' ];
		}

		// Determine identifier: prefer username extracted from platform URL.
		$username = self::extract_username_from_url( $platform_url );
		$id_type  = ( $username !== '' ) ? 'username' : 'name';
		$id_value = ( $username !== '' ) ? $username : $performer_name;

		$api_result = AweApiClient::fetch_model_profile( $id_value, $id_type );

		if ( ! $api_result['ok'] ) {
			return [ 'ok' => false, 'evidence' => [], 'error' => $api_result['error'] ];
		}

		$raw_data = $api_result['data'];

		// Normalize.
		$evidence = self::normalize( $raw_data, $performer_name, $platform_url );

		// Store normalized meta.
		self::save_evidence_meta( $post_id, $evidence, $raw_data );

		// Store short-lived debug transient (admin-only inspection).
		self::save_debug_transient( $post_id, $raw_data, $evidence );

		return [ 'ok' => true, 'evidence' => $evidence, 'error' => '' ];
	}

	/**
	 * Probe an AWE API response for bio-like and other profile fields.
	 *
	 * Returns two lists: fields found and fields missing from ALL_PROBE_FIELDS.
	 *
	 * @param array<string,mixed> $api_response  Raw decoded API response.
	 * @return array{found:string[],missing:string[],has_bio:bool}
	 */
	public static function probe_fields( array $api_response ): array {
		// AWE may return a top-level array of models; inspect the first item.
		$record = self::extract_first_record( $api_response );

		$found   = [];
		$missing = [];

		foreach ( self::ALL_PROBE_FIELDS as $field ) {
			if ( self::field_has_value( $record, $field ) ) {
				$found[] = $field;
			} else {
				$missing[] = $field;
			}
		}

		$has_bio = ! empty( array_intersect( self::BIO_FIELD_CANDIDATES, $found ) );

		return [
			'found'   => $found,
			'missing' => $missing,
			'has_bio' => $has_bio,
		];
	}

	/**
	 * Normalize a raw AWE API response into a safe evidence array.
	 *
	 * The returned array is admin-review material only.
	 * raw_bio_candidate is present for operator review — it must NEVER be
	 * auto-published as page content.
	 *
	 * @param array<string,mixed> $api_response
	 * @param string              $performer_name  Known performer name (fallback).
	 * @param string              $platform_url    Known platform URL hint.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $api_response, string $performer_name = '', string $platform_url = '' ): array {
		$record = self::extract_first_record( $api_response );
		$probe  = self::probe_fields( $api_response );

		// ── Identity ──────────────────────────────────────────────────────────
		$resolved_name = self::coalesce_string( $record, [ 'performerName', 'modelName', 'username' ] )
			?: $performer_name;

		$resolved_url = self::coalesce_string( $record, [ 'targetUrl', 'detailsUrl' ] )
			?: $platform_url;

		// ── Bio candidate ─────────────────────────────────────────────────────
		$raw_bio       = '';
		$bio_field_hit = '';
		foreach ( self::BIO_FIELD_CANDIDATES as $field ) {
			$val = self::safe_string( $record, $field );
			if ( $val !== '' ) {
				$raw_bio       = $val;
				$bio_field_hit = $field;
				break;
			}
		}

		// ── Media / taxonomy ──────────────────────────────────────────────────
		$tags       = self::coalesce_array( $record, [ 'tags', 'categories' ] );
		$profile_img = self::coalesce_string( $record, [ 'profileImage', 'thumbnailUrl', 'imageUrl' ] );

		// ── Confidence ────────────────────────────────────────────────────────
		$confidence = self::get_confidence_from_probe( $probe );

		// Source label without credentials.
		$base_url     = rtrim( (string) Settings::get( 'tmwseo_awe_base_url', 'https://pt.ptawe.com' ), '/' );
		$source_label = 'AWE / AWEmpire API';
		$source_url   = $base_url . AweApiClient::ENDPOINT_MODEL_PROFILE . ' [authorized request]';

		return [
			'source_type'           => 'awe_api',
			'source_label'          => $source_label,
			'source_url'            => $source_url,
			'performer_name'        => $resolved_name,
			'profile_url'           => $resolved_url,
			// raw_bio_candidate: admin-review only; do NOT publish directly.
			'raw_bio_candidate'     => $raw_bio,
			'bio_field_hit'         => $bio_field_hit,
			'has_bio_candidate'     => $raw_bio !== '',
			// Excerpt safe for admin panel display (first 200 chars, no HTML).
			'bio_excerpt_admin'     => $raw_bio !== '' ? wp_strip_all_tags( mb_substr( $raw_bio, 0, 200 ) ) : '',
			'tags'                  => $tags,
			'profile_image'         => $profile_img,
			'fetched_at'            => current_time( 'mysql' ),
			'confidence'            => $confidence,
			'available_fields'      => $probe['found'],
			'missing_fields'        => $probe['missing'],
			'has_bio'               => $probe['has_bio'],
		];
	}

	/**
	 * Derive a confidence label from a probe result.
	 *
	 * @param array{found:string[],missing:string[],has_bio:bool} $probe
	 * @return string  'high' | 'medium' | 'low' | 'none'
	 */
	public static function get_confidence( array $probe ): string {
		return self::get_confidence_from_probe( $probe );
	}

	/**
	 * Return admin-only debug info for a raw AWE response.
	 *
	 * Credentials and full raw text are stripped.
	 * This output is safe for admin-only display (never front-end).
	 *
	 * @param array<string,mixed> $api_response
	 * @param int|null            $http_status
	 * @param bool                $is_cached
	 * @return array<string,mixed>
	 */
	public static function admin_debug_inspect( array $api_response, ?int $http_status = null, bool $is_cached = false ): array {
		$probe   = self::probe_fields( $api_response );
		$record  = self::extract_first_record( $api_response );
		$top_keys = array_keys( $api_response );
		$data_keys = is_array( $record ) ? array_keys( $record ) : [];

		// Redact any key that could be a credential.
		$safe_data_keys = array_filter( $data_keys, static fn( $k ) => ! in_array(
			strtolower( (string) $k ),
			[ 'accesskey', 'access_key', 'psid', 'password', 'token', 'secret' ],
			true
		) );

		return [
			'endpoint'        => AweApiClient::ENDPOINT_MODEL_PROFILE . ' [credentials redacted]',
			'http_status'     => $http_status,
			'cached'          => $is_cached,
			'top_level_keys'  => $top_keys,
			'data_keys'       => array_values( $safe_data_keys ),
			'has_bio_field'   => $probe['has_bio'],
			'bio_fields_found'=> array_intersect( self::BIO_FIELD_CANDIDATES, $probe['found'] ),
			'all_found_fields'=> $probe['found'],
			'confidence'      => self::get_confidence( $probe ),
			// access_key is NEVER included here.
		];
	}

	/**
	 * Retrieve the short-lived debug transient for a post.
	 * Returns null if not present or expired.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_debug_transient( int $post_id ): ?array {
		$key  = self::DEBUG_TRANSIENT_PFX . (string) $post_id;
		$data = get_transient( $key );
		return is_array( $data ) ? $data : null;
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/**
	 * Persist normalized evidence as post meta.
	 *
	 * Only writes the new AWE-specific traceability keys.
	 * Does NOT touch _tmwseo_bio_summary or _tmwseo_bio_review_status.
	 *
	 * @param int                 $post_id
	 * @param array<string,mixed> $evidence
	 * @param array<string,mixed> $raw_data  Used only for hashing.
	 */
	private static function save_evidence_meta( int $post_id, array $evidence, array $raw_data ): void {
		update_post_meta( $post_id, self::META_AWE_FETCHED_AT, $evidence['fetched_at'] );
		update_post_meta( $post_id, self::META_AWE_AVAILABLE_FIELDS, wp_json_encode( $evidence['available_fields'] ) );
		update_post_meta( $post_id, self::META_AWE_CONFIDENCE, $evidence['confidence'] );
		// Hash for stale-detection. Credentials are NOT in raw_data (they are query params).
		update_post_meta( $post_id, self::META_AWE_EVIDENCE_HASH, md5( serialize( $raw_data ) ) );

		// Populate bio source traceability meta (the operator still sets review status).
		if ( $evidence['source_type'] !== '' ) {
			update_post_meta( $post_id, '_tmwseo_bio_source_type', sanitize_text_field( $evidence['source_type'] ) );
			update_post_meta( $post_id, '_tmwseo_bio_source_label', sanitize_text_field( $evidence['source_label'] ) );
			update_post_meta( $post_id, '_tmwseo_bio_source_url', esc_url_raw( $evidence['source_url'] ) );
		}

		// Persist tags as a JSON-encoded array in source_facts.
		if ( ! empty( $evidence['tags'] ) ) {
			$facts = array_slice( array_map( 'sanitize_text_field', (array) $evidence['tags'] ), 0, 20 );
			update_post_meta( $post_id, '_tmwseo_bio_source_facts', wp_json_encode( $facts ) );
		}
	}

	/**
	 * Store a short-lived debug transient (admin-only).
	 *
	 * Access key is never stored.
	 *
	 * @param int                 $post_id
	 * @param array<string,mixed> $raw_data
	 * @param array<string,mixed> $evidence
	 */
	private static function save_debug_transient( int $post_id, array $raw_data, array $evidence ): void {
		$key     = self::DEBUG_TRANSIENT_PFX . (string) $post_id;
		$payload = [
			'debug'    => self::admin_debug_inspect( $raw_data, null, false ),
			'evidence' => $evidence,
			'saved_at' => current_time( 'mysql' ),
			// raw_data stored for admin, but bio_candidate truncated, no credentials.
			'record_preview' => array_map(
				static fn( $v ) => is_string( $v ) ? mb_substr( $v, 0, 300 ) : $v,
				self::extract_first_record( $raw_data )
			),
		];
		set_transient( $key, $payload, self::DEBUG_TRANSIENT_TTL );
	}

	/**
	 * AWE API often returns `{ "models": [...] }` or a plain array.
	 * Extract the first model record regardless of wrapper shape.
	 *
	 * @param array<string,mixed> $response
	 * @return array<string,mixed>
	 */
	private static function extract_first_record( array $response ): array {
		// Try common wrapper keys.
		foreach ( [ 'models', 'data', 'performers', 'results', 'items' ] as $wrapper ) {
			if ( isset( $response[ $wrapper ] ) && is_array( $response[ $wrapper ] ) ) {
				$list = $response[ $wrapper ];
				return is_array( $list[0] ?? null ) ? (array) $list[0] : [];
			}
		}
		// If the response itself is a list, take the first element.
		if ( isset( $response[0] ) && is_array( $response[0] ) ) {
			return (array) $response[0];
		}
		// Otherwise treat the whole response as the record.
		return $response;
	}

	/**
	 * Return true when a field exists with a non-empty scalar value.
	 *
	 * @param array<string,mixed> $record
	 */
	private static function field_has_value( array $record, string $field ): bool {
		if ( ! array_key_exists( $field, $record ) ) {
			return false;
		}
		$v = $record[ $field ];
		if ( is_array( $v ) ) {
			return ! empty( $v );
		}
		return trim( (string) $v ) !== '';
	}

	/**
	 * Return the first non-empty string from a list of field candidates.
	 *
	 * @param array<string,mixed> $record
	 * @param string[]            $fields
	 */
	private static function coalesce_string( array $record, array $fields ): string {
		foreach ( $fields as $f ) {
			$v = self::safe_string( $record, $f );
			if ( $v !== '' ) {
				return $v;
			}
		}
		return '';
	}

	/**
	 * Return the first non-empty array from a list of field candidates.
	 *
	 * @param array<string,mixed> $record
	 * @param string[]            $fields
	 * @return mixed[]
	 */
	private static function coalesce_array( array $record, array $fields ): array {
		foreach ( $fields as $f ) {
			if ( isset( $record[ $f ] ) && is_array( $record[ $f ] ) && ! empty( $record[ $f ] ) ) {
				return (array) $record[ $f ];
			}
			// Some APIs return comma-separated strings for tags.
			if ( isset( $record[ $f ] ) && is_string( $record[ $f ] ) && trim( $record[ $f ] ) !== '' ) {
				return array_map( 'trim', explode( ',', $record[ $f ] ) );
			}
		}
		return [];
	}

	/**
	 * Safely extract a string from a record, stripping HTML.
	 *
	 * @param array<string,mixed> $record
	 */
	private static function safe_string( array $record, string $field ): string {
		if ( ! isset( $record[ $field ] ) || is_array( $record[ $field ] ) ) {
			return '';
		}
		return trim( wp_strip_all_tags( (string) $record[ $field ] ) );
	}

	/**
	 * Extract a LiveJasmin username from a profile URL.
	 *
	 * e.g. https://www.livejasmin.com/en/chat/ModelName → ModelName
	 */
	private static function extract_username_from_url( string $url ): string {
		if ( $url === '' ) {
			return '';
		}
		// Pattern: /en/chat/{username} or /chat/{username}
		if ( preg_match( '#/(?:en/)?chat/([^/?&#]+)#i', $url, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/**
	 * @param array{found:string[],missing:string[],has_bio:bool} $probe
	 */
	private static function get_confidence_from_probe( array $probe ): string {
		$found_count = count( $probe['found'] );
		if ( $probe['has_bio'] && $found_count >= 5 ) {
			return 'high';
		}
		if ( $found_count >= 3 ) {
			return 'medium';
		}
		if ( $found_count >= 1 ) {
			return 'low';
		}
		return 'none';
	}
}
