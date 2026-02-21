<?php
namespace TMWSEO\Engine\Services;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class DataForSEO {

    private const API_BASE = 'https://api.dataforseo.com';

    public static function is_configured(): bool {
        $login = trim((string)Settings::get('dataforseo_login', ''));
        $pass  = trim((string)Settings::get('dataforseo_password', ''));
        return $login !== '' && $pass !== '';
    }

    private static function auth_header(): string {
        // Normalize whitespace to avoid hidden \r/\n or trailing spaces breaking Basic Auth.
        $login = trim((string)Settings::get('dataforseo_login', ''));
        $pass  = trim((string)Settings::get('dataforseo_password', ''));
        return 'Basic ' . base64_encode($login . ':' . $pass);
    }

    private static function post(string $path, array $post_array): array {
        if (Settings::is_safe_mode()) return ['ok' => false, 'error' => 'safe_mode_enabled'];
        if (!self::is_configured()) return ['ok' => false, 'error' => 'dataforseo_credentials_missing'];

        $url = rtrim(self::API_BASE, '/') . '/' . ltrim($path, '/');

        $resp = wp_remote_post($url, [
            'timeout' => 60,
            'headers' => [
                'Authorization' => self::auth_header(),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($post_array),
        ]);

        if (is_wp_error($resp)) {
            Logs::error('dataforseo', 'WP error', ['error' => $resp->get_error_message(), 'path' => $path]);
            return ['ok' => false, 'error' => $resp->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw  = (string) wp_remote_retrieve_body($resp);
        $json = json_decode($raw, true);

        if ($code < 200 || $code >= 300 || !is_array($json)) {
            Logs::error('dataforseo', 'Bad response', ['code' => $code, 'path' => $path, 'body' => substr($raw, 0, 500)]);
            return ['ok' => false, 'error' => 'bad_response', 'code' => $code, 'body' => $raw];
        }

        if (!empty($json['status_code']) && (int)$json['status_code'] !== 20000) {
            Logs::warn('dataforseo', 'Non-20000 status', ['status_code' => $json['status_code'], 'msg' => $json['status_message'] ?? '', 'path' => $path]);
        }

        return ['ok' => true, 'data' => $json];
    }

    private static function loc_code(): int {
        return (int) Settings::get('dataforseo_location_code', 2840);
    }

    private static function lang_code(): string {
        return (string) Settings::get('dataforseo_language_code', 'en');
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
}
