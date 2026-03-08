<?php
namespace TMWSEO\Engine\Services;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Debug\DebugAPIMonitor;
use TMWSEO\Engine\Debug\DebugLogger;

if (!defined('ABSPATH')) { exit; }

class DataForSEO {

    private const API_BASE = 'https://api.dataforseo.com';

    public static function is_configured(): bool {
        $login = trim((string)Settings::get('dataforseo_login', ''));
        $pass  = trim((string)Settings::get('dataforseo_password', ''));
        return $login !== '' && $pass !== '';
    }

    private static function post(string $path, array $post_array): array {
        // NOTE: Safe Mode does NOT block DataForSEO calls.
        // Safe Mode is intended to prevent auto-actions (publishing/indexing), not analysis.
        if (!self::is_configured()) return ['ok' => false, 'error' => 'dataforseo_credentials_missing'];

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

        $args['headers']['Authorization'] = 'Basic ' . base64_encode($login . ':' . $pass);
        $args['headers'] = array_merge($args['headers'], [
            'Authorization' => 'Basic ' . base64_encode(trim($login) . ':' . trim($pass)),
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
            $args['headers']['Authorization'] = 'Basic ' . base64_encode($login . ':' . $pass);

            $started_at = microtime(true);
        $resp = wp_remote_post($url, $args);
        }

        if (is_wp_error($resp)) {
            DebugLogger::log_errors([
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
        }

        $response_time_ms = (microtime(true) - $started_at) * 1000;
        $keywords_returned = self::extract_keywords_count($json);
        DebugAPIMonitor::record_request($path, $code, $response_time_ms, $keywords_returned);

        return ['ok' => true, 'data' => $json];
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

    public static function keyword_suggestions(string $seed_keyword, int $limit = 200): array {
        $payload = [[
            'keyword' => mb_strtolower($seed_keyword, 'UTF-8'),
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
        return ['ok' => true, 'items' => $items, 'raw' => $res['data']];
    }

    public static function keyword_ideas(array $seed_keywords, int $limit = 200): array {
        $seed_keywords = array_values(array_filter(array_map('strval', $seed_keywords)));
        $seed_keywords = array_slice($seed_keywords, 0, 200);

        $payload = [[
            'keywords' => array_map(fn($k) => mb_strtolower($k, 'UTF-8'), $seed_keywords),
            'location_code' => self::loc_code(),
            'language_code' => self::lang_code(),
            'include_serp_info' => false,
            'include_clickstream_data' => false,
            'limit' => min(1000, max(10, $limit)),
        ]];

        $res = self::post('/v3/dataforseo_labs/google/keyword_ideas/live', $payload);
        if (!$res['ok']) return $res;

        $items = $res['data']['tasks'][0]['result'][0]['items'] ?? [];
        if (!is_array($items)) $items = [];
        return ['ok' => true, 'items' => $items, 'raw' => $res['data']];
    }

    public static function related_keywords(string $seed_keyword, int $depth = 1, int $limit = 200): array {
        $payload = [[
            'keyword' => mb_strtolower($seed_keyword, 'UTF-8'),
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
        return ['ok' => true, 'items' => $items, 'raw' => $res['data']];
    }

    /**
     * @return array ok=true, map=[keyword => kd]
     */
    public static function bulk_keyword_difficulty(array $keywords): array {
        $keywords = array_values(array_unique(array_filter(array_map('strval', $keywords))));
        $keywords = array_slice($keywords, 0, 1000);

        $payload = [[
            'location_code' => self::loc_code(),
            'language_code' => self::lang_code(),
            'keywords' => array_map(fn($k) => mb_strtolower($k, 'UTF-8'), $keywords),
        ]];

        $res = self::post('/v3/dataforseo_labs/google/bulk_keyword_difficulty/live', $payload);
        if (!$res['ok']) return $res;

        $items = $res['data']['tasks'][0]['result'][0]['items'] ?? [];
        $map = [];
        if (is_array($items)) {
            foreach ($items as $it) {
                $kw = $it['keyword'] ?? null;
                if (!is_string($kw) || $kw === '') continue;
                $kd = $it['keyword_difficulty'] ?? null;
                if ($kd === null) continue;
                $map[$kw] = (float)$kd;
            }
        }

        return ['ok' => true, 'map' => $map, 'raw' => $res['data']];
    }

    /**
     * Pull keywords a domain ranks for (competitor analysis).
     * NOTE: DataForSEO expects domain without scheme/www.
     */
    public static function ranked_keywords(string $domain, int $limit = 200): array {
        $domain = preg_replace('#^https?://#i', '', trim($domain));
        $domain = preg_replace('#^www\.#i', '', $domain);
        $domain = preg_replace('#/.*$#', '', $domain);

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
        return ['ok' => true, 'items' => $items, 'raw' => $res['data']];
    }

    /**
     * Pull organic keywords a domain ranks for via DataForSEO Labs endpoint.
     */
    public static function domain_organic_keywords(string $domain, int $limit = 500): array {
        $domain = preg_replace('#^https?://#i', '', trim($domain));
        $domain = preg_replace('#^www\.#i', '', $domain);
        $domain = preg_replace('#/.*$#', '', $domain);

        $payload = [[
            'target' => $domain,
            'location_code' => self::loc_code(),
            'language_code' => self::lang_code(),
            'limit' => min(1000, max(10, $limit)),
            'ignore_synonyms' => true,
        ]];

        $res = self::post('/v3/dataforseo_labs/google/domain_organic_keywords/live', $payload);
        if (!$res['ok']) return $res;

        $items = $res['data']['tasks'][0]['result'][0]['items'] ?? [];
        if (!is_array($items)) $items = [];

        return ['ok' => true, 'items' => $items, 'raw' => $res['data']];
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

        $payload = [[
            'keywords' => array_map(fn($k) => mb_strtolower($k, 'UTF-8'), $keywords),
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

        return ['ok' => true, 'map' => $map, 'raw' => $res['data']];
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

        // Transient cache: SERP data valid for 6 hours
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
            $normalised[] = [
                'url'            => $item['url'] ?? '',
                'title'          => $item['title'] ?? '',
                'snippet'        => $item['description'] ?? '',
                'domain_rating'  => (float) ($item['domain_info']['domain_rank'] ?? $item['domain_rank'] ?? 50),
                'word_count'     => (int) ($item['estimated_paid_traffic_cost'] ?? 0), // fallback — DFS doesn't return word count here
                'age_days'       => isset($item['timestamp']) ? (int) ((time() - strtotime((string)$item['timestamp'])) / 86400) : 0,
                'heading_count'  => 0, // not available at this endpoint
                'faq_count'      => 0,
                'position'       => (int) ($item['rank_absolute'] ?? 0),
            ];
        }

        $result = ['ok' => true, 'items' => $normalised, 'raw' => $res['data']];
        set_transient($cache_key, $result, 6 * HOUR_IN_SECONDS);
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
        return ['ok' => true, 'items' => $items, 'raw' => $res['data']];
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
     * Returns monthly search volume history for keywords (last 12 months).
     *
     * @param string[] $keywords
     * @return array{ok:bool, map:array}  map[keyword] = [{month:'2024-01', volume:int}]
     */
    public static function historical_search_volume(array $keywords): array {
        $keywords = array_values(array_unique(array_filter(array_map('strval', $keywords))));
        $keywords = array_slice($keywords, 0, 100);
        if (empty($keywords)) return ['ok' => false, 'error' => 'empty_keywords'];

        $payload = [[
            'keywords'      => array_map(fn($k) => mb_strtolower($k, 'UTF-8'), $keywords),
            'location_code' => self::loc_code(),
            'language_code' => self::lang_code(),
        ]];

        $res = self::post('/v3/keywords_data/google_ads/search_volume/live', $payload);
        if (!$res['ok']) return $res;

        $items = $res['data']['tasks'][0]['result'][0]['items'] ?? [];
        $map   = [];
        if (is_array($items)) {
            foreach ($items as $it) {
                $kw = $it['keyword'] ?? null;
                if (!is_string($kw) || $kw === '') continue;
                $monthly = [];
                foreach ((array) ($it['monthly_searches'] ?? []) as $m) {
                    $monthly[] = [
                        'month'  => sprintf('%04d-%02d', $m['year'] ?? 0, $m['month'] ?? 0),
                        'volume' => (int) ($m['search_volume'] ?? 0),
                    ];
                }
                $map[$kw] = [
                    'search_volume' => (int) ($it['search_volume'] ?? 0),
                    'trend'         => $monthly,
                    'competition'   => (float) ($it['competition'] ?? 0),
                    'cpc'           => (float) ($it['cpc'] ?? 0),
                ];
            }
        }

        return ['ok' => true, 'map' => $map, 'raw' => $res['data']];
    }
}

