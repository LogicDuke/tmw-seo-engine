<?php
namespace TMWSEO\Engine\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal Anthropic Claude API service.
 *
 * Mirrors the public surface of class-openai.php so the rest of the
 * engine can call it the same way.
 *
 * Settings keys (already registered in class-settings.php):
 *   tmwseo_anthropic_api_key — stored encrypted in wp_options.
 *
 * Constants (wp-config overrides):
 *   TMW_SEO_ANTHROPIC_KEY  or  ANTHROPIC_API_KEY
 */
class Anthropic {

	private const API_URL       = 'https://api.anthropic.com/v1/messages';
	private const API_VERSION   = '2023-06-01';
	private const DEFAULT_MODEL = 'claude-sonnet-4-6';

	// ------------------------------------------------------------------ //
	//  Public helpers
	// ------------------------------------------------------------------ //

	public static function is_configured(): bool {
		return self::api_key() !== '';
	}

	/**
	 * Send a messages request and parse the first text block.
	 *
	 * @param array<int,array{role:string,content:string}> $messages
	 * @param array<string,mixed>                          $options  temperature, max_tokens …
	 * @return array{ok:bool,text?:string,error?:string}
	 */
	public static function chat( array $messages, array $options = [] ): array {
		$key = self::api_key();
		if ( $key === '' ) {
			return [ 'ok' => false, 'error' => 'anthropic_not_configured' ];
		}

		$body = wp_json_encode( array_filter( [
			'model'      => self::DEFAULT_MODEL,
			'max_tokens' => (int) ( $options['max_tokens'] ?? 4096 ),
			'temperature' => isset( $options['temperature'] ) ? (float) $options['temperature'] : null,
			'system'     => self::extract_system( $messages ),
			'messages'   => self::strip_system( $messages ),
		], static fn( $v ) => $v !== null ) );

		$response = wp_remote_post( self::API_URL, [
			'timeout' => 60,
			'headers' => [
				'x-api-key'         => $key,
				'anthropic-version' => self::API_VERSION,
				'content-type'      => 'application/json',
			],
			'body' => $body,
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'error' => $response->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code !== 200 || ! is_array( $data ) ) {
			$msg = is_array( $data ) ? ( (string) ( $data['error']['message'] ?? $raw ) ) : $raw;
			return [ 'ok' => false, 'error' => "HTTP {$code}: " . wp_strip_all_tags( $msg ) ];
		}

		// Extract first text block.
		$text = '';
		foreach ( (array) ( $data['content'] ?? [] ) as $block ) {
			if ( is_array( $block ) && ( $block['type'] ?? '' ) === 'text' ) {
				$text = (string) ( $block['text'] ?? '' );
				break;
			}
		}

		return [ 'ok' => true, 'text' => $text ];
	}

	/**
	 * Like chat() but automatically JSON-decodes the response text.
	 *
	 * @param array<int,array{role:string,content:string}> $messages
	 * @param array<string,mixed>                          $options
	 * @return array{ok:bool,json?:array<string,mixed>,error?:string}
	 */
	public static function chat_json( array $messages, array $options = [] ): array {
		$res = self::chat( $messages, $options );
		if ( ! $res['ok'] ) {
			return [ 'ok' => false, 'error' => $res['error'] ?? 'unknown_error' ];
		}

		$text = trim( (string) ( $res['text'] ?? '' ) );
		// Strip optional ```json … ``` fences that Claude sometimes adds.
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text ) ?? $text;
		$text = preg_replace( '/\s*```\s*$/', '', $text ) ?? $text;

		$decoded = json_decode( $text, true );
		if ( ! is_array( $decoded ) ) {
			return [ 'ok' => false, 'error' => 'json_decode_failed', 'raw' => $text ];
		}

		return [ 'ok' => true, 'json' => $decoded ];
	}

	// ------------------------------------------------------------------ //
	//  Private helpers
	// ------------------------------------------------------------------ //

	private static function api_key(): string {
		if ( defined( 'TMW_SEO_ANTHROPIC_KEY' ) && TMW_SEO_ANTHROPIC_KEY !== '' ) {
			return (string) TMW_SEO_ANTHROPIC_KEY;
		}
		if ( defined( 'ANTHROPIC_API_KEY' ) && ANTHROPIC_API_KEY !== '' ) {
			return (string) ANTHROPIC_API_KEY;
		}

		// Engine settings key.
		$stored = trim( (string) Settings::get( 'tmwseo_anthropic_api_key', '' ) );
		if ( $stored !== '' ) {
			return $stored;
		}

		// Autopilot plugin option key (forward compat).
		return trim( (string) get_option( 'tmwseo_anthropic_api_key', '' ) );
	}

	/** Pull out the system message text (Anthropic sends it separately). */
	private static function extract_system( array $messages ): ?string {
		foreach ( $messages as $m ) {
			if ( ( $m['role'] ?? '' ) === 'system' ) {
				return (string) ( $m['content'] ?? '' );
			}
		}
		return null;
	}

	/** Remove system-role entries (not valid in Anthropic's messages array). */
	private static function strip_system( array $messages ): array {
		return array_values( array_filter(
			$messages,
			static fn( $m ) => ( $m['role'] ?? '' ) !== 'system'
		) );
	}
}
