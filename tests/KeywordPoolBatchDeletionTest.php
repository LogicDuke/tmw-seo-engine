<?php
/** Safe deletion tests for durable keyword import history. */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordPoolImportBatchRepository;

require_once __DIR__ . '/../includes/keywords/class-keyword-pool-import-batch-repository.php';

final class KeywordPoolBatchDeletionWpdb {
    public string $prefix = 'wp_delete_test_';
    public string $last_error = '';
    /** @var array<int,array<string,mixed>> */ public array $batches = [];
    /** @var array<int,array<string,mixed>> */ public array $rows = [];
    /** @var array<int,array<string,mixed>> */ public array $candidates = [];
    /** @var array<int,string> */ public array $queries = [];
    public bool $fail_child_delete = false;
    public bool $fail_batch_delete = false;

    public function esc_like(string $value): string { return addcslashes($value, '_%\\'); }
    public function prepare(string $sql, ...$args): string {
        $i = 0;
        return (string) preg_replace_callback('/%[sdf]/', static function () use (&$i, $args): string {
            $value = $args[$i++] ?? '';
            return is_string($value) ? "'" . addslashes($value) . "'" : (string) $value;
        }, $sql);
    }
    public function get_var(string $sql) {
        if (str_starts_with($sql, 'SHOW TABLES LIKE')) {
            preg_match("/'([^']+)'/", $sql, $match);
            return stripslashes($match[1] ?? '');
        }
        return 0;
    }
    public function get_row(string $sql, string $output = 'OBJECT'): ?array {
        if (str_contains($sql, 'COUNT(*) AS expected_rows')) {
            preg_match('/batch_id = (\d+)/', $sql, $match);
            $ids = [];
            $rows = 0;
            foreach ($this->rows as $row) {
                if ((int) ($row['batch_id'] ?? 0) === (int) ($match[1] ?? 0)) {
                    $rows++;
                    if ((int) ($row['candidate_id'] ?? 0) > 0) { $ids[(int) $row['candidate_id']] = true; }
                }
            }
            return [ 'expected_rows' => $rows, 'candidates_preserved' => count($ids) ];
        }
        if (str_contains($sql, 'tmw_keyword_import_batches') && preg_match('/id = (\d+)/', $sql, $match)) {
            $id = (int) $match[1];
            return isset($this->batches[$id]) ? $this->batches[$id] : null;
        }
        return null;
    }
    public function delete(string $table, array $where, array $format = []) {
        if (str_ends_with($table, 'tmw_keyword_import_rows')) {
            if ($this->fail_child_delete) { $this->last_error = 'child failure'; return false; }
            $count = 0;
            foreach ($this->rows as $key => $row) {
                if ((int) $row['batch_id'] === (int) $where['batch_id']) { unset($this->rows[$key]); $count++; }
            }
            return $count;
        }
        if ($this->fail_batch_delete) { return 0; }
        $id = (int) $where['id'];
        if (!isset($this->batches[$id])) { return 0; }
        unset($this->batches[$id]);
        return 1;
    }
    public function query(string $sql) {
        $this->queries[] = $sql;
        return 1;
    }
}

final class KeywordPoolBatchDeletionTest extends TestCase {
    private KeywordPoolBatchDeletionWpdb $wpdb;
    private KeywordPoolImportBatchRepository $repository;

    protected function setUp(): void {
        $this->wpdb = new KeywordPoolBatchDeletionWpdb();
        $this->wpdb->batches = [
            10 => [ 'id' => 10, 'source_file' => 'same.csv', 'approved' => 0 ],
            11 => [ 'id' => 11, 'source_file' => 'same.csv', 'approved' => 1 ],
        ];
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->repository = new KeywordPoolImportBatchRepository();
    }

    public function test_delete_empty_unreviewed_batch(): void {
        $result = $this->repository->delete_batch(10);
        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['deleted_rows']);
    }

    public function test_delete_batch_with_queued_rows_and_history_together(): void {
        $this->wpdb->rows[] = [ 'id' => 1, 'batch_id' => 10, 'status' => 'queued_for_review', 'candidate_id' => null ];
        $result = $this->repository->delete_batch(10);
        $this->assertSame(1, $result['deleted_rows']);
        $this->assertTrue($result['batch_deleted']);
        $this->assertArrayNotHasKey(10, $this->wpdb->batches);
        $this->assertSame([], $this->wpdb->rows);
    }

    public function test_other_batches_and_duplicate_filenames_remain_untouched(): void {
        $this->wpdb->rows = [ [ 'id' => 1, 'batch_id' => 10 ], [ 'id' => 2, 'batch_id' => 11 ] ];
        $this->repository->delete_batch(10);
        $this->assertArrayHasKey(11, $this->wpdb->batches);
        $this->assertSame(11, $this->wpdb->rows[1]['batch_id']);
    }

    public function test_approved_candidate_records_are_preserved(): void {
        $this->wpdb->candidates[50] = [ 'id' => 50, 'status' => 'approved', 'import_batch_id' => 'batch-10' ];
        $this->wpdb->rows[] = [ 'id' => 1, 'batch_id' => 10, 'status' => 'approved', 'candidate_id' => 50 ];
        $result = $this->repository->delete_batch(10);
        $this->assertSame(1, $result['candidates_preserved']);
        $this->assertSame('approved', $this->wpdb->candidates[50]['status']);
        $this->assertSame('batch-10', $this->wpdb->candidates[50]['import_batch_id']);
    }

    public function test_candidate_linked_to_another_batch_is_preserved(): void {
        $this->wpdb->candidates[50] = [ 'id' => 50, 'status' => 'approved' ];
        $this->wpdb->rows = [ [ 'id' => 1, 'batch_id' => 10, 'candidate_id' => 50 ], [ 'id' => 2, 'batch_id' => 11, 'candidate_id' => 50 ] ];
        $this->repository->delete_batch(10);
        $this->assertArrayHasKey(50, $this->wpdb->candidates);
        $this->assertSame(11, $this->wpdb->rows[1]['batch_id']);
    }

    public function test_invalid_and_repeated_deletion_fail_closed(): void {
        $this->assertSame('invalid_batch_id', $this->repository->delete_batch(0)['safe_reason']);
        $this->repository->delete_batch(10);
        $this->assertSame('batch_not_found', $this->repository->delete_batch(10)['safe_reason']);
    }

    public function test_child_failure_and_batch_mismatch_roll_back(): void {
        $this->wpdb->fail_child_delete = true;
        $this->assertSame('child_row_deletion_failed', $this->repository->delete_batch(10)['safe_reason']);
        $this->assertContains('ROLLBACK', $this->wpdb->queries);
        $this->wpdb->fail_child_delete = false;
        $this->wpdb->last_error = '';
        $this->wpdb->fail_batch_delete = true;
        $this->assertSame('batch_row_deletion_mismatch', $this->repository->delete_batch(10)['safe_reason']);
    }

    public function test_no_content_taxonomy_seo_indexing_or_candidate_writes_occur(): void {
        $this->repository->delete_batch(10);
        $sql = implode(' ', $this->wpdb->queries);
        foreach ([ 'posts', 'postmeta', 'terms', 'termmeta', 'rank_math', 'canonical', 'robots', 'tmw_keyword_candidates' ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sql);
        }
    }
}
