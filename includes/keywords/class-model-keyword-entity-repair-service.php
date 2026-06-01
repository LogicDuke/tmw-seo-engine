<?php
/**
 * Repairs unlinked personal model keyword candidate entity IDs.
 *
 * @package TMWSEO\Engine\Keywords
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Models\ModelEntityResolver;

if (!defined('ABSPATH')) { exit; }

class ModelKeywordEntityRepairService {

    private ModelEntityResolver $resolver;

    /** @var array<string,array<string,mixed>> */
    private array $resolution_cache = [];

    public function __construct(?ModelEntityResolver $resolver = null) {
        $this->resolver = $resolver ?: new ModelEntityResolver();
    }

    /**
     * @param array<int> $ids
     * @return array{selected:int,linked:int,unresolved:int,ambiguous:int,skipped:int,errors:int}
     */
    public function resolve_selected(array $ids): array {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $summary = [ 'selected' => count($ids), 'linked' => 0, 'unresolved' => 0, 'ambiguous' => 0, 'skipped' => 0, 'errors' => 0 ];
        if ([] === $ids) { return $summary; }

        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $rows = $this->fetch_rows($table, $ids);
        foreach ($rows as $row) {
            if (!$this->is_repair_eligible($row)) {
                $summary['skipped']++;
                continue;
            }

            $metadata = $this->metadata_from_row($row);
            $owner = trim((string) ($metadata['model_keyword_owner'] ?? ''));
            if ('' === $owner) {
                $summary['skipped']++;
                continue;
            }

            $resolution = $this->resolve_owner($owner);
            $match_type = (string) ($resolution['match_type'] ?? 'not_found');
            $reason = 'ambiguous' === $match_type ? 'model_entity_ambiguous' : 'model_entity_not_found';
            $entity_id = !empty($resolution['found']) ? (int) ($resolution['entity_id'] ?? 0) : 0;

            if ($entity_id > 0) {
                $updated = $this->update_row($table, $row, $resolution, [ 'model_entity_resolved', 'repair_unlinked_model_keyword', 'model_match_' . $match_type ], null);
                $summary[$updated ? 'linked' : 'errors']++;
                continue;
            }

            $new_status = $this->should_queue_unresolved($row, $metadata) ? 'queued_for_review' : null;
            $updated = $this->update_row($table, $row, $resolution, [ $reason, 'repair_unlinked_model_keyword', 'model_match_' . $match_type ], $new_status);
            if (!$updated) {
                $summary['errors']++;
            } elseif ('ambiguous' === $match_type) {
                $summary['ambiguous']++;
            } else {
                $summary['unresolved']++;
            }
        }

        return $summary;
    }

    /** @param array<int> $ids @return array<int,array<string,mixed>> */
    private function fetch_rows(string $table, array $ids): array {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare('SELECT * FROM ' . $table . ' WHERE id IN (' . $placeholders . ')', $ids);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @param array<string,mixed> $row */
    private function is_repair_eligible(array $row): bool {
        if ('model' !== strtolower((string) ($row['intent_type'] ?? $row['intent'] ?? ''))) { return false; }
        if ('model' !== strtolower((string) ($row['entity_type'] ?? ''))) { return false; }
        if (0 !== (int) ($row['entity_id'] ?? 0)) { return false; }
        $metadata = $this->metadata_from_row($row);
        $owner = trim((string) ($metadata['model_keyword_owner'] ?? ''));
        if ('' === $owner) { return false; }
        $scope = (string) ($metadata['model_keyword_usage_scope'] ?? '');
        return !empty($metadata['personal_model_keyword_csv']) || in_array($scope, [ 'model_bio_only', 'model_page_only' ], true);
    }

    /** @param array<string,mixed> $row @param array<string,string> $metadata */
    private function should_queue_unresolved(array $row, array $metadata): bool {
        return 'approved' === strtolower((string) ($row['status'] ?? ''))
            && in_array((string) ($metadata['model_keyword_usage_scope'] ?? ''), [ 'model_bio_only', 'model_page_only' ], true);
    }

    /** @return array<string,mixed> */
    private function resolve_owner(string $owner): array {
        $key = $this->normalize_key($owner);
        if (!isset($this->resolution_cache[$key])) {
            $this->resolution_cache[$key] = $this->resolver->resolve($owner);
        }
        return $this->resolution_cache[$key];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $resolution @param array<int,string> $markers */
    private function update_row(string $table, array $row, array $resolution, array $markers, ?string $new_status): bool {
        global $wpdb;

        $entity_id = !empty($resolution['found']) ? (int) ($resolution['entity_id'] ?? 0) : 0;
        $match_type = (string) ($resolution['match_type'] ?? ($entity_id > 0 ? 'resolved' : 'not_found'));
        $data = [
            'entity_type' => 'model',
            'entity_id' => $entity_id,
        ];
        if (null !== $new_status) { $data['status'] = $new_status; }
        if ($this->column_exists($table, 'updated_at')) { $data['updated_at'] = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'); }

        foreach ([ 'sources', 'notes' ] as $field) {
            if (!$this->column_exists($table, $field)) { continue; }
            $payload = $this->decode_json_field($row[$field] ?? null);
            $payload = $this->append_repair_metadata($payload, $resolution, $markers, $match_type, $entity_id);
            $data[$field] = $this->encode_json($payload);
        }

        $updated = $wpdb->update($table, $data, [ 'id' => (int) ($row['id'] ?? 0) ]);
        return false !== $updated;
    }

    private function column_exists(string $table, string $column): bool {
        static $cache = [];
        if (!isset($cache[$table])) {
            global $wpdb;
            $cache[$table] = [];
            $rows = $wpdb->get_results('SHOW COLUMNS FROM ' . $table, ARRAY_A);
            foreach ((array) $rows as $row) {
                $field = is_array($row) ? (string) ($row['Field'] ?? $row['field'] ?? '') : '';
                if ('' !== $field) { $cache[$table][$field] = true; }
            }
        }
        return !empty($cache[$table][$column]);
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $resolution @param array<int,string> $markers @return array<string,mixed> */
    private function append_repair_metadata(array $payload, array $resolution, array $markers, string $match_type, int $entity_id): array {
        $payload['model_entity_resolution'] = $resolution;
        $payload['model_entity_id'] = $entity_id;
        $payload['model_entity_match_type'] = $match_type;
        $payload['model_entity_reason_codes'] = array_values(array_unique(array_merge(
            is_array($payload['model_entity_reason_codes'] ?? null) ? array_map('strval', $payload['model_entity_reason_codes']) : [],
            $markers
        )));
        $payload['repair_unlinked_model_keyword'] = true;
        $payload['model_entity_repair_at'] = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
        return $payload;
    }

    /** @param array<string,mixed> $row @return array<string,string> */
    private function metadata_from_row(array $row): array {
        $metadata = [];
        foreach ([ 'sources', 'notes' ] as $field) {
            $metadata = array_merge($metadata, $this->find_model_keyword_metadata($this->decode_json_field($row[$field] ?? null)));
        }
        return $metadata;
    }

    /** @param array<string,mixed> $payload @return array<string,string> */
    private function find_model_keyword_metadata(array $payload): array {
        $metadata = [];
        foreach ([ 'model_keyword_owner', 'model_keyword_usage_scope', 'model_keyword_primary_candidate', 'model_entity_match_type' ] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && '' !== (string) $payload[$key]) { $metadata[$key] = (string) $payload[$key]; }
        }
        if (!empty($payload['personal_model_keyword_csv'])) { $metadata['personal_model_keyword_csv'] = '1'; }
        foreach ([ 'keyword_pools_import', 'keyword_pools_import_history' ] as $key) {
            $nested = $payload[$key] ?? null;
            if (!is_array($nested)) { continue; }
            if ($this->is_list_array($nested)) {
                foreach ($nested as $entry) { if (is_array($entry)) { $metadata = array_merge($this->find_model_keyword_metadata($entry), $metadata); } }
            } else {
                $metadata = array_merge($this->find_model_keyword_metadata($nested), $metadata);
            }
        }
        return $metadata;
    }

    /** @return array<string,mixed> */
    private function decode_json_field($value): array {
        if (is_array($value)) { return $value; }
        if (null === $value || '' === trim((string) $value)) { return []; }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [ 'legacy_value' => (string) $value ];
    }

    private function is_list_array(array $value): bool {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function encode_json($value): string {
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);
        return is_string($encoded) ? $encoded : '';
    }

    private function normalize_key(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
