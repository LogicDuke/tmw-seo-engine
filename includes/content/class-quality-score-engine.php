<?php
namespace TMWSEO\Engine\Content;

if (!defined('ABSPATH')) { exit; }

class QualityScoreEngine {

    public static function evaluate(string $html, array $context = []): array {
        $text = trim((string) wp_strip_all_tags($html));
        $word_count = self::count_words($text);

        $primary_keyword = trim((string) ($context['primary_keyword'] ?? ''));
        $secondary_keywords = is_array($context['secondary_keywords'] ?? null)
            ? array_values(array_filter(array_map('strval', $context['secondary_keywords'])))
            : [];

        $entities = is_array($context['entities'] ?? null)
            ? array_values(array_filter(array_map('strval', $context['entities'])))
            : [];

        $site_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);

        $semantic_coverage = self::score_semantic_keyword_coverage($text, $primary_keyword, $secondary_keywords);
        $heading_structure = self::score_heading_structure($html);
        $word_count_score = self::score_word_count($word_count);
        $entity_coverage = self::score_entity_coverage($text, $entities);
        $internal_links = self::score_internal_links($html, $site_host);
        $readability = self::score_readability($text);

        // ── Uniqueness score (new) ─────────────────────────────────────────
        $exclude_post_id   = (int) ($context['post_id'] ?? 0);
        $post_type_context = (string) ($context['post_type'] ?? 'model');
        $uniqueness_score  = 0.0;
        $uniqueness_pct    = 0.0;
        $uniqueness_verdict = [];
        if ($text !== '' && class_exists('\\TMWSEO\\Engine\\Content\\UniquenessChecker')) {
            $similarity = UniquenessChecker::similarity_score($text, $post_type_context, 12, $exclude_post_id);
            // Convert similarity → uniqueness: 100% similar = 0.0, 0% similar = 1.0
            $uniqueness_score   = max(0.0, min(1.0, 1.0 - ($similarity / 100)));
            $uniqueness_pct     = (int) round($uniqueness_score * 100);
            $uniqueness_verdict = UniquenessChecker::verdict($similarity);
        }

        // Rebalanced weights (total still = 100, uniqueness takes 15 from readability)
        $weights = [
            'semantic_keyword_coverage' => 25,
            'heading_structure'         => 15,
            'word_count'                => 20,
            'entity_coverage'           => 10,
            'internal_links'            => 10,
            'readability'               => 10,
            'uniqueness'                => 10,
        ];

        $raw_score =
            ($semantic_coverage * $weights['semantic_keyword_coverage']) +
            ($heading_structure * $weights['heading_structure']) +
            ($word_count_score * $weights['word_count']) +
            ($entity_coverage * $weights['entity_coverage']) +
            ($internal_links * $weights['internal_links']) +
            ($readability * $weights['readability']) +
            ($uniqueness_score * $weights['uniqueness']);

        $score = (int) round(max(1, min(100, $raw_score)));

        return [
            'score' => $score,
            'warning' => $score < 70,
            'warning_message' => 'This draft may need improvement before publishing.',
            'breakdown' => [
                'semantic_keyword_coverage' => (int) round($semantic_coverage * 100),
                'heading_structure' => (int) round($heading_structure * 100),
                'word_count' => (int) round($word_count_score * 100),
                'entity_coverage' => (int) round($entity_coverage * 100),
                'internal_links' => (int) round($internal_links * 100),
                'readability' => (int) round($readability * 100),
                'uniqueness' => $uniqueness_pct,
            ],
            'uniqueness_verdict' => $uniqueness_verdict,
            'word_count' => $word_count,
        ];
    }

    private static function score_semantic_keyword_coverage(string $text, string $primary_keyword, array $secondary_keywords): float {
        if ($text === '') {
            return 0.0;
        }

        $matches = 0;
        $total = 0;

        if ($primary_keyword !== '') {
            $total++;
            if (self::contains_phrase($text, $primary_keyword)) {
                $matches++;
            }
        }

        foreach ($secondary_keywords as $keyword) {
            $keyword = trim($keyword);
            if ($keyword === '') {
                continue;
            }
            $total++;
            if (self::contains_phrase($text, $keyword)) {
                $matches++;
            }
        }

        if ($total === 0) {
            return 0.5;
        }

        return max(0.0, min(1.0, $matches / $total));
    }

    private static function score_heading_structure(string $html): float {
        preg_match_all('/<h1\b[^>]*>/i', $html, $h1_matches);
        preg_match_all('/<h2\b[^>]*>/i', $html, $h2_matches);
        preg_match_all('/<h3\b[^>]*>/i', $html, $h3_matches);

        $h1_count = count($h1_matches[0] ?? []);
        $h2_count = count($h2_matches[0] ?? []);
        $h3_count = count($h3_matches[0] ?? []);

        $score = 0.0;

        if ($h1_count === 1) {
            $score += 0.4;
        } elseif ($h1_count > 1) {
            $score += 0.2;
        }

        if ($h2_count >= 2) {
            $score += 0.4;
        } elseif ($h2_count === 1) {
            $score += 0.2;
        }

        if ($h3_count >= 1) {
            $score += 0.2;
        }

        return max(0.0, min(1.0, $score));
    }

    private static function score_word_count(int $word_count): float {
        if ($word_count <= 0) {
            return 0.0;
        }

        if ($word_count < 300) {
            return $word_count / 300;
        }

        if ($word_count <= 1400) {
            return 1.0;
        }

        if ($word_count >= 2200) {
            return 0.7;
        }

        $over = $word_count - 1400;
        return max(0.7, 1.0 - ($over / 800) * 0.3);
    }

    private static function score_entity_coverage(string $text, array $entities): float {
        $normalized = [];
        foreach ($entities as $entity) {
            $entity = trim($entity);
            if ($entity !== '') {
                $normalized[] = $entity;
            }
        }

        $normalized = array_values(array_unique($normalized));
        if (empty($normalized)) {
            return 0.6;
        }

        $matches = 0;
        foreach ($normalized as $entity) {
            if (self::contains_phrase($text, $entity)) {
                $matches++;
            }
        }

        return max(0.0, min(1.0, $matches / count($normalized)));
    }

    private static function score_internal_links(string $html, string $site_host): float {
        preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches);
        $hrefs = isset($matches[1]) && is_array($matches[1]) ? $matches[1] : [];

        if (empty($hrefs)) {
            return 0.0;
        }

        $internal = 0;
        foreach ($hrefs as $href) {
            $href = trim((string) $href);
            if ($href === '') {
                continue;
            }

            if (strpos($href, '/') === 0 && strpos($href, '//') !== 0) {
                $internal++;
                continue;
            }

            $host = (string) wp_parse_url($href, PHP_URL_HOST);
            if ($host !== '' && $site_host !== '' && $host === $site_host) {
                $internal++;
            }
        }

        if ($internal >= 3) {
            return 1.0;
        }

        return max(0.0, min(1.0, $internal / 3));
    }

    private static function score_readability(string $text): float {
        $text = trim($text);
        if ($text === '') {
            return 0.0;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $text);
        $sentences = is_array($sentences) ? array_values(array_filter(array_map('trim', $sentences))) : [];

        $word_count = self::count_words($text);
        if ($word_count === 0) {
            return 0.0;
        }

        $sentence_count = max(1, count($sentences));
        $avg_sentence_length = $word_count / $sentence_count;

        if ($avg_sentence_length <= 18) {
            return 1.0;
        }
        if ($avg_sentence_length >= 35) {
            return 0.5;
        }

        $range = 35 - 18;
        return max(0.5, 1.0 - (($avg_sentence_length - 18) / $range) * 0.5);
    }

    private static function contains_phrase(string $text, string $phrase): bool {
        $text = mb_strtolower($text, 'UTF-8');
        $phrase = mb_strtolower(trim($phrase), 'UTF-8');
        if ($phrase === '') {
            return false;
        }

        return mb_stripos($text, $phrase, 0, 'UTF-8') !== false;
    }

    private static function count_words(string $text): int {
        preg_match_all('/\b[\p{L}\p{N}\']+\b/u', $text, $matches);
        return isset($matches[0]) && is_array($matches[0]) ? count($matches[0]) : 0;
    }
}
