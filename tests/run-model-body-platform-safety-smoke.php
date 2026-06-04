<?php
/**
 * Smoke test: generated model body platform safety.
 *
 * Run: php tests/run-model-body-platform-safety-smoke.php
 */
declare(strict_types=1);

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__); }
if (!defined('TMWSEO_ENGINE_PATH')) { define('TMWSEO_ENGINE_PATH', dirname(__DIR__) . '/'); }

$GLOBALS['_tmw_body_posts'] = [];

function smoke_assert_body(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
function smoke_body_contains(string $haystack, string $needle, string $context): void { smoke_assert_body(stripos($haystack, $needle) !== false, "{$context}: missing {$needle}"); }
function smoke_body_not_contains(string $haystack, string $needle, string $context): void { smoke_assert_body(stripos($haystack, $needle) === false, "{$context}: forbidden {$needle}"); }

if (!class_exists('WP_Post')) {
    class WP_Post { public int $ID = 0; public string $post_title = ''; public string $post_type = 'model'; public string $post_content = ''; public string $post_status = 'publish'; }
}
if (!class_exists('WP_Term')) { class WP_Term { public string $name = ''; } }

if (!function_exists('sanitize_key')) { function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $s)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s) { return strip_tags((string) $s); } }
if (!function_exists('wp_kses_post')) { function wp_kses_post($s) { return (string) $s; } }
if (!function_exists('esc_html')) { function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
if (!function_exists('esc_url')) { function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
if (!function_exists('esc_attr')) { function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
if (!function_exists('home_url')) { function home_url($path = '/') { return 'https://example.test' . (str_starts_with((string) $path, '/') ? (string) $path : '/' . (string) $path); } }
if (!function_exists('get_permalink')) { function get_permalink($post) { $id = is_object($post) ? (int) $post->ID : (int) $post; return home_url('/model/' . $id . '/'); } }
if (!function_exists('get_post')) { function get_post($id) { return $GLOBALS['_tmw_body_posts'][(int) $id] ?? null; } }
if (!function_exists('get_post_meta')) { function get_post_meta($id, $key = '', $single = false) { return ''; } }
if (!function_exists('get_object_taxonomies')) { function get_object_taxonomies($post_type, $output = 'names') { return []; } }
if (!function_exists('wp_get_post_terms')) { function wp_get_post_terms($post_id, $taxonomy, $args = []) { return []; } }
if (!function_exists('get_the_terms')) { function get_the_terms($post, $taxonomy) { return []; } }
if (!function_exists('get_post_type_archive_link')) { function get_post_type_archive_link($post_type) { return home_url('/' . $post_type . 's/'); } }
if (!function_exists('is_wp_error')) { function is_wp_error($thing) { return false; } }
if (!function_exists('wp_parse_url')) { function wp_parse_url($url, $component = -1) { return parse_url((string) $url, $component); } }
if (!function_exists('wp_http_validate_url')) { function wp_http_validate_url($url) { return filter_var((string) $url, FILTER_VALIDATE_URL) ? $url : false; } }

require_once TMWSEO_ENGINE_PATH . 'includes/model/class-model-body-safety.php';
require_once TMWSEO_ENGINE_PATH . 'includes/model/class-verified-links-families.php';

if (!class_exists('TMWSEO\\Engine\\Model\\VerifiedLinks')) {
    eval('namespace TMWSEO\\Engine\\Model; class VerifiedLinks { public static array $links = []; public static function get_links($post_id){ return self::$links[(int)$post_id] ?? []; } public static function get_routed_url($link){ return (string)($link["url"] ?? ""); } }');
}
if (!class_exists('TMWSEO\\Engine\\Platform\\PlatformProfiles')) {
    eval('namespace TMWSEO\\Engine\\Platform; class PlatformProfiles { public static function parse_url_for_platform_structured($platform,$url){ return ["success"=>true,"username"=>self::extract_username_from_profile_url($platform,$url)]; } public static function extract_username_from_profile_url($platform,$url){ $path = parse_url((string)$url, PHP_URL_PATH) ?: ""; $parts = array_values(array_filter(explode("/", trim($path, "/")))); return $parts ? (string) end($parts) : ""; } }');
}
if (!class_exists('TMWSEO\\Engine\\Platform\\AffiliateLinkBuilder')) {
    eval('namespace TMWSEO\\Engine\\Platform; class AffiliateLinkBuilder { public static function go_url($platform,$username){ return "https://example.test/go/" . $platform . "/" . $username; } public static function build_seo_content_affiliate_url($platform,$username){ return "https://" . $platform . ".example/" . $username; } }');
}
if (!class_exists('TMWSEO\\Engine\\Platform\\PlatformRegistry')) {
    eval('namespace TMWSEO\\Engine\\Platform; class PlatformRegistry { public static function get($platform){ $map = ["livejasmin"=>"LiveJasmin", "camsoda"=>"CamSoda", "stripchat"=>"Stripchat", "chaturbate"=>"Chaturbate"]; return ["name" => $map[$platform] ?? ucfirst((string)$platform)]; } }');
}
if (!class_exists('TMWSEO\\Engine\\Content\\TemplateContent')) {
    eval('namespace TMWSEO\\Engine\\Content; class TemplateContent { public static function get_editor_seed_data($post_id){ return []; } }');
}
require_once TMWSEO_ENGINE_PATH . 'includes/content/class-model-destination-resolver.php';
require_once TMWSEO_ENGINE_PATH . 'includes/model/class-model-content-generation-facade.php';

use TMWSEO\Engine\Model\ModelContentGenerationFacade;
use TMWSEO\Engine\Content\ModelDestinationResolver;
use TMWSEO\Engine\Model\VerifiedLinks;

function make_body_post(int $id, string $name): WP_Post {
    $post = new WP_Post();
    $post->ID = $id;
    $post->post_title = $name;
    $GLOBALS['_tmw_body_posts'][$id] = $post;
    return $post;
}

function build_body_html(int $id, string $name, array $platform_profiles, array $verified_links, array $safe_keywords = [], array $platform_keywords = []): string {
    make_body_post($id, $name);
    $payload = ModelContentGenerationFacade::build_preview_draft($id, [
        'platform_profiles' => $platform_profiles,
        'verified_links' => $verified_links,
        'safe_keywords' => $safe_keywords,
        'platform_keywords' => $platform_keywords,
        'internal_links' => [],
        'opportunity' => [],
        'rank_math' => [],
    ]);
    smoke_assert_body(!empty($payload['ok']), $name . ' payload should be ok');
    return (string) ($payload['html_preview'] ?? '');
}

$anisyia = build_body_html(101, 'Anisyia', [
    ['platform' => 'livejasmin', 'label' => 'LiveJasmin', 'profile_url' => 'https://livejasmin.com/en/chat/Anisyia', 'is_primary' => true],
    ['platform' => 'camsoda', 'label' => 'CamSoda', 'profile_url' => 'https://www.camsoda.com/anisyia'],
], [
    ['type' => 'livejasmin', 'label' => 'LiveJasmin', 'url' => 'https://livejasmin.com/en/chat/Anisyia', 'is_active' => 1, 'activity_level' => 'very_active', 'is_primary' => true],
    ['type' => 'camsoda', 'label' => 'CamSoda', 'url' => 'https://www.camsoda.com/anisyia', 'is_active' => 0, 'activity_level' => 'inactive'],
], ['Anisyia bbw cam model', 'Anisyia ebony cam model', 'private live chat'], ['Anisyia CamSoda', 'LiveJasmin profile']);

smoke_body_contains($anisyia, 'LiveJasmin', 'Anisyia active platform');
foreach (['Watch Live on CamSoda', 'Watch Anisyia on CamSoda', 'Anisyia CamSoda', 'Live profiles are available on LiveJasmin and CamSoda', 'bbw cam model', 'ebony cam model'] as $needle) {
    smoke_body_not_contains($anisyia, $needle, 'Anisyia safety');
}

$aisha = build_body_html(102, 'Aisha Dupont', [
    ['platform' => 'stripchat', 'label' => 'Stripchat', 'profile_url' => 'https://stripchat.com/AishaDupont', 'is_primary' => true],
    ['platform' => 'chaturbate', 'label' => 'Chaturbate', 'profile_url' => 'https://chaturbate.com/aishadupont/'],
    ['platform' => 'livejasmin', 'label' => 'LiveJasmin', 'profile_url' => 'https://livejasmin.com/en/chat/AishaDupont'],
], [
    ['type' => 'stripchat', 'label' => 'Stripchat', 'url' => 'https://stripchat.com/AishaDupont', 'is_active' => 1, 'activity_level' => 'active', 'is_primary' => true],
    ['type' => 'chaturbate', 'label' => 'Chaturbate', 'url' => 'https://chaturbate.com/aishadupont/', 'is_active' => 1, 'activity_level' => 'active'],
], ['The verified notes point to', 'personable cam delivery'], ['Use the the links below below']);

smoke_body_contains($aisha, 'Stripchat', 'Aisha active Stripchat');
smoke_body_contains($aisha, 'Chaturbate', 'Aisha active Chaturbate');
foreach (['LiveJasmin', 'personable cam delivery', 'The verified notes point to', 'Use the the links below below', 'links below below', 'the the', 'below below', '..'] as $needle) {
    smoke_body_not_contains($aisha, $needle, 'Aisha cleanup');
}

$abby = build_body_html(103, 'Abby Murray', [
    ['platform' => 'livejasmin', 'label' => 'LiveJasmin', 'profile_url' => 'https://livejasmin.com/en/chat/AbbyMurray', 'is_primary' => true],
], [
    ['type' => 'livejasmin', 'label' => 'LiveJasmin', 'url' => 'https://livejasmin.com/en/chat/AbbyMurray', 'is_active' => 1, 'activity_level' => 'active', 'is_primary' => true],
], ['private live chat'], []);
smoke_body_contains($abby, 'LiveJasmin', 'Abby active platform');
smoke_body_not_contains($abby, '..', 'Abby punctuation');


VerifiedLinks::$links[201] = [
    ['type' => 'livejasmin', 'label' => 'LiveJasmin', 'url' => 'https://livejasmin.com/en/chat/Anisyia', 'is_active' => 1, 'activity_level' => 'very_active', 'is_primary' => true],
    ['type' => 'camsoda', 'label' => 'CamSoda', 'url' => 'https://www.camsoda.com/anisyia', 'is_active' => 0, 'activity_level' => 'inactive'],
];
$resolved = ModelDestinationResolver::resolve(201);
$cta_labels = array_map(static fn(array $row): string => (string) ($row['label'] ?? ''), (array) ($resolved['watch_cta_destinations'] ?? []));
smoke_assert_body($cta_labels === ['LiveJasmin'], 'Resolver CTA labels should include only active/very_active verified cam platforms');
$active_labels = (array) ($resolved['active_platform_labels'] ?? []);
smoke_assert_body($active_labels === ['LiveJasmin'], 'Resolver active labels should omit inactive CamSoda');

echo "OK: model body platform safety smoke passed\n";
