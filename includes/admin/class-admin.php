<?php
namespace TMWSEO\Engine;

if (!defined('ABSPATH')) { exit; }

class Admin {

    const MENU_SLUG = 'tmwseo-engine';

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
        add_action('admin_post_tmwseo_run_worker', [__CLASS__, 'run_worker_now']);
        add_action('admin_post_tmwseo_save_settings', [__CLASS__, 'save_settings']);
        add_action('admin_post_tmwseo_run_keyword_cycle', [__CLASS__, 'run_keyword_cycle_now']);
        add_action('admin_post_tmwseo_run_pagespeed_cycle', [__CLASS__, 'run_pagespeed_cycle_now']);
        add_action('admin_post_tmwseo_enable_indexing', [__CLASS__, 'enable_indexing_now']);
        add_action('admin_post_tmwseo_optimize_post_now', [__CLASS__, 'handle_optimize_post_now']);
        add_action('admin_post_tmwseo_optimize_post_now', function() {
            error_log('TRACE: admin_post_tmwseo_optimize_post_now reached');
            debug_print_backtrace();
        });
        add_action('admin_post_tmwseo_import_keywords', [__CLASS__, 'import_keywords']);
        add_action('tmw_manual_cycle_event', ['\TMWSEO\Engine\Keywords\KeywordEngine', 'run_cycle_job'], 10, 1);
    }

    public static function enqueue_admin_assets(string $hook): void {
        if ($hook !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }

        wp_register_style('tmwseo-admin-overview', false);
        wp_enqueue_style('tmwseo-admin-overview');
        wp_add_inline_style('tmwseo-admin-overview', '
            .tmwseo-dashboard {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 20px;
            }

            .tmwseo-card {
                background: #fff;
                padding: 20px;
                border-radius: 6px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.08);
                text-align: center;
            }

            .tmwseo-card h3 {
                font-size: 28px;
                margin: 0;
            }

            .tmwseo-card span {
                font-size: 14px;
                color: #666;
            }

            .tmwseo-quick-actions {
                margin-top: 24px;
            }

            .tmwseo-quick-actions .button {
                margin-right: 8px;
                margin-bottom: 8px;
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

        $mode = sanitize_text_field((string)($input['openai_mode'] ?? 'hybrid'));
        if (!in_array($mode, ['quality', 'bulk', 'hybrid'], true)) {
            $mode = 'hybrid';
        }

        $primary = sanitize_text_field((string)($input['openai_model_primary'] ?? 'gpt-4o'));
        $bulk = sanitize_text_field((string)($input['openai_model_bulk'] ?? 'gpt-4o-mini'));

        $voice = sanitize_text_field((string)($input['brand_voice'] ?? 'premium'));
        if (!in_array($voice, ['premium', 'neutral'], true)) {
            $voice = 'premium';
        }

        return [
            'openai_api_key' => sanitize_text_field((string)($input['openai_api_key'] ?? '')),
            'openai_mode' => $mode,
            'openai_model_primary' => $primary,
            'openai_model_bulk' => $bulk,
            'openai_model' => ($mode === 'bulk') ? $bulk : $primary,
            'brand_voice' => $voice,
            'tmwseo_dry_run_mode' => !empty($input['tmwseo_dry_run_mode']) ? 1 : 0,
            'dataforseo_login' => sanitize_text_field((string)($input['dataforseo_login'] ?? '')),
            'dataforseo_password' => sanitize_text_field((string)($input['dataforseo_password'] ?? '')),
            'dataforseo_location_code' => sanitize_text_field((string)($input['dataforseo_location_code'] ?? '2840')),
            'dataforseo_language_code' => sanitize_text_field((string)($input['dataforseo_language_code'] ?? 'en')),
            'safe_mode' => !empty($input['safe_mode']) ? 1 : 0,
            'competitor_domains' => sanitize_textarea_field((string)($input['competitor_domains'] ?? '')),
            'keyword_min_volume' => max(0, (int)($input['keyword_min_volume'] ?? 30)),
            'keyword_max_kd' => max(0, (int)($input['keyword_max_kd'] ?? 60)),
            'keyword_new_limit' => max(0, (int)($input['keyword_new_limit'] ?? 300)),
            'keyword_kd_batch_limit' => max(0, (int)($input['keyword_kd_batch_limit'] ?? 300)),
            'keyword_pages_per_day' => max(0, (int)($input['keyword_pages_per_day'] ?? 3)),
            'google_pagespeed_api_key' => sanitize_text_field((string)($input['google_pagespeed_api_key'] ?? '')),
        ];
    }

    public static function menu(): void {
        add_menu_page(
            __('TMW SEO Engine', 'tmwseo'),
            __('TMW SEO Engine', 'tmwseo'),
            'manage_options',
            self::MENU_SLUG,
            [__CLASS__, 'render_overview'],
            'dashicons-chart-area',
            58
        );

        add_submenu_page(self::MENU_SLUG, __('Overview', 'tmwseo'), __('Overview', 'tmwseo'), 'manage_options', self::MENU_SLUG, [__CLASS__, 'render_overview']);
        add_submenu_page(self::MENU_SLUG, __('Queue', 'tmwseo'), __('Queue', 'tmwseo'), 'manage_options', 'tmwseo-queue', [__CLASS__, 'render_queue']);
        add_submenu_page(self::MENU_SLUG, __('Keywords', 'tmwseo'), __('Keywords', 'tmwseo'), 'manage_options', 'tmwseo-keywords', [__CLASS__, 'render_keywords']);
        add_submenu_page(self::MENU_SLUG, __('Import', 'tmwseo'), __('Import', 'tmwseo'), 'manage_options', 'tmwseo-import', [__CLASS__, 'render_import']);
        add_submenu_page(self::MENU_SLUG, __('Generated Pages', 'tmwseo'), __('Generated Pages', 'tmwseo'), 'manage_options', 'tmwseo-generated', [__CLASS__, 'render_generated_pages']);
        add_submenu_page(self::MENU_SLUG, __('Indexing', 'tmwseo'), __('Indexing', 'tmwseo'), 'manage_options', 'tmwseo-indexing', [__CLASS__, 'render_indexing']);
        add_submenu_page(self::MENU_SLUG, __('PageSpeed', 'tmwseo'), __('PageSpeed', 'tmwseo'), 'manage_options', 'tmwseo-pagespeed', [__CLASS__, 'render_pagespeed']);
        add_submenu_page(self::MENU_SLUG, __('Logs', 'tmwseo'), __('Logs', 'tmwseo'), 'manage_options', 'tmwseo-logs', [__CLASS__, 'render_logs']);
        add_submenu_page(self::MENU_SLUG, __('Settings', 'tmwseo'), __('Settings', 'tmwseo'), 'manage_options', 'tmwseo-settings', [__CLASS__, 'render_settings']);
        add_submenu_page(self::MENU_SLUG, __('Migration', 'tmwseo'), __('Migration', 'tmwseo'), 'manage_options', 'tmwseo-migration', [__CLASS__, 'render_migration']);
        add_submenu_page('tmw-seo', __('Engine Monitor', 'tmwseo'), __('Engine Monitor', 'tmwseo'), 'manage_options', 'tmw-engine-monitor', [__CLASS__, 'render_engine_monitor']);
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

            'dataforseo_login' => sanitize_text_field((string)($_POST['dataforseo_login'] ?? '')),
            'dataforseo_password' => sanitize_text_field((string)($_POST['dataforseo_password'] ?? '')),
            // Optional for now – will be used when keyword tasks land.
            'dataforseo_location_code' => sanitize_text_field((string)($_POST['dataforseo_location_code'] ?? '2840')),

            'safe_mode' => isset($_POST['safe_mode']) ? 1 : 0,
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

    public static function handle_optimize_post_now(): void {
        error_log('TMW ADMIN OPTIMIZE HANDLER ENTERED');

        if (!current_user_can('edit_posts')) wp_die('Permission denied.');

        $post_id = (int)($_GET['post_id'] ?? 0);
        if ($post_id <= 0) wp_die('Invalid post.');

        if (
            !isset($_GET['_wpnonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash((string)$_GET['_wpnonce'])), 'tmwseo_optimize_post_' . $post_id)
        ) {
            wp_die('Invalid or expired nonce.');
        }

        $post_type = get_post_type($post_id) ?: 'post';
        error_log('TMW DISPATCHING JOB');
        Jobs::enqueue('optimize_post', (string)$post_type, $post_id, [
            'context' => 'manual',
            'trigger' => 'manual',
        ]);

        Worker::run();

        $ref = wp_get_referer();
        error_log('TMW ADMIN OPTIMIZE BEFORE REDIRECT');
        wp_safe_redirect($ref ? $ref : admin_url('post.php?post=' . $post_id . '&action=edit'));
        exit;
    }

    // ---------- UI (alpha.8) ----------

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

        echo '<p>This is the Keyword Intelligence layer (DataForSEO + adult relevancy filter + clustering + auto page creation).</p>';

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
        submit_button('Run Keyword Cycle Now', 'primary', 'submit', false);
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
        self::header(__('TMW SEO Engine — Generated Pages', 'tmwseo'));

        global $wpdb;
        $gen_table = $wpdb->prefix . 'tmw_generated_pages';

        $rows = $wpdb->get_results(
            "SELECT g.page_id, g.keyword, g.kind, g.indexing, g.last_generated_at, p.post_status, p.post_title
             FROM {$gen_table} g
             LEFT JOIN {$wpdb->posts} p ON p.ID = g.page_id
             ORDER BY g.last_generated_at DESC
             LIMIT 50",
            ARRAY_A
        );

        // Critical visibility note.
        if ((int)get_option('blog_public') === 0) {
            echo '<div class="notice notice-warning"><p><strong>Search engines are currently discouraged (Settings → Reading).</strong> If you want to rank, you must eventually enable indexing (blog_public = 1).</p></div>';
        }

        echo '<p>Generated pages are created as <strong>draft + RankMath noindex</strong> by default. You can publish them and enable indexing when you are ready.</p>';

        if (empty($rows)) {
            echo '<p>No generated pages yet. Go to <a href="' . esc_url(admin_url('admin.php?page=tmwseo-keywords')) . '">Keywords</a> and run a cycle.</p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Page</th><th>Keyword</th><th>Status</th><th>Indexing</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $page_id = (int)$r['page_id'];
            $title = $r['post_title'] ?: ('Page #' . $page_id);
            $status = $r['post_status'] ?: '—';
            $indexing = (string)($r['indexing'] ?? 'noindex');
            $view = get_permalink($page_id);

            $actions = [];
            $actions[] = '<a href="' . esc_url(get_edit_post_link($page_id)) . '">Edit</a>';
            if ($view) $actions[] = '<a href="' . esc_url($view) . '" target="_blank" rel="noopener">View</a>';

            $actions[] = '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=tmwseo_optimize_post_now&post_id=' . $page_id), 'tmwseo_optimize_post_' . $page_id)) . '">Generate/Refresh AI</a>';

            if ($indexing !== 'index') {
                $actions[] = '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=tmwseo_enable_indexing&page_id=' . $page_id), 'tmwseo_enable_indexing')) . '">Enable Indexing</a>';
            }

            echo '<tr>';
            echo '<td>' . esc_html($title) . '</td>';
            echo '<td>' . esc_html((string)($r['keyword'] ?? '')) . '</td>';
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

    public static function render_overview(): void {
        self::header(__('TMW SEO Engine — Overview', 'tmwseo'));

        $tracked_post_types = ['post', 'page', 'model', 'blog', 'photos', 'tmw_category_page'];

        $total_posts = self::count_posts_with_query([
            'post_type' => $tracked_post_types,
            'post_status' => 'any',
        ]);

        $optimized_posts = self::count_posts_with_query([
            'post_type' => $tracked_post_types,
            'post_status' => 'any',
            'meta_query' => [
                [
                    'key' => '_tmwseo_optimize_done',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        $pending_optimization = self::count_posts_with_query([
            'post_type' => $tracked_post_types,
            'post_status' => 'any',
            'meta_query' => [
                [
                    'key' => '_tmwseo_optimize_enqueued',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        $missing_focus_keyword = self::count_posts_with_query([
            'post_type' => $tracked_post_types,
            'post_status' => 'any',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_tmwseo_keyword',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => '_tmwseo_keyword',
                    'value' => '',
                    'compare' => '=',
                ],
            ],
        ]);

        $missing_meta_description = self::count_posts_with_query([
            'post_type' => $tracked_post_types,
            'post_status' => 'any',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_yoast_wpseo_metadesc',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => '_yoast_wpseo_metadesc',
                    'value' => '',
                    'compare' => '=',
                ],
            ],
        ]);

        $seven_days_ago = gmdate('Y-m-d H:i:s', time() - (7 * DAY_IN_SECONDS));
        $last_7_days_optimized = self::count_posts_with_query([
            'post_type' => $tracked_post_types,
            'post_status' => 'any',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_tmwseo_optimize_done_date',
                    'value' => $seven_days_ago,
                    'compare' => '>=',
                    'type' => 'DATETIME',
                ],
                [
                    'relation' => 'AND',
                    [
                        'key' => '_tmwseo_optimize_done_date',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key' => '_tmwseo_optimize_done',
                        'value' => 'offline_dry',
                        'compare' => '=',
                    ],
                ],
            ],
        ]);

        echo '<div class="tmwseo-dashboard">';
        self::render_stat_card($total_posts, __('Total Posts', 'tmwseo'));
        self::render_stat_card($optimized_posts, __('Optimized Posts', 'tmwseo'));
        self::render_stat_card($pending_optimization, __('Pending Optimization', 'tmwseo'));
        self::render_stat_card($missing_focus_keyword, __('Missing Focus Keyword', 'tmwseo'));
        self::render_stat_card($missing_meta_description, __('Missing Meta Description', 'tmwseo'));
        self::render_stat_card($last_7_days_optimized, __('Last 7 Days Optimized', 'tmwseo'));
        echo '</div>';

        echo '<div class="tmwseo-quick-actions">';
        echo '<h2>' . esc_html__('Quick Actions', 'tmwseo') . '</h2>';
        echo '<p>';
        echo '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tmwseo_action=bulk_optimize_all')) . '">' . esc_html__('Bulk Optimize All', 'tmwseo') . '</a> ';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '&tmwseo_action=optimize_missing_seo')) . '">' . esc_html__('Optimize Only Missing SEO', 'tmwseo') . '</a> ';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=tmwseo-keywords&tmwseo_action=generate_clusters')) . '">' . esc_html__('Generate Clusters', 'tmwseo') . '</a> ';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=tmwseo-indexing&tmwseo_action=rebuild_indexing')) . '">' . esc_html__('Rebuild Indexing', 'tmwseo') . '</a>';
        echo '</p>';
        echo '</div>';

        $counts = Jobs::counts();
        echo '<p><strong>alpha.7.1 baseline</strong>: DB tables + queue + worker + logs + settings shell (+ model routing + voice preset). No AI calls yet.</p>';

        echo '<h2>Queue status</h2><ul>';
        foreach ($counts as $k => $v) echo '<li><strong>' . esc_html(ucfirst($k)) . ':</strong> ' . esc_html((string)$v) . '</li>';
        echo '</ul>';

        echo '<h2>Detected content mapping</h2><ul>';
        echo '<li><strong>Models:</strong> post_type <code>model</code></li>';
        echo '<li><strong>Videos:</strong> post_type <code>post</code></li>';
        echo '<li><strong>Video categories:</strong> taxonomy <code>category</code></li>';
        echo '<li><strong>Category Pages:</strong> post_type <code>tmw_category_page</code> (matched by slug to category term)</li>';
        echo '<li><strong>Model tags:</strong> taxonomy <code>models</code></li>';
        echo '</ul>';

        echo '<h2>Actions</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_run_worker');
        echo '<input type="hidden" name="action" value="tmwseo_run_worker" />';
        echo '<p><button class="button button-primary">Run Worker Now</button></p>';
        echo '</form>';

        self::footer();
    }

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
        $opts = get_option('tmwseo_engine_settings', []);
        if (!is_array($opts)) $opts = [];

        $openai_api_key = esc_attr((string)($opts['openai_api_key'] ?? ''));
        $openai_mode = esc_attr((string)($opts['openai_mode'] ?? 'hybrid'));
        // New fields (alpha.6) with sensible fallbacks.
        $openai_model_primary = esc_attr((string)($opts['openai_model_primary'] ?? ($opts['openai_model'] ?? 'gpt-4o')));
        $openai_model_bulk = esc_attr((string)($opts['openai_model_bulk'] ?? 'gpt-4o-mini'));
        // Legacy (kept for compatibility).
        $openai_model = esc_attr((string)($opts['openai_model'] ?? $openai_model_primary));

        $brand_voice = esc_attr((string)($opts['brand_voice'] ?? 'premium'));

        $d_login = esc_attr((string)($opts['dataforseo_login'] ?? ''));
        $d_pass = esc_attr((string)($opts['dataforseo_password'] ?? ''));
        $d_loc = esc_attr((string)($opts['dataforseo_location_code'] ?? '2840'));
        $safe_mode = !empty($opts['safe_mode']);
        $dry_run_mode = !empty($opts['tmwseo_dry_run_mode']);

        echo '<form method="post" action="options.php">';
        settings_fields('tmwseo_settings_group');
        do_settings_sections('tmwseo_settings');

        echo '<h2>Safe Mode</h2>';
        echo '<label><input type="checkbox" name="tmwseo_engine_settings[safe_mode]" value="1" ' . checked($safe_mode, true, false) . '> Keep safe mode enabled (no auto-publish / no indexing submissions)</label>';

        echo '<h2>OpenAI</h2>';
        echo '<table class="form-table">';
        echo '<tr><th>API Key</th><td><input type="password" name="tmwseo_engine_settings[openai_api_key]" value="' . $openai_api_key . '" class="regular-text" autocomplete="off">';
        echo '<p class="description">Store your OpenAI API key here.</p></td></tr>';

        echo '<tr><th>Mode</th><td>';
        echo '<select name="tmwseo_engine_settings[openai_mode]">';
        echo '<option value="hybrid"' . selected($openai_mode, 'hybrid', false) . '>Hybrid (recommended)</option>';
        echo '<option value="quality"' . selected($openai_mode, 'quality', false) . '>Quality (always primary)</option>';
        echo '<option value="bulk"' . selected($openai_mode, 'bulk', false) . '>Bulk (always bulk model)</option>';
        echo '</select>';
        echo '<p class="description">Hybrid balances quality + cost: primary model for high-value jobs, bulk model for large batches.</p>';
        echo '</td></tr>';

        echo '<tr><th>Primary model</th><td><input type="text" name="tmwseo_engine_settings[openai_model_primary]" value="' . $openai_model_primary . '" class="regular-text">';
        echo '<p class="description">Default: <code>gpt-4o</code></p></td></tr>';

        echo '<tr><th>Bulk model</th><td><input type="text" name="tmwseo_engine_settings[openai_model_bulk]" value="' . $openai_model_bulk . '" class="regular-text">';
        echo '<p class="description">Default: <code>gpt-4o-mini</code></p></td></tr>';

        echo '<tr><th>Brand voice</th><td>';
        echo '<select name="tmwseo_engine_settings[brand_voice]">';
        echo '<option value="premium"' . selected($brand_voice, 'premium', false) . '>Premium (recommended)</option>';
        echo '<option value="neutral"' . selected($brand_voice, 'neutral', false) . '>Neutral</option>';
        echo '</select>';
        echo '<p class="description">Affects default writing tone once AI generation is enabled in later versions.</p>';
        echo '</td></tr>';

        echo '<tr><th>Dry Run Mode</th><td>';
        echo '<label><input type="checkbox" name="tmwseo_engine_settings[tmwseo_dry_run_mode]" value="1" ' . checked($dry_run_mode, true, false) . '> Enable Dry Run Mode (Skip OpenAI, generate placeholder SEO content)</label>';
        echo '</td></tr>';

        // Legacy single-model field (hidden-ish): keep for compatibility / quick overrides.
        echo '<tr><th>Legacy model (auto)</th><td><input type="text" name="tmwseo_engine_settings[openai_model]" value="' . $openai_model . '" class="regular-text" readonly>'; 
        echo '<p class="description">This is kept for backward compatibility; it auto-syncs based on Mode.</p></td></tr>';

        echo '</table>';

        echo '<h2>DataForSEO</h2>';
        echo '<table class="form-table"><tr><th>Login</th><td><input type="text" name="tmwseo_engine_settings[dataforseo_login]" value="' . $d_login . '" class="regular-text"></td></tr>';
        echo '<tr><th>Password</th><td><input type="password" name="tmwseo_engine_settings[dataforseo_password]" value="' . $d_pass . '" class="regular-text" autocomplete="off"></td></tr></table>';

        echo '<table class="form-table"><tr><th>Location code</th><td><input type="text" name="tmwseo_engine_settings[dataforseo_location_code]" value="' . $d_loc . '" class="regular-text">';
        echo '<p class="description">Default: <code>2840</code> (United States). Change later if you want geo-specific keyword data.</p></td></tr></table>';


        echo '<table class="form-table"><tr><th>Language code</th><td><input type="text" name="tmwseo_engine_settings[dataforseo_language_code]" value="' . esc_attr((string)($opts['dataforseo_language_code'] ?? 'en')) . '" class="regular-text">';
        echo '<p class="description">Default: <code>en</code>. Example: <code>nl</code>, <code>de</code>, <code>fr</code>.</p></td></tr></table>';

        echo '<h2>Keyword Engine</h2>';
        echo '<table class="form-table"><tr><th>Competitor domains (one per line)</th><td><textarea name="tmwseo_engine_settings[competitor_domains]" rows="7" class="large-text code">' . esc_textarea((string)($opts['competitor_domains'] ?? '')) . '</textarea>';
        echo '<p class="description">Used for competitor rotation seeds. Domains only (no https://).</p></td></tr></table>';

        echo '<table class="form-table">';
        echo '<tr><th>Min search volume</th><td><input type="number" name="tmwseo_engine_settings[keyword_min_volume]" value="' . esc_attr((string)($opts['keyword_min_volume'] ?? 30)) . '" class="small-text"> <span class="description">Filter low-volume noise.</span></td></tr>';
        echo '<tr><th>Max KD</th><td><input type="number" name="tmwseo_engine_settings[keyword_max_kd]" value="' . esc_attr((string)($opts['keyword_max_kd'] ?? 60)) . '" class="small-text"> <span class="description">Auto-reject too difficult keywords.</span></td></tr>';
        echo '<tr><th>New keywords per run</th><td><input type="number" name="tmwseo_engine_settings[keyword_new_limit]" value="' . esc_attr((string)($opts['keyword_new_limit'] ?? 300)) . '" class="small-text"></td></tr>';
        echo '<tr><th>KD batch size</th><td><input type="number" name="tmwseo_engine_settings[keyword_kd_batch_limit]" value="' . esc_attr((string)($opts['keyword_kd_batch_limit'] ?? 300)) . '" class="small-text"></td></tr>';
        echo '<tr><th>Pages per day</th><td><input type="number" name="tmwseo_engine_settings[keyword_pages_per_day]" value="' . esc_attr((string)($opts['keyword_pages_per_day'] ?? 3)) . '" class="small-text"> <span class="description">Auto-created drafts (noindex) from top clusters.</span></td></tr>';
        echo '</table>';

        echo '<h2>PageSpeed Insights</h2>';
        echo '<table class="form-table"><tr><th>Google PageSpeed API key (optional)</th><td><input type="text" name="tmwseo_engine_settings[google_pagespeed_api_key]" value="' . esc_attr((string)($opts['google_pagespeed_api_key'] ?? '')) . '" class="regular-text" autocomplete="off">';
        echo '<p class="description">Optional. If empty, PSI may still work but can be rate-limited.</p></td></tr></table>';


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
}
