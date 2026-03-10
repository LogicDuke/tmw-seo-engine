<?php
namespace TMWSEO\Engine\Intelligence;

if (!defined('ABSPATH')) { exit; }

class IntelligenceMaterializer {
    public const CRON_HOOK = 'tmwseo_materialize_intelligence';
    private const LAST_RUN_OPTION = 'tmwseo_intel_materialized_last_run';

    public static function materialize_all(): array {
        $metrics = [
            'top_opportunities' => 0,
            'cluster_summary' => 0,
            'entity_keyword_map' => 0,
            'keyword_trends' => 0,
        ];

        $metrics['top_opportunities'] = self::materialize_top_opportunities();
        $metrics['cluster_summary'] = self::materialize_cluster_summary();
        $metrics['entity_keyword_map'] = self::materialize_entity_keyword_map();
        $metrics['keyword_trends'] = self::materialize_keyword_trends();

        $run_payload = [
            'ran_at' => current_time('mysql'),
            'metrics' => $metrics,
        ];

        update_option(self::LAST_RUN_OPTION, $run_payload, false);

        return $run_payload;
    }

    public static function get_last_run(): array {
        $data = get_option(self::LAST_RUN_OPTION, []);
        return is_array($data) ? $data : [];
    }

    private static function materialize_top_opportunities(): int {
        global $wpdb;

        $source_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $target_table = $wpdb->prefix . 'tmwseo_top_opportunities';

        $rows = $wpdb->get_results(
            "SELECT keyword, volume, difficulty, serp_weakness, opportunity, graph_cluster_id
             FROM {$source_table}
             WHERE volume >= 30
               AND difficulty <= 50
               AND serp_weakness >= 0.2
             ORDER BY opportunity DESC, volume DESC
             LIMIT 500",
            ARRAY_A
        );

        $wpdb->query("TRUNCATE TABLE {$target_table}");

        $inserted = 0;
        foreach ((array) $rows as $row) {
            $volume = (int) ($row['volume'] ?? 0);
            $difficulty = (float) ($row['difficulty'] ?? 0);
            $serp_weakness = (float) ($row['serp_weakness'] ?? 0);
            $base_opportunity = isset($row['opportunity']) ? (float) $row['opportunity'] : 0.0;

            $opportunity_score = ($base_opportunity > 0)
                ? $base_opportunity
                : round(($volume * max($serp_weakness, 0.0)) / max($difficulty, 1.0), 4);

            $ok = $wpdb->insert(
                $target_table,
                [
                    'keyword' => (string) ($row['keyword'] ?? ''),
                    'search_volume' => $volume,
                    'difficulty' => $difficulty,
                    'serp_weakness' => $serp_weakness,
                    'cluster_id' => (string) ($row['graph_cluster_id'] ?? ''),
                    'opportunity_score' => $opportunity_score,
                    'materialized_at' => current_time('mysql'),
                ],
                ['%s', '%d', '%f', '%f', '%s', '%f', '%s']
            );

            if ($ok) {
                $inserted++;
            }
        }

        return $inserted;
    }

    private static function materialize_cluster_summary(): int {
        global $wpdb;

        $source_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $target_table = $wpdb->prefix . 'tmwseo_cluster_summary';

        $rows = $wpdb->get_results(
            "SELECT graph_cluster_id AS cluster_id,
                    COUNT(*) AS cluster_size,
                    AVG(COALESCE(volume, 0)) AS avg_volume,
                    AVG(COALESCE(difficulty, 0)) AS avg_difficulty
             FROM {$source_table}
             WHERE graph_cluster_id IS NOT NULL
               AND graph_cluster_id <> ''
             GROUP BY graph_cluster_id
             ORDER BY cluster_size DESC",
            ARRAY_A
        );

        $wpdb->query("TRUNCATE TABLE {$target_table}");

        $inserted = 0;
        foreach ((array) $rows as $row) {
            $ok = $wpdb->insert(
                $target_table,
                [
                    'cluster_id' => (string) ($row['cluster_id'] ?? ''),
                    'cluster_size' => (int) ($row['cluster_size'] ?? 0),
                    'avg_volume' => round((float) ($row['avg_volume'] ?? 0), 2),
                    'avg_difficulty' => round((float) ($row['avg_difficulty'] ?? 0), 2),
                    'materialized_at' => current_time('mysql'),
                ],
                ['%s', '%d', '%f', '%f', '%s']
            );

            if ($ok) {
                $inserted++;
            }
        }

        return $inserted;
    }

    private static function materialize_entity_keyword_map(): int {
        global $wpdb;

        $source_table = $wpdb->prefix . 'tmw_keywords';
        $target_table = $wpdb->prefix . 'tmwseo_entity_keyword_map';

        $rows = $wpdb->get_results(
            "SELECT DISTINCT keyword, entity_type, entity_id
             FROM {$source_table}
             WHERE keyword <> ''
               AND entity_id > 0
               AND entity_type IN ('model', 'tag', 'category', 'post_tag')",
            ARRAY_A
        );

        $wpdb->query("TRUNCATE TABLE {$target_table}");

        $inserted = 0;
        foreach ((array) $rows as $row) {
            $entity_type = (string) ($row['entity_type'] ?? '');
            if ($entity_type === 'post_tag') {
                $entity_type = 'tag';
            }

            $ok = $wpdb->insert(
                $target_table,
                [
                    'keyword' => (string) ($row['keyword'] ?? ''),
                    'entity_type' => $entity_type,
                    'entity_id' => (int) ($row['entity_id'] ?? 0),
                    'materialized_at' => current_time('mysql'),
                ],
                ['%s', '%s', '%d', '%s']
            );

            if ($ok) {
                $inserted++;
            }
        }

        return $inserted;
    }

    private static function materialize_keyword_trends(): int {
        global $wpdb;

        $source_table = $wpdb->prefix . 'tmwseo_engine_rank_history';
        $target_table = $wpdb->prefix . 'tmwseo_keyword_trends';

        $rows = $wpdb->get_results(
            "SELECT keyword, position, checked_at
             FROM {$source_table}
             ORDER BY keyword ASC, checked_at DESC",
            ARRAY_A
        );

        $wpdb->query("TRUNCATE TABLE {$target_table}");

        $latest = [];
        $previous = [];

        foreach ((array) $rows as $row) {
            $keyword = (string) ($row['keyword'] ?? '');
            if ($keyword === '') {
                continue;
            }

            if (!isset($latest[$keyword])) {
                $latest[$keyword] = $row;
                continue;
            }

            if (!isset($previous[$keyword])) {
                $previous[$keyword] = $row;
            }
        }

        $inserted = 0;
        foreach ($latest as $keyword => $current_row) {
            $current_position = (int) ($current_row['position'] ?? 0);
            if ($current_position <= 0) {
                continue;
            }

            $previous_position = isset($previous[$keyword]) ? (int) ($previous[$keyword]['position'] ?? 0) : 0;
            $rank_change = ($previous_position > 0) ? ($previous_position - $current_position) : 0;
            $trend_score = round(($rank_change * 5) + max(0, (50 - $current_position)), 2);

            $ok = $wpdb->insert(
                $target_table,
                [
                    'keyword' => $keyword,
                    'current_position' => $current_position,
                    'previous_position' => $previous_position,
                    'rank_change' => $rank_change,
                    'trend_score' => $trend_score,
                    'snapshot_week' => gmdate('o-\\WW'),
                    'materialized_at' => current_time('mysql'),
                ],
                ['%s', '%d', '%d', '%d', '%f', '%s', '%s']
            );

            if ($ok) {
                $inserted++;
            }
        }

        return $inserted;
    }
}
