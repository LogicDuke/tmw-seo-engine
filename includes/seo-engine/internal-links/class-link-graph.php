<?php

if (!defined('ABSPATH')) { exit; }

class TMW_Link_Graph {
    /** @var string[] */
    private $model_tag_taxonomies = ['models', 'post_tag'];

    /** @var string[] */
    private $model_category_taxonomies = ['category'];

    public function get_model_tags(int $post_id): array {
        return $this->collect_terms($post_id, $this->available_taxonomies($post_id, $this->model_tag_taxonomies));
    }

    public function get_model_categories(int $post_id): array {
        return $this->collect_terms($post_id, $this->available_taxonomies($post_id, $this->model_category_taxonomies));
    }

    public function get_platform_categories(int $post_id): array {
        $platforms = $this->get_model_platforms($post_id);
        if (empty($platforms)) {
            return [];
        }

        $terms = [];
        $by_slug = [];

        foreach ($this->available_taxonomies($post_id, $this->model_category_taxonomies) as $taxonomy) {
            foreach ($platforms as $platform) {
                $slug = sanitize_title($platform . '-models');
                if (isset($by_slug[$slug])) {
                    continue;
                }

                $term = get_term_by('slug', $slug, $taxonomy);
                if ($term instanceof WP_Term) {
                    $terms[] = $term;
                    $by_slug[$slug] = true;
                }
            }
        }

        return $terms;
    }

    public function get_model_platforms(int $post_id): array {
        $platforms = [];

        if (class_exists('\\TMWSEO\\Engine\\Platform\\PlatformProfiles')) {
            $links = \TMWSEO\Engine\Platform\PlatformProfiles::get_links($post_id);
            if (is_array($links)) {
                foreach ($links as $link) {
                    if (!is_array($link)) {
                        continue;
                    }

                    $platform = trim((string) ($link['platform'] ?? ''));
                    if ($platform !== '') {
                        $platforms[] = $platform;
                    }
                }
            }
        }

        $meta_platform = trim((string) get_post_meta($post_id, 'tmw_model_platform', true));
        if ($meta_platform !== '') {
            $platforms[] = $meta_platform;
        }

        return array_values(array_unique($platforms));
    }

    private function available_taxonomies(int $post_id, array $candidates): array {
        $post_type = get_post_type($post_id);
        if (!is_string($post_type) || $post_type === '') {
            return [];
        }

        $registered = get_object_taxonomies($post_type, 'names');
        if (!is_array($registered)) {
            return [];
        }

        return array_values(array_intersect($candidates, $registered));
    }

    private function collect_terms(int $post_id, array $taxonomies): array {
        if (empty($taxonomies)) {
            return [];
        }

        $terms = [];
        $seen = [];

        foreach ($taxonomies as $taxonomy) {
            $tax_terms = get_the_terms($post_id, $taxonomy);
            if (empty($tax_terms) || is_wp_error($tax_terms)) {
                continue;
            }

            foreach ($tax_terms as $term) {
                if (!($term instanceof WP_Term)) {
                    continue;
                }

                $key = $term->taxonomy . ':' . $term->term_id;
                if (isset($seen[$key])) {
                    continue;
                }

                $terms[] = $term;
                $seen[$key] = true;
            }
        }

        return $terms;
    }
}
