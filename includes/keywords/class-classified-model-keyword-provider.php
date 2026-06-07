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

    /**
     * Build the keyword fragment for a given model post.
     *
     * Fetches both model-specific approved rows (entity_id = $model_post_id) and
     * approved Global Model Pool rows. The global pool candidates are returned in
     * `global_pool_candidates` and are used by ModelKeywordPack as the second-priority
     * source for Rank Math extras (after model-specific, before deterministic fallback).
     *
     * @return array{
     *   primary_candidates:array<int,string>,
     *   extra_focus_candidates:array<int,string>,
     *   body_semantic_candidates:array<int,string>,
     *   modifier_candidates:array<int,string>,
     *   excluded_candidates:array<int,string>,
     *   global_pool_candidates:array<int,string>,
     *   sources:array<string,mixed>
     * }
     */
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

        // Always fetch global pool candidates — used as second-priority fill for Rank Math extras
        // when model-specific approved rows are sparse or absent.
        $global_pool_candidates = $this->fetch_global_pool_candidates();

        if (!is_array($rows) || empty($rows)) {
            // No model-specific rows. Return global pool candidates in the fragment so
            // ModelKeywordPack can use them before falling back to the deterministic chips.
            $fragment = $this->empty_fragment();
            $fragment['global_pool_candidates'] = $global_pool_candidates;
            return $fragment;
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

            // PR-688: evaluate the live/cam rescue BEFORE is_focus_excluded() so that
            // CLASS_UNKNOWN_REVIEW rows with approved model-linked cam intent (e.g.
            // "anisyia cam") are never hard-excluded by the focus gate before the rescue
            // has a chance to admit them.  The rescue itself already enforces all safety
            // guards: approved status, entity_id match, model name containment, no adult
            // terms, and the safe-phrase whitelist.
            $admitted_live_intent_review_keyword = $this->is_approved_model_linked_live_intent_extra(
                $row, $model_post_id, $keyword, $normalized_model, $keyword_class, $suggested_usage, $standalone_allowed
            );

            // PR-689: approved personal CSV rows whose scope was recorded as
            // 'manual_review' (or another non-standard value) pass all safety checks
            // but are blocked by scope_is_compatible() before any rescue can run.
            // This flag lets the scope gate and focus gate be bypassed when the row
            // has explicit CSV provenance, an approved entity_id link, and a safe keyword.
            $admitted_approved_personal_extra = $this->is_approved_personal_csv_extra_candidate(
                $row, $sources, $model_post_id, $keyword, $keyword_class, $normalized_model
            );

            if ($keyword === '') {
                $reason = 'empty_keyword';
            } elseif ($keyword_class === '') {
                $reason = 'missing_keyword_class';
            } elseif (!$this->owner_matches($sources, $keyword, $normalized_model)) {
                $reason = 'wrong_model_keyword_owner';
            } elseif (!$admitted_approved_personal_extra && !$this->scope_is_compatible($sources)) {
                $reason = 'incompatible_model_keyword_usage_scope';
            } elseif (!$admitted_live_intent_review_keyword
                && !$admitted_approved_personal_extra
                && $this->is_focus_excluded($keyword_class, $suggested_usage, $standalone_allowed)) {
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

            if ($this->is_model_focus_extra_candidate($keyword_class, $suggested_usage)
                || $admitted_live_intent_review_keyword
                || $admitted_approved_personal_extra) {
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

            $decision = 'included';
            if ($admitted_live_intent_review_keyword) {
                $decision = 'included_model_live_intent_review_required';
            } elseif ($admitted_approved_personal_extra) {
                $decision = 'included_approved_personal_csv_extra';
            }
            $source_rows[] = $this->source_summary($row, $sources, $decision);
        }

        return [
            'primary_candidates'       => self::dedupe(array_merge($primary_personal, $primary_fallback)),
            'extra_focus_candidates'   => self::dedupe(array_merge($extra, $supporting_extra)),
            'body_semantic_candidates' => self::dedupe($body),
            'modifier_candidates'      => self::dedupe($modifiers),
            'excluded_candidates'      => self::dedupe($excluded),
            'global_pool_candidates'   => $global_pool_candidates,
            'sources'                  => [
                'provider' => 'classified_model_keyword_provider',
                'entity_id' => $model_post_id,
                'rows' => $source_rows,
            ],
        ];
    }

    /**
     * Fetch approved Global Model Pool keyword candidates using schema-safe detection.
     *
     * Mirrors the three-strategy detection logic from
     * ModelKeywordPack::debug_log_global_model_pool_lookup() but fetches actual keyword
     * rows instead of just counting them. Results are used as second-priority fill for
     * Rank Math extras when model-specific approved rows are absent or sparse.
     *
     * Detection strategy priority (same as debug log):
     *   1. model_keyword_usage_scope = 'global_model_pool'  (preferred, explicit column)
     *   2. target_type = 'global' AND target_name = 'Global Model Pool'
     *   3. target_type = 'global' AND target_slug = 'global-model-pool'
     *
     * Only status='approved' rows are returned. CLASS_UNSAFE_STANDALONE keywords
     * are excluded even if approved.
     *
     * @return array<int,string> Normalized approved keyword strings, in DB id order.
     */
    private function fetch_global_pool_candidates(): array {
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->prefix)) {
            return [];
        }

        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        // Verify table exists.
        if (method_exists($wpdb, 'get_var') && method_exists($wpdb, 'esc_like') && method_exists($wpdb, 'prepare')) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if (!is_string($found) || strtolower($found) !== strtolower($table)) {
                return [];
            }
        }

        if (!method_exists($wpdb, 'get_col') || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'prepare')) {
            return [];
        }

        // Detect available columns (schema-safe — the live table may not have all columns yet).
        $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . $table, 0);
        $columns = is_array($columns) ? array_map('strval', $columns) : [];
        $column_lookup = array_fill_keys($columns, true);

        if (!isset($column_lookup['intent_type'], $column_lookup['status'])) {
            return [];
        }

        $out = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';

        // PR-683: Cascading fallback — try each strategy in order and return on the first
        // non-empty result set. This guarantees Global Model Pool rows are found regardless
        // of which schema version was used when they were written (scope column vs.
        // target_type/target_name/target_slug vs. legacy entity_id=0).

        // Strategy 1: model_keyword_usage_scope = 'global_model_pool' (explicit scope column, preferred).
        if (isset($column_lookup['model_keyword_usage_scope'])) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id, keyword, sources FROM ' . $table
                    . ' WHERE intent_type = %s AND status = %s AND model_keyword_usage_scope = %s ORDER BY id ASC',
                    'model', 'approved', 'global_model_pool'
                ),
                $out
            );
            if (is_array($rows) && !empty($rows)) {
                return $this->extract_keywords_from_rows($rows);
            }
        }

        // Strategy 2: target_type = 'global' AND target_name = 'Global Model Pool'.
        if (isset($column_lookup['target_type'], $column_lookup['target_name'])) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id, keyword, sources FROM ' . $table
                    . ' WHERE intent_type = %s AND status = %s AND target_type = %s AND target_name = %s ORDER BY id ASC',
                    'model', 'approved', 'global', 'Global Model Pool'
                ),
                $out
            );
            if (is_array($rows) && !empty($rows)) {
                return $this->extract_keywords_from_rows($rows);
            }
        }

        // Strategy 3: target_type = 'global' AND target_slug = 'global-model-pool'.
        if (isset($column_lookup['target_type'], $column_lookup['target_slug'])) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id, keyword, sources FROM ' . $table
                    . ' WHERE intent_type = %s AND status = %s AND target_type = %s AND target_slug = %s ORDER BY id ASC',
                    'model', 'approved', 'global', 'global-model-pool'
                ),
                $out
            );
            if (is_array($rows) && !empty($rows)) {
                return $this->extract_keywords_from_rows($rows);
            }
        }

    

        return [];
    }

    /**
     * Convert a get_results row set into a deduplicated keyword string array,
     * skipping empty keywords and CLASS_UNSAFE_STANDALONE entries.
     *
     * @param  array<int,array<string,mixed>> $rows
     * @return array<int,string>
     */
    private function extract_keywords_from_rows(array $rows): array {
        $keywords = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $keyword = self::normalize_phrase((string) ($row['keyword'] ?? ''));
            if ($keyword === '') {
                continue;
            }
            // Exclude definitionally unsafe standalone terms even if approved.
            $sources       = $this->decode_sources($row['sources'] ?? null);
            $keyword_class = (string) $this->source_value($sources, 'keyword_class');
            if ($keyword_class === ModelKeywordPoolClassifier::CLASS_UNSAFE_STANDALONE) {
                continue;
            }
            $keywords[] = $keyword;
        }
        return self::dedupe($keywords);
    }

    /** @return array{primary_candidates:array<int,string>,extra_focus_candidates:array<int,string>,body_semantic_candidates:array<int,string>,modifier_candidates:array<int,string>,excluded_candidates:array<int,string>,global_pool_candidates:array<int,string>,sources:array<string,mixed>} */
    private function empty_fragment(): array {
        return [
            'primary_candidates'       => [],
            'extra_focus_candidates'   => [],
            'body_semantic_candidates' => [],
            'modifier_candidates'      => [],
            'excluded_candidates'      => [],
            'global_pool_candidates'   => [],
            'sources'                  => [],
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

    /**
     * Return true when an approved personal CSV model keyword row should be admitted
     * as an extra_focus_candidate regardless of its model_keyword_usage_scope value.
     *
     * This covers rows where scope was recorded as 'manual_review' (or another
     * non-standard value that fails scope_is_compatible()) but the row has since been
     * manually approved and entity-linked via the ModelKeywordLinkService.
     *
     * All conditions must be true:
     *   1. status = approved  (explicit human sign-off)
     *   2. intent_type = model AND entity_type = model
     *   3. entity_id = current model post ID  (linker has resolved the row)
     *   4. sources.personal_model_keyword_csv = true  (CSV provenance, not inferred)
     *   5. keyword contains the normalized model name  (not a generic term)
     *   6. keyword_class (after PR-615 default) is not CLASS_UNSAFE_STANDALONE
     *   7. keyword passes the adult-fallback-term safety check
     *
     * Rows with status ignored/rejected/blocked are excluded by condition 1.
     * Generic/global keywords are excluded by condition 5.
     * The row is only ever added to extra_focus_candidates, never to primary.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $sources
     */
    private function is_approved_personal_csv_extra_candidate(
        array  $row,
        array  $sources,
        int    $model_post_id,
        string $keyword,
        string $keyword_class,
        string $normalized_model
    ): bool {
        if ((string) ($row['status']      ?? '') !== 'approved') { return false; }
        if ((string) ($row['intent_type'] ?? '') !== 'model')    { return false; }
        if ((string) ($row['entity_type'] ?? '') !== 'model')    { return false; }
        if ((int)    ($row['entity_id']   ?? 0)  !== $model_post_id) { return false; }
        if ($normalized_model === '')                              { return false; }

        // Must have explicit personal CSV provenance — prevents global pool or
        // auto-generated rows from being admitted via this path.
        if (!$this->source_bool($sources, 'personal_model_keyword_csv')) { return false; }

        // Must contain the model name — prevents admission of generic terms
        // that happen to share entity_id because of a bulk-link operation.
        if (!self::contains_phrase($keyword, $normalized_model)) { return false; }

        // Must not be definitionally unsafe regardless of approved status.
        if ($keyword_class === ModelKeywordPoolClassifier::CLASS_UNSAFE_STANDALONE) { return false; }

        // No adult/unsafe content.
        if ($this->contains_adult_fallback_term($keyword)) { return false; }

        return true;
    }

    private function is_safe_model_live_intent_phrase(string $keyword, string $normalized_model): bool {
        if ($normalized_model === '') {
            return false;
        }
        $safe_phrases = [
            $normalized_model . ' live',
            $normalized_model . ' live chat',
            $normalized_model . ' live cam',
            // PR-688: also admit model+cam / model+webcam personal keywords that were
            // mis-classified as CLASS_UNKNOWN_REVIEW because "cam"/"webcam" alone are
            // not in PLATFORM_TERMS or CORE_MODEL_TERMS, while being unambiguously safe
            // cam-platform intent phrases when combined with the model name.
            $normalized_model . ' cam',
            $normalized_model . ' webcam',
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
        // global_model_pool rows are fetched separately via fetch_global_pool_candidates(),
        // not via the model-specific query, so they are excluded here.
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
