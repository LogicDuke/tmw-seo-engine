<?php
/**
 * Smoke checks for PR 600 personal model keyword batch storage.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s) { return strip_tags((string) $s); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v) { return json_encode($v); } }
if (!function_exists('current_time')) { function current_time($type) { return gmdate('Y-m-d H:i:s'); } }
if (!function_exists('get_posts')) { function get_posts($args = []) { return []; } }

require_once dirname(__DIR__) . '/includes/models/class-model-entity-resolver.php';
require_once dirname(__DIR__) . '/includes/keywords/class-keyword-pool-csv-parser.php';
require_once dirname(__DIR__) . '/includes/keywords/class-keyword-pool-metrics-scorer.php';
require_once dirname(__DIR__) . '/includes/keywords/class-model-keyword-strategy-classifier.php';
require_once dirname(__DIR__) . '/includes/keywords/class-keyword-pool-dry-run-service.php';
require_once dirname(__DIR__) . '/includes/keywords/class-keyword-pool-candidate-repository.php';
require_once dirname(__DIR__) . '/includes/keywords/class-keyword-pool-selected-import-service.php';

use TMWSEO\Engine\Keywords\KeywordPoolCsvParser;
use TMWSEO\Engine\Keywords\KeywordPoolDryRunService;
use TMWSEO\Engine\Keywords\KeywordPoolSelectedImportService;
use TMWSEO\Engine\Models\ModelEntityResolver;

final class PersonalModelKeywordSmokeWpdb {
    public string $prefix = 'wp_';
    public int $insert_id = 1;
    /** @var array<int,array<string,mixed>> */
    public array $inserts = [];

    public function esc_like($text): string { return addcslashes((string) $text, '_%\\'); }

    public function prepare(string $sql, ...$args): string {
        $i = 0;
        return (string) preg_replace_callback('/%[sdf]/', static function () use ($args, &$i) {
            $value = $args[$i++] ?? '';
            return is_string($value) ? "'" . addslashes($value) . "'" : (string) $value;
        }, $sql);
    }

    public function get_var(string $sql) {
        if (str_starts_with($sql, 'SHOW TABLES LIKE')) {
            return 'wp_tmw_keyword_candidates';
        }
        return null;
    }

    public function get_row(string $sql, string $output = 'OBJECT') { return null; }

    public function get_results(string $sql, string $output = 'OBJECT'): array {
        if (str_starts_with($sql, 'SHOW COLUMNS FROM')) {
            return array_map(static fn($field) => [ 'Field' => $field ], [
                'id', 'keyword', 'canonical', 'status', 'intent', 'intent_type', 'entity_type', 'entity_id',
                'volume', 'cpc', 'difficulty', 'competition', 'opportunity', 'seo_score', 'traffic_value',
                'trend', 'ad_difficulty', 'difficulty_proxy', 'sources', 'notes', 'created_at', 'updated_at',
            ]);
        }
        return [];
    }

    public function insert(string $table, array $data, array $format = []) {
        $this->inserts[] = [ 'table' => $table, 'data' => $data ];
        $this->insert_id++;
        return 1;
    }

    public function update(string $table, array $data, array $where, array $format = [], array $where_format = []) { return 1; }
}

$GLOBALS['wpdb'] = new PersonalModelKeywordSmokeWpdb();

$resolver = new ModelEntityResolver(static fn() => [ (object) [
    'ID' => 900,
    'post_title' => 'Anisyia',
    'post_name' => 'anisyia',
    'post_type' => 'model',
] ]);

$resolution = $resolver->resolve('anisyia');
if (empty($resolution['found']) || 900 !== (int) $resolution['entity_id']) {
    throw new RuntimeException('Anisyia model resolver smoke check failed.');
}

$csv = "Keyword, Volume, SEO Score\n"
    . "anisyia,12100,68\n"
    . "anisyia livejasmin,1900,50\n"
    . "livejasmin anisyia,170,35\n"
    . "anisyia cam,80,20\n"
    . "cheapest sex cam sites,1200,75\n"
    . "Total Volume,19950,\n";

$dry_run = (new KeywordPoolDryRunService())->dry_run((new KeywordPoolCsvParser())->parse_text($csv), 'model');
$result = (new KeywordPoolSelectedImportService(null, $resolver))->save_full_reviewed_model_batch($dry_run);
if (5 !== (int) $result['summary']['inserted'] || 1 !== (int) $result['summary']['blocked']) {
    throw new RuntimeException('Full batch summary smoke check failed: ' . wp_json_encode($result['summary']));
}

$statuses = [];
foreach ($GLOBALS['wpdb']->inserts as $insert) {
    $data = $insert['data'];
    $keyword = (string) $data['keyword'];
    if ('total volume' === $keyword) {
        throw new RuntimeException('Footer row was inserted.');
    }
    if (900 !== (int) $data['entity_id']) {
        throw new RuntimeException('Saved keyword did not link to Anisyia: ' . $keyword);
    }
    if ('approved' === (string) $data['status'] && 0 === (int) $data['entity_id']) {
        throw new RuntimeException('Approved model-bio row saved with entity_id 0.');
    }
    if (!str_contains((string) $data['sources'], 'personal_model_keyword_csv')) {
        throw new RuntimeException('Personal model CSV provenance missing.');
    }
    $statuses[$keyword] = (string) $data['status'];
}

foreach ([ 'anisyia', 'anisyia livejasmin', 'livejasmin anisyia' ] as $keyword) {
    if ('approved' !== ($statuses[$keyword] ?? '')) {
        throw new RuntimeException('Primary keyword was not approved: ' . $keyword);
    }
}
if ('queued_for_review' !== ($statuses['anisyia cam'] ?? '')) {
    throw new RuntimeException('Weak row was not queued.');
}
if ('rejected' !== ($statuses['cheapest sex cam sites'] ?? '')) {
    throw new RuntimeException('Not-model row was not rejected.');
}

$GLOBALS['wpdb'] = new PersonalModelKeywordSmokeWpdb();
(new KeywordPoolSelectedImportService(null, new ModelEntityResolver(static fn() => [])))->save_selected($dry_run, 'model', [ 2 ], 'approved');
$unresolved = $GLOBALS['wpdb']->inserts[0]['data'] ?? [];
if ('approved' === (string) ($unresolved['status'] ?? '') || 0 !== (int) ($unresolved['entity_id'] ?? -1)) {
    throw new RuntimeException('Unresolved owner was not safely queued with entity_id 0.');
}

$table_source = (string) file_get_contents(dirname(__DIR__) . '/includes/admin/tables/class-keywords-table.php');
foreach ([ 'Model Owner', 'Usage Scope', 'Provenance', 'Entity Link Status', 'source_label_from_item' ] as $needle) {
    if (!str_contains($table_source, $needle)) {
        throw new RuntimeException('Keywords table metadata display missing: ' . $needle);
    }
}

echo "personal model keyword full batch storage smoke checks passed\n";
