<?php
/**
 * Smoke checks for PR 601 unlinked personal model keyword repair.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

function current_time(string $type = 'mysql', bool $gmt = false): string { return '2026-05-29 00:00:00'; }
function wp_strip_all_tags($text): string { return strip_tags((string) $text); }
function wp_json_encode($value): string { return json_encode($value); }

require_once dirname(__DIR__) . '/includes/models/class-model-entity-resolver.php';
require_once dirname(__DIR__) . '/includes/keywords/class-keyword-pool-csv-parser.php';
require_once dirname(__DIR__) . '/includes/keywords/class-keyword-pool-metrics-scorer.php';
require_once dirname(__DIR__) . '/includes/keywords/class-model-keyword-strategy-classifier.php';
require_once dirname(__DIR__) . '/includes/keywords/class-keyword-pool-dry-run-service.php';
require_once dirname(__DIR__) . '/includes/keywords/class-keyword-pool-candidate-repository.php';
require_once dirname(__DIR__) . '/includes/keywords/class-keyword-pool-selected-import-service.php';
require_once dirname(__DIR__) . '/includes/keywords/class-model-keyword-entity-repair-service.php';

use TMWSEO\Engine\Keywords\KeywordPoolCsvParser;
use TMWSEO\Engine\Keywords\KeywordPoolDryRunService;
use TMWSEO\Engine\Keywords\KeywordPoolSelectedImportService;
use TMWSEO\Engine\Keywords\ModelKeywordEntityRepairService;
use TMWSEO\Engine\Models\ModelEntityResolver;

final class Pr601SmokeWpdb {
    public string $prefix = 'wp601_';
    public int $insert_id = 9000;
    public array $rows = [];
    public array $updates = [];
    public array $inserts = [];
    public array $queries = [];
    private array $columns = [ 'id', 'keyword', 'canonical', 'intent', 'intent_type', 'entity_type', 'entity_id', 'status', 'volume', 'cpc', 'difficulty', 'competition', 'opportunity', 'seo_score', 'traffic_value', 'trend', 'ad_difficulty', 'difficulty_proxy', 'sources', 'notes', 'created_at', 'updated_at' ];

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
    public function get_row(string $sql, string $output = 'OBJECT') {
        $this->queries[] = $sql;
        if (preg_match("/WHERE keyword = '([^']+)'/", $sql, $m)) {
            foreach ($this->rows as $row) { if ($row['keyword'] === stripslashes($m[1])) { return $row; } }
        }
        return null;
    }
    public function get_results(string $sql, string $output = 'OBJECT'): array {
        $this->queries[] = $sql;
        if (str_starts_with($sql, 'SHOW COLUMNS FROM')) { return array_map(static fn($field) => [ 'Field' => $field ], $this->columns); }
        if (str_starts_with($sql, 'SELECT * FROM')) { return array_values($this->rows); }
        return [];
    }
    public function insert(string $table, array $data, array $format = []) {
        $data['id'] = ++$this->insert_id;
        $this->rows[$data['id']] = $data;
        $this->inserts[] = [ 'table' => $table, 'data' => $data ];
        return 1;
    }
    public function update(string $table, array $data, array $where, array $format = [], array $where_format = []) {
        $id = (int) ($where['id'] ?? 0);
        $this->updates[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
        if (isset($this->rows[$id])) { $this->rows[$id] = array_merge($this->rows[$id], $data); }
        return 1;
    }
}

function pr601_row(int $id, string $keyword, string $owner = 'anisyia', int $entity_id = 0): array {
    return [
        'id' => $id,
        'keyword' => $keyword,
        'intent_type' => 'model',
        'entity_type' => 'model',
        'entity_id' => $entity_id,
        'status' => 'approved',
        'volume' => 100,
        'sources' => json_encode([ 'personal_model_keyword_csv' => true, 'model_keyword_owner' => $owner, 'model_keyword_usage_scope' => 'model_bio_only', 'model_keyword_primary_candidate' => 'yes' ]),
        'notes' => '{}',
    ];
}

function pr601_posts(): array { return [ (object) [ 'ID' => 4457, 'post_title' => 'Anisyia', 'post_name' => 'anisyia', 'post_type' => 'model' ] ]; }
function pr601_dry_run(string $csv): array { return (new KeywordPoolDryRunService())->dry_run((new KeywordPoolCsvParser())->parse_text($csv), 'model'); }
function pr601_assert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }

$wpdb = new Pr601SmokeWpdb();
$GLOBALS['wpdb'] = $wpdb;
$wpdb->rows = [
    1 => pr601_row(1, 'anisyia'),
    2 => pr601_row(2, 'anisyia livejasmin'),
    3 => pr601_row(3, 'livejasmin anisyia'),
];
$resolver = new ModelEntityResolver('pr601_posts');
$repair = (new ModelKeywordEntityRepairService($resolver))->resolve_selected([1, 2, 3]);
pr601_assert($repair['linked'] === 3, 'Expected 3 repaired rows.');
foreach ([1, 2, 3] as $id) { pr601_assert((int) $wpdb->rows[$id]['entity_id'] === 4457, 'Expected repaired entity_id 4457.'); }

$dryRun = pr601_dry_run("Keyword, Volume, SEO Score\nanisyia,12100,68\n");
$beforeInsertCount = count($wpdb->inserts);
$result = (new KeywordPoolSelectedImportService(null, $resolver))->save_full_reviewed_model_batch($dryRun);
pr601_assert($result['summary']['updated'] === 1, 'Expected full batch to update existing row.');
pr601_assert(count($wpdb->inserts) === $beforeInsertCount, 'Full batch should not insert duplicate.');

foreach ($wpdb->rows as $row) {
    $sources = json_decode((string) ($row['sources'] ?? '{}'), true) ?: [];
    if (($row['status'] ?? '') === 'approved' && ($row['intent_type'] ?? '') === 'model' && ($sources['model_keyword_usage_scope'] ?? '') === 'model_bio_only') {
        pr601_assert((int) ($row['entity_id'] ?? 0) > 0, 'No approved model_bio_only row should remain entity_id 0 when model exists.');
    }
}

$wpdb->rows[10] = pr601_row(10, 'conflict anisyia', 'othermodel', 9999);
$conflictDryRun = pr601_dry_run("Keyword, Volume, SEO Score\nconflict anisyia,100,68\n");
$conflict = (new KeywordPoolSelectedImportService(null, $resolver))->save_full_reviewed_model_batch($conflictDryRun);
pr601_assert($conflict['summary']['conflicts'] === 1, 'Expected linked different model conflict.');
pr601_assert((int) $wpdb->rows[10]['entity_id'] === 9999, 'Conflict row must not be overwritten.');

echo "unlinked personal model keyword repair smoke checks passed\n";
