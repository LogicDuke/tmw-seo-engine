<?php
namespace TMWSEO\Engine\KeywordIntelligence;

if (!defined('ABSPATH')) { exit; }

class KeywordPackBuilder {

    /**
     * @param array<int,array<string,mixed>> $scored_keywords
     * @return array<string,mixed>
     */
    public function build(array $scored_keywords): array {
        if (empty($scored_keywords)) {
            return [
                'primary_keyword' => '',
                'keywords' => [],
            ];
        }

        usort($scored_keywords, static function (array $a, array $b): int {
            $score_a = (int) ($a['score'] ?? 0);
            $score_b = (int) ($b['score'] ?? 0);
            if ($score_a === $score_b) {
                $sv_a = (int) ($a['search_volume'] ?? 0);
                $sv_b = (int) ($b['search_volume'] ?? 0);
                return $sv_b <=> $sv_a;
            }
            return $score_b <=> $score_a;
        });

        $top_keywords = array_slice($scored_keywords, 0, 20);
        if (count($top_keywords) > 10) {
            $top_keywords = array_slice($top_keywords, 0, max(10, count($top_keywords)));
        }

        return [
            'primary_keyword' => (string) ($top_keywords[0]['keyword'] ?? ''),
            'keywords' => $top_keywords,
        ];
    }
}
