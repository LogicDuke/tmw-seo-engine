<?php
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Keywords\KeywordDiscoveryGovernor;

if (!defined('ABSPATH')) { exit; }

class DiscoveryControlAdminPage {
    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'tmwseo'));
        }

        $governor = new KeywordDiscoveryGovernor();
        $config = $governor->config();
        $today = $governor->get_keywords_added_today();
        $db_size = size_format(max(0, (int) $governor->database_size_bytes()), 2);
        $last_run = $governor->last_run();

        echo '<div class="wrap">';
        AdminUI::enqueue();
        AdminUI::page_header('Discovery Control', 'Keyword Discovery Governor limits and runtime diagnostics.');

        AdminUI::kpi_row([
            ['value' => $today, 'label' => 'Keywords discovered today', 'color' => 'ok'],
            ['value' => (int) ($config['max_keywords_per_day'] ?? 0), 'label' => 'Daily limit', 'color' => 'warn'],
            ['value' => (int) ($config['max_keywords_per_run'] ?? 0), 'label' => 'Run limit', 'color' => 'warn'],
            ['value' => $db_size, 'label' => 'Database size', 'color' => 'neutral'],
        ]);

        echo '<table class="widefat striped" style="max-width:950px">';
        echo '<thead><tr><th>Setting</th><th>Value</th></tr></thead><tbody>';
        echo '<tr><td>Max keywords per run</td><td>' . esc_html((string) ($config['max_keywords_per_run'] ?? 0)) . '</td></tr>';
        echo '<tr><td>Max keywords per day</td><td>' . esc_html((string) ($config['max_keywords_per_day'] ?? 0)) . '</td></tr>';
        echo '<tr><td>Max depth</td><td>' . esc_html((string) ($config['max_depth'] ?? 0)) . '</td></tr>';
        echo '<tr><td>Minimum search volume</td><td>' . esc_html((string) ($config['min_search_volume'] ?? 0)) . '</td></tr>';
        echo '<tr><td>Max keywords per topic</td><td>' . esc_html((string) ($config['max_keywords_per_topic'] ?? 0)) . '</td></tr>';
        echo '</tbody></table>';

        echo '<h2 style="margin-top:24px;">Last Discovery Run</h2>';
        if (empty($last_run)) {
            AdminUI::empty_state('No discovery runs have been logged yet.');
        } else {
            echo '<table class="widefat striped" style="max-width:950px">';
            echo '<thead><tr><th>Created</th><th>Processed</th><th>Added</th><th>Filtered</th><th>Runtime (s)</th></tr></thead><tbody>';
            echo '<tr>';
            echo '<td>' . esc_html((string) ($last_run['created_at'] ?? '—')) . '</td>';
            echo '<td>' . esc_html((string) ((int) ($last_run['keywords_processed'] ?? 0))) . '</td>';
            echo '<td>' . esc_html((string) ((int) ($last_run['keywords_added'] ?? 0))) . '</td>';
            echo '<td>' . esc_html((string) ((int) ($last_run['keywords_filtered'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format((float) ($last_run['runtime'] ?? 0), 4)) . '</td>';
            echo '</tr>';
            echo '</tbody></table>';
        }

        echo '</div>';
    }
}
