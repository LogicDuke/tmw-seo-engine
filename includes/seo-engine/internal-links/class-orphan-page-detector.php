<?php
/**
 * OrphanPageDetector — finds published posts with zero inbound internal links.
 *
 * A page is "orphaned" if no other published post on the site links to it.
 * Orphans are invisible to Googlebot unless they appear in a sitemap.
 *
 * @package TMWSEO\Engine\InternalLinks
 */
namespace TMWSEO\Engine\InternalLinks;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use TMWSEO\Engine\Logs;

class OrphanPageDetector {

    const OPTION_RESULTS   = 'tmwseo_orphan_pages_results';
    const OPTION_LAST_SCAN = 'tmwseo_orphan_pages_last_scan';
    const HOOK_SCAN        = 'tmwseo_orphan_scan_weekly';

    // ── Boot ───────────────────────────────────────────────────────────────

    public static function init(): void {
        add_action( self::HOOK_SCAN, [ __CLASS__, 'run_scan' ] );
        add_action( 'wp_ajax_tmwseo_orphan_scan', [ __CLASS__, 'handle_ajax_scan' ] );
    }

    public static function schedule(): void {
        if ( ! wp_next_scheduled( self::HOOK_SCAN ) ) {
            wp_schedule_event( time() + 1800, 'tmwseo_weekly', self::HOOK_SCAN );
        }
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook( self::HOOK_SCAN );
    }

    // ── Main scan ──────────────────────────────────────────────────────────

    /**
     * Scans all published posts and returns orphan list.
     *
     * @param string|array $post_types
     * @param int          $limit
     * @return array{orphans:array, total_scanned:int, scan_time_ms:int}
     */
    public static function run_scan( $post_types = null, int $limit = 500 ): array {
        $start = microtime( true );

        if ( $post_types === null ) {
            $post_types = [ 'model', 'video', 'tmw_video', 'post', 'page' ];
        }
        $post_types = (array) $post_types;

        // Get all published posts of the given types
        $posts = get_posts( [
            'post_type'      => $post_types,
            'posts_per_page' => $limit,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ] );

        if ( empty( $posts ) ) {
            return [ 'orphans' => [], 'total_scanned' => 0, 'scan_time_ms' => 0 ];
        }

        $orphans = [];

        foreach ( $posts as $post_id ) {
            $inbound = self::count_inbound_links( (int) $post_id );
            if ( $inbound === 0 ) {
                $orphans[] = [
                    'post_id'    => (int) $post_id,
                    'post_type'  => get_post_type( $post_id ),
                    'title'      => get_the_title( $post_id ),
                    'permalink'  => get_permalink( $post_id ),
                    'modified'   => get_the_modified_date( 'Y-m-d', $post_id ),
                    'word_count' => str_word_count( strip_tags( (string) get_post_field( 'post_content', $post_id ) ) ),
                ];
            }
        }

        $result = [
            'orphans'       => $orphans,
            'orphan_count'  => count( $orphans ),
            'total_scanned' => count( $posts ),
            'scan_time_ms'  => (int) round( ( microtime( true ) - $start ) * 1000 ),
            'scanned_at'    => current_time( 'mysql' ),
        ];

        update_option( self::OPTION_RESULTS, $result, false );
        update_option( self::OPTION_LAST_SCAN, current_time( 'mysql' ), false );

        Logs::info( 'internal_links', '[ORPHAN] Scan complete', [
            'orphans'  => count( $orphans ),
            'scanned'  => count( $posts ),
            'time_ms'  => $result['scan_time_ms'],
        ] );

        return $result;
    }

    /**
     * Returns the most recently cached scan results (runs fresh if stale).
     */
    public static function get_results( bool $force_refresh = false ): array {
        if ( ! $force_refresh ) {
            $last = (string) get_option( self::OPTION_LAST_SCAN, '' );
            if ( $last !== '' && ( time() - strtotime( $last ) ) < 24 * HOUR_IN_SECONDS ) {
                $results = get_option( self::OPTION_RESULTS, null );
                if ( is_array( $results ) ) return $results;
            }
        }
        return self::run_scan();
    }

    // ── AJAX handler ───────────────────────────────────────────────────────

    public static function handle_ajax_scan(): void {
        check_ajax_referer( 'tmwseo_orphan_scan', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        $result = self::run_scan( null, 1000 );
        wp_send_json_success( $result );
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Counts how many other published posts contain a link to $post_id's permalink.
     */
    public static function count_inbound_links( int $post_id ): int {
        global $wpdb;
        $permalink = get_permalink( $post_id );
        if ( ! $permalink ) return 0;

        $slug = rtrim( parse_url( $permalink, PHP_URL_PATH ) ?? '', '/' );
        if ( $slug === '' || $slug === '/' ) return 0;

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                 AND ID != %d
                 AND post_content LIKE %s",
                $post_id,
                '%' . $wpdb->esc_like( $slug ) . '%'
            )
        );

        return $count;
    }
}
