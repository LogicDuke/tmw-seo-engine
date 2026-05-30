<?php
/**
 * Smoke checks for PR 606 model Template link evidence guards.
 */

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

    $GLOBALS['pr606_db_writes'] = [];
    $GLOBALS['pr606_generate_executed'] = false;

    function pr606_assert(bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    if (!function_exists('esc_html')) {
        function esc_html($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    }
    if (!function_exists('esc_attr')) {
        function esc_attr($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    }
    if (!function_exists('esc_url')) {
        function esc_url($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    }
    if (!function_exists('wp_strip_all_tags')) {
        function wp_strip_all_tags($text): string { return trim(strip_tags((string) $text)); }
    }
    if (!function_exists('sanitize_key')) {
        function sanitize_key($key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)) ?? ''; }
    }
    if (!function_exists('update_post_meta')) {
        function update_post_meta(...$args): void { $GLOBALS['pr606_db_writes'][] = ['update_post_meta', $args]; }
    }
    if (!function_exists('wp_update_post')) {
        function wp_update_post(...$args): void { $GLOBALS['pr606_db_writes'][] = ['wp_update_post', $args]; }
    }

    require_once dirname(__DIR__) . '/includes/model/class-verified-links-families.php';
    require_once dirname(__DIR__) . '/includes/templates/class-template-engine.php';
    require_once dirname(__DIR__) . '/includes/content/class-model-page-renderer.php';
    require_once dirname(__DIR__) . '/includes/content/class-template-content.php';

    use TMWSEO\Engine\Content\ModelPageRenderer;
    use TMWSEO\Engine\Content\TemplateContent;

    $forbidden_sparse = [
        'other listed profiles',
        'Other Official Destinations',
        'Social Profiles, Link Hubs, and Channels',
        'CamSoda',
        'personal sites',
        'fan/support pages',
        'video channels',
        'link hubs',
        '0 profile links found, including 1 live profile',
    ];

    $one_live_payload = [
        'link_evidence_summary' => [
            'live_count' => 1,
            'extra_count' => 0,
            'total_count' => 1,
            'has_live_profile' => true,
            'has_extra_links' => false,
            'has_any_links' => true,
        ],
        'intro_paragraphs' => [
            'LiveJasmin is the confirmed live-room option from this check. Start there for live access.',
            'Use the other listed profiles only when you need updates or support.',
        ],
        'watch_section_paragraphs' => [
            'This page currently lists the confirmed LiveJasmin profile only. Use that link as the primary access point and recheck status before joining.',
        ],
        'official_destinations_section_paragraphs' => [
            'CamSoda, personal sites, and fan/support pages are listed in the Official Links and Profiles section below.',
        ],
        'community_destinations_section_paragraphs' => [
            'Video channels, social profiles, and link hubs are listed below for updates.',
        ],
        'official_links_section_paragraphs' => [
            'Latest check: 1 confirmed live profile found.',
            'Latest check: 0 profile links found, including 1 live profile.',
        ],
    ];
    $one_live_html = ModelPageRenderer::render('Julieta Montesco', $one_live_payload);
    pr606_assert(str_contains($one_live_html, '<h2>Where to Watch Live</h2>'), 'One-live output should include the live-room section.');
    pr606_assert(str_contains($one_live_html, 'LiveJasmin'), 'One-live output should name the confirmed LiveJasmin/live-room evidence.');
    pr606_assert(str_contains($one_live_html, 'Latest check: 1 confirmed live profile found.'), 'One-live output should use the corrected latest-check wording.');
    foreach ($forbidden_sparse as $needle) {
        pr606_assert(!str_contains($one_live_html, $needle), 'One-live output must not include forbidden text: ' . $needle);
    }

    $zero_payload = [
        'link_evidence_summary' => [
            'live_count' => 0,
            'extra_count' => 0,
            'total_count' => 0,
            'has_live_profile' => false,
            'has_extra_links' => false,
            'has_any_links' => false,
        ],
        'intro_paragraphs' => ['No live-room profile is confirmed active in this check.'],
        'watch_section_paragraphs' => [],
        'official_links_section_paragraphs' => ['Latest check: no confirmed profile links found.'],
        'official_destinations_section_paragraphs' => ['personal sites are listed below.'],
        'community_destinations_section_paragraphs' => ['social profiles and link hubs are listed below.'],
    ];
    $zero_html = ModelPageRenderer::render('No Links Model', $zero_payload);
    pr606_assert(!str_contains($zero_html, 'confirmed live profile found'), 'Zero-link output should not claim a live profile exists.');
    pr606_assert(!str_contains($zero_html, 'Other Official Destinations'), 'Zero-link output should not render other-profile sections.');
    pr606_assert(!str_contains($zero_html, 'Social Profiles, Link Hubs, and Channels'), 'Zero-link output should not render social/link-hub sections.');
    pr606_assert(str_contains($zero_html, 'Latest check: no confirmed profile links found.'), 'Zero-link output should say no confirmed profile links were found.');

    $multi_payload = [
        'link_evidence_summary' => [
            'live_count' => 1,
            'extra_count' => 3,
            'total_count' => 4,
            'social_count' => 1,
            'link_hub_count' => 1,
            'tube_count' => 1,
            'personal_site_count' => 0,
            'fan_platform_count' => 0,
            'camsoda_count' => 0,
            'has_live_profile' => true,
            'has_extra_links' => true,
            'has_any_links' => true,
        ],
        'watch_section_paragraphs' => ['Open the confirmed LiveJasmin room first.'],
        'community_destinations_section_paragraphs' => ['video channels, social profiles, and link hubs are listed below for updates, archives, and handle checks.'],
        'official_destinations_section_paragraphs' => [],
        'official_links_section_paragraphs' => ['Latest check: 4 confirmed profile links found, including 1 live profile, 1 video channel, 1 social profile, and 1 link hub.'],
    ];
    $multi_html = ModelPageRenderer::render('Evidence Model', $multi_payload);
    pr606_assert(str_contains($multi_html, 'Social Profiles, Link Hubs, and Channels'), 'Community section should render when social/link-hub/video evidence exists.');
    pr606_assert(str_contains($multi_html, 'video channels'), 'Video channel evidence should be mentioned when present.');
    pr606_assert(str_contains($multi_html, 'social profiles'), 'Social evidence should be mentioned when present.');
    pr606_assert(str_contains($multi_html, 'link hubs'), 'Link-hub evidence should be mentioned when present.');
    pr606_assert(!str_contains($multi_html, 'CamSoda'), 'Missing CamSoda evidence should not be mentioned.');
    pr606_assert(!str_contains($multi_html, 'personal sites'), 'Missing personal-site evidence should not be mentioned.');
    pr606_assert(!str_contains($multi_html, 'fan/support pages'), 'Missing fan/support evidence should not be mentioned.');

    $method = new ReflectionMethod(TemplateContent::class, 'format_model_tags_for_body');
    $method->setAccessible(true);
    $explicit_tags = ['butt plugs', 'close up', 'dancing', 'dildo', 'fingering', 'love beads', 'oil', 'roleplay', 'striptease', 'vibrator', 'POV', 'foot fetish', 'snapshot', 'JOI'];
    $tag_text = (string) $method->invoke(null, $explicit_tags);
    pr606_assert(!str_contains($tag_text, implode(', ', $explicit_tags)), 'Explicit private-chat options should not be printed as one long raw list.');
    pr606_assert(str_contains($tag_text, 'private-chat themes'), 'Explicit options should be summarized into grouped wording.');
    $explicit_mentions = 0;
    foreach ($explicit_tags as $tag) {
        if (stripos($tag_text, $tag) !== false) {
            $explicit_mentions++;
        }
    }
    pr606_assert($explicit_mentions <= 3, 'Summarized explicit option text should show no more than three explicit examples.');

    pr606_assert($GLOBALS['pr606_db_writes'] === [], 'PR606 smoke should not perform database writes.');
    pr606_assert($GLOBALS['pr606_generate_executed'] === false, 'PR606 smoke should not execute Generate.');

    echo "✓ PR 606 model Template link evidence guard smoke checks passed\n";
}
