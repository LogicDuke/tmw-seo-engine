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
        $this->assertStringContainsString('NetworkId=crakrevenue', $url);
        $this->assertStringContainsString('Format=json', $url);
        $this->assertStringContainsString('Service=HasOffers', $url);
        $this->assertStringContainsString('Version=2', $url);
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
        $san = CrakRevenueCamManager::sanitize_api_settings(['api_key' => " bad<script> ", 'affiliate_id' => 'abc-123', 'network_id' => '']);
        $this->assertSame('bad', $san['api_key']);
        $this->assertSame('crakrevenue', $san['network_id']);
        $this->assertSame('abc-123', $san['affiliate_id']);
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
        $bad2 = CrakRevenueCamManager::validate_template('https://go.example.com/?model=JaneDoe');
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

    public function test_json_response_preview_redacts_api_key_variants(): void {
        update_option(CrakRevenueCamManager::API_SETTINGS_OPTION, ['api_key' => 'live-secret']);
        CrakRevenueCamManager::set_http_getter(static function (): array {
            return [
                'response' => ['code' => 200],
                'headers' => ['content-type' => 'application/json'],
                'body' => '{"response":{"data":[]},"api_key":"secret","apiKey":"secret"}',
            ];
        });
        CrakRevenueCamManager::sync_offers();
        $diag = (array) (get_option(CrakRevenueCamManager::API_SETTINGS_OPTION, [])['last_sync_diagnostics'] ?? []);
        $preview = (string) ($diag['response_preview'] ?? '');
        $this->assertStringNotContainsString('secret', $preview);
        $this->assertStringContainsString('[redacted]', $preview);
    }

    public function test_nested_request_api_key_is_redacted_in_preview_and_diagnostics(): void {
        update_option(CrakRevenueCamManager::API_SETTINGS_OPTION, ['api_key' => 'live-secret']);
        CrakRevenueCamManager::set_http_getter(static function (): array {
            return [
                'response' => ['code' => 200],
                'headers' => ['content-type' => 'application/json'],
                'body' => '{"request":{"Target":"Affiliate_Offer","Method":"findAll","NetworkId":"crakrevenue","api_key":"secret"},"response":{"status":-1,"errors":["bad credentials"]}}',
            ];
        });
        CrakRevenueCamManager::sync_offers();
        $diag = (array) (get_option(CrakRevenueCamManager::API_SETTINGS_OPTION, [])['last_sync_diagnostics'] ?? []);
        $preview = (string) ($diag['response_preview'] ?? '');
        $request = (array) ($diag['request_summary'] ?? []);
        $this->assertStringNotContainsString('secret', $preview);
        $this->assertSame('[redacted]', (string) ($request['api_key'] ?? ''));
        $this->assertSame('[redacted]', (string) ($request['AffiliateId'] ?? '[redacted]'));
    }

    public function test_response_status_error_sets_api_error_message(): void {
        update_option(CrakRevenueCamManager::API_SETTINGS_OPTION, ['api_key' => 'abc123']);
        CrakRevenueCamManager::set_http_getter(static function (): array {
            return [
                'response' => ['code' => 200],
                'headers' => ['content-type' => 'application/json'],
                'body' => '{"response":{"status":-1,"errors":["invalid api key"]}}',
            ];
        });
        $result = CrakRevenueCamManager::sync_offers();
        $settings = get_option(CrakRevenueCamManager::API_SETTINGS_OPTION, []);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('API returned an error:', (string) ($settings['last_sync_message'] ?? ''));
        $this->assertStringContainsString('invalid api key', (string) ($settings['last_sync_message'] ?? ''));
    }

    public function test_associative_map_shapes_parse_and_counts_are_correct(): void {
        update_option(CrakRevenueCamManager::API_SETTINGS_OPTION, ['api_key' => 'abc123']);
        CrakRevenueCamManager::set_http_getter(static function (): array {
            return [
                'response' => ['code' => 200],
                'headers' => ['content-type' => 'application/json'],
                'body' => '{"response":{"data":{"5170":{"Offer":{"name":"Camsoda - Revshare Lifetime"}},"3688":{"Offer":{"id":3688,"name":"Chaturbate - Revshare Lifetime"}}}}}',
            ];
        });
        $result = CrakRevenueCamManager::sync_offers();
        $settings = get_option(CrakRevenueCamManager::API_SETTINGS_OPTION, []);
        $diag = (array) ($settings['last_sync_diagnostics'] ?? []);
        $offers = CrakRevenueCamManager::get_cached_offers();
        $platforms = array_values(array_column($offers, 'platform_slug'));

        $this->assertTrue($result['ok']);
        $this->assertSame(2, (int) ($diag['raw_offer_count'] ?? 0));
        $this->assertSame(2, (int) ($diag['cam_offer_count'] ?? 0));
        $this->assertSame('response.data.map', (string) ($diag['payload_shape'] ?? ''));
        $this->assertContains('camsoda', $platforms);
        $this->assertContains('chaturbate', $platforms);
    }

    public function test_response_data_data_associative_map_parses(): void {
        $parsed = CrakRevenueCamManager::parse_offers_payload_with_diagnostics(
            '{"response":{"data":{"data":{"5170":{"id":5170,"name":"Camsoda - Revshare Lifetime"}}}}}'
        );
        $this->assertCount(1, $parsed['offers']);
        $this->assertSame('response.data.data.map', $parsed['payload_shape']);
        $this->assertSame(5170, (int) ($parsed['offers'][0]['id'] ?? 0));
    }

    public function test_payload_shape_is_specific_for_supported_shapes(): void {
        $parsed = CrakRevenueCamManager::parse_offers_payload_with_diagnostics(
            '{"response":{"data":{"5170":{"id":5170,"name":"Camsoda - Revshare Lifetime"}}}}'
        );
        $this->assertNotSame('unknown', $parsed['payload_shape']);
        $this->assertSame('response.data.map', $parsed['payload_shape']);
    }

    public function test_api_key_never_appears_in_last_sync_diagnostics(): void {
        update_option(CrakRevenueCamManager::API_SETTINGS_OPTION, ['api_key' => 'real-key-123']);
        CrakRevenueCamManager::set_http_getter(static function (): array {
            return [
                'response' => ['code' => 200],
                'headers' => ['content-type' => 'application/json'],
                'body' => '{"request":{"api_key":"real-key-123","Method":"findAll","Target":"Affiliate_Offer","NetworkId":"crakrevenue"},"response":{"data":[]}}',
            ];
        });
        CrakRevenueCamManager::sync_offers();
        $diag = (array) (get_option(CrakRevenueCamManager::API_SETTINGS_OPTION, [])['last_sync_diagnostics'] ?? []);
        $encoded = wp_json_encode($diag);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('real-key-123', (string) $encoded);
    }

    public function test_affiliate_id_never_appears_in_last_sync_diagnostics_or_preview(): void {
        update_option(CrakRevenueCamManager::API_SETTINGS_OPTION, ['api_key' => 'abc123']);
        CrakRevenueCamManager::set_http_getter(static function (): array {
            return [
                'response' => ['code' => 200],
                'headers' => ['content-type' => 'application/json'],
                'body' => '{"request":{"affiliate_id":"9988","AffiliateId":"9988","NetworkId":"crakrevenue"},"response":{"status":-1,"errors":["denied"]}}',
            ];
        });
        CrakRevenueCamManager::sync_offers();
        $diag = (array) (get_option(CrakRevenueCamManager::API_SETTINGS_OPTION, [])['last_sync_diagnostics'] ?? []);
        $encoded = (string) wp_json_encode($diag);
        $this->assertStringNotContainsString('9988', $encoded);
        $this->assertStringContainsString('[redacted]', $encoded);
    }

    public function test_nested_status_and_aliases_normalize_correctly(): void {
        $rowA = CrakRevenueCamManager::normalize_offer([
            'Offer' => ['id' => 5170, 'name' => 'Camsoda - Revshare Lifetime', 'status' => 'approved', 'previewUrl' => 'https://preview.test/a'],
        ]);
        $rowB = CrakRevenueCamManager::normalize_offer([
            'Affiliate_Offer' => ['id' => 5170, 'approval_status' => 'Approved'],
            'Offer' => ['name' => 'Camsoda - Revshare Lifetime', 'trackingLink' => 'https://go.test/?url={encoded_profile_url}'],
        ]);
        $rowC = CrakRevenueCamManager::normalize_offer([
            'id' => 5170,
            'name' => 'Camsoda - Revshare Lifetime',
            'require_approval' => true,
            'offerUrl' => 'https://go.test/?url={encoded_profile_url}',
            'payout' => 12.5,
        ]);

        $this->assertSame('approved', (string) ($rowA['approval_status'] ?? ''));
        $this->assertSame('https://preview.test/a', (string) ($rowA['preview_url'] ?? ''));
        $this->assertSame('approved', (string) ($rowB['approval_status'] ?? ''));
        $this->assertSame('https://go.test/?url={encoded_profile_url}', (string) ($rowB['tracking_template'] ?? ''));
        $this->assertSame('needs_approval', (string) ($rowC['approval_status'] ?? ''));
        $this->assertSame(12.5, (float) ($rowC['default_payout'] ?? 0.0));
    }

    public function test_preview_url_is_never_copied_into_tracking_template(): void {
        $row = CrakRevenueCamManager::normalize_offer([
            'id' => 5,
            'name' => 'Camsoda - Revshare Lifetime',
            'preview_url' => 'https://preview.test/only',
        ]);
        $this->assertSame('https://preview.test/only', (string) ($row['preview_url'] ?? ''));
        $this->assertSame('', (string) ($row['tracking_template'] ?? ''));
    }

    public function test_enable_defaults_requires_selected_offer_safe_template_and_not_expired(): void {
        $r = new \ReflectionClass(CrakRevenueCamManager::class);
        $m = $r->getMethod('mapping_is_eligible_for_frontend');
        $m->setAccessible(true);

        $badMissingTemplate = $m->invoke(null, [
            'approval_status' => 'approved',
            'selected_offer_id' => 88,
            'selected_offer_is_expired' => 0,
            'template_url' => '',
        ]);
        $good = $m->invoke(null, [
            'approval_status' => 'approved',
            'selected_offer_id' => 89,
            'selected_offer_is_expired' => 0,
            'template_url' => 'https://go.example.com/?url={encoded_profile_url}',
        ]);

        $this->assertFalse((bool) $badMissingTemplate);
        $this->assertTrue((bool) $good);
    }

    public function test_require_approval_zero_variants_normalize_to_approved(): void {
        $rowStringZero = CrakRevenueCamManager::normalize_offer(['id' => 11, 'name' => 'Camsoda - PPS', 'status' => 'active', 'require_approval' => '0']);
        $rowIntZero = CrakRevenueCamManager::normalize_offer(['id' => 12, 'name' => 'Camsoda - PPS', 'status' => 'active', 'require_approval' => 0]);
        $rowDisabled = CrakRevenueCamManager::normalize_offer(['id' => 13, 'name' => 'Camsoda - PPS', 'status' => 'active', 'require_approval' => 'disabled']);

        $this->assertSame('approved', (string) ($rowStringZero['approval_status'] ?? ''));
        $this->assertSame('approved', (string) ($rowIntZero['approval_status'] ?? ''));
        $this->assertSame('approved', (string) ($rowDisabled['approval_status'] ?? ''));
    }

    public function test_require_approval_one_variants_normalize_to_needs_approval(): void {
        $rowStringOne = CrakRevenueCamManager::normalize_offer(['id' => 14, 'name' => 'Camsoda - PPS', 'status' => 'active', 'require_approval' => '1']);
        $rowIntOne = CrakRevenueCamManager::normalize_offer(['id' => 15, 'name' => 'Camsoda - PPS', 'status' => 'active', 'require_approval' => 1]);
        $rowEnabled = CrakRevenueCamManager::normalize_offer(['id' => 16, 'name' => 'Camsoda - PPS', 'status' => 'active', 'require_approval' => 'enabled']);

        $this->assertSame('needs_approval', (string) ($rowStringOne['approval_status'] ?? ''));
        $this->assertSame('needs_approval', (string) ($rowIntOne['approval_status'] ?? ''));
        $this->assertSame('needs_approval', (string) ($rowEnabled['approval_status'] ?? ''));
    }

    public function test_status_active_with_missing_require_approval_is_approved(): void {
        $row = CrakRevenueCamManager::normalize_offer(['id' => 17, 'name' => 'Camsoda - PPS', 'status' => 'active']);
        $this->assertSame('approved', (string) ($row['approval_status'] ?? ''));
        $this->assertSame('active', (string) ($row['raw_status'] ?? ''));
    }

    public function test_status_active_with_empty_require_approval_is_approved(): void {
        $row = CrakRevenueCamManager::normalize_offer(['id' => 170, 'name' => 'Camsoda - PPS', 'status' => 'active', 'require_approval' => '']);
        $this->assertSame('approved', (string) ($row['approval_status'] ?? ''));
    }

    public function test_approval_counts_include_require_approval_based_statuses(): void {
        update_option(CrakRevenueCamManager::API_SETTINGS_OPTION, ['api_key' => 'abc123']);
        CrakRevenueCamManager::set_http_getter(static function (string $url, array $args = []): array {
            if (str_contains($url, 'Target=Affiliate_OfferUrl')) {
                return ['response' => ['code' => 404], 'headers' => ['content-type' => 'application/json'], 'body' => '{}'];
            }
            return [
                'response' => ['code' => 200],
                'headers' => ['content-type' => 'application/json'],
                'body' => wp_json_encode([
                    'response' => [
                        'data' => [
                            ['id' => 101, 'name' => 'Camsoda - Revshare', 'status' => 'active', 'require_approval' => '0'],
                            ['id' => 102, 'name' => 'Camsoda - PPS', 'status' => 'active', 'require_approval' => '1'],
                            ['id' => 103, 'name' => 'LiveJasmin - PPS', 'status' => 'active'],
                        ],
                    ],
                ]),
            ];
        });
        CrakRevenueCamManager::sync_offers();
        $offers = CrakRevenueCamManager::get_cached_offers();
        $approved = count(array_filter($offers, static fn(array $row): bool => (string) ($row['approval_status'] ?? '') === 'approved'));
        $needs = count(array_filter($offers, static fn(array $row): bool => (string) ($row['approval_status'] ?? '') === 'needs_approval'));
        $unknown = count(array_filter($offers, static fn(array $row): bool => (string) ($row['approval_status'] ?? '') === 'unknown'));

        $this->assertSame(2, $approved);
        $this->assertSame(1, $needs);
        $this->assertSame(0, $unknown);
    }

    public function test_auto_map_populates_selected_offer_id_and_name_for_approved_offers(): void {
        update_option(CrakRevenueCamManager::OFFERS_CACHE_OPTION, [
            'offers' => [
                ['offer_id' => 22, 'platform_slug' => 'stripchat', 'offer_name' => 'Stripchat - PPS', 'approval_status' => 'needs_approval', 'is_expired' => 0],
                ['offer_id' => 23, 'platform_slug' => 'stripchat', 'offer_name' => 'Stripchat - Revshare', 'approval_status' => 'approved', 'is_expired' => 0],
            ],
        ]);

        CrakRevenueCamManager::auto_map_best_offers();
        $map = (array) get_option(CrakRevenueCamManager::PLATFORM_MAPPINGS_OPTION, []);
        $this->assertSame(23, (int) ($map['stripchat']['selected_offer_id'] ?? 0));
        $this->assertSame('Stripchat - Revshare', (string) ($map['stripchat']['selected_offer_name'] ?? ''));
    }

    public function test_supported_platforms_detected_counts_unique_platform_slug_values(): void {
        update_option(CrakRevenueCamManager::API_SETTINGS_OPTION, ['api_key' => 'abc123']);
        CrakRevenueCamManager::set_http_getter(static function (string $url): array {
            if (str_contains($url, 'Target=Affiliate_OfferUrl')) {
                return ['response' => ['code' => 404], 'headers' => ['content-type' => 'application/json'], 'body' => '{}'];
            }
            return [
                'response' => ['code' => 200],
                'headers' => ['content-type' => 'application/json'],
                'body' => wp_json_encode([
                    'response' => [
                        'data' => [
                            ['id' => 24, 'name' => 'Camsoda - PPS', 'status' => 'active'],
                            ['id' => 25, 'name' => 'Camsoda - Revshare', 'status' => 'active'],
                            ['id' => 26, 'name' => 'Stripchat - Revshare', 'status' => 'active'],
                        ],
                    ],
                ]),
            ];
        });

        CrakRevenueCamManager::sync_offers();
        $diag = (array) (get_option(CrakRevenueCamManager::API_SETTINGS_OPTION, [])['last_sync_diagnostics'] ?? []);
        $this->assertSame(2, (int) ($diag['supported_platforms_detected'] ?? 0));
    }

    public function test_manual_template_save_persists_template_url(): void {
        $saved = CrakRevenueCamManager::save_mapping_templates(
            ['camsoda' => ['platform_slug' => 'camsoda', 'template_url' => '']],
            ['camsoda' => ['template_url' => 'https://go.example.com/?model={username}']],
            'camsoda'
        );
        $this->assertSame('https://go.example.com/?model={username}', (string) ($saved['camsoda']['template_url'] ?? ''));
    }

    public function test_epc_not_expected_from_affiliate_offer_and_preview_not_template(): void {
        $row = CrakRevenueCamManager::normalize_offer([
            'id' => 18,
            'name' => 'Camsoda - Revshare',
            'status' => 'active',
            'require_approval' => 0,
            'preview_url' => 'https://preview.test/offer',
        ]);
        $this->assertNull($row['epc']);
        $this->assertSame('EPC from stats/manual only', (string) ($row['epc_note'] ?? ''));
        $this->assertSame('https://preview.test/offer', (string) ($row['preview_url'] ?? ''));
        $this->assertSame('', (string) ($row['tracking_template'] ?? ''));
    }

    public function test_payout_prefers_percent_then_default_and_boolean_link_flags_parse_zero_one(): void {
        $r = new \ReflectionClass(CrakRevenueCamManager::class);
        $m = $r->getMethod('format_payout_display');
        $m->setAccessible(true);

        $rowRevshare = CrakRevenueCamManager::normalize_offer(['id' => 19, 'name' => 'Camsoda - Revshare', 'percent_payout' => 30, 'currency' => 'USD', 'allow_website_links' => '1', 'allow_direct_links' => 0]);
        $rowPps = CrakRevenueCamManager::normalize_offer(['id' => 20, 'name' => 'Camsoda - PPS', 'default_payout' => 45.5, 'currency' => 'USD', 'allow_website_links' => 0, 'allow_direct_links' => '1']);

        $this->assertSame('30%', (string) $m->invoke(null, $rowRevshare));
        $this->assertSame('USD 45.5', (string) $m->invoke(null, $rowPps));
        $this->assertTrue((bool) ($rowRevshare['allow_website_links'] ?? false));
        $this->assertFalse((bool) ($rowRevshare['allow_direct_links'] ?? true));
        $this->assertFalse((bool) ($rowPps['allow_website_links'] ?? true));
        $this->assertTrue((bool) ($rowPps['allow_direct_links'] ?? false));
    }
}
