<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class TopicalRelevanceFilter {
    /**
     * On-lane adult/cam authority phrases.
     * Phrase present in keyword => +2 score.  Absence => -1.
     *
     * @var string[]
     */
    private const TOPIC_AUTHORITY = [
        // Core cam/webcam identity
        'webcam',
        'web cam',
        'cam',
        'cam girl',
        'cam girls',
        'cam model',
        'cam models',
        'cam site',
        'camgirl',
        // Live-cam modifiers
        'live cam',
        'live cams',
        'live cam show',
        'live adult cam',
        'adult cam',
        'adult cams',
        'adult webcam',
        'adult webcam chat',
        'adult live cam',
        // Chat formats
        'webcam chat',
        'cam chat',
        'live chat',
        'adult chat',
        'video chat',
        'live video chat',
        'adult video chat',
        // Platform / earnings context (adult video chat family stays on-lane)
        'webcam platform',
        'webcam earnings',
        'cam platform',
        'webcam tips',
        'adult streaming',
        'sex cam',
        'sex cams',
    ];

    /**
     * Patterns that strongly signal generic-information queries not relevant to
     * this site.  Whole-word matched against the normalized keyword.
     * Each match applies a -2 penalty.
     *
     * @var string[]
     */
    private const GENERIC_INFO_PATTERNS = [
        'meaning',
        'means',
        'how old',
        'age',
        'net worth',
        'ethnicity',
        'birthday',
        'movies',
        'wikipedia',
        'wiki',
        'biography',
        'bio',
        'husband',
        'boyfriend',
        'girlfriend',
        'spouse',
        'nationality',
        'height',
        'weight',
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
     * Minimum score required for a phrase to be allowed.
     * Generic-info pattern penalties (-2 each) and similarity penalty (-2) ensure
     * off-lane phrases fall below this threshold without requiring a score so high
     * that legitimate 2-word cam phrases (e.g. "arianna webcam") are blocked.
     */
    private const ALLOW_THRESHOLD = 2;

    /**
     * @param array<string,mixed> $context
     * @return array{allowed:bool,score:int,reasons:array<int,string>,similarity:int,authority_match:bool}
     */
    public static function evaluate(string $keyword, array $context = []): array {
        $normalized_keyword = self::normalize($keyword);
        $score = 0;
        $reasons = [];

        // ── Authority phrase check ────────────────────────────────
        $authority_match = self::contains_authority_phrase($normalized_keyword);
        if ($authority_match) {
            $score += 2;
        } else {
            $score -= 1;
            $reasons[] = 'no authority phrase match';
        }

        // ── Blacklist check ───────────────────────────────────────
        $blacklist_match = self::blacklist_match($normalized_keyword);
        if ($blacklist_match !== '') {
            $score -= 5;
            $reasons[] = sprintf('blacklist (%s)', $blacklist_match);
        }

        // ── Generic-info penalty ──────────────────────────────────
        // Phrases matching generic-information patterns (e.g. "arianna meaning",
        // "arianna net worth") are not commercially relevant for this site.
        $generic_hits = self::generic_info_penalty($normalized_keyword);
        if ($generic_hits > 0) {
            $score -= $generic_hits * 2;
            $reasons[] = sprintf('generic info pattern penalty (-%d)', $generic_hits * 2);
        }

        // ── Word-count penalty ────────────────────────────────────
        // Only penalise short phrases that lack an authority signal — a 2-word
        // phrase like "arianna webcam" already has authority and shouldn't be docked.
        if (self::word_count($normalized_keyword) < 3 && !$authority_match) {
            $score -= 1;
            $reasons[] = 'keyword length < 3 words (no authority match)';
        }

        // ── SERP domain check ─────────────────────────────────────
        $serp_domains = self::extract_serp_domains($context);
        if (!empty($serp_domains)) {
            if (self::contains_competitor_domain($serp_domains)) {
                $score += 2;
            } else {
                $score -= 2;
                $reasons[] = 'serp domain mismatch';
            }
        }

        // ── Topic similarity ──────────────────────────────────────
        // Low similarity now applies a real penalty rather than just a note.
        $similarity = self::similarity_score($normalized_keyword, (string) ($context['entity_name'] ?? ''));
        if ($similarity < 1) {
            $score -= 2;
            $reasons[] = 'topic similarity below threshold (penalty applied)';
        }

        return [
            'allowed'        => $score >= self::ALLOW_THRESHOLD,
            'score'          => $score,
            'reasons'        => $reasons,
            'similarity'     => $similarity,
            'authority_match'=> $authority_match,
        ];
    }

    /**
     * Gate for whether a candidate phrase is worth expanding further.
     * Requires either a clear authority-phrase match OR a score above threshold.
     * Generic-info-only phrases are excluded.
     */
    public static function should_expand(string $keyword): bool {
        $normalized = self::normalize($keyword);

        // Hard-block: phrase is purely a generic-info query with no authority signal.
        if (!self::contains_authority_phrase($normalized) && self::generic_info_penalty($normalized) > 0) {
            return false;
        }

        // Require a meaningful similarity to the niche authority pool.
        return self::similarity_score($normalized, '') >= 2;
    }

    public static function log_rejection(string $keyword, array $evaluation): void {
        $reason = implode('; ', (array) ($evaluation['reasons'] ?? []));
        if ($reason === '') {
            $reason = 'insufficient relevance score';
        }

        Logs::debug('keywords', sprintf('Rejected keyword "%s" — reason: %s', $keyword, $reason), [
            'keyword'    => $keyword,
            'score'      => (int) ($evaluation['score'] ?? 0),
            'similarity' => (int) ($evaluation['similarity'] ?? 0),
            'reasons'    => (array) ($evaluation['reasons'] ?? []),
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

    /**
     * Count how many distinct GENERIC_INFO_PATTERNS match the keyword.
     * Uses whole-word / whole-phrase boundary detection (not naive substring).
     * Returns the number of distinct matches (typically 0 or 1, occasionally 2).
     */
    private static function generic_info_penalty(string $normalized_keyword): int {
        $hits = 0;
        foreach (self::GENERIC_INFO_PATTERNS as $pattern) {
            if ($pattern === '') {
                continue;
            }
            // Use word-boundary regex so "age" does not match "advantage" etc.
            if (preg_match('/\b' . preg_quote($pattern, '/') . '\b/u', $normalized_keyword)) {
                $hits++;
            }
        }
        return $hits;
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
