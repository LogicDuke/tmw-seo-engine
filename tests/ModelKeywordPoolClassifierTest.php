<?php
/**
 * Tests for PR 602 model keyword pool phrase classification.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\ModelKeywordPoolClassifier;

if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/keywords/class-model-keyword-pool-classifier.php';

final class ModelKeywordPoolClassifierTest extends TestCase {
    private ModelKeywordPoolClassifier $classifier;

    protected function setUp(): void {
        $this->classifier = new ModelKeywordPoolClassifier();
    }

    public function test_unsafe_standalone_exact_words_are_not_standalone_allowed(): void {
        foreach ([ 'video', 'videos', 'chat', 'photos', 'pictures', 'search', 'home', 'live', 'top', 'new', 'real', 'information', 'page', 'bio', 'profile' ] as $word) {
            $result = $this->classifier->classify($word);
            $this->assertSame(ModelKeywordPoolClassifier::CLASS_UNSAFE_STANDALONE, $result['keyword_class']);
            $this->assertFalse($result['standalone_allowed']);
            $this->assertSame(ModelKeywordPoolClassifier::USAGE_MODIFIER_ONLY, $result['suggested_usage']);
        }
    }

    public function test_core_model_terms_are_primary_focus_allowed(): void {
        foreach ([ 'webcam model', 'cam model', 'adult video chat model', 'live cam model', 'live webcam model', 'livejasmin model' ] as $term) {
            $result = $this->classifier->classify($term);
            $this->assertSame(ModelKeywordPoolClassifier::CLASS_CORE_MODEL_TERM, $result['keyword_class']);
            $this->assertTrue($result['standalone_allowed']);
            $this->assertContains($result['suggested_usage'], [ ModelKeywordPoolClassifier::USAGE_PRIMARY_FOCUS_ALLOWED, ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED ]);
        }
    }

    public function test_platform_terms_are_modifier_only(): void {
        foreach ([ 'livejasmin', 'jasmin', 'chaturbate', 'camsoda' ] as $term) {
            $result = $this->classifier->classify($term);
            $this->assertSame(ModelKeywordPoolClassifier::CLASS_PLATFORM_TERM, $result['keyword_class']);
            $this->assertFalse($result['standalone_allowed']);
            $this->assertSame(ModelKeywordPoolClassifier::USAGE_MODIFIER_ONLY, $result['suggested_usage']);
        }
    }

    public function test_attribute_and_geo_terms_are_modifier_only(): void {
        foreach ([ 'brunette', 'blonde', 'latina', 'colombian', 'asian', 'redhead' ] as $term) {
            $result = $this->classifier->classify($term);
            $this->assertContains($result['keyword_class'], [ ModelKeywordPoolClassifier::CLASS_ATTRIBUTE_TERM, ModelKeywordPoolClassifier::CLASS_GEO_LANGUAGE_TERM ]);
            $this->assertFalse($result['standalone_allowed']);
            $this->assertSame(ModelKeywordPoolClassifier::USAGE_MODIFIER_ONLY, $result['suggested_usage']);
        }
    }

    public function test_personal_model_keyword_requires_context(): void {
        $without = $this->classifier->classify('anisyia', []);
        $with = $this->classifier->classify('anisyia', [ 'model_name' => 'Anisyia' ]);

        $this->assertNotSame(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, $without['keyword_class']);
        $this->assertSame(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, $with['keyword_class']);
    }

    public function test_generated_longtail_flagged_when_is_generated_context(): void {
        $result = $this->classifier->classify('anisyia livejasmin model', [ 'is_generated' => true ]);
        $this->assertSame(ModelKeywordPoolClassifier::CLASS_GENERATED_LONGTAIL, $result['keyword_class']);
        $this->assertFalse($result['standalone_allowed']);
        $this->assertSame(ModelKeywordPoolClassifier::USAGE_REVIEW_REQUIRED, $result['suggested_usage']);
    }

    public function test_unsafe_standalone_wins_over_generated_flag(): void {
        $result = $this->classifier->classify('video', [ 'is_generated' => true ]);
        $this->assertSame(ModelKeywordPoolClassifier::CLASS_UNSAFE_STANDALONE, $result['keyword_class']);
        $this->assertNotSame(ModelKeywordPoolClassifier::CLASS_GENERATED_LONGTAIL, $result['keyword_class']);
    }
}
