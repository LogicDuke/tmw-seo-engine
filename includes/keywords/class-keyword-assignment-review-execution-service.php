<?php
/**
 * TMW SEO Engine — Keyword Assignment Review Execution Service (PR-E).
 *
 * Executes ONLY explicitly approved review records, dry-run first. Before
 * any write, the CURRENT analyzer output is re-derived (read-only) and the
 * fresh planned action for each record must exactly match the reviewed
 * snapshot (classification, target identity, role, planned status,
 * canonical-owner flag, source attribution). Any difference marks the record
 * stale instead of executing it — the system fails closed on every ambiguity.
 *
 * EXECUTION POLICY (PR-E):
 * - executes: approved clear_primary_owner and secondary_assignment records
 *   whose fresh plan matches the reviewed snapshot;
 * - refuses without state change: unused_owner (approvable for recording,
 *   never bulk-activated in PR-E) and any other classification;
 * - never executes: pending, rejected, deferred, stale, executed, or
 *   report-only records;
 * - writes ONLY through KeywordAssignmentRepository; manual assignments are
 *   preserved (recorded as skipped); identical migration rows are recorded
 *   as executed no-ops; concurrent active-primary conflicts fail closed as
 *   'failed' and never force ownership;
 * - restartable and idempotent: executed records are never re-run; failed
 *   records may be retried.
 *
 * Nothing here mutates candidate rows, Rank Math metadata, page content,
 * postmeta ownership, or any production read path.
 *
 * Log tag: [TMW-KW-ASSIGN-REVIEW]
 *
 * @package TMWSEO\Engine\Keywords
 * @since   5.9.24
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class KeywordAssignmentReviewExecutionService {

    public const LOG_TAG = KeywordAssignmentReviewRepository::LOG_TAG;

    /** Evidence-level filters passed through to the migration analyzer. */
    private const EVIDENCE_FILTERS = [ 'keyword', 'candidate_id', 'target_id', 'pool' ];

    private KeywordAssignmentMigrationService $migration;
    private KeywordAssignmentReviewRepository $reviews;
    private KeywordAssignmentReviewSyncService $sync;
    private KeywordAssignmentRepository $assignments;

    public function __construct(
        ?KeywordAssignmentMigrationService $migration = null,
        ?KeywordAssignmentReviewRepository $reviews = null,
        ?KeywordAssignmentReviewSyncService $sync = null,
        ?KeywordAssignmentRepository $assignments = null
    ) {
        $this->migration   = $migration ?: new KeywordAssignmentMigrationService();
        $this->reviews     = $reviews ?: new KeywordAssignmentReviewRepository();
        $this->sync        = $sync ?: new KeywordAssignmentReviewSyncService( $this->migration, $this->reviews );
        $this->assignments = $assignments ?: new KeywordAssignmentRepository();
    }

    /**
     * Execute approved review records. DRY RUN BY DEFAULT: with
     * $execute = false nothing is written anywhere (no assignment writes,
     * no review-record mutation, no audit rows) — the report states exactly
     * what a real run would do.
     *
     * @param array<string,mixed> $filters candidate_id, pool, target_id,
     *        keyword, classification, review_ids (array of explicit IDs)
     * @return array<string,mixed> execution report
     */
    public function execute_approved( array $filters = [], bool $execute = false, string $actor = 'cli', string $source = 'review-execute' ): array {
        $selection = $this->select_records( $filters );
        $records = $selection['records'];
        $counts = [ 'selected' => count( $records ), 'executed' => 0, 'noop' => 0, 'skipped' => 0, 'stale' => 0, 'refused_classification' => 0, 'failed' => 0 ];
        $results = [];

        // One fresh READ-ONLY analysis for verification. Evidence filters are
        // passed through when provided; explicit review-ID selections verify
        // against the full analysis so no identity escapes re-checking.
        $evidence_filters = array_intersect_key( $filters, array_flip( self::EVIDENCE_FILTERS ) );
        $analysis = $this->migration->analyze( $evidence_filters );
        $fresh_actions = $this->index_fresh_actions( $analysis );

        foreach ( $records as $stored ) {
            $review_id = (int) $stored['id'];
            $entry = [
                'review_id'          => $review_id,
                'candidate_id'       => (int) $stored['keyword_candidate_id'],
                'normalized_keyword' => (string) $stored['normalized_keyword'],
                'classification'     => (string) $stored['classification'],
                'target_key'         => (string) $stored['target_key'],
                'planned_role'       => (string) $stored['planned_role'],
            ];

            if ( ! in_array( (string) $stored['classification'], KeywordAssignmentReviewRepository::EXECUTABLE_CLASSIFICATIONS, true ) ) {
                // unused_owner and anything else: refused, no state change —
                // an approval for recording is never silently activated.
                $counts['refused_classification']++;
                $entry['outcome'] = 'refused_classification_not_executable';
                $results[] = $entry;
                continue;
            }

            $review_key = (string) $stored['review_key'];
            $fresh = $fresh_actions[ $review_key ] ?? null;
            if ( null === $fresh ) {
                $counts['stale']++;
                $entry['outcome'] = 'stale';
                $entry['reason'] = 'planned_action_no_longer_produced';
                if ( $execute ) {
                    $this->reviews->mark_stale( $stored, 'planned_action_no_longer_produced', $actor, $source );
                }
                $results[] = $entry;
                continue;
            }
            $changed = $this->reviews->changed_snapshot_fields( $stored, $fresh['record'] );
            if ( [] !== $changed ) {
                $counts['stale']++;
                $entry['outcome'] = 'stale';
                $entry['reason'] = 'planned_action_changed:' . implode( ',', $changed );
                if ( $execute ) {
                    $this->reviews->mark_stale( $stored, (string) $entry['reason'], $actor, $source );
                }
                $results[] = $entry;
                continue;
            }

            $kind = (string) $fresh['action']['action'];
            if ( ! $execute ) {
                $entry['outcome'] = 'would_execute';
                $entry['fresh_action'] = $kind;
                $counts[ in_array( $kind, [ 'unchanged' ], true ) ? 'noop' : ( 'preserve' === $kind ? 'skipped' : 'executed' ) ]++;
                $results[] = $entry;
                continue;
            }

            $outcome = $this->apply_fresh_action( $stored, $fresh['action'], $actor, $source );
            $counts[ $outcome['bucket'] ]++;
            $entry['outcome'] = $outcome['outcome'];
            if ( '' !== (string) ( $outcome['error'] ?? '' ) ) { $entry['error'] = (string) $outcome['error']; }
            $results[] = $entry;
        }

        $report = [
            'migration_version' => KeywordAssignmentMigrationAnalyzer::MIGRATION_VERSION,
            'generated_at'      => (string) ( $analysis['generated_at'] ?? '' ),
            'mode'              => $execute ? 'execute-approved' : 'execute-approved-dry-run',
            'filters'           => $filters,
            'selection'         => $selection['summary'],
            'counts'            => $counts,
            'results'           => $results,
        ];
        $this->log( sprintf( '%s selected=%d executed=%d noop=%d skipped=%d stale=%d refused=%d failed=%d', (string) $report['mode'], $counts['selected'], $counts['executed'], $counts['noop'], $counts['skipped'], $counts['stale'], $counts['refused_classification'], $counts['failed'] ) );
        return $report;
    }

    /**
     * Select ONLY approved, non-report-only records in a runnable execution
     * state (not_executed or failed for retry). Pending, rejected, deferred,
     * stale, executed, skipped, and report-only records are excluded at the
     * query level and can never reach the write path.
     *
     * @param array<string,mixed> $filters
     * @return array{records:array<int,array<string,mixed>>,summary:array<string,mixed>}
     */
    private function select_records( array $filters ): array {
        $stored_filters = [
            'migration_version' => KeywordAssignmentMigrationAnalyzer::MIGRATION_VERSION,
            'review_state'      => 'approved',
            'execution_state'   => [ 'not_executed', 'failed' ],
            'report_only'       => 0,
        ];
        $aliases = [ 'candidate_id' => 'keyword_candidate_id', 'keyword' => 'normalized_keyword' ];
        foreach ( $filters as $column => $value ) {
            $column = $aliases[ $column ] ?? $column;
            if ( 'review_ids' !== $column && in_array( (string) $column, KeywordAssignmentReviewRepository::FILTERABLE_COLUMNS, true ) ) {
                $stored_filters[ $column ] = $value;
            }
        }

        $records = $this->reviews->list_reviews( $stored_filters );

        $review_ids = array_values( array_filter( array_map( 'intval', (array) ( $filters['review_ids'] ?? [] ) ), fn ( $id ) => $id > 0 ) );
        if ( [] !== $review_ids ) {
            $records = array_values( array_filter( $records, fn ( $row ) => in_array( (int) $row['id'], $review_ids, true ) ) );
        }
        return [
            'records' => $records,
            'summary' => [
                'review_state'    => 'approved',
                'execution_state' => [ 'not_executed', 'failed' ],
                'explicit_ids'    => $review_ids,
                'matched'         => count( $records ),
            ],
        ];
    }

    /**
     * Index fresh writable planned actions (and their normalized review
     * records) by deterministic review identity.
     *
     * @param array<string,mixed> $analysis
     * @return array<string,array{action:array<string,mixed>,record:array<string,mixed>}>
     */
    private function index_fresh_actions( array $analysis ): array {
        $records = $this->sync->collect_fresh_records( $analysis );
        $indexed = [];
        foreach ( (array) ( $analysis['decisions'] ?? [] ) as $decision ) {
            if ( empty( $decision['writable'] ) ) { continue; }
            foreach ( (array) ( $decision['planned_actions'] ?? [] ) as $action ) {
                $payload = (array) ( $action['payload'] ?? [] );
                if ( [] === $payload ) { continue; }
                $key = $this->reviews->review_key( [
                    'migration_version'    => KeywordAssignmentMigrationAnalyzer::MIGRATION_VERSION,
                    'keyword_candidate_id' => (int) ( $payload['keyword_candidate_id'] ?? 0 ),
                    'pool'                 => (string) ( $payload['pool'] ?? '' ),
                    'page_type'            => (string) ( $payload['page_type'] ?? '' ),
                    'target_type'          => (string) ( $payload['target_type'] ?? '' ),
                    'target_id'            => (int) ( $payload['target_id'] ?? 0 ),
                    'target_key'           => (string) ( $payload['target_key'] ?? '' ),
                ] );
                if ( isset( $indexed[ $key ] ) || ! isset( $records[ $key ] ) ) { continue; }
                $indexed[ $key ] = [ 'action' => $action, 'record' => $records[ $key ] ];
            }
        }
        return $indexed;
    }

    /**
     * Apply one verified fresh action through the assignment repository and
     * record the per-record execution result.
     *
     * @param array<string,mixed> $stored
     * @param array<string,mixed> $action
     * @return array{bucket:string,outcome:string,error?:string}
     */
    private function apply_fresh_action( array $stored, array $action, string $actor, string $source ): array {
        $review_id = (int) $stored['id'];
        $kind = (string) ( $action['action'] ?? '' );
        $payload = (array) ( $action['payload'] ?? [] );

        if ( 'preserve' === $kind ) {
            // Existing manual assignment on this identity: NEVER mutated.
            $this->reviews->mark_execution( $review_id, 'skipped', 'manual_assignment_preserved', $actor, $source );
            return [ 'bucket' => 'skipped', 'outcome' => 'skipped_manual_assignment_preserved' ];
        }
        if ( 'unchanged' === $kind ) {
            // Identical migration assignment already present: executing is a
            // recorded no-op, which is what makes re-execution idempotent.
            $this->reviews->mark_execution( $review_id, 'executed', 'already_current_noop', $actor, $source );
            return [ 'bucket' => 'noop', 'outcome' => 'executed_noop_already_current' ];
        }
        if ( 'insert' === $kind ) {
            $result = $this->assignments->create_assignment( $payload );
            if ( empty( $result['ok'] ) ) {
                $error = (string) ( $result['error'] ?? 'insert_failed' );
                // Concurrent active primary or any repository refusal: fail
                // closed, record the error, never force ownership.
                $this->reviews->mark_execution( $review_id, 'failed', $error, $actor, $source );
                return [ 'bucket' => 'failed', 'outcome' => 'failed', 'error' => $error ];
            }
            $this->reviews->mark_execution( $review_id, 'executed', 'inserted_assignment_' . (int) $result['id'], $actor, $source );
            return [ 'bucket' => 'executed', 'outcome' => 'executed_inserted' ];
        }
        if ( 'update' === $kind ) {
            $partial = array_intersect_key( $payload, array_flip( [
                'keyword_candidate_id', 'pool', 'page_type', 'target_type', 'target_id', 'target_key', 'role', 'canonical_owner',
            ] ) );
            foreach ( (array) ( $action['changed_fields'] ?? [] ) as $field ) {
                if ( ! array_key_exists( $field, $payload ) ) {
                    $error = 'missing_payload_field_' . (string) $field;
                    $this->reviews->mark_execution( $review_id, 'failed', $error, $actor, $source );
                    return [ 'bucket' => 'failed', 'outcome' => 'failed', 'error' => $error ];
                }
                $partial[ $field ] = $payload[ $field ];
            }
            $result = $this->assignments->upsert_assignment( $partial );
            if ( empty( $result['ok'] ) ) {
                $error = (string) ( $result['error'] ?? 'update_failed' );
                $this->reviews->mark_execution( $review_id, 'failed', $error, $actor, $source );
                return [ 'bucket' => 'failed', 'outcome' => 'failed', 'error' => $error ];
            }
            $this->reviews->mark_execution( $review_id, 'executed', 'updated_assignment_' . (int) $result['id'], $actor, $source );
            return [ 'bucket' => 'executed', 'outcome' => 'executed_updated' ];
        }
        $error = 'unsupported_action_' . $kind;
        $this->reviews->mark_execution( $review_id, 'failed', $error, $actor, $source );
        return [ 'bucket' => 'failed', 'outcome' => 'failed', 'error' => $error ];
    }

    private function log( string $message ): void {
        error_log( self::LOG_TAG . ' ' . $message );
    }
}
