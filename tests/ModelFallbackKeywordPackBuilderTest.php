<?php
/**
 * Tests for PR 602 fallback keyword pack previews.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\ModelFallbackKeywordPackBuilder;
use TMWSEO\Engine\Keywords\ModelKeywordPoolClassifier;

if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/keywords/class-model-keyword-pool-classifier.php';
require_once __DIR__ . '/../includes/keywords/class-model-fallback-keyword-pack-builder.php';

final class ModelFallbackKeywordPackBuilderTest extends TestCase {
    private ModelKeywordPoolClassifier $classifier;
    private ModelFallbackKeywordPackBuilder $builder;

    protected function setUp(): void {
        $this->classifier = new ModelKeywordPoolClassifier();
        $this->builder = new ModelFallbackKeywordPackBuilder($this->classifier);
    }

    public function test_strong_strength_when_three_or_more_personal_keywords(): void {
        $preview = $this->builder->build_preview(4457, 'Anisyia', [ 'anisyia', 'anisyia livejasmin', 'livejasmin anisyia' ], [ 'webcam model', 'private chat' ]);
        $this->assertSame('strong', $preview['keyword_data_strength']);
        $this->assertSame('anisyia', $preview['primary_keyword_recommendation']);
        $this->assertSame([], $preview['fallback_generated_patterns']);
        $this->assertContains('strong_personal_keyword_data', $preview['reason_codes']);
        $this->assertTrue($preview['preview_only']);
    }

    public function test_medium_strength_when_one_personal_keyword(): void {
        $preview = $this->builder->build_preview(4457, 'Anisyia', [ 'anisyia' ], [ 'webcam model', 'private chat' ]);
        $this->assertSame('medium', $preview['keyword_data_strength']);
        $this->assertSame('anisyia', $preview['primary_keyword_recommendation']);
        $this->assertNotEmpty($preview['fallback_generated_patterns']);
        $this->assertContains('medium_personal_keyword_data', $preview['reason_codes']);
        $this->assertContains('fallback_fill_applied', $preview['reason_codes']);
        $this->assertTrue($preview['preview_only']);
    }

    public function test_low_strength_when_zero_personal_keywords(): void {
        $preview = $this->builder->build_preview(4457, 'NoDataModel', [], [ 'webcam model', 'private chat' ]);
        $this->assertSame('low', $preview['keyword_data_strength']);
        $this->assertStringContainsString('nodatamodel', $preview['primary_keyword_recommendation']);
        $this->assertGreaterThanOrEqual(5, count($preview['fallback_generated_patterns']));
        $this->assertContains('low_keyword_data_fallback_only', $preview['reason_codes']);
        $this->assertContains('all_generated_queued_for_review', $preview['reason_codes']);
        $this->assertTrue($preview['preview_only']);
    }

    public function test_generated_patterns_classified_as_generated_longtail(): void {
        $preview = $this->builder->build_preview(4457, 'NoDataModel', [], []);
        foreach ($preview['fallback_generated_patterns'] as $pattern) {
            $result = $this->classifier->classify($pattern, [ 'is_generated' => true ]);
            $this->assertSame(ModelKeywordPoolClassifier::CLASS_GENERATED_LONGTAIL, $result['keyword_class']);
            $this->assertSame(ModelKeywordPoolClassifier::USAGE_REVIEW_REQUIRED, $result['suggested_usage']);
        }
    }

    public function test_no_unsafe_standalone_becomes_primary(): void {
        foreach ([ 'video', 'chat', 'live', 'photos' ] as $model_name) {
            $preview = $this->builder->build_preview(1, $model_name, [], [ 'video', 'chat', 'live', 'photos' ]);
            $this->assertNotContains($preview['primary_keyword_recommendation'], [ 'video', 'chat', 'live', 'photos' ]);
        }
    }
}
