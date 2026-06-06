<?php
/**
 * Tests for GlobalModelPoolRepairService.
 *
 * Verifies that:
 * 1. Rows identified by sources JSON get explicit global markers written.
 * 2. Rows already correctly marked are skipped.
 * 3. Rows with conflicting non-global target_type are skipped conservatively.
 * 4. Rows with no sources JSON evidence are skipped.
 * 5. Dry-run mode counts but does not write.
 * 6. Nested import history entries are recognised as global pool evidence.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\GlobalModelPoolRepairService;

require_once __DIR__ . '/../includes/keywords/class-global-model-pool-repair-service.php';

// ─── Minimal wpdb stub ────────────────────────────────────────────────────────
final class GlobalRepairFakeWpdb {
    public string $prefix;
    public array  $queries  = [];
    public array  $updates  = [];
    /** @var array<int,array<string,mixed>> */
    private array $rows;
    private array $columns;
    private bool  $table_exists;

    /** @param array<int,array<string,mixed>> $rows */
    public function __construct(
        string $prefix,
        array  $rows         = [],
        array  $columns      = [],
        bool   $table_exists = true
    ) {
        $this->prefix       = $prefix;
        $this->rows         = $rows;
        $this->table_exists = $table_exists;
        $this->columns = $columns ?: [
            'id', 'keyword', 'intent_type', 'entity_type', 'entity_id',
            'status', 'sources', 'target_type', 'target_name', 'target_slug',
            'updated_at',
        ];
    }

    public function esc_like(string $text): string { return addcslashes($text, '_%\\'); }

    public function prepare(string $sql, ...$args): string {
        $i = 0;
        return preg_replace_callback('/%[sdf]/', function () use ($args, &$i) {
            $v = $args[$i++] ?? '';
            return is_string($v) ? "'" . addslashes($v) . "'" : (string) $v;
        }, $sql) ?? $sql;
    }

    public function get_var(string $sql) {
        $this->queries[] = $sql;
        if (str_starts_with($sql, 'SHOW TABLES LIKE')) {
            return $this->table_exists ? $this->prefix . 'tmw_keyword_candidates' : null;
        }
        return null;
    }

    public function get_results(string $sql, string $output = 'OBJECT'): array {
        $this->queries[] = $sql;
        if (str_contains($sql, 'SHOW COLUMNS FROM')) {
            return array_map(static fn($f) => ['Field' => $f], $this->columns);
        }
        // Return batch rows for the main scan query.
        if (str_contains($sql, 'intent_type') && str_contains($sql, 'entity_id')) {
            return $this->rows;
        }
        return [];
    }

    public function update(string $table, array $data, array $where): int {
        $this->queries[] = 'UPDATE:' . $table;
        $this->updates[] = ['data' => $data, 'where' => $where];
        return 1;
    }
}

// ─── Factory helpers ──────────────────────────────────────────────────────────

function repair_row(
    int    $id,
    string $keyword,
    ?string $target_type  = null,
    ?string $target_name  = null,
    ?string $sources_json = null
): array {
    return [
        'id'          => $id,
        'keyword'     => $keyword,
        'intent_type' => 'model',
        'entity_id'   => 0,
        'target_type' => $target_type,
        'target_name' => $target_name,
        'sources'     => $sources_json,
    ];
}

function global_sources_json(bool $use_flag = false): string {
    if ($use_flag) {
        return json_encode(['global_model_pool' => true]);
    }
    return json_encode(['model_keyword_usage_scope' => 'global_model_pool']);
}

function nested_sources_json(): string {
    return json_encode([
        'pool' => 'model',
        'keyword_pools_import' => [
            'model_keyword_usage_scope' => 'global_model_pool',
            'global_model_pool'         => true,
        ],
    ]);
}

// ─── Tests ────────────────────────────────────────────────────────────────────

final class GlobalModelPoolRepairServiceTest extends TestCase {
    private $original_wpdb;

    protected function setUp(): void {
        parent::setUp();
        $this->original_wpdb = $GLOBALS['wpdb'] ?? null;
        // Ensure debug flag is unset to avoid error_log noise in CI.
        if (!defined('WP_DEBUG_LOG')) {
            define('WP_DEBUG_LOG', false);
        }
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    // ── Test 1: scope-column evidence triggers update ─────────────────────

    public function test_row_with_scope_evidence_gets_global_markers_written(): void {
        $row  = repair_row(1, 'live cam model', null, null, global_sources_json());
        $wpdb = new GlobalRepairFakeWpdb('wp685_', [$row]);
        $GLOBALS['wpdb'] = $wpdb;

        $stats = (new GlobalModelPoolRepairService())->scan_and_repair(false);

        $this->assertSame(1, $stats['scanned']);
        $this->assertSame(1, $stats['updated']);
        $this->assertSame(0, $stats['skipped']);
        $this->assertSame(0, $stats['errors']);

        $this->assertCount(1, $wpdb->updates);
        $update = $wpdb->updates[0];
        $this->assertSame('global', $update['data']['target_type']);
        $this->assertSame('Global Model Pool', $update['data']['target_name']);
        $this->assertSame('global-model-pool', $update['data']['target_slug']);
        $this->assertSame(['id' => 1], $update['where']);
    }

    // ── Test 2: global_model_pool=true flag evidence ──────────────────────

    public function test_row_with_global_flag_evidence_gets_global_markers_written(): void {
        $row  = repair_row(2, 'webcam girls', null, null, global_sources_json(true));
        $wpdb = new GlobalRepairFakeWpdb('wp685b_', [$row]);
        $GLOBALS['wpdb'] = $wpdb;

        $stats = (new GlobalModelPoolRepairService())->scan_and_repair(false);

        $this->assertSame(1, $stats['updated']);
        $this->assertSame('global', $wpdb->updates[0]['data']['target_type']);
    }

    // ── Test 3: already-marked rows are skipped ───────────────────────────

    public function test_already_marked_row_is_skipped(): void {
        $row  = repair_row(3, 'cam girl live', 'global', 'Global Model Pool', global_sources_json());
        $wpdb = new GlobalRepairFakeWpdb('wp685c_', [$row]);
        $GLOBALS['wpdb'] = $wpdb;

        $stats = (new GlobalModelPoolRepairService())->scan_and_repair(false);

        $this->assertSame(1, $stats['scanned']);
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertCount(0, $wpdb->updates);
    }

    // ── Test 4: conflicting non-global target_type is skipped conservatively

    public function test_row_with_conflicting_target_type_is_skipped(): void {
        $row  = repair_row(4, 'model keyword', 'model', null, global_sources_json());
        $wpdb = new GlobalRepairFakeWpdb('wp685d_', [$row]);
        $GLOBALS['wpdb'] = $wpdb;

        $stats = (new GlobalModelPoolRepairService())->scan_and_repair(false);

        $this->assertSame(1, $stats['scanned']);
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertCount(0, $wpdb->updates);
    }

    // ── Test 5: rows with no sources evidence are skipped ─────────────────

    public function test_row_with_no_global_evidence_in_sources_is_skipped(): void {
        $row  = repair_row(5, 'some keyword', null, null, json_encode(['model_keyword_usage_scope' => 'model_bio_only']));
        $wpdb = new GlobalRepairFakeWpdb('wp685e_', [$row]);
        $GLOBALS['wpdb'] = $wpdb;

        $stats = (new GlobalModelPoolRepairService())->scan_and_repair(false);

        $this->assertSame(1, $stats['scanned']);
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertCount(0, $wpdb->updates);
    }

    // ── Test 6: dry-run mode counts but does not write ────────────────────

    public function test_dry_run_counts_but_does_not_write(): void {
        $rows = [
            repair_row(6, 'live cam profile',  null, null, global_sources_json()),
            repair_row(7, 'private chat model', null, null, global_sources_json(true)),
        ];
        $wpdb = new GlobalRepairFakeWpdb('wp685f_', $rows);
        $GLOBALS['wpdb'] = $wpdb;

        $stats = (new GlobalModelPoolRepairService())->scan_and_repair(true);

        $this->assertTrue($stats['dry_run']);
        $this->assertSame(2, $stats['scanned']);
        $this->assertSame(2, $stats['updated'], 'Dry-run should still count would-be updates');
        $this->assertCount(0, $wpdb->updates, 'Dry-run must not write to the database');
    }

    // ── Test 7: nested import history evidence ────────────────────────────

    public function test_nested_import_history_evidence_is_recognised(): void {
        $row  = repair_row(8, 'cam model live', null, null, nested_sources_json());
        $wpdb = new GlobalRepairFakeWpdb('wp685g_', [$row]);
        $GLOBALS['wpdb'] = $wpdb;

        $stats = (new GlobalModelPoolRepairService())->scan_and_repair(false);

        $this->assertSame(1, $stats['updated']);
        $this->assertSame('Global Model Pool', $wpdb->updates[0]['data']['target_name']);
    }

    // ── Test 8: null sources row is skipped ───────────────────────────────

    public function test_row_with_null_sources_is_skipped(): void {
        $row  = repair_row(9, 'generic term', null, null, null);
        $wpdb = new GlobalRepairFakeWpdb('wp685h_', [$row]);
        $GLOBALS['wpdb'] = $wpdb;

        $stats = (new GlobalModelPoolRepairService())->scan_and_repair(false);

        $this->assertSame(1, $stats['scanned']);
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(1, $stats['skipped']);
    }

    // ── Test 9: table_not_exists returns empty stats ──────────────────────

    public function test_missing_table_returns_zero_stats(): void {
        $wpdb = new GlobalRepairFakeWpdb('wp685i_', [], [], false);
        $GLOBALS['wpdb'] = $wpdb;

        $stats = (new GlobalModelPoolRepairService())->scan_and_repair(false);

        $this->assertSame(0, $stats['scanned']);
        $this->assertSame(0, $stats['updated']);
        $this->assertCount(0, $wpdb->updates);
    }

    // ── Test 10: mixed batch — some updated, some skipped ─────────────────

    public function test_mixed_batch_updates_only_eligible_rows(): void {
        $rows = [
            repair_row(10, 'live cam profile',   null,     null,              global_sources_json()),
            repair_row(11, 'already marked',     'global', 'Global Model Pool', global_sources_json()),
            repair_row(12, 'model specific',     'model',  null,              global_sources_json()),
            repair_row(13, 'webcam private chat', null,    null,              global_sources_json(true)),
            repair_row(14, 'unrelated term',     null,     null,              json_encode(['pool' => 'model'])),
        ];
        $wpdb = new GlobalRepairFakeWpdb('wp685j_', $rows);
        $GLOBALS['wpdb'] = $wpdb;

        $stats = (new GlobalModelPoolRepairService())->scan_and_repair(false);

        $this->assertSame(5, $stats['scanned']);
        $this->assertSame(2, $stats['updated'],  'rows 10 and 13 should be updated');
        $this->assertSame(3, $stats['skipped'],  'rows 11, 12, and 14 should be skipped');
        $this->assertSame(0, $stats['errors']);
        $this->assertCount(2, $wpdb->updates);
    }
}
