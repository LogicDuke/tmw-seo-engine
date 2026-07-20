<?php
/**
 * CategoryFormulaRepository — Persistence layer for category formulas.
 *
 * Handles all reads and writes for:
 *  - tmw_seo_category_formulas
 *  - tmw_seo_category_formula_conditions
 *  - tmw_seo_category_assignment_logs
 *
 * @package TMWSEO\Engine\CategoryFormulas
 * @since   5.2.0
 */
namespace TMWSEO\Engine\CategoryFormulas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryFormulaRepository {

    // ── Table names ──────────────────────────────────────────────────────────

    /** @return string */
    public static function formulas_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_seo_category_formulas';
    }

    /** @return string */
    public static function conditions_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_seo_category_formula_conditions';
    }

    /** @return string */
    public static function logs_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_seo_category_assignment_logs';
    }

    // ── Formula reads ────────────────────────────────────────────────────────

    /**
     * Return all formulas.
     *
     * @param string|null $status
     * @return array<int,object>
     */
    public function get_all( ?string $status = null ): array {
        global $wpdb;
        $t = self::formulas_table();
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
     * Return a single formula by ID.
     *
     * @param int $id
     * @return object|null
     */
    public function get_by_id( int $id ): ?object {
        global $wpdb;
        $t = self::formulas_table();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE id = %d", $id ) ) ?: null;
    }

    /**
     * Return a single formula by key.
     *
     * @param string $key
     * @return object|null
     */
    public function get_by_key( string $key ): ?object {
        global $wpdb;
        $t = self::formulas_table();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE formula_key = %s", $key ) ) ?: null;
    }

    // ── Condition reads ──────────────────────────────────────────────────────

    /**
     * Return all conditions for a formula.
     *
     * @param int         $formula_id
     * @param string|null $type       Optionally filter by condition_type.
     * @return array<int,object>
     */
    public function get_conditions( int $formula_id, ?string $type = null ): array {
        global $wpdb;
        $t = self::conditions_table();
        if ( $type !== null ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$t}` WHERE formula_id = %d AND condition_type = %s ORDER BY sort_order ASC",
                    $formula_id,
                    $type
                )
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$t}` WHERE formula_id = %d ORDER BY condition_type ASC, sort_order ASC",
                    $formula_id
                )
            );
        }
        return $rows ?: [];
    }

    /**
     * Return group IDs for required_group conditions of a formula.
     *
     * @param int $formula_id
     * @return int[]
     */
    public function get_required_group_ids( int $formula_id ): array {
        global $wpdb;
        $t    = self::conditions_table();
        $ids  = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT group_id FROM `{$t}` WHERE formula_id = %d AND condition_type = 'required_group' ORDER BY sort_order ASC",
                $formula_id
            )
        );
        return array_map( 'intval', $ids ?: [] );
    }

    /**
     * Return group IDs for excluded_group conditions of a formula.
     *
     * @param int $formula_id
     * @return int[]
     */
    public function get_excluded_group_ids( int $formula_id ): array {
        global $wpdb;
        $t    = self::conditions_table();
        $ids  = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT group_id FROM `{$t}` WHERE formula_id = %d AND condition_type = 'excluded_group' ORDER BY sort_order ASC",
                $formula_id
            )
        );
        return array_map( 'intval', $ids ?: [] );
    }

    // ── Formula writes ───────────────────────────────────────────────────────

    /**
     * Create a new formula.
     *
     * @param array $data
     * @return int|false
     */
    public function create( array $data ) {
        global $wpdb;
        $now = current_time( 'mysql' );
        $ok  = $wpdb->insert(
            self::formulas_table(),
            [
                'formula_key'      => sanitize_key( $data['formula_key'] ),
                'label'            => sanitize_text_field( $data['label'] ),
                'target_taxonomy'  => sanitize_key( $data['target_taxonomy'] ?? 'category' ),
                'target_term_id'   => (int) ( $data['target_term_id'] ?? 0 ),
                'source_taxonomy'  => sanitize_key( $data['source_taxonomy'] ?? 'post_tag' ),
                'post_type'        => sanitize_key( $data['post_type'] ?? 'post' ),
                'status'           => sanitize_key( $data['status'] ?? 'active' ),
                'notes'            => sanitize_textarea_field( $data['notes'] ?? '' ),
                'created_at'       => $now,
                'updated_at'       => $now,
            ],
            [ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
        return $ok ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update an existing formula.
     *
     * @param int   $id
     * @param array $data
     * @return bool
     */
    public function update( int $id, array $data ): bool {
        global $wpdb;
        $ok = $wpdb->update(
            self::formulas_table(),
            [
                'formula_key'      => sanitize_key( $data['formula_key'] ),
                'label'            => sanitize_text_field( $data['label'] ),
                'target_taxonomy'  => sanitize_key( $data['target_taxonomy'] ?? 'category' ),
                'target_term_id'   => (int) ( $data['target_term_id'] ?? 0 ),
                'source_taxonomy'  => sanitize_key( $data['source_taxonomy'] ?? 'post_tag' ),
                'post_type'        => sanitize_key( $data['post_type'] ?? 'post' ),
                'status'           => sanitize_key( $data['status'] ?? 'active' ),
                'notes'            => sanitize_textarea_field( $data['notes'] ?? '' ),
                'updated_at'       => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );
        return $ok !== false;
    }

    /**
     * Delete a formula and its conditions.
     *
     * @param int $id
     * @return bool
     */
    public function delete( int $id ): bool {
        global $wpdb;
        $wpdb->delete( self::conditions_table(), [ 'formula_id' => $id ], [ '%d' ] );
        $ok = $wpdb->delete( self::formulas_table(), [ 'id' => $id ], [ '%d' ] );
        return $ok !== false;
    }

    /**
     * Update dry run statistics on the formula row.
     *
     * @param int $formula_id
     * @param int $matched
     * @param int $missing
     * @return void
     */
    public function update_dry_run_stats( int $formula_id, int $matched, int $missing ): void {
        global $wpdb;
        $wpdb->update(
            self::formulas_table(),
            [
                'last_dry_run_at'      => current_time( 'mysql' ),
                'last_dry_run_matched' => $matched,
                'last_dry_run_missing' => $missing,
                'updated_at'           => current_time( 'mysql' ),
            ],
            [ 'id' => $formula_id ],
            [ '%s', '%d', '%d', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Update backfill statistics on the formula row.
     *
     * @param int $formula_id
     * @param int $changed
     * @return void
     */
    public function update_backfill_stats( int $formula_id, int $changed ): void {
        global $wpdb;
        $wpdb->update(
            self::formulas_table(),
            [
                'last_backfill_at'      => current_time( 'mysql' ),
                'last_backfill_changed' => $changed,
                'updated_at'            => current_time( 'mysql' ),
            ],
            [ 'id' => $formula_id ],
            [ '%s', '%d', '%s' ],
            [ '%d' ]
        );
    }

    // ── Condition writes ─────────────────────────────────────────────────────

    /**
     * Replace all conditions for a formula.
     *
     * @param int   $formula_id
     * @param int[] $required_group_ids
     * @param int[] $excluded_group_ids
     * @return void
     */
    public function sync_conditions( int $formula_id, array $required_group_ids, array $excluded_group_ids ): void {
        global $wpdb;
        $ct  = self::conditions_table();
        $now = current_time( 'mysql' );

        // Delete all existing conditions for this formula.
        $wpdb->delete( $ct, [ 'formula_id' => $formula_id ], [ '%d' ] );

        $order = 0;
        foreach ( array_unique( array_map( 'intval', $required_group_ids ) ) as $gid ) {
            if ( $gid <= 0 ) { continue; }
            $wpdb->insert(
                $ct,
                [
                    'formula_id'     => $formula_id,
                    'condition_type' => 'required_group',
                    'group_id'       => $gid,
                    'sort_order'     => $order++,
                    'created_at'     => $now,
                ],
                [ '%d', '%s', '%d', '%d', '%s' ]
            );
        }

        $order = 0;
        foreach ( array_unique( array_map( 'intval', $excluded_group_ids ) ) as $gid ) {
            if ( $gid <= 0 ) { continue; }
            $wpdb->insert(
                $ct,
                [
                    'formula_id'     => $formula_id,
                    'condition_type' => 'excluded_group',
                    'group_id'       => $gid,
                    'sort_order'     => $order++,
                    'created_at'     => $now,
                ],
                [ '%d', '%s', '%d', '%d', '%s' ]
            );
        }
    }

    // ── Audit log ────────────────────────────────────────────────────────────

    /**
     * Write one audit log entry.
     *
     * @param array $data  Keys: formula_id, post_id, action, result, before_term_ids, after_term_ids, message
     * @return void
     */
    public function write_log( array $data ): void {
        global $wpdb;
        $wpdb->insert(
            self::logs_table(),
            [
                'formula_id'     => (int) ( $data['formula_id'] ?? 0 ),
                'post_id'        => (int) ( $data['post_id'] ?? 0 ),
                'action'         => sanitize_key( $data['action'] ?? 'backfill' ),
                'result'         => sanitize_key( $data['result'] ?? 'ok' ),
                'before_term_ids'=> maybe_serialize( $data['before_term_ids'] ?? [] ),
                'after_term_ids' => maybe_serialize( $data['after_term_ids'] ?? [] ),
                'message'        => sanitize_text_field( $data['message'] ?? '' ),
                'created_at'     => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Return paginated audit log rows.
     *
     * @param int $per_page
     * @param int $offset
     * @return array<int,object>
     */
    public function get_logs( int $per_page = 50, int $offset = 0 ): array {
        global $wpdb;
        $lt = self::logs_table();
        $ft = self::formulas_table();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.*, f.label AS formula_label
                   FROM `{$lt}` l
              LEFT JOIN `{$ft}` f ON f.id = l.formula_id
                  ORDER BY l.created_at DESC
                  LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );
        return $rows ?: [];
    }

    /**
     * Total log row count for pagination.
     *
     * @return int
     */
    public function get_logs_count(): int {
        global $wpdb;
        $lt = self::logs_table();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$lt}`" );
    }
}
