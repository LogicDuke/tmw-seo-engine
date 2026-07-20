<?php
namespace TMWSEO\Engine\TopicAuthority;

if (!defined('ABSPATH')) { exit; }

class TopicAuthorityMapper {
    private const DEFAULT_THRESHOLD = 0.7;

    private static function topic_maps_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmwseo_topic_maps';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function rebuild_topic_maps(float $threshold = self::DEFAULT_THRESHOLD): array {
        global $wpdb;

        $clusters = self::load_keyword_clusters();
        if (empty($clusters)) {
            return [];
        }

        $threshold = max(0.1, min(1.0, $threshold));
        $groups = self::group_clusters_by_similarity($clusters, $threshold);

        $table = self::topic_maps_table();
        $wpdb->query("TRUNCATE TABLE {$table}");

        $results = [];
        foreach ($groups as $group) {
            $map = self::build_topic_map_row($group);
            if (empty($map)) {
                continue;
            }

            $wpdb->insert(
                $table,
                [
                    'topic_name' => $map['topic_name'],
                    'pillar_keyword' => $map['pillar_keyword'],
                    'cluster_ids' => $map['cluster_ids'],
                    'total_search_volume' => $map['total_search_volume'],
                    'created_at' => current_time('mysql'),
                ],
                ['%s', '%s', '%s', '%d', '%s']
            );

            $map['id'] = (int) $wpdb->insert_id;
            $results[] = $map;
        }

        return $results;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function get_topic_maps(): array {
        global $wpdb;

        $table = self::topic_maps_table();
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY total_search_volume DESC, id DESC", ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_map([__CLASS__, 'hydrate_topic_map'], $rows));
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get_topic_map(int $map_id): ?array {
        global $wpdb;

        $table = self::topic_maps_table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $map_id), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }

        return self::hydrate_topic_map($row);
    }

    /**
     * @return array<string,mixed>
     */
    private static function hydrate_topic_map(array $row): array {
        $cluster_ids = self::decode_cluster_ids((string) ($row['cluster_ids'] ?? ''));
        $cluster_lookup = self::load_clusters_by_ids($cluster_ids);

        $supporting = [];
        foreach ($cluster_ids as $cluster_id) {
            $cluster = $cluster_lookup[$cluster_id] ?? null;
            if (!is_array($cluster)) {
                continue;
            }

            $keyword = (string) ($cluster['representative'] ?? '');
            if ($keyword !== '' && $keyword !== (string) ($row['pillar_keyword'] ?? '')) {
                $supporting[] = $keyword;
            }
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'topic_name' => (string) ($row['topic_name'] ?? ''),
            'pillar_keyword' => (string) ($row['pillar_keyword'] ?? ''),
            'cluster_ids' => $cluster_ids,
            'cluster_ids_raw' => (string) ($row['cluster_ids'] ?? ''),
            'supporting_keywords' => array_values(array_unique($supporting)),
            'total_search_volume' => (int) ($row['total_search_volume'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'link_graph' => self::build_link_graph((string) ($row['pillar_keyword'] ?? ''), $supporting),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function load_keyword_clusters(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'tmw_keyword_clusters';
        $rows = $wpdb->get_results(
            "SELECT id, representative, keywords, total_volume FROM {$table} WHERE representative <> '' ORDER BY total_volume DESC, id ASC",
            ARRAY_A
        );

        if (!is_array($rows)) {
            return [];
        }

        $clusters = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $representative = sanitize_text_field((string) ($row['representative'] ?? ''));
            if ($representative === '') {
                continue;
            }

            $keywords = self::extract_keywords((string) ($row['keywords'] ?? ''));
            if (!in_array($representative, $keywords, true)) {
                array_unshift($keywords, $representative);
            }

            $clusters[] = [
                'id' => (int) ($row['id'] ?? 0),
                'representative' => $representative,
                'keywords' => $keywords,
                'total_volume' => max(0, (int) ($row['total_volume'] ?? 0)),
                'tokens' => self::tokenize(implode(' ', $keywords)),
            ];
        }

        return $clusters;
    }

    /** @param int[] $ids */
    private static function load_clusters_by_ids(array $ids): array {
        global $wpdb;

        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $table = $wpdb->prefix . 'tmw_keyword_clusters';
        $query = $wpdb->prepare("SELECT id, representative, total_volume FROM {$table} WHERE id IN ({$placeholders})", $ids);
        $rows = $wpdb->get_results($query, ARRAY_A);

        $lookup = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $lookup[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'representative' => sanitize_text_field((string) ($row['representative'] ?? '')),
                'total_volume' => (int) ($row['total_volume'] ?? 0),
            ];
        }

        return $lookup;
    }

    /**
     * @param array<int,array<string,mixed>> $clusters
     * @return array<int,array<int,array<string,mixed>>>
     */
    private static function group_clusters_by_similarity(array $clusters, float $threshold): array {
        $count = count($clusters);
        $parents = [];
        for ($i = 0; $i < $count; $i++) {
            $parents[$i] = $i;
        }

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $similarity = self::jaccard((array) $clusters[$i]['tokens'], (array) $clusters[$j]['tokens']);
                if ($similarity >= $threshold) {
                    self::union($parents, $i, $j);
                }
            }
        }

        $groups = [];
        for ($i = 0; $i < $count; $i++) {
            $root = self::find($parents, $i);
            if (!isset($groups[$root])) {
                $groups[$root] = [];
            }
            $groups[$root][] = $clusters[$i];
        }

        return array_values($groups);
    }

    /**
     * @param array<int,array<string,mixed>> $group
     * @return array<string,mixed>
     */
    private static function build_topic_map_row(array $group): array {
        if (empty($group)) {
            return [];
        }

        usort($group, static function ($a, $b) {
            return ((int) ($b['total_volume'] ?? 0)) <=> ((int) ($a['total_volume'] ?? 0));
        });

        $pillar = $group[0];
        $pillar_keyword = (string) ($pillar['representative'] ?? '');
        if ($pillar_keyword === '') {
            return [];
        }

        $cluster_ids = [];
        $total_volume = 0;
        foreach ($group as $cluster) {
            $cluster_ids[] = (int) ($cluster['id'] ?? 0);
            $total_volume += max(0, (int) ($cluster['total_volume'] ?? 0));
        }

        $cluster_ids = array_values(array_filter(array_unique($cluster_ids)));

        return [
            'topic_name' => ucwords($pillar_keyword),
            'pillar_keyword' => $pillar_keyword,
            'cluster_ids' => implode(',', $cluster_ids),
            'total_search_volume' => $total_volume,
        ];
    }

    /** @return string[] */
    private static function extract_keywords(string $raw): array {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $raw_keywords = $decoded;
        } else {
            $raw_keywords = preg_split('/[,\n]+/', $raw);
        }

        $keywords = [];
        foreach ((array) $raw_keywords as $keyword) {
            if (!is_scalar($keyword)) {
                continue;
            }

            $value = sanitize_text_field((string) $keyword);
            if ($value !== '') {
                $keywords[] = $value;
            }
        }

        return array_values(array_unique($keywords));
    }

    /** @return string[] */
    private static function tokenize(string $text): array {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text, 'UTF-8'));
        $parts = is_array($parts) ? $parts : [];

        $stop_words = ['the', 'and', 'for', 'with', 'from', 'that', 'this', 'best', 'top', 'how'];
        $tokens = array_values(array_filter($parts, static function ($token) use ($stop_words) {
            return $token !== '' && mb_strlen($token, 'UTF-8') > 2 && !in_array($token, $stop_words, true);
        }));

        return array_values(array_unique($tokens));
    }

    /** @param string[] $left @param string[] $right */
    private static function jaccard(array $left, array $right): float {
        if (empty($left) && empty($right)) {
            return 1.0;
        }

        $left_map = array_fill_keys($left, true);
        $right_map = array_fill_keys($right, true);

        $intersection = count(array_intersect_key($left_map, $right_map));
        $union = count($left_map + $right_map);

        return $union > 0 ? ($intersection / $union) : 0.0;
    }

    /** @param int[] $parents */
    private static function find(array &$parents, int $node): int {
        if ($parents[$node] !== $node) {
            $parents[$node] = self::find($parents, $parents[$node]);
        }
        return $parents[$node];
    }

    /** @param int[] $parents */
    private static function union(array &$parents, int $left, int $right): void {
        $root_left = self::find($parents, $left);
        $root_right = self::find($parents, $right);
        if ($root_left !== $root_right) {
            $parents[$root_right] = $root_left;
        }
    }

    /** @return int[] */
    private static function decode_cluster_ids(string $cluster_ids): array {
        $parts = array_map('trim', explode(',', $cluster_ids));
        $ids = array_values(array_filter(array_map('intval', $parts), static fn($id) => $id > 0));
        return array_values(array_unique($ids));
    }

    /**
     * @param string[] $supporting_keywords
     * @return array<string,array<int,array<string,string>>>
     */
    private static function build_link_graph(string $pillar_keyword, array $supporting_keywords): array {
        $edges = [
            'pillar_to_supporting' => [],
            'supporting_to_pillar' => [],
            'supporting_to_supporting' => [],
        ];

        foreach ($supporting_keywords as $supporting_keyword) {
            $edges['pillar_to_supporting'][] = ['from' => $pillar_keyword, 'to' => $supporting_keyword];
            $edges['supporting_to_pillar'][] = ['from' => $supporting_keyword, 'to' => $pillar_keyword];
        }

        $count = count($supporting_keywords);
        for ($i = 0; $i < $count; $i++) {
            for ($j = 0; $j < $count; $j++) {
                if ($i === $j) {
                    continue;
                }

                $edges['supporting_to_supporting'][] = [
                    'from' => $supporting_keywords[$i],
                    'to' => $supporting_keywords[$j],
                ];
            }
        }

        return $edges;
    }

    /**
     * @return array<int,array<string,string>>
     */
    public static function generate_content_plan(array $map): array {
        $pillar = (string) ($map['pillar_keyword'] ?? '');
        $supporting = (array) ($map['supporting_keywords'] ?? []);

        $plan = [];
        if ($pillar !== '') {
            $plan[] = [
                'title' => 'Ultimate Guide to ' . ucwords($pillar),
                'target_keyword' => $pillar,
                'internal_links' => 'Link out to all supporting pages from the pillar overview section.',
            ];
        }

        foreach ($supporting as $keyword) {
            $plan[] = [
                'title' => ucwords($keyword) . ': Best Options and Tips',
                'target_keyword' => (string) $keyword,
                'internal_links' => 'Link to pillar page and 2 related supporting pages using descriptive anchors.',
            ];
        }

        return $plan;
    }
}
