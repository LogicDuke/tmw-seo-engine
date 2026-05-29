<?php
/**
 * Smoke checks for PR 600 CodeRabbit review fixes.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

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

final class Pr600ReviewSmokeWpdb {
    public string $prefix = 'wp_';
    public int $insert_id = 1;
    public array $inserts = [];
    public array $prepare_args = [];
    public function esc_like($text): string { return addcslashes((string) $text, '_%\\'); }
    public function prepare(string $sql, ...$args): string { $this->prepare_args[] = $args; $i = 0; return (string) preg_replace_callback('/%[sdf]/', static function () use ($args, &$i) { $value = $args[$i++] ?? ''; return is_string($value) ? "'" . addslashes($value) . "'" : (string) $value; }, $sql); }
    public function get_var(string $sql) { return str_starts_with($sql, 'SHOW TABLES LIKE') ? 'wp_tmw_keyword_candidates' : 0; }
    public function get_row(string $sql, string $output = 'OBJECT') { return null; }
    public function get_results(string $sql, string $output = 'OBJECT'): array { if (str_starts_with($sql, 'SHOW COLUMNS FROM')) { return array_map(static fn($field) => [ 'Field' => $field ], [ 'id', 'keyword', 'canonical', 'status', 'intent', 'intent_type', 'entity_type', 'entity_id', 'volume', 'cpc', 'difficulty', 'competition', 'opportunity', 'seo_score', 'traffic_value', 'trend', 'ad_difficulty', 'difficulty_proxy', 'sources', 'notes', 'created_at', 'updated_at' ]); } return []; }
    public function insert(string $table, array $data, array $format = []) { $this->inserts[] = [ 'table' => $table, 'data' => $data ]; $this->insert_id++; return 1; }
    public function update(string $table, array $data, array $where, array $format = [], array $where_format = []) { return 1; }
}

final class Pr600ReviewCountingResolver extends ModelEntityResolver {
    public int $resolve_calls = 0;
    private array $posts;
    public function __construct(array $posts) { parent::__construct(static fn() => []); $this->posts = $posts; }
    public function resolve(string $owner): array { $this->resolve_calls++; return (new ModelEntityResolver(fn() => $this->posts))->resolve($owner); }
}

$GLOBALS['wpdb'] = new Pr600ReviewSmokeWpdb();
$posts = [ (object) [ 'ID' => 900, 'post_title' => 'Anisyia', 'post_name' => 'anisyia', 'post_type' => 'model' ] ];
$counting_resolver = new Pr600ReviewCountingResolver($posts);

$exact = (new ModelEntityResolver(static fn() => $posts))->resolve('Anisyia');
$normalized = (new ModelEntityResolver(static fn() => [ (object) [ 'ID' => 901, 'post_title' => 'Anisyia', 'post_name' => 'anisyia-alt', 'post_type' => 'model' ] ]))->resolve('anisyia');
if (($exact['match_type'] ?? '') !== 'exact_title' || ($normalized['match_type'] ?? '') !== 'normalized_title') {
    throw new RuntimeException('Resolver match taxonomy smoke check failed.');
}

$csv = "Keyword, Volume, SEO Score\n"
    . "anisyia,12100,68\n"
    . "anisyia livejasmin,1900,50\n"
    . "livejasmin anisyia,170,35\n"
    . "anisyia cam,80,20\n"
    . "anisyia chat,70,20\n"
    . "anisyia com,60,20\n"
    . "anisyia live,50,20\n"
    . "anisyia porn,40,20\n"
    . "anisyia sex,30,20\n"
    . "anisyiaxxx,20,10\n"
    . "Total Volume,19950,\n";
$dry_run = (new KeywordPoolDryRunService())->dry_run((new KeywordPoolCsvParser())->parse_text($csv), 'model');
$result = (new KeywordPoolSelectedImportService(null, $counting_resolver))->save_full_reviewed_model_batch($dry_run);
if (10 !== (int) $result['summary']['inserted'] || 1 !== $counting_resolver->resolve_calls) {
    throw new RuntimeException('Cached full batch resolution smoke check failed: ' . wp_json_encode($result['summary']));
}
foreach ($GLOBALS['wpdb']->inserts as $insert) {
    if (900 !== (int) $insert['data']['entity_id']) { throw new RuntimeException('Anisyia entity ID was not applied.'); }
    if ('approved' === (string) $insert['data']['status'] && 0 === (int) $insert['data']['entity_id']) { throw new RuntimeException('Approved model-bio row saved with entity_id 0.'); }
}

$GLOBALS['wpdb'] = new Pr600ReviewSmokeWpdb();
$provided_dry_run = (new KeywordPoolDryRunService())->dry_run((new KeywordPoolCsvParser())->parse_text("keyword,volume,cpc,competition,model_name,post_id\nLexy Ness webcam model,900,3.25,0.08,Lexy Ness,77\n"), 'model');
(new KeywordPoolSelectedImportService())->save_selected($provided_dry_run, 'model', [ 2 ], 'approved');
$sources = (string) ($GLOBALS['wpdb']->inserts[0]['data']['sources'] ?? '');
if (!str_contains($sources, 'model_entity_resolution') || !str_contains($sources, 'provided_entity_id') || !str_contains($sources, 'model_match_provided_entity_id')) {
    throw new RuntimeException('Provided entity ID resolution provenance missing.');
}

$table_source = (string) file_get_contents(dirname(__DIR__) . '/includes/admin/tables/class-keywords-table.php');
foreach ([ '$wpdb->esc_like( \'personal_model_keyword_csv\' )', '$wpdb->esc_like( \'"model_keyword_primary_candidate":"yes"\' )', '$wpdb->esc_like( \'"model_keyword_usage_scope":"model_bio_only"\' )' ] as $needle) {
    if (!str_contains($table_source, $needle)) { throw new RuntimeException('Escaped LIKE filter source check failed: ' . $needle); }
}
$admin_source = (string) file_get_contents(dirname(__DIR__) . '/includes/admin/class-admin.php');
if (!str_contains($admin_source, 'Ignored Model Keywords') || (str_contains($admin_source, 'Rejected Model Keywords') && str_contains($admin_source, "'status' => 'rejected', 'intent_type' => 'model'"))) {
    throw new RuntimeException('Ignored/rejected quick link smoke check failed.');
}

echo "pr600 review fixes smoke checks passed\n";
