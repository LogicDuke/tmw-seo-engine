<?php
namespace TMWSEO\Engine\Opportunities;

if (!defined('ABSPATH')) { exit; }

class TrafficForecastUI {
    /** @var array<int,float> */
    private const CTR_CURVE = [
        1 => 0.28,
        2 => 0.15,
        3 => 0.10,
        4 => 0.07,
        5 => 0.05,
    ];

    public static function init(): void {
        // Menu registration is centrally managed by Admin::menu().
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';
        $cluster_map_table = $wpdb->prefix . 'tmw_keyword_cluster_map';
        $keyword_candidates_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $serp_domains_table = $wpdb->prefix . 'tmwseo_serp_domains';

        $position = isset($_GET['forecast_position']) ? (int) $_GET['forecast_position'] : 1;
        if (!isset(self::CTR_CURVE[$position])) {
            $position = 1;
        }

        $clusters = $wpdb->get_results(
            "SELECT id, representative FROM {$cluster_table} ORDER BY opportunity DESC, total_volume DESC LIMIT 100",
            ARRAY_A
        );

        $forecasts = [];
        foreach ((array) $clusters as $cluster) {
            $cluster_id = (int) ($cluster['id'] ?? 0);
            if ($cluster_id <= 0) {
                continue;
            }

            $keyword_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT m.keyword, kc.id AS keyword_id, COALESCE(kc.volume, 0) AS volume, COALESCE(kc.difficulty, 0) AS difficulty
                     FROM {$cluster_map_table} m
                     LEFT JOIN {$keyword_candidates_table} kc ON kc.keyword = m.keyword
                     WHERE m.cluster_id = %d",
                    $cluster_id
                ),
                ARRAY_A
            );

            if (empty($keyword_rows)) {
                continue;
            }

            $cluster_total_volume = 0;
            $difficulty_total = 0.0;
            $difficulty_count = 0;
            $keyword_ids = [];

            foreach ($keyword_rows as $row) {
                $volume = max(0, (int) ($row['volume'] ?? 0));
                $difficulty = (float) ($row['difficulty'] ?? 0);
                $keyword_id = (int) ($row['keyword_id'] ?? 0);

                $cluster_total_volume += $volume;
                if ($difficulty > 0) {
                    $difficulty_total += $difficulty;
                    $difficulty_count++;
                }
                if ($keyword_id > 0) {
                    $keyword_ids[] = $keyword_id;
                }
            }

            // Step 1: persist aggregate cluster volume.
            $wpdb->update(
                $cluster_table,
                [
                    'total_volume' => $cluster_total_volume,
                    'metrics_updated_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => $cluster_id],
                ['%d', '%s', '%s'],
                ['%d']
            );

            $avg_difficulty = $difficulty_count > 0 ? ($difficulty_total / $difficulty_count) : 1.0;
            $ctr = self::CTR_CURVE[$position];
            $traffic_potential = $cluster_total_volume * $ctr;
            $opportunity_score = $avg_difficulty > 0 ? ($traffic_potential / $avg_difficulty) : 0.0;
            $competitor_avg_traffic = self::estimate_competitor_average_traffic(
                $wpdb,
                $serp_domains_table,
                $keyword_candidates_table,
                $keyword_ids
            );

            $forecasts[] = [
                'topic' => (string) ($cluster['representative'] ?? ''),
                'cluster_total_volume' => $cluster_total_volume,
                'estimated_traffic' => round($traffic_potential, 2),
                'keyword_difficulty' => round($avg_difficulty, 2),
                'opportunity_score' => round($opportunity_score, 2),
                'competitor_avg_traffic' => round($competitor_avg_traffic, 2),
            ];
        }

        update_option('tmwseo_traffic_forecast_cache', [
            'position' => $position,
            'updated_at' => current_time('mysql'),
            'rows' => $forecasts,
        ], false);

        usort($forecasts, static function(array $a, array $b): int {
            return $b['estimated_traffic'] <=> $a['estimated_traffic'];
        });

        $max_traffic = 0.0;
        foreach ($forecasts as $forecast) {
            $max_traffic = max($max_traffic, (float) ($forecast['estimated_traffic'] ?? 0));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Traffic Forecast', 'tmwseo') . '</h1>';
        echo '<p>' . esc_html__('Estimate potential traffic for keyword clusters before content creation.', 'tmwseo') . '</p>';

        echo '<form method="get" style="margin:12px 0 18px;">';
        echo '<input type="hidden" name="page" value="tmwseo-traffic-forecast" />';
        echo '<label for="forecast_position"><strong>' . esc_html__('Target Position', 'tmwseo') . '</strong> </label>';
        echo '<select id="forecast_position" name="forecast_position">';
        foreach (self::CTR_CURVE as $pos => $curve) {
            echo '<option value="' . esc_attr((string) $pos) . '" ' . selected($position, $pos, false) . '>';
            echo esc_html(sprintf('#%d (CTR %.0f%%)', $pos, $curve * 100));
            echo '</option>';
        }
        echo '</select> ';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Recalculate Forecast', 'tmwseo') . '</button>';
        echo '</form>';

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Topic', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Cluster Volume', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Estimated Traffic', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Keyword Difficulty', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Opportunity Score', 'tmwseo') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($forecasts)) {
            echo '<tr><td colspan="5">' . esc_html__('No clustered keyword data available yet.', 'tmwseo') . '</td></tr>';
        } else {
            foreach ($forecasts as $row) {
                echo '<tr>';
                echo '<td>' . esc_html((string) $row['topic']) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((int) $row['cluster_total_volume'])) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((float) $row['estimated_traffic'], 2)) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((float) $row['keyword_difficulty'], 2)) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((float) $row['opportunity_score'], 2)) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        echo '<h2 style="margin-top:28px;">' . esc_html__('Potential Traffic by Topic', 'tmwseo') . '</h2>';
        if (empty($forecasts)) {
            echo '<p>' . esc_html__('No data to visualize.', 'tmwseo') . '</p>';
        } else {
            echo '<div style="max-width:960px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px;">';
            foreach ($forecasts as $row) {
                $traffic = (float) ($row['estimated_traffic'] ?? 0);
                $bar_width = $max_traffic > 0 ? max(2.0, ($traffic / $max_traffic) * 100.0) : 0.0;
                echo '<div style="margin-bottom:10px;">';
                echo '<div style="font-size:12px;color:#334155;margin-bottom:4px;">' . esc_html((string) $row['topic']) . ' — ' . esc_html(number_format_i18n($traffic, 2)) . '</div>';
                echo '<div style="height:16px;background:#e2e8f0;border-radius:999px;overflow:hidden;">';
                echo '<div style="height:16px;width:' . esc_attr((string) $bar_width) . '%;background:#2563eb;"></div>';
                echo '</div>';
                echo '</div>';
            }
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * @param int[] $keyword_ids
     */
    private static function estimate_competitor_average_traffic($wpdb, string $serp_domains_table, string $keyword_candidates_table, array $keyword_ids): float {
        if (empty($keyword_ids)) {
            return 0.0;
        }

        $keyword_ids = array_values(array_filter(array_map('intval', $keyword_ids), static fn(int $id): bool => $id > 0));
        if (empty($keyword_ids)) {
            return 0.0;
        }

        $placeholders = implode(', ', array_fill(0, count($keyword_ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT sd.domain, sd.position, COALESCE(kc.volume, 0) AS keyword_volume
             FROM {$serp_domains_table} sd
             INNER JOIN (
                SELECT keyword_id, domain, MAX(captured_at) AS captured_at
                FROM {$serp_domains_table}
                WHERE keyword_id IN ({$placeholders})
                GROUP BY keyword_id, domain
             ) latest ON latest.keyword_id = sd.keyword_id AND latest.domain = sd.domain AND latest.captured_at = sd.captured_at
             INNER JOIN {$keyword_candidates_table} kc ON kc.id = sd.keyword_id
             WHERE sd.position BETWEEN 1 AND 5",
            ...$keyword_ids
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (empty($rows)) {
            return 0.0;
        }

        $traffic_by_domain = [];
        foreach ($rows as $row) {
            $domain = strtolower(trim((string) ($row['domain'] ?? '')));
            if ($domain === '') {
                continue;
            }

            $pos = (int) ($row['position'] ?? 0);
            $volume = max(0, (int) ($row['keyword_volume'] ?? 0));
            if (!isset(self::CTR_CURVE[$pos])) {
                continue;
            }

            $traffic_by_domain[$domain] = ($traffic_by_domain[$domain] ?? 0.0) + ($volume * self::CTR_CURVE[$pos]);
        }

        if (empty($traffic_by_domain)) {
            return 0.0;
        }

        arsort($traffic_by_domain);
        $top_traffic = array_slice(array_values($traffic_by_domain), 0, 5);
        $count = count($top_traffic);

        return $count > 0 ? (array_sum($top_traffic) / $count) : 0.0;
    }
}
