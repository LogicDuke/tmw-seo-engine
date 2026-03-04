<?php
namespace TMW\SEO\Lighthouse;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class Bootstrap {
    public static function init(): void {
        add_action('tmw_lighthouse_weekly_scan', [Worker::class, 'schedule_weekly_scan']);
        // Register Lighthouse submenu after the parent TMW SEO Engine menu.
        add_action('admin_menu', [Dashboard::class, 'register_menu'], 99);
        add_filter('menu_page_url', [Dashboard::class, 'filter_menu_page_url'], 10, 2);
        add_action('admin_post_tmw_lighthouse_scan_all', [Dashboard::class, 'handle_scan_all']);
        add_action('admin_post_tmw_lighthouse_reset', [Dashboard::class, 'handle_reset']);
        add_action('admin_post_tmw_lighthouse_rebuild_targets', [Dashboard::class, 'handle_rebuild_targets']);

        Logs::info('lighthouse', '[TMW-LH] Lighthouse Engine bootstrap initialized');
    }
}
