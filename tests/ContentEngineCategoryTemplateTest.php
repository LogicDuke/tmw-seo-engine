<?php
declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) define('ABSPATH', __DIR__);
    require_once __DIR__ . '/bootstrap/wp-post-stub.php';
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
            $this->assertStringNotContainsString('ctwmsg.com', $content);
            $this->assertStringNotContainsString('livejasmin.com', $content);
            $this->assertStringNotContainsString('/go/livejasmin/', $content);
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

        public function test_category_keyword_pack_falls_back_to_title_without_ai_credentials(): void {
            $post = new \WP_Post(['ID' => 21, 'post_title' => 'Big Boob Cam', 'post_type' => 'tmw_category_page']);
            $payload = $this->invoke('build_category_keyword_pack', [$post, ['strategy' => 'template']]);

            $this->assertSame('Big Boob Cam', $payload['primary']);
            $this->assertSame('post_title', $payload['sources']['primary']);
        }

        public function test_category_template_generation_creates_non_empty_content_for_template_strategy(): void {
            $post = new \WP_Post(['ID' => 22, 'post_title' => 'Big Boob Cam', 'post_type' => 'tmw_category_page']);
            $keywordPack = $this->invoke('build_category_keyword_pack', [$post, ['strategy' => 'template']]);
            $payload = $this->invoke('build_template_preview_payload', [$post, $keywordPack, $keywordPack['primary'], 'category_page']);

            $this->assertNotSame('', trim((string) $payload['content_html']));
            $this->assertStringContainsString('<h2>About Big Boob Cam</h2>', (string) $payload['content_html']);
        }


        public function test_category_keyword_fallback_sentences_preserve_faq_structure(): void {
            $html = '<p>Amateur Cams directory overview.</p><h2>What This Category Covers</h2><p>Browse listings.</p><h2>Frequently Asked Questions</h2><h3>How do I browse?</h3><p>Use the directory links.</p>';

            $covered = $this->invoke('inject_category_keyword_fallback_sentences', [$html, [
                'amateur webcam',
                'amateur tv cams',
                'live amateur sex cams',
                'amateur sex chat',
            ]]);

            $this->assertStringContainsString('Amateur Cams', $covered);
            $this->assertStringContainsString('amateur webcam', $covered);
            $this->assertStringContainsString('amateur tv cams', $covered);
            $this->assertStringContainsString('live amateur sex cams', $covered);
            $this->assertStringContainsString('amateur sex chat', $covered);
            $this->assertStringContainsString('<h2>What This Category Covers</h2>', $covered);
            $this->assertStringContainsString('<h2>Frequently Asked Questions</h2>', $covered);
            $this->assertLessThan(strpos($covered, '<h2>Frequently Asked Questions</h2>'), strpos($covered, 'amateur sex chat'));
        }

        public function test_category_bootstrap_does_not_set_ready_to_index(): void {
            $post = new \WP_Post(['ID' => 23, 'post_title' => 'Big Boob Cam', 'post_type' => 'tmw_category_page']);
            $GLOBALS['_tmw_test_post_meta'][23] = [];

            $payload = $this->invoke('bootstrap_manual_category_generate', [$post, ['strategy' => 'template']]);

            $this->assertSame('Big Boob Cam', $payload['primary']);
            $this->assertSame('', get_post_meta(23, '_tmwseo_ready_to_index', true));
            $this->assertSame('Big Boob Cam', get_post_meta(23, '_tmwseo_keyword', true));
            $this->assertSame(80, get_post_meta(23, '_tmwseo_keyword_confidence', true));
        }

        // ── PR B (v5.9.x): rotating fallback sentences, no mechanical filler ──────

        public function test_category_keyword_fallback_sentences_use_rotating_templates_not_single_pattern(): void {
            $html = '<p>Amateur Cams directory overview.</p><h2>What This Category Covers</h2><p>Browse listings.</p><h2>Frequently Asked Questions</h2><h3>How do I browse?</h3><p>Use the directory links.</p>';

            $covered = $this->invoke('inject_category_keyword_fallback_sentences', [$html, [
                'amateur webcam',
                'amateur tv cams',
                'live amateur sex cams',
                'amateur sex chat',
            ]]);

            $this->assertStringNotContainsString('Visitors searching for', $covered);

            foreach (['amateur webcam', 'amateur tv cams', 'live amateur sex cams', 'amateur sex chat'] as $keyword) {
                $this->assertStringContainsString($keyword, $covered);
            }

            preg_match_all('/<p>(.*?)<\/p>/', $covered, $matches);
            $injected = array_slice($matches[1], -4);
            $structures = array_map(static function (string $sentence): string {
                return preg_replace('/amateur webcam|amateur tv cams|live amateur sex cams|amateur sex chat/', '{KW}', $sentence);
            }, $injected);

            // Four missing keywords must not collapse into the same sentence shape.
            $this->assertGreaterThan(1, count(array_unique($structures)));
        }

        // ── PR B (v5.9.x): deterministic Layer 2 semantic/supporting keywords ─────

        public function test_category_supporting_keyword_fallback_is_deterministic_and_excludes_primary(): void {
            $first  = $this->invoke('deterministic_category_supporting_keywords', [845, 'Amateur Cams']);
            $second = $this->invoke('deterministic_category_supporting_keywords', [845, 'Amateur Cams']);

            $this->assertSame($first, $second);
            $this->assertLessThanOrEqual(6, count($first));
            $this->assertNotContains('amateur cams', array_map('strtolower', $first));
        }

        public function test_category_keyword_pack_fills_content_terms_when_no_approved_pool_exists(): void {
            $post = new \WP_Post(['ID' => 845, 'post_title' => 'Amateur Cams', 'post_type' => 'tmw_category_page']);
            $GLOBALS['_tmw_test_post_meta'][845] = [
                'rank_math_focus_keyword'       => 'Amateur Cams',
                'rank_math_additional_keywords' => 'amateur webcam, amateur tv cams, live amateur sex cams, amateur sex chat',
            ];

            $pack = $this->invoke('build_category_keyword_pack', [$post, ['strategy' => 'template']]);

            // CategoryApprovedKeywordResolver is not loaded in this isolated test file,
            // so class_exists() is false and content_terms falls back to the
            // deterministic Layer 2 pool added in PR B — this asserts that fallback fires.
            $this->assertNotEmpty($pack['content_terms']);
            $this->assertLessThanOrEqual(6, count($pack['content_terms']));
        }

        public function test_category_template_with_content_terms_keeps_word_count_and_keyword_coverage(): void {
            $post = new \WP_Post(['ID' => 845, 'post_title' => 'Amateur Cams', 'post_type' => 'tmw_category_page']);
            $keywordPack = [
                'content_terms' => ['webcam directory', 'model profiles', 'video clips', 'performer listings'],
            ];

            $payload = $this->invoke('build_category_page_template_preview', [$post, 'Amateur Cams', $keywordPack]);
            $content = (string) $payload['content_html'];
            $wordCount = str_word_count(trim(strip_tags($content)));

            $this->assertStringNotContainsString('Visitors searching for', $content);
            $this->assertStringNotContainsString('This draft keeps language safe', $content);
            $this->assertStringNotContainsString('for operators', $content);
            $this->assertStringNotContainsString('In SEO terms', $content);
            $this->assertGreaterThanOrEqual(500, $wordCount);
            $this->assertLessThanOrEqual(800, $wordCount);
        }
    }
}
