<?php

if (!defined('ABSPATH')) exit;

class TMW_Cluster_Linking_Engine {
    private $cluster_service;

    public function __construct(TMW_Cluster_Service $cluster_service) {
        $this->cluster_service = $cluster_service;
    }

    public function analyze_cluster($cluster_id) {
        $cluster_id = (int) $cluster_id;

        if ($cluster_id <= 0) {
            return [];
        }

        $cluster = $this->cluster_service->get_cluster($cluster_id);
        $pages = $this->cluster_service->get_cluster_pages($cluster_id);

        if (empty($cluster) || empty($pages) || !is_array($pages)) {
            return [];
        }

        $pillar_page = null;
        $support_pages = [];

        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }

            if (isset($page['role']) && $page['role'] === 'pillar' && $pillar_page === null) {
                $pillar_page = $page;
                continue;
            }

            if (isset($page['role']) && $page['role'] === 'support') {
                $support_pages[] = $page;
            }
        }

        if (empty($pillar_page)) {
            return [
                'cluster' => $cluster,
                'pillar' => null,
                'supports' => $support_pages,
                'missing_links' => [],
                'error' => 'missing_pillar',
            ];
        }

        $pillar_id = isset($pillar_page['post_id']) ? (int) $pillar_page['post_id'] : 0;
        $missing_links = [];

        foreach ($support_pages as $support_page) {
            $support_id = isset($support_page['post_id']) ? (int) $support_page['post_id'] : 0;

            if ($support_id <= 0 || $pillar_id <= 0) {
                continue;
            }

            $missing_links[] = [
                'from' => $support_id,
                'to' => $pillar_id,
                'type' => 'support_to_pillar',
            ];

            $missing_links[] = [
                'from' => $pillar_id,
                'to' => $support_id,
                'type' => 'pillar_to_support',
            ];
        }

        return [
            'cluster' => $cluster,
            'pillar' => $pillar_page,
            'supports' => $support_pages,
            'missing_links' => $missing_links,
        ];
    }
}
