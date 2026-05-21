<?php
namespace TMWSEO\Engine\Opportunities;
if (!defined('ABSPATH')) { exit; }
class ModelKeywordRoleClassifier {
    public static function classify(string $keyword, string $model): string {
        $k = ModelOpportunityNormalizer::normalize_keyword($keyword);
        $m = ModelOpportunityNormalizer::normalize_keyword($model);
        if (ModelOpportunityNormalizer::is_noise($keyword)) return 'noise';
        if ($k === $m) return 'primary';
        if (preg_match('/\b(porn|sex|xxx|nude|leaks?)\b/i', $k)) return 'risky_explicit';
        if (preg_match('/\b(livejasmin|camsoda|fancentro|onlyfans|fansly|pornhub|facebook|tiktok|official|website|link hub)\b/i', $k)) return 'platform_intent';
        if (preg_match('/\b(about|official profile|today|recent|website|live)\b/i', $k)) return 'content_support';
        if (str_contains($k, $m) && preg_match('/\b(live cam|webcam chat|cam show|official|livejasmin|camsoda|fancentro|onlyfans|fansly)\b/i', $k)) return 'rankmath_candidate';
        return str_contains($k, $m) ? 'content_support' : 'noise';
    }
}
