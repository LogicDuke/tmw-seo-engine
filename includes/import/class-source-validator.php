<?php
/**
 * Local-only validation for candidate public-profile URLs.
 *
 * @package TMWSEO\Engine\Import
 */
namespace TMWSEO\Engine\Import;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SourceValidator {
    public function validate( string $url ): SourceValidationResult {
        $url = trim( $url );
        if ( $url === '' || filter_var( $url, FILTER_VALIDATE_URL ) === false ) {
            return $this->invalid( 'invalid_url', 'Enter a valid absolute URL.' );
        }

        $parts = parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return $this->invalid( 'invalid_url', 'Enter a valid absolute URL.' );
        }
        if ( strtolower( (string) $parts['scheme'] ) !== 'https' ) {
            return $this->invalid( 'invalid_scheme', 'Only HTTPS source URLs are allowed.' );
        }
        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return $this->invalid( 'credentials_not_allowed', 'Source URLs must not contain credentials.' );
        }

        $host = strtolower( rtrim( trim( (string) $parts['host'], '[]' ), '.' ) );
        if ( $host === 'localhost' || str_ends_with( $host, '.localhost' ) ) {
            return $this->invalid( 'local_host', 'Local source URLs are not allowed.' );
        }
        if ( filter_var( $host, FILTER_VALIDATE_IP ) !== false && $this->is_unsafe_ip( $host ) ) {
            return $this->invalid( 'unsafe_ip', 'Private or reserved source URLs are not allowed.' );
        }

        $normalized = 'https://';
        if ( strpos( $host, ':' ) !== false ) {
            $normalized .= '[' . $host . ']';
        } else {
            $normalized .= $host;
        }
        if ( isset( $parts['port'] ) ) {
            $normalized .= ':' . (int) $parts['port'];
        }
        $normalized .= $parts['path'] ?? '';
        if ( isset( $parts['query'] ) ) {
            $normalized .= '?' . $parts['query'];
        }
        if ( isset( $parts['fragment'] ) ) {
            $normalized .= '#' . $parts['fragment'];
        }

        return new SourceValidationResult( true, $normalized, $host );
    }

    private function invalid( string $error_code, string $message ): SourceValidationResult {
        return new SourceValidationResult( false, '', '', $error_code, $message );
    }

    private function is_unsafe_ip( string $ip ): bool {
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) !== false ) {
            $value = ip2long( $ip );
            foreach ( [
                [ '0.0.0.0', 8 ], [ '10.0.0.0', 8 ], [ '100.64.0.0', 10 ],
                [ '127.0.0.0', 8 ], [ '169.254.0.0', 16 ], [ '172.16.0.0', 12 ],
                [ '192.0.0.0', 24 ], [ '192.0.2.0', 24 ], [ '192.168.0.0', 16 ],
                [ '198.18.0.0', 15 ], [ '198.51.100.0', 24 ], [ '203.0.113.0', 24 ],
                [ '224.0.0.0', 4 ], [ '240.0.0.0', 4 ],
            ] as [ $network, $prefix ] ) {
                $network_value = ip2long( $network );
                // Keep PHP's signed ip2long() representation. This avoids
                // converting unsigned values through an overflowing int on
                // 32-bit PHP while preserving the IPv4 bit pattern.
                $mask = $prefix === 0 ? 0 : ~( ( 1 << ( 32 - $prefix ) ) - 1 );
                if ( ( $value & $mask ) === ( $network_value & $mask ) ) {
                    return true;
                }
            }
            return false;
        }

        $packed = inet_pton( $ip );
        if ( $packed === false ) {
            return true;
        }
        // ::/128 and ::1/128, unique-local fc00::/7, and link-local fe80::/10.
        if ( $packed === str_repeat( "\0", 16 ) || $packed === str_repeat( "\0", 15 ) . "\1" ) {
            return true;
        }
        $first = ord( $packed[0] );
        $second = ord( $packed[1] );
        return ( $first & 0xfe ) === 0xfc || ( $first === 0xfe && ( $second & 0xc0 ) === 0x80 );
    }
}
