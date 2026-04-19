<?php
/**
 * TMW SEO Engine — Full Audit Stuck-Running Tests (v5.4.0)
 *
 * Locks in the fix for the v5.3.0 "contradictory UI + 720s timer stuck"
 * bug:
 *
 *   ROOT CAUSE (v5.3.0):
 *     - Front-end watchdog was a hard 180-poll (=720s) cap that called
 *       showError() with a "worker still running" message. showError()
 *       forcibly set the status text to "Failed." — so the UI literally
 *       said both "Failed." and "worker is still writing checkpoints".
 *     - There was no server-side stale-worker detection. A dead worker
 *       left META_STATUS='running' forever and the poller kept saying
 *       is_terminal=false.
 *     - There was no reconciliation between the tmwseo_jobs row state
 *       and META_STATUS. A crashed worker marked the row 'failed' but
 *       never updated post meta.
 *     - Duplicate enqueues for the same post were possible (every
 *       button click inserted a new row).
 *
 *   FIX (v5.4.0):
 *     - Status poller computes "seconds since last checkpoint" from
 *       META_AUDIT_BOUNDS['updated_at'], and returns is_stale=true
 *       when a running job has made no progress for > 300s. The stale
 *       marker also auto-writes partial (if prior data) or error.
 *     - The poller reconciles wp_tmwseo_jobs terminal rows into
 *       META_STATUS — failed → error, done-without-meta → researched
 *       if proposed data exists else error.
 *     - ajax_enqueue_full_audit reuses an in-flight job for the same
 *       post instead of inserting duplicates.
 *     - JS has three post-watchdog states: success, stalled, and
 *       still-running-informational — never "Failed" unless status
 *       really is 'error'.
 *
 * @package TMWSEO\Engine\Admin\Tests
 * @since   5.4.0
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Admin {

    use PHPUnit\Framework\TestCase;

    // Reuse the in-memory meta stubs established in the v5.3.0 test.
    // Loading this file is side-effect: it defines namespace-local
    // get_post_meta / update_post_meta / delete_post_meta / wp_slash /
    // current_time in TMWSEO\Engine\Admin, which is the namespace we
    // exercise in this test too.
    require_once __DIR__ . '/ModelHelperResearchPersistenceTest.php';

    final class FullAuditStuckRunningTest extends TestCase {

        protected function setUp(): void {
            $GLOBALS['_tmw_model_helper_meta']               = [];
            $GLOBALS['_tmw_model_helper_lock_options']       = [];
            $GLOBALS['_tmw_model_helper_title_by_post']      = [];
        }

        // ── audit_stale_since_seconds() ─────────────────────────────────────

        /**
         * S1. Returns null when the bounds blob lacks an updated_at
         * timestamp — the poller must treat "unknown" as "do not
         * mark stale" (fail-open on ambiguity).
         */
        public function test_stale_seconds_is_null_when_no_timestamp(): void {
            $this->assertNull( ModelHelper::audit_stale_since_seconds( [] ) );
            $this->assertNull( ModelHelper::audit_stale_since_seconds( [ 'updated_at' => '' ] ) );
            $this->assertNull( ModelHelper::audit_stale_since_seconds( [ 'updated_at' => 'garbage-date' ] ) );
        }

        /**
         * S2. Returns a non-negative integer when updated_at is valid.
         * The exact value depends on the stubbed current_time() (frozen
         * at 2026-04-15 00:00:00 in the shared stub), so we test the
         * relationship rather than the exact delta.
         */
        public function test_stale_seconds_uses_timestamp_diff(): void {
            // current_time('mysql') in the stub returns '2026-04-15 00:00:00'.
            // A bounds blob stamped 10 minutes earlier should read ~600s.
            $bounds = [ 'updated_at' => '2026-04-14 23:50:00' ];
            $stale  = ModelHelper::audit_stale_since_seconds( $bounds );
            $this->assertNotNull( $stale );
            $this->assertGreaterThanOrEqual( 0, $stale );
            $this->assertSame( 600, $stale, 'exact diff to stubbed current_time' );
        }

        // ── mark_audit_stalled() ─────────────────────────────────────────────

        /**
         * S3. A stalled audit with NO prior proposed data lands on 'error'.
         * This is the core anti-contradiction guarantee — the poller
         * writes a terminal state so the UI cannot display "running"
         * forever.
         */
        public function test_stalled_run_without_prior_data_marks_error(): void {
            $post_id = 5401;
            update_post_meta( $post_id, ModelHelper::META_STATUS, 'running' );

            ModelHelper::mark_audit_stalled( $post_id, 420 );

            $this->assertSame( 'error',       get_post_meta( $post_id, ModelHelper::META_STATUS, true ) );
            $this->assertSame( 'interrupted', get_post_meta( $post_id, ModelHelper::META_AUDIT_PHASE, true ) );

            $bounds = ModelHelper::read_audit_bounds( $post_id );
            $this->assertTrue( (bool) ( $bounds['interrupted'] ?? false ) );
            $this->assertSame( 'worker_stalled', $bounds['reason']  ?? '' );
            $this->assertSame( 420,              $bounds['stale_seconds'] ?? null );
        }

        /**
         * S4. A stalled audit WITH prior proposed data downgrades to
         * 'partial' — the operator keeps the recovery info and is told
         * truthfully that the new run did not finish.
         */
        public function test_stalled_run_with_prior_data_marks_partial(): void {
            $post_id = 5402;
            $prior   = json_encode( [ 'pipeline_status' => 'ok', 'merged' => [ 'platform_names' => [ 'OnlyFans' ] ] ] );
            update_post_meta( $post_id, ModelHelper::META_PROPOSED, $prior );
            update_post_meta( $post_id, ModelHelper::META_STATUS,   'running' );

            ModelHelper::mark_audit_stalled( $post_id, 620 );

            $this->assertSame( 'partial',     get_post_meta( $post_id, ModelHelper::META_STATUS, true ) );
            $this->assertSame( 'interrupted', get_post_meta( $post_id, ModelHelper::META_AUDIT_PHASE, true ) );

            // Prior data must still be readable — the stall-marker must
            // NEVER destroy recovery information.
            $raw = (string) get_post_meta( $post_id, ModelHelper::META_PROPOSED, true );
            $this->assertNotSame( '', $raw );
            $decoded = json_decode( $raw, true );
            $this->assertSame( [ 'OnlyFans' ], $decoded['merged']['platform_names'] ?? [] );
        }

        // ── Anti-contradiction regression guards ─────────────────────────────

        /**
         * S5. THE BUG REPLAY — the metabox JS must no longer force the
         * status text to "Failed." when all it is doing is informing the
         * operator that the page stopped live-watching.
         *
         * We check the JS string that render_metabox emits: the post-
         * watchdog code path should call showInfo(), which does NOT
         * overwrite the phase label, rather than showError().
         */
        public function test_js_has_distinct_showError_showInfo_showStale(): void {
            $ref    = new \ReflectionClass( ModelHelper::class );
            $method = $ref->getMethod( 'render_metabox' );
            $src    = file_get_contents( (string) $method->getFileName() );
            $start  = $method->getStartLine();
            $end    = $method->getEndLine();
            $lines  = explode( "\n", $src );
            $body   = implode( "\n", array_slice( $lines, $start - 1, $end - $start + 1 ) );

            $this->assertStringContainsString( 'function showError',  $body );
            $this->assertStringContainsString( 'function showInfo',   $body,
                'v5.4.0: showInfo() must exist — the watchdog path calls it instead of showError()' );
            $this->assertStringContainsString( 'function showStale',  $body,
                'v5.4.0: showStale() must exist for server-detected stalled jobs' );

            // The critical anti-contradiction guard: showInfo() must NOT
            // set the status text to "Failed."
            $this->assertMatchesRegularExpression(
                '/function showInfo\b[^}]*\}/s',
                $body,
                'showInfo body must be parseable'
            );
            // Extract showInfo body and assert it does NOT contain "Failed"
            if ( preg_match( '/function showInfo\b[^{]*\{(.*?)\}/s', $body, $m ) ) {
                $this->assertStringNotContainsString( 'Failed', $m[1],
                    'showInfo() must NEVER write "Failed." — that was the v5.3.0 contradiction'
                );
            } else {
                $this->fail( 'could not isolate showInfo() body' );
            }
        }

        /**
         * S6. The front-end watchdog no longer calls showError() for the
         * "still running" case. Guard against regression by asserting
         * the JS string "still running in the background" is associated
         * with showInfo, not showError.
         */
        public function test_front_end_watchdog_uses_showInfo_not_showError(): void {
            $ref    = new \ReflectionClass( ModelHelper::class );
            $method = $ref->getMethod( 'render_metabox' );
            $src    = file_get_contents( (string) $method->getFileName() );
            $start  = $method->getStartLine();
            $end    = $method->getEndLine();
            $lines  = explode( "\n", $src );
            $body   = implode( "\n", array_slice( $lines, $start - 1, $end - $start + 1 ) );

            $this->assertStringContainsString( 'still running in the background', $body,
                'watchdog message must be present' );
            $this->assertStringNotContainsString( 'Full Audit still running after', $body,
                'v5.3.0 contradictory message must be removed — that string was the smoking gun' );

            // Lightweight proximity check: within 200 chars before the
            // watchdog string, there should be a showInfo( call.
            $pos = strpos( $body, 'still running in the background' );
            $this->assertNotFalse( $pos );
            $window = substr( $body, max( 0, $pos - 200 ), 200 );
            $this->assertStringContainsString( 'showInfo(', $window,
                'the watchdog message must be emitted via showInfo(), not showError()' );
        }

        /**
         * S7. The JS no longer has the hard 180-poll / 720s cap that
         * blew up as a false failure.
         */
        public function test_front_end_poll_cap_raised_and_no_false_failure(): void {
            $ref    = new \ReflectionClass( ModelHelper::class );
            $method = $ref->getMethod( 'render_metabox' );
            $src    = file_get_contents( (string) $method->getFileName() );
            $start  = $method->getStartLine();
            $end    = $method->getEndLine();
            $lines  = explode( "\n", $src );
            $body   = implode( "\n", array_slice( $lines, $start - 1, $end - $start + 1 ) );

            $this->assertStringNotContainsString( 'maxPolls=180',
                $body,
                'the v5.3.0 hard 720s watchdog must be removed' );

            // watchdogPolls=225 (= 900s) is the v5.4.0 informational
            // watchdog — AFTER which the poll simply paints an info
            // notice and keeps polling.
            $this->assertStringContainsString( 'watchdogPolls', $body );
        }

        // ── Status poll response shape ──────────────────────────────────────

        /**
         * S8. The status poll response carries the new fields the JS
         * needs to avoid the contradictory state: is_stale,
         * stale_seconds, stale_threshold. Verify by reflecting on
         * ajax_research_status_poll source and asserting the send_json
         * payload includes those keys.
         */
        public function test_status_poll_response_exposes_stale_fields(): void {
            $ref    = new \ReflectionClass( ModelHelper::class );
            $method = $ref->getMethod( 'ajax_research_status_poll' );
            $src    = file_get_contents( (string) $method->getFileName() );
            $start  = $method->getStartLine();
            $end    = $method->getEndLine();
            $lines  = explode( "\n", $src );
            $body   = implode( "\n", array_slice( $lines, $start - 1, $end - $start + 1 ) );

            $this->assertStringContainsString( "'is_stale'",        $body );
            $this->assertStringContainsString( "'stale_seconds'",   $body );
            $this->assertStringContainsString( "'stale_threshold'", $body );
            $this->assertStringContainsString( "'is_terminal'",     $body );
            $this->assertStringContainsString( 'reconcile_audit_job_state',
                $body,
                'poll handler must call the job-row reconciler before reading meta' );
        }
    }
}
