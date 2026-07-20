<?php
/**
 * Keyword Metrics CSV Importer
 *
 * Admin tool: TMW SEO Engine → Keywords → Import Metrics
 *
 * Safely enriches wp_tmw_keyword_candidates (and optionally wp_tmw_keyword_raw)
 * from CSV exports produced by:
 *   - Google Keyword Planner / Google Ads
 *   - Ahrefs
 *   - Semrush
 *   - DataForSEO exports
 *   - Manual / generic CSVs
 *
 * Design goals:
 *   - Non-destructive by default (no overwrites, no deletes, no approvals)
 *   - Timeout-safe: preview is lightweight; import runs in AJAX batches
 *   - Full audit trail via [TMW-KW-METRICS-IMPORT] debug log tags
 *
 * @package TMWSEO\Engine\Admin
 * @since   5.9.0
 */

namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Services\CsvUpload;
use TMWSEO\Engine\Services\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KeywordMetricsCsvImporter {

    // ── Constants ────────────────────────────────────────────────────────────

    const PAGE_SLUG       = 'tmwseo-kw-metrics-import';
    const VERSION         = '1.0.0';
    const MAX_FILE_BYTES  = 5242880; // 5 MB
    const PREVIEW_ROWS    = 50;
    const TRANSIENT_TTL   = 1800; // 30 min
    const DEFAULT_BATCH   = 200;

    /** Column aliases → normalised internal name */
    private const KEYWORD_ALIASES = [
        'keyword'      => 'keyword',
        'seed_keyword' => 'keyword',
        'query'        => 'keyword',
        'search_term'  => 'keyword',
        'keyword_text' => 'keyword',
    ];

    private const VOLUME_ALIASES = [
        'volume'                  => 'volume',
        'search_volume'           => 'volume',
        'monthly_searches'        => 'volume',
        'avg_monthly_searches'    => 'volume',
        'avg. monthly searches'   => 'volume',
        'impressions'             => 'volume',
        'avg monthly searches'    => 'volume',
    ];

    private const DIFFICULTY_ALIASES = [
        'kd'                 => 'difficulty',
        'difficulty'         => 'difficulty',
        'keyword_difficulty' => 'difficulty',
        'seo_difficulty'     => 'difficulty',
    ];

    private const CPC_ALIASES = [
        'cpc'     => 'cpc',
        'avg_cpc' => 'cpc',
    ];

    private const COMPETITION_ALIASES = [
        'competition'       => 'competition',
        'competition_index' => 'competition',
    ];

    // ── Bootstrap ────────────────────────────────────────────────────────────

    public static function init(): void {
        // Admin-post: upload + preview (full-page)
        add_action( 'admin_post_tmw_kw_metrics_upload_preview',  [ __CLASS__, 'handle_upload_preview' ] );
        // Admin-post: confirm + kick off first batch (full-page, no-JS fallback)
        add_action( 'admin_post_tmw_kw_metrics_import_confirm',  [ __CLASS__, 'handle_import_confirm' ] );
        // AJAX: process a single batch
        add_action( 'wp_ajax_tmw_kw_metrics_import_batch',       [ __CLASS__, 'ajax_import_batch' ] );
        // AJAX: finalise after last batch
        add_action( 'wp_ajax_tmw_kw_metrics_import_finalise',    [ __CLASS__, 'ajax_import_finalise' ] );
    }

    // ── Admin page renderer ──────────────────────────────────────────────────

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Import Keyword Metrics', 'tmwseo' ) . '</h1>';
        echo '<p>' . esc_html__( 'Upload a CSV exported from Google Keyword Planner, Ahrefs, Semrush, DataForSEO, or any keyword tool to update Volume, KD, CPC, and Competition for existing keyword candidates.', 'tmwseo' ) . '</p>';

        // Show pending preview if transient exists
        $pending_key = get_transient( 'tmwseo_kw_metrics_pending_key_' . get_current_user_id() );
        if ( $pending_key ) {
            $data = get_transient( $pending_key );
            if ( $data ) {
                self::render_preview_confirm( $data, $pending_key );
                echo '</div>';
                return;
            }
        }

        // Show last import summary if available
        $last = get_option( 'tmwseo_kw_metrics_last_import', [] );
        if ( ! empty( $last ) ) {
            self::render_last_import_notice( $last );
        }

        self::render_upload_form();
        echo '</div>';
    }

    // ── Upload form ──────────────────────────────────────────────────────────

    private static function render_upload_form(): void {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="max-width:640px;">
            <?php wp_nonce_field( 'tmwseo_kw_metrics_upload', 'tmwseo_kw_metrics_nonce' ); ?>
            <input type="hidden" name="action" value="tmw_kw_metrics_upload_preview">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="kw_metrics_csv"><?php esc_html_e( 'CSV File', 'tmwseo' ); ?></label></th>
                    <td>
                        <input type="file" id="kw_metrics_csv" name="kw_metrics_csv" accept=".csv,text/csv" required>
                        <p class="description"><?php esc_html_e( 'Max 5 MB. Required column: keyword (or seed_keyword, query, search_term). Optional: volume, kd, cpc, competition, intent, source, notes.', 'tmwseo' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="kw_metrics_source"><?php esc_html_e( 'Data Source', 'tmwseo' ); ?></label></th>
                    <td>
                        <select id="kw_metrics_source" name="kw_metrics_source">
                            <option value="csv_import"><?php esc_html_e( 'Unknown / Generic CSV', 'tmwseo' ); ?></option>
                            <option value="google_keyword_planner"><?php esc_html_e( 'Google Keyword Planner', 'tmwseo' ); ?></option>
                            <option value="ahrefs"><?php esc_html_e( 'Ahrefs', 'tmwseo' ); ?></option>
                            <option value="semrush"><?php esc_html_e( 'Semrush', 'tmwseo' ); ?></option>
                            <option value="dataforseo"><?php esc_html_e( 'DataForSEO export', 'tmwseo' ); ?></option>
                            <option value="manual"><?php esc_html_e( 'Manual / other', 'tmwseo' ); ?></option>
                        </select>
                    </td>
                </tr>
            </table>

            <hr>
            <h3><?php esc_html_e( 'Import Options', 'tmwseo' ); ?></h3>
            <table class="form-table" role="presentation">
                <?php self::render_checkbox_row( 'opt_create_missing',    false, __( 'Create missing candidates', 'tmwseo' ),              __( 'When ON: insert new candidate rows for CSV keywords not found in the DB.', 'tmwseo' ) ); ?>
                <?php self::render_checkbox_row( 'opt_overwrite',         false, __( 'Overwrite existing metrics', 'tmwseo' ),              __( 'When ON: allow non-zero existing metrics to be overwritten by CSV values.', 'tmwseo' ) ); ?>
                <?php self::render_checkbox_row( 'opt_update_intent',     false, __( 'Update intent from CSV', 'tmwseo' ),                  __( 'When ON: write the intent column from CSV.', 'tmwseo' ) ); ?>
                <?php self::render_checkbox_row( 'opt_update_status',     false, __( 'Update status from CSV', 'tmwseo' ),                  __( 'When ON: write the status column from CSV. Never auto-approves.', 'tmwseo' ) ); ?>
                <?php self::render_checkbox_row( 'opt_kd25_is_fallback',  true,  __( 'Treat KD = 25 as fallback/default', 'tmwseo' ),       __( 'When ON: rows where imported KD = 25.00 are flagged in notes and not written to difficulty.', 'tmwseo' ) ); ?>
                <?php self::render_checkbox_row( 'opt_mark_scored',       true,  __( 'Mark enriched rows as scored (status=new only)', 'tmwseo' ), __( 'When ON: candidates in status=new with volume > 0 after import are set to status=scored.', 'tmwseo' ) ); ?>
            </table>

            <?php submit_button( __( 'Upload & Preview', 'tmwseo' ), 'primary', 'submit', false ); ?>
        </form>
        <?php
    }

    private static function render_checkbox_row( string $name, bool $default, string $label, string $desc ): void {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html( $label ); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1"<?php checked( $default ); ?>>
                    <span class="description"><?php echo esc_html( $desc ); ?></span>
                </label>
            </td>
        </tr>
        <?php
    }

    // ── Handle upload + build preview ────────────────────────────────────────

    public static function handle_upload_preview(): void {
        Capabilities::ensure( 'manage_options', 'Unauthorized' );
        check_admin_referer( 'tmwseo_kw_metrics_upload', 'tmwseo_kw_metrics_nonce' );

        // Validate via shared CsvUpload — content-sniffs the bytes via
        // wp_check_filetype_and_ext instead of trusting the filename
        // extension. Existing redirect notices preserved by mapping the
        // validator's error codes to the page's notice keys.
        $check = CsvUpload::validate( $_FILES['kw_metrics_csv'] ?? null, self::MAX_FILE_BYTES ); // phpcs:ignore
        if ( ! ( $check['ok'] ?? false ) ) {
            $error_to_notice = [
                'no_file'      => 'kw_metrics_no_file',
                'upload_error' => 'kw_metrics_no_file',
                'not_uploaded' => 'kw_metrics_no_file',
                'too_large'    => 'kw_metrics_file_too_large',
                'bad_filetype' => 'kw_metrics_bad_ext',
            ];
            $notice = $error_to_notice[ (string) ( $check['error'] ?? '' ) ] ?? 'kw_metrics_bad_ext';
            wp_safe_redirect( add_query_arg( 'tmwseo_notice', $notice, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
            exit;
        }
        // Rebuild a $file-shaped view so the rest of this method keeps reading
        // from the same variable name without a wider refactor.
        $file = [
            'name'     => $check['name'],
            'tmp_name' => $check['tmp'],
            'size'     => $check['bytes'] ?? 0,
            'error'    => UPLOAD_ERR_OK,
        ];

        $source = sanitize_key( (string) ( $_POST['kw_metrics_source'] ?? 'csv_import' ) ); // phpcs:ignore
        $opts   = self::collect_options( $_POST ); // phpcs:ignore
        $opts['source'] = $source;

        // Parse entire CSV into memory (just the data we need)
        $parsed = self::parse_csv( $file['tmp_name'] );
        if ( is_wp_error( $parsed ) ) {
            wp_safe_redirect( add_query_arg( 'tmwseo_notice', 'kw_metrics_parse_error', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
            exit;
        }

        // Build preview stats via a lightweight DB lookup
        $preview = self::build_preview( $parsed, $opts );

        // Store everything in a transient
        $uid        = get_current_user_id();
        $batch_key  = 'tmwseo_kwm_' . wp_generate_password( 12, false );
        set_transient( $batch_key, [
            'rows'    => $parsed['rows'],
            'map'     => $parsed['map'],
            'opts'    => $opts,
            'preview' => $preview,
        ], self::TRANSIENT_TTL );

        // Pointer transient so render_page() can find it
        set_transient( 'tmwseo_kw_metrics_pending_key_' . $uid, $batch_key, self::TRANSIENT_TTL );

        error_log( '[TMW-KW-METRICS-IMPORT] upload_preview batch_key=' . $batch_key . ' total_rows=' . count( $parsed['rows'] ) . ' source=' . $source );

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
        exit;
    }

    // ── CSV Parser ───────────────────────────────────────────────────────────

    /**
     * Parse CSV file. Returns [ 'rows' => [...], 'map' => [...] ] or WP_Error.
     *
     * @param  string $path  Temp file path
     * @return array|\WP_Error
     */
    private static function parse_csv( string $path ) {
        $fh = fopen( $path, 'r' );
        if ( ! $fh ) {
            return new \WP_Error( 'open_failed', 'Could not open CSV file.' );
        }

        $raw_header = fgetcsv( $fh );
        if ( ! is_array( $raw_header ) || empty( $raw_header ) ) {
            fclose( $fh );
            return new \WP_Error( 'empty_header', 'CSV has no header row.' );
        }

        // Build column map: col_index → normalised_name
        $map        = [];
        $kw_col     = null;
        $all_aliases = array_merge(
            self::KEYWORD_ALIASES,
            self::VOLUME_ALIASES,
            self::DIFFICULTY_ALIASES,
            self::CPC_ALIASES,
            self::COMPETITION_ALIASES,
            [
                'intent'  => 'intent',
                'status'  => 'status',
                'source'  => 'source_label',
                'notes'   => 'import_notes',
                'country' => 'country',
                'language'=> 'language',
            ]
        );

        foreach ( $raw_header as $i => $col ) {
            $c = strtolower( trim( (string) $col ) );
            if ( isset( $all_aliases[ $c ] ) ) {
                $normalised = $all_aliases[ $c ];
                if ( ! isset( $map[ $normalised ] ) ) { // first-win
                    $map[ $normalised ] = (int) $i;
                }
                if ( $normalised === 'keyword' ) {
                    $kw_col = (int) $i;
                }
            }
        }

        if ( $kw_col === null ) {
            // Fall back to column 0
            $kw_col        = 0;
            $map['keyword'] = 0;
        }

        $rows = [];
        while ( ( $row = fgetcsv( $fh ) ) !== false ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $kw = isset( $row[ $kw_col ] ) ? strtolower( trim( preg_replace( '/\s+/', ' ', (string) $row[ $kw_col ] ) ) ) : '';
            if ( $kw === '' ) {
                continue;
            }
            $rows[ $kw ] = $row; // deduplicate within CSV by normalised keyword
        }

        fclose( $fh );

        if ( empty( $rows ) ) {
            return new \WP_Error( 'no_rows', 'CSV contains no valid keyword rows.' );
        }

        return [
            'rows'       => $rows,
            'map'        => $map,
            'raw_header' => $raw_header,
        ];
    }

    // ── Preview builder ──────────────────────────────────────────────────────

    /**
     * Lightweight: one bulk SELECT to count matches, then compute stats.
     */
    private static function build_preview( array $parsed, array $opts ): array {
        global $wpdb;

        $rows  = $parsed['rows'];
        $map   = $parsed['map'];
        $kws   = array_keys( $rows );
        $total = count( $kws );

        if ( $total === 0 ) {
            return [ 'total' => 0, 'matched' => 0, 'missing' => 0 ];
        }

        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cand_table ) ) === $cand_table;

        if ( ! $table_exists ) {
            return [ 'total' => $total, 'matched' => 0, 'missing' => $total, 'table_missing' => true ];
        }

        // Bulk fetch existing rows for matched keywords
        $placeholders  = implode( ',', array_fill( 0, $total, '%s' ) );
        $existing_rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT keyword, volume, difficulty, cpc, status FROM {$cand_table} WHERE keyword IN ({$placeholders})", ...$kws ), // phpcs:ignore
            ARRAY_A
        ) ?: [];

        $existing_map = [];
        foreach ( $existing_rows as $er ) {
            $existing_map[ $er['keyword'] ] = $er;
        }

        $stats = [
            'total'                => $total,
            'matched'              => 0,
            'missing'              => 0,
            'would_update_volume'  => 0,
            'would_update_kd'      => 0,
            'would_update_cpc'     => 0,
            'would_update_intent'  => 0,
            'would_create'         => 0,
            'kd25_flagged'         => 0,
        ];

        $has_vol  = isset( $map['volume'] );
        $has_kd   = isset( $map['difficulty'] );
        $has_cpc  = isset( $map['cpc'] );
        $has_int  = isset( $map['intent'] );

        // KD=25 detection: scan all kd values first
        $kd_values = [];
        if ( $has_kd ) {
            foreach ( $rows as $kw => $row ) {
                $kd_raw = isset( $row[ $map['difficulty'] ] ) ? trim( (string) $row[ $map['difficulty'] ] ) : '';
                if ( $kd_raw !== '' ) {
                    $kd_values[] = (float) $kd_raw;
                }
            }
        }
        $kd25_ratio = ( count( $kd_values ) > 0 )
            ? count( array_filter( $kd_values, fn( $v ) => abs( $v - 25.0 ) < 0.01 ) ) / count( $kd_values )
            : 0.0;
        $kd25_flag_batch = $opts['kd25_is_fallback'] && $kd25_ratio >= 0.70;

        foreach ( $rows as $kw => $row ) {
            if ( isset( $existing_map[ $kw ] ) ) {
                $stats['matched']++;
                $ex = $existing_map[ $kw ];

                if ( $has_vol ) {
                    $csv_vol = self::extract_int( $row, $map, 'volume' );
                    if ( $csv_vol !== null && $csv_vol > 0 ) {
                        $existing_vol = (int) ( $ex['volume'] ?? 0 );
                        if ( $opts['overwrite'] || $existing_vol === 0 ) {
                            $stats['would_update_volume']++;
                        }
                    }
                }

                if ( $has_kd && ! $kd25_flag_batch ) {
                    $csv_kd = self::extract_float( $row, $map, 'difficulty' );
                    if ( $csv_kd !== null && $csv_kd > 0 ) {
                        $existing_kd = (float) ( $ex['difficulty'] ?? 0 );
                        if ( $opts['overwrite'] || $existing_kd <= 0 ) {
                            $stats['would_update_kd']++;
                        }
                    }
                }

                if ( $has_cpc ) {
                    $csv_cpc = self::extract_float( $row, $map, 'cpc' );
                    if ( $csv_cpc !== null && $csv_cpc > 0 ) {
                        $existing_cpc = (float) ( $ex['cpc'] ?? 0 );
                        if ( $opts['overwrite'] || $existing_cpc <= 0 ) {
                            $stats['would_update_cpc']++;
                        }
                    }
                }

                if ( $has_int && $opts['update_intent'] ) {
                    $stats['would_update_intent']++;
                }
            } else {
                $stats['missing']++;
                if ( $opts['create_missing'] ) {
                    $stats['would_create']++;
                }
            }
        }

        $stats['kd25_flagged']      = $kd25_flag_batch ? count( $kd_values ) : 0;
        $stats['kd25_batch_flag']   = $kd25_flag_batch;
        $stats['kd25_ratio_pct']    = round( $kd25_ratio * 100, 1 );
        $stats['has_volume_col']    = $has_vol;
        $stats['has_kd_col']        = $has_kd;
        $stats['has_cpc_col']       = $has_cpc;
        $stats['column_map']        = $map;

        return $stats;
    }

    // ── Preview confirm UI ───────────────────────────────────────────────────

    private static function render_preview_confirm( array $data, string $batch_key ): void {
        $preview = $data['preview'];
        $opts    = $data['opts'];
        $total   = count( $data['rows'] );

        echo '<div style="background:#f0f6fc;border:1px solid #72aee6;padding:16px 20px;max-width:800px;margin-bottom:20px;">';
        echo '<h2 style="margin-top:0;">' . esc_html__( 'Import Preview', 'tmwseo' ) . '</h2>';

        if ( ! empty( $preview['table_missing'] ) ) {
            echo '<p style="color:#c00;">' . esc_html__( 'Error: wp_tmw_keyword_candidates table not found. Cannot import.', 'tmwseo' ) . '</p>';
            echo '</div>';
            self::render_cancel_form( $batch_key );
            return;
        }

        // Column map
        echo '<h3>' . esc_html__( 'Detected Columns', 'tmwseo' ) . '</h3>';
        echo '<table class="widefat" style="max-width:500px;">';
        echo '<thead><tr><th>' . esc_html__( 'CSV column (normalised)', 'tmwseo' ) . '</th><th>' . esc_html__( 'DB field', 'tmwseo' ) . '</th></tr></thead><tbody>';
        foreach ( $preview['column_map'] as $norm => $idx ) {
            echo '<tr><td>' . esc_html( $norm ) . '</td><td><code>' . esc_html( (string) $idx ) . '</code></td></tr>';
        }
        echo '</tbody></table>';

        // Stats
        echo '<h3>' . esc_html__( 'Import Summary', 'tmwseo' ) . '</h3>';
        echo '<table class="widefat" style="max-width:500px;">';
        $rows_data = [
            [ __( 'Total CSV rows', 'tmwseo' ),              $preview['total'] ],
            [ __( 'Matched existing candidates', 'tmwseo' ), $preview['matched'] ],
            [ __( 'Missing (no match)', 'tmwseo' ),          $preview['missing'] ],
            [ __( 'Would update Volume', 'tmwseo' ),         $preview['would_update_volume'] ],
            [ __( 'Would update KD/Difficulty', 'tmwseo' ),  $preview['would_update_kd'] ],
            [ __( 'Would update CPC', 'tmwseo' ),            $preview['would_update_cpc'] ],
            [ __( 'Would update Intent', 'tmwseo' ),         $preview['would_update_intent'] ],
            [ __( 'Would create new candidates', 'tmwseo' ), $preview['would_create'] ],
        ];
        foreach ( $rows_data as $rd ) {
            echo '<tr><td>' . esc_html( $rd[0] ) . '</td><td><strong>' . esc_html( (string) $rd[1] ) . '</strong></td></tr>';
        }
        echo '</tbody></table>';

        // KD=25 warning
        if ( ! empty( $preview['kd25_batch_flag'] ) ) {
            echo '<div style="background:#fff3cd;border:1px solid #ffc107;padding:10px 14px;margin-top:12px;">';
            echo '<strong>' . esc_html__( '⚠ KD fallback warning:', 'tmwseo' ) . '</strong> ';
            printf(
                esc_html__( '%1$s%% of imported KD values are exactly 25.00. This strongly suggests a default/fallback value, not real organic difficulty. KD will NOT be written to the database unless you uncheck "Treat KD=25 as fallback" on the upload form.', 'tmwseo' ),
                esc_html( (string) $preview['kd25_ratio_pct'] )
            );
            echo '</div>';
        }

        echo '</div>';

        // Confirm form
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="tmwseo-kw-metrics-confirm-form">';
        wp_nonce_field( 'tmwseo_kw_metrics_confirm', 'tmwseo_kw_metrics_nonce' );
        echo '<input type="hidden" name="action" value="tmw_kw_metrics_import_confirm">';
        echo '<input type="hidden" name="batch_key" value="' . esc_attr( $batch_key ) . '">';
        echo '<input type="hidden" name="total_rows" value="' . esc_attr( (string) $total ) . '">';
        echo '<input type="hidden" name="batch_size" value="' . esc_attr( (string) self::DEFAULT_BATCH ) . '">';

        echo '<div style="display:flex;gap:10px;align-items:center;">';
        echo '<button type="submit" class="button button-primary" id="tmwseo-kw-metrics-import-btn">' . esc_html__( 'Confirm Import', 'tmwseo' ) . '</button>';
        echo '&nbsp;';
        self::render_cancel_form( $batch_key, true );
        echo '</div>';
        echo '</form>';

        // Progress area (JS-enhanced)
        echo '<div id="tmwseo-kw-metrics-progress" style="display:none;margin-top:16px;">';
        echo '<div style="background:#e7e7e7;border-radius:4px;height:20px;width:400px;">';
        echo '<div id="tmwseo-kw-metrics-bar" style="background:#0073aa;height:20px;width:0;border-radius:4px;transition:width .3s;"></div>';
        echo '</div>';
        echo '<p id="tmwseo-kw-metrics-status"></p>';
        echo '</div>';

        self::enqueue_import_script( $total, $batch_key );
    }

    private static function render_cancel_form( string $batch_key, bool $inline = false ): void {
        if ( ! $inline ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px;">';
        }
        wp_nonce_field( 'tmwseo_kw_metrics_cancel', 'tmwseo_kw_metrics_cancel_nonce' );
        echo '<input type="hidden" name="action" value="tmw_kw_metrics_import_confirm">';
        echo '<input type="hidden" name="batch_key" value="' . esc_attr( $batch_key ) . '">';
        echo '<input type="hidden" name="cancel" value="1">';
        echo '<button type="submit" class="button">' . esc_html__( 'Cancel / Start Over', 'tmwseo' ) . '</button>';
        if ( ! $inline ) {
            echo '</form>';
        }
    }

    // ── JS for AJAX batch loop ───────────────────────────────────────────────

    private static function enqueue_import_script( int $total, string $batch_key ): void {
        $batch_size = self::DEFAULT_BATCH;
        ?>
        <script>
        (function($){
            var total     = <?php echo (int) $total; ?>;
            var batchKey  = <?php echo wp_json_encode( $batch_key ); ?>;
            var batchSize = <?php echo (int) $batch_size; ?>;
            var ajaxUrl   = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var nonce     = <?php echo wp_json_encode( wp_create_nonce( 'tmwseo_kw_metrics_batch' ) ); ?>;

            // Capture options from hidden fields
            function getOptions() {
                return {
                    batch_key:  batchKey,
                    batch_size: batchSize,
                    nonce:      nonce,
                };
            }

            function updateProgress(processed) {
                var pct = total > 0 ? Math.min(100, Math.round(processed / total * 100)) : 100;
                $('#tmwseo-kw-metrics-bar').css('width', pct + '%');
                $('#tmwseo-kw-metrics-status').text(processed + ' / ' + total + ' rows processed (' + pct + '%)');
            }

            function runBatch(offset, summary) {
                var data = getOptions();
                data.action = 'tmw_kw_metrics_import_batch';
                data.offset = offset;
                $.post(ajaxUrl, data, function(resp) {
                    if (!resp || !resp.success) {
                        $('#tmwseo-kw-metrics-status').text('Error at offset ' + offset + ': ' + (resp && resp.data ? resp.data : 'unknown'));
                        return;
                    }
                    var d = resp.data;
                    // Accumulate summary
                    ['matched','updated','created','attempted_create','skipped','failed_updates','failed_creates','volume_updated','kd_updated','cpc_updated','intent_updated','status_updated'].forEach(function(k){
                        summary[k] = (summary[k] || 0) + (d.summary[k] || 0);
                    });
                    updateProgress(d.processed_total);
                    if (d.next_offset < total) {
                        runBatch(d.next_offset, summary);
                    } else {
                        finalise(summary);
                    }
                }, 'json').fail(function(){
                    $('#tmwseo-kw-metrics-status').text('AJAX request failed at offset ' + offset + '. Check debug.log.');
                });
            }

            function finalise(summary) {
                $.post(ajaxUrl, {
                    action:    'tmw_kw_metrics_import_finalise',
                    batch_key: batchKey,
                    summary:   JSON.stringify(summary),
                    nonce:     nonce,
                }, function(resp) {
                    if (resp && resp.success) {
                        window.location = resp.data.redirect;
                    } else {
                        $('#tmwseo-kw-metrics-status').text('Import complete but finalise failed — check debug.log.');
                    }
                }, 'json');
            }

            $('#tmwseo-kw-metrics-confirm-form').on('submit', function(e){
                if ($('input[name="cancel"]', this).length) { return true; }
                e.preventDefault();
                $('#tmwseo-kw-metrics-import-btn').prop('disabled', true).text('Importing…');
                $('#tmwseo-kw-metrics-progress').show();
                updateProgress(0);
                runBatch(0, {});
            });
        })(jQuery);
        </script>
        <?php
    }

    // ── Handle confirm (no-JS fallback + cancel) ─────────────────────────────

    public static function handle_import_confirm(): void {
        global $wpdb;
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'tmwseo_kw_metrics_confirm', 'tmwseo_kw_metrics_nonce' );

        $batch_key = sanitize_key( (string) ( $_POST['batch_key'] ?? '' ) ); // phpcs:ignore
        $uid       = get_current_user_id();

        // Cancel
        if ( ! empty( $_POST['cancel'] ) ) { // phpcs:ignore
            delete_transient( $batch_key );
            delete_transient( 'tmwseo_kw_metrics_pending_key_' . $uid );
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tmwseo_notice=kw_metrics_cancelled' ) );
            exit;
        }

        $data = get_transient( $batch_key );
        if ( ! $data ) {
            wp_safe_redirect( add_query_arg( 'tmwseo_notice', 'kw_metrics_expired', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
            exit;
        }

        $total      = count( $data['rows'] );
        $batch_size = max( 50, min( 500, (int) ( $_POST['batch_size'] ?? self::DEFAULT_BATCH ) ) ); // phpcs:ignore
        $offset     = max( 0, (int) ( $_POST['offset'] ?? 0 ) ); // phpcs:ignore

        error_log( '[TMW-KW-METRICS-IMPORT] import_started batch_key=' . $batch_key . ' offset=' . $offset . ' total=' . $total );

        $summary = self::process_batch( $data, $offset, $batch_size );
        $new_offset = $offset + $batch_size;

        if ( $new_offset >= $total ) {
            // Done
            delete_transient( $batch_key );
            delete_transient( 'tmwseo_kw_metrics_pending_key_' . $uid );
            update_option( 'tmwseo_kw_metrics_last_import', array_merge( $summary, [
                'source'     => $data['opts']['source'],
                'batch_key'  => $batch_key,
                'imported_at'=> current_time( 'mysql' ),
                'last_error' => $wpdb->last_error,
            ] ) );
            error_log( '[TMW-KW-METRICS-IMPORT] import_completed batch_key=' . $batch_key . ' ' . wp_json_encode( $summary ) );
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tmwseo_notice=kw_metrics_done' ) );
        } else {
            // More batches — render a "Continue" page (no-JS fallback)
            wp_safe_redirect( add_query_arg( [
                'page'             => self::PAGE_SLUG,
                'tmwseo_notice'    => 'kw_metrics_batch_done',
                'batch_key'        => $batch_key,
                'offset'           => $new_offset,
                'total'            => $total,
            ], admin_url( 'admin.php' ) ) );
        }
        exit;
    }

    // ── AJAX: process batch ──────────────────────────────────────────────────

    public static function ajax_import_batch(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        check_ajax_referer( 'tmwseo_kw_metrics_batch', 'nonce' );

        $batch_key  = sanitize_key( (string) ( $_POST['batch_key'] ?? '' ) ); // phpcs:ignore
        $offset     = max( 0, (int) ( $_POST['offset'] ?? 0 ) ); // phpcs:ignore
        $batch_size = max( 50, min( 500, (int) ( $_POST['batch_size'] ?? self::DEFAULT_BATCH ) ) ); // phpcs:ignore

        $data = get_transient( $batch_key );
        if ( ! $data ) {
            wp_send_json_error( 'Transient expired or not found.' );
        }

        $total   = count( $data['rows'] );
        $summary = self::process_batch( $data, $offset, $batch_size );

        wp_send_json_success( [
            'processed_total' => min( $total, $offset + $batch_size ),
            'next_offset'     => $offset + $batch_size,
            'total'           => $total,
            'summary'         => $summary,
        ] );
    }

    // ── AJAX: finalise ───────────────────────────────────────────────────────

    public static function ajax_import_finalise(): void {
        global $wpdb;
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        check_ajax_referer( 'tmwseo_kw_metrics_batch', 'nonce' );

        $batch_key   = sanitize_key( (string) ( $_POST['batch_key'] ?? '' ) ); // phpcs:ignore
        $summary_raw = (string) ( $_POST['summary'] ?? '{}' ); // phpcs:ignore
        $summary     = json_decode( wp_unslash( $summary_raw ), true ) ?: [];
        $uid         = get_current_user_id();

        $data = get_transient( $batch_key );
        $source = $data ? ( $data['opts']['source'] ?? 'csv_import' ) : 'csv_import';

        delete_transient( $batch_key );
        delete_transient( 'tmwseo_kw_metrics_pending_key_' . $uid );

        $final = array_merge( $summary, [
            'source'      => $source,
            'batch_key'   => $batch_key,
            'imported_at' => current_time( 'mysql' ),
            'last_error'  => $wpdb->last_error,
        ] );
        update_option( 'tmwseo_kw_metrics_last_import', $final );

        error_log( '[TMW-KW-METRICS-IMPORT] import_completed batch_key=' . $batch_key . ' ' . wp_json_encode( $final ) );

        wp_send_json_success( [
            'redirect' => admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tmwseo_notice=kw_metrics_done' ),
        ] );
    }

    // ── Core batch processor ─────────────────────────────────────────────────

    /**
     * Process a slice of parsed rows and update wp_tmw_keyword_candidates.
     *
     * @param  array $data        Transient data: rows, map, opts
     * @param  int   $offset      Start index
     * @param  int   $batch_size  Max rows to process
     * @return array              Summary counts for this batch
     */
    private static function process_batch( array $data, int $offset, int $batch_size ): array {
        global $wpdb;

        $all_rows   = $data['rows'];
        $map        = $data['map'];
        $opts       = $data['opts'];
        $now        = current_time( 'mysql' );
        $source     = sanitize_key( $opts['source'] ?? 'csv_import' );
        $batch_id   = substr( md5( ( $opts['batch_id'] ?? '' ) . $offset ), 0, 8 );

        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';
        // Table guard
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cand_table ) ) !== $cand_table ) {
            return [ 'error' => 'candidates_table_missing' ];
        }
        $table_columns_raw = $wpdb->get_col( "SHOW COLUMNS FROM {$cand_table}", 0 );
        $table_columns     = is_array( $table_columns_raw ) ? array_fill_keys( $table_columns_raw, true ) : [];

        $keys  = array_keys( $all_rows );
        $slice = array_slice( $keys, $offset, $batch_size );

        if ( empty( $slice ) ) {
            return [];
        }

        // Bulk fetch existing candidates for this slice
        $placeholders  = implode( ',', array_fill( 0, count( $slice ), '%s' ) );
        $existing_rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT id, keyword, volume, difficulty, cpc, status, notes, volume_source, cpc_source FROM {$cand_table} WHERE keyword IN ({$placeholders})", ...$slice ), // phpcs:ignore
            ARRAY_A
        ) ?: [];

        $existing_map = [];
        foreach ( $existing_rows as $er ) {
            $existing_map[ $er['keyword'] ] = $er;
        }

        // KD=25 batch detection for this slice
        $kd_vals_slice = [];
        if ( isset( $map['difficulty'] ) ) {
            foreach ( $slice as $kw ) {
                $row = $all_rows[ $kw ];
                $kd_raw = isset( $row[ $map['difficulty'] ] ) ? trim( (string) $row[ $map['difficulty'] ] ) : '';
                if ( $kd_raw !== '' ) {
                    $kd_vals_slice[] = (float) $kd_raw;
                }
            }
        }
        $kd25_ratio     = ( count( $kd_vals_slice ) > 0 )
            ? count( array_filter( $kd_vals_slice, fn( $v ) => abs( $v - 25.0 ) < 0.01 ) ) / count( $kd_vals_slice )
            : 0.0;
        $kd25_flag_batch = $opts['kd25_is_fallback'] && $kd25_ratio >= 0.70;

        $summary = [
            'matched'        => 0,
            'updated'        => 0,
            'created'        => 0,
            'attempted_create' => 0,
            'skipped'        => 0,
            'failed_updates' => 0,
            'failed_creates' => 0,
            'volume_updated' => 0,
            'kd_updated'     => 0,
            'cpc_updated'    => 0,
            'intent_updated' => 0,
            'status_updated' => 0,
        ];

        foreach ( $slice as $kw ) {
            $row = $all_rows[ $kw ];

            if ( isset( $existing_map[ $kw ] ) ) {
                $ex        = $existing_map[ $kw ];
                $updates   = [];
                $note_tags = [];
                $summary['matched']++;

                // Volume
                if ( isset( $map['volume'] ) ) {
                    $csv_vol = self::extract_int( $row, $map, 'volume' );
                    if ( $csv_vol !== null && $csv_vol > 0 ) {
                        $existing_vol = (int) ( $ex['volume'] ?? 0 );
                        if ( $opts['overwrite'] || $existing_vol === 0 ) {
                            $updates['volume']        = $csv_vol;
                            $updates['volume_source'] = $source;
                            $note_tags[]              = 'vol=' . $source;
                        }
                    }
                }

                // KD / Difficulty
                if ( isset( $map['difficulty'] ) && ! $kd25_flag_batch ) {
                    $csv_kd = self::extract_float( $row, $map, 'difficulty' );
                    if ( $csv_kd !== null && $csv_kd > 0 ) {
                        if ( $opts['kd25_is_fallback'] && abs( $csv_kd - 25.0 ) < 0.01 ) {
                            $note_tags[] = 'kd=25_fallback';
                        } else {
                            $existing_kd = (float) ( $ex['difficulty'] ?? 0 );
                            if ( $opts['overwrite'] || $existing_kd <= 0 ) {
                                $updates['difficulty'] = $csv_kd;
                                $note_tags[]           = 'kd=' . $source;
                            }
                        }
                    }
                } elseif ( isset( $map['difficulty'] ) && $kd25_flag_batch ) {
                    $note_tags[] = 'kd=25_batch_fallback';
                }

                // CPC
                if ( isset( $map['cpc'] ) ) {
                    $csv_cpc = self::extract_float( $row, $map, 'cpc' );
                    if ( $csv_cpc !== null && $csv_cpc > 0 ) {
                        $existing_cpc = (float) ( $ex['cpc'] ?? 0 );
                        if ( $opts['overwrite'] || $existing_cpc <= 0 ) {
                            $updates['cpc']        = $csv_cpc;
                            $updates['cpc_source'] = $source;
                            $note_tags[]           = 'cpc=' . $source;
                        }
                    }
                }

                // Intent (gated)
                if ( isset( $map['intent'] ) && $opts['update_intent'] ) {
                    $csv_intent = self::extract_string( $row, $map, 'intent' );
                    if ( $csv_intent !== '' ) {
                        $updates['intent'] = $csv_intent;
                    }
                }

                // Status (gated) — never approve
                if ( isset( $map['status'] ) && $opts['update_status'] ) {
                    $csv_status = self::extract_string( $row, $map, 'status' );
                    $forbidden  = [ 'approved', 'published', 'live' ];
                    if ( $csv_status !== '' && ! in_array( $csv_status, $forbidden, true ) ) {
                        $updates['status'] = $csv_status;
                    }
                }

                // Mark scored (status=new + volume > 0)
                if ( $opts['mark_scored'] && ( $ex['status'] ?? '' ) === 'new' ) {
                    $final_vol = isset( $updates['volume'] ) ? $updates['volume'] : (int) ( $ex['volume'] ?? 0 );
                    if ( $final_vol > 0 ) {
                        $updates['status'] = 'scored';
                    }
                }

                if ( ! empty( $updates ) ) {
                    // Append notes
                    $note_line   = '[Import ' . gmdate( 'Y-m-d' ) . ' src=' . $source . ' batch=' . $batch_id;
                    if ( ! empty( $note_tags ) ) {
                        $note_line .= ' ' . implode( ',', $note_tags );
                    }
                    $note_line  .= ']';
                    $old_notes   = (string) ( $ex['notes'] ?? '' );
                    $updates['notes']              = $old_notes !== '' ? $old_notes . "\n" . $note_line : $note_line;
                    $updates['metrics_updated_at'] = $now;
                    $updates['updated_at']         = $now;

                    $update_result = $wpdb->update(
                        $cand_table,
                        $updates,
                        [ 'id' => (int) $ex['id'] ]
                    );
                    if ( $update_result === false ) {
                        $summary['failed_updates']++;
                        error_log( '[TMW-KW-METRICS-IMPORT] row_update_failed kw=' . $kw . ' error=' . $wpdb->last_error );
                    } elseif ( $update_result === 0 ) {
                        $summary['skipped']++;
                        error_log( '[TMW-KW-METRICS-IMPORT] row_update_noop kw=' . $kw );
                    } else {
                        $summary['updated']++;
                        if ( isset( $updates['volume'] ) ) { $summary['volume_updated']++; }
                        if ( isset( $updates['difficulty'] ) ) { $summary['kd_updated']++; }
                        if ( isset( $updates['cpc'] ) ) { $summary['cpc_updated']++; }
                        if ( isset( $updates['intent'] ) ) { $summary['intent_updated']++; }
                        if ( isset( $updates['status'] ) ) { $summary['status_updated']++; }
                        error_log( '[TMW-KW-METRICS-IMPORT] row_updated kw=' . $kw . ' fields=' . implode( ',', array_keys( $updates ) ) );
                    }
                } else {
                    $summary['skipped']++;
                    error_log( '[TMW-KW-METRICS-IMPORT] row_skipped kw=' . $kw . ' reason=no_updateable_fields' );
                }
            } else {
                // No match
                if ( $opts['create_missing'] ) {
                    $summary['attempted_create']++;
                    $csv_vol         = isset( $map['volume'] ) ? self::extract_int( $row, $map, 'volume' ) : null;
                    $csv_kd          = ( isset( $map['difficulty'] ) && ! $kd25_flag_batch ) ? self::extract_float( $row, $map, 'difficulty' ) : null;
                    $csv_cpc         = isset( $map['cpc'] ) ? self::extract_float( $row, $map, 'cpc' ) : null;
                    $csv_competition = isset( $map['competition'] ) ? self::extract_float( $row, $map, 'competition' ) : null;
                    $source_label    = isset( $map['source'] ) ? sanitize_key( self::extract_string( $row, $map, 'source' ) ) : '';
                    $effective_source = $source_label !== '' ? $source_label : $source;
                    $note_line       = '[Import ' . gmdate( 'Y-m-d' ) . ' src=' . $effective_source . ' batch=' . $batch_id . ' new]';

                    $insert_data = [
                        'keyword'    => $kw,
                        'canonical'  => $kw,
                        'status'     => 'new',
                        'intent'     => str_contains( $kw, 'category' ) ? 'category' : 'mixed',
                        'volume'     => (int) ( $csv_vol ?? 0 ),
                        'cpc'        => (float) ( $csv_cpc ?? 0.0 ),
                        'difficulty' => (float) ( $csv_kd ?? 0.0 ),
                        'sources'    => 'csv_import:' . $effective_source,
                        'notes'      => $note_line,
                    ];
                    if ( isset( $table_columns['competition'] ) ) { $insert_data['competition'] = (float) ( $csv_competition ?? 0.0 ); }
                    if ( isset( $table_columns['volume_source'] ) ) { $insert_data['volume_source'] = $effective_source; }
                    if ( isset( $table_columns['cpc_source'] ) ) { $insert_data['cpc_source'] = $effective_source; }
                    if ( isset( $table_columns['metrics_updated_at'] ) ) { $insert_data['metrics_updated_at'] = $now; }
                    if ( isset( $table_columns['opportunity'] ) ) { $insert_data['opportunity'] = 0; }
                    if ( isset( $table_columns['updated_at'] ) ) { $insert_data['updated_at'] = $now; }
                    if ( isset( $table_columns['created_at'] ) ) { $insert_data['created_at'] = $now; }

                    $insert_result = $wpdb->insert( $cand_table, $insert_data );
                    if ( $insert_result === false ) {
                        if ( stripos( $wpdb->last_error, 'duplicate' ) !== false ) {
                            $summary['skipped']++;
                            error_log( '[TMW-KW-METRICS-IMPORT] row_create_skipped kw=' . $kw . ' reason=duplicate_or_existing' );
                        } else {
                            $summary['failed_creates']++;
                            error_log( '[TMW-KW-METRICS-IMPORT] row_create_failed kw=' . $kw . ' error=' . $wpdb->last_error );
                        }
                    } else {
                        $summary['created']++;
                        error_log( '[TMW-KW-METRICS-IMPORT] row_created kw=' . $kw . ' insert_id=' . (int) $wpdb->insert_id );
                    }
                } else {
                    $summary['skipped']++;
                    error_log( '[TMW-KW-METRICS-IMPORT] row_skipped kw=' . $kw . ' reason=no_candidate_match' );
                }
            }
        }

        return $summary;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function collect_options( array $post ): array {
        return [
            'create_missing'  => ! empty( $post['opt_create_missing'] ),
            'overwrite'       => ! empty( $post['opt_overwrite'] ),
            'update_intent'   => ! empty( $post['opt_update_intent'] ),
            'update_status'   => ! empty( $post['opt_update_status'] ),
            'kd25_is_fallback'=> empty( $post['opt_kd25_is_fallback'] ) ? false : true,  // default ON
            'mark_scored'     => empty( $post['opt_mark_scored'] ) ? false : true,         // default ON
            'batch_id'        => wp_generate_password( 6, false ),
        ];
    }

    private static function extract_int( array $row, array $map, string $field ): ?int {
        if ( ! isset( $map[ $field ] ) ) {
            return null;
        }
        $raw = preg_replace( '/[^0-9]/', '', (string) ( $row[ $map[ $field ] ] ?? '' ) );
        return ( $raw !== '' ) ? (int) $raw : null;
    }

    private static function extract_float( array $row, array $map, string $field ): ?float {
        if ( ! isset( $map[ $field ] ) ) {
            return null;
        }
        $raw = preg_replace( '/[^0-9.]/', '', (string) ( $row[ $map[ $field ] ] ?? '' ) );
        return ( $raw !== '' && is_numeric( $raw ) ) ? (float) $raw : null;
    }

    private static function extract_string( array $row, array $map, string $field ): string {
        if ( ! isset( $map[ $field ] ) ) {
            return '';
        }
        return sanitize_text_field( trim( (string) ( $row[ $map[ $field ] ] ?? '' ) ) );
    }

    // ── Last import notice ───────────────────────────────────────────────────

    private static function render_last_import_notice( array $last ): void {
        $has_warning = ( (int) ( $last['attempted_create'] ?? 0 ) > (int) ( $last['created'] ?? 0 ) )
            || ( (int) ( $last['failed_updates'] ?? 0 ) > 0 )
            || ( (int) ( $last['failed_creates'] ?? 0 ) > 0 )
            || ! empty( $last['last_error'] ?? '' );

        $fields = [
            __( 'Imported at', 'tmwseo' )         => $last['imported_at']     ?? '—',
            __( 'Source', 'tmwseo' )               => $last['source']          ?? '—',
            __( 'Matched candidates', 'tmwseo' )   => $last['matched']         ?? 0,
            __( 'Updated rows', 'tmwseo' )         => $last['updated']         ?? 0,
            __( 'Created rows', 'tmwseo' )         => $last['created']         ?? 0,
            __( 'Attempted creates', 'tmwseo' )    => $last['attempted_create'] ?? 0,
            __( 'Skipped rows', 'tmwseo' )         => $last['skipped']         ?? 0,
            __( 'Failed updates', 'tmwseo' )       => $last['failed_updates']  ?? 0,
            __( 'Failed creates', 'tmwseo' )       => $last['failed_creates']  ?? 0,
            __( 'Volume updated', 'tmwseo' )       => $last['volume_updated']  ?? 0,
            __( 'KD updated', 'tmwseo' )           => $last['kd_updated']      ?? 0,
            __( 'CPC updated', 'tmwseo' )          => $last['cpc_updated']     ?? 0,
            __( 'Intent updated', 'tmwseo' )       => $last['intent_updated']  ?? 0,
            __( 'Status updated', 'tmwseo' )       => $last['status_updated']  ?? 0,
        ];
        $notice_class = $has_warning ? 'notice notice-warning is-dismissible' : 'notice notice-success is-dismissible';
        echo '<div class="' . esc_attr( $notice_class ) . '" style="max-width:600px;">';
        echo '<p><strong>' . esc_html__( 'Last Import Summary', 'tmwseo' ) . '</strong></p><ul style="margin:.5em 0 .5em 1em;">';
        foreach ( $fields as $label => $value ) {
            echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( (string) $value ) . '</li>';
        }
        echo '</ul></div>';
    }
}
