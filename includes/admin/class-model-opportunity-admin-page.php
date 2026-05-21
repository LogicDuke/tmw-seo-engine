<?php
namespace TMWSEO\Engine\Admin;
use TMWSEO\Engine\Opportunities\ModelOpportunityNormalizer;
use TMWSEO\Engine\Opportunities\ModelOpportunityImportService;

if (!defined('ABSPATH')) { exit; }

class ModelOpportunityAdminPage {
    const PAGE_SLUG = 'tmwseo-model-opportunities';
    private const MODE_TO_SOURCE = [
        'kws_single_model_family' => 'kws_everywhere',
        'kws_bulk_discovery' => 'kws_everywhere',
        'kws_competitor_keywords' => 'kws_everywhere',
        'platform_model_list' => 'platform_csv',
    ];
    public static function init(): void {
        add_action('admin_post_tmw_model_opp_import', [__CLASS__, 'handle_import']);
    }
    public static function render_page(): void {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        echo '<div class="wrap"><h1>Model Opportunities</h1>';
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
        echo '</form></div>';
    }
    public static function handle_import(): void {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('tmw_model_opp_import','tmw_model_opp_nonce');
        global $wpdb;
        $mode = sanitize_key((string)($_POST['import_mode'] ?? ''));
        if (!isset(self::MODE_TO_SOURCE[$mode])) {
            wp_safe_redirect(admin_url('admin.php?page='.self::PAGE_SLUG.'&notice=invalid_mode'));
            exit;
        }
        if (
            empty($_FILES['opp_file']) ||
            !is_array($_FILES['opp_file']) ||
            (int)($_FILES['opp_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
            empty($_FILES['opp_file']['tmp_name']) ||
            !is_uploaded_file((string) $_FILES['opp_file']['tmp_name'])
        ) {
            wp_safe_redirect(admin_url('admin.php?page='.self::PAGE_SLUG.'&notice=bad_upload'));
            exit;
        }
        $file_name = sanitize_file_name((string)($_FILES['opp_file']['name'] ?? ''));
        $tmp_name = (string) $_FILES['opp_file']['tmp_name'];
        $ft = wp_check_filetype_and_ext($tmp_name, $file_name, ['csv' => 'text/csv']);
        if (($ft['ext'] ?? '') !== 'csv') {
            wp_safe_redirect(admin_url('admin.php?page='.self::PAGE_SLUG.'&notice=bad_filetype'));
            exit;
        }
        $model = sanitize_text_field((string)($_POST['model_entity'] ?? ''));
        $table = $wpdb->prefix.'tmwseo_model_opportunity_imports';
        $source = self::MODE_TO_SOURCE[$mode];
        $ok = $wpdb->insert($table, [
            'import_mode' => $mode,
            'source' => $source,
            'filename' => $file_name,
            'model_entity' => $model ?: null,
            'competitor_domain' => sanitize_text_field((string)($_POST['competitor_domain'] ?? '')) ?: null,
            'platform' => sanitize_text_field((string)($_POST['platform'] ?? '')) ?: null,
            'row_count' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        if ($ok === false) {
            error_log('[TMW-MODEL-OPP] import_failed mode='.$mode.' model='.ModelOpportunityNormalizer::normalize_model_name($model).' error='.$wpdb->last_error);
            wp_safe_redirect(admin_url('admin.php?page='.self::PAGE_SLUG.'&notice=import_failed'));
            exit;
        }
        $import_id = (int) $wpdb->insert_id;
        $preview = !empty($_POST['preview_only']);
        $summary = ModelOpportunityImportService::import($import_id, $mode, $tmp_name, [
            'model_entity' => $model,
            'competitor_domain' => sanitize_text_field((string)($_POST['competitor_domain'] ?? '')) ?: null,
            'platform' => sanitize_text_field((string)($_POST['platform'] ?? '')) ?: null,
            'source' => $source,
        ], $preview);
        $wpdb->update($table, [
            'row_count' => (int) ($summary['row_count'] ?? 0),
            'created_count' => (int) ($summary['created_count'] ?? 0),
            'updated_count' => (int) ($summary['updated_count'] ?? 0),
            'noise_count' => (int) ($summary['noise_count'] ?? 0),
            'options_json' => $preview ? wp_json_encode(['preview' => $summary['preview'] ?? []]) : null,
            'updated_at' => current_time('mysql'),
        ], ['id' => $import_id]);
        error_log('[TMW-MODEL-OPP] Import processed mode='.$mode.' model='.ModelOpportunityNormalizer::normalize_model_name($model).' rows='.(int)($summary['row_count'] ?? 0).' created='.(int)($summary['created_count'] ?? 0).' updated='.(int)($summary['updated_count'] ?? 0).' noise='.(int)($summary['noise_count'] ?? 0).' preview='.(int)$preview);
        wp_safe_redirect(admin_url('admin.php?page='.self::PAGE_SLUG.'&notice=' . ($preview ? 'previewed' : 'imported')));
        exit;
    }
}
