<?php
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Admin;

if (!defined('ABSPATH')) { exit; }

class CSVManagerAdminPage {
    public static function init(): void {
        add_action('admin_post_tmw_csv_manager_delete', [__CLASS__, 'handle_delete']);
        add_action('admin_post_tmw_csv_manager_download', [__CLASS__, 'handle_download']);
        add_action('admin_post_tmw_csv_manager_reimport', [__CLASS__, 'handle_reimport']);
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $csv_dir = self::get_csv_dir();
        $files = self::get_csv_files($csv_dir);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('CSV Manager', 'tmwseo') . '</h1>';

        if (isset($_GET['tmw_csv_notice'])) {
            $notice = sanitize_key((string) wp_unslash($_GET['tmw_csv_notice']));
            if ($notice === 'deleted') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('CSV file deleted successfully.', 'tmwseo') . '</p></div>';
            } elseif ($notice === 'reimported') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('CSV file re-import started successfully.', 'tmwseo') . '</p></div>';
            }
        }

        if (empty($files)) {
            echo '<p>' . esc_html__('No CSV files found in uploads/tmw-seo-imports.', 'tmwseo') . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Filename', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('File Size', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Imported Date', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Path', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Actions', 'tmwseo') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($files as $file) {
            $filename = basename($file);
            $mtime = (int) filemtime($file);
            $delete_url = wp_nonce_url(add_query_arg([
                'action' => 'tmw_csv_manager_delete',
                'file' => rawurlencode($filename),
            ], admin_url('admin-post.php')), 'tmw_delete_csv');

            $download_url = wp_nonce_url(add_query_arg([
                'action' => 'tmw_csv_manager_download',
                'file' => rawurlencode($filename),
            ], admin_url('admin-post.php')), 'tmw_download_csv');

            $reimport_url = wp_nonce_url(add_query_arg([
                'action' => 'tmw_csv_manager_reimport',
                'file' => rawurlencode($filename),
            ], admin_url('admin-post.php')), 'tmw_reimport_csv');

            echo '<tr>';
            echo '<td>' . esc_html($filename) . '</td>';
            echo '<td>' . esc_html(size_format((int) filesize($file))) . '</td>';
            echo '<td>' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $mtime)) . '</td>';
            echo '<td><code>' . esc_html($file) . '</code></td>';
            echo '<td>';
            echo '<a class="button button-link-delete" href="' . esc_url($delete_url) . '" onclick="return confirm(\'' . esc_js(__('Are you sure you want to delete this CSV file?', 'tmwseo')) . '\');">' . esc_html__('Delete', 'tmwseo') . '</a> ';
            echo '<a class="button" href="' . esc_url($download_url) . '">' . esc_html__('Download', 'tmwseo') . '</a> ';
            echo '<a class="button button-primary" href="' . esc_url($reimport_url) . '">' . esc_html__('Re-import', 'tmwseo') . '</a>';
            echo '</td>';
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

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce((string) wp_unslash($_GET['_wpnonce']), 'tmw_delete_csv')) {
            wp_die(__('Invalid nonce', 'tmwseo'));
        }

        $target = self::get_validated_file_path();
        if ($target && is_file($target)) {
            unlink($target);
        }

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-csv-manager&tmw_csv_notice=deleted'));
        exit;
    }

    public static function handle_download(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmw_download_csv');

        $target = self::get_validated_file_path();
        if (!$target || !is_file($target)) {
            wp_die(__('CSV file not found.', 'tmwseo'));
        }

        nocache_headers();
        header('Content-Description: File Transfer');
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . basename($target) . '"');
        header('Content-Length: ' . (string) filesize($target));
        readfile($target);
        exit;
    }

    public static function handle_reimport(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmw_reimport_csv');

        $target = self::get_validated_file_path();
        if (!$target || !is_file($target)) {
            wp_die(__('CSV file not found.', 'tmwseo'));
        }

        Admin::import_keywords_from_csv_path($target, 'manual_reimport', true);

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-csv-manager&tmw_csv_notice=reimported'));
        exit;
    }

    private static function get_csv_dir(): string {
        $csv_dir = function_exists('tmw_get_csv_directory') ? tmw_get_csv_directory() : trailingslashit(WP_CONTENT_DIR) . 'uploads/tmw-seo-imports';
        if (!file_exists($csv_dir)) {
            wp_mkdir_p($csv_dir);
        }

        return trailingslashit($csv_dir);
    }

    private static function get_csv_files(string $csv_dir): array {
        $files = glob($csv_dir . '*.csv') ?: [];
        $files = array_filter($files, static fn($file): bool => is_string($file) && is_file($file));

        usort($files, static fn($a, $b): int => filemtime($b) <=> filemtime($a));

        return $files;
    }

    private static function get_validated_file_path(): ?string {
        $file = isset($_GET['file']) ? sanitize_file_name(rawurldecode((string) wp_unslash($_GET['file']))) : '';
        if ($file === '' || strtolower((string) pathinfo($file, PATHINFO_EXTENSION)) !== 'csv') {
            return null;
        }

        $base_dir = realpath(self::get_csv_dir());
        if ($base_dir === false) {
            return null;
        }

        $path = realpath(trailingslashit($base_dir) . $file);
        if ($path === false || strpos($path, trailingslashit($base_dir)) !== 0) {
            return null;
        }

        return $path;
    }
}
