<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\JobWorker;
use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\DataForSEO;

if (!defined('ABSPATH')) { exit; }

class RecursiveKeywordExpansionEngine {
    private const MAX_DEPTH = 3;
    private const MAX_KEYWORDS_PER_RUN = 100;
    private const MAX_CHILDREN_PER_KEYWORD = 50;
    private const MIN_VOLUME = 50;

    /**
     * @param string[] $seed_keywords
     * @return array<string,int>
     */
    public static function run(array $seed_keywords): array {
        global $wpdb;

        $graph_table = QueryExpansionGraph::table_name();
        $queue = [];
        $seen = [];

        foreach ($seed_keywords as $seed_keyword) {
            $seed_keyword = self::normalize_keyword((string) $seed_keyword);
            if ($seed_keyword === '' || mb_strlen($seed_keyword, 'UTF-8') < 3) {
                continue;
            }
            $queue[] = ['keyword' => $seed_keyword, 'depth' => 0, 'parent' => ''];
            $seen[$seed_keyword . '|0'] = true;
        }

        $expanded_count = 0;
        $inserted_graph_rows = 0;
        $inserted_candidates = 0;

        while (!empty($queue) && $expanded_count < self::MAX_KEYWORDS_PER_RUN) {
            $node = array_shift($queue);
            if (!is_array($node)) {
                continue;
            }

            $keyword = (string) ($node['keyword'] ?? '');
            $depth = (int) ($node['depth'] ?? 0);
            if ($keyword === '' || $depth >= self::MAX_DEPTH) {
                continue;
            }

            if (self::was_already_expanded($keyword)) {
                continue;
            }

            $response = DataForSEO::keyword_suggestions($keyword, self::MAX_CHILDREN_PER_KEYWORD);
            if (empty($response['ok'])) {
                continue;
            }

            $items = (array) ($response['items'] ?? []);
            $children_added = 0;

            foreach ($items as $item) {
                if (!is_array($item) || $children_added >= self::MAX_CHILDREN_PER_KEYWORD) {
                    continue;
                }

                $child_keyword = self::normalize_keyword((string) ($item['keyword'] ?? $item['keyword_info']['keyword'] ?? ''));
                $search_volume = (int) ($item['keyword_info']['search_volume'] ?? $item['search_volume'] ?? 0);
                $keyword_difficulty = (float) ($item['keyword_info']['keyword_difficulty'] ?? $item['keyword_difficulty'] ?? 0);

                if ($child_keyword === '' || mb_strlen($child_keyword, 'UTF-8') < 3 || $search_volume < self::MIN_VOLUME) {
                    continue;
                }

                if (self::graph_keyword_exists($child_keyword) || self::candidate_keyword_exists($child_keyword)) {
                    continue;
                }

                $inserted = $wpdb->insert($graph_table, [
                    'parent_keyword' => $keyword,
                    'child_keyword' => $child_keyword,
                    'depth' => $depth + 1,
                    'search_volume' => $search_volume,
                    'keyword_difficulty' => $keyword_difficulty,
                    'source' => 'dataforseo_keyword_suggestions',
                    'created_at' => current_time('mysql'),
                ], ['%s', '%s', '%d', '%d', '%f', '%s', '%s']);

                if ($inserted === false) {
                    continue;
                }

                $inserted_graph_rows++;
                $children_added++;

                if (self::insert_candidate_keyword($child_keyword, $search_volume, $keyword_difficulty)) {
                    $inserted_candidates++;
                }

                if (($depth + 1) < self::MAX_DEPTH && !self::was_already_expanded($child_keyword)) {
                    $queue_key = $child_keyword . '|' . ($depth + 1);
                    if (!isset($seen[$queue_key])) {
                        $queue[] = ['keyword' => $child_keyword, 'depth' => $depth + 1, 'parent' => $keyword];
                        $seen[$queue_key] = true;
                    }
                }
            }

            self::mark_expanded($keyword, $depth);
            $expanded_count++;
        }

        Logs::info('keywords', '[TMW-RECURSIVE-EXPAND] Recursive expansion completed', [
            'expanded_keywords' => $expanded_count,
            'graph_rows_inserted' => $inserted_graph_rows,
            'candidates_inserted' => $inserted_candidates,
        ]);

        return [
            'expanded_keywords' => $expanded_count,
            'graph_rows_inserted' => $inserted_graph_rows,
            'candidates_inserted' => $inserted_candidates,
        ];
    }

    /**
     * @param string[] $seed_keywords
     */
    public static function enqueue(array $seed_keywords): int {
        return JobWorker::enqueue_job('recursive_keyword_expansion', [
            'seed_keywords' => array_values(array_unique(array_map('strval', $seed_keywords))),
        ]);
    }

    private static function normalize_keyword(string $keyword): string {
        $keyword = strtolower(trim(wp_strip_all_tags($keyword)));
        $keyword = preg_replace('/\s+/u', ' ', $keyword);

        return (string) $keyword;
    }

    private static function was_already_expanded(string $keyword): bool {
        global $wpdb;
        $table = QueryExpansionGraph::table_name();

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE parent_keyword = %s",
            $keyword
        ));

        return $count > 0;
    }

    private static function mark_expanded(string $keyword, int $depth): void {
        global $wpdb;
        $table = QueryExpansionGraph::table_name();

        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE parent_keyword = %s AND child_keyword = %s AND source = 'expanded_marker'",
            $keyword,
            $keyword
        ));

        if ($exists > 0) {
            return;
        }

        $wpdb->insert($table, [
            'parent_keyword' => $keyword,
            'child_keyword' => $keyword,
            'depth' => $depth,
            'search_volume' => 0,
            'keyword_difficulty' => 0.0,
            'source' => 'expanded_marker',
            'created_at' => current_time('mysql'),
        ], ['%s', '%s', '%d', '%d', '%f', '%s', '%s']);
    }

    private static function graph_keyword_exists(string $keyword): bool {
        global $wpdb;
        $table = QueryExpansionGraph::table_name();

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE child_keyword = %s",
            $keyword
        ));

        return $count > 0;
    }

    private static function candidate_keyword_exists(string $keyword): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_keyword_candidates';

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE keyword = %s",
            $keyword
        ));

        return $count > 0;
    }

    private static function insert_candidate_keyword(string $keyword, int $volume, float $difficulty): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $inserted = $wpdb->insert($table, [
            'keyword' => $keyword,
            'canonical' => $keyword,
            'status' => 'new',
            'volume' => $volume,
            'difficulty' => $difficulty,
            'sources' => wp_json_encode(['recursive_keyword_graph']),
            'updated_at' => current_time('mysql'),
        ], ['%s', '%s', '%s', '%d', '%f', '%s', '%s']);

        return $inserted !== false;
    }
}
