<?php
namespace TMWSEO\Engine\Debug;

if (!defined('ABSPATH')) { exit; }

class DebugDashboard {
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
    }

    public static function register_menu(): void {
        add_submenu_page(
            'tmwseo-engine',
            __('Debug Dashboard', 'tmwseo'),
            __('Debug Dashboard', 'tmwseo'),
            'manage_options',
            'tmw-seo-debug',
            __NAMESPACE__ . '\\tmw_render_debug_dashboard'
        );
    }

    public static function render_page(): void {
        tmw_render_debug_dashboard();
    }
}

function tmw_render_debug_dashboard(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    echo '<div class="wrap">';
    echo '<h1>TMW SEO Engine Debug Dashboard</h1>';
    echo '<p>Debug system initialized.</p>';

    if (!DebugLogger::is_enabled()) {
        echo '<p>Debug mode is currently disabled in settings.</p>';
        echo '</div>';
        return;
    }

    $requests = DebugAPIMonitor::get_recent_requests(20);

    echo '<p>Debug view for keyword intelligence, clustering, intent analysis, internal links, model similarity, opportunities and API activity.</p>';

    DebugPanels::render_panels();

    echo '<h2 style="margin-top:24px;">DataForSEO API Monitor (last 20 requests)</h2>';
    echo '<table class="widefat striped">';
    echo '<thead><tr><th>Time</th><th>Endpoint</th><th>Status Code</th><th>Response Time (ms)</th><th>Keywords Returned</th></tr></thead><tbody>';

    if (empty($requests)) {
        echo '<tr><td colspan="5">No API request records yet.</td></tr>';
    } else {
        foreach ($requests as $row) {
            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['time'] ?? '')) . '</td>';
            echo '<td><code>' . esc_html((string) ($row['endpoint'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html((string) ($row['status_code'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['response_time_ms'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['keywords_returned'] ?? '0')) . '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';
}
