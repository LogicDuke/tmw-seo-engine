<?php
/**
 * Audit-only keyword candidate pool classification checks.
 *
 * @package TMWSEO\Engine\Keywords
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

if (!defined('ABSPATH')) { exit; }

/**
 * Read-only classifier for saved keyword candidate pool assignments.
 */
class KeywordCandidateClassificationAudit {
    public const REASON_MISCLASSIFIED_MODEL = 'misclassified_model_intent_candidate';
    public const REASON_PERSON_IN_CATEGORY = 'person_name_in_category_pool';
    public const REASON_STANDALONE_MODEL_IN_VIDEO = 'standalone_model_name_in_video_pool';
    public const REASON_TOPIC_ENTITY_MODEL_REVIEW = 'topic_entity_model_pool_review';

    /** @var array<int, string> */
    private const NON_MODEL_MODEL_TERMS = [
        'sex cam sites',
        'cam sites',
        'chat hd',
        'live chat',
        'webcam chat',
        'adult chat',
        'cheap',
        'cheapest',
        'sites',
        'app',
        'platform',
        'category',
        'categories',
        'girls',
        'cams',
        'webcams',
        'live cams',
        'sex cams',
        'cam chat',
        'free cam',
        'best cam',
        'top cam',
    ];

    /** @var array<int, string> */
    private const CATEGORY_STYLE_TERMS = [
        'category',
        'categories',
        'tag',
        'tags',
        'browse',
        'topic',
        'niche',
        'type',
        'genre',
        'girls',
        'models',
        'cams',
        'webcams',
        'sites',
        'platform',
        'app',
    ];

    /** @var array<int, string> */
    private const VIDEO_MODIFIERS = [
        'video',
        'videos',
        'clip',
        'clips',
        'session',
        'show',
        'stream',
        'live show',
        'recorded',
        'recording',
        'watch',
        'camshow',
    ];

    /**
     * @param array<string, mixed> $row Keyword candidate row.
     * @return array<string, mixed> Audit row with reason codes and review action.
     */
    public static function audit_row(array $row): array {
        $keyword = self::normalize_keyword((string) ($row['keyword'] ?? ''));
        $intent_type = strtolower(trim((string) ($row['intent_type'] ?? '')));
        $entity_type = strtolower(trim((string) ($row['entity_type'] ?? '')));
        $reason_codes = [];

        if ($intent_type === 'model' && self::looks_non_model_model_keyword($keyword)) {
            $reason_codes[] = self::REASON_MISCLASSIFIED_MODEL;
        }

        if ($intent_type === 'category' && self::looks_like_standalone_person_name($keyword)) {
            $reason_codes[] = self::REASON_PERSON_IN_CATEGORY;
        }

        if ($intent_type === 'video' && self::looks_like_standalone_person_name($keyword) && !self::has_video_modifier($keyword)) {
            $reason_codes[] = self::REASON_STANDALONE_MODEL_IN_VIDEO;
        }

        if ($intent_type === 'model' && $entity_type === 'topic_entity') {
            $reason_codes[] = self::REASON_TOPIC_ENTITY_MODEL_REVIEW;
        }

        $reason_codes = array_values(array_unique($reason_codes));

        return array_merge($row, [
            'reason_codes' => $reason_codes,
            'recommended_review_action' => self::recommended_review_action($reason_codes),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows Candidate rows.
     * @return array<string, mixed>
     */
    public static function audit_rows(array $rows): array {
        $audited = [];
        $summary = [
            'total_scanned' => count($rows),
            'suspicious_model_rows' => 0,
            'suspicious_video_rows' => 0,
            'suspicious_category_rows' => 0,
            'rows_needing_manual_review' => 0,
        ];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $audit_row = self::audit_row($row);
            $reason_codes = (array) ($audit_row['reason_codes'] ?? []);
            if ($reason_codes === []) {
                continue;
            }

            if (in_array(self::REASON_MISCLASSIFIED_MODEL, $reason_codes, true)) {
                $summary['suspicious_model_rows']++;
            }
            if (in_array(self::REASON_STANDALONE_MODEL_IN_VIDEO, $reason_codes, true)) {
                $summary['suspicious_video_rows']++;
            }
            if (in_array(self::REASON_PERSON_IN_CATEGORY, $reason_codes, true)) {
                $summary['suspicious_category_rows']++;
            }
            if (in_array(self::REASON_TOPIC_ENTITY_MODEL_REVIEW, $reason_codes, true)) {
                $summary['rows_needing_manual_review']++;
            }

            $audited[] = $audit_row;
        }

        return [
            'summary' => $summary,
            'rows' => $audited,
        ];
    }

    /**
     * Build the read-only report from wp_tmw_keyword_candidates.
     *
     * @return array<string, mixed>
     */
    public static function audit_database(int $display_limit = 500): array {
        global $wpdb;

        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $columns = (array) $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $column_names = array_map(static fn($col): string => (string) ($col['Field'] ?? $col['field'] ?? ''), $columns);
        $selects = [];
        foreach ([ 'id', 'keyword', 'intent_type', 'entity_type', 'entity_id', 'status', 'volume', 'cpc', 'competition', 'opportunity', 'source', 'sources' ] as $column) {
            $selects[] = in_array($column, $column_names, true) ? $column : "NULL AS {$column}";
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $summary = [
            'total_scanned' => $total,
            'suspicious_model_rows' => 0,
            'suspicious_video_rows' => 0,
            'suspicious_category_rows' => 0,
            'rows_needing_manual_review' => 0,
        ];
        $audited = [];
        $offset = 0;
        $batch_size = 1000;
        $display_limit = max(1, $display_limit);
        $order_column = in_array('id', $column_names, true) ? 'id' : 'keyword';

        do {
            $rows = (array) $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT ' . implode(', ', $selects) . " FROM {$table} ORDER BY {$order_column} DESC LIMIT %d OFFSET %d",
                    $batch_size,
                    $offset
                ),
                ARRAY_A
            );

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $audit_row = self::audit_row($row);
                $reason_codes = (array) ($audit_row['reason_codes'] ?? []);
                if ($reason_codes === []) {
                    continue;
                }

                if (in_array(self::REASON_MISCLASSIFIED_MODEL, $reason_codes, true)) {
                    $summary['suspicious_model_rows']++;
                }
                if (in_array(self::REASON_STANDALONE_MODEL_IN_VIDEO, $reason_codes, true)) {
                    $summary['suspicious_video_rows']++;
                }
                if (in_array(self::REASON_PERSON_IN_CATEGORY, $reason_codes, true)) {
                    $summary['suspicious_category_rows']++;
                }
                if (in_array(self::REASON_TOPIC_ENTITY_MODEL_REVIEW, $reason_codes, true)) {
                    $summary['rows_needing_manual_review']++;
                }
                if (count($audited) < $display_limit) {
                    $audited[] = $audit_row;
                }
            }

            $offset += $batch_size;
        } while (count($rows) === $batch_size);

        return [
            'summary' => $summary,
            'rows' => $audited,
            'display_limit' => $display_limit,
            'suspicious_rows_returned' => count($audited),
        ];
    }

    private static function looks_non_model_model_keyword(string $keyword): bool {
        return self::has_any_term($keyword, self::NON_MODEL_MODEL_TERMS);
    }

    private static function looks_like_standalone_person_name(string $keyword): bool {
        if ($keyword === '' || self::has_any_term($keyword, self::CATEGORY_STYLE_TERMS) || self::has_video_modifier($keyword)) {
            return false;
        }

        $tokens = preg_split('/\s+/', $keyword) ?: [];
        $tokens = array_values(array_filter($tokens, static fn($token): bool => $token !== ''));
        $count = count($tokens);
        if ($count < 1 || $count > 2) {
            return false;
        }

        foreach ($tokens as $token) {
            if (!preg_match('/^[a-z][a-z0-9._-]{1,29}$/', $token)) {
                return false;
            }
        }

        return true;
    }

    private static function has_video_modifier(string $keyword): bool {
        return self::has_any_term($keyword, self::VIDEO_MODIFIERS);
    }

    /**
     * @param array<int, string> $terms
     */
    private static function has_any_term(string $keyword, array $terms): bool {
        foreach ($terms as $term) {
            if (preg_match('/(^|\s)' . preg_quote($term, '/') . '(\s|$)/', $keyword) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function normalize_keyword(string $keyword): string {
        $keyword = strtolower(trim($keyword));
        $keyword = preg_replace('/\s+/', ' ', $keyword) ?: $keyword;
        return $keyword;
    }

    /**
     * @param array<int, string> $reason_codes
     */
    private static function recommended_review_action(array $reason_codes): string {
        if (in_array(self::REASON_MISCLASSIFIED_MODEL, $reason_codes, true)) {
            return 'move_to_category_pool_later';
        }
        if (in_array(self::REASON_PERSON_IN_CATEGORY, $reason_codes, true)) {
            return 'review_model_pool';
        }
        if (in_array(self::REASON_STANDALONE_MODEL_IN_VIDEO, $reason_codes, true)) {
            return 'review_model_pool';
        }
        if (in_array(self::REASON_TOPIC_ENTITY_MODEL_REVIEW, $reason_codes, true)) {
            return 'keep_if_verified_model_keyword';
        }

        return 'ignore_if_irrelevant';
    }
}
