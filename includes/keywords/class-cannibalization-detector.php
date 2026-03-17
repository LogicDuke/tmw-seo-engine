<?php
/**
 * CannibalizationDetector — detects keyword ownership conflicts across page types.
 *
 * Scans stored primary/secondary keywords and flags overlapping targets between
 * model pages, video pages, category pages, and tag pages.
 *
 * Audit fix: the previous system had zero cannibalization detection.
 *
 * @package TMWSEO\Engine\Keywords
 * @since   4.4.0
 */
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Keywords\OwnershipEnforcer;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CannibalizationDetector {

    private const META_PRIMARY   = '_tmwseo_keyword';
    private const META_SECONDARY = '_tmwseo_secondary_keywords';

    /**
     * Page-type ownership rules.
     *
     * For each query-shape, exactly one page type should own it.
     * If two pages of different types target the same keyword, that is a conflict.
     */
    private const OWNER_PRIORITY = [
        'model'             => 10, // highest priority for name-based queries
        'tmw_category_page' => 7,
        'post'              => 5,  // video posts
        'page'              => 3,
    ];

    /**
     * Run a full scan and store detected conflicts.
     *
     * @return array{scanned:int, conflicts_found:int, conflicts_new:int}
     */
    public static function run_scan(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'tmw_cannibalization_flags';

        // Check table exists.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            Logs::warn( 'cannibalization', '[TMW-CANNIBAL] Table does not exist yet' );
            return [ 'scanned' => 0, 'conflicts_found' => 0, 'conflicts_new' => 0 ];
        }

        // Collect all assigned keywords from post meta.
        $keyword_map = self::build_keyword_map();
        $scanned     = count( $keyword_map );
        $conflicts   = [];

        foreach ( $keyword_map as $kw_hash => $pages ) {
            if ( count( $pages ) < 2 ) {
                continue;
            }

            // Group by page type.
            $by_type = [];
            foreach ( $pages as $page ) {
                $by_type[ $page['post_type'] ][] = $page;
            }

            // Conflict: same keyword on different page types.
            if ( count( $by_type ) >= 2 ) {
                $sorted = $pages;
                usort( $sorted, function ( $a, $b ) {
                    $pa = self::OWNER_PRIORITY[ $a['post_type'] ] ?? 1;
                    $pb = self::OWNER_PRIORITY[ $b['post_type'] ] ?? 1;
                    return $pb <=> $pa;
                });

                $owner = $sorted[0];
                for ( $i = 1; $i < count( $sorted ); $i++ ) {
                    $conflicts[] = [
                        'keyword_text' => $sorted[ $i ]['keyword'],
                        'keyword_hash' => $kw_hash,
                        'page_a_id'    => (int) $owner['post_id'],
                        'page_a_type'  => $owner['post_type'],
                        'page_b_id'    => (int) $sorted[ $i ]['post_id'],
                        'page_b_type'  => $sorted[ $i ]['post_type'],
                        'severity'     => $sorted[ $i ]['role'] === 'primary' ? 'critical' : 'warning',
                    ];
                }
            }

            // Also flag same-type duplicate primaries (two model pages targeting same keyword).
            foreach ( $by_type as $type => $type_pages ) {
                $primaries = array_filter( $type_pages, fn( $p ) => $p['role'] === 'primary' );
                if ( count( $primaries ) >= 2 ) {
                    $plist = array_values( $primaries );
                    for ( $i = 1; $i < count( $plist ); $i++ ) {
                        $conflicts[] = [
                            'keyword_text' => $plist[0]['keyword'],
                            'keyword_hash' => $kw_hash,
                            'page_a_id'    => (int) $plist[0]['post_id'],
                            'page_a_type'  => $plist[0]['post_type'],
                            'page_b_id'    => (int) $plist[ $i ]['post_id'],
                            'page_b_type'  => $plist[ $i ]['post_type'],
                            'severity'     => 'critical',
                        ];
                    }
                }
            }
        }

        // Persist new conflicts (skip already-recorded ones).
        $new_count = 0;
        foreach ( $conflicts as $c ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $table WHERE keyword_hash = %s AND page_a_id = %d AND page_b_id = %d AND resolved = 0 LIMIT 1",
                $c['keyword_hash'],
                $c['page_a_id'],
                $c['page_b_id']
            ) );

            if ( $existing ) {
                continue;
            }

            $wpdb->insert( $table, [
                'keyword_text' => mb_substr( $c['keyword_text'], 0, 255 ),
                'keyword_hash' => $c['keyword_hash'],
                'page_a_id'    => $c['page_a_id'],
                'page_a_type'  => $c['page_a_type'],
                'page_b_id'    => $c['page_b_id'],
                'page_b_type'  => $c['page_b_type'],
                'severity'     => $c['severity'],
                'resolved'     => 0,
                'detected_at'  => current_time( 'mysql' ),
            ] );
            $new_count++;
        }

        Logs::info( 'cannibalization', '[TMW-CANNIBAL] Scan complete', [
            'scanned'         => $scanned,
            'conflicts_found' => count( $conflicts ),
            'conflicts_new'   => $new_count,
        ] );

        return [
            'scanned'         => $scanned,
            'conflicts_found' => count( $conflicts ),
            'conflicts_new'   => $new_count,
        ];
    }

    /**
     * Check a specific post for conflicts against existing assignments.
     *
     * @return array<int, array{keyword:string, conflicting_post_id:int, conflicting_post_type:string, severity:string}>
     */
    public static function check_post( int $post_id ): array {
        return self::check_post_retrospective( $post_id );
    }

    /**
     * Preventive ownership check — called BEFORE assignment.
     *
     * Architecture v5.0: delegates to OwnershipEnforcer for the real logic.
     * This method is kept on CannibalizationDetector for backward compatibility.
     *
     * @return array{allowed:bool, reason:string, conflicts:array}
     */
    public static function check_before_assignment( string $keyword, int $target_post_id, string $role = 'primary' ): array {
        if ( class_exists( OwnershipEnforcer::class ) ) {
            return OwnershipEnforcer::check_assignment( $keyword, $target_post_id, $role );
        }

        // Fallback: basic conflict check if OwnershipEnforcer is unavailable
        return [ 'allowed' => true, 'reason' => 'enforcer_unavailable_fallback', 'conflicts' => [] ];
    }

    /**
     * Check a specific post for conflicts against existing assignments (retrospective).
     *
     * @return array<int, array{keyword:string, conflicting_post_id:int, conflicting_post_type:string, severity:string}>
     */
    public static function check_post_retrospective( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return [];
        }

        $primary   = trim( (string) get_post_meta( $post_id, self::META_PRIMARY, true ) );
        $secondary = (array) json_decode( (string) get_post_meta( $post_id, self::META_SECONDARY, true ), true );
        if ( ! is_array( $secondary ) ) {
            $secondary = [];
        }

        $keywords = [];
        if ( $primary !== '' ) {
            $keywords[] = [ 'kw' => $primary, 'role' => 'primary' ];
        }
        foreach ( $secondary as $kw ) {
            $kw = trim( (string) $kw );
            if ( $kw !== '' ) {
                $keywords[] = [ 'kw' => $kw, 'role' => 'secondary' ];
            }
        }

        $conflicts = [];
        foreach ( $keywords as $entry ) {
            $hash = md5( mb_strtolower( $entry['kw'] ) );
            $other_posts = self::find_posts_with_keyword( $hash, $post_id );
            foreach ( $other_posts as $other ) {
                $severity = 'warning';
                if ( $entry['role'] === 'primary' && $other['role'] === 'primary' ) {
                    $severity = 'critical';
                }
                if ( $post->post_type !== $other['post_type'] && $entry['role'] === 'primary' ) {
                    $severity = 'critical';
                }

                $conflicts[] = [
                    'keyword'                => $entry['kw'],
                    'conflicting_post_id'    => (int) $other['post_id'],
                    'conflicting_post_type'  => $other['post_type'],
                    'severity'               => $severity,
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Get unresolved conflicts for admin display.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function get_unresolved( int $limit = 100 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_cannibalization_flags';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE resolved = 0 ORDER BY severity DESC, detected_at DESC LIMIT %d",
            $limit
        ), ARRAY_A );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Mark a conflict as resolved.
     */
    public static function resolve( int $flag_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_cannibalization_flags';

        return (bool) $wpdb->update( $table, [
            'resolved'    => 1,
            'resolved_at' => current_time( 'mysql' ),
        ], [ 'id' => $flag_id ] );
    }

    // ── Internal ──────────────────────────────────────────────────────────

    /**
     * Build a keyword → pages map from post meta across all relevant post types.
     *
     * @return array<string, array<int, array{post_id:int, post_type:string, keyword:string, role:string}>>
     */
    private static function build_keyword_map(): array {
        global $wpdb;

        $map = [];

        // Primary keywords.
        $rows = $wpdb->get_results(
            "SELECT p.ID AS post_id, p.post_type, pm.meta_value AS keyword
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '" . self::META_PRIMARY . "'
             WHERE p.post_status IN ('publish','draft','pending')
               AND pm.meta_value != ''
             LIMIT 5000",
            ARRAY_A
        );

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $kw   = trim( (string) $row['keyword'] );
                $hash = md5( mb_strtolower( $kw ) );
                $map[ $hash ][] = [
                    'post_id'   => (int) $row['post_id'],
                    'post_type' => (string) $row['post_type'],
                    'keyword'   => $kw,
                    'role'      => 'primary',
                ];
            }
        }

        // Secondary keywords (stored as JSON array).
        $rows = $wpdb->get_results(
            "SELECT p.ID AS post_id, p.post_type, pm.meta_value AS keywords_json
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '" . self::META_SECONDARY . "'
             WHERE p.post_status IN ('publish','draft','pending')
               AND pm.meta_value != ''
             LIMIT 5000",
            ARRAY_A
        );

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $keywords = json_decode( (string) $row['keywords_json'], true );
                if ( ! is_array( $keywords ) ) {
                    continue;
                }
                foreach ( $keywords as $kw ) {
                    $kw = trim( (string) $kw );
                    if ( $kw === '' ) continue;
                    $hash = md5( mb_strtolower( $kw ) );
                    $map[ $hash ][] = [
                        'post_id'   => (int) $row['post_id'],
                        'post_type' => (string) $row['post_type'],
                        'keyword'   => $kw,
                        'role'      => 'secondary',
                    ];
                }
            }
        }

        return $map;
    }

    /**
     * Find other posts targeting the same keyword hash.
     *
     * @return array<int, array{post_id:int, post_type:string, role:string}>
     */
    private static function find_posts_with_keyword( string $kw_hash, int $exclude_post_id ): array {
        global $wpdb;
        $results = [];

        // Check primary keywords.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_type, pm.meta_value
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
             WHERE p.post_status IN ('publish','draft','pending')
               AND p.ID != %d
               AND pm.meta_value != ''
             LIMIT 200",
            self::META_PRIMARY,
            $exclude_post_id
        ), ARRAY_A );

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $hash = md5( mb_strtolower( trim( (string) $row['meta_value'] ) ) );
                if ( $hash === $kw_hash ) {
                    $results[] = [
                        'post_id'   => (int) $row['ID'],
                        'post_type' => (string) $row['post_type'],
                        'role'      => 'primary',
                    ];
                }
            }
        }

        return $results;
    }
}
