<?php
/**
 * Admin AJAX Handlers
 *
 * Extracted from class-admin.php (god class reduction).
 * Contains all wp_ajax_tmwseo_* handlers that were previously inline
 * inside the Admin class. All original action registrations in Admin::init()
 * now delegate here via static method calls — no hook strings changed.
 *
 * @package TMWSEO\Engine\Admin
 * @since   5.1.1
 */
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AdminAjaxHandlers {

    /**
     * wp_ajax_tmwseo_generate_now
     *
     * Enqueues an optimize_post job for the given post ID, then fires a
     * non-blocking kick of the worker so the job starts within ~1 second.
     */
    public static function ajax_generate_now(): void {
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( $post_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid post.', 'tmwseo' ) ], 400 );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'tmwseo' ) ], 403 );
        }

        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['nonce'] ) ) : '';
        if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'tmwseo_generate_' . $post_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid or expired nonce.', 'tmwseo' ) ], 403 );
        }

        $strategy = sanitize_key( (string) ( $_POST['strategy'] ?? '' ) );
        if ( ! in_array( $strategy, [ 'template', 'openai' ], true ) ) {
            $strategy = 'openai';
        }

        $insert_block          = ! empty( $_POST['insert_block'] ) ? 1 : 0;
        $refresh_keywords_only = ! empty( $_POST['refresh_keywords_only'] ) ? 1 : 0;

        Logs::info( 'admin', '[TMW-ADMIN] ajax_generate_now HIT', [
            'post_id'               => $post_id,
            'strategy'              => $strategy,
            'insert_block'          => $insert_block,
            'refresh_keywords_only' => $refresh_keywords_only,
        ] );

        $post_type = get_post_type( $post_id ) ?: 'post';
        Jobs::enqueue( 'optimize_post', (string) $post_type, $post_id, [
            'trigger'               => 'manual',
            'strategy'              => $strategy,
            'insert_block'          => $insert_block,
            'refresh_keywords_only' => $refresh_keywords_only,
        ] );

        Logs::info( 'admin', '[TMW-QUEUE] optimize_post queued from ajax_generate_now', [
            'post_id'               => $post_id,
            'post_type'             => (string) $post_type,
            'refresh_keywords_only' => $refresh_keywords_only,
        ] );

        // Non-blocking kick — starts the worker within ~1 s without blocking the response.
        wp_remote_post( admin_url( 'admin-ajax.php?action=tmwseo_kick_worker' ), [
            'timeout'   => 0.01,
            'blocking'  => false,
            'cookies'   => $_COOKIE,
            'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
        ] );

        wp_send_json_success( [ 'queued' => true ] );
    }

    /**
     * wp_ajax_tmwseo_kick_worker
     *
     * Synchronous worker kick — runs the next queued job inline.
     * Called by the non-blocking fire-and-forget in ajax_generate_now().
     */
    public static function ajax_kick_worker(): void {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'tmwseo' ) ], 403 );
        }

        \TMWSEO\Engine\Worker::run();
        wp_send_json_success( [ 'ran' => true ] );
    }
}
