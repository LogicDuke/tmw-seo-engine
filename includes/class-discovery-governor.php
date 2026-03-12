<?php
namespace TMWSEO\Engine;

if (!defined('ABSPATH')) { exit; }

class DiscoveryGovernor {
    private const METRICS = [
        'keywords_discovered' => 500,
        'models_discovered' => 200,
        'serp_requests' => 150,
        'queue_jobs_created' => 300,
    ];

    private static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_discovery_governor';
    }

    public static function is_enabled(): bool {
        return (bool) get_option('tmw_discovery_enabled', 1);
    }

    public static function is_discovery_allowed(): bool {
        if (self::is_enabled()) {
            return true;
        }

        Logs::warn('discovery_governor', 'Discovery governor triggered: discovery disabled by kill switch.');
        return false;
    }

    public static function defaults(): array {
        return self::METRICS;
    }

    public static function ensure_defaults(): void {
        global $wpdb;

        add_option('tmw_discovery_enabled', 1, '', false);

        $table = self::table_name();
        foreach (self::METRICS as $metric => $limit) {
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE metric = %s",
                $metric
            ));

            if ($exists > 0) {
                continue;
            }

            $wpdb->insert(
                $table,
                [
                    'metric' => $metric,
                    'limit_value' => (int) $limit,
                    'current_value' => 0,
                    'reset_at' => gmdate('Y-m-d H:i:s', strtotime('+1 day')),
                ],
                ['%s', '%d', '%d', '%s']
            );
        }
    }

    /** @return array<string,int|string> */
    private static function get_metric_row(string $metric): array {
        global $wpdb;
        $table = self::table_name();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE metric = %s LIMIT 1", $metric),
            ARRAY_A
        );

        if (!is_array($row)) {
            return [];
        }

        self::reset_if_due($metric, $row);

        $fresh = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE metric = %s LIMIT 1", $metric),
            ARRAY_A
        );

        return is_array($fresh) ? $fresh : [];
    }

    /** @param array<string,mixed> $row */
    private static function reset_if_due(string $metric, array $row): void {
        global $wpdb;

        $reset_at = (string) ($row['reset_at'] ?? '');
        if ($reset_at === '' || strtotime($reset_at) > time()) {
            return;
        }

        $table = self::table_name();
        $wpdb->update(
            $table,
            [
                'current_value' => 0,
                'reset_at' => gmdate('Y-m-d H:i:s', strtotime('+1 day')),
            ],
            ['metric' => $metric],
            ['%d', '%s'],
            ['%s']
        );
    }

    public static function remaining(string $metric): int {
        $row = self::get_metric_row($metric);
        if (empty($row)) {
            return PHP_INT_MAX;
        }

        return max(0, (int) ($row['limit_value'] ?? 0) - (int) ($row['current_value'] ?? 0));
    }

    public static function can_increment(string $metric, int $amount = 1): bool {
        $amount = max(1, $amount);
        $row = self::get_metric_row($metric);

        if (empty($row)) {
            return true;
        }

        $current = (int) ($row['current_value'] ?? 0);
        $limit = (int) ($row['limit_value'] ?? 0);

        if (($current + $amount) <= $limit) {
            return true;
        }

        Logs::warn('discovery_governor', sprintf('Discovery governor triggered: %s limit reached.', str_replace('_', ' ', $metric)), [
            'metric' => $metric,
            'current_value' => $current,
            'limit_value' => $limit,
            'requested_increment' => $amount,
        ]);

        return false;
    }

    public static function increment(string $metric, int $amount = 1): bool {
        global $wpdb;

        $amount = max(1, $amount);
        if (!self::can_increment($metric, $amount)) {
            return false;
        }

        $table = self::table_name();
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET current_value = current_value + %d WHERE metric = %s",
            $amount,
            $metric
        ));

        return $updated !== false;
    }
}
