<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class SeedRegistry {
    private const REPORT_OPTION = 'tmw_seed_registry_last_report';

    private static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmwseo_seeds';
    }

    public static function normalize_seed(string $seed): string {
        $seed = trim($seed);
        $seed = preg_replace('/\s+/', ' ', $seed);
        return mb_strtolower((string) $seed, 'UTF-8');
    }

    public static function register_seed(string $seed, string $source, string $entity_type = 'system', int $entity_id = 0): bool {
        global $wpdb;

        $normalized = self::normalize_seed($seed);
        if ($normalized === '') {
            return false;
        }

        $hash = md5($normalized);
        $table = self::table_name();

        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE hash=%s LIMIT 1", $hash));
        if ($exists > 0) {
            self::increment_counter('duplicates_prevented', 1);
            Logs::info('keywords', '[TMW-KW] Seed deduplicated by registry', [
                'seed' => $normalized,
                'source' => $source,
                'entity_type' => $entity_type,
                'entity_id' => $entity_id,
            ]);
            return false;
        }

        $inserted = $wpdb->insert($table, [
            'seed' => $normalized,
            'source' => sanitize_key($source),
            'entity_type' => sanitize_key($entity_type),
            'entity_id' => max(0, (int) $entity_id),
            'created_at' => current_time('mysql'),
            'last_used' => null,
            'hash' => $hash,
        ], ['%s', '%s', '%s', '%d', '%s', '%s', '%s']);

        if ($inserted === false) {
            Logs::warn('keywords', '[TMW-KW] Seed registry insert failed', [
                'error' => $wpdb->last_error,
                'seed' => $normalized,
            ]);
            return false;
        }

        self::increment_source_count(sanitize_key($source));
        self::increment_counter('registered_total', 1);

        return true;
    }

    public static function register_many(array $items, string $source, string $entity_type = 'system', int $entity_id = 0): array {
        $registered = 0;
        $deduped = 0;

        foreach ($items as $item) {
            $ok = self::register_seed((string) $item, $source, $entity_type, $entity_id);
            if ($ok) {
                $registered++;
            } else {
                $deduped++;
            }
        }

        return [
            'registered' => $registered,
            'deduplicated' => $deduped,
            'source' => $source,
        ];
    }

    public static function get_seeds_for_discovery(int $limit = 300): array {
        global $wpdb;
        $table = self::table_name();
        $cap = min(300, max(1, $limit));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, seed, source, entity_type, entity_id, created_at, last_used FROM {$table} ORDER BY COALESCE(last_used, '1970-01-01 00:00:00') ASC, id ASC LIMIT %d",
            $cap
        ), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public static function mark_seeds_used(array $seed_ids): void {
        global $wpdb;
        $ids = array_values(array_unique(array_map('intval', $seed_ids)));
        $ids = array_values(array_filter($ids, static fn($id) => $id > 0));
        if (empty($ids)) {
            return;
        }

        $table = self::table_name();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $params = array_merge([current_time('mysql')], $ids);

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET last_used=%s WHERE id IN ({$placeholders})",
            ...$params
        ));
    }

    public static function diagnostics(): array {
        global $wpdb;
        $table = self::table_name();

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $used_this_cycle = (int) get_option('tmw_seed_registry_last_cycle_used', 0);
        $report = get_option(self::REPORT_OPTION, []);
        if (!is_array($report)) {
            $report = [];
        }

        return [
            'total_seeds' => $total,
            'seeds_used_this_cycle' => $used_this_cycle,
            'duplicate_prevention_count' => (int) ($report['duplicates_prevented'] ?? 0),
            'seed_sources' => (array) ($report['source_counts'] ?? []),
            'registered_total' => (int) ($report['registered_total'] ?? 0),
        ];
    }

    private static function increment_source_count(string $source): void {
        $report = get_option(self::REPORT_OPTION, []);
        if (!is_array($report)) {
            $report = [];
        }

        if (!isset($report['source_counts']) || !is_array($report['source_counts'])) {
            $report['source_counts'] = [];
        }

        $report['source_counts'][$source] = (int) ($report['source_counts'][$source] ?? 0) + 1;
        update_option(self::REPORT_OPTION, $report, false);
    }

    private static function increment_counter(string $key, int $by = 1): void {
        $report = get_option(self::REPORT_OPTION, []);
        if (!is_array($report)) {
            $report = [];
        }

        $report[$key] = (int) ($report[$key] ?? 0) + $by;
        update_option(self::REPORT_OPTION, $report, false);
    }
}
