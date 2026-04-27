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
        CrakRevenueCamManager::set_http_getter(null);
    }

    /**
     * Ensure request URL contains required target/method/fields format.
     *
     * @return void
     */
    public function test_api_request_url_contains_target_method_and_repeated_fields(): void {
        $url = CrakRevenueCamManager::build_offers_request_url('abc123');
        $this->assertStringContainsString('Target=Affiliate_Offer', $url);
        $this->assertStringContainsString('Method=findAll', $url);
        $this->assertStringContainsString('api_key=abc123', $url);
        $this->assertStringContainsString('fields%5B%5D=id', $url);
        $this->assertStringNotContainsString('fields=id%2Cname', $url);
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
     * Ensure API key redaction removes secrets.
     *
     * @return void
     */
    public function test_api_key_redacted_in_diagnostics(): void {
        $text = CrakRevenueCamManager::redact_api_key_from_text('https://x.test?a=1&api_key=super-secret&b=2');
        $this->assertStringContainsString('api_key=[redacted]', $text);
        $this->assertStringNotContainsString('super-secret', $text);
    }

    /**
     * Verify HTTPS endpoint is attempted before HTTP fallback.
     *
     * @return void
     */
    public function test_https_endpoint_is_tried_first_then_http_fallback(): void {
        update_option(CrakRevenueCamManager::API_SETTINGS_OPTION, ['api_key' => 'abc123']);

        $calls = [];
        CrakRevenueCamManager::set_http_getter(static function (string $url) use (&$calls) {
            $calls[] = $url;
            if (str_starts_with($url, 'https://')) {
                return new \WP_Error('ssl', 'SSL failed');
            }
            return [
                'response' => ['code' => 200],
                'headers' => ['content-type' => 'application/json'],
                'body' => '[{"id":11,"name":"Camsoda - Revshare Lifetime"}]',
            ];
        });

        $result = CrakRevenueCamManager::sync_offers();
        $this->assertTrue($result['ok']);
        $this->assertStringStartsWith('https://gateway.crakrevenue.com/affiliate', $calls[0]);
        $this->assertStringStartsWith('http://gateway.crakrevenue.com/affiliate', $calls[1]);
    }

    /**
     * Verify non-200 response records status/message diagnostics.
     *
     * @return void
     */
    public function test_non_200_response_stores_status_and_message(): void {
        update_option(CrakRevenueCamManager::API_SETTINGS_OPTION, ['api_key' => 'abc123']);
        CrakRevenueCamManager::set_http_getter(static function (): array {
            return [
                'response' => ['code' => 500],
                'headers' => ['content-type' => 'application/json'],
                'body' => '{"error":"server"}',
            ];
        });
        $result = CrakRevenueCamManager::sync_offers();
        $settings = get_option(CrakRevenueCamManager::API_SETTINGS_OPTION, []);
        $diag = (array) ($settings['last_sync_diagnostics'] ?? []);

        $this->assertFalse($result['ok']);
        $this->assertSame('error', $settings['last_sync_status']);
        $this->assertSame(500, (int) ($diag['http_status_code'] ?? 0));
    }

    /**
     * Verify empty response body returns useful error.
     *
     * @return void
     */
    public function test_empty_response_stores_useful_error(): void {
        update_option(CrakRevenueCamManager::API_SETTINGS_OPTION, ['api_key' => 'abc123']);
        CrakRevenueCamManager::set_http_getter(static function (): array {
            return [
                'response' => ['code' => 200],
                'headers' => ['content-type' => 'application/json'],
                'body' => '',
            ];
        });

        $result = CrakRevenueCamManager::sync_offers();
        $this->assertFalse($result['ok']);
        $this->assertSame('Empty response from API.', $result['message']);
    }

    /**
     * Verify malformed payload is diagnosed.
     *
     * @return void
     */
    public function test_malformed_response_stores_useful_error_and_preview(): void {
        update_option(CrakRevenueCamManager::API_SETTINGS_OPTION, ['api_key' => 'abc123']);
        CrakRevenueCamManager::set_http_getter(static function (): array {
            return [
                'response' => ['code' => 200],
                'headers' => ['content-type' => 'application/json'],
                'body' => '{not-json api_key=supersecret}',
            ];
        });

        $result = CrakRevenueCamManager::sync_offers();
        $settings = get_option(CrakRevenueCamManager::API_SETTINGS_OPTION, []);
        $diag = (array) ($settings['last_sync_diagnostics'] ?? []);

        $this->assertFalse($result['ok']);
        $this->assertSame('unknown', (string) ($diag['payload_shape'] ?? ''));
        $this->assertStringNotContainsString('supersecret', (string) ($diag['response_preview'] ?? ''));
    }

    /**
     * Verify parser shape coverage for common JSON variants.
     *
     * @return void
     */
    public function test_parser_supports_multiple_json_shapes(): void {
        $bodyA = '{"response":{"data":[{"id":1,"name":"Camsoda - Revshare"}]}}';
        $bodyB = '{"data":[{"id":2,"name":"Live Jasmin - Revshare"}]}';
        $bodyC = '[{"id":3,"name":"SinParty - Revshare"}]';
        $bodyD = '{"offers":[{"id":4,"name":"Royal Cams Gay - Revshare"}]}';
        $bodyE = '{"offer":[{"id":5,"name":"Cams.com - PPS"}]}';
        $bodyF = '{"Affiliate_Offer":[{"id":6,"name":"Visit X - PPS"}]}';

        $this->assertCount(1, CrakRevenueCamManager::parse_offers_payload($bodyA));
        $this->assertCount(1, CrakRevenueCamManager::parse_offers_payload($bodyB));
        $this->assertCount(1, CrakRevenueCamManager::parse_offers_payload($bodyC));
        $this->assertCount(1, CrakRevenueCamManager::parse_offers_payload($bodyD));
        $this->assertCount(1, CrakRevenueCamManager::parse_offers_payload($bodyE));
        $this->assertCount(1, CrakRevenueCamManager::parse_offers_payload($bodyF));
    }

    /**
     * Verify diagnostics includes names when raw rows exist but no cam matches.
     *
     * @return void
     */
    public function test_zero_cam_offers_includes_first_offer_names(): void {
        update_option(CrakRevenueCamManager::API_SETTINGS_OPTION, ['api_key' => 'abc123']);
        CrakRevenueCamManager::set_http_getter(static function (): array {
            return [
                'response' => ['code' => 200],
                'headers' => ['content-type' => 'application/json'],
                'body' => '[{"id":7,"name":"Unrelated Brand - CPA"}]',
            ];
        });

        CrakRevenueCamManager::sync_offers();
        $settings = get_option(CrakRevenueCamManager::API_SETTINGS_OPTION, []);
        $diag = (array) ($settings['last_sync_diagnostics'] ?? []);
        $names = (array) ($diag['first_offer_names'] ?? []);

        $this->assertSame('Unrelated Brand - CPA', $names[0] ?? '');
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
                ['offer_id' => 1, 'platform_slug' => 'camsoda', 'offer_name' => 'Camsoda - PPS', 'approval_status' => 'approved', 'default_payout' => 20, 'epc' => 1.0, 'is_expired' => 0, 'preview_url' => 'https://preview.example.com/a'],
                ['offer_id' => 2, 'platform_slug' => 'camsoda', 'offer_name' => 'Camsoda - Revshare Lifetime', 'approval_status' => 'approved', 'default_payout' => 5, 'epc' => 0.2, 'is_expired' => 0, 'preview_url' => 'https://preview.example.com/b'],
            ],
        ]);

        CrakRevenueCamManager::auto_map_best_offers();
        $map = get_option(CrakRevenueCamManager::PLATFORM_MAPPINGS_OPTION, []);
        $this->assertSame(2, (int)($map['camsoda']['selected_offer_id'] ?? 0));
        $this->assertSame('https://preview.example.com/b', (string)($map['camsoda']['selected_preview_url'] ?? ''));
        $this->assertSame('', (string)($map['camsoda']['template_url'] ?? ''));
        $this->assertSame(0, (int)($map['camsoda']['enabled'] ?? 1));
    }

    /**
     * EPC breaks ties only when EPC exists.
     *
     * @return void
     */
    public function test_higher_epc_breaks_ties_only_when_present(): void {
        $a = ['offer_name' => 'Camsoda - Revshare', 'approval_status' => 'approved', 'default_payout' => 10, 'epc' => 1.0, 'is_expired' => 0, 'platform_slug' => 'camsoda'];
        $b = ['offer_name' => 'Camsoda - Revshare', 'approval_status' => 'approved', 'default_payout' => 10, 'epc' => 0.2, 'is_expired' => 0, 'platform_slug' => 'camsoda'];
        $c = ['offer_name' => 'Camsoda - Revshare', 'approval_status' => 'approved', 'default_payout' => 10, 'epc' => null, 'is_expired' => 0, 'platform_slug' => 'camsoda'];
        $d = ['offer_name' => 'Camsoda - Revshare', 'approval_status' => 'approved', 'default_payout' => 10, 'epc' => null, 'is_expired' => 0, 'platform_slug' => 'camsoda'];
        $this->assertGreaterThan(CrakRevenueCamManager::offer_score($b), CrakRevenueCamManager::offer_score($a));
        $this->assertSame(CrakRevenueCamManager::offer_score($c), CrakRevenueCamManager::offer_score($d));
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
     * Hardcoded performer/model values are unsafe while placeholders are safe.
     *
     * @return void
     */
    public function test_template_safety_validation(): void {
        $bad1 = CrakRevenueCamManager::validate_template('https://go.example.com/?performerName=AishaDupont');
        $ok1 = CrakRevenueCamManager::validate_template('https://go.example.com/?performerName={username}');

        $this->assertFalse($bad1['safe']);
        $this->assertTrue($ok1['safe']);
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

    /**
     * Preview URL alone should not make platform frontend eligible.
     *
     * @return void
     */
    public function test_preview_url_only_is_not_frontend_eligible(): void {
        $r = new \ReflectionClass(CrakRevenueCamManager::class);
        $m = $r->getMethod('mapping_is_eligible_for_frontend');
        $m->setAccessible(true);

        $eligible = $m->invoke(null, [
            'approval_status' => 'approved',
            'selected_preview_url' => 'https://preview.example.com/offer',
            'template_url' => '',
        ]);
        $this->assertFalse((bool)$eligible);
    }
}
