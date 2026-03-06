<?php
namespace TMWSEO\Engine\Suggestions;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

/**
 * Converts SEO analysis outputs into content creation suggestions.
 *
 * Safety rule:
 * - Never creates or publishes content automatically.
 * - Generates suggestion records only.
 */
class ContentSuggestionModule {
    private const TRAFFIC_KEYWORD_MIN_VOLUME = 200;
    private const TRAFFIC_KEYWORD_MAX_DIFFICULTY = 30.0;
    private const WEAK_COMPETITOR_DA_THRESHOLD = 40.0;

    private SuggestionEngine $suggestion_engine;

    public function __construct(?SuggestionEngine $suggestion_engine = null) {
        $this->suggestion_engine = $suggestion_engine ?: new SuggestionEngine();
    }

    /**
     * @param array<int,array<string,mixed>> $seo_opportunities
     * @param array<int,array<string,mixed>> $keyword_intelligence
     * @param array<int,array<string,mixed>> $keyword_clusters
     * @return array<int,array<string,mixed>>
     */
    public function buildSuggestions(
        array $seo_opportunities,
        array $keyword_intelligence,
        array $keyword_clusters
    ): array {
        $opportunity_map = $this->buildOpportunityMap($seo_opportunities);
        $cluster_map = $this->buildClusterMap($keyword_clusters);

        $suggestions = [];
        foreach ($keyword_intelligence as $row) {
            if (!is_array($row)) {
                continue;
            }

            $keyword = strtolower(trim((string) ($row['keyword'] ?? '')));
            if ($keyword === '') {
                continue;
            }

            $search_volume = $this->extractSearchVolume($row);
            $keyword_difficulty = $this->extractKeywordDifficulty($row);
            $opportunity_score = (float) ($opportunity_map[$keyword]['opportunity_score'] ?? $row['opportunity_score'] ?? 0);

            if ($search_volume <= 500 || $keyword_difficulty >= 40 || $opportunity_score <= 7) {
                continue;
            }

            $cluster_name = (string) ($cluster_map[$keyword] ?? 'Unclustered');
            $estimated_traffic = (int) round($search_volume * 0.3);

            $suggestions[] = [
                'type' => 'content_opportunity',
                'title' => sprintf('Create article targeting: %s', $keyword),
                'description' => $this->buildDescription(
                    $keyword,
                    $search_volume,
                    $keyword_difficulty,
                    $opportunity_score,
                    $cluster_name,
                    $estimated_traffic
                ),
                'source_engine' => 'seo_opportunity_suggestion_engine',
                'priority_score' => max(0.0, min(100.0, $opportunity_score * 10)),
                'estimated_traffic' => $estimated_traffic,
                'difficulty' => $keyword_difficulty,
                'suggested_action' => 'Generate draft idea only for manual review. Do not auto-create content.',
                'status' => 'new',
            ];

            $competitor_da_avg = $this->extractCompetitorDomainAuthorityAverage(
                $row,
                (array) ($opportunity_map[$keyword] ?? [])
            );

            if ($this->isTrafficKeywordOpportunity($search_volume, $keyword_difficulty, $competitor_da_avg)) {
                $suggestions[] = [
                    'type' => 'traffic_keyword',
                    'title' => 'New traffic keyword opportunity',
                    'description' => $this->buildTrafficKeywordDescription(
                        $keyword,
                        $search_volume,
                        $keyword_difficulty,
                        $competitor_da_avg
                    ),
                    'source_engine' => 'traffic_mining_engine',
                    'priority_score' => $this->calculateTrafficKeywordPriority($search_volume, $keyword_difficulty, $competitor_da_avg),
                    'estimated_traffic' => (int) round($search_volume * 0.4),
                    'difficulty' => $keyword_difficulty,
                    'suggested_action' => 'Create article targeting this keyword. Never create the article automatically.',
                    'status' => 'new',
                ];
            }
        }

        Logs::info('suggestions', '[TMW-SUGGEST] Content suggestions generated from SEO pipeline', [
            'opportunity_input' => count($seo_opportunities),
            'keyword_input' => count($keyword_intelligence),
            'cluster_input' => count($keyword_clusters),
            'generated' => count($suggestions),
        ]);

        return $suggestions;
    }

    /**
     * @param array<int,array<string,mixed>> $seo_opportunities
     * @param array<int,array<string,mixed>> $keyword_intelligence
     * @param array<int,array<string,mixed>> $keyword_clusters
     */
    public function buildAndStoreSuggestions(
        array $seo_opportunities,
        array $keyword_intelligence,
        array $keyword_clusters
    ): int {
        $suggestions = $this->buildSuggestions($seo_opportunities, $keyword_intelligence, $keyword_clusters);

        $created = 0;
        foreach ($suggestions as $suggestion) {
            $insert_id = $this->suggestion_engine->createSuggestion($suggestion);
            if ($insert_id > 0) {
                $created++;
            }
        }

        Logs::info('suggestions', '[TMW-SUGGEST] Content suggestions stored', [
            'generated' => count($suggestions),
            'stored' => $created,
        ]);

        return $created;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function extractSearchVolume(array $row): int {
        return max(0, (int) ($row['search_volume'] ?? $row['keyword_info']['search_volume'] ?? 0));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function extractKeywordDifficulty(array $row): float {
        return max(0.0, (float) ($row['keyword_difficulty'] ?? $row['difficulty'] ?? $row['keyword_properties']['keyword_difficulty'] ?? 0));
    }

    /**
     * @param array<int,array<string,mixed>> $seo_opportunities
     * @return array<string,array<string,mixed>>
     */
    private function buildOpportunityMap(array $seo_opportunities): array {
        $map = [];

        foreach ($seo_opportunities as $row) {
            if (!is_array($row)) {
                continue;
            }

            $keyword = strtolower(trim((string) ($row['keyword'] ?? '')));
            if ($keyword === '') {
                continue;
            }

            $score = (float) ($row['opportunity_score'] ?? 0);
            if (!isset($map[$keyword]) || $score > (float) ($map[$keyword]['opportunity_score'] ?? 0)) {
                $map[$keyword] = $row;
            }
        }

        return $map;
    }

    /**
     * @param array<int,array<string,mixed>> $keyword_clusters
     * @return array<string,string>
     */
    private function buildClusterMap(array $keyword_clusters): array {
        $map = [];

        foreach ($keyword_clusters as $cluster_row) {
            if (!is_array($cluster_row)) {
                continue;
            }

            $cluster_name = trim((string) ($cluster_row['cluster_name'] ?? $cluster_row['cluster'] ?? $cluster_row['primary'] ?? 'Unclustered'));
            if ($cluster_name === '') {
                $cluster_name = 'Unclustered';
            }

            $members = (array) ($cluster_row['keywords'] ?? []);
            $primary = trim((string) ($cluster_row['primary'] ?? ''));
            if ($primary !== '') {
                $members[] = $primary;
            }

            foreach ($members as $member) {
                $keyword = strtolower(trim((string) $member));
                if ($keyword === '') {
                    continue;
                }
                $map[$keyword] = $cluster_name;
            }
        }

        return $map;
    }

    private function buildDescription(
        string $keyword,
        int $search_volume,
        float $keyword_difficulty,
        float $opportunity_score,
        string $cluster_name,
        int $estimated_traffic
    ): string {
        return implode("\n", [
            sprintf('Keyword: %s', $keyword),
            sprintf('Search volume: %d', $search_volume),
            sprintf('Difficulty: %.2f', $keyword_difficulty),
            sprintf('Opportunity score: %.2f', $opportunity_score),
            '',
            sprintf('Cluster: %s', $cluster_name),
            '',
            'Suggested content type:',
            'Blog guide or comparison article.',
            '',
            'Estimated traffic potential:',
            (string) $estimated_traffic,
        ]);
    }

    private function isTrafficKeywordOpportunity(int $search_volume, float $keyword_difficulty, ?float $competitor_da_avg): bool {
        if ($search_volume <= self::TRAFFIC_KEYWORD_MIN_VOLUME) {
            return false;
        }

        if ($keyword_difficulty >= self::TRAFFIC_KEYWORD_MAX_DIFFICULTY) {
            return false;
        }

        if ($competitor_da_avg === null) {
            return false;
        }

        return $competitor_da_avg < self::WEAK_COMPETITOR_DA_THRESHOLD;
    }

    /**
     * @param array<string,mixed> $keyword_row
     * @param array<string,mixed> $opportunity_row
     */
    private function extractCompetitorDomainAuthorityAverage(array $keyword_row, array $opportunity_row): ?float {
        $direct_candidates = [
            $keyword_row['top_competitor_domain_authority_average'] ?? null,
            $keyword_row['competitor_domain_authority_average'] ?? null,
            $keyword_row['serp_competitor_da_avg'] ?? null,
            $opportunity_row['top_competitor_domain_authority_average'] ?? null,
            $opportunity_row['competitor_domain_authority_average'] ?? null,
            $opportunity_row['serp_competitor_da_avg'] ?? null,
        ];

        foreach ($direct_candidates as $value) {
            if (is_numeric($value)) {
                $score = (float) $value;
                if ($score >= 0 && $score <= 100) {
                    return $score;
                }
            }
        }

        $scores = [];
        foreach ([$keyword_row, $opportunity_row] as $row) {
            foreach (['serp_results', 'serp_competitors', 'competitors'] as $key) {
                if (!isset($row[$key]) || !is_array($row[$key])) {
                    continue;
                }

                foreach ($row[$key] as $competitor) {
                    if (!is_array($competitor)) {
                        continue;
                    }

                    $authority = $competitor['domain_authority'] ?? $competitor['domain_rating'] ?? $competitor['domain_rank'] ?? null;
                    if (!is_numeric($authority)) {
                        continue;
                    }

                    $authority = (float) $authority;
                    if ($authority >= 0 && $authority <= 100) {
                        $scores[] = $authority;
                    }
                }
            }
        }

        if (empty($scores)) {
            return null;
        }

        return array_sum($scores) / count($scores);
    }

    private function calculateTrafficKeywordPriority(int $search_volume, float $keyword_difficulty, ?float $competitor_da_avg): float {
        $volume_score = min(100.0, $search_volume / 10.0);
        $difficulty_score = max(0.0, 100.0 - ($keyword_difficulty * 2.0));
        $authority_score = is_null($competitor_da_avg) ? 0.0 : max(0.0, 100.0 - ($competitor_da_avg * 2.0));

        return max(0.0, min(100.0, ($volume_score * 0.4) + ($difficulty_score * 0.3) + ($authority_score * 0.3)));
    }

    private function buildTrafficKeywordDescription(
        string $keyword,
        int $search_volume,
        float $keyword_difficulty,
        float $competitor_da_avg
    ): string {
        return implode("\n", [
            sprintf('Keyword: %s', $keyword),
            '',
            sprintf('Volume: %d', $search_volume),
            sprintf('Difficulty: %.2f', $keyword_difficulty),
            '',
            sprintf('Top competitor domain authority average: %.2f', $competitor_da_avg),
            '',
            'Estimated traffic if ranked top 5:',
            (string) ((int) round($search_volume * 0.4)),
            '',
            'Suggested action:',
            'Create article targeting this keyword.',
            '',
            'Never create the article automatically.',
        ]);
    }
}
