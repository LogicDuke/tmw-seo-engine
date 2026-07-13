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

    // ── category-keywords-cta-block stubs: term resolution + affiliate CTA ──
    if (!class_exists('WP_Term')) {
        class WP_Term {
            public $term_id = 0;
            public $taxonomy = 'category';
            public $slug = '';
            public $name = '';
            public function __construct(array $props = []) {
                foreach ($props as $k => $v) { $this->$k = $v; }
            }
        }
    }
    if (!function_exists('taxonomy_exists')) { function taxonomy_exists($tax){ return in_array($tax, ['category','post_tag'], true); } }
    if (!function_exists('get_term_by')) {
        function get_term_by($field, $value, $taxonomy) {
            $map = $GLOBALS['_tmw_test_terms_by_slug'] ?? [];
            if ($field === 'slug' && isset($map[$value])) { return $map[$value]; }
            return false;
        }
    }
    if (!function_exists('get_term')) { function get_term($term_id, $taxonomy = ''){ return false; } }
    if (!function_exists('esc_html__')) { function esc_html__($s, $d = null){ return htmlspecialchars((string) $s, ENT_QUOTES); } }
    if (!function_exists('get_term_meta')) {
        function get_term_meta($term_id, $key = '', $single = false) {
            if ($key === 'tmw_category_affiliate_url') {
                return (string) ($GLOBALS['_tmw_test_affiliate_urls'][$term_id] ?? '');
            }
            return '';
        }
    }
    if (!function_exists('tmwseo_get_category_affiliate_url')) {
        function tmwseo_get_category_affiliate_url(\WP_Term $term): string {
            return (string) ($GLOBALS['_tmw_test_affiliate_urls'][$term->term_id] ?? '');
        }
    }

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

        public function test_category_seo_title_builder_starts_with_focus_keyword_has_year_and_no_best_claim(): void {
            $title = $this->invoke('build_category_page_seo_title', ['Amateur Webcam Models', '2026']);
            $this->assertStringStartsWith('Amateur Webcam Models:', $title);
            $this->assertStringContainsString('Webcam Category Guide', $title);
            $this->assertStringNotContainsString('Best', $title);
            $this->assertStringContainsString('2026', $title);
        }

        public function test_category_meta_description_builder_starts_with_focus_keyword_and_has_no_internal_wording(): void {
            $meta = $this->invoke('build_category_page_meta_description', ['Amateur Webcam Models', 'Top-Models.Webcam']);
            $this->assertStringStartsWith('Amateur Webcam Models', $meta);
            $this->assertStringContainsString('browse webcam model profiles', $meta);
            $this->assertStringNotContainsString('manual' . ' review', strtolower($meta));
            $this->assertStringNotContainsString('neutral browsing context', $meta);
        }

        public function test_category_body_starts_with_focus_keyword_and_contains_required_headings(): void {
            $post = new \WP_Post(['ID' => 11, 'post_title' => 'Amateur Webcam Models']);
            $payload = $this->invoke('build_category_page_template_preview', [$post, 'Amateur Webcam Models', ['longtail' => ['Blonde Webcam Models']]]);
            $content = (string) $payload['content_html'];

            $this->assertStringStartsWith('<p>Amateur Webcam Models', $content);
            $this->assertStringContainsString('<h2>About Amateur Webcam Models</h2>', $content);
            $this->assertStringContainsString('<h2>Browse Amateur Webcam Models Videos and Models</h2>', $content);
            $this->assertStringContainsString('<h2>Frequently Asked Questions</h2>', $content);
            $this->assertStringContainsString('<h3>What is the Amateur Webcam Models category?</h3>', $content);
            $this->assertStringNotContainsString('<h3>What are ', $content);
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
            $this->assertStringContainsString('href="https://top-models.webcam/categories/"', $content);
            $this->assertSame(3, preg_match_all('/href="https?:\/\/[^\"]+"/', $content, $matches));
            foreach ($matches[0] as $href) {
                $this->assertStringContainsString('top-models.webcam', $href);
            }
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

        // ── PR B2 (v5.9.x): blended fallback paragraph, no mechanical filler ─────

        public function test_category_keyword_fallback_sentences_blend_missing_keywords_into_one_paragraph(): void {
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

            $this->assertStringContainsString('amateur webcam, amateur tv cams, live amateur sex cams, or amateur sex chat options', $covered);

            $beforeFaq = strstr($covered, '<h2>Frequently Asked Questions</h2>', true);
            $this->assertIsString($beforeFaq);
            $this->assertSame(3, substr_count($beforeFaq, '<p>'));
            $this->assertSame(1, substr_count($beforeFaq, 'This category is also useful for visitors comparing'));
        }

        public function test_category_keyword_fallback_sentence_handles_single_missing_keyword(): void {
            $html = '<p>Amateur Cams directory overview.</p>';

            $covered = $this->invoke('inject_category_keyword_fallback_sentences', [$html, [
                'amateur webcam',
            ]]);

            $this->assertStringContainsString('visitors comparing amateur webcam pages', $covered);
            $this->assertStringNotContainsString('Visitors searching for', $covered);
            $this->assertStringNotContainsString('amateur webcam options', $covered);
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

        // ── PR: category content cleanup — public internal-wording guard ────────

        public function test_category_legacy_builder_output_contains_no_internal_wording(): void {
            $post = new \WP_Post(['ID' => 31, 'post_title' => 'Big Boob Cam', 'post_type' => 'tmw_category_page']);
            $payload = $this->invoke('build_category_page_template_preview', [$post, 'Big Boob Cam', []]);

            $haystack = strtolower(
                (string) $payload['seo_title'] . ' '
                . (string) $payload['meta_description'] . ' '
                . strip_tags((string) $payload['content_html'])
            );

            foreach (['draft', 'pipeline', 'bridge', 'manual' . ' review', 'generator', 'taxonomy structure', 'tmw_category_page', 'best webcam category' . ' guide', 'internal' . ' links', 'these paths are' . ' internal', 'internal model and video' . ' listings', 'existing internal category or tag' . ' links'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $haystack, "Forbidden internal term leaked: {$forbidden}");
            }
        }

        public function test_category_section_template_pool_contains_no_public_internal_wording(): void {
            $path = dirname(__DIR__) . '/data/category-section-templates.json';
            $pool = json_decode((string) file_get_contents($path), true);

            $this->assertIsArray($pool);
            $this->assertIsArray($pool['sections'] ?? null);

            foreach ($pool['sections'] as $sectionKey => $section) {
                foreach (($section['variants'] ?? []) as $variant) {
                    $variantId = (string) ($variant['id'] ?? $sectionKey);
                    $haystack = strtolower(
                        strip_tags((string) ($variant['h2'] ?? '') . ' ' . (string) ($variant['body'] ?? ''))
                    );

                    foreach (['internal directory' . ' links', 'internal tag' . ' links', 'internal category' . ' links', 'internal' . ' links', 'internally' . ' linked', 'internal' . ' navigation', 'internal' . ' taxonomy', 'internal linking' . ' structure'] as $forbidden) {
                        $this->assertStringNotContainsString($forbidden, $haystack, "Forbidden public wording leaked in {$sectionKey}/{$variantId}: {$forbidden}");
                    }
                }
            }
        }

        public function test_category_faq_heading_is_grammatical_for_singular_category_names(): void {
            $post = new \WP_Post(['ID' => 32, 'post_title' => 'Big Boob Cam', 'post_type' => 'tmw_category_page']);
            $payload = $this->invoke('build_category_page_template_preview', [$post, 'Big Boob Cam', []]);
            $content = (string) $payload['content_html'];

            $this->assertStringContainsString('<h3>What is the Big Boob Cam category?</h3>', $content);
            $this->assertStringNotContainsString('What are Big Boob Cam?', $content);
        }

        public function test_category_seo_title_has_no_best_claim(): void {
            $title = $this->invoke('build_category_page_seo_title', ['Big Boob Cam', '2026']);
            $this->assertStringNotContainsString('Best', $title);
            $this->assertStringNotContainsString('best', $title);
        }

        public function test_category_meta_description_has_no_manual_review_wording(): void {
            $meta = strtolower((string) $this->invoke('build_category_page_meta_description', ['Big Boob Cam', 'top-models.webcam']));
            $this->assertStringNotContainsString('manual' . ' review', $meta);
            $this->assertStringNotContainsString('manual seo review', $meta);
        }

        public function test_category_related_links_helper_falls_back_to_real_categories_hub_link(): void {
            $html = (string) $this->invoke('build_category_related_links_html', [845]);

            $this->assertStringContainsString('href="https://top-models.webcam/categories/"', $html);
            $this->assertStringNotContainsString('<ul>', $html);
            $this->assertStringNotContainsString('/category/', $html);
        }

        // ── category-keywords-cta-block: pool keyword coverage + CTA lifecycle ──

        public function test_supporting_keywords_from_pool_are_woven_naturally_with_length_cap(): void {
            $post = new \WP_Post(['ID' => 901, 'post_title' => 'Amateur Cams', 'post_type' => 'tmw_category_page']);
            $GLOBALS['_tmw_test_post_meta'][901] = [];

            $html = '<p>Amateur Cams directory overview text for browsing.</p>'
                . '<h2>What This Category Covers</h2><p>Browse listings across the archive.</p>'
                . '<h2>Frequently Asked Questions</h2><h3>How do I browse?</h3><p>Use the directory links.</p>';

            $keyword_set = [
                'primary_keyword' => 'Amateur Cams',
                'extra_keywords' => [],
                'all_keywords' => ['Amateur Cams'],
                'supporting_keywords' => ['webcam directory', 'model profiles', 'video clips', 'performer listings', 'live cam archive'],
                'supporting_source' => 'category_db_approved',
            ];

            $covered = $this->invoke('ensure_category_keyword_coverage', [$html, $keyword_set, $post]);

            // Short content (<450 words) → cap of 2 supporting insertions.
            $this->assertStringContainsString('webcam directory', $covered);
            $this->assertStringContainsString('model profiles', $covered);
            $this->assertStringNotContainsString('video clips', $covered);
            $this->assertStringNotContainsString('performer listings', $covered);

            // Insertions stay natural prose — no lists, no links inside them.
            $this->assertStringNotContainsString('<ul', $covered);
            $this->assertSame(0, preg_match('/<p class="tmw-cat-supporting">[^<]*<a /', $covered));

            // Structure preserved.
            $this->assertStringContainsString('<h2>Frequently Asked Questions</h2>', $covered);
        }

        public function test_supporting_keyword_weave_is_idempotent_and_skips_present_terms(): void {
            $post = new \WP_Post(['ID' => 902, 'post_title' => 'Amateur Cams', 'post_type' => 'tmw_category_page']);
            $GLOBALS['_tmw_test_post_meta'][902] = [];

            $html = '<p>Amateur Cams overview already mentions the webcam directory once.</p>'
                . '<h2>Frequently Asked Questions</h2><h3>How do I browse?</h3><p>Use the directory links.</p>';

            $keyword_set = [
                'primary_keyword' => 'Amateur Cams',
                'extra_keywords' => [],
                'all_keywords' => ['Amateur Cams'],
                'supporting_keywords' => ['webcam directory', 'model profiles'],
                'supporting_source' => 'category_db_approved',
            ];

            $once  = $this->invoke('ensure_category_keyword_coverage', [$html, $keyword_set, $post]);
            $twice = $this->invoke('ensure_category_keyword_coverage', [$once, $keyword_set, $post]);

            // "webcam directory" was already present → not repeated by the weave.
            $this->assertSame(1, substr_count(strtolower(strip_tags($once)), 'webcam directory'));
            $this->assertStringContainsString('model profiles', $once);

            // Second coverage pass never stacks more supporting sentences.
            $this->assertSame(substr_count($once, 'tmw-cat-supporting'), substr_count($twice, 'tmw-cat-supporting'));
            $this->assertSame(1, substr_count(strtolower(strip_tags($twice)), 'model profiles'));
        }

        public function test_keyword_set_exposes_supporting_keywords_from_pack_content_terms(): void {
            $post = new \WP_Post(['ID' => 903, 'post_title' => 'Big Boob Cam', 'post_type' => 'tmw_category_page']);
            $GLOBALS['_tmw_test_post_meta'][903] = [
                'rank_math_focus_keyword' => 'Big Boob Cam',
            ];

            $keyword_pack = [
                'primary' => 'Big Boob Cam',
                'content_terms' => ['webcam directory', 'model profiles', 'Big Boob Cam', ''],
            ];

            $set = $this->invoke('normalize_category_content_keyword_set', [$post, 'Big Boob Cam', $keyword_pack]);

            $this->assertSame('Big Boob Cam', $set['primary_keyword']);
            $this->assertContains('webcam directory', $set['supporting_keywords']);
            $this->assertContains('model profiles', $set['supporting_keywords']);
            // Pool terms duplicating the focus keyword are excluded.
            $this->assertNotContains('Big Boob Cam', $set['supporting_keywords']);
        }

        public function test_affiliate_cta_is_wrapped_in_html_block_and_link_survives_block_split(): void {
            $post = new \WP_Post(['ID' => 910, 'post_title' => 'Amateur Cams', 'post_name' => 'amateur-cams', 'post_type' => 'tmw_category_page']);
            $GLOBALS['_tmw_test_post_meta'][910] = [];
            $GLOBALS['_tmw_test_terms_by_slug']['amateur-cams'] = new \WP_Term(['term_id' => 55, 'taxonomy' => 'category', 'slug' => 'amateur-cams']);
            $GLOBALS['_tmw_test_affiliate_urls'][55] = 'https://example-affiliate.test/offer?x=1';

            $html = '<p>Amateur Cams overview.</p><h2>Frequently Asked Questions</h2><h3>How?</h3><p>Browse.</p>';
            $out  = (string) $this->invoke('append_category_affiliate_cta_html', [$html, $post]);

            // Rendered CTA: from term meta, never hardcoded.
            $this->assertStringContainsString('<!-- wp:html -->', $out);
            $this->assertStringContainsString('<!-- /wp:html -->', $out);
            $this->assertStringContainsString('href="https://example-affiliate.test/offer?x=1"', $out);
            $this->assertStringContainsString('rel="sponsored noopener"', $out);
            $this->assertStringContainsString('tmw-category-page-affiliate-cta', $out);

            // Simulate the block parser split: everything between the wp:html
            // delimiters is its own block; "Convert to blocks" only converts the
            // classic chunk, so the anchor must live fully inside the delimited chunk.
            $this->assertSame(1, preg_match('/<!-- wp:html -->(.*)<!-- \/wp:html -->/s', $out, $m));
            $this->assertStringContainsString('<a href=', $m[1]);
            $classic_chunk = (string) preg_replace('/<!-- wp:html -->.*<!-- \/wp:html -->/s', '', $out);
            $this->assertStringNotContainsString('<a href=', $classic_chunk);

            // Re-append is a no-op (dedupe on marker class).
            $again = (string) $this->invoke('append_category_affiliate_cta_html', [$out, $post]);
            $this->assertSame(1, substr_count($again, 'tmw-category-page-affiliate-cta'));

            unset($GLOBALS['_tmw_test_terms_by_slug']['amateur-cams'], $GLOBALS['_tmw_test_affiliate_urls'][55]);
        }

        public function test_empty_affiliate_url_appends_editable_slot_without_fake_link(): void {
            $post = new \WP_Post(['ID' => 911, 'post_title' => 'Big Boob Cam', 'post_name' => 'big-boob-cam', 'post_type' => 'tmw_category_page']);
            $GLOBALS['_tmw_test_post_meta'][911] = [];
            $GLOBALS['_tmw_test_terms_by_slug']['big-boob-cam'] = new \WP_Term(['term_id' => 56, 'taxonomy' => 'category', 'slug' => 'big-boob-cam']);
            // No affiliate URL set for term 56.

            $html = '<p>Big Boob Cam overview.</p>';
            $out  = (string) $this->invoke('append_category_affiliate_cta_html', [$html, $post]);

            $this->assertStringContainsString('tmw-category-affiliate-slot', $out);
            $this->assertStringContainsString('<!-- wp:html -->', $out);
            // No fake or placeholder link is ever emitted.
            $this->assertStringNotContainsString('<a ', $out);
            $this->assertStringNotContainsString('href=', $out);

            // Slot never blocks a later real CTA append after regeneration.
            $this->assertStringNotContainsString('tmw-category-page-affiliate-cta', $out);

            unset($GLOBALS['_tmw_test_terms_by_slug']['big-boob-cam']);
        }

        public function test_empty_slot_is_upgraded_to_real_cta_once_affiliate_url_is_set(): void {
            $post = new \WP_Post(['ID' => 913, 'post_title' => 'Lifecycle Cam', 'post_name' => 'lifecycle-cam', 'post_type' => 'tmw_category_page']);
            $GLOBALS['_tmw_test_post_meta'][913] = [];
            $GLOBALS['_tmw_test_terms_by_slug']['lifecycle-cam'] = new \WP_Term(['term_id' => 57, 'taxonomy' => 'category', 'slug' => 'lifecycle-cam']);
            unset($GLOBALS['_tmw_test_affiliate_urls'][57]);

            // 1. Generate with no URL → empty editable slot.
            $html = '<p>Lifecycle Cam overview.</p>';
            $with_slot = (string) $this->invoke('append_category_affiliate_cta_html', [$html, $post]);
            $this->assertStringContainsString('tmw-category-affiliate-slot', $with_slot);
            $this->assertStringNotContainsString('tmw-category-page-affiliate-cta', $with_slot);
            $this->assertStringNotContainsString('<a ', $with_slot);

            // 1b. Re-run with still no URL → slot retained once, never duplicated.
            $still_slot = (string) $this->invoke('append_category_affiliate_cta_html', [$with_slot, $post]);
            $this->assertSame(1, substr_count($still_slot, 'tmw-category-affiliate-slot'));

            // 2. Operator sets the affiliate URL on the category term.
            $GLOBALS['_tmw_test_affiliate_urls'][57] = 'https://operator-set.example/track?c=lc';

            // 3. Regenerate → 4. empty slot is replaced by the real CTA.
            $upgraded = (string) $this->invoke('append_category_affiliate_cta_html', [$still_slot, $post]);

            $this->assertStringNotContainsString('tmw-category-affiliate-slot', $upgraded);
            $this->assertStringContainsString('tmw-category-page-affiliate-cta', $upgraded);
            $this->assertStringContainsString('href="https://operator-set.example/track?c=lc"', $upgraded);
            $this->assertStringContainsString('rel="sponsored noopener"', $upgraded);
            // Exactly one wp:html block remains — replaced in place, not appended twice.
            $this->assertSame(1, substr_count($upgraded, '<!-- wp:html -->'));
            $this->assertSame(1, substr_count($upgraded, '<!-- /wp:html -->'));
            $this->assertSame(1, preg_match('/<!-- wp:html -->(.*)<!-- \/wp:html -->/s', $upgraded, $m));
            $this->assertStringContainsString('<a href=', $m[1]);

            // Re-run on upgraded content is a no-op (real CTA dedupe).
            $again = (string) $this->invoke('append_category_affiliate_cta_html', [$upgraded, $post]);
            $this->assertSame($upgraded, $again);

            unset($GLOBALS['_tmw_test_terms_by_slug']['lifecycle-cam'], $GLOBALS['_tmw_test_affiliate_urls'][57]);
        }

        public function test_edited_affiliate_slot_survives_normal_ai_block_regeneration(): void {
            $post = new \WP_Post(['ID' => 915, 'post_title' => 'Preserved Cam', 'post_name' => 'preserved-cam', 'post_type' => 'tmw_category_page']);
            $GLOBALS['_tmw_test_post_meta'][915] = [];
            $GLOBALS['_tmw_test_terms_by_slug']['preserved-cam'] = new \WP_Term(['term_id' => 59, 'taxonomy' => 'category', 'slug' => 'preserved-cam']);
            $GLOBALS['_tmw_test_affiliate_urls'][59] = 'https://term-meta.example/generated';

            $existing = '<p>Operator intro before marker.</p>'
                . "\n<!-- TMWSEO:AI -->\n"
                . '<p>Old generated body.</p>'
                . "\n\n<!-- wp:html -->\n"
                . '<div class="tmw-category-affiliate-slot"><a href="https://operator.example/manual">Operator-added CTA</a></div>'
                . "\n<!-- /wp:html -->\n";

            $regenerated = (string) $this->invoke('upsert_ai_block', [$existing, '<p>New generated body.</p>']);
            $out = (string) $this->invoke('append_category_affiliate_cta_html', [$regenerated, $post]);

            $this->assertStringContainsString('<p>Operator intro before marker.</p>', $out);
            $this->assertStringContainsString('<p>New generated body.</p>', $out);
            $this->assertStringNotContainsString('<p>Old generated body.</p>', $out);
            $this->assertStringContainsString('tmw-category-affiliate-slot', $out);
            $this->assertStringContainsString('https://operator.example/manual', $out);
            $this->assertStringContainsString('Operator-added CTA', $out);
            $this->assertStringNotContainsString('https://term-meta.example/generated', $out);
            $this->assertStringNotContainsString('tmw-category-page-affiliate-cta', $out);
            $this->assertSame(1, substr_count($out, 'tmw-category-affiliate-slot'));

            unset($GLOBALS['_tmw_test_terms_by_slug']['preserved-cam'], $GLOBALS['_tmw_test_affiliate_urls'][59]);
        }

        public function test_empty_affiliate_slot_upgrades_after_ai_block_regeneration_when_url_is_set(): void {
            $post = new \WP_Post(['ID' => 916, 'post_title' => 'Upgrade Cam', 'post_name' => 'upgrade-cam', 'post_type' => 'tmw_category_page']);
            $GLOBALS['_tmw_test_post_meta'][916] = [];
            $GLOBALS['_tmw_test_terms_by_slug']['upgrade-cam'] = new \WP_Term(['term_id' => 60, 'taxonomy' => 'category', 'slug' => 'upgrade-cam']);
            $GLOBALS['_tmw_test_affiliate_urls'][60] = 'https://term-meta.example/upgraded';

            $existing = '<p>Intro.</p>'
                . "\n<!-- TMWSEO:AI -->\n"
                . '<p>Old generated body.</p>'
                . "\n\n<!-- wp:html -->\n"
                . '<div class="tmw-category-affiliate-slot"></div>'
                . "\n<!-- /wp:html -->\n";

            $regenerated = (string) $this->invoke('upsert_ai_block', [$existing, '<p>Fresh generated body.</p>']);
            $out = (string) $this->invoke('append_category_affiliate_cta_html', [$regenerated, $post]);

            $this->assertStringContainsString('<p>Fresh generated body.</p>', $out);
            $this->assertStringNotContainsString('tmw-category-affiliate-slot', $out);
            $this->assertStringContainsString('tmw-category-page-affiliate-cta', $out);
            $this->assertStringContainsString('href="https://term-meta.example/upgraded"', $out);
            $this->assertSame(1, substr_count($out, 'tmw-category-page-affiliate-cta'));

            unset($GLOBALS['_tmw_test_terms_by_slug']['upgrade-cam'], $GLOBALS['_tmw_test_affiliate_urls'][60]);
        }

        public function test_existing_real_cta_dedupes_after_ai_block_regeneration(): void {
            $post = new \WP_Post(['ID' => 917, 'post_title' => 'Dedupe Cam', 'post_name' => 'dedupe-cam', 'post_type' => 'tmw_category_page']);
            $GLOBALS['_tmw_test_post_meta'][917] = [];
            $GLOBALS['_tmw_test_terms_by_slug']['dedupe-cam'] = new \WP_Term(['term_id' => 61, 'taxonomy' => 'category', 'slug' => 'dedupe-cam']);
            $GLOBALS['_tmw_test_affiliate_urls'][61] = 'https://term-meta.example/dedupe';

            $existing = '<p>Intro.</p>'
                . "\n<!-- TMWSEO:AI -->\n"
                . '<p>Old generated body.</p>'
                . "\n\n<!-- wp:html -->\n"
                . '<div class="tmw-category-page-affiliate-cta"><a href="https://term-meta.example/dedupe">Visit</a></div>'
                . "\n<!-- /wp:html -->\n";

            $regenerated = (string) $this->invoke('upsert_ai_block', [$existing, '<p>Fresh generated body.</p>']);
            $out = (string) $this->invoke('append_category_affiliate_cta_html', [$regenerated, $post]);

            $this->assertStringContainsString('<p>Fresh generated body.</p>', $out);
            $this->assertSame(1, substr_count($out, 'tmw-category-page-affiliate-cta'));
            $this->assertSame(1, substr_count($out, 'href="https://term-meta.example/dedupe"'));

            unset($GLOBALS['_tmw_test_terms_by_slug']['dedupe-cam'], $GLOBALS['_tmw_test_affiliate_urls'][61]);
        }

        public function test_slot_with_operator_added_content_is_never_overwritten(): void {
            $post = new \WP_Post(['ID' => 914, 'post_title' => 'Lifecycle Cam', 'post_name' => 'lifecycle-cam-2', 'post_type' => 'tmw_category_page']);
            $GLOBALS['_tmw_test_post_meta'][914] = [];
            $GLOBALS['_tmw_test_terms_by_slug']['lifecycle-cam-2'] = new \WP_Term(['term_id' => 58, 'taxonomy' => 'category', 'slug' => 'lifecycle-cam-2']);
            $GLOBALS['_tmw_test_affiliate_urls'][58] = 'https://term-meta.example/offer';

            // The operator pasted their own link inside the slot block.
            $html = '<p>Lifecycle Cam overview.</p>'
                . "\n\n<!-- wp:html -->\n"
                . '<div class="tmw-category-affiliate-slot"><a href="https://operator-manual.example/custom" target="_blank" rel="sponsored noopener">Custom operator link</a></div>'
                . "\n<!-- /wp:html -->";

            $out = (string) $this->invoke('append_category_affiliate_cta_html', [$html, $post]);

            // Operator content wins: no replacement, no second CTA appended.
            $this->assertSame($html, $out);
            $this->assertStringContainsString('https://operator-manual.example/custom', $out);
            $this->assertStringNotContainsString('https://term-meta.example/offer', $out);

            unset($GLOBALS['_tmw_test_terms_by_slug']['lifecycle-cam-2'], $GLOBALS['_tmw_test_affiliate_urls'][58]);
        }

        public function test_keyword_coverage_does_not_lose_links_when_block_html_is_present(): void {
            $post = new \WP_Post(['ID' => 912, 'post_title' => 'Amateur Cams', 'post_type' => 'tmw_category_page']);
            $GLOBALS['_tmw_test_post_meta'][912] = [];

            $html = '<p>Amateur Cams overview.</p>'
                . '<h2>Frequently Asked Questions</h2><h3>How?</h3><p>Browse.</p>'
                . "\n\n<!-- wp:html -->\n"
                . '<div class="tmw-category-page-affiliate-cta"><a href="https://example-affiliate.test/offer" target="_blank" rel="sponsored noopener">Visit live category related models</a></div>'
                . "\n<!-- /wp:html -->";

            $keyword_set = [
                'primary_keyword' => 'Amateur Cams',
                'extra_keywords' => ['amateur webcam'],
                'all_keywords' => ['Amateur Cams', 'amateur webcam'],
                'supporting_keywords' => ['model profiles'],
            ];

            $covered = $this->invoke('ensure_category_keyword_coverage', [$html, $keyword_set, $post]);

            $this->assertStringContainsString('href="https://example-affiliate.test/offer"', $covered);
            $this->assertStringContainsString('<!-- wp:html -->', $covered);
            $this->assertStringContainsString('<!-- /wp:html -->', $covered);
            $this->assertStringContainsString('amateur webcam', $covered);
            $this->assertStringContainsString('model profiles', $covered);
        }

        // ── v5.8.31: Rank Math extras write path + CTA backend state ─────────

        public function test_pool_backed_pack_writes_exactly_four_rankmath_extras_even_when_meta_not_empty(): void {
            $post_id = 930;
            // Stale pre-pool value: only 2 extras (the Big Boob Cam symptom).
            $GLOBALS['_tmw_test_post_meta'][$post_id] = [
                'rank_math_additional_keywords' => 'old keyword one, old keyword two',
            ];

            $pack = [
                'primary' => 'Big Boob Cam',
                'additional' => ['a1','a2','a3','a4','a5','a6'],
                'rankmath_additional' => ['big boobs webcam', 'huge tits cam', 'busty cam girls', 'big tit live chat'],
                'sources' => ['category_pool' => 'category_db_approved'],
            ];

            $this->invoke('apply_category_rankmath_extras', [$post_id, $pack]);

            $written = (string) get_post_meta($post_id, 'rank_math_additional_keywords', true);
            $this->assertSame('big boobs webcam, huge tits cam, busty cam girls, big tit live chat', $written);
            $this->assertCount(4, array_map('trim', explode(',', $written)));
        }

        public function test_pool_backed_pack_with_more_than_four_extras_is_capped_at_four(): void {
            $post_id = 931;
            $GLOBALS['_tmw_test_post_meta'][$post_id] = [];

            $pack = [
                'primary' => 'Big Boob Cam',
                'rankmath_additional' => ['k1','k2','k3','k4','k5','k6'],
                'sources' => ['category_pool' => 'category_db_approved'],
            ];

            $this->invoke('apply_category_rankmath_extras', [$post_id, $pack]);

            $written = (string) get_post_meta($post_id, 'rank_math_additional_keywords', true);
            $this->assertSame('k1, k2, k3, k4', $written);
        }

        public function test_non_pool_pack_never_overwrites_existing_rankmath_extras(): void {
            $post_id = 932;
            $GLOBALS['_tmw_test_post_meta'][$post_id] = [
                'rank_math_additional_keywords' => 'operator kw one, operator kw two',
            ];

            $pack = [
                'primary' => 'Big Boob Cam',
                'additional' => ['label derived one', 'label derived two'],
                'rankmath_additional' => ['label derived one', 'label derived two'],
                'sources' => [],
            ];

            $this->invoke('apply_category_rankmath_extras', [$post_id, $pack]);

            $this->assertSame(
                'operator kw one, operator kw two',
                (string) get_post_meta($post_id, 'rank_math_additional_keywords', true)
            );
        }

        public function test_non_pool_pack_fills_empty_meta_with_legacy_behavior(): void {
            $post_id = 933;
            $GLOBALS['_tmw_test_post_meta'][$post_id] = [];

            $pack = [
                'primary' => 'Big Boob Cam',
                'additional' => ['label one', 'label two'],
                'sources' => [],
            ];

            $this->invoke('apply_category_rankmath_extras', [$post_id, $pack]);

            $this->assertSame(
                'label one, label two',
                (string) get_post_meta($post_id, 'rank_math_additional_keywords', true)
            );
        }

        public function test_pool_refresh_is_idempotent_for_identical_extras(): void {
            $post_id = 934;
            $GLOBALS['_tmw_test_post_meta'][$post_id] = [
                'rank_math_additional_keywords' => 'k1, k2, k3, k4',
            ];

            $pack = [
                'primary' => 'Big Boob Cam',
                'rankmath_additional' => ['k1', 'k2', 'k3', 'k4'],
                'sources' => ['category_pool' => 'category_db_approved'],
            ];

            $this->invoke('apply_category_rankmath_extras', [$post_id, $pack]);

            $this->assertSame(
                'k1, k2, k3, k4',
                (string) get_post_meta($post_id, 'rank_math_additional_keywords', true)
            );
        }

        public function test_cta_state_summarizer_classifies_all_states(): void {
            if (!class_exists('TMW_Category_Affiliate_CTA')) {
                require_once dirname(__DIR__) . '/includes/categories/class-category-affiliate-cta.php';
            }

            $this->assertSame('cta', \TMW_Category_Affiliate_CTA::summarize_content_cta_state(
                '<p>x</p><!-- wp:html --><div class="tmw-category-page-affiliate-cta"><a href="https://x.test/o">Visit</a></div><!-- /wp:html -->'
            ));
            $this->assertSame('slot_empty', \TMW_Category_Affiliate_CTA::summarize_content_cta_state(
                "<p>x</p>\n<!-- wp:html -->\n<div class=\"tmw-category-affiliate-slot\"></div>\n<!-- /wp:html -->"
            ));
            $this->assertSame('slot_custom', \TMW_Category_Affiliate_CTA::summarize_content_cta_state(
                '<div class="tmw-category-affiliate-slot"><a href="https://manual.test/x">Manual</a></div>'
            ));
            $this->assertSame('none', \TMW_Category_Affiliate_CTA::summarize_content_cta_state('<p>plain content</p>'));
        }

        // ── v5.8.32: focus keyword density reduction + backend notice ────────

        private function density_fixture(): string {
            return '<p>Big Boob Cam is a directory archive for a focused webcam browsing theme.</p>'
                . '<h2>What This Category Covers</h2>'
                . '<p>Big Boob Cam as a category covers performer profiles and video listings.</p>'
                . '<h2>Who This Category Is For</h2>'
                . '<p>Adult visitors looking for Big Boob Cam content are the primary audience. '
                . 'Big Boob Cam is designed to be equally useful for new and regular visitors.</p>'
                . '<h2>How are Big Boob Cam performers selected?</h2>'
                . '<p>Performers in Big Boob Cam are listed because their profiles match the theme.</p>'
                . '<h2>Frequently Asked Questions</h2>'
                . '<h3>What is the difference between Big Boob Cam and related search terms?</h3>'
                . '<p>Big Boob Cam is a category archive, while search terms vary. '
                . 'Returning to Big Boob Cam is a simple way to see what is new.</p>'
                . '<h3>How do I browse?</h3><p>Use the directory links in Big Boob Cam and follow the tags.</p>'
                . '<p>The Big Boob Cam archive remains a neutral starting point.</p>'
                . "\n\n<!-- wp:html -->\n"
                . '<div class="tmw-category-page-affiliate-cta"><a href="https://op.example/track?c=bbc" target="_blank" rel="sponsored noopener">Visit live category related models</a></div>'
                . "\n<!-- /wp:html -->";
        }

        public function test_focus_keyword_density_is_reduced_with_natural_replacements(): void {
            $post = new \WP_Post(['ID' => 940, 'post_title' => 'Big Boob Cam', 'post_type' => 'tmw_category_page']);
            $html = $this->density_fixture();

            $before = preg_match_all('/(?<![\p{L}\p{N}])Big Boob Cam(?![\p{L}\p{N}])/iu', strip_tags($html));
            $this->assertGreaterThanOrEqual(10, $before);

            $reduced = (string) $this->invoke('reduce_category_focus_keyword_density', [$html, 'Big Boob Cam', $post]);
            $after = preg_match_all('/(?<![\p{L}\p{N}])Big Boob Cam(?![\p{L}\p{N}])/iu', strip_tags($reduced));

            // Well under the RankMath-flagged repetition; keyword still present.
            $this->assertLessThanOrEqual(6, $after);
            $this->assertGreaterThanOrEqual(3, $after);

            // First (intro) and last (closing) occurrences are preserved.
            $this->assertStringContainsString('Big Boob Cam is a directory archive', $reduced);
            $this->assertStringContainsString('The Big Boob Cam archive remains a neutral starting point.', $reduced);

            // Attributive use is never mangled.
            $this->assertStringContainsString('Big Boob Cam performers', $reduced);

            // Natural replacements appear.
            $this->assertMatchesRegularExpression('/this (category|archive|webcam theme|browsing theme|page)/i', $reduced);

            // Links and the CTA block are untouched.
            $this->assertStringContainsString('href="https://op.example/track?c=bbc"', $reduced);
            $this->assertStringContainsString('<!-- wp:html -->', $reduced);
            $this->assertStringContainsString('Visit live category related models', $reduced);
        }

        public function test_focus_keyword_density_reduction_is_idempotent_and_skips_low_density_pages(): void {
            $post = new \WP_Post(['ID' => 941, 'post_title' => 'Big Boob Cam', 'post_type' => 'tmw_category_page']);

            $reduced = (string) $this->invoke('reduce_category_focus_keyword_density', [$this->density_fixture(), 'Big Boob Cam', $post]);
            $twice   = (string) $this->invoke('reduce_category_focus_keyword_density', [$reduced, 'Big Boob Cam', $post]);
            $this->assertSame($reduced, $twice);

            // A page already at safe density is left byte-identical.
            $low = '<p>Big Boob Cam is a category page.</p><p>Browse the listings.</p>';
            $this->assertSame($low, (string) $this->invoke('reduce_category_focus_keyword_density', [$low, 'Big Boob Cam', $post]));
        }

        public function test_category_ai_save_path_reduces_focus_keyword_density(): void {
            $post = new \WP_Post(['ID' => 944, 'post_title' => 'Big Boob Cam', 'post_type' => 'tmw_category_page']);
            $html = $this->density_fixture();

            $prepared = (string) $this->invoke('prepare_category_ai_html_for_save', [$html, 'Big Boob Cam', ['primary' => 'Big Boob Cam'], $post]);
            $count = preg_match_all('/(?<![\p{L}\p{N}])Big Boob Cam(?![\p{L}\p{N}])/iu', strip_tags($prepared));

            $this->assertLessThanOrEqual(6, $count);
            $this->assertStringContainsString('Big Boob Cam is a directory archive', $prepared);
            $this->assertStringContainsString('The Big Boob Cam archive remains a neutral starting point.', $prepared);
            $this->assertStringContainsString('href="https://op.example/track?c=bbc"', $prepared);
            $this->assertStringContainsString('Visit live category related models', $prepared);
        }

        public function test_category_ai_save_path_skips_non_category_content(): void {
            $post = new \WP_Post(['ID' => 945, 'post_title' => 'Big Boob Cam', 'post_type' => 'model']);
            $html = $this->density_fixture();

            $this->assertSame($html, (string) $this->invoke('prepare_category_ai_html_for_save', [$html, 'Big Boob Cam', ['primary' => 'Big Boob Cam'], $post]));
        }

        public function test_generated_category_preview_stays_below_density_ceiling(): void {
            $post = new \WP_Post(['ID' => 942, 'post_title' => 'Big Boob Cam', 'post_type' => 'tmw_category_page']);
            $GLOBALS['_tmw_test_post_meta'][942] = [];

            $payload = $this->invoke('build_category_page_template_preview', [$post, 'Big Boob Cam', []]);
            $content = strip_tags((string) $payload['content_html']);

            $count = preg_match_all('/(?<![\p{L}\p{N}])Big Boob Cam(?![\p{L}\p{N}])/iu', $content);
            $this->assertLessThanOrEqual(8, $count);
            $this->assertGreaterThanOrEqual(1, $count);
        }


        public function test_free_cam_chat_root_family_limits_body_use_without_removing_rank_math_tracking(): void {
            $post = new \WP_Post(['ID' => 944, 'post_title' => 'Free Cam Chat', 'post_type' => 'tmw_category_page']);
            $GLOBALS['_tmw_test_post_meta'][944] = [
                'rank_math_focus_keyword' => 'Free Cam Chat,free cam chat sites,free webcam chat,free cam to cam chat,cam to cam chat,free live cam chat,free live cams,cam chat sites,webcam chat rooms',
            ];

            $pack = [
                'primary' => 'Free Cam Chat',
                'rankmath_additional' => ['free cam chat sites','free webcam chat','free cam to cam chat','cam to cam chat','free live cam chat','free live cams','cam chat sites','webcam chat rooms'],
                'content_terms' => ['free cam chat sites','free webcam chat','free cam to cam chat','cam to cam chat','free live cam chat','free live cams','cam chat sites','webcam chat rooms'],
                'content_terms_source' => 'category_db_approved',
            ];

            $set = $this->invoke('normalize_category_content_keyword_set', [$post, 'Free Cam Chat', $pack]);
            $this->assertCount(9, $set['rankmath_tracking_keywords']);
            $this->assertLessThan(8, count($set['body_use_keywords']));
            $this->assertNotEmpty($set['unused_keywords']);
            $this->assertContains('free cam chat sites', $set['body_use_keywords']);
        }

        public function test_free_cam_chat_coverage_avoids_keyword_dumps_and_keeps_family_density_safe(): void {
            $post = new \WP_Post(['ID' => 945, 'post_title' => 'Free Cam Chat', 'post_type' => 'tmw_category_page']);
            $GLOBALS['_tmw_test_post_meta'][945] = [];
            $base = '<p>Free Cam Chat helps visitors browse public cam rooms and compare what is visible before clicking through. Free Cam Chat pages should explain that free access can differ from private or paid features.</p>';
            for ($i = 0; $i < 18; $i++) {
                $base .= '<p>Use the listings to compare active rooms, performer details, video context, safety expectations, privacy prompts, and platform labels without assuming every feature is free.</p>';
            }
            $base .= '<h2>Frequently Asked Questions</h2><h3>How should visitors compare rooms?</h3><p>Look for clear labels and current activity.</p>';
            $keyword_set = [
                'primary_keyword' => 'Free Cam Chat',
                'extra_keywords' => ['free cam chat sites','free webcam chat','free cam to cam chat','cam to cam chat','free live cam chat','free live cams','cam chat sites','webcam chat rooms'],
                'all_keywords' => ['Free Cam Chat','free cam chat sites'],
                'supporting_keywords' => ['free cam chat sites'],
                'supporting_source' => 'category_db_approved',
            ];
            $covered = (string) $this->invoke('ensure_category_keyword_coverage', [$base, $keyword_set, $post]);
            $text = strtolower(strip_tags($covered));
            $words = max(1, str_word_count($text));
            $family_hits = preg_match_all('/\b(?:cam|chat|webcam|cams)\b/i', $text);
            $this->assertLessThan(2.2, ($family_hits / $words) * 100);
            $this->assertSame(0, preg_match('/free cam to cam chat,\s*free live cams,\s*free webcam chat/i', $text));
            foreach (preg_split('/(?<=[.!?])\s+/', $text) ?: [] as $sentence) {
                $hits = 0;
                foreach ($keyword_set['extra_keywords'] as $kw) {
                    if (strpos($sentence, strtolower($kw)) !== false) { $hits++; }
                }
                $this->assertLessThanOrEqual(2, $hits);
            }
            $this->assertStringContainsString('free cam chat', $text);
            foreach (['share the same browsing focus','using the same site model and video listings','related to this collection','directory structure here narrows the listings'] as $banned) {
                $this->assertStringNotContainsString($banned, $text);
            }
            $this->assertGreaterThanOrEqual(700, str_word_count($text));
        }

        public function test_admin_notice_html_shows_term_url_and_state(): void {
            if (!class_exists('TMW_Category_Affiliate_CTA')) {
                require_once dirname(__DIR__) . '/includes/categories/class-category-affiliate-cta.php';
            }

            $GLOBALS['_tmw_test_terms_by_slug']['big-boob-cam'] = new \WP_Term(['term_id' => 77, 'taxonomy' => 'category', 'slug' => 'big-boob-cam', 'name' => 'Big Boob Cam']);
            $GLOBALS['_tmw_test_affiliate_urls'][77] = 'https://op.example/track?c=bbc';

            $post = new \WP_Post([
                'ID' => 943,
                'post_title' => 'Big Boob Cam',
                'post_name' => 'big-boob-cam',
                'post_type' => 'tmw_category_page',
                'post_content' => '<p>x</p><!-- wp:html --><div class="tmw-category-page-affiliate-cta"><a href="https://op.example/track?c=bbc">Visit</a></div><!-- /wp:html -->',
            ]);
            $GLOBALS['_tmw_test_post_meta'][943] = [];

            $notice = (string) \TMW_Category_Affiliate_CTA::build_admin_notice_html($post);

            $this->assertStringContainsString('notice-info', $notice);
            $this->assertStringContainsString('Big Boob Cam', $notice);
            $this->assertStringContainsString('https://op.example/track?c=bbc', $notice);
            $this->assertStringContainsString('tag_ID=77', $notice);
            $this->assertStringContainsString('Live CTA block', $notice);
            $this->assertStringNotContainsString('<input', $notice);

            unset($GLOBALS['_tmw_test_terms_by_slug']['big-boob-cam'], $GLOBALS['_tmw_test_affiliate_urls'][77]);
        }
    }
}
