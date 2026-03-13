<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class TopicalRelevanceFilter {
    /** @var string[] */
    private const TOPIC_AUTHORITY = [
        'webcam',
        'camgirl',
        'cam model',
        'cam site',
        'adult webcam',
        'live cam',
        'webcam earnings',
        'cam platform',
        'webcam tips',
        'adult streaming',
    ];

    /** @var string[] */
    private const COMPETITOR_DOMAINS = [
        'chaturbate.com',
        'stripchat.com',
        'camsoda.com',
        'cam4.com',
        'bongacams.com',
        'adultwebcamplatforms.com',
        'onlyfans.com',
    ];

    /** @var string[] */
    private const DEFAULT_BLACKLIST = [
        'tattoo',
        'heels',
        'shoes',
        'lyrics',
        'bank',
        'drug',
        'crime',
        'missing',
        'celebrity',
        'makeup',
        'shopping',
    ];

    /**
     * @param array<string,mixed> $context
     * @return array{allowed:bool,score:int,reasons:array<int,string>,similarity:int,authority_match:bool}
     */
    public static function evaluate(string $keyword, array $context = []): array {
        $normalized_keyword = self::normalize($keyword);
        $score = 0;
        $reasons = [];

        $authority_match = self::contains_authority_phrase($normalized_keyword);
        if ($authority_match) {
            $score += 2;
        } else {
            $score -= 1;
            $reasons[] = 'no authority phrase match';
        }

        $blacklist_match = self::blacklist_match($normalized_keyword);
        if ($blacklist_match !== '') {
            $score -= 5;
            $reasons[] = sprintf('blacklist (%s)', $blacklist_match);
        }

        if (self::word_count($normalized_keyword) < 3) {
            $score -= 1;
            $reasons[] = 'keyword length < 3 words';
        }

        $serp_domains = self::extract_serp_domains($context);
        if (!empty($serp_domains)) {
            if (self::contains_competitor_domain($serp_domains)) {
                $score += 2;
            } else {
                $score -= 2;
                $reasons[] = 'serp domain mismatch';
            }
        }

        $similarity = self::similarity_score($normalized_keyword, (string) ($context['entity_name'] ?? ''));
        if ($similarity < 1) {
            $reasons[] = 'topic similarity below threshold';
        }

        return [
            'allowed' => $score >= 2,
            'score' => $score,
            'reasons' => $reasons,
            'similarity' => $similarity,
            'authority_match' => $authority_match,
        ];
    }

    public static function should_expand(string $keyword): bool {
        return self::similarity_score(self::normalize($keyword), '') >= 1;
    }

    public static function log_rejection(string $keyword, array $evaluation): void {
        $reason = implode('; ', (array) ($evaluation['reasons'] ?? []));
        if ($reason === '') {
            $reason = 'insufficient relevance score';
        }

        Logs::debug('keywords', sprintf('Rejected keyword "%s" — reason: %s', $keyword, $reason), [
            'keyword' => $keyword,
            'score' => (int) ($evaluation['score'] ?? 0),
            'similarity' => (int) ($evaluation['similarity'] ?? 0),
            'reasons' => (array) ($evaluation['reasons'] ?? []),
        ]);
    }

    /** @return string[] */
    public static function topic_authority(): array {
        return self::TOPIC_AUTHORITY;
    }

    private static function normalize(string $keyword): string {
        return strtolower(trim(preg_replace('/\s+/u', ' ', $keyword) ?? $keyword));
    }

    private static function word_count(string $keyword): int {
        $parts = preg_split('/\s+/u', trim($keyword));
        return is_array($parts) ? count(array_filter($parts, 'strlen')) : 0;
    }

    private static function contains_authority_phrase(string $keyword): bool {
        foreach (self::TOPIC_AUTHORITY as $phrase) {
            if ($phrase !== '' && str_contains($keyword, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private static function blacklist_match(string $keyword): string {
        foreach (self::blacklist_words() as $word) {
            if ($word !== '' && str_contains($keyword, $word)) {
                return $word;
            }
        }

        return '';
    }

    /** @return string[] */
    private static function blacklist_words(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'tmw_keyword_blacklist';
        $table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;

        if (!$table_exists) {
            return self::DEFAULT_BLACKLIST;
        }

        $words = (array) $wpdb->get_col("SELECT keyword FROM {$table}");
        $words = array_values(array_unique(array_filter(array_map(static function ($word) {
            return self::normalize((string) $word);
        }, $words))));

        return !empty($words) ? $words : self::DEFAULT_BLACKLIST;
    }

    /**
     * @param array<string,mixed> $context
     * @return string[]
     */
    private static function extract_serp_domains(array $context): array {
        $domains = [];
        $items = (array) ($context['serp_items'] ?? []);
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $url = (string) ($item['url'] ?? $item['target'] ?? '');
            if ($url === '') {
                continue;
            }

            $domain = wp_parse_url($url, PHP_URL_HOST);
            if (is_string($domain) && $domain !== '') {
                $domains[] = strtolower(preg_replace('/^www\./', '', $domain));
            }
            if (count($domains) >= 10) {
                break;
            }
        }

        return $domains;
    }

    /** @param string[] $domains */
    private static function contains_competitor_domain(array $domains): bool {
        foreach ($domains as $domain) {
            foreach (self::COMPETITOR_DOMAINS as $competitor) {
                if ($domain === $competitor || str_ends_with($domain, '.' . $competitor)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function similarity_score(string $keyword, string $entity_name = ''): int {
        $keyword_words = self::tokenize($keyword);
        if (empty($keyword_words)) {
            return 0;
        }

        $best_overlap = 0;
        $authority_pool = self::TOPIC_AUTHORITY;
        $entity_name = self::normalize($entity_name);
        if ($entity_name !== '') {
            $authority_pool[] = $entity_name;
        }

        foreach ($authority_pool as $phrase) {
            $authority_words = self::tokenize($phrase);
            $overlap = count(array_intersect($keyword_words, $authority_words));
            if ($overlap > $best_overlap) {
                $best_overlap = $overlap;
            }
        }

        return $best_overlap;
    }

    /** @return string[] */
    private static function tokenize(string $text): array {
        $parts = preg_split('/\s+/u', self::normalize($text));
        if (!is_array($parts)) {
            return [];
        }

        return array_values(array_filter($parts, 'strlen'));
    }
}
