<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class DirtyQueue {
    private const STATUS_QUEUED = 'queued';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_DONE = 'done';
    private const STATUS_FAILED = 'failed';

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_seo_dirty_queue';
    }

    public static function enqueue(string $object_type, int $object_id, string $reason, ?string $scheduled_at = null): void {
        global $wpdb;

        if ($object_id <= 0 || $object_type === '') {
            return;
        }

        $table = self::table_name();
        $now = current_time('mysql');
        $scheduled_at = $scheduled_at ?: $now;

        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE object_type=%s AND object_id=%d AND status IN ('queued','processing') ORDER BY id DESC LIMIT 1",
            $object_type,
            $object_id
        ));

        if ($existing_id > 0) {
            $wpdb->update($table, [
                'reason' => $reason,
                'scheduled_at' => $scheduled_at,
                'updated_at' => $now,
            ], ['id' => $existing_id]);
            return;
        }

        $wpdb->insert($table, [
            'object_type' => $object_type,
            'object_id' => $object_id,
            'reason' => $reason,
            'status' => self::STATUS_QUEUED,
            'scheduled_at' => $scheduled_at,
            'attempts' => 0,
            'last_error' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s','%d','%s','%s','%s','%d','%s','%s','%s']);
    }

    public static function process_batches(int $keyword_limit = 50, int $cluster_limit = 30, int $page_limit = 20): void {
        $claimed = self::claim_items('keyword', $keyword_limit);
        foreach ($claimed as $item) {
            self::process_one($item);
        }

        $claimed = self::claim_items('cluster', $cluster_limit);
        foreach ($claimed as $item) {
            self::process_one($item);
        }

        $claimed = self::claim_items('page', $page_limit);
        foreach ($claimed as $item) {
            self::process_one($item);
        }
    }

    /** @return array<int,array<string,mixed>> */
    private static function claim_items(string $object_type, int $limit): array {
        global $wpdb;
        $limit = max(1, $limit);
        $table = self::table_name();
        $now = current_time('mysql');

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE object_type=%s AND status=%s AND scheduled_at <= %s
             ORDER BY scheduled_at ASC, id ASC
             LIMIT %d",
            $object_type,
            self::STATUS_QUEUED,
            $now,
            $limit
        ));

        $items = [];
        foreach ((array) $ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }

            $updated = $wpdb->update($table, [
                'status' => self::STATUS_PROCESSING,
                'updated_at' => $now,
            ], [
                'id' => $id,
                'status' => self::STATUS_QUEUED,
            ], ['%s','%s'], ['%d','%s']);

            if ($updated !== 1) {
                continue;
            }

            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
            if (is_array($row)) {
                $items[] = $row;
            }
        }

        return $items;
    }

    /** @param array<string,mixed> $item */
    private static function process_one(array $item): void {
        global $wpdb;
        $table = self::table_name();
        $id = (int) ($item['id'] ?? 0);
        $object_type = (string) ($item['object_type'] ?? '');
        $object_id = (int) ($item['object_id'] ?? 0);

        try {
            if ($object_type === 'keyword') {
                KeywordEngine::process_dirty_keyword($object_id, (string) ($item['reason'] ?? 'dirty'));
            } elseif ($object_type === 'cluster') {
                KeywordEngine::process_dirty_cluster($object_id, (string) ($item['reason'] ?? 'dirty'));
            } elseif ($object_type === 'page') {
                KeywordEngine::process_dirty_page($object_id, (string) ($item['reason'] ?? 'dirty'));
            }

            $wpdb->update($table, [
                'status' => self::STATUS_DONE,
                'updated_at' => current_time('mysql'),
            ], ['id' => $id], ['%s','%s'], ['%d']);
        } catch (\Throwable $e) {
            $attempts = (int) ($item['attempts'] ?? 0) + 1;
            $next_status = $attempts >= 5 ? self::STATUS_FAILED : self::STATUS_QUEUED;
            $delay_minutes = min(60, max(5, $attempts * 5));

            $wpdb->update($table, [
                'status' => $next_status,
                'attempts' => $attempts,
                'last_error' => $e->getMessage(),
                'scheduled_at' => gmdate('Y-m-d H:i:s', time() + ($delay_minutes * MINUTE_IN_SECONDS)),
                'updated_at' => current_time('mysql'),
            ], ['id' => $id], ['%s','%d','%s','%s','%s'], ['%d']);

            Logs::error('dirty_queue', 'Dirty queue item failed', [
                'id' => $id,
                'object_type' => $object_type,
                'object_id' => $object_id,
                'attempts' => $attempts,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
