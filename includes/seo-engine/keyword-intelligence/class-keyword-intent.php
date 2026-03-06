<?php
namespace TMWSEO\Engine\KeywordIntelligence;

if (!defined('ABSPATH')) { exit; }

class KeywordIntent {

    /**
     * @param array<string,mixed> $context
     */
    public function classify(string $keyword, array $context): string {
        $keyword = strtolower(trim($keyword));
        if ($keyword === '') {
            return 'generic_intent';
        }

        $model_name = strtolower((string) ($context['model_name'] ?? ''));
        $platform = strtolower((string) ($context['platform_name'] ?? ''));

        if ($model_name !== '' && strpos($keyword, $model_name) !== false) {
            if (preg_match('/\b(watch|live|stream|cam|show)\b/u', $keyword)) {
                return 'watch_intent';
            }

            if ($platform !== '' && strpos($keyword, $platform) !== false) {
                return 'brand_intent';
            }

            return 'model_intent';
        }

        if ($platform !== '' && strpos($keyword, $platform) !== false) {
            return 'brand_intent';
        }

        return 'generic_intent';
    }
}
