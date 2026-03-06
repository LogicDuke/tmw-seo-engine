<?php
namespace TMWSEO\Engine\Suggestions;

use TMWSEO\Engine\Admin;
use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class SuggestionsAdminPage {
    private SuggestionEngine $engine;

    public function __construct(?SuggestionEngine $engine = null) {
        $this->engine = $engine ?: new SuggestionEngine();
    }

    public static function init(): void {
        $ui = new self();
        add_action('admin_menu', [$ui, 'register_menu'], 99);
        add_action('admin_post_tmwseo_suggestion_action', [$ui, 'handle_row_action']);
    }

    public function register_menu(): void {
        add_submenu_page(
            Admin::MENU_SLUG,
            __('Suggestions', 'tmwseo'),
            __('Suggestions', 'tmwseo'),
            'manage_options',
            'tmwseo-suggestions',
            [$this, 'render_page']
        );
    }

    public function handle_row_action(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_suggestion_action');

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $row_action = sanitize_key((string) ($_POST['row_action'] ?? ''));

        if ($id <= 0 || $row_action === '') {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-suggestions'));
            exit;
        }

        $notice = 'no_change';

        if ($row_action === 'ignore') {
            if ($this->engine->updateSuggestionStatus($id, 'ignored')) {
                $notice = 'ignored';
            }
        }

        if ($row_action === 'approve' || $row_action === 'create_draft') {
            $draft_id = $this->create_draft_from_suggestion($id);
            if ($draft_id > 0) {
                $status = $row_action === 'approve' ? 'approved' : 'implemented';
                $this->engine->updateSuggestionStatus($id, $status);
                $notice = $row_action;
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-suggestions&notice=' . rawurlencode($notice) . '&id=' . $id));
        exit;
    }

    private function create_draft_from_suggestion(int $suggestion_id): int {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, type, title, description, suggested_action, source_engine, priority_score FROM ' . SuggestionEngine::table_name() . ' WHERE id = %d LIMIT 1',
            $suggestion_id
        ), ARRAY_A);

        if (!is_array($row) || empty($row['title'])) {
            return 0;
        }

        $existing = get_posts([
            'post_type' => 'post',
            'post_status' => ['draft', 'pending', 'publish', 'future', 'private'],
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_tmwseo_suggestion_id',
                    'value' => (string) $suggestion_id,
                ],
            ],
        ]);

        if (!empty($existing)) {
            return (int) $existing[0];
        }

        $title = sanitize_text_field((string) $row['title']);
        $description = sanitize_textarea_field((string) ($row['description'] ?? ''));
        $suggested_action = sanitize_textarea_field((string) ($row['suggested_action'] ?? ''));

        $content = '<!-- TMWSEO:SUGGESTION -->\n';
        $content .= '<h2>' . esc_html($title) . '</h2>';
        $content .= '<p>' . esc_html($description) . '</p>';
        $content .= '<h3>' . esc_html__('Suggested next step', 'tmwseo') . '</h3>';
        $content .= '<p>' . esc_html($suggested_action) . '</p>';

        $post_id = wp_insert_post([
            'post_type' => 'post',
            'post_status' => 'draft',
            'post_title' => wp_strip_all_tags($title),
            'post_content' => $content,
            'post_author' => get_current_user_id() ?: 1,
        ], true);

        if (is_wp_error($post_id)) {
            Logs::error('suggestions', '[TMW-SUGGEST] Failed to create draft from suggestion', [
                'suggestion_id' => $suggestion_id,
                'error' => $post_id->get_error_message(),
            ]);
            return 0;
        }

        update_post_meta($post_id, '_tmwseo_generated', 1);
        update_post_meta($post_id, '_tmwseo_suggestion_id', $suggestion_id);
        update_post_meta($post_id, '_tmwseo_suggestion_type', sanitize_key((string) ($row['type'] ?? '')));
        update_post_meta($post_id, '_tmwseo_suggestion_source_engine', sanitize_key((string) ($row['source_engine'] ?? '')));
        update_post_meta($post_id, '_tmwseo_suggestion_priority', (float) ($row['priority_score'] ?? 0));
        update_post_meta($post_id, 'rank_math_robots', ['noindex']);

        Logs::info('suggestions', '[TMW-SUGGEST] Draft created from suggestion (manual action)', [
            'suggestion_id' => $suggestion_id,
            'post_id' => (int) $post_id,
        ]);

        return (int) $post_id;
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $rows = $this->engine->getSuggestions(['limit' => 500]);
        $active_filter = sanitize_key((string) ($_GET['tmw_filter'] ?? 'all'));
        $notice = sanitize_key((string) ($_GET['notice'] ?? ''));

        $filtered_rows = array_values(array_filter($rows, function (array $row) use ($active_filter): bool {
            $type = (string) ($row['type'] ?? '');
            $priority = (float) ($row['priority_score'] ?? 0);

            if ($active_filter === 'high_priority') {
                return $priority >= 8;
            }

            if ($active_filter === 'content_opportunity') {
                return $type === 'content_opportunity';
            }

            if ($active_filter === 'internal_linking') {
                return $type === 'internal_link';
            }

            if ($active_filter === 'cluster_expansion') {
                return $type === 'cluster_expansion';
            }

            if ($active_filter === 'traffic_keywords') {
                return $type === 'traffic_keyword';
            }

            return true;
        }));

        echo '<div class="wrap tmwseo-suggestions-page">';
        echo '<h1>' . esc_html__('Suggestions Dashboard', 'tmwseo') . '</h1>';
        echo '<p>' . esc_html__('Review SEO suggestions and decide what to do next. Actions only create drafts and never publish automatically.', 'tmwseo') . '</p>';

        if (in_array($notice, ['approve', 'create_draft', 'ignored'], true)) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            if ($notice === 'approve') {
                echo esc_html__('Suggestion approved and saved as a draft post.', 'tmwseo');
            } elseif ($notice === 'create_draft') {
                echo esc_html__('Draft post created from suggestion.', 'tmwseo');
            } else {
                echo esc_html__('Suggestion ignored.', 'tmwseo');
            }
            echo '</p></div>';
        }

        $tabs = [
            'all' => 'All',
            'high_priority' => 'High Priority',
            'content_opportunity' => 'Content Opportunities',
            'internal_linking' => 'Internal Linking',
            'cluster_expansion' => 'Cluster Expansion',
            'traffic_keywords' => 'Traffic Keywords',
        ];

        echo '<ul class="subsubsub">';
        $first = true;
        foreach ($tabs as $key => $label) {
            $url = add_query_arg([
                'page' => 'tmwseo-suggestions',
                'tmw_filter' => $key,
            ], admin_url('admin.php'));
            $class = $active_filter === $key ? 'current' : '';
            if (!$first) {
                echo ' | ';
            }
            echo '<li><a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
            $first = false;
        }
        echo '</ul>';

        echo '<table class="widefat fixed striped tmwseo-suggestions-table"><thead><tr>';
        echo '<th>' . esc_html__('Priority', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Suggestion Type', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Title', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Description', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Estimated Traffic', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Difficulty', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Source Engine', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Date Created', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Actions', 'tmwseo') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($filtered_rows)) {
            echo '<tr><td colspan="9">' . esc_html__('No suggestions found for this filter.', 'tmwseo') . '</td></tr>';
        }

        foreach ($filtered_rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $priority_score = (float) ($row['priority_score'] ?? 0);
            $priority_label = $this->priority_label($priority_score);
            $priority_class = strtolower($priority_label);

            echo '<tr>';
            echo '<td><span class="tmwseo-priority tmwseo-priority-' . esc_attr($priority_class) . '">' . esc_html($priority_label . ' (' . number_format_i18n($priority_score, 1) . ')') . '</span></td>';
            echo '<td>' . esc_html($this->format_label((string) ($row['type'] ?? ''))) . '</td>';
            echo '<td><strong>' . esc_html((string) ($row['title'] ?? '')) . '</strong></td>';
            echo '<td>' . esc_html(wp_trim_words((string) ($row['description'] ?? ''), 22, '…')) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) ($row['estimated_traffic'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float) ($row['difficulty'] ?? 0), 1)) . '</td>';
            echo '<td>' . esc_html($this->format_label((string) ($row['source_engine'] ?? ''))) . '</td>';
            echo '<td>' . esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) ($row['created_at'] ?? ''), true)) . '</td>';
            echo '<td>';

            $this->render_action_button($id, 'create_draft', __('Create Draft', 'tmwseo'), 'secondary');
            $this->render_action_button($id, 'approve', __('Approve', 'tmwseo'), 'primary');
            $this->render_action_button($id, 'ignore', __('Ignore', 'tmwseo'), 'delete');

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function render_action_button(int $id, string $action, string $label, string $class): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 6px 6px 0;">';
        wp_nonce_field('tmwseo_suggestion_action');
        echo '<input type="hidden" name="action" value="tmwseo_suggestion_action">';
        echo '<input type="hidden" name="id" value="' . esc_attr((string) $id) . '">';
        echo '<input type="hidden" name="row_action" value="' . esc_attr($action) . '">';
        submit_button($label, $class . ' small', 'submit', false);
        echo '</form>';
    }

    private function priority_label(float $score): string {
        if ($score >= 8) {
            return 'High';
        }
        if ($score >= 5) {
            return 'Medium';
        }

        return 'Low';
    }

    private function format_label(string $value): string {
        $value = str_replace(['_', '-'], ' ', $value);
        return ucwords(trim($value));
    }
}
