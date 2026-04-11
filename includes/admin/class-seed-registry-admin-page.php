<?php
/**
 * TMW SEO Engine — Seed Registry Admin Page
 *
 * Provides:
 *  1. Seed Registry overview — trusted roots, source breakdown, add manual seed
 *  2. Expansion Preview queue — review pending/fast_track candidates
 *  3. Import History — batch list with rollback
 *  4. Auto Builders Control Center — kill switches for every generator
 *
 * @package TMWSEO\Engine\Admin
 * @since   4.3.0
 */

namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Keywords\SeedRegistry;
use TMWSEO\Engine\Keywords\ExpansionCandidateRepository;
use TMWSEO\Engine\Keywords\BuilderCandidateService;
use TMWSEO\Engine\Admin\TMWSEORoutes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SeedRegistryAdminPage {

    private const PAGE_SLUG = 'tmwseo-seed-registry';

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_post_tmwseo_seed_registry_action',    [ __CLASS__, 'handle_post_action' ] );
        add_action( 'admin_post_tmwseo_trusted_seeds_export',    [ __CLASS__, 'handle_trusted_seeds_export' ] );
        add_action( 'admin_post_tmwseo_candidates_export',       [ __CLASS__, 'handle_candidates_export' ] );
        add_action( 'admin_post_tmwseo_trusted_seed_delete',     [ __CLASS__, 'handle_trusted_seed_delete' ] );
        add_action( 'admin_post_tmwseo_ts_bulk_action',           [ __CLASS__, 'handle_trusted_seeds_bulk_action' ] );
        add_action( 'admin_init', [ __CLASS__, 'maybe_handle_preview_bulk_action' ] );
    }

    public static function register_menu(): void {
        add_submenu_page(
            'tmwseo-engine',
            __( 'Seed Registry', 'tmwseo' ),
            __( 'Seed Registry', 'tmwseo' ),
            'manage_options',
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    // -------------------------------------------------------------------------
    // POST actions
    // -------------------------------------------------------------------------

    public static function handle_post_action(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden', 'tmwseo' ) );
        }

        check_admin_referer( 'tmwseo_seed_registry_nonce' );

        $action = sanitize_key( $_POST['tmwseo_action'] ?? '' );

        switch ( $action ) {

            case 'add_manual_seed':
                $phrase = sanitize_text_field( wp_unslash( $_POST['manual_phrase'] ?? '' ) );
                if ( $phrase !== '' ) {
                    $added = SeedRegistry::register_trusted_seed( $phrase, 'manual', 'system', 0 );
                    $seed_result = $added ? 'seed_added' : 'seed_duplicate';
                } else {
                    $seed_result = 'seed_empty';
                }
                break;

            case 'approve_candidate':
                $id = (int) ( $_POST['candidate_id'] ?? 0 );
                if ( $id > 0 ) {
                    ExpansionCandidateRepository::approve_candidate( $id, get_current_user_id() );
                }
                break;

            case 'reject_candidate':
                $id     = (int) ( $_POST['candidate_id'] ?? 0 );
                $reason = sanitize_text_field( wp_unslash( $_POST['rejection_reason'] ?? '' ) );
                if ( $id > 0 ) {
                    ExpansionCandidateRepository::reject_candidate( $id, $reason, get_current_user_id() );
                }
                break;

            case 'archive_candidate':
                $id = (int) ( $_POST['candidate_id'] ?? 0 );
                if ( $id > 0 ) {
                    ExpansionCandidateRepository::archive_candidate( $id, get_current_user_id() );
                }
                break;

            case 'approve_batch':
                $batch_id = sanitize_key( $_POST['batch_id'] ?? '' );
                if ( $batch_id !== '' ) {
                    ExpansionCandidateRepository::approve_batch( $batch_id, get_current_user_id() );
                }
                break;

            case 'rollback_batch':
                $batch_id = sanitize_key( $_POST['batch_id'] ?? '' );
                if ( $batch_id !== '' ) {
                    ExpansionCandidateRepository::rollback_batch( $batch_id );
                }
                break;

            // ── Niche Pattern Family Builder — manual preview seeding ─────────
            // Routes builder-generated phrases to preview/candidate queue ONLY.
            // NEVER touches tmwseo_seeds / trusted roots.

            case 'run_builder_preview':
                $raw_cat  = sanitize_title( wp_unslash( $_POST['builder_category'] ?? '' ) );
                $limit    = max( 1, min( 100, (int) ( $_POST['builder_limit'] ?? 50 ) ) );

                if ( $raw_cat === '__all__' || $raw_cat === '' ) {
                    // Bounded all-categories run
                    $max_cats = max( 1, min( 20, (int) ( $_POST['builder_max_categories'] ?? 5 ) ) );
                    $builder_result = \TMWSEO\Engine\Keywords\BuilderCandidateService::run_builder_candidate_bootstrap(
                        [],
                        [
                            'per_category_limit' => $limit,
                            'max_categories'     => $max_cats,
                        ]
                    );
                    $builder_mode = 'all';
                } else {
                    // Single category run
                    $cat_result = \TMWSEO\Engine\Keywords\BuilderCandidateService::insert_builder_candidates_for_category(
                        $raw_cat,
                        $limit
                    );
                    $builder_result = [
                        'categories_processed'          => ( $cat_result['error'] === '' ) ? 1 : 0,
                        'categories_skipped_queue_full' => $cat_result['queue_was_full'] ? 1 : 0,
                        'phrases_generated'             => $cat_result['phrases_generated'],
                        'filtered_out_as_junk'          => $cat_result['filtered_out_as_junk'] ?? 0,
                        'filtered_out_by_validator'     => $cat_result['filtered_out_by_validator'] ?? 0,
                        'inserted'                      => $cat_result['inserted'],
                        'skipped_duplicates'            => $cat_result['skipped'],
                        'filtered_no_family'            => ( $cat_result['error'] === 'no_pattern_family' ) ? 1 : 0,
                        'batch_ids'                     => $cat_result['batch_id'] !== '' ? [ $cat_result['batch_id'] ] : [],
                        'queue_full_abort'              => $cat_result['queue_was_full'],
                    ];
                    $builder_mode = 'single';
                }

                update_option( 'tmwseo_last_builder_preview_run', [
                    'timestamp' => current_time( 'mysql' ),
                    'mode'      => $builder_mode,
                    'result'    => $builder_result,
                ], false );

                $redirect_args = [
                    'page'           => self::PAGE_SLUG,
                    'tab'            => 'builders',
                    'builder_result' => 'done',
                    'inserted'       => (int) ( $builder_result['inserted'] ?? 0 ),
                    'generated'      => (int) ( $builder_result['phrases_generated'] ?? 0 ),
                    'processed'      => (int) ( $builder_result['categories_processed'] ?? 0 ),
                    'filtered'       => (int) ( ( $builder_result['filtered_out_as_junk'] ?? 0 ) + ( $builder_result['filtered_out_by_validator'] ?? 0 ) ),
                    'qfull'          => (int) ( $builder_result['queue_full_abort'] ?? false ),
                ];
                wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
                exit;


            case 'save_builder_switches':
                $switches = [
                    'tmwseo_builder_tag_modifier_expander_enabled',
                    'tmwseo_builder_entity_combo_enabled',
                    'tmwseo_builder_content_miner_enabled',
                    'tmwseo_builder_model_phrases_enabled',
                    'tmwseo_builder_tag_phrases_enabled',
                    'tmwseo_builder_video_phrases_enabled',
                    'tmwseo_builder_category_phrases_enabled',
                    'tmwseo_builder_competitor_phrases_enabled',
                ];
                foreach ( $switches as $opt ) {
                    update_option( $opt, isset( $_POST[ $opt ] ) ? 1 : 0, false );
                }
                break;

            // ── Reset & Starter Pack actions ─────────────────────────────────

            case 'purge_seed_sources':
                $sources_to_purge = array_map( 'sanitize_key', (array) ( $_POST['purge_sources'] ?? [] ) );
                if ( ! empty( $sources_to_purge ) ) {
                    $purged = SeedRegistry::purge_by_sources( $sources_to_purge );
                    $reset_result  = 'purged';
                    $reset_extras  = [
                        'purged_count'   => $purged,
                        'purged_sources' => implode( ', ', $sources_to_purge ),
                    ];
                } else {
                    $reset_result = 'purge_empty';
                }
                break;

            case 'save_static_curated_block':
                $block = isset( $_POST['tmwseo_block_static_curated'] ) ? 1 : 0;
                update_option( 'tmwseo_block_static_curated', $block, false );
                $reset_result = 'block_saved';
                break;

            case 'install_starter_pack':
                $pack   = SeedRegistry::get_starter_pack();
                $result = SeedRegistry::register_many(
                    $pack,
                    'manual',
                    'system',
                    0,
                    'general',
                    1
                );
                SeedRegistry::tag_starter_pack_provenance( $pack );
                $reset_result = 'starter_installed';
                $reset_extras = [
                    'starter_new' => (int) ( $result['registered'] ?? 0 ),
                    'starter_dup' => (int) ( $result['deduplicated'] ?? 0 ),
                ];
                \TMWSEO\Engine\Logs::info( 'seed_reset', '[TMW-SEED] Starter pack installed', [
                    'registered'   => (int) ( $result['registered'] ?? 0 ),
                    'deduplicated' => (int) ( $result['deduplicated'] ?? 0 ),
                    'user'         => get_current_user_id(),
                ] );
                break;
        }

        // ── Build redirect URL ─────────────────────────────────────────────
        $redirect_args = [ 'page' => self::PAGE_SLUG, 'updated' => '1' ];
        if ( isset( $seed_result ) ) {
            $redirect_args['seed_result'] = $seed_result;
        }
        if ( isset( $reset_result ) ) {
            $redirect_args['tab']          = 'reset';
            $redirect_args['reset_result'] = $reset_result;
            if ( ! empty( $reset_extras ) ) {
                $redirect_args = array_merge( $redirect_args, $reset_extras );
            }
        }

        wp_safe_redirect( add_query_arg(
            $redirect_args,
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------------

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tab = sanitize_key( $_GET['tab'] ?? 'registry' );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Seed Registry', 'tmwseo' ) . '</h1>';

        if ( ! empty( $_GET['updated'] ) ) {
            $seed_result = sanitize_key( $_GET['seed_result'] ?? '' );
            if ( $seed_result === 'seed_added' ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Seed added successfully.', 'tmwseo' ) . '</p></div>';
            } elseif ( $seed_result === 'seed_duplicate' ) {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Seed already exists (duplicate hash). Total unchanged.', 'tmwseo' ) . '</p></div>';
            } elseif ( $seed_result === 'seed_empty' ) {
                echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'No phrase provided.', 'tmwseo' ) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'tmwseo' ) . '</p></div>';
            }
        }

        // Reset & Starter Pack notices
        $reset_result = sanitize_key( $_GET['reset_result'] ?? '' );
        if ( $reset_result === 'purged' ) {
            $purged_count   = (int) ( $_GET['purged_count'] ?? 0 );
            $purged_sources = sanitize_text_field( wp_unslash( $_GET['purged_sources'] ?? '' ) );
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html( sprintf( __( 'Purged %d seed rows from: %s.', 'tmwseo' ), $purged_count, $purged_sources ) )
                . '</p></div>';
        } elseif ( $reset_result === 'purge_empty' ) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'No sources selected for purge.', 'tmwseo' ) . '</p></div>';
        } elseif ( $reset_result === 'block_saved' ) {
            $block_state = (int) get_option( 'tmwseo_block_static_curated', 0 );
            $msg = $block_state
                ? __( 'Static curated seed re-registration is now BLOCKED.', 'tmwseo' )
                : __( 'Static curated seed re-registration is now ALLOWED.', 'tmwseo' );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
        } elseif ( $reset_result === 'starter_installed' ) {
            $installed = (int) ( $_GET['starter_new'] ?? 0 );
            $skipped   = (int) ( $_GET['starter_dup'] ?? 0 );
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html( sprintf( __( 'Starter pack: %d seeds installed, %d duplicates skipped.', 'tmwseo' ), $installed, $skipped ) )
                . '</p></div>';
        }

        // Trusted Seeds Explorer notices (single-delete + bulk)
        $ts_notice = sanitize_key( $_GET['tmwseo_notice'] ?? '' );
        if ( $ts_notice === 'seed_deleted' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Seed deleted.', 'tmwseo' ) . '</p></div>';
        } elseif ( $ts_notice === 'seed_not_found' ) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Seed not found (may have already been deleted).', 'tmwseo' ) . '</p></div>';
        } elseif ( $ts_notice === 'bulk_deleted' ) {
            $n = (int) ( $_GET['deleted_count'] ?? 0 );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d seed(s) deleted.', 'tmwseo' ), $n ) ) . '</p></div>';
        } elseif ( $ts_notice === 'bulk_nothing' ) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'No seeds were selected.', 'tmwseo' ) . '</p></div>';
        }

        $preview_notice = sanitize_key( $_GET['preview_notice'] ?? '' );
        $preview_count  = (int) ( $_GET['preview_count'] ?? 0 );
        if ( $preview_notice === 'bulk_approved' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d candidate(s) approved.', 'tmwseo' ), $preview_count ) ) . '</p></div>';
        } elseif ( $preview_notice === 'bulk_rejected' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%d candidate(s) rejected.', 'tmwseo' ), $preview_count ) ) . '</p></div>';
        } elseif ( $preview_notice === 'bulk_nothing' ) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'No candidates were selected.', 'tmwseo' ) . '</p></div>';
        }

                // Tab nav
        $tabs = [
            'trusted_seeds' => __( '🌱 Trusted Seeds', 'tmwseo' ),
            'candidates'    => __( '🔍 Candidates', 'tmwseo' ),
            'registry'      => __( '📊 Overview', 'tmwseo' ),
            'preview'       => __( 'Expansion Preview', 'tmwseo' ),
            'history'       => __( 'Import History', 'tmwseo' ),
            'builders'      => __( 'Auto Builders', 'tmwseo' ),
            'reset'         => __( 'Reset & Starter Pack', 'tmwseo' ),
        ];

        echo '<nav class="nav-tab-wrapper">';
        foreach ( $tabs as $slug => $label ) {
            $class = ( $tab === $slug ) ? 'nav-tab nav-tab-active' : 'nav-tab';
            $url   = add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => $slug ], admin_url( 'admin.php' ) );
            printf(
                '<a href="%s" class="%s">%s</a>',
                esc_url( $url ),
                esc_attr( $class ),
                esc_html( $label )
            );
        }
        echo '</nav>';
        echo '<div style="margin-top:16px;">';

        switch ( $tab ) {
            case 'trusted_seeds':
                self::render_tab_trusted_seeds();
                break;
            case 'candidates':
                self::render_tab_candidates();
                break;
            case 'preview':
                self::render_tab_preview();
                break;
            case 'history':
                self::render_tab_history();
                break;
            case 'builders':
                self::render_tab_builders();
                break;
            case 'reset':
                self::render_tab_reset();
                break;
            default:
                self::render_tab_registry();
                break;
        }

        echo '</div></div>';
    }

    /**
     * admin_init gate — only proceeds when our routing field is present.
     * Replaces the former admin_post_tmwseo_seed_preview_bulk_action hook.
     * Posting to admin.php avoids admin-post.php JS routing that was clearing
     * the action field before the request reached the server.
     */
    public static function maybe_handle_preview_bulk_action(): void {
        if ( empty( $_POST['tmwseo_preview_bulk'] ) ) {
            return;
        }
        self::handle_preview_bulk_action();
    }

    public static function handle_preview_bulk_action(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Forbidden', 'tmwseo' ) );
        }

        check_admin_referer( 'tmwseo_preview_bulk_action', 'tmwseo_pba_nonce' );

        $action = sanitize_key( $_POST['preview_bulk_action'] ?? '' );
        if ( $action === '' || $action === '-1' ) {
            $action = sanitize_key( $_POST['preview_bulk_action_bottom'] ?? '' );
        }

        if ( ! in_array( $action, [ 'approve_candidate', 'reject_candidate' ], true ) ) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'page' => self::PAGE_SLUG,
                        'tab'  => 'preview',
                    ],
                    admin_url( 'admin.php' )
                )
            );
            exit;
        }

        $raw_ids = [];
        if ( isset( $_POST['candidate_ids'] ) ) {
            $raw_ids = (array) wp_unslash( $_POST['candidate_ids'] );
        } elseif ( isset( $_GET['candidate_ids'] ) ) {
            $raw_ids = (array) wp_unslash( $_GET['candidate_ids'] );
        }

        $candidate_ids = array_values( array_filter( array_map( 'absint', $raw_ids ) ) );
        $processed     = 0;
        foreach ( $candidate_ids as $candidate_id ) {
            if ( $action === 'approve_candidate' ) {
                ExpansionCandidateRepository::approve_candidate( $candidate_id, get_current_user_id() );
            } else {
                ExpansionCandidateRepository::reject_candidate( $candidate_id, '', get_current_user_id() );
            }
            $processed++;
        }

        $redirect_args = [
            'page'           => self::PAGE_SLUG,
            'tab'            => 'preview',
            'preview_notice' => ( $processed > 0 )
                ? ( $action === 'approve_candidate' ? 'bulk_approved' : 'bulk_rejected' )
                : 'bulk_nothing',
            'preview_count'  => $processed,
        ];

        $status = sanitize_key( $_POST['status'] ?? '' );
        if ( $status === '' ) {
            $status = sanitize_key( $_GET['status'] ?? '' );
        }
        if ( $status !== '' ) {
            $redirect_args['status'] = $status;
        }

        $search = sanitize_text_field( wp_unslash( $_POST['s'] ?? '' ) );
        if ( $search === '' ) {
            $search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
        }
        if ( $search !== '' ) {
            $redirect_args['s'] = $search;
        }

        $redirect_url = remove_query_arg(
            [ 'action', 'action2', '_wpnonce', '_wp_http_referer', 'candidate_ids' ],
            add_query_arg( $redirect_args, admin_url( 'admin.php' ) )
        );
        wp_safe_redirect( $redirect_url );
        exit;
    }

    // -------------------------------------------------------------------------
    // Tab: Registry
    // -------------------------------------------------------------------------

    private static function render_tab_registry(): void {
        $diag = SeedRegistry::diagnostics();

        // ── Discovery Governor status (critical for diagnosing no_seeds) ────
        $gov_enabled = ! empty( $diag['discovery_enabled'] );
        if ( ! $gov_enabled ) {
            echo '<div class="notice notice-error" style="margin:0 0 16px;"><p><strong>'
                . esc_html__( '⛔ Discovery is DISABLED', 'tmwseo' )
                . '</strong> — '
                . esc_html__( 'The Discovery Governor kill switch (tmw_discovery_enabled) is set to 0. No seeds will be used for keyword discovery until this is re-enabled. Check Settings or Debug Dashboard.', 'tmwseo' )
                . '</p></div>';
        }

        // Shared card CSS
        echo '<style>
.tmw-ov-link-cell { color:inherit; text-decoration:none; display:block; }
.tmw-ov-link-cell:hover, .tmw-ov-link-cell:focus { text-decoration:underline; color:#2271b1; }
.tmw-ov-link-cell:focus { outline:2px solid #2271b1; outline-offset:1px; }
.tmw-src-badge { display:inline-block;border-radius:3px;font-size:11px;font-weight:700;padding:2px 7px; }
</style>';

        echo '<h2>' . esc_html__( 'Trusted Root Seeds', 'tmwseo' ) . '</h2>';
        echo '<table class="widefat striped">';

        // Total trusted seeds — link to trusted_seeds tab
        echo '<tr><th>' . esc_html__( 'Total trusted seeds', 'tmwseo' ) . '</th>';
        echo '<td><a href="' . TMWSEORoutes::trusted_seeds() . '" class="tmw-ov-link-cell">'
            . '<strong>' . (int) $diag['total_seeds'] . '</strong>'
            . ' <small style="color:#6b7280;">→ View all</small></a></td></tr>';

        echo '<tr><th>' . esc_html__( 'Discovery enabled', 'tmwseo' ) . '</th><td>'
            . ( $gov_enabled
                ? '<span style="color:#2ecc71;font-weight:bold">✓ Yes</span>'
                : '<span style="color:#e74c3c;font-weight:bold">✗ DISABLED — seeds will not be used</span>' )
            . '</td></tr>';

        // Used this discovery cycle — plain informational, no destination page
        echo '<tr><th>' . esc_html__( 'Used this discovery cycle', 'tmwseo' ) . '</th>'
            . '<td>' . (int) $diag['seeds_used_this_cycle'] . '</td></tr>';

        // Duplicates prevented — plain informational
        echo '<tr><th>' . esc_html__( 'Duplicates prevented (lifetime)', 'tmwseo' ) . '</th>'
            . '<td>' . (int) $diag['duplicate_prevention_count'] . '</td></tr>';

        echo '</table>';

        // ── Seeds by source — link each count to the filtered trusted seeds tab ─
        echo '<h3 style="margin-top:24px">' . esc_html__( 'Seeds by source', 'tmwseo' ) . '</h3>';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th>' . esc_html__( 'Source', 'tmwseo' ) . '</th>'
            . '<th>' . esc_html__( 'Count', 'tmwseo' ) . '</th>'
            . '<th>' . esc_html__( 'Trusted?', 'tmwseo' ) . '</th>'
            . '<th>' . esc_html__( 'Note', 'tmwseo' ) . '</th>'
            . '</tr></thead><tbody>';

        foreach ( (array) $diag['seed_sources'] as $source => $count ) {
            $trusted = SeedRegistry::is_trusted_source( $source )
                ? '<span style="color:#2ecc71;font-weight:bold">✓ trusted</span>'
                : '<span style="color:#e74c3c;font-weight:bold">✗ NOT trusted (legacy rows)</span>';

            // Stale copy removed; updated to reference Linked Seeds explorer
            $note = '';
            if ( $source === 'csv_import' ) {
                $note = '<small style="color:#6b7280;">Legacy alias for approved_import. CSV-imported rows from older plugin versions.</small>';
            } elseif ( $source === 'approved_import' ) {
                $csv_link = TMWSEORoutes::csv_linked_seeds( '', '', 'approved_import' );
                $note = '<small style="color:#6b7280;">Current CSV/API import source. '
                    . '<a href="' . $csv_link . '">Browse in Linked Seeds explorer →</a></small>';
            }

            // Count cell links to trusted seeds tab filtered by this source
            $count_url = TMWSEORoutes::trusted_seeds( $source );

            printf(
                '<tr><td><code>%s</code></td>'
                . '<td><a href="%s" style="font-weight:700;color:#1e40af;text-decoration:none;" title="View seeds from this source">%d →</a></td>'
                . '<td>%s</td><td>%s</td></tr>',
                esc_html( $source ),
                esc_url( $count_url ),
                (int) $count,
                $trusted,
                wp_kses_post( $note )
            );
        }
        echo '</tbody></table>';

        // ── Import batches breakdown ─────────────────────────────────────
        self::render_import_batches_section();

        // ── Preview Queue Summary — link each status row to preview tab ──
        if ( ! empty( $diag['preview_queue'] ) ) {
            echo '<h3 style="margin-top:24px">' . esc_html__( 'Preview Queue Summary', 'tmwseo' ) . '</h3>';
            echo '<table class="widefat striped"><thead><tr>'
                . '<th>' . esc_html__( 'Status', 'tmwseo' ) . '</th>'
                . '<th>' . esc_html__( 'Count', 'tmwseo' ) . '</th>'
                . '<th>' . esc_html__( 'Action', 'tmwseo' ) . '</th>'
                . '</tr></thead><tbody>';
            foreach ( $diag['preview_queue'] as $status => $count ) {
                $preview_url = TMWSEORoutes::preview_queue( $status );
                printf(
                    '<tr><td>%s</td>'
                    . '<td><strong>%d</strong></td>'
                    . '<td><a href="%s" class="button button-small">Review %s →</a></td></tr>',
                    esc_html( $status ),
                    (int) $count,
                    esc_url( $preview_url ),
                    esc_html( $status )
                );
            }
            echo '</tbody></table>';
        }

        // Add manual seed form
        echo '<h3 style="margin-top:24px">' . esc_html__( 'Add Manual Trusted Seed', 'tmwseo' ) . '</h3>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tmwseo_seed_registry_nonce' );
        echo '<input type="hidden" name="action" value="tmwseo_seed_registry_action">';
        echo '<input type="hidden" name="tmwseo_action" value="add_manual_seed">';
        echo '<input type="text" name="manual_phrase" class="regular-text" placeholder="' . esc_attr__( 'Enter a trusted root phrase', 'tmwseo' ) . '" required>';
        echo '&nbsp;<input type="submit" class="button button-primary" value="' . esc_attr__( 'Add Seed', 'tmwseo' ) . '">';
        echo '<p class="description">' . esc_html__( 'Only add clean root phrases here. Generated or expanded phrases belong in the preview queue.', 'tmwseo' ) . '</p>';
        echo '</form>';
    }

    /**
     * Render a breakdown of import batches for imported seeds (approved_import + csv_import).
     * Each row now has View Seeds, Export, and Delete actions linking into CSV Manager.
     */
    private static function render_import_batches_section(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'tmwseo_seeds';
        $table_exists = ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
        if ( ! $table_exists ) {
            return;
        }

        // Check whether provenance columns exist (added in 4.3.0 migration).
        $columns    = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
        $col_names  = is_array( $columns ) ? array_column( $columns, 'Field' ) : [];
        $has_provenance = in_array( 'import_batch_id', $col_names, true );

        if ( $has_provenance ) {
            $batches = $wpdb->get_results(
                "SELECT
                    COALESCE(import_batch_id, '') AS batch_id,
                    COALESCE(import_source_label, '') AS source_label,
                    source,
                    COUNT(*) AS row_count,
                    MIN(created_at) AS earliest,
                    MAX(created_at) AS latest
                 FROM {$table}
                 WHERE source IN ('approved_import','csv_import')
                 GROUP BY COALESCE(import_batch_id, ''), COALESCE(import_source_label, ''), source
                 ORDER BY latest DESC",
                ARRAY_A
            );
        } else {
            $batches = $wpdb->get_results(
                "SELECT
                    '' AS batch_id,
                    '' AS source_label,
                    source,
                    COUNT(*) AS row_count,
                    MIN(created_at) AS earliest,
                    MAX(created_at) AS latest
                 FROM {$table}
                 WHERE source IN ('approved_import','csv_import')
                 GROUP BY source
                 ORDER BY latest DESC",
                ARRAY_A
            );
        }

        $import_total = 0;
        if ( is_array( $batches ) ) {
            foreach ( $batches as $b ) {
                $import_total += (int) $b['row_count'];
            }
        }

        echo '<h3 style="margin-top:24px">' . esc_html__( 'Imported Seed Batches', 'tmwseo' ) . '</h3>';

        // Updated description — no stale "DB Import History" copy
        echo '<p class="description">'
            . esc_html( sprintf(
                __( '%d total imported seeds (approved_import + csv_import).', 'tmwseo' ),
                $import_total
            ) )
            . ' <a href="' . TMWSEORoutes::csv_packs() . '">'
            . esc_html__( 'Manage packs in CSV Manager →', 'tmwseo' )
            . '</a>'
            . '</p>';

        if ( ! $has_provenance ) {
            echo '<div class="notice notice-warning inline" style="margin:0 0 12px;"><p>'
                . '<strong>' . esc_html__( 'Schema note:', 'tmwseo' ) . '</strong> '
                . esc_html__( 'Provenance columns (import_batch_id, import_source_label) are missing — deactivate and reactivate the plugin to run the 4.3.0 migration. Batch-level actions are unavailable until then; source-level grouping is shown instead.', 'tmwseo' )
                . '</p></div>';
        }

        if ( empty( $batches ) ) {
            echo '<p>' . esc_html__( 'No imported seed batches found.', 'tmwseo' ) . '</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        foreach ( [ 'Batch ID', 'Source Label', 'Source Type', 'Rows', 'Date Range', 'Actions' ] as $h ) {
            echo '<th>' . esc_html( $h ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( $batches as $b ) {
            $bid          = (string) $b['batch_id'];
            $source_label = (string) $b['source_label'];
            $source       = (string) $b['source'];
            $row_count    = (int) $b['row_count'];

            // Build action URLs (all lead into CSV Manager)
            $view_url = TMWSEORoutes::csv_linked_seeds( $bid, $source_label, $source );
            $export_url = wp_nonce_url(
                add_query_arg( [
                    'action'       => 'tmw_csv_linked_seeds_export',
                    'batch_id'     => rawurlencode( $bid ),
                    'source_label' => rawurlencode( $source_label ),
                    'source'       => rawurlencode( $source ),
                ], admin_url( 'admin-post.php' ) ),
                'tmw_linked_seeds_export'
            );
            $delete_url = wp_nonce_url(
                add_query_arg( [
                    'action'       => 'tmw_csv_linked_seeds_delete_all',
                    'batch_id'     => rawurlencode( $bid ),
                    'source_label' => rawurlencode( $source_label ),
                    'source'       => rawurlencode( $source ),
                ], admin_url( 'admin-post.php' ) ),
                'tmw_linked_seeds_delete_all'
            );

            echo '<tr>';
            echo '<td>' . ( $bid !== '' ? '<code>' . esc_html( $bid ) . '</code>' : '<em style="color:#9ca3af;">legacy (no batch ID)</em>' ) . '</td>';
            echo '<td>' . esc_html( $source_label ?: '—' ) . '</td>';
            echo '<td><code>' . esc_html( $source ) . '</code></td>';
            echo '<td><strong>' . $row_count . '</strong></td>';
            echo '<td><small>' . esc_html( (string) $b['earliest'] ) . ' — ' . esc_html( (string) $b['latest'] ) . '</small></td>';

            // Actions cell
            echo '<td><div style="display:flex;gap:4px;flex-wrap:wrap;">';
            echo '<a class="button button-small" style="background:#dbeafe;color:#1e40af;border-color:#93c5fd;" href="' . esc_url( $view_url ) . '">'
                . esc_html( sprintf( __( 'View %d Seeds', 'tmwseo' ), $row_count ) ) . '</a>';

            if ( in_array( $source, [ 'approved_import', 'csv_import' ], true ) ) {
                echo '<a class="button button-small" href="' . esc_url( $export_url ) . '">'
                    . esc_html__( 'Export', 'tmwseo' ) . '</a>';

                if ( $row_count > 0 ) {
                    echo '<a class="button button-small" style="color:#b91c1c;border-color:#fca5a5;" href="' . esc_url( $delete_url ) . '"'
                        . ' onclick="return confirm(\'' . esc_js( sprintf(
                            __( 'Delete all %d seed rows in this batch? This cannot be undone.', 'tmwseo' ),
                            $row_count
                        ) ) . '\');">'
                        . esc_html__( 'Delete', 'tmwseo' ) . '</a>';
                }
            }

            echo '</div></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Link to CSV Manager packs tab for full pack management
        echo '<p style="margin-top:8px;">'
            . '<a href="' . TMWSEORoutes::csv_packs() . '" class="button button-small">'
            . esc_html__( 'Manage in CSV Manager →', 'tmwseo' )
            . '</a>'
            . '</p>';
    }

    // -------------------------------------------------------------------------
    // Tab: Expansion Preview
    // -------------------------------------------------------------------------

    private static function render_tab_preview(): void {
        $status_filter = sanitize_key( $_GET['status'] ?? '' );
        $counts = ExpansionCandidateRepository::count_by_status();

        echo '<h2>' . esc_html__( 'Expansion Preview Queue', 'tmwseo' ) . '</h2>';

        $filter_labels = [
            ''            => __( 'Needs Review', 'tmwseo' ),
            'pending'     => __( 'Pending', 'tmwseo' ),
            'fast_track'  => __( 'Fast Track (GSC)', 'tmwseo' ),
            'approved'    => __( 'Approved', 'tmwseo' ),
            'rejected'    => __( 'Rejected', 'tmwseo' ),
            'archived'    => __( 'Archived', 'tmwseo' ),
        ];
        $base_url = add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'preview' ], admin_url( 'admin.php' ) );
        echo '<div style="margin-bottom:12px;">';
        foreach ( $filter_labels as $s => $l ) {
            $count_label = '';
            if ( $s === '' ) {
                $count_label = ' (' . ( ( $counts['pending'] ?? 0 ) + ( $counts['fast_track'] ?? 0 ) ) . ')';
            } elseif ( isset( $counts[ $s ] ) ) {
                $count_label = ' (' . (int) $counts[ $s ] . ')';
            }
            $url   = add_query_arg( 'status', $s, $base_url );
            $style = ( $status_filter === $s ) ? 'font-weight:bold;text-decoration:underline;' : '';
            printf( '<a href="%s" style="%s">%s%s</a>&nbsp;&nbsp;', esc_url( $url ), esc_attr( $style ), esc_html( $l ), esc_html( $count_label ) );
        }
        echo '</div>';

        $table = new \TMWSEO\Engine\Admin\Tables\SeedRegistryTable( $status_filter );
        $table->prepare_items();

        echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" id="tmwseo-preview-bulk-form">';
        echo '<input type="hidden" name="tmwseo_preview_bulk" value="1">';
        wp_nonce_field( 'tmwseo_preview_bulk_action', 'tmwseo_pba_nonce' );
        echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
        echo '<input type="hidden" name="tab" value="preview">';
        if ( $status_filter !== '' ) {
            echo '<input type="hidden" name="status" value="' . esc_attr( $status_filter ) . '">';
        }
        $search_term = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
        if ( $search_term !== '' ) {
            echo '<input type="hidden" name="s" value="' . esc_attr( $search_term ) . '">';
        }
        $table->search_box( __( 'Search Keywords / Clusters', 'tmwseo' ), 'seed-preview-search' );
        $table->display();
        echo '</form>';
    }

    // -------------------------------------------------------------------------
    // Tab: Import History
    // -------------------------------------------------------------------------

    private static function render_tab_history(): void {
        $batches = ExpansionCandidateRepository::get_recent_batches( 30 );

        echo '<h2>' . esc_html__( 'Expansion Batch History', 'tmwseo' ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'Each row is one generator run. Rollback deletes all candidates in that batch (including approved ones from the preview table — promoted working keywords are unaffected).', 'tmwseo' ) . '</p>';

        if ( empty( $batches ) ) {
            echo '<p>' . esc_html__( 'No batches yet.', 'tmwseo' ) . '</p>';
            return;
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tmwseo_seed_registry_nonce' );
        echo '<input type="hidden" name="action" value="tmwseo_seed_registry_action">';
        echo '<input type="hidden" name="tmwseo_action" id="tmwseo_history_action" value="">';
        echo '<input type="hidden" name="batch_id" id="tmwseo_history_batch_id" value="">';

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        foreach ( [ 'Batch ID', 'Source', 'Rule', 'Started', 'Total', 'Pending', 'Fast Track', 'Approved', 'Rejected', 'Actions' ] as $h ) {
            echo '<th>' . esc_html__( $h, 'tmwseo' ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( $batches as $b ) {
            $batch_id = (string) $b['batch_id'];
            echo '<tr>';
            printf( '<td><code>%s</code></td>', esc_html( $batch_id ) );
            printf( '<td>%s</td>', esc_html( (string) $b['source'] ) );
            printf( '<td><small>%s</small></td>', esc_html( (string) $b['generation_rule'] ) );
            printf( '<td><small>%s</small></td>', esc_html( (string) $b['started_at'] ) );
            printf( '<td>%d</td>', (int) $b['total'] );
            printf( '<td>%d</td>', (int) $b['pending'] );
            printf( '<td>%d</td>', (int) $b['fast_track'] );
            printf( '<td>%d</td>', (int) $b['approved'] );
            printf( '<td>%d</td>', (int) $b['rejected'] );
            echo '<td>';
            printf(
                '<button type="submit" class="button button-small button-primary" onclick="document.getElementById(\'tmwseo_history_action\').value=\'approve_batch\';document.getElementById(\'tmwseo_history_batch_id\').value=\'%s\';">%s</button> ',
                esc_attr( $batch_id ),
                esc_html__( 'Approve All', 'tmwseo' )
            );
            printf(
                '<button type="submit" class="button button-small" onclick="document.getElementById(\'tmwseo_history_action\').value=\'rollback_batch\';document.getElementById(\'tmwseo_history_batch_id\').value=\'%s\';return confirm(\'%s\');">%s</button>',
                esc_attr( $batch_id ),
                esc_attr__( 'Roll back this entire batch?', 'tmwseo' ),
                esc_html__( 'Rollback', 'tmwseo' )
            );
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></form>';
    }

    // -------------------------------------------------------------------------
    // Tab: Auto Builders Control Center
    // -------------------------------------------------------------------------

    private static function render_tab_builders(): void {
        $builders = [
            'tmwseo_builder_tag_modifier_expander_enabled'  => [
                'label'       => __( 'Tag Modifier Expander (cron, weekly)', 'tmwseo' ),
                'description' => __( 'Generates [tag] × [modifier] phrase combinations.', 'tmwseo' ),
                'last_run'    => get_option( 'tmwseo_last_tag_modifier_expander_report' ),
            ],
            'tmwseo_builder_entity_combo_enabled'           => [
                'label'       => __( 'Entity Combination Engine (cron, weekly)', 'tmwseo' ),
                'description' => __( 'Generates [model] × [tag] × [modifier] combinations.', 'tmwseo' ),
                'last_run'    => get_option( 'tmwseo_last_entity_combination_expansion' ),
            ],
            'tmwseo_builder_content_miner_enabled'          => [
                'label'       => __( 'Content Keyword Miner (cron, weekly)', 'tmwseo' ),
                'description' => __( 'Mines phrases from published post titles, H1s, tags.', 'tmwseo' ),
                'last_run'    => get_option( 'tmwseo_last_content_keyword_miner_report' ),
            ],
            'tmwseo_builder_model_phrases_enabled'          => [
                'label'       => __( 'Model Phrase Builder (on-demand)', 'tmwseo' ),
                'description' => __( 'Generates [model name] × [modifier] phrases.', 'tmwseo' ),
                'last_run'    => null,
            ],
            'tmwseo_builder_tag_phrases_enabled'            => [
                'label'       => __( 'Tag Phrase Builder (on-demand)', 'tmwseo' ),
                'description' => __( 'Generates [tag name] × [modifier] phrases.', 'tmwseo' ),
                'last_run'    => null,
            ],
            'tmwseo_builder_video_phrases_enabled'          => [
                'label'       => __( 'Video Phrase Builder (on-demand)', 'tmwseo' ),
                'description' => __( 'Generates [video title] × [modifier] phrases.', 'tmwseo' ),
                'last_run'    => null,
            ],
            'tmwseo_builder_category_phrases_enabled'       => [
                'label'       => __( 'Category Phrase Builder (on-demand)', 'tmwseo' ),
                'description' => __( 'Generates [category name] × [modifier] phrases.', 'tmwseo' ),
                'last_run'    => null,
            ],
            'tmwseo_builder_competitor_phrases_enabled'     => [
                'label'       => __( 'Competitor Ranked Keyword Fetcher (on-demand)', 'tmwseo' ),
                'description' => __( 'Fetches competitor ranked keywords via DataForSEO — costs API credits.', 'tmwseo' ),
                'last_run'    => null,
            ],
        ];

        echo '<h2>' . esc_html__( 'Auto Builders Control Center', 'tmwseo' ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'All builders are OFF by default. Enable only the ones you need. All output goes to the Expansion Preview queue — nothing writes directly to the Seed Registry.', 'tmwseo' ) . '</p>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tmwseo_seed_registry_nonce' );
        echo '<input type="hidden" name="action" value="tmwseo_seed_registry_action">';
        echo '<input type="hidden" name="tmwseo_action" value="save_builder_switches">';

        echo '<table class="widefat striped">';
        echo '<thead><tr><th style="width:40px">' . esc_html__( 'Enabled', 'tmwseo' ) . '</th><th>' . esc_html__( 'Builder', 'tmwseo' ) . '</th><th>' . esc_html__( 'Last Run', 'tmwseo' ) . '</th></tr></thead><tbody>';

        foreach ( $builders as $opt => $info ) {
            $enabled  = (bool) get_option( $opt, 0 );
            $last_run = '';
            if ( is_array( $info['last_run'] ) ) {
                $last_run = ( $info['last_run']['timestamp'] ?? '' )
                    . ' — '
                    . ( isset( $info['last_run']['report']['preview_inserted'] )
                        ? (int) $info['last_run']['report']['preview_inserted'] . ' queued'
                        : 'see logs' );
            }
            echo '<tr>';
            printf(
                '<td><input type="checkbox" name="%s" value="1" %s></td>',
                esc_attr( $opt ),
                checked( $enabled, true, false )
            );
            printf(
                '<td><strong>%s</strong><br><small>%s</small></td>',
                esc_html( $info['label'] ),
                esc_html( $info['description'] )
            );
            printf( '<td><small>%s</small></td>', esc_html( $last_run ?: __( 'Never', 'tmwseo' ) ) );
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p><input type="submit" class="button button-primary" value="' . esc_attr__( 'Save Builder Settings', 'tmwseo' ) . '"></p>';
        echo '</form>';

        // ── Niche Pattern Family Preview ──────────────────────────────────
        // Manual trigger only. Output goes to preview/candidate queue — never
        // to trusted seeds. Requires operator review before any phrase is used.
        // ──────────────────────────────────────────────────────────────────

        $last_run    = get_option( 'tmwseo_last_builder_preview_run', [] );
        $all_cats    = \TMWSEO\Engine\Keywords\CuratedKeywordLibrary::niche_pattern_categories();
        $queue_full  = ExpansionCandidateRepository::is_queue_full();

        // Result notice after a run
        $builder_notice = '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ( sanitize_key( $_GET['builder_result'] ?? '' ) === 'done' ) ) {
            $inserted  = (int) ( $_GET['inserted']  ?? 0 );
            $generated = (int) ( $_GET['generated'] ?? 0 );
            $processed = (int) ( $_GET['processed'] ?? 0 );
            $filtered  = (int) ( $_GET['filtered']  ?? 0 );
            $qfull     = (int) ( $_GET['qfull']     ?? 0 );
            $builder_notice = sprintf(
                __( 'Run complete — %d categories, %d phrases generated, %d filtered, %d inserted to preview queue.', 'tmwseo' ),
                $processed, $generated, $filtered, $inserted
            );
            if ( $qfull ) {
                $builder_notice .= ' ' . __( 'Queue cap reached — some categories skipped.', 'tmwseo' );
            }
        }

        echo '<hr style="margin:32px 0 24px;">';
        echo '<h3 style="margin-top:0;">' . esc_html__( 'Niche Pattern Family Preview Generator', 'tmwseo' ) . '</h3>';
        echo '<p class="description">'
            . esc_html__( 'Generates candidate phrases from the static niche pattern families and queues them for review. Output goes to the Expansion Preview queue only — nothing is written to the Seed Registry.', 'tmwseo' )
            . '</p>';

        if ( $builder_notice !== '' ) {
            echo '<div class="notice notice-success inline" style="margin:12px 0;"><p>' . esc_html( $builder_notice ) . '</p></div>';
        }

        if ( $queue_full ) {
            echo '<div class="notice notice-warning inline" style="margin:12px 0;"><p>'
                . esc_html__( 'Review queue is currently at capacity. Review or approve existing candidates before running the builder.', 'tmwseo' )
                . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tmwseo_seed_registry_nonce' );
        echo '<input type="hidden" name="action" value="tmwseo_seed_registry_action">';
        echo '<input type="hidden" name="tmwseo_action" value="run_builder_preview">';

        echo '<table class="form-table" style="max-width:640px;"><tbody>';

        // Category selector
        echo '<tr>';
        echo '<th scope="row"><label for="builder_category">' . esc_html__( 'Category', 'tmwseo' ) . '</label></th>';
        echo '<td><select name="builder_category" id="builder_category" style="min-width:220px;">';
        echo '<option value="__all__">' . esc_html__( '— All categories (bounded) —', 'tmwseo' ) . '</option>';
        foreach ( $all_cats as $cat_slug ) {
            printf( '<option value="%s">%s</option>', esc_attr( $cat_slug ), esc_html( $cat_slug ) );
        }
        echo '</select>';
        echo '<p class="description" style="margin-top:4px;">'
            . esc_html__( 'Pick one category or run all (bounded by Max Categories below).', 'tmwseo' )
            . '</p></td>';
        echo '</tr>';

        // Per-category candidate limit
        echo '<tr>';
        echo '<th scope="row"><label for="builder_limit">' . esc_html__( 'Candidates per category', 'tmwseo' ) . '</label></th>';
        echo '<td><input type="number" name="builder_limit" id="builder_limit" value="50" min="1" max="100" style="width:80px;">';
        echo '<p class="description" style="margin-top:4px;">' . esc_html__( 'Max phrases generated per category (1–100).', 'tmwseo' ) . '</p></td>';
        echo '</tr>';

        // Max categories (all-categories mode only)
        echo '<tr>';
        echo '<th scope="row"><label for="builder_max_categories">' . esc_html__( 'Max categories (all-categories mode)', 'tmwseo' ) . '</label></th>';
        echo '<td><input type="number" name="builder_max_categories" id="builder_max_categories" value="5" min="1" max="20" style="width:80px;">';
        echo '<p class="description" style="margin-top:4px;">' . esc_html__( 'Only used when "All categories" is selected above (1–20).', 'tmwseo' ) . '</p></td>';
        echo '</tr>';

        echo '</tbody></table>';

        $last_run_display = '';
        if ( ! empty( $last_run['timestamp'] ) ) {
            $r = $last_run['result'] ?? [];
            $last_run_display = sprintf(
                __( 'Last run: %s — %d inserted, %d skipped', 'tmwseo' ),
                esc_html( $last_run['timestamp'] ),
                (int) ( $r['inserted'] ?? 0 ),
                (int) ( $r['skipped_duplicates'] ?? 0 )
            );
        }

        echo '<p>';
        $btn_attr = $queue_full ? ' disabled title="' . esc_attr__( 'Queue full — review existing candidates first', 'tmwseo' ) . '"' : '';
        echo '<input type="submit" class="button button-secondary" value="' . esc_attr__( 'Run Preview Generator', 'tmwseo' ) . '"' . $btn_attr . '>';
        if ( $last_run_display !== '' ) {
            echo ' &nbsp; <small style="color:#666;">' . esc_html( $last_run_display ) . '</small>';
        }
        echo '</p>';
        echo '</form>';
    }

    // -------------------------------------------------------------------------
    // Tab: Reset & Starter Pack
    // -------------------------------------------------------------------------

    private static function render_tab_reset(): void {
        $source_counts    = SeedRegistry::get_source_counts();
        $total_seeds      = array_sum( $source_counts );
        $block_active     = (int) get_option( 'tmwseo_block_static_curated', 0 );
        $starter_pack     = SeedRegistry::get_starter_pack();
        $form_action      = esc_url( admin_url( 'admin-post.php' ) );

        // ── Intro ──────────────────────────────────────────────────────────
        echo '<h2>' . esc_html__( 'Seed Reset & Starter Pack', 'tmwseo' ) . '</h2>';
        echo '<p class="description">'
            . esc_html__( 'Use this panel to clean up bad seeds and install a fresh starter set. Each step is independent — you can run any step without the others. All actions are logged.', 'tmwseo' )
            . '</p>';

        // ═══════════════════════════════════════════════════════════════════
        // STEP 1: Purge Bad Seeds
        // ═══════════════════════════════════════════════════════════════════
        echo '<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #dc3232;padding:16px 20px;margin:20px 0;border-radius:0 4px 4px 0;">';
        echo '<h3 style="margin-top:0;">' . esc_html__( 'Step 1: Remove Unwanted Seeds', 'tmwseo' ) . '</h3>';

        if ( $total_seeds === 0 ) {
            echo '<p>' . esc_html__( 'The seed registry is empty — nothing to purge.', 'tmwseo' ) . '</p>';
        } else {
            echo '<p>' . esc_html( sprintf(
                __( 'Current registry: %d total seeds. Select which source types to permanently delete:', 'tmwseo' ),
                $total_seeds
            ) ) . '</p>';

            echo '<form method="post" action="' . $form_action . '" id="tmwseo-purge-form">';
            wp_nonce_field( 'tmwseo_seed_registry_nonce' );
            echo '<input type="hidden" name="action" value="tmwseo_seed_registry_action">';
            echo '<input type="hidden" name="tmwseo_action" value="purge_seed_sources">';

            echo '<table class="widefat striped" style="max-width:600px;">';
            echo '<thead><tr><th style="width:40px;">' . esc_html__( 'Delete?', 'tmwseo' ) . '</th><th>' . esc_html__( 'Source', 'tmwseo' ) . '</th><th>' . esc_html__( 'Rows', 'tmwseo' ) . '</th></tr></thead><tbody>';

            // Display order: imports first (most likely to be purged), then static, then manual last
            $display_order = [ 'csv_import', 'approved_import', 'static_curated', 'static', 'model_root', 'manual' ];
            $source_labels = [
                'csv_import'       => __( 'csv_import — Legacy CSV imports', 'tmwseo' ),
                'approved_import'  => __( 'approved_import — Reviewed CSV/API imports', 'tmwseo' ),
                'static_curated'   => __( 'static_curated — Hardcoded engine seeds', 'tmwseo' ),
                'static'           => __( 'static — Legacy alias for static_curated', 'tmwseo' ),
                'model_root'       => __( 'model_root — Model name seeds', 'tmwseo' ),
                'manual'           => __( 'manual — Operator-entered seeds', 'tmwseo' ),
            ];

            // Safe-by-default: only import sources are pre-checked
            $default_checked = [ 'csv_import', 'approved_import' ];

            foreach ( $display_order as $src ) {
                $count = (int) ( $source_counts[ $src ] ?? 0 );
                if ( $count === 0 ) {
                    continue;
                }
                $checked = in_array( $src, $default_checked, true ) ? ' checked' : '';
                $label   = $source_labels[ $src ] ?? $src;
                $warning = '';
                if ( $src === 'manual' ) {
                    $warning = ' <span style="color:#dc3232;font-weight:bold;">⚠ ' . esc_html__( 'Careful — these are your manually added seeds', 'tmwseo' ) . '</span>';
                }
                echo '<tr>';
                printf(
                    '<td><input type="checkbox" name="purge_sources[]" value="%s"%s></td>',
                    esc_attr( $src ),
                    $checked
                );
                echo '<td>' . esc_html( $label ) . $warning . '</td>';
                echo '<td><strong>' . $count . '</strong></td>';
                echo '</tr>';
            }

            // Show any unexpected source types (shouldn't happen, but defensive)
            foreach ( $source_counts as $src => $count ) {
                if ( ! in_array( $src, $display_order, true ) && $count > 0 ) {
                    echo '<tr>';
                    printf(
                        '<td><input type="checkbox" name="purge_sources[]" value="%s"></td>',
                        esc_attr( $src )
                    );
                    echo '<td><code>' . esc_html( $src ) . '</code> <em>(' . esc_html__( 'unknown source type', 'tmwseo' ) . ')</em></td>';
                    echo '<td><strong>' . (int) $count . '</strong></td>';
                    echo '</tr>';
                }
            }

            echo '</tbody></table>';

            echo '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:4px;padding:8px 12px;margin:12px 0;max-width:600px;">';
            echo '<small>' . esc_html__( '⚠ If you purge static_curated seeds, enable the block in Step 2 below — otherwise they will be re-registered on the next keyword cycle.', 'tmwseo' ) . '</small>';
            echo '</div>';

            // JavaScript confirm with dynamic counts
            echo '<p>';
            echo '<input type="submit" class="button" style="color:#dc3232;border-color:#dc3232;" value="'
                . esc_attr__( '🗑 Purge Selected Seed Sources', 'tmwseo' )
                . '" onclick="return tmwseoPurgeConfirm();">';
            echo '</p>';
            echo '</form>';

            echo '<script>
function tmwseoPurgeConfirm() {
    var boxes = document.querySelectorAll(\'#tmwseo-purge-form input[name="purge_sources[]"]:checked\');
    if (boxes.length === 0) { alert("' . esc_js( __( 'No sources selected.', 'tmwseo' ) ) . '"); return false; }
    var sources = [];
    for (var i = 0; i < boxes.length; i++) {
        var row = boxes[i].closest("tr");
        var count = row ? row.querySelector("td:last-child strong") : null;
        sources.push(boxes[i].value + " (" + (count ? count.textContent : "?") + ")");
    }
    return confirm("' . esc_js( __( 'PERMANENTLY DELETE seeds from these sources:\\n\\n', 'tmwseo' ) ) . '" + sources.join("\\n") + "\\n\\n' . esc_js( __( 'This cannot be undone. Continue?', 'tmwseo' ) ) . '");
}
</script>';
        }
        echo '</div>'; // end Step 1

        // ═══════════════════════════════════════════════════════════════════
        // STEP 2: Block Static Curated Re-Registration
        // ═══════════════════════════════════════════════════════════════════
        echo '<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #f0ad4e;padding:16px 20px;margin:20px 0;border-radius:0 4px 4px 0;">';
        echo '<h3 style="margin-top:0;">' . esc_html__( 'Step 2: Prevent Old Static Seeds from Coming Back', 'tmwseo' ) . '</h3>';

        echo '<p class="description">'
            . esc_html__( 'The keyword engine hardcodes 8 static seeds in collect_seeds() and re-registers them as static_curated every keyword cycle. If you purged static_curated in Step 1, enable this block to prevent them from returning.', 'tmwseo' )
            . '</p>';

        // Show the current hardcoded seeds so the operator knows what's being blocked
        echo '<details style="margin:8px 0 12px;">';
        echo '<summary style="cursor:pointer;color:#2271b1;">' . esc_html__( 'View the 8 hardcoded static seeds', 'tmwseo' ) . '</summary>';
        echo '<pre style="background:#f6f7f7;padding:8px 12px;margin:4px 0;border-radius:3px;max-width:400px;">';
        $hardcoded = [
            'adult webcam chat', 'live cam girls', 'webcam chat rooms', 'adult video chat',
            'cam to cam chat', 'random adult chat', 'private cam show', 'live adult chat',
        ];
        foreach ( $hardcoded as $s ) {
            echo esc_html( $s ) . "\n";
        }
        echo '</pre></details>';

        echo '<form method="post" action="' . $form_action . '">';
        wp_nonce_field( 'tmwseo_seed_registry_nonce' );
        echo '<input type="hidden" name="action" value="tmwseo_seed_registry_action">';
        echo '<input type="hidden" name="tmwseo_action" value="save_static_curated_block">';

        $block_checked = $block_active ? ' checked' : '';
        echo '<label>';
        echo '<input type="checkbox" name="tmwseo_block_static_curated" value="1"' . $block_checked . '> ';
        echo '<strong>' . esc_html__( 'Block static_curated seed re-registration', 'tmwseo' ) . '</strong>';
        echo '</label>';

        if ( $block_active ) {
            echo ' <span style="color:#dc3232;font-weight:bold;">(' . esc_html__( 'Currently BLOCKED', 'tmwseo' ) . ')</span>';
        } else {
            echo ' <span style="color:#2ecc71;">(' . esc_html__( 'Currently allowed', 'tmwseo' ) . ')</span>';
        }

        echo '<p><input type="submit" class="button button-primary" value="' . esc_attr__( '💾 Save Static Seed Setting', 'tmwseo' ) . '"></p>';
        echo '</form>';
        echo '</div>'; // end Step 2

        // ═══════════════════════════════════════════════════════════════════
        // STEP 3: Install Starter Seed Pack
        // ═══════════════════════════════════════════════════════════════════
        echo '<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #46b450;padding:16px 20px;margin:20px 0;border-radius:0 4px 4px 0;">';
        echo '<h3 style="margin-top:0;">' . esc_html__( 'Step 3: Install Recommended Starter Seeds', 'tmwseo' ) . '</h3>';

        echo '<p class="description">'
            . esc_html( sprintf(
                __( 'Install %d clean, commercial-intent root seeds. These are registered as source=manual with starter pack provenance. Duplicates are automatically skipped — safe to click more than once.', 'tmwseo' ),
                count( $starter_pack )
            ) )
            . '</p>';

        // Show the seeds in a read-only textarea so the operator can review
        echo '<textarea readonly rows="' . count( $starter_pack ) . '" style="width:300px;font-family:monospace;font-size:13px;background:#f6f7f7;resize:none;">';
        echo esc_textarea( implode( "\n", $starter_pack ) );
        echo '</textarea>';

        echo '<form method="post" action="' . $form_action . '" style="margin-top:8px;">';
        wp_nonce_field( 'tmwseo_seed_registry_nonce' );
        echo '<input type="hidden" name="action" value="tmwseo_seed_registry_action">';
        echo '<input type="hidden" name="tmwseo_action" value="install_starter_pack">';

        echo '<p><input type="submit" class="button button-primary" value="'
            . esc_attr( sprintf( __( '📦 Install %d Starter Seeds', 'tmwseo' ), count( $starter_pack ) ) )
            . '" onclick="return confirm(\'' . esc_js( sprintf(
                __( 'This will add %d trusted manual seeds to your registry. Existing seeds with the same phrases will be skipped (not duplicated). Continue?', 'tmwseo' ),
                count( $starter_pack )
            ) ) . '\');"></p>';
        echo '</form>';
        echo '</div>'; // end Step 3

        // ── Post-reset status ──────────────────────────────────────────────
        echo '<div style="background:#f0f6fc;border:1px solid #c3c4c7;padding:12px 16px;margin:20px 0;border-radius:4px;">';
        echo '<strong>' . esc_html__( 'Current Registry Status', 'tmwseo' ) . '</strong>';
        $fresh_counts = SeedRegistry::get_source_counts();
        $fresh_total  = array_sum( $fresh_counts );
        echo '<p>' . esc_html( sprintf( __( 'Total seeds: %d', 'tmwseo' ), $fresh_total ) ) . '</p>';
        if ( ! empty( $fresh_counts ) ) {
            echo '<ul style="margin:4px 0 0 18px;list-style:disc;">';
            foreach ( $fresh_counts as $src => $cnt ) {
                echo '<li><code>' . esc_html( $src ) . '</code>: ' . (int) $cnt . '</li>';
            }
            echo '</ul>';
        }
        $discovery_enabled = (bool) get_option( 'tmw_discovery_enabled', 1 );
        echo '<p style="margin-top:8px;">'
            . esc_html__( 'Discovery governor:', 'tmwseo' ) . ' '
            . ( $discovery_enabled
                ? '<span style="color:#2ecc71;font-weight:bold;">✓ ' . esc_html__( 'Enabled', 'tmwseo' ) . '</span>'
                : '<span style="color:#dc3232;font-weight:bold;">✗ ' . esc_html__( 'DISABLED', 'tmwseo' ) . '</span>' )
            . '</p>';
        echo '<p>'
            . esc_html__( 'Static curated block:', 'tmwseo' ) . ' '
            . ( (int) get_option( 'tmwseo_block_static_curated', 0 )
                ? '<span style="color:#dc3232;font-weight:bold;">🛑 ' . esc_html__( 'Blocked', 'tmwseo' ) . '</span>'
                : '<span style="color:#2ecc71;">✓ ' . esc_html__( 'Allowed', 'tmwseo' ) . '</span>' )
            . '</p>';
        echo '</div>';
    }


    // -------------------------------------------------------------------------
    // Action handlers: Trusted Seeds + Candidates exports/delete (v5.3)
    // -------------------------------------------------------------------------

    public static function handle_trusted_seeds_export(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }
        check_admin_referer( 'tmwseo_trusted_seeds_export' );

        $filters = [
            'search'       => sanitize_text_field( (string) ( $_GET['ts_search']  ?? '' ) ),
            'source'       => sanitize_key( (string)         ( $_GET['ts_source']  ?? '' ) ),
            'seed_type'    => sanitize_text_field( (string) ( $_GET['ts_stype']   ?? '' ) ),
            'entity_type'  => sanitize_key( (string)         ( $_GET['ts_etype']   ?? '' ) ),
            'batch_id'     => sanitize_text_field( (string) ( $_GET['ts_batch']   ?? '' ) ),
            'source_label' => sanitize_text_field( (string) ( $_GET['ts_slabel']  ?? '' ) ),
        ];

        $rows     = \TMWSEO\Engine\Admin\KeywordDataRepository::get_trusted_seeds_for_export( $filters );
        $filename = 'trusted-seeds-export-' . gmdate( 'YmdHis' ) . '.csv';
        \TMWSEO\Engine\Admin\KeywordDataRepository::stream_csv_download( $rows, $filename );
    }

    public static function handle_candidates_export(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }
        check_admin_referer( 'tmwseo_candidates_export' );

        $filters = [
            'search'   => sanitize_text_field( (string) ( $_GET['cand_search'] ?? '' ) ),
            'source'   => sanitize_key( (string)         ( $_GET['cand_source'] ?? '' ) ),
            'status'   => sanitize_key( (string)         ( $_GET['cand_status'] ?? '' ) ),
            'batch_id' => sanitize_text_field( (string) ( $_GET['cand_batch']  ?? '' ) ),
        ];

        $rows     = \TMWSEO\Engine\Admin\KeywordDataRepository::get_candidates_for_export( $filters );
        $filename = 'candidates-export-' . gmdate( 'YmdHis' ) . '.csv';
        \TMWSEO\Engine\Admin\KeywordDataRepository::stream_csv_download( $rows, $filename );
    }

    public static function handle_trusted_seed_delete(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized' ); }
        $seed_id = (int) ( $_GET['seed_id'] ?? 0 );
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( (string) wp_unslash( $_GET['_wpnonce'] ), 'tmwseo_trusted_seed_delete_' . $seed_id ) ) {
            wp_die( __( 'Invalid nonce', 'tmwseo' ) );
        }
        if ( $seed_id <= 0 ) { wp_die( 'Invalid seed ID.' ); }

        $deleted = \TMWSEO\Engine\Admin\KeywordDataRepository::delete_seed_by_id( $seed_id );

        \TMWSEO\Engine\Logs::info( 'seed_registry', 'Trusted seed deleted via explorer', [
            'seed_id' => $seed_id,
            'deleted' => $deleted,
            'user'    => get_current_user_id(),
        ] );

        $redirect = add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'tab' => 'trusted_seeds', 'tmwseo_notice' => $deleted ? 'seed_deleted' : 'seed_not_found' ],
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Handle bulk actions on the Trusted Seeds Explorer table.
     *
     * Dispatches to delete or export based on POSTed ts_bulk_action value.
     * Shared nonce: tmwseo_ts_bulk_nonce.
     * IDs come from ts_ids[] (array of int, POST).
     */
    public static function handle_trusted_seeds_bulk_action(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'tmwseo_ts_bulk_nonce' );

        // Accept either the top or bottom bulk-action select (bottom select syncs
        // to the top via JS; fall back to the bottom value if JS was disabled).
        $bulk_action = sanitize_key( (string) ( $_POST['ts_bulk_action'] ?? '' ) );
        if ( $bulk_action === '' ) {
            $bulk_action = sanitize_key( (string) ( $_POST['ts_bulk_action_bottom'] ?? '' ) );
        }
        $raw_ids     = isset( $_POST['ts_ids'] ) && is_array( $_POST['ts_ids'] )
            ? $_POST['ts_ids']
            : [];
        $ids = array_values( array_filter( array_map( 'intval', $raw_ids ), fn( $id ) => $id > 0 ) );

        // Return URL (fallback to Trusted Seeds tab).
        // Always strip ts_view so we land back on the list, never a detail panel.
        $return_url = wp_validate_redirect(
            wp_unslash( (string) ( $_POST['ts_return_url'] ?? '' ) ),
            add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'trusted_seeds' ], admin_url( 'admin.php' ) )
        );
        $return_url = remove_query_arg( 'ts_view', $return_url );

        if ( empty( $ids ) ) {
            wp_safe_redirect( add_query_arg( 'tmwseo_notice', 'bulk_nothing', $return_url ) );
            exit;
        }

        if ( $bulk_action === 'delete' ) {
            $deleted = \TMWSEO\Engine\Admin\KeywordDataRepository::delete_seeds_by_ids( $ids );

            \TMWSEO\Engine\Logs::info( 'seed_registry', 'Trusted seeds bulk deleted via explorer', [
                'ids'     => $ids,
                'deleted' => $deleted,
                'user'    => get_current_user_id(),
            ] );

            wp_safe_redirect( add_query_arg(
                [ 'tmwseo_notice' => 'bulk_deleted', 'deleted_count' => $deleted ],
                $return_url
            ) );
            exit;

        } elseif ( $bulk_action === 'export' ) {
            $rows     = \TMWSEO\Engine\Admin\KeywordDataRepository::get_seeds_by_ids( $ids );
            $filename = 'trusted-seeds-selection-' . count( $ids ) . '-' . gmdate( 'YmdHis' ) . '.csv';
            \TMWSEO\Engine\Admin\KeywordDataRepository::stream_csv_download( $rows, $filename );
            // stream_csv_download calls exit — execution stops here.

        } else {
            // Unknown action — redirect back without change
            wp_safe_redirect( $return_url );
            exit;
        }
    }

    // -------------------------------------------------------------------------
    // Tab: Trusted Seeds Explorer (v5.3.1 — operator control pass)
    // -------------------------------------------------------------------------

    private static function render_tab_trusted_seeds(): void {
        $repo = \TMWSEO\Engine\Admin\KeywordDataRepository::class;

        if ( ! $repo::seeds_table_exists() ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'tmwseo_seeds table not found. Activate the plugin to create it.', 'tmwseo' ) . '</p></div>';
            return;
        }

        // ── Route: detail panel view ──────────────────────────────────────────
        $ts_view = max( 0, (int) ( $_GET['ts_view'] ?? 0 ) );
        if ( $ts_view > 0 ) {
            self::render_trusted_seed_detail( $ts_view );
            // Still fall through to render the table below the panel.
        }

        echo '<h2>' . esc_html__( 'Trusted Seeds Explorer', 'tmwseo' ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'Full visual table of every row in tmwseo_seeds. Filter, sort, export, or delete individual seeds.', 'tmwseo' ) . '</p>';

        if ( ! $repo::has_provenance_columns() ) {
            echo '<div class="notice notice-warning inline" style="margin:0 0 12px;"><p><strong>' . esc_html__( 'Schema note:', 'tmwseo' ) . '</strong> ' . esc_html__( 'Provenance columns (import_batch_id, import_source_label) are missing — deactivate and reactivate the plugin to run the 4.3.0 migration.', 'tmwseo' ) . '</p></div>';
        }

        $base_url     = add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'trusted_seeds' ], admin_url( 'admin.php' ) );
        $search       = sanitize_text_field( (string) ( $_GET['ts_search']  ?? '' ) );
        $f_source     = sanitize_key( (string)        ( $_GET['ts_source']  ?? '' ) );
        $f_seed_type  = sanitize_text_field( (string) ( $_GET['ts_stype']   ?? '' ) );
        $f_etype      = sanitize_key( (string)        ( $_GET['ts_etype']   ?? '' ) );
        $f_batch      = sanitize_text_field( (string) ( $_GET['ts_batch']   ?? '' ) );
        $f_slabel     = sanitize_text_field( (string) ( $_GET['ts_slabel']  ?? '' ) );
        $orderby      = sanitize_key( (string)        ( $_GET['ts_orderby'] ?? 'created_at' ) );
        $order        = strtoupper( sanitize_key( (string) ( $_GET['ts_order'] ?? 'DESC' ) ) ) === 'ASC' ? 'ASC' : 'DESC';
        $per_page     = 50;
        $current_page = max( 1, (int) ( $_GET['ts_paged'] ?? 1 ) );
        $offset       = ( $current_page - 1 ) * $per_page;

        $filters = [
            'search'       => $search,
            'source'       => $f_source,
            'seed_type'    => $f_seed_type,
            'entity_type'  => $f_etype,
            'batch_id'     => $f_batch,
            'source_label' => $f_slabel,
        ];

        // ── Filter bar (GET form — keeps URL clean) ───────────────────────────
        echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
        echo '<input type="hidden" name="tab" value="trusted_seeds">';
        echo '<input type="search" name="ts_search" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search seed phrase\xe2\x80\xa6', 'tmwseo' ) . '" style="width:220px;">';

        echo '<select name="ts_source"><option value="">' . esc_html__( 'All sources', 'tmwseo' ) . '</option>';
        echo '<option value="__imported__"' . selected( $f_source, '__imported__', false ) . '>'
            . esc_html__( 'Imported seeds (approved_import + csv_import)', 'tmwseo' ) . '</option>';
        foreach ( $repo::TRUSTED_SOURCES as $src ) {
            echo '<option value="' . esc_attr( $src ) . '"' . selected( $f_source, $src, false ) . '>' . esc_html( $src ) . '</option>';
        }
        echo '</select>';

        $seed_types = $repo::distinct_seed_types();
        if ( ! empty( $seed_types ) ) {
            echo '<select name="ts_stype"><option value="">' . esc_html__( 'All seed types', 'tmwseo' ) . '</option>';
            foreach ( $seed_types as $st ) {
                echo '<option value="' . esc_attr( $st ) . '"' . selected( $f_seed_type, $st, false ) . '>' . esc_html( $st ) . '</option>';
            }
            echo '</select>';
        }

        $entity_types = $repo::distinct_entity_types();
        if ( ! empty( $entity_types ) ) {
            echo '<select name="ts_etype"><option value="">' . esc_html__( 'All entity types', 'tmwseo' ) . '</option>';
            foreach ( $entity_types as $et ) {
                echo '<option value="' . esc_attr( $et ) . '"' . selected( $f_etype, $et, false ) . '>' . esc_html( $et ) . '</option>';
            }
            echo '</select>';
        }

        if ( $repo::has_provenance_columns() ) {
            $batch_ids = $repo::distinct_import_batch_ids();
            if ( ! empty( $batch_ids ) ) {
                echo '<select name="ts_batch"><option value="">' . esc_html__( 'All batch IDs', 'tmwseo' ) . '</option>';
                echo '<option value="__none__"' . selected( $f_batch, '__none__', false ) . '>' . esc_html__( '(no batch ID)', 'tmwseo' ) . '</option>';
                foreach ( $batch_ids as $bid ) {
                    echo '<option value="' . esc_attr( $bid ) . '"' . selected( $f_batch, $bid, false ) . '>' . esc_html( $bid ) . '</option>';
                }
                echo '</select>';
            }
            $source_labels = $repo::distinct_import_source_labels();
            if ( ! empty( $source_labels ) ) {
                echo '<select name="ts_slabel"><option value="">' . esc_html__( 'All source labels', 'tmwseo' ) . '</option>';
                foreach ( $source_labels as $sl ) {
                    echo '<option value="' . esc_attr( $sl ) . '"' . selected( $f_slabel, $sl, false ) . '>' . esc_html( $sl ) . '</option>';
                }
                echo '</select>';
            }
        }

        echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'tmwseo' ) . '</button>';
        if ( array_filter( $filters ) ) {
            echo '<a href="' . esc_url( $base_url ) . '" class="button">' . esc_html__( 'Clear', 'tmwseo' ) . '</a>';
        }
        echo '</form>';

        // ── Counts + filtered export ──────────────────────────────────────────
        $total_rows   = $repo::count_trusted_seeds( $filters );
        $total_pages  = (int) ceil( $total_rows / $per_page );
        $showing_from = $total_rows > 0 ? $offset + 1 : 0;
        $showing_to   = min( $offset + $per_page, $total_rows );

        $filter_export_url = wp_nonce_url(
            add_query_arg( array_merge(
                [ 'action' => 'tmwseo_trusted_seeds_export' ],
                array_filter( [
                    'ts_search'  => $search,
                    'ts_source'  => $f_source,
                    'ts_stype'   => $f_seed_type,
                    'ts_etype'   => $f_etype,
                    'ts_batch'   => $f_batch,
                    'ts_slabel'  => $f_slabel,
                ] )
            ), admin_url( 'admin-post.php' ) ),
            'tmwseo_trusted_seeds_export'
        );

        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px;">';
        echo '<p style="margin:0;color:#6b7280;font-size:13px;">';
        echo esc_html( sprintf( __( 'Showing %1$d\xe2\x80\x93%2$d of %3$d trusted seeds', 'tmwseo' ), $showing_from, $showing_to, $total_rows ) );
        echo '</p>';
        echo '<a class="button" href="' . esc_url( $filter_export_url ) . '">';
        echo esc_html( sprintf( __( 'Export all %d filtered rows', 'tmwseo' ), $total_rows ) );
        echo '</a>';
        echo '</div>';

        if ( $total_rows === 0 ) {
            echo '<p>' . esc_html__( 'No seeds match the current filters.', 'tmwseo' ) . '</p>';
            return;
        }

        // ── Fetch current page ────────────────────────────────────────────────
        $rows = $repo::get_trusted_seeds( $filters, $orderby, $order, $per_page, $offset );

        // ── Sortable column link helper ───────────────────────────────────────
        $sort_col = static function( string $col, string $label ) use ( $base_url, $orderby, $order, $filters ): string {
            $next_order = ( $orderby === $col && $order === 'ASC' ) ? 'DESC' : 'ASC';
            $url        = add_query_arg( array_merge( $filters, [ 'ts_orderby' => $col, 'ts_order' => $next_order ] ), $base_url );
            $arrow      = $orderby === $col ? ( $order === 'ASC' ? ' \xe2\x96\xb2' : ' \xe2\x96\xbc' ) : '';
            return '<a href="' . esc_url( $url ) . '" style="color:inherit;text-decoration:none;white-space:nowrap;">'
                . esc_html( $label . $arrow ) . '</a>';
        };

        // Preserve all current filter+sort+page params for the bulk form's redirect-back URL
        $return_url = add_query_arg( array_merge(
            $filters,
            array_filter( [
                'ts_orderby' => $orderby !== 'created_at' ? $orderby : '',
                'ts_order'   => $order   !== 'DESC'       ? $order   : '',
                'ts_paged'   => $current_page > 1         ? (string) $current_page : '',
            ] )
        ), $base_url );

        // ── Bulk action POST form wrapping the table ───────────────────────────
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="tmwseo-ts-bulk-form">';
        echo '<input type="hidden" name="action" value="tmwseo_ts_bulk_action">';
        echo '<input type="hidden" name="ts_return_url" value="' . esc_attr( $return_url ) . '">';
        wp_nonce_field( 'tmwseo_ts_bulk_nonce' );

        // ── Bulk action bar ───────────────────────────────────────────────────
        echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;">';
        echo '<select name="ts_bulk_action" id="tmwseo-ts-bulk-select">';
        echo '<option value="">' . esc_html__( 'Bulk actions', 'tmwseo' ) . '</option>';
        echo '<option value="delete">' . esc_html__( 'Delete selected', 'tmwseo' ) . '</option>';
        echo '<option value="export">' . esc_html__( 'Export selected as CSV', 'tmwseo' ) . '</option>';
        echo '</select>';
        echo '<button type="submit" class="button" onclick="return tmwseoBulkConfirm();">'
            . esc_html__( 'Apply', 'tmwseo' ) . '</button>';
        echo '<span id="tmwseo-ts-selected-count" style="color:#6b7280;font-size:13px;"></span>';
        echo '</div>';

        // ── Table ─────────────────────────────────────────────────────────────
        echo '<div style="overflow-x:auto;">';
        echo '<table class="widefat striped" style="font-size:12px;min-width:1640px;">';
        echo '<thead><tr style="white-space:nowrap;">';

        // Checkbox select-all
        echo '<th style="width:32px;padding:6px 8px;">'
            . '<input type="checkbox" id="tmwseo-ts-check-all" title="' . esc_attr__( 'Select all on this page', 'tmwseo' ) . '">'
            . '</th>';

        $all_cols = [
            'id'                    => 'ID',
            'seed'                  => 'Seed Phrase',
            'source'                => 'Source',
            'seed_type'             => 'Seed Type',
            'priority'              => 'Priority',
            'entity_type'           => 'Entity Type',
            'entity_id'             => 'Entity ID',
            'created_at'            => 'Created',
            'last_used'             => 'Last Used',
            'last_expanded_at'      => 'Last Expanded',
            'expansion_count'       => 'Exp. Count',
            'net_new_yielded'       => 'Net New',
            'duplicates_returned'   => 'Dups',
            'estimated_spend_usd'   => 'Spend USD',
            'last_provider'         => 'Provider',
            'cooldown_until'        => 'Cooldown',
            'roi_score'             => 'ROI',
            'consecutive_zero_yield'=> 'Zero Streak',
            'import_batch_id'       => 'Batch ID',
            'import_source_label'   => 'Source Label',
        ];
        foreach ( $all_cols as $col => $label ) {
            if ( in_array( $col, [ 'import_batch_id', 'import_source_label' ], true )
                && ! $repo::has_provenance_columns() ) {
                continue;
            }
            echo '<th>' . $sort_col( $col, $label ) . '</th>';
        }
        echo '<th>' . esc_html__( 'Actions', 'tmwseo' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $r ) {
            $seed_id = (int) ( $r['id'] ?? 0 );

            // Per-row delete (existing GET link with its own nonce — unchanged)
            $del_url = wp_nonce_url(
                add_query_arg(
                    [ 'action' => 'tmwseo_trusted_seed_delete', 'seed_id' => $seed_id ],
                    admin_url( 'admin-post.php' )
                ),
                'tmwseo_trusted_seed_delete_' . $seed_id
            );

            // Per-row detail link (GET, no nonce needed — read-only)
            $detail_url = add_query_arg(
                array_merge( $filters, [
                    'ts_orderby' => $orderby,
                    'ts_order'   => $order,
                    'ts_paged'   => $current_page,
                    'ts_view'    => $seed_id,
                ] ),
                $base_url
            );

            echo '<tr>';

            // Checkbox
            echo '<td style="padding:6px 8px;">'
                . '<input type="checkbox" name="ts_ids[]" value="' . esc_attr( (string) $seed_id ) . '" class="tmwseo-ts-row-cb">'
                . '</td>';

            echo '<td>' . $seed_id . '</td>';
            echo '<td><strong>' . esc_html( (string) ( $r['seed'] ?? '' ) ) . '</strong></td>';
            echo '<td><code>' . esc_html( (string) ( $r['source'] ?? '' ) ) . '</code></td>';
            echo '<td>' . esc_html( (string) ( $r['seed_type'] ?? '' ) ?: '-' ) . '</td>';
            echo '<td>' . (int) ( $r['priority'] ?? 0 ) . '</td>';
            echo '<td>' . esc_html( (string) ( $r['entity_type'] ?? '' ) ?: '-' ) . '</td>';
            echo '<td>' . (int) ( $r['entity_id'] ?? 0 ) . '</td>';
            echo '<td style="white-space:nowrap;">' . esc_html( (string) ( $r['created_at']       ?? '' ) ?: '-' ) . '</td>';
            echo '<td style="white-space:nowrap;">' . esc_html( (string) ( $r['last_used']         ?? '' ) ?: '-' ) . '</td>';
            echo '<td style="white-space:nowrap;">' . esc_html( (string) ( $r['last_expanded_at']  ?? '' ) ?: '-' ) . '</td>';
            echo '<td>' . (int) ( $r['expansion_count']         ?? 0 ) . '</td>';
            echo '<td>' . (int) ( $r['net_new_yielded']         ?? 0 ) . '</td>';
            echo '<td>' . (int) ( $r['duplicates_returned']     ?? 0 ) . '</td>';
            $spend = $r['estimated_spend_usd'] ?? null;
            echo '<td>' . ( $spend !== null ? '$' . number_format( (float) $spend, 4 ) : '-' ) . '</td>';
            echo '<td>' . esc_html( (string) ( $r['last_provider']  ?? '' ) ?: '-' ) . '</td>';
            echo '<td style="white-space:nowrap;">' . esc_html( (string) ( $r['cooldown_until']    ?? '' ) ?: '-' ) . '</td>';
            $roi = $r['roi_score'] ?? null;
            echo '<td>' . ( $roi !== null ? number_format( (float) $roi, 2 ) : '-' ) . '</td>';
            echo '<td>' . (int) ( $r['consecutive_zero_yield'] ?? 0 ) . '</td>';
            if ( $repo::has_provenance_columns() ) {
                $bval = (string) ( $r['import_batch_id'] ?? '' );
                echo '<td style="font-size:11px;">' . ( $bval !== '' ? '<code>' . esc_html( $bval ) . '</code>' : '<em>-</em>' ) . '</td>';
                echo '<td style="font-size:11px;">' . esc_html( (string) ( $r['import_source_label'] ?? '' ) ?: '-' ) . '</td>';
            }

            // Actions column: View details + Delete
            echo '<td style="white-space:nowrap;display:flex;gap:4px;">';
            echo '<a class="button button-small" href="' . esc_url( $detail_url ) . '">'
                . esc_html__( 'Details', 'tmwseo' ) . '</a>';
            echo '<a class="button button-small" style="color:#b91c1c;" href="' . esc_url( $del_url ) . '"'
                . ' onclick="return confirm(\'' . esc_js( __( 'Permanently delete this seed?', 'tmwseo' ) ) . '\');">'
                . esc_html__( 'Delete', 'tmwseo' ) . '</a>';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';

        // Duplicate bulk bar below table for convenience
        echo '<div style="display:flex;align-items:center;gap:8px;margin-top:8px;flex-wrap:wrap;">';
        echo '<select name="ts_bulk_action_bottom">';
        echo '<option value="">' . esc_html__( 'Bulk actions', 'tmwseo' ) . '</option>';
        echo '<option value="delete">' . esc_html__( 'Delete selected', 'tmwseo' ) . '</option>';
        echo '<option value="export">' . esc_html__( 'Export selected as CSV', 'tmwseo' ) . '</option>';
        echo '</select>';
        echo '<button type="submit" class="button" onclick="return tmwseoBulkConfirmBottom();">'
            . esc_html__( 'Apply', 'tmwseo' ) . '</button>';
        echo '</div>';

        echo '</form>'; // end bulk form

        // ── Inline JS ─────────────────────────────────────────────────────────
        ?>
        <script>
        (function(){
            var form      = document.getElementById('tmwseo-ts-bulk-form');
            var checkAll  = document.getElementById('tmwseo-ts-check-all');
            var countSpan = document.getElementById('tmwseo-ts-selected-count');

            function updateCount() {
                var checked = form.querySelectorAll('.tmwseo-ts-row-cb:checked').length;
                countSpan.textContent = checked > 0 ? checked + ' selected' : '';
            }

            checkAll.addEventListener('change', function() {
                form.querySelectorAll('.tmwseo-ts-row-cb').forEach(function(cb) {
                    cb.checked = checkAll.checked;
                });
                updateCount();
            });

            form.addEventListener('change', function(e) {
                if (e.target.classList.contains('tmwseo-ts-row-cb')) {
                    var all  = form.querySelectorAll('.tmwseo-ts-row-cb').length;
                    var chkd = form.querySelectorAll('.tmwseo-ts-row-cb:checked').length;
                    checkAll.indeterminate = chkd > 0 && chkd < all;
                    checkAll.checked       = chkd === all;
                    updateCount();
                }
            });

            // Sync bottom select to top select before submit
            var topSelect    = document.querySelector('[name="ts_bulk_action"]');
            var bottomSelect = document.querySelector('[name="ts_bulk_action_bottom"]');
            if (bottomSelect) {
                bottomSelect.addEventListener('change', function() {
                    topSelect.value = bottomSelect.value;
                });
            }
        })();

        function tmwseoBulkConfirm() {
            return _tmwseoBulkApply();
        }
        function tmwseoBulkConfirmBottom() {
            var bottomSelect = document.querySelector('[name="ts_bulk_action_bottom"]');
            var topSelect    = document.querySelector('[name="ts_bulk_action"]');
            if (bottomSelect) { topSelect.value = bottomSelect.value; }
            return _tmwseoBulkApply();
        }
        function _tmwseoBulkApply() {
            var form   = document.getElementById('tmwseo-ts-bulk-form');
            var action = form.querySelector('[name="ts_bulk_action"]').value;
            var ids    = Array.from(form.querySelectorAll('.tmwseo-ts-row-cb:checked'));
            if (!action) {
                alert('<?php echo esc_js( __( 'Please choose a bulk action first.', 'tmwseo' ) ); ?>');
                return false;
            }
            if (ids.length === 0) {
                alert('<?php echo esc_js( __( 'No seeds selected.', 'tmwseo' ) ); ?>');
                return false;
            }
            if (action === 'delete') {
                return confirm('<?php echo esc_js( __( 'Permanently delete the selected seeds? This cannot be undone.', 'tmwseo' ) ); ?>');
            }
            return true; // export: no confirmation needed
        }
        </script>
        <?php

        // ── Pagination (outside the form — GET links) ─────────────────────────
        if ( $total_pages > 1 ) {
            $page_args = array_merge( $filters, [ 'ts_orderby' => $orderby, 'ts_order' => $order ] );
            echo '<div style="margin-top:12px;display:flex;gap:6px;flex-wrap:wrap;">';
            $range = range( max( 1, $current_page - 4 ), min( $total_pages, $current_page + 4 ) );
            if ( ! in_array( 1, $range, true ) ) {
                echo '<a href="' . esc_url( add_query_arg( array_merge( $page_args, [ 'ts_paged' => 1 ] ), $base_url ) ) . '" class="button button-small">1</a>';
                if ( $range[0] > 2 ) {
                    echo '<span style="line-height:2;">\xe2\x80\xa6</span>';
                }
            }
            foreach ( $range as $p ) {
                $style = ( $p === $current_page ) ? 'font-weight:700;' : '';
                echo '<a href="' . esc_url( add_query_arg( array_merge( $page_args, [ 'ts_paged' => $p ] ), $base_url ) ) . '" class="button button-small" style="' . esc_attr( $style ) . '">' . (int) $p . '</a>';
            }
            if ( ! in_array( $total_pages, $range, true ) ) {
                if ( end( $range ) < $total_pages - 1 ) {
                    echo '<span style="line-height:2;">\xe2\x80\xa6</span>';
                }
                echo '<a href="' . esc_url( add_query_arg( array_merge( $page_args, [ 'ts_paged' => $total_pages ] ), $base_url ) ) . '" class="button button-small">' . (int) $total_pages . '</a>';
            }
            echo '</div>';
        }

        echo '<p style="color:#6b7280;font-size:12px;margin-top:8px;">'
            . esc_html__( 'Delete actions are permanent and cannot be undone. Exported seeds can be re-imported via CSV Manager.', 'tmwseo' )
            . '</p>';
    }

    // -------------------------------------------------------------------------
    // Trusted Seed Detail Panel (v5.3.1)
    // -------------------------------------------------------------------------

    /**
     * Render a full-detail panel for a single trusted seed row.
     * Called from render_tab_trusted_seeds() when ?ts_view=N is present.
     * The main table still renders below after this returns.
     */
    private static function render_trusted_seed_detail( int $seed_id ): void {
        $repo = \TMWSEO\Engine\Admin\KeywordDataRepository::class;
        $row  = $repo::get_seed_by_id( $seed_id );

        $back_args = array_filter( [
            'page'       => self::PAGE_SLUG,
            'tab'        => 'trusted_seeds',
            'ts_search'  => sanitize_text_field( (string) ( $_GET['ts_search']  ?? '' ) ),
            'ts_source'  => sanitize_key( (string)         ( $_GET['ts_source']  ?? '' ) ),
            'ts_stype'   => sanitize_text_field( (string) ( $_GET['ts_stype']   ?? '' ) ),
            'ts_etype'   => sanitize_key( (string)         ( $_GET['ts_etype']   ?? '' ) ),
            'ts_batch'   => sanitize_text_field( (string) ( $_GET['ts_batch']   ?? '' ) ),
            'ts_slabel'  => sanitize_text_field( (string) ( $_GET['ts_slabel']  ?? '' ) ),
            'ts_orderby' => sanitize_key( (string)         ( $_GET['ts_orderby'] ?? '' ) ),
            'ts_order'   => sanitize_key( (string)         ( $_GET['ts_order']   ?? '' ) ),
            'ts_paged'   => (string) max( 1, (int) ( $_GET['ts_paged'] ?? 1 ) ),
        ] );
        // Remove defaults so URL stays clean
        if ( ( $back_args['ts_orderby'] ?? '' ) === 'created_at' ) { unset( $back_args['ts_orderby'] ); }
        if ( ( $back_args['ts_order']   ?? '' ) === 'DESC'        ) { unset( $back_args['ts_order'] ); }
        if ( ( $back_args['ts_paged']   ?? '' ) === '1'           ) { unset( $back_args['ts_paged'] ); }

        $back_url = add_query_arg( $back_args, admin_url( 'admin.php' ) );

        echo '<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;border-radius:0 4px 4px 0;padding:16px 20px;margin-bottom:20px;">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">';
        echo '<h3 style="margin:0;font-size:15px;">';

        if ( ! $row ) {
            echo esc_html__( 'Seed not found', 'tmwseo' );
            echo '</h3>';
            echo '<a href="' . esc_url( $back_url ) . '" class="button">' . esc_html__( '← Back to list', 'tmwseo' ) . '</a>';
            echo '</div>';
            echo '<p>' . esc_html__( 'Seed ID #', 'tmwseo' ) . (int) $seed_id . ' ' . esc_html__( 'was not found. It may have been deleted.', 'tmwseo' ) . '</p>';
            echo '</div>';
            return;
        }

        echo esc_html__( 'Seed Detail', 'tmwseo' ) . ' &mdash; <strong>' . esc_html( (string) ( $row['seed'] ?? '' ) ) . '</strong> <span style="color:#6b7280;font-size:12px;">(ID ' . (int) $row['id'] . ')</span>';
        echo '</h3>';
        echo '<a href="' . esc_url( $back_url ) . '" class="button">' . esc_html__( '← Back to list', 'tmwseo' ) . '</a>';
        echo '</div>';

        // ── Two-column field grid ─────────────────────────────────────────────
        $field_groups = [
            'Identity' => [
                'id'           => [ 'ID',          'integer' ],
                'seed'         => [ 'Seed Phrase',  'text' ],
                'source'       => [ 'Source',       'code' ],
                'seed_type'    => [ 'Seed Type',    'text' ],
                'priority'     => [ 'Priority',     'integer' ],
                'entity_type'  => [ 'Entity Type',  'text' ],
                'entity_id'    => [ 'Entity ID',    'integer' ],
                'created_at'   => [ 'Created',      'datetime' ],
            ],
            'Activity & Performance' => [
                'last_used'             => [ 'Last Used',          'datetime' ],
                'last_expanded_at'      => [ 'Last Expanded',      'datetime' ],
                'last_provider'         => [ 'Last Provider',      'text' ],
                'expansion_count'       => [ 'Expansion Count',    'integer' ],
                'net_new_yielded'       => [ 'Net New Yielded',    'integer' ],
                'duplicates_returned'   => [ 'Duplicates Returned','integer' ],
                'estimated_spend_usd'   => [ 'Estimated Spend USD','money' ],
                'roi_score'             => [ 'ROI Score',          'decimal' ],
                'consecutive_zero_yield'=> [ 'Consec. Zero Yield', 'integer' ],
                'cooldown_until'        => [ 'Cooldown Until',     'datetime' ],
            ],
            'Import Provenance' => [
                'import_batch_id'     => [ 'Import Batch ID',    'code' ],
                'import_source_label' => [ 'Import Source Label','text' ],
            ],
        ];

        $has_prov = $repo::has_provenance_columns();

        foreach ( $field_groups as $group_label => $fields ) {
            if ( $group_label === 'Import Provenance' && ! $has_prov ) {
                continue;
            }
            echo '<h4 style="margin:16px 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;">' . esc_html( $group_label ) . '</h4>';
            echo '<dl style="display:grid;grid-template-columns:1fr 1fr;gap:6px 24px;margin:0;">';

            foreach ( $fields as $field => $meta ) {
                [ $label, $type ] = $meta;
                $raw_val = $row[ $field ] ?? null;

                // Format value
                if ( $raw_val === null || $raw_val === '' ) {
                    $display = '<span style="color:#9ca3af;">—</span>';
                } elseif ( $type === 'code' ) {
                    $display = '<code>' . esc_html( (string) $raw_val ) . '</code>';
                } elseif ( $type === 'integer' ) {
                    $display = '<strong>' . number_format( (int) $raw_val ) . '</strong>';
                } elseif ( $type === 'decimal' ) {
                    $display = number_format( (float) $raw_val, 4 );
                } elseif ( $type === 'money' ) {
                    $display = '<strong>$' . number_format( (float) $raw_val, 4 ) . '</strong>';
                } elseif ( $type === 'datetime' ) {
                    $display = '<span style="font-variant-numeric:tabular-nums;">' . esc_html( (string) $raw_val ) . '</span>';
                } else {
                    $display = esc_html( (string) $raw_val );
                }

                echo '<div style="padding:5px 0;border-bottom:1px solid #f0f0f0;">';
                echo '<dt style="font-size:11px;color:#6b7280;margin-bottom:2px;">' . esc_html( $label ) . '</dt>';
                echo '<dd style="margin:0;font-size:13px;">' . wp_kses_post( $display ) . '</dd>';
                echo '</div>';
            }

            echo '</dl>';
        }

        // ── Action buttons ────────────────────────────────────────────────────
        $del_url = wp_nonce_url(
            add_query_arg(
                [ 'action' => 'tmwseo_trusted_seed_delete', 'seed_id' => (int) $row['id'] ],
                admin_url( 'admin-post.php' )
            ),
            'tmwseo_trusted_seed_delete_' . (int) $row['id']
        );

        echo '<div style="margin-top:16px;display:flex;gap:8px;">';
        echo '<a class="button" href="' . esc_url( $back_url ) . '">' . esc_html__( '← Back to list', 'tmwseo' ) . '</a>';
        echo '<a class="button" style="color:#b91c1c;border-color:#fca5a5;" href="' . esc_url( $del_url ) . '"'
            . ' onclick="return confirm(\'' . esc_js( sprintf( __( 'Permanently delete seed "%s"? This cannot be undone.', 'tmwseo' ), (string) ( $row['seed'] ?? '' ) ) ) . '\');">'
            . esc_html__( 'Delete this seed', 'tmwseo' ) . '</a>';
        echo '</div>';

        echo '</div>'; // end detail panel
    }


    // -------------------------------------------------------------------------
    // Tab: Expansion Candidates Explorer (v5.3)
    // -------------------------------------------------------------------------

    private static function render_tab_candidates(): void {
        $repo = \TMWSEO\Engine\Admin\KeywordDataRepository::class;

        if ( ! $repo::candidates_table_exists() ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'tmw_seed_expansion_candidates table not found.', 'tmwseo' ) . '</p></div>';
            return;
        }

        echo '<h2>' . esc_html__( 'Expansion Candidates Explorer', 'tmwseo' ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'Read-only view of all rows in tmw_seed_expansion_candidates. Use the Expansion Preview tab for approve/reject actions.', 'tmwseo' ) . '</p>';

        $base_url     = add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'candidates' ], admin_url( 'admin.php' ) );
        $search       = sanitize_text_field( (string) ( $_GET['cand_search'] ?? '' ) );
        $f_source     = sanitize_key( (string)        ( $_GET['cand_source'] ?? '' ) );
        $f_status     = sanitize_key( (string)        ( $_GET['cand_status'] ?? '' ) );
        $f_batch      = sanitize_text_field( (string) ( $_GET['cand_batch']  ?? '' ) );
        $orderby      = sanitize_key( (string)        ( $_GET['cand_ob']     ?? 'created_at' ) );
        $order        = strtoupper( sanitize_key( (string) ( $_GET['cand_ord'] ?? 'DESC' ) ) ) === 'ASC' ? 'ASC' : 'DESC';
        $per_page     = 50;
        $current_page = max( 1, (int) ( $_GET['cand_paged'] ?? 1 ) );
        $offset       = ( $current_page - 1 ) * $per_page;

        $filters = [ 'search' => $search, 'source' => $f_source, 'status' => $f_status, 'batch_id' => $f_batch ];

        echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
        echo '<input type="hidden" name="tab" value="candidates">';
        echo '<input type="search" name="cand_search" value="' . esc_attr( $search ) . '" placeholder="Search phrase..." style="width:220px;">';

        $sources = $repo::distinct_candidate_sources();
        if ( ! empty( $sources ) ) {
            echo '<select name="cand_source"><option value="">All sources</option>';
            foreach ( $sources as $src ) { echo '<option value="' . esc_attr( $src ) . '"' . selected( $f_source, $src, false ) . '>' . esc_html( $src ) . '</option>'; }
            echo '</select>';
        }

        echo '<select name="cand_status"><option value="">All statuses</option>';
        foreach ( [ 'pending', 'fast_track', 'approved', 'rejected', 'archived' ] as $st ) {
            echo '<option value="' . esc_attr( $st ) . '"' . selected( $f_status, $st, false ) . '>' . esc_html( $st ) . '</option>';
        }
        echo '</select>';

        $batch_ids = $repo::distinct_candidate_batch_ids();
        if ( ! empty( $batch_ids ) ) {
            echo '<select name="cand_batch"><option value="">All batches</option><option value="__none__"' . selected( $f_batch, '__none__', false ) . '>(no batch)</option>';
            foreach ( $batch_ids as $bid ) { echo '<option value="' . esc_attr( $bid ) . '"' . selected( $f_batch, $bid, false ) . '>' . esc_html( $bid ) . '</option>'; }
            echo '</select>';
        }

        echo '<button type="submit" class="button">Filter</button>';
        if ( array_filter( $filters ) ) { echo '<a href="' . esc_url( $base_url ) . '" class="button">Clear</a>'; }
        echo '</form>';

        // Status count badges
        $status_counts = ExpansionCandidateRepository::count_by_status();
        $status_colors = [ 'pending' => [ '#fef3c7','#92400e' ], 'fast_track' => [ '#ede9fe','#5b21b6' ], 'approved' => [ '#dcfce7','#15803d' ], 'rejected' => [ '#fee2e2','#991b1b' ], 'archived' => [ '#f1f5f9','#475569' ] ];
        echo '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;">';
        foreach ( $status_counts as $st => $cnt ) {
            [ $bg, $fg ] = $status_colors[ $st ] ?? [ '#f1f5f9','#374151' ];
            echo '<a href="' . esc_url( add_query_arg( [ 'cand_status' => $st ], $base_url ) ) . '" style="background:' . esc_attr( $bg ) . ';color:' . esc_attr( $fg ) . ';border:1px solid currentColor;border-radius:4px;padding:4px 10px;font-size:12px;font-weight:600;text-decoration:none;">' . esc_html( $st ) . ' <strong>' . (int) $cnt . '</strong></a>';
        }
        echo '</div>';

        $total_rows  = $repo::count_candidates( $filters );
        $total_pages = (int) ceil( $total_rows / $per_page );
        $showing_from = $total_rows > 0 ? $offset + 1 : 0;
        $showing_to   = min( $offset + $per_page, $total_rows );

        $export_url = wp_nonce_url( add_query_arg( array_merge(
            [ 'action' => 'tmwseo_candidates_export' ],
            array_filter( [ 'cand_search' => $search, 'cand_source' => $f_source, 'cand_status' => $f_status, 'cand_batch' => $f_batch ] )
        ), admin_url( 'admin-post.php' ) ), 'tmwseo_candidates_export' );
        $preview_url = add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'preview' ], admin_url( 'admin.php' ) );

        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px;">';
        echo '<p style="margin:0;color:#6b7280;font-size:13px;">' . esc_html( sprintf( 'Showing %1$d-%2$d of %3$d candidates', $showing_from, $showing_to, $total_rows ) ) . '</p>';
        echo '<div style="display:flex;gap:6px;">';
        echo '<a class="button" href="' . esc_url( $export_url ) . '">Export ' . (int) $total_rows . ' rows as CSV</a>';
        echo '<a class="button" href="' . esc_url( $preview_url ) . '">Approve / Reject in Preview tab</a>';
        echo '</div></div>';

        if ( $total_rows === 0 ) { return; }

        $rows = $repo::get_candidates( $filters, $orderby, $order, $per_page, $offset );

        $sort_col = static function( string $col, string $label ) use ( $base_url, $orderby, $order, $filters ): string {
            $no = ( $orderby === $col && $order === 'ASC' ) ? 'DESC' : 'ASC';
            $url = add_query_arg( array_merge( $filters, [ 'cand_ob' => $col, 'cand_ord' => $no ] ), $base_url );
            $arrow = $orderby === $col ? ( $order === 'ASC' ? ' ^' : ' v' ) : '';
            return '<a href="' . esc_url( $url ) . '" style="color:inherit;text-decoration:none;">' . esc_html( $label . $arrow ) . '</a>';
        };
        $sbadge = static function( string $status ): string {
            $map = [ 'pending' => 'background:#fef3c7;color:#92400e;', 'fast_track' => 'background:#ede9fe;color:#5b21b6;', 'approved' => 'background:#dcfce7;color:#15803d;', 'rejected' => 'background:#fee2e2;color:#991b1b;', 'archived' => 'background:#f1f5f9;color:#475569;' ];
            $style = $map[$status] ?? 'background:#f1f5f9;color:#374151;';
            return '<span style="' . esc_attr( $style ) . 'border-radius:3px;padding:2px 6px;font-size:11px;font-weight:700;">' . esc_html( $status ) . '</span>';
        };

        echo '<div style="overflow-x:auto;"><table class="widefat striped" style="font-size:12px;min-width:1400px;"><thead><tr style="white-space:nowrap;">';
        $ccols = [ 'id' => 'ID', 'phrase' => 'Phrase', 'source' => 'Source', 'generation_rule' => 'Gen. Rule', 'entity_type' => 'Entity Type', 'entity_id' => 'Entity ID', 'batch_id' => 'Batch ID', 'status' => 'Status', 'quality_score' => 'Quality', 'duplicate_flag' => 'Dup?', 'intent_guess' => 'Intent', 'created_at' => 'Created', 'reviewed_at' => 'Reviewed At', 'reviewed_by' => 'Reviewed By', 'rejection_reason' => 'Rejection Reason' ];
        foreach ( $ccols as $col => $label ) { echo '<th>' . $sort_col( $col, $label ) . '</th>'; }
        echo '</tr></thead><tbody>';

        foreach ( $rows as $r ) {
            echo '<tr>';
            echo '<td>' . (int) ( $r['id'] ?? 0 ) . '</td>';
            echo '<td><strong>' . esc_html( (string) ( $r['phrase'] ?? '' ) ) . '</strong></td>';
            echo '<td><code>' . esc_html( (string) ( $r['source'] ?? '' ) ) . '</code></td>';
            echo '<td style="font-size:11px;color:#6b7280;">' . esc_html( (string) ( $r['generation_rule'] ?? '' ) ?: '-' ) . '</td>';
            echo '<td>' . esc_html( (string) ( $r['entity_type'] ?? '-' ) ) . '</td>';
            echo '<td>' . (int) ( $r['entity_id'] ?? 0 ) . '</td>';
            echo '<td style="font-size:11px;"><code>' . esc_html( (string) ( $r['batch_id'] ?? '' ) ?: '-' ) . '</code></td>';
            echo '<td>' . $sbadge( (string) ( $r['status'] ?? 'pending' ) ) . '</td>';
            $qs = $r['quality_score'] ?? null;
            echo '<td>' . ( $qs !== null ? number_format( (float) $qs, 1 ) : '-' ) . '</td>';
            echo '<td>' . ( (int) ( $r['duplicate_flag'] ?? 0 ) ? '<span style="color:#dc2626;">Yes</span>' : 'No' ) . '</td>';
            echo '<td>' . esc_html( (string) ( $r['intent_guess'] ?? '' ) ?: '-' ) . '</td>';
            echo '<td style="white-space:nowrap;">' . esc_html( (string) ( $r['created_at'] ?? '-' ) ) . '</td>';
            echo '<td style="white-space:nowrap;">' . esc_html( (string) ( $r['reviewed_at'] ?? '' ) ?: '-' ) . '</td>';
            echo '<td>' . (int) ( $r['reviewed_by'] ?? 0 ) . '</td>';
            echo '<td style="max-width:200px;word-break:break-word;font-size:11px;color:#6b7280;">' . esc_html( (string) ( $r['rejection_reason'] ?? '' ) ?: '-' ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        // Pagination
        if ( $total_pages > 1 ) {
            $page_args = array_merge( $filters, [ 'cand_ob' => $orderby, 'cand_ord' => $order ] );
            echo '<div style="margin-top:12px;display:flex;gap:6px;flex-wrap:wrap;">';
            $range = range( max( 1, $current_page - 4 ), min( $total_pages, $current_page + 4 ) );
            if ( ! in_array( 1, $range, true ) ) { echo '<a href="' . esc_url( add_query_arg( array_merge( $page_args, [ 'cand_paged' => 1 ] ), $base_url ) ) . '" class="button button-small">1</a>'; }
            foreach ( $range as $p ) {
                $style = ( $p === $current_page ) ? 'font-weight:700;' : '';
                echo '<a href="' . esc_url( add_query_arg( array_merge( $page_args, [ 'cand_paged' => $p ] ), $base_url ) ) . '" class="button button-small" style="' . esc_attr( $style ) . '">' . (int) $p . '</a>';
            }
            if ( ! in_array( $total_pages, $range, true ) ) { echo '<a href="' . esc_url( add_query_arg( array_merge( $page_args, [ 'cand_paged' => $total_pages ] ), $base_url ) ) . '" class="button button-small">' . (int) $total_pages . '</a>'; }
            echo '</div>';
        }
        echo '<p style="color:#6b7280;font-size:12px;margin-top:8px;">Candidates are read-only in this view. Use the Expansion Preview tab to approve, reject, or archive rows.</p>';
    }

}
