<?php
/**
 * CategoryTemplatePool unit tests.
 *
 * Runs standalone — no WordPress required.
 * Uses the same bootstrap pattern as the existing suite.
 *
 * @package TMWSEO\Engine\Tests
 */
declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__);
    }
    if (!defined('TMWSEO_ENGINE_DATA_DIR')) {
        define('TMWSEO_ENGINE_DATA_DIR', dirname(__DIR__) . '/data');
    }

    require_once __DIR__ . '/bootstrap/wp-post-stub.php';

    if (!function_exists('esc_html')) {
        function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
    }
    if (!function_exists('esc_url')) {
        function esc_url($s) { return (string) $s; }
    }
    if (!function_exists('home_url')) {
        function home_url($path = '') { return 'https://top-models.webcam' . $path; }
    }
    if (!function_exists('get_bloginfo')) {
        function get_bloginfo($show = '') { return 'Top Models'; }
    }
    if (!function_exists('wp_parse_url')) {
        function wp_parse_url($url, $component = -1) { return parse_url((string) $url, $component); }
    }
    if (!function_exists('get_post_meta')) {
        function get_post_meta($post_id, $key = '', $single = false) {
            return $GLOBALS['_tmw_test_post_meta'][$post_id][$key] ?? '';
        }
    }

    require_once dirname(__DIR__) . '/includes/content/class-category-template-pool.php';
}

namespace TMWSEO\Engine\Tests {
    use PHPUnit\Framework\TestCase;
    use TMWSEO\Engine\Content\CategoryTemplatePool;

    /**
     * Minimal category_data fixture used across tests.
     */
    final class CategoryTemplatePoolTest extends TestCase {

        private function make_data(array $overrides = []): array {
            return array_merge([
                'category_name'      => 'Blonde Webcam Models',
                'category_slug'      => 'blonde-webcam-models',
                'focus_keyword'      => 'blonde webcam models',
                'secondary_keywords' => 'blonde cam girls, blonde live cam',
                'site_name'          => 'Top-Models.Webcam',
                'models_url'         => 'https://top-models.webcam/models/',
                'videos_url'         => 'https://top-models.webcam/videos/',
                'category_context'   => '',
                'platform_context'   => '',
                'safe_live_context'  => '',
                'internal_links'     => '',
                'related_categories' => '',
                'related_models'     => '',
            ], $overrides);
        }

        // ── Load tests ──────────────────────────────────────────────────────

        public function test_loads_section_json_and_returns_section_keys(): void {
            $pool = new CategoryTemplatePool();
            $keys = $pool->get_section_keys();

            $this->assertNotEmpty($keys, 'section keys must not be empty');
            $this->assertContains('intro', $keys);
            $this->assertContains('what_this_category_covers', $keys);
            $this->assertContains('who_this_category_is_for', $keys);
            $this->assertContains('how_to_browse', $keys);
        }

        public function test_loads_faq_json_and_returns_bucket_keys(): void {
            $pool = new CategoryTemplatePool();
            $keys = $pool->get_faq_bucket_keys();

            $this->assertNotEmpty($keys, 'FAQ bucket keys must not be empty');
            $this->assertContains('category_meaning', $keys);
            $this->assertContains('browsing_help', $keys);
            $this->assertContains('safety_and_expectations', $keys);
        }

        public function test_missing_json_directory_does_not_fatal(): void {
            $pool = new CategoryTemplatePool('/nonexistent/path/that/does/not/exist');

            $this->assertSame([], $pool->get_section_keys());
            $this->assertSame([], $pool->get_faq_bucket_keys());
            $this->assertSame([], $pool->get_all_sections(1, []));
            $this->assertSame([], $pool->get_faqs(1, []));
        }

        public function test_missing_specific_json_file_returns_empty_without_fatal(): void {
            // Point at a valid dir but with no JSON files.
            $tmp_dir = sys_get_temp_dir() . '/tmw_cat_pool_test_' . getmypid();
            @mkdir($tmp_dir, 0777, true);

            $pool = new CategoryTemplatePool($tmp_dir);
            $this->assertSame([], $pool->get_section_keys());
            $this->assertSame([], $pool->get_faq_bucket_keys());

            @rmdir($tmp_dir);
        }

        // ── Placeholder resolution ───────────────────────────────────────────

        public function test_resolve_replaces_known_placeholder(): void {
            $pool = new CategoryTemplatePool();
            $data = $this->make_data();

            $result = $pool->resolve('Browse {{category_name}} now.', $data);
            $this->assertSame('Browse Blonde Webcam Models now.', $result);
        }

        public function test_resolve_leaves_unknown_placeholder_intact(): void {
            $pool   = new CategoryTemplatePool();
            $result = $pool->resolve('Hello {{unknown_token}} world.', []);
            $this->assertStringContainsString('{{unknown_token}}', $result);
        }

        public function test_resolve_handles_empty_string(): void {
            $pool = new CategoryTemplatePool();
            $this->assertSame('', $pool->resolve('', []));
        }

        public function test_resolve_handles_array_value_for_placeholder(): void {
            $pool   = new CategoryTemplatePool();
            $result = $pool->resolve('{{bad}}', ['bad' => ['not', 'scalar']]);
            // Array value should leave placeholder intact.
            $this->assertStringContainsString('{{bad}}', $result);
        }

        // ── Unresolved placeholder detection ────────────────────────────────

        public function test_has_unresolved_placeholders_true_when_present(): void {
            $pool = new CategoryTemplatePool();
            $this->assertTrue($pool->has_unresolved_placeholders('Some text {{leftover}} here.'));
        }

        public function test_has_unresolved_placeholders_false_when_clean(): void {
            $pool = new CategoryTemplatePool();
            $this->assertFalse($pool->has_unresolved_placeholders('All clean text here.'));
        }

        // ── Deterministic variant selection ─────────────────────────────────

        public function test_deterministic_section_selection_is_stable_across_calls(): void {
            $pool = new CategoryTemplatePool();
            $data = $this->make_data();

            $first  = $pool->get_section('intro', 42, $data);
            $second = $pool->get_section('intro', 42, $data);

            $this->assertSame($first['id'], $second['id'], 'Same post_id must return same variant');
        }

        public function test_different_post_ids_may_return_different_variants(): void {
            $pool = new CategoryTemplatePool();
            $data = $this->make_data();

            $ids  = [];
            $seen = [];

            for ($i = 1; $i <= 20; $i++) {
                $s = $pool->get_section('intro', $i, $data);
                if ($s !== null) {
                    $seen[$s['id']] = true;
                }
            }

            // With 9 variants and 20 different IDs we expect at least 2 distinct variants.
            $this->assertGreaterThanOrEqual(2, count($seen), 'Different post IDs should produce variation');
        }

        // ── Section content checks ──────────────────────────────────────────

        public function test_no_h1_in_any_section_variant(): void {
            $pool = new CategoryTemplatePool();
            $data = $this->make_data();

            foreach ($pool->get_section_keys() as $key) {
                // Cycle through multiple post IDs to hit different variants.
                for ($post_id = 1; $post_id <= 15; $post_id++) {
                    $sec = $pool->get_section($key, $post_id, $data);
                    if ($sec === null) {
                        continue;
                    }
                    $body = (string) ($sec['body'] ?? $sec['content'] ?? '');
                    $this->assertStringNotContainsString('<h1', strtolower($body),
                        "H1 found in section '{$key}' variant id='{$sec['id']}'");
                }
            }
        }

        public function test_all_sections_have_non_empty_body_with_full_category_data(): void {
            $pool = new CategoryTemplatePool();
            $data = $this->make_data();

            $sections = $pool->get_all_sections(77, $data);

            $this->assertNotEmpty($sections);
            foreach ($sections as $key => $sec) {
                $body = (string) ($sec['body'] ?? $sec['content'] ?? '');
                $this->assertNotSame('', $body, "Section '{$key}' has empty body");
            }
        }

        public function test_section_body_has_no_unresolved_placeholders_with_full_data(): void {
            $pool = new CategoryTemplatePool();
            $data = $this->make_data();

            $sections = $pool->get_all_sections(77, $data);

            foreach ($sections as $key => $sec) {
                $body = (string) ($sec['body'] ?? $sec['content'] ?? '');
                $this->assertFalse(
                    $pool->has_unresolved_placeholders($body),
                    "Section '{$key}' has unresolved placeholders: {$body}"
                );
            }
        }

        // ── FAQ checks ──────────────────────────────────────────────────────

        public function test_get_faqs_respects_per_page_limit(): void {
            $pool = new CategoryTemplatePool();
            $data = $this->make_data();

            $faqs = $pool->get_faqs(100, $data, 3);
            $this->assertLessThanOrEqual(3, count($faqs));
        }

        public function test_get_faqs_returns_empty_array_for_zero_per_page(): void {
            $pool = new CategoryTemplatePool();
            $data = $this->make_data();

            $faqs = $pool->get_faqs(100, $data, 0);
            $this->assertSame([], $faqs);
        }

        public function test_get_faqs_no_duplicate_questions(): void {
            $pool = new CategoryTemplatePool();
            $data = $this->make_data();

            $faqs = $pool->get_faqs(42, $data, 8);
            $qs   = array_map(static fn($f) => strtolower((string) $f['q']), $faqs);

            $this->assertSame(count($qs), count(array_unique($qs)), 'Duplicate FAQ questions returned');
        }

        public function test_faq_items_have_no_unresolved_placeholders_with_full_data(): void {
            $pool = new CategoryTemplatePool();
            $data = $this->make_data();

            $faqs = $pool->get_faqs(42, $data, 8);

            foreach ($faqs as $faq) {
                $q = (string) ($faq['q'] ?? '');
                $a = (string) ($faq['a'] ?? '');
                $this->assertFalse($pool->has_unresolved_placeholders($q), "FAQ q has unresolved: {$q}");
                $this->assertFalse($pool->has_unresolved_placeholders($a), "FAQ a has unresolved: {$a}");
            }
        }

        public function test_faq_deterministic_selection_is_stable(): void {
            $pool = new CategoryTemplatePool();
            $data = $this->make_data();

            $first  = $pool->get_faqs(55, $data, 4);
            $second = $pool->get_faqs(55, $data, 4);

            $this->assertSame(
                array_column($first, 'id'),
                array_column($second, 'id'),
                'FAQ selection must be stable for same post_id'
            );
        }

        // ── all_sections integration ─────────────────────────────────────────

        public function test_get_all_sections_returns_all_required_keys(): void {
            $pool     = new CategoryTemplatePool();
            $data     = $this->make_data();
            $sections = $pool->get_all_sections(10, $data);
            $keys     = array_keys($sections);

            $required = [
                'intro',
                'what_this_category_covers',
                'who_this_category_is_for',
                'how_to_browse',
                'live_cam_and_model_discovery_tips',
                'similar_categories',
                'what_to_check_before_opening_profile',
                'internal_navigation',
                'closing_context',
            ];

            foreach ($required as $rk) {
                $this->assertContains($rk, $keys, "Missing required section key '{$rk}'");
            }
        }

        public function test_word_count_of_all_sections_plus_faqs_meets_minimum_target(): void {
            $pool     = new CategoryTemplatePool();
            $data     = $this->make_data();
            $sections = $pool->get_all_sections(10, $data);
            $faqs     = $pool->get_faqs(10, $data, 4);

            $text = '';
            foreach ($sections as $sec) {
                $text .= ' ' . ($sec['body'] ?? $sec['content'] ?? '');
            }
            foreach ($faqs as $faq) {
                $text .= ' ' . $faq['q'] . ' ' . $faq['a'];
            }

            $word_count = str_word_count(strip_tags($text));
            $this->assertGreaterThanOrEqual(700, $word_count,
                "Combined pool output should reach minimum 700 words; got {$word_count}");
        }
    }
}
