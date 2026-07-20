<?php
/**
 * TMW_GSC_Cluster_Importer — syncs real Google Search Console data into cluster metrics.
 *
 * Replaces the previous fake rand() implementation with real GSC API calls.
 * Falls back gracefully when GSC is not configured or connected.
 *
 * @since 4.2.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TMWSEO\Engine\Integrations\GSCApi;
use TMWSEO\Engine\Logs;

class TMW_GSC_Cluster_Importer {

    private $cluster_service;

    public function __construct( TMW_Cluster_Service $cluster_service ) {
        $this->cluster_service = $cluster_service;
    }

    public function sync_cluster_metrics(): array {
        global $wpdb;

        $clusters = $this->cluster_service->get_clusters( [ 'limit' => 1000 ] );
        $table    = $wpdb->prefix . 'tmw_cluster_metrics';
        $synced   = 0;
        $skipped  = 0;

        if ( GSCApi::is_connected() ) {
            $site_url = trim( (string) \TMWSEO\Engine\Services\Settings::get( 'gsc_site_url', '' ) );
            if ( $site_url !== '' ) {
                $end_date   = date( 'Y-m-d' );
                $start_date = date( 'Y-m-d', strtotime( '-90 days' ) );
                $res = GSCApi::search_analytics( $site_url, $start_date, $end_date, [ 'page' ], 5000 );

                if ( $res['ok'] ) {
                    $page_map = [];
                    foreach ( $res['rows'] as $row ) {
                        $page_url  = strtolower( rtrim( (string) ( $row['keys'][0] ?? '' ), '/' ) );
                        $page_path = rtrim( (string) ( parse_url( $page_url, PHP_URL_PATH ) ?? '' ), '/' );
                        if ( $page_path !== '' ) {
                            $page_map[ $page_path ] = [
                                'clicks'      => (int) ( $row['clicks'] ?? 0 ),
                                'impressions' => (int) ( $row['impressions'] ?? 0 ),
                                'ctr'         => round( (float) ( $row['ctr'] ?? 0 ) * 100, 2 ),
                                'position'    => round( (float) ( $row['position'] ?? 0 ), 2 ),
                            ];
                        }
                    }

                    foreach ( $clusters as $cluster ) {
                        $cluster_id = (int) ( $cluster['id'] ?? 0 );
                        $post_id    = (int) ( $cluster['post_id'] ?? $cluster['root_post_id'] ?? 0 );
                        if ( $post_id <= 0 ) { $skipped++; continue; }
                        $permalink = get_permalink( $post_id );
                        if ( ! $permalink ) { $skipped++; continue; }
                        $path    = rtrim( (string) ( parse_url( strtolower( $permalink ), PHP_URL_PATH ) ?? '' ), '/' );
                        $metrics = $page_map[ $path ] ?? [ 'clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0 ];
                        if ( ! isset( $page_map[ $path ] ) ) $skipped++;

                        $wpdb->replace( $table, [
                            'cluster_id'  => $cluster_id,
                            'impressions' => $metrics['impressions'],
                            'clicks'      => $metrics['clicks'],
                            'ctr'         => $metrics['ctr'],
                            'position'    => $metrics['position'],
                            'updated_at'  => current_time( 'mysql' ),
                        ], [ '%d', '%d', '%d', '%f', '%f', '%s' ] );

                        \TMWSEO\Engine\Plugin::clear_cluster_cache( $cluster_id );
                        $synced++;
                    }

                    Logs::info( 'gsc', '[GSC-CLUSTER] Real data synced', [ 'synced' => $synced, 'skipped' => $skipped ] );
                    return [ 'synced' => $synced, 'skipped' => $skipped, 'source' => 'gsc_real' ];
                }
                Logs::warn( 'gsc', '[GSC-CLUSTER] GSC fetch failed, writing zeros', [ 'error' => $res['error'] ?? 'unknown' ] );
            }
        }

        // Fallback: write zeros (never fake random data)
        foreach ( $clusters as $cluster ) {
            $cluster_id = (int) ( $cluster['id'] ?? 0 );
            if ( $cluster_id <= 0 ) continue;
            $wpdb->replace( $table, [
                'cluster_id' => $cluster_id, 'impressions' => 0, 'clicks' => 0,
                'ctr' => 0.0, 'position' => 0.0, 'updated_at' => current_time( 'mysql' ),
            ], [ '%d', '%d', '%d', '%f', '%f', '%s' ] );
            \TMWSEO\Engine\Plugin::clear_cluster_cache( $cluster_id );
            $skipped++;
        }

        $source = GSCApi::is_configured() ? 'gsc_no_data' : 'gsc_not_configured';
        Logs::warn( 'gsc', '[GSC-CLUSTER] Used zero fallback', [ 'source' => $source ] );
        return [ 'synced' => 0, 'skipped' => $skipped, 'source' => $source ];
    }
}
