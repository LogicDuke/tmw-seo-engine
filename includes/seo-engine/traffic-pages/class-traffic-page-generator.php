<?php
namespace TMWSEO\Engine\TrafficPages;

use TMWSEO\Engine\AI\AIRouter;
use TMWSEO\Engine\KeywordIntelligence\KeywordDatabase;
use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class TrafficPageGenerator {
    public const CPT = 'tmwseo_traffic_page';
    public const CRON_HOOK = 'tmwseo_generate_traffic_pages';
    private const MAX_PER_RUN = 20;

    public static function init(): void {
        add_action('init', [__CLASS__, 'register_cpt']);
        add_action(self::CRON_HOOK, [__CLASS__, 'run_cron']);
        add_action('admin_post_tmwseo_generate_traffic_pages', [__CLASS__, 'handle_manual_generate']);
        add_action('save_post_' . self::CPT, [__CLASS__, 'store_keyword_mapping'], 10, 3);
        add_action('wp_head', [__CLASS__, 'render_schema']);
        add_filter('cron_schedules', [__CLASS__, 'ensure_weekly_schedule']);
        add_filter('post_type_link', [__CLASS__, 'filter_post_type_link'], 10, 2);
    }

    public static function activate(): void {
        self::register_cpt();
        self::schedule_cron();
    }

    public static function deactivate(): void {
        self::unschedule_cron();
    }

    public static function register_cpt(): void {
        register_post_type(self::CPT, [
            'labels' => [
                'name' => __('Traffic Pages', 'tmwseo'),
                'singular_name' => __('Traffic Page', 'tmwseo'),
            ],
            'public' => true,
            'has_archive' => true,
            'rewrite' => true,
            'show_in_rest' => true,
            'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'revisions'],
            'menu_icon' => 'dashicons-chart-area',
        ]);

        add_rewrite_rule('([^/]+)/?$', 'index.php?post_type=' . self::CPT . '&name=$matches[1]', 'top');
    }


    public static function filter_post_type_link(string $permalink, \WP_Post $post): string {
        if ($post->post_type !== self::CPT) {
            return $permalink;
        }

        return home_url('/' . $post->post_name . '/');
    }

    public static function ensure_weekly_schedule(array $schedules): array {
        if (!isset($schedules['tmwseo_weekly'])) {
            $schedules['tmwseo_weekly'] = [
                'interval' => 7 * DAY_IN_SECONDS,
                'display' => __('Weekly (TMW SEO Engine)', 'tmwseo'),
            ];
        }

        return $schedules;
    }

    public static function schedule_cron(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'tmwseo_weekly', self::CRON_HOOK);
        }
    }

    public static function unschedule_cron(): void {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    public static function run_cron(): void {
        $generator = new self();
        $result = $generator->generate_pages(self::MAX_PER_RUN);
        Logs::info('traffic_pages', '[TMW-TRAFFIC] Weekly cron completed', $result);
    }

    public static function handle_manual_generate(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_generate_traffic_pages');

        $generator = new self();
        $result = $generator->generate_pages(self::MAX_PER_RUN);

        $redirect = add_query_arg([
            'page' => 'tmwseo-engine-v2',
            'tmwseo_notice' => 'traffic_pages_generated',
            'tmwseo_created' => (int) ($result['created'] ?? 0),
            'tmwseo_skipped' => (int) ($result['skipped'] ?? 0),
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * @return array{ok:bool,created:int,skipped:int,processed:int,errors:array<int,string>}
     */
    public function generate_pages(int $limit = self::MAX_PER_RUN): array {
        $limit = max(1, min(self::MAX_PER_RUN, $limit));
        $rows = $this->get_keyword_opportunities($limit * 3);
        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $row) {
            if ($created >= $limit) {
                break;
            }

            $keyword = sanitize_text_field((string) ($row['keyword'] ?? ''));
            if ($keyword === '') {
                $skipped++;
                continue;
            }

            $slug = sanitize_title($keyword);
            if ($slug === '' || $this->slug_exists($slug)) {
                $skipped++;
                continue;
            }

            $post_id = wp_insert_post([
                'post_status' => 'publish',
                'post_type' => self::CPT,
                'post_name' => $slug,
                'post_title' => sprintf('Best %s', ucwords($keyword)),
                'post_content' => $this->build_page_content($keyword),
            ], true);

            if (is_wp_error($post_id)) {
                $errors[] = sprintf('%s: %s', $keyword, $post_id->get_error_message());
                continue;
            }

            update_post_meta($post_id, '_tmwseo_target_keyword', $keyword);
            update_post_meta($post_id, '_tmwseo_traffic_page_slug', $slug);
            update_post_meta($post_id, '_tmwseo_traffic_page_schema', wp_json_encode($this->build_item_list_schema($post_id), JSON_UNESCAPED_SLASHES));
            $this->map_keyword_to_url($keyword, get_permalink($post_id));

            $created++;
        }

        return [
            'ok' => empty($errors),
            'created' => $created,
            'skipped' => $skipped,
            'processed' => $created + $skipped,
            'errors' => $errors,
        ];
    }

    private function get_keyword_opportunities(int $limit): array {
        global $wpdb;

        $primary = KeywordDatabase::table_name();
        $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $primary));

        if ($exists === $primary) {
            return KeywordDatabase::get_generation_candidates(max(1, $limit));
        }

        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $rank_table = $wpdb->prefix . 'tmw_seo_ranking_probability';

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT kc.keyword, kc.volume AS search_volume, kc.difficulty,
                    COALESCE(rp.ranking_probability, 0) AS ranking_probability
             FROM {$cand_table} kc
             LEFT JOIN (
                 SELECT rp1.keyword, rp1.ranking_probability
                 FROM {$rank_table} rp1
                 INNER JOIN (
                     SELECT keyword, MAX(id) AS max_id
                     FROM {$rank_table}
                     GROUP BY keyword
                 ) latest ON latest.max_id = rp1.id
             ) rp ON rp.keyword = kc.keyword
             WHERE kc.volume >= %d
               AND kc.difficulty <= %d
             ORDER BY kc.volume DESC
             LIMIT %d",
            50,
            40,
            max(1, $limit)
        ), ARRAY_A);

        $filtered = [];
        foreach ($rows as $row) {
            $probability = (float) ($row['ranking_probability'] ?? 0);
            $normalized = $probability > 1 ? $probability / 100 : $probability;
            if ($normalized < 0.6) {
                continue;
            }

            $slug = sanitize_title((string) ($row['keyword'] ?? ''));
            if ($slug === '' || $this->slug_exists($slug)) {
                continue;
            }

            $filtered[] = $row;
        }

        return $filtered;
    }

    private function slug_exists(string $slug): bool {
        if ($slug === '') {
            return true;
        }

        $existing = get_page_by_path($slug, OBJECT, [self::CPT, 'page', 'post']);
        return $existing instanceof \WP_Post;
    }

    private function build_page_content(string $keyword): string {
        $h1 = sprintf('Best %s', ucwords($keyword));
        $intro = $this->generate_intro($keyword);

        $top_models = $this->render_model_grid('Top Models', [
            'posts_per_page' => 8,
            'orderby' => 'comment_count',
            'order' => 'DESC',
        ]);

        $new_models = $this->render_model_grid('Newest Models', [
            'posts_per_page' => 8,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $categories = $this->render_tax_links('Popular Categories', 'category', 6);
        $related = $this->render_related_searches($keyword, 8);
        $internal_links = $this->render_internal_links();

        return implode("\n\n", [
            '<h1>' . esc_html($h1) . '</h1>',
            '<p>' . esc_html($intro) . '</p>',
            $top_models,
            $new_models,
            $categories,
            $related,
            $internal_links,
            '<p><a href="' . esc_url(home_url('/models/')) . '">Explore all models</a> · <a href="' . esc_url(home_url('/category/')) . '">Browse categories</a> · <a href="' . esc_url(home_url('/tag/')) . '">View tags</a></p>',
        ]);
    }

    private function generate_intro(string $keyword): string {
        $messages = [
            ['role' => 'system', 'content' => 'You are an SEO copywriter. Return valid JSON only.'],
            ['role' => 'user', 'content' => 'Write a short SEO optimized introduction for the page targeting the keyword: ' . $keyword . '. Focus on cam models and online live streaming. Return JSON with key "intro". Keep intro length between 120 and 150 words.'],
        ];

        $res = AIRouter::chat_json($messages, [
            'task' => 'traffic_page_intro',
            'max_tokens' => 260,
            'temperature' => 0.6,
        ]);

        if (!empty($res['ok'])) {
            $intro = trim((string) ($res['json']['intro'] ?? ''));
            if ($intro !== '') {
                return wp_trim_words($intro, 150, '');
            }
        }

        return sprintf(
            'Discover the best options for %s with curated live performers, verified profiles, and active rooms updated daily. This page helps you compare featured talent fast, filter by style and category, and jump directly into live streams that match your preferences. Whether you want trending personalities, rising newcomers, or niche favorites, our selection focuses on quality, consistency, and real-time availability. Use the sections below to explore top models, newest profiles, popular categories, and related searches so you can find the right live cam experience in seconds.',
            $keyword
        );
    }

    private function render_model_grid(string $title, array $args): string {
        $models = get_posts(array_merge([
            'post_type' => 'model',
            'post_status' => 'publish',
            'posts_per_page' => 8,
            'no_found_rows' => true,
        ], $args));

        $items = '';
        foreach ($models as $model) {
            $items .= '<li><a href="' . esc_url(get_permalink($model)) . '">' . esc_html(get_the_title($model)) . '</a></li>';
        }

        if ($items === '') {
            $items = '<li><a href="' . esc_url(home_url('/models/')) . '">Browse all models</a></li>';
        }

        return '<h2>' . esc_html($title) . '</h2><ul>' . $items . '</ul>';
    }

    private function render_tax_links(string $title, string $taxonomy, int $limit): string {
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => true,
            'number' => $limit,
        ]);

        $items = '';
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $url = get_term_link($term);
                if (is_wp_error($url)) {
                    continue;
                }
                $items .= '<li><a href="' . esc_url($url) . '">' . esc_html($term->name) . '</a></li>';
            }
        }

        if ($items === '') {
            $items = '<li><a href="' . esc_url(home_url('/category/')) . '">See all categories</a></li>';
        }

        return '<h2>' . esc_html($title) . '</h2><ul>' . $items . '</ul>';
    }

    private function render_related_searches(string $keyword, int $limit): string {
        global $wpdb;
        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $like = '%' . $wpdb->esc_like(substr($keyword, 0, 12)) . '%';

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT keyword FROM {$cand_table} WHERE keyword LIKE %s AND keyword != %s ORDER BY volume DESC LIMIT %d",
            $like,
            $keyword,
            max(1, $limit)
        ), ARRAY_A);

        $items = '';
        foreach ($rows as $row) {
            $kw = (string) ($row['keyword'] ?? '');
            if ($kw === '') {
                continue;
            }
            $items .= '<li>' . esc_html($kw) . '</li>';
        }

        if ($items === '') {
            $items = '<li>' . esc_html($keyword . ' live') . '</li>';
        }

        return '<h2>Related Searches</h2><ul>' . $items . '</ul>';
    }

    private function render_internal_links(): string {
        $related_pages = get_posts([
            'post_type' => self::CPT,
            'post_status' => 'publish',
            'posts_per_page' => 5,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        $model_pages = get_posts([
            'post_type' => 'model',
            'post_status' => 'publish',
            'posts_per_page' => 5,
            'orderby' => 'rand',
        ]);

        $categories = get_terms([
            'taxonomy' => 'category',
            'hide_empty' => true,
            'number' => 2,
        ]);

        $html = '<h2>Related Traffic Pages</h2><ul>' . $this->list_posts($related_pages, home_url('/models/')) . '</ul>';
        $html .= '<h2>Featured Model Profiles</h2><ul>' . $this->list_posts($model_pages, home_url('/models/')) . '</ul>';
        $html .= '<h2>Category Links</h2><ul>' . $this->list_terms($categories, home_url('/category/')) . '</ul>';

        return $html;
    }

    private function list_posts(array $posts, string $fallback): string {
        $items = '';
        foreach ($posts as $post) {
            $items .= '<li><a href="' . esc_url(get_permalink($post)) . '">' . esc_html(get_the_title($post)) . '</a></li>';
        }
        if ($items === '') {
            $items = '<li><a href="' . esc_url($fallback) . '">' . esc_html($fallback) . '</a></li>';
        }

        return $items;
    }

    private function list_terms($terms, string $fallback): string {
        $items = '';
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $url = get_term_link($term);
                if (is_wp_error($url)) {
                    continue;
                }
                $items .= '<li><a href="' . esc_url($url) . '">' . esc_html($term->name) . '</a></li>';
            }
        }

        if ($items === '') {
            $items = '<li><a href="' . esc_url($fallback) . '">' . esc_html($fallback) . '</a></li>';
        }

        return $items;
    }

    public static function store_keyword_mapping(int $post_id, \WP_Post $post, bool $update): void {
        if (wp_is_post_revision($post_id) || $post->post_type !== self::CPT) {
            return;
        }

        $keyword = (string) get_post_meta($post_id, '_tmwseo_target_keyword', true);
        if ($keyword === '') {
            return;
        }

        $generator = new self();
        $generator->map_keyword_to_url($keyword, get_permalink($post_id));
    }

    private function map_keyword_to_url(string $keyword, string $url): void {
        global $wpdb;

        $primary = KeywordDatabase::table_name();
        $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $primary));

        if ($exists === $primary) {
            $wpdb->update(
                $primary,
                ['mapped_url' => esc_url_raw($url)],
                ['keyword' => $keyword],
                ['%s'],
                ['%s']
            );
        }
    }

    private function build_item_list_schema(int $post_id): array {
        $models = get_posts([
            'post_type' => 'model',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'orderby' => 'comment_count',
            'order' => 'DESC',
        ]);

        $elements = [];
        $position = 1;
        foreach ($models as $model) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $position,
                'name' => get_the_title($model),
                'url' => get_permalink($model),
            ];
            $position++;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => get_the_title($post_id),
            'itemListElement' => $elements,
        ];
    }

    public static function render_schema(): void {
        if (!is_singular(self::CPT)) {
            return;
        }

        $post_id = get_queried_object_id();
        if ($post_id <= 0) {
            return;
        }

        $raw = (string) get_post_meta($post_id, '_tmwseo_traffic_page_schema', true);
        if ($raw === '') {
            $generator = new self();
            $raw = wp_json_encode($generator->build_item_list_schema($post_id), JSON_UNESCAPED_SLASHES);
        }

        if ($raw !== '') {
            echo "\n<script type=\"application/ld+json\">" . $raw . '</script>' . "\n";
        }
    }
}
