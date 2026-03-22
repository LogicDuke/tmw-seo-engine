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
use TMWSEO\Engine\Db\Jobs;
use TMWSEO\Engine\Worker;
use TMWSEO\Engine\JobWorker;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Keywords\SeedRegistry;

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
        wp_safe_redirect( admin_url( 'admin.php?page=' . \TMWSEO\Engine\Admin::MENU_SLUG . '&tmwseo_notice=worker_ran' ) );
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

        wp_safe_redirect( admin_url(
            'admin.php?page=tmwseo-keywords&tmwseo_notice=imported'
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
        $fh = fopen( $file_path, 'r' );
        if ( ! $fh ) {
            wp_die( __( 'Could not read CSV', 'tmwseo' ) );
        }

        $header = fgetcsv( $fh );
        if ( ! is_array( $header ) ) {
            $header = [];
        }

        $kw_col       = 0;
        $vol_col      = null;
        $type_col     = null;
        $priority_col = null;

        foreach ( $header as $i => $col ) {
            $c = strtolower( trim( (string) $col ) );
            if ( $c === '' ) {
                continue;
            }
            if ( strpos( $c, 'keyword' ) !== false ) {
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

        $batch_id = \TMWSEO\Engine\Keywords\ExpansionCandidateRepository::make_batch_id( 'csv_import' );

        global $wpdb;
        $raw_table  = $wpdb->prefix . 'tmw_keyword_raw';
        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';

        $raw_ins  = 0;
        $cand_ins = 0;
        $rejected = 0;

        while ( ( $row = fgetcsv( $fh ) ) !== false ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $kw = isset( $row[ $kw_col ] ) ? trim( (string) $row[ $kw_col ] ) : '';
            if ( $kw === '' ) {
                continue;
            }

            $reason = null;
            if ( ! \TMWSEO\Engine\Keywords\KeywordValidator::is_relevant( $kw, $reason ) ) {
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

            SeedRegistry::register_trusted_seed( $kw, 'approved_import', 'import', 0, $seed_type, $priority, $batch_id ?? '', $source );

            $vol = null;
            if ( $vol_col !== null && isset( $row[ $vol_col ] ) ) {
                $v = preg_replace( '/[^0-9]/', '', (string) $row[ $vol_col ] );
                if ( $v !== '' ) {
                    $vol = (int) $v;
                }
            }

            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$raw_table} (keyword, source, source_ref, volume, cpc, competition, raw, discovered_at)
                 VALUES (%s, %s, %s, %d, %f, %f, %s, %s)",
                $kw, 'import', $source, (int) ( $vol ?? 0 ), 0.0, 0.0, null, current_time( 'mysql' )
            ) );
            $raw_ins++;

            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$cand_table} WHERE keyword=%s LIMIT 1", $kw ) );
            if ( $exists ) {
                continue;
            }

            $canonical = \TMWSEO\Engine\Keywords\KeywordValidator::normalize( $kw );
            $intent    = \TMWSEO\Engine\Keywords\KeywordValidator::infer_intent( $kw );

            $wpdb->insert( $cand_table, [
                'keyword'    => $kw,
                'canonical'  => $canonical,
                'status'     => 'new',
                'intent'     => $intent,
                'volume'     => $vol,
                'cpc'        => null,
                'difficulty' => null,
                'opportunity'=> null,
                'sources'    => 'import:' . $source,
                'notes'      => null,
                'updated_at' => current_time( 'mysql' ),
            ], [ '%s', '%s', '%s', '%s', '%d', '%f', '%f', '%f', '%s', '%s', '%s' ] );
            $cand_ins++;
        }

        fclose( $fh );

        Logs::info( 'import', 'Imported keywords', [
            'raw'      => $raw_ins,
            'candidates'=> $cand_ins,
            'rejected' => $rejected,
            'source'   => $source,
            'file'     => $file_path,
        ] );

        if ( $run_kd ) {
            Jobs::enqueue( 'keyword_cycle', 'system', 0, [
                'trigger' => 'import',
                'mode'    => 'import_only',
            ] );
            Worker::run();
        }

        return [ 'raw' => $raw_ins, 'cand' => $cand_ins, 'rej' => $rejected ];
    }
}
