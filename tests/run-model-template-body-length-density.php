<?php
/**
 * Smoke checks for TemplateContent::build_model(), the active right-sidebar Template Generate path.
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Model {
    class VerifiedLinks {
        public static function get_links(int $post_id): array {
            return [
                [
                    'type' => 'livejasmin',
                    'label' => 'LiveJasmin',
                    'url' => 'https://www.livejasmin.com/en/chat/anisyia',
                    'is_primary' => true,
                    'is_active' => true,
                    'activity_level' => 'active',
                ],
                [
                    'type' => 'fansly',
                    'label' => 'Fansly',
                    'url' => 'https://fansly.com/Anisyia',
                    'is_primary' => false,
                    'is_active' => true,
                    'activity_level' => 'active',
                ],
                [
                    'type' => 'twitter',
                    'label' => 'Twitter / X',
                    'url' => 'https://x.com/Anisyia',
                    'is_primary' => false,
                    'is_active' => true,
                    'activity_level' => 'active',
                ],
            ];
        }

        public static function get_routed_url(array $link): string {
            return (string) ($link['url'] ?? '');
        }
    }
}

namespace TMWSEO\Engine\Platform {
    class AffiliateLinkBuilder {
        public static function build_seo_content_affiliate_url(string $platform, string $username): string { return ''; }
        public static function go_url(string $platform, string $username): string { return 'https://example.test/go/' . $platform . '/' . rawurlencode($username); }
        public static function canonical_platform_slug(string $platform): string { return strtolower($platform); }
    }
    class PlatformRegistry {
        public static function get(string $platform): array { return ['name' => $platform === 'livejasmin' ? 'LiveJasmin' : ucfirst($platform), 'group' => $platform === 'livejasmin' ? 'cam' : 'social']; }
    }
    class PlatformProfiles {
        public static function sync_to_table(int $post_id): void {}
        public static function get_links(int $post_id): array { return []; }
        public static function extract_username_from_profile_url(string $platform, string $url): string { return 'anisyia'; }
    }
}

namespace TMWSEO\Engine\Services {
    class Settings { public static function get(string $key, $default = null) { return $default; } }
    class TitleFixer { public static function shorten(string $title, int $max = 65): string { return strlen($title) > $max ? substr($title, 0, $max) : $title; } }
}

namespace TMWSEO\Engine\Keywords {
    class ModelKeywordPack {}
}

namespace TMWSEO\Engine {
    class Logs { public static function info(string $channel, string $message, array $context = []): void {} public static function warn(string $channel, string $message, array $context = []): void {} }
}

namespace {
    if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
    if (!defined('TMWSEO_ENGINE_PATH')) { define('TMWSEO_ENGINE_PATH', dirname(__DIR__) . '/'); }
    if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }

    function tmw_template_assert(bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    if (!function_exists('esc_html')) { function esc_html($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); } }
    if (!function_exists('esc_attr')) { function esc_attr($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); } }
    if (!function_exists('esc_url')) { function esc_url($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); } }
    if (!function_exists('wp_kses_post')) { function wp_kses_post($html): string { return (string) $html; } }
    if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($text): string { return trim(strip_tags((string) $text)); } }
    if (!function_exists('sanitize_text_field')) { function sanitize_text_field($text): string { return trim(strip_tags((string) $text)); } }
    if (!function_exists('sanitize_key')) { function sanitize_key($key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)) ?? ''; } }
    if (!function_exists('absint')) { function absint($value): int { return abs((int) $value); } }
    if (!function_exists('wp_parse_url')) { function wp_parse_url(string $url, int $component = -1) { return $component === -1 ? parse_url($url) : parse_url($url, $component); } }
    if (!function_exists('home_url')) { function home_url(string $path = ''): string { return 'https://example.test/' . ltrim($path, '/'); } }
    if (!function_exists('get_bloginfo')) { function get_bloginfo(string $show = ''): string { return 'Smoke Site'; } }
    if (!function_exists('get_transient')) { function get_transient(string $key) { return false; } }
    if (!function_exists('set_transient')) { function set_transient(string $key, $value, int $expiration = 0): bool { return true; } }
    if (!function_exists('get_posts')) { function get_posts(array $args = []): array { $p = new stdClass(); $p->ID = 971; $p->post_title = 'Anisyia live video'; $p->post_date = '2026-01-01 00:00:00'; return [$p]; } }
    if (!function_exists('get_the_title')) { function get_the_title($post = 0): string { return (int) $post === 971 ? 'Anisyia live video' : 'Anisyia'; } }
    if (!function_exists('get_permalink')) { function get_permalink($post = 0): string { return (int) $post === 971 ? 'https://example.test/videos/anisyia-live-video/' : 'https://example.test/models/anisyia/'; } }
    if (!function_exists('get_post_field')) { function get_post_field(string $field, int $post_id): string { return $field === 'post_name' ? 'anisyia' : ''; } }
    if (!function_exists('get_the_terms')) { function get_the_terms($post, string $taxonomy) { return false; } }
    if (!function_exists('is_wp_error')) { function is_wp_error($thing): bool { return false; } }
    if (!function_exists('sanitize_title_with_dashes')) { function sanitize_title_with_dashes(string $title): string { return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-'); } }
    if (!function_exists('trailingslashit')) { function trailingslashit(string $value): string { return rtrim($value, '/\\') . '/'; } }
    if (!function_exists('get_post_meta')) {
        function get_post_meta(int $post_id, string $key = '', bool $single = false) {
            $meta = [
                '_tmwseo_seed_external_bio' => 'Anisyia is described by operator notes as friendly, fashion-focused, and consistent with dancing and close-up profile themes.',
                '_tmwseo_seed_external_turn_ons' => 'Dancing, close up, roleplay, oil, twerk, striptease, and fashion looks.',
                '_tmwseo_seed_external_private_chat' => 'Love Beads, Beads, Striptease, Dancing, Strap-on, Foot Fetish, Close up, Roleplay, Oil, Snapshot.',
                '_tmwseo_editor_seed_tags' => 'Striptease, Dancing, Close up, Roleplay, Oil, Twerk',
                '_tmwseo_editor_seed_summary' => 'Operator seed notes confirm Anisyia profile context and safe private-chat themes.',
                '_tmwseo_platform_username_livejasmin' => 'anisyia',
            ];

            return $meta[$key] ?? '';
        }
    }
    if (!class_exists('WP_Post')) {
        class WP_Post {
            public int $ID = 636;
            public string $post_title = 'Anisyia';
            public string $post_name = 'anisyia';
            public string $post_type = 'model';
            public string $post_status = 'draft';
        }
    }

    require_once dirname(__DIR__) . '/includes/model/class-verified-links-families.php';
    require_once dirname(__DIR__) . '/includes/templates/class-template-engine.php';
    require_once dirname(__DIR__) . '/includes/content/class-model-page-renderer.php';
    require_once dirname(__DIR__) . '/includes/content/class-model-destination-resolver.php';
    require_once dirname(__DIR__) . '/includes/content/class-model-research-evidence.php';
    require_once dirname(__DIR__) . '/includes/content/class-model-copy-cleanup.php';
    require_once dirname(__DIR__) . '/includes/content/class-template-content.php';

    $post = new WP_Post();
    $result = \TMWSEO\Engine\Content\TemplateContent::build_model($post, [
        'primary' => 'Anisyia',
        'additional' => [
            'anisyia livejasmin',
            'anisyia live',
            'livejasmin anisyia',
            'anisyia camsoda',
        ],
        'rankmath_additional' => [
            'anisyia livejasmin',
            'anisyia live',
            'livejasmin anisyia',
            'anisyia camsoda',
        ],
        'longtail' => [
            'how to watch Anisyia live webcam shows',
            'Anisyia official profile access',
        ],
        'sources' => [
            'tags' => ['Striptease', 'Dancing', 'Close up', 'Roleplay', 'Oil', 'Twerk'],
        ],
    ]);

    $body = (string) ($result['content'] ?? '');
    $plain = trim(wp_strip_all_tags($body));
    $words = preg_split('/\s+/', $plain);
    $word_count = is_array($words) ? count(array_filter($words)) : 0;

    $focus_hits = preg_match_all('/(?<![\w-])Anisyia(?![\w-])/i', $plain, $matches);
    $density = $word_count > 0 ? (($focus_hits ?: 0) / $word_count) * 100 : 0.0;

    tmw_template_assert($word_count >= 600, 'TemplateContent::build_model() post_content should be at least 600 words; got ' . $word_count . '.');
    tmw_template_assert($density <= 2.0, 'Focus keyword density should be at or below 2%; got ' . number_format($density, 2) . '%.');
    tmw_template_assert(str_contains($body, '<a href="https://example.test/models/">Browse all models</a>'), 'Internal model link should be preserved.');
    tmw_template_assert(str_contains($body, 'https://example.test/videos/anisyia-live-video/'), 'Internal video link should be preserved.');
    tmw_template_assert(str_contains($body, 'https://example.test/categories/'), 'Internal category link should be preserved.');
    tmw_template_assert(str_contains($body, '<h2>Official Profile Access</h2>'), 'Official Profile Access section should exist.');
    tmw_template_assert(str_contains($body, '<h2>Where to Watch Live</h2>'), 'Where to Watch Live section should exist.');
    tmw_template_assert(str_contains($body, 'tmwseo-seed-evidence:start'), 'Evidence block should remain visible.');
    tmw_template_assert(stripos($plain, 'private-chat') !== false, 'Evidence/private-chat block should remain visible.');

    foreach (['the the', 'below below', 'links below below'] as $duplicate) {
        tmw_template_assert(stripos($body, $duplicate) === false, 'Duplicate copy should be absent: ' . $duplicate);
    }

    $cleanup_ref = new ReflectionMethod(\TMWSEO\Engine\Content\TemplateContent::class, 'final_template_copy_cleanup');
    $cleanup_ref->setAccessible(true);
    $dirty = '<p>Use the the links below below now!! Keep <a href="https://example.test/path/the/the?below=below">the the anchor</a>.</p>';
    $clean = (string) $cleanup_ref->invoke(null, $dirty);
    tmw_template_assert(str_contains($clean, 'Use the links below now!'), 'Final cleanup should remove duplicate body copy and punctuation.');
    tmw_template_assert(str_contains($clean, 'href="https://example.test/path/the/the?below=below"'), 'Final cleanup should preserve URLs inside HTML tags.');
    tmw_template_assert(str_contains($clean, '>the the anchor</a>'), 'Final cleanup should preserve anchor text.');

    $private_heading_pos = stripos($body, '<h2>Private Chat Options</h2>');
    $private_section = $private_heading_pos === false ? '' : substr($body, $private_heading_pos, 600);
    tmw_template_assert($private_heading_pos !== false, 'Private Chat Options heading should exist.');
    tmw_template_assert(stripos($private_section, 'Snapshot') === false, 'Snapshot should not appear in Private Chat Options.');

    $full_bundle = 'Fans searching for anisyia livejasmin, anisyia live, livejasmin anisyia, or anisyia camsoda';
    tmw_template_assert(substr_count(strtolower($plain), strtolower($full_bundle)) <= 1, 'Full keyword-bundle sentence should not repeat.');

    tmw_template_assert(str_contains($body, '<h2>Official Links and Profiles</h2>'), 'Verified link sections should still render.');
    foreach (['<table', '<th>Platform</th>', '<th>Profile</th>', '<th>Link</th>'] as $table_fragment) {
        tmw_template_assert(stripos($body, $table_fragment) === false, 'Generated body should not contain comparison table markup: ' . $table_fragment);
    }
    tmw_template_assert(str_contains($plain, 'Use the confirmed live-room button first.'), 'Single-platform comparison guidance paragraph should render.');

    $comparison_ref = new ReflectionMethod(\TMWSEO\Engine\Content\TemplateContent::class, 'build_platform_comparison');
    $comparison_ref->setAccessible(true);
    $single_comparison = (string) $comparison_ref->invoke(null, $post, 'Anisyia', [[
        'platform' => 'camsoda',
        'label' => 'CamSoda',
        'username' => 'anisyia',
        'url' => 'https://www.camsoda.com/anisyia',
    ]], '', []);
    $multi_comparison = (string) $comparison_ref->invoke(null, $post, 'Aisha Dupont', [[
        'platform' => 'stripchat',
        'label' => 'Stripchat',
        'username' => 'OhhAisha',
        'url' => 'https://stripchat.com/OhhAisha',
    ], [
        'platform' => 'chaturbate',
        'label' => 'Chaturbate',
        'username' => 'ohhaisha',
        'url' => 'https://chaturbate.com/ohhaisha',
    ]], '', []);
    $comparison_html = $single_comparison . $multi_comparison;
    foreach (['<table', '<th>Platform</th>', '<th>Profile</th>', '<th>Link</th>', '<td>CamSoda</td>', '<td>@anisyia</td>', '<td>Stripchat</td>', '<td>@OhhAisha</td>', '<td>Chaturbate</td>', '<td>@ohhaisha</td>', 'Watch Live'] as $table_fragment) {
        tmw_template_assert(stripos($comparison_html, $table_fragment) === false, 'Platform comparison helper should not emit table fragments or row content: ' . $table_fragment);
    }
    tmw_template_assert(str_contains(wp_strip_all_tags($single_comparison), 'Use the confirmed live-room button first.'), 'Single-platform helper should emit paragraph guidance.');
    tmw_template_assert(str_contains(wp_strip_all_tags($multi_comparison), 'When more than one live platform is available'), 'Multi-platform helper should emit paragraph guidance.');

    $safe_items = ['Striptease', 'Dancing', 'Close up', 'Roleplay', 'Oil', 'Twerk'];
    $visible = 0;
    foreach ($safe_items as $item) {
        if (preg_match('/(?<![A-Za-z])' . preg_quote($item, '/') . '(?![A-Za-z])/i', $plain)) {
            $visible++;
        }
    }
    tmw_template_assert($visible >= 4, 'At least 4 Anisyia-style safe items should remain visible; got ' . $visible . '.');

    tmw_template_assert(strpos($body, 'Private chat options should be read as session-dependent') === false, 'Template body should not rely on the old generic-only private-chat fallback.');

    foreach ([
        'The verified notes point to',
        'personable cam delivery',
        'do you accept',
        'Use these notes as profile context',
        'Private chat notes list',
        'available request areas',
    ] as $forbidden) {
        tmw_template_assert(stripos($body, $forbidden) === false, 'Forbidden phrase should be absent: ' . $forbidden);
    }

    echo "TemplateContent::build_model body length/density smoke passed. Words={$word_count}; Density=" . number_format($density, 2) . "%; SafeItems={$visible}\n";
}
