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
            [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%f', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
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

        $table = self::table();
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
            ARRAY_A
        );

        if ( empty( $row ) ) {
            return false;
        }

        if ( $row['status'] === self::STATUS_APPROVED ) {
            return true; // already approved
        }

        // Mark approved
        $wpdb->update(
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

        // Promote to working keyword pipeline
        self::promote_to_working_keywords( $row );

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
     * keyword pipeline). Uses INSERT IGNORE so re-approval is idempotent.
     *
     * @param array<string,mixed> $row Candidate row.
     */
    private static function promote_to_working_keywords( array $row ): void {
        global $wpdb;

        $phrase    = (string) ( $row['phrase'] ?? '' );
        $source    = (string) ( $row['source'] ?? 'preview' );
        $entity_type = (string) ( $row['entity_type'] ?? 'system' );
        $entity_id = (int) ( $row['entity_id'] ?? 0 );

        if ( $phrase === '' ) {
            return;
        }

        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';

        // Check the table exists before trying to write
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cand_table ) ) !== $cand_table ) {
            Logs::warn( 'keywords', '[TMW-PREVIEW] tmw_keyword_candidates table missing — skipping promotion', [
                'phrase' => $phrase,
            ] );
            return;
        }

        $existing = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$cand_table} WHERE keyword = %s LIMIT 1", $phrase )
        );

        if ( $existing ) {
            return; // already in working keywords
        }

        $canonical = KeywordValidator::normalize( $phrase );
        $intent    = KeywordValidator::infer_intent( $phrase );

        $wpdb->insert(
            $cand_table,
            [
                'keyword'        => $phrase,
                'canonical'      => $canonical,
                'source'         => sanitize_key( $source ),
                'source_ref'     => 'expansion_candidate:' . (int) ( $row['id'] ?? 0 ),
                'entity_type'    => sanitize_key( $entity_type ),
                'entity_id'      => $entity_id,
                'status'         => 'pending',
                'intent'         => $intent,
                'volume'         => 0,
                'difficulty'     => null,
                'opportunity'    => null,
                'discovered_at'  => current_time( 'mysql' ),
                'enriched_at'    => null,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%f', '%f', '%s', '%s' ]
        );
    }
}
