<?php
/**
 * Plugin Name: TMW SEO Engine
 * Description: Intelligence Core (Phase 2) — Manual analysis, reporting, and Model SEO Optimizer. No automatic actions.
 * Version: 4.0.2-intelligence-phase2-proud
 * Author: The Milisofia Ltd
 * Text Domain: tmwseo
 */

if (!defined('ABSPATH')) { exit; }

define('TMWSEO_ENGINE_VERSION', '4.0.2-intelligence-phase2-proud');
define('TMWSEO_ENGINE_PATH', plugin_dir_path(__FILE__));
define('TMWSEO_ENGINE_URL', plugin_dir_url(__FILE__));

require_once TMWSEO_ENGINE_PATH . 'includes/class-plugin.php';

// Core activation/deactivation.
register_activation_hook(__FILE__, ['TMWSEO\\Engine\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['TMWSEO\\Engine\\Plugin', 'deactivate']);

/**
 * DB migrations (cluster + intelligence) must run on:
 * - activation (fresh install)
 * - plugins_loaded (updates while active)
 */
add_action('plugins_loaded', function () {
    // Cluster migration.
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
        }
    }

    // Intelligence migration.
    if (!class_exists('TMW_Intelligence_DB_Migration')) {
        $migration_file = plugin_dir_path(__FILE__) . 'includes/migrations/class-intelligence-db-migration.php';
        if (file_exists($migration_file)) {
            require_once $migration_file;
        }
    }
    if (class_exists('TMW_Intelligence_DB_Migration') && method_exists('TMW_Intelligence_DB_Migration', 'maybe_migrate')) {
        TMW_Intelligence_DB_Migration::maybe_migrate();
    }
});

// On activation, also run migrations (best-effort).
register_activation_hook(__FILE__, function () {
    if (file_exists(plugin_dir_path(__FILE__) . 'includes/migrations/class-cluster-db-migration.php')) {
        require_once plugin_dir_path(__FILE__) . 'includes/migrations/class-cluster-db-migration.php';
        if (class_exists('TMW_Cluster_DB_Migration')) {
            $migration = new TMW_Cluster_DB_Migration();
            if (method_exists($migration, 'maybe_migrate')) {
                $migration->maybe_migrate();
            }
        }
    }

    if (file_exists(plugin_dir_path(__FILE__) . 'includes/migrations/class-intelligence-db-migration.php')) {
        require_once plugin_dir_path(__FILE__) . 'includes/migrations/class-intelligence-db-migration.php';
        if (class_exists('TMW_Intelligence_DB_Migration')) {
            TMW_Intelligence_DB_Migration::maybe_migrate();
        }
    }
});

// Bootstrap plugin.
add_action('plugins_loaded', function () {
    \TMWSEO\Engine\Plugin::init();
});
