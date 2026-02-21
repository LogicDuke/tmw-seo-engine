<?php

if (!defined('ABSPATH')) exit;

class TMW_Cluster_Scoring_Engine {
    private $cluster_service;
    private $linking_engine;

    public function __construct(TMW_Cluster_Service $cluster_service, TMW_Cluster_Linking_Engine $linking_engine) {
        $this->cluster_service = $cluster_service;
        $this->linking_engine = $linking_engine;
    }

    public function score_cluster($cluster_id) {
        $cluster_id = (int) $cluster_id;

        if ($cluster_id <= 0) {
            return [];
        }

        $cluster = $this->cluster_service->get_cluster($cluster_id);

        if (empty($cluster) || !is_array($cluster)) {
            return [];
        }

        $pages = $this->cluster_service->get_cluster_pages($cluster_id);
        $keywords = $this->cluster_service->get_cluster_keywords($cluster_id);
        $analysis = $this->linking_engine->analyze_cluster($cluster_id);

        $supports_count = 0;
        if (is_array($pages)) {
            foreach ($pages as $page) {
                if (is_array($page) && isset($page['role']) && $page['role'] === 'support') {
                    $supports_count++;
                }
            }
        }

        $has_linking_error = is_array($analysis) && !empty($analysis['error']);
        $pillar_exists = is_array($analysis) && !empty($analysis['pillar']);
        $pillar_score = (!$has_linking_error && $pillar_exists) ? 20 : 0;

        if ($supports_count >= 7) {
            $support_score = 20;
        } elseif ($supports_count >= 4) {
            $support_score = 15;
        } elseif ($supports_count >= 2) {
            $support_score = 10;
        } elseif ($supports_count >= 1) {
            $support_score = 5;
        } else {
            $support_score = 0;
        }

        $expected_links = $supports_count * 2;
        if ($expected_links > 0) {
            $missing_links = (is_array($analysis) && isset($analysis['missing_links']) && is_array($analysis['missing_links']))
                ? count($analysis['missing_links'])
                : $expected_links;
            $completeness = max(0, 1 - ($missing_links / $expected_links));
            $linking_score = (int) round($completeness * 30);
        } else {
            $linking_score = 0;
        }

        $keyword_count = is_array($keywords) ? count($keywords) : 0;
        if ($keyword_count >= 16) {
            $keyword_score = 30;
        } elseif ($keyword_count >= 6) {
            $keyword_score = 20;
        } elseif ($keyword_count >= 1) {
            $keyword_score = 10;
        } else {
            $keyword_score = 0;
        }

        $total = $pillar_score + $support_score + $linking_score + $keyword_score;

        if ($total >= 90) {
            $grade = 'A';
        } elseif ($total >= 75) {
            $grade = 'B';
        } elseif ($total >= 60) {
            $grade = 'C';
        } elseif ($total >= 40) {
            $grade = 'D';
        } else {
            $grade = 'F';
        }

        return [
            'score' => $total,
            'grade' => $grade,
            'breakdown' => [
                'pillar' => $pillar_score,
                'supports' => $support_score,
                'linking' => $linking_score,
                'keywords' => $keyword_score,
            ],
        ];

    }
}
