<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\DataForSEO;

if (!defined('ABSPATH')) { exit; }

class KeywordDiscoveryService {

    /**
     * @param string[] $seed_keywords
     */
    public static function discover_from_seeds(array $seed_keywords = [], int $limit = 100): int {
        global $wpdb;

        $limit = min(100, max(1, $limit));
        Logs::info('keywords', '[TMW-SEO-AUTO] keyword discovery started', [
            'seed_count' => count($seed_keywords),
            'limit' => $limit,
        ]);

        $seeds = self::normalize_keywords($seed_keywords);
        if (empty($seeds)) {
            $seed_rows = SeedRegistry::get_seeds_for_discovery(100);
            $seeds = [];
            foreach ($seed_rows as $seed_row) {
                $seed = self::normalize_keyword((string) ($seed_row['seed'] ?? ''));
                if ($seed !== '') {
                    $seeds[] = $seed;
                }
            }
            $seeds = array_values(array_unique($seeds));
        }

        if (empty($seeds)) {
            Logs::info('keywords', '[TMW-SEO-AUTO] keyword discovery inserted 0 candidates');
            return 0;
        }

        $pool = [];
        foreach ($seeds as $seed) {
            if (count($pool) >= ($limit * 2)) {
                break;
            }

            foreach (self::fetch_dataforseo_suggestions($seed) as $kw) {
                $pool[$kw] = true;
                if (count($pool) >= ($limit * 2)) {
                    break;
                }
            }

            if (count($pool) >= ($limit * 2)) {
                break;
            }

            foreach (self::fetch_google_autosuggest($seed) as $kw) {
                $pool[$kw] = true;
                if (count($pool) >= ($limit * 2)) {
                    break;
                }
            }
        }

        if (empty($pool)) {
            Logs::info('keywords', '[TMW-SEO-AUTO] keyword discovery inserted 0 candidates');
            return 0;
        }

        $candidate_keywords = array_keys($pool);
        $candidate_keywords = self::remove_existing_candidates($candidate_keywords);
        $candidate_keywords = array_slice($candidate_keywords, 0, $limit);

        if (empty($candidate_keywords)) {
            Logs::info('keywords', '[TMW-SEO-AUTO] keyword discovery inserted 0 candidates');
            return 0;
        }

        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $inserted = 0;

        foreach ($candidate_keywords as $keyword) {
            $result = $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$table} (keyword, status, source, created_at) VALUES (%s, %s, %s, %s)",
                    $keyword,
                    'new',
                    'discovery',
                    current_time('mysql')
                )
            );

            if ((int) $result > 0) {
                $inserted++;
            }
        }

        Logs::info('keywords', sprintf('[TMW-SEO-AUTO] keyword discovery inserted %d candidates', $inserted));

        return $inserted;
    }

    /**
     * @return string[]
     */
    private static function fetch_dataforseo_suggestions(string $seed): array {
        $res = DataForSEO::keyword_suggestions($seed, 100);
        if (empty($res['ok'])) {
            return [];
        }

        $items = (array) ($res['items'] ?? []);
        $keywords = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $normalized = self::normalize_keyword((string) ($item['keyword'] ?? ''));
            if ($normalized !== '') {
                $keywords[] = $normalized;
            }
        }

        return array_values(array_unique($keywords));
    }

    /**
     * @return string[]
     */
    private static function fetch_google_autosuggest(string $seed): array {
        $url = add_query_arg([
            'client' => 'firefox',
            'q' => $seed,
        ], 'https://suggestqueries.google.com/complete/search');

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return [];
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || !isset($body[1]) || !is_array($body[1])) {
            return [];
        }

        return self::normalize_keywords($body[1]);
    }

    /**
     * @param string[] $keywords
     * @return string[]
     */
    private static function remove_existing_candidates(array $keywords): array {
        global $wpdb;

        if (empty($keywords)) {
            return [];
        }

        $keywords = array_values(array_unique($keywords));
        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $existing_map = [];

        foreach (array_chunk($keywords, 200) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
            $rows = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT keyword FROM {$table} WHERE keyword IN ({$placeholders})",
                    ...$chunk
                )
            );

            foreach ((array) $rows as $existing) {
                $normalized = self::normalize_keyword((string) $existing);
                if ($normalized !== '') {
                    $existing_map[$normalized] = true;
                }
            }
        }

        $filtered = [];
        foreach ($keywords as $keyword) {
            if (!isset($existing_map[$keyword])) {
                $filtered[] = $keyword;
            }
        }

        return $filtered;
    }

    private static function normalize_keyword(string $keyword): string {
        $keyword = mb_strtolower(trim($keyword), 'UTF-8');
        $keyword = preg_replace('/\s+/', ' ', $keyword);
        return is_string($keyword) ? trim($keyword) : '';
    }

    /**
     * @param string[] $keywords
     * @return string[]
     */
    private static function normalize_keywords(array $keywords): array {
        $normalized = [];
        foreach ($keywords as $keyword) {
            $clean = self::normalize_keyword((string) $keyword);
            if ($clean !== '') {
                $normalized[] = $clean;
            }
        }

        return array_values(array_unique($normalized));
    }
}
