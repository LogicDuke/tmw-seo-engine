<?php
/**
 * Safe adapter for keyword pool candidate persistence.
 *
 * @package TMWSEO\Engine\Keywords
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

if (!defined('ABSPATH')) { exit; }

/**
 * Persists selected keyword-pool rows into the existing tmw_keyword_candidates table.
 */
class KeywordPoolCandidateRepository {

    private const ALLOWED_INTENTS = [ 'model', 'video', 'category' ];
    private const ALLOWED_STATUSES = [ 'new', 'discovered', 'scored', 'queued_for_review', 'approved', 'rejected', 'ignored' ];
    private const METRIC_COLUMNS = [ 'volume', 'difficulty', 'cpc', 'competition', 'opportunity', 'seo_score', 'traffic_value', 'trend', 'ad_difficulty', 'difficulty_proxy' ];

    /** @var array<string,array<string,bool>> */
    private static array $columns_cache = [];

    public function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_keyword_candidates';
    }

    public function table_exists(): bool {
        global $wpdb;
        $table = $this->table_name();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        return is_string($found) && strtolower($found) === strtolower($table);
    }

    /**
     * @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    public function save(array $candidate): array {
        global $wpdb;

        $keyword = $this->normalize_keyword((string) ($candidate['keyword'] ?? ''));
        $intent = $this->sanitize_intent((string) ($candidate['intent_type'] ?? ''));
        $status = $this->sanitize_status((string) ($candidate['status'] ?? 'queued_for_review'));
        $entity_type = $this->sanitize_entity_type((string) ($candidate['entity_type'] ?? $intent));
        $entity_id = max(0, (int) ($candidate['entity_id'] ?? 0));

        if ('' === $keyword || '' === $intent) {
            return $this->result($keyword, $intent, $status, 'error', 'invalid_candidate_scope', $entity_type, $entity_id);
        }
        if (!$this->table_exists()) {
            return $this->result($keyword, $intent, $status, 'error', 'keyword_candidate_table_unavailable', $entity_type, $entity_id);
        }

        $columns = $this->columns();
        foreach ([ 'keyword', 'intent_type', 'entity_id' ] as $required) {
            if (empty($columns[$required])) {
                return $this->result($keyword, $intent, $status, 'error', 'required_column_unavailable_' . $required, $entity_type, $entity_id);
            }
        }

        $existing = $this->find_existing_by_keyword($keyword);
        if (is_array($existing) && !$this->can_update_existing($existing, $intent, $entity_type, $entity_id)) {
            return $this->result($keyword, $intent, (string) ($existing['status'] ?? $status), 'conflict', 'existing_keyword_conflicting_scope', $entity_type, $entity_id);
        }

        $now = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
        $warnings = [];
        $data = [ 'keyword' => $keyword, 'intent_type' => $intent, 'entity_id' => $entity_id ];

        if (!empty($columns['canonical'])) {
            $data['canonical'] = (string) ($candidate['canonical'] ?? $keyword);
        }
        if (!empty($columns['intent'])) {
            $data['intent'] = $intent;
        }
        if (!empty($columns['entity_type'])) {
            $data['entity_type'] = $entity_type;
        }
        if (!empty($columns['status'])) {
            $data['status'] = $status;
        }
        if (!empty($columns['updated_at'])) {
            $data['updated_at'] = $now;
        }
        if (!is_array($existing) && !empty($columns['created_at'])) {
            $data['created_at'] = $now;
        }

        $unsupported_metrics = [];
        foreach (self::METRIC_COLUMNS as $metric) {
            if (!array_key_exists($metric, $candidate)) {
                continue;
            }
            if (!empty($columns[$metric])) {
                $data[$metric] = $candidate[$metric];
            } elseif (null !== $candidate[$metric] && '' !== $candidate[$metric]) {
                $unsupported_metrics[$metric] = $candidate[$metric];
            }
        }
        if ([] !== $unsupported_metrics) {
            $warnings[] = 'metric_column_unavailable_saved_to_notes';
        }

        $provenance = is_array($candidate['provenance'] ?? null) ? $candidate['provenance'] : [];
        if ([] !== $unsupported_metrics) {
            $provenance['unsupported_metrics'] = $unsupported_metrics;
        }

        if (!empty($columns['sources'])) {
            $data['sources'] = $this->encode_json($provenance);
        }
        if (!empty($columns['notes'])) {
            $data['notes'] = $this->encode_json([
                'keyword_pools_import' => $provenance,
                'warnings' => $warnings,
            ]);
        }

        if (is_array($existing)) {
            $id = (int) ($existing['id'] ?? 0);
            if ($id <= 0) {
                return $this->result($keyword, $intent, $status, 'conflict', 'existing_keyword_missing_id', $entity_type, $entity_id, $warnings);
            }
            $updated = $wpdb->update($this->table_name(), $data, [ 'id' => $id ]);
            if (false === $updated) {
                return $this->result($keyword, $intent, $status, 'error', 'database_update_failed', $entity_type, $entity_id, $warnings);
            }
            return $this->result($keyword, $intent, $status, 'updated', implode('|', $warnings) ?: 'same_scope_keyword_updated', $entity_type, $entity_id, $warnings, $id);
        }

        $inserted = $wpdb->insert($this->table_name(), $data);
        if (false === $inserted) {
            return $this->result($keyword, $intent, $status, 'error', 'database_insert_failed', $entity_type, $entity_id, $warnings);
        }
        return $this->result($keyword, $intent, $status, 'inserted', implode('|', $warnings) ?: 'keyword_inserted', $entity_type, $entity_id, $warnings, (int) $wpdb->insert_id);
    }

    public function normalize_keyword(string $keyword): string {
        $keyword = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($keyword) : strip_tags($keyword);
        $keyword = html_entity_decode($keyword, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $keyword = strtolower($keyword);
        $keyword = preg_replace('/[\x{2018}\x{2019}\x{201A}\x{201B}]/u', "'", $keyword);
        $keyword = preg_replace('/[\x{201C}\x{201D}\x{201E}\x{201F}]/u', '"', $keyword);
        $keyword = preg_replace('/[^\p{L}\p{N}\s\'"-]+/u', ' ', $keyword);
        $keyword = preg_replace('/\s+/u', ' ', (string) $keyword);
        return trim((string) $keyword);
    }

    /** @return array<string,bool> */
    private function columns(): array {
        global $wpdb;
        $table = $this->table_name();
        if (isset(self::$columns_cache[$table])) {
            return self::$columns_cache[$table];
        }
        $columns = [];
        $rows = $wpdb->get_results('SHOW COLUMNS FROM ' . $table, ARRAY_A);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $field = is_array($row) ? (string) ($row['Field'] ?? $row['field'] ?? '') : '';
                if ('' !== $field) {
                    $columns[$field] = true;
                }
            }
        }
        self::$columns_cache[$table] = $columns;
        return $columns;
    }

    /** @return array<string,mixed>|null */
    private function find_existing_by_keyword(string $keyword): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table_name() . ' WHERE keyword = %s LIMIT 1', $keyword), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $row */
    private function can_update_existing(array $row, string $intent, string $entity_type, int $entity_id): bool {
        $existing_intent = (string) ($row['intent_type'] ?? '');
        $existing_entity = (string) ($row['entity_type'] ?? '');
        $existing_id = (int) ($row['entity_id'] ?? 0);
        $existing_status = (string) ($row['status'] ?? '');

        if ($existing_intent === $intent && ($existing_entity === '' || $existing_entity === $entity_type) && ($existing_id === 0 || $existing_id === $entity_id)) {
            return true;
        }
        if (in_array($existing_intent, [ '', 'generic' ], true) && $existing_id === 0 && 'approved' !== $existing_status) {
            return true;
        }
        return false;
    }

    private function sanitize_intent(string $intent): string {
        $intent = function_exists('sanitize_key') ? sanitize_key($intent) : strtolower(preg_replace('/[^a-z0-9_-]/', '', $intent));
        return in_array($intent, self::ALLOWED_INTENTS, true) ? $intent : '';
    }

    private function sanitize_status(string $status): string {
        $status = function_exists('sanitize_key') ? sanitize_key($status) : strtolower(preg_replace('/[^a-z0-9_-]/', '', $status));
        return in_array($status, self::ALLOWED_STATUSES, true) ? $status : 'queued_for_review';
    }

    private function sanitize_entity_type(string $entity_type): string {
        $entity_type = function_exists('sanitize_key') ? sanitize_key($entity_type) : strtolower(preg_replace('/[^a-z0-9_-]/', '', $entity_type));
        return '' !== $entity_type ? $entity_type : 'keyword_pool';
    }

    private function encode_json($value): string {
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);
        return is_string($encoded) ? $encoded : '';
    }

    /** @return array<string,mixed> */
    private function result(string $keyword, string $pool, string $status, string $action, string $reason, string $entity_type, int $entity_id, array $warnings = [], int $id = 0): array {
        return [
            'id' => $id,
            'keyword' => $keyword,
            'pool' => $pool,
            'status' => $status,
            'action' => $action,
            'reason' => $reason,
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'warnings' => $warnings,
        ];
    }
}
