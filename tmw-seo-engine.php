<?php
/**
 * Plugin Name: TMW SEO Engine
 * Description: Intelligence Core v5.8.7-simple-model-research-evidence — Drastic simplification of the External Profile Evidence flow. (1) AWE / AWEmpire connector deleted entirely (classes, AJAX handlers, settings UI, defaults, source-type allowlist entries) — was unused for bio data; wps-livejasmin handles video metadata. (2) Old External Profile Evidence subsystem deleted (source URL, raw/transformed split, review states, AJAX Generate Suggestions flow, readiness banner, ~250-line model-editor panel). (3) New simple flow: 3 textareas inside the existing Model Research / Editor Seed area — "External Bio Evidence", "External Turn Ons Evidence", "External Private Chat Evidence". Saved with normal post update. (4) New ModelResearchEvidence helper humanizes the 3 fields at generation time and prepends "About {Model}" / "Turn Ons" / "Private Chat Options" sections above the existing generated body using <!-- tmwseo-seed-evidence:start --> wrapper markers (idempotent — re-generation never duplicates). Legacy v5.8.6 markers and heading-trio also stripped. (5) Bio uses curated noun-phrase map ("a warm room presence", "lingerie looks", "fashion-inspired posing") — no bare adjective dumps. (6) Private Chat denylist removes anal/anal sex/deepthroat/double penetration/squirt/cum/etc.; canonicaliser normalises strap-on/love-beads; JOI/POV/ASMR/C2C preserved uppercase; capped at 14 items. (7) Renderer-payload bridge (reviewed_bio_section_paragraphs etc.) and build_external_evidence_payload removed — single insertion point at all 7 model-content save sites (Template + 6 OpenAI/Claude paths). (8) Empty fields cleanly produce no section; un-pasting and re-generating cleanly removes the block.
 * Version: 5.8.7-simple-model-research-evidence
 * Author: The Milisofia Ltd
 * Text Domain: tmwseo
 */

if (!defined('ABSPATH')) { exit; }

if (defined('TMWSEO_ENGINE_BOOTSTRAPPED')) {
    return;
}

define('TMWSEO_ENGINE_BOOTSTRAPPED', true);
defined('TMWSEO_ENGINE_VERSION') || define('TMWSEO_ENGINE_VERSION', '5.8.7-simple-model-research-evidence');
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
