<?php

if (!defined('ABSPATH')) exit;

class TMW_Cluster_Link_Injector {
    private $cluster_service;
    private $linking_engine;

    public function __construct(TMW_Cluster_Service $cluster_service, TMW_Cluster_Linking_Engine $linking_engine) {
        $this->cluster_service = $cluster_service;
        $this->linking_engine = $linking_engine;
    }

    public function inject_missing_links($cluster_id) {
        $cluster_id = (int) $cluster_id;
        $max_links_per_post = 5;

        if ($cluster_id <= 0) {
            return [];
        }

        $analysis = $this->linking_engine->analyze_cluster($cluster_id);

        if (empty($analysis['missing_links']) || !is_array($analysis['missing_links'])) {
            return ['updated' => 0];
        }

        $updated = 0;
        $processed_pairs = [];

        $missing_links_by_source = [];

        foreach ($analysis['missing_links'] as $missing_link) {
            $from_id = isset($missing_link['from']) ? (int) $missing_link['from'] : 0;
            $to_id = isset($missing_link['to']) ? (int) $missing_link['to'] : 0;

            if ($from_id <= 0 || $to_id <= 0) {
                continue;
            }

            $missing_links_by_source[$from_id][] = $to_id;
        }

        foreach ($missing_links_by_source as $from_id => $target_ids) {
            $post = get_post($from_id);

            if (!$post) {
                continue;
            }

            if ($post->post_status !== 'publish') {
                continue;
            }

            if (!in_array($post->post_type, ['post', 'page'], true)) {
                continue;
            }

            $existing_links = substr_count($post->post_content, '<a href=');
            if ($existing_links >= $max_links_per_post) {
                continue;
            }

            foreach ($target_ids as $to_id) {
                $pair_key = $from_id . ':' . $to_id;
                if (isset($processed_pairs[$pair_key])) {
                    continue;
                }

                $target_url = get_permalink($to_id);
                $target_title = get_the_title($to_id);
                $anchor_text = $target_title;
                $anchor_link = '<a href="' . esc_url($target_url) . '">' . esc_html($anchor_text) . '</a>';
                $content = $post->post_content;

                if (strpos($content, $target_url) !== false) {
                    continue;
                }

                $limit = (int) (strlen($content) * 0.6);
                $search_area = substr($content, 0, $limit);
                $pos = stripos($search_area, $anchor_text);

                if ($pos !== false) {
                    $new_content = substr_replace(
                        $content,
                        $anchor_link,
                        $pos,
                        strlen($anchor_text)
                    );
                } else {
                    $new_content = $content . '<p>' . $anchor_link . '</p>';
                }

                $result = wp_update_post([
                    'ID' => $post->ID,
                    'post_content' => $new_content,
                ], true);

                if (!is_wp_error($result) && $result) {
                    $updated++;
                    $processed_pairs[$pair_key] = true;
                    break;
                }
            }
        }

        TMW_Main_Class::clear_cluster_cache($cluster_id);

        return [
            'updated' => $updated,
        ];
    }
}
