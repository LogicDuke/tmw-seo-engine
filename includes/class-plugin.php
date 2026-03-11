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
require_once TMWSEO_ENGINE_PATH . 'includes/admin/class-admin-ui.php';
require_once TMWSEO_ENGINE_PATH . 'includes/admin/class-admin.php';
require_once TMWSEO_ENGINE_PATH . 'includes/admin/class-command-center.php';
require_once TMWSEO_ENGINE_PATH . 'includes/admin/class-editor-ai-metabox.php';
require_once TMWSEO_ENGINE_PATH . 'includes/admin/class-staging-validation-helper.php';
require_once TMWSEO_ENGINE_PATH . 'includes/migration/class-migration.php';
require_once TMWSEO_ENGINE_PATH . 'includes/migration/class-autopilot-migration-registry.php';

require_once TMWSEO_ENGINE_PATH . 'includes/services/class-settings.php';

// ── Autopilot integration: new classes ────────────────────────────────────────
// Keyword usage deduplication (anti-cannibalization)
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-keyword-usage.php';
// Curated keyword library (30 niche CSV categories)
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-curated-keyword-library.php';
// Background keyword data maintenance crons (data-only, respects manual mode)
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-keyword-scheduler.php';
// Rollback (snapshot + restore pre-generation state)
require_once TMWSEO_ENGINE_PATH . 'includes/model/class-rollback.php';
// Content uniqueness / similarity checker
require_once TMWSEO_ENGINE_PATH . 'includes/content/class-uniqueness-checker.php';
// Automated image ALT / title / caption / description
require_once TMWSEO_ENGINE_PATH . 'includes/media/class-image-meta-generator.php';
require_once TMWSEO_ENGINE_PATH . 'includes/media/class-image-meta-hooks.php';
// WP-CLI commands
if (defined('WP_CLI') && WP_CLI) {
    require_once TMWSEO_ENGINE_PATH . 'includes/cli/class-cli.php';
}
// ─────────────────────────────────────────────────────────────────────────────

// ── v4.2: New integrations, AI, Schema, Export, Competitor Monitor ────────────
// AI Router (OpenAI primary + Anthropic Claude fallback + token/budget tracking)
require_once TMWSEO_ENGINE_PATH . 'includes/ai/class-ai-router.php';
// Real Google Search Console API (replaces fake rand() data)
require_once TMWSEO_ENGINE_PATH . 'includes/integrations/class-gsc-api.php';
require_once TMWSEO_ENGINE_PATH . 'includes/integrations/class-gsc-seed-importer.php';
// Google Indexing API (pings Google on publish)
require_once TMWSEO_ENGINE_PATH . 'includes/integrations/class-google-indexing-api.php';
// Ranking Probability Orchestrator (assembles full ranking signal set)
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/intelligence/class-ranking-probability-orchestrator.php';
// JSON-LD Schema Generator (Person, VideoObject, FAQPage)
require_once TMWSEO_ENGINE_PATH . 'includes/schema/class-schema-generator.php';
// Orphan Page Detector (zero inbound internal links)
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/internal-links/class-orphan-page-detector.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/internal-links/class-internal-link-opportunities.php';
// CSV Exporter
require_once TMWSEO_ENGINE_PATH . 'includes/export/class-csv-exporter.php';
// Competitor Monitor (weekly domain authority + keyword threat detection)
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/competitor-monitor/class-competitor-monitor.php';
// Admin Dashboard v2
require_once TMWSEO_ENGINE_PATH . 'includes/admin/class-admin-dashboard-v2.php';
// ─────────────────────────────────────────────────────────────────────────────
require_once TMWSEO_ENGINE_PATH . 'includes/services/class-trust-policy.php';
require_once TMWSEO_ENGINE_PATH . 'includes/services/class-title-fixer.php';
require_once TMWSEO_ENGINE_PATH . 'includes/services/class-openai.php';
require_once TMWSEO_ENGINE_PATH . 'includes/services/class-dataforseo.php';
require_once TMWSEO_ENGINE_PATH . 'includes/services/class-pagespeed.php';
require_once TMWSEO_ENGINE_PATH . 'includes/services/class-rank-tracker.php';
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-keyword-validator.php';
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-kd-filter.php';
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-query-expansion-graph.php';
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-keyword-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-seed-registry.php';
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-content-keyword-miner.php';
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-discovery-orchestrator.php';
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-unified-keyword-workflow-service.php';
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-keyword-library.php';
require_once TMWSEO_ENGINE_PATH . 'includes/keywords/class-model-keyword-pack.php';

require_once TMWSEO_ENGINE_PATH . 'includes/templates/class-template-engine.php';

require_once TMWSEO_ENGINE_PATH . 'includes/content/class-content-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/content/class-assisted-draft-enrichment-service.php';
require_once TMWSEO_ENGINE_PATH . 'includes/content/class-quality-score-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/content/class-template-content.php';
require_once TMWSEO_ENGINE_PATH . 'includes/platform/class-platform-registry.php';
require_once TMWSEO_ENGINE_PATH . 'includes/platform/class-platform-profiles.php';
require_once TMWSEO_ENGINE_PATH . 'includes/platform/class-affiliate-link-builder.php';
require_once TMWSEO_ENGINE_PATH . 'includes/model/class-model-optimizer.php';
require_once TMWSEO_ENGINE_PATH . 'includes/model/class-model-intelligence.php';

require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-expander.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-filter.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-intent.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-classifier.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-model-discovery-trigger.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-scorer.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-pack-builder.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-intelligence.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-database.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-entity-combination-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-tag-modifier-expander.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/clustering/class-keyword-normalizer.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/clustering/class-cluster-builder.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/clustering/class-cluster-engine.php';

require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/internal-links/class-link-graph.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/internal-links/class-related-models.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/internal-links/class-link-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/internal-links/class-link-opportunity-scanner.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/model-similarity/class-similarity-database.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/model-similarity/class-model-similarity-calculator.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/model-similarity/class-model-cluster-builder.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/model-similarity/class-model-similarity-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/search-intent/class-intent-template.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/search-intent/class-intent-analyzer.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/search-intent/class-intent-section-builder.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/search-intent/class-intent-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/topic-authority/class-topic-map.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/topic-authority/class-topic-page-generator.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/topic-authority/class-topic-engine.php';


require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/opportunities/class-opportunity-database.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/opportunities/class-opportunity-scorer.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/opportunities/class-keyword-gap.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/opportunities/class-opportunity-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/opportunities/class-opportunity-ui.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/opportunities/class-traffic-feedback-discovery.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/traffic-pages/class-traffic-page-generator.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/suggestions/class-suggestion-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/suggestions/class-content-suggestion-module.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/suggestions/class-content-improvement-analyzer.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/intelligence/class-intelligence-storage.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/intelligence/class-intelligence-materializer.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/intelligence/class-topical-authority-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/intelligence/class-serp-weakness-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/intelligence/class-ranking-probability-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/intelligence/class-competitor-gap-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/intelligence/class-content-brief-generator.php';
require_once TMWSEO_ENGINE_PATH . 'includes/admin/class-suggestions-admin-page.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/debug/class-debug-logger.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/debug/class-debug-panels.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/debug/class-debug-api-monitor.php';
require_once TMWSEO_ENGINE_PATH . 'includes/seo-engine/debug/class-debug-dashboard.php';

// Cluster & Lighthouse modules (manual triggers only in Phase 1).
require_once TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-repository.php';
require_once TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-service.php';
require_once TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-linking-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-scoring-engine.php';
require_once TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-advisor.php';
require_once TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-link-injector.php';
require_once TMWSEO_ENGINE_PATH . 'includes/admin/class-cluster-admin-page.php';
require_once TMWSEO_ENGINE_PATH . 'includes/admin/class-keyword-graph-admin-page.php';
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
        // Keyword data crons (update CSV files only, no content writing)
        \TMWSEO\Engine\Keywords\KeywordScheduler::init();
        \TMWSEO\Engine\Keywords\ContentKeywordMiner::init();
        \TMWSEO\Engine\Integrations\GSCSeedImporter::init();
        \TMWSEO\Engine\KeywordIntelligence\TagModifierExpander::init();
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
        // Orphan page detector init
        if ((bool) \TMWSEO\Engine\Services\Settings::get('orphan_scan_enabled', 1)) {
            \TMWSEO\Engine\InternalLinks\OrphanPageDetector::init();
        }
        \TMWSEO\Engine\InternalLinks\InternalLinkOpportunities::init();
        // CSV exporter
        \TMWSEO\Engine\Export\CSVExporter::init();
        // Competitor monitor
        \TMWSEO\Engine\CompetitorMonitor\CompetitorMonitor::init();
        // Traffic pages generator (CPT, cron, manual action)
        \TMWSEO\Engine\TrafficPages\TrafficPageGenerator::init();
        // Admin Dashboard v2
        \TMWSEO\Engine\Admin\AdminDashboardV2::init();
        // ──────────────────────────────────────────────────────────────────

        Migration::maybe_migrate_legacy();
        Schema::ensure_intelligence_schema();
        Schema::normalize_cluster_schema_version_option();

        // Phase 1 / Phase A: analysis-only, so we do NOT auto-hook ContentEngine.
        // Legacy publish-trigger autopilot is additionally hard-fenced inside ContentEngine.
        if (!$manual) {
            Logs::warn('core', '[TMW-SEO-AUTO] Manual mode disabled by policy override; ContentEngine init remains Phase A fenced');
            \TMWSEO\Engine\Content\ContentEngine::init();
        }

        // Keyword engine currently has no automatic hooks, safe to init.
        \TMWSEO\Engine\Keywords\KeywordEngine::init();
        // Auto-discover keyword seeds when a new model is published.
        \TMWSEO\Engine\KeywordIntelligence\ModelDiscoveryTrigger::init();

        // Platform profiles + affiliate redirects.
        \TMWSEO\Engine\Platform\PlatformProfiles::init();
        \TMWSEO\Engine\Platform\AffiliateLinkBuilder::init();

        // Internal linking on model pages.
        \TMW_Internal_Link_Engine::init();
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
            \TMWSEO\Engine\Admin\Editor_AI_Metabox::init();
            \TMWSEO\Engine\Intelligence\IntelligenceAdmin::init();
            \TMWSEO\Engine\Model\ModelOptimizer::init();
            \TMWSEO\Engine\Opportunities\OpportunityUI::init();
            \TMWSEO\Engine\Suggestions\SuggestionsAdminPage::init();
            \TMWSEO\Engine\Debug\DebugDashboard::init();
            \TMWSEO\Engine\Admin\Staging_Validation_Helper::init();
        }
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

        // ── v4.2 crons — only schedule if NOT in manual mode ───────────────
        // These are read-only scans, but we respect the manual-only trust policy.
        // Operators can trigger scans manually from the Tools page.
        if (!self::is_manual_control_mode()) {
            \TMWSEO\Engine\InternalLinks\OrphanPageDetector::schedule();
            \TMWSEO\Engine\CompetitorMonitor\CompetitorMonitor::schedule();
        }

        Migration::maybe_migrate_legacy(true);
        \TMWSEO\Engine\Platform\AffiliateLinkBuilder::register_rewrite_rule();
        flush_rewrite_rules();

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
        flush_rewrite_rules();
        Logs::info('core', 'Deactivated ' . TMWSEO_ENGINE_VERSION);
    }
}
