<?php
/**
 * TMW SEO Engine — Keyword Cluster Reconciler (v1.0.0)
 *
 * Provides:
 *  - A canonical cluster identity helper used by all writers.
 *  - A safe, idempotent, admin-triggered repair routine that merges
 *    duplicate rows in tmw_keyword_clusters that resolve to the same
 *    canonical base key (i.e. rows that differ only in intent/entity
 *    suffix but share the same human-visible representative keyword).
 *
 * Safety contract:
 *  - NEVER runs automatically on page load.
 *  - Dry-run mode returns what WOULD be merged without touching the DB.
 *  - Destructive mode only deletes sibling rows AFTER cluster_map has
 *    been rewired to the surviving row.
 *  - Idempotent: running twice is safe.
 *  - No schema changes required.
 *
 * Admin trigger:
 *  - Dry-run:  POST admin-post.php  action=tmwseo_reconcile_clusters  mode=dry
 *  - Execute:  POST admin-post.php  action=tmwseo_reconcile_clusters  mode=execute
 *  - Both protected by nonce  tmwseo_reconcile_clusters_nonce
 *
 * @package TMWSEO\Engine\Keywords
 * @since   5.3.1
 */

namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class KeywordClusterReconciler {

    // ── Boot ─────────────────────────────────────────────────────────────

    public static function init(): void {
        add_action( 'admin_post_tmwseo_reconcile_clusters', [ __CLASS__, 'handle_admin_action' ] );
    }

    // ── Canonical identity ────────────────────────────────────────────────

    /**
     * Derive the canonical base key for a cluster_key string.
     *
     * Strips known intent/entity suffixes added by KeywordEngine so that
     * rows like  "adult cam"  and  "adult cam:intent:generic"  resolve to
     * the same canonical string: "adult cam".
     *
     * Entity-scoped clusters (entity:model:42:generic) are NOT collapsed
     * into base keys — they are canonical unto themselves.
     */
    public static function canonical_base( string $cluster_key ): string {
        // Entity-scoped key — already canonical, do not strip.
        if ( strncmp( $cluster_key, 'entity:', 7 ) === 0 ) {
            return $cluster_key;
        }

        // Strip trailing :intent:<word> suffix added by KeywordEngine.
        $stripped = preg_replace( '/:intent:[a-z]+$/', '', $cluster_key );

        // Strip trailing :md5hash suffix added as a uniquifier.
        $stripped = preg_replace( '/:[0-9a-f]{32}$/', '', (string) $stripped );

        return trim( (string) $stripped );
    }

    // ── Repair routine ────────────────────────────────────────────────────

    /**
     * Run the reconciliation.
     *
     * @param bool $dry_run  When true, return analysis without DB writes.
     * @return array{
     *   mode: string,
     *   groups_found: int,
     *   rows_examined: int,
     *   merges_performed: int,
     *   map_rows_rewired: int,
     *   siblings_deleted: int,
     *   groups: array,
     *   errors: array,
     * }
     */
    public static function run( bool $dry_run = true ): array {
        global $wpdb;

        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';
        $map_table     = $wpdb->prefix . 'tmw_keyword_cluster_map';
        $map_exists    = (string) $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $map_table )
        ) === $map_table;

        // ── 1. Fetch all rows ─────────────────────────────────────────────
        $all_rows = (array) $wpdb->get_results(
            "SELECT id, cluster_key, representative, status, total_volume, avg_difficulty,
                    opportunity, page_id, keywords, clustered_at, updated_at
             FROM {$cluster_table}
             ORDER BY id ASC",
            ARRAY_A
        );

        if ( empty( $all_rows ) ) {
            return self::empty_result( $dry_run );
        }

        // ── 2. Group by canonical base key ────────────────────────────────
        $groups = []; // canonical_base → [ rows ]
        foreach ( $all_rows as $row ) {
            $base = self::canonical_base( (string) $row['cluster_key'] );
            $groups[ $base ][] = $row;
        }

        // Only groups with >1 member are duplicates.
        $duplicate_groups = array_filter( $groups, static fn( $g ) => count( $g ) > 1 );

        $result = [
            'mode'              => $dry_run ? 'dry_run' : 'execute',
            'groups_found'      => count( $duplicate_groups ),
            'rows_examined'     => count( $all_rows ),
            'merges_performed'  => 0,
            'map_rows_rewired'  => 0,
            'siblings_deleted'  => 0,
            'groups'            => [],
            'errors'            => [],
        ];

        if ( empty( $duplicate_groups ) ) {
            return $result;
        }

        // ── 3. Process each duplicate group ──────────────────────────────
        foreach ( $duplicate_groups as $canonical_base => $rows ) {
            $group_info = self::describe_group( $canonical_base, $rows );

            if ( $dry_run ) {
                $result['groups'][] = $group_info;
                continue;
            }

            // ── Execute: pick survivor, merge, remap, delete ──────────────
            $survivor_id = $group_info['survivor_id'];
            $sibling_ids = $group_info['sibling_ids'];
            $merged      = $group_info['merged_data'];

            // 3a. Write merged state to survivor.
            $update_ok = $wpdb->update(
                $cluster_table,
                [
                    'representative' => $merged['representative'],
                    'status'         => $merged['status'],
                    'page_id'        => $merged['page_id'],
                    'total_volume'   => $merged['total_volume'],
                    'avg_difficulty' => $merged['avg_difficulty'],
                    'opportunity'    => $merged['opportunity'],
                    'keywords'       => $merged['keywords_json'],
                    'clustered_at'   => $merged['clustered_at'],
                    'updated_at'     => current_time( 'mysql' ),
                ],
                [ 'id' => $survivor_id ]
            );

            if ( $update_ok === false ) {
                $result['errors'][] = "Failed to update survivor id={$survivor_id}: " . $wpdb->last_error;
                $result['groups'][] = array_merge( $group_info, [ 'error' => 'survivor_update_failed' ] );
                continue;
            }

            // 3b. Rewire cluster_map rows from siblings to survivor.
            $rewired = 0;
            if ( $map_exists && ! empty( $sibling_ids ) ) {
                $placeholders = implode( ', ', array_fill( 0, count( $sibling_ids ), '%d' ) );
                $rewired      = (int) $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$map_table} SET cluster_id = %d, updated_at = %s
                         WHERE cluster_id IN ({$placeholders})",
                        array_merge( [ $survivor_id, current_time( 'mysql' ) ], $sibling_ids )
                    )
                );
            }

            // 3c. Delete siblings (only after remap is confirmed complete).
            $deleted = 0;
            if ( ! empty( $sibling_ids ) ) {
                $placeholders = implode( ', ', array_fill( 0, count( $sibling_ids ), '%d' ) );
                $deleted      = (int) $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$cluster_table} WHERE id IN ({$placeholders})",
                        $sibling_ids
                    )
                );
            }

            $result['merges_performed']++;
            $result['map_rows_rewired'] += $rewired;
            $result['siblings_deleted']  += $deleted;
            $result['groups'][]           = array_merge( $group_info, [
                'executed'        => true,
                'map_rows_rewired'=> $rewired,
                'siblings_deleted'=> $deleted,
            ] );

            Logs::info( 'clusters', '[TMW-CLUSTER-RECONCILE] Merged duplicate cluster group', [
                'canonical_base' => $canonical_base,
                'survivor_id'    => $survivor_id,
                'sibling_ids'    => $sibling_ids,
                'rewired'        => $rewired,
                'deleted'        => $deleted,
            ] );
        }

        return $result;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Describe one duplicate group and compute merged state.
     *
     * Survivor selection priority:
     *  1. Any row already marked built (has page_id > 0).
     *  2. If tied, the row with the lowest id (oldest canonical row).
     *
     * Merged state:
     *  - status:      'built' if any sibling is built, else 'new'
     *  - page_id:     first non-null page_id found
     *  - representative: from the built row if available, else highest-opportunity row
     *  - total_volume: sum across siblings
     *  - avg_difficulty: weighted average
     *  - opportunity:  max across siblings
     *  - keywords:     union of all keyword lists
     *  - clustered_at: newest non-null value
     */
    private static function describe_group( string $canonical_base, array $rows ): array {
        // Sort by: built-first, then id ASC.
        usort( $rows, static function ( $a, $b ) {
            $a_built = (int) $a['page_id'] > 0 ? 0 : 1;
            $b_built = (int) $b['page_id'] > 0 ? 0 : 1;
            if ( $a_built !== $b_built ) { return $a_built - $b_built; }
            return (int) $a['id'] - (int) $b['id'];
        } );

        $survivor  = $rows[0];
        $siblings  = array_slice( $rows, 1 );
        $sibling_ids = array_map( static fn( $r ) => (int) $r['id'], $siblings );

        // Merge metrics.
        $best_page_id       = 0;
        $best_status        = 'new';
        $best_representative= (string) $survivor['representative'];
        $total_volume       = 0;
        $sum_kd             = 0.0;
        $kd_n               = 0;
        $best_opportunity   = 0.0;
        $all_keywords       = [];
        $newest_clustered   = null;

        foreach ( $rows as $row ) {
            $pid = (int) ( $row['page_id'] ?? 0 );
            if ( $pid > 0 && $best_page_id === 0 ) { $best_page_id = $pid; }
            if ( (string) ( $row['status'] ?? '' ) === 'built' ) { $best_status = 'built'; }

            $total_volume += (int) ( $row['total_volume'] ?? 0 );
            $kd = (float) ( $row['avg_difficulty'] ?? 0 );
            if ( $kd > 0 ) { $sum_kd += $kd; $kd_n++; }

            $opp = (float) ( $row['opportunity'] ?? 0 );
            if ( $opp > $best_opportunity ) {
                $best_opportunity   = $opp;
                $best_representative = (string) ( $row['representative'] ?: $best_representative );
            }

            // Keyword list.
            $kw_json = (string) ( $row['keywords'] ?? '[]' );
            $kws     = json_decode( $kw_json, true );
            if ( is_array( $kws ) ) {
                foreach ( $kws as $kw ) {
                    if ( is_string( $kw ) && $kw !== '' ) {
                        $all_keywords[] = $kw;
                    }
                }
            }

            // Clustered_at: keep newest.
            $ca = (string) ( $row['clustered_at'] ?? '' );
            if ( $ca !== '' && ( $newest_clustered === null || $ca > $newest_clustered ) ) {
                $newest_clustered = $ca;
            }
        }

        $avg_kd         = $kd_n > 0 ? round( $sum_kd / $kd_n, 2 ) : 0.0;
        $merged_keywords = array_values( array_unique( $all_keywords ) );

        return [
            'canonical_base'  => $canonical_base,
            'survivor_id'     => (int) $survivor['id'],
            'survivor_key'    => (string) $survivor['cluster_key'],
            'sibling_ids'     => $sibling_ids,
            'sibling_keys'    => array_map( static fn( $r ) => (string) $r['cluster_key'], $siblings ),
            'row_count'       => count( $rows ),
            'merged_data'     => [
                'representative'=> $best_representative,
                'status'        => $best_status,
                'page_id'       => $best_page_id > 0 ? $best_page_id : null,
                'total_volume'  => $total_volume,
                'avg_difficulty'=> $avg_kd,
                'opportunity'   => $best_opportunity,
                'keywords_json' => wp_json_encode( $merged_keywords ),
                'clustered_at'  => $newest_clustered ?? current_time( 'mysql' ),
            ],
        ];
    }

    private static function empty_result( bool $dry_run ): array {
        return [
            'mode'             => $dry_run ? 'dry_run' : 'execute',
            'groups_found'     => 0,
            'rows_examined'    => 0,
            'merges_performed' => 0,
            'map_rows_rewired' => 0,
            'siblings_deleted' => 0,
            'groups'           => [],
            'errors'           => [],
        ];
    }

    // ── Admin action handler ──────────────────────────────────────────────

    /**
     * Handles admin-post.php?action=tmwseo_reconcile_clusters
     * Requires nonce tmwseo_reconcile_clusters_nonce.
     * Stores result in transient, redirects back to Keywords → Keyword Clusters page.
     */
    public static function handle_admin_action(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden' );
        }
        check_admin_referer( 'tmwseo_reconcile_clusters_nonce' );

        $mode    = isset( $_POST['mode'] ) && sanitize_key( $_POST['mode'] ) === 'execute' ? 'execute' : 'dry';
        $dry_run = $mode !== 'execute';

        $result = self::run( $dry_run );
        set_transient( 'tmwseo_reconcile_result', $result, 5 * MINUTE_IN_SECONDS );

        wp_safe_redirect( add_query_arg(
            [ 'page' => 'tmwseo-keywords', 'view' => 'clusters', 'reconcile' => $mode ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }
}
