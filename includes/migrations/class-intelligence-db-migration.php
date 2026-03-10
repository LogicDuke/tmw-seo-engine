<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('TMW_Intelligence_DB_Migration', false)) {
class TMW_Intelligence_DB_Migration {
    const SCHEMA_VERSION = 3;
    const OPTION_KEY     = 'tmw_intelligence_schema_version';

    public static function maybe_migrate() {
        $stored_version = (int) get_option(self::OPTION_KEY, 0);

        if ($stored_version < self::SCHEMA_VERSION) {
            self::run_migration();
            update_option(self::OPTION_KEY, self::SCHEMA_VERSION);
        }

        if (class_exists('TMWSEO\Engine\Schema') && method_exists('TMWSEO\Engine\Schema', 'ensure_intelligence_schema')) {
            \TMWSEO\Engine\Schema::ensure_intelligence_schema();
            return;
        }

        if (class_exists('TMWSEO\Engine\Schema') && method_exists('TMWSEO\Engine\Schema', 'reconcile_required_intelligence_tables')) {
            \TMWSEO\Engine\Schema::reconcile_required_intelligence_tables();
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

        return [
            "CREATE TABLE {$prefix}tmw_intel_runs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                seeds LONGTEXT NULL,
                settings LONGTEXT NULL,
                totals LONGTEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                PRIMARY KEY  (id),
                KEY created_at (created_at),
                KEY status (status)
            ) {$charset_collate};",

            "CREATE TABLE {$prefix}tmw_intel_keywords (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                run_id BIGINT UNSIGNED NOT NULL,
                keyword VARCHAR(255) NOT NULL,
                source VARCHAR(191) NOT NULL,
                seed VARCHAR(255) NULL,
                volume INT NULL,
                kd DECIMAL(6,2) NULL,
                intent VARCHAR(30) NULL,
                kd_bucket VARCHAR(10) NULL,
                opportunity DECIMAL(6,2) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY run_keyword (run_id, keyword),
                KEY run_id (run_id),
                KEY kd_bucket (kd_bucket),
                KEY opportunity (opportunity)
            ) {$charset_collate};",

            "CREATE TABLE {$prefix}tmwseo_top_opportunities (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                keyword VARCHAR(255) NOT NULL,
                search_volume INT(11) NOT NULL DEFAULT 0,
                difficulty DECIMAL(6,2) NOT NULL DEFAULT 0,
                serp_weakness DECIMAL(6,4) NOT NULL DEFAULT 0,
                cluster_id VARCHAR(64) NOT NULL DEFAULT '',
                opportunity_score DECIMAL(10,4) NOT NULL DEFAULT 0,
                materialized_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY score_volume (opportunity_score, search_volume),
                KEY cluster_id (cluster_id),
                KEY keyword (keyword)
            ) {$charset_collate};",

            "CREATE TABLE {$prefix}tmwseo_cluster_summary (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                cluster_id VARCHAR(64) NOT NULL,
                cluster_size INT(11) NOT NULL DEFAULT 0,
                avg_volume DECIMAL(10,2) NOT NULL DEFAULT 0,
                avg_difficulty DECIMAL(6,2) NOT NULL DEFAULT 0,
                materialized_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY cluster_id (cluster_id),
                KEY cluster_size (cluster_size)
            ) {$charset_collate};",

            "CREATE TABLE {$prefix}tmwseo_entity_keyword_map (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                keyword VARCHAR(255) NOT NULL,
                entity_type VARCHAR(32) NOT NULL,
                entity_id BIGINT(20) UNSIGNED NOT NULL,
                materialized_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY keyword_entity (keyword, entity_type, entity_id),
                KEY entity_lookup (entity_type, entity_id),
                KEY keyword (keyword)
            ) {$charset_collate};",


            "CREATE TABLE {$prefix}tmw_seo_entities (
                entity_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                entity_type VARCHAR(50) NOT NULL,
                entity_name VARCHAR(191) NOT NULL,
                PRIMARY KEY  (entity_id),
                UNIQUE KEY entity_unique (entity_type, entity_name),
                KEY entity_lookup (entity_type)
            ) {$charset_collate};",

            "CREATE TABLE {$prefix}tmw_seo_entity_keyword (
                keyword_id BIGINT(20) UNSIGNED NOT NULL,
                entity_id BIGINT(20) UNSIGNED NOT NULL,
                PRIMARY KEY  (keyword_id, entity_id),
                KEY entity_lookup (entity_id),
                KEY keyword_lookup (keyword_id)
            ) {$charset_collate};",

            "CREATE TABLE {$prefix}tmwseo_keyword_trends (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                keyword VARCHAR(255) NOT NULL,
                current_position INT(11) NOT NULL DEFAULT 0,
                previous_position INT(11) NOT NULL DEFAULT 0,
                rank_change INT(11) NOT NULL DEFAULT 0,
                trend_score DECIMAL(10,2) NOT NULL DEFAULT 0,
                snapshot_week VARCHAR(12) NOT NULL,
                materialized_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY keyword_week (keyword, snapshot_week),
                KEY trend_score (trend_score),
                KEY rank_change (rank_change)
            ) {$charset_collate};",
        ];
    }
}
}
