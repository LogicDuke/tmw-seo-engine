<?php
/**
 * ModelKeywordRoleClassifier
 *
 * Classifies a single keyword string against a model-entity name.
 *
 * Roles (in decision order)
 * ──────────────────────────
 *  primary            — exact model-name match (safe Rank Math focus keyword)
 *  rankmath_candidate — model name + generic cam descriptor (safe as focus keyword)
 *  platform_intent    — model name + platform brand (content support / internal linking)
 *  content_support    — model name + benign modifier (safe support keyword)
 *  manual_review      — model name + adult modifier (preserve; never auto-focus)
 *  risky_explicit     — adult modifier without model name (unrelated)
 *  noise              — unrelated, empty, URL, numeric, or generic noise
 *
 * Key fixes (v5.10.0)
 * ────────────────────
 * 1. Adult modifier + model name → manual_review (was risky_explicit).
 *    "anisyia porn" (4 400 vol) is market intelligence, not trash.
 *
 * 2. CAM_TERMS now contains ONLY generic cam descriptors (live cam, webcam chat,
 *    cam show).  Platform names (livejasmin, onlyfans, …) were removed from
 *    CAM_TERMS because they belong to PLATFORM_TERMS.  Mixing them caused
 *    "anisyia livejasmin" to be promoted to rankmath_candidate instead of
 *    platform_intent.
 *
 * 3. CAM_TERMS check runs before CONTENT_TERMS so "live cam" is not
 *    downgraded by the bare word "live" in CONTENT_TERMS.
 *
 * @package TMWSEO\Engine\Opportunities
 * @since   5.10.0
 */

namespace TMWSEO\Engine\Opportunities;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ModelKeywordRoleClassifier {

    /**
     * Adult modifier terms.
     * With model name → manual_review.  Without model name → risky_explicit.
     */
    private const ADULT_MODIFIERS = '/\b(porn|sex|xxx|nude|leaks?)\b/i';

    /**
     * Generic cam descriptors — model name + these → rankmath_candidate.
     * Platform names deliberately excluded (handled by PLATFORM_TERMS).
     * Checked BEFORE CONTENT_TERMS to prevent "live cam" being caught by "live".
     */
    private const CAM_TERMS = '/\b(live cam|webcam chat|cam show)\b/i';

    /**
     * Platform and brand terms → platform_intent.
     */
    private const PLATFORM_TERMS = '/\b(livejasmin|camsoda|fancentro|onlyfans|fansly|pornhub|facebook|tiktok|official|website|link hub)\b/i';

    /**
     * Generic informational terms → content_support.
     */
    private const CONTENT_TERMS = '/\b(about|official profile|today|recent|website|live)\b/i';

    /**
     * @param string $keyword  Raw keyword from KWS or other source.
     * @param string $model    Model entity name (e.g. "Anisyia", "Dani Daniels").
     * @return string  One of: primary | rankmath_candidate | platform_intent |
     *                         content_support | manual_review | risky_explicit | noise
     */
    public static function classify( string $keyword, string $model ): string {
        $k = ModelOpportunityNormalizer::normalize_keyword( $keyword );
        $m = ModelOpportunityNormalizer::normalize_keyword( $model );

        if ( $m === '' ) { return 'noise'; }
        if ( ModelOpportunityNormalizer::is_noise( $keyword ) ) { return 'noise'; }
        if ( $k === $m ) { return 'primary'; }

        $has_model_name = str_contains( $k, $m );

        // Adult modifier: model present → manual_review; absent → risky_explicit.
        if ( preg_match( self::ADULT_MODIFIERS, $k ) ) {
            return $has_model_name ? 'manual_review' : 'risky_explicit';
        }

        // Rank Math candidate: model + generic cam phrase (no platform names here).
        if ( $has_model_name && preg_match( self::CAM_TERMS, $k ) ) {
            return 'rankmath_candidate';
        }

        // Platform intent: any platform/brand term present.
        if ( preg_match( self::PLATFORM_TERMS, $k ) ) {
            return 'platform_intent';
        }

        // Content support via informational terms.
        if ( preg_match( self::CONTENT_TERMS, $k ) ) {
            return 'content_support';
        }

        return $has_model_name ? 'content_support' : 'noise';
    }
}
