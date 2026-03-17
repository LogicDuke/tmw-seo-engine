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
    private const MIN_LINKS = 6;
    private const MAX_LINKS = 12;

    public static function init(): void {
        add_action('init', [__CLASS__, 'register_cpt']);
        add_action(self::CRON_HOOK, [__CLASS__, 'run_cron']);
        add_action('admin_post_tmwseo_generate_traffic_pages', [__CLASS__, 'handle_manual_generate']);
        add_action('save_post_' . self::CPT, [__CLASS__, 'store_keyword_mapping'], 10, 3);
        add_action('wp_head', [__CLASS__, 'render_schema']);
        add_filter('cron_schedules', [__CLASS__, 'ensure_weekly_schedule']);
        add_filter('post_type_link', [__CLASS__, 'filter_post_type_link'], 10, 2);
        // Safe rootless URL resolution — replaces the old catch-all add_rewrite_rule.
        add_filter('request', [__CLASS__, 'resolve_rootless_traffic_page']);
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

        // NOTE: The previous catch-all add_rewrite_rule('([^/]+)/?$', ..., 'top')
        // has been removed. It matched ANY single-segment URL before WordPress's own
        // rules, causing 404s on pages, posts, and category archives whose slugs did
        // not correspond to a traffic page.
        // Rootless traffic-page URL resolution is now handled safely by the 'request'
        // filter in resolve_rootless_traffic_page().
    }


    public static function filter_post_type_link(string $permalink, \WP_Post $post): string {
        if ($post->post_type !== self::CPT) {
            return $permalink;
        }

        return home_url('/' . $post->post_name . '/');
    }

    /**
     * Resolve rootless traffic-page URLs safely via the 'request' filter.
     *
     * WordPress's rewrite rules will parse a single-segment URL like /best-webcams/
     * as pagename=best-webcams (via the built-in page catch-all). This filter checks
     * whether a published traffic page exists with that slug and, if so, rewrites the
     * query to target the CPT. Unlike the previous add_rewrite_rule('([^/]+)/?$',
     * ..., 'top') approach, this ONLY fires when a traffic page actually exists — it
     * never intercepts pages, posts, categories, or any other content.
     *
     * @param array<string,mixed> $query_vars Parsed query variables from WP_Rewrite.
     * @return array<string,mixed>
     */
    public static function resolve_rootless_traffic_page( array $query_vars ): array {
        // Already targeting a specific post type — don't interfere.
        if ( isset( $query_vars['post_type'] ) ) {
            return $query_vars;
        }

        // Extract the slug from single-segment URL resolution.
        // 'pagename' is set by WordPress's page catch-all rule for hierarchical URLs.
        // 'name' is set by the post catch-all rule (e.g., with /%postname%/ permalinks).
        $slug = '';
        if ( ! empty( $query_vars['pagename'] ) && strpos( $query_vars['pagename'], '/' ) === false ) {
            $slug = $query_vars['pagename'];
        } elseif ( ! empty( $query_vars['name'] ) ) {
            $slug = $query_vars['name'];
        }

        $slug = sanitize_title( $slug );
        if ( $slug === '' ) {
            return $query_vars;
        }

        // Check if a published traffic page exists with this exact slug.
        global $wpdb;
        $found = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND post_status = 'publish' LIMIT 1",
            $slug,
            self::CPT
        ) );

        if ( $found <= 0 ) {
            return $query_vars;
        }

        // Traffic page exists — rewrite the query to target it.
        return [
            'post_type' => self::CPT,
            'name'      => $slug,
        ];
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

            $page_type = sanitize_key((string) ($row['page_type'] ?? 'category'));
            $entity_combo = (string) ($row['entity_combo'] ?? '');

            $post_id = wp_insert_post([
                'post_status' => 'draft',
                'post_type' => self::CPT,
                'post_name' => $slug,
                'post_title' => sprintf('Best %s', ucwords($keyword)),
                'post_content' => $this->build_page_content($keyword, $page_type, $entity_combo),
            ], true);

            if (is_wp_error($post_id)) {
                $errors[] = sprintf('%s: %s', $keyword, $post_id->get_error_message());
                continue;
            }

            update_post_meta($post_id, '_tmwseo_target_keyword', $keyword);
            update_post_meta($post_id, '_tmwseo_traffic_page_slug', $slug);
            update_post_meta($post_id, '_tmwseo_traffic_page_schema', wp_json_encode($this->build_item_list_schema($post_id), JSON_UNESCAPED_SLASHES));
            update_post_meta($post_id, '_tmwseo_traffic_page_type', $page_type);
            update_post_meta($post_id, '_tmwseo_entity_combo', $entity_combo);
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
        $rows = KeywordDatabase::get_generation_candidates_by_entity_combinations(max(1, $limit));

        $selected = [];
        $type_limits = [
            'model' => max(1, (int) floor($limit / 3)),
            'category' => max(1, (int) floor($limit / 3)),
            'model_category' => max(1, (int) floor($limit / 3)),
        ];
        $type_counts = ['model' => 0, 'category' => 0, 'model_category' => 0];

        foreach ($rows as $row) {
            $slug = sanitize_title((string) ($row['keyword'] ?? ''));
            if ($slug === '' || $this->slug_exists($slug)) {
                continue;
            }

            $page_type = $this->resolve_page_type($row);
            if (($type_counts[$page_type] ?? 0) >= ($type_limits[$page_type] ?? 0)) {
                continue;
            }

            $row['page_type'] = $page_type;
            $selected[] = $row;
            $type_counts[$page_type]++;

            if (count($selected) >= $limit) {
                break;
            }
        }

        return $selected;
    }

    /** @param array<string,mixed> $row */
    private function resolve_page_type(array $row): string {
        $has_model = (int) ($row['model_count'] ?? 0) > 0;
        $has_category = (int) ($row['category_count'] ?? 0) > 0;

        if ($has_model && $has_category) {
            return 'model_category';
        }

        if ($has_model) {
            return 'model';
        }

        if ($has_category) {
            return 'category';
        }

        return 'category';
    }

    private function slug_exists(string $slug): bool {
        if ($slug === '') {
            return true;
        }

        $existing = get_page_by_path($slug, OBJECT, [self::CPT, 'page', 'post']);
        return $existing instanceof \WP_Post;
    }

    private function build_page_content(string $keyword, string $page_type, string $entity_combo): string {
        $h1 = sprintf('Best %s', ucwords($keyword));
        $intro = $this->generate_intro($keyword);
        $entities = $this->parse_entity_combo($entity_combo);

        $models = $this->render_model_grid('Model Grid', [
            'posts_per_page' => 8,
            'orderby' => 'comment_count',
            'order' => 'DESC',
        ]);

        $categories = $this->render_tax_links('Related Categories', 'category', 6);
        $related = $this->render_related_searches($keyword, 8);
        $internal_links = $this->render_internal_links($keyword, $page_type, $entities);

        return implode("\n\n", [
            '<h1>' . esc_html($h1) . '</h1>',
            '<p>' . esc_html($intro) . '</p>',
            $models,
            $categories,
            $related,
            $internal_links,
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
        $keyword_table = KeywordDatabase::table_name();
        $like = '%' . $wpdb->esc_like(substr($keyword, 0, 12)) . '%';

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT keyword FROM {$keyword_table} WHERE keyword LIKE %s AND keyword != %s ORDER BY search_volume DESC LIMIT %d",
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

    /**
     * @param array{model:array<int,string>,category:array<int,string>} $entities
     */
    private function render_internal_links(string $keyword, string $page_type, array $entities): string {
        $link_limit = $this->link_limit();

        if ($page_type === 'model') {
            $category_links = $this->select_related_pages($keyword, $entities, 'category', $link_limit);
            $model_links = $this->select_related_pages($keyword, $entities, 'model', $link_limit);

            return '<h2>Related Categories</h2><ul>' . $this->list_entity_links($category_links, home_url('/category/')) . '</ul>'
                . '<h2>Related Models</h2><ul>' . $this->list_entity_links($model_links, home_url('/models/')) . '</ul>';
        }

        if ($page_type === 'category') {
            $model_links = $this->select_related_pages($keyword, $entities, 'model', $link_limit);

            return '<h2>Top Models in Category</h2><ul>' . $this->list_entity_links($model_links, home_url('/models/')) . '</ul>';
        }

        if ($page_type === 'model_category') {
            $model_page_links = $this->select_related_pages($keyword, $entities, 'model', 1);
            $category_page_links = $this->select_related_pages($keyword, $entities, 'category', 1);

            return '<h2>Link to model page</h2><ul>' . $this->list_entity_links($model_page_links, home_url('/models/')) . '</ul>'
                . '<h2>Link to category page</h2><ul>' . $this->list_entity_links($category_page_links, home_url('/category/')) . '</ul>';
        }

        return '';
    }

    private function link_limit(): int {
        $limit = (int) apply_filters('tmwseo_internal_link_limit', 8);
        return max(self::MIN_LINKS, min(self::MAX_LINKS, $limit));
    }

    /**
     * @param array{model:array<int,string>,category:array<int,string>} $source_entities
     * @return array<int,array{keyword:string,url:string,score:int}>
     */
    private function select_related_pages(string $keyword, array $source_entities, string $target_type, int $limit): array {
        $rows = $this->get_mapped_entity_pages();
        $scored = [];

        foreach ($rows as $row) {
            $row_keyword = (string) ($row['keyword'] ?? '');
            $url = (string) ($row['mapped_url'] ?? '');
            if ($row_keyword === '' || $url === '' || $row_keyword === $keyword) {
                continue;
            }

            $entities = $this->parse_entity_combo((string) ($row['entity_combo'] ?? ''));
            if (empty($entities[$target_type])) {
                continue;
            }

            $score = 0;
            $score += count(array_intersect($source_entities['model'], $entities['model'])) * 3;
            $score += count(array_intersect($source_entities['category'], $entities['category'])) * 2;
            if ($score <= 0 && (!empty($source_entities['model']) || !empty($source_entities['category']))) {
                continue;
            }

            $scored[] = [
                'keyword' => $row_keyword,
                'url' => esc_url_raw($url),
                'score' => $score,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            if ((int) $a['score'] === (int) $b['score']) {
                return strcmp((string) $a['keyword'], (string) $b['keyword']);
            }

            return ((int) $b['score']) <=> ((int) $a['score']);
        });

        return array_slice($scored, 0, max(1, $limit));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function get_mapped_entity_pages(): array {
        global $wpdb;

        $table = KeywordDatabase::table_name();
        $entity_table = KeywordDatabase::entity_table_name();
        $entity_keyword_table = KeywordDatabase::entity_keyword_table_name();

        return (array) $wpdb->get_results(
            "SELECT k.keyword, k.mapped_url,
                    GROUP_CONCAT(DISTINCT CONCAT(e.entity_type, ':', e.entity_name) ORDER BY e.entity_type, e.entity_name SEPARATOR '|') AS entity_combo
             FROM {$table} k
             LEFT JOIN {$entity_keyword_table} ek ON ek.keyword_id = k.id
             LEFT JOIN {$entity_table} e ON e.entity_id = ek.entity_id
             WHERE k.mapped_url IS NOT NULL AND k.mapped_url != ''
             GROUP BY k.id
             ORDER BY k.ranking_probability DESC, k.search_volume DESC
             LIMIT 300",
            ARRAY_A
        );
    }

    /**
     * @return array{model:array<int,string>,category:array<int,string>}
     */
    private function parse_entity_combo(string $entity_combo): array {
        $entities = ['model' => [], 'category' => []];
        foreach (explode('|', $entity_combo) as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '' || strpos($entry, ':') === false) {
                continue;
            }

            [$type, $name] = array_map('trim', explode(':', $entry, 2));
            $type = sanitize_key($type);
            $name = strtolower($name);
            if (!isset($entities[$type]) || $name === '') {
                continue;
            }

            $entities[$type][] = $name;
        }

        $entities['model'] = array_values(array_unique($entities['model']));
        $entities['category'] = array_values(array_unique($entities['category']));

        return $entities;
    }

    /**
     * @param array<int,array{keyword:string,url:string,score:int}> $links
     */
    private function list_entity_links(array $links, string $fallback): string {
        $items = '';
        foreach ($links as $link) {
            $items .= '<li><a href="' . esc_url((string) ($link['url'] ?? '')) . '">' . esc_html(ucwords((string) ($link['keyword'] ?? ''))) . '</a></li>';
        }

        if ($items === '') {
            $items = '<li><a href="' . esc_url($fallback) . '">' . esc_html($fallback) . '</a></li>';
        }

        return $items;
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
