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
        if ( $row_id <= 0 || $target_id <= 0 || 'category_page' !== $target_type ) {
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
            $wpdb->query( 'ROLLBACK' );
            return $this->result( false, '' !== (string) $wpdb->last_error ? 'candidate_lookup_failed' : 'candidate_not_found' );
        }
        $candidate_id = (int) ( $candidate['id'] ?? 0 );
        $identity = [
            'pool'        => 'category',
            'page_type'   => 'tmw_category_page',
            'target_type' => 'category_page',
            'target_id'   => $target_id,
            'target_key'  => 'category_page:' . $target_id,
        ];
        $existing = $assignments->find_assignment( $candidate_id, $identity );
        if ( is_array( $existing ) && ( 'secondary' !== (string) ( $existing['role'] ?? '' ) || 0 !== (int) ( $existing['canonical_owner'] ?? 0 ) ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->result( false, 'existing_assignment_ownership_ambiguous' );
        }
        $primary = $assignments->find_primary_owner( $candidate_id );
        if ( is_array( $primary ) && 'approved' !== (string) ( $primary['status'] ?? '' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->result( false, 'canonical_primary_not_approved' );
        }

        $assignment = $assignments->upsert_assignment( array_merge( $identity, [
            'keyword_candidate_id'     => $candidate_id,
            'target_name'              => (string) ( $row['target_name'] ?? $batch['target_name'] ?? '' ),
            'target_slug'              => (string) ( $batch['target_slug'] ?? '' ),
            'role'                     => 'secondary',
            'status'                   => 'approved',
            'canonical_owner'          => 0,
            'shared_secondary_allowed' => 1,
            'active_in_rank_math'      => 0,
            'approval_reason'          => 'manual_approval',
            'source_batch_id'          => (int) ( $row['batch_id'] ?? $batch['id'] ?? 0 ),
            'source_import_row_id'     => $row_id,
            'source_type'              => 'manual_approval',
        ] ) );
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
        return [ 'ok' => true, 'candidate_id' => $candidate_id, 'assignment_id' => (int) ( $assignment['id'] ?? 0 ), 'safe_reason' => 'manually_approved_secondary' ];
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
        $keyword = strtolower( trim( (string) preg_replace( '/\s+/', ' ', $keyword ) ) );
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
