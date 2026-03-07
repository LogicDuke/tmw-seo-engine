<?php

if (!defined('ABSPATH')) { exit; }

class TMW_Model_Similarity_Engine {
    private const MIN_SCORE_TO_STORE = 61;
    private const EMPTY_CLUSTER_REBUILD_META_KEY = '_tmw_similarity_empty_cluster_rebuild_at';
    private const EMPTY_CLUSTER_REBUILD_COOLDOWN = 43200;

    private $calculator;
    private $database;
    private $cluster_builder;

    public function __construct(
        ?TMW_Model_Similarity_Calculator $calculator = null,
        ?TMW_Similarity_Database $database = null,
        ?TMW_Model_Cluster_Builder $cluster_builder = null
    ) {
        $this->calculator = $calculator ?: new TMW_Model_Similarity_Calculator();
        $this->database = $database ?: new TMW_Similarity_Database();
        $this->cluster_builder = $cluster_builder ?: new TMW_Model_Cluster_Builder($this->database);
    }

    public static function init(): void {
        $engine = new self();
        add_action('save_post_model', [$engine, 'refresh_model_relationships'], 20, 3);
        add_filter('the_content', [$engine, 'inject_similar_models_section'], 25);
    }

    public function refresh_model_relationships(int $post_id, \WP_Post $post, bool $update): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if ($post->post_status !== 'publish') {
            $this->database->delete_for_model($post_id);
            return;
        }

        $this->rebuild_relationships_for_model($post_id);
    }

    public function rebuild_relationships_for_model(int $model_id): int {
        $model = get_post($model_id);
        if (!($model instanceof \WP_Post) || $model->post_type !== 'model' || $model->post_status !== 'publish') {
            return 0;
        }

        $source = $this->collect_model_metadata($model_id);
        if (empty($source)) {
            return 0;
        }

        $this->database->delete_for_model($model_id);

        $candidates = get_posts([
            'post_type' => 'model',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post__not_in' => [$model_id],
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'suppress_filters' => true,
        ]);

        if (empty($candidates) || !is_array($candidates)) {
            return 0;
        }

        $saved_relationships = 0;

        foreach ($candidates as $candidate_id) {
            $candidate_id = (int) $candidate_id;
            if ($candidate_id <= 0) {
                continue;
            }

            $candidate = $this->collect_model_metadata($candidate_id);
            $score = $this->calculator->calculate_score($source, $candidate);

            if ($score < self::MIN_SCORE_TO_STORE) {
                continue;
            }

            $this->database->save_relationship($model_id, $candidate_id, $score);
            $this->database->save_relationship($candidate_id, $model_id, $score);
            $saved_relationships++;
        }

        return $saved_relationships;
    }

    public function inject_similar_models_section(string $content): string {
        if (is_admin() || !is_singular('model') || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $model_id = (int) get_the_ID();
        if ($model_id <= 0) {
            return $content;
        }

        $cluster = $this->cluster_builder->get_cluster($model_id, 5);
        if (empty($cluster) && $this->should_attempt_empty_cluster_rebuild($model_id)) {
            $saved_relationships = $this->rebuild_relationships_for_model($model_id);
            $this->record_empty_cluster_rebuild_attempt($model_id, $saved_relationships > 0);
            $cluster = $this->cluster_builder->get_cluster($model_id, 5);
        }

        if (empty($cluster)) {
            return $content;
        }

        $items = array_map(static function(array $model): string {
            return sprintf(
                '<li><a href="%s">%s</a></li>',
                esc_url((string) ($model['url'] ?? '')),
                esc_html((string) ($model['title'] ?? ''))
            );
        }, $cluster);

        $section = '<section class="tmw-similar-cam-models"><h3>Similar Cam Models</h3><ul>' . implode('', $items) . '</ul></section>';

        return $content . "\n" . $section;
    }

    private function should_attempt_empty_cluster_rebuild(int $model_id): bool {
        $last_attempt = (int) get_post_meta($model_id, self::EMPTY_CLUSTER_REBUILD_META_KEY, true);
        if ($last_attempt <= 0) {
            return true;
        }

        return (time() - $last_attempt) >= self::EMPTY_CLUSTER_REBUILD_COOLDOWN;
    }

    private function record_empty_cluster_rebuild_attempt(int $model_id, bool $relationships_found): void {
        if ($relationships_found) {
            delete_post_meta($model_id, self::EMPTY_CLUSTER_REBUILD_META_KEY);
            return;
        }

        update_post_meta($model_id, self::EMPTY_CLUSTER_REBUILD_META_KEY, time());
    }

    private function collect_model_metadata(int $model_id): array {
        $post = get_post($model_id);
        if (!($post instanceof \WP_Post)) {
            return [];
        }

        return [
            'tags' => $this->collect_terms_by_hint($model_id, 'tag'),
            'platform' => $this->collect_platform($model_id),
            'category' => $this->collect_primary_category($model_id),
            'keyword_pack' => $this->collect_keyword_pack($model_id),
            'bio_text' => $this->collect_bio_text($post),
        ];
    }

    private function collect_terms_by_hint(int $model_id, string $hint): array {
        $taxonomies = get_object_taxonomies('model', 'names');
        $matches = [];

        foreach ((array) $taxonomies as $taxonomy) {
            if (stripos((string) $taxonomy, $hint) === false) {
                continue;
            }

            $terms = get_the_terms($model_id, $taxonomy);
            if (!is_array($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                if (!($term instanceof \WP_Term)) {
                    continue;
                }

                $matches[] = sanitize_title((string) $term->slug);
            }
        }

        return array_values(array_unique(array_filter($matches, 'strlen')));
    }

    private function collect_primary_category(int $model_id): string {
        $categories = $this->collect_terms_by_hint($model_id, 'cat');
        return (string) ($categories[0] ?? '');
    }

    private function collect_platform(int $model_id): string {
        $platform = sanitize_title((string) get_post_meta($model_id, '_tmwseo_platform_primary', true));
        if ($platform !== '') {
            return $platform;
        }

        $platform_terms = $this->collect_terms_by_hint($model_id, 'platform');
        return (string) ($platform_terms[0] ?? '');
    }

    private function collect_keyword_pack(int $model_id): array {
        $pack = get_post_meta($model_id, '_tmwseo_keyword_pack', true);
        if (is_string($pack) && $pack !== '') {
            $decoded = json_decode($pack, true);
            if (is_array($decoded)) {
                $keywords = [];
                $keywords[] = (string) ($decoded['primary'] ?? '');
                $keywords = array_merge($keywords, (array) ($decoded['additional'] ?? []), (array) ($decoded['longtail'] ?? []));

                return array_values(array_unique(array_filter(array_map('sanitize_title', array_map('strval', $keywords)), 'strlen')));
            }
        }

        $fallback = array_filter([
            (string) get_post_meta($model_id, '_tmwseo_keyword', true),
            (string) get_post_meta($model_id, 'rank_math_focus_keyword', true),
            (string) get_post_meta($model_id, 'rank_math_secondary_keywords', true),
        ], 'strlen');

        $keywords = [];
        foreach ($fallback as $value) {
            foreach (explode(',', (string) $value) as $keyword) {
                $slug = sanitize_title((string) $keyword);
                if ($slug !== '') {
                    $keywords[] = $slug;
                }
            }
        }

        return array_values(array_unique($keywords));
    }

    private function collect_bio_text(\WP_Post $post): string {
        $bio = (string) get_post_meta($post->ID, '_tmw_model_bio', true);
        if ($bio === '') {
            $bio = (string) $post->post_excerpt;
        }

        if ($bio === '') {
            $bio = (string) $post->post_content;
        }

        return trim(wp_strip_all_tags($bio));
    }
}
