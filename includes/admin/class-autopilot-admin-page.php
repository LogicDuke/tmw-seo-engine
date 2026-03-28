<?php
namespace TMWSEO\Engine\Admin;

if (!defined('ABSPATH')) { exit; }

use TMWSEO\Engine\Autopilot\SEOAutopilot;

class AutopilotAdminPage {
    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'tmwseo'));
        }

        $snapshot = SEOAutopilot::get_snapshot();
        $notice = sanitize_key((string) ($_GET['notice'] ?? ''));

        echo '<div class="wrap tmwseo-dashboard">';
        echo '<h1>' . esc_html__('SEO Autopilot', 'tmwseo') . '</h1>';
        echo '<p>' . esc_html__('Autopilot assists strategy through analysis, planning, and recommendations. It never auto-publishes content.', 'tmwseo') . '</p>';

        if ($notice === 'run_complete') {
            echo '<div class="notice notice-success"><p>' . esc_html__('Autopilot pipeline completed.', 'tmwseo') . '</p></div>';
        }
        if ($notice === 'confirmation_required') {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Please confirm before running Autopilot manually.', 'tmwseo') . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:16px 0 24px;">';
        wp_nonce_field('tmwseo_autopilot_run_now');
        echo '<input type="hidden" name="action" value="tmwseo_autopilot_run_now">';
        echo '<label style="display:block;margin-bottom:8px;"><input type="checkbox" name="tmwseo_confirm" value="1"> ' . esc_html__('I confirm running Autopilot now (analysis only, no auto-publishing).', 'tmwseo') . '</label>';
        echo '<button class="button button-primary" type="submit">' . esc_html__('Run Autopilot Now', 'tmwseo') . '</button>';
        echo '</form>';

        self::render_keyword_opportunities((array) ($snapshot['discovery'] ?? []));
        self::render_cluster_opportunities((array) ($snapshot['clusters'] ?? []));
        self::render_brief_queue((array) ($snapshot['clusters'] ?? []), (array) ($snapshot['blueprints'] ?? []));
        self::render_internal_link_suggestions((array) ($snapshot['internal_links'] ?? []));
        self::render_gsc_alerts((array) ($snapshot['gsc'] ?? []));

        echo '</div>';
    }

    private static function render_keyword_opportunities(array $discovery): void {
        $new_kw_count  = (int) ($discovery['new_keywords'] ?? 0);
        $kw_page_url   = admin_url('admin.php?page=tmwseo-keywords&tab=pipeline');
        $opp_page_url  = admin_url('admin.php?page=tmwseo-opportunities');

        echo '<h2>' . esc_html__('Keyword Opportunities', 'tmwseo') . '</h2>';

        // Count links to the keywords pipeline
        echo '<p>';
        if ($new_kw_count > 0) {
            echo '<a href="' . esc_url($kw_page_url) . '" style="font-weight:700;color:#1e40af;">'
                . esc_html(sprintf('New keywords discovered: %d →', $new_kw_count))
                . '</a>';
        } else {
            echo esc_html(sprintf('New keywords discovered: %d', $new_kw_count));
        }
        echo ' &nbsp;';
        echo '<a href="' . esc_url($opp_page_url) . '" class="button button-small">'
            . esc_html__('View All Opportunities →', 'tmwseo') . '</a>';
        echo '</p>';

        echo '<table class="widefat striped"><thead><tr><th>Keyword</th><th>Volume</th><th>KD</th><th>Opportunity</th></tr></thead><tbody>';

        $rows = (array) ($discovery['top_opportunities'] ?? []);
        if (empty($rows)) {
            echo '<tr><td colspan="4">' . esc_html__('No keyword opportunities yet. Run Autopilot.', 'tmwseo') . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                echo '<tr>';
                // Keyword cell — link into opportunities page if possible
                echo '<td><a href="' . esc_url($opp_page_url) . '" style="color:inherit;">'
                    . esc_html((string) ($row['keyword'] ?? '')) . '</a></td>';
                echo '<td>' . esc_html(number_format_i18n((int) ($row['volume'] ?? 0))) . '</td>';
                echo '<td>' . esc_html((string) ($row['difficulty'] ?? '0')) . '</td>';
                echo '<td>' . esc_html((string) ($row['opportunity'] ?? '0')) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
    }

    private static function render_cluster_opportunities(array $clusters): void {
        $cluster_url = admin_url('admin.php?page=tmwseo-keywords&tab=clusters');

        echo '<h2 style="margin-top:24px;">' . esc_html__('Cluster Opportunities', 'tmwseo') . '</h2>';
        echo '<p><a href="' . esc_url($cluster_url) . '" class="button button-small">'
            . esc_html__('View All Clusters →', 'tmwseo') . '</a></p>';
        echo '<table class="widefat striped"><thead><tr><th>Cluster Name</th><th>Type</th><th>Volume</th><th>Avg KD</th><th>Opportunity</th><th>Suggested Page Type</th></tr></thead><tbody>';

        $rows = (array) ($clusters['top_clusters'] ?? []);
        if (empty($rows)) {
            echo '<tr><td colspan="6">' . esc_html__('No cluster opportunities available yet.', 'tmwseo') . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                echo '<tr>';
                echo '<td><a href="' . esc_url($cluster_url) . '" style="color:inherit;">'
                    . esc_html((string) ($row['cluster_name'] ?? '')) . '</a></td>';
                echo '<td>' . esc_html(ucfirst((string) ($row['type'] ?? 'informational'))) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((int) ($row['total_volume'] ?? 0))) . '</td>';
                echo '<td>' . esc_html((string) ($row['avg_keyword_difficulty'] ?? '0')) . '</td>';
                echo '<td>' . esc_html((string) ($row['opportunity_score'] ?? '0')) . '</td>';
                echo '<td>' . esc_html((string) ($row['suggested_page_type'] ?? 'Directory Page')) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
    }

    private static function render_brief_queue(array $clusters, array $blueprints): void {
        $blueprint_by_cluster = [];
        foreach ((array) ($blueprints['items'] ?? []) as $bp) {
            $blueprint_by_cluster[(string) ($bp['cluster_name'] ?? '')] = $bp;
        }

        echo '<h2 style="margin-top:24px;">' . esc_html__('Content Brief Queue', 'tmwseo') . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Cluster</th><th>Primary Keyword</th><th>Brief Actions</th></tr></thead><tbody>';

        $rows = array_slice((array) ($clusters['top_clusters'] ?? []), 0, 8);
        if (empty($rows)) {
            echo '<tr><td colspan="3">' . esc_html__('No briefs queued yet.', 'tmwseo') . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                $cluster_name = (string) ($row['cluster_name'] ?? '');
                $keywords = (array) ($row['keywords'] ?? []);
                $primary = (string) ($keywords[0] ?? $cluster_name);
                $bp = (array) ($blueprint_by_cluster[$cluster_name] ?? []);
                $brief = [
                    'primary_keyword' => $primary,
                    'secondary_keywords' => array_values(array_slice($keywords, 1, 5)),
                    'search_intent' => (string) ($row['type'] ?? 'informational'),
                    'recommended_word_count' => (int) ($bp['recommended_word_count'] ?? 1200),
                    'heading_outline' => (array) ($bp['required_headings'] ?? []),
                    'faq_suggestions' => [
                        sprintf('What should users know about %s?', $primary),
                        sprintf('How to choose the best %s?', $primary),
                    ],
                    'internal_linking_targets' => ['Related hub pages', 'Relevant cluster pages'],
                    'meta_title_suggestions' => [sprintf('%s Directory (%s)', ucwords($primary), date('Y'))],
                    'note' => 'Autopilot generated draft brief. Manual editorial review required.',
                ];
                $brief_json = wp_json_encode($brief, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $download = 'data:text/plain;charset=utf-8,' . rawurlencode((string) $brief_json);

                echo '<tr>';
                echo '<td>' . esc_html($cluster_name) . '</td>';
                echo '<td>' . esc_html($primary) . '</td>';
                echo '<td>';
                echo '<details><summary>' . esc_html__('View Brief', 'tmwseo') . '</summary>';
                echo '<textarea readonly style="width:100%;min-height:180px;margin-top:8px;">' . esc_textarea((string) $brief_json) . '</textarea>';
                echo '<div style="margin-top:8px;display:flex;gap:8px;align-items:center;">';
                echo '<button class="button button-secondary" type="button" onclick="navigator.clipboard && navigator.clipboard.writeText(this.closest(\'details\').querySelector(\'textarea\').value)">' . esc_html__('Copy Brief', 'tmwseo') . '</button>';
                echo '<a class="button" href="' . esc_url($download) . '" download="brief-' . esc_attr(sanitize_title($cluster_name)) . '.txt">' . esc_html__('Export Brief', 'tmwseo') . '</a>';
                echo '<span>' . esc_html__('Saved to brief queue automatically as draft records.', 'tmwseo') . '</span>';
                echo '</div>';
                echo '</details>';
                echo '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
    }

    private static function render_internal_link_suggestions(array $internal): void {
        $suggestions_count = (int) ($internal['suggestions'] ?? 0);
        $orphans_count     = (int) ($internal['orphans'] ?? 0);
        $scanned_count     = (int) ($internal['scanned_pages'] ?? 0);
        $link_url          = admin_url('admin.php?page=tmwseo-internal-links');
        $reports_url       = admin_url('admin.php?page=tmwseo-reports&tab=orphans');

        echo '<h2 style="margin-top:24px;">' . esc_html__('Internal Link Suggestions', 'tmwseo') . '</h2>';
        echo '<p>';
        // Suggestions count links to internal link opportunities page
        if ($suggestions_count > 0) {
            echo '<a href="' . esc_url($link_url) . '" style="font-weight:700;">'
                . esc_html(sprintf('Suggestions: %d', $suggestions_count)) . '</a>';
        } else {
            echo esc_html(sprintf('Suggestions: %d', $suggestions_count));
        }
        echo ' | ';
        // Orphan count links to reports orphans tab
        if ($orphans_count > 0) {
            echo '<a href="' . esc_url($reports_url) . '" style="font-weight:700;color:#b45309;">'
                . esc_html(sprintf('Orphan pages: %d', $orphans_count)) . '</a>';
        } else {
            echo esc_html(sprintf('Orphan pages: %d', $orphans_count));
        }
        echo ' | ' . esc_html(sprintf('Scanned pages: %d', $scanned_count));
        echo '</p>';

        echo '<p><em>' . esc_html((string) ($internal['note'] ?? 'User approval is required before insertion.')) . '</em></p>';
        echo '<p><a class="button" href="' . esc_url($link_url) . '">' . esc_html__('Review Link Suggestions', 'tmwseo') . '</a></p>';
    }

    private static function render_gsc_alerts(array $gsc): void {
        echo '<h2 style="margin-top:24px;">' . esc_html__('GSC Alerts', 'tmwseo') . '</h2>';
        $alerts = (array) ($gsc['alerts'] ?? []);
        if (empty($alerts)) {
            echo '<p>' . esc_html((string) ($gsc['note'] ?? 'No alerts detected in the latest run.')) . '</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>Alert Type</th><th>Page</th><th>Clicks</th><th>Impressions</th><th>Avg Position</th><th>Queries</th></tr></thead><tbody>';
        foreach ($alerts as $alert) {
            echo '<tr>';
            echo '<td>' . esc_html(ucwords(str_replace('_', ' ', (string) ($alert['type'] ?? 'alert')))) . '</td>';
            echo '<td><a href="' . esc_url((string) ($alert['page'] ?? '#')) . '" target="_blank" rel="noopener">' . esc_html((string) ($alert['page'] ?? '')) . '</a></td>';
            echo '<td>' . esc_html(number_format_i18n((int) ($alert['clicks'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) ($alert['impressions'] ?? 0))) . '</td>';
            echo '<td>' . esc_html((string) ($alert['avg_position'] ?? '0')) . '</td>';
            echo '<td>' . esc_html(implode(', ', array_map('strval', array_slice((array) ($alert['queries'] ?? []), 0, 4)))) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
