<?php
/**
 * Tests for shared keyword pool dry-run previews.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordPoolCsvParser;
use TMWSEO\Engine\Keywords\KeywordPoolDryRunService;

require_once __DIR__ . '/../includes/keywords/class-keyword-pool-csv-parser.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-metrics-scorer.php';
require_once __DIR__ . '/../includes/keywords/class-model-keyword-strategy-classifier.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-dry-run-service.php';

class KeywordPoolDryRunServiceTest extends TestCase {

    private function parse(string $csv): array {
        return (new KeywordPoolCsvParser())->parse_text($csv);
    }

    public function test_model_pool_appends_model_keyword_strategy_fields(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume,SEO Score,model_name\nanisyia,12100,68,Anisyia\nanisyia livejasmin,1900,50,Anisyia\nlivejasmin anisyia,170,35,Anisyia\nanisyia webcam model,,,Anisyia\ncheapest sex cam sites,1000,75,\n"), 'model');

        $this->assertSame('named_model_opportunity', $result['rows'][0]['model_keyword_strategy']);
        $this->assertSame('lj_named_model_opportunity', $result['rows'][1]['model_keyword_strategy']);
        $this->assertSame('lj_named_model_opportunity', $result['rows'][2]['model_keyword_strategy']);
        $this->assertSame('fallback_model_intent', $result['rows'][3]['model_keyword_strategy']);
        $this->assertSame('not_model_intent', $result['rows'][4]['model_keyword_strategy']);
        $this->assertSame('reject', $result['rows'][4]['decision']);
    }

    public function test_video_and_category_pools_do_not_receive_named_model_strategy(): void {
        $category = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume,model_name\nanisyia,12100,Anisyia\n"), 'category');
        $video = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume,model_name\nanisyia,12100,Anisyia\nanisyia webcam video,1200,Anisyia\n"), 'video');

        $this->assertSame('not_applicable', $category['rows'][0]['model_keyword_strategy']);
        $this->assertSame('not_applicable', $video['rows'][0]['model_keyword_strategy']);
        $this->assertSame('reject', $video['rows'][0]['decision']);
        $this->assertSame('not_applicable', $video['rows'][1]['model_keyword_strategy']);
        $this->assertSame('accept', $video['rows'][1]['decision']);
    }

    public function test_empty_keyword_rejection(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume\n,10\n"), 'model');
        $row    = $result['rows'][0];

        $this->assertSame('invalid', $row['validation_state']);
        $this->assertSame('reject', $row['decision']);
        $this->assertContains('missing_keyword', $row['reason_codes']);
    }

    public function test_metric_normalization_and_invalid_numeric_reason(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume,difficulty,cpc,competition\nLexy Ness webcam model,\"1,200\",33.5,\$0.42,bad\n"), 'model');
        $row    = $result['rows'][0];

        $this->assertSame(1200, $row['volume']);
        $this->assertSame(33.5, $row['difficulty']);
        $this->assertSame(0.42, $row['cpc']);
        $this->assertNull($row['competition']);
        $this->assertContains('invalid_competition', $row['reason_codes']);
    }


    public function test_blank_ad_difficulty_does_not_add_invalid_warning(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,Ad Difficulty\nasian cam models,\n"), 'category');
        $row    = $result['rows'][0];

        $this->assertNull($row['ad_difficulty']);
        $this->assertNotContains('invalid_ad_difficulty', $row['reason_codes']);
    }

    public function test_missing_ad_difficulty_does_not_add_invalid_warning(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword\nasian cam models\n"), 'category');
        $row    = $result['rows'][0];

        $this->assertNull($row['ad_difficulty']);
        $this->assertNotContains('invalid_ad_difficulty', $row['reason_codes']);
    }

    public function test_whitespace_ad_difficulty_does_not_add_invalid_warning(): void {
        $result = (new KeywordPoolDryRunService())->dry_run([ [ 'keyword' => 'asian cam models', 'ad_difficulty' => " \t\n\xc2\xa0 " ] ], 'category');
        $row    = $result['rows'][0];

        $this->assertNull($row['ad_difficulty']);
        $this->assertNotContains('invalid_ad_difficulty', $row['reason_codes']);
    }

    public function test_nan_ad_difficulty_blank_does_not_add_invalid_warning(): void {
        $result = (new KeywordPoolDryRunService())->dry_run([ [ 'keyword' => 'asian cam models', 'ad_difficulty' => NAN ] ], 'category');
        $row    = $result['rows'][0];

        $this->assertNull($row['ad_difficulty']);
        $this->assertNotContains('invalid_ad_difficulty', $row['reason_codes']);
    }

    public function test_non_numeric_ad_difficulty_adds_invalid_warning(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,Ad Difficulty\nasian cam models,abc\n"), 'category');
        $row    = $result['rows'][0];

        $this->assertNull($row['ad_difficulty']);
        $this->assertContains('invalid_ad_difficulty', $row['reason_codes']);
    }

    public function test_numeric_ad_difficulty_is_accepted_without_invalid_warning(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,Ad Difficulty\nasian cam models,17.5\n"), 'category');
        $row    = $result['rows'][0];

        $this->assertSame(17.5, $row['ad_difficulty']);
        $this->assertNotContains('invalid_ad_difficulty', $row['reason_codes']);
    }

    public function test_status_normalization_and_unknown_status_fallback(): void {
        $service = new KeywordPoolDryRunService();
        $known   = $service->dry_run($this->parse("keyword,status\nLexy Ness webcam model,queued for review\n"), 'model');
        $unknown = $service->dry_run($this->parse("keyword,status\nLexy Ness webcam model,imported\n"), 'model');

        $this->assertSame('queued_for_review', $known['rows'][0]['status_preview']);
        $this->assertSame('new', $unknown['rows'][0]['status_preview']);
        $this->assertContains('unknown_status_defaulted_to_new', $unknown['rows'][0]['reason_codes']);
    }

    public function test_duplicate_detection_within_upload(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword\nLexy Ness webcam video\nlexy   ness webcam video\n"), 'video');

        $this->assertFalse($result['rows'][0]['is_duplicate_in_upload']);
        $this->assertTrue($result['rows'][1]['is_duplicate_in_upload']);
        $this->assertSame($result['rows'][0]['row_number'], $result['rows'][1]['duplicate_of_row']);
        $this->assertContains('duplicate_in_upload', $result['rows'][1]['reason_codes']);
    }

    public function test_video_pool_rejects_standalone_model_name(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,model_name\nLexy Ness,Lexy Ness\n"), 'video');
        $row    = $result['rows'][0];

        $this->assertSame('invalid', $row['validation_state']);
        $this->assertSame('reject', $row['decision']);
        $this->assertContains('standalone_model_name', $row['reason_codes']);
    }

    public function test_video_pool_accepts_model_name_led_video_phrase(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,model_name\nLexy Ness webcam video,Lexy Ness\n"), 'video');
        $row    = $result['rows'][0];

        $this->assertSame('valid', $row['validation_state']);
        $this->assertSame('accept', $row['decision']);
        $this->assertContains('video_intent_detected', $row['reason_codes']);
    }

    public function test_category_pool_accepts_category_safe_phrase(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword\nblonde webcam models\n"), 'category');
        $row    = $result['rows'][0];

        $this->assertSame('valid', $row['validation_state']);
        $this->assertSame('accept', $row['decision']);
        $this->assertContains('category_intent_detected', $row['reason_codes']);
    }

    public function test_model_pool_accepts_model_safe_phrase(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,model_name\nLexy Ness webcam model,Lexy Ness\n"), 'model');
        $row    = $result['rows'][0];

        $this->assertSame('valid', $row['validation_state']);
        $this->assertSame('accept', $row['decision']);
        $this->assertContains('model_entity_detected', $row['reason_codes']);
    }


    public function test_dry_run_appends_tmw_score_fields_for_phase_1_rows(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume,cpc,competition,SEO Score,Traffic Value
webcam models,1200,2.50,0.10,75,840
"), 'category');
        $row = $result['rows'][0];

        $this->assertArrayHasKey('tmw_score', $row);
        $this->assertSame('TMW-P1', $row['tmw_priority']);
        $this->assertSame('ready_for_phase_1_review', $row['tmw_indexing_readiness']);
        $this->assertSame('approve_for_phase_1', $row['tmw_recommended_action']);
    }

    public function test_dry_run_kwe_opportunity_without_cpc_is_not_golden_but_scores(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume,competition,SEO Score
livejasmin models,1200,0.10,75
"), 'model');
        $row = $result['rows'][0];

        $this->assertTrue($row['kwe_opportunity_candidate']);
        $this->assertFalse($row['is_golden_keyword']);
        $this->assertGreaterThan(0, $row['tmw_score']);
    }

    public function test_dry_run_defers_big_performer_model_terms(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume,cpc,competition,SEO Score
dani daniels,1200,2.50,0.10,75
natasha nice,1200,2.50,0.10,75
"), 'model');

        $this->assertSame('defer_until_lj_50_model_milestone', $result['rows'][0]['tmw_indexing_readiness']);
        $this->assertSame('defer_until_lj_50_model_milestone', $result['rows'][1]['tmw_indexing_readiness']);
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function summary_footer_keyword_provider(): array {
        return [
            [ 'Total Volume' ],
            [ 'Grand Total' ],
            [ 'Subtotal' ],
            [ 'Summary' ],
            [ 'Average' ],
            [ 'Avg' ],
            [ 'Showing 1-10 of 20' ],
            [ 'Keyword' ],
            [ 'Volume' ],
        ];
    }

    /**
     * @dataProvider summary_footer_keyword_provider
     */
    public function test_summary_footer_rows_are_blocked(string $keyword): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume
{$keyword},704750
"), 'category');
        $row    = $result['rows'][0];

        $this->assertSame('blocked', $row['validation_state']);
        $this->assertSame('block', $row['decision']);
        $this->assertContains('summary_or_footer_row', $row['reason_codes']);
    }

    public function test_category_pool_keeps_real_webcam_model_keywords_valid(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume
webcam models,1200
top webcam models,900
"), 'category');

        $this->assertSame('valid', $result['rows'][0]['validation_state']);
        $this->assertSame('accept', $result['rows'][0]['decision']);
        $this->assertContains('category_intent_detected', $result['rows'][0]['reason_codes']);
        $this->assertSame('valid', $result['rows'][1]['validation_state']);
        $this->assertSame('accept', $result['rows'][1]['decision']);
        $this->assertContains('category_intent_detected', $result['rows'][1]['reason_codes']);
    }

    public function test_footer_rows_are_not_counted_as_valid_accepts(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume
webcam models,1200
Total Volume,704750
"), 'category');

        $this->assertSame(1, $result['summary']['accepted']);
        $this->assertSame(1, $result['summary']['blocked']);
        $this->assertSame('blocked', $result['rows'][1]['validation_state']);
        $this->assertSame('block', $result['rows'][1]['decision']);
        $this->assertContains('summary_or_footer_row', $result['rows'][1]['reason_codes']);
    }


    public function test_priority_scoring_marks_high_volume_category_keywords_p1_and_golden(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume,cpc,competition\nasian cam models,18100,5.99,0.02\nlivejasmin models,3600,3.11,0.02\n"), 'category');

        foreach ($result['rows'] as $row) {
            $this->assertSame('valid', $row['validation_state']);
            $this->assertSame('accept', $row['decision']);
            $this->assertSame('P1', $row['priority_preview']);
            $this->assertTrue($row['is_golden_keyword']);
            $this->assertSame([], $row['golden_missing_reasons']);
            $this->assertSame('volume>=500, competition<0.20, cpc>=2.00', $row['golden_formula_summary']);
            $this->assertSame('approve_candidate', $row['recommended_action']);
        }
    }

    public function test_cam2cam_high_cpc_is_p1_but_not_golden_below_volume_threshold(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume,cpc,competition\ncam2cam shows,170,6.61,0.03\n"), 'category');
        $row    = $result['rows'][0];

        $this->assertSame('valid', $row['validation_state']);
        $this->assertSame('accept', $row['decision']);
        $this->assertSame('P1', $row['priority_preview']);
        $this->assertFalse($row['is_golden_keyword']);
        $this->assertContains('volume_below_500', $row['golden_missing_reasons']);
        $this->assertGreaterThanOrEqual(80, $row['commercial_score_preview']);
    }


    public function test_golden_diagnostics_explain_missing_cpc(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume,competition\nasian cam models,18100,0.02\n"), 'category');
        $row    = $result['rows'][0];

        $this->assertSame('P1', $row['priority_preview']);
        $this->assertFalse($row['is_golden_keyword']);
        $this->assertContains('missing_cpc', $row['golden_missing_reasons']);
        $this->assertSame('volume>=500, competition<0.20, cpc>=2.00', $row['golden_formula_summary']);
    }

    public function test_golden_diagnostics_explain_high_competition(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume,cpc,competition\nasian cam models,18100,5.99,0.22\n"), 'category');
        $row    = $result['rows'][0];

        $this->assertFalse($row['is_golden_keyword']);
        $this->assertContains('competition_not_below_0_20', $row['golden_missing_reasons']);
    }

    public function test_golden_diagnostics_explain_low_volume(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume,cpc,competition\nasian cam models,390,5.99,0.02\n"), 'category');
        $row    = $result['rows'][0];

        $this->assertFalse($row['is_golden_keyword']);
        $this->assertContains('volume_below_500', $row['golden_missing_reasons']);
    }

    public function test_archive_and_safety_rules_block_do_not_test_keywords(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume,cpc,competition\nschoolgirl roleplay,0,,\nspy cam shows,10,1,0.5\nfree video chat,1000,0.1,0.5\nwebcam models near me,500,2,0.1\n"), 'category');
        $rows   = $result['rows'];

        $this->assertSame('blocked', $rows[0]['validation_state']);
        $this->assertSame('block', $rows[0]['decision']);
        $this->assertSame('Archive', $rows[0]['priority_preview']);
        $this->assertSame('block_candidate', $rows[0]['recommended_action']);
        $this->assertNotContains('archive_keyword', $rows[0]['reason_codes']);
        $this->assertContains('unsafe_keyword', $rows[0]['reason_codes']);
        $this->assertContains('rename_recommended', $rows[0]['reason_codes']);
        $this->assertStringContainsString('Use "uniform roleplay cam girls" instead.', $rows[0]['reason_summary']);

        $this->assertSame('blocked', $rows[1]['validation_state']);
        $this->assertSame('Archive', $rows[1]['priority_preview']);
        $this->assertContains('unsafe_keyword', $rows[1]['reason_codes']);

        $this->assertSame('review_required', $rows[2]['validation_state']);
        $this->assertSame('TMW-Archive', $rows[2]['tmw_priority']);
        $this->assertContains('broad_non_tmw_chat_intent', $rows[2]['reason_codes']);
        $this->assertNotContains('too_broad_low_commercial_intent', $rows[2]['reason_codes']);

        $this->assertSame('blocked', $rows[3]['validation_state']);
        $this->assertSame('Archive', $rows[3]['priority_preview']);
        $this->assertContains('geo_local_intent', $rows[3]['reason_codes']);
        $this->assertFalse($rows[3]['is_golden_keyword']);
    }

    public function test_footer_rows_have_archive_priority_and_block_action(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume\nTotal Volume,704750\n"), 'category');
        $row    = $result['rows'][0];

        $this->assertSame('blocked', $row['validation_state']);
        $this->assertSame('block', $row['decision']);
        $this->assertSame('Archive', $row['priority_preview']);
        $this->assertSame('block_candidate', $row['recommended_action']);
        $this->assertContains('summary_or_footer_row', $row['reason_codes']);
    }

    public function test_no_db_rank_math_or_post_content_writes_are_present(): void {
        $parserSource = file_get_contents(__DIR__ . '/../includes/keywords/class-keyword-pool-csv-parser.php');
        $dryRunSource = file_get_contents(__DIR__ . '/../includes/keywords/class-keyword-pool-dry-run-service.php');
        $strategySource = file_get_contents(__DIR__ . '/../includes/keywords/class-model-keyword-strategy-classifier.php');
        $combined     = $parserSource . "\n" . $dryRunSource . "\n" . $strategySource;

        $this->assertStringNotContainsString('$wpdb->insert', $combined);
        $this->assertStringNotContainsString('$wpdb->update', $combined);
        $this->assertStringNotContainsString('update_post_meta', $combined);
        $this->assertStringNotContainsString('rank_math', strtolower($combined));
        $this->assertStringNotContainsString('post_content', $combined);
        $this->assertStringNotContainsString('wp_insert_post', $combined);
        $this->assertStringNotContainsString('wp_update_post', $combined);
        $this->assertStringNotContainsString('dbDelta', $combined);
        $this->assertStringNotContainsString('CREATE TABLE', $combined);
        $this->assertStringNotContainsString('noindex', $combined);
    }
    public function test_real_kwe_no_model_column_infers_personal_model_context_and_scope(): void {
        $csv = "Keyword, Volume, Trend, Trend Dir., SEO Score, Traffic Value, Competition, Ad Difficulty, Lowest CPC, Average CPC, Highest CPC, CPC Spread\nanisyia,12100,,,,,,\nanisyia livejasmin,1900,,,,,,\nlivejasmin anisyia,170,,,,,,\nanisyia cam,320,,,,,,\n";
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse($csv), 'model');

        $this->assertSame('anisyia', $result['inferred_model_context']);
        $this->assertSame('anisyia', $result['rows'][0]['model_keyword_owner']);
        $this->assertSame('named_model_opportunity', $result['rows'][0]['model_keyword_strategy']);
        $this->assertSame('model_bio_only', $result['rows'][0]['model_keyword_usage_scope']);
        $this->assertSame('yes', $result['rows'][0]['model_keyword_primary_candidate']);
        $this->assertContains('personal_model_keyword_csv', $result['rows'][0]['model_keyword_scope_reason_codes']);
        $this->assertSame('lj_named_model_opportunity', $result['rows'][1]['model_keyword_strategy']);
        $this->assertSame('model_bio_only', $result['rows'][1]['model_keyword_usage_scope']);
        $this->assertSame('yes', $result['rows'][1]['model_keyword_primary_candidate']);
        $this->assertSame('lj_named_model_opportunity', $result['rows'][2]['model_keyword_strategy']);
        $this->assertSame('model_bio_only', $result['rows'][2]['model_keyword_usage_scope']);
        $this->assertSame('yes', $result['rows'][2]['model_keyword_primary_candidate']);
        $this->assertSame('no', $result['rows'][3]['model_keyword_primary_candidate']);
    }


    public function test_real_kwe_anisyia_batch_ignores_footer_for_model_context(): void {
        $csv = "Keyword, Volume, SEO Score, Traffic Value
anisyia,12100,68,100
anisyia livejasmin,1900,50,40
livejasmin anisyia,170,35,10
Total Volume,19950,,
";
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse($csv), 'model');

        $this->assertSame('anisyia', $result['inferred_model_context']);
        $this->assertSame('anisyia', $result['rows'][0]['model_keyword_owner']);
        $this->assertSame('named_model_opportunity', $result['rows'][0]['model_keyword_strategy']);
        $this->assertSame('model_bio_only', $result['rows'][0]['model_keyword_usage_scope']);
        $this->assertSame('yes', $result['rows'][0]['model_keyword_primary_candidate']);
        $this->assertSame('lj_named_model_opportunity', $result['rows'][1]['model_keyword_strategy']);
        $this->assertSame('model_bio_only', $result['rows'][1]['model_keyword_usage_scope']);
        $this->assertSame('yes', $result['rows'][1]['model_keyword_primary_candidate']);
        $this->assertSame('lj_named_model_opportunity', $result['rows'][2]['model_keyword_strategy']);
        $this->assertSame('model_bio_only', $result['rows'][2]['model_keyword_usage_scope']);
        $this->assertSame('yes', $result['rows'][2]['model_keyword_primary_candidate']);

        $footer = $result['rows'][3];
        $this->assertSame('blocked', $footer['validation_state']);
        $this->assertSame('block', $footer['decision']);
        $this->assertContains('summary_or_footer_row', $footer['reason_codes']);
        $this->assertSame('', $footer['model_keyword_owner']);
        $this->assertSame('not_applicable', $footer['model_keyword_usage_scope']);
        $this->assertSame('no', $footer['model_keyword_primary_candidate']);
        $this->assertNotSame('total volume', $result['inferred_model_context']);
        foreach ($result['rows'] as $row) {
            $this->assertNotSame('total volume', $row['model_keyword_owner']);
        }
    }

    public function test_explicit_model_column_wins_over_inferred_batch_context(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume,model\nanisyia,12100,Other Model\nother model livejasmin,1900,Other Model\n"), 'model');

        $this->assertSame('', $result['inferred_model_context']);
        $this->assertSame('other model', $result['rows'][0]['model_keyword_owner']);
        $this->assertSame('weak_manual_review', $result['rows'][0]['model_keyword_strategy']);
        $this->assertSame('lj_named_model_opportunity', $result['rows'][1]['model_keyword_strategy']);
        $this->assertSame('other model', $result['rows'][1]['model_keyword_owner']);
    }

    public function test_category_and_video_pools_do_not_infer_personal_model_scope(): void {
        $csv = "keyword,volume\nanisyia,12100\n";
        $category = (new KeywordPoolDryRunService())->dry_run($this->parse($csv), 'category');
        $video = (new KeywordPoolDryRunService())->dry_run($this->parse($csv), 'video');

        $this->assertSame('', $category['inferred_model_context']);
        $this->assertSame('', $video['inferred_model_context']);
        $this->assertSame('', $category['rows'][0]['model_keyword_owner']);
        $this->assertSame('not_applicable', $category['rows'][0]['model_keyword_usage_scope']);
        $this->assertSame('', $video['rows'][0]['model_keyword_owner']);
        $this->assertSame('not_applicable', $video['rows'][0]['model_keyword_usage_scope']);
        $this->assertNotSame('model_bio_only', $video['rows'][0]['model_keyword_usage_scope']);
    }

    public function test_not_model_intent_gets_not_model_eligible_scope(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume\nanisyia,12100\ncheapest sex cam sites,1200\nfree adult webcam,800\n"), 'model');

        $this->assertSame('not_model_eligible', $result['rows'][1]['model_keyword_usage_scope']);
        $this->assertSame('not_model_eligible', $result['rows'][2]['model_keyword_usage_scope']);
        $this->assertSame('no', $result['rows'][1]['model_keyword_primary_candidate']);
        $this->assertSame('no', $result['rows'][2]['model_keyword_primary_candidate']);
    }

    public function test_phase_2_performer_expansion_remains_manual_and_non_primary(): void {
        $result = (new KeywordPoolDryRunService())->dry_run($this->parse("keyword,volume\nanisyia,12100\ndani daniels,1200\n"), 'model');

        $this->assertSame('deferred_phase_2_performer_expansion', $result['rows'][1]['model_keyword_strategy']);
        $this->assertSame('manual_review', $result['rows'][1]['model_keyword_usage_scope']);
        $this->assertSame('no', $result['rows'][1]['model_keyword_primary_candidate']);
    }
}

final class KeywordPoolValidationContractRegressionTest extends TestCase {
    private function parse(string $csv): array { return (new KeywordPoolCsvParser())->parse_text($csv); }
    private function row(string $keyword, array $metrics = [], array $context = []): array {
        $cols = array_merge([ 'keyword' => $keyword ], $metrics);
        $result = (new KeywordPoolDryRunService())->dry_run([ $cols ], 'category', array_merge([ 'target_title' => 'Live Cam Chat', 'target_slug' => 'live-cam-chat', 'target_id' => 123 ], $context));
        return $result['rows'][0];
    }
    public function test_live_cam_chat_target_phrases_are_target_context_classified_before_archive_policy(): void {
        foreach ([ 'free cam chat', 'free cam to cam chat', 'adult cam chat' ] as $kw) {
            $row = $this->row($kw, [ 'volume' => 1000, 'cpc' => 3.25, 'competition' => 0.06 ]);
            $this->assertContains($row['pool_fit'], [ 'exact_target_topic', 'category_intent' ]);
            $this->assertNotContains('archive_keyword', $row['reason_codes']);
            $this->assertNotContains('too_broad_low_commercial_intent', $row['reason_codes']);
            $this->assertContains($row['eligibility'], [ 'candidate', 'review' ]);
        }
    }
    public function test_exact_target_phrase_wins_over_generic_archive_dictionary(): void {
        $row = $this->row('live cam chat');
        $this->assertSame('exact_target_topic', $row['pool_fit']);
        $this->assertSame('candidate', $row['eligibility']);
    }
    public function test_browse_supporting_chat_room_and_site_terms_are_contextual_review_not_word_blocked(): void {
        foreach ([ 'free cam chat rooms', 'free cam chat sites' ] as $kw) {
            $row = $this->row($kw);
            $this->assertContains($row['pool_fit'], [ 'browse_supporting', 'category_intent', 'exact_target_topic' ]);
            $this->assertNotSame('blocked', $row['validation_state']);
        }
    }
    public function test_unrelated_free_video_chat_remains_broad_non_tmw(): void {
        $row = $this->row('free video chat', [ 'volume' => 1000, 'cpc' => 0.1, 'competition' => 0.5 ], [ 'target_title' => 'Asian Cam Models', 'target_slug' => 'asian-cam-models' ]);
        $this->assertSame('broad_non_tmw_chat', $row['archive_class']);
        $this->assertContains('broad_non_tmw_chat_intent', $row['reason_codes']);
    }
    public function test_optional_metrics_missing_are_not_invalid_and_competition_proxy_is_used(): void {
        $row = $this->row('asian cam models', [ 'difficulty' => null, 'competition' => 0.06, 'ad_difficulty' => 'null' ]);
        $this->assertNotContains('invalid_difficulty', $row['reason_codes']);
        $this->assertNotContains('invalid_ad_difficulty', $row['reason_codes']);
        $this->assertSame('competition_proxy', $row['difficulty_source']);
    }
    public function test_malformed_ad_difficulty_remains_invalid(): void {
        $row = $this->row('asian cam models', [ 'ad_difficulty' => 'abc' ]);
        $this->assertContains('invalid_ad_difficulty', $row['reason_codes']);
    }
    public function test_cpc_below_two_is_only_golden_missing_not_blocking(): void {
        $row = $this->row('asian cam models', [ 'volume' => 700, 'cpc' => 1.50, 'competition' => 0.06 ]);
        $this->assertFalse($row['is_golden_keyword']);
        $this->assertContains('cpc_below_2_00', $row['golden_missing_reasons']);
        $this->assertNotSame('blocked', $row['validation_state']);
    }
    public function test_high_commercial_broad_reason_is_not_low_commercial_contradiction(): void {
        $row = $this->row('free video chat', [ 'volume' => 1000, 'cpc' => 5.00 ], [ 'target_title' => 'Asian Cam Models' ]);
        $this->assertSame('high', $row['tmw_commercial_band']);
        $this->assertNotContains('too_broad_low_commercial_intent', $row['reason_codes']);
    }
    public function test_unsafe_and_geo_protections_remain(): void {
        $this->assertSame('blocked', $this->row('schoolgirl roleplay')['validation_state']);
        $this->assertSame('blocked', $this->row('webcam models near me')['validation_state']);
    }
}
