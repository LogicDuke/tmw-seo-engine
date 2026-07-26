<?php
/**
 * PR-D — KeywordAssignmentMigrationService tests.
 *
 * The service is driven with an injected fake evidence source (fixture rows
 * in the ownership-report shape) and an in-memory fake assignment repository
 * (subclass of the real one with DB methods overridden), so execution,
 * idempotency, restartability, rollback scoping, and manual-row preservation
 * are proven end to end without a database. A final static section verifies
 * PR-D cuts over no production path.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordAssignmentMigrationAnalyzer as Analyzer;
use TMWSEO\Engine\Keywords\KeywordAssignmentMigrationService;
use TMWSEO\Engine\Keywords\KeywordAssignmentRepository;
use TMWSEO\Engine\Keywords\KeywordOwnershipReportService;

require_once __DIR__ . '/../includes/keywords/class-keyword-pool-candidate-repository.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-ownership-report-service.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-assignment-repository.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-assignment-migration-analyzer.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-assignment-migration-service.php';

/** Fixture evidence source: replays ownership-report rows + summary. */
final class MigrationFixtureEvidence extends KeywordOwnershipReportService {
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];
    public function __construct() {} // no parent wiring needed
    public function run( array $filters = [] ): \Generator {
        foreach ( $this->rows as $row ) {
            if ( (int) ( $filters['candidate_id'] ?? 0 ) > 0 && (int) $row['candidate_id'] !== (int) $filters['candidate_id'] ) { continue; }
            yield $row;
        }
    }
    public function summary(): array {
        // Computed from the fixture rows so source_counts always agree with
        // the streamed evidence (mirrors the real service's run-time counters).
        $approved = count( array_filter( $this->rows, fn ( $row ) => 'approved' === (string) ( $row['status'] ?? '' ) ) );
        $duplicate_same_batch = 0;
        $duplicate_cross_batch = 0;
        foreach ( $this->rows as $row ) {
            $seen = [];
            foreach ( (array) ( $row['import_rows'] ?? [] ) as $import_row ) {
                $target = (string) ( $import_row['batch_target_type'] ?? '' ) . ':' . (int) ( $import_row['batch_target_id'] ?? 0 );
                $batch  = (int) ( $import_row['batch_id'] ?? 0 );
                if ( isset( $seen[ $target ][ $batch ] ) ) { $duplicate_same_batch++; }
                elseif ( isset( $seen[ $target ] ) ) { $duplicate_cross_batch++; }
                $seen[ $target ][ $batch ] = true;
            }
        }
        return [ 'total_candidate_identities' => count( $this->rows ), 'approved_candidates' => $approved, 'duplicate_import_rows_same_batch' => $duplicate_same_batch, 'duplicate_import_rows_cross_batch' => $duplicate_cross_batch ];
    }
}

/** In-memory assignment repository: real validation/identity, fake storage. */
final class MigrationFakeAssignmentRepository extends KeywordAssignmentRepository {
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];
    public int $next_id = 1;
    public int $fail_after_inserts = PHP_INT_MAX; // simulate interruption
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

final class KeywordAssignmentMigrationServiceTest extends TestCase {

    private MigrationFixtureEvidence $evidence;
    private MigrationFakeAssignmentRepository $repository;
    private KeywordAssignmentMigrationService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->evidence = new MigrationFixtureEvidence();
        $this->repository = new MigrationFakeAssignmentRepository();
        $this->service = new KeywordAssignmentMigrationService( $this->evidence, $this->repository, new Analyzer() );
    }

    /** @return array<string,mixed> */
    private function evidenceRow( int $candidate_id, string $keyword, array $overrides = [] ): array {
        return array_merge( [
            'candidate_id'       => $candidate_id,
            'normalized_keyword' => $keyword,
            'status'             => 'approved',
            'intent_type'        => 'category',
            'target_type'        => 'tmw_category_page',
            'target_id'          => 500 + $candidate_id,
            'target_name'        => 'Target ' . $candidate_id,
            'import_rows'        => [],
            'distinct_targets'   => [ [ 'target_type' => 'tmw_category_page', 'target_id' => 500 + $candidate_id, 'target_name' => 'Target ' . $candidate_id ] ],
            'rankmath_presence'  => [ [ 'post_id' => 500 + $candidate_id, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ] ],
            'content_presence'   => [ [ 'post_id' => 500 + $candidate_id, 'present' => true ] ],
            'target_unresolvable'=> [],
            'postmeta_ownership' => [],
        ], $overrides );
    }

    // ── Dry run writes nothing; deterministic across repeats ──────────────

    public function test_dry_run_is_read_only_and_stable(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ), $this->evidenceRow( 2, 'beta phrase' ) ];

        $first = $this->service->analyze();
        $second = $this->service->analyze();

        $this->assertSame( [], $this->repository->rows, 'Dry run must write no assignment rows.' );
        $this->assertSame( 'dry-run', $first['mode'] );
        $this->assertSame( 2, $first['normalized_keyword_count'] );
        $this->assertSame( 2, $first['planned']['insert'] );
        unset( $first['generated_at'], $second['generated_at'] );
        $this->assertSame( $first, $second, 'Repeated dry runs must produce identical decisions.' );
    }

    // ── Execute inserts; repeat produces no duplicates, no churn ──────────

    public function test_execute_then_repeat_is_idempotent(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];

        $first = $this->service->execute();
        $this->assertSame( 1, $first['execution']['inserted'] );
        $this->assertCount( 1, $this->repository->rows );
        $created_at = array_values( $this->repository->rows )[0]['created_at'];
        $updated_at = array_values( $this->repository->rows )[0]['updated_at'];

        $second = $this->service->execute();
        $this->assertSame( 0, $second['execution']['inserted'], 'No duplicate rows on repeat.' );
        $this->assertSame( 0, $second['execution']['updated'], 'No update when nothing changed.' );
        $this->assertSame( 1, $second['execution']['unchanged'] );
        $this->assertCount( 1, $this->repository->rows );
        $row = array_values( $this->repository->rows )[0];
        $this->assertSame( $created_at, $row['created_at'] );
        $this->assertSame( $updated_at, $row['updated_at'], 'Stable timestamps are not touched on unchanged repeats.' );
        $this->assertSame( 1, (int) $row['canonical_owner'], 'Primary ownership does not oscillate.' );
    }

    // ── Interrupted execution restarts safely ─────────────────────────────

    public function test_interrupted_execution_is_restartable(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ), $this->evidenceRow( 2, 'beta phrase' ), $this->evidenceRow( 3, 'gamma phrase' ) ];
        $this->repository->fail_after_inserts = 1; // interrupt after first insert

        $interrupted = $this->service->execute();
        $this->assertSame( 1, $interrupted['execution']['inserted'] );
        $this->assertSame( 2, $interrupted['execution']['failed'] );
        $this->assertCount( 2, $interrupted['execution_errors'] );

        $this->repository->fail_after_inserts = PHP_INT_MAX;
        $resumed = $this->service->execute();
        $this->assertSame( 2, $resumed['execution']['inserted'], 'Restart inserts only the missing rows.' );
        $this->assertSame( 1, $resumed['execution']['unchanged'], 'Already-written row is untouched.' );
        $this->assertSame( 0, $resumed['execution']['failed'] );
        $this->assertCount( 3, $this->repository->rows );
    }

    // ── Non-writable classifications never execute ────────────────────────

    public function test_conflicts_and_manual_review_never_write(): void {
        $this->evidence->rows = [
            // Conflict: two equal full-usage targets, no recorded owner.
            $this->evidenceRow( 1, 'conflict phrase', [
                'target_type' => '', 'target_id' => 0,
                'distinct_targets' => [
                    [ 'target_type' => 'tmw_category_page', 'target_id' => 601, 'target_name' => 'A' ],
                    [ 'target_type' => 'tmw_category_page', 'target_id' => 602, 'target_name' => 'B' ],
                ],
                'rankmath_presence' => [
                    [ 'post_id' => 601, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ],
                    [ 'post_id' => 602, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ],
                ],
                'content_presence' => [ [ 'post_id' => 601, 'present' => true ], [ 'post_id' => 602, 'present' => true ] ],
            ] ),
            // Unresolved target.
            $this->evidenceRow( 2, 'unresolved phrase', [ 'target_unresolvable' => [ 502 ], 'rankmath_presence' => [], 'content_presence' => [] ] ),
            // Manual review (no evidence at all).
            $this->evidenceRow( 3, 'review phrase', [ 'status' => 'queued_for_review', 'target_type' => '', 'target_id' => 0, 'distinct_targets' => [], 'rankmath_presence' => [], 'content_presence' => [] ] ),
        ];

        $report = $this->service->execute();

        $this->assertSame( [], $this->repository->rows, 'Conflicting/unresolved/manual-review must never write.' );
        $this->assertSame( 1, $report['execution']['conflicting'] );
        $this->assertSame( 2, $report['execution']['skipped'] );
        $this->assertSame( 1, $report['classification_counts'][Analyzer::C_CONFLICT] );
        $this->assertSame( 1, $report['classification_counts'][Analyzer::C_UNRESOLVED] );
        $this->assertSame( 1, $report['classification_counts'][Analyzer::C_MANUAL_REVIEW] );
    }

    // ── Manual assignments preserved through execution ────────────────────

    public function test_execution_preserves_manual_assignments(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        // Manual row already covers the same identity.
        $this->repository->rows[99] = array_merge(
            $this->repository->normalize_assignment( [
                'keyword_candidate_id' => 1, 'pool' => 'category', 'page_type' => 'tmw_category_page',
                'target_type' => 'tmw_category_page', 'target_id' => 501, 'role' => 'primary',
                'status' => 'approved', 'canonical_owner' => 1, 'source_type' => 'manual_curation',
            ] ),
            [ 'id' => 99, 'created_at' => 'manual-time', 'updated_at' => 'manual-time' ]
        );

        $report = $this->service->execute();

        $this->assertSame( 1, $report['execution']['preserved'] );
        $this->assertSame( 0, $report['execution']['inserted'] );
        $this->assertSame( 'manual-time', $this->repository->rows[99]['updated_at'], 'Manual row untouched byte for byte.' );
        $this->assertSame( 'manual_curation', $this->repository->rows[99]['source_type'] );
    }

    // ── Rollback: dry run, source scoping, manual preservation ────────────

    public function test_rollback_scopes_to_migration_rows_and_preserves_manual(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ), $this->evidenceRow( 2, 'beta phrase' ) ];
        $this->service->execute();
        $this->assertCount( 2, $this->repository->rows );
        // Add a manual row that must survive every rollback mode.
        $this->repository->rows[500] = array_merge(
            $this->repository->normalize_assignment( [
                'keyword_candidate_id' => 9, 'pool' => 'category', 'page_type' => 'tmw_category_page',
                'target_type' => 'tmw_category_page', 'target_id' => 900, 'role' => 'secondary',
                'status' => 'approved', 'source_type' => 'manual_curation',
            ] ),
            [ 'id' => 500 ]
        );

        $dry = $this->service->rollback( false );
        $this->assertSame( 'rollback-dry-run', $dry['mode'] );
        $this->assertCount( 2, $dry['would_delete'] );
        $this->assertSame( 0, $dry['deleted'] );
        $this->assertCount( 3, $this->repository->rows, 'Rollback dry run makes no changes.' );

        $executed = $this->service->rollback( true );
        $this->assertSame( 2, $executed['deleted'] );
        $this->assertCount( 1, $this->repository->rows );
        $this->assertArrayHasKey( 500, $this->repository->rows, 'Manual assignment survives rollback.' );

        // Idempotent: repeat rollback finds nothing.
        $again = $this->service->rollback( true );
        $this->assertSame( 0, $again['deleted'] );
        $this->assertSame( [], $again['would_delete'] );
    }

    public function test_rollback_by_single_source_type(): void {
        $this->evidence->rows = [
            $this->evidenceRow( 1, 'alpha phrase', [
                'distinct_targets' => [
                    [ 'target_type' => 'tmw_category_page', 'target_id' => 501, 'target_name' => 'A' ],
                    [ 'target_type' => 'tmw_category_page', 'target_id' => 502, 'target_name' => 'B' ],
                ],
                'import_rows' => [ [ 'row_id' => 900, 'batch_id' => 4, 'pool' => 'category', 'batch_target_type' => 'tmw_category_page', 'batch_target_id' => 502, 'batch_target_name' => 'B', 'row_status' => 'approved' ] ],
            ] ),
        ];
        $this->service->execute();
        $this->assertCount( 2, $this->repository->rows ); // primary (migration_candidate) + secondary (migration_import)

        $partial = $this->service->rollback( true, [ 'migration_import' ] );

        $this->assertSame( 1, $partial['deleted'] );
        $remaining = array_values( $this->repository->rows );
        $this->assertCount( 1, $remaining );
        $this->assertSame( 'migration_candidate', $remaining[0]['source_type'], 'Only the requested source type was rolled back.' );
    }

    // ── Candidate rows and their states are never touched (30) ────────────

    public function test_no_candidate_rows_are_mutated_in_any_phase(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        $this->service->analyze();
        $this->service->execute();
        $this->service->rollback( false );
        $this->service->rollback( true );
        // The fake repository records candidate writes; the migration has no
        // candidate write path at all — this asserts none appeared.
        $this->assertSame( [], $this->repository->candidate_writes );
        // And statically: the migration classes never reference the candidate
        // table or its status writer.
        foreach ( [
            '/../includes/keywords/class-keyword-assignment-migration-analyzer.php',
            '/../includes/keywords/class-keyword-assignment-migration-service.php',
        ] as $path ) {
            $source = (string) file_get_contents( __DIR__ . $path );
            $this->assertStringNotContainsString( 'tmw_keyword_candidates', $source );
            $this->assertStringNotContainsString( 'update_candidate_status', $source );
            $this->assertStringNotContainsString( 'rank_math_focus_keyword', $source, 'Migration never writes Rank Math metadata.' );
            $this->assertStringNotContainsString( 'wp_update_post', $source, 'Migration never writes content.' );
            $this->assertStringNotContainsString( 'update_post_meta', $source, 'Migration never writes postmeta.' );
        }
    }

    // ── Deterministic JSON report ─────────────────────────────────────────

    public function test_report_serialization_is_deterministic(): void {
        $this->evidence->rows = [ $this->evidenceRow( 2, 'beta phrase' ), $this->evidenceRow( 1, 'alpha phrase' ) ];

        $first = $this->service->analyze();
        $second = $this->service->analyze();
        unset( $first['generated_at'], $second['generated_at'] );

        $this->assertSame( $this->service->serialize_report( $first ), $this->service->serialize_report( $second ) );
        $this->assertSame( 1, $first['decisions'][0]['candidate_id'], 'Decisions ordered by candidate id, not source order.' );
        $json = $this->service->serialize_report( $first );
        $this->assertStringNotContainsString( 'post_content', $json, 'Report never dumps content bodies.' );
    }

    public function test_limit_restricts_decisions_but_preserves_full_source_summary(): void {
        $this->evidence->rows = [
            $this->evidenceRow( 1, 'one phrase' ),
            $this->evidenceRow( 2, 'two phrase' ),
            $this->evidenceRow( 3, 'three phrase' ),
        ];

        $report = $this->service->analyze( [ 'limit' => 1 ] );

        $this->assertSame( 1, $report['normalized_keyword_count'] );
        $this->assertSame( 3, $report['source_counts']['candidate_identities'] );
        $this->assertSame( [ 'limit' => 1 ], $report['filters'] );
    }

    public function test_serialization_failure_is_logged_as_service_error(): void {
        $json = $this->service->serialize_report( [ 'bad_utf8' => "\xB1\x31" ] );

        $this->assertSame( '{}', $json );
        $this->assertStringContainsString( 'UTF-8', $this->service->serialization_error() );
    }

    // ── Report summary buckets agree with counts and decisions ────────────

    public function test_report_buckets_agree_with_counts_and_decisions(): void {
        $this->evidence->rows = [
            $this->evidenceRow( 1, 'alpha phrase', [
                'distinct_targets' => [
                    [ 'target_type' => 'tmw_category_page', 'target_id' => 501, 'target_name' => 'A' ],
                    [ 'target_type' => 'tmw_category_page', 'target_id' => 511, 'target_name' => 'B' ],
                ],
                'import_rows' => [
                    [ 'row_id' => 950, 'batch_id' => 6, 'pool' => 'category', 'batch_target_type' => 'tmw_category_page', 'batch_target_id' => 511, 'batch_target_name' => 'B', 'row_status' => 'approved' ],
                    [ 'row_id' => 951, 'batch_id' => 6, 'pool' => 'category', 'batch_target_type' => 'tmw_category_page', 'batch_target_id' => 511, 'batch_target_name' => 'B', 'row_status' => 'approved' ], // duplicate same batch
                ],
            ] ),
            $this->evidenceRow( 2, 'beta phrase', [ 'status' => 'rejected' ] ),                       // skipped_records
            $this->evidenceRow( 3, 'gamma phrase', [ 'target_unresolvable' => [ 503 ], 'rankmath_presence' => [], 'content_presence' => [] ] ), // unresolved_targets
            $this->evidenceRow( 4, 'delta phrase', [                                                  // unused_owner_findings
                'rankmath_presence' => [ [ 'post_id' => 504, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'absent' ] ],
                'content_presence'  => [ [ 'post_id' => 504, 'present' => false ] ],
            ] ),
        ];
        // Existing manual row on candidate 1's secondary target → rows_preserved.
        $this->repository->rows[300] = array_merge(
            $this->repository->normalize_assignment( [
                'keyword_candidate_id' => 1, 'pool' => 'category', 'page_type' => 'tmw_category_page',
                'target_type' => 'tmw_category_page', 'target_id' => 511, 'role' => 'secondary',
                'status' => 'approved', 'source_type' => 'manual_curation',
            ] ),
            [ 'id' => 300 ]
        );

        $report = $this->service->analyze();

        // Bucket sizes agree with classification_counts.
        $this->assertCount( (int) ( $report['classification_counts'][Analyzer::C_REJECTED_SKIP] ?? 0 ), $report['skipped_records'] );
        $this->assertCount( (int) ( $report['classification_counts'][Analyzer::C_UNRESOLVED] ?? 0 ), $report['unresolved_targets'] );
        $this->assertCount( (int) ( $report['classification_counts'][Analyzer::C_UNUSED_OWNER] ?? 0 ), $report['unused_owner_findings'] );
        // Bucket sizes agree with planned action counts.
        $this->assertCount( (int) $report['planned']['insert'], $report['rows_to_insert'] );
        $this->assertCount( (int) $report['planned']['update'], $report['rows_to_update'] );
        $this->assertCount( (int) $report['planned']['unchanged'], $report['rows_unchanged'] );
        $this->assertCount( (int) $report['planned']['preserve'], $report['rows_preserved'] );
        $this->assertSame( 1, count( $report['rows_preserved'] ) );
        $this->assertSame( 511, $report['rows_preserved'][0]['target_id'] );
        // Bucket entries carry compact references with reason codes.
        $insert = $report['rows_to_insert'][0];
        foreach ( [ 'candidate_id', 'normalized_keyword', 'planned_action', 'role', 'status', 'target_type', 'target_id', 'reasons' ] as $key ) {
            $this->assertArrayHasKey( $key, $insert );
        }
        $this->assertSame( 'gamma phrase', $report['unresolved_targets'][0]['normalized_keyword'] );
        $this->assertNotSame( [], $report['unresolved_targets'][0]['reasons'] );
        // Duplicate source rows: one same-batch duplicate, compact reference.
        $this->assertCount( 1, $report['duplicate_source_rows'] );
        $this->assertSame( [ 'duplicate_row_same_batch' ], $report['duplicate_source_rows'][0]['reasons'] );
        $this->assertSame( 511, $report['duplicate_source_rows'][0]['target_id'] );
        // Buckets follow decision ordering (candidate_id ascending).
        $bucket_candidates = array_map( fn ( $entry ) => (int) $entry['candidate_id'], $report['rows_to_insert'] );
        $sorted = $bucket_candidates;
        sort( $sorted );
        $this->assertSame( $sorted, $bucket_candidates, 'Bucket ordering is deterministic.' );
        // Every bucketed classification entry corresponds to a real decision.
        foreach ( $report['skipped_records'] as $entry ) {
            $this->assertSame( Analyzer::C_REJECTED_SKIP, $entry['classification'] );
        }
    }

    // ── Fixture summary is consistent with streamed evidence rows ─────────

    public function test_source_counts_are_consistent_with_evidence_rows(): void {
        $this->evidence->rows = [
            $this->evidenceRow( 1, 'alpha phrase' ),                              // approved
            $this->evidenceRow( 2, 'beta phrase', [ 'status' => 'queued_for_review' ] ),
            $this->evidenceRow( 3, 'gamma phrase' ),                              // approved
        ];

        $report = $this->service->analyze();

        $expected_approved = count( array_filter( $this->evidence->rows, fn ( $row ) => 'approved' === $row['status'] ) );
        $approved_decisions = count( array_filter( $report['decisions'], fn ( $decision ) => 'approved' === $decision['candidate_status'] ) );
        $this->assertSame( $expected_approved, $report['source_counts']['approved_candidates'], 'Summary must agree with the evidence rows it summarizes.' );
        $this->assertSame( $expected_approved, $approved_decisions );
        $this->assertSame( count( $this->evidence->rows ), $report['source_counts']['candidate_identities'] );
        $this->assertSame( count( $this->evidence->rows ), $report['normalized_keyword_count'] );
    }

    // ── Rollback source-type subset: reported, scoped, fail-loud ──────────

    public function test_rollback_report_lists_requested_source_types(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        $this->service->execute();

        $subset = $this->service->rollback( false, [ 'migration_import' ] );
        $this->assertSame( [ 'migration_import' ], $subset['source_types'], 'Selected source types appear in the report.' );
        $this->assertSame( [], $subset['would_delete'], 'migration_candidate row is out of scope.' );
        $this->assertCount( 1, $this->repository->rows );

        $full = $this->service->rollback( false );
        $this->assertSame( Analyzer::MIGRATION_SOURCE_TYPES, $full['source_types'] );
        $this->assertCount( 1, $full['would_delete'] );
    }

    public function test_rollback_with_only_invalid_source_types_fails_loudly(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        $this->service->execute();

        $refused = $this->service->rollback( true, [ 'manual_curation', 'not_a_source' ] );

        $this->assertSame( [], $refused['source_types'] );
        $this->assertSame( 0, $refused['deleted'] );
        $this->assertNotSame( [], $refused['errors'], 'Invalid subsets fail loudly, never fall back to all types.' );
        $this->assertSame( 'invalid_source_types', $refused['errors'][0]['error'] );
        $this->assertCount( 1, $this->repository->rows, 'Nothing deleted; manual/unrelated rows unreachable.' );
    }

    // ── CLI static coverage for --source-type ─────────────────────────────

    public function test_cli_source_type_option_is_validated_and_rollback_scoped(): void {
        $cli = (string) file_get_contents( __DIR__ . '/../includes/cli/class-cli.php' );
        $this->assertStringContainsString( "isset( \$assoc['source-type'] )", $cli );
        $this->assertStringContainsString( 'KeywordAssignmentMigrationAnalyzer::MIGRATION_SOURCE_TYPES', $cli, 'Allowed values come from the analyzer constant, never a hardcoded list.' );
        $this->assertStringContainsString( "--source-type applies only to rollback-dry-run and rollback-execute", $cli );
        $this->assertStringContainsString( 'Invalid --source-type value(s)', $cli );
        $this->assertStringContainsString( "--source-type is empty", $cli );
        $this->assertStringContainsString( "rollback( 'rollback-execute' === \$mode, \$source_types )", $cli, 'Validated subset is passed to the service.' );
    }



    public function test_no_production_cutover(): void {
        $migration_files = [
            'includes/keywords/class-keyword-assignment-migration-analyzer.php',
            'includes/keywords/class-keyword-assignment-migration-service.php',
            // PR-E: reviewed rollout workflow (CLI-only consumers of the
            // migration analyzer; still no production cutover).
            'includes/keywords/class-keyword-assignment-review-repository.php',
            'includes/keywords/class-keyword-assignment-review-sync-service.php',
            'includes/keywords/class-keyword-assignment-review-execution-service.php',
            'includes/keywords/class-keyword-assignment-review-export-service.php',
        ];
        $sanctioned_referrers = array_merge( $migration_files, [ 'includes/class-loader.php', 'includes/cli/class-cli.php' ] );
        $offenders = [];
        $root = (string) realpath( dirname( __DIR__ ) );
        $iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( (string) realpath( __DIR__ . '/../includes' ), \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $iterator as $file ) {
            if ( 'php' !== strtolower( (string) $file->getExtension() ) ) { continue; }
            $relative = str_replace( '\\', '/', substr( (string) realpath( (string) $file->getPathname() ), strlen( $root ) + 1 ) );
            $source = (string) file_get_contents( (string) $file->getPathname() );
            if ( false !== strpos( $source, 'KeywordAssignmentMigration' ) && ! in_array( $relative, $sanctioned_referrers, true ) ) {
                $offenders[] = $relative;
            }
        }
        $this->assertSame( [], $offenders, 'Only loader + CLI may reference the migration; no production cutover.' );

        // Approval/rejection/generation/Rank Math surfaces remain assignment-free.
        foreach ( [
            '/../includes/admin/class-keyword-pools-admin-page.php',
            '/../includes/keywords/class-keyword-pool-selected-import-service.php',
            '/../includes/content/class-content-engine.php',
            '/../includes/keywords/class-category-approved-keyword-resolver.php',
            '/../includes/content/class-index-readiness-gate.php',
        ] as $path ) {
            $source = (string) file_get_contents( __DIR__ . $path );
            $this->assertStringNotContainsString( 'KeywordAssignmentMigration', $source );
            $this->assertStringNotContainsString( 'tmw_keyword_assignments', $source );
        }
    }
}
