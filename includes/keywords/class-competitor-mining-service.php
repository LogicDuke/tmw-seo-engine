<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\DataForSEO;

if (!defined('ABSPATH')) { exit; }

class CompetitorMiningService {
    public const MAX_DOMAINS_PER_RUN = 10;
    public const MAX_KEYWORDS_PER_DOMAIN = 100;
    private const MIN_VOLUME = 50;

    private static function serp_domains_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmwseo_serp_domains';
    }

    private static function competitor_keywords_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmwseo_competitor_keywords';
    }

    private static function keyword_candidates_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_keyword_candidates';
    }

    public static function run(array $args = []): array {
        global $wpdb;

        $seed_limit = max(1, (int) ($args['seed_limit'] ?? 10));
        $seed_table = $wpdb->prefix . 'tmwseo_seeds';
        $seed_rows = $wpdb->get_results(
            $wpdb->prepare("SELECT id, seed FROM {$seed_table} ORDER BY priority DESC, id DESC LIMIT %d", $seed_limit),
            ARRAY_A
        );

        if (!is_array($seed_rows) || empty($seed_rows)) {
            return ['ok' => false, 'error' => 'no_seed_keywords'];
        }

        $domains_by_name = [];
        $serp_rows_inserted = 0;

        foreach ($seed_rows as $row) {
            $keyword_id = (int) ($row['id'] ?? 0);
            $keyword = sanitize_text_field((string) ($row['seed'] ?? ''));
            if ($keyword_id <= 0 || $keyword === '') {
                continue;
            }

            $serp = DataForSEO::serp_organic_live($keyword, 10);
            if (empty($serp['ok']) || !is_array($serp['items'] ?? null)) {
                continue;
            }

            $position = 0;
            foreach (array_slice($serp['items'], 0, 10) as $item) {
                $domain = self::normalize_domain((string) ($item['domain'] ?? ''));
                $url = esc_url_raw((string) ($item['url'] ?? ''));
                $title = sanitize_text_field((string) ($item['title'] ?? ''));
                if ($domain === '' || $url === '') {
                    continue;
                }

                $position++;
                $wpdb->insert(
                    self::serp_domains_table(),
                    [
                        'keyword_id' => $keyword_id,
                        'domain' => $domain,
                        'url' => $url,
                        'title' => $title,
                        'position' => $position,
                        'captured_at' => current_time('mysql'),
                    ],
                    ['%d', '%s', '%s', '%s', '%d', '%s']
                );
                $serp_rows_inserted += ((int) $wpdb->insert_id > 0) ? 1 : 0;

                if (!isset($domains_by_name[$domain])) {
                    $domains_by_name[$domain] = [
                        'source_keyword' => $keyword,
                        'position' => $position,
                    ];
                }
            }
        }

        $selected_domains = array_slice(array_keys($domains_by_name), 0, self::MAX_DOMAINS_PER_RUN);
        $existing_keywords = self::load_existing_keywords();
        $candidate_ids = [];
        $candidate_inserts = 0;
        $filtered_out = 0;
        $domain_keyword_rows = 0;

        foreach ($selected_domains as $domain) {
            $domain_data = $domains_by_name[$domain] ?? ['source_keyword' => '', 'position' => 0];
            $source_keyword = sanitize_text_field((string) ($domain_data['source_keyword'] ?? ''));

            $keywords = DataForSEO::domain_keywords_live($domain, self::MAX_KEYWORDS_PER_DOMAIN);
            if (empty($keywords['ok']) || !is_array($keywords['items'] ?? null)) {
                continue;
            }

            foreach (array_slice($keywords['items'], 0, self::MAX_KEYWORDS_PER_DOMAIN) as $item) {
                $keyword = mb_strtolower(trim((string) ($item['keyword'] ?? '')), 'UTF-8');
                $volume = isset($item['search_volume']) ? (int) $item['search_volume'] : 0;
                $difficulty = isset($item['keyword_difficulty']) ? (float) $item['keyword_difficulty'] : null;
                $cpc = isset($item['cpc']) ? (float) $item['cpc'] : null;

                if ($keyword === '') {
                    continue;
                }

                $wpdb->insert(
                    self::competitor_keywords_table(),
                    [
                        'domain' => $domain,
                        'keyword' => $keyword,
                        'volume' => $volume,
                        'difficulty' => $difficulty,
                        'cpc' => $cpc,
                        'source_keyword' => $source_keyword,
                        'captured_at' => current_time('mysql'),
                    ],
                    ['%s', '%s', '%d', '%f', '%f', '%s', '%s']
                );
                $domain_keyword_rows++;

                if ($volume < self::MIN_VOLUME || mb_strlen($keyword, 'UTF-8') < 3 || isset($existing_keywords[$keyword])) {
                    $filtered_out++;
                    continue;
                }

                $canonical = KeywordValidator::normalize($keyword);
                $intent = KeywordValidator::infer_intent($keyword);
                $classification = KeywordClassifier::classify($keyword);

                $inserted = $wpdb->insert(
                    self::keyword_candidates_table(),
                    [
                        'keyword' => $keyword,
                        'canonical' => $canonical,
                        'status' => 'new',
                        'intent' => $intent,
                        'intent_type' => (string) ($classification['intent_type'] ?? 'generic'),
                        'entity_type' => (string) ($classification['entity_type'] ?? 'generic'),
                        'entity_id' => (int) ($classification['entity_id'] ?? 0),
                        'volume' => $volume,
                        'cpc' => $cpc,
                        'difficulty' => $difficulty,
                        'opportunity' => null,
                        'sources' => 'competitor_domain:' . $domain,
                        'notes' => 'source_keyword:' . $source_keyword,
                        'needs_recluster' => 1,
                        'needs_rescore' => 1,
                        'volume_source' => 'competitor_domain',
                        'cpc_source' => 'competitor_domain',
                        'updated_at' => current_time('mysql'),
                    ],
                    ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%f', '%f', '%s', '%s', '%d', '%d', '%s', '%s', '%s']
                );

                if ($inserted === false) {
                    $filtered_out++;
                    continue;
                }

                $candidate_id = (int) $wpdb->insert_id;
                if ($candidate_id > 0) {
                    $candidate_ids[] = $candidate_id;
                }

                $existing_keywords[$keyword] = true;
                $candidate_inserts++;
            }
        }

        foreach ($candidate_ids as $candidate_id) {
            KeywordEngine::process_dirty_keyword($candidate_id, 'competitor_domain');
        }

        Logs::info('keywords', '[TMW-COMPETITOR-MINING] Run completed', [
            'seeds_scanned' => count($seed_rows),
            'domains_processed' => count($selected_domains),
            'serp_rows_inserted' => $serp_rows_inserted,
            'competitor_keyword_rows' => $domain_keyword_rows,
            'candidate_inserts' => $candidate_inserts,
            'filtered_out' => $filtered_out,
        ]);

        return [
            'ok' => true,
            'seeds_scanned' => count($seed_rows),
            'domains_processed' => count($selected_domains),
            'serp_rows_inserted' => $serp_rows_inserted,
            'competitor_keyword_rows' => $domain_keyword_rows,
            'candidate_inserts' => $candidate_inserts,
            'filtered_out' => $filtered_out,
        ];
    }

    public static function dashboard_data(): array {
        global $wpdb;

        $domains = $wpdb->get_results(
            "SELECT domain, COUNT(*) AS hits, MIN(position) AS best_position
             FROM " . self::serp_domains_table() . "
             GROUP BY domain
             ORDER BY hits DESC, best_position ASC
             LIMIT 10",
            ARRAY_A
        );

        $keywords = $wpdb->get_results(
            "SELECT keyword, MAX(volume) AS volume, AVG(difficulty) AS difficulty, AVG(cpc) AS cpc
             FROM " . self::competitor_keywords_table() . "
             GROUP BY keyword
             ORDER BY volume DESC
             LIMIT 20",
            ARRAY_A
        );

        $top_keywords = [];
        foreach ((array) $keywords as $row) {
            $volume = (int) ($row['volume'] ?? 0);
            $difficulty = isset($row['difficulty']) ? (float) $row['difficulty'] : 0.0;
            $opportunity = max(0.0, ($volume / 100.0) - ($difficulty / 10.0));

            $top_keywords[] = [
                'keyword' => (string) ($row['keyword'] ?? ''),
                'volume' => $volume,
                'difficulty' => round($difficulty, 2),
                'cpc' => isset($row['cpc']) ? round((float) $row['cpc'], 2) : 0.0,
                'opportunity_score' => round($opportunity, 2),
            ];
        }

        return [
            'top_domains' => is_array($domains) ? $domains : [],
            'top_keywords' => $top_keywords,
        ];
    }

    private static function load_existing_keywords(): array {
        global $wpdb;

        $table = self::keyword_candidates_table();
        $rows = $wpdb->get_col("SELECT keyword FROM {$table}");
        $map = [];

        foreach ((array) $rows as $keyword) {
            $normalized = mb_strtolower(trim((string) $keyword), 'UTF-8');
            if ($normalized !== '') {
                $map[$normalized] = true;
            }
        }

        return $map;
    }

    private static function normalize_domain(string $domain): string {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#^www\.#', '', $domain);
        $domain = preg_replace('#/.*$#', '', $domain);
        return sanitize_text_field($domain);
    }
}
