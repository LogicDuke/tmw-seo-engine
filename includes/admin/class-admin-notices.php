<?php
declare(strict_types=1);

namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Schema;
use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Integrations\GoogleAdsKeywordPlannerApi;

if (!defined('ABSPATH')) { exit; }

/**
 * AdminNotices — `admin_notices` hook target + pipeline-health renderers.
 *
 * Extracted from class-admin.php as the second concrete step of the god-
 * class decomposition. The audit explicitly named `render_admin_notices`
 * (303 lines, cyclomatic complexity 67) as a top offender; this PR
 * relocates it intact so the diff is reviewable as a pure extraction.
 * A natural follow-up — once the relocation is settled — is to break the
 * giant if/elseif notice router into one helper per `tmwseo_notice` value.
 *
 * Hook surface (registered from Admin::init()):
 *   add_action('admin_notices', [AdminNotices::class, 'render']);
 *
 * Public API:
 *   AdminNotices::render() — main entry point, formerly Admin::render_admin_notices()
 *
 * Internal renderers (kept private; called only from render()):
 *   render_pipeline_health_notices()  — DataForSEO budget, circuit breakers, provider availability
 *   render_settings_integrity_notice() — partial-save warnings on the Settings page
 */
class AdminNotices {

    /**
     * Main admin-notices entry point. Decides which notice flavor to
     * render based on the current admin page + the `tmwseo_notice`
     * query-string parameter.
     *
     * The body below is the verbatim pre-extraction implementation
     * (formerly Admin::render_admin_notices) — relocated only, not
     * refactored. The internal decomposition into one-handler-per-
     * notice-key methods is the natural next-PR follow-up.
     */
    public static function render(): void {
        if (class_exists('TMWSEO\Engine\Schema') && method_exists('TMWSEO\Engine\Schema', 'get_missing_required_intelligence_tables')) {
            $missing_tables = Schema::get_missing_required_intelligence_tables();
            if (!empty($missing_tables)) {
                echo '<div class="notice notice-warning"><p>';
                echo esc_html__('Schema mismatch detected: one or more required intelligence tables are missing.', 'tmwseo');
                echo '</p></div>';
            }
        }

        // Pipeline health notices — only on TMW SEO admin pages.
        $page = sanitize_text_field((string) ($_GET['page'] ?? ''));
        if (strpos($page, 'tmwseo') === 0 || strpos($page, 'tmw-') === 0) {
            self::render_pipeline_health_notices();
        }

        // Settings integrity notice — on settings page only.
        if ($page === 'tmwseo-settings') {
            self::render_settings_integrity_notice();
        }

        if (!isset($_GET['tmwseo_notice'])) {
            return;
        }

        $notice = sanitize_text_field(wp_unslash((string) $_GET['tmwseo_notice']));
        $message = '';
        if ($notice === 'optimize_queued') {
            $message = __('Optimization queued. The worker/cron will process this post in the background.', 'tmwseo');
        } elseif ($notice === 'keywords_refresh_queued') {
            $message = __('Keyword refresh queued. The worker/cron will update keyword pack and RankMath fields in the background.', 'tmwseo');
        } elseif ($notice === 'draft_preview_generated') {
            $message = __('Draft content preview generated in preview metadata only. No post content was changed and nothing was published automatically.', 'tmwseo');
        } elseif ($notice === 'draft_preview_refused') {
            $message = __('Draft content preview was refused. This action is allowed only for explicit draft posts.', 'tmwseo');
        } elseif ($notice === 'draft_preview_applied') {
            $message = __('Reviewed preview fields were manually applied to this draft only. Nothing was published, live content was not changed, and noindex was not cleared automatically.', 'tmwseo');
        } elseif ($notice === 'draft_preview_apply_refused') {
            $message = __('Manual apply from preview was refused. This action requires an explicit draft and at least one selected preview field.', 'tmwseo');
        } elseif ($notice === 'review_bundle_prepared') {
            $message = __('Prepared for human review. Nothing has been applied automatically. Draft remains draft-only/noindex and requires manual review + manual apply.', 'tmwseo');
        } elseif ($notice === 'review_bundle_refused') {
            $message = __('Prepare for Human Review was refused. This action is allowed only for explicit operator-created draft posts.', 'tmwseo');
        } elseif ($notice === 'traffic_pages_generated') {
            $created = isset($_GET['tmwseo_created']) ? (int) $_GET['tmwseo_created'] : 0;
            $skipped = isset($_GET['tmwseo_skipped']) ? (int) $_GET['tmwseo_skipped'] : 0;
            $message = sprintf(
                __('Traffic page generation complete. Created: %1$d, Skipped: %2$d.', 'tmwseo'),
                $created,
                $skipped
            );
        } elseif ($notice === 'discovery_data_reset') {
            $keywords_deleted = isset($_GET['tmwseo_keywords_deleted']) ? max(0, (int) $_GET['tmwseo_keywords_deleted']) : 0;
            $clusters_deleted = isset($_GET['tmwseo_clusters_deleted']) ? max(0, (int) $_GET['tmwseo_clusters_deleted']) : 0;
            $suggestions_deleted = isset($_GET['tmwseo_suggestions_deleted']) ? max(0, (int) $_GET['tmwseo_suggestions_deleted']) : 0;
            $message = sprintf(
                __('Discovery data reset complete. Deleted rows — keywords: %1$d, clusters: %2$d, suggestions: %3$d.', 'tmwseo'),
                $keywords_deleted,
                $clusters_deleted,
                $suggestions_deleted
            );
        } elseif ($notice === 'model_seeds_generated') {
            $models_processed = isset($_GET['tmwseo_models_processed']) ? max(0, (int) $_GET['tmwseo_models_processed']) : 0;
            $seeds_created = isset($_GET['tmwseo_seeds_created']) ? max(0, (int) $_GET['tmwseo_seeds_created']) : 0;
            $message = sprintf(
                __('Model seed generation complete. Models processed: %1$d, Seeds created: %2$d.', 'tmwseo'),
                $models_processed,
                $seeds_created
            );
        } elseif ($notice === 'candidate_updated') {
            $candidate_id = isset($_GET['tmwseo_candidate_id']) ? absint($_GET['tmwseo_candidate_id']) : 0;
            $status = isset($_GET['tmwseo_candidate_status'])
                ? sanitize_key((string) wp_unslash($_GET['tmwseo_candidate_status']))
                : '';
            $message = sprintf(
                __('Candidate #%1$d updated to status: %2$s.', 'tmwseo'),
                $candidate_id,
                $status
            );
        } elseif ($notice === 'candidate_not_found') {
            $message = __('Candidate update skipped. The selected row was not found or unchanged.', 'tmwseo');
        } elseif ($notice === 'candidate_invalid_request') {
            $message = __('Candidate action failed. Invalid request.', 'tmwseo');
        } elseif ($notice === 'candidate_invalid_nonce') {
            $message = __('Candidate action failed. Security check did not pass.', 'tmwseo');
        } elseif ($notice === 'candidate_action_unauthorized') {
            $message = __('Candidate action failed. You are not allowed to do that.', 'tmwseo');
        } elseif ($notice === 'candidate_update_failed') {
            $message = __('Candidate action failed due to a database error.', 'tmwseo');
        } elseif ($notice === 'seo_engine_cycle_executed' || $notice === 'keyword_cycle_ran') {
            $message = __('SEO Engine keyword cycle executed synchronously. Check the Debug Dashboard for results.', 'tmwseo');
        } elseif ( $notice === 'kw_metrics_enrichment_completed' ) {
            $checked = isset($_GET['tmwseo_kw_checked']) ? max(0, (int) $_GET['tmwseo_kw_checked']) : 0;
            $updated = isset($_GET['tmwseo_kw_updated']) ? max(0, (int) $_GET['tmwseo_kw_updated']) : 0;
            $skipped = isset($_GET['tmwseo_kw_skipped']) ? max(0, (int) $_GET['tmwseo_kw_skipped']) : 0;
            $dfseo_reason = isset($_GET['tmwseo_kw_dfseo_reason']) ? sanitize_text_field((string) wp_unslash($_GET['tmwseo_kw_dfseo_reason'])) : '';
            $dfseo_called = isset($_GET['tmwseo_kw_dfseo_called']) ? (int) $_GET['tmwseo_kw_dfseo_called'] : 0;
            $dfseo_exact_called = isset($_GET['tmwseo_kw_dfseo_exact_called']) ? (int) $_GET['tmwseo_kw_dfseo_exact_called'] : 0;
            $dfseo_volume_count = isset($_GET['tmwseo_kw_dfseo_volume_count']) ? max(0, (int) $_GET['tmwseo_kw_dfseo_volume_count']) : 0;
            $dfseo_cpc_count = isset($_GET['tmwseo_kw_dfseo_cpc_count']) ? max(0, (int) $_GET['tmwseo_kw_dfseo_cpc_count']) : 0;
            $dfseo_usable_kd = isset($_GET['tmwseo_kw_dfseo_usable_kd']) ? max(0, (int) $_GET['tmwseo_kw_dfseo_usable_kd']) : 0;
            $dfseo_empty_map = isset($_GET['tmwseo_kw_dfseo_empty_map']) ? (int) $_GET['tmwseo_kw_dfseo_empty_map'] : 0;
            $gkp_called = isset($_GET['tmwseo_kw_gkp_called']) ? (int) $_GET['tmwseo_kw_gkp_called'] : 0;
            $gkp_usable_volume = isset($_GET['tmwseo_kw_gkp_usable_volume']) ? max(0, (int) $_GET['tmwseo_kw_gkp_usable_volume']) : 0;
            $skip_reasons = isset($_GET['tmwseo_kw_skip_reasons']) ? json_decode((string) wp_unslash($_GET['tmwseo_kw_skip_reasons']), true) : [];
            $skip_reasons_text = '';
            if ( is_array( $skip_reasons ) && ! empty( $skip_reasons ) ) {
                $pairs = [];
                foreach ( $skip_reasons as $reason => $count ) {
                    $pairs[] = sanitize_key( (string) $reason ) . ':' . max( 0, (int) $count );
                }
                $skip_reasons_text = implode( ', ', $pairs );
            }
            $message = sprintf(
                __('Keyword metric enrichment completed. Rows checked: %1$d, updated: %2$d, skipped: %3$d. DataForSEO exact metrics called: %4$s. DataForSEO volume count: %5$d. DataForSEO KD count: %6$d. DataForSEO CPC count: %7$d. DataForSEO empty map: %8$s, status: %9$s. GKP called: %10$s, usable volume count: %11$d. Rows no-data: %12$d. Skipped reasons: %13$s.', 'tmwseo'),
                $checked,
                $updated,
                $skipped,
                $dfseo_exact_called ? 'yes' : ( $dfseo_called ? 'legacy_yes' : 'no' ),
                $dfseo_volume_count,
                $dfseo_usable_kd,
                $dfseo_cpc_count,
                $dfseo_empty_map ? 'yes' : 'no',
                $dfseo_reason !== '' ? $dfseo_reason : 'ok',
                $gkp_called ? 'yes' : 'no',
                $gkp_usable_volume,
                $skipped,
                $skip_reasons_text !== '' ? $skip_reasons_text : 'none'
            );
        } elseif ( $notice === 'kw_force_recheck_completed' ) {
            $checked            = isset($_GET['tmwseo_kw_checked'])            ? max(0, (int) $_GET['tmwseo_kw_checked'])            : 0;
            $updated            = isset($_GET['tmwseo_kw_updated'])            ? max(0, (int) $_GET['tmwseo_kw_updated'])            : 0;
            $skipped            = isset($_GET['tmwseo_kw_skipped'])            ? max(0, (int) $_GET['tmwseo_kw_skipped'])            : 0;
            $dfseo_reason       = isset($_GET['tmwseo_kw_dfseo_reason'])       ? sanitize_text_field((string) wp_unslash($_GET['tmwseo_kw_dfseo_reason']))       : '';
            $dfseo_exact_called = isset($_GET['tmwseo_kw_dfseo_exact_called']) ? (int) $_GET['tmwseo_kw_dfseo_exact_called'] : 0;
            $dfseo_called       = isset($_GET['tmwseo_kw_dfseo_called'])       ? (int) $_GET['tmwseo_kw_dfseo_called']       : 0;
            $dfseo_volume_count = isset($_GET['tmwseo_kw_dfseo_volume_count']) ? max(0, (int) $_GET['tmwseo_kw_dfseo_volume_count']) : 0;
            $dfseo_cpc_count    = isset($_GET['tmwseo_kw_dfseo_cpc_count'])    ? max(0, (int) $_GET['tmwseo_kw_dfseo_cpc_count'])    : 0;
            $dfseo_usable_kd    = isset($_GET['tmwseo_kw_dfseo_usable_kd'])    ? max(0, (int) $_GET['tmwseo_kw_dfseo_usable_kd'])    : 0;
            $dfseo_empty_map    = isset($_GET['tmwseo_kw_dfseo_empty_map'])    ? (int) $_GET['tmwseo_kw_dfseo_empty_map']    : 0;
            $dfseo_task_status  = isset($_GET['tmwseo_kw_dfseo_task_status'])  ? (int) $_GET['tmwseo_kw_dfseo_task_status']  : 0;
            $dfseo_task_msg     = isset($_GET['tmwseo_kw_dfseo_task_msg'])     ? sanitize_text_field((string) wp_unslash($_GET['tmwseo_kw_dfseo_task_msg']))     : '';
            $dfseo_result_count = isset($_GET['tmwseo_kw_dfseo_result_count']) ? max(0, (int) $_GET['tmwseo_kw_dfseo_result_count']) : 0;
            $dfseo_parser_path  = isset($_GET['tmwseo_kw_dfseo_parser_path'])  ? sanitize_text_field((string) wp_unslash($_GET['tmwseo_kw_dfseo_parser_path']))  : '';
            $dfseo_cache_hit    = isset($_GET['tmwseo_kw_dfseo_cache_hit'])    ? (int) $_GET['tmwseo_kw_dfseo_cache_hit']    : 0;
            $gkp_called         = isset($_GET['tmwseo_kw_gkp_called'])         ? (int) $_GET['tmwseo_kw_gkp_called']         : 0;
            $gkp_usable_volume  = isset($_GET['tmwseo_kw_gkp_usable_volume'])  ? max(0, (int) $_GET['tmwseo_kw_gkp_usable_volume'])  : 0;
            $skip_reasons       = isset($_GET['tmwseo_kw_skip_reasons'])       ? json_decode((string) wp_unslash($_GET['tmwseo_kw_skip_reasons']), true) : [];
            $skip_reasons_text  = '';
            if ( is_array( $skip_reasons ) && ! empty( $skip_reasons ) ) {
                $parts = [];
                foreach ( $skip_reasons as $reason => $cnt ) {
                    $parts[] = sanitize_key( (string) $reason ) . ':' . max( 0, (int) $cnt );
                }
                $skip_reasons_text = implode( ', ', $parts );
            }

            // Build human-readable diagnostic labels.
            $http_ok_label     = $dfseo_exact_called ? 'yes' : ( $dfseo_called ? 'yes (legacy)' : 'no' );
            $task_status_label = $dfseo_task_status > 0
                ? $dfseo_task_status . ( $dfseo_task_msg !== '' ? ' ' . $dfseo_task_msg : '' )
                : ( $dfseo_reason !== '' ? $dfseo_reason : ( $checked === 0 ? 'no_candidates' : 'ok' ) );
            $parsed_total      = $dfseo_volume_count + $dfseo_usable_kd + $dfseo_cpc_count;
            $parser_empty_label = $dfseo_empty_map
                ? ( $dfseo_result_count > 0 ? 'yes — parser shape mismatch (result_count=' . $dfseo_result_count . ')' : 'yes — provider returned no data' )
                : 'no';
            $cache_hit_label   = $dfseo_cache_hit ? 'yes (stale transient was returned)' : 'no (live API call)';

            if ( $checked === 0 ) {
                $notice_type = 'notice-warning';
                $message = sprintf(
                    __( '[FORCE RECHECK] No eligible candidates found. All status=new rows with volume=0 or difficulty=0 are already enriched, or no rows with status=new exist.', 'tmwseo' )
                );
            } else {
                $notice_type = $updated > 0 ? 'notice-success' : 'notice-info';
                $message = sprintf(
                    __( '[FORCE RECHECK] Keyword metric enrichment completed. Force recheck: yes. Rows checked: %1$d. Rows updated: %2$d. Rows no-data: %3$d. DataForSEO HTTP ok: %4$s. DataForSEO task status: %5$s. DataForSEO result count: %6$d. DataForSEO parsed metric count: %7$d (volume: %8$d, KD: %9$d, CPC: %10$d). Parser empty: %11$s. Cache hit: %12$s. GKP called: %13$s, usable volume: %14$d. Skipped reasons: %15$s.', 'tmwseo' ),
                    $checked,
                    $updated,
                    $skipped,
                    $http_ok_label,
                    $task_status_label,
                    $dfseo_result_count,
                    $parsed_total,
                    $dfseo_volume_count,
                    $dfseo_usable_kd,
                    $dfseo_cpc_count,
                    $parser_empty_label,
                    $cache_hit_label,
                    $gkp_called ? 'yes' : 'no',
                    $gkp_usable_volume,
                    $skip_reasons_text !== '' ? $skip_reasons_text : 'none'
                );
            }
            echo '<div class="notice ' . esc_attr( $notice_type ?? 'notice-info' ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
        } elseif ($notice === 'keyword_cycle_queued_worker_dead') {
            $message = __('Keyword cycle job was queued but the background worker (tmwseo_worker_tick) is not scheduled. The job will not process until the worker is kicked manually from Debug Dashboard → Tools.', 'tmwseo');
            $is_error_notice_override = true;
        } elseif ($notice === 'legacy_save_blocked') {
            $message = __('A legacy settings save action was intercepted and blocked to prevent data loss. Settings were NOT changed. Use the Settings page to save.', 'tmwseo');
            $is_error_notice_override = true;
        } elseif ($notice === 'candidate_action_not_available') {
            $message = __('Candidate action skipped. This row is already in a final status for that action.', 'tmwseo');
        } elseif ($notice === 'niche_mining_ran') {
            $transient_key = 'tmwseo_niche_mining_result_' . get_current_user_id();
            $result = get_transient($transient_key);
            delete_transient($transient_key);
            if (is_array($result) && !empty($result['ok'])) {
                $message = sprintf(
                    __(
                        'Niche SERP mining complete. Phrases processed: %1$d / %2$d submitted. Domains seen: %3$d, selected: %4$d. Keyword rows mined: %5$d. Preview candidates inserted: %6$d, skipped: %7$d, filtered: %8$d. Est. cost: $%9$s.',
                        'tmwseo'
                    ),
                    (int) ($result['phrases_processed']           ?? 0),
                    (int) ($result['phrases_submitted']           ?? 0),
                    (int) ($result['domains_seen']                ?? 0),
                    (int) ($result['domains_selected']            ?? 0),
                    (int) ($result['mined_keyword_rows']          ?? 0),
                    (int) ($result['inserted_preview_candidates'] ?? 0),
                    (int) ($result['skipped_duplicates']          ?? 0),
                    (int) ($result['filtered_out']                ?? 0),
                    number_format((float) ($result['estimated_cost_usd'] ?? 0), 4)
                );
            } elseif (is_array($result) && isset($result['error'])) {
                $message = sprintf(
                    __('Niche SERP mining could not run: %s', 'tmwseo'),
                    esc_html((string) $result['error'])
                );
                $is_error_notice_override = true;
            } else {
                $message = __('Niche SERP mining run completed. Check the preview candidate queue for results.', 'tmwseo');
            }
        } elseif ($notice === 'review_handoff_exported') {
            $message = __('Review handoff exported. The draft package has been prepared for external review. Nothing has been applied automatically and the draft remains draft-only.', 'tmwseo');
        } elseif ($notice === 'review_handoff_export_refused') {
            $message = __('Review handoff export was refused. This action is allowed only for explicit draft posts that have passed the review bundle safety check.', 'tmwseo');
            $is_error_notice_override = true;
        } elseif ($notice === 'review_signoff_updated') {
            $message = __('Review sign-off recorded. No content was auto-applied and nothing was published automatically. The draft remains draft-only until a manual apply is performed.', 'tmwseo');
        } elseif ($notice === 'review_signoff_refused') {
            $message = __('Review sign-off was refused. This action is allowed only for explicit draft posts with a valid review bundle. No changes were made.', 'tmwseo');
            $is_error_notice_override = true;
        } elseif ($notice === 'model_preview_rerun') {
            $inserted = isset($_GET['tmwseo_preview_inserted']) ? max(0, (int) $_GET['tmwseo_preview_inserted']) : 0;
            $skipped  = isset($_GET['tmwseo_preview_skipped'])  ? max(0, (int) $_GET['tmwseo_preview_skipped'])  : 0;
            $message  = sprintf(
                __('Preview phrases rebuilt for this model. Inserted: %1$d, skipped: %2$d.', 'tmwseo'),
                $inserted,
                $skipped
            );
        } elseif ($notice === 'model_preview_rerun_failed') {
            $reason = isset($_GET['tmwseo_rerun_reason'])
                ? sanitize_key((string) wp_unslash($_GET['tmwseo_rerun_reason']))
                : '';
            $reason_labels = [
                'invalid_post'    => __('Preview phrase re-run failed: invalid model post.', 'tmwseo'),
                'wrong_post_type' => __('Preview phrase re-run failed: post is not a model.', 'tmwseo'),
                'not_published'   => __('Preview phrase re-run failed: model is not published.', 'tmwseo'),
                'empty_title'     => __('Preview phrase re-run failed: model title is empty.', 'tmwseo'),
                'invalid_nonce'   => __('Preview phrase re-run failed: security check did not pass.', 'tmwseo'),
            ];
            $message = $reason_labels[$reason] ?? __('Preview phrase re-run failed.', 'tmwseo');
            $is_error_notice_override = true;
        } elseif ($notice === 'scan_complete') {
            $message = __('Paid scan completed. Review the post-scan summary below.', 'tmwseo');
        } elseif ($notice === 'scan_rejected') {
            $message = __('Paid scan was not started. Confirm acknowledgement and valid post ID.', 'tmwseo');
            $is_error_notice_override = true;
        } elseif ($notice === 'task_cap_exceeded') {
            $message = __('Paid scan was blocked because the planned task count exceeds the manual safety cap. Reduce endpoints/seeds or use a smaller test scan.', 'tmwseo');
            $is_error_notice_override = true;
        } elseif ($notice === 'scan_failed') {
            $message = __('Paid scan failed before completion. Review logs and scan summary for details.', 'tmwseo');
            $is_error_notice_override = true;
        } elseif ($notice === 'scan_run_create_failed') {
            $message = __('Paid scan could not start because the scan run record could not be created. Check tmwseo_dfseo_scan_runs and database error logs.', 'tmwseo');
            $is_error_notice_override = true;
        } elseif ($notice === 'scan_ledger_tables_missing') {
            $message = __('DataForSEO scan ledger tables are missing. No paid scan was run. Re-run plugin schema migration.', 'tmwseo');
            $is_error_notice_override = true;
        }

        if ($message === '') {
            return;
        }

        $is_error_notice = isset($is_error_notice_override) ? $is_error_notice_override : in_array($notice, [
            'candidate_invalid_request',
            'candidate_invalid_nonce',
            'candidate_action_unauthorized',
            'candidate_update_failed',
            'candidate_action_not_available',
            'scan_rejected',
            'task_cap_exceeded',
            'scan_failed',
            'scan_run_create_failed',
            'scan_ledger_tables_missing',
        ], true);

        echo '<div class="notice ' . esc_attr($is_error_notice ? 'notice-error' : 'notice-success') . ' is-dismissible"><p>';
        echo esc_html($message);
        echo '</p></div>';
    }

    /**
     * Surface visible admin warnings for critical pipeline states.
     * Only called on TMW SEO pages. All checks are read-only.
     */
    private static function render_pipeline_health_notices(): void {
        // Use a short transient to avoid recomputing on every page load.
        $cache_key = 'tmwseo_pipeline_notices_v1';
        $cached = get_transient($cache_key);
        if ($cached === 'clean') {
            return; // No issues last time we checked.
        }
        if (is_array($cached)) {
            foreach ($cached as $msg) {
                echo '<div class="notice notice-warning is-dismissible"><p>' . wp_kses_post($msg) . '</p></div>';
            }
            return;
        }

        $warnings = [];

        // DataForSEO budget exceeded
        if (DataForSEO::is_configured() && DataForSEO::is_over_budget()) {
            $stats = DataForSEO::get_monthly_budget_stats();
            $warnings[] = sprintf(
                '<strong>TMW SEO:</strong> DataForSEO monthly budget exceeded ($%.2f / $%.2f). Keyword discovery via DataForSEO is paused. <a href="%s">Adjust budget →</a>',
                (float) ($stats['spent_usd'] ?? 0),
                (float) ($stats['budget_usd'] ?? 0),
                esc_url(admin_url('admin.php?page=tmwseo-settings'))
            );
        }

        // Discovery disabled
        if (!(bool) get_option('tmw_discovery_enabled', 1)) {
            $warnings[] = '<strong>TMW SEO:</strong> Keyword discovery is globally disabled (tmw_discovery_enabled=0). No keywords will be discovered.';
        }

        // Breaker active
        $breaker = get_option('tmw_keyword_engine_breaker', []);
        if (is_array($breaker) && !empty($breaker['last_triggered'])) {
            $cooldown_until = (int) $breaker['last_triggered'] + (15 * MINUTE_IN_SECONDS);
            if (time() < $cooldown_until) {
                $warnings[] = '<strong>TMW SEO:</strong> Keyword engine circuit breaker is active (3+ consecutive provider failures). Auto-clears in ' . human_time_diff(time(), $cooldown_until) . '.';
            }
        }

        // Settings integrity — critical keys missing
        $stored = get_option('tmwseo_engine_settings', []);
        $stored = is_array($stored) ? $stored : [];
        $critical = ['dataforseo_login', 'tmwseo_dataforseo_budget_usd', 'keyword_min_volume', 'keyword_max_kd', 'google_ads_enabled'];
        $missing = array_filter($critical, static fn($k) => !array_key_exists($k, $stored));
        if (!empty($missing)) {
            $warnings[] = sprintf(
                '<strong>TMW SEO:</strong> Critical settings keys are missing (%s). This may indicate a partial settings save. <a href="%s">Re-save Settings →</a>',
                esc_html(implode(', ', $missing)),
                esc_url(admin_url('admin.php?page=tmwseo-settings'))
            );
        }

        // No providers available
        $has_dfseo = DataForSEO::is_configured() && !DataForSEO::is_over_budget();
        $has_gads = GoogleAdsKeywordPlannerApi::is_configured();
        $has_gtrends = (bool) Settings::get('google_trends_enabled', 0);
        if (!$has_dfseo && !$has_gads && !$has_gtrends) {
            $warnings[] = sprintf(
                '<strong>TMW SEO:</strong> No keyword providers are available. Configure DataForSEO, enable Google Ads, or enable Google Trends in <a href="%s">Settings</a>.',
                esc_url(admin_url('admin.php?page=tmwseo-settings'))
            );
        }

        // Cache result for 5 minutes
        if (empty($warnings)) {
            set_transient($cache_key, 'clean', 5 * MINUTE_IN_SECONDS);
        } else {
            set_transient($cache_key, $warnings, 5 * MINUTE_IN_SECONDS);
            foreach ($warnings as $msg) {
                echo '<div class="notice notice-warning is-dismissible"><p>' . wp_kses_post($msg) . '</p></div>';
            }
        }
    }

    /**
     * Settings integrity notice — shown on the Settings page only.
     */
    private static function render_settings_integrity_notice(): void {
        $stored = get_option('tmwseo_engine_settings', []);
        if (!is_array($stored)) { $stored = []; }

        $defaults = Settings::defaults();
        $expected_keys = array_keys($defaults);
        $missing = array_diff($expected_keys, array_keys($stored));

        if (count($missing) > 10) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>Settings integrity warning:</strong> ' . count($missing) . ' expected settings keys are missing. ';
            echo 'This may indicate the settings were overwritten by a partial save. ';
            echo 'Re-saving this page will restore default values for missing keys. ';
            echo 'Missing keys include: <code>' . esc_html(implode('</code>, <code>', array_slice($missing, 0, 8))) . '</code>';
            if (count($missing) > 8) { echo ' and ' . (count($missing) - 8) . ' more'; }
            echo '.</p></div>';
        }
    }
}
