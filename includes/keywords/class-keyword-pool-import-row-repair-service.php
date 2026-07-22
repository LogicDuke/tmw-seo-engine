<?php
/** Safe report-only repair helper for stored keyword-pool import rows. */
declare(strict_types=1);
namespace TMWSEO\Engine\Keywords;
if (!class_exists(KeywordPoolClassificationPolicy::class)) { require_once __DIR__ . '/class-keyword-pool-classification-policy.php'; }
class KeywordPoolImportRowRepairService {
    /** @param array<int,array<string,mixed>> $rows @return array<string,mixed> */
    public function repair_rows(array $rows, string $pool = 'category', array $context = [], bool $dry_run = true): array {
        $report = [ 'dry_run'=>$dry_run, 'changed_rows'=>0, 'reason_changes'=>[], 'state_changes'=>[], 'rows'=>[] ];
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $payload = json_decode((string)($row['row_payload'] ?? ''), true);
            if (!is_array($payload)) { $payload = $row; }
            $original = $payload;
            $reasons = is_array($payload['reason_codes'] ?? null) ? array_values(array_map('strval', $payload['reason_codes'])) : [];
            foreach ([ 'difficulty', 'ad_difficulty' ] as $metric) {
                if ($this->blank($payload[$metric] ?? null) && in_array('invalid_' . $metric, $reasons, true)) {
                    $reasons = array_values(array_diff($reasons, [ 'invalid_' . $metric ]));
                    $this->bump($report['reason_changes'], 'removed_invalid_' . $metric);
                }
            }
            if (in_array('too_broad_low_commercial_intent', $reasons, true)) {
                $reasons = array_values(array_diff($reasons, [ 'too_broad_low_commercial_intent' ]));
                $reasons[] = 'broad_non_tmw_chat_intent';
                $this->bump($report['reason_changes'], 'mapped_too_broad_low_commercial_intent');
            }
            $payload['reason_codes'] = array_values(array_unique($reasons));
            $classification = (new KeywordPoolClassificationPolicy())->classify($payload, $pool, $context);
            $payload['reason_codes'] = array_values(array_unique(array_merge($payload['reason_codes'], $classification['reason_codes'])));
            $meaningful = $payload;
            $effective = $payload + [ 'original_classification' => $original ];
            $effective['effective_classification'] = $classification;
            if ('candidate_write_failed' === (string)($row['result_reason'] ?? '')) {
                $meaningful['candidate_persistence_state'] = 'failed';
                $meaningful['candidate_persistence_reason'] = 'candidate_write_failed';
                $meaningful['manual_review_state'] = 'approval_failed';
                $effective['candidate_persistence_state'] = 'failed';
                $effective['candidate_persistence_reason'] = 'candidate_write_failed';
                $effective['manual_review_state'] = 'approval_failed';
                $this->bump($report['state_changes'], 'separated_candidate_write_failed');
            }
            $changed = $this->meaningful_changed($original, $meaningful);
            if ($changed) { $report['changed_rows']++; }
            $report['rows'][] = [ 'id'=>(int)($row['id'] ?? 0), 'changed'=>$changed, 'raw_payload'=>$original, 'effective_payload'=>$effective, 'creates_candidate'=>false ];
        }
        return $report;
    }
    private function meaningful_changed(array $original, array $meaningful): bool {
        $ignore = [ 'original_classification', 'effective_classification', 'raw_payload', 'audit_metadata', 'repair_report' ];
        foreach ($ignore as $key) {
            unset($original[$key], $meaningful[$key]);
        }
        if (isset($original['reason_codes']) && is_array($original['reason_codes'])) {
            $original['reason_codes'] = array_values(array_unique(array_map('strval', $original['reason_codes'])));
            sort($original['reason_codes']);
        }
        if (isset($meaningful['reason_codes']) && is_array($meaningful['reason_codes'])) {
            $meaningful['reason_codes'] = array_values(array_unique(array_map('strval', $meaningful['reason_codes'])));
            sort($meaningful['reason_codes']);
        }
        ksort($original);
        ksort($meaningful);
        return $original != $meaningful;
    }
    private function blank($v): bool { return $v === null || (is_string($v) && in_array(strtolower(trim($v)), ['', 'null', 'n/a', 'na', '-'], true)) || (is_float($v) && is_nan($v)); }
    private function bump(array &$a, string $k): void { $a[$k] = ($a[$k] ?? 0) + 1; }
}
