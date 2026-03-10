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
    private const DEFAULT_EXPECTED_CTR = 0.3;
    private const TRAFFIC_KEYWORD_MIN_VOLUME = 200;
    private const TRAFFIC_KEYWORD_MAX_DIFFICULTY = 30.0;
    private const WEAK_COMPETITOR_DA_THRESHOLD = 40.0;

    /**
     * Physical-attribute / niche keywords that map to category pages on this platform.
     * These drive browsing/discovery pages, not individual model profile pages.
     *
     * @var string[]
     */
    private const CATEGORY_PAGE_CLUSTER_SIGNALS = [
        'category', 'niche', 'genre', 'type of',
        'blonde', 'brunette', 'redhead', 'ebony', 'latina', 'asian',
        'petite', 'curvy', 'athletic', 'big boob', 'big butt', 'bbw',
        'milf', 'teen', 'mature', 'fetish', 'cosplay', 'outdoor',
        'couples', 'dominant', 'interracial', 'glamour', 'fitness',
        'dance', 'chatty', 'livejasmin', 'compare platform', 'chat',
    ];

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
            $cluster_importance = $this->resolveClusterImportance($cluster_name, $keyword_clusters);
            $node_degree = max(0, (int) ($row['node_degree'] ?? 0));
            $cluster_size = max(0, (int) ($row['graph_cluster_size'] ?? 0));
            $estimated_traffic = $this->calculateEstimatedTraffic($search_volume);

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
                'priority_score' => $this->calculatePriorityScore(
                    $search_volume,
                    $opportunity_score,
                    $cluster_importance,
                    $keyword_difficulty,
                    $node_degree,
                    $cluster_size
                ),
                'estimated_traffic' => $estimated_traffic,
                'difficulty' => $keyword_difficulty,
                'suggested_action' => implode("\n", [
                    'DESTINATION_TYPE: ' . $this->resolve_suggestion_destination($keyword, $cluster_name, (string) ($row['intent_type'] ?? 'generic')),
                    'Generate draft idea only for manual review. Do not auto-create content.',
                ]),
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
                    'priority_score' => $this->calculateTrafficKeywordPriority(
                        $search_volume,
                        $keyword_difficulty,
                        $competitor_da_avg,
                        $cluster_importance,
                        $node_degree,
                        $cluster_size
                    ),
                    'estimated_traffic' => $this->calculateEstimatedTraffic($search_volume),
                    'difficulty' => $keyword_difficulty,
                    'suggested_action' => implode("\n", [
                        'DESTINATION_TYPE: ' . $this->resolve_suggestion_destination($keyword, $cluster_name, (string) ($row['intent_type'] ?? 'generic')),
                        'Create article targeting this keyword. Never create the article automatically.',
                    ]),
                    'status' => 'new',
                ];
            }
        }

        $cluster_expansion_suggestions = $this->buildClusterExpansionSuggestions($keyword_clusters);
        if (!empty($cluster_expansion_suggestions)) {
            $suggestions = array_merge($suggestions, $cluster_expansion_suggestions);
        }

        Logs::info('suggestions', '[TMW-SUGGEST] Content suggestions generated from SEO pipeline', [
            'opportunity_input' => count($seo_opportunities),
            'keyword_input' => count($keyword_intelligence),
            'cluster_input' => count($keyword_clusters),
            'cluster_expansion_generated' => count($cluster_expansion_suggestions),
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

    private function calculateTrafficKeywordPriority(
        int $search_volume,
        float $keyword_difficulty,
        ?float $competitor_da_avg,
        float $cluster_importance,
        int $node_degree = 0,
        int $cluster_size = 0
    ): float {
        $opportunity_score = is_null($competitor_da_avg)
            ? 0.0
            : max(0.0, min(10.0, 10.0 - ($competitor_da_avg / 10.0)));

        return $this->calculatePriorityScore(
            $search_volume,
            $opportunity_score,
            $cluster_importance,
            $keyword_difficulty,
            $node_degree,
            $cluster_size
        );
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
            (string) $this->calculateEstimatedTraffic($search_volume),
            '',
            'Suggested action:',
            'Create article targeting this keyword.',
            '',
            'Never create the article automatically.',
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $keyword_clusters
     * @return array<int,array<string,mixed>>
     */
    private function buildClusterExpansionSuggestions(array $keyword_clusters): array {
        $suggestions = [];

        foreach ($keyword_clusters as $cluster_row) {
            if (!is_array($cluster_row)) {
                continue;
            }

            $cluster_name = trim((string) ($cluster_row['cluster_name'] ?? $cluster_row['cluster'] ?? $cluster_row['primary'] ?? ''));
            if ($cluster_name === '') {
                continue;
            }

            $current_articles = $this->extractStringList($cluster_row, [
                'current_articles',
                'existing_articles',
                'articles',
                'published_articles',
            ]);

            $missing_articles = $this->extractStringList($cluster_row, [
                'missing_articles',
                'missing_supporting_articles',
                'missing_topics',
                'supporting_topic_gaps',
            ]);

            if (empty($current_articles) || empty($missing_articles)) {
                continue;
            }

            $missing_lines = array_map(
                static fn(string $topic): string => sprintf('- %s', $topic),
                $missing_articles
            );

            $suggestions[] = [
                'type' => 'cluster_expansion',
                'title' => 'Expand topic authority cluster',
                'description' => implode("\n", array_merge([
                    sprintf('Cluster: %s', $cluster_name),
                    '',
                    'Missing articles:',
                    '',
                ], $missing_lines)),
                'source_engine' => 'topic_authority_system',
                'priority_score' => max(1.0, min(10.0, 5.0 + (count($missing_articles) * 0.5))),
                'estimated_traffic' => 0,
                'difficulty' => 0,
                'suggested_action' => implode("\n", [
                    'DESTINATION_TYPE: ' . $this->resolve_cluster_destination($cluster_name),
                    'Generate content briefs for these topics.',
                ]),
                'status' => 'new',
            ];
        }

        return $suggestions;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $keys
     * @return array<int,string>
     */
    private function extractStringList(array $row, array $keys): array {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $value = $row[$key];
            $items = [];

            if (is_array($value)) {
                foreach ($value as $candidate) {
                    if (is_array($candidate)) {
                        $candidate = $candidate['topic'] ?? $candidate['title'] ?? $candidate['keyword'] ?? '';
                    }

                    $clean = trim((string) $candidate);
                    if ($clean !== '') {
                        $items[] = $clean;
                    }
                }
            } elseif (is_string($value)) {
                $parts = preg_split('/[\r\n,]+/', $value) ?: [];
                foreach ($parts as $part) {
                    $clean = trim((string) $part);
                    if ($clean !== '') {
                        $items[] = $clean;
                    }
                }
            }

            $items = array_values(array_unique($items));
            if (!empty($items)) {
                return $items;
            }
        }

        return [];
    }

    private function calculatePriorityScore(
        int $search_volume,
        float $opportunity_score,
        float $cluster_importance,
        float $keyword_difficulty,
        int $node_degree = 0,
        int $cluster_size = 0
    ): float {
        $normalized_search_volume = max(0.0, min(10.0, $search_volume / 1000.0));
        $normalized_opportunity = max(0.0, min(10.0, $opportunity_score));
        $normalized_cluster_importance = max(0.0, min(10.0, $cluster_importance / 10.0));
        $normalized_keyword_difficulty = max(0.0, min(10.0, $keyword_difficulty / 10.0));

        $node_degree_boost = max(0.0, min(2.0, $node_degree / 5.0));
        $cluster_size_boost = max(0.0, min(2.0, $cluster_size / 10.0));

        $priority_score = ($normalized_search_volume * 0.35)
            + ($normalized_opportunity * 0.25)
            + ($normalized_cluster_importance * 0.15)
            + $node_degree_boost
            + $cluster_size_boost
            - ($normalized_keyword_difficulty * 0.1);

        return max(1.0, min(10.0, round($priority_score, 1)));
    }

    private function calculateEstimatedTraffic(int $search_volume, ?float $expected_ctr = null): int {
        $ctr = is_numeric($expected_ctr) ? (float) $expected_ctr : self::DEFAULT_EXPECTED_CTR;
        $ctr = max(0.0, min(1.0, $ctr));

        return max(0, (int) round($search_volume * $ctr));
    }

    /**
     * Determine the correct destination type for keyword-based content suggestions.
     *
     * Routing rules (deterministic, explainable):
     * 1. If keyword or cluster clearly targets model/performer content → model_page
     * 2. If cluster is a physical-attribute / niche / platform category → category_page
     * 3. Fallback for genuinely generic informational intent → generic_post
     *
     * IMPORTANT: This never forces model_page for ambiguous content.
     * Only use model_page when signals are explicit and clear.
     */
    private function resolve_suggestion_destination(string $keyword, string $cluster_name, string $intent_type = 'generic'): string {
        $intent_route = $this->route_destination_by_intent($intent_type);
        if ($intent_route !== '') {
            return $intent_route;
        }

        $haystack = strtolower(trim($keyword . ' ' . $cluster_name));

        // Model-page signals: keyword/cluster explicitly targets a model profile or performer page.
        $model_signals = ['model page', 'model profile', 'performer page', 'cam model'];
        foreach ($model_signals as $signal) {
            if (strpos($haystack, $signal) !== false) {
                return 'model_page';
            }
        }

        // Category-page signals: physical attribute / niche / platform browsing clusters.
        // These generate category-hub pages, not model profiles.
        foreach (self::CATEGORY_PAGE_CLUSTER_SIGNALS as $signal) {
            if (strpos($haystack, $signal) !== false) {
                return 'category_page';
            }
        }

        // Genuinely generic informational / comparison content.
        return 'generic_post';
    }


    private function route_destination_by_intent(string $intent_type): string {
        return match (strtolower(trim($intent_type))) {
            'model_search' => 'model_page',
            'fetish_discovery' => 'tag_landing_page',
            'category_discovery' => 'category_page',
            'comparison' => 'traffic_page',
            default => '',
        };
    }

    /**
     * Determine the correct destination type for cluster expansion suggestions.
     *
     * Cluster expansion suggestions target topic authority hubs.
     * On this platform, topic clusters map to category pages (browsing hubs),
     * unless the cluster name explicitly references model-profile work.
     */
    private function resolve_cluster_destination(string $cluster_name): string {
        $haystack = strtolower(trim($cluster_name));

        // Model-page cluster: explicitly about model profile growth.
        if (strpos($haystack, 'model page') !== false || strpos($haystack, 'model profile') !== false) {
            return 'model_page';
        }

        // Physical-attribute / niche clusters are category-page authority hubs.
        foreach (self::CATEGORY_PAGE_CLUSTER_SIGNALS as $signal) {
            if (strpos($haystack, $signal) !== false) {
                return 'category_page';
            }
        }

        // Named clusters that aren't clearly model or category: keep generic.
        return 'generic_post';
    }

    /**
     * @param array<int,array<string,mixed>> $keyword_clusters
     */
    private function resolveClusterImportance(string $cluster_name, array $keyword_clusters): float {
        if ($cluster_name === '' || $cluster_name === 'Unclustered') {
            return 0.0;
        }

        foreach ($keyword_clusters as $cluster_row) {
            if (!is_array($cluster_row)) {
                continue;
            }

            $candidate_name = trim((string) ($cluster_row['cluster_name'] ?? $cluster_row['cluster'] ?? $cluster_row['primary'] ?? ''));
            if ($candidate_name === '' || strtolower($candidate_name) !== strtolower($cluster_name)) {
                continue;
            }

            foreach (['cluster_importance', 'importance', 'opportunity_score', 'opportunity'] as $importance_key) {
                if (isset($cluster_row[$importance_key]) && is_numeric($cluster_row[$importance_key])) {
                    $value = (float) $cluster_row[$importance_key];
                    if ($value > 10.0) {
                        $value = $value / 10.0;
                    }

                    return max(0.0, min(10.0, $value * 10.0));
                }
            }
        }

        return 0.0;
    }
}
