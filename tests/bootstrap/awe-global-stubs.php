<?php
/**
 * AWE test filter registry — global namespace only.
 *
 * Must be included BEFORE awe-namespace-stubs.php.
 * Contains no namespace declarations so it can freely declare global functions.
 *
 * @package TMWSEO\Engine\Tests\Bootstrap
 */

if ( ! isset( $GLOBALS['_tmw_filter_registry'] ) ) {
    $GLOBALS['_tmw_filter_registry'] = [];
}

if ( ! isset( $GLOBALS['_tmw_test_post_meta'] ) ) {
    $GLOBALS['_tmw_test_post_meta'] = [];
}

if ( ! function_exists( '_tmw_register_filter' ) ) {
    function _tmw_register_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
        $GLOBALS['_tmw_filter_registry'][ $hook ][] = [
            'priority'      => $priority,
            'callback'      => $callback,
            'accepted_args' => $accepted_args,
        ];
        return true;
    }
}

if ( ! function_exists( '_tmw_filter_apply' ) ) {
    function _tmw_filter_apply( string $hook, $value, ...$args ) {
        if ( empty( $GLOBALS['_tmw_filter_registry'][ $hook ] ) ) {
            return $value;
        }
        $callbacks = $GLOBALS['_tmw_filter_registry'][ $hook ];
        usort( $callbacks, static fn( $a, $b ) => $a['priority'] <=> $b['priority'] );
        foreach ( $callbacks as $entry ) {
            $all_args  = array_merge( [ $value ], $args );
            $call_args = array_slice( $all_args, 0, max( 1, (int) $entry['accepted_args'] ) );
            $value     = ( $entry['callback'] )( ...$call_args );
        }
        return $value;
    }
}

if ( ! function_exists( 'remove_all_filters' ) ) {
    function remove_all_filters( string $hook, $priority = false ): bool {
        if ( $priority === false ) {
            unset( $GLOBALS['_tmw_filter_registry'][ $hook ] );
        } else {
            $GLOBALS['_tmw_filter_registry'][ $hook ] = array_values( array_filter(
                $GLOBALS['_tmw_filter_registry'][ $hook ] ?? [],
                static fn( $e ) => (int) $e['priority'] !== (int) $priority
            ) );
        }
        return true;
    }
}
