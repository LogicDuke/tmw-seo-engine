<?php
/**
 * Structured candidate data returned by a profile fetch service.
 *
 * @package TMWSEO\Engine\Import
 */
namespace TMWSEO\Engine\Import;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ProfileFetchResult {
    public const STATUS_OK              = 'ok';
    public const STATUS_NOT_IMPLEMENTED = 'not_implemented';
    public const STATUS_INVALID         = 'invalid';
    public const STATUS_ERROR           = 'error';

    public string $status;
    public string $provider;
    public string $source_url;
    public string $username;
    public string $display_name;
    /** @var array<string,mixed> */
    public array $raw_fields;
    /** @var array<string,mixed> */
    public array $attributes;
    /** @var array<string,mixed> */
    public array $diagnostics;
    /** @var string[] */
    public array $warnings;
    public string $message;

    /** @param array<string,mixed> $data */
    public function __construct( array $data = [] ) {
        $this->status      = (string) ( $data['status'] ?? self::STATUS_NOT_IMPLEMENTED );
        $this->provider    = (string) ( $data['provider'] ?? '' );
        $this->source_url  = (string) ( $data['source_url'] ?? '' );
        $this->username    = (string) ( $data['username'] ?? '' );
        $this->display_name = (string) ( $data['display_name'] ?? '' );
        $this->raw_fields  = is_array( $data['raw_fields'] ?? null ) ? $data['raw_fields'] : [];
        $this->attributes  = is_array( $data['attributes'] ?? null ) ? $data['attributes'] : [];
        $this->diagnostics = is_array( $data['diagnostics'] ?? null ) ? $data['diagnostics'] : [];
        $this->warnings    = is_array( $data['warnings'] ?? null ) ? $data['warnings'] : [];
        $this->message     = (string) ( $data['message'] ?? '' );
    }
}
