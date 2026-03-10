<?php
namespace TMWSEO\Engine\Intelligence;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class RankingProbabilityEngine {
    /**
     * @param array<string,float|int> $inputs
     * @return array<string,mixed>
     */
    public function calculate(string $keyword, array $inputs): array {
        $started = microtime(true);

        $intent_match = $this->clamp((float) ($inputs['intent_match'] ?? 0));
        $topical_authority = $this->clamp((float) ($inputs['topical_authority'] ?? 0));
        $cluster_coverage = $this->clamp((float) ($inputs['cluster_coverage'] ?? 0));
        $content_depth = $this->clamp((float) ($inputs['content_depth'] ?? 0));
        $internal_linking_strength = $this->clamp((float) ($inputs['internal_linking_strength'] ?? 0));
        $competitor_weakness = $this->clamp((float) ($inputs['competitor_weakness'] ?? 0));
        $keyword_difficulty = $this->clamp((float) ($inputs['keyword_difficulty'] ?? 0));
        $page_type_fit = $this->clamp((float) ($inputs['page_type_fit'] ?? 0));

        $raw =
            ($intent_match * 0.25) +
            ($competitor_weakness * 0.20) +
            ($topical_authority * 0.15) +
            ($content_depth * 0.10) +
            ($internal_linking_strength * 0.10) +
            ($page_type_fit * 0.05) +
            ($cluster_coverage * 0.10) -
            ($keyword_difficulty * 0.15);

        $score = round($this->clamp($raw), 2);
        $tier = $this->tier($score);

        $result = [
            'keyword' => $keyword,
            'ranking_probability' => $score,
            'ranking_tier' => $tier,
        ];

        $this->persist($keyword, $inputs, $result);

        Logs::info('intelligence', '[TMW-RANK] Ranking probability calculated', [
            'keyword' => $keyword,
            'score' => $score,
            'tier' => $tier,
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
        ]);

        return $result;
    }

    public function tier(float $score): string {
        if ($score < 35) {
            return 'Low';
        }
        if ($score < 60) {
            return 'Medium';
        }
        if ($score < 80) {
            return 'High';
        }
        return 'Very High';
    }

    private function clamp(float $value): float {
        return max(0, min(100, $value));
    }

    /**
     * @param array<string,float|int> $inputs
     * @param array<string,mixed> $result
     */
    private function persist(string $keyword, array $inputs, array $result): void {
        global $wpdb;

        $wpdb->insert(
            IntelligenceStorage::table_ranking_probability(),
            [
                'keyword' => sanitize_text_field($keyword),
                'inputs_json' => wp_json_encode($inputs),
                'ranking_probability' => (float) $result['ranking_probability'],
                'ranking_tier' => sanitize_text_field((string) $result['ranking_tier']),
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%f', '%s', '%s']
        );
    }
}
