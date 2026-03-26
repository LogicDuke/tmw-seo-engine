<?php
/**
 * SignalGroupRepository — Persistence layer for signal groups.
 *
 * Handles all reads and writes for:
 *  - tmw_seo_signal_groups
 *  - tmw_seo_signal_group_terms
 *
 * @package TMWSEO\Engine\CategoryFormulas
 * @since   5.2.0
 */
namespace TMWSEO\Engine\CategoryFormulas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SignalGroupRepository {

    // ── Table names ──────────────────────────────────────────────────────────

    /** @return string */
    public static function groups_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_seo_signal_groups';
    }

    /** @return string */
    public static function terms_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_seo_signal_group_terms';
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * Return all signal groups (optionally filtered by status).
     *
     * @param string|null $status 'active' | 'inactive' | null = all
     * @return array<int,object>
     */
    public function get_all( ?string $status = null ): array {
        global $wpdb;
        $t = self::groups_table();
        if ( $status !== null ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM `{$t}` WHERE status = %s ORDER BY label ASC", $status )
            );
        } else {
            $rows = $wpdb->get_results( "SELECT * FROM `{$t}` ORDER BY label ASC" );
        }
        return $rows ?: [];
    }

    /**
     * Return a single group by ID, or null.
     *
     * @param int $id
     * @return object|null
     */
    public function get_by_id( int $id ): ?object {
        global $wpdb;
        $t = self::groups_table();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE id = %d", $id ) ) ?: null;
    }

    /**
     * Return a single group by key, or null.
     *
     * @param string $key
     * @return object|null
     */
    public function get_by_key( string $key ): ?object {
        global $wpdb;
        $t = self::groups_table();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE group_key = %s", $key ) ) ?: null;
    }

    /**
     * Return all terms mapped to a group.
     *
     * @param int $group_id
     * @return array<int,object>
     */
    public function get_terms_for_group( int $group_id ): array {
        global $wpdb;
        $t = self::terms_table();
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM `{$t}` WHERE group_id = %d ORDER BY term_name ASC", $group_id )
        );
        return $rows ?: [];
    }

    /**
     * Return a map of group_id → term count for all groups.
     *
     * @return array<int,int>
     */
    public function get_term_counts(): array {
        global $wpdb;
        $t    = self::terms_table();
        $rows = $wpdb->get_results( "SELECT group_id, COUNT(*) AS cnt FROM `{$t}` GROUP BY group_id" );
        $map  = [];
        foreach ( $rows ?: [] as $row ) {
            $map[ (int) $row->group_id ] = (int) $row->cnt;
        }
        return $map;
    }

    /**
     * Return all term IDs for a group (keyed by taxonomy).
     *
     * @param int    $group_id
     * @param string $taxonomy
     * @return int[]
     */
    public function get_term_ids_for_group( int $group_id, string $taxonomy = 'post_tag' ): array {
        global $wpdb;
        $t    = self::terms_table();
        $ids  = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT term_id FROM `{$t}` WHERE group_id = %d AND taxonomy = %s",
                $group_id,
                $taxonomy
            )
        );
        return array_map( 'intval', $ids ?: [] );
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * Create a new signal group.
     *
     * @param array $data  Keys: group_key, label, status
     * @return int|false   Inserted ID or false on failure.
     */
    public function create( array $data ) {
        global $wpdb;
        $now = current_time( 'mysql' );
        $ok  = $wpdb->insert(
            self::groups_table(),
            [
                'group_key'  => sanitize_key( $data['group_key'] ),
                'label'      => sanitize_text_field( $data['label'] ),
                'status'     => sanitize_key( $data['status'] ?? 'active' ),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );
        return $ok ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update an existing signal group.
     *
     * @param int   $id
     * @param array $data  Keys: group_key, label, status
     * @return bool
     */
    public function update( int $id, array $data ): bool {
        global $wpdb;
        $ok = $wpdb->update(
            self::groups_table(),
            [
                'group_key'  => sanitize_key( $data['group_key'] ),
                'label'      => sanitize_text_field( $data['label'] ),
                'status'     => sanitize_key( $data['status'] ?? 'active' ),
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );
        return $ok !== false;
    }

    /**
     * Delete a group and all its mapped terms.
     *
     * @param int $id
     * @return bool
     */
    public function delete( int $id ): bool {
        global $wpdb;
        // Delete child terms first.
        $wpdb->delete( self::terms_table(), [ 'group_id' => $id ], [ '%d' ] );
        $ok = $wpdb->delete( self::groups_table(), [ 'id' => $id ], [ '%d' ] );
        return $ok !== false;
    }

    /**
     * Replace all mapped terms for a group.
     *
     * Deletes ALL existing term rows for the group (regardless of taxonomy) before
     * inserting the new set. This prevents stale rows from lingering when a group's
     * taxonomy is changed from one value to another.
     *
     * @param int    $group_id
     * @param array  $term_ids  Array of WP term IDs.
     * @param string $taxonomy  Default 'post_tag'.
     * @return void
     */
    public function sync_terms( int $group_id, array $term_ids, string $taxonomy = 'post_tag' ): void {
        global $wpdb;
        $tt  = self::terms_table();
        $now = current_time( 'mysql' );

        // Remove ALL existing term rows for this group, regardless of taxonomy.
        // This prevents stale rows from old taxonomies surviving a taxonomy change.
        $wpdb->delete( $tt, [ 'group_id' => $group_id ], [ '%d' ] );

        if ( empty( $term_ids ) ) {
            return;
        }

        // Batch insert.
        foreach ( array_unique( array_map( 'intval', $term_ids ) ) as $term_id ) {
            $term = get_term( $term_id, $taxonomy );
            if ( ! $term || is_wp_error( $term ) ) {
                continue;
            }
            $wpdb->insert(
                $tt,
                [
                    'group_id'   => $group_id,
                    'taxonomy'   => $taxonomy,
                    'term_id'    => $term_id,
                    'term_slug'  => $term->slug,
                    'term_name'  => $term->name,
                    'created_at' => $now,
                ],
                [ '%d', '%s', '%d', '%s', '%s', '%s' ]
            );
        }
    }
}
