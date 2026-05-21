<?php
/**
 * ModelOpportunityScorer
 *
 * Scores a model opportunity row and returns a priority bucket.
 *
 * Scoring components
 * ──────────────────
 *  30 pts max  — volume (primary + family), log₁₀-based
 *  15 pts max  — traffic_value, log₁₀-based
 *  15 pts      — matched_post_id present (model already on site)
 *   8 pts      — platform_signals_count
 *   8 pts      — competitor_signal flag
 *   7 pts      — manual_competitor_exact_match_weakness flag
 *  15 pts max  — kws_seo_score (0-100 KWS metric), scaled 0-15
 * 31 pts      — competition = 0.0 AND combined volume ≥ 100 000 (major gap)
 *  25 pts      — competition = 0.0 AND combined volume ≥  10 000 (strong gap)
 * -30 pts      — is_noise flag (overrides everything)
 *
 * Priority thresholds
 * ───────────────────
 *  P1   ≥ 75
 *  P2   ≥ 50
 *  P3   ≥ 25
 *  archive < 25
 *
 * Real-world validation
 * ─────────────────────
 * Dani Daniels  — 1M vol, comp=0, seo=100, no match yet: 30+15+30 = 75 → P1 ✓
 * Anisyia       — 12k vol, comp=0, seo=68, $26k traffic, matched: 30+15+10+25+15 = 95 → P1 ✓
 * Natasha Nice  — 823k vol, comp=0, no match: 30+15+30 = 75 → P1 ✓
 *
 * @package TMWSEO\Engine\Opportunities
 * @since   5.10.0  Added kws_seo_score, kws_competition, score_explanation.
 */

namespace TMWSEO\Engine\Opportunities;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ModelOpportunityScorer {

    // ── Thresholds ────────────────────────────────────────────────────────

    private const P1_THRESHOLD           = 75.0;
    private const P2_THRESHOLD           = 50.0;
    private const P3_THRESHOLD           = 25.0;

    /** Minimum combined volume for the strong-gap zero-competition bonus. */
    private const ZERO_COMP_VOLUME_STRONG = 10_000;

    /** Minimum combined volume for the major-gap zero-competition bonus. */
    private const ZERO_COMP_VOLUME_MAJOR  = 100_000;

    /** Bonus when comp=0 and vol ≥ 100 000 (e.g. Dani Daniels 1M). */
    private const ZERO_COMP_BONUS_MAJOR   = 31.0;

    /** Bonus when comp=0 and vol ≥ 10 000 (e.g. Anisyia 12k). */
    private const ZERO_COMP_BONUS_STRONG  = 25.0;

    /** Maximum points from KWS seo_score. */
    private const SEO_SCORE_CAP   = 15.0;

    /** Scale factor: seo_score (0-100) × factor → 0-15. */
    private const SEO_SCORE_SCALE = 0.15;

    /**
     * Score a single opportunity row.
     *
     * Expected keys (all optional, zero-defaults):
     *   primary_volume  (int)
     *   family_volume   (int)
     *   traffic_value   (float)
     *   matched_post_id (int|string — truthy = model exists on site)
     *   platform_signals_count (int)
     *   competitor_signal (bool|int)
     *   manual_competitor_exact_match_weakness (bool|int)
     *   kws_seo_score   (float 0-100, or -1 = unknown)
     *   kws_competition (float 0.0-1.0, or -1 = unknown)
     *   is_noise        (bool|int)
     *
     * @param array<string,mixed> $row
     * @return array{score:float, priority:string, score_explanation:string}
     */
    public static function score( array $row ): array {
        $reasons = [];
        $score   = 0.0;

        // ── 1. Volume ─────────────────────────────────────────────────────
        $pv    = (int) ( $row['primary_volume'] ?? 0 );
        $fv    = (int) ( $row['family_volume']  ?? 0 );
        $vol   = max( 1, $pv + $fv );
        $v_pts = min( 30.0, log( $vol, 10 ) * 8 );
        $score += $v_pts;
        if ( $v_pts > 0 ) {
            $reasons[] = sprintf( 'volume(%d)=+%.1f', $pv + $fv, $v_pts );
        }

        // ── 2. Traffic value ──────────────────────────────────────────────
        $tv    = (float) ( $row['traffic_value'] ?? 0 );
        if ( $tv > 0 ) {
            $t_pts  = min( 15.0, log( max( 1, $tv ), 10 ) * 5 );
            $score += $t_pts;
            $reasons[] = sprintf( 'traffic_value($%.0f)=+%.1f', $tv, $t_pts );
        }

        // ── 3. Matched post ───────────────────────────────────────────────
        if ( ! empty( $row['matched_post_id'] ) ) {
            $score    += 15.0;
            $reasons[] = 'matched_post=+15';
        }

        // ── 4. Platform signals ───────────────────────────────────────────
        if ( ! empty( $row['platform_signals_count'] ) ) {
            $score    += 8.0;
            $reasons[] = 'platform_signals=+8';
        }

        // ── 5. Competitor signal ──────────────────────────────────────────
        if ( ! empty( $row['competitor_signal'] ) ) {
            $score    += 8.0;
            $reasons[] = 'competitor_signal=+8';
        }

        // ── 6. Manual competitor exact-match weakness ─────────────────────
        if ( ! empty( $row['manual_competitor_exact_match_weakness'] ) ) {
            $score    += 7.0;
            $reasons[] = 'competitor_weakness=+7';
        }

        // ── 7. KWS SEO Score ──────────────────────────────────────────────
        $seo_score = (float) ( $row['kws_seo_score'] ?? -1 );
        if ( $seo_score >= 0 ) {
            $s_pts  = min( self::SEO_SCORE_CAP, $seo_score * self::SEO_SCORE_SCALE );
            $score += $s_pts;
            $reasons[] = sprintf( 'kws_seo_score(%.0f)=+%.1f', $seo_score, $s_pts );
        }

        // ── 8. Zero-competition keyword gap ───────────────────────────────
        //
        // competition = exactly 0.0 (not -1/unknown) combined with high volume
        // is the primary indicator of a real organic gap.  Competitors like
        // PornHub, OnlyFans, and LiveJasmin are NOT targeting these model names;
        // zero competition on a 1M-volume term confirms the gap is winnable.
        //
        // Tiered bonus:
        //   vol ≥ 100 000 → +30 (major gap: likely P1 even without a matched post)
        //   vol ≥  10 000 → +25 (strong gap)
        //
        $kws_competition = (float) ( $row['kws_competition'] ?? -1 );
        $combined_vol    = $pv + $fv;

        if ( $kws_competition === 0.0 ) {
            if ( $combined_vol >= self::ZERO_COMP_VOLUME_MAJOR ) {
                $score    += self::ZERO_COMP_BONUS_MAJOR;
                $reasons[] = sprintf(
                    'zero_competition_gap_major(vol=%d)=+%.0f',
                    $combined_vol,
                    self::ZERO_COMP_BONUS_MAJOR
                );
            } elseif ( $combined_vol >= self::ZERO_COMP_VOLUME_STRONG ) {
                $score    += self::ZERO_COMP_BONUS_STRONG;
                $reasons[] = sprintf(
                    'zero_competition_gap_strong(vol=%d)=+%.0f',
                    $combined_vol,
                    self::ZERO_COMP_BONUS_STRONG
                );
            }
        }

        // ── 9. Noise penalty ──────────────────────────────────────────────
        if ( ! empty( $row['is_noise'] ) ) {
            $score    -= 30.0;
            $reasons[] = 'is_noise=-30';
        }

        // ── Priority bucket ───────────────────────────────────────────────
        $score    = round( $score, 2 );
        $priority = self::priority_from_score( $score );

        $explanation = sprintf(
            '%s (score=%.2f): %s',
            $priority,
            $score,
            $reasons ? implode( ', ', $reasons ) : 'no signals'
        );

        return [
            'score'             => $score,
            'priority'          => $priority,
            'score_explanation' => $explanation,
        ];
    }

    /**
     * Convert a numeric score to a priority string.
     */
    public static function priority_from_score( float $score ): string {
        if ( $score >= self::P1_THRESHOLD ) { return 'P1'; }
        if ( $score >= self::P2_THRESHOLD ) { return 'P2'; }
        if ( $score >= self::P3_THRESHOLD ) { return 'P3'; }
        return 'archive';
    }
}
