<?php
/**
 * CrakRevenue cam routing tests.
 *
 * @package TMWSEO\Engine\Tests
 */
declare(strict_types=1);

namespace TMWSEO\Engine\Tests;

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Affiliates\CrakRevenueCamManager;
use TMWSEO\Engine\Model\VerifiedLinks;
use TMWSEO\Engine\Platform\AffiliateLinkBuilder;

/**
 * Validate CrakRevenue API sync helpers and cam routing behavior.
 */
class CrakRevenueCamRoutingTest extends TestCase {

    /**
     * Reset options before each test.
     *
     * @return void
     */
    protected function setUp(): void {
        delete_option(CrakRevenueCamManager::API_SETTINGS_OPTION);
        delete_option(CrakRevenueCamManager::OFFERS_CACHE_OPTION);
        delete_option(CrakRevenueCamManager::PLATFORM_MAPPINGS_OPTION);
        delete_option('tmwseo_affiliate_networks');
        delete_option('tmwseo_platform_affiliate_settings');
    }

    /**
     * Ensure request URL contains required target and method.
     *
     * @return void
     */
    public function test_api_request_url_contains_target_and_method(): void {
        $url = CrakRevenueCamManager::build_offers_request_url('abc123');
        $this->assertStringContainsString('Target=Affiliate_Offer', $url);
        $this->assertStringContainsString('Method=findAll', $url);
        $this->assertStringContainsString('api_key=abc123', $url);
    }

    /**
     * Ensure API key sanitization works.
     *
     * @return void
     */
    public function test_api_key_sanitized(): void {
        $san = CrakRevenueCamManager::sanitize_api_settings(['api_key' => " bad<script> "]);
        $this->assertSame('bad', $san['api_key']);
    }

    /**
     * Validate platform detection aliases.
     *
     * @return void
     */
    public function test_platform_detection_examples(): void {
        $this->assertSame('camsoda', CrakRevenueCamManager::detect_platform_slug('Camsoda - Revshare Lifetime'));
        $this->assertSame('livejasmin', CrakRevenueCamManager::detect_platform_slug('Live Jasmin - Revshare'));
        $this->assertSame('sinparty', CrakRevenueCamManager::detect_platform_slug('SinParty - Revshare Lifetime'));
        $this->assertSame('royal_cams_gay', CrakRevenueCamManager::detect_platform_slug('Royal Cams Gay - Revshare Lifetime'));
    }

    /**
     * Required offers remain non-eligible by default.
     *
     * @return void
     */
    public function test_required_offer_not_frontend_eligible_by_default(): void {
        update_option(CrakRevenueCamManager::PLATFORM_MAPPINGS_OPTION, [
            'camsoda' => [
                'enabled' => 1,
                'selected_offer_id' => 99,
                'approval_status' => 'needs_approval',
                'manually_approved' => 0,
                'template_url' => 'https://go.example.com/?model={username}&url={encoded_profile_url}',
            ],
        ]);

        $url = 'https://www.camsoda.com/example';
        $this->assertSame($url, CrakRevenueCamManager::maybe_route_verified_link([
            'url' => $url,
            'type' => 'camsoda',
            'is_active' => 1,
            'activity_level' => 'active',
        ]));
    }

    /**
     * Required offers can route only after manual override approval.
     *
     * @return void
     */
    public function test_required_offer_can_route_when_manually_approved(): void {
        update_option(CrakRevenueCamManager::PLATFORM_MAPPINGS_OPTION, [
            'camsoda' => [
                'enabled' => 1,
                'selected_offer_id' => 99,
                'approval_status' => 'needs_approval',
                'manually_approved' => 1,
                'template_url' => 'https://go.example.com/?url={encoded_profile_url}',
            ],
        ]);
        $url = 'https://www.camsoda.com/example';
        $routed = CrakRevenueCamManager::maybe_route_verified_link(['url' => $url, 'type' => 'camsoda']);
        $this->assertStringContainsString(rawurlencode($url), $routed);
    }

    /**
     * Higher priority picks approved revshare lifetime before PPS.
     *
     * @return void
     */
    public function test_auto_map_prefers_revshare_lifetime_before_pps(): void {
        update_option(CrakRevenueCamManager::OFFERS_CACHE_OPTION, [
            'offers' => [
                ['offer_id' => 1, 'platform_slug' => 'camsoda', 'offer_name' => 'Camsoda - PPS', 'approval_status' => 'approved', 'default_payout' => 20, 'epc' => 1.0, 'is_expired' => 0],
                ['offer_id' => 2, 'platform_slug' => 'camsoda', 'offer_name' => 'Camsoda - Revshare Lifetime', 'approval_status' => 'approved', 'default_payout' => 5, 'epc' => 0.2, 'is_expired' => 0],
            ],
        ]);

        CrakRevenueCamManager::auto_map_best_offers();
        $map = get_option(CrakRevenueCamManager::PLATFORM_MAPPINGS_OPTION, []);
        $this->assertSame(2, (int)($map['camsoda']['selected_offer_id'] ?? 0));
    }

    /**
     * Expired offers are not selected as best candidates.
     *
     * @return void
     */
    public function test_expired_offer_not_selected(): void {
        update_option(CrakRevenueCamManager::OFFERS_CACHE_OPTION, [
            'offers' => [
                ['offer_id' => 1, 'platform_slug' => 'camsoda', 'offer_name' => 'Camsoda - Revshare Lifetime', 'approval_status' => 'approved', 'default_payout' => 20, 'epc' => 1.0, 'is_expired' => 1],
                ['offer_id' => 2, 'platform_slug' => 'camsoda', 'offer_name' => 'Camsoda - PPS', 'approval_status' => 'approved', 'default_payout' => 1, 'epc' => 0.1, 'is_expired' => 0],
            ],
        ]);
        CrakRevenueCamManager::auto_map_best_offers();
        $map = get_option(CrakRevenueCamManager::PLATFORM_MAPPINGS_OPTION, []);
        $this->assertSame(2, (int)($map['camsoda']['selected_offer_id'] ?? 0));
    }

    /**
     * EPC breaks ties.
     *
     * @return void
     */
    public function test_higher_epc_breaks_ties(): void {
        $a = ['offer_name' => 'Camsoda - Revshare', 'approval_status' => 'approved', 'default_payout' => 10, 'epc' => 1.0, 'is_expired' => 0, 'platform_slug' => 'camsoda'];
        $b = ['offer_name' => 'Camsoda - Revshare', 'approval_status' => 'approved', 'default_payout' => 10, 'epc' => 0.2, 'is_expired' => 0, 'platform_slug' => 'camsoda'];
        $this->assertGreaterThan(CrakRevenueCamManager::offer_score($b), CrakRevenueCamManager::offer_score($a));
    }

    /**
     * Smartlink fallback scores lower.
     *
     * @return void
     */
    public function test_cam_smartlink_is_fallback_only(): void {
        $direct = ['offer_name' => 'Camsoda - Revshare', 'approval_status' => 'approved', 'default_payout' => 10, 'epc' => 1.0, 'is_expired' => 0, 'platform_slug' => 'camsoda'];
        $smart = ['offer_name' => 'Cam Smartlink - Revshare', 'approval_status' => 'approved', 'default_payout' => 10, 'epc' => 1.0, 'is_expired' => 0, 'platform_slug' => 'cam_smartlink'];
        $this->assertGreaterThan(CrakRevenueCamManager::offer_score($smart), CrakRevenueCamManager::offer_score($direct));
    }

    /**
     * Existing manual selection is not overwritten by automap.
     *
     * @return void
     */
    public function test_auto_map_does_not_overwrite_manual_by_default(): void {
        update_option(CrakRevenueCamManager::OFFERS_CACHE_OPTION, [
            'offers' => [
                ['offer_id' => 11, 'platform_slug' => 'camsoda', 'offer_name' => 'Camsoda - Revshare Lifetime', 'approval_status' => 'approved', 'default_payout' => 5, 'epc' => 0.2, 'is_expired' => 0],
            ],
        ]);
        update_option(CrakRevenueCamManager::PLATFORM_MAPPINGS_OPTION, [
            'camsoda' => ['selected_offer_id' => 999, 'selected_offer_name' => 'Manual'],
        ]);

        CrakRevenueCamManager::auto_map_best_offers(false);
        $map = get_option(CrakRevenueCamManager::PLATFORM_MAPPINGS_OPTION, []);
        $this->assertSame(999, (int)$map['camsoda']['selected_offer_id']);
    }

    /**
     * Hardcoded performer/model values are unsafe while placeholders are safe.
     *
     * @return void
     */
    public function test_template_safety_validation(): void {
        $bad1 = CrakRevenueCamManager::validate_template('https://go.example.com/?performerName=AishaDupont');
        $bad2 = CrakRevenueCamManager::validate_template('https://go.example.com/?model=AishaDupont');
        $ok1 = CrakRevenueCamManager::validate_template('https://go.example.com/?performerName={username}');
        $ok2 = CrakRevenueCamManager::validate_template('https://go.example.com/?model={username}');

        $this->assertFalse($bad1['safe']);
        $this->assertFalse($bad2['safe']);
        $this->assertTrue($ok1['safe']);
        $this->assertTrue($ok2['safe']);
    }

    /**
     * Username extraction supports common cam URLs.
     *
     * @return void
     */
    public function test_username_extraction_examples(): void {
        $this->assertSame('ohhaisha', CrakRevenueCamManager::extract_username_from_profile_url('https://sinparty.com/ohhaisha', 'sinparty'));
        $this->assertSame('example', CrakRevenueCamManager::extract_username_from_profile_url('https://chaturbate.com/example/', 'chaturbate'));
    }

    /**
     * Unknown username with username-required template falls back.
     *
     * @return void
     */
    public function test_unknown_username_falls_back_when_required(): void {
        update_option(CrakRevenueCamManager::PLATFORM_MAPPINGS_OPTION, [
            'camsoda' => [
                'enabled' => 1,
                'selected_offer_id' => 77,
                'approval_status' => 'approved',
                'template_url' => 'https://go.example.com/?model={username}',
            ],
        ]);

        $url = 'https://www.camsoda.com/';
        $this->assertSame($url, CrakRevenueCamManager::maybe_route_verified_link(['url' => $url, 'type' => 'camsoda']));
    }

    /**
     * encoded_profile_url template can route without username.
     *
     * @return void
     */
    public function test_encoded_profile_url_routes_without_username(): void {
        update_option(CrakRevenueCamManager::PLATFORM_MAPPINGS_OPTION, [
            'camsoda' => [
                'enabled' => 1,
                'selected_offer_id' => 77,
                'approval_status' => 'approved',
                'template_url' => 'https://go.example.com/?url={encoded_profile_url}',
            ],
        ]);
        $url = 'https://www.camsoda.com/';
        $routed = CrakRevenueCamManager::maybe_route_verified_link(['url' => $url, 'type' => 'camsoda']);
        $this->assertStringContainsString(rawurlencode($url), $routed);
    }

    /**
     * sameAs stays real URL and network-level routing remains operational.
     *
     * @return void
     */
    public function test_existing_routing_and_schema_clean(): void {
        update_option('tmwseo_affiliate_networks', [
            'crack_revenue' => ['enabled' => 1, 'template' => 'https://go.example.com/?url={encoded_profile_url}'],
        ]);

        $target = 'https://fansly.com/profile/posts';
        $routed = AffiliateLinkBuilder::build_affiliate_url_for_target($target, 'crack_revenue');
        $this->assertStringContainsString('go.example.com', $routed);

        $schema = VerifiedLinks::get_schema_urls(123);
        $this->assertIsArray($schema);
    }
}
