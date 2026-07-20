<?php
declare(strict_types=1);

namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Admin;
use TMWSEO\Engine\Admin\AdminFormHandlers;

if (!defined('ABSPATH')) { exit; }

/**
 * AdminMenu — admin_menu hook target + post-registration submenu reorder.
 *
 * Extracted from class-admin.php as the fourth concrete step of the
 * god-class decomposition. Owns:
 *   - The top-level menu page and ~30 submenu page registrations.
 *   - The priority-9999 submenu reorder that produces the canonical
 *     sidebar order (Command Center → Workflow → Intelligence → Content
 *     → Reports → System → Hidden legacy slugs).
 *   - Six tiny legacy-slug redirect handlers (`tmwseo-cfg` → settings,
 *     `tmwseo-kw` → keywords, etc.) registered as page targets so old
 *     bookmarks resolve cleanly without a JS bounce.
 *
 * Hook registration (from Admin::init()):
 *   add_action('admin_menu', [AdminMenu::class, 'register']);
 *   add_action('admin_menu', [AdminMenu::class, 'reorder'], 9999);
 *
 * Cross-references to renderer methods that still live in Admin (e.g.
 * `Admin::render_keywords`, `Admin::render_topic_authority`) are written
 * as `[Admin::class, '...']`. When the page-renderer cluster is
 * extracted in a future PR, those references update to point at the new
 * class. Until then, the renderers' physical location doesn't matter to
 * WordPress — only the callable target.
 */
class AdminMenu {

    /**
     * admin_menu hook target. Registers the top-level menu + all
     * submenus, plus the hidden legacy-slug redirect targets.
     *
     * (Method renamed from the original `Admin::menu()` — calling
     * `AdminMenu::menu()` read awkwardly.)
     */
    public static function register(): void {
        $slug = Admin::MENU_SLUG;

        // ── Top-level entry point → Command Center directly (no redirect) ──
        add_menu_page(
            __('TMW SEO Engine', 'tmwseo'),
            __('TMW SEO Engine', 'tmwseo'),
            'manage_options',
            $slug,
            ['\\TMWSEO\\Engine\\Admin\\CommandCenter', 'render'],
            'dashicons-chart-area',
            58
        );

        // First submenu entry shares slug with top-level — relabels the sidebar item.
        add_submenu_page(
            $slug,
            __('Command Center', 'tmwseo'),
            __('&#9881; Command Center', 'tmwseo'),
            'manage_options',
            $slug,
            ['\\TMWSEO\\Engine\\Admin\\CommandCenter', 'render']
        );

        // ── Workflow ───────────────────────────────────────────────────────
        add_submenu_page(
            $slug,
            __('Keyword Command Center', 'tmwseo'),
            __('Command Center', 'tmwseo'),
            'manage_options',
            'tmwseo-command-center',
            ['\\TMWSEO\\Engine\\Admin\\KeywordCommandCenter', 'render_page']
        );
        add_submenu_page(
            $slug,
            __('Command Center (Legacy)', 'tmwseo'),
            __('Command Center (Legacy)', 'tmwseo'),
            'manage_options',
            'tmwseo-command-center-legacy',
            ['\\TMWSEO\\Engine\\Suggestions\\SuggestionsAdminPage', 'render_static_command_center_legacy']
        );
        add_submenu_page($slug, __('Suggestions', 'tmwseo'),    __('Suggestions', 'tmwseo'),    'manage_options', 'tmwseo-suggestions',    ['\\TMWSEO\\Engine\\Suggestions\\SuggestionsAdminPage', 'render_static_suggestions']);
        add_submenu_page($slug, __('Content Briefs', 'tmwseo'), __('Content Briefs', 'tmwseo'), 'manage_options', 'tmwseo-content-briefs', ['\\TMWSEO\\Engine\\Suggestions\\SuggestionsAdminPage', 'render_static_briefs']);

        // ── Intelligence ───────────────────────────────────────────────────
        // Store the hook so we can register the early bulk-action handler below.
        $kw_page_hook = add_submenu_page($slug, __('Keywords', 'tmwseo'), __('Keywords', 'tmwseo'), 'manage_options', 'tmwseo-keywords', [Admin::class, 'render_keywords']);
        add_submenu_page(
            $slug,
            __( 'Keyword Pools', 'tmwseo' ),
            __( 'Keyword Pools', 'tmwseo' ),
            'manage_options',
            'tmwseo-keyword-pools',
            [ '\\TMWSEO\\Engine\\Admin\\KeywordPoolsAdminPage', 'render_page' ]
        );
        add_submenu_page($slug, __('Model Opportunities', 'tmwseo'), __('Model Opportunities', 'tmwseo'), 'manage_options', 'tmwseo-model-opportunities', ['\\TMWSEO\\Engine\\Admin\\ModelOpportunityAdminPage', 'render_page']);
        // Early handler fires before admin-header.php so wp_safe_redirect() is safe.
        add_action( 'load-' . $kw_page_hook, [ AdminFormHandlers::class, 'handle_keyword_candidates_bulk' ] );
        add_submenu_page($slug, __('Autopilot', 'tmwseo'),           __('Autopilot', 'tmwseo'),           'manage_options', 'tmwseo-autopilot',          ['\\TMWSEO\\Engine\\Admin\\AutopilotAdminPage', 'render_page']);
        add_submenu_page($slug, __('Opportunities', 'tmwseo'),       __('Opportunities', 'tmwseo'),       'manage_options', 'tmwseo-opportunities',       ['\\TMWSEO\\Engine\\Opportunities\\OpportunityUI', 'render_static']);
        add_submenu_page($slug, __('Traffic Forecast', 'tmwseo'),   __('Traffic Forecast', 'tmwseo'),   'manage_options', 'tmwseo-traffic-forecast',   ['\\TMWSEO\\Engine\\Opportunities\\TrafficForecastUI', 'render_page']);
        add_submenu_page($slug, __('Internal Link Opportunities', 'tmwseo'), __('Internal Link Opportunities', 'tmwseo'), 'manage_options', 'tmwseo-internal-links', ['\\TMWSEO\\Engine\\InternalLinks\\InternalLinkOpportunities', 'render_admin_page']);

        // ── Search Intelligence group ──────────────────────────────────────
        // Hub: lightweight overview page with cards linking to each tool.
        // New slug only; all four tool slugs below are unchanged.
        add_submenu_page($slug, __('Search Intelligence', 'tmwseo'), __('&#x1F50D; Search Intelligence', 'tmwseo'), 'manage_options', 'tmwseo-search-intelligence', [Admin::class, 'render_search_intelligence_hub']);
        add_submenu_page($slug, __('SERP Analyzer', 'tmwseo'),      __('&rsaquo; SERP Analyzer', 'tmwseo'),      'manage_options', 'tmwseo-serp-analyzer',      ['\TMWSEO\Engine\Admin\SerpAnalyzerAdminPage', 'render_page']);
        add_submenu_page($slug, __('SERP Keyword Gaps', 'tmwseo'),  __('&rsaquo; SERP Keyword Gaps', 'tmwseo'),  'manage_options', 'tmwseo-serp-gaps',          ['\\TMWSEO\\Engine\\Admin\\SerpGapAdminPage', 'render_page']);
        add_submenu_page($slug, __('Content Gap', 'tmwseo'),        __('&rsaquo; Content Gap', 'tmwseo'),        'manage_options', 'tmwseo-content-gap',        ['\\TMWSEO\\Engine\\ContentGap\\ContentGapAdmin', 'render_page']);
        add_submenu_page($slug, __('Competitor Domains', 'tmwseo'), __('&rsaquo; Competitor Domains', 'tmwseo'), 'manage_options', 'tmwseo-competitor-domains', ['\\TMWSEO\\Engine\\Suggestions\\SuggestionsAdminPage', 'render_static_competitor_domains']);

        add_submenu_page($slug, __('Link Graph', 'tmwseo'), __('Link Graph', 'tmwseo'), 'manage_options', 'tmwseo-link-graph', ['\TMWSEO\Engine\Admin\LinkGraphAdminPage', 'render_page']);
        add_submenu_page($slug, __('Topic Maps', 'tmwseo'), __('Topic Maps', 'tmwseo'), 'manage_options', 'tmwseo-topic-maps', ['\TMWSEO\Engine\Admin\TopicMapsAdminPage', 'render_page']);
        add_submenu_page($slug, __('Topic Authority', 'tmwseo'), __('Topic Authority', 'tmwseo'), 'manage_options', 'tmwseo-topic-authority', [Admin::class, 'render_topic_authority']);
        add_submenu_page($slug, __('Keyword Graph', 'tmwseo'), __('Keyword Graph', 'tmwseo'), 'manage_options', 'tmwseo-keyword-graph', ['\TMWSEO\Engine\Admin\KeywordGraphAdminPage', 'render']);
        add_submenu_page($slug, __('Discovery Control', 'tmwseo'), __('Discovery Control', 'tmwseo'), 'manage_options', 'tmwseo-discovery-control', ['\TMWSEO\Engine\Admin\DiscoveryControlAdminPage', 'render_page']);
        add_submenu_page($slug, __('Competitor Mining', 'tmwseo'), __('Competitor Mining', 'tmwseo'), 'manage_options', 'tmwseo-competitor-mining', [Admin::class, 'render_competitor_mining']);
        add_submenu_page($slug, __('Ranking Probability', 'tmwseo'), __('Ranking Probability', 'tmwseo'), 'manage_options', 'tmwseo-ranking-probability', [Admin::class, 'render_ranking_probability']);

        // ── Content ────────────────────────────────────────────────────────
        add_submenu_page($slug, __('Models', 'tmwseo'), __('Models', 'tmwseo'), 'manage_options', 'tmwseo-models', [Admin::class, 'render_models_redirect']);

        // ── Reports ────────────────────────────────────────────────────────
        add_submenu_page($slug, __('Reports', 'tmwseo'), __('Reports', 'tmwseo'), 'manage_options', 'tmwseo-reports', ['\\TMWSEO\\Engine\\Admin\\AdminDashboardV2', 'page_reports']);

        // ── System ─────────────────────────────────────────────────────────
        add_submenu_page($slug, __('Connections', 'tmwseo'), __('Connections', 'tmwseo'), 'manage_options', 'tmwseo-connections', ['\\TMWSEO\\Engine\\Admin\\AdminDashboardV2', 'page_connections']);
        add_submenu_page($slug, __('Affiliates', 'tmwseo'),  __('Affiliates', 'tmwseo'),  'manage_options', 'tmwseo-affiliates', [Admin::class, 'render_affiliates']);
        add_submenu_page($slug, __('Settings', 'tmwseo'),    __('Settings', 'tmwseo'),    'manage_options', 'tmwseo-settings',    [Admin::class, 'render_settings']);
        add_submenu_page($slug, __('Tools', 'tmwseo'),       __('Tools', 'tmwseo'),       'manage_options', 'tmwseo-tools',       [AdminToolsPage::class, 'render']);
        add_submenu_page($slug, __('DataForSEO Keyword Strategy Preview', 'tmwseo'), __('DataForSEO Strategy Preview', 'tmwseo'), 'manage_options', 'tmwseo-dfseo-keyword-strategy-preview', [AdminDfseoPreviewPage::class, 'render']);
        add_submenu_page($slug, __('CSV Manager', 'tmwseo'), __('CSV Manager', 'tmwseo'), 'manage_options', 'tmwseo-csv-manager', ['\TMWSEO\Engine\Admin\CSVManagerAdminPage', 'render_page']);
        add_submenu_page($slug, __('Keyword Planner API Test', 'tmwseo'), __('Keyword Planner Test', 'tmwseo'), 'manage_options', 'tmwseo-gkp-test', [Admin::class, 'render_keyword_planner_test']);
        add_submenu_page($slug, __('Debug Dashboard', 'tmwseo'), __('Debug Dashboard', 'tmwseo'), 'manage_options', 'tmwseo-debug-dashboard', ['\\TMWSEO\\Engine\\Debug\\DebugDashboard', 'render_page']);
        add_submenu_page($slug, __('Staging Ops', 'tmwseo'), __('Staging Ops', 'tmwseo'), 'manage_options', 'tmwseo-staging-ops', ['\\TMWSEO\\Engine\\Admin\\StagingOperationsPage', 'render_page']);

        // ── Category Formulas (5.2.0) ──────────────────────────────────────
        add_submenu_page(
            $slug,
            __( 'Category Formulas', 'tmwseo' ),
            __( 'Category Formulas', 'tmwseo' ),
            'manage_options',
            'tmwseo-category-formulas',
            [ '\\TMWSEO\\Engine\\Admin\\CategoryFormulaAdminPage', 'render_page' ]
        );

        // ── Hidden (legacy routes — direct URLs still work) ────────────────
        add_submenu_page(null, __('Drafts to Review', 'tmwseo'), __('Drafts to Review', 'tmwseo'), 'manage_options', 'tmwseo-generated',   [Admin::class, 'render_generated_pages']);
        add_submenu_page(null, __('Logs', 'tmwseo'),             __('Logs', 'tmwseo'),             'manage_options', 'tmwseo-logs',        [Admin::class, 'render_logs']);
        add_submenu_page(null, __('Engine Monitor', 'tmwseo'),   __('Engine Monitor', 'tmwseo'),   'manage_options', 'tmw-engine-monitor', [Admin::class, 'render_engine_monitor']);
        add_submenu_page(null, __('Migration', 'tmwseo'),        __('Migration', 'tmwseo'),        'manage_options', 'tmwseo-migration',   [Admin::class, 'render_migration']);
        add_submenu_page(null, __('Import', 'tmwseo'),           __('Import', 'tmwseo'),           'manage_options', 'tmwseo-import',      [Admin::class, 'render_import']);
        add_submenu_page(null, __('Import Keyword Metrics', 'tmwseo'), __('Import Metrics', 'tmwseo'), 'manage_options', 'tmwseo-kw-metrics-import', ['\TMWSEO\Engine\Admin\KeywordMetricsCsvImporter', 'render_page']); // 5.9.0

        // Legacy V2 slugs → server-side redirect to canonical pages (no JS bounces).
        // These six tiny handlers used to live next to menu() in Admin; relocated here
        // so the entire "what slugs exist + where they go" surface lives in one place.
        add_submenu_page(null, '', '', 'manage_options', 'tmwseo-engine-v2',        [self::class, 'legacy_redirect_command_center']);
        add_submenu_page(null, '', '', 'manage_options', 'tmwseo-pagespeed',        [self::class, 'render_pagespeed_redirect']);
        add_submenu_page(null, '', '', 'manage_options', 'tmwseo-cfg',              [self::class, 'render_settings_redirect']);
        add_submenu_page(null, '', '', 'manage_options', 'tmwseo-kw',              [self::class, 'render_keywords_redirect']);
        add_submenu_page(null, '', '', 'manage_options', 'tmwseo-reports-legacy',   [self::class, 'render_reports_redirect']);
        add_submenu_page(null, '', '', 'manage_options', 'tmwseo-connections-legacy',[self::class, 'render_connections_redirect']);
        add_submenu_page(null, '', '', 'manage_options', 'tmwseo-diag',            [self::class, 'render_debug_redirect']);
    }

    /**
     * Rebuild $submenu for our top-level slug in the desired canonical order,
     * removing any duplicates registered by other classes.
     *
     * Hooked at priority 9999 (after all admin_menu callbacks fire).
     * (Method renamed from the original `Admin::reorder_admin_menus()`.)
     */
    public static function reorder(): void {
        global $submenu;
        $slug = Admin::MENU_SLUG;
        if (!isset($submenu[$slug]) || !is_array($submenu[$slug])) {
            return;
        }

        // Desired visible order (slug → keep).
        $desired_order = [
            $slug,                          // Command Center (top entry)
            'tmwseo-command-center',
            'tmwseo-command-center-legacy',
            'tmwseo-suggestions',
            'tmwseo-content-briefs',
            'tmwseo-keywords',
            'tmwseo-keyword-pools',
            'tmwseo-model-opportunities',
            'tmwseo-autopilot',
            'tmwseo-seed-registry',
            'tmwseo-opportunities',
            'tmwseo-traffic-forecast',
            'tmwseo-internal-links',
            'tmwseo-search-intelligence',
            'tmwseo-serp-analyzer',
            'tmwseo-serp-gaps',
            'tmwseo-competitor-domains',
            'tmwseo-content-gap',
            'tmwseo-link-graph',
            'tmwseo-topic-maps',
            'tmwseo-topic-authority',
            'tmwseo-keyword-graph',
            'tmwseo-discovery-control',
            'tmwseo-competitor-mining',
            'tmwseo-ranking-probability',
            'tmwseo-models',
            'tmwseo-model-optimizer',   // legacy hidden alias → redirects to tmwseo-models
            'tmwseo-reports',
            'tmwseo-connections',
            'tmwseo-affiliates',
            'tmwseo-settings',
            'tmwseo-tools',
            'tmwseo-dfseo-keyword-strategy-preview',
            'tmwseo-csv-manager',
            'tmwseo-kw-metrics-import', // 5.9.0
            'tmwseo-gkp-test',
            'tmwseo-staging-validation-helper',
            'tmwseo-staging-ops',
            'tmwseo-category-formulas',
            'tmwseo-debug-dashboard',
            'tmw-seo-debug',
        ];

        // Index by slug (first registration wins for dedup).
        $by_slug = [];
        foreach ($submenu[$slug] as $item) {
            $item_slug = $item[2] ?? '';
            if ($item_slug !== '' && !isset($by_slug[$item_slug])) {
                $by_slug[$item_slug] = $item;
            }
        }

        // Rebuild in desired order.
        $new_submenu = [];
        foreach ($desired_order as $item_slug) {
            if (isset($by_slug[$item_slug])) {
                $new_submenu[] = $by_slug[$item_slug];
            }
        }

        $submenu[$slug] = array_values($new_submenu);
    }

    // ── Legacy redirects ───────────────────────────────────────────────────
    // Each handler is registered as the target of a hidden submenu page so
    // WordPress dispatches to it when the legacy slug is requested. The
    // exit() after wp_safe_redirect is critical — otherwise WP would also
    // render its own page chrome.

    public static function legacy_redirect_command_center(): void {
        wp_safe_redirect( admin_url( 'admin.php?page=' . Admin::MENU_SLUG ) );
        exit;
    }

    public static function render_pagespeed_redirect(): void {
        wp_safe_redirect(admin_url('admin.php?page=tmwseo-reports'));
        exit;
    }

    public static function render_settings_redirect(): void {
        wp_safe_redirect(admin_url('admin.php?page=tmwseo-settings'));
        exit;
    }

    public static function render_keywords_redirect(): void {
        wp_safe_redirect(admin_url('admin.php?page=tmwseo-keywords'));
        exit;
    }

    public static function render_reports_redirect(): void {
        wp_safe_redirect(admin_url('admin.php?page=tmwseo-reports'));
        exit;
    }

    public static function render_connections_redirect(): void {
        wp_safe_redirect(admin_url('admin.php?page=tmwseo-connections'));
        exit;
    }

    public static function render_debug_redirect(): void {
        wp_safe_redirect(admin_url('admin.php?page=tmwseo-debug-dashboard'));
        exit;
    }
}
