<?php
/** Atomic manual approval into the target-specific assignment layer. */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class KeywordPoolManualApprovalService {
    /** @return array{ok:bool,candidate_id?:int,assignment_id?:int,safe_reason:string} */
    public function approve( array $row, array $batch, int $reviewed_by ): array {
        global $wpdb;
        $imports    = new KeywordPoolImportBatchRepository();
        $assignments = new KeywordAssignmentRepository();
        $row_id      = (int) ( $row['id'] ?? 0 );
        $candidate_id = (int) ( $row['candidate_id'] ?? 0 );
        $target_id   = (int) ( $row['target_id'] ?? $batch['target_id'] ?? 0 );
        $target_type = (string) ( $row['target_type'] ?? $batch['target_type'] ?? '' );
        if ( $row_id <= 0 || $target_id <= 0 || ! in_array( $target_type, [ 'category_page', 'tmw_category_page' ], true ) ) {
            return $this->result( false, 'invalid_assignment_target' );
        }

        $candidate_table = $wpdb->prefix . 'tmw_keyword_candidates';
        foreach ( [ $candidate_table, $assignments->table(), $imports->batches_table(), $imports->rows_table() ] as $table ) {
            if ( ! $this->is_transactional_table( $table ) ) {
                return $this->result( false, 'non_transactional_table' );
            }
        }

        $wpdb->last_error = '';
        if ( false === $wpdb->query( 'START TRANSACTION' ) || '' !== (string) $wpdb->last_error ) {
            return $this->result( false, 'transaction_start_failed' );
        }

        $candidate = $this->find_candidate( $candidate_table, $candidate_id, (string) ( $row['normalized_keyword'] ?? $row['keyword'] ?? '' ) );
        if ( null === $candidate ) {
            if ( '' !== (string) $wpdb->last_error ) {
                $wpdb->query( 'ROLLBACK' );
                return $this->result( false, 'candidate_lookup_failed' );
            }
            if ( $candidate_id > 0 ) {
                $wpdb->query( 'ROLLBACK' );
                return $this->result( false, 'candidate_not_found' );
            }

            // Candidate-less import rows must be materialized on the same connection and
            // inside this transaction so a later assignment/import-row failure rolls the
            // candidate write back with the rest of the approval.
            $candidate_result = ( new KeywordPoolSelectedImportService() )->approve_import_row_as_candidate_result( $row, $batch );
            $candidate_id = ! empty( $candidate_result['ok'] ) ? (int) ( $candidate_result['candidate_id'] ?? 0 ) : 0;
            if ( $candidate_id <= 0 ) {
                $wpdb->query( 'ROLLBACK' );
                return $this->result( false, (string) ( $candidate_result['safe_reason'] ?? 'candidate_persistence_failed' ) );
            }
            $candidate = $this->find_candidate( $candidate_table, $candidate_id, '' );
            if ( null === $candidate ) {
                $reason = '' !== (string) $wpdb->last_error ? 'candidate_lookup_failed' : 'candidate_not_found';
                $wpdb->query( 'ROLLBACK' );
                return $this->result( false, $reason );
            }
        }
        $candidate_id = (int) ( $candidate['id'] ?? 0 );
        $identity = [
            'pool'        => 'category',
            'page_type'   => 'tmw_category_page',
            'target_type' => 'tmw_category_page',
            'target_id'   => $target_id,
            'target_key'  => 'tmw_category_page:' . $target_id,
        ];
        $existing = $assignments->find_assignment( $candidate_id, $identity );
        $primary = $assignments->find_primary_owner( $candidate_id );
        $has_other_primary_evidence = false;
        foreach ( $assignments->find_assignments_for_candidate( $candidate_id ) as $candidate_assignment ) {
            if ( 'primary' === (string) ( $candidate_assignment['role'] ?? '' ) ) {
                $has_other_primary_evidence = true;
                break;
            }
        }
        if ( is_array( $existing ) && ( 'secondary' !== (string) ( $existing['role'] ?? '' ) || 0 !== (int) ( $existing['canonical_owner'] ?? 0 ) ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->result( false, 'existing_assignment_ownership_ambiguous' );
        }
        if ( is_array( $existing ) ) {
            $assignment_role = 'secondary';
        } elseif ( is_array( $primary ) ) {
            if ( 'approved' !== (string) ( $primary['status'] ?? '' ) ) {
                $wpdb->query( 'ROLLBACK' );
                return $this->result( false, 'canonical_primary_not_approved' );
            }
            $assignment_role = 'secondary';
        } elseif ( $has_other_primary_evidence ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->result( false, 'canonical_primary_not_approved' );
        } elseif ( ! $this->legacy_target_matches( $candidate, $target_type, $target_id ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->result( false, 'role_inference_ambiguous_no_primary_evidence' );
        } else {
            $assignment_role = 'primary';
        }

        if ( ! isset( $assignment ) ) {
            $payload = array_merge( $identity, [
                'keyword_candidate_id'     => $candidate_id,
                'target_name'              => (string) ( $row['target_name'] ?? $batch['target_name'] ?? '' ),
                'target_slug'              => (string) ( $batch['target_slug'] ?? '' ),
                'role'                     => $assignment_role,
                'status'                   => 'approved',
                'canonical_owner'          => 'primary' === $assignment_role ? 1 : 0,
                'shared_secondary_allowed' => 'secondary' === $assignment_role ? 1 : 0,
                'active_in_rank_math'      => 0,
                'approval_reason'          => 'manual_approval',
                'source_batch_id'          => (int) ( $row['batch_id'] ?? $batch['id'] ?? 0 ),
                'source_import_row_id'     => $row_id,
                'source_type'              => 'manual_approval',
            ] );
            $assignment = 'primary' === $assignment_role
                ? $assignments->create_primary_assignment_in_transaction( $payload )
                : $assignments->upsert_assignment( $payload );
        }
        if ( empty( $assignment['ok'] ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->result( false, 'assignment_write_failed' );
        }

        if ( 'approved' !== (string) ( $candidate['status'] ?? '' ) ) {
            $updated = $wpdb->update( $candidate_table, [ 'status' => 'approved', 'updated_at' => $this->now() ], [ 'id' => $candidate_id ] );
            if ( false === $updated ) {
                $wpdb->query( 'ROLLBACK' );
                return $this->result( false, 'candidate_status_write_failed' );
            }
        }
        if ( ! $imports->update_import_row( $row_id, [
            'status' => 'approved', 'result_action' => 'approved', 'result_reason' => 'manually_approved',
            'candidate_id' => $candidate_id, 'reviewed_by' => $reviewed_by, 'reviewed_at' => $this->now(),
        ] ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->result( false, 'import_row_write_failed' );
        }
        if ( false === $wpdb->query( 'COMMIT' ) || '' !== (string) $wpdb->last_error ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->result( false, 'transaction_commit_failed' );
        }
        return [
            'ok'            => true,
            'candidate_id'  => $candidate_id,
            'assignment_id' => (int) ( $assignment['id'] ?? 0 ),
            'safe_reason'   => 'primary' === $assignment_role ? 'manually_approved_primary' : 'manually_approved_secondary',
        ];
    }

    /** @param array<string,mixed> $candidate */
    private function legacy_target_matches( array $candidate, string $target_type, int $target_id ): bool {
        $legacy_id = (int) ( $candidate['target_id'] ?? 0 );
        if ( $legacy_id <= 0 ) { $legacy_id = (int) ( $candidate['entity_id'] ?? 0 ); }
        $legacy_type = (string) ( $candidate['target_type'] ?? '' );
        return $legacy_id === $target_id
            && in_array( $legacy_type, [ 'category_page', 'tmw_category_page' ], true )
            && in_array( $target_type, [ 'category_page', 'tmw_category_page' ], true );
    }

    /** @return array<string,mixed>|null */
    private function find_candidate( string $table, int $candidate_id, string $keyword ): ?array {
        global $wpdb;
        $wpdb->last_error = '';
        if ( $candidate_id > 0 ) {
            $wpdb->last_error = '';
            $found = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1 FOR UPDATE", $candidate_id ), ARRAY_A );
            return is_array( $found ) ? $found : null;
        }
        $wpdb->last_error = '';
        $keyword = ( new KeywordPoolCandidateRepository() )->normalize_keyword( $keyword );
        if ( '' === $keyword ) { return null; }
        $wpdb->last_error = '';
        $found = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE keyword = %s LIMIT 1 FOR UPDATE", $keyword ), ARRAY_A );
        return is_array( $found ) ? $found : null;
    }

    private function is_transactional_table( string $table ): bool {
        global $wpdb;
        $wpdb->last_error = '';
        $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
        return is_array( $status ) && 'innodb' === strtolower( (string) ( $status['Engine'] ?? '' ) ) && '' === (string) $wpdb->last_error;
    }

    private function now(): string { return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ); }
    /** @return array{ok:bool,safe_reason:string} */
    private function result( bool $ok, string $reason ): array { return [ 'ok' => $ok, 'safe_reason' => $reason ]; }
}
