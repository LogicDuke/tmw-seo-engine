<?php
namespace TMWSEO\Engine\Intelligence;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Suggestions\SuggestionEngine;

if (!defined('ABSPATH')) { exit; }

class CompetitorGapEngine {
    private SuggestionEngine $suggestion_engine;

    public function __construct(?SuggestionEngine $suggestion_engine = null) {
        $this->suggestion_engine = $suggestion_engine ?: new SuggestionEngine();
    }

    /**
     * @param string[] $site_keywords
     * @param array<int,array<string,mixed>> $competitor_keywords
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    public function analyze(array $site_keywords, array $competitor_keywords, array $context = []): array {
        $started = microtime(true);

        $site_lookup = [];
        foreach ($site_keywords as $keyword) {
            $site_lookup[sanitize_title((string) $keyword)] = true;
        }

        $gaps = [];
        foreach ($competitor_keywords as $row) {
            $keyword = sanitize_text_field((string) ($row['keyword'] ?? ''));
            if ($keyword === '') {
                continue;
            }

            $key = sanitize_title($keyword);
            if (isset($site_lookup[$key])) {
                continue;
            }

            $gaps[] = [
                'keyword' => $keyword,
                'search_volume' => max(0, (int) ($row['search_volume'] ?? 0)),
                'difficulty' => max(0.0, min(100.0, (float) ($row['difficulty'] ?? 0))),
                'cluster' => sanitize_text_field((string) ($row['cluster'] ?? 'general')),
                'competitor_weakness' => max(0.0, min(100.0, (float) ($row['competitor_weakness'] ?? 0))),
                'partial_authority' => max(0.0, min(100.0, (float) ($row['partial_authority'] ?? 0))),
            ];
        }

        usort($gaps, static function (array $a, array $b): int {
            return ($b['search_volume'] <=> $a['search_volume']);
        });

        $suggestions = [];
        $max = min(10, count($gaps));
        for ($i = 0; $i < $max; $i++) {
            $gap = $gaps[$i];
            $priority = $this->score_gap($gap);
            $suggestion = [
                'type' => 'competitor_gap',
                'title' => 'Competitor gap opportunity detected',
                'description' => sprintf(
                    "Competitors rank for '%s' but your site does not currently target it directly.",
                    $gap['keyword']
                ),
                'source_engine' => 'competitor_gap_ai',
                'priority_score' => $priority,
                'estimated_traffic' => (int) $gap['search_volume'],
                'difficulty' => (float) $gap['difficulty'],
                'suggested_action' => 'Generate a content brief for one of these opportunities. Human approval required before any publishing or live content changes.',
                'status' => 'new',
            ];

            $suggestion_id = $this->suggestion_engine->createSuggestion($suggestion);
            if ($suggestion_id > 0) {
                $suggestion['id'] = $suggestion_id;
                $suggestions[] = $suggestion;
            }
        }

        Logs::info('intelligence', '[TMW-GAP] Competitor gap analysis completed', [
            'gaps_detected' => count($gaps),
            'suggestions_created' => count($suggestions),
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
            'domains' => $context['competitor_domains'] ?? IntelligenceStorage::get_competitor_domains(),
        ]);

        return $suggestions;
    }

    /**
     * @param array<string,mixed> $gap
     */
    private function score_gap(array $gap): float {
        $volume_score = min(100, ((int) $gap['search_volume']) / 50);
        $difficulty_score = 100 - (float) $gap['difficulty'];
        $weakness = (float) $gap['competitor_weakness'];
        $authority = (float) $gap['partial_authority'];

        return round(max(0, min(100,
            ($volume_score * 0.35) +
            ($difficulty_score * 0.20) +
            ($weakness * 0.25) +
            ($authority * 0.20)
        )), 2);
    }
}
