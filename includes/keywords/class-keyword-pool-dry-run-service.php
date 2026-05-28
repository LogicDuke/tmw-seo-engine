<?php
/**
 * Shared dry-run normalizer and classifier for keyword pool import previews.
 *
 * @package TMWSEO\Engine\Keywords
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

/**
 * Produces keyword pool preview rows without persisting imported data.
 */
class KeywordPoolDryRunService {

    /** @var array<int, string> */
    private const POOLS = [ 'model', 'video', 'category' ];

    /** @var array<int, string> */
    private const LIFECYCLE_STATUSES = [
        'new',
        'discovered',
        'scored',
        'queued_for_review',
        'approved',
        'rejected',
        'ignored',
    ];

    /**
     * Run a deterministic dry-run preview for one target keyword pool.
     *
     * @param array<int|string, mixed> $parsed_rows Parser rows or a full parser result containing rows.
     * @param string                  $pool Target pool: model, video, or category.
     * @return array<string, mixed>
     */
    public function dry_run(array $parsed_rows, string $pool): array {
        $pool = strtolower(trim($pool));
        if (! in_array($pool, self::POOLS, true)) {
            $pool = 'category';
        }

        $rows       = isset($parsed_rows['rows']) && is_array($parsed_rows['rows']) ? $parsed_rows['rows'] : $parsed_rows;
        $preview    = [];
        $seen       = [];
        $summary    = [
            'total_rows'       => 0,
            'accepted'         => 0,
            'review_required'  => 0,
            'rejected'         => 0,
            'duplicates'       => 0,
            'invalid_keywords' => 0,
            'blocked'          => 0,
        ];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $preview_row = $this->build_preview_row($row, $pool, (int) $index + 2);
            $normalized  = $preview_row['normalized_keyword'];

            if ('' !== $normalized) {
                if (isset($seen[$normalized])) {
                    $preview_row['is_duplicate_in_upload'] = true;
                    $preview_row['duplicate_of_row']       = $seen[$normalized];
                    $preview_row['reason_codes'][]         = 'duplicate_in_upload';
                    if (! in_array($preview_row['decision'], [ 'reject', 'block' ], true)) {
                        $preview_row['validation_state'] = 'review_required';
                        $preview_row['decision']         = 'review_required';
                    }
                } else {
                    $seen[$normalized] = $preview_row['row_number'];
                }
            }

            $preview_row['reason_codes']   = array_values(array_unique($preview_row['reason_codes']));
            $preview_row['reason_summary'] = $this->summarize_reasons($preview_row['reason_codes']);
            $preview[]                     = $preview_row;

            ++$summary['total_rows'];
            if ($preview_row['is_duplicate_in_upload']) {
                ++$summary['duplicates'];
            }
            if ('' === $normalized) {
                ++$summary['invalid_keywords'];
            }
            if ('accept' === $preview_row['decision']) {
                ++$summary['accepted'];
            } elseif ('reject' === $preview_row['decision']) {
                ++$summary['rejected'];
            } elseif ('block' === $preview_row['decision']) {
                ++$summary['blocked'];
            } else {
                ++$summary['review_required'];
            }
        }

        return [
            'pool'    => $pool,
            'summary' => $summary,
            'rows'    => $preview,
        ];
    }

    /**
     * Backward-friendly alias for callers that prefer run().
     *
     * @param array<int|string, mixed> $parsed_rows Parser rows or full parser result.
     * @return array<string, mixed>
     */
    public function run(array $parsed_rows, string $pool): array {
        return $this->dry_run($parsed_rows, $pool);
    }

    /**
     * @param array<string, mixed> $row Parsed canonical row.
     * @return array<string, mixed>
     */
    private function build_preview_row(array $row, string $pool, int $fallback_row_number): array {
        $keyword            = $this->clean_text($row['keyword'] ?? '');
        $normalized_keyword = $this->normalize_keyword($keyword);
        $reason_codes       = [];

        $preview = [
            'row_number'             => isset($row['row_number']) ? (int) $row['row_number'] : $fallback_row_number,
            'pool'                   => $pool,
            'keyword'                => $keyword,
            'normalized_keyword'     => $normalized_keyword,
            'status_preview'         => $this->normalize_status($row['status'] ?? '', $reason_codes),
            'validation_state'       => 'valid',
            'decision'               => 'accept',
            'reason_codes'           => [],
            'reason_summary'         => '',
            'volume'                 => $this->normalize_metric($row['volume'] ?? '', 'volume', $reason_codes),
            'difficulty'             => $this->normalize_metric($row['difficulty'] ?? '', 'difficulty', $reason_codes),
            'cpc'                    => $this->normalize_metric($row['cpc'] ?? '', 'cpc', $reason_codes),
            'competition'            => $this->normalize_metric($row['competition'] ?? '', 'competition', $reason_codes),
            'intent'                 => $this->normalize_token($row['intent'] ?? ''),
            'source'                 => $this->normalize_source($row['source'] ?? ''),
            'model_name'             => $this->clean_text($row['model_name'] ?? ''),
            'category'               => $this->clean_text($row['category'] ?? ''),
            'post_id'                => $this->normalize_post_id($row['post_id'] ?? ''),
            'url'                    => $this->clean_text($row['url'] ?? ''),
            'slug'                   => $this->normalize_slug($row['slug'] ?? ''),
            'title'                  => $this->clean_text($row['title'] ?? ''),
            'notes'                  => $this->clean_text($row['notes'] ?? ''),
            'is_duplicate_in_upload' => false,
            'duplicate_of_row'       => null,
        ];

        if ('' === $normalized_keyword) {
            $preview['validation_state'] = 'invalid';
            $preview['decision']         = 'reject';
            $reason_codes[]              = 'missing_keyword';
        } elseif ($this->is_summary_or_footer_row($preview)) {
            $preview['validation_state'] = 'blocked';
            $preview['decision']         = 'block';
            $reason_codes[]              = 'summary_or_footer_row';
        } else {
            $pool_decision = $this->classify_for_pool($preview, $pool);
            $preview       = array_merge($preview, $pool_decision);
            $reason_codes  = array_merge($reason_codes, $pool_decision['reason_codes']);
        }

        $preview['reason_codes']   = array_values(array_unique($reason_codes));
        $preview['reason_summary'] = $this->summarize_reasons($preview['reason_codes']);

        return $preview;
    }

    /**
     * @param array<string, mixed> $row Preview row.
     * @return array<string, mixed>
     */
    private function classify_for_pool(array $row, string $pool): array {
        $keyword    = (string) $row['normalized_keyword'];
        $model_name = $this->normalize_keyword((string) $row['model_name']);

        if ('video' === $pool) {
            if ('' !== $model_name && $keyword === $model_name) {
                return $this->classification('invalid', 'reject', [ 'standalone_model_name', 'video_intent_required' ]);
            }
            if ($this->has_any($keyword, [ 'video', 'videos', 'webcam video', 'clip', 'clips', 'session', 'scene', 'watch', 'stream' ])) {
                return $this->classification('valid', 'accept', [ 'video_intent_detected' ]);
            }
            return $this->classification('review_required', 'review_required', [ 'video_intent_required' ]);
        }

        if ('model' === $pool) {
            if ($this->has_any($keyword, [ 'category', 'categories', 'browse', 'archive', 'topic' ])) {
                return $this->classification('review_required', 'review_required', [ 'category_intent_detected' ]);
            }
            if ($this->has_any($keyword, [ 'video', 'videos', 'clip', 'clips', 'session', 'scene', 'watch' ])) {
                return $this->classification('review_required', 'review_required', [ 'video_intent_detected' ]);
            }
            if ('' !== $model_name && str_contains($keyword, $model_name)) {
                return $this->classification('valid', 'accept', [ 'model_entity_detected' ]);
            }
            if ($this->has_any($keyword, [ 'model', 'models', 'profile', 'bio', 'biography', 'performer', 'talent', 'cam girl', 'webcam model' ])) {
                return $this->classification('valid', 'accept', [ 'model_intent_detected' ]);
            }
            return $this->classification('review_required', 'review_required', [ 'model_intent_unclear' ]);
        }

        if ($this->has_any($keyword, [ 'video', 'clip', 'session', 'scene' ])) {
            return $this->classification('review_required', 'review_required', [ 'video_intent_detected' ]);
        }
        if ('' !== $model_name && $keyword === $model_name) {
            return $this->classification('review_required', 'review_required', [ 'standalone_model_name' ]);
        }
        if ($this->has_any($keyword, [ 'category', 'categories', 'browse', 'archive', 'topic', 'models', 'webcam models', 'blonde', 'brunette', 'teen', 'mature' ])) {
            return $this->classification('valid', 'accept', [ 'category_intent_detected' ]);
        }

        return $this->classification('review_required', 'review_required', [ 'category_intent_unclear' ]);
    }

    /**
     * Detect CSV footer, summary, and metric-only rows before pool classification.
     *
     * @param array<string, mixed> $row Preview row.
     */
    private function is_summary_or_footer_row(array $row): bool {
        $keyword = $this->reporting_label_key((string) ($row['normalized_keyword'] ?? ''));
        if ('' === $keyword) {
            return false;
        }

        if ($this->is_reporting_label($keyword)) {
            return true;
        }

        if ($this->row_has_large_metric($row) && preg_match('/^(?:grand\s+)?total(?:s)?\s+(?:volume|keywords?|searches|results|rows|traffic)$/', $keyword)) {
            return true;
        }

        return false;
    }

    private function is_reporting_label(string $keyword): bool {
        $labels = [
            'total',
            'totals',
            'total volume',
            'grand total',
            'subtotal',
            'sub total',
            'summary',
            'all keywords total',
            'all keyword total',
            'keywords total',
            'keyword total',
            'total keywords',
            'total keyword',
            'overall total',
            'report total',
        ];

        if (in_array($keyword, $labels, true)) {
            return true;
        }

        return (bool) preg_match('/^(?:grand\s+)?total(?:s)?$/', $keyword);
    }

    /**
     * @param array<string, mixed> $row Preview row.
     */
    private function row_has_large_metric(array $row): bool {
        foreach ([ 'volume', 'difficulty', 'cpc', 'competition' ] as $metric) {
            $value = $row[$metric] ?? null;
            if (is_int($value) || is_float($value)) {
                if ($value >= 1000) {
                    return true;
                }
            }
        }

        return false;
    }

    private function reporting_label_key(string $keyword): string {
        $keyword = strtolower($this->clean_text($keyword));
        $keyword = preg_replace('/[^a-z0-9]+/', ' ', $keyword) ?? $keyword;
        $keyword = preg_replace('/\s+/', ' ', $keyword) ?? $keyword;
        return trim($keyword);
    }

    /**
     * @param array<int, string> $reasons Reason codes.
     * @return array<string, mixed>
     */
    private function classification(string $state, string $decision, array $reasons): array {
        return [
            'validation_state' => $state,
            'decision'         => $decision,
            'reason_codes'     => $reasons,
        ];
    }

    /**
     * @param mixed             $value Raw status.
     * @param array<int,string> $reason_codes Mutable reason list.
     */
    private function normalize_status($value, array &$reason_codes): string {
        $status = strtolower(trim((string) $value));
        $status = str_replace([ '-', ' ' ], '_', $status);
        if ('' === $status) {
            return 'new';
        }
        if (in_array($status, self::LIFECYCLE_STATUSES, true)) {
            return $status;
        }
        $reason_codes[] = 'unknown_status_defaulted_to_new';
        return 'new';
    }

    /**
     * @param mixed             $value Raw metric.
     * @param string            $metric Metric name.
     * @param array<int,string> $reason_codes Mutable reason list.
     * @return int|float|null
     */
    private function normalize_metric($value, string $metric, array &$reason_codes) {
        $raw = trim((string) $value);
        if ('' === $raw) {
            return null;
        }

        $numeric = str_replace([ ',', '$', '%' ], '', $raw);
        if (! is_numeric($numeric)) {
            $reason_codes[] = 'invalid_' . $metric;
            return null;
        }

        $number = (float) $numeric;
        if ('volume' === $metric) {
            return (int) round(max(0, $number));
        }
        return $number;
    }

    private function normalize_keyword(string $keyword): string {
        $keyword = strtolower($this->clean_text($keyword));
        $keyword = preg_replace('/\s+/', ' ', $keyword) ?? $keyword;
        return trim($keyword);
    }

    private function clean_text($value): string {
        $value = (string) $value;
        if (function_exists('wp_strip_all_tags')) {
            $value = wp_strip_all_tags($value);
        } else {
            $value = strip_tags($value);
        }
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return trim($value);
    }

    private function normalize_token($value): string {
        $value = strtolower($this->clean_text($value));
        return str_replace(' ', '_', $value);
    }

    private function normalize_source($value): string {
        $value = strtolower($this->clean_text($value));
        $value = preg_replace('/[^a-z0-9_\-\. ]/', '', $value) ?? $value;
        $value = preg_replace('/\s+/', '_', $value) ?? $value;
        return trim($value, '_');
    }

    private function normalize_slug($value): string {
        $value = strtolower($this->clean_text($value));
        $value = preg_replace('/[^a-z0-9\-]+/', '-', $value) ?? $value;
        return trim($value, '-');
    }

    private function normalize_post_id($value): int {
        $value = trim((string) $value);
        if ('' === $value || ! ctype_digit($value)) {
            return 0;
        }
        return max(0, (int) $value);
    }

    /**
     * @param array<int, string> $needles Needles.
     */
    private function has_any(string $haystack, array $needles): bool {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, string> $reason_codes Reason codes.
     */
    private function summarize_reasons(array $reason_codes): string {
        if ([] === $reason_codes) {
            return 'No dry-run warnings.';
        }
        return implode(', ', array_values(array_unique($reason_codes)));
    }
}
