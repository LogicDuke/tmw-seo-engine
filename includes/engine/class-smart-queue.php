<?php
namespace TMWSEO\Engine;

if (!defined('ABSPATH')) { exit; }

class SmartQueue {
    private const CRON_HOOK = 'tmwseo_daily_scan';

    public static function init(): void {
        add_action(self::CRON_HOOK, [__CLASS__, 'scan']);
    }

    public static function schedule_daily_scan(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CRON_HOOK);
        }
    }

    public static function unschedule_daily_scan(): void {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    /**
     * Phase C safe migration helper:
     * read-only candidate discovery for operator-triggered review flows.
     *
     * @return array<string,mixed>
     */
    public static function discovery_snapshot(int $limit = 20): array {
        $limit = max(1, min(100, $limit));

        $posts = get_posts([
            'post_type'      => ['model'],
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $candidates = [];
        $recently_optimized = 0;

        foreach ($posts as $post) {
            if (!($post instanceof \WP_Post)) {
                continue;
            }

            if (self::skip_recently_optimized($post->ID)) {
                $recently_optimized++;
                continue;
            }

            $health = (int) get_post_meta($post->ID, '_tmwseo_health_score', true);
            if ($health === 0) {
                $health = 40; // assume weak if no score yet
            }

            if ($health < 70) {
                $candidates[] = [
                    'post_id' => (int) $post->ID,
                    'post_title' => (string) get_the_title($post->ID),
                    'health_score' => $health,
                    'last_optimized' => (string) get_post_meta($post->ID, '_tmwseo_optimize_done', true),
                ];
            }
        }

        return [
            'scanned' => count($posts),
            'eligible_candidates' => count($candidates),
            'recently_optimized_skipped' => $recently_optimized,
            'candidates' => $candidates,
        ];
    }

    public static function scan(): void {

        $debug = (bool) \TMWSEO\Engine\Services\Settings::get('debug_mode', false);
        if ($debug) { error_log('TMW SmartQueue SCAN START'); }

        $snapshot = self::discovery_snapshot(20);
        $candidates = isset($snapshot['candidates']) && is_array($snapshot['candidates']) ? $snapshot['candidates'] : [];

        foreach ($candidates as $candidate) {
            $post_id = (int) ($candidate['post_id'] ?? 0);
            if ($post_id <= 0) {
                continue;
            }

            if ($debug) { error_log('TMW SmartQueue ENQUEUE POST ID: ' . $post_id); }

            \TMWSEO\Engine\Jobs::enqueue(
                'optimize_post',
                'model',
                $post_id,
                [
                    'context' => 'model',
                    'trigger' => 'smartqueue',
                ],
                0
            );

            update_post_meta($post_id, '_tmwseo_optimize_enqueued', 1);
        }

        if ($debug) { error_log('TMW SmartQueue SCAN END'); }
    }

    private static function skip_recently_optimized(int $post_id): bool {
        $last_done = get_post_meta($post_id, '_tmwseo_optimize_done', true);
        if (empty($last_done)) {
            return false;
        }

        $last_done_ts = is_numeric($last_done) ? (int) $last_done : strtotime((string) $last_done);

        if ($last_done_ts !== false && $last_done_ts > 0) {
            return $last_done_ts > (time() - WEEK_IN_SECONDS);
        }

        return (int) get_post_modified_time('U', true, $post_id) > (time() - DAY_IN_SECONDS);
    }
}
