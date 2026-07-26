<?php
/**
 * PR-E — shared test doubles for the keyword-assignment review workflow.
 *
 * All real domain logic (identity, snapshot hashing, state machines, audit
 * writing, sync/execution orchestration) runs unmodified; only raw storage
 * and the evidence stream are replaced with deterministic in-memory fakes.
 * Not a test suite — required by the PR-E *Test.php files.
 */

declare(strict_types=1);

use TMWSEO\Engine\Keywords\KeywordAssignmentRepository;
use TMWSEO\Engine\Keywords\KeywordAssignmentReviewRepository;
use TMWSEO\Engine\Keywords\KeywordOwnershipReportService;

require_once __DIR__ . '/../../includes/keywords/class-keyword-pool-candidate-repository.php';
require_once __DIR__ . '/../../includes/keywords/class-keyword-ownership-report-service.php';
require_once __DIR__ . '/../../includes/keywords/class-keyword-assignment-repository.php';
require_once __DIR__ . '/../../includes/keywords/class-keyword-assignment-migration-analyzer.php';
require_once __DIR__ . '/../../includes/keywords/class-keyword-assignment-migration-service.php';
require_once __DIR__ . '/../../includes/keywords/class-keyword-assignment-review-repository.php';
require_once __DIR__ . '/../../includes/keywords/class-keyword-assignment-review-sync-service.php';
require_once __DIR__ . '/../../includes/keywords/class-keyword-assignment-review-execution-service.php';
require_once __DIR__ . '/../../includes/keywords/class-keyword-assignment-review-export-service.php';

if ( ! class_exists( 'ReviewFixtureEvidence' ) ) {

/** Fixture evidence source: replays ownership-report rows + summary. */
final class ReviewFixtureEvidence extends KeywordOwnershipReportService {
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];
    public function __construct() {}
    public function run( array $filters = [] ): \Generator {
        $emitted = 0;
        foreach ( $this->rows as $row ) {
            if ( (int) ( $filters['candidate_id'] ?? 0 ) > 0 && (int) $row['candidate_id'] !== (int) $filters['candidate_id'] ) { continue; }
            if ( '' !== (string) ( $filters['keyword'] ?? '' ) && (string) $row['normalized_keyword'] !== (string) $filters['keyword'] ) { continue; }
            if ( (int) ( $filters['limit'] ?? 0 ) > 0 && $emitted >= (int) $filters['limit'] ) { return; }
            $emitted++;
            yield $row;
        }
    }
    public function summary(): array {
        $approved = count( array_filter( $this->rows, fn ( $row ) => 'approved' === (string) ( $row['status'] ?? '' ) ) );
        return [ 'total_candidate_identities' => count( $this->rows ), 'approved_candidates' => $approved, 'duplicate_import_rows_same_batch' => 0, 'duplicate_import_rows_cross_batch' => 0 ];
    }
}

/** In-memory assignment repository: real validation/identity, fake storage. */
final class ReviewFakeAssignmentRepository extends KeywordAssignmentRepository {
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];
    public int $next_id = 1;
    public int $fail_after_inserts = PHP_INT_MAX;
    private int $insert_count = 0;
    /** @var array<int,string> */
    public array $candidate_writes = [];

    public function table_exists(): bool { return true; }

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
        if ( 'primary' === $normalized['role'] && in_array( $normalized['status'], self::ACTIVE_STATUSES, true )
            && null !== $this->find_primary_owner( (int) $normalized['keyword_candidate_id'] ) ) {
            return [ 'ok' => false, 'error' => 'active_primary_owner_already_exists' ];
        }
        foreach ( $this->rows as $row ) {
            if ( $row['assignment_key'] === $normalized['assignment_key'] ) {
                return [ 'ok' => false, 'error' => 'assignment_identity_exists', 'id' => (int) $row['id'] ];
            }
        }
        if ( ++$this->insert_count > $this->fail_after_inserts ) {
            return [ 'ok' => false, 'error' => 'simulated_interruption' ];
        }
        $normalized['id'] = $this->next_id++;
        $normalized['created_at'] = '2026-07-26 12:00:00';
        $normalized['updated_at'] = '2026-07-26 12:00:00';
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
                $this->rows[ $id ]['updated_at'] = '2026-07-26 12:30:00';
                return [ 'ok' => true, 'id' => $id, 'action' => 'updated' ];
            }
        }
        $created = $this->create_assignment( $data );
        return ! empty( $created['ok'] ) ? [ 'ok' => true, 'id' => (int) $created['id'], 'action' => 'created' ] : $created;
    }

    public function find_assignments_by_source( array $source_types, string $source_reference = '' ): array {
        $found = array_values( array_filter( $this->rows, function ( $row ) use ( $source_types, $source_reference ) {
            return in_array( (string) ( $row['source_type'] ?? '' ), $source_types, true )
                && ( '' === $source_reference || (string) ( $row['source_reference'] ?? '' ) === $source_reference );
        } ) );
        usort( $found, fn ( $a, $b ) => (int) $a['id'] <=> (int) $b['id'] );
        return $found;
    }

    public function delete_assignment( int $assignment_id ): bool {
        if ( ! isset( $this->rows[ $assignment_id ] ) ) { return false; }
        unset( $this->rows[ $assignment_id ] );
        return true;
    }
}

/** In-memory review repository: real state machines, fake storage. */
final class ReviewFakeRepository extends KeywordAssignmentReviewRepository {
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];
    /** @var array<int,array<string,mixed>> */
    public array $audit_rows = [];
    public int $next_id = 1;
    public int $clock = 0;

    public function tables_exist(): bool { return true; }

    public function find_by_id( int $review_id ): ?array {
        return $this->rows[ $review_id ] ?? null;
    }

    public function find_by_review_key( string $review_key ): ?array {
        foreach ( $this->rows as $row ) {
            if ( (string) $row['review_key'] === $review_key ) { return $row; }
        }
        return null;
    }

    public function list_reviews( array $filters = [], int $limit = 0, int $offset = 0 ): array {
        $matched = array_values( array_filter( $this->rows, fn ( $row ) => $this->matches( $row, $filters ) ) );
        usort( $matched, fn ( $a, $b ) =>
            [ (int) $a['keyword_candidate_id'], (string) $a['pool'], (string) $a['target_type'], (int) $a['target_id'], (string) $a['target_key'], (int) $a['id'] ]
            <=> [ (int) $b['keyword_candidate_id'], (string) $b['pool'], (string) $b['target_type'], (int) $b['target_id'], (string) $b['target_key'], (int) $b['id'] ] );
        if ( $limit > 0 ) { $matched = array_slice( $matched, max( 0, $offset ), $limit ); }
        return $matched;
    }

    public function count_reviews( array $filters = [] ): int {
        return count( array_filter( $this->rows, fn ( $row ) => $this->matches( $row, $filters ) ) );
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $filters */
    private function matches( array $row, array $filters ): bool {
        foreach ( $filters as $column => $value ) {
            if ( ! in_array( (string) $column, self::FILTERABLE_COLUMNS, true ) ) { continue; }
            if ( is_array( $value ) ) {
                if ( ! in_array( (string) $row[ $column ], array_map( 'strval', $value ), true ) ) { return false; }
                continue;
            }
            if ( (string) $row[ $column ] !== (string) $value ) { return false; }
        }
        return true;
    }

    protected function insert_row( array $row ): int {
        $row['id'] = $this->next_id++;
        $this->rows[ (int) $row['id'] ] = $row;
        return (int) $row['id'];
    }

    protected function update_row( int $review_id, array $fields ): bool {
        if ( ! isset( $this->rows[ $review_id ] ) ) { return false; }
        foreach ( $fields as $field => $value ) { $this->rows[ $review_id ][ $field ] = $value; }
        return true;
    }

    protected function insert_audit_row( array $row ): bool {
        $row['id'] = count( $this->audit_rows ) + 1;
        $this->audit_rows[] = $row;
        return true;
    }

    public function audit_for_review( int $review_id ): array {
        return array_values( array_filter( $this->audit_rows, fn ( $row ) => (int) $row['review_id'] === $review_id ) );
    }

    protected function now(): string {
        // Monotonic fake clock so ordering assertions are deterministic.
        return '2026-07-26 12:' . str_pad( (string) ( $this->clock++ % 60 ), 2, '0', STR_PAD_LEFT ) . ':00';
    }
}

}
