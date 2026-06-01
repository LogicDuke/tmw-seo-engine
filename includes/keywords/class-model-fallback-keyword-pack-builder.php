<?php
/**
 * Preview-only fallback keyword pack builder for model keyword pools.
 *
 * @package TMWSEO\Engine\Keywords
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

if (!defined('ABSPATH')) { exit; }

class ModelFallbackKeywordPackBuilder {
    private ModelKeywordPoolClassifier $classifier;
    private ?KeywordPoolCandidateRepository $repository;

    private const GENERATED_TEMPLATES = [
        '{model_name} livejasmin model',
        '{model_name} webcam model',
        '{model_name} cam model',
        '{model_name} adult video chat model',
        '{model_name} private chat',
        '{model_name} video chat',
        '{model_name} live cam profile',
        '{model_name} model bio',
        '{model_name} cam show',
        '{model_name} livejasmin profile',
        '{model_name} adult video chat profile',
        '{model_name} private show',
        '{model_name} live show',
    ];

    public function __construct(ModelKeywordPoolClassifier $classifier, ?KeywordPoolCandidateRepository $repository = null) {
        $this->classifier = $classifier;
        $this->repository = $repository;
    }

    public function keyword_data_strength(array $approved_personal_keywords): string {
        $count = count($this->normalize_list($approved_personal_keywords));
        if ($count >= 3) { return 'strong'; }
        if ($count >= 1) { return 'medium'; }
        return 'low';
    }

    /** @return array<string,mixed> */
    public function build_preview(int $entity_id, string $model_name, array $approved_personal_keywords, array $available_short_variables): array {
        $normalized_model_name = ModelKeywordPoolClassifier::normalize_phrase($model_name);
        $personal = $this->normalize_list($approved_personal_keywords);
        $variables = $this->normalize_list($available_short_variables);
        $generated = $this->generated_patterns($normalized_model_name);
        $strength = $this->keyword_data_strength($personal);
        $reason_codes = [];

        $safe_variables = $this->safe_variables($variables);
        $body_safe_variables = array_values(array_filter($safe_variables, function (string $phrase): bool {
            $class = $this->classifier->classify($phrase);
            return in_array($class['suggested_usage'], [ ModelKeywordPoolClassifier::USAGE_BODY_SEMANTIC_ONLY, ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, ModelKeywordPoolClassifier::USAGE_PRIMARY_FOCUS_ALLOWED ], true);
        }));
        $core_variables = array_values(array_filter($safe_variables, function (string $phrase): bool {
            return $this->classifier->classify($phrase)['keyword_class'] === ModelKeywordPoolClassifier::CLASS_CORE_MODEL_TERM;
        }));
        $intent_variables = array_values(array_filter($safe_variables, function (string $phrase): bool {
            return in_array($this->classifier->classify($phrase)['keyword_class'], [ ModelKeywordPoolClassifier::CLASS_INTENT_TERM, ModelKeywordPoolClassifier::CLASS_PLATFORM_INTENT_TERM ], true);
        }));

        $primary = '';
        $extra = [];
        $body = [];
        $anchors = [];
        $fallback = [];

        if ('strong' === $strength) {
            $primary = $personal[0] ?? '';
            $extra = array_slice(array_merge(array_slice($personal, 1), $core_variables), 0, 4);
            $body = array_slice($this->dedupe(array_merge($personal, $body_safe_variables)), 0, 20);
            $anchors = array_slice($this->dedupe(array_merge($this->model_variants($normalized_model_name), $intent_variables)), 0, 10);
            $reason_codes[] = 'strong_personal_keyword_data';
        } elseif ('medium' === $strength) {
            $primary = $personal[0] ?? '';
            $extra = array_slice($this->dedupe(array_merge(array_slice($personal, 1), $generated)), 0, 4);
            $body = array_slice($this->dedupe(array_merge($personal, $safe_variables, $generated)), 0, 20);
            $anchors = array_slice($this->dedupe(array_merge($this->model_variants($normalized_model_name), $intent_variables)), 0, 10);
            $fallback = $generated;
            $reason_codes = array_merge($reason_codes, [ 'medium_personal_keyword_data', 'fallback_fill_applied' ]);
        } else {
            $primary = $generated[0] ?? '';
            $extra = array_slice($generated, 1, 4);
            $body = array_slice($this->dedupe(array_merge($generated, $safe_variables)), 0, 20);
            $anchors = array_slice($this->model_variants($normalized_model_name), 0, 10);
            $fallback = $generated;
            $reason_codes = array_merge($reason_codes, [ 'low_keyword_data_fallback_only', 'all_generated_queued_for_review' ]);
        }

        $classification_preview = [];
        foreach ($this->dedupe(array_merge($personal, $variables, $generated)) as $phrase) {
            $classification_preview[$phrase] = in_array($phrase, $generated, true)
                ? $this->classifier->classify($phrase, [ 'is_generated' => true ])
                : $this->classifier->classify($phrase, [ 'model_name' => $normalized_model_name ]);
        }

        return [
            'entity_id' => $entity_id,
            'model_name' => $normalized_model_name,
            'keyword_data_strength' => $strength,
            'personal_keyword_count' => count($personal),
            'primary_keyword_recommendation' => $primary,
            'extra_focus_candidates' => $this->dedupe($extra),
            'body_semantic_keywords' => $this->dedupe($body),
            'internal_link_anchors' => $this->dedupe($anchors),
            'fallback_generated_patterns' => $this->dedupe($fallback),
            'classification_preview' => $classification_preview,
            'reason_codes' => $this->dedupe($reason_codes),
            'preview_only' => true,
        ];
    }

    /** @return array<int,string> */
    private function generated_patterns(string $model_name): array {
        if ('' === $model_name) { return []; }
        $patterns = [];
        foreach (self::GENERATED_TEMPLATES as $template) {
            $phrase = ModelKeywordPoolClassifier::normalize_phrase(str_replace('{model_name}', $model_name, $template));
            if ('' !== $phrase) { $patterns[] = $phrase; }
        }
        return $this->dedupe($patterns);
    }

    /** @return array<int,string> */
    private function safe_variables(array $variables): array {
        return array_values(array_filter($variables, function (string $phrase): bool {
            $class = $this->classifier->classify($phrase);
            return $class['keyword_class'] !== ModelKeywordPoolClassifier::CLASS_UNSAFE_STANDALONE;
        }));
    }

    /** @return array<int,string> */
    private function model_variants(string $model_name): array {
        if ('' === $model_name) { return []; }
        return $this->dedupe([ $model_name, $model_name . ' livejasmin', 'livejasmin ' . $model_name, $model_name . ' model', $model_name . ' profile' ]);
    }

    /** @return array<int,string> */
    private function normalize_list(array $phrases): array {
        $normalized = [];
        foreach ($phrases as $phrase) {
            if (!is_scalar($phrase)) { continue; }
            $value = ModelKeywordPoolClassifier::normalize_phrase((string) $phrase);
            if ('' !== $value) { $normalized[] = $value; }
        }
        return $this->dedupe($normalized);
    }

    /** @template T @param array<int,T> $items @return array<int,T> */
    private function dedupe(array $items): array {
        $seen = [];
        $deduped = [];
        foreach ($items as $item) {
            $key = is_scalar($item) || null === $item ? (string) $item : json_encode($item);
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $deduped[] = $item;
        }
        return $deduped;
    }
}
