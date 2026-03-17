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

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SeedRegistryAdminPage {

    private const PAGE_SLUG = 'tmwseo-seed-registry';

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_post_tmwseo_seed_registry_action', [ __CLASS__, 'handle_post_action' ] );
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

        // Tab nav
        $tabs = [
            'registry' => __( 'Registry', 'tmwseo' ),
            'preview'  => __( 'Expansion Preview', 'tmwseo' ),
            'history'  => __( 'Import History', 'tmwseo' ),
            'builders' => __( 'Auto Builders', 'tmwseo' ),
            'reset'    => __( 'Reset & Starter Pack', 'tmwseo' ),
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

        echo '<h2>' . esc_html__( 'Trusted Root Seeds', 'tmwseo' ) . '</h2>';
        echo '<table class="widefat striped">';
        echo '<tr><th>' . esc_html__( 'Total trusted seeds', 'tmwseo' ) . '</th><td><strong>' . (int) $diag['total_seeds'] . '</strong></td></tr>';
        echo '<tr><th>' . esc_html__( 'Discovery enabled', 'tmwseo' ) . '</th><td>' . ( $gov_enabled ? '<span style="color:#2ecc71;font-weight:bold">✓ Yes</span>' : '<span style="color:#e74c3c;font-weight:bold">✗ DISABLED — seeds will not be used</span>' ) . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Used this discovery cycle', 'tmwseo' ) . '</th><td>' . (int) $diag['seeds_used_this_cycle'] . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Duplicates prevented (lifetime)', 'tmwseo' ) . '</th><td>' . (int) $diag['duplicate_prevention_count'] . '</td></tr>';
        echo '</table>';

        echo '<h3 style="margin-top:24px">' . esc_html__( 'Seeds by source', 'tmwseo' ) . '</h3>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Source', 'tmwseo' ) . '</th><th>' . esc_html__( 'Count', 'tmwseo' ) . '</th><th>' . esc_html__( 'Trusted?', 'tmwseo' ) . '</th><th>' . esc_html__( 'Note', 'tmwseo' ) . '</th></tr></thead><tbody>';
        foreach ( (array) $diag['seed_sources'] as $source => $count ) {
            $trusted = SeedRegistry::is_trusted_source( $source )
                ? '<span style="color:#2ecc71;font-weight:bold">✓ trusted</span>'
                : '<span style="color:#e74c3c;font-weight:bold">✗ NOT trusted (legacy rows)</span>';
            $note = '';
            if ( $source === 'csv_import' ) {
                $note = '<small style="color:#6b7280;">Legacy alias for approved_import. These are CSV-imported rows from older plugin versions.</small>';
            } elseif ( $source === 'approved_import' ) {
                $note = '<small style="color:#6b7280;">Current CSV/API import source. Cleanup available via CSV Manager → DB Import History.</small>';
            }
            printf(
                '<tr><td><code>%s</code></td><td>%d</td><td>%s</td><td>%s</td></tr>',
                esc_html( $source ),
                (int) $count,
                $trusted,
                wp_kses_post( $note )
            );
        }
        echo '</tbody></table>';

        // ── Import batches breakdown ───────────────────────────────────────
        self::render_import_batches_section();

        if ( ! empty( $diag['preview_queue'] ) ) {
            echo '<h3 style="margin-top:24px">' . esc_html__( 'Preview Queue Summary', 'tmwseo' ) . '</h3>';
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Status', 'tmwseo' ) . '</th><th>' . esc_html__( 'Count', 'tmwseo' ) . '</th></tr></thead><tbody>';
            foreach ( $diag['preview_queue'] as $status => $count ) {
                printf( '<tr><td>%s</td><td>%d</td></tr>', esc_html( $status ), (int) $count );
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
     */
    private static function render_import_batches_section(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'tmwseo_seeds';
        $table_exists = ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
        if ( ! $table_exists ) {
            return;
        }

        // Check whether provenance columns exist (added in 4.3.0 migration).
        $columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
        $col_names = is_array( $columns ) ? array_column( $columns, 'Field' ) : [];
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
        echo '<p class="description">' . esc_html( sprintf( __( '%d total imported seeds (approved_import + csv_import). Use CSV Manager → DB Import History for batch deletion.', 'tmwseo' ), $import_total ) ) . '</p>';

        if ( empty( $batches ) ) {
            echo '<p>' . esc_html__( 'No imported seed batches found.', 'tmwseo' ) . '</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        foreach ( [ 'Batch ID', 'Source Label', 'Source Type', 'Rows', 'Date Range' ] as $h ) {
            echo '<th>' . esc_html( $h ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( $batches as $b ) {
            $bid = (string) $b['batch_id'];
            echo '<tr>';
            echo '<td>' . ( $bid !== '' ? '<code>' . esc_html( $bid ) . '</code>' : '<em>legacy (no batch ID)</em>' ) . '</td>';
            echo '<td>' . esc_html( (string) $b['source_label'] ?: '—' ) . '</td>';
            echo '<td><code>' . esc_html( (string) $b['source'] ) . '</code></td>';
            echo '<td><strong>' . (int) $b['row_count'] . '</strong></td>';
            echo '<td><small>' . esc_html( (string) $b['earliest'] ) . ' — ' . esc_html( (string) $b['latest'] ) . '</small></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Link to CSV Manager for cleanup
        $csv_url = admin_url( 'admin.php?page=tmwseo-csv-manager' );
        echo '<p style="margin-top:8px;"><a href="' . esc_url( $csv_url ) . '" class="button button-small">' . esc_html__( 'Go to CSV Manager for batch cleanup →', 'tmwseo' ) . '</a></p>';
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

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
        echo '<input type="hidden" name="tab" value="preview">';
        if ( $status_filter !== '' ) {
            echo '<input type="hidden" name="status" value="' . esc_attr( $status_filter ) . '">';
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
                '<button type="submit" class="button button-small" onclick="return confirm(\'%s\');document.getElementById(\'tmwseo_history_action\').value=\'rollback_batch\';document.getElementById(\'tmwseo_history_batch_id\').value=\'%s\';">%s</button>',
                esc_attr__( 'Roll back this entire batch?', 'tmwseo' ),
                esc_attr( $batch_id ),
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
}
