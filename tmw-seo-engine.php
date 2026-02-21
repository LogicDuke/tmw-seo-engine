<?php
/**
 * Plugin Name: TMW SEO Engine
 * Description: Foundation (Phase 1+) with admin, settings shell, DB tables, job queue, worker, logs, cron. AI + Keyword Intelligence + Programmatic Pages (manual indexing).
 * Version: 3.0.0-alpha.8
 * Author: The Milisofia Ltd
 * Text Domain: tmwseo
 */

if (!defined('ABSPATH')) { exit; }

define('TMWSEO_ENGINE_VERSION', '3.0.0-alpha.8');
define('TMWSEO_ENGINE_PATH', plugin_dir_path(__FILE__));
define('TMWSEO_ENGINE_URL', plugin_dir_url(__FILE__));

require_once TMWSEO_ENGINE_PATH . 'includes/class-plugin.php';

register_activation_hook(__FILE__, ['TMWSEO\\Engine\\Plugin', 'activate']);
register_activation_hook(__FILE__, function () {
    require_once plugin_dir_path(__FILE__) . 'includes/migrations/class-cluster-db-migration.php';
    TMW_Cluster_DB_Migration::maybe_migrate();
});
register_deactivation_hook(__FILE__, ['TMWSEO\\Engine\\Plugin', 'deactivate']);

add_action('plugins_loaded', function () {
    if (!class_exists('TMW_Cluster_DB_Migration')) {
        require_once plugin_dir_path(__FILE__) . 'includes/migrations/class-cluster-db-migration.php';
    }

    TMW_Cluster_DB_Migration::maybe_migrate();
});

add_action('plugins_loaded', function () {
    \TMWSEO\Engine\Plugin::init();
});
