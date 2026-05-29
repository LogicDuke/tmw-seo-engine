<?php
/**
 * Save-selected service for keyword pool dry-run rows.
 *
 * @package TMWSEO\Engine\Keywords
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

if (!defined('ABSPATH')) { exit; }

class KeywordPoolSelectedImportService {

    private const ELIGIBLE_PRIORITIES = [ 'TMW-P1', 'TMW-P2', 'TMW-P3' ];
    private const ELIGIBLE_ACTIONS = [ 'approve_for_phase_1', 'queue_for_review' ];
    private const BLOCKING_REASONS = [ 'archive_keyword', 'unsafe_keyword', 'summary_or_footer_row', 'geo_local_intent', 'duplicate_in_upload' ];
    private const SAVE_MODES = [ 'auto', 'queued_for_review', 'approved' ];
    private const METRIC_FIELDS = [ 'volume', 'difficulty', 'cpc', 'competition', 'opportunity', 'seo_score', 'traffic_value', 'trend', 'ad_difficulty', 'difficulty_proxy', 'opportunity_score', 'trend_direction', 'tmw_score', 'tmw_priority', 'tmw_difficulty_band', 'tmw_commercial_band', 'tmw_indexing_readiness', 'tmw_recommended_action' ];

    private KeywordPoolCandidateRepository $repository;

    public function __construct(?KeywordPoolCandidateRepository $repository = null) {
        $this->repository = $repository ?: new KeywordPoolCandidateRepository();
    }

    /**
     * @param array<string,mixed> $dry_run
     * @param array<int|string> $selected_rows Row numbers selected by the operator.
     * @return array<string,mixed>
     */
    public function save_selected(array $dry_run, string $pool, array $selected_rows, string $save_mode = 'auto'): array {
        $pool = $this->sanitize_pool($pool);
        $save_mode = in_array($save_mode, self::SAVE_MODES, true) ? $save_mode : 'auto';
        $selected_lookup = [];
        foreach ($selected_rows as $row_number) {
            $selected_lookup[(string) (int) $row_number] = true;
        }

        $summary = [
            'selected' => count($selected_lookup),
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'conflicts' => 0,
            'blocked' => 0,
            'errors' => 0,
        ];
        $results = [];
        $seen_keywords = [];

        $rows = is_array($dry_run['rows'] ?? null) ? $dry_run['rows'] : [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row_number = (string) (int) ($row['row_number'] ?? 0);
            if (empty($selected_lookup[$row_number])) {
                continue;
            }

            $row = $this->ensure_tmw_scored_row($row, $pool);
            $keyword = $this->repository->normalize_keyword((string) ($row['normalized_keyword'] ?? $row['keyword'] ?? ''));
            $result_base = $this->result_base($row, $pool, $this->status_for_row($row, $save_mode));

            $eligibility = $this->eligibility_reason($row, $pool, $seen_keywords);
            if (null !== $eligibility) {
                $action = str_starts_with($eligibility, 'blocked_') ? 'blocked' : 'skipped';
                $summary[$action === 'blocked' ? 'blocked' : 'skipped']++;
                $results[] = array_merge($result_base, [
                    'keyword' => $keyword,
                    'action' => $action,
                    'reason' => $eligibility,
                ]);
                continue;
            }

            $seen_keywords[$keyword] = true;
            $saved = $this->repository->save($this->candidate_from_row($row, $pool, $save_mode));
            $result = array_merge($result_base, $saved, [
                'volume' => $row['volume'] ?? null,
                'cpc' => $row['cpc'] ?? null,
                'competition' => $row['competition'] ?? null,
                'seo_score' => $row['seo_score'] ?? null,
                'traffic_value' => $row['traffic_value'] ?? null,
            ]);

            if ('inserted' === $saved['action']) {
                $summary['inserted']++;
            } elseif ('updated' === $saved['action']) {
                $summary['updated']++;
            } elseif ('conflict' === $saved['action']) {
                $summary['conflicts']++;
            } elseif ('error' === $saved['action']) {
                $summary['errors']++;
            } else {
                $summary['skipped']++;
            }
            $results[] = $result;
        }

        return [ 'summary' => $summary, 'rows' => $results ];
    }

    /** @param array<string,mixed> $row */
    public function is_row_eligible(array $row, string $pool): bool {
        return null === $this->eligibility_reason($row, $this->sanitize_pool($pool), []);
    }

    /** @param array<string,mixed> $row */
    private function eligibility_reason(array $row, string $pool, array $seen_keywords): ?string {
        $row = $this->ensure_tmw_scored_row($row, $pool);
        $keyword = $this->repository->normalize_keyword((string) ($row['normalized_keyword'] ?? $row['keyword'] ?? ''));
        $reason_codes = is_array($row['reason_codes'] ?? null) ? array_map('strval', $row['reason_codes']) : [];

        if ('' === $keyword) {
            return 'missing_keyword';
        }
        if ('defer_until_lj_50_model_milestone' === (string) ($row['tmw_indexing_readiness'] ?? '')) {
            return 'defer_until_lj_50_model_milestone';
        }
        if ('valid' !== (string) ($row['validation_state'] ?? '')) {
            return 'blocked_validation_state_' . (string) ($row['validation_state'] ?? 'unknown');
        }
        if ('accept' !== (string) ($row['decision'] ?? '')) {
            return 'blocked_decision_' . (string) ($row['decision'] ?? 'unknown');
        }
        if ('archive_do_not_use' === (string) ($row['tmw_indexing_readiness'] ?? '')) {
            return 'tmw_archive_do_not_use';
        }
        if (!in_array((string) ($row['tmw_recommended_action'] ?? ''), self::ELIGIBLE_ACTIONS, true)) {
            return 'tmw_not_phase_1_ready';
        }
        if (!in_array((string) ($row['tmw_priority'] ?? ''), self::ELIGIBLE_PRIORITIES, true)) {
            return 'tmw_archive_do_not_use';
        }
        foreach (self::BLOCKING_REASONS as $blocking_reason) {
            if (in_array($blocking_reason, $reason_codes, true)) {
                return 'blocked_' . $blocking_reason;
            }
        }
        if (!empty($row['is_duplicate_in_upload']) || isset($seen_keywords[$keyword])) {
            return 'duplicate_in_upload_skipped';
        }
        if ('video' === $pool) {
            $model_name = $this->repository->normalize_keyword((string) ($row['model_name'] ?? ''));
            if ('' !== $model_name && $keyword === $model_name) {
                return 'blocked_standalone_model_name';
            }
            if (class_exists(VideoKeywordCandidateRepository::class)) {
                $video_repository = new VideoKeywordCandidateRepository();
                if (!$video_repository->is_video_intent_keyword($keyword, $model_name)) {
                    return 'blocked_video_intent_required';
                }
            }
        }
        return null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function candidate_from_row(array $row, string $pool, string $save_mode): array {
        $candidate = [
            'keyword' => (string) ($row['normalized_keyword'] ?? $row['keyword'] ?? ''),
            'intent_type' => $pool,
            'entity_type' => $this->entity_type_for_pool($pool),
            'entity_id' => $this->entity_id_for_pool($row, $pool),
            'status' => $this->status_for_row($row, $save_mode),
            'provenance' => $this->provenance_for_row($row, $pool),
        ];
        foreach (self::METRIC_FIELDS as $metric) {
            if (array_key_exists($metric, $row)) {
                $candidate[$metric] = $row[$metric];
            }
        }
        return $candidate;
    }

    /** @param array<string,mixed> $row */
    private function status_for_row(array $row, string $save_mode): string {
        if ('approved' === $save_mode) {
            return 'approved';
        }
        if ('queued_for_review' === $save_mode) {
            return 'queued_for_review';
        }
        if ('approve_for_phase_1' === (string) ($row['tmw_recommended_action'] ?? '') && 'valid' === (string) ($row['validation_state'] ?? '')) {
            if ('TMW-P1' === (string) ($row['tmw_priority'] ?? '') && empty($row['is_golden_keyword'])) {
                return 'queued_for_review';
            }
            return 'approved';
        }
        return 'queued_for_review';
    }


    /** @param array<string,mixed> $row */
    private function entity_id_for_pool(array $row, string $pool): int {
        if ('category' === $pool) {
            return max(0, (int) ($row['entity_id'] ?? 0));
        }
        if (array_key_exists('post_id', $row) && '' !== (string) $row['post_id']) {
            return max(0, (int) $row['post_id']);
        }
        return max(0, (int) ($row['entity_id'] ?? 0));
    }

    private function entity_type_for_pool(string $pool): string {
        if ('video' === $pool) {
            return 'post';
        }
        if ('category' === $pool) {
            return 'category';
        }
        return 'model';
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function provenance_for_row(array $row, string $pool): array {
        return [
            'pool' => $pool,
            'upload_source' => (string) ($row['source'] ?? ''),
            'parser_source_label' => (string) ($row['source'] ?? ''),
            'priority_preview' => (string) ($row['priority_preview'] ?? ''),
            'is_golden_keyword' => !empty($row['is_golden_keyword']),
            'recommended_action' => (string) ($row['recommended_action'] ?? ''),
            'tmw_priority' => (string) ($row['tmw_priority'] ?? ''),
            'tmw_indexing_readiness' => (string) ($row['tmw_indexing_readiness'] ?? ''),
            'tmw_recommended_action' => (string) ($row['tmw_recommended_action'] ?? ''),
            'tmw_score' => (int) ($row['tmw_score'] ?? 0),
            'tmw_reason_codes' => is_array($row['tmw_reason_codes'] ?? null) ? array_values($row['tmw_reason_codes']) : [],
            'reason_codes' => is_array($row['reason_codes'] ?? null) ? array_values($row['reason_codes']) : [],
            'golden_formula_summary' => (string) ($row['golden_formula_summary'] ?? ''),
            'golden_missing_reasons' => is_array($row['golden_missing_reasons'] ?? null) ? array_values($row['golden_missing_reasons']) : [],
            'imported_from_keyword_pools' => true,
            'imported_at' => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function ensure_tmw_scored_row(array $row, string $pool): array {
        if (array_key_exists('tmw_score', $row) && array_key_exists('tmw_priority', $row)) {
            return $row;
        }
        return array_merge($row, (new KeywordPoolMetricsScorer())->score($row, $pool));
    }

    private function sanitize_pool(string $pool): string {
        return in_array($pool, [ 'model', 'video', 'category' ], true) ? $pool : 'model';
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function result_base(array $row, string $pool, string $status): array {
        return [
            'keyword' => (string) ($row['normalized_keyword'] ?? $row['keyword'] ?? ''),
            'pool' => $pool,
            'status' => $status,
            'action' => '',
            'reason' => '',
            'volume' => $row['volume'] ?? null,
            'cpc' => $row['cpc'] ?? null,
            'competition' => $row['competition'] ?? null,
            'seo_score' => $row['seo_score'] ?? null,
            'traffic_value' => $row['traffic_value'] ?? null,
            'entity_type' => $this->entity_type_for_pool($pool),
            'entity_id' => $this->entity_id_for_pool($row, $pool),
        ];
    }
}
