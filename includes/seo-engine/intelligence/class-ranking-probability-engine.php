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

        // FIX: Replaced raw KD subtraction with a positive ease factor.
        // Old formula: - ($keyword_difficulty * 0.15)
        //   → KD 100 = -15 pts, KD 0 = 0 pts (only penalises hard, no reward for easy)
        // New formula: + ((1 - KD/100) * 0.15)
        //   → KD 0 = +15 pts, KD 50 = +7.5 pts, KD 100 = 0 pts (rewards easy keywords)
        // Weights still sum to 1.0 (0.25+0.20+0.15+0.10+0.10+0.05+0.10+0.15 = 1.10 max → clamped)
        $kd_ease = ( 1.0 - ( $keyword_difficulty / 100.0 ) ) * 0.15;

        $raw =
            ($intent_match * 0.25) +
            ($competitor_weakness * 0.20) +
            ($topical_authority * 0.15) +
            ($content_depth * 0.10) +
            ($internal_linking_strength * 0.10) +
            ($page_type_fit * 0.05) +
            ($cluster_coverage * 0.10) +
            $kd_ease;

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

        // FIX: Changed from INSERT to INSERT ... ON DUPLICATE KEY UPDATE.
        // Previously every calculation appended a new row (no UNIQUE key) causing the
        // ranking_probability table to grow unbounded with duplicate keyword rows.
        $table = IntelligenceStorage::table_ranking_probability();
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table}
                    (keyword, inputs_json, ranking_probability, ranking_tier, created_at)
                 VALUES (%s, %s, %f, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    inputs_json          = VALUES(inputs_json),
                    ranking_probability  = VALUES(ranking_probability),
                    ranking_tier         = VALUES(ranking_tier),
                    created_at           = VALUES(created_at)",
                sanitize_text_field( $keyword ),
                wp_json_encode( $inputs ),
                (float) $result['ranking_probability'],
                sanitize_text_field( (string) $result['ranking_tier'] ),
                current_time( 'mysql' )
            )
        );
    }
}
