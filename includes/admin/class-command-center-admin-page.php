<?php
namespace TMWSEO\Engine\Admin;

if (!defined('ABSPATH')) { exit; }

class CommandCenterAdminPage {
    private const NONCE_ACTION = 'tmwseo_command_center_stats';

    public static function init(): void {
        add_action('wp_ajax_tmwseo_dashboard_stats', [__CLASS__, 'ajax_dashboard_stats']);
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $stats = self::get_dashboard_stats();
        ?>
        <div class="wrap tmwseo-command-center-page">
            <h1>Command Center</h1>
            <p>Real-time SEO engine overview (refreshes every 30 seconds).</p>

            <div id="tmwseo-command-center-grid" class="tmwseo-command-center-grid">
                <?php self::render_widgets($stats); ?>
            </div>
        </div>
        <style>
            .tmwseo-command-center-grid {display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;}
            .tmwseo-command-center-grid .postbox{margin:0;}
            .tmwseo-stat-list{margin:0;padding:0;list-style:none;}
            .tmwseo-stat-list li{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f0f0f1;}
            .tmwseo-stat-list li:last-child{border-bottom:none;}
            .tmwseo-worker-status{display:inline-flex;align-items:center;gap:8px;}
            .tmwseo-dot{display:inline-block;width:10px;height:10px;border-radius:999px;}
            .tmwseo-dot.running{background:#16a34a;}
            .tmwseo-dot.idle{background:#eab308;}
            .tmwseo-dot.failed{background:#dc2626;}
            .tmwseo-table{width:100%;border-collapse:collapse;}
            .tmwseo-table th,.tmwseo-table td{padding:8px;border-bottom:1px solid #f0f0f1;text-align:left;}
        </style>
        <script>
            (function(){
                const nonce = <?php echo wp_json_encode(wp_create_nonce(self::NONCE_ACTION)); ?>;
                const action = 'tmwseo_dashboard_stats';
                const refresh = function() {
                    const body = new URLSearchParams({action: action, nonce: nonce});
                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: body.toString()
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (!data || !data.success || !data.data || !data.data.html) return;
                        const grid = document.getElementById('tmwseo-command-center-grid');
                        if (grid) grid.innerHTML = data.data.html;
                    })
                    .catch(() => {});
                };

                window.setInterval(refresh, 30000);
            })();
        </script>
        <?php
    }

    public static function ajax_dashboard_stats(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $stats = self::get_dashboard_stats();
        ob_start();
        self::render_widgets($stats);
        $html = ob_get_clean();

        wp_send_json_success([
            'stats' => $stats,
            'html'  => $html,
            'refreshed_at' => current_time('mysql'),
        ]);
    }

    private static function render_widgets(array $stats): void {
        self::render_stat_widget('Keyword Pipeline', [
            'Raw Keywords' => (int) ($stats['keyword_pipeline']['raw_keywords'] ?? 0),
            'Candidates' => (int) ($stats['keyword_pipeline']['candidates'] ?? 0),
            'Approved' => (int) ($stats['keyword_pipeline']['approved'] ?? 0),
            'Clusters' => (int) ($stats['keyword_pipeline']['clusters'] ?? 0),
        ]);

        self::render_stat_widget('SEO Opportunity Engine', [
            'Total Opportunities' => (int) ($stats['opportunities']['total'] ?? 0),
            'High Score Opportunities' => (int) ($stats['opportunities']['high_score'] ?? 0),
            'Approved Opportunities' => (int) ($stats['opportunities']['approved'] ?? 0),
        ]);

        self::render_worker_widget($stats['worker_health'] ?? []);

        self::render_stat_widget('API Usage', [
            'DataForSEO (today / month)' => (int) ($stats['api_usage']['dataforseo_today'] ?? 0) . ' / ' . (int) ($stats['api_usage']['dataforseo_month'] ?? 0),
            'Google Search Console (today / month)' => (int) ($stats['api_usage']['gsc_today'] ?? 0) . ' / ' . (int) ($stats['api_usage']['gsc_month'] ?? 0),
            'Google Keyword Planner (today / month)' => (int) ($stats['api_usage']['gkp_today'] ?? 0) . ' / ' . (int) ($stats['api_usage']['gkp_month'] ?? 0),
        ]);

        self::render_import_widget($stats['import_activity'] ?? []);

        self::render_stat_widget('Cluster Quality', [
            'Average cluster score' => number_format((float) ($stats['cluster_quality']['average_score'] ?? 0), 2),
            'Clusters graded A' => (int) ($stats['cluster_quality']['grade_a'] ?? 0),
            'Clusters graded B' => (int) ($stats['cluster_quality']['grade_b'] ?? 0),
            'Clusters graded C/D' => (int) ($stats['cluster_quality']['grade_cd'] ?? 0),
        ]);
    }

    private static function render_stat_widget(string $title, array $rows): void {
        echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">' . esc_html($title) . '</h2></div><div class="inside">';
        echo '<ul class="tmwseo-stat-list">';
        foreach ($rows as $label => $value) {
            echo '<li><span>' . esc_html((string) $label) . '</span><strong>' . esc_html((string) $value) . '</strong></li>';
        }
        echo '</ul></div></div>';
    }

    private static function render_worker_widget(array $workers): void {
        $default = [
            'keyword_worker' => ['label' => 'Keyword Worker', 'status' => 'idle', 'last_run' => null],
            'clustering_worker' => ['label' => 'Clustering Worker', 'status' => 'idle', 'last_run' => null],
            'serp_worker' => ['label' => 'SERP Worker', 'status' => 'idle', 'last_run' => null],
            'opportunity_worker' => ['label' => 'Opportunity Worker', 'status' => 'idle', 'last_run' => null],
        ];
        $workers = array_replace($default, $workers);

        echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">Worker Health</h2></div><div class="inside"><ul class="tmwseo-stat-list">';
        foreach ($workers as $worker) {
            $status = in_array($worker['status'], ['running', 'idle', 'failed'], true) ? $worker['status'] : 'idle';
            echo '<li><span>' . esc_html($worker['label']) . '</span><span class="tmwseo-worker-status"><span class="tmwseo-dot ' . esc_attr($status) . '"></span><strong>' . esc_html(ucfirst($status)) . '</strong></span></li>';
        }
        echo '</ul></div></div>';
    }

    private static function render_import_widget(array $rows): void {
        echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">Import Activity</h2></div><div class="inside">';
        if (empty($rows)) {
            echo '<p>No recent imports found.</p></div></div>';
            return;
        }

        echo '<table class="tmwseo-table"><thead><tr><th>Type</th><th>Source</th><th>Status</th><th>Started</th><th>Finished</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['type'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['source'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['status'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['started'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['finished'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function get_dashboard_stats(): array {
        global $wpdb;

        $keywords_table = self::resolve_table(['tmw_keywords']);
        $keyword_candidates_table = self::resolve_table(['tmw_keyword_candidates']);
        $keyword_clusters_table = self::resolve_table(['tmw_keyword_clusters']);
        $opportunities_table = self::resolve_table(['tmw_opportunities', 'tmw_seo_opportunities']);
        $jobs_table = self::resolve_table(['tmw_jobs', 'tmw_job_history', 'tmwseo_jobs']);
        $logs_table = self::resolve_table(['tmw_logs']);

        $raw_keywords = self::count_all($keywords_table);
        $candidates = $keyword_candidates_table ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$keyword_candidates_table} WHERE status IN ('new','candidate','pending')") : self::count_by_status($keywords_table, ['candidate', 'new']);
        $approved = $keyword_candidates_table ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$keyword_candidates_table} WHERE status = 'approved'") : self::count_by_status($keywords_table, ['approved']);
        $clusters = self::count_all($keyword_clusters_table);

        $total_opportunities = self::count_all($opportunities_table);
        $high_score_opportunities = self::count_by_score($opportunities_table, 80);
        $approved_opportunities = self::count_by_status($opportunities_table, ['approved']);

        $api_usage = self::api_usage_counts($logs_table);

        return [
            'keyword_pipeline' => [
                'raw_keywords' => $raw_keywords,
                'candidates' => $candidates,
                'approved' => $approved,
                'clusters' => $clusters,
            ],
            'opportunities' => [
                'total' => $total_opportunities,
                'high_score' => $high_score_opportunities,
                'approved' => $approved_opportunities,
            ],
            'worker_health' => self::worker_health($logs_table),
            'api_usage' => $api_usage,
            'import_activity' => self::import_activity($jobs_table),
            'cluster_quality' => self::cluster_quality($keyword_clusters_table),
        ];
    }

    private static function resolve_table(array $suffixes): ?string {
        global $wpdb;
        foreach ($suffixes as $suffix) {
            $table = $wpdb->prefix . $suffix;
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists === $table) {
                return $table;
            }
        }
        return null;
    }

    private static function has_column(?string $table, string $column): bool {
        global $wpdb;
        if (!$table) {
            return false;
        }

        $result = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
        return !empty($result);
    }

    private static function count_all(?string $table): int {
        global $wpdb;
        if (!$table) {
            return 0;
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    private static function count_by_status(?string $table, array $statuses): int {
        global $wpdb;
        if (!$table || !$statuses || !self::has_column($table, 'status')) {
            return 0;
        }

        $in = "'" . implode("','", array_map('esc_sql', $statuses)) . "'";
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status IN ({$in})");
    }

    private static function count_by_score(?string $table, int $min): int {
        global $wpdb;
        if (!$table) {
            return 0;
        }

        $column = self::has_column($table, 'opportunity_score') ? 'opportunity_score' : (self::has_column($table, 'score') ? 'score' : null);
        if (!$column) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} >= %d", $min));
    }

    private static function worker_health(?string $logs_table): array {
        global $wpdb;

        $workers = [
            'keyword_worker' => ['label' => 'Keyword Worker', 'contexts' => ['worker', 'keyword_worker']],
            'clustering_worker' => ['label' => 'Clustering Worker', 'contexts' => ['clustering_worker']],
            'serp_worker' => ['label' => 'SERP Worker', 'contexts' => ['serp_worker']],
            'opportunity_worker' => ['label' => 'Opportunity Worker', 'contexts' => ['opportunity_worker']],
        ];

        $now = time();
        $result = [];

        foreach ($workers as $key => $worker) {
            $last = null;
            $failed = false;

            if ($logs_table) {
                $contexts = "'" . implode("','", array_map('esc_sql', $worker['contexts'])) . "'";
                $last_time = $wpdb->get_var("SELECT MAX(time) FROM {$logs_table} WHERE context IN ({$contexts})");
                $last_error = $wpdb->get_var("SELECT MAX(time) FROM {$logs_table} WHERE context IN ({$contexts}) AND level='error'");
                $last = $last_time ? strtotime((string) $last_time) : null;
                $failed = $last_error && $last && strtotime((string) $last_error) >= $last;
            }

            $status = 'idle';
            if ($failed) {
                $status = 'failed';
            } elseif ($last && (($now - $last) <= 600)) {
                $status = 'running';
            }

            $result[$key] = [
                'label' => $worker['label'],
                'status' => $status,
                'last_run' => $last ? gmdate('Y-m-d H:i:s', $last) : null,
            ];
        }

        return $result;
    }

    private static function api_usage_counts(?string $logs_table): array {
        global $wpdb;

        $contexts = [
            'dataforseo' => ['dataforseo'],
            'gsc' => ['gsc', 'gsc_api'],
            'gkp' => ['keyword_planner', 'google_keyword_planner', 'gkp'],
        ];

        $today_start = gmdate('Y-m-d 00:00:00', current_time('timestamp', true));
        $month_start = gmdate('Y-m-01 00:00:00', current_time('timestamp', true));

        $out = [];
        foreach ($contexts as $key => $ctx) {
            $today = 0;
            $month = 0;
            if ($logs_table) {
                $in = "'" . implode("','", array_map('esc_sql', $ctx)) . "'";
                $today = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logs_table} WHERE context IN ({$in}) AND time >= %s", $today_start));
                $month = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logs_table} WHERE context IN ({$in}) AND time >= %s", $month_start));
            }
            $out[$key . '_today'] = $today;
            $out[$key . '_month'] = $month;
        }

        return $out;
    }

    private static function import_activity(?string $jobs_table): array {
        global $wpdb;

        if (!$jobs_table) {
            return [];
        }

        $type_col = self::has_column($jobs_table, 'job_type') ? 'job_type' : (self::has_column($jobs_table, 'type') ? 'type' : null);
        $status_col = self::has_column($jobs_table, 'status') ? 'status' : null;
        $started_col = self::has_column($jobs_table, 'started_at') ? 'started_at' : (self::has_column($jobs_table, 'created_at') ? 'created_at' : null);
        $finished_col = self::has_column($jobs_table, 'finished_at') ? 'finished_at' : (self::has_column($jobs_table, 'updated_at') ? 'updated_at' : null);
        $source_col = self::has_column($jobs_table, 'source') ? 'source' : (self::has_column($jobs_table, 'entity_type') ? 'entity_type' : null);

        if (!$type_col || !$status_col || !$started_col) {
            return [];
        }

        $source_select = $source_col ? $source_col : "''";
        $finished_select = $finished_col ? $finished_col : 'NULL';
        $sql = "SELECT {$type_col} AS type, {$source_select} AS source, {$status_col} AS status, {$started_col} AS started, {$finished_select} AS finished
                FROM {$jobs_table}
                WHERE {$type_col} LIKE 'import%'
                ORDER BY {$started_col} DESC
                LIMIT 5";

        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    private static function cluster_quality(?string $clusters_table): array {
        global $wpdb;
        if (!$clusters_table) {
            return ['average_score' => 0, 'grade_a' => 0, 'grade_b' => 0, 'grade_cd' => 0];
        }

        $score_col = self::has_column($clusters_table, 'opportunity') ? 'opportunity' : (self::has_column($clusters_table, 'score') ? 'score' : null);
        if (!$score_col) {
            return ['average_score' => 0, 'grade_a' => 0, 'grade_b' => 0, 'grade_cd' => 0];
        }

        $average = (float) $wpdb->get_var("SELECT COALESCE(AVG({$score_col}),0) FROM {$clusters_table}");
        $grade_a = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$clusters_table} WHERE {$score_col} >= 80");
        $grade_b = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$clusters_table} WHERE {$score_col} >= 60 AND {$score_col} < 80");
        $grade_cd = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$clusters_table} WHERE {$score_col} < 60");

        return [
            'average_score' => $average,
            'grade_a' => $grade_a,
            'grade_b' => $grade_b,
            'grade_cd' => $grade_cd,
        ];
    }
}
