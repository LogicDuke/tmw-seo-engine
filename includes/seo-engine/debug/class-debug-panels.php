<?php
namespace TMWSEO\Engine\Debug;

use TMWSEO\Engine\AutopilotMigrationRegistry;
use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Services\TrustPolicy;

if (!defined('ABSPATH')) { exit; }

class DebugPanels {
    private const TEST_REPORT_OPTION = 'tmwseo_debug_last_test_report';

    public static function render_engine_status(): void {
        $policy = TrustPolicy::flags();
        $publish_autopilot_status = \TMWSEO\Engine\Content\ContentEngine::get_publish_autopilot_hook_status();

        $migration_counts = AutopilotMigrationRegistry::status_counts();
        $preview_apply_count = self::meta_count('_tmwseo_preview_applied_at');
        $preview_apply_preset_count = self::meta_count('_tmwseo_preview_apply_preset_at');
        $review_recommendation_count = self::meta_count('_tmwseo_review_recommended_preset');

        $status = [
            'DataForSEO status' => DataForSEO::is_configured() ? 'Ready' : 'Missing credentials',
            'keyword intelligence status' => self::meta_count('tmw_keyword_pack') > 0 ? 'Ready for Review' : 'Needs Attention',
            'clustering status' => self::table_count('tmw_keyword_clusters') > 0 ? 'Ready for Review' : 'Needs Attention',
            'opportunities status' => self::table_count('tmw_seo_opportunities') > 0 ? 'Ready for Review' : 'Needs Attention',
            'topic suggestions status' => self::meta_count('tmw_topic_cluster') > 0 ? 'Ready for Review' : 'Needs Attention',
            'debug mode status' => DebugLogger::is_enabled() ? 'On' : 'Off',
            'manual_only' => TrustPolicy::bool_text(!empty($policy['manual_only'])),
            'auto_publish' => TrustPolicy::bool_text(!empty($policy['auto_publish'])),
            'auto_link_insertion' => TrustPolicy::bool_text(!empty($policy['auto_link_insertion'])),
            'cron_enabled' => TrustPolicy::bool_text(!empty($policy['cron_enabled'])),
            'legacy publish autopilot hooks' => (string) ($publish_autopilot_status['legacy_publish_autopilot_hooks'] ?? 'OFF'),
            'publish autopilot hard fence' => (string) ($publish_autopilot_status['hard_fence'] ?? 'ENABLED'),
            'publish transition hook registered' => (string) ($publish_autopilot_status['hook_registered'] ?? 'no'),
            'phase c migrated safely (legacy paths)' => (string) ($migration_counts['migrated_safely'] ?? 0),
            'phase c still fenced (legacy paths)' => (string) ($migration_counts['still_fenced'] ?? 0),
            'phase c disallowed (legacy paths)' => (string) ($migration_counts['phase_c_disallowed'] ?? 0),
            'manual draft preview applies' => (string) $preview_apply_count,
            'manual draft preset applies' => (string) $preview_apply_preset_count,
            'draft review recommendations generated' => (string) $review_recommendation_count,
        ];

        echo '<h2>Engine Status</h2><table class="widefat striped"><tbody>';
        foreach ($status as $label => $value) {
            echo '<tr><th style="width:260px;">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        echo '</tbody></table>';

        $paths = AutopilotMigrationRegistry::all_paths();
        echo '<h3 style="margin-top:16px;">Phase C Legacy Autopilot Migration Registry</h3>';
        echo '<p>Classification and migration state for legacy automation paths. Safe paths are operator-triggered only (including assisted draft-only metadata enrichment, preview-only draft content assist, manual preview apply to drafts with destination-aware apply presets, and advisory-only explicit-draft review scoring recommendations); live mutation paths remain fenced/disallowed in Phase C.</p>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th style="width:220px;">Path ID</th><th style="width:220px;">Bucket</th><th style="width:160px;">Status</th><th style="width:250px;">Operator Entry Point</th><th>Notes</th>';
        echo '</tr></thead><tbody>';
        foreach ($paths as $path) {
            echo '<tr>';
            echo '<td><code>' . esc_html((string) ($path['id'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html((string) ($path['bucket'] ?? '')) . '</td>';
            $raw_status = (string) ($path['status'] ?? '');
            echo '<td>' . esc_html(AutopilotMigrationRegistry::status_label($raw_status)) . '</td>';
            echo '<td>' . esc_html((string) ($path['entry_point'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($path['notes'] ?? '')) . '</td>';
            echo '</tr>';
        }
        if (empty($paths)) {
            echo '<tr><td colspan="5">No legacy autopilot paths registered.</td></tr>';
        }
        echo '</tbody></table>';
    }

    public static function maybe_run_testing_mode(): ?array {
        if (!isset($_POST['tmw_debug_run_tests'])) {
            return null;
        }

        if (!current_user_can('manage_options')) {
            return ['error' => 'Insufficient permissions to run full validation.'];
        }

        check_admin_referer('tmw_debug_run_tests', 'tmw_debug_run_tests_nonce');

        $started_at = microtime(true);
        $steps = self::build_validation_steps();
        $problem_count = 0;
        foreach ($steps as $step) {
            if (($step['result'] ?? 'CHECK') !== 'PASS') {
                $problem_count++;
            }
        }

        $report = [
            'ran_at' => current_time('mysql'),
            'steps' => $steps,
            'metrics' => [
                'execution_time_ms' => round((microtime(true) - $started_at) * 1000, 2),
                'problems_found' => $problem_count,
                'checks_ran' => count($steps),
                'mutation_mode' => 'read-safe only',
            ],
        ];

        update_option(self::TEST_REPORT_OPTION, $report, false);
        DebugLogger::log_test_mode($report);

        return $report;
    }

    /** @return array<int,array<string,string>> */
    private static function build_validation_steps(): array {
        global $wpdb;

        $keyword_pack_count = self::meta_count('tmw_keyword_pack');
        $legacy_pack_count = self::meta_count('_tmwseo_keyword_pack');
        $cluster_count = self::table_count('tmw_keyword_clusters');
        $opportunity_count = self::table_count('tmw_seo_opportunities');
        $generated_table = $wpdb->prefix . 'tmw_generated_pages';

        $generated_drafts_count = 0;
        $generated_non_draft_count = 0;
        $generated_noindex_count = 0;

        $generated_table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $generated_table)) === $generated_table;
        if ($generated_table_exists) {
            $generated_drafts_count = (int) $wpdb->get_var("SELECT COUNT(1) FROM {$generated_table} g INNER JOIN {$wpdb->posts} p ON p.ID = g.page_id WHERE p.post_status = 'draft'");
            $generated_non_draft_count = (int) $wpdb->get_var("SELECT COUNT(1) FROM {$generated_table} g INNER JOIN {$wpdb->posts} p ON p.ID = g.page_id WHERE p.post_status <> 'draft'");
            $generated_noindex_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(1) FROM {$generated_table} g INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = g.page_id WHERE pm.meta_key = %s",
                'rank_math_robots'
            ));
        }

        $steps = [];
        $steps[] = self::step_result(
            '1. DataForSEO connectivity',
            DataForSEO::is_configured(),
            'Credentials are configured for DataForSEO API access.',
            'Missing DataForSEO credentials in Settings.',
            'Add DataForSEO login/password in Settings and run validation again.'
        );

        $steps[] = self::step_result(
            '2. seed collection',
            $keyword_pack_count > 0 || $legacy_pack_count > 0,
            sprintf('Detected %d unified packs and %d legacy packs.', $keyword_pack_count, $legacy_pack_count),
            'No keyword pack metadata detected.',
            'Run a keyword refresh cycle from Keywords to seed data.'
        );

        $steps[] = self::step_result(
            '3. keyword expansion',
            $keyword_pack_count > 0,
            sprintf('Unified keyword pack count: %d', $keyword_pack_count),
            'Expansion output missing in unified pack metadata.',
            'Run Unified Keyword Workflow and verify tmw_keyword_pack writes.'
        );

        $steps[] = self::step_result(
            '4. filtering',
            self::meta_count('tmw_keyword_filters_applied') > 0,
            'Keyword filter metadata exists on one or more posts.',
            'No filtering metadata found.',
            'Ensure keyword filtering is executed and stored as tmw_keyword_filters_applied.'
        );

        $steps[] = self::step_result(
            '5. scoring',
            self::table_count('tmw_keyword_candidates') > 0,
            'Keyword candidate table contains scored/queued rows.',
            'Keyword candidate rows missing.',
            'Run keyword cycle and verify candidate ingestion/scoring tables.'
        );

        $steps[] = self::step_result(
            '6. clustering',
            $cluster_count > 0,
            sprintf('Cluster table has %d rows.', $cluster_count),
            'No clusters found.',
            'Generate clusters from the Keywords workflow.'
        );

        $steps[] = self::step_result(
            '7. storage',
            $generated_table_exists,
            $generated_table_exists ? 'Generated pages table exists.' : 'Generated pages table missing.',
            $generated_table_exists ? 'None' : 'Required storage table tmw_generated_pages is missing.',
            $generated_table_exists ? 'None' : 'Run database migration to create missing tables.'
        );

        $steps[] = self::step_result(
            '8. opportunity creation',
            $opportunity_count > 0,
            sprintf('Opportunity table has %d rows.', $opportunity_count),
            'No opportunities found for review.',
            'Run an opportunities scan from the Opportunities page.'
        );

        $steps[] = self::step_result(
            '9. draft generation guardrails',
            $generated_non_draft_count === 0,
            sprintf('Generated drafts: %d, non-draft generated pages: %d', $generated_drafts_count, $generated_non_draft_count),
            $generated_non_draft_count === 0 ? 'None' : 'Some generated pages are not in draft status.',
            'Keep generated pages draft-only and manually review before publishing.'
        );

        $steps[] = self::step_result(
            '10. noindex enforcement',
            $generated_non_draft_count === 0 && ($generated_noindex_count >= $generated_drafts_count),
            sprintf('Rank Math noindex records: %d, generated drafts: %d', $generated_noindex_count, $generated_drafts_count),
            'One or more generated drafts may be missing Rank Math noindex metadata.',
            'Re-apply noindex guardrail before any human publication decision.'
        );

        return $steps;
    }

    /** @return array<string,string> */
    private static function step_result(string $step, bool $ok, string $notes, string $problem, string $fix): array {
        return [
            'step' => $step,
            'result' => $ok ? 'PASS' : 'CHECK',
            'notes' => $notes,
            'detected_problems' => $ok ? 'None' : $problem,
            'recommended_fix' => $ok ? 'No action required.' : $fix,
        ];
    }

    public static function render_testing_dashboard(?array $report = null): void {
        if (!is_array($report)) {
            $stored = get_option(self::TEST_REPORT_OPTION, []);
            $report = is_array($stored) ? $stored : [];
        }

        echo '<h2>Debug Dashboard — Pipeline Validator</h2>';
        echo '<p>Run a read-safe full validation of the SEO pipeline. This does not publish pages and does not mutate live content.</p>';

        echo '<form method="post" style="margin:12px 0 18px;">';
        wp_nonce_field('tmw_debug_run_tests', 'tmw_debug_run_tests_nonce');
        submit_button('Run Full Validation', 'primary', 'tmw_debug_run_tests', false);
        echo '</form>';

        if (isset($report['error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html((string) $report['error']) . '</p></div>';
            return;
        }

        if (empty($report)) {
            echo '<p>No validation report yet.</p>';
            return;
        }

        $ran_at = isset($report['ran_at']) ? (string) $report['ran_at'] : '';
        echo '<p><strong>Last run:</strong> ' . esc_html($ran_at === '' ? 'n/a' : $ran_at) . '</p>';

        $metrics = isset($report['metrics']) && is_array($report['metrics']) ? $report['metrics'] : [];
        echo '<p><strong>Execution time:</strong> ' . esc_html((string)($metrics['execution_time_ms'] ?? 'n/a')) . 'ms';
        echo ' &nbsp;|&nbsp; <strong>Problems found:</strong> ' . esc_html((string)($metrics['problems_found'] ?? 0));
        echo ' &nbsp;|&nbsp; <strong>Mode:</strong> ' . esc_html((string)($metrics['mutation_mode'] ?? 'read-safe only'));
        echo '</p>';

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th style="width:220px;">Step</th><th style="width:110px;">Result</th><th>Notes</th><th>Detected Problems</th><th>Recommended Fix</th>';
        echo '</tr></thead><tbody>';

        $steps = isset($report['steps']) && is_array($report['steps']) ? $report['steps'] : [];
        foreach ($steps as $step) {
            $result = (string) ($step['result'] ?? 'CHECK');
            $badge = $result === 'PASS' ? '#15803d' : '#b45309';

            echo '<tr>';
            echo '<td>' . esc_html((string) ($step['step'] ?? '')) . '</td>';
            echo '<td><span style="display:inline-block;padding:3px 8px;border-radius:999px;color:#fff;background:' . esc_attr($badge) . ';font-weight:600;">' . esc_html($result) . '</span></td>';
            echo '<td>' . esc_html((string) ($step['notes'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($step['detected_problems'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($step['recommended_fix'] ?? '')) . '</td>';
            echo '</tr>';
        }

        if (empty($steps)) {
            echo '<tr><td colspan="5">No validation steps available.</td></tr>';
        }

        echo '</tbody></table>';
    }

    public static function render_suggestion_activity(int $limit = 80): void {
        $rows = \TMWSEO\Engine\Logs::latest($limit);

        echo '<h2>Suggestion Lifecycle Logs</h2>';
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

    public static function render_intelligence_activity(int $limit = 80): void {
        $rows = \TMWSEO\Engine\Logs::latest($limit);

        echo '<h2>Intelligence Module Logs</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th style="width:170px;">Time</th><th style="width:160px;">Module</th><th>Summary</th><th>Data</th>';
        echo '</tr></thead><tbody>';

        $shown = 0;
        foreach ($rows as $row) {
            $message = (string) ($row['message'] ?? '');
            if (strpos($message, '[TMW-TOPICAL]') === false && strpos($message, '[TMW-SERP]') === false && strpos($message, '[TMW-RANK]') === false && strpos($message, '[TMW-GAP]') === false && strpos($message, '[TMW-BRIEF]') === false) {
                continue;
            }

            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['time'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['context'] ?? 'intelligence')) . '</td>';
            echo '<td>' . esc_html($message) . '</td>';
            echo '<td><code style="white-space:pre-wrap;">' . esc_html((string) ($row['data'] ?? '')) . '</code></td>';
            echo '</tr>';
            $shown++;
        }

        if ($shown === 0) {
            echo '<tr><td colspan="4">No intelligence module logs yet.</td></tr>';
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
