<?php

namespace TMWSEO\Engine;

if (!defined('ABSPATH')) {
    exit;
}

class WorkerCron {

    public static function init(): void
    {
        error_log('TMW WorkerCron INIT CALLED');

        add_filter('cron_schedules', [self::class, 'register_interval']);
        add_action('tmwseo_process_queue', [self::class, 'process_queue']);

        if (!wp_next_scheduled('tmwseo_process_queue')) {
            wp_schedule_event(time(), 'tmwseo_every_ten_minutes', 'tmwseo_process_queue');
        }
    }

    public static function activate(): void
    {
        if (!wp_next_scheduled('tmwseo_process_queue')) {
            wp_schedule_event(time(), 'tmwseo_every_ten_minutes', 'tmwseo_process_queue');
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('tmwseo_process_queue');
    }

    public static function register_interval(array $schedules): array
    {
        $schedules['tmwseo_every_ten_minutes'] = [
            'interval' => 600,
            'display'  => 'Every 10 Minutes',
        ];

        return $schedules;
    }

    public static function process_queue(): void
    {
        error_log('TMW WorkerCron PROCESS QUEUE CALLED');

        if (get_transient('tmwseo_worker_lock')) {
            return;
        }

        set_transient('tmwseo_worker_lock', 1, 300);

        $max_jobs = 5;

        for ($i = 0; $i < $max_jobs; $i++) {

            if (!class_exists('\\TMWSEO\\Engine\\Jobs')) {
                break;
            }

            if (!class_exists('\\TMWSEO\\Engine\\Worker')) {
                break;
            }

            $job = \TMWSEO\Engine\Jobs::next();

            if (!$job) {
                break;
            }

            \TMWSEO\Engine\Worker::dispatch($job);
        }

        delete_transient('tmwseo_worker_lock');
    }
}
