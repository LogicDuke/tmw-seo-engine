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
            'Anisyia LiveJasmin Webcam Model & Live Cam Guide ' . $year,
            TemplateContent::build_default_model_seo_title('Anisyia', 'LiveJasmin', 123)
        );
    }

    public function test_unknown_platform_fallback_title_generation(): void {
        $year = gmdate('Y');

        $this->assertSame(
            'Abby Murray Webcam Model & Live Cam Profile Guide ' . $year,
            TemplateContent::build_default_model_seo_title('Abby Murray', '', 456)
        );
    }

    public function test_neutral_fallback_label_does_not_appear_in_title(): void {
        $title = TemplateContent::build_default_model_seo_title('Abby Murray', ' official profile links ', 789);

        $this->assertStringNotContainsString('official profile links', strtolower($title));
        $this->assertStringContainsString('Webcam Model & Live Cam Profile Guide', $title);
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
