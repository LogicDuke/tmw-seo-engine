<?php
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class SEOEngineRunner {

    public static function init(): void {
        add_action('admin_post_tmw_run_keyword_cycle', [__CLASS__, 'handle_run_keyword_cycle']);
    }

    public static function handle_run_keyword_cycle(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'tmwseo'));
        }

        check_admin_referer('tmw_seo_run_cycle');

        Logs::info('keyword_engine', '[TMW-SEO-AUTO] manual keyword cycle triggered');
        do_action('keyword_cycle');

        $redirect_url = wp_get_referer();
        if (!is_string($redirect_url) || $redirect_url === '') {
            $redirect_url = admin_url('admin.php?page=tmwseo-engine');
        }

        $redirect_url = add_query_arg('tmwseo_notice', 'seo_engine_cycle_executed', $redirect_url);
        wp_safe_redirect($redirect_url);
        exit;
    }
}
