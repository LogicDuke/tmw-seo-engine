<?php
/**
 * KeywordScheduler — background keyword data maintenance.
 *
 * Runs TWO independent cron jobs that are safe in manual-control mode
 * because they ONLY update keyword CSV data files — they never write
 * to any post, RankMath field, or generate content.
 *
 *   tmwseo_engine_keyword_discovery  — weekly
 *     Expands keyword libraries via Google Suggest for each category.
 *
 *   tmwseo_engine_keyword_metrics    — weekly
 *     Refreshes search_volume, KD and competition for all library keywords
 *     via DataForSEO in batches of 300.
 *
 *   tmwseo_engine_keyword_prune      — weekly
 *     Prunes stale low-value keywords from the keyword candidate table.
 *
 * @package TMWSEO\Engine\Keywords
 */
namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\KeywordIntelligence\EntityCombinationEngine;

class KeywordScheduler {

    const HOOK_DISCOVERY = 'tmwseo_engine_keyword_discovery';
    const HOOK_METRICS   = 'tmwseo_engine_keyword_metrics';
    const HOOK_PRUNE     = 'tmwseo_engine_keyword_prune';

    // ── Boot ───────────────────────────────────────────────────────────────

    public static function init(): void {
        add_action( self::HOOK_DISCOVERY, [ __CLASS__, 'discover_new_keywords' ] );
        add_action( self::HOOK_METRICS,   [ __CLASS__, 'refresh_keyword_metrics' ] );
        add_action( self::HOOK_PRUNE,     [ __CLASS__, 'prune_stale_keywords' ] );
    }

    // ── Activation / deactivation ──────────────────────────────────────────

    public static function schedule(): void {
        if ( ! wp_next_scheduled( self::HOOK_DISCOVERY ) ) {
            wp_schedule_event( time() + 3600, 'tmwseo_weekly', self::HOOK_DISCOVERY );
        }
        if ( ! wp_next_scheduled( self::HOOK_METRICS ) ) {
            wp_schedule_event( time() + 7200, 'tmwseo_weekly', self::HOOK_METRICS );
        }
        if ( ! wp_next_scheduled( self::HOOK_PRUNE ) ) {
            wp_schedule_event( time() + 10800, 'tmwseo_weekly', self::HOOK_PRUNE );
        }
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook( self::HOOK_DISCOVERY );
        wp_clear_scheduled_hook( self::HOOK_METRICS );
        wp_clear_scheduled_hook( self::HOOK_PRUNE );
    }

    // ── Weekly: discover new keywords ─────────────────────────────────────

    /**
     * Uses Google Suggest to discover new keyword phrases per category,
     * appending them to uploads CSV files.
     */
    public static function discover_new_keywords(): void {
        $categories = CuratedKeywordLibrary::categories();
        $discovered = 0;

        foreach ( $categories as $category ) {
            $patterns = CuratedKeywordLibrary::get_seed_patterns( $category );
            $seeds    = array_slice( $patterns['seeds'] ?? [], 0, 10 );

            foreach ( $seeds as $seed ) {
                $suggestions = self::fetch_google_suggest_cached( $seed );
                if ( empty( $suggestions ) ) {
                    continue;
                }

                foreach ( $suggestions as $kw ) {
                    if ( self::append_keyword_to_csv( $category, 'extra', $kw ) ) {
                        $discovered++;
                    }
                }
            }
        }

        $entity_report = EntityCombinationEngine::expand_weekly_seeds();

        \TMWSEO\Engine\Logs::debug( 'keywords', '[TMW-KW-DISCOVERY] Seed discovery run complete', [
            'categories' => count( $categories ),
            'discovered' => $discovered,
            'entity_combinations_generated' => (int) ( $entity_report['combinations_generated'] ?? 0 ),
            'entity_new_seeds_created' => (int) ( $entity_report['new_seeds_created'] ?? 0 ),
            'entity_duplicates_skipped' => (int) ( $entity_report['duplicates_skipped'] ?? 0 ),
        ] );

        update_option( 'tmwseo_last_keyword_discovery', [
            'timestamp'  => current_time( 'mysql' ),
            'categories' => count( $categories ),
            'discovered' => $discovered,
        ], false );
    }

    // ── Weekly: refresh metrics ───────────────────────────────────────────

    /**
     * Refreshes search_volume, KD, and competition for library keywords
     * in batches of 300, picking up where it left off.
     */
    public static function refresh_keyword_metrics(): void {
        if ( ! DataForSEO::is_configured() ) {
            return;
        }

        $batch_size = 300;
        $state      = get_option( 'tmwseo_keyword_metrics_refresh_state', [] );
        $offset     = (int) ( $state['offset'] ?? 0 );

        // Collect all keywords from CSVs
        $all_refs = self::collect_all_keyword_refs();
        $total    = count( $all_refs );
        if ( $total === 0 ) {
            return;
        }

        // Rotate through all keywords across runs
        $offset = $total > 0 ? ( $offset % $total ) : 0;
        $batch  = array_slice( $all_refs, $offset, $batch_size );
        if ( count( $batch ) < $batch_size && $total > $batch_size ) {
            $batch = array_merge( $batch, array_slice( $all_refs, 0, $batch_size - count( $batch ) ) );
        }

        $keywords = array_column( $batch, 'keyword' );
        if ( empty( $keywords ) ) {
            return;
        }

        // Fetch metrics from DataForSEO
        $vols   = self::safe_search_volume( $keywords );
        \TMWSEO\Engine\Logs::debug( 'keywords', '[TMW-DFS] Keyword metrics batch refresh', [
            'batch_size' => count( $keywords ),
            'offset' => $offset,
            'total' => $total,
        ] );

        // Write metrics back to CSV files
        foreach ( $batch as $ref ) {
            $kw  = $ref['keyword'];
            $vol = $vols[ $kw ] ?? [];
            if ( empty( $vol ) ) {
                continue;
            }
            self::update_keyword_in_csv(
                $ref['category'],
                $ref['type'],
                $kw,
                [
                    'search_volume'     => $vol['search_volume'] ?? '',
                    'competition_level' => $vol['competition_level'] ?? '',
                    'competition'       => $vol['competition'] ?? '',
                    'cpc'               => $vol['cpc'] ?? '',
                ]
            );
        }

        $processed = count( $batch );
        update_option( 'tmwseo_keyword_metrics_refresh_state', [
            'timestamp' => current_time( 'mysql' ),
            'processed' => $processed,
            'offset'    => ( $offset + $processed ) % max( 1, $total ),
        ], false );
    }


    /**
     * Weekly pruning of stale low-volume keyword candidates.
     */
    public static function prune_stale_keywords(): void {
        global $wpdb;

        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $usage_table = $wpdb->prefix . 'tmwseo_keyword_usage';
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 180 * DAY_IN_SECONDS ) );

        $sql = "DELETE c FROM {$cand_table} c
                INNER JOIN {$usage_table} u ON u.keyword_text = c.keyword
                WHERE COALESCE(c.volume, 0) < 10
                  AND u.last_used_at IS NOT NULL
                  AND u.last_used_at <= %s";

        $deleted = (int) $wpdb->query( $wpdb->prepare( $sql, $cutoff ) );

        \TMWSEO\Engine\Logs::debug( 'keywords', '[TMW-KW-PRUNE] Weekly keyword pruning complete', [
            'deleted' => $deleted,
            'cutoff' => $cutoff,
        ] );
    }

    // ── Internal helpers ───────────────────────────────────────────────────

    /**
     * Fetches Google Suggest with transient caching.
     */
    private static function fetch_google_suggest_cached( string $query ): array {
        $query = trim( $query );
        if ( $query === '' ) {
            return [];
        }

        $cache_key = 'tmwseo_ks_suggest_' . md5( $query );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false && is_array( $cached ) ) {
            \TMWSEO\Engine\Logs::debug( 'keywords', '[TMW-KW-CACHE] Google suggest cache hit', [
                'query' => $query,
                'count' => count( $cached ),
            ] );
            return $cached;
        }

        $endpoints = [
            'https://suggestqueries.google.com/complete/search',
            'https://clients1.google.com/complete/search',
        ];

        $backoff_ms = [ 300, 900, 1800 ];
        $out        = [];

        foreach ( $endpoints as $base_url ) {
            $url = $base_url . '?client=firefox&q=' . rawurlencode( $query );

            for ( $attempt = 0; $attempt <= 2; $attempt++ ) {
                if ( $attempt > 0 && isset( $backoff_ms[ $attempt - 1 ] ) ) {
                    usleep( $backoff_ms[ $attempt - 1 ] * 1000 );
                }

                $resp = wp_remote_get( $url, [
                    'timeout' => 12,
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (compatible; TMWSEO-Engine/4.1; +' . home_url( '/' ) . ')',
                        'Accept'     => 'application/json',
                    ],
                ] );

                if ( is_wp_error( $resp ) ) {
                    continue;
                }

                $code = (int) wp_remote_retrieve_response_code( $resp );
                if ( $code === 429 || $code >= 500 ) {
                    continue; // retry
                }

                $body = (string) wp_remote_retrieve_body( $resp );
                $json = json_decode( $body, true );
                if ( is_array( $json ) && isset( $json[1] ) && is_array( $json[1] ) ) {
                    foreach ( $json[1] as $s ) {
                        $s = trim( (string) $s );
                        if ( $s !== '' ) {
                            $out[] = $s;
                        }
                    }
                }
                break 2; // success — skip remaining endpoints
            }
        }

        $out = array_values( array_unique( $out ) );
        set_transient( $cache_key, $out, HOUR_IN_SECONDS );
        return $out;
    }

    /**
     * Collects all keyword references from all CSV files.
     *
     * @return array<int, array{keyword:string,category:string,type:string}>
     */
    private static function collect_all_keyword_refs(): array {
        $refs = [];
        foreach ( CuratedKeywordLibrary::categories() as $category ) {
            foreach ( [ 'extra', 'longtail', 'competitor' ] as $type ) {
                $keywords = CuratedKeywordLibrary::load( $category, $type );
                foreach ( $keywords as $kw ) {
                    $refs[] = [
                        'keyword'  => $kw,
                        'category' => $category,
                        'type'     => $type,
                    ];
                }
            }
        }
        return $refs;
    }

    /**
     * Appends a new keyword to a category's CSV in the uploads directory.
     * Returns true if the keyword was added (was not already present).
     */
    private static function append_keyword_to_csv( string $category, string $type, string $keyword ): bool {
        $keyword = trim( $keyword );
        if ( $keyword === '' ) {
            return false;
        }

        $uploads_dir = CuratedKeywordLibrary::uploads_base_dir() . "/{$category}";
        if ( ! is_dir( $uploads_dir ) ) {
            wp_mkdir_p( $uploads_dir );
        }

        $path = "{$uploads_dir}/{$type}.csv";

        // If the file doesn't exist yet, seed it from the bundled copy
        if ( ! file_exists( $path ) ) {
            $plugin_path = CuratedKeywordLibrary::plugin_base_dir() . "/{$category}/{$type}.csv";
            if ( file_exists( $plugin_path ) ) {
                copy( $plugin_path, $path );
            } else {
                // Create with header
                file_put_contents( $path, "keyword\n" );
            }
        }

        // Check if keyword already exists
        $existing = CuratedKeywordLibrary::load( $category, $type );
        $norm     = strtolower( $keyword );
        foreach ( $existing as $ex ) {
            if ( strtolower( $ex ) === $norm ) {
                return false;
            }
        }

        // Append
        $fh = fopen( $path, 'a' );
        if ( ! $fh ) {
            return false;
        }
        if ( flock( $fh, LOCK_EX ) ) {
            fputcsv( $fh, [ $keyword ] );
            flock( $fh, LOCK_UN );
        }
        fclose( $fh );
        return true;
    }

    /**
     * Updates specific metric columns for a keyword in its CSV.
     */
    private static function update_keyword_in_csv(
        string $category,
        string $type,
        string $keyword,
        array  $metrics
    ): void {
        $uploads_dir = CuratedKeywordLibrary::uploads_base_dir() . "/{$category}";
        $path        = "{$uploads_dir}/{$type}.csv";

        // Only update uploads copy
        if ( ! file_exists( $path ) ) {
            $plugin_path = CuratedKeywordLibrary::plugin_base_dir() . "/{$category}/{$type}.csv";
            if ( ! file_exists( $plugin_path ) ) {
                return;
            }
            if ( ! is_dir( $uploads_dir ) ) {
                wp_mkdir_p( $uploads_dir );
            }
            copy( $plugin_path, $path );
        }

        $fh = fopen( $path, 'r' );
        if ( ! $fh ) {
            return;
        }

        $header   = fgetcsv( $fh );
        if ( ! $header ) {
            fclose( $fh );
            return;
        }

        // Map headers to indexes
        $indexes = [];
        foreach ( $header as $i => $col ) {
            $col = strtolower( trim( $col ) );
            $col = ltrim( $col, "\xEF\xBB\xBF" ); // strip BOM
            $indexes[ $col ] = $i;
        }

        $kw_col = $indexes['keyword'] ?? $indexes['phrase'] ?? 0;

        // Ensure metric columns exist
        foreach ( array_keys( $metrics ) as $metric_col ) {
            if ( ! isset( $indexes[ $metric_col ] ) ) {
                $header[]              = $metric_col;
                $indexes[ $metric_col ] = count( $header ) - 1;
            }
        }

        $rows = [];
        while ( ( $row = fgetcsv( $fh ) ) !== false ) {
            // Pad row to header length
            while ( count( $row ) < count( $header ) ) {
                $row[] = '';
            }

            if ( isset( $row[ $kw_col ] ) && strtolower( trim( $row[ $kw_col ] ) ) === strtolower( trim( $keyword ) ) ) {
                foreach ( $metrics as $col => $value ) {
                    if ( isset( $indexes[ $col ] ) ) {
                        $row[ $indexes[ $col ] ] = $value;
                    }
                }
            }
            $rows[] = $row;
        }
        fclose( $fh );

        // Write back
        $fh = fopen( $path, 'w' );
        if ( ! $fh ) {
            return;
        }
        fputcsv( $fh, $header );
        foreach ( $rows as $row ) {
            fputcsv( $fh, $row );
        }
        fclose( $fh );
    }

    /**
     * Wraps DataForSEO search_volume call safely.
     */
    private static function safe_search_volume( array $keywords ): array {
        $result = DataForSEO::search_volume( $keywords );
        if ( ! ( $result['ok'] ?? false ) ) {
            \TMWSEO\Engine\Logs::warn( 'keywords', '[TMW-DFS] search_volume refresh failed', [
                'error' => $result['error'] ?? 'unknown',
            ] );
            return [];
        }

        return (array) ( $result['map'] ?? [] );
    }
}
