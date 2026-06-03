<?php
/**
 * Tests for durable keyword import batch persistence.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordPoolImportBatchRepository;

require_once __DIR__ . '/../includes/keywords/class-keyword-pool-import-batch-repository.php';

final class KeywordPoolImportBatchRepositoryTestWpdb {
    public string $prefix;
    public int $insert_id = 0;
    public string $last_error = '';
    public string $last_query = '';
    /** @var array<int,array{table:string,data:array<string,mixed>}> */
    public array $inserts = [];
    /** @var array<int,array{table:string,data:array<string,mixed>,where:array<string,mixed>}> */
    public array $updates = [];
    /** @var array<string,array<int,string>> */
    private array $columns;
    public int $existing_batch_id = 0;
    public int $existing_row_id = 0;
    public string $fail_update_suffix = '';
    public bool $fail_row_insert = false;
    /** @var array<int,string> */
    public array $missing_tables = [];
    private bool $fail_batch_insert;

    /** @param array<string,array<int,string>> $columns */
    public function __construct(string $prefix, array $columns, bool $fail_batch_insert = false) {
        $this->prefix = $prefix;
        $this->columns = $columns;
        $this->fail_batch_insert = $fail_batch_insert;
    }

    public function esc_like(string $text): string { return addcslashes($text, '_%\\'); }

    public function prepare(string $sql, ...$args): string {
        $i = 0;
        return (string) preg_replace_callback('/%[sdf]/', static function () use ($args, &$i): string {
            $value = $args[$i++] ?? '';
            return is_string($value) ? "'" . addslashes($value) . "'" : (string) $value;
        }, $sql);
    }

    public function get_var(string $sql) {
        $this->last_query = $sql;
        if (str_starts_with($sql, 'SHOW TABLES LIKE')) {
            if (preg_match("/'([^']+)'/", $sql, $match)) {
                $table = stripslashes($match[1]);
                return in_array($table, $this->missing_tables, true) ? null : $table;
            }
        }
        if (str_contains($sql, 'tmw_keyword_import_batches') && str_contains($sql, 'import_batch_id')) {
            return $this->existing_batch_id;
        }
        if (str_contains($sql, 'tmw_keyword_import_rows') && str_contains($sql, 'batch_id')) {
            return $this->existing_row_id;
        }
        return 0;
    }

    public function get_results(string $sql, string $output = 'OBJECT'): array {
        $this->last_query = $sql;
        if (preg_match('/SHOW COLUMNS FROM ([^\s]+)/', $sql, $match)) {
            return array_map(static fn(string $field): array => [ 'Field' => $field ], $this->columns[$match[1]] ?? []);
        }
        return [];
    }

    public function get_row(string $sql, string $output = 'OBJECT'): array|null {
        $this->last_query = $sql;
        if (str_contains($sql, 'COUNT(*) AS total_rows')) {
            $row_inserts = array_values(array_filter($this->inserts, fn(array $insert): bool => str_ends_with($insert['table'], 'tmw_keyword_import_rows')));
            $count = static fn(callable $predicate): int => count(array_filter($row_inserts, $predicate));
            return [
                'total_rows' => count($row_inserts),
                'inserted' => $count(static fn(array $insert): bool => ($insert['data']['result_action'] ?? '') === 'inserted'),
                'updated' => $count(static fn(array $insert): bool => ($insert['data']['result_action'] ?? '') === 'updated'),
                'queued' => $count(static fn(array $insert): bool => ($insert['data']['status'] ?? '') === 'queued_for_review'),
                'review_required' => $count(static fn(array $insert): bool => ($insert['data']['status'] ?? '') === 'review_required'),
                'approved' => $count(static fn(array $insert): bool => ($insert['data']['status'] ?? '') === 'approved'),
                'rejected' => $count(static fn(array $insert): bool => ($insert['data']['status'] ?? '') === 'rejected'),
                'skipped' => $count(static fn(array $insert): bool => ($insert['data']['status'] ?? '') === 'skipped'),
                'blocked' => $count(static fn(array $insert): bool => ($insert['data']['status'] ?? '') === 'blocked'),
                'errors' => $count(static fn(array $insert): bool => ($insert['data']['status'] ?? '') === 'error'),
            ];
        }
        return null;
    }

    public function insert(string $table, array $data, array $format = []): int|false {
        $this->last_query = 'INSERT INTO ' . $table . ' (' . implode(',', array_keys($data)) . ')';
        if ($this->fail_batch_insert && str_ends_with($table, 'tmw_keyword_import_batches')) {
            $this->last_error = 'Unknown column target_slug';
            return false;
        }
        if ($this->fail_row_insert && str_ends_with($table, 'tmw_keyword_import_rows')) {
            $this->last_error = 'Row insert failed for test';
            return false;
        }
        $this->insert_id++;
        $this->inserts[] = [ 'table' => $table, 'data' => $data ];
        return 1;
    }

    public function update(string $table, array $data, array $where, array $format = [], array $where_format = []): int|false {
        $this->last_query = 'UPDATE ' . $table;
        if ('' !== $this->fail_update_suffix && str_ends_with($table, $this->fail_update_suffix)) {
            $this->last_error = 'Update failed for test';
            return false;
        }
        $this->updates[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
        return 1;
    }
}

final class KeywordPoolImportBatchRepositoryTest extends TestCase {
    public function test_full_category_batch_persists_batch_and_all_attempted_rows(): void {
        $prefix = 'wp_pr_import_success_';
        $GLOBALS['wpdb'] = new KeywordPoolImportBatchRepositoryTestWpdb($prefix, $this->columns($prefix));

        $repository = new KeywordPoolImportBatchRepository();
        $rows = [];
        for ($i = 1; $i <= 35; $i++) {
            $rows[] = [
                'row_number' => $i,
                'keyword' => 'big boob cam keyword ' . $i,
                'action' => $i <= 3 ? 'updated' : ($i <= 5 ? 'skipped' : 'blocked'),
                'reason' => $i <= 5 ? '' : 'review_required',
                '_dry_run_row' => [ 'row_number' => $i, 'keyword' => 'big boob cam keyword ' . $i ],
            ];
        }

        $batch_id = $repository->persist_import('category', [
            'target_type' => 'category_page',
            'target_id' => 4534,
            'target_name' => 'Big Boob Cam',
            'target_slug' => 'big-boob-cam',
            'source_batch' => 'Big_Boob_Cam_35_priority_keywords.csv',
            'source_file' => 'Big_Boob_Cam_35_priority_keywords.csv',
            'import_batch_id' => 'batch-big-boob-cam-35',
        ], [ 'inserted' => 0, 'updated' => 3, 'skipped' => 2, 'blocked' => 30 ], $rows);

        $this->assertGreaterThan(0, $batch_id);
        $batch_inserts = array_values(array_filter($GLOBALS['wpdb']->inserts, fn(array $insert): bool => str_ends_with($insert['table'], 'tmw_keyword_import_batches')));
        $row_inserts = array_values(array_filter($GLOBALS['wpdb']->inserts, fn(array $insert): bool => str_ends_with($insert['table'], 'tmw_keyword_import_rows')));
        $this->assertCount(1, $batch_inserts);
        $this->assertSame('batch-big-boob-cam-35', $batch_inserts[0]['data']['import_batch_id']);
        $this->assertSame('category_page', $batch_inserts[0]['data']['target_type']);
        $this->assertSame(4534, $batch_inserts[0]['data']['target_id']);
        $this->assertCount(35, $row_inserts);
        $this->assertSame(1, $row_inserts[0]['data']['row_index']);
        $this->assertArrayNotHasKey('row_number', $row_inserts[0]['data']);
        $this->assertSame('', $repository->last_error());
    }

    public function test_missing_rows_table_reports_exact_table_name(): void {
        delete_option('tmw_keyword_import_rows_schema_error');
        $prefix = 'wp_pr_import_missing_rows_';
        $GLOBALS['wpdb'] = new KeywordPoolImportBatchRepositoryTestWpdb($prefix, $this->columns($prefix));
        $GLOBALS['wpdb']->missing_tables = [ $prefix . 'tmw_keyword_import_rows' ];

        $repository = new KeywordPoolImportBatchRepository();
        $batch_id = $repository->persist_import('category', [
            'target_type' => 'category_page',
            'target_id' => 4534,
            'import_batch_id' => 'batch-missing-rows',
        ], [], [ [ 'row_number' => 1, 'keyword' => 'big boob cam' ] ]);

        $this->assertSame(0, $batch_id);
        $this->assertSame('Import history schema missing table: ' . $prefix . 'tmw_keyword_import_rows', $repository->last_error());
    }

    public function test_missing_rows_table_exposes_schema_dbdelta_error_when_available(): void {
        $prefix = 'wp_pr_import_rows_schema_error_';
        $GLOBALS['wpdb'] = new KeywordPoolImportBatchRepositoryTestWpdb($prefix, $this->columns($prefix));
        $GLOBALS['wpdb']->missing_tables = [ $prefix . 'tmw_keyword_import_rows' ];
        update_option('tmw_keyword_import_rows_schema_error', '[TMW-KW-IMPORT] Rows table creation failed: Specified key was too long', false);

        $repository = new KeywordPoolImportBatchRepository();
        $batch_id = $repository->persist_import('category', [
            'target_type' => 'category_page',
            'target_id' => 4534,
            'import_batch_id' => 'batch-missing-rows-dbdelta',
        ], [], [ [ 'row_number' => 1, 'keyword' => 'big boob cam' ] ]);

        $this->assertSame(0, $batch_id);
        $this->assertSame('[TMW-KW-IMPORT] Rows table creation failed: Specified key was too long', $repository->last_error());
        delete_option('tmw_keyword_import_rows_schema_error');
    }

    public function test_insert_data_is_filtered_to_actual_schema_columns(): void {
        $prefix = 'wp_pr_import_legacy_';
        $columns = $this->columns($prefix);
        $columns[$prefix . 'tmw_keyword_import_batches'] = array_values(array_diff($columns[$prefix . 'tmw_keyword_import_batches'], [ 'target_slug' ]));
        $GLOBALS['wpdb'] = new KeywordPoolImportBatchRepositoryTestWpdb($prefix, $columns);

        $repository = new KeywordPoolImportBatchRepository();
        $batch_id = $repository->persist_import('category', [
            'target_type' => 'category_page',
            'target_id' => 4534,
            'target_slug' => 'big-boob-cam',
            'import_batch_id' => 'batch-with-legacy-columns',
        ], [ 'inserted' => 0, 'updated' => 3, 'skipped' => 2, 'blocked' => 30 ], [
            [ 'row_number' => 1, 'keyword' => 'big boob cam', 'action' => 'blocked', 'reason' => 'review_required' ],
        ]);

        $this->assertGreaterThan(0, $batch_id);
        $this->assertArrayNotHasKey('target_slug', $GLOBALS['wpdb']->inserts[0]['data']);
    }

    public function test_batch_insert_failure_exposes_database_error(): void {
        $prefix = 'wp_pr_import_fail_';
        $GLOBALS['wpdb'] = new KeywordPoolImportBatchRepositoryTestWpdb($prefix, $this->columns($prefix), true);

        $repository = new KeywordPoolImportBatchRepository();
        $batch_id = $repository->persist_import('category', [
            'target_type' => 'category_page',
            'target_id' => 4534,
            'import_batch_id' => 'batch-fails',
        ], [], [ [ 'row_number' => 1, 'keyword' => 'big boob cam' ] ]);

        $this->assertSame(0, $batch_id);
        $this->assertSame('Unknown column target_slug', $repository->last_error());
        $this->assertStringContainsString('INSERT INTO ' . $prefix . 'tmw_keyword_import_batches', $repository->last_query());
    }


    public function test_batch_update_failure_returns_zero(): void {
        $prefix = 'wp_pr_import_batch_update_fail_';
        $GLOBALS['wpdb'] = new KeywordPoolImportBatchRepositoryTestWpdb($prefix, $this->columns($prefix));
        $GLOBALS['wpdb']->existing_batch_id = 22;
        $GLOBALS['wpdb']->fail_update_suffix = 'tmw_keyword_import_batches';

        $repository = new KeywordPoolImportBatchRepository();
        $batch_id = $repository->create_or_update_batch('category', [ 'import_batch_id' => 'existing-batch' ], [], 1);

        $this->assertSame(0, $batch_id);
        $this->assertSame('Update failed for test', $repository->last_error());
    }

    public function test_row_update_failure_returns_zero(): void {
        $prefix = 'wp_pr_import_row_update_fail_';
        $GLOBALS['wpdb'] = new KeywordPoolImportBatchRepositoryTestWpdb($prefix, $this->columns($prefix));
        $GLOBALS['wpdb']->existing_row_id = 33;
        $GLOBALS['wpdb']->fail_update_suffix = 'tmw_keyword_import_rows';

        $repository = new KeywordPoolImportBatchRepository();
        $row_id = $repository->persist_row(22, 'category', [ 'import_batch_id' => 'existing-batch' ], [ 'row_number' => 1, 'keyword' => 'big boob cam' ], 1);

        $this->assertSame(0, $row_id);
        $this->assertSame('Update failed for test', $repository->last_error());
    }

    public function test_row_level_failure_preserves_batch_and_records_failure_count(): void {
        $prefix = 'wp_pr_import_row_insert_fail_';
        $GLOBALS['wpdb'] = new KeywordPoolImportBatchRepositoryTestWpdb($prefix, $this->columns($prefix));
        $GLOBALS['wpdb']->fail_row_insert = true;

        $repository = new KeywordPoolImportBatchRepository();
        $batch_id = $repository->persist_import('category', [
            'target_type' => 'category_page',
            'target_id' => 4534,
            'import_batch_id' => 'batch-row-fails',
        ], [], [ [ 'row_number' => 1, 'keyword' => 'big boob cam' ] ]);

        $this->assertGreaterThan(0, $batch_id);
        $this->assertSame(1, $repository->row_failure_count());
        $this->assertSame('Row insert failed for test', $repository->last_error());
    }


    public function test_row_level_warning_reaches_service_and_admin_notice_paths(): void {
        $service_source = (string) file_get_contents(__DIR__ . '/../includes/keywords/class-keyword-pool-selected-import-service.php');
        $admin_source = (string) file_get_contents(__DIR__ . '/../includes/admin/class-keyword-pools-admin-page.php');

        $this->assertStringContainsString('Import batch persisted but one or more rows failed:', $service_source);
        $this->assertStringContainsString('row_persistence_failures', $service_source);
        $this->assertStringContainsString("'type' => 'warning'", $admin_source);
        $this->assertStringContainsString("sprintf('[TMW-KW-IMPORT] %s', \$persistence_error)", $admin_source);
    }

    public function test_failure_logging_uses_query_hash_not_raw_query(): void {
        $source = (string) file_get_contents(__DIR__ . '/../includes/keywords/class-keyword-pool-import-batch-repository.php');

        $this->assertStringContainsString('query_hash=', $source);
        $this->assertStringNotContainsString("' | query=' .", $source);
    }

    /** @return array<string,array<int,string>> */
    private function columns(string $prefix): array {
        return [
            $prefix . 'tmw_keyword_import_batches' => [ 'id', 'import_batch_id', 'pool', 'target_type', 'target_id', 'target_name', 'target_slug', 'source_batch', 'source_file', 'imported_at', 'created_by', 'total_rows', 'inserted', 'updated', 'queued', 'review_required', 'approved', 'rejected', 'skipped', 'blocked', 'errors', 'status', 'created_at', 'updated_at' ],
            $prefix . 'tmw_keyword_import_rows' => [ 'id', 'batch_id', 'import_batch_id', 'row_index', 'keyword', 'normalized_keyword', 'volume', 'cpc', 'competition', 'status', 'result_action', 'result_reason', 'validation_state', 'decision', 'target_type', 'target_id', 'target_name', 'candidate_id', 'row_payload', 'reviewed_by', 'reviewed_at', 'created_at', 'updated_at' ],
        ];
    }
}
