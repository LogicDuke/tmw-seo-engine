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
    private string $plugin;

    protected function setUp(): void {
        $this->schema = (string) file_get_contents(__DIR__ . '/../includes/db/class-schema.php');
        $this->admin = (string) file_get_contents(__DIR__ . '/../includes/admin/class-keyword-pools-admin-page.php');
        $this->service = (string) file_get_contents(__DIR__ . '/../includes/keywords/class-keyword-pool-selected-import-service.php');
        $this->repository = (string) file_get_contents(__DIR__ . '/../includes/keywords/class-keyword-pool-import-batch-repository.php');
        $this->plugin = (string) file_get_contents(__DIR__ . '/../includes/class-plugin.php');
    }

    public function test_schema_defines_import_batch_and_row_tables_with_required_indexes(): void {
        $this->assertStringContainsString("tmw_keyword_import_batches", $this->schema);
        $this->assertStringContainsString("tmw_keyword_import_rows", $this->schema);
        $this->assertStringContainsString('rejected INT UNSIGNED NOT NULL DEFAULT 0', $this->schema);
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


    public function test_runtime_upgrade_path_verifies_keyword_import_history_tables_on_admin_init(): void {
        $this->assertStringContainsString('public static function ensure_keyword_import_history_schema(): bool', $this->schema);
        $this->assertStringContainsString("\$wpdb->prefix . 'tmw_keyword_import_batches'", $this->schema);
        $this->assertStringContainsString("\$wpdb->prefix . 'tmw_keyword_import_rows'", $this->schema);
        $this->assertStringContainsString('tmw_keyword_import_history_schema_version', $this->schema);
        $this->assertStringContainsString('SHOW TABLES LIKE %s', $this->schema);
        $this->assertStringContainsString('$wpdb->esc_like($table_name)', $this->schema);
        $this->assertStringContainsString('private static function reconcile_keyword_import_history_tables(): void', $this->schema);
        $this->assertStringContainsString('self::reconcile_keyword_import_history_tables();', $this->schema);
        $this->assertStringContainsString('dbDelta($sql_keyword_import_batches);', $this->schema);
        $this->assertStringContainsString('dbDelta($sql_keyword_import_rows);', $this->schema);
        $this->assertStringContainsString('update_option($version_option, $target_version, false)', $this->schema);
        $ensureStart = strpos($this->schema, 'public static function ensure_keyword_import_history_schema(): bool');
        $ensureEnd = strpos($this->schema, 'private static function reconcile_keyword_import_history_tables(): void');
        $this->assertIsInt($ensureStart);
        $this->assertIsInt($ensureEnd);
        $ensureMethod = substr($this->schema, $ensureStart, $ensureEnd - $ensureStart);
        $this->assertStringNotContainsString('self::create_or_update_tables();', $ensureMethod);
        $this->assertStringContainsString("[TMW-KW-IMPORT] Import history tables verified/created.", $this->schema);

        $this->assertStringContainsString('Schema::ensure_keyword_import_history_schema();', $this->plugin);
        $this->assertStringContainsString("add_action('admin_init', [Schema::class, 'ensure_keyword_import_history_schema'])", $this->plugin);
    }


    public function test_table_existence_checks_are_case_insensitive(): void {
        $this->assertStringContainsString('private static function table_exists(string $table_name): bool', $this->schema);
        $this->assertStringContainsString('is_string($exists) && strtolower($exists) === strtolower($table_name)', $this->schema);
        $this->assertStringContainsString('if (!self::table_exists($table_name))', $this->schema);
    }

    public function test_import_persistence_self_heals_missing_history_tables_before_returning_zero(): void {
        $this->assertStringContainsString('if (!$this->tables_exist())', $this->repository);
        $this->assertStringContainsString("method_exists('TMWSEO\\Engine\\Schema', 'ensure_keyword_import_history_schema')", $this->repository);
        $this->assertStringContainsString('\TMWSEO\Engine\Schema::ensure_keyword_import_history_schema();', $this->repository);
    }

    public function test_repository_counts_rejected_rows_and_supports_pagination(): void {
        $this->assertStringContainsString("SUM(status = 'rejected') AS rejected", $this->repository);
        $this->assertStringContainsString("'rejected'", $this->repository);
        $this->assertStringContainsString('public function query_rows(int $batch_id, string $status = \'\', int $limit = 100, int $offset = 0): array', $this->repository);
        $this->assertStringContainsString('LIMIT %d OFFSET %d', $this->repository);
        $this->assertStringContainsString('public function count_rows(int $batch_id, string $status = \'\'): int', $this->repository);
        $this->assertStringContainsString('SELECT COUNT(*) FROM {$table}', $this->repository);
        $this->assertStringNotContainsString('min(1000', $this->repository);
    }

    public function test_batch_view_has_pagination_controls(): void {
        $this->assertStringContainsString('$page_size = 100', $this->admin);
        $this->assertStringContainsString('count_rows($batch_id)', $this->admin);
        $this->assertStringContainsString('query_rows($batch_id, \'\', $page_size, $offset)', $this->admin);
        $this->assertStringContainsString('render_batch_pagination', $this->admin);
        $this->assertStringContainsString('Previous', $this->admin);
        $this->assertStringContainsString('Next', $this->admin);
        $this->assertStringContainsString('Total rows: %d. Page %d of %d.', $this->admin);
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
        $this->assertStringContainsString("if (\$candidate_id > 0 && \$repository->update_candidate_status(\$candidate_id, 'approved'))", $this->admin);
        $this->assertStringContainsString('approve_import_row_as_candidate($row, $batch)', $this->admin);
        $this->assertStringContainsString('candidate_write_failed', $this->admin);
        $this->assertStringContainsString('if ($approved_candidate_id > 0)', $this->admin);
        $this->assertStringContainsString('$can_reject = true', $this->admin);
        $this->assertStringContainsString("\$can_reject = \$repository->update_candidate_status(\$candidate_id, 'ignored')", $this->admin);
        $this->assertStringContainsString('if ($can_reject)', $this->admin);
        $this->assertStringContainsString('SELECT id FROM {$table} WHERE id = %d LIMIT 1', $this->repository);
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
