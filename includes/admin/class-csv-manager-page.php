<?php
namespace TMWSEO\Engine\Admin;

if (!defined('ABSPATH')) { exit; }

class CSVManagerPage {
    public static function init(): void {
        add_action('admin_post_tmw_delete_csv', [__CLASS__, 'handle_delete']);
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $csv_dir = function_exists('tmw_get_csv_directory') ? tmw_get_csv_directory() : trailingslashit(WP_CONTENT_DIR) . 'tmw-temp';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('CSV Manager', 'tmwseo') . '</h1>';

        if (isset($_GET['deleted']) && (int) $_GET['deleted'] === 1) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('CSV file deleted successfully.', 'tmwseo') . '</p></div>';
        }

        if (!is_dir($csv_dir)) {
            echo '<p>' . esc_html__('No CSV files found.', 'tmwseo') . '</p>';
            echo '</div>';
            return;
        }

        $files = glob(trailingslashit($csv_dir) . '*.csv') ?: [];
        $files = array_filter($files, static fn($file): bool => is_file($file));

        if (empty($files)) {
            echo '<p>' . esc_html__('No CSV files found.', 'tmwseo') . '</p>';
            echo '</div>';
            return;
        }

        usort($files, static fn($a, $b): int => filemtime($b) <=> filemtime($a));

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>' . esc_html__('File name', 'tmwseo') . '</th><th>' . esc_html__('File size', 'tmwseo') . '</th><th>' . esc_html__('Date modified', 'tmwseo') . '</th><th>' . esc_html__('Actions', 'tmwseo') . '</th></tr></thead>';
        echo '<tbody>';

        foreach ($files as $file_path) {
            $file_name = basename($file_path);
            $delete_url = add_query_arg([
                'action' => 'tmw_delete_csv',
                'file' => $file_name,
            ], admin_url('admin-post.php'));
            $delete_url = wp_nonce_url($delete_url, 'tmw_delete_csv_' . $file_name);

            echo '<tr>';
            echo '<td>' . esc_html($file_name) . '</td>';
            echo '<td>' . esc_html(size_format((int) filesize($file_path))) . '</td>';
            echo '<td>' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) filemtime($file_path))) . '</td>';
            echo '<td><a class="button button-link-delete" href="' . esc_url($delete_url) . '" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this CSV file?', 'tmwseo')) . '\');">' . esc_html__('Delete', 'tmwseo') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    public static function handle_delete(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $file_name = isset($_GET['file']) ? sanitize_file_name(wp_unslash((string) $_GET['file'])) : '';
        check_admin_referer('tmw_delete_csv_' . $file_name);

        $base_dir = function_exists('tmw_get_csv_directory') ? tmw_get_csv_directory() : trailingslashit(WP_CONTENT_DIR) . 'tmw-temp';
        $base_real = realpath($base_dir);

        if ($file_name !== '' && $base_real !== false) {
            $target_path = trailingslashit($base_real) . $file_name;
            $target_real = realpath($target_path);

            if (
                $target_real !== false
                && strpos($target_real, trailingslashit($base_real)) === 0
                && is_file($target_real)
                && strtolower((string) pathinfo($target_real, PATHINFO_EXTENSION)) === 'csv'
            ) {
                @unlink($target_real);
            }
        }

        wp_redirect(admin_url('admin.php?page=tmwseo-csv-manager&deleted=1'));
        exit;
    }
}
