<?php
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\JobWorker;

if (!defined('ABSPATH')) { exit; }

class LinkGraphAdminPage {
    public static function init(): void {
        add_action('admin_post_tmwseo_build_link_graph', [__CLASS__, 'handle_build_graph']);
        add_action('admin_post_tmwseo_insert_link_suggestions', [__CLASS__, 'handle_insert_suggestions']);
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $key = sanitize_key((string) ($_GET['graph_key'] ?? ''));
        if ($key === '') {
            $queued_key = get_transient('tmwseo_link_graph_last_result_user_' . get_current_user_id());
            if (is_string($queued_key) && $queued_key !== '') {
                $key = sanitize_key($queued_key);
                delete_transient('tmwseo_link_graph_last_result_user_' . get_current_user_id());
            }
        }
        $payload = $key !== '' ? get_transient('tmwseo_link_graph_ui_' . $key) : null;
        if (is_array($payload)) {
            delete_transient('tmwseo_link_graph_ui_' . $key);
        }

        echo '<div class="wrap"><h1>Link Graph</h1>';
        if (isset($_GET['queued']) && (int) $_GET['queued'] === 1) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Internal link graph scan queued. Refresh shortly to view results.', 'tmwseo') . '</p></div>';
        }
        echo '<div class="tmwseo-card" style="max-width:1200px;">';
        echo '<h2>Internal Link Graph Engine</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="tmwseo-link-graph-form">';
        wp_nonce_field('tmwseo_build_link_graph');
        echo '<input type="hidden" name="action" value="tmwseo_build_link_graph" />';
        submit_button(__('Analyze Site Graph', 'tmwseo'), 'primary', 'submit', false, ['id' => 'tmwseo-link-graph-submit']);
        echo ' <span id="tmwseo-link-graph-loading" style="display:none;">Building graph…</span>';
        echo '</form></div>';

        if (is_array($payload)) {
            self::render_graph_results($payload);
        }

        echo '</div>';
        echo '<script>document.getElementById("tmwseo-link-graph-form")?.addEventListener("submit",function(){document.getElementById("tmwseo-link-graph-loading").style.display="inline";document.getElementById("tmwseo-link-graph-submit").disabled=true;});</script>';
    }

    public static function handle_build_graph(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('tmwseo_build_link_graph');

        JobWorker::enqueue_job('internal_link_scan', [
            'user_id' => get_current_user_id(),
            'trigger' => 'link_graph_admin',
        ]);

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-link-graph&queued=1'));
        exit;
    }

    public static function build_graph_payload(): array {
        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'no_found_rows' => true,
        ]);

        $post_map = [];
        $existing_edges = [];
        foreach ($posts as $post) {
            $post_id = (int) $post->ID;
            $keywords = self::post_keywords($post_id, (string) $post->post_title);
            $post_map[$post_id] = [
                'id' => $post_id,
                'title' => (string) $post->post_title,
                'url' => get_permalink($post_id),
                'tokens' => self::tokenize(implode(' ', $keywords)),
            ];
        }

        $id_by_url = [];
        foreach ($post_map as $id => $data) {
            $id_by_url[untrailingslashit((string) $data['url'])] = $id;
        }

        foreach ($posts as $post) {
            $src = (int) $post->ID;
            preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/i', (string) $post->post_content, $matches);
            foreach ((array) ($matches[1] ?? []) as $href) {
                $target_id = $id_by_url[untrailingslashit((string) $href)] ?? 0;
                if ($target_id > 0) {
                    $existing_edges[$src][] = $target_id;
                }
            }
        }

        $suggestions = [];
        $ids = array_keys($post_map);
        $count = count($ids);
        for ($i = 0; $i < $count; $i++) {
            for ($j = 0; $j < $count; $j++) {
                if ($i === $j) {
                    continue;
                }
                $src = $ids[$i];
                $dst = $ids[$j];
                if (in_array($dst, (array) ($existing_edges[$src] ?? []), true)) {
                    continue;
                }
                $score = self::jaccard((array) $post_map[$src]['tokens'], (array) $post_map[$dst]['tokens']);
                if ($score < 0.2) {
                    continue;
                }
                $anchor = self::anchor_from_overlap((array) $post_map[$src]['tokens'], (array) $post_map[$dst]['tokens']);
                $suggestions[] = [
                    'source_id' => $src,
                    'source_title' => $post_map[$src]['title'],
                    'target_id' => $dst,
                    'target_title' => $post_map[$dst]['title'],
                    'anchor' => $anchor,
                    'score' => round($score, 3),
                ];
            }
        }

        usort($suggestions, static fn($a, $b) => $b['score'] <=> $a['score']);
        $suggestions = array_slice($suggestions, 0, 200);

        $inbound = array_fill_keys($ids, 0);
        foreach ($existing_edges as $targets) {
            foreach ((array) $targets as $target) {
                $inbound[(int) $target] = ($inbound[(int) $target] ?? 0) + 1;
            }
        }
        arsort($inbound);
        $most_linked_id = (int) (array_key_first($inbound) ?: 0);
        $orphan_ids = array_keys(array_filter($inbound, static fn($n) => (int) $n === 0));

        $depth_map = self::compute_depth_map($existing_edges);

        return [
            'suggestions' => $suggestions,
            'metrics' => [
                'most_linked' => $most_linked_id > 0 ? ($post_map[$most_linked_id]['title'] ?? '') : '',
                'orphan_pages' => array_values(array_map(static fn($id) => $post_map[$id]['title'] ?? ('#' . $id), array_slice($orphan_ids, 0, 10))),
                'avg_link_depth' => !empty($depth_map) ? round(array_sum($depth_map) / max(1, count($depth_map)), 2) : 0,
            ],
        ];
    }

    public static function handle_insert_suggestions(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('tmwseo_insert_link_suggestions');

        $confirm = isset($_POST['confirm_insert']) ? (int) $_POST['confirm_insert'] : 0;
        if ($confirm !== 1) {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-link-graph'));
            exit;
        }

        $suggestions = get_transient('tmwseo_link_graph_suggestions_' . get_current_user_id());
        if (!is_array($suggestions)) {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-link-graph'));
            exit;
        }

        $applied = 0;
        foreach (array_slice($suggestions, 0, 25) as $suggestion) {
            $source_id = (int) ($suggestion['source_id'] ?? 0);
            $target_id = (int) ($suggestion['target_id'] ?? 0);
            $anchor = sanitize_text_field((string) ($suggestion['anchor'] ?? ''));
            $target_url = get_permalink($target_id);
            if ($source_id <= 0 || $target_id <= 0 || $anchor === '' || $target_url === false) {
                continue;
            }

            $post = get_post($source_id);
            if (!$post || $post->post_status !== 'publish') {
                continue;
            }

            $content = (string) $post->post_content;
            if (stripos($content, 'href="' . $target_url . '"') !== false) {
                continue;
            }

            $content .= sprintf("\n\n<p><a href=\"%s\">%s</a></p>", esc_url($target_url), esc_html($anchor));
            wp_update_post([
                'ID' => $source_id,
                'post_content' => wp_kses_post($content),
            ]);
            $applied++;
        }

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-link-graph&inserted=' . $applied));
        exit;
    }

    private static function render_graph_results(array $payload): void {
        $metrics = (array) ($payload['metrics'] ?? []);
        $suggestions = (array) ($payload['suggestions'] ?? []);

        if (isset($_GET['inserted'])) {
            echo '<div class="notice notice-success"><p>Inserted ' . esc_html((string) ((int) $_GET['inserted'])) . ' manual link suggestions.</p></div>';
        }

        echo '<div class="tmwseo-card" style="max-width:1200px;margin-top:16px;">';
        echo '<h2>Graph Metrics</h2>';
        echo '<p><strong>Most linked page:</strong> ' . esc_html((string) ($metrics['most_linked'] ?? 'n/a')) . '</p>';
        echo '<p><strong>Orphan pages:</strong> ' . esc_html(implode(' | ', (array) ($metrics['orphan_pages'] ?? []))) . '</p>';
        echo '<p><strong>Average link depth:</strong> ' . esc_html((string) ($metrics['avg_link_depth'] ?? 0)) . '</p>';
        echo '</div>';

        echo '<div class="tmwseo-card" style="max-width:1200px;margin-top:16px;">';
        echo '<h2>Internal Link Suggestions</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Source Page</th><th>Suggested Anchor</th><th>Target Page</th><th>Relevance Score</th></tr></thead><tbody>';
        foreach ($suggestions as $row) {
            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['source_title'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['anchor'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['target_title'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['score'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">';
        wp_nonce_field('tmwseo_insert_link_suggestions');
        echo '<input type="hidden" name="action" value="tmwseo_insert_link_suggestions" />';
        echo '<label><input type="checkbox" name="confirm_insert" value="1" required /> Confirm manual insertion into source content.</label><br/>';
        submit_button(__('Insert Link Suggestions', 'tmwseo'), 'secondary', 'submit', false);
        echo '</form>';

        echo '</div>';
    }

    private static function post_keywords(int $post_id, string $title): array {
        $keywords = [$title];

        $focus = get_post_meta($post_id, 'rank_math_focus_keyword', true);
        if (is_string($focus) && trim($focus) !== '') {
            $keywords[] = $focus;
        }

        $terms = get_the_terms($post_id, 'category');
        if (is_array($terms)) {
            foreach ($terms as $term) {
                $keywords[] = (string) $term->name;
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_keywords';
        $columns = (array) $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (!empty($columns) && in_array('post_id', $columns, true) && in_array('keyword', $columns, true)) {
            $rows = (array) $wpdb->get_col($wpdb->prepare("SELECT keyword FROM {$table} WHERE post_id = %d LIMIT 20", $post_id));
            $keywords = array_merge($keywords, $rows);
        }

        return array_values(array_filter(array_map('sanitize_text_field', $keywords)));
    }

    private static function tokenize(string $text): array {
        $text = strtolower(wp_strip_all_tags($text));
        $tokens = preg_split('/[^a-z0-9]+/', $text);
        $stop = ['the','and','for','with','from','this','that','your','into','about','how','what','when','where'];
        $tokens = array_values(array_unique(array_filter((array) $tokens, static fn($t) => strlen((string) $t) > 2 && !in_array($t, $stop, true))));
        return $tokens;
    }

    private static function jaccard(array $a, array $b): float {
        $intersect = array_intersect($a, $b);
        $union = array_unique(array_merge($a, $b));
        if (count($union) === 0) {
            return 0.0;
        }
        return count($intersect) / count($union);
    }

    private static function anchor_from_overlap(array $a, array $b): string {
        $overlap = array_values(array_intersect($a, $b));
        if (empty($overlap)) {
            return 'Related topic';
        }
        return ucwords(implode(' ', array_slice($overlap, 0, 4)));
    }

    private static function compute_depth_map(array $edges): array {
        $front_id = (int) get_option('page_on_front');
        if ($front_id <= 0) {
            return [];
        }

        $depth = [$front_id => 0];
        $queue = [$front_id];
        while (!empty($queue)) {
            $node = array_shift($queue);
            $neighbors = (array) ($edges[$node] ?? []);
            foreach ($neighbors as $n) {
                $n = (int) $n;
                if (!array_key_exists($n, $depth)) {
                    $depth[$n] = ((int) $depth[$node]) + 1;
                    $queue[] = $n;
                }
            }
        }

        return $depth;
    }
}
