<?php
/**
 * SerpGapEngine — orchestrates the SERP Keyword Gap analysis pipeline.
 *
 * Responsibilities:
 *  - Pull SERP items from DataForSEO (respects 24h cache + monthly budget)
 *  - Hand items to SerpGapScorer (pure, no I/O)
 *  - Enrich with volume/KD from keyword_candidates if available
 *  - Persist via SerpGapStorage
 *  - Return a structured result the admin page can use directly
 *
 * Does NOT:
 *  - Render admin HTML
 *  - Register menus
 *  - Handle HTTP requests directly
 *
 * @package TMWSEO\Engine\SerpGaps
 * @since   4.6.3
 */
namespace TMWSEO\Engine\SerpGaps;

use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SerpGapEngine {

    // ── Result codes ──────────────────────────────────────────────────────────

    public const RESULT_OK                = 'ok';
    public const RESULT_NO_PROVIDER       = 'no_provider';
    public const RESULT_BUDGET_EXCEEDED   = 'budget_exceeded';
    public const RESULT_SERP_FETCH_FAILED = 'serp_fetch_failed';
    public const RESULT_EMPTY_SERP        = 'empty_serp';
    public const RESULT_CACHED            = 'cached';

    // ── Public entry points ───────────────────────────────────────────────────

    /**
     * Analyse a single keyword for SERP gap.
     *
     * @param  string $keyword  The search query to evaluate.
     * @param  string $source   Where this keyword came from (manual, candidates, model, etc.)
     * @return array<string,mixed>  Always returns a structured result; check ['result_code'].
     */
    public function analyse( string $keyword, string $source = 'manual' ): array {
        $keyword = mb_strtolower( trim( $keyword ), 'UTF-8' );

        if ( $keyword === '' ) {
            return $this->error_result( 'empty_keyword', 'Keyword is empty.' );
        }

        // ── Provider check ───────────────────────────────────────────────────
        if ( ! DataForSEO::is_configured() ) {
            return $this->error_result( self::RESULT_NO_PROVIDER, 'DataForSEO is not configured. Please add credentials in Settings → Connections.' );
        }

        if ( DataForSEO::is_over_budget() ) {
            $stats = DataForSEO::get_monthly_budget_stats();
            return $this->error_result(
                self::RESULT_BUDGET_EXCEEDED,
                sprintf(
                    'Monthly DataForSEO budget of $%.2f exceeded ($%.2f spent). Scan blocked.',
                    (float) ( $stats['budget_usd'] ?? 0 ),
                    (float) ( $stats['spent_usd'] ?? 0 )
                )
            );
        }

        // ── SERP fetch ───────────────────────────────────────────────────────
        $serp = DataForSEO::serp_live( $keyword, 10 );

        if ( ! ( $serp['ok'] ?? false ) ) {
            Logs::warn( 'serp_gaps', '[SERP-GAP] SERP fetch failed', [
                'keyword' => $keyword,
                'error'   => $serp['error'] ?? 'unknown',
            ] );

            return $this->error_result(
                self::RESULT_SERP_FETCH_FAILED,
                'SERP fetch failed: ' . ( $serp['error'] ?? 'unknown error' )
            );
        }

        $items = (array) ( $serp['items'] ?? [] );

        if ( empty( $items ) ) {
            return $this->error_result( self::RESULT_EMPTY_SERP, 'SERP returned no organic results.' );
        }

        // ── Scoring ──────────────────────────────────────────────────────────
        $scorer = new SerpGapScorer();
        $scored = $scorer->score( $keyword, $items );

        // ── Enrich with existing keyword metrics ────────────────────────────
        $metrics = $this->fetch_keyword_metrics( $keyword );

        $payload = array_merge( $scored, [
            'source'       => sanitize_key( $source ),
            'serp_items'   => $items,
        ] );

        if ( $metrics !== null ) {
            $payload['search_volume']   = $metrics['volume'] ?? null;
            $payload['difficulty']      = $metrics['difficulty'] ?? null;
            $payload['opportunity_score'] = $metrics['opportunity'] ?? null;
        }

        // ── Persist ──────────────────────────────────────────────────────────
        $gap_id = SerpGapStorage::upsert( $payload );

        Logs::info( 'serp_gaps', '[SERP-GAP] Analysis complete', [
            'keyword'        => $keyword,
            'gap_id'         => $gap_id,
            'serp_gap_score' => $scored['serp_gap_score'],
            'gap_types'      => implode( ', ', (array) ( $scored['gap_types'] ?? [] ) ),
        ] );

        return array_merge( $payload, [
            'result_code' => self::RESULT_OK,
            'gap_id'      => $gap_id,
        ] );
    }

    /**
     * Batch-analyse a list of keywords.
     * Respects budget; stops the batch if budget is exceeded mid-run.
     *
     * @param  string[] $keywords
     * @return array<string,mixed>  [ 'processed', 'errors', 'results' ]
     */
    public function analyse_batch( array $keywords, string $source = 'batch' ): array {
        $processed = 0;
        $errors    = [];
        $results   = [];

        foreach ( $keywords as $kw ) {
            $kw = trim( (string) $kw );
            if ( $kw === '' ) {
                continue;
            }

            $result = $this->analyse( $kw, $source );

            if ( $result['result_code'] === self::RESULT_BUDGET_EXCEEDED ) {
                // Stop the batch — no point trying more keywords
                $errors[] = [ 'keyword' => $kw, 'error' => $result['message'] ];
                break;
            }

            if ( $result['result_code'] === self::RESULT_OK ) {
                $processed++;
                $results[] = $result;
            } else {
                $errors[] = [ 'keyword' => $kw, 'error' => $result['message'] ];
            }

            // Small throttle between batch calls to reduce API hammering
            if ( $processed > 0 && $processed % 5 === 0 ) {
                usleep( 300000 ); // 300ms
            }
        }

        return [
            'processed' => $processed,
            'errors'    => $errors,
            'results'   => $results,
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function fetch_keyword_metrics( string $keyword ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT volume, difficulty, opportunity FROM {$table} WHERE keyword = %s LIMIT 1",
                $keyword
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function error_result( string $code, string $message ): array {
        return [
            'result_code' => $code,
            'message'     => $message,
            'ok'          => false,
        ];
    }
}
