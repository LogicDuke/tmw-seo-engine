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

    public function test_uses_canonical_migration_identity_and_secondary_payload(): void {
        $this->assertStringContainsString("'pool'        => 'category'", $this->source);
        $this->assertStringContainsString("'page_type'   => 'tmw_category_page'", $this->source);
        $this->assertStringContainsString("'target_type' => 'tmw_category_page'", $this->source);
        $this->assertStringContainsString("'target_key'  => 'tmw_category_page:'", $this->source);
        $this->assertStringContainsString("'role'                     => 'secondary'", $this->source);
        $this->assertStringContainsString("'canonical_owner'          => 0", $this->source);
        $this->assertStringContainsString("'shared_secondary_allowed' => 1", $this->source);
        $this->assertStringContainsString("'active_in_rank_math'      => 0", $this->source);
    }

    public function test_reuses_repository_upsert_and_canonical_normalizer(): void {
        $this->assertStringContainsString('$assignments->upsert_assignment(', $this->source);
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
}
