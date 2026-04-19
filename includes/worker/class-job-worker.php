<?php
namespace TMWSEO\Engine;

use TMWSEO\Engine\Admin\AIContentBriefGeneratorAdmin;
use TMWSEO\Engine\Admin\LinkGraphAdminPage;
use TMWSEO\Engine\Admin\SerpAnalyzerAdminPage;
use TMWSEO\Engine\Keywords\CompetitorMiningService;
use TMWSEO\Engine\Keywords\KeywordEngine;
use TMWSEO\Engine\Keywords\UnifiedKeywordWorkflowService;
use TMWSEO\Engine\ContentGap\ContentGapService;
use TMWSEO\Engine\DiscoveryGovernor;
use TMWSEO\Engine\Keywords\RecursiveKeywordExpansionEngine;

if (!defined('ABSPATH')) { exit; }

class JobWorker {
    private const MAX_RETRIES = 3;

    public static function enqueue_job(string $type, array $payload): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_jobs';

        if (!DiscoveryGovernor::can_increment('queue_jobs_created', 1)) {
            return 0;
        }

        $safe_payload = self::sanitize_payload($payload);
        $wpdb->insert(
            $table,
            [
                'job_type' => sanitize_key($type),
                'payload_json' => wp_json_encode($safe_payload),
                'status' => 'pending',
                'created_at' => current_time('mysql'),
                'retry_count' => 0,
            ],
            ['%s', '%s', '%s', '%s', '%d']
        );

        DiscoveryGovernor::increment('queue_jobs_created', 1);

        return (int) $wpdb->insert_id;
    }

    public static function process_next_job(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_jobs';

        // BUG-10 NOTE: This transient lock has a 55-second TTL. Jobs that call
        // DataForSEO (30s HTTP timeout) or OpenAI (60s timeout) can consume most
        // of that window. If a job times out without reaching the `finally` block,
        // the lock stays held for up to 55 seconds, stalling the next job pickup.
        // This is acceptable for now — the `finally` block below deletes the lock
        // on all clean exit paths. A future improvement would be a DB-backed lock
        // with an explicit release on watchdog heartbeat.
        if (get_transient('tmwseo_job_worker_lock')) {
            return;
        }

        set_transient('tmwseo_job_worker_lock', 1, 55);

        try {
            $job = $wpdb->get_row("SELECT * FROM {$table} WHERE status = 'pending' ORDER BY id ASC LIMIT 1", ARRAY_A);
            if (!is_array($job)) {
                return;
            }

            $job_id = (int) ($job['id'] ?? 0);
            if ($job_id <= 0) {
                return;
            }

            $updated = $wpdb->update(
                $table,
                ['status' => 'running', 'started_at' => current_time('mysql'), 'error_message' => null],
                ['id' => $job_id, 'status' => 'pending'],
                ['%s', '%s', '%s'],
                ['%d', '%s']
            );

            if ((int) $updated !== 1) {
                return;
            }

            $payload = json_decode((string) ($job['payload_json'] ?? '{}'), true);
            if (!is_array($payload)) {
                $payload = [];
            }

            $job_type = (string) ($job['job_type'] ?? '');

            if (self::is_discovery_job_type($job_type) && !DiscoveryGovernor::is_discovery_allowed()) {
                $wpdb->update(
                    $table,
                    ['status' => 'done', 'finished_at' => current_time('mysql'), 'error_message' => 'Discovery disabled by tmw_discovery_enabled'],
                    ['id' => $job_id],
                    ['%s', '%s', '%s'],
                    ['%d']
                );
                return;
            }

            try {
                self::execute_job($job_type, $payload);

                $wpdb->update(
                    $table,
                    ['status' => 'done', 'finished_at' => current_time('mysql')],
                    ['id' => $job_id],
                    ['%s', '%s'],
                    ['%d']
                );
            } catch (\Throwable $e) {
                $retry_count = ((int) ($job['retry_count'] ?? 0)) + 1;
                $status = $retry_count >= self::MAX_RETRIES ? 'failed' : 'pending';

                $wpdb->update(
                    $table,
                    [
                        'status' => $status,
                        'retry_count' => $retry_count,
                        'finished_at' => $status === 'failed' ? current_time('mysql') : null,
                        'error_message' => substr($e->getMessage(), 0, 1000),
                    ],
                    ['id' => $job_id],
                    ['%s', '%d', '%s', '%s'],
                    ['%d']
                );

                Logs::error('job_worker', 'Background job failed', [
                    'job_id' => $job_id,
                    'job_type' => (string) ($job['job_type'] ?? ''),
                    'retry_count' => $retry_count,
                    'error' => $e->getMessage(),
                ]);
            }
        } finally {
            delete_transient('tmwseo_job_worker_lock');
        }
    }

    public static function run_keyword_discovery(array $payload): void {
        UnifiedKeywordWorkflowService::run_cycle(['payload' => $payload, 'source' => 'background_job']);
    }

    public static function run_serp_analysis(array $payload): void {
        $keyword = sanitize_text_field((string) ($payload['keyword'] ?? ''));
        $user_id = (int) ($payload['user_id'] ?? 0);
        if ($keyword === '' || $user_id <= 0) {
            throw new \RuntimeException('Invalid SERP payload.');
        }

        if (!DiscoveryGovernor::can_increment('serp_requests', 1)) {
            throw new \RuntimeException('Discovery governor triggered: serp_requests limit reached.');
        }

        $cache_key = 'tmwseo_bg_serp_' . md5(mb_strtolower($keyword, 'UTF-8'));
        $result = get_transient($cache_key);
        if (!is_array($result)) {
            $result = SerpAnalyzerAdminPage::build_serp_result($keyword);
            DiscoveryGovernor::increment('serp_requests', 1);
            set_transient($cache_key, $result, DAY_IN_SECONDS);
        }

        $token = wp_generate_password(20, false, false);
        set_transient('tmwseo_serp_ui_' . $token, $result, 20 * MINUTE_IN_SECONDS);
        set_transient('tmwseo_serp_last_result_user_' . $user_id, $token, 20 * MINUTE_IN_SECONDS);
    }

    public static function run_cluster_generation(array $payload): void {
        KeywordEngine::run_cluster_projection_job([
            'payload' => $payload,
            'source' => 'background_cluster_generation',
        ]);
    }

    public static function run_competitor_mining(array $payload): void {
        CompetitorMiningService::run($payload);
    }

    public static function run_internal_link_scan(array $payload): void {
        $user_id = (int) ($payload['user_id'] ?? 0);
        if ($user_id <= 0) {
            throw new \RuntimeException('Invalid link scan payload.');
        }

        $result = LinkGraphAdminPage::build_graph_payload();
        $suggestions = (array) ($result['suggestions'] ?? []);
        $token = wp_generate_password(20, false, false);

        set_transient('tmwseo_link_graph_ui_' . $token, $result, 20 * MINUTE_IN_SECONDS);
        set_transient('tmwseo_link_graph_suggestions_' . $user_id, $suggestions, HOUR_IN_SECONDS);
        set_transient('tmwseo_link_graph_last_result_user_' . $user_id, $token, 20 * MINUTE_IN_SECONDS);
    }

    public static function run_content_gap_analysis(array $payload): void {
        ContentGapService::run_analysis();
    }

    public static function run_recursive_keyword_expansion(array $payload): void {
        $seed_keywords = array_values(array_filter(array_map('strval', (array) ($payload['seed_keywords'] ?? []))));
        if (empty($seed_keywords)) {
            throw new \RuntimeException('Invalid recursive keyword expansion payload.');
        }

        RecursiveKeywordExpansionEngine::run($seed_keywords);
    }

    public static function counts(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tmwseo_jobs';

        $rows = (array) $wpdb->get_results("SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status", ARRAY_A);
        $counts = ['pending' => 0, 'running' => 0, 'failed' => 0, 'done' => 0];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status] = (int) ($row['cnt'] ?? 0);
            }
        }

        return $counts;
    }

    private static function is_discovery_job_type(string $job_type): bool {
        return in_array($job_type, ['keyword_discovery', 'competitor_mining', 'recursive_keyword_expansion'], true);
    }

    private static function execute_job(string $job_type, array $payload): void {
        switch ($job_type) {
            case 'keyword_discovery':
                self::run_keyword_discovery($payload);
                return;
            case 'serp_analysis':
                self::run_serp_analysis($payload);
                return;
            case 'cluster_generation':
                self::run_cluster_generation($payload);
                return;
            case 'competitor_mining':
                self::run_competitor_mining($payload);
                return;
            case 'internal_link_scan':
                self::run_internal_link_scan($payload);
                return;
            case 'content_gap_analysis':
                self::run_content_gap_analysis($payload);
                return;
            case 'recursive_keyword_expansion':
                self::run_recursive_keyword_expansion($payload);
                return;
            case 'ai_content_brief_generation':
                AIContentBriefGeneratorAdmin::run_background_brief_generation($payload);
                return;
            case 'model_full_audit':
                self::run_model_full_audit($payload);
                return;
            default:
                throw new \RuntimeException('Unknown job type: ' . $job_type);
        }
    }

    /**
     * v5.3.0 — Durable Full Audit handler.
     *
     * Runs ModelHelper::run_full_audit_now() inside the background job
     * worker so the audit survives browser disconnects, reverse-proxy
     * idle timeouts, and PHP request limits. The handler delegates to
     * the same ModelHelper method called by the synchronous AJAX path,
     * so checkpoint/persistence behavior is identical.
     *
     * v5.5.0 — Hardened against fatal errors before the first phase
     * checkpoint:
     *   • Writes a "worker_started" bounds checkpoint BEFORE calling
     *     run_full_audit_now, so a fatal inside that method cannot
     *     leave the bounds blob stuck at the "queued" snapshot with
     *     all zeros. Stale-detection still works; operators see
     *     "worker_started" → "interrupted" instead of "worker_stalled".
     *   • Registers a register_shutdown_function catcher that writes
     *     the PHP fatal error (type, message, file, line) into the
     *     bounds blob and marks the post as error/partial. Without
     *     this, a segfault / OOM / E_ERROR silently leaves META_STATUS
     *     as 'running' forever (until 300s stale-detection kicks in
     *     and collapses it to the uninformative 'worker_stalled').
     */
    public static function run_model_full_audit(array $payload): void {
        $post_id = (int) ($payload['post_id'] ?? 0);
        if ($post_id <= 0) {
            throw new \RuntimeException('Invalid model_full_audit payload: missing post_id.');
        }

        if (!class_exists('\\TMWSEO\\Engine\\Admin\\ModelHelper')) {
            $f = TMWSEO_ENGINE_PATH . 'includes/admin/class-model-helper.php';
            if (file_exists($f)) { require_once $f; }
        }

        if (!class_exists('\\TMWSEO\\Engine\\Admin\\ModelHelper')) {
            throw new \RuntimeException('model_full_audit handler missing ModelHelper class.');
        }

        // v5.5.0 — First checkpoint: write BEFORE we call into the
        // audit pipeline so a fatal inside run_full_audit_now cannot
        // leave bounds stuck at "queued/0/0/0/0".
        \TMWSEO\Engine\Admin\ModelHelper::write_audit_bounds_checkpoint($post_id, [
            'phase'              => 'worker_started',
            'worker_started_at'  => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
            'worker_pid'         => function_exists('getmypid') ? (int) getmypid() : 0,
            'execution_mode'     => 'background_job',
        ]);

        // v5.5.0 — Register a fatal-error catcher. If PHP crashes with
        // an E_ERROR / E_PARSE / E_CORE_ERROR / E_COMPILE_ERROR, the
        // catch(\Throwable) inside run_full_audit_now and the outer
        // JobWorker catch both miss it. error_get_last() inside a
        // shutdown callback is the only way to surface the real cause.
        $fatal_catcher_armed = true;
        register_shutdown_function(static function () use ($post_id, &$fatal_catcher_armed): void {
            if (!$fatal_catcher_armed) { return; }
            $err = error_get_last();
            if (!is_array($err)) { return; }
            $type = (int) ($err['type'] ?? 0);
            // Only treat genuine fatals as "the reason".
            $fatal_mask = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
            if (($type & $fatal_mask) === 0) { return; }

            if (!class_exists('\\TMWSEO\\Engine\\Admin\\ModelHelper')) { return; }

            \TMWSEO\Engine\Admin\ModelHelper::mark_audit_fatal($post_id, [
                'reason'  => 'php_fatal',
                'type'    => $type,
                'message' => substr((string) ($err['message'] ?? ''), 0, 240),
                'file'    => basename((string) ($err['file'] ?? '')),
                'line'    => (int) ($err['line'] ?? 0),
            ]);
        });

        try {
            \TMWSEO\Engine\Admin\ModelHelper::run_full_audit_now($post_id);
        } finally {
            // Normal completion (success OR caught Throwable) — disarm
            // the shutdown catcher so it doesn't fire spuriously on a
            // clean-exit request that happens to end with a non-fatal
            // error_get_last() entry left from earlier in the request.
            $fatal_catcher_armed = false;
        }
    }

    private static function sanitize_payload(array $payload): array {
        $safe = [];
        foreach ($payload as $key => $value) {
            $safe_key = sanitize_key((string) $key);
            if (is_array($value)) {
                $safe[$safe_key] = self::sanitize_payload($value);
            } elseif (is_bool($value) || is_int($value) || is_float($value)) {
                $safe[$safe_key] = $value;
            } else {
                $safe[$safe_key] = sanitize_text_field((string) $value);
            }
        }
        return $safe;
    }
}
