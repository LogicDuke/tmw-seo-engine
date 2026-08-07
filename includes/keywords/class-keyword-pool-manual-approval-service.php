<?php
/**
 * Atomic manual approval of an existing category keyword as a per-target
 * assignment without rewriting the globally unique candidate's legacy owner.
 *
 * One candidate may therefore have one primary owner and multiple approved
 * secondary owners. Manual import-row approval in this service always creates
 * or reactivates a SECONDARY assignment; it never steals canonical ownership.
 *
 * @package TMWSEO\Engine\Keywords
 * @since   5.9.30-manual-approval-multi-owner-v1.0.0
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

if (!defined('ABSPATH')) { exit; }

class KeywordPoolManualApprovalService {

    public const LOG_TAG = '[TMW-KW-MANUAL-APPROVE]';

    private KeywordPoolCandidateRepository $candidates;
    private KeywordAssignmentRepository $assignments;
    private KeywordPoolImportBatchRepository $imports;

    public function __construct(
        ?KeywordPoolCandidateRepository $candidates = null,
        ?KeywordAssignmentRepository $assignments = null,
        ?KeywordPoolImportBatchRepository $imports = null
    ) {
        $this->candidates = $candidates ?: new KeywordPoolCandidateRepository();
        $this->assignments = $assignments ?: new KeywordAssignmentRepository();
        $this->imports = $imports ?: new KeywordPoolImportBatchRepository();
    }

    /**
     * Handle an existing category candidate through the assignment layer.
     * Candidate-absent and non-category paths return handled=false so the
     * unchanged legacy creation path can continue.
     *
     * @param array<string,mixed> $import_row
     * @param array<string,mixed> $batch
     * @return array<string,mixed>
     */
    public function approve_existing_category_candidate(array $import_row, array $batch): array {
        global $wpdb;

        if ('category' !== (string) ($batch['pool'] ?? '')) {
            return $this->result(false, false, 'not_category_approval');
        }

        $row_id = (int) ($import_row['id'] ?? 0);
        $batch_id = (int) ($import_row['batch_id'] ?? $batch['id'] ?? 0);
        $target_id = (int) ($batch['target_id'] ?? $import_row['target_id'] ?? 0);
        $keyword = $this->keyword_from_import_row($import_row);
        if ($row_id <= 0 || $batch_id <= 0 || $target_id <= 0 || '' === $keyword) {
            return $this->result(true, false, 'invalid_manual_approval_context');
        }

        $existing = $this->candidates->find_existing_by_keyword_global($keyword, false);
        if (!is_array($existing)) {
            if ('' !== (string) ($wpdb->last_error ?? '')) {
                return $this->result(true, false, 'candidate_lookup_failed');
            }
            return $this->result(false, false, 'candidate_not_found');
        }
        $candidate_id = (int) ($existing['id'] ?? 0);
        if ($candidate_id <= 0) {
            return $this->result(true, false, 'existing_candidate_missing_id');
        }
        if (!$this->assignments->table_exists() || !$this->imports->tables_exist()) {
            return $this->result(true, false, 'manual_approval_assignment_schema_unavailable', $candidate_id);
        }

        $wpdb->last_error = '';
        if (false === $wpdb->query('START TRANSACTION') || '' !== (string) $wpdb->last_error) {
            return $this->result(true, false, 'transaction_start_failed', $candidate_id);
        }

        try {
            $current_row = $this->imports->get_row_for_update($row_id);
            if (!is_array($current_row) || '' !== (string) ($wpdb->last_error ?? '')) {
                return $this->rollback_result('import_row_lock_failed', $candidate_id);
            }
            if ((int) ($current_row['batch_id'] ?? 0) !== $batch_id) {
                return $this->rollback_result('import_row_batch_changed', $candidate_id);
            }
            if (in_array((string) ($current_row['status'] ?? ''), [ 'rejected', 'blocked' ], true)
                || in_array((string) ($current_row['result_action'] ?? ''), [ 'rejected', 'manual_approval_blocked' ], true)
            ) {
                return $this->rollback_result('approval_state_changed', $candidate_id);
            }

            $locked_candidate = $this->candidates->find_existing_by_keyword_global($keyword, true);
            if (!is_array($locked_candidate) || '' !== (string) ($wpdb->last_error ?? '')) {
                return $this->rollback_result('candidate_lock_failed', $candidate_id);
            }
            if ((int) ($locked_candidate['id'] ?? 0) !== $candidate_id) {
                return $this->rollback_result('candidate_identity_changed', $candidate_id);
            }
            if (!$this->assignments->lock_assignments_for_candidate($candidate_id)) {
                return $this->rollback_result('assignment_lock_failed', $candidate_id);
            }

            $identity = $this->category_assignment_identity($candidate_id, $target_id);
            $existing_assignment = $this->assignments->find_assignment($candidate_id, $identity);
            if ('' !== (string) ($wpdb->last_error ?? '')) {
                return $this->rollback_result('assignment_lookup_failed', $candidate_id);
            }

            $payload = array_merge($identity, [
                'target_name'              => (string) ($batch['target_name'] ?? $import_row['target_name'] ?? ''),
                'target_slug'              => (string) ($batch['target_slug'] ?? ''),
                'role'                     => 'secondary',
                'status'                   => 'approved',
                'canonical_owner'          => 0,
                'shared_secondary_allowed' => 1,
                'conflict_reason'          => '',
                'approval_reason'          => 'manual_import_row_approved',
                'source_batch_id'          => $batch_id,
                'source_import_row_id'     => $row_id,
                'source_type'              => 'manual_import_approval',
                'source_reference'         => 'manual-approval:row:' . $row_id,
                'active_in_rank_math'      => 0,
                'present_in_content'       => 0,
            ]);

            $reason = 'secondary_assignment_created';
            $assignment_id = 0;
            $assignment_write_required = true;
            if (is_array($existing_assignment)) {
                $role = (string) ($existing_assignment['role'] ?? '');
                $status = (string) ($existing_assignment['status'] ?? '');
                $canonical = (int) ($existing_assignment['canonical_owner'] ?? 0);
                if ('primary' === $role && 1 !== $canonical) {
                    return $this->rollback_result('same_target_primary_not_canonical', $candidate_id);
                }
                if (!in_array($role, [ 'primary', 'secondary' ], true)) {
                    return $this->rollback_result('same_target_assignment_role_conflict', $candidate_id);
                }
                $reason = ('approved' === $status)
                    ? ($role . '_assignment_already_exists')
                    : ($role . '_assignment_reactivated');
                $assignment_id = (int) ($existing_assignment['id'] ?? 0);

                // An already-approved canonical primary is an exact no-op.
                // Never rewrite its source metadata or secondary-sharing flag.
                if ('primary' === $role && 'approved' === $status && 1 === $canonical) {
                    $assignment_write_required = false;
                } elseif ('primary' === $role) {
                    // Status reactivation may update the primary row, but it
                    // must not acquire a secondary-sharing flag.
                    unset($payload['shared_secondary_allowed']);
                } elseif ('approved' === $status
                    && 1 === (int) ($existing_assignment['shared_secondary_allowed'] ?? 0)
                ) {
                    $assignment_write_required = false;
                }
            }

            if ($assignment_write_required) {
                $assignment_result = $this->assignments->upsert_assignment($payload);
                if (empty($assignment_result['ok'])) {
                    return $this->rollback_result(
                        'assignment_write_failed:' . (string) ($assignment_result['error'] ?? 'unknown'),
                        $candidate_id
                    );
                }
                $assignment_id = (int) ($assignment_result['id'] ?? 0);
            }
            if ($assignment_id <= 0) {
                return $this->rollback_result('assignment_write_missing_id', $candidate_id);
            }

            if ('approved' !== (string) ($locked_candidate['status'] ?? '')) {
                if (!$this->imports->update_candidate_status($candidate_id, 'approved')) {
                    return $this->rollback_result('candidate_status_update_failed', $candidate_id, $assignment_id);
                }
            }

            $now = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
            if (!$this->imports->update_import_row($row_id, [
                'status'        => 'approved',
                'result_action' => 'approved',
                'result_reason' => $reason,
                'candidate_id'  => $candidate_id,
                'reviewed_by'   => function_exists('get_current_user_id') ? get_current_user_id() : 0,
                'reviewed_at'   => $now,
            ])) {
                return $this->rollback_result('import_row_update_failed', $candidate_id, $assignment_id);
            }

            $fresh_assignment = $this->assignments->find_assignment($candidate_id, $identity);
            if (!$this->approved_target_assignment_is_valid($fresh_assignment, $target_id)) {
                return $this->rollback_result('assignment_verification_failed', $candidate_id, $assignment_id);
            }
            $fresh_row = $this->imports->get_row($row_id);
            if (!is_array($fresh_row)
                || 'approved' !== (string) ($fresh_row['status'] ?? '')
                || 'approved' !== (string) ($fresh_row['result_action'] ?? '')
                || $candidate_id !== (int) ($fresh_row['candidate_id'] ?? 0)
            ) {
                return $this->rollback_result('import_row_verification_failed', $candidate_id, $assignment_id);
            }

            $wpdb->last_error = '';
            if (false === $wpdb->query('COMMIT') || '' !== (string) $wpdb->last_error) {
                $wpdb->query('ROLLBACK');
                return $this->result(true, false, 'commit_failed', $candidate_id, $assignment_id);
            }

            $this->log(sprintf(
                'approved row=%d candidate=%d assignment=%d target=%d result=%s',
                $row_id,
                $candidate_id,
                $assignment_id,
                $target_id,
                $reason
            ));
            return $this->result(true, true, $reason, $candidate_id, $assignment_id, true);
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            $this->log(sprintf('exception row=%d candidate=%d', $row_id, $candidate_id));
            return $this->result(true, false, 'manual_approval_exception', $candidate_id);
        }
    }

    /** @return array<string,mixed> */
    private function category_assignment_identity(int $candidate_id, int $target_id): array {
        return [
            'keyword_candidate_id' => $candidate_id,
            'pool'                 => 'category',
            'page_type'            => 'tmw_category_page',
            'target_type'          => 'tmw_category_page',
            'target_id'            => $target_id,
            'target_key'           => 'tmw_category_page:' . $target_id,
        ];
    }

    /** @param array<string,mixed>|null $row */
    private function approved_target_assignment_is_valid(?array $row, int $target_id): bool {
        if (!is_array($row)) { return false; }
        $role = (string) ($row['role'] ?? '');
        if (!in_array($role, [ 'primary', 'secondary' ], true)) { return false; }
        if ('approved' !== (string) ($row['status'] ?? '')) { return false; }
        if ('tmw_category_page' !== (string) ($row['target_type'] ?? '')) { return false; }
        if ($target_id !== (int) ($row['target_id'] ?? 0)) { return false; }
        if ('secondary' === $role) {
            return 0 === (int) ($row['canonical_owner'] ?? 0)
                && 1 === (int) ($row['shared_secondary_allowed'] ?? 0);
        }
        return 1 === (int) ($row['canonical_owner'] ?? 0);
    }

    private function keyword_from_import_row(array $row): string {
        $keyword = (string) ($row['normalized_keyword'] ?? $row['keyword'] ?? '');
        if ('' === trim($keyword)) {
            $payload = json_decode((string) ($row['row_payload'] ?? ''), true);
            if (is_array($payload)) {
                $keyword = (string) ($payload['normalized_keyword'] ?? $payload['keyword'] ?? '');
            }
        }
        return $this->candidates->normalize_keyword($keyword);
    }

    /** @return array<string,mixed> */
    private function rollback_result(string $reason, int $candidate_id = 0, int $assignment_id = 0): array {
        global $wpdb;
        $wpdb->query('ROLLBACK');
        $this->log(sprintf('rolled back candidate=%d assignment=%d reason=%s', $candidate_id, $assignment_id, $reason));
        return $this->result(true, false, $reason, $candidate_id, $assignment_id);
    }

    /** @return array<string,mixed> */
    private function result(
        bool $handled,
        bool $ok,
        string $reason,
        int $candidate_id = 0,
        int $assignment_id = 0,
        bool $row_persisted = false
    ): array {
        return [
            'handled'       => $handled,
            'ok'            => $ok,
            'candidate_id'  => $candidate_id,
            'assignment_id' => $assignment_id,
            'safe_reason'   => $reason,
            'row_persisted' => $row_persisted,
        ];
    }

    private function log(string $message): void {
        error_log(self::LOG_TAG . ' ' . $message);
    }
}
