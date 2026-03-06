<?php

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class TMW_Topic_Engine {
    private TMW_Topic_Map $topic_map;
    private TMW_Topic_Page_Generator $page_generator;

    public function __construct(?TMW_Topic_Map $topic_map = null, ?TMW_Topic_Page_Generator $page_generator = null) {
        $this->topic_map = $topic_map ?: new TMW_Topic_Map();
        $this->page_generator = $page_generator ?: new TMW_Topic_Page_Generator();
    }

    public static function init(): void {
        $engine = new self();
        add_action('save_post_model', [$engine, 'maybe_generate_cluster'], 20, 3);
    }

    public function maybe_generate_cluster(int $post_id, \WP_Post $post, bool $update): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if ($post->post_status !== 'publish') {
            return;
        }

        $cluster = $this->topic_map->get_cluster($post_id);
        if (empty($cluster)) {
            $cluster = $this->topic_map->build_cluster_for_model($post);
            $this->topic_map->save_cluster($post_id, $cluster);
        }

        if (empty($cluster)) {
            return;
        }

        $links = [];
        foreach ($cluster as $topic_row) {
            $page_id = $this->page_generator->upsert_topic_page($post, $topic_row);
            if ($page_id <= 0) {
                continue;
            }

            $url = get_permalink($page_id);
            if (is_string($url) && $url !== '') {
                $links[] = [
                    'topic' => (string) $topic_row['topic'],
                    'url' => $url,
                ];
            }
        }

        if (!empty($links)) {
            $this->append_cluster_links_to_model($post, $links);
            Logs::info('topic-authority', 'Topic cluster generated', ['model_id' => $post_id, 'topics' => count($links)]);
            error_log('[TMW-TOPIC] Topic cluster generated for model_id=' . $post_id . ' with topics=' . count($links));
        }
    }

    /**
     * @param array<int,array{topic:string,url:string}> $links
     */
    private function append_cluster_links_to_model(\WP_Post $post, array $links): void {
        $content = (string) $post->post_content;
        $marker_start = '<!-- tmw-topic-cluster:start -->';
        $marker_end = '<!-- tmw-topic-cluster:end -->';

        $items = [];
        foreach ($links as $link) {
            $items[] = '<li><a href="' . esc_url((string) $link['url']) . '">' . esc_html(ucwords((string) $link['topic'])) . '</a></li>';
        }

        $cluster_html = $marker_start
            . '<section class="tmw-topic-cluster"><h3>Explore More About ' . esc_html(get_the_title($post)) . '</h3><ul>'
            . implode('', $items)
            . '</ul></section>'
            . $marker_end;

        $updated_content = $content;
        if (strpos($content, $marker_start) !== false && strpos($content, $marker_end) !== false) {
            $pattern = '/' . preg_quote($marker_start, '/') . '.*?' . preg_quote($marker_end, '/') . '/s';
            $updated_content = (string) preg_replace($pattern, $cluster_html, $content);
        } else {
            $updated_content .= "\n\n" . $cluster_html;
        }

        if ($updated_content === $content) {
            return;
        }

        remove_action('save_post_model', [$this, 'maybe_generate_cluster'], 20);
        wp_update_post([
            'ID' => (int) $post->ID,
            'post_content' => $updated_content,
        ]);
        add_action('save_post_model', [$this, 'maybe_generate_cluster'], 20, 3);
    }
}
