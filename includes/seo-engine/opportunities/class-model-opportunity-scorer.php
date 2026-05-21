<?php
namespace TMWSEO\Engine\Opportunities;
if (!defined('ABSPATH')) { exit; }
class ModelOpportunityScorer {
    public static function score(array $row): array {
        $score = 0.0;
        $pv = (int)($row['primary_volume'] ?? 0); $fv = (int)($row['family_volume'] ?? 0);
        $score += min(30, log(max(1, $pv + $fv), 10) * 8);
        $score += min(15, ((float)($row['traffic_value'] ?? 0)) > 0 ? log(max(1, (float)$row['traffic_value']), 10) * 5 : 0);
        if (!empty($row['matched_post_id'])) $score += 15;
        if (!empty($row['platform_signals_count'])) $score += 8;
        if (!empty($row['competitor_signal'])) $score += 8;
        if (!empty($row['manual_competitor_exact_match_weakness'])) $score += 7;
        if (!empty($row['is_noise'])) $score -= 30;
        $priority = $score >= 75 ? 'P1' : ($score >= 50 ? 'P2' : ($score >= 25 ? 'P3' : 'archive'));
        return ['score' => round($score,2), 'priority' => $priority];
    }
}
