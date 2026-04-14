<?php
namespace TMWSEO\Engine;

use TMWSEO\Engine\Services\Settings;

if (!defined('ABSPATH')) { exit; }


/**
 * Safe file loader — prevents a single missing file from fatalling the entire wp-admin.
 *
 * On failure: logs a warning via error_log and continues. The class that was meant
 * to be loaded will simply not exist; callers that check class_exists() will skip it.
 * This is far better than a PHP fatal that blanks wp-admin.
 *
 * @param string $file Absolute path to the PHP file.
 */
function tmwseo_safe_require( string $file ): void {
    if ( file_exists( $file ) ) {
        require_once $file;
    } else {
        error_log( '[TMW SEO Engine] Missing file (non-fatal): ' . $file );
    }
}

// ── File loading ──────────────────────────────────────────────────────────────
// The Loader class groups all includes by domain. Each domain is isolated:
// a missing file in 'content/' cannot abort loading of 'keywords/'.
// See includes/class-loader.php for the full ordered manifest.
require_once TMWSEO_ENGINE_PATH . 'includes/class-loader.php';
Loader::load_all();


class Plugin {
    private static $did_init = false;


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
        // Safety layer policy: manual control is always enforced.
        return \TMWSEO\Engine\Services\TrustPolicy::is_manual_only();
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
        \TMWSEO\Engine\InternalLinks\OrphanPageDetector::unschedule();
        \TMWSEO\Engine\CompetitorMonitor\CompetitorMonitor::unschedule();
        // Note: KeywordScheduler is intentionally NOT cleared here — it only
        // updates keyword CSV data files and never writes post content, so it
        // is safe in manual-only mode. Operators can call KeywordScheduler::unschedule()
        // manually if they want to stop keyword data refreshes too.

        // Defensive: clear any lingering events by name.
        wp_clear_scheduled_hook('tmwseo_process_queue');
        wp_clear_scheduled_hook('tmwseo_worker_tick');
        wp_clear_scheduled_hook('tmwseo_daily_scan');
        wp_clear_scheduled_hook('tmwseo_daily');
        wp_clear_scheduled_hook('tmwseo_weekly');
        wp_clear_scheduled_hook('tmw_lighthouse_weekly_scan');
        wp_clear_scheduled_hook('tmwseo_materialize_intelligence');
        wp_clear_scheduled_hook('tmwseo_orphan_scan_weekly');
        wp_clear_scheduled_hook('tmwseo_competitor_monitor_weekly');
        wp_clear_scheduled_hook('tmwseo_keyword_scheduler_monthly');
        wp_clear_scheduled_hook('tmw_keyword_refresh_monthly');
        wp_clear_scheduled_hook('tmwseo_generate_traffic_pages');
        wp_clear_scheduled_hook('tmwseo_gsc_seed_import_weekly');
        wp_clear_scheduled_hook('tmwseo_engine_content_keyword_miner');
        wp_clear_scheduled_hook('tmwseo_tag_modifier_expander_weekly');

        update_option($applied_key, (string) TMWSEO_ENGINE_VERSION);
        Logs::info('core', 'Manual Control Mode applied (cron/auto hooks disabled)', [
            'version' => TMWSEO_ENGINE_VERSION,
        ]);
    }

    public static function init(): void {
        if (self::$did_init) {
            return;
        }

        self::$did_init = true;

        // ── Deferred rewrite flush (set during activate()) ─────────────────
        // Runs on 'init' at priority 99 to ensure ALL CPTs, taxonomies, and
        // rewrite rules from WordPress core, the active theme, other plugins,
        // AND this plugin have been registered before flushing.
        if ( get_option( 'tmwseo_needs_rewrite_flush' ) ) {
            add_action( 'init', [ __CLASS__, 'do_deferred_rewrite_flush' ], 99 );
        }

        $manual = self::is_manual_control_mode();

        // Safety first: ensure no scheduled tasks remain when manual mode is enabled.
        if ($manual) {
            self::apply_manual_control_mode();
        } else {
            Cron::init();
            SmartQueue::init();
        }

        // ── Always boot (manual or not) ────────────────────────────────────
        // Keyword usage tracking (anti-cannibalization) — DB-only, no automation
        \TMWSEO\Engine\Keywords\KeywordUsage::maybe_upgrade();

        // ── Staging-switchable data crons (4.4.0) ──────────────────────────
        // Each component respects its staging flag. When disabled, init() is
        // skipped so cron hooks are never registered. Classes remain loaded
        // for admin pages and manual triggers.
        if (\TMWSEO\Engine\Admin\StagingOperationsPage::is_component_enabled('keyword_scheduler')) {
            \TMWSEO\Engine\Keywords\KeywordScheduler::init();
            \TMWSEO\Engine\Keywords\KeywordClusterReconciler::init(); // admin-triggered repair only — never auto-runs
        }
        if (\TMWSEO\Engine\Admin\StagingOperationsPage::is_component_enabled('seo_autopilot')) {
            \TMWSEO\Engine\Autopilot\SEOAutopilot::init();
        }
        if (\TMWSEO\Engine\Admin\StagingOperationsPage::is_component_enabled('content_keyword_miner')) {
            \TMWSEO\Engine\Keywords\ContentKeywordMiner::init();
        }
        if (\TMWSEO\Engine\Admin\StagingOperationsPage::is_component_enabled('gsc_seed_importer')) {
            \TMWSEO\Engine\Integrations\GSCSeedImporter::init();
        }
        if (\TMWSEO\Engine\Admin\StagingOperationsPage::is_component_enabled('tag_modifier_expander')) {
            \TMWSEO\Engine\KeywordIntelligence\TagModifierExpander::init();
        }
        // Automated image ALT/title/caption on featured image assignment
        \TMWSEO\Engine\Media\ImageMetaHooks::init();
        // Cron custom schedules for keyword scheduler
        add_filter('cron_schedules', function($schedules) {
            if (!isset($schedules['tmwseo_monthly'])) {
                $schedules['tmwseo_monthly'] = ['interval' => 30 * DAY_IN_SECONDS, 'display' => 'Monthly (TMW SEO)'];
            }
            return $schedules;
        });

        // ── v4.2 boots ─────────────────────────────────────────────────────
        // Google Indexing API — pings Google on publish. Only active when safe_mode is OFF
        // and the operator has configured the service account. Non-mutating; just notifies Google.
        if (!(bool) \TMWSEO\Engine\Services\Settings::get('safe_mode', 1)) {
            \TMWSEO\Engine\Integrations\GoogleIndexingAPI::init();
        }
        // JSON-LD Schema output in <head>
        if ((bool) \TMWSEO\Engine\Services\Settings::get('schema_enabled', 1)) {
            \TMWSEO\Engine\Schema\SchemaGenerator::init();
        }
        // GSC OAuth callback handler
        if (!empty($_GET['tmwseo_gsc_callback'])) {
            add_action('admin_init', function() {
                $code  = sanitize_text_field($_GET['code'] ?? '');
                $state = sanitize_text_field($_GET['state'] ?? '');
                if ($code !== '') {
                    \TMWSEO\Engine\Integrations\GSCApi::handle_oauth_callback($code, $state);
                    wp_safe_redirect(admin_url('admin.php?page=tmwseo-settings&tmwseo_gsc_connected=1'));
                    exit;
                }
            });
        }
        // Orphan page detector init (respects staging flag)
        if ((bool) \TMWSEO\Engine\Services\Settings::get('orphan_scan_enabled', 1)
            && \TMWSEO\Engine\Admin\StagingOperationsPage::is_component_enabled('orphan_page_detector')) {
            \TMWSEO\Engine\InternalLinks\OrphanPageDetector::init();
        }
        \TMWSEO\Engine\InternalLinks\InternalLinkOpportunities::init();
        // CSV exporter
        \TMWSEO\Engine\Export\CSVExporter::init();
        // Competitor monitor (respects staging flag)
        if (\TMWSEO\Engine\Admin\StagingOperationsPage::is_component_enabled('competitor_monitor')) {
            \TMWSEO\Engine\CompetitorMonitor\CompetitorMonitor::init();
        }
        // Traffic pages generator (CPT, cron, manual action) (respects staging flag)
        if (\TMWSEO\Engine\Admin\StagingOperationsPage::is_component_enabled('traffic_page_generator')) {
            \TMWSEO\Engine\TrafficPages\TrafficPageGenerator::init();
        }
        // Content gap analysis service (weekly queue + storage sync)
        \TMWSEO\Engine\ContentGap\ContentGapService::init();
        // Admin Dashboard v2
        \TMWSEO\Engine\Admin\AdminDashboardV2::init();
        // ──────────────────────────────────────────────────────────────────

        Migration::maybe_migrate_legacy();
        Schema::ensure_intelligence_schema();
        Schema::normalize_cluster_schema_version_option();

        // Phase 1 / Phase A: analysis-only, so we do NOT auto-hook ContentEngine.
        // Legacy publish-trigger autopilot is additionally hard-fenced inside ContentEngine.
        if (!$manual) {
            Logs::info('core', '[TMW-SEO-AUTO] Manual mode policy is OFF (non-default). ContentEngine::init() called. '                . 'The publish-autopilot hook inside ContentEngine is still hard-fenced via PHASE_A_PUBLISH_AUTOPILOT_HARD_FENCE '                . 'and will NOT auto-publish content. Only shortcode registration and safety fence logging are active.');
            \TMWSEO\Engine\Content\ContentEngine::init();
        }

        // Keyword engine currently has no automatic hooks, safe to init.
        \TMWSEO\Engine\Keywords\KeywordEngine::init();
        // Auto-discover keyword seeds when a new model is published.
        \TMWSEO\Engine\KeywordIntelligence\ModelDiscoveryTrigger::init();

        // Model Discovery Engine worker (hourly crawl + model/page/category creation).
        if (\TMWSEO\Engine\Admin\StagingOperationsPage::is_component_enabled('model_discovery_worker')) {
            \TMWSEO\Engine\Model\ModelDiscoveryWorker::init();
        }

        // Platform profiles + affiliate redirects.
        \TMWSEO\Engine\Platform\PlatformProfiles::init();
        \TMWSEO\Engine\Platform\AffiliateLinkBuilder::init();

        // Internal linking on model pages (now also video pages — audit fix 4.4.0).
        \TMW_Internal_Link_Engine::init();

        // ── Audit-fix 4.4.0: readiness gates, tag quality, fingerprints ──────
        \TMWSEO\Engine\Content\IndexReadinessGate::init();

        // ── Patch 2 (4.4.1): video SEO admin metabox ────────────────────────
        \TMWSEO\Engine\Admin\VideoSeoMetabox::init();
        \TMW_Model_Similarity_Engine::init();
        \TMW_Intent_Engine::init();

        // Topic authority clusters on model pages.
        \TMW_Topic_Engine::init();

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

        add_action('admin_menu', [$cluster_admin_page, 'register_menu'], 99);

        add_filter('manage_post_posts_columns', [$cluster_admin_page, 'register_post_columns']);
        add_filter('manage_page_posts_columns', [$cluster_admin_page, 'register_post_columns']);

        add_action('manage_post_posts_custom_column', [$cluster_admin_page, 'render_post_column'], 10, 2);
        add_action('manage_page_posts_custom_column', [$cluster_admin_page, 'render_post_column'], 10, 2);

        if (is_admin()) {
            Admin::init();
            \TMWSEO\Engine\Admin\CommandCenter::init();
            \TMWSEO\Engine\Admin\SeedRegistryAdminPage::init(); // 4.3.0
            // Architecture v5.0: consolidated operator screens
            \TMWSEO\Engine\Admin\KeywordCommandCenter::init();
            \TMWSEO\Engine\Admin\ContentReviewPage::init();
            \TMWSEO\Engine\Admin\Editor_AI_Metabox::init();
            \TMWSEO\Engine\Admin\RankMathHelperPanel::init(); // 4.5.0
            \TMWSEO\Engine\Intelligence\IntelligenceAdmin::init();
            \TMWSEO\Engine\Model\ModelOptimizer::init();
            \TMWSEO\Engine\Admin\ModelHelper::init(); // 4.6.0 — Model Research enrichment workflow
            \TMWSEO\Engine\Opportunities\OpportunityUI::init();
            \TMWSEO\Engine\Opportunities\TrafficForecastUI::init();
            \TMWSEO\Engine\Suggestions\SuggestionsAdminPage::init();
            \TMWSEO\Engine\Debug\DebugDashboard::init();
            \TMWSEO\Engine\Admin\Staging_Validation_Helper::init();
            \TMWSEO\Engine\Admin\SerpAnalyzerAdminPage::init();
            \TMWSEO\Engine\Admin\LinkGraphAdminPage::init();
            \TMWSEO\Engine\Admin\TopicMapsAdminPage::init();
            \TMWSEO\Engine\Admin\CSVManagerAdminPage::init();
            \TMWSEO\Engine\Admin\AIContentBriefGeneratorAdmin::init();
            \TMWSEO\Engine\Admin\SEOEngineRunner::init();
            \TMWSEO\Engine\ContentGap\ContentGapAdmin::init();
            \TMWSEO\Engine\Expansion\KeywordExpansionEngine::init();
            \TMWSEO\Engine\Admin\StagingOperationsPage::init(); // 4.4.0
            \TMWSEO\Engine\Admin\SerpGapAdminPage::init(); // 4.6.3
            \TMWSEO\Engine\Admin\CategoryFormulaAdminPage::init(); // 5.2.0
        }

        // Runs on ALL requests (admin + front end) so [tmw_verified_links] shortcode
        // is registered on front-end page renders.  Admin-only hooks (metabox,
        // save_post, admin-post) are gated internally by WordPress — they simply
        // never fire outside the admin context.
        \TMWSEO\Engine\Model\VerifiedLinks::init(); // 4.7.0 — Verified external links + schema sameAs fix
    }

    /**
     * One-shot deferred rewrite flush.
     *
     * Hooked to 'init' at priority 99 (after all CPTs, taxonomies, and rewrite
     * rules from core, themes, other plugins, AND this plugin are registered).
     * Consumes the flag set by activate() and flushes exactly once.
     */
    public static function do_deferred_rewrite_flush(): void {
        if ( ! get_option( 'tmwseo_needs_rewrite_flush' ) ) {
            return;
        }

        delete_option( 'tmwseo_needs_rewrite_flush' );
        flush_rewrite_rules();

        Logs::info( 'core', '[TMW-SEO] Deferred rewrite flush completed after activation' );
    }

    public static function activate(): void {
        if (function_exists('tmwseo_engine_run_migrations')) {
            tmwseo_engine_run_migrations();
        }

        Schema::create_or_update_tables();
        Schema::ensure_intelligence_schema();
        Schema::normalize_cluster_schema_version_option();
        \TMWSEO\Engine\KeywordIntelligence\KeywordDatabase::create_table();

        // ── Keyword usage tables (anti-cannibalization) ────────────────────
        \TMWSEO\Engine\Keywords\KeywordUsage::install();

        // ── SERP Keyword Gaps table (4.6.3) ────────────────────────────────
        if (class_exists('TMWSEO\\Engine\\SerpGaps\\SerpGapStorage')) {
            \TMWSEO\Engine\SerpGaps\SerpGapStorage::maybe_create_table();
        }

        // Phase 1 default: manual-only => do NOT schedule content-writing cron.
        if (!self::is_manual_control_mode()) {
            Cron::schedule_events();
            SmartQueue::schedule_daily_scan();
        } else {
            // Ensure no old scheduled tasks exist.
            Cron::unschedule_events();
            SmartQueue::unschedule_daily_scan();
        }

        // ── Keyword data maintenance crons (safe in manual mode) ───────────
        // These only update keyword CSV data files, never write post content.
        \TMWSEO\Engine\Keywords\KeywordScheduler::schedule();
        \TMWSEO\Engine\Keywords\ContentKeywordMiner::schedule();
        \TMWSEO\Engine\Integrations\GSCSeedImporter::schedule();
        \TMWSEO\Engine\KeywordIntelligence\TagModifierExpander::schedule();
        \TMWSEO\Engine\TrafficPages\TrafficPageGenerator::activate();
        \TMWSEO\Engine\Model\ModelDiscoveryWorker::schedule();

        // ── v4.2 crons — only schedule if NOT in manual mode ───────────────
        // These are read-only scans, but we respect the manual-only trust policy.
        // Operators can trigger scans manually from the Tools page.
        if (!self::is_manual_control_mode()) {
            \TMWSEO\Engine\InternalLinks\OrphanPageDetector::schedule();
            \TMWSEO\Engine\CompetitorMonitor\CompetitorMonitor::schedule();
        }

        Migration::maybe_migrate_legacy(true);

        // Schedule a deferred rewrite flush instead of flushing now.
        // During activation, the 'init' hook has not yet fired, so WordPress core,
        // the active theme, and other plugins have not registered their CPTs,
        // taxonomies, or rewrite rules yet. Flushing here produces an incomplete
        // rule set that causes front-end 404s on pages, posts, and category archives.
        //
        // The flag is consumed on the next full page load (admin or front-end) by
        // the 'init' handler registered below, at which point ALL rewrite providers
        // are loaded and the flush captures the complete rule set.
        update_option( 'tmwseo_needs_rewrite_flush', 1, true );

        Logs::info('core', 'Activated ' . TMWSEO_ENGINE_VERSION);
    }

    public static function deactivate(): void {
        Cron::unschedule_events();
        SmartQueue::unschedule_daily_scan();
        \TMWSEO\Engine\Keywords\KeywordScheduler::unschedule();
        \TMWSEO\Engine\Keywords\ContentKeywordMiner::unschedule();
        \TMWSEO\Engine\Integrations\GSCSeedImporter::unschedule();
        \TMWSEO\Engine\KeywordIntelligence\TagModifierExpander::unschedule();
        \TMWSEO\Engine\InternalLinks\OrphanPageDetector::unschedule();
        \TMWSEO\Engine\CompetitorMonitor\CompetitorMonitor::unschedule();
        \TMWSEO\Engine\TrafficPages\TrafficPageGenerator::deactivate();
        \TMWSEO\Engine\Model\ModelDiscoveryWorker::unschedule();
        flush_rewrite_rules();
        Logs::info('core', 'Deactivated ' . TMWSEO_ENGINE_VERSION);
    }
}
