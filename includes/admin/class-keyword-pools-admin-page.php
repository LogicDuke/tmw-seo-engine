<?php
/**
 * Keyword Pools admin dry-run preview screen.
 *
 * @package TMWSEO\Engine\Admin
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Keywords\KeywordPoolCsvParser;
use TMWSEO\Engine\Keywords\KeywordPoolDryRunService;

if (!defined('ABSPATH')) { exit; }

/**
 * Renders preview-only CSV dry runs for model, video, and category keyword pools.
 */
class KeywordPoolsAdminPage {

    public const PAGE_SLUG = 'tmwseo-keyword-pools';
    public const CAPABILITY = 'manage_options';
    public const NONCE_ACTION = 'tmwseo_keyword_pools_dry_run';
    public const NONCE_FIELD = 'tmwseo_keyword_pools_nonce';
    public const EXPORT_ACTION = 'tmwseo_keyword_pools_export_preview';

    /** @var array<int, string> */
    private const ALLOWED_POOLS = [ 'model', 'video', 'category' ];

    /** @var array<string, string> */
    private const POOL_LABELS = [
        'model'    => 'Model Pool',
        'video'    => 'Video Pool',
        'category' => 'Category Pool',
    ];

    /** @var array<string, string> */
    private const POOL_HELP = [
        'model'    => 'Accepts model/profile/entity intent for performer profile and entity-led keyword review.',
        'video'    => 'Accepts video/session/clip intent and rejects standalone model names without video intent.',
        'category' => 'Accepts archive/topic/browse/category intent for public category and browse pages.',
    ];

    /**
     * Register POST-only handlers that do not persist keyword rows.
     */
    public static function init(): void {
        add_action('admin_post_' . self::EXPORT_ACTION, [__CLASS__, 'handle_export']);
    }

    public static function slug(): string {
        return self::PAGE_SLUG;
    }

    public static function capability(): string {
        return self::CAPABILITY;
    }

    /**
     * @return array<int, string>
     */
    public static function allowed_pools(): array {
        return self::ALLOWED_POOLS;
    }

    /**
     * @return array<int, string>
     */
    public static function preview_columns(): array {
        return [
            'Row',
            'Keyword',
            'Normalized Keyword',
            'Status Preview',
            'Validation State',
            'Decision',
            'Priority',
            'Golden?',
            'Recommended Action',
            'Reasons',
            'Volume',
            'Difficulty',
            'CPC',
            'Competition',
            'Intent',
            'Source',
            'Model',
            'Category',
            'Post ID',
            'URL',
            'Slug',
            'Title',
            'Duplicate',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function export_headers(): array {
        return array_merge(self::preview_columns(), [ 'Reason Codes' ]);
    }

    public static function render(): void {
        self::render_page();
    }

    public static function render_page(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html(__('Unauthorized', 'tmwseo')));
        }

        $active_pool = self::sanitize_pool((string) ($_GET['pool'] ?? $_POST['tmwseo_keyword_pool'] ?? 'model'));
        $state       = self::maybe_process_dry_run($active_pool);

        echo '<div class="wrap tmwseo-keyword-pools-page">';
        echo '<h1>' . esc_html(__('Keyword Pools', 'tmwseo')) . '</h1>';
        self::render_intro_notice();
        self::render_notices($state['notices']);
        self::render_tabs($active_pool);

        if ('metrics' === $active_pool) {
            self::render_metrics_tab();
        } else {
            self::render_pool_tab($active_pool, (string) $state['csv_text']);
            if (is_array($state['parser_result']) && is_array($state['dry_run'])) {
                self::render_preview($state['parser_result'], $state['dry_run']);
            }
        }

        echo '</div>';
    }

    /**
     * Export a posted current dry-run result. This writes no keyword rows and creates no import records.
     */
    public static function handle_export(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html(__('Unauthorized', 'tmwseo')));
        }
        check_admin_referer(self::EXPORT_ACTION, self::NONCE_FIELD);

        $payload = isset($_POST['tmwseo_keyword_pools_export_payload']) ? (string) wp_unslash($_POST['tmwseo_keyword_pools_export_payload']) : '';
        $decoded = json_decode(base64_decode($payload, true) ?: '', true);
        $rows    = is_array($decoded) && isset($decoded['rows']) && is_array($decoded['rows']) ? $decoded['rows'] : [];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tmwseo-keyword-pools-dry-run-preview.csv"');
        echo self::build_export_csv($rows);
        exit;
    }

    /**
     * @param array<int, array<string, mixed>> $rows Preview rows.
     */
    public static function build_export_csv(array $rows): string {
        $handle = fopen('php://temp', 'r+');
        if (false === $handle) {
            return '';
        }

        fputcsv($handle, self::export_headers());
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            fputcsv($handle, self::row_to_export_values($row));
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return is_string($csv) ? $csv : '';
    }

    private static function sanitize_pool(string $pool): string {
        $pool = strtolower(trim($pool));
        if ('metrics' === $pool) {
            return 'metrics';
        }
        return in_array($pool, self::ALLOWED_POOLS, true) ? $pool : 'model';
    }

    /**
     * @return array<string, mixed>
     */
    private static function maybe_process_dry_run(string &$active_pool): array {
        $state = [
            'csv_text'      => '',
            'parser_result' => null,
            'dry_run'       => null,
            'notices'       => [],
        ];

        if ('POST' !== (string) ($_SERVER['REQUEST_METHOD'] ?? '') || empty($_POST['tmwseo_keyword_pools_run_preview'])) {
            return $state;
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html(__('Unauthorized', 'tmwseo')));
        }

        $active_pool = self::sanitize_pool((string) ($_POST['tmwseo_keyword_pool'] ?? 'model'));
        if (!in_array($active_pool, self::ALLOWED_POOLS, true)) {
            $active_pool = 'model';
        }

        $pasted_csv = isset($_POST['tmwseo_keyword_pools_csv_text']) ? (string) wp_unslash($_POST['tmwseo_keyword_pools_csv_text']) : '';
        $upload     = self::read_upload_path();
        $parser     = new KeywordPoolCsvParser();

        if ('' !== $upload) {
            if ('' !== trim($pasted_csv)) {
                $state['notices'][] = [ 'type' => 'warning', 'text' => 'Uploaded CSV was used; pasted CSV was ignored for this dry run.' ];
            }
            $parser_result = $parser->parse_file($upload);
            $state['csv_text'] = '';
        } else {
            $state['csv_text'] = $pasted_csv;
            $parser_result = $parser->parse_text($pasted_csv);
        }

        $dry_run = (new KeywordPoolDryRunService())->dry_run($parser_result, $active_pool);

        $state['parser_result'] = $parser_result;
        $state['dry_run']       = $dry_run;
        return $state;
    }

    private static function read_upload_path(): string {
        if (empty($_FILES['tmwseo_keyword_pools_csv_file']) || !is_array($_FILES['tmwseo_keyword_pools_csv_file'])) {
            return '';
        }
        $file = $_FILES['tmwseo_keyword_pools_csv_file'];
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return '';
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ('' === $tmp || !is_uploaded_file($tmp) || !is_readable($tmp)) {
            return '';
        }
        return $tmp;
    }

    private static function render_intro_notice(): void {
        echo '<div class="notice notice-warning"><p><strong>' . esc_html(__('Dry-run preview only.', 'tmwseo')) . '</strong> ';
        echo esc_html(__('This tool does not save keywords, does not write Rank Math fields, does not change post content, does not change Generate output, and does not change indexing/noindex.', 'tmwseo'));
        echo '</p></div>';
    }

    /**
     * @param array<int, array{type:string,text:string}> $notices Notices.
     */
    private static function render_notices(array $notices): void {
        foreach ($notices as $notice) {
            $type = (string) ($notice['type'] ?? 'info');
            $css  = 'notice-info';
            if ('warning' === $type) {
                $css = 'notice-warning';
            } elseif ('error' === $type) {
                $css = 'notice-error';
            } elseif ('success' === $type) {
                $css = 'notice-success';
            }
            echo '<div class="notice ' . esc_attr($css) . '"><p>' . esc_html((string) ($notice['text'] ?? '')) . '</p></div>';
        }
    }

    private static function render_tabs(string $active_pool): void {
        echo '<h2 class="nav-tab-wrapper">';
        foreach (self::POOL_LABELS as $pool => $label) {
            $url    = add_query_arg([ 'page' => self::PAGE_SLUG, 'pool' => $pool ], admin_url('admin.php'));
            $active = $active_pool === $pool ? ' nav-tab-active' : '';
            echo '<a class="nav-tab' . esc_attr($active) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        $metrics_active = 'metrics' === $active_pool ? ' nav-tab-active' : '';
        echo '<a class="nav-tab' . esc_attr($metrics_active) . '" aria-disabled="true" href="' . esc_url(add_query_arg([ 'page' => self::PAGE_SLUG, 'pool' => 'metrics' ], admin_url('admin.php'))) . '">' . esc_html(__('Metrics Import', 'tmwseo')) . ' <span class="description">' . esc_html(__('coming soon', 'tmwseo')) . '</span></a>';
        echo '</h2>';
    }

    private static function render_pool_tab(string $pool, string $csv_text): void {
        echo '<h2>' . esc_html(self::POOL_LABELS[$pool]) . '</h2>';
        echo '<p>' . esc_html(self::POOL_HELP[$pool]) . '</p>';
        echo '<form method="post" enctype="multipart/form-data">';
        echo '<input type="hidden" name="' . esc_attr(self::NONCE_FIELD) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '" />';
        echo '<input type="hidden" name="action" value="tmwseo_keyword_pools_dry_run" />';
        echo '<input type="hidden" name="tmwseo_keyword_pool" value="' . esc_attr($pool) . '" />';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="tmwseo_keyword_pools_csv_file">' . esc_html(__('CSV Upload', 'tmwseo')) . '</label></th><td><input id="tmwseo_keyword_pools_csv_file" type="file" name="tmwseo_keyword_pools_csv_file" accept=".csv,text/csv" /><p class="description">' . esc_html(__('If upload and pasted CSV are both supplied, the uploaded file wins.', 'tmwseo')) . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="tmwseo_keyword_pools_csv_text">' . esc_html(__('Paste CSV', 'tmwseo')) . '</label></th><td><textarea id="tmwseo_keyword_pools_csv_text" name="tmwseo_keyword_pools_csv_text" rows="12" class="large-text code" placeholder="keyword,volume,difficulty,cpc,competition,intent,source,model_name,category,post_id,url,slug,title,status">' . self::esc_textarea($csv_text) . '</textarea></td></tr>';
        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-primary" name="tmwseo_keyword_pools_run_preview" value="1">' . esc_html(__('Run Dry Run Preview', 'tmwseo')) . '</button></p>';
        echo '</form>';
    }

    private static function render_metrics_tab(): void {
        echo '<h2>' . esc_html(__('Metrics Import', 'tmwseo')) . '</h2>';
        echo '<div class="notice notice-info inline"><p>' . esc_html(__('Metrics import is intentionally disabled on this preview-only page. Use existing approved metrics workflows when available; this tab does not import, save, or enrich keyword rows.', 'tmwseo')) . '</p></div>';
    }

    /**
     * @param array<string, mixed> $parser_result Parser result.
     * @param array<string, mixed> $dry_run Dry-run result.
     */
    private static function render_preview(array $parser_result, array $dry_run): void {
        echo '<hr />';
        echo '<div class="notice notice-error inline"><p><strong>' . esc_html(__('Preview only: no keywords were saved, no Rank Math fields were written, and no content was changed.', 'tmwseo')) . '</strong></p></div>';
        self::render_summary_cards($parser_result, $dry_run);
        self::render_parser_messages($parser_result);
        self::render_export_form($dry_run);
        self::render_preview_table($dry_run['rows'] ?? []);
    }

    /**
     * @param array<string, mixed> $parser_result Parser result.
     * @param array<string, mixed> $dry_run Dry-run result.
     */
    private static function render_summary_cards(array $parser_result, array $dry_run): void {
        $summary = is_array($dry_run['summary'] ?? null) ? $dry_run['summary'] : [];
        $cards = [
            'total parsed rows'      => (int) ($parser_result['row_count'] ?? 0),
            'accepted parser rows'   => (int) ($parser_result['accepted_row_count'] ?? 0),
            'skipped parser rows'    => (int) ($parser_result['skipped_row_count'] ?? 0),
            'valid rows'             => self::count_rows_by_state((array) ($dry_run['rows'] ?? []), 'valid'),
            'review-required rows'   => (int) ($summary['review_required'] ?? 0),
            'rejected rows'          => (int) ($summary['rejected'] ?? 0),
            'blocked rows'           => (int) ($summary['blocked'] ?? self::count_blocked_rows((array) ($dry_run['rows'] ?? []))),
            'duplicates in upload'   => (int) ($summary['duplicates'] ?? 0),
        ];

        echo '<h2>' . esc_html(__('Dry-Run Summary', 'tmwseo')) . '</h2>';
        echo '<div class="tmwseo-row executive-row">';
        foreach ($cards as $label => $count) {
            echo '<div class="tmwseo-card"><h3>' . esc_html((string) $count) . '</h3><p>' . esc_html(ucwords($label)) . '</p></div>';
        }
        echo '</div>';
    }

    /**
     * @param array<int, mixed> $rows Rows.
     */
    private static function count_rows_by_state(array $rows, string $state): int {
        $count = 0;
        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['validation_state'] ?? '') === $state) {
                ++$count;
            }
        }
        return $count;
    }

    /**
     * @param array<int, mixed> $rows Rows.
     */
    private static function count_blocked_rows(array $rows): int {
        $count = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $reasons = is_array($row['reason_codes'] ?? null) ? $row['reason_codes'] : [];
            if (in_array((string) ($row['decision'] ?? ''), [ 'reject', 'block' ], true) || in_array('standalone_model_name', $reasons, true) || in_array('missing_keyword', $reasons, true) || in_array('summary_or_footer_row', $reasons, true)) {
                ++$count;
            }
        }
        return $count;
    }

    /**
     * @param array<string, mixed> $parser_result Parser result.
     */
    private static function render_parser_messages(array $parser_result): void {
        $messages = [];
        foreach ([ 'warnings' => 'warning', 'errors' => 'error' ] as $key => $type) {
            if (!empty($parser_result[$key]) && is_array($parser_result[$key])) {
                foreach ($parser_result[$key] as $message) {
                    $messages[] = [ 'type' => $type, 'text' => (string) $message ];
                }
            }
        }
        if ([] === $messages) {
            return;
        }
        echo '<h2>' . esc_html(__('Parser Warnings and Errors', 'tmwseo')) . '</h2>';
        self::render_notices($messages);
    }

    /**
     * @param array<string, mixed> $dry_run Dry-run result.
     */
    private static function render_export_form(array $dry_run): void {
        $rows = is_array($dry_run['rows'] ?? null) ? $dry_run['rows'] : [];
        if ([] === $rows) {
            return;
        }
        $payload = base64_encode((string) wp_json_encode([ 'rows' => $rows, 'generated_at' => time() ]));
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="' . esc_attr(self::NONCE_FIELD) . '" value="' . esc_attr(wp_create_nonce(self::EXPORT_ACTION)) . '" />';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::EXPORT_ACTION) . '" />';
        echo '<input type="hidden" name="tmwseo_keyword_pools_export_payload" value="' . esc_attr($payload) . '" />';
        echo '<p><button type="submit" class="button button-secondary">' . esc_html(__('Download Dry-Run Preview CSV', 'tmwseo')) . '</button></p>';
        echo '<p class="description">' . esc_html(__('Export is generated only from the current dry-run preview and writes no keyword rows or import records.', 'tmwseo')) . '</p>';
        echo '</form>';
    }

    /**
     * @param array<int, mixed> $rows Rows.
     */
    private static function render_preview_table(array $rows): void {
        echo '<h2>' . esc_html(__('Preview Rows', 'tmwseo')) . '</h2>';
        if ([] === $rows) {
            echo '<p>' . esc_html(__('No preview rows are available.', 'tmwseo')) . '</p>';
            return;
        }

        echo '<div style="overflow:auto;max-width:100%;"><table class="widefat striped"><thead><tr>';
        foreach (self::preview_columns() as $header) {
            echo '<th>' . esc_html($header) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            echo '<tr>';
            foreach (self::row_to_preview_values($row) as $index => $value) {
                if (19 === $index && '' !== $value) {
                    echo '<td><a href="' . esc_url($value) . '" target="_blank" rel="noopener noreferrer">' . esc_html($value) . '</a></td>';
                } else {
                    echo '<td>' . esc_html($value) . '</td>';
                }
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * @param array<string, mixed> $row Preview row.
     * @return array<int, string>
     */
    private static function row_to_preview_values(array $row): array {
        $reasons = is_array($row['reason_codes'] ?? null) ? implode(' | ', array_map('strval', $row['reason_codes'])) : (string) ($row['reason_summary'] ?? '');
        return [
            (string) ($row['row_number'] ?? ''),
            (string) ($row['keyword'] ?? ''),
            (string) ($row['normalized_keyword'] ?? ''),
            (string) ($row['status_preview'] ?? ''),
            (string) ($row['validation_state'] ?? ''),
            (string) ($row['decision'] ?? ''),
            (string) ($row['priority_preview'] ?? ''),
            !empty($row['is_golden_keyword']) ? 'yes' : 'no',
            (string) ($row['recommended_action'] ?? ''),
            $reasons,
            self::metric_to_string($row['volume'] ?? null),
            self::metric_to_string($row['difficulty'] ?? null),
            self::metric_to_string($row['cpc'] ?? null),
            self::metric_to_string($row['competition'] ?? null),
            (string) ($row['intent'] ?? ''),
            (string) ($row['source'] ?? ''),
            (string) ($row['model_name'] ?? ''),
            (string) ($row['category'] ?? ''),
            (string) ($row['post_id'] ?? ''),
            (string) ($row['url'] ?? ''),
            (string) ($row['slug'] ?? ''),
            (string) ($row['title'] ?? ''),
            !empty($row['is_duplicate_in_upload']) ? 'yes' : 'no',
        ];
    }

    /**
     * @param array<string, mixed> $row Preview row.
     * @return array<int, string>
     */
    private static function row_to_export_values(array $row): array {
        $reason_codes = is_array($row['reason_codes'] ?? null) ? implode('|', array_map('strval', $row['reason_codes'])) : '';
        return array_merge(self::row_to_preview_values($row), [ $reason_codes ]);
    }

    private static function metric_to_string($value): string {
        if (null === $value || '' === $value) {
            return '';
        }
        return (string) $value;
    }

    private static function esc_textarea(string $text): string {
        if (function_exists('esc_textarea')) {
            return esc_textarea($text);
        }
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
