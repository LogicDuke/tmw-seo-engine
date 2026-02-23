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
        $query = new \WP_Query([
            'post_type' => ['post', 'model', 'tmw_category_page'],
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        if (empty($query->posts)) {
            return;
        }

        $home_url = home_url();

        $enqueued_count = 0;

        foreach ($query->posts as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id <= 0) {
                continue;
            }

            if (get_post_meta($post_id, '_tmwseo_optimize_enqueued', true)) {
                continue;
            }

            $post = get_post($post_id);
            if (!$post instanceof \WP_Post) {
                continue;
            }

            $meta_title = trim((string) get_post_meta($post_id, 'rank_math_title', true));
            $meta_description = trim((string) get_post_meta($post_id, 'rank_math_description', true));
            $content = (string) $post->post_content;
            $word_count = str_word_count(strip_tags($content));

            $missing_meta_title = ($meta_title === '');
            $missing_meta_description = ($meta_description === '');
            $low_content = ($word_count < 300);
            $missing_internal_links = (strpos($content, $home_url) === false);

            if (!$missing_meta_title && !$missing_meta_description && !$low_content && !$missing_internal_links) {
                continue;
            }

            update_post_meta($post_id, '_tmwseo_optimize_enqueued', 1);

            Jobs::enqueue('optimize_post', $post->post_type, $post_id, [
                'context' => 'auto_scan',
                'trigger' => 'smart_queue',
            ]);

            $enqueued_count++;
        }

        Logs::info('smart_queue', '[TMW-SEO-AUTO] Daily smart queue scan completed', [
            'scanned_posts' => count($query->posts),
            'enqueued_posts' => $enqueued_count,
        ]);
    }
}
