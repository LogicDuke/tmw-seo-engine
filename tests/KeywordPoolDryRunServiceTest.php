<?php
/**
 * Tests for shared keyword pool dry-run previews.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordPoolCsvParser;
use TMWSEO\Engine\Keywords\KeywordPoolDryRunService;

require_once __DIR__ . '/../includes/keywords/class-keyword-pool-csv-parser.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-dry-run-service.php';

class KeywordPoolDryRunServiceTest extends TestCase {

    private function parse(string $csv): array {
        return (new KeywordPoolCsvParser())->parse_text($csv);
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

    /**
     * @return array<int, array<int, string>>
     */
    public function summary_footer_keyword_provider(): array {
        return [
            [ 'Total Volume' ],
            [ 'Grand Total' ],
            [ 'Subtotal' ],
            [ 'Summary' ],
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
        $this->assertContains('archive_keyword', $rows[0]['reason_codes']);
        $this->assertContains('unsafe_keyword', $rows[0]['reason_codes']);
        $this->assertContains('rename_recommended', $rows[0]['reason_codes']);
        $this->assertStringContainsString('Use "uniform roleplay cam girls" instead.', $rows[0]['reason_summary']);

        $this->assertSame('blocked', $rows[1]['validation_state']);
        $this->assertSame('Archive', $rows[1]['priority_preview']);
        $this->assertContains('unsafe_keyword', $rows[1]['reason_codes']);

        $this->assertSame('blocked', $rows[2]['validation_state']);
        $this->assertSame('Archive', $rows[2]['priority_preview']);
        $this->assertContains('too_broad_low_commercial_intent', $rows[2]['reason_codes']);

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
        $combined     = $parserSource . "\n" . $dryRunSource;

        $this->assertStringNotContainsString('$wpdb->insert', $combined);
        $this->assertStringNotContainsString('$wpdb->update', $combined);
        $this->assertStringNotContainsString('update_post_meta', $combined);
        $this->assertStringNotContainsString('rank_math', strtolower($combined));
        $this->assertStringNotContainsString('post_content', $combined);
    }
}
