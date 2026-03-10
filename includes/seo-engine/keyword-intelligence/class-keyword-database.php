<?php
namespace TMWSEO\Engine\KeywordIntelligence;

if (!defined('ABSPATH')) { exit; }

class KeywordDatabase {
    public const TABLE_SUFFIX = 'tmwseo_keywords';

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
            keyword VARCHAR(255) NOT NULL,
            search_volume INT(11) NOT NULL DEFAULT 0,
            difficulty DECIMAL(6,2) NOT NULL DEFAULT 0,
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
            KEY freshness (last_checked),
            KEY score (opportunity_score),
            KEY intent_entity (intent_type, entity_type, entity_id)
        ) {$charset_collate};";

        dbDelta($sql);
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
     * @param array{keyword:string,search_volume?:int,difficulty?:float,serp_weakness?:float,opportunity_score?:float,source?:string,intent_type?:string,entity_type?:string,entity_id?:int} $metrics
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
            "INSERT INTO {$table} (keyword, search_volume, difficulty, serp_weakness, opportunity_score, source, intent_type, entity_type, entity_id, last_checked, created_at, updated_at)
             VALUES (%s, %d, %f, %f, %f, %s, %s, %s, %d, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                search_volume = VALUES(search_volume),
                difficulty = VALUES(difficulty),
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
