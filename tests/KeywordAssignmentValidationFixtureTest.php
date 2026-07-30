<?php
/**
 * PR-F — keyword-assignment production-validation tooling tests (rev 2).
 *
 * Proves, against the REAL migration/review/execution pipeline (only storage
 * and evidence faked):
 *
 * Opt-in override contract — an ACTIVE stale fixture changes NOTHING by
 * itself: ordinary migration analysis, ordinary review sync, and ordinary
 * execute-approved stay byte-identical with fixtures present; only the
 * explicit run-stale-validation action with the exact validation context
 * (token + review ID + candidate ID) applies the override, and any wrong
 * context element applies nothing and fails closed.
 *
 * Manual fixture — dry-run writes nothing; execution creates exactly one
 * SECONDARY assignment with unmistakable fixture source metadata and no
 * ownership; every non-secondary role is rejected; recreation is
 * idempotent; existing manual/migration assignments are never overwritten;
 * the PR-E executor reports the fixture as
 * skipped/manual_assignment_preserved without modifying it; cleanup removes
 * only the matching fixture row and refuses wrong tokens and re-attributed
 * rows; recover-manual-review returns EXACTLY the review skipped by the
 * removed fixture to a re-reviewable state through the existing workflow
 * (skipped -> stale -> sync -> not_executed -> executes normally), is
 * idempotent, and refuses active fixtures, unrelated reviews, tampered
 * reviews, and reoccupied identities.
 *
 * Stale fixture — dry-run writes nothing and reports the exact expected
 * stale reason; a sibling review whose planned record would change REFUSES
 * activation; run-stale-validation marks the approved review stale through
 * the REAL executor with zero assignment writes; restoration is exact,
 * idempotent, and audited; the review recovers ONLY via existing sync
 * rules and then executes normally.
 *
 * Integrity — every fixture lifecycle event lands in the append-only audit
 * trail; a JSON encoding failure or an audit insertion failure aborts and
 * ROLLS BACK the whole transaction (including the assignment write);
 * duplicate/concurrent creates are refused by the unique active-identity
 * enforcement even when every SELECT-level pre-check is blind; candidate
 * evidence rows, snapshot hashes, and review approval state are never
 * mutated by the validation tooling itself.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordAssignmentMigrationAnalyzer as Analyzer;
use TMWSEO\Engine\Keywords\KeywordAssignmentMigrationService;
use TMWSEO\Engine\Keywords\KeywordAssignmentReviewExecutionService;
use TMWSEO\Engine\Keywords\KeywordAssignmentReviewSyncService;
use TMWSEO\Engine\Keywords\KeywordAssignmentValidationFixtureRepository as FixtureRepo;
use TMWSEO\Engine\Keywords\KeywordAssignmentValidationService;

require_once __DIR__ . '/support/validation-fixture-test-doubles.php';

final class KeywordAssignmentValidationFixtureTest extends TestCase {

    private ReviewFixtureEvidence $evidence;
    private ValidationFakeAssignmentRepository $assignments;
    private ReviewFakeRepository $reviews;
    private ValidationFakeFixtureRepository $fixtures;
    private KeywordAssignmentMigrationService $migration;
    private KeywordAssignmentReviewSyncService $sync;
    private KeywordAssignmentReviewExecutionService $executor;
    private KeywordAssignmentValidationService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->evidence    = new ReviewFixtureEvidence();
        $this->assignments = new ValidationFakeAssignmentRepository();
        $this->reviews     = new ReviewFakeRepository();
        $this->fixtures    = new ValidationFakeFixtureRepository();
        $this->fixtures->linked_stores = [ $this->assignments, $this->reviews ]; // one shared transaction, as production $wpdb
        $this->migration   = new KeywordAssignmentMigrationService( $this->evidence, $this->assignments, new Analyzer() );
        $this->sync        = new KeywordAssignmentReviewSyncService( $this->migration, $this->reviews );
        $this->executor    = new KeywordAssignmentReviewExecutionService( $this->migration, $this->reviews, $this->sync, $this->assignments );
        $this->service     = new KeywordAssignmentValidationService( $this->fixtures, $this->assignments, $this->reviews, $this->migration, $this->sync, $this->executor );
    }

    /**
     * One candidate with a clear primary target (content present, Rank Math
     * primary) and a weaker secondary target (Rank Math extra, no content) —
     * the shape both production validation fixtures act on.
     *
     * @return array<string,mixed>
     */
    private function evidenceRow( int $candidate_id, string $keyword ): array {
        $primary_page   = 500 + $candidate_id;
        $secondary_page = 600 + $candidate_id;
        return [
            'candidate_id'       => $candidate_id,
            'normalized_keyword' => $keyword,
            'status'             => 'approved',
            'intent_type'        => 'category',
            'target_type'        => 'tmw_category_page',
            'target_id'          => $primary_page,
            'target_name'        => 'Primary ' . $candidate_id,
            'import_rows'        => [],
            'distinct_targets'   => [
                [ 'target_type' => 'tmw_category_page', 'target_id' => $primary_page, 'target_name' => 'Primary ' . $candidate_id ],
                [ 'target_type' => 'tmw_category_page', 'target_id' => $secondary_page, 'target_name' => 'Secondary ' . $candidate_id ],
            ],
            'rankmath_presence'  => [
                [ 'post_id' => $primary_page, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ],
                [ 'post_id' => $secondary_page, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'extra' ],
            ],
            'content_presence'   => [ [ 'post_id' => $primary_page, 'present' => true ] ],
            'target_unresolvable'=> [],
            'postmeta_ownership' => [],
        ];
    }

    /**
     * A candidate whose target page is shared by TWO pools ('category' via
     * the intent relationship and 'video' via import history), so flipping
     * that page's content presence changes BOTH secondary planned records —
     * the shape the sibling-change refusal must catch.
     *
     * @return array<string,mixed>
     */
    private function sharedPageEvidenceRow( int $candidate_id, string $keyword ): array {
        $primary_page = 500 + $candidate_id;
        $shared_page  = 600 + $candidate_id;
        return [
            'candidate_id'       => $candidate_id,
            'normalized_keyword' => $keyword,
            'status'             => 'approved',
            'intent_type'        => 'category',
            'target_type'        => 'tmw_category_page',
            'target_id'          => $primary_page,
            'target_name'        => 'Primary ' . $candidate_id,
            'import_rows'        => [
                [ 'pool' => 'category', 'batch_target_type' => 'tmw_category_page', 'batch_target_id' => $shared_page, 'batch_target_name' => 'Shared', 'row_status' => 'pending', 'batch_id' => 11, 'row_id' => 110 ],
                [ 'pool' => 'video', 'batch_target_type' => 'tmw_category_page', 'batch_target_id' => $shared_page, 'batch_target_name' => 'Shared', 'row_status' => 'pending', 'batch_id' => 12, 'row_id' => 120 ],
            ],
            'distinct_targets'   => [
                [ 'target_type' => 'tmw_category_page', 'target_id' => $primary_page, 'target_name' => 'Primary ' . $candidate_id ],
            ],
            'rankmath_presence'  => [
                [ 'post_id' => $primary_page, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ],
                [ 'post_id' => $shared_page, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'extra' ],
            ],
            'content_presence'   => [ [ 'post_id' => $primary_page, 'present' => true ] ],
            'target_unresolvable'=> [],
            'postmeta_ownership' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function reviewFor( int $candidate_id, string $role, string $pool = '' ): array {
        foreach ( $this->reviews->rows as $row ) {
            if ( (int) $row['keyword_candidate_id'] !== $candidate_id || (string) $row['planned_role'] !== $role ) { continue; }
            if ( '' !== $pool && (string) $row['pool'] !== $pool ) { continue; }
            return $row;
        }
        $this->fail( 'No review record for candidate ' . $candidate_id . ' role ' . $role . ( '' !== $pool ? ' pool ' . $pool : '' ) );
    }

    private function approve( int $review_id ): void {
        $result = $this->reviews->transition_review_state( $review_id, 'approved', 'op-a', '', 'test' );
        $this->assertTrue( $result['ok'], 'Approval failed: ' . (string) ( $result['error'] ?? '' ) );
    }

    /** @return array<string,mixed> manual-fixture args on the secondary identity */
    private function manualArgs( int $candidate_id, string $token ): array {
        return [
            'token'        => $token,
            'candidate_id' => $candidate_id,
            'target_type'  => 'tmw_category_page',
            'target_id'    => 600 + $candidate_id,
            'pool'         => 'category',
            'role'         => 'secondary',
        ];
    }

    /** Sync + approve the secondary review of candidate 1; returns its id. */
    private function approvedSecondaryReview(): int {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        $this->sync->sync();
        $review_id = (int) $this->reviewFor( 1, 'secondary' )['id'];
        $this->approve( $review_id );
        return $review_id;
    }

    /** @return array<string,mixed> */
    private function context( string $token, int $review_id, int $candidate_id ): array {
        return [ 'token' => $token, 'review_id' => $review_id, 'candidate_id' => $candidate_id ];
    }

    /** Analysis report minus the wall-clock field, for byte-identity checks. */
    private function analysisSansTime( array $filters = [] ): array {
        $report = $this->migration->analyze( $filters );
        unset( $report['generated_at'] );
        return $report;
    }

    // ══ A. Manual assignment fixture ══════════════════════════════════════

    public function test_manual_fixture_dry_run_writes_nothing(): void {
        $report = $this->service->create_manual_fixture( $this->manualArgs( 1, 'prval-manual-1' ), false, 'op-a' );

        $this->assertTrue( $report['ok'] );
        $this->assertSame( 'dry-run', $report['mode'] );
        $this->assertSame( 'would_create_manual_fixture', $report['outcome'] );
        $this->assertSame( [], $this->assignments->rows, 'Dry run must write no assignment rows.' );
        $this->assertSame( [], $this->fixtures->rows, 'Dry run must write no fixture rows.' );
        $this->assertSame( [], $this->fixtures->audit_rows, 'Dry run must write no audit rows.' );
    }

    public function test_manual_fixture_execution_creates_one_unmistakable_assignment(): void {
        $report = $this->service->create_manual_fixture( $this->manualArgs( 1, 'prval-manual-1' ), true, 'op-a' );

        $this->assertTrue( $report['ok'], 'Create failed: ' . (string) ( $report['error'] ?? '' ) );
        $this->assertSame( 'manual_fixture_created', $report['outcome'] );
        $this->assertCount( 1, $this->assignments->rows, 'Exactly one assignment row is created.' );
        $assignment = array_values( $this->assignments->rows )[0];
        $this->assertSame( FixtureRepo::MANUAL_SOURCE_TYPE, $assignment['source_type'] );
        $this->assertSame( 'validation:prval-manual-1', $assignment['source_reference'] );
        $this->assertSame( 'secondary', $assignment['role'] );
        $this->assertSame( 0, (int) $assignment['canonical_owner'], 'A fixture never takes ownership.' );
        $this->assertSame( 0, (int) $assignment['active_in_rank_math'], 'A fixture is never active in Rank Math.' );
        $this->assertCount( 1, $this->fixtures->rows );
        $fixture = $this->fixtures->find_by_id( 1 );
        $this->assertSame( 'active', $fixture['state'] );
        $this->assertSame( FixtureRepo::TYPE_MANUAL, $fixture['fixture_type'] );
        $this->assertSame( (int) $assignment['id'], (int) $fixture['assignment_id'] );
        // Active-identity keys and the append-only audit trail.
        $this->assertSame( 'prval-manual-1', (string) $fixture['active_token_key'] );
        $this->assertSame( FixtureRepo::manual_scope_key( (string) $assignment['assignment_key'] ), (string) $fixture['active_scope_key'] );
        $this->assertSame( [ 'created' ], $this->fixtures->audit_actions( 1 ) );
        $audit = array_values( $this->fixtures->audit_for_fixture( 1 ) )[0];
        $this->assertSame( 'active', $audit['new_state'] );
        $this->assertSame( 40, strlen( (string) $audit['payload_hash'] ), 'Audit rows pin a payload hash.' );
    }

    public function test_manual_fixture_role_is_secondary_only(): void {
        foreach ( [ 'primary', 'discovery', 'excluded' ] as $role ) {
            $args = $this->manualArgs( 1, 'prval-manual-1' );
            $args['role'] = $role;
            $report = $this->service->create_manual_fixture( $args, true, 'op-a' );
            $this->assertFalse( $report['ok'], 'Role must be refused: ' . $role );
            $this->assertSame( 'invalid_role_only_secondary_allowed', $report['error'] );
        }
        $this->assertSame( [], $this->assignments->rows, 'Nothing is written for a refused role.' );
        $this->assertSame( [], $this->fixtures->rows );
    }

    public function test_manual_fixture_recreate_is_idempotent(): void {
        $this->service->create_manual_fixture( $this->manualArgs( 1, 'prval-manual-1' ), true, 'op-a' );
        $rows_before = $this->assignments->rows;
        $fixtures_before = $this->fixtures->rows;

        $repeat = $this->service->create_manual_fixture( $this->manualArgs( 1, 'prval-manual-1' ), true, 'op-a' );

        $this->assertTrue( $repeat['ok'] );
        $this->assertSame( 'already_exists_same_fixture', $repeat['outcome'] );
        $this->assertSame( $rows_before, $this->assignments->rows, 'Recreate must not touch the assignment.' );
        $this->assertSame( $fixtures_before, $this->fixtures->rows, 'Recreate must not add fixture rows.' );
    }

    public function test_manual_fixture_refuses_to_overwrite_existing_assignments(): void {
        // A migration-owned row and an operator-manual row on two identities.
        $this->assignments->create_assignment( [
            'keyword_candidate_id' => 1, 'pool' => 'category', 'page_type' => 'tmw_category_page',
            'target_type' => 'tmw_category_page', 'target_id' => 601, 'role' => 'secondary',
            'status' => 'review_required', 'source_type' => 'migration_import', 'source_reference' => 'kwmig-v1',
        ] );
        $this->assignments->create_assignment( [
            'keyword_candidate_id' => 2, 'pool' => 'category', 'page_type' => 'tmw_category_page',
            'target_type' => 'tmw_category_page', 'target_id' => 602, 'role' => 'secondary',
            'status' => 'review_required', 'source_type' => 'operator_manual', 'source_reference' => 'ticket-77',
        ] );
        $rows_before = $this->assignments->rows;

        $against_migration = $this->service->create_manual_fixture( $this->manualArgs( 1, 'prval-manual-1' ), true, 'op-a' );
        $against_manual    = $this->service->create_manual_fixture( $this->manualArgs( 2, 'prval-manual-2' ), true, 'op-a' );

        $this->assertFalse( $against_migration['ok'] );
        $this->assertSame( 'existing_assignment_present_source_type_migration_import', $against_migration['error'] );
        $this->assertFalse( $against_manual['ok'] );
        $this->assertSame( 'existing_assignment_present_source_type_operator_manual', $against_manual['error'] );
        $this->assertSame( $rows_before, $this->assignments->rows, 'Existing assignments are never overwritten or relabeled.' );
        $this->assertSame( [], $this->fixtures->rows );
    }

    public function test_executor_preserves_the_manual_fixture_unmodified(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        $this->sync->sync();
        $this->approve( (int) $this->reviewFor( 1, 'secondary' )['id'] );
        $created = $this->service->create_manual_fixture( $this->manualArgs( 1, 'prval-manual-1' ), true, 'op-a' );
        $this->assertTrue( $created['ok'] );
        $fixture_assignment_before = $this->assignments->rows[ (int) $created['assignment_id'] ];

        $report = $this->executor->execute_approved( [ 'review_ids' => [ (int) $this->reviewFor( 1, 'secondary' )['id'] ] ], true );

        $this->assertSame( 1, $report['counts']['selected'] );
        $this->assertSame( 1, $report['counts']['skipped'] );
        $this->assertSame( 0, $report['counts']['executed'] );
        $this->assertSame( 'skipped_manual_assignment_preserved', $report['results'][0]['outcome'] );
        $this->assertSame(
            $fixture_assignment_before,
            $this->assignments->rows[ (int) $created['assignment_id'] ],
            'The executor must not modify the fixture assignment in any way.'
        );
        $review = $this->reviews->rows[ (int) $this->reviewFor( 1, 'secondary' )['id'] ];
        $this->assertSame( 'skipped', $review['execution_state'] );
        $this->assertSame( 'manual_assignment_preserved', $review['execution_result'] );
    }

    public function test_cleanup_removes_only_the_matching_fixture(): void {
        $this->service->create_manual_fixture( $this->manualArgs( 1, 'prval-manual-1' ), true, 'op-a' );
        $this->service->create_manual_fixture( $this->manualArgs( 2, 'prval-manual-2' ), true, 'op-a' );
        $this->assignments->create_assignment( [
            'keyword_candidate_id' => 3, 'pool' => 'category', 'page_type' => 'tmw_category_page',
            'target_type' => 'tmw_category_page', 'target_id' => 603, 'role' => 'secondary',
            'status' => 'review_required', 'source_type' => 'migration_import', 'source_reference' => 'kwmig-v1',
        ] );
        $this->assertCount( 3, $this->assignments->rows );

        $dry = $this->service->remove_manual_fixture( 'prval-manual-1', false, 'op-a' );
        $this->assertTrue( $dry['ok'] );
        $this->assertSame( 'would_remove_manual_fixture', $dry['outcome'] );
        $this->assertCount( 3, $this->assignments->rows, 'Dry run deletes nothing.' );

        $removed = $this->service->remove_manual_fixture( 'prval-manual-1', true, 'op-a' );
        $this->assertTrue( $removed['ok'] );
        $this->assertSame( 'manual_fixture_removed', $removed['outcome'] );
        $this->assertCount( 2, $this->assignments->rows, 'Exactly one row deleted.' );
        $remaining_sources = array_map( fn ( $row ) => (string) $row['source_reference'], array_values( $this->assignments->rows ) );
        $this->assertContains( 'validation:prval-manual-2', $remaining_sources, 'The other fixture is untouched.' );
        $this->assertContains( 'kwmig-v1', $remaining_sources, 'Migration data is untouched.' );
        $closed = $this->fixtures->find_by_id( 1 );
        $this->assertSame( 'removed', $closed['state'] );
        $this->assertNull( $closed['active_token_key'], 'Terminal fixtures release their token identity.' );
        $this->assertNull( $closed['active_scope_key'], 'Terminal fixtures release their scope identity.' );
        $this->assertSame( [ 'created', 'manual_fixture_removed' ], $this->fixtures->audit_actions( 1 ) );
        $this->assertSame( 'active', $this->fixtures->find_by_id( 2 )['state'] );

        $repeat = $this->service->remove_manual_fixture( 'prval-manual-1', true, 'op-a' );
        $this->assertTrue( $repeat['ok'] );
        $this->assertSame( 'already_removed', $repeat['outcome'] );
        $this->assertCount( 2, $this->assignments->rows );
    }

    public function test_cleanup_refuses_wrong_token_and_reattributed_rows(): void {
        $created = $this->service->create_manual_fixture( $this->manualArgs( 1, 'prval-manual-1' ), true, 'op-a' );
        $rows_before = $this->assignments->rows;

        $wrong = $this->service->remove_manual_fixture( 'prval-wrong-token', true, 'op-a' );
        $this->assertFalse( $wrong['ok'] );
        $this->assertSame( 'no_manual_fixture_for_token', $wrong['error'] );
        $this->assertSame( $rows_before, $this->assignments->rows );

        // A row someone re-attributed is no longer ours to delete.
        $this->assignments->rows[ (int) $created['assignment_id'] ]['source_type'] = 'operator_manual';
        $reattributed = $this->service->remove_manual_fixture( 'prval-manual-1', true, 'op-a' );
        $this->assertFalse( $reattributed['ok'] );
        $this->assertSame( 'assignment_not_owned_by_this_fixture_refusing_delete', $reattributed['error'] );
        $this->assertArrayHasKey( (int) $created['assignment_id'], $this->assignments->rows, 'The re-attributed row survives.' );
        $this->assertSame( 'active', $this->fixtures->find_by_id( 1 )['state'], 'Fixture stays active for investigation.' );
    }

    public function test_manual_fixture_fails_closed_on_missing_fields_and_bad_tokens(): void {
        $base = $this->manualArgs( 1, 'prval-manual-1' );
        foreach ( [
            'token'        => 'invalid_or_missing_validation_token',
            'candidate_id' => 'missing_candidate_id',
            'target_type'  => 'missing_target_type',
            'pool'         => 'missing_pool',
            'role'         => 'invalid_role_only_secondary_allowed',
        ] as $field => $error ) {
            $args = $base;
            unset( $args[ $field ] );
            $report = $this->service->create_manual_fixture( $args, true, 'op-a' );
            $this->assertFalse( $report['ok'], 'Must refuse without ' . $field );
            $this->assertSame( $error, $report['error'] );
        }
        $bad_token = $base;
        $bad_token['token'] = 'no spaces or $ymbols';
        $this->assertSame( 'invalid_or_missing_validation_token', $this->service->create_manual_fixture( $bad_token, true, 'op-a' )['error'] );
        $no_target = $base;
        $no_target['target_id'] = 0;
        $this->assertSame( 'missing_target_id_or_target_key', $this->service->create_manual_fixture( $no_target, true, 'op-a' )['error'] );
        $this->assertSame( [], $this->assignments->rows );
        $this->assertSame( [], $this->fixtures->rows );
    }

    public function test_one_active_fixture_per_token(): void {
        $this->service->create_manual_fixture( $this->manualArgs( 1, 'prval-shared' ), true, 'op-a' );

        $reuse = $this->service->create_manual_fixture( $this->manualArgs( 2, 'prval-shared' ), true, 'op-a' );

        $this->assertFalse( $reuse['ok'] );
        $this->assertSame( 'token_already_has_active_fixture', $reuse['error'] );
        $this->assertCount( 1, $this->assignments->rows );
    }

    // ══ Manual-review recovery ════════════════════════════════════════════

    /** Full happy path: fixture -> skipped -> removed -> recovered -> synced -> executed. */
    public function test_recover_manual_review_full_workflow(): void {
        $review_id = $this->approvedSecondaryReview();
        $this->service->create_manual_fixture( $this->manualArgs( 1, 'prval-manual-1' ), true, 'op-a' );
        $this->executor->execute_approved( [ 'review_ids' => [ $review_id ] ], true );
        $this->assertSame( 'skipped', $this->reviews->rows[ $review_id ]['execution_state'] );
        $this->service->remove_manual_fixture( 'prval-manual-1', true, 'op-a' );

        $dry = $this->service->recover_manual_review( $review_id, 'prval-manual-1', false, 'op-a' );
        $this->assertTrue( $dry['ok'], 'Recovery dry-run failed: ' . (string) ( $dry['error'] ?? '' ) );
        $this->assertSame( 'would_recover_manual_review', $dry['outcome'] );
        $this->assertSame( 'skipped', $this->reviews->rows[ $review_id ]['execution_state'], 'Dry run mutates nothing.' );

        $review_audits_before = count( $this->reviews->audit_rows );
        $recovered = $this->service->recover_manual_review( $review_id, 'prval-manual-1', true, 'op-a' );
        $this->assertTrue( $recovered['ok'], 'Recovery failed: ' . (string) ( $recovered['error'] ?? '' ) );
        $this->assertSame( 'manual_review_recovered', $recovered['outcome'] );
        // Rev 3: the review transition, its review-audit row, and the
        // fixture recovery audit commit together in one outer transaction.
        $this->assertSame( $review_audits_before + 1, count( $this->reviews->audit_rows ), 'Exactly one review-audit row commits with the transition.' );
        $this->assertSame( 'COMMIT', end( $this->fixtures->transactions ), 'The outer cross-repository transaction commits.' );
        $review = $this->reviews->rows[ $review_id ];
        $this->assertSame( 'stale', $review['execution_state'], 'Recovery uses the existing skipped->stale transition.' );
        $this->assertSame( 'approved', $review['review_state'], 'Human review state is preserved.' );
        $this->assertSame( KeywordAssignmentValidationService::RECOVERY_REASON_PREFIX . 'prval-manual-1', (string) $review['stale_reason'] );
        $this->assertSame( [ 'created', 'manual_fixture_removed', 'manual_review_recovered' ], $this->fixtures->audit_actions( 1 ) );

        // Idempotent while awaiting sync.
        $repeat = $this->service->recover_manual_review( $review_id, 'prval-manual-1', true, 'op-a' );
        $this->assertTrue( $repeat['ok'] );
        $this->assertSame( 'already_recovered', $repeat['outcome'] );

        // The EXISTING scoped sync restores the normal fresh plan.
        $sync_report = $this->sync->sync( [ 'candidate_id' => 1 ] );
        $this->assertSame( 1, $sync_report['counts']['restored'] );
        $this->assertSame( 'not_executed', $this->reviews->rows[ $review_id ]['execution_state'] );

        // Idempotent after sync too.
        $after_sync = $this->service->recover_manual_review( $review_id, 'prval-manual-1', true, 'op-a' );
        $this->assertTrue( $after_sync['ok'] );
        $this->assertSame( 'already_recovered_and_synced', $after_sync['outcome'] );

        // The review now approves/executes normally through PR-E.
        $report = $this->executor->execute_approved( [ 'review_ids' => [ $review_id ] ], true );
        $this->assertSame( 1, $report['counts']['executed'] );
        $this->assertSame( 'executed_inserted', $report['results'][0]['outcome'] );
        $this->assertCount( 1, $this->assignments->rows );
        $this->assertSame( 'migration_combined', (string) array_values( $this->assignments->rows )[0]['source_type'], 'A real migration assignment exists after recovery.' );
    }

    public function test_recover_manual_review_refuses_unsafe_states(): void {
        $review_id = $this->approvedSecondaryReview();
        $this->service->create_manual_fixture( $this->manualArgs( 1, 'prval-manual-1' ), true, 'op-a' );
        $this->executor->execute_approved( [ 'review_ids' => [ $review_id ] ], true );

        // Refused while the fixture still exists (state active) — audited.
        $active = $this->service->recover_manual_review( $review_id, 'prval-manual-1', true, 'op-a' );
        $this->assertFalse( $active['ok'] );
        $this->assertSame( 'fixture_still_active_remove_fixture_first', $active['error'] );
        $this->assertContains( 'recover_manual_review_refused', $this->fixtures->audit_actions( 1 ) );
        $this->assertSame( 'skipped', $this->reviews->rows[ $review_id ]['execution_state'], 'Nothing was reset.' );

        $this->service->remove_manual_fixture( 'prval-manual-1', true, 'op-a' );

        // Wrong token: nothing to recover with.
        $wrong_token = $this->service->recover_manual_review( $review_id, 'prval-wrong', true, 'op-a' );
        $this->assertFalse( $wrong_token['ok'] );
        $this->assertSame( 'no_manual_fixture_for_token', $wrong_token['error'] );

        // Unrelated review (the primary sibling) is refused — never reset.
        $primary_id = (int) $this->reviewFor( 1, 'primary' )['id'];
        $unrelated = $this->service->recover_manual_review( $primary_id, 'prval-manual-1', true, 'op-a' );
        $this->assertFalse( $unrelated['ok'] );
        $this->assertSame( 'review_not_skipped_by_this_fixture', $unrelated['error'] );

        // Identity reoccupied by an unrelated assignment: refused.
        $this->assignments->create_assignment( [
            'keyword_candidate_id' => 1, 'pool' => 'category', 'page_type' => 'tmw_category_page',
            'target_type' => 'tmw_category_page', 'target_id' => 601, 'role' => 'secondary',
            'status' => 'review_required', 'source_type' => 'migration_import', 'source_reference' => 'kwmig-v1',
        ] );
        $reoccupied = $this->service->recover_manual_review( $review_id, 'prval-manual-1', true, 'op-a' );
        $this->assertFalse( $reoccupied['ok'] );
        $this->assertSame( 'assignment_identity_reoccupied_refusing_recovery', $reoccupied['error'] );
        $this->assertSame( 'skipped', $this->reviews->rows[ $review_id ]['execution_state'], 'The skipped review is untouched by every refusal.' );

        // A review skipped for a DIFFERENT reason is refused.
        $this->assignments->rows = array_filter( $this->assignments->rows, fn ( $row ) => 'kwmig-v1' !== (string) $row['source_reference'] );
        $this->reviews->rows[ $review_id ]['execution_result'] = 'some_other_reason';
        $tampered = $this->service->recover_manual_review( $review_id, 'prval-manual-1', true, 'op-a' );
        $this->assertFalse( $tampered['ok'] );
        $this->assertSame( 'review_not_in_recoverable_state_skipped', $tampered['error'] );
    }

    // ══ B. Stale-plan fixture — opt-in contract ═══════════════════════════

    public function test_active_fixture_never_alters_ordinary_analysis_sync_or_execution(): void {
        $review_id = $this->approvedSecondaryReview();
        $analysis_before = $this->analysisSansTime( [ 'candidate_id' => 1 ] );
        $full_analysis_before = $this->analysisSansTime();
        $reviews_before = $this->reviews->rows;

        $activated = $this->service->create_stale_fixture( $review_id, 'prval-stale-1', true, 'op-a' );
        $this->assertTrue( $activated['ok'], 'Activation failed: ' . (string) ( $activated['error'] ?? '' ) );
        $this->assertCount( 1, $this->fixtures->list_active(), 'The fixture IS active.' );

        // Ordinary analysis: byte-identical with the active fixture present.
        $this->assertSame( $analysis_before, $this->analysisSansTime( [ 'candidate_id' => 1 ] ), 'Ordinary scoped analysis must ignore active fixtures.' );
        $this->assertSame( $full_analysis_before, $this->analysisSansTime(), 'Ordinary full analysis must ignore active fixtures.' );

        // Ordinary review sync: no record changes, no stale marks.
        $sync_report = $this->sync->sync( [ 'candidate_id' => 1 ] );
        $this->assertSame( 0, $sync_report['counts']['stale'], 'Ordinary sync must not stale anything because of a fixture.' );
        $this->assertSame( 0, $sync_report['counts']['updated'] );
        $this->assertSame( $reviews_before, $this->reviews->rows, 'Ordinary sync leaves every review row byte-identical.' );

        // Ordinary execute-approved: executes exactly as without a fixture.
        $report = $this->executor->execute_approved( [ 'review_ids' => [ $review_id ] ], true );
        $this->assertSame( 1, $report['counts']['executed'], 'Ordinary execution ignores active fixtures.' );
        $this->assertSame( 'executed_inserted', $report['results'][0]['outcome'] );
        $this->assertCount( 1, $this->assignments->rows );
    }

    public function test_stale_fixture_dry_run_writes_nothing_and_reports_expected_reason(): void {
        $review_id = $this->approvedSecondaryReview();
        $evidence_before = $this->evidence->rows;
        $reviews_before = $this->reviews->rows;

        $report = $this->service->create_stale_fixture( $review_id, 'prval-stale-1', false, 'op-a' );

        $this->assertTrue( $report['ok'], 'Dry run failed: ' . (string) ( $report['error'] ?? '' ) );
        $this->assertSame( 'would_activate_stale_fixture', $report['outcome'] );
        $this->assertSame( 'planned_action_changed:present_in_content', $report['expected_stale_reason'] );
        $this->assertSame( [ 'kind' => 'content_presence', 'post_id' => 601, 'present' => true ], $report['override'] );
        $this->assertSame( [], $this->fixtures->rows, 'Dry run activates nothing.' );
        $this->assertSame( [], $this->fixtures->audit_rows );
        $this->assertSame( $evidence_before, $this->evidence->rows, 'Candidate evidence is never mutated.' );
        $this->assertSame( $reviews_before, $this->reviews->rows, 'Review records are never mutated by the fixture command.' );
        $this->assertCount( 1, $report['sibling_effects'] );
        $this->assertSame( 'unchanged', $report['sibling_effects'][0]['effect'], 'The primary sibling review is unaffected by this override.' );
    }

    public function test_stale_fixture_requires_an_approved_unexecuted_real_review(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        $this->sync->sync();
        $pending_id = (int) $this->reviewFor( 1, 'secondary' )['id'];

        $this->assertSame( 'review_record_not_found', $this->service->create_stale_fixture( 999, 'prval-stale-1', true, 'op-a' )['error'] );
        $this->assertSame( 'review_not_approved_current_pending', $this->service->create_stale_fixture( $pending_id, 'prval-stale-1', true, 'op-a' )['error'] );
        $this->assertSame( 'missing_review_id', $this->service->create_stale_fixture( 0, 'prval-stale-1', true, 'op-a' )['error'] );
        $this->assertSame( 'invalid_or_missing_validation_token', $this->service->create_stale_fixture( $pending_id, '', true, 'op-a' )['error'] );
        $this->assertSame( [], $this->fixtures->rows );
    }

    public function test_sibling_plan_change_refuses_activation(): void {
        $this->evidence->rows = [ $this->sharedPageEvidenceRow( 3, 'gamma phrase' ) ];
        $this->sync->sync();
        $target_id = (int) $this->reviewFor( 3, 'secondary', 'category' )['id'];
        $this->approve( $target_id );

        $report = $this->service->create_stale_fixture( $target_id, 'prval-stale-3', true, 'op-a' );

        $this->assertFalse( $report['ok'], 'A sibling-changing override must refuse activation.' );
        $this->assertSame( 'sibling_plan_would_change_refusing_activation', $report['error'] );
        $this->assertSame( [], $this->fixtures->rows, 'Nothing is activated.' );
        $effects = array_column( (array) $report['sibling_effects'], 'effect', 'review_id' );
        $video_id = (int) $this->reviewFor( 3, 'secondary', 'video' )['id'];
        $this->assertSame( 'planned_record_would_change', $effects[ $video_id ], 'The report names the changing sibling.' );
    }

    public function test_run_stale_validation_applies_override_only_with_exact_context(): void {
        $review_id = $this->approvedSecondaryReview();
        $this->service->create_stale_fixture( $review_id, 'prval-stale-1', true, 'op-a' );
        $reviews_before = $this->reviews->rows;
        $assignments_before = $this->assignments->rows;

        // Wrong token, wrong review ID, wrong candidate ID: nothing applies.
        $wrong_token = $this->service->run_stale_validation( $this->context( 'prval-other-token', $review_id, 1 ), true, 'op-a' );
        $this->assertFalse( $wrong_token['ok'] );
        $this->assertSame( 'no_stale_fixture_for_token', $wrong_token['error'] );
        $primary_id = (int) $this->reviewFor( 1, 'primary' )['id'];
        $wrong_review = $this->service->run_stale_validation( $this->context( 'prval-stale-1', $primary_id, 1 ), true, 'op-a' );
        $this->assertFalse( $wrong_review['ok'] );
        $this->assertSame( 'validation_context_review_mismatch', $wrong_review['error'] );
        $wrong_candidate = $this->service->run_stale_validation( $this->context( 'prval-stale-1', $review_id, 2 ), true, 'op-a' );
        $this->assertFalse( $wrong_candidate['ok'] );
        $this->assertSame( 'validation_context_candidate_mismatch', $wrong_candidate['error'] );
        $this->assertSame( $reviews_before, $this->reviews->rows, 'A wrong context element mutates nothing.' );
        $this->assertSame( $assignments_before, $this->assignments->rows );

        // Exact context, dry-run: predicted, nothing written.
        $dry = $this->service->run_stale_validation( $this->context( 'prval-stale-1', $review_id, 1 ), false, 'op-a' );
        $this->assertTrue( $dry['ok'], 'Dry run failed: ' . (string) ( $dry['error'] ?? '' ) );
        $this->assertSame( 'would_mark_review_stale', $dry['outcome'] );
        $this->assertSame( 'stale', $dry['executor_outcome'] );
        $this->assertSame( $reviews_before, $this->reviews->rows, 'Dry run mutates nothing.' );

        // Exact context, execute: the REAL executor marks the review stale.
        $snapshot_hash_before = (string) $this->reviews->rows[ $review_id ]['snapshot_hash'];
        $review_audits_before = count( $this->reviews->audit_rows );
        $run = $this->service->run_stale_validation( $this->context( 'prval-stale-1', $review_id, 1 ), true, 'op-a' );
        $this->assertTrue( $run['ok'], 'Run failed: ' . (string) ( $run['error'] ?? '' ) );
        // Rev 3: the stale transition, its review-audit row, and the
        // fixture validation audit commit together in one outer transaction.
        $this->assertSame( $review_audits_before + 1, count( $this->reviews->audit_rows ), 'Exactly one review-audit row commits with the stale transition.' );
        $this->assertSame( 'COMMIT', end( $this->fixtures->transactions ), 'The outer cross-repository transaction commits.' );
        $this->assertSame( 'review_marked_stale_by_real_executor', $run['outcome'] );
        $this->assertSame( 'stale', $run['executor_outcome'] );
        $this->assertSame( 'planned_action_changed:present_in_content', $run['executor_reason'] );
        $review = $this->reviews->rows[ $review_id ];
        $this->assertSame( 'stale', $review['execution_state'] );
        $this->assertSame( 'approved', $review['review_state'], 'Human review state is preserved.' );
        $this->assertSame( $snapshot_hash_before, (string) $review['snapshot_hash'], 'Snapshot hashes are never tampered with.' );
        $this->assertSame( $assignments_before, $this->assignments->rows, 'No assignment write happens for a stale review.' );
        $this->assertSame( [ 'created', 'stale_validation_executed' ], $this->fixtures->audit_actions( 1 ) );
    }

    public function test_restoration_is_exact_idempotent_and_audited(): void {
        $review_id = $this->approvedSecondaryReview();
        $baseline = $this->analysisSansTime( [ 'candidate_id' => 1 ] );

        $this->service->create_stale_fixture( $review_id, 'prval-stale-1', true, 'op-a' );
        $this->assertSame( $baseline, $this->analysisSansTime( [ 'candidate_id' => 1 ] ), 'Activation alone never changes the analyzer input.' );

        $dry = $this->service->restore_stale_fixture( 'prval-stale-1', false, 'op-a' );
        $this->assertTrue( $dry['ok'] );
        $this->assertSame( 'would_restore_stale_fixture', $dry['outcome'] );
        $this->assertSame( 'active', $this->fixtures->find_by_id( 1 )['state'], 'Dry run restores nothing.' );

        $restored = $this->service->restore_stale_fixture( 'prval-stale-1', true, 'op-a' );
        $this->assertTrue( $restored['ok'] );
        $this->assertSame( 'stale_fixture_restored', $restored['outcome'] );
        $closed = $this->fixtures->find_by_id( 1 );
        $this->assertSame( 'restored', $closed['state'] );
        $this->assertNull( $closed['active_token_key'] );
        $this->assertNull( $closed['active_scope_key'] );
        $this->assertSame( [ 'created', 'stale_fixture_restored' ], $this->fixtures->audit_actions( 1 ) );
        $this->assertSame( $baseline, $this->analysisSansTime( [ 'candidate_id' => 1 ] ), 'Restoration leaves the analyzer input exactly as it always was.' );

        $repeat = $this->service->restore_stale_fixture( 'prval-stale-1', true, 'op-a' );
        $this->assertTrue( $repeat['ok'] );
        $this->assertSame( 'already_restored', $repeat['outcome'], 'Repeated restoration is idempotent.' );

        // A restored fixture can no longer be run: fails closed.
        $after = $this->service->run_stale_validation( $this->context( 'prval-stale-1', $review_id, 1 ), true, 'op-a' );
        $this->assertFalse( $after['ok'] );
        $this->assertSame( 'stale_fixture_not_active', $after['error'] );
    }

    public function test_full_stale_loop_review_recovers_only_through_existing_sync_rules(): void {
        $review_id = $this->approvedSecondaryReview();
        $this->service->create_stale_fixture( $review_id, 'prval-stale-1', true, 'op-a' );
        $this->service->run_stale_validation( $this->context( 'prval-stale-1', $review_id, 1 ), true, 'op-a' );
        $this->assertSame( 'stale', $this->reviews->rows[ $review_id ]['execution_state'] );

        // Restoring the fixture alone does NOT touch the review record.
        $this->service->restore_stale_fixture( 'prval-stale-1', true, 'op-a' );
        $this->assertSame( 'stale', $this->reviews->rows[ $review_id ]['execution_state'], 'Only the existing sync workflow may restore the review.' );

        $sync_report = $this->sync->sync( [ 'candidate_id' => 1 ] );
        $this->assertSame( 1, $sync_report['counts']['restored'] );
        $review = $this->reviews->rows[ $review_id ];
        $this->assertSame( 'not_executed', $review['execution_state'] );
        $this->assertSame( 'approved', $review['review_state'] );

        // The restored review now executes normally through PR-E.
        $report = $this->executor->execute_approved( [ 'review_ids' => [ $review_id ] ], true );
        $this->assertSame( 1, $report['counts']['executed'] );
        $this->assertSame( 'executed_inserted', $report['results'][0]['outcome'] );
        $this->assertCount( 1, $this->assignments->rows );
    }

    public function test_stale_fixture_refuses_mismatched_reviews_and_busy_candidates(): void {
        $review_id = $this->approvedSecondaryReview();
        $this->service->create_stale_fixture( $review_id, 'prval-stale-1', true, 'op-a' );

        // Same candidate, second fixture: refused while one is active.
        $primary_id = (int) $this->reviewFor( 1, 'primary' )['id'];
        $this->approve( $primary_id );
        $busy = $this->service->create_stale_fixture( $primary_id, 'prval-stale-2', true, 'op-a' );
        $this->assertFalse( $busy['ok'] );
        $this->assertSame( 'candidate_already_has_active_stale_fixture', $busy['error'] );

        // Once the review was marked stale by the explicit run, a NEW
        // fixture on it is refused — until sync heals it.
        $this->service->run_stale_validation( $this->context( 'prval-stale-1', $review_id, 1 ), true, 'op-a' );
        $this->service->restore_stale_fixture( 'prval-stale-1', true, 'op-a' );
        $stale_state = $this->service->create_stale_fixture( $review_id, 'prval-stale-3', true, 'op-a' );
        $this->assertFalse( $stale_state['ok'] );
        $this->assertSame( 'execution_state_not_not_executed_current_stale', $stale_state['error'] );
    }

    // ══ Integrity: encoding, audit atomicity, concurrency ═════════════════

    public function test_json_encoding_failure_aborts_and_rolls_back_fixture_creation(): void {
        $failing = new ValidationEncodeFailingFixtureRepository();
        $failing->linked_stores = [ $this->assignments ];
        $service = new KeywordAssignmentValidationService( $failing, $this->assignments, $this->reviews, $this->migration, $this->sync, $this->executor );

        // Manual creation: the assignment write is rolled back with it.
        $failing->fail_encode_on = 'values';
        $manual = $service->create_manual_fixture( $this->manualArgs( 1, 'prval-manual-1' ), true, 'op-a' );
        $this->assertFalse( $manual['ok'] );
        $this->assertSame( 'fixture_record_failed_fixture_values_encode_failed', $manual['error'] );
        $this->assertSame( [], $this->assignments->rows, 'The assignment insert is rolled back with the failed encoding.' );
        $this->assertSame( [], $failing->rows, 'No fixture row with fabricated JSON is ever persisted.' );
        $this->assertSame( [], $failing->audit_rows );
        $this->assertSame( 'ROLLBACK', end( $failing->transactions ), 'The transaction is rolled back.' );

        // Stale creation: same hard abort.
        $review_id = $this->approvedSecondaryReview();
        $stale = $service->create_stale_fixture( $review_id, 'prval-stale-1', true, 'op-a' );
        $this->assertFalse( $stale['ok'] );
        $this->assertSame( 'fixture_record_failed_fixture_values_encode_failed', $stale['error'] );
        $this->assertSame( [], $failing->rows );
        $this->assertSame( 'ROLLBACK', end( $failing->transactions ) );

        // Lifecycle persistence (restore): the audit snapshot encoding fails,
        // so the state transition rolls back and the fixture stays active.
        $failing->fail_encode_on = '';
        $created = $service->create_stale_fixture( $review_id, 'prval-stale-1', true, 'op-a' );
        $this->assertTrue( $created['ok'], 'Setup failed: ' . (string) ( $created['error'] ?? '' ) );
        $failing->fail_encode_on = 'audit';
        $restore = $service->restore_stale_fixture( 'prval-stale-1', true, 'op-a' );
        $this->assertFalse( $restore['ok'] );
        $this->assertSame( 'fixture_close_failed_fixture_audit_insert_failed', $restore['error'] );
        $this->assertSame( 'active', $failing->find_by_id( (int) $created['fixture_id'] )['state'], 'The lifecycle transition is rolled back.' );
        $this->assertSame( 'ROLLBACK', end( $failing->transactions ) );
    }

    public function test_real_encoder_fails_closed_on_unencodable_values(): void {
        $repository = new ExposedEncodeFixtureRepository();
        $this->assertNull( $repository->encode( [ 'bad' => "\xB1\x31" ] ), 'Invalid UTF-8 must yield null, never a fabricated fallback.' );
        $this->assertSame( '{"good":1}', $repository->encode( [ 'good' => 1 ] ) );
    }

    public function test_audit_insertion_failure_rolls_back_lifecycle_transitions(): void {
        // Creation: fixture + assignment roll back together.
        $this->fixtures->fail_audit_insert = true;
        $create = $this->service->create_manual_fixture( $this->manualArgs( 1, 'prval-manual-1' ), true, 'op-a' );
        $this->assertFalse( $create['ok'] );
        $this->assertSame( 'fixture_record_failed_fixture_audit_insert_failed', $create['error'] );
        $this->assertSame( [], $this->fixtures->rows, 'The fixture insert is rolled back.' );
        $this->assertSame( [], $this->assignments->rows, 'The assignment insert is rolled back.' );

        // Removal: the deletion and the state transition roll back together.
        $this->fixtures->fail_audit_insert = false;
        $created = $this->service->create_manual_fixture( $this->manualArgs( 1, 'prval-manual-1' ), true, 'op-a' );
        $this->assertTrue( $created['ok'] );
        $this->fixtures->fail_audit_insert = true;
        $remove = $this->service->remove_manual_fixture( 'prval-manual-1', true, 'op-a' );
        $this->assertFalse( $remove['ok'] );
        $this->assertSame( 'fixture_close_failed_fixture_audit_insert_failed', $remove['error'] );
        $this->assertSame( 'active', $this->fixtures->find_by_id( (int) $created['fixture_id'] )['state'], 'The state transition is rolled back.' );
        $this->assertArrayHasKey( (int) $created['assignment_id'], $this->assignments->rows, 'The fixture assignment survives the rolled-back removal.' );
    }

    public function test_duplicate_and_concurrent_creates_are_refused_by_the_identity_index(): void {
        $repository = new RaceWindowFixtureRepository();
        $repository->blind_precheck = true; // every SELECT pre-check misses

        $first = $repository->create_fixture( [
            'validation_token'     => 'prval-race-1',
            'fixture_type'         => FixtureRepo::TYPE_MANUAL,
            'keyword_candidate_id' => 9,
            'active_scope_key'     => FixtureRepo::manual_scope_key( 'abc123' ),
        ] );
        $this->assertTrue( $first['ok'], 'First insert wins: ' . (string) ( $first['error'] ?? '' ) );

        // Same token, same scope (the classic double-submit race).
        $same = $repository->create_fixture( [
            'validation_token'     => 'prval-race-1',
            'fixture_type'         => FixtureRepo::TYPE_MANUAL,
            'keyword_candidate_id' => 9,
            'active_scope_key'     => FixtureRepo::manual_scope_key( 'abc123' ),
        ] );
        $this->assertFalse( $same['ok'] );
        $this->assertSame( 'duplicate_active_fixture_identity', $same['error'] );

        // Different token, same manual assignment identity.
        $same_scope = $repository->create_fixture( [
            'validation_token'     => 'prval-race-2',
            'fixture_type'         => FixtureRepo::TYPE_MANUAL,
            'keyword_candidate_id' => 9,
            'active_scope_key'     => FixtureRepo::manual_scope_key( 'abc123' ),
        ] );
        $this->assertFalse( $same_scope['ok'] );
        $this->assertSame( 'duplicate_active_fixture_identity', $same_scope['error'] );

        // Stale scope: one active stale fixture per candidate.
        $stale_one = $repository->create_fixture( [
            'validation_token'     => 'prval-race-3',
            'fixture_type'         => FixtureRepo::TYPE_STALE,
            'keyword_candidate_id' => 12,
            'review_id'            => 40,
            'active_scope_key'     => FixtureRepo::stale_scope_key( 12 ),
        ] );
        $this->assertTrue( $stale_one['ok'] );
        $stale_two = $repository->create_fixture( [
            'validation_token'     => 'prval-race-4',
            'fixture_type'         => FixtureRepo::TYPE_STALE,
            'keyword_candidate_id' => 12,
            'review_id'            => 41,
            'active_scope_key'     => FixtureRepo::stale_scope_key( 12 ),
        ] );
        $this->assertFalse( $stale_two['ok'] );
        $this->assertSame( 'duplicate_active_fixture_identity', $stale_two['error'] );

        $this->assertCount( 2, $repository->rows, 'Exactly the two winning fixtures exist.' );
        $this->assertCount( 2, $repository->audit_rows, 'Refused duplicates leave no audit rows.' );
    }

    // ══ Rev 3: cross-repository transaction boundaries ════════════════════

    public function test_recover_manual_review_fixture_audit_failure_rolls_back_the_whole_unit(): void {
        $review_id = $this->approvedSecondaryReview();
        $this->service->create_manual_fixture( $this->manualArgs( 1, 'prval-manual-1' ), true, 'op-a' );
        $this->executor->execute_approved( [ 'review_ids' => [ $review_id ] ], true );
        $this->service->remove_manual_fixture( 'prval-manual-1', true, 'op-a' );
        $review_before = $this->reviews->rows[ $review_id ];
        $review_audits_before = count( $this->reviews->audit_rows );
        $fixture_audits_before = count( $this->fixtures->audit_rows );

        $this->fixtures->fail_audit_insert = true;
        $report = $this->service->recover_manual_review( $review_id, 'prval-manual-1', true, 'op-a' );

        $this->assertFalse( $report['ok'] );
        $this->assertSame( 'recovery_fixture_audit_failed_rolled_back', $report['error'] );
        $review = $this->reviews->rows[ $review_id ];
        $this->assertSame( 'skipped', $review['execution_state'], 'The review stays skipped.' );
        $this->assertSame( 'manual_assignment_preserved', $review['execution_result'] );
        $this->assertSame( (string) ( $review_before['stale_reason'] ?? '' ), (string) ( $review['stale_reason'] ?? '' ), 'stale_reason is unchanged.' );
        $this->assertSame( $review_before, $review, 'The review row is byte-identical after rollback.' );
        $this->assertSame( $review_audits_before, count( $this->reviews->audit_rows ), 'No recovery review-audit row remains.' );
        $this->assertSame( $fixture_audits_before, count( $this->fixtures->audit_rows ), 'No fixture recovery audit row exists.' );
        $this->assertSame( 'ROLLBACK', end( $this->fixtures->transactions ), 'The outer transaction rolled back.' );

        // The unit stays recoverable once the audit path works again.
        $this->fixtures->fail_audit_insert = false;
        $retry = $this->service->recover_manual_review( $review_id, 'prval-manual-1', true, 'op-a' );
        $this->assertTrue( $retry['ok'], 'Retry failed: ' . (string) ( $retry['error'] ?? '' ) );
        $this->assertSame( 'manual_review_recovered', $retry['outcome'] );
    }

    public function test_run_stale_validation_fixture_audit_failure_rolls_back_the_whole_unit(): void {
        $review_id = $this->approvedSecondaryReview();
        $this->service->create_stale_fixture( $review_id, 'prval-stale-1', true, 'op-a' );
        $review_before = $this->reviews->rows[ $review_id ];
        $review_audits_before = count( $this->reviews->audit_rows );
        $fixture_audits_before = count( $this->fixtures->audit_rows );
        $assignments_before = $this->assignments->rows;

        $this->fixtures->fail_audit_insert = true;
        $report = $this->service->run_stale_validation( $this->context( 'prval-stale-1', $review_id, 1 ), true, 'op-a' );

        $this->assertFalse( $report['ok'] );
        $this->assertSame( 'stale_validation_fixture_audit_failed_rolled_back', $report['error'] );
        $review = $this->reviews->rows[ $review_id ];
        $this->assertSame( 'approved', $review['review_state'], 'The review stays approved.' );
        $this->assertSame( 'not_executed', $review['execution_state'], 'The review stays not_executed.' );
        $this->assertSame( $review_before, $review, 'The review row is byte-identical after rollback.' );
        $this->assertSame( $review_audits_before, count( $this->reviews->audit_rows ), 'No stale review-audit row remains.' );
        $this->assertSame( $fixture_audits_before, count( $this->fixtures->audit_rows ), 'No fixture validation audit row exists.' );
        $this->assertSame( $assignments_before, $this->assignments->rows, 'No assignment write occurs.' );
        $this->assertSame( 'ROLLBACK', end( $this->fixtures->transactions ), 'The outer transaction rolled back.' );

        // The unit stays runnable once the audit path works again.
        $this->fixtures->fail_audit_insert = false;
        $retry = $this->service->run_stale_validation( $this->context( 'prval-stale-1', $review_id, 1 ), true, 'op-a' );
        $this->assertTrue( $retry['ok'], 'Retry failed: ' . (string) ( $retry['error'] ?? '' ) );
        $this->assertSame( 'stale', $this->reviews->rows[ $review_id ]['execution_state'] );
    }

    public function test_refused_recovery_with_failed_refusal_audit_reports_refusal_audit_failed(): void {
        $review_id = $this->approvedSecondaryReview();
        $this->service->create_manual_fixture( $this->manualArgs( 1, 'prval-manual-1' ), true, 'op-a' );
        $this->executor->execute_approved( [ 'review_ids' => [ $review_id ] ], true );
        $fixture_audits_before = count( $this->fixtures->audit_rows );

        // Refusal (fixture still active) + refusal-audit failure: the
        // command never claims the refusal was audited.
        $this->fixtures->fail_audit_insert = true;
        $report = $this->service->recover_manual_review( $review_id, 'prval-manual-1', true, 'op-a' );

        $this->assertFalse( $report['ok'] );
        $this->assertSame( 'refusal_audit_failed:fixture_still_active_remove_fixture_first', $report['error'] );
        $this->assertSame( $fixture_audits_before, count( $this->fixtures->audit_rows ), 'No refusal audit row was persisted.' );
        $this->assertSame( 'skipped', $this->reviews->rows[ $review_id ]['execution_state'], 'Nothing was reset.' );
    }

    public function test_external_participation_never_leaks_into_normal_review_operations(): void {
        $review_id = $this->approvedSecondaryReview();
        $this->service->create_manual_fixture( $this->manualArgs( 1, 'prval-manual-1' ), true, 'op-a' );
        $this->executor->execute_approved( [ 'review_ids' => [ $review_id ] ], true );
        $this->service->remove_manual_fixture( 'prval-manual-1', true, 'op-a' );

        // A failed outer unit (audit failure) must leave participation OFF...
        $this->fixtures->fail_audit_insert = true;
        $this->service->recover_manual_review( $review_id, 'prval-manual-1', true, 'op-a' );
        $this->fixtures->fail_audit_insert = false;

        // ...so normal review operations keep their OWN atomicity: a normal
        // audited transition with a failing review-audit insert still rolls
        // itself back via the repository-owned inner transaction.
        $this->reviews->fail_audit = true;
        $failed = $this->reviews->mark_execution( $review_id, 'stale', 'normal_path_check', 'op-a', 'test' );
        $this->assertFalse( $failed['ok'], 'The normal inner transaction is real again and fails closed.' );
        $this->assertSame( 'skipped', $this->reviews->rows[ $review_id ]['execution_state'], 'The normal path rolled itself back.' );
        $this->reviews->fail_audit = false;

        $recovered = $this->service->recover_manual_review( $review_id, 'prval-manual-1', true, 'op-a' );
        $this->assertTrue( $recovered['ok'], 'Recovery works after participation cleanly ended: ' . (string) ( $recovered['error'] ?? '' ) );
    }

    // ══ Cross-cutting safety ══════════════════════════════════════════════

    public function test_validation_workflow_never_mutates_candidate_evidence(): void {
        $review_id = $this->approvedSecondaryReview();
        $evidence_before = $this->evidence->rows;

        $this->service->create_manual_fixture( $this->manualArgs( 1, 'prval-manual-1' ), true, 'op-a' );
        $this->service->create_stale_fixture( $review_id, 'prval-stale-1', true, 'op-a' );
        $this->service->run_stale_validation( $this->context( 'prval-stale-1', $review_id, 1 ), true, 'op-a' );
        $this->service->restore_stale_fixture( 'prval-stale-1', true, 'op-a' );
        $this->sync->sync( [ 'candidate_id' => 1 ] );
        $this->service->remove_manual_fixture( 'prval-manual-1', true, 'op-a' );
        $this->service->recover_manual_review( $review_id, 'prval-manual-1', true, 'op-a' );

        $this->assertSame( $evidence_before, $this->evidence->rows, 'Evidence (candidates, Rank Math, content, postmeta, imports) is never mutated.' );
        $this->assertSame( [], $this->assignments->candidate_writes, 'No candidate-table write is ever attempted.' );
    }

    public function test_status_lists_active_fixtures(): void {
        $review_id = $this->approvedSecondaryReview();
        $this->service->create_manual_fixture( $this->manualArgs( 2, 'prval-manual-1' ), true, 'op-a' );
        $this->service->create_stale_fixture( $review_id, 'prval-stale-1', true, 'op-a' );

        $status = $this->service->status();

        $this->assertTrue( $status['ok'] );
        $this->assertCount( 2, $status['active_fixtures'] );
        $this->assertSame( [ 'manual_assignment/active' => 1, 'stale_plan/active' => 1 ], $status['counts'] );

        $this->service->restore_stale_fixture( 'prval-stale-1', true, 'op-a' );
        $after = $this->service->status();
        $this->assertCount( 1, $after['active_fixtures'] );
        $this->assertSame( [ 'manual_assignment/active' => 1, 'stale_plan/restored' => 1 ], $after['counts'] );
    }
}
