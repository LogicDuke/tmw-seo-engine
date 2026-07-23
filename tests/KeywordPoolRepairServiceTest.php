<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordPoolImportRowRepairService;
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-classification-policy.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-import-row-repair-service.php';
final class KeywordPoolRepairServiceTest extends TestCase {
    public function test_repair_report_counts_only_meaningful_changes_and_is_idempotent(): void {
        $rows = [
            [ 'id'=>1, 'row_payload'=>json_encode([ 'keyword'=>'asian cam models', 'normalized_keyword'=>'asian cam models', 'reason_codes'=>[ 'browse_supporting_intent' ] ]) ],
            [ 'id'=>2, 'row_payload'=>json_encode([ 'keyword'=>'asian cam models', 'normalized_keyword'=>'asian cam models', 'ad_difficulty'=>'', 'reason_codes'=>[ 'browse_supporting_intent', 'invalid_ad_difficulty' ] ]) ],
            [ 'id'=>3, 'row_payload'=>json_encode([ 'keyword'=>'free cam chat', 'normalized_keyword'=>'free cam chat', 'reason_codes'=>[ 'archive_keyword', 'too_broad_low_commercial_intent', 'broad_non_tmw_chat_intent' ] ]) ],
            [ 'id'=>4, 'result_reason'=>'candidate_write_failed', 'row_payload'=>json_encode([ 'keyword'=>'free cam chat', 'normalized_keyword'=>'free cam chat', 'reason_codes'=>[ 'target_topic_match', 'candidate_write_failed' ] ]) ],
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
    }

    public function test_target_matched_repair_drops_contradictory_legacy_broad_reasons(): void {
        $row = [ 'id'=>10, 'row_payload'=>json_encode([ 'keyword'=>'free cam chat', 'normalized_keyword'=>'free cam chat', 'reason_codes'=>[ 'archive_keyword', 'too_broad_low_commercial_intent', 'broad_non_tmw_chat_intent' ] ]) ];
        $report = (new KeywordPoolImportRowRepairService())->repair_rows([ $row ], 'category', [ 'target_title'=>'Live Cam Chat' ], true);
        $effective = $report['rows'][0]['effective_payload'];

        $this->assertContains('target_topic_match', $effective['reason_codes']);
        $this->assertNotContains('broad_non_tmw_chat_intent', $effective['reason_codes']);
        $this->assertNotContains('too_broad_low_commercial_intent', $effective['reason_codes']);
        $this->assertNotContains('archive_keyword', $effective['reason_codes']);
        $this->assertContains($effective['effective_classification']['eligibility'], [ 'candidate', 'review' ]);
        $this->assertNotSame('broad_non_tmw_chat', $effective['effective_classification']['archive_class']);
        $this->assertNotSame('blocked', $effective['effective_classification']['eligibility']);
        $this->assertSame([ 'archive_keyword', 'too_broad_low_commercial_intent', 'broad_non_tmw_chat_intent' ], $report['rows'][0]['raw_payload']['reason_codes']);
    }

    public function test_unrelated_target_repair_keeps_authoritative_broad_reason(): void {
        $row = [ 'id'=>11, 'row_payload'=>json_encode([ 'keyword'=>'free cam chat', 'normalized_keyword'=>'free cam chat', 'reason_codes'=>[ 'archive_keyword', 'too_broad_low_commercial_intent' ] ]) ];
        $report = (new KeywordPoolImportRowRepairService())->repair_rows([ $row ], 'category', [ 'target_title'=>'Asian Cam Models' ], true);
        $effective = $report['rows'][0]['effective_payload'];

        $this->assertContains('broad_non_tmw_chat_intent', $effective['reason_codes']);
        $this->assertNotContains('target_topic_match', $effective['reason_codes']);
        $this->assertSame('broad_non_tmw_chat', $effective['effective_classification']['archive_class']);
    }

    public function test_candidate_write_failed_is_separated_not_classification_reason(): void {
        $row = [ 'id'=>12, 'result_reason'=>'candidate_write_failed', 'row_payload'=>json_encode([ 'keyword'=>'free cam chat', 'normalized_keyword'=>'free cam chat', 'reason_codes'=>[ 'target_topic_match', 'candidate_write_failed' ] ]) ];
        $report = (new KeywordPoolImportRowRepairService())->repair_rows([ $row ], 'category', [ 'target_title'=>'Live Cam Chat' ], true);
        $effective = $report['rows'][0]['effective_payload'];

        $this->assertSame('failed', $effective['candidate_persistence_state']);
        $this->assertSame('candidate_write_failed', $effective['candidate_persistence_reason']);
        $this->assertNotContains('candidate_write_failed', $effective['reason_codes']);
        $this->assertContains('candidate_write_failed', $report['rows'][0]['raw_payload']['reason_codes']);
    }
}
