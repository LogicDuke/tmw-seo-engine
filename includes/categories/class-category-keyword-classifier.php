<?php
namespace TMWSEO\Engine\Categories;

if (!defined('ABSPATH')) { exit; }

class CategoryKeywordClassifier {
    private const BLOCKED_PATTERNS = [
        '/\bleak(?:ed|s)?\b/u',
        '/\bonlyfans\s+leak(?:ed|s)?\b/u',
        '/\bfree\s+leaks\b/u',
        '/\bmega\s+leak\b/u',
        '/\btorrent\b/u',
        '/\bstolen\b/u',
        '/\brip\b/u',
        '/\bpirat(?:e|ed|ing)\b/u',
    ];

    private const AGE_ADJACENT_PATTERNS = [
        '/\bteen\b/u',
        '/\byoung\b/u',
        '/\bbarely\s+legal\b/u',
        '/\bschoolgirl\b/u',
        '/\bcollege\s+girl\b/u',
        '/\b18\s*year\s*old\b/u',
        '/\b18yo\b/u',
        '/\b19yo\b/u',
    ];

    private const ADULT_INTENT_PATTERNS = [
        '/\badult\b/u',
        '/\bsex\b/u',
        '/\bporn\b/u',
        '/\bnsfw\b/u',
    ];

    private const ETHNICITY_REGION_LANGUAGE_TERMS = [
        'asian','latina','ebony','indian','arab','brazilian','russian','european','filipina','korean','japanese','chinese','thai','spanish','french','german',
    ];

    private const STYLE_APPEARANCE_TERMS = [
        'lingerie','glamour','cosplay','fitness','tattooed','blonde','brunette','redhead','curvy','bbw','petite','slim','mature',
    ];

    private const PLATFORM_TERMS = [
        'livejasmin','camsoda','chaturbate','stripchat','cam4','bongacams','jerkmate','myfreecams','flirt4free','imlive',
    ];

    private const METRIC_COLUMN_MAP = [
        'keyword' => ['keyword'],
        'volume' => ['volume','search volume'],
        'cpc' => ['cpc'],
        'competition' => ['competition'],
        'seo_score' => ['seo difficulty','seo score'],
        'trend' => ['trend','trend %'],
    ];

    public function classify_keyword(string $keyword, array $metrics = []): array {
        $normalized = $this->normalize_keyword($keyword);

        $result = [
            'keyword' => $keyword,
            'normalized_keyword' => $normalized,
            'volume' => $metrics['volume'] ?? null,
            'cpc' => $metrics['cpc'] ?? null,
            'competition' => $metrics['competition'] ?? null,
            'seo_score' => $metrics['seo_score'] ?? null,
            'trend' => $metrics['trend'] ?? null,
            'matched_registry_keys' => [],
            'matched_families' => [],
            'decision' => 'ignore',
            'risk_level' => 'low',
            'recommended_page_type' => 'none',
            'generator_safe' => false,
            'public_category_candidate' => false,
            'seo_research_candidate' => false,
            'review_required' => false,
            'blocked' => false,
            'reasons' => [],
        ];

        if ($normalized === '') {
            $result['reasons'][] = 'Empty keyword after normalization.';
            return $result;
        }

        if ($this->contains_pattern($normalized, self::BLOCKED_PATTERNS)) {
            return $this->blocked_result($result, 'Blocked due to leak/piracy wording.');
        }

        if ($this->contains_pattern($normalized, self::AGE_ADJACENT_PATTERNS)) {
            return $this->blocked_result($result, 'Blocked due to age-adjacent sexualized wording.');
        }

        $registryMatches = $this->match_registry($normalized);
        $result['matched_registry_keys'] = $registryMatches['keys'];
        $result['matched_families'] = $registryMatches['families'];

        $hasAdultIntent = $this->contains_pattern($normalized, self::ADULT_INTENT_PATTERNS)
            || $this->family_match($registryMatches['families'], [
                CategoryRegistry::FAMILY_ADULT_INTENT_REVIEW,
                CategoryRegistry::FAMILY_EXPLICIT_INTENT_REVIEW,
            ]);

        $hasSensitiveModifier = $this->contains_any_term($normalized, self::ETHNICITY_REGION_LANGUAGE_TERMS)
            || $this->contains_any_term($normalized, self::STYLE_APPEARANCE_TERMS)
            || $this->family_match($registryMatches['families'], [
                CategoryRegistry::FAMILY_ETHNICITY_REVIEW,
                CategoryRegistry::FAMILY_REGION_REVIEW,
                CategoryRegistry::FAMILY_NATIONALITY_REVIEW,
                CategoryRegistry::FAMILY_LANGUAGE_REVIEW,
                CategoryRegistry::FAMILY_APPEARANCE_REVIEW,
                CategoryRegistry::FAMILY_STYLE_REVIEW,
            ]);

        $isPlatform = $this->contains_any_term($normalized, self::PLATFORM_TERMS)
            || $this->family_match($registryMatches['families'], [CategoryRegistry::FAMILY_PLATFORM]);

        $isPublicPillar = str_contains($normalized, 'webcam models')
            || str_contains($normalized, 'cam models')
            || str_contains($normalized, 'live cam models')
            || str_contains($normalized, 'webcam chat rooms');

        if ($hasSensitiveModifier) {
            $result['decision'] = 'review_required';
            $result['risk_level'] = CategoryRegistry::RISK_REVIEW_REQUIRED;
            $result['recommended_page_type'] = 'internal_research_only';
            $result['review_required'] = true;
            $result['seo_research_candidate'] = true;
            $result['reasons'][] = 'Sensitive modifier detected; requires manual review and cannot be treated as model fact.';
        }

        if ($hasAdultIntent) {
            $result['decision'] = 'review_required';
            $result['risk_level'] = CategoryRegistry::RISK_REVIEW_REQUIRED;
            $result['recommended_page_type'] = str_contains($normalized, 'chat') ? 'pillar_page' : 'blog_or_guide';
            $result['review_required'] = true;
            $result['seo_research_candidate'] = true;
            $result['generator_safe'] = false;
            $result['public_category_candidate'] = false;
            $result['reasons'][] = 'Adult/explicit intent detected; SEO research only and not generator-safe.';
        }

        if ($isPlatform && !$hasSensitiveModifier && !$hasAdultIntent) {
            $result['decision'] = 'public_category_candidate';
            $result['risk_level'] = CategoryRegistry::RISK_LOW;
            $result['recommended_page_type'] = 'platform_category';
            $result['public_category_candidate'] = true;
            $result['seo_research_candidate'] = true;
            $result['review_required'] = false;
            $result['generator_safe'] = false;
            $result['reasons'][] = 'Platform keyword candidate; model/platform association still requires verified link evidence later.';
        }

        if ($isPublicPillar && !$hasSensitiveModifier && !$hasAdultIntent) {
            $result['decision'] = 'public_category_candidate';
            $result['risk_level'] = CategoryRegistry::RISK_LOW;
            $result['recommended_page_type'] = str_contains($normalized, 'chat') ? 'pillar_page' : 'category_page';
            $result['public_category_candidate'] = true;
            $result['seo_research_candidate'] = true;
            $result['reasons'][] = 'Broad low-risk pillar keyword candidate.';
        }

        if (in_array($normalized, $this->generator_safe_registry_keys(), true) && !$hasAdultIntent && !$hasSensitiveModifier) {
            $result['decision'] = 'generator_safe_candidate';
            $result['risk_level'] = CategoryRegistry::RISK_LOW;
            $result['generator_safe'] = true;
            $result['public_category_candidate'] = true;
            $result['seo_research_candidate'] = true;
            $result['recommended_page_type'] = 'category_page';
            $result['reasons'][] = 'Matched generator-safe candidate in CategoryRegistry.';
        }

        if ($result['decision'] === 'ignore' && !empty($registryMatches['keys'])) {
            $result['decision'] = 'seo_research_only';
            $result['risk_level'] = CategoryRegistry::RISK_REVIEW_REQUIRED;
            $result['recommended_page_type'] = 'internal_research_only';
            $result['seo_research_candidate'] = true;
            $result['review_required'] = true;
            $result['reasons'][] = 'Matched CategoryRegistry entries but not public-safe for direct generation.';
        }

        return $result;
    }

    public function classify_rows(array $rows): array {
        $results = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $normalizedRow = $this->normalize_row_keys($row);
            $keyword = (string) ($normalizedRow['keyword'] ?? '');
            $results[] = $this->classify_keyword($keyword, [
                'volume' => $normalizedRow['volume'] ?? null,
                'cpc' => $normalizedRow['cpc'] ?? null,
                'competition' => $normalizedRow['competition'] ?? null,
                'seo_score' => $normalizedRow['seo_score'] ?? null,
                'trend' => $normalizedRow['trend'] ?? null,
            ]);
        }

        return $results;
    }

    private function blocked_result(array $result, string $reason): array {
        $result['decision'] = 'blocked';
        $result['risk_level'] = CategoryRegistry::RISK_BLOCKED;
        $result['recommended_page_type'] = 'none';
        $result['blocked'] = true;
        $result['review_required'] = true;
        $result['generator_safe'] = false;
        $result['public_category_candidate'] = false;
        $result['seo_research_candidate'] = false;
        $result['reasons'][] = $reason;
        return $result;
    }

    private function normalize_keyword(string $keyword): string {
        $keyword = strtolower(trim($keyword));
        return preg_replace('/\s+/u', ' ', $keyword) ?? '';
    }

    private function contains_pattern(string $keyword, array $patterns): bool {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $keyword) === 1) { return true; }
        }
        return false;
    }

    private function contains_any_term(string $keyword, array $terms): bool {
        foreach ($terms as $term) {
            if (preg_match('/\b' . preg_quote($term, '/') . '\b/u', $keyword) === 1) { return true; }
        }
        return false;
    }

    private function normalize_row_keys(array $row): array {
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[strtolower(trim((string) $key))] = $value;
        }

        $result = [];
        foreach (self::METRIC_COLUMN_MAP as $field => $candidates) {
            foreach ($candidates as $candidate) {
                if (array_key_exists($candidate, $normalized)) {
                    $result[$field] = $normalized[$candidate];
                    break;
                }
            }
        }

        return $result;
    }

    private function match_registry(string $keyword): array {
        $matches = ['keys' => [], 'families' => []];
        foreach (CategoryRegistry::all() as $item) {
            $key = str_replace('_', ' ', (string) ($item['key'] ?? ''));
            $label = strtolower((string) ($item['label'] ?? ''));
            if (($key !== '' && str_contains($keyword, $key)) || ($label !== '' && str_contains($keyword, $label))) {
                $matches['keys'][] = (string) $item['key'];
                $matches['families'][] = (string) $item['family'];
            }
        }

        $matches['keys'] = array_values(array_unique($matches['keys']));
        $matches['families'] = array_values(array_unique($matches['families']));
        return $matches;
    }

    private function family_match(array $families, array $targets): bool {
        foreach ($targets as $target) {
            if (in_array($target, $families, true)) { return true; }
        }
        return false;
    }

    private function generator_safe_registry_keys(): array {
        return array_map(static function (array $item): string {
            return str_replace('_', ' ', (string) ($item['key'] ?? ''));
        }, CategoryRegistry::generator_safe_candidates());
    }
}
