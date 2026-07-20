<?php
/**
 * Repair service for Global Model Pool keyword candidates.
 *
 * Scans tmw_keyword_candidates for rows that were originally imported as Global
 * Model Pool entries but were saved without explicit DB-column markers
 * (target_type='global', target_name='Global Model Pool', target_slug='global-model-pool').
 *
 * Identification is based on sources JSON evidence only — never on entity_id=0 alone.
 * A row is considered a repair candidate if its sources JSON contains:
 *   - model_keyword_usage_scope = "global_model_pool"   (written by import service provenance)
 *   - OR global_model_pool = true                        (written by import service provenance)
 *
 * Rows that already have target_name = 'Global Model Pool' are skipped (already correct).
 * Rows with a conflicting non-global target_type are skipped (conservatively).
 *
 * @package TMWSEO\Engine\Keywords
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

if (!defined('ABSPATH')) { exit; }

class GlobalModelPoolRepairService {

    private const TABLE_SUFFIX   = 'tmw_keyword_candidates';
    private const TARGET_TYPE    = 'global';
    private const TARGET_NAME    = 'Global Model Pool';
    private const TARGET_SLUG    = 'global-model-pool';
    private const BATCH_SIZE     = 200;

    /** @var array<string,bool> */
    private array $columns_cache = [];

    /**
     * Scan tmw_keyword_candidates for rows with Global Model Pool provenance in their
     * sources JSON and write the explicit DB-column markers where missing.
     *
     * @param  bool $dry_run  When true, identifies rows and logs but does not write.
     * @return array{scanned:int,updated:int,skipped:int,errors:int,dry_run:bool}
     */
    public function scan_and_repair(bool $dry_run = false): array {
        global $wpdb;

        $stats = [ 'scanned' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'dry_run' => $dry_run ];

        if (!is_object($wpdb) || !isset($wpdb->prefix)) {
            $this->debug_log('[TMW-KW-GLOBAL-REPAIR] scanned=0 updated=0 skipped=0 reason=wpdb_unavailable dry_run=' . ($dry_run ? 'yes' : 'no'));
            return $stats;
        }

        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        // Verify table exists.
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if (!is_string($found) || strtolower($found) !== strtolower($table)) {
            $this->debug_log('[TMW-KW-GLOBAL-REPAIR] scanned=0 updated=0 skipped=0 reason=table_unavailable dry_run=' . ($dry_run ? 'yes' : 'no'));
            return $stats;
        }

        $columns = $this->detect_columns($table);

        if (!isset($columns['sources'])) {
            $this->debug_log('[TMW-KW-GLOBAL-REPAIR] scanned=0 updated=0 skipped=0 reason=sources_column_absent dry_run=' . ($dry_run ? 'yes' : 'no'));
            return $stats;
        }

        // Determine which target columns can be written.
        $can_write_target_type = isset($columns['target_type']);
        $can_write_target_name = isset($columns['target_name']);
        $can_write_target_slug = isset($columns['target_slug']);
        $can_write_updated_at  = isset($columns['updated_at']);
        $can_write_scope       = isset($columns['model_keyword_usage_scope']);

        if (!$can_write_target_name && !$can_write_target_type) {
            $this->debug_log('[TMW-KW-GLOBAL-REPAIR] scanned=0 updated=0 skipped=0 reason=no_writable_target_columns dry_run=' . ($dry_run ? 'yes' : 'no'));
            return $stats;
        }

        // Fetch all candidate rows in batches ordered by id.
        $offset = 0;
        do {
            $sql = $wpdb->prepare(
                'SELECT id, keyword, target_type, target_name, sources FROM ' . $table
                . ' WHERE intent_type = %s AND entity_id = %d ORDER BY id ASC LIMIT %d OFFSET %d',
                'model', 0, self::BATCH_SIZE, $offset
            );
            $rows = $wpdb->get_results($sql, ARRAY_A);
            if (!is_array($rows) || empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $stats['scanned']++;

                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    $stats['skipped']++;
                    continue;
                }

                $existing_target_type = trim((string) ($row['target_type'] ?? ''));
                $existing_target_name = trim((string) ($row['target_name'] ?? ''));

                // Skip rows already correctly marked.
                if (self::TARGET_NAME === $existing_target_name && self::TARGET_TYPE === $existing_target_type) {
                    $stats['skipped']++;
                    $this->debug_log('[TMW-KW-GLOBAL-REPAIR] id=' . $id
                        . ' keyword="' . (string) ($row['keyword'] ?? '') . '"'
                        . ' skipped reason=already_marked');
                    continue;
                }

                // Skip rows with a conflicting non-global target_type (conservatively preserve).
                if ('' !== $existing_target_type && self::TARGET_TYPE !== $existing_target_type) {
                    $stats['skipped']++;
                    $this->debug_log('[TMW-KW-GLOBAL-REPAIR] id=' . $id
                        . ' keyword="' . (string) ($row['keyword'] ?? '') . '"'
                        . ' skipped reason=conflicting_target_type existing_target_type=' . $existing_target_type);
                    continue;
                }

                // Identify via sources JSON evidence only.
                if (!$this->is_global_pool_by_sources($row)) {
                    $stats['skipped']++;
                    continue;
                }

                $keyword = (string) ($row['keyword'] ?? '');
                $this->debug_log('[TMW-KW-GLOBAL-REPAIR] id=' . $id
                    . ' keyword="' . $keyword . '"'
                    . ' action=' . ($dry_run ? 'dry_run_would_update' : 'updating')
                    . ' target_type=' . self::TARGET_TYPE
                    . ' target_name="' . self::TARGET_NAME . '"'
                    . ' target_slug="' . self::TARGET_SLUG . '"');

                if ($dry_run) {
                    $stats['updated']++;
                    continue;
                }

                $data = [];
                if ($can_write_target_type) {
                    $data['target_type'] = self::TARGET_TYPE;
                }
                if ($can_write_target_name) {
                    $data['target_name'] = self::TARGET_NAME;
                }
                if ($can_write_target_slug) {
                    $data['target_slug'] = self::TARGET_SLUG;
                }
                if ($can_write_scope) {
                    $data['model_keyword_usage_scope'] = 'global_model_pool';
                }
                if ($can_write_updated_at) {
                    $data['updated_at'] = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
                }

                if (empty($data)) {
                    $stats['skipped']++;
                    continue;
                }

                $result = $wpdb->update($table, $data, [ 'id' => $id ]);
                if (false === $result) {
                    $stats['errors']++;
                    $this->debug_log('[TMW-KW-GLOBAL-REPAIR] id=' . $id . ' keyword="' . $keyword . '" error=db_update_failed');
                } else {
                    $stats['updated']++;
                }
            }

            $offset += self::BATCH_SIZE;
        } while (count($rows) === self::BATCH_SIZE);

        $this->debug_log('[TMW-KW-GLOBAL-REPAIR]'
            . ' scanned=' . $stats['scanned']
            . ' updated=' . $stats['updated']
            . ' skipped=' . $stats['skipped']
            . ' errors=' . $stats['errors']
            . ' dry_run=' . ($dry_run ? 'yes' : 'no'));

        return $stats;
    }

    /**
     * Return true if the row's sources JSON contains evidence of Global Model Pool provenance.
     * Uses only the provenance written by KeywordPoolSelectedImportService, not entity_id alone.
     *
     * @param array<string,mixed> $row
     */
    private function is_global_pool_by_sources(array $row): bool {
        $raw = $row['sources'] ?? null;
        if (null === $raw || '' === trim((string) $raw)) {
            return false;
        }
        $sources = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($sources)) {
            return false;
        }
        // Primary marker: written by provenance_for_row() when global pool context is active.
        if (isset($sources['model_keyword_usage_scope'])
            && 'global_model_pool' === (string) $sources['model_keyword_usage_scope']
        ) {
            return true;
        }
        // Secondary marker: also written by provenance_for_row().
        if (!empty($sources['global_model_pool'])) {
            return true;
        }
        // Check nested import history entries.
        foreach ([ 'keyword_pools_import', 'keyword_pools_import_history' ] as $key) {
            $entries = $sources[$key] ?? null;
            if (!is_array($entries)) {
                continue;
            }
            // Single entry (not a list).
            if (array_keys($entries) !== array_keys(array_values($entries))) {
                if ($this->entry_is_global_pool($entries)) {
                    return true;
                }
                continue;
            }
            foreach ($entries as $entry) {
                if (is_array($entry) && $this->entry_is_global_pool($entry)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param array<string,mixed> $entry */
    private function entry_is_global_pool(array $entry): bool {
        if (isset($entry['model_keyword_usage_scope'])
            && 'global_model_pool' === (string) $entry['model_keyword_usage_scope']
        ) {
            return true;
        }
        return !empty($entry['global_model_pool']);
    }

    /** @return array<string,bool> */
    private function detect_columns(string $table): array {
        if (isset($this->columns_cache[$table])) {
            return $this->columns_cache[$table];
        }
        global $wpdb;
        $rows = $wpdb->get_results('SHOW COLUMNS FROM ' . $table, ARRAY_A);
        $columns = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $field = is_array($row) ? (string) ($row['Field'] ?? $row['field'] ?? '') : '';
                if ('' !== $field) {
                    $columns[$field] = true;
                }
            }
        }
        $this->columns_cache[$table] = $columns;
        return $columns;
    }

    private function debug_log(string $message): void {
        if (
            (defined('TMW_DEBUG') && TMW_DEBUG)
            || (defined('TMWSEO_DEBUG') && TMWSEO_DEBUG)
            || (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG)
        ) {
            error_log($message);
        }
    }
}
