<?php
/**
 * Model keyword pool phrase classifier.
 *
 * @package TMWSEO\Engine\Keywords
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

if (!defined('ABSPATH')) { exit; }

class ModelKeywordPoolClassifier {
    public const CLASS_PERSONAL_MODEL_KEYWORD = 'personal_model_keyword';
    public const CLASS_CORE_MODEL_TERM = 'core_model_term';
    public const CLASS_PLATFORM_TERM = 'platform_term';
    public const CLASS_PLATFORM_INTENT_TERM = 'platform_intent_term';
    public const CLASS_INTENT_TERM = 'intent_term';
    public const CLASS_ATTRIBUTE_TERM = 'attribute_term';
    public const CLASS_GEO_LANGUAGE_TERM = 'geo_language_term';
    public const CLASS_FEATURE_MODIFIER = 'feature_modifier';
    public const CLASS_UNSAFE_STANDALONE = 'unsafe_standalone_modifier';
    public const CLASS_GENERATED_LONGTAIL = 'generated_longtail';
    public const CLASS_UNKNOWN_REVIEW = 'unknown_review';

    public const USAGE_PRIMARY_FOCUS_ALLOWED = 'primary_focus_allowed';
    public const USAGE_SECONDARY_FOCUS_ALLOWED = 'secondary_focus_allowed';
    public const USAGE_BODY_SEMANTIC_ONLY = 'body_semantic_only';
    public const USAGE_MODIFIER_ONLY = 'modifier_only';
    public const USAGE_REVIEW_REQUIRED = 'review_required';

    private const UNSAFE_STANDALONE_TERMS = [ 'video', 'videos', 'chat', 'photos', 'pictures', 'search', 'home', 'live', 'top', 'new', 'real', 'information', 'page', 'bio', 'profile' ];
    private const PLATFORM_TERMS = [ 'livejasmin', 'jasmin', 'chaturbate', 'camsoda', 'cam4', 'bongacams', 'myfreecams', 'flirt4free', 'imlive', 'jerkmate', 'stripchat' ];
    private const CORE_MODEL_TERMS = [ 'webcam model', 'cam model', 'adult video chat model', 'live cam model', 'live webcam model', 'livejasmin model', 'jasmin model', 'cam girl', 'webcam girl', 'webcam performer', 'cam performer' ];
    private const PLATFORM_INTENT_TERMS = [ 'adult video chat', 'jasmin video chat', 'livejasmin video chat', 'jasmin live', 'livejasmin live' ];
    private const INTENT_TERMS = [ 'private chat', 'video chat', 'live chat', 'cam show', 'cam shows', 'live show', 'live shows', 'private show', 'private shows', 'cam session', 'model bio', 'live cam profile', 'webcam profile', 'cam profile', 'model gallery', 'model profile' ];
    private const ATTRIBUTE_TERMS = [ 'brunette', 'blonde', 'redhead', 'ebony', 'petite', 'curvy', 'bbw', 'mature', 'milf', 'slim', 'tattooed', 'busty', 'fit' ];
    private const GEO_LANGUAGE_TERMS = [ 'latina', 'latin', 'colombian', 'brazilian', 'russian', 'european', 'asian', 'indian', 'filipina', 'korean', 'japanese', 'chinese', 'thai', 'spanish', 'french', 'german', 'arabic', 'arab', 'romanian' ];
    private const FEATURE_MODIFIER_TERMS = [ 'cam2cam', 'c2c', 'lovense', 'hd cam', 'hd webcam', 'interactive toy', 'lovense toy', 'tip controlled', 'tip menu' ];

    /** @return array{keyword:string,keyword_class:string,standalone_allowed:bool,suggested_usage:string,reason_codes:array<int,string>,confidence:string} */
    public function classify(string $phrase, array $context = []): array {
        $keyword = self::normalize_phrase($phrase);
        if ('' === $keyword) {
            return $this->result($keyword, self::CLASS_UNKNOWN_REVIEW, false, self::USAGE_REVIEW_REQUIRED, [ 'empty_phrase' ], 'low');
        }

        $model_name = self::normalize_phrase((string) ($context['model_name'] ?? ''));
        if ('' !== $model_name) {
            if ($keyword === $model_name) {
                return $this->result($keyword, self::CLASS_PERSONAL_MODEL_KEYWORD, true, self::USAGE_PRIMARY_FOCUS_ALLOWED, [ 'model_name_exact_match' ], 'high');
            }
            if ($this->contains_phrase($keyword, $model_name) && ($this->contains_any($keyword, self::PLATFORM_TERMS) || $this->contains_any($keyword, self::CORE_MODEL_TERMS))) {
                return $this->result($keyword, self::CLASS_PERSONAL_MODEL_KEYWORD, true, self::USAGE_PRIMARY_FOCUS_ALLOWED, [ 'model_name_plus_platform_or_core_term' ], 'high');
            }
        }

        if (in_array($keyword, self::UNSAFE_STANDALONE_TERMS, true)) {
            return $this->result($keyword, self::CLASS_UNSAFE_STANDALONE, false, self::USAGE_MODIFIER_ONLY, [ 'unsafe_standalone_exact_match' ], 'high');
        }

        if (!empty($context['is_generated']) && $this->is_multi_word($keyword)) {
            return $this->result($keyword, self::CLASS_GENERATED_LONGTAIL, false, self::USAGE_REVIEW_REQUIRED, [ 'generated_longtail_flagged' ], 'high');
        }

        if ($this->contains_any($keyword, self::CORE_MODEL_TERMS)) {
            return $this->result($keyword, self::CLASS_CORE_MODEL_TERM, true, self::USAGE_PRIMARY_FOCUS_ALLOWED, [ 'core_model_term_match' ], 'high');
        }
        if (in_array($keyword, self::PLATFORM_TERMS, true)) {
            return $this->result($keyword, self::CLASS_PLATFORM_TERM, false, self::USAGE_MODIFIER_ONLY, [ 'platform_term_exact' ], 'high');
        }
        if ($this->contains_any($keyword, self::PLATFORM_INTENT_TERMS)) {
            return $this->result($keyword, self::CLASS_PLATFORM_INTENT_TERM, false, self::USAGE_BODY_SEMANTIC_ONLY, [ 'platform_intent_term_match' ], 'high');
        }
        if (in_array($keyword, self::INTENT_TERMS, true)) {
            return $this->result($keyword, self::CLASS_INTENT_TERM, false, self::USAGE_BODY_SEMANTIC_ONLY, [ 'intent_term_match' ], 'high');
        }
        if (in_array($keyword, self::ATTRIBUTE_TERMS, true)) {
            return $this->result($keyword, self::CLASS_ATTRIBUTE_TERM, false, self::USAGE_MODIFIER_ONLY, [ 'attribute_term_exact' ], 'high');
        }
        if (in_array($keyword, self::GEO_LANGUAGE_TERMS, true)) {
            return $this->result($keyword, self::CLASS_GEO_LANGUAGE_TERM, false, self::USAGE_MODIFIER_ONLY, [ 'geo_language_term_exact' ], 'high');
        }
        if (in_array($keyword, self::FEATURE_MODIFIER_TERMS, true)) {
            return $this->result($keyword, self::CLASS_FEATURE_MODIFIER, false, self::USAGE_MODIFIER_ONLY, [ 'feature_modifier_exact' ], 'high');
        }

        return $this->result($keyword, self::CLASS_UNKNOWN_REVIEW, false, self::USAGE_REVIEW_REQUIRED, [ 'no_rule_matched' ], 'low');
    }

    public static function normalize_phrase(string $phrase): string {
        $phrase = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($phrase) : strip_tags($phrase);
        $phrase = html_entity_decode($phrase, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $phrase = strtolower($phrase);
        $phrase = preg_replace('/\s+/u', ' ', (string) $phrase);
        $phrase = trim((string) $phrase);
        $phrase = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', (string) $phrase);
        $phrase = preg_replace('/\s+/u', ' ', (string) $phrase);
        return trim((string) $phrase);
    }

    private function contains_any(string $keyword, array $needles): bool {
        foreach ($needles as $needle) {
            if ($this->contains_phrase($keyword, $needle)) { return true; }
        }
        return false;
    }

    private function contains_phrase(string $keyword, string $needle): bool {
        return preg_match('/(?:^|\s)' . preg_quote($needle, '/') . '(?:\s|$)/u', $keyword) === 1;
    }

    private function is_multi_word(string $keyword): bool {
        return preg_match('/\s/u', $keyword) === 1;
    }

    private function result(string $keyword, string $class, bool $standalone_allowed, string $usage, array $reason_codes, string $confidence): array {
        return [
            'keyword' => $keyword,
            'keyword_class' => $class,
            'standalone_allowed' => $standalone_allowed,
            'suggested_usage' => $usage,
            'reason_codes' => $reason_codes,
            'confidence' => $confidence,
        ];
    }
}
