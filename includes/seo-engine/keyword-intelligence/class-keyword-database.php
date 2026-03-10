<?php
namespace TMWSEO\Engine\KeywordIntelligence;

if (!defined('ABSPATH')) { exit; }

class KeywordDatabase {
    public const TABLE_SUFFIX = 'tmwseo_keywords';
    public const ENTITY_TABLE_SUFFIX = 'tmw_seo_entities';
    public const ENTITY_KEYWORD_TABLE_SUFFIX = 'tmw_seo_entity_keyword';

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function entity_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::ENTITY_TABLE_SUFFIX;
    }

    public static function entity_keyword_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::ENTITY_KEYWORD_TABLE_SUFFIX;
    }

    public static function create_table(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword VARCHAR(255) NOT NULL,
            search_volume INT(11) NOT NULL DEFAULT 0,
            difficulty DECIMAL(6,2) NOT NULL DEFAULT 0,
            expanded_level TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            serp_weakness DECIMAL(6,2) NOT NULL DEFAULT 0,
            ranking_probability DECIMAL(6,4) NOT NULL DEFAULT 0,
            opportunity_score DECIMAL(10,2) NOT NULL DEFAULT 0,
            source VARCHAR(50) NOT NULL DEFAULT 'dataforseo',
            intent_type VARCHAR(50) NOT NULL DEFAULT 'generic',
            entity_type VARCHAR(50) NOT NULL DEFAULT 'generic',
            entity_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            mapped_url VARCHAR(255) NULL,
            last_checked DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY keyword (keyword),
            KEY quality_filter (search_volume, difficulty, ranking_probability),
            KEY expansion_level (expanded_level),
            KEY freshness (last_checked),
            KEY score (opportunity_score),
            KEY intent_entity (intent_type, entity_type, entity_id)
        ) {$charset_collate};";

        dbDelta($sql);

        $entity_table = self::entity_table_name();
        $entity_keyword_table = self::entity_keyword_table_name();

        $entity_sql = "CREATE TABLE {$entity_table} (
            entity_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(50) NOT NULL,
            entity_name VARCHAR(191) NOT NULL,
            PRIMARY KEY (entity_id),
            UNIQUE KEY entity_unique (entity_type, entity_name),
            KEY entity_lookup (entity_type)
        ) {$charset_collate};";

        $entity_keyword_sql = "CREATE TABLE {$entity_keyword_table} (
            keyword_id BIGINT(20) UNSIGNED NOT NULL,
            entity_id BIGINT(20) UNSIGNED NOT NULL,
            PRIMARY KEY (keyword_id, entity_id),
            KEY entity_lookup (entity_id),
            KEY keyword_lookup (keyword_id)
        ) {$charset_collate};";

        dbDelta($entity_sql);
        dbDelta($entity_keyword_sql);
    }

    /**
     * @param string[] $keywords
     * @return array<string,array<string,mixed>>
     */
    public static function get_recent_metrics_map(array $keywords, int $fresh_days = 30): array {
        global $wpdb;

        $keywords = array_values(array_unique(array_filter(array_map('strval', $keywords))));
        if (empty($keywords)) {
            return [];
        }

        $table = self::table_name();
        $fresh_cutoff = gmdate('Y-m-d H:i:s', time() - (DAY_IN_SECONDS * max(1, $fresh_days)));
        $placeholders = implode(', ', array_fill(0, count($keywords), '%s'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT keyword, search_volume, difficulty, serp_weakness, opportunity_score, last_checked, source
                 FROM {$table}
                 WHERE keyword IN ({$placeholders})
                   AND last_checked >= %s",
                ...array_merge($keywords, [$fresh_cutoff])
            ),
            ARRAY_A
        );

        $map = [];
        foreach ((array) $rows as $row) {
            $kw = (string) ($row['keyword'] ?? '');
            if ($kw === '') {
                continue;
            }

            $map[$kw] = $row;
        }

        return $map;
    }

    /**
     * @param array{keyword:string,search_volume?:int,difficulty?:float,expanded_level?:int,serp_weakness?:float,opportunity_score?:float,source?:string,intent_type?:string,entity_type?:string,entity_id?:int,entities?:array<int,array{entity_type:string,entity_name:string,source_id?:int}>} $metrics
     */
    public static function upsert_metrics(array $metrics): void {
        global $wpdb;

        $keyword = trim((string) ($metrics['keyword'] ?? ''));
        if ($keyword === '') {
            return;
        }

        $table = self::table_name();
        $now = current_time('mysql');

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (keyword, search_volume, difficulty, expanded_level, serp_weakness, opportunity_score, source, intent_type, entity_type, entity_id, last_checked, created_at, updated_at)
             VALUES (%s, %d, %f, %d, %f, %f, %s, %s, %s, %d, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                search_volume = VALUES(search_volume),
                difficulty = VALUES(difficulty),
                expanded_level = GREATEST(expanded_level, VALUES(expanded_level)),
                serp_weakness = VALUES(serp_weakness),
                opportunity_score = VALUES(opportunity_score),
                source = VALUES(source),
                intent_type = VALUES(intent_type),
                entity_type = VALUES(entity_type),
                entity_id = VALUES(entity_id),
                last_checked = VALUES(last_checked),
                updated_at = VALUES(updated_at)",
            $keyword,
            (int) ($metrics['search_volume'] ?? 0),
            (float) ($metrics['difficulty'] ?? 0),
            max(0, (int) ($metrics['expanded_level'] ?? 0)),
            (float) ($metrics['serp_weakness'] ?? 0),
            (float) ($metrics['opportunity_score'] ?? 0),
            (string) ($metrics['source'] ?? 'dataforseo'),
            (string) ($metrics['intent_type'] ?? 'generic'),
            (string) ($metrics['entity_type'] ?? 'generic'),
            (int) ($metrics['entity_id'] ?? 0),
            $now,
            $now,
            $now
        ));

        self::sync_keyword_entities((int) $wpdb->insert_id, $keyword, (array) ($metrics['entities'] ?? []));
    }

    /**
     * @param array<int,array{entity_type:string,entity_name:string,source_id?:int}> $entities
     */
    private static function sync_keyword_entities(int $keyword_id, string $keyword, array $entities): void {
        global $wpdb;

        if ($keyword_id <= 0) {
            $keyword_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM " . self::table_name() . " WHERE keyword = %s LIMIT 1",
                $keyword
            ));
        }

        if ($keyword_id <= 0) {
            return;
        }

        $normalized = [];
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $type = sanitize_key((string) ($entity['entity_type'] ?? ''));
            $name = strtolower(trim((string) ($entity['entity_name'] ?? '')));
            if ($type === '' || $name === '') {
                continue;
            }
            $key = $type . '::' . $name;
            $normalized[$key] = ['entity_type' => $type, 'entity_name' => $name];
        }

        if (empty($normalized)) {
            return;
        }

        $entity_ids = [];
        $entity_table = self::entity_table_name();
        $entity_keyword_table = self::entity_keyword_table_name();

        foreach (array_values($normalized) as $entity) {
            $type = $entity['entity_type'];
            $name = $entity['entity_name'];

            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$entity_table} (entity_type, entity_name) VALUES (%s, %s)
                 ON DUPLICATE KEY UPDATE entity_id = LAST_INSERT_ID(entity_id)",
                $type,
                $name
            ));

            $entity_id = (int) $wpdb->insert_id;
            if ($entity_id <= 0) {
                $entity_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT entity_id FROM {$entity_table} WHERE entity_type = %s AND entity_name = %s LIMIT 1",
                    $type,
                    $name
                ));
            }

            if ($entity_id > 0) {
                $entity_ids[] = $entity_id;
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$entity_keyword_table} (keyword_id, entity_id) VALUES (%d, %d)
                     ON DUPLICATE KEY UPDATE keyword_id = VALUES(keyword_id)",
                    $keyword_id,
                    $entity_id
                ));
            }
        }

        $entity_ids = array_values(array_unique(array_filter($entity_ids)));
        if (empty($entity_ids)) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($entity_ids), '%d'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$entity_keyword_table}
             WHERE keyword_id = %d
               AND entity_id NOT IN ({$placeholders})",
            ...array_merge([$keyword_id], $entity_ids)
        ));
    }

    /**
     * @param string[] $keywords
     * @return array<string,int>
     */
    public static function get_expanded_levels(array $keywords): array {
        global $wpdb;

        $keywords = array_values(array_unique(array_filter(array_map('strval', $keywords))));
        if (empty($keywords)) {
            return [];
        }

        $table = self::table_name();
        $placeholders = implode(', ', array_fill(0, count($keywords), '%s'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT keyword, expanded_level FROM {$table} WHERE keyword IN ({$placeholders})",
                ...$keywords
            ),
            ARRAY_A
        );

        $map = [];
        foreach ((array) $rows as $row) {
            $keyword = (string) ($row['keyword'] ?? '');
            if ($keyword === '') {
                continue;
            }

            $map[$keyword] = (int) ($row['expanded_level'] ?? 0);
        }

        return $map;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function get_generation_candidates_by_entity_combinations(int $limit): array {
        global $wpdb;

        $limit = max(1, min(100, $limit));
        $table = self::table_name();
        $entity_table = self::entity_table_name();
        $entity_keyword_table = self::entity_keyword_table_name();

        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT k.keyword, k.search_volume, k.difficulty, k.ranking_probability, k.mapped_url,
                    SUM(CASE WHEN e.entity_type = 'model' THEN 1 ELSE 0 END) AS model_count,
                    SUM(CASE WHEN e.entity_type = 'category' THEN 1 ELSE 0 END) AS category_count,
                    GROUP_CONCAT(DISTINCT CONCAT(e.entity_type, ':', e.entity_name) ORDER BY e.entity_type, e.entity_name SEPARATOR '|') AS entity_combo
             FROM {$table} k
             LEFT JOIN {$entity_keyword_table} ek ON ek.keyword_id = k.id
             LEFT JOIN {$entity_table} e ON e.entity_id = ek.entity_id
             WHERE k.search_volume >= %d
               AND k.difficulty <= %d
               AND k.ranking_probability >= %f
               AND (k.mapped_url IS NULL OR k.mapped_url = '')
             GROUP BY k.id
             ORDER BY k.ranking_probability DESC, k.search_volume DESC
             LIMIT %d",
            50,
            40,
            0.6,
            $limit
        ), ARRAY_A);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function get_generation_candidates(int $limit): array {
        global $wpdb;

        $limit = max(1, min(100, $limit));
        $table = self::table_name();

        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT keyword, search_volume, difficulty, ranking_probability, mapped_url, intent_type, entity_type, entity_id
             FROM {$table}
             WHERE search_volume >= %d
               AND difficulty <= %d
               AND ranking_probability >= %f
               AND (mapped_url IS NULL OR mapped_url = '')
             ORDER BY ranking_probability DESC, search_volume DESC
             LIMIT %d",
            50,
            40,
            0.6,
            $limit
        ), ARRAY_A);
    }
}
