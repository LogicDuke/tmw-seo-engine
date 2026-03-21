<?php
/**
 * Plugin Name: TMW SEO Engine
 * Description: Intelligence Core v4.6.2 — Security & stability patch: atomic API budget tracking (DataForSEO + AI Router), GSC token encryption, ModelDiscovery draft-only publishing, bulk KD batching, DiscoveryGovernor race fix, Expansion candidate pruning, Bing/Reddit caching, POST-before-output fix, dead WorkerCron removed.
 * Version: 4.6.2
 * Author: The Milisofia Ltd
 * Text Domain: tmwseo
 */

if (!defined('ABSPATH')) { exit; }

if (defined('TMWSEO_ENGINE_BOOTSTRAPPED')) {
    return;
}

define('TMWSEO_ENGINE_BOOTSTRAPPED', true);
defined('TMWSEO_ENGINE_VERSION') || define('TMWSEO_ENGINE_VERSION', '4.6.2');
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

        $seed_roi_migration_file = TMWSEO_ENGINE_PATH . 'includes/migrations/class-seed-roi-migration.php';
        if (!class_exists('TMW_Seed_ROI_Migration', false) && file_exists($seed_roi_migration_file)) {
            require_once $seed_roi_migration_file;
        }

        if (class_exists('TMW_Seed_ROI_Migration', false) && method_exists('TMW_Seed_ROI_Migration', 'maybe_migrate')) {
            TMW_Seed_ROI_Migration::maybe_migrate();
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

if (!function_exists('tmw_seo_classify_keyword_intent')) {
    /**
     * Lightweight keyword intent classifier for discovery and clustering.
     *
     * Returns one of: model, category, interaction, generic.
     */
    function tmw_seo_classify_keyword_intent($keyword): string {
        $keyword = strtolower(trim((string) wp_strip_all_tags((string) $keyword)));
        if ($keyword === '') {
            return 'generic';
        }

        $interaction_rules = [
            '/\b(private|pvt|exclusive|one\s*on\s*one|1\s*on\s*1)\b/u',
            '/\b(cam\s*to\s*cam|c2c|video\s*chat|chat\s*show|cam\s*show|live\s*show)\b/u',
            '/\b(sext|roleplay|custom\s*show|ticket\s*show)\b/u',
        ];

        foreach ($interaction_rules as $rule) {
            if (preg_match($rule, $keyword)) {
                return 'interaction';
            }
        }

        $category_rules = [
            '/\b(cam\s*girl|cam\s*girls|webcam\s*girls?|models?)\b/u',
            '/\b(tattoo|bbw|milf|teen|asian|latina|blonde|brunette|ebony|fetish|cosplay|couple|redhead|petite|curvy)\b/u',
            '/\b(best|top)\s+.+\b(cam|webcam|model|models|girls)\b/u',
        ];

        foreach ($category_rules as $rule) {
            if (preg_match($rule, $keyword)) {
                return 'category';
            }
        }

        if (preg_match('/\b[a-z]{3,}\s+[a-z]{3,}\s+(webcam|cam|model|live)\b/u', $keyword)) {
            return 'model';
        }

        return 'generic';
    }
}

if (!function_exists('tmw_get_csv_directory')) {
    /**
     * Returns the absolute path used for temporary CSV storage.
     */
    function tmw_get_csv_directory(): string {
        $upload_dir = wp_upload_dir();
        $csv_dir = trailingslashit((string) ($upload_dir['basedir'] ?? WP_CONTENT_DIR)) . 'tmw-seo-imports';

        if (!file_exists($csv_dir)) {
            wp_mkdir_p($csv_dir);
        }

        if (is_dir($csv_dir)) {
            @chmod($csv_dir, 0755);

            $htaccess_path = trailingslashit($csv_dir) . '.htaccess';
            if (!file_exists($htaccess_path)) {
                @file_put_contents($htaccess_path, "deny from all\n");
            }
        }

        return $csv_dir;
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
