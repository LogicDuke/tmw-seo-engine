<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\DataForSEO;

if (!defined('ABSPATH')) { exit; }

class KeywordDiscoveryService {

    /**
     * @param string[] $seed_keywords
     */
    public static function discover_from_seeds(array $seed_keywords = [], int $limit = 100): array {
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
            Logs::warn('keywords', '[TMW-KW] keyword discovery inserted 0 candidates', [
                'reason' => 'no normalized seeds',
            ]);
            return [
                'inserted' => 0,
                'reason' => 'no_seeds',
                'providers' => [
                    'dataforseo' => 0,
                    'google_autosuggest' => 0,
                ],
            ];
        }

        $pool = [];
        $provider_counts = [
            'dataforseo' => 0,
            'google_autosuggest' => 0,
        ];

        foreach ($seeds as $seed) {
            if (count($pool) >= ($limit * 2)) {
                break;
            }

            $dfseo_keywords = self::fetch_dataforseo_suggestions($seed);
            $provider_counts['dataforseo'] += count($dfseo_keywords);
            foreach ($dfseo_keywords as $kw) {
                $pool[$kw] = true;
                if (count($pool) >= ($limit * 2)) {
                    break;
                }
            }

            if (count($pool) >= ($limit * 2)) {
                break;
            }

            $google_keywords = self::fetch_google_autosuggest($seed);
            $provider_counts['google_autosuggest'] += count($google_keywords);
            foreach ($google_keywords as $kw) {
                $pool[$kw] = true;
                if (count($pool) >= ($limit * 2)) {
                    break;
                }
            }
        }

        if (empty($pool)) {
            $reason = 'discovery_inserted_zero_provider_empty';
            Logs::warn('keywords', '[TMW-KW] keyword discovery inserted 0 candidates', [
                'reason' => $reason,
                'providers' => $provider_counts,
                'provider_diagnostics' => [
                    'dataforseo' => $provider_counts['dataforseo'] <= 0 ? 'DataForSEO returned empty' : 'DataForSEO returned suggestions',
                    'google_autosuggest' => $provider_counts['google_autosuggest'] <= 0 ? 'Google Autosuggest returned empty' : 'Google Autosuggest returned suggestions',
                    'overall' => ($provider_counts['dataforseo'] <= 0 && $provider_counts['google_autosuggest'] <= 0)
                        ? 'all providers empty'
                        : 'provider suggestions were filtered before insert',
                ],
            ]);
            return [
                'inserted' => 0,
                'reason' => $reason,
                'providers' => $provider_counts,
            ];
        }

        $candidate_keywords = array_keys($pool);
        $candidate_keywords = self::remove_existing_candidates($candidate_keywords);
        $candidate_keywords = array_slice($candidate_keywords, 0, $limit);

        if (empty($candidate_keywords)) {
            Logs::warn('keywords', '[TMW-KW] keyword discovery inserted 0 candidates', [
                'reason' => 'all fetched suggestions were duplicates of existing candidates',
                'providers' => $provider_counts,
            ]);
            return [
                'inserted' => 0,
                'reason' => 'discovery_inserted_zero_all_duplicates',
                'providers' => $provider_counts,
            ];
        }

        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $inserted = 0;
        $ignored_existing_rows = 0;

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
            } else {
                $ignored_existing_rows++;
            }
        }

        if ($inserted <= 0) {
            $reason = $ignored_existing_rows > 0
                ? 'insert attempts ignored because rows already existed'
                : 'discovery_inserted_zero_provider_empty';
            Logs::warn('keywords', '[TMW-KW] keyword discovery inserted 0 candidates', [
                'reason' => $reason,
                'providers' => $provider_counts,
                'ignored_existing_rows' => $ignored_existing_rows,
            ]);

            return [
                'inserted' => 0,
                'reason' => $ignored_existing_rows > 0 ? 'discovery_inserted_zero_all_duplicates' : 'discovery_inserted_zero_provider_empty',
                'providers' => $provider_counts,
                'counts' => [
                    'ignored_existing_rows' => $ignored_existing_rows,
                ],
            ];
        }

        Logs::info('keywords', sprintf('[TMW-SEO-AUTO] keyword discovery inserted %d candidates', $inserted), [
            'providers' => $provider_counts,
            'ignored_existing_rows' => $ignored_existing_rows,
        ]);

        return [
            'inserted' => $inserted,
            'reason' => '',
            'providers' => $provider_counts,
            'counts' => [
                'ignored_existing_rows' => $ignored_existing_rows,
            ],
        ];
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
