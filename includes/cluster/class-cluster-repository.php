<?php

if (!defined('ABSPATH')) exit;

class TMW_Cluster_Repository {
    private $wpdb;
    private $clusters_table;
    private $keywords_table;
    private $pages_table;
    private $metrics_table;

    public function __construct($wpdb) {
        $this->wpdb = $wpdb;
        $this->clusters_table = $wpdb->prefix . 'tmw_clusters';
        $this->keywords_table = $wpdb->prefix . 'tmw_keywords';
        $this->pages_table = $wpdb->prefix . 'tmw_pages';
        $this->metrics_table = $wpdb->prefix . 'tmw_metrics';
    }

    public function get_cluster($id) {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }

        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->clusters_table} WHERE id = %d LIMIT 1",
            $id
        );
        $cluster = $this->wpdb->get_row($query, ARRAY_A);

        return $cluster ?: null;
    }

    public function get_cluster_by_slug($slug) {
        $slug = sanitize_title($slug);
        if ($slug === '') {
            return null;
        }

        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->clusters_table} WHERE slug = %s LIMIT 1",
            $slug
        );
        $cluster = $this->wpdb->get_row($query, ARRAY_A);

        return $cluster ?: null;
    }

    public function get_clusters($args = []) {
        // TODO: Implement cluster list retrieval.
        return [];
    }

    public function create_cluster($data) {
        // TODO: Implement cluster creation.
        return null;
    }

    public function update_cluster($id, $data) {
        // TODO: Implement cluster update.
        return null;
    }

    public function delete_cluster($id) {
        // TODO: Implement cluster deletion.
        return null;
    }
}
