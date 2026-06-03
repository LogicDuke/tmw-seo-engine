<?php
/**
 * Static safety checks for keyword-pool import batch history.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class KeywordPoolImportHistoryStaticTest extends TestCase {
    private string $schema;
    private string $admin;
    private string $service;
    private string $repository;

    protected function setUp(): void {
        $this->schema = (string) file_get_contents(__DIR__ . '/../includes/db/class-schema.php');
        $this->admin = (string) file_get_contents(__DIR__ . '/../includes/admin/class-keyword-pools-admin-page.php');
        $this->service = (string) file_get_contents(__DIR__ . '/../includes/keywords/class-keyword-pool-selected-import-service.php');
        $this->repository = (string) file_get_contents(__DIR__ . '/../includes/keywords/class-keyword-pool-import-batch-repository.php');
    }

    public function test_schema_defines_import_batch_and_row_tables_with_required_indexes(): void {
        $this->assertStringContainsString("tmw_keyword_import_batches", $this->schema);
        $this->assertStringContainsString("tmw_keyword_import_rows", $this->schema);
        foreach ([
            'UNIQUE KEY import_batch_id (import_batch_id)',
            'KEY pool_target (pool, target_type, target_id)',
            'KEY imported_at (imported_at)',
            'KEY source_batch (source_batch)',
            'UNIQUE KEY batch_row (batch_id, row_number)',
            'KEY batch_status (batch_id, status)',
            'KEY pool_target_status (target_type, target_id, status)',
            'KEY candidate_id (candidate_id)',
            'KEY keyword (keyword)',
        ] as $indexSql) {
            $this->assertStringContainsString($indexSql, $this->schema);
        }
    }

    public function test_import_results_persist_all_attempted_rows_and_context(): void {
        $this->assertStringContainsString('persist_import($pool, $context, $summary, $history_rows)', $this->service);
        $this->assertStringContainsString("'_dry_run_row' => \$row", $this->service);
        $this->assertStringContainsString('row_payload', $this->repository);
        foreach ([ 'source_batch', 'source_file', 'import_batch_id', 'imported_at', 'target_type', 'target_id', 'target_name', 'target_slug' ] as $contextKey) {
            $this->assertStringContainsString($contextKey, $this->service . $this->repository);
        }
    }

    public function test_admin_ui_renders_history_batch_rows_and_manual_actions(): void {
        $this->assertStringContainsString('Import History', $this->admin);
        $this->assertStringContainsString('View Batch', $this->admin);
        $this->assertStringContainsString('Continue Review', $this->admin);
        $this->assertStringContainsString('Export coming soon', $this->admin);
        foreach ([ 'Approve', 'Reject', 'Inspect', 'Copy' ] as $label) {
            $this->assertStringContainsString($label, $this->admin);
        }
        $this->assertStringContainsString('data-tmw-copy-keyword', $this->admin);
    }

    public function test_manual_import_row_actions_are_post_nonce_and_candidate_safe(): void {
        $this->assertStringContainsString('admin_post_tmwseo_keyword_import_row_action', $this->admin);
        $this->assertStringContainsString('check_admin_referer', $this->admin);
        $this->assertStringContainsString('current_user_can(self::CAPABILITY)', $this->admin);
        $this->assertStringContainsString('approve_import_row_as_candidate', $this->service);
        $this->assertStringContainsString("'status' => 'approved'", $this->admin);
        $this->assertStringContainsString("'result_reason' => 'manually_approved'", $this->admin);
        $this->assertStringContainsString("'status' => 'rejected'", $this->admin);
        $this->assertStringContainsString("'result_reason' => 'manually_rejected'", $this->admin);
        $this->assertStringContainsString("update_candidate_status(\$candidate_id, 'ignored')", $this->admin);
    }

    public function test_safety_boundary_excludes_rank_math_content_slug_taxonomy_publish_indexing_writes(): void {
        $changedFiles = $this->admin . $this->service . $this->repository;
        foreach ([ 'rank_math', 'wp_insert_post', 'wp_update_post', 'wp_set_object_terms', 'wp_create_term', 'publish', 'noindex' ] as $unsafeToken) {
            if ('noindex' === $unsafeToken) {
                $this->assertStringContainsString('indexing/noindex', $changedFiles);
                continue;
            }
            $this->assertStringNotContainsString($unsafeToken . '(', $changedFiles);
        }
    }
}
