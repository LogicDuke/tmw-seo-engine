<?php
/**
 * TMW SEO Engine — Keyword Assignment Review Repository (PR-E).
 *
 * Persistent review state for the kwmig-v1 assignment migration: one row per
 * deterministic planned-action identity, plus an append-only audit trail for
 * every review mutation. This layer owns identity, snapshot hashing, the
 * review/execution state machines, generic filtered listing, and audit
 * writes; the sync and execution services own analyzer I/O.
 *
 * PR-E SCOPE: migration review infrastructure only. Nothing in production
 * (approval UI, generation, Rank Math, publishing, indexing, candidate
 * status, ownership reads) calls this layer; only the explicit
 * `wp tmwseo keyword-assignment-review` CLI workflow does.
 *
 * STATE MACHINES (fail closed on anything not listed):
 *
 * review_state:
 *   pending  -> approved | rejected | deferred
 *   approved -> rejected | deferred | pending (reset-to-pending only)
 *   rejected -> pending (reset-to-pending only)
 *   deferred -> approved | rejected | pending (reset-to-pending only)
 *   No review-state transition is ever allowed once execution_state is
 *   'executed' (review history of executed rows is immutable), and no
 *   transition ever happens implicitly — every change is an explicit,
 *   audited operator action. Rejected/deferred rows are never auto-converted
 *   back to pending by sync.
 *
 * execution_state:
 *   not_executed -> executed | skipped | failed | stale
 *   failed       -> executed | failed | stale   (restartable)
 *   skipped      -> executed | skipped | stale  (re-runnable once unblocked)
 *   stale        -> not_executed                (only when a sync proves the
 *                                               fresh planned action again
 *                                               matches the reviewed snapshot,
 *                                               or via reset-to-pending resync)
 *   executed     -> terminal
 *
 * Log tag: [TMW-KW-ASSIGN-REVIEW]
 *
 * @package TMWSEO\Engine\Keywords
 * @since   5.9.24
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class KeywordAssignmentReviewRepository {

    public const LOG_TAG = '[TMW-KW-ASSIGN-REVIEW]';

    public const REVIEW_STATES    = [ 'pending', 'approved', 'rejected', 'deferred' ];
    public const EXECUTION_STATES = [ 'not_executed', 'executed', 'skipped', 'failed', 'stale' ];

    /** Classifications an operator may approve at all in PR-E. */
    public const APPROVABLE_CLASSIFICATIONS = [
        KeywordAssignmentMigrationAnalyzer::C_CLEAR_PRIMARY,
        KeywordAssignmentMigrationAnalyzer::C_SECONDARY,
        KeywordAssignmentMigrationAnalyzer::C_UNUSED_OWNER,
    ];

    /**
     * Classifications the execution service may write in PR-E. unused_owner
     * is deliberately absent: it stays reviewable/recordable but is never
     * bulk-activated merely because legacy ownership exists.
     */
    public const EXECUTABLE_CLASSIFICATIONS = [
        KeywordAssignmentMigrationAnalyzer::C_CLEAR_PRIMARY,
        KeywordAssignmentMigrationAnalyzer::C_SECONDARY,
    ];

    /** Fields pinned by snapshot_hash: exactly what the operator reviewed. */
    public const SNAPSHOT_FIELDS = [
        'classification',
        'normalized_keyword',
        'pool',
        'page_type',
        'target_type',
        'target_id',
        'target_key',
        'planned_role',
        'planned_status',
        'planned_canonical_owner',
        'active_in_rank_math',
        'present_in_content',
        'source_type',
        'source_reference',
        'source_batch_id',
        'source_import_row_id',
    ];

    /** Whitelisted, generically filterable columns for list/count/export. */
    public const FILTERABLE_COLUMNS = [
        'review_state', 'execution_state', 'classification', 'keyword_candidate_id',
        'normalized_keyword', 'pool', 'page_type', 'target_type', 'target_id',
        'target_key', 'planned_role', 'planned_status', 'source_type',
        'source_batch_id', 'present_in_content', 'active_in_rank_math',
        'candidate_status', 'migration_version', 'report_only', 'id',
    ];

    public function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_keyword_assignment_review';
    }

    public function audit_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_keyword_assignment_review_audit';
    }

    public function tables_exist(): bool {
        global $wpdb;
        foreach ( [ $this->table(), $this->audit_table() ] as $table ) {
            $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
            if ( ! is_string( $found ) || strtolower( $found ) !== strtolower( $table ) ) { return false; }
        }
        return true;
    }

    // ── Deterministic identity ────────────────────────────────────────────

    /**
     * Deterministic review identity: one review record per planned action of
     * one migration version, keyed by the same tuple as the assignment
     * identity (candidate, pool, page_type, target_type, target_id,
     * target_key) plus the migration version. Role, action kind, status, and
     * source attribution are deliberately NOT part of the identity — they are
     * part of the reviewed SNAPSHOT, so a change in any of them makes the
     * record stale instead of creating a duplicate.
     *
     * @param array<string,mixed> $record
     */
    public function review_key( array $record ): string {
        $record = $this->normalize_snapshot_input( $record );
        return sha1( implode( '|', [
            (string) ( $record['migration_version'] ?? KeywordAssignmentMigrationAnalyzer::MIGRATION_VERSION ),
            (string) (int) ( $record['keyword_candidate_id'] ?? 0 ),
            strtolower( (string) ( $record['pool'] ?? '' ) ),
            strtolower( (string) ( $record['page_type'] ?? '' ) ),
            strtolower( (string) ( $record['target_type'] ?? '' ) ),
            (string) (int) ( $record['target_id'] ?? 0 ),
            (string) ( $record['target_key'] ?? '' ),
        ] ) );
    }

    /**
     * Canonical hash of the reviewed snapshot. Field order is fixed by
     * SNAPSHOT_FIELDS so the hash is stable across PHP versions and callers.
     *
     * @param array<string,mixed> $record
     */
    public function snapshot_hash( array $record ): string {
        $record = $this->normalize_snapshot_input( $record );
        $parts = [];
        foreach ( self::SNAPSHOT_FIELDS as $field ) {
            $value = $record[ $field ] ?? '';
            if ( in_array( $field, [ 'target_id', 'source_batch_id', 'source_import_row_id', 'planned_canonical_owner' ], true ) ) {
                $value = (int) $value;
            }
            $parts[] = $field . '=' . (string) $value;
        }
        return sha1( implode( '|', $parts ) );
    }

    /**
     * Fields of the fresh snapshot that differ from the stored record.
     *
     * @param array<string,mixed> $stored
     * @param array<string,mixed> $fresh
     * @return array<int,string>
     */
    public function changed_snapshot_fields( array $stored, array $fresh ): array {
        $stored = $this->normalize_snapshot_input( $stored );
        $fresh  = $this->normalize_snapshot_input( $fresh );
        $changed = [];
        foreach ( self::SNAPSHOT_FIELDS as $field ) {
            $a = $stored[ $field ] ?? '';
            $b = $fresh[ $field ] ?? '';
            if ( in_array( $field, [ 'target_id', 'source_batch_id', 'source_import_row_id', 'planned_canonical_owner' ], true ) ) {
                if ( (int) $a !== (int) $b ) { $changed[] = $field; }
            } elseif ( (string) $a !== (string) $b ) {
                $changed[] = $field;
            }
        }
        return $changed;
    }

    /** Normalize snapshot values exactly as the review table persists them. */
    public function normalize_snapshot_input( array $record ): array {
        foreach ( [ 'pool' => 30, 'page_type' => 50, 'target_type' => 50 ] as $field => $max ) {
            $record[ $field ] = $this->sanitize_text( strtolower( (string) ( $record[ $field ] ?? '' ) ), $max );
        }
        foreach ( [ 'normalized_keyword' => 191, 'classification' => 40, 'target_key' => 191, 'planned_role' => 20, 'planned_status' => 30, 'source_type' => 50, 'source_reference' => 191 ] as $field => $max ) {
            $record[ $field ] = $this->sanitize_text( (string) ( $record[ $field ] ?? '' ), $max );
        }
        foreach ( [ 'target_id', 'source_batch_id', 'source_import_row_id', 'planned_canonical_owner', 'active_in_rank_math', 'present_in_content' ] as $field ) {
            $record[ $field ] = (int) ( $record[ $field ] ?? 0 );
        }
        return $record;
    }

    // ── Storage primitives (overridable for in-memory testing) ────────────

    /** @return array<string,mixed>|null */
    public function find_by_id( int $review_id ): ?array {
        global $wpdb;
        if ( $review_id <= 0 || ! $this->tables_exist() ) { return null; }
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $review_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function find_by_review_key( string $review_key ): ?array {
        global $wpdb;
        if ( '' === $review_key || ! $this->tables_exist() ) { return null; }
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE review_key = %s", $review_key ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * Generic filtered listing with stable deterministic ordering
     * (candidate, target identity, id — never database row order).
     *
     * @param array<string,mixed> $filters whitelisted column => value
     * @return array<int,array<string,mixed>>
     */
    public function list_reviews( array $filters = [], int $limit = 0, int $offset = 0 ): array {
        global $wpdb;
        if ( ! $this->tables_exist() ) { return []; }
        [ $where, $values ] = $this->build_where( $filters );
        $sql = "SELECT * FROM {$this->table()} $where ORDER BY keyword_candidate_id ASC, pool ASC, target_type ASC, target_id ASC, target_key ASC, id ASC";
        if ( $limit > 0 ) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . max( 0, (int) $offset );
        }
        $rows = [] === $values ? $wpdb->get_results( $sql, ARRAY_A ) : $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    /** @param array<string,mixed> $filters */
    public function count_reviews( array $filters = [] ): int {
        global $wpdb;
        if ( ! $this->tables_exist() ) { return 0; }
        [ $where, $values ] = $this->build_where( $filters );
        $sql = "SELECT COUNT(*) FROM {$this->table()} $where";
        return (int) ( [] === $values ? $wpdb->get_var( $sql ) : $wpdb->get_var( $wpdb->prepare( $sql, $values ) ) );
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<int,mixed>}
     */
    private function build_where( array $filters ): array {
        $clauses = [];
        $values  = [];
        foreach ( $filters as $column => $value ) {
            if ( ! in_array( (string) $column, self::FILTERABLE_COLUMNS, true ) ) { continue; }
            if ( is_array( $value ) ) {
                $value = array_values( $value );
                if ( [] === $value ) { continue; }
                $placeholders = implode( ',', array_fill( 0, count( $value ), is_int( $value[0] ) ? '%d' : '%s' ) );
                $clauses[] = "$column IN ($placeholders)";
                foreach ( $value as $item ) { $values[] = $item; }
                continue;
            }
            $clauses[] = is_int( $value ) ? "$column = %d" : "$column = %s";
            $values[] = $value;
        }
        return [ [] === $clauses ? '' : 'WHERE ' . implode( ' AND ', $clauses ), $values ];
    }

    /** @param array<string,mixed> $row @return int 0 on failure */
    protected function insert_row( array $row ): int {
        global $wpdb;
        if ( ! $this->tables_exist() ) { return 0; }
        $ok = $wpdb->insert( $this->table(), $row );
        return false === $ok ? 0 : (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $fields */
    protected function update_row( int $review_id, array $fields ): bool {
        global $wpdb;
        if ( $review_id <= 0 || [] === $fields || ! $this->tables_exist() ) { return false; }
        return false !== $wpdb->update( $this->table(), $fields, [ 'id' => $review_id ] );
    }

    protected function transaction( string $command ): bool {
        global $wpdb;
        return false !== $wpdb->query( $command );
    }

    /** @param array<string,mixed> $row */
    protected function insert_audit_row( array $row ): bool {
        global $wpdb;
        if ( ! $this->tables_exist() ) { return false; }
        return false !== $wpdb->insert( $this->audit_table(), $row );
    }

    /** @return array<int,array<string,mixed>> oldest first */
    public function audit_for_review( int $review_id ): array {
        global $wpdb;
        if ( $review_id <= 0 || ! $this->tables_exist() ) { return []; }
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->audit_table()} WHERE review_id = %d ORDER BY id ASC", $review_id ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    // ── Record creation / refresh (used only by the sync service) ─────────

    /**
     * Create a new review record from a normalized planned-action record.
     * The record arrives with all snapshot fields; identity and snapshot
     * hashes are computed here so they can never drift from the stored data.
     *
     * @param array<string,mixed> $record
     * @return array{ok:bool,id?:int,error?:string}
     */
    public function create_review( array $record, string $actor, string $source ): array {
        $record = $this->normalize_snapshot_input( $record );
        $candidate_id = (int) ( $record['keyword_candidate_id'] ?? 0 );
        if ( $candidate_id <= 0 ) { return [ 'ok' => false, 'error' => 'missing_keyword_candidate_id' ]; }
        if ( '' === (string) ( $record['classification'] ?? '' ) ) { return [ 'ok' => false, 'error' => 'missing_classification' ]; }
        if ( '' === (string) ( $record['pool'] ?? '' ) || '' === (string) ( $record['page_type'] ?? '' ) ) {
            return [ 'ok' => false, 'error' => 'missing_pool_or_page_type' ];
        }
        if ( '' === (string) ( $record['target_key'] ?? '' ) ) { return [ 'ok' => false, 'error' => 'missing_target_key' ]; }

        $now = $this->now();
        $row = [
            'review_key'              => $this->review_key( $record ),
            'migration_version'       => (string) ( $record['migration_version'] ?? KeywordAssignmentMigrationAnalyzer::MIGRATION_VERSION ),
            'keyword_candidate_id'    => $candidate_id,
            'assignment_key'          => (string) ( $record['assignment_key'] ?? '' ),
            'normalized_keyword'      => $this->sanitize_text( (string) ( $record['normalized_keyword'] ?? '' ), 191 ),
            'classification'          => $this->sanitize_text( (string) $record['classification'], 40 ),
            'candidate_status'        => $this->sanitize_text( (string) ( $record['candidate_status'] ?? '' ), 30 ),
            'planned_action'          => $this->sanitize_text( (string) ( $record['planned_action'] ?? '' ), 20 ),
            'pool'                    => $this->sanitize_text( strtolower( (string) $record['pool'] ), 30 ),
            'page_type'               => $this->sanitize_text( strtolower( (string) $record['page_type'] ), 50 ),
            'target_type'             => $this->sanitize_text( strtolower( (string) ( $record['target_type'] ?? '' ) ), 50 ),
            'target_id'               => (int) ( $record['target_id'] ?? 0 ),
            'target_key'              => $this->sanitize_text( (string) $record['target_key'], 191 ),
            'target_name'             => $this->sanitize_text( (string) ( $record['target_name'] ?? '' ), 255 ),
            'planned_role'            => $this->sanitize_text( (string) ( $record['planned_role'] ?? '' ), 20 ),
            'planned_status'          => $this->sanitize_text( (string) ( $record['planned_status'] ?? '' ), 30 ),
            'planned_canonical_owner' => ! empty( $record['planned_canonical_owner'] ) ? 1 : 0,
            'active_in_rank_math'     => ! empty( $record['active_in_rank_math'] ) ? 1 : 0,
            'present_in_content'      => ! empty( $record['present_in_content'] ) ? 1 : 0,
            'source_type'             => $this->sanitize_text( (string) ( $record['source_type'] ?? '' ), 50 ),
            'source_reference'        => $this->sanitize_text( (string) ( $record['source_reference'] ?? '' ), 191 ),
            'source_batch_id'         => (int) ( $record['source_batch_id'] ?? 0 ),
            'source_import_row_id'    => (int) ( $record['source_import_row_id'] ?? 0 ),
            'snapshot_hash'           => $this->snapshot_hash( $record ),
            'report_only'             => ! empty( $record['report_only'] ) ? 1 : 0,
            'review_state'            => 'pending',
            'reviewer'                => '',
            'review_note'             => '',
            'reviewed_at'             => null,
            'execution_state'         => 'not_executed',
            'executed_at'             => null,
            'execution_result'        => '',
            'stale_reason'            => '',
            'created_at'              => $now,
            'updated_at'              => $now,
        ];
        $existing = $this->find_by_review_key( (string) $row['review_key'] );
        if ( null !== $existing ) {
            return [ 'ok' => false, 'error' => 'review_identity_exists', 'id' => (int) $existing['id'] ];
        }
        if ( ! $this->transaction( 'START TRANSACTION' ) ) { return [ 'ok' => false, 'error' => 'transaction_start_failed' ]; }
        $id = $this->insert_row( $row );
        if ( $id <= 0 ) { $this->transaction( 'ROLLBACK' ); return [ 'ok' => false, 'error' => 'insert_failed' ]; }
        if ( ! $this->audit( $id, (string) $row['review_key'], 'sync_create', '', 'pending', '', 'not_executed', $actor, '', $source, (string) $row['snapshot_hash'] ) ) {
            $this->transaction( 'ROLLBACK' );
            return [ 'ok' => false, 'error' => 'audit_insert_failed' ];
        }
        if ( ! $this->transaction( 'COMMIT' ) ) { $this->transaction( 'ROLLBACK' ); return [ 'ok' => false, 'error' => 'transaction_commit_failed' ]; }
        return [ 'ok' => true, 'id' => $id ];
    }

    /**
     * Refresh the snapshot of a PENDING, unexecuted record to a fresh
     * planned action of the same identity. Also restores execution_state
     * from 'stale' back to 'not_executed', because the record now again
     * reflects exactly what the analyzer plans. Never touches records with
     * any human review state — the sync service routes those to mark_stale()
     * or preserves them.
     *
     * @param array<string,mixed> $stored
     * @param array<string,mixed> $fresh normalized record for the same identity
     * @return array{ok:bool,outcome?:string,error?:string}
     */
    public function refresh_pending_snapshot( array $stored, array $fresh, string $actor, string $source ): array {
        $fresh = $this->normalize_snapshot_input( $fresh );
        if ( 'pending' !== (string) ( $stored['review_state'] ?? '' ) ) {
            return [ 'ok' => false, 'error' => 'refresh_requires_pending_state' ];
        }
        if ( 'executed' === (string) ( $stored['execution_state'] ?? '' ) ) {
            return [ 'ok' => false, 'error' => 'executed_record_is_immutable' ];
        }
        $fresh_hash = $this->snapshot_hash( $fresh );
        $was_stale = 'stale' === (string) ( $stored['execution_state'] ?? '' );
        if ( (string) $stored['snapshot_hash'] === $fresh_hash && ! $was_stale ) {
            return [ 'ok' => true, 'outcome' => 'unchanged' ];
        }
        $fields = [
            'assignment_key'          => (string) ( $fresh['assignment_key'] ?? $stored['assignment_key'] ?? '' ),
            'normalized_keyword'      => $this->sanitize_text( (string) ( $fresh['normalized_keyword'] ?? '' ), 191 ),
            'classification'          => $this->sanitize_text( (string) ( $fresh['classification'] ?? '' ), 40 ),
            'candidate_status'        => $this->sanitize_text( (string) ( $fresh['candidate_status'] ?? '' ), 30 ),
            'planned_action'          => $this->sanitize_text( (string) ( $fresh['planned_action'] ?? '' ), 20 ),
            'target_name'             => $this->sanitize_text( (string) ( $fresh['target_name'] ?? '' ), 255 ),
            'planned_role'            => $this->sanitize_text( (string) ( $fresh['planned_role'] ?? '' ), 20 ),
            'planned_status'          => $this->sanitize_text( (string) ( $fresh['planned_status'] ?? '' ), 30 ),
            'planned_canonical_owner' => ! empty( $fresh['planned_canonical_owner'] ) ? 1 : 0,
            'active_in_rank_math'     => ! empty( $fresh['active_in_rank_math'] ) ? 1 : 0,
            'present_in_content'      => ! empty( $fresh['present_in_content'] ) ? 1 : 0,
            'source_type'             => $this->sanitize_text( (string) ( $fresh['source_type'] ?? '' ), 50 ),
            'source_reference'        => $this->sanitize_text( (string) ( $fresh['source_reference'] ?? '' ), 191 ),
            'source_batch_id'         => (int) ( $fresh['source_batch_id'] ?? 0 ),
            'source_import_row_id'    => (int) ( $fresh['source_import_row_id'] ?? 0 ),
            'snapshot_hash'           => $fresh_hash,
            'execution_state'         => 'not_executed',
            'stale_reason'            => '',
            'updated_at'              => $this->now(),
        ];
        if ( ! $this->update_with_audit( $stored, $fields, 'sync_refresh', 'pending', 'pending', (string) $stored['execution_state'], 'not_executed', $actor, $was_stale ? 'stale_cleared_by_matching_resync' : 'snapshot_refreshed', $source, $fresh_hash ) ) {
            return [ 'ok' => false, 'error' => 'mutation_or_audit_failed' ];
        }
        return [ 'ok' => true, 'outcome' => 'updated' ];
    }

    /**
     * Mark a record stale: the fresh analyzer output no longer matches the
     * reviewed snapshot (or produces no action for the identity at all).
     * Human review state is preserved untouched; the record simply becomes
     * non-executable until it is resynced/reset.
     */
    public function mark_stale( array $stored, string $reason, string $actor, string $source ): array {
        $execution_state = (string) ( $stored['execution_state'] ?? '' );
        if ( 'executed' === $execution_state ) {
            return [ 'ok' => false, 'error' => 'executed_record_is_immutable' ];
        }
        if ( 'stale' === $execution_state && (string) ( $stored['stale_reason'] ?? '' ) === $reason ) {
            return [ 'ok' => true, 'outcome' => 'unchanged' ];
        }
        $fields = [
            'execution_state' => 'stale',
            'stale_reason'    => $this->sanitize_text( $reason, 500 ),
            'updated_at'      => $this->now(),
        ];
        if ( ! $this->update_with_audit( $stored, $fields, 'sync_stale', (string) $stored['review_state'], (string) $stored['review_state'], $execution_state, 'stale', $actor, $reason, $source, (string) $stored['snapshot_hash'] ) ) { return [ 'ok' => false, 'error' => 'mutation_or_audit_failed' ]; }
        return [ 'ok' => true, 'outcome' => 'stale' ];
    }

    /**
     * Restore a stale record whose fresh planned action again matches the
     * reviewed snapshot exactly. Review state is preserved.
     */
    public function restore_from_stale( array $stored, string $actor, string $source ): array {
        if ( 'stale' !== (string) ( $stored['execution_state'] ?? '' ) ) {
            return [ 'ok' => false, 'error' => 'record_not_stale' ];
        }
        $fields = [
            'execution_state' => 'not_executed',
            'stale_reason'    => '',
            'updated_at'      => $this->now(),
        ];
        if ( ! $this->update_with_audit( $stored, $fields, 'sync_restore', (string) $stored['review_state'], (string) $stored['review_state'], 'stale', 'not_executed', $actor, 'fresh_plan_matches_reviewed_snapshot', $source, (string) $stored['snapshot_hash'] ) ) { return [ 'ok' => false, 'error' => 'mutation_or_audit_failed' ]; }
        return [ 'ok' => true, 'outcome' => 'restored' ];
    }

    // ── Review-state transitions (assignment-specific, audited) ───────────

    /**
     * Transition one review record's review_state. Assignment-specific by
     * construction: exactly one record (one planned assignment identity) is
     * mutated; nothing propagates to sibling assignments of the same keyword
     * and nothing ever touches the candidate table.
     *
     * @return array{ok:bool,error?:string,outcome?:string}
     */
    public function transition_review_state( int $review_id, string $new_state, string $actor, string $note, string $source ): array {
        if ( ! in_array( $new_state, self::REVIEW_STATES, true ) ) {
            return [ 'ok' => false, 'error' => 'invalid_review_state_' . $new_state ];
        }
        $stored = $this->find_by_id( $review_id );
        if ( null === $stored ) { return [ 'ok' => false, 'error' => 'review_record_not_found' ]; }

        $old_state = (string) $stored['review_state'];
        $execution_state = (string) $stored['execution_state'];

        if ( 'executed' === $execution_state ) {
            return [ 'ok' => false, 'error' => 'executed_record_is_immutable' ];
        }
        if ( $old_state === $new_state ) {
            return [ 'ok' => true, 'outcome' => 'unchanged' ];
        }
        if ( ! $this->transition_allowed( $old_state, $new_state ) ) {
            return [ 'ok' => false, 'error' => 'transition_not_allowed_' . $old_state . '_to_' . $new_state ];
        }
        if ( 'approved' === $new_state ) {
            if ( ! empty( $stored['report_only'] ) ) {
                return [ 'ok' => false, 'error' => 'report_only_record_cannot_be_approved' ];
            }
            if ( ! in_array( (string) $stored['classification'], self::APPROVABLE_CLASSIFICATIONS, true ) ) {
                return [ 'ok' => false, 'error' => 'classification_not_approvable_' . (string) $stored['classification'] ];
            }
            if ( 'stale' === $execution_state ) {
                return [ 'ok' => false, 'error' => 'stale_record_requires_resync_before_approval' ];
            }
        }

        $fields = [
            'review_state' => $new_state,
            'reviewer'     => $this->sanitize_text( $actor, 191 ),
            'review_note'  => $this->sanitize_text( $note, 500 ),
            'reviewed_at'  => $this->now(),
            'updated_at'   => $this->now(),
        ];
        if ( 'pending' === $new_state ) {
            // reset-to-pending clears reviewer identity but keeps the audit
            // trail as the permanent history of who did what before.
            $fields['reviewer']    = '';
            $fields['review_note'] = '';
            $fields['reviewed_at'] = null;
        }
        $action = 'pending' === $new_state ? 'reset_to_pending' : $new_state;
        if ( ! $this->update_with_audit( $stored, $fields, $action, $old_state, $new_state, $execution_state, $execution_state, $actor, $note, $source, (string) $stored['snapshot_hash'] ) ) {
            return [ 'ok' => false, 'error' => 'mutation_or_audit_failed' ];
        }
        $this->log( sprintf( 'review #%d %s -> %s by %s (%s)', $review_id, $old_state, $new_state, $actor, $source ) );
        return [ 'ok' => true, 'outcome' => $new_state ];
    }

    private function transition_allowed( string $from, string $to ): bool {
        $matrix = [
            'pending'  => [ 'approved', 'rejected', 'deferred' ],
            'approved' => [ 'rejected', 'deferred', 'pending' ],
            'rejected' => [ 'pending' ],
            'deferred' => [ 'approved', 'rejected', 'pending' ],
        ];
        return in_array( $to, $matrix[ $from ] ?? [], true );
    }

    // ── Execution-state transitions (used only by the execution service) ──

    /**
     * Record an execution outcome for one review record.
     *
     * @return array{ok:bool,error?:string}
     */
    public function mark_execution( int $review_id, string $execution_state, string $result, string $actor, string $source ): array {
        if ( ! in_array( $execution_state, self::EXECUTION_STATES, true ) ) {
            return [ 'ok' => false, 'error' => 'invalid_execution_state_' . $execution_state ];
        }
        $stored = $this->find_by_id( $review_id );
        if ( null === $stored ) { return [ 'ok' => false, 'error' => 'review_record_not_found' ]; }
        $old = (string) $stored['execution_state'];
        if ( 'executed' === $old ) {
            return [ 'ok' => false, 'error' => 'executed_record_is_immutable' ];
        }
        $allowed = [
            'not_executed' => [ 'executed', 'skipped', 'failed', 'stale' ],
            'failed'       => [ 'executed', 'failed', 'stale' ],
            'skipped'      => [ 'executed', 'skipped', 'stale' ],
            'stale'        => [ 'not_executed' ],
        ];
        if ( $old !== $execution_state && ! in_array( $execution_state, $allowed[ $old ] ?? [], true ) ) {
            return [ 'ok' => false, 'error' => 'execution_transition_not_allowed_' . $old . '_to_' . $execution_state ];
        }
        $fields = [
            'execution_state'  => $execution_state,
            'execution_result' => $this->sanitize_text( $result, 500 ),
            'updated_at'       => $this->now(),
        ];
        if ( 'executed' === $execution_state ) { $fields['executed_at'] = $this->now(); }
        if ( 'stale' === $execution_state ) { $fields['stale_reason'] = $this->sanitize_text( $result, 500 ); }
        if ( ! $this->update_with_audit( $stored, $fields, 'execution_' . $execution_state, (string) $stored['review_state'], (string) $stored['review_state'], $old, $execution_state, $actor, $result, $source, (string) $stored['snapshot_hash'] ) ) {
            return [ 'ok' => false, 'error' => 'mutation_or_audit_failed' ];
        }
        return [ 'ok' => true ];
    }

    // ── Audit ─────────────────────────────────────────────────────────────

    private function audit( int $review_id, string $review_key, string $action, string $old_review, string $new_review, string $old_exec, string $new_exec, string $actor, string $note, string $source, string $snapshot_hash ): bool {
        return $this->insert_audit_row( [
            'review_id'           => $review_id,
            'review_key'          => $review_key,
            'action'              => $this->sanitize_text( $action, 40 ),
            'old_review_state'    => $this->sanitize_text( $old_review, 20 ),
            'new_review_state'    => $this->sanitize_text( $new_review, 20 ),
            'old_execution_state' => $this->sanitize_text( $old_exec, 20 ),
            'new_execution_state' => $this->sanitize_text( $new_exec, 20 ),
            'actor'               => $this->sanitize_text( $actor, 191 ),
            'note'                => $this->sanitize_text( $note, 500 ),
            'source'              => $this->sanitize_text( $source, 100 ),
            'snapshot_hash'       => $this->sanitize_text( $snapshot_hash, 40 ),
            'created_at'          => $this->now(),
        ] );
    }

    /** Update a review and append its audit as one fail-closed unit. */
    private function update_with_audit( array $stored, array $fields, string $action, string $old_review, string $new_review, string $old_exec, string $new_exec, string $actor, string $note, string $source, string $snapshot_hash ): bool {
        $id = (int) $stored['id'];
        if ( ! $this->transaction( 'START TRANSACTION' ) ) { return false; }
        if ( ! $this->update_row( $id, $fields ) ) { $this->transaction( 'ROLLBACK' ); return false; }
        if ( $this->audit( $id, (string) $stored['review_key'], $action, $old_review, $new_review, $old_exec, $new_exec, $actor, $note, $source, $snapshot_hash ) && $this->transaction( 'COMMIT' ) ) { return true; }
        $this->transaction( 'ROLLBACK' );
        return false;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function sanitize_text( string $value, int $max ): string {
        $value = trim( preg_replace( '/[\r\n\t]+/', ' ', $value ) ?? '' );
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
    }

    protected function now(): string {
        return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
    }

    private function log( string $message ): void {
        error_log( self::LOG_TAG . ' ' . $message );
    }
}
