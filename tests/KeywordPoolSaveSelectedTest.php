<?php
/**
 * Tests for save-selected keyword pool import.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordPoolCandidateRepository;
use TMWSEO\Engine\Keywords\KeywordPoolCsvParser;
use TMWSEO\Engine\Keywords\KeywordPoolDryRunService;
use TMWSEO\Engine\Keywords\KeywordPoolSelectedImportService;

require_once __DIR__ . '/../includes/keywords/class-keyword-pool-csv-parser.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-dry-run-service.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-candidate-repository.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-selected-import-service.php';

final class KeywordPoolSaveSelectedTest extends TestCase {
    private $original_wpdb;

    protected function setUp(): void {
        parent::setUp();
        $this->original_wpdb = $GLOBALS['wpdb'] ?? null;
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    public function test_save_selected_valid_category_keyword(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp590_cat_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume,cpc,competition,SEO Score,Traffic Value\nasian cam models,18100,5.99,0.02,91,108419\n", 'category');

        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'category', [2], 'auto');

        $this->assertSame(1, $result['summary']['inserted']);
        $this->assertSame('category', $wpdb->candidate_inserts[0]['data']['intent_type']);
        $this->assertSame('approved', $wpdb->candidate_inserts[0]['data']['status']);
        $this->assertSame('category', $wpdb->candidate_inserts[0]['data']['entity_type']);
        $this->assertStringContainsString('imported_from_keyword_pools', $wpdb->candidate_inserts[0]['data']['sources']);
    }

    public function test_save_selected_video_keyword_uses_video_intent(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp590_vid_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume,cpc,competition,model_name\nLexy Ness webcam video,1200,2.50,0.1,Lexy Ness\n", 'video');

        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'video', [2], 'queued_for_review');

        $this->assertSame(1, $result['summary']['inserted']);
        $this->assertSame('video', $wpdb->candidate_inserts[0]['data']['intent_type']);
        $this->assertSame('post', $wpdb->candidate_inserts[0]['data']['entity_type']);
        $this->assertSame('queued_for_review', $wpdb->candidate_inserts[0]['data']['status']);
    }

    public function test_rejects_video_standalone_model_keyword(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp590_rej_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,model_name\nLexy Ness,Lexy Ness\n", 'video');

        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'video', [2], 'approved');

        $this->assertSame(1, $result['summary']['blocked']);
        $this->assertSame([], $wpdb->candidate_inserts);
    }

    public function test_save_selected_model_keyword(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp590_mod_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume,cpc,competition,model_name\nLexy Ness webcam model,900,3.25,0.08,Lexy Ness\n", 'model');

        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'model', [2], 'auto');

        $this->assertSame(1, $result['summary']['inserted']);
        $this->assertSame('model', $wpdb->candidate_inserts[0]['data']['intent_type']);
        $this->assertSame('model', $wpdb->candidate_inserts[0]['data']['entity_type']);
    }

    public function test_blocked_archive_and_footer_rows_are_not_saved(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp590_blk_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume\nfree video chat,1000\nTotal Volume,704750\n", 'category');

        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'category', [2, 3], 'approved');

        $this->assertSame(2, $result['summary']['blocked']);
        $this->assertSame([], $wpdb->candidate_inserts);
    }

    public function test_duplicate_in_upload_is_handled_safely(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp590_dup_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume,cpc,competition\nasian cam models,18100,5.99,0.02\nasian cam models,18100,5.99,0.02\n", 'category');

        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'category', [2, 3], 'approved');

        $this->assertSame(1, $result['summary']['inserted']);
        $this->assertSame(1, $result['summary']['skipped'] + $result['summary']['blocked']);
        $this->assertCount(1, $wpdb->candidate_inserts);
    }

    public function test_existing_keyword_conflict_does_not_overwrite_other_scope(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp590_con_', true, null, [
            'id' => 44,
            'keyword' => 'asian cam models',
            'intent_type' => 'model',
            'entity_type' => 'model',
            'entity_id' => 12,
            'status' => 'approved',
        ]);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume,cpc,competition\nasian cam models,18100,5.99,0.02\n", 'category');

        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'category', [2], 'approved');

        $this->assertSame(1, $result['summary']['conflicts']);
        $this->assertSame([], $wpdb->candidate_inserts);
        $this->assertSame([], $wpdb->candidate_updates);
    }

    public function test_missing_optional_metric_columns_preserve_data_in_notes(): void {
        $columns = [ 'id', 'keyword', 'status', 'intent_type', 'entity_type', 'entity_id', 'sources', 'notes', 'updated_at' ];
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp590_met_', true, $columns);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume,cpc,competition,SEO Score,Traffic Value\nasian cam models,18100,5.99,0.02,91,108419\n", 'category');

        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'category', [2], 'approved');

        $this->assertSame(1, $result['summary']['inserted']);
        $this->assertStringContainsString('metric_column_unavailable_saved_to_notes', $result['rows'][0]['reason']);
        $this->assertStringContainsString('unsupported_metrics', $wpdb->candidate_inserts[0]['data']['notes']);
    }

    public function test_save_selected_does_not_write_rank_math_posts_or_generate(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp590_safe_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume,cpc,competition\nasian cam models,18100,5.99,0.02\n", 'category');

        (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'category', [2], 'approved');
        $queries = implode("\n", $wpdb->queries);

        $this->assertStringNotContainsString('postmeta', $queries);
        $this->assertStringNotContainsString('rank_math', $queries);
        $this->assertStringNotContainsString('post_content', $queries);
        $this->assertStringNotContainsString('generate', strtolower($queries));
    }

    private function dryRun(string $csv, string $pool): array {
        $parsed = (new KeywordPoolCsvParser())->parse_text($csv);
        return (new KeywordPoolDryRunService())->dry_run($parsed, $pool);
    }
}

final class KeywordPoolSaveSelectedFakeWpdb {
    public string $prefix;
    public int $insert_id = 1000;
    public array $queries = [];
    public array $candidate_inserts = [];
    public array $candidate_updates = [];
    private bool $table_exists;
    private array $columns;
    private ?array $existing_row;

    public function __construct(string $prefix, bool $table_exists, ?array $columns = null, ?array $existing_row = null) {
        $this->prefix = $prefix;
        $this->table_exists = $table_exists;
        $this->columns = $columns ?? [
            'id', 'keyword', 'canonical', 'status', 'intent', 'intent_type', 'entity_type', 'entity_id',
            'volume', 'cpc', 'difficulty', 'competition', 'opportunity', 'seo_score', 'traffic_value',
            'trend', 'ad_difficulty', 'difficulty_proxy', 'sources', 'notes', 'created_at', 'updated_at',
        ];
        $this->existing_row = $existing_row;
    }

    public function esc_like(string $text): string { return addcslashes($text, '_%\\'); }
    public function prepare(string $sql, ...$args): string {
        $i = 0;
        return preg_replace_callback('/%[sdf]/', function () use ($args, &$i) {
            $value = $args[$i++] ?? '';
            return is_string($value) ? "'" . addslashes($value) . "'" : (string) $value;
        }, $sql);
    }
    public function get_var(string $sql) {
        $this->queries[] = $sql;
        if (str_starts_with($sql, 'SHOW TABLES LIKE')) {
            return $this->table_exists ? $this->prefix . 'tmw_keyword_candidates' : null;
        }
        return null;
    }
    public function get_row(string $sql, string $output = 'OBJECT') {
        $this->queries[] = $sql;
        if (str_starts_with($sql, 'SELECT * FROM')) {
            return $this->existing_row;
        }
        return null;
    }
    public function get_results(string $sql, string $output = 'OBJECT'): array {
        $this->queries[] = $sql;
        if (str_starts_with($sql, 'SHOW COLUMNS FROM')) {
            return array_map(fn($field) => [ 'Field' => $field ], $this->columns);
        }
        return [];
    }
    public function insert(string $table, array $data, array $format = []) {
        $this->queries[] = 'INSERT:' . $table . ':' . json_encode($data);
        $this->candidate_inserts[] = [ 'table' => $table, 'data' => $data ];
        $this->insert_id++;
        return 1;
    }
    public function update(string $table, array $data, array $where, array $format = [], array $where_format = []) {
        $this->queries[] = 'UPDATE:' . $table . ':' . json_encode($data) . ':' . json_encode($where);
        $this->candidate_updates[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
        return 1;
    }
}
