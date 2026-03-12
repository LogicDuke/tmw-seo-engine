<?php
/**
 * TMW SEO Engine — Expansion Candidate Repository
 *
 * All generated, discovered, or synthesised phrases land here first.
 * Nothing writes to tmwseo_seeds unless it goes through explicit human approval.
 *
 * Status lifecycle:
 *   pending     → newly generated, awaiting review
 *   fast_track  → real-signal sources (GSC) that need only a sanity check
 *   approved    → operator approved; phrase promoted to working keyword layer
 *   rejected    → operator rejected; phrase excluded
 *   archived    → soft-removed without explicit rejection
 *
 * @package TMWSEO\Engine\Keywords
 * @since   4.3.0
 */

namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExpansionCandidateRepository {

    private const LAST_PROMOTION_DIAG_OPTION = 'tmwseo_last_promotion_diag';

    // -------------------------------------------------------------------------
    // Status constants
    // -------------------------------------------------------------------------

    public const STATUS_PENDING    = 'pending';
    public const STATUS_FAST_TRACK = 'fast_track';
    public const STATUS_APPROVED   = 'approved';
    public const STATUS_REJECTED   = 'rejected';
    public const STATUS_ARCHIVED   = 'archived';

    // Sources that are considered real-signal (lighter review burden)
    private const FAST_TRACK_SOURCES = [ 'gsc' ];

    // -------------------------------------------------------------------------
    // Table helper
    // -------------------------------------------------------------------------

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_seed_expansion_candidates';
    }

    /**
     * @return string[]
     */
    private static function keyword_candidate_columns(): array {
        static $columns = null;

        if ( is_array( $columns ) ) {
            return $columns;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $rows  = $wpdb->get_results( 'SHOW COLUMNS FROM ' . $table, ARRAY_A );

        $columns = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $field = (string) ( $row['Field'] ?? '' );
                if ( $field !== '' ) {
                    $columns[ $field ] = $field;
                }
            }
        }

        return array_values( $columns );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private static function infer_insert_formats( array $payload ): array {
        $formats = [];

        foreach ( $payload as $value ) {
            if ( is_int( $value ) ) {
                $formats[] = '%d';
            } elseif ( is_float( $value ) ) {
                $formats[] = '%f';
            } else {
                $formats[] = '%s';
            }
        }

        return $formats;
    }

    // -------------------------------------------------------------------------
    // Batch ID generation
    // -------------------------------------------------------------------------

    /**
     * Generate a unique batch identifier for a generator run.
     * Format: {source}_{yyyymmddHis}_{random4hex}
     */
    public static function make_batch_id( string $source ): string {
        $ts  = gmdate( 'YmdHis' );
        $rnd = substr( md5( uniqid( '', true ) ), 0, 6 );
        return sanitize_key( $source ) . '_' . $ts . '_' . $rnd;
    }

    // -------------------------------------------------------------------------
    // Single insert
    // -------------------------------------------------------------------------

    /**
     * Insert one candidate phrase.
     *
     * @param string $phrase          The generated phrase (will be normalised).
     * @param string $source          Source identifier, e.g. 'tag', 'model_auto'.
     * @param string $generation_rule Human-readable rule, e.g. 'tag_x_modifier'.
     * @param string $entity_type     WP entity type ('post_tag', 'model', 'system', …).
     * @param int    $entity_id       WP entity ID (0 = system-level).
     * @param string $batch_id        Batch identifier from make_batch_id().
     * @param array  $provenance_meta Optional associative array; serialised to JSON.
     * @return bool True on insert, false if phrase already in table or empty.
     */
    public static function insert_candidate(
        string $phrase,
        string $source,
        string $generation_rule,
        string $entity_type = 'system',
        int    $entity_id   = 0,
        string $batch_id    = '',
        array  $provenance_meta = []
    ): bool {
        global $wpdb;

        $normalised = SeedRegistry::normalize_seed( $phrase );
        if ( $normalised === '' ) {
            return false;
        }

        $hash  = md5( $normalised );
        $table = self::table();

        // De-duplicate within preview table
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE hash = %s LIMIT 1", $hash )
        );
        if ( $exists > 0 ) {
            return false;
        }

        // Also skip phrases that are already trusted roots
        if ( SeedRegistry::seed_exists( $normalised ) ) {
            return false;
        }

        $status = in_array( $source, self::FAST_TRACK_SOURCES, true )
            ? self::STATUS_FAST_TRACK
            : self::STATUS_PENDING;

        $inserted = $wpdb->insert(
            $table,
            [
                'phrase'          => $normalised,
                'source'          => sanitize_key( $source ),
                'generation_rule' => sanitize_text_field( $generation_rule ),
                'entity_type'     => sanitize_key( $entity_type ),
                'entity_id'       => max( 0, $entity_id ),
                'batch_id'        => sanitize_key( $batch_id ),
                'status'          => $status,
                'quality_score'   => null,
                'duplicate_flag'  => 0,
                'intent_guess'    => null,
                'provenance_meta' => ! empty( $provenance_meta )
                    ? wp_json_encode( $provenance_meta )
                    : null,
                'created_at'      => current_time( 'mysql' ),
                'reviewed_at'     => null,
                'reviewed_by'     => null,
                'rejection_reason' => null,
                'hash'            => $hash,
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%f', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return $inserted !== false;
    }

    // -------------------------------------------------------------------------
    // Batch insert
    // -------------------------------------------------------------------------

    /**
     * Insert multiple candidate phrases under one batch ID.
     *
     * @param string[] $phrases
     * @param string   $source
     * @param string   $generation_rule
     * @param string   $entity_type
     * @param int      $entity_id
     * @param array    $provenance_meta
     * @return array{ batch_id: string, inserted: int, skipped: int }
     */
    public static function insert_batch(
        array  $phrases,
        string $source,
        string $generation_rule,
        string $entity_type = 'system',
        int    $entity_id   = 0,
        array  $provenance_meta = []
    ): array {
        $batch_id = self::make_batch_id( $source );
        $inserted = 0;
        $skipped  = 0;

        foreach ( $phrases as $phrase ) {
            $ok = self::insert_candidate(
                (string) $phrase,
                $source,
                $generation_rule,
                $entity_type,
                $entity_id,
                $batch_id,
                $provenance_meta
            );
            if ( $ok ) {
                $inserted++;
            } else {
                $skipped++;
            }
        }

        Logs::info( 'keywords', '[TMW-PREVIEW] Candidate batch inserted', [
            'batch_id'        => $batch_id,
            'source'          => $source,
            'generation_rule' => $generation_rule,
            'inserted'        => $inserted,
            'skipped'         => $skipped,
        ] );

        return [
            'batch_id' => $batch_id,
            'inserted' => $inserted,
            'skipped'  => $skipped,
        ];
    }

    // -------------------------------------------------------------------------
    // Review actions
    // -------------------------------------------------------------------------

    /**
     * Approve a candidate and promote it into the working keyword pipeline.
     *
     * The phrase is inserted into tmw_keyword_candidates (the existing working
     * keyword layer) so it flows into DataForSEO scoring, clustering, and
     * opportunity generation without polluting tmwseo_seeds.
     *
     * @param int    $id          Candidate row ID.
     * @param int    $reviewed_by WP user ID performing the approval.
     * @return bool
     */
    public static function approve_candidate( int $id, int $reviewed_by = 0 ): bool {
        global $wpdb;

        $diag = [
            'TMW-PREVIEW-DIAG timestamp'                   => current_time( 'mysql' ),
            'TMW-PREVIEW-DIAG candidate_id'                => $id,
            'TMW-PREVIEW-DIAG phrase'                      => '',
            'TMW-PREVIEW-DIAG source'                      => '',
            'TMW-PREVIEW-DIAG status_transition_result'    => null,
            'TMW-PREVIEW-DIAG promote_invoked'             => false,
            'TMW-PREVIEW-DIAG table_found'                 => null,
            'TMW-PREVIEW-DIAG keyword_already_exists'      => null,
            'TMW-PREVIEW-DIAG insert_payload_keys'         => [],
            'TMW-PREVIEW-DIAG insert_result'               => null,
            'TMW-PREVIEW-DIAG insert_last_error'           => '',
        ];

        error_log( sprintf( '[TMW-PREVIEW-DIAG] approve_candidate start id=%d reviewed_by=%d', $id, $reviewed_by ) );

        $table = self::table();
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
            ARRAY_A
        );

        if ( empty( $row ) ) {
            error_log( sprintf( '[TMW-PREVIEW-DIAG] approve_candidate missing_row id=%d', $id ) );
            $diag['TMW-PREVIEW-DIAG message'] = 'approve_candidate missing_row';
            self::save_last_promotion_diag( $diag );
            return false;
        }

        $diag['TMW-PREVIEW-DIAG phrase'] = (string) ( $row['phrase'] ?? '' );
        $diag['TMW-PREVIEW-DIAG source'] = (string) ( $row['source'] ?? '' );

        error_log( sprintf(
            '[TMW-PREVIEW-DIAG] approve_candidate row_loaded id=%d phrase="%s" status=%s',
            $id,
            (string) ( $row['phrase'] ?? '' ),
            (string) ( $row['status'] ?? '' )
        ) );

        if ( $row['status'] === self::STATUS_APPROVED ) {
            error_log( sprintf( '[TMW-PREVIEW-DIAG] approve_candidate already_approved id=%d', $id ) );
            $diag['TMW-PREVIEW-DIAG message'] = 'approve_candidate already_approved';
            self::save_last_promotion_diag( $diag );
            return true; // already approved
        }

        // Mark approved before promotion so the review queue updates immediately.
        $updated = $wpdb->update(
            $table,
            [
                'status'      => self::STATUS_APPROVED,
                'reviewed_at' => current_time( 'mysql' ),
                'reviewed_by' => $reviewed_by,
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%d' ],
            [ '%d' ]
        );

        error_log( sprintf(
            '[TMW-PREVIEW-DIAG] approve_candidate status_transition id=%d from=%s to=%s update_result=%s',
            $id,
            (string) ( $row['status'] ?? '' ),
            self::STATUS_APPROVED,
            var_export( $updated, true )
        ) );
        $diag['TMW-PREVIEW-DIAG status_transition_result'] = var_export( $updated, true );

        if ( $updated === false ) {
            $diag['TMW-PREVIEW-DIAG message'] = 'approve_candidate status_transition_failed';
            self::save_last_promotion_diag( $diag );
            return false;
        }

        // Promote to working keyword pipeline
        error_log( sprintf( '[TMW-PREVIEW-DIAG] approve_candidate invoking_promotion id=%d', $id ) );
        $diag['TMW-PREVIEW-DIAG promote_invoked'] = true;
        $promoted = self::promote_to_working_keywords( $row, $diag );

        if ( ! $promoted ) {
            $wpdb->update(
                $table,
                [
                    'status'      => self::STATUS_PENDING,
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                ],
                [ 'id' => $id ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
            $diag['TMW-PREVIEW-DIAG message'] = (string) ( $diag['TMW-PREVIEW-DIAG message'] ?? 'promotion_failed_reverted' );
            self::save_last_promotion_diag( $diag );
            return false;
        }

        self::save_last_promotion_diag( $diag );

        Logs::info( 'keywords', '[TMW-PREVIEW] Candidate approved', [
            'id'          => $id,
            'phrase'      => $row['phrase'],
            'source'      => $row['source'],
            'reviewed_by' => $reviewed_by,
        ] );

        return true;
    }

    /**
     * Reject a candidate phrase.
     *
     * @param int    $id
     * @param string $reason
     * @param int    $reviewed_by
     */
    public static function reject_candidate( int $id, string $reason = '', int $reviewed_by = 0 ): bool {
        global $wpdb;

        $table   = self::table();
        $updated = $wpdb->update(
            $table,
            [
                'status'           => self::STATUS_REJECTED,
                'rejection_reason' => sanitize_text_field( $reason ),
                'reviewed_at'      => current_time( 'mysql' ),
                'reviewed_by'      => $reviewed_by,
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s', '%d' ],
            [ '%d' ]
        );

        return $updated !== false;
    }

    /**
     * Archive a candidate (soft-remove without explicit rejection).
     */
    public static function archive_candidate( int $id, int $reviewed_by = 0 ): bool {
        global $wpdb;

        $updated = $wpdb->update(
            self::table(),
            [
                'status'      => self::STATUS_ARCHIVED,
                'reviewed_at' => current_time( 'mysql' ),
                'reviewed_by' => $reviewed_by,
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%d' ],
            [ '%d' ]
        );

        return $updated !== false;
    }

    // -------------------------------------------------------------------------
    // Bulk review
    // -------------------------------------------------------------------------

    /**
     * Approve all candidates in a batch.
     *
     * @param string $batch_id
     * @param int    $reviewed_by
     * @return int Number approved.
     */
    public static function approve_batch( string $batch_id, int $reviewed_by = 0 ): int {
        global $wpdb;

        $table = self::table();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE batch_id = %s AND status IN ('pending','fast_track') LIMIT 500",
                $batch_id
            ),
            ARRAY_A
        );

        $count = 0;
        foreach ( $rows as $row ) {
            if ( self::approve_candidate( (int) $row['id'], $reviewed_by ) ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Roll back an entire batch: deletes all candidates regardless of status.
     * Approved candidates that were already promoted to working keywords are
     * NOT automatically removed (that is a separate operator action).
     *
     * @param string $batch_id
     * @return int Rows deleted.
     */
    public static function rollback_batch( string $batch_id ): int {
        global $wpdb;

        $deleted = (int) $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . self::table() . ' WHERE batch_id = %s',
                $batch_id
            )
        );

        Logs::info( 'keywords', '[TMW-PREVIEW] Batch rolled back', [
            'batch_id' => $batch_id,
            'deleted'  => $deleted,
        ] );

        return $deleted;
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    /**
     * Get pending candidates for the review queue.
     *
     * @param int    $limit
     * @param int    $offset
     * @param string $status Filter by status; empty = all pending + fast_track.
     * @return array<int,array<string,mixed>>
     */
    public static function get_pending( int $limit = 50, int $offset = 0, string $status = '' ): array {
        global $wpdb;

        $table = self::table();

        if ( $status !== '' ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
                    $status, $limit, $offset
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE status IN ('pending','fast_track') ORDER BY created_at DESC LIMIT %d OFFSET %d",
                    $limit, $offset
                ),
                ARRAY_A
            );
        }

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Count rows by status.
     *
     * @return array<string,int>
     */
    public static function count_by_status(): array {
        global $wpdb;

        $table = self::table();
        $rows  = $wpdb->get_results(
            "SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status",
            ARRAY_A
        );

        $counts = [
            self::STATUS_PENDING    => 0,
            self::STATUS_FAST_TRACK => 0,
            self::STATUS_APPROVED   => 0,
            self::STATUS_REJECTED   => 0,
            self::STATUS_ARCHIVED   => 0,
        ];

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $s = (string) ( $row['status'] ?? '' );
                if ( isset( $counts[ $s ] ) ) {
                    $counts[ $s ] = (int) $row['cnt'];
                }
            }
        }

        return $counts;
    }

    /**
     * Get all rows belonging to a batch.
     *
     * @param string $batch_id
     * @return array<int,array<string,mixed>>
     */
    public static function get_by_batch( string $batch_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE batch_id = %s ORDER BY id ASC LIMIT 1000',
                $batch_id
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Get distinct recent batch IDs with summary info.
     *
     * @param int $limit
     * @return array<int,array<string,mixed>>
     */
    public static function get_recent_batches( int $limit = 30 ): array {
        global $wpdb;

        $table = self::table();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    batch_id,
                    source,
                    generation_rule,
                    MIN(created_at)  AS started_at,
                    COUNT(*)         AS total,
                    SUM(status = 'pending')    AS pending,
                    SUM(status = 'fast_track') AS fast_track,
                    SUM(status = 'approved')   AS approved,
                    SUM(status = 'rejected')   AS rejected,
                    SUM(status = 'archived')   AS archived
                FROM {$table}
                WHERE batch_id != ''
                GROUP BY batch_id, source, generation_rule
                ORDER BY MIN(created_at) DESC
                LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : [];
    }

    // -------------------------------------------------------------------------
    // Internal: promote to working keyword layer
    // -------------------------------------------------------------------------

    /**
     * Insert the approved phrase into tmw_keyword_candidates (existing working
     * keyword pipeline). Returns true only when the working-keyword row exists.
     *
     * @param array<string,mixed> $row Candidate row.
     */
    private static function promote_to_working_keywords( array $row, array &$diag ): bool {
        global $wpdb;

        $phrase      = (string) ( $row['phrase'] ?? '' );
        $source      = (string) ( $row['source'] ?? 'preview' );
        $entity_type = (string) ( $row['entity_type'] ?? 'system' );
        $entity_id   = (int) ( $row['entity_id'] ?? 0 );

        error_log( sprintf(
            '[TMW-PREVIEW-DIAG] promote_to_working_keywords start candidate_id=%d phrase="%s" source=%s',
            (int) ( $row['id'] ?? 0 ),
            $phrase,
            $source
        ) );

        if ( $phrase === '' ) {
            error_log( '[TMW-PREVIEW-DIAG] promote_to_working_keywords empty_phrase_skip' );
            $diag['TMW-PREVIEW-DIAG message'] = 'promote_to_working_keywords empty_phrase_skip';
            return false;
        }

        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cand_table ) ) !== $cand_table ) {
            error_log( sprintf( '[TMW-PREVIEW-DIAG] promote_to_working_keywords missing_table table=%s', $cand_table ) );
            $diag['TMW-PREVIEW-DIAG table_found'] = false;
            $diag['TMW-PREVIEW-DIAG message']     = 'promote_to_working_keywords missing_table';
            Logs::warn( 'keywords', '[TMW-PREVIEW] tmw_keyword_candidates table missing — skipping promotion', [
                'phrase' => $phrase,
            ] );
            return false;
        }

        $diag['TMW-PREVIEW-DIAG table_found'] = true;

        $columns      = self::keyword_candidate_columns();
        $column_index = array_fill_keys( $columns, true );
        if ( empty( $column_index ) ) {
            $diag['TMW-PREVIEW-DIAG message'] = 'promote_to_working_keywords missing_columns';
            return false;
        }

        $canonical = KeywordValidator::normalize( $phrase );
        $intent    = KeywordValidator::infer_intent( $phrase );

        $existing = null;
        if ( isset( $column_index['canonical'] ) ) {
            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$cand_table} WHERE keyword = %s OR canonical = %s LIMIT 1",
                    $phrase,
                    $canonical
                )
            );
        } else {
            $existing = $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$cand_table} WHERE keyword = %s LIMIT 1", $phrase )
            );
        }

        if ( $existing ) {
            error_log( sprintf( '[TMW-PREVIEW-DIAG] promote_to_working_keywords already_exists keyword="%s" existing_id=%s', $phrase, (string) $existing ) );
            $diag['TMW-PREVIEW-DIAG keyword_already_exists'] = true;
            $diag['TMW-PREVIEW-DIAG message']                = 'promote_to_working_keywords already_exists';
            return true;
        }

        $diag['TMW-PREVIEW-DIAG keyword_already_exists'] = false;

        $preferred_status = isset( $column_index['status'] ) ? 'new' : null;
        $candidate_values = [
            'keyword'         => $phrase,
            'canonical'       => $canonical,
            'status'          => $preferred_status,
            'intent'          => $intent,
            'intent_type'     => 'generic',
            'entity_type'     => sanitize_key( $entity_type ),
            'entity_id'       => $entity_id,
            'volume'          => 0,
            'cpc'             => 0.0,
            'difficulty'      => null,
            'opportunity'     => null,
            'sources'         => wp_json_encode( [ sanitize_key( $source ) ] ),
            'notes'           => 'Promoted from expansion candidate #' . (int) ( $row['id'] ?? 0 ),
            'needs_recluster' => 1,
            'needs_rescore'   => 1,
            'updated_at'      => current_time( 'mysql' ),
        ];

        $insert_payload = [];
        foreach ( $candidate_values as $column => $value ) {
            if ( ! isset( $column_index[ $column ] ) || $value === null ) {
                continue;
            }
            $insert_payload[ $column ] = $value;
        }

        $diag['TMW-PREVIEW-DIAG insert_payload_keys'] = array_keys( $insert_payload );
        error_log( '[TMW-PREVIEW-DIAG] promote_to_working_keywords insert_payload_keys=' . implode( ',', array_keys( $insert_payload ) ) );

        if ( empty( $insert_payload['keyword'] ?? '' ) ) {
            $diag['TMW-PREVIEW-DIAG message'] = 'promote_to_working_keywords empty_insert_payload';
            return false;
        }

        $inserted = $wpdb->insert(
            $cand_table,
            $insert_payload,
            self::infer_insert_formats( $insert_payload )
        );

        error_log( sprintf( '[TMW-PREVIEW-DIAG] promote_to_working_keywords insert_result=%s', var_export( $inserted, true ) ) );
        $diag['TMW-PREVIEW-DIAG insert_result'] = var_export( $inserted, true );

        if ( $inserted === false ) {
            $diag['TMW-PREVIEW-DIAG insert_last_error'] = (string) $wpdb->last_error;
            $diag['TMW-PREVIEW-DIAG message']           = 'promote_to_working_keywords insert_failed';
            error_log( '[TMW-PREVIEW-DIAG] promote_to_working_keywords insert_last_error=' . (string) $wpdb->last_error );
            return false;
        }

        $verified = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(1) FROM {$cand_table} WHERE keyword = %s", $phrase )
        );
        if ( $verified < 1 ) {
            $diag['TMW-PREVIEW-DIAG message'] = 'promote_to_working_keywords verification_failed';
            return false;
        }

        $diag['TMW-PREVIEW-DIAG message'] = 'promote_to_working_keywords insert_success';
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_last_promotion_diag(): array {
        $diag = get_option( self::LAST_PROMOTION_DIAG_OPTION, [] );
        return is_array( $diag ) ? $diag : [];
    }

    public static function clear_last_promotion_diag(): void {
        delete_option( self::LAST_PROMOTION_DIAG_OPTION );
    }

    /**
     * @param array<string,mixed> $diag
     */
    private static function save_last_promotion_diag( array $diag ): void {
        update_option( self::LAST_PROMOTION_DIAG_OPTION, $diag, false );
    }

}
