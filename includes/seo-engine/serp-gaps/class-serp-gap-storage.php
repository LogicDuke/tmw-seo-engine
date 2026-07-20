<?php
/**
 * SerpGapStorage — database layer for the SERP Keyword Gaps module.
 *
 * Owns the table name, CREATE SQL, and all CRUD operations.
 * Contains no scoring logic, no admin rendering, no menu registration.
 *
 * @package TMWSEO\Engine\SerpGaps
 * @since   4.6.3
 */
namespace TMWSEO\Engine\SerpGaps;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SerpGapStorage {

    // ── Table / schema ────────────────────────────────────────────────────────

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_serp_gaps';
    }

    /**
     * Returns the CREATE TABLE SQL consumed by dbDelta().
     * Also used by the migration class.
     */
    public static function create_sql( string $charset_collate ): string {
        $t = self::table();
        return "CREATE TABLE {$t} (
            id                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword             VARCHAR(255)        NOT NULL,
            serp_gap_score      DECIMAL(6,2)        NOT NULL DEFAULT 0,
            gap_types           VARCHAR(255)        NOT NULL DEFAULT '',
            exact_match_score   TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            modifier_score      TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            intent_score        TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            specificity_score   TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            weak_serp_score     TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            exact_match_count   TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            modifier_misses     VARCHAR(512)        NOT NULL DEFAULT '',
            intent_mismatch_flag TINYINT(1)         NOT NULL DEFAULT 0,
            specificity_gap_flag TINYINT(1)         NOT NULL DEFAULT 0,
            reason              TEXT                         NULL,
            suggested_page_type VARCHAR(100)        NOT NULL DEFAULT '',
            suggested_title_angle VARCHAR(255)      NOT NULL DEFAULT '',
            suggested_h1_angle  VARCHAR(255)        NOT NULL DEFAULT '',
            source              VARCHAR(50)         NOT NULL DEFAULT 'manual',
            status              VARCHAR(30)         NOT NULL DEFAULT 'new',
            search_volume       INT(11)                      NULL,
            difficulty          DECIMAL(6,2)                 NULL,
            opportunity_score   DECIMAL(8,2)                 NULL,
            serp_items_json     LONGTEXT                     NULL,
            last_scanned_at     DATETIME                     NULL,
            created_at          DATETIME            NOT NULL,
            updated_at          DATETIME            NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY keyword_unique (keyword),
            KEY score_status (serp_gap_score, status),
            KEY source_status (source, status),
            KEY gap_types_idx (gap_types(50))
        ) {$charset_collate};";
    }

    public static function maybe_create_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( self::create_sql( $wpdb->get_charset_collate() ) );
    }

    // ── Allowed enum values ───────────────────────────────────────────────────

    /** @return string[] */
    public static function allowed_statuses(): array {
        return [ 'new', 'reviewing', 'not_a_gap', 'brief_created', 'attached', 'opportunity' ];
    }

    /** @return string[] */
    public static function allowed_gap_types(): array {
        return [
            'exact_phrase_gap',
            'modifier_gap',
            'intent_gap',
            'specificity_gap',
            'weak_serp_gap',
            'mixed_intent_gap',
        ];
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    /**
     * Upsert a gap record. Returns the row id (insert or existing).
     *
     * @param array<string,mixed> $data
     */
    public static function upsert( array $data ): int {
        global $wpdb;

        $keyword = mb_strtolower( trim( (string) ( $data['keyword'] ?? '' ) ), 'UTF-8' );
        if ( $keyword === '' ) {
            return 0;
        }

        $now = current_time( 'mysql' );

        $row = [
            'keyword'              => $keyword,
            'serp_gap_score'       => round( (float) ( $data['serp_gap_score'] ?? 0 ), 2 ),
            'gap_types'            => substr( implode( ',', (array) ( $data['gap_types'] ?? [] ) ), 0, 255 ),
            'exact_match_score'    => min( 30, max( 0, (int) ( $data['exact_match_score'] ?? 0 ) ) ),
            'modifier_score'       => min( 25, max( 0, (int) ( $data['modifier_score'] ?? 0 ) ) ),
            'intent_score'         => min( 20, max( 0, (int) ( $data['intent_score'] ?? 0 ) ) ),
            'specificity_score'    => min( 15, max( 0, (int) ( $data['specificity_score'] ?? 0 ) ) ),
            'weak_serp_score'      => min( 10, max( 0, (int) ( $data['weak_serp_score'] ?? 0 ) ) ),
            'exact_match_count'    => max( 0, (int) ( $data['exact_match_count'] ?? 0 ) ),
            'modifier_misses'      => substr( (string) ( $data['modifier_misses'] ?? '' ), 0, 512 ),
            'intent_mismatch_flag' => (int) (bool) ( $data['intent_mismatch_flag'] ?? false ),
            'specificity_gap_flag' => (int) (bool) ( $data['specificity_gap_flag'] ?? false ),
            'reason'               => sanitize_textarea_field( (string) ( $data['reason'] ?? '' ) ),
            'suggested_page_type'  => sanitize_text_field( substr( (string) ( $data['suggested_page_type'] ?? '' ), 0, 100 ) ),
            'suggested_title_angle' => sanitize_text_field( substr( (string) ( $data['suggested_title_angle'] ?? '' ), 0, 255 ) ),
            'suggested_h1_angle'   => sanitize_text_field( substr( (string) ( $data['suggested_h1_angle'] ?? '' ), 0, 255 ) ),
            'source'               => sanitize_key( (string) ( $data['source'] ?? 'manual' ) ),
            'status'               => 'new',
            'search_volume'        => isset( $data['search_volume'] ) ? (int) $data['search_volume'] : null,
            'difficulty'           => isset( $data['difficulty'] ) ? round( (float) $data['difficulty'], 2 ) : null,
            'opportunity_score'    => isset( $data['opportunity_score'] ) ? round( (float) $data['opportunity_score'], 2 ) : null,
            'serp_items_json'      => isset( $data['serp_items'] ) ? wp_json_encode( $data['serp_items'] ) : null,
            'last_scanned_at'      => $now,
            'updated_at'           => $now,
        ];

        $table = self::table();
        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE keyword = %s LIMIT 1", $keyword )
        );

        if ( $existing_id > 0 ) {
            // Preserve existing human status — only reset to 'new' if still 'new'
            $existing_status = (string) $wpdb->get_var(
                $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d LIMIT 1", $existing_id )
            );
            if ( in_array( $existing_status, [ 'reviewing', 'brief_created', 'attached', 'opportunity' ], true ) ) {
                unset( $row['status'] ); // keep the human-set status
            }
            unset( $row['created_at'] ); // don't overwrite original creation date

            $formats = array_fill( 0, count( $row ), '%s' );
            $wpdb->update( $table, $row, [ 'id' => $existing_id ], $formats, [ '%d' ] );
            return $existing_id;
        }

        $row['created_at'] = $now;
        $formats = array_fill( 0, count( $row ), '%s' );
        $wpdb->insert( $table, $row, $formats );
        return (int) $wpdb->insert_id;
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function list_page( array $filters = [], int $limit = 50, int $offset = 0 ): array {
        global $wpdb;

        $limit  = max( 1, min( 500, $limit ) );
        $offset = max( 0, $offset );
        $table  = self::table();

        [ $where, $args ] = self::build_where( $filters );

        $sql = "SELECT * FROM {$table} {$where} ORDER BY serp_gap_score DESC, last_scanned_at DESC LIMIT %d OFFSET %d";
        $args[] = $limit;
        $args[] = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
    }

    /** @param array<string,mixed> $filters */
    public static function count_all( array $filters = [] ): int {
        global $wpdb;

        $table = self::table();
        [ $where, $args ] = self::build_where( $filters );

        $sql = "SELECT COUNT(*) FROM {$table} {$where}";

        if ( ! empty( $args ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var( $sql );
    }

    /** @return array<string,mixed>|null */
    public static function find_by_id( int $id ): ?array {
        global $wpdb;
        $table = self::table();
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    // ── Mutations ────────────────────────────────────────────────────────────

    public static function update_status( int $id, string $status ): bool {
        if ( ! in_array( $status, self::allowed_statuses(), true ) ) {
            return false;
        }

        global $wpdb;
        return (bool) $wpdb->update(
            self::table(),
            [ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed> $filters
     * @return array{string, array<int,mixed>}  [ $where_clause, $args ]
     */
    private static function build_where( array $filters ): array {
        $clauses = [];
        $args    = [];

        if ( ! empty( $filters['status'] ) ) {
            $clauses[] = 'status = %s';
            $args[]    = sanitize_key( (string) $filters['status'] );
        }

        if ( ! empty( $filters['gap_type'] ) ) {
            $clauses[] = 'FIND_IN_SET(%s, gap_types)';
            $args[]    = sanitize_key( (string) $filters['gap_type'] );
        }

        if ( ! empty( $filters['source'] ) ) {
            $clauses[] = 'source = %s';
            $args[]    = sanitize_key( (string) $filters['source'] );
        }

        if ( isset( $filters['min_score'] ) ) {
            $clauses[] = 'serp_gap_score >= %f';
            $args[]    = (float) $filters['min_score'];
        }

        if ( isset( $filters['max_score'] ) ) {
            $clauses[] = 'serp_gap_score <= %f';
            $args[]    = (float) $filters['max_score'];
        }

        if ( ! empty( $filters['model_only'] ) ) {
            // model-related = source contains 'model' or keyword matches model patterns
            $clauses[] = "(source LIKE %s OR keyword REGEXP %s)";
            $args[]    = '%model%';
            $args[]    = '(live|cam|private|bio|instagram|onlyfans)$';
        }

        if ( ! empty( $filters['unassigned'] ) ) {
            $clauses[] = "status = 'new'";
        }

        $where = ! empty( $clauses ) ? 'WHERE ' . implode( ' AND ', $clauses ) : '';

        return [ $where, $args ];
    }
}
