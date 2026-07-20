<?php
declare(strict_types=1);

namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\JobWorker;
use TMWSEO\Engine\Admin\AdminUI;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Services\OpenAI;
use TMWSEO\Engine\Integrations\GSCApi;
use TMWSEO\Engine\Intelligence\IntelligenceStorage;
use TMWSEO\Engine\Keywords\SeedRegistry;

if (!defined('ABSPATH')) { exit; }

/**
 * AdminToolsPage — `tmwseo-tools` page renderer + helper-readiness panel.
 *
 * Extracted from class-admin.php as the fifth concrete step of the
 * god-class decomposition. Owns:
 *   - render() — the page entry point (formerly Admin::render_tools).
 *     Renders the Content Scans / Discovery & Intelligence / Maintenance /
 *     Advanced sections plus inline JS for orphan + competitor scan buttons.
 *   - render_readiness_section() — the "Helper & Data Readiness" panel
 *     at the top, with status badges for DataForSEO / GSC / AI provider /
 *     competitor coverage / keyword corpus / volume coverage / KD coverage.
 *
 * Registered as a submenu page target from AdminMenu::register():
 *   add_submenu_page(..., 'tmwseo-tools', [AdminToolsPage::class, 'render'])
 *
 * The shared `Admin::footer()` helper was replaced inline with its body
 * (`echo '</div>';`) so this class doesn't depend on a private Admin
 * method. footer() is still called from 6+ other Admin renderers, so
 * leaving it there is correct until those renderers also extract.
 */
class AdminToolsPage {

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // ── Page shell ───────────────────────────────────────────────────────
        echo '<div class="wrap">';
        AdminUI::page_header(
            __('TMW SEO Engine — Tools', 'tmwseo'),
            __('Operator-triggered utilities. Every action requires explicit approval — nothing runs automatically.', 'tmwseo')
        );

        // ── Helper & Data Readiness ──────────────────────────────────────────
        self::render_readiness_section();

        // ── Content Scans ────────────────────────────────────────────────────
        AdminUI::section_start( __('Content Scans', 'tmwseo') );
        echo '<div class="tmwui-card-grid">';

        // Internal Link Opportunities
        echo '<div class="tmwui-card">';
        echo '<h3 class="tmwui-card-title">' . esc_html__('Internal Link Opportunities', 'tmwseo') . '</h3>';
        echo '<p class="tmwui-card-desc">' . esc_html__('Scans published content to find where internal links are missing or weak. Results are added to Suggestions for review.', 'tmwseo') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_scan_internal_link_opportunities');
        echo '<input type="hidden" name="action" value="tmwseo_scan_internal_link_opportunities">';
        submit_button(__('Scan Internal Link Opportunities', 'tmwseo'), 'secondary', 'submit', false);
        echo '</form>';
        echo '</div>';

        // Content Improvement Scan
        echo '<div class="tmwui-card">';
        echo '<h3 class="tmwui-card-title">' . esc_html__('Content Improvement Scan', 'tmwseo') . '</h3>';
        echo '<p class="tmwui-card-desc">' . esc_html__('Analyzes existing content for SEO gaps: missing keywords, thin content, meta issues. Results go to Suggestions.', 'tmwseo') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_scan_content_improvements');
        echo '<input type="hidden" name="action" value="tmwseo_scan_content_improvements">';
        submit_button(__('Scan Content Improvements', 'tmwseo'), 'secondary', 'submit', false);
        echo '</form>';
        echo '</div>';

        // Orphan Page Scan
        echo '<div class="tmwui-card">';
        echo '<h3 class="tmwui-card-title">' . esc_html__('Orphan Page Scan', 'tmwseo') . '</h3>';
        echo '<p class="tmwui-card-desc">' . esc_html__('Finds published pages with zero inbound internal links. Orphan pages are invisible to Googlebot unless linked from elsewhere.', 'tmwseo') . '</p>';
        $orphan_last = get_option(\TMWSEO\Engine\InternalLinks\OrphanPageDetector::OPTION_LAST_SCAN, '');
        if ($orphan_last) {
            echo '<p><small>' . esc_html__('Last scan:', 'tmwseo') . ' ' . esc_html((string) $orphan_last) . '</small></p>';
        }
        echo '<button type="button" class="button" id="tmwseo-orphan-scan-btn">' . esc_html__('Scan Orphan Pages', 'tmwseo') . '</button>';
        echo '<div id="tmwseo-orphan-scan-result" style="margin-top:8px;"></div>';
        echo '</div>';

        // Competitor Monitor
        echo '<div class="tmwui-card">';
        echo '<h3 class="tmwui-card-title">' . esc_html__('Competitor Domain Scan', 'tmwseo') . '</h3>';
        echo '<p class="tmwui-card-desc">' . esc_html__('Checks competitor domains for new content targeting keywords you rank for. Results are stored for review.', 'tmwseo') . '</p>';
        $comp_last = get_option(\TMWSEO\Engine\CompetitorMonitor\CompetitorMonitor::OPTION_LAST_RUN, '');
        if ($comp_last) {
            echo '<p><small>' . esc_html__('Last scan:', 'tmwseo') . ' ' . esc_html(date('Y-m-d H:i', (int) $comp_last)) . '</small></p>';
        }
        echo '<button type="button" class="button" id="tmwseo-competitor-scan-btn">' . esc_html__('Run Competitor Scan', 'tmwseo') . '</button>';
        echo '<div id="tmwseo-competitor-scan-result" style="margin-top:8px;"></div>';
        echo '</div>';

        echo '</div>'; // .tmwui-card-grid
        AdminUI::section_end();

        // ── Discovery & Intelligence ──────────────────────────────────────────
        AdminUI::section_start( __('Discovery & Intelligence', 'tmwseo') );
        echo '<div class="tmwui-card-grid">';

        echo '<div class="tmwui-card">';
        echo '<h3 class="tmwui-card-title">' . esc_html__('Discovery Snapshot (Phase C)', 'tmwseo') . '</h3>';
        echo '<p class="tmwui-card-desc">' . esc_html__('Runs a safe read-only snapshot of the keyword + opportunity landscape. Does not publish anything. Results feed the Suggestions queue.', 'tmwseo') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_run_phase_c_discovery_snapshot');
        echo '<input type="hidden" name="action" value="tmwseo_run_phase_c_discovery_snapshot">';
        submit_button(__('Run Discovery Snapshot', 'tmwseo'), 'secondary', 'submit', false);
        echo '</form>';
        echo '</div>';

        echo '<div class="tmwui-card">';
        echo '<h3 class="tmwui-card-title">' . esc_html__('Keyword Cycle', 'tmwseo') . '</h3>';
        echo '<p class="tmwui-card-desc">' . esc_html__('Refreshes keyword data from DataForSEO: discovery, KD scoring, clustering. Creates new candidate clusters for review.', 'tmwseo') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_run_keyword_cycle');
        echo '<input type="hidden" name="action" value="tmwseo_run_keyword_cycle">';
        submit_button(__('Run Keyword Cycle', 'tmwseo'), 'primary', 'submit', false);
        echo '</form>';
        echo '</div>';

        echo '</div>'; // .tmwui-card-grid
        AdminUI::section_end();

        // ── Maintenance ───────────────────────────────────────────────────────
        AdminUI::section_start( __('Maintenance', 'tmwseo') );
        echo '<div class="tmwui-card-grid">';

        echo '<div class="tmwui-card">';
        echo '<h3 class="tmwui-card-title">' . esc_html__('Auto Fix Missing SEO', 'tmwseo') . '</h3>';
        echo '<p class="tmwui-card-desc">' . esc_html__('Fills in missing focus keywords and meta descriptions using post titles. Safe batch fix — does not touch content or publish anything.', 'tmwseo') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_bulk_autofix');
        echo '<input type="hidden" name="action" value="tmwseo_bulk_autofix">';
        submit_button(__('Auto Fix Missing SEO', 'tmwseo'), 'secondary', 'submit', false);
        echo '</form>';
        echo '</div>';

        echo '<div class="tmwui-card">';
        echo '<h3 class="tmwui-card-title">' . esc_html__('Generate Model Seeds', 'tmwseo') . '</h3>';
        echo '<p class="tmwui-card-desc">' . esc_html__('Generates predefined seed keywords for every model post and inserts new rows into the keyword table.', 'tmwseo') . '</p>';
        echo '<p class="tmwui-card-desc">' . esc_html__('Runs in batches to prevent timeouts and skips duplicates automatically.', 'tmwseo') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_generate_model_seeds');
        echo '<input type="hidden" name="action" value="tmwseo_generate_model_seeds">';
        submit_button(__('Generate Model Seeds', 'tmwseo'), 'secondary', 'submit', false);
        echo '</form>';
        echo '</div>';

        echo '<div class="tmwui-card">';
        echo '<h3 class="tmwui-card-title">' . esc_html__( 'Export Current Keyword Candidates', 'tmwseo' ) . '</h3>';
        echo '<p class="tmwui-card-desc">' . esc_html__( 'Downloads a CSV export of all current keyword candidates from the live working table for external enrichment and later metrics re-import.', 'tmwseo' ) . '</p>';
        echo \TMWSEO\Engine\Export\CSVExporter::button( 'current_keyword_candidates', __( 'Export Current Keyword Candidates', 'tmwseo' ) );
        echo '</div>';

        echo '<div class="tmwui-card">';
        echo '<h3 class="tmwui-card-title">' . esc_html__('System Worker', 'tmwseo') . '</h3>';
        $job_counts = JobWorker::counts();
        echo '<p class="tmwui-card-desc">' . esc_html__('Runs one queued background SEO job immediately. Worker cron also runs every minute.', 'tmwseo') . '</p>';
        echo '<p class="tmwui-card-desc"><strong>' . esc_html__('Pending:', 'tmwseo') . '</strong> ' . esc_html((string) ($job_counts['pending'] ?? 0)) . ' · <strong>' . esc_html__('Running:', 'tmwseo') . '</strong> ' . esc_html((string) ($job_counts['running'] ?? 0)) . ' · <strong>' . esc_html__('Failed:', 'tmwseo') . '</strong> ' . esc_html((string) ($job_counts['failed'] ?? 0)) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_run_worker');
        echo '<input type="hidden" name="action" value="tmwseo_run_worker">';
        submit_button(__('Run Worker Now', 'tmwseo'), 'secondary', 'submit', false);
        echo '</form>';
        echo '</div>';

        $reset_discovery_confirm = esc_js(__('Are you sure you want to reset discovery data? This permanently deletes imported keywords, clusters, and suggestions only.', 'tmwseo'));
        echo '<div class="tmwui-card">';
        echo '<h3 class="tmwui-card-title">' . esc_html__('Reset Discovery Data', 'tmwseo') . '</h3>';
        echo '<p class="tmwui-card-desc">' . esc_html__('Clears all imported keywords and clusters so a new seed set can be tested.', 'tmwseo') . '</p>';
        echo '<p class="tmwui-card-desc">' . esc_html__('Deletes rows only from tmw_seo_keywords, tmw_seo_clusters, and tmw_seo_suggestions. Does not delete posts, models, settings, or API keys.', 'tmwseo') . '</p>';
        echo "<form method=\"post\" action=\"" . esc_url(admin_url('admin-post.php')) . "\" onsubmit=\"return window.confirm('" . $reset_discovery_confirm . "');\">";
        wp_nonce_field('tmwseo_reset_discovery_data');
        echo '<input type="hidden" name="action" value="tmwseo_reset_discovery_data">';
        submit_button(__('Reset Discovery Data', 'tmwseo'), 'delete', 'submit', false);
        echo '</form>';
        echo '</div>';

        echo '</div>'; // .tmwui-card-grid
        AdminUI::section_end();

        // ── Advanced Links ────────────────────────────────────────────────────
        AdminUI::section_start( __('Advanced', 'tmwseo') );
        echo '<ul style="line-height:2;">';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=tmwseo-logs')) . '">' . esc_html__('View Logs', 'tmwseo') . '</a></li>';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=tmw-engine-monitor')) . '">' . esc_html__('Engine Monitor', 'tmwseo') . '</a></li>';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=tmwseo-model-optimizer')) . '">' . esc_html__('Model Optimizer', 'tmwseo') . '</a></li>';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=tmwseo-intelligence')) . '">' . esc_html__('Legacy Keyword Research', 'tmwseo') . '</a></li>';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=tmwseo-staging-validation-helper')) . '">' . esc_html__('Staging Validation Helper', 'tmwseo') . '</a></li>';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=tmwseo-import')) . '">' . esc_html__('Import Keywords (CSV)', 'tmwseo') . '</a></li>';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=tmwseo-kw-metrics-import')) . '">' . esc_html__('Import Keyword Metrics (Volume / KD / CPC)', 'tmwseo') . '</a></li>'; // 5.9.0
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=tmwseo-migration')) . '">' . esc_html__('Migration Info', 'tmwseo') . '</a></li>';
        echo '</ul>';
        AdminUI::section_end();

        // AJAX for orphan + competitor scan buttons — IDs, actions, and nonces unchanged
        wp_add_inline_script('jquery', '
            jQuery(function($){
                $("#tmwseo-orphan-scan-btn").on("click", function(){
                    var btn = $(this);
                    btn.prop("disabled", true).text("' . esc_js(__('Scanning…', 'tmwseo')) . '");
                    $.post(ajaxurl, {action:"tmwseo_orphan_scan", nonce:"' . wp_create_nonce('tmwseo_orphan_scan') . '"}, function(res){
                        btn.prop("disabled", false).text("' . esc_js(__('Scan Orphan Pages', 'tmwseo')) . '");
                        var msg = res.success ? res.data.message : (res.data||"Error");
                        $("#tmwseo-orphan-scan-result").html("<p style=\'color:green\'>"+msg+"</p>");
                    }).fail(function(){ btn.prop("disabled",false); $("#tmwseo-orphan-scan-result").html("<p style=\'color:red\'>' . esc_js(__('Request failed.', 'tmwseo')) . '</p>"); });
                });
                $("#tmwseo-competitor-scan-btn").on("click", function(){
                    var btn = $(this);
                    btn.prop("disabled", true).text("' . esc_js(__('Scanning…', 'tmwseo')) . '");
                    $.post(ajaxurl, {action:"tmwseo_competitor_scan", nonce:"' . wp_create_nonce('tmwseo_competitor_scan') . '"}, function(res){
                        btn.prop("disabled", false).text("' . esc_js(__('Run Competitor Scan', 'tmwseo')) . '");
                        var msg = res.success ? res.data.message : (res.data||"Error");
                        $("#tmwseo-competitor-scan-result").html("<p style=\'color:green\'>"+msg+"</p>");
                    }).fail(function(){ btn.prop("disabled",false); $("#tmwseo-competitor-scan-result").html("<p style=\'color:red\'>' . esc_js(__('Request failed.', 'tmwseo')) . '</p>"); });
                });
            });
        ');

        // Inlined from Admin::footer() — was `private static function footer(): void { echo '</div>'; }`.
        // Closing the `<div class="wrap">` opened at the top of this method.
        echo '</div>';
    }

    /**
     * "Helper & Data Readiness" section at the top of the Tools page.
     *
     * Inspects the live configuration (DataForSEO / GSC / AI / competitor
     * coverage) plus keyword corpus size + volume/KD coverage, then renders
     * a status table with a per-row badge, narrative explanation of why
     * each helper matters for suggestion quality, and a "Next action" link.
     *
     * Pure presentation — runs read-only SELECTs only. No side effects.
     */
    private static function render_readiness_section(): void {
        global $wpdb;

        // 1. Collect all signals ───────────────────────────────────────────────

        $dfs_ok         = DataForSEO::is_configured();
        $gsc_configured = GSCApi::is_configured();
        $gsc_connected  = GSCApi::is_connected();
        $openai_ok      = OpenAI::is_configured();
        $anthropic_ok   = trim((string) Settings::get('tmwseo_anthropic_api_key', '')) !== '';
        $ai_ok          = $openai_ok || $anthropic_ok;

        $competitor_domains = IntelligenceStorage::get_competitor_domains();
        $competitor_count   = count($competitor_domains);

        $raw_table     = $wpdb->prefix . 'tmw_keyword_raw';
        $cand_table    = $wpdb->prefix . 'tmw_keyword_candidates';
        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';

        $raw_count     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$raw_table}");
        $cand_count    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$cand_table}");
        $cluster_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$cluster_table}");

        $cand_with_vol = 0;
        $cand_with_kd  = 0;
        if ($cand_count > 0) {
            $cand_with_vol = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$cand_table} WHERE volume IS NOT NULL AND volume > 0");
            $cand_with_kd  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$cand_table} WHERE difficulty IS NOT NULL");
        }
        $vol_pct = $cand_count > 0 ? (int) round($cand_with_vol / $cand_count * 100) : 0;
        $kd_pct  = $cand_count > 0 ? (int) round($cand_with_kd  / $cand_count * 100) : 0;

        // 2. Apply thresholds ─────────────────────────────────────────────────

        $raw_ok     = $raw_count     >= 100;
        $cand_ok    = $cand_count    >= 50;
        $cluster_ok = $cluster_count >= 5;
        $vol_ok     = $cand_count    === 0 || $vol_pct >= 50;
        $kd_ok      = $cand_count    === 0 || $kd_pct  >= 50;

        // 3. Derive quality-limit messages from actual state ──────────────────
        // These are truthful and derived — not generic fear text.

        $limits = [];
        if (!$dfs_ok) {
            $limits[] = __('DataForSEO is not configured — suggestions have no keyword difficulty (KD), search volume, SERP weakness, or competitor backlink data. Priority scoring is unreliable without this.', 'tmwseo');
        }
        if (!$gsc_connected) {
            $limits[] = __('Google Search Console is not connected — suggestions are blind to real clicks, impressions, and CTR from your live site. Ranking probability uses placeholder data instead.', 'tmwseo');
        }
        if (!$ai_ok) {
            $limits[] = __('No AI provider is configured — content briefs, intent classification, SEO copy generation, and draft enrichment are all unavailable.', 'tmwseo');
        }
        if ($competitor_count === 0) {
            $limits[] = __('No competitor domains are configured — competitor gap analysis is disabled and suggestions cannot include keyword gap or domain intersection signals.', 'tmwseo');
        }
        if ($raw_count < 100) {
            $limits[] = sprintf(
                __('Keyword corpus is too small (%d raw keywords; recommended minimum: 100) — suggestions cover only a fraction of available opportunities.', 'tmwseo'),
                $raw_count
            );
        }
        if ($cand_count >= 50 && !$vol_ok) {
            $limits[] = sprintf(
                __('Only %d%% of keyword candidates have search volume data — run the keyword cycle to fetch missing metrics so suggestion priority reflects real traffic opportunity.', 'tmwseo'),
                $vol_pct
            );
        }
        if ($cand_count >= 50 && !$kd_ok) {
            $limits[] = sprintf(
                __('Only %d%% of keyword candidates have difficulty (KD) data — run the keyword cycle to fetch missing KD so content improvement difficulty is scored reliably.', 'tmwseo'),
                $kd_pct
            );
        }

        // 4. Row renderer (closure — status, label, why, action_html) ─────────
        // $action_html must be pre-sanitized safe HTML.

        $render_row = static function(
            string $status,
            string $label,
            string $why,
            string $action_html
        ): void {
            $badge_map = [
                'ok'      => ['style' => 'background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;', 'text' => '✓ Connected'],
                'warn'    => ['style' => 'background:#fefce8;color:#78350f;border:1px solid #fde68a;', 'text' => '⚠ Warning'],
                'partial' => ['style' => 'background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;', 'text' => '◑ Partial'],
                'missing' => ['style' => 'background:#fef2f2;color:#991b1b;border:1px solid #fecaca;', 'text' => '✗ Missing'],
            ];
            $badge = $badge_map[$status] ?? $badge_map['missing'];
            echo '<tr>';
            echo '<td style="vertical-align:top;padding-top:10px;">';
            echo '<span style="display:inline-block;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap;' . esc_attr($badge['style']) . '">';
            echo esc_html($badge['text']);
            echo '</span></td>';
            echo '<td style="vertical-align:top;"><strong>' . esc_html($label) . '</strong></td>';
            echo '<td style="vertical-align:top;font-size:12px;color:#4b5563;">' . esc_html($why) . '</td>';
            echo '<td style="vertical-align:top;font-size:12px;">' . $action_html . '</td>';
            echo '</tr>';
        };

        // 5. Render section ────────────────────────────────────────────────────

        AdminUI::section_start(
            __('Helper & Data Readiness', 'tmwseo'),
            __('Every helper below improves suggestion quality. Missing helpers do not block the plugin — they reduce how much intelligence suggestions can carry.', 'tmwseo')
        );

        echo '<div class="tmwui-table-wrap">';
        echo '<table class="widefat striped" style="table-layout:fixed;">';
        echo '<colgroup><col style="width:120px;"><col style="width:175px;"><col><col style="width:270px;"></colgroup>';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Status', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Helper / Data', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Why it matters for suggestion quality', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Next action', 'tmwseo') . '</th>';
        echo '</tr></thead><tbody>';

        // DataForSEO
        $dfs_action = $dfs_ok
            ? esc_html__('Configured.', 'tmwseo')
                . ' <a href="' . esc_url(admin_url('admin.php?page=tmwseo-connections')) . '">'
                . esc_html__('View Connections', 'tmwseo') . '</a>'
            : '<a href="' . esc_url(admin_url('admin.php?page=tmwseo-settings&stab=dataforseo')) . '">'
                . esc_html__('Add credentials in Settings → DataForSEO', 'tmwseo') . '</a>';
        $render_row(
            $dfs_ok ? 'ok' : 'missing',
            'DataForSEO',
            __('KD, search volume, SERP live data, competitor backlink authority, and domain gap analysis. Without this, suggestion priority cannot reflect real traffic or competitive difficulty.', 'tmwseo'),
            $dfs_action
        );

        // Google Search Console
        if ($gsc_connected) {
            $gsc_status = 'ok';
            $gsc_action = esc_html__('Connected and active.', 'tmwseo')
                . ' <a href="' . esc_url(admin_url('admin.php?page=tmwseo-connections')) . '">'
                . esc_html__('View Connections', 'tmwseo') . '</a>';
        } elseif ($gsc_configured) {
            $gsc_status = 'partial';
            $gsc_action = '<a href="' . esc_url(GSCApi::get_auth_url()) . '">'
                . esc_html__('Credentials saved — click to authorise with Google →', 'tmwseo') . '</a>';
        } else {
            $gsc_status = 'missing';
            $gsc_action = '<a href="' . esc_url(admin_url('admin.php?page=tmwseo-settings&stab=gsc')) . '">'
                . esc_html__('Add OAuth2 credentials in Settings → Google SC', 'tmwseo') . '</a>';
        }
        $render_row(
            $gsc_status,
            'Google Search Console',
            __('Real clicks, impressions, CTR, and position for your site. Without it, ranking probability uses placeholder data and suggestions cannot factor in what is already driving traffic.', 'tmwseo'),
            $gsc_action
        );

        // AI Provider
        if ($openai_ok) {
            $ai_status = 'ok';
            $ai_label  = 'OpenAI' . ($anthropic_ok ? ' + Anthropic fallback' : '');
            $ai_action = esc_html__('Configured.', 'tmwseo')
                . ' <a href="' . esc_url(admin_url('admin.php?page=tmwseo-settings&stab=ai')) . '">'
                . esc_html__('Edit in Settings → AI', 'tmwseo') . '</a>';
        } elseif ($anthropic_ok) {
            $ai_status = 'partial';
            $ai_label  = 'Anthropic (OpenAI missing)';
            $ai_action = '<a href="' . esc_url(admin_url('admin.php?page=tmwseo-settings&stab=ai')) . '">'
                . esc_html__('Add OpenAI key in Settings → AI for full coverage', 'tmwseo') . '</a>';
        } else {
            $ai_status = 'missing';
            $ai_label  = 'AI Provider';
            $ai_action = '<a href="' . esc_url(admin_url('admin.php?page=tmwseo-settings&stab=ai')) . '">'
                . esc_html__('Add API key in Settings → AI', 'tmwseo') . '</a>';
        }
        $render_row(
            $ai_status,
            $ai_label,
            __('Content briefs, intent classification, SEO copy generation, draft enrichment, and ranking probability explanations. Without AI, brief generation and suggestion reasoning are unavailable.', 'tmwseo'),
            $ai_action
        );

        // Competitor Domains
        if ($competitor_count >= 3) {
            $comp_status = 'ok';
            $comp_why_sfx = sprintf(__('%d domain(s) tracked.', 'tmwseo'), $competitor_count);
        } elseif ($competitor_count >= 1) {
            $comp_status = 'warn';
            $comp_why_sfx = sprintf(__('%d domain(s) tracked — add more for broader gap coverage (3+ recommended).', 'tmwseo'), $competitor_count);
        } else {
            $comp_status = 'missing';
            $comp_why_sfx = __('No domains configured — gap analysis disabled.', 'tmwseo');
        }
        $render_row(
            $comp_status,
            'Competitor Domains',
            __('Keyword gap detection, domain intersection, and competitor content monitoring. Required for gap-based suggestions. ', 'tmwseo') . $comp_why_sfx,
            '<a href="' . esc_url(admin_url('admin.php?page=tmwseo-competitor-domains')) . '">'
                . esc_html__('Manage Competitor Domains', 'tmwseo') . '</a>'
        );

        // Keyword Corpus
        $corpus_summary = sprintf(
            __('%1$d raw · %2$d candidates · %3$d clusters', 'tmwseo'),
            $raw_count, $cand_count, $cluster_count
        );
        if ($raw_ok && $cand_ok && $cluster_ok) {
            $corpus_status = 'ok';
            $corpus_why    = $corpus_summary . '. ' . __('Corpus meets minimum thresholds (100 raw / 50 candidates / 5 clusters).', 'tmwseo');
        } elseif ($raw_count > 0) {
            $corpus_status = 'warn';
            $corpus_why    = $corpus_summary . '. ' . __('Below recommended minimums: 100 raw / 50 candidates / 5 clusters. Run the keyword cycle.', 'tmwseo');
        } else {
            $corpus_status = 'missing';
            $corpus_why    = __('No keywords discovered yet. Without a keyword corpus, content improvement suggestions have no volume, KD, or cluster data.', 'tmwseo');
        }
        $render_row(
            $corpus_status,
            'Keyword Corpus',
            $corpus_why,
            '<a href="' . esc_url(admin_url('admin.php?page=tmwseo-keywords')) . '">'
                . esc_html__('View Keywords → Run Keyword Cycle', 'tmwseo') . '</a>'
        );

        // Search Volume Coverage
        if ($cand_count === 0) {
            $render_row(
                'missing',
                'Search Volume Coverage',
                __('No candidates yet — volume data unavailable. Grow the keyword corpus first.', 'tmwseo'),
                '<a href="' . esc_url(admin_url('admin.php?page=tmwseo-keywords')) . '">'
                    . esc_html__('View Keywords', 'tmwseo') . '</a>'
            );
        } else {
            $vol_status = $vol_ok ? 'ok' : 'warn';
            $vol_why    = sprintf(
                __('%1$d%% of %2$d candidates have volume data. Threshold: ≥50%% recommended. Missing volume = suggestion priority cannot reflect real traffic opportunity.', 'tmwseo'),
                $vol_pct, $cand_count
            );
            $vol_action = $dfs_ok
                ? '<a href="' . esc_url(admin_url('admin.php?page=tmwseo-keywords')) . '">'
                    . esc_html__('Run Keyword Cycle on Keywords page', 'tmwseo') . '</a>'
                : '<a href="' . esc_url(admin_url('admin.php?page=tmwseo-settings&stab=dataforseo')) . '">'
                    . esc_html__('Connect DataForSEO first', 'tmwseo') . '</a>';
            $render_row($vol_status, 'Search Volume Coverage', $vol_why, $vol_action);
        }

        // KD Coverage
        if ($cand_count === 0) {
            $render_row(
                'missing',
                'KD Coverage',
                __('No candidates yet — KD data unavailable. Grow the keyword corpus first.', 'tmwseo'),
                '<a href="' . esc_url(admin_url('admin.php?page=tmwseo-keywords')) . '">'
                    . esc_html__('View Keywords', 'tmwseo') . '</a>'
            );
        } else {
            $kd_status = $kd_ok ? 'ok' : 'warn';
            $kd_why    = sprintf(
                __('%1$d%% of %2$d candidates have KD data. Threshold: ≥50%% recommended. Missing KD = content improvement difficulty scoring is unreliable, suggestions may be mis-prioritised.', 'tmwseo'),
                $kd_pct, $cand_count
            );
            $kd_action = $dfs_ok
                ? '<a href="' . esc_url(admin_url('admin.php?page=tmwseo-keywords')) . '">'
                    . esc_html__('Run Keyword Cycle on Keywords page', 'tmwseo') . '</a>'
                : '<a href="' . esc_url(admin_url('admin.php?page=tmwseo-settings&stab=dataforseo')) . '">'
                    . esc_html__('Connect DataForSEO first', 'tmwseo') . '</a>';
            $render_row($kd_status, 'KD Coverage', $kd_why, $kd_action);
        }

        echo '</tbody></table>';
        echo '</div>'; // .tmwui-table-wrap

        // 6. Quality limits box ────────────────────────────────────────────────

        if (!empty($limits)) {
            echo '<div style="margin-top:12px;padding:14px 16px;background:#fefce8;border:1px solid #fde68a;border-radius:8px;">';
            echo '<p style="font-size:13px;font-weight:700;color:#78350f;margin:0 0 8px;">'
                . esc_html__('Current suggestion quality limits:', 'tmwseo') . '</p>';
            echo '<ul style="list-style:disc;margin:0 0 0 18px;padding:0;">';
            foreach ($limits as $limit) {
                echo '<li style="font-size:12px;color:#92400e;margin-bottom:4px;line-height:1.5;">' . esc_html($limit) . '</li>';
            }
            echo '</ul>';
            echo '<p style="font-size:11px;color:#a16207;margin:10px 0 0;">'
                . esc_html__('These limits are derived from actual plugin state — not generic warnings. Fix the helpers above to improve suggestion quality.', 'tmwseo')
                . '</p>';
            echo '</div>';
        } else {
            AdminUI::alert(
                __('All helpers connected and keyword data meets minimum thresholds. Suggestion quality is at full capacity.', 'tmwseo'),
                'info'
            );
        }

        // 7. Quick action shortcuts ────────────────────────────────────────────

        echo '<div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">';
        echo '<strong style="font-size:12px;color:#6b7280;margin-right:4px;">'
            . esc_html__('Quick links:', 'tmwseo') . '</strong>';
        echo '<a class="button button-small" href="' . esc_url(admin_url('admin.php?page=tmwseo-connections')) . '">'
            . esc_html__('All Connections', 'tmwseo') . '</a>';
        echo '<a class="button button-small" href="' . esc_url(admin_url('admin.php?page=tmwseo-competitor-domains')) . '">'
            . esc_html__('Competitor Domains', 'tmwseo') . '</a>';
        echo '<a class="button button-small" href="' . esc_url(admin_url('admin.php?page=tmwseo-keywords')) . '">'
            . esc_html__('Keywords &amp; Keyword Cycle', 'tmwseo') . '</a>';
        echo '<a class="button button-small" href="' . esc_url(admin_url('admin.php?page=tmwseo-suggestions')) . '">'
            . esc_html__('View Suggestions', 'tmwseo') . '</a>';
        echo '</div>';

        $seed_diag = SeedRegistry::diagnostics();
        $seed_sources = (array) ($seed_diag['seed_sources'] ?? []);
        $seed_rows = [];
        foreach ($seed_sources as $seed_source => $seed_count) {
            $seed_rows[] = esc_html((string) $seed_source) . ' seeds: ' . (int) $seed_count;
        }

        echo '<div style="margin-top:12px;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">';
        echo '<p style="margin:0 0 8px;font-weight:600;">' . esc_html__('Seed Health Monitoring', 'tmwseo') . '</p>';
        echo '<ul style="margin:0 0 0 18px;list-style:disc;">';
        echo '<li>' . esc_html__('Total seeds:', 'tmwseo') . ' ' . (int) ($seed_diag['total_seeds'] ?? 0) . '</li>';
        echo '<li>' . esc_html__('Seeds used this cycle:', 'tmwseo') . ' ' . (int) ($seed_diag['seeds_used_this_cycle'] ?? 0) . '</li>';
        echo '<li>' . esc_html__('Duplicate prevention count:', 'tmwseo') . ' ' . (int) ($seed_diag['duplicate_prevention_count'] ?? 0) . '</li>';
        foreach ($seed_rows as $seed_row) {
            echo '<li>' . $seed_row . '</li>';
        }
        echo '</ul>';
        echo '</div>';

        AdminUI::section_end();
    }
}
