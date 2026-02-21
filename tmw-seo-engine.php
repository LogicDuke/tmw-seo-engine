<?php
/**
 * Plugin Name: TMW SEO Engine
 * Description: Foundation (Phase 1+) with admin, settings shell, DB tables, job queue, worker, logs, cron. AI + Keyword Intelligence + Programmatic Pages (manual indexing).
 * Version: 3.1.0-alpha-cluster-db
 * Author: The Milisofia Ltd
 * Text Domain: tmwseo
 */

if (!defined('ABSPATH')) { exit; }

define('TMWSEO_ENGINE_VERSION', '3.1.0-alpha-cluster-db');
define('TMWSEO_ENGINE_PATH', plugin_dir_path(__FILE__));
define('TMWSEO_ENGINE_URL', plugin_dir_url(__FILE__));

require_once TMWSEO_ENGINE_PATH . 'includes/class-plugin.php';

register_activation_hook(__FILE__, ['TMWSEO\\Engine\\Plugin', 'activate']);
register_activation_hook(__FILE__, function () {
    if (!class_exists('TMW_Cluster_DB_Migration')) {
        $migration_file = plugin_dir_path(__FILE__) . 'includes/migrations/class-cluster-db-migration.php';
        if (file_exists($migration_file)) {
            require_once $migration_file;
        }
    }

    if (class_exists('TMW_Cluster_DB_Migration')) {
        $migration = new TMW_Cluster_DB_Migration();
        if (method_exists($migration, 'maybe_migrate')) {
            $migration->maybe_migrate();

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[TMW-DIAG] TMW_Cluster_DB_Migration->maybe_migrate() executed during activation.');
            }
        } else {
            error_log('[TMW-DIAG] TMW_Cluster_DB_Migration::maybe_migrate() not found as instance method during activation.');
        }
    } else {
        error_log('[TMW-DIAG] TMW_Cluster_DB_Migration class missing during activation; migration not run.');
    }
});
register_deactivation_hook(__FILE__, ['TMWSEO\\Engine\\Plugin', 'deactivate']);

add_action('plugins_loaded', function () {
    if (!class_exists('TMW_Cluster_DB_Migration')) {
        $migration_file = plugin_dir_path(__FILE__) . 'includes/migrations/class-cluster-db-migration.php';
        if (file_exists($migration_file)) {
            require_once $migration_file;
        }
    }

    if (class_exists('TMW_Cluster_DB_Migration')) {
        $migration = new TMW_Cluster_DB_Migration();
        if (method_exists($migration, 'maybe_migrate')) {
            $migration->maybe_migrate();

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[TMW-DIAG] TMW_Cluster_DB_Migration->maybe_migrate() executed on plugins_loaded.');
            }
        } else {
            error_log('[TMW-DIAG] TMW_Cluster_DB_Migration::maybe_migrate() not found as instance method on plugins_loaded.');
        }
    } else {
        error_log('[TMW-DIAG] TMW_Cluster_DB_Migration class missing on plugins_loaded; migration not run.');
    }
});

add_action('plugins_loaded', function () {
    \TMWSEO\Engine\Plugin::init();
});
