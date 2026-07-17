<?php
/**
 * Candidate-only importer for supported LiveJasmin public profile URLs.
 *
 * This importer deliberately performs local URL recognition only. It does not
 * fetch remote profiles or persist candidate data.
 *
 * @package TMWSEO\Engine\Import
 */
namespace TMWSEO\Engine\Import;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class LiveJasminProfileImporter implements ProfileImporter {
    private const PROFILE_PATH_PATTERN = '#^/(?:en/)?chat/([A-Za-z0-9][A-Za-z0-9_-]{0,63})/?$#';
    private ProfileFetchService $fetch_service;

    public function __construct( ?ProfileFetchService $fetch_service = null ) {
        $this->fetch_service = $fetch_service ?? new NullProfileFetchService();
    }

    public function provider_name(): string {
        return 'livejasmin';
    }

    public function supports( string $url ): bool {
        return $this->username_from_url( $url ) !== '';
    }

    public function import_profile( string $url ): ImportResult {
        $username = $this->username_from_url( $url );
        if ( $username === '' ) {
            return new ImportResult( [
                'status'     => ImportResult::STATUS_UNSUPPORTED,
                'provider'   => $this->provider_name(),
                'source_url' => $url,
                'message'    => 'The URL is not a supported LiveJasmin public profile URL.',
            ] );
        }

        $request = new ProfileFetchRequest( [
            'provider'   => $this->provider_name(),
            'source_url' => $this->canonical_url( $username ),
            'username'   => $username,
        ] );

        try {
            $fetch_result = $this->fetch_service->fetch( $request );
        } catch ( \Throwable $exception ) {
            return new ImportResult( [
                'status'     => ImportResult::STATUS_ERROR,
                'provider'   => $request->provider,
                'source_url' => $request->source_url,
                'username'   => $request->username,
                'diagnostics' => [ 'fetch_failed' => true ],
                'message'    => 'The profile fetch service could not complete the request.',
            ] );
        }

        return new ImportResult( [
            'status'      => $this->import_status( $fetch_result->status ),
            'provider'    => $fetch_result->provider,
            'source_url'  => $fetch_result->source_url,
            'username'    => $fetch_result->username,
            'raw_fields'  => $fetch_result->raw_fields,
            'attributes'  => $fetch_result->attributes,
            'diagnostics' => $fetch_result->diagnostics,
            'warnings'    => $fetch_result->warnings,
            'message'     => $fetch_result->message,
        ] );
    }

    private function username_from_url( string $url ): string {
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) || strtolower( (string) ( $parts['scheme'] ?? '' ) ) !== 'https' ) {
            return '';
        }

        $host = strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );
        if ( ! in_array( $host, [ 'livejasmin.com', 'www.livejasmin.com' ], true ) ) {
            return '';
        }
        if ( isset( $parts['port'] ) && (int) $parts['port'] !== 443 ) {
            return '';
        }

        $matches = [];
        if ( preg_match( self::PROFILE_PATH_PATTERN, (string) ( $parts['path'] ?? '' ), $matches ) !== 1 ) {
            return '';
        }

        return $matches[1];
    }

    private function canonical_url( string $username ): string {
        return 'https://www.livejasmin.com/en/chat/' . $username;
    }

    private function import_status( string $status ): string {
        if ( $status === ProfileFetchResult::STATUS_NOT_IMPLEMENTED ) {
            return ImportResult::STATUS_UNSUPPORTED;
        }

        return $status;
    }
}
