<?php
/**
 * Structured outcome of generic public-profile source URL validation.
 *
 * @package TMWSEO\Engine\Import
 */
namespace TMWSEO\Engine\Import;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class SourceValidationResult {
    public bool $is_valid;
    public string $normalized_url;
    public string $host;
    public string $error_code;
    public string $message;

    public function __construct( bool $is_valid = false, string $normalized_url = '', string $host = '', string $error_code = '', string $message = '' ) {
        $this->is_valid       = $is_valid;
        $this->normalized_url = $normalized_url;
        $this->host           = $host;
        $this->error_code     = $error_code;
        $this->message        = $message;
    }
}
