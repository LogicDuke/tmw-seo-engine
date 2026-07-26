<?php
/**
 * TMW SEO Engine — Keyword Assignment Repository (PR-C).
 *
 * Data-access layer for {$prefix}tmw_keyword_assignments: the per-target
 * assignment records that separate global keyword identity (one row per
 * normalized keyword in tmw_keyword_candidates) from where and how that
 * keyword is used (pool, page type, target, role, status).
 *
 * PR-C SCOPE: this repository is wired into the loader but NO production
 * path calls it yet. Approval, rejection, conflict decisions, generation,
 * Rank Math selection, and content behavior all remain on the candidate
 * table. Later PRs migrate reads/writes here behind explicit flags.
 *
 * Invariants enforced here (not at the SQL layer — MySQL/MariaDB has no
 * partial unique index, and generated-column workarounds are incompatible
 * with dbDelta):
 * - one row per assignment identity via the UNIQUE sha1 assignment_key over
 *   (candidate, pool, page_type, target_type, target identity);
 * - at most one ACTIVE primary owner per candidate, enforced transactionally
 *   in set_primary_owner() with SELECT ... FOR UPDATE and fail-closed
 *   verification before COMMIT;
 * - canonical_owner=1 only with role=primary;
 * - excluded/blocked/rejected/inactive assignments can never be
 *   active_in_rank_math.
 *
 * Log tag: [TMW-KW-ASSIGN]
 *
 * @package TMWSEO\Engine\Keywords
 * @since   5.9.22
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class KeywordAssignmentRepository {

    public const LOG_TAG = '[TMW-KW-ASSIGN]';

    public const ROLES = [ 'primary', 'secondary', 'discovery', 'excluded' ];

    public const STATUSES = [ 'approved', 'review_required', 'blocked', 'rejected', 'inactive' ];

    /** Statuses that count as ACTIVE for the single-primary-owner invariant. */
    public const ACTIVE_STATUSES = [ 'approved', 'review_required' ];

    /** Statuses/roles that must never be active in Rank Math. */
    private const RANK_MATH_FORBIDDEN_STATUSES = [ 'blocked', 'rejected', 'inactive' ];

    public function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_keyword_assignments';
    }

    public function table_exists(): bool {
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $this->table() ) ) );
        return is_string( $found ) && strtolower( $found ) === strtolower( $this->table() );
    }

    // ── Identity ──────────────────────────────────────────────────────────

    /**
     * Deterministic assignment identity: one assignment per
     * (candidate, pool, page_type, target_type, target identity).
     *
     * target_key makes the identity deterministic even when target_id is
     * unavailable (e.g. a global pool scope): callers must supply a stable
     * target_key in that case; when target_id > 0 the default key is
     * "{target_type}:{target_id}". role and source batch are intentionally
     * NOT part of the identity, so duplicate import rows and re-imports of
     * the same target collapse onto one assignment row.
     *
     * @param array<string,mixed> $assignment
     */
    public function assignment_key( array $assignment ): string {
        return sha1( implode( '|', [
            (string) (int) ( $assignment['keyword_candidate_id'] ?? 0 ),
            (string) ( $assignment['pool'] ?? '' ),
            (string) ( $assignment['page_type'] ?? '' ),
            (string) ( $assignment['target_type'] ?? '' ),
            (string) (int) ( $assignment['target_id'] ?? 0 ),
            (string) ( $assignment['target_key'] ?? '' ),
        ] ) );
    }

    // ── Validation (small validator, matching the codebase's helper style) ─

    /**
     * Normalize and validate assignment data. Returns the normalized array
     * or a ['error' => reason] array. Never throws.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function normalize_assignment( array $data ): array {
        $candidate_id = (int) ( $data['keyword_candidate_id'] ?? 0 );
        if ( $candidate_id <= 0 ) {
            return [ 'error' => 'missing_keyword_candidate_id' ];
        }

        $pool        = $this->sanitize_key_value( (string) ( $data['pool'] ?? '' ), 30 );
        $page_type   = $this->sanitize_key_value( (string) ( $data['page_type'] ?? '' ), 50 );
        $target_type = $this->sanitize_key_value( (string) ( $data['target_type'] ?? '' ), 50 );
        if ( '' === $pool || '' === $page_type ) {
            return [ 'error' => 'missing_pool_or_page_type' ];
        }

        $target_id  = null !== ( $data['target_id'] ?? null ) ? max( 0, (int) $data['target_id'] ) : 0;
        $target_key = trim( (string) ( $data['target_key'] ?? '' ) );
        if ( '' === $target_key ) {
            if ( $target_id > 0 && '' !== $target_type ) {
                $target_key = $target_type . ':' . $target_id;
            } else {
                // Deterministic identity requires an explicit key when no
                // numeric target exists (e.g. a global pool scope).
                return [ 'error' => 'indeterminate_target_identity' ];
            }
        }
        $target_key = substr( $target_key, 0, 191 );

        $role = strtolower( trim( (string) ( $data['role'] ?? 'secondary' ) ) );
        if ( ! in_array( $role, self::ROLES, true ) ) {
            return [ 'error' => 'unsupported_role' ];
        }
        $status = strtolower( trim( (string) ( $data['status'] ?? 'review_required' ) ) );
        if ( ! in_array( $status, self::STATUSES, true ) ) {
            return [ 'error' => 'unsupported_status' ];
        }

        $canonical_owner = ! empty( $data['canonical_owner'] ) ? 1 : 0;
        if ( 1 === $canonical_owner && 'primary' !== $role ) {
            return [ 'error' => 'canonical_owner_requires_primary_role' ];
        }

        $active_in_rank_math = ! empty( $data['active_in_rank_math'] ) ? 1 : 0;
        if ( 1 === $active_in_rank_math
            && ( 'excluded' === $role || in_array( $status, self::RANK_MATH_FORBIDDEN_STATUSES, true ) ) ) {
            return [ 'error' => 'rank_math_activation_forbidden_for_role_or_status' ];
        }

        $normalized = [
            'keyword_candidate_id'     => $candidate_id,
            'pool'                     => $pool,
            'page_type'                => $page_type,
            'target_type'              => $target_type,
            'target_id'                => $target_id,
            'target_key'               => $target_key,
            'target_name'              => $this->sanitize_text_value( (string) ( $data['target_name'] ?? '' ), 255 ),
            'target_slug'              => $this->sanitize_text_value( (string) ( $data['target_slug'] ?? '' ), 191 ),
            'role'                     => $role,
            'status'                   => $status,
            'canonical_owner'          => $canonical_owner,
            'shared_secondary_allowed' => ! empty( $data['shared_secondary_allowed'] ) ? 1 : 0,
            'conflict_reason'          => $this->sanitize_text_value( (string) ( $data['conflict_reason'] ?? '' ), 191 ),
            'approval_reason'          => $this->sanitize_text_value( (string) ( $data['approval_reason'] ?? '' ), 191 ),
            'source_batch_id'          => max( 0, (int) ( $data['source_batch_id'] ?? 0 ) ),
            'source_import_row_id'     => max( 0, (int) ( $data['source_import_row_id'] ?? 0 ) ),
            'source_type'              => $this->sanitize_key_value( (string) ( $data['source_type'] ?? '' ), 50 ),
            'source_reference'         => $this->sanitize_text_value( (string) ( $data['source_reference'] ?? '' ), 191 ),
            'active_in_rank_math'      => $active_in_rank_math,
            'present_in_content'       => ! empty( $data['present_in_content'] ) ? 1 : 0,
            'last_verified_at'         => $this->sanitize_text_value( (string) ( $data['last_verified_at'] ?? '' ), 32 ),
        ];
        $normalized['assignment_key'] = $this->assignment_key( $normalized );
        return $normalized;
    }

    // ── Reads ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    public function find_by_id( int $assignment_id ): ?array {
        if ( $assignment_id <= 0 || ! $this->table_exists() ) { return null; }
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE id = %d LIMIT 1',
            $assignment_id
        ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function find_assignments_for_candidate( int $candidate_id ): array {
        if ( $candidate_id <= 0 || ! $this->table_exists() ) { return []; }
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE keyword_candidate_id = %d ORDER BY id ASC',
            $candidate_id
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    /** @return array<int,array<string,mixed>> */
    public function find_assignments_for_target( string $pool, string $page_type, string $target_type, int $target_id, string $target_key = '' ): array {
        if ( ! $this->table_exists() ) { return []; }
        global $wpdb;
        $pool       = $this->sanitize_key_value( $pool, 30 );
        $page_type  = $this->sanitize_key_value( $page_type, 50 );
        $target_type = $this->sanitize_key_value( $target_type, 50 );
        if ( '' !== trim( $target_key ) ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                'SELECT * FROM ' . $this->table() . ' WHERE pool = %s AND page_type = %s AND target_key = %s ORDER BY id ASC',
                $pool,
                $page_type,
                substr( trim( $target_key ), 0, 191 )
            ), ARRAY_A );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare(
                'SELECT * FROM ' . $this->table() . ' WHERE pool = %s AND page_type = %s AND target_type = %s AND target_id = %d ORDER BY id ASC',
                $pool,
                $page_type,
                $target_type,
                max( 0, $target_id )
            ), ARRAY_A );
        }
        return is_array( $rows ) ? $rows : [];
    }

    /** The single ACTIVE primary owner for a candidate, if any. @return array<string,mixed>|null */
    public function find_primary_owner( int $candidate_id ): ?array {
        if ( $candidate_id <= 0 || ! $this->table_exists() ) { return null; }
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . $this->table() . " WHERE keyword_candidate_id = %d AND role = 'primary' AND canonical_owner = 1 AND status IN ('" . implode( "','", self::ACTIVE_STATUSES ) . "') ORDER BY id ASC LIMIT 1",
            $candidate_id
        ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function find_secondary_assignments( int $candidate_id ): array {
        if ( $candidate_id <= 0 || ! $this->table_exists() ) { return []; }
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . $this->table() . " WHERE keyword_candidate_id = %d AND role = 'secondary' ORDER BY id ASC",
            $candidate_id
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Look up one assignment by its full identity tuple.
     *
     * @param array<string,mixed> $identity pool, page_type, target_type, target_id, target_key
     * @return array<string,mixed>|null
     */
    public function find_assignment( int $candidate_id, array $identity ): ?array {
        if ( $candidate_id <= 0 || ! $this->table_exists() ) { return null; }
        $normalized = $this->normalize_assignment( array_merge( $identity, [
            'keyword_candidate_id' => $candidate_id,
        ] ) );
        if ( isset( $normalized['error'] ) ) { return null; }
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE assignment_key = %s LIMIT 1',
            $normalized['assignment_key']
        ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    public function count_assignments_for_candidate( int $candidate_id ): int {
        if ( $candidate_id <= 0 || ! $this->table_exists() ) { return 0; }
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE keyword_candidate_id = %d',
            $candidate_id
        ) );
    }

    public function candidate_has_other_assignments( int $candidate_id, int $exclude_assignment_id = 0 ): bool {
        if ( $candidate_id <= 0 || ! $this->table_exists() ) { return false; }
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE keyword_candidate_id = %d AND id != %d',
            $candidate_id,
            max( 0, $exclude_assignment_id )
        ) ) > 0;
    }

    // ── Writes ────────────────────────────────────────────────────────────

    /**
     * Create a new assignment. Fails (returns error array) when the identity
     * already exists — use upsert_assignment() for create-or-update.
     *
     * @param array<string,mixed> $data
     * @return array{ok:bool, id?:int, error?:string}
     */
    public function create_assignment( array $data ): array {
        if ( ! $this->table_exists() ) { return [ 'ok' => false, 'error' => 'assignments_table_missing' ]; }
        $normalized = $this->normalize_assignment( $data );
        if ( isset( $normalized['error'] ) ) {
            return [ 'ok' => false, 'error' => (string) $normalized['error'] ];
        }
        // Creating a NEW active primary while another active primary exists
        // must go through set_primary_owner() instead; fail closed here.
        if ( 'primary' === $normalized['role']
            && in_array( $normalized['status'], self::ACTIVE_STATUSES, true )
            && null !== $this->find_primary_owner( (int) $normalized['keyword_candidate_id'] ) ) {
            return [ 'ok' => false, 'error' => 'active_primary_owner_already_exists' ];
        }
        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM ' . $this->table() . ' WHERE assignment_key = %s LIMIT 1',
            $normalized['assignment_key']
        ) );
        if ( (int) $existing > 0 ) {
            return [ 'ok' => false, 'error' => 'assignment_identity_exists', 'id' => (int) $existing ];
        }
        $normalized['created_at'] = $this->now();
        $normalized['updated_at'] = $this->now();
        $inserted = $wpdb->insert( $this->table(), $this->to_row( $normalized ) );
        if ( false === $inserted ) {
            return [ 'ok' => false, 'error' => 'insert_failed' ];
        }
        $id = (int) $wpdb->insert_id;
        $this->log( sprintf( 'created assignment id=%d candidate=%d key=%s role=%s status=%s', $id, (int) $normalized['keyword_candidate_id'], (string) $normalized['target_key'], (string) $normalized['role'], (string) $normalized['status'] ) );
        return [ 'ok' => true, 'id' => $id ];
    }

    /**
     * Deterministic create-or-update by assignment identity. Duplicate import
     * rows and re-imports of the same target collapse onto the existing row;
     * only mutable fields are updated, identity fields never change.
     *
     * @param array<string,mixed> $data
     * @return array{ok:bool, id?:int, action?:string, error?:string}
     */
    public function upsert_assignment( array $data ): array {
        if ( ! $this->table_exists() ) { return [ 'ok' => false, 'error' => 'assignments_table_missing' ]; }
        $normalized = $this->normalize_assignment( $data );
        if ( isset( $normalized['error'] ) ) {
            return [ 'ok' => false, 'error' => (string) $normalized['error'] ];
        }
        global $wpdb;
        $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM ' . $this->table() . ' WHERE assignment_key = %s LIMIT 1',
            $normalized['assignment_key']
        ) );
        if ( $existing_id <= 0 ) {
            $created = $this->create_assignment( $data );
            if ( ! empty( $created['ok'] ) ) {
                return [ 'ok' => true, 'id' => (int) $created['id'], 'action' => 'created' ];
            }
            return $created;
        }
        // Canonical/primary promotion must go through set_primary_owner();
        // an upsert never silently flips ownership.
        $mutable = [
            'target_name'              => $normalized['target_name'],
            'target_slug'              => $normalized['target_slug'],
            'status'                   => $normalized['status'],
            'shared_secondary_allowed' => $normalized['shared_secondary_allowed'],
            'conflict_reason'          => $normalized['conflict_reason'],
            'approval_reason'          => $normalized['approval_reason'],
            'source_batch_id'          => $normalized['source_batch_id'],
            'source_import_row_id'     => $normalized['source_import_row_id'],
            'source_type'              => $normalized['source_type'],
            'source_reference'         => $normalized['source_reference'],
            'active_in_rank_math'      => $normalized['active_in_rank_math'],
            'present_in_content'       => $normalized['present_in_content'],
            'last_verified_at'         => $normalized['last_verified_at'],
            'updated_at'               => $this->now(),
        ];
        $updated = $wpdb->update( $this->table(), $mutable, [ 'id' => $existing_id ] );
        if ( false === $updated ) {
            return [ 'ok' => false, 'error' => 'update_failed', 'id' => $existing_id ];
        }
        $this->log( sprintf( 'upserted assignment id=%d candidate=%d (existing identity)', $existing_id, (int) $normalized['keyword_candidate_id'] ) );
        return [ 'ok' => true, 'id' => $existing_id, 'action' => 'updated' ];
    }

    /**
     * Assignment-scoped status change. Never touches the candidate table or
     * any other assignment. Moving to a Rank-Math-forbidden status clears
     * active_in_rank_math on the same row so the invariant cannot be violated
     * by a status transition.
     */
    public function update_assignment_status( int $assignment_id, string $status, ?string $reason = null ): bool {
        if ( $assignment_id <= 0 || ! $this->table_exists() ) { return false; }
        $status = strtolower( trim( $status ) );
        if ( ! in_array( $status, self::STATUSES, true ) ) { return false; }
        $data = [ 'status' => $status, 'updated_at' => $this->now() ];
        if ( null !== $reason ) {
            $data['approval_reason'] = $this->sanitize_text_value( $reason, 191 );
        }
        if ( in_array( $status, self::RANK_MATH_FORBIDDEN_STATUSES, true ) ) {
            $data['active_in_rank_math'] = 0;
        }
        global $wpdb;
        $updated = $wpdb->update( $this->table(), $data, [ 'id' => $assignment_id ] );
        if ( false === $updated ) { return false; }
        $this->log( sprintf( 'status change id=%d status=%s', $assignment_id, $status ) );
        return true;
    }

    /**
     * Promote one assignment to the single canonical primary owner of its
     * candidate. Transactional and fail-closed:
     * - locks the candidate's assignment rows (SELECT ... FOR UPDATE);
     * - demotes every other active primary to secondary/non-canonical;
     * - promotes the target row;
     * - verifies exactly one active canonical primary remains, else ROLLBACK.
     */
    public function set_primary_owner( int $assignment_id ): bool {
        if ( $assignment_id <= 0 || ! $this->table_exists() ) { return false; }
        global $wpdb;
        $table = $this->table();

        if ( false === $wpdb->query( 'START TRANSACTION' ) || '' !== (string) $wpdb->last_error ) {
            $this->log( 'set_primary_owner: transaction start failed id=' . $assignment_id );
            return false;
        }

        $target = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d LIMIT 1 FOR UPDATE",
            $assignment_id
        ), ARRAY_A );
        if ( ! is_array( $target ) ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }
        if ( in_array( (string) ( $target['status'] ?? '' ), self::RANK_MATH_FORBIDDEN_STATUSES, true ) ) {
            // A blocked/rejected/inactive assignment cannot become the owner.
            $wpdb->query( 'ROLLBACK' );
            $this->log( 'set_primary_owner: refused for non-active status id=' . $assignment_id );
            return false;
        }
        $candidate_id = (int) ( $target['keyword_candidate_id'] ?? 0 );
        if ( $candidate_id <= 0 ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }

        // Lock all sibling assignments for this candidate.
        $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE keyword_candidate_id = %d FOR UPDATE",
            $candidate_id
        ), ARRAY_A );

        // Demote any other canonical/active-primary rows.
        $demoted = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET canonical_owner = 0, role = 'secondary', updated_at = %s WHERE keyword_candidate_id = %d AND id != %d AND (canonical_owner = 1 OR role = 'primary')",
            $this->now(),
            $candidate_id,
            $assignment_id
        ) );
        if ( false === $demoted ) {
            $wpdb->query( 'ROLLBACK' );
            $this->log( 'set_primary_owner: demotion failed candidate=' . $candidate_id );
            return false;
        }

        $promoted = $wpdb->update( $table, [
            'role'            => 'primary',
            'canonical_owner' => 1,
            'updated_at'      => $this->now(),
        ], [ 'id' => $assignment_id ] );
        if ( false === $promoted ) {
            $wpdb->query( 'ROLLBACK' );
            $this->log( 'set_primary_owner: promotion failed id=' . $assignment_id );
            return false;
        }

        // Fail-closed verification before commit: exactly one canonical
        // primary must remain for this candidate.
        $owner_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE keyword_candidate_id = %d AND canonical_owner = 1 AND role = 'primary'",
            $candidate_id
        ) );
        if ( 1 !== $owner_count ) {
            $wpdb->query( 'ROLLBACK' );
            $this->log( sprintf( 'set_primary_owner: verification failed candidate=%d owner_count=%d — rolled back', $candidate_id, $owner_count ) );
            return false;
        }

        if ( false === $wpdb->query( 'COMMIT' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }
        $this->log( sprintf( 'primary owner set candidate=%d assignment=%d', $candidate_id, $assignment_id ) );
        return true;
    }

    /** Demote a canonical primary back to a non-canonical secondary. */
    public function clear_primary_owner( int $assignment_id ): bool {
        if ( $assignment_id <= 0 || ! $this->table_exists() ) { return false; }
        global $wpdb;
        $updated = $wpdb->update( $this->table(), [
            'canonical_owner' => 0,
            'role'            => 'secondary',
            'updated_at'      => $this->now(),
        ], [ 'id' => $assignment_id ] );
        if ( false === $updated ) { return false; }
        $this->log( 'primary owner cleared assignment=' . $assignment_id );
        return true;
    }

    /**
     * Delete one assignment row by id. Targeted single-row deletion only —
     * needed for future migration rollback mapping. Never cascades and never
     * touches the candidate or other assignments.
     */
    public function delete_assignment( int $assignment_id ): bool {
        if ( $assignment_id <= 0 || ! $this->table_exists() ) { return false; }
        global $wpdb;
        $deleted = $wpdb->delete( $this->table(), [ 'id' => $assignment_id ], [ '%d' ] );
        if ( false === $deleted ) { return false; }
        $this->log( 'deleted assignment=' . $assignment_id );
        return true;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @param array<string,mixed> $normalized @return array<string,mixed> */
    private function to_row( array $normalized ): array {
        $row = $normalized;
        foreach ( [ 'target_name', 'target_slug', 'conflict_reason', 'approval_reason', 'source_type', 'source_reference', 'last_verified_at' ] as $nullable ) {
            if ( '' === ( $row[ $nullable ] ?? '' ) ) { $row[ $nullable ] = null; }
        }
        foreach ( [ 'source_batch_id', 'source_import_row_id' ] as $nullable_int ) {
            if ( 0 === ( $row[ $nullable_int ] ?? 0 ) ) { $row[ $nullable_int ] = null; }
        }
        return $row;
    }

    private function sanitize_key_value( string $value, int $max ): string {
        $value = function_exists( 'sanitize_key' ) ? sanitize_key( $value ) : strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) );
        return substr( $value, 0, $max );
    }

    private function sanitize_text_value( string $value, int $max ): string {
        $value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( strip_tags( $value ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
    }

    private function now(): string {
        return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
    }

    private function log( string $message ): void {
        error_log( self::LOG_TAG . ' ' . $message );
    }
}
