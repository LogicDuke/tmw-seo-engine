<?php
namespace TMWSEO\Engine\KeywordIntelligence;

use TMWSEO\Engine\Services\DataForSEO;

if (!defined('ABSPATH')) { exit; }

class KeywordExpander {

    private DataForSEO $dfs;

    public function __construct(?DataForSEO $dfs = null) {
        $this->dfs = $dfs ?: new DataForSEO();
    }

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
        $response = $this->dfs->keyword_suggestions($seed_keyword, 100);
        if (empty($response['ok'])) {
            return [];
        }

        return $this->extract_items((array) ($response['items'] ?? []));
    }

    /** @return array<int,array<string,mixed>> */
    private function related_keywords(string $seed_keyword): array {
        $response = $this->dfs->related_keywords($seed_keyword, 1, 100);
        if (empty($response['ok'])) {
            return [];
        }

        return $this->extract_items((array) ($response['items'] ?? []));
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
