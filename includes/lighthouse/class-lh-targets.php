<?php
namespace TMW\SEO\Lighthouse;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class Targets {
    public static function sync(int $limit = 100): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_lighthouse_targets';

        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);

        $count = 0;
        foreach ($posts as $post_id) {
            $url = get_permalink((int)$post_id);
            if (!$url) {
                continue;
            }

            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE post_id = %d", (int)$post_id));
            if ($existing) {
                $count++;
                continue;
            }

            $ok = $wpdb->insert($table, [
                'url' => esc_url_raw($url),
                'post_id' => (int)$post_id,
                'type' => get_post_type((int)$post_id) ?: 'post',
                'created_at' => current_time('mysql'),
            ], ['%s', '%d', '%s', '%s']);

            if ($ok !== false) {
                $count++;
            }
        }

        Logs::info('lighthouse', '[TMW-LH] Synced targets', ['count' => $count, 'limit' => $limit]);
        return $count;
    }

    public static function get(int $target_id): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_lighthouse_targets';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $target_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function list_with_latest(string $strategy = 'mobile', int $limit = 100): array {
        global $wpdb;
        $targets = $wpdb->prefix . 'tmw_lighthouse_targets';
        $runs = $wpdb->prefix . 'tmw_lighthouse_runs';

        $strategy = $strategy === 'desktop' ? 'desktop' : 'mobile';

        $sql = $wpdb->prepare(
            "SELECT t.*, r.performance_score, r.seo_score, r.lcp, r.cls, r.inp, r.created_at as last_run_at
             FROM {$targets} t
             LEFT JOIN {$runs} r ON r.id = (
                 SELECT rr.id FROM {$runs} rr
                 WHERE rr.target_id = t.id AND rr.strategy = %s
                 ORDER BY rr.created_at DESC
                 LIMIT 1
             )
             ORDER BY t.id DESC
             LIMIT %d",
            $strategy,
            $limit
        );

        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }
}
