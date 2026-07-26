<?php
/**
 * PR-E — review execution + export tests.
 *
 * Proves: dry-run default writes absolutely nothing; only approved,
 * non-stale, non-report-only, executable-classification records execute;
 * per-record snapshot re-verification marks changed plans stale instead of
 * executing them; manual assignments are preserved; execution is idempotent
 * and restartable after interruption; unused_owner approvals are refused at
 * execution without state change; explicit-ID and filtered execution;
 * candidate/Rank Math/content/postmeta immutability; deterministic JSON/CSV
 * export with unsafe extensions refused.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordAssignmentMigrationAnalyzer as Analyzer;
use TMWSEO\Engine\Keywords\KeywordAssignmentMigrationService;
use TMWSEO\Engine\Keywords\KeywordAssignmentReviewExecutionService;
use TMWSEO\Engine\Keywords\KeywordAssignmentReviewExportService;
use TMWSEO\Engine\Keywords\KeywordAssignmentReviewSyncService;

require_once __DIR__ . '/support/review-test-doubles.php';

final class KeywordAssignmentReviewExecutionTest extends TestCase {

    private ReviewFixtureEvidence $evidence;
    private ReviewFakeAssignmentRepository $assignments;
    private ReviewFakeRepository $reviews;
    private KeywordAssignmentMigrationService $migration;
    private KeywordAssignmentReviewSyncService $sync;
    private KeywordAssignmentReviewExecutionService $executor;

    protected function setUp(): void {
        parent::setUp();
        $this->evidence    = new ReviewFixtureEvidence();
        $this->assignments = new ReviewFakeAssignmentRepository();
        $this->reviews     = new ReviewFakeRepository();
        $this->migration   = new KeywordAssignmentMigrationService( $this->evidence, $this->assignments, new Analyzer() );
        $this->sync        = new KeywordAssignmentReviewSyncService( $this->migration, $this->reviews );
        $this->executor    = new KeywordAssignmentReviewExecutionService( $this->migration, $this->reviews, $this->sync, $this->assignments );
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

    /** @return array<string,mixed> row for candidate id from the review store */
    private function reviewFor( int $candidate_id, string $role = 'primary' ): array {
        foreach ( $this->reviews->rows as $row ) {
            if ( (int) $row['keyword_candidate_id'] === $candidate_id && (string) $row['planned_role'] === $role ) { return $row; }
        }
        $this->fail( 'No review record for candidate ' . $candidate_id );
    }

    private function approve( int $review_id ): void {
        $result = $this->reviews->transition_review_state( $review_id, 'approved', 'op-a', '', 'test' );
        $this->assertTrue( $result['ok'], 'Approval failed: ' . (string) ( $result['error'] ?? '' ) );
    }

    // ── Dry run writes nothing at all ─────────────────────────────────────

    public function test_dry_run_default_writes_nothing_anywhere(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        $this->sync->sync();
        $this->approve( (int) $this->reviewFor( 1 )['id'] );
        $rows_before = $this->reviews->rows;
        $audit_before = count( $this->reviews->audit_rows );

        $report = $this->executor->execute_approved(); // no $execute flag

        $this->assertSame( 'execute-approved-dry-run', $report['mode'] );
        $this->assertSame( 1, $report['counts']['selected'] );
        $this->assertSame( 'would_execute', $report['results'][0]['outcome'] );
        $this->assertSame( [], $this->assignments->rows, 'Dry run must write no assignment rows.' );
        $this->assertSame( $rows_before, $this->reviews->rows, 'Dry run must not mutate review records.' );
        $this->assertSame( $audit_before, count( $this->reviews->audit_rows ), 'Dry run must not write audit rows.' );
    }

    // ── Only approved records execute ─────────────────────────────────────

    public function test_only_approved_records_are_selected_for_execution(): void {
        $this->evidence->rows = [
            $this->evidenceRow( 1, 'alpha phrase' ),
            $this->evidenceRow( 2, 'beta phrase' ),
            $this->evidenceRow( 3, 'gamma phrase' ),
            $this->evidenceRow( 4, 'delta phrase' ),
        ];
        $this->sync->sync();
        $this->approve( (int) $this->reviewFor( 1 )['id'] );
        $this->reviews->transition_review_state( (int) $this->reviewFor( 2 )['id'], 'rejected', 'op-a', '', 'test' );
        $this->reviews->transition_review_state( (int) $this->reviewFor( 3 )['id'], 'deferred', 'op-a', '', 'test' );
        // candidate 4 stays pending

        $report = $this->executor->execute_approved( [], true );

        $this->assertSame( 1, $report['counts']['selected'], 'Pending, rejected, and deferred records are never selected.' );
        $this->assertSame( 1, $report['counts']['executed'] );
        $this->assertCount( 1, $this->assignments->rows );
        $assignment = array_values( $this->assignments->rows )[0];
        $this->assertSame( 1, (int) $assignment['keyword_candidate_id'] );
        $this->assertSame( 'primary', $assignment['role'] );
        $this->assertSame( 1, (int) $assignment['canonical_owner'] );
        $this->assertSame( 'executed', $this->reviews->rows[ (int) $this->reviewFor( 1 )['id'] ]['execution_state'] );
        $this->assertSame( 'not_executed', $this->reviews->rows[ (int) $this->reviewFor( 4 )['id'] ]['execution_state'] );
        $this->assertSame( [], $this->assignments->candidate_writes, 'Execution never touches candidate rows.' );
    }

    public function test_stale_records_never_execute(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        $this->sync->sync();
        $id = (int) $this->reviewFor( 1 )['id'];
        $this->approve( $id );
        // Plan changes after approval; sync marks the record stale.
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase', [ 'status' => 'pending_review' ] ) ];
        $this->sync->sync();
        $this->assertSame( 'stale', $this->reviews->rows[ $id ]['execution_state'] );

        $report = $this->executor->execute_approved( [], true );
        $this->assertSame( 0, $report['counts']['selected'], 'Stale records are excluded from selection.' );
        $this->assertSame( [], $this->assignments->rows );
    }

    // ── Snapshot re-verification at execution time ────────────────────────

    public function test_plan_changed_between_approval_and_execution_goes_stale_not_executed(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        $this->sync->sync();
        $id = (int) $this->reviewFor( 1 )['id'];
        $this->approve( $id );

        // The world changes AFTER approval and WITHOUT a resync: execution's
        // own re-verification must catch it.
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase', [ 'status' => 'pending_review' ] ) ];
        $report = $this->executor->execute_approved( [], true );

        $this->assertSame( 1, $report['counts']['selected'] );
        $this->assertSame( 1, $report['counts']['stale'] );
        $this->assertSame( 0, $report['counts']['executed'] );
        $this->assertSame( 'stale', $report['results'][0]['outcome'] );
        $this->assertStringContainsString( 'planned_status', (string) $report['results'][0]['reason'] );
        $this->assertSame( 'stale', $this->reviews->rows[ $id ]['execution_state'] );
        $this->assertSame( 'approved', $this->reviews->rows[ $id ]['review_state'], 'Review state preserved on execution-time stale.' );
        $this->assertSame( [], $this->assignments->rows, 'Nothing is written for a stale record.' );
    }

    public function test_identity_no_longer_planned_at_execution_goes_stale(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        $this->sync->sync();
        $id = (int) $this->reviewFor( 1 )['id'];
        $this->approve( $id );

        $this->evidence->rows = []; // identity vanishes entirely
        $report = $this->executor->execute_approved( [], true );

        $this->assertSame( 1, $report['counts']['stale'] );
        $this->assertSame( 'planned_action_no_longer_produced', (string) $report['results'][0]['reason'] );
        $this->assertSame( 'stale', $this->reviews->rows[ $id ]['execution_state'] );
        $this->assertSame( [], $this->assignments->rows );
    }

    // ── Manual preservation and idempotent no-ops ─────────────────────────

    public function test_manual_assignment_is_preserved_and_recorded_as_skipped(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        // Pre-existing MANUAL assignment on the exact planned identity.
        $manual = $this->assignments->create_assignment( [
            'keyword_candidate_id' => 1,
            'pool'                 => 'category',
            'page_type'            => 'tmw_category_page',
            'target_type'          => 'tmw_category_page',
            'target_id'            => 501,
            'target_key'           => 'tmw_category_page:501',
            'role'                 => 'primary',
            'status'               => 'approved',
            'canonical_owner'      => 1,
            'source_type'          => 'manual',
        ] );
        $this->assertTrue( $manual['ok'] );
        $manual_row_before = $this->assignments->rows[ (int) $manual['id'] ];

        $this->sync->sync();
        $id = (int) $this->reviewFor( 1 )['id'];
        $this->assertSame( 'preserve', $this->reviews->rows[ $id ]['planned_action'] );
        $this->approve( $id );

        $report = $this->executor->execute_approved( [], true );

        $this->assertSame( 1, $report['counts']['skipped'] );
        $this->assertSame( 'skipped_manual_assignment_preserved', $report['results'][0]['outcome'] );
        $this->assertSame( 'skipped', $this->reviews->rows[ $id ]['execution_state'] );
        $this->assertSame( 'manual_assignment_preserved', $this->reviews->rows[ $id ]['execution_result'] );
        $this->assertSame( $manual_row_before, $this->assignments->rows[ (int) $manual['id'] ], 'Manual assignment row is byte-identical after execution.' );
        $this->assertCount( 1, $this->assignments->rows, 'No second row was created for the manual identity.' );
    }

    public function test_re_execution_is_idempotent_end_to_end(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        $this->sync->sync();
        $id = (int) $this->reviewFor( 1 )['id'];
        $this->approve( $id );

        $first = $this->executor->execute_approved( [], true );
        $this->assertSame( 1, $first['counts']['executed'] );
        $this->assertCount( 1, $this->assignments->rows );
        $assignment_after_first = $this->assignments->rows;

        // Executed records are excluded from selection: nothing to re-run.
        $second = $this->executor->execute_approved( [], true );
        $this->assertSame( 0, $second['counts']['selected'] );
        $this->assertSame( $assignment_after_first, $this->assignments->rows, 'Repeat execution changes nothing.' );

        // A resync after execution sees the fresh action as 'unchanged' and
        // preserves the executed record untouched.
        $report = $this->sync->sync();
        $this->assertSame( 1, $report['counts']['preserved'] );
        $this->assertSame( 'executed', $this->reviews->rows[ $id ]['execution_state'] );

        // Review state of an executed record is immutable history.
        $refused = $this->reviews->transition_review_state( $id, 'rejected', 'op-a', '', 'test' );
        $this->assertFalse( $refused['ok'] );
        $this->assertSame( 'executed_record_is_immutable', (string) $refused['error'] );
    }

    // ── Interrupted execution restarts safely ─────────────────────────────

    public function test_interrupted_execution_is_restartable(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ), $this->evidenceRow( 2, 'beta phrase' ) ];
        $this->sync->sync();
        $this->approve( (int) $this->reviewFor( 1 )['id'] );
        $this->approve( (int) $this->reviewFor( 2 )['id'] );

        $this->assignments->fail_after_inserts = 1; // simulate crash mid-run
        $first = $this->executor->execute_approved( [], true );
        $this->assertSame( 1, $first['counts']['executed'] );
        $this->assertSame( 1, $first['counts']['failed'] );
        $failed = array_values( array_filter( $this->reviews->rows, fn ( $row ) => 'failed' === $row['execution_state'] ) );
        $this->assertCount( 1, $failed );
        $this->assertSame( 'simulated_interruption', $failed[0]['execution_result'] );

        // Restart: the failed record is retryable, the executed one is not
        // re-run, and no duplicates appear.
        $this->assignments->fail_after_inserts = PHP_INT_MAX;
        $second = $this->executor->execute_approved( [], true );
        $this->assertSame( 1, $second['counts']['selected'], 'Only the failed record is selected on restart.' );
        $this->assertSame( 1, $second['counts']['executed'] );
        $this->assertCount( 2, $this->assignments->rows );
        $keys = array_map( fn ( $row ) => (string) $row['assignment_key'], $this->assignments->rows );
        $this->assertCount( 2, array_unique( $keys ), 'No duplicate assignment identities after restart.' );
    }

    // ── Classification execution policy ───────────────────────────────────

    public function test_approved_unused_owner_is_refused_at_execution_without_state_change(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase', [ 'rankmath_presence' => [], 'content_presence' => [] ] ) ];
        $this->sync->sync();
        $row = $this->reviewFor( 1 );
        $this->assertSame( Analyzer::C_UNUSED_OWNER, $row['classification'] );
        $this->approve( (int) $row['id'] ); // approvable for recording

        $report = $this->executor->execute_approved( [], true );

        $this->assertSame( 1, $report['counts']['refused_classification'] );
        $this->assertSame( 0, $report['counts']['executed'] );
        $this->assertSame( 'refused_classification_not_executable', $report['results'][0]['outcome'] );
        $this->assertSame( 'not_executed', $this->reviews->rows[ (int) $row['id'] ]['execution_state'], 'Refusal changes no state.' );
        $this->assertSame( 'approved', $this->reviews->rows[ (int) $row['id'] ]['review_state'] );
        $this->assertSame( [], $this->assignments->rows, 'unused_owner is never bulk-activated.' );
    }

    public function test_report_only_records_can_never_execute(): void {
        // Colliding page IDs → manual_review_required, synced as report-only.
        $this->evidence->rows = [ $this->evidenceRow( 9, 'epsilon phrase', [
            'distinct_targets' => [
                [ 'target_type' => 'tmw_category_page', 'target_id' => 700, 'target_name' => 'Page A' ],
                [ 'target_type' => 'tmw_video_page', 'target_id' => 700, 'target_name' => 'Page B' ],
            ],
        ] ) ];
        $this->sync->sync( [], true );
        $this->assertNotEmpty( $this->reviews->rows );
        foreach ( $this->reviews->rows as $row ) {
            $this->assertSame( 1, (int) $row['report_only'] );
        }
        $report = $this->executor->execute_approved( [], true );
        $this->assertSame( 0, $report['counts']['selected'], 'Report-only records are excluded at the query level.' );
        $this->assertSame( [], $this->assignments->rows );
    }

    // ── Explicit IDs and filters ──────────────────────────────────────────

    public function test_explicit_id_and_candidate_filter_narrow_execution(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ), $this->evidenceRow( 2, 'beta phrase' ) ];
        $this->sync->sync();
        $id_one = (int) $this->reviewFor( 1 )['id'];
        $id_two = (int) $this->reviewFor( 2 )['id'];
        $this->approve( $id_one );
        $this->approve( $id_two );

        $report = $this->executor->execute_approved( [ 'review_ids' => [ $id_one ] ], true );
        $this->assertSame( 1, $report['counts']['selected'] );
        $this->assertSame( 'executed', $this->reviews->rows[ $id_one ]['execution_state'] );
        $this->assertSame( 'not_executed', $this->reviews->rows[ $id_two ]['execution_state'] );

        $report = $this->executor->execute_approved( [ 'candidate_id' => 2 ], true );
        $this->assertSame( 1, $report['counts']['selected'] );
        $this->assertSame( 'executed', $this->reviews->rows[ $id_two ]['execution_state'] );
        $this->assertCount( 2, $this->assignments->rows );
    }

    // ── Export ────────────────────────────────────────────────────────────

    public function test_export_json_and_csv_are_deterministic_and_safe(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ), $this->evidenceRow( 2, 'beta phrase' ) ];
        $this->sync->sync();
        $export = new KeywordAssignmentReviewExportService();
        $rows = $this->reviews->list_reviews();

        $json = $export->to_json( $rows );
        $decoded = json_decode( $json, true );
        $this->assertSame( Analyzer::MIGRATION_VERSION, $decoded['migration_version'] );
        $this->assertSame( 2, $decoded['record_count'] );
        $this->assertSame( KeywordAssignmentReviewExportService::EXPORT_COLUMNS, $decoded['columns'] );
        $this->assertSame( array_keys( $decoded['records'][0] ), KeywordAssignmentReviewExportService::EXPORT_COLUMNS, 'Fixed column order in every record.' );
        $this->assertSame( $json, $export->to_json( $this->reviews->list_reviews() ), 'Repeat export is byte-identical.' );

        $csv = $export->to_csv( $rows );
        $lines = array_values( array_filter( explode( "\n", trim( $csv ) ) ) );
        $this->assertCount( 3, $lines, 'Header plus one line per record.' );
        $this->assertStringStartsWith( 'id,review_key,migration_version', $lines[0] );

        // Unsafe output extensions are refused; only .json/.csv allowed.
        $this->assertTrue( $export->is_safe_output_path( '/tmp/review.json' ) );
        $this->assertTrue( $export->is_safe_output_path( '/tmp/review.CSV' ) );
        $this->assertFalse( $export->is_safe_output_path( '/tmp/review.php' ) );
        $this->assertFalse( $export->is_safe_output_path( '/tmp/review.phtml' ) );
        $this->assertFalse( $export->is_safe_output_path( '/tmp/review' ) );
        $this->assertSame( '', $export->format_for_path( '/tmp/review.php' ) );
    }
}
