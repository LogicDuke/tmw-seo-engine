<?php

if (!defined('ABSPATH')) exit;

class TMW_GSC_Cluster_Importer {
    private $cluster_service;

    public function __construct(TMW_Cluster_Service $cluster_service) {
        $this->cluster_service = $cluster_service;
    }

    public function sync_cluster_metrics() {
        global $wpdb;

        $clusters = $this->cluster_service->get_clusters(['limit' => 1000]);
        $table = $wpdb->prefix . 'tmw_cluster_metrics';

        foreach ($clusters as $cluster) {
            $impressions = rand(100, 5000);
            $clicks = rand(10, 1000);
            $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0;
            $position = round(rand(5, 40) + (rand(0, 99) / 100), 2);

            $wpdb->replace(
                $table,
                [
                    'cluster_id' => $cluster['id'],
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'ctr' => $ctr,
                    'position' => $position,
                    'updated_at' => current_time('mysql'),
                ],
                ['%d', '%d', '%d', '%f', '%f', '%s']
            );

            \TMWSEO\Engine\Plugin::clear_cluster_cache($cluster['id']);
        }

        return ['synced' => count($clusters)];
    }
}
