<?php
/**
 * Tests for ModelOpportunityScorer (v5.10.0 scoring logic).
 *
 * Runs standalone — no WordPress functions required.
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
if ( ! function_exists( 'remove_accents' ) ) {
    function remove_accents( $v ) { return $v; }
}

require_once __DIR__ . '/../includes/seo-engine/opportunities/class-model-opportunity-scorer.php';

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Opportunities\ModelOpportunityScorer;

final class ModelOpportunityScorerTest extends TestCase {

    // ── Zero-competition gap bonus ────────────────────────────────────────

    public function test_zero_competition_high_volume_is_P1(): void {
        // Dani Daniels: 1 000 000 vol, competition = 0, no match yet.
        $result = ModelOpportunityScorer::score( [
            'primary_volume'  => 1_000_000,
            'family_volume'   => 3_000_000,
            'traffic_value'   => 0,
            'kws_competition' => 0.0,
            'kws_seo_score'   => 100,
        ] );

        $this->assertSame( 'P1', $result['priority'],
            'High-volume, zero-competition model keyword must score P1.' );
        $this->assertGreaterThanOrEqual( 75.0, $result['score'] );
    }

    public function test_zero_competition_below_volume_threshold_not_auto_P1(): void {
        // Low volume + competition = 0 should NOT get the gap bonus.
        $result = ModelOpportunityScorer::score( [
            'primary_volume'  => 500,
            'family_volume'   => 500,
            'traffic_value'   => 0,
            'kws_competition' => 0.0,
            'kws_seo_score'   => 10,
        ] );

        $this->assertNotSame( 'P1', $result['priority'],
            'Low-volume keyword with zero competition must not auto-escalate to P1.' );
    }

    public function test_unknown_competition_does_not_grant_bonus(): void {
        // competition = -1 (unknown/N/A) — bonus must NOT fire.
        $with_unknown = ModelOpportunityScorer::score( [
            'primary_volume'  => 50_000,
            'family_volume'   => 50_000,
            'kws_competition' => -1.0,   // unknown
            'kws_seo_score'   => -1.0,
        ] );

        $with_zero = ModelOpportunityScorer::score( [
            'primary_volume'  => 50_000,
            'family_volume'   => 50_000,
            'kws_competition' => 0.0,    // confirmed zero
            'kws_seo_score'   => -1.0,
        ] );

        $this->assertGreaterThan(
            $with_unknown['score'],
            $with_zero['score'],
            'Confirmed zero competition must score higher than unknown competition.'
        );
    }

    // ── KWS SEO Score contribution ────────────────────────────────────────

    public function test_kws_seo_score_contributes_to_score(): void {
        $base = ModelOpportunityScorer::score( [
            'primary_volume' => 10_000,
            'kws_seo_score'  => -1.0,   // unknown
        ] );

        $with_seo = ModelOpportunityScorer::score( [
            'primary_volume' => 10_000,
            'kws_seo_score'  => 80.0,   // good KWS score
        ] );

        $this->assertGreaterThan(
            $base['score'],
            $with_seo['score'],
            'kws_seo_score > 0 must increase the opportunity score.'
        );
    }

    public function test_kws_seo_score_capped_at_15(): void {
        $result = ModelOpportunityScorer::score( [
            'primary_volume' => 1,
            'kws_seo_score'  => 200.0, // absurd value
        ] );

        // Score from seo_score alone cannot exceed 15; with volume(1)=0.
        $this->assertLessThanOrEqual( 15.0, $result['score'] );
    }

    // ── Anisyia real-world check ──────────────────────────────────────────

    public function test_anisyia_with_match_is_P1(): void {
        // vol=12 100, competition=0, traffic=$26 645, seo_score=68, matched.
        $result = ModelOpportunityScorer::score( [
            'primary_volume'  => 12_100,
            'family_volume'   => 12_100,
            'traffic_value'   => 26_645,
            'kws_seo_score'   => 68.0,
            'kws_competition' => 0.0,
            'matched_post_id' => 1,
        ] );

        $this->assertSame( 'P1', $result['priority'],
            'Anisyia: 12 100 vol, zero competition, existing match must be P1.' );
    }

    // ── Score explanation ─────────────────────────────────────────────────

    public function test_score_explanation_returned(): void {
        $result = ModelOpportunityScorer::score( [ 'primary_volume' => 5_000 ] );

        $this->assertArrayHasKey( 'score_explanation', $result );
        $this->assertNotEmpty( $result['score_explanation'] );
    }

    public function test_score_explanation_mentions_zero_competition(): void {
        $result = ModelOpportunityScorer::score( [
            'primary_volume'  => 50_000,
            'kws_competition' => 0.0,
        ] );

        $this->assertStringContainsString(
            'zero_competition_gap',
            $result['score_explanation'],
            'Explanation must mention zero_competition_gap when the bonus fires.'
        );
    }

    // ── Noise penalty ─────────────────────────────────────────────────────

    public function test_noise_flag_archives(): void {
        $result = ModelOpportunityScorer::score( [
            'primary_volume' => 1_000_000,
            'is_noise'       => 1,
        ] );

        $this->assertSame( 'archive', $result['priority'],
            'is_noise must always result in archive priority regardless of volume.' );
    }

    // ── Matched post boost ────────────────────────────────────────────────

    public function test_matched_post_boosts_score(): void {
        $unmatched = ModelOpportunityScorer::score( [ 'primary_volume' => 10_000 ] );
        $matched   = ModelOpportunityScorer::score( [
            'primary_volume' => 10_000,
            'matched_post_id' => 99,
        ] );

        $this->assertGreaterThan( $unmatched['score'], $matched['score'] );
    }
}
