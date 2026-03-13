<?php
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Services\OpenAI;
use TMWSEO\Engine\JobWorker;

if (!defined('ABSPATH')) { exit; }

class AIContentBriefGeneratorAdmin {
    public static function init(): void {
        add_action('admin_post_tmwseo_generate_ai_brief', [__CLASS__, 'handle_generate_ai_brief']);
        add_action('admin_post_tmwseo_save_ai_brief', [__CLASS__, 'handle_save_ai_brief']);
        add_action('admin_post_tmwseo_export_ai_brief', [__CLASS__, 'handle_export_ai_brief']);
    }

    public static function render_widget(): void {
        echo '<div class="tmwseo-card" style="max-width:1100px;margin:16px 0;">';
        echo '<h2>AI Content Brief Generator</h2>';
        if (isset($_GET['queued']) && (int) $_GET['queued'] === 1) {
            echo '<div class="notice notice-success inline"><p>' . esc_html__('AI content brief job queued. Refresh shortly to view the generated brief.', 'tmwseo') . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="tmwseo-ai-brief-form">';
        wp_nonce_field('tmwseo_generate_ai_brief');
        echo '<input type="hidden" name="action" value="tmwseo_generate_ai_brief" />';
        echo '<p><label for="tmwseo_cluster_id"><strong>Cluster ID</strong></label></p>';
        echo '<input type="number" min="1" id="tmwseo_cluster_id" name="cluster_id" required /> ';
        submit_button(__('Generate AI Brief', 'tmwseo'), 'primary', 'submit', false, ['id' => 'tmwseo-ai-brief-submit']);
        echo ' <span id="tmwseo-ai-brief-loading" style="display:none;">Generating AI brief…</span>';
        echo '</form>';

        $key = sanitize_key((string) ($_GET['ai_brief_key'] ?? ''));
        if ($key === '') {
            $queued_key = get_transient('tmwseo_ai_brief_last_result_user_' . get_current_user_id());
            if (is_string($queued_key) && $queued_key !== '') {
                $key = sanitize_key($queued_key);
                delete_transient('tmwseo_ai_brief_last_result_user_' . get_current_user_id());
            }
        }
        if ($key !== '') {
            $brief = get_transient('tmwseo_ai_brief_ui_' . $key);
            if (is_array($brief)) {
                if (!empty($brief['error'])) {
                    echo '<div class="notice notice-error inline"><p>' . esc_html((string) $brief['error']) . '</p></div>';
                } else {
                    self::render_brief_editor($brief);
                }
            }
        }

        echo '</div>';
        echo '<script>document.getElementById("tmwseo-ai-brief-form")?.addEventListener("submit",function(){document.getElementById("tmwseo-ai-brief-loading").style.display="inline";document.getElementById("tmwseo-ai-brief-submit").disabled=true;});</script>';
    }

    public static function handle_generate_ai_brief(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('tmwseo_generate_ai_brief');

        $cluster_id = isset($_POST['cluster_id']) ? (int) $_POST['cluster_id'] : 0;
        if ($cluster_id <= 0) {
            self::redirect_with_error('Invalid cluster ID.');
        }

        JobWorker::enqueue_job('ai_content_brief_generation', [
            'cluster_id' => $cluster_id,
            'user_id' => get_current_user_id(),
            'trigger' => 'ai_brief_admin',
        ]);

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-content-briefs&queued=1'));
        exit;
    }

    public static function handle_save_ai_brief(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('tmwseo_save_ai_brief');

        global $wpdb;
        $table = $wpdb->prefix . 'tmw_seo_content_briefs';
        $payload = wp_unslash((string) ($_POST['brief_json'] ?? '{}'));
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            self::redirect_with_error('Invalid brief payload.');
        }

        $primary_keyword = sanitize_text_field((string) ($data['primary_keyword'] ?? ''));
        $cluster_key = 'cluster-' . (int) ($data['cluster_id'] ?? 0);

        $wpdb->insert($table, [
            'primary_keyword' => $primary_keyword,
            'cluster_key' => $cluster_key,
            'brief_type' => 'ai_generated',
            'brief_json' => wp_json_encode($data),
            'status' => 'ready',
            'created_at' => current_time('mysql'),
        ], ['%s','%s','%s','%s','%s','%s']);

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-content-briefs&notice=ai_brief_saved'));
        exit;
    }

    public static function handle_export_ai_brief(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('tmwseo_export_ai_brief');

        $payload = wp_unslash((string) ($_POST['brief_json'] ?? '{}'));
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            wp_die('Invalid export payload.');
        }

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="tmwseo-ai-brief-' . gmdate('Ymd-His') . '.json"');
        echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function run_background_brief_generation(array $payload): void {
        $cluster_id = (int) ($payload['cluster_id'] ?? 0);
        $user_id = (int) ($payload['user_id'] ?? 0);
        if ($cluster_id <= 0 || $user_id <= 0) {
            throw new \RuntimeException('Invalid AI brief payload.');
        }

        $cache_key = 'tmwseo_bg_ai_brief_' . $cluster_id;
        $brief = get_transient($cache_key);
        if (!is_array($brief)) {
            $keywords = self::get_cluster_keywords($cluster_id);
            if (empty($keywords)) {
                throw new \RuntimeException('No keywords found for this cluster.');
            }

            $serp_data = self::get_cluster_serp_data($cluster_id);
            $metrics = self::get_keyword_metrics($keywords);
            $brief = self::generate_brief($cluster_id, $keywords, $serp_data, $metrics);
            if (!is_array($brief) || empty($brief['primary_keyword'])) {
                throw new \RuntimeException('Failed to generate AI brief.');
            }

            $brief['cluster_id'] = $cluster_id;
            $brief['generated_at'] = current_time('mysql');
            set_transient($cache_key, $brief, 30 * MINUTE_IN_SECONDS);
        }

        $key = wp_generate_password(20, false, false);
        set_transient('tmwseo_ai_brief_ui_' . $key, $brief, HOUR_IN_SECONDS);
        set_transient('tmwseo_ai_brief_last_result_user_' . $user_id, $key, 20 * MINUTE_IN_SECONDS);
    }

    private static function generate_brief(int $cluster_id, array $keywords, array $serp_data, array $metrics): array {
        $primary = (string) ($keywords[0] ?? '');
        $prompt_input = [
            'cluster_id' => $cluster_id,
            'keywords' => array_slice($keywords, 0, 30),
            'serp_data' => $serp_data,
            'metrics' => $metrics,
        ];

        if (OpenAI::is_configured()) {
            $res = OpenAI::chat_json([
                ['role' => 'system', 'content' => 'Generate an SEO content brief. Return JSON with keys: primary_keyword, search_intent, recommended_word_count, headings (object with h1 and h2 array), faq (array), schema_markup_suggestion, internal_link_targets (array), meta_title, meta_description.'],
                ['role' => 'user', 'content' => wp_json_encode($prompt_input)],
            ], 'gpt-4o-mini', ['max_tokens' => 700, 'temperature' => 0.4]);

            if (!empty($res['ok']) && is_array($res['json'])) {
                $brief = $res['json'];
                $brief['primary_keyword'] = sanitize_text_field((string) ($brief['primary_keyword'] ?? $primary));
                return $brief;
            }
        }

        return [
            'primary_keyword' => $primary,
            'search_intent' => 'Informational',
            'recommended_word_count' => 1400,
            'headings' => [
                'h1' => 'Comprehensive Guide to ' . $primary,
                'h2' => ['What is ' . $primary . '?', 'How to choose the best option', 'Common mistakes to avoid'],
            ],
            'faq' => ['What is the best way to get started?', 'How long does it take to see results?', 'What tools are recommended?'],
            'schema_markup_suggestion' => 'Article + FAQPage',
            'internal_link_targets' => array_slice($keywords, 1, 5),
            'meta_title' => 'Best ' . $primary . ' Guide for 2026',
            'meta_description' => 'Learn how to plan, create, and optimize content for ' . $primary . ' with actionable SEO guidance.',
        ];
    }

    private static function get_cluster_keywords(int $cluster_id): array {
        global $wpdb;

        $keywords = [];
        $table1 = $wpdb->prefix . 'tmw_cluster_keywords';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table1)) === $table1) {
            $rows = (array) $wpdb->get_col($wpdb->prepare("SELECT keyword FROM {$table1} WHERE cluster_id = %d LIMIT 100", $cluster_id));
            $keywords = array_merge($keywords, $rows);
        }

        $table2 = $wpdb->prefix . 'tmw_keyword_cluster_map';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table2)) === $table2) {
            $rows = (array) $wpdb->get_col($wpdb->prepare("SELECT keyword FROM {$table2} WHERE cluster_id = %d LIMIT 100", $cluster_id));
            $keywords = array_merge($keywords, $rows);
        }

        return array_values(array_unique(array_filter(array_map('sanitize_text_field', $keywords))));
    }

    private static function get_cluster_serp_data(int $cluster_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_seo_serp_analysis';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return [];
        }

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT keyword, serp_weakness_score, competitor_count, analyzed_at FROM {$table} WHERE cluster_id = %d ORDER BY analyzed_at DESC LIMIT 30",
            $cluster_id
        ), ARRAY_A);

        return $rows;
    }

    private static function get_keyword_metrics(array $keywords): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_keywords';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return [];
        }

        $slice = array_slice($keywords, 0, 50);
        if (empty($slice)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($slice), '%s'));
        $query = $wpdb->prepare(
            "SELECT keyword, search_volume, cpc, difficulty FROM {$table} WHERE keyword IN ({$placeholders})",
            ...$slice
        );

        return (array) $wpdb->get_results($query, ARRAY_A);
    }

    private static function render_brief_editor(array $brief): void {
        echo '<h3 style="margin-top:20px;">Generated AI Brief</h3>';
        echo '<textarea rows="20" style="width:100%;font-family:monospace;">' . esc_textarea((string) wp_json_encode($brief, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</textarea>';

        echo '<div style="margin-top:10px;display:flex;gap:8px;">';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_save_ai_brief');
        echo '<input type="hidden" name="action" value="tmwseo_save_ai_brief" />';
        echo '<input type="hidden" name="brief_json" value="' . esc_attr((string) wp_json_encode($brief)) . '" />';
        submit_button(__('Save Brief', 'tmwseo'), 'primary', 'submit', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_export_ai_brief');
        echo '<input type="hidden" name="action" value="tmwseo_export_ai_brief" />';
        echo '<input type="hidden" name="brief_json" value="' . esc_attr((string) wp_json_encode($brief)) . '" />';
        submit_button(__('Export Brief', 'tmwseo'), 'secondary', 'submit', false);
        echo '</form>';

        echo '</div>';
    }

    private static function redirect_with_error(string $message): void {
        $key = wp_generate_password(20, false, false);
        set_transient('tmwseo_ai_brief_ui_' . $key, ['error' => $message], 10 * MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=tmwseo-content-briefs&ai_brief_key=' . rawurlencode($key)));
        exit;
    }
}
