<?php
namespace TMWSEO\Engine\Admin;

if (!defined('ABSPATH')) { exit; }

class JobMonitorAdminPage {
    public static function init(): void {
        add_action('admin_post_tmwseo_delete_completed_jobs', [__CLASS__, 'handle_delete_completed_jobs']);
        add_action('wp_ajax_tmwseo_job_monitor_rows', [__CLASS__, 'ajax_rows']);
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'tmwseo'));
        }


        $status = sanitize_key((string) ($_GET['status'] ?? 'all'));
        if (!in_array($status, ['all', 'running', 'completed', 'failed', 'pending'], true)) {
            $status = 'all';
        }

        $jobs = \TMW_Job_Logger::list_jobs($status, 200);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Job Monitor', 'tmwseo') . '</h1>';

        if (isset($_GET['tmw_jobs_deleted'])) {
            $deleted = (int) $_GET['tmw_jobs_deleted'];
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('Deleted %d completed jobs older than 30 days.', 'tmwseo'), $deleted)) . '</p></div>';
        }

        self::render_filters($status);

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0 16px;">';
        wp_nonce_field('tmwseo_delete_completed_jobs');
        echo '<input type="hidden" name="action" value="tmwseo_delete_completed_jobs">';
        submit_button(__('Delete Completed Jobs', 'tmwseo'), 'delete', 'submit', false);
        echo '</form>';

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('ID', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Job Type', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Source', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Status', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Progress', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Started', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Finished', 'tmwseo') . '</th>';
        echo '<th>' . esc_html__('Message', 'tmwseo') . '</th>';
        echo '</tr></thead>';
        echo '<tbody id="tmw-job-monitor-body">';
        self::render_rows($jobs);
        echo '</tbody>';
        echo '</table>';

        self::render_polling_script($status);
        echo '</div>';
    }

    private static function render_filters(string $status): void {
        $base_url = admin_url('admin.php?page=tmwseo-job-monitor');
        $filters = [
            'all' => __('All', 'tmwseo'),
            'running' => __('Running', 'tmwseo'),
            'completed' => __('Completed', 'tmwseo'),
            'failed' => __('Failed', 'tmwseo'),
        ];

        echo '<ul class="subsubsub">';
        $parts = [];
        foreach ($filters as $slug => $label) {
            $class = $status === $slug ? ' class="current"' : '';
            $url = $slug === 'all' ? $base_url : add_query_arg('status', $slug, $base_url);
            $parts[] = '<li><a' . $class . ' href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
        }
        echo implode(' | ', $parts);
        echo '</ul>';
    }

    private static function render_rows(array $jobs): void {
        if (empty($jobs)) {
            echo '<tr><td colspan="8">' . esc_html__('No jobs found.', 'tmwseo') . '</td></tr>';
            return;
        }

        foreach ($jobs as $job) {
            $id = (int) ($job['id'] ?? 0);
            $total = (int) ($job['items_total'] ?? 0);
            $processed = (int) ($job['items_processed'] ?? 0);
            $progress = $total > 0 ? ($processed . ' / ' . $total) : '-';

            echo '<tr>';
            echo '<td>' . esc_html((string) $id) . '</td>';
            echo '<td>' . esc_html((string) ($job['job_type'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($job['source'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($job['status'] ?? '')) . '</td>';
            echo '<td>' . esc_html($progress) . '</td>';
            echo '<td>' . esc_html((string) ($job['started_at'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($job['finished_at'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($job['message'] ?? '')) . '</td>';
            echo '</tr>';
        }
    }

    public static function handle_delete_completed_jobs(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'tmwseo'));
        }

        check_admin_referer('tmwseo_delete_completed_jobs');

        $deleted = \TMW_Job_Logger::cleanup_completed_older_than_days(30);

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-job-monitor&tmw_jobs_deleted=' . $deleted));
        exit;
    }

    public static function ajax_rows(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        check_ajax_referer('tmwseo-job-monitor-refresh');

        $status = sanitize_key((string) ($_GET['status'] ?? 'all'));
        $jobs = \TMW_Job_Logger::list_jobs($status, 200);

        ob_start();
        self::render_rows($jobs);
        $rows_html = ob_get_clean();

        wp_send_json_success(['rows_html' => $rows_html]);
    }

    private static function render_polling_script(string $status): void {
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('tmwseo-job-monitor-refresh');
        ?>
        <script>
        (function () {
            const status = <?php echo wp_json_encode($status); ?>;
            const endpoint = <?php echo wp_json_encode($ajax_url); ?>;
            const tbody = document.getElementById('tmw-job-monitor-body');
            if (!tbody) {
                return;
            }

            async function refreshRows() {
                const url = new URL(endpoint, window.location.origin);
                url.searchParams.set('action', 'tmwseo_job_monitor_rows');
                url.searchParams.set('status', status);
                url.searchParams.set('_ajax_nonce', <?php echo wp_json_encode($nonce); ?>);

                const response = await fetch(url.toString(), { credentials: 'same-origin' });
                if (!response.ok) {
                    return;
                }

                const payload = await response.json();
                if (!payload || !payload.success || !payload.data || !payload.data.rows_html) {
                    return;
                }

                tbody.innerHTML = payload.data.rows_html;
            }

            setInterval(refreshRows, 10000);
        })();
        </script>
        <?php
    }
}
