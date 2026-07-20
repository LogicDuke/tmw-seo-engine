<?php
namespace TMWSEO\Engine\Services;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class RankTracker {
    private const MAX_KEYWORDS_PER_RUN = 50;

    public static function run_cycle_job(array $job = []): void {
        global $wpdb;

        $api_key = trim((string) Settings::get('serper_api_key', ''));
        if ($api_key === '') {
            Logs::warn('rank_tracker', '[TMW-RANK] Skipped rank tracking: Serper API key missing.');
            return;
        }

        $keywords = self::collect_tracked_keywords();
        if (empty($keywords)) {
            Logs::info('rank_tracker', '[TMW-RANK] No tracked keywords found.');
            return;
        }

        $site_host = self::normalize_host((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $checked_at = current_time('mysql');
        $table = $wpdb->prefix . 'tmwseo_engine_rank_history';

        $stored = 0;
        foreach ($keywords as $keyword) {
            $results = self::fetch_serp_results($api_key, $keyword);
            [$position, $url] = self::resolve_site_position($results, $site_host);

            $wpdb->insert(
                $table,
                [
                    'keyword' => $keyword,
                    'position' => $position,
                    'url' => $url,
                    'checked_at' => $checked_at,
                ],
                ['%s', '%d', '%s', '%s']
            );

            $stored++;
            usleep(200000);
        }

        Logs::info('rank_tracker', '[TMW-RANK] Weekly rank tracking completed.', [
            'tracked_keywords' => count($keywords),
            'stored_rows' => $stored,
            'trigger' => (string) ($job['type'] ?? 'manual'),
        ]);
    }

    /**
     * @return array<int,string>
     */
    private static function collect_tracked_keywords(): array {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT DISTINCT TRIM(pm.meta_value) AS keyword
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND pm.meta_value IS NOT NULL
               AND TRIM(pm.meta_value) <> ''
               AND p.post_status = 'publish'
             ORDER BY pm.meta_value ASC
             LIMIT %d",
            'rank_math_focus_keyword',
            self::MAX_KEYWORDS_PER_RUN
        );

        $rows = $wpdb->get_col($sql);
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map('sanitize_text_field', $rows), static fn($keyword) => $keyword !== ''));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function fetch_serp_results(string $api_key, string $keyword): array {
        $resp = wp_remote_post('https://google.serper.dev/search', [
            'timeout' => 25,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-KEY' => $api_key,
            ],
            'body' => wp_json_encode([
                'q' => $keyword,
                'gl' => 'us',
                'hl' => 'en',
                'num' => 100,
            ]),
        ]);

        if (is_wp_error($resp)) {
            Logs::warn('rank_tracker', '[TMW-RANK] Serper request failed.', ['keyword' => $keyword, 'error' => $resp->get_error_message()]);
            return [];
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            Logs::warn('rank_tracker', '[TMW-RANK] Serper returned non-2xx response.', ['keyword' => $keyword, 'status' => $code]);
            return [];
        }

        $json = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($json)) {
            return [];
        }

        $organic = $json['organic'] ?? [];
        return is_array($organic) ? $organic : [];
    }

    /**
     * @param array<int,array<string,mixed>> $results
     * @return array{0:int,1:string}
     */
    private static function resolve_site_position(array $results, string $site_host): array {
        if ($site_host === '') {
            return [0, ''];
        }

        foreach ($results as $item) {
            if (!is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['link'] ?? ''));
            $position = (int) ($item['position'] ?? 0);
            if ($url === '' || $position <= 0) {
                continue;
            }

            $result_host = self::normalize_host((string) wp_parse_url($url, PHP_URL_HOST));
            if ($result_host === '' ) {
                continue;
            }

            if ($result_host === $site_host || substr($result_host, -strlen('.' . $site_host)) === '.' . $site_host) {
                return [$position, $url];
            }
        }

        return [0, ''];
    }

    private static function normalize_host(string $host): string {
        $host = strtolower(trim($host));
        $host = preg_replace('/^www\./', '', $host);
        return is_string($host) ? $host : '';
    }

    /**
     * @return array<string,array<int,array{date:string,position:int,url:string}>>
     */
    public static function get_history(int $keyword_limit = 5, int $days = 56): array {
        global $wpdb;

        $keyword_limit = max(1, min(20, $keyword_limit));
        $days = max(7, min(180, $days));

        $table = $wpdb->prefix . 'tmwseo_engine_rank_history';
        $from_date = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        $sql = $wpdb->prepare(
            "SELECT keyword, position, COALESCE(url, '') AS url, checked_at
             FROM {$table}
             WHERE checked_at >= %s
             ORDER BY checked_at ASC",
            $from_date
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $history = [];
        foreach ($rows as $row) {
            $keyword = (string) ($row['keyword'] ?? '');
            if ($keyword === '') {
                continue;
            }

            if (!isset($history[$keyword])) {
                if (count($history) >= $keyword_limit) {
                    continue;
                }
                $history[$keyword] = [];
            }

            $history[$keyword][] = [
                'date' => substr((string) ($row['checked_at'] ?? ''), 0, 10),
                'position' => (int) ($row['position'] ?? 0),
                'url' => (string) ($row['url'] ?? ''),
            ];
        }

        return $history;
    }
}
