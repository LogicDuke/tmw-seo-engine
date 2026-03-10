<?php
/**
 * SerpWeaknessEngine — evaluates SERP weakness for a keyword.
 *
 * v4.2: Now fetches real SERP data from DataForSEO before evaluating.
 * Falls back gracefully if DataForSEO is not configured.
 *
 * @since 4.2.0
 */
namespace TMWSEO\Engine\Intelligence;

use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class SerpWeaknessEngine {

    /** @var string[] */
    private const WEAK_DOMAINS = [
        'reddit.com',
        'quora.com',
        'pinterest.com',
        'youtube.com',
        'medium.com',
        'wordpress.com',
    ];

    /** @var string[] */
    private const WEAK_TEXT_SIGNALS = [
        'forum',
        'forums',
        'board',
        'boards',
    ];

    /**
     * Evaluates SERP weakness for a keyword.
     * Fetches real SERP data if DataForSEO is configured.
     *
     * @param array<int,array<string,mixed>> $serp_results  Optional pre-fetched SERP data.
     *                                                       If empty, will fetch via DataForSEO.
     */
    public function evaluate(string $keyword, array $serp_results = []): array {
        $started = microtime(true);

        // Fetch real SERP data if not provided
        if (empty($serp_results) && DataForSEO::is_configured()) {
            $res = DataForSEO::serp_live($keyword, 10);
            if (($res['ok'] ?? false) && !empty($res['items'])) {
                $serp_results = $res['items'];
            }
        }

        $signals = [
            'weak_domains_found' => 0,
            'low_authority_domains' => 0,
            'thin_pages_detected' => 0,
            'ugc_pages_detected' => 0,
        ];

        $top = array_slice($serp_results, 0, 10);

        if (empty($top)) {
            // No SERP data available — return neutral score
            $result = [
                'keyword' => $keyword,
                'serp_weakness_score' => 0.0,
                'reason' => 'No SERP data available.',
                'signals' => $signals,
                'data_source' => 'none',
                'serp_results_count' => 0,
            ];
            $this->persist($result);
            return $result;
        }

        $weak_serp = 0;

        foreach ($top as $row) {
            $url = (string) ($row['url'] ?? '');
            $host = $this->extract_host($url);
            $domain_rank = (float) ($row['domain_rank'] ?? $row['domain_rating'] ?? 100);
            $word_count = (int) ($row['word_count'] ?? 0);

            if ($this->has_weak_signal($row, $host)) {
                $weak_serp += 2;
                $signals['weak_domains_found']++;
            }

            if ($domain_rank < 40) {
                $weak_serp += 1;
                $signals['low_authority_domains']++;
            }

            if ($word_count > 0 && $word_count < 800) {
                $weak_serp += 1;
                $signals['thin_pages_detected']++;
            }

            if ($this->contains_weak_text_signal($row, $host)) {
                $signals['ugc_pages_detected']++;
            }
        }

        $weakness_score = round($weak_serp / max(1, count($top)), 4);
        $reason   = $this->explain($signals);

        $result = [
            'keyword'              => $keyword,
            'serp_weakness_score'  => $weakness_score,
            'reason'               => $reason,
            'signals'              => $signals,
            'serp_results_count'   => count($top),
            'data_source'          => DataForSEO::is_configured() ? 'dataforseo_live' : 'provided',
        ];

        $this->persist($result);

        Logs::info('intelligence', '[TMW-SERP] SERP weakness evaluated', [
            'keyword'             => $keyword,
            'weak_domains_found'  => $signals['weak_domains_found'],
            'thin_pages_detected' => $signals['thin_pages_detected'],
            'score'               => $weakness_score,
            'source'              => $result['data_source'],
            'duration_ms'         => round((microtime(true) - $started) * 1000, 2),
        ]);

        return $result;
    }

    private function extract_host(string $url): string {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            $host = $url;
        }
        return strtolower(preg_replace('/^www\./i', '', $host));
    }

    private function is_weak_domain(string $host): bool {
        foreach (self::WEAK_DOMAINS as $domain) {
            if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function has_weak_signal(array $row, string $host): bool {
        return $this->is_weak_domain($host) || $this->contains_weak_text_signal($row, $host);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function contains_weak_text_signal(array $row, string $host): bool {
        $haystack = strtolower(trim(implode(' ', [
            $host,
            (string) ($row['url'] ?? ''),
            (string) ($row['title'] ?? ''),
            (string) ($row['snippet'] ?? ''),
        ])));

        foreach (self::WEAK_TEXT_SIGNALS as $signal) {
            if (strpos($haystack, $signal) !== false) {
                return true;
            }
        }

        return false;
    }

    public function explain(array $signals): string {
        return sprintf(
            'Weak domains: %d; low-authority domains: %d; thin pages: %d; UGC domains: %d.',
            (int) ($signals['weak_domains_found'] ?? 0),
            (int) ($signals['low_authority_domains'] ?? 0),
            (int) ($signals['thin_pages_detected'] ?? 0),
            (int) ($signals['ugc_pages_detected'] ?? 0)
        );
    }

    private function persist(array $result): void {
        global $wpdb;
        $wpdb->insert(
            IntelligenceStorage::table_serp_analysis(),
            [
                'keyword'              => sanitize_text_field((string) $result['keyword']),
                'serp_weakness_score'  => (float) $result['serp_weakness_score'],
                'reason'               => sanitize_textarea_field((string) $result['reason']),
                'signals_json'         => wp_json_encode((array) $result['signals']),
                'created_at'           => current_time('mysql'),
            ],
            ['%s', '%f', '%s', '%s', '%s']
        );

        $keyword_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $keyword_table));
        if ($table_exists === $keyword_table) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$keyword_table} SET serp_weakness = %f, updated_at = %s WHERE keyword = %s",
                (float) $result['serp_weakness_score'],
                current_time('mysql'),
                sanitize_text_field((string) $result['keyword'])
            ));
        }
    }
}
