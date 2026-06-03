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

    public function tables_exist(): bool {
        global $wpdb;
        foreach ([ $this->batches_table(), $this->rows_table() ] as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if (!is_string($found) || strtolower($found) !== strtolower($table)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $context @param array<int,array<string,mixed>> $rows */
    public function persist_import(string $pool, array $context, array $summary, array $rows): int {
        $this->clear_last_error();
        if (!$this->tables_exist()) {
            if (class_exists('TMWSEO\\Engine\\Schema') && method_exists('TMWSEO\\Engine\\Schema', 'ensure_keyword_import_history_schema')) {
                \TMWSEO\Engine\Schema::ensure_keyword_import_history_schema();
            }
            if (!$this->tables_exist()) {
                $this->record_failure('Import history tables do not exist after schema ensure.', implode(', ', [ $this->batches_table(), $this->rows_table() ]), []);
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
            $this->persist_row($batch_id, $pool, $context, $row, $index + 1);
        }
        $this->recalculate_batch_counts($batch_id);
        return $batch_id;
    }

    /** @param array<string,mixed> $context */
    public function create_or_update_batch(string $pool, array $context, array $summary = [], int $total_rows = 0): int {
        global $wpdb;

        if (!$this->tables_exist()) {
            $this->record_failure('Import history tables do not exist.', implode(', ', [ $this->batches_table(), $this->rows_table() ]), []);
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
                $this->record_failure($this->wpdb_error('Batch update failed.'), $table, array_keys($data));
            }
            return $existing_id;
        }

        $inserted = $wpdb->insert($table, $data);
        if (false === $inserted || (int) $wpdb->insert_id <= 0) {
            $this->record_failure($this->wpdb_error('Batch insert failed.'), $table, array_keys($data));
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
            'row_number' => $row_number,
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

        $existing_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE batch_id = %d AND row_number = %d LIMIT 1", $batch_id, $row_number));
        if ($existing_id > 0) {
            unset($data['created_at']);
            $updated = $wpdb->update($table, $data, [ 'id' => $existing_id ]);
            if (false === $updated) {
                $this->record_failure($this->wpdb_error('Import row update failed.'), $table, array_keys($data));
            }
            return $existing_id;
        }

        $inserted = $wpdb->insert($table, $data);
        if (false === $inserted || (int) $wpdb->insert_id <= 0) {
            $this->record_failure($this->wpdb_error('Import row insert failed.'), $table, array_keys($data));
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

    /** @return array<string,mixed>|null */
    public function get_row(int $row_id): ?array {
        global $wpdb;
        if (!$this->tables_exist()) { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->rows_table() . ' WHERE id = %d LIMIT 1', $row_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function query_rows(int $batch_id, string $status = '', int $limit = 100, int $offset = 0): array {
        global $wpdb;
        if (!$this->tables_exist()) { return []; }
        $table = $this->rows_table();
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        if ('' !== $status) {
            return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE batch_id = %d AND status = %s ORDER BY row_number ASC, id ASC LIMIT %d OFFSET %d", $batch_id, $this->sanitize_key($status, 30), $limit, $offset), ARRAY_A);
        }
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE batch_id = %d ORDER BY row_number ASC, id ASC LIMIT %d OFFSET %d", $batch_id, $limit, $offset), ARRAY_A);
    }

    public function count_rows(int $batch_id, string $status = ''): int {
        global $wpdb;
        if (!$this->tables_exist()) { return 0; }
        $table = $this->rows_table();
        if ('' !== $status) {
            return max(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE batch_id = %d AND status = %s", $batch_id, $this->sanitize_key($status, 30))));
        }
        return max(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE batch_id = %d", $batch_id)));
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
    private function record_failure(string $message, string $table, array $data_keys): void {
        global $wpdb;
        $message = '' !== trim($message) ? trim($message) : 'Unknown database error.';
        $this->last_error = $message;
        $this->last_query = isset($wpdb->last_query) ? (string) $wpdb->last_query : '';

        $log = sprintf(
            '[TMW-KW-IMPORT] Batch insert failed: %s | table=%s | keys=%s',
            $message,
            $table,
            implode(',', array_map(static fn($key): string => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $key) ?? '', $data_keys))
        );
        if ('' !== $this->last_query) {
            $log .= ' | query=' . $this->last_query;
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
