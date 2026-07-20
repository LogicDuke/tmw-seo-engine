<?php
namespace TMWSEO\Engine\ContentGap;

use TMWSEO\Engine\Intelligence\ContentBriefGenerator;

if (!defined('ABSPATH')) { exit; }

class ContentGapAdmin {
    public static function init(): void {
        add_action('admin_post_tmwseo_run_content_gap_scan', [__CLASS__, 'handle_run_gap_scan']);
        add_action('admin_post_tmwseo_generate_gap_brief', [__CLASS__, 'handle_generate_brief']);
    }

    public static function handle_run_gap_scan(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_run_content_gap_scan');
        $result = ContentGapService::run_analysis();

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-content-gap&scan=1&stored=' . (int) ($result['stored_gaps'] ?? 0)));
        exit;
    }

    public static function handle_generate_brief(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_generate_gap_brief');

        $keyword = sanitize_text_field((string) ($_POST['keyword'] ?? ''));
        if ($keyword !== '') {
            $generator = new ContentBriefGenerator();
            $generator->generate([
                'primary_keyword' => $keyword,
                'keyword_cluster' => 'Content Gap Opportunities',
                'search_intent' => 'Commercial Investigation',
                'brief_type' => 'content_gap',
            ]);
        }

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-content-gap&brief=1'));
        exit;
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $rows = ContentGapService::get_gaps(300);

        echo '<div class="wrap"><h1>' . esc_html__('Content Gap', 'tmwseo') . '</h1>';
        echo '<p>' . esc_html__('Identify keywords competitors rank for that your site does not.', 'tmwseo') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0 18px;">';
        wp_nonce_field('tmwseo_run_content_gap_scan');
        echo '<input type="hidden" name="action" value="tmwseo_run_content_gap_scan">';
        submit_button(__('Run Gap Analysis', 'tmwseo'), 'primary', 'submit', false);
        echo '</form>';

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Keyword', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Volume', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Difficulty', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Competitors ranking', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Opportunity score', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Actions', 'tmwseo') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($rows)) {
            echo '<tr><td colspan="6">' . esc_html__('No content gap keywords yet. Run analysis to populate this table.', 'tmwseo') . '</td></tr>';
        }

        foreach ($rows as $row) {
            $keyword = (string) ($row['keyword'] ?? '');
            $competitors = json_decode((string) ($row['competitors_json'] ?? '[]'), true);
            if (!is_array($competitors)) {
                $competitors = [];
            }

            echo '<tr>';
            echo '<td>' . esc_html($keyword) . '</td>';
            echo '<td>' . esc_html((string) ((int) ($row['search_volume'] ?? 0))) . '</td>';
            echo '<td>' . esc_html((string) ((float) ($row['keyword_difficulty'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(implode(', ', $competitors)) . '</td>';
            echo '<td><strong>' . esc_html((string) ((float) ($row['opportunity_score'] ?? 0))) . '</strong></td>';
            echo '<td>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">';
            wp_nonce_field('tmwseo_generate_gap_brief');
            echo '<input type="hidden" name="action" value="tmwseo_generate_gap_brief">';
            echo '<input type="hidden" name="keyword" value="' . esc_attr($keyword) . '">';
            submit_button(__('Generate Content Brief', 'tmwseo'), 'secondary', 'submit', false);
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }
}
