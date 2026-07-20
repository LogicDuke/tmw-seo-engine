<?php
namespace TMWSEO\Engine;

use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Services\Crypto;
use TMWSEO\Engine\Services\Capabilities;
use TMWSEO\Engine\Services\TrustPolicy;
use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Services\OpenAI;
use TMWSEO\Engine\Integrations\GSCApi;
use TMWSEO\Engine\Intelligence\IntelligenceStorage;
use TMWSEO\Engine\Admin\AdminUI;
use TMWSEO\Engine\Admin\AdminSettingsSanitizer;
use TMWSEO\Engine\Admin\AdminNotices;
use TMWSEO\Engine\Admin\AdminMenu;
use TMWSEO\Engine\Admin\AdminDfseoPreviewPage;
use TMWSEO\Engine\Platform\PlatformRegistry;
use TMWSEO\Engine\Keywords\DiscoveryOrchestrator;
use TMWSEO\Engine\Keywords\SeedRegistry;
use TMWSEO\Engine\Keywords\CompetitorMiningService;
use TMWSEO\Engine\Keywords\KeywordCandidateClassificationAudit;
use TMWSEO\Engine\Keywords\KeywordPoolCandidateRepository;
use TMWSEO\Engine\Keywords\KeywordPoolClassificationApplyService;
use TMWSEO\Engine\Keywords\ModelKeywordPoolClassifier;
use TMWSEO\Engine\Admin\Tables\KeywordsTable;
use TMWSEO\Engine\Admin\Tables\ClustersTable;
use TMWSEO\Engine\Admin\Tables\KeywordClustersTable;
use TMWSEO\Engine\Admin\AdminFormHandlers;
use TMWSEO\Engine\Admin\AdminAjaxHandlers;
use TMWSEO\Engine\Admin\DiscoveryControlAdminPage;
use TMWSEO\Engine\Affiliates\CrakRevenueCamManager;

if (!defined('ABSPATH')) { exit; }

class Admin {

    const MENU_SLUG = 'tmwseo-engine';

    public static function init(): void {
        add_action('admin_menu', [AdminMenu::class, 'register']);
        add_action('admin_menu', [AdminMenu::class, 'reorder'], 9999);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
        // Bulletproof CSS fallback: output styles in admin_head for any tmwseo page,
        // regardless of hook suffix. This catches pages missed by enqueue_admin_assets.
        add_action('admin_head', [__CLASS__, 'print_admin_css_if_tmw_page']);
        add_action('admin_notices', [AdminNotices::class, 'render']);
        add_action('admin_init', [DiscoveryControlAdminPage::class, 'maybe_handle_post_action']);
        // Hook targets routed directly to AdminFormHandlers / AdminAjaxHandlers.
        // These were previously thin Admin:: wrappers padding the god class.
        add_action('admin_post_tmwseo_run_worker', [AdminFormHandlers::class, 'run_worker_now']);
        // Legacy handler neutralized — the settings form uses options.php + register_setting().
        // This redirect prevents a stripped save if any old bookmark/form somehow targets this action.
        add_action('admin_post_tmwseo_save_settings', [AdminFormHandlers::class, 'legacy_save_settings_redirect']);
        add_action('admin_post_tmwseo_run_keyword_cycle', [AdminFormHandlers::class, 'run_keyword_cycle_now']);
        add_action('admin_post_tmwseo_run_competitor_mining', [AdminFormHandlers::class, 'run_competitor_mining_now']);
        add_action('admin_post_tmwseo_run_niche_serp_mining', [AdminFormHandlers::class, 'run_niche_serp_mining_now']);
        add_action('admin_post_tmwseo_run_pagespeed_cycle', [AdminFormHandlers::class, 'run_pagespeed_cycle_now']);
        add_action('admin_post_tmwseo_enable_indexing', [AdminFormHandlers::class, 'enable_indexing_now']);
        add_action('admin_post_tmwseo_keyword_candidate_action', [AdminFormHandlers::class, 'handle_keyword_candidate_action']);
        add_action('admin_post_tmwseo_optimize_post_now', [AdminFormHandlers::class, 'handle_optimize_post_now']);
        add_action('admin_post_tmwseo_refresh_keywords_now', [AdminFormHandlers::class, 'handle_refresh_keywords_now']);
        add_action('wp_ajax_tmwseo_generate_now', [AdminAjaxHandlers::class, 'ajax_generate_now']);
        add_action('wp_ajax_tmwseo_kick_worker', [AdminAjaxHandlers::class, 'ajax_kick_worker']);
        add_action('wp_ajax_tmwseo_rerun_model_preview_phrases', [AdminAjaxHandlers::class, 'ajax_rerun_model_preview_phrases']);
        add_action('wp_ajax_tmwseo_save_model_fallback_pack', [AdminAjaxHandlers::class, 'ajax_save_model_fallback_pack']);
        add_action('wp_ajax_tmwseo_kw_classification_dry_run', [AdminAjaxHandlers::class, 'ajax_kw_classification_dry_run']);
        add_action('wp_ajax_tmwseo_kw_classification_apply_batch', [AdminAjaxHandlers::class, 'ajax_kw_classification_apply_batch']);
        add_action('admin_post_tmwseo_import_keywords', [AdminFormHandlers::class, 'import_keywords']);
        add_action('admin_post_tmwseo_bulk_autofix', [AdminFormHandlers::class, 'handle_bulk_autofix']);
        add_action('admin_post_tmwseo_reset_discovery_data', [AdminFormHandlers::class, 'handle_reset_discovery_data']);
        add_action('admin_post_tmwseo_generate_model_seeds', [AdminFormHandlers::class, 'handle_generate_model_seeds']);
        add_action('admin_post_tmwseo_rerun_model_preview_phrases', [AdminFormHandlers::class, 'handle_rerun_model_preview_phrases']);
        add_action('admin_post_tmwseo_cr_save_and_sync', [CrakRevenueCamManager::class, 'handle_save_and_sync']);
        add_action('admin_post_tmwseo_cr_quick_action', [CrakRevenueCamManager::class, 'handle_quick_action']);
        add_action('admin_post_tmwseo_cr_auto_map', [CrakRevenueCamManager::class, 'handle_quick_action']);
        add_action('admin_post_tmwseo_run_dfseo_paid_keyword_scan', [AdminDfseoPreviewPage::class, 'handle_paid_scan']);
        add_action('admin_post_tmwseo_preview_keyword_cleanup', [AdminFormHandlers::class, 'preview_keyword_cleanup']);
        add_action('admin_post_tmwseo_apply_keyword_cleanup', [AdminFormHandlers::class, 'apply_keyword_cleanup']);
        add_action('admin_post_tmwseo_preview_csv_keyword_approvals', [AdminFormHandlers::class, 'preview_csv_keyword_approvals']);
        add_action('admin_post_tmwseo_apply_csv_keyword_approvals', [AdminFormHandlers::class, 'apply_csv_keyword_approvals']);
        add_action('admin_post_tmwseo_download_csv_keyword_approval_rollback', [AdminFormHandlers::class, 'download_csv_keyword_approval_rollback']);
        add_action('admin_post_tmwseo_verify_new_keyword_metrics', [AdminFormHandlers::class, 'verify_new_keyword_metrics_now']);
        add_action('admin_post_tmwseo_force_recheck_keyword_metrics', [AdminFormHandlers::class, 'force_recheck_keyword_metrics_now']);
        add_action('tmw_manual_cycle_event', ['\TMWSEO\Engine\Keywords\UnifiedKeywordWorkflowService', 'run_cycle'], 10, 1);
        \TMWSEO\Engine\Admin\KeywordMetricsCsvImporter::init();
        \TMWSEO\Engine\Admin\KeywordPoolsAdminPage::init();
        \TMWSEO\Engine\Admin\ModelOpportunityAdminPage::init();
    }

    public static function handle_bulk_autofix(): void {
        AdminFormHandlers::handle_bulk_autofix();
    }

    /**
     * Output admin CSS directly in <head> for any TMW SEO Engine page.
     * Uses echo <style> so it works regardless of wp_add_inline_style timing.
     * Runs on admin_head (fires inside <head>) — reliable on every page.
     */
    public static function print_admin_css_if_tmw_page(): void {
        static $printed = false;
        if ( $printed ) { return; }
        $screen = get_current_screen();
        if ( ! $screen ) { return; }
        $id = $screen->id ?? '';
        if ( strpos( $id, 'tmwseo' ) === false &&
             strpos( $id, 'tmw-seo' ) === false &&
             strpos( $id, 'tmwcc' ) === false &&
             strpos( $id, self::MENU_SLUG ) === false ) {
            return;
        }
        $printed = true;
        echo '<style id="tmwseo-admin-ui-css" type="text/css">' . AdminUI::css() . '</style>';
    }

    public static function enqueue_admin_assets(string $hook): void {
        $allowed_hooks = [
            'toplevel_page_' . self::MENU_SLUG,
            self::MENU_SLUG . '_page_tmwseo-suggestions',
            self::MENU_SLUG . '_page_tmwseo-command-center',
            self::MENU_SLUG . '_page_tmwseo-command-center-legacy',
            self::MENU_SLUG . '_page_tmwseo-content-briefs',
            self::MENU_SLUG . '_page_tmwseo-competitor-domains',
            self::MENU_SLUG . '_page_tmwseo-content-gap',
            self::MENU_SLUG . '_page_tmwseo-keywords',
            self::MENU_SLUG . '_page_tmwseo-keyword-pools',
            self::MENU_SLUG . '_page_tmwseo-opportunities',
            self::MENU_SLUG . '_page_tmwseo-traffic-forecast',
            self::MENU_SLUG . '_page_tmwseo-autopilot',
            self::MENU_SLUG . '_page_tmwseo-ranking-probability',
            self::MENU_SLUG . '_page_tmwseo-reports',
            self::MENU_SLUG . '_page_tmwseo-connections',
            self::MENU_SLUG . '_page_tmwseo-affiliates',
            self::MENU_SLUG . '_page_tmwseo-settings',
            self::MENU_SLUG . '_page_tmwseo-tools',
            self::MENU_SLUG . '_page_tmwseo-internal-links',
            self::MENU_SLUG . '_page_tmwseo-serp-analyzer',
            self::MENU_SLUG . '_page_tmwseo-competitor-mining',
            self::MENU_SLUG . '_page_tmwseo-link-graph',
            self::MENU_SLUG . '_page_tmwseo-topic-maps',
            self::MENU_SLUG . '_page_tmwseo-topic-authority',
            self::MENU_SLUG . '_page_tmwseo-debug-dashboard',
            self::MENU_SLUG . '_page_tmwseo-staging-ops',
            self::MENU_SLUG . '_page_tmwseo-model-opportunities',
            self::MENU_SLUG . '_page_tmwseo-serp-gaps',
            self::MENU_SLUG . '_page_tmwseo-search-intelligence',
            self::MENU_SLUG . '_page_tmwseo-category-formulas',
            self::MENU_SLUG . '_page_tmwseo-dfseo-keyword-strategy-preview',
            self::MENU_SLUG . '_page_tmw-seo-debug',
            // Hidden pages (null parent) use admin_page_{slug} hook format
            'admin_page_tmwseo-generated',
            'admin_page_tmwseo-logs',
            'admin_page_tmw-engine-monitor',
            'admin_page_tmwseo-migration',
            'admin_page_tmwseo-import',
            'admin_page_tmwseo-kw-metrics-import', // 5.9.0
            'admin_page_tmwseo-intelligence',
            'admin_page_tmwseo-pagespeed',
            'admin_page_tmw-seo-clusters',
        ];

        if (!in_array($hook, $allowed_hooks, true)) {
            return;
        }

        AdminUI::enqueue();

        wp_register_style('tmwseo-admin-overview', false);
        wp_enqueue_style('tmwseo-admin-overview');
        wp_add_inline_style('tmwseo-admin-overview', '
            .tmwseo-dashboard {
                max-width: 1200px;
                padding-bottom:40px;
                font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
            }

            .tmwseo-row {
                display:grid;
                gap:20px;
                margin-bottom:30px;
            }

            .executive-row {
                grid-template-columns: repeat(3, 1fr);
                align-items:stretch;
            }

            .executive-row .tmwseo-card {
                display:flex;
                flex-direction:column;
                justify-content:center;
                text-align:center;
            }

            .tmwseo-row:not(.executive-row) {
                grid-template-columns: repeat(2, 1fr);
            }

            .tmwseo-card {
                background:#ffffff;
                border:1px solid #e5e7eb;
                padding:25px;
                border-radius:12px;
                box-shadow:0 4px 12px rgba(0,0,0,0.05);
                transition: all 0.2s ease;
                text-align: center;
            }

            .tmwseo-card:hover {
                transform: translateY(-2px);
                box-shadow:0 6px 18px rgba(0,0,0,0.08);
            }

            .tmwseo-card h3 {
                font-size: 28px;
                margin: 0;
            }

            .tmwseo-suggestions-page .subsubsub {
                float:none;
                margin:12px 0 14px;
            }

            .tmwseo-suggestions-table td,
            .tmwseo-suggestions-table th {
                vertical-align:top;
            }

            .tmwseo-priority {
                display:inline-block;
                padding:4px 10px;
                border-radius:999px;
                font-weight:600;
                font-size:12px;
                line-height:1.4;
            }

            .tmwseo-priority-high {
                background:#fef2f2;
                color:#b91c1c;
            }

            .tmwseo-priority-medium {
                background:#fff7ed;
                color:#c2410c;
            }

            .tmwseo-priority-low {
                background:#eff6ff;
                color:#1d4ed8;
            }


            .tmwseo-status-badge,
            .tmwseo-target-badge,
            .tmwseo-action-label {
                display:inline-block;
                padding:4px 10px;
                border-radius:999px;
                font-weight:600;
                font-size:12px;
                line-height:1.4;
                margin-bottom:6px;
            }

            .tmwseo-cell-note {
                font-size:12px;
                color:#4b5563;
                line-height:1.45;
            }

            .tmwseo-description-block {
                font-size:12px;
                line-height:1.45;
            }

            .tmwseo-description-summary {
                margin:0 0 6px;
                color:#111827;
            }

            .tmwseo-description-details-inline {
                margin:0;
                color:#374151;
                font-size:12px;
            }

            .tmwseo-description-details {
                margin:0;
                padding-left:16px;
                color:#374151;
            }

            .tmwseo-inline-preview {
                padding:6px 8px;
                border:1px solid #e5e7eb;
                border-radius:8px;
                background:#f9fafb;
            }

            .tmwseo-inline-preview summary {
                cursor:pointer;
                color:#111827;
                margin-bottom:6px;
            }

            .tmwseo-inline-preview[open] summary {
                margin-bottom:8px;
            }

            .tmwseo-description-details li {
                margin:0 0 4px;
            }

            .tmwseo-status-new {
                background:#eff6ff;
                color:#1d4ed8;
            }

            .tmwseo-status-draft-created {
                background:#ecfdf5;
                color:#047857;
            }

            .tmwseo-status-ignored {
                background:#f3f4f6;
                color:#374151;
            }

            .tmwseo-status-implemented {
                background:#ede9fe;
                color:#6d28d9;
            }

            .tmwseo-target-category-page {
                background:#fef3c7;
                color:#92400e;
                border:1px solid #f59e0b;
                box-shadow:inset 0 0 0 1px rgba(245, 158, 11, 0.25);
            }

            .tmwseo-target-model-page {
                background:#f3e8ff;
                color:#5b21b6;
                border:1px solid #c4b5fd;
                box-shadow:inset 0 0 0 1px rgba(124,58,237,0.20);
                font-weight:700;
            }
            .tmwseo-model-intel-cue {
                margin-top: 5px;
                font-size: 11px;
                color: #6b7280;
                line-height: 1.5;
            }
            .tmwseo-model-intel-tag {
                display: inline-block;
                background: #faf5ff;
                border: 1px solid #e9d5ff;
                border-radius: 4px;
                padding: 1px 6px;
                margin-right: 3px;
                color: #6d28d9;
                font-size: 11px;
            }

            .tmwseo-target-video-page {
                background:#fee2e2;
                color:#991b1b;
            }

            .tmwseo-target-generic-post {
                background:#e0f2fe;
                color:#075985;
            }

            .tmwseo-action-label {
                background:#f3e8ff;
                color:#6b21a8;
            }

            .tmwseo-aging-badge {
                border:1px solid #d1d5db;
            }

            .tmwseo-aging-fresh {
                background:#dcfce7;
                color:#166534;
                border-color:#86efac;
            }

            .tmwseo-aging-aging {
                background:#fef3c7;
                color:#92400e;
                border-color:#fcd34d;
            }

            .tmwseo-aging-overdue {
                background:#fee2e2;
                color:#991b1b;
                border-color:#fca5a5;
            }

            .tmwseo-command-center {
                max-width:1100px;
            }

            .tmwseo-command-grid {
                display:grid;
                grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));
                gap:16px;
                margin-top:20px;
            }

            .tmwseo-command-widget {
                display:block;
                text-decoration:none;
                border:1px solid #e5e7eb;
                border-left-width:6px;
                border-radius:10px;
                padding:18px;
                background:#fff;
                color:#111827;
                box-shadow:0 3px 8px rgba(0,0,0,0.04);
                transition:transform 0.15s ease, box-shadow 0.15s ease;
            }

            .tmwseo-command-widget:hover,
            .tmwseo-command-widget:focus {
                transform:translateY(-1px);
                box-shadow:0 6px 16px rgba(0,0,0,0.08);
                color:#111827;
            }

            .tmwseo-command-widget-value {
                display:block;
                font-size:34px;
                line-height:1.1;
                font-weight:700;
                margin-bottom:8px;
            }

            .tmwseo-command-widget-label {
                display:block;
                font-size:14px;
                color:#4b5563;
                margin-bottom:10px;
            }

            .tmwseo-command-status {
                display:inline-block;
                border-radius:999px;
                padding:4px 10px;
                font-size:12px;
                font-weight:600;
                background:#f3f4f6;
            }

            .tmwseo-command-trust-copy {
                margin-top:10px;
                margin-bottom:0;
                font-size:12px;
                color:#1f2937;
            }

            .tmwseo-command-sub-link {
                font-weight:600;
                text-decoration:none;
            }

            .tmwseo-command-sub-link:hover,
            .tmwseo-command-sub-link:focus {
                text-decoration:underline;
            }

            .tmwseo-command-good {
                border-left-color:#16a34a;
            }

            .tmwseo-command-good .tmwseo-command-status {
                background:#dcfce7;
                color:#166534;
            }

            .tmwseo-command-warn {
                border-left-color:#eab308;
            }

            .tmwseo-command-warn .tmwseo-command-status {
                background:#fef9c3;
                color:#854d0e;
            }

            .tmwseo-command-alert {
                border-left-color:#dc2626;
            }

            .tmwseo-command-alert .tmwseo-command-status {
                background:#fee2e2;
                color:#991b1b;
            }

            .tmwseo-card span {
                font-size: 14px;
                color: #666;
            }

            .tmwseo-health-card {
                text-align: center;
                padding: 30px;
                border-radius: 8px;
                font-size:48px;
                font-weight:700;
                border:1px solid #e5e7eb;
            }

            .tmwseo-health-card,
            .tmwseo-rankmath-card {
                display:flex;
                flex-direction:column;
                align-items:center;
                justify-content:center;
                padding:30px;
            }

            .tmwseo-health-card.good { background:#f0fff4; color:#2f855a; }
            .tmwseo-health-card.warning { background:#fffaf0; color:#dd6b20; }
            .tmwseo-health-card.bad {
                background:#ffffff;
                border-left:5px solid #dc2626;
                color:#c53030;
            }

            .tmwseo-health-circle {
                width:110px;
                height:110px;
                border-radius:50%;
                background:#fef2f2;
                display:flex;
                align-items:center;
                justify-content:center;
                font-size:34px;
                font-weight:700;
                margin:0 auto;
                box-shadow:0 4px 12px rgba(0,0,0,0.08);
            }

            .tmwseo-health-card .tmwseo-health-label {
                display: block;
                margin-top:15px;
                font-size:13px;
                text-transform:uppercase;
                letter-spacing:0.8px;
                opacity:0.8;
            }

            .tmwseo-progress-card {
                display:flex;
                flex-direction:column;
                justify-content:center;
                align-items:center;
                padding:30px;
                text-align:center;
                border-left:5px solid #2563eb;
            }

            .tmwseo-progress-percent {
                font-size:42px;
                font-weight:700;
                margin-bottom:2px;
                color:#1e40af;
            }

            .tmwseo-progress-label {
                font-size:13px;
                text-transform:uppercase;
                letter-spacing:0.8px;
                color:#6b7280;
                margin-bottom:12px;
            }

            .tmwseo-rankmath-card {
                background:#ffffff;
                border-left:5px solid #16a34a;
                border-radius:8px;
                text-align:center;
            }

            .rankmath-circle {
                background:#ecfdf5;
                color:#15803d;
            }

            .tmwseo-rankmath-card .tmwseo-health-label {
                color:#15803d;
            }

            .tmwseo-rankmath-card .score {
                font-size:36px;
                font-weight:700;
            }

            .tmwseo-kpi-number {
                font-size:40px;
                font-weight:700;
                margin-bottom:8px;
            }

            .tmwseo-kpi-label {
                font-size:13px;
                text-transform:uppercase;
                letter-spacing:0.7px;
                color:#6b7280;
            }

            .tmwseo-type-grid {
                display:grid;
                grid-template-columns: repeat(3, 1fr);
                gap:15px;
            }

            .tmwseo-type-card {
                background:#f9fafb;
                border:1px solid #edf2f7;
                border-radius:10px;
                padding:18px;
                text-align:center;
            }

            .tmwseo-type-card h3 {
                font-size:14px;
                text-transform:uppercase;
                letter-spacing:0.5px;
                color:#718096;
            }

            .tmwseo-type-card .score {
                font-size:28px;
                margin-top:10px;
                font-weight:700;
            }

            .tmwseo-progress-wrapper {
                width:100%;
                max-width:260px;
                height:20px;
                background:#e0e7ff;
                border-radius:12px;
                overflow:hidden;
                margin-bottom:14px;
            }

            .tmwseo-progress-bar {
                height:100%;
                background:#2563eb;
                color:#ffffff;
                font-size:12px;
                display:flex;
                align-items:center;
                justify-content:center;
                font-weight:600;
                transition: width 0.3s ease;
            }

            .tmwseo-progress-meta {
                font-size:13px;
                color:#6b7280;
            }

            .tmwseo-actions-card {
                background:#f9fafb;
                padding:22px;
                border-radius:8px;
                text-align:center;
            }

            .tmwseo-primary-action {
                text-align:center;
                margin-bottom:25px;
            }

            .tmwseo-primary-action .button {
                padding:10px 26px;
                font-size:15px;
                min-width:240px;
            }

            .tmwseo-primary-action .button-primary {
                background:#2563eb;
                border-color:#2563eb;
            }

            .tmwseo-secondary-actions {
                display:grid;
                grid-template-columns: repeat(2, 1fr);
                gap:12px;
                max-width:600px;
                margin:0 auto;
            }

            .tmwseo-secondary-actions .button {
                width:100%;
            }

            .tmwseo-detail-card {
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                padding: 20px;
            }

            .tmwseo-system-card p {
                margin:6px 0;
            }

            .tmwseo-system-card {
                background:#ffffff;
                border:1px solid #e5e7eb;
                border-radius: 8px;
                padding: 18px;
            }

            .tmwseo-system-card h3 {
                font-size:14px;
                text-transform:uppercase;
                letter-spacing:0.6px;
                color:#6b7280;
            }

            .tmwseo-system-card summary {
                cursor: pointer;
                font-weight: 600;
                margin-bottom: 12px;
            }

            .tmwseo-detail-card ul {
                margin: 12px 0 0 18px;
            }

            @media (max-width: 900px) {
                .executive-row,
                .tmwseo-row:not(.executive-row) {
                    grid-template-columns: 1fr;
                }
            }
        ');
    }

    /**
     * @deprecated Implementation moved to AdminSettingsSanitizer::register()
     * as the first step of decomposing this 4,700-line file. Kept as a
     * thin wrapper for hooks/external callers that still reference
     * [Admin::class, 'register_settings'].
     */
    public static function register_settings(): void {
        AdminSettingsSanitizer::register();
    }

    /**
     * @deprecated Implementation moved to AdminSettingsSanitizer::sanitize_engine_settings().
     * Kept as a thin wrapper because SettingsValidationTest calls this
     * directly (Admin::sanitize_settings) and WP-Settings-API hooks may
     * still reference [Admin::class, 'sanitize_settings']. New code
     * should call AdminSettingsSanitizer directly.
     */
    public static function sanitize_settings($input): array {
        return AdminSettingsSanitizer::sanitize_engine_settings($input);
    }

    /**
     * @deprecated Implementation moved to AdminSettingsSanitizer.
     * Thin wrapper for back-compat with WP-Settings-API callbacks that
     * referenced [Admin::class, 'sanitize_platform_affiliate_settings'].
     */
    public static function sanitize_platform_affiliate_settings($input): array {
        return AdminSettingsSanitizer::sanitize_platform_affiliate_settings($input);
    }

    /**
     * @deprecated Implementation moved to AdminSettingsSanitizer.
     * Thin wrapper for back-compat with WP-Settings-API callbacks that
     * referenced [Admin::class, 'sanitize_affiliate_networks'].
     */
    public static function sanitize_affiliate_networks($input): array {
        return AdminSettingsSanitizer::sanitize_affiliate_networks($input);
    }


    private static function get_affiliate_admin_platform_rows(): array {
        $rows = [];
        foreach (PlatformRegistry::get_platforms() as $platform) {
            $slug = sanitize_key((string) ($platform['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $group = sanitize_key((string) ($platform['group'] ?? ''));
            $supported = !empty($platform['affiliate_supported']);
            if (!$supported && in_array($group, ['social', 'linkhub'], true)) {
                continue;
            }
            if (!$supported) {
                continue;
            }

            $rows[$slug] = [
                'label' => sanitize_text_field((string) ($platform['name'] ?? ucfirst($slug))),
                'affiliate_link_pattern' => sanitize_text_field((string) ($platform['affiliate_link_pattern'] ?? '')),
                'siteid' => $slug === 'livejasmin' ? 'jasmin' : '',
                'categoryname' => $slug === 'livejasmin' ? 'girl' : '',
                'pagename' => $slug === 'livejasmin' ? 'freechat' : '',
            ];
        }

        return $rows;
    }

    private static function get_affiliate_platform_defaults(): array {
        return self::get_affiliate_admin_platform_rows();
    }

    /**
     * Render the read-only saved keyword candidate classification audit report.
     */
    private static function render_keyword_candidate_classification_audit(): void {
        $report = KeywordCandidateClassificationAudit::audit_database(500);
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $rows = is_array($report['rows'] ?? null) ? $report['rows'] : [];
        $display_limit = (int) ($report['display_limit'] ?? 500);
        $suspicious_rows_returned = (int) ($report['suspicious_rows_returned'] ?? count($rows));

        AdminUI::section_start(
            __( 'Keyword Pool Classification Audit', 'tmwseo' ),
            __( 'Audit-only report for saved wp_tmw_keyword_candidates pool classifications. This view never updates rows, changes statuses, writes Rank Math, writes post content, calls Generate, or changes indexing/noindex.', 'tmwseo' )
        );

        echo '<div class="notice notice-warning inline" style="margin:0 0 12px;"><p><strong>' . esc_html__( 'Read-only:', 'tmwseo' ) . '</strong> ' . esc_html__( 'Review these rows manually before any later cleanup PR. No automatic reclassification or deletion is performed here.', 'tmwseo' ) . '</p></div>';

        if ($suspicious_rows_returned >= $display_limit) {
            echo '<p class="description" style="margin:0 0 12px;">' . esc_html(sprintf(__('Showing the first %1$d suspicious rows from a full read-only scan of %2$d saved candidates.', 'tmwseo'), $display_limit, (int) ($summary['total_scanned'] ?? 0))) . '</p>';
        }

        echo '<div class="tmwui-kpi-row" style="margin:0 0 12px;">';
        $cards = [
            __( 'Total candidates scanned', 'tmwseo' ) => (int) ($summary['total_scanned'] ?? 0),
            __( 'Suspicious model rows', 'tmwseo' ) => (int) ($summary['suspicious_model_rows'] ?? 0),
            __( 'Suspicious video rows', 'tmwseo' ) => (int) ($summary['suspicious_video_rows'] ?? 0),
            __( 'Suspicious category rows', 'tmwseo' ) => (int) ($summary['suspicious_category_rows'] ?? 0),
            __( 'Rows needing manual review', 'tmwseo' ) => (int) ($summary['rows_needing_manual_review'] ?? 0),
        ];
        foreach ($cards as $label => $count) {
            echo '<div class="tmwui-kpi-card" style="min-width:150px;"><strong>' . esc_html((string) $count) . '</strong><span>' . esc_html((string) $label) . '</span></div>';
        }
        echo '</div>';

        if ($rows === []) {
            AdminUI::empty_state(__( 'No suspicious keyword candidate pool classifications found in the current audit sample.', 'tmwseo' ));
        } else {
            echo '<div class="tmwui-table-wrap">';
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([ 'Keyword', 'Intent Type', 'Entity Type', 'Entity ID', 'Status', 'Volume', 'CPC', 'Competition', 'Opportunity', 'Sources', 'Reason Codes', 'Recommended Review Action' ] as $heading) {
            echo '<th>' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $sources = (string) ($row['sources'] ?? '');
            if ($sources === '') {
                $sources = (string) ($row['source'] ?? '');
            }
            $reason_codes = implode(', ', (array) ($row['reason_codes'] ?? []));
            echo '<tr>';
            echo '<td><strong>' . esc_html((string) ($row['keyword'] ?? '')) . '</strong></td>';
            echo '<td>' . esc_html((string) ($row['intent_type'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['entity_type'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['entity_id'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['status'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['volume'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['cpc'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['competition'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['opportunity'] ?? '')) . '</td>';
            echo '<td><code>' . esc_html($sources) . '</code></td>';
            echo '<td><code>' . esc_html($reason_codes) . '</code></td>';
            echo '<td><strong>' . esc_html((string) ($row['recommended_review_action'] ?? '')) . '</strong></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        }

        self::render_keyword_pool_classification_apply_workflow();
        AdminUI::section_end();
    }


    /**
     * Render the PR 602 metadata dry-run/apply workflow appended to the audit view.
     */
    private static function render_keyword_pool_classification_apply_workflow(): void {
        $service = new KeywordPoolClassificationApplyService(new KeywordPoolCandidateRepository(), new ModelKeywordPoolClassifier());
        $summary = $service->summary();
        $nonce = wp_create_nonce('tmwseo_kw_classification_audit');

        echo '<hr style="margin:24px 0;">';
        echo '<h2>' . esc_html__( 'PR 602 Classification Metadata — Dry Run & Apply', 'tmwseo' ) . '</h2>';
        echo '<div class="notice notice-warning inline" style="margin:0 0 12px;"><p>' . esc_html__( 'This tool only writes classification metadata into keyword candidate sources JSON. It does not write Rank Math fields, post content, Generate output, indexing/noindex, publish status, slugs, or model posts. Status, entity_id, entity_type, intent_type, model_keyword_owner, and model_keyword_usage_scope are never changed.', 'tmwseo' ) . '</p></div>';

        $cards = [
            __( 'Total model keyword rows', 'tmwseo' ) => (int) ($summary['total_model_rows'] ?? 0),
            __( 'Already classified', 'tmwseo' ) => (int) ($summary['already_classified'] ?? 0),
            __( 'Missing classification', 'tmwseo' ) => (int) ($summary['missing_classification'] ?? 0),
            __( 'Standalone allowed: yes', 'tmwseo' ) => (int) ($summary['standalone_allowed_yes'] ?? 0),
            __( 'Standalone allowed: no', 'tmwseo' ) => (int) ($summary['standalone_allowed_no'] ?? 0),
            __( 'Unlinked entity_id = 0', 'tmwseo' ) => (int) ($summary['unlinked_entity_id_zero'] ?? 0),
        ];
        echo '<div class="tmwui-kpi-row" style="margin:0 0 12px;">';
        foreach ($cards as $label => $count) {
            echo '<div class="tmwui-kpi-card" style="min-width:150px;"><strong>' . esc_html((string) $count) . '</strong><span>' . esc_html((string) $label) . '</span></div>';
        }
        echo '</div>';

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin:12px 0;">';
        self::render_keyword_classification_distribution_table(__( 'By keyword_class', 'tmwseo' ), (array) ($summary['by_keyword_class'] ?? []));
        self::render_keyword_classification_distribution_table(__( 'By suggested_usage', 'tmwseo' ), (array) ($summary['by_suggested_usage'] ?? []));
        echo '</div>';

        echo '<div id="tmwseo-kw-classification-workflow" data-nonce="' . esc_attr($nonce) . '" data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '">';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;align-items:start;">';
        echo '<div class="postbox" style="padding:12px;"><h3 style="margin-top:0;">' . esc_html__( 'Dry Run Preview', 'tmwseo' ) . '</h3>';
        echo '<label>' . esc_html__( 'Filter', 'tmwseo' ) . ' <select id="tmwseo-kw-classification-filter">';
        foreach ([ 'missing', 'all', 'unlinked', 'unsafe', 'unknown' ] as $filter) {
            echo '<option value="' . esc_attr($filter) . '">' . esc_html($filter) . '</option>';
        }
        echo '</select></label> ';
        echo '<label>' . esc_html__( 'Batch size', 'tmwseo' ) . ' <select id="tmwseo-kw-classification-dry-batch">';
        foreach ([ 25, 50, 100 ] as $size) {
            echo '<option value="' . esc_attr((string) $size) . '"' . selected($size, 50, false) . '>' . esc_html((string) $size) . '</option>';
        }
        echo '</select></label> ';
        echo '<button type="button" class="button button-primary" id="tmwseo-kw-classification-dry-run">' . esc_html__( 'Run Dry Run Preview', 'tmwseo' ) . '</button>';
        echo '<div id="tmwseo-kw-classification-dry-result" style="margin-top:12px;"></div></div>';

        echo '<div class="postbox" style="padding:12px;"><h3 style="margin-top:0;">' . esc_html__( 'Apply Missing Classification Metadata', 'tmwseo' ) . '</h3>';
        echo '<p class="description">' . esc_html__( 'Apply runs against rows missing classification metadata only. Review the dry run preview first.', 'tmwseo' ) . '</p>';
        echo '<label>' . esc_html__( 'Batch size', 'tmwseo' ) . ' <select id="tmwseo-kw-classification-apply-batch">';
        foreach ([ 50, 100, 250 ] as $size) {
            echo '<option value="' . esc_attr((string) $size) . '"' . selected($size, 100, false) . '>' . esc_html((string) $size) . '</option>';
        }
        echo '</select></label> ';
        echo '<button type="button" class="button button-secondary" id="tmwseo-kw-classification-apply">' . esc_html__( 'Apply Next Batch (Missing Classification Only)', 'tmwseo' ) . '</button>';
        echo '<div id="tmwseo-kw-classification-apply-result" style="margin-top:12px;"></div></div>';
        echo '</div></div>';

        self::render_keyword_classification_apply_js();
    }

    /** @param array<string,int> $rows */
    private static function render_keyword_classification_distribution_table(string $title, array $rows): void {
        echo '<div class="tmwui-table-wrap"><h3>' . esc_html($title) . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Value', 'tmwseo' ) . '</th><th>' . esc_html__( 'Rows', 'tmwseo' ) . '</th></tr></thead><tbody>';
        if ([] === $rows) {
            echo '<tr><td colspan="2">' . esc_html__( 'No items found.', 'tmwseo' ) . '</td></tr>';
        } else {
            foreach ($rows as $value => $count) {
                echo '<tr><td><code>' . esc_html((string) $value) . '</code></td><td>' . esc_html((string) (int) $count) . '</td></tr>';
            }
        }
        echo '</tbody></table></div>';
    }

    private static function render_keyword_classification_apply_js(): void {
        ?>
        <script>
        (function(){
            const root = document.getElementById('tmwseo-kw-classification-workflow');
            if (!root) { return; }
            const ajaxUrl = root.getAttribute('data-ajax-url');
            const nonce = root.getAttribute('data-nonce');
            let offset = 0;
            let dryFilter = 'missing';
            let dryBatchSize = 50;
            const esc = function(value) {
                return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function(ch) {
                    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[ch];
                });
            };
            const showError = function(box, message) {
                box.innerHTML = '<div class="notice notice-error inline"><p>' + esc(message || 'Request failed. Please refresh the page and try again.') + '</p></div>';
            };
            const post = function(data) {
                const body = new URLSearchParams(data);
                body.set('nonce', nonce);
                return fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body.toString()
                }).then(function(resp){
                    return resp.text().then(function(text){
                        let payload = null;
                        try {
                            payload = text ? JSON.parse(text) : null;
                        } catch (error) {
                            if (!resp.ok) {
                                throw new Error('Request failed with HTTP ' + resp.status + '.');
                            }
                            throw new Error('The server returned an unreadable response. Please try again.');
                        }
                        if (!resp.ok) {
                            const message = payload && payload.data && payload.data.message ? payload.data.message : 'Request failed with HTTP ' + resp.status + '.';
                            throw new Error(message);
                        }
                        if (!payload || payload.success !== true) {
                            const message = payload && payload.data && payload.data.message ? payload.data.message : 'The request did not complete successfully.';
                            throw new Error(message);
                        }
                        return payload;
                    });
                });
            };
            const renderDry = function(payload) {
                const box = document.getElementById('tmwseo-kw-classification-dry-result');
                const data = payload && payload.data ? payload.data : null;
                if (!data) { showError(box, 'Dry run failed.'); return; }
                let html = '<p><strong>' + esc(data.rows.length) + '</strong> rows shown from <strong>' + esc(data.total) + '</strong> matching rows. Offset ' + esc(data.offset) + '.</p>';
                if (data.total_is_exact === false) {
                    html += '<p class="description">Total is a lower-bound for this classification filter to avoid scanning the full table.</p>';
                }
                html += '<div class="tmwui-table-wrap"><table class="widefat striped"><thead><tr>';
                ['ID','Keyword','Status','Entity Type','Entity ID','Model Name Context','Already Classified','Proposed KW Class','Proposed Usage','Proposed Standalone','Reason Codes','Confidence'].forEach(function(h){ html += '<th>' + esc(h) + '</th>'; });
                html += '</tr></thead><tbody>';
                if (!data.rows.length) {
                    html += '<tr><td colspan="12">No rows found.</td></tr>';
                } else {
                    data.rows.forEach(function(row){
                        html += '<tr><td>' + esc(row.id) + '</td><td><strong>' + esc(row.keyword) + '</strong></td><td>' + esc(row.current_status) + '</td><td>' + esc(row.entity_type) + '</td><td>' + esc(row.entity_id) + '</td><td>' + esc(row.model_name_context) + '</td><td>' + esc(row.already_classified ? 'yes' : 'no') + '</td><td><code>' + esc(row.proposed_keyword_class) + '</code></td><td><code>' + esc(row.proposed_suggested_usage) + '</code></td><td>' + esc(row.proposed_standalone_allowed ? 'yes' : 'no') + '</td><td><code>' + esc(row.proposed_reason_codes) + '</code></td><td>' + esc(row.proposed_confidence) + '</td></tr>';
                    });
                }
                html += '</tbody></table></div>';
                if ((data.offset + data.batch_size) < data.total) {
                    html += '<p><button type="button" class="button" id="tmwseo-kw-classification-next">Load Next Batch</button></p>';
                }
                box.innerHTML = html;
                const next = document.getElementById('tmwseo-kw-classification-next');
                if (next) { next.addEventListener('click', function(){ offset += dryBatchSize; runDry(); }); }
            };
            const runDry = function() {
                const box = document.getElementById('tmwseo-kw-classification-dry-result');
                box.innerHTML = '<p>Loading dry-run preview…</p>';
                post({ action: 'tmwseo_kw_classification_dry_run', offset: offset, batch_size: dryBatchSize, filter: dryFilter })
                    .then(renderDry)
                    .catch(function(error){ showError(box, error.message); });
            };
            document.getElementById('tmwseo-kw-classification-dry-run').addEventListener('click', function(){
                offset = 0;
                dryFilter = document.getElementById('tmwseo-kw-classification-filter').value;
                dryBatchSize = parseInt(document.getElementById('tmwseo-kw-classification-dry-batch').value, 10) || 50;
                runDry();
            });
            document.getElementById('tmwseo-kw-classification-apply').addEventListener('click', function(){
                const box = document.getElementById('tmwseo-kw-classification-apply-result');
                box.innerHTML = '<p>Applying…</p>';
                post({ action: 'tmwseo_kw_classification_apply_batch', auto_fetch_missing: '1', batch_size: document.getElementById('tmwseo-kw-classification-apply-batch').value })
                    .then(function(payload){
                        const d = payload.data;
                        box.innerHTML = '<div class="notice notice-success inline"><p>Scanned: ' + esc(d.scanned) + ' · Classified: ' + esc(d.classified) + ' · Already classified: ' + esc(d.skipped_already_classified) + ' · Not model: ' + esc(d.skipped_not_model) + ' · Empty: ' + esc(d.skipped_empty) + ' · Errors: ' + esc(d.errors) + '</p></div>';
                    })
                    .catch(function(error){ showError(box, error.message); });
            });
        })();
        </script>
        <?php
    }

    // ── Ranking Probability page ───────────────────────────────────────────

    // ── Search Intelligence hub ───────────────────────────────────────────
    // Lightweight overview page. Renders cards linking to the four tools.
    // No logic, no state, no duplicate rendering — pure navigation aid.
    public static function render_search_intelligence_hub(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $tools = [
            [
                'slug'  => 'tmwseo-serp-analyzer',
                'icon'  => '📊',
                'title' => __( 'SERP Analyzer', 'tmwseo' ),
                'desc'  => __( 'Reverse-engineer a live SERP. See what ranks, why it ranks, and what a competitor page does well.', 'tmwseo' ),
            ],
            [
                'slug'  => 'tmwseo-serp-gaps',
                'icon'  => '🎯',
                'title' => __( 'SERP Keyword Gaps', 'tmwseo' ),
                'desc'  => __( 'Find queries where ranking pages only partially satisfy the search — revealing exact-match and modifier opportunities.', 'tmwseo' ),
            ],
            [
                'slug'  => 'tmwseo-content-gap',
                'icon'  => '🏴',
                'title' => __( 'Content Gap', 'tmwseo' ),
                'desc'  => __( 'Identify keywords competitors rank for that your site does not yet target.', 'tmwseo' ),
            ],
            [
                'slug'  => 'tmwseo-competitor-domains',
                'icon'  => '🔭',
                'title' => __( 'Competitor Domains', 'tmwseo' ),
                'desc'  => __( 'Manage tracked competitor domains used for gap analysis and threat detection.', 'tmwseo' ),
            ],
        ];

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Search Intelligence', 'tmwseo' ) . '</h1>';
        echo '<p class="description">' . esc_html__( 'Four tools for analysing SERPs, finding keyword gaps, and tracking what competitors rank for.', 'tmwseo' ) . '</p>';
        echo '<div style="display:flex;flex-wrap:wrap;gap:20px;margin-top:24px;">';

        foreach ( $tools as $tool ) {
            $url = esc_url( admin_url( 'admin.php?page=' . $tool['slug'] ) );
            echo '<a href="' . $url . '" style="text-decoration:none;flex:1;min-width:220px;max-width:340px;">';
            echo '<div class="tmwseo-card" style="padding:22px 24px;height:100%;box-sizing:border-box;border:1px solid #ddd;border-radius:4px;background:#fff;transition:box-shadow .15s;"';
            echo ' onmouseover="this.style.boxShadow=\'0 2px 8px rgba(0,0,0,.12)\'"';
            echo ' onmouseout="this.style.boxShadow=\'none\'">';
            echo '<div style="font-size:32px;margin-bottom:10px;">' . esc_html( $tool['icon'] ) . '</div>';
            echo '<h3 style="margin:0 0 8px;font-size:15px;color:#1d2327;">' . esc_html( $tool['title'] ) . '</h3>';
            echo '<p style="margin:0;color:#555;font-size:13px;line-height:1.5;">' . esc_html( $tool['desc'] ) . '</p>';
            echo '</div>';
            echo '</a>';
        }

        echo '</div>';
        echo '</div>';
    }

    public static function render_topic_authority(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        \TMWSEO\Engine\Admin\TopicAuthorityPage::render_page();
    }


    public static function render_ranking_probability(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'tmwseo'));
        }

        // FIX BUG-04: POST handling MUST come before any HTML output.
        // Previously wp_safe_redirect() was called after page_header() had already
        // echoed HTML, causing headers-already-sent failures and silent no-ops.
        if (isset($_POST['tmwseo_run_ranking_probability']) && check_admin_referer('tmwseo_run_ranking_probability')) {
            if (class_exists('\\TMWSEO\\Engine\\Intelligence\\RankingProbabilityOrchestrator')) {
                // Prevent PHP timeout killing the request mid-run before redirect fires.
                // run_all() loops up to 200 posts with external API calls per post.
                @set_time_limit(300); // 5 minutes — safe ceiling for bulk run
                ignore_user_abort(true); // keep running even if browser disconnects
                try {
                    \TMWSEO\Engine\Intelligence\RankingProbabilityOrchestrator::run_all();
                    wp_safe_redirect(admin_url('admin.php?page=tmwseo-ranking-probability&tmw_ran=1'));
                    exit;
                } catch (\Throwable $e) {
                    // Redirect with error flag so the page can surface it
                    wp_safe_redirect(admin_url('admin.php?page=tmwseo-ranking-probability&tmw_error=1'));
                    exit;
                }
            }
            else {
                // Orchestrator class not found — redirect with error instead of falling through silently
                wp_safe_redirect( admin_url( 'admin.php?page=tmwseo-ranking-probability&tmw_error=1' ) );
                exit;
            }
        }

        global $wpdb;


        $intel_table = $wpdb->prefix . 'tmw_seo_ranking_probability';

        $rows     = [];
        $last_run = '';

        if ($wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $intel_table ) ) === $intel_table) {
            $table = \TMWSEO\Engine\Intelligence\IntelligenceStorage::table_ranking_probability();

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id,keyword, ranking_probability, ranking_tier, inputs_json, created_at
                 FROM {$table}
                 ORDER BY ranking_probability DESC, created_at DESC
                 LIMIT 50",
                'ranking_probability'
            ), ARRAY_A) ?: [];

            // Most recent computation timestamp — use prepare for consistency
            $last_run = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(created_at) FROM {$intel_table}",
                'ranking_probability'
            ));
        }

        // ── Page shell ───────────────────────────────────────────────────────
        echo '<div class="wrap">';
        AdminUI::page_header(
            __('Ranking Probability', 'tmwseo'),
            __('Estimated ranking scores from multiple signals. Read-only — nothing is published.', 'tmwseo')
        );

        AdminUI::trust_reminder(
            __('Read-only intelligence. This page does not publish, modify, or schedule anything automatically. All data is generated on demand via the Run button below.', 'tmwseo')
        );

        // ── Action row ───────────────────────────────────────────────────────
        echo '<div class="tmwui-cta-row">';
        echo '<form method="post">';
        wp_nonce_field('tmwseo_run_ranking_probability');
        echo '<input type="hidden" name="tmwseo_run_ranking_probability" value="1">';
        echo '<button class="button button-primary">&#9654; ' . esc_html__('Run Ranking Probability Scan', 'tmwseo') . '</button>';
        echo '</form>';
        if ($last_run) {
            echo '<span class="tmwui-table-meta">' . esc_html__('Last computed:', 'tmwseo') . ' ' . esc_html(substr($last_run, 0, 16)) . '</span>';
        } else {
            echo '<span class="tmwui-data-label">' . esc_html__('No data yet — click Run to generate scores.', 'tmwseo') . '</span>';
        }
        echo '<a href="' . esc_url(admin_url('admin.php?page=tmwseo-tools')) . '" class="button">' . esc_html__('← Back to Tools', 'tmwseo') . '</a>';
        echo '</div>';

        // ── Success / error notices ───────────────────────────────────────────
        if (isset($_GET['tmw_ran'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Ranking probability scan completed. Scores updated below.', 'tmwseo') . '</p></div>';
        }
        if (isset($_GET['tmw_error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__('Ranking probability scan encountered an error. If the page went blank, the scan may have exceeded the server time limit — try again or contact your host to increase max_execution_time.', 'tmwseo')
                . '</p></div>';
        }

        // ── Results ──────────────────────────────────────────────────────────
        AdminUI::section_start( __('Ranking Scores', 'tmwseo') );
        if (empty($rows)) {
            AdminUI::empty_state(
                __('No ranking probability scores yet. Click "Run Ranking Probability Scan" above to generate them.', 'tmwseo')
            );
        } else {
            echo '<p class="tmwui-table-meta">' . esc_html(count($rows)) . ' ' . esc_html__('pages scored. Showing top 50 by probability.', 'tmwseo') . '</p>';
            echo '<div class="tmwui-table-wrap">';
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Keyword', 'tmwseo') . '</th>';
            echo '<th>' . esc_html__('Probability Ranking', 'tmwseo') . '</th>';
            echo '<th>' . esc_html__('Ranking Tier', 'tmwseo') . '</th>';
            echo '<th>' . esc_html__('Input Json', 'tmwseo') . '</th>';
            echo '<th>' . esc_html__('Created at', 'tmwseo') . '</th>';
            echo '<th>' . esc_html__('Actions', 'tmwseo') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($rows as $row) {
                global $wpdb;
                $post_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta}
                     WHERE meta_key = 'rank_math_focus_keyword'
                     AND meta_value = %s
                     LIMIT 1",
                    $row['keyword']
                ) );

                $pid   = (int) $row['id'];
                $prob  = ((float) $row['ranking_probability']);
                $ranking_tier  = $row['ranking_tier'];
                $date  = substr((string) $row['created_at'], 0, 10);
                $title     = $post_id > 0 ? get_the_title( $post_id ) : esc_html( $row['keyword'] );
                $edit_link = $post_id > 0 ? get_edit_post_link( $post_id ) : '';
                $color_class = $prob >= 70 ? 'tmwui-prob-ok' : ($prob >= 40 ? 'tmwui-prob-warn' : 'tmwui-prob-danger');
               // $signals_json = $row['inputs_json'];

               //  $signals = json_decode($signals_json, true);
               //  $page_type_fit = is_array($signals) ? (float) ($signals['page_type_fit']['fit'] ?? 0) : 0.0;
               //  $page_type_fit_label = is_array($signals)
               //      ? (string) ($signals['page_type_fit']['status'] ?? 'unknown')
               //      : 'not_available';


                $inputs = [];
                if ( ! empty( $row['inputs_json'] ) ) {
                    $decoded = json_decode( $row['inputs_json'], true );
                    if ( is_array( $decoded ) ) {
                        $inputs = $decoded;
                    }
                }

                // Show top 3 signal scores as a short readable summary
                $summary_keys = [ 'intent_match', 'content_depth', 'internal_linking' ];
                $summary_parts = [];
                foreach ( $summary_keys as $key ) {
                    if ( isset( $inputs[ $key ] ) ) {
                        $summary_parts[] = ucwords( str_replace( '_', ' ', $key ) ) . ': ' . round( (float) $inputs[ $key ], 1 );
                    }
                }
                $inputs_summary = ! empty( $summary_parts )
                    ? implode( ' · ', $summary_parts )
                    : '—';

                echo '<tr>';
                echo '<td><a href="' . esc_url($edit_link ?: '#') . '">' . esc_html($title) . '</a></td>';
                echo '<td><strong class="tmwui-prob-score ' . esc_attr($color_class) . '">' . esc_html($prob) . '%</strong></td>';

                echo '<td><strong class="tmwui-prob-score ' . esc_attr($color_class) . '">' . esc_html($ranking_tier) . '</strong></td>';

                echo '<td class="tmwui-meta-label" title="' . esc_attr( wp_json_encode( $inputs ) ) . '">'
                    . esc_html( $inputs_summary )
                    . '</td>';
                echo '<td class="tmwui-date-cell">' . esc_html($date) . '</td>';
                echo '<td><a href="' . esc_url($edit_link ?: '#') . '" class="button button-small">' . esc_html__('Edit', 'tmwseo') . '</a></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '</div>';
        }
        AdminUI::section_end();

        echo '</div>';
    }




    // ---------- UI (alpha.8) ----------


    /**
     * Legacy overview page — renders Command Center directly.
     */
    public static function render_overview(): void {
        \TMWSEO\Engine\Admin\CommandCenter::render();
    }

    /**
     * Models page renderer — the canonical landing page for the Models sidebar item.
     *
     * Delegates to ModelHelper::render_page(), which provides:
     *   • Aggregate stats bar (total / researched / needs-SEO counts)
     *   • Searchable, paginated table of all model posts
     *   • Research status badge + confidence per row
     *   • "Research" row button (runs the enrichment pipeline)
     *   • "Open SEO Optimizer" row button (links to the ModelOptimizer metabox)
     *   • "Research Selected" bulk action
     *
     * Absolute fallback (class missing): renders a minimal valid page.
     */
    public static function render_models_redirect(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'tmwseo' ) );
        }

        if ( class_exists( '\\TMWSEO\\Engine\\Admin\\ModelHelper' ) ) {
            \TMWSEO\Engine\Admin\ModelHelper::render_page();
            return;
        }

        // ── Absolute fallback ─────────────────────────────────────────────
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Models', 'tmwseo' ) . '</h1>';
        echo '<p class="description">' . esc_html__( 'The Model Helper could not be loaded.', 'tmwseo' ) . '</p>';
        echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'edit.php?post_type=model' ) ) . '">'
            . esc_html__( 'Open Model List', 'tmwseo' ) . '</a></p>';
        echo '</div>';
    }


    public static function render_keyword_planner_test(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $keyword = '';
        $result = null;
        $did_submit = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tmwseo_gkp_test_submit'])) {
            check_admin_referer('tmwseo_gkp_test_run');
            $did_submit = true;
            $keyword = sanitize_text_field((string) wp_unslash($_POST['tmwseo_gkp_keyword'] ?? ''));
            if ($keyword === '') {
                $keyword = 'webcam models';
            }

            $result = \TMWSEO\Engine\Integrations\GoogleAdsKeywordPlannerApi::keyword_ideas_debug($keyword, 50);
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Keyword Planner API Test', 'tmwseo') . '</h1>';
        echo '<p>' . esc_html__('Run a read-only Google Ads Keyword Planner test using existing OAuth credentials.', 'tmwseo') . '</p>';

        echo '<form method="post" action="">';
        wp_nonce_field('tmwseo_gkp_test_run');
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr>';
        echo '<th scope="row"><label for="tmwseo_gkp_keyword">' . esc_html__('Keyword', 'tmwseo') . '</label></th>';
        echo '<td><input type="text" class="regular-text" id="tmwseo_gkp_keyword" name="tmwseo_gkp_keyword" value="' . esc_attr($keyword) . '" placeholder="webcam models"></td>';
        echo '</tr>';
        echo '</tbody></table>';
        submit_button(__('Run Test', 'tmwseo'), 'primary', 'tmwseo_gkp_test_submit');
        echo '</form>';

        if ($did_submit && is_array($result)) {
            if (!empty($result['ok'])) {
                $items = is_array($result['items'] ?? null) ? $result['items'] : [];
                echo '<h2>' . esc_html__('Results', 'tmwseo') . '</h2>';
                echo '<table class="widefat striped"><thead><tr>';
                echo '<th>' . esc_html__('Keyword', 'tmwseo') . '</th>';
                echo '<th>' . esc_html__('Avg Monthly Searches', 'tmwseo') . '</th>';
                echo '<th>' . esc_html__('Competition', 'tmwseo') . '</th>';
                echo '<th>' . esc_html__('Low CPC', 'tmwseo') . '</th>';
                echo '<th>' . esc_html__('High CPC', 'tmwseo') . '</th>';
                echo '</tr></thead><tbody>';

                if (empty($items)) {
                    echo '<tr><td colspan="5">' . esc_html__('No keyword ideas returned.', 'tmwseo') . '</td></tr>';
                } else {
                    foreach ($items as $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        $metrics = is_array($item['keyword_info'] ?? null) ? $item['keyword_info'] : [];
                        $competition = (float) ($metrics['competition'] ?? 0.0);
                        $low_cpc = (float) ($metrics['low_top_of_page_bid'] ?? 0.0);
                        $high_cpc = (float) ($metrics['high_top_of_page_bid'] ?? 0.0);

                        echo '<tr>';
                        echo '<td>' . esc_html((string) ($item['keyword'] ?? '')) . '</td>';
                        echo '<td>' . esc_html(number_format_i18n((int) ($metrics['search_volume'] ?? 0))) . '</td>';
                        echo '<td>' . esc_html(number_format_i18n($competition, 2)) . '</td>';
                        echo '<td>' . esc_html('$' . number_format_i18n($low_cpc, 2)) . '</td>';
                        echo '<td>' . esc_html('$' . number_format_i18n($high_cpc, 2)) . '</td>';
                        echo '</tr>';
                    }
                }

                echo '</tbody></table>';
            } else {
                echo '<div class="notice notice-error"><p><strong>' . esc_html__('Keyword Planner API request failed.', 'tmwseo') . '</strong></p>';
                echo '<p>' . esc_html__('Error message:', 'tmwseo') . ' ' . esc_html((string) ($result['message'] ?? $result['error'] ?? 'unknown_error')) . '</p>';
                echo '<p>' . esc_html__('HTTP status:', 'tmwseo') . ' ' . esc_html((string) ($result['http_status'] ?? 'n/a')) . '</p>';
                echo '<p>' . esc_html__('Google Ads error code:', 'tmwseo') . ' ' . esc_html((string) ($result['google_ads_error_code'] ?? 'n/a')) . '</p>';
                $hints = (array) ($result['diagnostic_hints'] ?? []);
                if (!empty($hints)) {
                    echo '<p><strong>' . esc_html__('Diagnostic hints:', 'tmwseo') . '</strong></p><ul style="list-style:disc;padding-left:20px;">';
                    foreach ($hints as $hint) {
                        echo '<li>' . esc_html((string) $hint) . '</li>';
                    }
                    echo '</ul>';
                }
                echo '</div>';
            }

            echo '<h2>' . esc_html__('Debug Response', 'tmwseo') . '</h2>';
            echo '<details><summary>' . esc_html__('Show raw API response', 'tmwseo') . '</summary>';
            echo '<pre style="max-height:420px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:12px;">';
            echo esc_html(wp_json_encode($result['raw_response'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            echo '</pre>';
            echo '</details>';
        }

        echo '</div>';
    }

    // ── Helper & Data Readiness ───────────────────────────────────────────
    //
    // Surfaces helper connection state, keyword corpus quality, and metric
    // coverage so the operator can immediately see what is missing, why it
    // limits suggestion quality, and the exact admin page to fix it.
    //
    // Thresholds (explicit documented defaults):
    //   raw_min     = 100   fewer raw keywords = corpus too small
    //   cand_min    = 50    fewer candidates = corpus too small or over-filtered
    //   cluster_min = 5     fewer clusters = not enough topical structure
    //   vol_min_pct = 50%   <50% candidates with volume = priority is volume-blind
    //   kd_min_pct  = 50%   <50% candidates with KD = difficulty scoring unreliable
    //   comp_ok     = 1+    at least one competitor domain required for gap analysis
    //   comp_good   = 3+    three or more domains recommended for gap breadth
    //


    public static function render_competitor_mining(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'tmwseo'));
        }

        $data = CompetitorMiningService::dashboard_data();
        $domains = (array) ($data['top_domains'] ?? []);
        $keywords = (array) ($data['top_keywords'] ?? []);

        echo '<div class="wrap tmwseo-dashboard">';
        echo '<h1>' . esc_html__('Competitor Mining', 'tmwseo') . '</h1>';
        echo '<p>' . esc_html__('SERP-driven domain mining pipeline. Discovers competitor domains from seed keyword SERPs, expands their ranked keywords, filters low-value terms, and pushes candidates into clustering.', 'tmwseo') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:16px 0;">';
        wp_nonce_field('tmwseo_run_competitor_mining');
        echo '<input type="hidden" name="action" value="tmwseo_run_competitor_mining" />';
        submit_button(__('Run Competitor Mining (Background)', 'tmwseo'), 'primary', 'submit', false);
        echo '</form>';

        echo '<h2>' . esc_html__('Top discovered domains', 'tmwseo') . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Domain</th><th>SERP Hits</th><th>Best Position</th></tr></thead><tbody>';
        if (empty($domains)) {
            echo '<tr><td colspan="3">' . esc_html__('No domains discovered yet.', 'tmwseo') . '</td></tr>';
        } else {
            foreach ($domains as $row) {
                echo '<tr>';
                echo '<td>' . esc_html((string) ($row['domain'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) (int) ($row['hits'] ?? 0)) . '</td>';
                echo '<td>' . esc_html((string) (int) ($row['best_position'] ?? 0)) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';

        echo '<h2 style="margin-top:20px;">' . esc_html__('Top discovered keywords', 'tmwseo') . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Keyword</th><th>Volume</th><th>Difficulty</th><th>CPC</th><th>Opportunity score</th></tr></thead><tbody>';
        if (empty($keywords)) {
            echo '<tr><td colspan="5">' . esc_html__('No keyword opportunities discovered yet.', 'tmwseo') . '</td></tr>';
        } else {
            foreach ($keywords as $row) {
                echo '<tr>';
                echo '<td>' . esc_html((string) ($row['keyword'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) (int) ($row['volume'] ?? 0)) . '</td>';
                echo '<td>' . esc_html(number_format((float) ($row['difficulty'] ?? 0), 2)) . '</td>';
                echo '<td>$' . esc_html(number_format((float) ($row['cpc'] ?? 0), 2)) . '</td>';
                echo '<td>' . esc_html(number_format((float) ($row['opportunity_score'] ?? 0), 2)) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';

        // ── Niche SERP Mining ─────────────────────────────────────────────────
        echo '<hr style="margin:32px 0 24px;">';
        echo '<h2>' . esc_html__('Niche SERP Mining', 'tmwseo') . '</h2>';
        echo '<p>' . esc_html__('Submit niche descriptor phrases (one per line) to mine SERP domains and discover related niche terms. Discovered phrases go into the preview/candidate queue for human review — nothing is written to trusted seeds automatically.', 'tmwseo') . '</p>';
        echo '<p class="description">' . esc_html__('Example phrases: live asian cams · blonde cam girls · ebony webcam models · mature cam women', 'tmwseo') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:16px 0;">';
        wp_nonce_field('tmwseo_run_niche_serp_mining');
        echo '<input type="hidden" name="action" value="tmwseo_run_niche_serp_mining" />';
        echo '<p>';
        echo '<label for="tmwseo-niche-phrases"><strong>' . esc_html__('Niche phrases (one per line):', 'tmwseo') . '</strong></label><br>';
        echo '<textarea id="tmwseo-niche-phrases" name="niche_phrases" rows="8" cols="60" placeholder="' . esc_attr__('live asian cams', 'tmwseo') . '" style="width:100%;max-width:600px;font-family:monospace;margin-top:6px;"></textarea>';
        echo '</p>';
        echo '<p class="description" style="margin-bottom:12px;">';
        echo esc_html__('Cost estimate: ~$0.08 per phrase (1 SERP call + up to 3 domain-keyword calls). Giant cam platforms are excluded from mining targets in this lane.', 'tmwseo');
        echo ' ' . esc_html__('Discovered phrases are filtered for adult/niche relevance and origin-phrase overlap before preview. Results land in the candidate review queue — human approval is required before promotion.', 'tmwseo');
        echo '</p>';
        submit_button(__('Run Niche SERP Mining', 'tmwseo'), 'secondary', 'submit', false);
        echo '</form>';
        echo '</div>';
    }

    public static function render_keywords(): void {
        global $wpdb;
        $raw_table     = $wpdb->prefix . 'tmw_keyword_raw';
        $cand_table    = $wpdb->prefix . 'tmw_keyword_candidates';
        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';

        // ── Counts for KPI cards and tab badges ──────────────────────────────
        $raw_count     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$raw_table}" );
        $cand_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$cand_table}" );
        $cluster_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$cluster_table}" );
        $new_clusters  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$cluster_table} WHERE status='new'" );

        // Per-status counts for tabs
        $status_rows = (array) $wpdb->get_results(
            "SELECT status, COUNT(*) AS cnt FROM {$cand_table} GROUP BY status",
            ARRAY_A
        );
        $sc = []; // status → count
        foreach ( $status_rows as $r ) {
            $sc[ (string) $r['status'] ] = (int) $r['cnt'];
        }
        $approved_count        = $sc['approved']          ?? 0;
        $new_count             = $sc['new']               ?? 0;
        // Keyword candidates use `ignored` for rejected moderation actions.
        // Any legacy `rejected` rows (rare) are folded into the same bucket.
        $ignored_count         = ( $sc['ignored'] ?? 0 ) + ( $sc['rejected'] ?? 0 );
        $queued_count          = $sc['queued_for_review'] ?? 0;

        $candidate_columns = (array) $wpdb->get_results( "SHOW COLUMNS FROM {$cand_table}", ARRAY_A );
        $candidate_column_names = array_map(
            static fn( $col ) => (string) ( $col['Field'] ?? $col['field'] ?? '' ),
            $candidate_columns
        );
        $has_intent_type_column = in_array( 'intent_type', $candidate_column_names, true );
        $has_competition_column = in_array( 'competition', $candidate_column_names, true );
        $has_opportunity_column = in_array( 'opportunity', $candidate_column_names, true );
        $has_seo_score_column   = in_array( 'seo_score', $candidate_column_names, true );
        $has_sources_column     = in_array( 'sources', $candidate_column_names, true ) || in_array( 'notes', $candidate_column_names, true );
        $has_entity_type_column = in_array( 'entity_type', $candidate_column_names, true );
        $has_entity_id_column   = in_array( 'entity_id', $candidate_column_names, true );
        $unlinked_model_keyword_count = 0;
        if ( $has_intent_type_column && $has_entity_type_column && $has_entity_id_column ) {
            $unlinked_model_keyword_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$cand_table} WHERE intent_type='model' AND status='approved' AND entity_type='model' AND entity_id=0" );
        }

        $pool_counts = [ 'model' => 0, 'video' => 0, 'category' => 0 ];
        $approved_pool_counts = [ 'model' => 0, 'video' => 0, 'category' => 0 ];
        if ( $has_intent_type_column ) {
            $pool_rows = (array) $wpdb->get_results(
                "SELECT intent_type, COUNT(*) AS cnt FROM {$cand_table} WHERE intent_type IN ('model','video','category') GROUP BY intent_type",
                ARRAY_A
            );
            foreach ( $pool_rows as $row ) {
                $intent_type = (string) ( $row['intent_type'] ?? '' );
                if ( array_key_exists( $intent_type, $pool_counts ) ) {
                    $pool_counts[ $intent_type ] = (int) ( $row['cnt'] ?? 0 );
                }
            }

            $approved_pool_rows = (array) $wpdb->get_results(
                "SELECT intent_type, COUNT(*) AS cnt FROM {$cand_table} WHERE status='approved' AND intent_type IN ('model','video','category') GROUP BY intent_type",
                ARRAY_A
            );
            foreach ( $approved_pool_rows as $row ) {
                $intent_type = (string) ( $row['intent_type'] ?? '' );
                if ( array_key_exists( $intent_type, $approved_pool_counts ) ) {
                    $approved_pool_counts[ $intent_type ] = (int) ( $row['cnt'] ?? 0 );
                }
            }
        }

        // ── Active view ───────────────────────────────────────────────────────
        $allowed_views = [
            'candidates', 'all', 'raw', 'approved', 'new',
            'ignored', 'queued_for_review', 'clusters', 'classification_audit',
        ];
        $view = isset( $_GET['view'] ) ? sanitize_key( (string) $_GET['view'] ) : 'candidates';
        // `rejected` is a legacy URL alias — keyword candidates store rejected
        // moderation as `ignored`, so redirect silently to the correct tab.
        if ( $view === 'rejected' ) {
            $view = 'ignored';
        }
        if ( ! in_array( $view, $allowed_views, true ) ) {
            $view = 'candidates';
        }

        // Helper: build page URL preserving view
        $kw_url = static function ( array $args = [] ) use ( $view ): string {
            $base = [ 'page' => 'tmwseo-keywords' ];
            return esc_url( add_query_arg( array_merge( $base, $args ), admin_url( 'admin.php' ) ) );
        };
        $view_url = static function ( string $v ) use ( $kw_url ): string {
            return $kw_url( [ 'view' => $v ] );
        };

        // ── Page shell ────────────────────────────────────────────────────────
        echo '<div class="wrap">';
        AdminUI::page_header(
            __( 'TMW SEO Engine — Keywords', 'tmwseo' ),
            __( 'Keyword workflow for review. It does not auto-create or auto-publish pages.', 'tmwseo' )
        );

        // ── Clickable KPI cards ───────────────────────────────────────────────
        AdminUI::kpi_row( [
            [
                'value' => $raw_count,
                'label' => __( 'Raw Keywords', 'tmwseo' ),
                'color' => 'neutral',
                'url'   => $view_url( 'raw' ),
                'sub'   => __( 'View raw list →', 'tmwseo' ),
            ],
            [
                'value' => $cand_count,
                'label' => __( 'Candidates', 'tmwseo' ),
                'color' => 'neutral',
                'url'   => $view_url( 'candidates' ),
                'sub'   => __( 'All candidate rows →', 'tmwseo' ),
            ],
            [
                'value' => $approved_count,
                'label' => __( 'Approved', 'tmwseo' ),
                'color' => $approved_count > 0 ? 'ok' : 'neutral',
                'url'   => $view_url( 'approved' ),
                'sub'   => __( 'Approved only →', 'tmwseo' ),
            ],
            [
                'value' => $cluster_count,
                'label' => __( 'Keyword Clusters', 'tmwseo' ),
                'color' => 'neutral',
                'url'   => $view_url( 'clusters' ),
                'sub'   => sprintf( __( '%d new →', 'tmwseo' ), $new_clusters ),
            ],
        ] );

        // ── Actions row ───────────────────────────────────────────────────────
        echo '<div class="tmwui-cta-row">';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tmwseo_run_keyword_cycle' );
        echo '<input type="hidden" name="action" value="tmwseo_run_keyword_cycle">';
        submit_button( 'Refresh Suggestions', 'primary', 'submit', false );
        echo '</form>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tmwseo_verify_new_keyword_metrics' );
        echo '<input type="hidden" name="action" value="tmwseo_verify_new_keyword_metrics">';
        submit_button( 'Verify New Keyword Metrics', 'secondary', 'submit', false );
        echo '</form>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;">';
        wp_nonce_field( 'tmwseo_force_recheck_keyword_metrics' );
        echo '<input type="hidden" name="action" value="tmwseo_force_recheck_keyword_metrics">';
        submit_button( 'Force Recheck New Keyword Metrics', 'delete', 'submit', false, [ 'title' => 'Bypasses 14-day skip window and purges stale cache. Use after a provider or parser fix.' ] );
        echo '</form>';
        echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tmwseo_run_worker' ), 'tmwseo_run_worker' ) ) . '">Run Worker (healthcheck)</a>';
        echo \TMWSEO\Engine\Export\CSVExporter::button( 'current_keyword_candidates', __( 'Export Current Keywords CSV', 'tmwseo' ) );
        echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=tmwseo-kw-metrics-import' ) ) . '">' . esc_html__( 'Import Metrics CSV', 'tmwseo' ) . '</a>'; // 5.9.0
        echo '</div>';
        echo '<p class="description" style="margin-top:6px;">' . esc_html__( 'Verify: skips rows checked in the last 14 days. Force Recheck: bypasses skip window and purges stale transient cache — use after a parser/provider fix. Neither button approves or publishes anything.', 'tmwseo' ) . '</p>';

        if ( isset( $_GET['tmwseo_notice'] ) ) {
            $notice = sanitize_key( (string) $_GET['tmwseo_notice'] );
            if ( $notice === 'keyword_cleanup_preview_ready' ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Keyword cleanup preview generated. Review matches below before applying.', 'tmwseo' ) . '</p></div>';
            } elseif ( $notice === 'keyword_cleanup_applied' ) {
                $count = isset( $_GET['tmwseo_bulk_count'] ) ? max( 0, (int) $_GET['tmwseo_bulk_count'] ) : 0;
                echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( 'Safe keyword cleanup applied. %d candidate rows were marked as ignored.', 'tmwseo' ), $count ) . '</p></div>';
            } elseif ( $notice === 'keyword_cleanup_confirm_required' ) {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Please confirm cleanup before applying changes.', 'tmwseo' ) . '</p></div>';
            } elseif ( $notice === 'csv_keyword_approval_preview_ready' ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'CSV keyword approval preview generated. Review the rows below before applying.', 'tmwseo' ) . '</p></div>';
            } elseif ( $notice === 'csv_keyword_approval_applied' ) {
                $count = isset( $_GET['tmwseo_bulk_count'] ) ? max( 0, (int) $_GET['tmwseo_bulk_count'] ) : 0;
                $remaining = isset( $_GET['tmwseo_remaining'] ) ? max( 0, (int) $_GET['tmwseo_remaining'] ) : 0;
                $message = sprintf( esc_html__( 'CSV keyword approval applied. %d queued candidate rows were approved.', 'tmwseo' ), $count );
                if ( $remaining > 0 ) {
                    $message .= ' ' . sprintf( esc_html__( '%d preview-approved rows remain; run Apply again to process the next batch.', 'tmwseo' ), $remaining );
                }
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
            } elseif ( $notice === 'csv_keyword_approval_confirm_required' ) {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Please confirm CSV keyword approvals before applying changes.', 'tmwseo' ) . '</p></div>';
            } elseif ( $notice === 'csv_keyword_approval_missing_preview' ) {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'CSV approval preview expired or was not found. Upload the CSV and preview it again before applying.', 'tmwseo' ) . '</p></div>';
            } elseif ( $notice === 'csv_keyword_approval_upload_error' ) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'CSV upload could not be read. Please upload a valid .csv file with a supported keyword column.', 'tmwseo' ) . '</p></div>';
            }
        }


        // ── CSV bulk approve panel ─────────────────────────────────────────
        self::render_csv_bulk_approve_safe_keywords_panel();

        // ── Safe keyword cleanup panel ───────────────────────────────────────
        self::render_safe_keyword_cleanup_panel();

        // ── View tabs ─────────────────────────────────────────────────────────
        // Map: view slug => [ label, count or null ]
        // NOTE: keyword candidates use `ignored` as the stored status for Reject
        // actions — there is no separate `rejected` candidate status in this table.
        $tabs = [
            'candidates'       => [ __( 'All Candidates', 'tmwseo' ),    $cand_count ],
            'new'              => [ __( 'New', 'tmwseo' ),                $new_count ],
            'queued_for_review'=> [ __( 'Queued for Review', 'tmwseo' ),  $queued_count ],
            'approved'         => [ __( 'Approved', 'tmwseo' ),           $approved_count ],
            'ignored'          => [ __( 'Ignored / Rejected', 'tmwseo' ), $ignored_count ],
            'raw'              => [ __( 'Raw Keywords', 'tmwseo' ),        $raw_count ],
            'clusters'         => [ __( 'Keyword Clusters', 'tmwseo' ),   $cluster_count ],
            'classification_audit' => [ __( 'Keyword Pool Classification Audit', 'tmwseo' ), null ],
        ];

        // Normalise: 'all' aliases to 'candidates' in the tab bar
        $active_tab = ( $view === 'all' ) ? 'candidates' : $view;

        echo '<nav class="nav-tab-wrapper tmwui-view-tabs" style="margin-bottom:20px;">';
        foreach ( $tabs as $slug => [ $label, $count ] ) {
            $is_active = ( $slug === $active_tab );
            $link_url  = $view_url( $slug );
            $badge = $count !== null
                    ? ' <span class="tmwui-tab-badge' . ( $is_active ? ' tmwui-tab-badge-active' : '' ) . '">'
                    . esc_html( (string) $count ) . '</span>'
                    : '';
            $class = 'nav-tab' . ( $is_active ? ' nav-tab-active' : '' );
            echo '<a href="' . esc_url( $link_url ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . $badge . '</a>';
        }
        echo '</nav>';

        $focused_candidate_id = isset( $_GET['tmwseo_candidate_focus'] ) ? absint( $_GET['tmwseo_candidate_focus'] ) : 0;

        // ── Render content by view ────────────────────────────────────────────

        if ( $view === 'classification_audit' ) {
            self::render_keyword_candidate_classification_audit();

        } elseif ( $view === 'raw' ) {
            // ── Raw Keywords view ────────────────────────────────────────────
            AdminUI::section_start(
                __( 'Raw Keywords', 'tmwseo' ),
                __( 'Keywords ingested from all sources before de-duplication and scoring. Read-only reference.', 'tmwseo' )
            );

            $search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : '';

            // Search form
            echo '<form method="get" style="margin-bottom:12px;">';
            echo '<input type="hidden" name="page" value="tmwseo-keywords">';
            echo '<input type="hidden" name="view" value="raw">';
            echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search raw keywords…', 'tmwseo' ) . '" style="width:260px;">';
            submit_button( __( 'Search', 'tmwseo' ), 'button', 'submit', false );
            echo '</form>';

            $where_sql  = '';
            $where_args = [];
            if ( $search !== '' ) {
                $where_sql    = ' WHERE keyword LIKE %s';
                $where_args[] = '%' . $wpdb->esc_like( $search ) . '%';
            }

            $per_page     = 100;
            $current_page = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
            $offset       = ( $current_page - 1 ) * $per_page;

            $total_raw = $where_args === []
                ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$raw_table}{$where_sql}" )
                : (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$raw_table}{$where_sql}", $where_args ) );

            $raw_sql  = "SELECT id, keyword, source, volume, cpc, competition, discovered_at FROM {$raw_table}{$where_sql} ORDER BY discovered_at DESC LIMIT %d OFFSET %d";
            $raw_args = $where_args;
            $raw_args[] = $per_page;
            $raw_args[] = $offset;

            $raw_rows = (array) $wpdb->get_results( $wpdb->prepare( $raw_sql, $raw_args ), ARRAY_A );

            if ( empty( $raw_rows ) ) {
                AdminUI::empty_state( __( 'No raw keywords found.', 'tmwseo' ) );
            } else {
                $total_pages = (int) ceil( $total_raw / $per_page );

                echo '<p class="tmwui-table-meta">' . sprintf(
                    esc_html__( 'Showing %1$d–%2$d of %3$d raw keywords.', 'tmwseo' ),
                    $offset + 1,
                    min( $offset + $per_page, $total_raw ),
                    $total_raw
                ) . '</p>';

                echo '<div class="tmwui-table-wrap">';
                echo '<table class="widefat striped"><thead><tr>';
                echo '<th>' . esc_html__( 'Keyword', 'tmwseo' ) . '</th>';
                echo '<th>' . esc_html__( 'Source', 'tmwseo' ) . '</th>';
                echo '<th>' . esc_html__( 'Volume', 'tmwseo' ) . '</th>';
                echo '<th>' . esc_html__( 'CPC', 'tmwseo' ) . '</th>';
                echo '<th>' . esc_html__( 'Competition', 'tmwseo' ) . '</th>';
                echo '<th>' . esc_html__( 'Discovered', 'tmwseo' ) . '</th>';
                echo '</tr></thead><tbody>';

                foreach ( $raw_rows as $r ) {
                    echo '<tr>';
                    echo '<td><strong>' . esc_html( (string) $r['keyword'] ) . '</strong></td>';
                    echo '<td><span class="tmwui-source-badge">' . esc_html( (string) $r['source'] ) . '</span></td>';
                    echo '<td>' . ( $r['volume'] !== null ? esc_html( number_format_i18n( (int) $r['volume'] ) ) : '—' ) . '</td>';
                    echo '<td>' . ( $r['cpc'] !== null ? '$' . esc_html( number_format( (float) $r['cpc'], 2 ) ) : '—' ) . '</td>';
                    echo '<td>' . ( $r['competition'] !== null ? esc_html( number_format( (float) $r['competition'], 3 ) ) : '—' ) . '</td>';
                    echo '<td class="tmwui-date-cell">' . esc_html( substr( (string) $r['discovered_at'], 0, 16 ) ) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';

                // Simple pagination
                if ( $total_pages > 1 ) {
                    echo '<div class="tmwui-pagination">';
                    for ( $p = 1; $p <= min( $total_pages, 20 ); $p++ ) {
                        $purl = $view_url( 'raw' ) . '&paged=' . $p . ( $search !== '' ? '&s=' . urlencode( $search ) : '' );
                        if ( $p === $current_page ) {
                            echo '<strong class="tmwui-page-current">' . $p . '</strong>';
                        } else {
                            echo '<a href="' . esc_url( $purl ) . '" class="tmwui-page-link">' . $p . '</a>';
                        }
                    }
                    if ( $total_pages > 20 ) {
                        echo '<span class="tmwui-page-overflow"">…' . esc_html( (string) $total_pages ) . ' pages</span>';
                    }
                    echo '</div>';
                }
            }
            AdminUI::section_end();

        } elseif ( $view === 'clusters' ) {
            // ── Keyword Clusters view ────────────────────────────────────────
            // Source: tmw_keyword_clusters (keyword-clustering dataset).
            // NOT the legacy internal-link clusters (tmw_clusters / TMW_Cluster_Service).

            // ── Reconciler result notice ─────────────────────────────────────
            $reconcile_result = get_transient( 'tmwseo_reconcile_result' );
            if ( is_array( $reconcile_result ) ) {
                delete_transient( 'tmwseo_reconcile_result' );

                $is_dry     = $reconcile_result['mode'] === 'dry_run';
                $has_errors = ! empty( $reconcile_result['errors'] );
                $groups     = (array) ( $reconcile_result['groups'] ?? [] );
                $found      = (int) $reconcile_result['groups_found'];
                $examined   = (int) $reconcile_result['rows_examined'];

                if ( $has_errors ) {
                    $notice_class = 'notice-error';
                    $headline     = '❌ Reconciler encountered errors';
                } elseif ( $is_dry && $found === 0 ) {
                    $notice_class = 'notice-success';
                    $headline     = '✅ Dry-run complete — no duplicate groups found';
                } elseif ( $is_dry ) {
                    $notice_class = 'notice-warning';
                    $headline     = "⚠️ Dry-run complete — {$found} duplicate group(s) found across {$examined} rows. No changes made. Review each group below, then click Execute Merge if correct.";
                } elseif ( $found === 0 ) {
                    $notice_class = 'notice-success';
                    $headline     = '✅ Execute complete — no duplicate groups to merge';
                } else {
                    $notice_class = 'notice-success';
                    $headline     = '✅ Execute complete — ' . (int) $reconcile_result['merges_performed'] . ' group(s) merged, '
                        . (int) $reconcile_result['map_rows_rewired'] . ' cluster-map row(s) rewired, '
                        . (int) $reconcile_result['siblings_deleted'] . ' sibling row(s) deleted.';
                }

                echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible" style="padding:14px 16px 16px;">';
                echo '<p style="font-size:14px;font-weight:700;margin:0 0 4px;">' . esc_html( $headline ) . '</p>';
                echo '<p style="font-size:12px;color:#6b7280;margin:0 0 12px;">Rows examined: <strong>' . esc_html( (string) $examined ) . '</strong> &nbsp;|&nbsp; Duplicate groups found: <strong>' . esc_html( (string) $found ) . '</strong></p>';

                if ( $found === 0 && ! $has_errors ) {
                    echo '<p style="font-size:13px;color:#166534;margin:0;">All ' . esc_html( (string) $examined ) . ' keyword cluster rows have distinct canonical identities. No merge action is needed.</p>';
                }

                foreach ( $groups as $idx => $g ) {
                    $g_num       = $idx + 1;
                    $canonical   = (string) ( $g['canonical_base'] ?? '—' );
                    $surv_id     = (int) ( $g['survivor_id'] ?? 0 );
                    $surv_key    = (string) ( $g['survivor_key'] ?? '—' );
                    $surv_status = (string) ( $g['survivor_status'] ?? 'new' );
                    $surv_page   = (int) ( $g['survivor_page_id'] ?? 0 );
                    $surv_rep    = (string) ( $g['survivor_representative'] ?? '—' );
                    $surv_vol    = (int) ( $g['survivor_volume'] ?? 0 );
                    $sib_rows    = (array) ( $g['sibling_rows'] ?? [] );
                    $merged      = (array) ( $g['merged_data'] ?? [] );
                    $g_error     = (string) ( $g['error'] ?? '' );

                    $badge = static function ( string $st, int $pid = 0 ): string {
                        $class_map = [
                            'built'     => 'tmwui-status-built',
                            'new'       => 'tmwui-status-new',
                            'candidate' => 'tmwui-status-candidate',
                            'archived'  => 'tmwui-status-archived',
                        ];
                        $cls = $class_map[$st] ?? 'tmwui-status-candidate';
                        $c   = $map[ $st ] ?? '...';
                        $out = '<span class="tmwui-status-badge' . esc_attr( $cls ) . '">' . esc_html( $st ) . '</span>';
                        if ( $pid > 0 ) {
                            $out .= '&nbsp;<span style="font-size:11px;color:#15803d;font-weight:600;">page_id=' . (int) $pid . '</span>';
                        }
                        return $out;
                    };

                    $hdr_bg = $g_error !== '' ? '#fef2f2' : ( $is_dry ? '#fefce8' : '#f0fdf4' );
                    echo '<div class="tmwui-group-block">';

                    // Group header
                    echo '<div class="tmwui-group-header" style="background:' . esc_attr( $hdr_bg ) . ';">';
                    echo '<span style="font-weight:700;font-size:13px;">Group ' . $g_num . '</span>';
                    echo '<span style="font-size:12px;color:#374151;">Canonical identity: <code style="background:#f3f4f6;padding:1px 5px;border-radius:3px;">' . esc_html( $canonical ) . '</code></span>';
                    echo '<span style="font-size:12px;color:#6b7280;">' . (int) $g['row_count'] . ' rows will become 1</span>';
                    if ( $g_error !== '' ) {
                        echo '<span style="color:#b91c1c;font-size:12px;font-weight:600;">⚠ Error: ' . esc_html( $g_error ) . '</span>';
                    }
                    echo '</div>';

                    // Rows table
                    echo '<table class="tmwui-group-table">';
                    echo '<thead><tr>';
                    foreach ( [ 'Role', 'ID', 'cluster_key', 'Representative', 'Current Status', 'Volume' ] as $th ) {
                        $align = $th === 'Volume' ? 'right' : 'left';
                        echo '<th class="tmwui-th-' . esc_attr($align) . '">';
                    }
                    echo '</tr></thead><tbody>';

                    // Survivor
                    echo '<tr style="background:#f0fdf4;">';
                    echo '<td style="padding:7px 10px;border-bottom:1px solid #e5e7eb;white-space:nowrap;"><strong style="color:#15803d;">✔ SURVIVOR</strong><br><span style="font-size:10px;color:#6b7280;">row kept; state merged into it</span></td>';
                    echo '<td style="padding:7px 10px;border-bottom:1px solid #e5e7eb;font-family:monospace;white-space:nowrap;">#' . $surv_id . '</td>';
                    echo '<td style="padding:7px 10px;border-bottom:1px solid #e5e7eb;word-break:break-all;"><code style="background:#f3f4f6;padding:1px 4px;border-radius:3px;">' . esc_html( $surv_key ) . '</code></td>';
                    echo '<td style="padding:7px 10px;border-bottom:1px solid #e5e7eb;">' . esc_html( $surv_rep ) . '</td>';
                    echo '<td style="padding:7px 10px;border-bottom:1px solid #e5e7eb;white-space:nowrap;">' . $badge( $surv_status, $surv_page ) . '</td>';
                    echo '<td style="padding:7px 10px;border-bottom:1px solid #e5e7eb;text-align:right;">' . esc_html( number_format_i18n( $surv_vol ) ) . '</td>';
                    echo '</tr>';

                    // Siblings
                    foreach ( $sib_rows as $sib ) {
                        $sib_id     = (int) ( $sib['id'] ?? 0 );
                        $sib_key    = (string) ( $sib['cluster_key'] ?? '—' );
                        $sib_rep    = (string) ( $sib['representative'] ?? '—' );
                        $sib_status = (string) ( $sib['status'] ?? 'new' );
                        $sib_page   = (int) ( $sib['page_id'] ?? 0 );
                        $sib_vol    = (int) ( $sib['total_volume'] ?? 0 );

                        echo '<tr style="background:#fef2f2;">';
                        echo '<td style="padding:7px 10px;border-bottom:1px solid #e5e7eb;white-space:nowrap;"><strong style="color:#b91c1c;">✕ SIBLING</strong><br><span style="font-size:10px;color:#6b7280;">cluster-map rewired → survivor; row deleted</span></td>';
                        echo '<td style="padding:7px 10px;border-bottom:1px solid #e5e7eb;font-family:monospace;white-space:nowrap;">#' . $sib_id . '</td>';
                        echo '<td style="padding:7px 10px;border-bottom:1px solid #e5e7eb;word-break:break-all;"><code style="background:#f3f4f6;padding:1px 4px;border-radius:3px;">' . esc_html( $sib_key ) . '</code></td>';
                        echo '<td style="padding:7px 10px;border-bottom:1px solid #e5e7eb;">' . esc_html( $sib_rep ) . '</td>';
                        echo '<td style="padding:7px 10px;border-bottom:1px solid #e5e7eb;white-space:nowrap;">' . $badge( $sib_status, $sib_page ) . '</td>';
                        echo '<td style="padding:7px 10px;border-bottom:1px solid #e5e7eb;text-align:right;">' . esc_html( number_format_i18n( $sib_vol ) ) . '</td>';
                        echo '</tr>';
                    }

                    // After-merge preview
                    $m_status = (string) ( $merged['status'] ?? '—' );
                    $m_page   = (int) ( $merged['page_id'] ?? 0 );
                    $m_rep    = (string) ( $merged['representative'] ?? '—' );
                    $m_vol    = (int) ( $merged['total_volume'] ?? 0 );
                    echo '<tr style="background:#eff6ff;border-top:2px solid #bfdbfe;">';
                    echo '<td style="padding:7px 10px;color:#1e40af;font-weight:700;" colspan="2">After execute →</td>';
                    echo '<td style="padding:7px 10px;"><code style="background:#f3f4f6;padding:1px 4px;border-radius:3px;">' . esc_html( $surv_key ) . '</code>&nbsp;<span style="font-size:10px;color:#6b7280;">(key unchanged)</span></td>';
                    echo '<td style="padding:7px 10px;">' . esc_html( $m_rep ) . '</td>';
                    echo '<td style="padding:7px 10px;white-space:nowrap;">' . $badge( $m_status, $m_page ) . '</td>';
                    echo '<td style="padding:7px 10px;text-align:right;">' . esc_html( number_format_i18n( $m_vol ) ) . '</td>';
                    echo '</tr>';

                    echo '</tbody></table></div>';
                }

                if ( $has_errors ) {
                    echo '<ul style="margin:8px 0 0 18px;padding:0;">';
                    foreach ( (array) $reconcile_result['errors'] as $err ) {
                        echo '<li style="font-size:12px;color:#b91c1c;">' . esc_html( (string) $err ) . '</li>';
                    }
                    echo '</ul>';
                }

                echo '</div>';
            }

            AdminUI::section_start(
                __( 'Cluster Maintenance', 'tmwseo' ),
                __( 'If the same keyword appears as both new and built, run a dry-run first to preview, then execute to merge duplicates safely.', 'tmwseo' )
            );
            echo '<div class="tmwui-cta-row">';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'tmwseo_reconcile_clusters_nonce' );
            echo '<input type="hidden" name="action" value="tmwseo_reconcile_clusters">';
            echo '<input type="hidden" name="mode" value="dry">';
            echo '<button type="submit" class="button button-secondary">🔍 ' . esc_html__( 'Dry-run (report only)', 'tmwseo' ) . '</button>';
            echo '</form>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'tmwseo_reconcile_clusters_nonce' );
            echo '<input type="hidden" name="action" value="tmwseo_reconcile_clusters">';
            echo '<input type="hidden" name="mode" value="execute">';
            echo '<button type="submit" class="button" style="color:#b91c1c;border-color:#fca5a5;" onclick="return confirm(\'' . esc_js( __( 'This will merge duplicate keyword cluster rows and rewire cluster-map references. Run dry-run first to preview. Continue?', 'tmwseo' ) ) . '\')">🔧 ' . esc_html__( 'Execute Merge', 'tmwseo' ) . '</button>';
            echo '</form>';
            echo '</div>';
            AdminUI::section_end();

            AdminUI::section_start(
                __( 'Keyword Clusters', 'tmwseo' ),
                __( 'Keyword groups built by the clustering engine. Count matches the KPI card above.', 'tmwseo' )
            );

            $kw_clusters_table = new KeywordClustersTable();
            $kw_clusters_table->prepare_items();
            echo '<div class="tmwui-table-wrap">';
            echo '<form method="get">';
            echo '<input type="hidden" name="page" value="tmwseo-keywords">';
            echo '<input type="hidden" name="view" value="clusters">';
            $kw_clusters_table->search_box( __( 'Search Keyword Clusters', 'tmwseo' ), 'kw-cluster-search' );
            $kw_clusters_table->display();
            echo '</form>';
            echo '</div>';
            AdminUI::section_end();

        } else {
            // ── Candidate views (all, approved, new, ignored, queued_for_review) ──
            // Note: keyword candidates store rejected moderation as `ignored`.
            // ?view=rejected is aliased to `ignored` before reaching this block.

            // Determine status filter
            $status_map = [
                'approved'         => 'approved',
                'new'              => 'new',
                // `ignored` is the real stored status for rejected keyword candidates.
                'ignored'          => 'ignored',
                'queued_for_review'=> 'queued_for_review',
            ];
            $status_filter = $status_map[ $view ] ?? null; // null = all candidates

            $section_label = match ( $view ) {
                'approved'          => __( 'Approved Candidates', 'tmwseo' ),
                'new'               => __( 'New Candidates', 'tmwseo' ),
                'ignored'           => __( 'Ignored / Rejected Candidates', 'tmwseo' ),
                'queued_for_review' => __( 'Queued for Review', 'tmwseo' ),
                default             => __( 'All Candidates', 'tmwseo' ),
            };

            AdminUI::section_start( $section_label );
            $keywords_table = new KeywordsTable( $status_filter, $view );
            $keywords_table->prepare_items();
            $active_filters = $keywords_table->get_active_filters();
            echo '<div class="notice notice-info inline" style="margin:0 0 12px;"><p>' . esc_html__( 'Keyword candidates are stored in wp_tmw_keyword_candidates. This page is for reviewing saved keyword candidates only. Editing here does not write Rank Math, post content, Generate output, or indexing/noindex.', 'tmwseo' ) . '</p></div>';
            if ( $unlinked_model_keyword_count > 0 ) {
                echo '<div class="notice notice-warning inline" style="margin:0 0 12px;"><p><strong>' . esc_html__( 'Unlinked model keywords:', 'tmwseo' ) . '</strong> ' . esc_html__( 'Approved model keywords with Entity ID 0 cannot be used for bio automation until linked.', 'tmwseo' ) . ' ' . esc_html( sprintf( _n( '%d approved row currently needs linking.', '%d approved rows currently need linking.', $unlinked_model_keyword_count, 'tmwseo' ), $unlinked_model_keyword_count ) ) . '</p></div>';
            }

            echo '<div class="tmwui-kpi-row" style="margin:0 0 12px;">';
            $pool_count_cards = [
                __( 'All Candidates', 'tmwseo' )      => $cand_count,
                __( 'Model', 'tmwseo' )               => $pool_counts['model'],
                __( 'Video', 'tmwseo' )               => $pool_counts['video'],
                __( 'Category', 'tmwseo' )            => $pool_counts['category'],
                __( 'Approved Model', 'tmwseo' )      => $approved_pool_counts['model'],
                __( 'Approved Video', 'tmwseo' )      => $approved_pool_counts['video'],
                __( 'Approved Category', 'tmwseo' )   => $approved_pool_counts['category'],
            ];
            foreach ( $pool_count_cards as $label => $count ) {
                echo '<div class="tmwui-kpi-card" style="min-width:130px;"><strong>' . esc_html( (string) $count ) . '</strong><span>' . esc_html( (string) $label ) . '</span></div>';
            }
            echo '</div>';

            echo '<div class="tmwui-table-wrap">';
            $filter_base = [ 'page' => 'tmwseo-keywords', 'view' => 'candidates' ];
            $status_filter_args = $status_filter !== null ? [ 'status' => $status_filter ] : [];
            $active_pool = $has_intent_type_column && isset( $_GET['intent_type'] ) ? sanitize_key( (string) $_GET['intent_type'] ) : '';
            if ( ! in_array( $active_pool, [ 'model', 'video', 'category' ], true ) ) {
                $active_pool = '';
            }
            $pool_tabs = [
                ''         => [ __( 'All Pools', 'tmwseo' ), $cand_count ],
                'model'    => [ __( 'Model Keywords', 'tmwseo' ), $pool_counts['model'] ],
                'video'    => [ __( 'Video Keywords', 'tmwseo' ), $pool_counts['video'] ],
                'category' => [ __( 'Category Keywords', 'tmwseo' ), $pool_counts['category'] ],
            ];
            echo '<nav class="nav-tab-wrapper tmwseo-pool-tabs" style="margin:0 0 12px;">';
            foreach ( $pool_tabs as $pool => [ $label, $count ] ) {
                $args = array_merge( $filter_base, $status_filter_args );
                if ( $pool !== '' && $has_intent_type_column ) {
                    $args['intent_type'] = $pool;
                }
                $class = 'nav-tab' . ( $pool === $active_pool ? ' nav-tab-active' : '' );
                echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . ' <span class="tmwui-tab-badge">' . esc_html( (string) $count ) . '</span></a>';
            }
            echo '</nav>';
            if ( ! $has_intent_type_column ) {
                echo '<p class="description" style="margin:0 0 12px;">' . esc_html__( 'Pool filters are hidden because intent_type is not available on the keyword candidates table.', 'tmwseo' ) . '</p>';
            }

            $quick_links = [
                'Volume High → Low'      => array_merge( $filter_base, [ 'min_volume' => 1, 'orderby' => 'volume', 'order' => 'desc' ] ),
                'Scored With Volume'     => array_merge( $filter_base, [ 'status' => 'scored', 'min_volume' => 1, 'orderby' => 'volume', 'order' => 'desc' ] ),
                'New With Volume'        => array_merge( $filter_base, [ 'status' => 'new', 'min_volume' => 1, 'orderby' => 'volume', 'order' => 'desc' ] ),
                'Lowest KD First'        => array_merge( $filter_base, [ 'min_volume' => 1, 'orderby' => 'difficulty', 'order' => 'asc' ] ),
                'High Volume + Low KD'   => array_merge( $filter_base, [ 'min_volume' => 1, 'max_kd' => 40, 'orderby' => 'volume', 'order' => 'desc' ] ),
                'Commercial Intent'      => array_merge( $filter_base, [ 'intent' => 'commercial', 'min_volume' => 1, 'orderby' => 'volume', 'order' => 'desc' ] ),
            ];
            if ( $has_intent_type_column ) {
                $quick_links['Approved Category Keywords'] = array_merge( $filter_base, [ 'status' => 'approved', 'intent_type' => 'category', 'orderby' => 'volume', 'order' => 'desc' ] );
                $quick_links['Approved Video Keywords']    = array_merge( $filter_base, [ 'status' => 'approved', 'intent_type' => 'video', 'orderby' => 'volume', 'order' => 'desc' ] );
                $quick_links['Approved Model Keywords']    = array_merge( $filter_base, [ 'status' => 'approved', 'intent_type' => 'model', 'orderby' => 'volume', 'order' => 'desc' ] );
                $quick_links['Personal Model CSV Keywords'] = array_merge( $filter_base, [ 'intent_type' => 'model', 'model_keyword_filter' => 'personal_model_csv', 'orderby' => 'volume', 'order' => 'desc' ] );
                $quick_links['Primary Model Bio Keywords']  = array_merge( $filter_base, [ 'intent_type' => 'model', 'model_keyword_filter' => 'primary_model_bio', 'orderby' => 'volume', 'order' => 'desc' ] );
                $quick_links['Unlinked Model Keywords']     = array_merge( $filter_base, [ 'intent_type' => 'model', 'model_keyword_filter' => 'unlinked_model', 'orderby' => 'volume', 'order' => 'desc' ] );
                if ( $has_sources_column ) {
                    $quick_links['Core Model Terms'] = array_merge( $filter_base, [ 'intent_type' => 'model', 'model_keyword_filter' => 'core_model_term', 'orderby' => 'volume', 'order' => 'desc' ] );
                    $quick_links['Platform Terms'] = array_merge( $filter_base, [ 'intent_type' => 'model', 'model_keyword_filter' => 'platform_term', 'orderby' => 'volume', 'order' => 'desc' ] );
                    $quick_links['Platform Intent Terms'] = array_merge( $filter_base, [ 'intent_type' => 'model', 'model_keyword_filter' => 'platform_intent_term', 'orderby' => 'volume', 'order' => 'desc' ] );
                    $quick_links['Intent Terms'] = array_merge( $filter_base, [ 'intent_type' => 'model', 'model_keyword_filter' => 'intent_term', 'orderby' => 'volume', 'order' => 'desc' ] );
                    $quick_links['Attribute Terms'] = array_merge( $filter_base, [ 'intent_type' => 'model', 'model_keyword_filter' => 'attribute_term', 'orderby' => 'volume', 'order' => 'desc' ] );
                    $quick_links['Geo / Language Terms'] = array_merge( $filter_base, [ 'intent_type' => 'model', 'model_keyword_filter' => 'geo_language_term', 'orderby' => 'volume', 'order' => 'desc' ] );
                    $quick_links['Feature Modifiers'] = array_merge( $filter_base, [ 'intent_type' => 'model', 'model_keyword_filter' => 'feature_modifier', 'orderby' => 'volume', 'order' => 'desc' ] );
                    $quick_links['Unsafe Standalone Modifiers'] = array_merge( $filter_base, [ 'intent_type' => 'model', 'model_keyword_filter' => 'unsafe_standalone', 'orderby' => 'volume', 'order' => 'desc' ] );
                    $quick_links['Generated Fallback Keywords'] = array_merge( $filter_base, [ 'intent_type' => 'model', 'model_keyword_filter' => 'generated_fallback', 'orderby' => 'volume', 'order' => 'desc' ] );
                    $quick_links['Not Standalone Allowed'] = array_merge( $filter_base, [ 'intent_type' => 'model', 'model_keyword_filter' => 'not_standalone_allowed', 'orderby' => 'volume', 'order' => 'desc' ] );
                }
                $quick_links['Ignored Model Keywords']      = array_merge( $filter_base, [ 'status' => 'ignored', 'intent_type' => 'model', 'orderby' => 'volume', 'order' => 'desc' ] );
                $quick_links['Queued Model Keywords']       = array_merge( $filter_base, [ 'status' => 'queued_for_review', 'intent_type' => 'model', 'orderby' => 'volume', 'order' => 'desc' ] );
                $quick_links['Queued Video Keywords']      = array_merge( $filter_base, [ 'status' => 'queued_for_review', 'intent_type' => 'video', 'orderby' => 'volume', 'order' => 'desc' ] );
                $quick_links['Queued Category Keywords']   = array_merge( $filter_base, [ 'status' => 'queued_for_review', 'intent_type' => 'category', 'orderby' => 'volume', 'order' => 'desc' ] );
            }
            if ( $has_competition_column ) {
                $quick_links['High Volume + Low Competition'] = array_merge( $filter_base, [ 'min_volume' => 1, 'max_competition' => 0.35, 'orderby' => 'volume', 'order' => 'desc' ] );
            }
            if ( $has_opportunity_column ) {
                $quick_links['Golden / KWE Opportunity'] = array_merge( $filter_base, [ 'min_volume' => 1, 'min_opportunity' => 0.7, 'orderby' => 'opportunity', 'order' => 'desc' ] );
            } elseif ( $has_seo_score_column ) {
                $quick_links['Golden / KWE Opportunity'] = array_merge( $filter_base, [ 'min_volume' => 1, 'min_seo_score' => 70, 'orderby' => 'seo_score', 'order' => 'desc' ] );
            } elseif ( $has_sources_column ) {
                $quick_links['Golden / KWE Opportunity'] = array_merge( $filter_base, [ 'min_volume' => 1, 'orderby' => 'volume', 'order' => 'desc' ] );
            }
            $page_type_exists = in_array( 'page_type', $candidate_column_names, true );
            if ( $page_type_exists ) {
                $quick_links['Category Candidates'] = array_merge( $filter_base, [ 'page_type' => 'category', 'min_volume' => 1, 'orderby' => 'volume', 'order' => 'desc' ] );
            }
            $quick_links['Reset Filters'] = $filter_base;
            echo '<p style="margin:0 0 12px;display:flex;gap:8px;flex-wrap:wrap;">';
            foreach ( $quick_links as $label => $args ) {
                echo '<a class="button button-small" href="' . esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a>';
            }
            echo '</p>';
            if ( $active_filters !== [] ) {
                $parts = [];
                foreach ( $active_filters as $k => $v ) { $parts[] = $k . '=' . $v; }
                echo '<div class="notice notice-info inline"><p><strong>Filters active:</strong> ' . esc_html( implode( ', ', $parts ) ) . ' <a href="' . esc_url( add_query_arg( $filter_base, admin_url( 'admin.php' ) ) ) . '">Reset Filters</a></p></div>';
            }

            $operator_summary = $keywords_table->get_operator_summary();
            $summary_cards = [
                __( 'Total rows', 'tmwseo' ) => (int) ( $operator_summary['total_rows'] ?? 0 ),
                __( 'Approved', 'tmwseo' ) => (int) ( $operator_summary['approved'] ?? 0 ),
                __( 'Queued for review', 'tmwseo' ) => (int) ( $operator_summary['queued_for_review'] ?? 0 ),
                __( 'Rejected/Ignored', 'tmwseo' ) => (int) ( $operator_summary['rejected_ignored'] ?? 0 ),
                __( 'Blocked', 'tmwseo' ) => (int) ( $operator_summary['blocked'] ?? 0 ),
                __( 'Linked', 'tmwseo' ) => (int) ( $operator_summary['linked'] ?? 0 ),
                __( 'Errors', 'tmwseo' ) => (int) ( $operator_summary['errors'] ?? 0 ),
            ];
            echo '<div class="tmwui-kpi-row" style="margin:0 0 12px;" data-tmw-debug="TMW-SEO-KEYWORD-SIMPLE-VIEW">';
            foreach ( $summary_cards as $label => $count ) {
                echo '<div class="tmwui-kpi-card" style="min-width:120px;"><strong>' . esc_html( (string) $count ) . '</strong><span>' . esc_html( (string) $label ) . '</span></div>';
            }
            echo '</div>';
            $toggle_args = [];
            foreach ( $_GET as $key => $value ) {
                if ( ! is_scalar( $value ) ) {
                    continue;
                }
                $toggle_args[ sanitize_key( (string) $key ) ] = wp_unslash( (string) $value );
            }
            $toggle_args['page'] = 'tmwseo-keywords';
            $toggle_args['view'] = $view;
            if ( $keywords_table->is_showing_technical_details() ) {
                unset( $toggle_args['tmwseo_keyword_technical'] );
                $toggle_label = __( 'Hide technical details', 'tmwseo' );
            } else {
                $toggle_args['tmwseo_keyword_technical'] = '1';
                $toggle_label = __( 'Show technical details', 'tmwseo' );
            }
            echo '<p style="margin:0 0 12px;"><a class="button button-secondary" href="' . esc_url( add_query_arg( $toggle_args, admin_url( 'admin.php' ) ) ) . '">' . esc_html( $toggle_label ) . '</a> <span class="description">' . esc_html__( 'Default operator view hides technical keyword metadata visually only; no candidate data or moderation logic changes.', 'tmwseo' ) . '</span></p>';

            // ── Bulk-result notice ────────────────────────────────────────────
            if ( isset( $_GET['tmwseo_notice'] ) ) {
                $bulk_notice = sanitize_key( (string) $_GET['tmwseo_notice'] );
                $bulk_count  = isset( $_GET['tmwseo_bulk_count'] ) ? max( 0, (int) $_GET['tmwseo_bulk_count'] ) : 0;
                if ( 'kw_model_entity_repair_done' === $bulk_notice ) {
                    $selected = isset( $_GET['tmwseo_repair_selected'] ) ? max( 0, (int) $_GET['tmwseo_repair_selected'] ) : 0;
                    $linked = isset( $_GET['tmwseo_repair_linked'] ) ? max( 0, (int) $_GET['tmwseo_repair_linked'] ) : 0;
                    $unresolved = isset( $_GET['tmwseo_repair_unresolved'] ) ? max( 0, (int) $_GET['tmwseo_repair_unresolved'] ) : 0;
                    $ambiguous = isset( $_GET['tmwseo_repair_ambiguous'] ) ? max( 0, (int) $_GET['tmwseo_repair_ambiguous'] ) : 0;
                    echo '<div class="notice notice-success is-dismissible inline" style="margin:0 0 12px;"><p>' . esc_html( sprintf( __( 'Resolve selected complete: %1$d selected, %2$d linked, %3$d unresolved, %4$d ambiguous.', 'tmwseo' ), $selected, $linked, $unresolved, $ambiguous ) ) . '</p></div>';
                } elseif ( in_array( $bulk_notice, [ 'kw_bulk_approved', 'kw_bulk_rejected', 'kw_bulk_deleted', 'kw_bulk_empty', 'kw_bulk_unauthorized' ], true ) ) {
                    $bulk_msg = match ( $bulk_notice ) {
                        'kw_bulk_approved'   => sprintf( _n( '%d keyword approved.', '%d keywords approved.', $bulk_count, 'tmwseo' ), $bulk_count ),
                        'kw_bulk_rejected'   => sprintf( _n( '%d keyword rejected (set to ignored).', '%d keywords rejected (set to ignored).', $bulk_count, 'tmwseo' ), $bulk_count ),
                        'kw_bulk_deleted'    => sprintf( _n( '%d keyword deleted.', '%d keywords deleted.', $bulk_count, 'tmwseo' ), $bulk_count ),
                        'kw_bulk_empty'      => __( 'No rows selected. Please check at least one keyword before applying a bulk action.', 'tmwseo' ),
                        'kw_bulk_unauthorized' => __( 'Bulk action failed: insufficient permissions.', 'tmwseo' ),
                        default              => '',
                    };
                    $bulk_level = in_array( $bulk_notice, [ 'kw_bulk_empty', 'kw_bulk_unauthorized' ], true ) ? 'notice-warning' : 'notice-success';
                    if ( $bulk_msg !== '' ) {
                        echo '<div class="notice ' . esc_attr( $bulk_level ) . ' is-dismissible inline" style="margin:0 0 12px;"><p>' . esc_html( $bulk_msg ) . '</p></div>';
                    }
                }
            }

            // ── Search form (GET — preserves view/search in URL) ──────────────
            echo '<form method="get" style="margin-bottom:4px;">';
            echo '<input type="hidden" name="page" value="tmwseo-keywords">';
            echo '<input type="hidden" name="view" value="' . esc_attr( $view ) . '">';
            if ( $keywords_table->is_showing_technical_details() ) { echo '<input type="hidden" name="tmwseo_keyword_technical" value="1">'; }
            foreach ( $active_filters as $k => $v ) {
                if ( in_array( $k, [ 'page', 'view', 's' ], true ) ) { continue; }
                echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
            }
            $keywords_table->search_box( __( 'Search Keywords', 'tmwseo' ), 'keyword-search' );
            echo '</form>';

            // ── Bulk-action form (POST — enables reliable checkbox submission) ─
            // Separate from the search form so GET-based search and POST-based
            // bulk actions do not conflict.  The load-{hook} handler processes
            // POSTs before admin-header.php is output, allowing a clean redirect.
            $search_val = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : '';
            $paged_val  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
            echo '<form method="post" id="tmwseo-kw-bulk-form">';
            echo '<input type="hidden" name="page" value="tmwseo-keywords">';
            echo '<input type="hidden" name="view" value="' . esc_attr( $view ) . '">';
            if ( $keywords_table->is_showing_technical_details() ) { echo '<input type="hidden" name="_tmwseo_keyword_technical" value="1">'; }
            echo '<input type="hidden" name="_s" value="' . esc_attr( $search_val ) . '">';
            echo '<input type="hidden" name="_paged" value="' . esc_attr( (string) $paged_val ) . '">';
            foreach ( $active_filters as $k => $v ) {
                if ( in_array( $k, [ 'page', 'view', 's' ], true ) ) { continue; }
                echo '<input type="hidden" name="_filter_' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
            }
            // wp_nonce_field so our handler can verify; WP_List_Table also outputs
            // its own bulk-keywords nonce inside display() — both target same action.
            wp_nonce_field( 'bulk-keywords' );
            $keywords_table->display();
            echo '</form>';
            echo '</div>';

            if ( $focused_candidate_id > 0 ) {
                echo '<script>(function(){var row=document.getElementById(' . wp_json_encode( 'tmw-candidate-' . $focused_candidate_id ) . ');if(row){row.style.outline="2px solid #2271b1";row.style.outlineOffset="-2px";row.style.background="#f0f6fc";row.scrollIntoView({behavior:"smooth",block:"center"});}})();</script>';
            }

            echo '<script>(function(){if(window.tmwCandidateCopyBound){return;}window.tmwCandidateCopyBound=true;document.addEventListener("click",function(event){var button=event.target&&event.target.closest("[data-tmw-copy-keyword]");if(!button){return;}var keyword=(button.getAttribute("data-tmw-copy-keyword")||"").trim();if(keyword===""){return;}var previous=button.textContent;var showResult=function(text){button.textContent=text;window.setTimeout(function(){button.textContent=previous;},1200);};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(keyword).then(function(){showResult("Copied");}).catch(function(){showResult("Copy keyword manually");});return;}var helper=document.createElement("textarea");helper.value=keyword;helper.setAttribute("readonly","");helper.style.position="absolute";helper.style.left="-9999px";document.body.appendChild(helper);helper.select();try{document.execCommand("copy");showResult("Copied");}catch(error){showResult("Copy keyword manually");}document.body.removeChild(helper);});})();</script>';

            echo '<p class="description">' . esc_html__( 'Approve sets candidate status to approved. Reject sets candidate status to ignored. Use bulk actions for faster moderation.', 'tmwseo' ) . '</p>';
            AdminUI::section_end();

            // ── Keyword Clusters summary on default (candidates/all) view ──
            if ( in_array( $view, [ 'candidates', 'all' ], true ) ) {
                AdminUI::section_start(
                    __( 'Top Keyword Clusters', 'tmwseo' ),
                    __( 'Highest-opportunity keyword clusters. Click the Keyword Clusters tab for the full list.', 'tmwseo' )
                );
                $kw_clusters_table = new KeywordClustersTable();
                $kw_clusters_table->prepare_items();
                echo '<div class="tmwui-table-wrap">';
                echo '<form method="get">';
                echo '<input type="hidden" name="page" value="tmwseo-keywords">';
                $kw_clusters_table->search_box( __( 'Search Keyword Clusters', 'tmwseo' ), 'kw-cluster-search' );
                $kw_clusters_table->display();
                echo '</form>';
                echo '</div>';
                AdminUI::section_end();
            }
        }

        self::footer();
    }



    private static function render_csv_bulk_approve_safe_keywords_panel(): void {
        $preview = get_transient( 'tmwseo_csv_keyword_approval_preview_' . get_current_user_id() );
        $rollback = get_transient( 'tmwseo_csv_keyword_approval_rollback_' . get_current_user_id() );
        $has_preview_token = is_array( $preview ) && ! empty( $preview['token'] );

        AdminUI::section_start(
            __( 'CSV Bulk Approve Safe Keywords', 'tmwseo' ),
            __( 'Upload a reviewed CSV and approve only matching queued model keyword candidates.', 'tmwseo' )
        );
        echo '<div class="notice notice-warning inline" style="margin:0 0 12px;"><p>' . esc_html__( 'This tool only approves existing keyword candidates from a reviewed CSV. It does not create keywords, update Rank Math, change post content, or modify indexing settings.', 'tmwseo' ) . '</p></div>';
        echo '<p class="description">' . esc_html__( 'Recommended first CSV: tmwseo-priority-safe-model-keywords-volume-100-plus-2026-05-31.csv', 'tmwseo' ) . '</p>';

        echo '<div class="tmwui-cta-row" style="align-items:flex-start;">';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tmwseo_preview_csv_keyword_approvals' );
        echo '<input type="hidden" name="action" value="tmwseo_preview_csv_keyword_approvals">';
        echo '<label for="tmwseo_csv_keyword_approvals"><strong>' . esc_html__( 'Reviewed safe keyword CSV', 'tmwseo' ) . '</strong></label><br>';
        echo '<input type="file" id="tmwseo_csv_keyword_approvals" name="tmwseo_csv_keyword_approvals" accept=".csv,text/csv" required> ';
        submit_button( __( 'Preview CSV Keyword Approvals', 'tmwseo' ), 'secondary', 'submit', false );
        echo '</form>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tmwseo_apply_csv_keyword_approvals' );
        echo '<input type="hidden" name="action" value="tmwseo_apply_csv_keyword_approvals">';
        if ( $has_preview_token ) {
            echo '<input type="hidden" name="preview_token" value="' . esc_attr( (string) $preview['token'] ) . '">';
        }
        echo '<label><input type="checkbox" name="confirm_apply" value="1"> ' . esc_html__( 'I understand this will approve matching queued keyword candidates only.', 'tmwseo' ) . '</label><br>';
        submit_button( __( 'Apply CSV Keyword Approvals', 'tmwseo' ), 'primary', 'submit', false, $has_preview_token ? [] : [ 'disabled' => 'disabled' ] );
        echo '<p class="description">' . esc_html__( 'Applies at most 250 preview-approved rows per batch.', 'tmwseo' ) . '</p>';
        echo '</form>';
        echo '</div>';

        if ( is_array( $rollback ) && ! empty( $rollback['token'] ) ) {
            $download_url = wp_nonce_url(
                add_query_arg( [ 'action' => 'tmwseo_download_csv_keyword_approval_rollback', 'token' => (string) $rollback['token'] ], admin_url( 'admin-post.php' ) ),
                'tmwseo_download_csv_keyword_approval_rollback'
            );
            echo '<p><a class="button" href="' . esc_url( $download_url ) . '">' . esc_html__( 'Download latest rollback CSV', 'tmwseo' ) . '</a></p>';
        }

        if ( is_array( $preview ) ) {
            $summary = is_array( $preview['summary'] ?? null ) ? $preview['summary'] : [];
            echo '<p><strong>' . esc_html__( 'Preview Summary', 'tmwseo' ) . '</strong></p>';
            echo '<ul>';
            $labels = [
                'total_csv_rows' => __( 'Total CSV rows', 'tmwseo' ),
                'matched_candidates' => __( 'Matched candidates', 'tmwseo' ),
                'ready_to_approve' => __( 'Safe queued candidates ready to approve', 'tmwseo' ),
                'already_approved_skipped' => __( 'Already approved skipped', 'tmwseo' ),
                'ignored_rejected_skipped' => __( 'Ignored/rejected skipped', 'tmwseo' ),
                'no_match_skipped' => __( 'No match skipped', 'tmwseo' ),
                'duplicate_matches' => __( 'Duplicate matches', 'tmwseo' ),
                'ambiguous_matches' => __( 'Ambiguous matches', 'tmwseo' ),
                'invalid_rows' => __( 'Invalid rows', 'tmwseo' ),
            ];
            foreach ( $labels as $key => $label ) {
                echo '<li>' . esc_html( $label ) . ': ' . (int) ( $summary[ $key ] ?? 0 ) . '</li>';
            }
            echo '</ul>';

            $rows = is_array( $preview['rows'] ?? null ) ? $preview['rows'] : [];
            if ( ! empty( $rows ) ) {
                echo '<div class="tmwui-table-wrap"><table class="widefat striped"><thead><tr>';
                foreach ( [ 'CSV Row', 'CSV Keyword', 'Matched Candidate ID', 'Current Candidate Keyword', 'Current Status', 'Type / Classification', 'Volume', 'KD', 'Action', 'Reason' ] as $heading ) {
                    echo '<th>' . esc_html( $heading ) . '</th>';
                }
                echo '</tr></thead><tbody>';
                foreach ( $rows as $row ) {
                    echo '<tr>';
                    echo '<td>' . (int) ( $row['row_number'] ?? 0 ) . '</td>';
                    echo '<td>' . esc_html( (string) ( $row['csv_keyword'] ?? '' ) ) . '</td>';
                    echo '<td>' . (int) ( $row['candidate_id'] ?? 0 ) . '</td>';
                    echo '<td>' . esc_html( (string) ( $row['candidate_keyword'] ?? '' ) ) . '</td>';
                    echo '<td>' . esc_html( (string) ( $row['status'] ?? '' ) ) . '</td>';
                    echo '<td>' . esc_html( (string) ( $row['type'] ?? '' ) ) . '</td>';
                    echo '<td>' . esc_html( (string) ( $row['volume'] ?? '' ) ) . '</td>';
                    echo '<td>' . esc_html( (string) ( $row['kd'] ?? '' ) ) . '</td>';
                    echo '<td><strong>' . esc_html( (string) ( $row['action'] ?? '' ) ) . '</strong></td>';
                    echo '<td>' . esc_html( (string) ( $row['reason'] ?? '' ) ) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }
        }

        AdminUI::section_end();
    }


    private static function render_safe_keyword_cleanup_panel(): void {
        $include_ignored = isset( $_GET['tmwseo_cleanup_include_ignored'] ) && (string) $_GET['tmwseo_cleanup_include_ignored'] === '1';
        $include_clusters = isset( $_GET['tmwseo_cleanup_include_clusters'] ) && (string) $_GET['tmwseo_cleanup_include_clusters'] === '1';
        $preview = get_transient( 'tmwseo_keyword_cleanup_preview_' . get_current_user_id() );

        AdminUI::section_start( __( 'Safe Keyword Cleanup', 'tmwseo' ), __( 'Preview and ignore obviously irrelevant keyword candidates. Approved keywords are never changed.', 'tmwseo' ) );
        echo '<div class="tmwui-cta-row">';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tmwseo_preview_keyword_cleanup' );
        echo '<input type="hidden" name="action" value="tmwseo_preview_keyword_cleanup">';
        echo '<label><input type="checkbox" name="include_ignored" value="1" ' . checked( $include_ignored, true, false ) . '> ' . esc_html__( 'Include already ignored rows in preview', 'tmwseo' ) . '</label><br>';
        echo '<label><input type="checkbox" name="include_clusters" value="1" disabled> ' . esc_html__( 'Also clean keyword clusters (coming later)', 'tmwseo' ) . '</label><br>';
        submit_button( __( 'Preview Cleanup', 'tmwseo' ), 'secondary', 'submit', false );
        echo '</form>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tmwseo_apply_keyword_cleanup' );
        echo '<input type="hidden" name="action" value="tmwseo_apply_keyword_cleanup">';
        echo '<input type="hidden" name="include_ignored" value="' . ( $include_ignored ? '1' : '0' ) . '">';
        echo '<input type="hidden" name="include_clusters" value="' . ( $include_clusters ? '1' : '0' ) . '">';
        echo '<label><input type="checkbox" name="confirm_apply" value="1"> ' . esc_html__( 'I understand this will mark matching non-approved keywords as ignored.', 'tmwseo' ) . '</label><br>';
        submit_button( __( 'Apply Cleanup', 'tmwseo' ), 'primary', 'submit', false );
        echo '</form>';
        echo '</div>';

        if ( is_array( $preview ) ) {
            echo '<p><strong>' . esc_html__( 'Preview Results', 'tmwseo' ) . '</strong></p>';
            echo '<ul>';
            echo '<li>' . esc_html__( 'Total candidates scanned:', 'tmwseo' ) . ' ' . (int) ( $preview['total_scanned'] ?? 0 ) . '</li>';
            echo '<li>' . esc_html__( 'Would be ignored:', 'tmwseo' ) . ' ' . (int) ( $preview['would_ignore'] ?? 0 ) . '</li>';
            echo '<li>' . esc_html__( 'Protected because approved:', 'tmwseo' ) . ' ' . (int) ( $preview['approved_protected'] ?? 0 ) . '</li>';
            echo '<li>' . esc_html__( 'Skipped already ignored:', 'tmwseo' ) . ' ' . (int) ( $preview['already_ignored'] ?? 0 ) . '</li>';
            echo '</ul>';
            $matches = is_array( $preview['matches'] ?? null ) ? $preview['matches'] : [];
            if ( ! empty( $matches ) ) {
                echo '<div class="tmwui-table-wrap"><table class="widefat striped"><thead><tr><th>Keyword</th><th>Current Status</th><th>Reason</th></tr></thead><tbody>';
                foreach ( $matches as $row ) {
                    echo '<tr><td>' . esc_html( (string) ( $row['keyword'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $row['status'] ?? '' ) ) . '</td><td><code>' . esc_html( (string) ( $row['reason'] ?? '' ) ) . '</code></td></tr>';
                }
                echo '</tbody></table></div>';
            }
        }

        AdminUI::section_end();
    }

    public static function render_generated_pages(): void {
        self::header(__('TMW SEO Engine — Drafts to Review', 'tmwseo'));

        $rows = get_posts([
            'post_type' => 'post',
            'post_status' => ['draft'],
            'posts_per_page' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                [
                    'key' => '_tmwseo_generated',
                    'value' => '1',
                    'compare' => '=',
                ],
            ],
        ]);

        // Critical visibility note.
        if ((int)get_option('blog_public') === 0) {
            echo '<div class="notice notice-warning"><p><strong>Search engines are currently discouraged (Settings → Reading).</strong> If you want to rank, you must eventually enable indexing (blog_public = 1).</p></div>';
        }

        echo '<p>All suggestions become <strong>draft + Rank Math noindex</strong>. Review each draft before publishing.</p>';

        if (empty($rows)) {
            echo '<p>No drafts yet. Review <a href="' . esc_url(admin_url('admin.php?page=tmwseo-opportunities')) . '">Opportunities</a> to create drafts manually.</p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Post</th><th>Suggestion ID</th><th>Status</th><th>Indexing</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $page_id = (int)$r->ID;
            $title = $r->post_title ?: ('Post #' . $page_id);
            $status = $r->post_status ?: '—';
            $suggestion_id = (string)get_post_meta($page_id, '_tmwseo_suggestion_id', true);

            $robots = get_post_meta($page_id, 'rank_math_robots', true);
            if (is_array($robots)) {
                $indexing = in_array('noindex', $robots, true) ? 'noindex' : 'index';
            } else {
                $robots_string = strtolower((string)$robots);
                $indexing = strpos($robots_string, 'noindex') !== false ? 'noindex' : 'index';
            }

            $view = get_permalink($page_id);
            $edit_link = admin_url('post.php?post=' . $page_id . '&action=edit');

            $actions = [];
            $actions[] = '<a href="' . esc_url($edit_link) . '">Edit</a>';
            if ($view) $actions[] = '<a href="' . esc_url($view) . '" target="_blank" rel="noopener">View</a>';

            echo '<tr>';
            echo '<td>' . esc_html($title) . '</td>';
            echo '<td>' . esc_html($suggestion_id !== '' ? $suggestion_id : '—') . '</td>';
            echo '<td>' . esc_html($status) . '</td>';
            echo '<td>' . esc_html($indexing) . '</td>';
            echo '<td>' . implode(' | ', $actions) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '</div>';
    }

    public static function render_indexing(): void {
        self::header(__('TMW SEO Engine — Indexing Log', 'tmwseo'));

        global $wpdb;
        $table = $wpdb->prefix . 'tmw_indexing';

        $rows = $wpdb->get_results(
            "SELECT url, status, provider, created_at
             FROM {$table}
             ORDER BY created_at DESC
             LIMIT 100",
            ARRAY_A
        );

        echo '<p>This is a log of URLs that were created/queued for indexing. In alpha.8 the workflow is manual (you enable indexing when ready).</p>';

        if (empty($rows)) {
            echo '<p>No indexing events yet.</p></div>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Created</th><th>Status</th><th>Provider</th><th>URL</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td>' . esc_html((string)$r['created_at']) . '</td>';
            echo '<td>' . esc_html((string)$r['status']) . '</td>';
            echo '<td>' . esc_html((string)$r['provider']) . '</td>';
            echo '<td><a href="' . esc_url((string)$r['url']) . '" target="_blank" rel="noopener">' . esc_html((string)$r['url']) . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '</div>';
    }

    public static function render_pagespeed(): void {
        self::header(__('TMW SEO Engine — PageSpeed', 'tmwseo'));

        echo '<p>Weekly PageSpeed Insights checks (homepage by default). Optional API key can be set in Settings.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0;">';
        wp_nonce_field('tmwseo_run_pagespeed_cycle');
        echo '<input type="hidden" name="action" value="tmwseo_run_pagespeed_cycle">';
        submit_button('Run PageSpeed Cycle Now', 'primary', 'submit', false);
        echo '</form>';

        global $wpdb;
        $table = $wpdb->prefix . 'tmw_pagespeed';
        $rows = $wpdb->get_results(
            "SELECT url, strategy, score, checked_at
             FROM {$table}
             ORDER BY checked_at DESC
             LIMIT 50",
            ARRAY_A
        );

        if (empty($rows)) {
            echo '<p>No PageSpeed data yet.</p></div>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Checked</th><th>Strategy</th><th>Score</th><th>URL</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td>' . esc_html((string)$r['checked_at']) . '</td>';
            echo '<td>' . esc_html((string)$r['strategy']) . '</td>';
            echo '<td>' . esc_html((string)$r['score']) . '</td>';
            echo '<td><a href="' . esc_url((string)$r['url']) . '" target="_blank" rel="noopener">' . esc_html((string)$r['url']) . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '</div>';
    }


    
    public static function render_import(): void {
        self::header(__('TMW SEO Engine — Import Keywords', 'tmwseo'));
        $rejections = get_transient('tmwseo_import_rejections');
        

        if ( isset($_GET['raw']) ) {
            echo '<div class="notice notice-success"><p>';
            echo 'Rows read: ' . intval($_GET['raw']);
            echo ' | Candidates: ' . intval($_GET['cand']);
            echo ' | Rejected: ' . intval($_GET['rej']);
            echo '</p></div>';
        }
        if ( ! empty($rejections) && is_array($rejections) ) {
            echo '<div class="notice notice-warning"><p><strong>Rejected Keywords Details:</strong></p>';

            echo '<ul style="margin-left:20px;">';

          foreach ( $rejections as $r ) {
                $keyword = $r['keyword'] ?? '';
                $reason  = $r['reason'] ?? 'unknown reason (validator did not return explanation)';

                echo '<li>';
                echo esc_html($keyword) . ' → ';
                echo '<em>' . esc_html($reason) . '</em>';
                echo '</li>';
            }

            echo '</ul></div>';
            delete_transient('tmwseo_import_rejections');
        }

        echo '<p>Import keywords from <strong>Google Keyword Planner</strong> or <strong>SEMrush</strong> (CSV). Imported keywords go through the adult relevancy filter and then can be KD-scored via DataForSEO.</p>';
        echo '<p><em>Seed CSV format supports <code>keyword,type,priority</code>. Missing columns default to <code>general</code> and <code>1</code>.</em></p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data" style="max-width:720px;">';
        wp_nonce_field('tmwseo_import_keywords');
        echo '<input type="hidden" name="action" value="tmwseo_import_keywords">';

        echo '<table class="form-table"><tr><th>CSV file</th><td><input type="file" name="keywords_csv" accept=".csv,text/csv" required></td></tr></table>';

        echo '<table class="form-table"><tr><th>Source label</th><td>';
        echo '<select name="import_source">';
        echo '<option value="keyword_planner">Google Keyword Planner</option>';
        echo '<option value="semrush">SEMrush</option>';
        echo '<option value="manual">Manual/Other</option>';
        echo '</select>';
        echo '<p class="description">This is just for logging and tracking.</p>';
        echo '</td></tr></table>';

        echo '<p><label><input type="checkbox" name="run_kd" value="1"> run keyword scoring cycle (KD + clustering). Does NOT create or publish pages.</label></p>';

        submit_button('Import Keywords', 'primary');

        echo '</form>';

        echo '</div>';
    }

    public static function import_keywords(): void {
        AdminFormHandlers::import_keywords();
    }


    public static function import_keywords_from_csv_path(string $file_path, string $source = 'manual', bool $run_kd = true): array {
        return AdminFormHandlers::import_keywords_from_csv_path($file_path, $source, $run_kd);
    }


private static function header(string $title): void {
        echo '<div class="wrap"><h1>' . esc_html($title) . '</h1>';
        if (isset($_GET['tmwseo_notice']) && $_GET['tmwseo_notice'] === 'worker_ran') {
            echo '<div class="notice notice-success"><p>Worker ran. Check Logs and Queue.</p></div>';
        }
        if (isset($_GET['updated']) && $_GET['updated'] == '1') {
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }
    }

    private static function footer(): void { echo '</div>'; }

    private static function count_posts_with_query(array $args): int {
        $query = new \WP_Query(array_merge([
            'post_type' => 'post',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'fields' => 'ids',
            'posts_per_page' => 1,
            'no_found_rows' => false,
            'ignore_sticky_posts' => true,
            'cache_results' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ], $args));

        return (int)$query->found_posts;
    }

    private static function render_stat_card(int $value, string $label): void {
        echo '<div class="tmwseo-card">';
        echo '<h3>' . esc_html(number_format_i18n($value)) . '</h3>';
        echo '<span>' . esc_html($label) . '</span>';
        echo '</div>';
    }

    public static function render_queue(): void {
        self::header(__('TMW SEO Engine — Queue', 'tmwseo'));
        $status = isset($_GET['status']) ? sanitize_text_field((string)$_GET['status']) : '';
        $jobs = Jobs::list(200, $status);

        echo '<p>Showing last 200 jobs. Filter: ';
        $base = admin_url('admin.php?page=tmwseo-queue');
        $filters = ['' => 'All', 'queued' => 'Queued', 'running' => 'Running', 'success' => 'Success', 'dead' => 'Dead'];
        foreach ($filters as $k => $label) {
            $url = $k === '' ? $base : add_query_arg(['status' => $k], $base);
            $active = ($k === $status) ? ' style="font-weight:bold"' : '';
            echo '<a href="' . esc_url($url) . '"' . $active . '>' . esc_html($label) . '</a> ';
        }
        echo '</p>';

        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Type</th><th>Entity</th><th>Status</th><th>Attempts</th><th>Run After</th><th>Last Error</th></tr></thead><tbody>';
        if (empty($jobs)) {
            echo '<tr><td colspan="7">No jobs found.</td></tr>';
        } else {
            foreach ($jobs as $j) {
                echo '<tr>';
                echo '<td>' . esc_html((string)$j['id']) . '</td>';
                echo '<td><code>' . esc_html((string)$j['type']) . '</code></td>';
                echo '<td>' . esc_html((string)$j['entity_type']) . ' ' . esc_html((string)($j['entity_id'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string)$j['status']) . '</td>';
                echo '<td>' . esc_html((string)$j['attempts']) . '</td>';
                echo '<td>' . esc_html((string)$j['run_after']) . '</td>';
                echo '<td>' . esc_html((string)($j['last_error'] ?? '')) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        self::footer();
    }

    public static function render_logs(): void {
        self::header(__('TMW SEO Engine — Logs', 'tmwseo'));
        $level = isset($_GET['level']) ? sanitize_text_field((string)$_GET['level']) : '';
        $logs = Logs::latest(200, $level);

        echo '<p>Showing last 200 logs. Filter: ';
        $base = admin_url('admin.php?page=tmwseo-logs');
        $filters = ['' => 'All', 'info' => 'Info', 'warn' => 'Warn', 'error' => 'Error', 'debug' => 'Debug'];
        foreach ($filters as $k => $label) {
            $url = $k === '' ? $base : add_query_arg(['level' => $k], $base);
            $active = ($k === $level) ? ' style="font-weight:bold"' : '';
            echo '<a href="' . esc_url($url) . '"' . $active . '>' . esc_html($label) . '</a> ';
        }
        echo '</p>';

        echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Level</th><th>Context</th><th>Message</th><th>Data</th></tr></thead><tbody>';
        if (empty($logs)) {
            echo '<tr><td colspan="5">No logs found.</td></tr>';
        } else {
            foreach ($logs as $l) {
                $data = (string)($l['data'] ?? '');
                $pretty = '';
                if ($data !== '') {
                    $decoded = json_decode($data, true);
                    $pretty = is_array($decoded) ? wp_json_encode($decoded, JSON_PRETTY_PRINT) : $data;
                }
                echo '<tr>';
                echo '<td>' . esc_html((string)$l['time']) . '</td>';
                echo '<td>' . esc_html((string)$l['level']) . '</td>';
                echo '<td>' . esc_html((string)$l['context']) . '</td>';
                echo '<td>' . esc_html((string)$l['message']) . '</td>';
                echo '<td><pre style="white-space:pre-wrap;max-width:520px;overflow:auto;">' . esc_html($pretty) . '</pre></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        self::footer();
    }

    public static function render_engine_monitor(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_POST['tmw_engine_monitor_nonce']) || !wp_verify_nonce((string)$_POST['tmw_engine_monitor_nonce'], 'tmw_engine_monitor_actions')) {
                wp_die('Invalid nonce');
            }

            if (isset($_POST['release_lock'])) {
                delete_transient('tmw_dfseo_keyword_lock');
            }

            if (isset($_POST['reset_breaker'])) {
                delete_option('tmw_keyword_engine_breaker');
            }

            if (isset($_POST['full_rebuild_projections'])) {
                \TMWSEO\Engine\Keywords\KeywordEngine::full_rebuild_projections();
            }

            if (isset($_POST['run_cycle'])) {
                if (!wp_next_scheduled('tmw_manual_cycle_event')) {
                    wp_schedule_single_event(time(), 'tmw_manual_cycle_event', [[
                        'id' => 0,
                        'payload' => [],
                    ]]);
                }
            }
        }

        $metrics = get_option('tmw_keyword_engine_metrics', []);
        $breaker = get_option('tmw_keyword_engine_breaker', []);

        $lock_time = get_transient('tmw_dfseo_keyword_lock');
        $health = 'Healthy';
        $health_color = 'green';

        $now = time();
        $last_run = $metrics['last_run'] ?? null;
        $failures = $metrics['failures'] ?? 0;
        $runtime = $metrics['runtime_seconds'] ?? 0;

        $lock_active = $lock_time && (($now - (int)$lock_time) < (10 * MINUTE_IN_SECONDS));

        if (!empty($breaker['last_triggered'])) {
            $health = 'Circuit Breaker Active';
            $health_color = 'red';
        } elseif ($lock_active && $runtime > 600) {
            $health = 'Possibly Stuck (Long Lock)';
            $health_color = 'red';
        } elseif ($failures > 2) {
            $health = 'Degraded (High Failures)';
            $health_color = 'orange';
        } elseif ($last_run && ($now - $last_run) > (2 * HOUR_IN_SECONDS)) {
            $health = 'Idle (No Recent Run)';
            $health_color = 'orange';
        }
        ?>

        <div class="wrap">
            <h1>Keyword Engine Monitor</h1>

            <div style="padding:15px;margin:15px 0;background:#f8f9fa;border-left:6px solid <?php echo esc_attr($health_color); ?>;">
                <strong>Engine Health:</strong>
                <span style="color:<?php echo esc_attr($health_color); ?>;font-weight:bold;">
                    <?php echo esc_html($health); ?>
                </span>
            </div>

            <h2>Status</h2>
            <table class="widefat striped">
                <tbody>
                    <tr>
                        <th>Lock Active</th>
                        <td><?php echo esc_html($lock_active ? 'Yes' : 'No'); ?></td>
                    </tr>
                    <tr>
                        <th>Last Run</th>
                        <td><?php echo esc_html(!empty($metrics['last_run']) ? date('Y-m-d H:i:s', (int)$metrics['last_run']) : '—'); ?></td>
                    </tr>
                    <tr>
                        <th>Runtime (seconds)</th>
                        <td><?php echo esc_html($metrics['runtime_seconds'] ?? '—'); ?></td>
                    </tr>
                    <tr>
                        <th>Inserted</th>
                        <td><?php echo esc_html($metrics['inserted'] ?? '—'); ?></td>
                    </tr>
                    <tr>
                        <th>Failures</th>
                        <td><?php echo esc_html($metrics['failures'] ?? '—'); ?></td>
                    </tr>
                    <tr>
                        <th>Circuit Breaker Active</th>
                        <td><?php echo esc_html(!empty($breaker['last_triggered']) ? 'Triggered' : 'No'); ?></td>
                    </tr>
                </tbody>
            </table>

            <h2>Controls</h2>

            <form method="post">
                <?php wp_nonce_field('tmw_engine_monitor_actions', 'tmw_engine_monitor_nonce'); ?>

                <p>
                    <button type="submit" name="release_lock" class="button">Release Lock</button>
                    <button type="submit" name="reset_breaker" class="button">Reset Circuit Breaker</button>
                    <button type="submit" name="run_cycle" class="button button-primary">Run Cycle Now</button>
                    <button type="submit" name="full_rebuild_projections" class="button">Full Rebuild Projections</button>
                </p>
            </form>

        </div>

        <?php
    }

    public static function render_settings(): void {
        self::header(__('TMW SEO Engine — Settings', 'tmwseo'));
        $opts = \TMWSEO\Engine\Services\Settings::all();

        $openai_api_key         = esc_attr((string)($opts['openai_api_key'] ?? ''));
        $openai_mode            = esc_attr((string)($opts['openai_mode'] ?? 'hybrid'));
        $openai_model_primary   = esc_attr((string)($opts['openai_model_primary'] ?? 'gpt-4o'));
        $openai_model_bulk      = esc_attr((string)($opts['openai_model_bulk'] ?? 'gpt-4o-mini'));
        $openai_model           = esc_attr((string)($opts['openai_model'] ?? $openai_model_primary));
        $brand_voice            = esc_attr((string)($opts['brand_voice'] ?? 'premium'));
        $anthropic_key          = esc_attr((string)($opts['tmwseo_anthropic_api_key'] ?? ''));
        $ai_primary             = esc_attr((string)($opts['tmwseo_ai_primary'] ?? 'openai'));
        $ai_budget              = esc_attr((string)($opts['tmwseo_openai_budget_usd'] ?? 20.0));
        $d_login                = esc_attr((string)($opts['dataforseo_login'] ?? ''));
        $d_pass                 = esc_attr((string)($opts['dataforseo_password'] ?? ''));
        $d_loc                  = esc_attr((string)($opts['dataforseo_location_code'] ?? '2840'));
        $d_lang                 = esc_attr((string)($opts['dataforseo_language_code'] ?? 'en'));
        $d_budget               = esc_attr((string)($opts['tmwseo_dataforseo_budget_usd'] ?? 20.0));
        $gsc_client_id          = esc_attr((string)($opts['gsc_client_id'] ?? ''));
        $gsc_client_secret      = esc_attr((string)($opts['gsc_client_secret'] ?? ''));
        $gsc_site_url           = esc_attr((string)($opts['gsc_site_url'] ?? ''));
        $indexing_json          = esc_textarea((string)($opts['google_indexing_service_account_json'] ?? ''));
        $indexing_post_types    = esc_attr((string)($opts['indexing_api_post_types'] ?? 'model,video,tmw_video'));
        $schema_enabled         = (bool)($opts['schema_enabled'] ?? 1);
        $schema_post_types      = esc_attr((string)($opts['schema_post_types'] ?? 'model,video,tmw_video'));
        $orphan_scan_enabled    = (bool)($opts['orphan_scan_enabled'] ?? 1);
        $model_auto_discovery   = (bool)($opts['enable_model_auto_keyword_discovery'] ?? 1);
        $safe_mode              = !empty($opts['safe_mode']);
        $dry_run_mode           = !empty($opts['tmwseo_dry_run_mode']);
        $auto_clear_noindex     = !empty($opts['auto_clear_noindex']);
        $debug_mode             = (bool)($opts['debug_mode'] ?? 0);
        $serper_api_key         = esc_attr((string)($opts['serper_api_key'] ?? ''));
        $intel_max_seeds        = esc_attr((string)($opts['intel_max_seeds'] ?? 10));
        $intel_max_keywords     = esc_attr((string)($opts['intel_max_keywords'] ?? 1000));

        echo '<p>' . esc_html__('Settings are grouped by subsystem. Save once at the bottom. The Connections page provides live status cards for each integration.', 'tmwseo') . ' <a href="' . esc_url(admin_url('admin.php?page=tmwseo-connections')) . '">' . esc_html__('Go to Connections →', 'tmwseo') . '</a></p>';

        echo '<form method="post" action="options.php">';
        settings_fields('tmwseo_settings_group');
        do_settings_sections('tmwseo_settings');

        // ── Safety (locked) ───────────────────────────────────────────────
        echo '<h2>' . esc_html__('Safety Policies (Locked)', 'tmwseo') . '</h2>';
        echo '<div style="background:#fefce8;border-left:4px solid #eab308;padding:12px 16px;margin-bottom:16px;">';
        echo '<strong>' . esc_html__('These policies are always enforced and cannot be disabled.', 'tmwseo') . '</strong>';
        echo '</div>';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Human approval', 'tmwseo') . '</th><td><input type="checkbox" checked disabled> ' . esc_html__('Always required', 'tmwseo') . '</td></tr>';
        echo '<tr><th>' . esc_html__('Manual control mode', 'tmwseo') . '</th><td><input type="checkbox" checked disabled> ' . esc_html__('Always enabled', 'tmwseo') . '</td></tr>';
        echo '<tr><th>' . esc_html__('Auto-publish', 'tmwseo') . '</th><td><input type="checkbox" disabled> ' . esc_html__('Always disabled', 'tmwseo') . '</td></tr>';
        echo '<tr><th>' . esc_html__('Auto-link insertion', 'tmwseo') . '</th><td><input type="checkbox" disabled> ' . esc_html__('Always disabled', 'tmwseo') . '</td></tr>';
        echo '</table>';

        // ── Safe Mode ─────────────────────────────────────────────────────
        echo '<h2>' . esc_html__('Safe Mode', 'tmwseo') . '</h2>';
        echo '<table class="form-table"><tr><th>' . esc_html__('Safe Mode', 'tmwseo') . '</th><td>';
        echo '<label><input type="checkbox" name="tmwseo_engine_settings[safe_mode]" value="1" ' . checked($safe_mode, true, false) . '> ' . esc_html__('Enable safe mode', 'tmwseo') . '</label>';
        echo '<p class="description">' . esc_html__('When ON: blocks Google Indexing API pings, OpenAI/AI calls, and PageSpeed cycles. Recommended until you are satisfied with your setup. Turn OFF to allow AI-powered features and indexing submissions.', 'tmwseo') . '</p>';
        echo '</td></tr></table>';

        // ── Model Discovery Scraper ───────────────────────────────────────
        $model_discovery = (bool) \TMWSEO\Engine\Services\Settings::get('model_discovery_enabled', 0);
        echo '<h2>' . esc_html__('Model Discovery Scraper', 'tmwseo') . '</h2>';
        echo '<table class="form-table"><tr><th>' . esc_html__('Enable scraper', 'tmwseo') . '</th><td>';
        echo '<label><input type="checkbox" name="tmwseo_engine_settings[model_discovery_enabled]" value="1" ' . checked($model_discovery, true, false) . '> ';
        echo esc_html__('Enable hourly model discovery from cam platforms', 'tmwseo') . '</label>';
        echo '<p class="description" style="color:#8a1a1a;">';
        echo '<strong>' . esc_html__('⚠ Default: OFF.', 'tmwseo') . '</strong> ';
        echo esc_html__('When enabled, the worker scrapes external platforms (Chaturbate, Stripchat, etc.) hourly to discover new model names. Only enable after reviewing each platform\'s Terms of Service. Consider using the Models → Research workflow instead, which uses DataForSEO SERP data — no direct scraping required.', 'tmwseo');
        echo '</p>';
        echo '</td></tr>';

        // ── Keyword Review Queue Cap ──────────────────────────────────────
        $kw_queue_cap = (int) \TMWSEO\Engine\Services\Settings::get('keyword_review_queue_cap', 200);
        echo '<tr><th>' . esc_html__('Review Queue Cap', 'tmwseo') . '</th><td>';
        echo '<input type="number" name="tmwseo_engine_settings[keyword_review_queue_cap]" value="' . (int)$kw_queue_cap . '" class="small-text" min="20" max="1000" step="10">';
        echo '<p class="description">' . esc_html__('Maximum combined items in the keyword review queue (discovery + generator tracks). Default: 200. Lower for smaller batches; raise if reviews are frequent. Hard bounds: 20–1000.', 'tmwseo') . '</p>';
        echo '</td></tr></table>';

        // ── AI Provider / Router ──────────────────────────────────────────
        echo '<h2>' . esc_html__('AI Provider', 'tmwseo') . '</h2>';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Primary provider', 'tmwseo') . '</th><td>';
        echo '<select name="tmwseo_engine_settings[tmwseo_ai_primary]">';
        echo '<option value="openai"' . selected($ai_primary, 'openai', false) . '>OpenAI (primary)</option>';
        echo '<option value="anthropic"' . selected($ai_primary, 'anthropic', false) . '>Anthropic Claude (primary)</option>';
        echo '</select>';
        echo '<p class="description">' . esc_html__('If the primary provider fails, the other is tried automatically.', 'tmwseo') . '</p>';
        echo '</td></tr>';
        echo '<tr><th>' . esc_html__('Monthly AI budget (USD)', 'tmwseo') . '</th><td>';
        echo '<input type="number" name="tmwseo_engine_settings[tmwseo_openai_budget_usd]" value="' . $ai_budget . '" class="small-text" min="0" step="1">';
        echo '<p class="description">' . esc_html__('Monthly spend cap in USD. Set to 0 for unlimited. Tracked across both providers.', 'tmwseo') . '</p>';
        echo '</td></tr>';
        echo '<tr><th>' . esc_html__('Dry-run mode', 'tmwseo') . '</th><td>';
        echo '<label><input type="checkbox" name="tmwseo_engine_settings[tmwseo_dry_run_mode]" value="1" ' . checked($dry_run_mode, true, false) . '> ' . esc_html__('Dry-run mode', 'tmwseo') . '</label>';
        echo '<p class="description">Use template generation for previews/testing and avoid OpenAI API cost. Recommended while validating workflows.</p>';
        echo '</td></tr></table>';

        // ── OpenAI ────────────────────────────────────────────────────────
        echo '<h2>' . esc_html__('OpenAI', 'tmwseo') . '</h2>';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('API Key', 'tmwseo') . '</th><td><input type="password" name="tmwseo_engine_settings[openai_api_key]" value="' . $openai_api_key . '" class="regular-text" autocomplete="off"></td></tr>';
        echo '<tr><th>' . esc_html__('Mode', 'tmwseo') . '</th><td>';
        echo '<select name="tmwseo_engine_settings[openai_mode]">';
        echo '<option value="hybrid"' . selected($openai_mode, 'hybrid', false) . '>Hybrid (recommended)</option>';
        echo '<option value="quality"' . selected($openai_mode, 'quality', false) . '>Quality (always primary)</option>';
        echo '<option value="bulk"' . selected($openai_mode, 'bulk', false) . '>Bulk (always bulk model)</option>';
        echo '</select></td></tr>';
        echo '<tr><th>' . esc_html__('Primary model', 'tmwseo') . '</th><td><input type="text" name="tmwseo_engine_settings[openai_model_primary]" value="' . $openai_model_primary . '" class="regular-text"><p class="description">Default: <code>gpt-4o</code></p></td></tr>';
        echo '<tr><th>' . esc_html__('Bulk model', 'tmwseo') . '</th><td><input type="text" name="tmwseo_engine_settings[openai_model_bulk]" value="' . $openai_model_bulk . '" class="regular-text"><p class="description">Default: <code>gpt-4o-mini</code></p></td></tr>';
        echo '<tr><th>' . esc_html__('Brand voice', 'tmwseo') . '</th><td>';
        echo '<select name="tmwseo_engine_settings[brand_voice]"><option value="premium"' . selected($brand_voice, 'premium', false) . '>Premium</option><option value="neutral"' . selected($brand_voice, 'neutral', false) . '>Neutral</option></select></td></tr>';
        echo '<input type="hidden" name="tmwseo_engine_settings[openai_model]" value="' . $openai_model . '">';
        echo '</table>';

        // ── Anthropic ────────────────────────────────────────────────────
        echo '<h2>' . esc_html__('Anthropic Claude', 'tmwseo') . '</h2>';
        echo '<table class="form-table"><tr><th>' . esc_html__('API Key', 'tmwseo') . '</th><td>';
        echo '<input type="password" name="tmwseo_engine_settings[tmwseo_anthropic_api_key]" value="' . $anthropic_key . '" class="regular-text" autocomplete="off">';
        echo '<p class="description">' . esc_html__('Required if Anthropic is your primary or fallback provider.', 'tmwseo') . '</p>';
        echo '</td></tr></table>';

        // ── Google Search Console ─────────────────────────────────────────
        echo '<h2>' . esc_html__('Google Search Console (OAuth2)', 'tmwseo') . '</h2>';
        $gsc_connected = \TMWSEO\Engine\Integrations\GSCApi::is_connected();
        if ($gsc_connected) {
            echo '<div style="background:#dcfce7;border-left:4px solid #16a34a;padding:10px 14px;margin-bottom:12px;">✓ ' . esc_html__('GSC is connected.', 'tmwseo') . ' <a href="' . esc_url(admin_url('admin.php?page=tmwseo-connections')) . '">' . esc_html__('Manage on Connections page', 'tmwseo') . '</a></div>';
        }
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Client ID', 'tmwseo') . '</th><td><input type="text" name="tmwseo_engine_settings[gsc_client_id]" value="' . $gsc_client_id . '" class="regular-text"></td></tr>';
        echo '<tr><th>' . esc_html__('Client Secret', 'tmwseo') . '</th><td><input type="password" name="tmwseo_engine_settings[gsc_client_secret]" value="' . $gsc_client_secret . '" class="regular-text" autocomplete="off"></td></tr>';
        echo '<tr><th>' . esc_html__('Verified Site URL', 'tmwseo') . '</th><td><input type="text" name="tmwseo_engine_settings[gsc_site_url]" value="' . $gsc_site_url . '" class="regular-text">';
        echo '<p class="description">' . esc_html__('Exact URL as shown in GSC (with trailing slash if required).', 'tmwseo') . '</p></td></tr>';
        echo '</table>';
        if (!$gsc_connected && !empty($gsc_client_id) && !empty($gsc_client_secret)) {
            $auth_url = \TMWSEO\Engine\Integrations\GSCApi::get_auth_url();
            echo '<p><a class="button" href="' . esc_url($auth_url) . '" target="_blank">' . esc_html__('Connect GSC →', 'tmwseo') . '</a></p>';
        }

        // ── Google Indexing API ────────────────────────────────────────────
        echo '<h2>' . esc_html__('Google Indexing API', 'tmwseo') . '</h2>';
        echo '<div style="background:#fefce8;border-left:4px solid #eab308;padding:10px 14px;margin-bottom:12px;">';
        echo esc_html__('Indexing API is only active when Safe Mode is OFF. It notifies Google when posts are published — it does not auto-publish content.', 'tmwseo');
        echo '</div>';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Service Account JSON', 'tmwseo') . '</th><td>';
        echo '<textarea name="tmwseo_engine_settings[google_indexing_service_account_json]" rows="6" class="large-text code">' . $indexing_json . '</textarea>';
        echo '<p class="description">' . esc_html__('Paste the full JSON from your Google Cloud service account key file.', 'tmwseo') . '</p>';
        echo '</td></tr>';
        echo '<tr><th>' . esc_html__('Post types to index', 'tmwseo') . '</th><td>';
        echo '<input type="text" name="tmwseo_engine_settings[indexing_api_post_types]" value="' . $indexing_post_types . '" class="regular-text">';
        echo '<p class="description">' . esc_html__('Comma-separated list of post types to notify Google about on publish.', 'tmwseo') . '</p>';
        echo '</td></tr></table>';

        // ── DataForSEO ────────────────────────────────────────────────────
        echo '<h2>' . esc_html__('DataForSEO', 'tmwseo') . '</h2>';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Login', 'tmwseo') . '</th><td><input type="text" name="tmwseo_engine_settings[dataforseo_login]" value="' . $d_login . '" class="regular-text"></td></tr>';
        echo '<tr><th>' . esc_html__('Password', 'tmwseo') . '</th><td><input type="password" name="tmwseo_engine_settings[dataforseo_password]" value="' . $d_pass . '" class="regular-text" autocomplete="off"></td></tr>';
        echo '<tr><th>' . esc_html__('Location code', 'tmwseo') . '</th><td><input type="text" name="tmwseo_engine_settings[dataforseo_location_code]" value="' . $d_loc . '" class="regular-text"><p class="description">Default: <code>2840</code> (US)</p></td></tr>';
        echo '<tr><th>' . esc_html__('Language code', 'tmwseo') . '</th><td><input type="text" name="tmwseo_engine_settings[dataforseo_language_code]" value="' . $d_lang . '" class="regular-text"><p class="description">Default: <code>en</code></p></td></tr>';
        echo '<tr><th>' . esc_html__('API Budget (USD / month)', 'tmwseo') . '</th><td><input type="number" name="tmwseo_engine_settings[tmwseo_dataforseo_budget_usd]" value="' . $d_budget . '" class="small-text" min="0" step="1"><p class="description">Default: <code>$20/month</code>. Set <code>0</code> for unlimited usage.</p></td></tr>';
        echo '</table>';

        // ── Schema ────────────────────────────────────────────────────────
        echo '<h2>' . esc_html__('JSON-LD Schema', 'tmwseo') . '</h2>';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Enable Schema', 'tmwseo') . '</th><td><label><input type="checkbox" name="tmwseo_engine_settings[schema_enabled]" value="1" ' . checked($schema_enabled, true, false) . '> ' . esc_html__('Output JSON-LD schema in &lt;head&gt;', 'tmwseo') . '</label></td></tr>';
        echo '<tr><th>' . esc_html__('Post types', 'tmwseo') . '</th><td><input type="text" name="tmwseo_engine_settings[schema_post_types]" value="' . $schema_post_types . '" class="regular-text"><p class="description">' . esc_html__('Comma-separated post types.', 'tmwseo') . '</p></td></tr>';
        echo '</table>';

        // ── Orphan Detection ──────────────────────────────────────────────
        echo '<h2>' . esc_html__('Orphan Page Detection', 'tmwseo') . '</h2>';
        echo '<table class="form-table"><tr><th>' . esc_html__('Enable detection', 'tmwseo') . '</th><td>';
        echo '<label><input type="checkbox" name="tmwseo_engine_settings[orphan_scan_enabled]" value="1" ' . checked($orphan_scan_enabled, true, false) . '> ' . esc_html__('Enable orphan page detection (AJAX scan from Tools page)', 'tmwseo') . '</label>';
        echo '</td></tr></table>';

        // ── Model Keyword Discovery ─────────────────────────────────────
        echo '<h2>' . esc_html__('Model Keyword Discovery', 'tmwseo') . '</h2>';
        echo '<table class="form-table"><tr><th>' . esc_html__('Automatic discovery', 'tmwseo') . '</th><td>';
        echo '<label><input type="checkbox" name="tmwseo_engine_settings[enable_model_auto_keyword_discovery]" value="1" ' . checked($model_auto_discovery, true, false) . '> ' . esc_html__('Enable automatic model keyword discovery', 'tmwseo') . '</label>';
        echo '<p class="description">' . esc_html__('When enabled, newly published model posts automatically generate keyword seeds and trigger discovery.', 'tmwseo') . '</p>';
        echo '</td></tr></table>';

        // ── Keyword Engine ────────────────────────────────────────────────
        echo '<h2>' . esc_html__('Keyword Engine', 'tmwseo') . '</h2>';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Competitor domains', 'tmwseo') . '</th><td><textarea name="tmwseo_engine_settings[competitor_domains]" rows="6" class="large-text code">' . esc_textarea((string)($opts['competitor_domains'] ?? '')) . '</textarea><p class="description">' . esc_html__('One domain per line. No https://. Used for competitor seeding.', 'tmwseo') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('Min search volume', 'tmwseo') . '</th><td><input type="number" name="tmwseo_engine_settings[keyword_min_volume]" value="' . esc_attr((string)($opts['keyword_min_volume'] ?? 30)) . '" class="small-text"></td></tr>';
        echo '<tr><th>' . esc_html__('Max KD', 'tmwseo') . '</th><td><input type="number" name="tmwseo_engine_settings[keyword_max_kd]" value="' . esc_attr((string)($opts['keyword_max_kd'] ?? 60)) . '" class="small-text"></td></tr>';
        echo '<tr><th>' . esc_html__('New keywords per run', 'tmwseo') . '</th><td><input type="number" name="tmwseo_engine_settings[keyword_new_limit]" value="' . esc_attr((string)($opts['keyword_new_limit'] ?? 300)) . '" class="small-text"></td></tr>';
        echo '<tr><th>' . esc_html__('Governor: Max keywords per run', 'tmwseo') . '</th><td><input type="number" name="tmwseo_engine_settings[max_keywords_per_run]" value="' . esc_attr((string)($opts['max_keywords_per_run'] ?? 500)) . '" class="small-text"></td></tr>';
        echo '<tr><th>' . esc_html__('Governor: Max keywords per day', 'tmwseo') . '</th><td><input type="number" name="tmwseo_engine_settings[max_keywords_per_day]" value="' . esc_attr((string)($opts['max_keywords_per_day'] ?? 5000)) . '" class="small-text"></td></tr>';
        echo '<tr><th>' . esc_html__('Governor: Max expansion depth', 'tmwseo') . '</th><td><input type="number" name="tmwseo_engine_settings[max_depth]" value="' . esc_attr((string)($opts['max_depth'] ?? 3)) . '" class="small-text"></td></tr>';
        echo '<tr><th>' . esc_html__('Governor: Minimum search volume', 'tmwseo') . '</th><td><input type="number" name="tmwseo_engine_settings[min_search_volume]" value="' . esc_attr((string)($opts['min_search_volume'] ?? 50)) . '" class="small-text"></td></tr>';
        echo '<tr><th>' . esc_html__('Governor: Max keywords per topic', 'tmwseo') . '</th><td><input type="number" name="tmwseo_engine_settings[max_keywords_per_topic]" value="' . esc_attr((string)($opts['max_keywords_per_topic'] ?? 300)) . '" class="small-text"></td></tr>';
        echo '<tr><th>' . esc_html__('KD batch size', 'tmwseo') . '</th><td><input type="number" name="tmwseo_engine_settings[keyword_kd_batch_limit]" value="' . esc_attr((string)($opts['keyword_kd_batch_limit'] ?? 300)) . '" class="small-text"></td></tr>';
        echo '<tr><th>' . esc_html__('Keyword Filters', 'tmwseo') . '</th><td><textarea name="tmwseo_engine_settings[keyword_negative_filters]" rows="8" class="large-text code">' . esc_textarea((string)($opts['keyword_negative_filters'] ?? "video chat
random chat
omegle
chatroulette
chat room
chatroom
stranger chat
talk to strangers")) . '</textarea><p class="description">' . esc_html__('One blocked phrase per line. Matching keywords are silently discarded before candidate insertion.', 'tmwseo') . '</p></td></tr>';
        echo '</table>';

        // ── Intelligence ──────────────────────────────────────────────────
        echo '<h2>' . esc_html__('Intelligence', 'tmwseo') . '</h2>';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Serper API key', 'tmwseo') . '</th><td><input type="password" name="tmwseo_engine_settings[serper_api_key]" value="' . $serper_api_key . '" class="regular-text" autocomplete="off"><p class="description">' . esc_html__('Optional. Enables People Also Ask keyword expansion.', 'tmwseo') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('Max seeds per run', 'tmwseo') . '</th><td><input type="number" name="tmwseo_engine_settings[intel_max_seeds]" value="' . $intel_max_seeds . '" class="small-text" min="1" max="20"></td></tr>';
        echo '<tr><th>' . esc_html__('Max keywords per run', 'tmwseo') . '</th><td><input type="number" name="tmwseo_engine_settings[intel_max_keywords]" value="' . $intel_max_keywords . '" class="small-text" min="50" max="2000"></td></tr>';
        echo '</table>';

        // ── Debug ─────────────────────────────────────────────────────────
        echo '<h2>' . esc_html__('Debug', 'tmwseo') . '</h2>';
        echo '<table class="form-table"><tr><th>' . esc_html__('Debug mode', 'tmwseo') . '</th><td>';
        echo '<label><input type="checkbox" name="tmwseo_engine_settings[debug_mode]" value="1" ' . checked($debug_mode, true, false) . '> ' . esc_html__('Enable debug logging', 'tmwseo') . '</label>';
        echo '</td></tr></table>';

        // ── Google Ads Keyword Planner ────────────────────────────────────
        $ga_enabled        = !empty($opts['google_ads_enabled']);
        $ga_dev_token      = esc_attr((string)($opts['google_ads_developer_token'] ?? ''));
        $ga_client_id      = esc_attr((string)($opts['google_ads_client_id'] ?? ''));
        $ga_client_secret  = esc_attr((string)($opts['google_ads_client_secret'] ?? ''));
        $ga_refresh_token  = esc_attr((string)($opts['google_ads_refresh_token'] ?? ''));
        $ga_customer_id    = esc_attr((string)($opts['google_ads_customer_id'] ?? ''));
        $ga_login_cid      = esc_attr((string)($opts['google_ads_login_customer_id'] ?? ''));
        echo '<h2>' . esc_html__('Google Ads Keyword Planner', 'tmwseo') . '</h2>';
        echo '<div style="background:#f0f9ff;border-left:4px solid #3b82f6;padding:10px 14px;margin-bottom:12px;">';
        echo '<p style="margin:0;">' . esc_html__('Keyword Planner enriches candidate keyword metrics (volume, CPC) via the Google Ads API. Credentials require a Google Ads developer account with a manager (MCC) customer ID and OAuth2 tokens.', 'tmwseo') . '</p>';
        echo '</div>';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Enable', 'tmwseo') . '</th><td>';
        echo '<label><input type="checkbox" name="tmwseo_engine_settings[google_ads_enabled]" value="1" ' . checked($ga_enabled, true, false) . '> ' . esc_html__('Enable Google Ads Keyword Planner integration', 'tmwseo') . '</label>';
        echo '</td></tr>';
        echo '<tr><th>' . esc_html__('Developer Token', 'tmwseo') . '</th><td><input type="password" name="tmwseo_engine_settings[google_ads_developer_token]" value="' . $ga_dev_token . '" class="regular-text" autocomplete="off"><p class="description">' . esc_html__('From your Google Ads API Center under your MCC account.', 'tmwseo') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('OAuth2 Client ID', 'tmwseo') . '</th><td><input type="text" name="tmwseo_engine_settings[google_ads_client_id]" value="' . $ga_client_id . '" class="regular-text"></td></tr>';
        echo '<tr><th>' . esc_html__('OAuth2 Client Secret', 'tmwseo') . '</th><td><input type="password" name="tmwseo_engine_settings[google_ads_client_secret]" value="' . $ga_client_secret . '" class="regular-text" autocomplete="off"></td></tr>';
        echo '<tr><th>' . esc_html__('Refresh Token', 'tmwseo') . '</th><td><input type="password" name="tmwseo_engine_settings[google_ads_refresh_token]" value="' . $ga_refresh_token . '" class="regular-text" autocomplete="off"><p class="description">' . esc_html__('Long-lived OAuth2 refresh token. Generate via the Google OAuth2 Playground.', 'tmwseo') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('Customer ID', 'tmwseo') . '</th><td><input type="text" name="tmwseo_engine_settings[google_ads_customer_id]" value="' . $ga_customer_id . '" class="regular-text"><p class="description">' . esc_html__('10-digit Google Ads Customer ID (without dashes).', 'tmwseo') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('Login Customer ID (MCC)', 'tmwseo') . '</th><td><input type="text" name="tmwseo_engine_settings[google_ads_login_customer_id]" value="' . $ga_login_cid . '" class="regular-text"><p class="description">' . esc_html__('Required if your developer token belongs to a Manager (MCC) account. Set this to the MCC account ID (without dashes). Leave blank if using a direct non-MCC account.', 'tmwseo') . '</p></td></tr>';
        echo '</table>';

        // ── Google Trends ──────────────────────────────────────────────────
        $gt_enabled   = !empty($opts['google_trends_enabled']);
        $gt_geo       = esc_attr((string)($opts['google_trends_geo'] ?? 'US'));
        $gt_locale    = esc_attr((string)($opts['google_trends_locale'] ?? 'en-US'));
        $gt_timeframe = esc_attr((string)($opts['google_trends_timeframe'] ?? 'today 3-m'));
        echo '<h2>' . esc_html__('Google Trends', 'tmwseo') . '</h2>';
        echo '<div style="background:#f0fdf4;border-left:4px solid #22c55e;padding:10px 14px;margin-bottom:12px;">';
        echo '<p style="margin:0;">' . esc_html__('Google Trends seeds daily trending queries into your candidate pool and overlays trend scores on existing candidates. No API key required — uses public RSS and explore endpoints.', 'tmwseo') . '</p>';
        echo '</div>';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Enable', 'tmwseo') . '</th><td>';
        echo '<label><input type="checkbox" name="tmwseo_engine_settings[google_trends_enabled]" value="1" ' . checked($gt_enabled, true, false) . '> ' . esc_html__('Enable Google Trends seed discovery and trend scoring', 'tmwseo') . '</label>';
        echo '</td></tr>';
        echo '<tr><th>' . esc_html__('Geo', 'tmwseo') . '</th><td><input type="text" name="tmwseo_engine_settings[google_trends_geo]" value="' . $gt_geo . '" class="small-text"><p class="description">' . esc_html__('Two-letter country code (e.g. US, GB, DE). Leave blank for worldwide.', 'tmwseo') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('Locale', 'tmwseo') . '</th><td><input type="text" name="tmwseo_engine_settings[google_trends_locale]" value="' . $gt_locale . '" class="small-text"><p class="description">' . esc_html__('BCP-47 locale for trend data (e.g. en-US, de-DE).', 'tmwseo') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('Timeframe', 'tmwseo') . '</th><td><input type="text" name="tmwseo_engine_settings[google_trends_timeframe]" value="' . $gt_timeframe . '" class="regular-text"><p class="description">' . esc_html__('Google Trends timeframe string. Examples: <code>today 3-m</code>, <code>today 12-m</code>, <code>now 7-d</code>.', 'tmwseo') . '</p></td></tr>';
        echo '</table>';

        submit_button();
        echo '</form>';

        self::footer();
    }

    public static function render_migration(): void {
        self::header(__('TMW SEO Engine — Migration', 'tmwseo'));
        echo '<p>Legacy alpha.4 option logs are auto-migrated into the new logs table on activation.</p>';
        echo '<p>If you want to re-run legacy migration, deactivate and activate the plugin (safe), or we can add a button in a later step.</p>';
        self::footer();
    }

    public static function render_affiliates(): void {
        self::header(__('TMW SEO Engine — Affiliates', 'tmwseo'));

        $per_platform = get_option('tmwseo_platform_affiliate_settings', []);
        $per_platform = is_array($per_platform) ? $per_platform : [];
        $platforms = self::get_affiliate_platform_defaults();

        // ── Affiliate Networks section ────────────────────────────────────────
        // Network-level templates are for generic URL-rewriting (e.g. Crack Revenue)
        // applied to any approved outbound link, independent of which platform
        // it originated from. These are separate from per-platform templates above.
        $networks     = get_option( 'tmwseo_affiliate_networks', [] );
        $networks     = is_array( $networks ) ? $networks : [];

        echo '<form method="post" action="options.php">';
        settings_fields( 'tmwseo_affiliate_networks_group' );

        echo '<h2>' . esc_html__( 'Affiliate Networks (URL-level routing)', 'tmwseo' ) . '</h2>';
        echo '<p class="description">'
            . esc_html__( 'Configure network-level templates for routing approved outbound links (e.g. Crack Revenue). '
                . 'These templates receive {profile_url} and {encoded_profile_url} as the approved outbound URL. '
                . 'Network keys here are available in the Verified External Links affiliate routing selector.', 'tmwseo' )
            . '</p>';
        echo '<table class="form-table">';

        // Pre-seed row for crack_revenue if not yet configured
        $known_network_defaults = [
            'crack_revenue' => __( 'Crack Revenue', 'tmwseo' ),
        ];
        foreach ( $known_network_defaults as $net_slug => $net_label ) {
            if ( ! isset( $networks[ $net_slug ] ) ) {
                $networks[ $net_slug ] = [
                    'label'    => $net_label,
                    'enabled'  => 0,
                    'template' => '',
                    'campaign' => '',
                    'source'   => '',
                    'subaffid' => '',
                ];
            }
        }

        foreach ( $networks as $net_slug => $net ) {
            $net_label    = (string) ( $net['label']    ?? $net_slug );
            $net_enabled  = ! empty( $net['enabled'] );
            $net_template = (string) ( $net['template'] ?? '' );
            $net_campaign = (string) ( $net['campaign'] ?? '' );
            $net_source   = (string) ( $net['source']   ?? '' );
            $net_subaffid = (string) ( $net['subaffid'] ?? '' );

            $preview_target = 'https://example.com/model/demo_user';
            $preview = $net_template !== '' ? strtr( $net_template, [
                '{profile_url}'         => $preview_target,
                '{encoded_profile_url}' => rawurlencode( $preview_target ),
                '{campaign}'            => rawurlencode( $net_campaign ),
                '{source}'              => rawurlencode( $net_source ),
                '{subaffid}'            => rawurlencode( $net_subaffid ),
                '{username}'            => '',
                '{platform}'            => '',
            ] ) : '';

            echo '<tr><td colspan="2"><h3 style="margin:0 0 4px;">'
                . esc_html( $net_label )
                . ' <code style="font-size:11px;color:#888;">(' . esc_html( $net_slug ) . ')</code>'
                . '</h3></td></tr>';

            echo '<input type="hidden" name="tmwseo_affiliate_networks[' . esc_attr( $net_slug ) . '][slug]"  value="' . esc_attr( $net_slug ) . '" />';
            echo '<input type="hidden" name="tmwseo_affiliate_networks[' . esc_attr( $net_slug ) . '][label]" value="' . esc_attr( $net_label ) . '" />';

            echo '<tr><th>' . esc_html__( 'Enabled', 'tmwseo' ) . '</th><td>'
                . '<label><input type="checkbox" '
                . 'name="tmwseo_affiliate_networks[' . esc_attr( $net_slug ) . '][enabled]" '
                . 'value="1" ' . checked( $net_enabled, true, false ) . '> '
                . /* translators: %s: network label */ sprintf( esc_html__( 'Enable %s routing', 'tmwseo' ), esc_html( $net_label ) )
                . '</label></td></tr>';

            echo '<tr><th>' . esc_html__( 'Template', 'tmwseo' ) . '</th><td>'
                . '<textarea name="tmwseo_affiliate_networks[' . esc_attr( $net_slug ) . '][template]" '
                . 'rows="3" class="large-text code">'
                . esc_textarea( $net_template )
                . '</textarea>'
                . '<p class="description">'
                . esc_html__( 'Placeholders: {profile_url}, {encoded_profile_url}, {campaign}, {source}, {subaffid}', 'tmwseo' )
                . '</p></td></tr>';

            echo '<tr><th>' . esc_html__( 'Campaign', 'tmwseo' ) . '</th><td>'
                . '<input type="text" name="tmwseo_affiliate_networks[' . esc_attr( $net_slug ) . '][campaign]" '
                . 'value="' . esc_attr( $net_campaign ) . '" class="regular-text" /></td></tr>';

            echo '<tr><th>' . esc_html__( 'Source', 'tmwseo' ) . '</th><td>'
                . '<input type="text" name="tmwseo_affiliate_networks[' . esc_attr( $net_slug ) . '][source]" '
                . 'value="' . esc_attr( $net_source ) . '" class="regular-text" /></td></tr>';

            echo '<tr><th>' . esc_html__( 'Sub Aff ID', 'tmwseo' ) . '</th><td>'
                . '<input type="text" name="tmwseo_affiliate_networks[' . esc_attr( $net_slug ) . '][subaffid]" '
                . 'value="' . esc_attr( $net_subaffid ) . '" class="regular-text" /></td></tr>';

            if ( $preview !== '' ) {
                echo '<tr><th>' . esc_html__( 'Preview', 'tmwseo' ) . '</th><td>'
                    . '<code>' . esc_html( $preview ) . '</code>'
                    . '<p class="description">'
                    . esc_html__( 'Preview uses dummy URL: https://example.com/model/demo_user', 'tmwseo' )
                    . '</p></td></tr>';
            }

            echo '<tr><td colspan="2"><hr style="margin:8px 0;border:none;border-top:1px solid #ddd;"></td></tr>';
        }

        echo '</table>';
        submit_button( __( 'Save affiliate networks', 'tmwseo' ) );
        echo '</form>';

        echo '<hr style="margin:24px 0;">';

        // ── Per-platform affiliate templates ──────────────────────────────────
        echo '<form method="post" action="options.php">';
        settings_fields('tmwseo_platform_affiliate_settings_group');
        echo '<h2>' . esc_html__('Affiliate-Capable Platforms', 'tmwseo') . '</h2>';
        echo '<p class="description">' . esc_html__('Use placeholders only. Do not paste hardcoded usernames into templates.', 'tmwseo') . '</p>';

        foreach ($platforms as $platform_key => $platform) {
            $current = is_array($per_platform[$platform_key] ?? null) ? $per_platform[$platform_key] : [];
            $pattern = (string) ($current['template'] ?? $platform['affiliate_link_pattern']);
            $campaign = (string)($current['campaign'] ?? '');
            $source = (string)($current['source'] ?? '');
            $subaffid = (string) ($current['subaffid'] ?? '');
            $psid = (string) ($current['psid'] ?? '');
            $pstool = (string) ($current['pstool'] ?? '');
            $psprogram = (string) ($current['psprogram'] ?? '');
            $campaign_id = (string) ($current['campaign_id'] ?? '');
            $siteid = (string) ($current['siteid'] ?? (string) ($platform['siteid'] ?? ''));
            $categoryname = (string) ($current['categoryname'] ?? (string) ($platform['categoryname'] ?? ''));
            $pagename = (string) ($current['pagename'] ?? (string) ($platform['pagename'] ?? ''));

            $username = 'demo_' . $platform_key;
            $profile_url_pattern = (string) ((PlatformRegistry::get($platform_key)['profile_url_pattern'] ?? ''));
            $profile_url = $profile_url_pattern !== '' ? str_replace('{username}', rawurlencode($username), $profile_url_pattern) : '';

            $preview = str_replace(
                ['{username}', '{profile_url}', '{encoded_profile_url}', '{campaign}', '{source}', '{subaffid}', '{psid}', '{pstool}', '{psprogram}', '{campaign_id}', '{siteid}', '{categoryname}', '{pagename}', '{platform}', '{siteId}', '{categoryName}', '{pageName}', '{subAffId}'],
                [rawurlencode($username), $profile_url, rawurlencode($profile_url), rawurlencode($campaign), rawurlencode($source), rawurlencode($subaffid), rawurlencode($psid), rawurlencode($pstool), rawurlencode($psprogram), rawurlencode($campaign_id), rawurlencode($siteid), rawurlencode($categoryname), rawurlencode($pagename), rawurlencode($platform_key), rawurlencode($siteid), rawurlencode($categoryname), rawurlencode($pagename), rawurlencode($subaffid)],
                $pattern
            );

            echo '<h2>' . esc_html($platform['label']) . '</h2>';
            echo '<table class="form-table">';

            echo '<tr><th>Enabled</th><td><label><input type="checkbox" name="tmwseo_platform_affiliate_settings[' . esc_attr($platform_key) . '][enabled]" value="1" ' . checked(!empty($current['enabled']), true, false) . '> Enable affiliate template for ' . esc_html($platform['label']) . '</label></td></tr>';

            echo '<tr><th>Template</th><td><textarea name="tmwseo_platform_affiliate_settings[' . esc_attr($platform_key) . '][template]" rows="4" class="large-text code">' . esc_textarea($pattern) . '</textarea>';
            echo '<p class="description">Supported placeholders: <code>{username}</code>, <code>{profile_url}</code>, <code>{encoded_profile_url}</code>, <code>{campaign}</code>, <code>{source}</code>, <code>{subaffid}</code>, <code>{psid}</code>, <code>{pstool}</code>, <code>{psprogram}</code>, <code>{campaign_id}</code>, <code>{siteid}</code>, <code>{categoryname}</code>, <code>{pagename}</code>, <code>{platform}</code>.</p></td></tr>';

            echo '<tr><th>Campaign</th><td><input type="text" name="tmwseo_platform_affiliate_settings[' . esc_attr($platform_key) . '][campaign]" value="' . esc_attr($campaign) . '" class="regular-text"></td></tr>';
            echo '<tr><th>Source</th><td><input type="text" name="tmwseo_platform_affiliate_settings[' . esc_attr($platform_key) . '][source]" value="' . esc_attr($source) . '" class="regular-text"></td></tr>';
            echo '<tr><th>Sub Aff ID</th><td><input type="text" name="tmwseo_platform_affiliate_settings[' . esc_attr($platform_key) . '][subaffid]" value="' . esc_attr($subaffid) . '" class="regular-text"></td></tr>';
            echo '<tr><th>PSID</th><td><input type="text" name="tmwseo_platform_affiliate_settings[' . esc_attr($platform_key) . '][psid]" value="' . esc_attr($psid) . '" class="regular-text"></td></tr>';
            echo '<tr><th>PSTool</th><td><input type="text" name="tmwseo_platform_affiliate_settings[' . esc_attr($platform_key) . '][pstool]" value="' . esc_attr($pstool) . '" class="regular-text"></td></tr>';
            echo '<tr><th>PSProgram</th><td><input type="text" name="tmwseo_platform_affiliate_settings[' . esc_attr($platform_key) . '][psprogram]" value="' . esc_attr($psprogram) . '" class="regular-text"></td></tr>';
            echo '<tr><th>Campaign ID</th><td><input type="text" name="tmwseo_platform_affiliate_settings[' . esc_attr($platform_key) . '][campaign_id]" value="' . esc_attr($campaign_id) . '" class="regular-text"></td></tr>';
            echo '<tr><th>Site ID</th><td><input type="text" name="tmwseo_platform_affiliate_settings[' . esc_attr($platform_key) . '][siteid]" value="' . esc_attr($siteid) . '" class="regular-text"></td></tr>';
            echo '<tr><th>Category Name</th><td><input type="text" name="tmwseo_platform_affiliate_settings[' . esc_attr($platform_key) . '][categoryname]" value="' . esc_attr($categoryname) . '" class="regular-text"></td></tr>';
            echo '<tr><th>Page Name</th><td><input type="text" name="tmwseo_platform_affiliate_settings[' . esc_attr($platform_key) . '][pagename]" value="' . esc_attr($pagename) . '" class="regular-text"></td></tr>';

            echo '<tr><th>Preview example link</th><td><code>' . esc_html($preview) . '</code>';
            echo '<p class="description">Preview uses dummy username <code>' . esc_html($username) . '</code>.</p></td></tr>';

            echo '</table>';
        }

        submit_button(__('Save affiliate templates', 'tmwseo'));
        echo '</form>';

        CrakRevenueCamManager::render_admin_section();

        self::footer();
    }
}
