<?php
/**
 * Smoke checks for PR 608 LiveJasmin affiliate settings persistence and direct generated hrefs.
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Services {
    class Settings { public static function get(string $key, $default = null) { return $default; } }
}

namespace TMWSEO\Engine {
    class Logs { public static function info(string $channel, string $message, array $context = []): void {} }
}

namespace {
    if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

    $GLOBALS['pr608_options'] = [];
    $GLOBALS['pr608_post_meta'] = [];
    $GLOBALS['pr608_warnings'] = [];
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if ($severity & (E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED)) {
            $GLOBALS['pr608_warnings'][] = $message . ' at ' . $file . ':' . $line;
        }
        return false;
    });

    function pr608_assert(bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    if (!function_exists('__')) { function __($text, $domain = null): string { return (string) $text; } }
    if (!function_exists('sanitize_text_field')) { function sanitize_text_field($text): string { return trim(strip_tags((string) $text)); } }
    if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($text): string { return trim(strip_tags((string) $text)); } }
    if (!function_exists('sanitize_key')) { function sanitize_key($key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)) ?? ''; } }
    if (!function_exists('wp_unslash')) { function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes((string) $value); } }
    if (!function_exists('esc_url_raw')) { function esc_url_raw($url): string { return (string) $url; } }
    if (!function_exists('esc_url')) { function esc_url($url): string { return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8'); } }
    if (!function_exists('esc_html')) { function esc_html($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); } }
    if (!function_exists('wp_parse_url')) { function wp_parse_url(string $url, int $component = -1) { return $component === -1 ? parse_url($url) : parse_url($url, $component); } }
    if (!function_exists('home_url')) { function home_url(string $path = ''): string { return 'https://example.test/' . ltrim($path, '/'); } }
    if (!function_exists('wp_http_validate_url')) { function wp_http_validate_url($url): string|false { return filter_var((string) $url, FILTER_VALIDATE_URL) ? (string) $url : false; } }
    if (!function_exists('filter_var')) { throw new RuntimeException('filter_var unavailable.'); }
    if (!function_exists('add_query_arg')) {
        function add_query_arg(array $params, string $url): string {
            $fragment = '';
            $hash_pos = strpos($url, '#');
            if ($hash_pos !== false) {
                $fragment = substr($url, $hash_pos);
                $url = substr($url, 0, $hash_pos);
            }

            $base = $url;
            $existing_query = '';
            $query_pos = strpos($url, '?');
            if ($query_pos !== false) {
                $base = substr($url, 0, $query_pos);
                $existing_query = substr($url, $query_pos + 1);
            }

            $existing_params = [];
            if ($existing_query !== '') {
                parse_str($existing_query, $existing_params);
            }

            $query = http_build_query(array_merge($existing_params, $params), '', '&', PHP_QUERY_RFC3986);
            $query = str_replace(['%5B', '%5D'], ['[', ']'], $query);

            return $base . ($query !== '' ? '?' . $query : '') . $fragment;
        }
    }
    $add_query_arg_probe = add_query_arg(['b' => '2'], 'https://example.com/path?a=1#frag');
    pr608_assert(str_contains($add_query_arg_probe, 'a=1'), 'add_query_arg stub should preserve existing query parameters.');
    pr608_assert(str_contains($add_query_arg_probe, 'b=2'), 'add_query_arg stub should append new query parameters.');
    pr608_assert(str_contains($add_query_arg_probe, '#frag'), 'add_query_arg stub should preserve URL fragments.');
    pr608_assert(substr_count($add_query_arg_probe, '?') === 1, 'add_query_arg stub should not duplicate question marks.');

    if (!function_exists('get_option')) { function get_option(string $name, $default = false) { return $GLOBALS['pr608_options'][$name] ?? $default; } }
    if (!function_exists('update_option')) { function update_option(string $name, $value): bool { $GLOBALS['pr608_options'][$name] = $value; return true; } }
    if (!function_exists('get_post_meta')) {
        function get_post_meta(int $post_id, string $key = '', bool $single = false) {
            $value = $GLOBALS['pr608_post_meta'][$post_id][$key] ?? '';
            return $single ? $value : [$value];
        }
    }

    require_once dirname(__DIR__) . '/includes/platform/class-platform-registry.php';
    require_once dirname(__DIR__) . '/includes/platform/class-platform-profiles.php';
    require_once dirname(__DIR__) . '/includes/platform/class-affiliate-link-builder.php';
    require_once dirname(__DIR__) . '/includes/model/class-verified-links-families.php';
    require_once dirname(__DIR__) . '/includes/model/class-verified-links.php';
    require_once dirname(__DIR__) . '/includes/admin/class-admin.php';
    require_once dirname(__DIR__) . '/includes/content/class-model-destination-resolver.php';
    require_once dirname(__DIR__) . '/includes/content/class-template-content.php';

    use TMWSEO\Engine\Admin;
    use TMWSEO\Engine\Content\ModelDestinationResolver;
    use TMWSEO\Engine\Content\TemplateContent;
    use TMWSEO\Engine\Platform\AffiliateLinkBuilder;

    $submitted = [
        'livejasmin' => [
            'enabled' => '1',
            'template' => '',
            'campaign' => '',
            'source' => '',
            'subaffid' => '',
            'psid' => 'Topmodels4u',
            'pstool' => '205_1',
            'psprogram' => 'revs',
            'campaign_id' => '',
            'siteid' => 'jasmin',
            'categoryname' => 'girl',
            'pagename' => 'freechat',
        ],
    ];

    update_option('tmwseo_platform_affiliate_settings', Admin::sanitize_platform_affiliate_settings($submitted));
    $settings = get_option('tmwseo_platform_affiliate_settings', []);
    $lj = $settings['livejasmin'] ?? [];

    foreach ([
        'psid' => 'Topmodels4u',
        'pstool' => '205_1',
        'psprogram' => 'revs',
        'siteid' => 'jasmin',
        'categoryname' => 'girl',
        'pagename' => 'freechat',
    ] as $key => $expected) {
        pr608_assert(($lj[$key] ?? null) === $expected, 'Saved LiveJasmin setting mismatch for ' . $key);
    }
    pr608_assert(!empty($lj['enabled']), 'LiveJasmin enabled flag should persist.');
    pr608_assert(array_key_exists('campaign_id', $lj) && $lj['campaign_id'] === '', 'Empty campaign_id should persist as an empty string.');
    pr608_assert(array_key_exists('subaffid', $lj) && $lj['subaffid'] === '', 'Empty subaffid should persist as an empty string.');

    $url = AffiliateLinkBuilder::build_seo_content_affiliate_url('livejasmin', 'JulietaMontesco');
    pr608_assert(str_starts_with($url, 'https://ctwmsg.com/'), 'SEO affiliate URL should use the ctwmsg.com endpoint.');
    foreach (['performerName=JulietaMontesco', 'siteId=jasmin', 'categoryName=girl', 'pageName=freechat'] as $needle) {
        pr608_assert(str_contains($url, $needle), 'SEO affiliate URL missing ' . $needle);
    }
    foreach (['prm[psid]=Topmodels4u', 'prm%5Bpsid%5D=Topmodels4u', 'prm[pstool]=205_1', 'prm%5Bpstool%5D=205_1', 'prm[psprogram]=revs', 'prm%5Bpsprogram%5D=revs'] as $needle) {
        if (str_contains($url, $needle)) { $found[$needle] = true; }
    }
    pr608_assert(str_contains($url, 'psid') && str_contains($url, 'Topmodels4u'), 'SEO affiliate URL missing psid tracking value.');
    pr608_assert(str_contains($url, 'pstool') && str_contains($url, '205_1'), 'SEO affiliate URL missing pstool tracking value.');
    pr608_assert(str_contains($url, 'psprogram') && str_contains($url, 'revs'), 'SEO affiliate URL missing psprogram tracking value.');

    $GLOBALS['pr608_post_meta'][123]['_tmwseo_platform_username_livejasmin'] = 'LegacyJulieta';
    $resolved = ModelDestinationResolver::resolve(123, [[
        'platform' => 'livejasmin',
        'username' => 'LegacyJulieta',
        'go_url' => home_url('/go/livejasmin/LegacyJulieta/'),
        'is_primary' => true,
    ]], [[
        'type' => 'livejasmin',
        'url' => 'https://www.livejasmin.com/en/chat/JulietaMontesco',
        'label' => 'LiveJasmin',
        'activity_level' => 'active',
    ]], []);
    $row = $resolved['watch_cta_destinations'][0] ?? [];
    pr608_assert(($row['go_url'] ?? '') === 'https://example.test/go/livejasmin/JulietaMontesco/', 'Verified row should derive the /go/ URL from the verified URL username.');
    pr608_assert(($row['username'] ?? '') === 'JulietaMontesco', 'Verified row should ignore legacy username meta when deriving the username.');
    pr608_assert(str_starts_with((string) ($row['seo_affiliate_url'] ?? ''), 'https://ctwmsg.com/'), 'Verified row should include seo_affiliate_url.');

    $render_method = new ReflectionMethod(TemplateContent::class, 'render_confirmed_outbound_watch_cta');
    $html = (string) $render_method->invoke(null, [$row], 'Julieta Montesco');
    pr608_assert(str_contains($html, 'href="https://ctwmsg.com/'), 'Outbound CTA should use ctwmsg.com, not /go/.');
    pr608_assert(!str_contains($html, '/go/livejasmin/'), 'Outbound CTA should reject internal /go/ URLs.');
    pr608_assert(str_contains($html, 'target="_blank"'), 'Outbound CTA should keep target _blank.');

    pr608_assert($GLOBALS['pr608_warnings'] === [], 'Smoke should not emit PHP warnings/notices: ' . implode('; ', $GLOBALS['pr608_warnings']));

    restore_error_handler();
    echo "✓ PR 608 LiveJasmin affiliate settings and direct href smoke checks passed\n";
}
