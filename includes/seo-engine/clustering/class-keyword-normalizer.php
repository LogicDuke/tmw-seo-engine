<?php
namespace TMWSEO\Engine\Clustering;

if (!defined('ABSPATH')) { exit; }

class KeywordNormalizer {

    /** @var array<string,bool> */
    private array $stopwords;

    public function __construct(?array $stopwords = null) {
        $defaults = [
            'a', 'an', 'and', 'at', 'by', 'for', 'from', 'in', 'into', 'is', 'it', 'of',
            'on', 'or', 'that', 'the', 'to', 'with', 'your', 'you', 'me', 'my', 'our',
        ];

        $items = is_array($stopwords) ? $stopwords : $defaults;
        $map = [];
        foreach ($items as $word) {
            $word = trim((string) $word);
            if ($word === '') {
                continue;
            }
            $map[strtolower($word)] = true;
        }

        $this->stopwords = $map;
    }

    /** @return string[] */
    public function tokenize(string $keyword): array {
        $keyword = strtolower(trim($keyword));
        if ($keyword === '') {
            return [];
        }

        $parts = preg_split('/[^\p{L}\p{N}]+/u', $keyword);
        if (!is_array($parts)) {
            return [];
        }

        $tokens = [];
        foreach ($parts as $part) {
            $token = trim((string) $part);
            if ($token === '') {
                continue;
            }
            if (isset($this->stopwords[$token])) {
                continue;
            }
            $tokens[] = $token;
        }

        return $tokens;
    }

    public function normalize(string $keyword): string {
        $tokens = $this->tokenize($keyword);
        if (empty($tokens)) {
            return '';
        }

        $tokens = array_values(array_unique($tokens));
        sort($tokens, SORT_NATURAL | SORT_FLAG_CASE);

        return implode(' ', $tokens);
    }
}
