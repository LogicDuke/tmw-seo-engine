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

        $this->assertCount(2, $resolved['watch_cta_destinations']);
        $watchPlatforms = array_map(static fn(array $r): string => (string)($r['platform'] ?? ''), $resolved['watch_cta_destinations']);
        $this->assertSame(['chaturbate', 'fansly'], $watchPlatforms);
        $this->assertSame(['Chaturbate', 'Fansly'], $resolved['active_platform_labels']);
        $this->assertCount(1, $resolved['social_destinations']);
        $this->assertCount(1, $resolved['link_hub_destinations']);
        $this->assertCount(1, $resolved['personal_site_destinations']);
    }

    public function test_resolver_maps_legacy_activity_to_is_active_state(): void {
        $resolved = ModelDestinationResolver::resolve(77, [], [
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
