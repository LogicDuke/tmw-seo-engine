<?php
/**
 * TMW SEO Engine — Architecture Reset
 *
 * Safe, archive-based reset for the seed/candidate/raw keyword infrastructure.
 * This class performs the "reset to zero" operation described in the architecture review.
 *
 * SAFETY RULES:
 * - Old tables are ARCHIVED (renamed), never deleted.
 * - Post meta (live keyword-page assignments) is NEVER touched.
 * - tmw_keyword_candidates (working keywords) is NOT archived — it contains live assignments.
 * - Only seeds, expansion candidates, and raw keyword tables are reset.
 * - Content generation is automatically paused during reset.
 * - Model root seeds are automatically restored.
 * - Proven manual seeds (ROI > 0 or low zero-yield) are restored from archive.
 * - Starter pack is registered once with version tracking.
 *
 * @package TMWSEO\Engine\Keywords
 * @since   5.0.0
 */

namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Content\ContentGenerationGate;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ArchitectureReset {

    private const RESET_LOG_OPTION      = 'tmwseo_architecture_reset_log';
    private const STARTER_PACK_VERSION  = 'v2';
    private const STARTER_PACK_BATCH_ID = 'starter_pack_v2';

    /**
     * Execute the full architecture reset.
     *
     * @return array{
     *   success: bool,
     *   archived_tables: string[],
     *   model_roots_restored: int,
     *   manual_seeds_restored: int,
     *   starter_pack_registered: int,
     *   errors: string[]
     * }
     */
    public static function execute_reset(): array {
        global $wpdb;

        if ( ! current_user_can( 'manage_options' ) ) {
            return [ 'success' => false, 'errors' => [ 'insufficient_permissions' ] ];
        }

        $timestamp = gmdate( 'Ymd_His' );
        $result    = [
            'success'                 => true,
            'timestamp'               => $timestamp,
            'archived_tables'         => [],
            'model_roots_restored'    => 0,
            'manual_seeds_restored'   => 0,
            'starter_pack_registered' => 0,
            'errors'                  => [],
        ];

        Logs::info( 'reset', '[TMW-RESET] Architecture reset started', [
            'user'      => get_current_user_id(),
            'timestamp' => $timestamp,
        ] );

        // ── Step 0: Pause content generation ──────────────────────
        ContentGenerationGate::pause_all();

        // ── Step 1: Archive old tables ────────────────────────────
        $tables_to_archive = [
            $wpdb->prefix . 'tmwseo_seeds',
            $wpdb->prefix . 'tmw_seed_expansion_candidates',
            $wpdb->prefix . 'tmw_keyword_raw',
        ];

        foreach ( $tables_to_archive as $table ) {
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( $exists !== $table ) {
                continue;
            }

            $archive_name = $table . '_archive_' . $timestamp;
            $renamed = $wpdb->query( "RENAME TABLE `{$table}` TO `{$archive_name}`" );

            if ( $renamed === false ) {
                $result['errors'][] = 'failed_to_archive:' . $table . ':' . $wpdb->last_error;
                $result['success'] = false;
            } else {
                $result['archived_tables'][] = $archive_name;
                Logs::info( 'reset', '[TMW-RESET] Table archived', [
                    'original' => $table,
                    'archive'  => $archive_name,
                ] );
            }
        }

        if ( ! $result['success'] ) {
            Logs::error( 'reset', '[TMW-RESET] Reset aborted due to archive failures', $result );
            return $result;
        }

        // ── Step 2: Recreate clean tables ─────────────────────────
        self::create_seeds_table();
        self::create_expansion_candidates_table();
        self::create_keyword_raw_table();

        // ── Step 3: Restore model root seeds ──────────────────────
        $model_ids = get_posts( [
            'post_type'      => 'model',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );

        if ( is_array( $model_ids ) ) {
            foreach ( $model_ids as $model_id ) {
                $model_name = trim( (string) get_the_title( (int) $model_id ) );
                if ( $model_name === '' ) {
                    continue;
                }

                $ok = SeedRegistry::register_trusted_seed(
                    $model_name,
                    'model_root',
                    'model',
                    (int) $model_id,
                    'model_root',
                    1,
                    'model_root_restore_' . $timestamp,
                    'Architecture Reset: model root restore'
                );

                if ( $ok ) {
                    $result['model_roots_restored']++;
                }
            }
        }

        Logs::info( 'reset', '[TMW-RESET] Model root seeds restored', [
            'count' => $result['model_roots_restored'],
        ] );

        // ── Step 4: Restore proven manual seeds from archive ──────
        $seeds_archive = $wpdb->prefix . 'tmwseo_seeds_archive_' . $timestamp;
        $has_roi = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'roi_score' LIMIT 1",
            $seeds_archive
        ) );

        if ( $has_roi ) {
            $proven_manual = $wpdb->get_results(
                "SELECT seed, entity_type, entity_id, seed_type, priority
                 FROM `{$seeds_archive}`
                 WHERE source = 'manual'
                   AND ( roi_score > 0 OR consecutive_zero_yield < 3 )
                 ORDER BY roi_score DESC, id ASC
                 LIMIT 200",
                ARRAY_A
            );
        } else {
            // Pre-ROI archive: restore all manual seeds
            $proven_manual = $wpdb->get_results(
                "SELECT seed, entity_type, entity_id, seed_type, priority
                 FROM `{$seeds_archive}`
                 WHERE source = 'manual'
                 ORDER BY id ASC
                 LIMIT 200",
                ARRAY_A
            );
        }

        if ( is_array( $proven_manual ) ) {
            foreach ( $proven_manual as $row ) {
                $ok = SeedRegistry::register_trusted_seed(
                    (string) $row['seed'],
                    'manual',
                    (string) ( $row['entity_type'] ?? 'system' ),
                    (int) ( $row['entity_id'] ?? 0 ),
                    (string) ( $row['seed_type'] ?? 'general' ),
                    (int) ( $row['priority'] ?? 1 ),
                    'manual_restore_' . $timestamp,
                    'Architecture Reset: proven manual seed restore'
                );

                if ( $ok ) {
                    $result['manual_seeds_restored']++;
                }
            }
        }

        Logs::info( 'reset', '[TMW-RESET] Proven manual seeds restored', [
            'count' => $result['manual_seeds_restored'],
        ] );

        // ── Step 5: Install versioned starter pack ────────────────
        $already_installed = get_option( 'tmwseo_starter_pack_version', '' );
        if ( $already_installed !== self::STARTER_PACK_VERSION ) {
            $starter = SeedRegistry::get_starter_pack();
            $registered = SeedRegistry::register_many(
                $starter,
                'static_curated',
                'system',
                0,
                'starter_pack',
                2
            );

            $result['starter_pack_registered'] = (int) ( $registered['registered'] ?? 0 );

            // Tag provenance
            SeedRegistry::tag_starter_pack_provenance( $starter );

            // Record version so it never runs again
            update_option( 'tmwseo_starter_pack_version', self::STARTER_PACK_VERSION );

            Logs::info( 'reset', '[TMW-RESET] Starter pack installed', [
                'version'    => self::STARTER_PACK_VERSION,
                'registered' => $result['starter_pack_registered'],
            ] );
        }

        // ── Step 6: Block static seed re-registration ─────────────
        update_option( 'tmwseo_block_static_curated', 1 );

        // ── Step 7: Ensure all auto-builders are OFF ──────────────
        update_option( 'tmwseo_builder_model_phrases_enabled', 0 );
        update_option( 'tmwseo_builder_tag_phrases_enabled', 0 );
        update_option( 'tmwseo_builder_video_phrases_enabled', 0 );
        update_option( 'tmwseo_builder_category_phrases_enabled', 0 );
        update_option( 'tmwseo_builder_competitor_phrases_enabled', 0 );

        // ── Log final result ──────────────────────────────────────
        $result['timestamp'] = $timestamp;
        update_option( self::RESET_LOG_OPTION, $result );

        Logs::info( 'reset', '[TMW-RESET] Architecture reset completed', $result );

        return $result;
    }

    /**
     * Get the last reset log.
     */
    public static function get_last_reset_log(): array {
        $log = get_option( self::RESET_LOG_OPTION, [] );
        return is_array( $log ) ? $log : [];
    }

    /**
     * Check if system is in post-reset mode (content paused, awaiting first clean cycle).
     */
    public static function is_in_reset_mode(): bool {
        return ContentGenerationGate::is_paused();
    }

    // ── Table creation (mirrors existing schema) ──────────────

    private static function create_seeds_table(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'tmwseo_seeds';
        $charset = $wpdb->get_charset_collate();

        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            seed VARCHAR(500) NOT NULL,
            hash CHAR(32) NOT NULL,
            source VARCHAR(50) NOT NULL DEFAULT 'manual',
            seed_type VARCHAR(50) NOT NULL DEFAULT 'general',
            priority INT NOT NULL DEFAULT 1,
            entity_type VARCHAR(50) NOT NULL DEFAULT 'system',
            entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            last_used DATETIME DEFAULT NULL,
            last_expanded_at DATETIME DEFAULT NULL,
            expansion_count INT UNSIGNED NOT NULL DEFAULT 0,
            net_new_yielded INT UNSIGNED NOT NULL DEFAULT 0,
            duplicates_returned INT UNSIGNED NOT NULL DEFAULT 0,
            estimated_spend_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
            last_provider VARCHAR(50) DEFAULT NULL,
            cooldown_until DATETIME DEFAULT NULL,
            roi_score DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            consecutive_zero_yield INT UNSIGNED NOT NULL DEFAULT 0,
            import_batch_id VARCHAR(100) DEFAULT NULL,
            import_source_label VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY hash (hash),
            KEY source (source),
            KEY seed_type (seed_type),
            KEY entity_type_entity_id (entity_type, entity_id),
            KEY cooldown_until (cooldown_until),
            KEY roi_score (roi_score)
        ) {$charset}" );
    }

    private static function create_expansion_candidates_table(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'tmw_seed_expansion_candidates';
        $charset = $wpdb->get_charset_collate();

        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            phrase VARCHAR(500) NOT NULL,
            hash CHAR(32) NOT NULL,
            source VARCHAR(50) NOT NULL,
            generation_rule VARCHAR(100) DEFAULT NULL,
            entity_type VARCHAR(50) NOT NULL DEFAULT 'system',
            entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            batch_id VARCHAR(100) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            quality_score DECIMAL(5,2) DEFAULT NULL,
            duplicate_flag TINYINT NOT NULL DEFAULT 0,
            intent_guess VARCHAR(50) DEFAULT NULL,
            provenance_meta TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            reviewed_by BIGINT UNSIGNED DEFAULT NULL,
            rejection_reason VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY hash (hash),
            KEY status (status),
            KEY source (source),
            KEY batch_id (batch_id),
            KEY entity_type_entity_id (entity_type, entity_id)
        ) {$charset}" );
    }

    private static function create_keyword_raw_table(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'tmw_keyword_raw';
        $charset = $wpdb->get_charset_collate();

        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword VARCHAR(500) NOT NULL,
            source VARCHAR(100) DEFAULT NULL,
            source_ref VARCHAR(500) DEFAULT NULL,
            volume INT DEFAULT NULL,
            cpc DECIMAL(8,2) DEFAULT NULL,
            competition DECIMAL(5,4) DEFAULT NULL,
            raw TEXT DEFAULT NULL,
            discovered_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY keyword (keyword(191)),
            KEY source (source)
        ) {$charset}" );
    }
}
