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
        if (!isset($data['name']) || trim((string) $data['name']) === '') {
            return null;
        }

        $name = sanitize_text_field($data['name']);
        if ($name === '') {
            return null;
        }

        $base_slug = isset($data['slug']) && $data['slug'] !== ''
            ? sanitize_title($data['slug'])
            : sanitize_title($name);

        if ($base_slug === '') {
            return null;
        }

        $unique_slug = $base_slug;
        $suffix = 2;

        while ($this->get_cluster_by_slug($unique_slug) !== null) {
            $unique_slug = $base_slug . '-' . $suffix;
            $suffix++;
        }

        $insert_data = [
            'name'   => $name,
            'slug'   => $unique_slug,
            'status' => sanitize_text_field($data['status'] ?? 'active'),
        ];

        $formats = [
            '%s',
            '%s',
            '%s',
        ];

        $inserted = $this->wpdb->insert($this->clusters_table, $insert_data, $formats);
        if ($inserted === false) {
            return null;
        }

        return $this->get_cluster($this->wpdb->insert_id);
    }

    public function update_cluster($id, $data) {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }

        $existing_cluster = $this->get_cluster($id);
        if ($existing_cluster === null) {
            return null;
        }

        $update_data = [];
        $formats = [];

        if (array_key_exists('name', $data)) {
            $update_data['name'] = sanitize_text_field($data['name']);
            $formats[] = '%s';
        }

        if (array_key_exists('slug', $data)) {
            $base_slug = sanitize_title($data['slug']);
            if ($base_slug === '') {
                return null;
            }

            $unique_slug = $base_slug;
            $suffix = 2;
            $existing_slug_cluster = $this->get_cluster_by_slug($unique_slug);

            while ($existing_slug_cluster !== null && (int) $existing_slug_cluster['id'] !== $id) {
                $unique_slug = $base_slug . '-' . $suffix;
                $suffix++;
                $existing_slug_cluster = $this->get_cluster_by_slug($unique_slug);
            }

            $update_data['slug'] = $unique_slug;
            $formats[] = '%s';
        }

        if (array_key_exists('status', $data)) {
            $update_data['status'] = sanitize_text_field($data['status']);
            $formats[] = '%s';
        }

        if ($update_data === []) {
            return $existing_cluster;
        }

        $updated = $this->wpdb->update(
            $this->clusters_table,
            $update_data,
            ['id' => $id],
            $formats,
            ['%d']
        );

        if ($updated === false) {
            return null;
        }

        return $this->get_cluster($id);
    }

    public function delete_cluster($id) {
        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }

        $cluster = $this->get_cluster($id);
        if ($cluster === null) {
            return false;
        }

        $deleted = $this->wpdb->delete(
            $this->clusters_table,
            ['id' => $id],
            ['%d']
        );

        return $deleted !== false;
    }

    public function clear_cluster_keywords($cluster_id) {
        $cluster_id = (int) $cluster_id;
        if ($cluster_id <= 0) {
            return false;
        }

        $cluster = $this->get_cluster($cluster_id);
        if ($cluster === null) {
            return false;
        }

        $deleted = $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->keywords_table} WHERE cluster_id = %d",
                $cluster_id
            )
        );

        return $deleted !== false;
    }

    public function get_cluster_keywords($cluster_id, $args = []) {
        $cluster_id = (int) $cluster_id;
        if ($cluster_id <= 0) {
            return [];
        }

        $defaults = [
            'limit'   => 100,
            'offset'  => 0,
            'orderby' => 'id',
            'order'   => 'ASC',
        ];

        $args = wp_parse_args($args, $defaults);

        $allowed_orderby = ['id', 'keyword', 'search_volume', 'keyword_difficulty', 'created_at'];
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'id';

        $order = strtoupper((string) $args['order']);
        if (!in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'ASC';
        }

        $limit = (int) $args['limit'];
        $offset = (int) $args['offset'];

        if ($limit < 0) {
            $limit = 0;
        }

        if ($offset < 0) {
            $offset = 0;
        }

        $sql = "SELECT * FROM {$this->keywords_table} WHERE cluster_id = %d ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $query = $this->wpdb->prepare($sql, $cluster_id, $limit, $offset);
        $keywords = $this->wpdb->get_results($query, ARRAY_A);

        return $keywords ?: [];
    }

    public function add_keyword_to_cluster($cluster_id, $keyword, $data = []) {
        $cluster_id = (int) $cluster_id;
        if ($cluster_id <= 0) {
            return false;
        }

        $cluster = $this->get_cluster($cluster_id);
        if ($cluster === null) {
            return false;
        }

        $keyword = trim(sanitize_text_field($keyword));
        if ($keyword === '') {
            return false;
        }

        $existing_query = $this->wpdb->prepare(
            "SELECT * FROM {$this->keywords_table} WHERE cluster_id = %d AND keyword = %s LIMIT 1",
            $cluster_id,
            $keyword
        );
        $existing_keyword = $this->wpdb->get_row($existing_query, ARRAY_A);

        if ($existing_keyword !== null) {
            return $existing_keyword;
        }

        $insert_data = [
            'cluster_id'         => $cluster_id,
            'keyword'            => $keyword,
            'search_volume'      => $data['search_volume'] ?? null,
            'keyword_difficulty' => $data['keyword_difficulty'] ?? null,
            'intent'             => isset($data['intent']) ? sanitize_text_field($data['intent']) : null,
        ];

        $formats = [
            '%d',
            '%s',
            '%d',
            '%d',
            '%s',
        ];

        $inserted = $this->wpdb->insert($this->keywords_table, $insert_data, $formats);
        if ($inserted === false) {
            return false;
        }

        $inserted_query = $this->wpdb->prepare(
            "SELECT * FROM {$this->keywords_table} WHERE id = %d LIMIT 1",
            $this->wpdb->insert_id
        );

        return $this->wpdb->get_row($inserted_query, ARRAY_A);
    }

    public function remove_keyword_from_cluster($cluster_id, $keyword) {
        $cluster_id = (int) $cluster_id;
        if ($cluster_id <= 0) {
            return false;
        }

        $keyword = trim(sanitize_text_field($keyword));
        if ($keyword === '') {
            return false;
        }

        $deleted = $this->wpdb->delete(
            $this->keywords_table,
            [
                'cluster_id' => $cluster_id,
                'keyword' => $keyword,
            ],
            [
                '%d',
                '%s',
            ]
        );

        return $deleted > 0;
    }

    public function add_page_to_cluster($cluster_id, $post_id, $role = 'support') {
        $cluster_id = (int) $cluster_id;
        $post_id = (int) $post_id;

        if ($cluster_id <= 0 || $post_id <= 0) {
            return false;
        }

        $cluster = $this->get_cluster($cluster_id);
        if ($cluster === null) {
            return false;
        }

        $post = get_post($post_id);
        if ($post === null) {
            return false;
        }

        $role = sanitize_text_field($role);
        if (!in_array($role, ['pillar', 'support'], true)) {
            $role = 'support';
        }

        $existing_query = $this->wpdb->prepare(
            "SELECT * FROM {$this->pages_table} WHERE cluster_id = %d AND post_id = %d LIMIT 1",
            $cluster_id,
            $post_id
        );
        $existing_page = $this->wpdb->get_row($existing_query, ARRAY_A);

        if ($existing_page !== null) {
            return $existing_page;
        }

        $inserted = $this->wpdb->insert(
            $this->pages_table,
            [
                'cluster_id' => $cluster_id,
                'post_id'    => $post_id,
                'role'       => $role,
            ],
            [
                '%d',
                '%d',
                '%s',
            ]
        );

        if ($inserted === false) {
            return false;
        }

        $inserted_query = $this->wpdb->prepare(
            "SELECT * FROM {$this->pages_table} WHERE id = %d LIMIT 1",
            $this->wpdb->insert_id
        );

        return $this->wpdb->get_row($inserted_query, ARRAY_A);
    }

    public function remove_page_from_cluster($cluster_id, $post_id) {
        $cluster_id = (int) $cluster_id;
        $post_id = (int) $post_id;

        if ($cluster_id <= 0 || $post_id <= 0) {
            return false;
        }

        $deleted = $this->wpdb->delete(
            $this->pages_table,
            [
                'cluster_id' => $cluster_id,
                'post_id'    => $post_id,
            ],
            [
                '%d',
                '%d',
            ]
        );

        return $deleted > 0;
    }

    public function get_cluster_pages($cluster_id, $args = []) {
        $cluster_id = (int) $cluster_id;
        if ($cluster_id <= 0) {
            return [];
        }

        $defaults = [
            'role'    => null,
            'limit'   => 100,
            'offset'  => 0,
            'orderby' => 'id',
            'order'   => 'ASC',
        ];

        $args = wp_parse_args($args, $defaults);

        $allowed_orderby = ['id', 'post_id', 'role', 'created_at'];
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'id';

        $order = strtoupper((string) $args['order']);
        if (!in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'ASC';
        }

        $limit = (int) $args['limit'];
        $offset = (int) $args['offset'];

        if ($limit < 0) {
            $limit = 0;
        }

        if ($offset < 0) {
            $offset = 0;
        }

        $where_sql = 'WHERE cluster_id = %d';
        $prepare_args = [$cluster_id];

        if ($args['role'] !== null && $args['role'] !== '') {
            $where_sql .= ' AND role = %s';
            $prepare_args[] = sanitize_text_field($args['role']);
        }

        $sql = "SELECT * FROM {$this->pages_table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $prepare_args[] = $limit;
        $prepare_args[] = $offset;

        $query = $this->wpdb->prepare($sql, $prepare_args);
        $pages = $this->wpdb->get_results($query, ARRAY_A);

        return $pages ?: [];
    }
}
