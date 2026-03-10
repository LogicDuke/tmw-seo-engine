<?php
namespace TMWSEO\Engine\KeywordIntelligence;

use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Keywords\QueryExpansionGraph;

if (!defined('ABSPATH')) { exit; }

class KeywordExpander {
    private const CACHE_TTL = 30 * DAY_IN_SECONDS;

    /**
     * @param string[] $seed_keywords
     * @return array<int,array<string,mixed>>
     */
    public function expand(array $seed_keywords): array {
        $expanded = [];

        foreach ($seed_keywords as $seed_keyword) {
            $seed_keyword = $this->normalize((string) $seed_keyword);
            if ($seed_keyword === '') {
                continue;
            }

            $suggestions = $this->keyword_suggestions($seed_keyword);
            $related = $this->related_keywords($seed_keyword);

            foreach ([$suggestions, $related] as $source_rows) {
                foreach ($source_rows as $row) {
                    $keyword = $this->normalize((string) ($row['keyword'] ?? ''));
                    if ($keyword === '') {
                        continue;
                    }

                    if (!isset($expanded[$keyword])) {
                        $expanded[$keyword] = [
                            'keyword' => $keyword,
                            'search_volume' => (int) ($row['search_volume'] ?? 0),
                            'keyword_difficulty' => (float) ($row['keyword_difficulty'] ?? 0),
                        ];
                        continue;
                    }

                    $expanded[$keyword]['search_volume'] = max(
                        (int) $expanded[$keyword]['search_volume'],
                        (int) ($row['search_volume'] ?? 0)
                    );
                    $expanded[$keyword]['keyword_difficulty'] = max(
                        (float) $expanded[$keyword]['keyword_difficulty'],
                        (float) ($row['keyword_difficulty'] ?? 0)
                    );
                }
            }
        }

        return array_values($expanded);
    }

    /** @return array<int,array<string,mixed>> */
    private function keyword_suggestions(string $seed_keyword): array {
        $cache_key = 'tmwseo_kw_suggest_' . md5($seed_keyword);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            Logs::debug('keywords', '[TMW-KW-CACHE] Keyword suggestions cache hit', [
                'seed' => $seed_keyword,
                'count' => count($cached),
            ]);
            return $cached;
        }

        Logs::debug('keywords', '[TMW-DFS] Requesting keyword suggestions', ['seed' => $seed_keyword]);
        $response = DataForSEO::keyword_suggestions($seed_keyword, 100);
        if (empty($response['ok'])) {
            return [];
        }

        $items = $this->extract_items((array) ($response['items'] ?? []));
        set_transient($cache_key, $items, self::CACHE_TTL);

        foreach ($items as $item) {
            QueryExpansionGraph::store_relationship($seed_keyword, (string) ($item['keyword'] ?? ''), 'dataforseo_suggest');
        }

        return $items;
    }

    /** @return array<int,array<string,mixed>> */
    private function related_keywords(string $seed_keyword): array {
        Logs::debug('keywords', '[TMW-DFS] Requesting related keywords', ['seed' => $seed_keyword]);
        $response = DataForSEO::related_keywords($seed_keyword, 1, 100);
        if (empty($response['ok'])) {
            return [];
        }

        $items = $this->extract_items((array) ($response['items'] ?? []));
        foreach ($items as $item) {
            QueryExpansionGraph::store_relationship($seed_keyword, (string) ($item['keyword'] ?? ''), 'related_keywords');
        }

        return $items;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function extract_items(array $items): array {
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $keyword = (string) ($item['keyword'] ?? $item['keyword_info']['keyword'] ?? '');
            if ($keyword === '') {
                continue;
            }

            $result[] = [
                'keyword' => $keyword,
                'search_volume' => (int) ($item['keyword_info']['search_volume'] ?? $item['search_volume'] ?? 0),
                'keyword_difficulty' => (float) ($item['keyword_info']['keyword_difficulty'] ?? $item['keyword_difficulty'] ?? 0),
            ];
        }

        return $result;
    }

    private function normalize(string $keyword): string {
        $keyword = strtolower(trim(wp_strip_all_tags($keyword)));
        $keyword = preg_replace('/\s+/u', ' ', $keyword);
        return (string) $keyword;
    }
}
