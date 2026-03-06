<?php

use TMWSEO\Engine\Services\OpenAI;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class TMW_Topic_Page_Generator {
    private const CONTENT_MARKER = '<!-- tmw-topic-authority -->';

    /**
     * @param array{topic:string,slug:string,parent:string} $topic_row
     */
    public function upsert_topic_page(\WP_Post $model, array $topic_row): int {
        $topic = (string) ($topic_row['topic'] ?? '');
        $slug = (string) ($topic_row['slug'] ?? '');

        if ($topic === '' || $slug === '') {
            return 0;
        }

        if (Settings::is_human_approval_required()) {
            // Safety change: topic pages are suggestion-first; no automatic creation/update.
            Logs::info('topic-authority', '[TMW-TOPIC] Human approval required, skipping automatic topic page write', [
                'model_id' => (int) $model->ID,
                'topic' => $topic,
            ]);
            return 0;
        }

        $existing = get_page_by_path($slug, OBJECT, 'page');
        $page_id = $existing instanceof \WP_Post ? (int) $existing->ID : 0;

        $content = $this->build_topic_content($model, $topic);
        $args = [
            'post_type' => 'page',
            'post_status' => 'draft',
            'post_title' => ucwords($topic),
            'post_name' => $slug,
            'post_content' => $content,
        ];

        if ($page_id > 0) {
            $args['ID'] = $page_id;
            $result = wp_update_post($args, true);
            if (is_wp_error($result)) {
                Logs::error('topic-authority', 'Failed to update topic page', ['model_id' => $model->ID, 'slug' => $slug]);
                error_log('[TMW-TOPIC] Failed to update topic page: ' . $slug);
                return 0;
            }
        } else {
            $result = wp_insert_post($args, true);
            if (is_wp_error($result)) {
                Logs::error('topic-authority', 'Failed to create topic page', ['model_id' => $model->ID, 'slug' => $slug]);
                error_log('[TMW-TOPIC] Failed to create topic page: ' . $slug);
                return 0;
            }
            $page_id = (int) $result;
        }

        update_post_meta($page_id, '_tmw_topic_parent_model_id', (int) $model->ID);
        update_post_meta($page_id, '_tmw_topic_keyword', $topic);

        // Safety hardening: all topic authority pages are always noindex until a
        // human explicitly reviews and changes indexing settings.
        update_post_meta($page_id, 'rank_math_robots', ['noindex']);

        return (int) $page_id;
    }

    private function build_topic_content(\WP_Post $model, string $topic): string {
        $model_name = trim((string) get_the_title($model));
        $main_link = get_permalink($model);

        $tag_links = $this->build_term_links($model->ID, 'post_tag', 5);
        $category_links = $this->build_term_links($model->ID, 'category', 5);

        $ai_content = $this->generate_ai_copy($model_name, $topic);
        if ($ai_content === '') {
            $ai_content = sprintf(
                '<p>%s features live performances, private sessions, and updated highlights around %s. Explore this topic page for current information and discover similar profiles.</p>',
                esc_html($model_name),
                esc_html($topic)
            );
        }

        $parts = [];
        $parts[] = self::CONTENT_MARKER;
        $parts[] = '<h2>' . esc_html(ucwords($topic)) . '</h2>';
        $parts[] = wp_kses_post($ai_content);

        if (!empty($main_link)) {
            $parts[] = '<p><strong>Main model page:</strong> <a href="' . esc_url($main_link) . '">' . esc_html($model_name) . '</a></p>';
        }

        if (!empty($tag_links)) {
            $parts[] = '<h3>Related Tags</h3><ul>' . $tag_links . '</ul>';
        }

        if (!empty($category_links)) {
            $parts[] = '<h3>Related Categories</h3><ul>' . $category_links . '</ul>';
        }

        return implode("\n", $parts);
    }

    private function generate_ai_copy(string $model_name, string $topic): string {
        if (!OpenAI::is_configured()) {
            return '';
        }

        $model = Settings::openai_model_for_bulk();
        $messages = [
            [
                'role' => 'system',
                'content' => 'Write unique SEO-friendly HTML for a webcam model topic page. Keep copy non-explicit, helpful, and concise.',
            ],
            [
                'role' => 'user',
                'content' => sprintf(
                    'Create 2 short paragraphs (120-170 words total) for topic "%s" about model "%s". Return valid HTML only using <p> tags.',
                    $topic,
                    $model_name
                ),
            ],
        ];

        $response = OpenAI::chat($messages, $model, [
            'temperature' => 0.8,
            'max_tokens' => 300,
        ]);

        if (empty($response['ok'])) {
            Logs::warn('topic-authority', 'AI content generation failed', ['topic' => $topic, 'error' => $response['error'] ?? 'unknown']);
            error_log('[TMW-TOPIC] AI generation failed for topic: ' . $topic);
            return '';
        }

        $content = (string) ($response['data']['choices'][0]['message']['content'] ?? '');
        return trim($content);
    }

    private function build_term_links(int $post_id, string $taxonomy, int $limit): string {
        $terms = get_the_terms($post_id, $taxonomy);
        if (!is_array($terms) || empty($terms)) {
            return '';
        }

        $items = [];
        foreach (array_slice($terms, 0, $limit) as $term) {
            if (!($term instanceof \WP_Term)) {
                continue;
            }

            $link = get_term_link($term);
            if (is_wp_error($link)) {
                continue;
            }

            $items[] = '<li><a href="' . esc_url($link) . '">' . esc_html((string) $term->name) . '</a></li>';
        }

        return implode('', $items);
    }
}
