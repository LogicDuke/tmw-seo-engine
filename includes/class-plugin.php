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

tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/db/class-schema.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/db/class-logs.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/db/class-jobs.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/class-discovery-governor.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/cron/class-cron.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/engine/class-smart-queue.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/worker/class-worker.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/worker/class-job-worker.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-admin-ui.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-list-table-pagination.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/tables/class-keywords-table.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/tables/class-clusters-table.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/tables/class-opportunities-table.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/tables/class-seed-registry-table.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-admin.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-command-center.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-seed-registry-admin-page.php' ); // 4.3.0
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-editor-ai-metabox.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-staging-validation-helper.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-serp-analyzer-admin-page.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-link-graph-admin-page.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-topic-maps-admin-page.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-discovery-control-admin-page.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-keyword-graph-admin-page.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-csv-manager-admin-page.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-ai-content-brief-generator-admin.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-autopilot-admin-page.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-staging-operations-page.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-seo-engine-runner.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/topic-authority-page.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/migration/class-migration.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/migration/class-autopilot-migration-registry.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/autopilot/class-seo-autopilot.php' );

tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/services/class-settings.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/services/class-topic-authority-engine.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/services/class-semantic-coverage-engine.php' );

// ── Autopilot integration: new classes ────────────────────────────────────────
// Keyword usage deduplication (anti-cannibalization)
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-keyword-usage.php' );
// Curated keyword library (30 niche CSV categories)
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-curated-keyword-library.php' );
// Background keyword data maintenance crons (data-only, respects manual mode)
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-keyword-scheduler.php' );
// Rollback (snapshot + restore pre-generation state)
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/model/class-rollback.php' );
// Content uniqueness / similarity checker
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/content/class-uniqueness-checker.php' );
// Audit-fix classes (4.4.0)
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/content/class-index-readiness-gate.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/content/class-video-title-rewriter.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/content/class-video-content-architecture.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/content/class-audit-trail.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/content/class-rank-math-mapper.php' ); // Patch 2
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/content/class-rank-math-reader.php' ); // 4.5.0 — RM read layer
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/content/class-rank-math-checklist.php' ); // 4.5.0 — RM checklist
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-rank-math-helper-panel.php' ); // 4.5.0 — RM helper panel
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-cannibalization-detector.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-tag-quality-engine.php' );
// Architecture v5.0: ownership enforcement, content generation gate, architecture reset
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-ownership-enforcer.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-architecture-reset.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-staging-clean-rebuild.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-term-lifecycle.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/content/class-content-generation-gate.php' );
// Architecture v5.0: consolidated operator screens
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-keyword-command-center.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-content-review-page.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-video-seo-metabox.php' ); // Patch 2
// Automated image ALT / title / caption / description
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/media/class-image-meta-generator.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/media/class-image-meta-hooks.php' );
// WP-CLI commands
if (defined('WP_CLI') && WP_CLI) {
    tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/cli/class-cli.php' );
}
// ─────────────────────────────────────────────────────────────────────────────

// ── v4.2: New integrations, AI, Schema, Export, Competitor Monitor ────────────
// AI Router (OpenAI primary + Anthropic Claude fallback + token/budget tracking)
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/ai/class-ai-router.php' );
// Real Google Search Console API (replaces fake rand() data)
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/integrations/class-gsc-api.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/integrations/class-gsc-seed-importer.php' );
// Google Indexing API (pings Google on publish)
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/integrations/class-google-indexing-api.php' );
// Ranking Probability Orchestrator (assembles full ranking signal set)
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/intelligence/class-ranking-probability-orchestrator.php' );
// JSON-LD Schema Generator (Person, VideoObject, FAQPage)
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/schema/class-schema-generator.php' );
// Orphan Page Detector (zero inbound internal links)
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/internal-links/class-orphan-page-detector.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/internal-links/class-internal-link-opportunities.php' );
// CSV Exporter
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/export/class-csv-exporter.php' );
// Competitor Monitor (weekly domain authority + keyword threat detection)
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/competitor-monitor/class-competitor-monitor.php' );
// Admin Dashboard v2
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-admin-dashboard-v2.php' );
// ─────────────────────────────────────────────────────────────────────────────
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/services/class-trust-policy.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/services/class-title-fixer.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/services/class-openai.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/services/class-dataforseo.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/services/class-google-trends.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/integrations/class-google-ads-keyword-planner-api.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/services/class-pagespeed.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/services/class-rank-tracker.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-keyword-validator.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-kd-filter.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-query-expansion-graph.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-keyword-discovery-governor.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-topical-relevance-filter.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-topic-entity-layer.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-recursive-keyword-expansion-engine.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-expansion-candidate-repository.php' ); // 4.3.0
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-keyword-discovery-service.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-seed-registry.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-keyword-engine.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-dirty-queue.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-content-keyword-miner.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-competitor-mining-service.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-discovery-orchestrator.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-keyword-idea-provider-interface.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-dataforseo-keyword-idea-provider.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-google-trends-idea-provider.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-google-keyword-planner-idea-provider.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-google-autosuggest-idea-provider.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-keyword-idea-aggregator.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-unified-keyword-workflow-service.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-keyword-library.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/keywords/class-model-keyword-pack.php' );

tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/templates/class-template-engine.php' );

tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/content/class-content-engine.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/content/class-assisted-draft-enrichment-service.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/content/class-quality-score-engine.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/content/class-template-content.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/platform/class-platform-registry.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/platform/class-platform-profiles.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/platform/class-affiliate-link-builder.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/model/class-model-optimizer.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/model/class-model-discovery-worker.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/model/class-model-intelligence.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-model-helper.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/model/class-model-serp-research-provider.php' ); // 4.6.1 — DataForSEO SERP provider // 4.6.0 — Model Research enrichment workflow

tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-expander.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-filter.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-intent.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-classifier.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-model-discovery-trigger.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-scorer.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-pack-builder.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-intelligence.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/expansion/class-keyword-expansion-engine.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-keyword-database.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-entity-combination-engine.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/keyword-intelligence/class-tag-modifier-expander.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/clustering/class-keyword-normalizer.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/clustering/class-cluster-builder.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/clustering/class-cluster-engine.php' );

tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/internal-links/class-link-graph.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/internal-links/class-related-models.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/internal-links/class-link-engine.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/internal-links/class-link-opportunity-scanner.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/model-similarity/class-similarity-database.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/model-similarity/class-model-similarity-calculator.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/model-similarity/class-model-cluster-builder.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/model-similarity/class-model-similarity-engine.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/search-intent/class-intent-template.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/search-intent/class-intent-analyzer.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/search-intent/class-intent-section-builder.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/search-intent/class-intent-engine.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/topic-authority/class-topic-map.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/topic-authority/class-topic-page-generator.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/topic-authority/class-topic-engine.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/topic-authority/class-topic-authority-mapper.php' );


tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/opportunities/class-opportunity-database.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/opportunities/class-opportunity-scorer.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/opportunities/class-keyword-gap.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/opportunities/class-opportunity-engine.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/opportunities/class-opportunity-ui.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/opportunities/class-traffic-forecast-ui.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/opportunities/class-traffic-feedback-discovery.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/content-gap/class-content-gap-service.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/content-gap/class-content-gap-admin.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/traffic-pages/class-traffic-page-generator.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/suggestions/class-suggestion-engine.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/suggestions/class-content-suggestion-module.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/suggestions/class-content-improvement-analyzer.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/intelligence/class-intelligence-storage.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/intelligence/class-intelligence-materializer.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/intelligence/class-topical-authority-engine.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/intelligence/class-serp-weakness-engine.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/intelligence/class-ranking-probability-engine.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/intelligence/class-competitor-gap-engine.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/intelligence/class-content-brief-generator.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-suggestions-admin-page.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/debug/class-debug-logger.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/debug/class-debug-panels.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/debug/class-debug-api-monitor.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/seo-engine/debug/class-debug-dashboard.php' );

// Cluster & Lighthouse modules (manual triggers only in Phase 1).
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-repository.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-service.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-linking-engine.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-scoring-engine.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-advisor.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/cluster/class-cluster-link-injector.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/admin/class-cluster-admin-page.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/integrations/class-gsc-cluster-importer.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/compat/class-tmw-main-class.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/lighthouse/class-lh-schema.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/lighthouse/class-lh-targets.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/lighthouse/class-lh-collector-psi.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/lighthouse/class-lh-normalizer.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/lighthouse/class-lh-worker.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/lighthouse/class-lh-advisor.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/lighthouse/class-lh-dashboard.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/lighthouse/class-lh-bootstrap.php' );

// Intelligence Core (Phase 1)
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/intelligence/class-intelligence-runner.php' );
tmwseo_safe_require( TMWSEO_ENGINE_PATH . 'includes/intelligence/class-intelligence-admin.php' );

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
        }
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
