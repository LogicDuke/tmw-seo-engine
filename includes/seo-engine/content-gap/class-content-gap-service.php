<?php
namespace TMWSEO\Engine\ContentGap;

use TMWSEO\Engine\JobWorker;
use TMWSEO\Engine\Clustering\ClusterBuilder;
use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Integrations\GSCApi;

if (!defined('ABSPATH')) { exit; }

class ContentGapService {
    private const HOOK_WEEKLY_GAP = 'tmwseo_content_gap_weekly';

    public static function init(): void {
        add_action(self::HOOK_WEEKLY_GAP, [__CLASS__, 'queue_weekly_analysis']);

        if (!wp_next_scheduled(self::HOOK_WEEKLY_GAP)) {
            wp_schedule_event(time() + 1800, 'tmwseo_weekly', self::HOOK_WEEKLY_GAP);
        }
    }

    public static function queue_weekly_analysis(): void {
        JobWorker::enqueue_job('content_gap_analysis', ['trigger' => 'weekly']);
    }

    /** @return array<int,array<string,mixed>> */
    public static function list_competitor_domains(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_competitor_domains';
        return (array) $wpdb->get_results("SELECT * FROM {$table} ORDER BY added_at DESC", ARRAY_A);
    }

    public static function add_competitor_domain(string $domain, string $source = 'manual'): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_competitor_domains';

        $domain = self::normalize_domain($domain);
        if ($domain === '') {
            return false;
        }

        $existing_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE domain = %s LIMIT 1", $domain));
        if ($existing_id > 0) {
            return true;
        }

        return (bool) $wpdb->insert(
            $table,
            [
                'domain' => $domain,
                'source' => sanitize_key($source) ?: 'manual',
                'added_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s']
        );
    }

    /** @return array<string,mixed> */
    public static function run_analysis(): array {
        $domains = self::list_competitor_domains();
        $domain_names = array_values(array_filter(array_map(static function ($row): string {
            return self::normalize_domain((string) ($row['domain'] ?? ''));
        }, $domains)));

        self::sync_competitor_keywords($domain_names);
        self::sync_site_keywords();
        $gaps = self::detect_and_store_gaps();

        return [
            'domains' => count($domain_names),
            'stored_gaps' => $gaps,
        ];
    }

    /** @param string[] $domains */
    public static function sync_competitor_keywords(array $domains): int {
        global $wpdb;

        $table = $wpdb->prefix . 'tmwseo_competitor_keywords';
        $stored = 0;

        foreach ($domains as $domain) {
            $domain = self::normalize_domain($domain);
            if ($domain === '') {
                continue;
            }

            $res = DataForSEO::domain_keywords_live($domain, 300);
            if (empty($res['ok']) || !is_array($res['items'] ?? null)) {
                continue;
            }

            foreach ($res['items'] as $item) {
                $keyword = mb_strtolower(trim((string) ($item['keyword'] ?? '')), 'UTF-8');
                if ($keyword === '') {
                    continue;
                }

                $row = [
                    'domain' => $domain,
                    'keyword' => $keyword,
                    'search_volume' => max(0, (int) ($item['search_volume'] ?? 0)),
                    'keyword_difficulty' => isset($item['keyword_difficulty']) ? (float) $item['keyword_difficulty'] : null,
                    'cpc' => isset($item['cpc']) ? (float) $item['cpc'] : null,
                    'position' => max(0, (int) ($item['position'] ?? 0)),
                    'source_keyword' => $keyword,
                    'captured_at' => current_time('mysql'),
                ];

                $existing = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE domain = %s AND keyword = %s LIMIT 1",
                    $domain,
                    $keyword
                ));

                if ($existing > 0) {
                    $wpdb->update(
                        $table,
                        [
                            'search_volume' => $row['search_volume'],
                            'keyword_difficulty' => $row['keyword_difficulty'],
                            'cpc' => $row['cpc'],
                            'position' => $row['position'],
                            'source_keyword' => $row['source_keyword'],
                            'captured_at' => $row['captured_at'],
                        ],
                        ['id' => $existing],
                        ['%d', '%f', '%f', '%d', '%s', '%s'],
                        ['%d']
                    );
                    $stored++;
                    continue;
                }

                $ok = $wpdb->insert(
                    $table,
                    $row,
                    ['%s', '%s', '%d', '%f', '%f', '%d', '%s', '%s']
                );

                if ($ok) {
                    $stored++;
                }
            }
        }

        return $stored;
    }

    public static function sync_site_keywords(): int {
        global $wpdb;

        $table = $wpdb->prefix . 'tmwseo_site_keywords';
        $stored = 0;

        $site_url = (string) get_option('tmwseo_gsc_site_url', '');
        if ($site_url !== '') {
            $end = gmdate('Y-m-d');
            $start = gmdate('Y-m-d', strtotime('-90 days'));
            $gsc = GSCApi::search_analytics($site_url, $start, $end, ['query'], 2000);

            if (!empty($gsc['ok']) && is_array($gsc['rows'] ?? null)) {
                foreach ($gsc['rows'] as $row) {
                    $keyword = mb_strtolower(trim((string) ($row['keys'][0] ?? '')), 'UTF-8');
                    if ($keyword === '') {
                        continue;
                    }
                    $stored += self::upsert_site_keyword($keyword, 'gsc', (int) ($row['impressions'] ?? 0), $table);
                }
            }
        }

        $candidate_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $rows = (array) $wpdb->get_results("SELECT keyword, volume FROM {$candidate_table} WHERE keyword <> '' LIMIT 5000", ARRAY_A);
        foreach ($rows as $row) {
            $keyword = mb_strtolower(trim((string) ($row['keyword'] ?? '')), 'UTF-8');
            if ($keyword === '') {
                continue;
            }
            $stored += self::upsert_site_keyword($keyword, 'candidate', (int) ($row['volume'] ?? 0), $table);
        }

        return $stored;
    }

    private static function upsert_site_keyword(string $keyword, string $source, int $volume, string $table): int {
        global $wpdb;

        $source = sanitize_key($source);
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE keyword = %s AND source = %s LIMIT 1",
            $keyword,
            $source
        ));

        if ($existing > 0) {
            $wpdb->update(
                $table,
                [
                    'search_volume' => max(0, $volume),
                    'captured_at' => current_time('mysql'),
                ],
                ['id' => $existing],
                ['%d', '%s'],
                ['%d']
            );
            return 1;
        }

        $ok = $wpdb->insert(
            $table,
            [
                'keyword' => $keyword,
                'source' => $source,
                'search_volume' => max(0, $volume),
                'captured_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%d', '%s']
        );

        return $ok ? 1 : 0;
    }

    public static function detect_and_store_gaps(): int {
        global $wpdb;

        $competitor_table = $wpdb->prefix . 'tmwseo_competitor_keywords';
        $site_table = $wpdb->prefix . 'tmwseo_site_keywords';
        $gaps_table = $wpdb->prefix . 'tmwseo_content_gaps';

        $rows = (array) $wpdb->get_results(
            "SELECT c.keyword, MAX(c.search_volume) AS search_volume, MAX(c.keyword_difficulty) AS keyword_difficulty,
                    COUNT(DISTINCT c.domain) AS competitor_count, GROUP_CONCAT(DISTINCT c.domain) AS competitors
             FROM {$competitor_table} c
             LEFT JOIN {$site_table} s ON s.keyword = c.keyword
             WHERE s.keyword IS NULL
               AND c.search_volume > 100
             GROUP BY c.keyword",
            ARRAY_A
        );

        $stored = 0;
        $cluster_builder = class_exists('TMWSEO\\Engine\\Clustering\\ClusterBuilder') ? new ClusterBuilder() : null;
        $keywords_for_cluster = [];

        foreach ($rows as $row) {
            $keyword = mb_strtolower(trim((string) ($row['keyword'] ?? '')), 'UTF-8');
            if ($keyword === '') {
                continue;
            }

            $volume = max(0, (int) ($row['search_volume'] ?? 0));
            $difficulty = (float) ($row['keyword_difficulty'] ?? 0);
            $difficulty = $difficulty > 0 ? $difficulty : 1.0;
            $competitor_count = max(1, (int) ($row['competitor_count'] ?? 1));
            $score = round(($volume / $difficulty) * $competitor_count, 2);
            $competitors = array_values(array_filter(array_map('trim', explode(',', (string) ($row['competitors'] ?? '')))));

            $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$gaps_table} WHERE keyword = %s LIMIT 1", $keyword));

            $payload = [
                'search_volume' => $volume,
                'keyword_difficulty' => (float) ($row['keyword_difficulty'] ?? 0),
                'competitor_count' => $competitor_count,
                'opportunity_score' => $score,
                'competitors_json' => wp_json_encode($competitors),
                'status' => 'new',
                'updated_at' => current_time('mysql'),
            ];

            if ($exists > 0) {
                $wpdb->update($gaps_table, $payload, ['id' => $exists], ['%d', '%f', '%d', '%f', '%s', '%s', '%s'], ['%d']);
                $stored++;
            } else {
                $payload['keyword'] = $keyword;
                $payload['created_at'] = current_time('mysql');
                $ok = $wpdb->insert($gaps_table, $payload, ['%d', '%f', '%d', '%f', '%s', '%s', '%s', '%s', '%s']);
                if ($ok) {
                    $stored++;
                }
            }

            $keywords_for_cluster[] = $keyword;
        }

        if ($cluster_builder instanceof ClusterBuilder && !empty($keywords_for_cluster)) {
            $clusters = $cluster_builder->build($keywords_for_cluster, []);
            update_option('tmwseo_content_gap_clusters', $clusters, false);
        }

        return $stored;
    }

    /** @return array<int,array<string,mixed>> */
    public static function get_gaps(int $limit = 200): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_content_gaps';

        return (array) $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY opportunity_score DESC, search_volume DESC LIMIT %d", max(1, $limit)),
            ARRAY_A
        );
    }

    private static function normalize_domain(string $domain): string {
        $domain = preg_replace('#^https?://#i', '', trim($domain));
        $domain = preg_replace('#^www\\.#i', '', (string) $domain);
        $domain = preg_replace('#/.*$#', '', (string) $domain);
        $domain = strtolower((string) $domain);

        return sanitize_text_field($domain);
    }
}
