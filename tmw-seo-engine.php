<?php
/**
 * Plugin Name: TMW SEO Engine
 * Description: Intelligence Core v5.8.11-final-copy — Final deterministic copy pipeline fix. Builds on v5.8.10. No architecture changes, no routing changes, no link changes. (1) build_sparse_model_payload: secondary-keyword tails removed from intro and features intro; Features section rebuilt as practical prose paragraphs surfacing secondary phrases [0]/[1]/[3]; FAQ first-link answers shortened to concise semicolon form; single-platform comparison line shortened. (2) render_varied_features: static pool trimmed to 4 practical-checks bullets; meta-commentary "Platform notes here..." bullet removed. (3) build_platform_comparison single-CTA branch: redundant intro paragraph dropped; checklist trimmed to 2 non-overlapping bullets; CTA href/rel/target untouched. (4) build_verification_process_paragraph: returns empty string when verified_count > 0 (avoids duplicate status sentences); "latest grouped link check" wording eliminated. (5) official_links_section_paragraphs rebuilt with three independent filtered entries. (6) enforce_keyword_heading_placement: name-bearing keyword guard added (mirrors select_heading_safe_secondary_keyword_phrases); section-context guard restricts H3 injection to Features and FAQ only; six routing/link sections are now disallowed injection targets. (7) ensure_minimum_useful_depth: "How to Decide Where to Start" gated on count(active_platforms) >= 2. (8) ModelCopyCleanup dedupe_latest_check_sentences family extended with "latest grouped link check", "latest automated review", "grouped link check", "latest review". All links, routes, affiliate URLs, verified-link grouping, Rank Math coverage, extra-keyword pipeline, and CrakRevenue routing are untouched.
 * Version: 5.8.14-video-seo-fix
 * Author: The Milisofia Ltd
 * Text Domain: tmwseo
 */

if (!defined('ABSPATH')) { exit; }

if (defined('TMWSEO_ENGINE_BOOTSTRAPPED')) {
    return;
}

define('TMWSEO_ENGINE_BOOTSTRAPPED', true);
defined('TMWSEO_ENGINE_VERSION') || define('TMWSEO_ENGINE_VERSION', '5.8.14-video-seo-fix');
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

        // SERP Keyword Gaps schema (4.6.3)
        $serp_gap_migration_file = TMWSEO_ENGINE_PATH . 'includes/migrations/class-serp-gap-migration.php';
        if (!class_exists('TMW_Serp_Gap_Migration', false) && file_exists($serp_gap_migration_file)) {
            require_once $serp_gap_migration_file;
        }

        if (class_exists('TMW_Serp_Gap_Migration', false) && method_exists('TMW_Serp_Gap_Migration', 'maybe_migrate')) {
            TMW_Serp_Gap_Migration::maybe_migrate();
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
register_activation_hook(__FILE__, function () {
    // Flush template transients on (re-)activation so new template files take effect immediately.
    if (class_exists('TMWSEO\\Engine\\Templates\\TemplateEngine')) {
        \TMWSEO\Engine\Templates\TemplateEngine::flush_cache();
    }
});
register_deactivation_hook(__FILE__, ['TMWSEO\\Engine\\Plugin', 'deactivate']);

// Canonical runtime bootstrap.
add_action('plugins_loaded', function () {
    tmwseo_engine_run_migrations();
    \TMWSEO\Engine\Plugin::init();

    // Flush template cache when plugin version changes (handles manual file-drop upgrades).
    $version_option = 'tmwseo_engine_tpl_flushed_version';
    if ((string) get_option($version_option, '') !== TMWSEO_ENGINE_VERSION) {
        if (class_exists('TMWSEO\\Engine\\Templates\\TemplateEngine')) {
            \TMWSEO\Engine\Templates\TemplateEngine::flush_cache();
        }
        update_option($version_option, TMWSEO_ENGINE_VERSION, false);
    }
});
