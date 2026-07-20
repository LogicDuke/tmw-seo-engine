<?php
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Keywords\RecursiveKeywordExpansionEngine;

if (!defined('ABSPATH')) { exit; }

class KeywordGraphAdminPage {
    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        self::handle_enqueue_request();

        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_keyword_graph';

        $rows = (array) $wpdb->get_results(
            "SELECT child_keyword AS keyword, parent_keyword, depth, search_volume, keyword_difficulty
             FROM {$table}
             WHERE source <> 'expanded_marker'
             ORDER BY depth ASC, search_volume DESC, child_keyword ASC
             LIMIT 500",
            ARRAY_A
        );

        $tree_rows = (array) $wpdb->get_results(
            "SELECT parent_keyword, child_keyword
             FROM {$table}
             WHERE source <> 'expanded_marker'
             ORDER BY depth ASC, search_volume DESC
             LIMIT 300",
            ARRAY_A
        );

        echo '<div class="wrap"><h1>Keyword Graph</h1>';
        echo '<p>Recursive keyword expansion graph and discovered keyword tree.</p>';

        echo '<form method="post" style="margin:16px 0;padding:12px;background:#fff;border:1px solid #ccd0d4;max-width:900px;">';
        wp_nonce_field('tmwseo_enqueue_recursive_keyword_expansion', 'tmwseo_recursive_keyword_expansion_nonce');
        echo '<h2 style="margin-top:0;">Run Recursive Expansion</h2>';
        echo '<p>Enter seed keywords (comma-separated). Expansion runs in the background worker (max depth 3).</p>';
        echo '<input type="text" name="tmwseo_seed_keywords" style="width:100%;max-width:800px;" placeholder="webcam models, cam girls" /> ';
        submit_button('Enqueue Expansion Job', 'primary', 'tmwseo_enqueue_recursive_keyword_expansion', false);
        echo '</form>';

        if (empty($rows)) {
            echo '<p>No keyword graph rows available yet.</p></div>';
            return;
        }

        echo '<h2>Keyword Graph Table</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Keyword</th><th>Parent Keyword</th><th>Depth</th><th>Volume</th><th>Difficulty</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['keyword'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['parent_keyword'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ((int) ($row['depth'] ?? 0))) . '</td>';
            echo '<td>' . esc_html((string) ((int) ($row['search_volume'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format((float) ($row['keyword_difficulty'] ?? 0), 2)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<h2 style="margin-top:24px;">Keyword Tree Visualization</h2>';
        echo '<pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:420px;overflow:auto;">' . esc_html(self::build_tree_visualization($tree_rows)) . '</pre>';

        echo '</div>';
    }

    private static function handle_enqueue_request(): void {
        if (!isset($_POST['tmwseo_enqueue_recursive_keyword_expansion'])) {
            return;
        }

        check_admin_referer('tmwseo_enqueue_recursive_keyword_expansion', 'tmwseo_recursive_keyword_expansion_nonce');

        $raw = sanitize_text_field((string) ($_POST['tmwseo_seed_keywords'] ?? ''));
        $seed_keywords = array_values(array_filter(array_map('trim', explode(',', $raw))));

        if (empty($seed_keywords)) {
            echo '<div class="notice notice-error"><p>Please provide at least one seed keyword.</p></div>';
            return;
        }

        $job_id = RecursiveKeywordExpansionEngine::enqueue($seed_keywords);
        echo '<div class="notice notice-success"><p>Recursive keyword expansion job queued. Job ID: ' . esc_html((string) $job_id) . '.</p></div>';
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private static function build_tree_visualization(array $rows): string {
        $children = [];
        $all_children = [];

        foreach ($rows as $row) {
            $parent = strtolower(trim((string) ($row['parent_keyword'] ?? '')));
            $child = strtolower(trim((string) ($row['child_keyword'] ?? '')));
            if ($parent === '' || $child === '' || $parent === $child) {
                continue;
            }
            $children[$parent][] = $child;
            $all_children[$child] = true;
        }

        if (empty($children)) {
            return 'No tree data yet.';
        }

        $roots = [];
        foreach (array_keys($children) as $parent) {
            if (!isset($all_children[$parent])) {
                $roots[] = $parent;
            }
        }
        if (empty($roots)) {
            $roots = array_slice(array_keys($children), 0, 10);
        }

        $output = [];
        foreach (array_slice($roots, 0, 10) as $root) {
            $output[] = $root;
            self::append_tree_lines($output, $children, $root, 1, [$root => true]);
            $output[] = '';
        }

        return trim(implode("\n", $output));
    }

    /**
     * @param array<int,string> $output
     * @param array<string,array<int,string>> $children
     * @param array<string,bool> $seen
     */
    private static function append_tree_lines(array &$output, array $children, string $parent, int $depth, array $seen): void {
        if ($depth > 3 || empty($children[$parent])) {
            return;
        }

        $kids = array_values(array_unique($children[$parent]));
        sort($kids);
        $kids = array_slice($kids, 0, 8);

        foreach ($kids as $index => $child) {
            $prefix = str_repeat('  ', max(0, $depth - 1));
            $branch = ($index === count($kids) - 1) ? '└── ' : '├── ';
            $output[] = $prefix . $branch . $child;
            if (!isset($seen[$child])) {
                $next_seen = $seen;
                $next_seen[$child] = true;
                self::append_tree_lines($output, $children, $child, $depth + 1, $next_seen);
            }
        }
    }
}
