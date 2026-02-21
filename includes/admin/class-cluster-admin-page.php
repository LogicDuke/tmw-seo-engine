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
        echo '<div class="wrap"><h1>SEO Clusters</h1>';
        echo '<p>Cluster intelligence dashboard (Phase 11).</p>';
        echo '</div>';
    }
}
