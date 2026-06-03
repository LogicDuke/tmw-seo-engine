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
use TMWSEO\Engine\Models\ModelEntityResolver;

require_once __DIR__ . '/../includes/keywords/class-keyword-pool-csv-parser.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-metrics-scorer.php';
require_once __DIR__ . '/../includes/keywords/class-model-keyword-strategy-classifier.php';
require_once __DIR__ . '/../includes/keywords/class-model-keyword-pool-classifier.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-dry-run-service.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-candidate-repository.php';
require_once __DIR__ . '/../includes/models/class-model-entity-resolver.php';
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

    public function test_save_selected_preserves_model_keyword_strategy_provenance(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp596_mod_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume,cpc,competition,SEO Score,model_name\nanisyia,12100,3.25,0.08,68,Anisyia\n", 'model');

        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'model', [2], 'auto');

        $this->assertSame(1, $result['summary']['inserted']);
        $this->assertStringContainsString('model_keyword_strategy', $wpdb->candidate_inserts[0]['data']['sources']);
        $this->assertStringContainsString('named_model_opportunity', $wpdb->candidate_inserts[0]['data']['sources']);
        $this->assertStringContainsString('approve_named_model_keyword', $wpdb->candidate_inserts[0]['data']['notes']);
    }

    public function test_save_selected_blocks_model_strategy_reject_and_defer_actions(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp596_blk_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume,cpc,competition,SEO Score\ncheapest sex cam sites,1200,2.50,0.10,75\nphoenix marie,1200,2.50,0.10,75\n", 'model');

        $result = $this->serviceWithModelPosts([ $this->modelPost(700, 'Anisyia', 'anisyia') ])->save_selected($dryRun, 'model', [2, 3], 'approved');

        $this->assertSame(2, $result['summary']['blocked'] + $result['summary']['skipped']);
        $this->assertSame([], $wpdb->candidate_inserts);
    }


    public function test_save_selected_preserves_personal_model_keyword_scope_provenance(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp598_mod_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("Keyword, Volume, SEO Score
anisyia,12100,68
anisyia livejasmin,1900,50
", 'model');

        $result = $this->serviceWithModelPosts([ $this->modelPost(700, 'Anisyia', 'anisyia') ])->save_selected($dryRun, 'model', [2, 3], 'approved');

        $this->assertSame(2, $result['summary']['inserted']);
        $sources = $wpdb->candidate_inserts[0]['data']['sources'];
        $notes = $wpdb->candidate_inserts[0]['data']['notes'];
        $this->assertStringContainsString('"model_keyword_owner":"anisyia"', $sources);
        $this->assertStringContainsString('"model_keyword_usage_scope":"model_bio_only"', $sources);
        $this->assertStringContainsString('"model_keyword_primary_candidate":"yes"', $sources);
        $this->assertStringContainsString('personal_model_keyword_csv', $sources);
        $this->assertStringContainsString('"model_keyword_owner":"anisyia"', $notes);
        $this->assertSame(700, $wpdb->candidate_inserts[0]['data']['entity_id']);
        $this->assertSame('model', $wpdb->candidate_inserts[0]['data']['entity_type']);
        $this->assertSame('approved', $wpdb->candidate_inserts[0]['data']['status']);
        $this->assertStringContainsString('model_entity_resolved', $sources);
    }

    public function test_save_selected_blocks_not_model_eligible_model_scope(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp598_not_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume
anisyia,12100
cheapest sex cam sites,1200
", 'model');

        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'model', [3], 'approved');

        $this->assertSame(1, $result['summary']['blocked']);
        $this->assertSame([], $wpdb->candidate_inserts);
    }


    public function test_save_selected_unresolved_personal_model_keyword_is_queued_not_approved_entity_zero(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp600_unresolved_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("Keyword, Volume, SEO Score
anisyia,12100,68
", 'model');

        $result = $this->serviceWithModelPosts([])->save_selected($dryRun, 'model', [2], 'approved');

        $this->assertSame(1, $result['summary']['inserted']);
        $this->assertSame('queued_for_review', $wpdb->candidate_inserts[0]['data']['status']);
        $this->assertSame(0, $wpdb->candidate_inserts[0]['data']['entity_id']);
        $this->assertStringContainsString('model_entity_not_found', $wpdb->candidate_inserts[0]['data']['sources']);
    }

    public function test_save_selected_ambiguous_personal_model_keyword_is_queued(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp600_ambiguous_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("Keyword, Volume, SEO Score
anisyia,12100,68
", 'model');

        $result = $this->serviceWithModelPosts([ $this->modelPost(701, 'Anisyia', 'anisyia'), $this->modelPost(702, 'Anisyia', 'anisyia-2') ])->save_selected($dryRun, 'model', [2], 'approved');

        $this->assertSame(1, $result['summary']['inserted']);
        $this->assertSame('queued_for_review', $wpdb->candidate_inserts[0]['data']['status']);
        $this->assertStringContainsString('model_entity_ambiguous', $wpdb->candidate_inserts[0]['data']['sources']);
    }

    public function test_full_reviewed_model_batch_saves_useful_non_footer_rows_with_entity_linking(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp600_full_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("Keyword, Volume, SEO Score
anisyia,12100,68
anisyia livejasmin,1900,50
livejasmin anisyia,170,35
anisyia cam,80,20
anisyia chat,70,20
anisyia com,60,20
anisyia live,50,20
anisyia porn,40,20
anisyia sex,30,20
anisyiaxxx,20,10
cheapest sex cam sites,1200,75
Total Volume,19950,
", 'model');

        $result = $this->serviceWithModelPosts([ $this->modelPost(703, 'Anisyia', 'anisyia') ])->save_full_reviewed_model_batch($dryRun);

        $this->assertSame(11, $result['summary']['inserted']);
        $this->assertSame(1, $result['summary']['blocked']);
        $keywords = array_column(array_column($wpdb->candidate_inserts, 'data'), 'keyword');
        $this->assertContains('anisyia', $keywords);
        $this->assertNotContains('total volume', $keywords);
        $statuses = [];
        foreach ($wpdb->candidate_inserts as $insert) {
            $statuses[$insert['data']['keyword']] = $insert['data']['status'];
            $this->assertSame(703, $insert['data']['entity_id']);
            $this->assertStringContainsString('personal_model_keyword_csv', $insert['data']['sources']);
        }
        $this->assertSame('approved', $statuses['anisyia']);
        $this->assertSame('approved', $statuses['anisyia livejasmin']);
        $this->assertSame('approved', $statuses['livejasmin anisyia']);
        $this->assertContains('queued_for_review', $statuses);
        $this->assertContains('rejected', $statuses);
    }


    public function test_full_batch_reuses_cached_owner_resolution(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp600_cache_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("Keyword, Volume, SEO Score
anisyia,12100,68
anisyia livejasmin,1900,50
livejasmin anisyia,170,35
anisyia cam,80,20
anisyia chat,70,20
anisyia com,60,20
anisyia live,50,20
anisyia porn,40,20
anisyia sex,30,20
anisyiaxxx,20,10
Total Volume,19950,
", 'model');
        $resolver = new KeywordPoolSaveSelectedCountingResolver([ $this->modelPost(704, 'Anisyia', 'anisyia') ]);

        $result = (new KeywordPoolSelectedImportService(null, $resolver))->save_full_reviewed_model_batch($dryRun);

        $this->assertSame(10, $result['summary']['inserted']);
        $this->assertSame(1, $resolver->resolve_calls);
        foreach ($wpdb->candidate_inserts as $insert) {
            $this->assertSame(704, $insert['data']['entity_id']);
        }
    }

    public function test_provided_model_entity_id_writes_resolution_provenance(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp600_provided_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume,cpc,competition,model_name,post_id
Lexy Ness webcam model,900,3.25,0.08,Lexy Ness,77
", 'model');

        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'model', [2], 'approved');

        $this->assertSame(1, $result['summary']['inserted']);
        $this->assertSame(77, $wpdb->candidate_inserts[0]['data']['entity_id']);
        $sources = $wpdb->candidate_inserts[0]['data']['sources'];
        $this->assertStringContainsString('model_entity_resolution', $sources);
        $this->assertStringContainsString('provided_entity_id', $sources);
        $this->assertStringContainsString('model_match_provided_entity_id', $sources);
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


    public function test_full_category_batch_saves_valid_accept_category_rows_and_blocks_footer(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp590_cat_batch_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume,cpc,competition,SEO Score
asian cam models,18100,5.99,0.02,91
Total Volume,18100,,
", 'category');

        $result = (new KeywordPoolSelectedImportService())->save_full_reviewed_category_batch($dryRun);

        $this->assertSame(2, $result['summary']['selected']);
        $this->assertSame(1, $result['summary']['inserted']);
        $this->assertSame(1, $result['summary']['blocked']);
        $this->assertSame('category', $wpdb->candidate_inserts[0]['data']['intent_type']);
        $this->assertSame('approved', $wpdb->candidate_inserts[0]['data']['status']);
        $this->assertSame('blocked_summary_or_footer_row', $result['rows'][1]['reason']);
    }

    public function test_full_category_batch_does_not_auto_approve_review_required_rows(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp590_cat_review_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume,cpc,competition,SEO Score
big boob cam,1200,2.50,0.10,75
", 'category');

        $result = (new KeywordPoolSelectedImportService())->save_full_reviewed_category_batch($dryRun);

        $this->assertSame(1, $result['summary']['blocked']);
        $this->assertSame([], $wpdb->candidate_inserts);
        $this->assertStringContainsString('blocked_validation_state_review_required', $result['rows'][0]['reason']);
    }

    public function test_full_category_batch_only_writes_keyword_candidates_not_seo_content_or_indexing(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp590_cat_safe_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume,cpc,competition,SEO Score
asian cam models,18100,5.99,0.02,91
", 'category');

        (new KeywordPoolSelectedImportService())->save_full_reviewed_category_batch($dryRun);
        $queries = implode("
", $wpdb->queries);

        $this->assertStringContainsString($wpdb->prefix . 'tmw_keyword_candidates', $queries);
        $this->assertStringNotContainsString('postmeta', $queries);
        $this->assertStringNotContainsString('rank_math', $queries);
        $this->assertStringNotContainsString('post_content', $queries);
        $this->assertStringNotContainsString('slug', strtolower($queries));
        $this->assertStringNotContainsString('noindex', strtolower($queries));
        $this->assertStringNotContainsString('generate', strtolower($queries));
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


    public function test_save_selected_blocks_model_pool_footer_row_even_if_selected(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp599_footer_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("Keyword, Volume, SEO Score
anisyia,12100,68
Total Volume,19950,
", 'model');

        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'model', [3], 'approved');

        $this->assertSame(1, $result['summary']['blocked']);
        $this->assertSame([], $wpdb->candidate_inserts);
        $this->assertSame('blocked_summary_or_footer_row', $result['rows'][0]['reason']);
        $this->assertContains('summary_or_footer_row', $dryRun['rows'][1]['reason_codes']);
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



    public function test_existing_sources_and_notes_are_preserved_and_merged_on_update(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp590_merge_', true, null, [
            'id' => 55,
            'keyword' => 'asian cam models',
            'intent_type' => 'category',
            'entity_type' => 'category',
            'entity_id' => 0,
            'status' => 'queued_for_review',
            'sources' => '{"manual_source":"curated","reason_codes":["existing_reason"]}',
            'notes' => '{"manual_note":"keep this","unknown_key":{"nested":true},"warnings":["existing_warning"],"keyword_pools_import":{"pool":"category","priority_preview":"P2"}}',
        ]);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume,cpc,competition\nasian cam models,18100,5.99,0.02\n", 'category');

        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'category', [2], 'approved');

        $this->assertSame(1, $result['summary']['updated']);
        $this->assertCount(1, $wpdb->candidate_updates);
        $sources = json_decode($wpdb->candidate_updates[0]['data']['sources'], true);
        $notes = json_decode($wpdb->candidate_updates[0]['data']['notes'], true);
        $this->assertSame('curated', $sources['manual_source']);
        $this->assertSame('keep this', $notes['manual_note']);
        $this->assertSame(['nested' => true], $notes['unknown_key']);
        $this->assertContains('existing_warning', $notes['warnings']);
        $this->assertTrue($notes['keyword_pools_import']['imported_from_keyword_pools']);
        $this->assertNotEmpty($notes['keyword_pools_import_history']);
    }

    public function test_existing_global_keyword_row_is_not_claimed_by_entity_scoped_save(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp590_global_conflict_', true, null, [
            'id' => 56,
            'keyword' => 'asian cam models',
            'intent_type' => 'category',
            'entity_type' => 'category',
            'entity_id' => 0,
            'status' => 'queued_for_review',
        ]);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume,cpc,competition\nasian cam models,18100,5.99,0.02\n", 'category');
        $dryRun['rows'][0]['entity_id'] = 123;

        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'category', [2], 'approved');

        $this->assertSame(1, $result['summary']['conflicts']);
        $this->assertSame('conflict', $result['rows'][0]['action']);
        $this->assertSame(123, $result['rows'][0]['entity_id']);
        $this->assertSame([], $wpdb->candidate_inserts);
        $this->assertSame([], $wpdb->candidate_updates);
    }

    public function test_category_pool_uses_entity_id_instead_of_post_id(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp590_cat_entity_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume,cpc,competition,post_id\nasian cam models,18100,5.99,0.02,999\n", 'category');
        $dryRun['rows'][0]['entity_id'] = 55;

        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'category', [2], 'approved');

        $this->assertSame(1, $result['summary']['inserted']);
        $this->assertSame(55, $wpdb->candidate_inserts[0]['data']['entity_id']);
        $this->assertSame(55, $result['rows'][0]['entity_id']);
    }

    public function test_category_pool_without_entity_id_saves_as_global_even_with_post_id(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp590_cat_global_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume,cpc,competition,post_id\nasian cam models,18100,5.99,0.02,999\n", 'category');

        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'category', [2], 'approved');

        $this->assertSame(1, $result['summary']['inserted']);
        $this->assertSame(0, $wpdb->candidate_inserts[0]['data']['entity_id']);
        $this->assertSame(0, $result['rows'][0]['entity_id']);
    }

    public function test_model_and_video_pools_prefer_post_id_over_entity_id(): void {
        $modelWpdb = new KeywordPoolSaveSelectedFakeWpdb('wp590_model_post_', true);
        $GLOBALS['wpdb'] = $modelWpdb;
        $modelDryRun = $this->dryRun("keyword,volume,cpc,competition,model_name,post_id\nLexy Ness webcam model,900,3.25,0.08,Lexy Ness,77\n", 'model');
        $modelDryRun['rows'][0]['entity_id'] = 88;

        $modelResult = (new KeywordPoolSelectedImportService())->save_selected($modelDryRun, 'model', [2], 'approved');
        $this->assertSame(1, $modelResult['summary']['inserted']);
        $this->assertSame(77, $modelWpdb->candidate_inserts[0]['data']['entity_id']);
        $this->assertSame(77, $modelResult['rows'][0]['entity_id']);

        $videoWpdb = new KeywordPoolSaveSelectedFakeWpdb('wp590_video_post_', true);
        $GLOBALS['wpdb'] = $videoWpdb;
        $videoDryRun = $this->dryRun("keyword,volume,cpc,competition,model_name,post_id\nLexy Ness webcam video,1200,2.50,0.1,Lexy Ness,66\n", 'video');
        $videoDryRun['rows'][0]['entity_id'] = 99;

        $videoResult = (new KeywordPoolSelectedImportService())->save_selected($videoDryRun, 'video', [2], 'approved');
        $this->assertSame(1, $videoResult['summary']['inserted']);
        $this->assertSame(66, $videoWpdb->candidate_inserts[0]['data']['entity_id']);
        $this->assertSame(66, $videoResult['rows'][0]['entity_id']);
    }


    public function test_save_selected_skips_tmw_deferred_big_performer_without_error(): void {
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp591_defer_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume,cpc,competition,SEO Score
dani daniels,1200,2.50,0.10,75
", 'model');

        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'model', [2], 'approved');

        $this->assertSame(1, $result['summary']['skipped']);
        $this->assertSame('defer_until_lj_50_model_milestone', $result['rows'][0]['reason']);
        $this->assertSame([], $wpdb->candidate_inserts);
    }

    public function test_tmw_p1_rows_are_save_eligible_and_archive_rows_are_not(): void {
        $service = new KeywordPoolSelectedImportService();
        $dryRun = $this->dryRun("keyword,volume,cpc,competition,SEO Score
webcam models,1200,2.50,0.10,75
free video chat,1200,2.50,0.10,75
", 'category');

        $this->assertTrue($service->is_row_eligible($dryRun['rows'][0], 'category'));
        $this->assertFalse($service->is_row_eligible($dryRun['rows'][1], 'category'));
    }


    public function test_full_batch_updates_existing_unlinked_personal_model_keyword_row(): void {
        $existing = [
            'id' => 601,
            'keyword' => 'anisyia',
            'intent_type' => 'model',
            'entity_type' => 'model',
            'entity_id' => 0,
            'status' => 'approved',
            'volume' => 12100,
            'sources' => '{"personal_model_keyword_csv":true,"model_keyword_owner":"anisyia","model_keyword_usage_scope":"model_bio_only","model_keyword_primary_candidate":"yes"}',
            'notes' => '{"manual_note":"keep"}',
        ];
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp601_upsert_', true, null, $existing);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("Keyword, Volume, SEO Score
anisyia,12100,68
", 'model');

        $result = $this->serviceWithModelPosts([ $this->modelPost(4457, 'Anisyia', 'anisyia') ])->save_full_reviewed_model_batch($dryRun);

        $this->assertSame(1, $result['summary']['updated']);
        $this->assertSame([], $wpdb->candidate_inserts);
        $this->assertSame(4457, $wpdb->candidate_updates[0]['data']['entity_id']);
        $this->assertSame('approved', $wpdb->candidate_updates[0]['data']['status']);
        $this->assertStringContainsString('model_entity_resolved', $wpdb->candidate_updates[0]['data']['sources']);
        $this->assertStringContainsString('keep', $wpdb->candidate_updates[0]['data']['notes']);
    }

    public function test_full_batch_does_not_overwrite_keyword_linked_to_different_model(): void {
        $existing = [
            'id' => 602,
            'keyword' => 'anisyia',
            'intent_type' => 'model',
            'entity_type' => 'model',
            'entity_id' => 9999,
            'status' => 'approved',
            'sources' => '{"personal_model_keyword_csv":true,"model_keyword_owner":"othermodel","model_keyword_usage_scope":"model_bio_only"}',
            'notes' => '{}',
        ];
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp601_conflict_', true, null, $existing);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("Keyword, Volume, SEO Score
anisyia,12100,68
", 'model');

        $result = $this->serviceWithModelPosts([ $this->modelPost(4457, 'Anisyia', 'anisyia') ])->save_full_reviewed_model_batch($dryRun);

        $this->assertSame(1, $result['summary']['conflicts']);
        $this->assertSame('conflict', $result['rows'][0]['action']);
        $this->assertStringContainsString('keyword_owner_conflict', $result['rows'][0]['reason']);
        $this->assertSame([], $wpdb->candidate_inserts);
        $this->assertSame([], $wpdb->candidate_updates);
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


    public function test_category_save_with_valid_target_context_persists_ownership_and_source_fields(): void {
        $columns = $this->keywordCandidateColumnsWithTargetOwnership();
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp_target_cat_', true, $columns);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume,cpc,competition,SEO Score\nbig boob cam,18100,5.99,0.02,91\n", 'category');

        $context = [
            'target_type' => 'category_page',
            'target_id' => 301,
            'target_name' => 'Big Boob Cam',
            'target_slug' => 'big-boob-cam',
            'source_batch' => 'Big Boob Cam priority upload',
            'source_file' => 'Big_Boob_Cam_35_priority_keywords.csv',
        ];
        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'category', [2], 'approved', $context);

        $this->assertSame(1, $result['summary']['inserted']);
        $data = $wpdb->candidate_inserts[0]['data'];
        $this->assertSame('category_page', $data['target_type']);
        $this->assertSame(301, $data['target_id']);
        $this->assertSame('Big Boob Cam', $data['target_name']);
        $this->assertSame('big-boob-cam', $data['target_slug']);
        $this->assertSame('Big Boob Cam priority upload', $data['source_batch']);
        $this->assertSame('Big_Boob_Cam_35_priority_keywords.csv', $data['source_file']);
        $this->assertNotEmpty($data['import_batch_id']);
        $this->assertNotEmpty($data['imported_at']);
    }

    public function test_model_save_with_valid_target_context_persists_ownership_and_source_fields(): void {
        $columns = $this->keywordCandidateColumnsWithTargetOwnership();
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp_target_model_', true, $columns);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume,cpc,competition,model_name\ngabriela hadid cam model,900,3.25,0.08,Gabriela Hadid\n", 'model');

        $context = [
            'target_type' => 'model',
            'target_id' => 902,
            'target_name' => 'Gabriela Hadid',
            'target_slug' => 'gabriela-hadid',
            'source_batch' => 'Gabriela manual batch',
            'source_file' => 'gabriela.csv',
        ];
        $result = (new KeywordPoolSelectedImportService(null, new ModelEntityResolver(static fn() => [])))->save_selected($dryRun, 'model', [2], 'approved', $context);

        $this->assertSame(1, $result['summary']['inserted']);
        $data = $wpdb->candidate_inserts[0]['data'];
        $this->assertSame('model', $data['target_type']);
        $this->assertSame(902, $data['target_id']);
        $this->assertSame('Gabriela Hadid', $data['target_name']);
        $this->assertSame('gabriela-hadid', $data['target_slug']);
        $this->assertSame('Gabriela manual batch', $data['source_batch']);
        $this->assertSame('gabriela.csv', $data['source_file']);
        $this->assertNotEmpty($data['import_batch_id']);
        $this->assertNotEmpty($data['imported_at']);
    }

    public function test_duplicate_keyword_with_different_target_does_not_overwrite_ownership(): void {
        $columns = $this->keywordCandidateColumnsWithTargetOwnership();
        $existing = [
            'id' => 55,
            'keyword' => 'latina cam models',
            'intent_type' => 'category',
            'entity_type' => 'category',
            'entity_id' => 0,
            'target_type' => 'category_page',
            'target_id' => 111,
            'target_name' => 'Latina Cam Models',
            'target_slug' => 'latina-cam-models',
            'status' => 'approved',
            'sources' => '{}',
            'notes' => '{}',
        ];
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp_target_conflict_', true, $columns, $existing);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume\nlatina cam models,100\n", 'category');

        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'category', [2], 'approved', [
            'target_type' => 'category_page',
            'target_id' => 222,
            'target_name' => 'Different Category',
            'target_slug' => 'different-category',
        ]);

        $this->assertSame(1, $result['summary']['conflicts']);
        $this->assertSame([], $wpdb->candidate_updates);
        $this->assertSame('existing_keyword_has_different_target', $result['rows'][0]['reason']);
        $this->assertSame('Latina Cam Models', $result['rows'][0]['existing_target_name']);
        $this->assertSame('approved', $result['rows'][0]['status']);
    }

    public function test_existing_rows_with_null_target_fields_attach_selected_target_and_preserve_status_on_auto(): void {
        $columns = $this->keywordCandidateColumnsWithTargetOwnership();
        $existing = [
            'id' => 56,
            'keyword' => 'unassigned category keyword',
            'intent_type' => 'category',
            'entity_type' => 'category',
            'entity_id' => 0,
            'target_type' => null,
            'target_id' => null,
            'status' => 'queued_for_review',
            'sources' => '{"legacy":"source"}',
            'notes' => '{}',
        ];
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp_target_null_', true, $columns, $existing);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume\nunassigned category keyword,100\n", 'category');

        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'category', [2], 'auto', [
            'target_type' => 'category_page',
            'target_id' => 333,
            'target_name' => 'New Owner',
            'target_slug' => 'new-owner',
            'source_batch' => 'new owner batch',
            'source_file' => 'new-owner.csv',
            'import_batch_id' => 'batch-333',
            'imported_at' => '2026-06-02 00:00:00',
        ]);

        $this->assertSame(1, $result['summary']['updated']);
        $this->assertSame([], $wpdb->candidate_inserts);
        $data = $wpdb->candidate_updates[0]['data'];
        $this->assertSame('category_page', $data['target_type']);
        $this->assertSame(333, $data['target_id']);
        $this->assertSame('New Owner', $data['target_name']);
        $this->assertSame('new-owner', $data['target_slug']);
        $this->assertSame('queued_for_review', $data['status']);
        $this->assertSame('new owner batch', $data['source_batch']);
        $this->assertSame('new-owner.csv', $data['source_file']);
        $this->assertSame('batch-333', $data['import_batch_id']);
        $this->assertStringContainsString('keyword_pools_import_history', $data['sources']);
        $this->assertStringContainsString('new owner batch', $data['sources']);
    }

    public function test_existing_same_target_refresh_preserves_existing_rejected_status_on_auto(): void {
        $columns = $this->keywordCandidateColumnsWithTargetOwnership();
        $existing = [
            'id' => 57,
            'keyword' => 'same target keyword',
            'intent_type' => 'category',
            'entity_type' => 'category',
            'entity_id' => 0,
            'target_type' => 'category_page',
            'target_id' => 444,
            'target_name' => 'Same Target',
            'target_slug' => 'same-target',
            'status' => 'rejected',
            'sources' => '{}',
            'notes' => '{}',
        ];
        $wpdb = new KeywordPoolSaveSelectedFakeWpdb('wp_target_same_', true, $columns, $existing);
        $GLOBALS['wpdb'] = $wpdb;
        $dryRun = $this->dryRun("keyword,volume\nsame target keyword,100\n", 'category');

        $result = (new KeywordPoolSelectedImportService())->save_selected($dryRun, 'category', [2], 'auto', [
            'target_type' => 'category_page',
            'target_id' => 444,
            'target_name' => 'Same Target',
            'target_slug' => 'same-target',
        ]);

        $this->assertSame(1, $result['summary']['updated']);
        $this->assertSame('rejected', $wpdb->candidate_updates[0]['data']['status']);
    }


    private function keywordCandidateColumnsWithTargetOwnership(): array {
        return [
            'id', 'keyword', 'canonical', 'status', 'intent', 'intent_type', 'entity_type', 'entity_id',
            'target_type', 'target_id', 'target_name', 'target_slug', 'source_batch', 'source_file', 'import_batch_id', 'imported_at',
            'volume', 'cpc', 'difficulty', 'competition', 'opportunity', 'seo_score', 'traffic_value',
            'trend', 'ad_difficulty', 'difficulty_proxy', 'sources', 'notes', 'created_at', 'updated_at',
        ];
    }

    private function serviceWithModelPosts(array $posts): KeywordPoolSelectedImportService {
        return new KeywordPoolSelectedImportService(null, new ModelEntityResolver(static fn() => $posts));
    }

    private function modelPost(int $id, string $title, string $slug): object {
        return (object) [ 'ID' => $id, 'post_title' => $title, 'post_name' => $slug, 'post_type' => 'model' ];
    }

    private function dryRun(string $csv, string $pool): array {
        $parsed = (new KeywordPoolCsvParser())->parse_text($csv);
        return (new KeywordPoolDryRunService())->dry_run($parsed, $pool);
    }
}

final class KeywordPoolSaveSelectedCountingResolver extends ModelEntityResolver {
    public int $resolve_calls = 0;
    private array $posts;

    public function __construct(array $posts) {
        parent::__construct(static fn() => []);
        $this->posts = $posts;
    }

    public function resolve(string $owner): array {
        $this->resolve_calls++;
        return (new ModelEntityResolver(fn() => $this->posts))->resolve($owner);
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
