<?php
/**
 * CSVExporter — exports intelligence data as downloadable CSV files.
 *
 * Routes:
 *   /wp-admin/admin-post.php?action=tmwseo_export_csv&dataset=keywords&nonce=...
 *   datasets: keywords | opportunities | orphan_pages | ranking_probability | competitor_gaps | ai_token_log
 *
 * @package TMWSEO\Engine\Export
 */
namespace TMWSEO\Engine\Export;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use TMWSEO\Engine\AI\AIRouter;

class CSVExporter {

    public static function init(): void {
        add_action( 'admin_post_tmwseo_export_csv', [ __CLASS__, 'handle_export' ] );
    }

    public static function handle_export(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized', 403 );
        }

        $dataset = sanitize_key( $_GET['dataset'] ?? '' );
        $nonce   = sanitize_text_field( $_GET['nonce'] ?? '' );

        if ( ! wp_verify_nonce( $nonce, 'tmwseo_export_' . $dataset ) ) {
            wp_die( 'Invalid nonce', 403 );
        }

        switch ( $dataset ) {
            case 'keywords':
                self::export_keywords();
                break;
            case 'opportunities':
                self::export_opportunities();
                break;
            case 'orphan_pages':
                self::export_orphan_pages();
                break;
            case 'ranking_probability':
                self::export_ranking_probability();
                break;
            case 'competitor_gaps':
                self::export_competitor_gaps();
                break;
            case 'ai_token_log':
                self::export_ai_token_log();
                break;
            default:
                wp_die( 'Unknown dataset', 400 );
        }
    }

    // ── Export helpers ─────────────────────────────────────────────────────

    /**
     * Returns HTML for an export button pointing to a dataset.
     */
    public static function button( string $dataset, string $label = '' ): string {
        if ( $label === '' ) {
            $label = 'Export ' . ucfirst( str_replace( '_', ' ', $dataset ) ) . ' CSV';
        }
        $url = admin_url( 'admin-post.php' ) . '?' . http_build_query( [
            'action'  => 'tmwseo_export_csv',
            'dataset' => $dataset,
            'nonce'   => wp_create_nonce( 'tmwseo_export_' . $dataset ),
        ] );
        return '<a href="' . esc_url( $url ) . '" class="button button-secondary" style="margin-left:8px">'
            . esc_html( $label ) . '</a>';
    }

    // ── Dataset exporters ──────────────────────────────────────────────────

    private static function export_keywords(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_keyword_candidates';
        $rows  = $wpdb->get_results( "SELECT keyword, search_volume, difficulty, intent, source, created_at FROM {$table} ORDER BY search_volume DESC LIMIT 5000", ARRAY_A );

        self::stream_csv( 'tmwseo-keywords-' . date( 'Y-m-d' ) . '.csv',
            [ 'Keyword', 'Search Volume', 'Difficulty', 'Intent', 'Source', 'Created At' ],
            (array) $rows
        );
    }

    private static function export_opportunities(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_opportunities';
        $rows  = $wpdb->get_results( "SELECT keyword, opportunity_score, search_volume, difficulty, competitor_url, status, created_at FROM {$table} ORDER BY opportunity_score DESC LIMIT 5000", ARRAY_A );

        self::stream_csv( 'tmwseo-opportunities-' . date( 'Y-m-d' ) . '.csv',
            [ 'Keyword', 'Opportunity Score', 'Search Volume', 'Difficulty', 'Competitor URL', 'Status', 'Created At' ],
            (array) $rows
        );
    }

    private static function export_orphan_pages(): void {
        $result  = \TMWSEO\Engine\InternalLinks\OrphanPageDetector::get_results();
        $orphans = $result['orphans'] ?? [];

        $rows = [];
        foreach ( $orphans as $o ) {
            $rows[] = [
                'post_id'    => $o['post_id'],
                'post_type'  => $o['post_type'],
                'title'      => $o['title'],
                'permalink'  => $o['permalink'],
                'word_count' => $o['word_count'],
                'modified'   => $o['modified'],
            ];
        }

        self::stream_csv( 'tmwseo-orphan-pages-' . date( 'Y-m-d' ) . '.csv',
            [ 'Post ID', 'Post Type', 'Title', 'URL', 'Word Count', 'Last Modified' ],
            $rows
        );
    }

    private static function export_ranking_probability(): void {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_type,
                    pm1.meta_value AS focus_keyword,
                    pm2.meta_value AS ranking_probability,
                    pm3.meta_value AS ranking_tier,
                    pm4.meta_value AS calculated_at
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = p.ID AND pm1.meta_key = 'rank_math_focus_keyword'
             LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_tmwseo_ranking_probability'
             LEFT JOIN {$wpdb->postmeta} pm3 ON pm3.post_id = p.ID AND pm3.meta_key = '_tmwseo_ranking_tier'
             LEFT JOIN {$wpdb->postmeta} pm4 ON pm4.post_id = p.ID AND pm4.meta_key = '_tmwseo_ranking_probability_at'
             WHERE p.post_status = 'publish' AND pm2.meta_value IS NOT NULL
             ORDER BY CAST(pm2.meta_value AS DECIMAL) DESC
             LIMIT 2000",
            ARRAY_A
        );

        self::stream_csv( 'tmwseo-ranking-probability-' . date( 'Y-m-d' ) . '.csv',
            [ 'Post ID', 'Title', 'Post Type', 'Focus Keyword', 'Ranking Probability', 'Tier', 'Calculated At' ],
            (array) $rows
        );
    }

    private static function export_competitor_gaps(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_opportunities';
        $rows  = $wpdb->get_results(
            "SELECT keyword, search_volume, difficulty, competitor_url, opportunity_score, created_at
             FROM {$table}
             WHERE competitor_url != ''
             ORDER BY opportunity_score DESC LIMIT 5000",
            ARRAY_A
        );

        self::stream_csv( 'tmwseo-competitor-gaps-' . date( 'Y-m-d' ) . '.csv',
            [ 'Keyword', 'Search Volume', 'Difficulty', 'Competitor', 'Opportunity Score', 'Found At' ],
            (array) $rows
        );
    }

    private static function export_ai_token_log(): void {
        $stats = AIRouter::get_token_stats();
        $log   = $stats['tokens'] ?? [];

        $rows = [];
        foreach ( $log as $entry ) {
            $rows[] = [
                'ts'       => $entry['ts'] ?? '',
                'provider' => $entry['provider'] ?? '',
                'model'    => $entry['model'] ?? '',
                'in'       => $entry['in'] ?? 0,
                'out'      => $entry['out'] ?? 0,
                'cost'     => '$' . number_format( (float) ( $entry['cost'] ?? 0 ), 6 ),
            ];
        }

        self::stream_csv( 'tmwseo-ai-tokens-' . date( 'Y-m' ) . '.csv',
            [ 'Timestamp', 'Provider', 'Model', 'Input Tokens', 'Output Tokens', 'Cost (USD)' ],
            $rows
        );
    }

    // ── Streaming ─────────────────────────────────────────────────────────

    /**
     * Streams a CSV file directly to the browser.
     *
     * @param array  $headers Column headers
     * @param array  $rows    Rows as arrays (keys are ignored — values used in order)
     */
    private static function stream_csv( string $filename, array $headers, array $rows ): void {
        // Clean any output buffers
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $fh = fopen( 'php://output', 'w' );
        if ( ! $fh ) exit;

        // BOM for Excel UTF-8 compatibility
        fwrite( $fh, "\xEF\xBB\xBF" );

        fputcsv( $fh, $headers );

        foreach ( $rows as $row ) {
            fputcsv( $fh, array_values( (array) $row ) );
        }

        fclose( $fh );
        exit;
    }
}
