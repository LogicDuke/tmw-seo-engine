<?php

if (!defined('ABSPATH')) exit;

class TMW_GSC_Cluster_Importer {
    private $cluster_service;

    public function __construct(TMW_Cluster_Service $cluster_service) {
        $this->cluster_service = $cluster_service;
    }

    public function sync_cluster_metrics() {
        // TODO: Fetch GSC data per cluster pages, aggregate impressions/clicks, and store in tmw_cluster_metrics.
        return [];
    }
}
