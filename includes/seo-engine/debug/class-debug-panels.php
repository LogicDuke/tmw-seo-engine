<?php
namespace TMWSEO\Engine\Debug;

if (!defined('ABSPATH')) { exit; }

class DebugPanels {
    public static function get_panels(): array {
        global $wpdb;

        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';
        $candidates_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $similarity_table = $wpdb->prefix . 'tmw_model_similarity';
        $opportunity_table = $wpdb->prefix . 'tmw_seo_opportunities';
        $logs_table = $wpdb->prefix . 'tmw_logs';
        $generated_pages = $wpdb->prefix . 'tmw_generated_pages';

        return [
            'Keyword Pack' => [
                'postmeta_records' => self::meta_count('tmw_keyword_pack') + self::meta_count('_tmwseo_keyword_pack'),
                'db_records' => self::table_count($candidates_table),
            ],
            'Keyword Clusters' => [
                'postmeta_records' => self::meta_count('tmw_keyword_clusters'),
                'db_records' => self::table_count($cluster_table),
            ],
            'Search Intent' => [
                'postmeta_records' => self::meta_count('tmw_keyword_pack'),
                'db_records' => (int) $wpdb->get_var("SELECT COUNT(1) FROM {$candidates_table} WHERE intent IS NOT NULL AND intent <> ''"),
            ],
            'Internal Links' => [
                'postmeta_records' => self::meta_count('_tmw_topic_parent_model_id') + self::meta_count('tmw_model_platform'),
                'db_records' => self::table_count($generated_pages),
            ],
            'Model Similarity' => [
                'postmeta_records' => self::meta_count('_tmwseo_platform_primary'),
                'db_records' => self::table_count($similarity_table),
            ],
            'SEO Opportunities' => [
                'postmeta_records' => self::meta_count('_tmwseo_cluster_id'),
                'db_records' => self::table_count($opportunity_table),
            ],
            'DataForSEO API' => [
                'postmeta_records' => self::meta_count('tmw_keyword_pack'),
                'db_records' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$logs_table} WHERE context = %s", 'dataforseo')),
            ],
        ];
    }

    public static function render_panels(): void {
        $panels = self::get_panels();

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-top:16px;">';
        foreach ($panels as $title => $stats) {
            echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px;">';
            echo '<h3 style="margin:0 0 10px;">' . esc_html($title) . '</h3>';
            echo '<p style="margin:0 0 4px;"><strong>Post Meta:</strong> ' . esc_html((string) ($stats['postmeta_records'] ?? 0)) . '</p>';
            echo '<p style="margin:0;"><strong>DB Rows:</strong> ' . esc_html((string) ($stats['db_records'] ?? 0)) . '</p>';
            echo '</div>';
        }
        echo '</div>';
    }

    private static function meta_count(string $meta_key): int {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            $meta_key
        ));
    }

    private static function table_count(string $table_name): int {
        global $wpdb;

        $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        if ($exists !== $table_name) {
            return 0;
        }

        return (int) $wpdb->get_var("SELECT COUNT(1) FROM {$table_name}");
    }
}
