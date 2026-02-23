<?php
namespace TMWSEO\Engine;

if (!defined('ABSPATH')) { exit; }

class Jobs {

    public static function next(): ?array {
        $batch = self::claim_batch(1);
        if (empty($batch)) {
            return null;
        }

        return $batch[0];
    }

    public static function enqueue(string $type, string $entity_type, ?int $entity_id = null, array $payload = [], int $delay_seconds = 0): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_jobs';
        $run_after = gmdate('Y-m-d H:i:s', time() + max(0, $delay_seconds));
        $wpdb->insert(
            $table,
            [
                'type' => $type,
                'entity_type' => $entity_type,
                'entity_id' => $entity_id,
                'payload' => !empty($payload) ? wp_json_encode($payload) : null,
                'status' => 'queued',
                'attempts' => 0,
                'run_after' => $run_after,
                'locked_until' => null,
                'last_error' => null,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['%s','%s','%d','%s','%s','%d','%s','%s','%s','%s','%s']
        );
        return (int)$wpdb->insert_id;
    }

    public static function claim_batch(int $limit = 5): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_jobs';
        $limit = max(1, min(50, $limit));
        $now = current_time('mysql');
        $lock_until = gmdate('Y-m-d H:i:s', time() + 5*60);

        $jobs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table
                 WHERE status = 'queued'
                   AND run_after <= %s
                   AND (locked_until IS NULL OR locked_until < %s)
                 ORDER BY id ASC
                 LIMIT %d",
                $now, $now, $limit
            ),
            ARRAY_A
        );

        if (empty($jobs)) return [];

        $ids = array_map(fn($j) => (int)$j['id'], $jobs);
        $ids_in = implode(',', array_map('intval', $ids));

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table SET locked_until = %s, status = 'running', updated_at = %s WHERE id IN ($ids_in)",
                $lock_until,
                $now
            )
        );

        return $wpdb->get_results("SELECT * FROM $table WHERE id IN ($ids_in) ORDER BY id ASC", ARRAY_A);
    }

    public static function mark_success(int $id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_jobs';
        $wpdb->update(
            $table,
            ['status' => 'success', 'locked_until' => null, 'updated_at' => current_time('mysql')],
            ['id' => $id],
            ['%s','%s','%s'],
            ['%d']
        );
    }

    public static function mark_failed(int $id, string $error, int $attempts, int $next_delay_seconds): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_jobs';
        $attempts = max(1, $attempts);
        $status = ($attempts >= 4) ? 'dead' : 'queued';
        $run_after = gmdate('Y-m-d H:i:s', time() + max(60, $next_delay_seconds));

        $wpdb->update(
            $table,
            [
                'status' => $status,
                'attempts' => $attempts,
                'run_after' => $run_after,
                'locked_until' => null,
                'last_error' => $error,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s','%d','%s','%s','%s','%s'],
            ['%d']
        );
    }

    public static function list(int $limit = 200, string $status = ''): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_jobs';
        $limit = max(1, min(1000, $limit));
        if ($status !== '') {
            return $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM $table WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit),
                ARRAY_A
            );
        }
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT %d", $limit),
            ARRAY_A
        );
    }

    public static function counts(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_jobs';
        $rows = $wpdb->get_results("SELECT status, COUNT(*) as cnt FROM $table GROUP BY status", ARRAY_A);
        $out = ['queued'=>0,'running'=>0,'success'=>0,'dead'=>0];
        foreach ($rows as $r) { $out[$r['status']] = (int)$r['cnt']; }
        return $out;
    }
}
