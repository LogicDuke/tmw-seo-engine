<?php
namespace TMWSEO\Engine;

use TMWSEO\Engine\Services\Settings;

if (!defined('ABSPATH')) { exit; }

require_once TMWSEO_ENGINE_PATH . 'includes/db/class-schema.php';
require_once TMWSEO_ENGINE_PATH . 'includes/db/class-logs.php';
require_once TMWSEO_ENGINE_PATH . 'includes/db/class-jobs.php';
require_once TMWSEO_ENGINE_PATH . 'includes/cron/class-cron.php';
require_once TMWSEO_ENGINE_PATH . 'includes/engine/class-smart-queue.php';
require_once TMWSEO_ENGINE_PATH . 'includes/worker/class-worker.php';
require_once TMWSEO_ENGINE_PATH . 'includes/admin/class-admin.php';
require_once TMWSEO_ENGINE_PATH . 'includes/admin/class-editor-ai-metabox.php';
require_once TMWSEO_ENGINE_PATH . 'includes/migration/class-migration.php';

require_once TMWSEO_ENGINE_PATH . 'includes/services/class-settings.php';
require_once TMWSEO_ENGINE_PATH . 'includes/services/class-title-fixer.php';
require_once TMWSEO_ENGINE_PATH . 'includes/services/class-openai.php';
require_once TMWSEO_ENGINE_PATH . 'includes/services/class-dataforseo.php';
require_once TMWSEO_ENGINE_PATH . 'includes/services/class-pagespeed.php';
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-keyword-validator.php';
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-kd-filter.php';
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-keyword-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-keyword-library.php';
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-model-keyword-pack.php';

require_once TMWSEO_ENGINE_PATH . 'includes/templates/class-template-engine.php';

require_once TMWSEO_ENGINE_PATH . 'includes/content/class-content-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/content/class-template-content.php';
require_once TMWSEO_ENGINE_PATH . 'includes/platform/class-platform-registry.php';
require_once TMWSEO_ENGINE_PATH . 'includes/platform/class-platform-profiles.php';
require_once TMWSEO_ENGINE_PATH . 'includes/platform/class-affiliate-link-builder.php';
require_once TMWSEO_ENGINE_PATH . 'includes/model/class-model-optimizer.php';

require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-expander.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-filter.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-intent.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-scorer.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-pack-builder.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-intelligence.php';

// Cluster & Lighthouse modules (manual triggers only in Phase 1).
require_once TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-repository.php';
require_once TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-service.php';
require_once TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-linking-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-scoring-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-advisor.php';
require_once TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-link-injector.php';
require_once TMWSEO_ENGINE_PATH . 'includes/admin/class-cluster-admin-page.php';
require_once TMWSEO_ENGINE_PATH . 'includes/integrations/class-gsc-cluster-importer.php';
require_once TMWSEO_ENGINE_PATH . 'includes/compat/class-tmw-main-class.php';
require_once TMWSEO_ENGINE_PATH . 'includes/lighthouse/class-lh-schema.php';
require_once TMWSEO_ENGINE_PATH . 'includes/lighthouse/class-lh-targets.php';
require_once TMWSEO_ENGINE_PATH . 'includes/lighthouse/class-lh-collector-psi.php';
require_once TMWSEO_ENGINE_PATH . 'includes/lighthouse/class-lh-normalizer.php';
require_once TMWSEO_ENGINE_PATH . 'includes/lighthouse/class-lh-worker.php';
require_once TMWSEO_ENGINE_PATH . 'includes/lighthouse/class-lh-advisor.php';
require_once TMWSEO_ENGINE_PATH . 'includes/lighthouse/class-lh-dashboard.php';
require_once TMWSEO_ENGINE_PATH . 'includes/lighthouse/class-lh-bootstrap.php';

// Intelligence Core (Phase 1)
require_once TMWSEO_ENGINE_PATH . 'includes/intelligence/class-intelligence-runner.php';
require_once TMWSEO_ENGINE_PATH . 'includes/intelligence/class-intelligence-admin.php';

class Plugin {

    private static $instance;
    private static $cluster_service;
    private static $cluster_linking_engine;
    private static $cluster_scoring_engine;
    private static $cluster_advisor;
    private static $cluster_link_injector;
    private static $gsc_cluster_importer;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function get_cluster_service() {
        return self::$cluster_service ?? null;
    }

    public static function get_cluster_linking_engine() {
        return self::$cluster_linking_engine ?? null;
    }

    public static function get_cluster_scoring_engine() {
        return self::$cluster_scoring_engine ?? null;
    }

    public static function get_cluster_advisor() {
        return self::$cluster_advisor ?? null;
    }

    public static function get_cluster_link_injector() {
        return self::$cluster_link_injector ?? null;
    }

    public static function get_gsc_cluster_importer() {
        return self::$gsc_cluster_importer ?? null;
    }

    public static function clear_cluster_cache($cluster_id) {
        $cluster_id = (int) $cluster_id;
        if ($cluster_id <= 0) {
            return;
        }

        delete_transient('tmw_cluster_analysis_' . $cluster_id);
        delete_transient('tmw_cluster_score_' . $cluster_id);
    }

    /**
     * Phase 1 policy: manual-only.
     * - No cron scheduling.
     * - No automatic post optimization.
     */
    private static function is_manual_control_mode(): bool {
        return (bool) Settings::get('manual_control_mode', 1);
    }

    /**
     * If the site used an older build that scheduled cron jobs,
     * we actively remove them when Manual Control Mode is enabled.
     */
    private static function apply_manual_control_mode(): void {
        $applied_key = 'tmwseo_manual_control_applied_version';
        $already = (string) get_option($applied_key, '');
        if ($already === (string) TMWSEO_ENGINE_VERSION) {
            return;
        }

        // Kill all known scheduled hooks.
        Cron::unschedule_events();
        SmartQueue::unschedule_daily_scan();

        // Defensive: clear any lingering events by name.
        wp_clear_scheduled_hook('tmwseo_process_queue');
        wp_clear_scheduled_hook('tmwseo_daily_scan');
        wp_clear_scheduled_hook('tmwseo_daily');
        wp_clear_scheduled_hook('tmwseo_weekly');
        wp_clear_scheduled_hook('tmw_lighthouse_weekly_scan');

        update_option($applied_key, (string) TMWSEO_ENGINE_VERSION);
        Logs::info('core', 'Manual Control Mode applied (cron/auto hooks disabled)', [
            'version' => TMWSEO_ENGINE_VERSION,
        ]);
    }

    public static function init(): void {
        $manual = self::is_manual_control_mode();

        // Safety first: ensure no scheduled tasks remain when manual mode is enabled.
        if ($manual) {
            self::apply_manual_control_mode();
        } else {
            Cron::init();
            SmartQueue::init();
        }

        Migration::maybe_migrate_legacy();

        // Phase 1: analysis-only, so we do NOT auto-hook ContentEngine.
        if (!$manual) {
            \TMWSEO\Engine\Content\ContentEngine::init();
        }

        // Keyword engine currently has no automatic hooks, safe to init.
        \TMWSEO\Engine\Keywords\KeywordEngine::init();

        // Platform profiles + affiliate redirects.
        \TMWSEO\Engine\Platform\PlatformProfiles::init();
        \TMWSEO\Engine\Platform\AffiliateLinkBuilder::init();

        // Lighthouse menus + manual actions.
        \TMW\SEO\Lighthouse\Bootstrap::init();

        // Cluster engine (admin-only actions unless you click buttons).
        global $wpdb;
        $cluster_repository = new \TMW_Cluster_Repository($wpdb);
        $cluster_service = new \TMW_Cluster_Service($cluster_repository);
        self::$cluster_service = $cluster_service;

        $gsc_cluster_importer = new \TMW_GSC_Cluster_Importer(self::$cluster_service);
        self::$gsc_cluster_importer = $gsc_cluster_importer;

        $cluster_linking_engine = new \TMW_Cluster_Linking_Engine(self::$cluster_service);
        self::$cluster_linking_engine = $cluster_linking_engine;

        $cluster_scoring_engine = new \TMW_Cluster_Scoring_Engine(self::$cluster_service, self::$cluster_linking_engine);
        self::$cluster_scoring_engine = $cluster_scoring_engine;

        $cluster_advisor = new \TMW_Cluster_Advisor(self::$cluster_service, self::$cluster_linking_engine, self::$cluster_scoring_engine);
        self::$cluster_advisor = $cluster_advisor;

        $cluster_link_injector = new \TMW_Cluster_Link_Injector(self::$cluster_service, self::$cluster_linking_engine);
        self::$cluster_link_injector = $cluster_link_injector;

        $cluster_admin_page = new \TMW_Cluster_Admin_Page(self::$cluster_service, self::$cluster_scoring_engine);

        add_action('admin_menu', [$cluster_admin_page, 'register_menu']);

        add_filter('manage_post_posts_columns', [$cluster_admin_page, 'register_post_columns']);
        add_filter('manage_page_posts_columns', [$cluster_admin_page, 'register_post_columns']);

        add_action('manage_post_posts_custom_column', [$cluster_admin_page, 'render_post_column'], 10, 2);
        add_action('manage_page_posts_custom_column', [$cluster_admin_page, 'render_post_column'], 10, 2);

        if (is_admin()) {
            Admin::init();
            \TMWSEO\Engine\Admin\Editor_AI_Metabox::init();
            \TMWSEO\Engine\Intelligence\IntelligenceAdmin::init();
            \TMWSEO\Engine\Model\ModelOptimizer::init();
        }
    }

    public static function activate(): void {
        Schema::create_or_update_tables();

        // Phase 1 default: manual-only => do NOT schedule cron.
        if (!self::is_manual_control_mode()) {
            Cron::schedule_events();
            SmartQueue::schedule_daily_scan();
        } else {
            // Ensure no old scheduled tasks exist.
            Cron::unschedule_events();
            SmartQueue::unschedule_daily_scan();
        }

        Migration::maybe_migrate_legacy(true);
        \TMWSEO\Engine\Platform\AffiliateLinkBuilder::register_rewrite_rule();
        flush_rewrite_rules();

        Logs::info('core', 'Activated ' . TMWSEO_ENGINE_VERSION);
    }

    public static function deactivate(): void {
        Cron::unschedule_events();
        SmartQueue::unschedule_daily_scan();
        flush_rewrite_rules();
        Logs::info('core', 'Deactivated ' . TMWSEO_ENGINE_VERSION);
    }
}
