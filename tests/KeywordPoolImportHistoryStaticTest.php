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
    private string $readme;

    protected function setUp(): void {
        $this->schema = (string) file_get_contents(__DIR__ . '/../includes/db/class-schema.php');
        $this->admin = (string) file_get_contents(__DIR__ . '/../includes/admin/class-keyword-pools-admin-page.php');
        $this->service = (string) file_get_contents(__DIR__ . '/../includes/keywords/class-keyword-pool-selected-import-service.php');
        $this->repository = (string) file_get_contents(__DIR__ . '/../includes/keywords/class-keyword-pool-import-batch-repository.php');
        $this->plugin = (string) file_get_contents(__DIR__ . '/../includes/class-plugin.php');
        $this->readme = (string) file_get_contents(__DIR__ . '/../README.md');
    }

    public function test_service_uses_isset_not_empty_for_lookup_key(): void {
        // empty($selected_lookup[$row_number]) silently skips rows with row_number=0
        $this->assertStringNotContainsString(
            'empty($selected_lookup[',
            $this->service,
            'save_matching_rows must use isset(), not empty(), for the lookup key check'
        );
    }

    public function test_service_all_dry_run_row_lookup_uses_prefixed_keys(): void {
        // The lookup must use a prefix to avoid PHP empty("0") false positive
        $this->assertStringContainsString(
            "'n:'",
            $this->service,
            'all_dry_run_row_lookup must use prefixed keys like n: to avoid empty("0") bug'
        );
    }

    public function test_service_lookup_paths_use_shared_helper(): void {
        $this->assertStringContainsString(
            'private function dry_run_row_lookup_key(array $row, int $array_index): string',
            $this->service
        );
        $this->assertStringContainsString(
            '$selected_lookup[$this->dry_run_row_lookup_key($row, (int) $index)] = true;',
            $this->service,
            'all_dry_run_row_lookup must use the shared lookup helper'
        );
        $this->assertStringContainsString(
            '$lookup_key = $this->dry_run_row_lookup_key($row, (int) $row_array_index);',
            $this->service,
            'save_matching_rows must use the shared lookup helper'
        );
    }

    public function test_admin_save_selected_uses_stable_row_tokens(): void {
        $this->assertStringContainsString(
            '$row_token = self::dry_run_row_lookup_key($row, (int) $row_index);',
            $this->admin
        );
        $this->assertStringContainsString(
            'esc_attr($row_token)',
            $this->admin,
            'Preview checkboxes must post stable n:/i: tokens, not raw row_number values'
        );
        $this->assertStringNotContainsString(
            "esc_attr((string) (int) (\$row['row_number'] ?? 0))",
            $this->admin,
            'Preview checkboxes must not post ambiguous raw row_number values'
        );
    }

    public function test_admin_attempted_row_total_includes_conflicts(): void {
        $this->assertStringContainsString("\$attempted_row_total = (int) (\$summary['inserted'] ?? 0)", $this->admin);
        $this->assertStringContainsString("+ (int) (\$summary['conflicts'] ?? 0);", $this->admin);
        $this->assertStringContainsString('0 === $attempted_row_total', $this->admin);
        $this->assertStringContainsString('$attempted_row_total', $this->admin);
    }

    public function test_service_model_batch_persists_rows_for_pool_model(): void {
        // save_full_reviewed_model_batch must call persist_import with pool=model
        $this->assertStringContainsString(
            'save_full_reviewed_model_batch',
            $this->service
        );
        $this->assertStringContainsString(
            "'model', \$this->all_dry_run_row_lookup",
            $this->service
        );
    }

    public function test_schema_defines_import_batch_and_row_tables_with_required_indexes(): void {
        $this->assertStringContainsString("tmw_keyword_import_batches", $this->schema);
        $this->assertStringContainsString("tmw_keyword_import_rows", $this->schema);
        $this->assertStringContainsString('rejected INT UNSIGNED NOT NULL DEFAULT 0', $this->schema);
        $this->assertStringContainsString('PRIMARY KEY  (id)', $this->schema);
        foreach ([
            'UNIQUE KEY import_batch_id (import_batch_id)',
            'KEY pool_target (pool, target_type, target_id)',
            'KEY imported_at (imported_at)',
            'KEY source_batch (source_batch)',
            'UNIQUE KEY batch_row (batch_id,row_index)',
            'KEY batch_status (batch_id,status)',
            'KEY candidate_id (candidate_id)',
        ] as $indexSql) {
            $this->assertStringContainsString($indexSql, $this->schema);
        }
    }

    public function test_rows_table_sql_is_dbdelta_safe_and_avoids_risky_keyword_index(): void {
        preg_match_all('/return "CREATE TABLE \$rows_table \((.*?)\) \$charset_collate;";/s', $this->schema, $matches);
        $this->assertCount(1, $matches[1]);

        $rowsSql = $matches[1][0];
        $this->assertStringContainsString('private static function get_keyword_import_rows_sql(string $rows_table, string $charset_collate): string', $this->schema);
        $this->assertMatchesRegularExpression('/batch_id BIGINT UNSIGNED NOT NULL,\s*\R\s*import_batch_id VARCHAR\(64\) NOT NULL,\s*\R\s*row_index INT UNSIGNED NOT NULL DEFAULT 0,\s*\R\s*keyword VARCHAR\(255\) NOT NULL,/', $rowsSql);
        $this->assertStringContainsString('PRIMARY KEY  (id)', $rowsSql);
        $this->assertStringContainsString('UNIQUE KEY batch_row (batch_id,row_index)', $rowsSql);
        $this->assertStringNotContainsString('row_number INT UNSIGNED', $rowsSql);
        $this->assertStringNotContainsString('batch_id,row_number', $rowsSql);
        $this->assertStringContainsString('KEY import_batch_id (import_batch_id)', $rowsSql);
        $this->assertStringContainsString('KEY batch_status (batch_id,status)', $rowsSql);
        $this->assertStringContainsString('KEY candidate_id (candidate_id)', $rowsSql);
        $this->assertStringNotContainsString('KEY keyword (keyword)', $rowsSql);
        $this->assertStringNotContainsString('KEY keyword (keyword(255))', $rowsSql);
        $this->assertStringNotContainsString('pool_target_status', $rowsSql);
        $this->assertStringNotContainsString('row_payload)', $rowsSql);
        $this->assertSame(0, preg_match('/\$sql_keyword_import_rows = "CREATE TABLE /', $this->schema));
        $this->assertSame(2, substr_count($this->schema, '$sql_keyword_import_rows = self::get_keyword_import_rows_sql($keyword_import_rows, $charset_collate);'));

        $this->assertStringContainsString('private static function run_keyword_import_rows_dbdelta(string $rows_sql, string $rows_table): void', $this->schema);
        $this->assertStringContainsString("\$wpdb->last_error = '';", $this->schema);
        $this->assertStringContainsString('$dbdelta_error = self::safe_db_error((string) $wpdb->last_error);', $this->schema);
        $this->assertStringContainsString("\$wpdb->prepare('SHOW TABLES LIKE %s', \$wpdb->esc_like(\$rows_table))", $this->schema);
        $this->assertStringContainsString("\$message = '[TMW-KW-IMPORT] Rows table creation failed: db_error=' . \$db_error . '; sql_hash=' . self::safe_sql_hash(\$rows_sql);", $this->schema);
        $this->assertStringContainsString("update_option('tmw_keyword_import_rows_schema_error', \$message, false);", $this->schema);
        $this->assertStringNotContainsString('error_log($rows_sql', $this->schema);
    }


    public function test_runtime_upgrade_path_verifies_keyword_import_history_tables_on_admin_init(): void {
        $this->assertStringContainsString('public static function ensure_keyword_import_history_schema(): bool', $this->schema);
        $this->assertStringContainsString("\$wpdb->prefix . 'tmw_keyword_import_batches'", $this->schema);
        $this->assertStringContainsString("\$wpdb->prefix . 'tmw_keyword_import_rows'", $this->schema);
        $this->assertStringContainsString('tmw_keyword_import_history_schema_version', $this->schema);
        $this->assertStringContainsString('$target_version = 2;', $this->schema);
        $this->assertStringContainsString('SHOW TABLES LIKE %s', $this->schema);
        $this->assertStringContainsString('$wpdb->esc_like($table_name)', $this->schema);
        $this->assertStringContainsString('private static function missing_tables(array $tables): array', $this->schema);
        $this->assertStringContainsString('private static function clear_table_exists_cache(array $tables): void', $this->schema);
        $this->assertStringContainsString('private static function can_run_keyword_import_history_schema_update(): bool', $this->schema);
        $this->assertStringContainsString('if (!self::can_run_keyword_import_history_schema_update())', $this->schema);
        $this->assertStringContainsString('private static function reconcile_keyword_import_history_tables(): void', $this->schema);
        $this->assertStringContainsString('self::reconcile_keyword_import_history_tables();', $this->schema);
        $this->assertStringContainsString('self::clear_table_exists_cache($tables);', $this->schema);
        $this->assertStringContainsString('dbDelta($sql_keyword_import_batches);', $this->schema);
        $this->assertStringContainsString('self::run_keyword_import_rows_dbdelta($sql_keyword_import_rows, $keyword_import_rows);', $this->schema);
        $this->assertStringContainsString('update_option($version_option, $target_version, false)', $this->schema);
        $ensureStart = strpos($this->schema, 'public static function ensure_keyword_import_history_schema(): bool');
        $ensureEnd = strpos($this->schema, 'private static function reconcile_keyword_import_history_tables(): void');
        $this->assertIsInt($ensureStart);
        $this->assertIsInt($ensureEnd);
        $ensureMethod = substr($this->schema, $ensureStart, $ensureEnd - $ensureStart);
        $this->assertStringNotContainsString('self::create_or_update_tables();', $ensureMethod);
        $this->assertStringNotContainsString('Fast exit: version option already satisfied', $ensureMethod);
        $this->assertStringContainsString("[TMW-KW-IMPORT] Import history tables verified/created.", $this->schema);
        $this->assertStringContainsString("[TMW-KW-IMPORT] Rows table creation failed: db_error=", $this->schema);

        $this->assertStringContainsString('Schema::ensure_keyword_import_history_schema();', $this->plugin);
        $this->assertStringContainsString("add_action('admin_init', [Schema::class, 'ensure_keyword_import_history_schema'])", $this->plugin);
    }


    public function test_table_existence_checks_are_case_insensitive(): void {
        $this->assertStringContainsString('private static function table_exists(string $table_name): bool', $this->schema);
        $this->assertStringContainsString('is_string($exists) && strtolower($exists) === strtolower($table_name)', $this->schema);
        $this->assertStringContainsString('if (!self::table_exists($table_name))', $this->schema);
    }

    public function test_missing_import_history_table_notice_lists_exact_table(): void {
        $this->assertStringContainsString('public function missing_tables(): array', $this->repository);
        $this->assertStringContainsString("return 'Import history schema missing table: ' . $missing[0];", $this->repository);
        $this->assertStringNotContainsString('Import history tables do not exist after schema ensure.', $this->repository);
        $this->assertStringNotContainsString('Import history tables do not exist.', $this->repository);
    }

    public function test_import_persistence_self_heals_missing_history_tables_before_returning_zero(): void {
        $this->assertStringContainsString('if (!$this->tables_exist())', $this->repository);
        $this->assertStringContainsString("method_exists('TMWSEO\\Engine\\Schema', 'ensure_keyword_import_history_schema')", $this->repository);
        $this->assertStringContainsString('\TMWSEO\Engine\Schema::ensure_keyword_import_history_schema();', $this->repository);
    }

    public function test_repository_counts_rejected_rows_and_supports_pagination(): void {
        $this->assertStringContainsString("SUM(status = 'rejected') AS rejected", $this->repository);
        $this->assertStringContainsString("'rejected'", $this->repository);
        $this->assertStringContainsString("public function query_rows(int \$batch_id, string \$status = '', int \$limit = 100, int \$offset = 0, string \$orderby = '', string \$order = 'desc', string \$search = ''): array", $this->repository);
        $this->assertStringNotContainsString("public function query_rows(int \$batch_id, string \$status = '', int \$limit = 100, int \$offset = 0): array", $this->repository);
        $this->assertStringContainsString("'row_index' => $row_number", $this->repository);
        $this->assertStringContainsString('WHERE batch_id = %d AND row_index = %d', $this->repository);
        $this->assertStringContainsString('ORDER BY row_index ASC, id ASC', $this->repository);
        $this->assertStringNotContainsString("'row_number' => $row_number", $this->repository);
        $this->assertStringNotContainsString('ORDER BY row_number ASC', $this->repository);
        $this->assertStringContainsString('LIMIT %d OFFSET %d', $this->repository);
        $this->assertStringContainsString("public function count_rows(int \$batch_id, string \$status = '', string \$search = ''): int", $this->repository);
        $this->assertStringNotContainsString("public function count_rows(int \$batch_id, string \$status = ''): int", $this->repository);
        $this->assertStringContainsString('SELECT COUNT(*) FROM {$table}', $this->repository);
        $this->assertStringNotContainsString('min(1000', $this->repository);
    }

    public function test_batch_view_has_pagination_controls(): void {
        $this->assertStringContainsString('$page_size = 100', $this->admin);
        $this->assertStringContainsString("\$search = self::current_pool_search_from_array(\$_GET);", $this->admin);
        $this->assertStringContainsString("count_rows(\$batch_id, '', \$search)", $this->admin);
        $this->assertStringContainsString("query_rows(\$batch_id, '', \$page_size, \$offset, \$sort['orderby'], \$sort['order'], \$search)", $this->admin);
        $this->assertStringContainsString('tmwseo_pool_search', $this->admin);
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
        $this->assertStringContainsString('Export CSV', $this->admin);
        $this->assertStringContainsString('BATCH_EXPORT_ACTION', $this->admin);
        $this->assertStringContainsString('handle_batch_export', $this->admin);
        $this->assertStringContainsString('build_batch_export_csv', $this->admin);
        $this->assertStringContainsString('batch_export_headers', $this->admin);
        $this->assertStringContainsString('stored_row_to_batch_export_values', $this->admin);
        $this->assertStringContainsString('wp_nonce_url', $this->admin);
        $this->assertStringContainsString('check_admin_referer', $this->admin);
        $this->assertStringNotContainsString('Export coming soon', $this->admin);
        foreach ([ 'Approve', 'Reject', 'Inspect', 'Copy' ] as $label) {
            $this->assertStringContainsString($label, $this->admin);
        }
        $this->assertStringContainsString('data-tmw-copy-keyword', $this->admin);
    }


    public function test_readme_version_matches_import_rows_fix_release(): void {
        $this->assertStringContainsString('**Version:** 5.8.14-import-rows-fix', $this->readme);
        $this->assertStringContainsString('`5.8.14-import-rows-fix`', $this->readme);
        $this->assertStringNotContainsString('5.8.11-final-copy', $this->readme);
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
