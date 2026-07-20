<?php
/**
 * Focused checks for generated model SEO titles and model video anchors.
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TMWSEO\Engine\Content\TemplateContent;

require_once TMWSEO_ENGINE_PATH . 'includes/services/class-title-fixer.php';
require_once TMWSEO_ENGINE_PATH . 'includes/content/class-template-content.php';

class TemplateContentTitleAnchorTest extends TestCase {

    public function test_known_platform_title_generation(): void {
        $year = gmdate('Y');

        $this->assertSame(
            'Anisyia LiveJasmin Live Webcam Guide ' . $year,
            TemplateContent::build_default_model_seo_title('Anisyia', 'LiveJasmin', 123)
        );
    }


    public function test_known_platform_title_generation_varies_deterministically(): void {
        $year = gmdate('Y');
        $cases = [
            ['Anisyia', 123, 'Anisyia LiveJasmin Live Webcam Guide ' . $year],
            ['Abby Murray', 456, 'Abby Murray LiveJasmin Webcam Model Profile ' . $year],
            ['Mia Collie', 789, 'Mia Collie LiveJasmin Live Cam Profile Guide ' . $year],
            ['Julieta Montesco', 101112, 'Julieta Montesco LiveJasmin Cam Profile & Access Guide ' . $year],
        ];

        $descriptor_phrases = [];
        foreach ($cases as [$name, $post_id, $expected]) {
            $title = TemplateContent::build_default_model_seo_title($name, 'LiveJasmin', $post_id);
            $this->assertSame($expected, $title);
            $this->assertStringContainsString($name, $title);
            $this->assertStringContainsString('LiveJasmin', $title);
            $this->assertMatchesRegularExpression('/\b(?:cam|webcam|live cam)\b/i', $title);
            $this->assertStringContainsString($year, $title);
            $this->assertLessThanOrEqual(65, (function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title)));
            $this->assertFalse(TemplateContent::is_weak_auto_model_title($title, $name, 'LiveJasmin', $post_id));

            $descriptor_phrases[] = trim(str_replace([$name, 'LiveJasmin', $year], '', $title));
        }

        $this->assertGreaterThan(1, count(array_unique($descriptor_phrases)));
    }

    public function test_unknown_platform_fallback_title_generation(): void {
        $year = gmdate('Y');

        $this->assertSame(
            'Abby Murray Webcam Model & Live Cam Profile Guide ' . $year,
            TemplateContent::build_default_model_seo_title('Abby Murray', '', 456)
        );
    }

    public function test_neutral_fallback_label_does_not_appear_in_title(): void {
        $year = gmdate('Y');
        $title = TemplateContent::build_default_model_seo_title('Abby Murray', ' official profile links ', 789);

        $this->assertSame('Abby Murray Webcam Model & Live Cam Profile Guide ' . $year, $title);
        $this->assertStringNotContainsString('official profile links', strtolower($title));
    }

    public function test_placeholder_platform_label_is_treated_as_unknown_platform(): void {
        $year = gmdate('Y');

        $this->assertSame(
            'Alice Webcam Model & Live Cam Profile Guide ' . $year,
            TemplateContent::build_default_model_seo_title('Alice', 'the platform', 123)
        );
    }

    public function test_real_platform_label_still_uses_platform_aware_formula(): void {
        $year = gmdate('Y');

        $this->assertSame(
            'Alice LiveJasmin Webcam Profile & Cam Guide ' . $year,
            TemplateContent::build_default_model_seo_title('Alice', 'LiveJasmin', 123)
        );
    }

    public function test_v112_known_platform_title_is_not_weak(): void {
        $this->assertFalse(
            TemplateContent::is_weak_auto_model_title('Anisyia LiveJasmin Webcam Model & Live Cam Guide 2026', 'Anisyia', 'LiveJasmin')
        );
    }

    public function test_known_platform_title_without_platform_is_weak(): void {
        $this->assertTrue(
            TemplateContent::is_weak_auto_model_title('Mia Collie Webcam Model & Live Cam Profile Guide 2026', 'Mia Collie', 'LiveJasmin')
        );
    }

    public function test_known_platform_title_accepts_normalized_platform_equivalent(): void {
        $this->assertFalse(
            TemplateContent::is_weak_auto_model_title('Mia Collie Live Jasmin Webcam Model & Live Cam Guide 2026', 'Mia Collie', 'LiveJasmin')
        );
    }

    public function test_unknown_platform_still_preserves_existing_validation(): void {
        $this->assertFalse(
            TemplateContent::is_weak_auto_model_title('Mia Collie Webcam Model & Live Cam Profile Guide 2026', 'Mia Collie', 'the platform')
        );
    }


    public function test_long_known_platform_title_preserves_platform_and_year(): void {
        $year = gmdate('Y');
        $name = 'Alexandria Catherine Montgomery Smith';
        $title = TemplateContent::build_default_model_seo_title($name, 'LiveJasmin', 321);

        $this->assertStringContainsString($name, $title);
        $this->assertStringContainsString('LiveJasmin', $title);
        $this->assertStringContainsString($year, $title);
        $this->assertLessThanOrEqual(65, (function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title)));
        $this->assertFalse(TemplateContent::is_weak_auto_model_title($title, $name, 'LiveJasmin', 321));
    }

    public function test_v112_unknown_platform_title_is_not_weak(): void {
        $this->assertFalse(
            TemplateContent::is_weak_auto_model_title('Abby Murray Webcam Model & Live Cam Profile Guide 2026', 'Abby Murray')
        );
    }

    public function test_obviously_weak_generic_title_remains_weak(): void {
        $this->assertTrue(
            TemplateContent::is_weak_auto_model_title('Live Cam Profile', 'Anisyia')
        );

        $this->assertTrue(
            TemplateContent::is_weak_auto_model_title('Webcam Model & Live Cam Guide 2026', 'Anisyia')
        );

        $this->assertTrue(
            TemplateContent::is_weak_auto_model_title('Live Cam Model Webcam Model & Live Cam Guide 2026')
        );
    }

    public function test_video_anchor_with_model_name(): void {
        $this->assertSame(
            'Watch a video featuring Anisyia',
            self::model_video_anchor_text('Anisyia')
        );
    }

    public function test_video_anchor_fallback_when_model_name_is_empty(): void {
        $this->assertSame(
            'Watch a video featuring this model',
            self::model_video_anchor_text('   ')
        );
    }

    private static function model_video_anchor_text(string $model_title): string {
        $method = new ReflectionMethod(TemplateContent::class, 'model_video_anchor_text');
        $method->setAccessible(true);

        return (string) $method->invoke(null, $model_title);
    }
}
