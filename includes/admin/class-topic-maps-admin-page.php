<?php
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\TopicAuthority\TopicAuthorityMapper;

if (!defined('ABSPATH')) { exit; }

class TopicMapsAdminPage {
    public static function init(): void {
        add_action('admin_post_tmwseo_generate_topic_maps', [__CLASS__, 'handle_generate_topic_maps']);
        add_action('admin_post_tmwseo_generate_topic_content_plan', [__CLASS__, 'handle_generate_content_plan']);
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $maps = TopicAuthorityMapper::get_topic_maps();
        $plan_payload = self::get_content_plan_payload();

        echo '<div class="wrap"><h1>Topic Maps</h1>';
        echo '<p>Build topical authority maps from existing keyword clusters using semantic similarity > 0.7.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:16px 0;">';
        wp_nonce_field('tmwseo_generate_topic_maps');
        echo '<input type="hidden" name="action" value="tmwseo_generate_topic_maps" />';
        submit_button(__('Generate Topic Maps', 'tmwseo'), 'primary', 'submit', false);
        echo '</form>';

        if (isset($_GET['generated']) && (int) $_GET['generated'] === 1) {
            $count = max(0, (int) ($_GET['count'] ?? 0));
            echo '<div class="notice notice-success"><p>Generated ' . esc_html((string) $count) . ' topic maps.</p></div>';
        }

        if (empty($maps)) {
            echo '<div class="tmwseo-card" style="max-width:1200px;"><p>No topic maps found. Click <strong>Generate Topic Maps</strong> to build them from current clusters.</p></div>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:1200px;">';
        echo '<thead><tr><th>Topic</th><th>Pillar Page Keyword</th><th>Supporting Pages</th><th>Total Topic Volume</th><th>Actions</th></tr></thead><tbody>';

        foreach ($maps as $map) {
            $supporting = (array) ($map['supporting_keywords'] ?? []);
            echo '<tr>';
            echo '<td>' . esc_html((string) ($map['topic_name'] ?? '')) . '</td>';
            echo '<td><strong>' . esc_html((string) ($map['pillar_keyword'] ?? '')) . '</strong></td>';
            echo '<td>' . esc_html(implode(', ', $supporting)) . '</td>';
            echo '<td>' . esc_html(number_format((int) ($map['total_search_volume'] ?? 0))) . '</td>';
            echo '<td>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('tmwseo_generate_topic_content_plan');
            echo '<input type="hidden" name="action" value="tmwseo_generate_topic_content_plan" />';
            echo '<input type="hidden" name="map_id" value="' . esc_attr((string) ((int) ($map['id'] ?? 0))) . '" />';
            submit_button(__('Generate Content Plan', 'tmwseo'), 'secondary', 'submit', false);
            echo '</form>';
            echo '</td>';
            echo '</tr>';

            self::render_visual_graph($map);
        }

        echo '</tbody></table>';

        if (is_array($plan_payload)) {
            self::render_content_plan($plan_payload);
        }

        echo '</div>';
    }

    public static function handle_generate_topic_maps(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_generate_topic_maps');

        $maps = TopicAuthorityMapper::rebuild_topic_maps(0.7);
        wp_safe_redirect(admin_url('admin.php?page=tmwseo-topic-maps&generated=1&count=' . count($maps)));
        exit;
    }

    public static function handle_generate_content_plan(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_generate_topic_content_plan');

        $map_id = isset($_POST['map_id']) ? (int) $_POST['map_id'] : 0;
        $map = TopicAuthorityMapper::get_topic_map($map_id);
        if (!is_array($map)) {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-topic-maps'));
            exit;
        }

        $payload = [
            'topic_name' => (string) ($map['topic_name'] ?? ''),
            'pillar_keyword' => (string) ($map['pillar_keyword'] ?? ''),
            'items' => TopicAuthorityMapper::generate_content_plan($map),
        ];

        $key = wp_generate_password(10, false, false);
        set_transient('tmwseo_topic_map_plan_' . $key, $payload, 10 * MINUTE_IN_SECONDS);

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-topic-maps&plan_key=' . rawurlencode($key)));
        exit;
    }

    /** @return array<string,mixed>|null */
    private static function get_content_plan_payload(): ?array {
        $key = sanitize_key((string) ($_GET['plan_key'] ?? ''));
        if ($key === '') {
            return null;
        }

        $payload = get_transient('tmwseo_topic_map_plan_' . $key);
        if (!is_array($payload)) {
            return null;
        }

        delete_transient('tmwseo_topic_map_plan_' . $key);
        return $payload;
    }

    /** @param array<string,mixed> $map */
    private static function render_visual_graph(array $map): void {
        $pillar = (string) ($map['pillar_keyword'] ?? '');
        $supporting = (array) ($map['supporting_keywords'] ?? []);

        echo '<tr><td colspan="5">';
        echo '<div style="padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin:8px 0;">';
        echo '<strong>Visual Topic Graph:</strong> ';
        echo '<span style="display:inline-block;padding:4px 8px;background:#2563eb;color:#fff;border-radius:999px;margin-right:8px;">Topic: ' . esc_html($pillar) . '</span>';
        foreach ($supporting as $keyword) {
            echo '<span style="display:inline-block;padding:4px 8px;background:#e2e8f0;color:#0f172a;border-radius:999px;margin-right:6px;margin-top:6px;">↔ ' . esc_html((string) $keyword) . '</span>';
        }

        $graph = (array) ($map['link_graph'] ?? []);
        $pillar_links = (array) ($graph['pillar_to_supporting'] ?? []);
        $support_links = (array) ($graph['supporting_to_supporting'] ?? []);
        echo '<p style="margin:10px 0 0 0;"><em>Link Plan:</em> '; 
        echo esc_html((string) count($pillar_links)) . ' pillar→support links, ';
        echo esc_html((string) count($supporting)) . ' support→pillar links, ';
        echo esc_html((string) count($support_links)) . ' support↔support links.</p>';
        echo '</div>';
        echo '</td></tr>';
    }

    /** @param array<string,mixed> $payload */
    private static function render_content_plan(array $payload): void {
        $items = (array) ($payload['items'] ?? []);
        if (empty($items)) {
            return;
        }

        echo '<div class="tmwseo-card" style="max-width:1200px;margin-top:16px;">';
        echo '<h2>Content Plan: ' . esc_html((string) ($payload['topic_name'] ?? '')) . '</h2>';
        echo '<p><strong>Pillar keyword:</strong> ' . esc_html((string) ($payload['pillar_keyword'] ?? '')) . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>Recommended Page Title</th><th>Target Keyword</th><th>Internal Link Suggestion</th></tr></thead><tbody>';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            echo '<tr>';
            echo '<td>' . esc_html((string) ($item['title'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($item['target_keyword'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($item['internal_links'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }
}
