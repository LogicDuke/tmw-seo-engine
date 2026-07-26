<?php
/**
 * TMW SEO Engine — Keyword Assignment Migration Service (PR-D).
 *
 * Two-phase migration orchestrator:
 *
 * PHASE 1 — analyze() (DEFAULT, read-only): streams evidence rows from the
 * PR-A ownership report (candidates + import history + Rank Math metadata +
 * content presence + legacy postmeta), loads existing assignment rows, runs
 * the deterministic analyzer, and returns a structured report model. Writes
 * nothing anywhere.
 *
 * PHASE 2 — execute(): consumes the SAME analysis model and writes only
 * writable planned actions through KeywordAssignmentRepository (PR #779).
 * Inserts and partial updates only; migration-owned rows only; manual rows
 * are always preserved; conflicting/unresolved/manual-review records are
 * never written. Idempotent and restartable: re-running against unchanged
 * data yields only 'unchanged' actions and touches no timestamps.
 *
 * ROLLBACK — rollback(): scoped strictly to rows whose source_type is one of
 * the migration source types AND whose source_reference matches this
 * migration version. Dry-run rollback reports without deleting. Candidate
 * rows, Rank Math metadata, content, and postmeta are never touched by any
 * phase.
 *
 * Log tag: [TMW-KW-ASSIGN-MIGRATE]
 *
 * @package TMWSEO\Engine\Keywords
 * @since   5.9.23
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class KeywordAssignmentMigrationService {

    public const LOG_TAG = '[TMW-KW-ASSIGN-MIGRATE]';

    private KeywordOwnershipReportService $evidence;
    private KeywordAssignmentRepository $assignments;
    private KeywordAssignmentMigrationAnalyzer $analyzer;
    private string $serialization_error = '';

    public function __construct(
        ?KeywordOwnershipReportService $evidence = null,
        ?KeywordAssignmentRepository $assignments = null,
        ?KeywordAssignmentMigrationAnalyzer $analyzer = null
    ) {
        $this->evidence    = $evidence ?: new KeywordOwnershipReportService();
        $this->assignments = $assignments ?: new KeywordAssignmentRepository();
        $this->analyzer    = $analyzer ?: new KeywordAssignmentMigrationAnalyzer();
    }

    // ── Phase 1: analysis / dry run (read-only) ───────────────────────────

    /**
     * Build the full deterministic analysis model. READ-ONLY.
     *
     * Supported generic filters (local testing only): keyword, candidate_id,
     * target_id, pool (passed through to the evidence report), limit.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed> report model (see build_report()).
     */
    public function analyze( array $filters = [] ): array {
        $limit = max( 0, (int) ( $filters['limit'] ?? 0 ) );
        unset( $filters['limit'] );

        $decisions = [];
        $classification_counts = [];
        $existing_encountered = 0;
        $duplicate_source_rows = [];
        $processed = 0;

        foreach ( $this->evidence->run( $filters ) as $evidence_row ) {
            // Keep consuming the generator after the decision limit so the
            // ownership service can finish its full-source summary counters.
            if ( $limit > 0 && $processed >= $limit ) { continue; }
            $candidate_id = (int) ( $evidence_row['candidate_id'] ?? 0 );
            $existing = $this->assignments->find_assignments_for_candidate( $candidate_id );
            $existing_encountered += count( $existing );
            foreach ( $this->collect_duplicate_source_rows( $evidence_row ) as $duplicate ) {
                $duplicate_source_rows[] = $duplicate;
            }
            $decision = $this->analyzer->analyze( $evidence_row, $existing );
            $decisions[] = $decision;
            $classification = (string) $decision['classification'];
            $classification_counts[ $classification ] = ( $classification_counts[ $classification ] ?? 0 ) + 1;
            $processed++;
        }

        // Deterministic output order regardless of source iteration details.
        usort( $decisions, fn ( $a, $b ) => [ (int) $a['candidate_id'], (string) $a['normalized_keyword'] ] <=> [ (int) $b['candidate_id'], (string) $b['normalized_keyword'] ] );
        usort( $duplicate_source_rows, fn ( $a, $b ) => [ (int) $a['candidate_id'], (string) $a['target_type'], (int) $a['target_id'], (int) $a['batch_id'] ] <=> [ (int) $b['candidate_id'], (string) $b['target_type'], (int) $b['target_id'], (int) $b['batch_id'] ] );
        ksort( $classification_counts );

        if ( $limit > 0 ) { $filters['limit'] = $limit; }
        return $this->build_report( 'dry-run', $decisions, $classification_counts, $existing_encountered, $filters, [], $duplicate_source_rows );
    }

    /**
     * Compact duplicate import-row records for one evidence row: the same
     * (target, batch) referenced more than once, or the same target repeated
     * across batches. References only — no evidence duplication.
     *
     * @param array<string,mixed> $evidence_row
     * @return array<int,array<string,mixed>>
     */
    private function collect_duplicate_source_rows( array $evidence_row ): array {
        $duplicates = [];
        $seen = [];
        foreach ( (array) ( $evidence_row['import_rows'] ?? [] ) as $import_row ) {
            $type  = (string) ( $import_row['batch_target_type'] ?? '' );
            $id    = (int) ( $import_row['batch_target_id'] ?? 0 );
            $batch = (int) ( $import_row['batch_id'] ?? 0 );
            $target = $type . ':' . $id;
            if ( isset( $seen[ $target ] ) ) {
                $duplicates[] = [
                    'candidate_id'       => (int) ( $evidence_row['candidate_id'] ?? 0 ),
                    'normalized_keyword' => (string) ( $evidence_row['normalized_keyword'] ?? '' ),
                    'target_type'        => $type,
                    'target_id'          => $id,
                    'batch_id'           => $batch,
                    'reasons'            => [ isset( $seen[ $target ][ $batch ] ) ? 'duplicate_row_same_batch' : 'duplicate_row_cross_batch' ],
                ];
            }
            $seen[ $target ][ $batch ] = true;
        }
        return $duplicates;
    }

    // ── Phase 2: explicit execution ───────────────────────────────────────

    /**
     * Apply writable planned actions from a fresh analysis. Writes only
     * through the assignment repository; everything else is untouched.
     *
     * @param array<string,mixed> $filters same generic filters as analyze()
     * @return array<string,mixed> report model in 'execute' mode
     */
    public function execute( array $filters = [] ): array {
        $report = $this->analyze( $filters );
        $errors = [];
        $execution = [ 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'preserved' => 0, 'skipped' => 0, 'conflicting' => 0, 'failed' => 0 ];

        foreach ( $report['decisions'] as $index => $decision ) {
            if ( empty( $decision['writable'] ) ) {
                $execution[ $this->non_writable_bucket( (string) $decision['classification'] ) ]++;
                continue;
            }
            foreach ( $decision['planned_actions'] as $action_index => $action ) {
                $result = $this->apply_action( $action );
                $report['decisions'][ $index ]['planned_actions'][ $action_index ]['execution'] = $result;
                if ( ! empty( $result['error'] ) ) {
                    $execution['failed']++;
                    $errors[] = [
                        'candidate_id' => (int) $decision['candidate_id'],
                        'target'       => (string) ( $action['target'] ?? '' ),
                        'error'        => (string) $result['error'],
                    ];
                    continue;
                }
                $execution[ (string) $result['outcome'] ]++;
            }
        }

        $report['mode'] = 'execute';
        $report['execution'] = $execution;
        $report['execution_errors'] = $errors;
        $this->log( sprintf( 'execute complete inserted=%d updated=%d unchanged=%d preserved=%d skipped=%d conflicting=%d failed=%d', $execution['inserted'], $execution['updated'], $execution['unchanged'], $execution['preserved'], $execution['skipped'], $execution['conflicting'], $execution['failed'] ) );
        return $report;
    }

    /** @param array<string,mixed> $action @return array<string,mixed> */
    private function apply_action( array $action ): array {
        $kind = (string) ( $action['action'] ?? '' );
        $payload = (array) ( $action['payload'] ?? [] );
        if ( 'preserve' === $kind ) { return [ 'outcome' => 'preserved' ]; }
        if ( 'unchanged' === $kind ) { return [ 'outcome' => 'unchanged' ]; }
        if ( 'insert' === $kind ) {
            $result = $this->assignments->create_assignment( $payload );
            if ( empty( $result['ok'] ) ) {
                // A concurrent/manual active primary blocks creation: fail
                // closed and surface as an error, never force ownership.
                return [ 'error' => (string) ( $result['error'] ?? 'insert_failed' ) ];
            }
            return [ 'outcome' => 'inserted', 'id' => (int) $result['id'] ];
        }
        if ( 'update' === $kind ) {
            // Partial upsert: identity fields plus ONLY the changed migration-
            // mutable fields, so stable data and timestamps stay untouched
            // elsewhere and manual edits outside those fields survive.
            $partial = array_intersect_key( $payload, array_flip( [
                'keyword_candidate_id', 'pool', 'page_type', 'target_type', 'target_id', 'target_key', 'role', 'canonical_owner',
            ] ) );
            foreach ( (array) ( $action['changed_fields'] ?? [] ) as $field ) {
                $partial[ $field ] = $payload[ $field ] ?? '';
            }
            $result = $this->assignments->upsert_assignment( $partial );
            if ( empty( $result['ok'] ) ) {
                return [ 'error' => (string) ( $result['error'] ?? 'update_failed' ) ];
            }
            return [ 'outcome' => 'updated', 'id' => (int) $result['id'] ];
        }
        return [ 'error' => 'unsupported_action_' . $kind ];
    }

    private function non_writable_bucket( string $classification ): string {
        if ( KeywordAssignmentMigrationAnalyzer::C_CONFLICT === $classification ) { return 'conflicting'; }
        return 'skipped';
    }

    // ── Rollback (by migration source type + version) ─────────────────────

    /**
     * Roll back migration-owned assignment rows. Dry run by default.
     *
     * @param bool $execute true = delete; false = report only
     * @param array<int,string> $source_types subset filter; defaults to all migration source types
     * @return array<string,mixed>
     */
    public function rollback( bool $execute = false, array $source_types = [] ): array {
        $requested = [] === $source_types
            ? KeywordAssignmentMigrationAnalyzer::MIGRATION_SOURCE_TYPES
            : array_values( array_intersect( $source_types, KeywordAssignmentMigrationAnalyzer::MIGRATION_SOURCE_TYPES ) );
        if ( [] === $requested ) {
            // A non-empty request that matches no migration source type must
            // fail loudly, never fall back to all types or report emptiness.
            $this->log( 'rollback refused: no valid migration source types in ' . implode( ',', $source_types ) );
            return [
                'migration_version' => KeywordAssignmentMigrationAnalyzer::MIGRATION_VERSION,
                'generated_at'      => $this->now(),
                'mode'              => $execute ? 'rollback-execute' : 'rollback-dry-run',
                'source_types'      => [],
                'would_delete'      => [],
                'deleted'           => 0,
                'preserved_manual'  => 0,
                'failed'            => 0,
                'errors'            => [ [ 'error' => 'invalid_source_types', 'requested' => array_values( $source_types ) ] ],
            ];
        }

        $rows = $this->assignments->find_assignments_by_source( $requested, KeywordAssignmentMigrationAnalyzer::MIGRATION_VERSION );
        usort( $rows, fn ( $a, $b ) => (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );

        $report = [
            'migration_version' => KeywordAssignmentMigrationAnalyzer::MIGRATION_VERSION,
            'generated_at'      => $this->now(),
            'mode'              => $execute ? 'rollback-execute' : 'rollback-dry-run',
            'source_types'      => $requested,
            'would_delete'      => [],
            'deleted'           => 0,
            'preserved_manual'  => 0,
            'failed'            => 0,
            'errors'            => [],
        ];

        foreach ( $rows as $row ) {
            // Double guard: never delete anything not provably migration-owned.
            if ( ! $this->analyzer->is_migration_owned( $row )
                || KeywordAssignmentMigrationAnalyzer::MIGRATION_VERSION !== (string) ( $row['source_reference'] ?? '' ) ) {
                $report['preserved_manual']++;
                continue;
            }
            $summary = [
                'id'           => (int) $row['id'],
                'candidate_id' => (int) ( $row['keyword_candidate_id'] ?? 0 ),
                'target_type'  => (string) ( $row['target_type'] ?? '' ),
                'target_id'    => (int) ( $row['target_id'] ?? 0 ),
                'role'         => (string) ( $row['role'] ?? '' ),
                'source_type'  => (string) ( $row['source_type'] ?? '' ),
            ];
            $report['would_delete'][] = $summary;
            if ( ! $execute ) { continue; }
            if ( $this->assignments->delete_assignment( (int) $row['id'] ) ) {
                $report['deleted']++;
            } else {
                $report['failed']++;
                $report['errors'][] = [ 'id' => (int) $row['id'], 'error' => 'delete_failed' ];
            }
        }
        $this->log( sprintf( '%s rows=%d deleted=%d preserved=%d failed=%d', (string) $report['mode'], count( $report['would_delete'] ), (int) $report['deleted'], (int) $report['preserved_manual'], (int) $report['failed'] ) );
        return $report;
    }

    // ── Report model ──────────────────────────────────────────────────────

    /**
     * @param array<int,array<string,mixed>> $decisions
     * @param array<string,int> $classification_counts
     * @param array<string,mixed> $filters
     * @param array<int,array<string,mixed>> $errors
     * @return array<string,mixed>
     */
    private function build_report( string $mode, array $decisions, array $classification_counts, int $existing_encountered, array $filters, array $errors, array $duplicate_source_rows = [] ): array {
        $planned = [ 'insert' => 0, 'update' => 0, 'unchanged' => 0, 'preserve' => 0 ];
        $primary_proposals = 0;
        $secondary_proposals = 0;
        $target_relationships = 0;
        // Compact report-level buckets (references only, deterministic order —
        // decisions are already sorted by candidate_id + keyword).
        $buckets = [
            'skipped_records'      => [], // rejected_candidate_skipped etc.
            'stale_owner_findings' => [],
            'unused_owner_findings'=> [],
            'ownership_conflicts'  => [],
            'unresolved_targets'   => [],
            'manual_review_records'=> [],
            'rows_to_insert'       => [],
            'rows_to_update'       => [],
            'rows_unchanged'       => [],
            'rows_preserved'       => [],
        ];
        $classification_bucket_map = [
            KeywordAssignmentMigrationAnalyzer::C_REJECTED_SKIP => 'skipped_records',
            KeywordAssignmentMigrationAnalyzer::C_STALE_OWNER   => 'stale_owner_findings',
            KeywordAssignmentMigrationAnalyzer::C_UNUSED_OWNER  => 'unused_owner_findings',
            KeywordAssignmentMigrationAnalyzer::C_CONFLICT      => 'ownership_conflicts',
            KeywordAssignmentMigrationAnalyzer::C_UNRESOLVED    => 'unresolved_targets',
            KeywordAssignmentMigrationAnalyzer::C_MANUAL_REVIEW => 'manual_review_records',
        ];
        $action_bucket_map = [ 'insert' => 'rows_to_insert', 'update' => 'rows_to_update', 'unchanged' => 'rows_unchanged', 'preserve' => 'rows_preserved' ];

        foreach ( $decisions as $decision ) {
            $target_relationships += count( (array) $decision['targets'] );
            $classification = (string) $decision['classification'];
            if ( isset( $classification_bucket_map[ $classification ] ) ) {
                $buckets[ $classification_bucket_map[ $classification ] ][] = [
                    'candidate_id'       => (int) $decision['candidate_id'],
                    'normalized_keyword' => (string) $decision['normalized_keyword'],
                    'classification'     => $classification,
                    'targets'            => array_map(
                        fn ( $target ) => [ 'target_type' => (string) $target['target_type'], 'target_id' => (int) $target['target_id'] ],
                        (array) $decision['targets']
                    ),
                    'reasons'            => (array) $decision['reasons'],
                ];
            }
            foreach ( (array) $decision['planned_actions'] as $action ) {
                $kind = (string) ( $action['action'] ?? '' );
                if ( isset( $planned[ $kind ] ) ) { $planned[ $kind ]++; }
                if ( isset( $action_bucket_map[ $kind ] ) ) {
                    $buckets[ $action_bucket_map[ $kind ] ][] = [
                        'candidate_id'       => (int) $decision['candidate_id'],
                        'normalized_keyword' => (string) $decision['normalized_keyword'],
                        'planned_action'     => $kind,
                        'role'               => (string) ( $action['payload']['role'] ?? '' ),
                        'status'             => (string) ( $action['payload']['status'] ?? '' ),
                        'target_type'        => (string) ( $action['payload']['target_type'] ?? '' ),
                        'target_id'          => (int) ( $action['payload']['target_id'] ?? 0 ),
                        'reasons'            => (array) ( $action['reasons'] ?? [] ),
                    ];
                }
                $role = (string) ( $action['payload']['role'] ?? '' );
                if ( in_array( $kind, [ 'insert', 'update' ], true ) ) {
                    if ( 'primary' === $role ) { $primary_proposals++; }
                    if ( 'secondary' === $role ) { $secondary_proposals++; }
                }
            }
        }
        $summary = $this->evidence->summary();
        ksort( $filters );

        return [
            'migration_version'      => KeywordAssignmentMigrationAnalyzer::MIGRATION_VERSION,
            'generated_at'           => $this->now(),
            'mode'                   => $mode,
            'filters'                => $filters,
            'source_counts'          => [
                'candidate_identities'         => (int) ( $summary['total_candidate_identities'] ?? 0 ),
                'approved_candidates'          => (int) ( $summary['approved_candidates'] ?? 0 ),
                'duplicate_rows_same_batch'    => (int) ( $summary['duplicate_import_rows_same_batch'] ?? 0 ),
                'duplicate_rows_cross_batch'   => (int) ( $summary['duplicate_import_rows_cross_batch'] ?? 0 ),
                'existing_assignments'         => $existing_encountered,
            ],
            'normalized_keyword_count'   => count( $decisions ),
            'target_relationship_count'  => $target_relationships,
            'classification_counts'      => $classification_counts,
            'planned'                    => $planned,
            'proposed_primary_assignments'   => $primary_proposals,
            'proposed_secondary_assignments' => $secondary_proposals,
            'rollback_source_tags'       => KeywordAssignmentMigrationAnalyzer::MIGRATION_SOURCE_TYPES,
            'duplicate_source_rows'      => $duplicate_source_rows,
            'skipped_records'            => $buckets['skipped_records'],
            'stale_owner_findings'       => $buckets['stale_owner_findings'],
            'unused_owner_findings'      => $buckets['unused_owner_findings'],
            'ownership_conflicts'        => $buckets['ownership_conflicts'],
            'unresolved_targets'         => $buckets['unresolved_targets'],
            'manual_review_records'      => $buckets['manual_review_records'],
            'rows_to_insert'             => $buckets['rows_to_insert'],
            'rows_to_update'             => $buckets['rows_to_update'],
            'rows_unchanged'             => $buckets['rows_unchanged'],
            'rows_preserved'             => $buckets['rows_preserved'],
            'decisions'                  => $decisions,
            'execution'                  => null,
            'execution_errors'           => $errors,
        ];
    }

    /** Canonical JSON serialization (stable structure; timestamps only in meta). */
    public function serialize_report( array $report ): string {
        $this->serialization_error = '';
        $encoded = function_exists( 'wp_json_encode' )
            ? wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
            : json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $encoded ) ) {
            $this->serialization_error = function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'unknown JSON encoding error';
            $this->log( 'report serialization failed: ' . $this->serialization_error );
            return '{}';
        }
        return $encoded;
    }

    public function serialization_error(): string {
        return $this->serialization_error;
    }

    private function now(): string {
        return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
    }

    private function log( string $message ): void {
        error_log( self::LOG_TAG . ' ' . $message );
    }
}
