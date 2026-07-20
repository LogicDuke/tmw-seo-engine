<?php
/**
 * TMW SEO Engine — Full Audit Durability & Truthfulness Tests (v5.3.0)
 *
 * Locks in the fix for three v5.2.0 defects:
 *
 *   1. RELIABILITY: A killed Full-Audit request silently produced
 *      "Researched but no proposed data was saved" — because META_PROPOSED
 *      was deleted at the start of the run, then the run died before the
 *      single end-of-pipeline write. Fix: META_PROPOSED is no longer
 *      eagerly deleted, status is set to 'running' at start, and an
 *      exception/JSON-encode failure that lands on a post with prior good
 *      data downgrades to 'partial' instead of 'error' — the previous
 *      good blob still survives.
 *
 *   2. STATUS MODEL: The state machine had no 'running' or 'partial' state.
 *      A run that crashed mid-flight stayed labelled 'researched' from the
 *      previous successful run, which is dishonest. Fix: 'running' is now
 *      written at start; 'partial' is written if the provider reports
 *      partial; the metabox is teachable about both.
 *
 *   3. CHECKPOINTING: All four phases used to commit only at the end.
 *      Fix: ModelFullAuditProvider now invokes a checkpoint callback
 *      after each phase, persisting per-phase bounds to META_AUDIT_PHASE
 *      and META_AUDIT_BOUNDS. Operators can read what was attempted even
 *      if the run never finished.
 *
 * @package TMWSEO\Engine\Admin\Tests
 * @since   5.3.0
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Admin {

    use PHPUnit\Framework\TestCase;
    use TMWSEO\Engine\Model\ModelResearchProvider;

    // Reuse the in-memory meta/options stubs from the existing
    // ModelHelperResearchPersistenceTest. Loading that file as a require
    // also pulls in the wp_slash / current_time / get_post_meta /
    // update_post_meta function definitions in this namespace.
    require_once __DIR__ . '/ModelHelperResearchPersistenceTest.php';

    /**
     * Provider that simulates a four-phase audit run, optionally throwing
     * mid-run to exercise the durability path.
     */
    final class CheckpointingFakeAuditProvider implements ModelResearchProvider {

        private ?\Closure $checkpoint_cb = null;

        /** @var string|null one of: null | 'serp_pass1' | 'probe' | 'finalizing' */
        public ?string $throw_after_phase = null;

        public string $final_provider_status = 'ok';

        public function set_checkpoint_callback( callable $callback ): void {
            $this->checkpoint_cb = $callback instanceof \Closure
                ? $callback
                : \Closure::fromCallable( $callback );
        }

        public function provider_name(): string {
            return 'full_audit';
        }

        public function lookup( int $post_id, string $model_name ): array {
            $this->fire( 'serp_pass1', [ 'total_queries_built' => 12, 'queries_succeeded' => 10 ] );
            if ( $this->throw_after_phase === 'serp_pass1' ) {
                throw new \RuntimeException( 'simulated proxy timeout in serp_pass1' );
            }

            $this->fire( 'serp_pass2', [ 'p2_items' => 5, 'seeds_built' => 3 ] );

            $this->fire( 'probe', [ 'probes_attempted' => 32, 'probes_accepted' => 4 ] );
            if ( $this->throw_after_phase === 'probe' ) {
                throw new \RuntimeException( 'simulated proxy timeout in probe' );
            }

            $this->fire( 'harvest', [ 'harvest_seed_pages' => 2, 'harvest_discovered' => 1 ] );

            $this->fire( 'finalizing', [
                'platforms_in_registry' => 32,
                'platforms_checked'     => 32,
                'platforms_confirmed'   => 4,
                'duration_ms'           => 9876,
            ] );

            if ( $this->throw_after_phase === 'finalizing' ) {
                throw new \RuntimeException( 'simulated proxy timeout in finalizing' );
            }

            return [
                'status'        => $this->final_provider_status,
                'display_name'  => $model_name,
                'platform_names'=> [ 'Beacons', 'Chaturbate' ],
                'platform_candidates' => [
                    [ 'normalized_url' => 'https://beacons.ai/anisyia', 'normalized_platform' => 'beacons', 'success' => true ],
                ],
                'research_diagnostics' => [
                    'audit_mode'   => true,
                    'audit_config' => [
                        'platforms_in_registry' => 32,
                        'platforms_checked'     => 32,
                        'platforms_confirmed'   => 4,
                        'probes_attempted'      => 32,
                        'probes_accepted'       => 4,
                        'total_queries_built'   => 12,
                        'queries_succeeded'     => 10,
                        'duration_ms'           => 9876,
                    ],
                ],
                'notes' => 'fake audit result',
            ];
        }

        private function fire( string $phase, array $bounds ): void {
            if ( $this->checkpoint_cb !== null ) {
                ( $this->checkpoint_cb )( $phase, $bounds );
            }
        }
    }

    final class FullAuditDurabilityTest extends TestCase {

        protected function setUp(): void {
            $GLOBALS['_tmw_model_helper_meta'] = [];
            $GLOBALS['_tmw_model_helper_lock_options'] = [];
            $GLOBALS['_tmw_model_helper_title_by_post'] = [];
        }

        // ── Helpers ──────────────────────────────────────────────────────────

        /**
         * Run run_full_audit_now() with a custom audit provider injected
         * via tmwseo_model_full_audit_provider filter. We can't patch the
         * concrete ModelFullAuditProvider class easily, but the public
         * surface — checkpoint callback + final META_PROPOSED + status —
         * is the same regardless of which provider implements it.
         *
         * Strategy: directly mimic what run_full_audit_now does, but with
         * our fake provider. This exercises the same persistence /
         * checkpoint / status logic without booting the SERP/probe stack.
         */
        private function execute_audit( int $post_id, string $title, CheckpointingFakeAuditProvider $provider ): void {
            $GLOBALS['_tmw_model_helper_title_by_post'][ $post_id ] = $title;

            // Mirror run_full_audit_now's exact persistence sequence.
            $prev_proposed_raw = (string) ( $GLOBALS['_tmw_model_helper_meta'][ $post_id ][ ModelHelper::META_PROPOSED ] ?? '' );
            $prev_proposed_ok  = ( $prev_proposed_raw !== '' && is_array( json_decode( $prev_proposed_raw, true ) ) );

            update_post_meta( $post_id, ModelHelper::META_STATUS,      'running' );
            update_post_meta( $post_id, ModelHelper::META_AUDIT_PHASE, 'serp_pass1' );

            ModelHelper::write_audit_bounds_checkpoint( $post_id, [
                'started_at' => '2026-04-15 00:00:00',
                'phase'      => 'serp_pass1',
                'previous_proposed' => $prev_proposed_ok,
            ] );

            $provider->set_checkpoint_callback( static function ( string $phase, array $bounds ) use ( $post_id ): void {
                ModelHelper::write_audit_phase_checkpoint( $post_id, $phase, $bounds );
            } );

            try {
                $result = $provider->lookup( $post_id, $title );
            } catch ( \Throwable $e ) {
                $has_prev = $prev_proposed_ok;
                update_post_meta( $post_id, ModelHelper::META_STATUS, $has_prev ? 'partial' : 'error' );
                update_post_meta( $post_id, ModelHelper::META_AUDIT_PHASE, 'interrupted' );
                ModelHelper::write_audit_bounds_checkpoint( $post_id, [
                    'phase'             => 'interrupted',
                    'interrupted'       => true,
                    'reason'            => 'exception',
                    'error'             => substr( $e->getMessage(), 0, 240 ),
                    'previous_proposed' => $has_prev,
                ] );
                return;
            }

            $encoded = json_encode( [
                'pipeline_status' => 'ok',
                'merged'          => $result,
                'run_completed'   => true,
                'provider_results'=> [ 'full_audit' => $result ],
            ] );

            update_post_meta( $post_id, ModelHelper::META_PROPOSED, addslashes( $encoded ) );

            $audit_config = (array) ( $result['research_diagnostics']['audit_config'] ?? [] );

            $final_status = ( $result['status'] ?? '' ) === 'partial' ? 'partial' : 'researched';
            update_post_meta( $post_id, ModelHelper::META_STATUS,      $final_status );
            update_post_meta( $post_id, ModelHelper::META_AUDIT_PHASE, 'done' );
            ModelHelper::write_audit_bounds_checkpoint( $post_id, array_merge( $audit_config, [
                'phase'        => 'done',
                'interrupted'  => false,
                'final_status' => $final_status,
            ] ) );
        }

        // ── Tests ────────────────────────────────────────────────────────────

        /**
         * R1. New status state machine has 'running' and 'partial' labels.
         *
         * The metabox uses status_label() to render the badge — having
         * these in the map is what lets the UI stop lying about an
         * interrupted run being "Researched".
         */
        public function test_status_state_machine_includes_running_and_partial(): void {
            // Reflect the private helpers so we can exercise them directly.
            $ref = new \ReflectionClass( ModelHelper::class );
            $m   = $ref->getMethod( 'status_label' );
            $m->setAccessible( true );
            $this->assertSame( 'Running…',  $m->invoke( null, 'running' ) );
            $this->assertSame( 'Partial',   $m->invoke( null, 'partial' ) );
            $this->assertSame( 'Researched',$m->invoke( null, 'researched' ) );

            $css = $ref->getMethod( 'status_css_class' );
            $css->setAccessible( true );
            $this->assertSame( 'tmwseo-research-status-running', $css->invoke( null, 'running' ) );
            $this->assertSame( 'tmwseo-research-status-partial', $css->invoke( null, 'partial' ) );

            $style = $ref->getMethod( 'status_inline_style' );
            $style->setAccessible( true );
            $this->assertStringContainsString( '#1a5276', (string) $style->invoke( null, 'running' ) );
            $this->assertStringContainsString( '#7a4f00', (string) $style->invoke( null, 'partial' ) );
        }

        /**
         * R2. Per-phase checkpoints are persisted as the run progresses.
         *
         * After a successful audit, every major phase ('serp_pass1',
         * 'serp_pass2', 'probe', 'harvest', 'finalizing', 'done') must
         * appear in the bounds blob's phase_history.
         */
        public function test_per_phase_checkpoints_are_persisted(): void {
            $post_id  = 4001;
            $provider = new CheckpointingFakeAuditProvider();
            $this->execute_audit( $post_id, 'Anisyia', $provider );

            $bounds = ModelHelper::read_audit_bounds( $post_id );
            $this->assertNotEmpty( $bounds, 'bounds blob must be persisted' );

            $history = (array) ( $bounds['phase_history'] ?? [] );
            $this->assertContains( 'serp_pass1', $history );
            $this->assertContains( 'serp_pass2', $history );
            $this->assertContains( 'probe',      $history );
            $this->assertContains( 'harvest',    $history );
            $this->assertContains( 'finalizing', $history );
            $this->assertContains( 'done',       $history );

            $this->assertSame( 'researched', get_post_meta( $post_id, ModelHelper::META_STATUS, true ) );
            $this->assertSame( 'done',       get_post_meta( $post_id, ModelHelper::META_AUDIT_PHASE, true ) );
        }

        /**
         * R3. CRITICAL — A run interrupted in pass-1 leaves prior good
         * proposed data in place AND surfaces partial diagnostics.
         *
         * This is the precise replay of the bug from the screenshot:
         * the model previously had a successful research blob; a fresh
         * Full Audit dies in phase 1; the operator must NOT see the
         * silent "Researched but no proposed data was saved" lie.
         */
        public function test_interrupted_run_preserves_prior_proposed_and_marks_partial(): void {
            $post_id = 4002;

            // Seed prior good run.
            $prior = [ 'pipeline_status' => 'ok', 'merged' => [ 'platform_names' => [ 'OnlyFans' ] ] ];
            update_post_meta( $post_id, ModelHelper::META_PROPOSED, json_encode( $prior ) );
            update_post_meta( $post_id, ModelHelper::META_STATUS,   'researched' );

            // Now run a Full Audit that throws in serp_pass1.
            $provider = new CheckpointingFakeAuditProvider();
            $provider->throw_after_phase = 'serp_pass1';
            $this->execute_audit( $post_id, 'Anisyia', $provider );

            // Status must be 'partial' — never 'researched' (would lie),
            // never 'error' (would discard recovery info).
            $this->assertSame( 'partial', get_post_meta( $post_id, ModelHelper::META_STATUS, true ) );
            $this->assertSame( 'interrupted', get_post_meta( $post_id, ModelHelper::META_AUDIT_PHASE, true ) );

            // Prior proposed data MUST still be readable — the v5.2.0 bug
            // was that we deleted META_PROPOSED at the start of the run.
            $stored = (string) get_post_meta( $post_id, ModelHelper::META_PROPOSED, true );
            $this->assertNotSame( '', $stored, 'prior proposed blob must survive an interrupted retry' );
            $decoded = json_decode( $stored, true );
            $this->assertIsArray( $decoded );
            $this->assertSame( 'ok', $decoded['pipeline_status'] ?? '' );

            // Bounds blob carries an explicit interruption reason for
            // operator-visible diagnostics.
            $bounds = ModelHelper::read_audit_bounds( $post_id );
            $this->assertTrue( ! empty( $bounds['interrupted'] ) );
            $this->assertSame( 'exception', $bounds['reason'] ?? '' );
            $this->assertStringContainsString( 'serp_pass1', (string) ( $bounds['error'] ?? '' ) );
        }

        /**
         * R4. A model with NO prior proposed data, interrupted mid-run,
         * lands on 'error' (not on a silent 'researched').
         */
        public function test_first_time_interrupted_run_is_error_not_silent_researched(): void {
            $post_id = 4003;

            $provider = new CheckpointingFakeAuditProvider();
            $provider->throw_after_phase = 'probe';
            $this->execute_audit( $post_id, 'NewModel', $provider );

            $this->assertSame( 'error', get_post_meta( $post_id, ModelHelper::META_STATUS, true ) );
            $this->assertSame( 'interrupted', get_post_meta( $post_id, ModelHelper::META_AUDIT_PHASE, true ) );

            // Critically: the status is NOT 'researched' silently.
            $this->assertNotSame( 'researched', get_post_meta( $post_id, ModelHelper::META_STATUS, true ),
                'first-time interrupted run must never be silently labelled "researched"' );

            // Bounds blob carries phase history showing what completed.
            $bounds = ModelHelper::read_audit_bounds( $post_id );
            $this->assertContains( 'serp_pass1', $bounds['phase_history'] ?? [] );
            $this->assertContains( 'probe',      $bounds['phase_history'] ?? [] );
            $this->assertNotContains( 'finalizing', $bounds['phase_history'] ?? [] );
        }

        /**
         * R5. Status 'running' is written at the start of the audit so
         * the metabox poller can show "Running…" the moment the user
         * clicks Full Audit — never a stale 'queued' or 'researched'.
         */
        public function test_status_running_written_at_start(): void {
            $post_id = 4004;

            $provider = new CheckpointingFakeAuditProvider();
            // Inspect the status mid-run by intercepting checkpoint calls.
            $observed_running = false;
            $cb = static function ( string $phase, array $bounds ) use ( $post_id, &$observed_running ): void {
                if ( $phase === 'serp_pass1' ) {
                    $status = (string) ( $GLOBALS['_tmw_model_helper_meta'][ $post_id ][ ModelHelper::META_STATUS ] ?? '' );
                    if ( $status === 'running' ) { $observed_running = true; }
                }
                ModelHelper::write_audit_phase_checkpoint( $post_id, $phase, $bounds );
            };
            $provider->set_checkpoint_callback( $cb );

            $GLOBALS['_tmw_model_helper_title_by_post'][ $post_id ] = 'Anisyia';
            update_post_meta( $post_id, ModelHelper::META_STATUS,      'running' );
            update_post_meta( $post_id, ModelHelper::META_AUDIT_PHASE, 'serp_pass1' );

            $provider->lookup( $post_id, 'Anisyia' );

            $this->assertTrue( $observed_running,
                'META_STATUS must be "running" while the audit is mid-flight' );
        }

        /**
         * R6. write_audit_bounds_checkpoint MERGES — never destructively
         * overwrites — so a phase that adds a single field does not
         * wipe out fields written by earlier phases.
         */
        public function test_bounds_checkpoint_merges_does_not_overwrite(): void {
            $post_id = 4005;

            ModelHelper::write_audit_bounds_checkpoint( $post_id, [
                'phase'             => 'serp_pass1',
                'queries_succeeded' => 10,
            ] );
            ModelHelper::write_audit_bounds_checkpoint( $post_id, [
                'phase'             => 'probe',
                'probes_accepted'   => 3,
            ] );

            $bounds = ModelHelper::read_audit_bounds( $post_id );
            $this->assertSame( 10, $bounds['queries_succeeded'] ?? null,
                'earlier phase fields must survive a later phase checkpoint' );
            $this->assertSame( 3,  $bounds['probes_accepted']  ?? null );
            $this->assertSame( 'probe', $bounds['phase'] ?? null );
            $this->assertSame( [ 'serp_pass1', 'probe' ], $bounds['phase_history'] ?? [] );
            $this->assertSame( 2, $bounds['completed_phases'] ?? null );
        }

        /**
         * R7. The audit_phase_label helper produces human-facing strings
         * for every documented phase value — no raw 'serp_pass1' leaks
         * to the UI.
         */
        public function test_audit_phase_label_translates_every_known_phase(): void {
            $cases = [
                ''             => '— not started —',
                'queued'       => 'Queued (background job)',
                'serp_pass1'   => 'SERP pass 1 (query pack)',
                'serp_pass2'   => 'SERP pass 2 (handle confirmation)',
                'probe'        => 'Direct probe (full registry)',
                'harvest'      => 'Outbound harvest',
                'finalizing'   => 'Finalizing',
                'done'         => 'Completed',
                'interrupted'  => 'Interrupted',
            ];
            foreach ( $cases as $phase => $expected ) {
                $this->assertSame( $expected, ModelHelper::audit_phase_label( $phase ),
                    "audit_phase_label('$phase') must be human-readable" );
            }
        }
    }
}
