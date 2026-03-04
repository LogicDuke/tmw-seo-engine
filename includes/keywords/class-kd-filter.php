<?php
namespace TMWSEO\Engine\Keywords;

if (!defined('ABSPATH')) { exit; }

/**
 * KD-based scoring + tiering.
 * Ported from tmw-seo-autopilot (KD_Filter).
 */
class KDFilter {

    public static function classify(float $kd): string {
        if ($kd <= 20) return 'very_easy';
        if ($kd <= 30) return 'easy';
        if ($kd <= 50) return 'medium';
        if ($kd <= 70) return 'hard';
        return 'very_hard';
    }

    /**
     * Opportunity score from 0..100 where higher is better.
     * Combines volume and keyword difficulty, with a small intent multiplier.
     */
    public static function opportunity_score(?float $kd, ?int $volume, string $intent = 'mixed'): ?float {
        if ($kd === null) return null;

        $vol = max(0, (int)($volume ?? 0));
        $kd  = max(0.0, min(100.0, (float)$kd));

        // Normalize volume using log scaling (so 10k doesn't dwarf everything).
        $volume_score = ($vol > 0) ? (log(1 + $vol) / log(1 + 10000)) : 0.0; // 0..1-ish
        $kd_score     = 1.0 - ($kd / 100.0); // 1..0

        $intent_multiplier = match ($intent) {
            'commercial' => 1.2,
            'informational' => 1.0,
            'free' => 0.95,
            'local' => 0.9,
            default => 1.0,
        };

        $score = ($volume_score * 0.6 + $kd_score * 0.4) * $intent_multiplier * 100.0;

        // Penalize ultra-low volume unless KD is very easy.
        if ($vol < 10 && $kd > 20) {
            $score *= 0.7;
        }

        return round(max(0.0, min(100.0, $score)), 2);
    }
}
