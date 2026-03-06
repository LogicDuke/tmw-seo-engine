<?php
namespace TMWSEO\Engine\Debug;

if (!defined('ABSPATH')) { exit; }

class DebugPanels {
    public static function render_engine_status(): void {
        $status = [
            'DataForSEO status' => \TMWSEO\Engine\Services\DataForSEO::is_configured() ? 'Ready' : 'Missing credentials',
            'keyword intelligence status' => self::meta_count('tmw_keyword_pack') > 0 ? 'Ready for Review' : 'Needs Attention',
            'clustering status' => self::table_count('tmw_keyword_clusters') > 0 ? 'Ready for Review' : 'Needs Attention',
            'opportunities status' => self::table_count('tmw_seo_opportunities') > 0 ? 'Ready for Review' : 'Needs Attention',
            'topic suggestions status' => self::meta_count('tmw_topic_cluster') > 0 ? 'Ready for Review' : 'Needs Attention',
            'debug mode status' => DebugLogger::is_enabled() ? 'On' : 'Off',
        ];

        echo '<h2>Engine Status</h2><table class="widefat striped"><tbody>';
        foreach ($status as $label => $value) {
            echo '<tr><th style="width:260px;">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    public static function render_suggestion_activity(int $limit = 50): void {
        $rows = \TMWSEO\Engine\Logs::latest($limit);

        echo '<h2>Suggestion Activity Log</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th style="width:170px;">Time</th><th style="width:120px;">Level</th><th style="width:160px;">Context</th><th>Message</th><th>Data</th>';
        echo '</tr></thead><tbody>';

        $shown = 0;
        foreach ($rows as $row) {
            $context = isset($row['context']) ? (string) $row['context'] : '';
            $message = isset($row['message']) ? (string) $row['message'] : '';

            if ($context !== 'suggestions' && stripos($message, 'Suggestion ') === false) {
                continue;
            }

            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['time'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['level'] ?? '')) . '</td>';
            echo '<td>' . esc_html($context) . '</td>';
            echo '<td>' . esc_html($message) . '</td>';
            echo '<td><code style="white-space:pre-wrap;">' . esc_html((string) ($row['data'] ?? '')) . '</code></td>';
            echo '</tr>';

            $shown++;
        }

        if ($shown === 0) {
            echo '<tr><td colspan="5">No suggestion lifecycle activity logged yet.</td></tr>';
        }

        echo '</tbody></table>';
    }

    public static function render_post_inspector(int $post_id): void {
        $sections = [
            'keyword pack' => get_post_meta($post_id, 'tmw_keyword_pack', true),
            'filters applied' => get_post_meta($post_id, 'tmw_keyword_filters_applied', true),
            'rejected keywords' => get_post_meta($post_id, 'tmw_rejected_keywords', true),
            'clusters' => get_post_meta($post_id, 'tmw_keyword_clusters', true),
            'opportunities' => get_post_meta($post_id, 'tmw_opportunities', true),
            'internal links' => get_post_meta($post_id, 'tmw_internal_links', true),
            'model similarity' => get_post_meta($post_id, 'tmw_model_similarity', true),
            'topic suggestions' => get_post_meta($post_id, 'tmw_topic_cluster', true),
            'recent API calls' => DebugAPIMonitor::get_recent_requests(10),
            'errors' => get_post_meta($post_id, 'tmw_engine_errors', true),
        ];

        echo '<h2>Post Inspector</h2>';
        foreach ($sections as $title => $data) {
            echo '<h3 style="margin-top:16px;">' . esc_html(ucwords($title)) . '</h3>';
            echo '<pre style="background:#fff;border:1px solid #dcdcde;padding:10px;max-height:200px;overflow:auto;">' . esc_html(wp_json_encode($data, JSON_PRETTY_PRINT)) . '</pre>';
        }
    }

    private static function meta_count(string $meta_key): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key));
    }

    private static function table_count(string $table_suffix): int {
        global $wpdb;
        $table_name = $wpdb->prefix . $table_suffix;
        $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        if ($exists !== $table_name) {
            return 0;
        }
        return (int) $wpdb->get_var("SELECT COUNT(1) FROM {$table_name}");
    }
}
