<?php
/**
 * Tests for PR 603 keyword pool classification apply service.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordPoolCandidateRepository;
use TMWSEO\Engine\Keywords\KeywordPoolClassificationApplyService;
use TMWSEO\Engine\Keywords\ModelKeywordPoolClassifier;

if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

if (!class_exists('WP_Post')) {
    class WP_Post {
        public int $ID;
        public string $post_type;
        public string $post_title;
        public function __construct(int $id = 0, string $post_type = 'post', string $post_title = '') {
            $this->ID = $id;
            $this->post_type = $post_type;
            $this->post_title = $post_title;
        }
    }
}
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags(string $text): string { return strip_tags($text); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($data): string { return (string) json_encode($data); } }
if (!function_exists('current_time')) { function current_time(string $type): string { return '2026-05-30 00:00:00'; } }
if (!function_exists('get_post')) { function get_post(int $post_id) { return $GLOBALS['pr603_phpunit_posts'][$post_id] ?? null; } }

require_once __DIR__ . '/../includes/keywords/class-model-keyword-pool-classifier.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-candidate-repository.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-classification-apply-service.php';

final class Pr603PhpunitWpdb {
    public string $prefix = 'wp_';
    public array $rows = [];
    public array $updates = [];
    private array $columns = [ 'id', 'keyword', 'canonical', 'intent', 'intent_type', 'entity_type', 'entity_id', 'status', 'sources', 'created_at', 'updated_at' ];

    public function esc_like(string $text): string { return addcslashes($text, '_%\\'); }
    public function prepare(string $sql, ...$args): string {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        $i = 0;
        return preg_replace_callback('/%[sdf]/', static function () use ($args, &$i) {
            $value = $args[$i++] ?? '';
            return is_string($value) ? "'" . addslashes($value) . "'" : (string) $value;
        }, $sql);
    }
    public function get_var(string $sql) { return str_starts_with($sql, 'SHOW TABLES LIKE') ? $this->prefix . 'tmw_keyword_candidates' : null; }
    public function get_results(string $sql, string $output = 'OBJECT'): array {
        if (str_starts_with($sql, 'SHOW COLUMNS FROM')) { return array_map(static fn($field) => [ 'Field' => $field ], $this->columns); }
        if (str_contains($sql, 'FROM ' . $this->prefix . 'tmw_keyword_candidates') && str_contains($sql, "intent_type = 'model'")) {
            $rows = array_values(array_filter($this->rows, static fn($row): bool => ($row['intent_type'] ?? '') === 'model'));
            usort($rows, static fn($a, $b): int => ((int) $a['id']) <=> ((int) $b['id']));
            return $rows;
        }
        return [];
    }
    public function get_row(string $sql, string $output = 'OBJECT') {
        if (preg_match('/WHERE id = (\d+)/', $sql, $m)) { return $this->rows[(int) $m[1]] ?? null; }
        if (preg_match("/WHERE keyword = '([^']+)'/", $sql, $m)) {
            foreach ($this->rows as $row) { if (($row['keyword'] ?? '') === stripslashes($m[1])) { return $row; } }
        }
        return null;
    }
    public function update(string $table, array $data, array $where, array $format = [], array $where_format = []) {
        $id = (int) ($where['id'] ?? 0);
        $this->updates[] = [ 'data' => $data, 'where' => $where ];
        if (!isset($this->rows[$id])) { return false; }
        $this->rows[$id] = array_merge($this->rows[$id], $data);
        return 1;
    }
}

final class ModelKeywordPoolClassificationApplyServiceTest extends TestCase {
    private Pr603PhpunitWpdb $wpdb;
    private KeywordPoolClassificationApplyService $service;

    protected function setUp(): void {
        $this->wpdb = new Pr603PhpunitWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        $GLOBALS['pr603_phpunit_posts'] = [ 4457 => new WP_Post(4457, 'model', 'Anisyia') ];
        $this->wpdb->rows = $this->fixtureRows();
        $this->service = new KeywordPoolClassificationApplyService(new KeywordPoolCandidateRepository(), new ModelKeywordPoolClassifier());
    }

    public function test_dry_run_batch_does_not_write_to_db(): void {
        $this->service->dry_run_batch(0, 10, 'all');
        $this->assertSame([], $this->wpdb->updates);
    }

    public function test_apply_batch_writes_only_sources_and_updated_at(): void {
        $this->service->apply_batch([1]);
        $this->assertNotEmpty($this->wpdb->updates);
        foreach ($this->wpdb->updates as $update) {
            $keys = array_keys($update['data']);
            sort($keys);
            $this->assertSame([ 'sources', 'updated_at' ], $keys);
        }
    }

    public function test_apply_batch_does_not_change_status(): void {
        $before = $this->wpdb->rows[1]['status'];
        $this->service->apply_batch([1]);
        $this->assertSame($before, $this->wpdb->rows[1]['status']);
    }

    public function test_apply_batch_does_not_change_entity_id(): void {
        $this->service->apply_batch([2]);
        $this->assertSame(0, $this->wpdb->rows[2]['entity_id']);
    }

    public function test_apply_batch_preserves_model_keyword_owner(): void {
        $this->service->apply_batch([4]);
        $sources = json_decode((string) $this->wpdb->rows[4]['sources'], true);
        $this->assertSame('Anisyia', $sources['model_keyword_owner'] ?? '');
    }

    public function test_apply_batch_preserves_model_keyword_usage_scope(): void {
        $this->service->apply_batch([4]);
        $sources = json_decode((string) $this->wpdb->rows[4]['sources'], true);
        $this->assertSame('model_bio_only', $sources['model_keyword_usage_scope'] ?? '');
    }

    public function test_unsafe_standalone_terms_classify_as_unsafe_standalone_modifier(): void {
        $rows = $this->service->dry_run_batch(0, 10, 'all')['rows'];
        $video = $this->findPreview($rows, 'video');
        $this->assertSame(ModelKeywordPoolClassifier::CLASS_UNSAFE_STANDALONE, $video['proposed_keyword_class']);
    }

    public function test_anisyia_with_entity_id_4457_and_get_post_context_classifies_as_personal_model_keyword(): void {
        $row = $this->findPreview($this->service->dry_run_batch(0, 10, 'all')['rows'], 'anisyia');
        $this->assertSame(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, $row['proposed_keyword_class']);
    }

    public function test_anisyia_without_context_does_not_become_personal_model_keyword(): void {
        $row = $this->findPreview($this->service->dry_run_batch(0, 10, 'all')['rows'], 'anisyia no context');
        $this->assertNotSame(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, $row['proposed_keyword_class']);
    }

    public function test_already_classified_rows_are_skipped(): void {
        $result = $this->service->apply_batch([8]);
        $this->assertSame(1, $result['skipped_already_classified']);
        $this->assertSame(0, $result['classified']);
    }

    public function test_approved_rows_remain_approved(): void {
        $this->service->apply_batch([4]);
        $this->assertSame('approved', $this->wpdb->rows[4]['status']);
    }

    public function test_unlinked_rows_remain_entity_id_zero(): void {
        $this->service->apply_batch([2]);
        $this->assertSame(0, $this->wpdb->rows[2]['entity_id']);
    }


    public function test_fetch_missing_ids_excludes_empty_keywords(): void {
        $this->wpdb->rows[9] = [
            'id' => 9,
            'keyword' => '   ',
            'canonical' => '',
            'intent_type' => 'model',
            'entity_type' => 'model',
            'entity_id' => 0,
            'status' => 'queued_for_review',
            'sources' => '',
            'updated_at' => 'before',
        ];

        $this->assertNotContains(9, $this->service->fetch_missing_ids(20));
    }

    public function test_more_than_250_candidate_ids_is_rejected(): void {
        $result = $this->service->apply_batch(range(1, 251));
        $this->assertSame(1, $result['errors']);
        $this->assertSame(0, $result['scanned']);
        $this->assertSame([], $this->wpdb->updates);
    }

    private function findPreview(array $rows, string $keyword): array {
        foreach ($rows as $row) { if (($row['keyword'] ?? '') === $keyword) { return $row; } }
        $this->fail('Preview row not found: ' . $keyword);
    }

    private function fixtureRows(): array {
        $base = static function (int $id, string $keyword, int $entity_id = 0, string $status = 'queued_for_review', array $sources = []): array {
            return [
                'id' => $id,
                'keyword' => $keyword,
                'canonical' => $keyword,
                'intent_type' => 'model',
                'entity_type' => 'model',
                'entity_id' => $entity_id,
                'status' => $status,
                'sources' => json_encode($sources),
                'updated_at' => 'before',
            ];
        };
        return [
            1 => $base(1, 'webcam model'),
            2 => $base(2, 'video'),
            3 => $base(3, 'chat'),
            4 => $base(4, 'anisyia', 4457, 'approved', [ 'model_keyword_owner' => 'Anisyia', 'model_keyword_usage_scope' => 'model_bio_only' ]),
            5 => $base(5, 'livejasmin model'),
            6 => $base(6, 'brunette'),
            7 => $base(7, 'anisyia no context'),
            8 => $base(8, 'anisyia livejasmin', 0, 'queued_for_review', [ 'keyword_class' => 'personal_model_keyword', 'suggested_usage' => 'primary_focus_allowed', 'standalone_allowed' => true ]),
        ];
    }
}
