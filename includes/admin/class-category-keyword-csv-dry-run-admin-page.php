<?php
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Categories\CategoryKeywordClassifier;

if (!defined('ABSPATH')) { exit; }

class CategoryKeywordCsvDryRunAdminPage {
    private const PAGE_SLUG = 'tmwseo-category-keyword-csv-dry-run';
    private const ROW_CAP = 2000;

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_init', [__CLASS__, 'maybe_handle_csv_download']);
    }


    public static function maybe_handle_csv_download(): void {
        if (!is_admin() || !current_user_can('manage_options')) { return; }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['tmwseo_cat_dry_run_download'])) { return; }

        $page = isset($_REQUEST['page']) ? sanitize_key(wp_unslash((string) $_REQUEST['page'])) : '';
        if ($page !== self::PAGE_SLUG) { return; }

        check_admin_referer('tmwseo_cat_keyword_csv_dry_run_nonce');

        $csvInput = isset($_POST['tmwseo_csv_payload']) ? wp_unslash((string) $_POST['tmwseo_csv_payload']) : '';
        if (trim($csvInput) === '') {
            add_settings_error('tmwseo_cat_dry_run', 'tmwseo_cat_dry_run_missing_csv', __('Download request is missing CSV input. Please run a new dry-run classification.', 'tmwseo'), 'error');
            return;
        }

        $parsed = self::parse_csv($csvInput);
        if (!empty($parsed['notices'])) {
            foreach ($parsed['notices'] as $notice) {
                add_settings_error('tmwseo_cat_dry_run', 'tmwseo_cat_dry_run_parse_' . wp_generate_uuid4(), (string) ($notice['text'] ?? ''), ($notice['type'] ?? '') === 'notice-warning' ? 'warning' : 'error');
            }
        }

        if (empty($parsed['rows'])) {
            add_settings_error('tmwseo_cat_dry_run', 'tmwseo_cat_dry_run_empty_rows', __('No classified rows were available to export.', 'tmwseo'), 'error');
            return;
        }

        $classifier = new CategoryKeywordClassifier();
        $results = $classifier->classify_rows($parsed['rows']);
        if (empty($results)) {
            add_settings_error('tmwseo_cat_dry_run', 'tmwseo_cat_dry_run_empty_results', __('No classified rows were available to export.', 'tmwseo'), 'error');
            return;
        }

        self::stream_classification_csv($results);
    }

    public static function register_menu(): void {
        add_submenu_page(
            'tmwseo-engine',
            __('Category Keyword CSV Dry Run', 'tmwseo'),
            __('Category Keyword CSV Dry Run', 'tmwseo'),
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) { return; }

        $notices = [];
        $results = [];
        $summary = [];
        $csvInput = '';
        $downloadReady = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tmwseo_cat_dry_run_submit'])) {
            check_admin_referer('tmwseo_cat_keyword_csv_dry_run_nonce');

            $uploadCsv = self::read_uploaded_csv();
            $pastedCsv = isset($_POST['tmwseo_csv_text']) ? wp_unslash((string) $_POST['tmwseo_csv_text']) : '';

            if ($uploadCsv !== '') {
                if (trim($pastedCsv) !== '') {
                    $notices[] = ['type' => 'notice-warning', 'text' => 'Both upload and pasted CSV were provided; uploaded file was used.'];
                }
                $csvInput = $uploadCsv;
            } else {
                $csvInput = $pastedCsv;
            }

            if (trim($csvInput) === '') {
                $notices[] = ['type' => 'notice-error', 'text' => 'Please upload a CSV file or paste CSV text.'];
            } else {
                $parsed = self::parse_csv($csvInput);
                $notices = array_merge($notices, $parsed['notices']);
                if (!empty($parsed['rows'])) {
                    $classifier = new CategoryKeywordClassifier();
                    $results = $classifier->classify_rows($parsed['rows']);
                    $summary = self::build_summary($results);
                    $downloadReady = true;
                }
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Category Keyword CSV Dry Run', 'tmwseo') . '</h1>';
        echo '<p><strong>' . esc_html__('This is a dry-run classifier only. It does not create categories, does not import keywords, does not update posts, does not write Rank Math fields, and does not affect the Generate button.', 'tmwseo') . '</strong></p>';

        foreach ($notices as $notice) {
            echo '<div class="notice ' . esc_attr($notice['type']) . ' is-dismissible"><p>' . esc_html($notice['text']) . '</p></div>';
        }

        settings_errors('tmwseo_cat_dry_run');

        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('tmwseo_cat_keyword_csv_dry_run_nonce');
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="tmwseo_csv_file">' . esc_html__('Upload CSV File', 'tmwseo') . '</label></th><td><input type="file" id="tmwseo_csv_file" name="tmwseo_csv_file" accept=".csv,text/csv"></td></tr>';
        echo '<tr><th scope="row"><label for="tmwseo_csv_text">' . esc_html__('Paste CSV Text', 'tmwseo') . '</label></th><td><textarea id="tmwseo_csv_text" name="tmwseo_csv_text" rows="10" cols="120" class="large-text code" placeholder="Keyword,Volume,CPC,Competition,SEO Score,Trend">' . esc_textarea($csvInput) . '</textarea></td></tr>';
        echo '</table>';
        submit_button(__('Run Dry-Run Classification', 'tmwseo'), 'primary', 'tmwseo_cat_dry_run_submit');
        echo '</form>';

        if (!empty($summary)) {
            echo '<h2>' . esc_html__('Summary', 'tmwseo') . '</h2><ul>';
            foreach ($summary as $label => $count) {
                echo '<li><strong>' . esc_html($label) . ':</strong> ' . esc_html((string) $count) . '</li>';
            }
            echo '</ul>';
        }

        if (!empty($results)) {
            echo '<h2>' . esc_html__('Classification Preview', 'tmwseo') . '</h2>';
            echo '<div style="overflow:auto;max-width:100%;">';
            echo '<table class="widefat striped"><thead><tr>';
            $headers = ['Keyword','Volume','CPC','Competition','SEO Score','Trend','Decision','Risk Level','Recommended Page Type','Generator Safe','Public Category Candidate','SEO Research Candidate','Review Required','Blocked','Reasons'];
            foreach ($headers as $h) { echo '<th>' . esc_html($h) . '</th>'; }
            echo '</tr></thead><tbody>';
            foreach ($results as $row) {
                echo '<tr>';
                echo '<td>' . esc_html((string) ($row['keyword'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['volume'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['cpc'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['competition'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['seo_score'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['trend'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['decision'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['risk_level'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['recommended_page_type'] ?? '')) . '</td>';
                echo '<td>' . esc_html(!empty($row['generator_safe']) ? 'yes' : 'no') . '</td>';
                echo '<td>' . esc_html(!empty($row['public_category_candidate']) ? 'yes' : 'no') . '</td>';
                echo '<td>' . esc_html(!empty($row['seo_research_candidate']) ? 'yes' : 'no') . '</td>';
                echo '<td>' . esc_html(!empty($row['review_required']) ? 'yes' : 'no') . '</td>';
                echo '<td>' . esc_html(!empty($row['blocked']) ? 'yes' : 'no') . '</td>';
                $reasons = isset($row['reasons']) && is_array($row['reasons']) ? implode(' | ', $row['reasons']) : '';
                echo '<td>' . esc_html($reasons) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';

            if ($downloadReady && trim($csvInput) !== '') {
                echo '<h2>' . esc_html__('Export', 'tmwseo') . '</h2>';
                echo '<form method="post">';
                wp_nonce_field('tmwseo_cat_keyword_csv_dry_run_nonce');
                echo '<textarea name="tmwseo_csv_payload" style="display:none;">' . esc_textarea($csvInput) . '</textarea>';
                submit_button(__('Download Classified CSV', 'tmwseo'), 'secondary', 'tmwseo_cat_dry_run_download', false);
                echo '<p class="description">' . esc_html__('Download is generated from the current dry-run only. No data is saved or imported.', 'tmwseo') . '</p>';
                echo '</form>';
            }
        }

        echo '</div>';
    }

    private static function read_uploaded_csv(): string {
        if (empty($_FILES['tmwseo_csv_file']) || !is_array($_FILES['tmwseo_csv_file'])) { return ''; }
        $file = $_FILES['tmwseo_csv_file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { return ''; }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) { return ''; }
        $contents = file_get_contents($tmp);
        return is_string($contents) ? $contents : '';
    }

    private static function parse_csv(string $csv): array {
        $rows = [];
        $notices = [];
        $h = fopen('php://temp', 'r+');
        if ($h === false) {
            error_log('[TMW-CAT] Unable to open temporary stream for CSV parsing.');
            return ['rows' => [], 'notices' => [['type' => 'notice-error', 'text' => 'Unable to parse CSV input.']]];
        }
        fwrite($h, $csv);
        rewind($h);

        $headers = fgetcsv($h);
        if (!is_array($headers) || empty($headers)) {
            fclose($h);
            return ['rows' => [], 'notices' => [['type' => 'notice-error', 'text' => 'CSV header row is missing or invalid.']]];
        }

        $headers = array_map(static function ($v) { return trim((string) $v); }, $headers);
        $count = 0;
        while (($data = fgetcsv($h)) !== false) {
            if ($count >= self::ROW_CAP) { break; }
            if (!is_array($data)) { continue; }
            $data = array_pad($data, count($headers), '');
            $row = array_combine($headers, array_map(static function ($v) { return sanitize_text_field((string) $v); }, $data));
            if (!is_array($row)) { continue; }
            $rows[] = $row;
            $count++;
        }
        fclose($h);

        if ($count >= self::ROW_CAP) {
            $notices[] = ['type' => 'notice-warning', 'text' => 'Row limit reached. Only the first 2,000 rows were processed.'];
        }

        return ['rows' => $rows, 'notices' => $notices];
    }

    private static function build_summary(array $results): array {
        $summary = [
            'Total rows processed' => count($results),
            'Generator-safe candidates' => 0,
            'Public category candidates' => 0,
            'SEO research only' => 0,
            'Review required' => 0,
            'Blocked' => 0,
            'Ignored' => 0,
            'Platform candidates' => 0,
            'Adult-intent review' => 0,
            'Modifier review' => 0,
        ];

        foreach ($results as $row) {
            if (!empty($row['generator_safe'])) { $summary['Generator-safe candidates']++; }
            if (!empty($row['public_category_candidate'])) { $summary['Public category candidates']++; }
            if (($row['decision'] ?? '') === 'seo_research_only') { $summary['SEO research only']++; }
            if (!empty($row['review_required'])) { $summary['Review required']++; }
            if (!empty($row['blocked'])) { $summary['Blocked']++; }
            if (($row['decision'] ?? '') === 'ignore') { $summary['Ignored']++; }
            if (($row['recommended_page_type'] ?? '') === 'platform_category') { $summary['Platform candidates']++; }
            $reasons = strtolower(implode(' ', is_array($row['reasons'] ?? null) ? $row['reasons'] : []));
            if (str_contains($reasons, 'adult/explicit intent')) { $summary['Adult-intent review']++; }
            if (str_contains($reasons, 'sensitive modifier')) { $summary['Modifier review']++; }
        }

        return $summary;
    }

    private static function stream_classification_csv(array $results): void {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tmw-category-keyword-classification-' . gmdate('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w');
        if ($out === false) { exit; }

        $headers = [
            'Original Keyword',
            'Normalized Keyword',
            'Volume',
            'CPC',
            'Competition',
            'SEO Score',
            'Trend',
            'Decision',
            'Risk Level',
            'Recommended Page Type',
            'Generator Safe',
            'Public Category Candidate',
            'SEO Research Candidate',
            'Review Required',
            'Blocked',
            'Matched Registry Keys',
            'Matched Families',
            'Reasons',
            'Platform Candidate',
            'Adult Intent Review',
            'Modifier Review',
        ];
        fputcsv($out, $headers);

        foreach ($results as $row) {
            $reasons = is_array($row['reasons'] ?? null) ? $row['reasons'] : [];
            $reasonsText = implode(' | ', $reasons);
            $reasonsJoined = strtolower(implode(' ', $reasons));
            fputcsv($out, [
                (string) ($row['keyword'] ?? ''),
                (string) ($row['normalized_keyword'] ?? ''),
                (string) ($row['volume'] ?? ''),
                (string) ($row['cpc'] ?? ''),
                (string) ($row['competition'] ?? ''),
                (string) ($row['seo_score'] ?? ''),
                (string) ($row['trend'] ?? ''),
                (string) ($row['decision'] ?? ''),
                (string) ($row['risk_level'] ?? ''),
                (string) ($row['recommended_page_type'] ?? ''),
                !empty($row['generator_safe']) ? 'yes' : 'no',
                !empty($row['public_category_candidate']) ? 'yes' : 'no',
                !empty($row['seo_research_candidate']) ? 'yes' : 'no',
                !empty($row['review_required']) ? 'yes' : 'no',
                !empty($row['blocked']) ? 'yes' : 'no',
                implode(' | ', is_array($row['matched_registry_keys'] ?? null) ? $row['matched_registry_keys'] : []),
                implode(' | ', is_array($row['matched_families'] ?? null) ? $row['matched_families'] : []),
                $reasonsText,
                (($row['recommended_page_type'] ?? '') === 'platform_category') ? 'yes' : 'no',
                str_contains($reasonsJoined, 'adult/explicit intent') ? 'yes' : 'no',
                str_contains($reasonsJoined, 'sensitive modifier') ? 'yes' : 'no',
            ]);
        }

        fclose($out);
        exit;
    }
}
