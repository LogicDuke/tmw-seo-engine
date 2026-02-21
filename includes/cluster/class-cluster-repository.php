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
        $defaults = [
            'status'  => null,
            'limit'   => 50,
            'offset'  => 0,
            'orderby' => 'created_at',
            'order'   => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        $allowed_orderby = ['id', 'name', 'created_at', 'updated_at'];
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'created_at';

        $order = strtoupper((string) $args['order']);
        if (!in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'DESC';
        }

        $limit = (int) $args['limit'];
        $offset = (int) $args['offset'];

        if ($limit < 0) {
            $limit = 0;
        }

        if ($offset < 0) {
            $offset = 0;
        }

        $where_sql = '';
        $prepare_args = [];

        if ($args['status'] !== null && $args['status'] !== '') {
            $where_sql = 'WHERE status = %s';
            $prepare_args[] = $args['status'];
        }

        $sql = "SELECT * FROM {$this->clusters_table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $prepare_args[] = $limit;
        $prepare_args[] = $offset;

        $query = $this->wpdb->prepare($sql, $prepare_args);
        $clusters = $this->wpdb->get_results($query, ARRAY_A);

        return $clusters ?: [];
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
