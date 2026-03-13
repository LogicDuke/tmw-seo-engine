<?php
namespace TMWSEO\Engine\Services;

if (!defined('ABSPATH')) { exit; }

class TopicAuthorityEngine {
    private const CACHE_TABLE = 'tmw_seo_topic_authority';

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function build_topic_authority_map(): array {
        $clusters = self::read_clusters();
        if (empty($clusters)) {
            return [];
        }

        $maps = [];
        foreach ($clusters as $cluster) {
            $pillar = self::detect_pillar_keyword($cluster);
            if ($pillar === '') {
                continue;
            }

            $children = self::detect_supporting_keywords($cluster, $pillar);
            $topic = [
                'cluster_id' => (int) ($cluster['cluster_id'] ?? 0),
                'pillar' => $pillar,
                'slug' => sanitize_title($pillar),
                'children' => $children,
            ];

            $topic['silo_structure'] = self::generate_silo_structure($topic);
            $topic['internal_link_map'] = self::generate_internal_link_map($topic);
            $topic['cluster_size'] = (int) max(1, count($children) + 1);
            $topic['pages_planned'] = (int) (count($children) + 1);
            $topic['ranking_probability_average'] = (float) ($cluster['ranking_probability'] ?? 0.0);
            $topic['authority_score'] = self::calculate_topic_authority_score($topic);

            self::cache_topic_result($topic);
            $maps[] = $topic;
        }

        return $maps;
    }

    /** @param array<string,mixed> $topic */
    public static function generate_silo_structure(array $topic): array {
        $pillar_slug = sanitize_title((string) ($topic['pillar'] ?? ''));
        $children = (array) ($topic['children'] ?? []);

        $child_nodes = [];
        foreach ($children as $child) {
            $child_keyword = sanitize_text_field((string) $child);
            if ($child_keyword === '') {
                continue;
            }

            $child_slug = sanitize_title($child_keyword);
            $child_nodes[] = [
                'keyword' => $child_keyword,
                'slug' => $child_slug,
                'path' => '/' . $pillar_slug . '/' . $child_slug . '/',
            ];
        }

        return [
            'pillar' => [
                'keyword' => sanitize_text_field((string) ($topic['pillar'] ?? '')),
                'slug' => $pillar_slug,
                'path' => '/' . $pillar_slug . '/',
            ],
            'children' => $child_nodes,
        ];
    }

    /** @param array<string,mixed> $topic */
    public static function generate_internal_link_map(array $topic): array {
        $children = array_values(array_filter(array_map('sanitize_text_field', (array) ($topic['children'] ?? []))));

        return [
            'pillar_links_to' => $children,
            'child_links_to_pillar' => true,
        ];
    }

    /** @param array<string,mixed> $topic */
    public static function calculate_topic_authority_score(array $topic): float {
        $cluster_size = (int) max(1, (int) ($topic['cluster_size'] ?? 1));
        $ranking_probability = (float) ($topic['ranking_probability_average'] ?? 0.0);
        $pages_planned = (int) max(1, (int) ($topic['pages_planned'] ?? 1));

        $ranking_probability_average = max(0.0, min(1.0, $ranking_probability / 100));
        $internal_link_strength = max(0.1, min(1.0, ((count((array) ($topic['children'] ?? [])) * 2) / max(2, $pages_planned * 2))));
        $keyword_coverage = max(0.1, min(1.0, $pages_planned / max(1, $cluster_size)));

        $score = ($cluster_size * 2) * $ranking_probability_average * $internal_link_strength * $keyword_coverage;
        return round(max(0, min(100, $score)), 2);
    }

    public static function get_summary(): array {
        $topics = self::build_topic_authority_map();
        if (empty($topics)) {
            return [
                'clusters' => 0,
                'average_authority_score' => 0.0,
                'pillar_topics' => 0,
            ];
        }

        $scores = array_map(static fn(array $topic): float => (float) ($topic['authority_score'] ?? 0.0), $topics);
        return [
            'clusters' => count($topics),
            'average_authority_score' => round(array_sum($scores) / max(1, count($scores)), 2),
            'pillar_topics' => count($topics),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function read_clusters(): array {
        global $wpdb;

        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';
        $stats_table = $wpdb->prefix . 'tmw_seo_cluster_stats';
        $cluster_map_table = $wpdb->prefix . 'tmw_keyword_cluster_map';

        $has_keyword_clusters = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $cluster_table)) === $cluster_table;
        if ($has_keyword_clusters) {
            $has_stats = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $stats_table)) === $stats_table;
            $stats_select = $has_stats ? 'COALESCE(s.ranking_probability, 0) AS ranking_probability' : '0 AS ranking_probability';
            $stats_join = $has_stats ? "LEFT JOIN {$stats_table} s ON s.cluster_id = c.id" : '';

            $rows = $wpdb->get_results(
                "SELECT c.id AS cluster_id, c.representative, c.keywords, {$stats_select} FROM {$cluster_table} c {$stats_join} ORDER BY c.total_volume DESC LIMIT 100",
                ARRAY_A
            );

            $out = [];
            foreach ((array) $rows as $row) {
                $keywords = json_decode((string) ($row['keywords'] ?? ''), true);
                if (!is_array($keywords)) {
                    $keywords = [];
                }

                if (empty($keywords) && (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $cluster_map_table)) === $cluster_map_table) {
                    $keywords = $wpdb->get_col($wpdb->prepare(
                        "SELECT keyword FROM {$cluster_map_table} WHERE cluster_id = %d ORDER BY updated_at DESC LIMIT 50",
                        (int) ($row['cluster_id'] ?? 0)
                    ));
                }

                $out[] = [
                    'cluster_id' => (int) ($row['cluster_id'] ?? 0),
                    'representative' => (string) ($row['representative'] ?? ''),
                    'keywords' => $keywords,
                    'ranking_probability' => (float) ($row['ranking_probability'] ?? 0),
                ];
            }

            return $out;
        }

        return [];
    }

    /** @param array<string,mixed> $cluster */
    private static function detect_pillar_keyword(array $cluster): string {
        $representative = sanitize_text_field((string) ($cluster['representative'] ?? ''));
        if ($representative !== '') {
            return strtolower($representative);
        }

        $keywords = (array) ($cluster['keywords'] ?? []);
        $first = sanitize_text_field((string) ($keywords[0] ?? ''));
        return strtolower($first);
    }

    /** @param array<string,mixed> $cluster */
    private static function detect_supporting_keywords(array $cluster, string $pillar): array {
        $keywords = (array) ($cluster['keywords'] ?? []);
        $supporting = [];

        foreach ($keywords as $keyword) {
            $clean = strtolower(sanitize_text_field((string) $keyword));
            if ($clean === '' || $clean === $pillar) {
                continue;
            }
            $supporting[] = $clean;
        }

        return array_values(array_slice(array_unique($supporting), 0, 12));
    }

    /** @param array<string,mixed> $topic */
    private static function cache_topic_result(array $topic): void {
        global $wpdb;

        self::maybe_create_cache_table();
        $table = $wpdb->prefix . self::CACHE_TABLE;
        $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if (!$exists) {
            return;
        }

        $wpdb->insert(
            $table,
            [
                'pillar_keyword' => sanitize_text_field((string) ($topic['pillar'] ?? '')),
                'cluster_size' => (int) ($topic['cluster_size'] ?? 0),
                'authority_score' => (float) ($topic['authority_score'] ?? 0),
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%d', '%f', '%s']
        );
    }

    private static function maybe_create_cache_table(): void {
        static $created = false;
        if ($created) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::CACHE_TABLE;
        $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if ($exists) {
            $created = true;
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pillar_keyword VARCHAR(191) NOT NULL,
            cluster_size INT UNSIGNED NOT NULL DEFAULT 0,
            authority_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY pillar_keyword (pillar_keyword)
        ) {$charset_collate};");

        $created = true;
    }
}
