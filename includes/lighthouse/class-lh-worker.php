<?php
namespace TMW\SEO\Lighthouse;

use TMWSEO\Engine\Jobs;
use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class Worker {
    public static function get_baseline_timestamp(): int {
        return (int) get_option('tmw_lighthouse_baseline_timestamp', 0);
    }

    public static function get_baseline_mysql_datetime(): string {
        $baseline = self::get_baseline_timestamp();
        if ($baseline <= 0) {
            return '1970-01-01 00:00:00';
        }

        return gmdate('Y-m-d H:i:s', $baseline);
    }

    public static function run_scan_job(array $job): void {
        global $wpdb;
        $payload = $job['payload'] ?? [];
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($payload)) {
            $payload = [];
        }

        $target_id = (int)($payload['target_id'] ?? 0);
        $strategy = (($payload['strategy'] ?? 'mobile') === 'desktop') ? 'desktop' : 'mobile';
        if ($target_id <= 0) {
            throw new \InvalidArgumentException('Missing target_id');
        }

        $target = Targets::get($target_id);
        if (!$target || empty($target['url'])) {
            throw new \RuntimeException('Invalid lighthouse target');
        }

        $result = Collector_PSI::run((string)$target['url'], $strategy);
        $norm = Normalizer::normalize($result);

        $runs = $wpdb->prefix . 'tmw_lighthouse_runs';
        $targets = $wpdb->prefix . 'tmw_lighthouse_targets';

        $wpdb->insert($runs, [
            'target_id' => $target_id,
            'strategy' => $strategy,
            'lighthouse_version' => $norm['lighthouse_version'],
            'performance_score' => $norm['performance_score'],
            'seo_score' => $norm['seo_score'],
            'lcp' => $norm['lcp'],
            'cls' => $norm['cls'],
            'inp' => $norm['inp'],
            'raw_json' => wp_json_encode($result),
            'created_at' => current_time('mysql'),
        ], ['%d','%s','%s','%f','%f','%f','%f','%f','%s','%s']);

        if ($wpdb->last_error) {
            throw new \RuntimeException('Insert run failed: ' . $wpdb->last_error);
        }

        $scan_col = $strategy === 'desktop' ? 'last_scanned_desktop' : 'last_scanned_mobile';
        $wpdb->query($wpdb->prepare("UPDATE {$targets} SET {$scan_col} = %s WHERE id = %d", current_time('mysql'), $target_id));

        Logs::info('lighthouse', '[TMW-LH] Scanned target', ['target_id' => $target_id, 'strategy' => $strategy]);
    }

    public static function schedule_weekly_scan(): void {
        $target_count = Targets::sync(100);
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_lighthouse_targets';
        $targets = $wpdb->get_results("SELECT id FROM {$table} ORDER BY id ASC LIMIT 100", ARRAY_A) ?: [];
        $baseline = self::get_baseline_timestamp();

        foreach ($targets as $target) {
            $id = (int)$target['id'];
            Jobs::enqueue('lighthouse_scan_url', 'lighthouse_target', $id, ['target_id' => $id, 'strategy' => 'mobile']);
            Jobs::enqueue('lighthouse_scan_url', 'lighthouse_target', $id, ['target_id' => $id, 'strategy' => 'desktop']);
        }

        Logs::info('lighthouse', '[TMW-LH] Weekly scan jobs enqueued', ['targets_synced' => $target_count, 'jobs' => count($targets) * 2, 'baseline_timestamp' => $baseline]);
    }
}
