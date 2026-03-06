<?php
namespace TMWSEO\Engine\KeywordIntelligence;

if (!defined('ABSPATH')) { exit; }

class KeywordScorer {

    /**
     * @param array<string,mixed> $context
     */
    public function score(string $keyword, array $context): int {
        $keyword = strtolower(trim($keyword));
        $score = 0;

        $model = strtolower((string) ($context['model_name'] ?? ''));
        if ($model !== '' && strpos($keyword, $model) !== false) {
            $score += 40;
        }

        $tags = (array) ($context['model_tags'] ?? []);
        foreach ($tags as $tag) {
            $tag = strtolower(trim((string) $tag));
            if ($tag !== '' && strpos($keyword, $tag) !== false) {
                $score += 20;
                break;
            }
        }

        $platform = strtolower((string) ($context['platform_name'] ?? ''));
        if ($platform !== '' && strpos($keyword, $platform) !== false) {
            $score += 20;
        }

        $category = strtolower((string) ($context['category_name'] ?? ''));
        if ($category !== '' && strpos($keyword, $category) !== false) {
            $score += 10;
        }

        return $score;
    }
}
