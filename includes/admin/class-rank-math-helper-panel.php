<?php
/**
 * RankMathHelperPanel — editor metabox showing Rank Math snapshot,
 * SEO checklist, TMW readiness, and prefill actions.
 *
 * Design rules:
 *   - Reads via RankMathReader (never raw meta).
 *   - Checklist via RankMathChecklist (never DOM scraping).
 *   - TMW readiness via IndexReadinessGate (independent of RM score).
 *   - Prefill writes via existing RankMathMapper.
 *   - Graceful degradation when Rank Math is inactive.
 *
 * @package TMWSEO\Engine\Admin
 * @since   4.5.0
 */
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Content\RankMathReader;
use TMWSEO\Engine\Content\RankMathChecklist;
use TMWSEO\Engine\Content\RankMathMapper;
use TMWSEO\Engine\Content\IndexReadinessGate;
use TMWSEO\Engine\Keywords\UnifiedKeywordWorkflowService;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class RankMathHelperPanel {

    /** Supported post types for the panel. */
    private const SUPPORTED_TYPES = [ 'model', 'post', 'page', 'tmw_category_page' ];

    public static function init(): void {
        add_action( 'add_meta_boxes', [ __CLASS__, 'register_metabox' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_tmwseo_rm_helper_refresh', [ __CLASS__, 'ajax_refresh' ] );
        add_action( 'wp_ajax_tmwseo_rm_helper_prefill', [ __CLASS__, 'ajax_prefill' ] );
    }

    /* ──────────────────────────────────────────────
     * Metabox registration
     * ────────────────────────────────────────────── */

    public static function register_metabox(): void {
        foreach ( self::SUPPORTED_TYPES as $type ) {
            if ( ! post_type_exists( $type ) ) {
                continue;
            }
            add_meta_box(
                'tmwseo_rank_math_helper',
                __( 'TMW SEO Helper', 'tmwseo' ),
                [ __CLASS__, 'render' ],
                $type,
                'side',
                'default'
            );
        }
    }

    /* ──────────────────────────────────────────────
     * Asset loading
     * ────────────────────────────────────────────── */

    public static function enqueue_assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || ! in_array( $screen->post_type, self::SUPPORTED_TYPES, true ) ) {
            return;
        }

        wp_enqueue_script(
            'tmwseo-rm-helper',
            TMWSEO_ENGINE_URL . 'assets/js/rank-math-helper.js',
            [ 'jquery', 'wp-data' ],
            defined( 'TMWSEO_ENGINE_VERSION' ) ? TMWSEO_ENGINE_VERSION : '4.5.0',
            true
        );

        wp_localize_script( 'tmwseo-rm-helper', 'tmwseoRMHelper', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'tmwseo_rm_helper' ),
        ] );
    }

    /* ──────────────────────────────────────────────
     * Render
     * ────────────────────────────────────────────── */

    public static function render( \WP_Post $post ): void {
        $post_id = (int) $post->ID;

        echo '<div id="tmwseo-rm-helper-panel" data-post-id="' . esc_attr( (string) $post_id ) . '">';

        // ── Section 1: Rank Math Snapshot ──
        self::render_snapshot( $post_id );

        // ── Section 2: Checklist ──
        self::render_checklist( $post_id );

        // ── Section 3: TMW Readiness ──
        self::render_tmw_readiness( $post_id );

        // ── Section 4: Actions ──
        self::render_actions( $post_id );

        echo '</div>';
    }

    /* ──────────────────────────────────────────────
     * Section renderers
     * ────────────────────────────────────────────── */

    private static function render_snapshot( int $post_id ): void {
        $rm_active = RankMathReader::is_active();

        echo '<div class="tmwseo-rm-section">';
        echo '<p style="margin:0 0 6px"><strong>' . esc_html__( 'Rank Math Snapshot', 'tmwseo' ) . '</strong></p>';

        if ( ! $rm_active ) {
            echo '<p style="margin:0;font-size:12px;color:#b32d2e;">' . esc_html__( 'Rank Math is not active.', 'tmwseo' ) . '</p>';
            echo '</div>';
            return;
        }

        $snap = RankMathReader::get_snapshot( $post_id );

        $rows = [
            [ 'Focus keyword', $snap['focus_keyword'] ?: '—' ],
            [ 'SEO title',     $snap['seo_title'] ?: '(using default template)' ],
            [ 'Meta desc.',    $snap['meta_description'] ? self::truncate( $snap['meta_description'], 80 ) : '—' ],
            [ 'Score',         $snap['seo_score'] > 0 ? $snap['seo_score'] . '/100 (' . $snap['score_label'] . ')' : '—' ],
            [ 'Robots',        ! empty( $snap['robots'] ) ? implode( ', ', $snap['robots'] ) : '(default)' ],
        ];

        echo '<table style="width:100%;font-size:12px;border-collapse:collapse;">';
        foreach ( $rows as $row ) {
            echo '<tr>';
            echo '<td style="padding:2px 4px 2px 0;font-weight:600;white-space:nowrap;vertical-align:top;">' . esc_html( $row[0] ) . '</td>';
            echo '<td style="padding:2px 0;word-break:break-word;">' . esc_html( $row[1] ) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '</div>';
    }

    private static function render_checklist( int $post_id ): void {
        echo '<div id="tmwseo-rm-checklist" class="tmwseo-rm-section" style="margin-top:10px">';
        echo '<p style="margin:0 0 6px"><strong>' . esc_html__( 'SEO Checklist', 'tmwseo' ) . '</strong></p>';
        echo self::build_checklist_html( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';
    }

    /**
     * Build the checklist HTML (also used by AJAX refresh).
     */
    public static function build_checklist_html( int $post_id ): string {
        $result = RankMathChecklist::evaluate( $post_id );
        $checks = $result['checks'];

        if ( empty( $checks ) ) {
            return '<p style="margin:0;font-size:12px;">' . esc_html__( 'No checks available.', 'tmwseo' ) . '</p>';
        }

        // Group by severity.
        $groups = [
            RankMathChecklist::SEV_MUST_FIX     => [ 'label' => '🔴 Must fix', 'items' => [] ],
            RankMathChecklist::SEV_RECOMMENDED   => [ 'label' => '🟡 Recommended', 'items' => [] ],
            RankMathChecklist::SEV_OPTIONAL       => [ 'label' => '🔵 Optional', 'items' => [] ],
            RankMathChecklist::SEV_IGNORE_FOR_PAGE_TYPE => [ 'label' => '⚪ N/A for this page type', 'items' => [] ],
        ];

        foreach ( $checks as $check ) {
            $sev = $check['severity'];
            if ( isset( $groups[ $sev ] ) ) {
                $groups[ $sev ]['items'][] = $check;
            }
        }

        $html = '';

        // Summary bar.
        $s = $result['summary'];
        $html .= '<p style="margin:0 0 8px;font-size:12px;">';
        $html .= '<span style="color:#46b450;">✅ ' . $s['pass'] . '</span> &nbsp; ';
        $html .= '<span style="color:#dc3232;">❌ ' . $s['fail'] . '</span> &nbsp; ';
        $html .= '<span style="color:#dba617;">⚠ ' . $s['warning'] . '</span> &nbsp; ';
        $html .= '<strong>~' . $s['score_estimate'] . '%</strong>';
        $html .= '</p>';

        // Status icons.
        $icons = [
            RankMathChecklist::STATUS_PASS    => '✅',
            RankMathChecklist::STATUS_FAIL    => '❌',
            RankMathChecklist::STATUS_WARNING => '⚠️',
        ];

        foreach ( $groups as $sev => $group ) {
            // Skip empty groups and ignored groups if all pass.
            $non_pass = array_filter( $group['items'], fn( $c ) => $c['status'] !== RankMathChecklist::STATUS_PASS );
            if ( empty( $group['items'] ) ) {
                continue;
            }
            // For "ignore" severity, only show if there are failures.
            if ( $sev === RankMathChecklist::SEV_IGNORE_FOR_PAGE_TYPE && empty( $non_pass ) ) {
                continue;
            }

            $html .= '<p style="margin:8px 0 4px;font-size:11px;font-weight:600;">' . esc_html( $group['label'] ) . '</p>';

            foreach ( $group['items'] as $check ) {
                $icon = $icons[ $check['status'] ] ?? '❓';
                $html .= '<div style="font-size:12px;margin:0 0 4px;line-height:1.4;">';
                $html .= $icon . ' ' . esc_html( $check['label'] );
                if ( $check['fix'] !== '' && $check['status'] !== RankMathChecklist::STATUS_PASS ) {
                    $html .= '<br><span style="font-size:11px;opacity:.8;margin-left:18px;display:inline-block;">' . esc_html( $check['fix'] ) . '</span>';
                }
                $html .= '</div>';
            }
        }

        return $html;
    }

    private static function render_tmw_readiness( int $post_id ): void {
        echo '<div class="tmwseo-rm-section" style="margin-top:10px;border-top:1px solid #ddd;padding-top:8px;">';
        echo '<p style="margin:0 0 6px"><strong>' . esc_html__( 'TMW Readiness (independent)', 'tmwseo' ) . '</strong></p>';

        $ready    = (string) get_post_meta( $post_id, '_tmwseo_ready_to_index', true ) === '1';
        $gate_log = (string) get_post_meta( $post_id, '_tmwseo_gate_log', true );

        $icon  = $ready ? '✅' : '❌';
        $label = $ready ? __( 'Ready to index', 'tmwseo' ) : __( 'Not ready to index', 'tmwseo' );

        echo '<p style="margin:0;font-size:12px;">' . $icon . ' <strong>' . esc_html( $label ) . '</strong></p>';

        if ( ! $ready && $gate_log !== '' ) {
            echo '<p style="margin:4px 0 0;font-size:11px;opacity:.8;">' . esc_html( $gate_log ) . '</p>';
        }

        echo '<p style="margin:6px 0 0;font-size:11px;opacity:.7;">'
            . esc_html__( 'TMW readiness is separate from Rank Math score. A page can be green in Rank Math but not ready in TMW.', 'tmwseo' )
            . '</p>';
        echo '</div>';
    }

    private static function render_actions( int $post_id ): void {
        echo '<div class="tmwseo-rm-section" style="margin-top:10px;border-top:1px solid #ddd;padding-top:8px;">';
        echo '<p style="margin:0 0 6px"><strong>' . esc_html__( 'Quick Actions', 'tmwseo' ) . '</strong></p>';

        // Gather TMW recommendations for the prefill data attribute.
        $pack = UnifiedKeywordWorkflowService::get_pack_with_legacy_fallback( $post_id );
        $primary_kw = trim( (string) ( $pack['primary'] ?? '' ) );
        $post_title = trim( get_the_title( $post_id ) );

        // Current stored values for display.
        $current_title = RankMathReader::get_seo_title( $post_id );
        $current_desc  = RankMathReader::get_meta_description( $post_id );
        $current_kw    = RankMathReader::get_primary_keyword( $post_id );

        $tmw_keyword = $primary_kw !== '' ? $primary_kw : $post_title;

        // Store prefill data for JS.
        $prefill = [
            'keyword'     => $tmw_keyword,
            'title'       => $current_title !== '' ? $current_title : $post_title,
            'description' => $current_desc,
        ];

        echo '<div id="tmwseo-rm-prefill-data" style="display:none;" '
            . 'data-keyword="' . esc_attr( $prefill['keyword'] ) . '" '
            . 'data-title="' . esc_attr( $prefill['title'] ) . '" '
            . 'data-description="' . esc_attr( $prefill['description'] ) . '"'
            . '></div>';

        // Sync keywords button.
        echo '<p style="margin:0 0 6px;">'
            . '<button type="button" id="tmwseo-rm-sync-keywords" class="button" style="width:100%;" '
            . 'data-post-id="' . esc_attr( (string) $post_id ) . '">'
            . esc_html__( 'Sync Keywords to Rank Math', 'tmwseo' )
            . '</button></p>';

        // Prefill snippet button (uses JS bridge to populate RM editor fields).
        if ( RankMathReader::is_active() ) {
            echo '<p style="margin:0 0 6px;">'
                . '<button type="button" id="tmwseo-rm-prefill-snippet" class="button" style="width:100%;">'
                . esc_html__( 'Prefill Snippet in Editor', 'tmwseo' )
                . '</button></p>';
        }

        // Refresh checklist.
        echo '<p style="margin:0;">'
            . '<button type="button" id="tmwseo-rm-refresh" class="button" style="width:100%;" '
            . 'data-post-id="' . esc_attr( (string) $post_id ) . '">'
            . esc_html__( 'Refresh Checklist', 'tmwseo' )
            . '</button></p>';

        echo '</div>';
    }

    /* ──────────────────────────────────────────────
     * AJAX handlers
     * ────────────────────────────────────────────── */

    /**
     * Refresh the checklist panel via AJAX.
     */
    public static function ajax_refresh(): void {
        check_ajax_referer( 'tmwseo_rm_helper', 'nonce' );

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Invalid post or insufficient permissions.' ] );
        }

        $html = self::build_checklist_html( $post_id );
        wp_send_json_success( [ 'html' => $html ] );
    }

    /**
     * Sync TMW keyword pack to Rank Math via existing RankMathMapper.
     */
    public static function ajax_prefill(): void {
        check_ajax_referer( 'tmwseo_rm_helper', 'nonce' );

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Invalid post or insufficient permissions.' ] );
        }

        $action = sanitize_key( (string) ( $_POST['prefill_action'] ?? '' ) );

        switch ( $action ) {
            case 'sync_keywords':
                $pack = UnifiedKeywordWorkflowService::get_pack_with_legacy_fallback( $post_id );
                if ( empty( $pack['primary'] ) ) {
                    wp_send_json_error( [ 'message' => 'No TMW keyword pack available for this post.' ] );
                }
                RankMathMapper::sync_to_rank_math( $post_id, $pack );
                $new_kw = RankMathReader::get_focus_keyword_csv( $post_id );
                wp_send_json_success( [
                    'message'       => 'Keywords synced to Rank Math.',
                    'focus_keyword' => $new_kw,
                ] );
                break;

            default:
                wp_send_json_error( [ 'message' => 'Unknown action.' ] );
        }
    }

    /* ──────────────────────────────────────────────
     * Helpers
     * ────────────────────────────────────────────── */

    private static function truncate( string $text, int $max ): string {
        if ( mb_strlen( $text ) <= $max ) {
            return $text;
        }
        return mb_substr( $text, 0, $max ) . '…';
    }
}
