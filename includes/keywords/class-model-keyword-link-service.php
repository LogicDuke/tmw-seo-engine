<?php
/**
 * Links approved personal model keyword rows from entity_id=0
 * to the correct WordPress model post ID when sources JSON proves
 * the row belongs to that model.
 *
 * Used by the `wp tmwseo link-model-keywords` CLI command (class-cli.php).
 *
 * Safety guarantees
 * -----------------
 *   - Never touches Global Model Pool rows (guarded by DB target_type='global'
 *     and sources.global_model_pool / sources.model_keyword_usage_scope markers).
 *   - Only processes rows with sources.personal_model_keyword_csv=true.
 *   - Only processes rows with a non-empty sources.model_keyword_owner.
 *   - Links only on unique / non-ambiguous owner matches.
 *   - Writes only entity_id (+ updated_at when column is present).
 *   - Dry-run performs zero DB writes.
 *   - Never changes status, keyword text, target_type/target_name/target_slug,
 *     or any Rank Math field.
 *
 * Debug log tags
 * --------------
 *   [TMW-KW-MODEL-LINK]  — per-row and summary entries.
 *
 * @package TMWSEO\Engine\Keywords
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Models\ModelEntityResolver;

if (!defined('ABSPATH')) { exit; }

class ModelKeywordLinkService {

    private const TABLE_SUFFIX = 'tmw_keyword_candidates';
    private const BATCH_SIZE   = 200;

    /**
     * Match types from ModelEntityResolver that must NOT be linked.
     * Ambiguous = multiple candidates; not_found / '' = no candidate.
     *
     * @var array<int,string>
     */
    private const SKIP_MATCH_TYPES = [ 'ambiguous', 'not_found', '' ];

    /** @var array<string,array<string,mixed>> Owner resolution cache keyed by normalized name. */
    private array $resolution_cache = [];

    /** @var array<string,array<string,bool>> Table-column presence cache. */
    private array $columns_cache = [];

    private ModelEntityResolver $resolver;

    public function __construct(?ModelEntityResolver $resolver = null) {
        $this->resolver = $resolver ?: new ModelEntityResolver();
    }

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Scan approved entity_id=0 personal model keyword rows and update entity_id
     * to the correct model post ID when owner evidence is strong and unambiguous.
     *
     * @param  bool   $dry_run    When true, identify rows but do not write anything.
     * @param  int    $limit      Maximum rows to process after filtering (hard cap 5 000).
     * @param  string $model_name When non-empty, restricts scan to rows whose
     *                            sources.model_keyword_owner matches this name exactly
     *                            after normalisation.  Safe for per-model testing.
     * @param  bool   $force      Reserved — pass true to re-process rows that have
     *                            already been linked on a previous run (entity_id > 0
     *                            rows are always excluded by the base query so this
     *                            has no effect in the current schema).
     *
     * @return array{
     *   scanned:int,
     *   linked:int,
     *   skipped:int,
     *   errors:int,
     *   dry_run:bool,
     *   rows:array<int,array<string,mixed>>
     * }
     */
    public function scan_and_link(
        bool   $dry_run    = false,
        int    $limit      = 500,
        string $model_name = '',
        bool   $force      = false
    ): array {
        global $wpdb;

        $limit  = max(1, min($limit, 5000));
        $stats  = [
            'scanned'  => 0,
            'linked'   => 0,
            'skipped'  => 0,
            'errors'   => 0,
            'dry_run'  => $dry_run,
            'rows'     => [],
        ];

        if (!is_object($wpdb) || !isset($wpdb->prefix)) {
            $this->debug_log(
                '[TMW-KW-MODEL-LINK] scanned=0 linked=0 skipped=0 errors=0'
                . ' reason=wpdb_unavailable dry_run=' . ($dry_run ? 'yes' : 'no')
            );
            return $stats;
        }

        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        if (!$this->table_exists($table)) {
            $this->debug_log(
                '[TMW-KW-MODEL-LINK] scanned=0 linked=0 skipped=0 errors=0'
                . ' reason=table_unavailable dry_run=' . ($dry_run ? 'yes' : 'no')
            );
            return $stats;
        }

        $columns          = $this->detect_columns($table);
        $has_target_type  = isset($columns['target_type']);
        $has_updated_at   = isset($columns['updated_at']);
        $normalized_filter = $this->normalize_owner($model_name);

        $processed = 0;
        $offset    = 0;

        do {
            $rows = $this->fetch_batch($table, $offset, self::BATCH_SIZE, $has_target_type);

            if (!is_array($rows) || empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                if ($processed >= $limit) {
                    break 2;
                }

                $stats['scanned']++;
                $id      = (int)    ($row['id']      ?? 0);
                $keyword = (string) ($row['keyword']  ?? '');

                if ($id <= 0) {
                    $stats['skipped']++;
                    $stats['rows'][] = $this->row_result($id, $keyword, '', 0, 'skipped', 'invalid_id');
                    continue;
                }

                $sources = $this->decode_json($row['sources'] ?? null);

                // All eligibility checks — returns a skip-reason string or null.
                $skip_reason = $this->eligibility_check($row, $sources, $normalized_filter);
                if (null !== $skip_reason) {
                    $stats['skipped']++;
                    $stats['rows'][] = $this->row_result($id, $keyword, '', 0, 'skipped', $skip_reason);
                    $this->debug_log(
                        '[TMW-KW-MODEL-LINK] id=' . $id
                        . ' keyword="' . $keyword . '"'
                        . ' skipped reason=' . $skip_reason
                    );
                    continue;
                }

                $owner      = $this->extract_owner($sources);
                $resolution = $this->resolve_owner($owner);
                $match_type = (string) ($resolution['match_type'] ?? 'not_found');

                // Only link on unique, non-ambiguous matches.
                if (empty($resolution['found']) || in_array($match_type, self::SKIP_MATCH_TYPES, true)) {
                    $stats['skipped']++;
                    $skip_reason = ('ambiguous' === $match_type) ? 'owner_ambiguous' : 'owner_not_found';
                    $stats['rows'][] = $this->row_result($id, $keyword, $owner, 0, 'skipped', $skip_reason);
                    $this->debug_log(
                        '[TMW-KW-MODEL-LINK] id=' . $id
                        . ' keyword="' . $keyword . '"'
                        . ' owner="' . $owner . '"'
                        . ' skipped reason=' . $skip_reason
                        . ' match_type=' . $match_type
                    );
                    continue;
                }

                $post_id = (int) ($resolution['entity_id'] ?? 0);
                if ($post_id <= 0) {
                    $stats['skipped']++;
                    $stats['rows'][] = $this->row_result($id, $keyword, $owner, 0, 'skipped', 'resolved_post_id_zero');
                    continue;
                }

                $processed++;

                // Dry-run: record what would happen, write nothing.
                if ($dry_run) {
                    $stats['linked']++;
                    $stats['rows'][] = $this->row_result(
                        $id, $keyword, $owner, $post_id, 'dry_run_would_link', ''
                    );
                    $this->debug_log(
                        '[TMW-KW-MODEL-LINK] id=' . $id
                        . ' keyword="' . $keyword . '"'
                        . ' owner="' . $owner . '"'
                        . ' dry_run_would_link post_id=' . $post_id
                    );
                    continue;
                }

                // Real mode: write only entity_id (+ updated_at).
                $write_result = $this->write_entity_id($table, $id, $post_id, $has_updated_at);
                if ('linked' === $write_result) {
                    $stats['linked']++;
                    $stats['rows'][] = $this->row_result($id, $keyword, $owner, $post_id, 'linked', '');
                    $this->debug_log(
                        '[TMW-KW-MODEL-LINK] id=' . $id
                        . ' keyword="' . $keyword . '"'
                        . ' owner="' . $owner . '"'
                        . ' linked post_id=' . $post_id
                        . ' match_type=' . $match_type
                    );
                } elseif ('concurrent_update' === $write_result) {
                    // Another process linked this row between our scan and our update.
                    // Do not count as linked; not an error.
                    $stats['skipped']++;
                    $stats['rows'][] = $this->row_result(
                        $id, $keyword, $owner, $post_id, 'skipped', 'concurrent_update'
                    );
                    $this->debug_log(
                        '[TMW-KW-MODEL-LINK] id=' . $id
                        . ' keyword="' . $keyword . '"'
                        . ' owner="' . $owner . '"'
                        . ' skipped reason=concurrent_update'
                    );
                } else {
                    $stats['errors']++;
                    $stats['rows'][] = $this->row_result(
                        $id, $keyword, $owner, $post_id, 'error', 'db_update_failed'
                    );
                    $this->debug_log(
                        '[TMW-KW-MODEL-LINK] id=' . $id
                        . ' keyword="' . $keyword . '"'
                        . ' owner="' . $owner . '"'
                        . ' error=db_update_failed'
                    );
                }
            }

            $offset += self::BATCH_SIZE;

        } while (count($rows) === self::BATCH_SIZE && $processed < $limit);

        $this->debug_log(
            '[TMW-KW-MODEL-LINK]'
            . ' scanned='  . $stats['scanned']
            . ' linked='   . $stats['linked']
            . ' skipped='  . $stats['skipped']
            . ' errors='   . $stats['errors']
            . ' dry_run='  . ($dry_run ? 'yes' : 'no')
        );

        return $stats;
    }

    // ── Eligibility ───────────────────────────────────────────────────────

    /**
     * Return null when a row is eligible for linking, or a skip-reason code string.
     *
     * Checks run in order from cheapest (DB column) to most expensive (sources JSON decode).
     *
     * @param array<string,mixed> $row     Raw DB row (already fetched).
     * @param array<string,mixed> $sources Decoded sources JSON (caller pre-decodes once).
     * @param string              $normalized_filter Normalized model_name filter; '' = all.
     */
    private function eligibility_check(array $row, array $sources, string $normalized_filter): ?string {
        // Guard 1 (DB column): skip anything whose target_type = 'global' — these are
        // Global Model Pool rows and must never receive a model-specific entity_id.
        $target_type = strtolower(trim((string) ($row['target_type'] ?? '')));
        if ('global' === $target_type) {
            return 'global_target_type';
        }

        // Guard 2 (sources JSON): must carry the explicit personal model keyword CSV flag.
        // This flag is written by KeywordPoolSelectedImportService::provenance_for_row()
        // for every non-global pool model keyword row that has an owner.
        if (!$this->sources_bool($sources, 'personal_model_keyword_csv')) {
            return 'missing_personal_model_keyword_csv';
        }

        // Guard 3 (sources JSON): must have a non-empty owner string.
        $owner = $this->extract_owner($sources);
        if ('' === $owner) {
            return 'missing_model_keyword_owner';
        }

        // Guard 4 (sources JSON): must NOT be a Global Model Pool row.
        // Mirrors GlobalModelPoolRepairService::is_global_pool_by_sources().
        if ($this->is_global_pool_by_sources($sources)) {
            return 'global_model_pool_row';
        }

        // Guard 5 (optional name filter): restrict scan to one model for safe testing.
        if ('' !== $normalized_filter && $this->normalize_owner($owner) !== $normalized_filter) {
            return 'owner_name_filter_mismatch';
        }

        return null;
    }

    // ── Global Pool detection ─────────────────────────────────────────────

    /**
     * Return true if sources JSON proves this is a Global Model Pool row.
     * Mirrors GlobalModelPoolRepairService::is_global_pool_by_sources().
     *
     * @param array<string,mixed> $sources
     */
    private function is_global_pool_by_sources(array $sources): bool {
        // Primary marker — written by provenance_for_row() for global pool context.
        if (isset($sources['model_keyword_usage_scope'])
            && 'global_model_pool' === (string) $sources['model_keyword_usage_scope']
        ) {
            return true;
        }

        // Secondary marker — also written by provenance_for_row().
        // Use is_truthy_flag() so string "false" is not treated as truthy.
        if ($this->is_truthy_flag($sources['global_model_pool'] ?? null)) {
            return true;
        }

        // Nested import-history entries.
        foreach ([ 'keyword_pools_import', 'keyword_pools_import_history' ] as $key) {
            $entries = $sources[$key] ?? null;
            if (!is_array($entries)) {
                continue;
            }
            // Single associative entry (not a list).
            if (!$this->is_list_array($entries)) {
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
        // Use is_truthy_flag() so string "false" is not treated as truthy.
        return $this->is_truthy_flag($entry['global_model_pool'] ?? null);
    }

    // ── Sources JSON helpers ──────────────────────────────────────────────

    /**
     * Extract model_keyword_owner from sources JSON.
     * Checks the top level first, then nested import-history entries.
     *
     * @param array<string,mixed> $sources
     */
    private function extract_owner(array $sources): string {
        // Top-level check.
        if (isset($sources['model_keyword_owner']) && is_scalar($sources['model_keyword_owner'])) {
            $owner = trim((string) $sources['model_keyword_owner']);
            if ('' !== $owner) {
                return $owner;
            }
        }

        // Nested import history.
        foreach ([ 'keyword_pools_import', 'keyword_pools_import_history' ] as $key) {
            $entries = $sources[$key] ?? null;
            if (!is_array($entries)) {
                continue;
            }
            $list = $this->is_list_array($entries) ? $entries : [ $entries ];
            foreach ($list as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (isset($entry['model_keyword_owner']) && is_scalar($entry['model_keyword_owner'])) {
                    $owner = trim((string) $entry['model_keyword_owner']);
                    if ('' !== $owner) {
                        return $owner;
                    }
                }
            }
        }

        return '';
    }

    /**
     * Read a boolean-like value from sources JSON, including nested history entries.
     *
     * @param array<string,mixed> $sources
     */
    private function sources_bool(array $sources, string $key): bool {
        if (array_key_exists($key, $sources)) {
            $v = $sources[$key];
            if (is_bool($v))   { return $v; }
            if (is_int($v))    { return 1 === $v; }
            if (is_string($v)) { return in_array(strtolower(trim($v)), [ '1', 'true', 'yes' ], true); }
        }

        // Nested import history.
        foreach ([ 'keyword_pools_import', 'keyword_pools_import_history' ] as $history_key) {
            $entries = $sources[$history_key] ?? null;
            if (!is_array($entries)) { continue; }
            $list = $this->is_list_array($entries) ? $entries : [ $entries ];
            foreach ($list as $entry) {
                if (!is_array($entry) || !array_key_exists($key, $entry)) { continue; }
                $v = $entry[$key];
                if (is_bool($v))   { return $v; }
                if (is_int($v))    { return 1 === $v; }
                if (is_string($v)) { return in_array(strtolower(trim($v)), [ '1', 'true', 'yes' ], true); }
            }
        }

        return false;
    }

    // ── DB helpers ────────────────────────────────────────────────────────

    /**
     * Fetch one batch of entity_id=0 approved model keyword rows.
     *
     * Selects only the columns needed for eligibility checks + linking.
     * target_type is only included when the column exists (schema-safe).
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetch_batch(string $table, int $offset, int $batch_size, bool $has_target_type): array {
        global $wpdb;

        $select = 'id, keyword, sources' . ($has_target_type ? ', target_type' : '');

        $sql = $wpdb->prepare(
            'SELECT ' . $select . ' FROM ' . $table
            . ' WHERE intent_type = %s AND entity_type = %s AND entity_id = %d AND status = %s'
            . ' ORDER BY id ASC LIMIT %d OFFSET %d',
            'model', 'model', 0, 'approved',
            $batch_size, $offset
        );

        $rows = $wpdb->get_results($sql, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * Write only entity_id (and updated_at when the column exists).
     *
     * This is the ONLY DB write this service ever makes.
     * Status, keyword, target fields, and sources JSON are never touched.
     *
     * The WHERE clause includes entity_id=0 so a concurrent link by another
     * process results in 0 affected rows rather than silently overwriting
     * the winner's value.
     *
     * @return string 'linked' | 'concurrent_update' | 'error'
     */
    private function write_entity_id(string $table, int $id, int $post_id, bool $has_updated_at): string {
        global $wpdb;

        $data = [ 'entity_id' => $post_id ];
        if ($has_updated_at) {
            $data['updated_at'] = function_exists('current_time')
                ? current_time('mysql')
                : gmdate('Y-m-d H:i:s');
        }

        // entity_id=0 in WHERE: if another process already linked this row,
        // the WHERE no longer matches and we get 0 affected rows (not false).
        $result = $wpdb->update($table, $data, [ 'id' => $id, 'entity_id' => 0 ]);

        if (false === $result) {
            return 'error';
        }
        if (0 === (int) $result) {
            // Row no longer has entity_id=0 — another process linked it concurrently.
            return 'concurrent_update';
        }
        return 'linked';
    }

    private function table_exists(string $table): bool {
        global $wpdb;
        if (!method_exists($wpdb, 'get_var')
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'esc_like')) {
            return false;
        }
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        return is_string($found) && strtolower($found) === strtolower($table);
    }

    /**
     * Return a map of column_name => true for the given table.
     *
     * @return array<string,bool>
     */
    private function detect_columns(string $table): array {
        if (isset($this->columns_cache[$table])) {
            return $this->columns_cache[$table];
        }
        global $wpdb;
        $columns = [];
        $rows    = $wpdb->get_results(
            'SHOW COLUMNS FROM ' . $table,
            defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'
        );
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

    // ── Resolution helpers ────────────────────────────────────────────────

    /**
     * Resolve an owner string to a model post, with per-run caching.
     *
     * @return array<string,mixed>
     */
    private function resolve_owner(string $owner): array {
        $key = $this->normalize_owner($owner);
        if (!isset($this->resolution_cache[$key])) {
            $this->resolution_cache[$key] = $this->resolver->resolve($owner);
        }
        return $this->resolution_cache[$key];
    }

    // ── Utility ───────────────────────────────────────────────────────────

    /**
     * Strict boolean-ish check for sources JSON flag fields.
     *
     * Avoids the !empty() pitfall where string "false" evaluates as truthy.
     * Only `true`, `1`, or the strings "1"/"true"/"yes" (case-insensitive)
     * are considered truthy.  String "false", `0`, `null`, and absent keys
     * all return false.
     *
     * @param mixed $value
     */
    private function is_truthy_flag($value): bool {
        if (true === $value || 1 === $value) {
            return true;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), [ '1', 'true', 'yes' ], true);
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function decode_json($value): array {
        if (is_array($value)) { return $value; }
        if (null === $value || '' === trim((string) $value)) { return []; }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function is_list_array(array $arr): bool {
        return [] === $arr || array_keys($arr) === range(0, count($arr) - 1);
    }

    private function normalize_owner(string $owner): string {
        $owner = strtolower(trim($owner));
        $owner = preg_replace('/[^a-z0-9]+/', ' ', $owner) ?? $owner;
        return trim(preg_replace('/\s+/', ' ', $owner) ?? $owner);
    }

    /**
     * Build a per-row result entry for the stats['rows'] array.
     *
     * @return array<string,mixed>
     */
    private function row_result(
        int    $id,
        string $keyword,
        string $owner,
        int    $resolved_post_id,
        string $action,
        string $reason
    ): array {
        return [
            'id'               => $id,
            'keyword'          => $keyword,
            'owner'            => $owner,
            'resolved_post_id' => $resolved_post_id,
            'action'           => $action,
            'reason'           => $reason,
        ];
    }

    private function debug_log(string $message): void {
        if (
            (defined('TMW_DEBUG')     && TMW_DEBUG)
            || (defined('TMWSEO_DEBUG') && TMWSEO_DEBUG)
            || (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG)
        ) {
            error_log($message);
        }
    }
}
