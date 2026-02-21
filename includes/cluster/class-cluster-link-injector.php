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

        if ($cluster_id <= 0) {
            return [];
        }

        $analysis = $this->linking_engine->analyze_cluster($cluster_id);

        if (empty($analysis['missing_links']) || !is_array($analysis['missing_links'])) {
            return ['updated' => 0];
        }

        $updated = 0;
        $processed_pairs = [];

        foreach ($analysis['missing_links'] as $missing_link) {
            $from_id = isset($missing_link['from']) ? (int) $missing_link['from'] : 0;
            $to_id = isset($missing_link['to']) ? (int) $missing_link['to'] : 0;

            if ($from_id <= 0 || $to_id <= 0) {
                continue;
            }

            $pair_key = $from_id . ':' . $to_id;
            if (isset($processed_pairs[$pair_key])) {
                continue;
            }

            $post = get_post($from_id);

            if (!$post) {
                continue;
            }

            $target_url = get_permalink($to_id);
            $target_title = get_the_title($to_id);

            $new_content = $post->post_content . '<p><a href="' . esc_url($target_url) . '">' . esc_html($target_title) . '</a></p>';

            $result = wp_update_post([
                'ID' => $post->ID,
                'post_content' => $new_content,
            ], true);

            if (!is_wp_error($result) && $result) {
                $updated++;
                $processed_pairs[$pair_key] = true;
            }
        }

        TMW_Main_Class::clear_cluster_cache($cluster_id);

        return [
            'updated' => $updated,
        ];
    }
}
