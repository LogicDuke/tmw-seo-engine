<?php
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__); }
if (!function_exists('remove_accents')) { function remove_accents($v){ return $v; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v){ return json_encode($v); } }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

require_once __DIR__ . '/../includes/seo-engine/opportunities/class-model-opportunity-normalizer.php';
require_once __DIR__ . '/../includes/seo-engine/opportunities/class-model-keyword-role-classifier.php';
require_once __DIR__ . '/../includes/seo-engine/opportunities/class-model-opportunity-scorer.php';
require_once __DIR__ . '/../includes/seo-engine/opportunities/class-model-opportunity-import-service.php';

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Opportunities\ModelOpportunityImportService;

final class FakeWpdbForSingleFamily {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public string $last_error = '';
    public array $opps = [];
    public array $kws = [];

    public function prepare(string $query, ...$args): string { return $query . '|' . implode('|', $args); }
    public function get_var(string $query) { return '1'; }
    public function get_row(string $query, $output = null) {
        if (str_contains($query, 'WHERE canonical_entity_key=')) {
            $parts = explode('|', $query);
            $k = end($parts);
            return $this->opps[$k] ?? null;
        }
        return null;
    }
    public function insert(string $table, array $data) {
        if (str_contains($table, 'model_opportunities')) {
            $this->insert_id++;
            $data['id'] = $this->insert_id;
            $this->opps[$data['canonical_entity_key']] = $data;
            return 1;
        }
        $this->kws[] = $data;
        return 1;
    }
    public function update(string $table, array $data, array $where) {
        if (str_contains($table, 'model_opportunities')) {
            foreach ($this->opps as $k => $row) {
                if (($row['id'] ?? 0) === (int)$where['id']) {
                    $this->opps[$k] = array_merge($row, $data);
                    return 1;
                }
            }
        }
        return 0;
    }
}

final class ModelOpportunityImportServiceSingleFamilyTest extends TestCase {
    protected function setUp(): void {
        global $wpdb;
        $wpdb = new FakeWpdbForSingleFamily();
    }

    public function test_single_model_family_aggregates_parent_once_and_preserves_primary(): void {
        $rows = [
            ['keyword'=>'anisyia','volume'=>'12100','seo_score'=>'68','competition'=>'0','traffic_value'=>'55'],
            ['keyword'=>'anisyia livejasmin','volume'=>'1900','seo_score'=>'40','competition'=>'0.4','traffic_value'=>'12'],
            ['keyword'=>'anisyia live cam','volume'=>'40','seo_score'=>'30','competition'=>'0.2','traffic_value'=>'2'],
            ['keyword'=>'anisyia porn','volume'=>'4400','seo_score'=>'10','competition'=>'0.8','traffic_value'=>'1'],
            ['keyword'=>'Total Volume','volume'=>'19950'],
        ];
        $context = ['model_entity' => 'Anisyia'];
        $result = ModelOpportunityImportService::apply_rows(1, 'kws_single_model_family', $rows, $context, false);
        global $wpdb;
        $opp = reset($wpdb->opps);

        $this->assertSame('anisyia', strtolower((string)$opp['primary_keyword']));
        $this->assertSame(12100, (int)$opp['primary_volume']);
        $this->assertSame(19950, (int)$opp['family_volume']);
        $this->assertNotSame('archive', $opp['priority']);
        $this->assertSame('missing_model_acquisition', $opp['opportunity_type']);
        $this->assertNotSame('platform_coverage', $opp['opportunity_type']);
        $this->assertCount(4, $wpdb->kws);
        $this->assertSame(1, $result['created_count']);
    }

    public function test_single_model_family_uses_existing_model_optimization_when_matched_post_exists(): void {
        global $wpdb;
        $wpdb->opps['anisyia'] = [
            'id' => 77,
            'canonical_entity_key' => 'anisyia',
            'matched_post_id' => 123,
            'family_volume' => 0,
        ];

        $rows = [
            ['keyword'=>'anisyia','volume'=>'12100','seo_score'=>'68','competition'=>'0','traffic_value'=>'55'],
            ['keyword'=>'Total Volume','volume'=>'19950'],
        ];

        ModelOpportunityImportService::apply_rows(2, 'kws_single_model_family', $rows, ['model_entity' => 'Anisyia'], false);
        $opp = $wpdb->opps['anisyia'];

        $this->assertSame('existing_model_optimization', $opp['opportunity_type']);
        $this->assertSame(123, (int) $opp['matched_post_id']);
    }
}
