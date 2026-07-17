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

        return new ImportResult( [
            'status'      => ImportResult::STATUS_UNSUPPORTED,
            'provider'    => $this->provider_name(),
            'source_url'  => $this->canonical_url( $username ),
            'username'    => $username,
            'raw_fields'  => [],
            'attributes'  => [],
            'diagnostics' => [
                'source_recognized' => true,
                'fetch_implemented' => false,
            ],
            'warnings'    => [ 'Profile fetching is not implemented.' ],
            'message'     => 'The LiveJasmin URL was recognized, but no profile data was fetched because profile fetching is not implemented.',
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
}
