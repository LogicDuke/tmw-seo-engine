<?php
/**
 * CategoryFormulaAdminPage — Admin interface for Category Formulas.
 *
 * Registers all form POST handlers and renders the 3-tab admin page:
 *   Tab 1 — Signal Groups (CRUD + mapped terms)
 *   Tab 2 — Formulas (CRUD + dry run + backfill)
 *   Tab 3 — Backfill Log
 *
 * Responsibilities of this class:
 *  - Hook registration (init)
 *  - Form POST handling with nonce + capability checks
 *  - Orchestrate rendering
 *
 * Does NOT contain any matching or DB logic — those live in the engine/repository layer.
 *
 * @package TMWSEO\Engine\Admin
 * @since   5.2.0
 */
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\CategoryFormulas\SensitiveTagPolicy;
use TMWSEO\Engine\CategoryFormulas\SignalGroupRepository;
use TMWSEO\Engine\CategoryFormulas\CategoryFormulaRepository;
use TMWSEO\Engine\CategoryFormulas\CategoryFormulaEngine;
use TMWSEO\Engine\CategoryFormulas\CategoryBackfillRunner;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CategoryFormulaAdminPage {

    const PAGE_SLUG = 'tmwseo-category-formulas';
    const CAP       = 'manage_options';

    // ── Bootstrap ─────────────────────────────────────────────────────────────

    public static function init(): void {
        add_action( 'admin_post_tmwseo_save_signal_group',      [ __CLASS__, 'handle_save_signal_group' ] );
        add_action( 'admin_post_tmwseo_delete_signal_group',    [ __CLASS__, 'handle_delete_signal_group' ] );
        add_action( 'admin_post_tmwseo_save_category_formula',  [ __CLASS__, 'handle_save_formula' ] );
        add_action( 'admin_post_tmwseo_delete_category_formula',[ __CLASS__, 'handle_delete_formula' ] );
        add_action( 'admin_post_tmwseo_formula_dry_run',        [ __CLASS__, 'handle_dry_run' ] );
        add_action( 'admin_post_tmwseo_formula_backfill',       [ __CLASS__, 'handle_backfill' ] );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function require_caps(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Permission denied.', 'tmwseo' ) );
        }
    }

    /** @return string */
    private static function page_url( array $args = [] ): string {
        return add_query_arg( array_merge( [ 'page' => self::PAGE_SLUG ], $args ), admin_url( 'admin.php' ) );
    }

    private static function redirect( array $args = [] ): void {
        wp_safe_redirect( self::page_url( $args ) );
        exit;
    }

    /** @return SignalGroupRepository */
    private static function group_repo(): SignalGroupRepository {
        return new SignalGroupRepository();
    }

    /** @return CategoryFormulaRepository */
    private static function formula_repo(): CategoryFormulaRepository {
        return new CategoryFormulaRepository();
    }

    /** @return CategoryFormulaEngine */
    private static function engine(): CategoryFormulaEngine {
        return new CategoryFormulaEngine( self::group_repo(), self::formula_repo() );
    }

    /** @return CategoryBackfillRunner */
    private static function runner(): CategoryBackfillRunner {
        return new CategoryBackfillRunner( self::engine(), self::formula_repo() );
    }

    // ── Signal Group handlers ─────────────────────────────────────────────────

    public static function handle_save_signal_group(): void {
        self::require_caps();
        check_admin_referer( 'tmwseo_save_signal_group' );

        $id        = (int) ( $_POST['group_id'] ?? 0 );
        $group_key = sanitize_key( $_POST['group_key'] ?? '' );
        $label     = sanitize_text_field( $_POST['group_label'] ?? '' );
        $taxonomy  = sanitize_key( $_POST['group_taxonomy'] ?? 'post_tag' );
        $status    = sanitize_key( $_POST['group_status'] ?? 'active' );
        $term_ids  = array_map( 'intval', (array) ( $_POST['group_term_ids'] ?? [] ) );

        // Validation.
        if ( empty( $group_key ) || empty( $label ) ) {
            self::redirect( [ 'tab' => 'groups', 'error' => 'empty_fields', 'edit_group' => $id ?: '' ] );
        }

        $policy = SensitiveTagPolicy::validate( $label, 'group label' );
        if ( is_wp_error( $policy ) ) {
            self::redirect( [ 'tab' => 'groups', 'error' => 'blocked_label', 'edit_group' => $id ?: '' ] );
        }

        $policy_key = SensitiveTagPolicy::validate( $group_key, 'group key' );
        if ( is_wp_error( $policy_key ) ) {
            self::redirect( [ 'tab' => 'groups', 'error' => 'blocked_label', 'edit_group' => $id ?: '' ] );
        }

        if ( empty( $term_ids ) ) {
            self::redirect( [ 'tab' => 'groups', 'error' => 'no_terms', 'edit_group' => $id ?: '' ] );
        }

        $repo = self::group_repo();

        // Check uniqueness.
        $existing = $repo->get_by_key( $group_key );
        if ( $existing && (int) $existing->id !== $id ) {
            self::redirect( [ 'tab' => 'groups', 'error' => 'duplicate_key', 'edit_group' => $id ?: '' ] );
        }

        $data = compact( 'group_key', 'label', 'status' );

        if ( $id > 0 ) {
            $repo->update( $id, $data );
            $repo->sync_terms( $id, $term_ids, $taxonomy );
            self::redirect( [ 'tab' => 'groups', 'saved' => '1' ] );
        } else {
            $new_id = $repo->create( $data );
            if ( $new_id ) {
                $repo->sync_terms( $new_id, $term_ids, $taxonomy );
                self::redirect( [ 'tab' => 'groups', 'saved' => '1' ] );
            } else {
                self::redirect( [ 'tab' => 'groups', 'error' => 'db_error' ] );
            }
        }
    }

    public static function handle_delete_signal_group(): void {
        self::require_caps();
        check_admin_referer( 'tmwseo_delete_signal_group' );

        $id = (int) ( $_POST['group_id'] ?? 0 );
        if ( $id > 0 ) {
            self::group_repo()->delete( $id );
        }
        self::redirect( [ 'tab' => 'groups', 'deleted' => '1' ] );
    }

    // ── Formula handlers ──────────────────────────────────────────────────────

    public static function handle_save_formula(): void {
        self::require_caps();
        check_admin_referer( 'tmwseo_save_category_formula' );

        $id               = (int) ( $_POST['formula_id'] ?? 0 );
        $formula_key      = sanitize_key( $_POST['formula_key'] ?? '' );
        $label            = sanitize_text_field( $_POST['formula_label'] ?? '' );
        $target_taxonomy  = sanitize_key( $_POST['target_taxonomy'] ?? 'category' );
        $target_term_id   = (int) ( $_POST['target_term_id'] ?? 0 );
        $source_taxonomy  = sanitize_key( $_POST['source_taxonomy'] ?? 'post_tag' );
        $post_type        = sanitize_key( $_POST['formula_post_type'] ?? 'post' );
        $status           = sanitize_key( $_POST['formula_status'] ?? 'active' );
        $notes            = sanitize_textarea_field( $_POST['formula_notes'] ?? '' );
        $required_groups  = array_map( 'intval', (array) ( $_POST['required_groups'] ?? [] ) );
        $excluded_groups  = array_map( 'intval', (array) ( $_POST['excluded_groups'] ?? [] ) );

        if ( empty( $formula_key ) || empty( $label ) ) {
            self::redirect( [ 'tab' => 'formulas', 'error' => 'empty_fields', 'edit_formula' => $id ?: '' ] );
        }

        foreach ( [ 'formula label' => $label, 'formula key' => $formula_key ] as $field => $val ) {
            $policy = SensitiveTagPolicy::validate( $val, $field );
            if ( is_wp_error( $policy ) ) {
                self::redirect( [ 'tab' => 'formulas', 'error' => 'blocked_label', 'edit_formula' => $id ?: '' ] );
            }
        }

        if ( $target_term_id <= 0 ) {
            self::redirect( [ 'tab' => 'formulas', 'error' => 'no_target_term', 'edit_formula' => $id ?: '' ] );
        }

        // Verify target term exists.
        $target_term = get_term( $target_term_id, $target_taxonomy );
        if ( ! $target_term || is_wp_error( $target_term ) ) {
            self::redirect( [ 'tab' => 'formulas', 'error' => 'term_not_found', 'edit_formula' => $id ?: '' ] );
        }

        if ( empty( $required_groups ) ) {
            self::redirect( [ 'tab' => 'formulas', 'error' => 'no_required_groups', 'edit_formula' => $id ?: '' ] );
        }

        $repo     = self::formula_repo();
        $existing = $repo->get_by_key( $formula_key );
        if ( $existing && (int) $existing->id !== $id ) {
            self::redirect( [ 'tab' => 'formulas', 'error' => 'duplicate_key', 'edit_formula' => $id ?: '' ] );
        }

        $data = compact( 'formula_key', 'label', 'target_taxonomy', 'target_term_id', 'source_taxonomy', 'post_type', 'status', 'notes' );

        if ( $id > 0 ) {
            $repo->update( $id, $data );
            $repo->sync_conditions( $id, $required_groups, $excluded_groups );
            self::redirect( [ 'tab' => 'formulas', 'saved' => '1' ] );
        } else {
            $new_id = $repo->create( $data );
            if ( $new_id ) {
                $repo->sync_conditions( $new_id, $required_groups, $excluded_groups );
                self::redirect( [ 'tab' => 'formulas', 'saved' => '1' ] );
            } else {
                self::redirect( [ 'tab' => 'formulas', 'error' => 'db_error' ] );
            }
        }
    }

    public static function handle_delete_formula(): void {
        self::require_caps();
        check_admin_referer( 'tmwseo_delete_category_formula' );

        $id = (int) ( $_POST['formula_id'] ?? 0 );
        if ( $id > 0 ) {
            self::formula_repo()->delete( $id );
        }
        self::redirect( [ 'tab' => 'formulas', 'deleted' => '1' ] );
    }

    public static function handle_dry_run(): void {
        self::require_caps();
        check_admin_referer( 'tmwseo_formula_dry_run' );

        $formula_id = (int) ( $_POST['formula_id'] ?? 0 );
        $formula    = self::formula_repo()->get_by_id( $formula_id );
        if ( ! $formula ) {
            self::redirect( [ 'tab' => 'formulas', 'error' => 'formula_not_found' ] );
        }

        $result = self::engine()->dry_run( $formula );
        self::formula_repo()->update_dry_run_stats( $formula_id, $result['matched'], $result['missing_count'] );

        // Store dry run result in a transient for rendering on the next page load.
        set_transient( 'tmwseo_dry_run_result_' . get_current_user_id(), [
            'formula_id' => $formula_id,
            'result'     => $result,
        ], 300 );

        self::redirect( [ 'tab' => 'formulas', 'dry_run_done' => '1', 'formula_id' => $formula_id ] );
    }

    public static function handle_backfill(): void {
        self::require_caps();
        check_admin_referer( 'tmwseo_formula_backfill' );

        $formula_id      = (int) ( $_POST['formula_id'] ?? 0 );
        $offset          = (int) ( $_POST['backfill_offset'] ?? 0 );
        // Always read changed_so_far from $_POST — it is passed as a hidden field
        // in the continue form and in the initial submission.
        $prev_changed    = (int) ( $_POST['changed_so_far'] ?? 0 );
        $formula         = self::formula_repo()->get_by_id( $formula_id );

        if ( ! $formula ) {
            self::redirect( [ 'tab' => 'formulas', 'error' => 'formula_not_found' ] );
        }

        $result = self::runner()->run( $formula, $offset, CategoryBackfillRunner::CHUNK_SIZE );

        if ( ! empty( $result['error'] ) ) {
            self::redirect( [ 'tab' => 'formulas', 'error' => esc_attr( $result['error'] ), 'formula_id' => $formula_id ] );
        }

        // Cumulative total for this chunk plus all previous chunks.
        $cumulative_changed = $prev_changed + $result['changed'];

        if ( $result['has_more'] ) {
            // Continue chunked run via redirect loop, passing cumulative total forward.
            self::redirect( [
                'tab'            => 'formulas',
                'backfill_cont'  => '1',
                'formula_id'     => $formula_id,
                'offset'         => $result['next_offset'],
                'changed_so_far' => $cumulative_changed,
            ] );
        }

        // Final chunk complete — persist the full cumulative changed count.
        self::formula_repo()->update_backfill_stats( $formula_id, $cumulative_changed );

        self::redirect( [ 'tab' => 'formulas', 'backfill_done' => '1', 'changed' => $cumulative_changed, 'formula_id' => $formula_id ] );
    }

    // ── Page renderer ─────────────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Permission denied.', 'tmwseo' ) );
        }

        $tab = sanitize_key( $_GET['tab'] ?? 'groups' );
        if ( ! in_array( $tab, [ 'groups', 'formulas', 'log' ], true ) ) {
            $tab = 'groups';
        }

        echo '<div class="wrap tmwseo-wrap">';
        echo '<h1>' . esc_html__( 'Category Formulas', 'tmwseo' ) . '</h1>';

        self::render_notices();
        self::render_tabs( $tab );

        switch ( $tab ) {
            case 'groups':
                self::render_groups_tab();
                break;
            case 'formulas':
                self::render_formulas_tab();
                break;
            case 'log':
                self::render_log_tab();
                break;
        }

        echo '</div>';
    }

    // ── Admin notices ─────────────────────────────────────────────────────────

    private static function render_notices(): void {
        // Success notices.
        if ( ! empty( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved successfully.', 'tmwseo' ) . '</p></div>';
        }
        if ( ! empty( $_GET['deleted'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Deleted.', 'tmwseo' ) . '</p></div>';
        }
        if ( ! empty( $_GET['dry_run_done'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Dry run complete. See results below.', 'tmwseo' ) . '</p></div>';
        }
        if ( ! empty( $_GET['backfill_done'] ) ) {
            $changed = (int) ( $_GET['changed'] ?? 0 );
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html( sprintf( __( 'Backfill complete. %d post(s) updated.', 'tmwseo' ), $changed ) )
                . '</p></div>';
        }
        if ( ! empty( $_GET['backfill_cont'] ) ) {
            $changed = (int) ( $_GET['changed_so_far'] ?? 0 );
            $formula_id = (int) ( $_GET['formula_id'] ?? 0 );
            $offset     = (int) ( $_GET['offset'] ?? 0 );
            echo '<div class="notice notice-info"><p>'
                . esc_html( sprintf( __( 'Backfill in progress… %d post(s) updated so far. Continue?', 'tmwseo' ), $changed ) )
                . ' ';
            // Auto-continue form.
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
            wp_nonce_field( 'tmwseo_formula_backfill' );
            echo '<input type="hidden" name="action" value="tmwseo_formula_backfill">';
            echo '<input type="hidden" name="formula_id" value="' . esc_attr( $formula_id ) . '">';
            echo '<input type="hidden" name="backfill_offset" value="' . esc_attr( $offset ) . '">';
            echo '<input type="hidden" name="changed_so_far" value="' . esc_attr( $changed ) . '">';
            echo '<button type="submit" class="button button-primary">' . esc_html__( 'Continue Backfill', 'tmwseo' ) . '</button>';
            echo '</form></p></div>';
        }

        // Error notices.
        $error_map = [
            'empty_fields'       => __( 'Please fill in all required fields.', 'tmwseo' ),
            'blocked_label'      => __( 'The label or key contains a blocked/sensitive term and cannot be used as a public label in this module.', 'tmwseo' ),
            'no_terms'           => __( 'At least one mapped term is required.', 'tmwseo' ),
            'duplicate_key'      => __( 'That key is already in use. Please choose a unique key.', 'tmwseo' ),
            'db_error'           => __( 'A database error occurred. Please try again.', 'tmwseo' ),
            'no_target_term'     => __( 'Please select a target category term.', 'tmwseo' ),
            'term_not_found'     => __( 'The selected target term no longer exists. Please create it in WordPress first.', 'tmwseo' ),
            'no_required_groups' => __( 'At least one required signal group is required.', 'tmwseo' ),
            'formula_not_found'  => __( 'Formula not found.', 'tmwseo' ),
            'invalid_target_term'=> __( 'Invalid target term ID on formula.', 'tmwseo' ),
            'target_term_not_found' => __( 'Target term no longer exists. Please update the formula.', 'tmwseo' ),
        ];
        $error = sanitize_key( $_GET['error'] ?? '' );
        if ( $error && isset( $error_map[ $error ] ) ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error_map[ $error ] ) . '</p></div>';
        }
    }

    // ── Tab navigation ────────────────────────────────────────────────────────

    private static function render_tabs( string $active ): void {
        $tabs = [
            'groups'   => __( 'Signal Groups', 'tmwseo' ),
            'formulas' => __( 'Formulas', 'tmwseo' ),
            'log'      => __( 'Backfill Log', 'tmwseo' ),
        ];
        echo '<nav class="nav-tab-wrapper">';
        foreach ( $tabs as $slug => $label ) {
            $class = ( $slug === $active ) ? 'nav-tab nav-tab-active' : 'nav-tab';
            echo '<a href="' . esc_url( self::page_url( [ 'tab' => $slug ] ) ) . '" class="' . esc_attr( $class ) . '">'
                . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
    }

    // ════════════════════════════════════════════════════════════════════════
    // TAB 1: Signal Groups
    // ════════════════════════════════════════════════════════════════════════

    private static function render_groups_tab(): void {
        $edit_id = (int) ( $_GET['edit_group'] ?? 0 );
        $repo    = self::group_repo();

        echo '<div class="tmwseo-section" style="margin-top:20px">';

        if ( $edit_id > 0 ) {
            $group = $repo->get_by_id( $edit_id );
            self::render_group_form( $group );
        } else {
            self::render_group_form( null );
        }

        echo '</div>';
        echo '<hr>';
        echo '<div class="tmwseo-section">';
        self::render_groups_list( $repo );
        echo '</div>';
    }

    private static function render_group_form( ?object $group ): void {
        $is_edit  = ( $group !== null );
        $repo     = self::group_repo();

        // Determine the default taxonomy from the group's saved terms.
        // Avoid direct [0]->taxonomy access — get_terms_for_group() may return an
        // empty array, and accessing ->taxonomy on null is a fatal in PHP 7.4.
        $saved_taxonomy = 'post_tag';
        if ( $is_edit ) {
            $saved_terms = $repo->get_terms_for_group( (int) $group->id );
            if ( ! empty( $saved_terms ) ) {
                $saved_taxonomy = $saved_terms[0]->taxonomy;
            }
        }
        // The GET param overrides the saved value (taxonomy switcher reload).
        $taxonomy = sanitize_key( isset( $_GET['group_taxonomy'] ) ? $_GET['group_taxonomy'] : $saved_taxonomy );

        // Fetch all terms in the selected taxonomy for the multi-select.
        $all_terms = get_terms( [
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'orderby'    => 'name',
            'number'     => 500,
        ] );
        $all_terms = is_wp_error( $all_terms ) ? [] : $all_terms;

        // Pre-select only those term IDs that belong to the currently displayed
        // taxonomy. Without this filter, IDs from a previously-saved taxonomy
        // could accidentally match IDs in the new taxonomy's term list.
        // Reuse $saved_terms fetched above — no second DB call needed.
        $selected_term_ids = [];
        if ( $is_edit ) {
            foreach ( $saved_terms as $t ) {
                if ( $t->taxonomy === $taxonomy ) {
                    $selected_term_ids[] = (int) $t->term_id;
                }
            }
        }

        echo '<h2>' . ( $is_edit ? esc_html__( 'Edit Signal Group', 'tmwseo' ) : esc_html__( 'Create Signal Group', 'tmwseo' ) ) . '</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tmwseo_save_signal_group' );
        echo '<input type="hidden" name="action" value="tmwseo_save_signal_group">';
        if ( $is_edit ) {
            echo '<input type="hidden" name="group_id" value="' . esc_attr( $group->id ) . '">';
        }

        echo '<table class="form-table"><tbody>';

        // Group Key.
        echo '<tr><th scope="row"><label for="group_key">' . esc_html__( 'Group Key', 'tmwseo' ) . '</label></th><td>';
        echo '<input type="text" id="group_key" name="group_key" class="regular-text" required '
            . ( $is_edit ? 'value="' . esc_attr( $group->group_key ) . '"' : '' ) . '>';
        echo '<p class="description">' . esc_html__( 'Unique machine-readable key (lowercase, underscores). e.g. cam_intent', 'tmwseo' ) . '</p>';
        echo '</td></tr>';

        // Label.
        echo '<tr><th scope="row"><label for="group_label">' . esc_html__( 'Label', 'tmwseo' ) . '</label></th><td>';
        echo '<input type="text" id="group_label" name="group_label" class="regular-text" required '
            . ( $is_edit ? 'value="' . esc_attr( $group->label ) . '"' : '' ) . '>';
        echo '</td></tr>';

        // Taxonomy selector — reloads the page via GET to refresh the term list.
        // Uses a data-attribute URL to avoid injecting raw PHP into inline JS strings.
        $reload_args = [ 'tab' => 'groups' ];
        if ( $is_edit ) {
            $reload_args['edit_group'] = $group->id;
        }
        $reload_base_url = self::page_url( $reload_args );

        echo '<tr><th scope="row"><label for="group_taxonomy">' . esc_html__( 'Source Taxonomy', 'tmwseo' ) . '</label></th><td>';
        $taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
        echo '<select id="group_taxonomy" name="group_taxonomy"'
            . ' data-reload-url="' . esc_attr( $reload_base_url ) . '"'
            . ' onchange="window.location.href=this.dataset.reloadUrl+\'&group_taxonomy=\'+encodeURIComponent(this.value);">';
        foreach ( $taxonomies as $tx ) {
            echo '<option value="' . esc_attr( $tx->name ) . '" ' . selected( $taxonomy, $tx->name, false ) . '>'
                . esc_html( $tx->label ) . '</option>';
        }
        echo '</select>';
        echo '</td></tr>';

        // Mapped terms multi-select.
        echo '<tr><th scope="row"><label for="group_term_ids">' . esc_html__( 'Mapped Terms', 'tmwseo' ) . '</label></th><td>';
        if ( empty( $all_terms ) ) {
            echo '<p>' . esc_html__( 'No terms found in this taxonomy.', 'tmwseo' ) . '</p>';
        } else {
            echo '<select id="group_term_ids" name="group_term_ids[]" multiple size="12" style="min-width:300px">';
            foreach ( $all_terms as $term ) {
                $sel = in_array( (int) $term->term_id, $selected_term_ids, true ) ? ' selected' : '';
                echo '<option value="' . esc_attr( $term->term_id ) . '"' . $sel . '>'
                    . esc_html( $term->name ) . ' (' . esc_html( $term->slug ) . ')</option>';
            }
            echo '</select>';
            echo '<p class="description">' . esc_html__( 'Hold Ctrl / Cmd to select multiple terms.', 'tmwseo' ) . '</p>';
        }
        echo '</td></tr>';

        // Status.
        echo '<tr><th scope="row"><label for="group_status">' . esc_html__( 'Status', 'tmwseo' ) . '</label></th><td>';
        $cur_status = $group ? $group->status : 'active';
        echo '<select id="group_status" name="group_status">';
        echo '<option value="active" ' . selected( $cur_status, 'active', false ) . '>' . esc_html__( 'Active', 'tmwseo' ) . '</option>';
        echo '<option value="inactive" ' . selected( $cur_status, 'inactive', false ) . '>' . esc_html__( 'Inactive', 'tmwseo' ) . '</option>';
        echo '</select></td></tr>';

        echo '</tbody></table>';

        submit_button( $is_edit ? __( 'Update Signal Group', 'tmwseo' ) : __( 'Create Signal Group', 'tmwseo' ) );

        if ( $is_edit ) {
            echo ' <a href="' . esc_url( self::page_url( [ 'tab' => 'groups' ] ) ) . '" class="button">'
                . esc_html__( 'Cancel', 'tmwseo' ) . '</a>';
        }

        echo '</form>';
    }

    private static function render_groups_list( SignalGroupRepository $repo ): void {
        $groups      = $repo->get_all();
        $term_counts = $repo->get_term_counts();

        echo '<h2>' . esc_html__( 'Signal Groups', 'tmwseo' ) . '</h2>';

        if ( empty( $groups ) ) {
            echo '<p>' . esc_html__( 'No signal groups yet. Create one above.', 'tmwseo' ) . '</p>';
            return;
        }

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>'
            . '<th style="width:50px">' . esc_html__( 'ID', 'tmwseo' ) . '</th>'
            . '<th>' . esc_html__( 'Key', 'tmwseo' ) . '</th>'
            . '<th>' . esc_html__( 'Label', 'tmwseo' ) . '</th>'
            . '<th>' . esc_html__( 'Taxonomy', 'tmwseo' ) . '</th>'
            . '<th style="width:80px">' . esc_html__( 'Terms', 'tmwseo' ) . '</th>'
            . '<th style="width:80px">' . esc_html__( 'Status', 'tmwseo' ) . '</th>'
            . '<th>' . esc_html__( 'Actions', 'tmwseo' ) . '</th>'
            . '</tr></thead><tbody>';

        foreach ( $groups as $g ) {
            $term_count = $term_counts[ (int) $g->id ] ?? 0;
            $edit_url   = self::page_url( [ 'tab' => 'groups', 'edit_group' => $g->id ] );

            echo '<tr>';
            echo '<td>' . esc_html( $g->id ) . '</td>';
            echo '<td><code>' . esc_html( $g->group_key ) . '</code></td>';
            echo '<td>' . esc_html( $g->label ) . '</td>';

            // Display taxonomy from first term row if available.
            $group_terms = $repo->get_terms_for_group( (int) $g->id );
            $tx_display  = $group_terms ? $group_terms[0]->taxonomy : '—';
            echo '<td>' . esc_html( $tx_display ) . '</td>';

            echo '<td>' . esc_html( $term_count ) . '</td>';
            echo '<td>' . esc_html( $g->status ) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url( $edit_url ) . '" class="button button-small">' . esc_html__( 'Edit', 'tmwseo' ) . '</a> ';

            // Delete form.
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" '
                . 'onsubmit="return confirm(\'' . esc_js( __( 'Delete this signal group?', 'tmwseo' ) ) . '\')">';
            wp_nonce_field( 'tmwseo_delete_signal_group' );
            echo '<input type="hidden" name="action" value="tmwseo_delete_signal_group">';
            echo '<input type="hidden" name="group_id" value="' . esc_attr( $g->id ) . '">';
            echo '<button type="submit" class="button button-small button-link-delete">'
                . esc_html__( 'Delete', 'tmwseo' ) . '</button>';
            echo '</form>';

            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    // ════════════════════════════════════════════════════════════════════════
    // TAB 2: Formulas
    // ════════════════════════════════════════════════════════════════════════

    private static function render_formulas_tab(): void {
        $edit_id    = (int) ( $_GET['edit_formula'] ?? 0 );
        $formula_id = (int) ( $_GET['formula_id'] ?? 0 );
        $repo       = self::formula_repo();

        // Show dry run results if available.
        $dry_run_data = get_transient( 'tmwseo_dry_run_result_' . get_current_user_id() );
        if ( $dry_run_data && isset( $dry_run_data['formula_id'] ) ) {
            $view_formula_id = (int) $dry_run_data['formula_id'];
            if ( ! $formula_id || $formula_id === $view_formula_id ) {
                self::render_dry_run_results( $dry_run_data['result'], $view_formula_id, $repo );
                delete_transient( 'tmwseo_dry_run_result_' . get_current_user_id() );
            }
        }

        echo '<div class="tmwseo-section" style="margin-top:20px">';
        if ( $edit_id > 0 ) {
            $formula = $repo->get_by_id( $edit_id );
            self::render_formula_form( $formula );
        } else {
            self::render_formula_form( null );
        }
        echo '</div>';

        echo '<hr>';

        echo '<div class="tmwseo-section">';
        self::render_formulas_list( $repo );
        echo '</div>';
    }

    private static function render_formula_form( ?object $formula ): void {
        $is_edit = ( $formula !== null );
        $repo    = self::formula_repo();
        $groups  = self::group_repo()->get_all( 'active' );

        $req_ids = $is_edit ? $repo->get_required_group_ids( (int) $formula->id ) : [];
        $exc_ids = $is_edit ? $repo->get_excluded_group_ids( (int) $formula->id ) : [];

        // Target taxonomy and current terms.
        $target_taxonomy = $is_edit ? $formula->target_taxonomy : 'category';
        $target_terms    = get_terms( [ 'taxonomy' => $target_taxonomy, 'hide_empty' => false, 'orderby' => 'name', 'number' => 500 ] );
        $target_terms    = is_wp_error( $target_terms ) ? [] : $target_terms;

        $public_post_types = get_post_types( [ 'public' => true ], 'objects' );

        echo '<h2>' . ( $is_edit ? esc_html__( 'Edit Formula', 'tmwseo' ) : esc_html__( 'Create Formula', 'tmwseo' ) ) . '</h2>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tmwseo_save_category_formula' );
        echo '<input type="hidden" name="action" value="tmwseo_save_category_formula">';
        if ( $is_edit ) {
            echo '<input type="hidden" name="formula_id" value="' . esc_attr( $formula->id ) . '">';
        }

        echo '<table class="form-table"><tbody>';

        // Formula Key.
        echo '<tr><th scope="row"><label for="formula_key">' . esc_html__( 'Formula Key', 'tmwseo' ) . '</label></th><td>';
        echo '<input type="text" id="formula_key" name="formula_key" class="regular-text" required '
            . ( $is_edit ? 'value="' . esc_attr( $formula->formula_key ) . '"' : '' ) . '>';
        echo '<p class="description">' . esc_html__( 'Unique machine-readable key. e.g. free_blonde_cam_models', 'tmwseo' ) . '</p>';
        echo '</td></tr>';

        // Label.
        echo '<tr><th scope="row"><label for="formula_label">' . esc_html__( 'Label', 'tmwseo' ) . '</label></th><td>';
        echo '<input type="text" id="formula_label" name="formula_label" class="regular-text" required '
            . ( $is_edit ? 'value="' . esc_attr( $formula->label ) . '"' : '' ) . '>';
        echo '</td></tr>';

        // Target Taxonomy.
        echo '<tr><th scope="row"><label for="target_taxonomy">' . esc_html__( 'Target Taxonomy', 'tmwseo' ) . '</label></th><td>';
        $taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
        echo '<select id="target_taxonomy" name="target_taxonomy">';
        foreach ( $taxonomies as $tx ) {
            echo '<option value="' . esc_attr( $tx->name ) . '" ' . selected( $target_taxonomy, $tx->name, false ) . '>'
                . esc_html( $tx->label ) . '</option>';
        }
        echo '</select></td></tr>';

        // Target Term.
        echo '<tr><th scope="row"><label for="target_term_id">' . esc_html__( 'Target Category Term', 'tmwseo' ) . '</label></th><td>';
        $cur_target = $is_edit ? (int) $formula->target_term_id : 0;
        echo '<select id="target_term_id" name="target_term_id" required>';
        echo '<option value="">' . esc_html__( '— Select term —', 'tmwseo' ) . '</option>';
        foreach ( $target_terms as $term ) {
            echo '<option value="' . esc_attr( $term->term_id ) . '" ' . selected( $cur_target, (int) $term->term_id, false ) . '>'
                . esc_html( $term->name ) . ' (ID ' . (int) $term->term_id . ')</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'The target term must already exist in WordPress.', 'tmwseo' ) . '</p>';
        echo '</td></tr>';

        // Source Taxonomy.
        echo '<tr><th scope="row"><label for="source_taxonomy">' . esc_html__( 'Source Taxonomy (matching)', 'tmwseo' ) . '</label></th><td>';
        $source_taxonomy = $is_edit ? $formula->source_taxonomy : 'post_tag';
        echo '<select id="source_taxonomy" name="source_taxonomy">';
        foreach ( $taxonomies as $tx ) {
            echo '<option value="' . esc_attr( $tx->name ) . '" ' . selected( $source_taxonomy, $tx->name, false ) . '>'
                . esc_html( $tx->label ) . '</option>';
        }
        echo '</select></td></tr>';

        // Post Type.
        echo '<tr><th scope="row"><label for="formula_post_type">' . esc_html__( 'Post Type', 'tmwseo' ) . '</label></th><td>';
        $cur_pt = $is_edit ? $formula->post_type : 'post';
        echo '<select id="formula_post_type" name="formula_post_type">';
        foreach ( $public_post_types as $pt ) {
            echo '<option value="' . esc_attr( $pt->name ) . '" ' . selected( $cur_pt, $pt->name, false ) . '>'
                . esc_html( $pt->labels->singular_name ) . '</option>';
        }
        echo '</select></td></tr>';

        // Required Groups.
        echo '<tr><th scope="row"><label for="required_groups">' . esc_html__( 'Required Groups', 'tmwseo' ) . '</label></th><td>';
        if ( empty( $groups ) ) {
            echo '<p>' . esc_html__( 'No active signal groups. Create one first.', 'tmwseo' ) . '</p>';
        } else {
            echo '<select id="required_groups" name="required_groups[]" multiple size="8" style="min-width:280px">';
            foreach ( $groups as $g ) {
                $sel = in_array( (int) $g->id, $req_ids, true ) ? ' selected' : '';
                echo '<option value="' . esc_attr( $g->id ) . '"' . $sel . '>'
                    . esc_html( $g->label ) . ' [' . esc_html( $g->group_key ) . ']</option>';
            }
            echo '</select>';
            echo '<p class="description">' . esc_html__( 'Post must match at least one term from EACH selected group.', 'tmwseo' ) . '</p>';
        }
        echo '</td></tr>';

        // Excluded Groups.
        echo '<tr><th scope="row"><label for="excluded_groups">' . esc_html__( 'Excluded Groups', 'tmwseo' ) . '</label></th><td>';
        if ( ! empty( $groups ) ) {
            echo '<select id="excluded_groups" name="excluded_groups[]" multiple size="8" style="min-width:280px">';
            foreach ( $groups as $g ) {
                $sel = in_array( (int) $g->id, $exc_ids, true ) ? ' selected' : '';
                echo '<option value="' . esc_attr( $g->id ) . '"' . $sel . '>'
                    . esc_html( $g->label ) . ' [' . esc_html( $g->group_key ) . ']</option>';
            }
            echo '</select>';
            echo '<p class="description">' . esc_html__( 'Posts with any term from these groups will be excluded. Optional.', 'tmwseo' ) . '</p>';
        }
        echo '</td></tr>';

        // Status.
        echo '<tr><th scope="row"><label for="formula_status">' . esc_html__( 'Status', 'tmwseo' ) . '</label></th><td>';
        $cur_status = $is_edit ? $formula->status : 'active';
        echo '<select id="formula_status" name="formula_status">';
        echo '<option value="active" ' . selected( $cur_status, 'active', false ) . '>' . esc_html__( 'Active', 'tmwseo' ) . '</option>';
        echo '<option value="inactive" ' . selected( $cur_status, 'inactive', false ) . '>' . esc_html__( 'Inactive', 'tmwseo' ) . '</option>';
        echo '</select></td></tr>';

        // Notes.
        echo '<tr><th scope="row"><label for="formula_notes">' . esc_html__( 'Notes', 'tmwseo' ) . '</label></th><td>';
        echo '<textarea id="formula_notes" name="formula_notes" rows="3" class="large-text">'
            . ( $is_edit ? esc_textarea( $formula->notes ?? '' ) : '' ) . '</textarea>';
        echo '</td></tr>';

        echo '</tbody></table>';

        submit_button( $is_edit ? __( 'Update Formula', 'tmwseo' ) : __( 'Create Formula', 'tmwseo' ) );

        if ( $is_edit ) {
            echo ' <a href="' . esc_url( self::page_url( [ 'tab' => 'formulas' ] ) ) . '" class="button">'
                . esc_html__( 'Cancel', 'tmwseo' ) . '</a>';
        }

        echo '</form>';
    }

    private static function render_formulas_list( CategoryFormulaRepository $repo ): void {
        $formulas   = $repo->get_all();
        $group_repo = self::group_repo();
        $all_groups = [];
        foreach ( $group_repo->get_all() as $g ) {
            $all_groups[ (int) $g->id ] = $g->label;
        }

        echo '<h2>' . esc_html__( 'Formulas', 'tmwseo' ) . '</h2>';

        if ( empty( $formulas ) ) {
            echo '<p>' . esc_html__( 'No formulas yet. Create one above.', 'tmwseo' ) . '</p>';
            return;
        }

        echo '<table class="widefat fixed striped" style="table-layout:auto">';
        echo '<thead><tr>'
            . '<th style="width:40px">ID</th>'
            . '<th>' . esc_html__( 'Key', 'tmwseo' ) . '</th>'
            . '<th>' . esc_html__( 'Label', 'tmwseo' ) . '</th>'
            . '<th>' . esc_html__( 'Target Category', 'tmwseo' ) . '</th>'
            . '<th>' . esc_html__( 'Source Taxonomy', 'tmwseo' ) . '</th>'
            . '<th>' . esc_html__( 'Post Type', 'tmwseo' ) . '</th>'
            . '<th>' . esc_html__( 'Required Groups', 'tmwseo' ) . '</th>'
            . '<th style="width:60px">' . esc_html__( 'Status', 'tmwseo' ) . '</th>'
            . '<th>' . esc_html__( 'Last Dry Run', 'tmwseo' ) . '</th>'
            . '<th>' . esc_html__( 'Last Backfill', 'tmwseo' ) . '</th>'
            . '<th>' . esc_html__( 'Actions', 'tmwseo' ) . '</th>'
            . '</tr></thead><tbody>';

        foreach ( $formulas as $f ) {
            $target_term = get_term( (int) $f->target_term_id, $f->target_taxonomy );
            $target_name = ( $target_term && ! is_wp_error( $target_term ) ) ? $target_term->name : '⚠ missing';

            $req_ids   = $repo->get_required_group_ids( (int) $f->id );
            $req_labels = array_map( fn( $gid ) => $all_groups[ $gid ] ?? "#{$gid}", $req_ids );

            $edit_url = self::page_url( [ 'tab' => 'formulas', 'edit_formula' => $f->id ] );

            echo '<tr>';
            echo '<td>' . esc_html( $f->id ) . '</td>';
            echo '<td><code>' . esc_html( $f->formula_key ) . '</code></td>';
            echo '<td>' . esc_html( $f->label ) . '</td>';
            echo '<td>' . esc_html( $target_name ) . '</td>';
            echo '<td>' . esc_html( $f->source_taxonomy ) . '</td>';
            echo '<td>' . esc_html( $f->post_type ) . '</td>';
            echo '<td>' . esc_html( implode( ', ', $req_labels ) ) . '</td>';
            echo '<td>' . esc_html( $f->status ) . '</td>';

            // Dry run stats.
            if ( $f->last_dry_run_at ) {
                echo '<td><small>' . esc_html( $f->last_dry_run_at ) . '<br>'
                    . esc_html( sprintf( __( '%d matched / %d missing', 'tmwseo' ), $f->last_dry_run_matched, $f->last_dry_run_missing ) )
                    . '</small></td>';
            } else {
                echo '<td>—</td>';
            }

            // Backfill stats.
            if ( $f->last_backfill_at ) {
                echo '<td><small>' . esc_html( $f->last_backfill_at ) . '<br>'
                    . esc_html( sprintf( __( '%d changed', 'tmwseo' ), $f->last_backfill_changed ) )
                    . '</small></td>';
            } else {
                echo '<td>—</td>';
            }

            echo '<td>';
            echo '<a href="' . esc_url( $edit_url ) . '" class="button button-small">' . esc_html__( 'Edit', 'tmwseo' ) . '</a> ';

            // Dry Run form.
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
            wp_nonce_field( 'tmwseo_formula_dry_run' );
            echo '<input type="hidden" name="action" value="tmwseo_formula_dry_run">';
            echo '<input type="hidden" name="formula_id" value="' . esc_attr( $f->id ) . '">';
            echo '<button type="submit" class="button button-small">' . esc_html__( 'Dry Run', 'tmwseo' ) . '</button>';
            echo '</form> ';

            // Backfill form.
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" '
                . 'onsubmit="return confirm(\'' . esc_js( __( 'Run backfill? This will assign the target category to matching posts that are missing it.', 'tmwseo' ) ) . '\')">';
            wp_nonce_field( 'tmwseo_formula_backfill' );
            echo '<input type="hidden" name="action" value="tmwseo_formula_backfill">';
            echo '<input type="hidden" name="formula_id" value="' . esc_attr( $f->id ) . '">';
            echo '<input type="hidden" name="backfill_offset" value="0">';
            echo '<button type="submit" class="button button-small button-primary">' . esc_html__( 'Backfill', 'tmwseo' ) . '</button>';
            echo '</form> ';

            // Delete form.
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" '
                . 'onsubmit="return confirm(\'' . esc_js( __( 'Delete this formula?', 'tmwseo' ) ) . '\')">';
            wp_nonce_field( 'tmwseo_delete_category_formula' );
            echo '<input type="hidden" name="action" value="tmwseo_delete_category_formula">';
            echo '<input type="hidden" name="formula_id" value="' . esc_attr( $f->id ) . '">';
            echo '<button type="submit" class="button button-small button-link-delete">' . esc_html__( 'Delete', 'tmwseo' ) . '</button>';
            echo '</form>';

            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    private static function render_dry_run_results( array $result, int $formula_id, CategoryFormulaRepository $repo ): void {
        $formula = $repo->get_by_id( $formula_id );

        echo '<div class="tmwseo-card" style="margin-top:20px;padding:16px;background:#fff;border:1px solid #ccd0d4;border-radius:4px">';
        echo '<h2>' . esc_html__( 'Dry Run Results', 'tmwseo' );
        if ( $formula ) {
            echo ' — ' . esc_html( $formula->label );
        }
        echo '</h2>';

        echo '<table class="form-table"><tbody>';
        echo '<tr><th>' . esc_html__( 'Total matched posts', 'tmwseo' ) . '</th><td><strong>' . esc_html( $result['matched'] ) . '</strong></td></tr>';
        echo '<tr><th>' . esc_html__( 'Already assigned', 'tmwseo' ) . '</th><td>' . esc_html( $result['already_count'] ) . '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Missing (would be backfilled)', 'tmwseo' ) . '</th><td><strong style="color:#d63638">' . esc_html( $result['missing_count'] ) . '</strong></td></tr>';
        echo '</tbody></table>';

        if ( ! empty( $result['sample'] ) ) {
            echo '<h3>' . esc_html__( 'Sample Posts (max 25)', 'tmwseo' ) . '</h3>';
            echo '<table class="widefat striped" style="max-width:900px">';
            echo '<thead><tr>'
                . '<th>ID</th>'
                . '<th>' . esc_html__( 'Title', 'tmwseo' ) . '</th>'
                . '<th>' . esc_html__( 'Current Categories', 'tmwseo' ) . '</th>'
                . '<th>' . esc_html__( 'Already Assigned?', 'tmwseo' ) . '</th>'
                . '</tr></thead><tbody>';

            foreach ( $result['sample'] as $row ) {
                $post_edit = get_edit_post_link( $row['post_id'] );
                echo '<tr>';
                echo '<td>' . esc_html( $row['post_id'] ) . '</td>';
                echo '<td><a href="' . esc_url( (string) $post_edit ) . '">' . esc_html( $row['title'] ) . '</a></td>';
                echo '<td>' . esc_html( $row['current_cats'] ?: '—' ) . '</td>';
                echo '<td>' . ( $row['already_assigned']
                    ? '<span style="color:green">✔ ' . esc_html__( 'Yes', 'tmwseo' ) . '</span>'
                    : '<span style="color:#d63638">✘ ' . esc_html__( 'No', 'tmwseo' ) . '</span>'
                ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }

    // ════════════════════════════════════════════════════════════════════════
    // TAB 3: Backfill Log
    // ════════════════════════════════════════════════════════════════════════

    private static function render_log_tab(): void {
        $repo     = self::formula_repo();
        $per_page = 50;
        $paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $offset   = ( $paged - 1 ) * $per_page;
        $total    = $repo->get_logs_count();
        $logs     = $repo->get_logs( $per_page, $offset );
        $pages    = $total > 0 ? (int) ceil( $total / $per_page ) : 1;

        echo '<div class="tmwseo-section" style="margin-top:20px">';
        echo '<h2>' . esc_html__( 'Backfill Assignment Log', 'tmwseo' ) . '</h2>';
        echo '<p>' . esc_html( sprintf( __( '%d total entries.', 'tmwseo' ), $total ) ) . '</p>';

        if ( empty( $logs ) ) {
            echo '<p>' . esc_html__( 'No log entries yet. Run a backfill first.', 'tmwseo' ) . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>'
            . '<th>' . esc_html__( 'Date', 'tmwseo' ) . '</th>'
            . '<th>' . esc_html__( 'Formula', 'tmwseo' ) . '</th>'
            . '<th>' . esc_html__( 'Post ID', 'tmwseo' ) . '</th>'
            . '<th>' . esc_html__( 'Action', 'tmwseo' ) . '</th>'
            . '<th>' . esc_html__( 'Result', 'tmwseo' ) . '</th>'
            . '<th>' . esc_html__( 'Message', 'tmwseo' ) . '</th>'
            . '</tr></thead><tbody>';

        foreach ( $logs as $row ) {
            $post_link = get_edit_post_link( $row->post_id );
            $result_color = ( $row->result === 'ok' ) ? '#007a21' : ( ( $row->result === 'skipped' ) ? '#888' : '#d63638' );

            echo '<tr>';
            echo '<td><small>' . esc_html( $row->created_at ) . '</small></td>';
            echo '<td>' . esc_html( $row->formula_label ?? "#{$row->formula_id}" ) . '</td>';
            echo '<td>';
            if ( $post_link ) {
                echo '<a href="' . esc_url( $post_link ) . '">#' . esc_html( $row->post_id ) . '</a>';
            } else {
                echo '#' . esc_html( $row->post_id );
            }
            echo '</td>';
            echo '<td><code>' . esc_html( $row->action ) . '</code></td>';
            echo '<td><strong style="color:' . esc_attr( $result_color ) . '">' . esc_html( $row->result ) . '</strong></td>';
            echo '<td>' . esc_html( $row->message ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Pagination.
        if ( $pages > 1 ) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">';
            $page_links = paginate_links( [
                'base'      => add_query_arg( 'paged', '%#%' ),
                'format'    => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total'     => $pages,
                'current'   => $paged,
            ] );
            if ( $page_links ) {
                echo wp_kses_post( $page_links );
            }
            echo '</div></div>';
        }

        echo '</div>';
    }
}
