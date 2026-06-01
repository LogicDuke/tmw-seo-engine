<?php
/**
 * Save-selected service for keyword pool dry-run rows.
 *
 * @package TMWSEO\Engine\Keywords
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Models\ModelEntityResolver;

if (!defined('ABSPATH')) { exit; }

class KeywordPoolSelectedImportService {

    private const ELIGIBLE_PRIORITIES = [ 'TMW-P1', 'TMW-P2', 'TMW-P3' ];
    private const ELIGIBLE_ACTIONS = [ 'approve_for_phase_1', 'queue_for_review' ];
    private const BLOCKING_REASONS = [ 'archive_keyword', 'unsafe_keyword', 'summary_or_footer_row', 'geo_local_intent', 'duplicate_in_upload' ];
    private const SAVE_MODES = [ 'auto', 'queued_for_review', 'approved' ];
    private const METRIC_FIELDS = [ 'volume', 'difficulty', 'cpc', 'competition', 'opportunity', 'seo_score', 'traffic_value', 'trend', 'trend_direction', 'ad_difficulty', 'difficulty_proxy', 'opportunity_score', 'lowest_cpc', 'average_cpc', 'highest_cpc', 'cpc_spread', 'tmw_score', 'tmw_priority', 'tmw_difficulty_band', 'tmw_commercial_band', 'tmw_indexing_readiness', 'tmw_recommended_action' ];

    private KeywordPoolCandidateRepository $repository;
    private ModelEntityResolver $model_entity_resolver;
    /** @var array<string,array<string,mixed>> */
    private array $model_entity_resolution_cache = [];

    public function __construct(?KeywordPoolCandidateRepository $repository = null, ?ModelEntityResolver $model_entity_resolver = null) {
        $this->repository = $repository ?: new KeywordPoolCandidateRepository();
        $this->model_entity_resolver = $model_entity_resolver ?: new ModelEntityResolver();
    }

    /**
     * @param array<string,mixed> $dry_run
     * @param array<int|string> $selected_rows Row numbers selected by the operator.
     * @return array<string,mixed>
     */
    public function save_selected(array $dry_run, string $pool, array $selected_rows, string $save_mode = 'auto'): array {
        $selected_lookup = [];
        foreach ($selected_rows as $row_number) {
            $selected_lookup[(string) (int) $row_number] = true;
        }
        return $this->save_matching_rows($dry_run, $pool, $selected_lookup, $save_mode, false);
    }

    /**
     * Save every useful non-footer row from a reviewed Model Pool dry run.
     *
     * @param array<string,mixed> $dry_run
     * @return array<string,mixed>
     */
    public function save_full_reviewed_model_batch(array $dry_run): array {
        $selected_lookup = [];
        $rows = is_array($dry_run['rows'] ?? null) ? $dry_run['rows'] : [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $selected_lookup[(string) (int) ($row['row_number'] ?? 0)] = true;
            }
        }
        return $this->save_matching_rows($dry_run, 'model', $selected_lookup, 'auto', true);
    }

    /** @param array<string,mixed> $row */
    public function is_row_eligible(array $row, string $pool): bool {
        return null === $this->eligibility_reason($row, $this->sanitize_pool($pool), [], false);
    }

    /** @param array<string,mixed> $dry_run @param array<string,bool> $selected_lookup @return array<string,mixed> */
    private function save_matching_rows(array $dry_run, string $pool, array $selected_lookup, string $save_mode, bool $full_batch): array {
        $pool = $this->sanitize_pool($pool);
        $save_mode = in_array($save_mode, self::SAVE_MODES, true) ? $save_mode : 'auto';
        $this->model_entity_resolution_cache = [];

        $summary = [
            'selected' => count($selected_lookup),
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'conflicts' => 0,
            'blocked' => 0,
            'errors' => 0,
            'linked_model_entities' => 0,
            'unresolved_model_entities' => 0,
            'ambiguous_model_entities' => 0,
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
            $row = $this->apply_model_entity_resolution($row, $pool);
            $status = $this->status_for_row($row, $save_mode, $full_batch);
            $keyword = $this->repository->normalize_keyword((string) ($row['normalized_keyword'] ?? $row['keyword'] ?? ''));
            $result_base = $this->result_base($row, $pool, $status);

            $eligibility = $this->eligibility_reason($row, $pool, $seen_keywords, $full_batch);
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
            $saved = $this->repository->save($this->candidate_from_row($row, $pool, $save_mode, $status));
            $result = array_merge($result_base, $saved, [
                'volume' => $row['volume'] ?? null,
                'cpc' => $row['cpc'] ?? null,
                'competition' => $row['competition'] ?? null,
                'seo_score' => $row['seo_score'] ?? null,
                'traffic_value' => $row['traffic_value'] ?? null,
            ]);

            if ('model' === $pool && '' !== (string) ($row['model_keyword_owner'] ?? '')) {
                $match_type = (string) ($row['model_entity_match_type'] ?? '');
                if (!empty($row['model_entity_resolved'])) {
                    $summary['linked_model_entities']++;
                } elseif ('ambiguous' === $match_type) {
                    $summary['ambiguous_model_entities']++;
                } else {
                    $summary['unresolved_model_entities']++;
                }
            }

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
    private function eligibility_reason(array $row, string $pool, array $seen_keywords, bool $full_batch = false): ?string {
        $row = $this->ensure_tmw_scored_row($row, $pool);
        $keyword = $this->repository->normalize_keyword((string) ($row['normalized_keyword'] ?? $row['keyword'] ?? ''));
        $reason_codes = is_array($row['reason_codes'] ?? null) ? array_map('strval', $row['reason_codes']) : [];

        if ('' === $keyword) {
            return 'missing_keyword';
        }
        if ($this->is_summary_or_footer_keyword($keyword) || in_array('summary_or_footer_row', $reason_codes, true)) {
            return 'blocked_summary_or_footer_row';
        }
        if (!empty($row['is_duplicate_in_upload']) || isset($seen_keywords[$keyword])) {
            return 'duplicate_in_upload_skipped';
        }
        foreach (self::BLOCKING_REASONS as $blocking_reason) {
            if (in_array($blocking_reason, $reason_codes, true)) {
                return 'blocked_' . $blocking_reason;
            }
        }

        if ($full_batch) {
            return null;
        }

        if ('defer_until_lj_50_model_milestone' === (string) ($row['tmw_indexing_readiness'] ?? '')) {
            return 'defer_until_lj_50_model_milestone';
        }
        $model_scope = (string) ($row['model_keyword_usage_scope'] ?? '');
        if ('model' === $pool && 'not_model_eligible' === $model_scope) {
            return 'blocked_not_model_eligible';
        }
        $is_model_manual_review = 'model' === $pool && 'manual_review' === $model_scope;
        if ('valid' !== (string) ($row['validation_state'] ?? '') && ! $is_model_manual_review) {
            return 'blocked_validation_state_' . (string) ($row['validation_state'] ?? 'unknown');
        }
        if ('accept' !== (string) ($row['decision'] ?? '') && ! $is_model_manual_review) {
            return 'blocked_decision_' . (string) ($row['decision'] ?? 'unknown');
        }
        if ('archive_do_not_use' === (string) ($row['tmw_indexing_readiness'] ?? '')) {
            return 'tmw_archive_do_not_use';
        }
        if ('model' === $pool && in_array((string) ($row['model_keyword_recommended_action'] ?? ''), [ 'reject_not_model_intent', 'defer_until_lj_50_model_milestone' ], true)) {
            return (string) ($row['model_keyword_recommended_action'] ?? 'model_keyword_strategy_blocked');
        }
        if (!in_array((string) ($row['tmw_recommended_action'] ?? ''), self::ELIGIBLE_ACTIONS, true)) {
            return 'tmw_not_phase_1_ready';
        }
        if (!in_array((string) ($row['tmw_priority'] ?? ''), self::ELIGIBLE_PRIORITIES, true)) {
            return 'tmw_archive_do_not_use';
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

    private function is_summary_or_footer_keyword(string $keyword): bool {
        $keyword = strtolower(trim(preg_replace('/[^a-z0-9]+/', ' ', $keyword) ?? $keyword));
        $keyword = preg_replace('/\s+/', ' ', $keyword) ?? $keyword;
        $keyword = trim($keyword);
        if ('' === $keyword) { return false; }
        $labels = [ 'total', 'totals', 'total volume', 'grand total', 'subtotal', 'sub total', 'summary', 'showing', 'keyword', 'volume', 'average', 'avg' ];
        if (in_array($keyword, $labels, true)) { return true; }
        foreach ([ 'total volume', 'grand total', 'subtotal', 'sub total', 'average', 'summary', 'showing' ] as $label) {
            if (str_contains($keyword, $label)) { return true; }
        }
        return preg_match('/(?:^|\s)(?:avg|total|totals|keyword|volume)(?:\s|$)/', $keyword) === 1
            && preg_match('/^(?:avg|average|grand total|keyword|showing|sub total|subtotal|summary|total|totals|volume)(?:\s|$)/', $keyword) === 1;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function apply_model_entity_resolution(array $row, string $pool): array {
        if ('model' !== $pool) { return $row; }
        $owner = (string) ($row['model_keyword_owner'] ?? '');
        $scope = (string) ($row['model_keyword_usage_scope'] ?? '');
        if ('' === trim($owner) || !in_array($scope, [ 'model_bio_only', 'model_page_only', 'manual_review', 'not_model_eligible' ], true)) {
            return $row;
        }
        $provided_entity_id = $this->entity_id_for_pool($row, $pool);
        if ($provided_entity_id > 0) {
            $resolution = [
                'found' => true,
                'post_id' => $provided_entity_id,
                'entity_id' => $provided_entity_id,
                'post_title' => '',
                'post_type' => 'model',
                'match_type' => 'provided_entity_id',
                'reason_codes' => [ 'model_entity_resolved', 'model_match_provided_entity_id' ],
                'matches' => [],
            ];
            $row['model_entity_resolution'] = $resolution;
            $row['model_entity_resolved'] = true;
            $row['model_entity_id'] = $provided_entity_id;
            $row['model_entity_match_type'] = 'provided_entity_id';
            $row['model_entity_reason_codes'] = $resolution['reason_codes'];
            return $row;
        }

        $cache_key = $this->model_owner_cache_key($owner);
        if (!isset($this->model_entity_resolution_cache[$cache_key])) {
            $this->model_entity_resolution_cache[$cache_key] = $this->model_entity_resolver->resolve($owner);
        }
        $resolution = $this->model_entity_resolution_cache[$cache_key];
        $row['model_entity_resolution'] = $resolution;
        $row['model_entity_resolved'] = !empty($resolution['found']);
        $row['model_entity_id'] = (int) ($resolution['entity_id'] ?? 0);
        $row['model_entity_title'] = (string) ($resolution['post_title'] ?? '');
        $row['model_entity_post_type'] = (string) ($resolution['post_type'] ?? 'model');
        $row['model_entity_match_type'] = (string) ($resolution['match_type'] ?? 'not_found');
        $row['model_entity_reason_codes'] = is_array($resolution['reason_codes'] ?? null) ? array_values($resolution['reason_codes']) : [];
        if (!empty($resolution['found'])) {
            $row['entity_id'] = (int) ($resolution['entity_id'] ?? 0);
            $row['post_id'] = (int) ($resolution['entity_id'] ?? 0);
        } else {
            $row['entity_id'] = 0;
            $row['post_id'] = 0;
            $row['reason_codes'] = array_values(array_unique(array_merge(
                is_array($row['reason_codes'] ?? null) ? array_map('strval', $row['reason_codes']) : [],
                [ 'ambiguous' === (string) ($resolution['match_type'] ?? '') ? 'model_entity_ambiguous' : 'model_entity_not_found' ]
            )));
        }
        return $row;
    }

    private function model_owner_cache_key(string $owner): string {
        return $this->repository->normalize_keyword($owner);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function candidate_from_row(array $row, string $pool, string $save_mode, ?string $status = null): array {
        $candidate = [
            'keyword' => (string) ($row['normalized_keyword'] ?? $row['keyword'] ?? ''),
            'intent_type' => $pool,
            'entity_type' => $this->entity_type_for_pool($pool),
            'entity_id' => $this->entity_id_for_pool($row, $pool),
            'status' => $status ?: $this->status_for_row($row, $save_mode, false),
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
    private function status_for_row(array $row, string $save_mode, bool $full_batch = false): string {
        $strategy = (string) ($row['model_keyword_strategy'] ?? '');
        $scope = (string) ($row['model_keyword_usage_scope'] ?? '');
        $primary = (string) ($row['model_keyword_primary_candidate'] ?? '');
        $has_owner = '' !== (string) ($row['model_keyword_owner'] ?? '');
        $entity_id = $this->entity_id_for_pool($row, 'model');
        $needs_model_entity = $has_owner && in_array($scope, [ 'model_bio_only', 'model_page_only' ], true);

        if ($needs_model_entity && $entity_id <= 0) {
            return 'queued_for_review';
        }
        if ($full_batch && 'model' === (string) ($row['pool'] ?? 'model')) {
            if (ModelKeywordStrategyClassifier::STRATEGY_NOT_MODEL === $strategy || 'reject_not_model_intent' === (string) ($row['model_keyword_recommended_action'] ?? '')) {
                return 'rejected';
            }
            if (ModelKeywordStrategyClassifier::STRATEGY_DEFERRED_PHASE_2 === $strategy) {
                return 'queued_for_review';
            }
            if (in_array($strategy, [ ModelKeywordStrategyClassifier::STRATEGY_NAMED_MODEL, ModelKeywordStrategyClassifier::STRATEGY_LJ_NAMED_MODEL ], true) && 'yes' === $primary && 'model_bio_only' === $scope) {
                return 'approved';
            }
            return 'queued_for_review';
        }
        if ('manual_review' === $scope) {
            return 'queued_for_review';
        }
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
        if ('category' === $pool) { return max(0, (int) ($row['entity_id'] ?? 0)); }
        if (array_key_exists('post_id', $row) && '' !== (string) $row['post_id']) { return max(0, (int) $row['post_id']); }
        return max(0, (int) ($row['entity_id'] ?? 0));
    }

    private function entity_type_for_pool(string $pool): string {
        if ('video' === $pool) { return 'post'; }
        if ('category' === $pool) { return 'category'; }
        return 'model';
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function provenance_for_row(array $row, string $pool): array {
        $model_resolution = is_array($row['model_entity_resolution'] ?? null) ? $row['model_entity_resolution'] : [];
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
            'model_keyword_strategy' => (string) ($row['model_keyword_strategy'] ?? ''),
            'model_keyword_confidence' => (string) ($row['model_keyword_confidence'] ?? ''),
            'model_keyword_reason_codes' => is_array($row['model_keyword_reason_codes'] ?? null) ? array_values($row['model_keyword_reason_codes']) : [],
            'model_keyword_recommended_action' => (string) ($row['model_keyword_recommended_action'] ?? ''),
            'model_keyword_owner' => (string) ($row['model_keyword_owner'] ?? ''),
            'model_keyword_usage_scope' => (string) ($row['model_keyword_usage_scope'] ?? ''),
            'model_keyword_primary_candidate' => (string) ($row['model_keyword_primary_candidate'] ?? ''),
            'model_keyword_scope_reason_codes' => is_array($row['model_keyword_scope_reason_codes'] ?? null) ? array_values($row['model_keyword_scope_reason_codes']) : [],
            'personal_model_keyword_csv' => 'model' === $pool && '' !== (string) ($row['model_keyword_owner'] ?? ''),
            'model_entity_resolution' => $model_resolution,
            'model_entity_id' => (int) ($row['model_entity_id'] ?? 0),
            'model_entity_match_type' => (string) ($row['model_entity_match_type'] ?? ''),
            'model_entity_reason_codes' => is_array($row['model_entity_reason_codes'] ?? null) ? array_values($row['model_entity_reason_codes']) : [],
            'reason_codes' => is_array($row['reason_codes'] ?? null) ? array_values($row['reason_codes']) : [],
            'golden_formula_summary' => (string) ($row['golden_formula_summary'] ?? ''),
            'golden_missing_reasons' => is_array($row['golden_missing_reasons'] ?? null) ? array_values($row['golden_missing_reasons']) : [],
            'imported_from_keyword_pools' => true,
            'imported_at' => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function ensure_tmw_scored_row(array $row, string $pool): array {
        if (array_key_exists('tmw_score', $row) && array_key_exists('tmw_priority', $row)) { return $row; }
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
            'model_name' => (string) ($row['model_name'] ?? $row['model_keyword_owner'] ?? ''),
            'model_keyword_owner' => (string) ($row['model_keyword_owner'] ?? ''),
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
