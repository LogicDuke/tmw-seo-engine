<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordPoolImportRowRepairService;
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-classification-policy.php';
require_once __DIR__ . '/../includes/keywords/class-keyword-pool-import-row-repair-service.php';
final class KeywordPoolRepairServiceTest extends TestCase {
    public function test_repair_report_removes_stale_blank_invalid_metric_preserves_raw_and_separates_write_failure(): void {
        $rows = [[ 'id'=>7, 'result_reason'=>'candidate_write_failed', 'row_payload'=>json_encode([ 'keyword'=>'free cam chat', 'normalized_keyword'=>'free cam chat', 'ad_difficulty'=>'', 'reason_codes'=>[ 'archive_keyword', 'invalid_ad_difficulty', 'too_broad_low_commercial_intent' ] ]) ]];
        $report = (new KeywordPoolImportRowRepairService())->repair_rows($rows, 'category', [ 'target_title'=>'Live Cam Chat' ], true);
        $this->assertSame(1, $report['changed_rows']);
        $this->assertSame('', $report['rows'][0]['raw_payload']['ad_difficulty']);
        $effective = $report['rows'][0]['effective_payload'];
        $this->assertNotContains('invalid_ad_difficulty', $effective['reason_codes']);
        $this->assertSame('failed', $effective['candidate_persistence_state']);
        $this->assertFalse($report['rows'][0]['creates_candidate']);
    }
}
