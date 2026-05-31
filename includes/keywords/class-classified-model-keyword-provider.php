<?php
/**
 * Approved classified keyword provider for model Generate keyword packs.
 *
 * @package TMWSEO\Engine\Keywords
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class ClassifiedModelKeywordProvider {

    private const TABLE_SUFFIX = 'tmw_keyword_candidates';

    /** @var array<int,string> */
    private const PRIMARY_CLASSES = [
        ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD,
        ModelKeywordPoolClassifier::CLASS_CORE_MODEL_TERM,
    ];

    /** @return array{primary_candidates:array<int,string>,extra_focus_candidates:array<int,string>,body_semantic_candidates:array<int,string>,modifier_candidates:array<int,string>,excluded_candidates:array<int,string>,sources:array<string,mixed>} */
    public function build_for_model(int $model_post_id, string $model_name): array {
        $empty = $this->empty_fragment();
        if ($model_post_id <= 0) {
            return $empty;
        }

        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->prefix)) {
            return $empty;
        }

        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        if (method_exists($wpdb, 'get_var') && method_exists($wpdb, 'esc_like') && method_exists($wpdb, 'prepare')) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if (!is_string($found) || strtolower($found) !== strtolower($table)) {
                return $empty;
            }
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, keyword, intent_type, entity_type, entity_id, status, sources FROM ' . $table . ' WHERE intent_type = %s AND entity_type = %s AND entity_id = %d AND status = %s ORDER BY id ASC',
                'model',
                'model',
                $model_post_id,
                'approved'
            ),
            defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'
        );
        if (!is_array($rows) || empty($rows)) {
            return $empty;
        }

        $primary_personal = [];
        $primary_fallback = [];
        $extra = [];
        $body = [];
        $modifiers = [];
        $excluded = [];
        $source_rows = [];
        $normalized_model = self::normalize_phrase($model_name);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $keyword = self::normalize_phrase((string) ($row['keyword'] ?? ''));
            $sources = $this->decode_sources($row['sources'] ?? null);
            $keyword_class = (string) $this->source_value($sources, 'keyword_class');
            $suggested_usage = (string) $this->source_value($sources, 'suggested_usage');
            $standalone_allowed = $this->source_bool($sources, 'standalone_allowed');
            $reason = '';
            $admitted_live_intent_review_keyword = false;

            // PR-615: status='approved' is explicit human sign-off. If keyword_class
            // metadata is missing, default to safe values rather than sending the keyword
            // to excluded_candidates (which would actively block it from rankmath_additional).
            if ($keyword_class === '' && (string) ($row['status'] ?? '') === 'approved') {
                $keyword_class   = ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD;
                $suggested_usage = $suggested_usage !== ''
                    ? $suggested_usage
                    : ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED;
                $standalone_allowed = $standalone_allowed ?? true;
            }

            if ($keyword === '') {
                $reason = 'empty_keyword';
            } elseif ($keyword_class === '') {
                $reason = 'missing_keyword_class';
            } elseif (!$this->owner_matches($sources, $keyword, $normalized_model)) {
                $reason = 'wrong_model_keyword_owner';
            } elseif (!$this->scope_is_compatible($sources)) {
                $reason = 'incompatible_model_keyword_usage_scope';
            } elseif ($this->is_focus_excluded($keyword_class, $suggested_usage, $standalone_allowed)) {
                if ($this->is_approved_model_linked_live_intent_extra($row, $model_post_id, $keyword, $normalized_model, $keyword_class, $suggested_usage, $standalone_allowed)) {
                    $admitted_live_intent_review_keyword = true;
                } else {
                    $reason = 'excluded_from_focus_by_classification';
                }
            }

            if ($reason !== '') {
                if ($keyword !== '') {
                    $excluded[] = $keyword;
                    $source_rows[] = $this->source_summary($row, $sources, $reason);
                }
                continue;
            }

            if (in_array($keyword_class, self::PRIMARY_CLASSES, true)
                && $suggested_usage === ModelKeywordPoolClassifier::USAGE_PRIMARY_FOCUS_ALLOWED
                && $standalone_allowed === true
            ) {
                if ($keyword_class === ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD) {
                    $primary_personal[] = $keyword;
                } else {
                    $primary_fallback[] = $keyword;
                }
            }

            if ($this->is_model_focus_extra_candidate($keyword_class, $suggested_usage) || $admitted_live_intent_review_keyword) {
                $extra[] = $keyword;
            }

            if ($admitted_live_intent_review_keyword) {
                $this->log_live_intent_review_keyword_admitted($row, $keyword, $keyword_class, $suggested_usage, $standalone_allowed);
            }

            if (in_array($keyword_class, [ ModelKeywordPoolClassifier::CLASS_PLATFORM_INTENT_TERM, ModelKeywordPoolClassifier::CLASS_INTENT_TERM ], true)
                && $suggested_usage === ModelKeywordPoolClassifier::USAGE_BODY_SEMANTIC_ONLY
            ) {
                $body[] = $keyword;
            }

            if (in_array($keyword_class, [ ModelKeywordPoolClassifier::CLASS_ATTRIBUTE_TERM, ModelKeywordPoolClassifier::CLASS_GEO_LANGUAGE_TERM, ModelKeywordPoolClassifier::CLASS_FEATURE_MODIFIER ], true)
                && $suggested_usage === ModelKeywordPoolClassifier::USAGE_MODIFIER_ONLY
            ) {
                $modifiers[] = $keyword;
            }

            $source_rows[] = $this->source_summary(
                $row,
                $sources,
                $admitted_live_intent_review_keyword ? 'included_model_live_intent_review_required' : 'included'
            );
        }

        return [
            'primary_candidates'       => self::dedupe(array_merge($primary_personal, $primary_fallback)),
            'extra_focus_candidates'   => self::dedupe($extra),
            'body_semantic_candidates' => self::dedupe($body),
            'modifier_candidates'      => self::dedupe($modifiers),
            'excluded_candidates'      => self::dedupe($excluded),
            'sources'                  => [
                'provider' => 'classified_model_keyword_provider',
                'entity_id' => $model_post_id,
                'rows' => $source_rows,
            ],
        ];
    }

    /** @return array{primary_candidates:array<int,string>,extra_focus_candidates:array<int,string>,body_semantic_candidates:array<int,string>,modifier_candidates:array<int,string>,excluded_candidates:array<int,string>,sources:array<string,mixed>} */
    private function empty_fragment(): array {
        return [
            'primary_candidates' => [],
            'extra_focus_candidates' => [],
            'body_semantic_candidates' => [],
            'modifier_candidates' => [],
            'excluded_candidates' => [],
            'sources' => [],
        ];
    }

    private function is_focus_excluded(string $keyword_class, string $suggested_usage, ?bool $standalone_allowed): bool {
        if (in_array($keyword_class, [ ModelKeywordPoolClassifier::CLASS_UNSAFE_STANDALONE, ModelKeywordPoolClassifier::CLASS_UNKNOWN_REVIEW ], true)) {
            return true;
        }
        if ($suggested_usage === ModelKeywordPoolClassifier::USAGE_REVIEW_REQUIRED) {
            return !in_array($keyword_class, self::PRIMARY_CLASSES, true);
        }
        return $standalone_allowed === false && in_array($suggested_usage, [ ModelKeywordPoolClassifier::USAGE_PRIMARY_FOCUS_ALLOWED, ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED ], true);
    }


    /** @param array<string,mixed> $row */
    private function is_approved_model_linked_live_intent_extra(array $row, int $model_post_id, string $keyword, string $normalized_model, string $keyword_class, string $suggested_usage, ?bool $standalone_allowed): bool {
        if ((string) ($row['status'] ?? '') !== 'approved') {
            return false;
        }
        if ((string) ($row['intent_type'] ?? '') !== 'model' || (string) ($row['entity_type'] ?? '') !== 'model') {
            return false;
        }
        if ((int) ($row['entity_id'] ?? 0) !== $model_post_id || $normalized_model === '') {
            return false;
        }
        if ($keyword_class !== ModelKeywordPoolClassifier::CLASS_UNKNOWN_REVIEW) {
            return false;
        }
        if ($suggested_usage !== ModelKeywordPoolClassifier::USAGE_REVIEW_REQUIRED || $standalone_allowed !== false) {
            return false;
        }
        if (!self::contains_phrase($keyword, $normalized_model)) {
            return false;
        }
        if ($this->contains_adult_fallback_term($keyword)) {
            return false;
        }
        return $this->is_safe_model_live_intent_phrase($keyword, $normalized_model);
    }

    private function is_safe_model_live_intent_phrase(string $keyword, string $normalized_model): bool {
        if ($normalized_model === '') {
            return false;
        }
        $safe_phrases = [
            $normalized_model . ' live',
            $normalized_model . ' live chat',
            $normalized_model . ' live cam',
        ];
        return in_array($keyword, $safe_phrases, true);
    }

    private function contains_adult_fallback_term(string $keyword): bool {
        return preg_match('/(?:^|\s)(?:porn|porno|adult|sex|xxx|nude|nudes|naked)(?:\s|$)/u', $keyword) === 1;
    }

    /** @param array<string,mixed> $row */
    private function log_live_intent_review_keyword_admitted(array $row, string $keyword, string $keyword_class, string $suggested_usage, ?bool $standalone_allowed): void {
        if (!class_exists(Logs::class) || !method_exists(Logs::class, 'info')) {
            return;
        }
        Logs::info('keywords', '[TMW-SEO-KW] Approved model-linked live-intent keyword admitted despite review classification', [
            'id' => (int) ($row['id'] ?? 0),
            'keyword' => $keyword,
            'intent_type' => (string) ($row['intent_type'] ?? ''),
            'entity_type' => (string) ($row['entity_type'] ?? ''),
            'entity_id' => (int) ($row['entity_id'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'keyword_class' => $keyword_class,
            'suggested_usage' => $suggested_usage,
            'standalone_allowed' => $standalone_allowed,
        ]);
    }

    private function is_model_focus_extra_candidate(string $keyword_class, string $suggested_usage): bool {
        return in_array($keyword_class, self::PRIMARY_CLASSES, true)
            && in_array($suggested_usage, [
                ModelKeywordPoolClassifier::USAGE_PRIMARY_FOCUS_ALLOWED,
                ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED,
                ModelKeywordPoolClassifier::USAGE_REVIEW_REQUIRED,
            ], true);
    }

    /** @param array<string,mixed> $sources */
    private function owner_matches(array $sources, string $keyword, string $normalized_model): bool {
        $owner = self::normalize_phrase((string) $this->source_value($sources, 'model_keyword_owner'));
        if ($owner === '') {
            return true;
        }
        if ($normalized_model !== '' && $owner === $normalized_model) {
            return true;
        }
        return $normalized_model !== '' && self::contains_phrase($keyword, $normalized_model);
    }

    /** @param array<string,mixed> $sources */
    private function scope_is_compatible(array $sources): bool {
        $scope = strtolower(trim((string) $this->source_value($sources, 'model_keyword_usage_scope')));
        if ($scope === '') {
            return true;
        }
        if (in_array($scope, [ 'model_bio_only', 'model', 'model_page', 'model_pages', 'model_generate', 'model_content', 'model_keyword_pack' ], true)) {
            return true;
        }
        if (strpos($scope, 'model') !== false && strpos($scope, 'video') === false && strpos($scope, 'category') === false) {
            return true;
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function decode_sources($value): array {
        if (is_array($value)) {
            return $value;
        }
        if (null === $value || trim((string) $value) === '') {
            return [];
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $sources */
    private function source_value(array $sources, string $key) {
        if (array_key_exists($key, $sources) && is_scalar($sources[$key])) {
            return $sources[$key];
        }
        foreach ($sources as $value) {
            if (!is_array($value)) {
                continue;
            }
            $found = $this->source_value($value, $key);
            if ($found !== null && $found !== '') {
                return $found;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $sources */
    private function source_bool(array $sources, string $key): ?bool {
        $value = $this->source_value($sources, $key);
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            if (in_array($lower, [ '1', 'true', 'yes' ], true)) {
                return true;
            }
            if (in_array($lower, [ '0', 'false', 'no' ], true)) {
                return false;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $sources @return array<string,mixed> */
    private function source_summary(array $row, array $sources, string $decision): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'keyword' => self::normalize_phrase((string) ($row['keyword'] ?? '')),
            'keyword_class' => (string) $this->source_value($sources, 'keyword_class'),
            'suggested_usage' => (string) $this->source_value($sources, 'suggested_usage'),
            'standalone_allowed' => $this->source_bool($sources, 'standalone_allowed'),
            'decision' => $decision,
        ];
    }

    private static function normalize_phrase(string $phrase): string {
        if (class_exists(ModelKeywordPoolClassifier::class)) {
            return ModelKeywordPoolClassifier::normalize_phrase($phrase);
        }
        $phrase = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($phrase) : strip_tags($phrase);
        $phrase = html_entity_decode($phrase, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $phrase = strtolower($phrase);
        $phrase = preg_replace('/\s+/u', ' ', (string) $phrase);
        return trim((string) $phrase);
    }

    private static function contains_phrase(string $keyword, string $needle): bool {
        return $needle !== '' && preg_match('/(?:^|\s)' . preg_quote($needle, '/') . '(?:\s|$)/u', $keyword) === 1;
    }

    /** @param array<int,string> $keywords @return array<int,string> */
    private static function dedupe(array $keywords): array {
        $out = [];
        $seen = [];
        foreach ($keywords as $keyword) {
            $clean = self::normalize_phrase((string) $keyword);
            if ($clean === '') {
                continue;
            }
            $key = strtolower($clean);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $clean;
        }
        return $out;
    }
}
