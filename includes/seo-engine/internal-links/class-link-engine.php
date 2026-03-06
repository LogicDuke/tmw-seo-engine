<?php

if (!defined('ABSPATH')) { exit; }

class TMW_Internal_Link_Engine {
    private $graph;
    private $related_models;

    public function __construct(?TMW_Link_Graph $graph = null, ?TMW_Related_Models $related_models = null) {
        $this->graph = $graph ?: new TMW_Link_Graph();
        $this->related_models = $related_models ?: new TMW_Related_Models($this->graph);
    }

    public static function init(): void {
        $engine = new self();
        add_filter('the_content', [$engine, 'inject_into_content']);
    }

    public function generate_links($post_id): array {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return [
                'related_models' => [],
                'tag_links' => [],
                'category_links' => [],
            ];
        }

        $tag_links = $this->build_term_links($this->graph->get_model_tags($post_id));

        $category_terms = array_merge(
            $this->graph->get_model_categories($post_id),
            $this->graph->get_platform_categories($post_id)
        );

        $category_links = $this->build_term_links($category_terms);
        $related_models = $this->related_models->get_related($post_id, 5);

        return [
            'related_models' => $related_models,
            'tag_links' => $tag_links,
            'category_links' => $category_links,
        ];
    }

    public function inject_into_content(string $content): string {
        if (is_admin() || !is_singular() || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        global $post;
        if (!($post instanceof WP_Post) || $post->post_status !== 'publish') {
            return $content;
        }

        if ($post->post_type !== 'model') {
            return $content;
        }

        $links = $this->generate_links((int) $post->ID);

        $sections = [];

        if (!empty($links['related_models'])) {
            $items = array_map(static function(array $model): string {
                return sprintf(
                    '<li><a href="%s">%s</a></li>',
                    esc_url((string) ($model['url'] ?? '')),
                    esc_html((string) ($model['title'] ?? ''))
                );
            }, $links['related_models']);

            $sections[] = '<section class="tmw-related-models"><h3>Related Cam Models</h3><ul>' . implode('', $items) . '</ul></section>';
        }

        if (!empty($links['tag_links'])) {
            $primary_tag = (string) ($links['tag_links'][0]['name'] ?? 'Cam Girls');
            $items = array_map([$this, 'render_link_item'], $links['tag_links']);
            $sections[] = '<section class="tmw-more-tags"><h3>More ' . esc_html(ucwords($primary_tag)) . ' Cam Girls</h3><ul>' . implode('', $items) . '</ul></section>';
        }

        if (!empty($links['category_links'])) {
            $primary_category = (string) ($links['category_links'][0]['name'] ?? 'Models');
            $items = array_map([$this, 'render_link_item'], $links['category_links']);
            $sections[] = '<section class="tmw-more-categories"><h3>More ' . esc_html(ucwords($primary_category)) . ' Models</h3><ul>' . implode('', $items) . '</ul></section>';
        }

        if (empty($sections)) {
            return $content;
        }

        return $content . "\n" . implode("\n", $sections);
    }

    private function build_term_links(array $terms): array {
        $links = [];
        $seen = [];

        foreach ($terms as $term) {
            if (!($term instanceof WP_Term)) {
                continue;
            }

            $url = get_term_link($term);
            if (is_wp_error($url)) {
                continue;
            }

            $key = $term->taxonomy . ':' . $term->term_id;
            if (isset($seen[$key])) {
                continue;
            }

            $links[] = [
                'term_id' => (int) $term->term_id,
                'taxonomy' => (string) $term->taxonomy,
                'name' => (string) $term->name,
                'slug' => (string) $term->slug,
                'url' => (string) $url,
            ];

            $seen[$key] = true;
        }

        return $links;
    }

    private function render_link_item(array $link): string {
        return sprintf(
            '<li><a href="%s">%s</a></li>',
            esc_url((string) ($link['url'] ?? '')),
            esc_html((string) ($link['name'] ?? ''))
        );
    }
}
