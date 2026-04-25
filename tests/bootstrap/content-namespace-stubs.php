<?php
/**
 * TMWSEO\Engine\Content namespace stubs for ExternalProfileEvidence tests.
 *
 * Declares namespaced versions of WP functions used by ExternalProfileEvidence
 * so PHP resolves them to in-memory test implementations.
 *
 * Included from wordpress-stubs.php after the global stubs.
 *
 * @package TMWSEO\Engine\Tests\Bootstrap
 */

namespace TMWSEO\Engine\Content;

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

function wp_strip_all_tags( string $string, bool $remove_breaks = false ): string {
    return strip_tags( $string );
}

function parse_url( string $url, int $component = -1 ) {
    return \parse_url( $url, $component );
}
