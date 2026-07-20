<?php
namespace TMWSEO\Engine\Intelligence;

if (!defined('ABSPATH')) { exit; }

class IntelligenceStorage {
    public static function table_content_briefs(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_seo_content_briefs';
    }

    public static function table_cluster_scores(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_seo_cluster_scores';
    }

    public static function table_serp_analysis(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_seo_serp_analysis';
    }

    public static function table_competitors(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_seo_competitors';
    }

    public static function table_ranking_probability(): string {
        global $wpdb;
        return $wpdb->prefix . 'tmw_seo_ranking_probability';
    }

    /**
     * @return string[]
     */
    public static function get_competitor_domains(): array {
        global $wpdb;
        $rows = (array) $wpdb->get_col("SELECT domain FROM " . self::table_competitors() . " WHERE is_active = 1 ORDER BY domain ASC");
        $domains = array_values(array_filter(array_map('strval', $rows)));

        $content_gap_domains_table = $wpdb->prefix . 'tmwseo_competitor_domains';
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $content_gap_domains_table));
        if ($table_exists === $content_gap_domains_table) {
            $gap_domains = (array) $wpdb->get_col("SELECT domain FROM {$content_gap_domains_table} ORDER BY domain ASC");
            $domains = array_merge($domains, array_values(array_filter(array_map('strval', $gap_domains))));
        }

        $domains = array_values(array_unique(array_map(static function (string $domain): string {
            return strtolower(trim($domain));
        }, $domains)));

        sort($domains, SORT_NATURAL | SORT_FLAG_CASE);
        return $domains;
    }

    public static function add_competitor_domain(string $domain): bool {
        global $wpdb;

        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = trim((string) $domain, '/');

        if ($domain === '' || !preg_match('/^[a-z0-9.-]+$/', $domain)) {
            return false;
        }

        $exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table_competitors() . ' WHERE domain = %s LIMIT 1',
            $domain
        ));

        if ($exists > 0) {
            $wpdb->update(
                self::table_competitors(),
                ['is_active' => 1, 'updated_at' => current_time('mysql')],
                ['id' => $exists],
                ['%d', '%s'],
                ['%d']
            );
            return true;
        }

        $ok = $wpdb->insert(
            self::table_competitors(),
            [
                'domain' => $domain,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
                'is_active' => 1,
            ],
            ['%s', '%s', '%s', '%d']
        );

        $content_gap_domains_table = $wpdb->prefix . 'tmwseo_competitor_domains';
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $content_gap_domains_table));
        if ($table_exists === $content_gap_domains_table) {
            $gap_exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$content_gap_domains_table} WHERE domain = %s LIMIT 1",
                $domain
            ));

            if ($gap_exists <= 0) {
                $wpdb->insert(
                    $content_gap_domains_table,
                    [
                        'domain' => $domain,
                        'source' => 'manual',
                        'added_at' => current_time('mysql'),
                    ],
                    ['%s', '%s', '%s']
                );
            }
        }

        return (bool) $ok;
    }
}
