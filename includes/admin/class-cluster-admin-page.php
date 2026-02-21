<?php

if (!defined('ABSPATH')) exit;

class TMW_Cluster_Admin_Page {
    private $cluster_service;
    private $scoring_engine;

    public function __construct(TMW_Cluster_Service $cluster_service, TMW_Cluster_Scoring_Engine $scoring_engine) {
        $this->cluster_service = $cluster_service;
        $this->scoring_engine = $scoring_engine;
    }

    public function register_menu() {
        add_menu_page(
            'SEO Clusters',
            'SEO Clusters',
            'manage_options',
            'tmw-seo-clusters',
            [$this, 'render_page'],
            'dashicons-chart-line',
            58
        );
    }

    public function render_page() {
        $clusters = $this->cluster_service->list_clusters(['limit' => 100]);

        echo '<div class="wrap">';
        echo '<h1>SEO Clusters</h1>';

        if (empty($clusters) || !is_array($clusters)) {
            echo '<p>' . esc_html('No clusters found.') . '</p>';
            echo '</div>';

            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html('Name') . '</th>';
        echo '<th>' . esc_html('Score') . '</th>';
        echo '<th>' . esc_html('Grade') . '</th>';
        echo '<th>' . esc_html('Pillar') . '</th>';
        echo '<th>' . esc_html('Supports') . '</th>';
        echo '<th>' . esc_html('Keywords') . '</th>';
        echo '<th>' . esc_html('Missing Links') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($clusters as $cluster) {
            if (!is_array($cluster) || !isset($cluster['id'])) {
                continue;
            }

            $cluster_id = (int) $cluster['id'];
            $score_data = $this->scoring_engine->score_cluster($cluster_id);
            $analysis = TMW_Main_Class::get_cluster_linking_engine()->analyze_cluster($cluster_id);
            $keywords = $this->cluster_service->get_cluster_keywords($cluster_id);

            $name = isset($cluster['name']) ? (string) $cluster['name'] : '';
            $score = (is_array($score_data) && isset($score_data['score'])) ? (int) $score_data['score'] : 0;
            $grade = (is_array($score_data) && isset($score_data['grade'])) ? (string) $score_data['grade'] : 'F';
            $has_pillar = (is_array($analysis) && !empty($analysis['pillar'])) ? 'Yes' : 'No';
            $supports_count = (is_array($analysis) && isset($analysis['supports']) && is_array($analysis['supports']))
                ? count($analysis['supports'])
                : 0;
            $keywords_count = is_array($keywords) ? count($keywords) : 0;
            $missing_links_count = (is_array($analysis) && isset($analysis['missing_links']) && is_array($analysis['missing_links']))
                ? count($analysis['missing_links'])
                : 0;

            echo '<tr>';
            echo '<td>' . esc_html($name) . '</td>';
            echo '<td>' . esc_html((string) $score) . '</td>';
            echo '<td>' . esc_html($grade) . '</td>';
            echo '<td>' . esc_html($has_pillar) . '</td>';
            echo '<td>' . esc_html((string) $supports_count) . '</td>';
            echo '<td>' . esc_html((string) $keywords_count) . '</td>';
            echo '<td>' . esc_html((string) $missing_links_count) . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }
}
