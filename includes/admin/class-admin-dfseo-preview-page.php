<?php
declare(strict_types=1);

namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Services\Capabilities;
use TMWSEO\Engine\Admin\AdminUI;

if (!defined('ABSPATH')) { exit; }

/**
 * AdminDfseoPreviewPage — `tmwseo-dfseo-keyword-strategy-preview` page +
 * the paid-scan admin_post handler + the two scan-summary renderers.
 *
 * Extracted from class-admin.php as the sixth concrete step of the
 * god-class decomposition. Owns the full DataForSEO preview surface:
 *   - render() — the page itself (formerly Admin::render_dataforseo_keyword_strategy_preview).
 *     Dry-run planning UI; does not call DataForSEO.
 *   - handle_paid_scan() — admin_post handler that actually spends credits
 *     (formerly Admin::handle_dfseo_paid_keyword_scan). Confirmation-gated.
 *   - render_latest_runs_for_post() — recent-runs table.
 *   - render_scan_summary() — post-scan KPI panel + per-item breakdown.
 *   - preview_seed_strings() / preview_small_test_seeds() — private helpers
 *     for computing seed previews + small-test seed sets.
 *
 * Hook registrations (updated in Admin::init() + AdminMenu::register()):
 *   add_action('admin_post_tmwseo_run_dfseo_paid_keyword_scan', [...::class, 'handle_paid_scan']);
 *   add_submenu_page(..., 'tmwseo-dfseo-keyword-strategy-preview', [...::class, 'render']);
 *
 * Method renaming on extraction:
 *   Admin::render_dataforseo_keyword_strategy_preview → render()
 *   Admin::handle_dfseo_paid_keyword_scan             → handle_paid_scan()
 *   Admin::dfseo_preview_seed_strings                  → preview_seed_strings()
 *   Admin::dfseo_preview_small_test_seeds              → preview_small_test_seeds()
 *   Admin::render_dfseo_latest_runs_for_post           → render_latest_runs_for_post()
 *   Admin::render_dfseo_scan_summary                   → render_scan_summary()
 *
 * The `dfseo_` / `render_dfseo_` prefixes were necessary for namespace
 * separation inside the god class; inside a focused class they become
 * noise and get dropped.
 */
class AdminDfseoPreviewPage {

    /**
     * Page entry point: `tmwseo-dfseo-keyword-strategy-preview` submenu.
     *
     * The page is dry-run only — it builds the planned scan shape without
     * calling DataForSEO. The "Run Confirmed Paid Scan" form at the bottom
     * is what actually spends credits, and it POSTs to admin-post.php
     * (which routes to handle_paid_scan() below).
     */
    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'tmwseo' ) );
        }
        global $wpdb;

        $submitted = isset( $_REQUEST['tmwseo_dfseo_preview_submit'] );
        $post_id = isset( $_REQUEST['post_id'] ) ? absint( wp_unslash( $_REQUEST['post_id'] ) ) : 0;
        $override = isset( $_REQUEST['page_type_override'] ) ? sanitize_key( wp_unslash( $_REQUEST['page_type_override'] ) ) : 'auto';
        $allowed_overrides = [ 'auto', 'model', 'video', 'category', 'tag', 'opportunity' ];
        if ( ! in_array( $override, $allowed_overrides, true ) ) {
            $override = 'auto';
        }

        $plan = null;
        $error = '';

        if ( $submitted ) {
            check_admin_referer( 'tmwseo_dfseo_keyword_strategy_preview' );
            if ( $post_id <= 0 ) {
                $error = __( 'Please enter a valid post ID.', 'tmwseo' );
            } else {
                $strategy = '\TMWSEO\Engine\Keywords\DataForSEOPageTypeKeywordStrategy';
                $base_context = $strategy::build_context_from_post( $post_id );
                if ( $override === 'auto' ) {
                    $plan = $strategy::build_preview_plan_for_post( $post_id );
                } else {
                    $plan = $strategy::build_preview_plan_for_page_type( $override, $base_context );
                }
            }
        }

        $render_json = static function ( $value ): string {
            if ( ! is_array( $value ) ) {
                return '';
            }
            $encoded = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            return is_string( $encoded ) ? $encoded : '';
        };

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'DataForSEO Keyword Strategy Preview', 'tmwseo' ) . '</h1>';
        $dfseo_runs_table = $wpdb->prefix . 'tmwseo_dfseo_scan_runs';
        $dfseo_items_table = $wpdb->prefix . 'tmwseo_dfseo_scan_items';
        $ledger_tables_ok = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $dfseo_runs_table)) === $dfseo_runs_table)
            && ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $dfseo_items_table)) === $dfseo_items_table);

        echo '<p><strong>' . esc_html__( 'Preview only. This does not call DataForSEO and does not spend API credits.', 'tmwseo' ) . '</strong></p>';
        echo '<p class="description">' . esc_html__( 'Dry-run planning tool for page-type keyword strategy. No API requests, no paid scans, and no database writes are performed.', 'tmwseo' ) . '</p>';
        echo '<p><strong>' . esc_html__('Ledger tables:', 'tmwseo') . '</strong> ' . esc_html($ledger_tables_ok ? __('OK', 'tmwseo') : __('Missing', 'tmwseo')) . '</p>';
        if (!$ledger_tables_ok) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__('DataForSEO scan ledger tables are missing. No paid scan was run. Re-run plugin schema migration.', 'tmwseo')
                . '</p></div>';
        }

        if ( $error !== '' ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=tmwseo-dfseo-keyword-strategy-preview' ) ) . '" style="background:#fff;border:1px solid #dcdcde;padding:16px;max-width:820px;">';
        wp_nonce_field( 'tmwseo_dfseo_keyword_strategy_preview' );
        echo '<input type="hidden" name="tmwseo_dfseo_preview_submit" value="1" />';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="tmwseo_dfseo_preview_post_id">' . esc_html__( 'Post ID', 'tmwseo' ) . '</label></th>';
        echo '<td><input id="tmwseo_dfseo_preview_post_id" name="post_id" type="number" min="1" step="1" class="regular-text" value="' . esc_attr( (string) $post_id ) . '" required /></td></tr>';
        echo '<tr><th scope="row"><label for="tmwseo_dfseo_preview_override">' . esc_html__( 'Page type override', 'tmwseo' ) . '</label></th><td>';
        echo '<select id="tmwseo_dfseo_preview_override" name="page_type_override">';
        foreach ( $allowed_overrides as $value ) {
            $label = $value === 'auto' ? 'auto' : $value;
            echo '<option value="' . esc_attr( $value ) . '"' . selected( $override, $value, false ) . '>' . esc_html( ucfirst( $label ) ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Auto uses detected page type from the post context.', 'tmwseo' ) . '</p>';
        echo '</td></tr></tbody></table>';
        submit_button( __( 'Preview Strategy', 'tmwseo' ) );
        echo '</form>';

        if ( is_array( $plan ) ) {
            echo '<h2 style="margin-top:24px;">' . esc_html__( 'Preview Plan Result', 'tmwseo' ) . '</h2>';
            echo '<table class="widefat striped" style="max-width:1100px;"><tbody>';
            echo '<tr><th style="width:260px;">page_type</th><td>' . esc_html( (string) ( $plan['page_type'] ?? '' ) ) . '</td></tr>';
            echo '<tr><th>entity_name</th><td>' . esc_html( (string) ( $plan['entity_name'] ?? '' ) ) . '</td></tr>';
            echo '<tr><th>post_id</th><td>' . esc_html( (string) ( $plan['post_id'] ?? 0 ) ) . '</td></tr>';
            echo '<tr><th>post_type</th><td>' . esc_html( (string) ( $base_context['post_type'] ?? '' ) ) . '</td></tr>';
            echo '<tr><th>taxonomy_tags</th><td><code>' . esc_html( implode( ', ', (array) ( $plan['taxonomy_tags'] ?? [] ) ) ) . '</code></td></tr>';
            echo '<tr><th>taxonomy_categories</th><td><code>' . esc_html( implode( ', ', (array) ( $plan['taxonomy_categories'] ?? [] ) ) ) . '</code></td></tr>';
            echo '<tr><th>verified_platforms</th><td><code>' . esc_html( implode( ', ', (array) ( $plan['verified_platforms'] ?? [] ) ) ) . '</code></td></tr>';
            echo '<tr><th>related_content_tags</th><td><code>' . esc_html( implode( ', ', (array) ( $plan['related_content_tags'] ?? [] ) ) ) . '</code></td></tr>';
            echo '<tr><th>related_content_categories</th><td><code>' . esc_html( implode( ', ', (array) ( $plan['related_content_categories'] ?? [] ) ) ) . '</code></td></tr>';
            echo '<tr><th>modifier_terms</th><td><code>' . esc_html( implode( ', ', (array) ( $plan['modifier_terms'] ?? [] ) ) ) . '</code></td></tr>';
            echo '<tr><th>warnings</th><td><code>' . esc_html( implode( ', ', (array) ( $plan['warnings'] ?? [] ) ) ) . '</code></td></tr>';
            echo '</tbody></table>';

            echo '<div style="display:grid;grid-template-columns:1fr;gap:16px;max-width:1100px;margin-top:16px;">';
            echo '<div style="background:#fff;border:1px solid #dcdcde;padding:12px;"><h3 style="margin-top:0;">seed_groups</h3><pre style="overflow:auto;">' . esc_html( $render_json( (array) ( $plan['seed_groups'] ?? [] ) ) ) . '</pre></div>';
            echo '<div style="background:#fff;border:1px solid #dcdcde;padding:12px;"><h3 style="margin-top:0;">endpoint_plan</h3><pre style="overflow:auto;">' . esc_html( $render_json( (array) ( $plan['recommended_endpoints'] ?? [] ) ) ) . '</pre></div>';
            echo '<div style="background:#fff;border:1px solid #dcdcde;padding:12px;"><h3 style="margin-top:0;">notes</h3><pre style="overflow:auto;">' . esc_html( $render_json( (array) ( $plan['notes'] ?? [] ) ) ) . '</pre></div>';
            echo '</div>';

            $seed_groups = (array) ($plan['seed_groups'] ?? []);
            $seed_strings = self::preview_seed_strings($seed_groups);
            $seed_count = count($seed_strings);
            $seed_preview = array_slice($seed_strings, 0, 8);
            $endpoint_plan = array_values(array_filter(array_map('strval', (array) ($plan['recommended_endpoints'] ?? []))));
            $estimated_task_count = $seed_count * count($endpoint_plan);
            $small_test_default = true;
            $small_test_seeds = self::preview_small_test_seeds($seed_groups, 2);
            $small_test_seed_count = count($small_test_seeds);
            $small_test_endpoint = in_array('dataforseo_labs/google/keyword_suggestions/live', $endpoint_plan, true)
                ? 'dataforseo_labs/google/keyword_suggestions/live'
                : (!empty($endpoint_plan) ? (string) $endpoint_plan[0] : '');
            $small_test_endpoint_count = $small_test_endpoint !== '' ? 1 : 0;
            $small_test_task_count = $small_test_seed_count * $small_test_endpoint_count;
            AdminUI::section_start(__('Paid Scan Preflight (Manual)', 'tmwseo'));
            AdminUI::alert(__('This action may spend DataForSEO credits. Paid DataForSEO calls only happen after explicit confirmation.', 'tmwseo'), 'warn');
            AdminUI::trust_reminder(__('Manual-only: this scan fetches keyword intelligence for review. It does not create pages, create drafts, publish content, or change frontend templates.', 'tmwseo'));
            echo '<p><strong>' . esc_html__('Freshness policy:', 'tmwseo') . '</strong> ' . esc_html__('Fresh ≤30 days, Stale 31–90 days, Old >90 days. Fresh results are reused by default unless force refresh is checked.', 'tmwseo') . '</p>';
            echo '<ul style="list-style:disc;padding-left:20px;">';
            echo '<li><strong>Post ID:</strong> ' . esc_html((string) $post_id) . '</li>';
            echo '<li><strong>Post title:</strong> ' . esc_html(get_the_title($post_id)) . '</li>';
            echo '<li><strong>Detected page type:</strong> ' . esc_html((string) ($plan['page_type'] ?? 'unknown')) . '</li>';
            echo '<li><strong>Seed count:</strong> ' . esc_html((string) $seed_count) . '</li>';
            echo '<li><strong>Seed preview:</strong> <code>' . esc_html(implode(', ', $seed_preview)) . '</code></li>';
            echo '<li><strong>Endpoint plan:</strong> <code>' . esc_html(implode(', ', $endpoint_plan)) . '</code></li>';
            echo '<li><strong>Estimated task count:</strong> ' . esc_html((string) $estimated_task_count) . '</li>';
            echo '<li><strong>Full plan:</strong> ' . esc_html((string) $seed_count) . ' seeds × ' . esc_html((string) count($endpoint_plan)) . ' endpoints = ' . esc_html((string) $estimated_task_count) . ' possible tasks</li>';
            echo '<li><strong>This run (small test):</strong> ' . esc_html((string) $small_test_seed_count) . ' seeds × ' . esc_html((string) $small_test_endpoint_count) . ' endpoint = ' . esc_html((string) $small_test_task_count) . ' paid tasks</li>';
            if (!empty($small_test_seeds)) {
                echo '<li><strong>Small test seeds:</strong> <code>' . esc_html(implode(', ', $small_test_seeds)) . '</code></li>';
            }
            echo '<li><strong>Location / language:</strong> ' . esc_html((string)\TMWSEO\Engine\Services\DataForSEO::default_location_code()) . ' / ' . esc_html((string)\TMWSEO\Engine\Services\DataForSEO::default_language_code()) . '</li>';
            echo '</ul>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('tmwseo_dfseo_paid_scan_confirm');
            echo '<input type="hidden" name="action" value="tmwseo_run_dfseo_paid_keyword_scan" />';
            echo '<input type="hidden" name="post_id" value="' . esc_attr((string)$post_id) . '" />';
            echo '<p><label><input type="checkbox" name="tmwseo_paid_ack" value="1" required> ' . esc_html__('I understand this may spend DataForSEO credits and will only fetch/store review data.', 'tmwseo') . '</label></p>';
            echo '<p><label><input type="checkbox" name="force_refresh" value="1"> ' . esc_html__('Force refresh (ignore fresh cached results for this manual run).', 'tmwseo') . '</label></p>';
            echo '<p><label><input type="checkbox" name="small_test_scan" value="1" ' . checked($small_test_default, true, false) . '> ' . esc_html__('Run small test scan only (default): top-ranked 2 seeds and 1 endpoint.', 'tmwseo') . '</label></p>';
            submit_button(__('Run Confirmed Paid Scan', 'tmwseo'), 'primary', 'submit', false, $ledger_tables_ok ? [] : ['disabled' => 'disabled']);
            echo '</form>';
            AdminUI::section_end();
        }

        $scan_run_id = isset($_GET['scan_run_id']) ? absint(wp_unslash($_GET['scan_run_id'])) : 0;
        $notice = isset($_GET['tmwseo_notice']) ? sanitize_key((string) wp_unslash($_GET['tmwseo_notice'])) : '';
        if ($notice === 'scan_complete' && $scan_run_id <= 0) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__('Scan completed notice was returned without a valid run ID. Check tmwseo_dfseo_scan_runs and tmwseo_dfseo_scan_items.', 'tmwseo')
                . '</p></div>';
        }
        if ($scan_run_id > 0) {
            self::render_scan_summary($scan_run_id);
        }
        if ($post_id > 0) {
            self::render_latest_runs_for_post($post_id);
        }

        echo '</div>';
    }

    /**
     * admin_post_tmwseo_run_dfseo_paid_keyword_scan handler.
     * Routes to the paid scan runner after a checkbox-acknowledged
     * confirmation. Bounces back to the preview page with a notice.
     */
    public static function handle_paid_scan(): void {
        Capabilities::ensure('manage_options', esc_html__('Unauthorized', 'tmwseo'));
        check_admin_referer('tmwseo_dfseo_paid_scan_confirm');
        $post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;
        $ack = !empty($_POST['tmwseo_paid_ack']);
        $force = !empty($_POST['force_refresh']);
        $small_test = !empty($_POST['small_test_scan']);
        if ($post_id <= 0 || !$ack) {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-dfseo-keyword-strategy-preview&post_id=' . $post_id . '&tmwseo_notice=scan_rejected'));
            exit;
        }
        $res = \TMWSEO\Engine\Keywords\DataForSEOPaidKeywordScanRunner::run_for_post($post_id, $force, $small_test);
        $args = ['page' => 'tmwseo-dfseo-keyword-strategy-preview', 'post_id' => $post_id];
        if (!($res['ok'] ?? false)) {
            $args['tmwseo_notice'] = (string)($res['error'] ?? 'scan_failed');
            if (!empty($res['run_id']) && (int) $res['run_id'] > 0) {
                $args['scan_run_id'] = (int) $res['run_id'];
            }
        } else {
            $run_id = (int) ($res['run_id'] ?? 0);
            if ($run_id > 0) {
                $args['scan_run_id'] = $run_id;
                $args['tmwseo_notice'] = 'scan_complete';
            } else {
                $args['tmwseo_notice'] = 'scan_run_create_failed';
            }
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * Renders the "Latest DataForSEO Scan Runs for this Post" table at
     * the bottom of the preview page.
     */
    private static function render_latest_runs_for_post(int $post_id): void {
        global $wpdb;

        $runs = $wpdb->prefix . 'tmwseo_dfseo_scan_runs';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,status,seed_count,endpoint_count,estimated_task_count,fetched_count,stored_count,filtered_count,reused_fresh_count,reused_stale_count,skipped_count,created_at,completed_at FROM {$runs} WHERE post_id=%d ORDER BY id DESC LIMIT 5",
            $post_id
        ), ARRAY_A) ?: [];

        AdminUI::section_start(__('Latest DataForSEO Scan Runs for this Post', 'tmwseo'));
        if (empty($rows)) {
            echo '<p>' . esc_html__('No scan runs found for this post yet.', 'tmwseo') . '</p>';
            AdminUI::section_end();
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Status</th><th>Seed Count</th><th>Endpoint Count</th><th>Estimated Tasks</th><th>Fetched</th><th>Stored</th><th>Filtered</th><th>Reused Fresh</th><th>Reused Stale</th><th>Skipped</th><th>Created At</th><th>Completed At</th><th>Summary</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $summary_url = add_query_arg([
                'page' => 'tmwseo-dfseo-keyword-strategy-preview',
                'post_id' => $post_id,
                'scan_run_id' => (int) $row['id'],
            ], admin_url('admin.php'));
            echo '<tr>';
            echo '<td>' . esc_html((string) $row['id']) . '</td>';
            echo '<td>' . esc_html((string) $row['status']) . '</td>';
            echo '<td>' . esc_html((string) $row['seed_count']) . '</td>';
            echo '<td>' . esc_html((string) $row['endpoint_count']) . '</td>';
            echo '<td>' . esc_html((string) $row['estimated_task_count']) . '</td>';
            echo '<td>' . esc_html((string) $row['fetched_count']) . '</td>';
            echo '<td>' . esc_html((string) $row['stored_count']) . '</td>';
            echo '<td>' . esc_html((string) $row['filtered_count']) . '</td>';
            echo '<td>' . esc_html((string) $row['reused_fresh_count']) . '</td>';
            echo '<td>' . esc_html((string) $row['reused_stale_count']) . '</td>';
            echo '<td>' . esc_html((string) $row['skipped_count']) . '</td>';
            echo '<td>' . esc_html((string) $row['created_at']) . '</td>';
            echo '<td>' . esc_html((string) $row['completed_at']) . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url($summary_url) . '">' . esc_html__('Open Summary', 'tmwseo') . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        AdminUI::section_end();
    }

    /**
     * Renders the post-scan summary KPI panel + per-item breakdown for
     * a single scan run.
     */
    private static function render_scan_summary(int $run_id): void {
        global $wpdb; $runs = $wpdb->prefix . 'tmwseo_dfseo_scan_runs'; $items = $wpdb->prefix . 'tmwseo_dfseo_scan_items';
        $run = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$runs} WHERE id=%d", $run_id), ARRAY_A); if (!$run) { return; }
        AdminUI::section_start(__('Post-Scan Summary', 'tmwseo'));
        AdminUI::kpi_row([
            ['value'=>(int)$run['fetched_count'],'label'=>'Fetched','color'=>'neutral'],
            ['value'=>(int)$run['stored_count'],'label'=>'Stored','color'=>'ok'],
            ['value'=>(int)$run['filtered_count'],'label'=>'Filtered','color'=>'warn'],
            ['value'=>(int)$run['reused_fresh_count'],'label'=>'Reused Fresh','color'=>'ok'],
            ['value'=>(int)$run['reused_stale_count'],'label'=>'Reused Stale/Old','color'=>'warn'],
            ['value'=>(int)$run['skipped_count'],'label'=>'Skipped/Failed','color'=>'danger'],
        ]);
        echo '<p><strong>Run #'.esc_html((string)$run_id).'</strong> | Status: '.esc_html((string)$run['status']).' | Location/Language: '.esc_html((string)$run['location_code']).'/'.esc_html((string)$run['language_code']).'</p>';
        $fetched_count = (int) ($run['fetched_count'] ?? 0);
        $stored_count = (int) ($run['stored_count'] ?? 0);
        $filtered_count = (int) ($run['filtered_count'] ?? 0);
        $filtered_ratio = $fetched_count > 0 ? ($filtered_count / $fetched_count) : 0.0;
        if ($filtered_ratio >= 0.70 || ($fetched_count > 0 && $stored_count === 0)) {
            echo '<div class="notice notice-warning inline"><p>'
                . esc_html__('Most fetched keywords were filtered because they did not match the model entity. This is expected for strict relevance filtering and prevents irrelevant storage.', 'tmwseo')
                . '</p></div>';
        }
        AdminUI::section_start(__('Credit Safety', 'tmwseo'));
        echo '<ul style="list-style:disc;padding-left:20px;">';
        echo '<li>' . esc_html__('Paid calls were attempted only for seed/endpoint pairs without fresh cached results.', 'tmwseo') . '</li>';
        echo '<li>' . esc_html__('Fresh cached pairs were reused by default unless force refresh was selected.', 'tmwseo') . '</li>';
        echo '<li>' . esc_html__('No pages were created.', 'tmwseo') . '</li>';
        echo '<li>' . esc_html__('No drafts were created.', 'tmwseo') . '</li>';
        echo '<li>' . esc_html__('No posts were published.', 'tmwseo') . '</li>';
        echo '<li>' . esc_html__('This is manual review data only.', 'tmwseo') . '</li>';
        echo '</ul>';
        AdminUI::section_end();
        AdminUI::trust_reminder(__('No pages were created. No posts were published. No automatic publishing is enabled in this pass.', 'tmwseo'));
        $reason_rows = $wpdb->get_results($wpdb->prepare("SELECT filter_reason, COUNT(*) AS c FROM {$items} WHERE run_id=%d AND status='filtered' GROUP BY filter_reason ORDER BY c DESC", $run_id), ARRAY_A) ?: [];
        $cached_rejected_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$items} WHERE run_id=%d AND status='filtered' AND filter_reason='cached_result_failed_current_filter'", $run_id));
        if ($cached_rejected_count > 0) {
            echo '<div class="notice notice-warning inline"><p>'
                . esc_html__('Some fresh cached rows were rejected because they no longer pass the current relevance filter. They were not reused.', 'tmwseo')
                . '</p></div>';
        }
        if (!empty($reason_rows)) {
            echo '<p><strong>' . esc_html__('Filtered by reason:', 'tmwseo') . '</strong> ';
            $chips = [];
            foreach ($reason_rows as $reason_row) {
                $chips[] = esc_html((string)($reason_row['filter_reason'] ?: 'unknown')) . ': ' . esc_html((string)$reason_row['c']);
            }
            echo implode(' | ', $chips);
            echo '</p>';
        }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT status,keyword,seed,endpoint,filter_reason,freshness,fetched_at,volume,cpc,competition,intent FROM {$items} WHERE run_id=%d ORDER BY id DESC LIMIT 250", $run_id), ARRAY_A) ?: [];
        echo '<table class="widefat striped"><thead><tr><th>Status</th><th>Keyword</th><th>Seed</th><th>Endpoint</th><th>Freshness</th><th>Reason</th></tr></thead><tbody>';
        foreach ($rows as $r) { echo '<tr><td>'.esc_html((string)$r['status']).'</td><td>'.esc_html((string)$r['keyword']).'</td><td>'.esc_html((string)$r['seed']).'</td><td><code>'.esc_html((string)$r['endpoint']).'</code></td><td>'.esc_html((string)$r['freshness']).'</td><td>'.esc_html((string)($r['filter_reason']??'')).'</td></tr>'; }
        echo '</tbody></table>';
        AdminUI::section_end();
    }

    /**
     * Top N seeds from each seed group, used for the small-test scan
     * preview row. Delegates to the scan-runner's native preview helper
     * when present (introduced post-extraction); otherwise falls back to
     * a simple "first N of all unique seeds" projection.
     */
    private static function preview_small_test_seeds(array $seed_groups, int $max = 2): array {
        $runner = '\TMWSEO\Engine\Keywords\DataForSEOPaidKeywordScanRunner';
        if (!method_exists($runner, 'preview_small_test_seeds')) {
            $all = self::preview_seed_strings($seed_groups);
            return array_slice($all, 0, max(0, $max));
        }

        return $runner::preview_small_test_seeds($seed_groups, $max);
    }

    /**
     * Flattens a list of seed-group rows into a deduplicated array of
     * seed strings, preserving first-seen order.
     */
    private static function preview_seed_strings(array $seed_groups): array {
        $seeds = [];

        foreach ($seed_groups as $row) {
            if (!is_array($row)) {
                continue;
            }

            $seed = sanitize_text_field((string) ($row['seed'] ?? ''));
            if ($seed === '') {
                continue;
            }

            $seeds[] = $seed;
        }

        return array_values(array_unique($seeds));
    }
}
