<?php
namespace TMWSEO\Engine\Intelligence;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class ContentBriefGenerator {
    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function generate(array $input): array {
        $primary_keyword = sanitize_text_field((string) ($input['primary_keyword'] ?? ''));
        $cluster = sanitize_text_field((string) ($input['keyword_cluster'] ?? 'General'));
        $intent = sanitize_text_field((string) ($input['search_intent'] ?? 'Informational'));
        $brief_type = sanitize_text_field((string) ($input['brief_type'] ?? 'informational guide brief'));

        $brief = [
            'primary_keyword' => $primary_keyword,
            'secondary_keywords' => array_values(array_slice(array_map('sanitize_text_field', (array) ($input['secondary_keywords'] ?? [])), 0, 8)),
            'intent_classification' => $intent,
            'recommended_article_type' => $this->recommend_article_type($intent, $brief_type),
            'recommended_title_options' => $this->title_options($primary_keyword, $intent),
            'recommended_h1' => ucwords($primary_keyword),
            'suggested_outline' => $this->default_outline($intent, $cluster),
            'questions_to_answer' => array_values(array_slice(array_map('sanitize_text_field', (array) ($input['related_questions'] ?? [])), 0, 8)),
            'semantic_terms' => array_values(array_slice(array_map('sanitize_text_field', (array) ($input['semantic_terms'] ?? [])), 0, 12)),
            'suggested_internal_links' => array_values(array_slice(array_map('sanitize_text_field', (array) ($input['internal_link_opportunities'] ?? [])), 0, 8)),
            'recommended_cta_type' => $this->cta_for_intent($intent),
            'suggested_word_count_range' => $this->word_count_range($intent),
            'serp_weakness_notes' => sanitize_textarea_field((string) ($input['serp_weakness_notes'] ?? 'Look for outdated or thin competitor pages and answer missing questions with stronger structure.')),
            'human_approval_notice' => 'Human approval required before any publishing or live content changes.',
        ];

        $brief_id = $this->persist($primary_keyword, $cluster, $brief);
        $brief['id'] = $brief_id;

        Logs::info('intelligence', '[TMW-BRIEF] Content brief generated', [
            'brief_id' => $brief_id,
            'keyword' => $primary_keyword,
            'cluster' => $cluster,
        ]);

        return $brief;
    }

    private function recommend_article_type(string $intent, string $brief_type): string {
        if ($brief_type !== '') {
            return $brief_type;
        }

        if (stripos($intent, 'commercial') !== false) {
            return 'comparison article brief';
        }

        return 'informational guide brief';
    }

    /** @return string[] */
    private function title_options(string $keyword, string $intent): array {
        return [
            sprintf('%s: Complete Guide', ucwords($keyword)),
            sprintf('%s (%s)', ucwords($keyword), $intent),
            sprintf('Best Practices for %s', ucwords($keyword)),
        ];
    }

    /** @return string[] */
    private function default_outline(string $intent, string $cluster): array {
        return [
            sprintf('What %s means', $cluster),
            'Key factors and decision criteria',
            sprintf('Practical implementation steps for %s intent', strtolower($intent)),
            'Common mistakes and how to avoid them',
            'FAQ',
        ];
    }

    private function cta_for_intent(string $intent): string {
        return stripos($intent, 'commercial') !== false ? 'Product comparison CTA' : 'Newsletter/signup CTA';
    }

    private function word_count_range(string $intent): string {
        return stripos($intent, 'commercial') !== false ? '1400-2200 words' : '1200-2000 words';
    }

    /**
     * @param array<string,mixed> $brief
     */
    private function persist(string $keyword, string $cluster, array $brief): int {
        global $wpdb;

        $ok = $wpdb->insert(
            IntelligenceStorage::table_content_briefs(),
            [
                'primary_keyword' => sanitize_text_field($keyword),
                'cluster_key' => sanitize_text_field($cluster),
                'brief_type' => sanitize_text_field((string) ($brief['recommended_article_type'] ?? 'informational guide brief')),
                'brief_json' => wp_json_encode($brief),
                'status' => 'ready',
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        return $ok ? (int) $wpdb->insert_id : 0;
    }
}
