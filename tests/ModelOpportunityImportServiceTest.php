<?php
require_once __DIR__ . '/../includes/seo-engine/opportunities/class-model-opportunity-normalizer.php';
require_once __DIR__ . '/../includes/seo-engine/opportunities/class-model-opportunity-import-service.php';

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Opportunities\ModelOpportunityImportService;

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__); }
if (!function_exists('remove_accents')) { function remove_accents($v){ return $v; } }

final class ModelOpportunityImportServiceTest extends TestCase {
    /** @dataProvider csvProvider */
    public function test_parse_csv_maps_kws_columns(string $csv, int $expectedRows, string $firstKeyword): void {
        $tmp = tempnam(sys_get_temp_dir(), 'tmw_');
        file_put_contents($tmp, $csv);
        $rows = ModelOpportunityImportService::parse_csv($tmp);
        @unlink($tmp);
        $this->assertCount($expectedRows, $rows);
        $this->assertSame($firstKeyword, $rows[0]['keyword']);
        $this->assertArrayHasKey('volume', $rows[0]);
        $this->assertArrayHasKey('traffic_value', $rows[0]);
    }

    public static function csvProvider(): array {
        $header = "Keyword,Volume,Trend,Trend Dir.,SEO Score,Traffic Value,Competition,Ad Difficulty,Lowest CPC,Average CPC,Highest CPC,CPC Spread\n";
        return [
            'anisyia_single_family' => [$header . "Anisyia onlyfans,1200,12,up,66,45.5,0.4,12,0.12,0.22,0.42,0.30\nTotal Volume,3200,,,,,,,,,,\n", 2, 'Anisyia onlyfans'],
            'dani_daniels_single_family' => [$header . "Dani Daniels livejasmin,900,8,up,70,52,0.5,20,0.30,0.45,0.75,0.45\n", 1, 'Dani Daniels livejasmin'],
            'bulk_modelcentro_style' => [$header . "ModelCentro performer one,450,2,flat,40,9.1,0.2,15,0.08,0.12,0.20,0.12\n", 1, 'ModelCentro performer one'],
            'competitor_keywords' => [$header . "anisyia profile,300,4,up,55,12,0.3,10,0.09,0.14,0.21,0.12\n", 1, 'anisyia profile'],
            'platform_model_list' => ["Keyword,Volume\nAnisyia,0\nDani Daniels,0\n", 2, 'Anisyia'],
        ];
    }
}
