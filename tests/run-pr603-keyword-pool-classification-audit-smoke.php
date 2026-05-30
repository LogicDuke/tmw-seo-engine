<?php
/**
 * Smoke checks for PR 603 keyword pool classification audit dry-run/apply workflow.
 */

declare(strict_types=1);

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

function pr603_assert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function wp_strip_all_tags(string $text): string { return strip_tags($text); }
function wp_json_encode($data): string { return (string) json_encode($data); }
function current_time(string $type): string { return '2026-05-30 00:00:00'; }
function get_post(int $post_id) { return $GLOBALS['pr603_posts'][$post_id] ?? null; }

require_once dirname(__DIR__) . '/includes/keywords/class-model-keyword-pool-classifier.php';
require_once dirname(__DIR__) . '/includes/keywords/class-keyword-pool-candidate-repository.php';
require_once dirname(__DIR__) . '/includes/keywords/class-keyword-pool-classification-apply-service.php';

use TMWSEO\Engine\Keywords\KeywordPoolCandidateRepository;
use TMWSEO\Engine\Keywords\KeywordPoolClassificationApplyService;
use TMWSEO\Engine\Keywords\ModelKeywordPoolClassifier;

final class Pr603SmokeWpdb {
    public string $prefix = 'wp_';
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];
    public array $updates = [];
    public array $queries = [];
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
    public function get_var(string $sql) {
        $this->queries[] = $sql;
        if (str_starts_with($sql, 'SHOW TABLES LIKE')) { return $this->prefix . 'tmw_keyword_candidates'; }
        return null;
    }
    public function get_results(string $sql, string $output = 'OBJECT'): array {
        $this->queries[] = $sql;
        if (str_starts_with($sql, 'SHOW COLUMNS FROM')) { return array_map(static fn($field) => [ 'Field' => $field ], $this->columns); }
        if (str_contains($sql, 'FROM ' . $this->prefix . 'tmw_keyword_candidates') && str_contains($sql, "intent_type = 'model'")) {
            $rows = array_values(array_filter($this->rows, static fn($row): bool => ($row['intent_type'] ?? '') === 'model'));
            usort($rows, static fn($a, $b): int => ((int) $a['id']) <=> ((int) $b['id']));
            return $rows;
        }
        return [];
    }
    public function get_row(string $sql, string $output = 'OBJECT') {
        $this->queries[] = $sql;
        if (preg_match('/WHERE id = (\d+)/', $sql, $m)) {
            return $this->rows[(int) $m[1]] ?? null;
        }
        if (preg_match("/WHERE keyword = '([^']+)'/", $sql, $m)) {
            $keyword = stripslashes($m[1]);
            foreach ($this->rows as $row) { if (($row['keyword'] ?? '') === $keyword) { return $row; } }
        }
        return null;
    }
    public function update(string $table, array $data, array $where, array $format = [], array $where_format = []) {
        $id = (int) ($where['id'] ?? 0);
        $this->updates[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
        if (!isset($this->rows[$id])) { return false; }
        $this->rows[$id] = array_merge($this->rows[$id], $data);
        return 1;
    }
}

$wpdb = new Pr603SmokeWpdb();
$GLOBALS['wpdb'] = $wpdb;
$GLOBALS['pr603_posts'] = [ 4457 => new WP_Post(4457, 'model', 'Anisyia') ];

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
$wpdb->rows = [
    1 => $base(1, 'webcam model'),
    2 => $base(2, 'video'),
    3 => $base(3, 'chat'),
    4 => $base(4, 'anisyia', 4457, 'approved', [ 'model_keyword_owner' => 'Anisyia', 'model_keyword_usage_scope' => 'model_bio_only' ]),
    5 => $base(5, 'livejasmin model'),
    6 => $base(6, 'brunette'),
    7 => $base(7, 'indian'),
    8 => $base(8, 'anisyia livejasmin', 0, 'queued_for_review', [ 'keyword_class' => 'personal_model_keyword', 'suggested_usage' => 'primary_focus_allowed', 'standalone_allowed' => true, 'model_keyword_owner' => 'Anisyia', 'model_keyword_usage_scope' => 'model_bio_only' ]),
];

$service = new KeywordPoolClassificationApplyService(new KeywordPoolCandidateRepository(), new ModelKeywordPoolClassifier());
$summary = $service->summary();
pr603_assert($summary['total_model_rows'] === 8, 'Summary should count all model rows.');
pr603_assert($summary['already_classified'] === 1, 'Summary should count already classified rows.');
pr603_assert($summary['missing_classification'] === 7, 'Summary should count missing rows.');
pr603_assert($summary['unlinked_entity_id_zero'] === 7, 'Summary should count unlinked rows.');

$before_updates = count($wpdb->updates);
$dry = $service->dry_run_batch(0, 10, 'all');
pr603_assert(count($wpdb->updates) === $before_updates, 'Dry run must not write.');
$by_keyword = [];
foreach ($dry['rows'] as $row) { $by_keyword[$row['keyword']] = $row; }
pr603_assert($by_keyword['video']['proposed_keyword_class'] === ModelKeywordPoolClassifier::CLASS_UNSAFE_STANDALONE, 'video should be unsafe standalone.');
pr603_assert($by_keyword['chat']['proposed_keyword_class'] === ModelKeywordPoolClassifier::CLASS_UNSAFE_STANDALONE, 'chat should be unsafe standalone.');
pr603_assert($by_keyword['webcam model']['proposed_keyword_class'] === ModelKeywordPoolClassifier::CLASS_CORE_MODEL_TERM, 'webcam model should be core model term.');
pr603_assert($by_keyword['livejasmin model']['proposed_keyword_class'] === ModelKeywordPoolClassifier::CLASS_CORE_MODEL_TERM, 'livejasmin model should be core model term.');
pr603_assert($by_keyword['anisyia']['proposed_keyword_class'] === ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, 'Anisyia with post context should be personal.');

$ids = $service->fetch_missing_ids(3);
pr603_assert($ids === [1, 2, 3], 'fetch_missing_ids should return first missing IDs.');

$apply = $service->apply_batch([1,2,3,4,5,6,7,8]);
pr603_assert($apply['scanned'] === 8, 'Apply should scan IDs.');
pr603_assert($apply['classified'] === 7, 'Apply should classify seven missing rows.');
pr603_assert($apply['skipped_already_classified'] === 1, 'Apply should skip already classified row.');
foreach ($wpdb->updates as $update) {
    $keys = array_keys($update['data']);
    sort($keys);
    pr603_assert($keys === [ 'sources', 'updated_at' ], 'Apply may write only sources and updated_at.');
    pr603_assert(!array_key_exists('status', $update['data']), 'Apply must never write status.');
    pr603_assert(!array_key_exists('entity_id', $update['data']), 'Apply must never write entity_id.');
    pr603_assert(!array_key_exists('intent_type', $update['data']), 'Apply must never write intent_type.');
}
pr603_assert($wpdb->rows[4]['status'] === 'approved', 'Approved Anisyia row must remain approved.');
pr603_assert((int) $wpdb->rows[2]['entity_id'] === 0, 'Unlinked video row must remain entity_id 0.');
$sources4 = json_decode((string) $wpdb->rows[4]['sources'], true);
pr603_assert(($sources4['model_keyword_owner'] ?? '') === 'Anisyia', 'model_keyword_owner must survive.');
pr603_assert(($sources4['model_keyword_usage_scope'] ?? '') === 'model_bio_only', 'model_keyword_usage_scope must survive.');
pr603_assert(($sources4['keyword_classified_by'] ?? '') === 'pr603_keyword_pool_classification_audit', 'classified_by should be PR 603 marker.');

$without_context = $service->dry_run_batch(0, 20, 'all');
foreach ($without_context['rows'] as $row) {
    if ($row['keyword'] === 'anisyia livejasmin') {
        pr603_assert($row['proposed_keyword_class'] === ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, 'Owner context may classify matching owner phrase.');
    }
}

$too_many = $service->apply_batch(range(1, 251));
pr603_assert($too_many['errors'] === 1 && $too_many['scanned'] === 0, 'More than 250 IDs should be rejected.');

echo "✓ PR 603 keyword pool classification audit smoke checks passed\n";
