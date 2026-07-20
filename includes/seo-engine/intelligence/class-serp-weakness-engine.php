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
        'medium.com',
        'pinterest.com',
        'tumblr.com',
        'wordpress.com',
        'blogspot.com',
    ];

    /** @var string[] */
    private const AUTHORITY_DOMAINS = [
        'wikipedia.org',
        'forbes.com',
        'nytimes.com',
        'hubspot.com',
        'amazon.com',
        'bbc.com',
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

            if ($this->is_weak_domain($host)) {
                $weak_serp += 1;
                $signals['weak_domains_found']++;
            }

            if ($domain_rank < 40) {
                $weak_serp += 1;
                $signals['low_authority_domains']++;
            }

            if ($this->is_authority_domain($host)) {
                $weak_serp -= 2;
            }

            if ($content_length > 0 && $content_length < 800) {
                $signals['thin_pages_detected']++;
            }

            if ($this->contains_weak_text_signal($row, $host)) {
                $signals['ugc_pages_detected']++;
            }
        }

        $weakness_score = round(max(0, $weak_serp), 4);
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

        Logs::debug('intelligence', sprintf('SERP weakness score for keyword "%s" = %s', $keyword, $weakness_score), [
            'keyword' => $keyword,
            'serp_weakness_score' => $weakness_score,
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

    private function is_authority_domain(string $host): bool {
        foreach (self::AUTHORITY_DOMAINS as $domain) {
            if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) {
                return true;
            }
        }

        return false;
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

        // FIX: Changed from INSERT to INSERT ... ON DUPLICATE KEY UPDATE.
        // Previously every evaluation appended a new row (no UNIQUE key) causing the
        // serp_analysis table to grow unbounded with duplicate keyword rows.
        $table   = IntelligenceStorage::table_serp_analysis();
        $keyword = sanitize_text_field( (string) $result['keyword'] );
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table}
                    (keyword, cluster_id, serp_weakness_score, competitor_count, reason, signals_json, analyzed_at, created_at)
                 VALUES (%s, %d, %f, %d, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    cluster_id          = VALUES(cluster_id),
                    serp_weakness_score = VALUES(serp_weakness_score),
                    competitor_count    = VALUES(competitor_count),
                    reason              = VALUES(reason),
                    signals_json        = VALUES(signals_json),
                    analyzed_at         = VALUES(analyzed_at)",
                $keyword,
                (int) ( $result['cluster_id'] ?? 0 ),
                (float) $result['serp_weakness_score'],
                (int) ( $result['competitor_count'] ?? 0 ),
                sanitize_textarea_field( (string) $result['reason'] ),
                wp_json_encode( (array) $result['signals'] ),
                current_time( 'mysql' ),
                current_time( 'mysql' )
            )
        );

        $keyword_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $keyword_table));
        if ($table_exists === $keyword_table) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$keyword_table} SET serp_weakness = %f, updated_at = %s WHERE keyword = %s",
                (float) $result['serp_weakness_score'],
                current_time('mysql'),
                $keyword
            ));

            $this->update_keyword_opportunity_score($keyword, (float) $result['serp_weakness_score']);
        }
    }

    private function update_keyword_opportunity_score(string $keyword, float $serp_weakness_score): void {
        global $wpdb;

        $keyword_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $ranking_table = IntelligenceStorage::table_ranking_probability();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT kc.volume, rp.ranking_probability
                 FROM {$keyword_table} kc
                 LEFT JOIN {$ranking_table} rp ON rp.keyword = kc.keyword
                 WHERE kc.keyword = %s
                 LIMIT 1",
                $keyword
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            return;
        }

        $search_volume = max(0, (int) ($row['volume'] ?? 0));
        $ranking_probability = max(0.0, (float) ($row['ranking_probability'] ?? 0));

        $opportunity_score = ($search_volume * 0.4)
            * ($serp_weakness_score * 0.4)
            * ($ranking_probability * 0.2);

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$keyword_table}
                 SET opportunity = %f, updated_at = %s
                 WHERE keyword = %s",
                round($opportunity_score, 4),
                current_time('mysql'),
                $keyword
            )
        );
    }
}
