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
                    'url' => 'https://www.livejasmin.com/en/chat/abby-murray',
                    'is_primary' => true,
                    'is_active' => true,
                    'activity_level' => 'active',
                ],
                [
                    'type' => 'stripchat',
                    'label' => 'Stripchat',
                    'url' => 'https://stripchat.com/AbbyMurray',
                    'is_primary' => false,
                    'is_active' => true,
                    'activity_level' => 'active',
                ],
                [
                    'type' => 'twitter',
                    'label' => 'Twitter / X',
                    'url' => 'https://x.com/AbbyMurray',
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
        public static function get(string $platform): array { return ['name' => $platform === 'livejasmin' ? 'LiveJasmin' : ucfirst($platform), 'group' => 'cam']; }
    }
    class PlatformProfiles {
        public static function sync_to_table(int $post_id): void {}
        public static function get_links(int $post_id): array { return []; }
        public static function extract_username_from_profile_url(string $platform, string $url): string { return 'abby-murray'; }
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
    if (!function_exists('get_posts')) { function get_posts(array $args = []): array { return []; } }
    if (!function_exists('get_the_title')) { function get_the_title($post = 0): string { return 'Abby Murray'; } }
    if (!function_exists('get_permalink')) { function get_permalink($post = 0): string { return 'https://example.test/models/abby-murray/'; } }
    if (!function_exists('get_post_field')) { function get_post_field(string $field, int $post_id): string { return $field === 'post_name' ? 'abby-murray' : ''; } }
    if (!function_exists('get_the_terms')) { function get_the_terms($post, string $taxonomy) { return false; } }
    if (!function_exists('is_wp_error')) { function is_wp_error($thing): bool { return false; } }
    if (!function_exists('sanitize_title_with_dashes')) { function sanitize_title_with_dashes(string $title): string { return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-'); } }
    if (!function_exists('trailingslashit')) { function trailingslashit(string $value): string { return rtrim($value, '/\\') . '/'; } }
    if (!function_exists('get_post_meta')) {
        function get_post_meta(int $post_id, string $key = '', bool $single = false) {
            $meta = [
                '_tmwseo_seed_external_bio' => 'Abby Murray is described by operator notes as friendly, fashion-focused, and consistent with dancing and close-up profile themes.',
                '_tmwseo_seed_external_turn_ons' => 'Dancing, close up, roleplay, oil, twerk, striptease, and fashion looks.',
                '_tmwseo_seed_external_private_chat' => 'Striptease, Dancing, Close up, Roleplay, Oil, Twerk, snapshot, private chat sessions.',
                '_tmwseo_editor_seed_tags' => 'Striptease, Dancing, Close up, Roleplay, Oil, Twerk',
                '_tmwseo_editor_seed_summary' => 'Operator seed notes confirm Abby Murray profile context and safe private-chat themes.',
                '_tmwseo_platform_username_livejasmin' => 'abby-murray',
                '_tmwseo_platform_username_stripchat' => 'AbbyMurray',
            ];

            return $meta[$key] ?? '';
        }
    }
    if (!class_exists('WP_Post')) {
        class WP_Post {
            public int $ID = 636;
            public string $post_title = 'Abby Murray';
            public string $post_name = 'abby-murray';
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
        'primary' => 'Abby Murray',
        'additional' => [
            'Abby Murray LiveJasmin',
            'Abby Murray private chat',
            'Abby Murray live cam',
            'Abby Murray webcam chat',
        ],
        'rankmath_additional' => [
            'Abby Murray LiveJasmin',
            'Abby Murray private chat',
            'Abby Murray live cam',
            'Abby Murray webcam chat',
        ],
        'longtail' => [
            'how to watch Abby Murray live webcam shows',
            'Abby Murray official profile access',
        ],
        'sources' => [
            'tags' => ['Striptease', 'Dancing', 'Close up', 'Roleplay', 'Oil', 'Twerk'],
        ],
    ]);

    $body = (string) ($result['content'] ?? '');
    $plain = trim(wp_strip_all_tags($body));
    $words = preg_split('/\s+/', $plain);
    $word_count = is_array($words) ? count(array_filter($words)) : 0;

    $focus_hits = preg_match_all('/(?<![\w-])Abby Murray(?![\w-])/i', $plain, $matches);
    $density = $word_count > 0 ? (($focus_hits ?: 0) / $word_count) * 100 : 0.0;

    tmw_template_assert($word_count >= 600, 'TemplateContent::build_model() post_content should be at least 600 words; got ' . $word_count . '.');
    tmw_template_assert($density <= 2.0, 'Focus keyword density should be at or below 2%; got ' . number_format($density, 2) . '%.');
    tmw_template_assert(str_contains($body, '<a href="https://example.test/models/">Browse all models</a>'), 'Internal model link should be preserved.');
    tmw_template_assert(str_contains($body, '<h2>Official Profile Access</h2>'), 'Official Profile Access section should exist.');
    tmw_template_assert(str_contains($body, '<h2>Where to Watch Live</h2>'), 'Where to Watch Live section should exist.');
    tmw_template_assert(str_contains($body, 'tmwseo-seed-evidence:start'), 'Evidence block should remain visible.');
    tmw_template_assert(stripos($plain, 'private-chat') !== false, 'Evidence/private-chat block should remain visible.');

    $safe_items = ['Striptease', 'Dancing', 'Close up', 'Roleplay', 'Oil', 'Twerk'];
    $visible = 0;
    foreach ($safe_items as $item) {
        if (preg_match('/(?<![A-Za-z])' . preg_quote($item, '/') . '(?![A-Za-z])/i', $plain)) {
            $visible++;
        }
    }
    tmw_template_assert($visible >= 4, 'At least 4 Abby-style safe items should remain visible; got ' . $visible . '.');

    tmw_template_assert(strpos($body, 'Private chat options should be read as session-dependent') === false, 'Template body should not rely on the old generic-only private-chat fallback.');

    foreach ([
        'The verified notes point to',
        'personable cam delivery',
        'do you accept',
        'Use these notes as profile context',
    ] as $forbidden) {
        tmw_template_assert(stripos($body, $forbidden) === false, 'Forbidden phrase should be absent: ' . $forbidden);
    }

    echo "TemplateContent::build_model body length/density smoke passed. Words={$word_count}; Density=" . number_format($density, 2) . "%; SafeItems={$visible}\n";
}
