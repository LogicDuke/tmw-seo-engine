<?php
/**
 * Tests for ModelKeywordRoleClassifier (v5.10.0 manual_review fix).
 *
 * Runs standalone — no WordPress functions required.
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
if ( ! function_exists( 'remove_accents' ) ) {
    function remove_accents( $v ) { return $v; }
}

require_once __DIR__ . '/../includes/seo-engine/opportunities/class-model-opportunity-normalizer.php';
require_once __DIR__ . '/../includes/seo-engine/opportunities/class-model-keyword-role-classifier.php';

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Opportunities\ModelKeywordRoleClassifier;

final class ModelKeywordRoleClassifierTest extends TestCase {

    // ── Primary ───────────────────────────────────────────────────────────

    public function test_exact_model_name_is_primary(): void {
        $this->assertSame( 'primary', ModelKeywordRoleClassifier::classify( 'Anisyia', 'anisyia' ) );
        $this->assertSame( 'primary', ModelKeywordRoleClassifier::classify( 'dani daniels', 'Dani Daniels' ) );
    }

    // ── Adult modifier + model name → manual_review (v5.10.0 fix) ────────

    public function test_adult_modifier_with_model_name_is_manual_review(): void {
        // These should be manual_review, NOT risky_explicit.
        $this->assertSame(
            'manual_review',
            ModelKeywordRoleClassifier::classify( 'anisyia porn', 'anisyia' ),
            '"anisyia porn" (4 400 vol) must be manual_review, not discarded.'
        );
        $this->assertSame(
            'manual_review',
            ModelKeywordRoleClassifier::classify( 'anisyia sex', 'anisyia' )
        );
        $this->assertSame(
            'manual_review',
            ModelKeywordRoleClassifier::classify( 'dani daniels nude', 'dani daniels' )
        );
        $this->assertSame(
            'manual_review',
            ModelKeywordRoleClassifier::classify( 'valentina nappi xxx', 'valentina nappi' )
        );
    }

    // ── Adult modifier WITHOUT model name → risky_explicit ───────────────

    public function test_adult_modifier_without_model_name_is_risky_explicit(): void {
        $this->assertSame(
            'risky_explicit',
            ModelKeywordRoleClassifier::classify( 'hardcore porn', 'anisyia' )
        );
        $this->assertSame(
            'risky_explicit',
            ModelKeywordRoleClassifier::classify( 'free sex videos', 'dani daniels' )
        );
        $this->assertSame(
            'risky_explicit',
            ModelKeywordRoleClassifier::classify( 'porn', 'anisyia' )
        );
    }

    // ── manual_review keywords must NEVER be focus-safe ──────────────────
    // (Rank Math preview already blocks risky_explicit and manual_review — we
    //  verify the classifier never mis-promotes them.)

    public function test_manual_review_not_primary(): void {
        $role = ModelKeywordRoleClassifier::classify( 'anisyia porn', 'anisyia' );
        $this->assertNotSame( 'primary', $role );
        $this->assertNotSame( 'rankmath_candidate', $role );
    }

    // ── Platform intent ───────────────────────────────────────────────────

    public function test_model_name_plus_platform_is_platform_intent(): void {
        $this->assertSame(
            'platform_intent',
            ModelKeywordRoleClassifier::classify( 'anisyia livejasmin', 'anisyia' )
        );
        $this->assertSame(
            'platform_intent',
            ModelKeywordRoleClassifier::classify( 'dani daniels onlyfans', 'dani daniels' )
        );
    }

    // ── Rank Math candidate ───────────────────────────────────────────────

    public function test_model_name_plus_cam_term_is_rankmath_candidate(): void {
        $this->assertSame(
            'rankmath_candidate',
            ModelKeywordRoleClassifier::classify( 'anisyia live cam', 'anisyia' )
        );
        $this->assertSame(
            'rankmath_candidate',
            ModelKeywordRoleClassifier::classify( 'anisyia webcam chat', 'anisyia' )
        );
    }

    // ── Content support ───────────────────────────────────────────────────

    public function test_model_name_variant_is_content_support(): void {
        // Close variant containing model name but no special modifier.
        $this->assertSame(
            'content_support',
            ModelKeywordRoleClassifier::classify( 'anisyia profile', 'anisyia' )
        );
        $this->assertSame(
            'content_support',
            ModelKeywordRoleClassifier::classify( 'anisyia com', 'anisyia' )
        );
    }

    // ── Noise / unrelated names ───────────────────────────────────────────

    public function test_unrelated_name_is_noise(): void {
        // "danielle garonce" should be noise when model is "dani daniels".
        $this->assertSame(
            'noise',
            ModelKeywordRoleClassifier::classify( 'danielle garonce', 'dani daniels' )
        );
    }

    public function test_empty_model_is_noise(): void {
        $this->assertSame( 'noise', ModelKeywordRoleClassifier::classify( 'anisyia', '' ) );
    }

    public function test_url_keyword_is_noise(): void {
        $this->assertSame(
            'noise',
            ModelKeywordRoleClassifier::classify( 'https://anisyia.com', 'anisyia' )
        );
    }

    // ── Total volume rows never reach classifier (handled upstream) ───────
    // (This is a guard test — the classifier itself should treat "total volume" as noise.)

    public function test_total_volume_string_is_noise(): void {
        $role = ModelKeywordRoleClassifier::classify( 'Total Volume', 'anisyia' );
        // "Total Volume" does not contain "anisyia" so it is noise.
        $this->assertSame( 'noise', $role );
    }
}
