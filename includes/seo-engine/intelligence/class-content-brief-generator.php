<?php
/**
 * ContentBriefGenerator — generates AI-powered content briefs.
 *
 * v4.2: Now calls AI via AIRouter with SERP weakness signals + competitor context.
 * Falls back to rule-based generation if AI is unavailable.
 *
 * @since 4.2.0
 */
namespace TMWSEO\Engine\Intelligence;

use TMWSEO\Engine\AI\AIRouter;
use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class ContentBriefGenerator {

    public function generate(array $input): array {
        $primary_keyword = sanitize_text_field((string) ($input['primary_keyword'] ?? ''));
        $cluster         = sanitize_text_field((string) ($input['keyword_cluster'] ?? 'General'));
        $intent          = sanitize_text_field((string) ($input['search_intent'] ?? 'Informational'));
        $brief_type      = sanitize_text_field((string) ($input['brief_type'] ?? ''));

        // Enrich with SERP data if available
        $serp_context = $this->build_serp_context($primary_keyword, $input);

        // Try AI generation first
        $ai_brief = $this->ai_generate($primary_keyword, $cluster, $intent, $serp_context, $input);

        if ($ai_brief !== null) {
            $brief = $ai_brief;
        } else {
            // Rule-based fallback
            $brief = $this->rule_based_generate($primary_keyword, $cluster, $intent, $brief_type, $input);
        }

        $brief['primary_keyword']    = $primary_keyword;
        $brief['intent_classification'] = $intent;
        $brief['human_approval_notice'] = 'Human approval required before any publishing or live content changes.';
        $brief['generated_by']       = $ai_brief !== null ? 'ai' : 'rules';

        $brief_id       = $this->persist($primary_keyword, $cluster, $brief);
        $brief['id']    = $brief_id;

        Logs::info('intelligence', '[TMW-BRIEF] Content brief generated', [
            'brief_id'     => $brief_id,
            'keyword'      => $primary_keyword,
            'cluster'      => $cluster,
            'generated_by' => $brief['generated_by'],
        ]);

        return $brief;
    }

    // ── AI generation ──────────────────────────────────────────────────────

    private function ai_generate(string $keyword, string $cluster, string $intent, array $serp_context, array $input): ?array {
        if (AIRouter::is_over_budget()) return null;
        if ($keyword === '') return null;

        $secondary_kws  = implode(', ', array_slice((array) ($input['secondary_keywords'] ?? []), 0, 8));
        $related_qs     = implode("\n- ", array_slice((array) ($input['related_questions'] ?? []), 0, 6));
        $serp_weakness  = $serp_context['weakness_summary'] ?? 'No SERP weakness data available.';
        $competitor_info = $serp_context['competitor_summary'] ?? '';

        $system = "You are an expert SEO content strategist specialising in adult cam model directory websites. Your briefs are SFW (safe for work) — no explicit content. Always include a note that content must be approved by a human editor before publishing.";

        $user = <<<PROMPT
Create a detailed SEO content brief for the keyword: "{$keyword}"

Cluster: {$cluster}
Intent: {$intent}
Secondary keywords: {$secondary_kws}
Related questions:
- {$related_qs}

SERP weakness analysis: {$serp_weakness}
{$competitor_info}

Respond ONLY with a JSON object with these keys:
- recommended_article_type (string)
- recommended_title_options (array of 3 title strings)
- recommended_h1 (string)
- suggested_outline (array of section headings as strings)
- questions_to_answer (array of 5 questions)
- semantic_terms (array of 10 related terms)
- recommended_cta_type (string)
- suggested_word_count_range (string like "1200-1800 words")
- serp_weakness_notes (string, 2-3 sentences)
- content_angle (string: unique angle to beat competitors)
PROMPT;

        $res = AIRouter::chat_json(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            ['quality' => true, 'max_tokens' => 1000]
        );

        if (!$res['ok'] || empty($res['json'])) {
            Logs::warn('content_brief', 'AI generation failed', ['error' => $res['error'] ?? 'unknown']);
            return null;
        }

        $json = $res['json'];
        return [
            'recommended_article_type'   => sanitize_text_field((string) ($json['recommended_article_type'] ?? '')),
            'recommended_title_options'  => array_map('sanitize_text_field', (array) ($json['recommended_title_options'] ?? [])),
            'recommended_h1'             => sanitize_text_field((string) ($json['recommended_h1'] ?? '')),
            'suggested_outline'          => array_map('sanitize_text_field', (array) ($json['suggested_outline'] ?? [])),
            'questions_to_answer'        => array_map('sanitize_text_field', (array) ($json['questions_to_answer'] ?? [])),
            'semantic_terms'             => array_map('sanitize_text_field', (array) ($json['semantic_terms'] ?? [])),
            'recommended_cta_type'       => sanitize_text_field((string) ($json['recommended_cta_type'] ?? '')),
            'suggested_word_count_range' => sanitize_text_field((string) ($json['suggested_word_count_range'] ?? '')),
            'serp_weakness_notes'        => sanitize_textarea_field((string) ($json['serp_weakness_notes'] ?? '')),
            'content_angle'              => sanitize_text_field((string) ($json['content_angle'] ?? '')),
            'suggested_internal_links'   => array_map('sanitize_text_field', (array) ($input['internal_link_opportunities'] ?? [])),
        ];
    }

    // ── SERP context builder ───────────────────────────────────────────────

    private function build_serp_context(string $keyword, array $input): array {
        // Use pre-provided SERP weakness notes if available
        if (!empty($input['serp_weakness_notes'])) {
            return ['weakness_summary' => sanitize_textarea_field($input['serp_weakness_notes'])];
        }

        if (!DataForSEO::is_configured() || $keyword === '') return [];

        // Pull most recent SERP weakness from DB
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_serp_analysis';
        if ($wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table) return [];

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT serp_weakness_score, reason, signals_json FROM {$table} WHERE keyword = %s ORDER BY created_at DESC LIMIT 1", strtolower($keyword))
        );

        if (!$row) {
            // Run SERP weakness analysis on the fly
            $engine = new SerpWeaknessEngine();
            $result = $engine->evaluate($keyword);
            return [
                'weakness_summary'    => $result['reason'] ?? '',
                'weakness_score'      => $result['serp_weakness_score'] ?? 5,
                'competitor_summary'  => "SERP weakness score: {$result['serp_weakness_score']}/10. " . ($result['reason'] ?? ''),
            ];
        }

        return [
            'weakness_summary'   => $row->reason,
            'weakness_score'     => (float) $row->serp_weakness_score,
            'competitor_summary' => "SERP weakness score: {$row->serp_weakness_score}/10. {$row->reason}",
        ];
    }

    // ── Rule-based fallback ────────────────────────────────────────────────

    private function rule_based_generate(string $keyword, string $cluster, string $intent, string $brief_type, array $input): array {
        return [
            'recommended_article_type'   => $brief_type ?: (stripos($intent, 'commercial') !== false ? 'comparison article brief' : 'informational guide brief'),
            'recommended_title_options'  => [
                sprintf('%s: Complete Guide', ucwords($keyword)),
                sprintf('%s (%s)', ucwords($keyword), $intent),
                sprintf('Best Practices for %s', ucwords($keyword)),
            ],
            'recommended_h1'             => ucwords($keyword),
            'suggested_outline'          => [
                sprintf('What %s means', $cluster),
                'Key factors and decision criteria',
                sprintf('Practical implementation steps for %s intent', strtolower($intent)),
                'Common mistakes and how to avoid them',
                'FAQ',
            ],
            'questions_to_answer'        => array_values(array_slice(array_map('sanitize_text_field', (array) ($input['related_questions'] ?? [])), 0, 8)),
            'semantic_terms'             => array_values(array_slice(array_map('sanitize_text_field', (array) ($input['semantic_terms'] ?? [])), 0, 12)),
            'suggested_internal_links'   => array_values(array_slice(array_map('sanitize_text_field', (array) ($input['internal_link_opportunities'] ?? [])), 0, 8)),
            'recommended_cta_type'       => stripos($intent, 'commercial') !== false ? 'Product comparison CTA' : 'Newsletter/signup CTA',
            'suggested_word_count_range' => stripos($intent, 'commercial') !== false ? '1400-2200 words' : '1200-2000 words',
            'serp_weakness_notes'        => sanitize_textarea_field((string) ($input['serp_weakness_notes'] ?? 'Look for outdated or thin competitor pages and answer missing questions with stronger structure.')),
            'content_angle'              => 'Rule-based — upgrade to AI for better angles.',
        ];
    }

    private function persist(string $keyword, string $cluster, array $brief): int {
        global $wpdb;
        $ok = $wpdb->insert(
            IntelligenceStorage::table_content_briefs(),
            [
                'primary_keyword' => sanitize_text_field($keyword),
                'cluster_key'     => sanitize_text_field($cluster),
                'brief_type'      => sanitize_text_field((string) ($brief['recommended_article_type'] ?? '')),
                'brief_json'      => wp_json_encode($brief),
                'created_at'      => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );
        return $ok ? (int) $wpdb->insert_id : 0;
    }
}
