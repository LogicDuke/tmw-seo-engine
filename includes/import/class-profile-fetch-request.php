<?php
/**
 * Normalized input for a candidate-only profile fetch service.
 *
 * @package TMWSEO\Engine\Import
 */
namespace TMWSEO\Engine\Import;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class ProfileFetchRequest {
    public string $provider;
    public string $source_url;
    public string $username;
    public int $post_id;
    /** @var array<string,mixed> */
    public array $context;

    /** @param array<string,mixed> $data */
    public function __construct( array $data = [] ) {
        $this->provider   = (string) ( $data['provider'] ?? '' );
        $this->source_url = (string) ( $data['source_url'] ?? '' );
        $this->username   = (string) ( $data['username'] ?? '' );
        $this->post_id    = max( 0, (int) ( $data['post_id'] ?? 0 ) );
        $this->context    = is_array( $data['context'] ?? null ) ? $data['context'] : [];
    }
}
