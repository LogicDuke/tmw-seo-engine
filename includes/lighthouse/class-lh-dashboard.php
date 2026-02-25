<?php
namespace TMW\SEO\Lighthouse;

use TMWSEO\Engine\Admin;

if (!defined('ABSPATH')) { exit; }

class Dashboard {
    public const MENU_SLUG = 'tmwseo-lighthouse';

    public static function register_menu(): void {
        add_submenu_page(
            Admin::MENU_SLUG,
            __('Lighthouse', 'tmwseo'),
            __('Lighthouse', 'tmwseo'),
            'manage_options',
            self::MENU_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function filter_menu_page_url(string $url, string $menu_slug): string {
        if ($menu_slug !== self::MENU_SLUG) {
            return $url;
        }

        return admin_url('admin.php?page=' . self::MENU_SLUG);
    }

    public static function handle_scan_all(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('tmw_lighthouse_scan_all');

        Worker::schedule_weekly_scan();

        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&tmwseo_notice=scan_enqueued'));
        exit;
    }

    public static function handle_reset(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmw_lighthouse_reset');

        update_option('tmw_lighthouse_baseline_timestamp', time());

        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&baseline_reset=1'));
        exit;
    }

    public static function render_page(): void {
        global $wpdb;
        $runs = $wpdb->prefix . 'tmw_lighthouse_runs';
        $baseline_at = Worker::get_baseline_mysql_datetime();

        $avg = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT AVG(performance_score) AS avg_performance, AVG(seo_score) AS avg_seo, AVG(lcp) AS avg_lcp, AVG(cls) AS avg_cls, AVG(inp) AS avg_inp
                 FROM {$runs}
                 WHERE created_at >= %s",
                $baseline_at
            ),
            ARRAY_A
        );

        $issues = Advisor::get_systemic_issues('mobile');
        $targets = Targets::list_with_latest('mobile', 100);

        echo '<div class="wrap">';
        echo '<h1>TMW SEO Engine — Lighthouse</h1>';

        if (isset($_GET['tmwseo_notice']) && $_GET['tmwseo_notice'] === 'scan_enqueued') {
            echo '<div class="notice notice-success"><p>Weekly Lighthouse scan jobs were enqueued.</p></div>';
        }

        if (isset($_GET['baseline_reset'])) {
            echo '<div class="notice notice-success"><p>Lighthouse baseline successfully reset.</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:8px;">';
        wp_nonce_field('tmw_lighthouse_reset');
        echo '<input type="hidden" name="action" value="tmw_lighthouse_reset" />';
        submit_button('Reset Baseline', 'secondary', 'submit', false, ['onclick' => "return confirm('Reset Lighthouse baseline? Historical data will be retained but excluded from reporting.');"]);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:16px;">';
        wp_nonce_field('tmw_lighthouse_scan_all');
        echo '<input type="hidden" name="action" value="tmw_lighthouse_scan_all" />';
        submit_button('Scan All (100 mobile + 100 desktop)', 'primary', 'submit', false);
        echo '</form>';

        echo '<h2>Overview</h2>';
        echo '<p>Avg Performance: <strong>' . esc_html(number_format((float)($avg['avg_performance'] ?? 0), 2)) . '</strong> | '
            . 'Avg SEO: <strong>' . esc_html(number_format((float)($avg['avg_seo'] ?? 0), 2)) . '</strong> | '
            . 'Avg LCP: <strong>' . esc_html(number_format((float)($avg['avg_lcp'] ?? 0), 2)) . '</strong> | '
            . 'Avg CLS: <strong>' . esc_html(number_format((float)($avg['avg_cls'] ?? 0), 3)) . '</strong> | '
            . 'Avg INP: <strong>' . esc_html(number_format((float)($avg['avg_inp'] ?? 0), 2)) . '</strong></p>';

        echo '<h2>Systemic Issues</h2>';
        if (empty($issues)) {
            echo '<p>No systemic issues yet. Run a scan first.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Audit ID</th><th>Title</th><th>Frequency</th></tr></thead><tbody>';
            foreach ($issues as $issue) {
                echo '<tr><td>' . esc_html($issue['audit_id']) . '</td><td>' . esc_html($issue['title']) . '</td><td>' . esc_html((string)$issue['frequency']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h2>URLs</h2>';
        echo '<table class="widefat striped"><thead><tr><th>URL</th><th>Performance</th><th>SEO</th><th>LCP</th><th>CLS</th><th>INP</th><th>Last Run</th></tr></thead><tbody>';
        foreach ($targets as $row) {
            echo '<tr>';
            echo '<td><a href="' . esc_url((string)$row['url']) . '" target="_blank" rel="noopener">' . esc_html((string)$row['url']) . '</a></td>';
            echo '<td>' . esc_html((string)($row['performance_score'] ?? '—')) . '</td>';
            echo '<td>' . esc_html((string)($row['seo_score'] ?? '—')) . '</td>';
            echo '<td>' . esc_html((string)($row['lcp'] ?? '—')) . '</td>';
            echo '<td>' . esc_html((string)($row['cls'] ?? '—')) . '</td>';
            echo '<td>' . esc_html((string)($row['inp'] ?? '—')) . '</td>';
            echo '<td>' . esc_html((string)($row['last_run_at'] ?? '—')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '</div>';
    }
}
