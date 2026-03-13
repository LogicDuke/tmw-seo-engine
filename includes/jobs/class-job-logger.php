<?php

if (!defined('ABSPATH')) { exit; }

class TMW_Job_Logger {
    private const TABLE_SUFFIX = 'tmw_job_history';

    private static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function create_job($type, $source, $items_total): int {
        global $wpdb;

        $table = self::table_name();
        $items_total = max(0, (int) $items_total);

        $inserted = $wpdb->insert($table, [
            'job_type' => sanitize_key((string) $type),
            'source' => sanitize_text_field((string) $source),
            'status' => 'running',
            'items_total' => $items_total,
            'items_processed' => 0,
            'started_at' => current_time('mysql'),
            'finished_at' => null,
            'message' => null,
        ], ['%s','%s','%s','%d','%d','%s','%s','%s']);

        if ($inserted === false) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    public static function update_progress($job_id, $processed): void {
        global $wpdb;

        $job_id = (int) $job_id;
        if ($job_id <= 0) {
            return;
        }

        $table = self::table_name();
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT items_total FROM {$table} WHERE id = %d", $job_id));
        if ($total < 0) {
            $total = 0;
        }

        $processed = max(0, (int) $processed);
        if ($total > 0) {
            $processed = min($processed, $total);
        }

        $wpdb->update($table, [
            'items_processed' => $processed,
            'status' => 'running',
        ], ['id' => $job_id], ['%d', '%s'], ['%d']);
    }

    public static function complete_job($job_id): void {
        global $wpdb;

        $job_id = (int) $job_id;
        if ($job_id <= 0) {
            return;
        }

        $table = self::table_name();
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT items_total FROM {$table} WHERE id = %d", $job_id));
        if ($total < 0) {
            $total = 0;
        }

        $wpdb->update($table, [
            'status' => 'completed',
            'items_processed' => $total,
            'finished_at' => current_time('mysql'),
            'message' => null,
        ], ['id' => $job_id], ['%s', '%d', '%s', '%s'], ['%d']);
    }

    public static function fail_job($job_id, $message): void {
        global $wpdb;

        $job_id = (int) $job_id;
        if ($job_id <= 0) {
            return;
        }

        $table = self::table_name();

        $wpdb->update($table, [
            'status' => 'failed',
            'finished_at' => current_time('mysql'),
            'message' => sanitize_textarea_field((string) $message),
        ], ['id' => $job_id], ['%s', '%s', '%s'], ['%d']);
    }

    public static function list_jobs(string $status = 'all', int $limit = 100): array {
        global $wpdb;

        $table = self::table_name();
        $limit = max(1, min(500, $limit));

        if (in_array($status, ['running', 'completed', 'failed', 'pending'], true)) {
            return $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit),
                ARRAY_A
            ) ?: [];
        }

        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit),
            ARRAY_A
        ) ?: [];
    }

    public static function cleanup_completed_older_than_days(int $days = 30): int {
        global $wpdb;

        $table = self::table_name();
        $days = max(1, $days);
        $threshold = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status = 'completed' AND finished_at IS NOT NULL AND finished_at < %s",
            $threshold
        ));

        return max(0, (int) $deleted);
    }
}
