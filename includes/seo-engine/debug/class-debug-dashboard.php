<?php
namespace TMWSEO\Engine\Debug;

if (!defined('ABSPATH')) { exit; }

class DebugDashboard {
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 99);
    }

    public static function register_menu(): void {
        add_submenu_page(
            'tmwseo-engine',
            __('Debug Dashboard', 'tmwseo'),
            __('↳ Debug Dashboard', 'tmwseo'),
            'manage_options',
            'tmw-seo-debug',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;

        echo '<div class="wrap">';
        echo '<h1>TMW SEO Engine Inspector</h1>';
        echo '<p>Suggestions, status, and diagnostics for human review.</p>';

        echo '<form method="get" style="margin:12px 0;">';
        echo '<input type="hidden" name="page" value="tmw-seo-debug" />';
        echo '<label for="post_id"><strong>Inspect post ID:</strong></label> ';
        echo '<input type="number" name="post_id" id="post_id" value="' . esc_attr((string) $post_id) . '" class="small-text" /> ';
        submit_button('Inspect', 'secondary', 'submit', false);
        echo '</form>';

        $test_report = DebugPanels::maybe_run_testing_mode();

        DebugPanels::render_engine_status();
        DebugPanels::render_testing_dashboard($test_report);
        DebugPanels::render_suggestion_activity(100);

        if ($post_id > 0) {
            DebugPanels::render_post_inspector($post_id);
        }

        echo '</div>';
    }
}
