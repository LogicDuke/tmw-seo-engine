<?php

if (!defined('ABSPATH')) { exit; }

class TMW_Intent_Engine {
    private $analyzer;
    private $section_builder;

    public function __construct(
        ?TMW_Intent_Analyzer $analyzer = null,
        ?TMW_Intent_Section_Builder $section_builder = null
    ) {
        $this->analyzer = $analyzer ?: new TMW_Intent_Analyzer();
        $this->section_builder = $section_builder ?: new TMW_Intent_Section_Builder();
    }

    public static function init(): void {
        $engine = new self();
        add_filter('the_content', [$engine, 'inject_into_content'], 24);
    }

    public function inject_into_content(string $content): string {
        if (is_admin() || !is_singular('model') || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $post_id = (int) get_the_ID();
        if ($post_id <= 0 || strpos($content, 'tmw-intent-content') !== false) {
            return $content;
        }

        $generated = $this->generate_for_post($post_id, false);
        if ($generated === '') {
            return $content;
        }

        return $content . "\n" . $generated;
    }

    public function generate_for_post(int $post_id, bool $persist_rank_math_meta = true): string {
        $post = get_post($post_id);
        if (!($post instanceof WP_Post)) {
            return '';
        }

        $context = $this->build_context($post_id, $post);
        $analysis = $this->analyzer->analyze_for_post($post_id, $context['MODEL']);

        $sections = $this->section_builder->build(
            $analysis['intents'],
            $context,
            $analysis['keywords']
        );

        $integration_sections = array_filter([
            $this->build_internal_links_section($post_id),
            $this->build_model_similarity_section($post_id),
        ]);

        $sections = array_merge($sections, $integration_sections);
        $sections = array_values(array_filter($sections, 'strlen'));

        if (empty($sections)) {
            return '';
        }

        $html = '<div class="tmw-intent-content">' . implode("\n", $sections) . '</div>';

        if ($persist_rank_math_meta) {
            $this->sync_rank_math_meta($post_id, $context, $analysis['keywords']);
        }

        return $html;
    }

    /** @param array<string,string> $context @param string[] $keywords */
    private function sync_rank_math_meta(int $post_id, array $context, array $keywords): void {
        $focus_keyword = trim((string) ($keywords[0] ?? $context['MODEL']));
        if ($focus_keyword !== '') {
            update_post_meta($post_id, 'rank_math_focus_keyword', $focus_keyword);
        }

        $description = sprintf(
            'Discover %s live cam streams on %s. Explore related tags: %s.',
            $context['MODEL'],
            $context['PLATFORM'] !== '' ? $context['PLATFORM'] : 'top platforms',
            $context['TAGS'] !== '' ? $context['TAGS'] : 'cam models'
        );

        update_post_meta($post_id, 'rank_math_description', wp_trim_words($description, 26, '...'));
    }

    /** @return array<string,string> */
    private function build_context(int $post_id, WP_Post $post): array {
        $platform = (string) get_post_meta($post_id, '_tmwseo_platform_primary', true);
        if ($platform === '') {
            $platform_terms = get_the_terms($post_id, 'platform');
            if (is_array($platform_terms) && !empty($platform_terms[0]) && $platform_terms[0] instanceof WP_Term) {
                $platform = (string) $platform_terms[0]->name;
            }
        }

        $category = '';
        $categories = get_the_terms($post_id, 'category');
        if (is_array($categories) && !empty($categories[0]) && $categories[0] instanceof WP_Term) {
            $category = (string) $categories[0]->name;
        }

        $tags = [];
        $tag_terms = get_the_terms($post_id, 'post_tag');
        if (is_array($tag_terms)) {
            foreach ($tag_terms as $term) {
                if ($term instanceof WP_Term) {
                    $tags[] = (string) $term->name;
                }
            }
        }

        return [
            'MODEL' => (string) get_the_title($post),
            'PLATFORM' => $platform,
            'TAGS' => implode(', ', array_slice($tags, 0, 4)),
            'CATEGORY' => $category,
        ];
    }

    private function build_internal_links_section(int $post_id): string {
        if (!class_exists('TMW_Internal_Link_Engine')) {
            return '';
        }

        $engine = new TMW_Internal_Link_Engine();
        $links = $engine->generate_links($post_id);

        $items = [];
        foreach ((array) ($links['related_models'] ?? []) as $model) {
            $items[] = sprintf(
                '<li><a href="%s">%s</a></li>',
                esc_url((string) ($model['url'] ?? '')),
                esc_html((string) ($model['title'] ?? ''))
            );
        }

        if (empty($items)) {
            return '';
        }

        return '<section class="tmw-intent-internal-links"><h3>Related Model Links</h3><ul>' . implode('', $items) . '</ul></section>';
    }

    private function build_model_similarity_section(int $post_id): string {
        if (!class_exists('TMW_Model_Cluster_Builder')) {
            return '';
        }

        $builder = new TMW_Model_Cluster_Builder();
        $cluster = $builder->get_cluster($post_id, 5);

        $items = [];
        foreach ((array) $cluster as $model) {
            $items[] = sprintf(
                '<li><a href="%s">%s</a></li>',
                esc_url((string) ($model['url'] ?? '')),
                esc_html((string) ($model['title'] ?? ''))
            );
        }

        if (empty($items)) {
            return '';
        }

        return '<section class="tmw-intent-similar-models"><h3>Model Similarity Matches</h3><ul>' . implode('', $items) . '</ul></section>';
    }
}
