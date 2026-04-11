<?php
/**
 * TMW SEO Engine — File Loader
 *
 * Groups all includes by domain instead of a single 100+ line flat list.
 * This makes it immediately clear which domain a missing file belongs to,
 * and prevents a missing file in one domain (e.g. content/) from silently
 * breaking an unrelated domain (e.g. keywords/).
 *
 * Every entry still uses tmwseo_safe_require() so a single missing file
 * never fatals the whole admin. Behaviour is identical to the old flat list —
 * this is purely a structural reorganisation.
 *
 * Loading order within each group follows dependency direction:
 *   interfaces → repositories → services → engines → admin pages
 *
 * @package TMWSEO\Engine
 * @since   5.1.1
 */
namespace TMWSEO\Engine;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Loader {

    /**
     * Load all plugin files.
     * Called once from the plugin bootstrap (tmw-seo-engine.php) before Plugin::init().
     */
    public static function load_all(): void {
        self::load_core();
        self::load_services();
        self::load_keywords();
        self::load_content();
        self::load_models();
        self::load_platform();
        self::load_integrations();
        self::load_seo_engine();
        self::load_cluster_and_lighthouse();
        self::load_admin();
        self::load_intelligence();
        self::load_cli();
    }

    // ── Core ──────────────────────────────────────────────────────────────────

    private static function load_core(): void {
        $p = TMWSEO_ENGINE_PATH;
        tmwseo_safe_require( $p . 'includes/db/class-schema.php' );
        tmwseo_safe_require( $p . 'includes/db/class-logs.php' );
        tmwseo_safe_require( $p . 'includes/db/class-jobs.php' );
        tmwseo_safe_require( $p . 'includes/class-discovery-governor.php' );
        tmwseo_safe_require( $p . 'includes/cron/class-cron.php' );
        tmwseo_safe_require( $p . 'includes/engine/class-smart-queue.php' );
        tmwseo_safe_require( $p . 'includes/worker/class-worker.php' );
        tmwseo_safe_require( $p . 'includes/worker/class-job-worker.php' );
        tmwseo_safe_require( $p . 'includes/migration/class-migration.php' );
        tmwseo_safe_require( $p . 'includes/migration/class-autopilot-migration-registry.php' );
        tmwseo_safe_require( $p . 'includes/autopilot/class-seo-autopilot.php' );
        tmwseo_safe_require( $p . 'includes/compat/class-tmw-main-class.php' );
        tmwseo_safe_require( $p . 'includes/ai/class-ai-router.php' );
        tmwseo_safe_require( $p . 'includes/schema/class-schema-generator.php' );
        tmwseo_safe_require( $p . 'includes/export/class-csv-exporter.php' );
    }

    // ── Services ──────────────────────────────────────────────────────────────

    private static function load_services(): void {
        $p = TMWSEO_ENGINE_PATH . 'includes/services/';
        tmwseo_safe_require( $p . 'class-settings.php' );
        tmwseo_safe_require( $p . 'class-trust-policy.php' );
        tmwseo_safe_require( $p . 'class-openai.php' );
        tmwseo_safe_require( $p . 'class-anthropic.php' );
        tmwseo_safe_require( $p . 'class-dataforseo.php' );
        tmwseo_safe_require( $p . 'class-google-trends.php' );
        tmwseo_safe_require( $p . 'class-pagespeed.php' );
        tmwseo_safe_require( $p . 'class-rank-tracker.php' );
        tmwseo_safe_require( $p . 'class-title-fixer.php' );
        // @deprecated — use seo-engine/topic-authority/ instead. Kept for backward compat.
        tmwseo_safe_require( $p . 'class-topic-authority-engine.php' );
        tmwseo_safe_require( $p . 'class-semantic-coverage-engine.php' );
    }

    // ── Keywords ──────────────────────────────────────────────────────────────

    private static function load_keywords(): void {
        $p = TMWSEO_ENGINE_PATH . 'includes/keywords/';
        tmwseo_safe_require( $p . 'class-keyword-validator.php' );
        tmwseo_safe_require( $p . 'class-keyword-cluster-reconciler.php' ); // canonical identity + admin-triggered merge
        tmwseo_safe_require( $p . 'class-kd-filter.php' );
        tmwseo_safe_require( $p . 'class-keyword-usage.php' );
        tmwseo_safe_require( $p . 'class-curated-keyword-library.php' );
        tmwseo_safe_require( $p . 'class-keyword-scheduler.php' );
        tmwseo_safe_require( $p . 'class-cannibalization-detector.php' );
        tmwseo_safe_require( $p . 'class-tag-quality-engine.php' );
        tmwseo_safe_require( $p . 'class-ownership-enforcer.php' );
        tmwseo_safe_require( $p . 'class-architecture-reset.php' );
        tmwseo_safe_require( $p . 'class-staging-clean-rebuild.php' );
        tmwseo_safe_require( $p . 'class-term-lifecycle.php' );
        tmwseo_safe_require( $p . 'class-query-expansion-graph.php' );
        tmwseo_safe_require( $p . 'class-keyword-discovery-governor.php' );
        tmwseo_safe_require( $p . 'class-topical-relevance-filter.php' );
        tmwseo_safe_require( $p . 'class-topic-entity-layer.php' );
        tmwseo_safe_require( $p . 'class-recursive-keyword-expansion-engine.php' );
        tmwseo_safe_require( $p . 'class-expansion-candidate-repository.php' );
        tmwseo_safe_require( $p . 'class-builder-candidate-service.php' );
        tmwseo_safe_require( $p . 'class-keyword-discovery-service.php' );
        tmwseo_safe_require( $p . 'class-seed-registry.php' );
        tmwseo_safe_require( $p . 'class-keyword-engine.php' );
        tmwseo_safe_require( $p . 'class-dirty-queue.php' );
        tmwseo_safe_require( $p . 'class-content-keyword-miner.php' );
        tmwseo_safe_require( $p . 'class-competitor-mining-service.php' );
        tmwseo_safe_require( $p . 'class-niche-serp-mining-service.php' );
        tmwseo_safe_require( $p . 'class-discovery-orchestrator.php' );
        tmwseo_safe_require( $p . 'class-keyword-idea-provider-interface.php' );
        tmwseo_safe_require( $p . 'class-dataforseo-keyword-idea-provider.php' );
        tmwseo_safe_require( $p . 'class-google-trends-idea-provider.php' );
        tmwseo_safe_require( $p . 'class-google-keyword-planner-idea-provider.php' );
        tmwseo_safe_require( $p . 'class-google-autosuggest-idea-provider.php' );
        tmwseo_safe_require( $p . 'class-keyword-idea-aggregator.php' );
        tmwseo_safe_require( $p . 'class-unified-keyword-workflow-service.php' );
        tmwseo_safe_require( $p . 'class-keyword-library.php' );
        tmwseo_safe_require( $p . 'class-model-keyword-pack.php' );

        // Keyword Intelligence subsystem (seo-engine/keyword-intelligence/)
        $ki = TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/';
        tmwseo_safe_require( $ki . 'class-keyword-expander.php' );
        tmwseo_safe_require( $ki . 'class-keyword-filter.php' );
        tmwseo_safe_require( $ki . 'class-keyword-intent.php' );
        tmwseo_safe_require( $ki . 'class-keyword-classifier.php' );
        tmwseo_safe_require( $ki . 'class-model-discovery-trigger.php' );
        tmwseo_safe_require( $ki . 'class-keyword-scorer.php' );
        tmwseo_safe_require( $ki . 'class-keyword-pack-builder.php' );
        tmwseo_safe_require( $ki . 'class-keyword-intelligence.php' );
        tmwseo_safe_require( $ki . 'class-keyword-database.php' );
        tmwseo_safe_require( $ki . 'class-entity-combination-engine.php' );
        tmwseo_safe_require( $ki . 'class-tag-modifier-expander.php' );

        tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/expansion/class-keyword-expansion-engine.php' );
    }

    // ── Content ───────────────────────────────────────────────────────────────

    private static function load_content(): void {
        $p = TMWSEO_ENGINE_PATH . 'includes/content/';
        tmwseo_safe_require( $p . 'class-uniqueness-checker.php' );
        tmwseo_safe_require( $p . 'class-index-readiness-gate.php' );
        tmwseo_safe_require( $p . 'class-video-title-rewriter.php' );
        tmwseo_safe_require( $p . 'class-video-content-architecture.php' );
        tmwseo_safe_require( $p . 'class-audit-trail.php' );
        tmwseo_safe_require( $p . 'class-rank-math-mapper.php' );
        tmwseo_safe_require( $p . 'class-rank-math-reader.php' );
        tmwseo_safe_require( $p . 'class-rank-math-checklist.php' );
        tmwseo_safe_require( $p . 'class-content-generation-gate.php' );
        tmwseo_safe_require( $p . 'class-model-page-renderer.php' );
        tmwseo_safe_require( $p . 'class-content-engine.php' );
        tmwseo_safe_require( $p . 'class-assisted-draft-enrichment-service.php' );
        tmwseo_safe_require( $p . 'class-quality-score-engine.php' );
        tmwseo_safe_require( $p . 'class-template-content.php' );
        tmwseo_safe_require( $p . 'class-claude-content.php' );
        tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/templates/class-template-engine.php' );
        tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/media/class-image-meta-generator.php' );
        tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/media/class-image-meta-hooks.php' );
    }

    // ── Models ────────────────────────────────────────────────────────────────

    private static function load_models(): void {
        $p = TMWSEO_ENGINE_PATH . 'includes/model/';
        tmwseo_safe_require( $p . 'class-model-research-provider-interface.php' );
        tmwseo_safe_require( $p . 'class-rollback.php' );
        tmwseo_safe_require( $p . 'class-model-optimizer.php' );
        tmwseo_safe_require( $p . 'class-model-discovery-worker.php' );
        tmwseo_safe_require( $p . 'class-model-intelligence.php' );
        tmwseo_safe_require( $p . 'class-model-serp-research-provider.php' );
    }

    // ── Platform ──────────────────────────────────────────────────────────────

    private static function load_platform(): void {
        $p = TMWSEO_ENGINE_PATH . 'includes/platform/';
        tmwseo_safe_require( $p . 'class-platform-registry.php' );
        tmwseo_safe_require( $p . 'class-platform-profiles.php' );
        tmwseo_safe_require( $p . 'class-affiliate-link-builder.php' );
    }

    // ── Integrations ──────────────────────────────────────────────────────────

    private static function load_integrations(): void {
        $p = TMWSEO_ENGINE_PATH . 'includes/integrations/';
        tmwseo_safe_require( $p . 'class-gsc-api.php' );
        tmwseo_safe_require( $p . 'class-gsc-seed-importer.php' );
        tmwseo_safe_require( $p . 'class-gsc-cluster-importer.php' );
        tmwseo_safe_require( $p . 'class-google-indexing-api.php' );
        tmwseo_safe_require( $p . 'class-google-ads-keyword-planner-api.php' );
    }

    // ── SEO Engine (seo-engine/ subtree) ──────────────────────────────────────

    private static function load_seo_engine(): void {
        $p = TMWSEO_ENGINE_PATH . 'includes/seo-engine/';

        // Clustering
        tmwseo_safe_require( $p . 'clustering/class-keyword-normalizer.php' );
        tmwseo_safe_require( $p . 'clustering/class-cluster-builder.php' );
        tmwseo_safe_require( $p . 'clustering/class-cluster-engine.php' );

        // Internal links
        tmwseo_safe_require( $p . 'internal-links/class-link-graph.php' );
        tmwseo_safe_require( $p . 'internal-links/class-related-models.php' );
        tmwseo_safe_require( $p . 'internal-links/class-link-engine.php' );
        tmwseo_safe_require( $p . 'internal-links/class-link-opportunity-scanner.php' );
        tmwseo_safe_require( $p . 'internal-links/class-orphan-page-detector.php' );
        tmwseo_safe_require( $p . 'internal-links/class-internal-link-opportunities.php' );

        // Model similarity
        tmwseo_safe_require( $p . 'model-similarity/class-similarity-database.php' );
        tmwseo_safe_require( $p . 'model-similarity/class-model-similarity-calculator.php' );
        tmwseo_safe_require( $p . 'model-similarity/class-model-cluster-builder.php' );
        tmwseo_safe_require( $p . 'model-similarity/class-model-similarity-engine.php' );

        // Search intent
        tmwseo_safe_require( $p . 'search-intent/class-intent-template.php' );
        tmwseo_safe_require( $p . 'search-intent/class-intent-analyzer.php' );
        tmwseo_safe_require( $p . 'search-intent/class-intent-section-builder.php' );
        tmwseo_safe_require( $p . 'search-intent/class-intent-engine.php' );

        // Topic authority
        tmwseo_safe_require( $p . 'topic-authority/class-topic-map.php' );
        tmwseo_safe_require( $p . 'topic-authority/class-topic-page-generator.php' );
        tmwseo_safe_require( $p . 'topic-authority/class-topic-engine.php' );
        tmwseo_safe_require( $p . 'topic-authority/class-topic-authority-mapper.php' );

        // Opportunities
        tmwseo_safe_require( $p . 'opportunities/class-opportunity-database.php' );
        tmwseo_safe_require( $p . 'opportunities/class-opportunity-scorer.php' );
        tmwseo_safe_require( $p . 'opportunities/class-keyword-gap.php' );
        tmwseo_safe_require( $p . 'opportunities/class-opportunity-engine.php' );
        tmwseo_safe_require( $p . 'opportunities/class-opportunity-ui.php' );
        tmwseo_safe_require( $p . 'opportunities/class-traffic-forecast-ui.php' );
        tmwseo_safe_require( $p . 'opportunities/class-traffic-feedback-discovery.php' );

        // Content gap
        tmwseo_safe_require( $p . 'content-gap/class-content-gap-service.php' );
        tmwseo_safe_require( $p . 'content-gap/class-content-gap-admin.php' );

        // Traffic pages
        tmwseo_safe_require( $p . 'traffic-pages/class-traffic-page-generator.php' );

        // Suggestions
        tmwseo_safe_require( $p . 'suggestions/class-suggestion-engine.php' );
        tmwseo_safe_require( $p . 'suggestions/class-content-suggestion-module.php' );
        tmwseo_safe_require( $p . 'suggestions/class-content-improvement-analyzer.php' );

        // Intelligence
        tmwseo_safe_require( $p . 'intelligence/class-intelligence-storage.php' );
        tmwseo_safe_require( $p . 'intelligence/class-intelligence-materializer.php' );
        tmwseo_safe_require( $p . 'intelligence/class-topical-authority-engine.php' );
        tmwseo_safe_require( $p . 'intelligence/class-serp-weakness-engine.php' );
        tmwseo_safe_require( $p . 'intelligence/class-ranking-probability-engine.php' );
        tmwseo_safe_require( $p . 'intelligence/class-ranking-probability-orchestrator.php' );
        tmwseo_safe_require( $p . 'intelligence/class-competitor-gap-engine.php' );
        tmwseo_safe_require( $p . 'intelligence/class-content-brief-generator.php' );

        // SERP Keyword Gaps (4.6.3)
        tmwseo_safe_require( $p . 'serp-gaps/class-serp-gap-storage.php' );
        tmwseo_safe_require( $p . 'serp-gaps/class-serp-gap-scorer.php' );
        tmwseo_safe_require( $p . 'serp-gaps/class-serp-gap-engine.php' );

        // Category Formulas (5.2.0)
        tmwseo_safe_require( $p . 'category-formulas/class-sensitive-tag-policy.php' );
        tmwseo_safe_require( $p . 'category-formulas/class-signal-group-repository.php' );
        tmwseo_safe_require( $p . 'category-formulas/class-category-formula-repository.php' );
        tmwseo_safe_require( $p . 'category-formulas/class-category-formula-engine.php' );
        tmwseo_safe_require( $p . 'category-formulas/class-category-backfill-runner.php' );

        // Competitor monitor
        tmwseo_safe_require( $p . 'competitor-monitor/class-competitor-monitor.php' );

        // Debug
        tmwseo_safe_require( $p . 'debug/class-debug-logger.php' );
        tmwseo_safe_require( $p . 'debug/class-debug-panels.php' );
        tmwseo_safe_require( $p . 'debug/class-debug-api-monitor.php' );
        tmwseo_safe_require( $p . 'debug/class-debug-dashboard.php' );
    }

    // ── Cluster & Lighthouse ──────────────────────────────────────────────────

    private static function load_cluster_and_lighthouse(): void {
        $p = TMWSEO_ENGINE_PATH . 'includes/cluster/';
        tmwseo_safe_require( $p . 'class-cluster-repository.php' );
        tmwseo_safe_require( $p . 'class-cluster-service.php' );
        tmwseo_safe_require( $p . 'class-cluster-linking-engine.php' );
        tmwseo_safe_require( $p . 'class-cluster-scoring-engine.php' );
        tmwseo_safe_require( $p . 'class-cluster-advisor.php' );
        tmwseo_safe_require( $p . 'class-cluster-link-injector.php' );

        $lh = TMWSEO_ENGINE_PATH . 'includes/lighthouse/';
        tmwseo_safe_require( $lh . 'class-lh-schema.php' );
        tmwseo_safe_require( $lh . 'class-lh-targets.php' );
        tmwseo_safe_require( $lh . 'class-lh-collector-psi.php' );
        tmwseo_safe_require( $lh . 'class-lh-normalizer.php' );
        tmwseo_safe_require( $lh . 'class-lh-worker.php' );
        tmwseo_safe_require( $lh . 'class-lh-advisor.php' );
        tmwseo_safe_require( $lh . 'class-lh-dashboard.php' );
        tmwseo_safe_require( $lh . 'class-lh-bootstrap.php' );
    }

    // ── Admin ─────────────────────────────────────────────────────────────────

    private static function load_admin(): void {
        $p = TMWSEO_ENGINE_PATH . 'includes/admin/';
        tmwseo_safe_require( $p . 'class-admin-ui.php' );
        tmwseo_safe_require( $p . 'class-tmwseo-routes.php' );  // admin route map — must load before page files
        tmwseo_safe_require( $p . 'class-list-table-pagination.php' );
        tmwseo_safe_require( $p . 'tables/class-keywords-table.php' );
        tmwseo_safe_require( $p . 'tables/class-clusters-table.php' );
        tmwseo_safe_require( $p . 'tables/class-keyword-clusters-table.php' ); // keyword-cluster dataset (tmw_keyword_clusters), NOT legacy tmw_clusters
        tmwseo_safe_require( $p . 'tables/class-opportunities-table.php' );
        tmwseo_safe_require( $p . 'tables/class-seed-registry-table.php' );

        // Handler classes (extracted from god class — v5.1.1)
        tmwseo_safe_require( $p . 'class-admin-ajax-handlers.php' );
        tmwseo_safe_require( $p . 'class-admin-form-handlers.php' );

        // Core admin + page classes
        tmwseo_safe_require( $p . 'class-admin.php' );
        tmwseo_safe_require( $p . 'class-command-center.php' );
        tmwseo_safe_require( $p . 'class-keyword-data-repository.php' );
        tmwseo_safe_require( $p . 'class-seed-registry-admin-page.php' );
        tmwseo_safe_require( $p . 'class-editor-ai-metabox.php' );
        tmwseo_safe_require( $p . 'class-staging-validation-helper.php' );
        tmwseo_safe_require( $p . 'class-serp-analyzer-admin-page.php' );
        tmwseo_safe_require( $p . 'class-link-graph-admin-page.php' );
        tmwseo_safe_require( $p . 'class-topic-maps-admin-page.php' );
        tmwseo_safe_require( $p . 'class-discovery-control-admin-page.php' );
        tmwseo_safe_require( $p . 'class-keyword-graph-admin-page.php' );
        tmwseo_safe_require( $p . 'class-csv-manager-admin-page.php' );
        tmwseo_safe_require( $p . 'class-ai-content-brief-generator-admin.php' );
        tmwseo_safe_require( $p . 'class-autopilot-admin-page.php' );
        tmwseo_safe_require( $p . 'class-staging-operations-page.php' );
        tmwseo_safe_require( $p . 'class-seo-engine-runner.php' );
        // NOTE: topic-authority-page.php is a bare procedural file (not a class).
        // It is kept here for backward compat. Candidate for removal in v6 cleanup.
        tmwseo_safe_require( $p . 'topic-authority-page.php' );
        tmwseo_safe_require( $p . 'class-rank-math-helper-panel.php' );
        tmwseo_safe_require( $p . 'class-keyword-command-center.php' );
        tmwseo_safe_require( $p . 'class-content-review-page.php' );
        tmwseo_safe_require( $p . 'class-video-seo-metabox.php' );
        tmwseo_safe_require( $p . 'class-model-helper.php' );
        tmwseo_safe_require( $p . 'class-admin-dashboard-v2.php' );
        tmwseo_safe_require( $p . 'class-cluster-admin-page.php' );

        // SERP Keyword Gaps admin page (4.6.3)
        tmwseo_safe_require( $p . 'class-serp-gap-admin-page.php' );

        // Category Formula Engine admin page (5.2.0)
        tmwseo_safe_require( $p . 'class-category-formula-admin-page.php' );

        // Suggestions admin (large — handlers extracted to trait v5.1.1)
        tmwseo_safe_require( $p . 'class-suggestions-form-handlers-trait.php' );
        tmwseo_safe_require( $p . 'class-suggestions-admin-page.php' );
    }

    // ── Intelligence ─────────────────────────────────────────────────────────

    private static function load_intelligence(): void {
        $p = TMWSEO_ENGINE_PATH . 'includes/intelligence/';
        tmwseo_safe_require( $p . 'class-intelligence-runner.php' );
        tmwseo_safe_require( $p . 'class-intelligence-admin.php' );
    }

    // ── CLI ───────────────────────────────────────────────────────────────────

    private static function load_cli(): void {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/cli/class-cli.php' );
        }
    }
}
