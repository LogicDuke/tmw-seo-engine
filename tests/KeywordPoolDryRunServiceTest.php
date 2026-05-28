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
