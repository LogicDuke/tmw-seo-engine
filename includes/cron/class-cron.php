<?php
namespace TMWSEO\Engine;

if (!defined('ABSPATH')) { exit; }

class Cron {
    const HOOK_WORKER = 'tmwseo_worker_event';
    const HOOK_PROCESS_QUEUE = 'tmwseo_process_queue';
    const HOOK_DAILY = 'tmwseo_daily_event';
    const HOOK_WEEKLY = 'tmwseo_weekly_event';

    // Legacy weekly event from alpha.4
    const LEGACY_SCHEDULE = 'tmwseo_engine_weekly';
    const LEGACY_EVENT = 'tmwseo_engine_weekly_rank_tracker';

    public static function init(): void {
        add_filter('cron_schedules', [__CLASS__, 'add_schedules']);

        add_action(self::HOOK_WORKER, [Worker::class, 'run']);
        add_action(self::HOOK_PROCESS_QUEUE, [__CLASS__, 'process_queue']);
        add_action(self::HOOK_DAILY, [__CLASS__, 'daily']);
        add_action(self::HOOK_WEEKLY, [__CLASS__, 'weekly']);

        // Keep legacy event as no-op + log
        add_action(self::LEGACY_EVENT, [__CLASS__, 'legacy_weekly']);
    }

    public static function add_schedules(array $schedules): array {
        if (!isset($schedules['every_ten_minutes'])) {
            $schedules['every_ten_minutes'] = ['interval' => 600, 'display' => 'Every 10 Minutes'];
        }
        if (!isset($schedules['tmwseo_10min'])) {
            $schedules['tmwseo_10min'] = ['interval' => 10 * 60, 'display' => __('Every 10 minutes (TMW SEO Engine)', 'tmwseo')];
        }
        if (!isset($schedules['tmwseo_weekly'])) {
            $schedules['tmwseo_weekly'] = ['interval' => 7 * 24 * 60 * 60, 'display' => __('Weekly (TMW SEO Engine)', 'tmwseo')];
        }
        // Legacy schedule key
        if (!isset($schedules[self::LEGACY_SCHEDULE])) {
            $schedules[self::LEGACY_SCHEDULE] = ['interval' => 7 * DAY_IN_SECONDS, 'display' => 'TMW SEO Engine Weekly (legacy)'];
        }
        return $schedules;
    }

    public static function schedule_events(): void {
        if (!wp_next_scheduled(self::HOOK_PROCESS_QUEUE)) wp_schedule_event(time(), 'every_ten_minutes', self::HOOK_PROCESS_QUEUE);
        if (!wp_next_scheduled(self::HOOK_WORKER)) wp_schedule_event(time() + 120, 'tmwseo_10min', self::HOOK_WORKER);
        if (!wp_next_scheduled(self::HOOK_DAILY)) wp_schedule_event(time() + 300, 'daily', self::HOOK_DAILY);
        if (!wp_next_scheduled(self::HOOK_WEEKLY)) wp_schedule_event(time() + 600, 'tmwseo_weekly', self::HOOK_WEEKLY);

        // Ensure legacy event stays scheduled if it existed
        if (!wp_next_scheduled(self::LEGACY_EVENT)) wp_schedule_event(time() + 3600, self::LEGACY_SCHEDULE, self::LEGACY_EVENT);
    }

    public static function unschedule_events(): void {
        self::unschedule(self::HOOK_PROCESS_QUEUE);
        self::unschedule(self::HOOK_WORKER);
        self::unschedule(self::HOOK_DAILY);
        self::unschedule(self::HOOK_WEEKLY);
        self::unschedule(self::LEGACY_EVENT);
    }

    private static function unschedule(string $hook): void {
        $timestamp = wp_next_scheduled($hook);
        while ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
            $timestamp = wp_next_scheduled($hook);
        }
    }


    public static function process_queue(): void {
        if (get_transient('tmwseo_worker_lock')) {
            return;
        }

        set_transient('tmwseo_worker_lock', 1, 300);

        $max_jobs = 5;

        try {
            for ($i = 0; $i < $max_jobs; $i++) {
                $job = Jobs::next();

                if (!$job) {
                    break;
                }

                $id = (int)$job['id'];
                $attempts = (int)$job['attempts'];

                try {
                    Worker::dispatch($job);
                    Jobs::mark_success($id);
                } catch (\Throwable $e) {
                    $attempts++;
                    $delay = Worker::backoff_seconds($attempts);
                    Jobs::mark_failed($id, $e->getMessage(), $attempts, $delay);
                    Logs::error('worker', 'Process queue job failed', ['id' => $id, 'attempts' => $attempts, 'error' => $e->getMessage()]);
                }
            }
        } finally {
            delete_transient('tmwseo_worker_lock');
        }
    }

    public static function daily(): void {
        Logs::info('cron', 'Daily tick');
        Jobs::enqueue('healthcheck', 'system', null, ['note' => 'daily tick']);
        // alpha.8: keyword cycle (adaptive budget inside the job)
        Jobs::enqueue('keyword_cycle', 'system', null, ['trigger' => 'daily']);
    }

    public static function weekly(): void {
        Logs::info('cron', 'Weekly tick');
        Jobs::enqueue('healthcheck', 'system', null, ['note' => 'weekly tick']);
        Jobs::enqueue('pagespeed_cycle', 'system', null, ['trigger' => 'weekly']);
    }

    public static function legacy_weekly(): void {
        Logs::info('cron', 'Legacy weekly cron executed');
    }
}
