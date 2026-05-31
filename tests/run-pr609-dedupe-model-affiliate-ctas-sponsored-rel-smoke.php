<?php
/**
 * Smoke checks for PR 609 model affiliate CTA de-duplication and sponsored-only rel.
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Services {
    class Settings { public static function get(string $key, $default = null) { return $default; } }
    class TitleFixer {}
}

namespace TMWSEO\Engine\Keywords {
    class ModelKeywordPack {}
}

namespace TMWSEO\Engine\Platform {
    class PlatformProfiles {
        public static function sync_to_table(int $post_id): void {}
        public static function get_links(int $post_id): array { return []; }
        public static function extract_username_from_profile_url(string $platform, string $url): string { return ''; }
    }
}

namespace TMWSEO\Engine\Model {
    class VerifiedLinks {
        public static function get_links(int $post_id): array { return []; }
        public static function get_routed_url(array $link): string { return (string) ($link['url'] ?? ''); }
    }
}

namespace TMWSEO\Engine {
    class Logs { public static function info(string $channel, string $message, array $context = []): void {} }
}

namespace {
    if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

    $GLOBALS['pr609_options'] = [
        'tmwseo_platform_affiliate_settings' => [
            'livejasmin' => [
                'enabled' => '1',
                'template' => '',
                'psid' => 'Topmodels4u',
                'pstool' => '205_1',
                'psprogram' => 'revs',
                'campaign_id' => '',
                'subaffid' => '',
                'siteid' => 'jasmin',
                'categoryname' => 'girl',
                'pagename' => 'freechat',
            ],
        ],
    ];
    $GLOBALS['pr609_db_writes'] = [];
    $GLOBALS['pr609_generate_executed'] = false;

    function pr609_assert(bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    if (!function_exists('esc_html')) { function esc_html($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); } }
    if (!function_exists('esc_attr')) { function esc_attr($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); } }
    if (!function_exists('esc_url')) { function esc_url($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); } }
    if (!function_exists('esc_url_raw')) { function esc_url_raw($text): string { return (string) $text; } }
    if (!function_exists('wp_kses_post')) { function wp_kses_post($html): string { return (string) $html; } }
    if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($text): string { return trim(strip_tags((string) $text)); } }
    if (!function_exists('sanitize_text_field')) { function sanitize_text_field($text): string { return trim(strip_tags((string) $text)); } }
    if (!function_exists('sanitize_key')) { function sanitize_key($key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)) ?? ''; } }
    if (!function_exists('wp_unslash')) { function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes((string) $value); } }
    if (!function_exists('wp_parse_url')) { function wp_parse_url(string $url, int $component = -1) { return $component === -1 ? parse_url($url) : parse_url($url, $component); } }
    if (!function_exists('home_url')) { function home_url(string $path = ''): string { return 'https://example.test/' . ltrim($path, '/'); } }
    if (!function_exists('get_bloginfo')) { function get_bloginfo(string $show = ''): string { return 'Smoke Site'; } }
    if (!function_exists('wp_http_validate_url')) { function wp_http_validate_url($url): string|false { return filter_var((string) $url, FILTER_VALIDATE_URL) ? (string) $url : false; } }
    if (!function_exists('get_option')) { function get_option(string $name, $default = false) { return $GLOBALS['pr609_options'][$name] ?? $default; } }
    if (!function_exists('update_option')) { function update_option(string $name, $value): bool { $GLOBALS['pr609_db_writes'][] = ['update_option', $name, $value]; return true; } }
    if (!function_exists('get_post_meta')) { function get_post_meta(int $post_id, string $key = '', bool $single = false) { return ''; } }
    if (!function_exists('update_post_meta')) { function update_post_meta(...$args) { $GLOBALS['pr609_db_writes'][] = ['update_post_meta', $args]; return true; } }
    if (!function_exists('wp_update_post')) { function wp_update_post(...$args) { $GLOBALS['pr609_db_writes'][] = ['wp_update_post', $args]; return 1; } }
    if (!class_exists('WP_Post')) { class WP_Post { public int $ID = 609; public string $post_title = 'Julieta Montesco'; } }

    require_once dirname(__DIR__) . '/includes/model/class-verified-links-families.php';
    require_once dirname(__DIR__) . '/includes/platform/class-platform-registry.php';
    require_once dirname(__DIR__) . '/includes/platform/class-affiliate-link-builder.php';
    require_once dirname(__DIR__) . '/includes/templates/class-template-engine.php';
    require_once dirname(__DIR__) . '/includes/content/class-model-destination-resolver.php';
    require_once dirname(__DIR__) . '/includes/content/class-model-page-renderer.php';
    require_once dirname(__DIR__) . '/includes/content/class-template-content.php';

    use TMWSEO\Engine\Content\ModelPageRenderer;
    use TMWSEO\Engine\Content\TemplateContent;
    use TMWSEO\Engine\Platform\AffiliateLinkBuilder;

    $cta_links = [[
        'platform' => 'livejasmin',
        'label' => 'LiveJasmin',
        'go_url' => home_url('/go/livejasmin/JulietaMontesco/'),
        'seo_affiliate_url' => AffiliateLinkBuilder::build_seo_content_affiliate_url('livejasmin', 'JulietaMontesco'),
        'is_primary' => true,
        'username' => 'JulietaMontesco',
        'source' => 'platform_profiles',
    ]];

    $support_method = new ReflectionMethod(TemplateContent::class, 'build_model_renderer_support_payload');
    $payload = $support_method->invoke(null, new WP_Post(), [
        'name' => 'Julieta Montesco',
        'cta_links' => $cta_links,
        'active_platforms' => ['LiveJasmin'],
        'resolved_destinations' => [
            'watch_cta_destinations' => $cta_links,
            'active_platform_labels' => ['LiveJasmin'],
            'source_of_truth_summary' => ['verified_count' => 1],
            'all_verified_destinations' => [],
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
        'intro_paragraphs' => ['Julieta Montesco has a confirmed LiveJasmin profile for live access.'],
        'watch_section_paragraphs' => ['Open the confirmed LiveJasmin profile below.'],
        'features_section_paragraphs' => ['Check chat availability before joining.'],
        'link_evidence_summary' => ['live_count' => 1, 'extra_count' => 0, 'total_count' => 1, 'has_live_profile' => true, 'has_extra_links' => false, 'has_any_links' => true],
    ]));

    preg_match_all('/<a\b[^>]*href="([^"]*ctwmsg\.com[^"]*)"[^>]*>/i', $html, $ctw_matches);
    pr609_assert(count($ctw_matches[0]) === 1, 'Generated model body should contain exactly one direct ctwmsg.com anchor. HTML: ' . $html);

    $anchor = $ctw_matches[0][0];
    $href = html_entity_decode($ctw_matches[1][0], ENT_QUOTES, 'UTF-8');
    foreach (['performerName=JulietaMontesco', 'siteId=jasmin', 'categoryName=girl', 'pageName=freechat'] as $needle) {
        pr609_assert(str_contains($href, $needle), 'Direct affiliate href missing ' . $needle . ': ' . $href);
    }
    foreach ([['psid', 'Topmodels4u'], ['pstool', '205_1'], ['psprogram', 'revs']] as [$key, $value]) {
        pr609_assert(str_contains($href, $key) && str_contains($href, $value), 'Direct affiliate href missing ' . $key . ' tracking value: ' . $href);
    }
    pr609_assert(str_contains($href, 'campaign_id]=' ) || str_contains($href, 'campaign_id%5D='), 'Direct affiliate href should preserve campaign_id as explicit empty value: ' . $href);
    pr609_assert(str_contains($href, 'subAffId='), 'Direct affiliate href should preserve subAffId as explicit empty value: ' . $href);

    pr609_assert((bool) preg_match('/\brel="([^"]*)"/i', $anchor, $rel_match), 'Direct affiliate anchor should include rel.');
    $rel = strtolower($rel_match[1]);
    pr609_assert(str_contains($rel, 'sponsored'), 'Direct affiliate rel should contain sponsored.');
    pr609_assert(str_contains($rel, 'noopener'), 'Direct affiliate rel should contain noopener.');
    pr609_assert(!str_contains($rel, 'nofollow'), 'Direct affiliate rel should not contain nofollow.');
    pr609_assert(str_contains($anchor, 'target="_blank"'), 'Direct affiliate anchor should keep target _blank.');

    preg_match_all('/<a\b[^>]*href="[^"]*\/go\/livejasmin\/JulietaMontesco\/[^"]*"[^>]*>/i', $html, $go_matches);
    pr609_assert(count($go_matches[0]) <= 1, 'Generated model body should not contain duplicate /go/livejasmin/JulietaMontesco/ anchors.');
    pr609_assert(AffiliateLinkBuilder::go_url('livejasmin', 'JulietaMontesco') === 'https://example.test/go/livejasmin/JulietaMontesco/', '/go/ route builder should remain unchanged.');
    pr609_assert($GLOBALS['pr609_db_writes'] === [], 'PR609 smoke should not perform database writes.');
    pr609_assert($GLOBALS['pr609_generate_executed'] === false, 'PR609 smoke should not execute Generate.');

    echo "✓ PR 609 dedupe model affiliate CTA and sponsored rel smoke checks passed\n";
}
