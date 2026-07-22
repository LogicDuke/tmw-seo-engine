<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordPoolImportRowRepairService;
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-classification-policy.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-import-row-repair-service.php';
final class KeywordPoolRepairServiceTest extends TestCase {
    public function test_repair_report_counts_only_meaningful_changes_and_is_idempotent(): void {
        $rows = [
            [ 'id'=>1, 'row_payload'=>json_encode([ 'keyword'=>'asian cam models', 'normalized_keyword'=>'asian cam models', 'reason_codes'=>[ 'category_intent_detected', 'browse_supporting_intent' ] ]) ],
            [ 'id'=>2, 'row_payload'=>json_encode([ 'keyword'=>'asian cam models', 'normalized_keyword'=>'asian cam models', 'ad_difficulty'=>'', 'reason_codes'=>[ 'category_intent_detected', 'invalid_ad_difficulty' ] ]) ],
            [ 'id'=>3, 'row_payload'=>json_encode([ 'keyword'=>'free cam chat', 'normalized_keyword'=>'free cam chat', 'reason_codes'=>[ 'too_broad_low_commercial_intent' ] ]) ],
            [ 'id'=>4, 'result_reason'=>'candidate_write_failed', 'row_payload'=>json_encode([ 'keyword'=>'free cam chat', 'normalized_keyword'=>'free cam chat', 'reason_codes'=>[ 'target_topic_match' ] ]) ],
        ];
        $service = new KeywordPoolImportRowRepairService();
        $report = $service->repair_rows($rows, 'category', [ 'target_title'=>'Live Cam Chat' ], true);
        $again = $service->repair_rows($rows, 'category', [ 'target_title'=>'Live Cam Chat' ], true);

        $this->assertSame(3, $report['changed_rows']);
        $this->assertEquals($report, $again);
        $this->assertFalse($report['rows'][0]['changed']);
        $this->assertTrue($report['rows'][1]['changed']);
        $this->assertTrue($report['rows'][2]['changed']);
        $this->assertTrue($report['rows'][3]['changed']);
        foreach ($report['rows'] as $row) {
            $this->assertFalse($row['creates_candidate']);
            $this->assertArrayHasKey('raw_payload', $row);
        }
        $this->assertSame('', $report['rows'][1]['raw_payload']['ad_difficulty']);
        $this->assertNotContains('invalid_ad_difficulty', $report['rows'][1]['effective_payload']['reason_codes']);
        $this->assertContains('broad_non_tmw_chat_intent', $report['rows'][2]['effective_payload']['reason_codes']);
        $this->assertSame('failed', $report['rows'][3]['effective_payload']['candidate_persistence_state']);
    }
}
