<?php
namespace TMWSEO\Engine\Debug;

use TMWSEO\Engine\Integrations\GoogleAdsKeywordPlannerApi;
use TMWSEO\Engine\Integrations\GSCApi;
use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Services\OpenAI;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Clustering\ClusterBuilder;
use TMWSEO\Engine\Jobs;
use TMWSEO\Engine\Worker;

if (!defined('ABSPATH')) { exit; }

class DebugDashboard {
    private const PAGE_SLUG = 'tmwseo-debug-dashboard';

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 99);
    }

    public static function register_menu(): void {
        add_submenu_page(
            null,
            __('Debug Dashboard', 'tmwseo'),
            __('Debug Dashboard', 'tmwseo'),
            'manage_options',
            'tmw-seo-debug',
            [__CLASS__, 'render_legacy_redirect']
        );
    }

    public static function render_legacy_redirect(): void {
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $state = self::maybe_run_section_action();

        echo '<div class="wrap">';
        echo '<h1>TMW SEO Engine → Debug Dashboard</h1>';
        echo '<p>Developer-only diagnostics for SEO APIs and pipeline components. Results are view-only and are not stored as debug records.</p>';

        self::render_keyword_planner_section($state);
        self::render_dataforseo_suggestions_section($state);
        self::render_serp_analysis_section($state);
        self::render_competitor_domain_section($state);
        self::render_gsc_section($state);
        self::render_internal_links_section($state);
        self::render_cluster_section($state);
        self::render_api_status_section();
        self::render_worker_section($state);
        self::render_system_diagnostics_section();

        self::render_loading_script();
        echo '</div>';
    }

    private static function maybe_run_section_action(): array {
        $state = [
            'active' => '',
            'result' => [],
            'error' => '',
            'inputs' => [],
        ];

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return $state;
        }

        $nonce = sanitize_text_field((string) ($_POST['tmwseo_debug_nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'tmwseo_debug_dashboard')) {
            $state['error'] = 'Security check failed. Please refresh and retry.';
            return $state;
        }

        $action = sanitize_key((string) ($_POST['tmwseo_debug_action'] ?? ''));
        $state['active'] = $action;

        if ($action === 'keyword_planner') {
            $keyword = sanitize_text_field((string) ($_POST['keyword'] ?? ''));
            $state['inputs']['keyword'] = $keyword;
            if ($keyword === '') {
                $state['error'] = 'Keyword is required.';
                return $state;
            }
            $state['result'] = GoogleAdsKeywordPlannerApi::keyword_ideas_debug($keyword, 30);
            return $state;
        }

        if ($action === 'dataforseo_suggestions') {
            $keyword = sanitize_text_field((string) ($_POST['keyword'] ?? ''));
            $state['inputs']['keyword'] = $keyword;
            if ($keyword === '') {
                $state['error'] = 'Keyword is required.';
                return $state;
            }
            $state['result'] = DataForSEO::keyword_suggestions($keyword, 30);
            return $state;
        }

        if ($action === 'serp_scan') {
            $keyword = sanitize_text_field((string) ($_POST['keyword'] ?? ''));
            $state['inputs']['keyword'] = $keyword;
            if ($keyword === '') {
                $state['error'] = 'Keyword is required.';
                return $state;
            }
            $state['result'] = DataForSEO::serp_live($keyword, 20);
            return $state;
        }

        if ($action === 'analyze_domain') {
            $domain = sanitize_text_field((string) ($_POST['domain'] ?? ''));
            $state['inputs']['domain'] = $domain;
            if ($domain === '') {
                $state['error'] = 'Domain is required.';
                return $state;
            }
            $state['result'] = DataForSEO::ranked_keywords($domain, 50);
            return $state;
        }

        if ($action === 'fetch_gsc_queries') {
            $site_url = trim((string) Settings::get('gsc_site_url', ''));
            if ($site_url === '') {
                $state['error'] = 'Set GSC Site URL in plugin settings first.';
                return $state;
            }
            $state['result'] = GSCApi::search_analytics(
                $site_url,
                gmdate('Y-m-d', strtotime('-30 days')),
                gmdate('Y-m-d'),
                ['query'],
                20
            );
            return $state;
        }

        if ($action === 'scan_internal_links') {
            $state['result'] = ['ok' => true, 'items' => self::build_internal_link_suggestions()];
            return $state;
        }

        if ($action === 'generate_cluster') {
            $raw = (string) ($_POST['keywords'] ?? '');
            $state['inputs']['keywords'] = $raw;
            $keywords = preg_split('/\r\n|\r|\n/', $raw);
            $keywords = is_array($keywords) ? array_values(array_filter(array_map(static fn($k) => trim((string) $k), $keywords))) : [];
            if (empty($keywords)) {
                $state['error'] = 'Please paste one keyword per line.';
                return $state;
            }
            $builder = new ClusterBuilder();
            $state['result'] = ['ok' => true, 'clusters' => $builder->build($keywords)];
            return $state;
        }

        if ($action === 'run_worker_now') {
            Jobs::enqueue('healthcheck', 'system', 0, ['trigger' => 'debug-dashboard']);
            Worker::run();
            $state['result'] = ['ok' => true, 'message' => 'Worker executed.'];
            return $state;
        }

        return $state;
    }

    private static function render_card_open(string $title): void {
        echo '<div class="postbox" style="margin-top:16px;"><div class="postbox-header"><h2 class="hndle">' . esc_html($title) . '</h2></div><div class="inside">';
    }

    private static function render_card_close(): void {
        echo '</div></div>';
    }

    private static function render_keyword_planner_section(array $state): void {
        self::render_card_open('SECTION 1 — Google Keyword Planner Test');
        self::render_keyword_form('keyword_planner', 'Run Keyword Planner Test', $state['inputs']['keyword'] ?? '');

        if (($state['active'] ?? '') === 'keyword_planner') {
            self::render_error_notice($state['error'] ?? '');
            $result = $state['result'];
            $items = is_array($result['items'] ?? null) ? $result['items'] : [];
            self::render_table(['Keyword', 'Avg Monthly Searches', 'Competition', 'Low CPC', 'High CPC'], array_map(static function (array $item): array {
                $info = is_array($item['keyword_info'] ?? null) ? $item['keyword_info'] : [];
                return [
                    (string) ($item['keyword'] ?? ''),
                    (string) ((int) ($info['search_volume'] ?? 0)),
                    (string) ($info['competition_label'] ?? ($info['competition'] ?? '')),
                    '$' . number_format((float) ($info['low_top_of_page_bid'] ?? 0), 2),
                    '$' . number_format((float) ($info['high_top_of_page_bid'] ?? 0), 2),
                ];
            }, $items));
            self::render_raw_response($result['raw_response'] ?? null);
        }

        self::render_card_close();
    }

    private static function render_dataforseo_suggestions_section(array $state): void {
        self::render_card_open('SECTION 2 — DataForSEO Keyword Suggestions Test');
        self::render_keyword_form('dataforseo_suggestions', 'Run DataForSEO Suggestions', $state['inputs']['keyword'] ?? '');

        if (($state['active'] ?? '') === 'dataforseo_suggestions') {
            self::render_error_notice($state['error'] ?? '');
            $result = $state['result'];
            $items = is_array($result['items'] ?? null) ? $result['items'] : [];
            self::render_table(['Keyword', 'Search Volume', 'Keyword Difficulty', 'CPC', 'Competition'], array_map(static function (array $item): array {
                $info = is_array($item['keyword_info'] ?? null) ? $item['keyword_info'] : [];
                return [
                    (string) ($item['keyword'] ?? ''),
                    (string) ((int) ($info['search_volume'] ?? 0)),
                    isset($info['keyword_difficulty']) ? (string) $info['keyword_difficulty'] : '-',
                    '$' . number_format((float) ($info['cpc'] ?? 0), 2),
                    isset($info['competition']) ? (string) $info['competition'] : '-',
                ];
            }, $items));
            self::render_raw_response($result['raw'] ?? null);
        }

        self::render_card_close();
    }

    private static function render_serp_analysis_section(array $state): void {
        self::render_card_open('SECTION 3 — DataForSEO SERP Analysis');
        self::render_keyword_form('serp_scan', 'Run SERP Scan', $state['inputs']['keyword'] ?? '');

        if (($state['active'] ?? '') === 'serp_scan') {
            self::render_error_notice($state['error'] ?? '');
            $items = is_array(($state['result']['items'] ?? null)) ? $state['result']['items'] : [];
            self::render_table(['Rank', 'Domain', 'URL', 'Title', 'Snippet'], array_map(static function (array $item): array {
                return [
                    (string) ((int) ($item['position'] ?? 0)),
                    (string) ($item['domain'] ?? ''),
                    (string) ($item['url'] ?? ''),
                    (string) ($item['title'] ?? ''),
                    (string) ($item['snippet'] ?? ''),
                ];
            }, $items));

            $frequency = [];
            foreach ($items as $item) {
                $domain = strtolower(trim((string) ($item['domain'] ?? '')));
                if ($domain === '') {
                    continue;
                }
                $frequency[$domain] = (int) ($frequency[$domain] ?? 0) + 1;
            }
            arsort($frequency);
            echo '<h4>Domain frequency</h4>';
            $freq_rows = [];
            foreach ($frequency as $domain => $count) {
                $freq_rows[] = [$domain, (string) $count];
            }
            self::render_table(['Domain', 'Appearances'], $freq_rows);
            self::render_raw_response($state['result']['raw'] ?? null);
        }

        self::render_card_close();
    }

    private static function render_competitor_domain_section(array $state): void {
        self::render_card_open('SECTION 4 — Competitor Domain Analyzer');

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '" class="tmwseo-debug-form">';
        wp_nonce_field('tmwseo_debug_dashboard', 'tmwseo_debug_nonce');
        echo '<input type="hidden" name="tmwseo_debug_action" value="analyze_domain" />';
        echo '<p><label><strong>Domain</strong><br /><input type="text" class="regular-text" name="domain" value="' . esc_attr((string) ($state['inputs']['domain'] ?? '')) . '" placeholder="example.com" /></label></p>';
        submit_button('Analyze Domain', 'secondary', '', false);
        echo '<span class="spinner tmwseo-debug-loading" style="float:none;"></span>';
        echo '</form>';

        if (($state['active'] ?? '') === 'analyze_domain') {
            self::render_error_notice($state['error'] ?? '');
            $items = is_array(($state['result']['items'] ?? null)) ? $state['result']['items'] : [];
            self::render_table(['Keyword', 'Position', 'Search Volume', 'Traffic'], array_map(static function (array $item): array {
                return [
                    (string) ($item['keyword_data']['keyword'] ?? $item['keyword'] ?? ''),
                    (string) ((int) ($item['ranked_serp_element']['serp_item']['rank_absolute'] ?? $item['rank_absolute'] ?? 0)),
                    (string) ((int) ($item['keyword_data']['keyword_info']['search_volume'] ?? 0)),
                    isset($item['etv']) ? (string) $item['etv'] : '-',
                ];
            }, $items));
            self::render_raw_response($state['result']['raw'] ?? null);
        }

        self::render_card_close();
    }

    private static function render_gsc_section(array $state): void {
        self::render_card_open('SECTION 5 — Google Search Console Test');

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '" class="tmwseo-debug-form">';
        wp_nonce_field('tmwseo_debug_dashboard', 'tmwseo_debug_nonce');
        echo '<input type="hidden" name="tmwseo_debug_action" value="fetch_gsc_queries" />';
        submit_button('Fetch Top Queries', 'secondary', '', false);
        echo '<span class="spinner tmwseo-debug-loading" style="float:none;"></span>';
        echo '</form>';

        if (($state['active'] ?? '') === 'fetch_gsc_queries') {
            self::render_error_notice($state['error'] ?? '');
            $rows = is_array(($state['result']['rows'] ?? null)) ? $state['result']['rows'] : [];
            self::render_table(['Query', 'Clicks', 'Impressions', 'CTR', 'Position'], array_map(static function (array $row): array {
                return [
                    (string) ($row['keys'][0] ?? ''),
                    (string) ((int) ($row['clicks'] ?? 0)),
                    (string) ((int) ($row['impressions'] ?? 0)),
                    number_format(((float) ($row['ctr'] ?? 0)) * 100, 2) . '%',
                    number_format((float) ($row['position'] ?? 0), 2),
                ];
            }, $rows));
        }

        self::render_card_close();
    }

    private static function render_internal_links_section(array $state): void {
        self::render_card_open('SECTION 6 — Internal Link Engine Test');

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '" class="tmwseo-debug-form">';
        wp_nonce_field('tmwseo_debug_dashboard', 'tmwseo_debug_nonce');
        echo '<input type="hidden" name="tmwseo_debug_action" value="scan_internal_links" />';
        submit_button('Scan Site Content', 'secondary', '', false);
        echo '<span class="spinner tmwseo-debug-loading" style="float:none;"></span>';
        echo '</form>';

        if (($state['active'] ?? '') === 'scan_internal_links') {
            $items = is_array(($state['result']['items'] ?? null)) ? $state['result']['items'] : [];
            self::render_table(['Source Post', 'Suggested Anchor', 'Target Post'], array_map(static function (array $item): array {
                return [
                    (string) ($item['source_post'] ?? ''),
                    (string) ($item['anchor'] ?? ''),
                    (string) ($item['target_post'] ?? ''),
                ];
            }, $items));
        }

        self::render_card_close();
    }

    private static function render_cluster_section(array $state): void {
        self::render_card_open('SECTION 7 — Keyword Cluster Simulator');

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '" class="tmwseo-debug-form">';
        wp_nonce_field('tmwseo_debug_dashboard', 'tmwseo_debug_nonce');
        echo '<input type="hidden" name="tmwseo_debug_action" value="generate_cluster" />';
        echo '<p><label><strong>Paste multiple keywords</strong><br /><textarea name="keywords" rows="6" class="large-text" placeholder="keyword one&#10;keyword two">' . esc_textarea((string) ($state['inputs']['keywords'] ?? '')) . '</textarea></label></p>';
        submit_button('Generate Cluster', 'secondary', '', false);
        echo '<span class="spinner tmwseo-debug-loading" style="float:none;"></span>';
        echo '</form>';

        if (($state['active'] ?? '') === 'generate_cluster') {
            self::render_error_notice($state['error'] ?? '');
            $clusters = is_array(($state['result']['clusters'] ?? null)) ? $state['result']['clusters'] : [];
            self::render_table(['Cluster Name', 'Representative Keyword', 'Keywords inside cluster'], array_map(static function (array $cluster): array {
                return [
                    (string) ($cluster['cluster'] ?? ''),
                    (string) ($cluster['primary'] ?? ''),
                    implode(', ', array_map('strval', (array) ($cluster['keywords'] ?? []))),
                ];
            }, $clusters));
        }

        self::render_card_close();
    }

    private static function render_api_status_section(): void {
        self::render_card_open('SECTION 8 — API Status Monitor');

        $statuses = [
            'Google Ads API' => GoogleAdsKeywordPlannerApi::is_configured() ? 'Connected' : 'Error',
            'DataForSEO' => DataForSEO::is_configured() ? 'Connected' : 'Error',
            'Google Search Console' => GSCApi::is_connected() ? 'Connected' : 'Error',
            'OpenAI' => OpenAI::is_configured() ? 'Connected' : 'Error',
            'Anthropic' => trim((string) Settings::get('tmwseo_anthropic_api_key', '')) !== '' ? 'Connected' : 'Error',
        ];

        $rows = [];
        foreach ($statuses as $service => $status) {
            $rows[] = [$service, $status];
        }

        self::render_table(['Service', 'Status'], $rows);
        self::render_card_close();
    }

    private static function render_worker_section(array $state): void {
        self::render_card_open('SECTION 9 — Background Worker Monitor');
        $counts = Jobs::counts();
        self::render_table(['Metric', 'Count'], [
            ['Pending jobs', (string) ((int) ($counts['queued'] ?? 0))],
            ['Running jobs', (string) ((int) ($counts['running'] ?? 0))],
            ['Failed jobs', (string) ((int) ($counts['dead'] ?? 0))],
        ]);

        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '" class="tmwseo-debug-form">';
        wp_nonce_field('tmwseo_debug_dashboard', 'tmwseo_debug_nonce');
        echo '<input type="hidden" name="tmwseo_debug_action" value="run_worker_now" />';
        submit_button('Run Worker Now', 'secondary', '', false);
        echo '<span class="spinner tmwseo-debug-loading" style="float:none;"></span>';
        echo '</form>';

        if (($state['active'] ?? '') === 'run_worker_now' && !empty($state['result']['message'])) {
            echo '<p><em>' . esc_html((string) $state['result']['message']) . '</em></p>';
        }

        self::render_card_close();
    }

    private static function render_system_diagnostics_section(): void {
        global $wpdb;

        self::render_card_open('SECTION 10 — System Diagnostics');

        $plugin_db_version = (string) get_option('tmwseo_engine_db_version', 'not_set');

        $tables = [
            $wpdb->prefix . 'tmw_jobs',
            $wpdb->prefix . 'tmw_logs',
            $wpdb->prefix . 'tmw_keyword_candidates',
            $wpdb->prefix . 'tmw_keyword_clusters',
            $wpdb->prefix . 'tmw_seo_opportunities',
        ];

        $table_status = [];
        $missing_indexes = [];
        foreach ($tables as $table) {
            $exists = ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
            $table_status[] = [$table, $exists ? 'OK' : 'Missing'];
            if (!$exists) {
                $missing_indexes[] = $table . ' (table missing)';
                continue;
            }
            $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            if (!is_array($indexes) || empty($indexes)) {
                $missing_indexes[] = $table;
            }
        }

        $queue_counts = Jobs::counts();
        $queue_sizes = sprintf('queued=%d, running=%d, dead=%d', (int) ($queue_counts['queued'] ?? 0), (int) ($queue_counts['running'] ?? 0), (int) ($queue_counts['dead'] ?? 0));
        $last_cron = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(time) FROM {$wpdb->prefix}tmw_logs WHERE context = %s",
            'cron'
        ));

        self::render_table(['Diagnostic', 'Value'], [
            ['Plugin DB version', $plugin_db_version],
            ['Tables status', implode(' | ', array_map(static fn($row) => $row[0] . ': ' . $row[1], $table_status))],
            ['Missing indexes', empty($missing_indexes) ? 'None detected' : implode(', ', $missing_indexes)],
            ['Queue sizes', $queue_sizes],
            ['Last cron execution', $last_cron !== '' ? $last_cron : 'No cron log found'],
        ]);

        self::render_card_close();
    }

    private static function render_keyword_form(string $action, string $button_text, string $keyword = ''): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)) . '" class="tmwseo-debug-form">';
        wp_nonce_field('tmwseo_debug_dashboard', 'tmwseo_debug_nonce');
        echo '<input type="hidden" name="tmwseo_debug_action" value="' . esc_attr($action) . '" />';
        echo '<p><label><strong>Keyword</strong><br /><input type="text" class="regular-text" name="keyword" value="' . esc_attr($keyword) . '" /></label></p>';
        submit_button($button_text, 'secondary', '', false);
        echo '<span class="spinner tmwseo-debug-loading" style="float:none;"></span>';
        echo '</form>';
    }

    private static function render_table(array $headers, array $rows): void {
        echo '<table class="widefat striped" style="margin-top:12px;"><thead><tr>';
        foreach ($headers as $header) {
            echo '<th>' . esc_html((string) $header) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if (empty($rows)) {
            echo '<tr><td colspan="' . esc_attr((string) count($headers)) . '"><em>No rows to display.</em></td></tr>';
        } else {
            foreach ($rows as $row) {
                echo '<tr>';
                foreach ($row as $value) {
                    echo '<td>' . esc_html((string) $value) . '</td>';
                }
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
    }

    private static function render_raw_response($data): void {
        if ($data === null || $data === '') {
            return;
        }

        echo '<details style="margin-top:10px;"><summary><strong>Raw API Response</strong></summary>';
        echo '<pre style="max-height:280px;overflow:auto;background:#f6f7f7;padding:10px;">' . esc_html((string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
        echo '</details>';
    }

    private static function render_error_notice(string $error): void {
        if ($error === '') {
            return;
        }
        echo '<div class="notice notice-error inline"><p>' . esc_html($error) . '</p></div>';
    }

    private static function build_internal_link_suggestions(): array {
        $posts = get_posts([
            'post_type' => 'any',
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'orderby' => 'rand',
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        if (!is_array($posts) || empty($posts)) {
            return [];
        }

        $tokens_by_post = [];
        foreach ($posts as $post_id) {
            $title = (string) get_the_title((int) $post_id);
            $tokens_by_post[(int) $post_id] = self::extract_title_keywords($title);
        }

        $suggestions = [];
        foreach ($posts as $source_id) {
            $source_id = (int) $source_id;
            $source_tokens = $tokens_by_post[$source_id] ?? [];
            if (empty($source_tokens)) {
                continue;
            }
            foreach ($posts as $target_id) {
                $target_id = (int) $target_id;
                if ($target_id === $source_id) {
                    continue;
                }
                $overlap = array_values(array_intersect($source_tokens, $tokens_by_post[$target_id] ?? []));
                if (empty($overlap)) {
                    continue;
                }
                $suggestions[] = [
                    'source_post' => get_the_title($source_id),
                    'anchor' => $overlap[0],
                    'target_post' => get_the_title($target_id),
                ];
                if (count($suggestions) >= 50) {
                    break 2;
                }
            }
        }

        return $suggestions;
    }

    private static function extract_title_keywords(string $title): array {
        $tokens = preg_split('/[^a-z0-9]+/i', strtolower($title));
        $tokens = is_array($tokens) ? array_values(array_filter($tokens)) : [];
        $stop = ['the','a','an','and','or','for','to','in','on','of','with','by','is','at'];
        $tokens = array_values(array_filter($tokens, static fn($token) => strlen($token) > 2 && !in_array($token, $stop, true)));
        return array_values(array_unique($tokens));
    }

    private static function render_loading_script(): void {
        echo '<script>';
        echo 'document.querySelectorAll(".tmwseo-debug-form").forEach(function(form){form.addEventListener("submit",function(){var spinner=form.querySelector(".tmwseo-debug-loading"); if(spinner){spinner.classList.add("is-active");} var button=form.querySelector("button[type=submit]"); if(button){button.disabled=true;}});});';
        echo '</script>';
    }
}
