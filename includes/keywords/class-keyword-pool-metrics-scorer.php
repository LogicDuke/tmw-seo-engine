<?php
/**
 * Deterministic TMW-owned scoring layer for keyword pool metric rows.
 *
 * @package TMWSEO\Engine\Keywords
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

/**
 * Converts imported KWE/DataForSEO metrics into project-specific TMW decisions.
 */
class KeywordPoolMetricsScorer {

    /** @var array<int, string> */
    private const POOLS = [ 'model', 'video', 'category' ];

    /** @var array<int, string> */
    private const HARD_BLOCK_REASONS = [ 'unsafe_keyword', 'summary_or_footer_row', 'archive_keyword', 'geo_local_intent' ];

    /** @var array<int, string> */
    private const BROAD_NON_TMW_CHAT_INTENTS = [ 'free video chat', 'online video chat', 'adult video chat' ];

    /** @var array<int, string> */
    private const LJ_CATEGORY_BROWSE_INTENTS = [
        'webcam models',
        'cam models',
        'live cam models',
        'live webcam models',
        'asian webcam models',
        'latina webcam models',
        'blonde webcam models',
        'busty webcam models',
        'milf webcam models',
    ];

    /** @var array<int, string> */
    private const VIDEO_MODIFIERS = [ 'video', 'clip', 'live show', 'cam show', 'private show', 'webcam video' ];

    /** @var array<int, string> */
    private const MODEL_MODIFIERS = [ 'webcam model', 'cam model', 'livejasmin model', 'jasmin model' ];

    /** @var array<int, string> */
    private const BIG_PERFORMER_EXPANSIONS = [
        'dani daniels',
        'natasha nice',
        'valentina nappi',
        'cherie deville',
        'dillion harper',
        'romi rain',
        'eva lovia',
    ];

    /**
     * Score one normalized dry-run row for one target keyword pool.
     *
     * @param array<string, mixed> $row Normalized dry-run row.
     * @return array<string, mixed> TMW scoring fields.
     */
    public function score(array $row, string $pool): array {
        $pool = $this->sanitize_pool($pool);
        $keyword = $this->keyword($row);
        $reason_codes = is_array($row['reason_codes'] ?? null) ? array_values(array_unique(array_map('strval', $row['reason_codes']))) : [];
        $tmw_reasons = [];
        $formula = [];
        $score = 0;

        $blocked = $this->is_blocked($row, $reason_codes);
        $deferred_big_performer = $this->is_standalone_big_performer($keyword);
        $standalone_person_name = $this->is_standalone_person_name($keyword);
        $strong_pool_fit = $this->has_strong_pool_fit($keyword, $pool, $row);
        $broad_chat_intent = in_array($keyword, self::BROAD_NON_TMW_CHAT_INTENTS, true);

        if ($blocked) {
            $tmw_reasons[] = 'blocked_or_rejected';
            $score = 0;
            $formula[] = 'score forced to 0 by blocked/rejected/archive input state';
        } else {
            $this->add_metric_scores($row, $score, $tmw_reasons, $formula);
            $this->add_strategy_scores($keyword, $pool, $score, $tmw_reasons, $formula, $strong_pool_fit);

            if ($broad_chat_intent) {
                $score -= 25;
                $tmw_reasons[] = 'too_broad_non_tmw_chat_intent';
                $formula[] = '-25 broad non-TMW chat intent';
            }
            if ('video' === $pool && $standalone_person_name) {
                $score -= 20;
                $tmw_reasons[] = 'standalone_person_name_video';
                $formula[] = '-20 standalone person-name video keyword';
            }
            if ('category' === $pool && $standalone_person_name) {
                $score -= 15;
                $tmw_reasons[] = 'standalone_person_name_category';
                $formula[] = '-15 standalone person-name category keyword';
            }
            if ($this->is_strongly_declining($row['trend_direction'] ?? '')) {
                $score -= 10;
                $tmw_reasons[] = 'strongly_declining_trend';
                $formula[] = '-10 strongly declining trend';
            }
            if (! $this->has_positive_traffic_value($row) && null === $this->number($row['cpc'] ?? null)) {
                $score -= 10;
                $tmw_reasons[] = 'missing_commercial_metric';
                $formula[] = '-10 traffic value empty and CPC missing';
            }

            if ($deferred_big_performer) {
                $tmw_reasons[] = 'defer_until_lj_50_model_milestone';
            }

            if (! $strong_pool_fit) {
                $tmw_reasons[] = 'weak_pool_fit';
            }

            $score = max(0, min(100, $score));
        }

        if ($broad_chat_intent && ! $blocked) {
            $score = min($score, 29);
            $tmw_reasons[] = 'archive_broad_chat_intent';
        }

        $priority = $this->priority($score, $blocked, $strong_pool_fit);
        if ($broad_chat_intent) {
            $priority = 'TMW-Archive';
        }
        $difficulty_band = $this->difficulty_band($row, $tmw_reasons, $formula);
        $commercial_band = $this->commercial_band($row['cpc'] ?? null, $row['traffic_value'] ?? null);
        $readiness = $this->indexing_readiness($priority, $blocked || 'TMW-Archive' === $priority, $strong_pool_fit, $deferred_big_performer, $row);
        $action = $this->recommended_action($readiness, $priority, $blocked);

        if ('archive_do_not_use' === $readiness && ! in_array('archive_do_not_use', $tmw_reasons, true)) {
            $tmw_reasons[] = 'archive_do_not_use';
        }
        if ('ready_for_phase_1_review' === $readiness) {
            $tmw_reasons[] = 'phase_1_ready';
        }

        return [
            'tmw_score' => (int) round($score),
            'tmw_priority' => $priority,
            'tmw_difficulty_band' => $difficulty_band,
            'tmw_commercial_band' => $commercial_band,
            'tmw_indexing_readiness' => $readiness,
            'tmw_recommended_action' => $action,
            'tmw_reason_codes' => array_values(array_unique($tmw_reasons)),
            'tmw_score_formula' => $this->formula_summary($formula, (int) round($score)),
        ];
    }

    /** @param array<string, mixed> $row */
    public static function is_phase_1_save_eligible(array $row): bool {
        return in_array((string) ($row['tmw_recommended_action'] ?? ''), [ 'approve_for_phase_1', 'queue_for_review' ], true)
            && ! in_array((string) ($row['tmw_indexing_readiness'] ?? ''), [ 'defer_until_lj_50_model_milestone', 'archive_do_not_use' ], true)
            && 'TMW-Archive' !== (string) ($row['tmw_priority'] ?? '');
    }

    /** @param array<string, mixed> $row @param array<int, string> $reason_codes */
    private function is_blocked(array $row, array $reason_codes): bool {
        if (in_array((string) ($row['validation_state'] ?? ''), [ 'blocked', 'rejected' ], true)) {
            return true;
        }
        if (in_array((string) ($row['decision'] ?? ''), [ 'block', 'reject' ], true)) {
            return true;
        }
        foreach (self::HARD_BLOCK_REASONS as $reason) {
            if (in_array($reason, $reason_codes, true)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $row @param array<int, string> $tmw_reasons @param array<int, string> $formula */
    private function add_metric_scores(array $row, int &$score, array &$tmw_reasons, array &$formula): void {
        $volume = $this->number($row['volume'] ?? null);
        if (null !== $volume) {
            if ($volume >= 1000) { $score += 25; $tmw_reasons[] = 'volume_1000_plus'; $formula[] = '+25 volume>=1000'; }
            elseif ($volume >= 500) { $score += 20; $tmw_reasons[] = 'volume_500_999'; $formula[] = '+20 volume 500-999'; }
            elseif ($volume >= 100) { $score += 10; $tmw_reasons[] = 'volume_100_499'; $formula[] = '+10 volume 100-499'; }
        }

        $competition = $this->number($row['competition'] ?? null);
        if (null !== $competition) {
            if ($competition < 0.20) { $score += 20; $tmw_reasons[] = 'competition_below_0_20'; $formula[] = '+20 competition<0.20'; }
            elseif ($competition <= 0.39) { $score += 10; $tmw_reasons[] = 'competition_0_20_0_39'; $formula[] = '+10 competition 0.20-0.39'; }
        }

        $cpc = $this->number($row['cpc'] ?? null);
        if (null !== $cpc) {
            if ($cpc >= 2.00) { $score += 20; $tmw_reasons[] = 'cpc_2_plus'; $formula[] = '+20 cpc>=2.00'; }
            elseif ($cpc >= 1.00) { $score += 10; $tmw_reasons[] = 'cpc_1_1_99'; $formula[] = '+10 cpc 1.00-1.99'; }
        }

        $seo_score = max((float) ($this->number($row['seo_score'] ?? null) ?? -1), (float) ($this->number($row['opportunity_score'] ?? null) ?? -1));
        if ($seo_score >= 60) {
            $score += 15;
            $tmw_reasons[] = 'kwe_opportunity_score_60_plus';
            $formula[] = '+15 KWE SEO/opportunity score>=60';
        }

        if ($this->has_positive_traffic_value($row)) {
            $score += 10;
            $tmw_reasons[] = 'positive_traffic_value';
            $formula[] = '+10 positive traffic value';
        }
        if (! empty($row['is_golden_keyword'])) {
            $score += 15;
            $tmw_reasons[] = 'golden_keyword';
            $formula[] = '+15 golden keyword';
        }
        if (! empty($row['kwe_opportunity_candidate'])) {
            $score += 10;
            $tmw_reasons[] = 'kwe_opportunity_candidate';
            $formula[] = '+10 KWE opportunity candidate';
        }
    }

    /** @param array<int, string> $tmw_reasons @param array<int, string> $formula */
    private function add_strategy_scores(string $keyword, string $pool, int &$score, array &$tmw_reasons, array &$formula, bool $strong_pool_fit): void {
        if (str_contains($keyword, 'livejasmin') || str_contains($keyword, 'jasmin')) {
            $score += 15;
            $tmw_reasons[] = 'livejasmin_phase_1_fit';
            $formula[] = '+15 LiveJasmin/Jasmin fit';
        }
        if ('category' === $pool && $this->matches_any($keyword, self::LJ_CATEGORY_BROWSE_INTENTS)) {
            $score += 10;
            $tmw_reasons[] = 'lj_compatible_browse_intent';
            $formula[] = '+10 LJ-compatible browse intent';
        }
        if ('video' === $pool && $this->matches_any($keyword, self::VIDEO_MODIFIERS)) {
            $score += 10;
            $tmw_reasons[] = 'accepted_video_modifier';
            $formula[] = '+10 accepted video/session modifier';
        }
        if ('model' === $pool && ($this->matches_any($keyword, self::MODEL_MODIFIERS) || $strong_pool_fit)) {
            $score += 10;
            $tmw_reasons[] = 'accepted_model_modifier';
            $formula[] = '+10 accepted model/entity modifier';
        }
    }

    /** @param array<string, mixed> $row */
    private function has_strong_pool_fit(string $keyword, string $pool, array $row): bool {
        if ('' === $keyword) {
            return false;
        }
        if (str_contains($keyword, 'livejasmin') || str_contains($keyword, 'jasmin')) {
            return true;
        }
        if ('category' === $pool) {
            return $this->matches_any($keyword, self::LJ_CATEGORY_BROWSE_INTENTS);
        }
        if ('video' === $pool) {
            return $this->matches_any($keyword, self::VIDEO_MODIFIERS);
        }
        $model_name = $this->normalize((string) ($row['model_name'] ?? ''));
        return $this->matches_any($keyword, self::MODEL_MODIFIERS)
            || ('' !== $model_name && str_contains($keyword, $model_name))
            || preg_match('/\bmodels?\b/', $keyword) === 1;
    }

    private function priority(int $score, bool $blocked, bool $strong_pool_fit): string {
        if ($blocked || $score < 30) {
            return 'TMW-Archive';
        }
        if ($score >= 75 && $strong_pool_fit) {
            return 'TMW-P1';
        }
        if ($score >= 55) {
            return 'TMW-P2';
        }
        return 'TMW-P3';
    }

    /** @param array<string, mixed> $row */
    private function indexing_readiness(string $priority, bool $archive, bool $strong_pool_fit, bool $deferred_big_performer, array $row): string {
        if ($archive) {
            return 'archive_do_not_use';
        }
        if ($deferred_big_performer) {
            return 'defer_until_lj_50_model_milestone';
        }
        $valid = 'valid' === (string) ($row['validation_state'] ?? '');
        if (in_array($priority, [ 'TMW-P1', 'TMW-P2' ], true) && $valid && $strong_pool_fit) {
            return 'ready_for_phase_1_review';
        }
        if (in_array($priority, [ 'TMW-P2', 'TMW-P3' ], true)) {
            return 'needs_manual_review';
        }
        return 'archive_do_not_use';
    }

    private function recommended_action(string $readiness, string $priority, bool $blocked): string {
        if ($blocked) {
            return 'block';
        }
        if ('archive_do_not_use' === $readiness || 'TMW-Archive' === $priority) {
            return 'archive';
        }
        if ('defer_until_lj_50_model_milestone' === $readiness) {
            return 'defer_for_phase_2';
        }
        if ('ready_for_phase_1_review' === $readiness && 'TMW-P1' === $priority) {
            return 'approve_for_phase_1';
        }
        return 'queue_for_review';
    }

    /**
     * Resolve the TMW difficulty band, preferring true KD/difficulty over paid competition.
     *
     * @param array<string, mixed> $row Preview/scoring row.
     * @param array<int, string>   $tmw_reasons Mutable TMW reason codes.
     * @param array<int, string>   $formula Mutable score formula explanations.
     */
    private function difficulty_band(array $row, array &$tmw_reasons, array &$formula): string {
        $difficulty = $this->number($row['difficulty'] ?? null);
        if (null !== $difficulty) {
            $tmw_reasons[] = 'difficulty_from_true_kd';
            $formula[] = 'difficulty band from true KD';
            if ($difficulty <= 20) { return 'very_easy'; }
            if ($difficulty <= 40) { return 'easy'; }
            if ($difficulty <= 60) { return 'moderate'; }
            return 'hard';
        }

        $competition = $this->number($row['competition'] ?? null);
        if (null !== $competition) {
            $tmw_reasons[] = 'difficulty_from_competition_proxy';
            $formula[] = 'difficulty band from competition proxy';
            if ($competition <= 0.10) { return 'very_easy'; }
            if ($competition <= 0.20) { return 'easy'; }
            if ($competition <= 0.40) { return 'moderate'; }
            return 'hard';
        }

        $tmw_reasons[] = 'difficulty_unknown';
        $formula[] = 'difficulty band unknown';
        return 'unknown';
    }

    private function commercial_band($cpc, $traffic_value): string {
        $cpc = $this->number($cpc);
        $traffic_value = $this->number($traffic_value);
        if (null === $cpc && null === $traffic_value) { return 'unknown'; }
        if ((null !== $cpc && $cpc >= 2.00) || (null !== $traffic_value && $traffic_value >= 1000)) { return 'high'; }
        if ((null !== $cpc && $cpc >= 1.00) || (null !== $traffic_value && $traffic_value >= 100)) { return 'medium'; }
        return 'low';
    }

    private function is_strongly_declining($trend_direction): bool {
        $value = strtolower(trim((string) $trend_direction));
        if ('' === $value) { return false; }
        if (str_contains($value, 'strong') && str_contains($value, 'declin')) { return true; }
        $number = $this->number($value);
        return null !== $number && $number <= -20.0;
    }

    private function has_positive_traffic_value(array $row): bool {
        $traffic_value = $this->number($row['traffic_value'] ?? null);
        return null !== $traffic_value && $traffic_value > 0;
    }

    private function is_standalone_big_performer(string $keyword): bool {
        return in_array($keyword, self::BIG_PERFORMER_EXPANSIONS, true);
    }

    private function is_standalone_person_name(string $keyword): bool {
        if ('' === $keyword || $this->matches_any($keyword, array_merge(self::MODEL_MODIFIERS, self::VIDEO_MODIFIERS, self::LJ_CATEGORY_BROWSE_INTENTS))) {
            return false;
        }
        return preg_match('/^[a-z]+(?:\s+[a-z]+){1,2}$/', $keyword) === 1;
    }

    /** @param array<int, string> $needles */
    private function matches_any(string $keyword, array $needles): bool {
        foreach ($needles as $needle) {
            if (str_contains($keyword, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $row */
    private function keyword(array $row): string {
        $keyword = (string) ($row['normalized_keyword'] ?? $row['keyword'] ?? '');
        return $this->normalize($keyword);
    }

    private function normalize(string $value): string {
        $value = strtolower(trim(strip_tags($value)));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return trim($value);
    }

    private function number($value): ?float {
        if (null === $value || '' === $value) { return null; }
        if (is_int($value) || is_float($value)) { return (float) $value; }
        $raw = strtolower(trim((string) $value));
        if (in_array($raw, [ '', 'n/a', 'na', 'null', '-' ], true)) { return null; }
        $numeric = str_replace([ ',', '$', '%', '+' ], '', $raw);
        if (! is_numeric($numeric)) { return null; }
        return (float) $numeric;
    }

    private function sanitize_pool(string $pool): string {
        $pool = strtolower(trim($pool));
        return in_array($pool, self::POOLS, true) ? $pool : 'category';
    }

    /** @param array<int, string> $formula */
    private function formula_summary(array $formula, int $score): string {
        if ([] === $formula) {
            return 'TMW score ' . $score . ': no positive project-fit metric signals.';
        }
        return 'TMW score ' . $score . ': ' . implode('; ', array_values(array_unique($formula))) . '.';
    }
}
