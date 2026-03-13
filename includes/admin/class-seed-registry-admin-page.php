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
                    SeedRegistry::register_trusted_seed( $phrase, 'manual', 'system', 0 );
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
        }

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'updated' => '1' ],
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
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'tmwseo' ) . '</p></div>';
        }

        // Tab nav
        $tabs = [
            'registry' => __( 'Registry', 'tmwseo' ),
            'preview'  => __( 'Expansion Preview', 'tmwseo' ),
            'history'  => __( 'Import History', 'tmwseo' ),
            'builders' => __( 'Auto Builders', 'tmwseo' ),
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

        echo '<h2>' . esc_html__( 'Trusted Root Seeds', 'tmwseo' ) . '</h2>';
        echo '<table class="widefat striped">';
        echo '<tr><th>' . esc_html__( 'Total trusted seeds', 'tmwseo' ) . '</th><td><strong>' . (int) $diag['total_seeds'] . '</strong></td></tr>';
        echo '<tr><th>' . esc_html__( 'Used this discovery cycle', 'tmwseo' ) . '</th><td>' . (int) $diag['seeds_used_this_cycle'] . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Duplicates prevented (lifetime)', 'tmwseo' ) . '</th><td>' . (int) $diag['duplicate_prevention_count'] . '</td></tr>';
        echo '</table>';

        echo '<h3 style="margin-top:24px">' . esc_html__( 'Seeds by source', 'tmwseo' ) . '</h3>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Source', 'tmwseo' ) . '</th><th>' . esc_html__( 'Count', 'tmwseo' ) . '</th><th>' . esc_html__( 'Trusted?', 'tmwseo' ) . '</th></tr></thead><tbody>';
        foreach ( (array) $diag['seed_sources'] as $source => $count ) {
            $trusted = SeedRegistry::is_trusted_source( $source )
                ? '<span style="color:#2ecc71;font-weight:bold">✓ trusted</span>'
                : '<span style="color:#e74c3c;font-weight:bold">✗ NOT trusted (legacy rows)</span>';
            printf(
                '<tr><td>%s</td><td>%d</td><td>%s</td></tr>',
                esc_html( $source ),
                (int) $count,
                $trusted
            );
        }
        echo '</tbody></table>';

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
}
