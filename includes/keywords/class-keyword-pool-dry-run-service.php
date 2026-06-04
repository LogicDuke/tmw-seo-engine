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

    /** @var array<int, string> */
    private const ARCHIVE_KEYWORDS = [
        'schoolgirl roleplay',
        'spy cam shows',
        'free video chat',
        'online video chat',
        'free cam chat',
        'webcam models near me',
        'cam models near me',
        'local webcam models',
        'local cam models',
        'local webcam girls',
        'local cam girls',
        'webcam girls near me',
        'cam girls near me',
        'new cam models',
        'featured webcam models',
        'real webcam models',
        'premium webcam models',
        'verified webcam models',
    ];

    /**
     * Run a deterministic dry-run preview for one target keyword pool.
     *
     * @param array<int|string, mixed> $parsed_rows Parser rows or a full parser result containing rows.
     * @param string                  $pool Target pool: model, video, or category.
     * @return array<string, mixed>
     */
    public function dry_run(array $parsed_rows, string $pool, array $context = []): array {
        $pool = strtolower(trim($pool));
        if (! in_array($pool, self::POOLS, true)) {
            $pool = 'category';
        }

        $rows       = isset($parsed_rows['rows']) && is_array($parsed_rows['rows']) ? $parsed_rows['rows'] : $parsed_rows;
        $is_global_model_pool = 'model' === $pool && $this->is_global_model_pool_context($context);
        $inferred_model_context = 'model' === $pool && !$is_global_model_pool ? $this->infer_model_context_from_rows($rows) : '';
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

            $preview_row = $this->build_preview_row($row, $pool, (int) $index + 2, $inferred_model_context, $is_global_model_pool);
            $normalized  = $preview_row['normalized_keyword'];

            if ('' !== $normalized) {
                if (isset($seen[$normalized])) {
                    $preview_row['is_duplicate_in_upload'] = true;
                    $preview_row['duplicate_of_row']       = $seen[$normalized];
                    $preview_row['reason_codes'][]         = 'duplicate_in_upload';
                    $preview_row['priority_preview']       = 'Archive';
                    if (! in_array($preview_row['decision'], [ 'reject', 'block' ], true)) {
                        $preview_row['validation_state'] = 'review_required';
                        $preview_row['decision']         = 'review_required';
                    }
                } else {
                    $seen[$normalized] = $preview_row['row_number'];
                }
            }

            $preview_row['reason_codes']       = array_values(array_unique($preview_row['reason_codes']));
            $preview_row['priority_preview']   = $this->priority_preview($preview_row);
            $preview_row['is_golden_keyword']       = $this->is_golden_keyword($preview_row);
            $preview_row['golden_missing_reasons']  = $this->golden_missing_reasons($preview_row);
            $preview_row['golden_formula_summary']  = $this->golden_formula_summary();
            $preview_row['recommended_action']      = $this->recommended_action($preview_row);
            $preview_row['reason_summary']          = $this->summarize_reasons($preview_row['reason_codes']);
            $preview_row = array_merge($preview_row, (new KeywordPoolMetricsScorer())->score($preview_row, $pool));
            $preview[]                         = $preview_row;

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
            'inferred_model_context' => $inferred_model_context,
            'rows'    => $preview,
        ];
    }


    /** @param array<string,mixed> $context */
    private function is_global_model_pool_context(array $context): bool {
        return !empty($context['is_global_model_pool']) || ('global' === (string) ($context['target_type'] ?? '') && 'global-model-pool' === (string) ($context['target_slug'] ?? ''));
    }

    /**
     * Backward-friendly alias for callers that prefer run().
     *
     * @param array<int|string, mixed> $parsed_rows Parser rows or full parser result.
     * @return array<string, mixed>
     */
    public function run(array $parsed_rows, string $pool, array $context = []): array {
        return $this->dry_run($parsed_rows, $pool, $context);
    }

    /**
     * @param array<string, mixed> $row Parsed canonical row.
     * @return array<string, mixed>
     */
    private function build_preview_row(array $row, string $pool, int $fallback_row_number, string $inferred_model_context = '', bool $is_global_model_pool = false): array {
        $keyword            = $this->clean_text($row['keyword'] ?? '');
        $explicit_model_name = $this->clean_text($row['model_name'] ?? '');
        $model_name = '' !== $explicit_model_name ? $explicit_model_name : ('model' === $pool ? $inferred_model_context : '');
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
            'priority_preview'          => 'P3',
            'is_golden_keyword'         => false,
            'recommended_action'        => 'queue_for_review',
            'commercial_score_preview'  => 0,
            'golden_missing_reasons'  => [],
            'golden_formula_summary'  => $this->golden_formula_summary(),
            'volume'                 => $this->normalize_optional_metric($row, 'volume', $reason_codes),
            'difficulty'             => $this->normalize_optional_metric($row, 'difficulty', $reason_codes),
            'cpc'                    => $this->normalize_optional_metric($row, 'cpc', $reason_codes),
            'competition'            => $this->normalize_optional_metric($row, 'competition', $reason_codes),
            'seo_score'              => $this->normalize_optional_metric($row, 'seo_score', $reason_codes),
            'opportunity_score'      => $this->normalize_optional_metric($row, 'opportunity_score', $reason_codes),
            'traffic_value'          => $this->normalize_optional_metric($row, 'traffic_value', $reason_codes),
            'trend'                  => $this->clean_text($row['trend'] ?? ''),
            'trend_direction'        => $this->clean_text($row['trend_direction'] ?? ''),
            'ad_difficulty'          => $this->normalize_optional_metric($row, 'ad_difficulty', $reason_codes),
            'intent'                 => $this->normalize_token($row['intent'] ?? ''),
            'source'                 => $this->normalize_source($row['source'] ?? ''),
            'model_name'             => $model_name,
            'category'               => $this->clean_text($row['category'] ?? ''),
            'post_id'                => $this->normalize_post_id($row['post_id'] ?? ''),
            'url'                    => $this->clean_text($row['url'] ?? ''),
            'slug'                   => $this->normalize_slug($row['slug'] ?? ''),
            'title'                  => $this->clean_text($row['title'] ?? ''),
            'notes'                  => $this->clean_text($row['notes'] ?? ''),
            'model_keyword_strategy'            => '',
            'model_keyword_confidence'          => '',
            'model_keyword_reason_codes'        => [],
            'model_keyword_recommended_action'  => '',
            'model_keyword_owner'               => '',
            'model_keyword_usage_scope'         => 'not_applicable',
            'model_keyword_primary_candidate'   => 'no',
            'model_keyword_scope_reason_codes'  => [],
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
            $archive_decision = $this->classify_archive_keyword($preview);
            if ([] !== $archive_decision) {
                $preview      = array_merge($preview, $archive_decision);
                $reason_codes = array_merge($reason_codes, $archive_decision['reason_codes']);
            } else {
                $pool_decision = $this->classify_for_pool($preview, $pool);
                $preview       = array_merge($preview, $pool_decision);
                $reason_codes  = array_merge($reason_codes, $pool_decision['reason_codes']);
            }
        }

        if ($is_global_model_pool) {
            $preview['model_name'] = '';
            $preview['model_keyword_owner'] = '';
            $preview['model_keyword_usage_scope'] = 'global_model_pool';
            $preview['model_keyword_primary_candidate'] = 'no';
            $preview['model_keyword_scope_reason_codes'] = array_values(array_unique(array_merge(
                is_array($preview['model_keyword_scope_reason_codes'] ?? null) ? array_map('strval', $preview['model_keyword_scope_reason_codes']) : [],
                [ 'global_model_pool' ]
            )));
            $preview['post_id'] = 0;
            $preview['is_global_model_pool'] = true;
        }

        $preview['reason_codes']             = array_values(array_unique($reason_codes));
        $preview['priority_preview']         = $this->priority_preview($preview);
        $preview['is_golden_keyword']        = $this->is_golden_keyword($preview);
        $preview['golden_missing_reasons']   = $this->golden_missing_reasons($preview);
        $preview['golden_formula_summary']   = $this->golden_formula_summary();
        $preview['commercial_score_preview'] = $this->commercial_score($preview);
        $preview['kwe_opportunity_candidate'] = $this->is_kwe_opportunity_candidate($preview);
        $preview['recommended_action']       = $this->recommended_action($preview);
        $preview['reason_summary']           = $this->summarize_reasons($preview['reason_codes']);

        $preview = array_merge($preview, (new KeywordPoolMetricsScorer())->score($preview, $pool));
        $preview = $this->append_model_keyword_strategy($preview, $pool);
        if ($is_global_model_pool) {
            $preview['model_name'] = '';
            $preview['model_keyword_owner'] = '';
            $preview['model_keyword_usage_scope'] = 'global_model_pool';
            $preview['model_keyword_primary_candidate'] = 'no';
            $preview['model_keyword_scope_reason_codes'] = array_values(array_unique(array_merge(
                is_array($preview['model_keyword_scope_reason_codes'] ?? null) ? array_map('strval', $preview['model_keyword_scope_reason_codes']) : [],
                [ 'global_model_pool' ]
            )));
            $preview['post_id'] = 0;
            $preview['is_global_model_pool'] = true;
        }
        return $preview;
    }
    /**
     * @param array<string, mixed> $row Preview row.
     * @return array<string, mixed>
     */
    private function append_model_keyword_strategy(array $row, string $pool): array {
        $reason_codes = is_array($row['reason_codes'] ?? null) ? array_map('strval', $row['reason_codes']) : [];
        if (in_array('summary_or_footer_row', $reason_codes, true)) {
            $row['model_name'] = '';
            $row['model_keyword_strategy'] = '';
            $row['model_keyword_confidence'] = '';
            $row['model_keyword_reason_codes'] = [];
            $row['model_keyword_recommended_action'] = '';
            $row['model_keyword_owner'] = '';
            $row['model_keyword_usage_scope'] = 'not_applicable';
            $row['model_keyword_primary_candidate'] = 'no';
            $row['model_keyword_scope_reason_codes'] = [];
            return $row;
        }

        if ('model' !== $pool && 'model' !== (string) ($row['intent'] ?? '')) {
            $row['model_keyword_strategy'] = 'not_applicable';
            $row['model_keyword_confidence'] = 'none';
            $row['model_keyword_reason_codes'] = [];
            $row['model_keyword_recommended_action'] = '';
            $row['model_keyword_owner'] = '';
            $row['model_keyword_usage_scope'] = 'not_applicable';
            $row['model_keyword_primary_candidate'] = 'no';
            $row['model_keyword_scope_reason_codes'] = [];
            return $row;
        }

        $strategy = (new ModelKeywordStrategyClassifier())->classify($row, (string) ($row['model_name'] ?? ''), $pool);
        $row = array_merge($row, $strategy);
        $row = $this->append_model_keyword_scope($row, $pool);
        $reason_codes = is_array($strategy['model_keyword_reason_codes'] ?? null) ? array_map('strval', $strategy['model_keyword_reason_codes']) : [];

        if (ModelKeywordStrategyClassifier::STRATEGY_NOT_MODEL === (string) ($strategy['model_keyword_strategy'] ?? '')) {
            $row['validation_state'] = 'invalid';
            $row['decision'] = 'reject';
            $row['reason_codes'] = array_values(array_unique(array_merge(
                is_array($row['reason_codes'] ?? null) ? array_map('strval', $row['reason_codes']) : [],
                $reason_codes
            )));
            $row['reason_summary'] = $this->summarize_reasons($row['reason_codes']);
            $row = array_merge($row, (new KeywordPoolMetricsScorer())->score($row, $pool));
        } elseif (ModelKeywordStrategyClassifier::STRATEGY_WEAK_REVIEW === (string) ($strategy['model_keyword_strategy'] ?? '') && 'valid' === (string) ($row['validation_state'] ?? '')) {
            $row['validation_state'] = 'review_required';
            $row['decision'] = 'review_required';
            $row['reason_codes'] = array_values(array_unique(array_merge(
                is_array($row['reason_codes'] ?? null) ? array_map('strval', $row['reason_codes']) : [],
                $reason_codes
            )));
            $row['reason_summary'] = $this->summarize_reasons($row['reason_codes']);
            $row = array_merge($row, (new KeywordPoolMetricsScorer())->score($row, $pool));
        }

        return $row;
    }



    /**
     * Infer the owner for KWE-style personal model CSVs that have no Model column.
     *
     * @param array<int|string, mixed> $rows Parsed rows.
     */
    private function infer_model_context_from_rows(array $rows): string {
        $best = '';
        $best_score = -1.0;
        foreach ($rows as $row) {
            if (! is_array($row) || '' !== $this->clean_text($row['model_name'] ?? '')) {
                continue;
            }
            $keyword = $this->normalize_keyword((string) ($row['keyword'] ?? ''));
            if ($this->is_summary_or_footer_keyword($keyword) || ! $this->is_standalone_model_context_candidate($keyword)) {
                continue;
            }

            $volume = $this->metric_number($row['volume'] ?? null) ?? 0.0;
            $seo_score = $this->metric_number($row['seo_score'] ?? null) ?? 0.0;
            $opportunity_score = $this->metric_number($row['opportunity_score'] ?? null) ?? $this->metric_number($row['opportunity'] ?? null) ?? 0.0;
            $traffic_value = $this->metric_number($row['traffic_value'] ?? null) ?? 0.0;
            if ($volume < 100.0 && max($seo_score, $opportunity_score) < 40.0) {
                continue;
            }

            $score = ($volume * 1000000.0) + (max($seo_score, $opportunity_score) * 1000.0) + $traffic_value;
            if ($score > $best_score) {
                $best = $keyword;
                $best_score = $score;
            }
        }

        return $best;
    }

    private function is_standalone_model_context_candidate(string $keyword): bool {
        if ('' === $keyword) {
            return false;
        }
        $word_count = count(explode(' ', $keyword));
        if ($word_count < 1 || $word_count > 3) {
            return false;
        }
        if (preg_match('/^[a-z][a-z0-9]*(?: [a-z][a-z0-9]*){0,2}$/', $keyword) !== 1) {
            return false;
        }
        if (preg_match('/(?:^|\s)(?:livejasmin|live\s+jasmin|jasmin|lj|porn|cam|webcam|chat|live|sex|videos?|sites?|total|volume)(?:\s|$)/', $keyword) === 1) {
            return false;
        }

        return ! $this->has_any($keyword, [
            'category', 'categories', 'browse', 'archive', 'topic', 'platform', 'app',
            'model', 'models', 'profile', 'bio', 'performer', 'talent', 'free', 'best', 'cheap', 'cheapest',
        ]);
    }

    /** @param array<string, mixed> $row */
    private function append_model_keyword_scope(array $row, string $pool): array {
        if ('model' !== $pool) {
            $row['model_keyword_owner'] = '';
            $row['model_keyword_usage_scope'] = 'not_applicable';
            $row['model_keyword_primary_candidate'] = 'no';
            $row['model_keyword_scope_reason_codes'] = [];
            return $row;
        }

        $owner = $this->normalize_keyword((string) ($row['model_name'] ?? ''));
        $strategy = (string) ($row['model_keyword_strategy'] ?? '');
        $action = (string) ($row['model_keyword_recommended_action'] ?? '');
        $scope = 'not_applicable';
        $primary = 'no';
        $reasons = [];

        if ('' !== $owner) {
            $reasons[] = 'personal_model_keyword_csv';
        }

        if ('' !== $owner && ModelKeywordStrategyClassifier::STRATEGY_NAMED_MODEL === $strategy && 'approve_named_model_keyword' === $action) {
            $scope = 'model_bio_only';
            $primary = 'yes';
            $reasons = array_merge($reasons, [ 'model_specific_keyword', 'bio_primary_candidate' ]);
        } elseif ('' !== $owner && ModelKeywordStrategyClassifier::STRATEGY_LJ_NAMED_MODEL === $strategy && 'approve_lj_named_model_keyword' === $action) {
            $scope = 'model_bio_only';
            $primary = 'yes';
            $reasons = array_merge($reasons, [ 'model_specific_keyword', 'bio_primary_candidate' ]);
        } elseif (in_array($strategy, [ ModelKeywordStrategyClassifier::STRATEGY_NAMED_MODEL, ModelKeywordStrategyClassifier::STRATEGY_LJ_NAMED_MODEL ], true)) {
            $scope = 'manual_review';
        } elseif (ModelKeywordStrategyClassifier::STRATEGY_FALLBACK_MODEL === $strategy) {
            $scope = '' !== $owner ? 'model_page_only' : 'manual_review';
            $reasons[] = 'fallback_model_page_keyword';
        } elseif (ModelKeywordStrategyClassifier::STRATEGY_WEAK_REVIEW === $strategy) {
            $scope = 'manual_review';
        } elseif (ModelKeywordStrategyClassifier::STRATEGY_NOT_MODEL === $strategy) {
            $scope = 'not_model_eligible';
        } elseif (ModelKeywordStrategyClassifier::STRATEGY_DEFERRED_PHASE_2 === $strategy) {
            $scope = 'manual_review';
        }

        $row['model_keyword_owner'] = $owner;
        $row['model_keyword_usage_scope'] = $scope;
        $row['model_keyword_primary_candidate'] = $primary;
        $row['model_keyword_scope_reason_codes'] = array_values(array_unique(array_filter(array_map('strval', $reasons))));
        return $row;
    }

    private function metric_number($value): ?float {
        if ($this->is_blank_metric_value($value)) {
            return null;
        }
        $numeric = str_replace([ ',', '$', '%' ], '', $this->clean_metric_value((string) $value));
        return is_numeric($numeric) ? (float) $numeric : null;
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
        if ($this->has_any($keyword, [ 'category', 'categories', 'browse', 'archive', 'topic', 'model', 'models', 'cam model', 'cam models', 'webcam model', 'webcam models', 'cam girls', 'webcam girls', 'live cam', 'cam chat', 'cam shows', 'livejasmin', 'jasmin', 'couples live webcam', 'private cam shows', 'blonde', 'brunette', 'latina', 'lesbian', 'ebony', 'asian', 'indian', 'busty', 'teen', 'mature' ])) {
            return $this->classification('valid', 'accept', [ 'category_intent_detected' ]);
        }

        return $this->classification('review_required', 'review_required', [ 'category_intent_unclear' ]);
    }


    /**
     * @param array<string, mixed> $row Preview row.
     * @return array<string, mixed>
     */
    private function classify_archive_keyword(array $row): array {
        $keyword = (string) ($row['normalized_keyword'] ?? '');
        if ('' === $keyword) {
            return [];
        }

        $reasons = [];
        if (in_array($keyword, self::ARCHIVE_KEYWORDS, true)) {
            $reasons[] = 'archive_keyword';
        }
        if ('schoolgirl roleplay' === $keyword || 'spy cam shows' === $keyword) {
            $reasons[] = 'archive_keyword';
            $reasons[] = 'unsafe_keyword';
        }
        if ('schoolgirl roleplay' === $keyword) {
            $reasons[] = 'rename_recommended';
        }
        if ($this->has_any($keyword, [ ' near me', 'local webcam', 'local cam', 'local ' ])) {
            $reasons[] = 'archive_keyword';
            $reasons[] = 'geo_local_intent';
        }
        if ($this->has_any($keyword, [ 'free video chat', 'online video chat', 'free cam chat' ])) {
            $reasons[] = 'archive_keyword';
            $reasons[] = 'too_broad_low_commercial_intent';
        }
        if (0 === (int) ($row['volume'] ?? -1) && ! $this->has_strong_commercial_webcam_intent($keyword)) {
            $reasons[] = 'archive_keyword';
            $reasons[] = 'zero_volume_noise';
        }

        if ([] === $reasons) {
            return [];
        }

        return $this->classification('blocked', 'block', array_values(array_unique($reasons)));
    }

    /**
     * @param array<string, mixed> $row Preview row.
     */
    private function is_golden_keyword(array $row): bool {
        if (in_array((string) ($row['decision'] ?? ''), [ 'reject', 'block' ], true) || 'Archive' === (string) ($row['priority_preview'] ?? '')) {
            return false;
        }

        $volume      = $row['volume'] ?? null;
        $cpc         = $row['cpc'] ?? null;
        $competition = $row['competition'] ?? null;

        return is_int($volume)
            && $volume >= 500
            && is_numeric($competition)
            && (float) $competition < 0.20
            && is_numeric($cpc)
            && (float) $cpc >= 2.00;
    }


    /**
     * @param array<string, mixed> $row Preview row.
     * @return array<int, string>
     */
    private function golden_missing_reasons(array $row): array {
        if ($this->is_golden_keyword($row)) {
            return [];
        }

        $reasons     = [];
        $volume      = $row['volume'] ?? null;
        $cpc         = $row['cpc'] ?? null;
        $competition = $row['competition'] ?? null;

        if (! is_int($volume) || $volume < 500) {
            $reasons[] = 'volume_below_500';
        }

        if (! is_numeric($competition)) {
            $reasons[] = 'competition_missing';
        } elseif ((float) $competition >= 0.20) {
            $reasons[] = 'competition_not_below_0_20';
        }

        if (! is_numeric($cpc)) {
            $reasons[] = 'missing_cpc';
        } elseif ((float) $cpc < 2.00) {
            $reasons[] = 'cpc_below_2_00';
        }

        return $reasons;
    }

    private function golden_formula_summary(): string {
        return 'volume>=500, competition<0.20, cpc>=2.00';
    }

    /**
     * @param array<string, mixed> $row Preview row.
     */
    private function commercial_score(array $row): int {
        $keyword = (string) ($row['normalized_keyword'] ?? '');
        $volume  = is_int($row['volume'] ?? null) ? (int) $row['volume'] : 0;
        $cpc     = is_numeric($row['cpc'] ?? null) ? (float) $row['cpc'] : 0.0;

        $score = 0;
        if ($volume >= 1000) {
            $score += 35;
        } elseif ($volume >= 500) {
            $score += 25;
        } elseif ($volume >= 100) {
            $score += 15;
        } elseif ($volume > 0) {
            $score += 5;
        }

        if ($cpc >= 5.00) {
            $score += 35;
        } elseif ($cpc >= 3.00) {
            $score += 25;
        } elseif ($cpc >= 2.00) {
            $score += 15;
        } elseif ($cpc > 0) {
            $score += 5;
        }

        if ($this->has_strong_commercial_webcam_intent($keyword)) {
            $score += 30;
        } elseif ($this->has_any($keyword, [ 'cam', 'webcam', 'chat', 'shows', 'models' ])) {
            $score += 15;
        }

        if (ModelKeywordPoolClassifier::is_conditional_supporting_keyword($keyword)) {
            $score = min($score, 10);
        }

        return max(0, min(100, $score));
    }

    /**
     * @param array<string, mixed> $row Preview row.
     */
    private function priority_preview(array $row): string {
        $decision = (string) ($row['decision'] ?? '');
        $reasons  = is_array($row['reason_codes'] ?? null) ? $row['reason_codes'] : [];
        if (in_array($decision, [ 'reject', 'block' ], true) || ! empty($row['is_duplicate_in_upload']) || in_array('archive_keyword', $reasons, true) || in_array('summary_or_footer_row', $reasons, true)) {
            return 'Archive';
        }

        $keyword = (string) ($row['normalized_keyword'] ?? '');
        $volume  = is_int($row['volume'] ?? null) ? (int) $row['volume'] : null;
        $cpc     = is_numeric($row['cpc'] ?? null) ? (float) $row['cpc'] : null;

        if (ModelKeywordPoolClassifier::is_conditional_supporting_keyword($keyword)) {
            return 'P3';
        }

        if (null !== $volume && $volume >= 1000) {
            return 'P1';
        }
        if (null !== $volume && null !== $cpc && $cpc >= 3.00 && $volume >= 100 && $this->has_strong_commercial_webcam_intent($keyword)) {
            return 'P1';
        }
        if ((null !== $volume && $volume >= 100 && $volume <= 999) || (0 === $volume && $this->has_strong_long_tail_adult_webcam_relevance($keyword))) {
            return 'P2';
        }

        return 'P3';
    }

    /**
     * @param array<string, mixed> $row Preview row.
     */
    private function is_kwe_opportunity_candidate(array $row): bool {
        $seo_score = is_numeric($row['seo_score'] ?? null) ? (float) $row['seo_score'] : null;
        $opportunity_score = is_numeric($row['opportunity_score'] ?? null) ? (float) $row['opportunity_score'] : null;
        return (null !== $seo_score && $seo_score >= 60) || (null !== $opportunity_score && $opportunity_score >= 60);
    }

    /**
     * @param array<string, mixed> $row Preview row.
     */
    private function recommended_action(array $row): string {
        $decision = (string) ($row['decision'] ?? '');
        if ('block' === $decision || 'reject' === $decision) {
            return 'block' === $decision ? 'block_candidate' : 'archive_candidate';
        }
        if ('Archive' === (string) ($row['priority_preview'] ?? '')) {
            return 'archive_candidate';
        }
        if ('accept' === $decision && 'P1' === (string) ($row['priority_preview'] ?? '')) {
            return 'approve_candidate';
        }
        return 'queue_for_review';
    }

    private function has_strong_commercial_webcam_intent(string $keyword): bool {
        return $this->has_any($keyword, [
            'cam model',
            'cam models',
            'webcam model',
            'webcam models',
            'cam girls',
            'webcam girls',
            'live cam',
            'cam show',
            'cam shows',
            'private cam',
            'cam2cam',
            'livejasmin',
            'jasmin models',
            'couples live webcam',
            'live webcam',
        ]);
    }

    private function has_strong_long_tail_adult_webcam_relevance(string $keyword): bool {
        return $this->has_strong_commercial_webcam_intent($keyword)
            && str_word_count($keyword) >= 3
            && ! $this->has_any($keyword, [ 'free video chat', 'online video chat', 'near me', 'local ' ]);
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

        if ($this->is_summary_or_footer_keyword($keyword)) {
            return true;
        }

        if ($this->row_has_large_metric($row) && preg_match('/^(?:grand\s+)?total(?:s)?\s+(?:volume|keywords?|searches|results|rows|traffic)$/', $keyword)) {
            return true;
        }

        return false;
    }

    private function is_summary_or_footer_keyword(string $keyword): bool {
        $keyword = $this->reporting_label_key($keyword);
        if ('' === $keyword) {
            return false;
        }

        if ($this->is_reporting_label($keyword)) {
            return true;
        }

        foreach ([ 'total volume', 'grand total', 'subtotal', 'sub total', 'average', 'summary', 'showing' ] as $label) {
            if (str_contains($keyword, $label)) {
                return true;
            }
        }

        return preg_match('/(?:^|\s)(?:avg|total|totals|keyword|volume)(?:\s|$)/', $keyword) === 1
            && preg_match('/^(?:avg|average|grand total|keyword|showing|sub total|subtotal|summary|total|totals|volume)(?:\s|$)/', $keyword) === 1;
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
            'showing',
            'keyword',
            'volume',
            'average',
            'avg',
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
        foreach ([ 'volume', 'difficulty', 'cpc', 'competition', 'seo_score', 'traffic_value', 'ad_difficulty' ] as $metric) {
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
     * @param array<string, mixed> $row Parsed canonical row.
     * @param string               $metric Metric name.
     * @param array<int,string>    $reason_codes Mutable reason list.
     * @return int|float|null
     */
    private function normalize_optional_metric(array $row, string $metric, array &$reason_codes) {
        if (! array_key_exists($metric, $row)) {
            return null;
        }

        return $this->normalize_metric($row[$metric], $metric, $reason_codes);
    }

    /**
     * @param mixed             $value Raw metric.
     * @param string            $metric Metric name.
     * @param array<int,string> $reason_codes Mutable reason list.
     * @return int|float|null
     */
    private function normalize_metric($value, string $metric, array &$reason_codes) {
        if ($this->is_blank_metric_value($value)) {
            return null;
        }

        $raw     = $this->clean_metric_value((string) $value);
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

    private function is_blank_metric_value($value): bool {
        if (null === $value) {
            return true;
        }

        if (is_float($value) && is_nan($value)) {
            return true;
        }

        $cleaned = strtolower($this->clean_metric_value((string) $value));
        return '' === $cleaned || in_array($cleaned, [ 'nan', 'n/a', 'na', 'null' ], true);
    }

    private function clean_metric_value(string $value): string {
        $value = preg_replace('/[\p{Z}\s]+/u', ' ', $value) ?? $value;
        return trim($value);
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
        $summary = implode(', ', array_values(array_unique($reason_codes)));
        if (in_array('rename_recommended', $reason_codes, true)) {
            $summary .= '. Use "uniform roleplay cam girls" instead.';
        }
        return $summary;
    }
}
