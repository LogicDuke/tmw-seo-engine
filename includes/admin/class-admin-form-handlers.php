<?php
/**
 * Admin Form Handlers
 *
 * Extracted from class-admin.php (god class reduction).
 * Contains all admin_post_tmwseo_* form-action handlers that were previously
 * inline inside the Admin class. All original action registrations in
 * Admin::init() still point to Admin::method_name() — those are thin
 * one-line delegates that call the corresponding method here, so every
 * existing external reference continues to work unchanged.
 *
 * @package TMWSEO\Engine\Admin
 * @since   5.1.1
 */
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Jobs;
use TMWSEO\Engine\Worker;
use TMWSEO\Engine\JobWorker;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Keywords\SeedRegistry;
use TMWSEO\Engine\Keywords\NicheSerpMiningService;
use TMWSEO\Engine\Keywords\KeywordCleanupClassifier;
use TMWSEO\Engine\Keywords\ModelKeywordEntityRepairService;
use TMWSEO\Engine\KeywordIntelligence\ModelDiscoveryTrigger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AdminFormHandlers {

    // ─── Worker ──────────────────────────────────────────────────────────────

    public static function run_worker_now(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions', 'tmwseo' ) );
        }
        check_admin_referer( 'tmwseo_run_worker' );

        JobWorker::process_next_job();
        // Keep legacy queue processing for backward compatibility.
        Worker::run();

        $redirect_page = isset( $_POST['redirect_page'] )
            ? sanitize_key( wp_unslash( (string) $_POST['redirect_page'] ) )
            : \TMWSEO\Engine\Admin::MENU_SLUG;

        $allowed_pages = [
            'tmwseo-discovery-control',
            'tmwseo-engine',
        ];

        if ( ! in_array( $redirect_page, $allowed_pages, true ) ) {
            $redirect_page = \TMWSEO\Engine\Admin::MENU_SLUG;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . rawurlencode( $redirect_page ) . '&tmwseo_notice=worker_ran' ) );
        exit;
    }

    // ─── Discovery / Keywords ─────────────────────────────────────────────

    public static function handle_reset_discovery_data(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions', 'tmwseo' ) );
        }
        check_admin_referer( 'tmwseo_reset_discovery_data' );

        global $wpdb;

        $tables = [
            'keywords'    => $wpdb->prefix . 'tmw_seo_keywords',
            'clusters'    => $wpdb->prefix . 'tmw_seo_clusters',
            'suggestions' => $wpdb->prefix . 'tmw_seo_suggestions',
        ];

        $deleted = [ 'keywords' => 0, 'clusters' => 0, 'suggestions' => 0 ];

        foreach ( $tables as $key => $table ) {
            $exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( $exists !== $table ) {
                continue;
            }
            $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $affected         = $wpdb->rows_affected;
            $deleted[ $key ]  = $affected > 0 ? (int) $affected : 0;
        }

        Logs::info( 'tools', '[TMW-RESET] Discovery data reset from Tools page', [
            'keywords_deleted'    => $deleted['keywords'],
            'clusters_deleted'    => $deleted['clusters'],
            'suggestions_deleted' => $deleted['suggestions'],
            'scope'               => 'discovery_tables_only',
        ] );

        wp_safe_redirect( add_query_arg( [
            'page'                       => 'tmwseo-tools',
            'tmwseo_notice'              => 'discovery_data_reset',
            'tmwseo_keywords_deleted'    => $deleted['keywords'],
            'tmwseo_clusters_deleted'    => $deleted['clusters'],
            'tmwseo_suggestions_deleted' => $deleted['suggestions'],
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_generate_model_seeds(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions', 'tmwseo' ) );
        }
        check_admin_referer( 'tmwseo_generate_model_seeds' );

        global $wpdb;

        $offset          = isset( $_REQUEST['offset'] )    ? max( 0, (int) $_REQUEST['offset'] )    : 0;
        $processed_total = isset( $_REQUEST['processed'] ) ? max( 0, (int) $_REQUEST['processed'] ) : 0;
        $created_total   = isset( $_REQUEST['created'] )   ? max( 0, (int) $_REQUEST['created'] )   : 0;
        $batch_size      = 75;

        $total_models = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash', 'auto-draft')",
            'model'
        ) );

        $model_ids = get_posts( [
            'post_type'                => 'model',
            'post_status'              => [ 'publish', 'draft', 'pending', 'private', 'future' ],
            'posts_per_page'           => $batch_size,
            'offset'                   => $offset,
            'orderby'                  => 'ID',
            'order'                    => 'ASC',
            'fields'                   => 'ids',
            'no_found_rows'            => true,
            'cache_results'            => false,
            'update_post_meta_cache'   => false,
            'update_post_term_cache'   => false,
            'suppress_filters'         => true,
        ] );

        if ( ! is_array( $model_ids ) ) {
            $model_ids = [];
        }

        $keywords_table        = $wpdb->prefix . 'tmw_seo_keywords';
        $updated_at            = current_time( 'mysql' );
        $keywords_table_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $keywords_table ) );

        if ( $keywords_table_exists !== $keywords_table ) {
            wp_safe_redirect( add_query_arg( [
                'page'                     => 'tmwseo-tools',
                'tmwseo_notice'            => 'model_seeds_generated',
                'tmwseo_models_processed'  => $processed_total,
                'tmwseo_seeds_created'     => $created_total,
            ], admin_url( 'admin.php' ) ) );
            exit;
        }

        foreach ( $model_ids as $model_id ) {
            $model_id   = (int) $model_id;
            $model_name = trim( (string) get_the_title( $model_id ) );
            if ( $model_name === '' ) {
                $processed_total++;
                continue;
            }

            $seed_keywords = self::build_model_seed_keywords( $model_name );
            if ( empty( $seed_keywords ) ) {
                $processed_total++;
                continue;
            }

            $placeholders  = implode( ', ', array_fill( 0, count( $seed_keywords ), '%s' ) );
            $existing_rows = $wpdb->get_col( $wpdb->prepare(
                "SELECT keyword FROM {$keywords_table} WHERE entity_type = %s AND entity_id = %d AND keyword IN ({$placeholders})",
                ...array_merge( [ 'model', $model_id ], $seed_keywords )
            ) );

            $existing_map = [];
            if ( is_array( $existing_rows ) ) {
                foreach ( $existing_rows as $existing_keyword ) {
                    $existing_map[ (string) $existing_keyword ] = true;
                }
            }

            foreach ( $seed_keywords as $seed_keyword ) {
                if ( isset( $existing_map[ $seed_keyword ] ) ) {
                    continue;
                }
                $wpdb->insert( $keywords_table, [
                    'entity_type' => 'model',
                    'entity_id'   => $model_id,
                    'keyword'     => $seed_keyword,
                    'volume'      => null,
                    'cpc'         => null,
                    'difficulty'  => null,
                    'intent'      => null,
                    'source'      => 'model_seed_tool',
                    'raw'         => null,
                    'updated_at'  => $updated_at,
                ] );
                $created_total++;
            }

            $processed_total++;
        }

        $next_offset = $offset + count( $model_ids );

        if ( ! empty( $model_ids ) && $next_offset < $total_models ) {
            wp_safe_redirect( add_query_arg( [
                'action'     => 'tmwseo_generate_model_seeds',
                '_wpnonce'   => wp_create_nonce( 'tmwseo_generate_model_seeds' ),
                'offset'     => $next_offset,
                'processed'  => $processed_total,
                'created'    => $created_total,
            ], admin_url( 'admin-post.php' ) ) );
            exit;
        }

        Logs::info( 'keywords', '[TMW-SEEDS] Model seed generation completed', [
            'models_processed' => $processed_total,
            'seeds_created'    => $created_total,
            'total_models'     => $total_models,
            'batch_size'       => $batch_size,
        ] );

        wp_safe_redirect( add_query_arg( [
            'page'                    => 'tmwseo-tools',
            'tmwseo_notice'           => 'model_seeds_generated',
            'tmwseo_models_processed' => $processed_total,
            'tmwseo_seeds_created'    => $created_total,
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /** @return string[] */
    private static function build_model_seed_keywords( string $model_name ): array {
        $patterns = [
            '%s webcam model',
            '%s live cam girl',
            '%s webcam show',
            '%s cam model',
            '%s private cam show',
            '%s live webcam show',
            '%s cam girl live',
        ];

        $keywords = [];
        foreach ( $patterns as $pattern ) {
            $keyword = SeedRegistry::normalize_seed( sprintf( $pattern, $model_name ) );
            if ( $keyword !== '' ) {
                $keywords[ $keyword ] = true;
            }
        }

        return array_keys( $keywords );
    }

    public static function legacy_save_settings_redirect(): void {
        Logs::warn( 'settings', '[TMW-SEO] Legacy admin_post_tmwseo_save_settings triggered — redirecting without saving.', [
            'user_id'  => get_current_user_id(),
            'referrer' => wp_get_referer() ?: '(none)',
        ] );
        wp_safe_redirect( admin_url( 'admin.php?page=tmwseo-settings&tmwseo_notice=legacy_save_blocked' ) );
        exit;
    }

    public static function run_keyword_cycle_now(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions', 'tmwseo' ) );
        }
        check_admin_referer( 'tmwseo_run_keyword_cycle' );

        Logs::info( 'keyword_engine', '[TMW-SEO] Manual keyword cycle triggered by user ' . get_current_user_id() );

        \TMWSEO\Engine\Keywords\UnifiedKeywordWorkflowService::run_cycle( [
            'payload' => [
                'trigger' => 'manual_keyword_cycle',
                'user_id' => get_current_user_id(),
            ],
            'source' => 'manual_admin',
        ] );

        Logs::info( 'keyword_engine', '[TMW-SEO] Manual keyword cycle: synchronous unified workflow completed', [
            'source'  => 'manual_admin',
            'trigger' => 'manual_keyword_cycle',
            'user_id' => get_current_user_id(),
        ] );

        wp_safe_redirect( admin_url( 'admin.php?page=tmwseo-keywords&tmwseo_notice=seo_engine_cycle_executed' ) );
        exit;
    }

    public static function run_competitor_mining_now(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions', 'tmwseo' ) );
        }
        check_admin_referer( 'tmwseo_run_competitor_mining' );

        JobWorker::enqueue_job( 'competitor_mining', [
            'trigger'    => 'manual_competitor_mining',
            'user_id'    => get_current_user_id(),
            'seed_limit' => 25,
        ] );

        wp_safe_redirect( admin_url( 'admin.php?page=tmwseo-competitor-mining&tmwseo_notice=competitor_mining_queued' ) );
        exit;
    }

    public static function handle_keyword_candidate_action(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=tmwseo-keywords&tmwseo_notice=candidate_action_unauthorized' ) );
            exit;
        }

        $candidate_id     = isset( $_REQUEST['candidate_id'] ) ? absint( $_REQUEST['candidate_id'] ) : 0;
        $requested_action = isset( $_REQUEST['candidate_action'] )
            ? sanitize_key( (string) wp_unslash( $_REQUEST['candidate_action'] ) )
            : '';

        if ( $candidate_id <= 0 ) {
            wp_safe_redirect( admin_url( 'admin.php?page=tmwseo-keywords&tmwseo_notice=candidate_invalid_request' ) );
            exit;
        }

        $nonce_action = 'tmwseo_keyword_candidate_action_' . $candidate_id;
        if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( (string) wp_unslash( $_REQUEST['_wpnonce'] ), $nonce_action ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=tmwseo-keywords&tmwseo_notice=candidate_invalid_nonce' ) );
            exit;
        }

        $status_map = [
            'approve' => 'approved',
            'reject'  => 'ignored',
        ];

        if ( ! isset( $status_map[ $requested_action ] ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=tmwseo-keywords&tmwseo_notice=candidate_invalid_request' ) );
            exit;
        }

        global $wpdb;
        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $new_status = $status_map[ $requested_action ];

        $wpdb->update(
            $cand_table,
            [ 'status' => $new_status, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $candidate_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        $current_status = strtolower( trim( (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$cand_table} WHERE id = %d",
            $candidate_id
        ) ) ) );

        // Already in desired state — surface a helpful notice.
        if ( ( $current_status === 'approved' && $new_status === 'approved' ) ||
             ( $current_status === 'ignored'  && $new_status === 'ignored'  ) ) {
            wp_safe_redirect( add_query_arg( [
                'page'                  => 'tmwseo-keywords',
                'tmwseo_notice'         => 'candidate_action_not_available',
                'tmwseo_candidate_id'   => $candidate_id,
            ], admin_url( 'admin.php' ) ) );
            exit;
        }

        wp_safe_redirect( add_query_arg( [
            'page'                      => 'tmwseo-keywords',
            'tmwseo_notice'             => 'candidate_updated',
            'tmwseo_candidate_id'       => $candidate_id,
            'tmwseo_candidate_status'   => $new_status,
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    // ─── Indexing / PageSpeed ─────────────────────────────────────────────

    public static function run_pagespeed_cycle_now(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions', 'tmwseo' ) );
        }
        check_admin_referer( 'tmwseo_run_pagespeed_cycle' );

        Jobs::enqueue( 'pagespeed_cycle', 'system', 0, [ 'trigger' => 'manual' ] );
        Worker::run();

        wp_safe_redirect( admin_url( 'admin.php?page=tmwseo-pagespeed&tmwseo_notice=pagespeed_cycle_ran' ) );
        exit;
    }

    public static function enable_indexing_now(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions', 'tmwseo' ) );
        }
        check_admin_referer( 'tmwseo_enable_indexing' );

        if ( Settings::is_human_approval_required() && ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Human approval required.', 'tmwseo' ) );
        }

        $page_id = (int) ( $_GET['page_id'] ?? 0 );
        if ( $page_id <= 0 ) {
            wp_die( __( 'Missing page_id', 'tmwseo' ) );
        }

        delete_post_meta( $page_id, 'rank_math_robots' );

        global $wpdb;

        $gen_table = $wpdb->prefix . 'tmw_generated_pages';
        $wpdb->update( $gen_table, [
            'indexing'          => 'index',
            'last_generated_at' => current_time( 'mysql' ),
        ], [ 'page_id' => $page_id ], [ '%s', '%s' ], [ '%d' ] );

        $url = get_permalink( $page_id );
        if ( $url ) {
            $idx_table = $wpdb->prefix . 'tmw_indexing';
            $wpdb->update( $idx_table, [ 'status' => 'manual_indexing_enabled' ], [ 'url' => $url ], [ '%s' ], [ '%s' ] );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=tmwseo-generated&tmwseo_notice=indexing_enabled' ) );
        exit;
    }

    // ─── Post optimization ────────────────────────────────────────────────

    public static function handle_refresh_keywords_now(): void {
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( $post_id <= 0 ) {
            wp_die( 'Invalid post.' );
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( 'Permission denied.' );
        }
        check_admin_referer( 'tmwseo_refresh_keywords_' . $post_id );

        $post_type = get_post_type( $post_id ) ?: 'post';
        Jobs::enqueue( 'optimize_post', (string) $post_type, $post_id, [
            'trigger'       => 'manual',
            'keywords_only' => 1,
        ] );

        Logs::info( 'admin', '[TMW-QUEUE] optimize_post queued from refresh_keywords_now', [
            'post_id'       => $post_id,
            'post_type'     => (string) $post_type,
            'keywords_only' => 1,
        ] );

        $ref          = wp_get_referer();
        $redirect_url = $ref ? $ref : admin_url( 'post.php?post=' . $post_id . '&action=edit' );
        $redirect_url = add_query_arg( 'tmwseo_notice', 'keywords_refresh_queued', $redirect_url );
        wp_safe_redirect( $redirect_url );
        exit;
    }

    public static function handle_optimize_post_now(): void {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Permission denied.' );
        }

        $req     = $_REQUEST;
        $post_id = (int) ( $req['post_id'] ?? 0 );
        if ( $post_id <= 0 ) {
            wp_die( 'Invalid post.' );
        }

        $strategy = sanitize_key( (string) ( $req['strategy'] ?? '' ) );
        if ( ! in_array( $strategy, [ 'template', 'openai' ], true ) ) {
            $strategy = 'openai';
        }

        $insert_block = ! empty( $req['insert_block'] ) ? 1 : 0;

        $nonce = (string) ( $req['_wpnonce'] ?? '' );
        if ( $nonce === '' || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), 'tmwseo_optimize_post_' . $post_id ) ) {
            wp_die( 'Invalid or expired nonce.' );
        }

        Logs::info( 'admin', 'optimize_post_now handler HIT', [
            'post_id'      => $post_id,
            'strategy'     => $strategy,
            'insert_block' => $insert_block,
        ] );

        $post_type = get_post_type( $post_id ) ?: 'post';
        Jobs::enqueue( 'optimize_post', (string) $post_type, $post_id, [
            'trigger'      => 'manual',
            'strategy'     => $strategy,
            'insert_block' => $insert_block,
        ] );
        Logs::info( 'admin', '[TMW-QUEUE] optimize_post queued from manual action', [
            'post_id'   => $post_id,
            'post_type' => (string) $post_type,
        ] );

        $ref          = wp_get_referer();
        $redirect_url = $ref ? $ref : admin_url( 'post.php?post=' . $post_id . '&action=edit' );
        $redirect_url = add_query_arg( 'tmwseo_notice', 'optimize_queued', $redirect_url );
        wp_safe_redirect( $redirect_url );

        // Kick the worker after the response is flushed to avoid 504s.
        if ( function_exists( 'fastcgi_finish_request' ) ) {
            @fastcgi_finish_request(); // phpcs:ignore
        } else {
            @ob_end_flush(); // phpcs:ignore
            @flush();        // phpcs:ignore
        }
        if ( function_exists( 'ignore_user_abort' ) ) {
            @ignore_user_abort( true ); // phpcs:ignore
        }
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore
        }

        Worker::run( 1 );
        exit;
    }

    // ─── Bulk autofix ────────────────────────────────────────────────────

    public static function handle_bulk_autofix(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'tmwseo_bulk_autofix' );

        $post_types = [ 'post', 'model', 'tmw_category_page' ];
        $posts      = get_posts( [
            'post_type'              => $post_types,
            'posts_per_page'         => -1,
            'post_status'            => 'publish',
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'cache_results'          => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ] );

        $updated = 0;

        foreach ( $posts as $post_id ) {
            $keyword = get_post_meta( (int) $post_id, 'rank_math_focus_keyword', true );
            $meta    = get_post_meta( (int) $post_id, 'rank_math_description', true );
            $post    = get_post( (int) $post_id );

            if ( ! $post ) {
                continue;
            }

            $title   = trim( (string) $post->post_title );
            $changed = false;

            if ( $title !== '' && trim( (string) $keyword ) === '' ) {
                $words       = preg_split( '/\s+/', strtolower( $title ) );
                $words       = is_array( $words ) ? array_values( array_filter( $words ) ) : [];
                $new_keyword = implode( ' ', array_slice( $words, 0, 4 ) );
                if ( $new_keyword !== '' ) {
                    update_post_meta( (int) $post_id, 'rank_math_focus_keyword', sanitize_text_field( $new_keyword ) );
                    $changed = true;
                }
            }

            if ( $title !== '' && trim( (string) $meta ) === '' ) {
                $new_meta = mb_substr( sprintf(
                    'Watch %s online. Discover premium streaming content and exclusive live experiences.',
                    $title
                ), 0, 155 );
                update_post_meta( (int) $post_id, 'rank_math_description', sanitize_text_field( $new_meta ) );
                $changed = true;
            }

            if ( $changed ) {
                update_post_meta( (int) $post_id, '_tmwseo_autofixed', current_time( 'mysql' ) );
                $updated++;
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . \TMWSEO\Engine\Admin::MENU_SLUG . '&bulk_updated=' . $updated ) );
        exit;
    }

    // ─── CSV Import ──────────────────────────────────────────────────────

    public static function import_keywords(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions', 'tmwseo' ) );
        }
        check_admin_referer( 'tmwseo_import_keywords' );

        if ( empty( $_FILES['keywords_csv'] ) || ! isset( $_FILES['keywords_csv']['tmp_name'] ) ) {
            wp_die( __( 'No file uploaded', 'tmwseo' ) );
        }

        $file = $_FILES['keywords_csv'];
        if ( ! empty( $file['error'] ) ) {
            wp_die( __( 'Upload error', 'tmwseo' ) );
        }

        $source   = sanitize_text_field( (string) ( $_POST['import_source'] ?? 'manual' ) );
        $run_kd   = ! empty( $_POST['run_kd'] );

        $tmp      = (string) $file['tmp_name'];
        $filename = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : 'import.csv';
        if ( $filename === '' || strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) ) !== 'csv' ) {
            $filename = 'import-' . gmdate( 'Ymd-His' ) . '.csv';
        }

        $csv_dir = function_exists( 'tmw_get_csv_directory' )
            ? tmw_get_csv_directory()
            : trailingslashit( WP_CONTENT_DIR ) . 'uploads/tmw-seo-imports';

        if ( ! file_exists( $csv_dir ) ) {
            wp_mkdir_p( $csv_dir );
        }

        $target = trailingslashit( $csv_dir ) . wp_unique_filename( $csv_dir, $filename );
        $moved  = is_uploaded_file( $tmp ) ? move_uploaded_file( $tmp, $target ) : @rename( $tmp, $target );
        if ( ! $moved ) {
            wp_die( __( 'Could not store CSV in uploads/tmw-seo-imports.', 'tmwseo' ) );
        }

        $result = self::import_keywords_from_csv_path( $target, $source, $run_kd );

        set_transient(
            'tmwseo_import_rejections',
            $result['rejections'],
            60
        );

        wp_safe_redirect( admin_url(
            'admin.php?page=tmwseo-import'
            . '&raw=' . (int) $result['raw']
            . '&cand=' . (int) $result['cand']
            . '&rej=' . (int) $result['rej']
        ) );
        exit;


    }

    /**
     * Parse a CSV file and insert keywords into the raw + candidates tables.
     *
     * Public so it can be called directly from WP-CLI or other tooling.
     *
     * @return array{raw:int,cand:int,rej:int}
     */
    public static function import_keywords_from_csv_path( string $file_path, string $source = 'manual', bool $run_kd = true ): array {
    Logs::info( 'import', '[TMW-CSV-IMPORT] import_start', [
        'file'   => basename( $file_path ),
        'source' => $source,
        'run_kd' => $run_kd,
    ] );
    $fh = fopen( $file_path, 'r' );
    if ( ! $fh ) {
        Logs::error( 'import', '[TMW-CSV-IMPORT] import_failed', [ 'file' => basename( $file_path ), 'reason' => 'file_open_failed' ] );
        wp_die( __( 'Could not read CSV', 'tmwseo' ) );
    }

    $header = fgetcsv( $fh );
    if ( ! is_array( $header ) ) {
        $header = [];
    }

    $kw_col       = null;
    $vol_col      = null;
    $type_col     = null;
    $priority_col = null;

    foreach ( $header as $i => $col ) {
        $c = strtolower( trim( (string) $col ) );
        if ( $c === 'seed_keyword' ) {
            $kw_col = (int) $i;
        } elseif ( $c === 'keyword' && $kw_col === null ) {
            $kw_col = (int) $i;
        }
        if ( strpos( $c, 'volume' ) !== false || $c === 'avg. monthly searches' || $c === 'search volume' ) {
            $vol_col = (int) $i;
        }
        if ( $c === 'type' ) {
            $type_col = (int) $i;
        }
        if ( $c === 'priority' ) {
            $priority_col = (int) $i;
        }
    }

    if ( $kw_col === null ) {
        $kw_col = 0;
        Logs::warn( 'import', '[TMW-CSV] No seed_keyword/keyword column found in header, falling back to column 0', [
            'header' => $header,
        ] );
    }

    $batch_id   = \TMWSEO\Engine\Keywords\ExpansionCandidateRepository::make_batch_id( 'csv_import' );
    $now        = current_time( 'mysql' );

    global $wpdb;
    $raw_table    = $wpdb->prefix . 'tmw_keyword_raw';
    $cand_table   = $wpdb->prefix . 'tmw_keyword_candidates';
    $seeds_table  = $wpdb->prefix . 'tmwseo_seeds';

    // ── STEP 1: Read all valid keywords from CSV into memory first ──────────
    // No DB calls here — just read and validate in PHP.
    $valid_rows     = [];
    $rejected       = 0;
    $rejected_items = [];

    while ( ( $row = fgetcsv( $fh ) ) !== false ) {
        if ( ! is_array( $row ) ) {
            continue;
        }

        $kw = isset( $row[ $kw_col ] ) ? trim( (string) $row[ $kw_col ] ) : '';
        if ( $kw === '' ) {
            continue;
        }

        $reason = null;
        if ( ! \TMWSEO\Engine\Keywords\KeywordValidator::is_relevant_no_track( $kw, $reason ) ) {
            error_log( sprintf( '[TMWSEO Import] Rejected: "%s" | Reason: %s', $kw, $reason ?? 'unknown' ) );
            $rejected_items[] = [ 'keyword' => $kw, 'reason' => $reason ?? 'unknown' ];
            $rejected++;
            continue;
        }

        $seed_type = 'general';
        if ( $type_col !== null && isset( $row[ $type_col ] ) ) {
            $st = sanitize_key( (string) $row[ $type_col ] );
            if ( $st !== '' ) {
                $seed_type = $st;
            }
        }

        $priority = 1;
        if ( $priority_col !== null && isset( $row[ $priority_col ] ) ) {
            $p = absint( (string) $row[ $priority_col ] );
            if ( $p > 0 ) {
                $priority = $p;
            }
        }

        $vol = null;
        if ( $vol_col !== null && isset( $row[ $vol_col ] ) ) {
            $v = preg_replace( '/[^0-9]/', '', (string) $row[ $vol_col ] );
            if ( $v !== '' ) {
                $vol = (int) $v;
            }
        }

        $normalised = \TMWSEO\Engine\Keywords\SeedRegistry::normalize_seed( $kw );
        if ( $normalised === '' ) {
            continue;
        }

        // Deduplicate within the CSV itself before any DB call
        $valid_rows[ $normalised ] = [
            'kw'        => $kw,
            'normalised'=> $normalised,
            'hash'      => md5( $normalised ),
            'seed_type' => $seed_type,
            'priority'  => $priority,
            'vol'       => $vol,
        ];
    }

    fclose( $fh );

    if ( empty( $valid_rows ) ) {
        Logs::warning( 'import', '[TMW-CSV-IMPORT] import_failed', [ 'file' => basename( $file_path ), 'reason' => 'no_valid_rows', 'rejected' => $rejected ] );
        Logs::info( 'import', '[TMW-CSV] No valid rows after validation', [
            'rejected' => $rejected,
            'source'   => $source,
        ] );
        // Flush validator stats once
        \TMWSEO\Engine\Keywords\KeywordValidator::flush_stats( $rejected, 0 );
        return [ 'raw' => 0, 'cand' => 0, 'rej' => $rejected, 'rejections' => $rejected_items ];
    }

    // ── STEP 2: Bulk check existing seeds in ONE query ──────────────────────
    $all_hashes    = array_column( $valid_rows, 'hash' );
    $placeholders  = implode( ',', array_fill( 0, count( $all_hashes ), '%s' ) );
    $existing_hashes = $wpdb->get_col(
        $wpdb->prepare( "SELECT hash FROM {$seeds_table} WHERE hash IN ({$placeholders})", ...$all_hashes )
    );
    $existing_hash_map = array_flip( $existing_hashes );

    // ── STEP 3: Bulk check existing candidates in ONE query ─────────────────
    $all_kws         = array_column( $valid_rows, 'kw' );
    $kw_placeholders = implode( ',', array_fill( 0, count( $all_kws ), '%s' ) );
    $existing_cands  = $wpdb->get_col(
        $wpdb->prepare( "SELECT keyword FROM {$cand_table} WHERE keyword IN ({$kw_placeholders})", ...$all_kws )
    );
    $existing_cand_map = array_flip( $existing_cands );

    // ── STEP 4: Insert in bulk ──────────────────────────────────────────────
    $raw_ins          = 0;
    $cand_ins         = 0;
    $seeds_new        = 0;
    $seeds_duplicated = 0;

    $raw_rows   = [];
    $cand_rows  = [];
    $seed_rows  = [];

    foreach ( $valid_rows as $normalised => $r ) {
        $kw   = $r['kw'];
        $hash = $r['hash'];
        $vol  = $r['vol'];

        // Seeds table — only new hashes
        if ( ! isset( $existing_hash_map[ $hash ] ) ) {
            $seed_rows[] = $wpdb->prepare(
                '(%s, %s, %s, %d, %s, %d, %s, %s, %s, %s)',
                $normalised,
                'approved_import',
                $r['seed_type'],
                $r['priority'],
                'import',
                0,
                $now,
                $hash,
                $batch_id,
                sanitize_text_field( $source )
            );
            $seeds_new++;
            Logs::info( 'import', '[TMW-CSV-IMPORT] row_inserted', [ 'keyword' => $kw, 'hash' => $hash, 'source' => $source ] );
        } else {
            $seeds_duplicated++;
            Logs::info( 'import', '[TMW-CSV-IMPORT] duplicate_skipped', [ 'keyword' => $kw, 'hash' => $hash, 'source' => $source ] );
        }

        // Raw table — always attempt (INSERT IGNORE handles duplicates)
        $raw_rows[] = $wpdb->prepare(
            '(%s, %s, %s, %d, %f, %f, %s, %s)',
            $kw, 'import', $source, (int) ( $vol ?? 0 ), 0.0, 0.0, null, $now
        );
        $raw_ins++;

        // Candidates table — only if not already existing
        if ( ! isset( $existing_cand_map[ $kw ] ) ) {
            $canonical  = \TMWSEO\Engine\Keywords\KeywordValidator::normalize( $kw );
            $intent     = \TMWSEO\Engine\Keywords\KeywordValidator::infer_intent( $kw );
            $cand_rows[] = $wpdb->prepare(
                '(%s, %s, %s, %s, %d, %s, %s)',
                $kw,
                $canonical,
                'new',
                $intent,
                (int) ( $vol ?? 0 ),
                'import:' . $source,
                $now
            );
            $cand_ins++;
        }
    }

    // Bulk insert seeds (100 rows per query to avoid max_allowed_packet issues)
    if ( ! empty( $seed_rows ) ) {
        foreach ( array_chunk( $seed_rows, 100 ) as $chunk ) {
            $wpdb->query(
                "INSERT IGNORE INTO {$seeds_table}
                 (seed, source, seed_type, priority, entity_type, entity_id, created_at, hash, import_batch_id, import_source_label)
                 VALUES " . implode( ',', $chunk ) // phpcs:ignore
            );
        }
    }

    // Bulk insert raw keywords
    if ( ! empty( $raw_rows ) ) {
        foreach ( array_chunk( $raw_rows, 100 ) as $chunk ) {
            $wpdb->query(
                "INSERT IGNORE INTO {$raw_table}
                 (keyword, source, source_ref, volume, cpc, competition, raw, discovered_at)
                 VALUES " . implode( ',', $chunk ) // phpcs:ignore
            );
        }
    }

    // Bulk insert candidates
    if ( ! empty( $cand_rows ) ) {
        foreach ( array_chunk( $cand_rows, 100 ) as $chunk ) {
            $wpdb->query(
            "INSERT IGNORE INTO {$cand_table}
                 (keyword, canonical, status, intent, volume, sources, updated_at)
                 VALUES " . implode( ',', $chunk ) // phpcs:ignore
            );
        }
    }

    // ── STEP 5: Flush stats ONCE at end (not per-row) ───────────────────────
    \TMWSEO\Engine\Keywords\KeywordValidator::flush_stats( $rejected, $raw_ins );
    \TMWSEO\Engine\Keywords\SeedRegistry::flush_import_counters( $source, $seeds_new, $seeds_duplicated );

    Logs::info( 'import', '[TMW-CSV] Bulk import complete', [
        'raw'              => $raw_ins,
        'candidates_new'   => $cand_ins,
        'seeds_new'        => $seeds_new,
        'seeds_duplicated' => $seeds_duplicated,
        'rejected'         => $rejected,
        'source'           => $source,
        'file'             => basename( $file_path ),
    ] );

    if ( $run_kd ) {
        Logs::warning( 'import', '[TMW-CSV-IMPORT] timeout_risk', [ 'reason' => 'synchronous_worker_run', 'source' => $source ] );
        Jobs::enqueue( 'keyword_cycle', 'system', 0, [
            'trigger' => 'import',
            'mode'    => 'import_only',
        ] );
        Worker::run();
    }

    Logs::info( 'import', '[TMW-CSV-IMPORT] import_completed', [
        'raw'        => $raw_ins,
        'cand'       => $cand_ins,
        'duplicates' => $seeds_duplicated,
        'rejected'   => $rejected,
        'source'     => $source,
    ] );

    return [ 'raw' => $raw_ins, 'cand' => $cand_ins, 'rej' => $rejected, 'duplicates' => $seeds_duplicated, 'rejections' => $rejected_items ];
}

    // ─── Niche SERP Mining ────────────────────────────────────────────────────

    /**
     * Handle the admin-triggered niche SERP mining run.
     *
     * Reads a textarea of niche phrases (one per line), runs the bounded
     * SERP → domain-score → domain-keyword → preview-candidate flow, and
     * stores the run summary in a short-lived transient for admin display.
     *
     * Nonce: tmwseo_run_niche_serp_mining
     * Form field: niche_phrases (textarea, one phrase per line)
     */
    public static function run_niche_serp_mining_now(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions', 'tmwseo' ) );
        }

        check_admin_referer( 'tmwseo_run_niche_serp_mining' );

        $raw_input = isset( $_POST['niche_phrases'] )
            ? sanitize_textarea_field( wp_unslash( (string) $_POST['niche_phrases'] ) )
            : '';

        // Split by newline; each non-empty trimmed line is one phrase.
        $lines   = explode( "\n", str_replace( "\r\n", "\n", $raw_input ) );
        $phrases = [];
        foreach ( $lines as $line ) {
            $phrase = trim( $line );
            if ( $phrase !== '' ) {
                $phrases[] = $phrase;
            }
        }

        $summary = NicheSerpMiningService::run_niche_phrase_batch( $phrases );

        // Store summary in a transient so the redirect target can display it.
        $transient_key = 'tmwseo_niche_mining_result_' . get_current_user_id();
        set_transient( $transient_key, $summary, 5 * MINUTE_IN_SECONDS );

        wp_safe_redirect(
            admin_url( 'admin.php?page=tmwseo-competitor-mining&tmwseo_notice=niche_mining_ran' )
        );
        exit;
    }

    // ─── Model Preview Phrases Re-run ─────────────────────────────────────────

    /**
     * Admin-post handler: re-run preview phrases for an existing published model.
     *
     * Nonce:   tmwseo_rerun_model_preview_phrases_{post_id}
     * Action:  tmwseo_rerun_model_preview_phrases
     * Capability: manage_options
     *
     * On success redirects to post.php?post={id}&action=edit with:
     *   tmwseo_notice=model_preview_rerun
     *   tmwseo_preview_inserted=N
     *   tmwseo_preview_skipped=N
     *   tmwseo_preview_batch_id=... (optional)
     *
     * On failure redirects with tmwseo_notice=model_preview_rerun_failed.
     */
    public static function handle_rerun_model_preview_phrases(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions', 'tmwseo' ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        if ( $post_id <= 0 ) {
            wp_safe_redirect( add_query_arg(
                [ 'tmwseo_notice' => 'model_preview_rerun_failed', 'tmwseo_rerun_reason' => 'invalid_post' ],
                wp_get_referer() ?: admin_url()
            ) );
            exit;
        }

        if ( ! check_admin_referer( 'tmwseo_rerun_model_preview_phrases_' . $post_id ) ) {
            wp_safe_redirect( add_query_arg(
                [
                    'post'           => $post_id,
                    'action'         => 'edit',
                    'tmwseo_notice'  => 'model_preview_rerun_failed',
                    'tmwseo_rerun_reason' => 'invalid_nonce',
                ],
                admin_url( 'post.php' )
            ) );
            exit;
        }

        $result = ModelDiscoveryTrigger::rerun_preview_phrases_for_model( $post_id );

        if ( ! $result['ok'] ) {
            wp_safe_redirect( add_query_arg(
                array_filter( [
                    'post'                => $post_id,
                    'action'              => 'edit',
                    'tmwseo_notice'       => 'model_preview_rerun_failed',
                    'tmwseo_rerun_reason' => $result['reason'],
                ] ),
                admin_url( 'post.php' )
            ) );
            exit;
        }

        $args = array_filter( [
            'post'                     => $post_id,
            'action'                   => 'edit',
            'tmwseo_notice'            => 'model_preview_rerun',
            'tmwseo_preview_inserted'  => $result['preview_inserted'],
            'tmwseo_preview_skipped'   => $result['preview_skipped'],
            'tmwseo_preview_batch_id'  => $result['preview_batch_id'] ?? '',
        ] );

        wp_safe_redirect( add_query_arg( $args, admin_url( 'post.php' ) ) );
        exit;
    }

    // ── Keyword Candidates Bulk Action ────────────────────────────────────────

    public static function handle_keyword_candidates_bulk(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_safe_redirect( add_query_arg(
                [ 'page' => 'tmwseo-keywords', 'tmwseo_notice' => 'kw_bulk_unauthorized' ],
                admin_url( 'admin.php' )
            ) );
            exit;
        }

        // Determine which bulk action was submitted (top or bottom select).
        $raw_action = '';
        if ( isset( $_POST['action'] ) && (string) $_POST['action'] !== '-1' ) {
            $raw_action = sanitize_key( (string) wp_unslash( $_POST['action'] ) );
        }
        if ( $raw_action === '' && isset( $_POST['action2'] ) && (string) $_POST['action2'] !== '-1' ) {
            $raw_action = sanitize_key( (string) wp_unslash( $_POST['action2'] ) );
        }

        $allowed_actions = [ 'tmwseo_kw_bulk_approve', 'tmwseo_kw_bulk_reject', 'tmwseo_kw_bulk_delete', 'tmwseo_kw_resolve_model_entities' ];
        if ( ! in_array( $raw_action, $allowed_actions, true ) ) {
            // Not a bulk action (e.g. search submit with action=-1) — let render proceed.
            return;
        }

        check_admin_referer( 'bulk-keywords' );

        $ids = isset( $_POST['keyword_ids'] ) ? (array) $_POST['keyword_ids'] : [];
        $ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

        // Preserve view/search/paged for the redirect destination.
        $view  = isset( $_POST['view'] )  ? sanitize_key( (string) wp_unslash( $_POST['view'] ) )            : 'candidates';
        $s     = isset( $_POST['_s'] )    ? sanitize_text_field( (string) wp_unslash( $_POST['_s'] ) )        : '';
        $paged = isset( $_POST['_paged'] ) ? max( 1, (int) $_POST['_paged'] )                                 : 1;

        if ( empty( $ids ) ) {
            wp_safe_redirect( add_query_arg( array_filter( [
                'page'            => 'tmwseo-keywords',
                'view'            => $view,
                's'               => $s,
                'paged'           => $paged > 1 ? $paged : null,
                'tmwseo_notice'   => 'kw_bulk_empty',
            ] ), admin_url( 'admin.php' ) ) );
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $count = 0;

        if ( $raw_action === 'tmwseo_kw_resolve_model_entities' ) {
            $repair_summary = ( new ModelKeywordEntityRepairService() )->resolve_selected( $ids );
            wp_safe_redirect( add_query_arg( array_filter( [
                'page'                  => 'tmwseo-keywords',
                'view'                  => $view,
                's'                     => $s,
                'paged'                 => $paged > 1 ? $paged : null,
                'intent_type'           => 'model',
                'model_keyword_filter'  => 'unlinked_model',
                'tmwseo_notice'         => 'kw_model_entity_repair_done',
                'tmwseo_repair_selected'=> (int) ( $repair_summary['selected'] ?? 0 ),
                'tmwseo_repair_linked'  => (int) ( $repair_summary['linked'] ?? 0 ),
                'tmwseo_repair_unresolved' => (int) ( $repair_summary['unresolved'] ?? 0 ),
                'tmwseo_repair_ambiguous'  => (int) ( $repair_summary['ambiguous'] ?? 0 ),
            ] ), admin_url( 'admin.php' ) ) );
            exit;
        }

        foreach ( $ids as $id ) {
            if ( $raw_action === 'tmwseo_kw_bulk_approve' ) {
                $updated = $wpdb->update(
                    $table,
                    [ 'status' => 'approved', 'updated_at' => current_time( 'mysql' ) ],
                    [ 'id' => $id ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                );
                if ( $updated !== false ) { $count++; }
            } elseif ( $raw_action === 'tmwseo_kw_bulk_reject' ) {
                $updated = $wpdb->update(
                    $table,
                    [ 'status' => 'ignored', 'updated_at' => current_time( 'mysql' ) ],
                    [ 'id' => $id ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                );
                if ( $updated !== false ) { $count++; }
            } elseif ( $raw_action === 'tmwseo_kw_bulk_delete' ) {
                $deleted = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
                if ( $deleted ) { $count++; }
            }
        }

        $notice_slug = match ( $raw_action ) {
            'tmwseo_kw_bulk_approve' => 'kw_bulk_approved',
            'tmwseo_kw_bulk_reject'  => 'kw_bulk_rejected',
            'tmwseo_kw_bulk_delete'  => 'kw_bulk_deleted',
            default                  => 'kw_bulk_done',
        };

        wp_safe_redirect( add_query_arg( array_filter( [
            'page'                  => 'tmwseo-keywords',
            'view'                  => $view,
            's'                     => $s,
            'paged'                 => $paged > 1 ? $paged : null,
            'tmwseo_notice'         => $notice_slug,
            'tmwseo_bulk_count'     => $count,
        ] ), admin_url( 'admin.php' ) ) );
        exit;
    }


    public static function preview_csv_keyword_approvals(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( __( 'Insufficient permissions', 'tmwseo' ) ); }
        check_admin_referer( 'tmwseo_preview_csv_keyword_approvals' );

        $upload = $_FILES['tmwseo_csv_keyword_approvals'] ?? null;
        if ( ! is_array( $upload ) || ! self::is_valid_csv_upload( $upload ) ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'tmwseo-keywords', 'tmwseo_notice' => 'csv_keyword_approval_upload_error' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        $source_filename = sanitize_file_name( (string) ( $upload['name'] ?? 'uploaded-keywords.csv' ) );
        $parsed = self::parse_keyword_approval_csv( (string) $upload['tmp_name'] );
        if ( empty( $parsed['ok'] ) ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'tmwseo-keywords', 'tmwseo_notice' => 'csv_keyword_approval_upload_error' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        $preview = self::build_csv_keyword_approval_preview( (array) $parsed['rows'], $source_filename );
        set_transient( 'tmwseo_csv_keyword_approval_preview_' . get_current_user_id(), $preview, 30 * MINUTE_IN_SECONDS );
        Logs::info( 'keywords', '[TMW-SEO-KEYWORDS] [TMW-SEO-CANDIDATES] [TMW-SEO-CSV-BULK-APPROVE] Preview CSV keyword approvals', [
            'source_filename' => $source_filename,
            'summary' => $preview['summary'],
        ] );

        wp_safe_redirect( add_query_arg( [ 'page' => 'tmwseo-keywords', 'tmwseo_notice' => 'csv_keyword_approval_preview_ready' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function apply_csv_keyword_approvals(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( __( 'Insufficient permissions', 'tmwseo' ) ); }
        check_admin_referer( 'tmwseo_apply_csv_keyword_approvals' );
        if ( ! isset( $_POST['confirm_apply'] ) ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'tmwseo-keywords', 'tmwseo_notice' => 'csv_keyword_approval_confirm_required' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        $preview = get_transient( 'tmwseo_csv_keyword_approval_preview_' . get_current_user_id() );
        $posted_token = isset( $_POST['preview_token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['preview_token'] ) ) : '';
        if ( ! is_array( $preview ) || $posted_token === '' || ! hash_equals( (string) ( $preview['token'] ?? '' ), $posted_token ) ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'tmwseo-keywords', 'tmwseo_notice' => 'csv_keyword_approval_missing_preview' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $columns = self::get_table_columns( $table );
        $has_updated_at = in_array( 'updated_at', $columns, true );
        $ready_rows = array_values( array_filter( (array) ( $preview['rows'] ?? [] ), static function ( $row ): bool {
            return is_array( $row ) && (string) ( $row['action'] ?? '' ) === 'approve' && empty( $row['applied'] );
        } ) );
        $batch = array_slice( $ready_rows, 0, 250 );
        $timestamp = current_time( 'mysql' );
        $admin_user_id = get_current_user_id();
        $rollback_rows = [];
        $updated = 0;

        foreach ( $batch as $row ) {
            $candidate_id = (int) ( $row['candidate_id'] ?? 0 );
            if ( $candidate_id <= 0 ) { continue; }
            $current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $candidate_id ), ARRAY_A );
            if ( ! is_array( $current ) ) { continue; }
            $old_status = (string) ( $current['status'] ?? '' );
            $current_keyword_normalized = self::normalize_keyword_text( (string) ( $current['keyword'] ?? '' ) );
            if ( $current_keyword_normalized === '' || $current_keyword_normalized !== self::normalize_keyword_text( (string) ( $row['csv_keyword'] ?? '' ) ) ) { continue; }
            if ( ! self::is_csv_keyword_approval_candidate_safe( $current, $columns ) ) { continue; }

            $rollback_row = [
                'candidate_id' => $candidate_id,
                'keyword' => (string) ( $current['keyword'] ?? '' ),
                'old_status' => $old_status,
                'new_status' => 'approved',
                'timestamp' => $timestamp,
                'admin_user_id' => $admin_user_id,
                'source_csv_filename' => (string) ( $preview['source_filename'] ?? '' ),
                'source_row_number' => (int) ( $row['row_number'] ?? 0 ),
            ];
            Logs::info( 'keywords', '[TMW-SEO-KEYWORDS] [TMW-SEO-CANDIDATES] [TMW-SEO-CSV-BULK-APPROVE] Prepared rollback row before CSV keyword approval', $rollback_row );

            $set_sql = $has_updated_at ? 'status = %s, updated_at = %s' : 'status = %s';
            $sql_args = $has_updated_at
                ? array_merge( [ 'approved', $timestamp, $candidate_id ], self::queued_keyword_candidate_statuses() )
                : array_merge( [ 'approved', $candidate_id ], self::queued_keyword_candidate_statuses() );
            $status_placeholders = implode( ', ', array_fill( 0, count( self::queued_keyword_candidate_statuses() ), '%s' ) );
            $updated_result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$set_sql} WHERE id = %d AND status IN ({$status_placeholders})", ...$sql_args ) );
            if ( $updated_result ) {
                $updated++;
                $rollback_rows[] = $rollback_row;
                Logs::info( 'keywords', '[TMW-SEO-KEYWORDS] [TMW-SEO-CANDIDATES] [TMW-SEO-CSV-BULK-APPROVE] Applied CSV keyword approval row', [
                    'candidate_id' => $candidate_id,
                    'keyword' => (string) ( $current['keyword'] ?? '' ),
                    'old_status' => $old_status,
                    'new_status' => 'approved',
                    'source_row_number' => (int) ( $row['row_number'] ?? 0 ),
                    'source_filename' => (string) ( $preview['source_filename'] ?? '' ),
                ] );
                foreach ( $preview['rows'] as &$preview_row ) {
                    if ( is_array( $preview_row ) && (int) ( $preview_row['candidate_id'] ?? 0 ) === $candidate_id && (int) ( $preview_row['row_number'] ?? 0 ) === (int) ( $row['row_number'] ?? 0 ) ) {
                        $preview_row['applied'] = true;
                        $preview_row['action'] = 'skip';
                        $preview_row['reason'] = 'Applied in a previous batch.';
                        break;
                    }
                }
                unset( $preview_row );
            }
        }

        $rollback = [
            'token' => wp_generate_password( 20, false ),
            'generated_at' => $timestamp,
            'rows' => $rollback_rows,
        ];
        set_transient( 'tmwseo_csv_keyword_approval_rollback_' . get_current_user_id(), $rollback, DAY_IN_SECONDS );

        $remaining = count( array_filter( (array) ( $preview['rows'] ?? [] ), static function ( $row ): bool {
            return is_array( $row ) && (string) ( $row['action'] ?? '' ) === 'approve' && empty( $row['applied'] );
        } ) );
        set_transient( 'tmwseo_csv_keyword_approval_preview_' . get_current_user_id(), $preview, 30 * MINUTE_IN_SECONDS );

        Logs::info( 'keywords', '[TMW-SEO-KEYWORDS] [TMW-SEO-CANDIDATES] [TMW-SEO-CSV-BULK-APPROVE] Applied CSV keyword approval batch', [
            'updated' => $updated,
            'remaining' => $remaining,
            'source_filename' => (string) ( $preview['source_filename'] ?? '' ),
        ] );

        wp_safe_redirect( add_query_arg( [
            'page' => 'tmwseo-keywords',
            'tmwseo_notice' => 'csv_keyword_approval_applied',
            'tmwseo_bulk_count' => $updated,
            'tmwseo_remaining' => $remaining,
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function download_csv_keyword_approval_rollback(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( __( 'Insufficient permissions', 'tmwseo' ) ); }
        check_admin_referer( 'tmwseo_download_csv_keyword_approval_rollback' );
        $rollback = get_transient( 'tmwseo_csv_keyword_approval_rollback_' . get_current_user_id() );
        $token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ) : '';
        if ( ! is_array( $rollback ) || $token === '' || ! hash_equals( (string) ( $rollback['token'] ?? '' ), $token ) ) {
            wp_die( esc_html__( 'Rollback CSV expired or unavailable.', 'tmwseo' ) );
        }

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="tmwseo-csv-keyword-approval-rollback-' . gmdate( 'Ymd-His' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'candidate_id', 'keyword', 'old_status', 'new_status', 'timestamp', 'admin_user_id', 'source_csv_filename', 'source_row_number' ] );
        foreach ( (array) ( $rollback['rows'] ?? [] ) as $row ) {
            fputcsv( $out, [
                (int) ( $row['candidate_id'] ?? 0 ),
                (string) ( $row['keyword'] ?? '' ),
                (string) ( $row['old_status'] ?? '' ),
                (string) ( $row['new_status'] ?? '' ),
                (string) ( $row['timestamp'] ?? '' ),
                (int) ( $row['admin_user_id'] ?? 0 ),
                (string) ( $row['source_csv_filename'] ?? '' ),
                (int) ( $row['source_row_number'] ?? 0 ),
            ] );
        }
        fclose( $out );
        exit;
    }

    private static function is_valid_csv_upload( array $upload ): bool {
        if ( (int) ( $upload['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) { return false; }
        $name = (string) ( $upload['name'] ?? '' );
        $tmp = (string) ( $upload['tmp_name'] ?? '' );
        if ( $name === '' || $tmp === '' || ! is_uploaded_file( $tmp ) ) { return false; }
        $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        if ( $ext !== 'csv' ) { return false; }
        $type = (string) ( $upload['type'] ?? '' );
        return $type === '' || in_array( strtolower( $type ), [ 'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream' ], true );
    }

    /** @return array{ok:bool,rows?:array<int,array<string,mixed>>} */
    private static function parse_keyword_approval_csv( string $path ): array {
        $handle = fopen( $path, 'r' );
        if ( ! $handle ) { return [ 'ok' => false ]; }
        $headers = fgetcsv( $handle );
        if ( ! is_array( $headers ) ) { fclose( $handle ); return [ 'ok' => false ]; }
        $headers = array_map( static fn( $header ): string => trim( (string) $header ), $headers );
        $normalized_headers = array_map( [ __CLASS__, 'normalize_csv_header' ], $headers );
        $keyword_names = array_map( [ __CLASS__, 'normalize_csv_header' ], [ 'keyword', 'Keyword', 'candidate', 'Candidate', 'phrase', 'Phrase', 'search_term', 'Search Term' ] );
        $id_names = array_map( [ __CLASS__, 'normalize_csv_header' ], [ 'id', 'ID', 'candidate_id', 'Candidate ID', 'keyword_candidate_id' ] );
        $keyword_index = self::first_header_index( $normalized_headers, $keyword_names );
        if ( $keyword_index === null ) { fclose( $handle ); return [ 'ok' => false ]; }
        $id_index = self::first_header_index( $normalized_headers, $id_names );

        $rows = [];
        $row_number = 1;
        while ( ( $data = fgetcsv( $handle ) ) !== false ) {
            $row_number++;
            if ( ! is_array( $data ) ) { continue; }
            $keyword = sanitize_text_field( (string) ( $data[ $keyword_index ] ?? '' ) );
            $candidate_id = $id_index === null ? 0 : absint( $data[ $id_index ] ?? 0 );
            $rows[] = [
                'row_number' => $row_number,
                'keyword' => $keyword,
                'candidate_id' => $candidate_id,
            ];
        }
        fclose( $handle );
        return [ 'ok' => true, 'rows' => $rows ];
    }

    private static function normalize_csv_header( string $header ): string {
        return strtolower( preg_replace( '/[^a-z0-9]+/', '_', trim( $header ) ) ?? '' );
    }

    /** @param string[] $headers @param string[] $names */
    private static function first_header_index( array $headers, array $names ): ?int {
        foreach ( $headers as $index => $header ) {
            if ( in_array( $header, $names, true ) ) { return (int) $index; }
        }
        return null;
    }

    /** @param array<int,array<string,mixed>> $csv_rows @return array<string,mixed> */
    private static function build_csv_keyword_approval_preview( array $csv_rows, string $source_filename ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $columns = self::get_table_columns( $table );
        $select_columns = array_values( array_intersect( [ 'id', 'keyword', 'status', 'intent_type', 'intent', 'page_type', 'entity_type', 'keyword_class', 'volume', 'difficulty', 'kd', 'competition' ], $columns ) );
        if ( empty( $select_columns ) ) { $select_columns = [ 'id', 'keyword', 'status' ]; }
        $quoted_columns = implode( ', ', array_map( static fn( string $column ): string => '`' . esc_sql( $column ) . '`', $select_columns ) );
        $candidate_rows = (array) $wpdb->get_results( "SELECT {$quoted_columns} FROM {$table}", ARRAY_A );
        $by_id = [];
        $by_keyword = [];
        foreach ( $candidate_rows as $candidate ) {
            $id = (int) ( $candidate['id'] ?? 0 );
            $normalized = self::normalize_keyword_text( (string) ( $candidate['keyword'] ?? '' ) );
            if ( $id > 0 ) { $by_id[ $id ] = $candidate; }
            if ( $normalized !== '' ) { $by_keyword[ $normalized ][] = $candidate; }
        }

        $summary = [
            'total_csv_rows' => count( $csv_rows ),
            'matched_candidates' => 0,
            'ready_to_approve' => 0,
            'already_approved_skipped' => 0,
            'ignored_rejected_skipped' => 0,
            'no_match_skipped' => 0,
            'duplicate_matches' => 0,
            'ambiguous_matches' => 0,
            'invalid_rows' => 0,
        ];
        $rows = [];
        $seen_candidate_ids = [];

        foreach ( $csv_rows as $csv_row ) {
            $keyword = sanitize_text_field( (string) ( $csv_row['keyword'] ?? '' ) );
            $normalized = self::normalize_keyword_text( $keyword );
            $candidate_id = (int) ( $csv_row['candidate_id'] ?? 0 );
            $candidate = null;
            $action = 'skip';
            $reason = '';

            if ( $normalized === '' ) {
                $summary['invalid_rows']++;
                $reason = 'Missing keyword value.';
            } elseif ( $candidate_id > 0 ) {
                $candidate = $by_id[ $candidate_id ] ?? null;
                if ( ! is_array( $candidate ) ) {
                    $summary['no_match_skipped']++;
                    $reason = 'Candidate ID not found.';
                } elseif ( self::normalize_keyword_text( (string) ( $candidate['keyword'] ?? '' ) ) !== $normalized ) {
                    $summary['ambiguous_matches']++;
                    $action = 'warning';
                    $reason = 'Candidate ID exists, but keyword does not match the CSV keyword.';
                }
            } else {
                $matches = $by_keyword[ $normalized ] ?? [];
                if ( count( $matches ) === 1 ) {
                    $candidate = $matches[0];
                } elseif ( count( $matches ) > 1 ) {
                    $summary['ambiguous_matches']++;
                    $action = 'warning';
                    $reason = 'Multiple candidates match this normalized keyword.';
                } else {
                    $summary['no_match_skipped']++;
                    $reason = 'No saved keyword candidate matches this normalized keyword.';
                }
            }

            if ( is_array( $candidate ) ) {
                $summary['matched_candidates']++;
                $candidate_id = (int) ( $candidate['id'] ?? 0 );
                if ( $action !== 'warning' ) {
                    if ( isset( $seen_candidate_ids[ $candidate_id ] ) ) {
                        $summary['duplicate_matches']++;
                        $action = 'warning';
                        $reason = 'Duplicate CSV row targets the same candidate; duplicates are not auto-approved.';
                    } elseif ( (string) ( $candidate['status'] ?? '' ) === 'approved' ) {
                        $summary['already_approved_skipped']++;
                        $reason = 'Candidate is already approved.';
                    } elseif ( in_array( (string) ( $candidate['status'] ?? '' ), [ 'ignored', 'rejected', 'deleted', 'trash' ], true ) ) {
                        $summary['ignored_rejected_skipped']++;
                        $reason = 'Candidate is ignored, rejected, or deleted.';
                    } elseif ( ! self::is_csv_keyword_approval_candidate_safe( $candidate, $columns ) ) {
                        $action = 'warning';
                        $reason = 'Candidate is not a queued/new/pending model keyword candidate.';
                    } else {
                        $action = 'approve';
                        $reason = 'Queued model keyword candidate matches reviewed CSV row.';
                        $summary['ready_to_approve']++;
                        $seen_candidate_ids[ $candidate_id ] = true;
                    }
                }
            }

            $rows[] = [
                'row_number' => (int) ( $csv_row['row_number'] ?? 0 ),
                'csv_keyword' => $keyword,
                'candidate_id' => is_array( $candidate ) ? (int) ( $candidate['id'] ?? 0 ) : $candidate_id,
                'candidate_keyword' => is_array( $candidate ) ? (string) ( $candidate['keyword'] ?? '' ) : '',
                'status' => is_array( $candidate ) ? (string) ( $candidate['status'] ?? '' ) : '',
                'type' => is_array( $candidate ) ? self::candidate_type_label( $candidate, $columns ) : '',
                'volume' => is_array( $candidate ) ? (string) ( $candidate['volume'] ?? '' ) : '',
                'kd' => is_array( $candidate ) ? (string) ( $candidate['difficulty'] ?? $candidate['kd'] ?? $candidate['competition'] ?? '' ) : '',
                'action' => $action,
                'reason' => $reason,
            ];
        }

        return [
            'token' => wp_generate_password( 20, false ),
            'source_filename' => $source_filename,
            'generated_at' => current_time( 'mysql' ),
            'summary' => $summary,
            'rows' => $rows,
        ];
    }

    /** @return string[] */
    private static function get_table_columns( string $table ): array {
        global $wpdb;
        $safe_table = esc_sql( $table );
        $columns = (array) $wpdb->get_results( "SHOW COLUMNS FROM {$safe_table}", ARRAY_A );
        return array_values( array_map( static fn( $column ): string => (string) ( $column['Field'] ?? $column['field'] ?? '' ), $columns ) );
    }

    private static function normalize_keyword_text( string $keyword ): string {
        return strtolower( trim( preg_replace( '/\s+/', ' ', $keyword ) ?? '' ) );
    }

    /** @param array<string,mixed> $candidate @param string[] $columns */
    private static function is_csv_keyword_approval_candidate_safe( array $candidate, array $columns ): bool {
        if ( ! in_array( (string) ( $candidate['status'] ?? '' ), self::queued_keyword_candidate_statuses(), true ) ) { return false; }
        $type = self::candidate_type_label( $candidate, $columns );
        return $type === '' || strtolower( $type ) === 'model';
    }

    /** @return string[] */
    private static function queued_keyword_candidate_statuses(): array {
        return [ 'queued', 'new', 'pending_review', 'queued_for_review', 'pending review', 'pending' ];
    }

    /** @param array<string,mixed> $candidate @param string[] $columns */
    private static function candidate_type_label( array $candidate, array $columns ): string {
        foreach ( [ 'intent_type', 'intent', 'page_type', 'entity_type', 'keyword_class' ] as $column ) {
            if ( in_array( $column, $columns, true ) && isset( $candidate[ $column ] ) && (string) $candidate[ $column ] !== '' ) {
                return (string) $candidate[ $column ];
            }
        }
        return '';
    }

    public static function preview_keyword_cleanup(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( __( 'Insufficient permissions', 'tmwseo' ) ); }
        check_admin_referer( 'tmwseo_preview_keyword_cleanup' );
        $include_ignored = isset( $_POST['include_ignored'] );
        $include_clusters = isset( $_POST['include_clusters'] );
        $preview = self::run_keyword_cleanup_scan( $include_ignored );
        set_transient( 'tmwseo_keyword_cleanup_preview_' . get_current_user_id(), $preview, 30 * MINUTE_IN_SECONDS );
        Logs::info( 'keywords', '[TMW-SEO-CLEANUP] Preview keyword cleanup', $preview );
        wp_safe_redirect( add_query_arg( [
            'page' => 'tmwseo-keywords',
            'tmwseo_notice' => 'keyword_cleanup_preview_ready',
            'tmwseo_cleanup_include_ignored' => $include_ignored ? 1 : 0,
            'tmwseo_cleanup_include_clusters' => $include_clusters ? 1 : 0,
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function apply_keyword_cleanup(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( __( 'Insufficient permissions', 'tmwseo' ) ); }
        check_admin_referer( 'tmwseo_apply_keyword_cleanup' );
        if ( ! isset( $_POST['confirm_apply'] ) ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'tmwseo-keywords', 'tmwseo_notice' => 'keyword_cleanup_confirm_required' ], admin_url( 'admin.php' ) ) );
            exit;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $include_ignored = isset( $_POST['include_ignored'] ) && (string) $_POST['include_ignored'] === '1';
        $scan = self::run_keyword_cleanup_scan( $include_ignored );
        $updated = 0;
        foreach ( $scan['matches'] as $row ) {
            if ( in_array( (string) ( $row['status'] ?? '' ), [ 'approved', 'ignored', 'assigned' ], true ) ) { continue; }
            $result = $wpdb->update( $table, [ 'status' => 'ignored', 'updated_at' => current_time( 'mysql' ) ], [ 'id' => (int) $row['id'] ], [ '%s', '%s' ], [ '%d' ] );
            if ( $result ) { $updated++; }
        }
        Logs::info( 'keywords', '[TMW-SEO-CLEANUP] Applied keyword cleanup', [ 'updated' => $updated ] );
        wp_safe_redirect( add_query_arg( [
            'page' => 'tmwseo-keywords',
            'tmwseo_notice' => 'keyword_cleanup_applied',
            'tmwseo_bulk_count' => $updated,
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /** @return array<string,mixed> */
    private static function run_keyword_cleanup_scan( bool $include_ignored ): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results( 'SELECT id, keyword, status FROM ' . $wpdb->prefix . 'tmw_keyword_candidates', ARRAY_A );
        $matches = [];
        $approved_protected = 0;
        $already_ignored = 0;
        $would_ignore = 0;
        foreach ( $rows as $row ) {
            $status = (string) ( $row['status'] ?? '' );
            $result = KeywordCleanupClassifier::classify( (string) ( $row['keyword'] ?? '' ) );
            if ( ! $result['match'] ) { continue; }
            if ( in_array( $status, [ 'approved', 'assigned' ], true ) ) { $approved_protected++; continue; }
            if ( $status === 'ignored' && ! $include_ignored ) { $already_ignored++; continue; }
            if ( count( $matches ) < 200 ) {
                $matches[] = [ 'id' => (int) $row['id'], 'keyword' => (string) $row['keyword'], 'status' => $status, 'reason' => (string) $result['reason'] ];
            }
            if ( $status !== 'ignored' ) { $would_ignore++; }
        }
        return [
            'total_scanned' => count( $rows ),
            'would_ignore' => $would_ignore,
            'approved_protected' => $approved_protected,
            'already_ignored' => $already_ignored,
            'matches' => $matches,
        ];
    }
}
