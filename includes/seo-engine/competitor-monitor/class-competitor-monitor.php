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

        $threats         = [];
        $authority_data  = [];
        $domain_keywords = [];

        foreach ( $competitors as $domain ) {
            // 1. Get domain authority via backlinks_summary
            $authority = self::fetch_domain_authority( $domain );
            if ( $authority !== null ) {
                $authority_data[ $domain ] = $authority;
            }

            // 2. Get keywords the competitor ranks for
            $res = DataForSEO::domain_organic_keywords( $domain, 300 );
            if ( ! ( $res['ok'] ?? false ) ) {
                Logs::warn( 'competitor_monitor', "Failed to fetch keywords for {$domain}", [ 'error' => $res['error'] ?? '' ] );
                continue;
            }

            $domain_keywords[ $domain ] = [];
            foreach ( (array) ( $res['items'] ?? [] ) as $item ) {
                $kw  = strtolower( trim( (string) ( $item['keyword'] ?? '' ) ) );
                $pos = (int) ( $item['rank_absolute'] ?? $item['position'] ?? 100 );
                $vol = (int) ( $item['keyword_info']['search_volume'] ?? $item['search_volume'] ?? 0 );
                $kd  = (float) ( $item['keyword_properties']['keyword_difficulty'] ?? $item['keyword_difficulty'] ?? 0 );

                if ( $kw === '' ) continue;
                $domain_keywords[ $domain ][] = [ 'keyword' => $kw, 'position' => $pos, 'volume' => $vol, 'kd' => $kd ];

                // Threat if: they rank in top 20 for a keyword we also track, with decent volume
                if ( $vol >= 100 && $pos <= 20 && isset( $known_keywords[ $kw ] ) ) {
                    $threats[] = [
                        'keyword'    => $kw,
                        'competitor' => $domain,
                        'their_pos'  => $pos,
                        'our_pos'    => $known_keywords[ $kw ]['position'] ?? 0,
                        'volume'     => $vol,
                        'kd'         => $kd,
                        'found_at'   => current_time( 'mysql' ),
                    ];
                }
            }
        }

        // 3. Keyword intersection (keywords they ALL rank for but we don't)
        $intersection = self::find_domain_intersection( $competitors );

        $result = [
            'ok'             => true,
            'run_at'         => current_time( 'mysql' ),
            'competitors'    => $competitors,
            'threats'        => $threats,
            'threat_count'   => count( $threats ),
            'authority'      => $authority_data,
            'intersection'   => $intersection,
        ];

        update_option( self::OPTION_RESULTS, $result, false );
        update_option( self::OPTION_AUTHORITY, $authority_data, false );
        update_option( self::OPTION_LAST_RUN, current_time( 'mysql' ), false );

        // Store threats in opportunities table
        self::persist_threats( $threats );

        Logs::info( 'competitor_monitor', '[CM] Weekly scan complete', [
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
        $payload = [ [ 'target' => $domain, 'include_subdomains' => true ] ];

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
        $table = $wpdb->prefix . 'tmwseo_keyword_candidates';
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

    private static function persist_threats( array $threats ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_opportunities';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) return;

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
                'status'            => 'new',
                'created_at'        => $threat['found_at'],
            ], [ '%s', '%f', '%d', '%f', '%s', '%s', '%s' ] );
        }
    }
}
