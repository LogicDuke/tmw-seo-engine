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
        $existing_suggestions = [];
        $content_cache = [];
        $permalink_cache = [];

        $has_existing_link = static function ($from_post_id, $target_post_id) use (&$content_cache, &$permalink_cache) {
            $from_post_id = (int) $from_post_id;
            $target_post_id = (int) $target_post_id;

            if ($from_post_id <= 0 || $target_post_id <= 0) {
                return false;
            }

            if (!array_key_exists($from_post_id, $content_cache)) {
                $from_post = get_post($from_post_id);
                $content_cache[$from_post_id] = ($from_post && isset($from_post->post_content)) ? (string) $from_post->post_content : '';
            }

            if (!array_key_exists($target_post_id, $permalink_cache)) {
                $permalink = get_permalink($target_post_id);
                $permalink_cache[$target_post_id] = is_string($permalink) ? $permalink : '';
            }

            $content = $content_cache[$from_post_id];
            $permalink = $permalink_cache[$target_post_id];

            if ($content === '' || $permalink === '') {
                return false;
            }

            if (strpos($content, $permalink) !== false) {
                return true;
            }

            $relative_permalink = wp_parse_url($permalink, PHP_URL_PATH);

            if (!empty($relative_permalink)) {
                $query = wp_parse_url($permalink, PHP_URL_QUERY);
                $fragment = wp_parse_url($permalink, PHP_URL_FRAGMENT);

                if (!empty($query)) {
                    $relative_permalink .= '?' . $query;
                }

                if (!empty($fragment)) {
                    $relative_permalink .= '#' . $fragment;
                }

                if (strpos($content, $relative_permalink) !== false) {
                    return true;
                }

                if (strpos($content, 'href="' . $relative_permalink . '"') !== false || strpos($content, "href='" . $relative_permalink . "'") !== false) {
                    return true;
                }
            }

            if (strpos($content, 'href="' . $permalink . '"') !== false || strpos($content, "href='" . $permalink . "'") !== false) {
                return true;
            }

            return false;
        };

        foreach ($support_pages as $support_page) {
            $support_id = isset($support_page['post_id']) ? (int) $support_page['post_id'] : 0;

            if ($support_id <= 0 || $pillar_id <= 0) {
                continue;
            }

            $support_to_pillar_key = $support_id . ':' . $pillar_id . ':support_to_pillar';

            if (!isset($existing_suggestions[$support_to_pillar_key]) && !$has_existing_link($support_id, $pillar_id)) {
                $missing_links[] = [
                    'from' => $support_id,
                    'to' => $pillar_id,
                    'type' => 'support_to_pillar',
                ];
                $existing_suggestions[$support_to_pillar_key] = true;
            }

            $pillar_to_support_key = $pillar_id . ':' . $support_id . ':pillar_to_support';

            if (!isset($existing_suggestions[$pillar_to_support_key]) && !$has_existing_link($pillar_id, $support_id)) {
                $missing_links[] = [
                    'from' => $pillar_id,
                    'to' => $support_id,
                    'type' => 'pillar_to_support',
                ];
                $existing_suggestions[$pillar_to_support_key] = true;
            }
        }

        return [
            'cluster' => $cluster,
            'pillar' => $pillar_page,
            'supports' => $support_pages,
            'missing_links' => $missing_links,
        ];
    }
}
