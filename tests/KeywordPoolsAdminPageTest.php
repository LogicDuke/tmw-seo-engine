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
require_once __DIR__ . '/../includes/admin/class-keyword-pool-target-provider.php';
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
        if (isset($GLOBALS['_tmw_test_posts'])) {
            $GLOBALS['_tmw_test_posts'] = [];
        }

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


    public function test_category_pool_upload_form_shows_target_category_selector_and_source_label(): void {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [ 'pool' => 'category' ];
        $GLOBALS['_tmw_test_posts'] = [
            301 => (object) [ 'ID' => 301, 'post_type' => 'tmw_category_page', 'post_status' => 'publish', 'post_title' => 'Big Boob Cam', 'post_name' => 'big-boob-cam' ],
        ];

        ob_start();
        KeywordPoolsAdminPage::render_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Target Category', $html);
        $this->assertStringContainsString('tmwseo_keyword_pools_target_id', $html);
        $this->assertStringContainsString('Big Boob Cam (ID 301, slug: big-boob-cam)', $html);
        $this->assertStringContainsString('Source Label', $html);
    }

    public function test_model_pool_upload_form_shows_target_model_selector_and_source_label(): void {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [ 'pool' => 'model' ];
        $GLOBALS['_tmw_test_posts'] = [
            902 => (object) [ 'ID' => 902, 'post_type' => 'model', 'post_status' => 'draft', 'post_title' => 'Gabriela Hadid', 'post_name' => 'gabriela-hadid' ],
        ];

        ob_start();
        KeywordPoolsAdminPage::render_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Target Model', $html);
        $this->assertStringContainsString('tmwseo_keyword_pools_target_id', $html);
        $this->assertStringContainsString('Gabriela Hadid (ID 902, slug: gabriela-hadid)', $html);
        $this->assertStringContainsString('Source Label', $html);
    }


    public function test_model_pool_upload_form_shows_global_model_pool_option_above_models(): void {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [ 'pool' => 'model' ];
        $GLOBALS['_tmw_test_posts'] = [
            902 => (object) [ 'ID' => 902, 'post_type' => 'model', 'post_status' => 'draft', 'post_title' => 'Gabriela Hadid', 'post_name' => 'gabriela-hadid' ],
        ];

        ob_start();
        KeywordPoolsAdminPage::render_page();
        $html = (string) ob_get_clean();

        $globalPosition = strpos($html, 'Global Model Pool / All Models');
        $modelPosition = strpos($html, 'Gabriela Hadid (ID 902, slug: gabriela-hadid)');
        $this->assertNotFalse($globalPosition);
        $this->assertNotFalse($modelPosition);
        $this->assertLessThan($modelPosition, $globalPosition);
        $this->assertStringContainsString('value="__global_model_pool__"', $html);
    }

    public function test_global_model_pool_preview_satisfies_model_target_requirement_and_shows_scope(): void {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'tmwseo_keyword_pools_run_preview' => '1',
            'tmwseo_keyword_pool' => 'model',
            'tmwseo_keyword_pools_nonce' => 'test_nonce',
            'tmwseo_keyword_pools_target_id' => '__global_model_pool__',
            'tmwseo_keyword_pools_csv_text' => "keyword,volume
all cam models live,100
",
        ];

        ob_start();
        KeywordPoolsAdminPage::render_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Target: Global Model Pool / All Models. Scope: global_model_pool.', $html);
        $this->assertStringContainsString('global_model_pool', $html);
        $this->assertStringNotContainsString('Target Model or Global Model Pool / All Models is required before saving model keywords.', $html);
    }

    public function test_target_provider_uses_uncapped_post_query_for_v1(): void {
        $source = file_get_contents(__DIR__ . '/../includes/admin/class-keyword-pool-target-provider.php');
        $this->assertIsString($source);

        $this->assertStringContainsString("'posts_per_page' => -1", $source);
        $this->assertStringContainsString('TODO: Replace full-list loading with AJAX/search pagination', $source);
        $this->assertStringNotContainsString("'posts_per_page' => 500", $source);
    }


    public function test_preview_without_category_target_warns_but_renders_preview(): void {
        $html = $this->renderPreviewForPool('category', "keyword,volume\nasian cam models,100\n");

        $this->assertStringContainsString('Target Category is required before saving category keywords.', $html);
        $this->assertStringContainsString('Preview Rows', $html);
    }

    public function test_preview_without_model_target_warns_but_renders_preview(): void {
        $html = $this->renderPreviewForPool('model', "keyword,volume\nabigail murray cam model,100\n");

        $this->assertStringContainsString('Target Model or Global Model Pool / All Models is required before saving model keywords.', $html);
        $this->assertStringContainsString('Preview Rows', $html);
    }

    public function test_schema_declares_keyword_candidate_target_source_columns_and_indexes(): void {
        $source = file_get_contents(__DIR__ . '/../includes/db/class-schema.php');
        $this->assertIsString($source);

        foreach ([
            'target_type VARCHAR(50) NULL',
            'target_id BIGINT(20) UNSIGNED NULL',
            'target_name VARCHAR(255) NULL',
            'target_slug VARCHAR(191) NULL',
            'source_batch VARCHAR(255) NULL',
            'source_file VARCHAR(255) NULL',
            'import_batch_id VARCHAR(64) NULL',
            'imported_at DATETIME NULL',
            'KEY target_pool (intent_type, target_type, target_id)',
            'KEY source_batch (source_batch)',
            'KEY import_batch_id (import_batch_id)',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
    }



    public function test_category_save_without_target_is_blocked(): void {
        $parser = new KeywordPoolCsvParser();
        $parserResult = $parser->parse_text("keyword,volume\nasian cam models,100\n");
        $payload = $this->signedSavePayload('category', $parserResult);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'tmwseo_keyword_pools_save_selected' => '1',
            'tmwseo_keyword_pool' => 'category',
            'tmwseo_keyword_pools_nonce' => 'test_nonce',
            'tmwseo_keyword_pools_save_payload' => $payload,
            'tmwseo_keyword_pool_selected_rows' => [ '2' ],
            'tmwseo_keyword_pool_save_mode' => 'approved',
        ];

        ob_start();
        KeywordPoolsAdminPage::render_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Target Category is required before saving category keywords.', $html);
        $this->assertStringNotContainsString('Import Result', $html);
    }

    public function test_model_save_without_target_is_blocked(): void {
        $parser = new KeywordPoolCsvParser();
        $parserResult = $parser->parse_text("keyword,volume\nabigail murray cam model,100\n");
        $payload = $this->signedSavePayload('model', $parserResult);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'tmwseo_keyword_pools_save_selected' => '1',
            'tmwseo_keyword_pool' => 'model',
            'tmwseo_keyword_pools_nonce' => 'test_nonce',
            'tmwseo_keyword_pools_save_payload' => $payload,
            'tmwseo_keyword_pool_selected_rows' => [ '2' ],
            'tmwseo_keyword_pool_save_mode' => 'approved',
        ];

        ob_start();
        KeywordPoolsAdminPage::render_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Target Model or Global Model Pool / All Models is required before saving model keywords.', $html);
        $this->assertStringNotContainsString('Import Result', $html);
    }

    private function signedSavePayload(string $pool, array $parserResult): string {
        $method = new ReflectionMethod(KeywordPoolsAdminPage::class, 'encode_signed_payload');
        $method->setAccessible(true);
        return (string) $method->invoke(null, [ 'pool' => $pool, 'parser_result' => $parserResult, 'generated_at' => time() ]);
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


    public function test_import_batch_volume_sort_header_toggles_and_shows_direction(): void {
        $method = new ReflectionMethod(KeywordPoolsAdminPage::class, 'volume_sort_header');
        $method->setAccessible(true);
        $batch = [ 'pool' => 'model', 'target_id' => 902 ];

        $defaultHeader = (string) $method->invoke(null, $batch, 44, 3, [ 'orderby' => '', 'order' => '' ]);
        $descHeader = (string) $method->invoke(null, $batch, 44, 3, [ 'orderby' => 'volume', 'order' => 'desc' ]);
        $ascHeader = (string) $method->invoke(null, $batch, 44, 3, [ 'orderby' => 'volume', 'order' => 'asc' ]);

        $this->assertStringContainsString('orderby=volume', $defaultHeader);
        $this->assertStringContainsString('order=desc', $defaultHeader);
        $this->assertStringContainsString('tmwseo_keyword_batch_page=3', $defaultHeader);
        $this->assertStringContainsString('Volume ↓', $descHeader);
        $this->assertStringContainsString('order=asc', $descHeader);
        $this->assertStringContainsString('Volume ↑', $ascHeader);
        $this->assertStringContainsString('order=desc', $ascHeader);
    }

    public function test_import_batch_volume_sort_header_preserves_search(): void {
        $method = new ReflectionMethod(KeywordPoolsAdminPage::class, 'volume_sort_header');
        $method->setAccessible(true);
        $header = (string) $method->invoke(null, [ 'pool' => 'model', 'target_id' => 902 ], 44, 1, [ 'orderby' => '', 'order' => '' ], 'classy');

        $this->assertStringContainsString('tmwseo_pool_search=classy', $header);
    }

    public function test_import_batch_sort_urls_preserve_global_model_pool_context(): void {
        $method = new ReflectionMethod(KeywordPoolsAdminPage::class, 'batch_view_query_args');
        $method->setAccessible(true);

        $args = $method->invoke(null, [
            'pool' => 'model',
            'target_type' => 'global',
            'target_id' => null,
            'target_slug' => 'global-model-pool',
            'target_name' => 'Global Model Pool',
        ], 55, 2, [ 'orderby' => 'volume', 'order' => 'desc' ]);

        $this->assertSame('model', $args['pool']);
        $this->assertSame('__global_model_pool__', $args['tmwseo_keyword_pools_target_id']);
        $this->assertSame(55, $args['tmwseo_keyword_batch_id']);
        $this->assertSame(2, $args['tmwseo_keyword_batch_page']);
        $this->assertSame('volume', $args['orderby']);
        $this->assertSame('desc', $args['order']);
    }


    public function test_import_batch_query_args_preserve_scoped_search(): void {
        $method = new ReflectionMethod(KeywordPoolsAdminPage::class, 'batch_view_query_args');
        $method->setAccessible(true);

        $args = $method->invoke(null, [ 'pool' => 'model', 'target_id' => 902 ], 55, 2, [ 'orderby' => 'volume', 'order' => 'desc' ], 'classy');

        $this->assertSame('tmwseo-keyword-pools', $args['page']);
        $this->assertSame('model', $args['pool']);
        $this->assertSame(55, $args['tmwseo_keyword_batch_id']);
        $this->assertSame('classy', $args['tmwseo_pool_search']);
        $this->assertSame('volume', $args['orderby']);
        $this->assertSame('desc', $args['order']);
    }

    public function test_import_batch_search_form_uses_search_keywords_wording_and_preserves_context(): void {
        $method = new ReflectionMethod(KeywordPoolsAdminPage::class, 'render_batch_search_form');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null, [ 'pool' => 'model', 'target_id' => 902 ], 55, [ 'orderby' => 'volume', 'order' => 'asc' ], 'live');
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('name="page" value="tmwseo-keyword-pools"', $html);
        $this->assertStringContainsString('name="pool" value="model"', $html);
        $this->assertStringContainsString('name="tmwseo_keyword_batch_id" value="55"', $html);
        $this->assertStringContainsString('name="orderby" value="volume"', $html);
        $this->assertStringContainsString('name="order" value="asc"', $html);
        $this->assertStringContainsString('name="tmwseo_pool_search" value="live"', $html);
        $this->assertStringContainsString('Search Keywords', $html);
        $this->assertStringNotContainsString('name="tmwseo_keyword_batch_page"', $html);
    }

    public function test_import_row_action_forms_preserve_volume_sort_and_page(): void {
        $method = new ReflectionMethod(KeywordPoolsAdminPage::class, 'import_row_action_forms');
        $method->setAccessible(true);

        $html = (string) $method->invoke(null, [ 'id' => 707 ], 4, [ 'orderby' => 'volume', 'order' => 'asc' ]);

        $this->assertStringContainsString('name="tmwseo_keyword_batch_page" value="4"', $html);
        $this->assertStringContainsString('name="orderby" value="volume"', $html);
        $this->assertStringContainsString('name="order" value="asc"', $html);
        $this->assertStringNotContainsString('tmwseo_pool_search', $html);
        $htmlWithSearch = (string) $method->invoke(null, [ 'id' => 707 ], 4, [ 'orderby' => 'volume', 'order' => 'asc' ], 'classy');
        $this->assertStringContainsString('name="tmwseo_pool_search" value="classy"', $htmlWithSearch);
        $this->assertStringContainsString('Approve', $html);
        $this->assertStringContainsString('Reject', $html);
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
