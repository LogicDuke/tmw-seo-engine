<?php

if (!defined('ABSPATH')) { exit; }

class TMW_Related_Models {
    private $graph;

    public function __construct(?TMW_Link_Graph $graph = null) {
        $this->graph = $graph ?: new TMW_Link_Graph();
    }

    public function get_related(int $post_id, int $limit = 5): array {
        $limit = max(1, $limit);
        $tags = $this->graph->get_model_tags($post_id);

        if (empty($tags)) {
            return [];
        }

        $tag_ids = array_map(static function($term) {
            return (int) $term->term_id;
        }, $tags);

        $query = new WP_Query([
            'post_type' => get_post_type($post_id) ?: 'model',
            'post_status' => 'publish',
            'post__not_in' => [$post_id],
            'posts_per_page' => 20,
            'ignore_sticky_posts' => true,
            'tax_query' => [
                [
                    'taxonomy' => $tags[0]->taxonomy,
                    'field' => 'term_id',
                    'terms' => $tag_ids,
                    'operator' => 'IN',
                ],
            ],
        ]);

        if (!$query->have_posts()) {
            return [];
        }

        $scores = [];

        foreach ($query->posts as $candidate) {
            $candidate_tags = $this->graph->get_model_tags((int) $candidate->ID);
            if (empty($candidate_tags)) {
                continue;
            }

            $candidate_ids = array_map(static function($term) {
                return (int) $term->term_id;
            }, $candidate_tags);

            $shared = count(array_intersect($tag_ids, $candidate_ids));
            if ($shared <= 0) {
                continue;
            }

            $scores[] = [
                'post_id' => (int) $candidate->ID,
                'title' => get_the_title($candidate),
                'url' => get_permalink($candidate),
                'shared_tags' => $shared,
            ];
        }

        usort($scores, static function(array $a, array $b): int {
            if ($a['shared_tags'] === $b['shared_tags']) {
                return strcmp((string) $a['title'], (string) $b['title']);
            }

            return $b['shared_tags'] <=> $a['shared_tags'];
        });

        return array_slice($scores, 0, $limit);
    }
}
