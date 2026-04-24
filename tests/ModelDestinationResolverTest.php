<?php

declare(strict_types=1);

namespace TMWSEO\Engine\Content;

use PHPUnit\Framework\TestCase;

require_once TMWSEO_ENGINE_PATH . 'includes/content/class-model-destination-resolver.php';
require_once TMWSEO_ENGINE_PATH . 'includes/content/class-model-page-renderer.php';
require_once TMWSEO_ENGINE_PATH . 'includes/content/class-template-content.php';
require_once TMWSEO_ENGINE_PATH . 'includes/templates/class-template-engine.php';

class ModelDestinationResolverTest extends TestCase {

    public function test_resolver_separates_watch_from_social_and_link_hubs(): void {
        $platform_links = [
            ['platform' => 'chaturbate', 'username' => 'alice', 'go_url' => 'https://example.test/go/chaturbate/alice', 'is_primary' => 1],
            ['platform' => 'fansly', 'username' => 'alicevip', 'go_url' => 'https://example.test/go/fansly/alicevip', 'is_primary' => 0],
        ];
        $verified = [
            ['type' => 'instagram', 'url' => 'https://instagram.com/alice', 'label' => 'Instagram', 'is_active' => true],
            ['type' => 'linktree', 'url' => 'https://linktr.ee/alice', 'label' => 'Linktree', 'is_active' => true],
            ['type' => 'personal_site', 'url' => 'https://alice.example', 'label' => 'Official Site', 'is_active' => true],
        ];

        $resolved = ModelDestinationResolver::resolve(55, $platform_links, $verified, ['summary' => '', 'platform_notes' => [], 'confirmed_facts' => []]);

        $this->assertCount(1, $resolved['watch_cta_destinations']);
        $watchPlatforms = array_map(static fn(array $r): string => (string)($r['platform'] ?? ''), $resolved['watch_cta_destinations']);
        $this->assertSame(['chaturbate'], $watchPlatforms);
        $this->assertSame(['Chaturbate'], $resolved['active_platform_labels']);
        $this->assertCount(1, $resolved['social_destinations']);
        $this->assertCount(1, $resolved['link_hub_destinations']);
        $this->assertCount(1, $resolved['personal_site_destinations']);
    }

    public function test_inactive_verified_links_survive_all_verified_destinations(): void {
        $resolved = ModelDestinationResolver::resolve(77, [], [
            ['type' => 'x', 'url' => 'https://x.com/alice', 'is_active' => true],
            ['type' => 'youtube', 'url' => 'https://youtube.com/@alice', 'is_active' => false],
        ], ['summary' => '', 'platform_notes' => [], 'confirmed_facts' => []]);

        $this->assertCount(2, $resolved['all_verified_destinations']);
        $this->assertSame('active', $resolved['all_verified_destinations'][0]['activity_level']);
        $this->assertSame('inactive', $resolved['all_verified_destinations'][1]['activity_level']);
        $this->assertSame(2, (int) ($resolved['source_of_truth_summary']['verified_count'] ?? 0));
    }

    public function test_inactive_verified_cam_rows_do_not_become_watch_ctas(): void {
        $resolved = ModelDestinationResolver::resolve(
            78,
            [['platform' => 'chaturbate', 'username' => 'alice', 'go_url' => 'https://example.test/go/chaturbate/alice', 'is_primary' => 1]],
            [['type' => 'chaturbate', 'url' => 'https://chaturbate.com/alice', 'is_active' => false]],
            ['summary' => '', 'platform_notes' => [], 'confirmed_facts' => []]
        );

        $this->assertCount(1, $resolved['all_verified_destinations']);
        $this->assertCount(0, $resolved['watch_cta_destinations']);
        $this->assertFalse((bool) ($resolved['all_verified_destinations'][0]['is_cta_eligible'] ?? true));
    }

    public function test_verified_active_cam_row_overrides_platform_profiles_watch_destination(): void {
        $resolved = ModelDestinationResolver::resolve(
            79,
            [['platform' => 'chaturbate', 'username' => 'alice', 'go_url' => 'https://example.test/go/chaturbate/alice', 'is_primary' => 1]],
            [['type' => 'chaturbate', 'url' => 'https://chaturbate.com/aliceofficial', 'is_active' => true, 'label' => 'Chaturbate Official']],
            ['summary' => '', 'platform_notes' => [], 'confirmed_facts' => []]
        );

        $this->assertCount(1, $resolved['watch_cta_destinations']);
        $this->assertSame('verified_links', $resolved['watch_cta_destinations'][0]['source'] ?? '');
    }

    public function test_social_and_link_hub_are_never_watch_ctas(): void {
        $resolved = ModelDestinationResolver::resolve(
            80,
            [],
            [
                ['type' => 'instagram', 'url' => 'https://instagram.com/alice', 'is_active' => true],
                ['type' => 'linktree', 'url' => 'https://linktr.ee/alice', 'is_active' => true],
            ],
            ['summary' => '', 'platform_notes' => [], 'confirmed_facts' => []]
        );
        $this->assertCount(0, $resolved['watch_cta_destinations']);
        $this->assertCount(2, $resolved['all_verified_destinations']);
    }

    public function test_extended_link_hub_types_map_to_link_hub_destinations_and_more_links_heading(): void {
        $post = new \WP_Post();
        $post->ID = 503;
        $resolved = ModelDestinationResolver::resolve(503, [], [
            ['type' => 'solo_to', 'url' => 'https://solo.to/alice', 'is_active' => true, 'label' => 'Solo.to'],
            ['type' => 'carrd', 'url' => 'https://alice.carrd.co', 'is_active' => true, 'label' => 'Carrd'],
            ['type' => 'link_me', 'url' => 'https://link.me/alice', 'is_active' => true, 'label' => 'Link.me'],
            ['type' => 'friendsbio', 'url' => 'https://friendsbio.com/alice', 'is_active' => true, 'label' => 'Friends Bio'],
        ], ['summary' => '', 'platform_notes' => [], 'confirmed_facts' => []]);

        $this->assertCount(4, $resolved['link_hub_destinations']);
        $this->assertCount(0, $resolved['social_destinations']);
        $this->assertCount(0, $resolved['watch_cta_destinations']);

        $payload = TemplateContent::build_model_renderer_support_payload($post, [
            'name' => 'Alice',
            'resolved_destinations' => $resolved,
            'cta_links' => [],
            'comparison_copy' => '',
        ]);

        $this->assertStringContainsString('<h3>More Links</h3>', (string) ($payload['external_info_html'] ?? ''));
    }

    public function test_fansites_are_never_watch_ctas_even_when_marked_active(): void {
        $resolved = ModelDestinationResolver::resolve(
            82,
            [],
            [
                ['type' => 'fansly', 'url' => 'https://fansly.com/alice', 'is_active' => true],
            ],
            ['summary' => '', 'platform_notes' => [], 'confirmed_facts' => []]
        );

        $this->assertCount(1, $resolved['fan_platform_destinations']);
        $this->assertCount(0, $resolved['watch_cta_destinations']);
        $this->assertFalse((bool) ($resolved['fan_platform_destinations'][0]['is_cta_eligible'] ?? true));
    }

    public function test_template_payload_splits_non_live_and_social_destinations(): void {
        $post = new \WP_Post();
        $post->ID = 500;
        $resolved = ModelDestinationResolver::resolve(500, [], [
            ['type' => 'chaturbate', 'url' => 'https://chaturbate.com/alice', 'is_active' => false, 'label' => 'Chaturbate'],
            ['type' => 'fansly', 'url' => 'https://fansly.com/alice', 'is_active' => true, 'label' => 'Fansly'],
            ['type' => 'personal_site', 'url' => 'https://alice.example', 'is_active' => true, 'label' => 'Alice Site'],
            ['type' => 'instagram', 'url' => 'https://instagram.com/alice', 'is_active' => true, 'label' => 'Instagram'],
            ['type' => 'linktree', 'url' => 'https://linktr.ee/alice', 'is_active' => true, 'label' => 'Linktree'],
            ['type' => 'youtube', 'url' => 'https://youtube.com/@alice', 'is_active' => true, 'label' => 'YouTube'],
        ], ['summary' => '', 'platform_notes' => [], 'confirmed_facts' => []]);

        $payload = TemplateContent::build_model_renderer_support_payload($post, [
            'name' => 'Alice',
            'resolved_destinations' => $resolved,
            'cta_links' => [],
            'comparison_copy' => '',
        ]);

        $this->assertStringContainsString('Visit Profile on Chaturbate', (string) ($payload['official_destinations_section_html'] ?? ''));
        $this->assertStringContainsString('Visit Fan Page on Fansly', (string) ($payload['official_destinations_section_html'] ?? ''));
        $this->assertStringContainsString('Visit Official Site on Alice Site', (string) ($payload['official_destinations_section_html'] ?? ''));
        $this->assertStringContainsString('Follow on Instagram', (string) ($payload['community_destinations_section_html'] ?? ''));
        $this->assertStringContainsString('Open Link Hub on Linktree', (string) ($payload['community_destinations_section_html'] ?? ''));
        $this->assertStringContainsString('Visit Channel on YouTube', (string) ($payload['community_destinations_section_html'] ?? ''));
    }

    public function test_comparison_html_uses_only_live_cam_destinations(): void {
        $post = new \WP_Post();
        $post->ID = 501;
        $resolved = ModelDestinationResolver::resolve(501, [], [
            ['type' => 'chaturbate', 'url' => 'https://chaturbate.com/alice', 'is_active' => true, 'label' => 'Chaturbate'],
            ['type' => 'fansly', 'url' => 'https://fansly.com/alice', 'is_active' => true, 'label' => 'Fansly'],
        ], ['summary' => '', 'platform_notes' => [], 'confirmed_facts' => []]);

        $payload = TemplateContent::build_model_renderer_support_payload($post, [
            'name' => 'Alice',
            'resolved_destinations' => $resolved,
            'cta_links' => (array) ($resolved['watch_cta_destinations'] ?? []),
            'comparison_copy' => 'Comparison seed',
        ]);

        $this->assertStringContainsString('Chaturbate', (string) ($payload['comparison_section_html'] ?? ''));
        $this->assertStringNotContainsString('Fansly', (string) ($payload['comparison_section_html'] ?? ''));
        $this->assertStringNotContainsString('Watch Live on Fansly', (string) ($payload['comparison_section_html'] ?? ''));
    }

    public function test_resolver_maps_legacy_activity_to_is_active_state(): void {
        $resolved = ModelDestinationResolver::resolve(81, [], [
            ['type' => 'x', 'url' => 'https://x.com/alice', 'is_active' => true],
            ['type' => 'youtube', 'url' => 'https://youtube.com/@alice', 'is_active' => false],
        ], ['summary' => '', 'platform_notes' => [], 'confirmed_facts' => []]);

        $this->assertSame('active', $resolved['all_verified_destinations'][0]['activity_level']);
        $this->assertSame('inactive', $resolved['all_verified_destinations'][1]['activity_level']);
    }

    public function test_sparse_data_fallback_still_available(): void {
        $payload = TemplateContent::build_sparse_model_payload('Alice', [], ['reason' => 'insufficient_performer_data']);
        $this->assertNotEmpty($payload['intro_paragraphs']);
        $this->assertSame('insufficient_performer_data', $payload['model_data_notice']);
    }

    public function test_single_active_platform_uses_before_you_click_heading(): void {
        $html = ModelPageRenderer::render('Alice', [
            'active_platforms' => ['Chaturbate'],
            'comparison_section_paragraphs' => ['Run checks before joining.'],
        ]);

        $this->assertStringContainsString('<h2>Before You Click</h2>', $html);
        $this->assertStringNotContainsString('<h2>Live Platform Comparison</h2>', $html);
    }

    public function test_multi_active_platform_keeps_comparison_heading(): void {
        $html = ModelPageRenderer::render('Alice', [
            'active_platforms' => ['Chaturbate', 'Stripchat'],
            'comparison_section_paragraphs' => ['Compare both active rooms.'],
        ]);

        $this->assertStringContainsString('<h2>Live Platform Comparison</h2>', $html);
    }

    public function test_structurally_rich_sparse_payload_uses_practical_faq_items(): void {
        $payload = TemplateContent::build_sparse_model_payload('Alice', ['Chaturbate'], [
            'reason' => 'insufficient_performer_data',
            'signals' => [
                'platform_links' => 1,
                'active_platforms' => 1,
                'tags' => 1,
            ],
        ]);

        $faq_questions = array_map(static fn(array $item): string => (string) ($item['q'] ?? ''), (array) ($payload['faq_items'] ?? []));
        $this->assertContains('Which link should I open first?', $faq_questions);
        $this->assertNotContains('Why is this page short right now?', $faq_questions);
        $this->assertNotContains('What is already verified on this page?', $faq_questions);
    }

    public function test_truly_sparse_payload_keeps_diagnostic_faq_fallback(): void {
        $payload = TemplateContent::build_sparse_model_payload('Alice', [], [
            'reason' => 'insufficient_performer_data',
            'signals' => [
                'platform_links' => 0,
                'active_platforms' => 0,
                'tags' => 0,
            ],
        ]);

        $faq_questions = array_map(static fn(array $item): string => (string) ($item['q'] ?? ''), (array) ($payload['faq_items'] ?? []));
        $this->assertContains('Why is this page short right now?', $faq_questions);
        $this->assertContains('What is already verified on this page?', $faq_questions);
    }

    public function test_sparse_payload_weaves_secondary_keywords_into_visible_sections(): void {
        $payload = TemplateContent::build_sparse_model_payload(
            'Alice',
            ['Chaturbate'],
            ['reason' => 'insufficient_performer_data'],
            ['alice private live chat', 'alice verified profile links', 'alice live stream schedule'],
            ['fallback phrase']
        );

        $this->assertStringContainsString('alice private live chat', (string) ($payload['intro_paragraphs'][1] ?? ''));
        $this->assertStringContainsString('alice verified profile links', (string) ($payload['features_section_paragraphs'][0] ?? ''));
        $faq_answers = array_map(static fn(array $item): string => (string) ($item['a'] ?? ''), (array) ($payload['faq_items'] ?? []));
        $this->assertStringContainsString('alice live stream schedule', implode(' ', $faq_answers));
    }

    public function test_support_payload_adds_secondary_keyword_to_verification_copy(): void {
        $post = new \WP_Post();
        $post->ID = 94;
        $post->post_title = 'Alice';
        $post->post_type = 'model';

        $payload = TemplateContent::build_model_renderer_support_payload($post, [
            'name' => 'Alice',
            'resolved_destinations' => [
                'watch_cta_destinations' => [],
                'active_platform_labels' => [],
                'source_of_truth_summary' => [],
            ],
            'rankmath_additional' => ['alice verified profile links', 'alice private live chat', 'alice stream updates'],
        ]);

        $paragraphs = (array) ($payload['official_links_section_paragraphs'] ?? []);
        $this->assertNotEmpty($paragraphs);
        $this->assertStringContainsString('alice stream updates', implode(' ', $paragraphs));
    }

    public function test_single_platform_intro_wording_drops_old_fallback_pattern(): void {
        $method = new \ReflectionMethod(TemplateContent::class, 'build_seed_intro_paragraphs');
        $method->setAccessible(true);

        /** @var array<int,string> $lines */
        $lines = $method->invoke(null, 'Alice', [], ['Chaturbate'], 'Fallback intro', 'Fallback second');
        $intro = implode(' ', $lines);

        $this->assertStringContainsString('In this review pass, Chaturbate was the only live-room destination confirmed active.', $intro);
        $this->assertStringNotContainsString('one live-room destination is currently confirmed active:', $intro);
        $this->assertStringNotContainsString('is active on Chaturbate', $intro);
        $this->assertStringNotContainsString('has active profiles on', $intro);
    }

    public function test_verification_notes_use_review_scoped_active_language(): void {
        $method = new \ReflectionMethod(TemplateContent::class, 'build_verification_process_paragraph');
        $method->setAccessible(true);

        $line = $method->invoke(null, [
            'source_of_truth_summary' => [
                'verified_count' => 4,
                'watch_cta_count' => 1,
            ],
        ]);

        $this->assertStringContainsString('1 live-room destination confirmed active in the latest review pass', (string) $line);
        $this->assertStringNotContainsString('currently confirmed active for live-room routing in this review', (string) $line);
    }

    public function test_single_platform_checklist_intro_uses_review_pass_wording(): void {
        $method = new \ReflectionMethod(TemplateContent::class, 'build_platform_comparison');
        $method->setAccessible(true);

        $post = new \WP_Post();
        $post->ID = 811;

        $html = (string) $method->invoke(
            null,
            $post,
            'Alice',
            [
                [
                    'label' => 'LiveJasmin',
                    'go_url' => 'https://www.livejasmin.com/en/chat/Alice',
                    'platform' => 'livejasmin',
                    'username' => 'alice',
                ],
            ],
            '',
            []
        );

        $this->assertStringContainsString('This review pass found one confirmed active live-room destination (LiveJasmin)', $html);
        $this->assertStringNotContainsString('Only one live-room destination is currently confirmed active in this review', $html);
    }

    public function test_support_payload_includes_truthful_guidance_sections(): void {
        $post = new \WP_Post();
        $post->ID = 502;
        $resolved = ModelDestinationResolver::resolve(502, [], [
            ['type' => 'chaturbate', 'url' => 'https://chaturbate.com/alice', 'is_active' => false, 'label' => 'Chaturbate'],
            ['type' => 'fansly', 'url' => 'https://fansly.com/alice', 'is_active' => true, 'label' => 'Fansly'],
            ['type' => 'instagram', 'url' => 'https://instagram.com/alice', 'is_active' => true, 'label' => 'Instagram'],
        ], ['summary' => '', 'platform_notes' => [], 'confirmed_facts' => []]);

        $payload = TemplateContent::build_model_renderer_support_payload($post, [
            'name' => 'Alice',
            'resolved_destinations' => $resolved,
            'cta_links' => [],
            'comparison_copy' => '',
        ]);

        $official_lines = implode(' ', (array) ($payload['official_destinations_section_paragraphs'] ?? []));
        $community_lines = implode(' ', (array) ($payload['community_destinations_section_paragraphs'] ?? []));
        $links_lines = implode(' ', (array) ($payload['official_links_section_paragraphs'] ?? []));

        $this->assertStringContainsString('not currently treated as active live-room links', $official_lines);
        $this->assertStringContainsString('not presented as direct live-room shortcuts', $community_lines);
        $this->assertStringContainsString('Verification notes:', $links_lines);
    }

    public function test_depth_guardrail_expands_short_content_with_practical_sections(): void {
        $method = new \ReflectionMethod(TemplateContent::class, 'ensure_minimum_useful_depth');
        $method->setAccessible(true);

        $short_html = '<p>Short page body.</p>';
        $expanded = (string) $method->invoke(null, $short_html, 'Alice', ['Chaturbate', 'Stripchat'], [], 'Chaturbate', 'seed-1');
        $plain = trim(strip_tags($expanded));
        $word_count = str_word_count($plain);

        $this->assertGreaterThanOrEqual(620, $word_count);
        $this->assertStringContainsString('How to Decide Where to Start', $expanded);
        $this->assertStringContainsString('Verification and Review Method', $expanded);
    }

    public function test_varied_features_are_utility_focused_not_boilerplate_hype(): void {
        $method = new \ReflectionMethod(TemplateContent::class, 'render_varied_features');
        $method->setAccessible(true);
        $html = (string) $method->invoke(null, 'Alice', ['chatty'], 'Chaturbate', 'seed-utility');

        $this->assertStringContainsString('Truth-first routing', $html);
        $this->assertStringNotContainsString('Respectful community', $html);
        $this->assertStringNotContainsString('HD video quality', $html);
    }

    public function test_renderer_adds_focus_keyword_to_meaningful_subheading(): void {
        $html = ModelPageRenderer::render('Alice', [
            'focus_keyword' => 'Alice',
            'intro_paragraphs' => ['Intro line'],
            'features_section_paragraphs' => ['Feature details'],
            'official_links_section_paragraphs' => ['Links summary'],
        ]);

        $this->assertStringContainsString('<h2>Features and Platform Experience for Alice</h2>', $html);
    }

    public function test_depth_guardrail_target_is_raised_for_rank_math_alignment(): void {
        $method = new \ReflectionMethod(TemplateContent::class, 'ensure_minimum_useful_depth');
        $method->setAccessible(true);

        $short_html = '<p>Short page body.</p>';
        $expanded = (string) $method->invoke(null, $short_html, 'Alice', ['Chaturbate', 'Stripchat'], [], 'Chaturbate', 'seed-2');
        $plain = trim(strip_tags($expanded));
        $word_count = str_word_count($plain);

        $this->assertGreaterThanOrEqual(640, $word_count);
        $this->assertStringContainsString('How to Use Backup Destinations Safely', $expanded);
    }

    public function test_faq_name_mentions_are_limited_to_reduce_density_spikes(): void {
        $method = new \ReflectionMethod(TemplateContent::class, 'build_seed_faq_items');
        $method->setAccessible(true);

        $items = (array) $method->invoke(null, [], [[
            'q' => 'How do I find Alice quickly?',
            'a' => 'Alice is easiest to find when Alice uses verified links and Alice keeps one handle.',
        ]], 'Alice');

        $answer = (string) ($items[0]['a'] ?? '');
        $this->assertStringContainsString('Alice is easiest to find', $answer);
        $this->assertStringContainsString('this performer uses verified links', $answer);
        $this->assertSame(1, substr_count($answer, 'Alice'));
    }
}
