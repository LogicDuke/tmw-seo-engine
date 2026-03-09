<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Intelligence Core — Phase 1 schema.
 *
 * Design guarantees:
 * - Fully additive.
 * - No destructive changes.
 * - WordPress-safe (no foreign keys).
 */
if (!class_exists('TMW_Intelligence_DB_Migration', false)) {
class TMW_Intelligence_DB_Migration {
    const SCHEMA_VERSION = 1;
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

        // Backward-compatible fallback.
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
        ];
    }
}
}
