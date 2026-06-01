<?php
/**
 * Tests for model keyword strategy classification.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\ModelKeywordStrategyClassifier;

require_once __DIR__ . '/../includes/keywords/class-model-keyword-strategy-classifier.php';

final class ModelKeywordStrategyClassifierTest extends TestCase {
    private ModelKeywordStrategyClassifier $classifier;

    protected function setUp(): void {
        $this->classifier = new ModelKeywordStrategyClassifier();
    }

    public function test_named_model_opportunities_with_strong_demand(): void {
        $anisyia = $this->classifier->classify([ 'keyword' => 'anisyia', 'volume' => 12100, 'seo_score' => 68 ], 'Anisyia', 'model');
        $arianna = $this->classifier->classify([ 'keyword' => 'arianna', 'volume' => 33100, 'seo_score' => 75 ], 'Arianna', 'model');

        $this->assertSame('named_model_opportunity', $anisyia['model_keyword_strategy']);
        $this->assertSame('high', $anisyia['model_keyword_confidence']);
        $this->assertSame('approve_named_model_keyword', $anisyia['model_keyword_recommended_action']);
        $this->assertContains('model_name_match', $anisyia['model_keyword_reason_codes']);
        $this->assertContains('named_model_search_demand', $anisyia['model_keyword_reason_codes']);
        $this->assertSame('named_model_opportunity', $arianna['model_keyword_strategy']);
    }

    public function test_lj_named_model_opportunities(): void {
        $forward = $this->classifier->classify([ 'keyword' => 'anisyia livejasmin', 'volume' => 1900 ], 'Anisyia', 'model');
        $reverse = $this->classifier->classify([ 'keyword' => 'livejasmin anisyia', 'volume' => 170 ], 'Anisyia', 'model');
        $jasminForward = $this->classifier->classify([ 'keyword' => 'anisyia jasmin', 'volume' => 90, 'seo_score' => 45 ], 'Anisyia', 'model');
        $jasminReverse = $this->classifier->classify([ 'keyword' => 'jasmin anisyia', 'volume' => 20 ], 'Anisyia', 'model');

        $this->assertSame('lj_named_model_opportunity', $forward['model_keyword_strategy']);
        $this->assertSame('high', $forward['model_keyword_confidence']);
        $this->assertSame('approve_lj_named_model_keyword', $forward['model_keyword_recommended_action']);
        $this->assertContains('livejasmin_modifier', $forward['model_keyword_reason_codes']);
        $this->assertContains('model_name_match', $forward['model_keyword_reason_codes']);
        $this->assertContains('lj_model_search_demand', $forward['model_keyword_reason_codes']);
        $this->assertNotContains('manual_review_required', $forward['model_keyword_reason_codes']);
        $this->assertSame('lj_named_model_opportunity', $reverse['model_keyword_strategy']);
        $this->assertContains($reverse['model_keyword_confidence'], [ 'medium', 'high' ]);
        $this->assertSame('approve_lj_named_model_keyword', $reverse['model_keyword_recommended_action']);
        $this->assertSame('lj_named_model_opportunity', $jasminForward['model_keyword_strategy']);
        $this->assertSame('lj_named_model_opportunity', $jasminReverse['model_keyword_strategy']);
    }

    public function test_fallback_model_intent_keywords(): void {
        $webcam = $this->classifier->classify([ 'keyword' => 'anisyia webcam model' ], 'Anisyia', 'model');
        $ljModel = $this->classifier->classify([ 'keyword' => 'lexy ness livejasmin model' ], 'Lexy Ness', 'model');

        $this->assertSame('fallback_model_intent', $webcam['model_keyword_strategy']);
        $this->assertSame('use_as_fallback_model_keyword', $webcam['model_keyword_recommended_action']);
        $this->assertContains('fallback_model_modifier', $webcam['model_keyword_reason_codes']);
        $this->assertSame('lj_named_model_opportunity', $ljModel['model_keyword_strategy']);
    }

    public function test_weak_manual_review_keywords(): void {
        $chat = $this->classifier->classify([ 'keyword' => 'anisyia chat', 'volume' => 10 ], 'Anisyia', 'model');
        $unknown = $this->classifier->classify([ 'keyword' => 'unknownname', 'volume' => 10 ], '', 'model');

        $this->assertSame('weak_manual_review', $chat['model_keyword_strategy']);
        $this->assertSame('queue_for_manual_review', $chat['model_keyword_recommended_action']);
        $this->assertContains('manual_review_required', $chat['model_keyword_reason_codes']);
        $this->assertSame('weak_manual_review', $unknown['model_keyword_strategy']);
    }

    public function test_not_model_intent_keywords(): void {
        foreach ([ 'cheapest sex cam sites', 'creative live cam chat hd webcam', 'free adult webcam' ] as $keyword) {
            $result = $this->classifier->classify([ 'keyword' => $keyword ], '', 'model');
            $this->assertSame('not_model_intent', $result['model_keyword_strategy']);
            $this->assertSame('reject_not_model_intent', $result['model_keyword_recommended_action']);
            $this->assertContains('category_or_site_intent', $result['model_keyword_reason_codes']);
        }
    }

    public function test_deferred_phase_2_performer_expansion_keywords(): void {
        foreach ([ 'dani daniels', 'natasha nice' ] as $keyword) {
            $result = $this->classifier->classify([ 'keyword' => $keyword ], '', 'model');
            $this->assertSame('deferred_phase_2_performer_expansion', $result['model_keyword_strategy']);
            $this->assertSame('defer_until_lj_50_model_milestone', $result['model_keyword_recommended_action']);
            $this->assertContains('post_50_lj_model_expansion', $result['model_keyword_reason_codes']);
        }
    }

    public function test_non_model_pool_is_not_applicable(): void {
        $category = $this->classifier->classify([ 'keyword' => 'anisyia', 'volume' => 12100 ], 'Anisyia', 'category');
        $video = $this->classifier->classify([ 'keyword' => 'anisyia', 'volume' => 12100 ], 'Anisyia', 'video');

        $this->assertSame('not_applicable', $category['model_keyword_strategy']);
        $this->assertSame('not_applicable', $video['model_keyword_strategy']);
    }


    public function test_lj_named_model_uses_passed_context_for_kwe_rows_without_model_column(): void {
        $forward = $this->classifier->classify([ 'keyword' => 'anisyia livejasmin', 'volume' => 1900 ], 'anisyia', 'model');
        $reverse = $this->classifier->classify([ 'keyword' => 'livejasmin anisyia', 'volume' => 170 ], 'anisyia', 'model');

        $this->assertSame('lj_named_model_opportunity', $forward['model_keyword_strategy']);
        $this->assertSame('approve_lj_named_model_keyword', $forward['model_keyword_recommended_action']);
        $this->assertSame('lj_named_model_opportunity', $reverse['model_keyword_strategy']);
        $this->assertSame('approve_lj_named_model_keyword', $reverse['model_keyword_recommended_action']);
    }
}
