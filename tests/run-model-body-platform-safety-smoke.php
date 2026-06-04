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
require_once TMWSEO_ENGINE_PATH . 'includes/content/class-template-content.php';
require_once TMWSEO_ENGINE_PATH . 'includes/content/class-model-research-evidence.php';
require_once TMWSEO_ENGINE_PATH . 'includes/model/class-model-draft-context-builder.php';
require_once TMWSEO_ENGINE_PATH . 'includes/model/class-model-content-draft-service.php';
require_once TMWSEO_ENGINE_PATH . 'includes/content/class-model-destination-resolver.php';
require_once TMWSEO_ENGINE_PATH . 'includes/model/class-model-content-generation-facade.php';

use TMWSEO\Engine\Model\ModelBodySafety;
use TMWSEO\Engine\Model\ModelContentGenerationFacade;
use TMWSEO\Engine\Model\ModelContentDraftService;
use TMWSEO\Engine\Model\ModelDraftContextBuilder;
use TMWSEO\Engine\Content\ModelDestinationResolver;
use TMWSEO\Engine\Content\TemplateContent;
use TMWSEO\Engine\Content\ModelResearchEvidence;
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


smoke_assert_body(!ModelBodySafety::verified_link_is_live_eligible(['type' => 'livejasmin', 'url' => 'https://livejasmin.com/a', 'activity_level' => 'active']), 'missing is_active must fail closed');
smoke_assert_body(!ModelBodySafety::verified_link_is_live_eligible(['type' => 'livejasmin', 'url' => 'https://livejasmin.com/a', 'is_active' => 1]), 'missing activity_level must fail closed');
smoke_assert_body(!ModelBodySafety::verified_link_is_live_eligible(['type' => 'livejasmin', 'url' => 'https://livejasmin.com/a', 'is_active' => 1, 'activity_level' => 'recently_live']), 'invalid activity_level must fail closed');
smoke_assert_body(!ModelBodySafety::verified_link_is_live_eligible(['type' => 'livejasmin', 'url' => 'https://livejasmin.com/a', 'is_active' => 1, 'activity_level' => '']), 'empty activity_level must fail closed');
smoke_assert_body(ModelBodySafety::verified_link_is_live_eligible(['type' => 'livejasmin', 'url' => 'https://livejasmin.com/a', 'is_active' => 1, 'activity_level' => 'active']), 'explicit active + is_active should pass');
smoke_assert_body(ModelBodySafety::verified_link_is_live_eligible(['type' => 'livejasmin', 'url' => 'https://livejasmin.com/a', 'is_active' => 1, 'activity_level' => 'very_active']), 'explicit very_active + is_active should pass');
smoke_assert_body(!ModelBodySafety::verified_link_is_live_eligible(['type' => 'livejasmin', 'url' => 'https://livejasmin.com/a', 'is_active' => 0, 'activity_level' => 'active']), 'inactive checkbox must fail closed even with active level');

$anisyia = build_body_html(101, 'Anisyia', [
    ['platform' => 'livejasmin', 'label' => 'LiveJasmin', 'profile_url' => 'https://livejasmin.com/en/chat/Anisyia', 'is_primary' => true],
    ['platform' => 'camsoda', 'label' => 'CamSoda', 'profile_url' => 'https://www.camsoda.com/anisyia'],
], [
    ['type' => 'livejasmin', 'label' => 'LiveJasmin', 'url' => 'https://livejasmin.com/en/chat/Anisyia', 'is_active' => 1, 'activity_level' => 'very_active', 'is_primary' => true],
    ['type' => 'camsoda', 'label' => 'CamSoda', 'url' => 'https://www.camsoda.com/anisyia', 'is_active' => 1, 'activity_level' => ''],
], ['Anisyia bbw cam model', 'Anisyia ebony cam model', 'private live chat'], ['Anisyia CamSoda', 'LiveJasmin profile']);

smoke_body_contains($anisyia, 'LiveJasmin', 'Anisyia active platform');
foreach (['Watch Live on CamSoda', 'Watch Anisyia on CamSoda', 'Anisyia CamSoda', 'Live profiles are available on LiveJasmin and CamSoda', 'CamSoda profile', 'confirmed live profile on CamSoda', 'bbw cam model', 'ebony cam model'] as $needle) {
    smoke_body_not_contains($anisyia, $needle, 'Anisyia safety');
}

$aisha = build_body_html(102, 'Aisha Dupont', [
    ['platform' => 'stripchat', 'label' => 'Stripchat', 'profile_url' => 'https://stripchat.com/AishaDupont', 'is_primary' => true],
    ['platform' => 'chaturbate', 'label' => 'Chaturbate', 'profile_url' => 'https://chaturbate.com/aishadupont/'],
    ['platform' => 'livejasmin', 'label' => 'LiveJasmin', 'profile_url' => 'https://livejasmin.com/en/chat/AishaDupont'],
], [
    ['type' => 'stripchat', 'label' => 'Stripchat', 'url' => 'https://stripchat.com/AishaDupont', 'is_active' => 1, 'activity_level' => 'very_active', 'is_primary' => true],
    ['type' => 'chaturbate', 'label' => 'Chaturbate', 'url' => 'https://chaturbate.com/aishadupont/', 'is_active' => 1, 'activity_level' => 'active'],
    ['type' => 'livejasmin', 'label' => 'LiveJasmin', 'url' => 'https://livejasmin.com/en/chat/AishaDupont', 'is_active' => 1, 'activity_level' => ''],
], ['The verified notes point to', 'personable cam delivery'], ['Use the the links below below']);

smoke_body_contains($aisha, 'Watch Live on Stripchat', 'Aisha active Stripchat');
smoke_body_contains($aisha, 'Watch Live on Chaturbate', 'Aisha active Chaturbate');
foreach (['Watch Live on LiveJasmin', 'Open the verified live destination on LiveJasmin', 'Live profiles are available on LiveJasmin', 'confirmed live profile on LiveJasmin', 'personable cam delivery', 'The verified notes point to', 'Use the the links below below', 'links below below', 'the the', 'below below', '..'] as $needle) {
    smoke_body_not_contains($aisha, $needle, 'Aisha cleanup');
}

$abby = build_body_html(103, 'Abby Murray', [
    ['platform' => 'livejasmin', 'label' => 'LiveJasmin', 'profile_url' => 'https://livejasmin.com/en/chat/AbbyMurray', 'is_primary' => true],
], [
    ['type' => 'livejasmin', 'label' => 'LiveJasmin', 'url' => 'https://livejasmin.com/en/chat/AbbyMurray', 'is_active' => 1, 'activity_level' => 'active', 'is_primary' => true],
], ['private live chat'], []);
smoke_body_contains($abby, 'LiveJasmin', 'Abby active platform');
smoke_body_not_contains($abby, '..', 'Abby punctuation');

$allysa = build_body_html(104, 'Allysa Quinn', [
    ['platform' => 'livejasmin', 'label' => 'LiveJasmin', 'profile_url' => 'https://livejasmin.com/en/chat/AllysaQuinn', 'is_primary' => true],
], [
    ['type' => 'livejasmin', 'label' => 'LiveJasmin', 'url' => 'https://livejasmin.com/en/chat/AllysaQuinn', 'is_active' => 1, 'activity_level' => 'very_active', 'is_primary' => true],
], ['private live chat'], []);
smoke_body_contains($allysa, 'LiveJasmin', 'Allysa active platform');
smoke_body_not_contains($allysa, 'CamSoda', 'Allysa remains LiveJasmin-only');
smoke_body_not_contains($allysa, 'Stripchat', 'Allysa remains LiveJasmin-only');

$turn_on_cleanup = ModelResearchEvidence::humanize_turn_ons('Beautiful People, To Help, Chocolate, and Morning Coffee And Animals.,');
smoke_body_not_contains($turn_on_cleanup, '.,', 'turn-on cleanup malformed punctuation');
smoke_body_not_contains($turn_on_cleanup, 'Beautiful People, To Help, Chocolate, and Morning Coffee And Animals.,', 'turn-on cleanup broken title-case dump');


VerifiedLinks::$links[201] = [
    ['type' => 'livejasmin', 'label' => 'LiveJasmin', 'url' => 'https://livejasmin.com/en/chat/Anisyia', 'is_active' => 1, 'activity_level' => 'very_active', 'is_primary' => true],
    ['type' => 'camsoda', 'label' => 'CamSoda', 'url' => 'https://www.camsoda.com/anisyia', 'is_active' => 0, 'activity_level' => 'inactive'],
];
$resolved = ModelDestinationResolver::resolve(201);
$cta_labels = array_map(static fn(array $row): string => (string) ($row['label'] ?? ''), (array) ($resolved['watch_cta_destinations'] ?? []));
smoke_assert_body($cta_labels === ['LiveJasmin'], 'Resolver CTA labels should include only active/very_active verified cam platforms');
$active_labels = (array) ($resolved['active_platform_labels'] ?? []);
smoke_assert_body($active_labels === ['LiveJasmin'], 'Resolver active labels should omit inactive CamSoda');

VerifiedLinks::$links[202] = [
    ['type' => 'instagram', 'label' => 'Instagram', 'url' => 'https://instagram.com/socialactive', 'is_active' => 1, 'activity_level' => 'active'],
    ['type' => 'personal_site', 'label' => 'Official Site', 'url' => 'https://model.example.test/', 'is_active' => 1, 'activity_level' => 'active'],
    ['type' => 'stripchat', 'label' => 'Stripchat', 'url' => 'https://stripchat.com/camactive', 'is_active' => 1, 'activity_level' => 'very_active'],
];
$resolved_non_cam = ModelDestinationResolver::resolve(202);
$summary_method = new ReflectionMethod(TemplateContent::class, 'build_link_evidence_summary');
$summary_method->setAccessible(true);
$non_cam_summary = $summary_method->invoke(null, $resolved_non_cam, (array) ($resolved_non_cam['watch_cta_destinations'] ?? []));
smoke_assert_body((int) ($non_cam_summary['live_count'] ?? 0) === 1, 'active non-cam rows must not count as live profiles');
smoke_assert_body(($non_cam_summary['live_platform_labels'] ?? []) === ['Stripchat'], 'only active CAM rows should be live platform labels');
smoke_assert_body((int) ($non_cam_summary['social_count'] ?? 0) === 1, 'active Instagram should remain social evidence only');
smoke_assert_body((int) ($non_cam_summary['personal_site_count'] ?? 0) === 1, 'active personal site should remain personal evidence only');


VerifiedLinks::$links[301] = [
    ['type' => 'livejasmin', 'label' => 'Inactive LiveJasmin', 'url' => 'https://livejasmin.com/en/chat/inactive', 'is_active' => 0, 'activity_level' => 'inactive'],
    ['type' => 'stripchat', 'label' => 'Missing Activity', 'url' => 'https://stripchat.com/missingactivity', 'is_active' => 1],
    ['type' => 'chaturbate', 'label' => 'Active Chaturbate', 'url' => 'https://chaturbate.com/active/', 'is_active' => 1, 'activity_level' => 'active'],
];
$draft_links_method = new ReflectionMethod(ModelDraftContextBuilder::class, 'build_verified_links_context');
$draft_links_method->setAccessible(true);
$draft_links = $draft_links_method->invoke(null, 301);
smoke_assert_body(count($draft_links) === 1 && ($draft_links[0]['type'] ?? '') === 'chaturbate', 'draft context should pass only live-eligible verified links');
$draft_preview = build_body_html(301, 'Draft Filter', [
    ['platform' => 'chaturbate', 'label' => 'Active Chaturbate', 'profile_url' => 'https://chaturbate.com/active/'],
], $draft_links);
smoke_body_contains($draft_preview, 'Active Chaturbate', 'active verified preview link');
smoke_body_not_contains($draft_preview, 'Inactive LiveJasmin', 'inactive verified preview link');
smoke_body_not_contains($draft_preview, 'Missing Activity', 'missing activity verified preview link');

$alias_html = build_body_html(302, 'Alias Model', [
    ['platform' => 'livejasmin', 'label' => 'LiveJasmin', 'profile_url' => 'https://livejasmin.com/en/chat/CorrectAlias'],
    ['platform' => 'livejasmin', 'label' => 'LiveJasmin', 'profile_url' => 'https://livejasmin.com/en/chat/StaleAlias'],
], [
    ['type' => 'jasmin', 'label' => 'LiveJasmin', 'url' => 'https://livejasmin.com/en/chat/CorrectAlias', 'is_active' => 1, 'activity_level' => 'very_active'],
]);
smoke_body_contains($alias_html, 'CorrectAlias', 'jasmin/livejasmin alias verified URL');
smoke_body_not_contains($alias_html, 'StaleAlias', 'jasmin/livejasmin stale URL');

$rankmath_method = new ReflectionMethod(TemplateContent::class, 'select_body_safe_rankmath_keywords');
$rankmath_method->setAccessible(true);
$rankmath_keywords = $rankmath_method->invoke(null, [
    'rankmath_additional' => [
        'Anisyia CamSoda',
        'Anisyia bbw cam model',
        'Anisyia ebony cam model',
        'streamate cam model',
        'Anisyia live cam',
        'Anisyia live webcam',
        'Anisyia private live chat',
        'Anisyia profile links',
    ],
], [], 'Anisyia', [
    ['type' => 'camsoda', 'label' => 'CamSoda', 'url' => 'https://www.camsoda.com/anisyia', 'is_active' => 0, 'activity_level' => 'inactive'],
]);
smoke_assert_body($rankmath_keywords === ['Anisyia live cam', 'Anisyia live webcam', 'Anisyia private live chat', 'Anisyia profile links'], 'unsafe first-four phrases should be filtered and backfilled by later safe phrases');

VerifiedLinks::$links[401] = [
    ['type' => 'livejasmin', 'label' => 'Invalid URL', 'url' => 'javascript:alert(1)', 'is_active' => 1, 'activity_level' => 'active'],
    ['type' => 'stripchat', 'label' => 'FTP URL', 'url' => 'ftp://stripchat.example/model', 'is_active' => 1, 'activity_level' => 'active'],
    ['type' => 'chaturbate', 'label' => 'Valid URL', 'url' => 'https://chaturbate.com/valid/', 'is_active' => 1, 'activity_level' => 'active'],
];
$draft_profiles_method = new ReflectionMethod(ModelContentDraftService::class, 'collect_platform_profiles');
$draft_profiles_method->setAccessible(true);
$draft_profiles = $draft_profiles_method->invoke(null, 401);
smoke_assert_body(count($draft_profiles) === 1 && ($draft_profiles[0]['platform'] ?? '') === 'chaturbate', 'invalid platform profile URLs should be skipped');
smoke_assert_body(($draft_profiles[0]['activity_level'] ?? '') === 'active', 'emitted draft activity_level should be normalized');

echo "OK: model body platform safety smoke passed\n";
