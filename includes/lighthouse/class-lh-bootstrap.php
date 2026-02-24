<?php
namespace TMW\SEO\Lighthouse;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class Bootstrap {
    public static function init(): void {
        add_action('tmw_lighthouse_weekly_scan', [Worker::class, 'schedule_weekly_scan']);
        add_action('admin_menu', [Dashboard::class, 'register_menu']);
        add_action('admin_post_tmw_lighthouse_scan_all', [Dashboard::class, 'handle_scan_all']);

        Logs::info('lighthouse', '[TMW-LH] Lighthouse Engine bootstrap initialized');
    }
}
