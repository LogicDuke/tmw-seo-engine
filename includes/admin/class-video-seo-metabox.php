<?php
/**
 * VideoSeoMetabox — admin metabox integrating the video SEO chain.
 *
 * Provides a single admin interface for:
 * - Video title rewriting (generate candidates, review, apply)
 * - Keyword pack preview
 * - Cannibalization warnings
 * - Readiness evaluation display
 * - Content preview trigger
 *
 * Patch 2: wires VideoTitleRewriter, CannibalizationDetector, IndexReadinessGate,
 * VideoContentArchitecture, and AuditTrail into a real admin workflow.
 *
 * @package TMWSEO\Engine\Admin
 * @since   4.4.1
 */
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Content\VideoTitleRewriter;
use TMWSEO\Engine\Content\VideoContentArchitecture;
use TMWSEO\Engine\Content\IndexReadinessGate;
use TMWSEO\Engine\Content\AuditTrail;
use TMWSEO\Engine\Content\RankMathMapper;
use TMWSEO\Engine\Keywords\CannibalizationDetector;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class VideoSeoMetabox {

    public static function init(): void {
        if ( ! is_admin() ) return;

        add_action( 'add_meta_boxes', [ __CLASS__, 'register_metabox' ] );
        add_action( 'admin_post_tmwseo_video_generate_titles', [ __CLASS__, 'handle_generate_titles' ] );
        add_action( 'admin_post_tmwseo_video_apply_title', [ __CLASS__, 'handle_apply_title' ] );
        add_action( 'admin_post_tmwseo_video_generate_preview', [ __CLASS__, 'handle_generate_preview' ] );
        add_action( 'admin_post_tmwseo_video_apply_preview', [ __CLASS__, 'handle_apply_preview' ] );
    }

    public static function register_metabox(): void {
        add_meta_box(
            'tmwseo_video_seo',
            __( 'TMW Video SEO (Patch 2)', 'tmwseo' ),
            [ __CLASS__, 'render_metabox' ],
            'post',
            'normal',
            'high'
        );
    }

    public static function render_metabox( \WP_Post $post ): void {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            echo '<p>Insufficient permissions.</p>';
            return;
        }

        $post_id = (int) $post->ID;

        // ── Title rewriting section ──────────────────────────────────────
        echo '<h3>Title Rewriting</h3>';
        $is_rewritten = ! VideoTitleRewriter::is_original_title( $post_id );
        $original     = (string) get_post_meta( $post_id, VideoTitleRewriter::META_ORIGINAL, true );
        $candidates   = VideoTitleRewriter::get_candidates( $post_id );

        echo '<p><strong>Status:</strong> ' . ( $is_rewritten
            ? '<span style="color:green">Rewritten</span>'
            : '<span style="color:orange">Original import title</span>' ) . '</p>';

        if ( $original !== '' ) {
            echo '<p><strong>Original title:</strong> <code>' . esc_html( $original ) . '</code></p>';
        }

        if ( ! empty( $candidates ) ) {
            echo '<table class="widefat striped" style="margin-bottom:10px"><thead><tr>';
            echo '<th>Candidate</th><th>Score</th><th>Source</th><th>Action</th></tr></thead><tbody>';

            foreach ( $candidates as $c ) {
                $selected = ! empty( $c['is_selected'] ) ? ' <strong>(applied)</strong>' : '';
                $apply_url = wp_nonce_url(
                    admin_url( 'admin-post.php?action=tmwseo_video_apply_title&post_id=' . $post_id . '&candidate_id=' . (int) $c['id'] ),
                    'tmwseo_video_apply_title_' . $post_id
                );
                echo '<tr><td>' . esc_html( $c['candidate_title'] ) . $selected . '</td>';
                echo '<td>' . esc_html( round( (float) $c['score'], 1 ) ) . '</td>';
                echo '<td>' . esc_html( $c['source'] ?? '' ) . '</td>';
                echo '<td><a class="button button-small" href="' . esc_url( $apply_url ) . '">Apply</a></td></tr>';
            }

            echo '</tbody></table>';
        }

        $gen_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=tmwseo_video_generate_titles&post_id=' . $post_id ),
            'tmwseo_video_generate_titles_' . $post_id
        );
        echo '<p><a class="button" href="' . esc_url( $gen_url ) . '">Generate Title Candidates</a></p>';

        // ── Keyword pack & content preview ───────────────────────────────
        echo '<hr><h3>Keyword Pack & Content Preview</h3>';
        $pack_json = (string) get_post_meta( $post_id, '_tmwseo_keyword_pack_json', true );
        if ( $pack_json !== '' ) {
            $pack = json_decode( $pack_json, true );
            if ( is_array( $pack ) ) {
                echo '<p><strong>Primary:</strong> ' . esc_html( $pack['primary'] ?? '' ) . '</p>';
                echo '<p><strong>Secondary:</strong> ' . esc_html( implode( ', ', $pack['secondary'] ?? $pack['additional'] ?? [] ) ) . '</p>';
                if ( ! empty( $pack['longtail'] ) ) {
                    echo '<p><strong>Long-tail:</strong> ' . esc_html( implode( '; ', $pack['longtail'] ) ) . '</p>';
                }
                // Show Rank Math preview.
                if ( class_exists( '\\TMWSEO\\Engine\\Content\\RankMathMapper' ) ) {
                    echo '<p><strong>Rank Math output:</strong> <code>' . esc_html( RankMathMapper::preview_rank_math_csv( $post_id, $pack ) ) . '</code></p>';
                }
            }
        }

        $preview_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=tmwseo_video_generate_preview&post_id=' . $post_id ),
            'tmwseo_video_generate_preview_' . $post_id
        );
        echo '<p><a class="button" href="' . esc_url( $preview_url ) . '">Generate Content Preview</a></p>';

        $apply_preview_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=tmwseo_video_apply_preview&post_id=' . $post_id ),
            'tmwseo_video_apply_preview_' . $post_id
        );
        $has_preview = (string) get_post_meta( $post_id, '_tmwseo_video_preview_html', true ) !== '';
        if ( $has_preview ) {
            echo '<p><a class="button button-primary" href="' . esc_url( $apply_preview_url ) . '">Apply Content Preview</a></p>';
        }

        // ── Cannibalization warnings ─────────────────────────────────────
        echo '<hr><h3>Cannibalization Check</h3>';
        if ( class_exists( '\\TMWSEO\\Engine\\Keywords\\CannibalizationDetector' ) ) {
            $conflicts = CannibalizationDetector::check_post( $post_id );
            if ( empty( $conflicts ) ) {
                echo '<p style="color:green">No keyword conflicts detected.</p>';
            } else {
                echo '<div class="notice notice-warning inline"><p><strong>' . count( $conflicts ) . ' conflict(s) detected:</strong></p><ul>';
                foreach ( $conflicts as $c ) {
                    echo '<li><strong>' . esc_html( $c['keyword'] ) . '</strong> — conflicts with '
                        . esc_html( $c['conflicting_post_type'] ) . ' #' . (int) $c['conflicting_post_id']
                        . ' (' . esc_html( $c['severity'] ) . ')</li>';
                }
                echo '</ul></div>';

                // Persist cannibalization data via AuditTrail.
                if ( class_exists( '\\TMWSEO\\Engine\\Content\\AuditTrail' ) ) {
                    AuditTrail::persist_cannibalization( $post_id, $conflicts );
                }
            }
        }

        // ── Readiness evaluation ─────────────────────────────────────────
        echo '<hr><h3>Index Readiness</h3>';
        if ( class_exists( '\\TMWSEO\\Engine\\Content\\IndexReadinessGate' ) ) {
            $readiness = IndexReadinessGate::evaluate_post( $post_id );
            if ( $readiness['ready'] ) {
                echo '<p style="color:green"><strong>READY</strong> — all gates pass.</p>';
            } else {
                echo '<div class="notice notice-error inline"><p><strong>NOT READY</strong></p><ul>';
                foreach ( $readiness['reasons'] as $r ) {
                    echo '<li>' . esc_html( $r ) . '</li>';
                }
                echo '</ul></div>';
            }
        }

        // ── Notices from prior actions ────────────────────────────────────
        if ( isset( $_GET['tmwseo_video_notice'] ) ) {
            $notice = sanitize_text_field( (string) $_GET['tmwseo_video_notice'] );
            echo '<div class="notice notice-success inline"><p>' . esc_html( $notice ) . '</p></div>';
        }
    }

    // ── Handlers ──────────────────────────────────────────────────────────

    public static function handle_generate_titles(): void {
        $post_id = (int) ( $_GET['post_id'] ?? 0 );
        if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'tmwseo_video_generate_titles_' . $post_id );

        $result = VideoTitleRewriter::generate_candidates( $post_id );

        // Also generate/store keyword pack for this video.
        $post = get_post( $post_id );
        if ( $post instanceof \WP_Post ) {
            $pack = VideoContentArchitecture::build_keyword_pack( $post );
            AuditTrail::persist_keyword_pack( $post_id, $pack );
            RankMathMapper::sync_to_rank_math( $post_id, $pack );
        }

        $count = count( $result['candidates'] ?? [] );
        wp_safe_redirect( get_edit_post_link( $post_id, 'url' ) . '&tmwseo_video_notice=' . rawurlencode( $count . ' title candidates generated' ) . '#tmwseo_video_seo' );
        exit;
    }

    public static function handle_apply_title(): void {
        $post_id      = (int) ( $_GET['post_id'] ?? 0 );
        $candidate_id = (int) ( $_GET['candidate_id'] ?? 0 );
        if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'tmwseo_video_apply_title_' . $post_id );

        $ok = VideoTitleRewriter::apply_title( $post_id, $candidate_id );

        // Re-evaluate readiness after title change.
        IndexReadinessGate::evaluate_post( $post_id );

        $msg = $ok ? 'Title applied successfully' : 'Title apply failed';
        wp_safe_redirect( get_edit_post_link( $post_id, 'url' ) . '&tmwseo_video_notice=' . rawurlencode( $msg ) . '#tmwseo_video_seo' );
        exit;
    }

    public static function handle_generate_preview(): void {
        $post_id = (int) ( $_GET['post_id'] ?? 0 );
        if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'tmwseo_video_generate_preview_' . $post_id );

        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            wp_die( 'Post not found' );
        }

        // Full video chain: keyword pack → content preview → quality → uniqueness → readiness → audit.
        $pack    = VideoContentArchitecture::build_keyword_pack( $post );
        $preview = VideoContentArchitecture::build_preview( $post );

        // Persist keyword pack + Rank Math sync.
        AuditTrail::persist_keyword_pack( $post_id, $pack );
        RankMathMapper::sync_to_rank_math( $post_id, $pack );

        // Store preview for later apply.
        update_post_meta( $post_id, '_tmwseo_video_preview_html', $preview['content_html'] ?? '' );
        update_post_meta( $post_id, '_tmwseo_video_preview_seo_title', $preview['seo_title'] ?? '' );
        update_post_meta( $post_id, '_tmwseo_video_preview_meta_desc', $preview['meta_description'] ?? '' );
        update_post_meta( $post_id, '_tmwseo_video_preview_outline', $preview['outline'] ?? '' );

        // Quality scoring with correct page_type.
        $quality_context = [
            'primary_keyword'    => $pack['primary'] ?? '',
            'secondary_keywords' => $pack['secondary'] ?? [],
            'entities'           => array_values( array_unique( array_filter( array_merge(
                [ $pack['primary'] ?? '' ],
                $pack['secondary'] ?? [],
                $pack['longtail'] ?? []
            ) ) ) ),
            'page_type' => 'video',
            'post_type' => 'post',
            'post_id'   => $post_id,
        ];

        $quality = \TMWSEO\Engine\Content\QualityScoreEngine::evaluate(
            (string) ( $preview['content_html'] ?? '' ),
            $quality_context
        );

        AuditTrail::persist_quality( $post_id, $quality, (float) ( $quality['breakdown']['uniqueness'] ?? 0 ) );
        AuditTrail::persist_fingerprint( $post_id, (string) ( $preview['content_html'] ?? '' ), 'post' );

        // Cannibalization check.
        $conflicts = CannibalizationDetector::check_post( $post_id );
        AuditTrail::persist_cannibalization( $post_id, $conflicts );

        // Readiness evaluation.
        IndexReadinessGate::evaluate_post( $post_id );

        wp_safe_redirect( get_edit_post_link( $post_id, 'url' ) . '&tmwseo_video_notice=' . rawurlencode( 'Content preview generated (quality: ' . ( $quality['score'] ?? 0 ) . ')' ) . '#tmwseo_video_seo' );
        exit;
    }

    public static function handle_apply_preview(): void {
        $post_id = (int) ( $_GET['post_id'] ?? 0 );
        if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'tmwseo_video_apply_preview_' . $post_id );

        $html      = (string) get_post_meta( $post_id, '_tmwseo_video_preview_html', true );
        $seo_title = (string) get_post_meta( $post_id, '_tmwseo_video_preview_seo_title', true );
        $meta_desc = (string) get_post_meta( $post_id, '_tmwseo_video_preview_meta_desc', true );

        if ( $html === '' ) {
            wp_safe_redirect( get_edit_post_link( $post_id, 'url' ) . '&tmwseo_video_notice=' . rawurlencode( 'No preview available to apply' ) . '#tmwseo_video_seo' );
            exit;
        }

        // Apply content (append via AI block marker, preserving original content).
        $post = get_post( $post_id );
        if ( $post instanceof \WP_Post ) {
            $marker  = '<!-- TMWSEO:AI -->';
            $current = (string) $post->post_content;
            if ( strpos( $current, $marker ) !== false ) {
                $parts       = explode( $marker, $current, 2 );
                $new_content = rtrim( $parts[0] ) . "\n" . $marker . "\n" . $html . "\n";
            } else {
                $new_content = rtrim( $current ) . "\n\n" . $marker . "\n" . $html . "\n";
            }

            wp_update_post( [
                'ID'           => $post_id,
                'post_content' => $new_content,
            ] );
        }

        // Apply SEO meta via Rank Math mapper.
        if ( $seo_title !== '' ) {
            update_post_meta( $post_id, 'rank_math_title', $seo_title );
        }
        if ( $meta_desc !== '' ) {
            update_post_meta( $post_id, 'rank_math_description', $meta_desc );
        }

        // Set approval status.
        AuditTrail::set_approval_status( $post_id, 'approved' );

        // Re-evaluate readiness.
        IndexReadinessGate::evaluate_post( $post_id );

        Logs::info( 'video_seo', '[TMW-VIDEO-SEO] Preview applied', [ 'post_id' => $post_id ] );

        wp_safe_redirect( get_edit_post_link( $post_id, 'url' ) . '&tmwseo_video_notice=' . rawurlencode( 'Content preview applied' ) . '#tmwseo_video_seo' );
        exit;
    }
}
