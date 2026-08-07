<?php
/** Multi-owner manual approval regression tests. */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordAssignmentRepository;
use TMWSEO\Engine\Keywords\KeywordPoolCandidateRepository;
use TMWSEO\Engine\Keywords\KeywordPoolImportBatchRepository;
use TMWSEO\Engine\Keywords\KeywordPoolManualApprovalService;

require_once __DIR__ . '/../includes/keywords/class-keyword-pool-candidate-repository.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-assignment-repository.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-import-batch-repository.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-manual-approval-service.php';

final class KeywordPoolManualApprovalServiceTest extends TestCase {
    private KeywordPoolManualApprovalCandidateDouble $candidates;
    private KeywordPoolManualApprovalAssignmentDouble $assignments;
    private KeywordPoolManualApprovalImportDouble $imports;
    private KeywordPoolManualApprovalWpdb $wpdb;
    private KeywordPoolManualApprovalService $service;

    protected function setUp(): void {
        $this->wpdb = new KeywordPoolManualApprovalWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        $this->candidates = new KeywordPoolManualApprovalCandidateDouble();
        $this->assignments = new KeywordPoolManualApprovalAssignmentDouble();
        $this->imports = new KeywordPoolManualApprovalImportDouble();
        $this->service = new KeywordPoolManualApprovalService($this->candidates, $this->assignments, $this->imports);
    }

    public function test_global_legacy_candidate_can_be_approved_for_first_category_owner_as_secondary(): void {
        $result = $this->service->approve_existing_category_candidate($this->row(77), $this->batch(501));

        $this->assertTrue((bool) $result['ok'], (string) $result['safe_reason']);
        $this->assertSame('secondary_assignment_created', $result['safe_reason']);
        $this->assertSame(534, $result['candidate_id']);
        $this->assertCount(1, $this->assignments->rows);
        $assignment = array_values($this->assignments->rows)[0];
        $this->assertSame('secondary', $assignment['role']);
        $this->assertSame(0, $assignment['canonical_owner']);
        $this->assertSame(1, $assignment['shared_secondary_allowed']);
        $this->assertSame(501, $assignment['target_id']);
        $this->assertSame('global', $this->candidates->row['target_type'], 'legacy candidate ownership is not rewritten');
        $this->assertSame('approved', $this->imports->rows[77]['status']);
        $this->assertSame(['START TRANSACTION', 'COMMIT'], $this->wpdb->transaction_commands());
    }

    public function test_same_candidate_can_be_approved_for_several_category_owners(): void {
        $first = $this->service->approve_existing_category_candidate($this->row(77), $this->batch(501));
        $this->imports->rows[78] = $this->row(78);
        $second = $this->service->approve_existing_category_candidate($this->row(78), $this->batch(502));

        $this->assertTrue((bool) $first['ok']);
        $this->assertTrue((bool) $second['ok']);
        $this->assertCount(2, $this->assignments->rows);
        $targetIds = array_map(static fn(array $row): int => (int) $row['target_id'], array_values($this->assignments->rows));
        sort($targetIds);
        $this->assertSame([501, 502], $targetIds);
    }

    public function test_repeated_approval_for_same_owner_is_idempotent(): void {
        $first = $this->service->approve_existing_category_candidate($this->row(77), $this->batch(501));
        $this->imports->rows[78] = $this->row(78);
        $second = $this->service->approve_existing_category_candidate($this->row(78), $this->batch(501));

        $this->assertTrue((bool) $first['ok']);
        $this->assertTrue((bool) $second['ok']);
        $this->assertSame('secondary_assignment_already_exists', $second['safe_reason']);
        $this->assertCount(1, $this->assignments->rows);
    }

    public function test_existing_primary_is_preserved_while_another_owner_gets_secondary(): void {
        $this->assignments->seedPrimary(534, 400);
        $before = $this->assignments->rows;
        $result = $this->service->approve_existing_category_candidate($this->row(77), $this->batch(501));

        $this->assertTrue((bool) $result['ok']);
        $this->assertSame($before['tmw_category_page:400'], $this->assignments->rows['tmw_category_page:400']);
        $this->assertSame('secondary', $this->assignments->rows['tmw_category_page:501']['role']);
    }

    public function test_same_target_approved_primary_is_idempotent_and_byte_identical(): void {
        $this->assignments->seedPrimary(534, 501);
        $before = $this->assignments->rows['tmw_category_page:501'];
        $result = $this->service->approve_existing_category_candidate($this->row(77), $this->batch(501));

        $this->assertTrue((bool) $result['ok']);
        $this->assertSame('primary_assignment_already_exists', $result['safe_reason']);
        $this->assertSame($before, $this->assignments->rows['tmw_category_page:501']);
    }

    public function test_assignment_failure_rolls_back_and_does_not_approve_import_row(): void {
        $this->assignments->fail_write = true;
        $result = $this->service->approve_existing_category_candidate($this->row(77), $this->batch(501));

        $this->assertFalse((bool) $result['ok']);
        $this->assertStringStartsWith('assignment_write_failed:', (string) $result['safe_reason']);
        $this->assertNotSame('approved', $this->imports->rows[77]['status']);
        $this->assertContains('ROLLBACK', $this->wpdb->transaction_commands());
    }

    public function test_non_category_and_candidate_absent_paths_are_not_intercepted(): void {
        $notCategory = $this->service->approve_existing_category_candidate($this->row(77), array_merge($this->batch(501), ['pool' => 'model']));
        $this->candidates->row = null;
        $absent = $this->service->approve_existing_category_candidate($this->row(77), $this->batch(501));

        $this->assertFalse((bool) $notCategory['handled']);
        $this->assertFalse((bool) $absent['handled']);
        $this->assertSame([], $this->wpdb->transaction_commands());
    }

    private function row(int $id): array {
        $row = [
            'id' => $id,
            'batch_id' => 9,
            'keyword' => 'adult video chat',
            'normalized_keyword' => 'adult video chat',
            'status' => 'review_required',
            'result_action' => 'blocked',
            'result_reason' => 'blocked_validation_state_review_required',
            'candidate_id' => null,
        ];
        $this->imports->rows[$id] = $row;
        return $row;
    }

    private function batch(int $targetId): array {
        return [
            'id' => 9,
            'pool' => 'category',
            'target_type' => 'category_page',
            'target_id' => $targetId,
            'target_name' => 'Target ' . $targetId,
            'target_slug' => 'target-' . $targetId,
        ];
    }
}

final class KeywordPoolManualApprovalCandidateDouble extends KeywordPoolCandidateRepository {
    public ?array $row = [
        'id' => 534,
        'keyword' => 'adult video chat',
        'status' => 'approved',
        'intent_type' => 'category',
        'entity_type' => 'category',
        'entity_id' => 0,
        'target_type' => 'global',
        'target_id' => null,
        'target_name' => 'Global Model Pool',
        'target_slug' => 'global-model-pool',
    ];
    public function table_exists(): bool { return true; }
    public function find_existing_by_keyword_global(string $keyword, bool $for_update = false): ?array { return $this->row; }
}

final class KeywordPoolManualApprovalAssignmentDouble extends KeywordAssignmentRepository {
    public array $rows = [];
    public bool $fail_write = false;
    public function table_exists(): bool { return true; }
    public function lock_assignments_for_candidate(int $candidate_id): bool { return true; }
    public function find_assignment(int $candidate_id, array $identity): ?array { return $this->rows[(string) $identity['target_key']] ?? null; }
    public function upsert_assignment(array $data): array {
        if ($this->fail_write) { return ['ok' => false, 'error' => 'injected_failure']; }
        $key = (string) $data['target_key'];
        if (isset($this->rows[$key])) {
            foreach (['target_name','target_slug','status','shared_secondary_allowed','approval_reason','source_batch_id','source_import_row_id','source_type','source_reference','active_in_rank_math','present_in_content'] as $field) {
                if (array_key_exists($field, $data)) { $this->rows[$key][$field] = $data[$field]; }
            }
            return ['ok' => true, 'id' => (int) $this->rows[$key]['id'], 'action' => 'updated'];
        }
        $data['id'] = count($this->rows) + 1;
        $this->rows[$key] = $data;
        return ['ok' => true, 'id' => (int) $data['id'], 'action' => 'created'];
    }
    public function seedPrimary(int $candidateId, int $targetId): void {
        $this->rows['tmw_category_page:' . $targetId] = [
            'id' => 90,
            'keyword_candidate_id' => $candidateId,
            'pool' => 'category',
            'page_type' => 'tmw_category_page',
            'target_type' => 'tmw_category_page',
            'target_id' => $targetId,
            'target_key' => 'tmw_category_page:' . $targetId,
            'role' => 'primary',
            'status' => 'approved',
            'canonical_owner' => 1,
            'shared_secondary_allowed' => 0,
        ];
    }
}

final class KeywordPoolManualApprovalImportDouble extends KeywordPoolImportBatchRepository {
    public array $rows = [];
    public bool $candidate_status_updated = false;
    public function tables_exist(): bool { return true; }
    public function get_row_for_update(int $row_id): ?array { return $this->rows[$row_id] ?? null; }
    public function get_row(int $row_id): ?array { return $this->rows[$row_id] ?? null; }
    public function update_candidate_status(int $candidate_id, string $status): bool { $this->candidate_status_updated = true; return true; }
    public function update_import_row(int $row_id, array $updates): bool {
        if (!isset($this->rows[$row_id])) { return false; }
        $this->rows[$row_id] = array_merge($this->rows[$row_id], $updates);
        return true;
    }
}

final class KeywordPoolManualApprovalWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public array $queries = [];
    public function query(string $sql) { $this->queries[] = trim($sql); return 1; }
    public function transaction_commands(): array {
        return array_values(array_filter($this->queries, static fn(string $sql): bool => in_array($sql, ['START TRANSACTION','COMMIT','ROLLBACK'], true)));
    }
}
