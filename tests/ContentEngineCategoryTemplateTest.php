<?php
declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) define('ABSPATH', __DIR__);
    if (!class_exists('WP_Post')) { class WP_Post { public $ID=0; public $post_title=''; public $post_type='tmw_category_page'; public function __construct($a=[]){foreach($a as $k=>$v){$this->$k=$v;}} } }
    if (!function_exists('esc_html')) { function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES); } }
    if (!function_exists('esc_url')) { function esc_url($s){ return (string)$s; } }
    if (!function_exists('home_url')) { function home_url($path=''){ return 'https://top-models.webcam' . $path; } }
    if (!function_exists('wp_parse_url')) { function wp_parse_url($url,$component=-1){ return parse_url((string)$url,$component); } }
    if (!function_exists('get_bloginfo')) { function get_bloginfo($show=''){ return 'Top Models'; } }

    require_once dirname(__DIR__) . '/includes/services/class-title-fixer.php';
    require_once dirname(__DIR__) . '/includes/content/class-content-engine.php';
}

namespace TMWSEO\Engine\Tests {
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use TMWSEO\Engine\Content\ContentEngine;

    final class ContentEngineCategoryTemplateTest extends TestCase {
        private function invoke(string $method, array $args) {
            $ref = new ReflectionMethod(ContentEngine::class, $method);
            $ref->setAccessible(true);
            return $ref->invokeArgs(null, $args);
        }

        public function test_category_seo_title_builder_starts_with_focus_keyword_and_contains_best_and_year(): void {
            $title = $this->invoke('build_category_page_seo_title', ['Amateur Webcam Models', '2026']);
            $this->assertStringStartsWith('Amateur Webcam Models:', $title);
            $this->assertStringContainsString('Best', $title);
            $this->assertStringContainsString('2026', $title);
        }

        public function test_category_meta_description_builder_starts_with_focus_keyword(): void {
            $meta = $this->invoke('build_category_page_meta_description', ['Amateur Webcam Models', 'Top-Models.Webcam']);
            $this->assertStringStartsWith('Amateur Webcam Models', $meta);
            $this->assertStringContainsString('neutral browsing context', $meta);
        }

        public function test_category_body_starts_with_focus_keyword_and_contains_required_headings(): void {
            $post = new \WP_Post(['ID' => 11, 'post_title' => 'Amateur Webcam Models']);
            $payload = $this->invoke('build_category_page_template_preview', [$post, 'Amateur Webcam Models', ['longtail' => ['Blonde Webcam Models']]]);
            $content = (string) $payload['content_html'];

            $this->assertStringStartsWith('<p>Amateur Webcam Models', $content);
            $this->assertStringContainsString('<h2>About Amateur Webcam Models</h2>', $content);
            $this->assertStringContainsString('<h2>Browse Amateur Webcam Models Videos and Models</h2>', $content);
            $this->assertStringContainsString('<h2>Frequently Asked Questions</h2>', $content);
            $this->assertStringContainsString('<h3>What are Amateur Webcam Models?</h3>', $content);
        }

        public function test_category_template_targets_non_thin_content_length(): void {
            $post = new \WP_Post(['ID' => 14, 'post_title' => 'Amateur Webcam Models']);
            $payload = $this->invoke('build_category_page_template_preview', [$post, 'Amateur Webcam Models', []]);
            $word_count = str_word_count(trim(strip_tags((string) $payload['content_html'])));
            $this->assertGreaterThanOrEqual(500, $word_count);
            $this->assertLessThanOrEqual(800, $word_count);
        }

        public function test_category_template_includes_required_internal_links_and_no_external_links(): void {
            $post = new \WP_Post(['ID' => 15, 'post_title' => 'Blonde Webcam Models']);
            $payload = $this->invoke('build_category_page_template_preview', [$post, 'Blonde Webcam Models', []]);
            $content = (string) $payload['content_html'];
            $this->assertStringContainsString('href="https://top-models.webcam/models/"', $content);
            $this->assertStringContainsString('href="https://top-models.webcam/videos/"', $content);
            $this->assertSame(2, preg_match_all('/href="https?:\/\/[^\"]+"/', $content, $matches));
        }

        public function test_category_template_does_not_write_term_or_robot_state(): void {
            $post = new \WP_Post(['ID' => 12, 'post_title' => 'Blonde Webcam Models']);
            $payload = $this->invoke('build_category_page_template_preview', [$post, 'Blonde Webcam Models', []]);
            $serialized = json_encode($payload);

            $this->assertIsArray($payload);
            $this->assertStringNotContainsString('rank_math_robots', (string) $serialized);
            $this->assertStringNotContainsString('term_id', (string) $serialized);
            $this->assertStringNotContainsString('slug', (string) $serialized);
        }

        public function test_category_content_is_neutral_non_graphic(): void {
            $post = new \WP_Post(['ID' => 13, 'post_title' => 'Latina Webcam Models']);
            $payload = $this->invoke('build_category_page_template_preview', [$post, 'Latina Webcam Models', []]);
            $content = strtolower(strip_tags((string) $payload['content_html']));
            $this->assertStringNotContainsString('explicit', $content);
            $this->assertStringNotContainsString('porn', $content);
            $this->assertStringContainsString('neutral', $content);
        }
    }
}
