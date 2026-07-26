<?php
/**
 * PR-D — KeywordAssignmentMigrationAnalyzer classification tests.
 *
 * Pure unit tests: evidence fixtures in the exact shape produced by
 * KeywordOwnershipReportService::run() are fed to the analyzer; every
 * classification rule, tie-break refusal, and planning decision is asserted
 * deterministically. No database, no wpdb.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordAssignmentMigrationAnalyzer as Analyzer;

require_once __DIR__ . '/../includes/keywords/class-keyword-assignment-repository.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-assignment-migration-analyzer.php';

final class KeywordAssignmentMigrationAnalyzerTest extends TestCase {

    private Analyzer $analyzer;

    protected function setUp(): void {
        parent::setUp();
        $this->analyzer = new Analyzer();
    }

    // ── Evidence fixture builder (generic data only) ──────────────────────

    /** @return array<string,mixed> ownership-report-shaped evidence row */
    private function evidence( array $overrides = [] ): array {
        return array_merge( [
            'candidate_id'       => 10,
            'keyword'            => 'alpha generic phrase',
            'normalized_keyword' => 'alpha generic phrase',
            'status'             => 'approved',
            'intent_type'        => 'category',
            'entity_id'          => 0,
            'target_type'        => 'tmw_category_page',
            'target_id'          => 501,
            'target_name'        => 'Target A',
            'import_rows'        => [],
            'distinct_targets'   => [ $this->target( 'tmw_category_page', 501, 'Target A' ) ],
            'rankmath_presence'  => [],
            'content_presence'   => [],
            'target_unresolvable'=> [],
            'postmeta_ownership' => [],
        ], $overrides );
    }

    private function target( string $type, int $id, string $name ): array {
        return [ 'target_type' => $type, 'target_id' => $id, 'target_name' => $name, 'source' => 'candidate_row' ];
    }

    private function importRow( string $type, int $id, string $status, int $batch_id = 1, string $pool = 'category' ): array {
        return [ 'row_id' => 100 + $batch_id, 'batch_id' => $batch_id, 'pool' => $pool, 'batch_target_type' => $type, 'batch_target_id' => $id, 'batch_target_name' => 'Target ' . $id, 'row_status' => $status ];
    }

    private function primaryAction( array $decision ): ?array {
        foreach ( (array) $decision['planned_actions'] as $action ) {
            if ( 'primary' === (string) ( $action['payload']['role'] ?? '' ) ) { return $action; }
        }
        return null;
    }

    // ── 5. Strong primary + valid secondary ───────────────────────────────

    public function test_strong_primary_with_valid_secondary(): void {
        $decision = $this->analyzer->analyze( $this->evidence( [
            'distinct_targets'  => [ $this->target( 'tmw_category_page', 501, 'A' ), $this->target( 'tmw_category_page', 502, 'B' ) ],
            'rankmath_presence' => [ [ 'post_id' => 501, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ] ],
            'content_presence'  => [ [ 'post_id' => 501, 'present' => true ] ],
            'import_rows'       => [ $this->importRow( 'tmw_category_page', 502, 'approved', 2 ) ],
        ] ) );

        $this->assertSame( Analyzer::C_SECONDARY, $decision['classification'] );
        $this->assertTrue( $decision['writable'] );
        $primary = $this->primaryAction( $decision );
        $this->assertSame( 501, $primary['payload']['target_id'] );
        $this->assertSame( 1, $primary['payload']['canonical_owner'] );
        $this->assertSame( 'approved', $primary['payload']['status'] );
        $this->assertSame( 'migration_candidate', $primary['payload']['source_type'] );
        $secondary = $decision['planned_actions'][1];
        $this->assertSame( 502, $secondary['payload']['target_id'] );
        $this->assertSame( 'secondary', $secondary['payload']['role'] );
        $this->assertSame( 'review_required', $secondary['payload']['status'], 'Secondary use is proposed, never auto-approved.' );
        $this->assertSame( 'migration_import', $secondary['payload']['source_type'] );
    }

    // ── 6 & 27. Equal evidence → conflict, never row-order winner ─────────

    public function test_two_equally_strong_owners_conflict_regardless_of_order(): void {
        $base = [
            'target_type' => '', 'target_id' => 0, // no recorded candidate owner
            'rankmath_presence' => [
                [ 'post_id' => 501, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ],
                [ 'post_id' => 502, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ],
            ],
            'content_presence' => [ [ 'post_id' => 501, 'present' => true ], [ 'post_id' => 502, 'present' => true ] ],
        ];
        $forward  = $this->analyzer->analyze( $this->evidence( $base + [ 'distinct_targets' => [ $this->target( 'tmw_category_page', 501, 'A' ), $this->target( 'tmw_category_page', 502, 'B' ) ] ] ) );
        $backward = $this->analyzer->analyze( $this->evidence( $base + [ 'distinct_targets' => [ $this->target( 'tmw_category_page', 502, 'B' ), $this->target( 'tmw_category_page', 501, 'A' ) ] ] ) );

        $this->assertSame( Analyzer::C_CONFLICT, $forward['classification'] );
        $this->assertSame( Analyzer::C_CONFLICT, $backward['classification'], 'Source ordering must not change the decision.' );
        $this->assertFalse( $forward['writable'] );
        $this->assertSame( [], $forward['planned_actions'] );
        // Deterministic target ordering in the report itself.
        $this->assertSame( 501, $forward['targets'][0]['target_id'] );
        $this->assertSame( 501, $backward['targets'][0]['target_id'] );
    }

    // ── 7. Stale legacy ownership ─────────────────────────────────────────

    public function test_stale_owner_reported_not_reassigned(): void {
        $decision = $this->analyzer->analyze( $this->evidence( [
            'status'            => 'queued_for_review',
            'distinct_targets'  => [ $this->target( 'tmw_category_page', 501, 'Legacy' ), $this->target( 'tmw_category_page', 502, 'Active' ) ],
            // Legacy owner 501: no rankmath, no content. Active 502: full usage.
            'rankmath_presence' => [ [ 'post_id' => 502, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ], [ 'post_id' => 501, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'absent' ] ],
            'content_presence'  => [ [ 'post_id' => 502, 'present' => true ], [ 'post_id' => 501, 'present' => false ] ],
            'import_rows'       => [ $this->importRow( 'tmw_category_page', 502, 'approved', 3 ) ],
        ] ) );

        $this->assertSame( Analyzer::C_STALE_OWNER, $decision['classification'] );
        $this->assertFalse( $decision['writable'], 'Ownership reassignment is never automated.' );
        $this->assertSame( [], $decision['planned_actions'] );
        $this->assertStringContainsString( 'recorded_owner_without_usage_outscored_by_tmw_category_page:502', implode( ',', $decision['reasons'] ) );
    }

    // ── 8 & 11. Unused owner: resolves, but no usage anywhere ─────────────

    public function test_unused_owner_is_mirrored_and_flagged(): void {
        $decision = $this->analyzer->analyze( $this->evidence( [
            'rankmath_presence' => [ [ 'post_id' => 501, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'absent' ] ],
            'content_presence'  => [ [ 'post_id' => 501, 'present' => false ] ],
        ] ) );

        $this->assertSame( Analyzer::C_UNUSED_OWNER, $decision['classification'] );
        $this->assertTrue( $decision['writable'], 'Current production ownership is mirrored, flagged for cleanup.' );
        $primary = $this->primaryAction( $decision );
        $this->assertSame( Analyzer::C_UNUSED_OWNER, $primary['payload']['conflict_reason'] );
        $this->assertSame( 0, $primary['payload']['active_in_rank_math'] );
        $this->assertSame( 0, $primary['payload']['present_in_content'] );
    }

    // ── 9 & 10. Rank Math and content scored independently ────────────────

    public function test_rankmath_present_content_absent_and_inverse(): void {
        $rankmath_only = $this->analyzer->analyze( $this->evidence( [
            'rankmath_presence' => [ [ 'post_id' => 501, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ] ],
            'content_presence'  => [ [ 'post_id' => 501, 'present' => false ] ],
        ] ) );
        $content_only = $this->analyzer->analyze( $this->evidence( [
            'rankmath_presence' => [ [ 'post_id' => 501, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'absent' ] ],
            'content_presence'  => [ [ 'post_id' => 501, 'present' => true ] ],
        ] ) );

        foreach ( [ $rankmath_only, $content_only ] as $decision ) {
            $this->assertSame( Analyzer::C_CLEAR_PRIMARY, $decision['classification'] );
        }
        $rm = $this->primaryAction( $rankmath_only )['payload'];
        $ct = $this->primaryAction( $content_only )['payload'];
        $this->assertSame( [ 1, 0 ], [ $rm['active_in_rank_math'], $rm['present_in_content'] ] );
        $this->assertSame( [ 0, 1 ], [ $ct['active_in_rank_math'], $ct['present_in_content'] ] );
        $this->assertSame( 'primary', $rankmath_only['targets'][0]['evidence']['rankmath_role'] );
        $this->assertTrue( $content_only['targets'][0]['evidence']['content_evaluated'] );
    }

    // ── 12 & 13. Per-target approval/rejection never becomes global ───────

    public function test_per_target_rejection_stays_per_target(): void {
        $decision = $this->analyzer->analyze( $this->evidence( [
            'distinct_targets'  => [ $this->target( 'tmw_category_page', 501, 'A' ), $this->target( 'tmw_category_page', 502, 'B' ) ],
            'rankmath_presence' => [ [ 'post_id' => 501, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ] ],
            'content_presence'  => [ [ 'post_id' => 501, 'present' => true ] ],
            'import_rows'       => [
                $this->importRow( 'tmw_category_page', 501, 'approved', 4 ),
                $this->importRow( 'tmw_category_page', 502, 'rejected', 5 ),
            ],
        ] ) );

        $this->assertSame( Analyzer::C_SECONDARY, $decision['classification'] );
        $primary = $this->primaryAction( $decision );
        $this->assertSame( 'approved', $primary['payload']['status'], 'Primary keeps its approval.' );
        $rejected_secondary = $decision['planned_actions'][1];
        $this->assertSame( 502, $rejected_secondary['payload']['target_id'] );
        $this->assertSame( 'rejected', $rejected_secondary['payload']['status'], 'Rejection is recorded per target only.' );
        $this->assertSame( 0, $rejected_secondary['payload']['active_in_rank_math'] );
    }

    // ── 14 & 15. Unresolved and unsupported targets ───────────────────────

    public function test_unresolved_target_keeps_source_info(): void {
        $decision = $this->analyzer->analyze( $this->evidence( [
            'target_unresolvable' => [ 501 ],
        ] ) );

        $this->assertSame( Analyzer::C_UNRESOLVED, $decision['classification'] );
        $this->assertFalse( $decision['writable'] );
        $this->assertSame( 501, $decision['targets'][0]['target_id'] );
        $this->assertSame( 'tmw_category_page', $decision['targets'][0]['target_type'] );
        $this->assertTrue( $decision['targets'][0]['unresolved'] );
    }

    public function test_unsupported_zero_id_target_is_classified_not_coerced(): void {
        $decision = $this->analyzer->analyze( $this->evidence( [
            'target_type'      => 'mystery_type',
            'target_id'        => 0,
            'distinct_targets' => [ $this->target( 'mystery_type', 0, 'Unknown' ) ],
        ] ) );

        $this->assertSame( Analyzer::C_UNRESOLVED, $decision['classification'] );
        $this->assertFalse( $decision['writable'] );
    }

    // ── 29. Target IDs colliding across target types ──────────────────────

    public function test_colliding_target_ids_across_types_require_manual_review(): void {
        $decision = $this->analyzer->analyze( $this->evidence( [
            'distinct_targets'  => [ $this->target( 'tmw_category_page', 501, 'A' ), $this->target( 'tmw_video_page', 501, 'V' ) ],
            'rankmath_presence' => [ [ 'post_id' => 501, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ] ],
            'content_presence'  => [ [ 'post_id' => 501, 'present' => true ] ],
        ] ) );

        $this->assertSame( Analyzer::C_MANUAL_REVIEW, $decision['classification'] );
        $this->assertFalse( $decision['writable'] );
        $this->assertContains( 'page_evidence_ambiguous_across_target_types', $decision['reasons'] );
    }

    // ── 1, 2, 28. Duplicate rows collapse onto one identity/plan ──────────

    public function test_duplicate_import_rows_produce_single_planned_assignment(): void {
        $decision = $this->analyzer->analyze( $this->evidence( [
            'rankmath_presence' => [ [ 'post_id' => 501, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ] ],
            'content_presence'  => [ [ 'post_id' => 501, 'present' => true ] ],
            'import_rows'       => [
                $this->importRow( 'tmw_category_page', 501, 'approved', 6 ),
                $this->importRow( 'tmw_category_page', 501, 'approved', 6 ),   // duplicate in batch
                $this->importRow( 'tmw_category_page', 501, 'approved', 7 ),   // duplicate cross batch
            ],
        ] ) );

        $this->assertSame( Analyzer::C_CLEAR_PRIMARY, $decision['classification'] );
        $this->assertCount( 1, $decision['planned_actions'], 'Duplicates collapse onto one assignment identity.' );
    }

    // ── 3 & 4. Shared candidate across targets and pools ──────────────────

    public function test_cross_pool_use_plans_pool_specific_rows(): void {
        $decision = $this->analyzer->analyze( $this->evidence( [
            'distinct_targets'  => [ $this->target( 'tmw_category_page', 501, 'A' ), $this->target( 'global', 0, 'Model Pool' ) ],
            'rankmath_presence' => [ [ 'post_id' => 501, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ] ],
            'content_presence'  => [ [ 'post_id' => 501, 'present' => true ] ],
            'import_rows'       => [ $this->importRow( 'global', 0, 'approved', 8, 'model' ) ],
        ] ) );

        $this->assertSame( Analyzer::C_SECONDARY, $decision['classification'] );
        $global_secondary = $decision['planned_actions'][1];
        $this->assertSame( 'global-model-pool', $global_secondary['payload']['target_key'], 'Global scope keeps a deterministic key.' );
        $this->assertSame( 'model', $global_secondary['payload']['pool'] );
        $this->assertSame( 'category', $this->primaryAction( $decision )['payload']['pool'] );
    }

    // ── 16 & 18. Existing manual assignments veto/persist ─────────────────

    public function test_existing_manual_primary_on_other_target_forces_conflict(): void {
        $existing = [ [ 'id' => 77, 'keyword_candidate_id' => 10, 'pool' => 'category', 'page_type' => 'tmw_category_page', 'target_type' => 'tmw_category_page', 'target_id' => 777, 'target_key' => 'tmw_category_page:777', 'role' => 'primary', 'canonical_owner' => 1, 'status' => 'approved', 'source_type' => 'manual' ] ];
        $decision = $this->analyzer->analyze( $this->evidence( [
            'rankmath_presence' => [ [ 'post_id' => 501, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ] ],
            'content_presence'  => [ [ 'post_id' => 501, 'present' => true ] ],
        ] ), $existing );

        $this->assertSame( Analyzer::C_CONFLICT, $decision['classification'] );
        $this->assertFalse( $decision['writable'] );
        $this->assertStringContainsString( 'existing_manual_primary_on_tmw_category_page:777', implode( ',', $decision['reasons'] ) );
    }

    public function test_existing_manual_assignment_on_same_target_is_preserved(): void {
        $existing = [ [ 'id' => 78, 'keyword_candidate_id' => 10, 'pool' => 'category', 'page_type' => 'tmw_category_page', 'target_type' => 'tmw_category_page', 'target_id' => 501, 'target_key' => 'tmw_category_page:501', 'role' => 'primary', 'canonical_owner' => 1, 'status' => 'approved', 'source_type' => 'manual' ] ];
        $decision = $this->analyzer->analyze( $this->evidence( [
            'rankmath_presence' => [ [ 'post_id' => 501, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ] ],
            'content_presence'  => [ [ 'post_id' => 501, 'present' => true ] ],
        ] ), $existing );

        $this->assertSame( Analyzer::C_CLEAR_PRIMARY, $decision['classification'] );
        $this->assertSame( 'preserve', $decision['planned_actions'][0]['action'], 'Manual state is never overwritten by migration evidence.' );
    }

    // ── 17. Existing manual secondary preserved alongside new primary ─────

    public function test_existing_manual_secondary_is_preserved(): void {
        $existing = [ [ 'id' => 79, 'keyword_candidate_id' => 10, 'pool' => 'category', 'page_type' => 'tmw_category_page', 'target_type' => 'tmw_category_page', 'target_id' => 502, 'target_key' => 'tmw_category_page:502', 'role' => 'secondary', 'canonical_owner' => 0, 'status' => 'approved', 'source_type' => 'manual' ] ];
        $decision = $this->analyzer->analyze( $this->evidence( [
            'distinct_targets'  => [ $this->target( 'tmw_category_page', 501, 'A' ), $this->target( 'tmw_category_page', 502, 'B' ) ],
            'rankmath_presence' => [ [ 'post_id' => 501, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ] ],
            'content_presence'  => [ [ 'post_id' => 501, 'present' => true ] ],
            'import_rows'       => [ $this->importRow( 'tmw_category_page', 502, 'approved', 9 ) ],
        ] ), $existing );

        $secondary = $decision['planned_actions'][1];
        $this->assertSame( 502, $secondary['payload']['target_id'] );
        $this->assertSame( 'preserve', $secondary['action'] );
    }

    // ── 19 & partial metadata. Migration-owned rows update idempotently ───

    public function test_migration_row_unchanged_then_updated_only_when_fields_differ(): void {
        $row_fixture = [
            'rankmath_presence' => [ [ 'post_id' => 501, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ] ],
            'content_presence'  => [ [ 'post_id' => 501, 'present' => true ] ],
        ];
        $existing_current = [ [ 'id' => 80, 'keyword_candidate_id' => 10, 'pool' => 'category', 'page_type' => 'tmw_category_page', 'target_type' => 'tmw_category_page', 'target_id' => 501, 'target_key' => 'tmw_category_page:501', 'role' => 'primary', 'canonical_owner' => 1, 'status' => 'approved', 'active_in_rank_math' => 1, 'present_in_content' => 1, 'conflict_reason' => null, 'approval_reason' => 'kwmig_evidence_score_13', 'target_name' => 'Target A', 'source_type' => 'migration_candidate', 'source_reference' => 'kwmig-v1' ] ];
        $unchanged = $this->analyzer->analyze( $this->evidence( $row_fixture ), $existing_current );
        $this->assertSame( 'unchanged', $unchanged['planned_actions'][0]['action'], 'Stable data → no write, no timestamp churn.' );

        // Partial metadata drift (present_in_content stale) → targeted update.
        $existing_stale = $existing_current;
        $existing_stale[0]['present_in_content'] = 0;
        $updated = $this->analyzer->analyze( $this->evidence( $row_fixture ), $existing_stale );
        $this->assertSame( 'update', $updated['planned_actions'][0]['action'] );
        $this->assertSame( [ 'present_in_content' ], $updated['planned_actions'][0]['changed_fields'] );
    }

    // ── 20. Repeated analysis is stable ───────────────────────────────────

    public function test_repeated_analysis_is_deterministic(): void {
        $fixture = $this->evidence( [
            'distinct_targets'  => [ $this->target( 'tmw_category_page', 502, 'B' ), $this->target( 'tmw_category_page', 501, 'A' ) ],
            'rankmath_presence' => [ [ 'post_id' => 501, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ] ],
            'content_presence'  => [ [ 'post_id' => 501, 'present' => true ] ],
            'import_rows'       => [ $this->importRow( 'tmw_category_page', 502, 'approved', 11 ) ],
        ] );

        $this->assertSame(
            $this->analyzer->analyze( $fixture ),
            $this->analyzer->analyze( $fixture ),
            'Identical evidence must yield an identical decision record.'
        );
    }

    // ── 26. Partial/absent evidence → manual review, not silent writes ────

    public function test_insufficient_evidence_requires_manual_review(): void {
        $decision = $this->analyzer->analyze( $this->evidence( [
            'status'           => 'queued_for_review',
            'target_type'      => '',
            'target_id'        => 0,
            'distinct_targets' => [ $this->target( 'tmw_category_page', 501, 'A' ) ],
            'import_rows'      => [ $this->importRow( 'tmw_category_page', 501, 'queued_for_review', 12 ) ],
        ] ) );

        $this->assertSame( Analyzer::C_MANUAL_REVIEW, $decision['classification'] );
        $this->assertFalse( $decision['writable'] );
        $this->assertSame( [], $decision['planned_actions'] );
    }

    public function test_no_target_evidence_requires_manual_review(): void {
        $decision = $this->analyzer->analyze( $this->evidence( [ 'target_type' => '', 'target_id' => 0, 'distinct_targets' => [] ] ) );
        $this->assertSame( Analyzer::C_MANUAL_REVIEW, $decision['classification'] );
        $this->assertContains( 'no_target_evidence', $decision['reasons'] );
    }

    public function test_postmeta_only_page_is_scored_with_its_reported_page_type(): void {
        $decision = $this->analyzer->analyze( $this->evidence( [
            'rankmath_presence' => [
                [ 'post_id' => 501, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ],
                [ 'post_id' => 777, 'post_type' => 'tmw_video_page', 'rankmath_role' => 'absent' ],
            ],
            'content_presence' => [ [ 'post_id' => 501, 'present' => true ], [ 'post_id' => 777, 'present' => false ] ],
            'postmeta_ownership' => [ [ 'post_id' => 777, 'role' => 'secondary' ] ],
        ] ) );

        $this->assertSame( Analyzer::C_SECONDARY, $decision['classification'] );
        $this->assertCount( 2, $decision['targets'] );
        $secondary = $decision['planned_actions'][1];
        $this->assertSame( 'tmw_video_page', $secondary['payload']['target_type'] );
        $this->assertSame( 777, $secondary['payload']['target_id'] );
        $this->assertSame( 'migration_postmeta', $secondary['payload']['source_type'] );
    }

    public function test_postmeta_page_without_a_resolved_type_is_report_only(): void {
        $decision = $this->analyzer->analyze( $this->evidence( [
            'target_type' => '', 'target_id' => 0, 'distinct_targets' => [],
            'rankmath_presence' => [], 'content_presence' => [],
            'target_unresolvable' => [ 888 ],
            'postmeta_ownership' => [ [ 'post_id' => 888, 'role' => 'primary' ] ],
        ] ) );

        $this->assertSame( Analyzer::C_UNRESOLVED, $decision['classification'] );
        $this->assertSame( '', $decision['targets'][0]['target_type'] );
        $this->assertSame( [], $decision['planned_actions'] );
    }

    public function test_same_target_in_multiple_pools_keeps_isolated_relationships(): void {
        $category = $this->importRow( 'tmw_category_page', 501, 'rejected', 20, 'category' );
        $model = $this->importRow( 'tmw_category_page', 501, 'approved', 21, 'model' );
        $decision = $this->analyzer->analyze( $this->evidence( [
            'rankmath_presence' => [ [ 'post_id' => 501, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ] ],
            'content_presence' => [ [ 'post_id' => 501, 'present' => true ] ],
            'import_rows' => [ $model, $category ],
        ] ) );

        $this->assertCount( 2, $decision['targets'] );
        $actions = $decision['planned_actions'];
        $this->assertSame( [ 'category', 'model' ], array_column( array_column( $actions, 'payload' ), 'pool' ) );
        $this->assertSame( 'approved', $actions[0]['payload']['status'] );
        $this->assertSame( 'review_required', $actions[1]['payload']['status'], 'Approval in model must not alter the category relationship.' );
    }

    public function test_import_attribution_uses_lowest_positive_batch_then_row(): void {
        $high = $this->importRow( 'tmw_category_page', 501, 'approved', 9 );
        $high['row_id'] = 10;
        $low_row = $this->importRow( 'tmw_category_page', 501, 'approved', 4 );
        $low_row['row_id'] = 8;
        $lower_row = $low_row;
        $lower_row['row_id'] = 7;
        $base = [
            'rankmath_presence' => [ [ 'post_id' => 501, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ] ],
            'content_presence' => [ [ 'post_id' => 501, 'present' => true ] ],
        ];
        $forward = $this->analyzer->analyze( $this->evidence( $base + [ 'import_rows' => [ $high, $low_row, $lower_row ] ] ) );
        $reverse = $this->analyzer->analyze( $this->evidence( $base + [ 'import_rows' => [ $lower_row, $low_row, $high ] ] ) );

        $this->assertSame( 4, $this->primaryAction( $forward )['payload']['source_batch_id'] );
        $this->assertSame( 7, $this->primaryAction( $forward )['payload']['source_import_row_id'] );
        $this->assertSame( $forward, $reverse );
    }

    public function test_existing_identity_is_normalized_before_matching(): void {
        $existing = [ [
            'id' => 404, 'keyword_candidate_id' => 10, 'pool' => 'CATEGORY!!!',
            'page_type' => 'TMW_CATEGORY_PAGE!!!', 'target_type' => 'TMW_CATEGORY_PAGE!!!',
            'target_id' => 501, 'target_key' => '  tmw_category_page:501  ', 'role' => 'primary',
            'canonical_owner' => 1, 'status' => 'approved', 'source_type' => 'manual',
        ] ];
        $decision = $this->analyzer->analyze( $this->evidence( [
            'rankmath_presence' => [ [ 'post_id' => 501, 'post_type' => 'tmw_category_page', 'rankmath_role' => 'primary' ] ],
            'content_presence' => [ [ 'post_id' => 501, 'present' => true ] ],
        ] ), $existing );

        $this->assertSame( 'preserve', $decision['planned_actions'][0]['action'] );
        $this->assertSame( 404, $decision['planned_actions'][0]['existing_id'] );
    }

    // ── Rejected candidates are skipped, never written ────────────────────

    public function test_rejected_candidate_is_skipped(): void {
        $decision = $this->analyzer->analyze( $this->evidence( [ 'status' => 'rejected' ] ) );
        $this->assertSame( Analyzer::C_REJECTED_SKIP, $decision['classification'] );
        $this->assertFalse( $decision['writable'] );
    }

    // ── No hardcoding in analyzer/service source ──────────────────────────

    public function test_no_category_specific_hardcoding(): void {
        foreach ( [
            '/../includes/keywords/class-keyword-assignment-migration-analyzer.php',
            '/../includes/keywords/class-keyword-assignment-migration-service.php',
        ] as $path ) {
            $source = (string) file_get_contents( __DIR__ . $path );
            foreach ( [ 'Free Cam Chat', 'Live Cam Chat', 'live jasmin', 'livejasmin' ] as $forbidden ) {
                $this->assertFalse( stripos( $source, $forbidden ), 'Hardcoded audit example in ' . $path );
            }
        }
    }
}
