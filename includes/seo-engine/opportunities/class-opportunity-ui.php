<?php
namespace TMWSEO\Engine\Opportunities;

use TMWSEO\Engine\Admin;
use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Jobs;

if (!defined('ABSPATH')) { exit; }

class OpportunityUI {
    private OpportunityEngine $engine;
    private OpportunityDatabase $db;

    public function __construct(?OpportunityEngine $engine = null, ?OpportunityDatabase $db = null) {
        $this->engine = $engine ?: new OpportunityEngine();
        $this->db = $db ?: new OpportunityDatabase();
    }

    public static function init(): void {
        $ui = new self();
        add_action('admin_menu', [$ui, 'register_menu']);
        add_action('admin_post_tmwseo_run_opportunity_scan', [$ui, 'handle_run_scan']);
        add_action('admin_post_tmwseo_opportunity_action', [$ui, 'handle_row_action']);
    }

    public function register_menu(): void {
        add_submenu_page(
            Admin::MENU_SLUG,
            __('SEO Opportunities', 'tmwseo'),
            __('SEO Opportunities', 'tmwseo'),
            'manage_options',
            'tmwseo-opportunities',
            [$this, 'render_page']
        );
    }

    public function handle_run_scan(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('tmwseo_run_opportunity_scan');

        $result = $this->engine->run();
        $stored = (int) ($result['stored'] ?? 0);

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-opportunities&scan_stored=' . $stored));
        exit;
    }

    public function handle_row_action(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('tmwseo_opportunity_action');

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $action = sanitize_key((string) ($_POST['row_action'] ?? ''));

        if ($id <= 0 || $action === '') {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-opportunities'));
            exit;
        }

        if ($action === 'ignore') {
            $this->db->update_status($id, 'ignored');
        } elseif ($action === 'approve') {
            $this->db->update_status($id, 'approved');
        } elseif ($action === 'generate') {
            $this->generate_draft_page($id);
        }

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-opportunities&updated=' . $id));
        exit;
    }

    private function generate_draft_page(int $opportunity_id): void {
        $row = $this->db->find_by_id($opportunity_id);
        if (!$row) {
            return;
        }

        $keyword = trim((string) ($row['keyword'] ?? ''));
        if ($keyword === '') {
            return;
        }

        $cluster_id = $this->resolve_cluster_id($keyword);

        $post_id = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => wp_strip_all_tags(ucwords($keyword)),
            'post_name' => sanitize_title($keyword),
            'post_content' => $this->build_seed_content($keyword),
            'post_author' => get_current_user_id() ?: 1,
        ], true);

        if (is_wp_error($post_id)) {
            Logs::error('opportunities', '[TMW-OPP] Failed to create draft from opportunity', [
                'id' => $opportunity_id,
                'error' => $post_id->get_error_message(),
            ]);
            return;
        }

        update_post_meta($post_id, '_tmwseo_generated', 1);
        update_post_meta($post_id, '_tmwseo_keyword', $keyword);
        update_post_meta($post_id, '_tmwseo_cluster_id', $cluster_id);

        // Rank Math metadata + enforced noindex for all generated opportunity pages.
        update_post_meta($post_id, 'rank_math_focus_keyword', $keyword);
        update_post_meta($post_id, 'rank_math_title', ucwords($keyword) . ' | ' . get_bloginfo('name'));
        update_post_meta($post_id, 'rank_math_description', sprintf('Explore %s and related live cam topics.', $keyword));
        update_post_meta($post_id, 'rank_math_robots', ['noindex']);

        // Keep generation in draft workflow only.
        Jobs::enqueue('optimize_post', 'page', (int) $post_id, [
            'context' => 'keyword_page',
            'keyword' => $keyword,
        ]);

        $this->db->update_status($opportunity_id, 'generated');

        Logs::info('opportunities', '[TMW-OPP] Draft page generated from opportunity', [
            'opportunity_id' => $opportunity_id,
            'post_id' => (int) $post_id,
            'cluster_id' => $cluster_id,
            'keyword' => $keyword,
        ]);
    }

    private function resolve_cluster_id(string $keyword): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_keyword_clusters';

        $cluster_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE representative = %s ORDER BY id DESC LIMIT 1",
            $keyword
        ));

        if ($cluster_id > 0) {
            return $cluster_id;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE representative LIKE %s ORDER BY opportunity DESC LIMIT 1",
            '%' . $wpdb->esc_like($keyword) . '%'
        ));
    }

    private function build_seed_content(string $keyword): string {
        $internal_links = $this->build_internal_links();

        $content = "<!-- TMWSEO:AI -->\n";
        $content .= '<h2>' . esc_html(ucwords($keyword)) . '</h2>';
        $content .= '<p>' . esc_html__('Draft placeholder for SEO opportunity content generation.', 'tmwseo') . '</p>';

        if (!empty($internal_links)) {
            $content .= '<h3>' . esc_html__('Related pages', 'tmwseo') . '</h3><ul>' . $internal_links . '</ul>';
        }

        return $content;
    }

    private function build_internal_links(): string {
        $posts = get_posts([
            'post_type' => ['post', 'page', 'model'],
            'post_status' => 'publish',
            'posts_per_page' => 5,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $items = [];
        foreach ($posts as $post) {
            if (!($post instanceof \WP_Post)) {
                continue;
            }
            $items[] = sprintf(
                '<li><a href="%s">%s</a></li>',
                esc_url(get_permalink($post)),
                esc_html(get_the_title($post))
            );
        }

        return implode('', $items);
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $rows = $this->db->list_all('', 300);
        $stored = isset($_GET['scan_stored']) ? (int) $_GET['scan_stored'] : null;

        echo '<div class="wrap"><h1>SEO Opportunities</h1>';
        echo '<p>Analyze competitors and collect keyword opportunities. This module never auto-publishes and never auto-creates pages.</p>';

        if ($stored !== null) {
            echo '<div class="notice notice-success"><p>Scan completed. Stored opportunities: <strong>' . esc_html((string) $stored) . '</strong>.</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0 20px;">';
        wp_nonce_field('tmwseo_run_opportunity_scan');
        echo '<input type="hidden" name="action" value="tmwseo_run_opportunity_scan">';
        submit_button('Run Competitor Opportunity Scan', 'primary', 'submit', false);
        echo '</form>';

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Keyword</th><th>Search Volume</th><th>Difficulty</th><th>Opportunity Score</th><th>Status</th><th>Actions</th>';
        echo '</tr></thead><tbody>';

        if (empty($rows)) {
            echo '<tr><td colspan="6">No opportunities found yet.</td></tr>';
        }

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['keyword'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ((int) ($row['search_volume'] ?? 0))) . '</td>';
            echo '<td>' . esc_html((string) ((float) ($row['difficulty'] ?? 0))) . '</td>';
            echo '<td><strong>' . esc_html((string) ((float) ($row['opportunity_score'] ?? 0))) . '</strong></td>';
            echo '<td>' . esc_html((string) ($row['status'] ?? 'new')) . '</td>';
            echo '<td>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:5px;">';
            wp_nonce_field('tmwseo_opportunity_action');
            echo '<input type="hidden" name="action" value="tmwseo_opportunity_action">';
            echo '<input type="hidden" name="id" value="' . esc_attr((string) $id) . '">';
            echo '<input type="hidden" name="row_action" value="generate">';
            submit_button('Generate Draft Page', 'secondary', 'submit', false);
            echo '</form>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:5px;">';
            wp_nonce_field('tmwseo_opportunity_action');
            echo '<input type="hidden" name="action" value="tmwseo_opportunity_action">';
            echo '<input type="hidden" name="id" value="' . esc_attr((string) $id) . '">';
            echo '<input type="hidden" name="row_action" value="approve">';
            submit_button('Add To Topic Cluster', 'secondary', 'submit', false);
            echo '</form>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">';
            wp_nonce_field('tmwseo_opportunity_action');
            echo '<input type="hidden" name="action" value="tmwseo_opportunity_action">';
            echo '<input type="hidden" name="id" value="' . esc_attr((string) $id) . '">';
            echo '<input type="hidden" name="row_action" value="ignore">';
            submit_button('Ignore', 'delete', 'submit', false);
            echo '</form>';

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }
}
