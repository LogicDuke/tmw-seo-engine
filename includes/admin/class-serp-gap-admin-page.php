<?php
/**
 * SerpGapAdminPage — WordPress admin page for SERP Keyword Gaps.
 *
 * Responsibilities:
 *  - Register form POST handlers and AJAX handlers (via init())
 *  - Render the full admin page (via render_page())
 *
 * Does NOT:
 *  - Register menu items (that is done in class-admin.php::menu())
 *  - Define shared interfaces
 *  - Contain any scoring or storage logic
 *
 * @package TMWSEO\Engine\Admin
 * @since   4.6.3
 */
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\SerpGaps\SerpGapEngine;
use TMWSEO\Engine\SerpGaps\SerpGapStorage;
use TMWSEO\Engine\Intelligence\ContentBriefGenerator;
use TMWSEO\Engine\Opportunities\OpportunityDatabase;
use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SerpGapAdminPage {

    // ── Bootstrap ─────────────────────────────────────────────────────────────

    public static function init(): void {
        // Form POST handlers
        add_action( 'admin_post_tmwseo_serp_gap_scan',           [ __CLASS__, 'handle_scan' ] );
        add_action( 'admin_post_tmwseo_serp_gap_batch_scan',     [ __CLASS__, 'handle_batch_scan' ] );
        add_action( 'admin_post_tmwseo_serp_gap_update_status',  [ __CLASS__, 'handle_update_status' ] );
        add_action( 'admin_post_tmwseo_serp_gap_create_brief',   [ __CLASS__, 'handle_create_brief' ] );
        add_action( 'admin_post_tmwseo_serp_gap_to_opportunity', [ __CLASS__, 'handle_to_opportunity' ] );

        // AJAX (for inline rescan / status changes)
        add_action( 'wp_ajax_tmwseo_serp_gap_rescan',        [ __CLASS__, 'ajax_rescan' ] );
        add_action( 'wp_ajax_tmwseo_serp_gap_status_change', [ __CLASS__, 'ajax_status_change' ] );
    }

    // ── Form POST handlers ────────────────────────────────────────────────────

    public static function handle_scan(): void {
        self::require_caps();
        check_admin_referer( 'tmwseo_serp_gap_scan' );

        $keyword = sanitize_text_field( (string) ( $_POST['keyword'] ?? '' ) );
        if ( $keyword === '' ) {
            self::redirect( [ 'error' => 'empty_keyword' ] );
        }

        $source  = sanitize_key( (string) ( $_POST['source'] ?? 'manual' ) );
        $engine  = new SerpGapEngine();
        $result  = $engine->analyse( $keyword, $source );

        $code = $result['result_code'] ?? 'unknown';

        if ( $code === SerpGapEngine::RESULT_OK ) {
            self::redirect( [ 'scanned' => '1', 'kw' => urlencode( $keyword ), 'score' => (int) ( $result['serp_gap_score'] ?? 0 ) ] );
        } else {
            self::redirect( [ 'error' => $code, 'msg' => urlencode( $result['message'] ?? '' ) ] );
        }
    }

    public static function handle_batch_scan(): void {
        self::require_caps();
        check_admin_referer( 'tmwseo_serp_gap_batch_scan' );

        $raw     = sanitize_textarea_field( (string) ( $_POST['keywords'] ?? '' ) );
        $lines   = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ) );
        $source  = sanitize_key( (string) ( $_POST['source'] ?? 'batch' ) );

        if ( empty( $lines ) ) {
            self::redirect( [ 'error' => 'empty_keywords' ] );
        }

        $engine = new SerpGapEngine();
        $batch  = $engine->analyse_batch( array_values( $lines ), $source );

        self::redirect( [
            'batch_done'  => '1',
            'processed'   => (int) $batch['processed'],
            'errors'      => count( $batch['errors'] ),
        ] );
    }

    public static function handle_update_status(): void {
        self::require_caps();
        check_admin_referer( 'tmwseo_serp_gap_update_status' );

        $id     = (int) ( $_POST['gap_id'] ?? 0 );
        $status = sanitize_key( (string) ( $_POST['status'] ?? '' ) );

        if ( $id <= 0 || ! in_array( $status, SerpGapStorage::allowed_statuses(), true ) ) {
            self::redirect( [ 'error' => 'invalid_params' ] );
        }

        SerpGapStorage::update_status( $id, $status );
        self::redirect( [ 'status_updated' => '1' ] );
    }

    public static function handle_create_brief(): void {
        self::require_caps();
        check_admin_referer( 'tmwseo_serp_gap_create_brief' );

        $gap_id = (int) ( $_POST['gap_id'] ?? 0 );
        $row    = $gap_id > 0 ? SerpGapStorage::find_by_id( $gap_id ) : null;

        if ( $row === null ) {
            self::redirect( [ 'error' => 'gap_not_found' ] );
        }

        $generator = new ContentBriefGenerator();
        $generator->generate( [
            'primary_keyword' => (string) $row['keyword'],
            'keyword_cluster' => 'SERP Keyword Gaps',
            'search_intent'   => 'Commercial Investigation',
            'brief_type'      => 'serp_gap',
            'notes'           => (string) ( $row['reason'] ?? '' ),
        ] );

        SerpGapStorage::update_status( $gap_id, 'brief_created' );
        self::redirect( [ 'brief_created' => '1', 'kw' => urlencode( (string) $row['keyword'] ) ] );
    }

    public static function handle_to_opportunity(): void {
        self::require_caps();
        check_admin_referer( 'tmwseo_serp_gap_to_opportunity' );

        $gap_id = (int) ( $_POST['gap_id'] ?? 0 );
        $row    = $gap_id > 0 ? SerpGapStorage::find_by_id( $gap_id ) : null;

        if ( $row === null ) {
            self::redirect( [ 'error' => 'gap_not_found' ] );
        }

        if ( class_exists( OpportunityDatabase::class ) ) {
            $opp_db = new OpportunityDatabase();
            $opp_db->store( [ [
                'keyword'            => (string) $row['keyword'],
                'search_volume'      => (int) ( $row['search_volume'] ?? 0 ),
                'difficulty'         => (float) ( $row['difficulty'] ?? 0 ),
                'opportunity_score'  => (float) ( $row['serp_gap_score'] ?? 0 ),
                'competitor_url'     => '',
                'source'             => 'serp_gap',
                'type'               => 'serp_gap',
                'recommended_action' => 'Create Draft',
            ] ] );
        }

        SerpGapStorage::update_status( $gap_id, 'opportunity' );
        self::redirect( [ 'converted' => '1', 'kw' => urlencode( (string) $row['keyword'] ) ] );
    }

    // ── AJAX handlers ─────────────────────────────────────────────────────────

    public static function ajax_rescan(): void {
        self::require_caps_ajax();
        check_ajax_referer( 'tmwseo_serp_gap_ajax' );

        $gap_id = (int) ( $_POST['gap_id'] ?? 0 );
        $row    = $gap_id > 0 ? SerpGapStorage::find_by_id( $gap_id ) : null;

        if ( $row === null ) {
            wp_send_json_error( [ 'message' => 'Gap record not found.' ] );
        }

        // Delete the DataForSEO transient so it gets a fresh SERP fetch
        $kw        = (string) $row['keyword'];
        $cache_key = 'tmwseo_serp_' . md5( $kw );
        delete_transient( $cache_key );

        $engine = new SerpGapEngine();
        $result = $engine->analyse( $kw, (string) ( $row['source'] ?? 'manual' ) );

        if ( $result['result_code'] === SerpGapEngine::RESULT_OK ) {
            wp_send_json_success( [
                'score'      => $result['serp_gap_score'],
                'gap_types'  => $result['gap_types'],
                'reason'     => $result['reason'],
            ] );
        } else {
            wp_send_json_error( [ 'message' => $result['message'] ?? 'Unknown error.' ] );
        }
    }

    public static function ajax_status_change(): void {
        self::require_caps_ajax();
        check_ajax_referer( 'tmwseo_serp_gap_ajax' );

        $gap_id = (int) ( $_POST['gap_id'] ?? 0 );
        $status = sanitize_key( (string) ( $_POST['status'] ?? '' ) );

        if ( $gap_id <= 0 || ! in_array( $status, SerpGapStorage::allowed_statuses(), true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid parameters.' ] );
        }

        $ok = SerpGapStorage::update_status( $gap_id, $status );
        if ( $ok ) {
            wp_send_json_success( [ 'status' => $status ] );
        } else {
            wp_send_json_error( [ 'message' => 'Update failed.' ] );
        }
    }

    // ── Page renderer ─────────────────────────────────────────────────────────

    public static function render_page(): void {
        self::require_caps();

        $provider_ok = DataForSEO::is_configured();
        $filters     = self::get_active_filters();
        $page_num    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $per_page    = 25;
        $offset      = ( $page_num - 1 ) * $per_page;
        $total       = SerpGapStorage::count_all( $filters );
        $rows        = SerpGapStorage::list_page( $filters, $per_page, $offset );
        $total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 1;
        $base_url    = admin_url( 'admin.php?page=tmwseo-serp-gaps' );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'SERP Keyword Gaps', 'tmwseo' ) . '</h1>';
        echo '<p class="description">' . esc_html__( 'Identify queries where Google is ranking pages that only partially satisfy the search intent — revealing exact-match, modifier, or specificity opportunities.', 'tmwseo' ) . '</p>';

        self::render_notices( $provider_ok );

        if ( $provider_ok ) {
            self::render_scan_forms();
        }

        self::render_filter_bar( $filters, $base_url );
        self::render_table( $rows, $total, $page_num, $total_pages, $per_page, $base_url );

        self::render_inline_js( $provider_ok );

        echo '</div>'; // .wrap
    }

    // ── Sub-renderers ─────────────────────────────────────────────────────────

    private static function render_notices( bool $provider_ok ): void {
        // Provider warning
        if ( ! $provider_ok ) {
            echo '<div class="notice notice-warning">';
            echo '<p><strong>' . esc_html__( 'SERP provider not configured.', 'tmwseo' ) . '</strong> ';
            echo esc_html__( 'Add your DataForSEO credentials in ', 'tmwseo' );
            echo '<a href="' . esc_url( admin_url( 'admin.php?page=tmwseo-settings' ) ) . '">';
            echo esc_html__( 'Settings → Connections', 'tmwseo' ) . '</a>';
            echo esc_html__( ' to enable live SERP scanning.', 'tmwseo' ) . '</p>';
            echo '</div>';
        }

        // Budget warning
        if ( $provider_ok && DataForSEO::is_over_budget() ) {
            $stats = DataForSEO::get_monthly_budget_stats();
            echo '<div class="notice notice-error">';
            echo '<p>' . sprintf(
                esc_html__( 'Monthly DataForSEO budget of $%.2f has been exceeded ($%.2f spent). Scanning is paused until next month or budget is increased.', 'tmwseo' ),
                (float) ( $stats['budget_usd'] ?? 0 ),
                (float) ( $stats['spent_usd'] ?? 0 )
            ) . '</p>';
            echo '</div>';
        }

        // Action feedback
        $scan_ok    = isset( $_GET['scanned'] ) && (int) $_GET['scanned'] === 1;
        $batch_ok   = isset( $_GET['batch_done'] ) && (int) $_GET['batch_done'] === 1;
        $brief_ok   = isset( $_GET['brief_created'] ) && (int) $_GET['brief_created'] === 1;
        $conv_ok    = isset( $_GET['converted'] ) && (int) $_GET['converted'] === 1;
        $status_ok  = isset( $_GET['status_updated'] ) && (int) $_GET['status_updated'] === 1;
        $error_code = sanitize_key( (string) ( $_GET['error'] ?? '' ) );

        if ( $scan_ok ) {
            $kw    = sanitize_text_field( urldecode( (string) ( $_GET['kw'] ?? '' ) ) );
            $score = (int) ( $_GET['score'] ?? 0 );
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
                esc_html__( 'Scan complete for "%s" — Gap Score: %d/100.', 'tmwseo' ), esc_html( $kw ), $score
            ) . '</p></div>';
        }

        if ( $batch_ok ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
                esc_html__( 'Batch scan complete — %d keywords processed, %d errors.', 'tmwseo' ),
                (int) ( $_GET['processed'] ?? 0 ),
                (int) ( $_GET['errors'] ?? 0 )
            ) . '</p></div>';
        }

        if ( $brief_ok ) {
            $kw = sanitize_text_field( urldecode( (string) ( $_GET['kw'] ?? '' ) ) );
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
                esc_html__( 'Content brief created for "%s". View it in Content Briefs.', 'tmwseo' ), esc_html( $kw )
            ) . '</p></div>';
        }

        if ( $conv_ok ) {
            $kw = sanitize_text_field( urldecode( (string) ( $_GET['kw'] ?? '' ) ) );
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
                esc_html__( '"%s" added to Opportunities.', 'tmwseo' ), esc_html( $kw )
            ) . '</p></div>';
        }

        if ( $status_ok ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Status updated.', 'tmwseo' ) . '</p></div>';
        }

        if ( $error_code !== '' ) {
            $msg = sanitize_text_field( urldecode( (string) ( $_GET['msg'] ?? '' ) ) );
            $human_errors = [
                'empty_keyword'      => __( 'Please enter a keyword.', 'tmwseo' ),
                'empty_keywords'     => __( 'Please enter at least one keyword.', 'tmwseo' ),
                'no_provider'        => __( 'DataForSEO is not configured.', 'tmwseo' ),
                'budget_exceeded'    => __( 'Monthly API budget exceeded.', 'tmwseo' ),
                'serp_fetch_failed'  => __( 'SERP fetch failed.', 'tmwseo' ),
                'empty_serp'         => __( 'No organic results returned for this keyword.', 'tmwseo' ),
                'gap_not_found'      => __( 'Gap record not found.', 'tmwseo' ),
                'invalid_params'     => __( 'Invalid parameters.', 'tmwseo' ),
            ];
            $display_msg = $human_errors[ $error_code ] ?? __( 'An error occurred.', 'tmwseo' );
            if ( $msg !== '' ) {
                $display_msg .= ' ' . $msg;
            }
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $display_msg ) . '</p></div>';
        }
    }

    private static function render_scan_forms(): void {
        $base_action = esc_url( admin_url( 'admin-post.php' ) );
        ?>
        <div style="display:flex;gap:20px;flex-wrap:wrap;margin:16px 0;">

            <!-- Single keyword scan -->
            <div class="tmwseo-card" style="flex:1;min-width:300px;max-width:520px;padding:16px 20px;">
                <h3 style="margin-top:0;"><?php esc_html_e( 'Scan a Keyword', 'tmwseo' ); ?></h3>
                <form method="post" action="<?php echo $base_action; ?>" id="tmwseo-gap-single-form">
                    <?php wp_nonce_field( 'tmwseo_serp_gap_scan' ); ?>
                    <input type="hidden" name="action" value="tmwseo_serp_gap_scan">
                    <div style="display:flex;gap:8px;align-items:flex-end;">
                        <div style="flex:1;">
                            <label for="tmwseo-gap-kw" style="display:block;margin-bottom:4px;font-weight:600;"><?php esc_html_e( 'Keyword', 'tmwseo' ); ?></label>
                            <input type="text" id="tmwseo-gap-kw" name="keyword" class="regular-text" style="width:100%;" placeholder="<?php esc_attr_e( 'e.g. free cam chat no signup', 'tmwseo' ); ?>" required>
                        </div>
                        <div>
                            <label for="tmwseo-gap-source" style="display:block;margin-bottom:4px;font-weight:600;"><?php esc_html_e( 'Source', 'tmwseo' ); ?></label>
                            <select id="tmwseo-gap-source" name="source">
                                <option value="manual"><?php esc_html_e( 'Manual', 'tmwseo' ); ?></option>
                                <option value="model"><?php esc_html_e( 'Model-related', 'tmwseo' ); ?></option>
                                <option value="candidates"><?php esc_html_e( 'From Candidates', 'tmwseo' ); ?></option>
                            </select>
                        </div>
                    </div>
                    <p style="margin-top:12px;">
                        <?php submit_button( __( 'Scan SERP', 'tmwseo' ), 'primary', 'submit', false, [ 'id' => 'tmwseo-gap-submit' ] ); ?>
                        <span id="tmwseo-gap-loading" style="display:none;margin-left:8px;color:#666;"><?php esc_html_e( 'Fetching SERP…', 'tmwseo' ); ?></span>
                    </p>
                </form>
            </div>

            <!-- Batch scan -->
            <div class="tmwseo-card" style="flex:1;min-width:300px;max-width:520px;padding:16px 20px;">
                <h3 style="margin-top:0;"><?php esc_html_e( 'Batch Scan', 'tmwseo' ); ?></h3>
                <form method="post" action="<?php echo $base_action; ?>" id="tmwseo-gap-batch-form">
                    <?php wp_nonce_field( 'tmwseo_serp_gap_batch_scan' ); ?>
                    <input type="hidden" name="action" value="tmwseo_serp_gap_batch_scan">
                    <input type="hidden" name="source" value="batch">
                    <label for="tmwseo-gap-batch-kw" style="display:block;margin-bottom:4px;font-weight:600;"><?php esc_html_e( 'Keywords (one per line)', 'tmwseo' ); ?></label>
                    <textarea id="tmwseo-gap-batch-kw" name="keywords" rows="5" style="width:100%;font-family:monospace;font-size:12px;" placeholder="<?php esc_attr_e( "free cam to cam\nprivate cam chat\nlive cam no registration", 'tmwseo' ); ?>" required></textarea>
                    <p>
                        <?php submit_button( __( 'Batch Scan', 'tmwseo' ), 'secondary', 'submit', false, [ 'id' => 'tmwseo-gap-batch-submit' ] ); ?>
                        <span style="color:#888;font-size:12px;margin-left:8px;"><?php esc_html_e( 'Each keyword uses 1 DataForSEO credit (cached 24h).', 'tmwseo' ); ?></span>
                    </p>
                </form>
            </div>

        </div>
        <?php
    }

    private static function render_filter_bar( array $filters, string $base_url ): void {
        $statuses  = SerpGapStorage::allowed_statuses();
        $gap_types = SerpGapStorage::allowed_gap_types();
        $current_status   = sanitize_key( (string) ( $_GET['filter_status'] ?? '' ) );
        $current_gap_type = sanitize_key( (string) ( $_GET['filter_gap_type'] ?? '' ) );
        $current_source   = sanitize_key( (string) ( $_GET['filter_source'] ?? '' ) );
        $current_min      = (string) ( $_GET['filter_min_score'] ?? '' );
        $current_model    = (bool) ( $_GET['filter_model_only'] ?? false );
        $current_unassigned = (bool) ( $_GET['filter_unassigned'] ?? false );
        ?>
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:12px 0 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
            <input type="hidden" name="page" value="tmwseo-serp-gaps">

            <div>
                <label style="display:block;font-size:11px;font-weight:600;margin-bottom:3px;"><?php esc_html_e( 'Status', 'tmwseo' ); ?></label>
                <select name="filter_status" style="min-width:130px;">
                    <option value=""><?php esc_html_e( 'All statuses', 'tmwseo' ); ?></option>
                    <?php foreach ( $statuses as $s ) : ?>
                        <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $current_status, $s ); ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $s ) ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="display:block;font-size:11px;font-weight:600;margin-bottom:3px;"><?php esc_html_e( 'Gap type', 'tmwseo' ); ?></label>
                <select name="filter_gap_type" style="min-width:160px;">
                    <option value=""><?php esc_html_e( 'All types', 'tmwseo' ); ?></option>
                    <?php foreach ( $gap_types as $t ) : ?>
                        <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $current_gap_type, $t ); ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $t ) ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label style="display:block;font-size:11px;font-weight:600;margin-bottom:3px;"><?php esc_html_e( 'Source', 'tmwseo' ); ?></label>
                <select name="filter_source">
                    <option value=""><?php esc_html_e( 'All sources', 'tmwseo' ); ?></option>
                    <option value="manual"    <?php selected( $current_source, 'manual' ); ?>><?php esc_html_e( 'Manual', 'tmwseo' ); ?></option>
                    <option value="model"     <?php selected( $current_source, 'model' ); ?>><?php esc_html_e( 'Model-related', 'tmwseo' ); ?></option>
                    <option value="batch"     <?php selected( $current_source, 'batch' ); ?>><?php esc_html_e( 'Batch', 'tmwseo' ); ?></option>
                    <option value="candidates" <?php selected( $current_source, 'candidates' ); ?>><?php esc_html_e( 'Candidates', 'tmwseo' ); ?></option>
                </select>
            </div>

            <div>
                <label style="display:block;font-size:11px;font-weight:600;margin-bottom:3px;"><?php esc_html_e( 'Min score', 'tmwseo' ); ?></label>
                <input type="number" name="filter_min_score" value="<?php echo esc_attr( $current_min ); ?>" min="0" max="100" step="1" style="width:72px;">
            </div>

            <div style="display:flex;flex-direction:column;gap:4px;">
                <label style="font-size:11px;font-weight:600;">
                    <input type="checkbox" name="filter_model_only" value="1" <?php checked( $current_model ); ?>>
                    <?php esc_html_e( 'Model-related only', 'tmwseo' ); ?>
                </label>
                <label style="font-size:11px;font-weight:600;">
                    <input type="checkbox" name="filter_unassigned" value="1" <?php checked( $current_unassigned ); ?>>
                    <?php esc_html_e( 'Unreviewed only', 'tmwseo' ); ?>
                </label>
            </div>

            <div>
                <?php submit_button( __( 'Filter', 'tmwseo' ), 'secondary small', 'submit', false ); ?>
                <a href="<?php echo esc_url( $base_url ); ?>" class="button button-small" style="margin-left:4px;"><?php esc_html_e( 'Reset', 'tmwseo' ); ?></a>
            </div>
        </form>
        <?php
    }

    private static function render_table( array $rows, int $total, int $page_num, int $total_pages, int $per_page, string $base_url ): void {
        echo '<p style="color:#555;font-size:13px;">' . sprintf(
            esc_html( _n( '%s gap record total.', '%s gap records total.', $total, 'tmwseo' ) ),
            '<strong>' . number_format( $total ) . '</strong>'
        ) . '</p>';

        if ( empty( $rows ) ) {
            echo '<div class="tmwseo-card" style="padding:24px;text-align:center;color:#888;">';
            echo '<p>' . esc_html__( 'No SERP gap records yet.', 'tmwseo' ) . '</p>';
            echo '<p>' . esc_html__( 'Use the scan form above to analyse a keyword.', 'tmwseo' ) . '</p>';
            echo '</div>';
            return;
        }

        // ── Pagination ────────────────────────────────────────────────────
        $qs = self::build_filter_qs();
        echo '<div style="margin-bottom:8px;">' . self::pagination_links( $page_num, $total_pages, $base_url . $qs ) . '</div>';

        // ── Table ─────────────────────────────────────────────────────────
        echo '<table class="widefat striped tmwseo-serp-gaps-table" style="margin-top:0;">';
        echo '<thead><tr>';
        foreach ( [
            __( 'Keyword', 'tmwseo' ),
            __( 'Gap Score', 'tmwseo' ),
            __( 'Gap Types', 'tmwseo' ),
            __( 'Vol / KD', 'tmwseo' ),
            __( 'Why it\'s a gap', 'tmwseo' ),
            __( 'Suggested page', 'tmwseo' ),
            __( 'Status', 'tmwseo' ),
            __( 'Last scanned', 'tmwseo' ),
            __( 'Actions', 'tmwseo' ),
        ] as $col ) {
            echo '<th>' . esc_html( $col ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            self::render_row( $row );
        }

        echo '</tbody></table>';

        echo '<div style="margin-top:8px;">' . self::pagination_links( $page_num, $total_pages, $base_url . $qs ) . '</div>';
    }

    /** @param array<string,mixed> $row */
    private static function render_row( array $row ): void {
        $id       = (int) $row['id'];
        $keyword  = (string) $row['keyword'];
        $score    = (float) $row['serp_gap_score'];
        $types    = array_filter( explode( ',', (string) $row['gap_types'] ) );
        $vol      = $row['search_volume'] !== null ? number_format( (int) $row['search_volume'] ) : '—';
        $kd       = $row['difficulty'] !== null ? round( (float) $row['difficulty'], 1 ) . '%' : '—';
        $reason   = (string) ( $row['reason'] ?? '' );
        $pg_type  = (string) ( $row['suggested_page_type'] ?? '' );
        $title_a  = (string) ( $row['suggested_title_angle'] ?? '' );
        $h1_a     = (string) ( $row['suggested_h1_angle'] ?? '' );
        $status   = (string) ( $row['status'] ?? 'new' );
        $scanned  = (string) ( $row['last_scanned_at'] ?? '' );
        $exact    = (int) $row['exact_match_score'];
        $mod      = (int) $row['modifier_score'];
        $intent   = (int) $row['intent_score'];
        $spec     = (int) $row['specificity_score'];
        $weak     = (int) $row['weak_serp_score'];
        $misses   = (string) ( $row['modifier_misses'] ?? '' );

        $score_color = $score >= 60 ? '#c0392b' : ( $score >= 35 ? '#e67e22' : '#27ae60' );
        $base_action = esc_url( admin_url( 'admin-post.php' ) );

        echo '<tr id="gap-row-' . esc_attr( (string) $id ) . '">';

        // Keyword
        echo '<td><strong>' . esc_html( $keyword ) . '</strong></td>';

        // Gap score (large badge + mini dimension bar)
        echo '<td style="min-width:120px;">';
        echo '<span style="font-size:24px;font-weight:700;color:' . esc_attr( $score_color ) . ';">' . esc_html( (string) $score ) . '</span><span style="color:#888;"> /100</span>';
        echo '<div style="margin-top:6px;font-size:10px;color:#555;line-height:1.7;">';
        echo esc_html( sprintf( __( 'Exact: %d/30  Mod: %d/25  Intent: %d/20  Spec: %d/15  SERP: %d/10', 'tmwseo' ), $exact, $mod, $intent, $spec, $weak ) );
        echo '</div>';
        echo '</td>';

        // Gap types
        echo '<td style="min-width:140px;">';
        foreach ( $types as $t ) {
            $t = trim( $t );
            if ( $t === '' ) continue;
            $label = ucwords( str_replace( '_', ' ', $t ) );
            $color = match ( $t ) {
                'exact_phrase_gap'  => '#2980b9',
                'modifier_gap'      => '#8e44ad',
                'intent_gap'        => '#c0392b',
                'mixed_intent_gap'  => '#e67e22',
                'specificity_gap'   => '#16a085',
                'weak_serp_gap'     => '#7f8c8d',
                default             => '#555',
            };
            echo '<span style="display:inline-block;margin:2px 3px 2px 0;padding:2px 7px;background:' . esc_attr( $color ) . ';color:#fff;border-radius:10px;font-size:10px;white-space:nowrap;">' . esc_html( $label ) . '</span>';
        }
        if ( $misses !== '' ) {
            echo '<div style="font-size:10px;color:#888;margin-top:4px;">' . esc_html__( 'Missing: ', 'tmwseo' ) . esc_html( $misses ) . '</div>';
        }
        echo '</td>';

        // Vol / KD
        echo '<td style="white-space:nowrap;">' . esc_html( $vol ) . '<br><span style="font-size:10px;color:#888;">' . esc_html__( 'KD: ', 'tmwseo' ) . esc_html( $kd ) . '</span></td>';

        // Why it's a gap (collapsible)
        echo '<td style="max-width:260px;font-size:12px;">';
        if ( strlen( $reason ) > 100 ) {
            echo '<details><summary style="cursor:pointer;color:#0073aa;">' . esc_html( mb_substr( $reason, 0, 80 ) ) . '…</summary>';
            echo '<p style="margin-top:6px;">' . esc_html( $reason ) . '</p>';
            echo '</details>';
        } else {
            echo esc_html( $reason );
        }
        echo '</td>';

        // Suggested page / angles
        echo '<td style="font-size:12px;max-width:220px;">';
        if ( $pg_type !== '' ) {
            echo '<div style="font-weight:600;margin-bottom:4px;">' . esc_html( $pg_type ) . '</div>';
        }
        if ( $title_a !== '' ) {
            echo '<div style="color:#555;"><em>' . esc_html__( 'Title:', 'tmwseo' ) . '</em> ' . esc_html( $title_a ) . '</div>';
        }
        if ( $h1_a !== '' ) {
            echo '<div style="color:#555;"><em>' . esc_html__( 'H1:', 'tmwseo' ) . '</em> ' . esc_html( $h1_a ) . '</div>';
        }
        echo '</td>';

        // Status
        $status_colors = [
            'new'           => '#7f8c8d',
            'reviewing'     => '#2980b9',
            'not_a_gap'     => '#bdc3c7',
            'brief_created' => '#27ae60',
            'attached'      => '#16a085',
            'opportunity'   => '#8e44ad',
        ];
        $sc = $status_colors[ $status ] ?? '#888';
        echo '<td><span style="padding:2px 8px;background:' . esc_attr( $sc ) . ';color:#fff;border-radius:10px;font-size:11px;">' . esc_html( ucwords( str_replace( '_', ' ', $status ) ) ) . '</span></td>';

        // Last scanned
        echo '<td style="font-size:11px;white-space:nowrap;color:#888;">';
        echo $scanned !== '' ? esc_html( wp_date( 'd M Y H:i', strtotime( $scanned ) ) ) : '—';
        echo '</td>';

        // Actions
        echo '<td style="white-space:nowrap;min-width:160px;">';

        // Create Brief
        echo '<form method="post" action="' . $base_action . '" style="display:inline;">';
        wp_nonce_field( 'tmwseo_serp_gap_create_brief', '_wpnonce', true, true );
        echo '<input type="hidden" name="action" value="tmwseo_serp_gap_create_brief">';
        echo '<input type="hidden" name="gap_id" value="' . esc_attr( (string) $id ) . '">';
        echo '<button type="submit" class="button button-small" title="' . esc_attr__( 'Create Content Brief', 'tmwseo' ) . '">' . esc_html__( 'Brief', 'tmwseo' ) . '</button>';
        echo '</form> ';

        // Convert to Opportunity
        echo '<form method="post" action="' . $base_action . '" style="display:inline;">';
        wp_nonce_field( 'tmwseo_serp_gap_to_opportunity', '_wpnonce', true, true );
        echo '<input type="hidden" name="action" value="tmwseo_serp_gap_to_opportunity">';
        echo '<input type="hidden" name="gap_id" value="' . esc_attr( (string) $id ) . '">';
        echo '<button type="submit" class="button button-small" title="' . esc_attr__( 'Add to Opportunities', 'tmwseo' ) . '">' . esc_html__( 'Opp', 'tmwseo' ) . '</button>';
        echo '</form> ';

        // Rescan (AJAX)
        echo '<button class="button button-small tmwseo-gap-rescan" data-id="' . esc_attr( (string) $id ) . '" title="' . esc_attr__( 'Re-scan this keyword', 'tmwseo' ) . '">' . esc_html__( 'Rescan', 'tmwseo' ) . '</button>';
        echo '<br style="margin-bottom:4px;">';

        // Mark Not a Gap
        echo '<form method="post" action="' . $base_action . '" style="display:inline;">';
        wp_nonce_field( 'tmwseo_serp_gap_update_status', '_wpnonce', true, true );
        echo '<input type="hidden" name="action" value="tmwseo_serp_gap_update_status">';
        echo '<input type="hidden" name="gap_id" value="' . esc_attr( (string) $id ) . '">';
        echo '<input type="hidden" name="status" value="not_a_gap">';
        echo '<button type="submit" class="button button-small" style="color:#c0392b;" title="' . esc_attr__( 'Mark as Not a Gap', 'tmwseo' ) . '">' . esc_html__( '✗ Not a gap', 'tmwseo' ) . '</button>';
        echo '</form>';

        echo '</td>';
        echo '</tr>';
    }

    // ── Inline JS ─────────────────────────────────────────────────────────────

    private static function render_inline_js( bool $provider_ok ): void {
        $nonce = wp_create_nonce( 'tmwseo_serp_gap_ajax' );
        $ajax  = esc_url( admin_url( 'admin-ajax.php' ) );
        ?>
        <script>
        (function(){
            // Single scan loading spinner
            var form = document.getElementById('tmwseo-gap-single-form');
            if(form){
                form.addEventListener('submit',function(){
                    document.getElementById('tmwseo-gap-loading').style.display='inline';
                    document.getElementById('tmwseo-gap-submit').disabled=true;
                });
            }

            // Rescan buttons
            document.querySelectorAll('.tmwseo-gap-rescan').forEach(function(btn){
                btn.addEventListener('click',function(){
                    var id = this.getAttribute('data-id');
                    this.textContent = '<?php echo esc_js( __( 'Scanning…', 'tmwseo' ) ); ?>';
                    this.disabled = true;

                    var fd = new FormData();
                    fd.append('action','tmwseo_serp_gap_rescan');
                    fd.append('gap_id', id);
                    fd.append('_ajax_nonce','<?php echo esc_js( $nonce ); ?>');

                    fetch('<?php echo $ajax; ?>',{method:'POST',body:fd})
                        .then(r=>r.json())
                        .then(data=>{
                            if(data.success){
                                var row = document.getElementById('gap-row-'+id);
                                if(row) row.style.opacity = '0.5';
                                // Soft-reload row by refreshing the page
                                window.location.reload();
                            } else {
                                alert('<?php echo esc_js( __( 'Rescan error: ', 'tmwseo' ) ); ?>' + (data.data&&data.data.message||'unknown'));
                                this.textContent = '<?php echo esc_js( __( 'Rescan', 'tmwseo' ) ); ?>';
                                this.disabled = false;
                            }
                        }).catch(()=>{
                            this.textContent = '<?php echo esc_js( __( 'Rescan', 'tmwseo' ) ); ?>';
                            this.disabled = false;
                        });
                });
            });
        })();
        </script>
        <?php
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private static function get_active_filters(): array {
        $filters = [];

        $status = sanitize_key( (string) ( $_GET['filter_status'] ?? '' ) );
        if ( $status !== '' ) $filters['status'] = $status;

        $gap_type = sanitize_key( (string) ( $_GET['filter_gap_type'] ?? '' ) );
        if ( $gap_type !== '' ) $filters['gap_type'] = $gap_type;

        $source = sanitize_key( (string) ( $_GET['filter_source'] ?? '' ) );
        if ( $source !== '' ) $filters['source'] = $source;

        $min = (string) ( $_GET['filter_min_score'] ?? '' );
        if ( $min !== '' ) $filters['min_score'] = (float) $min;

        if ( ! empty( $_GET['filter_model_only'] ) ) $filters['model_only'] = true;
        if ( ! empty( $_GET['filter_unassigned'] ) )  $filters['unassigned'] = true;

        return $filters;
    }

    private static function build_filter_qs(): string {
        $params = [];
        foreach ( [ 'filter_status', 'filter_gap_type', 'filter_source', 'filter_min_score', 'filter_model_only', 'filter_unassigned' ] as $k ) {
            if ( ! empty( $_GET[ $k ] ) ) {
                $params[ $k ] = sanitize_text_field( (string) $_GET[ $k ] );
            }
        }
        return ! empty( $params ) ? '&' . http_build_query( $params ) : '';
    }

    private static function pagination_links( int $current, int $total, string $base_url ): string {
        if ( $total <= 1 ) return '';

        $out = '<span style="font-size:13px;">';
        if ( $current > 1 ) {
            $out .= '<a href="' . esc_url( $base_url . '&paged=' . ( $current - 1 ) ) . '" class="button button-small">← ' . esc_html__( 'Prev', 'tmwseo' ) . '</a> ';
        }
        $out .= sprintf( esc_html__( 'Page %d of %d', 'tmwseo' ), $current, $total );
        if ( $current < $total ) {
            $out .= ' <a href="' . esc_url( $base_url . '&paged=' . ( $current + 1 ) ) . '" class="button button-small">' . esc_html__( 'Next', 'tmwseo' ) . ' →</a>';
        }
        $out .= '</span>';

        return $out;
    }

    private static function require_caps(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized', 403 );
        }
    }

    private static function require_caps_ajax(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }
    }

    /** @param array<string,mixed> $args */
    private static function redirect( array $args ): never {
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=tmwseo-serp-gaps' ) ) );
        exit;
    }
}
