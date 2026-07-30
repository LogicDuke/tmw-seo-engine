<?php
/**
 * PR-F — shared test doubles for the keyword-assignment validation tooling.
 *
 * All real domain logic (token discipline, fixture lifecycle, audit
 * payload hashing, override application, manual/stale workflows, migration
 * analysis, review state machines, execution orchestration) runs
 * unmodified; only raw storage and the evidence stream are replaced with
 * deterministic in-memory fakes. The fixture fake additionally implements
 * REAL transactional semantics (snapshot on START TRANSACTION, restore on
 * ROLLBACK — including linked stores such as the assignment repository, so
 * rollback proofs cover every table one production transaction would) and
 * REAL unique-active-identity enforcement at insert time, mirroring the
 * database UNIQUE indexes. Failure-injection subclasses simulate JSON
 * encoding failures, audit-insert failures, and the check-then-insert race
 * window. Not a test suite — required by the PR-F *Test.php files.
 */

declare(strict_types=1);

use TMWSEO\Engine\Keywords\KeywordAssignmentRepository;
use TMWSEO\Engine\Keywords\KeywordAssignmentValidationFixtureRepository;

require_once __DIR__ . '/review-test-doubles.php';
require_once __DIR__ . '/../../includes/keywords/class-keyword-assignment-validation-fixture-repository.php';
require_once __DIR__ . '/../../includes/keywords/class-keyword-assignment-validation-service.php';

if ( ! class_exists( 'ValidationFakeFixtureRepository' ) ) {

/**
 * In-memory fixture repository: real lifecycle/audit rules, fake storage
 * with true transaction and unique-index semantics.
 */
class ValidationFakeFixtureRepository extends KeywordAssignmentValidationFixtureRepository {
    /** @var array<int,array<string,mixed>> rows as persisted (JSON strings) */
    public array $rows = [];
    /** @var array<int,array<string,mixed>> APPEND-ONLY audit rows */
    public array $audit_rows = [];
    public int $next_id = 1;
    public int $next_audit_id = 1;
    /** Every transaction verb issued through this repository. */
    public array $transactions = [];
    /** Simulated audit-table insert failure (rollback proofs). */
    public bool $fail_audit_insert = false;
    /**
     * Stores participating in the SAME transaction as this repository (as
     * one production $wpdb transaction would): objects exposing a public
     * $rows array, e.g. the fake assignment repository.
     *
     * @var array<int,object>
     */
    public array $linked_stores = [];
    /** @var array<string,mixed>|null */
    private ?array $transaction_snapshot = null;

    public function table_exists(): bool { return true; }
    public function tables_exist(): bool { return true; }

    public function transaction( string $command ): bool {
        $this->transactions[] = $command;
        if ( 'START TRANSACTION' === $command ) {
            $linked = [];
            foreach ( $this->linked_stores as $index => $store ) {
                $linked[ $index ] = [
                    'rows'       => $store->rows,
                    'audit_rows' => property_exists( $store, 'audit_rows' ) ? $store->audit_rows : null,
                ];
            }
            $this->transaction_snapshot = [
                'rows'          => $this->rows,
                'audit_rows'    => $this->audit_rows,
                'next_id'       => $this->next_id,
                'next_audit_id' => $this->next_audit_id,
                'linked'        => $linked,
            ];
        } elseif ( 'ROLLBACK' === $command ) {
            if ( null !== $this->transaction_snapshot ) {
                $this->rows          = $this->transaction_snapshot['rows'];
                $this->audit_rows    = $this->transaction_snapshot['audit_rows'];
                $this->next_id       = $this->transaction_snapshot['next_id'];
                $this->next_audit_id = $this->transaction_snapshot['next_audit_id'];
                foreach ( $this->linked_stores as $index => $store ) {
                    $store->rows = $this->transaction_snapshot['linked'][ $index ]['rows'];
                    if ( null !== $this->transaction_snapshot['linked'][ $index ]['audit_rows'] ) {
                        $store->audit_rows = $this->transaction_snapshot['linked'][ $index ]['audit_rows'];
                    }
                }
                $this->transaction_snapshot = null;
            }
        } elseif ( 'COMMIT' === $command ) {
            $this->transaction_snapshot = null;
        }
        return true;
    }

    protected function insert_row( array $row ): int {
        // Mirror the DATABASE UNIQUE indexes: an active token or scope
        // identity can exist at most once, regardless of any pre-check.
        foreach ( $this->rows as $existing ) {
            foreach ( [ 'active_token_key', 'active_scope_key' ] as $key ) {
                if ( null !== ( $row[ $key ] ?? null ) && null !== ( $existing[ $key ] ?? null )
                    && (string) $row[ $key ] === (string) $existing[ $key ] ) {
                    return 0; // duplicate-key violation
                }
            }
        }
        $row['id'] = $this->next_id++;
        $this->rows[ (int) $row['id'] ] = $row;
        return (int) $row['id'];
    }

    protected function update_row( int $fixture_id, array $fields ): bool {
        if ( ! isset( $this->rows[ $fixture_id ] ) ) { return false; }
        $this->rows[ $fixture_id ] = array_merge( $this->rows[ $fixture_id ], $fields );
        return true;
    }

    protected function insert_audit_row( array $row ): bool {
        if ( $this->fail_audit_insert ) { return false; }
        $row['id'] = $this->next_audit_id++;
        $this->audit_rows[ (int) $row['id'] ] = $row;
        return true;
    }

    public function find_by_id( int $fixture_id ): ?array {
        return isset( $this->rows[ $fixture_id ] ) ? $this->decode( $this->rows[ $fixture_id ] ) : null;
    }

    public function find_latest_by_token( string $token, string $fixture_type = '' ): ?array {
        $token = $this->normalize_token( $token );
        if ( '' === $token ) { return null; }
        $latest = null;
        foreach ( $this->rows as $row ) {
            if ( (string) $row['validation_token'] !== $token ) { continue; }
            if ( '' !== $fixture_type && (string) $row['fixture_type'] !== $fixture_type ) { continue; }
            if ( null === $latest || (int) $row['id'] > (int) $latest['id'] ) { $latest = $row; }
        }
        return null === $latest ? null : $this->decode( $latest );
    }

    public function list_active( string $fixture_type = '', int $candidate_id = 0 ): array {
        $found = [];
        foreach ( $this->rows as $row ) {
            if ( 'active' !== (string) $row['state'] ) { continue; }
            if ( '' !== $fixture_type && (string) $row['fixture_type'] !== $fixture_type ) { continue; }
            if ( $candidate_id > 0 && (int) $row['keyword_candidate_id'] !== $candidate_id ) { continue; }
            $found[] = $this->decode( $row );
        }
        usort( $found, fn ( $a, $b ) => (int) $a['id'] <=> (int) $b['id'] );
        return $found;
    }

    public function state_counts(): array {
        $counts = [];
        foreach ( $this->rows as $row ) {
            $key = (string) $row['fixture_type'] . '/' . (string) $row['state'];
            $counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
        }
        ksort( $counts );
        return $counts;
    }

    public function audit_for_fixture( int $fixture_id ): array {
        $found = array_values( array_filter( $this->audit_rows, fn ( $row ) => (int) $row['fixture_id'] === $fixture_id ) );
        usort( $found, fn ( $a, $b ) => (int) $a['id'] <=> (int) $b['id'] );
        return $found;
    }

    /** @return array<int,string> ordered audit actions for one fixture */
    public function audit_actions( int $fixture_id ): array {
        return array_map( fn ( $row ) => (string) $row['action'], $this->audit_for_fixture( $fixture_id ) );
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decode( array $row ): array {
        foreach ( [ 'original_values', 'override_values' ] as $field ) {
            $value = $row[ $field ] ?? '';
            if ( is_array( $value ) ) { continue; }
            $decoded = json_decode( (string) $value, true );
            $row[ $field ] = is_array( $decoded ) ? $decoded : [];
        }
        return $row;
    }
}

/**
 * Simulates the check-then-insert race window: with $blind_precheck the
 * SELECT-level pre-checks see nothing, so only the unique-index enforcement
 * in insert_row can (and must) refuse the duplicate.
 */
final class RaceWindowFixtureRepository extends ValidationFakeFixtureRepository {
    public bool $blind_precheck = false;

    public function find_latest_by_token( string $token, string $fixture_type = '' ): ?array {
        return $this->blind_precheck ? null : parent::find_latest_by_token( $token, $fixture_type );
    }

    public function list_active( string $fixture_type = '', int $candidate_id = 0 ): array {
        if ( $this->blind_precheck && ( '' !== $fixture_type || $candidate_id > 0 ) ) { return []; }
        return parent::list_active( $fixture_type, $candidate_id );
    }
}

/**
 * Simulates a JSON encoding failure at the exact production seam
 * (encode_values). 'values' fails fixture value encoding; 'audit' fails the
 * audit payload-snapshot encoding (audit snapshots always carry 'action').
 */
final class ValidationEncodeFailingFixtureRepository extends ValidationFakeFixtureRepository {
    /** '' | 'values' | 'audit' */
    public string $fail_encode_on = '';

    protected function encode_values( $values ): ?string {
        $values = (array) $values;
        $is_audit_snapshot = array_key_exists( 'action', $values );
        if ( 'values' === $this->fail_encode_on && ! $is_audit_snapshot ) { return null; }
        if ( 'audit' === $this->fail_encode_on && $is_audit_snapshot ) { return null; }
        return parent::encode_values( $values );
    }
}

/** Exposes the real repository's protected encoder for direct unit checks. */
final class ExposedEncodeFixtureRepository extends KeywordAssignmentValidationFixtureRepository {
    /** @param mixed $values */
    public function encode( $values ): ?string {
        return $this->encode_values( $values );
    }
}

/**
 * In-memory assignment repository for the validation workflow: identical to
 * ReviewFakeAssignmentRepository (which is final) plus the read methods the
 * validation service uses (find_by_id, find_assignment). Real validation and
 * identity logic runs unmodified.
 */
final class ValidationFakeAssignmentRepository extends KeywordAssignmentRepository {
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];
    public int $next_id = 1;
    /** Any candidate-row mutation attempted by this workflow is recorded. */
    public array $candidate_writes = [];

    public function table_exists(): bool { return true; }

    public function find_by_id( int $assignment_id ): ?array {
        return $this->rows[ $assignment_id ] ?? null;
    }

    public function find_assignment( int $candidate_id, array $identity ): ?array {
        if ( $candidate_id <= 0 ) { return null; }
        $normalized = $this->normalize_assignment( array_merge( $identity, [ 'keyword_candidate_id' => $candidate_id ] ) );
        if ( isset( $normalized['error'] ) ) { return null; }
        foreach ( $this->rows as $row ) {
            if ( (string) $row['assignment_key'] === (string) $normalized['assignment_key'] ) { return $row; }
        }
        return null;
    }

    public function find_assignments_for_candidate( int $candidate_id ): array {
        $found = array_values( array_filter( $this->rows, fn ( $row ) => (int) $row['keyword_candidate_id'] === $candidate_id ) );
        usort( $found, fn ( $a, $b ) => (int) $a['id'] <=> (int) $b['id'] );
        return $found;
    }

    public function find_primary_owner( int $candidate_id ): ?array {
        foreach ( $this->find_assignments_for_candidate( $candidate_id ) as $row ) {
            if ( 'primary' === $row['role'] && 1 === (int) $row['canonical_owner'] && in_array( $row['status'], self::ACTIVE_STATUSES, true ) ) { return $row; }
        }
        return null;
    }

    public function create_assignment( array $data ): array {
        $normalized = $this->normalize_assignment( $data );
        if ( isset( $normalized['error'] ) ) { return [ 'ok' => false, 'error' => (string) $normalized['error'] ]; }
        if ( 'primary' === $normalized['role'] && 1 === (int) $normalized['canonical_owner']
            && in_array( $normalized['status'], self::ACTIVE_STATUSES, true )
            && null !== $this->find_primary_owner( (int) $normalized['keyword_candidate_id'] ) ) {
            return [ 'ok' => false, 'error' => 'active_primary_owner_already_exists' ];
        }
        foreach ( $this->rows as $row ) {
            if ( $row['assignment_key'] === $normalized['assignment_key'] ) {
                return [ 'ok' => false, 'error' => 'assignment_identity_exists', 'id' => (int) $row['id'] ];
            }
        }
        $normalized['id'] = $this->next_id++;
        $normalized['created_at'] = '2026-07-27 12:00:00';
        $normalized['updated_at'] = '2026-07-27 12:00:00';
        $this->rows[ (int) $normalized['id'] ] = $normalized;
        return [ 'ok' => true, 'id' => (int) $normalized['id'] ];
    }

    public function upsert_assignment( array $data ): array {
        $normalized = $this->normalize_assignment( $data );
        if ( isset( $normalized['error'] ) ) { return [ 'ok' => false, 'error' => (string) $normalized['error'] ]; }
        foreach ( $this->rows as $id => $row ) {
            if ( $row['assignment_key'] === $normalized['assignment_key'] ) {
                foreach ( [ 'target_name', 'target_slug', 'status', 'shared_secondary_allowed', 'conflict_reason', 'approval_reason', 'source_batch_id', 'source_import_row_id', 'source_type', 'source_reference', 'active_in_rank_math', 'present_in_content', 'last_verified_at' ] as $field ) {
                    if ( array_key_exists( $field, $data ) ) { $this->rows[ $id ][ $field ] = $normalized[ $field ]; }
                }
                $this->rows[ $id ]['updated_at'] = '2026-07-27 12:30:00';
                return [ 'ok' => true, 'id' => $id, 'action' => 'updated' ];
            }
        }
        $created = $this->create_assignment( $data );
        return ! empty( $created['ok'] ) ? [ 'ok' => true, 'id' => (int) $created['id'], 'action' => 'created' ] : $created;
    }

    public function set_primary_owner( int $assignment_id ): bool {
        if ( ! isset( $this->rows[ $assignment_id ] ) ) { return false; }
        $candidate_id = (int) $this->rows[ $assignment_id ]['keyword_candidate_id'];
        foreach ( $this->rows as $id => $row ) {
            if ( $id !== $assignment_id && (int) $row['keyword_candidate_id'] === $candidate_id
                && 'primary' === $row['role'] && 1 === (int) $row['canonical_owner']
                && in_array( $row['status'], self::ACTIVE_STATUSES, true ) ) {
                return false;
            }
        }
        $this->rows[ $assignment_id ]['role'] = 'primary';
        $this->rows[ $assignment_id ]['canonical_owner'] = 1;
        return true;
    }

    public function clear_primary_owner( int $assignment_id ): bool {
        if ( ! isset( $this->rows[ $assignment_id ] ) ) { return false; }
        $this->rows[ $assignment_id ]['role'] = 'secondary';
        $this->rows[ $assignment_id ]['canonical_owner'] = 0;
        return true;
    }

    public function delete_assignment( int $assignment_id ): bool {
        if ( ! isset( $this->rows[ $assignment_id ] ) ) { return false; }
        unset( $this->rows[ $assignment_id ] );
        return true;
    }
}

}
