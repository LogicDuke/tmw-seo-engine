<?php
namespace TMWSEO\Engine\Intelligence;

use TMWSEO\Engine\Services\Settings;

if (!defined('ABSPATH')) { exit; }

class IntelligenceAdmin {

    const PAGE_SLUG = 'tmwseo-intelligence';

    public static function init(): void {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [__CLASS__, 'register_menu'], 99);
        add_action('admin_post_tmwseo_run_intelligence', [__CLASS__, 'handle_run']);
    }

    public static function register_menu(): void {
        add_submenu_page(
            'tmwseo-engine',
            __('Legacy Keyword Research', 'tmwseo'),
            __('↳ Legacy Keyword Research', 'tmwseo'),
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function handle_run(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'tmwseo'));
        }

        check_admin_referer('tmwseo_run_intelligence');

        $seeds_raw = (string)($_POST['tmwseo_seeds'] ?? '');
        $seeds_raw = wp_unslash($seeds_raw);

        $seeds = preg_split('/\r\n|\r|\n|,/', $seeds_raw);
        $seeds = is_array($seeds) ? $seeds : [];
        $seeds = array_values(array_unique(array_filter(array_map('trim', $seeds), static fn($s) => $s !== '')));

        $sources = [
            'dataforseo' => !empty($_POST['sources']['dataforseo']),
            'google' => !empty($_POST['sources']['google']),
            'bing' => !empty($_POST['sources']['bing']),
            'reddit' => !empty($_POST['sources']['reddit']),
            'serper' => !empty($_POST['sources']['serper']),
        ];

        $max_total = (int)($_POST['max_total_keywords'] ?? Settings::get('intel_max_keywords', 400));
        $recommended_limit = (int)($_POST['recommended_limit'] ?? 120);

        $options = [
            'max_seeds' => (int) Settings::get('intel_max_seeds', 3),
            'max_total_keywords' => $max_total,
            'recommended_limit' => $recommended_limit,
            'dataforseo_limit' => 200,
            'reddit_limit' => 10,
            'suggest_expand_modifiers' => true,
            'cluster_preview_limit' => 25,
            'sources' => $sources,
        ];

        $result = KeywordIntelligenceRunner::run($seeds, $options);

        if (!($result['ok'] ?? false)) {
            $err = sanitize_text_field((string)($result['error'] ?? 'unknown_error'));
            wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&run_error=' . rawurlencode($err)));
            exit;
        }

        // Persist the run.
        global $wpdb;
        $runs_table = $wpdb->prefix . 'tmw_intel_runs';
        $kw_table = $wpdb->prefix . 'tmw_intel_keywords';

        $created_at = current_time('mysql');

        $seeds_json = wp_json_encode($result['totals']['seeds'] ?? $seeds);
        $settings_json = wp_json_encode([
            'sources' => $sources,
            'max_total_keywords' => $options['max_total_keywords'],
            'recommended_limit' => $options['recommended_limit'],
            'suggest_expand_modifiers' => $options['suggest_expand_modifiers'],
        ]);

        $totals_json = wp_json_encode([
            'totals' => $result['totals'] ?? [],
            'errors' => $result['errors'] ?? [],
            'recommended' => array_slice($result['recommended'] ?? [], 0, 200),
            'clusters' => array_slice($result['clusters'] ?? [], 0, 50),
        ]);

        $wpdb->insert(
            $runs_table,
            [
                'created_at' => $created_at,
                'seeds' => $seeds_json,
                'settings' => $settings_json,
                'totals' => $totals_json,
                'status' => 'active',
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );

        $run_id = (int) $wpdb->insert_id;

        // Insert keyword rows.
        $rows = $result['rows'] ?? [];
        if (!is_array($rows)) $rows = [];

        foreach ($rows as $r) {
            if (!is_array($r)) continue;

            $keyword = (string)($r['keyword'] ?? '');
            if ($keyword === '') continue;

            $source = (string)($r['source'] ?? '');
            $seed = (string)($r['seed'] ?? '');

            $wpdb->insert(
                $kw_table,
                [
                    'run_id' => $run_id,
                    'keyword' => $keyword,
                    'source' => mb_substr($source, 0, 190),
                    'seed' => mb_substr($seed, 0, 254),
                    'volume' => is_null($r['volume']) ? null : (int)$r['volume'],
                    'kd' => is_null($r['kd']) ? null : (float)$r['kd'],
                    'intent' => mb_substr((string)($r['intent'] ?? ''), 0, 29),
                    'kd_bucket' => mb_substr((string)($r['kd_bucket'] ?? ''), 0, 9),
                    'opportunity' => is_null($r['opportunity']) ? null : (float)$r['opportunity'],
                    'created_at' => $created_at,
                ],
                ['%d','%s','%s','%s','%d','%f','%s','%s','%f','%s']
            );
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&run_id=' . $run_id . '&done=1'));
        exit;
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'tmwseo'));
        }

        $run_id = isset($_GET['run_id']) ? (int)$_GET['run_id'] : 0;
        $done = !empty($_GET['done']);
        $run_error = isset($_GET['run_error']) ? sanitize_text_field((string)$_GET['run_error']) : '';

        echo '<div class="wrap">';
        echo '<h1>TMW SEO Engine — Intelligence (Phase 1)</h1>';
        echo '<p><strong>Manual-only:</strong> This module only generates analysis + advice. It never auto-edits posts.</p>';

        $manual_mode = (int) Settings::get('manual_control_mode', 1);
        if ($manual_mode) {
            echo '<div class="notice notice-success"><p><strong>Manual Control Mode is ON.</strong> All cron + auto-optimizations are disabled by default.</p></div>';
        } else {
            echo '<div class="notice notice-warning"><p><strong>Manual Control Mode is OFF.</strong> Background automation may run.</p></div>';
        }

        if ($done) {
            echo '<div class="notice notice-success"><p>Intelligence run completed and saved.</p></div>';
        }

        if ($run_error !== '') {
            echo '<div class="notice notice-error"><p>Run failed: <code>' . esc_html($run_error) . '</code></p></div>';
        }

        echo '<hr />';

        echo '<h2>Run a new Intelligence Analysis</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="tmwseo_run_intelligence" />';
        wp_nonce_field('tmwseo_run_intelligence');

        echo '<table class="form-table">';
        echo '<tr><th scope="row"><label for="tmwseo_seeds">Seed keywords</label></th><td>';
        echo '<textarea id="tmwseo_seeds" name="tmwseo_seeds" rows="4" class="large-text" placeholder="example: livejasmin cam girls\nprivate webcam chat\nbest adult cam site">' . esc_textarea('') . '</textarea>';
        echo '<p class="description">Max 3 seeds per run. Separate by new lines or commas.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Sources</th><td>';
        $serper_key = trim((string) Settings::get('serper_api_key', ''));

        echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="sources[dataforseo]" value="1" checked> DataForSEO (keyword suggestions + KD + volume)</label>';
        echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="sources[google]" value="1" checked> Google Suggest</label>';
        echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="sources[bing]" value="1" checked> Bing Suggest</label>';
        echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="sources[reddit]" value="1" checked> Reddit titles</label>';

        if ($serper_key !== '') {
            echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="sources[serper]" value="1" checked> Serper (PAA + related searches)</label>';
        } else {
            echo '<label style="display:block;margin-bottom:6px;opacity:0.7;"><input type="checkbox" name="sources[serper]" value="1" disabled> Serper (PAA + related searches) — add API key in Settings to enable</label>';
        }

        echo '<p class="description">Default uses a balanced multi-source mix (Option C). Everything stays in analysis mode.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Max total keywords</th><td>';
        echo '<input type="number" name="max_total_keywords" value="' . esc_attr((string) Settings::get('intel_max_keywords', 400)) . '" class="small-text" min="50" max="2000" />';
        echo '<p class="description">Hard cap before KD/volume enrichment (controls cost).</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Recommended picks</th><td>';
        echo '<input type="number" name="recommended_limit" value="120" class="small-text" min="30" max="500" />';
        echo '<p class="description">We pick a Growth Mix 70/20/10 across low/medium/high KD (you can still target high KD pages).</p>';
        echo '</td></tr>';

        echo '</table>';

        submit_button('Run Intelligence Analysis');
        echo '</form>';

        echo '<hr />';

        // Display last run (or requested run).
        global $wpdb;
        $runs_table = $wpdb->prefix . 'tmw_intel_runs';
        $kw_table = $wpdb->prefix . 'tmw_intel_keywords';

        if ($run_id <= 0) {
            $run_id = (int) $wpdb->get_var("SELECT id FROM {$runs_table} ORDER BY id DESC LIMIT 1");
        }

        if ($run_id > 0) {
            $run = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$runs_table} WHERE id=%d", $run_id), ARRAY_A);

            if ($run) {
                $totals = json_decode((string)($run['totals'] ?? ''), true);
                if (!is_array($totals)) $totals = [];

                $summary = $totals['totals'] ?? [];
                $errors = $totals['errors'] ?? [];
                $recommended = $totals['recommended'] ?? [];
                $clusters = $totals['clusters'] ?? [];

                echo '<h2>Latest saved report (Run #' . (int)$run_id . ')</h2>';
                echo '<p><strong>Date:</strong> ' . esc_html((string)($run['created_at'] ?? '')) . '</p>';

                $bucket_counts = $summary['bucket_counts'] ?? [];

                echo '<ul style="list-style:disc;margin-left:20px;">';
                echo '<li><strong>Total collected:</strong> ' . esc_html((string)($summary['total_collected'] ?? '')) . '</li>';
                echo '<li><strong>Total relevant:</strong> ' . esc_html((string)($summary['total_relevant'] ?? '')) . '</li>';
                echo '<li><strong>KD buckets:</strong> low ' . esc_html((string)($bucket_counts['low'] ?? 0)) . ' / medium ' . esc_html((string)($bucket_counts['medium'] ?? 0)) . ' / high ' . esc_html((string)($bucket_counts['high'] ?? 0)) . ' / very_high ' . esc_html((string)($bucket_counts['very_high'] ?? 0)) . ' / unknown ' . esc_html((string)($bucket_counts['unknown'] ?? 0)) . '</li>';
                echo '</ul>';

                if (!empty($errors) && is_array($errors)) {
                    echo '<div class="notice notice-warning"><p><strong>Notes:</strong></p><ul style="margin-left:20px;">';
                    foreach ($errors as $e) {
                        echo '<li>' . esc_html((string)$e) . '</li>';
                    }
                    echo '</ul></div>';
                }

                echo '<h3>Recommended keywords (Growth Mix 70/20/10)</h3>';
                echo '<table class="widefat striped">';
                echo '<thead><tr><th>Keyword</th><th>Volume</th><th>KD</th><th>KD Bucket</th><th>Intent</th><th>Opportunity</th><th>Sources</th></tr></thead><tbody>';

                // If recommended payload is stored, render it. Otherwise, fallback to querying DB.
                if (is_array($recommended) && !empty($recommended)) {
                    foreach (array_slice($recommended, 0, 120) as $r) {
                        if (!is_array($r)) continue;
                        echo '<tr>';
                        echo '<td>' . esc_html((string)($r['keyword'] ?? '')) . '</td>';
                        echo '<td>' . esc_html((string)($r['volume'] ?? '')) . '</td>';
                        echo '<td>' . esc_html((string)($r['kd'] ?? '')) . '</td>';
                        echo '<td>' . esc_html((string)($r['kd_bucket'] ?? '')) . '</td>';
                        echo '<td>' . esc_html((string)($r['intent'] ?? '')) . '</td>';
                        echo '<td>' . esc_html((string)($r['opportunity'] ?? '')) . '</td>';
                        echo '<td>' . esc_html((string)($r['source'] ?? '')) . '</td>';
                        echo '</tr>';
                    }
                } else {
                    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$kw_table} WHERE run_id=%d ORDER BY opportunity DESC LIMIT 120", $run_id), ARRAY_A);
                    foreach ($rows as $r) {
                        echo '<tr>';
                        echo '<td>' . esc_html((string)($r['keyword'] ?? '')) . '</td>';
                        echo '<td>' . esc_html((string)($r['volume'] ?? '')) . '</td>';
                        echo '<td>' . esc_html((string)($r['kd'] ?? '')) . '</td>';
                        echo '<td>' . esc_html((string)($r['kd_bucket'] ?? '')) . '</td>';
                        echo '<td>' . esc_html((string)($r['intent'] ?? '')) . '</td>';
                        echo '<td>' . esc_html((string)($r['opportunity'] ?? '')) . '</td>';
                        echo '<td>' . esc_html((string)($r['source'] ?? '')) . '</td>';
                        echo '</tr>';
                    }
                }

                echo '</tbody></table>';

                if (is_array($clusters) && !empty($clusters)) {
                    echo '<h3>Cluster preview (top)</h3>';
                    echo '<table class="widefat striped">';
                    echo '<thead><tr><th>Cluster</th><th>Keywords</th><th>Total Volume</th><th>Avg KD</th><th>Best Opportunity</th><th>Examples</th></tr></thead><tbody>';
                    foreach (array_slice($clusters, 0, 25) as $c) {
                        if (!is_array($c)) continue;
                        echo '<tr>';
                        echo '<td>' . esc_html((string)($c['cluster'] ?? '')) . '</td>';
                        echo '<td>' . esc_html((string)($c['count'] ?? '')) . '</td>';
                        echo '<td>' . esc_html((string)($c['total_volume'] ?? '')) . '</td>';
                        echo '<td>' . esc_html((string)($c['avg_kd'] ?? '')) . '</td>';
                        echo '<td>' . esc_html((string)($c['best_opportunity'] ?? '')) . '</td>';
                        $examples = $c['examples'] ?? [];
                        if (is_array($examples)) {
                            $examples = implode(', ', array_slice($examples, 0, 4));
                        } else {
                            $examples = '';
                        }
                        echo '<td>' . esc_html((string)$examples) . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                }

                echo '<p style="margin-top:15px;">Run history is stored in the database (Phase 2 will add archiving + comparisons).</p>';
            }
        } else {
            echo '<p>No intelligence runs found yet. Run your first analysis above.</p>';
        }

        echo '</div>';
    }
}
