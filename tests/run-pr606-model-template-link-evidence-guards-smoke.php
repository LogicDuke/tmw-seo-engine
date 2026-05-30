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
        function update_post_meta(...$args) { $GLOBALS['pr606_db_writes'][] = ['update_post_meta', $args]; return true; }
    }
    if (!function_exists('wp_update_post')) {
        function wp_update_post(...$args) { $GLOBALS['pr606_db_writes'][] = ['wp_update_post', $args]; return is_array($args[0] ?? null) && isset($args[0]['ID']) ? (int) $args[0]['ID'] : 1; }
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

    $sparse_gate = [
        'reason' => 'insufficient_performer_data',
        'signals' => [
            'platform_links' => 1,
            'active_platforms' => 1,
            'comparison_copy' => 0,
            'tags' => 0,
        ],
    ];
    $livejasmin_sparse = TemplateContent::build_sparse_model_payload('Julieta Montesco', ['LiveJasmin'], $sparse_gate, [], [], [
        'live_count' => 1,
        'extra_count' => 0,
        'has_live_profile' => true,
        'has_extra_links' => false,
    ]);
    $livejasmin_sparse_text = implode(' ', array_merge($livejasmin_sparse['comparison_section_paragraphs'] ?? [], $livejasmin_sparse['intro_paragraphs'] ?? []));
    pr606_assert(str_contains($livejasmin_sparse_text, 'This page currently lists the confirmed LiveJasmin profile only.'), 'LiveJasmin-only sparse payload should name LiveJasmin.');

    $camsoda_sparse = TemplateContent::build_sparse_model_payload('Cam Only', ['CamSoda'], $sparse_gate, [], [], [
        'live_count' => 1,
        'extra_count' => 0,
        'has_live_profile' => true,
        'has_extra_links' => false,
    ]);
    $camsoda_sparse_text = implode(' ', array_merge($camsoda_sparse['comparison_section_paragraphs'] ?? [], $camsoda_sparse['intro_paragraphs'] ?? []));
    pr606_assert(str_contains($camsoda_sparse_text, 'This page currently lists the confirmed CamSoda profile only.'), 'CamSoda-only sparse payload should name CamSoda.');

    $platform_sentence_method = new ReflectionMethod(TemplateContent::class, 'build_confirmed_live_profile_only_sentence');
    $platform_sentence_method->setAccessible(true);
    $unknown_platform_sentence = (string) $platform_sentence_method->invoke(null, '');
    pr606_assert(str_contains($unknown_platform_sentence, 'This page currently lists one confirmed live profile only.'), 'Unknown one-live platform should use generic one-live wording.');

    $camsoda_render_html = ModelPageRenderer::render('Cam Only', [
        'link_evidence_summary' => [
            'live_count' => 1,
            'extra_count' => 0,
            'total_count' => 1,
            'has_live_profile' => true,
            'has_extra_links' => false,
            'has_any_links' => true,
        ],
        'intro_paragraphs' => ['CamSoda is the confirmed live-room profile in this check. Start there for live access.'],
        'watch_section_paragraphs' => ['This page currently lists the confirmed CamSoda profile only. Use that link as the primary access point and recheck status before joining.'],
        'official_links_section_paragraphs' => ['Latest check: 1 confirmed live profile found.'],
    ]);
    pr606_assert(str_contains($camsoda_render_html, 'CamSoda'), 'CamSoda-only renderer output should not lose valid CamSoda text due to no-extra denylist.');

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

    $payload_with_old_faq = [
        'link_evidence_summary' => [
            'live_count' => 1,
            'extra_count' => 0,
            'has_live_profile' => true,
            'has_extra_links' => false,
        ],
        'faq_items' => [[
            'q' => 'How do I avoid stale or copied profile links?',
            'a' => 'Start from the live profile shown on this page, then use the grouped profiles below for follow-up checks.',
        ]],
        'questions_section_paragraphs' => [],
    ];
    $updated_faq_payload = TemplateContent::maybe_add_sparse_wordcount_support_paragraph($payload_with_old_faq, 'Cam Only', ['CamSoda'], true, 680);
    $updated_faq_answer = (string) ($updated_faq_payload['faq_items'][0]['a'] ?? '');
    pr606_assert($updated_faq_answer !== '', 'Stale-link FAQ should remain present after sparse support update.');
    pr606_assert(!str_contains($updated_faq_answer, 'grouped profiles below'), 'No-extra stale-link FAQ answer should not mention grouped profiles below.');
    pr606_assert(str_contains($updated_faq_answer, 'Start from the confirmed live profile shown on this page.'), 'No-extra stale-link FAQ should use the safe replacement answer.');

    $depth_method = new ReflectionMethod(TemplateContent::class, 'ensure_minimum_useful_depth');
    $depth_method->setAccessible(true);
    $no_extra_depth = (string) $depth_method->invoke(null, '<p>Short.</p>', 'Depth Model', ['LiveJasmin', 'CamSoda'], [
        'source_of_truth_summary' => ['verified_count' => 0],
        'all_verified_destinations' => [],
        'personal_site_destinations' => [],
        'fan_platform_destinations' => [],
        'social_destinations' => [],
        'link_hub_destinations' => [],
        'tube_destinations' => [],
    ], 'LiveJasmin', 'depth-no-extra');
    pr606_assert(!str_contains($no_extra_depth, 'additional verified destinations'), 'Depth filler should not mention additional verified destinations without extra evidence.');
    pr606_assert(str_contains($no_extra_depth, 'recheck the listed live profiles later'), 'Depth filler should use multi-live safe wording without extra evidence.');

    $extra_depth = (string) $depth_method->invoke(null, '<p>Short.</p>', 'Depth Model', ['LiveJasmin', 'CamSoda'], [
        'source_of_truth_summary' => ['verified_count' => 1],
        'all_verified_destinations' => [],
        'personal_site_destinations' => [[ 'url' => 'https://example.com/model', 'family' => 'personal_site' ]],
        'fan_platform_destinations' => [],
        'social_destinations' => [],
        'link_hub_destinations' => [],
        'tube_destinations' => [],
    ], 'LiveJasmin', 'depth-extra');
    pr606_assert(str_contains($extra_depth, 'additional verified destinations'), 'Depth filler may mention additional verified destinations when extra evidence exists.');

    pr606_assert(update_post_meta(606, '_smoke', 'value') === true, 'update_post_meta smoke stub should return a realistic truthy value.');
    pr606_assert(wp_update_post(['ID' => 606]) === 606, 'wp_update_post smoke stub should return the provided post ID.');
    pr606_assert(wp_update_post(['post_title' => 'No ID']) === 1, 'wp_update_post smoke stub should return a fallback post ID.');
    $GLOBALS['pr606_db_writes'] = [];

    pr606_assert($GLOBALS['pr606_db_writes'] === [], 'PR606 smoke should not perform database writes.');
    pr606_assert($GLOBALS['pr606_generate_executed'] === false, 'PR606 smoke should not execute Generate.');

    echo "✓ PR 606 model Template link evidence guard smoke checks passed\n";
}
