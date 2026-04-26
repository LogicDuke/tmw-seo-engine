<?php
/**
 * Plugin Name: TMW SEO Engine
 * Description: Intelligence Core v5.8.8-model-copy-cleanup — Deterministic final-pass cleanup of generated model-page copy. Builds on v5.8.7-simple-model-research-evidence (3-textarea evidence flow + ModelResearchEvidence helper). NEW in v5.8.8: (A) Evidence transformer wording fixes — bio no longer produces "style built around ... style" (glamour mapping changed to "polished glamour presentation", subject template changed to "{Name}'s cam style is built around ..."); turn-ons no longer produces "Highlighted turn-on themes include ... as core turn-on themes" (trailing tag dropped, openers self-sufficient). (B) New ModelCopyCleanup class wired into all 7 model-content save sites (6 in ContentEngine + 1 in TemplateContent), running immediately after ModelResearchEvidence::prepend_sections(). (C) Heading rewrites: "Features and Platform Experience for {Model} and how to join a live session" → "Features and Platform Experience"; "Feature check for how to watch live webcam shows" → "Live Show Feature Check"; "Before You Click and LiveJasmin live show schedule" / "Before you click: HD live stream experience" → "Before You Click"; "Where Are the Official Links and Other Profiles?" → "Official Links and Profiles". (D) Review-pass language family ("review pass" / "latest review pass" / "operator review" / "latest operator review") capped at 2 combined occurrences; remaining occurrences rewritten to "check" / "latest check" / "manual check"; targeted rewrite of "Where shown as non-active, the latest operator review marked that destination..." → "Where shown as non-active, that destination is not currently treated as a live-room entry." (E) Duplicate paragraph cleanup — exact-match dedup plus first-60-character near-duplicate dedup, only on standalone <p> blocks containing no <a>/<ul>/<ol>/<li>/<table>/<h*> tags so verified link rendering and structural HTML are never touched. (F) Link-section overlap reduction handled by the same paragraph-dedup pass (all sections share the same dedup space, so explanatory paragraphs duplicated across "Where to Watch Live", "Other Official Destinations", "Social Profiles, Link Hubs, and Channels", "Official Links and Profiles", "Find {Model} elsewhere" are eliminated; real links and lists are never removed). (G) Adjacent same-level headings sharing a 2+ word prefix get the prefix stripped from the second heading. (H) Evidence block split out before cleanup and restored verbatim afterwards (cleanup never touches anything between <!-- tmwseo-seed-evidence:start -->...<!-- tmwseo-seed-evidence:end -->). (I) Idempotent — running cleanup twice produces the same output as running it once. New tests/run-model-copy-cleanup.php harness covers all rules.
 * Version: 5.8.8-model-copy-cleanup
 * Author: The Milisofia Ltd
 * Text Domain: tmwseo
 */

if (!defined('ABSPATH')) { exit; }

if (defined('TMWSEO_ENGINE_BOOTSTRAPPED')) {
    return;
}

define('TMWSEO_ENGINE_BOOTSTRAPPED', true);
defined('TMWSEO_ENGINE_VERSION') || define('TMWSEO_ENGINE_VERSION', '5.8.8-model-copy-cleanup');
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
