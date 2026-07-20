<?php
/**
 * CompetitorMonitor — weekly background scan of competitor domains.
 *
 * Checks if competitors have published new content targeting keywords
 * you already rank for. Flags these as "new threats" in the opportunities table.
 *
 * Also fetches domain authority via DataForSEO backlinks_summary endpoint.
 *
 * @package TMWSEO\Engine\CompetitorMonitor
 */
namespace TMWSEO\Engine\CompetitorMonitor;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Logs;

class CompetitorMonitor {

    const HOOK_WEEKLY      = 'tmwseo_competitor_monitor_weekly';
    const OPTION_RESULTS   = 'tmwseo_competitor_monitor_results';
    const OPTION_AUTHORITY = 'tmwseo_competitor_authority_cache';
    const OPTION_LAST_RUN  = 'tmwseo_competitor_monitor_last_run';

    private const POSITION_CTR_MAP = [
        1 => 0.30,
        2 => 0.16,
        3 => 0.10,
        4 => 0.07,
        5 => 0.05,
    ];

    // ── Boot ───────────────────────────────────────────────────────────────

    public static function init(): void {
        add_action( self::HOOK_WEEKLY, [ __CLASS__, 'run' ] );
        add_action( 'wp_ajax_tmwseo_competitor_scan', [ __CLASS__, 'handle_ajax_scan' ] );
    }

    public static function schedule(): void {
        if ( ! wp_next_scheduled( self::HOOK_WEEKLY ) ) {
            wp_schedule_event( time() + 3600, 'tmwseo_weekly', self::HOOK_WEEKLY );
        }
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook( self::HOOK_WEEKLY );
    }

    // ── Main run ───────────────────────────────────────────────────────────

    public static function run(): array {
        if ( ! DataForSEO::is_configured() ) {
            return [ 'ok' => false, 'error' => 'dataforseo_not_configured' ];
        }

        $competitors = Settings::competitor_domains();
        if ( empty( $competitors ) ) {
            return [ 'ok' => false, 'error' => 'no_competitors_configured' ];
        }

        // Collect our known keywords from DB
        $known_keywords = self::get_our_known_keywords();

        $threats              = [];
        $authority_data       = [];
        $domain_keywords      = [];
        $top_traffic_keywords = [];

        foreach ( $competitors as $domain ) {
            // 1. Get domain authority via backlinks_summary
            $authority = self::fetch_domain_authority( $domain );
            if ( $authority !== null ) {
                $authority_data[ $domain ] = $authority;
            }

            // 2. Get keywords the competitor ranks for
            $res = DataForSEO::ranked_keywords( $domain, 300 );
            if ( ! ( $res['ok'] ?? false ) ) {
                Logs::warn( 'competitor_monitor', "Failed to fetch keywords for {$domain}", [ 'error' => $res['error'] ?? '' ] );
                continue;
            }

            $domain_keywords[ $domain ] = [];
            foreach ( (array) ( $res['items'] ?? [] ) as $item ) {
                $kw  = self::extract_ranked_keyword( $item );
                $pos = self::extract_ranked_position( $item );
                $vol = self::extract_ranked_search_volume( $item );
                $kd  = (float) ( $item['keyword_properties']['keyword_difficulty'] ?? $item['keyword_difficulty'] ?? 0 );
                $estimated_clicks = (float) ( $item['keyword_info']['last_month_clicks'] ?? $item['estimated_clicks'] ?? 0 );
                $estimated_traffic = self::estimate_traffic( $vol, $pos );

                if ( $kw === '' ) continue;
                $domain_keywords[ $domain ][] = [
                    'keyword'           => $kw,
                    'position'          => $pos,
                    'search_volume'     => $vol,
                    'estimated_clicks'  => $estimated_clicks,
                    'estimated_traffic' => $estimated_traffic,
                    'kd'                => $kd,
                ];

                $top_traffic_keywords[] = [
                    'keyword'           => $kw,
                    'competitor'        => $domain,
                    'competitor_position' => $pos,
                    'search_volume'     => $vol,
                    'estimated_clicks'  => $estimated_clicks,
                    'estimated_traffic' => $estimated_traffic,
                ];

                // Threat if: they rank in top 20 for a keyword we also track, with decent volume
                if ( $vol >= 100 && $pos <= 20 && isset( $known_keywords[ $kw ] ) ) {
                    $opportunity_score = $pos > 5 ? 20 : 10;
                    $threats[] = [
                        'keyword'             => $kw,
                        'competitor'          => $domain,
                        'their_pos'           => $pos,
                        'our_pos'             => $known_keywords[ $kw ]['position'] ?? 0,
                        'volume'              => $vol,
                        'kd'                  => $kd,
                        'competitor_position' => $pos,
                        'estimated_clicks'    => $estimated_clicks,
                        'estimated_traffic'   => $estimated_traffic,
                        'opportunity_score'   => $opportunity_score,
                        'found_at'            => current_time( 'mysql' ),
                    ];
                }
            }
        }

        // 3. Keyword intersection (keywords they ALL rank for but we don't)
        $intersection = self::find_domain_intersection( $competitors );

        usort( $top_traffic_keywords, static function( array $a, array $b ): int {
            return ( $b['estimated_traffic'] <=> $a['estimated_traffic'] );
        } );

        $result = [
            'ok'             => true,
            'run_at'         => current_time( 'mysql' ),
            'competitors'    => $competitors,
            'threats'        => $threats,
            'threat_count'   => count( $threats ),
            'authority'      => $authority_data,
            'intersection'   => $intersection,
            'top_traffic_keywords' => array_slice( $top_traffic_keywords, 0, 50 ),
        ];

        update_option( self::OPTION_RESULTS, $result, false );
        update_option( self::OPTION_AUTHORITY, $authority_data, false );
        update_option( self::OPTION_LAST_RUN, current_time( 'mysql' ), false );

        // Store threats in opportunities table
        self::persist_threats( $threats );

        Logs::info( 'competitor_monitor', '[TMW-CM] Weekly scan complete', [
            'competitors' => count( $competitors ),
            'threats'     => count( $threats ),
            'intersection'=> count( $intersection ),
        ] );

        return $result;
    }

    // ── AJAX ───────────────────────────────────────────────────────────────

    public static function handle_ajax_scan(): void {
        check_ajax_referer( 'tmwseo_competitor_scan', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
        wp_send_json_success( self::run() );
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private static function fetch_domain_authority( string $domain ): ?array {
        // DataForSEO backlinks_summary endpoint
        $res = DataForSEO::backlinks_summary( $domain );
        if ( ! ( $res['ok'] ?? false ) ) return null;

        return [
            'domain'     => $domain,
            'domain_rank'=> $res['domain_rank'] ?? 0,
            'backlinks'  => $res['backlinks'] ?? 0,
            'referring_domains' => $res['referring_domains'] ?? 0,
            'fetched_at' => current_time( 'mysql' ),
        ];
    }

    private static function find_domain_intersection( array $domains ): array {
        if ( count( $domains ) < 2 ) return [];

        // Use DataForSEO domain_intersection for the top 2 competitors
        $d1  = $domains[0];
        $d2  = $domains[1];
        $res = DataForSEO::domain_intersection( $d1, $d2 );
        if ( ! ( $res['ok'] ?? false ) ) return [];

        $intersection = [];
        foreach ( (array) ( $res['items'] ?? [] ) as $item ) {
            $kw  = strtolower( trim( (string) ( $item['keyword'] ?? '' ) ) );
            $vol = (int) ( $item['keyword_info']['search_volume'] ?? 0 );
            if ( $kw !== '' && $vol >= 100 ) {
                $intersection[] = [
                    'keyword' => $kw,
                    'volume'  => $vol,
                    'kd'      => (float) ( $item['keyword_properties']['keyword_difficulty'] ?? 0 ),
                ];
            }
        }

        return array_slice( $intersection, 0, 50 );
    }

    private static function get_our_known_keywords(): array {
        global $wpdb;
        $map = [];
        // From keyword_candidates table
        $table = $wpdb->prefix . 'tmw_keyword_candidates'; // FIX: was 'tmwseo_keyword_candidates' — wrong prefix caused threat detection to always return empty
        $rows  = $wpdb->get_results( "SELECT keyword FROM {$table} LIMIT 5000", ARRAY_A );
        foreach ( (array) $rows as $row ) {
            $kw = strtolower( trim( (string) ( $row['keyword'] ?? '' ) ) );
            if ( $kw !== '' ) $map[ $kw ] = [ 'position' => 0 ];
        }
        // Also from RankMath focus keywords on published posts
        $meta_rows = $wpdb->get_results(
            "SELECT meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = 'rank_math_focus_keyword' AND p.post_status = 'publish'
             LIMIT 2000",
            ARRAY_A
        );
        foreach ( (array) $meta_rows as $row ) {
            $kw = strtolower( trim( (string) ( $row['meta_value'] ?? '' ) ) );
            if ( $kw !== '' ) $map[ $kw ] = [ 'position' => 0 ];
        }
        return $map;
    }

    private static function estimate_traffic( int $search_volume, int $position ): float {
        if ( $search_volume <= 0 ) {
            return 0.0;
        }

        if ( isset( self::POSITION_CTR_MAP[ $position ] ) ) {
            $ctr = self::POSITION_CTR_MAP[ $position ];
        } elseif ( $position >= 6 && $position <= 10 ) {
            $ctr = 0.02;
        } else {
            $ctr = 0.0;
        }

        return round( $search_volume * $ctr, 2 );
    }

    private static function extract_ranked_keyword( array $item ): string {
        $keyword = (string) ( $item['keyword_data']['keyword'] ?? $item['keyword'] ?? '' );
        return strtolower( trim( $keyword ) );
    }

    private static function extract_ranked_position( array $item ): int {
        return (int) (
            $item['ranked_serp_element']['serp_item']['rank_absolute']
            ?? $item['ranked_serp_element']['serp_item']['rank_group']
            ?? $item['rank_absolute']
            ?? $item['position']
            ?? 100
        );
    }

    private static function extract_ranked_search_volume( array $item ): int {
        return (int) (
            $item['keyword_data']['keyword_info']['search_volume']
            ?? $item['keyword_info']['search_volume']
            ?? $item['search_volume']
            ?? 0
        );
    }

    private static function ensure_opportunity_columns( string $table ): void {
        global $wpdb;

        $required_columns = [
            'competitor_position' => "ALTER TABLE {$table} ADD COLUMN competitor_position INT(11) NULL AFTER competitor_url",
            'estimated_traffic'   => "ALTER TABLE {$table} ADD COLUMN estimated_traffic DECIMAL(12,2) NULL AFTER competitor_position",
        ];

        $existing_columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
        foreach ( $required_columns as $column_name => $alter_sql ) {
            if ( in_array( $column_name, $existing_columns, true ) ) {
                continue;
            }

            $wpdb->query( $alter_sql );
        }
    }

    private static function persist_threats( array $threats ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_opportunities';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        self::ensure_opportunity_columns( $table );

        foreach ( array_slice( $threats, 0, 100 ) as $threat ) {
            $existing = $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$table} WHERE keyword = %s AND competitor_url = %s LIMIT 1", $threat['keyword'], $threat['competitor'] )
            );
            if ( $existing ) continue;

            $wpdb->insert( $table, [
                'keyword'           => sanitize_text_field( $threat['keyword'] ),
                'opportunity_score' => 0,
                'search_volume'     => (int) $threat['volume'],
                'difficulty'        => (float) $threat['kd'],
                'competitor_url'    => sanitize_text_field( $threat['competitor'] ),
                'competitor_position' => (int) ( $threat['competitor_position'] ?? 0 ),
                'estimated_traffic' => (float) ( $threat['estimated_traffic'] ?? 0 ),
                'status'            => 'new',
                'created_at'        => $threat['found_at'],
            ], [ '%s', '%f', '%d', '%f', '%s', '%d', '%f', '%s', '%s' ] );
        }
    }
}
