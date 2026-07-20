<?php
/**
 * AWE namespaced test stubs — TMWSEO\Engine\Integrations overrides.
 *
 * PHP resolves unqualified function calls first to the current namespace,
 * then falls back to global. By declaring these inside
 * TMWSEO\Engine\Integrations, the AWE classes pick up test-aware versions
 * of wp_remote_get, get_post_meta, etc. without any production code changes.
 *
 * This file MUST begin with the namespace declaration — no global statements
 * before it. Global helpers are in awe-global-stubs.php (included first).
 *
 * @package TMWSEO\Engine\Tests\Bootstrap
 */

namespace TMWSEO\Engine\Integrations;

// ── HTTP transport — honours pre_http_request so tests can intercept ──────────

function wp_remote_get( string $url, array $args = [] ) {
    $pre = _tmw_filter_apply( 'pre_http_request', false, $args, $url );
    if ( $pre !== false ) {
        return $pre;
    }
    return \wp_remote_get( $url, $args );
}

function wp_remote_retrieve_response_code( $response ): int {
    return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( $response ): string {
    return (string) ( $response['body'] ?? '' );
}

function is_wp_error( $thing ): bool {
    return $thing instanceof \WP_Error;
}

// ── In-memory post meta store ─────────────────────────────────────────────────

function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
    $store = $GLOBALS['_tmw_test_post_meta'][ $post_id ] ?? [];
    if ( $key === '' ) {
        return $store;
    }
    return $store[ $key ] ?? ( $single ? '' : [] );
}

function update_post_meta( int $post_id, string $meta_key, $meta_value, $prev_value = '' ): bool {
    if ( ! isset( $GLOBALS['_tmw_test_post_meta'] ) ) {
        $GLOBALS['_tmw_test_post_meta'] = [];
    }
    $GLOBALS['_tmw_test_post_meta'][ $post_id ][ $meta_key ] = $meta_value;
    return true;
}

// ── Transient store ───────────────────────────────────────────────────────────

function get_transient( string $key ) {
    return $GLOBALS['_tmw_test_transients'][ $key ] ?? false;
}

function set_transient( string $key, $value, int $expiration = 0 ): bool {
    $GLOBALS['_tmw_test_transients'][ $key ] = $value;
    return true;
}

function delete_transient( string $key ): bool {
    unset( $GLOBALS['_tmw_test_transients'][ $key ] );
    return true;
}

// ── WP helper stubs ───────────────────────────────────────────────────────────

function current_time( string $type = 'mysql', bool $gmt = false ): string {
    return \gmdate( 'Y-m-d H:i:s' );
}

function wp_strip_all_tags( string $string, bool $remove_breaks = false ): string {
    return \strip_tags( $string );
}

function wp_json_encode( $data, int $options = 0, int $depth = 512 ): string|false {
    return \json_encode( $data, $options, $depth );
}

function esc_url_raw( string $url ): string {
    return \filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
}

function sanitize_text_field( string $str ): string {
    return \trim( \strip_tags( $str ) );
}

function parse_url( string $url, int $component = -1 ) {
    return \parse_url( $url, $component );
}

function http_build_query( array $data, string $numeric_prefix = '', ?string $arg_separator = null, int $encoding_type = PHP_QUERY_RFC1738 ): string {
    return \http_build_query( $data, $numeric_prefix, $arg_separator ?? '&', $encoding_type );
}
