<?php
/**
 * TMW SEO Engine — Keyword Assignment Review Sync Service (PR-E).
 *
 * Imports the CURRENT migration analyzer output into the persistent review
 * queue. Arbitrary external JSON is never trusted: every sync re-runs
 * KeywordAssignmentMigrationService::analyze() (read-only) and derives review
 * records from the fresh planned actions only.
 *
 * SYNC CONTRACT (restartable and idempotent):
 * - one review record per deterministic planned-action identity;
 * - new identities are created as pending / not_executed;
 * - pending records are refreshed in place when the plan changed;
 * - human-reviewed records (approved/rejected/deferred) are PRESERVED when
 *   the plan is unchanged, and marked STALE when it changed — never silently
 *   re-approved, never auto-converted back to pending;
 * - stale records whose fresh plan again matches the reviewed snapshot are
 *   restored to not_executed (review state untouched);
 * - executed records are never modified by sync;
 * - identities inside the sync scope that the fresh analyzer no longer plans
 *   are marked stale (skipped when the scope is truncated by a limit filter,
 *   because absence cannot be proven from a partial analysis);
 * - report-only classifications create records only when explicitly included,
 *   always flagged report_only and never approvable/executable.
 *
 * Log tag: [TMW-KW-ASSIGN-REVIEW]
 *
 * @package TMWSEO\Engine\Keywords
 * @since   5.9.24
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class KeywordAssignmentReviewSyncService {

    public const LOG_TAG = KeywordAssignmentReviewRepository::LOG_TAG;

    /** Evidence-level filters passed through to the migration analyzer. */
    private const EVIDENCE_FILTERS = [ 'keyword', 'candidate_id', 'target_id', 'pool', 'limit' ];

    /** Decision-level filters applied to fresh analyzer decisions locally. */
    private const DECISION_FILTERS = [ 'classification', 'candidate_status', 'source_type', 'active_in_rank_math', 'present_in_content' ];

    private KeywordAssignmentMigrationService $migration;
    private KeywordAssignmentReviewRepository $reviews;

    public function __construct(
        ?KeywordAssignmentMigrationService $migration = null,
        ?KeywordAssignmentReviewRepository $reviews = null
    ) {
        $this->migration = $migration ?: new KeywordAssignmentMigrationService();
        $this->reviews   = $reviews ?: new KeywordAssignmentReviewRepository();
    }

    /**
     * Synchronize review records from a fresh read-only analysis.
     *
     * @param array<string,mixed> $filters generic sync filters (see class docs)
     * @param bool $include_report_only also create report-only records for
     *        non-writable classifications (never approvable, never executable)
     * @param string $actor operator identity for the audit trail
     * @param string $source command/action source for the audit trail
     * @return array<string,mixed> sync report
     */
    public function sync( array $filters = [], bool $include_report_only = false, string $actor = 'cli', string $source = 'review-sync' ): array {
        $evidence_filters = array_intersect_key( $filters, array_flip( self::EVIDENCE_FILTERS ) );
        $decision_filters = array_intersect_key( $filters, array_flip( self::DECISION_FILTERS ) );

        $analysis = $this->migration->analyze( $evidence_filters );
        $fresh = $this->collect_fresh_records( $analysis, $decision_filters, $include_report_only );

        $counts = [ 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'preserved' => 0, 'restored' => 0, 'stale' => 0, 'skipped' => 0, 'failed' => 0 ];
        $failures = [];

        foreach ( $fresh as $review_key => $record ) {
            $stored = $this->reviews->find_by_review_key( $review_key );
            if ( null === $stored ) {
                $result = $this->reviews->create_review( $record, $actor, $source );
                $this->tally( $counts, $failures, ! empty( $result['ok'] ) ? 'inserted' : 'failed', $record, (string) ( $result['error'] ?? '' ) );
                continue;
            }
            if ( 'executed' === (string) $stored['execution_state'] ) {
                // Executed history is immutable; the fresh plan for an
                // executed identity is expected to be 'unchanged'.
                $counts['preserved']++;
                continue;
            }
            $changed = $this->reviews->changed_snapshot_fields( $stored, $record );
            $review_state = (string) $stored['review_state'];
            if ( [] === $changed ) {
                if ( 'stale' === (string) $stored['execution_state'] ) {
                    $result = $this->reviews->restore_from_stale( $stored, $actor, $source );
                    $this->tally( $counts, $failures, ! empty( $result['ok'] ) ? 'restored' : 'failed', $record, (string) ( $result['error'] ?? '' ) );
                    continue;
                }
                $counts[ 'pending' === $review_state ? 'unchanged' : 'preserved' ]++;
                continue;
            }
            if ( 'pending' === $review_state ) {
                $result = $this->reviews->refresh_pending_snapshot( $stored, $record, $actor, $source );
                $this->tally( $counts, $failures, ! empty( $result['ok'] ) ? 'updated' : 'failed', $record, (string) ( $result['error'] ?? '' ) );
                continue;
            }
            // Human-reviewed record whose plan changed: stale, review state
            // preserved. Rejected/deferred records are never auto-pending.
            $result = $this->reviews->mark_stale( $stored, 'planned_action_changed:' . implode( ',', $changed ), $actor, $source );
            $this->tally( $counts, $failures, ! empty( $result['ok'] ) ? 'stale' : 'failed', $record, (string) ( $result['error'] ?? '' ) );
        }

        // Identities inside the sync scope that the fresh analysis no longer
        // plans at all. Absence is provable only from a complete analysis, so
        // a limit-truncated run skips this phase entirely.
        $missing_checked = ! isset( $evidence_filters['limit'] ) || (int) $evidence_filters['limit'] <= 0;
        if ( $missing_checked ) {
            foreach ( $this->stored_records_in_scope( $filters ) as $stored ) {
                $key = (string) $stored['review_key'];
                if ( isset( $fresh[ $key ] ) ) { continue; }
                $execution_state = (string) $stored['execution_state'];
                if ( in_array( $execution_state, [ 'executed', 'stale' ], true ) ) { continue; }
                if ( ! empty( $stored['report_only'] ) && ! $include_report_only ) { continue; }
                $result = $this->reviews->mark_stale( $stored, 'planned_action_no_longer_produced', $actor, $source );
                $this->tally( $counts, $failures, ! empty( $result['ok'] ) ? 'stale' : 'failed', $stored, (string) ( $result['error'] ?? '' ) );
            }
        }

        $report = [
            'migration_version'    => KeywordAssignmentMigrationAnalyzer::MIGRATION_VERSION,
            'generated_at'         => (string) ( $analysis['generated_at'] ?? '' ),
            'mode'                 => 'sync',
            'filters'              => $filters,
            'include_report_only'  => $include_report_only,
            'fresh_planned_records'=> count( $fresh ),
            'missing_check_ran'    => $missing_checked,
            'counts'               => $counts,
            'failures'             => $failures,
        ];
        $this->log( sprintf( 'sync complete fresh=%d inserted=%d updated=%d unchanged=%d preserved=%d restored=%d stale=%d skipped=%d failed=%d', count( $fresh ), $counts['inserted'], $counts['updated'], $counts['unchanged'], $counts['preserved'], $counts['restored'], $counts['stale'], $counts['skipped'], $counts['failed'] ) );
        return $report;
    }

    /**
     * Derive normalized review records from fresh analyzer decisions,
     * keyed by deterministic review identity. Duplicate planned actions for
     * one identity within one analysis collapse deterministically onto the
     * first (analyzer output is already deterministic and sorted).
     *
     * @param array<string,mixed> $analysis
     * @param array<string,mixed> $decision_filters
     * @return array<string,array<string,mixed>>
     */
    public function collect_fresh_records( array $analysis, array $decision_filters = [], bool $include_report_only = false ): array {
        $records = [];
        foreach ( (array) ( $analysis['decisions'] ?? [] ) as $decision ) {
            $classification = (string) ( $decision['classification'] ?? '' );
            $writable = ! empty( $decision['writable'] );
            if ( ! $writable && ! $include_report_only ) { continue; }
            if ( isset( $decision_filters['classification'] ) && (string) $decision_filters['classification'] !== $classification ) { continue; }
            if ( isset( $decision_filters['candidate_status'] ) && (string) $decision_filters['candidate_status'] !== (string) ( $decision['candidate_status'] ?? '' ) ) { continue; }

            if ( $writable ) {
                foreach ( (array) ( $decision['planned_actions'] ?? [] ) as $action ) {
                    $record = $this->record_from_planned_action( $decision, $action, false );
                    if ( null === $record ) { continue; }
                    if ( ! $this->passes_record_filters( $record, $decision_filters ) ) { continue; }
                    $key = $this->reviews->review_key( $record );
                    if ( ! isset( $records[ $key ] ) ) { $records[ $key ] = $record; }
                }
                continue;
            }
            // Report-only decision: one record per resolved target as pure
            // documentation. Never approvable, never executable.
            foreach ( (array) ( $decision['targets'] ?? [] ) as $target ) {
                $record = $this->record_from_report_target( $decision, $target );
                if ( null === $record ) { continue; }
                if ( ! $this->passes_record_filters( $record, $decision_filters ) ) { continue; }
                $key = $this->reviews->review_key( $record );
                if ( ! isset( $records[ $key ] ) ) { $records[ $key ] = $record; }
            }
        }
        ksort( $records );
        return $records;
    }

    /**
     * @param array<string,mixed> $decision
     * @param array<string,mixed> $action
     * @return array<string,mixed>|null
     */
    private function record_from_planned_action( array $decision, array $action, bool $report_only ): ?array {
        $payload = (array) ( $action['payload'] ?? [] );
        if ( [] === $payload ) { return null; }
        $assignment_repository = new KeywordAssignmentRepository();
        return [
            'migration_version'       => KeywordAssignmentMigrationAnalyzer::MIGRATION_VERSION,
            'keyword_candidate_id'    => (int) ( $payload['keyword_candidate_id'] ?? $decision['candidate_id'] ?? 0 ),
            'assignment_key'          => $assignment_repository->assignment_key( $payload ),
            'normalized_keyword'      => (string) ( $decision['normalized_keyword'] ?? '' ),
            'classification'          => (string) ( $decision['classification'] ?? '' ),
            'candidate_status'        => (string) ( $decision['candidate_status'] ?? '' ),
            'planned_action'          => (string) ( $action['action'] ?? '' ),
            'pool'                    => (string) ( $payload['pool'] ?? '' ),
            'page_type'               => (string) ( $payload['page_type'] ?? '' ),
            'target_type'             => (string) ( $payload['target_type'] ?? '' ),
            'target_id'               => (int) ( $payload['target_id'] ?? 0 ),
            'target_key'              => (string) ( $payload['target_key'] ?? '' ),
            'target_name'             => (string) ( $payload['target_name'] ?? '' ),
            'planned_role'            => (string) ( $payload['role'] ?? '' ),
            'planned_status'          => (string) ( $payload['status'] ?? '' ),
            'planned_canonical_owner' => (int) ( $payload['canonical_owner'] ?? 0 ),
            'active_in_rank_math'     => (int) ( $payload['active_in_rank_math'] ?? 0 ),
            'present_in_content'      => (int) ( $payload['present_in_content'] ?? 0 ),
            'source_type'             => (string) ( $payload['source_type'] ?? '' ),
            'source_reference'        => (string) ( $payload['source_reference'] ?? '' ),
            'source_batch_id'         => (int) ( $payload['source_batch_id'] ?? 0 ),
            'source_import_row_id'    => (int) ( $payload['source_import_row_id'] ?? 0 ),
            'report_only'             => $report_only ? 1 : 0,
        ];
    }

    /**
     * @param array<string,mixed> $decision
     * @param array<string,mixed> $target
     * @return array<string,mixed>|null
     */
    private function record_from_report_target( array $decision, array $target ): ?array {
        $target_key = (string) ( $target['target_key'] ?? '' );
        if ( '' === $target_key ) { return null; }
        return [
            'migration_version'       => KeywordAssignmentMigrationAnalyzer::MIGRATION_VERSION,
            'keyword_candidate_id'    => (int) ( $decision['candidate_id'] ?? 0 ),
            'assignment_key'          => '',
            'normalized_keyword'      => (string) ( $decision['normalized_keyword'] ?? '' ),
            'classification'          => (string) ( $decision['classification'] ?? '' ),
            'candidate_status'        => (string) ( $decision['candidate_status'] ?? '' ),
            'planned_action'          => 'report_only',
            'pool'                    => 'report',
            'page_type'               => 'report',
            'target_type'             => (string) ( $target['target_type'] ?? '' ),
            'target_id'               => (int) ( $target['target_id'] ?? 0 ),
            'target_key'              => $target_key,
            'target_name'             => '',
            'planned_role'            => '',
            'planned_status'          => '',
            'planned_canonical_owner' => 0,
            'active_in_rank_math'     => 0,
            'present_in_content'      => 0,
            'source_type'             => '',
            'source_reference'        => KeywordAssignmentMigrationAnalyzer::MIGRATION_VERSION,
            'source_batch_id'         => 0,
            'source_import_row_id'    => 0,
            'report_only'             => 1,
        ];
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $filters */
    private function passes_record_filters( array $record, array $filters ): bool {
        if ( isset( $filters['source_type'] ) && (string) $filters['source_type'] !== (string) $record['source_type'] ) { return false; }
        if ( isset( $filters['active_in_rank_math'] ) && (int) $filters['active_in_rank_math'] !== (int) $record['active_in_rank_math'] ) { return false; }
        if ( isset( $filters['present_in_content'] ) && (int) $filters['present_in_content'] !== (int) $record['present_in_content'] ) { return false; }
        return true;
    }

    /**
     * Stored records covered by the current sync scope, for missing-identity
     * stale detection. Scope is derived from the same generic filters as the
     * fresh side, so the comparison is symmetric.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    private function stored_records_in_scope( array $filters ): array {
        $stored_filters = [ 'migration_version' => KeywordAssignmentMigrationAnalyzer::MIGRATION_VERSION ];
        if ( isset( $filters['candidate_id'] ) && (int) $filters['candidate_id'] > 0 ) { $stored_filters['keyword_candidate_id'] = (int) $filters['candidate_id']; }
        if ( isset( $filters['keyword'] ) && '' !== (string) $filters['keyword'] ) { $stored_filters['normalized_keyword'] = (string) $filters['keyword']; }
        if ( isset( $filters['pool'] ) && '' !== (string) $filters['pool'] ) { $stored_filters['pool'] = (string) $filters['pool']; }
        if ( isset( $filters['target_id'] ) && (int) $filters['target_id'] > 0 ) { $stored_filters['target_id'] = (int) $filters['target_id']; }
        if ( isset( $filters['classification'] ) && '' !== (string) $filters['classification'] ) { $stored_filters['classification'] = (string) $filters['classification']; }
        if ( isset( $filters['candidate_status'] ) && '' !== (string) $filters['candidate_status'] ) { $stored_filters['candidate_status'] = (string) $filters['candidate_status']; }
        if ( isset( $filters['source_type'] ) && '' !== (string) $filters['source_type'] ) { $stored_filters['source_type'] = (string) $filters['source_type']; }
        if ( isset( $filters['active_in_rank_math'] ) ) { $stored_filters['active_in_rank_math'] = (int) $filters['active_in_rank_math']; }
        if ( isset( $filters['present_in_content'] ) ) { $stored_filters['present_in_content'] = (int) $filters['present_in_content']; }
        return $this->reviews->list_reviews( $stored_filters );
    }

    /**
     * @param array<string,int> $counts
     * @param array<int,array<string,mixed>> $failures
     * @param array<string,mixed> $record
     */
    private function tally( array &$counts, array &$failures, string $bucket, array $record, string $error ): void {
        $counts[ $bucket ] = ( $counts[ $bucket ] ?? 0 ) + 1;
        if ( 'failed' === $bucket ) {
            $failures[] = [
                'candidate_id' => (int) ( $record['keyword_candidate_id'] ?? 0 ),
                'target_key'   => (string) ( $record['target_key'] ?? '' ),
                'error'        => $error,
            ];
        }
    }

    private function log( string $message ): void {
        error_log( self::LOG_TAG . ' ' . $message );
    }
}
