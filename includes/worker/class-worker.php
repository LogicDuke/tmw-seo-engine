<?php
namespace TMWSEO\Engine;

if (!defined('ABSPATH')) { exit; }

class Worker {

    public static function run(): void {
        $batch = Jobs::claim_batch((int)apply_filters('tmwseo_worker_batch_size', 5));
        if (empty($batch)) return;

        Logs::info('worker', 'Claimed jobs', ['count' => count($batch)]);

        foreach ($batch as $job) {
            $id = (int)$job['id'];
            $type = (string)$job['type'];
            $attempts = (int)$job['attempts'];

            try {
                Logs::info('worker', 'Running job', ['id' => $id, 'type' => $type]);
                self::dispatch($job);
                Jobs::mark_success($id);
                Logs::info('worker', 'Job success', ['id' => $id, 'type' => $type]);
            } catch (\Throwable $e) {
                $attempts++;
                $delay = self::backoff_seconds($attempts);
                Jobs::mark_failed($id, $e->getMessage(), $attempts, $delay);
                Logs::error('worker', 'Job failed', ['id'=>$id,'type'=>$type,'attempts'=>$attempts,'error'=>$e->getMessage()]);
            }
        }
    }

    private static function dispatch(array $job): void {
        $type = (string)$job['type'];
        switch ($type) {
            case 'healthcheck':
                self::healthcheck();
                return;

            case 'optimize_post':
                \TMWSEO\Engine\Content\ContentEngine::run_optimize_job($job);
                return;

            case 'keyword_cycle':
                \TMWSEO\Engine\Keywords\KeywordEngine::run_cycle_job($job);
                return;

            case 'pagespeed_cycle':
                \TMWSEO\Engine\Services\PageSpeed::run_cycle_job($job);
                return;

            default:
                Logs::warn('worker', 'Unknown job type', ['type' => $type]);
                return;
        }
    }

private static function healthcheck(): void {
        $opts = get_option('tmwseo_engine_settings', []);
        if (!is_array($opts)) $opts = [];

        $checks = [
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'theme' => wp_get_theme()->get('Name'),
            'is_rankmath_active' => class_exists('RankMath\\Helper'),

            // alpha.6: surface config in logs (no secrets).
            'safe_mode' => !empty($opts['safe_mode']),
            'openai_mode' => (string)($opts['openai_mode'] ?? 'hybrid'),
            'openai_model_primary' => (string)($opts['openai_model_primary'] ?? ($opts['openai_model'] ?? 'gpt-4o')),
            'openai_model_bulk' => (string)($opts['openai_model_bulk'] ?? 'gpt-4o-mini'),
            'brand_voice' => (string)($opts['brand_voice'] ?? 'premium'),
            'openai_api_key_set' => !empty($opts['openai_api_key']),
            'dataforseo_login_set' => !empty($opts['dataforseo_login']),
        ];
        Logs::info('health', 'Healthcheck', $checks);
    }

    private static function backoff_seconds(int $attempts): int {
        if ($attempts <= 1) return 15 * 60;
        if ($attempts == 2) return 60 * 60;
        if ($attempts == 3) return 6 * 60 * 60;
        return 12 * 60 * 60;
    }
}
