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
        'directory',
        'directories',
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

        $weak_serp = 0.0;
        $competitor_count = count($top);

        foreach ($top as $row) {
            $url = (string) ($row['url'] ?? '');
            $host = $this->extract_host($url);
            $domain_rank = (float) ($row['domain_rank'] ?? $row['domain_rating'] ?? 100);
            $content_length = (int) ($row['content_length'] ?? $row['word_count'] ?? 0);

            if ($this->has_weak_signal($row, $host)) {
                $weak_serp += 2.5;
                $signals['weak_domains_found']++;
            }

            if ($domain_rank < 40) {
                $weak_serp += 1.5;
                $signals['low_authority_domains']++;
            }

            if ($content_length > 0 && $content_length < 800) {
                $weak_serp += 1.5;
                $signals['thin_pages_detected']++;
            }

            if (!$this->keyword_in_title($keyword, (string) ($row['title'] ?? ''))) {
                $weak_serp += 1.0;
            }

            if ($this->contains_weak_text_signal($row, $host)) {
                $signals['ugc_pages_detected']++;
            }
        }

        $weakness_score = round($weak_serp / max(1, $competitor_count), 4);
        $reason   = $this->explain($signals);

        $result = [
            'keyword'              => $keyword,
            'serp_weakness_score'  => $weakness_score,
            'reason'               => $reason,
            'signals'              => $signals,
            'serp_results_count'   => $competitor_count,
            'competitor_count'     => $competitor_count,
            'cluster_id'           => $this->resolve_cluster_id($keyword, $serp_results),
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


    /**
     * @param array<int,array<string,mixed>> $serp_results
     */
    private function resolve_cluster_id(string $keyword, array $serp_results): int {
        if (!empty($serp_results[0]['cluster_id'])) {
            return (int) $serp_results[0]['cluster_id'];
        }

        global $wpdb;
        $map_table = $wpdb->prefix . 'tmw_keyword_cluster_map';
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $map_table));
        if ($table_exists !== $map_table) {
            return 0;
        }

        $cluster_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT cluster_id FROM {$map_table} WHERE keyword = %s LIMIT 1",
            sanitize_text_field($keyword)
        ));

        return max(0, $cluster_id);
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


    private function keyword_in_title(string $keyword, string $title): bool {
        $keyword = mb_strtolower(trim($keyword), 'UTF-8');
        $title = mb_strtolower(trim($title), 'UTF-8');

        if ($keyword === '' || $title === '') {
            return false;
        }

        return strpos($title, $keyword) !== false;
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
                'cluster_id'           => (int) ($result['cluster_id'] ?? 0),
                'serp_weakness_score'  => (float) $result['serp_weakness_score'],
                'competitor_count'     => (int) ($result['competitor_count'] ?? 0),
                'reason'               => sanitize_textarea_field((string) $result['reason']),
                'signals_json'         => wp_json_encode((array) $result['signals']),
                'analyzed_at'          => current_time('mysql'),
                'created_at'           => current_time('mysql'),
            ],
            ['%s', '%d', '%f', '%d', '%s', '%s', '%s', '%s']
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
