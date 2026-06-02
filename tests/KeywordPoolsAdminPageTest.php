<?php
/**
 * Tests for the keyword pools dry-run admin page.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Admin\KeywordPoolsAdminPage;
use TMWSEO\Engine\Keywords\KeywordPoolCsvParser;
use TMWSEO\Engine\Keywords\KeywordPoolDryRunService;

require_once __DIR__ . '/../includes/keywords/class-keyword-pool-csv-parser.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-metrics-scorer.php';
require_once __DIR__ . '/../includes/keywords/class-model-keyword-strategy-classifier.php';
require_once __DIR__ . '/../includes/keywords/class-model-keyword-pool-classifier.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-dry-run-service.php';
require_once __DIR__ . '/../includes/models/class-model-entity-resolver.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-selected-import-service.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-candidate-repository.php';
require_once __DIR__ . '/../includes/admin/class-keyword-pools-admin-page.php';

class KeywordPoolsAdminPageTest extends TestCase {

    protected function tearDown(): void {
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        parent::tearDown();
    }

    private function renderPreviewForPool(string $pool, string $csv): string {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'tmwseo_keyword_pools_run_preview' => '1',
            'tmwseo_keyword_pool' => $pool,
            'tmwseo_keyword_pools_nonce' => 'test_nonce',
            'tmwseo_keyword_pools_csv_text' => $csv,
        ];

        ob_start();
        KeywordPoolsAdminPage::render_page();
        return (string) ob_get_clean();
    }

    public function test_class_slug_and_capability_are_available(): void {
        $this->assertTrue(class_exists(KeywordPoolsAdminPage::class));
        $this->assertSame('tmwseo-keyword-pools', KeywordPoolsAdminPage::slug());
        $this->assertSame('manage_options', KeywordPoolsAdminPage::capability());
        $this->assertTrue(method_exists(KeywordPoolsAdminPage::class, 'render'));
    }

    public function test_admin_menu_registers_keyword_pools_submenu(): void {
        $source = file_get_contents(__DIR__ . '/../includes/admin/class-admin.php');
        $this->assertIsString($source);

        $this->assertStringContainsString('add_submenu_page(', $source);
        $this->assertStringContainsString('\TMWSEO\Engine\Admin\KeywordPoolsAdminPage::capability()', $source);
        $this->assertStringContainsString('\TMWSEO\Engine\Admin\KeywordPoolsAdminPage::slug()', $source);
        $this->assertStringContainsString("[\TMWSEO\Engine\Admin\KeywordPoolsAdminPage::class, 'render']", $source);
    }

    public function test_admin_menu_reorder_keeps_keyword_pools_near_keyword_group(): void {
        $source = file_get_contents(__DIR__ . '/../includes/admin/class-admin.php');
        $this->assertIsString($source);

        $this->assertMatchesRegularExpression('/\$desired_order\s*=\s*\[(?P<order>.*?)\];/s', $source);
        preg_match('/\$desired_order\s*=\s*\[(?P<order>.*?)\];/s', $source, $matches);
        $desiredOrder = $matches['order'];

        $keywordsPosition = strpos($desiredOrder, "'tmwseo-keywords'");
        $keywordPoolsPosition = strpos($desiredOrder, "'tmwseo-keyword-pools'");
        $modelOpportunitiesPosition = strpos($desiredOrder, "'tmwseo-model-opportunities'");

        $this->assertNotFalse($keywordsPosition);
        $this->assertNotFalse($keywordPoolsPosition);
        $this->assertNotFalse($modelOpportunitiesPosition);
        $this->assertGreaterThan($keywordsPosition, $keywordPoolsPosition);
        $this->assertLessThan($modelOpportunitiesPosition, $keywordPoolsPosition);
    }

    public function test_loader_requires_keyword_pools_admin_page_before_importer_init(): void {
        $source = file_get_contents(__DIR__ . '/../includes/class-loader.php');
        $this->assertIsString($source);

        $keywordPoolsPosition = strpos($source, "class-keyword-pools-admin-page.php");
        $keywordMetricsPosition = strpos($source, "class-keyword-metrics-csv-importer.php");
        $this->assertNotFalse($keywordPoolsPosition);
        $this->assertNotFalse($keywordMetricsPosition);
        $this->assertLessThan($keywordMetricsPosition, $keywordPoolsPosition);
    }

    public function test_pool_names_are_constrained_to_three_active_pools(): void {
        $this->assertSame([ 'model', 'video', 'category' ], KeywordPoolsAdminPage::allowed_pools());
    }


    public function test_preview_and_export_include_model_keyword_scope_columns(): void {
        $headers = KeywordPoolsAdminPage::preview_columns();
        $this->assertContains('Model Keyword Owner', $headers);
        $this->assertContains('Model Keyword Usage Scope', $headers);
        $this->assertContains('Model Keyword Primary Candidate', $headers);
        $this->assertContains('Model Keyword Scope Reason Codes', $headers);

        $csv = KeywordPoolsAdminPage::build_export_csv([[
            'row_number' => 2,
            'keyword' => 'anisyia',
            'normalized_keyword' => 'anisyia',
            'model_keyword_owner' => 'anisyia',
            'model_keyword_usage_scope' => 'model_bio_only',
            'model_keyword_primary_candidate' => 'yes',
            'model_keyword_scope_reason_codes' => [ 'personal_model_keyword_csv', 'model_specific_keyword' ],
        ]]);

        $this->assertStringContainsString('Model Keyword Owner', $csv);
        $this->assertStringContainsString('anisyia', $csv);
        $this->assertStringContainsString('model_bio_only', $csv);
        $this->assertStringContainsString('personal_model_keyword_csv | model_specific_keyword', $csv);
    }

    public function test_render_method_includes_dry_run_safety_copy_and_form_fields(): void {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['pool'] = 'model';

        ob_start();
        KeywordPoolsAdminPage::render_page();
        $html = ob_get_clean();

        $this->assertIsString($html);
        $this->assertStringContainsString('Keyword Pools', $html);
        $this->assertStringContainsString('Dry-run first workflow', $html);
        $this->assertStringContainsString('does not save keywords', $html);
        $this->assertStringContainsString('does not write Rank Math fields', $html);
        $this->assertStringContainsString('does not change post content', $html);
        $this->assertStringContainsString('tmwseo_keyword_pools_nonce', $html);
        $this->assertStringContainsString('name="action" value="tmwseo_keyword_pools_dry_run"', $html);
        $this->assertStringContainsString('name="tmwseo_keyword_pool" value="model"', $html);
        $this->assertStringContainsString('Run Dry Run Preview', $html);
    }





    public function test_save_selected_form_phpdoc_matches_actual_parameters(): void {
        $source = file_get_contents(__DIR__ . '/../includes/admin/class-keyword-pools-admin-page.php');
        $this->assertIsString($source);

        $this->assertMatchesRegularExpression('/@param\s+string\s+\$pool\b/', $source);
        $this->assertMatchesRegularExpression('/@param\s+array<string, mixed>\s+\$parser_result\b/', $source);
        $this->assertMatchesRegularExpression('/@param\s+array<string, mixed>\s+\$dry_run\b/', $source);
        $this->assertDoesNotMatchRegularExpression('/@param\s+array<int, mixed>\s+\$rows\s+Rows\.\s*\*\/\s*private static function render_save_selected_form/s', $source);
    }

    public function test_preview_includes_save_selected_controls_for_eligible_rows(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'tmwseo_keyword_pools_run_preview' => '1',
            'tmwseo_keyword_pool' => 'category',
            'tmwseo_keyword_pools_nonce' => 'test_nonce',
            'tmwseo_keyword_pools_csv_text' => "keyword,volume,cpc,competition\nasian cam models,18100,5.99,0.02\nfree video chat,1000,0.1,0.5\n",
        ];

        ob_start();
        KeywordPoolsAdminPage::render_page();
        $html = ob_get_clean();

        $this->assertStringContainsString('Select all P1', $html);
        $this->assertStringContainsString('Select all Golden', $html);
        $this->assertStringContainsString('Select all Approve Candidates', $html);
        $this->assertStringContainsString('Clear selection', $html);
        $this->assertStringContainsString('Save Selected Keywords', $html);
        $this->assertStringContainsString('Save Full Reviewed Category Keyword Batch', $html);
        $this->assertStringContainsString('Stores all useful reviewed category rows in the keyword candidate pool only', $html);
        $this->assertStringNotContainsString('Save Full Reviewed Model Keyword Batch', $html);
        $this->assertStringContainsString('Save selected as:', $html);
        $this->assertStringContainsString('Saving selected keywords stores them in the review pool only', $html);
        $this->assertStringContainsString('name="tmwseo_keyword_pool_selected_rows[]"', $html);
        $this->assertStringContainsString('disabled', $html);
    }


    public function test_model_pool_preview_renders_only_model_full_batch_button(): void {
        $html = $this->renderPreviewForPool('model', "keyword,volume,cpc,competition,SEO Score
anisyia,12100,3.25,0.08,68
");

        $this->assertStringContainsString('Save Full Reviewed Model Keyword Batch', $html);
        $this->assertStringNotContainsString('Save Full Reviewed Category Keyword Batch', $html);
        $this->assertStringNotContainsString('tmwseo_keyword_pools_save_full_category_batch', $html);
    }

    public function test_video_pool_preview_renders_no_full_batch_buttons(): void {
        $html = $this->renderPreviewForPool('video', "keyword,volume,cpc,competition,model_name
Lexy Ness webcam video,1200,2.50,0.1,Lexy Ness
");

        $this->assertStringNotContainsString('Save Full Reviewed Model Keyword Batch', $html);
        $this->assertStringNotContainsString('Save Full Reviewed Category Keyword Batch', $html);
    }

    public function test_category_full_batch_post_field_routes_to_category_batch_save(): void {
        $source = file_get_contents(__DIR__ . '/../includes/admin/class-keyword-pools-admin-page.php');
        $this->assertIsString($source);

        $this->assertStringContainsString("tmwseo_keyword_pools_save_full_category_batch", $source);
        $this->assertStringContainsString("save_full_reviewed_category_batch(\$dry_run)", $source);
        $this->assertStringContainsString("\$save_full_category_batch = !empty(\$_POST['tmwseo_keyword_pools_save_full_category_batch']) && 'category' === \$active_pool;", $source);
    }


    public function test_bulk_select_golden_excludes_tmw_deferred_rows(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'tmwseo_keyword_pools_run_preview' => '1',
            'tmwseo_keyword_pool' => 'model',
            'tmwseo_keyword_pools_nonce' => 'test_nonce',
            'tmwseo_keyword_pools_csv_text' => "keyword,volume,cpc,competition,SEO Score
webcam models,1200,2.50,0.10,75
dani daniels,1200,2.50,0.10,75
",
        ];

        ob_start();
        KeywordPoolsAdminPage::render_page();
        $html = ob_get_clean();

        $this->assertStringContainsString('TMW Priority', $html);
        $this->assertStringContainsString('Model Keyword Strategy', $html);
        $this->assertStringContainsString('tmwseo-keyword-row-golden', $html);
        $this->assertStringContainsString('defer_until_lj_50_model_milestone', $html);
        preg_match_all('/<input[^>]+tmwseo-keyword-row-golden/', $html, $matches);
        $this->assertCount(1, $matches[0]);
    }

    public function test_export_helper_outputs_expected_csv_header(): void {
        $csv = KeywordPoolsAdminPage::build_export_csv([]);
        $header = str_getcsv(rtrim((string) strtok($csv, "\n"), "\r"));

        $this->assertSame(KeywordPoolsAdminPage::export_headers(), $header);
        $this->assertContains('Priority', $header);
        $this->assertContains('Golden?', $header);
        $this->assertContains('Recommended Action', $header);
        $this->assertContains('Golden Missing Reasons', $header);
        $this->assertContains('Golden Formula', $header);
        $this->assertContains('SEO Score', $header);
        $this->assertContains('Traffic Value', $header);
        $this->assertContains('Ad Difficulty', $header);
        $this->assertContains('TMW Score', $header);
        $this->assertContains('TMW Priority', $header);
        $this->assertContains('TMW Indexing Readiness', $header);
        $this->assertContains('TMW Recommended Action', $header);
        $this->assertContains('Model Keyword Strategy', $header);
        $this->assertContains('Model Keyword Confidence', $header);
        $this->assertContains('Model Keyword Reason Codes', $header);
        $this->assertContains('Model Keyword Recommended Action', $header);
    }

    public function test_export_helper_outputs_current_preview_rows(): void {
        $parser = new KeywordPoolCsvParser();
        $parsed = $parser->parse_text("keyword,volume,difficulty,cpc,competition,SEO Score,Traffic Value,Ad Difficulty,intent,source,model_name\nLexy Ness webcam video,1200,33,0.42,0.2,88,42.25,11,video,kws,Lexy Ness\n");
        $dryRun = (new KeywordPoolDryRunService())->dry_run($parsed, 'video');

        $csv = KeywordPoolsAdminPage::build_export_csv($dryRun['rows']);

        $this->assertStringContainsString('Lexy Ness webcam video', $csv);
        $this->assertStringContainsString('video_intent_detected', $csv);
        $this->assertStringContainsString('P1', $csv);
        $this->assertStringContainsString('approve_candidate', $csv);
        $this->assertStringContainsString('Golden Missing Reasons', $csv);
        $this->assertStringContainsString('Golden Formula', $csv);
        $this->assertStringContainsString('cpc_below_2_00', $csv);
        $this->assertStringContainsString('88', $csv);
        $this->assertStringContainsString('42.25', $csv);
    }


    public function test_export_helper_removes_invalid_ad_difficulty_for_blank_metric(): void {
        $parser = new KeywordPoolCsvParser();
        $parsed = $parser->parse_text("keyword,Ad Difficulty\nasian cam models,\n");
        $dryRun = (new KeywordPoolDryRunService())->dry_run($parsed, 'category');
        $row    = $dryRun['rows'][0];
        $row['reason_codes'][] = 'invalid_ad_difficulty';

        $csv  = KeywordPoolsAdminPage::build_export_csv([ $row ]);
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $header = $rows[0];
        $values = $rows[1];
        $reasonCodesIndex = array_search('Reason Codes', $header, true);

        $this->assertIsInt($reasonCodesIndex);
        $this->assertStringNotContainsString('invalid_ad_difficulty', $values[$reasonCodesIndex]);
        $this->assertStringContainsString('category_intent_detected', $values[$reasonCodesIndex]);
    }

    public function test_admin_page_source_does_not_call_persistent_keyword_or_content_writes(): void {
        $source = file_get_contents(__DIR__ . '/../includes/admin/class-keyword-pools-admin-page.php');
        $this->assertIsString($source);

        $forbiddenCalls = [
            'ModelOpportunityImportService::import',
            'CategoryPageKeywordGenerator',
            'RankMathMapper',
            'ContentEngine',
            'wp_insert_post',
            'wp_update_post',
            'update_post_meta',
        ];

        foreach ($forbiddenCalls as $call) {
            $this->assertStringNotContainsString($call, $source);
        }
    }
}
