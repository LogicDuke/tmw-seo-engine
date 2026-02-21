<?php

if (!defined('ABSPATH')) exit;

class TMW_Cluster_Advisor {
    private $cluster_service;
    private $linking_engine;
    private $scoring_engine;

    public function __construct(
        TMW_Cluster_Service $cluster_service,
        TMW_Cluster_Linking_Engine $linking_engine,
        TMW_Cluster_Scoring_Engine $scoring_engine
    ) {
        $this->cluster_service = $cluster_service;
        $this->linking_engine = $linking_engine;
        $this->scoring_engine = $scoring_engine;
    }

    public function get_cluster_warnings($cluster_id) {
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
        $score_data = $this->scoring_engine->score_cluster($cluster_id);

        $warnings = [];

        $has_linking_error = is_array($analysis) && !empty($analysis['error']);
        $has_pillar = is_array($analysis) && !empty($analysis['pillar']);

        if ($has_linking_error || !$has_pillar) {
            $warnings[] = [
                'type' => 'missing_pillar',
                'severity' => 'high',
                'message' => 'This cluster has no pillar page.',
            ];
        }

        $supports_count = 0;
        if (is_array($pages)) {
            foreach ($pages as $page) {
                if (is_array($page) && isset($page['role']) && $page['role'] === 'support') {
                    $supports_count++;
                }
            }
        }

        if ($supports_count < 2) {
            $warnings[] = [
                'type' => 'low_support_count',
                'severity' => 'medium',
                'message' => 'This cluster has fewer than 2 support pages.',
            ];
        }

        $expected_links = $supports_count * 2;
        $missing_links = (is_array($analysis) && isset($analysis['missing_links']) && is_array($analysis['missing_links']))
            ? count($analysis['missing_links'])
            : $expected_links;
        $completeness = ($expected_links > 0) ? max(0, 1 - ($missing_links / $expected_links)) : 0;

        if ($completeness < 0.7) {
            $warnings[] = [
                'type' => 'weak_linking',
                'severity' => 'medium',
                'message' => 'Internal linking completeness is below 70% for this cluster.',
            ];
        }

        $keyword_count = is_array($keywords) ? count($keywords) : 0;
        if ($keyword_count < 5) {
            $warnings[] = [
                'type' => 'low_keyword_coverage',
                'severity' => 'low',
                'message' => 'This cluster has fewer than 5 keywords.',
            ];
        }

        $score = (is_array($score_data) && isset($score_data['score'])) ? (int) $score_data['score'] : 0;
        if ($score < 60) {
            $warnings[] = [
                'type' => 'low_score',
                'severity' => 'high',
                'message' => 'This cluster score is below 60.',
            ];
        }

        return $warnings;
    }

    public function get_cluster_opportunities($cluster_id) {
        $cluster_id = (int) $cluster_id;

        if ($cluster_id <= 0) {
            return [];
        }

        $score_data = $this->scoring_engine->score_cluster($cluster_id);
        $analysis = $this->linking_engine->analyze_cluster($cluster_id);

        global $wpdb;
        $metrics_table = $wpdb->prefix . 'tmw_cluster_metrics';

        $metrics = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$metrics_table} WHERE cluster_id = %d ORDER BY recorded_at DESC, id DESC LIMIT 1",
                $cluster_id
            ),
            ARRAY_A
        );

        $structural_score = 0;
        if (is_array($score_data)) {
            if (isset($score_data['breakdown']) && is_array($score_data['breakdown'])) {
                $structural_score =
                    (int) ($score_data['breakdown']['pillar'] ?? 0) +
                    (int) ($score_data['breakdown']['supports'] ?? 0) +
                    (int) ($score_data['breakdown']['linking'] ?? 0) +
                    (int) ($score_data['breakdown']['keywords'] ?? 0);
            } elseif (isset($score_data['score'])) {
                $structural_score = (int) $score_data['score'];
            }
        }

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

        $supports_count = (is_array($analysis) && isset($analysis['supports']) && is_array($analysis['supports']))
            ? count($analysis['supports'])
            : 0;
        $expected_links = $supports_count * 2;
        $missing_links = (is_array($analysis) && isset($analysis['missing_links']) && is_array($analysis['missing_links']))
            ? count($analysis['missing_links'])
            : $expected_links;
        $linking_completeness = $expected_links > 0 ? max(0, 1 - ($missing_links / $expected_links)) : 0;

        $opportunities = [];

        if ($impressions > 1000 && $ctr < 2) {
            $opportunities[] = [
                'type' => 'ctr_opportunity',
                'priority' => 'high',
                'message' => 'High impressions but low CTR. Improve titles and meta descriptions.',
            ];
        }

        if ($position >= 11 && $position <= 20 && $structural_score > 70) {
            $opportunities[] = [
                'type' => 'page_one_push',
                'priority' => 'high',
                'message' => 'Cluster is close to page 1. Reinforce internal linking and content depth.',
            ];
        }

        if ($structural_score > 80 && $impressions < 300) {
            $opportunities[] = [
                'type' => 'low_demand_warning',
                'priority' => 'medium',
                'message' => 'Strong structure but low search demand. Expand keyword targeting or validate topic demand.',
            ];
        }

        if ($position > 0 && $position < 15 && $linking_completeness < 0.7) {
            $opportunities[] = [
                'type' => 'reinforcement_opportunity',
                'priority' => 'high',
                'message' => 'Rankings are within reach. Strengthen cluster internal links to reinforce authority flow.',
            ];
        }

        return $opportunities;
    }

    public function get_cluster_opportunity_score($cluster_id) {
        $cluster_id = (int) $cluster_id;

        if ($cluster_id <= 0) {
            return [];
        }

        // TODO: Calculate page gap.
        // TODO: Calculate CTR gap.
        // TODO: Calculate linking weakness.
        return [];
    }
}
