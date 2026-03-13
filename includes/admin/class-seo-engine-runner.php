<?php
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\JobWorker;

if (!defined('ABSPATH')) { exit; }

class SEOEngineRunner {

    public static function init(): void {
        add_action('admin_post_tmw_run_keyword_cycle', [__CLASS__, 'handle_run_keyword_cycle']);
    }

    public static function handle_run_keyword_cycle(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'tmwseo'));
        }

        $has_new_nonce = isset($_POST['_wpnonce']) && wp_verify_nonce((string) $_POST['_wpnonce'], 'tmwseo_run_keyword_cycle');
        if (!$has_new_nonce) {
            check_admin_referer('tmw_seo_run_cycle');
        }

        Logs::info('keyword_engine', '[TMW-SEO] Manual keyword cycle triggered via legacy action (SEOEngineRunner)');

        JobWorker::enqueue_job('keyword_discovery', [
            'trigger' => 'manual_keyword_cycle',
            'user_id' => get_current_user_id(),
        ]);
        JobWorker::enqueue_job('cluster_generation', [
            'trigger' => 'manual_keyword_cycle',
            'user_id' => get_current_user_id(),
        ]);

        // Deterministic execution: process immediately instead of relying on killed cron.
        for ($i = 0; $i < 3; $i++) {
            JobWorker::process_next_job();
        }

        $redirect_url = wp_get_referer();
        if (!is_string($redirect_url) || $redirect_url === '') {
            $redirect_url = admin_url('admin.php?page=tmwseo-engine');
        }

        $redirect_url = add_query_arg('tmwseo_notice', 'seo_engine_cycle_executed', $redirect_url);
        wp_safe_redirect($redirect_url);
        exit;
    }
}
