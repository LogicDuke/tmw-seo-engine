<?php
namespace TMWSEO\Engine;

use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Services\TrustPolicy;

if (!defined('ABSPATH')) { exit; }

class Admin {

    const MENU_SLUG = 'tmwseo-engine';

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_menu', [__CLASS__, 'reorder_admin_menus'], 9999);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
        add_action('admin_notices', [__CLASS__, 'render_admin_notices']);
        add_action('admin_post_tmwseo_run_worker', [__CLASS__, 'run_worker_now']);
        add_action('admin_post_tmwseo_save_settings', [__CLASS__, 'save_settings']);
        add_action('admin_post_tmwseo_run_keyword_cycle', [__CLASS__, 'run_keyword_cycle_now']);
        add_action('admin_post_tmwseo_run_pagespeed_cycle', [__CLASS__, 'run_pagespeed_cycle_now']);
        add_action('admin_post_tmwseo_enable_indexing', [__CLASS__, 'enable_indexing_now']);
        add_action('admin_post_tmwseo_optimize_post_now', [__CLASS__, 'handle_optimize_post_now']);
        add_action('admin_post_tmwseo_refresh_keywords_now', [__CLASS__, 'handle_refresh_keywords_now']);
        add_action('wp_ajax_tmwseo_generate_now', [__CLASS__, 'ajax_generate_now']);
        add_action('wp_ajax_tmwseo_kick_worker', [__CLASS__, 'ajax_kick_worker']);
        add_action('admin_post_tmwseo_import_keywords', [__CLASS__, 'import_keywords']);
        add_action('admin_post_tmwseo_bulk_autofix', [__CLASS__, 'handle_bulk_autofix']);
        add_action('tmw_manual_cycle_event', ['\TMWSEO\Engine\Keywords\UnifiedKeywordWorkflowService', 'run_cycle'], 10, 1);
    }

    public static function handle_bulk_autofix(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_bulk_autofix');

        $post_types = ['post', 'model', 'tmw_category_page'];
        $posts = get_posts([
            'post_type' => $post_types,
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'fields' => 'ids',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'cache_results' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        $updated = 0;

        foreach ($posts as $post_id) {
            $keyword = get_post_meta((int)$post_id, 'rank_math_focus_keyword', true);
            $meta = get_post_meta((int)$post_id, 'rank_math_description', true);

            $post = get_post((int)$post_id);
            if (!$post) {
                continue;
            }

            $title = trim((string)$post->post_title);
            if ($title === '') {
                continue;
            }

            $changed = false;

            if (trim((string)$keyword) === '') {
                $words = preg_split('/\s+/', strtolower($title));
                $words = is_array($words) ? array_values(array_filter($words, static fn($word) => $word !== '')) : [];
                $new_keyword = implode(' ', array_slice($words, 0, 4));

                if ($new_keyword !== '') {
                    update_post_meta((int)$post_id, 'rank_math_focus_keyword', sanitize_text_field($new_keyword));
                    $changed = true;
                }
            }

            if (trim((string)$meta) === '') {
                $new_meta = sprintf(
                    'Watch %s online. Discover premium streaming content and exclusive live experiences.',
                    $title
                );
                $new_meta = mb_substr($new_meta, 0, 155);
                update_post_meta((int)$post_id, 'rank_math_description', sanitize_text_field($new_meta));
                $changed = true;
            }

            if ($changed) {
                update_post_meta((int)$post_id, '_tmwseo_autofixed', current_time('mysql'));
                $updated++;
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&bulk_updated=' . $updated));
        exit;
    }

    public static function enqueue_admin_assets(string $hook): void {
        $allowed_hooks = [
            'toplevel_page_' . self::MENU_SLUG,
            self::MENU_SLUG . '_page_tmwseo-suggestions',
            self::MENU_SLUG . '_page_tmwseo-command-center',
            self::MENU_SLUG . '_page_tmwseo-content-briefs',
            self::MENU_SLUG . '_page_tmwseo-competitor-domains',
            self::MENU_SLUG . '_page_tmwseo-keywords',
            self::MENU_SLUG . '_page_tmwseo-opportunities',
            self::MENU_SLUG . '_page_tmwseo-ranking-probability',
            self::MENU_SLUG . '_page_tmwseo-reports',
            self::MENU_SLUG . '_page_tmwseo-connections',
            self::MENU_SLUG . '_page_tmwseo-settings',
            self::MENU_SLUG . '_page_tmwseo-tools',
            self::MENU_SLUG . '_page_tmw-seo-debug',
        ];

        if (!in_array($hook, $allowed_hooks, true)) {
            return;
        }

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

    public static function register_settings(): void {
        register_setting(
            'tmwseo_settings_group',
            'tmwseo_engine_settings',
            [
                'type' => 'array',
                'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
                'default' => [],
            ]
        );
    }

    public static function sanitize_settings($input): array {
        $input = is_array($input) ? $input : [];

        if (($input['tmwseo_settings_section'] ?? '') === 'affiliate') {
            $current = get_option('tmwseo_engine_settings', []);
            $current = is_array($current) ? $current : [];
            $current['affiliate'] = self::sanitize_affiliate_settings($input['affiliate'] ?? []);
            return $current;
        }

        // Preserve any existing settings keys not present in this form submission,
        // so that partial saves (e.g., tabbed Settings pages) don't wipe keys.
        $existing = get_option('tmwseo_engine_settings', []);
        $existing = is_array($existing) ? $existing : [];

        $mode = sanitize_text_field((string)($input['openai_mode'] ?? $existing['openai_mode'] ?? 'hybrid'));
        if (!in_array($mode, ['quality', 'bulk', 'hybrid'], true)) {
            $mode = 'hybrid';
        }

        $primary = sanitize_text_field((string)($input['openai_model_primary'] ?? $existing['openai_model_primary'] ?? 'gpt-4o'));
        $bulk    = sanitize_text_field((string)($input['openai_model_bulk'] ?? $existing['openai_model_bulk'] ?? 'gpt-4o-mini'));

        $voice = sanitize_text_field((string)($input['brand_voice'] ?? $existing['brand_voice'] ?? 'premium'));
        if (!in_array($voice, ['premium', 'neutral'], true)) {
            $voice = 'premium';
        }

        $ai_primary = sanitize_text_field((string)($input['tmwseo_ai_primary'] ?? $existing['tmwseo_ai_primary'] ?? 'openai'));
        if (!in_array($ai_primary, ['openai', 'anthropic'], true)) {
            $ai_primary = 'openai';
        }

        $sanitized = [
            // Safety — always locked on
            'manual_control_mode' => 1,
            // safe_mode: simple checkbox. Admin::render_settings() is a single-page form —
            // absent key = user explicitly unchecked. Intentional OFF saves correctly.
            'safe_mode' => !empty($input['safe_mode']) ? 1 : 0,

            // OpenAI
            'openai_api_key'        => sanitize_text_field((string)($input['openai_api_key'] ?? $existing['openai_api_key'] ?? '')),
            'openai_mode'           => $mode,
            'openai_model_primary'  => $primary,
            'openai_model_bulk'     => $bulk,
            'openai_model'          => ($mode === 'bulk') ? $bulk : $primary,
            'brand_voice'           => $voice,
            'tmwseo_dry_run_mode'   => !empty($input['tmwseo_dry_run_mode']) ? 1 : 0,
            'auto_clear_noindex'    => !empty($input['auto_clear_noindex']) ? 1 : 0,
            'template_external_link_enabled' => !empty($input['template_external_link_enabled']) ? 1 : 0,
            'include_external_info_link'     => !empty($input['include_external_info_link']) ? 1 : 0,

            // v4.2: Anthropic / AI Router
            'tmwseo_anthropic_api_key' => sanitize_text_field((string)($input['tmwseo_anthropic_api_key'] ?? $existing['tmwseo_anthropic_api_key'] ?? '')),
            'tmwseo_ai_primary'        => $ai_primary,
            'tmwseo_openai_budget_usd' => max(0.0, (float)($input['tmwseo_openai_budget_usd'] ?? $existing['tmwseo_openai_budget_usd'] ?? 20.0)),

            // DataForSEO
            'dataforseo_login'         => sanitize_text_field((string)($input['dataforseo_login'] ?? $existing['dataforseo_login'] ?? '')),
            'dataforseo_password'      => sanitize_text_field((string)($input['dataforseo_password'] ?? $existing['dataforseo_password'] ?? '')),
            'dataforseo_location_code' => sanitize_text_field((string)($input['dataforseo_location_code'] ?? $existing['dataforseo_location_code'] ?? '2840')),
            'dataforseo_language_code' => sanitize_text_field((string)($input['dataforseo_language_code'] ?? $existing['dataforseo_language_code'] ?? 'en')),

            // v4.2: Google Search Console OAuth2
            'gsc_client_id'     => sanitize_text_field((string)($input['gsc_client_id'] ?? $existing['gsc_client_id'] ?? '')),
            'gsc_client_secret' => sanitize_text_field((string)($input['gsc_client_secret'] ?? $existing['gsc_client_secret'] ?? '')),
            'gsc_site_url'      => esc_url_raw((string)($input['gsc_site_url'] ?? $existing['gsc_site_url'] ?? '')),

            // v4.2: Google Indexing API
            'google_indexing_service_account_json' => sanitize_textarea_field((string)($input['google_indexing_service_account_json'] ?? $existing['google_indexing_service_account_json'] ?? '')),
            'indexing_api_post_types'              => sanitize_text_field((string)($input['indexing_api_post_types'] ?? $existing['indexing_api_post_types'] ?? 'model,video,tmw_video')),

            // v4.2: Schema + Orphan — single-page form, absent = unchecked = 0.
            'schema_enabled'    => !empty($input['schema_enabled']) ? 1 : 0,
            'schema_post_types' => sanitize_text_field((string)($input['schema_post_types'] ?? $existing['schema_post_types'] ?? 'model,video,tmw_video')),
            'orphan_scan_enabled' => !empty($input['orphan_scan_enabled']) ? 1 : 0,

            // Keyword engine
            'keyword_min_volume'     => max(0, (int)($input['keyword_min_volume'] ?? $existing['keyword_min_volume'] ?? 30)),
            'keyword_max_kd'         => max(0, (int)($input['keyword_max_kd'] ?? $existing['keyword_max_kd'] ?? 60)),
            'keyword_new_limit'      => max(0, (int)($input['keyword_new_limit'] ?? $existing['keyword_new_limit'] ?? 300)),
            'keyword_kd_batch_limit' => max(0, (int)($input['keyword_kd_batch_limit'] ?? $existing['keyword_kd_batch_limit'] ?? 300)),
            'keyword_pages_per_day'  => max(0, (int)($input['keyword_pages_per_day'] ?? $existing['keyword_pages_per_day'] ?? 3)),
            'competitor_domains'     => sanitize_textarea_field((string)($input['competitor_domains'] ?? $existing['competitor_domains'] ?? '')),

            // Misc
            'google_pagespeed_api_key' => sanitize_text_field((string)($input['google_pagespeed_api_key'] ?? $existing['google_pagespeed_api_key'] ?? '')),
            'serper_api_key'           => sanitize_text_field((string)($input['serper_api_key'] ?? $existing['serper_api_key'] ?? '')),
            'intel_max_seeds'          => max(1, (int)($input['intel_max_seeds'] ?? $existing['intel_max_seeds'] ?? 3)),
            'intel_max_keywords'       => max(50, (int)($input['intel_max_keywords'] ?? $existing['intel_max_keywords'] ?? 400)),
            'debug_mode'               => !empty($input['debug_mode']) ? 1 : 0,

            // Affiliates (preserved from existing)
            'affiliate' => self::sanitize_affiliate_settings($input['affiliate'] ?? ($existing['affiliate'] ?? [])),
        ];

        return $sanitized;
    }

    private static function sanitize_affiliate_settings($input): array {
        $input = is_array($input) ? $input : [];
        $platforms_input = is_array($input['platforms'] ?? null) ? $input['platforms'] : [];

        $platform_defaults = self::get_affiliate_platform_defaults();
        $platforms = [];

        foreach ($platform_defaults as $platform_key => $defaults) {
            $platform_input = is_array($platforms_input[$platform_key] ?? null) ? $platforms_input[$platform_key] : [];

            $platforms[$platform_key] = [
                'enabled' => !empty($platform_input['enabled']) ? 1 : 0,
                'affiliate_link_pattern' => sanitize_text_field((string)($platform_input['affiliate_link_pattern'] ?? $defaults['affiliate_link_pattern'])),
                'campaign' => sanitize_text_field((string)($platform_input['campaign'] ?? '')),
                'source' => sanitize_text_field((string)($platform_input['source'] ?? '')),
            ];
        }

        return [
            'platforms' => $platforms,
        ];
    }

    private static function get_affiliate_platform_defaults(): array {
        return [
            'livejasmin' => [
                'label' => 'LiveJasmin',
                'base_profile_url' => 'https://www.livejasmin.com/en/profile/',
                'affiliate_link_pattern' => 'https://YOURAFFBASE/?campaign={campaign}&url={encoded_profile_url}',
            ],
            'stripchat' => [
                'label' => 'Stripchat',
                'base_profile_url' => 'https://stripchat.com/',
                'affiliate_link_pattern' => 'https://YOURAFFBASE/?campaign={campaign}&url={encoded_profile_url}',
            ],
        ];
    }

    public static function menu(): void {
        // ── Top-level entry point → Command Center directly (no redirect) ──
        add_menu_page(
            __('TMW SEO Engine', 'tmwseo'),
            __('TMW SEO Engine', 'tmwseo'),
            'manage_options',
            self::MENU_SLUG,
            ['\\TMWSEO\\Engine\\Admin\\CommandCenter', 'render'],
            'dashicons-chart-area',
            58
        );

        // First submenu entry shares slug with top-level — relabels the sidebar item.
        add_submenu_page(
            self::MENU_SLUG,
            __('Command Center', 'tmwseo'),
            __('&#9881; Command Center', 'tmwseo'),
            'manage_options',
            self::MENU_SLUG,
            ['\\TMWSEO\\Engine\\Admin\\CommandCenter', 'render']
        );

        // ── Workflow ───────────────────────────────────────────────────────
        add_submenu_page(self::MENU_SLUG, __('Suggestions', 'tmwseo'),    __('Suggestions', 'tmwseo'),    'manage_options', 'tmwseo-suggestions',    ['\\TMWSEO\\Engine\\Suggestions\\SuggestionsAdminPage', 'render_static_suggestions']);
        add_submenu_page(self::MENU_SLUG, __('Content Briefs', 'tmwseo'), __('Content Briefs', 'tmwseo'), 'manage_options', 'tmwseo-content-briefs', ['\\TMWSEO\\Engine\\Suggestions\\SuggestionsAdminPage', 'render_static_briefs']);

        // ── Intelligence ───────────────────────────────────────────────────
        add_submenu_page(self::MENU_SLUG, __('Keywords', 'tmwseo'),            __('Keywords', 'tmwseo'),            'manage_options', 'tmwseo-keywords',            [__CLASS__, 'render_keywords']);
        add_submenu_page(self::MENU_SLUG, __('Opportunities', 'tmwseo'),       __('Opportunities', 'tmwseo'),       'manage_options', 'tmwseo-opportunities',       ['\\TMWSEO\\Engine\\Opportunities\\OpportunityUI', 'render_static']);
        add_submenu_page(self::MENU_SLUG, __('Competitor Domains', 'tmwseo'),  __('Competitor Domains', 'tmwseo'),  'manage_options', 'tmwseo-competitor-domains',  ['\\TMWSEO\\Engine\\Suggestions\\SuggestionsAdminPage', 'render_static_competitor_domains']);
        add_submenu_page(self::MENU_SLUG, __('Ranking Probability', 'tmwseo'), __('Ranking Probability', 'tmwseo'), 'manage_options', 'tmwseo-ranking-probability', [__CLASS__, 'render_ranking_probability']);

        // ── Content ────────────────────────────────────────────────────────
        add_submenu_page(self::MENU_SLUG, __('Models', 'tmwseo'), __('Models', 'tmwseo'), 'manage_options', 'tmwseo-models', [__CLASS__, 'render_models_redirect']);

        // ── Reports ────────────────────────────────────────────────────────
        add_submenu_page(self::MENU_SLUG, __('Reports', 'tmwseo'), __('Reports', 'tmwseo'), 'manage_options', 'tmwseo-reports', ['\\TMWSEO\\Engine\\Admin\\AdminDashboardV2', 'page_reports']);

        // ── System ─────────────────────────────────────────────────────────
        add_submenu_page(self::MENU_SLUG, __('Connections', 'tmwseo'), __('Connections', 'tmwseo'), 'manage_options', 'tmwseo-connections', ['\\TMWSEO\\Engine\\Admin\\AdminDashboardV2', 'page_connections']);
        add_submenu_page(self::MENU_SLUG, __('Settings', 'tmwseo'),    __('Settings', 'tmwseo'),    'manage_options', 'tmwseo-settings',    [__CLASS__, 'render_settings']);
        add_submenu_page(self::MENU_SLUG, __('Tools', 'tmwseo'),       __('Tools', 'tmwseo'),       'manage_options', 'tmwseo-tools',       [__CLASS__, 'render_tools']);
        add_submenu_page(self::MENU_SLUG, __('Debug Dashboard', 'tmwseo'), __('Debug Dashboard', 'tmwseo'), 'manage_options', 'tmw-seo-debug', ['\\TMWSEO\\Engine\\Debug\\DebugDashboard', 'render_page']);

        // ── Hidden (legacy routes — direct URLs still work) ────────────────
        add_submenu_page(null, __('Drafts to Review', 'tmwseo'), __('Drafts to Review', 'tmwseo'), 'manage_options', 'tmwseo-generated',   [__CLASS__, 'render_generated_pages']);
        add_submenu_page(null, __('Logs', 'tmwseo'),             __('Logs', 'tmwseo'),             'manage_options', 'tmwseo-logs',        [__CLASS__, 'render_logs']);
        add_submenu_page(null, __('Engine Monitor', 'tmwseo'),   __('Engine Monitor', 'tmwseo'),   'manage_options', 'tmw-engine-monitor', [__CLASS__, 'render_engine_monitor']);
        add_submenu_page(null, __('Migration', 'tmwseo'),        __('Migration', 'tmwseo'),        'manage_options', 'tmwseo-migration',   [__CLASS__, 'render_migration']);
        add_submenu_page(null, __('Import', 'tmwseo'),           __('Import', 'tmwseo'),           'manage_options', 'tmwseo-import',      [__CLASS__, 'render_import']);

        // Legacy V2 slugs → server-side redirect to canonical pages (no JS bounces)
        add_submenu_page(null, '', '', 'manage_options', 'tmwseo-command-center',   ['\\TMWSEO\\Engine\\Admin\\CommandCenter', 'render']); // keep old slug working
        add_submenu_page(null, '', '', 'manage_options', 'tmwseo-engine-v2',        [__CLASS__, 'legacy_redirect_command_center']);
        add_submenu_page(null, '', '', 'manage_options', 'tmwseo-pagespeed',        [__CLASS__, 'render_pagespeed_redirect']);
        add_submenu_page(null, '', '', 'manage_options', 'tmwseo-cfg',              [__CLASS__, 'render_settings_redirect']);
        add_submenu_page(null, '', '', 'manage_options', 'tmwseo-kw',              [__CLASS__, 'render_keywords_redirect']);
        add_submenu_page(null, '', '', 'manage_options', 'tmwseo-reports-legacy',   [__CLASS__, 'render_reports_redirect']);
        add_submenu_page(null, '', '', 'manage_options', 'tmwseo-connections-legacy',[__CLASS__, 'render_connections_redirect']);
        add_submenu_page(null, '', '', 'manage_options', 'tmwseo-diag',            [__CLASS__, 'render_debug_redirect']);
    }

    public static function legacy_redirect_command_center(): void {
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
        exit;
    }

    /**
     * Rebuild $submenu for our top-level slug in the desired canonical order,
     * removing any duplicates registered by other classes.
     * Runs at priority 9999 (after all admin_menu callbacks fire).
     */
    public static function reorder_admin_menus(): void {
        global $submenu;
        $slug = self::MENU_SLUG;
        if (!isset($submenu[$slug]) || !is_array($submenu[$slug])) {
            return;
        }

        // Desired visible order (slug → keep).
        $desired_order = [
            self::MENU_SLUG,              // Command Center (top entry)
            'tmwseo-suggestions',
            'tmwseo-content-briefs',
            'tmwseo-keywords',
            'tmwseo-opportunities',
            'tmwseo-competitor-domains',
            'tmwseo-ranking-probability',
            'tmwseo-models',
            'tmwseo-reports',
            'tmwseo-connections',
            'tmwseo-settings',
            'tmwseo-tools',
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
        wp_safe_redirect(admin_url('admin.php?page=tmw-seo-debug'));
        exit;
    }

    // ── Ranking Probability page ───────────────────────────────────────────

    public static function render_ranking_probability(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $intel_table = $wpdb->prefix . 'tmwseo_intelligence';

        $rows      = [];
        $last_run  = '';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$intel_table}'") === $intel_table) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, signal_type, signal_value, computed_at
                 FROM {$intel_table}
                 WHERE signal_type = %s
                 ORDER BY CAST(signal_value AS DECIMAL(5,2)) DESC
                 LIMIT 50",
                'ranking_probability'
            ), ARRAY_A) ?: [];

            // Most recent computation timestamp
            $last_run = (string) $wpdb->get_var(
                "SELECT MAX(computed_at) FROM {$intel_table} WHERE signal_type = 'ranking_probability'"
            );
        }

        // Handle "Run Now" POST
        if (isset($_POST['tmwseo_run_ranking_probability']) && check_admin_referer('tmwseo_run_ranking_probability')) {
            if (class_exists('\\TMWSEO\\Engine\\Intelligence\\RankingProbabilityOrchestrator')) {
                try {
                    \TMWSEO\Engine\Intelligence\RankingProbabilityOrchestrator::run_all();
                    wp_safe_redirect(admin_url('admin.php?page=tmwseo-ranking-probability&tmw_ran=1'));
                    exit;
                } catch (\Throwable $e) {
                    // fall through to show page with error
                }
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Ranking Probability', 'tmwseo') . '</h1>';
        echo '<p>' . esc_html__('Estimates how likely each page is to rank for its target keyword — scored from 7 signals: content quality, domain authority, keyword difficulty, backlink profile, SERP weakness, topical authority, and freshness.', 'tmwseo') . '</p>';

        // Trust notice
        echo '<div style="background:#f0fdf4;border-left:4px solid #16a34a;padding:10px 14px;margin-bottom:20px;font-size:13px;border-radius:0 6px 6px 0;">';
        echo '<strong>✅ Read-only intelligence.</strong> This page does not publish, modify, or schedule anything automatically. All data is generated on demand via the Run button below.';
        echo '</div>';

        // Status bar
        echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">';

        // Run Now form
        echo '<form method="post" style="display:inline;">';
        wp_nonce_field('tmwseo_run_ranking_probability');
        echo '<input type="hidden" name="tmwseo_run_ranking_probability" value="1">';
        echo '<button class="button button-primary">&#9654; Run Ranking Probability Scan</button>';
        echo '</form>';

        if ($last_run) {
            echo '<span style="color:#6b7280;font-size:13px;">Last computed: ' . esc_html(substr($last_run, 0, 16)) . '</span>';
        } else {
            echo '<span style="color:#9ca3af;font-size:13px;">No data yet — click Run to generate scores.</span>';
        }

        echo '<a href="' . esc_url(admin_url('admin.php?page=tmwseo-tools')) . '" class="button" style="margin-left:auto;">← Back to Tools</a>';
        echo '</div>';

        if (isset($_GET['tmw_ran'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Ranking probability scan completed. Scores updated below.</p></div>';
        }

        if (empty($rows)) {
            echo '<div class="notice notice-info"><p>';
            echo esc_html__('No ranking probability scores yet. Click "Run Ranking Probability Scan" above to generate them.', 'tmwseo');
            echo '</p></div>';
        } else {
            echo '<p style="color:#6b7280;font-size:13px;">' . esc_html(count($rows)) . ' pages scored. Showing top 50 by probability.</p>';
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Page', 'tmwseo') . '</th>';
            echo '<th>' . esc_html__('Probability Score', 'tmwseo') . '</th>';
            echo '<th>' . esc_html__('Signal Bar', 'tmwseo') . '</th>';
            echo '<th>' . esc_html__('Computed', 'tmwseo') . '</th>';
            echo '<th>' . esc_html__('Actions', 'tmwseo') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($rows as $row) {
                $pid   = (int) $row['post_id'];
                $prob  = min(100, max(0, round((float) $row['signal_value'] * 100)));
                $date  = substr((string) $row['computed_at'], 0, 10);
                $title = get_the_title($pid) ?: "Post #{$pid}";
                $edit  = get_edit_post_link($pid);
                $color = $prob >= 70 ? '#16a34a' : ($prob >= 40 ? '#ca8a04' : '#dc2626');

                echo '<tr>';
                echo '<td><a href="' . esc_url($edit ?: '#') . '">' . esc_html($title) . '</a></td>';
                echo '<td><strong style="color:' . esc_attr($color) . ';font-size:16px;">' . esc_html($prob) . '%</strong></td>';
                echo '<td><div style="background:#e5e7eb;border-radius:4px;height:8px;width:120px;overflow:hidden;"><div style="background:' . esc_attr($color) . ';height:100%;width:' . esc_attr($prob) . '%;"></div></div></td>';
                echo '<td style="color:#9ca3af;font-size:12px;">' . esc_html($date) . '</td>';
                echo '<td><a href="' . esc_url($edit ?: '#') . '" class="button button-small">Edit</a></td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';
    }

    public static function run_worker_now(): void {
        if (!current_user_can('manage_options')) wp_die(__('Insufficient permissions', 'tmwseo'));
        check_admin_referer('tmwseo_run_worker');

        // Always enqueue at least one lightweight job so the button produces
        // a deterministic result (and a log entry), even when the queue is empty.
        Jobs::enqueue('healthcheck', 'system', 0, [
            'trigger' => 'manual',
            'version' => defined('TMWSEO_ENGINE_VERSION') ? TMWSEO_ENGINE_VERSION : 'unknown',
        ]);

        Worker::run();
        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&tmwseo_notice=worker_ran'));
        exit;
    }

    public static function save_settings(): void {
        if (!current_user_can('manage_options')) wp_die(__('Insufficient permissions', 'tmwseo'));
        // New (alpha.6): model routing + brand voice, while keeping legacy "openai_model" for compatibility.
        $mode = sanitize_text_field((string)($_POST['openai_mode'] ?? 'hybrid'));
        if (!in_array($mode, ['quality', 'bulk', 'hybrid'], true)) {
            $mode = 'hybrid';
        }

        $primary = sanitize_text_field((string)($_POST['openai_model_primary'] ?? 'gpt-4o'));
        $bulk = sanitize_text_field((string)($_POST['openai_model_bulk'] ?? 'gpt-4o-mini'));

        $voice = sanitize_text_field((string)($_POST['brand_voice'] ?? 'premium'));
        if (!in_array($voice, ['premium', 'neutral'], true)) {
            $voice = 'premium';
        }

        $opts = [
            'openai_api_key' => sanitize_text_field((string)($_POST['openai_api_key'] ?? '')),

            'openai_mode' => $mode,
            'openai_model_primary' => $primary,
            'openai_model_bulk' => $bulk,
            // Legacy single-model key. Keep in sync so older code can still read it.
            'openai_model' => ($mode === 'bulk') ? $bulk : $primary,

            'brand_voice' => $voice,
            'tmwseo_dry_run_mode' => isset($_POST['tmwseo_dry_run_mode']) ? 1 : 0,
            'auto_clear_noindex' => isset($_POST['auto_clear_noindex']) ? 1 : 0,
            'template_external_link_enabled' => isset($_POST['template_external_link_enabled']) ? 1 : 0,
            'include_external_info_link' => isset($_POST['include_external_info_link']) ? 1 : 0,

            'dataforseo_login' => sanitize_text_field((string)($_POST['dataforseo_login'] ?? '')),
            'dataforseo_password' => sanitize_text_field((string)($_POST['dataforseo_password'] ?? '')),
            // Optional for now – will be used when keyword tasks land.
            'dataforseo_location_code' => sanitize_text_field((string)($_POST['dataforseo_location_code'] ?? '2840')),

            'safe_mode' => isset($_POST['safe_mode']) ? 1 : 0,
            'debug_mode' => isset($_POST['debug_mode']) ? 1 : 0,
        ];
        update_option('tmwseo_engine_settings', $opts);
        Logs::info('settings', 'Settings saved', [
            'safe_mode' => $opts['safe_mode'],
            'openai_mode' => $opts['openai_mode'],
            'brand_voice' => $opts['brand_voice'],
            'openai_model_primary' => $opts['openai_model_primary'],
            'openai_model_bulk' => $opts['openai_model_bulk'],
        ]);

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-settings&updated=1'));
        exit;
    }

    

    // ---------- Manual actions (alpha.8) ----------

    public static function run_keyword_cycle_now(): void {
        if (!current_user_can('manage_options')) wp_die(__('Insufficient permissions', 'tmwseo'));
        check_admin_referer('tmwseo_run_keyword_cycle');

        Jobs::enqueue('keyword_cycle', 'system', 0, [
            'trigger' => 'manual',
        ]);

        Worker::run();

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-keywords&tmwseo_notice=keyword_cycle_ran'));
        exit;
    }

    public static function run_pagespeed_cycle_now(): void {
        if (!current_user_can('manage_options')) wp_die(__('Insufficient permissions', 'tmwseo'));
        check_admin_referer('tmwseo_run_pagespeed_cycle');

        Jobs::enqueue('pagespeed_cycle', 'system', 0, [
            'trigger' => 'manual',
        ]);

        Worker::run();

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-pagespeed&tmwseo_notice=pagespeed_cycle_ran'));
        exit;
    }

    public static function enable_indexing_now(): void {
        if (!current_user_can('manage_options')) wp_die(__('Insufficient permissions', 'tmwseo'));
        check_admin_referer('tmwseo_enable_indexing');

        if (Settings::is_human_approval_required() && !current_user_can('manage_options')) {
            wp_die(__('Human approval required.', 'tmwseo'));
        }

        $page_id = (int)($_GET['page_id'] ?? 0);
        if ($page_id <= 0) wp_die(__('Missing page_id', 'tmwseo'));

        // Remove Rank Math noindex override (default back to index).
        delete_post_meta($page_id, 'rank_math_robots');

        global $wpdb;
        $gen_table = $wpdb->prefix . 'tmw_generated_pages';
        $gen_updated = $wpdb->update($gen_table, [
            'indexing' => 'index',
            'last_generated_at' => current_time('mysql'),
        ], ['page_id' => $page_id], ['%s', '%s'], ['%d']);
        if ($gen_updated === false) {
            error_log('TMW indexing update failed: ' . $wpdb->last_error);
        }

        // Update indexing log (best-effort).
        $idx_table = $wpdb->prefix . 'tmw_indexing';
        $url = get_permalink($page_id);
        if ($url) {
            $idx_updated = $wpdb->update($idx_table, ['status' => 'manual_indexing_enabled'], ['url' => $url], ['%s'], ['%s']);
            if ($idx_updated === false) {
                error_log('TMW indexing update failed: ' . $wpdb->last_error);
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-generated&tmwseo_notice=indexing_enabled'));
        exit;
    }

    public static function ajax_generate_now(): void {
        $post_id = (int)($_POST['post_id'] ?? 0);
        if ($post_id <= 0) {
            wp_send_json_error(['message' => __('Invalid post.', 'tmwseo')], 400);
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'tmwseo')], 403);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash((string)$_POST['nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'tmwseo_generate_' . $post_id)) {
            wp_send_json_error(['message' => __('Invalid or expired nonce.', 'tmwseo')], 403);
        }

        $strategy = sanitize_key((string)($_POST['strategy'] ?? ''));
        if (!in_array($strategy, ['template', 'openai'], true)) {
            $strategy = 'openai';
        }

        $insert_block = !empty($_POST['insert_block']) ? 1 : 0;
        $refresh_keywords_only = !empty($_POST['refresh_keywords_only']) ? 1 : 0;

        Logs::info('admin', '[TMW-ADMIN] ajax_generate_now HIT', [
            'post_id' => $post_id,
            'strategy' => $strategy,
            'insert_block' => $insert_block,
            'refresh_keywords_only' => $refresh_keywords_only,
        ]);

        $post_type = get_post_type($post_id) ?: 'post';
        Jobs::enqueue('optimize_post', (string)$post_type, $post_id, [
            'trigger' => 'manual',
            'strategy' => $strategy,
            'insert_block' => $insert_block,
            'refresh_keywords_only' => $refresh_keywords_only,
        ]);

        Logs::info('admin', '[TMW-QUEUE] optimize_post queued from ajax_generate_now', [
            'post_id' => $post_id,
            'post_type' => (string)$post_type,
            'refresh_keywords_only' => $refresh_keywords_only,
        ]);

        wp_remote_post(admin_url('admin-ajax.php?action=tmwseo_kick_worker'), [
            'timeout' => 0.01,
            'blocking' => false,
            'cookies' => $_COOKIE,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);

        wp_send_json_success(['queued' => true]);
    }

    public static function ajax_kick_worker(): void {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied.', 'tmwseo')], 403);
        }

        \TMWSEO\Engine\Worker::run();
        wp_send_json_success(['ran' => true]);
    }


    public static function handle_refresh_keywords_now(): void {
        $post_id = (int)($_POST['post_id'] ?? 0);
        if ($post_id <= 0) {
            wp_die('Invalid post.');
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_die('Permission denied.');
        }

        check_admin_referer('tmwseo_refresh_keywords_' . $post_id);

        $post_type = get_post_type($post_id) ?: 'post';
        Jobs::enqueue('optimize_post', (string)$post_type, $post_id, [
            'trigger' => 'manual',
            'keywords_only' => 1,
        ]);

        Logs::info('admin', '[TMW-QUEUE] optimize_post queued from refresh_keywords_now', [
            'post_id' => $post_id,
            'post_type' => (string)$post_type,
            'keywords_only' => 1,
        ]);

        $ref = wp_get_referer();
        $redirect_url = $ref ? $ref : admin_url('post.php?post=' . $post_id . '&action=edit');
        $redirect_url = add_query_arg('tmwseo_notice', 'keywords_refresh_queued', $redirect_url);
        wp_safe_redirect($redirect_url);
        exit;
    }

    public static function handle_optimize_post_now(): void {
        if (!current_user_can('edit_posts')) wp_die('Permission denied.');

        // Support both GET links and POST form submits.
        $req = $_REQUEST;

        $post_id = (int)($req['post_id'] ?? 0);
        if ($post_id <= 0) wp_die('Invalid post.');

        $strategy = sanitize_key((string)($req['strategy'] ?? ''));
        if (!in_array($strategy, ['template', 'openai'], true)) {
            $strategy = 'openai';
        }

        $insert_block = !empty($req['insert_block']) ? 1 : 0;

        Logs::info('admin', 'optimize_post_now handler HIT', [
            'post_id' => $post_id,
            'strategy' => $strategy,
            'insert_block' => $insert_block,
        ]);

        $nonce = (string)($req['_wpnonce'] ?? '');
        if ($nonce === '' || !wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), 'tmwseo_optimize_post_' . $post_id)) {
            wp_die('Invalid or expired nonce.');
        }

        $post_type = get_post_type($post_id) ?: 'post';
        Jobs::enqueue('optimize_post', (string)$post_type, $post_id, [
            'trigger' => 'manual',
            'strategy' => $strategy,
            'insert_block' => $insert_block,
        ]);
        Logs::info('admin', '[TMW-QUEUE] optimize_post queued from manual action', ['post_id' => $post_id, 'post_type' => (string)$post_type]);

        $ref = wp_get_referer();
        $redirect_url = $ref ? $ref : admin_url('post.php?post=' . $post_id . '&action=edit');
        $redirect_url = add_query_arg('tmwseo_notice', 'optimize_queued', $redirect_url);
        wp_safe_redirect($redirect_url);

        // Kick the worker immediately after sending the redirect.
        // This avoids Cloudflare 504s while still processing the queued job right away.
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            @ob_end_flush();
            @flush();
        }

        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        // Process just one queued job (the one we just enqueued).
        \TMWSEO\Engine\Worker::run(1);

        exit;
    }

    public static function render_admin_notices(): void {
        if (class_exists('TMWSEO\Engine\Schema') && method_exists('TMWSEO\Engine\Schema', 'get_missing_required_intelligence_tables')) {
            $missing_tables = \TMWSEO\Engine\Schema::get_missing_required_intelligence_tables();
            if (!empty($missing_tables)) {
                echo '<div class="notice notice-warning"><p>';
                echo esc_html__('Schema mismatch detected: one or more required intelligence tables are missing.', 'tmwseo');
                echo '</p></div>';
            }
        }

        if (!isset($_GET['tmwseo_notice'])) {
            return;
        }

        $notice = sanitize_text_field(wp_unslash((string) $_GET['tmwseo_notice']));
        $message = '';
        if ($notice === 'optimize_queued') {
            $message = __('Optimization queued. The worker/cron will process this post in the background.', 'tmwseo');
        } elseif ($notice === 'keywords_refresh_queued') {
            $message = __('Keyword refresh queued. The worker/cron will update keyword pack and RankMath fields in the background.', 'tmwseo');
        } elseif ($notice === 'draft_preview_generated') {
            $message = __('Draft content preview generated in preview metadata only. No post content was changed and nothing was published automatically.', 'tmwseo');
        } elseif ($notice === 'draft_preview_refused') {
            $message = __('Draft content preview was refused. This action is allowed only for explicit draft posts.', 'tmwseo');
        } elseif ($notice === 'draft_preview_applied') {
            $message = __('Reviewed preview fields were manually applied to this draft only. Nothing was published, live content was not changed, and noindex was not cleared automatically.', 'tmwseo');
        } elseif ($notice === 'draft_preview_apply_refused') {
            $message = __('Manual apply from preview was refused. This action requires an explicit draft and at least one selected preview field.', 'tmwseo');
        } elseif ($notice === 'review_bundle_prepared') {
            $message = __('Prepared for human review. Nothing has been applied automatically. Draft remains draft-only/noindex and requires manual review + manual apply.', 'tmwseo');
        } elseif ($notice === 'review_bundle_refused') {
            $message = __('Prepare for Human Review was refused. This action is allowed only for explicit operator-created draft posts.', 'tmwseo');
        }

        if ($message === '') {
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>';
        echo esc_html($message);
        echo '</p></div>';
    }

    // ---------- UI (alpha.8) ----------


    /**
     * Legacy overview page — renders Command Center directly.
     */
    public static function render_overview(): void {
        \TMWSEO\Engine\Admin\CommandCenter::render();
    }

    public static function render_models_redirect(): void {
        wp_safe_redirect(admin_url('edit.php?post_type=model'));
        exit;
    }

    public static function render_tools(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        self::header(__('TMW SEO Engine — Tools', 'tmwseo'));

        echo '<p>' . esc_html__('Operator-triggered utilities. Every tool below requires explicit action — nothing runs automatically. No content is published.', 'tmwseo') . '</p>';
        echo '<p style="background:#fefce8;border-left:4px solid #eab308;padding:10px 14px;margin-bottom:24px;"><strong>' . esc_html__('Manual-Only Mode Active:', 'tmwseo') . '</strong> ' . esc_html__('All scans are read-only or create drafts for your review. Nothing is auto-published.', 'tmwseo') . '</p>';

        // ── Scan Tools ──────────────────────────────────────────────────────
        echo '<h2>' . esc_html__('Content Scans', 'tmwseo') . '</h2>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-bottom:32px;">';

        // Internal Link Opportunities
        echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;">';
        echo '<h3 style="margin-top:0;">' . esc_html__('Internal Link Opportunities', 'tmwseo') . '</h3>';
        echo '<p>' . esc_html__('Scans published content to find where internal links are missing or weak. Results are added to Suggestions for review.', 'tmwseo') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_scan_internal_link_opportunities');
        echo '<input type="hidden" name="action" value="tmwseo_scan_internal_link_opportunities">';
        submit_button(__('Scan Internal Link Opportunities', 'tmwseo'), 'secondary', 'submit', false);
        echo '</form></div>';

        // Content Improvement Scan
        echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;">';
        echo '<h3 style="margin-top:0;">' . esc_html__('Content Improvement Scan', 'tmwseo') . '</h3>';
        echo '<p>' . esc_html__('Analyzes existing content for SEO gaps: missing keywords, thin content, meta issues. Results go to Suggestions.', 'tmwseo') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_scan_content_improvements');
        echo '<input type="hidden" name="action" value="tmwseo_scan_content_improvements">';
        submit_button(__('Scan Content Improvements', 'tmwseo'), 'secondary', 'submit', false);
        echo '</form></div>';

        // Orphan Page Scan
        echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;">';
        echo '<h3 style="margin-top:0;">' . esc_html__('Orphan Page Scan', 'tmwseo') . '</h3>';
        echo '<p>' . esc_html__('Finds published pages with zero inbound internal links. Orphan pages are invisible to Googlebot unless linked from elsewhere.', 'tmwseo') . '</p>';
        $orphan_last = get_option(\TMWSEO\Engine\InternalLinks\OrphanPageDetector::OPTION_LAST_SCAN, '');
        if ($orphan_last) {
            echo '<p><small>' . esc_html__('Last scan:', 'tmwseo') . ' ' . esc_html((string) $orphan_last) . '</small></p>';
        }
        echo '<button type="button" class="button" id="tmwseo-orphan-scan-btn">' . esc_html__('Scan Orphan Pages', 'tmwseo') . '</button>';
        echo '<div id="tmwseo-orphan-scan-result" style="margin-top:8px;"></div>';
        echo '</div>';

        // Competitor Monitor
        echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;">';
        echo '<h3 style="margin-top:0;">' . esc_html__('Competitor Domain Scan', 'tmwseo') . '</h3>';
        echo '<p>' . esc_html__('Checks competitor domains for new content targeting keywords you rank for. Results are stored for review.', 'tmwseo') . '</p>';
        $comp_last = get_option(\TMWSEO\Engine\CompetitorMonitor\CompetitorMonitor::OPTION_LAST_RUN, '');
        if ($comp_last) {
            echo '<p><small>' . esc_html__('Last scan:', 'tmwseo') . ' ' . esc_html(date('Y-m-d H:i', (int) $comp_last)) . '</small></p>';
        }
        echo '<button type="button" class="button" id="tmwseo-competitor-scan-btn">' . esc_html__('Run Competitor Scan', 'tmwseo') . '</button>';
        echo '<div id="tmwseo-competitor-scan-result" style="margin-top:8px;"></div>';
        echo '</div>';

        echo '</div>';

        // ── Phase C Discovery Snapshot ───────────────────────────────────────
        echo '<h2>' . esc_html__('Discovery & Intelligence', 'tmwseo') . '</h2>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-bottom:32px;">';

        echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;">';
        echo '<h3 style="margin-top:0;">' . esc_html__('Discovery Snapshot (Phase C)', 'tmwseo') . '</h3>';
        echo '<p>' . esc_html__('Runs a safe read-only snapshot of the keyword + opportunity landscape. Does not publish anything. Results feed the Suggestions queue.', 'tmwseo') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_run_phase_c_discovery_snapshot');
        echo '<input type="hidden" name="action" value="tmwseo_run_phase_c_discovery_snapshot">';
        submit_button(__('Run Discovery Snapshot', 'tmwseo'), 'secondary', 'submit', false);
        echo '</form></div>';

        echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;">';
        echo '<h3 style="margin-top:0;">' . esc_html__('Keyword Cycle', 'tmwseo') . '</h3>';
        echo '<p>' . esc_html__('Refreshes keyword data from DataForSEO: discovery, KD scoring, clustering. Creates new candidate clusters for review.', 'tmwseo') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_run_keyword_cycle');
        echo '<input type="hidden" name="action" value="tmwseo_run_keyword_cycle">';
        submit_button(__('Run Keyword Cycle', 'tmwseo'), 'secondary', 'submit', false);
        echo '</form></div>';

        echo '</div>';

        // ── Maintenance ──────────────────────────────────────────────────────
        echo '<h2>' . esc_html__('Maintenance', 'tmwseo') . '</h2>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-bottom:32px;">';

        echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;">';
        echo '<h3 style="margin-top:0;">' . esc_html__('Auto Fix Missing SEO', 'tmwseo') . '</h3>';
        echo '<p>' . esc_html__('Fills in missing focus keywords and meta descriptions using post titles. Safe batch fix — does not touch content or publish anything.', 'tmwseo') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_bulk_autofix');
        echo '<input type="hidden" name="action" value="tmwseo_bulk_autofix">';
        submit_button(__('Auto Fix Missing SEO', 'tmwseo'), 'secondary', 'submit', false);
        echo '</form></div>';

        echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;">';
        echo '<h3 style="margin-top:0;">' . esc_html__('System Worker', 'tmwseo') . '</h3>';
        echo '<p>' . esc_html__('Runs a healthcheck job and processes any queued tasks. Use this to unstick the queue if the background worker missed a job.', 'tmwseo') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_run_worker');
        echo '<input type="hidden" name="action" value="tmwseo_run_worker">';
        submit_button(__('Run Worker Now', 'tmwseo'), 'secondary', 'submit', false);
        echo '</form></div>';

        echo '</div>';

        // ── Advanced Links ───────────────────────────────────────────────────
        echo '<h2>' . esc_html__('Advanced', 'tmwseo') . '</h2>';
        echo '<ul style="line-height:2;">';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=tmwseo-logs')) . '">' . esc_html__('View Logs', 'tmwseo') . '</a></li>';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=tmw-engine-monitor')) . '">' . esc_html__('Engine Monitor', 'tmwseo') . '</a></li>';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=tmwseo-model-optimizer')) . '">' . esc_html__('Model Optimizer', 'tmwseo') . '</a></li>';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=tmwseo-intelligence')) . '">' . esc_html__('Legacy Keyword Research', 'tmwseo') . '</a></li>';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=tmwseo-staging-validation-helper')) . '">' . esc_html__('Staging Validation Helper', 'tmwseo') . '</a></li>';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=tmwseo-import')) . '">' . esc_html__('Import Keywords (CSV)', 'tmwseo') . '</a></li>';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=tmwseo-migration')) . '">' . esc_html__('Migration Info', 'tmwseo') . '</a></li>';
        echo '</ul>';

        // AJAX for orphan + competitor scan buttons
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

        self::footer();
    }

    public static function render_keywords(): void {
        self::header(__('TMW SEO Engine — Keywords', 'tmwseo'));

        global $wpdb;
        $raw_table = $wpdb->prefix . 'tmw_keyword_raw';
        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';

        $raw_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$raw_table}");
        $cand_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$cand_table}");
        $approved_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$cand_table} WHERE status='approved'");
        $cluster_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$cluster_table}");
        $new_clusters = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$cluster_table} WHERE status='new'");

        echo '<p>This workflow builds keyword suggestions and clusters for review. It does not auto-create or auto-publish pages.</p>';

        echo '<div style="display:flex; gap:12px; flex-wrap:wrap; margin:12px 0;">';
        echo '<div class="card" style="padding:12px; min-width:180px;"><strong>Raw keywords</strong><br>' . esc_html($raw_count) . '</div>';
        echo '<div class="card" style="padding:12px; min-width:180px;"><strong>Candidates</strong><br>' . esc_html($cand_count) . '</div>';
        echo '<div class="card" style="padding:12px; min-width:180px;"><strong>Approved</strong><br>' . esc_html($approved_count) . '</div>';
        echo '<div class="card" style="padding:12px; min-width:180px;"><strong>Clusters</strong><br>' . esc_html($cluster_count) . ' (new: ' . esc_html($new_clusters) . ')</div>';
        echo '</div>';

        echo '<p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block; margin-right:8px;">';
        wp_nonce_field('tmwseo_run_keyword_cycle');
        echo '<input type="hidden" name="action" value="tmwseo_run_keyword_cycle">';
        submit_button('Refresh Suggestions', 'primary', 'submit', false);
        echo '</form>';

        echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=tmwseo_run_worker'), 'tmwseo_run_worker')) . '">Run Worker (healthcheck)</a>';
        echo '</p>';

        // Top clusters preview
        $clusters = $wpdb->get_results(
            "SELECT id, cluster_key, representative, total_volume, avg_difficulty, opportunity, status, page_id
             FROM {$cluster_table}
             ORDER BY opportunity DESC, total_volume DESC
             LIMIT 20",
            ARRAY_A
        );

        echo '<h2>Top Clusters</h2>';
        if (empty($clusters)) {
            echo '<p>No clusters yet. Run the keyword cycle.</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Opportunity</th><th>Volume</th><th>Avg KD</th><th>Representative Keyword</th><th>Status</th><th>Page</th></tr></thead><tbody>';
            foreach ($clusters as $c) {
                $page = (int)($c['page_id'] ?? 0);
                $page_link = $page ? '<a href="' . esc_url(get_edit_post_link($page)) . '">Edit</a>' : '—';
                echo '<tr>';
                echo '<td>' . esc_html($c['opportunity']) . '</td>';
                echo '<td>' . esc_html($c['total_volume']) . '</td>';
                echo '<td>' . esc_html($c['avg_difficulty']) . '</td>';
                echo '<td>' . esc_html($c['representative']) . '</td>';
                echo '<td>' . esc_html($c['status']) . '</td>';
                echo '<td>' . $page_link . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
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

        echo '<p>Import keywords from <strong>Google Keyword Planner</strong> or <strong>SEMrush</strong> (CSV). Imported keywords go through the adult relevancy filter and then can be KD-scored via DataForSEO.</p>';

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

        echo '<p><label><input type="checkbox" name="run_kd" value="1" checked> After import: run KD + clustering + auto page creation</label></p>';

        submit_button('Import Keywords', 'primary');

        echo '</form>';

        echo '</div>';
    }

    public static function import_keywords(): void {
        if (!current_user_can('manage_options')) wp_die(__('Insufficient permissions', 'tmwseo'));
        check_admin_referer('tmwseo_import_keywords');

        if (empty($_FILES['keywords_csv']) || !isset($_FILES['keywords_csv']['tmp_name'])) {
            wp_die(__('No file uploaded', 'tmwseo'));
        }

        $file = $_FILES['keywords_csv'];
        if (!empty($file['error'])) {
            wp_die(__('Upload error', 'tmwseo'));
        }

        $tmp = (string)$file['tmp_name'];
        $source = sanitize_text_field((string)($_POST['import_source'] ?? 'manual'));
        $run_kd = !empty($_POST['run_kd']);

        $fh = fopen($tmp, 'r');
        if (!$fh) wp_die(__('Could not read CSV', 'tmwseo'));

        $header = fgetcsv($fh);
        if (!is_array($header)) $header = [];

        $kw_col = 0;
        $vol_col = null;

        foreach ($header as $i => $col) {
            $c = strtolower(trim((string)$col));
            if ($c === '') continue;
            if (strpos($c, 'keyword') !== false) $kw_col = (int)$i;
            if (strpos($c, 'volume') !== false) $vol_col = (int)$i;
            if ($c === 'avg. monthly searches') $vol_col = (int)$i;
            if ($c === 'search volume') $vol_col = (int)$i;
        }

        global $wpdb;
        $raw_table = $wpdb->prefix . 'tmw_keyword_raw';
        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';

        $raw_ins = 0;
        $cand_ins = 0;
        $rejected = 0;

        while (($row = fgetcsv($fh)) !== false) {
            if (!is_array($row)) continue;

            $kw = isset($row[$kw_col]) ? trim((string)$row[$kw_col]) : '';
            if ($kw === '') continue;

            $reason = null;
            if (!\TMWSEO\Engine\Keywords\KeywordValidator::is_relevant($kw, $reason)) {
                $rejected++;
                continue;
            }

            $vol = null;
            if ($vol_col !== null && isset($row[$vol_col])) {
                $v = preg_replace('/[^0-9]/', '', (string)$row[$vol_col]);
                if ($v !== '') $vol = (int)$v;
            }

            // Raw
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$raw_table} (keyword, source, source_ref, volume, cpc, competition, raw, discovered_at)
                 VALUES (%s, %s, %s, %d, %f, %f, %s, %s)",
                $kw, 'import', $source, (int)($vol ?? 0), 0.0, 0.0, null, current_time('mysql')
            ));
            $raw_ins++;

            // Candidate upsert
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$cand_table} WHERE keyword=%s LIMIT 1", $kw));
            if ($exists) continue;

            $canonical = \TMWSEO\Engine\Keywords\KeywordValidator::normalize($kw);
            $intent = \TMWSEO\Engine\Keywords\KeywordValidator::infer_intent($kw);

            $cand_inserted = $wpdb->insert($cand_table, [
                'keyword' => $kw,
                'canonical' => $canonical,
                'status' => 'new',
                'intent' => $intent,
                'volume' => $vol,
                'cpc' => null,
                'difficulty' => null,
                'opportunity' => null,
                'sources' => 'import:' . $source,
                'notes' => null,
                'updated_at' => current_time('mysql'),
            ], ['%s', '%s', '%s', '%s', '%d', '%f', '%f', '%f', '%s', '%s', '%s']);
            if ($cand_inserted === false) {
                error_log('TMW CSV insert failed: ' . $wpdb->last_error);
            }
            $cand_ins++;
        }

        fclose($fh);

        Logs::info('import', 'Imported keywords', ['raw' => $raw_ins, 'candidates' => $cand_ins, 'rejected' => $rejected, 'source' => $source]);

        if ($run_kd) {
            Jobs::enqueue('keyword_cycle', 'system', 0, [
                'trigger' => 'import',
                'mode' => 'import_only',
            ]);
            Worker::run();
        }

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-keywords&tmwseo_notice=imported&raw=' . $raw_ins . '&cand=' . $cand_ins . '&rej=' . $rejected));
        exit;
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
            'post_status' => 'any',
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
        $gsc_client_id          = esc_attr((string)($opts['gsc_client_id'] ?? ''));
        $gsc_client_secret      = esc_attr((string)($opts['gsc_client_secret'] ?? ''));
        $gsc_site_url           = esc_attr((string)($opts['gsc_site_url'] ?? ''));
        $indexing_json          = esc_textarea((string)($opts['google_indexing_service_account_json'] ?? ''));
        $indexing_post_types    = esc_attr((string)($opts['indexing_api_post_types'] ?? 'model,video,tmw_video'));
        $schema_enabled         = (bool)($opts['schema_enabled'] ?? 1);
        $schema_post_types      = esc_attr((string)($opts['schema_post_types'] ?? 'model,video,tmw_video'));
        $orphan_scan_enabled    = (bool)($opts['orphan_scan_enabled'] ?? 1);
        $safe_mode              = !empty($opts['safe_mode']);
        $dry_run_mode           = !empty($opts['tmwseo_dry_run_mode']);
        $auto_clear_noindex     = !empty($opts['auto_clear_noindex']);
        $debug_mode             = (bool)($opts['debug_mode'] ?? 0);
        $serper_api_key         = esc_attr((string)($opts['serper_api_key'] ?? ''));
        $intel_max_seeds        = esc_attr((string)($opts['intel_max_seeds'] ?? 3));
        $intel_max_keywords     = esc_attr((string)($opts['intel_max_keywords'] ?? 400));

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

        // ── Keyword Engine ────────────────────────────────────────────────
        echo '<h2>' . esc_html__('Keyword Engine', 'tmwseo') . '</h2>';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Competitor domains', 'tmwseo') . '</th><td><textarea name="tmwseo_engine_settings[competitor_domains]" rows="6" class="large-text code">' . esc_textarea((string)($opts['competitor_domains'] ?? '')) . '</textarea><p class="description">' . esc_html__('One domain per line. No https://. Used for competitor seeding.', 'tmwseo') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('Min search volume', 'tmwseo') . '</th><td><input type="number" name="tmwseo_engine_settings[keyword_min_volume]" value="' . esc_attr((string)($opts['keyword_min_volume'] ?? 30)) . '" class="small-text"></td></tr>';
        echo '<tr><th>' . esc_html__('Max KD', 'tmwseo') . '</th><td><input type="number" name="tmwseo_engine_settings[keyword_max_kd]" value="' . esc_attr((string)($opts['keyword_max_kd'] ?? 60)) . '" class="small-text"></td></tr>';
        echo '<tr><th>' . esc_html__('New keywords per run', 'tmwseo') . '</th><td><input type="number" name="tmwseo_engine_settings[keyword_new_limit]" value="' . esc_attr((string)($opts['keyword_new_limit'] ?? 300)) . '" class="small-text"></td></tr>';
        echo '<tr><th>' . esc_html__('KD batch size', 'tmwseo') . '</th><td><input type="number" name="tmwseo_engine_settings[keyword_kd_batch_limit]" value="' . esc_attr((string)($opts['keyword_kd_batch_limit'] ?? 300)) . '" class="small-text"></td></tr>';
        echo '</table>';

        // ── Intelligence ──────────────────────────────────────────────────
        echo '<h2>' . esc_html__('Intelligence', 'tmwseo') . '</h2>';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Serper API key', 'tmwseo') . '</th><td><input type="password" name="tmwseo_engine_settings[serper_api_key]" value="' . $serper_api_key . '" class="regular-text" autocomplete="off"><p class="description">' . esc_html__('Optional. Enables People Also Ask keyword expansion.', 'tmwseo') . '</p></td></tr>';
        echo '<tr><th>' . esc_html__('Max seeds per run', 'tmwseo') . '</th><td><input type="number" name="tmwseo_engine_settings[intel_max_seeds]" value="' . $intel_max_seeds . '" class="small-text" min="1" max="10"></td></tr>';
        echo '<tr><th>' . esc_html__('Max keywords per run', 'tmwseo') . '</th><td><input type="number" name="tmwseo_engine_settings[intel_max_keywords]" value="' . $intel_max_keywords . '" class="small-text" min="50" max="2000"></td></tr>';
        echo '</table>';

        // ── Debug ─────────────────────────────────────────────────────────
        echo '<h2>' . esc_html__('Debug', 'tmwseo') . '</h2>';
        echo '<table class="form-table"><tr><th>' . esc_html__('Debug mode', 'tmwseo') . '</th><td>';
        echo '<label><input type="checkbox" name="tmwseo_engine_settings[debug_mode]" value="1" ' . checked($debug_mode, true, false) . '> ' . esc_html__('Enable debug logging', 'tmwseo') . '</label>';
        echo '</td></tr></table>';

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

        $opts = get_option('tmwseo_engine_settings', []);
        $opts = is_array($opts) ? $opts : [];
        $affiliate = is_array($opts['affiliate'] ?? null) ? $opts['affiliate'] : [];
        $platform_settings = is_array($affiliate['platforms'] ?? null) ? $affiliate['platforms'] : [];
        $platforms = self::get_affiliate_platform_defaults();

        echo '<form method="post" action="options.php">';
        settings_fields('tmwseo_settings_group');
        echo '<input type="hidden" name="tmwseo_engine_settings[tmwseo_settings_section]" value="affiliate">';

        foreach ($platforms as $platform_key => $platform) {
            $current = is_array($platform_settings[$platform_key] ?? null) ? $platform_settings[$platform_key] : [];
            $pattern = (string)($current['affiliate_link_pattern'] ?? $platform['affiliate_link_pattern']);
            $campaign = (string)($current['campaign'] ?? '');
            $source = (string)($current['source'] ?? '');
            $username = 'demo' . $platform_key;
            $profile_url = rtrim((string)$platform['base_profile_url'], '/') . '/' . rawurlencode($username);

            $preview = str_replace(
                ['{campaign}', '{source}', '{encoded_profile_url}', '{profile_url}'],
                [rawurlencode($campaign), rawurlencode($source), rawurlencode($profile_url), $profile_url],
                $pattern
            );

            echo '<h2>' . esc_html($platform['label']) . '</h2>';
            echo '<table class="form-table">';

            echo '<tr><th>Enabled</th><td><label><input type="checkbox" name="tmwseo_engine_settings[affiliate][platforms][' . esc_attr($platform_key) . '][enabled]" value="1" ' . checked(!empty($current['enabled']), true, false) . '> Enable affiliate links for ' . esc_html($platform['label']) . '</label></td></tr>';

            echo '<tr><th>Affiliate link pattern</th><td><input type="text" name="tmwseo_engine_settings[affiliate][platforms][' . esc_attr($platform_key) . '][affiliate_link_pattern]" value="' . esc_attr($pattern) . '" class="large-text code">';
            echo '<p class="description">Supported placeholders: <code>{campaign}</code>, <code>{source}</code>, <code>{encoded_profile_url}</code>, <code>{profile_url}</code>.</p></td></tr>';

            echo '<tr><th>Campaign</th><td><input type="text" name="tmwseo_engine_settings[affiliate][platforms][' . esc_attr($platform_key) . '][campaign]" value="' . esc_attr($campaign) . '" class="regular-text"></td></tr>';
            echo '<tr><th>Source</th><td><input type="text" name="tmwseo_engine_settings[affiliate][platforms][' . esc_attr($platform_key) . '][source]" value="' . esc_attr($source) . '" class="regular-text"></td></tr>';

            echo '<tr><th>Preview example link</th><td><code>' . esc_html($preview) . '</code>';
            echo '<p class="description">Preview uses dummy username <code>' . esc_html($username) . '</code>.</p></td></tr>';

            echo '</table>';
        }

        submit_button(__('Save affiliate settings', 'tmwseo'));
        echo '</form>';

        self::footer();
    }
}
