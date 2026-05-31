<?php
/**
 * Approved classified keyword provider for model Generate keyword packs.
 *
 * @package TMWSEO\Engine\Keywords
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

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

            if ($keyword === '') {
                $reason = 'empty_keyword';
            } elseif ($keyword_class === '') {
                $reason = 'missing_keyword_class';
            } elseif (!$this->owner_matches($sources, $keyword, $normalized_model)) {
                $reason = 'wrong_model_keyword_owner';
            } elseif (!$this->scope_is_compatible($sources)) {
                $reason = 'incompatible_model_keyword_usage_scope';
            } elseif ($this->is_focus_excluded($keyword_class, $suggested_usage, $standalone_allowed)) {
                $reason = 'excluded_from_focus_by_classification';
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

            if ($this->is_model_focus_extra_candidate($keyword_class, $suggested_usage)) {
                $extra[] = $keyword;
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

            $source_rows[] = $this->source_summary($row, $sources, 'included');
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
