<?php
/**
 * Tests for repairing unlinked personal model keyword candidates.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\ModelKeywordEntityRepairService;
use TMWSEO\Engine\Models\ModelEntityResolver;

require_once __DIR__ . '/../includes/models/class-model-entity-resolver.php';
require_once __DIR__ . '/../includes/keywords/class-model-keyword-entity-repair-service.php';

final class ModelKeywordEntityRepairServiceTest extends TestCase {
    private $original_wpdb;

    protected function setUp(): void {
        parent::setUp();
        $this->original_wpdb = $GLOBALS['wpdb'] ?? null;
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    public function test_existing_approved_anisyia_row_resolves_to_model_post_id_4457(): void {
        $wpdb = new ModelKeywordEntityRepairFakeWpdb('wp601_repair_', [ $this->unlinkedRow(1, 'anisyia') ]);
        $GLOBALS['wpdb'] = $wpdb;

        $summary = $this->serviceWithPosts([ $this->modelPost(4457, 'Anisyia', 'anisyia') ])->resolve_selected([1]);

        $this->assertSame([ 'selected' => 1, 'linked' => 1, 'unresolved' => 0, 'ambiguous' => 0, 'skipped' => 0, 'errors' => 0 ], $summary);
        $this->assertSame(4457, $wpdb->updates[0]['data']['entity_id']);
        $this->assertSame('model', $wpdb->updates[0]['data']['entity_type']);
    }

    public function test_bulk_repair_preserves_keyword_status_metrics_strategy_scope_and_primary_flag(): void {
        $row = $this->unlinkedRow(2, 'anisyia livejasmin');
        $row['volume'] = 1900;
        $row['sources'] = json_encode([
            'personal_model_keyword_csv' => true,
            'model_keyword_owner' => 'anisyia',
            'model_keyword_usage_scope' => 'model_bio_only',
            'model_keyword_primary_candidate' => 'yes',
            'model_keyword_strategy' => 'lj_named_model_opportunity',
        ]);
        $wpdb = new ModelKeywordEntityRepairFakeWpdb('wp601_repair_preserve_', [ $row ]);
        $GLOBALS['wpdb'] = $wpdb;

        $this->serviceWithPosts([ $this->modelPost(4457, 'Anisyia', 'anisyia') ])->resolve_selected([2]);

        $this->assertSame('anisyia livejasmin', $wpdb->rows[2]['keyword']);
        $this->assertSame('approved', $wpdb->rows[2]['status']);
        $this->assertSame(1900, $wpdb->rows[2]['volume']);
        $sources = json_decode($wpdb->updates[0]['data']['sources'], true);
        $this->assertSame('model_bio_only', $sources['model_keyword_usage_scope']);
        $this->assertSame('yes', $sources['model_keyword_primary_candidate']);
        $this->assertSame('lj_named_model_opportunity', $sources['model_keyword_strategy']);
        $this->assertContains('repair_unlinked_model_keyword', $sources['model_entity_reason_codes']);
    }

    public function test_owner_not_found_remains_entity_zero_and_queues_approved_bio_row(): void {
        $wpdb = new ModelKeywordEntityRepairFakeWpdb('wp601_repair_missing_', [ $this->unlinkedRow(3, 'missing model') ]);
        $GLOBALS['wpdb'] = $wpdb;

        $summary = $this->serviceWithPosts([])->resolve_selected([3]);

        $this->assertSame(1, $summary['unresolved']);
        $this->assertSame(0, $wpdb->updates[0]['data']['entity_id']);
        $this->assertSame('queued_for_review', $wpdb->updates[0]['data']['status']);
        $this->assertStringContainsString('model_entity_not_found', $wpdb->updates[0]['data']['sources']);
    }

    public function test_ambiguous_owner_remains_entity_zero_and_queues_approved_bio_row(): void {
        $wpdb = new ModelKeywordEntityRepairFakeWpdb('wp601_repair_ambiguous_', [ $this->unlinkedRow(4, 'anisyia') ]);
        $GLOBALS['wpdb'] = $wpdb;

        $summary = $this->serviceWithPosts([ $this->modelPost(4457, 'Anisyia', 'anisyia'), $this->modelPost(4458, 'Anisyia', 'anisyia-2') ])->resolve_selected([4]);

        $this->assertSame(1, $summary['ambiguous']);
        $this->assertSame(0, $wpdb->updates[0]['data']['entity_id']);
        $this->assertSame('queued_for_review', $wpdb->updates[0]['data']['status']);
        $this->assertStringContainsString('model_entity_ambiguous', $wpdb->updates[0]['data']['sources']);
    }

    private function serviceWithPosts(array $posts): ModelKeywordEntityRepairService {
        return new ModelKeywordEntityRepairService(new ModelEntityResolver(static fn() => $posts));
    }

    private function modelPost(int $id, string $title, string $slug): object {
        return (object) [ 'ID' => $id, 'post_title' => $title, 'post_name' => $slug, 'post_type' => 'model' ];
    }

    private function unlinkedRow(int $id, string $keyword): array {
        return [
            'id' => $id,
            'keyword' => $keyword,
            'intent_type' => 'model',
            'entity_type' => 'model',
            'entity_id' => 0,
            'status' => 'approved',
            'volume' => 12100,
            'sources' => '{"personal_model_keyword_csv":true,"model_keyword_owner":"anisyia","model_keyword_usage_scope":"model_bio_only","model_keyword_primary_candidate":"yes"}',
            'notes' => '{"model_keyword_owner":"anisyia"}',
        ];
    }
}

final class ModelKeywordEntityRepairFakeWpdb {
    public string $prefix;
    public array $rows = [];
    public array $updates = [];
    public array $queries = [];
    private array $columns = [ 'id', 'keyword', 'intent_type', 'entity_type', 'entity_id', 'status', 'volume', 'sources', 'notes', 'updated_at' ];

    public function __construct(string $prefix, array $rows) {
        $this->prefix = $prefix;
        foreach ($rows as $row) { $this->rows[(int) $row['id']] = $row; }
    }

    public function prepare(string $sql, ...$args): string {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        $i = 0;
        return preg_replace_callback('/%[sdf]/', static function () use ($args, &$i) {
            $value = $args[$i++] ?? '';
            return is_string($value) ? "'" . addslashes($value) . "'" : (string) $value;
        }, $sql);
    }

    public function get_results(string $sql, string $output = 'OBJECT'): array {
        $this->queries[] = $sql;
        if (str_starts_with($sql, 'SHOW COLUMNS FROM')) { return array_map(static fn($field) => [ 'Field' => $field ], $this->columns); }
        if (str_starts_with($sql, 'SELECT * FROM')) { return array_values($this->rows); }
        return [];
    }

    public function update(string $table, array $data, array $where, array $format = [], array $where_format = []) {
        $id = (int) ($where['id'] ?? 0);
        $this->updates[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
        if (isset($this->rows[$id])) { $this->rows[$id] = array_merge($this->rows[$id], $data); }
        return 1;
    }
}
