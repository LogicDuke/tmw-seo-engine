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
use TMWSEO\Engine\Content\ContentEngine;
use TMWSEO\Engine\Content\ContentGenerationGate;
use TMWSEO\Engine\KeywordIntelligence\ModelDiscoveryTrigger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AdminAjaxHandlers {

    /**
     * wp_ajax_tmwseo_generate_now
     *
     * Explicit model Generate clicks now run inline through ContentEngine so
     * the editor gets immediate content instead of queue-only feedback.
     * Other flows (including Refresh Keywords) keep the existing queue path.
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
        if ( ! in_array( $strategy, [ 'template', 'openai', 'claude' ], true ) ) {
            if ( class_exists( '\TMWSEO\Engine\Services\OpenAI' ) && \TMWSEO\Engine\Services\OpenAI::is_configured() ) {
                $strategy = 'openai';
            } elseif ( class_exists( '\TMWSEO\Engine\Services\Anthropic' ) && \TMWSEO\Engine\Services\Anthropic::is_configured() ) {
                $strategy = 'claude';
            } else {
                $strategy = 'template';
            }
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
        $context   = 'video_or_post';
        if ( $post_type === 'model' ) {
            $context = 'model';
        } elseif ( $post_type === 'tmw_category_page' ) {
            $context = 'category_page';
        }

        $job_payload = [
            'trigger'               => 'manual',
            'generated_via'         => 'editor_metabox',
            'context'               => $context,
            'strategy'              => $strategy,
            'insert_block'          => $insert_block,
            'refresh_keywords_only' => $refresh_keywords_only,
            'manual_model_generate' => ( $post_type === 'model' && ! $refresh_keywords_only ) ? 1 : 0,
            'explicit_generate'     => ( $post_type === 'model' && ! $refresh_keywords_only ) ? 1 : 0,
        ];

        /**
         * Fix path: model Generate should behave like a direct operator action,
         * not just queue and hope the worker finishes before the user refreshes.
         * Refresh Keywords stays queued because it is cheap and already works.
         */
        if ( $post_type === 'model' && ! $refresh_keywords_only ) {
            $before_content = (string) get_post_field( 'post_content', $post_id );
            $before_done    = (string) get_post_meta( $post_id, '_tmwseo_optimize_done', true );

            // Capture PHP notices/warnings that would otherwise leak before the
            // JSON response body and cause "Unexpected token '<'" parse errors in JS.
            ob_start();
            $generation_threw = false;
            try {
                ContentEngine::run_optimize_job( [
                    'entity_id' => $post_id,
                    'payload'   => $job_payload,
                ] );
            } catch ( \Throwable $e ) {
                $generation_threw = true;
                $leaked = (string) ob_get_clean();
                if ( $leaked !== '' ) {
                    Logs::warning( 'admin', '[TMW-ADMIN] PHP output leaked during generation (exception path)', [
                        'post_id' => $post_id,
                        'snippet' => substr( $leaked, 0, 500 ),
                    ] );
                }
                Logs::error( 'admin', '[TMW-ADMIN] Inline model generation failed', [
                    'post_id' => $post_id,
                    'error'   => $e->getMessage(),
                ] );
                wp_send_json_error( [
                    'message' => __( 'Generation failed. Check logs.', 'tmwseo' ),
                ], 500 );
            }
            if ( ! $generation_threw ) {
                $leaked_output = (string) ob_get_clean();
                if ( $leaked_output !== '' ) {
                    Logs::warning( 'admin', '[TMW-ADMIN] PHP output leaked during generation (pre-JSON)', [
                        'post_id' => $post_id,
                        'snippet' => substr( $leaked_output, 0, 500 ),
                    ] );
                }
            }

            clean_post_cache( $post_id );

            $after_content = (string) get_post_field( 'post_content', $post_id );
            $after_done    = (string) get_post_meta( $post_id, '_tmwseo_optimize_done', true );
            $gate_raw      = (string) get_post_meta( $post_id, ContentGenerationGate::META_GATE_RESULT, true );
            $gate_result   = json_decode( $gate_raw, true );
            $gate_reasons  = is_array( $gate_result ) && ! empty( $gate_result['reasons'] ) && is_array( $gate_result['reasons'] )
                ? array_values( array_map( 'strval', $gate_result['reasons'] ) )
                : [];

            if ( $after_done === 'blocked_content_gate' ) {
                $message = __( 'Generation blocked by content prerequisites.', 'tmwseo' );
                if ( ! empty( $gate_reasons ) ) {
                    $message .= ' ' . implode( ', ', $gate_reasons );
                }

                wp_send_json_error( [
                    'message' => $message,
                    'reasons' => $gate_reasons,
                ], 409 );
            }

            $content_changed = trim( $after_content ) !== '' && trim( $after_content ) !== trim( $before_content );
            $run_completed   = $after_done !== '' && $after_done !== $before_done;

            if ( $content_changed || $run_completed ) {
                wp_send_json_success( [
                    'generated_now' => true,
                    'reload'        => true,
                    'message'       => __( 'SEO generated. Reloading...', 'tmwseo' ),
                ] );
            }

            wp_send_json_error( [
                'message' => __( 'Generation finished but no content was written. Check logs.', 'tmwseo' ),
            ], 500 );
        }

        Jobs::enqueue( 'optimize_post', (string) $post_type, $post_id, $job_payload );

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

        wp_send_json_success( [
            'queued'  => true,
            'message' => $refresh_keywords_only
                ? __( 'Keyword refresh queued. Refresh in a few seconds.', 'tmwseo' )
                : __( 'Generation queued. Refresh in a few seconds.', 'tmwseo' ),
        ] );
    }

    /**
     * wp_ajax_tmwseo_rerun_model_preview_phrases
     *
     * Gutenberg-safe AJAX endpoint for "Re-run Preview Phrases" metabox button.
     * Delegates to ModelDiscoveryTrigger::rerun_preview_phrases_for_model() —
     * no discovery logic lives here.
     *
     * Nonce action: tmwseo_rerun_preview_phrases_{post_id}
     */
    public static function ajax_rerun_model_preview_phrases(): void {
        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        if ( $post_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid post.', 'tmwseo' ) ], 400 );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'tmwseo' ) ], 403 );
        }

        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['nonce'] ) ) : '';
        if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'tmwseo_rerun_preview_phrases_' . $post_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid or expired nonce.', 'tmwseo' ) ], 403 );
        }

        $result = ModelDiscoveryTrigger::rerun_preview_phrases_for_model( $post_id );

        if ( ! $result['ok'] ) {
            $reason_labels = [
                'invalid_post'    => __( 'Invalid model post.', 'tmwseo' ),
                'wrong_post_type' => __( 'Post is not a model.', 'tmwseo' ),
                'not_published'   => __( 'Model is not published.', 'tmwseo' ),
                'empty_title'     => __( 'Model title is empty.', 'tmwseo' ),
            ];
            $message = $reason_labels[ $result['reason'] ] ?? __( 'Preview phrase re-run failed.', 'tmwseo' );
            wp_send_json_error( [ 'message' => $message, 'reason' => $result['reason'] ], 422 );
        }

        wp_send_json_success( [
            'reload'   => true,
            'inserted' => $result['preview_inserted'],
            'skipped'  => $result['preview_skipped'],
            'message'  => sprintf(
                /* translators: 1: inserted count, 2: skipped count */
                __( 'Preview phrases rebuilt. Inserted: %1$d, skipped: %2$d. Reloading...', 'tmwseo' ),
                $result['preview_inserted'],
                $result['preview_skipped']
            ),
        ] );
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
