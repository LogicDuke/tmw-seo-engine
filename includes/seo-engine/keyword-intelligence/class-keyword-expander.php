<?php
namespace TMWSEO\Engine\KeywordIntelligence;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\Settings;

if (!defined('ABSPATH')) { exit; }

class KeywordExpander {

    private const API_BASE = 'https://api.dataforseo.com';

    /**
     * @param string[] $seed_keywords
     * @return array<int,array<string,mixed>>
     */
    public function expand(array $seed_keywords): array {
        $expanded = [];

        foreach ($seed_keywords as $seed_keyword) {
            $seed_keyword = $this->normalize((string) $seed_keyword);
            if ($seed_keyword === '') {
                continue;
            }

            $suggestions = $this->keyword_suggestions($seed_keyword);
            $related = $this->related_keywords($seed_keyword);

            foreach ([$suggestions, $related] as $source_rows) {
                foreach ($source_rows as $row) {
                    $keyword = $this->normalize((string) ($row['keyword'] ?? ''));
                    if ($keyword === '') {
                        continue;
                    }

                    if (!isset($expanded[$keyword])) {
                        $expanded[$keyword] = [
                            'keyword' => $keyword,
                            'search_volume' => (int) ($row['search_volume'] ?? 0),
                            'keyword_difficulty' => (float) ($row['keyword_difficulty'] ?? 0),
                        ];
                        continue;
                    }

                    $expanded[$keyword]['search_volume'] = max(
                        (int) $expanded[$keyword]['search_volume'],
                        (int) ($row['search_volume'] ?? 0)
                    );
                    $expanded[$keyword]['keyword_difficulty'] = max(
                        (float) $expanded[$keyword]['keyword_difficulty'],
                        (float) ($row['keyword_difficulty'] ?? 0)
                    );
                }
            }
        }

        return array_values($expanded);
    }

    /** @return array<int,array<string,mixed>> */
    private function keyword_suggestions(string $seed_keyword): array {
        $payload = [[
            'keyword' => $seed_keyword,
            'location_code' => (int) Settings::get('dataforseo_location_code', 2840),
            'language_code' => (string) Settings::get('dataforseo_language_code', 'en'),
            'limit' => 100,
        ]];

        $response = $this->post('/v3/keywords_data/google/keyword_suggestions', $payload);
        if (!$response['ok']) {
            return [];
        }

        return $this->extract_items($response['data']);
    }

    /** @return array<int,array<string,mixed>> */
    private function related_keywords(string $seed_keyword): array {
        $payload = [[
            'keyword' => $seed_keyword,
            'location_code' => (int) Settings::get('dataforseo_location_code', 2840),
            'language_code' => (string) Settings::get('dataforseo_language_code', 'en'),
            'depth' => 1,
            'limit' => 100,
        ]];

        $response = $this->post('/v3/dataforseo_labs/google/related_keywords', $payload);
        if (!$response['ok']) {
            return [];
        }

        return $this->extract_items($response['data']);
    }

    /**
     * @param array<int,array<string,mixed>> $payload
     * @return array{ok:bool,data?:array<string,mixed>}
     */
    private function post(string $path, array $payload): array {
        $login = trim((string) Settings::get('dataforseo_login', ''));
        $password = trim((string) Settings::get('dataforseo_password', ''));

        if ($login === '' || $password === '') {
            return ['ok' => false];
        }

        $url = rtrim(self::API_BASE, '/') . '/' . ltrim($path, '/');
        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($login . ':' . $password),
            ],
            'body' => wp_json_encode($payload),
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            Logs::warn('keyword_intelligence', '[TMW-KIP] DataForSEO call failed', ['error' => $response->get_error_message(), 'path' => $path]);
            return ['ok' => false];
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return ['ok' => false];
        }

        return ['ok' => true, 'data' => $body];
    }

    /**
     * @param array<string,mixed> $body
     * @return array<int,array<string,mixed>>
     */
    private function extract_items(array $body): array {
        $items = $body['tasks'][0]['result'][0]['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $keyword = (string) ($item['keyword'] ?? $item['keyword_info']['keyword'] ?? '');
            if ($keyword === '') {
                continue;
            }

            $result[] = [
                'keyword' => $keyword,
                'search_volume' => (int) ($item['keyword_info']['search_volume'] ?? $item['search_volume'] ?? 0),
                'keyword_difficulty' => (float) ($item['keyword_info']['keyword_difficulty'] ?? $item['keyword_difficulty'] ?? 0),
            ];
        }

        return $result;
    }

    private function normalize(string $keyword): string {
        $keyword = strtolower(trim(wp_strip_all_tags($keyword)));
        $keyword = preg_replace('/\s+/u', ' ', $keyword);
        return (string) $keyword;
    }
}
