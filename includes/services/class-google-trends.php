<?php
/**
 * TMW SEO Engine — Google Trends Service
 *
 * Provides Google Trends data without the official Trends API (which is not publicly
 * available). Instead, this service wraps the unofficial Trends endpoint used by the
 * browser widget, falling back to an RSS-based related query feed.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IMPORTANT — FEATURE GATE:
 * This service is only active when 'google_trends_enabled' is set to 1 in Settings.
 * Default is 0 (OFF). Enable under Settings → Google Trends once you have verified
 * the Trends RSS endpoint is accessible from your server.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * DATA SOURCES (in priority order):
 *  1. Trends "related queries" RSS feed — returns rising + top related queries for a keyword.
 *     URL: https://trends.google.com/trends/trendingsearches/daily/rss?geo={GEO}
 *  2. Trends explore endpoint (unofficial JSON) — returns interest over time and related queries.
 *     URL: https://trends.google.com/trends/api/explore
 *
 * WHAT THIS PROVIDES:
 *  - Rising / trending queries for a seed keyword → candidate phrases
 *  - Trend direction (up/flat/down) for an existing keyword
 *  - Trend score (0–100) for candidate enrichment
 *  - Seasonality estimate (rolling avg vs. peak)
 *
 * @package TMWSEO\Engine\Services
 * @since   4.4.0
 */

namespace TMWSEO\Engine\Services;

use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GoogleTrends {

    private const TRENDS_RSS_BASE  = 'https://trends.google.com/trends/trendingsearches/daily/rss';
    private const TRENDS_WIDGET    = 'https://trends.google.com/trends/api/explore';
    private const CACHE_TTL        = 6 * HOUR_IN_SECONDS;  // Trends data changes slowly

    // ── Configuration ───────────────────────────────────────────────────────

    public static function is_enabled(): bool {
        return (bool) Settings::get( 'google_trends_enabled', 0 );
    }

    public static function default_geo(): string {
        return strtoupper( trim( (string) Settings::get( 'google_trends_geo', 'US' ) ) ) ?: 'US';
    }

    public static function default_locale(): string {
        return strtolower( trim( (string) Settings::get( 'google_trends_locale', 'en-US' ) ) ) ?: 'en-US';
    }

    public static function default_timeframe(): string {
        return trim( (string) Settings::get( 'google_trends_timeframe', 'today 3-m' ) ) ?: 'today 3-m';
    }

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Get daily trending searches for the configured geo.
     * Returns an array of [ 'keyword' => string, 'trend_score' => int ] items.
     *
     * Used by: collect_seeds() → seed expansion input.
     *
     * @return array<int,array{keyword:string,trend_score:int}>
     */
    public static function get_daily_trending( int $limit = 20 ): array {
        if ( ! self::is_enabled() ) {
            return [];
        }

        $geo       = self::default_geo();
        $cache_key = 'tmwseo_gtrends_daily_' . md5( $geo );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return array_slice( $cached, 0, $limit );
        }

        $url  = add_query_arg( [ 'geo' => $geo ], self::TRENDS_RSS_BASE );
        $resp = wp_safe_remote_get( $url, [
            'timeout'    => 15,
            'user-agent' => 'Mozilla/5.0 (compatible; TMW-SEO-Engine/4.4)',
        ] );

        if ( is_wp_error( $resp ) ) {
            Logs::warn( 'google-trends', 'Daily trending fetch failed', [
                'error' => $resp->get_error_message(),
                'geo'   => $geo,
            ] );
            return [];
        }

        $body    = wp_remote_retrieve_body( $resp );
        $results = self::parse_trending_rss( $body );

        set_transient( $cache_key, $results, self::CACHE_TTL );

        Logs::info( 'google-trends', 'Daily trending fetched', [
            'geo'   => $geo,
            'count' => count( $results ),
        ] );

        return array_slice( $results, 0, $limit );
    }

    /**
     * Get related queries for a specific keyword term.
     * Returns items shaped for the keyword idea provider pipeline.
     *
     * @return array{ok:bool,items?:array<int,array<string,mixed>>,error?:string}
     */
    public static function get_related_queries( string $keyword, int $limit = 40 ): array {
        if ( ! self::is_enabled() ) {
            return [ 'ok' => false, 'error' => 'google_trends_disabled' ];
        }

        if ( trim( $keyword ) === '' ) {
            return [ 'ok' => false, 'error' => 'empty_keyword' ];
        }

        $geo       = self::default_geo();
        $timeframe = self::default_timeframe();
        $cache_key = 'tmwseo_gtrends_related_' . md5( $keyword . '|' . $geo . '|' . $timeframe );
        $cached    = get_transient( $cache_key );

        if ( is_array( $cached ) ) {
            return $cached;
        }

        $items = self::fetch_related_via_explore( $keyword, $geo, $timeframe, $limit );

        if ( $items === null ) {
            $result = [ 'ok' => false, 'error' => 'google_trends_explore_failed' ];
        } else {
            $result = [ 'ok' => true, 'items' => $items ];
        }

        set_transient( $cache_key, $result, self::CACHE_TTL );
        return $result;
    }

    /**
     * Score a single keyword's current trend (0–100) and direction.
     *
     * @return array{trend_score:int,trend_direction:string,seasonality_index:float}
     */
    public static function score_keyword( string $keyword ): array {
        $default = [ 'trend_score' => 0, 'trend_direction' => 'unknown', 'seasonality_index' => 0.0 ];

        if ( ! self::is_enabled() || trim( $keyword ) === '' ) {
            return $default;
        }

        $geo       = self::default_geo();
        $timeframe = self::default_timeframe();
        $cache_key = 'tmwseo_gtrends_score_' . md5( $keyword . '|' . $geo . '|' . $timeframe );
        $cached    = get_transient( $cache_key );

        if ( is_array( $cached ) ) {
            return $cached;
        }

        $interest = self::fetch_interest_over_time( $keyword, $geo, $timeframe );

        if ( $interest === null ) {
            set_transient( $cache_key, $default, self::CACHE_TTL );
            return $default;
        }

        $result = self::compute_trend_score( $interest );
        set_transient( $cache_key, $result, self::CACHE_TTL );
        return $result;
    }

    // ── Private Helpers ─────────────────────────────────────────────────────

    /**
     * Parse Google Trends RSS feed body into keyword items.
     *
     * @return array<int,array{keyword:string,trend_score:int}>
     */
    private static function parse_trending_rss( string $body ): array {
        if ( trim( $body ) === '' ) { return []; }

        $items = [];

        // Suppress libxml errors for malformed RSS.
        $prev  = libxml_use_internal_errors( true );
        $xml   = simplexml_load_string( $body );
        libxml_use_internal_errors( $prev );

        if ( $xml === false ) { return []; }

        foreach ( $xml->channel->item ?? [] as $item ) {
            $title = trim( (string) ( $item->title ?? '' ) );
            if ( $title === '' ) { continue; }

            // ht:news_item_title may contain a sub-title; we want the trend keyword.
            $ht_ns     = $item->children( 'ht', true );
            $approx_ns = isset( $ht_ns->approx_traffic )
                ? (int) str_replace( [ '+', ',', 'K' ], [ '', '', '000' ], (string) $ht_ns->approx_traffic )
                : 0;

            // Normalize score to 0–100 based on 10M+ typical max
            $score = min( 100, (int) round( ( $approx_ns / 10_000_000 ) * 100 ) );

            $items[] = [
                'keyword'     => $title,
                'trend_score' => max( 1, $score ),
            ];
        }

        return $items;
    }

    /**
     * Call the unofficial Trends explore endpoint and extract related queries.
     * Returns null on failure (caller converts to error).
     *
     * @return array<int,array<string,mixed>>|null
     */
    private static function fetch_related_via_explore( string $keyword, string $geo, string $timeframe, int $limit ): ?array {
        // The explore endpoint requires a widget token obtained from a first call.
        // We use the simpler "interest by region" approach which is publicly parseable.
        // This fetch is best-effort — production integrations should use the official
        // Ads Keyword Planner for volume/cpc, and Trends only for direction.

        $widget_req_body = wp_json_encode( [
            'comparisonItem' => [
                [ 'keyword' => $keyword, 'geo' => $geo, 'time' => $timeframe ],
            ],
            'category'  => 0,
            'property'  => '',
        ] );

        if ( $widget_req_body === false ) { return null; }

        $explore_url = add_query_arg( [
            'hl'  => str_replace( '-', '_', self::default_locale() ),
            'tz'  => '-60',
            'req' => $widget_req_body,
        ], self::TRENDS_WIDGET );

        $resp = wp_safe_remote_get( $explore_url, [
            'timeout'    => 20,
            'user-agent' => 'Mozilla/5.0 (compatible; TMW-SEO-Engine/4.4)',
        ] );

        if ( is_wp_error( $resp ) ) {
            Logs::warn( 'google-trends', 'Explore fetch failed', [
                'keyword' => $keyword,
                'error'   => $resp->get_error_message(),
            ] );
            return null;
        }

        $body = wp_remote_retrieve_body( $resp );

        // Strip Trends anti-XSSI prefix: ")]}',\n"
        $body = preg_replace( '/^\)\]\}\'[^\n]*\n/', '', (string) $body );
        $data = json_decode( (string) $body, true );

        if ( ! is_array( $data ) ) { return null; }

        // Extract widgets array — we look for related queries widget.
        $widgets = (array) ( $data['widgets'] ?? [] );
        $items   = [];

        foreach ( $widgets as $widget ) {
            if ( ! is_array( $widget ) ) { continue; }
            $widget_id = (string) ( $widget['id'] ?? '' );
            // 'RELATED_QUERIES' is the widget type we want.
            if ( strpos( $widget_id, 'RELATED_QUERIES' ) === false ) { continue; }

            $request = (array) ( $widget['request'] ?? [] );
            $request_json = wp_json_encode( $request );
            if ( $request_json === false ) { continue; }

            // Fetch the actual data for this widget.
            $data_url = 'https://trends.google.com/trends/api/widgetdata/relatedsearches';
            $data_url = add_query_arg( [
                'hl'  => str_replace( '-', '_', self::default_locale() ),
                'tz'  => '-60',
                'req' => $request_json,
                'token' => (string) ( $widget['token'] ?? '' ),
            ], $data_url );

            $data_resp = wp_safe_remote_get( $data_url, [
                'timeout'    => 15,
                'user-agent' => 'Mozilla/5.0 (compatible; TMW-SEO-Engine/4.4)',
            ] );

            if ( is_wp_error( $data_resp ) ) { continue; }

            $data_body = preg_replace( '/^\)\]\}\'[^\n]*\n/', '', (string) wp_remote_retrieve_body( $data_resp ) );
            $widget_data = json_decode( (string) $data_body, true );
            if ( ! is_array( $widget_data ) ) { continue; }

            $ranked   = (array) ( $widget_data['default']['rankedList'][0]['rankedKeyword'] ?? [] );
            $rising   = (array) ( $widget_data['default']['rankedList'][1]['rankedKeyword'] ?? [] );

            foreach ( [ [ 'type' => 'trend_related', 'rows' => $ranked ], [ 'type' => 'trend_rising', 'rows' => $rising ] ] as $bucket ) {
                foreach ( $bucket['rows'] as $row ) {
                    if ( ! is_array( $row ) ) { continue; }
                    $kw = trim( (string) ( $row['query'] ?? $row['topic']['title'] ?? '' ) );
                    if ( $kw === '' ) { continue; }
                    $value = (int) ( $row['value'] ?? 0 );
                    $items[] = [
                        'keyword'                 => $kw,
                        '_tmw_relationship_type'  => $bucket['type'],
                        '_tmw_volume_source'      => 'google_trends',
                        '_tmw_cpc_source'         => null,
                        'keyword_info'            => [
                            'search_volume'        => 0,    // Trends doesn't give absolute volume
                            'cpc'                  => null,
                            'competition'          => null,
                            'keyword_difficulty'   => null,
                        ],
                        '_tmw_trend_score'        => min( 100, $value ),
                        '_tmw_trend_direction'    => $bucket['type'] === 'trend_rising' ? 'up' : 'flat',
                        '_tmw_seasonality_index'  => 0.0,
                    ];

                    if ( count( $items ) >= $limit ) { break 3; }
                }
            }
        }

        return $items;
    }

    /**
     * Fetch interest-over-time values as array of [ week => value (0–100) ].
     *
     * @return int[]|null
     */
    private static function fetch_interest_over_time( string $keyword, string $geo, string $timeframe ): ?array {
        // Uses the same explore + multiline endpoint pattern.
        // For brevity: we return a simplified set — most real-world usage calls
        // get_related_queries() and score_keyword() separately.

        $widget_req_body = wp_json_encode( [
            'comparisonItem' => [
                [ 'keyword' => $keyword, 'geo' => $geo, 'time' => $timeframe ],
            ],
            'category'  => 0,
            'property'  => '',
        ] );

        if ( $widget_req_body === false ) { return null; }

        $explore_url = add_query_arg( [
            'hl'  => str_replace( '-', '_', self::default_locale() ),
            'tz'  => '-60',
            'req' => $widget_req_body,
        ], self::TRENDS_WIDGET );

        $resp = wp_safe_remote_get( $explore_url, [
            'timeout'    => 20,
            'user-agent' => 'Mozilla/5.0 (compatible; TMW-SEO-Engine/4.4)',
        ] );

        if ( is_wp_error( $resp ) ) { return null; }

        $body = preg_replace( '/^\)\]\}\'[^\n]*\n/', '', (string) wp_remote_retrieve_body( $resp ) );
        $data = json_decode( (string) $body, true );
        if ( ! is_array( $data ) ) { return null; }

        foreach ( (array) ( $data['widgets'] ?? [] ) as $widget ) {
            if ( ! is_array( $widget ) ) { continue; }
            if ( (string) ( $widget['id'] ?? '' ) !== 'TIMESERIES' ) { continue; }

            $request = (array) ( $widget['request'] ?? [] );
            $request_json = wp_json_encode( $request );
            if ( $request_json === false ) { continue; }

            $ts_url = add_query_arg( [
                'hl'    => str_replace( '-', '_', self::default_locale() ),
                'tz'    => '-60',
                'req'   => $request_json,
                'token' => (string) ( $widget['token'] ?? '' ),
            ], 'https://trends.google.com/trends/api/widgetdata/multiline' );

            $ts_resp = wp_safe_remote_get( $ts_url, [
                'timeout'    => 15,
                'user-agent' => 'Mozilla/5.0 (compatible; TMW-SEO-Engine/4.4)',
            ] );

            if ( is_wp_error( $ts_resp ) ) { return null; }

            $ts_body = preg_replace( '/^\)\]\}\'[^\n]*\n/', '', (string) wp_remote_retrieve_body( $ts_resp ) );
            $ts_data = json_decode( (string) $ts_body, true );
            if ( ! is_array( $ts_data ) ) { return null; }

            $timeline = (array) ( $ts_data['default']['timelineData'] ?? [] );
            $values   = [];
            foreach ( $timeline as $point ) {
                if ( ! is_array( $point ) ) { continue; }
                $val = (int) ( $point['value'][0] ?? 0 );
                $values[] = $val;
            }

            return $values ?: null;
        }

        return null;
    }

    /**
     * Compute trend score (0–100), direction, and seasonality from interest-over-time data.
     *
     * @param int[] $interest  Array of weekly interest values (0–100 scale from Trends).
     * @return array{trend_score:int,trend_direction:string,seasonality_index:float}
     */
    private static function compute_trend_score( array $interest ): array {
        $n = count( $interest );
        if ( $n === 0 ) {
            return [ 'trend_score' => 0, 'trend_direction' => 'unknown', 'seasonality_index' => 0.0 ];
        }

        $current = (int) end( $interest );
        $avg     = $n > 0 ? array_sum( $interest ) / $n : 0;

        // Direction: compare last 4 weeks vs. previous 4 weeks.
        if ( $n >= 8 ) {
            $recent = array_sum( array_slice( $interest, -4 ) ) / 4;
            $prior  = array_sum( array_slice( $interest, -8, 4 ) ) / 4;
            if ( $prior > 0 ) {
                $delta = ( $recent - $prior ) / $prior;
                if ( $delta > 0.10 )       { $direction = 'up'; }
                elseif ( $delta < -0.10 )  { $direction = 'down'; }
                else                       { $direction = 'flat'; }
            } else {
                $direction = 'flat';
            }
        } else {
            $direction = 'flat';
        }

        // Seasonality: ratio of current to rolling avg (how "peak-ish" this is right now).
        $seasonality = $avg > 0 ? round( $current / $avg, 2 ) : 1.0;

        return [
            'trend_score'       => $current,
            'trend_direction'   => $direction,
            'seasonality_index' => (float) $seasonality,
        ];
    }
}
