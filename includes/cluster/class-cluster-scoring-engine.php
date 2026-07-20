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
        $cache_key = 'tmw_cluster_score_' . $cluster_id;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

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

        $structural_score = $pillar_score + $support_score + $linking_score + $keyword_score;

        global $wpdb;
        $metrics_table = $wpdb->prefix . 'tmw_cluster_metrics';

        $metrics = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$metrics_table} WHERE cluster_id = %d",
                $cluster_id
            ),
            ARRAY_A
        );

        if (!empty($metrics) && is_array($metrics)) {
            $impressions = isset($metrics['impressions']) ? (int) $metrics['impressions'] : 0;

            if (isset($metrics['ctr'])) {
                $ctr = (float) $metrics['ctr'];
            } else {
                $clicks = isset($metrics['clicks']) ? (float) $metrics['clicks'] : 0;
                $ctr = $impressions > 0 ? ($clicks / $impressions) * 100 : 0;
            }

            if (isset($metrics['position'])) {
                $position = (float) $metrics['position'];
            } else {
                $position = isset($metrics['avg_position']) ? (float) $metrics['avg_position'] : 0;
            }

            if ($impressions >= 10000) {
                $impressions_score = 40;
            } elseif ($impressions >= 5000) {
                $impressions_score = 30;
            } elseif ($impressions >= 1000) {
                $impressions_score = 20;
            } elseif ($impressions >= 100) {
                $impressions_score = 10;
            } else {
                $impressions_score = 0;
            }

            if ($ctr >= 10) {
                $ctr_score = 30;
            } elseif ($ctr >= 5) {
                $ctr_score = 20;
            } elseif ($ctr >= 2) {
                $ctr_score = 10;
            } else {
                $ctr_score = 0;
            }

            if ($position > 0 && $position <= 3) {
                $position_score = 30;
            } elseif ($position <= 10) {
                $position_score = 20;
            } elseif ($position <= 20) {
                $position_score = 10;
            } else {
                $position_score = 0;
            }

            $performance_score = $impressions_score + $ctr_score + $position_score;
            $final_score = (int) round(($structural_score * 0.7) + ($performance_score * 0.3));
        } else {
            $final_score = $structural_score;
        }

        if ($final_score >= 90) {
            $grade = 'A';
        } elseif ($final_score >= 75) {
            $grade = 'B';
        } elseif ($final_score >= 60) {
            $grade = 'C';
        } elseif ($final_score >= 40) {
            $grade = 'D';
        } else {
            $grade = 'F';
        }

        $result = [
            'score' => $final_score,
            'grade' => $grade,
            'breakdown' => [
                'pillar' => $pillar_score,
                'supports' => $support_score,
                'linking' => $linking_score,
                'keywords' => $keyword_score,
            ],
        ];

        set_transient($cache_key, $result, HOUR_IN_SECONDS);

        return $result;

    }
}
