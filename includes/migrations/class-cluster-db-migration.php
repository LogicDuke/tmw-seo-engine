<?php

if (! defined('ABSPATH')) {
    exit;
}

class TMW_Cluster_DB_Migration {
    const SCHEMA_VERSION = 1;
    const OPTION_KEY     = 'tmw_cluster_schema_version';

    public function maybe_migrate() {
        $stored_version = (int) get_option(self::OPTION_KEY, 0);

        if ($stored_version < self::SCHEMA_VERSION) {
            $this->run_migration();
            update_option(self::OPTION_KEY, self::SCHEMA_VERSION);
        }
    }

    private function run_migration() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $schema_sql      = self::get_schema_sql($charset_collate);

        dbDelta(implode("\n", $schema_sql));
    }

    private static function get_schema_sql($charset_collate) {
        global $wpdb;

        $prefix = $wpdb->prefix;

        return array(
            "CREATE TABLE {$prefix}tmw_clusters (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(191) NOT NULL,
                slug VARCHAR(191) NOT NULL,
                status VARCHAR(50) DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY slug (slug)
            ) {$charset_collate};",
            "CREATE TABLE {$prefix}tmw_cluster_keywords (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                cluster_id BIGINT UNSIGNED NOT NULL,
                keyword VARCHAR(255) NOT NULL,
                search_volume INT DEFAULT NULL,
                keyword_difficulty INT DEFAULT NULL,
                intent VARCHAR(50) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY cluster_id (cluster_id),
                KEY keyword (keyword)
            ) {$charset_collate};",
            "CREATE TABLE {$prefix}tmw_cluster_pages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                cluster_id BIGINT UNSIGNED NOT NULL,
                post_id BIGINT UNSIGNED NOT NULL,
                role VARCHAR(50) DEFAULT 'support',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY cluster_post (cluster_id, post_id),
                KEY cluster_id (cluster_id),
                KEY post_id (post_id)
            ) {$charset_collate};",
            "CREATE TABLE {$prefix}tmw_cluster_metrics (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                cluster_id BIGINT UNSIGNED NOT NULL,
                impressions BIGINT DEFAULT 0,
                clicks BIGINT DEFAULT 0,
                avg_position FLOAT DEFAULT NULL,
                recorded_at DATE NOT NULL,
                PRIMARY KEY  (id),
                KEY cluster_id (cluster_id),
                KEY recorded_at (recorded_at)
            ) {$charset_collate};",
        );
    }
}
