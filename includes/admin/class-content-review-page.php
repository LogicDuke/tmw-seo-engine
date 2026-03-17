<?php
/**
 * TMW SEO Engine — Content Review Page
 *
 * The second primary operator screen. Shows:
 * - Pages with generated content awaiting approval
 * - Quality scores, uniqueness, readiness gates
 * - Approve / Hold / Reject controls
 *
 * @package TMWSEO\Engine\Admin
 * @since   5.0.0
 */

namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Content\IndexReadinessGate;
use TMWSEO\Engine\Content\ContentGenerationGate;
use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ContentReviewPage {

    private const PAGE_SLUG = 'tmwseo-content-review';

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_post_tmwseo_content_review_action', [ __CLASS__, 'handle_action' ] );
    }

    public static function register_menu(): void {
        add_submenu_page(
            'tmwseo-engine',
            __( 'Content Review', 'tmwseo' ),
            __( 'Content Review', 'tmwseo' ),
            'manage_options',
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function handle_action(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden' );
        }
        check_admin_referer( 'tmwseo_content_review_nonce' );

        $action  = sanitize_key( $_POST['tmwseo_cr_action'] ?? '' );
        $post_id = (int) ( $_POST['post_id'] ?? 0 );

        if ( $post_id <= 0 ) {
            wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG ], admin_url( 'admin.php' ) ) );
            exit;
        }

        switch ( $action ) {
            case 'approve_for_index':
                update_post_meta( $post_id, '_tmwseo_approval_status', 'approved' );
                update_post_meta( $post_id, '_tmwseo_approved_at', current_time( 'mysql' ) );
                update_post_meta( $post_id, '_tmwseo_approved_by', get_current_user_id() );
                // Re-evaluate readiness with approval now in place
                IndexReadinessGate::evaluate_post( $post_id );
                Logs::info( 'content_review', '[TMW-REVIEW] Content approved for indexing', [
                    'post_id' => $post_id,
                    'user'    => get_current_user_id(),
                ] );
                break;

            case 'hold':
                update_post_meta( $post_id, '_tmwseo_approval_status', 'hold' );
                Logs::info( 'content_review', '[TMW-REVIEW] Content held', [
                    'post_id' => $post_id,
                    'user'    => get_current_user_id(),
                ] );
                break;

            case 'reject_content':
                update_post_meta( $post_id, '_tmwseo_approval_status', 'rejected' );
                update_post_meta( $post_id, IndexReadinessGate::META_READY, '0' );
                Logs::info( 'content_review', '[TMW-REVIEW] Content rejected', [
                    'post_id' => $post_id,
                    'user'    => get_current_user_id(),
                ] );
                break;
        }

        wp_safe_redirect( add_query_arg( [
            'page' => self::PAGE_SLUG,
            'msg'  => $action,
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $msg = sanitize_key( $_GET['msg'] ?? '' );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Content Review', 'tmwseo' ) . '</h1>';

        if ( ContentGenerationGate::is_paused() ) {
            echo '<div class="notice notice-warning"><p><strong>Content generation is paused.</strong> No new content will be generated until resumed from the Command Center.</p></div>';
        }

        if ( $msg !== '' ) {
            echo '<div class="notice notice-success is-dismissible"><p>Action completed: <code>' . esc_html( $msg ) . '</code></p></div>';
        }

        // Get posts with content that needs review
        $posts = get_posts( [
            'post_type'      => [ 'model', 'post', 'tmw_category_page' ],
            'post_status'    => [ 'publish', 'draft', 'pending' ],
            'posts_per_page' => 50,
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'     => '_tmwseo_keyword',
                    'compare' => '!=',
                    'value'   => '',
                ],
                [
                    'relation' => 'OR',
                    [
                        'key'     => '_tmwseo_approval_status',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'   => '_tmwseo_approval_status',
                        'value' => [ '', 'pending', 'hold' ],
                        'compare' => 'IN',
                    ],
                ],
            ],
            'orderby'  => 'modified',
            'order'    => 'DESC',
        ] );

        if ( empty( $posts ) ) {
            echo '<p>No content awaiting review.</p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Page</th><th>Type</th><th>Primary KW</th>';
        echo '<th>Quality</th><th>Unique</th><th>Confidence</th>';
        echo '<th>Ready?</th><th>Actions</th>';
        echo '</tr></thead><tbody>';

        foreach ( $posts as $post ) {
            $pid        = $post->ID;
            $primary_kw = (string) get_post_meta( $pid, '_tmwseo_keyword', true );
            $quality    = (int) get_post_meta( $pid, '_tmwseo_content_quality_score', true );
            $unique     = (float) get_post_meta( $pid, '_tmwseo_uniqueness_score', true );
            $confidence = (float) get_post_meta( $pid, '_tmwseo_keyword_confidence', true );
            $ready_meta = get_post_meta( $pid, IndexReadinessGate::META_READY, true );
            $gate_log   = json_decode( (string) get_post_meta( $pid, IndexReadinessGate::META_GATE_LOG, true ), true );
            $approval   = (string) get_post_meta( $pid, '_tmwseo_approval_status', true );

            $ready_display = $ready_meta === '1' ? '<span style="color:green">Yes</span>'
                : ( $ready_meta === '0' ? '<span style="color:red">No</span>' : '<span style="color:gray">Not evaluated</span>' );

            $gate_reasons = '';
            if ( is_array( $gate_log ) && ! empty( $gate_log['reasons'] ) ) {
                $gate_reasons = implode( ', ', (array) $gate_log['reasons'] );
            }

            echo '<tr>';
            echo '<td><a href="' . esc_url( get_edit_post_link( $pid ) ) . '">' . esc_html( get_the_title( $pid ) ) . '</a>';
            if ( $approval !== '' ) {
                echo ' <small>[' . esc_html( $approval ) . ']</small>';
            }
            echo '</td>';
            echo '<td>' . esc_html( $post->post_type ) . '</td>';
            echo '<td>' . esc_html( $primary_kw ) . '</td>';
            echo '<td>' . esc_html( $quality ?: '-' ) . '</td>';
            echo '<td>' . esc_html( $unique ? round( $unique ) : '-' ) . '</td>';
            echo '<td>' . esc_html( $confidence ? round( $confidence ) : '-' ) . '</td>';
            echo '<td>' . $ready_display;
            if ( $gate_reasons ) {
                echo '<br><small style="color:#666">' . esc_html( $gate_reasons ) . '</small>';
            }
            echo '</td>';
            echo '<td>';

            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
            wp_nonce_field( 'tmwseo_content_review_nonce' );
            echo '<input type="hidden" name="action" value="tmwseo_content_review_action">';
            echo '<input type="hidden" name="post_id" value="' . $pid . '">';

            echo '<button type="submit" name="tmwseo_cr_action" value="approve_for_index" class="button button-small button-primary">Approve</button> ';
            echo '<button type="submit" name="tmwseo_cr_action" value="hold" class="button button-small">Hold</button> ';
            echo '<button type="submit" name="tmwseo_cr_action" value="reject_content" class="button button-small">Reject</button>';

            echo '</form>';
            echo '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }
}
