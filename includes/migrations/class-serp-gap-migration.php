<?php
/**
 * SERP Gap DB Migration — v1
 *
 * Creates the tmw_serp_gaps table if it does not exist.
 * Additive-only: never drops columns, never truncates data.
 *
 * @since 4.6.3
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'TMW_Serp_Gap_Migration', false ) ) {
class TMW_Serp_Gap_Migration {

    const SCHEMA_VERSION = 1;
    const OPTION_KEY     = 'tmw_serp_gap_schema_version';

    public static function maybe_migrate(): void {
        $stored = (int) get_option( self::OPTION_KEY, 0 );

        if ( $stored >= self::SCHEMA_VERSION ) {
            return;
        }

        self::run();
        update_option( self::OPTION_KEY, self::SCHEMA_VERSION, true );

        if ( class_exists( 'TMWSEO\\Engine\\Logs' ) ) {
            \TMWSEO\Engine\Logs::info( 'migration', '[TMW] SERP Gap migration v1 complete: tmw_serp_gaps table created.' );
        }
    }

    private static function run(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        if ( class_exists( 'TMWSEO\\Engine\\SerpGaps\\SerpGapStorage' ) ) {
            \TMWSEO\Engine\SerpGaps\SerpGapStorage::maybe_create_table();
            return;
        }

        // Fallback: inline CREATE so the migration is self-contained even if
        // the storage class was not loaded yet (e.g. during activation).
        $table           = $wpdb->prefix . 'tmw_serp_gaps';
        $charset_collate = $wpdb->get_charset_collate();

        dbDelta( "CREATE TABLE {$table} (
            id                   BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword              VARCHAR(255)        NOT NULL,
            serp_gap_score       DECIMAL(6,2)        NOT NULL DEFAULT 0,
            gap_types            VARCHAR(255)        NOT NULL DEFAULT '',
            exact_match_score    TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            modifier_score       TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            intent_score         TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            specificity_score    TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            weak_serp_score      TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            exact_match_count    TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            modifier_misses      VARCHAR(512)        NOT NULL DEFAULT '',
            intent_mismatch_flag TINYINT(1)          NOT NULL DEFAULT 0,
            specificity_gap_flag TINYINT(1)          NOT NULL DEFAULT 0,
            reason               TEXT                         NULL,
            suggested_page_type  VARCHAR(100)        NOT NULL DEFAULT '',
            suggested_title_angle VARCHAR(255)       NOT NULL DEFAULT '',
            suggested_h1_angle   VARCHAR(255)        NOT NULL DEFAULT '',
            source               VARCHAR(50)         NOT NULL DEFAULT 'manual',
            status               VARCHAR(30)         NOT NULL DEFAULT 'new',
            search_volume        INT(11)                      NULL,
            difficulty           DECIMAL(6,2)                 NULL,
            opportunity_score    DECIMAL(8,2)                 NULL,
            serp_items_json      LONGTEXT                     NULL,
            last_scanned_at      DATETIME                     NULL,
            created_at           DATETIME            NOT NULL,
            updated_at           DATETIME            NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY keyword_unique (keyword),
            KEY score_status (serp_gap_score, status),
            KEY source_status (source, status),
            KEY gap_types_idx (gap_types(50))
        ) {$charset_collate};" );
    }
}
}
