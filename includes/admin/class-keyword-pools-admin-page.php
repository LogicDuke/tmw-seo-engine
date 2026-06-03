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
use TMWSEO\Engine\Keywords\KeywordPoolImportBatchRepository;
use TMWSEO\Engine\Keywords\KeywordPoolSelectedImportService;

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
    public const SAVE_ACTION = 'tmwseo_keyword_pools_save_selected';

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
     * Register POST-only handlers.
     */
    public static function init(): void {
        add_action('admin_post_' . self::EXPORT_ACTION, [__CLASS__, 'handle_export']);
        add_action('admin_post_tmwseo_keyword_import_row_action', [__CLASS__, 'handle_import_row_action']);
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
            'Golden Missing Reasons',
            'Golden Formula',
            'Recommended Action',
            'TMW Score',
            'TMW Priority',
            'TMW Difficulty',
            'TMW Commercial',
            'TMW Indexing Readiness',
            'TMW Recommended Action',
            'Model Keyword Strategy',
            'Model Keyword Confidence',
            'Model Keyword Reason Codes',
            'Model Keyword Recommended Action',
            'Model Keyword Owner',
            'Model Keyword Usage Scope',
            'Model Keyword Primary Candidate',
            'Model Keyword Scope Reason Codes',
            'TMW Reason Codes',
            'TMW Score Formula',
            'Reasons',
            'Volume',
            'Difficulty',
            'CPC',
            'Competition',
            'SEO Score',
            'Traffic Value',
            'Trend',
            'Ad Difficulty',
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
        self::render_get_notice();
        self::render_tabs($active_pool);
        $view_batch_id = isset($_GET['tmwseo_keyword_batch_id']) ? absint($_GET['tmwseo_keyword_batch_id']) : 0;

        if ($view_batch_id > 0 && 'metrics' !== $active_pool) {
            self::render_saved_batch_view($view_batch_id);
        }

        if ('metrics' === $active_pool) {
            self::render_metrics_tab();
        } else {
            self::render_pool_tab($active_pool, (string) $state['csv_text'], is_array($state['import_context']) ? $state['import_context'] : []);
            if (is_array($state['parser_result']) && is_array($state['dry_run'])) {
                self::render_preview($active_pool, $state['parser_result'], $state['dry_run'], is_array($state['import_result']) ? $state['import_result'] : null, is_array($state['import_context']) ? $state['import_context'] : []);
            }
        }

        self::render_copy_keyword_script();
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


    public static function handle_import_row_action(): void {
        if (!current_user_can(self::CAPABILITY)) {
            wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tmwseo_notice=import_row_unauthorized'));
            exit;
        }

        $row_id = isset($_POST['import_row_id']) ? absint($_POST['import_row_id']) : 0;
        $requested_action = isset($_POST['import_row_action']) ? sanitize_key((string) wp_unslash($_POST['import_row_action'])) : '';
        if ($row_id <= 0 || !in_array($requested_action, [ 'approve', 'reject' ], true)) {
            wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tmwseo_notice=import_row_invalid'));
            exit;
        }
        check_admin_referer('tmwseo_keyword_import_row_action_' . $row_id, self::NONCE_FIELD);

        $repository = new KeywordPoolImportBatchRepository();
        $row = $repository->get_row($row_id);
        if (!is_array($row)) {
            wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tmwseo_notice=import_row_missing'));
            exit;
        }
        $batch_id = (int) ($row['batch_id'] ?? 0);
        $batch = $repository->get_batch($batch_id);
        if (!is_array($batch)) {
            wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tmwseo_notice=import_batch_missing'));
            exit;
        }

        $candidate_id = (int) ($row['candidate_id'] ?? 0);
        $now = current_time('mysql');
        if ('approve' === $requested_action) {
            $approved_candidate_id = 0;
            if ($candidate_id > 0 && $repository->update_candidate_status($candidate_id, 'approved')) {
                $approved_candidate_id = $candidate_id;
            } else {
                $approved_candidate_id = (new KeywordPoolSelectedImportService())->approve_import_row_as_candidate($row, $batch);
            }

            if ($approved_candidate_id > 0) {
                $repository->update_import_row($row_id, [
                    'status' => 'approved',
                    'result_action' => 'approved',
                    'result_reason' => 'manually_approved',
                    'candidate_id' => $approved_candidate_id,
                    'reviewed_by' => get_current_user_id(),
                    'reviewed_at' => $now,
                ]);
            } else {
                $repository->update_import_row($row_id, [
                    'result_action' => 'candidate_write_failed',
                    'result_reason' => 'candidate_write_failed',
                    'reviewed_by' => get_current_user_id(),
                    'reviewed_at' => $now,
                ]);
            }
        } else {
            $can_reject = true;
            if ($candidate_id > 0) {
                $can_reject = $repository->update_candidate_status($candidate_id, 'ignored');
            }
            if ($can_reject) {
                $repository->update_import_row($row_id, [
                    'status' => 'rejected',
                    'result_action' => 'rejected',
                    'result_reason' => 'manually_rejected',
                    'reviewed_by' => get_current_user_id(),
                    'reviewed_at' => $now,
                ]);
            } else {
                $repository->update_import_row($row_id, [
                    'result_action' => 'candidate_write_failed',
                    'result_reason' => 'candidate_write_failed',
                    'reviewed_by' => get_current_user_id(),
                    'reviewed_at' => $now,
                ]);
            }
        }
        $repository->recalculate_batch_counts($batch_id);

        wp_safe_redirect(add_query_arg([
            'page' => self::PAGE_SLUG,
            'pool' => (string) ($batch['pool'] ?? 'model'),
            'tmwseo_keyword_batch_id' => $batch_id,
            'tmwseo_keyword_pools_target_id' => (int) ($batch['target_id'] ?? 0),
            'tmwseo_notice' => 'import_row_' . $requested_action,
        ], admin_url('admin.php')));
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
            'import_result' => null,
            'import_context' => [],
        ];

        if ('POST' !== (string) ($_SERVER['REQUEST_METHOD'] ?? '')) {
            $target_id = isset($_GET['tmwseo_keyword_pools_target_id']) ? absint($_GET['tmwseo_keyword_pools_target_id']) : 0;
            if ($target_id > 0 && self::pool_requires_target($active_pool)) {
                $target = (new KeywordPoolTargetProvider())->validate_target($active_pool, $target_id);
                if (is_array($target)) {
                    $state['import_context'] = $target;
                }
            }
            return $state;
        }

        if (
            !empty($_POST['tmwseo_keyword_pools_save_selected'])
            || !empty($_POST['tmwseo_keyword_pools_save_full_model_batch'])
            || !empty($_POST['tmwseo_keyword_pools_save_full_category_batch'])
        ) {
            return self::maybe_process_save_selected($active_pool, $state);
        }

        if (empty($_POST['tmwseo_keyword_pools_run_preview'])) {
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
        $source_file = self::uploaded_source_filename();
        $import_context = self::posted_import_context($active_pool, $source_file, false);
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

        if (self::pool_requires_target($active_pool) && empty($import_context['target_id'])) {
            $state['notices'][] = [ 'type' => 'warning', 'text' => self::target_required_message($active_pool) ];
        }
        $parser_result['_tmw_import_context'] = $import_context;
        $state['parser_result'] = $parser_result;
        $state['dry_run']       = $dry_run;
        $state['import_context'] = $import_context;
        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private static function maybe_process_save_selected(string &$active_pool, array $state): array {
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html(__('Unauthorized', 'tmwseo')));
        }

        $active_pool = self::sanitize_pool((string) ($_POST['tmwseo_keyword_pool'] ?? 'model'));
        if (!in_array($active_pool, self::ALLOWED_POOLS, true)) {
            $active_pool = 'model';
        }

        $payload = isset($_POST['tmwseo_keyword_pools_save_payload']) ? (string) wp_unslash($_POST['tmwseo_keyword_pools_save_payload']) : '';
        $decoded = self::decode_signed_payload($payload);
        if (!is_array($decoded) || ($decoded['pool'] ?? '') !== $active_pool) {
            $state['notices'][] = [ 'type' => 'error', 'text' => 'Save failed because the dry-run payload was missing, expired, or did not match the selected pool.' ];
            return $state;
        }

        $parser_result = is_array($decoded['parser_result'] ?? null) ? $decoded['parser_result'] : null;
        if (!is_array($parser_result)) {
            $state['notices'][] = [ 'type' => 'error', 'text' => 'Save failed because the original parsed dry-run rows were unavailable.' ];
            return $state;
        }

        $dry_run = (new KeywordPoolDryRunService())->dry_run($parser_result, $active_pool);
        $payload_context = is_array($parser_result['_tmw_import_context'] ?? null) ? $parser_result['_tmw_import_context'] : [];
        $import_context = self::posted_import_context($active_pool, (string) ($payload_context['source_file'] ?? ''), true, $payload_context);
        if (self::pool_requires_target($active_pool) && empty($import_context['target_id'])) {
            $state['parser_result'] = $parser_result;
            $state['dry_run'] = $dry_run;
            $state['import_context'] = $import_context;
            $state['notices'][] = [ 'type' => 'error', 'text' => self::target_required_message($active_pool) ];
            return $state;
        }
        if (self::pool_requires_target($active_pool) && empty($import_context['target_type'])) {
            $state['parser_result'] = $parser_result;
            $state['dry_run'] = $dry_run;
            $state['import_context'] = $import_context;
            $state['notices'][] = [ 'type' => 'error', 'text' => self::invalid_target_message($active_pool) ];
            return $state;
        }
        if ('' === trim((string) ($import_context['source_batch'] ?? ''))) {
            $state['notices'][] = [ 'type' => 'warning', 'text' => '[TMW-KW-BATCH] Source Label is empty; saved keywords will not have a named source batch.' ];
        }
        $save_full_model_batch = !empty($_POST['tmwseo_keyword_pools_save_full_model_batch']) && 'model' === $active_pool;
        $save_full_category_batch = !empty($_POST['tmwseo_keyword_pools_save_full_category_batch']) && 'category' === $active_pool;
        $selected = isset($_POST['tmwseo_keyword_pool_selected_rows']) && is_array($_POST['tmwseo_keyword_pool_selected_rows']) ? array_map('intval', (array) wp_unslash($_POST['tmwseo_keyword_pool_selected_rows'])) : [];
        $save_mode = isset($_POST['tmwseo_keyword_pool_save_mode']) ? (string) wp_unslash($_POST['tmwseo_keyword_pool_save_mode']) : 'auto';
        $import_service = new KeywordPoolSelectedImportService();
        if ($save_full_model_batch) {
            $import_result = $import_service->save_full_reviewed_model_batch($dry_run, $import_context);
        } elseif ($save_full_category_batch) {
            $import_result = $import_service->save_full_reviewed_category_batch($dry_run, $import_context);
        } else {
            $import_result = $import_service->save_selected($dry_run, $active_pool, $selected, $save_mode, $import_context);
        }

        $state['parser_result'] = $parser_result;
        $state['dry_run'] = $dry_run;
        $state['import_result'] = $import_result;
        $state['import_context'] = $import_context;
        $summary = is_array($import_result['summary'] ?? null) ? $import_result['summary'] : [];
        $operation_label = $save_full_model_batch ? '[TMW-KW-POOL] [TMW-KW-BATCH] [TMW-MODEL-KW] Save full reviewed model keyword batch' : '[TMW-KW-POOL] Save selected';
        if ('model' === $active_pool && !$save_full_model_batch) {
            $operation_label = '[TMW-KW-POOL] [TMW-KW-TARGET] [TMW-MODEL-KW] Save selected model keywords';
        }
        if ('category' === $active_pool && !$save_full_category_batch) {
            $operation_label = '[TMW-KW-POOL] [TMW-KW-TARGET] [TMW-CAT-KW] Save selected category keywords';
        }
        if ($save_full_category_batch) {
            $operation_label = '[TMW-KW-POOL] [TMW-KW-BATCH] [TMW-CAT-KW] Save full reviewed category keyword batch';
        }
        $state['notices'][] = [
            'type' => empty($summary['errors']) && empty($summary['conflicts']) ? 'success' : 'warning',
            'text' => sprintf(
                '%s complete: %d selected, %d inserted, %d updated, %d skipped, %d conflicts, %d blocked, %d errors, %d linked to model entity, %d unresolved, %d ambiguous.',
                $operation_label,
                (int) ($summary['selected'] ?? 0),
                (int) ($summary['inserted'] ?? 0),
                (int) ($summary['updated'] ?? 0),
                (int) ($summary['skipped'] ?? 0),
                (int) ($summary['conflicts'] ?? 0),
                (int) ($summary['blocked'] ?? 0),
                (int) ($summary['errors'] ?? 0),
                (int) ($summary['linked_model_entities'] ?? 0),
                (int) ($summary['unresolved_model_entities'] ?? 0),
                (int) ($summary['ambiguous_model_entities'] ?? 0)
            ),
        ];
        $created_batch_id = (int) ($import_result['batch_id'] ?? 0);
        $persistence_error = trim((string) ($import_result['persistence_error'] ?? ''));
        if ($created_batch_id > 0) {
            if ('' !== $persistence_error) {
                $state['notices'][] = [
                    'type' => 'warning',
                    'text' => sprintf('[TMW-KW-IMPORT] %s', $persistence_error),
                ];
            } else {
                $state['notices'][] = [
                    'type' => 'success',
                    'text' => sprintf(
                        '[TMW-KW-IMPORT] Created import batch %d for target %s:%d with %d rows',
                        $created_batch_id,
                        (string) ($import_context['target_type'] ?? self::target_type_for_pool($active_pool)),
                        (int) ($import_context['target_id'] ?? 0),
                        (int) ($summary['selected'] ?? 0)
                    ),
                ];
            }
        } else {
            $state['notices'][] = [
                'type' => 'warning',
                'text' => '' !== $persistence_error
                    ? sprintf('[TMW-KW-IMPORT] Import batch persistence failed: %s', $persistence_error)
                    : '[TMW-KW-IMPORT] Import batch was not persisted (batch_id=0). Verify that tmw_keyword_import_batches and tmw_keyword_import_rows tables exist and the schema migration has run.',
            ];
        }
        return $state;
    }


    private static function uploaded_source_filename(): string {
        if (empty($_FILES['tmwseo_keyword_pools_csv_file']) || !is_array($_FILES['tmwseo_keyword_pools_csv_file'])) {
            return '';
        }
        $name = (string) ($_FILES['tmwseo_keyword_pools_csv_file']['name'] ?? '');
        if ('' === trim($name)) {
            return '';
        }
        return self::sanitize_text($name, 255);
    }

    /** @param array<string,mixed> $fallback @return array<string,mixed> */
    private static function posted_import_context(string $pool, string $source_file = '', bool $require_valid_target = false, array $fallback = []): array {
        $source_file = '' !== $source_file ? $source_file : (string) ($fallback['source_file'] ?? '');
        $source_label = isset($_POST['tmwseo_keyword_pools_source_label']) ? (string) wp_unslash($_POST['tmwseo_keyword_pools_source_label']) : (string) ($fallback['source_batch'] ?? '');
        $source_label = self::sanitize_text($source_label, 255);
        if ('' === $source_label && '' !== $source_file) {
            $source_label = function_exists('sanitize_file_name') ? sanitize_file_name($source_file) : preg_replace('/[^a-zA-Z0-9._-]/', '-', $source_file);
        }

        $context = [
            'source_batch' => $source_label,
            'source_file' => $source_file,
            'import_batch_id' => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16)),
            'imported_at' => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
        ];

        $target_id = isset($_POST['tmwseo_keyword_pools_target_id']) ? (int) wp_unslash($_POST['tmwseo_keyword_pools_target_id']) : (int) ($fallback['target_id'] ?? 0);
        if ($target_id > 0 && self::pool_requires_target($pool)) {
            $target = (new KeywordPoolTargetProvider())->validate_target($pool, $target_id);
            if (is_array($target)) {
                $context = array_merge($context, $target);
            } elseif ($require_valid_target) {
                $context['target_id'] = $target_id;
            }
        }

        return $context;
    }

    /** @param array<string,mixed> $context */
    private static function render_target_source_fields(string $pool, array $context, bool $for_save): void {
        if (self::pool_requires_target($pool)) {
            $provider = new KeywordPoolTargetProvider();
            $targets = 'category' === $pool ? $provider->category_targets() : $provider->model_targets();
            $label = 'category' === $pool ? __('Target Category', 'tmwseo') : __('Target Model', 'tmwseo');
            echo '<tr><th scope="row"><label for="tmwseo_keyword_pools_target_id">' . esc_html($label) . '</label></th><td>';
            echo '<select id="tmwseo_keyword_pools_target_id" name="tmwseo_keyword_pools_target_id"><option value="0">' . esc_html__('Select target…', 'tmwseo') . '</option>';
            $selected_id = (int) ($context['target_id'] ?? 0);
            foreach ($targets as $target) {
                echo '<option value="' . esc_attr((string) (int) ($target['target_id'] ?? 0)) . '" ' . selected($selected_id, (int) ($target['target_id'] ?? 0), false) . '>' . esc_html((string) ($target['label'] ?? '')) . '</option>';
            }
            echo '</select>';
            echo '<p class="description">' . esc_html__('Uses existing posts only; this does not create pages, posts, terms, or publish drafts.', 'tmwseo') . '</p>';
            if ($for_save && $selected_id <= 0) {
                echo '<p class="description" style="color:#b32d2e;">' . esc_html(self::target_required_message($pool)) . '</p>';
            }
            echo '</td></tr>';
        }

        $source_batch = (string) ($context['source_batch'] ?? '');
        echo '<tr><th scope="row"><label for="tmwseo_keyword_pools_source_label">' . esc_html__('Source Label', 'tmwseo') . '</label></th><td>';
        echo '<input id="tmwseo_keyword_pools_source_label" type="text" name="tmwseo_keyword_pools_source_label" class="regular-text" value="' . esc_attr($source_batch) . '" placeholder="' . esc_attr__('Optional batch/source label', 'tmwseo') . '" />';
        echo '<p class="description">' . esc_html__('Optional for preview; recommended before saving. If omitted for uploads, the sanitized filename is used.', 'tmwseo') . '</p>';
        echo '</td></tr>';
    }

    private static function pool_requires_target(string $pool): bool {
        return in_array($pool, [ 'category', 'model' ], true);
    }

    /**
     * Returns the canonical target_type string for a pool,
     * matching what KeywordPoolTargetProvider stores in every batch record.
     */
    private static function target_type_for_pool(string $pool): string {
        if ('category' === $pool) {
            return 'category_page';
        }
        if ('model' === $pool) {
            return 'model';
        }
        return '';
    }

    private static function target_required_message(string $pool): string {
        return 'category' === $pool
            ? __('Target Category is required before saving category keywords.', 'tmwseo')
            : __('Target Model is required before saving model keywords.', 'tmwseo');
    }

    private static function invalid_target_message(string $pool): string {
        return 'category' === $pool
            ? __('Selected Target Category is invalid or no longer available.', 'tmwseo')
            : __('Selected Target Model is invalid or no longer available.', 'tmwseo');
    }

    private static function sanitize_text(string $value, int $max_length): string {
        $value = function_exists('sanitize_text_field') ? sanitize_text_field($value) : trim(strip_tags($value));
        return function_exists('mb_substr') ? mb_substr($value, 0, $max_length) : substr($value, 0, $max_length);
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


    private static function render_copy_keyword_script(): void {
        echo '<script>(function(){if(window.tmwCandidateCopyBound){return;}window.tmwCandidateCopyBound=true;document.addEventListener("click",function(event){var button=event.target&&event.target.closest("[data-tmw-copy-keyword]");if(!button){return;}var keyword=(button.getAttribute("data-tmw-copy-keyword")||"").trim();if(keyword===""){return;}var previous=button.textContent;var showResult=function(text){button.textContent=text;window.setTimeout(function(){button.textContent=previous;},1200);};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(keyword).then(function(){showResult("Copied");}).catch(function(){showResult("Copy keyword manually");});return;}var helper=document.createElement("textarea");helper.value=keyword;helper.setAttribute("readonly","");helper.style.position="absolute";helper.style.left="-9999px";document.body.appendChild(helper);helper.select();try{document.execCommand("copy");showResult("Copied");}catch(error){showResult("Copy keyword manually");}document.body.removeChild(helper);});})();</script>';
    }

    private static function render_intro_notice(): void {
        echo '<div class="notice notice-warning"><p><strong>' . esc_html(__('Dry-run first workflow.', 'tmwseo')) . '</strong> ';
        echo esc_html(__('Dry-run preview does not save keywords. Save Selected Keywords stores only explicitly selected safe rows in the review pool; it does not write Rank Math fields, does not change post content, does not change Generate output, and does not change indexing/noindex.', 'tmwseo'));
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

    /**
     * Renders a one-time admin notice from the ?tmwseo_notice= GET param.
     * Used by handle_import_row_action() redirects.
     */
    private static function render_get_notice(): void {
        if (empty($_GET['tmwseo_notice'])) {
            return;
        }
        $slug = sanitize_key((string) wp_unslash($_GET['tmwseo_notice']));
        $map  = [
            'import_row_approve'      => [ 'success', __('Import row approved and candidate saved.', 'tmwseo') ],
            'import_row_reject'       => [ 'success', __('Import row rejected.', 'tmwseo') ],
            'import_row_unauthorized' => [ 'error',   __('Unauthorized action.', 'tmwseo') ],
            'import_row_invalid'      => [ 'error',   __('Invalid import row action.', 'tmwseo') ],
            'import_row_missing'      => [ 'error',   __('Import row not found.', 'tmwseo') ],
            'import_batch_missing'    => [ 'error',   __('Import batch not found.', 'tmwseo') ],
        ];
        if (!isset($map[$slug])) {
            return;
        }
        [$type, $message] = $map[$slug];
        $css = 'success' === $type ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($css) . ' is-dismissible"><p>'
            . esc_html($message)
            . '</p></div>';
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

    private static function render_pool_tab(string $pool, string $csv_text, array $context = []): void {
        echo '<h2>' . esc_html(self::POOL_LABELS[$pool]) . '</h2>';
        echo '<p>' . esc_html(self::POOL_HELP[$pool]) . '</p>';
        echo '<form method="post" enctype="multipart/form-data">';
        echo '<input type="hidden" name="' . esc_attr(self::NONCE_FIELD) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '" />';
        echo '<input type="hidden" name="action" value="tmwseo_keyword_pools_dry_run" />';
        echo '<input type="hidden" name="tmwseo_keyword_pool" value="' . esc_attr($pool) . '" />';
        echo '<table class="form-table" role="presentation"><tbody>';
        self::render_target_source_fields($pool, $context, false);
        self::render_import_history_row($pool, $context);
        echo '<tr><th scope="row"><label for="tmwseo_keyword_pools_csv_file">' . esc_html(__('CSV Upload', 'tmwseo')) . '</label></th><td><input id="tmwseo_keyword_pools_csv_file" type="file" name="tmwseo_keyword_pools_csv_file" accept=".csv,text/csv" /><p class="description">' . esc_html(__('If upload and pasted CSV are both supplied, the uploaded file wins.', 'tmwseo')) . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="tmwseo_keyword_pools_csv_text">' . esc_html(__('Paste CSV', 'tmwseo')) . '</label></th><td><textarea id="tmwseo_keyword_pools_csv_text" name="tmwseo_keyword_pools_csv_text" rows="12" class="large-text code" placeholder="keyword,volume,difficulty,cpc,competition,intent,source,model_name,category,post_id,url,slug,title,status">' . self::esc_textarea($csv_text) . '</textarea></td></tr>';
        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-primary" name="tmwseo_keyword_pools_run_preview" value="1">' . esc_html(__('Run Dry Run Preview', 'tmwseo')) . '</button></p>';
        echo '</form>';
    }


    /** @param array<string,mixed> $context */
    private static function render_import_history_row(string $pool, array $context): void {
        if (!in_array($pool, [ 'category', 'model' ], true)) {
            return;
        }
        $target_id = (int) ($context['target_id'] ?? 0);
        echo '<tr><th scope="row">' . esc_html__('Import History', 'tmwseo') . '</th><td>';
        if ($target_id <= 0) {
            echo '<p class="description">' . esc_html__('Select a target to see durable import history for that category or model.', 'tmwseo') . '</p>';
            echo '</td></tr>';
            return;
        }
        $repository = new KeywordPoolImportBatchRepository();
        $query_target_type = (string) ($context['target_type'] ?? '');
        if ('' === $query_target_type) {
            $query_target_type = self::target_type_for_pool($pool);
        }
        $batches = $repository->query_batches(
            $pool,
            '' !== $query_target_type ? $query_target_type : null,
            $target_id,
            10
        );
        if ([] === $batches) {
            echo '<p class="description">' . esc_html__('No saved import batches found for this target yet.', 'tmwseo') . '</p>';
            echo '</td></tr>';
            return;
        }
        self::render_import_history_table($batches);
        echo '</td></tr>';
    }

    /** @param array<int,array<string,mixed>> $batches */
    private static function render_import_history_table(array $batches): void {
        echo '<table class="widefat striped" style="max-width:1100px;"><thead><tr>';
        foreach ([ 'Source', 'Target', 'Imported', 'Rows', 'Approved', 'Queued/Review', 'Blocked', 'Skipped', 'Errors', 'Status', 'Actions' ] as $header) {
            echo '<th>' . esc_html($header) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($batches as $batch) {
            $batch_id = (int) ($batch['id'] ?? 0);
            $pool = (string) ($batch['pool'] ?? 'model');
            $target_id = (int) ($batch['target_id'] ?? 0);
            $source = (string) ($batch['source_file'] ?? '') !== '' ? (string) $batch['source_file'] : (string) ($batch['source_batch'] ?? '');
            $view_url = add_query_arg([ 'page' => self::PAGE_SLUG, 'pool' => $pool, 'tmwseo_keyword_pools_target_id' => $target_id, 'tmwseo_keyword_batch_id' => $batch_id ], admin_url('admin.php'));
            echo '<tr>';
            echo '<td>' . esc_html($source) . '</td>';
            echo '<td>' . esc_html((string) ($batch['target_name'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($batch['imported_at'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) (int) ($batch['total_rows'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) (int) ($batch['approved'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) ((int) ($batch['queued'] ?? 0) + (int) ($batch['review_required'] ?? 0))) . '</td>';
            echo '<td>' . esc_html((string) (int) ($batch['blocked'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) (int) ($batch['skipped'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) (int) ($batch['errors'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) ($batch['status'] ?? 'open')) . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url($view_url) . '">' . esc_html__('View Batch', 'tmwseo') . '</a> ';
            echo '<a class="button button-small" href="' . esc_url($view_url) . '">' . esc_html__('Continue Review', 'tmwseo') . '</a> ';
            echo '<span class="button button-small disabled" aria-disabled="true">' . esc_html__('Export coming soon', 'tmwseo') . '</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_saved_batch_view(int $batch_id): void {
        $repository = new KeywordPoolImportBatchRepository();
        $batch = $repository->get_batch($batch_id);
        if (!is_array($batch)) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Import batch was not found.', 'tmwseo') . '</p></div>';
            return;
        }
        $page_size = 100;
        $current_page = max(1, isset($_GET['tmwseo_keyword_batch_page']) ? absint($_GET['tmwseo_keyword_batch_page']) : 1);
        $total_rows = $repository->count_rows($batch_id);
        $total_pages = max(1, (int) ceil($total_rows / $page_size));
        if ($current_page > $total_pages) { $current_page = $total_pages; }
        $offset = ($current_page - 1) * $page_size;
        $rows = $repository->query_rows($batch_id, '', $page_size, $offset);
        echo '<hr /><h2>' . esc_html__('Import Batch', 'tmwseo') . ': ' . esc_html((string) ($batch['source_file'] ?: $batch['source_batch'] ?: $batch['import_batch_id'])) . '</h2>';
        echo '<p>' . esc_html(sprintf('Target: %s. Imported: %s. Total rows: %d. Page %d of %d.', (string) ($batch['target_name'] ?? ''), (string) ($batch['imported_at'] ?? ''), $total_rows, $current_page, $total_pages)) . '</p>';
        self::render_batch_pagination($batch, $batch_id, $current_page, $total_pages);
        $inspect_id = isset($_GET['tmwseo_import_row_inspect']) ? absint($_GET['tmwseo_import_row_inspect']) : 0;
        echo '<table class="widefat striped"><thead><tr>';
        foreach ([ 'Keyword', 'Volume', 'Status', 'Target', 'Result / Reason', 'Actions' ] as $header) {
            echo '<th>' . esc_html($header) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $row_id = (int) ($row['id'] ?? 0);
            $copy_keyword = (string) ($row['normalized_keyword'] ?? '') !== '' ? (string) $row['normalized_keyword'] : (string) ($row['keyword'] ?? '');
            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['keyword'] ?? '')) . '</td>';
            echo '<td>' . esc_html(self::metric_to_string($row['volume'] ?? '')) . '</td>';
            echo '<td>' . self::status_badge((string) ($row['status'] ?? 'review_required')) . '</td>';
            echo '<td>' . esc_html((string) ($row['target_name'] ?? $batch['target_name'] ?? '')) . '</td>';
            echo '<td>' . esc_html(trim((string) ($row['result_action'] ?? '') . ((string) ($row['result_reason'] ?? '') !== '' ? ' — ' . (string) $row['result_reason'] : ''))) . '</td>';
            echo '<td>' . self::import_row_action_forms($row, $batch) . ' ';
            $inspect_url = add_query_arg([ 'page' => self::PAGE_SLUG, 'pool' => (string) ($batch['pool'] ?? 'model'), 'tmwseo_keyword_batch_id' => $batch_id, 'tmwseo_keyword_batch_page' => $current_page, 'tmwseo_import_row_inspect' => $row_id ], admin_url('admin.php'));
            echo '<a href="' . esc_url($inspect_url) . '">' . esc_html__('Inspect', 'tmwseo') . '</a> ';
            echo '<button type="button" class="button-link" data-tmw-copy-keyword="' . esc_attr($copy_keyword) . '">' . esc_html__('Copy', 'tmwseo') . '</button>';
            echo '</td></tr>';
            if ($inspect_id === $row_id) {
                echo '<tr><td colspan="6">';
                self::render_import_row_inspect_panel($row, $batch);
                echo '</td></tr>';
            }
        }
        echo '</tbody></table>';
        self::render_batch_pagination($batch, $batch_id, $current_page, $total_pages);
        echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__('Safety boundary:', 'tmwseo') . '</strong> ' . esc_html__('Manual row actions only update keyword-pool candidates and import-row review state. They do not write Rank Math, content, slugs, taxonomy terms, publishing state, or indexing/noindex.', 'tmwseo') . '</p></div>';
    }


    /** @param array<string,mixed> $batch */
    private static function render_batch_pagination(array $batch, int $batch_id, int $current_page, int $total_pages): void {
        if ($total_pages <= 1) { return; }
        $base_args = [
            'page' => self::PAGE_SLUG,
            'pool' => (string) ($batch['pool'] ?? 'model'),
            'tmwseo_keyword_batch_id' => $batch_id,
            'tmwseo_keyword_pools_target_id' => (int) ($batch['target_id'] ?? 0),
        ];
        echo '<p class="tablenav-pages">';
        echo '<span class="displaying-num">' . esc_html(sprintf(__('Page %1$d of %2$d', 'tmwseo'), $current_page, $total_pages)) . '</span> ';
        if ($current_page > 1) {
            $previous_url = add_query_arg(array_merge($base_args, [ 'tmwseo_keyword_batch_page' => $current_page - 1 ]), admin_url('admin.php'));
            echo '<a class="button" href="' . esc_url($previous_url) . '">' . esc_html__('Previous', 'tmwseo') . '</a> ';
        } else {
            echo '<span class="button disabled" aria-disabled="true">' . esc_html__('Previous', 'tmwseo') . '</span> ';
        }
        if ($current_page < $total_pages) {
            $next_url = add_query_arg(array_merge($base_args, [ 'tmwseo_keyword_batch_page' => $current_page + 1 ]), admin_url('admin.php'));
            echo '<a class="button" href="' . esc_url($next_url) . '">' . esc_html__('Next', 'tmwseo') . '</a>';
        } else {
            echo '<span class="button disabled" aria-disabled="true">' . esc_html__('Next', 'tmwseo') . '</span>';
        }
        echo '</p>';
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $batch */
    private static function import_row_action_forms(array $row, array $batch): string {
        $row_id = (int) ($row['id'] ?? 0);
        if ($row_id <= 0) { return ''; }
        $forms = [];
        foreach ([ 'approve' => __('Approve', 'tmwseo'), 'reject' => __('Reject', 'tmwseo') ] as $action => $label) {
            $forms[] = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
                . '<input type="hidden" name="action" value="tmwseo_keyword_import_row_action" />'
                . '<input type="hidden" name="import_row_id" value="' . esc_attr((string) $row_id) . '" />'
                . '<input type="hidden" name="import_row_action" value="' . esc_attr($action) . '" />'
                . '<input type="hidden" name="' . esc_attr(self::NONCE_FIELD) . '" value="' . esc_attr(wp_create_nonce('tmwseo_keyword_import_row_action_' . $row_id)) . '" />'
                . '<button type="submit" class="button-link">' . esc_html($label) . '</button>'
                . '</form>';
        }
        return implode(' | ', $forms);
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $batch */
    private static function render_import_row_inspect_panel(array $row, array $batch): void {
        $payload = json_decode((string) ($row['row_payload'] ?? ''), true);
        echo '<div class="tmwseo-import-row-inspect" style="background:#fff;border:1px solid #ccd0d4;padding:12px;">';
        echo '<h3>' . esc_html__('Import Row Inspect', 'tmwseo') . '</h3>';
        echo '<dl style="display:grid;grid-template-columns:180px minmax(0,1fr);gap:6px 12px;">';
        $details = [
            'keyword' => $row['keyword'] ?? '',
            'normalized_keyword' => $row['normalized_keyword'] ?? '',
            'row_number' => $row['row_index'] ?? '',
            'target' => (string) ($row['target_name'] ?? $batch['target_name'] ?? ''),
            'source_batch/source_file' => trim((string) ($batch['source_batch'] ?? '') . ' / ' . (string) ($batch['source_file'] ?? ''), ' /'),
            'validation_state' => $row['validation_state'] ?? '',
            'decision' => $row['decision'] ?? '',
            'result_action' => $row['result_action'] ?? '',
            'result_reason' => $row['result_reason'] ?? '',
            'volume/cpc/competition' => trim((string) ($row['volume'] ?? '') . ' / ' . (string) ($row['cpc'] ?? '') . ' / ' . (string) ($row['competition'] ?? ''), ' /'),
            'candidate_id' => $row['candidate_id'] ?? '',
        ];
        foreach ($details as $label => $value) {
            echo '<dt><strong>' . esc_html((string) $label) . '</strong></dt><dd>' . esc_html((string) $value) . '</dd>';
        }
        echo '</dl>';
        echo '<h4>' . esc_html__('Row Payload', 'tmwseo') . '</h4>';
        echo '<pre style="white-space:pre-wrap;max-height:320px;overflow:auto;">' . esc_html(json_encode(is_array($payload) ? $payload : [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
        echo '</div>';
    }

    private static function render_metrics_tab(): void {
        echo '<h2>' . esc_html(__('Metrics Import', 'tmwseo')) . '</h2>';
        echo '<div class="notice notice-info inline"><p>' . esc_html(__('Metrics import is intentionally disabled on this preview-only page. Use existing approved metrics workflows when available; this tab does not import, save, or enrich keyword rows.', 'tmwseo')) . '</p></div>';
    }

    /**
     * @param array<string, mixed> $parser_result Parser result.
     * @param array<string, mixed> $dry_run Dry-run result.
     */
    private static function render_preview(string $pool, array $parser_result, array $dry_run, ?array $import_result = null, array $context = []): void {
        echo '<hr />';
        echo '<div class="notice notice-warning inline"><p><strong>' . esc_html(__('Save safety boundary:', 'tmwseo')) . '</strong> ' . esc_html(__('Saving selected keywords stores them in the review pool only. It does not write Rank Math, does not change content, does not change slugs, does not call Generate, and does not change indexing/noindex.', 'tmwseo')) . '</p></div>';
        self::render_summary_cards($parser_result, $dry_run);
        self::render_parser_messages($parser_result);
        if (is_array($import_result)) {
            self::render_import_result($import_result);
        }
        if (self::pool_requires_target($pool) && empty($context['target_id'])) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html(self::target_required_message($pool)) . '</p></div>';
        }
        self::render_export_form($dry_run);
        self::render_save_selected_form($pool, $parser_result, $dry_run, $context);
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
     * @param string $pool Active keyword pool.
     * @param array<string, mixed> $parser_result Parser result used to rebuild the dry run.
     * @param array<string, mixed> $dry_run Current dry-run result.
     */
    private static function render_save_selected_form(string $pool, array $parser_result, array $dry_run, array $context = []): void {
        $rows = is_array($dry_run['rows'] ?? null) ? $dry_run['rows'] : [];
        echo '<h2>' . esc_html(__('Preview Rows', 'tmwseo')) . '</h2>';
        if ([] === $rows) {
            echo '<p>' . esc_html(__('No preview rows are available.', 'tmwseo')) . '</p>';
            return;
        }

        $payload = self::encode_signed_payload([ 'pool' => $pool, 'parser_result' => $parser_result, 'generated_at' => time() ]);
        echo '<form method="post">';
        echo '<input type="hidden" name="' . esc_attr(self::NONCE_FIELD) . '" value="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '" />';
        echo '<input type="hidden" name="tmwseo_keyword_pool" value="' . esc_attr($pool) . '" />';
        echo '<input type="hidden" name="tmwseo_keyword_pools_save_payload" value="' . esc_attr($payload) . '" />';
        echo '<table class="form-table" role="presentation"><tbody>';
        self::render_target_source_fields($pool, $context, true);
        echo '</tbody></table>';
        echo '<p><button type="button" class="button" onclick="document.querySelectorAll(\'.tmwseo-keyword-row-p1:not(:disabled)\').forEach(function(c){c.checked=true;});">' . esc_html(__('Select all P1', 'tmwseo')) . '</button> ';
        echo '<button type="button" class="button" onclick="document.querySelectorAll(\'.tmwseo-keyword-row-golden:not(:disabled)\').forEach(function(c){c.checked=true;});">' . esc_html(__('Select all Golden', 'tmwseo')) . '</button> ';
        echo '<button type="button" class="button" onclick="document.querySelectorAll(\'.tmwseo-keyword-row-approve:not(:disabled)\').forEach(function(c){c.checked=true;});">' . esc_html(__('Select all Approve Candidates', 'tmwseo')) . '</button> ';
        echo '<button type="button" class="button" onclick="document.querySelectorAll(\'.tmwseo-keyword-row-select\').forEach(function(c){c.checked=false;});">' . esc_html(__('Clear selection', 'tmwseo')) . '</button></p>';
        echo '<p><label for="tmwseo_keyword_pool_save_mode"><strong>' . esc_html(__('Save selected as:', 'tmwseo')) . '</strong></label> ';
        echo '<select id="tmwseo_keyword_pool_save_mode" name="tmwseo_keyword_pool_save_mode">';
        echo '<option value="auto">' . esc_html(__('Auto by recommendation', 'tmwseo')) . '</option>';
        echo '<option value="queued_for_review">' . esc_html(__('queued_for_review', 'tmwseo')) . '</option>';
        echo '<option value="approved">' . esc_html(__('approved', 'tmwseo')) . '</option>';
        echo '</select></p>';
        echo '<div style="overflow:auto;max-width:100%;"><table class="widefat striped"><thead><tr><th>' . esc_html(__('Save?', 'tmwseo')) . '</th>';
        foreach (self::preview_columns() as $header) {
            echo '<th>' . esc_html($header) . '</th>';
        }
        echo '</tr></thead><tbody>';
        $import_service = new KeywordPoolSelectedImportService();
        $url_column_index = (int) array_search('URL', self::preview_columns(), true);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $eligible = $import_service->is_row_eligible($row, $pool);
            $classes = [ 'tmwseo-keyword-row-select' ];
            if ($eligible && 'TMW-P1' === (string) ($row['tmw_priority'] ?? '')) { $classes[] = 'tmwseo-keyword-row-p1'; }
            if ($eligible && !empty($row['is_golden_keyword']) && !in_array((string) ($row['tmw_indexing_readiness'] ?? ''), [ 'defer_until_lj_50_model_milestone', 'archive_do_not_use' ], true)) { $classes[] = 'tmwseo-keyword-row-golden'; }
            if ($eligible && 'approve_for_phase_1' === (string) ($row['tmw_recommended_action'] ?? '')) { $classes[] = 'tmwseo-keyword-row-approve'; }
            echo '<tr><td>';
            echo '<input type="checkbox" class="' . esc_attr(implode(' ', $classes)) . '" name="tmwseo_keyword_pool_selected_rows[]" value="' . esc_attr((string) (int) ($row['row_number'] ?? 0)) . '" ' . disabled(!$eligible, true, false) . ' />';
            echo '</td>';
            foreach (self::row_to_preview_values($row) as $index => $value) {
                if ($url_column_index === $index && '' !== $value) {
                    echo '<td><a href="' . esc_url($value) . '" target="_blank" rel="noopener noreferrer">' . esc_html($value) . '</a></td>';
                } else {
                    echo '<td>' . esc_html($value) . '</td>';
                }
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<div class="notice notice-warning inline"><p><strong>' . esc_html(__('Operational safety:', 'tmwseo')) . '</strong> ' . esc_html(__('Saving selected keywords stores them in the review pool only. It does not write Rank Math, does not change content, does not change slugs, does not call Generate, and does not change indexing/noindex.', 'tmwseo')) . '</p></div>';
        echo '<p><button type="submit" class="button button-primary" name="tmwseo_keyword_pools_save_selected" value="1">' . esc_html(__('Save Selected Keywords', 'tmwseo')) . '</button>';
        if ('model' === $pool) {
            echo ' <button type="submit" class="button button-secondary" name="tmwseo_keyword_pools_save_full_model_batch" value="1">' . esc_html(__('Save Full Reviewed Model Keyword Batch', 'tmwseo')) . '</button>';
            echo ' <span class="description">' . esc_html(__('Stores all useful non-footer model rows with approved, queued, rejected, or ignored status based on the dry-run review.', 'tmwseo')) . '</span>';
        }
        if ('category' === $pool) {
            echo ' <button type="submit" class="button button-secondary" name="tmwseo_keyword_pools_save_full_category_batch" value="1">' . esc_html(__('Save Full Reviewed Category Keyword Batch', 'tmwseo')) . '</button>';
            echo ' <span class="description">' . esc_html(__('Stores all useful reviewed category rows in the keyword candidate pool only. It does not write Rank Math, does not change content, does not change slugs, does not call Generate, and does not change indexing/noindex.', 'tmwseo')) . '</span>';
        }
        echo '</p>';
        echo '</form>';
    }

    /**
     * @param array<string,mixed> $import_result Import result.
     */
    private static function render_import_result(array $import_result): void {
        $summary = is_array($import_result['summary'] ?? null) ? $import_result['summary'] : [];
        echo '<h2>' . esc_html(__('Import Result', 'tmwseo')) . '</h2>';
        echo '<p>' . esc_html(sprintf('Selected: %d. Inserted: %d. Updated: %d. Skipped: %d. Conflicts: %d. Blocked: %d. Errors: %d. Linked: %d. Unresolved: %d. Ambiguous: %d.', (int) ($summary['selected'] ?? 0), (int) ($summary['inserted'] ?? 0), (int) ($summary['updated'] ?? 0), (int) ($summary['skipped'] ?? 0), (int) ($summary['conflicts'] ?? 0), (int) ($summary['blocked'] ?? 0), (int) ($summary['errors'] ?? 0), (int) ($summary['linked_model_entities'] ?? 0), (int) ($summary['unresolved_model_entities'] ?? 0), (int) ($summary['ambiguous_model_entities'] ?? 0))) . '</p>';
        $rows = is_array($import_result['rows'] ?? null) ? $import_result['rows'] : [];
        if ([] === $rows) {
            return;
        }

        $operator_summary = self::import_operator_summary($rows, $summary);
        echo '<div class="tmwui-kpi-row" style="margin:0 0 12px;" data-tmw-debug="TMW-SEO-KEYWORD-SIMPLE-VIEW">';
        foreach ($operator_summary as $label => $count) {
            echo '<div class="tmwui-kpi-card" style="min-width:120px;"><strong>' . esc_html((string) $count) . '</strong><span>' . esc_html($label) . '</span></div>';
        }
        echo '</div>';

        echo '<table class="widefat striped" data-tmw-debug="TMW-SEO-KEYWORD-SIMPLE-VIEW"><thead><tr>';
        foreach ([
            __( 'Keyword', 'tmwseo' ),
            __( 'Volume', 'tmwseo' ),
            __( 'Status', 'tmwseo' ),
            __( 'Model', 'tmwseo' ),
            __( 'Action / Result', 'tmwseo' ),
        ] as $header) { echo '<th>' . esc_html($header) . '</th>'; }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['keyword'] ?? '')) . '</td>';
            echo '<td>' . esc_html(self::metric_to_string($row['volume'] ?? '')) . '</td>';
            $display_status = 'blocked' === strtolower((string) ($row['action'] ?? '')) ? 'blocked' : (string) ($row['status'] ?? '');
            echo '<td>' . self::status_badge($display_status) . '</td>';
            echo '<td>' . esc_html(self::import_row_model_label($row)) . '</td>';
            $action_text = trim((string) ($row['action'] ?? '') . ('' !== (string) ($row['reason'] ?? '') ? ' — ' . (string) $row['reason'] : ''));
            if ('' !== (string) ($row['existing_target_name'] ?? '')) {
                $action_text .= ' — Existing target: ' . (string) $row['existing_target_name'];
            }
            echo '<td>' . esc_html($action_text) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<details style="margin-top:12px;"><summary class="button button-secondary" style="display:inline-block;">' . esc_html__('Show technical details', 'tmwseo') . '</summary>';
        $headers = [
            __( 'Keyword', 'tmwseo' ),
            __( 'Pool', 'tmwseo' ),
            __( 'Status', 'tmwseo' ),
            __( 'Action', 'tmwseo' ),
            __( 'Reason', 'tmwseo' ),
            __( 'Volume', 'tmwseo' ),
            __( 'CPC', 'tmwseo' ),
            __( 'Competition', 'tmwseo' ),
            __( 'SEO Score', 'tmwseo' ),
            __( 'Traffic Value', 'tmwseo' ),
            __( 'Entity Type', 'tmwseo' ),
            __( 'Entity ID', 'tmwseo' ),
            __( 'Existing Target', 'tmwseo' ),
        ];
        echo '<table class="widefat striped" style="margin-top:12px;"><thead><tr>';
        foreach ($headers as $header) { echo '<th>' . esc_html($header) . '</th>'; }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            echo '<tr>';
            foreach ([ 'keyword', 'pool', 'status', 'action', 'reason', 'volume', 'cpc', 'competition', 'seo_score', 'traffic_value', 'entity_type', 'entity_id' ] as $key) {
                echo '<td>' . esc_html(self::metric_to_string($row[$key] ?? '')) . '</td>';
            }
            echo '<td>' . esc_html((string) ($row['existing_target_name'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></details>';
    }

    /** @param array<int,mixed> $rows @param array<string,mixed> $summary @return array<string,int> */
    private static function import_operator_summary(array $rows, array $summary): array {
        $counts = [
            __('Total rows', 'tmwseo') => 0,
            __('Approved', 'tmwseo') => 0,
            __('Queued for review', 'tmwseo') => 0,
            __('Rejected/Ignored', 'tmwseo') => 0,
            __('Blocked', 'tmwseo') => 0,
            __('Linked', 'tmwseo') => (int) ($summary['linked_model_entities'] ?? 0),
            __('Errors', 'tmwseo') => 0,
        ];
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $counts[__('Total rows', 'tmwseo')]++;
            $status = strtolower((string) ($row['status'] ?? ''));
            if ('approved' === $status) { $counts[__('Approved', 'tmwseo')]++; }
            elseif ('queued_for_review' === $status) { $counts[__('Queued for review', 'tmwseo')]++; }
            elseif (in_array($status, [ 'rejected', 'ignored' ], true)) { $counts[__('Rejected/Ignored', 'tmwseo')]++; }
            if ('blocked' === strtolower((string) ($row['action'] ?? ''))) { $counts[__('Blocked', 'tmwseo')]++; }
            if ('error' === strtolower((string) ($row['action'] ?? ''))) { $counts[__('Errors', 'tmwseo')]++; }
        }
        return $counts;
    }

    /** @param array<string,mixed> $row */
    private static function import_row_model_label(array $row): string {
        foreach ([ 'model_name', 'model_keyword_owner', 'model', 'owner' ] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ('' !== $value) { return $value; }
        }
        return 'model' === (string) ($row['pool'] ?? '') ? '—' : (string) ($row['pool'] ?? '—');
    }

    private static function status_badge(string $status): string {
        $normalized = strtolower($status);
        $styles = [
            'approved' => 'display:inline-block;padding:2px 8px;border-radius:999px;background:#dcfce7;color:#166534;font-weight:600;',
            'queued_for_review' => 'display:inline-block;padding:2px 8px;border-radius:999px;background:#fef3c7;color:#92400e;font-weight:600;',
            'rejected' => 'display:inline-block;padding:2px 8px;border-radius:999px;background:#fee2e2;color:#991b1b;font-weight:600;',
            'ignored' => 'display:inline-block;padding:2px 8px;border-radius:999px;background:#f3f4f6;color:#6b7280;font-weight:600;',
            'blocked' => 'display:inline-block;padding:2px 8px;border-radius:999px;background:#fee2e2;color:#991b1b;font-weight:700;',
        ];
        $style = $styles[$normalized] ?? 'display:inline-block;padding:2px 8px;border-radius:999px;background:#f3f4f6;color:#374151;font-weight:600;';
        return '<span style="' . esc_attr($style) . '">' . esc_html($status) . '</span>';
    }

    /**
     * @param array<string, mixed> $row Preview row.
     * @return array<int, string>
     */
    private static function row_to_preview_values(array $row): array {
        $reasons = is_array($row['reason_codes'] ?? null) ? implode(' | ', self::exportable_reason_codes($row)) : (string) ($row['reason_summary'] ?? '');
        $golden_missing_reasons = is_array($row['golden_missing_reasons'] ?? null) ? implode(' | ', array_map('strval', $row['golden_missing_reasons'])) : (string) ($row['golden_missing_reasons'] ?? '');
        return [
            (string) ($row['row_index'] ?? ''),
            (string) ($row['keyword'] ?? ''),
            (string) ($row['normalized_keyword'] ?? ''),
            (string) ($row['status_preview'] ?? ''),
            (string) ($row['validation_state'] ?? ''),
            (string) ($row['decision'] ?? ''),
            (string) ($row['priority_preview'] ?? ''),
            !empty($row['is_golden_keyword']) ? 'yes' : 'no',
            $golden_missing_reasons,
            (string) ($row['golden_formula_summary'] ?? ''),
            (string) ($row['recommended_action'] ?? ''),
            self::metric_to_string($row['tmw_score'] ?? null),
            (string) ($row['tmw_priority'] ?? ''),
            (string) ($row['tmw_difficulty_band'] ?? ''),
            (string) ($row['tmw_commercial_band'] ?? ''),
            (string) ($row['tmw_indexing_readiness'] ?? ''),
            (string) ($row['tmw_recommended_action'] ?? ''),
            (string) ($row['model_keyword_strategy'] ?? ''),
            (string) ($row['model_keyword_confidence'] ?? ''),
            is_array($row['model_keyword_reason_codes'] ?? null) ? implode(' | ', array_map('strval', $row['model_keyword_reason_codes'])) : '',
            (string) ($row['model_keyword_recommended_action'] ?? ''),
            (string) ($row['model_keyword_owner'] ?? ''),
            (string) ($row['model_keyword_usage_scope'] ?? ''),
            (string) ($row['model_keyword_primary_candidate'] ?? ''),
            is_array($row['model_keyword_scope_reason_codes'] ?? null) ? implode(' | ', array_map('strval', $row['model_keyword_scope_reason_codes'])) : '',
            is_array($row['tmw_reason_codes'] ?? null) ? implode(' | ', array_map('strval', $row['tmw_reason_codes'])) : '',
            (string) ($row['tmw_score_formula'] ?? ''),
            $reasons,
            self::metric_to_string($row['volume'] ?? null),
            self::metric_to_string($row['difficulty'] ?? null),
            self::metric_to_string($row['cpc'] ?? null),
            self::metric_to_string($row['competition'] ?? null),
            self::metric_to_string($row['seo_score'] ?? null),
            self::metric_to_string($row['traffic_value'] ?? null),
            (string) ($row['trend'] ?? ''),
            self::metric_to_string($row['ad_difficulty'] ?? null),
            (string) ($row['intent'] ?? ''),
            (string) ($row['source'] ?? ''),
            (string) ($row['model_name'] ?? $row['model_keyword_owner'] ?? ''),
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
        $reason_codes = is_array($row['reason_codes'] ?? null) ? implode('|', self::exportable_reason_codes($row)) : '';
        return array_merge(self::row_to_preview_values($row), [ $reason_codes ]);
    }

    /**
     * @param array<string, mixed> $row Preview row.
     * @return array<int, string>
     */
    private static function exportable_reason_codes(array $row): array {
        $reason_codes = is_array($row['reason_codes'] ?? null) ? array_map('strval', $row['reason_codes']) : [];
        if (self::is_blank_optional_metric($row['ad_difficulty'] ?? null)) {
            $reason_codes = array_values(array_filter(
                $reason_codes,
                static fn(string $reason): bool => 'invalid_ad_difficulty' !== $reason
            ));
        }

        return $reason_codes;
    }

    private static function is_blank_optional_metric($value): bool {
        if (null === $value) {
            return true;
        }
        if (is_float($value) && is_nan($value)) {
            return true;
        }

        $value = (string) $value;
        $value = preg_replace('/[\p{Z}\s]+/u', ' ', $value) ?? $value;
        $value = strtolower(trim($value));

        return '' === $value || in_array($value, [ 'nan', 'n/a', 'na', 'null' ], true);
    }


    /** @param array<string,mixed> $payload */
    private static function encode_signed_payload(array $payload): string {
        $json = (string) wp_json_encode($payload);
        $body = base64_encode($json);
        $signature = hash_hmac('sha256', $body, self::payload_secret());
        return $body . '.' . $signature;
    }

    /** @return array<string,mixed>|null */
    private static function decode_signed_payload(string $payload): ?array {
        $parts = explode('.', $payload, 2);
        if (2 !== count($parts)) {
            return null;
        }
        [ $body, $signature ] = $parts;
        $expected = hash_hmac('sha256', $body, self::payload_secret());
        if (!hash_equals($expected, $signature)) {
            return null;
        }
        $decoded = json_decode(base64_decode($body, true) ?: '', true);
        if (!is_array($decoded)) {
            return null;
        }
        $generated_at = (int) ($decoded['generated_at'] ?? 0);
        if ($generated_at > 0 && (time() - $generated_at) > HOUR_IN_SECONDS) {
            return null;
        }
        return $decoded;
    }

    private static function payload_secret(): string {
        if (function_exists('wp_salt')) {
            return wp_salt('auth') . self::NONCE_ACTION;
        }
        return self::NONCE_ACTION;
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
