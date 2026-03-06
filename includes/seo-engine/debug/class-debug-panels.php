<?php
namespace TMWSEO\Engine\Debug;

if (!defined('ABSPATH')) { exit; }

class DebugPanels {
    private const TEST_REPORT_OPTION = 'tmwseo_debug_last_test_report';

    public static function render_engine_status(): void {
        $status = [
            'DataForSEO status' => \TMWSEO\Engine\Services\DataForSEO::is_configured() ? 'Ready' : 'Missing credentials',
            'keyword intelligence status' => self::meta_count('tmw_keyword_pack') > 0 ? 'Ready for Review' : 'Needs Attention',
            'clustering status' => self::table_count('tmw_keyword_clusters') > 0 ? 'Ready for Review' : 'Needs Attention',
            'opportunities status' => self::table_count('tmw_seo_opportunities') > 0 ? 'Ready for Review' : 'Needs Attention',
            'topic suggestions status' => self::meta_count('tmw_topic_cluster') > 0 ? 'Ready for Review' : 'Needs Attention',
            'debug mode status' => DebugLogger::is_enabled() ? 'On' : 'Off',
        ];

        echo '<h2>Engine Status</h2><table class="widefat striped"><tbody>';
        foreach ($status as $label => $value) {
            echo '<tr><th style="width:260px;">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    public static function maybe_run_testing_mode(): ?array {
        if (!isset($_POST['tmw_debug_run_tests'])) {
            return null;
        }

        if (!current_user_can('manage_options')) {
            return [
                'error' => 'Insufficient permissions to run debug testing mode.',
            ];
        }

        check_admin_referer('tmw_debug_run_tests', 'tmw_debug_run_tests_nonce');

        $started_at = microtime(true);
        $memory_start = memory_get_usage(true);

        $keyword_meta_count = self::meta_count('tmw_keyword_pack');
        $cluster_count = self::table_count('tmw_keyword_clusters');
        $opportunity_count = self::table_count('tmw_seo_opportunities');
        $api_calls_before = count(DebugAPIMonitor::get_recent_requests(20));

        $steps = [];

        $steps[] = [
            'name' => 'simulate keyword pipelines',
            'status' => $keyword_meta_count > 0 ? 'ok' : 'warning',
            'details' => sprintf('Detected %d posts with keyword packs for simulation.', $keyword_meta_count),
        ];

        $steps[] = [
            'name' => 'run clustering test',
            'status' => $cluster_count > 0 ? 'ok' : 'warning',
            'details' => sprintf('Validated %d stored keyword clusters.', $cluster_count),
        ];

        $steps[] = [
            'name' => 'run opportunity detection',
            'status' => $opportunity_count > 0 ? 'ok' : 'warning',
            'details' => sprintf('Detected %d opportunity rows ready for evaluation.', $opportunity_count),
        ];

        $generated_suggestions = self::build_test_suggestions($keyword_meta_count, $cluster_count, $opportunity_count);

        $steps[] = [
            'name' => 'generate test suggestions',
            'status' => count($generated_suggestions) > 0 ? 'ok' : 'warning',
            'details' => sprintf('Generated %d debug suggestions.', count($generated_suggestions)),
        ];

        $runtime_ms = (microtime(true) - $started_at) * 1000;
        DebugAPIMonitor::record_request('debug/testing-mode/simulation', 200, $runtime_ms, count($generated_suggestions));

        $api_calls_after = count(DebugAPIMonitor::get_recent_requests(20));
        $memory_end = memory_get_usage(true);

        $report = [
            'ran_at' => current_time('mysql'),
            'steps' => $steps,
            'suggestions' => $generated_suggestions,
            'metrics' => [
                'execution_time_ms' => round((microtime(true) - $started_at) * 1000, 2),
                'memory_usage_mb' => round(max(0, $memory_end - $memory_start) / 1048576, 2),
                'suggestions_created' => count($generated_suggestions),
                'api_calls' => max(0, $api_calls_after - $api_calls_before),
            ],
        ];

        update_option(self::TEST_REPORT_OPTION, $report, false);
        DebugLogger::log_test_mode($report);

        return $report;
    }

    public static function render_testing_dashboard(?array $report = null): void {
        if (!is_array($report)) {
            $stored = get_option(self::TEST_REPORT_OPTION, []);
            $report = is_array($stored) ? $stored : [];
        }

        echo '<h2>Debug Testing Mode</h2>';
        echo '<p>Run a full testing simulation from the Debug Dashboard.</p>';

        echo '<form method="post" style="margin:12px 0 18px;">';
        wp_nonce_field('tmw_debug_run_tests', 'tmw_debug_run_tests_nonce');
        submit_button('Run Testing Mode', 'primary', 'tmw_debug_run_tests', false);
        echo '</form>';

        if (isset($report['error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html((string) $report['error']) . '</p></div>';
            return;
        }

        if (empty($report)) {
            echo '<p>No test run captured yet.</p>';
            return;
        }

        $ran_at = isset($report['ran_at']) ? (string) $report['ran_at'] : '';
        echo '<p><strong>Last run:</strong> ' . esc_html($ran_at === '' ? 'n/a' : $ran_at) . '</p>';

        echo '<h3>Debug Mode Features</h3>';
        echo '<table class="widefat striped"><thead><tr><th style="width:260px;">Feature</th><th style="width:120px;">Result</th><th>Details</th></tr></thead><tbody>';

        $steps = isset($report['steps']) && is_array($report['steps']) ? $report['steps'] : [];
        foreach ($steps as $step) {
            $name = isset($step['name']) ? (string) $step['name'] : '';
            $status = isset($step['status']) ? (string) $step['status'] : 'warning';
            $details = isset($step['details']) ? (string) $step['details'] : '';
            echo '<tr>';
            echo '<td>' . esc_html(ucfirst($name)) . '</td>';
            echo '<td>' . esc_html(strtoupper($status)) . '</td>';
            echo '<td>' . esc_html($details) . '</td>';
            echo '</tr>';
        }

        if (empty($steps)) {
            echo '<tr><td colspan="3">No feature checks completed.</td></tr>';
        }

        echo '</tbody></table>';

        echo '<h3>Metrics</h3>';
        $metrics = isset($report['metrics']) && is_array($report['metrics']) ? $report['metrics'] : [];
        echo '<table class="widefat striped"><tbody>';
        echo '<tr><th style="width:260px;">Execution time</th><td>' . esc_html((string) ($metrics['execution_time_ms'] ?? 0)) . ' ms</td></tr>';
        echo '<tr><th>Memory usage</th><td>' . esc_html((string) ($metrics['memory_usage_mb'] ?? 0)) . ' MB</td></tr>';
        echo '<tr><th>Number of suggestions created</th><td>' . esc_html((string) ($metrics['suggestions_created'] ?? 0)) . '</td></tr>';
        echo '<tr><th>API calls</th><td>' . esc_html((string) ($metrics['api_calls'] ?? 0)) . '</td></tr>';
        echo '</tbody></table>';

        echo '<h3>Generated Test Suggestions</h3>';
        $suggestions = isset($report['suggestions']) && is_array($report['suggestions']) ? $report['suggestions'] : [];
        echo '<pre style="background:#fff;border:1px solid #dcdcde;padding:10px;max-height:220px;overflow:auto;">' . esc_html(wp_json_encode($suggestions, JSON_PRETTY_PRINT)) . '</pre>';
    }

    public static function render_suggestion_activity(int $limit = 50): void {
        $rows = \TMWSEO\Engine\Logs::latest($limit);

        echo '<h2>Suggestion Activity Log</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th style="width:170px;">Time</th><th style="width:120px;">Level</th><th style="width:160px;">Context</th><th>Message</th><th>Data</th>';
        echo '</tr></thead><tbody>';

        $shown = 0;
        foreach ($rows as $row) {
            $context = isset($row['context']) ? (string) $row['context'] : '';
            $message = isset($row['message']) ? (string) $row['message'] : '';

            if ($context !== 'suggestions' && stripos($message, 'Suggestion ') === false) {
                continue;
            }

            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['time'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['level'] ?? '')) . '</td>';
            echo '<td>' . esc_html($context) . '</td>';
            echo '<td>' . esc_html($message) . '</td>';
            echo '<td><code style="white-space:pre-wrap;">' . esc_html((string) ($row['data'] ?? '')) . '</code></td>';
            echo '</tr>';

            $shown++;
        }

        if ($shown === 0) {
            echo '<tr><td colspan="5">No suggestion lifecycle activity logged yet.</td></tr>';
        }

        echo '</tbody></table>';
    }

    public static function render_post_inspector(int $post_id): void {
        $sections = [
            'keyword pack' => get_post_meta($post_id, 'tmw_keyword_pack', true),
            'filters applied' => get_post_meta($post_id, 'tmw_keyword_filters_applied', true),
            'rejected keywords' => get_post_meta($post_id, 'tmw_rejected_keywords', true),
            'clusters' => get_post_meta($post_id, 'tmw_keyword_clusters', true),
            'opportunities' => get_post_meta($post_id, 'tmw_opportunities', true),
            'internal links' => get_post_meta($post_id, 'tmw_internal_links', true),
            'model similarity' => get_post_meta($post_id, 'tmw_model_similarity', true),
            'topic suggestions' => get_post_meta($post_id, 'tmw_topic_cluster', true),
            'recent API calls' => DebugAPIMonitor::get_recent_requests(10),
            'errors' => get_post_meta($post_id, 'tmw_engine_errors', true),
        ];

        echo '<h2>Post Inspector</h2>';
        foreach ($sections as $title => $data) {
            echo '<h3 style="margin-top:16px;">' . esc_html(ucwords($title)) . '</h3>';
            echo '<pre style="background:#fff;border:1px solid #dcdcde;padding:10px;max-height:200px;overflow:auto;">' . esc_html(wp_json_encode($data, JSON_PRETTY_PRINT)) . '</pre>';
        }
    }

    private static function build_test_suggestions(int $keyword_meta_count, int $cluster_count, int $opportunity_count): array {
        return [
            [
                'type' => 'keyword-pipeline',
                'priority' => $keyword_meta_count > 0 ? 'medium' : 'high',
                'suggestion' => $keyword_meta_count > 0
                    ? 'Keyword packs are present. Validate long-tail coverage for the next refresh cycle.'
                    : 'No keyword packs detected. Seed at least one keyword pack to validate pipeline quality.',
            ],
            [
                'type' => 'clustering',
                'priority' => $cluster_count > 10 ? 'low' : 'high',
                'suggestion' => $cluster_count > 0
                    ? 'Run a semantic overlap review on existing clusters to remove duplicates.'
                    : 'No clusters detected. Trigger the clustering workflow for a baseline test.',
            ],
            [
                'type' => 'opportunities',
                'priority' => $opportunity_count > 0 ? 'medium' : 'high',
                'suggestion' => $opportunity_count > 0
                    ? 'Opportunity records exist. Prioritize by score and publish quick-win updates.'
                    : 'No opportunities found. Refresh rankings and rerun detection to populate opportunities.',
            ],
        ];
    }

    private static function meta_count(string $meta_key): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key));
    }

    private static function table_count(string $table_suffix): int {
        global $wpdb;
        $table_name = $wpdb->prefix . $table_suffix;
        $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        if ($exists !== $table_name) {
            return 0;
        }
        return (int) $wpdb->get_var("SELECT COUNT(1) FROM {$table_name}");
    }
}
