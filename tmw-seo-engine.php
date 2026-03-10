<?php
/**
 * Plugin Name: TMW SEO Engine
 * Description: Intelligence Core v4.2 — Real GSC, AI Router (OpenAI+Claude), SERP data, Ranking Probability Orchestrator, JSON-LD Schema, Orphan Detector, CSV Export, Competitor Monitor.
 * Version: 4.2.2
 * Author: The Milisofia Ltd
 * Text Domain: tmwseo
 */

if (!defined('ABSPATH')) { exit; }

if (defined('TMWSEO_ENGINE_BOOTSTRAPPED')) {
    return;
}

define('TMWSEO_ENGINE_BOOTSTRAPPED', true);
defined('TMWSEO_ENGINE_VERSION') || define('TMWSEO_ENGINE_VERSION', '4.2.2');
defined('TMWSEO_ENGINE_PATH') || define('TMWSEO_ENGINE_PATH', plugin_dir_path(__FILE__));
defined('TMWSEO_ENGINE_URL') || define('TMWSEO_ENGINE_URL', plugin_dir_url(__FILE__));

require_once TMWSEO_ENGINE_PATH . 'includes/class-plugin.php';

if (!function_exists('tmwseo_engine_run_migrations')) {
    /**
     * Run additive migrations once per request.
     */
    function tmwseo_engine_run_migrations(): void {
        static $did_run = false;

        if ($did_run) {
            return;
        }

        $did_run = true;

        $cluster_migration_file = TMWSEO_ENGINE_PATH . 'includes/migrations/class-cluster-db-migration.php';
        if (!class_exists('TMW_Cluster_DB_Migration', false) && file_exists($cluster_migration_file)) {
            require_once $cluster_migration_file;
        }

        if (class_exists('TMW_Cluster_DB_Migration', false) && method_exists('TMW_Cluster_DB_Migration', 'maybe_migrate')) {
            TMW_Cluster_DB_Migration::maybe_migrate();
        }

        $intelligence_migration_file = TMWSEO_ENGINE_PATH . 'includes/migrations/class-intelligence-db-migration.php';
        if (!class_exists('TMW_Intelligence_DB_Migration', false) && file_exists($intelligence_migration_file)) {
            require_once $intelligence_migration_file;
        }

        if (class_exists('TMW_Intelligence_DB_Migration', false) && method_exists('TMW_Intelligence_DB_Migration', 'maybe_migrate')) {
            TMW_Intelligence_DB_Migration::maybe_migrate();
        }
    }
}


if (!function_exists('tmw_seo_is_blocked_keyword')) {
    /**
     * Returns true when keyword matches a configured negative filter phrase.
     */
    function tmw_seo_is_blocked_keyword($keyword): bool {
        $keyword = strtolower(trim((string) wp_strip_all_tags((string) $keyword)));
        if ($keyword === '') {
            return false;
        }

        $defaults = "video chat
random chat
omegle
chatroulette
chat room
chatroom
stranger chat
talk to strangers";
        $raw_filters = (string) \TMWSEO\Engine\Services\Settings::get('keyword_negative_filters', $defaults);
        $filters = preg_split('/\\r\\n|\\r|\\n/', $raw_filters);
        if (!is_array($filters)) {
            $filters = [];
        }

        foreach ($filters as $filter) {
            $phrase = strtolower(trim((string) $filter));
            if ($phrase === '') {
                continue;
            }

            if (strpos($keyword, $phrase) !== false) {
                return true;
            }
        }

        return false;
    }
}

// Core activation/deactivation.
register_activation_hook(__FILE__, ['TMWSEO\\Engine\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['TMWSEO\\Engine\\Plugin', 'deactivate']);

// Canonical runtime bootstrap.
add_action('plugins_loaded', function () {
    tmwseo_engine_run_migrations();
    \TMWSEO\Engine\Plugin::init();
});
