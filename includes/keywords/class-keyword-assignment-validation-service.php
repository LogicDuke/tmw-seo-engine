<?php
/**
 * TMW SEO Engine — Keyword Assignment Validation Service (PR-F).
 *
 * Explicit, auditable, reversible PRODUCTION-VALIDATION workflows for the
 * two PR-E guarantees that cannot be proven safely without tooling:
 *
 *   A. MANUAL ASSIGNMENT PRESERVATION — create-manual-fixture writes exactly
 *      one SECONDARY assignment through KeywordAssignmentRepository with
 *      source_type=manual_validation_fixture and
 *      source_reference=validation:<token>. The PR-E executor must then
 *      report it as skipped/manual_assignment_preserved without modifying
 *      it. remove-manual-fixture deletes ONLY that one row, verified by
 *      token and source metadata. recover-manual-review afterwards returns
 *      the (now orphaned) skipped review to a re-reviewable state THROUGH
 *      the existing review workflow (skipped -> stale via the audited
 *      execution state machine; the existing scoped sync then restores
 *      stale -> not_executed once the fresh plan matches again). Operator
 *      and migration assignments are never deletable through this workflow,
 *      and existing migration assignments are never relabeled as manual.
 *
 *   B. FRESH-PLAN MISMATCH -> STALE — create-stale-fixture activates a
 *      reversible fixture (content presence of one page id, for one
 *      explicitly named approved review) whose override is proven by
 *      simulation to change ONLY that review's fresh plan; if ANY sibling
 *      review's normalized planned snapshot would change, activation is
 *      REFUSED. The override influences nothing by itself: ordinary
 *      migration, sync, and execute-approved commands never read fixtures
 *      and stay byte-identical. Only the explicit run-stale-validation
 *      action — which requires the full validation context (exact token +
 *      exact review ID + exact candidate ID) and verifies it against the
 *      ACTIVE fixture — passes the override into the REAL executor as a
 *      per-call argument, so execute-approved marks that review stale
 *      before any write. restore-stale-fixture deactivates the fixture;
 *      the review returns to non-stale only through the existing reviewed
 *      sync workflow.
 *
 * EVERY write requires an explicit CLI action, an explicit ID/token, and
 * --mode=execute (dry-run is the default). Snapshot hashes, candidate rows,
 * Rank Math metadata, page content, postmeta, and import evidence are never
 * touched by any action here. Every fixture lifecycle transition commits
 * atomically with its APPEND-ONLY audit row; a JSON encoding failure or an
 * audit insertion failure aborts and rolls back the whole operation.
 * Nothing runs on plugin load; there is no broad or unbounded command in
 * this workflow. Logging is gated behind WP_DEBUG or
 * TMWSEO_KW_VALIDATION_DEBUG.
 *
 * Log tag: [TMW-KW-ASSIGN-VALIDATE]
 *
 * @package TMWSEO\Engine\Keywords
 * @since   5.9.25
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class KeywordAssignmentValidationService {

    public const LOG_TAG = KeywordAssignmentValidationFixtureRepository::LOG_TAG;

    /**
     * The ONLY role a manual validation fixture may take. Production
     * validation needs nothing beyond a preserved secondary; primary (and
     * every other role) is rejected at both the CLI and the service layer.
     * Ownership is never granted regardless: canonical_owner and
     * active_in_rank_math are forced to 0.
     */
    public const FIXTURE_ROLES = [ 'secondary' ];

    /** Statuses a manual validation fixture may take. */
    public const FIXTURE_STATUSES = [ 'review_required', 'inactive' ];

    /** stale_reason prefix written by recover-manual-review (idempotency marker). */
    public const RECOVERY_REASON_PREFIX = 'validation_manual_fixture_removed:';

    /** Audit/command source recorded on every mutation of this workflow. */
    private const SOURCE = 'keyword-assignment-validation';

    private KeywordAssignmentValidationFixtureRepository $fixtures;
    private KeywordAssignmentRepository $assignments;
    private KeywordAssignmentReviewRepository $reviews;
    private KeywordAssignmentMigrationService $migration;
    private KeywordAssignmentReviewSyncService $sync;
    private KeywordAssignmentReviewExecutionService $executor;

    public function __construct(
        ?KeywordAssignmentValidationFixtureRepository $fixtures = null,
        ?KeywordAssignmentRepository $assignments = null,
        ?KeywordAssignmentReviewRepository $reviews = null,
        ?KeywordAssignmentMigrationService $migration = null,
        ?KeywordAssignmentReviewSyncService $sync = null,
        ?KeywordAssignmentReviewExecutionService $executor = null
    ) {
        $this->fixtures    = $fixtures ?: new KeywordAssignmentValidationFixtureRepository();
        $this->assignments = $assignments ?: new KeywordAssignmentRepository();
        $this->reviews     = $reviews ?: new KeywordAssignmentReviewRepository();
        $this->migration   = $migration ?: new KeywordAssignmentMigrationService();
        $this->sync        = $sync ?: new KeywordAssignmentReviewSyncService( $this->migration, $this->reviews );
        $this->executor    = $executor ?: new KeywordAssignmentReviewExecutionService( $this->migration, $this->reviews, $this->sync, $this->assignments );
    }

    // ══ A. Manual assignment fixture ══════════════════════════════════════

    /**
     * Plan/create exactly ONE manual validation assignment. DRY RUN BY
     * DEFAULT: with $execute = false nothing is written anywhere.
     *
     * Required args: token, candidate_id, target_type, (target_id or
     * target_key), pool, role (secondary only). Optional: page_type
     * (defaults to target_type), target_key, status (default
     * review_required).
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed> report
     */
    public function create_manual_fixture( array $args, bool $execute = false, string $actor = 'cli' ): array {
        $report = [ 'mode' => $execute ? 'execute' : 'dry-run', 'action' => 'create-manual-fixture' ];

        $token = $this->fixtures->normalize_token( (string) ( $args['token'] ?? '' ) );
        if ( '' === $token ) { return $this->refuse( $report, 'invalid_or_missing_validation_token' ); }
        $candidate_id = (int) ( $args['candidate_id'] ?? 0 );
        if ( $candidate_id <= 0 ) { return $this->refuse( $report, 'missing_candidate_id' ); }
        $target_type = strtolower( trim( (string) ( $args['target_type'] ?? '' ) ) );
        if ( '' === $target_type ) { return $this->refuse( $report, 'missing_target_type' ); }
        $target_id  = max( 0, (int) ( $args['target_id'] ?? 0 ) );
        $target_key = trim( (string) ( $args['target_key'] ?? '' ) );
        if ( 0 === $target_id && '' === $target_key ) { return $this->refuse( $report, 'missing_target_id_or_target_key' ); }
        $pool = strtolower( trim( (string) ( $args['pool'] ?? '' ) ) );
        if ( '' === $pool ) { return $this->refuse( $report, 'missing_pool' ); }
        $role = strtolower( trim( (string) ( $args['role'] ?? '' ) ) );
        if ( ! in_array( $role, self::FIXTURE_ROLES, true ) ) {
            return $this->refuse( $report, 'invalid_role_only_secondary_allowed' );
        }
        $status = strtolower( trim( (string) ( $args['status'] ?? 'review_required' ) ) );
        if ( ! in_array( $status, self::FIXTURE_STATUSES, true ) ) {
            return $this->refuse( $report, 'invalid_status_use_one_of_' . implode( '|', self::FIXTURE_STATUSES ) );
        }
        $page_type = strtolower( trim( (string) ( $args['page_type'] ?? '' ) ) );
        if ( '' === $page_type ) { $page_type = $target_type; }

        // A validation fixture never takes ownership and is never active in
        // Rank Math — hard-coded, not operator-selectable.
        $payload = [
            'keyword_candidate_id' => $candidate_id,
            'pool'                 => $pool,
            'page_type'            => $page_type,
            'target_type'          => $target_type,
            'target_id'            => $target_id,
            'target_key'           => $target_key,
            'role'                 => $role,
            'status'               => $status,
            'canonical_owner'      => 0,
            'active_in_rank_math'  => 0,
            'present_in_content'   => 0,
            'source_type'          => KeywordAssignmentValidationFixtureRepository::MANUAL_SOURCE_TYPE,
            'source_reference'     => $this->fixtures->source_reference_for_token( $token ),
            'approval_reason'      => 'manual_validation_fixture',
        ];
        $normalized = $this->assignments->normalize_assignment( $payload );
        if ( isset( $normalized['error'] ) ) { return $this->refuse( $report, 'invalid_assignment_' . (string) $normalized['error'] ); }
        $report['planned_assignment'] = $normalized;

        // Never overwrite ANY existing assignment on this identity. The only
        // acceptable existing row is this exact fixture (idempotent recreate).
        $existing = $this->assignments->find_assignment( $candidate_id, $normalized );
        if ( is_array( $existing ) ) {
            if ( $this->is_fixture_assignment( $existing, $token ) ) {
                $report['ok'] = true;
                $report['outcome'] = 'already_exists_same_fixture';
                $report['assignment_id'] = (int) $existing['id'];
                return $report;
            }
            return $this->refuse( $report, 'existing_assignment_present_source_type_' . (string) ( $existing['source_type'] ?? 'operator' ) );
        }

        // Token discipline: one active fixture per token, always. (The
        // database UNIQUE active-identity indexes remain the hard barrier
        // for concurrent attempts that pass this pre-check.)
        $token_fixture = $this->fixtures->find_latest_by_token( $token );
        if ( null !== $token_fixture && 'active' === (string) $token_fixture['state'] ) {
            return $this->refuse( $report, 'token_already_has_active_fixture' );
        }

        if ( ! $execute ) {
            $report['ok'] = true;
            $report['outcome'] = 'would_create_manual_fixture';
            return $report;
        }

        if ( ! $this->fixtures->transaction( 'START TRANSACTION' ) ) { return $this->refuse( $report, 'transaction_start_failed' ); }
        $created = $this->assignments->create_assignment( $payload );
        if ( empty( $created['ok'] ) ) {
            $this->fixtures->transaction( 'ROLLBACK' );
            return $this->refuse( $report, 'assignment_create_failed_' . (string) ( $created['error'] ?? 'unknown' ) );
        }
        $assignment_id = (int) $created['id'];
        $fixture = $this->fixtures->create_fixture( [
            'validation_token'     => $token,
            'fixture_type'         => KeywordAssignmentValidationFixtureRepository::TYPE_MANUAL,
            'keyword_candidate_id' => $candidate_id,
            'assignment_id'        => $assignment_id,
            'active_scope_key'     => KeywordAssignmentValidationFixtureRepository::manual_scope_key( (string) $normalized['assignment_key'] ),
            'original_values'      => [ 'created_assignment' => $normalized ],
            'override_values'      => [],
            'created_by'           => $actor,
            'audit_note'           => 'assignment:' . $assignment_id,
        ], $actor, self::SOURCE );
        if ( empty( $fixture['ok'] ) ) {
            $this->fixtures->transaction( 'ROLLBACK' );
            return $this->refuse( $report, 'fixture_record_failed_' . (string) ( $fixture['error'] ?? 'unknown' ) );
        }
        if ( ! $this->fixtures->transaction( 'COMMIT' ) ) {
            $this->fixtures->transaction( 'ROLLBACK' );
            return $this->refuse( $report, 'transaction_commit_failed' );
        }
        $report['ok'] = true;
        $report['outcome'] = 'manual_fixture_created';
        $report['assignment_id'] = $assignment_id;
        $report['fixture_id'] = (int) $fixture['id'];
        $this->log( sprintf( 'manual fixture token=%s assignment=%d candidate=%d', $token, $assignment_id, $candidate_id ) );
        return $report;
    }

    /**
     * Read-only inspection of one manual fixture by token.
     *
     * @return array<string,mixed>
     */
    public function inspect_manual_fixture( string $token ): array {
        $report = [ 'mode' => 'read-only', 'action' => 'inspect-manual-fixture' ];
        $token = $this->fixtures->normalize_token( $token );
        if ( '' === $token ) { return $this->refuse( $report, 'invalid_or_missing_validation_token' ); }
        $fixture = $this->fixtures->find_latest_by_token( $token, KeywordAssignmentValidationFixtureRepository::TYPE_MANUAL );
        if ( null === $fixture ) { return $this->refuse( $report, 'no_manual_fixture_for_token' ); }
        $report['fixture'] = $fixture;
        $assignment = $this->assignments->find_by_id( (int) $fixture['assignment_id'] );
        $report['assignment'] = $assignment;
        $report['assignment_intact'] = is_array( $assignment ) && $this->is_fixture_assignment( $assignment, $token );
        $report['audit_events'] = count( $this->fixtures->audit_for_fixture( (int) $fixture['id'] ) );
        $report['ok'] = true;
        return $report;
    }

    /**
     * Remove ONE manual fixture assignment. DRY RUN BY DEFAULT. Deletes only
     * the single assignment row created by this validation command, verified
     * by token AND source metadata; anything else fails closed untouched.
     * The deletion, the fixture state transition, and the audit row commit
     * atomically or not at all.
     *
     * @return array<string,mixed>
     */
    public function remove_manual_fixture( string $token, bool $execute = false, string $actor = 'cli' ): array {
        $report = [ 'mode' => $execute ? 'execute' : 'dry-run', 'action' => 'remove-manual-fixture' ];
        $token = $this->fixtures->normalize_token( $token );
        if ( '' === $token ) { return $this->refuse( $report, 'invalid_or_missing_validation_token' ); }
        $fixture = $this->fixtures->find_latest_by_token( $token, KeywordAssignmentValidationFixtureRepository::TYPE_MANUAL );
        if ( null === $fixture ) { return $this->refuse( $report, 'no_manual_fixture_for_token' ); }
        if ( 'removed' === (string) $fixture['state'] ) {
            $report['ok'] = true;
            $report['outcome'] = 'already_removed';
            return $report;
        }
        if ( 'active' !== (string) $fixture['state'] ) { return $this->refuse( $report, 'fixture_not_active' ); }

        $assignment_id = (int) $fixture['assignment_id'];
        $assignment = $this->assignments->find_by_id( $assignment_id );
        if ( ! is_array( $assignment ) ) { return $this->refuse( $report, 'fixture_assignment_missing_refusing_cleanup' ); }
        // Fail closed: the row must still carry EXACTLY this fixture's source
        // metadata. Operator/migration data is never deletable here, and a
        // row someone re-attributed is no longer ours to delete.
        if ( ! $this->is_fixture_assignment( $assignment, $token ) ) {
            return $this->refuse( $report, 'assignment_not_owned_by_this_fixture_refusing_delete' );
        }
        $report['assignment_id'] = $assignment_id;

        if ( ! $execute ) {
            $report['ok'] = true;
            $report['outcome'] = 'would_remove_manual_fixture';
            return $report;
        }

        if ( ! $this->fixtures->transaction( 'START TRANSACTION' ) ) { return $this->refuse( $report, 'transaction_start_failed' ); }
        if ( ! $this->assignments->delete_assignment( $assignment_id ) ) {
            $this->fixtures->transaction( 'ROLLBACK' );
            return $this->refuse( $report, 'assignment_delete_failed' );
        }
        $closed = $this->fixtures->close_fixture( (int) $fixture['id'], 'removed', $actor, 'assignment:' . $assignment_id, self::SOURCE );
        if ( empty( $closed['ok'] ) ) {
            $this->fixtures->transaction( 'ROLLBACK' );
            return $this->refuse( $report, 'fixture_close_failed_' . (string) ( $closed['error'] ?? 'unknown' ) );
        }
        if ( ! $this->fixtures->transaction( 'COMMIT' ) ) {
            $this->fixtures->transaction( 'ROLLBACK' );
            return $this->refuse( $report, 'transaction_commit_failed' );
        }
        $report['ok'] = true;
        $report['outcome'] = 'manual_fixture_removed';
        $report['next_step'] = 'wp tmwseo keyword-assignment-validation recover-manual-review --token=' . $token . ' --review-id=<skipped-review-id>';
        $this->log( sprintf( 'manual fixture removed token=%s assignment=%d', $token, $assignment_id ) );
        return $report;
    }

    /**
     * Recover the ONE review record that the executor set to
     * skipped/manual_assignment_preserved because of this exact (now
     * removed) manual fixture. DRY RUN BY DEFAULT. Narrowly scoped, audited,
     * idempotent, and fail-closed:
     *
     * - requires the exact validation token AND the exact review ID;
     * - refuses while the fixture still exists (state active);
     * - refuses when the fixture's recorded source metadata does not match;
     * - refuses any review that is not the one skipped by this fixture
     *   (identity is re-derived from the fixture's recorded assignment and
     *   must equal the stored review_key);
     * - refuses when the review was skipped for any other reason;
     * - refuses when an unrelated assignment reoccupied the identity;
     * - never resets arbitrary skipped rows.
     *
     * Recovery itself uses the EXISTING review workflow: the audited
     * execution state machine moves skipped -> stale with an explicit
     * recovery reason; the existing scoped sync then restores
     * stale -> not_executed once the fresh plan matches the reviewed
     * snapshot again, after which the review approves/executes normally.
     * An append-only fixture audit event records the recovery (and, in
     * execute mode, each refused recovery).
     *
     * @return array<string,mixed>
     */
    public function recover_manual_review( int $review_id, string $token, bool $execute = false, string $actor = 'cli' ): array {
        $report = [ 'mode' => $execute ? 'execute' : 'dry-run', 'action' => 'recover-manual-review', 'review_id' => $review_id ];
        $token = $this->fixtures->normalize_token( $token );
        if ( '' === $token ) { return $this->refuse( $report, 'invalid_or_missing_validation_token' ); }
        if ( $review_id <= 0 ) { return $this->refuse( $report, 'missing_review_id' ); }
        $fixture = $this->fixtures->find_latest_by_token( $token, KeywordAssignmentValidationFixtureRepository::TYPE_MANUAL );
        if ( null === $fixture ) { return $this->refuse( $report, 'no_manual_fixture_for_token' ); }
        $report['fixture_id'] = (int) $fixture['id'];

        if ( 'active' === (string) $fixture['state'] ) {
            return $this->refuse_recovery( $report, $fixture, 'fixture_still_active_remove_fixture_first', $execute, $actor );
        }
        if ( 'removed' !== (string) $fixture['state'] ) {
            return $this->refuse_recovery( $report, $fixture, 'fixture_not_in_removed_state_' . (string) $fixture['state'], $execute, $actor );
        }

        // Source metadata of the recorded fixture assignment must match this
        // token exactly — a tampered or foreign fixture row recovers nothing.
        $recorded = (array) ( ( $fixture['original_values'] ?? [] )['created_assignment'] ?? [] );
        if ( [] === $recorded
            || KeywordAssignmentValidationFixtureRepository::MANUAL_SOURCE_TYPE !== (string) ( $recorded['source_type'] ?? '' )
            || $this->fixtures->source_reference_for_token( $token ) !== (string) ( $recorded['source_reference'] ?? '' ) ) {
            return $this->refuse_recovery( $report, $fixture, 'fixture_source_metadata_mismatch_refusing_recovery', $execute, $actor );
        }

        $stored = $this->reviews->find_by_id( $review_id );
        if ( null === $stored ) { return $this->refuse_recovery( $report, $fixture, 'review_record_not_found', $execute, $actor ); }

        // The review must be EXACTLY the identity this fixture occupied:
        // its review_key is re-derived from the fixture's recorded
        // assignment. Unrelated skipped reviews are refused here.
        $expected_key = $this->reviews->review_key( [
            'migration_version'    => KeywordAssignmentMigrationAnalyzer::MIGRATION_VERSION,
            'keyword_candidate_id' => (int) ( $recorded['keyword_candidate_id'] ?? 0 ),
            'pool'                 => (string) ( $recorded['pool'] ?? '' ),
            'page_type'            => (string) ( $recorded['page_type'] ?? '' ),
            'target_type'          => (string) ( $recorded['target_type'] ?? '' ),
            'target_id'            => (int) ( $recorded['target_id'] ?? 0 ),
            'target_key'           => (string) ( $recorded['target_key'] ?? '' ),
        ] );
        if ( (string) $stored['review_key'] !== $expected_key ) {
            return $this->refuse_recovery( $report, $fixture, 'review_not_skipped_by_this_fixture', $execute, $actor );
        }

        // The fixture assignment must actually be gone, and no unrelated
        // assignment may have replaced it on the identity.
        $current = $this->assignments->find_assignment( (int) $recorded['keyword_candidate_id'], $recorded );
        if ( is_array( $current ) ) {
            $error = $this->is_fixture_assignment( $current, $token )
                ? 'fixture_assignment_still_present_refusing_recovery'
                : 'assignment_identity_reoccupied_refusing_recovery';
            return $this->refuse_recovery( $report, $fixture, $error, $execute, $actor );
        }

        $execution_state = (string) $stored['execution_state'];
        $recovery_reason = self::RECOVERY_REASON_PREFIX . $token;

        // Idempotency: recovery already performed (and possibly synced).
        if ( 'stale' === $execution_state && (string) ( $stored['stale_reason'] ?? '' ) === $recovery_reason ) {
            $report['ok'] = true;
            $report['outcome'] = 'already_recovered';
            $report['next_step'] = $this->sync_next_step( (int) $stored['keyword_candidate_id'] );
            return $report;
        }
        if ( 'not_executed' === $execution_state ) {
            $report['ok'] = true;
            $report['outcome'] = 'already_recovered_and_synced';
            return $report;
        }
        if ( 'skipped' !== $execution_state || 'manual_assignment_preserved' !== (string) ( $stored['execution_result'] ?? '' ) ) {
            return $this->refuse_recovery( $report, $fixture, 'review_not_in_recoverable_state_' . $execution_state, $execute, $actor );
        }

        if ( ! $execute ) {
            $report['ok'] = true;
            $report['outcome'] = 'would_recover_manual_review';
            $report['next_step'] = $this->sync_next_step( (int) $stored['keyword_candidate_id'] );
            return $report;
        }

        // EXISTING workflow: the audited execution state machine allows
        // skipped -> stale; the existing scoped sync then restores
        // stale -> not_executed once the fresh plan matches the snapshot.
        //
        // Rev 3 atomicity: ONE outer transaction covers the review
        // transition, its review-audit row, and the fixture recovery audit
        // — they commit together or not at all. The review repository joins
        // the external transaction (its own verbs become no-ops; nested
        // START TRANSACTION would implicitly commit), and participation is
        // cleared in finally on every path.
        $this->reviews->join_external_transaction();
        try {
            if ( ! $this->fixtures->transaction( 'START TRANSACTION' ) ) {
                return $this->refuse( $report, 'transaction_start_failed' );
            }
            $marked = $this->reviews->mark_execution( $review_id, 'stale', $recovery_reason, $actor, self::SOURCE );
            if ( empty( $marked['ok'] ) ) {
                $this->fixtures->transaction( 'ROLLBACK' );
                $this->reviews->leave_external_transaction(); // refusal audit below is standalone, not part of the rolled-back unit
                return $this->refuse_recovery( $report, $fixture, 'review_recovery_failed_' . (string) ( $marked['error'] ?? 'unknown' ), $execute, $actor );
            }
            if ( ! $this->fixtures->audit_event( $fixture, 'manual_review_recovered', 'removed', 'removed', $actor, 'review:' . $review_id, self::SOURCE ) ) {
                // Roll back the WHOLE unit: the review returns to skipped /
                // manual_assignment_preserved with no recovery review-audit
                // row and no fixture audit row.
                $this->fixtures->transaction( 'ROLLBACK' );
                return $this->refuse( $report, 'recovery_fixture_audit_failed_rolled_back' );
            }
            if ( ! $this->fixtures->transaction( 'COMMIT' ) ) {
                $this->fixtures->transaction( 'ROLLBACK' );
                return $this->refuse( $report, 'transaction_commit_failed' );
            }
        } finally {
            $this->reviews->leave_external_transaction();
        }
        $report['ok'] = true;
        $report['outcome'] = 'manual_review_recovered';
        $report['next_step'] = $this->sync_next_step( (int) $stored['keyword_candidate_id'] );
        $this->log( sprintf( 'manual review recovered token=%s review=%d', $token, $review_id ) );
        return $report;
    }

    // ══ B. Stale-plan fixture ═════════════════════════════════════════════

    /**
     * Plan/activate a stale-plan fixture for ONE explicitly named approved,
     * non-report-only, not-yet-executed review record. DRY RUN BY DEFAULT.
     *
     * The override flips the content-presence flag of the review's target
     * page — but ONLY inside the explicit run-stale-validation workflow;
     * activation alone changes nothing anywhere. The command first proves,
     * via a scoped simulation, that the override (a) makes the fresh plan
     * for EXACTLY this review differ from its reviewed snapshot and (b)
     * leaves EVERY sibling review's normalized planned snapshot unchanged —
     * any sibling change REFUSES activation.
     *
     * @return array<string,mixed>
     */
    public function create_stale_fixture( int $review_id, string $token, bool $execute = false, string $actor = 'cli' ): array {
        $report = [ 'mode' => $execute ? 'execute' : 'dry-run', 'action' => 'create-stale-fixture', 'review_id' => $review_id ];
        $token = $this->fixtures->normalize_token( $token );
        if ( '' === $token ) { return $this->refuse( $report, 'invalid_or_missing_validation_token' ); }
        if ( $review_id <= 0 ) { return $this->refuse( $report, 'missing_review_id' ); }
        $stored = $this->reviews->find_by_id( $review_id );
        if ( null === $stored ) { return $this->refuse( $report, 'review_record_not_found' ); }
        if ( ! empty( $stored['report_only'] ) ) { return $this->refuse( $report, 'report_only_review_not_usable' ); }
        if ( ! in_array( (string) $stored['classification'], KeywordAssignmentReviewRepository::EXECUTABLE_CLASSIFICATIONS, true ) ) {
            return $this->refuse( $report, 'classification_not_executable_' . (string) $stored['classification'] );
        }
        if ( 'approved' !== (string) $stored['review_state'] ) {
            return $this->refuse( $report, 'review_not_approved_current_' . (string) $stored['review_state'] );
        }
        if ( 'not_executed' !== (string) $stored['execution_state'] ) {
            return $this->refuse( $report, 'execution_state_not_not_executed_current_' . (string) $stored['execution_state'] );
        }
        $candidate_id = (int) $stored['keyword_candidate_id'];
        $target_id = (int) $stored['target_id'];
        if ( $target_id <= 0 ) { return $this->refuse( $report, 'global_or_idless_target_not_supported' ); }

        if ( [] !== $this->fixtures->list_active( KeywordAssignmentValidationFixtureRepository::TYPE_STALE, $candidate_id ) ) {
            return $this->refuse( $report, 'candidate_already_has_active_stale_fixture' );
        }
        $token_fixture = $this->fixtures->find_latest_by_token( $token );
        if ( null !== $token_fixture && 'active' === (string) $token_fixture['state'] ) {
            return $this->refuse( $report, 'token_already_has_active_fixture' );
        }

        $original_present = (int) $stored['present_in_content'];
        $override = [
            'kind'    => 'content_presence',
            'post_id' => $target_id,
            'present' => 1 !== $original_present,
        ];

        // Simulation: baseline must match the snapshot, the override must
        // change EXACTLY this review, and every sibling must stay unchanged.
        $verified = $this->verify_stale_override( $stored, $override );
        $report['override'] = $override;
        $report['sibling_effects'] = $verified['sibling_effects'] ?? [];
        if ( empty( $verified['ok'] ) ) {
            return $this->refuse( $report, (string) $verified['error'] );
        }
        $report['expected_stale_reason'] = (string) $verified['expected_stale_reason'];

        if ( ! $execute ) {
            $report['ok'] = true;
            $report['outcome'] = 'would_activate_stale_fixture';
            return $report;
        }

        if ( ! $this->fixtures->transaction( 'START TRANSACTION' ) ) { return $this->refuse( $report, 'transaction_start_failed' ); }
        $fixture = $this->fixtures->create_fixture( [
            'validation_token'     => $token,
            'fixture_type'         => KeywordAssignmentValidationFixtureRepository::TYPE_STALE,
            'keyword_candidate_id' => $candidate_id,
            'review_id'            => $review_id,
            'active_scope_key'     => KeywordAssignmentValidationFixtureRepository::stale_scope_key( $candidate_id ),
            'original_values'      => [
                'kind'                   => 'content_presence',
                'post_id'                => $target_id,
                'original_present'       => 1 === $original_present,
                'reviewed_snapshot_hash' => (string) $stored['snapshot_hash'],
                'expected_stale_reason'  => (string) $verified['expected_stale_reason'],
                'baseline_fresh_record'  => (array) $verified['baseline_record'],
            ],
            'override_values'      => $override,
            'created_by'           => $actor,
            'audit_note'           => 'review:' . $review_id,
        ], $actor, self::SOURCE );
        if ( empty( $fixture['ok'] ) ) {
            $this->fixtures->transaction( 'ROLLBACK' );
            return $this->refuse( $report, 'fixture_record_failed_' . (string) ( $fixture['error'] ?? 'unknown' ) );
        }
        if ( ! $this->fixtures->transaction( 'COMMIT' ) ) {
            $this->fixtures->transaction( 'ROLLBACK' );
            return $this->refuse( $report, 'transaction_commit_failed' );
        }
        $report['ok'] = true;
        $report['outcome'] = 'stale_fixture_activated';
        $report['fixture_id'] = (int) $fixture['id'];
        $report['next_step'] = 'wp tmwseo keyword-assignment-validation run-stale-validation --token=' . $token
            . ' --review-id=' . $review_id . ' --candidate-id=' . $candidate_id;
        $this->log( sprintf( 'stale fixture token=%s review=%d candidate=%d post=%d present=%s', $token, $review_id, $candidate_id, $target_id, $override['present'] ? '1' : '0' ) );
        return $report;
    }

    /**
     * Run the stale validation THROUGH the real executor, with the full
     * explicit validation context. DRY RUN BY DEFAULT.
     *
     * This is the ONLY place in the plugin where a stale-plan override is
     * applied: the context (token + review ID + candidate ID) is verified
     * against the ACTIVE fixture — any mismatch applies nothing and fails
     * closed — and the fixture's override is then passed as a per-call
     * argument into KeywordAssignmentReviewExecutionService::execute_approved
     * for EXACTLY the named review. The executor's own snapshot comparison
     * marks the review stale before any write; a pre-run simulation
     * re-proves the mismatch (and unchanged siblings) so the executor can
     * never be reached in a state where it would write.
     *
     * @param array<string,mixed> $context token, review_id, candidate_id
     * @return array<string,mixed>
     */
    public function run_stale_validation( array $context, bool $execute = false, string $actor = 'cli' ): array {
        $report = [ 'mode' => $execute ? 'execute' : 'dry-run', 'action' => 'run-stale-validation' ];

        $token = $this->fixtures->normalize_token( (string) ( $context['token'] ?? '' ) );
        if ( '' === $token ) { return $this->refuse( $report, 'invalid_or_missing_validation_token' ); }
        $review_id = (int) ( $context['review_id'] ?? 0 );
        if ( $review_id <= 0 ) { return $this->refuse( $report, 'missing_review_id' ); }
        $candidate_id = (int) ( $context['candidate_id'] ?? 0 );
        if ( $candidate_id <= 0 ) { return $this->refuse( $report, 'missing_candidate_id' ); }
        $report['review_id'] = $review_id;

        // Full validation context verification against the ACTIVE fixture:
        // wrong token, wrong review ID, or wrong candidate ID applies
        // nothing and fails closed.
        $fixture = $this->fixtures->find_latest_by_token( $token, KeywordAssignmentValidationFixtureRepository::TYPE_STALE );
        if ( null === $fixture ) { return $this->refuse( $report, 'no_stale_fixture_for_token' ); }
        if ( 'active' !== (string) $fixture['state'] ) { return $this->refuse( $report, 'stale_fixture_not_active' ); }
        if ( (int) $fixture['review_id'] !== $review_id ) {
            return $this->refuse( $report, 'validation_context_review_mismatch' );
        }
        if ( (int) $fixture['keyword_candidate_id'] !== $candidate_id ) {
            return $this->refuse( $report, 'validation_context_candidate_mismatch' );
        }
        $report['fixture_id'] = (int) $fixture['id'];

        $stored = $this->reviews->find_by_id( $review_id );
        if ( null === $stored ) { return $this->refuse( $report, 'review_record_not_found' ); }
        if ( 'approved' !== (string) $stored['review_state'] || 'not_executed' !== (string) $stored['execution_state'] ) {
            return $this->refuse( $report, 'review_not_runnable_' . (string) $stored['review_state'] . '_' . (string) $stored['execution_state'] );
        }
        $recorded_hash = (string) ( ( $fixture['original_values'] ?? [] )['reviewed_snapshot_hash'] ?? '' );
        if ( '' === $recorded_hash || $recorded_hash !== (string) $stored['snapshot_hash'] ) {
            return $this->refuse( $report, 'review_snapshot_changed_since_activation_restore_and_recreate_fixture' );
        }

        $override = (array) ( $fixture['override_values'] ?? [] );
        $overrides = [ $candidate_id => $override ];

        // Re-prove the mismatch (and unchanged siblings) immediately before
        // running, so the executor can never execute-and-write here.
        $verified = $this->verify_stale_override( $stored, $override );
        $report['sibling_effects'] = $verified['sibling_effects'] ?? [];
        if ( empty( $verified['ok'] ) ) {
            return $this->refuse( $report, 'override_verification_failed_' . (string) $verified['error'] );
        }
        $report['expected_stale_reason'] = (string) $verified['expected_stale_reason'];

        // The REAL executor, scoped to exactly this review, with the
        // override as an explicit per-call argument.
        //
        // Rev 3 atomicity (execute mode): ONE outer transaction covers the
        // executor's stale transition, its review-audit row, and the
        // stale_validation_executed fixture audit — they commit together or
        // not at all. The review repository joins the external transaction
        // (its own verbs become no-ops; nested START TRANSACTION would
        // implicitly commit), and participation is cleared in finally on
        // every path. Dry-run stays transaction-free: the executor writes
        // nothing.
        if ( $execute ) {
            $this->reviews->join_external_transaction();
        }
        try {
            if ( $execute && ! $this->fixtures->transaction( 'START TRANSACTION' ) ) {
                return $this->refuse( $report, 'transaction_start_failed' );
            }
            $executor_report = $this->executor->execute_approved(
                [ 'candidate_id' => $candidate_id, 'review_ids' => [ $review_id ] ],
                $execute,
                $actor,
                self::SOURCE,
                $overrides
            );
            $result = (array) ( $executor_report['results'][0] ?? [] );
            $report['executor_mode'] = (string) ( $executor_report['mode'] ?? '' );
            $report['executor_outcome'] = (string) ( $result['outcome'] ?? 'no_record_selected' );
            $report['executor_reason'] = (string) ( $result['reason'] ?? '' );
            $report['executor_counts'] = (array) ( $executor_report['counts'] ?? [] );

            if ( 'stale' !== (string) ( $result['outcome'] ?? '' ) ) {
                if ( $execute ) { $this->fixtures->transaction( 'ROLLBACK' ); } // undoes anything the defensive branch caught
                return $this->refuse( $report, 'unexpected_executor_outcome_' . (string) ( $result['outcome'] ?? 'none' ) );
            }

            if ( $execute ) {
                if ( ! $this->fixtures->audit_event( $fixture, 'stale_validation_executed', 'active', 'active', $actor, 'review:' . $review_id . ' reason:' . (string) $result['reason'], self::SOURCE ) ) {
                    // Roll back the WHOLE unit: the review returns to
                    // approved / not_executed with no stale review-audit row
                    // and no fixture audit row; no assignment write exists.
                    $this->fixtures->transaction( 'ROLLBACK' );
                    return $this->refuse( $report, 'stale_validation_fixture_audit_failed_rolled_back' );
                }
                if ( ! $this->fixtures->transaction( 'COMMIT' ) ) {
                    $this->fixtures->transaction( 'ROLLBACK' );
                    return $this->refuse( $report, 'transaction_commit_failed' );
                }
            }
        } finally {
            if ( $execute ) {
                $this->reviews->leave_external_transaction();
            }
        }

        $report['ok'] = true;
        $report['outcome'] = $execute ? 'review_marked_stale_by_real_executor' : 'would_mark_review_stale';
        $report['next_step'] = $execute
            ? 'wp tmwseo keyword-assignment-validation restore-stale-fixture --token=' . $token . ' --mode=execute'
            : 'wp tmwseo keyword-assignment-validation run-stale-validation --token=' . $token . ' --review-id=' . $review_id . ' --candidate-id=' . $candidate_id . ' --mode=execute';
        $this->log( sprintf( 'stale validation %s token=%s review=%d outcome=%s', $execute ? 'executed' : 'dry-run', $token, $review_id, (string) $result['outcome'] ) );
        return $report;
    }

    /**
     * Deactivate a stale-plan fixture. DRY RUN BY DEFAULT. Restoration is
     * exact by construction: the override only ever existed as a per-call
     * argument of the explicit validation workflow, so closing the fixture
     * removes the last way it can be applied — the analyzer input was never
     * modified in the first place. Idempotent: restoring an already restored
     * token succeeds without change. The state transition and its audit row
     * commit atomically or not at all.
     *
     * @return array<string,mixed>
     */
    public function restore_stale_fixture( string $token, bool $execute = false, string $actor = 'cli' ): array {
        $report = [ 'mode' => $execute ? 'execute' : 'dry-run', 'action' => 'restore-stale-fixture' ];
        $token = $this->fixtures->normalize_token( $token );
        if ( '' === $token ) { return $this->refuse( $report, 'invalid_or_missing_validation_token' ); }
        $fixture = $this->fixtures->find_latest_by_token( $token, KeywordAssignmentValidationFixtureRepository::TYPE_STALE );
        if ( null === $fixture ) { return $this->refuse( $report, 'no_stale_fixture_for_token' ); }
        if ( 'restored' === (string) $fixture['state'] ) {
            $report['ok'] = true;
            $report['outcome'] = 'already_restored';
            return $report;
        }
        if ( 'active' !== (string) $fixture['state'] ) { return $this->refuse( $report, 'fixture_not_active' ); }
        $report['fixture_id'] = (int) $fixture['id'];
        $report['review_id'] = (int) $fixture['review_id'];

        if ( ! $execute ) {
            $report['ok'] = true;
            $report['outcome'] = 'would_restore_stale_fixture';
            return $report;
        }
        if ( ! $this->fixtures->transaction( 'START TRANSACTION' ) ) { return $this->refuse( $report, 'transaction_start_failed' ); }
        $closed = $this->fixtures->close_fixture( (int) $fixture['id'], 'restored', $actor, 'review:' . (int) $fixture['review_id'], self::SOURCE );
        if ( empty( $closed['ok'] ) ) {
            $this->fixtures->transaction( 'ROLLBACK' );
            return $this->refuse( $report, 'fixture_close_failed_' . (string) ( $closed['error'] ?? 'unknown' ) );
        }
        if ( ! $this->fixtures->transaction( 'COMMIT' ) ) {
            $this->fixtures->transaction( 'ROLLBACK' );
            return $this->refuse( $report, 'transaction_commit_failed' );
        }
        $report['ok'] = true;
        $report['outcome'] = 'stale_fixture_restored';
        $report['next_step'] = $this->sync_next_step( (int) $fixture['keyword_candidate_id'] );
        $this->log( sprintf( 'stale fixture restored token=%s review=%d', $token, (int) $fixture['review_id'] ) );
        return $report;
    }

    // ══ Status ════════════════════════════════════════════════════════════

    /** @return array<string,mixed> read-only status of all fixtures */
    public function status(): array {
        return [
            'mode'            => 'read-only',
            'action'          => 'status',
            'ok'              => true,
            'counts'          => $this->fixtures->state_counts(),
            'active_fixtures' => $this->fixtures->list_active(),
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /** The assignment row belongs to the fixture identified by $token. */
    private function is_fixture_assignment( array $assignment, string $token ): bool {
        return KeywordAssignmentValidationFixtureRepository::MANUAL_SOURCE_TYPE === (string) ( $assignment['source_type'] ?? '' )
            && $this->fixtures->source_reference_for_token( $token ) === (string) ( $assignment['source_reference'] ?? '' );
    }

    /**
     * Prove, by read-only simulation, that the override changes EXACTLY the
     * target review's fresh record and nothing else for the candidate:
     *
     * - baseline (no override) must still match the reviewed snapshot;
     * - the simulated target record must differ (or vanish);
     * - every OTHER fresh record of the candidate must be identical between
     *   baseline and simulation — a changed, appearing, or vanishing sibling
     *   record refuses the operation;
     * - additionally, every stored sibling review is reported (and must be
     *   'unchanged').
     *
     * @param array<string,mixed> $stored   stored review record
     * @param array<string,mixed> $override single content_presence override
     * @return array{ok:bool,error?:string,expected_stale_reason?:string,baseline_record?:array<string,mixed>,sibling_effects:array<int,array<string,mixed>>}
     */
    private function verify_stale_override( array $stored, array $override ): array {
        $candidate_id = (int) $stored['keyword_candidate_id'];
        $review_key = (string) $stored['review_key'];
        $overrides = [ $candidate_id => $override ];

        $baseline = $this->fresh_records_for_candidate( $candidate_id, [] );
        $simulated = $this->fresh_records_for_candidate( $candidate_id, $overrides );

        $baseline_record = $baseline[ $review_key ] ?? null;
        if ( null === $baseline_record ) {
            return [ 'ok' => false, 'error' => 'review_already_mismatched_fresh_plan_missing_resync_first', 'sibling_effects' => [] ];
        }
        $baseline_changed = $this->reviews->changed_snapshot_fields( $stored, $baseline_record );
        if ( [] !== $baseline_changed ) {
            return [ 'ok' => false, 'error' => 'review_already_mismatched_' . implode( ',', $baseline_changed ) . '_resync_first', 'sibling_effects' => [] ];
        }

        // Sibling discipline: EVERY fresh record other than the target must
        // be byte-identical between baseline and simulation.
        $sibling_effects = [];
        $sibling_changed = false;
        foreach ( array_unique( array_merge( array_keys( $baseline ), array_keys( $simulated ) ) ) as $key ) {
            if ( $key === $review_key ) { continue; }
            $before = $baseline[ $key ] ?? null;
            $after  = $simulated[ $key ] ?? null;
            if ( $before !== $after ) { $sibling_changed = true; }
        }
        foreach ( $this->reviews->list_reviews( [ 'keyword_candidate_id' => $candidate_id, 'report_only' => 0 ] ) as $sibling ) {
            if ( (int) $sibling['id'] === (int) $stored['id'] ) { continue; }
            $key = (string) $sibling['review_key'];
            $before = $baseline[ $key ] ?? null;
            $after  = $simulated[ $key ] ?? null;
            $sibling_effects[] = [
                'review_id'  => (int) $sibling['id'],
                'target_key' => (string) $sibling['target_key'],
                'effect'     => $before === $after ? 'unchanged' : 'planned_record_would_change',
            ];
        }
        if ( $sibling_changed ) {
            return [ 'ok' => false, 'error' => 'sibling_plan_would_change_refusing_activation', 'sibling_effects' => $sibling_effects ];
        }

        $simulated_record = $simulated[ $review_key ] ?? null;
        if ( null === $simulated_record ) {
            $expected = 'planned_action_no_longer_produced';
        } else {
            $changed = $this->reviews->changed_snapshot_fields( $stored, $simulated_record );
            if ( [] === $changed ) {
                return [ 'ok' => false, 'error' => 'override_ineffective_fresh_plan_unchanged', 'sibling_effects' => $sibling_effects ];
            }
            $expected = 'planned_action_changed:' . implode( ',', $changed );
        }
        return [
            'ok'                    => true,
            'expected_stale_reason' => $expected,
            'baseline_record'       => $baseline_record,
            'sibling_effects'       => $sibling_effects,
        ];
    }

    /**
     * Fresh normalized review records for one candidate from a scoped
     * READ-ONLY analysis. $overrides is a strictly per-call argument (see
     * KeywordAssignmentMigrationService::analyze()); [] is the exact
     * production analyzer path.
     *
     * @param array<int,array<string,mixed>> $overrides
     * @return array<string,array<string,mixed>> review_key => record
     */
    private function fresh_records_for_candidate( int $candidate_id, array $overrides ): array {
        $analysis = $this->migration->analyze( [ 'candidate_id' => $candidate_id ], $overrides );
        return $this->sync->collect_fresh_records( $analysis );
    }

    /**
     * Audited refusal for recover-manual-review (execute mode only). The
     * refusal audit is REQUIRED (rev 3, option A): if the audit insertion
     * fails, the command returns the distinct non-zero error
     * refusal_audit_failed:<original-reason> and never claims the refusal
     * was audited. Standalone single-row insert — never part of a
     * transaction that could roll it back.
     */
    private function refuse_recovery( array $report, array $fixture, string $error, bool $execute, string $actor ): array {
        if ( $execute && ! $this->fixtures->audit_event( $fixture, 'recover_manual_review_refused', (string) $fixture['state'], (string) $fixture['state'], $actor, $error, self::SOURCE ) ) {
            return $this->refuse( $report, 'refusal_audit_failed:' . $error );
        }
        return $this->refuse( $report, $error );
    }

    private function sync_next_step( int $candidate_id ): string {
        return 'wp tmwseo keyword-assignment-review sync --candidate-id=' . $candidate_id
            . '  (existing reviewed workflow restores the review to not_executed once the fresh plan matches again)';
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private function refuse( array $report, string $error ): array {
        $report['ok'] = false;
        $report['error'] = $error;
        return $report;
    }

    private function log( string $message ): void {
        if ( KeywordAssignmentValidationFixtureRepository::debug_logging_enabled() ) {
            error_log( self::LOG_TAG . ' ' . $message );
        }
    }
}
