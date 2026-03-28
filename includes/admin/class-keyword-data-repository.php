<?php
/**
 * Keyword Data Repository — shared query helpers for the Keyword Data Explorer.
 *
 * Centralises all SQL used by the Trusted Seeds Explorer, Candidates Explorer,
 * and Import Pack detail views so neither admin page embeds raw queries inline.
 *
 * Architecture notes:
 *  - All methods are static; no instantiation needed.
 *  - All filter values are run through WordPress prepare() / sanitize_*().
 *  - Table existence is checked before every query; methods return safe empty
 *    arrays/zeros when tables are absent rather than crashing.
 *  - Column introspection (provenance columns, etc.) is memoised per request
 *    via static variables so SHOW COLUMNS is only called once per page load.
 *
 * @package TMWSEO\Engine\Admin
 * @since   5.3.0
 */

namespace TMWSEO\Engine\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KeywordDataRepository {

    // ─── Trusted sources (mirrors SeedRegistry::TRUSTED_DIRECT_SOURCES) ──────
    public const TRUSTED_SOURCES = [
        'manual',
        'model_root',
        'static_curated',
        'static',
        'approved_import',
        'csv_import',
    ];

    public const IMPORT_SOURCES = [ 'approved_import', 'csv_import' ];

    // ─── Memoisation ─────────────────────────────────────────────────────────

    /** @var bool|null */
    private static $seeds_table_exists = null;

    /** @var bool|null */
    private static $candidates_table_exists = null;

    /** @var bool|null */
    private static $has_provenance = null;

    // =========================================================================
    // Schema helpers
    // =========================================================================

    public static function seeds_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmwseo_seeds';
    }

    public static function candidates_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_seed_expansion_candidates';
    }

    public static function seeds_table_exists(): bool {
        if ( self::$seeds_table_exists !== null ) {
            return self::$seeds_table_exists;
        }
        global $wpdb;
        $t = self::seeds_table();
        self::$seeds_table_exists = ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t );
        return self::$seeds_table_exists;
    }

    public static function candidates_table_exists(): bool {
        if ( self::$candidates_table_exists !== null ) {
            return self::$candidates_table_exists;
        }
        global $wpdb;
        $t = self::candidates_table();
        self::$candidates_table_exists = ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t );
        return self::$candidates_table_exists;
    }

    /**
     * Returns true when the 4.3.0 provenance columns exist on tmwseo_seeds.
     */
    public static function has_provenance_columns(): bool {
        if ( self::$has_provenance !== null ) {
            return self::$has_provenance;
        }
        if ( ! self::seeds_table_exists() ) {
            self::$has_provenance = false;
            return false;
        }
        global $wpdb;
        $cols = $wpdb->get_results( 'SHOW COLUMNS FROM ' . self::seeds_table(), ARRAY_A );
        $col_names = is_array( $cols ) ? array_column( $cols, 'Field' ) : [];
        self::$has_provenance = in_array( 'import_batch_id', $col_names, true );
        return self::$has_provenance;
    }

    // =========================================================================
    // Summary / aggregate counts
    // =========================================================================

    /**
     * Returns the high-level summary bar numbers for the explorer header.
     *
     * @return array{
     *   total_seeds: int,
     *   imported_seeds: int,
     *   manual_seeds: int,
     *   model_root_seeds: int,
     *   static_curated_seeds: int,
     *   candidates_pending: int,
     *   candidates_approved: int,
     *   candidates_rejected: int,
     *   candidates_archived: int,
     *   import_packs: int,
     *   orphaned_db_packs: int,
     * }
     */
    public static function summary_counts(): array {
        global $wpdb;

        $out = [
            'total_seeds'          => 0,
            'imported_seeds'       => 0,
            'manual_seeds'         => 0,
            'model_root_seeds'     => 0,
            'static_curated_seeds' => 0,
            'candidates_pending'   => 0,
            'candidates_approved'  => 0,
            'candidates_rejected'  => 0,
            'candidates_archived'  => 0,
            'import_packs'         => 0,
            'orphaned_db_packs'    => 0,
        ];

        // ── Seeds ─────────────────────────────────────────────────────────
        if ( self::seeds_table_exists() ) {
            $t = self::seeds_table();
            $rows = $wpdb->get_results(
                "SELECT source, COUNT(*) AS cnt FROM {$t} GROUP BY source",
                ARRAY_A
            );
            if ( is_array( $rows ) ) {
                foreach ( $rows as $r ) {
                    $src = (string) $r['source'];
                    $cnt = (int) $r['cnt'];
                    $out['total_seeds'] += $cnt;
                    if ( in_array( $src, self::IMPORT_SOURCES, true ) ) {
                        $out['imported_seeds'] += $cnt;
                    }
                    if ( $src === 'manual' ) {
                        $out['manual_seeds'] = $cnt;
                    }
                    if ( $src === 'model_root' ) {
                        $out['model_root_seeds'] = $cnt;
                    }
                    if ( in_array( $src, [ 'static_curated', 'static' ], true ) ) {
                        $out['static_curated_seeds'] += $cnt;
                    }
                }
            }
        }

        // ── Candidates ────────────────────────────────────────────────────
        if ( self::candidates_table_exists() ) {
            $ct   = self::candidates_table();
            $rows = $wpdb->get_results(
                "SELECT status, COUNT(*) AS cnt FROM {$ct} GROUP BY status",
                ARRAY_A
            );
            if ( is_array( $rows ) ) {
                foreach ( $rows as $r ) {
                    $status = (string) $r['status'];
                    $cnt    = (int) $r['cnt'];
                    if ( in_array( $status, [ 'pending', 'fast_track' ], true ) ) {
                        $out['candidates_pending'] += $cnt;
                    } elseif ( $status === 'approved' ) {
                        $out['candidates_approved'] = $cnt;
                    } elseif ( $status === 'rejected' ) {
                        $out['candidates_rejected'] = $cnt;
                    } elseif ( $status === 'archived' ) {
                        $out['candidates_archived'] = $cnt;
                    }
                }
            }
        }

        // ── Import packs (filesystem) ──────────────────────────────────────
        $csv_dir = self::get_csv_dir();
        $files   = glob( $csv_dir . '*.csv' ) ?: [];
        $out['import_packs'] = count( $files );

        // ── Orphaned DB packs (DB rows but no matching file) ───────────────
        if ( self::seeds_table_exists() && self::has_provenance_columns() ) {
            $t       = self::seeds_table();
            $batches = $wpdb->get_col(
                "SELECT DISTINCT COALESCE(import_source_label,'') FROM {$t}
                 WHERE source IN ('approved_import','csv_import')
                   AND import_source_label IS NOT NULL
                   AND import_source_label != ''"
            );
            if ( is_array( $batches ) ) {
                foreach ( $batches as $label ) {
                    $file_path = realpath( $csv_dir . sanitize_file_name( (string) $label ) );
                    if ( $file_path === false || ! is_file( $file_path ) ) {
                        $out['orphaned_db_packs']++;
                    }
                }
            }
        }

        return $out;
    }

    // =========================================================================
    // Trusted Seeds Explorer
    // =========================================================================

    /**
     * Count trusted seeds with optional filters applied.
     *
     * @param array<string,string> $filters  Keys: search, source, seed_type, entity_type, batch_id, source_label
     */
    public static function count_trusted_seeds( array $filters = [] ): int {
        if ( ! self::seeds_table_exists() ) {
            return 0;
        }
        global $wpdb;
        $t = self::seeds_table();
        [ $where, $params ] = self::build_seeds_where( $filters );
        $sql = "SELECT COUNT(*) FROM {$t}" . ( $where ? " WHERE {$where}" : '' );
        return (int) ( $params
            ? $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) )
            : $wpdb->get_var( $sql )
        );
    }

    /**
     * Fetch a page of trusted seed rows.
     *
     * @param array<string,string> $filters
     * @param string               $orderby  Column name; validated against allowlist.
     * @param string               $order    ASC|DESC
     * @param int                  $limit
     * @param int                  $offset
     * @return list<array<string,mixed>>
     */
    public static function get_trusted_seeds(
        array  $filters  = [],
        string $orderby  = 'created_at',
        string $order    = 'DESC',
        int    $limit    = 50,
        int    $offset   = 0
    ): array {
        if ( ! self::seeds_table_exists() ) {
            return [];
        }
        global $wpdb;
        $t = self::seeds_table();

        $orderby = self::validate_seeds_orderby( $orderby );
        $order   = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';
        $limit   = max( 1, min( 500, $limit ) );

        [ $where, $params ] = self::build_seeds_where( $filters );

        $sql = "SELECT * FROM {$t}"
            . ( $where ? " WHERE {$where}" : '' )
            . " ORDER BY {$orderby} {$order}"
            . $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );

        $rows = $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Fetch all trusted seeds matching filters (for CSV export — no pagination).
     * Hard-capped at 50 000 rows to prevent OOM on huge tables.
     *
     * @param array<string,string> $filters
     * @return list<array<string,mixed>>
     */
    public static function get_trusted_seeds_for_export( array $filters = [] ): array {
        if ( ! self::seeds_table_exists() ) {
            return [];
        }
        global $wpdb;
        $t = self::seeds_table();
        [ $where, $params ] = self::build_seeds_where( $filters );
        $sql = "SELECT * FROM {$t}"
            . ( $where ? " WHERE {$where}" : '' )
            . ' ORDER BY created_at DESC'
            . $wpdb->prepare( ' LIMIT %d', 50000 );
        $rows = $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Delete one trusted seed row by ID (safe single-row delete).
     */
    public static function delete_seed_by_id( int $id ): bool {
        if ( ! self::seeds_table_exists() || $id <= 0 ) {
            return false;
        }
        global $wpdb;
        $result = $wpdb->delete( self::seeds_table(), [ 'id' => $id ], [ '%d' ] );
        return $result !== false && $result > 0;
    }

    /**
     * Delete multiple trusted seed rows by ID array.
     *
     * @param int[] $ids
     * @return int rows deleted
     */
    public static function delete_seeds_by_ids( array $ids ): int {
        $ids = array_filter( array_map( 'intval', $ids ), fn( $id ) => $id > 0 );
        if ( empty( $ids ) || ! self::seeds_table_exists() ) {
            return 0;
        }
        global $wpdb;
        $t          = self::seeds_table();
        $place_hold = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        return (int) $wpdb->query(
            $wpdb->prepare( "DELETE FROM {$t} WHERE id IN ({$place_hold})", ...$ids )
        );
    }

    // ─── Seeds WHERE builder ─────────────────────────────────────────────────

    /**
     * @param  array<string,string>  $filters
     * @return array{0:string,1:array<int,mixed>}  [where_clause, params]
     */
    private static function build_seeds_where( array $filters ): array {
        $parts  = [];
        $params = [];

        $search = trim( (string) ( $filters['search'] ?? '' ) );
        if ( $search !== '' ) {
            $parts[]  = 'seed LIKE %s';
            $params[] = '%' . $search . '%';
        }

        $source = sanitize_key( (string) ( $filters['source'] ?? '' ) );
        if ( $source !== '' && in_array( $source, self::TRUSTED_SOURCES, true ) ) {
            $parts[]  = 'source = %s';
            $params[] = $source;
        }

        $seed_type = sanitize_text_field( (string) ( $filters['seed_type'] ?? '' ) );
        if ( $seed_type !== '' ) {
            $parts[]  = 'seed_type = %s';
            $params[] = $seed_type;
        }

        $entity_type = sanitize_key( (string) ( $filters['entity_type'] ?? '' ) );
        if ( $entity_type !== '' ) {
            $parts[]  = 'entity_type = %s';
            $params[] = $entity_type;
        }

        $batch_id = sanitize_text_field( (string) ( $filters['batch_id'] ?? '' ) );
        if ( $batch_id !== '' && self::has_provenance_columns() ) {
            if ( $batch_id === '__none__' ) {
                $parts[] = "(import_batch_id IS NULL OR import_batch_id = '')";
            } else {
                $parts[]  = 'import_batch_id = %s';
                $params[] = $batch_id;
            }
        }

        $source_label = sanitize_text_field( (string) ( $filters['source_label'] ?? '' ) );
        if ( $source_label !== '' && self::has_provenance_columns() ) {
            $parts[]  = 'import_source_label = %s';
            $params[] = $source_label;
        }

        return [ implode( ' AND ', $parts ), $params ];
    }

    /** Validate orderby column against a strict allowlist. */
    private static function validate_seeds_orderby( string $col ): string {
        $allowed = [
            'id', 'seed', 'source', 'seed_type', 'priority', 'entity_type',
            'entity_id', 'created_at', 'last_used', 'last_expanded_at',
            'expansion_count', 'net_new_yielded', 'duplicates_returned',
            'estimated_spend_usd', 'roi_score', 'consecutive_zero_yield',
            'cooldown_until',
        ];
        return in_array( $col, $allowed, true ) ? $col : 'created_at';
    }

    // =========================================================================
    // Seeds distinct values (for filter dropdowns)
    // =========================================================================

    /** @return string[] */
    public static function distinct_seed_types(): array {
        if ( ! self::seeds_table_exists() ) {
            return [];
        }
        global $wpdb;
        $t    = self::seeds_table();
        $rows = $wpdb->get_col( "SELECT DISTINCT seed_type FROM {$t} WHERE seed_type IS NOT NULL AND seed_type != '' ORDER BY seed_type" );
        return is_array( $rows ) ? array_map( 'strval', $rows ) : [];
    }

    /** @return string[] */
    public static function distinct_entity_types(): array {
        if ( ! self::seeds_table_exists() ) {
            return [];
        }
        global $wpdb;
        $t    = self::seeds_table();
        $rows = $wpdb->get_col( "SELECT DISTINCT entity_type FROM {$t} WHERE entity_type IS NOT NULL AND entity_type != '' ORDER BY entity_type" );
        return is_array( $rows ) ? array_map( 'strval', $rows ) : [];
    }

    /** @return string[] */
    public static function distinct_import_batch_ids(): array {
        if ( ! self::seeds_table_exists() || ! self::has_provenance_columns() ) {
            return [];
        }
        global $wpdb;
        $t    = self::seeds_table();
        $rows = $wpdb->get_col( "SELECT DISTINCT import_batch_id FROM {$t} WHERE import_batch_id IS NOT NULL AND import_batch_id != '' ORDER BY import_batch_id DESC LIMIT 200" );
        return is_array( $rows ) ? array_map( 'strval', $rows ) : [];
    }

    /** @return string[] */
    public static function distinct_import_source_labels(): array {
        if ( ! self::seeds_table_exists() || ! self::has_provenance_columns() ) {
            return [];
        }
        global $wpdb;
        $t    = self::seeds_table();
        $rows = $wpdb->get_col( "SELECT DISTINCT import_source_label FROM {$t} WHERE import_source_label IS NOT NULL AND import_source_label != '' ORDER BY import_source_label" );
        return is_array( $rows ) ? array_map( 'strval', $rows ) : [];
    }

    // =========================================================================
    // Import Pack Linked Seeds
    // =========================================================================

    /**
     * Get all seed rows linked to a specific import pack.
     *
     * Matching strategy:
     *  - If provenance columns exist: match on (batch_id, source_label) pair.
     *  - Fallback: match on source only (legacy; show warning in caller).
     *
     * @return array{rows:list<array<string,mixed>>,legacy_fallback:bool,warning:string}
     */
    public static function get_seeds_for_pack(
        string $batch_id,
        string $source_label,
        string $source,
        int    $limit  = 200,
        int    $offset = 0
    ): array {
        if ( ! self::seeds_table_exists() ) {
            return [ 'rows' => [], 'legacy_fallback' => false, 'warning' => '' ];
        }

        global $wpdb;
        $t = self::seeds_table();

        if ( self::has_provenance_columns() ) {
            $where  = 'source = %s';
            $params = [ $source ];

            if ( $batch_id !== '' ) {
                $where   .= ' AND import_batch_id = %s';
                $params[] = $batch_id;
            } else {
                $where .= " AND (import_batch_id IS NULL OR import_batch_id = '')";
            }

            if ( $source_label !== '' ) {
                $where   .= ' AND import_source_label = %s';
                $params[] = $source_label;
            }

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$t} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                    ...[...$params, $limit, $offset]
                ),
                ARRAY_A
            );

            return [
                'rows'            => is_array( $rows ) ? $rows : [],
                'legacy_fallback' => false,
                'warning'         => '',
            ];
        }

        // Legacy: source-only fallback.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$t} WHERE source = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $source,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        return [
            'rows'            => is_array( $rows ) ? $rows : [],
            'legacy_fallback' => true,
            'warning'         => __( 'Provenance columns (import_batch_id, import_source_label) are missing from your database. Showing ALL rows with source "' . esc_html( $source ) . '" — this may include rows from multiple import packs. Deactivate and reactivate the plugin to run the 4.3.0 migration and gain precise per-pack filtering.', 'tmwseo' ),
        ];
    }

    /**
     * Count seeds for a pack (same matching logic as get_seeds_for_pack).
     */
    public static function count_seeds_for_pack(
        string $batch_id,
        string $source_label,
        string $source
    ): int {
        if ( ! self::seeds_table_exists() ) {
            return 0;
        }
        global $wpdb;
        $t = self::seeds_table();

        if ( self::has_provenance_columns() ) {
            $where  = 'source = %s';
            $params = [ $source ];
            if ( $batch_id !== '' ) {
                $where   .= ' AND import_batch_id = %s';
                $params[] = $batch_id;
            } else {
                $where .= " AND (import_batch_id IS NULL OR import_batch_id = '')";
            }
            if ( $source_label !== '' ) {
                $where   .= ' AND import_source_label = %s';
                $params[] = $source_label;
            }
            return (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$where}", ...$params )
            );
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE source = %s", $source )
        );
    }

    // =========================================================================
    // Expansion Candidates Explorer
    // =========================================================================

    /**
     * Count candidates with optional filters.
     *
     * @param array<string,string> $filters  Keys: search, source, status, batch_id
     */
    public static function count_candidates( array $filters = [] ): int {
        if ( ! self::candidates_table_exists() ) {
            return 0;
        }
        global $wpdb;
        $t = self::candidates_table();
        [ $where, $params ] = self::build_candidates_where( $filters );
        $sql = "SELECT COUNT(*) FROM {$t}" . ( $where ? " WHERE {$where}" : '' );
        return (int) ( $params
            ? $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) )
            : $wpdb->get_var( $sql )
        );
    }

    /**
     * Fetch a page of candidate rows.
     *
     * @param array<string,string> $filters
     * @param string               $orderby
     * @param string               $order
     * @param int                  $limit
     * @param int                  $offset
     * @return list<array<string,mixed>>
     */
    public static function get_candidates(
        array  $filters = [],
        string $orderby = 'created_at',
        string $order   = 'DESC',
        int    $limit   = 50,
        int    $offset  = 0
    ): array {
        if ( ! self::candidates_table_exists() ) {
            return [];
        }
        global $wpdb;
        $t = self::candidates_table();

        $orderby = self::validate_candidates_orderby( $orderby );
        $order   = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';
        $limit   = max( 1, min( 500, $limit ) );

        [ $where, $params ] = self::build_candidates_where( $filters );

        $sql = "SELECT * FROM {$t}"
            . ( $where ? " WHERE {$where}" : '' )
            . " ORDER BY {$orderby} {$order}"
            . $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );

        $rows = $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Fetch all candidates matching filters for CSV export (cap 50k).
     *
     * @param array<string,string> $filters
     * @return list<array<string,mixed>>
     */
    public static function get_candidates_for_export( array $filters = [] ): array {
        if ( ! self::candidates_table_exists() ) {
            return [];
        }
        global $wpdb;
        $t = self::candidates_table();
        [ $where, $params ] = self::build_candidates_where( $filters );
        $sql = "SELECT * FROM {$t}"
            . ( $where ? " WHERE {$where}" : '' )
            . ' ORDER BY created_at DESC'
            . $wpdb->prepare( ' LIMIT %d', 50000 );
        $rows = $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A )
            : $wpdb->get_results( $sql, ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    /** @return string[] */
    public static function distinct_candidate_sources(): array {
        if ( ! self::candidates_table_exists() ) {
            return [];
        }
        global $wpdb;
        $t    = self::candidates_table();
        $rows = $wpdb->get_col( "SELECT DISTINCT source FROM {$t} WHERE source IS NOT NULL AND source != '' ORDER BY source" );
        return is_array( $rows ) ? array_map( 'strval', $rows ) : [];
    }

    /** @return string[] */
    public static function distinct_candidate_batch_ids(): array {
        if ( ! self::candidates_table_exists() ) {
            return [];
        }
        global $wpdb;
        $t    = self::candidates_table();
        $rows = $wpdb->get_col( "SELECT DISTINCT batch_id FROM {$t} WHERE batch_id IS NOT NULL AND batch_id != '' ORDER BY batch_id DESC LIMIT 200" );
        return is_array( $rows ) ? array_map( 'strval', $rows ) : [];
    }

    // ─── Candidates WHERE builder ─────────────────────────────────────────────

    /**
     * @param  array<string,string>  $filters
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function build_candidates_where( array $filters ): array {
        $parts  = [];
        $params = [];

        $search = trim( (string) ( $filters['search'] ?? '' ) );
        if ( $search !== '' ) {
            $parts[]  = 'phrase LIKE %s';
            $params[] = '%' . $search . '%';
        }

        $source = sanitize_key( (string) ( $filters['source'] ?? '' ) );
        if ( $source !== '' ) {
            $parts[]  = 'source = %s';
            $params[] = $source;
        }

        $status = sanitize_key( (string) ( $filters['status'] ?? '' ) );
        $valid_statuses = [ 'pending', 'fast_track', 'approved', 'rejected', 'archived' ];
        if ( $status !== '' && in_array( $status, $valid_statuses, true ) ) {
            $parts[]  = 'status = %s';
            $params[] = $status;
        }

        $batch_id = sanitize_text_field( (string) ( $filters['batch_id'] ?? '' ) );
        if ( $batch_id !== '' ) {
            if ( $batch_id === '__none__' ) {
                $parts[] = "(batch_id IS NULL OR batch_id = '')";
            } else {
                $parts[]  = 'batch_id = %s';
                $params[] = $batch_id;
            }
        }

        return [ implode( ' AND ', $parts ), $params ];
    }

    private static function validate_candidates_orderby( string $col ): string {
        $allowed = [
            'id', 'phrase', 'source', 'generation_rule', 'entity_type',
            'entity_id', 'batch_id', 'status', 'quality_score',
            'duplicate_flag', 'intent_guess', 'created_at',
            'reviewed_at', 'reviewed_by', 'rejection_reason',
        ];
        return in_array( $col, $allowed, true ) ? $col : 'created_at';
    }

    // =========================================================================
    // CSV export helper
    // =========================================================================

    /**
     * Stream an array of rows as a CSV download directly to output.
     * Exits after sending. Safe to call from a wp-admin action handler.
     *
     * @param list<array<string,mixed>> $rows
     * @param string                    $filename  Sanitised filename (no path).
     */
    public static function stream_csv_download( array $rows, string $filename ): void {
        if ( empty( $rows ) ) {
            wp_die( esc_html__( 'No rows to export.', 'tmwseo' ) );
        }

        $filename = sanitize_file_name( $filename );
        if ( ! str_ends_with( $filename, '.csv' ) ) {
            $filename .= '.csv';
        }

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $fh = fopen( 'php://output', 'w' );
        if ( $fh === false ) {
            wp_die( esc_html__( 'Could not open output stream.', 'tmwseo' ) );
        }

        // Header row.
        fputcsv( $fh, array_keys( reset( $rows ) ) );

        foreach ( $rows as $row ) {
            fputcsv( $fh, array_values( $row ) );
        }

        fclose( $fh );
        exit;
    }

    // =========================================================================
    // Utility
    // =========================================================================

    public static function get_csv_dir(): string {
        $csv_dir = function_exists( 'tmw_get_csv_directory' )
            ? tmw_get_csv_directory()
            : trailingslashit( WP_CONTENT_DIR ) . 'uploads/tmw-seo-imports';
        return trailingslashit( $csv_dir );
    }

    /**
     * Fetch a single trusted seed row by ID.
     *
     * @return array<string,mixed>|null  Full row, or null if not found.
     */
    public static function get_seed_by_id( int $id ): ?array {
        if ( ! self::seeds_table_exists() || $id <= 0 ) {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . self::seeds_table() . ' WHERE id = %d LIMIT 1', $id ),
            ARRAY_A
        );
        return is_array( $row ) ? $row : null;
    }

    /**
     * Fetch multiple trusted seed rows by an explicit ID list (for bulk export).
     * IDs are validated; order matches the input array.
     *
     * @param  int[]  $ids
     * @return list<array<string,mixed>>
     */
    public static function get_seeds_by_ids( array $ids ): array {
        $ids = array_values( array_filter( array_map( 'intval', $ids ), fn( $id ) => $id > 0 ) );
        if ( empty( $ids ) || ! self::seeds_table_exists() ) {
            return [];
        }
        global $wpdb;
        $t          = self::seeds_table();
        $place_hold = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows       = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$t} WHERE id IN ({$place_hold})", ...$ids ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : [];
    }

}
