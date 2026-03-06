<?php
namespace TMWSEO\Engine\KeywordIntelligence;

if (!defined('ABSPATH')) { exit; }

class KeywordFilter {

    /** @var string[] */
    private array $blocked_tokens = [
        'site',
        'sites',
        'review',
        'reviews',
        'best',
        'top',
        '2024',
        '2025',
        'vs',
        'comparison',
        'porn tube',
        'escort',
    ];

    /**
     * @param array<int,array<string,mixed>> $keywords
     * @return array<int,array<string,mixed>>
     */
    public function filter(array $keywords): array {
        $filtered = [];

        foreach ($keywords as $row) {
            $keyword = $this->normalize((string) ($row['keyword'] ?? ''));
            if ($keyword === '' || $this->contains_blocked_token($keyword)) {
                continue;
            }

            $row['keyword'] = $keyword;
            $filtered[$keyword] = $row;
        }

        return array_values($filtered);
    }

    private function contains_blocked_token(string $keyword): bool {
        foreach ($this->blocked_tokens as $token) {
            if (strpos($keyword, $token) !== false) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $keyword): string {
        $keyword = strtolower(trim(wp_strip_all_tags($keyword)));
        $keyword = preg_replace('/\s+/u', ' ', $keyword);
        return (string) $keyword;
    }
}
