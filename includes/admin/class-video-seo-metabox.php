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
            echo '<p style="color:#6b7280;font-size:13px">Insufficient permissions.</p>';
            return;
        }

        $post_id = (int) $post->ID;

        // Shared style tokens — presentation only, no logic.
        $s_panel        = 'margin:0 0 10px;padding:10px 12px;border:1px solid #e5e7eb;border-radius:3px;background:#fff';
        $s_section_head = 'margin:0 0 7px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#9ca3af';
        $s_badge_green  = 'display:inline-block;padding:1px 7px;border-radius:10px;font-size:11px;background:#d1fae5;color:#065f46';
        $s_badge_amber  = 'display:inline-block;padding:1px 7px;border-radius:10px;font-size:11px;background:#fef3c7;color:#92400e';
        $s_badge_red    = 'display:inline-block;padding:1px 7px;border-radius:10px;font-size:11px;background:#fee2e2;color:#991b1b';

        // -- Prior-action notice (top, slim banner) ---------------------------
        if ( isset( $_GET['tmwseo_video_notice'] ) ) {
            $notice = sanitize_text_field( (string) $_GET['tmwseo_video_notice'] );
            echo '<div style="margin:0 0 12px;padding:7px 10px;border-left:3px solid #10b981;'
                . 'background:#ecfdf5;border-radius:2px;font-size:12px;color:#065f46">'
                . esc_html( $notice ) . '</div>';
        }

        // =====================================================================
        // Section 1: Title Rewriting
        // =====================================================================
        $is_rewritten = ! VideoTitleRewriter::is_original_title( $post_id );
        $original     = (string) get_post_meta( $post_id, VideoTitleRewriter::META_ORIGINAL, true );
        $candidates   = VideoTitleRewriter::get_candidates( $post_id );

        echo '<div style="' . $s_panel . '">';
        echo '<p style="' . $s_section_head . '">Title Rewriting</p>';

        if ( $is_rewritten ) {
            echo '<p style="margin:0 0 6px"><span style="' . $s_badge_green . '">Rewritten</span></p>';
        } else {
            echo '<p style="margin:0 0 6px"><span style="' . $s_badge_amber . '">Original import title</span></p>';
        }

        if ( $original !== '' ) {
            echo '<p style="margin:0 0 6px;font-size:12px;color:#6b7280">Original: '
                . '<code style="font-size:11px">' . esc_html( $original ) . '</code></p>';
        }

        if ( ! empty( $candidates ) ) {
            echo '<table class="widefat striped" style="margin:6px 0 8px;font-size:12px"><thead><tr>';
            echo '<th>Candidate</th>'
                . '<th style="width:50px">Score</th>'
                . '<th style="width:70px">Source</th>'
                . '<th style="width:50px"></th>';
            echo '</tr></thead><tbody>';

            foreach ( $candidates as $c ) {
                $applied_badge = ! empty( $c['is_selected'] )
                    ? ' <span style="' . $s_badge_green . '">applied</span>'
                    : '';
                $apply_url = wp_nonce_url(
                    admin_url( 'admin-post.php?action=tmwseo_video_apply_title&post_id=' . $post_id . '&candidate_id=' . (int) $c['id'] ),
                    'tmwseo_video_apply_title_' . $post_id
                );
                echo '<tr>'
                    . '<td>' . esc_html( $c['candidate_title'] ) . $applied_badge . '</td>'
                    . '<td>' . esc_html( round( (float) $c['score'], 1 ) ) . '</td>'
                    . '<td>' . esc_html( $c['source'] ?? '' ) . '</td>'
                    . '<td><a class="button button-small" href="' . esc_url( $apply_url ) . '">Apply</a></td>'
                    . '</tr>';
            }

            echo '</tbody></table>';
        }

        $gen_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=tmwseo_video_generate_titles&post_id=' . $post_id ),
            'tmwseo_video_generate_titles_' . $post_id
        );
        echo '<a class="button" href="' . esc_url( $gen_url ) . '">Generate Title Candidates</a>';
        echo '</div>';

        // =====================================================================
        // Section 2: Keyword Pack & Content Preview
        // =====================================================================
        $pack_json         = (string) get_post_meta( $post_id, '_tmwseo_keyword_pack_json', true );
        $preview_url       = wp_nonce_url(
            admin_url( 'admin-post.php?action=tmwseo_video_generate_preview&post_id=' . $post_id ),
            'tmwseo_video_generate_preview_' . $post_id
        );
        $apply_preview_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=tmwseo_video_apply_preview&post_id=' . $post_id ),
            'tmwseo_video_apply_preview_' . $post_id
        );
        $has_preview       = (string) get_post_meta( $post_id, '_tmwseo_video_preview_html', true ) !== '';

        echo '<div style="' . $s_panel . '">';
        echo '<p style="' . $s_section_head . '">Keyword Pack & Content Preview</p>';

        if ( $pack_json !== '' ) {
            $pack = json_decode( $pack_json, true );
            if ( is_array( $pack ) ) {
                echo '<p style="margin:0 0 3px;font-size:12px">'
                    . '<strong>Primary:</strong> ' . esc_html( $pack['primary'] ?? '' ) . '</p>';

                $secondary = implode( ', ', $pack['secondary'] ?? $pack['additional'] ?? [] );
                if ( $secondary !== '' ) {
                    echo '<p style="margin:0 0 3px;font-size:12px">'
                        . '<strong>Secondary:</strong> ' . esc_html( $secondary ) . '</p>';
                }
                if ( ! empty( $pack['longtail'] ) ) {
                    echo '<p style="margin:0 0 3px;font-size:12px">'
                        . '<strong>Long-tail:</strong> ' . esc_html( implode( '; ', $pack['longtail'] ) ) . '</p>';
                }
                if ( class_exists( '\\TMWSEO\\Engine\\Content\\RankMathMapper' ) ) {
                    echo '<p style="margin:0 0 3px;font-size:12px"><strong>Rank Math:</strong> '
                        . '<code style="font-size:11px">'
                        . esc_html( RankMathMapper::preview_rank_math_csv( $post_id, $pack ) )
                        . '</code></p>';
                }
            }
        } else {
            echo '<p style="margin:0 0 6px;font-size:12px;color:#9ca3af">No keyword pack yet.</p>';
        }

        // Action buttons (flex row, same buttons as before).
        echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px">';
        echo '<a class="button" href="' . esc_url( $preview_url ) . '">Generate Content Preview</a>';
        if ( $has_preview ) {
            echo '<a class="button button-primary" href="' . esc_url( $apply_preview_url ) . '">Apply Content Preview</a>';
        }
        echo '</div>';

        // -- Humanizer Signals advisory (read-only, advisory only) -----------
        if ( $has_preview ) {
            $hd_raw  = (string) get_post_meta( $post_id, '_tmwseo_quality_score_data', true );
            $hd_data = $hd_raw !== '' ? json_decode( $hd_raw, true ) : null;
            $hd      = is_array( $hd_data ) && is_array( $hd_data['humanizer_diagnostics'] ?? null )
                ? $hd_data['humanizer_diagnostics']
                : null;

            if ( $hd !== null ) {
                echo '<div style="margin-top:8px;padding:8px 10px;border-radius:3px;border:1px solid '
                    . ( $hd['warning'] ? '#f59e0b' : '#d1d5db' )
                    . ';background:'
                    . ( $hd['warning'] ? '#fffbeb' : '#f9fafb' )
                    . '">';
                echo '<strong style="font-size:12px;color:'
                    . ( $hd['warning'] ? '#92400e' : '#6b7280' )
                    . '">&#128270; Humanizer Signals</strong>';

                if ( ! $hd['warning'] ) {
                    echo '<span style="margin-left:8px;font-size:12px;color:#6b7280">'
                        . '&#10003; No AI-writing signals detected</span>';
                } else {
                    $summary = (string) ( $hd['signal_summary'] ?? '' );
                    if ( $summary !== '' ) {
                        echo '<p style="margin:4px 0 6px;font-size:12px;color:#92400e">'
                            . esc_html( $summary ) . '</p>';
                    }
                    if ( ! empty( $hd['flagged_phrases'] ) ) {
                        echo '<ul style="margin:0;padding-left:16px;font-size:12px;color:#78350f">';
                        foreach ( array_slice( (array) $hd['flagged_phrases'], 0, 5 ) as $fp ) {
                            $phrase = (string) ( $fp['phrase'] ?? '' );
                            $count  = (int)    ( $fp['count']  ?? 0 );
                            $type   = (string) ( $fp['type']   ?? '' );
                            echo '<li>' . esc_html( '"' . $phrase . '" x' . $count . ' (' . $type . ')' ) . '</li>';
                        }
                        echo '</ul>';
                    }
                    if ( ! empty( $hd['repeated_openers'] ) ) {
                        $opener_labels = array_map(
                            static fn( $o ) => '"' . ( $o['opener'] ?? '' ) . '" x' . (int) ( $o['count'] ?? 0 ),
                            (array) $hd['repeated_openers']
                        );
                        echo '<p style="margin:4px 0 0;font-size:12px;color:#92400e">'
                            . esc_html( 'Repeated openers: ' . implode( ', ', $opener_labels ) )
                            . '</p>';
                    }
                    if ( isset( $hd['em_dash_count'] ) && (int) $hd['em_dash_count'] >= 3 ) {
                        echo '<p style="margin:4px 0 0;font-size:12px;color:#92400e">'
                            . esc_html( 'Em dashes: ' . (int) $hd['em_dash_count'] )
                            . '</p>';
                    }
                }
                echo '</div>';
            }
        }

        echo '</div>'; // end keyword pack & preview panel

        // =====================================================================
        // Section 3: Cannibalization Check
        // =====================================================================
        echo '<div style="' . $s_panel . '">';
        echo '<p style="' . $s_section_head . '">Cannibalization Check</p>';

        if ( class_exists( '\\TMWSEO\\Engine\\Keywords\\CannibalizationDetector' ) ) {
            $conflicts = CannibalizationDetector::check_post( $post_id );
            if ( empty( $conflicts ) ) {
                echo '<p style="margin:0;font-size:12px"><span style="' . $s_badge_green . '">No conflicts</span></p>';
            } else {
                echo '<p style="margin:0 0 6px;font-size:12px">'
                    . '<span style="' . $s_badge_amber . '">' . count( $conflicts ) . ' conflict(s)</span></p>';
                echo '<ul style="margin:0;padding-left:16px;font-size:12px;color:#78350f">';
                foreach ( $conflicts as $c ) {
                    echo '<li><strong>' . esc_html( $c['keyword'] ) . '</strong>'
                        . ' — ' . esc_html( $c['conflicting_post_type'] )
                        . ' #' . (int) $c['conflicting_post_id']
                        . ' <span style="color:#9ca3af">(' . esc_html( $c['severity'] ) . ')</span></li>';
                }
                echo '</ul>';

                // Persist cannibalization data via AuditTrail.
                if ( class_exists( '\\TMWSEO\\Engine\\Content\\AuditTrail' ) ) {
                    AuditTrail::persist_cannibalization( $post_id, $conflicts );
                }
            }
        }

        echo '</div>';

        // =====================================================================
        // Section 4: Index Readiness
        // =====================================================================
        echo '<div style="' . $s_panel . '">';
        echo '<p style="' . $s_section_head . '">Index Readiness</p>';

        if ( class_exists( '\\TMWSEO\\Engine\\Content\\IndexReadinessGate' ) ) {
            $readiness = IndexReadinessGate::evaluate_post( $post_id );
            if ( $readiness['ready'] ) {
                echo '<p style="margin:0;font-size:12px">'
                    . '<span style="' . $s_badge_green . '">Ready</span>'
                    . ' <span style="color:#6b7280">All gates pass.</span></p>';
            } else {
                echo '<p style="margin:0 0 6px;font-size:12px">'
                    . '<span style="' . $s_badge_red . '">Not ready</span></p>';
                echo '<ul style="margin:0;padding-left:16px;font-size:12px;color:#991b1b">';
                foreach ( $readiness['reasons'] as $r ) {
                    echo '<li>' . esc_html( $r ) . '</li>';
                }
                echo '</ul>';
            }
        }

        echo '</div>';
    }

    // -- Handlers ----------------------------------------------------------

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
        // Persist full quality blob (includes humanizer_diagnostics) for metabox display.
        update_post_meta( $post_id, '_tmwseo_quality_score_data', wp_json_encode( $quality ) );
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
