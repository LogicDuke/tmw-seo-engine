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

        // Audit fix: extend linking beyond model pages to video posts.
        $supported_types = ['model', 'post'];
        if (!in_array($post->post_type, $supported_types, true)) {
            return $content;
        }

        if ($post->post_type === 'model') {
            return $this->inject_model_links($content, (int) $post->ID);
        }

        if ($post->post_type === 'post') {
            return $this->inject_video_links($content, $post);
        }

        return $content;
    }

    /**
     * Inject internal links for model pages (original behavior, preserved).
     */
    private function inject_model_links(string $content, int $post_id): string {
        $links = $this->generate_links($post_id);
        \TMWSEO\Engine\Debug\DebugLogger::log_internal_links([
            'post_id' => $post_id,
            'related_models' => count($links['related_models'] ?? []),
            'tag_links' => count($links['tag_links'] ?? []),
            'category_links' => count($links['category_links'] ?? []),
        ]);

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

    /**
     * Inject internal links for video posts.
     *
     * Audit fix: video pages were previously orphaned from the link engine.
     * Conservative first pass: video → model backlink is mandatory,
     * plus related tag/category links.
     */
    private function inject_video_links(string $content, \WP_Post $post): string {
        $sections = [];

        // 1. Link back to the model page (mandatory).
        $model_link = $this->find_model_for_video($post);
        if ($model_link) {
            $sections[] = '<section class="tmw-video-model-link"><h3>About '
                . esc_html($model_link['title']) . '</h3><p>See '
                . '<a href="' . esc_url($model_link['url']) . '">'
                . esc_html($model_link['title']) . '\'s full profile</a>'
                . ' for live shows, platform links, and more.</p></section>';
        }

        // 2. Tag links (reuse existing term link builder).
        $tag_terms = $this->graph->get_model_tags((int) $post->ID);
        // Also try direct post tags.
        if (empty($tag_terms)) {
            $direct_tags = wp_get_post_terms($post->ID, 'post_tag');
            if (is_array($direct_tags)) {
                $tag_terms = $direct_tags;
            }
        }
        $tag_links = $this->build_term_links(array_slice($tag_terms, 0, 4));
        if (!empty($tag_links)) {
            $items = array_map([$this, 'render_link_item'], $tag_links);
            $sections[] = '<section class="tmw-video-tags"><h3>Related Tags</h3><ul>' . implode('', $items) . '</ul></section>';
        }

        // 3. Category links.
        $cat_terms = wp_get_post_terms($post->ID, 'category');
        if (is_array($cat_terms) && !empty($cat_terms)) {
            $cat_links = $this->build_term_links(array_slice($cat_terms, 0, 3));
            if (!empty($cat_links)) {
                $items = array_map([$this, 'render_link_item'], $cat_links);
                $sections[] = '<section class="tmw-video-categories"><h3>Browse More</h3><ul>' . implode('', $items) . '</ul></section>';
            }
        }

        if (empty($sections)) {
            return $content;
        }

        return $content . "\n" . implode("\n", $sections);
    }

    /**
     * Find the model page associated with a video post.
     *
     * Checks the 'models' taxonomy and also tries slug-based lookup.
     *
     * @return array{title:string,url:string}|null
     */
    private function find_model_for_video(\WP_Post $post): ?array {
        // Try 'models' taxonomy first.
        $model_terms = wp_get_post_terms($post->ID, 'models');
        if (!is_wp_error($model_terms) && is_array($model_terms) && !empty($model_terms)) {
            $model_term = $model_terms[0];
            // Find the model CPT post matching this term slug.
            $model_posts = get_posts([
                'post_type'      => 'model',
                'name'           => $model_term->slug,
                'posts_per_page' => 1,
                'post_status'    => 'publish',
                'fields'         => 'ids',
            ]);
            if (!empty($model_posts)) {
                $model_id = (int) $model_posts[0];
                return [
                    'title' => (string) get_the_title($model_id),
                    'url'   => (string) get_permalink($model_id),
                ];
            }
        }

        // Try extracting model name from video post meta.
        $model_slug = (string) get_post_meta($post->ID, '_tmw_model_slug', true);
        if ($model_slug === '') {
            // Try from post title pattern: many imports start with model name.
            $model_slug = sanitize_title(explode(' ', $post->post_title)[0] ?? '');
        }

        if ($model_slug !== '') {
            $model_posts = get_posts([
                'post_type'      => 'model',
                'name'           => $model_slug,
                'posts_per_page' => 1,
                'post_status'    => 'publish',
                'fields'         => 'ids',
            ]);
            if (!empty($model_posts)) {
                $model_id = (int) $model_posts[0];
                return [
                    'title' => (string) get_the_title($model_id),
                    'url'   => (string) get_permalink($model_id),
                ];
            }
        }

        return null;
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
