<?php
/**
 * Deterministic model keyword strategy classifier for keyword pool previews.
 *
 * @package TMWSEO\Engine\Keywords
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

/**
 * Classifies Model Pool keywords into named, LiveJasmin-named, fallback, review, reject, or Phase 2 buckets.
 */
class ModelKeywordStrategyClassifier {

    public const STRATEGY_NAMED_MODEL = 'named_model_opportunity';
    public const STRATEGY_LJ_NAMED_MODEL = 'lj_named_model_opportunity';
    public const STRATEGY_FALLBACK_MODEL = 'fallback_model_intent';
    public const STRATEGY_WEAK_REVIEW = 'weak_manual_review';
    public const STRATEGY_NOT_MODEL = 'not_model_intent';
    public const STRATEGY_DEFERRED_PHASE_2 = 'deferred_phase_2_performer_expansion';
    public const STRATEGY_NOT_APPLICABLE = 'not_applicable';

    /** @var array<int, string> */
    private const BIG_PERFORMER_EXPANSIONS = [
        'dani daniels',
        'natasha nice',
        'valentina nappi',
        'cherie deville',
        'dillion harper',
        'romi rain',
        'eva lovia',
        'phoenix marie',
        'jessa rhodes',
        'kenzie taylor',
    ];

    /** @var array<int, string> */
    private const FALLBACK_MODIFIERS = [
        'webcam model',
        'cam model',
        'live webcam model',
        'live cam model',
        'livejasmin model',
        'jasmin model',
        'live cam profile',
        'webcam profile',
    ];

    /** @var array<int, string> */
    private const NOT_MODEL_PATTERNS = [
        'sex cam sites',
        'cam sites',
        'chat online',
        'webcam chat',
        'adult chat',
        'free adult webcam',
        'cheapest',
        'sites',
        'platform',
        'app',
        'creative live cam chat hd webcam',
    ];

    /** @var array<int, string> */
    private const CATEGORY_OR_SITE_TERMS = [
        'category',
        'categories',
        'browse',
        'archive',
        'topic',
        'site',
        'sites',
        'platform',
        'app',
        'chat online',
        'webcam chat',
        'adult chat',
    ];

    /** @var array<int, string> */
    private const UNSAFE_OR_ARCHIVE_TERMS = [
        'schoolgirl',
        'spy cam',
        'near me',
        'local webcam',
        'local cam',
        'footer',
        'subtotal',
        'total volume',
        'grand total',
    ];

    /**
     * @param array<string, mixed> $row Normalized keyword row.
     * @return array<string, mixed>
     */
    public function classify(array $row, string $model_name = '', string $pool = ''): array {
        $pool        = $this->normalize_token($pool ?: (string) ($row['pool'] ?? $row['intent_type'] ?? $row['intent'] ?? ''));
        $intent_type = $this->normalize_token((string) ($row['intent_type'] ?? $row['intent'] ?? ''));
        $keyword     = $this->normalize_keyword((string) ($row['normalized_keyword'] ?? $row['keyword'] ?? ''));
        $model_name  = $this->normalize_keyword($model_name ?: (string) ($row['model_name'] ?? ''));

        if ('model' !== $pool && 'model' !== $intent_type) {
            return $this->result(self::STRATEGY_NOT_APPLICABLE, 'none', [], '');
        }

        if ('' === $keyword) {
            return $this->result(self::STRATEGY_WEAK_REVIEW, 'none', [ 'weak_model_signal', 'manual_review_required' ], 'queue_for_manual_review');
        }

        $has_model_match = $this->has_model_name_match($keyword, $model_name, $row);
        $has_source_lj   = $this->has_livejasmin_source($row);
        $has_lj_modifier = $this->has_livejasmin_modifier($keyword);
        $metrics         = $this->metrics($row);

        if ($this->is_standalone_big_performer($keyword) && ! $this->is_current_lj_model($row)) {
            return $this->result(
                self::STRATEGY_DEFERRED_PHASE_2,
                'medium',
                [ 'post_50_lj_model_expansion', 'defer_big_performer_model' ],
                'defer_until_lj_50_model_milestone'
            );
        }

        if ($this->is_not_model_intent($keyword, $has_model_match)) {
            return $this->result(
                self::STRATEGY_NOT_MODEL,
                'none',
                [ 'not_model_page_intent', 'category_or_site_intent' ],
                'reject_not_model_intent'
            );
        }

        if (ModelKeywordPoolClassifier::is_conditional_supporting_keyword($keyword)) {
            return $this->result(
                self::STRATEGY_WEAK_REVIEW,
                'medium',
                [ 'conditional_safe_supporting_live_keyword', 'not_primary_focus_keyword', 'manual_review_required' ],
                'queue_for_manual_review'
            );
        }

        if ($has_model_match && ($has_lj_modifier || $has_source_lj) && ! $this->has_disqualifying_named_intent($keyword)) {
            return $this->result(
                self::STRATEGY_LJ_NAMED_MODEL,
                $this->livejasmin_named_confidence($metrics),
                [ 'livejasmin_modifier', 'model_name_match', 'lj_model_search_demand' ],
                'approve_lj_named_model_keyword'
            );
        }

        if ($this->has_fallback_modifier($keyword) && ($has_model_match || $this->has_model_placeholder($keyword))) {
            return $this->result(
                self::STRATEGY_FALLBACK_MODEL,
                'medium',
                [ 'fallback_model_modifier', 'model_page_semantic_fit' ],
                'use_as_fallback_model_keyword'
            );
        }

        if ($this->is_named_model_candidate($keyword, $model_name, $has_model_match) && $this->has_named_demand($metrics) && ! $this->has_disqualifying_named_intent($keyword)) {
            return $this->result(
                self::STRATEGY_NAMED_MODEL,
                $metrics['volume'] >= 500 || $metrics['score'] >= 60 ? 'high' : 'medium',
                [ 'model_name_match', 'named_model_search_demand' ],
                'approve_named_model_keyword'
            );
        }

        return $this->result(
            self::STRATEGY_WEAK_REVIEW,
            'low',
            [ 'weak_model_signal', 'manual_review_required' ],
            'queue_for_manual_review'
        );
    }

    /** @return array<string, mixed> */
    private function result(string $strategy, string $confidence, array $reason_codes, string $action): array {
        return [
            'model_keyword_strategy' => $strategy,
            'model_keyword_confidence' => $confidence,
            'model_keyword_reason_codes' => array_values(array_unique(array_map('strval', $reason_codes))),
            'model_keyword_recommended_action' => $action,
        ];
    }

    /** @param array<string, mixed> $row @return array{volume:float, traffic:float, score:float} */
    private function metrics(array $row): array {
        $seo = $this->number($row['seo_score'] ?? null);
        $opportunity = $this->number($row['opportunity_score'] ?? $row['opportunity'] ?? null);
        return [
            'volume' => $this->number($row['volume'] ?? null) ?? 0.0,
            'traffic' => $this->number($row['traffic_value'] ?? null) ?? 0.0,
            'score' => max($seo ?? 0.0, $opportunity ?? 0.0),
        ];
    }

    /** @param array{volume:float, traffic:float, score:float} $metrics */
    private function has_named_demand(array $metrics): bool {
        return $metrics['volume'] >= 500 || $metrics['traffic'] > 0.0 || $metrics['score'] >= 60;
    }

    /** @param array{volume:float, traffic:float, score:float} $metrics */
    private function livejasmin_named_confidence(array $metrics): string {
        if ($metrics['volume'] >= 500 || $metrics['score'] >= 60 || $metrics['traffic'] > 0.0) {
            return 'high';
        }
        if ($metrics['volume'] >= 100 || $metrics['score'] >= 40) {
            return 'medium';
        }
        return 'low';
    }

    /** @param array<string, mixed> $row */
    private function has_model_name_match(string $keyword, string $model_name, array $row): bool {
        if ('' !== $model_name && $this->contains_phrase($keyword, $model_name)) {
            return true;
        }
        $mapping = $this->normalize_keyword((string) ($row['model_keyword_mapping'] ?? $row['model_alias'] ?? $row['mapped_model_name'] ?? ''));
        return '' !== $mapping && $this->contains_phrase($keyword, $mapping);
    }

    private function is_named_model_candidate(string $keyword, string $model_name, bool $has_model_match): bool {
        if ($has_model_match) {
            return true;
        }
        if ('' !== $model_name) {
            return false;
        }
        if ($this->has_disqualifying_named_intent($keyword)) {
            return false;
        }
        $parts = explode(' ', $keyword);
        return count($parts) >= 1 && count($parts) <= 2 && preg_match('/^[a-z][a-z0-9]*(?: [a-z][a-z0-9]*)?$/', $keyword) === 1;
    }

    private function has_disqualifying_named_intent(string $keyword): bool {
        return $this->has_any($keyword, array_merge(self::CATEGORY_OR_SITE_TERMS, self::UNSAFE_OR_ARCHIVE_TERMS, [ 'video', 'videos', 'clip', 'clips', 'session', 'scene', 'watch' ]));
    }

    private function is_not_model_intent(string $keyword, bool $has_model_match): bool {
        if ($has_model_match) {
            return false;
        }
        return $this->has_any($keyword, self::NOT_MODEL_PATTERNS);
    }

    private function has_livejasmin_modifier(string $keyword): bool {
        return preg_match('/(?:^|\s)(?:livejasmin|live\s+jasmin|jasmin|lj)(?:\s|$)/', $keyword) === 1;
    }

    private function has_fallback_modifier(string $keyword): bool {
        return $this->has_any($keyword, self::FALLBACK_MODIFIERS);
    }

    private function has_model_placeholder(string $keyword): bool {
        return $this->has_any($keyword, [ '{model}', '{{model}}', '{model_name}', '{{model_name}}', '[model]', '[model name]', '%model%' ]);
    }

    /** @param array<string, mixed> $row */
    private function has_livejasmin_source(array $row): bool {
        $source = strtolower(implode(' ', [
            $this->stringify($row['source'] ?? ''),
            $this->stringify($row['sources'] ?? ''),
            $this->stringify($row['notes'] ?? ''),
            $this->stringify($row['provenance'] ?? ''),
        ]));
        return str_contains($source, 'livejasmin') || preg_match('/\blj\b/', $source) === 1;
    }

    /** @param array<string, mixed> $row */
    private function is_current_lj_model(array $row): bool {
        foreach ([ 'current_lj_model', 'is_current_lj_model', 'livejasmin_current_model', 'lj_phase_1_model' ] as $key) {
            $value = $row[$key] ?? null;
            if (true === $value || 1 === $value || '1' === $value || 'yes' === strtolower((string) $value) || 'true' === strtolower((string) $value)) {
                return true;
            }
        }
        return false;
    }

    private function is_standalone_big_performer(string $keyword): bool {
        return in_array($keyword, self::BIG_PERFORMER_EXPANSIONS, true);
    }

    /** @param array<int, string> $needles */
    private function has_any(string $haystack, array $needles): bool {
        foreach ($needles as $needle) {
            if ('' !== $needle && str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function contains_phrase(string $keyword, string $phrase): bool {
        return preg_match('/(?:^|\s)' . preg_quote($phrase, '/') . '(?:\s|$)/', $keyword) === 1;
    }

    private function normalize_token(string $value): string {
        return strtolower(trim($value));
    }

    private function normalize_keyword(string $keyword): string {
        $keyword = html_entity_decode(strip_tags($keyword), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $keyword = strtolower($keyword);
        $keyword = preg_replace('/[\x{2018}\x{2019}\x{201A}\x{201B}]/u', "'", $keyword);
        $keyword = preg_replace('/[\x{201C}\x{201D}\x{201E}\x{201F}]/u', '"', (string) $keyword);
        $keyword = preg_replace('/[^\p{L}\p{N}\s\'"%{}\[\]_-]+/u', ' ', (string) $keyword);
        $keyword = preg_replace('/\s+/u', ' ', (string) $keyword);
        return trim((string) $keyword);
    }

    /** @param mixed $value */
    private function stringify($value): string {
        if (is_array($value)) {
            return (string) json_encode($value);
        }
        if (is_scalar($value) || null === $value) {
            return (string) $value;
        }
        return '';
    }

    /** @param mixed $value */
    private function number($value): ?float {
        if (null === $value || '' === $value) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $clean = preg_replace('/[^0-9.\-]+/', '', $value);
        if ('' === $clean || !is_numeric($clean)) {
            return null;
        }
        return (float) $clean;
    }
}
