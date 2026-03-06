<?php
namespace TMWSEO\Engine\Clustering;

if (!defined('ABSPATH')) { exit; }

class ClusterBuilder {

    private KeywordNormalizer $normalizer;
    private int $max_clusters;
    private int $max_keywords_per_cluster;
    private float $similarity_threshold;

    public function __construct(
        ?KeywordNormalizer $normalizer = null,
        int $max_clusters = 5,
        int $max_keywords_per_cluster = 5,
        float $similarity_threshold = 0.4
    ) {
        $this->normalizer = $normalizer ?: new KeywordNormalizer();
        $this->max_clusters = max(1, $max_clusters);
        $this->max_keywords_per_cluster = max(1, $max_keywords_per_cluster);
        $this->similarity_threshold = max(0.0, min(1.0, $similarity_threshold));
    }

    /**
     * @param string[] $keywords
     * @return array<int,array{cluster:string,primary:string,keywords:string[]}>
     */
    public function build(array $keywords): array {
        $entries = $this->prepare_keywords($keywords);

        $cluster_map = [];
        $cluster_order = [];

        foreach ($entries as $entry) {
            $best_key = '';
            $best_score = 0.0;

            foreach ($cluster_order as $cluster_key) {
                $cluster_tokens = $cluster_map[$cluster_key]['tokens'];
                $score = $this->overlap_score($entry['tokens'], $cluster_tokens);
                if ($score > $best_score) {
                    $best_score = $score;
                    $best_key = $cluster_key;
                }
            }

            if ($best_key !== '' && $best_score >= $this->similarity_threshold) {
                $cluster_map[$best_key]['items'][] = $entry;
                continue;
            }

            if (count($cluster_order) >= $this->max_clusters) {
                continue;
            }

            $cluster_key = $entry['normalized'];
            if ($cluster_key === '' || isset($cluster_map[$cluster_key])) {
                $cluster_key = $entry['normalized'] . ':' . md5($entry['keyword']);
            }

            $cluster_map[$cluster_key] = [
                'tokens' => $entry['tokens'],
                'items' => [$entry],
            ];
            $cluster_order[] = $cluster_key;
        }

        usort($cluster_order, static function (string $a, string $b) use ($cluster_map): int {
            return count($cluster_map[$b]['items']) <=> count($cluster_map[$a]['items']);
        });

        $clusters = [];
        foreach ($cluster_order as $cluster_key) {
            $items = $cluster_map[$cluster_key]['items'];
            $primary = $this->pick_primary_keyword(array_map(static fn(array $item): string => $item['keyword'], $items));
            if ($primary === '') {
                continue;
            }

            $supporting = [];
            foreach ($items as $item) {
                $keyword = $item['keyword'];
                if ($keyword === $primary) {
                    continue;
                }
                $supporting[] = $keyword;
                if (count($supporting) >= $this->max_keywords_per_cluster) {
                    break;
                }
            }

            $clusters[] = [
                'cluster' => $primary,
                'primary' => $primary,
                'keywords' => $supporting,
            ];

            if (count($clusters) >= $this->max_clusters) {
                break;
            }
        }

        return $clusters;
    }

    /**
     * @param string[] $keywords
     * @return array<int,array{keyword:string,normalized:string,tokens:string[]}>
     */
    private function prepare_keywords(array $keywords): array {
        $unique = [];
        foreach ($keywords as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword === '') {
                continue;
            }
            $unique[$keyword] = true;
        }

        $entries = [];
        foreach (array_keys($unique) as $keyword) {
            $tokens = $this->normalizer->tokenize($keyword);
            if (empty($tokens)) {
                continue;
            }
            $tokens = array_values(array_unique($tokens));
            sort($tokens, SORT_NATURAL | SORT_FLAG_CASE);

            $entries[] = [
                'keyword' => $keyword,
                'normalized' => implode(' ', $tokens),
                'tokens' => $tokens,
            ];
        }

        usort($entries, static function (array $a, array $b): int {
            $a_count = count($a['tokens']);
            $b_count = count($b['tokens']);
            if ($a_count !== $b_count) {
                return $a_count <=> $b_count;
            }
            return strlen($a['keyword']) <=> strlen($b['keyword']);
        });

        return $entries;
    }

    /** @param string[] $tokens_a @param string[] $tokens_b */
    private function overlap_score(array $tokens_a, array $tokens_b): float {
        $set_a = array_fill_keys($tokens_a, true);
        $set_b = array_fill_keys($tokens_b, true);

        $intersection = count(array_intersect_key($set_a, $set_b));
        $union = count($set_a) + count($set_b) - $intersection;

        if ($union <= 0) {
            return 0.0;
        }

        return $intersection / $union;
    }

    /** @param string[] $keywords */
    private function pick_primary_keyword(array $keywords): string {
        $best = '';
        $best_token_count = PHP_INT_MAX;

        foreach ($keywords as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword === '') {
                continue;
            }
            $count = count(array_filter(preg_split('/\s+/u', $keyword), 'strlen'));
            if ($count < $best_token_count) {
                $best = $keyword;
                $best_token_count = $count;
                continue;
            }

            if ($count === $best_token_count && ($best === '' || strlen($keyword) < strlen($best))) {
                $best = $keyword;
            }
        }

        return $best;
    }
}
