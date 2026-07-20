<?php
/**
 * Keyword usage tracking — prevents keyword cannibalization.
 *
 * Maintains two tables:
 *   - tmwseo_keyword_usage  : per-keyword usage counter + last-used timestamp
 *   - tmwseo_keyword_usage_log : full audit log of every keyword assignment
 *
 * @package TMWSEO\Engine\Keywords
 */
namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class KeywordUsage {

    const SCHEMA_VERSION = 1;

    // ── Table names ────────────────────────────────────────────────────────

    public static function usage_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmwseo_keyword_usage';
    }

    public static function log_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmwseo_keyword_usage_log';
    }

    // ── Install / upgrade ──────────────────────────────────────────────────

    public static function maybe_upgrade(): void {
        $current = (int) get_option( 'tmwseo_keyword_usage_schema', 0 );
        if ( $current < self::SCHEMA_VERSION ) {
            self::install();
            update_option( 'tmwseo_keyword_usage_schema', self::SCHEMA_VERSION, false );
        }
    }

    public static function install(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $usage = self::usage_table();
        $log   = self::log_table();

        $sql_usage = "CREATE TABLE {$usage} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword_hash CHAR(32) NOT NULL,
            keyword_text TEXT NOT NULL,
            category VARCHAR(64) NOT NULL DEFAULT '',
            type VARCHAR(16) NOT NULL DEFAULT '',
            used_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_used_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY keyword_hash (keyword_hash)
        ) {$charset_collate};";

        $sql_log = "CREATE TABLE {$log} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword_hash CHAR(32) NOT NULL,
            keyword_text TEXT NOT NULL,
            category VARCHAR(64) NOT NULL DEFAULT '',
            type VARCHAR(16) NOT NULL DEFAULT '',
            post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            post_type VARCHAR(32) NOT NULL DEFAULT '',
            used_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY keyword_hash (keyword_hash),
            KEY used_at (used_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_usage );
        dbDelta( $sql_log );

        update_option( 'tmwseo_keyword_usage_schema', self::SCHEMA_VERSION, false );
    }

    // ── Read helpers ───────────────────────────────────────────────────────

    /**
     * Returns true if the keyword has ever been assigned to any post.
     */
    public static function is_used( string $keyword ): bool {
        global $wpdb;
        $keyword = strtolower( trim( $keyword ) );
        if ( $keyword === '' ) {
            return false;
        }

        $hash = md5( $keyword );
        $row  = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT used_count FROM %i WHERE keyword_hash = %s",
                self::usage_table(),
                $hash
            )
        );

        return $row && (int) $row->used_count > 0;
    }

    /**
     * Returns all used keywords (lowercase) as a flat array.
     */
    public static function get_all_used(): array {
        global $wpdb;
        $rows = $wpdb->get_col(
            "SELECT keyword_text FROM " . self::usage_table() . " WHERE used_count > 0"
        );
        return array_map( 'strtolower', (array) $rows );
    }

    // ── Write helpers ──────────────────────────────────────────────────────

    /**
     * Records that one or more keywords were used for a post.
     *
     * @param string[] $keywords
     * @param string   $category  Category slug (may be empty).
     * @param string   $type      'extra', 'longtail', 'competitor', or ''.
     * @param int      $post_id
     * @param string   $post_type
     */
    public static function record_usage(
        array  $keywords,
        string $category,
        string $type,
        int    $post_id,
        string $post_type
    ): void {
        global $wpdb;

        $now = current_time( 'mysql' );

        foreach ( $keywords as $kw ) {
            $kw = strtolower( trim( (string) $kw ) );
            if ( $kw === '' ) {
                continue;
            }

            $hash = md5( $kw );

            // Upsert into usage table.
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO %i (keyword_hash, keyword_text, category, type, used_count, last_used_at)
                     VALUES (%s, %s, %s, %s, 1, %s)
                     ON DUPLICATE KEY UPDATE
                       used_count    = used_count + 1,
                       last_used_at  = %s",
                    self::usage_table(),
                    $hash,
                    $kw,
                    sanitize_key( $category ),
                    sanitize_key( $type ),
                    $now,
                    $now
                )
            );

            // Audit log.
            $wpdb->insert(
                self::log_table(),
                [
                    'keyword_hash' => $hash,
                    'keyword_text' => $kw,
                    'category'     => sanitize_key( $category ),
                    'type'         => sanitize_key( $type ),
                    'post_id'      => (int) $post_id,
                    'post_type'    => sanitize_key( $post_type ),
                    'used_at'      => $now,
                ],
                [ '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
            );
        }
    }

    /**
     * Removes a keyword from the used set (e.g. after rollback).
     */
    public static function mark_unused( string $keyword ): void {
        global $wpdb;
        $kw   = strtolower( trim( $keyword ) );
        if ( $kw === '' ) {
            return;
        }
        $hash = md5( $kw );
        $wpdb->update(
            self::usage_table(),
            [ 'used_count' => 0 ],
            [ 'keyword_hash' => $hash ],
            [ '%d' ],
            [ '%s' ]
        );
    }

    // ── Stats helper ───────────────────────────────────────────────────────

    /**
     * Returns a summary for the debug dashboard.
     */
    public static function get_stats(): array {
        global $wpdb;
        $total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::usage_table() );
        $used    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::usage_table() . " WHERE used_count > 0" );
        $log_cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::log_table() );
        return [
            'total_tracked' => $total,
            'total_used'    => $used,
            'log_entries'   => $log_cnt,
        ];
    }
}
