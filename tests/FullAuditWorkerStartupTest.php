<?php
/**
 * TMW SEO Engine — Full Audit Worker-Startup Diagnosability Tests (v5.5.0)
 *
 * Locks in the fix for the v5.4.0 "worker_stalled with all bounds zero"
 * bug on hosts where the background worker never manages to start.
 *
 *   ROOT CAUSE (v5.4.0):
 *     The non-blocking loopback POST to admin-ajax.php silently swallowed
 *     every failure mode (WAF block, 403, connection refused, SSL fail,
 *     admin-ajax 404). On hosts where WP-Cron does not fire on its own AND
 *     the loopback is blocked, the queued audit row sat forever in
 *     'pending' state. The front-end poller's stale detector eventually
 *     fired at 300s with reason 'worker_stalled' and all bounds counters
 *     at 0 — the UI had no way to tell "loopback blocked" from "worker
 *     crashed in phase 1" from "DataForSEO quota exhausted".
 *
 *     Separately: a genuine PHP fatal (E_ERROR, OOM, E_PARSE) inside
 *     run_full_audit_now could not be caught by catch(\Throwable). The
 *     JobWorker's outer try/catch also misses it because PHP shutdown
 *     bypasses both. So the real fatal_message was lost forever.
 *
 *   FIX (v5.5.0):
 *     - spawn_worker_loopback_kick() now probes the loopback with a 2s
 *       blocking GET before firing the fire-and-forget POST, caches the
 *       result for 5 minutes, and returns a diagnostic struct.
 *     - ajax_enqueue_full_audit() falls back to running the audit
 *       synchronously in the enqueue request when the probe fails.
 *     - JobWorker::run_model_full_audit() writes a "worker_started"
 *       bounds checkpoint before calling run_full_audit_now, and
 *       registers a shutdown handler that copies fatal-error details
 *       (type / message / file / line) into the bounds blob.
 *     - mark_audit_stalled() differentiates 'worker_never_started'
 *       from 'worker_stalled_mid_run' based on the last known phase
 *       in the bounds blob, and copies the wp_tmwseo_jobs.error_message
 *       into the bounds as 'job_error' for the UI to surface.
 *
 * @package TMWSEO\Engine\Admin\Tests
 * @since   5.5.0
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Admin {

    use PHPUnit\Framework\TestCase;

    // Reuse the in-memory meta stubs from the v5.3.0 persistence test.
    require_once __DIR__ . '/ModelHelperResearchPersistenceTest.php';

    final class FullAuditWorkerStartupTest extends TestCase {

        protected function setUp(): void {
            $GLOBALS['_tmw_model_helper_meta']          = [];
            $GLOBALS['_tmw_model_helper_lock_options']  = [];
            $GLOBALS['_tmw_model_helper_title_by_post'] = [];
        }

        // ── W1. worker_never_started vs worker_stalled_mid_run ─────────────

        /**
         * W1.1. When the bounds blob phase is 'queued' (or blank),
         * mark_audit_stalled must record reason='worker_never_started'.
         * This is the root-cause signal for "loopback + cron both broken".
         */
        public function test_mark_stalled_with_no_progress_reports_never_started(): void {
            $post_id = 5501;

            // Simulate the v5.4.0 initial enqueue state: bounds phase='queued', 0 counters.
            ModelHelper::write_audit_bounds_checkpoint( $post_id, [
                'phase'      => 'queued',
                'enqueued_at'=> '2026-04-15 00:00:00',
            ] );
            update_post_meta( $post_id, ModelHelper::META_STATUS, 'running' );

            ModelHelper::mark_audit_stalled( $post_id, 301 );

            $bounds = ModelHelper::read_audit_bounds( $post_id );
            $this->assertSame( 'worker_never_started', $bounds['reason'] ?? '',
                'a stall that never advanced past queued MUST be labelled worker_never_started, not the generic worker_stalled' );
            $this->assertSame( 'queued', $bounds['last_known_phase'] ?? '' );
        }

        /**
         * W1.2. When the bounds blob shows real progress (phase='probe'
         * for example), mark_audit_stalled reports worker_stalled_mid_run.
         * This distinguishes "worker is dead mid-flight" from "worker
         * never ran at all" — the operator needs different remediation
         * for each.
         */
        public function test_mark_stalled_with_progress_reports_mid_run(): void {
            $post_id = 5502;

            ModelHelper::write_audit_bounds_checkpoint( $post_id, [
                'phase'            => 'probe',
                'probes_attempted' => 14,
                'probes_accepted'  => 3,
            ] );
            update_post_meta( $post_id, ModelHelper::META_STATUS, 'running' );

            ModelHelper::mark_audit_stalled( $post_id, 400 );

            $bounds = ModelHelper::read_audit_bounds( $post_id );
            $this->assertSame( 'worker_stalled_mid_run', $bounds['reason'] ?? '' );
            $this->assertSame( 'probe', $bounds['last_known_phase'] ?? '' );
            // Earlier probe counters must be preserved by the merge.
            $this->assertSame( 14, $bounds['probes_attempted'] ?? null );
        }

        // ── W2. PHP fatal capture ──────────────────────────────────────────

        /**
         * W2.1. mark_audit_fatal writes the PHP fatal details (type,
         * message, file, line) into the bounds blob and sets a terminal
         * status. Without this, an E_ERROR inside run_full_audit_now
         * would silently leave META_STATUS='running' until the 300s
         * stale detector wrote the uninformative "worker_stalled".
         */
        public function test_mark_audit_fatal_writes_terminal_state(): void {
            $post_id = 5503;

            ModelHelper::mark_audit_fatal( $post_id, [
                'reason'  => 'php_fatal',
                'type'    => 1,  // E_ERROR
                'message' => 'Allowed memory size of 134217728 bytes exhausted',
                'file'    => 'class-model-platform-probe.php',
                'line'    => 423,
            ] );

            $this->assertSame( 'error',       get_post_meta( $post_id, ModelHelper::META_STATUS, true ) );
            $this->assertSame( 'interrupted', get_post_meta( $post_id, ModelHelper::META_AUDIT_PHASE, true ) );

            $bounds = ModelHelper::read_audit_bounds( $post_id );
            $this->assertSame( 'php_fatal', $bounds['reason'] ?? '' );
            $this->assertStringContainsString( 'memory', (string) ( $bounds['fatal_message'] ?? '' ) );
            $this->assertSame( 'class-model-platform-probe.php', $bounds['fatal_file'] ?? '' );
            $this->assertSame( 423, $bounds['fatal_line'] ?? null );
        }

        /**
         * W2.2. A fatal on a post that already has prior proposed data
         * downgrades to 'partial' (not 'error') so recovery info
         * survives — same contract as mark_audit_stalled.
         */
        public function test_mark_audit_fatal_preserves_prior_data_as_partial(): void {
            $post_id = 5504;
            $prior   = json_encode( [ 'pipeline_status' => 'ok', 'merged' => [ 'platform_names' => [ 'Chaturbate' ] ] ] );
            update_post_meta( $post_id, ModelHelper::META_PROPOSED, $prior );

            ModelHelper::mark_audit_fatal( $post_id, [
                'reason'  => 'php_fatal',
                'type'    => 1,
                'message' => 'Fatal error in phase 2',
                'file'    => 'x.php',
                'line'    => 1,
            ] );

            $this->assertSame( 'partial', get_post_meta( $post_id, ModelHelper::META_STATUS, true ),
                'a fatal on a post with prior good data must downgrade to partial, not error' );

            // Prior proposed blob must still be readable.
            $stored = (string) get_post_meta( $post_id, ModelHelper::META_PROPOSED, true );
            $decoded = json_decode( $stored, true );
            $this->assertSame( [ 'Chaturbate' ], $decoded['merged']['platform_names'] ?? [] );
        }

        // ── W3. Job-row error_message surfacing ─────────────────────────────

        /**
         * W3.1. read_job_row_error_for_post returns '' when no
         * META_AUDIT_JOB_ID is set — graceful fallback, no crash.
         */
        public function test_read_job_row_error_without_job_id_returns_empty_string(): void {
            $post_id = 5505;
            $this->assertSame( '', ModelHelper::read_job_row_error_for_post( $post_id ) );
        }

        // ── W4. Loopback probe + sync fallback code-path presence ──────────

        /**
         * W4.1. spawn_worker_loopback_kick returns a diagnostic struct
         * with the fields the enqueue handler and UI both need.
         */
        public function test_loopback_kick_returns_probe_struct(): void {
            // In the test environment wp_remote_post / get_transient are
            // stubs — verify the function does not crash and returns
            // the documented shape.
            $result = ModelHelper::spawn_worker_loopback_kick();
            $this->assertIsArray( $result );
            $this->assertArrayHasKey( 'attempted',   $result );
            $this->assertArrayHasKey( 'probe_ok',    $result );
            $this->assertArrayHasKey( 'probe_http',  $result );
            $this->assertArrayHasKey( 'probe_error', $result );
        }

        /**
         * W4.2. ajax_enqueue_full_audit source contains the sync-fallback
         * branch — defence-in-depth against someone removing it in a
         * later refactor. We verify by inspecting the PHP source.
         */
        public function test_enqueue_handler_has_sync_fallback_branch(): void {
            $ref = new \ReflectionClass( ModelHelper::class );
            $m   = $ref->getMethod( 'ajax_enqueue_full_audit' );
            $src = file_get_contents( (string) $m->getFileName() );
            $body = implode( "\n", array_slice( explode( "\n", $src ), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1 ) );

            $this->assertStringContainsString( 'loopback_blocked', $body,
                'the enqueue handler must detect a blocked loopback' );
            $this->assertStringContainsString( 'sync_fallback', $body,
                'the enqueue handler must expose the sync-fallback execution mode' );
            $this->assertStringContainsString( 'run_full_audit_now', $body,
                'the sync-fallback path must actually call the audit' );
        }

        // ── W5. JobWorker first-checkpoint safety ───────────────────────────

        /**
         * W5.1. JobWorker::run_model_full_audit source writes a
         * 'worker_started' bounds checkpoint BEFORE calling into
         * run_full_audit_now. Without this, a fatal inside the audit
         * pipeline leaves bounds stuck at the v5.4.0 "queued" snapshot.
         */
        public function test_job_worker_writes_first_checkpoint_before_audit(): void {
            $worker_file = __DIR__ . '/../includes/worker/class-job-worker.php';
            $src = file_get_contents( $worker_file );
            $this->assertNotFalse( $src );

            $this->assertStringContainsString( 'worker_started', $src,
                'JobWorker must write a worker_started checkpoint before the audit pipeline runs' );

            // The checkpoint write must come BEFORE the actual call to
            // ModelHelper::run_full_audit_now — not before a docblock
            // mention. Locate the real static-call site.
            $checkpoint_pos = strpos( $src, "'worker_started_at'" );
            // The real call site is distinguished from docblock mentions
            // by the $post_id argument — docblock says "run_full_audit_now()".
            $run_call_pos   = strpos( $src, 'run_full_audit_now($post_id)' );
            $this->assertNotFalse( $checkpoint_pos,
                'worker_started_at checkpoint literal must exist in the handler' );
            $this->assertNotFalse( $run_call_pos,
                'actual call site for run_full_audit_now($post_id) must exist' );
            $this->assertLessThan( $run_call_pos, $checkpoint_pos,
                'the worker_started checkpoint write must precede the run_full_audit_now call' );
        }

        /**
         * W5.2. JobWorker::run_model_full_audit registers a
         * shutdown-function catcher for PHP fatals. Defence-in-depth:
         * this is the only path that sees real E_ERROR events.
         */
        public function test_job_worker_registers_fatal_shutdown_catcher(): void {
            $worker_file = __DIR__ . '/../includes/worker/class-job-worker.php';
            $src = file_get_contents( $worker_file );

            $this->assertStringContainsString( 'register_shutdown_function', $src,
                'JobWorker must register a shutdown catcher for PHP fatals' );
            $this->assertStringContainsString( 'error_get_last',           $src );
            $this->assertStringContainsString( 'mark_audit_fatal',         $src,
                'the shutdown catcher must route fatals into mark_audit_fatal' );
        }

        // ── W6. UI surface ──────────────────────────────────────────────────

        /**
         * W6.1. The render_metabox() partial/error UI block surfaces
         * the rich diagnostics — reason label, job_error, fatal_message,
         * loopback probe result, last known phase. This is the piece
         * that replaces the bare "worker_stalled" string on the screen.
         */
        public function test_partial_error_ui_surfaces_rich_diagnostics(): void {
            $ref    = new \ReflectionClass( ModelHelper::class );
            $method = $ref->getMethod( 'render_metabox' );
            $src    = file_get_contents( (string) $method->getFileName() );
            $start  = $method->getStartLine();
            $end    = $method->getEndLine();
            $lines  = explode( "\n", $src );
            $body   = implode( "\n", array_slice( $lines, $start - 1, $end - $start + 1 ) );

            $this->assertStringContainsString( 'worker_never_started', $body );
            $this->assertStringContainsString( 'worker_stalled_mid_run', $body );
            $this->assertStringContainsString( 'php_fatal', $body );
            $this->assertStringContainsString( 'Loopback probe', $body );
            $this->assertStringContainsString( 'Worker error_message', $body );
            $this->assertStringContainsString( 'Last known phase', $body );
        }
    }
}
