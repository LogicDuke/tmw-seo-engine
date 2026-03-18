<?php
/**
 * TMW SEO Engine — Keyword Command Center (v5.2)
 *
 * The primary operator screen. Weekly workflow centers here.
 *
 * v5.2 KEY CHANGES:
 *  - ONE merged review queue capped at 50 TOTAL across both tracks
 *  - Generator approval is FINAL keyword approval (no double-review)
 *  - Clear lifecycle labels and operator guidance
 *
 * Lifecycle:
 *   Discovery: discovered → scored → queued_for_review → (human) → approved
 *   Generator: pending → (human) → approved (direct, no second review)
 *   Then: approved → assigned → content_ready → indexed
 *
 * @package TMWSEO\Engine\Admin
 * @since   5.2.0
 */

namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Keywords\SeedRegistry;
use TMWSEO\Engine\Keywords\ExpansionCandidateRepository;
use TMWSEO\Engine\Keywords\OwnershipEnforcer;
use TMWSEO\Engine\Keywords\ArchitectureReset;
use TMWSEO\Engine\Keywords\StagingCleanRebuild;
use TMWSEO\Engine\Content\ContentGenerationGate;
use TMWSEO\Engine\Services\TrustPolicy;
use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KeywordCommandCenter {

    private const PAGE_SLUG = 'tmwseo-command-center';

    /** Combined actionable queue cap: 50 TOTAL across both tracks. */
    private const QUEUE_CAP = 50;

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_post_tmwseo_command_center_action', [ __CLASS__, 'handle_action' ] );
    }

    public static function register_menu(): void {
        add_submenu_page(
            'tmwseo-engine',
            'Command Center',
            'Command Center',
            'manage_options',
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    // === Action Handler =====================================================

    public static function handle_action(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden' );
        }
        check_admin_referer( 'tmwseo_cc_nonce' );

        $action = sanitize_key( $_POST['cc_action'] ?? '' );
        $tab    = 'review';
        $msg    = $action;

        switch ( $action ) {

            // -- Generator-track actions --
            case 'gen_approve':
                $id = (int) ( $_POST['gen_id'] ?? 0 );
                if ( $id > 0 ) { ExpansionCandidateRepository::approve_candidate( $id, get_current_user_id() ); }
                break;
            case 'gen_reject':
                $id = (int) ( $_POST['gen_id'] ?? 0 );
                if ( $id > 0 ) { ExpansionCandidateRepository::reject_candidate( $id, '', get_current_user_id() ); }
                break;
            case 'gen_park':
                $id = (int) ( $_POST['gen_id'] ?? 0 );
                if ( $id > 0 ) { ExpansionCandidateRepository::archive_candidate( $id ); }
                break;

            // -- Discovery-track actions --
            case 'disc_approve':
                $id = (int) ( $_POST['disc_id'] ?? 0 );
                if ( $id > 0 ) { self::disc_set_status( $id, 'approved' ); }
                break;
            case 'disc_reject':
                $id = (int) ( $_POST['disc_id'] ?? 0 );
                if ( $id > 0 ) { self::disc_set_status( $id, 'rejected' ); }
                break;
            case 'disc_park':
                $id = (int) ( $_POST['disc_id'] ?? 0 );
                if ( $id > 0 ) { self::disc_set_status( $id, 'scored' ); }
                break;

            // -- Batch actions (unified) --
            case 'batch_approve':
                foreach ( self::collect_batch_ids() as list( $track, $id ) ) {
                    if ( $track === 'disc' ) { self::disc_set_status( $id, 'approved' ); }
                    else { ExpansionCandidateRepository::approve_candidate( $id, get_current_user_id() ); }
                }
                break;
            case 'batch_reject':
                foreach ( self::collect_batch_ids() as list( $track, $id ) ) {
                    if ( $track === 'disc' ) { self::disc_set_status( $id, 'rejected' ); }
                    else { ExpansionCandidateRepository::reject_candidate( $id, 'batch_reject', get_current_user_id() ); }
                }
                break;

            // -- Assignment --
            case 'assign':
                $post_id = (int) ( $_POST['target_post_id'] ?? 0 );
                $kw      = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
                if ( $post_id > 0 && $kw !== '' ) {
                    $check = OwnershipEnforcer::enforce_assignment( $kw, $post_id, 'primary' );
                    if ( $check['allowed'] ) {
                        update_post_meta( $post_id, '_tmwseo_keyword', $kw );
                        Logs::info( 'assignment', '[TMW-ASSIGN] Keyword assigned', [ 'post_id' => $post_id, 'keyword' => $kw, 'user' => get_current_user_id() ] );
                    }
                }
                $tab = 'assign';
                break;

            // -- System --
            case 'reset':
                set_transient( 'tmwseo_reset_result', ArchitectureReset::execute_reset(), 300 );
                $tab = 'health';
                break;
            case 'clean_rebuild_zero':
                $confirmation = sanitize_text_field( wp_unslash( $_POST['clean_rebuild_confirm'] ?? '' ) );
                if ( $confirmation !== 'CLEAN-REBUILD-ZERO' ) {
                    set_transient( 'tmwseo_clean_rebuild_result', StagingCleanRebuild::record_preflight_failure( get_current_user_id(), [ 'confirmation_mismatch' ] ), 300 );
                    $msg = 'clean_rebuild_zero_invalid_confirm';
                } else {
                    set_transient( 'tmwseo_clean_rebuild_result', StagingCleanRebuild::execute( get_current_user_id() ), 300 );
                }
                $tab = 'health';
                break;
            case 'clear_clean_rebuild_history':
                StagingCleanRebuild::clear_last_result();
                $tab = 'health';
                $msg = 'clean_rebuild_history_cleared';
                break;
            case 'resume':
                ContentGenerationGate::resume_all();
                $tab = 'health';
                break;
            case 'add_seed':
                $p = sanitize_text_field( wp_unslash( $_POST['manual_phrase'] ?? '' ) );
                if ( $p !== '' ) { SeedRegistry::register_trusted_seed( $p, 'manual', 'system', 0 ); }
                $tab = 'health';
                break;
        }

        wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => $tab, 'msg' => $msg ], admin_url( 'admin.php' ) ) );
        exit;
    }

    // === Helpers for discovery-track status changes ==========================

    private static function disc_set_status( int $id, string $status ): void {
        global $wpdb;
        $t   = $wpdb->prefix . 'tmw_keyword_candidates';
        $now = current_time( 'mysql' );
        $uid = get_current_user_id();
        $wpdb->update( $t, [
            'status'     => $status,
            'notes'      => $wpdb->get_var( $wpdb->prepare(
                "SELECT CONCAT(IFNULL(notes,''), %s) FROM {$t} WHERE id = %d",
                " | {$status}_by:{$uid}:{$now}",
                $id
            ) ),
            'updated_at' => $now,
        ], [ 'id' => $id ] );

        Logs::info( 'keywords', '[TMW-REVIEW] Discovery keyword ' . $status, [ 'id' => $id, 'user' => $uid ] );
    }

    /** Parse unified batch checkbox values like "disc:123" / "gen:456". */
    private static function collect_batch_ids(): array {
        $raw = (array) ( $_POST['queue_ids'] ?? [] );
        $out = [];
        foreach ( $raw as $v ) {
            $v = sanitize_text_field( (string) $v );
            if ( strpos( $v, ':' ) !== false ) {
                list( $track, $id ) = explode( ':', $v, 2 );
                $id = (int) $id;
                if ( $id > 0 && in_array( $track, [ 'disc', 'gen' ], true ) ) {
                    $out[] = [ $track, $id ];
                }
            }
        }
        return $out;
    }

    // === Page Renderer ======================================================

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $tab = sanitize_key( $_GET['tab'] ?? 'review' );
        $msg = sanitize_key( $_GET['msg'] ?? '' );

        echo '<div class="wrap"><h1>Keyword Command Center</h1>';
        echo '<p style="color:#666;margin-top:-5px;">Weekly: <strong>1) Review</strong> &rarr; <strong>2) Assign</strong> &rarr; <a href="' . esc_url( admin_url( 'admin.php?page=tmwseo-content-review' ) ) . '"><strong>Content Review</strong></a></p>';

        $tabs = [ 'review' => '1. Review', 'assign' => '2. Assign', 'health' => '3. Health' ];
        echo '<nav class="nav-tab-wrapper">';
        foreach ( $tabs as $k => $l ) {
            echo '<a href="' . esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => $k ], admin_url( 'admin.php' ) ) ) . '" class="nav-tab' . ( $tab === $k ? ' nav-tab-active' : '' ) . '">' . esc_html( $l ) . '</a>';
        }
        echo '</nav>';

        if ( $msg === 'clean_rebuild_zero_invalid_confirm' ) {
            echo '<div class="notice notice-warning is-dismissible"><p>Clean Rebuild from Zero was blocked. Type <code>CLEAN-REBUILD-ZERO</code> exactly to continue.</p></div>';
        } elseif ( $msg === 'clean_rebuild_history_cleared' ) {
            echo '<div class="notice notice-success is-dismissible"><p>Last Clean Rebuild Run history was cleared.</p></div>';
        } elseif ( $msg !== '' && ! in_array( $msg, [ 'clean_rebuild_zero' ], true ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Done: <code>' . esc_html( $msg ) . '</code></p></div>';
        }

        echo '<div style="margin-top:15px;">';
        match ( $tab ) {
            'review' => self::render_review(),
            'assign' => self::render_assign(),
            'health' => self::render_health(),
            default  => self::render_review(),
        };
        echo '</div></div>';
    }

    // === PANEL 1: UNIFIED REVIEW QUEUE ======================================

    private static function render_review(): void {
        // ── Fetch from both tracks, merge into one capped queue ──

        $merged = self::build_merged_queue();

        // Overflow counts
        global $wpdb;
        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $disc_scored = 0;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cand_table ) ) === $cand_table ) {
            $disc_scored = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$cand_table} WHERE status = 'scored'" );
        }

        echo '<h2>Review Queue <small style="font-weight:normal;color:#666;">(' . count( $merged ) . ' / ' . self::QUEUE_CAP . ' max)</small></h2>';

        echo '<div style="background:#f0f6fc;border:1px solid #c3daf0;padding:10px 15px;margin-bottom:15px;border-radius:4px;">';
        echo '<strong>One queue, two sources.</strong> ';
        echo '<span style="background:#e2e3e5;padding:1px 6px;border-radius:3px;font-size:11px;">Discovery</span> = keywords from seed expansion. ';
        echo '<span style="background:#fff3cd;padding:1px 6px;border-radius:3px;font-size:11px;">Generator</span> = tag/model/video phrases. ';
        echo 'Max 50 combined. Approving here is <strong>final</strong> — approved keywords go directly to the Assignment tab.';
        echo '</div>';

        if ( $disc_scored > 0 ) {
            echo '<p style="color:#856404;"><strong>' . $disc_scored . '</strong> more scored keywords waiting for queue space (deferred, not lost).</p>';
        }

        if ( empty( $merged ) ) {
            echo '<p style="color:#666;">Queue is empty. Next cron cycle will promote scored items here.</p>';
            echo '<div style="margin-top:20px;padding:10px 15px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:4px;">';
            echo '<strong>Next step:</strong> Go to <em>2. Assign</em> to assign already-approved keywords, or wait for the next discovery cycle.';
            echo '</div>';
            return;
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tmwseo_cc_nonce' );
        echo '<input type="hidden" name="action" value="tmwseo_command_center_action">';
        echo '<input type="hidden" name="disc_id" value="0">';
        echo '<input type="hidden" name="gen_id" value="0">';

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th style="width:30px;"><input type="checkbox" id="sa"></th>';
        echo '<th>Keyword / Phrase</th><th>Track</th><th>Vol</th><th>KD</th><th>Opp</th>';
        echo '<th>Source</th><th>Actions</th>';
        echo '</tr></thead><tbody>';

        foreach ( $merged as $item ) {
            $id    = (int) $item['id'];
            $track = (string) $item['track'];
            $cbval = $track . ':' . $id;

            $track_badge = $track === 'disc'
                ? '<span style="background:#e2e3e5;padding:1px 6px;border-radius:3px;font-size:11px;">Discovery</span>'
                : '<span style="background:#fff3cd;padding:1px 6px;border-radius:3px;font-size:11px;">Generator</span>';

            echo '<tr>';
            echo '<td><input type="checkbox" name="queue_ids[]" value="' . esc_attr( $cbval ) . '"></td>';
            echo '<td><strong>' . esc_html( $item['keyword'] ) . '</strong></td>';
            echo '<td>' . $track_badge . '</td>';
            echo '<td>' . esc_html( $item['volume'] ?? '-' ) . '</td>';
            echo '<td>' . esc_html( $item['difficulty'] ?? '-' ) . '</td>';
            echo '<td>' . esc_html( $item['opportunity'] ?? '-' ) . '</td>';
            echo '<td><small>' . esc_html( $item['source'] ) . '</small></td>';

            echo '<td style="white-space:nowrap;">';
            if ( $track === 'disc' ) {
                echo '<button type="submit" name="cc_action" value="disc_approve" class="button button-small button-primary" onclick="this.form.disc_id.value=' . $id . '">Approve</button> ';
                echo '<button type="submit" name="cc_action" value="disc_reject" class="button button-small" onclick="this.form.disc_id.value=' . $id . '">Reject</button> ';
                echo '<button type="submit" name="cc_action" value="disc_park" class="button button-small" onclick="this.form.disc_id.value=' . $id . '">Park</button>';
            } else {
                echo '<button type="submit" name="cc_action" value="gen_approve" class="button button-small button-primary" onclick="this.form.gen_id.value=' . $id . '">Approve</button> ';
                echo '<button type="submit" name="cc_action" value="gen_reject" class="button button-small" onclick="this.form.gen_id.value=' . $id . '">Reject</button> ';
                echo '<button type="submit" name="cc_action" value="gen_park" class="button button-small" onclick="this.form.gen_id.value=' . $id . '">Park</button>';
            }
            echo '</td></tr>';
        }

        echo '</tbody></table>';
        echo '<div style="margin-top:8px;">';
        echo '<button type="submit" name="cc_action" value="batch_approve" class="button button-primary">Approve Selected</button> ';
        echo '<button type="submit" name="cc_action" value="batch_reject" class="button">Reject Selected</button>';
        echo '</div></form>';

        echo '<script>document.getElementById("sa").addEventListener("change",function(){document.querySelectorAll("input[name=\'queue_ids[]\']").forEach(function(c){c.checked=this.checked}.bind(this))});</script>';

        echo '<div style="margin-top:20px;padding:10px 15px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:4px;">';
        echo '<strong>Next step:</strong> Switch to <em>2. Assign</em> to assign approved keywords to pages.';
        echo '</div>';
    }

    /**
     * Build one merged queue from both tracks, capped at QUEUE_CAP total.
     *
     * Discovery items: queued_for_review in tmw_keyword_candidates
     * Generator items: pending/fast_track in tmw_seed_expansion_candidates
     *
     * Items are interleaved fairly: fetch up to QUEUE_CAP from each, merge,
     * sort by a unified score, then truncate to QUEUE_CAP total.
     */
    private static function build_merged_queue(): array {
        global $wpdb;
        $merged = [];

        // -- Discovery track --
        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cand_table ) ) === $cand_table ) {
            $disc_rows = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT id, keyword, volume, difficulty, opportunity, intent, sources
                 FROM {$cand_table} WHERE status = 'queued_for_review'
                 ORDER BY opportunity DESC LIMIT %d",
                self::QUEUE_CAP
            ), ARRAY_A );

            foreach ( $disc_rows as $r ) {
                $src = trim( strtok( (string) ( $r['sources'] ?? '' ), "\n" ) );
                $merged[] = [
                    'track'      => 'disc',
                    'id'         => (int) $r['id'],
                    'keyword'    => (string) $r['keyword'],
                    'volume'     => $r['volume'] ?? '-',
                    'difficulty' => $r['difficulty'] !== null ? (string) round( (float) $r['difficulty'] ) : '-',
                    'opportunity'=> $r['opportunity'] !== null ? (string) round( (float) $r['opportunity'] ) : '-',
                    'source'     => mb_strlen( $src ) > 30 ? mb_substr( $src, 0, 27 ) . '...' : ( $src ?: '-' ),
                    'sort_score' => (float) ( $r['opportunity'] ?? 0 ),
                ];
            }
        }

        // -- Generator track --
        $gen_items = ExpansionCandidateRepository::get_pending( self::QUEUE_CAP, 0 );
        foreach ( $gen_items as $r ) {
            $merged[] = [
                'track'      => 'gen',
                'id'         => (int) $r['id'],
                'keyword'    => (string) ( $r['phrase'] ?? '' ),
                'volume'     => '-',
                'difficulty' => '-',
                'opportunity'=> (string) ( $r['quality_score'] ?? '-' ),
                'source'     => (string) ( $r['source'] ?? '' ) . ( $r['generation_rule'] ? ':' . $r['generation_rule'] : '' ),
                'sort_score' => (float) ( $r['quality_score'] ?? 0 ),
            ];
        }

        // Sort by score descending, then truncate to cap
        usort( $merged, fn( $a, $b ) => $b['sort_score'] <=> $a['sort_score'] );
        return array_slice( $merged, 0, self::QUEUE_CAP );
    }

    // === PANEL 2: ASSIGNMENT QUEUE ==========================================

    private static function render_assign(): void {
        global $wpdb;

        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cand_table ) ) !== $cand_table ) {
            echo '<p>Working keyword table not found.</p>';
            return;
        }

        // Only truly 'approved' keywords are assignable
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.id, c.keyword, c.volume, c.difficulty, c.opportunity, c.intent
             FROM {$cand_table} c
             LEFT JOIN {$wpdb->postmeta} pm ON pm.meta_value = c.keyword AND pm.meta_key = '_tmwseo_keyword'
             WHERE c.status = 'approved' AND pm.meta_id IS NULL
             ORDER BY c.opportunity DESC LIMIT %d",
            self::QUEUE_CAP
        ), ARRAY_A );

        echo '<h2>Assignment Queue</h2>';
        echo '<p>Only <strong>approved</strong> keywords appear here. Ownership is checked at assignment time.</p>';

        if ( empty( $rows ) ) {
            echo '<p style="color:#666;">No unassigned approved keywords. Approve keywords in <em>1. Review</em> first.</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Keyword</th><th>Vol</th><th>KD</th><th>Opp</th><th>Intent</th>';
        echo '<th>Suggested Owner</th><th>Assign</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $r ) {
            $kw  = (string) $r['keyword'];
            $sug = OwnershipEnforcer::suggest_owner( $kw );

            echo '<tr><td><strong>' . esc_html( $kw ) . '</strong></td>';
            echo '<td>' . esc_html( $r['volume'] ?? '-' ) . '</td>';
            echo '<td>' . esc_html( $r['difficulty'] !== null ? round( (float) $r['difficulty'] ) : '-' ) . '</td>';
            echo '<td>' . esc_html( $r['opportunity'] !== null ? round( (float) $r['opportunity'] ) : '-' ) . '</td>';
            echo '<td><small>' . esc_html( $r['intent'] ?? '' ) . '</small></td>';
            echo '<td><small>' . esc_html( ( $sug['page_type'] ?: '?' ) . ' (' . $sug['reason'] . ')' ) . '</small></td>';
            echo '<td>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
            wp_nonce_field( 'tmwseo_cc_nonce' );
            echo '<input type="hidden" name="action" value="tmwseo_command_center_action">';
            echo '<input type="hidden" name="cc_action" value="assign">';
            echo '<input type="hidden" name="keyword" value="' . esc_attr( $kw ) . '">';
            echo '<input type="number" name="target_post_id" value="' . esc_attr( $sug['entity_id'] ) . '" style="width:80px;" min="0"> ';
            echo '<button type="submit" class="button button-small button-primary">Assign</button>';
            echo '</form></td></tr>';
        }

        echo '</tbody></table>';
    }

    // === PANEL 3: HEALTH ====================================================

    private static function render_health(): void {
        global $wpdb;

        $seed_diag   = SeedRegistry::diagnostics();
        $cand_counts = ExpansionCandidateRepository::count_by_status();
        $is_paused   = ContentGenerationGate::is_paused();
        $metrics     = (array) get_option( 'tmw_keyword_engine_metrics', [] );
        $reset_result = get_transient( 'tmwseo_reset_result' );
        $clean_rebuild_result = get_transient( 'tmwseo_clean_rebuild_result' );
        $manual_mode = TrustPolicy::is_manual_only();

        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $lc = [];
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cand_table ) ) === $cand_table ) {
            $rows = $wpdb->get_results( "SELECT status, COUNT(*) AS cnt FROM {$cand_table} GROUP BY status", ARRAY_A );
            if ( is_array( $rows ) ) { foreach ( $rows as $r ) { $lc[ (string) $r['status'] ] = (int) $r['cnt']; } }
        }

        if ( $reset_result ) {
            delete_transient( 'tmwseo_reset_result' );
            echo '<div class="notice notice-info"><p><strong>Reset done.</strong> Seeds: ' . esc_html( $reset_result['model_roots_restored'] ?? 0 ) . ' model roots, ' . esc_html( $reset_result['manual_seeds_restored'] ?? 0 ) . ' manual, ' . esc_html( $reset_result['starter_pack_registered'] ?? 0 ) . ' starter.</p></div>';
        }

        if ( $clean_rebuild_result ) {
            delete_transient( 'tmwseo_clean_rebuild_result' );

            if ( ! empty( $clean_rebuild_result['success'] ) ) {
                $reset = is_array( $clean_rebuild_result['reset'] ?? null ) ? $clean_rebuild_result['reset'] : [];
                echo '<div class="notice notice-warning"><p><strong>Clean Rebuild from Zero complete.</strong> ';
                echo 'Legacy migrated: <strong>' . esc_html( (string) ( $clean_rebuild_result['legacy_new_migrated'] ?? 0 ) ) . '</strong>; ';
                echo 'generator archived: <strong>' . esc_html( (string) ( $clean_rebuild_result['generator_archived'] ?? 0 ) ) . '</strong>; ';
                echo 'queued parked: <strong>' . esc_html( (string) ( $clean_rebuild_result['discovery_parked'] ?? 0 ) ) . '</strong>; ';
                echo 'manual jobs deleted: <strong>' . esc_html( (string) ( $clean_rebuild_result['manual_jobs_deleted'] ?? 0 ) ) . '</strong>; ';
                echo 'model roots restored: <strong>' . esc_html( (string) ( $reset['model_roots_restored'] ?? 0 ) ) . '</strong>; ';
                echo 'manual seeds restored: <strong>' . esc_html( (string) ( $reset['manual_seeds_restored'] ?? 0 ) ) . '</strong>; ';
                echo 'starter pack registered: <strong>' . esc_html( (string) ( $reset['starter_pack_registered'] ?? 0 ) ) . '</strong>; ';
                echo 'first clean cycle: <strong>' . ( ! empty( $clean_rebuild_result['cycle_triggered'] ) ? 'ran' : 'not run' ) . '</strong>. ';
                echo 'Content generation remains paused for manual review.</p></div>';
            } else {
                $errors = (array) ( $clean_rebuild_result['errors'] ?? [] );
                $message = empty( $errors ) ? 'Clean rebuild failed.' : 'Clean rebuild aborted: ' . implode( ', ', array_map( 'esc_html', array_map( 'strval', $errors ) ) ) . '.';
                echo '<div class="notice notice-error"><p><strong>Clean Rebuild from Zero did not run.</strong> ' . $message . '</p></div>';
            }
        }

        echo '<h2>System Health</h2>';

        // -- Lifecycle --
        echo '<h3>Keyword Lifecycle</h3>';
        echo '<table class="widefat" style="max-width:700px;"><tbody>';
        self::hr( 'Seeds (trusted roots)', $seed_diag['total_seeds'] ?? 0 );
        self::hr( 'Discovered (unscored)', $lc['discovered'] ?? 0 );
        self::hr( 'Scored (waiting for queue space)', $lc['scored'] ?? 0, ( $lc['scored'] ?? 0 ) > 50 ? 'i' : '' );
        self::hr( 'Queued for Review', $lc['queued_for_review'] ?? 0, 'g' );
        self::hr( 'Approved (assignable)', $lc['approved'] ?? 0, 'g' );
        self::hr( 'Rejected', $lc['rejected'] ?? 0 );
        if ( ( $lc['new'] ?? 0 ) > 0 ) { self::hr( 'Legacy "new" (pre-v5.1)', $lc['new'], 'w' ); }
        echo '</tbody></table>';

        echo '<h3 style="margin-top:15px;">Generator Pipeline</h3>';
        echo '<table class="widefat" style="max-width:700px;"><tbody>';
        self::hr( 'Pending review', $cand_counts['pending'] ?? 0 );
        self::hr( 'Fast-track', $cand_counts['fast_track'] ?? 0 );
        self::hr( 'Approved (promoted to working keywords)', $cand_counts['approved'] ?? 0 );
        self::hr( 'Rejected', $cand_counts['rejected'] ?? 0 );
        echo '</tbody></table>';

        echo '<h3 style="margin-top:15px;">System</h3>';
        echo '<table class="widefat" style="max-width:700px;"><tbody>';
        self::hr( 'Content Generation', $is_paused ? 'PAUSED' : 'Active', $is_paused ? 'w' : 'g' );
        self::hr( 'Last Cycle', isset( $metrics['last_run'] ) ? date( 'Y-m-d H:i', (int) $metrics['last_run'] ) : 'Never' );
        self::hr( 'Stop Reason', $metrics['last_stop_reason'] ?? 'None', ! empty( $metrics['last_stop_reason'] ) ? 'w' : '' );
        self::hr( 'Combined Queue Cap', self::QUEUE_CAP . ' total (both tracks)' );
        echo '</tbody></table>';

        // -- Actions --
        echo '<h3 style="margin-top:20px;">Actions</h3>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tmwseo_cc_nonce' );
        echo '<input type="hidden" name="action" value="tmwseo_command_center_action">';

        echo '<div style="margin-bottom:12px;padding:10px;background:#f9f9f9;border:1px solid #ddd;">';
        echo '<strong>Add Manual Seed:</strong> <input type="text" name="manual_phrase" placeholder="e.g. adult cam site" style="width:280px;"> ';
        echo '<button type="submit" name="cc_action" value="add_seed" class="button">Add</button>';
        echo '</div>';

        if ( $is_paused ) {
            echo '<div style="margin-bottom:12px;padding:10px;background:#fff3cd;border:1px solid #ffc107;">';
            echo '<strong>Content generation PAUSED.</strong> ';
            echo '<button type="submit" name="cc_action" value="resume" class="button button-primary" onclick="return confirm(\'Resume?\');">Resume</button>';
            echo '</div>';
        }

        echo '<div style="margin-bottom:12px;padding:10px;background:#fff;border:1px solid #dc3545;">';
        echo '<strong>Architecture Reset</strong> <small>(archives tables, rebuilds clean)</small><br>';
        echo '<button type="submit" name="cc_action" value="reset" class="button" style="margin-top:5px;color:#dc3545;" onclick="return confirm(\'Archive and rebuild?\');">Execute Reset</button>';
        echo '</div>';

        echo '<div style="margin-bottom:12px;padding:12px;background:#fff5f5;border:1px solid #b91c1c;border-radius:6px;">';
        echo '<strong>Clean Rebuild from Zero</strong> <small>(staging/debug only)</small><br>';
        echo '<p style="margin:8px 0 6px;color:#7f1d1d;">Staging-only helper. Quiet background jobs, migrate legacy rows, archive old review noise, rebuild trusted seed infrastructure, run one clean discovery cycle, then leave the system paused for human review.</p>';
        echo '<p style="margin:0 0 8px;color:#7f1d1d;"><strong>This does NOT approve, assign, or generate content.</strong> Review remains manual.</p>';
        echo '<p style="margin:0 0 8px;color:#444;">Archives old seed infrastructure, restores trusted roots, migrates legacy rows, runs one clean discovery cycle, and leaves the system paused for review.</p>';
        echo '<p style="margin:0 0 8px;"><label for="tmwseo-clean-rebuild-confirm"><strong>Type <code>CLEAN-REBUILD-ZERO</code> to confirm:</strong></label><br>';
        echo '<input type="text" id="tmwseo-clean-rebuild-confirm" name="clean_rebuild_confirm" value="" placeholder="CLEAN-REBUILD-ZERO" style="margin-top:6px;width:280px;max-width:100%;"></p>';
        echo '<p style="margin:0 0 8px;color:' . ( $manual_mode ? '#166534' : '#b91c1c' ) . ';"><strong>Manual-control-safe context:</strong> ' . ( $manual_mode ? 'TrustPolicy manual-only mode is active.' : 'TrustPolicy manual-only mode is NOT active. Execution will be blocked.' ) . '</p>';
        echo '<button
            type="submit"
            name="cc_action"
            value="clean_rebuild_zero"
            class="button"
            style="margin-top:8px;background:#b91c1c;border-color:#991b1b;color:#fff;display:inline-flex;align-items:center;gap:6px;padding:6px 12px;min-height:36px;font-weight:600;border-radius:4px;"
            onmouseover="this.style.background=\'#991b1b\';this.style.borderColor=\'#7f1d1d\';"
            onmouseout="this.style.background=\'#b91c1c\';this.style.borderColor=\'#991b1b\';"
            onclick="return confirm(\'This will run the full staging clean rebuild sequence. Continue?\');"
        >';
        echo '<span class="dashicons dashicons-warning" aria-hidden="true" style="font-size:16px;line-height:1;"></span>';
        echo '<span>Clean Rebuild from Zero</span>';
        echo '</button>';
        echo '</div>';
        echo '</form>';

        self::render_last_clean_rebuild_panel();

        // -- Lifecycle reference --
        echo '<h3 style="margin-top:25px;">Lifecycle Reference</h3>';
        echo '<div style="background:#f8f9fa;border:1px solid #dee2e6;padding:12px 15px;border-radius:4px;font-size:13px;">';
        echo '<strong>Discovery track:</strong> <code>discovered &rarr; scored &rarr; queued_for_review &rarr; <b>human approve</b> &rarr; approved</code><br>';
        echo '<strong>Generator track:</strong> <code>pending &rarr; <b>human approve</b> &rarr; approved</code> <em>(approval is final, no second review)</em><br>';
        echo '<strong>After approval:</strong> <code>approved &rarr; assigned (ownership-checked) &rarr; content generated &rarr; indexed</code><br><br>';
        echo '<strong>Approved</strong> = assignable to a page. <strong>Scored/Parked</strong> = deferred, promoted when space opens. <strong>Rejected</strong> = terminal.<br>';
        echo '<strong>Only approved + assigned keywords can trigger content generation.</strong>';
        echo '</div>';
    }


    private static function render_last_clean_rebuild_panel(): void {
        $result = StagingCleanRebuild::get_last_result();

        echo '<div style="margin-top:18px;padding:14px;background:#fff;border:1px solid #dcdcde;border-radius:6px;max-width:980px;">';
        echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">';
        echo '<div><h3 style="margin:0 0 4px;">Last Clean Rebuild Run</h3>';
        echo '<p style="margin:0;color:#646970;">Most recent staging reset-and-prime execution for debugging/review preparation.</p></div>';
        echo '<div>' . self::render_clean_rebuild_status_badge( $result ) . '</div>';
        echo '</div>';

        if ( empty( $result ) ) {
            echo '<p style="margin:12px 0 0;color:#646970;">No clean rebuild has been recorded yet.</p>';
            echo '</div>';
            return;
        }

        $timestamp     = self::clean_rebuild_admin_time( (string) ( $result['timestamp'] ?? '' ), (string) ( $result['timestamp_gmt'] ?? '' ) );
        $user_label    = trim( (string) ( $result['user_label'] ?? '' ) );
        $error_messages = array_values( array_filter( array_map( 'strval', (array) ( $result['errors'] ?? [] ) ) ) );
        $summary_rows  = [
            'Legacy new → discovered migrated' => self::clean_rebuild_int( $result, 'legacy_new_migrated' ),
            'Generator rows archived'          => self::clean_rebuild_int( $result, 'generator_archived' ),
            'queued_for_review rows parked'    => self::clean_rebuild_int( $result, 'discovery_parked' ),
            'Pending manual cycle jobs deleted'=> self::clean_rebuild_int( $result, 'manual_jobs_deleted' ),
            'First clean cycle run'            => self::clean_rebuild_bool_label( $result['cycle_triggered'] ?? false ),
            'Content generation paused afterward' => self::clean_rebuild_bool_label( $result['content_generation_paused'] ?? false ),
        ];

        echo '<div style="margin-top:12px;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:8px 18px;">';
        echo '<div><strong>Ran at:</strong> ' . esc_html( $timestamp ) . '</div>';
        echo '<div><strong>Triggered by:</strong> ' . esc_html( $user_label !== '' ? $user_label : 'Unknown' ) . '</div>';
        echo '<div><strong>Status:</strong> ' . esc_html( ! empty( $result['success'] ) ? 'Success' : 'Failed' ) . '</div>';
        echo '</div>';

        echo '<table class="widefat striped" style="margin-top:12px;max-width:780px;"><tbody>';
        foreach ( $summary_rows as $label => $value ) {
            echo '<tr><th style="width:340px;">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
        }
        echo '</tbody></table>';

        $before = is_array( $result['before'] ?? null ) ? $result['before'] : [];
        $after  = is_array( $result['after'] ?? null ) ? $result['after'] : [];
        $count_rows = [
            'Working keywords total'          => [ self::clean_rebuild_count( $before, [ 'working_keywords' ] ), self::clean_rebuild_count( $after, [ 'working_keywords' ] ) ],
            'Queued for review'               => [ self::clean_rebuild_count( $before, [ 'discovery', 'queued_for_review' ] ), self::clean_rebuild_count( $after, [ 'discovery', 'queued_for_review' ] ) ],
            'Approved'                        => [ self::clean_rebuild_count( $before, [ 'discovery', 'approved' ] ), self::clean_rebuild_count( $after, [ 'discovery', 'approved' ] ) ],
            'Assigned pages / post meta count'=> [ self::clean_rebuild_count( $before, [ 'assigned_pages' ] ), self::clean_rebuild_count( $after, [ 'assigned_pages' ] ) ],
            'Trusted seeds total'             => [ self::clean_rebuild_count( $before, [ 'seed_totals', 'total_seeds' ] ), self::clean_rebuild_count( $after, [ 'seed_totals', 'total_seeds' ] ) ],
            'Seed expansion candidates total' => [ self::clean_rebuild_count( $before, [ 'generator', 'total' ] ), self::clean_rebuild_count( $after, [ 'generator', 'total' ] ) ],
            'Raw keyword rows total'          => [ self::clean_rebuild_count( $before, [ 'raw_keywords' ] ), self::clean_rebuild_count( $after, [ 'raw_keywords' ] ) ],
        ];

        echo '<h4 style="margin:16px 0 8px;">Counts snapshot</h4>';
        echo '<table class="widefat striped" style="max-width:780px;"><thead><tr><th>Metric</th><th style="width:120px;">Before</th><th style="width:120px;">After</th></tr></thead><tbody>';
        foreach ( $count_rows as $label => $pair ) {
            echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( (string) $pair[0] ) . '</td><td>' . esc_html( (string) $pair[1] ) . '</td></tr>';
        }
        echo '</tbody></table>';

        $reset = is_array( $result['reset'] ?? null ) ? $result['reset'] : [];
        $reset_rows = [
            'Model roots restored'    => self::clean_rebuild_int( $reset, 'model_roots_restored' ),
            'Manual seeds restored'   => self::clean_rebuild_int( $reset, 'manual_seeds_restored' ),
            'Starter pack registered' => self::clean_rebuild_reset_bool_or_count( $reset, 'starter_pack_registered' ),
            'Archived tables created' => self::clean_rebuild_archived_tables_label( $reset ),
        ];

        echo '<h4 style="margin:16px 0 8px;">Reset details</h4>';
        echo '<ul style="margin:0 0 0 18px;">';
        foreach ( $reset_rows as $label => $value ) {
            echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( (string) $value ) . '</li>';
        }
        echo '</ul>';

        if ( ! empty( $error_messages ) ) {
            echo '<div class="notice notice-error inline" style="margin:14px 0 0;padding:8px 12px;">';
            echo '<p style="margin:0 0 6px;"><strong>Errors</strong></p><ul style="margin:0 0 0 18px;">';
            foreach ( $error_messages as $message ) {
                echo '<li>' . esc_html( $message ) . '</li>';
            }
            echo '</ul></div>';
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px;">';
        wp_nonce_field( 'tmwseo_cc_nonce' );
        echo '<input type="hidden" name="action" value="tmwseo_command_center_action">';
        echo '<button type="submit" name="cc_action" value="clear_clean_rebuild_history" class="button button-secondary">Clear last run record</button>';
        echo '</form>';

        echo '</div>';
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function render_clean_rebuild_status_badge( array $result ): string {
        if ( empty( $result ) ) {
            return '<span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#f0f0f1;color:#50575e;font-weight:600;">No Data</span>';
        }

        if ( ! empty( $result['success'] ) ) {
            return '<span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#dcfce7;color:#166534;font-weight:600;">Success</span>';
        }

        return '<span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#fee2e2;color:#991b1b;font-weight:600;">Failed</span>';
    }

    private static function clean_rebuild_admin_time( string $local_mysql, string $gmt_mysql ): string {
        if ( $local_mysql !== '' ) {
            $timestamp = strtotime( $local_mysql );
            if ( $timestamp ) {
                return wp_date( 'Y-m-d H:i', $timestamp );
            }
            return $local_mysql;
        }

        if ( $gmt_mysql !== '' ) {
            $timestamp = strtotime( $gmt_mysql . ' UTC' );
            if ( $timestamp ) {
                return wp_date( 'Y-m-d H:i', $timestamp );
            }
            return $gmt_mysql;
        }

        return 'Unknown';
    }

    /**
     * @param array<string,mixed> $source
     */
    private static function clean_rebuild_int( array $source, string $key ): int {
        return (int) ( $source[ $key ] ?? 0 );
    }

    private static function clean_rebuild_bool_label( $value ): string {
        return ! empty( $value ) ? 'Yes' : 'No';
    }

    /**
     * @param array<string,mixed> $source
     * @param array<int,string> $path
     */
    private static function clean_rebuild_count( array $source, array $path ): int {
        $value = $source;
        foreach ( $path as $segment ) {
            if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
                return 0;
            }
            $value = $value[ $segment ];
        }

        return (int) $value;
    }

    /**
     * @param array<string,mixed> $reset
     */
    private static function clean_rebuild_reset_bool_or_count( array $reset, string $key ): string {
        $value = $reset[ $key ] ?? 0;
        if ( is_bool( $value ) ) {
            return $value ? 'Yes' : 'No';
        }

        $count = (int) $value;
        return $count > 0 ? 'Yes (' . $count . ')' : 'No';
    }

    /**
     * @param array<string,mixed> $reset
     */
    private static function clean_rebuild_archived_tables_label( array $reset ): string {
        $tables = is_array( $reset['archived_tables'] ?? null ) ? $reset['archived_tables'] : [];
        $count  = count( $tables );

        return $count > 0 ? 'Yes (' . $count . ')' : 'No';
    }

    private static function hr( string $label, $value, string $s = '' ): void {
        $st = match ( $s ) { 'w' => ' style="color:#856404;background:#fff3cd;"', 'g' => ' style="color:#155724;background:#d4edda;"', 'i' => ' style="color:#0c5460;background:#d1ecf1;"', default => '' };
        echo '<tr' . $st . '><th style="width:300px;">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
    }
}
