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

    public static function scan(): void {

        error_log('TMW SmartQueue SCAN START');

        $args = [
            'post_type'      => ['model'],
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        $posts = get_posts($args);

        foreach ($posts as $post) {

            $post_id = $post->ID;

            // Skip if already optimized recently
            $last_done = get_post_meta($post_id, '_tmwseo_optimize_done', true);
            if ($last_done === 'done') {
                continue;
            }

            $health = (int) get_post_meta($post_id, '_tmwseo_health_score', true);

            if ($health === 0) {
                $health = 40; // assume weak if no score yet
            }

            if ($health < 70) {

                error_log('TMW SmartQueue ENQUEUE POST ID: ' . $post_id);

                \TMWSEO\Engine\Jobs::enqueue(
                    'optimize_post',
                    'post',
                    $post_id,
                    [],
                    0
                );

                update_post_meta($post_id, '_tmwseo_optimize_enqueued', 1);
            }
        }

        error_log('TMW SmartQueue SCAN END');
    }
}
