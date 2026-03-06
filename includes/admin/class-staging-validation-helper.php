<?php
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Admin;
use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Intelligence\IntelligenceStorage;
use TMWSEO\Engine\Suggestions\SuggestionEngine;
use TMWSEO\Engine\Services\Settings;

if (!defined('ABSPATH')) { exit; }

class Staging_Validation_Helper {
    private const PAGE_SLUG = 'tmwseo-staging-validation-helper';
    private const NONCE_SEED = 'tmwseo_seed_staging_test_data';
    private const NONCE_CLEAR = 'tmwseo_clear_staging_test_data';
    private const TEST_MARKER = '[TEST DATA]';

    public static function init(): void {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', [__CLASS__, 'register_menu'], 99);
        add_action('admin_post_tmwseo_seed_staging_test_data', [__CLASS__, 'handle_seed_test_data']);
        add_action('admin_post_tmwseo_clear_staging_test_data', [__CLASS__, 'handle_clear_test_data']);
    }

    public static function register_menu(): void {
        add_submenu_page(
            Admin::MENU_SLUG,
            __('Staging Validation Helper', 'tmwseo'),
            __('↳ Staging Validation Helper', 'tmwseo'),
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'tmwseo'));
        }

        $notice = isset($_GET['tmw_notice']) ? sanitize_key((string) $_GET['tmw_notice']) : '';
        $environment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('TMW SEO Engine — Staging Validation Helper Pack', 'tmwseo') . '</h1>';
        echo '<p><strong>' . esc_html__('Staging-only helper.', 'tmwseo') . '</strong> ' . esc_html__('Never auto-publishes and never auto-mutates live content.', 'tmwseo') . '</p>';
        echo '<p>' . esc_html__('Current environment:', 'tmwseo') . ' <code>' . esc_html($environment) . '</code></p>';

        self::render_notice($notice);

        self::render_seed_tools();
        self::render_schema_diagnostics();
        self::render_safety_diagnostics();
        self::render_validation_export();

        echo '</div>';
    }

    private static function render_notice(string $notice): void {
        $messages = [
            'seeded' => ['success', __('TEST DATA fixtures were created successfully.', 'tmwseo')],
            'cleared' => ['success', __('Only TEST DATA fixtures were removed successfully.', 'tmwseo')],
            'blocked_env' => ['warning', __('Mutation actions are blocked outside staging/development environments.', 'tmwseo')],
            'invalid' => ['error', __('Request validation failed.', 'tmwseo')],
        ];

        if (!isset($messages[$notice])) {
            return;
        }

        $type = $messages[$notice][0];
        $text = $messages[$notice][1];
        echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html($text) . '</p></div>';
    }

    private static function render_seed_tools(): void {
        $seeded_counts = self::get_seeded_counts();
        $can_mutate = self::can_run_mutation_actions();

        echo '<hr />';
        echo '<h2>' . esc_html__('1) Staging Test Data Seeder', 'tmwseo') . '</h2>';
        echo '<p>' . esc_html__('Creates and clears TEST DATA fixtures only. No published post writes are performed.', 'tmwseo') . '</p>';

        if (!$can_mutate) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Seeding and clear actions are disabled in production environments.', 'tmwseo') . '</p></div>';
        }

        echo '<table class="widefat striped" style="max-width:900px">';
        echo '<thead><tr><th>' . esc_html__('Fixture Area', 'tmwseo') . '</th><th>' . esc_html__('Seeded Rows', 'tmwseo') . '</th></tr></thead><tbody>';
        foreach ($seeded_counts as $label => $count) {
            echo '<tr><td>' . esc_html($label) . '</td><td>' . esc_html((string) $count) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<div style="margin-top:14px;display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;">';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="tmwseo_seed_staging_test_data" />';
        wp_nonce_field(self::NONCE_SEED);
        echo '<p><label><input type="checkbox" name="create_draft_fixtures" value="1" /> ' . esc_html__('Also create draft-only fixture posts (never publish).', 'tmwseo') . '</label></p>';
        submit_button(__('Seed TEST DATA fixtures', 'tmwseo'), 'primary', 'submit', false, ['disabled' => $can_mutate ? false : true]);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="tmwseo_clear_staging_test_data" />';
        wp_nonce_field(self::NONCE_CLEAR);
        submit_button(__('Clear TEST DATA fixtures only', 'tmwseo'), 'secondary', 'submit', false, ['disabled' => $can_mutate ? false : true]);
        echo '</form>';

        echo '</div>';
    }

    public static function handle_seed_test_data(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'tmwseo'));
        }

        if (!check_admin_referer(self::NONCE_SEED)) {
            self::redirect_with_notice('invalid');
        }

        if (!self::can_run_mutation_actions()) {
            self::redirect_with_notice('blocked_env');
        }

        global $wpdb;
        $suggestion_engine = new SuggestionEngine();
        $now = current_time('mysql');

        $fixture_suggestions = [
            [
                'type' => 'competitor_gap',
                'title' => self::TEST_MARKER . ' Competitor Gap: cam model studio seo',
                'description' => self::TEST_MARKER . ' Staging fixture for competitor gap validation.',
                'source_engine' => 'competitor_gap_ai',
                'priority_score' => 72,
                'estimated_traffic' => 640,
                'difficulty' => 44,
                'suggested_action' => self::TEST_MARKER . ' Create a draft landing page targeting this content gap.',
            ],
            [
                'type' => 'ranking_probability',
                'title' => self::TEST_MARKER . ' Ranking Probability: private stream rooms',
                'description' => self::TEST_MARKER . ' Staging fixture for ranking probability diagnostics.',
                'source_engine' => 'ranking_probability_prediction',
                'priority_score' => 61,
                'estimated_traffic' => 430,
                'difficulty' => 37,
                'suggested_action' => self::TEST_MARKER . ' Validate scoring thresholds in manual QA.',
            ],
            [
                'type' => 'serp_weakness',
                'title' => self::TEST_MARKER . ' SERP Weakness: best live cam categories',
                'description' => self::TEST_MARKER . ' Staging fixture for SERP weakness review.',
                'source_engine' => 'serp_weakness_detection',
                'priority_score' => 54,
                'estimated_traffic' => 380,
                'difficulty' => 32,
                'suggested_action' => self::TEST_MARKER . ' Draft improved page structure and FAQ coverage.',
            ],
            [
                'type' => 'authority_cluster',
                'title' => self::TEST_MARKER . ' Authority Cluster: webcam strategy cluster',
                'description' => self::TEST_MARKER . ' Staging fixture for topical authority signals.',
                'source_engine' => 'topical_authority_scoring',
                'priority_score' => 67,
                'estimated_traffic' => 520,
                'difficulty' => 35,
                'suggested_action' => self::TEST_MARKER . ' Review cluster authority output before content planning.',
            ],
            [
                'type' => 'content_brief',
                'title' => self::TEST_MARKER . ' Content Brief: safe cam tips',
                'description' => self::TEST_MARKER . ' Staging fixture for content brief workflow.',
                'source_engine' => 'content_brief_generator',
                'priority_score' => 48,
                'estimated_traffic' => 260,
                'difficulty' => 25,
                'suggested_action' => self::TEST_MARKER . ' Generate draft outline and validate brief rendering.',
            ],
        ];

        foreach ($fixture_suggestions as $row) {
            $suggestion_engine->createSuggestion($row);
        }

        $brief_table = IntelligenceStorage::table_content_briefs();
        $wpdb->insert(
            $brief_table,
            [
                'primary_keyword' => self::TEST_MARKER . ' stage brief keyword',
                'cluster_key' => self::TEST_MARKER . ' stage-cluster-key',
                'brief_type' => 'test_data_fixture',
                'brief_json' => wp_json_encode([
                    'marker' => self::TEST_MARKER,
                    'intent' => 'informational',
                    'outline' => ['Intro', 'Key takeaways', 'FAQ'],
                ]),
                'status' => 'ready',
                'created_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        $competitor_table = IntelligenceStorage::table_competitors();
        $wpdb->insert(
            $competitor_table,
            [
                'domain' => 'test-data-fixture.example',
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%d', '%s', '%s']
        );

        $ranking_table = IntelligenceStorage::table_ranking_probability();
        $wpdb->insert(
            $ranking_table,
            [
                'keyword' => self::TEST_MARKER . ' ranking fixture keyword',
                'inputs_json' => wp_json_encode([
                    'marker' => self::TEST_MARKER,
                    'domain_authority' => 42,
                    'content_depth' => 71,
                ]),
                'ranking_probability' => 64.50,
                'ranking_tier' => 'medium',
                'created_at' => $now,
            ],
            ['%s', '%s', '%f', '%s', '%s']
        );

        $serp_table = IntelligenceStorage::table_serp_analysis();
        $wpdb->insert(
            $serp_table,
            [
                'keyword' => self::TEST_MARKER . ' serp fixture keyword',
                'serp_weakness_score' => 3.80,
                'reason' => self::TEST_MARKER . ' Competing pages have thin content coverage.',
                'signals_json' => wp_json_encode([
                    'marker' => self::TEST_MARKER,
                    'word_count_median' => 620,
                    'faq_coverage' => 'low',
                ]),
                'created_at' => $now,
            ],
            ['%s', '%f', '%s', '%s', '%s']
        );

        if (!empty($_POST['create_draft_fixtures'])) {
            $draft_id = wp_insert_post([
                'post_type' => 'post',
                'post_status' => 'draft',
                'post_title' => self::TEST_MARKER . ' Draft Fixture — Manual QA Only',
                'post_content' => self::TEST_MARKER . ' Draft-only fixture created for staging validation checklist.',
                'post_author' => get_current_user_id() ?: 1,
            ], true);

            if (!is_wp_error($draft_id) && (int) $draft_id > 0) {
                update_post_meta((int) $draft_id, '_tmwseo_staging_fixture', 1);
            }
        }

        Logs::info('staging-helper', '[TMW-VALIDATION] Seeded staging TEST DATA fixtures', [
            'actor' => get_current_user_id(),
            'environment' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown',
        ]);

        self::redirect_with_notice('seeded');
    }

    public static function handle_clear_test_data(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'tmwseo'));
        }

        if (!check_admin_referer(self::NONCE_CLEAR)) {
            self::redirect_with_notice('invalid');
        }

        if (!self::can_run_mutation_actions()) {
            self::redirect_with_notice('blocked_env');
        }

        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . SuggestionEngine::table_name() . ' WHERE title LIKE %s OR description LIKE %s OR suggested_action LIKE %s',
            '%' . $wpdb->esc_like(self::TEST_MARKER) . '%',
            '%' . $wpdb->esc_like(self::TEST_MARKER) . '%',
            '%' . $wpdb->esc_like(self::TEST_MARKER) . '%'
        ));

        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . IntelligenceStorage::table_content_briefs() . ' WHERE primary_keyword LIKE %s OR cluster_key LIKE %s',
            '%' . $wpdb->esc_like(self::TEST_MARKER) . '%',
            '%' . $wpdb->esc_like(self::TEST_MARKER) . '%'
        ));

        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . IntelligenceStorage::table_competitors() . ' WHERE domain LIKE %s',
            'test-data-%'
        ));

        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . IntelligenceStorage::table_ranking_probability() . ' WHERE keyword LIKE %s OR inputs_json LIKE %s',
            '%' . $wpdb->esc_like(self::TEST_MARKER) . '%',
            '%' . $wpdb->esc_like(self::TEST_MARKER) . '%'
        ));

        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . IntelligenceStorage::table_serp_analysis() . ' WHERE keyword LIKE %s OR reason LIKE %s OR signals_json LIKE %s',
            '%' . $wpdb->esc_like(self::TEST_MARKER) . '%',
            '%' . $wpdb->esc_like(self::TEST_MARKER) . '%',
            '%' . $wpdb->esc_like(self::TEST_MARKER) . '%'
        ));

        $fixture_posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'draft',
            'posts_per_page' => 50,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_tmwseo_staging_fixture',
                    'value' => '1',
                ],
            ],
        ]);

        foreach ($fixture_posts as $post_id) {
            wp_delete_post((int) $post_id, true);
        }

        Logs::info('staging-helper', '[TMW-VALIDATION] Cleared staging TEST DATA fixtures', [
            'actor' => get_current_user_id(),
            'environment' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown',
        ]);

        self::redirect_with_notice('cleared');
    }

    private static function render_schema_diagnostics(): void {
        global $wpdb;

        echo '<hr />';
        echo '<h2>' . esc_html__('2) Schema Diagnostics Panel', 'tmwseo') . '</h2>';
        echo '<p>' . esc_html__('Read-only schema/version checks for staging validation.', 'tmwseo') . '</p>';

        $plugin_db_version = (string) get_option('tmwseo_engine_db_version', 'not_set');
        $intelligence_schema_version = (string) get_option('tmw_intelligence_schema_version', 'not_set');
        $cluster_schema_version = (string) get_option('tmw_cluster_db_version', get_option('tmw_cluster_schema_version', 'not_set'));

        echo '<ul>';
        echo '<li><strong>' . esc_html__('Plugin DB version:', 'tmwseo') . '</strong> <code>' . esc_html($plugin_db_version) . '</code></li>';
        echo '<li><strong>' . esc_html__('Intelligence schema version:', 'tmwseo') . '</strong> <code>' . esc_html($intelligence_schema_version) . '</code></li>';
        echo '<li><strong>' . esc_html__('Cluster DB version:', 'tmwseo') . '</strong> <code>' . esc_html($cluster_schema_version) . '</code></li>';
        echo '</ul>';

        $required_tables = [
            SuggestionEngine::table_name() => ['status_priority', 'source_engine', 'type'],
            IntelligenceStorage::table_content_briefs() => ['cluster_status', 'keyword'],
            IntelligenceStorage::table_competitors() => ['domain', 'active_domain'],
            IntelligenceStorage::table_ranking_probability() => ['keyword', 'score_tier'],
            IntelligenceStorage::table_serp_analysis() => ['keyword', 'score_created'],
            $wpdb->prefix . 'tmw_intel_runs' => ['created_at', 'status'],
            $wpdb->prefix . 'tmw_intel_keywords' => ['run_keyword', 'run_id'],
            $wpdb->prefix . 'tmw_clusters' => ['slug'],
        ];

        echo '<table class="widefat striped" style="max-width:1050px">';
        echo '<thead><tr><th>' . esc_html__('Table', 'tmwseo') . '</th><th>' . esc_html__('Exists', 'tmwseo') . '</th><th>' . esc_html__('Missing indexes (detected)', 'tmwseo') . '</th></tr></thead><tbody>';

        foreach ($required_tables as $table_name => $expected_indexes) {
            $exists = self::table_exists($table_name);
            $missing = [];

            if ($exists) {
                $available_indexes = self::get_table_indexes($table_name);
                foreach ($expected_indexes as $index_name) {
                    if (!in_array($index_name, $available_indexes, true)) {
                        $missing[] = $index_name;
                    }
                }
            }

            echo '<tr>';
            echo '<td><code>' . esc_html($table_name) . '</code></td>';
            echo '<td>' . ($exists ? '<span style="color:#008a20;">Yes</span>' : '<span style="color:#b32d2e;">No</span>') . '</td>';
            echo '<td>' . esc_html(empty($missing) ? 'None detected' : implode(', ', $missing)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function render_safety_diagnostics(): void {
        echo '<hr />';
        echo '<h2>' . esc_html__('3) Safety Diagnostics Panel', 'tmwseo') . '</h2>';
        echo '<p>' . esc_html__('Read-only checks for publish safety and automation hooks.', 'tmwseo') . '</p>';

        $publish_risk = self::detect_publish_post_status_in_suggestion_actions();
        $tmwseo_cron_hooks = self::get_tmwseo_cron_hooks();
        $auto_internal_link_status = self::get_internal_link_insertion_status();

        echo '<ul style="list-style:disc;margin-left:20px;">';
        echo '<li><strong>' . esc_html__('Suggestion actions creating publish-status posts:', 'tmwseo') . '</strong> ';
        echo $publish_risk
            ? '<span style="color:#b32d2e;">Warning detected</span>'
            : '<span style="color:#008a20;">No publish insert path detected</span>';
        echo '</li>';

        echo '<li><strong>' . esc_html__('tmwseo cron hooks found:', 'tmwseo') . '</strong> ';
        if (empty($tmwseo_cron_hooks)) {
            echo '<span style="color:#008a20;">None detected</span>';
        } else {
            echo '<span style="color:#b32d2e;">' . esc_html(implode(', ', $tmwseo_cron_hooks)) . '</span>';
        }
        echo '</li>';

        echo '<li><strong>' . esc_html__('Auto-insert internal links configured:', 'tmwseo') . '</strong> ';
        if ($auto_internal_link_status['enabled']) {
            echo '<span style="color:#b32d2e;">Warning: potential auto insertion path present</span>';
        } else {
            echo '<span style="color:#008a20;">Blocked by human-approval safety policy</span>';
        }
        echo '</li>';

        echo '</ul>';

        echo '<p><em>' . esc_html($auto_internal_link_status['detail']) . '</em></p>';
    }

    private static function render_validation_export(): void {
        global $wpdb;

        echo '<hr />';
        echo '<h2>' . esc_html__('4) Validation Summary Export', 'tmwseo') . '</h2>';
        echo '<p>' . esc_html__('Copy this summary block into the staging validation checklist.', 'tmwseo') . '</p>';

        $schema_ok = self::table_exists(SuggestionEngine::table_name())
            && self::table_exists(IntelligenceStorage::table_content_briefs())
            && self::table_exists(IntelligenceStorage::table_competitors())
            && self::table_exists(IntelligenceStorage::table_ranking_probability())
            && self::table_exists(IntelligenceStorage::table_serp_analysis());

        $seeded_counts = self::get_seeded_counts();

        $recent_logs = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT time, level, context, message FROM ' . $wpdb->prefix . 'tmw_logs WHERE context IN (%s, %s, %s) ORDER BY id DESC LIMIT %d',
                'intelligence',
                'suggestions',
                'staging-helper',
                8
            ),
            ARRAY_A
        );

        $recent_drafts = get_posts([
            'post_type' => 'post',
            'post_status' => 'draft',
            'posts_per_page' => 6,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                [
                    'key' => '_tmwseo_generated',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        $lines = [];
        $lines[] = '=== TMW SEO Engine Staging Validation Summary ===';
        $lines[] = 'Schema status: ' . ($schema_ok ? 'PASS' : 'ATTENTION');
        $lines[] = 'Plugin DB version: ' . (string) get_option('tmwseo_engine_db_version', 'not_set');
        $lines[] = 'Intelligence schema version: ' . (string) get_option('tmw_intelligence_schema_version', 'not_set');
        $lines[] = 'Cluster DB version: ' . (string) get_option('tmw_cluster_db_version', get_option('tmw_cluster_schema_version', 'not_set'));
        $lines[] = 'Seeded rows count:';

        foreach ($seeded_counts as $label => $count) {
            $lines[] = ' - ' . $label . ': ' . $count;
        }

        $lines[] = 'Recent intelligence logs:';
        if (empty($recent_logs)) {
            $lines[] = ' - none';
        } else {
            foreach ($recent_logs as $log_row) {
                $lines[] = sprintf(
                    ' - [%s][%s][%s] %s',
                    (string) ($log_row['time'] ?? ''),
                    strtoupper((string) ($log_row['level'] ?? 'info')),
                    (string) ($log_row['context'] ?? ''),
                    (string) ($log_row['message'] ?? '')
                );
            }
        }

        $lines[] = 'Recent draft creations:';
        if (empty($recent_drafts)) {
            $lines[] = ' - none';
        } else {
            foreach ($recent_drafts as $draft_post) {
                $lines[] = sprintf(
                    ' - #%d | %s | %s',
                    (int) $draft_post->ID,
                    (string) $draft_post->post_title,
                    (string) $draft_post->post_date
                );
            }
        }

        echo '<textarea readonly rows="20" class="large-text code" onclick="this.select();">' . esc_textarea(implode("\n", $lines)) . '</textarea>';
    }

    /**
     * @return array<string,int>
     */
    private static function get_seeded_counts(): array {
        global $wpdb;

        $marker_like = '%' . $wpdb->esc_like(self::TEST_MARKER) . '%';

        $suggestions = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . SuggestionEngine::table_name() . ' WHERE title LIKE %s OR description LIKE %s OR suggested_action LIKE %s',
            $marker_like,
            $marker_like,
            $marker_like
        ));

        $briefs = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . IntelligenceStorage::table_content_briefs() . ' WHERE primary_keyword LIKE %s OR cluster_key LIKE %s',
            $marker_like,
            $marker_like
        ));

        $competitors = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . IntelligenceStorage::table_competitors() . ' WHERE domain LIKE %s',
            'test-data-%'
        ));

        $ranking = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . IntelligenceStorage::table_ranking_probability() . ' WHERE keyword LIKE %s OR inputs_json LIKE %s',
            $marker_like,
            $marker_like
        ));

        $serp = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . IntelligenceStorage::table_serp_analysis() . ' WHERE keyword LIKE %s OR reason LIKE %s OR signals_json LIKE %s',
            $marker_like,
            $marker_like,
            $marker_like
        ));

        $drafts = (int) count(get_posts([
            'post_type' => 'post',
            'post_status' => 'draft',
            'posts_per_page' => 200,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_tmwseo_staging_fixture',
                    'value' => '1',
                ],
            ],
        ]));

        return [
            'Suggestions (intelligence types)' => $suggestions,
            'Content briefs' => $briefs,
            'Competitor domains' => $competitors,
            'Ranking probability rows' => $ranking,
            'SERP analysis rows' => $serp,
            'Draft-only fixtures' => $drafts,
        ];
    }

    private static function redirect_with_notice(string $notice): void {
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tmw_notice=' . rawurlencode($notice)));
        exit;
    }

    private static function can_run_mutation_actions(): bool {
        if (!function_exists('wp_get_environment_type')) {
            return false;
        }

        $environment = wp_get_environment_type();
        return in_array($environment, ['local', 'development', 'staging'], true);
    }

    private static function table_exists(string $table_name): bool {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        return is_string($exists) && $exists === $table_name;
    }

    /**
     * @return string[]
     */
    private static function get_table_indexes(string $table_name): array {
        global $wpdb;

        $rows = $wpdb->get_results('SHOW INDEX FROM ' . $table_name, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $names = [];
        foreach ($rows as $row) {
            $name = isset($row['Key_name']) ? (string) $row['Key_name'] : '';
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    private static function detect_publish_post_status_in_suggestion_actions(): bool {
        $file = TMWSEO_ENGINE_PATH . 'includes/admin/class-suggestions-admin-page.php';
        if (!file_exists($file)) {
            return true;
        }

        $source = (string) file_get_contents($file);
        if ($source === '') {
            return true;
        }

        $has_publish_insert = (bool) preg_match('/wp_insert_post\s*\([^\)]*post_status\s*[\"\']\s*=>\s*[\"\']publish[\"\']/si', $source);

        return $has_publish_insert;
    }

    /**
     * @return string[]
     */
    private static function get_tmwseo_cron_hooks(): array {
        $cron = _get_cron_array();
        if (!is_array($cron)) {
            return [];
        }

        $found = [];

        foreach ($cron as $timestamp => $hooks) {
            unset($timestamp);
            if (!is_array($hooks)) {
                continue;
            }

            foreach (array_keys($hooks) as $hook_name) {
                if (strpos((string) $hook_name, 'tmwseo') === 0 || strpos((string) $hook_name, 'tmw_') === 0) {
                    $found[] = (string) $hook_name;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @return array{enabled:bool,detail:string}
     */
    private static function get_internal_link_insertion_status(): array {
        $human_required = Settings::is_human_approval_required();

        if ($human_required) {
            return [
                'enabled' => false,
                'detail' => 'Settings::is_human_approval_required() returns true, so automatic link insertion is blocked and suggestions stay manual.',
            ];
        }

        return [
            'enabled' => true,
            'detail' => 'Human-approval guard is disabled; review internal link injector pathways before production use.',
        ];
    }
}
