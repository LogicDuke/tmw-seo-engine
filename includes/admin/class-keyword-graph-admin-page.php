<?php
namespace TMWSEO\Engine\Admin;

if (!defined('ABSPATH')) { exit; }

class KeywordGraphAdminPage {
    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';

        $clusters = (array) $wpdb->get_results(
            "SELECT graph_cluster_id AS cluster_id,
                    COUNT(*) AS cluster_size,
                    SUM(node_degree) AS degree_total,
                    MAX(keyword) AS sample_keyword
             FROM {$cand_table}
             WHERE graph_cluster_id IS NOT NULL
             GROUP BY graph_cluster_id
             ORDER BY cluster_size DESC, degree_total DESC
             LIMIT 25",
            ARRAY_A
        );

        echo '<div class="wrap"><h1>Keyword Graph</h1>';
        echo '<p>Top graph clusters generated from keyword relationships.</p>';

        if (empty($clusters)) {
            echo '<p>No graph clusters available yet. Run a keyword cycle to generate graph data.</p></div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Cluster ID</th><th>Cluster Size</th><th>Top Seed Keyword</th><th>Total Node Degree</th>';
        echo '</tr></thead><tbody>';

        foreach ($clusters as $row) {
            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['cluster_id'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ((int) ($row['cluster_size'] ?? 0))) . '</td>';
            echo '<td>' . esc_html((string) ($row['sample_keyword'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ((int) ($row['degree_total'] ?? 0))) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }
}
