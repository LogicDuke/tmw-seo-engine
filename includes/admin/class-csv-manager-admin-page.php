<?php
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Admin;

if (!defined('ABSPATH')) { exit; }

class CSVManagerAdminPage {
    public static function init(): void {
        add_action('admin_post_tmw_csv_manager_delete', [__CLASS__, 'handle_delete']);
        add_action('admin_post_tmw_csv_manager_download', [__CLASS__, 'handle_download']);
        add_action('admin_post_tmw_csv_manager_reimport', [__CLASS__, 'handle_reimport']);
        add_action('admin_post_tmw_csv_manager_delete_seeds', [__CLASS__, 'handle_delete_seeds']);
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
            } elseif ($notice === 'seeds_deleted') {
                $count = (int) ($_GET['seed_count'] ?? 0);
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('%d imported seed rows deleted from database.', 'tmwseo'), $count)) . '</p></div>';
            }
        }

        // ── Reconciliation notice ──────────────────────────────────────────
        echo '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:12px 16px;margin-bottom:16px;">';
        echo '<strong>' . esc_html__('How CSV imports work:', 'tmwseo') . '</strong>';
        echo '<ul style="margin:6px 0 0 18px;list-style:disc;">';
        echo '<li>' . esc_html__('Filesystem CSV files (below) are upload artifacts in uploads/tmw-seo-imports.', 'tmwseo') . '</li>';
        echo '<li>' . esc_html__('When imported, keywords are written into the tmwseo_seeds database table as approved_import (or legacy csv_import) rows.', 'tmwseo') . '</li>';
        echo '<li>' . esc_html__('Deleting a CSV file only removes the file — it does NOT remove the imported seeds from the database.', 'tmwseo') . '</li>';
        echo '<li>' . esc_html__('To remove imported seeds from the database, use the "DB Import History" section below.', 'tmwseo') . '</li>';
        echo '</ul>';
        echo '</div>';

        // ── Section 1: Filesystem CSV files ────────────────────────────────
        echo '<h2>' . esc_html__('Filesystem CSV Files', 'tmwseo') . '</h2>';

        if (empty($files)) {
            echo '<p>' . esc_html__('No CSV files found in uploads/tmw-seo-imports.', 'tmwseo') . '</p>';
        } else {
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
                echo '<a class="button button-link-delete" href="' . esc_url($delete_url) . '" onclick="return confirm(\'' . esc_js(__('Delete filesystem file only. DB seeds will remain. Continue?', 'tmwseo')) . '\');">' . esc_html__('Delete File', 'tmwseo') . '</a> ';
                echo '<a class="button" href="' . esc_url($download_url) . '">' . esc_html__('Download', 'tmwseo') . '</a> ';
                echo '<a class="button button-primary" href="' . esc_url($reimport_url) . '">' . esc_html__('Re-import', 'tmwseo') . '</a>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
        }

        // ── Section 2: DB Import History ───────────────────────────────────
        self::render_db_import_history();

        echo '</div>';
    }

    /**
     * Render DB import history grouped by batch/source with cleanup actions.
     */
    private static function render_db_import_history(): void {
        global $wpdb;

        $seeds_table = $wpdb->prefix . 'tmwseo_seeds';
        $table_exists = ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $seeds_table)) === $seeds_table);

        echo '<h2 style="margin-top:28px;">' . esc_html__('DB Import History (tmwseo_seeds)', 'tmwseo') . '</h2>';

        if (!$table_exists) {
            echo '<p style="color:#dc2626;">Seeds table does not exist yet.</p>';
            return;
        }

        // Check whether provenance columns exist (added in 4.3.0 migration).
        $columns = $wpdb->get_results( "SHOW COLUMNS FROM {$seeds_table}", ARRAY_A );
        $col_names = is_array( $columns ) ? array_column( $columns, 'Field' ) : [];
        $has_provenance = in_array( 'import_batch_id', $col_names, true );

        // Batched imports (have import_batch_id)
        if ( $has_provenance ) {
            $batches = $wpdb->get_results(
                "SELECT
                    COALESCE(import_batch_id, '') AS batch_id,
                    COALESCE(import_source_label, '') AS source_label,
                    source,
                    COUNT(*) AS row_count,
                    MIN(created_at) AS earliest,
                    MAX(created_at) AS latest
                 FROM {$seeds_table}
                 WHERE source IN ('approved_import','csv_import')
                 GROUP BY COALESCE(import_batch_id, ''), COALESCE(import_source_label, ''), source
                 ORDER BY latest DESC",
                ARRAY_A
            );
        } else {
            // Fallback: provenance columns missing (pre-4.3.0 schema).
            $batches = $wpdb->get_results(
                "SELECT
                    '' AS batch_id,
                    '' AS source_label,
                    source,
                    COUNT(*) AS row_count,
                    MIN(created_at) AS earliest,
                    MAX(created_at) AS latest
                 FROM {$seeds_table}
                 WHERE source IN ('approved_import','csv_import')
                 GROUP BY source
                 ORDER BY latest DESC",
                ARRAY_A
            );

            if ( ! empty( $batches ) ) {
                echo '<div class="notice notice-warning" style="margin:12px 0;"><p><strong>' . esc_html__( 'Schema note:', 'tmwseo' ) . '</strong> '
                    . esc_html__( 'Provenance columns (import_batch_id, import_source_label) are missing. Deactivate and reactivate the plugin to run the 4.3.0 migration, or these rows will display without batch detail.', 'tmwseo' )
                    . '</p></div>';
            }
        }

        if (empty($batches)) {
            echo '<p>' . esc_html__('No imported seeds found in the database (no rows with source=approved_import or csv_import).', 'tmwseo') . '</p>';
            return;
        }

        // Check which original CSV files still exist
        $csv_dir = self::get_csv_dir();
        $existing_files = self::get_csv_files($csv_dir);
        $existing_names = array_map('basename', $existing_files);

        echo '<div style="background:#fefce8;border:1px solid #fde68a;border-radius:6px;padding:12px 16px;margin-bottom:14px;">';
        echo '<strong>' . esc_html__('Warning:', 'tmwseo') . '</strong> ';
        echo esc_html__('Deleting imported seeds removes them permanently from the tmwseo_seeds table. This cannot be undone. Seeds that have already been used for keyword discovery will not be recalled.', 'tmwseo');
        echo '</div>';

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        foreach (['Batch ID', 'Source Label', 'Source Type', 'Rows', 'Earliest', 'Latest', 'CSV File Exists?', 'Actions'] as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($batches as $b) {
            $batch_id     = (string) $b['batch_id'];
            $source_label = (string) $b['source_label'];
            $source       = (string) $b['source'];
            $row_count    = (int) $b['row_count'];
            $batch_display = $batch_id !== '' ? $batch_id : '<em>(no batch ID — legacy rows)</em>';

            // Try to match source label to a filename
            $file_match = '—';
            if ($source_label !== '' && in_array($source_label, $existing_names, true)) {
                $file_match = '<span style="color:#16a34a;">✓ ' . esc_html($source_label) . '</span>';
            } elseif ($source_label !== '') {
                // Try partial match
                $found = false;
                foreach ($existing_names as $fn) {
                    if (stripos($fn, pathinfo($source_label, PATHINFO_FILENAME)) !== false) {
                        $file_match = '<span style="color:#f59e0b;">~ ' . esc_html($fn) . '</span>';
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $file_match = '<span style="color:#6b7280;">✗ Not found</span>';
                }
            }

            // Build delete URL
            $delete_url = wp_nonce_url(add_query_arg([
                'action'       => 'tmw_csv_manager_delete_seeds',
                'batch_id'     => rawurlencode($batch_id),
                'source'       => rawurlencode($source),
                'source_label' => rawurlencode($source_label),
            ], admin_url('admin-post.php')), 'tmw_delete_seeds');

            echo '<tr>';
            echo '<td>' . wp_kses_post($batch_display) . '</td>';
            echo '<td>' . esc_html($source_label ?: '—') . '</td>';
            echo '<td><code>' . esc_html($source) . '</code></td>';
            echo '<td><strong>' . $row_count . '</strong></td>';
            echo '<td><small>' . esc_html($b['earliest']) . '</small></td>';
            echo '<td><small>' . esc_html($b['latest']) . '</small></td>';
            echo '<td>' . wp_kses_post($file_match) . '</td>';
            echo '<td>';
            echo '<a class="button button-link-delete button-small" href="' . esc_url($delete_url) . '" onclick="return confirm(\'' . esc_js(sprintf(__('Delete %d imported seed rows from database?', 'tmwseo'), $row_count)) . '\');">';
            echo esc_html(sprintf(__('Delete %d DB rows', 'tmwseo'), $row_count));
            echo '</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Handle deletion of imported seed rows from DB.
     */
    public static function handle_delete_seeds(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce((string) wp_unslash($_GET['_wpnonce']), 'tmw_delete_seeds')) {
            wp_die(__('Invalid nonce', 'tmwseo'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_seeds';

        $batch_id     = sanitize_text_field(rawurldecode((string) ($_GET['batch_id'] ?? '')));
        $source       = sanitize_key(rawurldecode((string) ($_GET['source'] ?? '')));
        $source_label = sanitize_text_field(rawurldecode((string) ($_GET['source_label'] ?? '')));

        // Build WHERE clause
        $where = "source = %s";
        $params = [$source];

        // Check if provenance columns exist before referencing them.
        $columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
        $col_names = is_array( $columns ) ? array_column( $columns, 'Field' ) : [];
        $has_provenance = in_array( 'import_batch_id', $col_names, true );

        if ( $has_provenance ) {
            if ($batch_id !== '') {
                $where .= " AND import_batch_id = %s";
                $params[] = $batch_id;
            } else {
                $where .= " AND (import_batch_id IS NULL OR import_batch_id = '')";
            }

            if ($source_label !== '') {
                $where .= " AND import_source_label = %s";
                $params[] = $source_label;
            } else {
                $where .= " AND (import_source_label IS NULL OR import_source_label = '')";
            }
        }
        // If provenance columns don't exist, we delete all rows matching the source only.

        $deleted = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE {$where}",
            ...$params
        ));

        \TMWSEO\Engine\Logs::info('csv_manager', 'Imported seeds deleted from DB', [
            'batch_id'     => $batch_id,
            'source'       => $source,
            'source_label' => $source_label,
            'deleted'      => $deleted,
            'user'         => get_current_user_id(),
        ]);

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-csv-manager&tmw_csv_notice=seeds_deleted&seed_count=' . $deleted));
        exit;
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
