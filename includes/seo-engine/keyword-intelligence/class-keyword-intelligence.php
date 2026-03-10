<?php
namespace TMWSEO\Engine\KeywordIntelligence;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Clustering\ClusterEngine;
use TMWSEO\Engine\Debug\DebugLogger;
use TMWSEO\Engine\KeywordIntelligence\KeywordClassifier;

if (!defined('ABSPATH')) { exit; }

class KeywordIntelligence {

    private KeywordExpander $expander;
    private KeywordFilter $filter;
    private KeywordIntent $intent;
    private KeywordScorer $scorer;
    private KeywordPackBuilder $pack_builder;

    public function __construct(
        ?KeywordExpander $expander = null,
        ?KeywordFilter $filter = null,
        ?KeywordIntent $intent = null,
        ?KeywordScorer $scorer = null,
        ?KeywordPackBuilder $pack_builder = null
    ) {
        $this->expander = $expander ?: new KeywordExpander();
        $this->filter = $filter ?: new KeywordFilter();
        $this->intent = $intent ?: new KeywordIntent();
        $this->scorer = $scorer ?: new KeywordScorer();
        $this->pack_builder = $pack_builder ?: new KeywordPackBuilder();
    }

    /**
     * @return array<string,mixed>
     */
    public function build_for_post(int $post_id, array $context = []): array {
        $context = $this->context_for_post($post_id, $context);

        $seed_keywords = $this->seed_keywords($context);
        $expanded = $this->expander->expand($seed_keywords);
        $filtered = $this->filter->filter($expanded);

        $scored = [];
        foreach ($filtered as $row) {
            $keyword = (string) ($row['keyword'] ?? '');
            if ($keyword === '') {
                continue;
            }

            $score = $this->scorer->score($keyword, $context);
            if ($score < 60) {
                continue;
            }

            $row['intent'] = $this->intent->classify($keyword, $context);
            $classification = KeywordClassifier::classify($keyword);
            $row['intent_type'] = (string) ($classification['intent_type'] ?? 'generic');
            $row['entity_type'] = (string) ($classification['entity_type'] ?? 'generic');
            $row['entity_id'] = (int) ($classification['entity_id'] ?? 0);
            $row['entities'] = (array) ($classification['entities'] ?? []);
            $row['score'] = $score;
            $scored[] = $row;
        }

        $pack = $this->pack_builder->build($scored);
        $pack['seed_keywords'] = $seed_keywords;

        update_post_meta($post_id, 'tmw_keyword_pack', $pack);

        $cluster_engine = new ClusterEngine();
        $cluster_engine->build_for_post($post_id);

        DebugLogger::log_keyword_processing([
            'post_id' => $post_id,
            'final_count' => count($pack['keywords'] ?? []),
        ]);

        Logs::info('keyword_intelligence', '[TMW-KIP] Keyword pack generated', [
            'post_id' => $post_id,
            'seed_count' => count($seed_keywords),
            'expanded_count' => count($expanded),
            'filtered_count' => count($filtered),
            'final_count' => count($pack['keywords'] ?? []),
        ]);

        return $pack;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function context_for_post(int $post_id, array $context): array {
        $post = get_post($post_id);

        $model_name = trim((string) ($context['model_name'] ?? ($post ? $post->post_title : '')));

        $model_tags = $context['model_tags'] ?? $this->get_post_terms($post_id, 'post_tag');
        if (!is_array($model_tags)) {
            $model_tags = [];
        }

        $platform_name = trim((string) ($context['platform_name'] ?? get_post_meta($post_id, '_tmwseo_platform_primary', true)));
        $category_name = trim((string) ($context['category_name'] ?? $this->first_term_name($post_id, 'category')));

        return [
            'model_name' => strtolower($model_name),
            'model_tags' => array_map(static fn($tag) => strtolower(trim((string) $tag)), $model_tags),
            'platform_name' => strtolower($platform_name),
            'category_name' => strtolower($category_name),
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return string[]
     */
    private function seed_keywords(array $context): array {
        $seeds = [];

        foreach (['model_name', 'platform_name', 'category_name'] as $key) {
            $value = trim((string) ($context[$key] ?? ''));
            if ($value !== '') {
                $seeds[] = $value;
            }
        }

        foreach ((array) ($context['model_tags'] ?? []) as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '') {
                $seeds[] = $tag;
            }
        }

        return array_values(array_unique($seeds));
    }

    /** @return string[] */
    private function get_post_terms(int $post_id, string $taxonomy): array {
        $terms = get_the_terms($post_id, $taxonomy);
        if (!is_array($terms)) {
            return [];
        }

        $names = [];
        foreach ($terms as $term) {
            if (!($term instanceof \WP_Term)) {
                continue;
            }
            $names[] = (string) $term->name;
        }

        return $names;
    }

    private function first_term_name(int $post_id, string $taxonomy): string {
        $terms = $this->get_post_terms($post_id, $taxonomy);
        return (string) ($terms[0] ?? '');
    }
}
