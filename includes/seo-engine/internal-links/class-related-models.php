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

        $source_context = $this->extract_keyword_context($post_id);

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

            $candidate_context = $this->extract_keyword_context((int) $candidate->ID);
            $shared_cluster = (!empty($source_context['clusters']) && !empty($candidate_context['clusters']))
                ? count(array_intersect($source_context['clusters'], $candidate_context['clusters']))
                : 0;
            $shared_entity = (!empty($source_context['entities']) && !empty($candidate_context['entities']))
                ? count(array_intersect($source_context['entities'], $candidate_context['entities']))
                : 0;

            $scores[] = [
                'post_id' => (int) $candidate->ID,
                'title' => get_the_title($candidate),
                'url' => get_permalink($candidate),
                'shared_tags' => $shared,
                'shared_cluster' => $shared_cluster,
                'shared_entity' => $shared_entity,
            ];
        }

        usort($scores, static function(array $a, array $b): int {
            $a_score = ((int) ($a['shared_entity'] ?? 0) * 4) + ((int) ($a['shared_cluster'] ?? 0) * 2) + (int) ($a['shared_tags'] ?? 0);
            $b_score = ((int) ($b['shared_entity'] ?? 0) * 4) + ((int) ($b['shared_cluster'] ?? 0) * 2) + (int) ($b['shared_tags'] ?? 0);

            if ($a_score === $b_score) {
                return strcmp((string) $a['title'], (string) $b['title']);
            }

            return $b_score <=> $a_score;
        });

        return array_slice($scores, 0, $limit);
    }

    /**
     * @return array{clusters:array<int,string>,entities:array<int,string>}
     */
    private function extract_keyword_context(int $post_id): array {
        $clusters = [];
        $entities = [];

        $cluster_rows = get_post_meta($post_id, 'tmw_keyword_clusters', true);
        if (is_array($cluster_rows)) {
            foreach ($cluster_rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $cluster = strtolower(trim((string) ($row['cluster'] ?? $row['primary'] ?? '')));
                if ($cluster !== '') {
                    $clusters[] = $cluster;
                }
            }
        }

        $pack = get_post_meta($post_id, 'tmw_keyword_pack', true);
        if (is_array($pack) && !empty($pack['keywords']) && is_array($pack['keywords'])) {
            foreach ($pack['keywords'] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $entity_type = strtolower(trim((string) ($row['entity_type'] ?? 'generic')));
                $entity_id = (int) ($row['entity_id'] ?? 0);
                if ($entity_type !== 'generic' && $entity_id > 0) {
                    $entities[] = $entity_type . ':' . $entity_id;
                }
            }
        }

        return [
            'clusters' => array_values(array_unique($clusters)),
            'entities' => array_values(array_unique($entities)),
        ];
    }
}
