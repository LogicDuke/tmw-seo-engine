<?php
/**
 * Safe dry-run/apply service for PR 602 keyword candidate classification metadata.
 *
 * @package TMWSEO\Engine\Keywords
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

if (!defined('ABSPATH')) { exit; }

/**
 * Backfills keyword_class metadata into existing model keyword candidate rows.
 */
class KeywordPoolClassificationApplyService {
    private const DEFAULT_CLASSIFIED_BY = 'pr603_keyword_pool_classification_audit';
    private const ALLOWED_FILTERS = [ 'missing', 'all', 'unlinked', 'unsafe', 'unknown' ];

    private KeywordPoolCandidateRepository $repository;
    private ModelKeywordPoolClassifier $classifier;

    public function __construct(KeywordPoolCandidateRepository $repository, ModelKeywordPoolClassifier $classifier) {
        $this->repository = $repository;
        $this->classifier = $classifier;
    }

    /** @return array<string,mixed> */
    public function summary(): array {
        $summary = $this->empty_summary();
        if (!$this->repository->table_exists()) {
            return $summary;
        }

        global $wpdb;
        $rows = $wpdb->get_results("SELECT id, entity_id, sources FROM " . $this->repository->table_name() . " WHERE intent_type = 'model'", ARRAY_A);
        if (!is_array($rows)) {
            return $summary;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $summary['total_model_rows']++;
            if ((int) ($row['entity_id'] ?? 0) === 0) {
                $summary['unlinked_entity_id_zero']++;
            }

            $sources_raw = (string) ($row['sources'] ?? '');
            if (!$this->is_already_classified($sources_raw)) {
                $summary['missing_classification']++;
                continue;
            }

            $summary['already_classified']++;
            $sources = $this->decode_sources($sources_raw);
            $class = (string) ($sources['keyword_class'] ?? '');
            if ('' !== $class) {
                $summary['by_keyword_class'][$class] = (int) ($summary['by_keyword_class'][$class] ?? 0) + 1;
            }
            $usage = (string) ($sources['suggested_usage'] ?? '');
            if ('' !== $usage) {
                $summary['by_suggested_usage'][$usage] = (int) ($summary['by_suggested_usage'][$usage] ?? 0) + 1;
            }
            if (array_key_exists('standalone_allowed', $sources)) {
                if ((bool) $sources['standalone_allowed']) {
                    $summary['standalone_allowed_yes']++;
                } else {
                    $summary['standalone_allowed_no']++;
                }
            }
        }

        ksort($summary['by_keyword_class']);
        ksort($summary['by_suggested_usage']);
        return $summary;
    }

    /** @return array<string,mixed> */
    public function dry_run_batch(int $offset = 0, int $batch_size = 50, string $filter = 'missing'): array {
        $offset = max(0, $offset);
        $batch_size = $this->clamp_batch_size($batch_size, 50);
        $filter = in_array($filter, self::ALLOWED_FILTERS, true) ? $filter : 'missing';

        $matched = [];
        if ($this->repository->table_exists()) {
            foreach ($this->model_rows() as $row) {
                if ($this->row_matches_filter($row, $filter)) {
                    $matched[] = $row;
                }
            }
        }

        $slice = array_slice($matched, $offset, $batch_size);
        $preview_rows = [];
        foreach ($slice as $row) {
            $context_info = $this->resolve_model_name_context($row);
            $classification = $this->classifier->classify((string) ($row['keyword'] ?? ''), $context_info['context']);
            $reason_codes = array_values(array_map('strval', is_array($classification['reason_codes'] ?? null) ? $classification['reason_codes'] : []));
            $sources_raw = (string) ($row['sources'] ?? '');
            $preview_rows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'keyword' => (string) ($row['keyword'] ?? ''),
                'current_status' => (string) ($row['status'] ?? ''),
                'entity_type' => (string) ($row['entity_type'] ?? ''),
                'entity_id' => (int) ($row['entity_id'] ?? 0),
                'current_sources_snippet' => $this->sources_snippet($sources_raw),
                'model_name_context' => $context_info['model_name'],
                'already_classified' => $this->is_already_classified($sources_raw),
                'proposed_keyword_class' => (string) ($classification['keyword_class'] ?? ''),
                'proposed_suggested_usage' => (string) ($classification['suggested_usage'] ?? ''),
                'proposed_standalone_allowed' => (bool) ($classification['standalone_allowed'] ?? false),
                'proposed_reason_codes' => implode(', ', $reason_codes),
                'proposed_confidence' => (string) ($classification['confidence'] ?? ''),
            ];
        }

        return [
            'rows' => $preview_rows,
            'total' => count($matched),
            'offset' => $offset,
            'batch_size' => $batch_size,
            'filter' => $filter,
            'dry_run' => true,
        ];
    }

    /** @return array<int,int> */
    public function fetch_missing_ids(int $batch_size = 100): array {
        $batch_size = $this->clamp_batch_size($batch_size, 100);
        if (!$this->repository->table_exists()) {
            return [];
        }
        $ids = [];
        foreach ($this->model_rows() as $row) {
            if (!$this->is_already_classified((string) ($row['sources'] ?? ''))) {
                $ids[] = (int) ($row['id'] ?? 0);
            }
            if (count($ids) >= $batch_size) {
                break;
            }
        }
        return array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
    }

    /** @param array<int,int|string> $candidate_ids @return array<string,int> */
    public function apply_batch(array $candidate_ids, string $classified_by = self::DEFAULT_CLASSIFIED_BY): array {
        $result = [
            'scanned' => 0,
            'classified' => 0,
            'skipped_already_classified' => 0,
            'skipped_not_model' => 0,
            'skipped_empty' => 0,
            'errors' => 0,
        ];

        $candidate_ids = array_values(array_filter(array_map('intval', $candidate_ids), static fn(int $id): bool => $id > 0));
        if (count($candidate_ids) > 250) {
            $result['errors'] = 1;
            return $result;
        }
        $candidate_ids = array_values(array_unique($candidate_ids));
        if (!$this->repository->table_exists()) {
            if ($candidate_ids !== []) {
                $result['errors'] = count($candidate_ids);
            }
            return $result;
        }

        foreach ($candidate_ids as $candidate_id) {
            $result['scanned']++;
            $row = $this->fetch_row_by_id($candidate_id);
            if (!is_array($row)) {
                $result['errors']++;
                continue;
            }
            if ((string) ($row['intent_type'] ?? '') !== 'model') {
                $result['skipped_not_model']++;
                continue;
            }
            if ($this->is_already_classified((string) ($row['sources'] ?? ''))) {
                $result['skipped_already_classified']++;
                continue;
            }
            $keyword = trim((string) ($row['keyword'] ?? ''));
            if ('' === $keyword) {
                $result['skipped_empty']++;
                continue;
            }

            $context_info = $this->resolve_model_name_context($row);
            $applied = $this->repository->classify_candidate_phrase($keyword, $context_info['context'], true, (int) ($row['id'] ?? 0));
            if (empty($applied['applied'])) {
                $result['errors']++;
                continue;
            }
            if (!$this->ensure_classified_by((int) ($row['id'] ?? 0), $classified_by)) {
                $result['errors']++;
                continue;
            }
            $result['classified']++;
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function empty_summary(): array {
        return [
            'total_model_rows' => 0,
            'already_classified' => 0,
            'missing_classification' => 0,
            'by_keyword_class' => [],
            'by_suggested_usage' => [],
            'standalone_allowed_yes' => 0,
            'standalone_allowed_no' => 0,
            'unlinked_entity_id_zero' => 0,
        ];
    }

    private function clamp_batch_size(int $batch_size, int $default): int {
        if ($batch_size <= 0) {
            $batch_size = $default;
        }
        return min(250, max(1, $batch_size));
    }

    /** @return array<int,array<string,mixed>> */
    private function model_rows(): array {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM " . $this->repository->table_name() . " WHERE intent_type = 'model' ORDER BY id ASC", ARRAY_A);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @param array<string,mixed> $row */
    private function row_matches_filter(array $row, string $filter): bool {
        $sources_raw = (string) ($row['sources'] ?? '');
        $already = $this->is_already_classified($sources_raw);
        if ('all' === $filter) {
            return true;
        }
        if ('missing' === $filter) {
            return !$already;
        }
        if ('unlinked' === $filter) {
            return (int) ($row['entity_id'] ?? 0) === 0;
        }

        $context_info = $this->resolve_model_name_context($row);
        $classification = $this->classifier->classify((string) ($row['keyword'] ?? ''), $context_info['context']);
        $class = (string) ($classification['keyword_class'] ?? '');
        if ('unsafe' === $filter) {
            return ModelKeywordPoolClassifier::CLASS_UNSAFE_STANDALONE === $class;
        }
        if ('unknown' === $filter) {
            return ModelKeywordPoolClassifier::CLASS_UNKNOWN_REVIEW === $class;
        }
        return !$already;
    }

    /** @param array<string,mixed> $row @return array{context:array<string,string>,model_name:string} */
    private function resolve_model_name_context(array $row): array {
        $model_name = '';
        $entity_id = (int) ($row['entity_id'] ?? 0);
        $entity_type = (string) ($row['entity_type'] ?? '');
        if ($entity_id > 0 && 'model' === $entity_type && function_exists('get_post')) {
            $post = get_post($entity_id);
            if ($post instanceof \WP_Post && (string) ($post->post_type ?? '') === 'model') {
                $model_name = trim((string) ($post->post_title ?? ''));
            }
        }

        if ('' === $model_name) {
            $sources = $this->decode_sources((string) ($row['sources'] ?? ''));
            $owner = trim((string) ($sources['model_keyword_owner'] ?? ''));
            if ('' !== $owner && $this->keyword_contains_owner((string) ($row['keyword'] ?? ''), $owner)) {
                $model_name = $owner;
            }
        }

        return [
            'context' => '' !== $model_name ? [ 'model_name' => $model_name ] : [],
            'model_name' => $model_name,
        ];
    }

    private function keyword_contains_owner(string $keyword, string $owner): bool {
        $keyword = ModelKeywordPoolClassifier::normalize_phrase($keyword);
        $owner = ModelKeywordPoolClassifier::normalize_phrase($owner);
        return '' !== $owner && preg_match('/(?:^|\s)' . preg_quote($owner, '/') . '(?:\s|$)/u', $keyword) === 1;
    }

    private function is_already_classified(string $sources_raw): bool {
        return false !== strpos($sources_raw, '"keyword_class"') || false !== strpos($sources_raw, "'keyword_class'");
    }

    /** @return array<string,mixed> */
    private function decode_sources(string $sources_raw): array {
        if ('' === trim($sources_raw)) {
            return [];
        }
        $decoded = json_decode($sources_raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function sources_snippet(string $sources_raw): string {
        $snippet = trim($sources_raw);
        if (function_exists('mb_substr')) {
            return mb_substr($snippet, 0, 240, 'UTF-8');
        }
        return substr($snippet, 0, 240);
    }

    /** @return array<string,mixed>|null */
    private function fetch_row_by_id(int $candidate_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->repository->table_name() . ' WHERE id = %d LIMIT 1', $candidate_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private function ensure_classified_by(int $candidate_id, string $classified_by): bool {
        global $wpdb;
        $row = $this->fetch_row_by_id($candidate_id);
        if (!is_array($row)) {
            return false;
        }
        $sources = $this->decode_sources((string) ($row['sources'] ?? ''));
        $sources['keyword_classified_by'] = '' !== $classified_by ? $classified_by : self::DEFAULT_CLASSIFIED_BY;
        $data = [ 'sources' => function_exists('wp_json_encode') ? wp_json_encode($sources) : json_encode($sources) ];
        $data['updated_at'] = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
        return false !== $wpdb->update($this->repository->table_name(), $data, [ 'id' => $candidate_id ]);
    }
}
