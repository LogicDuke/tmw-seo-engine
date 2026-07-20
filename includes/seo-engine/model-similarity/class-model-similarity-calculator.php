<?php

if (!defined('ABSPATH')) { exit; }

class TMW_Model_Similarity_Calculator {
    public function calculate_score(array $source, array $candidate): int {
        $score = 0;

        $tags_overlap = $this->jaccard_overlap($source['tags'] ?? [], $candidate['tags'] ?? []);
        $score += (int) round(40 * $tags_overlap);

        $shared_category = !empty($source['category']) && !empty($candidate['category']) && (string) $source['category'] === (string) $candidate['category'];
        if ($shared_category) {
            $score += 20;
        }

        $shared_platform = !empty($source['platform']) && !empty($candidate['platform']) && (string) $source['platform'] === (string) $candidate['platform'];
        if ($shared_platform) {
            $score += 10;
        }

        $keyword_overlap = $this->jaccard_overlap($source['keyword_pack'] ?? [], $candidate['keyword_pack'] ?? []);
        $score += (int) round(10 * $keyword_overlap);

        $bio_similarity = $this->bio_similarity((string) ($source['bio_text'] ?? ''), (string) ($candidate['bio_text'] ?? ''));
        $score += (int) round(10 * $bio_similarity);

        return max(0, min(100, $score));
    }

    private function jaccard_overlap(array $left, array $right): float {
        $left = $this->normalize_tokens($left);
        $right = $this->normalize_tokens($right);

        if (empty($left) || empty($right)) {
            return 0.0;
        }

        $intersection = array_intersect($left, $right);
        $union = array_unique(array_merge($left, $right));

        if (empty($union)) {
            return 0.0;
        }

        return count($intersection) / count($union);
    }

    private function bio_similarity(string $left, string $right): float {
        $left_tokens = $this->tokenize_string($left);
        $right_tokens = $this->tokenize_string($right);

        if (empty($left_tokens) || empty($right_tokens)) {
            return 0.0;
        }

        $intersection = array_intersect($left_tokens, $right_tokens);
        $union = array_unique(array_merge($left_tokens, $right_tokens));

        if (empty($union)) {
            return 0.0;
        }

        return count($intersection) / count($union);
    }

    private function normalize_tokens(array $tokens): array {
        $normalized = [];
        foreach ($tokens as $token) {
            $value = sanitize_title((string) $token);
            if ($value === '') {
                continue;
            }

            $normalized[] = $value;
        }

        return array_values(array_unique($normalized));
    }

    private function tokenize_string(string $text): array {
        $parts = preg_split('/\s+/u', wp_strip_all_tags(strtolower($text)));
        if (!is_array($parts)) {
            return [];
        }

        $tokens = [];
        foreach ($parts as $part) {
            $part = preg_replace('/[^\p{L}\p{N}\-]/u', '', (string) $part);
            if ($part === null || strlen($part) < 3) {
                continue;
            }

            $tokens[] = sanitize_title($part);
        }

        return array_values(array_unique($tokens));
    }
}
