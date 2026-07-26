<?php
/**
 * PR-E — review repository + sync service workflow tests.
 *
 * Proves: deterministic review identity, idempotent restartable sync,
 * preservation of human review state across re-syncs, stale detection when
 * the underlying plan changes, the full fail-closed review state machine
 * (including that rejected/deferred records are never auto-converted back to
 * pending), assignment-specific approval, report-only handling, candidate
 * immutability, and the append-only audit history.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordAssignmentMigrationAnalyzer as Analyzer;
use TMWSEO\Engine\Keywords\KeywordAssignmentMigrationService;
use TMWSEO\Engine\Keywords\KeywordAssignmentReviewRepository;
use TMWSEO\Engine\Keywords\KeywordAssignmentReviewSyncService;

require_once __DIR__ . '/support/review-test-doubles.php';

final class KeywordAssignmentReviewWorkflowTest extends TestCase {

    private ReviewFixtureEvidence $evidence;
    private ReviewFakeAssignmentRepository $assignments;
    private ReviewFakeRepository $reviews;
    private KeywordAssignmentMigrationService $migration;
    private KeywordAssignmentReviewSyncService $sync;

    protected function setUp(): void {
        parent::setUp();
        $this->evidence    = new ReviewFixtureEvidence();
        $this->assignments = new ReviewFakeAssignmentRepository();
        $this->reviews     = new ReviewFakeRepository();
        $this->migration   = new KeywordAssignmentMigrationService( $this->evidence, $this->assignments, new Analyzer() );
        $this->sync        = new KeywordAssignmentReviewSyncService( $this->migration, $this->reviews );
    }

    /** Clear-primary shaped evidence row (generic IDs, no production data). */
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

    /**
     * Primary + secondary planned actions for one keyword: canonical owner
     * on one page, secondary usage on another (the shape of the manually
     * migrated production test candidate, with generic IDs).
     */
    private function primarySecondaryRow( int $candidate_id, string $keyword ): array {
        return $this->evidenceRow( $candidate_id, $keyword, [
            'distinct_targets'  => [
                [ 'target_type' => 'tmw_category_page', 'target_id' => 500 + $candidate_id, 'target_name' => 'Primary Target' ],
                [ 'target_type' => 'tmw_category_page', 'target_id' => 900 + $candidate_id, 'target_name' => 'Secondary Target' ],
            ],
            'rankmath_presence' => [
                [ 'post_id' => 500 + $candidate_id, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ],
                [ 'post_id' => 900 + $candidate_id, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'extra' ],
            ],
            'content_presence'  => [
                [ 'post_id' => 500 + $candidate_id, 'present' => true ],
                [ 'post_id' => 900 + $candidate_id, 'present' => true ],
            ],
        ] );
    }

    /** unused_owner shaped row: owner resolves but keyword is used nowhere. */
    private function unusedOwnerRow( int $candidate_id, string $keyword ): array {
        return $this->evidenceRow( $candidate_id, $keyword, [
            'rankmath_presence' => [],
            'content_presence'  => [],
        ] );
    }

    /** manual_review shaped row: colliding page IDs across target types. */
    private function manualReviewRow( int $candidate_id, string $keyword ): array {
        return $this->evidenceRow( $candidate_id, $keyword, [
            'distinct_targets' => [
                [ 'target_type' => 'tmw_category_page', 'target_id' => 700, 'target_name' => 'Page A' ],
                [ 'target_type' => 'tmw_video_page', 'target_id' => 700, 'target_name' => 'Page B' ],
            ],
        ] );
    }

    // ── Deterministic identity ────────────────────────────────────────────

    public function test_review_identity_is_deterministic_and_excludes_snapshot_fields(): void {
        $base = [
            'migration_version'    => Analyzer::MIGRATION_VERSION,
            'keyword_candidate_id' => 11,
            'pool'                 => 'category',
            'page_type'            => 'tmw_category_page',
            'target_type'          => 'tmw_category_page',
            'target_id'            => 511,
            'target_key'           => 'tmw_category_page:511',
        ];
        $key_a = $this->reviews->review_key( $base );
        $key_b = $this->reviews->review_key( array_merge( $base, [ 'planned_role' => 'primary', 'planned_status' => 'approved', 'classification' => 'anything' ] ) );
        $this->assertSame( $key_a, $key_b, 'Identity must ignore snapshot-only fields (role, status, classification).' );
        $this->assertSame( 40, strlen( $key_a ) );

        $other_target = $this->reviews->review_key( array_merge( $base, [ 'target_id' => 512, 'target_key' => 'tmw_category_page:512' ] ) );
        $this->assertNotSame( $key_a, $other_target, 'Different targets must produce different identities.' );

        $snapshot_a = $this->reviews->snapshot_hash( array_merge( $base, [ 'planned_role' => 'primary' ] ) );
        $snapshot_b = $this->reviews->snapshot_hash( array_merge( $base, [ 'planned_role' => 'secondary' ] ) );
        $this->assertNotSame( $snapshot_a, $snapshot_b, 'Snapshot hash must pin the reviewed role.' );
    }

    // ── Sync: create, idempotent repeat, duplicate collapse ───────────────

    public function test_sync_creates_pending_records_and_repeat_is_idempotent(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ), $this->evidenceRow( 2, 'beta phrase' ) ];

        $first = $this->sync->sync();
        $this->assertSame( 2, $first['counts']['inserted'] );
        $this->assertSame( 0, $first['counts']['failed'] );
        $this->assertCount( 2, $this->reviews->rows );
        foreach ( $this->reviews->rows as $row ) {
            $this->assertSame( 'pending', $row['review_state'] );
            $this->assertSame( 'not_executed', $row['execution_state'] );
            $this->assertSame( 0, (int) $row['report_only'] );
            $this->assertSame( $this->reviews->snapshot_hash( $row ), (string) $row['snapshot_hash'], 'Stored snapshot hash must match stored snapshot fields.' );
        }

        $second = $this->sync->sync();
        $this->assertSame( 0, $second['counts']['inserted'], 'Repeat sync must not duplicate identities.' );
        $this->assertSame( 2, $second['counts']['unchanged'] );
        $this->assertCount( 2, $this->reviews->rows );
        $this->assertSame( 0, $this->assignments->next_id - 1, 'Sync must never write assignment rows.' );
    }

    public function test_duplicate_planned_input_collapses_to_one_record_per_identity(): void {
        // Two identical evidence rows for the same candidate → analyzer/sync
        // must still yield exactly one review record for the identity.
        $row = $this->evidenceRow( 3, 'gamma phrase' );
        $this->evidence->rows = [ $row, $row ];

        $report = $this->sync->sync();
        $identities = array_unique( array_map( fn ( $r ) => (string) $r['review_key'], $this->reviews->rows ) );
        $this->assertCount( count( $this->reviews->rows ), $identities, 'No duplicate review identities.' );
        $this->assertSame( 0, $report['counts']['failed'] );
    }

    // ── Sync preserves human review state; stale on change ────────────────

    public function test_repeated_sync_preserves_review_states_and_marks_stale_on_change(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ), $this->evidenceRow( 2, 'beta phrase' ), $this->evidenceRow( 3, 'gamma phrase' ) ];
        $this->sync->sync();

        $by_candidate = [];
        foreach ( $this->reviews->rows as $row ) { $by_candidate[ (int) $row['keyword_candidate_id'] ] = $row; }
        [ $first, $second, $third ] = [ $by_candidate[1], $by_candidate[2], $by_candidate[3] ];
        $this->assertTrue( $this->reviews->transition_review_state( (int) $first['id'], 'approved', 'op-a', '', 'test' )['ok'] );
        $this->assertTrue( $this->reviews->transition_review_state( (int) $second['id'], 'rejected', 'op-a', 'bad target', 'test' )['ok'] );
        $this->assertTrue( $this->reviews->transition_review_state( (int) $third['id'], 'deferred', 'op-a', '', 'test' )['ok'] );

        // Unchanged plan: all review states preserved.
        $report = $this->sync->sync();
        $this->assertSame( 3, $report['counts']['preserved'] );
        $this->assertSame( 'approved', $this->reviews->rows[ (int) $first['id'] ]['review_state'] );
        $this->assertSame( 'rejected', $this->reviews->rows[ (int) $second['id'] ]['review_state'] );
        $this->assertSame( 'deferred', $this->reviews->rows[ (int) $third['id'] ]['review_state'] );

        // Candidate 1's planned status/canonical flag changes → approved
        // record becomes stale; review state itself is preserved.
        $this->evidence->rows[0] = $this->evidenceRow( 1, 'alpha phrase', [ 'status' => 'pending_review' ] );
        $report = $this->sync->sync();
        $this->assertSame( 1, $report['counts']['stale'] );
        $stale = $this->reviews->rows[ (int) $first['id'] ];
        $this->assertSame( 'approved', $stale['review_state'], 'Review state preserved on stale.' );
        $this->assertSame( 'stale', $stale['execution_state'] );
        $this->assertStringContainsString( 'planned_action_changed:', (string) $stale['stale_reason'] );
        $this->assertStringContainsString( 'planned_status', (string) $stale['stale_reason'] );

        // Rejected/deferred records with a changed plan also go stale but are
        // NEVER auto-converted back to pending.
        $this->evidence->rows[1] = $this->evidenceRow( 2, 'beta phrase', [ 'status' => 'pending_review' ] );
        $this->evidence->rows[2] = $this->evidenceRow( 3, 'gamma phrase', [ 'status' => 'pending_review' ] );
        $this->sync->sync();
        $this->assertSame( 'rejected', $this->reviews->rows[ (int) $second['id'] ]['review_state'] );
        $this->assertSame( 'deferred', $this->reviews->rows[ (int) $third['id'] ]['review_state'] );
        $this->assertNotSame( 'pending', $this->reviews->rows[ (int) $second['id'] ]['review_state'] );
    }

    public function test_stale_record_restored_when_fresh_plan_matches_reviewed_snapshot_again(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        $this->sync->sync();
        $id = (int) array_values( $this->reviews->rows )[0]['id'];
        $this->reviews->transition_review_state( $id, 'approved', 'op-a', '', 'test' );

        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase', [ 'status' => 'pending_review' ] ) ];
        $this->sync->sync();
        $this->assertSame( 'stale', $this->reviews->rows[ $id ]['execution_state'] );

        // Plan reverts to exactly the reviewed snapshot → restored, still approved.
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        $report = $this->sync->sync();
        $this->assertSame( 1, $report['counts']['restored'] );
        $this->assertSame( 'not_executed', $this->reviews->rows[ $id ]['execution_state'] );
        $this->assertSame( 'approved', $this->reviews->rows[ $id ]['review_state'] );
        $this->assertSame( '', (string) $this->reviews->rows[ $id ]['stale_reason'] );
    }

    public function test_identity_no_longer_planned_is_marked_stale_only_on_full_sync(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ), $this->evidenceRow( 2, 'beta phrase' ) ];
        $this->sync->sync();
        $this->assertCount( 2, $this->reviews->rows );

        // Candidate 2 disappears from evidence; limit-truncated sync must NOT
        // mark anything stale (absence is unprovable from a partial analysis).
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        $report = $this->sync->sync( [ 'limit' => 1 ] );
        $this->assertFalse( $report['missing_check_ran'] );
        $this->assertSame( 0, $report['counts']['stale'] );

        // Full sync proves absence → stale with the missing reason.
        $report = $this->sync->sync();
        $this->assertTrue( $report['missing_check_ran'] );
        $this->assertSame( 1, $report['counts']['stale'] );
        $missing = array_values( array_filter( $this->reviews->rows, fn ( $row ) => 2 === (int) $row['keyword_candidate_id'] ) )[0];
        $this->assertSame( 'stale', $missing['execution_state'] );
        $this->assertSame( 'planned_action_no_longer_produced', $missing['stale_reason'] );
    }

    // ── Review state machine ──────────────────────────────────────────────

    public function test_review_state_machine_fails_closed(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        $this->sync->sync();
        $id = (int) array_values( $this->reviews->rows )[0]['id'];

        // pending → approved → deferred → approved → rejected are legal.
        $this->assertTrue( $this->reviews->transition_review_state( $id, 'approved', 'op-a', '', 'test' )['ok'] );
        $this->assertTrue( $this->reviews->transition_review_state( $id, 'deferred', 'op-a', '', 'test' )['ok'] );
        $this->assertTrue( $this->reviews->transition_review_state( $id, 'approved', 'op-a', '', 'test' )['ok'] );
        $this->assertTrue( $this->reviews->transition_review_state( $id, 'rejected', 'op-a', '', 'test' )['ok'] );

        // rejected → approved directly is refused; only reset-to-pending
        // reopens the record, and only as an explicit operator action.
        $refused = $this->reviews->transition_review_state( $id, 'approved', 'op-a', '', 'test' );
        $this->assertFalse( $refused['ok'] );
        $this->assertStringContainsString( 'transition_not_allowed_rejected_to_approved', (string) $refused['error'] );

        $reset = $this->reviews->transition_review_state( $id, 'pending', 'op-b', '', 'test' );
        $this->assertTrue( $reset['ok'] );
        $row = $this->reviews->rows[ $id ];
        $this->assertSame( 'pending', $row['review_state'] );
        $this->assertSame( '', (string) $row['reviewer'], 'Reset clears reviewer identity fields.' );
        $this->assertNull( $row['reviewed_at'] );

        // Unknown state names are refused outright.
        $this->assertFalse( $this->reviews->transition_review_state( $id, 'blessed', 'op-b', '', 'test' )['ok'] );
        // Unknown record IDs are refused outright.
        $this->assertFalse( $this->reviews->transition_review_state( 999999, 'approved', 'op-b', '', 'test' )['ok'] );
    }

    public function test_stale_record_cannot_be_approved_until_resynced(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        $this->sync->sync();
        $id = (int) array_values( $this->reviews->rows )[0]['id'];

        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase', [ 'status' => 'pending_review' ] ) ];
        $this->sync->sync(); // pending record: snapshot refreshed in place, not stale
        $this->assertSame( 'not_executed', $this->reviews->rows[ $id ]['execution_state'] );

        // Approve, then change the plan again → stale approved record.
        $this->reviews->transition_review_state( $id, 'approved', 'op-a', '', 'test' );
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        $this->sync->sync();
        $this->assertSame( 'stale', $this->reviews->rows[ $id ]['execution_state'] );

        // A stale record must not be re-approved (nor "approved" while stale
        // after a reset) without a resync proving the fresh plan.
        $this->reviews->transition_review_state( $id, 'pending', 'op-a', '', 'test' );
        $refused = $this->reviews->transition_review_state( $id, 'approved', 'op-a', '', 'test' );
        $this->assertFalse( $refused['ok'] );
        $this->assertSame( 'stale_record_requires_resync_before_approval', (string) $refused['error'] );

        // Resync refreshes the pending snapshot and clears stale → approvable.
        $this->sync->sync();
        $this->assertSame( 'not_executed', $this->reviews->rows[ $id ]['execution_state'] );
        $this->assertTrue( $this->reviews->transition_review_state( $id, 'approved', 'op-a', '', 'test' )['ok'] );
    }

    // ── Assignment-specific approval; candidate immutability ──────────────

    public function test_approval_is_assignment_specific_not_keyword_wide(): void {
        $this->evidence->rows = [ $this->primarySecondaryRow( 7, 'delta phrase' ) ];
        $this->sync->sync();
        $this->assertCount( 2, $this->reviews->rows, 'Primary and secondary planned actions are separate review records.' );

        $rows = array_values( $this->reviews->rows );
        $primary = array_values( array_filter( $rows, fn ( $r ) => 'primary' === $r['planned_role'] ) )[0];
        $secondary = array_values( array_filter( $rows, fn ( $r ) => 'secondary' === $r['planned_role'] ) )[0];

        $this->assertTrue( $this->reviews->transition_review_state( (int) $primary['id'], 'approved', 'op-a', '', 'test' )['ok'] );
        $this->assertSame( 'approved', $this->reviews->rows[ (int) $primary['id'] ]['review_state'] );
        $this->assertSame( 'pending', $this->reviews->rows[ (int) $secondary['id'] ]['review_state'], 'Sibling assignment of the same keyword stays untouched.' );
    }

    public function test_rejection_mutates_no_candidate_and_no_assignment_data(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        $this->sync->sync();
        $id = (int) array_values( $this->reviews->rows )[0]['id'];

        $result = $this->reviews->transition_review_state( $id, 'rejected', 'op-a', 'not wanted', 'test' );
        $this->assertTrue( $result['ok'] );
        $this->assertSame( [], $this->assignments->rows, 'Rejection writes no assignment rows.' );
        $this->assertSame( [], $this->assignments->candidate_writes, 'Rejection never touches candidate rows.' );
        $this->assertSame( 'rejected', $this->reviews->rows[ $id ]['review_state'] );
    }

    // ── Report-only classifications ───────────────────────────────────────

    public function test_report_only_classifications_excluded_by_default_and_never_approvable(): void {
        $this->evidence->rows = [ $this->manualReviewRow( 9, 'epsilon phrase' ), $this->evidenceRow( 1, 'alpha phrase' ) ];

        $this->sync->sync();
        $this->assertCount( 1, $this->reviews->rows, 'Non-writable classifications create no records by default.' );

        $this->sync->sync( [], true );
        $report_only = array_values( array_filter( $this->reviews->rows, fn ( $r ) => 1 === (int) $r['report_only'] ) );
        $this->assertNotEmpty( $report_only, 'Explicit include creates report-only records.' );
        foreach ( $report_only as $row ) {
            $this->assertSame( Analyzer::C_MANUAL_REVIEW, $row['classification'] );
            $refused = $this->reviews->transition_review_state( (int) $row['id'], 'approved', 'op-a', '', 'test' );
            $this->assertFalse( $refused['ok'] );
            $this->assertSame( 'report_only_record_cannot_be_approved', (string) $refused['error'] );
        }
    }

    public function test_unused_owner_is_approvable_for_recording(): void {
        $this->evidence->rows = [ $this->unusedOwnerRow( 4, 'zeta phrase' ) ];
        $this->sync->sync();
        $this->assertNotEmpty( $this->reviews->rows );
        $row = array_values( $this->reviews->rows )[0];
        $this->assertSame( Analyzer::C_UNUSED_OWNER, $row['classification'] );
        $this->assertSame( 0, (int) $row['report_only'] );
        $this->assertTrue( $this->reviews->transition_review_state( (int) $row['id'], 'approved', 'op-a', 'record legacy owner', 'test' )['ok'] );
    }

    // ── Audit history ─────────────────────────────────────────────────────

    public function test_audit_trail_answers_who_did_what_when(): void {
        $this->evidence->rows = [ $this->evidenceRow( 1, 'alpha phrase' ) ];
        $this->sync->sync();
        $id = (int) array_values( $this->reviews->rows )[0]['id'];

        $this->reviews->transition_review_state( $id, 'approved', 'reviewer-one', 'looks right', 'wp tmwseo keyword-assignment-review approve' );
        $this->reviews->transition_review_state( $id, 'rejected', 'reviewer-two', 'changed mind', 'wp tmwseo keyword-assignment-review reject' );
        $this->reviews->transition_review_state( $id, 'pending', 'reviewer-two', '', 'wp tmwseo keyword-assignment-review reset-to-pending' );

        $audit = $this->reviews->audit_for_review( $id );
        $actions = array_map( fn ( $row ) => (string) $row['action'], $audit );
        $this->assertSame( [ 'sync_create', 'approved', 'rejected', 'reset_to_pending' ], $actions );

        $approval = $audit[1];
        $this->assertSame( 'reviewer-one', $approval['actor'] );
        $this->assertSame( 'looks right', $approval['note'] );
        $this->assertSame( 'pending', $approval['old_review_state'] );
        $this->assertSame( 'approved', $approval['new_review_state'] );
        $this->assertSame( 'wp tmwseo keyword-assignment-review approve', $approval['source'] );
        $this->assertSame( 40, strlen( (string) $approval['snapshot_hash'] ), 'Audit pins the snapshot hash in force at mutation time.' );

        $reset = $audit[3];
        $this->assertSame( 'rejected', $reset['old_review_state'] );
        $this->assertSame( 'pending', $reset['new_review_state'] );
        $this->assertSame( 'reviewer-two', $reset['actor'], 'Reset clears the record fields but the audit keeps the actor forever.' );
    }
}
