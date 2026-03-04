<?php
namespace TMW\SEO\Lighthouse;

if (!defined('ABSPATH')) { exit; }

class Schema {
    public static function create_or_update_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $targets = $wpdb->prefix . 'tmw_lighthouse_targets';
        $runs = $wpdb->prefix . 'tmw_lighthouse_runs';

        $sql_targets = "CREATE TABLE {$targets} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            url TEXT NOT NULL,
            post_id BIGINT UNSIGNED NULL,
            type VARCHAR(50) DEFAULT 'post',
            last_scanned_mobile DATETIME NULL,
            last_scanned_desktop DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY post_id (post_id),
            KEY type (type)
        ) {$charset_collate};";

        $sql_runs = "CREATE TABLE {$runs} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            target_id BIGINT UNSIGNED NOT NULL,
            strategy ENUM('mobile','desktop') NOT NULL,
            lighthouse_version VARCHAR(20) NOT NULL,
            performance_score FLOAT,
            seo_score FLOAT,
            lcp FLOAT,
            cls FLOAT,
            inp FLOAT,
            raw_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (target_id),
            INDEX (strategy),
            INDEX (created_at)
        ) {$charset_collate};";

        dbDelta($sql_targets);
        dbDelta($sql_runs);
    }
}
