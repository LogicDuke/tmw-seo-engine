<?php
declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) define('ABSPATH', __DIR__);
    if (!function_exists('esc_html')) { function esc_html($s){ return (string)$s; } }
    if (!function_exists('esc_url')) { function esc_url($s){ return (string)$s; } }
    if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s){ return strip_tags((string)$s); } }
    if (!function_exists('get_post_meta')) { function get_post_meta($id,$k,$s=true){ return ''; } }
    if (!function_exists('get_the_title')) { function get_the_title($id=0){ return 'Title'; } }
    require_once dirname(__DIR__) . '/includes/content/class-video-content-builder.php';
}

namespace TMWSEO\Engine\Tests {
    use PHPUnit\Framework\TestCase;
    use TMWSEO\Engine\Content\VideoContentBuilder;

    final class VideoContentBuilderTest extends TestCase {
        public function test_derive_focus_keyword_prefers_video_chat(): void {
            $kw = VideoContentBuilder::derive_focus_keyword('Lexy Ness Plays — Webcam Video Chat', 'Lexy Ness');
            $this->assertSame('Lexy Ness video chat', $kw);
        }
        public function test_secondary_keywords_are_unique_and_exclude_primary(): void {
            $secondary = VideoContentBuilder::build_secondary_keywords('Lexy Ness', 'Lexy Ness video chat');
            $this->assertNotContains('Lexy Ness video chat', $secondary);
            $this->assertSame($secondary, array_values(array_unique($secondary)));
        }
        public function test_seo_title_starts_with_focus_keyword_and_has_best_and_year(): void {
            $title = VideoContentBuilder::build_seo_title('Lexy Ness', 'Lexy Ness video chat', 'X');
            $this->assertStringStartsWith('Lexy Ness Video Chat:', $title);
            $this->assertStringContainsString('Best', $title);
            $this->assertStringContainsString(gmdate('Y'), $title);
        }
        public function test_meta_description_starts_with_focus_keyword(): void {
            $desc = VideoContentBuilder::build_meta_description('Lexy Ness', 'Lexy Ness video chat', 'X');
            $this->assertStringStartsWith('Lexy Ness video chat', $desc);
        }
    }
}
