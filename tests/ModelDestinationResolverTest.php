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
}
