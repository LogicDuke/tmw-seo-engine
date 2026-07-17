<?php
/**
 * Authenticated, candidate-only client for the isolated Playwright service.
 * @package TMWSEO\Engine\Import
 */
declare(strict_types=1);
namespace TMWSEO\Engine\Import;

use TMWSEO\Engine\Services\Settings;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LiveJasminRemoteProfileFetchService implements ProfileFetchService {
    private const MAX_RESPONSE_BYTES = 131072;
    private const MAX_TEXT = 1000;
    private const MAX_BIO = 10000;
    /** @var array<string,mixed> */ private array $config;
    /** @var callable|null */ private $transport;
    /** @param array<string,mixed> $config */
    public function __construct( array $config = [], ?callable $transport = null ) {
        $this->config = $config;
        $this->transport = $transport;
    }
    public static function is_configured(): bool {
        return ! Settings::is_safe_mode() && (bool) Settings::get( 'livejasmin_profile_fetch_enabled', 0 )
            && trim( (string) Settings::get( 'livejasmin_profile_fetch_endpoint', '' ) ) !== ''
            && trim( (string) Settings::get( 'livejasmin_profile_fetch_secret', '' ) ) !== '';
    }
    public function fetch( ProfileFetchRequest $request ): ProfileFetchResult {
        $endpoint = trim( (string) ( $this->config['endpoint'] ?? Settings::get( 'livejasmin_profile_fetch_endpoint', '' ) ) );
        $secret = trim( (string) ( $this->config['secret'] ?? Settings::get( 'livejasmin_profile_fetch_secret', '' ) ) );
        $timeout = max( 3, min( 30, (int) ( $this->config['timeout'] ?? Settings::get( 'livejasmin_profile_fetch_timeout', 15 ) ) ) );
        if ( $endpoint === '' || $secret === '' || ! $this->valid_endpoint( $endpoint ) ) return $this->error( $request, ProfileFetchResult::STATUS_NOT_IMPLEMENTED, 'Remote profile fetching is not configured.' );
        $request_id = wp_generate_uuid4();
        $args = [ 'timeout' => $timeout, 'redirection' => 0, 'headers' => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-TMW-Profile-Fetch-Token' => $secret ], 'body' => wp_json_encode( [ 'provider' => $request->provider, 'source_url' => $request->source_url, 'username' => $request->username, 'request_id' => $request_id ] ) ];
        $response = $this->transport ? ( $this->transport )( $endpoint, $args ) : wp_remote_post( $endpoint, $args );
        if ( is_wp_error( $response ) ) return $this->error( $request, ProfileFetchResult::STATUS_ERROR, 'The remote profile fetch service could not be reached.', $request_id );
        $code = (int) wp_remote_retrieve_response_code( $response ); $body = (string) wp_remote_retrieve_body( $response );
        $type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
        if ( $code < 200 || $code >= 300 || strpos( $type, 'application/json' ) === false || strlen( $body ) > self::MAX_RESPONSE_BYTES ) return $this->error( $request, ProfileFetchResult::STATUS_ERROR, 'The remote profile fetch service returned an invalid response.', $request_id );
        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) return $this->error( $request, ProfileFetchResult::STATUS_ERROR, 'The remote profile fetch service returned malformed JSON.', $request_id );
        foreach ( [ 'provider', 'source_url', 'username' ] as $field ) if ( ! empty( $data[$field] ) && (string) $data[$field] !== (string) $request->{$field} ) return $this->error( $request, ProfileFetchResult::STATUS_ERROR, 'The remote service returned a conflicting profile identity.', $request_id );
        $status = (string) ( $data['status'] ?? 'error' );
        $status = $status === 'ok' ? ProfileFetchResult::STATUS_OK : ( $status === 'invalid' ? ProfileFetchResult::STATUS_INVALID : ProfileFetchResult::STATUS_ERROR );
        return new ProfileFetchResult( [ 'status' => $status, 'provider' => $request->provider, 'source_url' => $request->source_url, 'username' => $request->username, 'display_name' => $this->text( $data['display_name'] ?? '' ), 'raw_fields' => $this->fields( $data['raw_fields'] ?? [], self::MAX_BIO ), 'attributes' => $this->fields( $data['attributes'] ?? [], self::MAX_TEXT ), 'diagnostics' => $this->fields( $data['diagnostics'] ?? [], self::MAX_TEXT ), 'warnings' => $this->warnings( $data['warnings'] ?? [] ), 'message' => $this->text( $data['message'] ?? 'Profile fetch completed.' ) ] );
    }
    private function valid_endpoint( string $url ): bool { $p = wp_parse_url( $url ); if ( ! is_array( $p ) ) return false; $scheme = strtolower( (string) ( $p['scheme'] ?? '' ) ); $host = strtolower( (string) ( $p['host'] ?? '' ) ); return $scheme === 'https' || ( defined( 'WP_DEBUG' ) && WP_DEBUG && in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true ) ); }
    private function text( $value ): string { return is_scalar( $value ) ? mb_substr( trim( (string) $value ), 0, self::MAX_TEXT ) : ''; }
    /** @return array<string,mixed> */ private function fields( $value, int $limit ): array { if ( ! is_array( $value ) ) return []; $out=[]; foreach ( array_slice( $value, 0, 30, true ) as $k=>$v ) { if ( ! is_string( $k ) ) continue; if ( is_scalar( $v ) || $v === null ) $out[$k] = is_string($v) ? mb_substr(trim($v),0,$limit) : $v; elseif ( is_array($v) ) $out[$k] = array_values(array_map(fn($x)=>is_scalar($x)?mb_substr(trim((string)$x),0,$limit):'', array_slice($v,0,100))); } return $out; }
    /** @return string[] */ private function warnings( $value ): array { return is_array($value) ? array_values(array_filter(array_map(fn($v)=>$this->text($v), array_slice($value,0,50)))) : []; }
    private function error( ProfileFetchRequest $r, string $status, string $message, string $request_id = '' ): ProfileFetchResult { return new ProfileFetchResult([ 'status'=>$status,'provider'=>$r->provider,'source_url'=>$r->source_url,'username'=>$r->username,'diagnostics'=>['fetch_attempted'=>true,'fetch_implemented'=>true,'request_id'=>$request_id],'message'=>$message ]); }
}
