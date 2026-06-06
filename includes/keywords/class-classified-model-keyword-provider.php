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

    /** @var array<int,string> */
    private const SUPPORTING_CLASSES = [
        ModelKeywordPoolClassifier::CLASS_SUPPORTING_MODEL_TERM,
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
        $supporting_extra = [];
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
                if (in_array($keyword_class, self::SUPPORTING_CLASSES, true)) {
                    $supporting_extra[] = $keyword;
                } else {
                    $extra[] = $keyword;
                }
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
            'extra_focus_candidates'   => self::dedupe(array_merge($extra, $supporting_extra)),
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

    /**
     * Fetch approved Global Model Pool rows for model Rank Math extras.
     *
     * @param string[] $active_platforms Active platform slugs for ordering platform-approved rows first.
     * @return array{extra_focus_candidates:array<int,string>,active_platform_candidates:array<int,string>,global_pool_candidates:array<int,string>,excluded_candidates:array<int,string>,sources:array<string,mixed>}
     */
    public function approved_global_model_pool_keywords(string $model_name = '', array $active_platforms = []): array {
        $empty = [
            'extra_focus_candidates' => [],
            'active_platform_candidates' => [],
            'global_pool_candidates' => [],
            'excluded_candidates' => [],
            'sources' => [
                'provider' => 'classified_model_keyword_provider',
                'scope' => 'global_model_pool',
                'rows' => [],
            ],
        ];

        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->prefix) || !method_exists($wpdb, 'get_results')) {
            return $empty;
        }

        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        if (method_exists($wpdb, 'get_var') && method_exists($wpdb, 'esc_like') && method_exists($wpdb, 'prepare')) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if (!is_string($found) || strtolower($found) !== strtolower($table)) {
                return $empty;
            }
        }

        $columns = $this->keyword_candidate_columns($table);
        if (empty($columns) || !isset($columns['keyword'], $columns['status'])) {
            return $empty;
        }

        $where = [ 'status = %s' ];
        $args = [ 'approved' ];
        if (isset($columns['intent_type'])) {
            $where[] = 'intent_type = %s';
            $args[] = 'model';
        }
        if (isset($columns['entity_type'])) {
            $where[] = 'entity_type = %s';
            $args[] = 'model';
        }

        $global_clauses = [];
        if (isset($columns['model_keyword_usage_scope'])) {
            $global_clauses[] = 'model_keyword_usage_scope = %s';
            $args[] = 'global_model_pool';
        }
        if (isset($columns['target_type'])) {
            $global_clauses[] = 'target_type = %s';
            $args[] = 'global';
        }
        if (isset($columns['target_name'])) {
            $global_clauses[] = 'target_name = %s';
            $args[] = 'Global Model Pool';
        }
        if (isset($columns['entity_id'])) {
            $global_clauses[] = 'entity_id = %d';
            $args[] = 0;
        }
        if (empty($global_clauses)) {
            return $empty;
        }
        $where[] = '(' . implode(' OR ', $global_clauses) . ')';

        $select_columns = array_values(array_intersect([
            'id', 'keyword', 'intent_type', 'entity_type', 'entity_id', 'status', 'sources',
            'target_type', 'target_id', 'target_name', 'target_slug', 'model_keyword_usage_scope',
        ], array_keys($columns)));
        if (!in_array('keyword', $select_columns, true)) {
            $select_columns[] = 'keyword';
        }
        if (!in_array('status', $select_columns, true)) {
            $select_columns[] = 'status';
        }

        $sql = 'SELECT ' . implode(', ', $select_columns) . ' FROM ' . $table . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC';
        if (method_exists($wpdb, 'prepare')) {
            $sql = $wpdb->prepare($sql, $args);
        }
        $rows = $wpdb->get_results($sql, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (!is_array($rows) || empty($rows)) {
            return $empty;
        }

        $active_lookup = [];
        foreach ($active_platforms as $platform) {
            $key = sanitize_key((string) $platform);
            if ($key !== '') {
                $active_lookup[$key] = true;
            }
        }

        $active_platform = [];
        $global_pool = [];
        $excluded = [];
        $source_rows = [];
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
            } elseif ((string) ($row['status'] ?? '') !== 'approved') {
                $reason = 'non_approved_status';
            } elseif (!$this->is_global_model_pool_row($row, $sources)) {
                $reason = 'not_global_model_pool';
            } elseif ($this->is_blocked_collision_row($row, $sources)) {
                $reason = 'blocked_collision';
            } elseif ($keyword_class !== '' && $this->is_focus_excluded($keyword_class, $suggested_usage, $standalone_allowed)) {
                $reason = 'excluded_from_focus_by_classification';
            }

            if ($reason !== '') {
                if ($keyword !== '') {
                    $excluded[] = $keyword;
                    $source_rows[] = $this->source_summary($row, $sources, $reason);
                }
                continue;
            }

            if ($this->row_matches_active_platform($row, $sources, $keyword, $active_lookup)) {
                $active_platform[] = $keyword;
                $source_rows[] = $this->source_summary($row, $sources, 'included_active_platform_global_model_pool');
            } else {
                $global_pool[] = $keyword;
                $source_rows[] = $this->source_summary($row, $sources, 'included_global_model_pool');
            }
        }

        $active_platform = self::dedupe($active_platform);
        $global_pool = self::dedupe($global_pool);

        return [
            'extra_focus_candidates' => self::dedupe(array_merge($active_platform, $global_pool)),
            'active_platform_candidates' => $active_platform,
            'global_pool_candidates' => $global_pool,
            'excluded_candidates' => self::dedupe($excluded),
            'sources' => [
                'provider' => 'classified_model_keyword_provider',
                'scope' => 'global_model_pool',
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
        return preg_match('/(?:^|\s)(?:porn|porno|adult|sex|xxx|nude|nudes|naked|leak|leaked|onlyfans)(?:\s|$)/u', $keyword) === 1;
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
        if (in_array($keyword_class, self::SUPPORTING_CLASSES, true)) {
            return ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED === $suggested_usage;
        }

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

    /** @return array<string,bool> */
    private function keyword_candidate_columns(string $table): array {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_col')) {
            return [
                'id' => true,
                'keyword' => true,
                'intent_type' => true,
                'entity_type' => true,
                'entity_id' => true,
                'status' => true,
                'sources' => true,
            ];
        }
        $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . $table, 0);
        if (!is_array($columns)) {
            return [];
        }
        $lookup = [];
        foreach ($columns as $column) {
            $column = (string) $column;
            if ($column !== '') {
                $lookup[$column] = true;
            }
        }
        return $lookup;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $sources */
    private function is_global_model_pool_row(array $row, array $sources): bool {
        $scope = strtolower(trim((string) ($row['model_keyword_usage_scope'] ?? $this->source_value($sources, 'model_keyword_usage_scope'))));
        if ($scope === 'global_model_pool') {
            return true;
        }
        if (strtolower(trim((string) ($row['target_type'] ?? ''))) === 'global') {
            return true;
        }
        if (strcasecmp(trim((string) ($row['target_name'] ?? '')), 'Global Model Pool') === 0) {
            return true;
        }
        if (array_key_exists('entity_id', $row) && (int) ($row['entity_id'] ?? -1) === 0) {
            return true;
        }
        return false;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $sources */
    private function is_blocked_collision_row(array $row, array $sources): bool {
        foreach ([ 'blocked_collision', 'is_blocked_collision', 'collision_blocked' ] as $key) {
            $value = $row[$key] ?? $this->source_value($sources, $key);
            if ($value === true || $value === 1 || (is_string($value) && in_array(strtolower(trim($value)), [ '1', 'true', 'yes', 'blocked' ], true))) {
                return true;
            }
        }
        foreach ([ 'collision_status', 'collision_decision', 'collision_action', 'validation_state' ] as $key) {
            $value = strtolower(trim((string) ($row[$key] ?? $this->source_value($sources, $key))));
            if (in_array($value, [ 'blocked', 'rejected', 'ignored', 'archived' ], true)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $sources @param array<string,bool> $active_lookup */
    private function row_matches_active_platform(array $row, array $sources, string $keyword, array $active_lookup): bool {
        if (empty($active_lookup)) {
            return false;
        }
        foreach ([ 'platform', 'platform_key', 'target_platform', 'model_keyword_platform' ] as $key) {
            $value = $row[$key] ?? $this->source_value($sources, $key);
            if (is_scalar($value)) {
                $platform = sanitize_key((string) $value);
                if ($platform !== '' && isset($active_lookup[$platform])) {
                    return true;
                }
            }
        }
        foreach (array_keys($active_lookup) as $platform) {
            $platform_phrase = self::normalize_phrase(str_replace([ '-', '_' ], ' ', $platform));
            if ($platform_phrase !== '' && self::contains_phrase($keyword, $platform_phrase)) {
                return true;
            }
        }
        return false;
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
