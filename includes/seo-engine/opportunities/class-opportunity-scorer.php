<?php
namespace TMWSEO\Engine\Opportunities;

if (!defined('ABSPATH')) { exit; }

class OpportunityScorer {
    /** @var string[] */
    private array $blocked_terms = ['site', 'review', 'best', 'top', 'vs', '2024', '2025'];

    public function is_allowed_keyword(string $keyword): bool {
        $keyword = strtolower(trim($keyword));
        if ($keyword === '') {
            return false;
        }

        foreach ($this->blocked_terms as $blocked) {
            if (strpos($keyword, $blocked) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $row
     */
    public function score(array $row): float {
        $search_volume = max(0, (int) ($row['search_volume'] ?? 0));
        $difficulty = max(0.0, min(100.0, (float) ($row['difficulty'] ?? 0)));
        $relevance = max(0.0, min(100.0, (float) ($row['model_relevance'] ?? 0)));

        $volume_score = min(100.0, log(1 + $search_volume) / log(1 + 10000) * 100.0);
        $difficulty_score = 100.0 - $difficulty;

        // Weighted score to favor relevance + attainable SERP difficulty.
        return round(($volume_score * 0.35) + ($difficulty_score * 0.25) + ($relevance * 0.40), 2);
    }
}
