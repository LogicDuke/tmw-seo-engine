<?php
namespace TMWSEO\Engine\Services;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Debug\DebugAPIMonitor;
use TMWSEO\Engine\Debug\DebugLogger;

if (!defined('ABSPATH')) { exit; }

class DataForSEO {

    private const API_BASE = 'https://api.dataforseo.com';

    private const FALLBACK_COST_PER_REQUEST = [
        '/v3/dataforseo_labs/google/keyword_suggestions/live' => 0.01,
        '/v3/dataforseo_labs/google/related_keywords/live'    => 0.01,
        '/v3/dataforseo_labs/google/bulk_keyword_difficulty/live' => 0.02,
        '/v3/dataforseo_labs/google/ranked_keywords/live'     => 0.02,
        '/v3/serp/google/organic/live/advanced'               => 0.02,
        '/v3/serp/google/organic/live'                        => 0.02,
    ];

    public static function is_configured(): bool {
        $login = trim((string)Settings::get('dataforseo_login', ''));
        $pass  = trim((string)Settings::get('dataforseo_password', ''));
        return $login !== '' && $pass !== '';
    }

    public static function get_monthly_budget_stats(): array {
        $month = gmdate('Y_m');
        $spend_key = 'tmwseo_dataforseo_spend_' . $month;
        $calls_key = 'tmwseo_dataforseo_calls_' . $month;

        $budget = (float) Settings::get('tmwseo_dataforseo_budget_usd', 20.0);
        $spent = (float) get_option($spend_key, 0.0);
        $calls = (int) get_option($calls_key, 0);

        return [
            'month' => gmdate('F Y'),
            'budget_usd' => $budget,
            'spent_usd' => round($spent, 6),
            'calls' => $calls,
            'remaining_usd' => $budget > 0 ? max(0.0, round($budget - $spent, 6)) : null,
            'over_budget' => $budget > 0 && $spent >= $budget,
        ];
    }

    public static function is_over_budget(): bool {
        $stats = self::get_monthly_budget_stats();
        return (bool) ($stats['over_budget'] ?? false);
    }


    private static function post(string $path, array $post_array): array {
        // NOTE: Safe Mode does NOT block DataForSEO calls.
        // Safe Mode is intended to prevent auto-actions (publishing/indexing), not analysis.
        if (!self::is_configured()) return ['ok' => false, 'error' => 'dataforseo_credentials_missing'];

        if (self::is_over_budget()) {
            $stats = self::get_monthly_budget_stats();
            Logs::warn('dataforseo', 'Monthly API budget exceeded — request blocked', [
                'path' => $path,
                'budget_usd' => $stats['budget_usd'] ?? 0,
                'spent_usd' => $stats['spent_usd'] ?? 0,
                'calls' => $stats['calls'] ?? 0,
            ]);
            return ['ok' => false, 'error' => 'dataforseo_budget_exceeded'];
        }

        $url = rtrim(self::API_BASE, '/') . '/' . ltrim($path, '/');

        $login = (string) Settings::get('dataforseo_login', '');
        $pass  = (string) Settings::get('dataforseo_password', '');

        // Use WP HTTP API-native request args for Basic Auth compatibility on hosts/proxies that alter handcrafted auth headers.
        $args = [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body' => wp_json_encode($post_array),
            'data_format' => 'body',
            'sslverify' => true,
        ];

        $auth_header = 'Basic ' . base64_encode(trim($login) . ':' . trim($pass));
        $args['headers']['Authorization'] = $auth_header;

        DebugLogger::log_api_request([
            'service' => 'dataforseo',
            'path' => $path,
            'request' => $post_array,
        ]);

        $started_at = microtime(true);
        $resp = wp_remote_post($url, $args);

        if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 401) {
            $args['headers'] = [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ];
            $args['body'] = wp_json_encode($post_array);
            $args['sslverify'] = true;
            $args['timeout'] = 30;
            $args['redirection'] = 0;
            $args['headers']['Authorization'] = $auth_header;

            $started_at = microtime(true);
        $resp = wp_remote_post($url, $args);
        }

        if (is_wp_error($resp)) {
            DebugLogger::log_errors([
                'path' => $path,
                'error' => $resp->get_error_message(),
            ]);
            DebugLogger::log_api_request([
                'service' => 'dataforseo',
                'path' => $path,
                'error' => $resp->get_error_message(),
            ]);
            Logs::error('dataforseo', 'WP error', ['error' => $resp->get_error_message(), 'path' => $path]);
            return ['ok' => false, 'error' => $resp->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw  = (string) wp_remote_retrieve_body($resp);
        $json = json_decode($raw, true);

        if ($code < 200 || $code >= 300 || !is_array($json)) {
            DebugLogger::log_errors([
                'path' => $path,
                'code' => $code,
                'body' => substr($raw, 0, 300),
            ]);
            Logs::error('dataforseo', 'Bad response', ['code' => $code, 'path' => $path, 'body' => substr($raw, 0, 500)]);
            DebugLogger::log_api_request([
                'service' => 'dataforseo',
                'path' => $path,
                'http_code' => $code,
                'response' => is_array($json) ? $json : substr($raw, 0, 2000),
                'error' => 'bad_response',
            ]);
            return ['ok' => false, 'error' => 'bad_response', 'code' => $code, 'body' => $raw];
        }

        if (!empty($json['status_code']) && (int)$json['status_code'] !== 20000) {
            Logs::warn('dataforseo', 'Non-20000 status', [
                'status_code' => $json['status_code'],
                'http_code' => $code,
                'msg' => $json['status_message'] ?? '',
                'path' => $path,
                'body' => substr($raw, 0, 500),
            ]);
            DebugLogger::log_api_request([
                'service' => 'dataforseo',
                'path' => $path,
                'http_code' => $code,
                'status_code' => (int) $json['status_code'],
                'status_message' => (string) ($json['status_message'] ?? ''),
                'response' => $json,
            ]);
        }

        $tasks = $json['tasks'] ?? null;
        if (!is_array($tasks) || empty($tasks)) {
            Logs::error('dataforseo', 'Empty tasks response', ['path' => $path, 'body' => substr($raw, 0, 500)]);
            DebugLogger::log_api_request([
                'service' => 'dataforseo',
                'path' => $path,
                'http_code' => $code,
                'response' => $json,
                'error' => 'empty_tasks',
            ]);
            return ['ok' => false, 'error' => 'empty_tasks', 'code' => $code, 'data' => $json];
        }

        $response_time_ms = (microtime(true) - $started_at) * 1000;
        $keywords_returned = self::extract_keywords_count($json);
        DebugAPIMonitor::record_request($path, $code, $response_time_ms, $keywords_returned);
        DebugLogger::log_api_request([
            'service' => 'dataforseo',
            'path' => $path,
            'http_code' => $code,
            'response' => $json,
        ]);

        self::record_request_cost($path, $json);

        return ['ok' => true, 'data' => $json];
    }

    private static function record_request_cost(string $path, array $json): void {
        $month = gmdate('Y_m');
        $spend_key = 'tmwseo_dataforseo_spend_' . $month;
        $calls_key = 'tmwseo_dataforseo_calls_' . $month;

        $cost = self::extract_response_cost($json);
        if ($cost <= 0) {
            $cost = self::FALLBACK_COST_PER_REQUEST[$path] ?? 0.01;
        }

        $spent = (float) get_option($spend_key, 0.0);
        $calls = (int) get_option($calls_key, 0);

        update_option($spend_key, round($spent + $cost, 6), false);
        update_option($calls_key, $calls + 1, false);
    }

    private static function extract_response_cost(array $json): float {
        $response_cost = isset($json['cost']) ? (float) $json['cost'] : 0.0;
        if ($response_cost > 0) {
            return $response_cost;
        }

        $tasks = $json['tasks'] ?? [];
        if (is_array($tasks)) {
            foreach ($tasks as $task) {
                if (!is_array($task)) {
                    continue;
                }
                $task_cost = isset($task['cost']) ? (float) $task['cost'] : 0.0;
                if ($task_cost > 0) {
                    return $task_cost;
                }
            }
        }

        return 0.0;
    }

    private static function loc_code(): int {
        return (int) Settings::get('dataforseo_location_code', 2840);
    }

    private static function lang_code(): string {
        return (string) Settings::get('dataforseo_language_code', 'en');
    }

    private static function extract_keywords_count(array $json): int {
        $items = $json['tasks'][0]['result'][0]['items'] ?? [];
        if (!is_array($items)) {
            return 0;
        }

        return count($items);
    }

    private static function keyword_metrics_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmwseo_keywords';
    }

    /**
     * @param string[] $keywords
     * @return array<string,array<string,mixed>>
     */
    private static function get_recent_keyword_metrics(array $keywords, int $max_age_days = 30): array {
        global $wpdb;

        $keywords = array_values(array_unique(array_filter(array_map('strval', $keywords))));
        if (empty($keywords)) {
            return [];
        }

        $table = self::keyword_metrics_table();
        $placeholders = implode(',', array_fill(0, count($keywords), '%s'));
        $since = gmdate('Y-m-d H:i:s', time() - ($max_age_days * DAY_IN_SECONDS));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT keyword, search_volume, difficulty, serp_score, last_checked, source
                 FROM {$table}
                 WHERE keyword IN ({$placeholders})
                   AND last_checked >= %s",
                ...array_merge($keywords, [$since])
            ),
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $kw = isset($row['keyword']) ? (string) $row['keyword'] : '';
            if ($kw === '') {
                continue;
            }
            $map[$kw] = $row;
        }

        return $map;
    }

    private static function upsert_keyword_metric(string $keyword, ?int $search_volume, ?float $difficulty, ?float $serp_score, string $source): void {
        global $wpdb;

        $keyword = trim($keyword);
        if ($keyword === '') {
            return;
        }

        $table = self::keyword_metrics_table();
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (keyword, search_volume, difficulty, serp_score, last_checked, source)
                 VALUES (%s, %d, %f, %f, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    search_volume = VALUES(search_volume),
                    difficulty = VALUES(difficulty),
                    serp_score = VALUES(serp_score),
                    last_checked = VALUES(last_checked),
                    source = VALUES(source)",
                $keyword,
                (int) ($search_volume ?? 0),
                (float) ($difficulty ?? 0.0),
                (float) ($serp_score ?? 0.0),
                current_time('mysql', true),
                $source
            )
        );
    }

    public static function keyword_suggestions(string $seed_keyword, int $limit = 200): array {
        $keyword = mb_strtolower(trim($seed_keyword), 'UTF-8');
        $cache_key = 'tmwseo_kw_expand_' . md5($keyword);
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['keyword_suggestions']) && is_array($cached['keyword_suggestions'])) {
            return $cached['keyword_suggestions'];
        }

        $payload = [[
            'keyword' => $keyword,
            'location_code' => self::loc_code(),
            'language_code' => self::lang_code(),
            'include_seed_keyword' => true,
            'include_serp_info' => false,
            'include_clickstream_data' => false,
            'limit' => min(1000, max(10, $limit)),
        ]];

        $res = self::post('/v3/dataforseo_labs/google/keyword_suggestions/live', $payload);
        if (!$res['ok']) return $res;

        $items = $res['data']['tasks'][0]['result'][0]['items'] ?? [];
        if (!is_array($items)) $items = [];
        $result = ['ok' => true, 'items' => $items, 'raw' => $res['data']];

        $cache_payload = is_array($cached) ? $cached : [];
        $cache_payload['keyword_suggestions'] = $result;
        set_transient($cache_key, $cache_payload, WEEK_IN_SECONDS);

        return $result;
    }

    public static function related_keywords(string $seed_keyword, int $depth = 1, int $limit = 200): array {
        $keyword = mb_strtolower(trim($seed_keyword), 'UTF-8');
        $cache_key = 'tmwseo_kw_expand_' . md5($keyword);
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['related_keywords']) && is_array($cached['related_keywords'])) {
            return $cached['related_keywords'];
        }

        $payload = [[
            'keyword' => $keyword,
            'location_code' => self::loc_code(),
            'language_code' => self::lang_code(),
            'depth' => max(1, min(4, $depth)),
            'include_serp_info' => false,
            'include_clickstream_data' => false,
            'limit' => min(5000, max(10, $limit)),
        ]];

        $res = self::post('/v3/dataforseo_labs/google/related_keywords/live', $payload);
        if (!$res['ok']) return $res;

        $items = $res['data']['tasks'][0]['result'][0]['items'] ?? [];
        if (!is_array($items)) $items = [];
        $result = ['ok' => true, 'items' => $items, 'raw' => $res['data']];

        $cache_payload = is_array($cached) ? $cached : [];
        $cache_payload['related_keywords'] = $result;
        set_transient($cache_key, $cache_payload, WEEK_IN_SECONDS);

        return $result;
    }

    /**
     * @return array ok=true, map=[keyword => kd]
     */
    public static function bulk_keyword_difficulty(array $keywords): array {
        $keywords = array_values(array_unique(array_filter(array_map('strval', $keywords))));
        $keywords = array_slice($keywords, 0, 1000);

        $normalized_keywords = array_map(fn($k) => mb_strtolower($k, 'UTF-8'), $keywords);
        $cache_key = 'tmwseo_kd_' . md5(implode(',', $normalized_keywords));
        $difficulty = get_transient($cache_key);
        if ($difficulty !== false && is_array($difficulty)) {
            return $difficulty;
        }

        $recent_metrics = self::get_recent_keyword_metrics($normalized_keywords, 30);
        $map = [];
        $to_lookup = [];

        foreach ($normalized_keywords as $keyword) {
            $cached_metric = $recent_metrics[$keyword] ?? null;
            if (is_array($cached_metric) && isset($cached_metric['difficulty']) && $cached_metric['difficulty'] !== null) {
                $map[$keyword] = (float) $cached_metric['difficulty'];
                continue;
            }
            $to_lookup[] = $keyword;
        }

        if (empty($to_lookup)) {
            $result = ['ok' => true, 'map' => $map, 'raw' => []];
            set_transient($cache_key, $result, 30 * DAY_IN_SECONDS);
            return $result;
        }

        $payload = [[
            'location_code' => self::loc_code(),
            'language_code' => self::lang_code(),
            'keywords' => $to_lookup,
        ]];

        $res = self::post('/v3/dataforseo_labs/google/bulk_keyword_difficulty/live', $payload);
        if (!$res['ok']) return $res;

        $items = $res['data']['tasks'][0]['result'][0]['items'] ?? [];
        if (is_array($items)) {
            foreach ($items as $it) {
                $kw = $it['keyword'] ?? null;
                if (!is_string($kw) || $kw === '') continue;
                $kd = $it['keyword_difficulty'] ?? null;
                if ($kd === null) continue;
                $normalized_keyword = mb_strtolower($kw, 'UTF-8');
                $map[$normalized_keyword] = (float)$kd;
                self::upsert_keyword_metric($normalized_keyword, null, (float) $kd, null, 'dataforseo');
            }
        }

        $result = ['ok' => true, 'map' => $map, 'raw' => $res['data']];
        set_transient($cache_key, $result, 30 * DAY_IN_SECONDS);

        return $result;
    }

    /**
     * Pull keywords a domain ranks for (competitor analysis).
     * NOTE: DataForSEO expects domain without scheme/www.
     */
    public static function ranked_keywords(string $domain, int $limit = 200): array {
        $domain = preg_replace('#^https?://#i', '', trim($domain));
        $domain = preg_replace('#^www\.#i', '', $domain);
        $domain = preg_replace('#/.*$#', '', $domain);

        // FIX: Added 24-hour transient cache. Previously this method had no cache and hit the
        // live API on every call (daily keyword cycle + weekly competitor scan), causing
        // uncontrolled spend at $0.02/call.
        $cache_key = 'tmwseo_rk_' . md5( $domain . self::loc_code() . self::lang_code() . $limit );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false && is_array( $cached ) ) {
            return $cached;
        }

        $payload = [[
            'target' => $domain,
            'location_code' => self::loc_code(),
            'language_code' => self::lang_code(),
            'limit' => min(1000, max(10, $limit)),
            'ignore_synonyms' => true,
        ]];

        $res = self::post('/v3/dataforseo_labs/google/ranked_keywords/live', $payload);
        if (!$res['ok']) return $res;

        $items = $res['data']['tasks'][0]['result'][0]['items'] ?? [];
        if (!is_array($items)) $items = [];
        $result = ['ok' => true, 'items' => $items, 'raw' => $res['data']];

        set_transient( $cache_key, $result, DAY_IN_SECONDS );
        return $result;
    }

    /**
     * Search volume enrichment (Google Ads keywords_data endpoint).
     *
     * @param string[] $keywords
     * @return array ok=true, map=[keyword => ['search_volume'=>int|null]]
     */
    public static function search_volume(array $keywords): array {
        $keywords = array_values(array_unique(array_filter(array_map('strval', $keywords))));
        $keywords = array_slice($keywords, 0, 1000);

        // FIX: Added 7-day transient cache. Previously had no caching — every call hit the
        // live Google Ads API at cost. Search volumes change monthly at most.
        $normalized = array_map( fn($k) => mb_strtolower($k, 'UTF-8'), $keywords );
        $cache_key  = 'tmwseo_sv_' . md5( implode( ',', $normalized ) . self::loc_code() . self::lang_code() );
        $cached     = get_transient( $cache_key );
        if ( $cached !== false && is_array( $cached ) ) {
            return $cached;
        }

        $payload = [[
            'keywords' => $normalized,
            'location_code' => self::loc_code(),
            'language_code' => self::lang_code(),
            'include_adult_keywords' => true,
        ]];

        $res = self::post('/v3/keywords_data/google_ads/search_volume/live', $payload);
        if (!$res['ok']) return $res;

        $items = $res['data']['tasks'][0]['result'][0]['items'] ?? [];
        $map = [];
        if (is_array($items)) {
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $kw = $it['keyword'] ?? null;
                if (!is_string($kw) || $kw === '') continue;

                $sv = null;
                if (isset($it['search_volume'])) {
                    $sv = (int) $it['search_volume'];
                } elseif (isset($it['keyword_info']['search_volume'])) {
                    $sv = (int) $it['keyword_info']['search_volume'];
                }

                $map[$kw] = [
                    'search_volume' => $sv,
                ];
            }
        }

        $result = ['ok' => true, 'map' => $map, 'raw' => $res['data']];
        set_transient( $cache_key, $result, 7 * DAY_IN_SECONDS );
        return $result;
    }

    // ── NEW: SERP Live (feeds SerpWeaknessEngine with real data) ──────────

    /**
     * Fetches live SERP results for a keyword.
     *
     * @return array{ok:bool, items:array}
     */
    public static function serp_live(string $keyword, int $depth = 10): array {
        $keyword = mb_strtolower(trim($keyword), 'UTF-8');
        if ($keyword === '') return ['ok' => false, 'error' => 'empty_keyword'];

        // Transient cache: SERP data valid for 24 hours
        $cache_key = 'tmwseo_serp_' . md5($keyword . self::loc_code() . self::lang_code());
        $cached    = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $payload = [[
            'keyword'       => $keyword,
            'location_code' => self::loc_code(),
            'language_code' => self::lang_code(),
            'depth'         => min(100, max(10, $depth)),
        ]];

        $res = self::post('/v3/serp/google/organic/live/advanced', $payload);
        if (!$res['ok']) return $res;

        $items = $res['data']['tasks'][0]['result'][0]['items'] ?? [];
        if (!is_array($items)) $items = [];

        // Normalise to what SerpWeaknessEngine expects
        $normalised = [];
        foreach ($items as $item) {
            if (($item['type'] ?? '') !== 'organic') continue;
            $url = (string) ($item['url'] ?? '');
            $host = (string) parse_url($url, PHP_URL_HOST);
            if ($host === '') {
                $host = (string) ($item['domain'] ?? '');
            }
            $host = strtolower(preg_replace('/^www\./i', '', $host));

            $content_length = 0;
            if (isset($item['ranked_serp_element']['word_count'])) {
                $content_length = (int) $item['ranked_serp_element']['word_count'];
            } elseif (isset($item['word_count'])) {
                $content_length = (int) $item['word_count'];
            }

            $normalised[] = [
                'domain'         => $host,
                'url'            => $item['url'] ?? '',
                'title'          => $item['title'] ?? '',
                'snippet'        => $item['description'] ?? '',
                'domain_rank'    => (float) ($item['domain_info']['domain_rank'] ?? $item['domain_rank'] ?? 50),
                'domain_rating'  => (float) ($item['domain_info']['domain_rank'] ?? $item['domain_rank'] ?? 50), // backward compat
                'age_days'       => isset($item['timestamp']) ? (int) ((time() - strtotime((string)$item['timestamp'])) / 86400) : 0,
                'heading_count'  => 0, // not available at this endpoint
                'faq_count'      => 0,
                'position'       => (int) ($item['rank_absolute'] ?? 0),
                'content_length' => $content_length,
                'word_count'     => $content_length, // backward compat
            ];
        }

        $result = ['ok' => true, 'items' => $normalised, 'raw' => $res['data']];
        set_transient($cache_key, $result, DAY_IN_SECONDS);
        return $result;
    }

    /**
     * Fetches live Google organic SERP results from /v3/serp/google/organic/live.
     * Cached for 24 hours.
     *
     * @return array{ok:bool,items?:array,raw?:array,error?:string}
     */
    public static function serp_organic_live(string $keyword, int $depth = 10): array {
        $keyword = mb_strtolower(trim($keyword), 'UTF-8');
        if ($keyword === '') {
            return ['ok' => false, 'error' => 'empty_keyword'];
        }

        $cache_key = 'tmwseo_serp_org_live_' . md5($keyword . self::loc_code() . self::lang_code() . $depth);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $payload = [[
            'keyword'       => $keyword,
            'location_code' => self::loc_code(),
            'language_code' => self::lang_code(),
            'depth'         => min(100, max(10, $depth)),
        ]];

        $res = self::post('/v3/serp/google/organic/live', $payload);
        if (!$res['ok']) {
            return $res;
        }

        $items = $res['data']['tasks'][0]['result'][0]['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        $normalised = [];
        foreach ($items as $item) {
            if (($item['type'] ?? '') !== 'organic') {
                continue;
            }

            $url = (string) ($item['url'] ?? '');
            $host = (string) parse_url($url, PHP_URL_HOST);
            $normalised[] = [
                'position' => (int) ($item['rank_absolute'] ?? 0),
                'url' => $url,
                'domain' => strtolower((string) preg_replace('/^www\./i', '', $host)),
                'title' => (string) ($item['title'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
            ];
        }

        $result = ['ok' => true, 'items' => $normalised, 'raw' => $res['data']];
        set_transient($cache_key, $result, DAY_IN_SECONDS);
        return $result;
    }

    // ── NEW: Domain intersection (keywords two domains share) ─────────────

    /**
     * Keywords that both domain1 and domain2 rank for — easiest competitor gaps.
     */
    public static function domain_intersection(string $domain1, string $domain2, int $limit = 200): array {
        foreach ([$domain1, $domain2] as &$d) {
            $d = preg_replace('#^https?://#i', '', trim($d));
            $d = preg_replace('#^www\.#i', '', $d);
            $d = preg_replace('#/.*$#', '', $d);
        }
        unset($d);

        // FIX: Added 24-hour transient cache. Previously had no cache — every weekly competitor
        // scan hit the live API at cost. Domain keyword overlap does not change daily.
        $cache_key = 'tmwseo_di_' . md5( $domain1 . '|' . $domain2 . self::loc_code() . self::lang_code() . $limit );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false && is_array( $cached ) ) {
            return $cached;
        }

        $payload = [[
            'target1'       => $domain1,
            'target2'       => $domain2,
            'location_code' => self::loc_code(),
            'language_code' => self::lang_code(),
            'limit'         => min(1000, max(10, $limit)),
        ]];

        $res = self::post('/v3/dataforseo_labs/google/domain_intersection/live', $payload);
        if (!$res['ok']) return $res;

        $items = $res['data']['tasks'][0]['result'][0]['items'] ?? [];
        if (!is_array($items)) $items = [];
        $result = ['ok' => true, 'items' => $items, 'raw' => $res['data']];

        set_transient( $cache_key, $result, DAY_IN_SECONDS );
        return $result;
    }

    // ── NEW: Backlinks summary (domain authority) ─────────────────────────

    /**
     * Returns domain authority metrics for a domain.
     *
     * @return array{ok:bool, domain_rank:int, backlinks:int, referring_domains:int}
     */
    public static function backlinks_summary(string $domain): array {
        $domain = preg_replace('#^https?://#i', '', trim($domain));
        $domain = preg_replace('#^www\.#i', '', $domain);
        $domain = preg_replace('#/.*$#', '', $domain);

        $cache_key = 'tmwseo_bl_summ_' . md5($domain);
        $cached    = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) return $cached;

        $payload = [[
            'target'             => $domain,
            'include_subdomains' => true,
        ]];

        $res = self::post('/v3/backlinks/summary/live', $payload);
        if (!$res['ok']) return $res;

        $data = $res['data']['tasks'][0]['result'][0] ?? [];
        $result = [
            'ok'                => true,
            'domain_rank'       => (int) ($data['rank'] ?? 0),
            'backlinks'         => (int) ($data['backlinks'] ?? 0),
            'referring_domains' => (int) ($data['referring_domains'] ?? 0),
            'broken_backlinks'  => (int) ($data['broken_backlinks'] ?? 0),
            'raw'               => $data,
        ];

        set_transient($cache_key, $result, DAY_IN_SECONDS);
        return $result;
    }

    // ── NEW: Historical search volume ─────────────────────────────────────

    /**
     * Backward-compatible alias of search_volume().
     *
     * @param string[] $keywords
     * @return array{ok:bool, map:array}
     */
    public static function historical_search_volume(array $keywords): array {
        return self::search_volume($keywords);
    }
}
