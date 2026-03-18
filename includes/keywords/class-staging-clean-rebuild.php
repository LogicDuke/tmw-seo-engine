<?php
/**
 * TMW SEO Engine — Staging Clean Rebuild From Zero
 *
 * Deterministic staging/debug helper for rebuilding the v5.2 keyword lifecycle
 * baseline without crossing into approval, assignment, or content generation.
 *
 * @package TMWSEO\Engine\Keywords
 * @since   5.2.0
 */

namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Content\ContentGenerationGate;
use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\TrustPolicy;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class StagingCleanRebuild {

    private const STAGING_FLAGS_OPTION = 'tmwseo_staging_flags';
    private const LAST_RESULT_OPTION   = 'tmwseo_last_clean_rebuild_result';

    /**
     * Run the staging-only clean rebuild sequence.
     *
     * @return array<string,mixed>
     */
    public static function execute( int $user_id ): array {
        global $wpdb;

        $result = [
            'success'                   => false,
            'errors'                    => [],
            'legacy_new_migrated'       => 0,
            'generator_archived'        => 0,
            'discovery_parked'          => 0,
            'manual_jobs_deleted'       => 0,
            'reset'                     => [],
            'cycle_triggered'           => false,
            'content_generation_paused' => false,
            'before'                    => self::capture_counts(),
            'after'                     => [],
        ];

        if ( ! current_user_can( 'manage_options' ) ) {
            $result['errors'][] = 'insufficient_permissions';
            return self::finalize_result( $result, $user_id );
        }

        if ( ! TrustPolicy::is_manual_only() ) {
            $result['errors'][] = 'manual_only_required';
            Logs::warn( 'keywords', '[TMW-CLEAN-REBUILD] Aborted: manual-only trust policy not active', [
                'user_id' => $user_id,
            ] );
            return self::finalize_result( $result, $user_id );
        }

        $now_mysql = current_time( 'mysql' );
        $note_time = gmdate( 'c' );

        Logs::info( 'keywords', '[TMW-CLEAN-REBUILD] Starting staging clean rebuild from zero', [
            'user_id' => $user_id,
            'before'  => $result['before'],
        ] );

        $flags = get_option( self::STAGING_FLAGS_OPTION, [] );
        if ( ! is_array( $flags ) ) {
            $flags = [];
        }
        $flags['master_disable_background'] = 1;
        update_option( self::STAGING_FLAGS_OPTION, $flags, false );
        Logs::info( 'keywords', '[TMW-CLEAN-REBUILD] Enforced staging quiet mode', [
            'user_id' => $user_id,
            'flags'   => $flags,
        ] );

        $jobs_table = $wpdb->prefix . 'tmwseo_jobs';
        if ( self::table_exists( $jobs_table ) ) {
            $result['manual_jobs_deleted'] = (int) $wpdb->query(
                "DELETE FROM {$jobs_table} WHERE status = 'pending' AND payload_json LIKE '%manual_keyword_cycle%'"
            );
            Logs::info( 'keywords', '[TMW-CLEAN-REBUILD] Pending manual keyword cycle jobs deleted', [
                'user_id' => $user_id,
                'deleted' => $result['manual_jobs_deleted'],
                'table'   => $jobs_table,
            ] );
        } else {
            Logs::info( 'keywords', '[TMW-CLEAN-REBUILD] Jobs table not present; skipping manual job cleanup', [
                'user_id' => $user_id,
                'table'   => $jobs_table,
            ] );
        }

        $candidate_table = $wpdb->prefix . 'tmw_keyword_candidates';
        if ( self::table_exists( $candidate_table ) ) {
            $result['legacy_new_migrated'] = (int) $wpdb->query( $wpdb->prepare(
                "UPDATE {$candidate_table}
                 SET status = 'discovered', updated_at = %s,
                     notes = CONCAT(IFNULL(notes,''), %s)
                 WHERE status = 'new'",
                $now_mysql,
                ' | legacy_new_migrated:' . $note_time . ':user_' . $user_id
            ) );
            Logs::info( 'keywords', '[TMW-CLEAN-REBUILD] Legacy discovery rows migrated', [
                'user_id' => $user_id,
                'count'   => $result['legacy_new_migrated'],
            ] );

            $result['discovery_parked'] = (int) $wpdb->query( $wpdb->prepare(
                "UPDATE {$candidate_table}
                 SET status = 'scored', updated_at = %s,
                     notes = CONCAT(IFNULL(notes,''), %s)
                 WHERE status = 'queued_for_review'",
                $now_mysql,
                ' | parked_by_clean_rebuild:' . $note_time . ':user_' . $user_id
            ) );
            Logs::info( 'keywords', '[TMW-CLEAN-REBUILD] Queued discovery rows parked back to scored', [
                'user_id' => $user_id,
                'count'   => $result['discovery_parked'],
            ] );
        } else {
            Logs::info( 'keywords', '[TMW-CLEAN-REBUILD] Keyword candidate table not present; skipping legacy/discovery updates', [
                'user_id' => $user_id,
                'table'   => $candidate_table,
            ] );
        }

        $generator_table = $wpdb->prefix . 'tmw_seed_expansion_candidates';
        if ( self::table_exists( $generator_table ) ) {
            $result['generator_archived'] = (int) $wpdb->query(
                "UPDATE {$generator_table}
                 SET status = 'archived'
                 WHERE status IN ('pending', 'fast_track')"
            );
            Logs::info( 'keywords', '[TMW-CLEAN-REBUILD] Generator review noise archived', [
                'user_id' => $user_id,
                'count'   => $result['generator_archived'],
            ] );
        } else {
            Logs::info( 'keywords', '[TMW-CLEAN-REBUILD] Generator table not present; skipping archival', [
                'user_id' => $user_id,
                'table'   => $generator_table,
            ] );
        }

        $result['reset'] = ArchitectureReset::execute_reset();
        Logs::info( 'keywords', '[TMW-CLEAN-REBUILD] Architecture reset finished', [
            'user_id' => $user_id,
            'reset'   => $result['reset'],
        ] );

        if ( empty( $result['reset']['success'] ) ) {
            $result['errors'][] = 'architecture_reset_failed';
            if ( ! empty( $result['reset']['errors'] ) && is_array( $result['reset']['errors'] ) ) {
                $result['errors'] = array_merge( $result['errors'], array_map( 'strval', $result['reset']['errors'] ) );
            }
            ContentGenerationGate::pause_all();
            $result['content_generation_paused'] = ContentGenerationGate::is_paused();
            $result['after']                     = self::capture_counts();
            Logs::error( 'keywords', '[TMW-CLEAN-REBUILD] Aborted after reset failure', $result );
            return self::finalize_result( $result, $user_id );
        }

        try {
            UnifiedKeywordWorkflowService::run_cycle([
                'payload' => [
                    'trigger' => 'manual_keyword_cycle',
                    'user_id' => $user_id,
                ],
                'source' => 'manual_admin',
            ]);
            $result['cycle_triggered'] = true;
            Logs::info( 'keywords', '[TMW-CLEAN-REBUILD] First clean discovery cycle completed', [
                'user_id' => $user_id,
                'source'  => 'manual_admin',
                'trigger' => 'manual_keyword_cycle',
            ] );
        } catch ( \Throwable $throwable ) {
            $result['errors'][] = 'manual_cycle_failed:' . $throwable->getMessage();
            ContentGenerationGate::pause_all();
            $result['content_generation_paused'] = ContentGenerationGate::is_paused();
            $result['after']                     = self::capture_counts();
            Logs::error( 'keywords', '[TMW-CLEAN-REBUILD] Manual clean discovery cycle failed', [
                'user_id' => $user_id,
                'message' => $throwable->getMessage(),
            ] );
            return self::finalize_result( $result, $user_id );
        }

        ContentGenerationGate::pause_all();
        Logs::info( 'keywords', '[TMW-CLEAN-REBUILD] Content generation explicitly left paused after rebuild', [
            'user_id' => $user_id,
        ] );

        $result['content_generation_paused'] = ContentGenerationGate::is_paused();
        $result['after']                     = self::capture_counts();
        $result['success']                   = true;

        Logs::info( 'keywords', '[TMW-CLEAN-REBUILD] Staging clean rebuild from zero completed', [
            'user_id' => $user_id,
            'after'   => $result['after'],
        ] );

        return self::finalize_result( $result, $user_id );
    }

    /**
     * Persist a compact failed attempt before the main orchestrator runs.
     *
     * @param array<int,string> $errors
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public static function record_preflight_failure( int $user_id, array $errors, array $extra = [] ): array {
        $result = array_merge( [
            'success'                   => false,
            'errors'                    => array_values( array_map( 'strval', $errors ) ),
            'legacy_new_migrated'       => 0,
            'generator_archived'        => 0,
            'discovery_parked'          => 0,
            'manual_jobs_deleted'       => 0,
            'reset'                     => [],
            'cycle_triggered'           => false,
            'content_generation_paused' => ContentGenerationGate::is_paused(),
            'before'                    => [],
            'after'                     => [],
        ], $extra );

        return self::finalize_result( $result, $user_id );
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function finalize_result( array $result, int $user_id ): array {
        $result['content_generation_paused'] = ! empty( $result['content_generation_paused'] ) || ContentGenerationGate::is_paused();
        self::persist_last_result( $result, $user_id );

        return $result;
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function persist_last_result( array $result, int $user_id ): void {
        $user_label = '';
        $user       = $user_id > 0 ? get_userdata( $user_id ) : false;

        if ( $user instanceof \WP_User ) {
            $user_label = (string) ( $user->display_name ?: $user->user_email ?: $user->user_login );
        }

        $payload = [
            'timestamp'                 => current_time( 'mysql' ),
            'timestamp_gmt'             => gmdate( 'Y-m-d H:i:s' ),
            'user_id'                   => $user_id,
            'user_label'                => $user_label,
            'success'                   => ! empty( $result['success'] ),
            'errors'                    => array_values( array_map( 'strval', (array) ( $result['errors'] ?? [] ) ) ),
            'legacy_new_migrated'       => (int) ( $result['legacy_new_migrated'] ?? 0 ),
            'generator_archived'        => (int) ( $result['generator_archived'] ?? 0 ),
            'discovery_parked'          => (int) ( $result['discovery_parked'] ?? 0 ),
            'manual_jobs_deleted'       => (int) ( $result['manual_jobs_deleted'] ?? 0 ),
            'reset'                     => is_array( $result['reset'] ?? null ) ? $result['reset'] : [],
            'cycle_triggered'           => ! empty( $result['cycle_triggered'] ),
            'content_generation_paused' => ! empty( $result['content_generation_paused'] ),
            'before'                    => is_array( $result['before'] ?? null ) ? $result['before'] : [],
            'after'                     => is_array( $result['after'] ?? null ) ? $result['after'] : [],
        ];

        update_option( self::LAST_RESULT_OPTION, $payload, false );
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_last_result(): array {
        $stored = get_option( self::LAST_RESULT_OPTION, [] );

        return is_array( $stored ) ? $stored : [];
    }

    public static function clear_last_result(): void {
        delete_option( self::LAST_RESULT_OPTION );
    }

    /**
     * @return array<string,mixed>
     */
    private static function capture_counts(): array {
        global $wpdb;

        $counts = [
            'staging_flags'       => get_option( self::STAGING_FLAGS_OPTION, [] ),
            'content_paused'      => ContentGenerationGate::is_paused(),
            'manual_jobs_pending' => 0,
            'generator'           => [],
            'discovery'           => [],
            'seed_totals'         => [],
            'working_keywords'    => 0,
            'assigned_pages'      => 0,
            'raw_keywords'        => 0,
        ];

        $jobs_table = $wpdb->prefix . 'tmwseo_jobs';
        if ( self::table_exists( $jobs_table ) ) {
            $counts['manual_jobs_pending'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$jobs_table} WHERE status = 'pending' AND payload_json LIKE '%manual_keyword_cycle%'"
            );
        }

        $generator_table = $wpdb->prefix . 'tmw_seed_expansion_candidates';
        if ( self::table_exists( $generator_table ) ) {
            $counts['generator']['total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$generator_table}" );
            $rows = $wpdb->get_results( "SELECT status, COUNT(*) AS cnt FROM {$generator_table} GROUP BY status", ARRAY_A );
            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    $counts['generator'][ (string) $row['status'] ] = (int) $row['cnt'];
                }
            }
        }

        $candidate_table = $wpdb->prefix . 'tmw_keyword_candidates';
        if ( self::table_exists( $candidate_table ) ) {
            $counts['working_keywords'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$candidate_table}" );
            $rows = $wpdb->get_results( "SELECT status, COUNT(*) AS cnt FROM {$candidate_table} GROUP BY status", ARRAY_A );
            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    $counts['discovery'][ (string) $row['status'] ] = (int) $row['cnt'];
                }
            }
        }

        $raw_table = $wpdb->prefix . 'tmw_keyword_raw';
        if ( self::table_exists( $raw_table ) ) {
            $counts['raw_keywords'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$raw_table}" );
        }

        $counts['assigned_pages'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            '_tmwseo_keyword'
        ) );

        $counts['seed_totals'] = SeedRegistry::diagnostics();

        return $counts;
    }

    private static function table_exists( string $table_name ): bool {
        global $wpdb;

        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
    }
}
