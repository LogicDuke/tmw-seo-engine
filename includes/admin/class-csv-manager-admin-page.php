<?php
/**
 * CSV Manager Admin Page — Import Packs + Linked Seeds Explorer v5.3
 * @package TMWSEO\Engine\Admin
 */
namespace TMWSEO\Engine\Admin;
use TMWSEO\Engine\Admin;
use TMWSEO\Engine\Admin\TMWSEORoutes;
if (!defined('ABSPATH')) { exit; }

class CSVManagerAdminPage {
    private const MAX_PREVIEW_ROWS    = 50;
    private const MAX_INVENTORY_ROWS  = 100000;
    private const LARGE_FILE_BYTES    = 5242880;
    private const LINKED_PER_PAGE     = 100;
    private const PAGE_SLUG           = 'tmwseo-csv-manager';

    public static function init(): void {
        add_action('admin_post_tmw_csv_manager_delete',          [__CLASS__, 'handle_delete']);
        add_action('admin_post_tmw_csv_manager_download',        [__CLASS__, 'handle_download']);
        add_action('admin_post_tmw_csv_manager_reimport',        [__CLASS__, 'handle_reimport']);
        add_action('admin_post_tmw_csv_manager_delete_seeds',    [__CLASS__, 'handle_delete_seeds']);
        add_action('admin_post_tmw_csv_manager_delete_pack',     [__CLASS__, 'handle_delete_pack']);
        add_action('admin_post_tmw_csv_linked_seeds_export',     [__CLASS__, 'handle_linked_seeds_export']);
        add_action('admin_post_tmw_csv_linked_seeds_delete_all', [__CLASS__, 'handle_linked_seeds_delete_all']);
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        $tab = sanitize_key((string)($_GET['tmw_csv_tab'] ?? 'packs'));
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('CSV Manager / Explorer', 'tmwseo') . '</h1>';
        self::render_summary_bar();
        self::render_notices();
        $tabs = [
            'packs'        => __('📦 Import Packs', 'tmwseo'),
            'linked_seeds' => __('🔗 Linked Seeds', 'tmwseo'),
        ];
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:0;">';
        foreach ($tabs as $slug => $label) {
            $class = ($tab === $slug) ? 'nav-tab nav-tab-active' : 'nav-tab';
            $url   = add_query_arg(['page' => self::PAGE_SLUG, 'tmw_csv_tab' => $slug], admin_url('admin.php'));
            printf('<a href="%s" class="%s">%s</a>', esc_url($url), esc_attr($class), esc_html($label));
        }
        echo '</nav>';
        echo '<div style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:20px 20px 24px;">';
        switch ($tab) {
            case 'linked_seeds': self::render_tab_linked_seeds(); break;
            default:             self::render_tab_packs(); break;
        }
        echo '</div></div>';
    }

    // ── Summary bar ──────────────────────────────────────────────────────────
    private static function render_summary_bar(): void {
        $counts = KeywordDataRepository::summary_counts();

        // Each card: label, value, color, optional warn flag, destination URL, title attr
        $items = [
            [
                'label' => __('Trusted Seeds', 'tmwseo'),
                'value' => $counts['total_seeds'],
                'color' => '#1e40af',
                'href'  => TMWSEORoutes::trusted_seeds(),
                'title' => __('View all trusted seeds in the Seed Registry', 'tmwseo'),
            ],
            [
                // Count = approved_import + csv_import (both IMPORT_SOURCES).
                // Destination uses the __imported__ preset which expands to
                // source IN ('approved_import','csv_import') — row count matches exactly.
                'label' => __('Imported Seeds', 'tmwseo'),
                'value' => $counts['imported_seeds'],
                'color' => '#065f46',
                'href'  => TMWSEORoutes::imported_seeds(),
                'title' => __('View all imported seeds (approved_import + csv_import) in Seed Registry', 'tmwseo'),
            ],
            [
                'label' => __('Import Packs (files)', 'tmwseo'),
                'value' => $counts['import_packs'],
                'color' => '#6b21a8',
                'href'  => TMWSEORoutes::csv_packs(),
                'title' => __('View all import pack files in CSV Manager', 'tmwseo'),
            ],
            [
                'label' => __('Orphaned DB Packs', 'tmwseo'),
                'value' => $counts['orphaned_db_packs'],
                'color' => '#92400e',
                'warn'  => $counts['orphaned_db_packs'] > 0,
                'href'  => TMWSEORoutes::csv_packs( 'db_only' ),
                'title' => __('View DB-only (orphaned) packs in CSV Manager', 'tmwseo'),
            ],
            [
                // Count = pending + fast_track (both "needs review" statuses).
                // Destination = Preview tab with no status filter = "Needs Review" view,
                // which shows exactly pending + fast_track combined.
                'label' => __('Candidates Pending', 'tmwseo'),
                'value' => $counts['candidates_pending'],
                'color' => '#b45309',
                'href'  => TMWSEORoutes::preview_queue( '' ),
                'title' => __('Review pending + fast_track candidates in Expansion Preview', 'tmwseo'),
            ],
        ];

        // One shared CSS block for the clickable cards
        echo '<style>
.tmw-summary-card {
    display:inline-flex;flex-direction:column;align-items:center;justify-content:center;
    border-radius:6px;padding:10px 16px;min-width:130px;text-align:center;
    text-decoration:none;cursor:pointer;transition:box-shadow .15s,transform .1s;
    border-width:1px;border-style:solid;
}
.tmw-summary-card:hover,.tmw-summary-card:focus {
    box-shadow:0 2px 8px rgba(0,0,0,.14);transform:translateY(-1px);text-decoration:none;
}
.tmw-summary-card:focus { outline:2px solid #2271b1;outline-offset:2px; }
</style>';

        echo '<div style="display:flex;flex-wrap:wrap;gap:10px;margin:12px 0 16px;font-size:13px;" role="list">';
        foreach ( $items as $item ) {
            $bg   = ! empty( $item['warn'] ) ? '#fef3c7' : '#f8fafc';
            $bord = ! empty( $item['warn'] ) ? '#f59e0b' : '#e2e8f0';
            printf(
                '<a href="%s" class="tmw-summary-card" title="%s" role="listitem"'
                . ' style="background:%s;border-color:%s;">'
                . '<span style="font-size:22px;font-weight:700;color:%s;">%d</span>'
                . '<span style="color:#6b7280;margin-top:2px;">%s</span>'
                . '</a>',
                esc_url( $item['href'] ),
                esc_attr( $item['title'] ),
                esc_attr( $bg ),
                esc_attr( $bord ),
                esc_attr( $item['color'] ),
                (int) $item['value'],
                esc_html( $item['label'] )
            );
        }
        echo '</div>';
    }

    // ── Tab: Import Packs ─────────────────────────────────────────────────────
    private static function render_tab_packs(): void {
        $csv_dir = self::get_csv_dir();
        if (isset($_GET['tmw_csv_preview'])) {
            $pf = sanitize_file_name(rawurldecode((string)wp_unslash($_GET['tmw_csv_preview'])));
            self::render_preview_panel($pf, $csv_dir);
        }
        echo '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:12px 16px;margin-bottom:16px;font-size:13px;">';
        echo '<strong>' . esc_html__('Import Packs', 'tmwseo') . '</strong> ';
        echo esc_html__('Each row is a CSV file and/or its imported seed records. Use "View Seeds" to inspect exactly which seed rows belong to each pack.', 'tmwseo');
        echo ' <strong>Status:</strong> Linked = file + DB; File Only = not imported yet; DB Only = file deleted; Mismatch = partial filename match.';
        echo '</div>';
        $inventory     = self::build_inventory($csv_dir);
        $search        = sanitize_text_field((string)($_GET['tmw_csv_s']      ?? ''));
        $status_filter = sanitize_key((string)($_GET['tmw_csv_status'] ?? ''));
        $page_url      = admin_url('admin.php?page=' . self::PAGE_SLUG . '&tmw_csv_tab=packs');
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin-bottom:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '">';
        echo '<input type="hidden" name="tmw_csv_tab" value="packs">';
        echo '<input type="search" name="tmw_csv_s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Search filename, batch ID, source label…', 'tmwseo') . '" style="width:280px;">';
        echo '<select name="tmw_csv_status">';
        foreach (['' => 'All statuses', 'linked' => 'Linked', 'file_only' => 'File only', 'db_only' => 'DB only (orphaned)', 'mismatch' => 'Mismatch'] as $val => $label) {
            echo '<option value="' . esc_attr($val) . '"' . selected($status_filter, $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<button type="submit" class="button">Filter</button>';
        if ($search !== '' || $status_filter !== '') {
            echo '<a href="' . esc_url($page_url) . '" class="button">Clear</a>';
        }
        echo '</form>';
        $rows      = self::filter_inventory($inventory, $search, $status_filter);
        $total     = count($inventory);
        $shown     = count($rows);
        $by_status = array_count_values(array_column($inventory, 'status'));
        echo '<p style="color:#6b7280;margin:0 0 10px;font-size:13px;">' . esc_html(sprintf(
            __('Showing %1$d of %2$d records — %3$d linked, %4$d file-only, %5$d DB-only, %6$d mismatch', 'tmwseo'),
            $shown, $total,
            (int)($by_status['linked']??0), (int)($by_status['file_only']??0),
            (int)($by_status['db_only']??0), (int)($by_status['mismatch']??0)
        )) . '</p>';
        if (!KeywordDataRepository::has_provenance_columns() && KeywordDataRepository::seeds_table_exists()) {
            echo '<div class="notice notice-warning" style="margin:0 0 12px;"><p><strong>Schema note:</strong> Provenance columns (import_batch_id, import_source_label) are missing. Deactivate and reactivate the plugin to run the 4.3.0 migration. Without them, "View Seeds" falls back to source-only grouping.</p></div>';
        }
        if (empty($rows)) { echo '<p>No records found.</p>'; return; }
        echo self::table_styles();
        echo '<table class="widefat striped tmw-inv"><thead><tr>';
        foreach (['File / Label','File?','Size','Modified','CSV Rows','Columns','Source','Batch ID(s)','DB Seeds','Status','Actions'] as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) { self::render_inventory_row($row); }
        echo '</tbody></table>';
        echo '<div style="background:#fefce8;border:1px solid #fde68a;border-radius:6px;padding:10px 14px;margin-top:16px;font-size:13px;"><strong>Warning:</strong> Deleting imported seeds removes them permanently from tmwseo_seeds. This cannot be undone.</div>';
    }

    // ── Tab: Linked Seeds ─────────────────────────────────────────────────────
    private static function render_tab_linked_seeds(): void {
        $batch_id     = sanitize_text_field((string)($_GET['batch_id']     ?? ''));
        $source_label = sanitize_text_field((string)($_GET['source_label'] ?? ''));
        $source       = sanitize_key((string)($_GET['source']              ?? ''));
        $packs_url    = add_query_arg(['page' => self::PAGE_SLUG, 'tmw_csv_tab' => 'packs'], admin_url('admin.php'));

        if ($source === '') {
            echo '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:24px;text-align:center;">';
            echo '<p style="font-size:15px;color:#0369a1;margin:0 0 10px;">' . esc_html__('No import pack selected.', 'tmwseo') . '</p>';
            echo '<p style="color:#6b7280;">' . esc_html__('Go to the Import Packs tab and click "View Seeds" on any row.', 'tmwseo') . '</p>';
            echo '<a href="' . esc_url($packs_url) . '" class="button button-primary">← Back to Import Packs</a>';
            echo '</div>';
            return;
        }
        if (!in_array($source, ['approved_import','csv_import'], true)) {
            echo '<div class="notice notice-error"><p>Invalid source type.</p></div>';
            return;
        }

        $current_page = max(1, (int)($_GET['linked_paged'] ?? 1));
        $offset       = ($current_page - 1) * self::LINKED_PER_PAGE;
        $result       = KeywordDataRepository::get_seeds_for_pack($batch_id, $source_label, $source, self::LINKED_PER_PAGE, $offset);
        $total_count  = KeywordDataRepository::count_seeds_for_pack($batch_id, $source_label, $source);
        $rows         = $result['rows'];
        $is_legacy    = $result['legacy_fallback'];
        $warning      = $result['warning'];

        echo '<a href="' . esc_url($packs_url) . '" style="color:#2563eb;font-size:13px;">← Back to Import Packs</a>';
        echo '<h2 style="margin:10px 0 4px;">';
        if ($source_label !== '') {
            echo esc_html($source_label);
        } elseif ($batch_id !== '') {
            echo '<code>' . esc_html($batch_id) . '</code>';
        } else {
            echo 'Legacy import (no label/batch ID)';
        }
        echo '</h2>';

        echo '<dl style="display:grid;grid-template-columns:180px 1fr;gap:3px 12px;font-size:13px;margin-bottom:14px;">';
        echo '<dt style="font-weight:600;">Source type:</dt><dd><code>' . esc_html($source) . '</code></dd>';
        if ($batch_id !== '') { echo '<dt style="font-weight:600;">Batch ID:</dt><dd><code>' . esc_html($batch_id) . '</code></dd>'; }
        if ($source_label !== '') { echo '<dt style="font-weight:600;">Source label:</dt><dd>' . esc_html($source_label) . '</dd>'; }
        echo '<dt style="font-weight:600;">Total rows:</dt><dd><strong>' . (int)$total_count . '</strong></dd>';
        echo '</dl>';

        if ($is_legacy) {
            echo '<div class="notice notice-warning" style="margin:0 0 14px;"><p><strong>Legacy source-only grouping:</strong> ' . esc_html($warning) . '</p></div>';
        }

        // Action buttons
        $export_url = wp_nonce_url(add_query_arg([
            'action'       => 'tmw_csv_linked_seeds_export',
            'batch_id'     => rawurlencode($batch_id),
            'source_label' => rawurlencode($source_label),
            'source'       => rawurlencode($source),
        ], admin_url('admin-post.php')), 'tmw_linked_seeds_export');

        $delete_all_url = wp_nonce_url(add_query_arg([
            'action'       => 'tmw_csv_linked_seeds_delete_all',
            'batch_id'     => rawurlencode($batch_id),
            'source_label' => rawurlencode($source_label),
            'source'       => rawurlencode($source),
        ], admin_url('admin-post.php')), 'tmw_linked_seeds_delete_all');

        echo '<div style="display:flex;gap:8px;margin-bottom:16px;align-items:center;flex-wrap:wrap;">';
        echo '<a class="button" href="' . esc_url($export_url) . '">Export This Pack as CSV</a>';
        if ($total_count > 0) {
            echo '<a class="button" style="color:#b91c1c;border-color:#fca5a5;" href="' . esc_url($delete_all_url) . '"'
                . ' onclick="return confirm(\'' . esc_js(sprintf(__('Delete all %d seed rows in this pack? This cannot be undone.','tmwseo'), $total_count)) . '\');">'
                . esc_html(sprintf(__('Delete All %d Seeds in Pack','tmwseo'), $total_count)) . '</a>';
        }
        if ($is_legacy) {
            echo '<span style="color:#92400e;font-size:12px;background:#fef3c7;padding:4px 8px;border-radius:4px;">⚠ Legacy grouping — applies to ALL rows with this source type</span>';
        }
        echo '</div>';

        if (empty($rows)) { echo '<p>No seed rows found for this pack.</p>'; return; }

        $total_pages = (int)ceil($total_count / self::LINKED_PER_PAGE);
        if ($total_pages > 1) {
            $from = $offset + 1;
            $to   = min($offset + self::LINKED_PER_PAGE, $total_count);
            echo '<p style="color:#6b7280;font-size:13px;margin:0 0 8px;">' . esc_html(sprintf(__('Showing %1$d–%2$d of %3$d rows','tmwseo'), $from, $to, $total_count)) . '</p>';
        }

        echo self::table_styles();
        echo '<div style="overflow-x:auto;">';
        echo '<table class="widefat striped" style="font-size:12px;min-width:1200px;"><thead><tr>';
        foreach (['ID','Seed Phrase','Source','Seed Type','Priority','Entity Type','Entity ID','Created','Last Used','Last Expanded','Exp. Count','Net New','Dups Returned','Est. Spend USD','Last Provider','Cooldown Until','ROI Score','Consec. Zero','Batch ID','Source Label'] as $h) {
            echo '<th style="white-space:nowrap;">' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td>' . (int)($r['id']??0) . '</td>';
            echo '<td><strong>' . esc_html((string)($r['seed']??'')) . '</strong></td>';
            echo '<td><code>' . esc_html((string)($r['source']??'')) . '</code></td>';
            echo '<td>' . esc_html((string)($r['seed_type']??'—')) . '</td>';
            echo '<td>' . (int)($r['priority']??0) . '</td>';
            echo '<td>' . esc_html((string)($r['entity_type']??'—')) . '</td>';
            echo '<td>' . (int)($r['entity_id']??0) . '</td>';
            echo '<td style="white-space:nowrap;">' . esc_html((string)($r['created_at']??'—')) . '</td>';
            echo '<td style="white-space:nowrap;">' . esc_html((string)($r['last_used']??'—') ?: '—') . '</td>';
            echo '<td style="white-space:nowrap;">' . esc_html((string)($r['last_expanded_at']??'—') ?: '—') . '</td>';
            echo '<td>' . (int)($r['expansion_count']??0) . '</td>';
            echo '<td>' . (int)($r['net_new_yielded']??0) . '</td>';
            echo '<td>' . (int)($r['duplicates_returned']??0) . '</td>';
            $spend = $r['estimated_spend_usd'] ?? null;
            echo '<td>' . ($spend !== null ? '$' . number_format((float)$spend, 4) : '—') . '</td>';
            echo '<td>' . esc_html((string)($r['last_provider']??'—') ?: '—') . '</td>';
            echo '<td style="white-space:nowrap;">' . esc_html((string)($r['cooldown_until']??'—') ?: '—') . '</td>';
            $roi = $r['roi_score'] ?? null;
            echo '<td>' . ($roi !== null ? number_format((float)$roi, 2) : '—') . '</td>';
            echo '<td>' . (int)($r['consecutive_zero_yield']??0) . '</td>';
            echo '<td style="font-size:11px;">' . (isset($r['import_batch_id']) && $r['import_batch_id'] !== '' ? '<code>' . esc_html((string)$r['import_batch_id']) . '</code>' : '<em>—</em>') . '</td>';
            echo '<td style="font-size:11px;">' . esc_html((string)($r['import_source_label']??'—') ?: '—') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        // Pagination
        if ($total_pages > 1) {
            $base_args = ['page' => self::PAGE_SLUG, 'tmw_csv_tab' => 'linked_seeds', 'batch_id' => rawurlencode($batch_id), 'source_label' => rawurlencode($source_label), 'source' => rawurlencode($source)];
            echo '<div style="margin-top:12px;display:flex;gap:6px;flex-wrap:wrap;">';
            for ($p = 1; $p <= min($total_pages, 50); $p++) {
                $url   = add_query_arg(array_merge($base_args, ['linked_paged' => $p]), admin_url('admin.php'));
                $style = ($p === $current_page) ? 'font-weight:700;' : '';
                echo '<a href="' . esc_url($url) . '" class="button button-small" style="' . esc_attr($style) . '">' . (int)$p . '</a>';
            }
            echo '</div>';
        }
    }

    // ── Shared table CSS ──────────────────────────────────────────────────────
    private static function table_styles(): string {
        return '<style>
            .tmw-inv td,.tmw-inv th{vertical-align:top;font-size:13px;padding:6px 8px}
            .tmw-actions{display:flex;flex-wrap:wrap;gap:4px;min-width:190px}
            .tmw-actions .button{font-size:11px!important;height:auto;line-height:1.4;padding:3px 7px}
            .tmw-badge{display:inline-block;border-radius:3px;font-size:11px;font-weight:700;padding:2px 7px;white-space:nowrap}
            .tmw-badge-linked{background:#dcfce7;color:#15803d}
            .tmw-badge-file_only{background:#e0f2fe;color:#0369a1}
            .tmw-badge-db_only{background:#fef9c3;color:#854d0e}
            .tmw-badge-mismatch{background:#fee2e2;color:#991b1b}
            .tmw-multi-batch{color:#d97706;font-weight:700}
        </style>';
    }

    // ── Notices ───────────────────────────────────────────────────────────────
    private static function render_notices(): void {
        if (!isset($_GET['tmw_csv_notice'])) { return; }
        $notice = sanitize_key((string)wp_unslash($_GET['tmw_csv_notice']));
        if ($notice === 'deleted') {
            echo '<div class="notice notice-success is-dismissible"><p>CSV file deleted successfully.</p></div>';
        } elseif ($notice === 'reimported') {
            echo '<div class="notice notice-success is-dismissible"><p>CSV file re-import started successfully.</p></div>';
        } elseif ($notice === 'seeds_deleted') {
            $c = (int)($_GET['seed_count'] ?? 0);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf('%d imported seed rows deleted.', $c)) . '</p></div>';
        } elseif ($notice === 'pack_deleted') {
            $fd = (int)($_GET['file_deleted'] ?? 0);
            $sd = (int)($_GET['seeds_deleted'] ?? 0);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf('Import pack deleted — file: %s, seeds removed: %d.', $fd ? 'yes' : 'no (file not found)', $sd)) . '</p></div>';
        } elseif ($notice === 'pack_delete_failed') {
            $reason = sanitize_key((string)($_GET['reason'] ?? ''));
            $msg = ($reason === 'invalid_batch_payload') ? 'Delete Pack aborted: invalid batch payload. Nothing deleted.' : 'Delete Pack aborted: validation failed. Nothing deleted.';
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        } elseif ($notice === 'linked_deleted') {
            $c = (int)($_GET['deleted_count'] ?? 0);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf('%d seed rows deleted from this pack.', $c)) . '</p></div>';
        }
    }

    // ── Inventory row renderer ────────────────────────────────────────────────
    private static function render_inventory_row(array $row): void {
        $filename    = (string)$row['filename'];
        $file_exists = (bool)$row['file_exists'];
        $db_batches  = (array)$row['db_batches'];
        $status      = (string)$row['status'];
        $file_badge  = $file_exists ? '<span style="color:#15803d;font-weight:600;">Yes</span>' : '<span style="color:#dc2626;">No</span>';
        $size_str    = $file_exists ? esc_html(size_format($row['file_size'])) : '—';
        $mtime_str   = ($file_exists && $row['file_mtime']) ? esc_html(wp_date(get_option('date_format'), $row['file_mtime'])) : '—';
        $csv_rows_str = '—';
        if ($row['csv_row_count'] !== null) {
            $csv_rows_str = '<strong>' . esc_html((string)$row['csv_row_count']) . '</strong>';
            if ($row['count_truncated']) { $csv_rows_str .= '<br><small style="color:#9ca3af;">100k+ (truncated)</small>'; }
        }
        $headers_str = '—';
        if (!empty($row['csv_headers'])) {
            $n = count($row['csv_headers']);
            $headers_str = '<strong>' . $n . '</strong> col' . ($n !== 1 ? 's' : '') . '<br><small style="color:#6b7280;word-break:break-word;">' . esc_html(implode(', ', array_slice($row['csv_headers'], 0, 4))) . ($n > 4 ? ' …' : '') . '</small>';
        }
        $sources    = $db_batches ? array_unique(array_column($db_batches, 'source')) : [];
        $source_str = $sources ? '<code>' . implode('</code><br><code>', array_map('esc_html', $sources)) . '</code>' : '—';
        $batch_parts = [];
        foreach ($db_batches as $b) {
            $bid = (string)$b['batch_id'];
            $batch_parts[] = $bid !== '' ? esc_html($bid) : '<em style="color:#9ca3af;">(no ID)</em>';
        }
        $batch_str = $batch_parts ? implode('<br>', $batch_parts) : '—';
        if (count($db_batches) > 1) { $batch_str = '<span class="tmw-multi-batch">' . esc_html(sprintf('%d batches', count($db_batches))) . '</span><br>' . $batch_str; }
        $seed_str = $db_batches ? '<strong>' . esc_html((string)$row['db_seed_count']) . '</strong>' : '—';
        $badge = self::status_badge($status);
        $actions = [];
        if ($file_exists) {
            $preview_url = add_query_arg(['page' => self::PAGE_SLUG, 'tmw_csv_tab' => 'packs', 'tmw_csv_preview' => rawurlencode($filename)], admin_url('admin.php'));
            $actions[] = '<a class="button button-small" href="' . esc_url($preview_url) . '">Preview</a>';
            $dl_url = wp_nonce_url(add_query_arg(['action' => 'tmw_csv_manager_download', 'file' => rawurlencode($filename)], admin_url('admin-post.php')), 'tmw_download_csv');
            $actions[] = '<a class="button button-small" href="' . esc_url($dl_url) . '">Download</a>';
            $reimport_url = wp_nonce_url(add_query_arg(['action' => 'tmw_csv_manager_reimport', 'file' => rawurlencode($filename)], admin_url('admin-post.php')), 'tmw_reimport_csv');
            $actions[] = '<a class="button button-primary button-small" href="' . esc_url($reimport_url) . '">Re-import</a>';
            $del_file_url = wp_nonce_url(add_query_arg(['action' => 'tmw_csv_manager_delete', 'file' => rawurlencode($filename)], admin_url('admin-post.php')), 'tmw_delete_csv');
            $actions[] = '<a class="button button-link-delete button-small" href="' . esc_url($del_file_url) . '" onclick="return confirm(\'Delete the filesystem file only? DB seeds will remain.\');">Delete File</a>';
        }
        foreach ($db_batches as $b) {
            $view_url = add_query_arg(['page' => self::PAGE_SLUG, 'tmw_csv_tab' => 'linked_seeds', 'batch_id' => rawurlencode((string)$b['batch_id']), 'source_label' => rawurlencode((string)$b['source_label']), 'source' => rawurlencode((string)$b['source'])], admin_url('admin.php'));
            $btn = count($db_batches) > 1 ? sprintf('View %d Seeds (batch)', (int)$b['row_count']) : sprintf('View %d Seeds', (int)$b['row_count']);
            $actions[] = '<a class="button button-small" style="background:#dbeafe;color:#1e40af;border-color:#93c5fd;" href="' . esc_url($view_url) . '">' . esc_html($btn) . '</a>';
        }
        foreach ($db_batches as $b) {
            $bid = (string)$b['batch_id']; $src = (string)$b['source']; $sl = (string)$b['source_label']; $rc = (int)$b['row_count'];
            $del_seeds_url = wp_nonce_url(add_query_arg(['action' => 'tmw_csv_manager_delete_seeds', 'batch_id' => rawurlencode($bid), 'source' => rawurlencode($src), 'source_label' => rawurlencode($sl)], admin_url('admin-post.php')), 'tmw_delete_seeds');
            $btn = count($db_batches) > 1 ? sprintf('Delete %d Seeds (batch)', $rc) : sprintf('Delete %d Seeds', $rc);
            $actions[] = '<a class="button button-link-delete button-small" href="' . esc_url($del_seeds_url) . '" onclick="return confirm(\'' . esc_js(sprintf('Delete %d imported seed rows? This cannot be undone.', $rc)) . '\');">' . esc_html($btn) . '</a>';
        }
        if ($file_exists && !empty($db_batches)) {
            $pack_batches = array_map(fn($b) => ['batch_id' => (string)$b['batch_id'], 'source' => (string)$b['source'], 'source_label' => (string)$b['source_label']], $db_batches);
            $pack_url = wp_nonce_url(add_query_arg(['action' => 'tmw_csv_manager_delete_pack', 'file' => rawurlencode($filename), 'pack_batches' => rawurlencode((string)wp_json_encode($pack_batches))], admin_url('admin-post.php')), 'tmw_delete_pack');
            $actions[] = '<a class="button button-link-delete button-small" style="font-weight:700;border-color:#991b1b;" href="' . esc_url($pack_url) . '" onclick="return confirm(\'' . esc_js(sprintf('Delete ENTIRE import pack: file + %d DB seed rows? This cannot be undone.', (int)$row['db_seed_count'])) . '\');">Delete Pack</a>';
        }
        echo '<tr>';
        echo '<td><strong>' . esc_html($filename) . '</strong></td>';
        echo '<td>' . wp_kses_post($file_badge) . '</td>';
        echo '<td>' . $size_str . '</td>';
        echo '<td>' . $mtime_str . '</td>';
        echo '<td>' . wp_kses_post($csv_rows_str) . '</td>';
        echo '<td>' . wp_kses_post($headers_str) . '</td>';
        echo '<td>' . wp_kses_post($source_str) . '</td>';
        echo '<td style="font-size:11px;">' . wp_kses_post($batch_str) . '</td>';
        echo '<td>' . wp_kses_post($seed_str) . '</td>';
        echo '<td>' . wp_kses_post($badge) . '</td>';
        echo '<td><div class="tmw-actions">' . implode('', $actions) . '</div></td>';
        echo '</tr>';
    }

    private static function status_badge(string $status): string {
        $map = ['linked' => ['tmw-badge-linked','Linked'], 'file_only' => ['tmw-badge-file_only','File Only'], 'db_only' => ['tmw-badge-db_only','DB Only'], 'mismatch' => ['tmw-badge-mismatch','Mismatch']];
        [$cls, $lbl] = $map[$status] ?? ['',''];
        return '<span class="tmw-badge ' . esc_attr($cls) . '">' . esc_html($lbl ?: $status) . '</span>';
    }

    // ── New action handlers ───────────────────────────────────────────────────
    public static function handle_linked_seeds_export(): void {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('tmw_linked_seeds_export');
        $batch_id     = sanitize_text_field(rawurldecode((string)($_GET['batch_id']     ?? '')));
        $source_label = sanitize_text_field(rawurldecode((string)($_GET['source_label'] ?? '')));
        $source       = sanitize_key(rawurldecode((string)($_GET['source']              ?? '')));
        if (!in_array($source, ['approved_import','csv_import'], true)) { wp_die('Invalid source.'); }
        $result = KeywordDataRepository::get_seeds_for_pack($batch_id, $source_label, $source, 50000, 0);
        $label  = $source_label ?: ($batch_id ?: $source);
        KeywordDataRepository::stream_csv_download($result['rows'], 'seeds-pack-' . sanitize_file_name($label) . '-' . gmdate('YmdHis') . '.csv');
    }

    public static function handle_linked_seeds_delete_all(): void {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce((string)wp_unslash($_GET['_wpnonce']), 'tmw_linked_seeds_delete_all')) { wp_die('Invalid nonce'); }
        $batch_id     = sanitize_text_field(rawurldecode((string)($_GET['batch_id']     ?? '')));
        $source_label = sanitize_text_field(rawurldecode((string)($_GET['source_label'] ?? '')));
        $source       = sanitize_key(rawurldecode((string)($_GET['source']              ?? '')));
        if (!in_array($source, ['approved_import','csv_import'], true)) { wp_die('Invalid source.'); }
        global $wpdb;
        $table    = KeywordDataRepository::seeds_table();
        $has_prov = KeywordDataRepository::has_provenance_columns();
        if ($has_prov) {
            $where = 'source = %s'; $params = [$source];
            if ($batch_id !== '') { $where .= ' AND import_batch_id = %s'; $params[] = $batch_id; } else { $where .= " AND (import_batch_id IS NULL OR import_batch_id = '')"; }
            if ($source_label !== '') { $where .= ' AND import_source_label = %s'; $params[] = $source_label; }
            $deleted = (int)$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE {$where}", ...$params));
        } else {
            $deleted = (int)$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE source = %s", $source));
        }
        \TMWSEO\Engine\Logs::info('csv_manager', 'Linked seeds deleted via pack detail view', ['batch_id' => $batch_id, 'source_label' => $source_label, 'source' => $source, 'deleted' => $deleted, 'user' => get_current_user_id()]);
        wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'tmw_csv_tab' => 'packs', 'tmw_csv_notice' => 'linked_deleted', 'deleted_count' => $deleted], admin_url('admin.php')));
        exit;
    }

    // ── Existing action handlers (preserved from v5.2) ────────────────────────
    public static function handle_delete_pack(): void {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce((string)wp_unslash($_GET['_wpnonce']), 'tmw_delete_pack')) { wp_die('Invalid nonce'); }
        $target = self::get_validated_file_path();
        $raw = (string)($_GET['pack_batches'] ?? '');
        $pack_batches = self::parse_and_validate_pack_batches($raw);
        if ($pack_batches === null) {
            \TMWSEO\Engine\Logs::warning('csv_manager', 'handle_delete_pack aborted: invalid batch payload', ['raw_payload' => substr($raw, 0, 500), 'user' => get_current_user_id()]);
            wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tmw_csv_notice=pack_delete_failed&reason=invalid_batch_payload'));
            exit;
        }
        global $wpdb;
        $table = KeywordDataRepository::seeds_table();
        $has_prov = KeywordDataRepository::has_provenance_columns();
        $total_deleted = 0;
        foreach ($pack_batches as $b) {
            $source = $b['source']; $batch_id = $b['batch_id']; $source_label = $b['source_label'];
            if (!in_array($source, ['approved_import','csv_import'], true)) { continue; }
            if ($has_prov) {
                $where = 'source = %s'; $params = [$source];
                if ($batch_id !== '') { $where .= ' AND import_batch_id = %s'; $params[] = $batch_id; } else { $where .= " AND (import_batch_id IS NULL OR import_batch_id = '')"; }
                if ($source_label !== '') { $where .= ' AND import_source_label = %s'; $params[] = $source_label; } else { $where .= " AND (import_source_label IS NULL OR import_source_label = '')"; }
                $deleted = (int)$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE {$where}", ...$params));
            } else {
                \TMWSEO\Engine\Logs::warning('csv_manager', 'handle_delete_pack: provenance columns missing; source-only fallback', ['source' => $source, 'user' => get_current_user_id()]);
                $deleted = (int)$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE source = %s", $source));
            }
            $total_deleted += $deleted;
        }
        $file_deleted = false;
        if ($target !== null && is_file($target)) { $file_deleted = @unlink($target); }
        \TMWSEO\Engine\Logs::info('csv_manager', 'Import pack deleted', ['file_deleted' => $file_deleted, 'seeds_deleted' => $total_deleted, 'pack_batches' => $pack_batches, 'user' => get_current_user_id()]);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tmw_csv_notice=pack_deleted&file_deleted=' . ($file_deleted ? '1' : '0') . '&seeds_deleted=' . $total_deleted));
        exit;
    }

    public static function parse_and_validate_pack_batches(string $raw): ?array {
        $decoded = rawurldecode($raw);
        if (trim($decoded) === '') { return null; }
        $parsed = json_decode($decoded, true);
        if (!is_array($parsed) || empty($parsed)) { return null; }
        $valid = [];
        foreach ($parsed as $entry) {
            if (!is_array($entry)) { continue; }
            $source = sanitize_key((string)($entry['source'] ?? ''));
            if (!in_array($source, ['approved_import','csv_import'], true)) { continue; }
            $valid[] = ['batch_id' => sanitize_text_field((string)($entry['batch_id'] ?? '')), 'source' => $source, 'source_label' => sanitize_text_field((string)($entry['source_label'] ?? ''))];
        }
        return empty($valid) ? null : $valid;
    }

    public static function handle_delete_seeds(): void {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce((string)wp_unslash($_GET['_wpnonce']), 'tmw_delete_seeds')) { wp_die('Invalid nonce'); }
        global $wpdb;
        $table = KeywordDataRepository::seeds_table();
        $batch_id = sanitize_text_field(rawurldecode((string)($_GET['batch_id'] ?? '')));
        $source   = sanitize_key(rawurldecode((string)($_GET['source'] ?? '')));
        $sl       = sanitize_text_field(rawurldecode((string)($_GET['source_label'] ?? '')));
        $where = 'source = %s'; $params = [$source];
        if (KeywordDataRepository::has_provenance_columns()) {
            if ($batch_id !== '') { $where .= ' AND import_batch_id = %s'; $params[] = $batch_id; } else { $where .= " AND (import_batch_id IS NULL OR import_batch_id = '')"; }
            if ($sl !== '') { $where .= ' AND import_source_label = %s'; $params[] = $sl; } else { $where .= " AND (import_source_label IS NULL OR import_source_label = '')"; }
        }
        $deleted = (int)$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE {$where}", ...$params));
        \TMWSEO\Engine\Logs::info('csv_manager', 'Imported seeds deleted from DB', ['batch_id' => $batch_id, 'source' => $source, 'source_label' => $sl, 'deleted' => $deleted, 'user' => get_current_user_id()]);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tmw_csv_notice=seeds_deleted&seed_count=' . $deleted));
        exit;
    }

    public static function handle_delete(): void {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce((string)wp_unslash($_GET['_wpnonce']), 'tmw_delete_csv')) { wp_die('Invalid nonce'); }
        $target = self::get_validated_file_path();
        if ($target && is_file($target)) { @unlink($target); }
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tmw_csv_notice=deleted'));
        exit;
    }

    public static function handle_download(): void {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('tmw_download_csv');
        $target = self::get_validated_file_path();
        if (!$target || !is_file($target)) { wp_die('CSV file not found.'); }
        nocache_headers();
        header('Content-Description: File Transfer');
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . basename($target) . '"');
        header('Content-Length: ' . filesize($target));
        readfile($target);
        exit;
    }

    public static function handle_reimport(): void {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('tmw_reimport_csv');
        $target = self::get_validated_file_path();
        if (!$target || !is_file($target)) { wp_die('CSV file not found.'); }
        Admin::import_keywords_from_csv_path($target, 'manual_reimport', true);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tmw_csv_notice=reimported'));
        exit;
    }

    // ── Inventory helpers ─────────────────────────────────────────────────────
    private static function build_inventory(string $csv_dir): array {
        $files = self::get_csv_files($csv_dir);
        $db_batches = self::get_db_batches();
        $db_by_label = [];
        foreach ($db_batches as $b) { $db_by_label[(string)$b['source_label']][] = $b; }
        $rows = []; $matched_labels = [];
        foreach ($files as $file_path) {
            $filename = basename($file_path);
            $row = ['filename' => $filename, 'file_path' => $file_path, 'file_exists' => true, 'file_size' => (int)filesize($file_path), 'file_mtime' => (int)filemtime($file_path), 'db_batches' => [], 'db_seed_count' => 0, 'status' => 'file_only', '_sort_key' => (int)filemtime($file_path)];
            if (isset($db_by_label[$filename])) {
                $row['db_batches'] = $db_by_label[$filename]; $row['db_seed_count'] = (int)array_sum(array_column($db_by_label[$filename], 'row_count')); $row['status'] = 'linked'; $matched_labels[$filename] = true;
            } else {
                $stem = pathinfo($filename, PATHINFO_FILENAME);
                foreach ($db_by_label as $label => $batches) {
                    if ($label !== '' && stripos($label, $stem) !== false) { $row['db_batches'] = $batches; $row['db_seed_count'] = (int)array_sum(array_column($batches, 'row_count')); $row['status'] = 'mismatch'; $matched_labels[$label] = true; break; }
                }
            }
            $rows[] = $row;
        }
        foreach ($db_by_label as $label => $batches) {
            if (isset($matched_labels[$label])) { continue; }
            $latest_ts = 0;
            foreach ($batches as $b) { $ts = strtotime((string)$b['latest']); if ($ts && $ts > $latest_ts) { $latest_ts = $ts; } }
            $rows[] = ['filename' => $label !== '' ? $label : '(no label)', 'file_path' => null, 'file_exists' => false, 'file_size' => 0, 'file_mtime' => 0, 'db_batches' => $batches, 'db_seed_count' => (int)array_sum(array_column($batches, 'row_count')), 'status' => 'db_only', '_sort_key' => $latest_ts];
        }
        usort($rows, fn($a, $b) => $b['_sort_key'] <=> $a['_sort_key']);
        foreach ($rows as &$row) {
            if ($row['file_exists'] && is_string($row['file_path'])) {
                $meta = self::read_csv_meta($row['file_path'], 0);
                $row['csv_row_count'] = $meta['row_count']; $row['csv_headers'] = $meta['headers']; $row['count_truncated'] = $meta['count_truncated'];
            } else { $row['csv_row_count'] = null; $row['csv_headers'] = null; $row['count_truncated'] = false; }
        }
        unset($row);
        return $rows;
    }

    private static function filter_inventory(array $inventory, string $search, string $status): array {
        return array_values(array_filter($inventory, function(array $row) use ($search, $status): bool {
            if ($status !== '' && $row['status'] !== $status) { return false; }
            if ($search !== '') {
                $needle = strtolower($search); $haystack = strtolower($row['filename']);
                foreach ($row['db_batches'] as $b) { $haystack .= ' ' . strtolower((string)$b['batch_id']) . ' ' . strtolower((string)$b['source_label']); }
                if (strpos($haystack, $needle) === false) { return false; }
            }
            return true;
        }));
    }

    private static function get_db_batches(): array {
        global $wpdb;
        if (!KeywordDataRepository::seeds_table_exists()) { return []; }
        $table = KeywordDataRepository::seeds_table();
        $has_prov = KeywordDataRepository::has_provenance_columns();
        if ($has_prov) {
            $batches = $wpdb->get_results("SELECT COALESCE(import_batch_id,'') AS batch_id, COALESCE(import_source_label,'') AS source_label, source, COUNT(*) AS row_count, MIN(created_at) AS earliest, MAX(created_at) AS latest FROM {$table} WHERE source IN ('approved_import','csv_import') GROUP BY COALESCE(import_batch_id,''), COALESCE(import_source_label,''), source ORDER BY latest DESC", ARRAY_A);
        } else {
            $batches = $wpdb->get_results("SELECT '' AS batch_id, '' AS source_label, source, COUNT(*) AS row_count, MIN(created_at) AS earliest, MAX(created_at) AS latest FROM {$table} WHERE source IN ('approved_import','csv_import') GROUP BY source ORDER BY latest DESC", ARRAY_A);
        }
        return is_array($batches) ? $batches : [];
    }

    private static function read_csv_meta(string $path, int $max_preview_rows): array {
        $result = ['headers' => [], 'row_count' => 0, 'preview_rows' => [], 'preview_truncated' => false, 'count_truncated' => false, 'malformed_rows' => 0];
        if (!is_file($path) || !is_readable($path)) { return $result; }
        $fh = fopen($path, 'r');
        if ($fh === false) { return $result; }
        $first_line = fgets($fh);
        if ($first_line === false) { fclose($fh); return $result; }
        $delimiter = ',';
        $tab_n = substr_count($first_line, "\t"); $semi_n = substr_count($first_line, ';'); $comma_n = substr_count($first_line, ',');
        if ($tab_n > $comma_n && $tab_n > $semi_n) { $delimiter = "\t"; } elseif ($semi_n > $comma_n) { $delimiter = ';'; }
        rewind($fh);
        $header_row = fgetcsv($fh, 0, $delimiter);
        if (!is_array($header_row)) { fclose($fh); return $result; }
        $result['headers'] = array_map('trim', $header_row);
        $header_count = count($result['headers']);
        while (($data_row = fgetcsv($fh, 0, $delimiter)) !== false) {
            $result['row_count']++;
            if ($result['row_count'] > self::MAX_INVENTORY_ROWS) { $result['count_truncated'] = true; break; }
            if ($max_preview_rows > 0) {
                if (count($result['preview_rows']) < $max_preview_rows) {
                    if (count($data_row) !== $header_count) { $result['malformed_rows']++; }
                    $result['preview_rows'][] = array_pad(array_slice($data_row, 0, $header_count), $header_count, '');
                } else { $result['preview_truncated'] = true; }
            }
        }
        fclose($fh);
        return $result;
    }

    private static function render_preview_panel(string $filename, string $csv_dir): void {
        if ($filename === '' || strtolower((string)pathinfo($filename, PATHINFO_EXTENSION)) !== 'csv') { return; }
        $base_dir = realpath($csv_dir);
        if ($base_dir === false) { return; }
        $path = realpath(trailingslashit($base_dir) . $filename);
        if ($path === false || strpos($path, trailingslashit($base_dir)) !== 0 || !is_file($path)) {
            echo '<div class="notice notice-error"><p>Preview: file not found or path invalid.</p></div>'; return;
        }
        $meta = self::read_csv_meta($path, self::MAX_PREVIEW_ROWS);
        $close_url = remove_query_arg('tmw_csv_preview');
        echo '<div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px;margin-bottom:20px;">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">';
        echo '<h2 style="margin:0;font-size:15px;">Preview: <code>' . esc_html($filename) . '</code></h2>';
        echo '<a href="' . esc_url($close_url) . '" class="button">Close Preview</a></div>';
        echo '<p><strong>File size:</strong> ' . esc_html(size_format((int)filesize($path))) . ' &nbsp; <strong>Rows:</strong> ' . (int)$meta['row_count'] . ($meta['count_truncated'] ? ' <em>(100k+ truncated)</em>' : '') . '</p>';
        if (!empty($meta['headers'])) { echo '<p><strong>Columns:</strong> <code>' . esc_html(implode(', ', $meta['headers'])) . '</code></p>'; }
        if (!empty($meta['preview_rows'])) {
            echo '<div style="overflow:auto;max-height:360px;"><table class="widefat striped" style="font-size:12px;"><thead><tr>';
            foreach ($meta['headers'] as $hdr) { echo '<th>' . esc_html($hdr) . '</th>'; }
            echo '</tr></thead><tbody>';
            foreach ($meta['preview_rows'] as $dr) { echo '<tr>'; foreach ($dr as $cell) { echo '<td>' . esc_html((string)$cell) . '</td>'; } echo '</tr>'; }
            echo '</tbody></table></div>';
        } else { echo '<p><em>No data rows found.</em></p>'; }
        echo '</div>';
    }

    private static function get_csv_dir(): string { return KeywordDataRepository::get_csv_dir(); }

    private static function get_csv_files(string $csv_dir): array {
        $files = glob($csv_dir . '*.csv') ?: [];
        $files = array_filter($files, fn($f) => is_string($f) && is_file($f));
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        return $files;
    }

    private static function get_validated_file_path(): ?string {
        $file = isset($_GET['file']) ? sanitize_file_name(rawurldecode((string)wp_unslash($_GET['file']))) : '';
        if ($file === '' || strtolower((string)pathinfo($file, PATHINFO_EXTENSION)) !== 'csv') { return null; }
        $base_dir = realpath(self::get_csv_dir());
        if ($base_dir === false) { return null; }
        $path = realpath(trailingslashit($base_dir) . $file);
        if ($path === false || strpos($path, trailingslashit($base_dir)) !== 0) { return null; }
        return $path;
    }
}
