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
use TMWSEO\Engine\Content\VideoGeneratePolicy;
use TMWSEO\Engine\Content\VideoContentBuilder;
use TMWSEO\Engine\Content\ModelCopyCleanup;
use TMWSEO\Engine\Content\RankMathMapper;
use TMWSEO\Engine\KeywordIntelligence\ModelDiscoveryTrigger;
use TMWSEO\Engine\Model\Rollback;
use TMWSEO\Engine\Keywords\KeywordPoolCandidateRepository;
use TMWSEO\Engine\Keywords\KeywordPoolClassificationApplyService;
use TMWSEO\Engine\Keywords\ModelFallbackKeywordPackBuilder;
use TMWSEO\Engine\Keywords\ModelKeywordPoolClassifier;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AdminAjaxHandlers {
    private const AI_MARKER = '<!-- TMWSEO:AI -->';
    private const AI_MARKER_START = '<!-- TMWSEO:AI:START -->';
    private const AI_MARKER_END   = '<!-- TMWSEO:AI:END -->';

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

        $post_type = get_post_type( $post_id ) ?: 'post';
        $dry       = isset( $_POST['dry'] ) ? (int) wp_unslash( $_POST['dry'] ) : null;
        $strategy  = ContentEngine::normalize_generate_strategy(
            (string) ( $_POST['strategy'] ?? '' ),
            (string) $post_type,
            $dry
        );

        $insert_block          = ! empty( $_POST['insert_block'] ) ? 1 : 0;
        $refresh_keywords_only = ! empty( $_POST['refresh_keywords_only'] ) ? 1 : 0;

        Logs::info( 'admin', '[TMW-ADMIN] ajax_generate_now HIT', [
            'post_id'               => $post_id,
            'strategy'              => $strategy,
            'insert_block'          => $insert_block,
            'refresh_keywords_only' => $refresh_keywords_only,
        ] );

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

        if ( $post_type === 'model' && $refresh_keywords_only ) {
            ob_start();
            try {
                ContentEngine::run_optimize_job( [
                    'entity_id' => $post_id,
                    'payload'   => $job_payload,
                ] );
            } catch ( \Throwable $e ) {
                $leaked = (string) ob_get_clean();
                if ( $leaked !== '' ) {
                    Logs::warning( 'admin', '[TMW-KEYWORDS] PHP output leaked during inline keyword refresh', [
                        'post_id' => $post_id,
                        'snippet' => substr( $leaked, 0, 500 ),
                    ] );
                }
                Logs::error( 'admin', '[TMW-KEYWORDS] Inline model keyword refresh failed', [
                    'post_id' => $post_id,
                    'error'   => $e->getMessage(),
                ] );
                wp_send_json_error( [
                    'message' => __( 'Keyword refresh failed. Check logs.', 'tmwseo' ),
                ], 500 );
            }
            $leaked_output = (string) ob_get_clean();
            if ( $leaked_output !== '' ) {
                Logs::warning( 'admin', '[TMW-KEYWORDS] PHP output leaked during inline keyword refresh (pre-JSON)', [
                    'post_id' => $post_id,
                    'snippet' => substr( $leaked_output, 0, 500 ),
                ] );
            }

            clean_post_cache( $post_id );
            $rank_math_csv = (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true );
            wp_send_json_success( [
                'generated_now'            => true,
                'queued'                   => false,
                'rank_math_focus_keyword'  => $rank_math_csv,
                'message'                  => __( 'Keywords refreshed.', 'tmwseo' ),
            ] );
        }

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

        if ( $post_type === 'post' && self::is_video_post( $post_id ) && ! $refresh_keywords_only ) {
            // ── Video posts use VideoGeneratePolicy, NOT ContentGenerationGate ──
            // ContentGenerationGate reads _tmwseo_keyword which is the base model name
            // on imported posts; that always triggers model_page_owns_keyword.
            // VideoGeneratePolicy resolves the keyword from the post title first and
            // applies a video-aware ownership policy that allows model-name-in-title.
            $gate = VideoGeneratePolicy::evaluate( $post_id );

            Logs::info( 'admin', '[TMW-VIDEO-GENERATE] VideoGeneratePolicy result', [
                'post_id' => $post_id,
                'allowed' => $gate['allowed'],
                'keyword' => $gate['keyword'] ?? '',
                'reasons' => $gate['reasons'] ?? [],
            ] );

            if ( empty( $gate['allowed'] ) ) {
                $reasons  = ! empty( $gate['reasons'] ) && is_array( $gate['reasons'] )
                    ? array_values( array_map( 'strval', $gate['reasons'] ) )
                    : [];
                $kw_label = isset( $gate['keyword'] ) && $gate['keyword'] !== ''
                    ? ' [keyword: ' . $gate['keyword'] . ']'
                    : '';
                $message  = __( 'Generation blocked by content prerequisites.', 'tmwseo' );
                if ( ! empty( $reasons ) ) {
                    $message .= $kw_label . ' ' . implode( ', ', $reasons );
                }
                wp_send_json_error( [
                    'message' => $message,
                    'reasons' => $reasons,
                    'keyword' => $gate['keyword'] ?? '',
                ], 409 );
            }

            $build = VideoContentBuilder::build( $post_id );
            Rollback::snapshot( $post_id );
            $generated_html = $build['html'] ?? '';
            if ( $generated_html === '' ) {
                wp_send_json_error( [
                    'message' => __( 'Video inline generation unavailable. Please refresh and try again.', 'tmwseo' ),
                ], 500 );
            }

            // ── Humanizer pass (v5.8.14) — mirrors model page pipeline ──
            if ( class_exists( ModelCopyCleanup::class ) ) {
                $generated_html = ModelCopyCleanup::cleanup( $generated_html, (string) get_the_title( $post_id ) );
            }

            $build['html'] = $generated_html;

            $current_content = (string) get_post_field( 'post_content', $post_id );
            $next_content    = self::upsert_managed_ai_block( $current_content, $generated_html );

            $updated = wp_update_post( [
                'ID'           => $post_id,
                'post_content' => $next_content,
            ], true );

            if ( is_wp_error( $updated ) ) {
                Logs::error( 'admin', '[TMW-VIDEO-GENERATE] Inline video generation write failed', [
                    'post_id' => $post_id,
                    'error'   => $updated->get_error_message(),
                ] );
                wp_send_json_error( [
                    'message' => __( 'Video generation failed while saving content.', 'tmwseo' ),
                ], 500 );
            }

            // Write Rank Math SEO fields (guarded — never touches robots/noindex)
            VideoContentBuilder::write_rank_math_fields( $post_id, $build, true );
            $slug_result = self::maybe_update_video_slug( $post_id, (string) ( $build['focus_keyword'] ?? '' ) );
            self::maybe_update_video_featured_image_alt( $post_id, (string) ( $build['focus_keyword'] ?? '' ) );

            clean_post_cache( $post_id );
            Logs::info( 'admin', '[TMW-VIDEO-GENERATE] Inline video AI block generated from sidebar', [
                'post_id'    => $post_id,
                'keyword'    => $gate['keyword'] ?? '',
                'word_count' => $build['word_count'] ?? 0,
                'focus_kw'   => $build['focus_keyword'] ?? '',
            ] );
            wp_send_json_success( [
                'generated_now' => true,
                'reload'        => true,
                'message'       => __( 'Video content generated. Reloading editor.', 'tmwseo' ),
                'slug_updated'  => (bool) ( $slug_result['updated'] ?? false ),
                'slug_error'    => (string) ( $slug_result['error'] ?? '' ),
            ] );
        }

        // ── Category page: inline generation ────────────────────────────────
        // Reuse the same direct ContentEngine path as model Generate so the
        // Gutenberg sidebar gets completion feedback and can reload automatically.
        if ( $post_type === 'tmw_category_page' && ! $refresh_keywords_only ) {
            $run_id = 'cat_' . $post_id . '_' . wp_generate_uuid4();
            update_post_meta( $post_id, '_tmwseo_category_generation_run_id', $run_id );
            update_post_meta( $post_id, '_tmwseo_category_generation_status', 'queued' );
            delete_post_meta( $post_id, '_tmwseo_category_generation_error' );
            delete_post_meta( $post_id, '_tmwseo_category_last_save_result' );

            Logs::info( 'admin', '[TMW-CAT-GEN] queued', [
                'post_id'              => $post_id,
                'run_id'               => $run_id,
                'strategy'             => $strategy,
                'insert_content_block' => (bool) $insert_block,
            ] );

            $before_content = (string) get_post_field( 'post_content', $post_id );
            $before_preview = (string) get_post_meta( $post_id, '_tmwseo_ai_preview_content', true );
            $before_status  = (string) get_post_status( $post_id );

            $job_payload['manual_category_generate']   = 1;
            $job_payload['explicit_category_generate'] = 1;
            $job_payload['run_id']                     = $run_id;

            ob_start();
            try {
                update_post_meta( $post_id, '_tmwseo_category_generation_status', 'running' );
                Logs::info( 'admin', '[TMW-CAT-GEN] running', [
                    'post_id'              => $post_id,
                    'run_id'               => $run_id,
                    'strategy'             => $strategy,
                    'insert_content_block' => (bool) $insert_block,
                ] );

                ContentEngine::run_optimize_job( [
                    'entity_id' => $post_id,
                    'payload'   => $job_payload,
                ] );
            } catch ( \Throwable $e ) {
                $leaked = (string) ob_get_clean();
                update_post_meta( $post_id, '_tmwseo_category_generation_status', 'error' );
                update_post_meta( $post_id, '_tmwseo_category_generation_error', $e->getMessage() );
                if ( $leaked !== '' ) {
                    Logs::warning( 'admin', '[TMW-CAT-GEN] PHP output leaked during category generation', [
                        'post_id' => $post_id,
                        'run_id'  => $run_id,
                        'snippet' => substr( $leaked, 0, 500 ),
                    ] );
                }
                Logs::error( 'admin', '[TMW-CAT-GEN] error', [
                    'post_id'              => $post_id,
                    'run_id'               => $run_id,
                    'strategy'             => $strategy,
                    'insert_content_block' => (bool) $insert_block,
                    'error'                => $e->getMessage(),
                ] );
                wp_send_json_error( [
                    'success' => false,
                    'run_id'  => $run_id,
                    'status'  => 'error',
                    'message' => __( 'Category generation failed. Check logs.', 'tmwseo' ),
                    'error'   => $e->getMessage(),
                ], 500 );
            }

            $leaked_output = (string) ob_get_clean();
            if ( $leaked_output !== '' ) {
                Logs::warning( 'admin', '[TMW-CAT-GEN] PHP output leaked during category generation', [
                    'post_id' => $post_id,
                    'run_id'  => $run_id,
                    'snippet' => substr( $leaked_output, 0, 500 ),
                ] );
            }

            clean_post_cache( $post_id );
            $after_content = (string) get_post_field( 'post_content', $post_id );
            $after_preview = (string) get_post_meta( $post_id, '_tmwseo_ai_preview_content', true );
            $after_done    = (string) get_post_meta( $post_id, '_tmwseo_optimize_done', true );
            $after_status  = (string) get_post_status( $post_id );

            if ( $after_status !== $before_status ) {
                wp_update_post( [ 'ID' => $post_id, 'post_status' => $before_status ] );
            }
            // Read the authoritative transaction result. Do not restore
            // readiness: it is a deliberate post-save transaction outcome.
            $transaction_raw = (string) get_post_meta( $post_id, '_tmwseo_category_transaction_result', true );
            $transaction = $transaction_raw !== '' ? json_decode( $transaction_raw, true ) : [];
            $save_result_raw = (string) get_post_meta( $post_id, '_tmwseo_category_last_save_result', true );
            $save_result     = $save_result_raw !== '' ? json_decode( $save_result_raw, true ) : [];
            $save_written    = is_array( $save_result ) && ! empty( $save_result['content_written'] );
            $save_target     = is_array( $save_result ) ? (string) ( $save_result['target'] ?? '' ) : '';
            $save_word_count = is_array( $save_result ) ? (int) ( $save_result['word_count'] ?? 0 ) : 0;
            $save_source     = is_array( $save_result ) ? (string) ( $save_result['source'] ?? '' ) : '';

            // PR #715: $save_written is true when _tmwseo_category_last_save_result confirms a save
            // attempt (written by both the template path and, after PR #715, the AI path).
            // This covers re-generate cases where post_content remains identical after the job —
            // without $save_written the AJAX handler would falsely report "no content was written".
            $after_content_non_empty = trim( $after_content ) !== '';
            $content_differs         = trim( $after_content ) !== trim( $before_content );
            $content_changed         = $insert_block && $after_content_non_empty && ( $content_differs || $save_written );
            $preview_changed = ! $insert_block && trim( $after_preview ) !== '' && trim( $after_preview ) !== trim( $before_preview );

            if ( $after_done === 'blocked_content_gate' ) {
                update_post_meta( $post_id, '_tmwseo_category_generation_status', 'error' );
                $gate_raw     = (string) get_post_meta( $post_id, ContentGenerationGate::META_GATE_RESULT, true );
                $gate_result  = json_decode( $gate_raw, true );
                $gate_reasons = is_array( $gate_result ) && ! empty( $gate_result['reasons'] ) && is_array( $gate_result['reasons'] )
                    ? array_values( array_map( 'strval', $gate_result['reasons'] ) )
                    : [];
                $message = __( 'Category generation blocked by content prerequisites.', 'tmwseo' );
                if ( ! empty( $gate_reasons ) ) {
                    $message .= ' ' . implode( ', ', $gate_reasons );
                }
                update_post_meta( $post_id, '_tmwseo_category_generation_error', $message );
                Logs::error( 'admin', '[TMW-CAT-GEN] error', [
                    'post_id' => $post_id,
                    'run_id'  => $run_id,
                    'error'   => $message,
                ] );
                wp_send_json_error( [
                    'success' => false,
                    'run_id'  => $run_id,
                    'status'  => 'error',
                    'message' => $message,
                    'reasons' => $gate_reasons,
                ], 409 );
            }

            if ( is_array( $transaction ) && array_key_exists( 'ok', $transaction ) && empty( $transaction['ok'] ) ) {
                $code = (string) ( $transaction['failure_code'] ?? 'category_transaction_failed' );
                $reasons = array_values( array_map( 'strval', (array) ( $transaction['reasons'] ?? [] ) ) );
                $message = 'Category generation blocked: ' . $code;
                if ( ! empty( $reasons ) ) { $message .= '. ' . implode( '; ', $reasons ); }
                $message .= ' [run ' . $run_id . ']';
                update_post_meta( $post_id, '_tmwseo_category_generation_status', 'error' );
                update_post_meta( $post_id, '_tmwseo_category_generation_error', $message );
                wp_send_json_error( [
                    'ok' => false, 'written' => false, 'run_id' => $run_id, 'post_id' => $post_id,
                    'strategy' => $strategy, 'failure_code' => $code, 'reasons' => $reasons,
                    'transaction' => $transaction, 'message' => $message,
                ], 409 );
            }

            if ( ! $content_changed && ! $preview_changed ) {
                update_post_meta( $post_id, '_tmwseo_category_generation_status', 'error' );
                update_post_meta( $post_id, '_tmwseo_category_generation_error', 'empty_generated_content' );
                Logs::error( 'admin', '[TMW-CAT-GEN] error', [
                    'post_id'              => $post_id,
                    'run_id'               => $run_id,
                    'strategy'             => $strategy,
                    'insert_content_block' => (bool) $insert_block,
                    'error'                => 'empty_generated_content',
                ] );
                wp_send_json_error( [
                    'success' => false,
                    'run_id'  => $run_id,
                    'status'  => 'error',
                    'message' => __( 'Category generation blocked: no verified content transaction was recorded.', 'tmwseo' ) . ' [run ' . $run_id . ']',
                ], 500 );
            }

            update_post_meta( $post_id, '_tmwseo_category_generation_status', 'complete' );
            Logs::info( 'admin', '[TMW-CAT-GEN] complete', [
                'post_id'              => $post_id,
                'run_id'               => $run_id,
                'strategy'             => $strategy,
                'insert_content_block' => (bool) $insert_block,
                'provider'             => $strategy === 'template' ? 'template' : $strategy,
            ] );

            $autosave_warning = '';
            if ( $insert_block && function_exists( 'wp_get_post_autosave' ) ) {
                $autosave = wp_get_post_autosave( $post_id );
                if ( $autosave instanceof \WP_Post && strtotime( (string) $autosave->post_modified_gmt ) > strtotime( (string) get_post_field( 'post_modified_gmt', $post_id ) ) ) {
                    $autosave_warning = __( ' Content was saved, but WordPress reports a newer autosave. Reload and review/discard the autosave if the editor still shows old content.', 'tmwseo' );
                }
            }

            wp_send_json_success( [
                'success'         => true,
                'generated_now'   => true,
                'reload'          => true,
                'run_id'          => $run_id,
                'job_id'          => $run_id,
                'status'          => 'complete',
                'content_written' => $insert_block ? (bool) $content_changed : false,
                'target'          => $insert_block ? ( $save_target ?: 'post_content' ) : 'preview_meta',
                'post_id'         => $post_id,
                'word_count'      => $save_word_count,
                'source'          => $save_source,
                'transaction'     => $transaction,
                'autosave_warning' => $autosave_warning !== '',
                'message'         => ( $insert_block
                    ? __( 'Category content generated and written to post content. Reloading editor.', 'tmwseo' )
                    : __( 'Category content generated into the preview buffer. Reloading editor.', 'tmwseo' ) ) . $autosave_warning,
            ] );
        }

        Jobs::enqueue( 'optimize_post', (string) $post_type, $post_id, $job_payload );

        Logs::info( 'admin', '[TMW-QUEUE] optimize_post queued from ajax_generate_now', [
            'post_id'               => $post_id,
            'post_type'             => (string) $post_type,
            'refresh_keywords_only' => $refresh_keywords_only,
        ] );

        // Non-blocking kick — starts the worker within ~1 s without blocking the response.
        //
        // We deliberately do NOT forward $_COOKIE to the loopback URL. The
        // standard wp-cron pattern does forward the cookie jar (including
        // the admin's auth cookie), which means a hostile network observer
        // between this PHP process and admin-ajax.php — misconfigured
        // Docker, hostile shared host, intercepting reverse proxy — can
        // capture the admin session. Instead, the receiver authenticates
        // the kick via a short-lived HMAC token in the URL. Token material
        // is keyed on AUTH_KEY (with wp_salt fallback) and TTL-bounded to
        // 60 s; the kick is idempotent so the 60 s replay window only
        // costs an extra worker tick.
        $kick_args = self::build_kick_token();
        wp_remote_post(
            add_query_arg(
                array_merge( [ 'action' => 'tmwseo_kick_worker' ], $kick_args ),
                admin_url( 'admin-ajax.php' )
            ),
            [
                'timeout'   => 0.01,
                'blocking'  => false,
                'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
            ]
        );

        wp_send_json_success( [
            'queued'  => true,
            'message' => $refresh_keywords_only
                ? __( 'Keyword refresh queued. Refresh in a few seconds.', 'tmwseo' )
                : __( 'Generation queued. Refresh in a few seconds.', 'tmwseo' ),
        ] );
    }

    /**
     * Detect whether a 'post' type post is eligible for the video inline generate path.
     *
     * Uses three signals to avoid silently routing imported posts to the queue when
     * their post_format was never explicitly set to 'video':
     *
     *   1. WordPress post_format = 'video'  (most reliable, use when set)
     *   2. _tmwseo_title_rewritten = '1'    (operator has run VideoTitleRewriter)
     *   3. Has _tmw_model_name or _tmw_linked_model_name (imported via video importer)
     *
     * Mirrors VideoGeneratePolicy::detect_video_signals() — keep in sync.
     */
    private static function is_video_post( int $post_id ): bool {
        // Signal 1: explicit WordPress post format
        if ( has_post_format( 'video', $post_id ) ) {
            return true;
        }
        if ( (string) get_post_format( $post_id ) === 'video' ) {
            return true;
        }

        // Signal 2: title has been rewritten by VideoTitleRewriter
        if ( (string) get_post_meta( $post_id, '_tmwseo_title_rewritten', true ) === '1' ) {
            return true;
        }

        // Signal 3: post was imported via the video importer (has a linked model name)
        $model_name = trim( (string) get_post_meta( $post_id, '_tmw_model_name', true ) );
        if ( $model_name === '' ) {
            $model_name = trim( (string) get_post_meta( $post_id, '_tmw_linked_model_name', true ) );
        }
        return $model_name !== '';
    }

    /**
     * Updates the video slug from the full post TITLE.
     *
     * sanitize_title() already converts em-dashes and special chars to hyphens,
     * so the full title "Lexy Ness Plays With Her Amazing Body — Webcam Video Chat"
     * naturally becomes: lexy-ness-plays-with-her-amazing-body-webcam-video-chat
     *
     * No manual em-dash stripping is needed or wanted — that would drop
     * "Webcam Video Chat" from the URL.
     *
     * @return array{updated:bool,error:string}
     * @since 5.8.14
     */
    private static function maybe_update_video_slug( int $post_id, string $focus_keyword ): array {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return [ 'updated' => false, 'error' => '' ];
        }

        // Use the full raw post title — sanitize_title() handles all normalisation.
        $raw_title   = trim( (string) get_the_title( $post_id ) );
        $target_slug = sanitize_title( $raw_title );

        if ( $target_slug === '' ) {
            return [ 'updated' => false, 'error' => '' ];
        }

        $current_slug = (string) $post->post_name;
        if ( $current_slug === $target_slug ) {
            return [ 'updated' => false, 'error' => '' ];
        }

        // Backup previous slug (once).
        if ( (string) get_post_meta( $post_id, '_tmwseo_prev_video_slug', true ) === '' && $current_slug !== '' ) {
            update_post_meta( $post_id, '_tmwseo_prev_video_slug', $current_slug );
            update_post_meta( $post_id, '_tmwseo_prev_video_slug_at', current_time( 'mysql' ) );
        }

        $unique_slug = wp_unique_post_slug( $target_slug, $post_id, $post->post_status, $post->post_type, (int) $post->post_parent );
        $result      = wp_update_post( [ 'ID' => $post_id, 'post_name' => $unique_slug ], true );
        if ( is_wp_error( $result ) ) {
            $error = $result->get_error_message();
            Logs::error( 'admin', '[TMW-VIDEO-SLUG] update failed', [ 'post_id' => $post_id, 'target' => $unique_slug, 'error' => $error ] );
            return [ 'updated' => false, 'error' => $error ];
        }
        if ( ! $result ) {
            Logs::error( 'admin', '[TMW-VIDEO-SLUG] update failed', [ 'post_id' => $post_id, 'target' => $unique_slug, 'error' => 'unknown_failure' ] );
            return [ 'updated' => false, 'error' => 'unknown_failure' ];
        }
        Logs::info( 'admin', '[TMW-VIDEO-SLUG] updated to full title-derived slug', [ 'post_id' => $post_id, 'from' => $current_slug, 'to' => $unique_slug ] );
        return [ 'updated' => true, 'error' => '' ];
    }

    /**
     * Updates featured image alt, title, caption, and description safely.
     *
     * Safety rules (v5.8.14 / audit-corrected):
     *   - Alt:   always written; backed up once before first TMW overwrite.
     *   - Title / Caption / Description on the attachment post:
     *       · Fill when the field is currently empty.
     *       · Overwrite when marked TMW-managed (_tmwseo_img_managed = '1').
     *       · Never touch a non-empty, non-TMW-managed value (shared attachments safe).
     *   - After any write, mark the attachment as TMW-managed.
     *
     * Field values:
     *   Alt:         {focus_keyword} webcam clip
     *   Title:       {post title} webcam video clip
     *   Caption:     Watch {focus_keyword} on Top-Models.Webcam
     *   Description: {focus_keyword} webcam video clip from Top-Models.Webcam.
     *
     * @since 5.8.14
     */
    private static function maybe_update_video_featured_image_alt( int $post_id, string $focus_keyword ): void {
        $thumb_id = (int) get_post_thumbnail_id( $post_id );
        if ( $thumb_id <= 0 || trim( $focus_keyword ) === '' ) {
            return;
        }

        $post_title  = trim( (string) get_the_title( $post_id ) );
        $new_alt     = trim( $focus_keyword . ' webcam clip' );
        $new_title   = ( $post_title !== '' ? $post_title : $focus_keyword ) . ' webcam video clip';
        $new_caption = 'Watch ' . $focus_keyword . ' on Top-Models.Webcam';
        $new_desc    = $focus_keyword . ' webcam video clip from Top-Models.Webcam.';

        // ── Alt text ──────────────────────────────────────────────────────────
        $old_alt = trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) );
        // Backup once before first TMW overwrite.
        if ( (string) get_post_meta( $post_id, '_tmwseo_prev_video_image_alt', true ) === '' && $old_alt !== '' ) {
            update_post_meta( $post_id, '_tmwseo_prev_video_image_alt', $old_alt );
            update_post_meta( $post_id, '_tmwseo_prev_video_image_alt_at', current_time( 'mysql' ) );
        }
        update_post_meta( $thumb_id, '_wp_attachment_image_alt', $new_alt );

        // ── Attachment post fields (title / caption / description) ────────────
        $attachment   = get_post( $thumb_id );
        $is_tmw_managed = $attachment instanceof \WP_Post
            && (string) get_post_meta( $thumb_id, '_tmwseo_img_managed', true ) === '1';

        if ( $attachment instanceof \WP_Post ) {
            $update_args = [ 'ID' => $thumb_id ];

            if ( trim( $attachment->post_title )   === '' || $is_tmw_managed ) {
                $update_args['post_title']   = $new_title;
            }
            if ( trim( $attachment->post_excerpt ) === '' || $is_tmw_managed ) {
                $update_args['post_excerpt'] = $new_caption;
            }
            if ( trim( $attachment->post_content ) === '' || $is_tmw_managed ) {
                $update_args['post_content'] = $new_desc;
            }

            if ( count( $update_args ) > 1 ) {
                wp_update_post( $update_args );
            }

            // Mark as TMW-managed so future Generate calls can safely overwrite.
            update_post_meta( $thumb_id, '_tmwseo_img_managed', '1' );
            update_post_meta( $thumb_id, '_tmwseo_img_managed_at', current_time( 'mysql' ) );
        }

        Logs::info( 'admin', '[TMW-VIDEO-IMAGE-META] Image meta updated', [
            'post_id'     => $post_id,
            'thumb_id'    => $thumb_id,
            'alt'         => $new_alt,
            'was_managed' => $is_tmw_managed,
        ] );
    }

    /**
     * NOTE: build_inline_video_content_html() was removed in v5.8.13.
     * Video content is now produced by VideoContentBuilder::build().
     * The 85-word placeholder it generated has been replaced with a full
     * 600-800 word SEO-ready block including H2/H3 headings and Rank Math fields.
     */

    private static function upsert_managed_ai_block( string $content, string $html ): string {
        $html = trim( $html );
        if ( $html === '' ) {
            return $content;
        }

        $bounded_block = self::AI_MARKER_START . "\n" . $html . "\n" . self::AI_MARKER_END;

        $bounded_pattern = '/' . preg_quote( self::AI_MARKER_START, '/' ) . '.*?' . preg_quote( self::AI_MARKER_END, '/' ) . '/s';
        if ( preg_match( $bounded_pattern, $content ) ) {
            return (string) preg_replace( $bounded_pattern, $bounded_block, $content, 1 );
        }

        if ( strpos( $content, self::AI_MARKER ) !== false ) {
            $parts  = explode( self::AI_MARKER, $content, 2 );
            $before = rtrim( (string) $parts[0] );
            return $before . "\n" . $bounded_block . "\n";
        }

        $content = rtrim( $content );
        if ( $content !== '' ) {
            $content .= "\n\n";
        }

        return $content . $bounded_block . "\n";
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
        // Two acceptable authentication paths:
        //   1. HMAC kick token in the URL (the internal-kick path — used
        //      by ajax_generate_now above to avoid forwarding the admin's
        //      cookie jar to the loopback URL).
        //   2. Standard user-capability check (covers any operator who
        //      hits this URL directly from their browser, plus
        //      back-compat for anything that still posts with cookies).
        //
        // Either passes. Both failing = denied + logged to the security
        // context (so the kick endpoint is observable in forensics if it
        // ever gets probed).
        if ( ! self::verify_kick_token() && ! current_user_can( 'edit_posts' ) ) {
            Logs::warn( 'security', 'Kick worker denied', [
                'has_token'   => isset( $_GET['kick_token'] ),
                'token_age_s' => isset( $_GET['kick_ts'] ) ? ( time() - (int) $_GET['kick_ts'] ) : null,
                'logged_in'   => is_user_logged_in(),
                'ip'          => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '',
            ] );
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'tmwseo' ) ], 403 );
        }

        \TMWSEO\Engine\Worker::run();
        wp_send_json_success( [ 'ran' => true ] );
    }

    /**
     * Build the URL-arg triple (ts, nonce, token) for an internal kick.
     * HMAC-SHA256 keyed on AUTH_KEY so the receiver can verify without
     * needing the admin's session cookie. Timestamp + nonce give the
     * receiver a TTL window and a non-replayable token shape.
     */
    private static function build_kick_token(): array {
        $ts    = time();
        $nonce = bin2hex( random_bytes( 8 ) );
        $key   = self::kick_signing_key();
        return [
            'kick_ts'    => $ts,
            'kick_nonce' => $nonce,
            'kick_token' => hash_hmac( 'sha256', $ts . '|' . $nonce . '|kick', $key ),
        ];
    }

    /**
     * Verify the HMAC kick token. Returns true if the URL carries a
     * well-formed, fresh, signature-matching token; false otherwise.
     *
     * Constant-time comparison (hash_equals) so brute-forcing the
     * signature is not made cheaper by short-circuit string compare.
     * 60-second TTL bounds the replay window — the kick is idempotent so
     * a single replay only costs one extra worker tick.
     */
    private static function verify_kick_token(): bool {
        if ( empty( $_GET['kick_ts'] ) || empty( $_GET['kick_nonce'] ) || empty( $_GET['kick_token'] ) ) {
            return false;
        }
        $ts    = (int) $_GET['kick_ts'];
        $nonce = (string) $_GET['kick_nonce'];
        $token = (string) $_GET['kick_token'];

        // Reject ancient or future-dated timestamps — both indicate either
        // a replay attack or a clock-skewed client. 60 s tolerates normal
        // loopback latency; tighter windows might trip on slow PHP-FPM.
        $age = time() - $ts;
        if ( $ts <= 0 || $age < -10 || $age > 60 ) {
            return false;
        }

        // Nonce shape sanity — random_bytes(8) → bin2hex → 16 hex chars.
        // Defends against an attacker injecting a noise-suppressing
        // pathological value into the HMAC input.
        if ( ! preg_match( '/^[0-9a-f]{16}$/', $nonce ) ) {
            return false;
        }

        $expected = hash_hmac( 'sha256', $ts . '|' . $nonce . '|kick', self::kick_signing_key() );
        return hash_equals( $expected, $token );
    }

    /**
     * Key material for the kick-token HMAC. Prefers AUTH_KEY (the
     * canonical install secret); falls back to wp_salt('auth') so a
     * misconfigured wp-config without AUTH_KEY doesn't disable the kick
     * entirely. If neither is available, the empty-string key would
     * make every token valid for the same input — so we refuse to
     * issue/verify tokens in that pathological state by returning a
     * sentinel that won't match any legitimate signed input.
     */
    private static function kick_signing_key(): string {
        if ( defined( 'AUTH_KEY' ) && AUTH_KEY !== '' ) {
            return (string) AUTH_KEY;
        }
        if ( function_exists( 'wp_salt' ) ) {
            $salt = (string) wp_salt( 'auth' );
            if ( $salt !== '' ) {
                return $salt;
            }
        }
        // Pathological fallback — never matches a legitimate token.
        return 'tmwseo_kick_signing_key_unavailable_' . random_bytes( 16 );
    }

    /**
     * wp_ajax_tmwseo_save_model_fallback_pack
     *
     * Saves reviewed fallback phrases as queued_for_review keyword candidates only.
     * This action never writes content, Rank Math fields, indexing state, or posts.
     */
    public static function ajax_save_model_fallback_pack(): void {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['nonce'])) : '';
        if ('' === $nonce || !wp_verify_nonce($nonce, 'tmwseo_save_model_fallback_pack')) {
            wp_send_json_error([ 'message' => __('Invalid or expired nonce.', 'tmwseo') ], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error([ 'message' => __('Permission denied.', 'tmwseo') ], 403);
        }

        $entity_id = isset($_POST['entity_id']) ? absint($_POST['entity_id']) : 0;
        if ($entity_id <= 0) {
            wp_send_json_error([ 'message' => __('Invalid model entity.', 'tmwseo') ], 400);
        }
        $entity_post = get_post($entity_id);
        if (!$entity_post instanceof \WP_Post || 'model' !== $entity_post->post_type) {
            wp_send_json_error([ 'message' => __('Invalid model entity.', 'tmwseo') ], 400);
        }

        $model_name = isset($_POST['model_name']) ? ModelKeywordPoolClassifier::normalize_phrase(wp_unslash((string) $_POST['model_name'])) : '';
        if ('' === $model_name) {
            wp_send_json_error([ 'message' => __('Model name is required.', 'tmwseo') ], 400);
        }

        $keywords_raw = $_POST['keywords'] ?? null;
        if (!is_array($keywords_raw)) {
            wp_send_json_error([ 'message' => __('Keywords must be an array.', 'tmwseo') ], 400);
        }

        $classifier = new ModelKeywordPoolClassifier();
        $builder = new ModelFallbackKeywordPackBuilder($classifier);
        $preview = $builder->build_preview($entity_id, $model_name, [], []);
        $allowed_generated = array_flip(array_values(array_map('strval', is_array($preview['fallback_generated_patterns'] ?? null) ? $preview['fallback_generated_patterns'] : [])));
        $repository = new KeywordPoolCandidateRepository();
        $counts = [
            'inserted' => 0,
            'conflicts' => 0,
            'skipped_empty' => 0,
            'skipped_unsafe' => 0,
            'skipped_not_generated' => 0,
            'errors' => 0,
        ];

        foreach ($keywords_raw as $raw_keyword) {
            if (!is_scalar($raw_keyword)) {
                $counts['skipped_empty']++;
                continue;
            }
            $phrase = ModelKeywordPoolClassifier::normalize_phrase(wp_unslash((string) $raw_keyword));
            if ('' === $phrase) {
                $counts['skipped_empty']++;
                continue;
            }

            $base_classification = $classifier->classify($phrase);
            if (ModelKeywordPoolClassifier::CLASS_UNSAFE_STANDALONE === $base_classification['keyword_class']) {
                $counts['skipped_unsafe']++;
                continue;
            }

            if (!isset($allowed_generated[$phrase])) {
                $counts['skipped_not_generated']++;
                continue;
            }

            $generated_classification = $classifier->classify($phrase, [ 'is_generated' => true ]);
            if (ModelKeywordPoolClassifier::CLASS_GENERATED_LONGTAIL !== $generated_classification['keyword_class']) {
                $counts['skipped_not_generated']++;
                continue;
            }

            if (null !== $repository->find_existing_by_canonical_and_entity($phrase, $entity_id)) {
                $counts['conflicts']++;
                continue;
            }

            $result = $repository->save([
                'keyword' => $phrase,
                'canonical' => $phrase,
                'intent_type' => 'model',
                'entity_type' => 'model',
                'entity_id' => $entity_id,
                'status' => 'queued_for_review',
                'provenance' => [
                    'source' => 'generated_model_fallback',
                    'model_keyword_owner' => $model_name,
                    'model_keyword_usage_scope' => 'model_bio_only',
                    'model_keyword_primary_candidate' => 'no',
                    'keyword_class' => ModelKeywordPoolClassifier::CLASS_GENERATED_LONGTAIL,
                    'suggested_usage' => ModelKeywordPoolClassifier::USAGE_REVIEW_REQUIRED,
                    'standalone_allowed' => false,
                    'keyword_class_reason_codes' => array_values(array_map('strval', $generated_classification['reason_codes'] ?? [])),
                    'keyword_class_confidence' => (string) ($generated_classification['confidence'] ?? 'high'),
                    'generated_by' => 'pr602_model_fallback_keyword_pack_builder',
                ],
            ]);

            $action = (string) ($result['action'] ?? '');
            if ('inserted' === $action) {
                $counts['inserted']++;
            } elseif ('conflict' === $action || 'updated' === $action) {
                $counts['conflicts']++;
            } else {
                $counts['errors']++;
            }
        }

        wp_send_json_success($counts);
    }


    public static function ajax_kw_classification_dry_run(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error([ 'message' => __('Permission denied.', 'tmwseo') ], 403);
        }
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['nonce'])) : '';
        if ('' === $nonce || !wp_verify_nonce($nonce, 'tmwseo_kw_classification_audit')) {
            wp_send_json_error([ 'message' => __('Invalid or expired nonce.', 'tmwseo') ], 403);
        }

        $allowed_filters = [ 'missing', 'all', 'unlinked', 'unsafe', 'unknown' ];
        $offset = isset($_POST['offset']) ? max(0, absint($_POST['offset'])) : 0;
        $batch_size = isset($_POST['batch_size']) ? min(250, max(1, absint($_POST['batch_size']))) : 50;
        $filter = isset($_POST['filter']) ? sanitize_key((string) wp_unslash($_POST['filter'])) : 'missing';
        if (!in_array($filter, $allowed_filters, true)) {
            $filter = 'missing';
        }

        $service = new KeywordPoolClassificationApplyService(new KeywordPoolCandidateRepository(), new ModelKeywordPoolClassifier());
        wp_send_json_success($service->dry_run_batch($offset, $batch_size, $filter));
    }

    public static function ajax_kw_classification_apply_batch(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error([ 'message' => __('Permission denied.', 'tmwseo') ], 403);
        }
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['nonce'])) : '';
        if ('' === $nonce || !wp_verify_nonce($nonce, 'tmwseo_kw_classification_audit')) {
            wp_send_json_error([ 'message' => __('Invalid or expired nonce.', 'tmwseo') ], 403);
        }

        $batch_size = isset($_POST['batch_size']) ? min(250, max(1, absint($_POST['batch_size']))) : 100;
        $service = new KeywordPoolClassificationApplyService(new KeywordPoolCandidateRepository(), new ModelKeywordPoolClassifier());
        $auto_fetch_missing = isset($_POST['auto_fetch_missing']) && (string) wp_unslash($_POST['auto_fetch_missing']) === '1';

        if ($auto_fetch_missing) {
            $candidate_ids = $service->fetch_missing_ids($batch_size);
        } else {
            $raw_ids = $_POST['candidate_ids'] ?? [];
            if (!is_array($raw_ids)) {
                wp_send_json_error([ 'message' => __('candidate_ids[] is required.', 'tmwseo') ], 400);
            }
            $candidate_ids = array_values(array_unique(array_filter(array_map('absint', wp_unslash($raw_ids)))));
            if ([] === $candidate_ids) {
                wp_send_json_error([ 'message' => __('candidate_ids[] is required.', 'tmwseo') ], 400);
            }
        }

        if (count($candidate_ids) > 250) {
            wp_send_json_error([ 'message' => __('Batch size exceeds the hard cap of 250 candidate IDs.', 'tmwseo') ], 400);
        }

        wp_send_json_success($service->apply_batch($candidate_ids, 'pr603_keyword_pool_classification_audit'));
    }

}
