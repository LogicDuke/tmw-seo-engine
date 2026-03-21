<?php
/**
 * TMW SEO Engine — Intelligence DB Migration
 * Version 4 (4.6.1 stabilization patch)
 *
 * v4 changes:
 *  - Adds (status, volume) composite index on tmw_keyword_candidates
 *  - Adds (needs_recluster, needs_rescore) index on tmw_keyword_candidates
 *  - Initialises keyword_review_queue_cap setting to 200 if absent
 *  - Clears stale tmwseo_kw_queue_full_since flag
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'TMW_Intelligence_DB_Migration', false ) ) {
class TMW_Intelligence_DB_Migration {
    const SCHEMA_VERSION = 4;
    const OPTION_KEY     = 'tmw_intelligence_schema_version';

    public static function maybe_migrate(): void {
        $stored_version = (int) get_option( self::OPTION_KEY, 0 );

        if ( $stored_version < self::SCHEMA_VERSION ) {
            self::run_migration( $stored_version );
            update_option( self::OPTION_KEY, self::SCHEMA_VERSION );
        }

        if ( class_exists( 'TMWSEO\\Engine\\Schema' ) && method_exists( 'TMWSEO\\Engine\\Schema', 'ensure_intelligence_schema' ) ) {
            \TMWSEO\Engine\Schema::ensure_intelligence_schema();
            return;
        }

        if ( class_exists( 'TMWSEO\\Engine\\Schema' ) && method_exists( 'TMWSEO\\Engine\\Schema', 'reconcile_required_intelligence_tables' ) ) {
            \TMWSEO\Engine\Schema::reconcile_required_intelligence_tables();
        }
    }

    private static function run_migration( int $from_version ): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        if ( $from_version < 2 ) { self::migrate_v2( $wpdb, $charset_collate ); }
        if ( $from_version < 3 ) { /* v3 was schema-reconcile only, handled by ensure_intelligence_schema */ }
        if ( $from_version < 4 ) { self::migrate_v4( $wpdb ); }
    }

    private static function migrate_v2( $wpdb, string $charset_collate ): void {
        dbDelta( implode( "\n", self::get_base_schema_sql( $wpdb->prefix, $charset_collate ) ) );
    }

    private static function migrate_v4( $wpdb ): void {
        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';

        // Add (status, volume) composite index — used by review-queue promotion queries.
        self::add_index_safe( $wpdb, $cand_table, 'status_volume', '(status, volume)' );

        // Add (needs_recluster, needs_rescore) index — used by dirty-keyword enqueue.
        self::add_index_safe( $wpdb, $cand_table, 'dirty_flags', '(needs_recluster, needs_rescore)' );

        // Initialise keyword_review_queue_cap to 200 if absent (was hard-coded 50).
        $existing_settings = get_option( 'tmwseo_engine_settings', [] );
        if ( is_array( $existing_settings ) && ! array_key_exists( 'keyword_review_queue_cap', $existing_settings ) ) {
            $existing_settings['keyword_review_queue_cap'] = 200;
            update_option( 'tmwseo_engine_settings', $existing_settings );
        }

        // Clear stale queue-full flag from pre-4.6.1 builds.
        delete_option( 'tmwseo_kw_queue_full_since' );

        if ( class_exists( 'TMWSEO\\Engine\\Logs' ) ) {
            \TMWSEO\Engine\Logs::info( 'migration', '[TMW] Migration v4 complete: indexes added, queue cap initialised.' );
        }
    }

    /**
     * Add a named index if it does not already exist.
     * Uses IF NOT EXISTS (MySQL 5.7.4+/MariaDB 10.1.4+) with a fallback.
     */
    private static function add_index_safe( $wpdb, string $table, string $index_name, string $columns ): void {
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( "ALTER TABLE `{$table}` ADD INDEX IF NOT EXISTS `{$index_name}` {$columns}" );

        if ( $wpdb->last_error ) {
            // IF NOT EXISTS not supported — check manually before adding.
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE table_schema = DATABASE()
                   AND table_name = %s AND index_name = %s",
                $table, $index_name
            ) );
            if ( ! $exists ) {
                $wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `{$index_name}` {$columns}" );
            }
        }
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
    }

    private static function get_base_schema_sql( string $prefix, string $charset_collate ): array {
        return [
            "CREATE TABLE {$prefix}tmw_intel_runs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                seeds LONGTEXT NULL, settings LONGTEXT NULL, totals LONGTEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                PRIMARY KEY (id), KEY created_at (created_at), KEY status (status)
            ) {$charset_collate};",

            "CREATE TABLE {$prefix}tmw_intel_keywords (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                run_id BIGINT UNSIGNED NOT NULL, keyword VARCHAR(255) NOT NULL,
                source VARCHAR(191) NOT NULL, seed VARCHAR(255) NULL, volume INT NULL,
                kd DECIMAL(6,2) NULL, intent VARCHAR(30) NULL, kd_bucket VARCHAR(10) NULL,
                opportunity DECIMAL(6,2) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id), UNIQUE KEY run_keyword (run_id, keyword),
                KEY run_id (run_id), KEY kd_bucket (kd_bucket), KEY opportunity (opportunity)
            ) {$charset_collate};",

            "CREATE TABLE {$prefix}tmwseo_top_opportunities (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                keyword VARCHAR(255) NOT NULL, search_volume INT(11) NOT NULL DEFAULT 0,
                difficulty DECIMAL(6,2) NOT NULL DEFAULT 0, serp_weakness DECIMAL(6,4) NOT NULL DEFAULT 0,
                cluster_id VARCHAR(64) NOT NULL DEFAULT '', opportunity_score DECIMAL(10,4) NOT NULL DEFAULT 0,
                materialized_at DATETIME NOT NULL,
                PRIMARY KEY (id), KEY score_volume (opportunity_score, search_volume),
                KEY cluster_id (cluster_id), KEY keyword (keyword)
            ) {$charset_collate};",

            "CREATE TABLE {$prefix}tmwseo_cluster_summary (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                cluster_id VARCHAR(64) NOT NULL, cluster_size INT(11) NOT NULL DEFAULT 0,
                avg_volume DECIMAL(10,2) NOT NULL DEFAULT 0, avg_difficulty DECIMAL(6,2) NOT NULL DEFAULT 0,
                materialized_at DATETIME NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY cluster_id (cluster_id), KEY cluster_size (cluster_size)
            ) {$charset_collate};",

            "CREATE TABLE {$prefix}tmwseo_entity_keyword_map (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                keyword VARCHAR(255) NOT NULL, entity_type VARCHAR(32) NOT NULL,
                entity_id BIGINT(20) UNSIGNED NOT NULL, materialized_at DATETIME NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY keyword_entity (keyword, entity_type, entity_id),
                KEY entity_lookup (entity_type, entity_id), KEY keyword (keyword)
            ) {$charset_collate};",

            "CREATE TABLE {$prefix}tmw_seo_entities (
                entity_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                entity_type VARCHAR(50) NOT NULL, entity_name VARCHAR(191) NOT NULL,
                PRIMARY KEY (entity_id), UNIQUE KEY entity_unique (entity_type, entity_name),
                KEY entity_lookup (entity_type)
            ) {$charset_collate};",

            "CREATE TABLE {$prefix}tmw_seo_entity_keyword (
                keyword_id BIGINT(20) UNSIGNED NOT NULL, entity_id BIGINT(20) UNSIGNED NOT NULL,
                PRIMARY KEY (keyword_id, entity_id),
                KEY entity_lookup (entity_id), KEY keyword_lookup (keyword_id)
            ) {$charset_collate};",

            "CREATE TABLE {$prefix}tmwseo_keyword_trends (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                keyword VARCHAR(255) NOT NULL, current_position INT(11) NOT NULL DEFAULT 0,
                previous_position INT(11) NOT NULL DEFAULT 0, rank_change INT(11) NOT NULL DEFAULT 0,
                trend_score DECIMAL(10,2) NOT NULL DEFAULT 0, snapshot_week VARCHAR(12) NOT NULL,
                materialized_at DATETIME NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY keyword_week (keyword, snapshot_week),
                KEY trend_score (trend_score), KEY rank_change (rank_change)
            ) {$charset_collate};",
        ];
    }
}
}
