<?php
/**
 * TMW SEO Engine — Full Audit Retry Recovery Tests (v5.6.0)
 *
 * Locks in the fix for the v5.5.0 "retry after worker_never_started
 * silently repeats the same dead queued path" bug.
 *
 *   ROOT CAUSES (v5.5.0):
 *     A. GET-based loopback probe produced false positives on hosts
 *        where GETs to admin-ajax.php are allowed but POSTs to
 *        privileged endpoints from 127.0.0.1 are blocked by WAF.
 *     B. ajax_enqueue_full_audit reused stranded pending/running job
 *        rows from the failed prior attempt via find_in_flight_audit_job_id.
 *     C. The probe verdict was cached for 5 minutes; a wrong
 *        "probe_ok=true" verdict trapped every retry in that window.
 *     D. The sync-fallback branch was only reachable when the probe
 *        failed — but cached "probe_ok=true" meant it was skipped,
 *        and job-row reuse meant the retry took the background path
 *        again regardless.
 *
 *   FIX (v5.6.0):
 *     - POST-based probe to admin-post.php with token-echo body check.
 *     - Per-post worker_never_started transient: retry forces sync.
 *     - cancel_all_audit_jobs_for_post: retries kill every stranded row.
 *     - invalidate_loopback_probe_cache + TTL lowered to 60 s.
 *     - execution_mode surfaced in response JSON + bounds blob.
 *
 * @package TMWSEO\Engine\Admin\Tests
 * @since   5.6.0
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Admin {

    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/ModelHelperResearchPersistenceTest.php';

    final class FullAuditRetryRecoveryTest extends TestCase {

        protected function setUp(): void {
            $GLOBALS['_tmw_model_helper_meta']          = [];
            $GLOBALS['_tmw_model_helper_lock_options']  = [];
            $GLOBALS['_tmw_model_helper_title_by_post'] = [];
            $GLOBALS['_tmw_test_transients']            = [];
        }

        // ── flag / recent / clear worker_never_started ────────────────────

        /**
         * R1. flag_worker_never_started writes a transient that
         * recent_worker_never_started reads. Baseline for the retry
         * force-sync path.
         */
        public function test_flag_and_read_worker_never_started(): void {
            $post_id = 5601;
            $this->assertFalse( ModelHelper::recent_worker_never_started( $post_id ) );

            ModelHelper::flag_worker_never_started( $post_id );
            $this->assertTrue( ModelHelper::recent_worker_never_started( $post_id ),
                'after flagging, recent_worker_never_started must return true' );

            ModelHelper::clear_worker_never_started( $post_id );
            $this->assertFalse( ModelHelper::recent_worker_never_started( $post_id ),
                'after clearing, the flag must be gone' );
        }

        /**
         * R2. mark_audit_stalled with reason=worker_never_started
         * automatically raises the flag. This is the wiring that
         * enables the next click to force sync without the operator
         * having to remember the history.
         */
        public function test_mark_stalled_with_no_progress_flags_post(): void {
            $post_id = 5602;

            // Simulate bounds stuck at queued — real failure mode on
            // affected hosts.
            ModelHelper::write_audit_bounds_checkpoint( $post_id, [
                'phase' => 'queued',
            ] );
            update_post_meta( $post_id, ModelHelper::META_STATUS, 'running' );

            // Sanity: flag is not yet set.
            $this->assertFalse( ModelHelper::recent_worker_never_started( $post_id ) );

            ModelHelper::mark_audit_stalled( $post_id, 301 );

            // Stall reason must be worker_never_started (last phase was 'queued').
            $bounds = ModelHelper::read_audit_bounds( $post_id );
            $this->assertSame( 'worker_never_started', $bounds['reason'] ?? '' );

            // The flag must now be set — the next click will force sync.
            $this->assertTrue( ModelHelper::recent_worker_never_started( $post_id ),
                'mark_audit_stalled with reason=worker_never_started must raise the per-post flag' );
        }

        /**
         * R3. mark_audit_stalled with reason=worker_stalled_mid_run
         * (i.e. progress existed past 'queued') must NOT flag the
         * post — the worker demonstrably CAN start on this host, so
         * forcing sync on retry would be overkill.
         */
        public function test_mark_stalled_mid_run_does_not_flag_post(): void {
            $post_id = 5603;

            ModelHelper::write_audit_bounds_checkpoint( $post_id, [
                'phase'            => 'probe',
                'probes_attempted' => 20,
            ] );
            update_post_meta( $post_id, ModelHelper::META_STATUS, 'running' );

            ModelHelper::mark_audit_stalled( $post_id, 400 );

            $bounds = ModelHelper::read_audit_bounds( $post_id );
            $this->assertSame( 'worker_stalled_mid_run', $bounds['reason'] ?? '' );

            $this->assertFalse( ModelHelper::recent_worker_never_started( $post_id ),
                'mid-run stalls must NOT raise the force-sync flag' );
        }

        // ── Probe cache invalidation ───────────────────────────────────────

        /**
         * R4. invalidate_loopback_probe_cache removes both the v5.5.0
         * ('tmwseo_loopback_probe') and v5.6.0 ('tmwseo_loopback_probe_v2')
         * transient keys — defensive against a partial upgrade.
         */
        public function test_invalidate_loopback_probe_cache_clears_both_versions(): void {
            set_transient( 'tmwseo_loopback_probe',    [ 'probe_ok' => true ] );
            set_transient( 'tmwseo_loopback_probe_v2', [ 'probe_ok' => true ] );

            ModelHelper::invalidate_loopback_probe_cache();

            $this->assertFalse( get_transient( 'tmwseo_loopback_probe' ) );
            $this->assertFalse( get_transient( 'tmwseo_loopback_probe_v2' ) );
        }

        /**
         * R5. mark_audit_stalled with worker_never_started also
         * invalidates the probe cache — a stale "probe_ok=true"
         * verdict cannot survive a real-world counter-example.
         */
        public function test_worker_never_started_invalidates_probe_cache(): void {
            $post_id = 5605;
            set_transient( 'tmwseo_loopback_probe_v2', [ 'probe_ok' => true, 'probe_method' => 'POST' ] );

            ModelHelper::write_audit_bounds_checkpoint( $post_id, [ 'phase' => 'queued' ] );
            update_post_meta( $post_id, ModelHelper::META_STATUS, 'running' );

            ModelHelper::mark_audit_stalled( $post_id, 305 );

            $this->assertFalse( get_transient( 'tmwseo_loopback_probe_v2' ),
                'a proven-wrong probe cache must be cleared by worker_never_started stall detection' );
        }

        // ── Loopback probe shape (v5.6.0 surface) ──────────────────────────

        /**
         * R6. spawn_worker_loopback_kick returns the v5.6.0 extended
         * struct including probe_method, probe_endpoint, from_cache —
         * which the UI now surfaces.
         */
        public function test_loopback_kick_struct_is_v5_6_shape(): void {
            // force_probe=true to avoid any transient leftover noise.
            $result = ModelHelper::spawn_worker_loopback_kick( true );
            $this->assertIsArray( $result );
            $this->assertArrayHasKey( 'attempted',      $result );
            $this->assertArrayHasKey( 'probe_ok',       $result );
            $this->assertArrayHasKey( 'probe_http',     $result );
            $this->assertArrayHasKey( 'probe_error',    $result );
            $this->assertArrayHasKey( 'probe_method',   $result );
            $this->assertArrayHasKey( 'probe_endpoint', $result );
            $this->assertArrayHasKey( 'from_cache',     $result );
            // Probe must be a POST (not the v5.5.0 GET).
            $this->assertSame( 'POST', $result['probe_method'] );
            // Probe endpoint must be admin-post.php (not admin-ajax.php).
            $this->assertStringContainsString( 'admin-post.php', $result['probe_endpoint'] );
        }

        /**
         * R7. force_probe=true bypasses the cache. This is what lets
         * a retry re-test immediately instead of trusting a stale
         * verdict from the previous session.
         */
        public function test_force_probe_bypasses_cache(): void {
            // Seed the cache with a passing verdict.
            set_transient( 'tmwseo_loopback_probe_v2', [
                'probe_ok'       => true,
                'probe_http'     => 200,
                'probe_method'   => 'POST',
                'probe_endpoint' => 'admin-post.php?action=tmwseo_loopback_health',
                'probe_error'    => '',
            ] );

            $cached = ModelHelper::spawn_worker_loopback_kick( false );
            $this->assertTrue( (bool) ( $cached['from_cache'] ?? false ),
                'default call must read from cache' );

            $fresh = ModelHelper::spawn_worker_loopback_kick( true );
            $this->assertFalse( (bool) ( $fresh['from_cache'] ?? true ),
                'force_probe=true must NOT return the cached verdict' );
        }

        // ── Enqueue handler source-level guarantees ────────────────────────

        /**
         * R8. ajax_enqueue_full_audit contains the v5.6.0 retry-recovery
         * logic: it consults recent_worker_never_started and takes
         * forced_sync when set. Source-level assertion guards against
         * a later refactor that silently removes the branch.
         */
        public function test_enqueue_handler_has_forced_sync_path(): void {
            $ref = new \ReflectionClass( ModelHelper::class );
            $m   = $ref->getMethod( 'ajax_enqueue_full_audit' );
            $src = file_get_contents( (string) $m->getFileName() );
            $lines = explode( "\n", $src );
            $body  = implode( "\n", array_slice( $lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1 ) );

            $this->assertStringContainsString( 'recent_worker_never_started', $body,
                'enqueue handler must consult the worker_never_started flag' );
            $this->assertStringContainsString( "'forced_sync'", $body,
                'enqueue handler must label forced-sync execution mode' );
            $this->assertStringContainsString( 'cancel_all_audit_jobs_for_post', $body,
                'enqueue handler must kill stranded rows on retry' );
            // v5.5.0's dead-job reuse helper must NOT be called here
            // any more — latching onto stranded rows is exactly the bug.
            $this->assertStringNotContainsString( 'find_in_flight_audit_job_id', $body,
                'enqueue handler must NOT reuse a stranded in-flight job row' );
            // execution_mode must be returned to the client.
            $this->assertStringContainsString( "'execution_mode'", $body );
            $this->assertStringContainsString( "'execution_reason'", $body );
        }

        /**
         * R9. cancel_all_audit_jobs_for_post exists and is distinct
         * from cancel_stale_audit_jobs (which keeps one row).
         */
        public function test_cancel_all_audit_jobs_for_post_exists(): void {
            $this->assertTrue( method_exists( ModelHelper::class, 'cancel_all_audit_jobs_for_post' ),
                'v5.6.0 must ship cancel_all_audit_jobs_for_post' );

            $ref = new \ReflectionClass( ModelHelper::class );
            $m   = $ref->getMethod( 'cancel_all_audit_jobs_for_post' );
            $src = file_get_contents( (string) $m->getFileName() );
            $lines = explode( "\n", $src );
            $body  = implode( "\n", array_slice( $lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1 ) );

            $this->assertStringContainsString( "IN ('pending','running')", $body,
                'must target pending/running rows, not done/failed ones' );
            $this->assertStringContainsString( "'failed'", $body,
                'must mark the row as failed with a reason' );
        }
    }
}
