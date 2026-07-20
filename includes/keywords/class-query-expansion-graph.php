<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class QueryExpansionGraph {
    public const TABLE_SUFFIX = 'tmwseo_keyword_graph';

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function create_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_keyword VARCHAR(255) NOT NULL,
            child_keyword VARCHAR(255) NOT NULL,
            depth TINYINT(3) UNSIGNED NOT NULL DEFAULT 1,
            search_volume INT(11) NOT NULL DEFAULT 0,
            keyword_difficulty DECIMAL(6,2) NOT NULL DEFAULT 0,
            source VARCHAR(40) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY parent_keyword (parent_keyword),
            KEY child_keyword (child_keyword),
            KEY depth (depth),
            KEY source (source),
            KEY keyword_edge (parent_keyword, child_keyword),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    public static function store_relationship(string $keyword, string $related_keyword, string $source): bool {
        global $wpdb;

        $keyword = KeywordValidator::normalize($keyword);
        $related_keyword = KeywordValidator::normalize($related_keyword);
        $source = sanitize_key($source);

        if ($keyword === '' || $related_keyword === '' || $source === '') {
            return false;
        }

        $inserted = $wpdb->insert(self::table_name(), [
            'parent_keyword' => $keyword,
            'child_keyword' => $related_keyword,
            'depth' => 1,
            'search_volume' => 0,
            'keyword_difficulty' => 0.0,
            'source' => $source,
            'created_at' => current_time('mysql'),
        ], ['%s', '%s', '%d', '%d', '%f', '%s', '%s']);

        return $inserted !== false;
    }

    public static function mark_expanded(string $keyword): void {
        self::store_relationship($keyword, $keyword, 'expanded');
    }

    public static function was_expanded_recently(string $keyword, int $days = 30): bool {
        global $wpdb;

        $keyword = KeywordValidator::normalize($keyword);
        if ($keyword === '') {
            return true;
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - (DAY_IN_SECONDS * max(1, $days)));
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::table_name() . "
             WHERE parent_keyword = %s
               AND source = 'expanded'
               AND created_at >= %s",
            $keyword,
            $cutoff
        ));

        return $count > 0;
    }

    /** @return array<string,int> */
    public static function generate_topic_clusters(): array {
        global $wpdb;

        $rows = (array) $wpdb->get_results(
            "SELECT parent_keyword, child_keyword, COUNT(*) AS rel_count
             FROM " . self::table_name() . "
             WHERE source IN ('suggestion', 'related', 'dataforseo_suggest', 'related_keywords', 'dataforseo_keyword_suggestions')
             GROUP BY parent_keyword, child_keyword
             HAVING COUNT(*) >= 2",
            ARRAY_A
        );

        if (empty($rows)) {
            self::reset_graph_metrics();
            return [
                'nodes' => 0,
                'relationships' => 0,
                'clusters' => 0,
            ];
        }

        $adj = [];
        foreach ($rows as $row) {
            $a = KeywordValidator::normalize((string) ($row['parent_keyword'] ?? ''));
            $b = KeywordValidator::normalize((string) ($row['child_keyword'] ?? ''));
            if ($a === '' || $b === '') {
                continue;
            }

            $adj[$a][$b] = true;
            $adj[$b][$a] = true;
        }

        $nodes = array_keys($adj);
        $node_degree = [];
        foreach ($adj as $node => $neighbors) {
            $node_degree[$node] = count($neighbors);
        }

        $cluster_assignments = [];
        $cluster_sizes = [];
        $visited = [];
        $cluster_index = 0;

        foreach ($nodes as $start) {
            if (!empty($visited[$start])) {
                continue;
            }

            $cluster_index++;
            $cluster_id = 'g' . $cluster_index;
            $stack = [$start];
            $component = [];

            while (!empty($stack)) {
                $node = array_pop($stack);
                if (!empty($visited[$node])) {
                    continue;
                }

                $visited[$node] = true;
                $component[] = $node;
                foreach (array_keys($adj[$node] ?? []) as $neighbor) {
                    if (empty($visited[$neighbor])) {
                        $stack[] = $neighbor;
                    }
                }
            }

            $size = count($component);
            foreach ($component as $node) {
                $cluster_assignments[$node] = $cluster_id;
                $cluster_sizes[$node] = $size;
            }
        }

        self::persist_metrics($cluster_assignments, $cluster_sizes, $node_degree);

        Logs::info('keywords', '[TMW-GRAPH] Graph clusters generated', [
            'nodes_created' => count($nodes),
            'relationships_created' => count($rows),
            'clusters_generated' => $cluster_index,
        ]);

        return [
            'nodes' => count($nodes),
            'relationships' => count($rows),
            'clusters' => $cluster_index,
        ];
    }

    private static function reset_graph_metrics(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $wpdb->query("UPDATE {$table} SET node_degree = 0, graph_cluster_id = NULL, graph_cluster_size = 0");
    }

    /**
     * @param array<string,string> $cluster_assignments
     * @param array<string,int> $cluster_sizes
     * @param array<string,int> $node_degree
     */
    private static function persist_metrics(array $cluster_assignments, array $cluster_sizes, array $node_degree): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_keyword_candidates';

        $wpdb->query("UPDATE {$table} SET node_degree = 0, graph_cluster_id = NULL, graph_cluster_size = 0");

        foreach ($cluster_assignments as $keyword => $cluster_id) {
            $wpdb->update($table, [
                'node_degree' => (int) ($node_degree[$keyword] ?? 0),
                'graph_cluster_id' => (string) $cluster_id,
                'graph_cluster_size' => (int) ($cluster_sizes[$keyword] ?? 0),
            ], [
                'keyword' => $keyword,
            ], ['%d', '%s', '%d'], ['%s']);
        }
    }
}
