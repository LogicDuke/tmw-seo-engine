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
        $target_type = $this->sanitize_optional_key((string) ($candidate['target_type'] ?? ''));
        $target_id = array_key_exists('target_id', $candidate) && null !== $candidate['target_id'] ? max(0, (int) $candidate['target_id']) : null;
        $target_name = $this->sanitize_optional_text((string) ($candidate['target_name'] ?? ''), 255);
        $target_slug = $this->sanitize_optional_text((string) ($candidate['target_slug'] ?? ''), 191);
        $source_batch = $this->sanitize_optional_text((string) ($candidate['source_batch'] ?? ''), 255);
        $source_file = $this->sanitize_optional_text((string) ($candidate['source_file'] ?? ''), 255);
        $import_batch_id = $this->sanitize_optional_text((string) ($candidate['import_batch_id'] ?? ''), 64);
        $imported_at = $this->sanitize_optional_text((string) ($candidate['imported_at'] ?? ''), 32);
        $canonical = array_key_exists('canonical', $candidate)
            ? $this->normalize_keyword((string) $candidate['canonical'])
            : $keyword;
        if ('' === $canonical) {
            $canonical = $keyword;
        }

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
        if (is_array($existing) && !$this->target_scope_matches_existing($existing, $target_type, $target_id)) {
            return $this->result($keyword, $intent, (string) ($existing['status'] ?? $status), 'conflict', 'existing_keyword_has_different_target', $entity_type, $entity_id, [], 0, $this->existing_target_context($existing));
        }
        if (is_array($existing) && !$this->can_update_existing($existing, $candidate, $intent, $entity_type, $entity_id)) {
            $conflict_reason = $this->existing_conflict_reason($existing, $candidate, $intent, $entity_type, $entity_id);
            return $this->result($keyword, $intent, (string) ($existing['status'] ?? $status), 'conflict', $conflict_reason, $entity_type, $entity_id);
        }

        $now = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
        $warnings = [];
        $data = [ 'keyword' => $keyword, 'intent_type' => $intent, 'entity_id' => $entity_id ];

        if (!empty($columns['canonical'])) {
            $data['canonical'] = $canonical;
        }
        if (!empty($columns['intent'])) {
            $data['intent'] = $intent;
        }
        if (!empty($columns['entity_type'])) {
            $data['entity_type'] = $entity_type;
        }
        if (!empty($columns['status'])) {
            $existing_status = is_array($existing) ? (string) ($existing['status'] ?? '') : '';
            $explicit_status_change = !empty($candidate['status_change_explicit']);
            $data['status'] = is_array($existing) && !$explicit_status_change && '' !== $existing_status ? $existing_status : $status;
        }
        if (!empty($columns['updated_at'])) {
            $data['updated_at'] = $now;
        }
        if (!is_array($existing) && !empty($columns['created_at'])) {
            $data['created_at'] = $now;
        }
        $target_source_fields = [
            'target_type' => '' !== $target_type ? $target_type : null,
            'target_id' => null !== $target_id && $target_id > 0 ? $target_id : null,
            'target_name' => '' !== $target_name ? $target_name : null,
            'target_slug' => '' !== $target_slug ? $target_slug : null,
            'source_batch' => '' !== $source_batch ? $source_batch : null,
            'source_file' => '' !== $source_file ? $source_file : null,
            'import_batch_id' => '' !== $import_batch_id ? $import_batch_id : null,
        ];
        foreach ($target_source_fields as $field => $value) {
            if (!empty($columns[$field]) && (null !== $value || !is_array($existing))) {
                $data[$field] = $value;
            }
        }
        if (!empty($columns['imported_at'])) {
            $existing_imported_at = is_array($existing) ? trim((string) ($existing['imported_at'] ?? '')) : '';
            if (!is_array($existing) || '' === $existing_imported_at) {
                $data['imported_at'] = '' !== $imported_at ? $imported_at : $now;
            }
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
            $existing_sources = is_array($existing) ? ($existing['sources'] ?? null) : null;
            $data['sources'] = $this->encode_json($this->merge_sources($existing_sources, $provenance));
        }
        if (!empty($columns['notes'])) {
            $existing_notes = is_array($existing) ? ($existing['notes'] ?? null) : null;
            $data['notes'] = $this->encode_json($this->merge_notes($existing_notes, $provenance, $warnings));
        }

        $result_status = (string) ($data['status'] ?? $status);
        if (is_array($existing)) {
            $id = (int) ($existing['id'] ?? 0);
            if ($id <= 0) {
                return $this->result($keyword, $intent, $result_status, 'conflict', 'existing_keyword_missing_id', $entity_type, $entity_id, $warnings);
            }
            $updated = $wpdb->update($this->table_name(), $data, [ 'id' => $id ]);
            if (false === $updated) {
                return $this->result($keyword, $intent, $result_status, 'error', 'database_update_failed', $entity_type, $entity_id, $warnings);
            }
            return $this->result($keyword, $intent, $result_status, 'updated', implode('|', $warnings) ?: 'same_scope_keyword_updated', $entity_type, $entity_id, $warnings, $id);
        }

        $inserted = $wpdb->insert($this->table_name(), $data);
        if (false === $inserted) {
            return $this->result($keyword, $intent, $result_status, 'error', 'database_insert_failed', $entity_type, $entity_id, $warnings);
        }
        return $this->result($keyword, $intent, $result_status, 'inserted', implode('|', $warnings) ?: 'keyword_inserted', $entity_type, $entity_id, $warnings, (int) $wpdb->insert_id);
    }


    /**
     * Classify one keyword candidate and optionally merge safe classification metadata into sources JSON.
     *
     * Dry-run mode returns the proposed metadata without writing. Apply mode only writes the
     * PR 602 classification metadata keys to sources; it does not change status, scope,
     * entity ownership, metrics, content, or SEO fields.
     *
     * @return array<string,mixed>
     */
    public function classify_candidate_phrase(string $keyword, array $context = [], bool $apply = false, ?int $candidate_id = null): array {
        global $wpdb;

        $classifier = new ModelKeywordPoolClassifier();
        $classification = $classifier->classify($keyword, $context);
        $metadata = $this->classification_metadata($classification);
        $normalized = $this->normalize_keyword($keyword);

        $result = [
            'keyword' => $normalized,
            'classification' => $classification,
            'metadata' => $metadata,
            'dry_run' => !$apply,
            'applied' => false,
            'reason' => 'dry_run_only',
        ];

        if (!$apply) {
            return $result;
        }
        if (!$this->table_exists()) {
            $result['reason'] = 'keyword_candidate_table_unavailable';
            return $result;
        }
        $columns = $this->columns();
        if (empty($columns['sources'])) {
            $result['reason'] = 'sources_column_unavailable';
            return $result;
        }

        $row = null;
        if (null !== $candidate_id && $candidate_id > 0) {
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table_name() . ' WHERE id = %d LIMIT 1', $candidate_id), ARRAY_A);
        } elseif ('' !== $normalized) {
            $row = $this->find_existing_by_keyword($normalized);
        }
        if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0) {
            $result['reason'] = 'candidate_not_found';
            return $result;
        }

        $sources = $this->decode_json_field($row['sources'] ?? null);
        foreach ($metadata as $key => $value) {
            $sources[$key] = $value;
        }
        $data = [ 'sources' => $this->encode_json($sources) ];
        if (!empty($columns['updated_at'])) {
            $data['updated_at'] = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
        }
        $updated = $wpdb->update($this->table_name(), $data, [ 'id' => (int) $row['id'] ]);
        if (false === $updated) {
            $result['reason'] = 'database_update_failed';
            return $result;
        }
        $result['applied'] = true;
        $result['reason'] = 'classification_metadata_applied';
        return $result;
    }

    /** @return array<string,mixed> */
    public function classification_metadata(array $classification): array {
        return [
            'keyword_class' => (string) ($classification['keyword_class'] ?? ModelKeywordPoolClassifier::CLASS_UNKNOWN_REVIEW),
            'suggested_usage' => (string) ($classification['suggested_usage'] ?? ModelKeywordPoolClassifier::USAGE_REVIEW_REQUIRED),
            'standalone_allowed' => (bool) ($classification['standalone_allowed'] ?? false),
            'keyword_class_reason_codes' => array_values(array_map('strval', is_array($classification['reason_codes'] ?? null) ? $classification['reason_codes'] : [])),
            'keyword_class_confidence' => (string) ($classification['confidence'] ?? 'low'),
            'keyword_classified_at' => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
            'keyword_classified_by' => 'pr602_model_keyword_pool_classifier',
        ];
    }

    /** @return array<string,mixed>|null */
    public function find_existing_by_canonical_and_entity(string $keyword, int $entity_id): ?array {
        global $wpdb;
        if (!$this->table_exists()) {
            return null;
        }
        $normalized = $this->normalize_keyword($keyword);
        if ('' === $normalized) {
            return null;
        }
        $columns = $this->columns();
        $clauses = [ 'keyword = %s' ];
        $args = [ $normalized ];
        if (!empty($columns['canonical'])) {
            $clauses[] = 'canonical = %s';
            $args[] = $normalized;
        }
        $entity_sql = !empty($columns['entity_id']) ? ' AND entity_id = %d' : '';
        if ('' !== $entity_sql) {
            $args[] = $entity_id;
        }
        $sql = 'SELECT * FROM ' . $this->table_name() . ' WHERE (' . implode(' OR ', $clauses) . ')' . $entity_sql . ' LIMIT 1';
        $row = $wpdb->get_row($wpdb->prepare($sql, $args), ARRAY_A);
        return is_array($row) ? $row : null;
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
    private function target_scope_matches_existing(array $row, string $target_type, ?int $target_id): bool {
        $incoming_is_global = 'global' === $target_type && (null === $target_id || $target_id <= 0);
        $incoming_has_target = '' !== $target_type && null !== $target_id && $target_id > 0;
        $existing_type = $this->sanitize_optional_key((string) ($row['target_type'] ?? ''));
        $existing_id_raw = $row['target_id'] ?? null;
        $existing_id = null === $existing_id_raw || '' === (string) $existing_id_raw ? null : max(0, (int) $existing_id_raw);
        $existing_is_global = 'global' === $existing_type && (null === $existing_id || $existing_id <= 0);
        $existing_has_target = '' !== $existing_type && null !== $existing_id && $existing_id > 0;

        if ($incoming_is_global || $existing_is_global) {
            return $incoming_is_global && $existing_is_global;
        }
        if (!$incoming_has_target) {
            return !$existing_has_target && '' === $existing_type;
        }
        if (!$existing_has_target) {
            return '' === $existing_type;
        }
        return $existing_type === $target_type && $existing_id === $target_id;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function existing_target_context(array $row): array {
        return [
            'existing_target_type' => (string) ($row['target_type'] ?? ''),
            'existing_target_id' => (int) ($row['target_id'] ?? 0),
            'existing_target_name' => (string) ($row['target_name'] ?? ''),
            'existing_target_slug' => (string) ($row['target_slug'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $row */
    private function can_update_existing(array $row, array $candidate, string $intent, string $entity_type, int $entity_id): bool {
        $existing_intent = (string) ($row['intent_type'] ?? '');
        $existing_entity = (string) ($row['entity_type'] ?? '');
        $existing_id = (int) ($row['entity_id'] ?? 0);
        $existing_status = (string) ($row['status'] ?? '');

        $same_entity_id = 0 === $entity_id ? 0 === $existing_id : $existing_id === $entity_id;
        if ($existing_intent === $intent && ($existing_entity === '' || $existing_entity === $entity_type) && $same_entity_id) {
            return true;
        }
        if ('model' === $intent && 'model' === $entity_type && 'model' === $existing_intent && in_array($existing_entity, [ '', 'model' ], true) && 0 === $existing_id && $entity_id > 0) {
            return $this->existing_model_owner_matches_candidate($row, $candidate);
        }
        if (0 === $entity_id && in_array($existing_intent, [ '', 'generic' ], true) && 0 === $existing_id && 'approved' !== $existing_status) {
            return true;
        }
        return false;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $candidate */
    private function existing_model_owner_matches_candidate(array $row, array $candidate): bool {
        $candidate_provenance = is_array($candidate['provenance'] ?? null) ? $candidate['provenance'] : [];
        $candidate_owner = $this->normalize_owner((string) ($candidate_provenance['model_keyword_owner'] ?? ''));
        if ('' === $candidate_owner) { return false; }

        $existing_owner = $this->normalize_owner($this->model_owner_from_payload($this->decode_json_field($row['sources'] ?? null)));
        if ('' === $existing_owner) {
            $existing_owner = $this->normalize_owner($this->model_owner_from_payload($this->decode_json_field($row['notes'] ?? null)));
        }
        return '' !== $existing_owner && $existing_owner === $candidate_owner;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $candidate */
    private function existing_conflict_reason(array $row, array $candidate, string $intent, string $entity_type, int $entity_id): string {
        $existing_intent = (string) ($row['intent_type'] ?? '');
        $existing_entity = (string) ($row['entity_type'] ?? '');
        $existing_id = (int) ($row['entity_id'] ?? 0);
        if ('model' === $intent && 'model' === $entity_type && 'model' === $existing_intent && in_array($existing_entity, [ '', 'model' ], true)) {
            if ($existing_id > 0 && $entity_id > 0 && $existing_id !== $entity_id) {
                return 'keyword_owner_conflict|existing_keyword_scope_conflict';
            }
            if (0 === $existing_id && $entity_id > 0 && !$this->existing_model_owner_matches_candidate($row, $candidate)) {
                return 'keyword_owner_conflict|existing_keyword_scope_conflict';
            }
        }
        return 'existing_keyword_conflicting_scope';
    }

    /** @param array<string,mixed> $payload */
    private function model_owner_from_payload(array $payload): string {
        if (isset($payload['model_keyword_owner']) && is_scalar($payload['model_keyword_owner'])) {
            return (string) $payload['model_keyword_owner'];
        }
        foreach ([ 'keyword_pools_import', 'keyword_pools_import_history' ] as $key) {
            $nested = $payload[$key] ?? null;
            if (!is_array($nested)) { continue; }
            if (array_keys($nested) === range(0, count($nested) - 1)) {
                foreach ($nested as $entry) {
                    if (is_array($entry)) {
                        $owner = $this->model_owner_from_payload($entry);
                        if ('' !== $owner) { return $owner; }
                    }
                }
            } else {
                $owner = $this->model_owner_from_payload($nested);
                if ('' !== $owner) { return $owner; }
            }
        }
        return '';
    }

    private function normalize_owner(string $owner): string {
        $owner = strtolower(trim($owner));
        $owner = preg_replace('/[^a-z0-9]+/', ' ', $owner) ?? $owner;
        return trim(preg_replace('/\s+/', ' ', $owner) ?? $owner);
    }


    /**
     * @param mixed $existing Existing JSON/string/array value.
     * @param array<string,mixed> $provenance New import provenance.
     * @return array<string,mixed>
     */
    private function merge_sources($existing, array $provenance): array {
        $sources = $this->decode_json_field($existing);
        if ([] === $sources) {
            return $provenance;
        }

        $merged = $sources;
        foreach ($provenance as $key => $value) {
            if (!array_key_exists($key, $merged)) {
                $merged[$key] = $value;
                continue;
            }
            if ($merged[$key] === $value) {
                continue;
            }
            if (is_array($merged[$key]) && is_array($value)) {
                $merged[$key] = $this->dedupe_list(array_merge($merged[$key], $value));
            }
        }

        $history = is_array($merged['keyword_pools_import_history'] ?? null) ? $merged['keyword_pools_import_history'] : [];
        $history[] = $provenance;
        $merged['keyword_pools_import_history'] = $this->dedupe_list($history);
        if (!array_key_exists('keyword_pools_import', $merged)) {
            $merged['keyword_pools_import'] = $provenance;
        }

        return $merged;
    }


    /**
     * @param mixed $existing Existing JSON/string/array value.
     * @param array<string,mixed> $provenance New import provenance.
     * @param array<int,string> $warnings Import warnings.
     * @return array<string,mixed>
     */
    private function merge_notes($existing, array $provenance, array $warnings): array {
        $notes = $this->decode_json_field($existing);
        if ([] === $notes) {
            return [
                'keyword_pools_import' => $provenance,
                'warnings' => array_values(array_unique($warnings)),
            ];
        }

        $existing_import = is_array($notes['keyword_pools_import'] ?? null) ? $notes['keyword_pools_import'] : [];
        $notes['keyword_pools_import'] = $this->merge_sources($existing_import, $provenance);

        $existing_warnings = is_array($notes['warnings'] ?? null) ? array_map('strval', $notes['warnings']) : [];
        $notes['warnings'] = array_values(array_unique(array_merge($existing_warnings, $warnings)));

        $history = is_array($notes['keyword_pools_import_history'] ?? null) ? $notes['keyword_pools_import_history'] : [];
        $history[] = $provenance;
        $notes['keyword_pools_import_history'] = $this->dedupe_list($history);

        return $notes;
    }

    /** @return array<string,mixed> */
    private function decode_json_field($value): array {
        if (is_array($value)) {
            return $value;
        }
        if (null === $value || '' === trim((string) $value)) {
            return [];
        }

        $decoded = json_decode((string) $value, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return [ 'legacy_value' => (string) $value ];
    }

    /**
     * @param array<int|string,mixed> $items Items to deduplicate.
     * @return array<int,mixed>
     */
    private function dedupe_list(array $items): array {
        $seen = [];
        $deduped = [];
        foreach ($items as $item) {
            $key = is_scalar($item) || null === $item ? (string) $item : $this->encode_json($item);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $item;
        }
        return $deduped;
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

    private function sanitize_optional_key(string $value): string {
        $value = function_exists('sanitize_key') ? sanitize_key($value) : strtolower(preg_replace('/[^a-z0-9_-]/', '', $value));
        return (string) $value;
    }

    private function sanitize_optional_text(string $value, int $max_length): string {
        $value = trim($value);
        if (function_exists('sanitize_text_field')) {
            $value = sanitize_text_field($value);
        } else {
            $value = trim(strip_tags($value));
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max_length);
        }
        return substr($value, 0, $max_length);
    }

    private function encode_json($value): string {
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);
        return is_string($encoded) ? $encoded : '';
    }

    /** @return array<string,mixed> */
    private function result(string $keyword, string $pool, string $status, string $action, string $reason, string $entity_type, int $entity_id, array $warnings = [], int $id = 0, array $extra = []): array {
        return array_merge([
            'id' => $id,
            'keyword' => $keyword,
            'pool' => $pool,
            'status' => $status,
            'action' => $action,
            'reason' => $reason,
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'warnings' => $warnings,
        ], $extra);
    }
}
