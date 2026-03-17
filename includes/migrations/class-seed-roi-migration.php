<?php
/**
 * TMW SEO Engine — Seed ROI Tracking Migration
 *
 * Adds per-seed expansion metrics and cooldown columns to tmwseo_seeds.
 * Fully additive — no existing columns or data are modified.
 *
 * New columns:
 *   last_expanded_at       — when this seed was last sent to a paid provider
 *   expansion_count        — total number of times this seed has been expanded
 *   net_new_yielded        — cumulative net-new keywords produced by this seed
 *   duplicates_returned    — cumulative duplicate keywords returned for this seed
 *   estimated_spend_usd    — estimated total DataForSEO spend on this seed
 *   last_provider          — last provider used to expand this seed
 *   cooldown_until         — do not re-expand before this timestamp
 *   roi_score              — computed ROI metric (higher = more productive seed)
 *   consecutive_zero_yield — consecutive expansions that produced zero net-new
 *
 * Rollback: DROP the columns if downgrading (no data loss in other columns).
 *
 * @package TMWSEO\Engine\Migrations
 * @since   4.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'TMW_Seed_ROI_Migration', false ) ) {
class TMW_Seed_ROI_Migration {

    const SCHEMA_VERSION = 1;
    const OPTION_KEY     = 'tmw_seed_roi_schema_version';

    public static function maybe_migrate(): void {
        $stored = (int) get_option( self::OPTION_KEY, 0 );

        if ( $stored < self::SCHEMA_VERSION ) {
            self::run_migration();
            update_option( self::OPTION_KEY, self::SCHEMA_VERSION );
        }
    }

    private static function run_migration(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'tmwseo_seeds';

        // Guard: only alter if the table exists and columns don't.
        $table_exists = (string) $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
        );

        if ( $table_exists !== $table ) {
            return; // Seeds table not created yet — skip; it will be created with these columns later.
        }

        $existing_columns = [];
        $col_rows = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
        if ( is_array( $col_rows ) ) {
            foreach ( $col_rows as $col ) {
                $existing_columns[] = (string) ( $col['Field'] ?? '' );
            }
        }

        $adds = [];

        if ( ! in_array( 'last_expanded_at', $existing_columns, true ) ) {
            $adds[] = 'ADD COLUMN last_expanded_at DATETIME DEFAULT NULL';
        }
        if ( ! in_array( 'expansion_count', $existing_columns, true ) ) {
            $adds[] = 'ADD COLUMN expansion_count INT UNSIGNED NOT NULL DEFAULT 0';
        }
        if ( ! in_array( 'net_new_yielded', $existing_columns, true ) ) {
            $adds[] = 'ADD COLUMN net_new_yielded INT UNSIGNED NOT NULL DEFAULT 0';
        }
        if ( ! in_array( 'duplicates_returned', $existing_columns, true ) ) {
            $adds[] = 'ADD COLUMN duplicates_returned INT UNSIGNED NOT NULL DEFAULT 0';
        }
        if ( ! in_array( 'estimated_spend_usd', $existing_columns, true ) ) {
            $adds[] = 'ADD COLUMN estimated_spend_usd DECIMAL(8,4) NOT NULL DEFAULT 0';
        }
        if ( ! in_array( 'last_provider', $existing_columns, true ) ) {
            $adds[] = 'ADD COLUMN last_provider VARCHAR(40) DEFAULT NULL';
        }
        if ( ! in_array( 'cooldown_until', $existing_columns, true ) ) {
            $adds[] = 'ADD COLUMN cooldown_until DATETIME DEFAULT NULL';
        }
        if ( ! in_array( 'roi_score', $existing_columns, true ) ) {
            $adds[] = 'ADD COLUMN roi_score DECIMAL(6,2) NOT NULL DEFAULT 0';
        }
        if ( ! in_array( 'consecutive_zero_yield', $existing_columns, true ) ) {
            $adds[] = 'ADD COLUMN consecutive_zero_yield INT UNSIGNED NOT NULL DEFAULT 0';
        }

        if ( empty( $adds ) ) {
            return; // All columns already exist.
        }

        $sql = "ALTER TABLE {$table} " . implode( ', ', $adds );
        $wpdb->query( $sql );

        // Add index on cooldown_until for efficient selection queries.
        $index_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'idx_cooldown_roi'",
            $table
        ) );

        if ( (int) $index_exists === 0 ) {
            $wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_cooldown_roi (cooldown_until, roi_score, last_expanded_at)" );
        }
    }
}
}
