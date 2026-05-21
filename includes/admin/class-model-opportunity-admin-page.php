<?php
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Opportunities\ModelOpportunityImportService;
use TMWSEO\Engine\Opportunities\ModelOpportunityNormalizer;
use TMWSEO\Engine\Opportunities\ModelOpportunityRankMathPreview;

if (!defined('ABSPATH')) { exit; }

class ModelOpportunityAdminPage {
    const PAGE_SLUG = 'tmwseo-model-opportunities';
    private const ACTION = 'tmw_model_opp_action';
    private const MODE_TO_SOURCE = [
        'kws_single_model_family' => 'kws_everywhere',
        'kws_bulk_discovery' => 'kws_everywhere',
        'kws_competitor_keywords' => 'kws_everywhere',
        'platform_model_list' => 'platform_csv',
    ];
    private const ALLOWED_PRIORITIES = ['P1', 'P2', 'P3', 'archive'];
    private const ALLOWED_TYPES = [
        'existing_model_optimization', 'missing_model_acquisition', 'competitor_gap', 'platform_coverage',
        'generic_keyword_candidate', 'noise_archive',
    ];

    public static function init(): void {
        add_action('admin_post_tmw_model_opp_import', [__CLASS__, 'handle_import']);
        add_action('admin_post_' . self::ACTION, [__CLASS__, 'handle_row_action']);
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        $tab = self::sanitize_tab((string) ($_GET['tab'] ?? 'import'));

        echo '<div class="wrap"><h1>Model Opportunities</h1>';
        self::render_notice();
        echo '<h2 class="nav-tab-wrapper">';
        foreach (['import' => 'Import', 'opportunities' => 'Opportunities', 'detail' => 'Opportunity Detail'] as $k => $label) {
            $active = $tab === $k ? ' nav-tab-active' : '';
            $url = add_query_arg(['page' => self::PAGE_SLUG, 'tab' => $k], admin_url('admin.php'));
            if ($k === 'detail' && empty($_GET['id'])) { $url = add_query_arg(['page' => self::PAGE_SLUG, 'tab' => 'opportunities'], admin_url('admin.php')); }
            echo '<a class="nav-tab' . esc_attr($active) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';

        if ($tab === 'opportunities') self::render_opportunities_tab();
        elseif ($tab === 'detail') self::render_detail_tab();
        else self::render_import_tab();
        echo '</div>';
    }

    private static function render_import_tab(): void {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data">';
        wp_nonce_field('tmw_model_opp_import','tmw_model_opp_nonce');
        echo '<input type="hidden" name="action" value="tmw_model_opp_import" />';
        echo '<p><select name="import_mode"><option value="kws_single_model_family">KWS Single Model Family</option><option value="kws_bulk_discovery">KWS Bulk Discovery</option><option value="kws_competitor_keywords">KWS Competitor Keywords</option><option value="platform_model_list">Platform Model List</option></select></p>';
        echo '<p><input name="model_entity" placeholder="Model name (single mode)" /></p>';
        echo '<p><input name="competitor_domain" placeholder="Competitor domain" /></p>';
        echo '<p><input name="platform" placeholder="Platform key" /></p>';
        echo '<p><label><input type="checkbox" name="preview_only" value="1" /> Preview only</label></p>';
        echo '<p><input type="file" name="opp_file" accept=".csv,text/csv" required /></p>';
        submit_button('Import');
        echo '</form>';

        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_model_opportunity_imports';
        $rows = (array) $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 10", ARRAY_A);
        echo '<h2>Recent Imports</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Mode</th><th>Source</th><th>Filename</th><th>Model Entity</th><th>Competitor Domain</th><th>Platform</th><th>Row Count</th><th>Created</th><th>Updated</th><th>Noise</th><th>Created At</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . (int) $row['id'] . '</td><td>' . esc_html((string) $row['import_mode']) . '</td><td>' . esc_html((string) $row['source']) . '</td><td>' . esc_html((string) $row['filename']) . '</td><td>' . esc_html((string) $row['model_entity']) . '</td><td>' . esc_html((string) $row['competitor_domain']) . '</td><td>' . esc_html((string) $row['platform']) . '</td><td>' . (int) $row['row_count'] . '</td><td>' . (int) $row['created_count'] . '</td><td>' . (int) $row['updated_count'] . '</td><td>' . (int) $row['noise_count'] . '</td><td>' . esc_html((string) $row['created_at']) . '</td></tr>';
            self::render_preview_summary((string) ($row['options_json'] ?? ''));
        }
        echo '</tbody></table>';
    }

    private static function render_preview_summary(string $options_json): void {
        $decoded = json_decode($options_json, true);
        if (!is_array($decoded) || empty($decoded['preview']) || !is_array($decoded['preview'])) return;
        $preview = array_slice($decoded['preview'], 0, 50);
        echo '<tr><td colspan="12"><details><summary>Preview rows (' . count($preview) . ' shown)</summary><ol>';
        foreach ($preview as $item) {
            echo '<li><code>' . esc_html(wp_json_encode($item)) . '</code></li>';
        }
        echo '</ol></details></td></tr>';
    }

    private static function render_opportunities_tab(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_model_opportunities';
        $filters = self::read_filters();
        $where = [];
        $args = [];

        if ($filters['status'] !== '') { $where[] = 'status = %s'; $args[] = $filters['status']; }
        if ($filters['priority'] !== '') { $where[] = 'priority = %s'; $args[] = $filters['priority']; }
        if ($filters['opportunity_type'] !== '') { $where[] = 'opportunity_type = %s'; $args[] = $filters['opportunity_type']; }
        if ($filters['needs_dfseo_verification'] !== '') { $where[] = 'needs_dfseo_verification = %d'; $args[] = (int) $filters['needs_dfseo_verification']; }
        if ($filters['match_state'] === 'matched') { $where[] = 'matched_post_id IS NOT NULL AND matched_post_id > 0'; }
        if ($filters['match_state'] === 'missing') { $where[] = '(matched_post_id IS NULL OR matched_post_id = 0)'; }
        if ($filters['s'] !== '') {
            $where[] = '(model_entity LIKE %s OR primary_keyword LIKE %s)';
            $term = '%' . $wpdb->esc_like($filters['s']) . '%';
            $args[] = $term; $args[] = $term;
        }
        $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $sortable = ['score', 'family_volume', 'primary_volume', 'traffic_value', 'updated_at'];
        $orderby = sanitize_key((string) ($_GET['orderby'] ?? 'score'));
        if (!in_array($orderby, $sortable, true)) { $orderby = 'score'; }
        $order = strtoupper(sanitize_key((string) ($_GET['order'] ?? 'DESC')));
        if (!in_array($order, ['ASC', 'DESC'], true)) { $order = 'DESC'; }

        $sql = "SELECT * FROM {$table}{$where_sql} ORDER BY {$orderby} {$order} LIMIT 250";
        $rows = (array) ($args ? $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A));

        self::render_filter_form($filters);
        echo '<table class="widefat striped"><thead><tr><th>Priority</th><th>Score</th><th>Model / Entity</th><th>Opportunity Type</th><th>Status</th><th>Primary Keyword</th><th>Primary Volume</th><th>Family Volume</th><th>Traffic Value</th><th>Matched Post</th><th>Platform Signals</th><th>Competitor Sources</th><th>Needs DataForSEO</th><th>Updated At</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            echo '<tr><td>' . esc_html((string) $row['priority']) . '</td><td>' . esc_html((string) $row['score']) . '</td><td>' . esc_html((string) $row['model_entity']) . '</td><td>' . esc_html((string) $row['opportunity_type']) . '</td><td>' . esc_html((string) $row['status']) . '</td><td>' . esc_html((string) $row['primary_keyword']) . '</td><td>' . (int) $row['primary_volume'] . '</td><td>' . (int) $row['family_volume'] . '</td><td>' . esc_html((string) $row['traffic_value']) . '</td><td>' . (int) $row['matched_post_id'] . '</td><td><code>' . esc_html((string) $row['platform_signals_json']) . '</code></td><td><code>' . esc_html((string) $row['competitor_sources_json']) . '</code></td><td>' . ((int)$row['needs_dfseo_verification'] ? 'Yes' : 'No') . '</td><td>' . esc_html((string) $row['updated_at']) . '</td><td>' . self::render_row_actions($id, $filters) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_row_actions(int $id, array $filters): string {
        $actions = [
            'mark_p1' => 'Mark P1', 'mark_p2' => 'Mark P2', 'mark_p3' => 'Mark P3', 'archive' => 'Archive',
            'restore' => 'Restore to pending_review', 'set_dfseo' => 'Mark needs DataForSEO verification',
            'clear_dfseo' => 'Clear DataForSEO verification flag',
        ];
        $parts = [];
        foreach ($actions as $action => $label) {
            $url = wp_nonce_url(add_query_arg(array_merge($filters, ['action' => self::ACTION, 'opp_action' => $action, 'id' => $id]), admin_url('admin-post.php')), self::ACTION . '_' . $id);
            $parts[] = '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        $detail = add_query_arg(['page' => self::PAGE_SLUG, 'tab' => 'detail', 'id' => $id], admin_url('admin.php'));
        $preview = $detail . '#rank-math-preview';
        $parts[] = '<a href="' . esc_url($detail) . '">View Details</a>';
        $parts[] = '<a href="' . esc_url($preview) . '">Preview Rank Math</a>';
        return implode(' | ', $parts);
    }

    private static function render_detail_tab(): void {
        global $wpdb;
        $id = absint((int) ($_GET['id'] ?? 0));
        if ($id <= 0) { echo '<p>Invalid opportunity ID.</p>'; return; }
        $opp_table = $wpdb->prefix . 'tmwseo_model_opportunities';
        $kw_table = $wpdb->prefix . 'tmwseo_model_opportunity_keywords';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$opp_table} WHERE id=%d", $id), ARRAY_A);
        if (!$row) { echo '<p>Opportunity not found.</p>'; return; }
        echo '<h2>' . esc_html((string) $row['model_entity']) . '</h2><table class="widefat striped">';
        foreach ($row as $k => $v) { echo '<tr><th>' . esc_html((string) $k) . '</th><td>' . esc_html((string) $v) . '</td></tr>'; }
        echo '</table>';
        if ((int) ($row['matched_post_id'] ?? 0) > 0) {
            $post_id = (int) $row['matched_post_id'];
            echo '<p><a class="button" target="_blank" href="' . esc_url(get_edit_post_link($post_id, '')) . '">Open model edit page</a> ';
            $link = get_permalink($post_id);
            if ($link) echo '<a class="button" target="_blank" href="' . esc_url($link) . '">View model page</a>';
            echo '</p>';
        } else {
            echo '<p><strong>Missing model opportunity</strong> — preview only. Create model manually before applying Rank Math metadata.</p>';
        }

        $preview = ModelOpportunityRankMathPreview::build($id);
        echo '<h3 id="rank-math-preview">Rank Math Preview</h3>';
        echo '<p><strong>Preview only. This PR does not write Rank Math metadata.</strong></p>';
        if ((int) ($row['matched_post_id'] ?? 0) > 0) {
            $current_focus = (string) get_post_meta((int) $row['matched_post_id'], 'rank_math_focus_keyword', true);
            echo '<p><strong>Current Rank Math focus keyword:</strong> ' . esc_html($current_focus !== '' ? $current_focus : '(empty)') . '</p>';
            echo '<p><strong>Suggested Rank Math focus keyword:</strong> ' . esc_html((string) ($preview['focus_keyword'] ?? '')) . '</p>';
        } else {
            echo '<p><strong>Suggested Rank Math focus keyword:</strong> ' . esc_html((string) ($preview['focus_keyword'] ?? '')) . '</p>';
            echo '<p><em>Missing model opportunity — preview only. Create model manually before applying Rank Math metadata.</em></p>';
        }
        echo '<p><strong>Suggested supporting keywords:</strong><br>' . esc_html(implode(', ', (array) ($preview['supporting_keywords'] ?? []))) . '</p>';
        echo '<p><strong>Excluded risky keywords:</strong><br>' . esc_html(implode(', ', (array) ($preview['excluded_risky'] ?? []))) . '</p>';
        echo '<p><strong>Excluded noise keywords:</strong><br>' . esc_html(implode(', ', (array) ($preview['excluded_noise'] ?? []))) . '</p>';
        echo '<p><strong>Source explanation:</strong> ' . esc_html((string) ($preview['source_note'] ?? '')) . '</p>';

        $keywords = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$kw_table} WHERE opportunity_id=%d ORDER BY volume DESC", $id), ARRAY_A);
        $role_counts = [];
        foreach ($keywords as $kw) { $role = (string) ($kw['role'] ?? 'unknown'); $role_counts[$role] = (int) ($role_counts[$role] ?? 0) + 1; }
        echo '<h3>Role counts</h3><ul>'; foreach ($role_counts as $role => $count) { echo '<li>' . esc_html($role) . ': ' . (int) $count . '</li>'; } echo '</ul>';

        $groups = ['rankmath_candidate', 'platform_intent', 'risky_explicit', 'content_support', 'typo', 'noise', 'manual_review'];
        foreach ($groups as $group) {
            echo '<h3>' . esc_html($group) . '</h3><ul>';
            foreach ($keywords as $kw) {
                if ((string) ($kw['role'] ?? '') !== $group) continue;
                echo '<li>' . esc_html((string) $kw['keyword']) . ' (' . (int) ($kw['volume'] ?? 0) . ')</li>';
            }
            echo '</ul>';
        }

        echo '<h3>Keyword variants</h3><table class="widefat striped"><thead><tr><th>Keyword</th><th>Role</th><th>Volume</th><th>Source</th><th>Competitor Domain</th><th>Platform</th><th>Raw Row</th></tr></thead><tbody>';
        foreach ($keywords as $kw) {
            echo '<tr><td>' . esc_html((string) $kw['keyword']) . '</td><td>' . esc_html((string) $kw['role']) . '</td><td>' . (int) $kw['volume'] . '</td><td>' . esc_html((string) $kw['source']) . '</td><td>' . esc_html((string) $kw['competitor_domain']) . '</td><td>' . esc_html((string) $kw['platform_detected']) . '</td><td><details><summary>View</summary><code>' . esc_html((string) $kw['raw_row_json']) . '</code></details></td></tr>';
        }
        echo '</tbody></table>';
    }

    public static function handle_row_action(): void {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        $id = absint((int) ($_GET['id'] ?? 0));
        check_admin_referer(self::ACTION . '_' . $id);
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_model_opportunities';
        $action = sanitize_key((string) ($_GET['opp_action'] ?? ''));
        $changes = [];
        if ($action === 'mark_p1') { $changes = ['priority' => 'P1', 'status' => 'pending_review']; }
        elseif ($action === 'mark_p2') { $changes = ['priority' => 'P2', 'status' => 'pending_review']; }
        elseif ($action === 'mark_p3') { $changes = ['priority' => 'P3', 'status' => 'pending_review']; }
        elseif ($action === 'archive') { $changes = ['priority' => 'archive', 'status' => 'archived']; }
        elseif ($action === 'restore') { $changes = ['priority' => null, 'status' => 'pending_review']; }
        elseif ($action === 'set_dfseo') { $changes = ['needs_dfseo_verification' => 1]; }
        elseif ($action === 'clear_dfseo') { $changes = ['needs_dfseo_verification' => 0]; }
        if (!empty($changes)) {
            $changes['updated_at'] = current_time('mysql');
            $wpdb->update($table, $changes, ['id' => $id]);
            error_log('[TMW-MODEL-OPP] action=' . $action . ' id=' . $id);
        }
        $redirect = add_query_arg(self::read_filters(), admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=opportunities'));
        wp_safe_redirect($redirect);
        exit;
    }

    private static function render_filter_form(array $filters): void {
        echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '"><input type="hidden" name="tab" value="opportunities">';
        echo 'Status <input name="status" value="' . esc_attr($filters['status']) . '"> ';
        echo 'Priority <select name="priority"><option value="">All</option><option value="P1">P1</option><option value="P2">P2</option><option value="P3">P3</option><option value="archive">archive</option></select> ';
        echo 'Type <input name="opportunity_type" value="' . esc_attr($filters['opportunity_type']) . '"> ';
        echo 'Needs DFSEO <select name="needs_dfseo_verification"><option value="">All</option><option value="1">Yes</option><option value="0">No</option></select> ';
        echo 'Matched <select name="match_state"><option value="">All</option><option value="matched">Matched</option><option value="missing">Missing</option></select> ';
        echo 'Search <input name="s" value="' . esc_attr($filters['s']) . '"> ';
        submit_button('Filter', 'secondary', '', false);
        echo '</form>';
    }

    private static function read_filters(): array {
        $priority = sanitize_text_field((string) ($_GET['priority'] ?? ''));
        if (in_array(strtolower($priority), ['p1', 'p2', 'p3'], true)) { $priority = strtoupper($priority); }
        $type = sanitize_key((string) ($_GET['opportunity_type'] ?? ''));
        return [
            'tab' => 'opportunities',
            'status' => sanitize_key((string) ($_GET['status'] ?? '')),
            'priority' => in_array($priority, self::ALLOWED_PRIORITIES, true) ? $priority : '',
            'opportunity_type' => in_array($type, self::ALLOWED_TYPES, true) ? $type : '',
            'needs_dfseo_verification' => in_array((string) ($_GET['needs_dfseo_verification'] ?? ''), ['0', '1'], true) ? (string) $_GET['needs_dfseo_verification'] : '',
            'match_state' => in_array((string) ($_GET['match_state'] ?? ''), ['matched', 'missing'], true) ? (string) $_GET['match_state'] : '',
            's' => sanitize_text_field((string) ($_GET['s'] ?? '')),
        ];
    }

    private static function sanitize_tab(string $tab): string {
        return in_array($tab, ['import', 'opportunities', 'detail'], true) ? $tab : 'import';
    }

    private static function render_notice(): void {
        $notice = sanitize_key((string) ($_GET['notice'] ?? ''));
        if ($notice === '') return;
        echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>';
    }

    public static function handle_import(): void {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('tmw_model_opp_import','tmw_model_opp_nonce');
        global $wpdb;
        $mode = sanitize_key((string)($_POST['import_mode'] ?? ''));
        if (!isset(self::MODE_TO_SOURCE[$mode])) {
            wp_safe_redirect(admin_url('admin.php?page='.self::PAGE_SLUG.'&tab=import&notice=invalid_mode')); exit;
        }
        if (empty($_FILES['opp_file']) || !is_array($_FILES['opp_file']) || (int)($_FILES['opp_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($_FILES['opp_file']['tmp_name']) || !is_uploaded_file((string) $_FILES['opp_file']['tmp_name'])) {
            wp_safe_redirect(admin_url('admin.php?page='.self::PAGE_SLUG.'&tab=import&notice=bad_upload')); exit;
        }
        $file_name = sanitize_file_name((string)($_FILES['opp_file']['name'] ?? ''));
        $tmp_name = (string) $_FILES['opp_file']['tmp_name'];
        $ft = wp_check_filetype_and_ext($tmp_name, $file_name, ['csv' => 'text/csv']);
        if (($ft['ext'] ?? '') !== 'csv') { wp_safe_redirect(admin_url('admin.php?page='.self::PAGE_SLUG.'&tab=import&notice=bad_filetype')); exit; }
        $model = sanitize_text_field((string)($_POST['model_entity'] ?? ''));
        $table = $wpdb->prefix.'tmwseo_model_opportunity_imports';
        $source = self::MODE_TO_SOURCE[$mode];
        $ok = $wpdb->insert($table, ['import_mode' => $mode,'source' => $source,'filename' => $file_name,'model_entity' => $model ?: null,'competitor_domain' => sanitize_text_field((string)($_POST['competitor_domain'] ?? '')) ?: null,'platform' => sanitize_text_field((string)($_POST['platform'] ?? '')) ?: null,'row_count' => 0,'created_at' => current_time('mysql'),'updated_at' => current_time('mysql')]);
        if ($ok === false) { error_log('[TMW-MODEL-OPP] import_failed mode='.$mode.' model='.ModelOpportunityNormalizer::normalize_model_name($model).' error='.$wpdb->last_error); wp_safe_redirect(admin_url('admin.php?page='.self::PAGE_SLUG.'&tab=import&notice=import_failed')); exit; }
        $import_id = (int) $wpdb->insert_id;
        $preview = !empty($_POST['preview_only']);
        $summary = ModelOpportunityImportService::import($import_id, $mode, $tmp_name, ['model_entity' => $model,'competitor_domain' => sanitize_text_field((string)($_POST['competitor_domain'] ?? '')) ?: null,'platform' => sanitize_text_field((string)($_POST['platform'] ?? '')) ?: null,'source' => $source,], $preview);
        $wpdb->update($table, ['row_count' => (int) ($summary['row_count'] ?? 0),'created_count' => (int) ($summary['created_count'] ?? 0),'updated_count' => (int) ($summary['updated_count'] ?? 0),'noise_count' => (int) ($summary['noise_count'] ?? 0),'options_json' => $preview ? wp_json_encode(['preview' => $summary['preview'] ?? []]) : null,'updated_at' => current_time('mysql'),], ['id' => $import_id]);
        error_log('[TMW-MODEL-OPP] Import processed mode='.$mode.' model='.ModelOpportunityNormalizer::normalize_model_name($model).' rows='.(int)($summary['row_count'] ?? 0).' created='.(int)($summary['created_count'] ?? 0).' updated='.(int)($summary['updated_count'] ?? 0).' noise='.(int)($summary['noise_count'] ?? 0).' preview='.(int)$preview);
        wp_safe_redirect(admin_url('admin.php?page='.self::PAGE_SLUG.'&tab=import&notice=' . ($preview ? 'previewed' : 'imported'))); exit;
    }
}
