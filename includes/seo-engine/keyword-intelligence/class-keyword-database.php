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
            serp_score DECIMAL(6,2) NOT NULL DEFAULT 0,
            ranking_probability DECIMAL(6,4) NOT NULL DEFAULT 0,
            opportunity_score DECIMAL(10,2) NOT NULL DEFAULT 0,
            source VARCHAR(50) NOT NULL DEFAULT 'dataforseo',
            mapped_url VARCHAR(255) NULL,
            last_checked DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY keyword (keyword),
            KEY quality_filter (search_volume, difficulty, ranking_probability),
            KEY freshness (last_checked),
            KEY score (opportunity_score)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function get_generation_candidates(int $limit): array {
        global $wpdb;

        $limit = max(1, min(100, $limit));
        $table = self::table_name();

        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT keyword, search_volume, difficulty, ranking_probability, mapped_url
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
