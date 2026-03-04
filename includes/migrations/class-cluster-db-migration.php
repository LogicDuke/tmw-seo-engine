<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Cluster-Based Domination Architecture - Phase 1 Migration Notes
 *
 * This migration implements Phase 1 of the cluster-based domination architecture.
 *
 * Design guarantees:
 * - This migration is fully additive.
 * - No existing tables are modified.
 * - No foreign key constraints are used (WordPress-safe design).
 *
 * Rollback safety:
 * - Downgrading to 3.0.0-alpha will not break anything.
 * - Older plugin versions will simply ignore these new tables.
 *
 * Manual rollback instructions (if you explicitly want to remove cluster data):
 * DROP TABLE wp_tmw_clusters;
 * DROP TABLE wp_tmw_cluster_keywords;
 * DROP TABLE wp_tmw_cluster_pages;
 * DROP TABLE wp_tmw_cluster_metrics;
 * DELETE FROM wp_options WHERE option_name = 'tmw_cluster_schema_version';
 *
 * Explicit policy:
 * - We DO NOT auto-drop tables on downgrade.
 * - Data integrity > destructive downgrade.
 *
 * Documentation only:
 * - This block is explanatory guidance only.
 * - No functional behavior changes are introduced by this documentation.
 */

class TMW_Cluster_DB_Migration {
    const SCHEMA_VERSION = 1;
    const OPTION_KEY     = 'tmw_cluster_schema_version';

    public static function maybe_migrate() {
        $stored_version = (int) get_option(self::OPTION_KEY, 0);

        if ($stored_version < self::SCHEMA_VERSION) {
            self::run_migration();
            update_option(self::OPTION_KEY, self::SCHEMA_VERSION);
        }
    }

    private static function run_migration() {
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
