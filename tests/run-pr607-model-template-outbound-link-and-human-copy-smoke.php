<?php
/**
 * Smoke checks for PR 607 model Template outbound links and humanized copy.
 */

declare(strict_types=1);

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
        public static function extract_username_from_profile_url(string $platform, string $url): string { return 'julieta'; }
    }
}

namespace TMWSEO\Engine\Services {
    class Settings { public static function get(string $key, $default = null) { return $default; } }
    class TitleFixer {}
}

namespace TMWSEO\Engine\Keywords {
    class ModelKeywordPack {}
}

namespace TMWSEO\Engine {
    class Logs { public static function info(string $channel, string $message, array $context = []): void {} }
}

namespace {
    if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

    $GLOBALS['pr607_db_writes'] = [];
    $GLOBALS['pr607_generate_executed'] = false;

    function pr607_assert(bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    if (!function_exists('esc_html')) { function esc_html($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); } }
    if (!function_exists('esc_attr')) { function esc_attr($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); } }
    if (!function_exists('esc_url')) { function esc_url($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); } }
    if (!function_exists('wp_kses_post')) { function wp_kses_post($html): string { return (string) $html; } }
    if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($text): string { return trim(strip_tags((string) $text)); } }
    if (!function_exists('sanitize_key')) { function sanitize_key($key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)) ?? ''; } }
    if (!function_exists('wp_parse_url')) { function wp_parse_url(string $url, int $component = -1) { return $component === -1 ? parse_url($url) : parse_url($url, $component); } }
    if (!function_exists('home_url')) { function home_url(string $path = ''): string { return 'https://example.test/' . ltrim($path, '/'); } }
    if (!function_exists('get_bloginfo')) { function get_bloginfo(string $show = ''): string { return 'Smoke Site'; } }
    if (!function_exists('get_post_meta')) { function get_post_meta(int $post_id, string $key = '', bool $single = false) { return ''; } }
    if (!function_exists('get_posts')) { function get_posts(array $args = []): array { return []; } }
    if (!function_exists('update_post_meta')) { function update_post_meta(...$args) { $GLOBALS['pr607_db_writes'][] = ['update_post_meta', $args]; return true; } }
    if (!function_exists('wp_update_post')) { function wp_update_post(...$args) { $GLOBALS['pr607_db_writes'][] = ['wp_update_post', $args]; return 1; } }
    if (!class_exists('WP_Post')) { class WP_Post { public int $ID = 607; public string $post_title = 'Julieta Montesco'; } }

    require_once dirname(__DIR__) . '/includes/model/class-verified-links-families.php';
    require_once dirname(__DIR__) . '/includes/templates/class-template-engine.php';
    require_once dirname(__DIR__) . '/includes/content/class-model-page-renderer.php';
    require_once dirname(__DIR__) . '/includes/content/class-model-research-evidence.php';
    require_once dirname(__DIR__) . '/includes/content/class-template-content.php';

    use TMWSEO\Engine\Content\ModelPageRenderer;
    use TMWSEO\Engine\Content\ModelResearchEvidence;
    use TMWSEO\Engine\Content\TemplateContent;

    $confirmed_url = 'https://www.livejasmin.com/en/chat/julieta-montesco';
    $cta_links = [[
        'platform' => 'livejasmin',
        'label' => 'LiveJasmin',
        'go_url' => 'https://example.test/go/livejasmin/julieta',
        'is_primary' => true,
        'username' => 'julieta-montesco',
        'source' => 'verified_links',
        'verified_url' => $confirmed_url,
    ]];

    $support_method = new ReflectionMethod(TemplateContent::class, 'build_model_renderer_support_payload');
    $post = new WP_Post();
    $payload = $support_method->invoke(null, $post, [
        'name' => 'Julieta Montesco',
        'cta_links' => $cta_links,
        'active_platforms' => ['LiveJasmin'],
        'resolved_destinations' => [
            'watch_cta_destinations' => $cta_links,
            'active_platform_labels' => ['LiveJasmin'],
            'source_of_truth_summary' => ['verified_count' => 1],
            'all_verified_destinations' => [[
                'family' => 'cam',
                'type' => 'livejasmin',
                'is_cta_eligible' => true,
                'url' => $confirmed_url,
            ]],
            'personal_site_destinations' => [],
            'fan_platform_destinations' => [],
            'social_destinations' => [],
            'link_hub_destinations' => [],
            'tube_destinations' => [],
        ],
        'model_data_gate' => ['is_sufficient' => true],
        'tags' => ['dancing'],
    ]);
    $html = ModelPageRenderer::render('Julieta Montesco', array_merge($payload, [
        'focus_keyword' => 'Julieta Montesco',
        'intro_paragraphs' => ['LiveJasmin is the confirmed live-room profile in this check. Start there for live access.'],
        'watch_section_paragraphs' => ['Open the confirmed live profile below. This page currently lists the confirmed LiveJasmin profile only.'],
        'features_section_paragraphs' => ['Check playback quality and chat readability before joining.'],
        'link_evidence_summary' => ['live_count' => 1, 'extra_count' => 0, 'total_count' => 1, 'has_live_profile' => true, 'has_extra_links' => false, 'has_any_links' => true],
    ]));

    pr607_assert(str_contains($html, '<a '), 'Confirmed-url output should include an anchor.');
    pr607_assert(str_contains($html, 'href="' . $confirmed_url . '"'), 'Confirmed-url output should use the exact verified external URL.');
    pr607_assert((bool) preg_match('/rel="[^"]*nofollow[^"]*noopener[^"]*"|rel="[^"]*noopener[^"]*nofollow[^"]*"/i', $html), 'Confirmed outbound anchor rel should contain nofollow and noopener.');
    pr607_assert(str_contains($html, 'sponsored'), 'Confirmed outbound anchor rel should contain sponsored.');
    pr607_assert(str_contains($html, 'Watch Julieta Montesco on LiveJasmin'), 'Confirmed outbound anchor text should mention the model and platform.');
    foreach (['CamSoda', 'social profiles', 'fan/support pages', 'link hubs', 'video channels', 'personal sites', 'other listed profiles', '0 profile links found, including 1 live profile'] as $needle) {
        pr607_assert(!str_contains($html, $needle), 'One-live/zero-extra output must not mention missing destination evidence: ' . $needle);
    }

    $no_url_payload = $support_method->invoke(null, $post, [
        'name' => 'Julieta Montesco',
        'cta_links' => [[
            'platform' => 'livejasmin',
            'label' => 'LiveJasmin',
            'go_url' => 'https://example.test/go/livejasmin/julieta',
            'is_primary' => true,
            'username' => 'julieta-montesco',
            'source' => 'verified_links',
        ]],
        'active_platforms' => ['LiveJasmin'],
        'resolved_destinations' => ['watch_cta_destinations' => [], 'active_platform_labels' => ['LiveJasmin'], 'all_verified_destinations' => []],
        'model_data_gate' => ['is_sufficient' => true],
        'tags' => ['dancing'],
    ]);
    pr607_assert(!str_contains((string) $no_url_payload['watch_section_html'], 'Watch Julieta Montesco on LiveJasmin</a>'), 'Missing confirmed URL should not invent the confirmed outbound anchor.');

    $explicit_raw = 'butt plugs, close up, dancing, dildo, fingering, love beads, oil, roleplay, striptease, vibrator, POV, foot fetish, snapshot, and JOI.';
    $private_text = ModelResearchEvidence::humanize_private_chat($explicit_raw);
    pr607_assert($private_text !== '', 'Private-chat evidence should render non-empty output when safe filtered evidence exists.');
    pr607_assert(!str_contains($private_text, $explicit_raw), 'Private-chat evidence should not dump the full raw explicit list.');
    foreach (['Close up', 'Dancing', 'Oil', 'Roleplay', 'Striptease', 'Foot Fetish', 'Snapshot'] as $safe_item) {
        pr607_assert(str_contains($private_text, $safe_item), 'Private-chat evidence should preserve safe item: ' . $safe_item);
    }
    foreach (['butt plugs', 'dildo', 'fingering', 'love beads', 'vibrator', 'JOI', 'POV'] as $explicit) {
        pr607_assert(stripos($private_text, $explicit) === false, 'Private-chat evidence should filter unsafe item: ' . $explicit);
    }
    foreach (['The verified notes' . ' point to', 'personable cam ' . 'delivery', 'do you ' . 'accept', 'Use these notes as profile ' . 'context'] as $forbidden) {
        pr607_assert(stripos($private_text, $forbidden) === false, 'Private-chat evidence should not include forbidden wording: ' . $forbidden);
    }
    pr607_assert(!str_contains($private_text, 'private-chat availability, interactive requests, roleplay-style options, and media/chat features'), 'Private-chat evidence should not use the old generic collapse sentence.');

    pr607_assert(!str_contains($html, 'Feature check for how to join live cam chat'), 'Robotic feature heading should be removed.');
    pr607_assert(!str_contains($html, 'Confirmed Official Profile Link and live webcam chat tips'), 'Robotic official-link heading should be removed.');
    pr607_assert(str_contains($html, 'Live Chat Experience') || str_contains($html, 'Confirmed Live Profile') || str_contains($html, 'Official Live Access'), 'Output should contain a natural live/profile heading.');
    pr607_assert(substr_count($html, 'payment/privacy controls') <= 1, 'payment/privacy controls should not repeat.');
    pr607_assert(substr_count($html, 'recheck status before joining') <= 1, 'recheck status before joining should not repeat.');

    pr607_assert($GLOBALS['pr607_db_writes'] === [], 'PR607 smoke should not perform database writes.');
    pr607_assert($GLOBALS['pr607_generate_executed'] === false, 'PR607 smoke should not execute Generate.');

    echo "✓ PR 607 model Template outbound link and human copy smoke checks passed\n";
}
