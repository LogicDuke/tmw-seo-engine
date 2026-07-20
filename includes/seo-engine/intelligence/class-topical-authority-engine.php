<?php
namespace TMWSEO\Engine\Intelligence;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class TopicalAuthorityEngine {
    /**
     * @param array<string,float|int> $signals
     * @return array<string,mixed>
     */
    public function calculate_cluster_score(string $cluster_key, array $signals): array {
        $started = microtime(true);

        $page_count = $this->normalize((float) ($signals['page_count'] ?? 0), 0, 25);
        $coverage = $this->clamp((float) ($signals['coverage_quality'] ?? 0));
        $internal_links = $this->clamp((float) ($signals['internal_linking_density'] ?? 0));
        $semantic = $this->clamp((float) ($signals['semantic_coverage'] ?? 0));
        $freshness = $this->clamp((float) ($signals['freshness'] ?? 0));
        $structure = $this->clamp((float) ($signals['pillar_supporting_structure'] ?? 0));
        $rank_distribution = $this->clamp((float) ($signals['ranking_distribution'] ?? 0));
        $competitor_depth = $this->clamp((float) ($signals['competitor_cluster_depth'] ?? 0));

        $score = (
            ($page_count * 0.15) +
            ($coverage * 0.20) +
            ($internal_links * 0.15) +
            ($semantic * 0.15) +
            ($freshness * 0.10) +
            ($structure * 0.10) +
            ($rank_distribution * 0.10) +
            ((100 - $competitor_depth) * 0.05)
        );

        $score = round($this->clamp($score), 2);
        $label = $this->label($score);

        $result = [
            'cluster_key' => $cluster_key,
            'topical_authority_score' => $score,
            'label' => $label,
            'explanation' => $this->build_explanation($score, $coverage, $internal_links, $structure),
        ];

        $this->persist($result);

        Logs::info('intelligence', '[TMW-TOPICAL] Cluster authority scored', [
            'cluster' => $cluster_key,
            'score' => $score,
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
        ]);

        return $result;
    }

    public function label(float $score): string {
        if ($score <= 25) {
            return 'weak';
        }
        if ($score <= 50) {
            return 'developing';
        }
        if ($score <= 75) {
            return 'solid';
        }
        return 'strong';
    }

    private function clamp(float $score): float {
        return max(0, min(100, $score));
    }

    private function normalize(float $value, float $min, float $max): float {
        if ($max <= $min) {
            return 0;
        }

        return $this->clamp((($value - $min) / ($max - $min)) * 100);
    }

    private function build_explanation(float $score, float $coverage, float $internal_links, float $structure): string {
        return sprintf(
            'Cluster score %.2f (%s): coverage %.1f, internal linking %.1f, pillar/support structure %.1f. Improve weak supporting pages before publishing changes.',
            $score,
            $this->label($score),
            $coverage,
            $internal_links,
            $structure
        );
    }

    /**
     * @param array<string,mixed> $result
     */
    private function persist(array $result): void {
        global $wpdb;

        $wpdb->replace(
            IntelligenceStorage::table_cluster_scores(),
            [
                'cluster_key' => sanitize_text_field((string) $result['cluster_key']),
                'score' => (float) $result['topical_authority_score'],
                'label' => sanitize_text_field((string) $result['label']),
                'explanation' => sanitize_textarea_field((string) $result['explanation']),
                'updated_at' => current_time('mysql'),
            ],
            ['%s', '%f', '%s', '%s', '%s']
        );
    }
}
