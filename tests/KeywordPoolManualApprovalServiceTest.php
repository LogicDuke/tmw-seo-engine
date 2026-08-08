<?php
/** Focused source-contract regressions for atomic secondary manual approval. */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class KeywordPoolManualApprovalServiceTest extends TestCase {
    private string $source;

    protected function setUp(): void {
        $source = file_get_contents( __DIR__ . '/../includes/keywords/class-keyword-pool-manual-approval-service.php' );
        $this->assertIsString( $source );
        $this->source = $source;
    }

    public function test_uses_canonical_migration_identity_and_role_appropriate_payload(): void {
        $this->assertStringContainsString("'pool'        => 'category'", $this->source);
        $this->assertStringContainsString("'page_type'   => 'tmw_category_page'", $this->source);
        $this->assertStringContainsString("'target_type' => 'tmw_category_page'", $this->source);
        $this->assertStringContainsString("'target_key'  => 'tmw_category_page:'", $this->source);
        $this->assertStringContainsString("'role'                     => \$assignment_role", $this->source);
        $this->assertStringContainsString("'canonical_owner'          => 'primary' === \$assignment_role ? 1 : 0", $this->source);
        $this->assertStringContainsString("'shared_secondary_allowed' => 'secondary' === \$assignment_role ? 1 : 0", $this->source);
        $this->assertStringContainsString("'active_in_rank_math'      => 0", $this->source);
    }

    public function test_reuses_repository_upsert_and_canonical_normalizer(): void {
        $this->assertStringContainsString('$assignments->create_primary_assignment_in_transaction( $payload )', $this->source);
        $this->assertStringContainsString('$assignments->upsert_assignment( $payload )', $this->source);
        $this->assertStringContainsString('KeywordPoolCandidateRepository() )->normalize_keyword', $this->source);
        $this->assertSame( 1, substr_count( $this->source, "query( 'START TRANSACTION'" ) );
    }

    public function test_validates_all_transaction_tables_before_start(): void {
        $engine = strpos( $this->source, 'foreach ( [ $candidate_table, $assignments->table(), $imports->batches_table(), $imports->rows_table() ]' );
        $start  = strpos( $this->source, "query( 'START TRANSACTION'" );
        $this->assertTrue( false !== $engine );
        $this->assertTrue( false !== $start );
        $this->assertLessThan( $start, $engine );
    }

    public function test_creates_missing_candidate_inside_approval_transaction(): void {
        $start = strpos( $this->source, "query( 'START TRANSACTION'" );
        $create = strpos( $this->source, 'approve_import_row_as_candidate_result( $row, $batch )' );
        $assignment = strpos( $this->source, '$assignments->create_primary_assignment_in_transaction(' );
        $commit = strpos( $this->source, "query( 'COMMIT'" );

        $this->assertNotFalse( $start );
        $this->assertNotFalse( $create );
        $this->assertNotFalse( $assignment );
        $this->assertNotFalse( $commit );
        $this->assertLessThan( $create, $start );
        $this->assertLessThan( $assignment, $create );
        $this->assertLessThan( $commit, $assignment );
        $this->assertStringContainsString("return \$this->result( false, (string) ( \$candidate_result['safe_reason'] ?? 'candidate_persistence_failed' ) );", $this->source);
        $this->assertStringContainsString("'safe_reason'   => 'primary' === \$assignment_role ? 'manually_approved_primary' : 'manually_approved_secondary'", $this->source);
    }

    public function test_role_inference_uses_assignment_and_legacy_owner_evidence(): void {
        $this->assertStringContainsString('$assignments->find_primary_owner( $candidate_id )', $this->source);
        $this->assertStringContainsString('$assignments->find_assignments_for_candidate( $candidate_id )', $this->source);
        $this->assertStringContainsString("\$assignment_role = 'secondary'", $this->source);
        $this->assertStringContainsString("\$assignment_role = 'primary'", $this->source);
        $this->assertStringContainsString('role_inference_ambiguous_no_primary_evidence', $this->source);
    }

    public function test_repository_participation_does_not_nest_transaction_boundaries(): void {
        $repository = file_get_contents( __DIR__ . '/../includes/keywords/class-keyword-assignment-repository.php' );
        $start = strpos( $repository, 'public function create_primary_assignment_in_transaction' );
        $end = strpos( $repository, 'public function update_assignment_status', $start );
        $method = substr( $repository, $start, $end - $start );
        $this->assertStringNotContainsString('START TRANSACTION', $method);
        $this->assertStringNotContainsString("query( 'COMMIT'", $method);
        $this->assertStringNotContainsString("query( 'ROLLBACK'", $method);
        $this->assertStringContainsString('create_active_primary_atomically( $normalized, false )', $method);
        $this->assertStringContainsString('$manage_transaction &&', $repository);
    }

}
