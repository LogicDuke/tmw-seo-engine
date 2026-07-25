<?php
/**
 * Durable keyword-pool import batch history persistence.
 *
 * @package TMWSEO\Engine\Keywords
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

if (!defined('ABSPATH')) { exit; }

class KeywordPoolImportBatchRepository {

    /** @var array<string,array<string,bool>> */
    private static array $columns_cache = [];

    private string $last_error = '';
    private string $last_query = '';
    private int $row_failure_count = 0;

    public function batches_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_keyword_import_batches';
    }

    public function rows_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_keyword_import_rows';
    }

    public function last_error(): string {
        return $this->last_error;
    }

    public function last_query(): string {
        return $this->last_query;
    }

    public function row_failure_count(): int {
        return $this->row_failure_count;
    }

    public function tables_exist(): bool {
        return empty($this->missing_tables());
    }

    /** @return array<int,string> */
    public function missing_tables(): array {
        global $wpdb;
        $missing = [];
        foreach ([ $this->batches_table(), $this->rows_table() ] as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if (!is_string($found) || strtolower($found) !== strtolower($table)) {
                $missing[] = $table;
            }
        }
        return $missing;
    }

    private function missing_table_message(): string {
        $missing = $this->missing_tables();
        if (!empty($missing)) {
            $rows_table = $this->rows_table();
            if (in_array($rows_table, $missing, true)) {
                $schema_error = (string) get_option('tmw_keyword_import_rows_schema_error', '');
                if ($schema_error !== '') {
                    return $schema_error;
                }
            }
            return 'Import history schema missing table: ' . $missing[0];
        }
        return 'Import history schema unavailable.';
    }

    /** @param array<string,mixed> $context @param array<int,array<string,mixed>> $rows */
    public function persist_import(string $pool, array $context, array $summary, array $rows): int {
        try {
            $this->clear_last_error();
            if (!$this->tables_exist()) {
                if (class_exists('TMWSEO\\Engine\\Schema') && method_exists('TMWSEO\\Engine\\Schema', 'ensure_keyword_import_history_schema')) {
                    \TMWSEO\Engine\Schema::ensure_keyword_import_history_schema();
                }
                if (!$this->tables_exist()) {
                    $message = $this->missing_table_message();
                    $this->record_failure($message, implode(', ', [ $this->batches_table(), $this->rows_table() ]), [], 'Persistence failed');
                    return 0;
                }
            }

            if ('' === $this->sanitize_text((string) ($context['import_batch_id'] ?? ''), 64)) {
                $context['import_batch_id'] = $this->generate_import_batch_id();
            }

            $batch_id = $this->create_or_update_batch($pool, $context, $summary, count($rows));
            if ($batch_id <= 0) {
                return 0;
            }

            foreach ($rows as $index => $row) {
                if (!is_array($row)) { continue; }
                if ($this->persist_row($batch_id, $pool, $context, $row, $index + 1) <= 0) {
                    $this->row_failure_count++;
                }
            }
            $this->recalculate_batch_counts($batch_id);
            return $batch_id;
        } catch (\Throwable $e) {
            $this->last_error = 'Import history persistence exception: ' . substr($e->getMessage(), 0, 200);
            error_log('[TMW-KW-IMPORT] persist_import exception: ' . $e->getMessage());
            return 0;
        }
    }

    /** @param array<string,mixed> $context */
    public function create_or_update_batch(string $pool, array $context, array $summary = [], int $total_rows = 0): int {
        global $wpdb;

        if (!$this->tables_exist()) {
            $message = $this->missing_table_message();
            $this->record_failure($message, implode(', ', [ $this->batches_table(), $this->rows_table() ]), [], 'Persistence failed');
            return 0;
        }

        $table = $this->batches_table();
        $now = $this->now();
        $import_batch_id = $this->sanitize_text((string) ($context['import_batch_id'] ?? ''), 64);
        if ('' === $import_batch_id) {
            $import_batch_id = $this->generate_import_batch_id();
        }

        $data = [
            'import_batch_id' => $import_batch_id,
            'pool' => $this->sanitize_pool($pool),
            'target_type' => $this->nullable_text((string) ($context['target_type'] ?? ''), 50),
            'target_id' => !empty($context['target_id']) ? max(0, (int) $context['target_id']) : null,
            'target_name' => $this->nullable_text((string) ($context['target_name'] ?? ''), 255),
            'target_slug' => $this->nullable_text((string) ($context['target_slug'] ?? ''), 191),
            'source_batch' => $this->nullable_text((string) ($context['source_batch'] ?? ''), 255),
            'source_file' => $this->nullable_text((string) ($context['source_file'] ?? ''), 255),
            'imported_at' => $this->sanitize_text((string) ($context['imported_at'] ?? $now), 32),
            'created_by' => function_exists('get_current_user_id') ? (int) get_current_user_id() : null,
            'total_rows' => max(0, $total_rows),
            'inserted' => max(0, (int) ($summary['inserted'] ?? 0)),
            'updated' => max(0, (int) ($summary['updated'] ?? 0)),
            'queued' => max(0, (int) ($summary['queued'] ?? 0)),
            'review_required' => max(0, (int) ($summary['review_required'] ?? 0)),
            'approved' => max(0, (int) ($summary['approved'] ?? 0)),
            'rejected' => max(0, (int) ($summary['rejected'] ?? 0)),
            'skipped' => max(0, (int) ($summary['skipped'] ?? 0)),
            'blocked' => max(0, (int) ($summary['blocked'] ?? 0)),
            'errors' => max(0, (int) ($summary['errors'] ?? 0)),
            'status' => $this->sanitize_key((string) ($context['status'] ?? 'open'), 30),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $data = $this->filter_data_for_table($table, $data);

        $existing_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE import_batch_id = %s LIMIT 1", $import_batch_id));
        if ($existing_id > 0) {
            unset($data['created_at']);
            $updated = $wpdb->update($table, $data, [ 'id' => $existing_id ]);
            if (false === $updated) {
                $this->record_failure($this->wpdb_error('Batch update failed.'), $table, array_keys($data), 'Batch update failed');
                return 0;
            }
            return $existing_id;
        }

        $inserted = $wpdb->insert($table, $data);
        if (false === $inserted || (int) $wpdb->insert_id <= 0) {
            $this->record_failure($this->wpdb_error('Batch insert failed.'), $table, array_keys($data), 'Batch insert failed');
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $result */
    public function persist_row(int $batch_id, string $pool, array $context, array $result, int $fallback_row_number = 0): int {
        global $wpdb;

        $table = $this->rows_table();
        $now = $this->now();
        $payload = is_array($result['_dry_run_row'] ?? null) ? $result['_dry_run_row'] : $result;
        $row_number = max(0, (int) ($payload['row_number'] ?? $result['row_number'] ?? $fallback_row_number));
        $keyword = $this->sanitize_text((string) ($payload['keyword'] ?? $result['keyword'] ?? ''), 255);
        $normalized = $this->sanitize_text((string) ($payload['normalized_keyword'] ?? $result['keyword'] ?? $keyword), 255);
        if ('' === $keyword) { $keyword = $normalized; }
        if ('' === $normalized) { $normalized = $keyword; }
        $action = $this->sanitize_key((string) ($result['action'] ?? $result['result_action'] ?? ''), 30);
        $reason = $this->sanitize_text((string) ($result['reason'] ?? $result['result_reason'] ?? ''), 255);
        $status = $this->status_from_result($result, $payload);

        $data = [
            'batch_id' => $batch_id,
            'import_batch_id' => $this->sanitize_text((string) ($context['import_batch_id'] ?? $result['import_batch_id'] ?? ''), 64),
            'row_index' => $row_number,
            'keyword' => $keyword,
            'normalized_keyword' => '' !== $normalized ? $normalized : null,
            'volume' => $this->nullable_int($payload['volume'] ?? $result['volume'] ?? null),
            'cpc' => $this->nullable_decimal($payload['cpc'] ?? $result['cpc'] ?? null),
            'competition' => $this->nullable_decimal($payload['competition'] ?? $result['competition'] ?? null),
            'status' => $status,
            'result_action' => '' !== $action ? $action : null,
            'result_reason' => '' !== $reason ? $reason : null,
            'validation_state' => $this->nullable_text((string) ($payload['validation_state'] ?? ''), 60),
            'decision' => $this->nullable_text((string) ($payload['decision'] ?? ''), 60),
            'target_type' => $this->nullable_text((string) ($context['target_type'] ?? $result['target_type'] ?? ''), 50),
            'target_id' => !empty($context['target_id']) ? max(0, (int) $context['target_id']) : (!empty($result['target_id']) ? max(0, (int) $result['target_id']) : null),
            'target_name' => $this->nullable_text((string) ($context['target_name'] ?? $result['target_name'] ?? ''), 255),
            'candidate_id' => !empty($result['candidate_id']) ? max(0, (int) $result['candidate_id']) : (!empty($result['id']) ? max(0, (int) $result['id']) : null),
            'row_payload' => $this->encode_json($payload),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $data = $this->filter_data_for_table($table, $data);

        $existing_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE batch_id = %d AND row_index = %d LIMIT 1", $batch_id, $row_number));
        if ($existing_id > 0) {
            unset($data['created_at']);
            $updated = $wpdb->update($table, $data, [ 'id' => $existing_id ]);
            if (false === $updated) {
                $this->record_failure($this->wpdb_error('Import row update failed.'), $table, array_keys($data), 'Import row update failed');
                return 0;
            }
            return $existing_id;
        }

        $inserted = $wpdb->insert($table, $data);
        if (false === $inserted || (int) $wpdb->insert_id <= 0) {
            $this->record_failure($this->wpdb_error('Import row insert failed.'), $table, array_keys($data), 'Import row insert failed');
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /** @return array<int,array<string,mixed>> */
    public function query_batches(string $pool, ?string $target_type = null, ?int $target_id = null, int $limit = 20): array {
        global $wpdb;
        if (!$this->tables_exist()) { return []; }
        $table = $this->batches_table();
        $where = [ 'pool = %s' ];
        $args = [ $this->sanitize_pool($pool) ];
        if (null !== $target_type && '' !== $target_type) { $where[] = 'target_type = %s'; $args[] = $this->sanitize_text($target_type, 50); }
        if (null !== $target_id && $target_id > 0) { $where[] = 'target_id = %d'; $args[] = $target_id; }
        $args[] = max(1, min(100, $limit));
        return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY imported_at DESC, id DESC LIMIT %d', $args), ARRAY_A);
    }

    /** @return array<string,mixed>|null */
    public function get_batch(int $batch_id): ?array {
        global $wpdb;
        if (!$this->tables_exist()) { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->batches_table() . ' WHERE id = %d LIMIT 1', $batch_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * Describe whether a numeric import-history batch can be safely deleted.
     * Candidate records are deliberately counted but never modified.
     *
     * @return array{ok:bool,deleted_rows:int,batch_deleted:bool,candidates_preserved:int,safe_reason:string}
     */
    public function batch_deletion_contract(int $batch_id): array {
        global $wpdb;
        $result = $this->deletion_result(false, 'invalid_batch_id');
        if ($batch_id <= 0) {
            return $result;
        }
        if (!$this->tables_exist()) {
            return $this->deletion_result(false, 'import_history_tables_missing');
        }
        if (!$this->transaction_tables_supported()) {
            return $this->deletion_result(false, 'non_transactional_table');
        }
        if (!is_array($this->get_batch($batch_id))) {
            return $this->deletion_result(false, 'batch_not_found');
        }

        $wpdb->last_error = '';
        $counts = $wpdb->get_row($wpdb->prepare(
            'SELECT COUNT(*) AS expected_rows, COUNT(DISTINCT CASE WHEN candidate_id IS NOT NULL AND candidate_id > 0 THEN candidate_id END) AS candidates_preserved FROM ' . $this->rows_table() . ' WHERE batch_id = %d',
            $batch_id
        ), ARRAY_A);
        if (!is_array($counts) || '' !== (string) $wpdb->last_error) {
            return $this->deletion_result(false, 'batch_contract_query_failed');
        }
        $result = $this->deletion_result(true, 'candidates_preserved', 0, false, max(0, (int) ($counts['candidates_preserved'] ?? 0)));
        return $result;
    }

    /**
     * Delete child rows only while delete_batch() owns the transaction and lock.
     */
    private function delete_batch_rows(int $batch_id, int $expected_rows): int|false {
        global $wpdb;
        $deleted = $wpdb->delete($this->rows_table(), [ 'batch_id' => $batch_id ], [ '%d' ]);
        if (false === $deleted || $expected_rows !== (int) $deleted || '' !== (string) $wpdb->last_error) {
            return false;
        }
        return (int) $deleted;
    }

    /**
     * Atomically delete one import-history batch and its rows. No candidate or
     * WordPress content/SEO/taxonomy tables are read for ownership or written.
     *
     * @return array{ok:bool,deleted_rows:int,batch_deleted:bool,candidates_preserved:int,safe_reason:string}
     */
    public function delete_batch(int $batch_id): array {
        global $wpdb;
        if ($batch_id <= 0) {
            return $this->deletion_result(false, 'invalid_batch_id');
        }
        if (!$this->tables_exist()) {
            return $this->deletion_result(false, 'import_history_tables_missing');
        }
        if (!$this->transaction_tables_supported()) {
            return $this->deletion_result(false, 'non_transactional_table');
        }

        $wpdb->last_error = '';
        if (false === $wpdb->query('START TRANSACTION') || '' !== (string) $wpdb->last_error) {
            return $this->deletion_result(false, 'transaction_start_failed');
        }

        $batch = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->batches_table() . ' WHERE id = %d LIMIT 1 FOR UPDATE', $batch_id), ARRAY_A);
        if (!is_array($batch) || '' !== (string) $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return $this->deletion_result(false, 'batch_not_found');
        }
        if (!$this->transaction_tables_supported()) {
            $wpdb->query('ROLLBACK');
            return $this->deletion_result(false, 'non_transactional_table');
        }

        $locked_rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, candidate_id FROM ' . $this->rows_table() . ' WHERE batch_id = %d FOR UPDATE',
            $batch_id
        ), ARRAY_A);
        if (!is_array($locked_rows) || '' !== (string) $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return $this->deletion_result(false, 'batch_contract_query_failed');
        }
        $expected_rows = count($locked_rows);
        $candidate_ids = [];
        foreach ($locked_rows as $locked_row) {
            $candidate_id = (int) ($locked_row['candidate_id'] ?? 0);
            if ($candidate_id > 0) { $candidate_ids[$candidate_id] = true; }
        }
        $candidates = count($candidate_ids);
        $rows = $this->delete_batch_rows($batch_id, $expected_rows);
        if (false === $rows) {
            $wpdb->query('ROLLBACK');
            return $this->deletion_result(false, 'child_row_deletion_failed', 0, false, $candidates);
        }
        $batch_deleted = $wpdb->delete($this->batches_table(), [ 'id' => $batch_id ], [ '%d' ]);
        if (1 !== $batch_deleted || '' !== (string) $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return $this->deletion_result(false, 'batch_row_deletion_mismatch', 0, false, $candidates);
        }
        if (false === $wpdb->query('COMMIT') || '' !== (string) $wpdb->last_error) {
            $wpdb->query('ROLLBACK');
            return $this->deletion_result(false, 'transaction_commit_failed', 0, false, $candidates);
        }

        error_log('[TMW-KW-BATCH-DELETE] Deleted import-history batch_id=' . $batch_id . '; rows=' . (int) $rows . '; candidates_preserved=' . $candidates);
        return $this->deletion_result(true, 'batch_deleted_candidates_preserved', (int) $rows, true, $candidates);
    }

    public function transaction_tables_supported(): bool {
        global $wpdb;
        foreach ([ $this->batches_table(), $this->rows_table() ] as $table) {
            $wpdb->last_error = '';
            $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)), ARRAY_A);
            if (!is_array($status) || 'innodb' !== strtolower((string) ($status['Engine'] ?? '')) || '' !== (string) $wpdb->last_error) {
                return false;
            }
        }
        return true;
    }

    /** @return array{ok:bool,deleted_rows:int,batch_deleted:bool,candidates_preserved:int,safe_reason:string} */
    private function deletion_result(bool $ok, string $reason, int $rows = 0, bool $batch_deleted = false, int $candidates = 0): array {
        return [
            'ok' => $ok,
            'deleted_rows' => max(0, $rows),
            'batch_deleted' => $batch_deleted,
            'candidates_preserved' => max(0, $candidates),
            'safe_reason' => $reason,
        ];
    }

    /** @return array<string,mixed>|null */
    public function get_row(int $row_id): ?array {
        global $wpdb;
        if (!$this->tables_exist()) { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->rows_table() . ' WHERE id = %d LIMIT 1', $row_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function query_rows(int $batch_id, string $status = '', int $limit = 100, int $offset = 0, string $orderby = '', string $order = 'desc', string $search = ''): array {
        global $wpdb;
        if (!$this->tables_exist()) { return []; }
        $table = $this->rows_table();
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $orderby = $this->sanitize_key($orderby, 30);
        $order = 'asc' === strtolower($order) ? 'ASC' : 'DESC';
        $order_clause = 'row_index ASC, id ASC';
        if ('volume' === $orderby) {
            $order_clause = 'COALESCE(volume, 0) ' . $order . ', row_index ASC, id ASC';
        }

        $where = [ 'batch_id = %d' ];
        $args = [ $batch_id ];
        if ('' !== $status) {
            $where[] = 'status = %s';
            $args[] = $this->sanitize_key($status, 30);
        }
        $search = $this->sanitize_text($search, 100);
        if ('' !== $search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(keyword LIKE %s OR normalized_keyword LIKE %s)';
            $args[] = $like;
            $args[] = $like;
        }
        $args[] = $limit;
        $args[] = $offset;

        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY {$order_clause} LIMIT %d OFFSET %d", ...$args), ARRAY_A);
    }

    public function count_rows(int $batch_id, string $status = '', string $search = ''): int {
        global $wpdb;
        if (!$this->tables_exist()) { return 0; }
        $table = $this->rows_table();
        $where = [ 'batch_id = %d' ];
        $args = [ $batch_id ];
        if ('' !== $status) {
            $where[] = 'status = %s';
            $args[] = $this->sanitize_key($status, 30);
        }
        $search = $this->sanitize_text($search, 100);
        if ('' !== $search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(keyword LIKE %s OR normalized_keyword LIKE %s)';
            $args[] = $like;
            $args[] = $like;
        }
        return max(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE " . implode(' AND ', $where), ...$args)));
    }

    /** @param array<string,mixed> $updates */
    public function update_import_row(int $row_id, array $updates): bool {
        global $wpdb;
        $allowed = [ 'status', 'result_action', 'result_reason', 'candidate_id', 'reviewed_by', 'reviewed_at', 'updated_at' ];
        $data = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $updates)) { continue; }
            if (in_array($key, [ 'candidate_id', 'reviewed_by' ], true)) {
                $data[$key] = null === $updates[$key] ? null : max(0, (int) $updates[$key]);
            } elseif ('updated_at' === $key || 'reviewed_at' === $key) {
                $data[$key] = null === $updates[$key] ? null : $this->sanitize_text((string) $updates[$key], 32);
            } elseif ('status' === $key || 'result_action' === $key) {
                $data[$key] = $this->sanitize_key((string) $updates[$key], 30);
            } else {
                $data[$key] = $this->sanitize_text((string) $updates[$key], 255);
            }
        }
        $data['updated_at'] = $data['updated_at'] ?? $this->now();
        if (empty($data)) { return false; }
        $updated = $wpdb->update($this->rows_table(), $data, [ 'id' => $row_id ]);
        return false !== $updated;
    }

    public function update_candidate_status(int $candidate_id, string $status): bool {
        global $wpdb;
        if ($candidate_id <= 0) { return false; }
        $status = in_array($status, [ 'approved', 'ignored' ], true) ? $status : 'ignored';
        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $existing_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id = %d LIMIT 1", $candidate_id));
        if ($existing_id <= 0) { return false; }
        $updated = $wpdb->update($table, [ 'status' => $status, 'updated_at' => $this->now() ], [ 'id' => $candidate_id ], [ '%s', '%s' ], [ '%d' ]);
        return false !== $updated;
    }

    public function recalculate_batch_counts(int $batch_id): void {
        global $wpdb;
        if (!$this->tables_exist()) { return; }
        $rows_table = $this->rows_table();
        $batch_table = $this->batches_table();
        $counts = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total_rows,
                SUM(result_action = 'inserted') AS inserted,
                SUM(result_action = 'updated') AS updated,
                SUM(status = 'queued_for_review') AS queued,
                SUM(status = 'review_required') AS review_required,
                SUM(status = 'approved') AS approved,
                SUM(status = 'rejected') AS rejected,
                SUM(status = 'skipped') AS skipped,
                SUM(status = 'blocked') AS blocked,
                SUM(status = 'error') AS errors
             FROM {$rows_table} WHERE batch_id = %d",
            $batch_id
        ), ARRAY_A);
        if (!is_array($counts)) { return; }
        $data = [ 'updated_at' => $this->now() ];
        foreach ([ 'total_rows', 'inserted', 'updated', 'queued', 'review_required', 'approved', 'rejected', 'skipped', 'blocked', 'errors' ] as $key) {
            $data[$key] = max(0, (int) ($counts[$key] ?? 0));
        }
        $wpdb->update($batch_table, $data, [ 'id' => $batch_id ]);
    }


    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function filter_data_for_table(string $table, array $data): array {
        $columns = $this->table_columns($table);
        if (empty($columns)) {
            return $data;
        }
        return array_intersect_key($data, $columns);
    }

    /** @return array<string,bool> */
    private function table_columns(string $table): array {
        global $wpdb;
        if (isset(self::$columns_cache[$table])) {
            return self::$columns_cache[$table];
        }

        $columns = [];
        $results = $wpdb->get_results('SHOW COLUMNS FROM ' . $table, ARRAY_A);
        foreach ((array) $results as $row) {
            $field = is_array($row) ? (string) ($row['Field'] ?? '') : (string) ($row->Field ?? '');
            if ('' !== $field) {
                $columns[$field] = true;
            }
        }
        self::$columns_cache[$table] = $columns;
        return $columns;
    }

    /** @param array<int,string> $data_keys */
    private function record_failure(string $message, string $table, array $data_keys, string $event = 'Persistence failed'): void {
        global $wpdb;
        $message = '' !== trim($message) ? $this->sanitize_text($message, 255) : 'Unknown database error.';
        $this->last_error = $message;
        $this->last_query = isset($wpdb->last_query) ? (string) $wpdb->last_query : '';

        $event = $this->sanitize_text($event, 80);
        $log = sprintf(
            '[TMW-KW-IMPORT] %s: %s | table=%s | keys=%s',
            '' !== $event ? $event : 'Persistence failed',
            $message,
            $table,
            implode(',', array_map(static fn($key): string => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $key) ?? '', $data_keys))
        );
        if ('' !== $this->last_query) {
            $log .= ' | query_hash=' . sha1($this->last_query);
        }
        error_log($log);
    }

    private function wpdb_error(string $fallback): string {
        global $wpdb;
        $error = isset($wpdb->last_error) ? trim((string) $wpdb->last_error) : '';
        return '' !== $error ? $error : $fallback;
    }

    private function clear_last_error(): void {
        $this->last_error = '';
        $this->last_query = '';
        $this->row_failure_count = 0;
    }

    private function generate_import_batch_id(): string {
        return function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16));
    }

    /** @param array<string,mixed> $row */
    private function status_from_result(array $row, array $payload): string {
        $action = strtolower((string) ($row['action'] ?? $row['result_action'] ?? ''));
        $status = strtolower((string) ($row['status'] ?? ''));
        $reason = strtolower((string) ($row['reason'] ?? ''));
        if ('blocked' === $action) { return str_contains($reason, 'review_required') ? 'review_required' : 'blocked'; }
        if ('skipped' === $action) { return 'skipped'; }
        if ('error' === $action) { return 'error'; }
        if ('conflict' === $action) { return 'blocked'; }
        if ('approved' === $status) { return 'approved'; }
        if ('queued_for_review' === $status) { return 'queued_for_review'; }
        if ('rejected' === $status || 'ignored' === $status) { return 'rejected'; }
        if ('review_required' === (string) ($payload['validation_state'] ?? '')) { return 'review_required'; }
        return '' !== $status ? $this->sanitize_key($status, 30) : 'review_required';
    }

    private function sanitize_pool(string $pool): string { return in_array($pool, [ 'model', 'video', 'category' ], true) ? $pool : 'model'; }
    private function sanitize_key(string $value, int $max): string { $value = function_exists('sanitize_key') ? sanitize_key($value) : strtolower(preg_replace('/[^a-z0-9_\-]/', '', $value) ?? ''); return substr($value, 0, $max); }
    private function sanitize_text(string $value, int $max): string { $value = function_exists('sanitize_text_field') ? sanitize_text_field($value) : trim(strip_tags($value)); return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max); }
    private function nullable_text(string $value, int $max): ?string { $value = $this->sanitize_text($value, $max); return '' === $value ? null : $value; }
    private function nullable_int($value): ?int { return (null === $value || '' === (string) $value) ? null : (int) $value; }
    private function nullable_decimal($value): ?float { return (null === $value || '' === (string) $value || !is_numeric($value)) ? null : (float) $value; }
    private function now(): string { return function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'); }

    /** @param mixed $value */
    private function encode_json($value): string {
        $json = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);
        return is_string($json) ? $json : '{}';
    }
}
