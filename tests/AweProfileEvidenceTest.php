<?php
/**
 * Tests: AweProfileEvidence
 *
 * Covers:
 *  - probe_fields() detects bio-like keys when present
 *  - probe_fields() reports missing when absent
 *  - normalize() produces required shape with available_fields
 *  - normalize() flags has_bio correctly
 *  - admin_debug_inspect() never includes access_key
 *  - get_confidence() returns correct tier
 *  - Raw bio candidate not in published output shape
 *  - Tags extracted from both array and comma-separated forms
 *
 * @package TMWSEO\Engine\Tests
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Integrations\AweProfileEvidence;

require_once __DIR__ . '/../includes/services/class-settings.php';
require_once __DIR__ . '/../includes/integrations/class-awe-api-client.php';
require_once __DIR__ . '/../includes/integrations/class-awe-profile-evidence.php';

class AweProfileEvidenceTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_tmw_test_options']    = [];
        $GLOBALS['_tmw_test_transients'] = [];
        $GLOBALS['_tmw_test_post_meta']  = [];
    }

    protected function tearDown(): void {
        remove_all_filters( 'pre_http_request' );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /** Full AWE model response with all interesting fields. */
    private function full_response(): array {
        return [
            'models' => [
                [
                    'performerName'  => 'SophiaVega',
                    'performerId'    => '98765',
                    'username'       => 'sophiavega',
                    'bio'            => 'A short performer bio example.',
                    'tags'           => [ 'brunette', 'european', 'lingerie' ],
                    'categories'     => [ 'girls' ],
                    'profileImage'   => 'https://example.com/img/sophia.jpg',
                    'targetUrl'      => 'https://www.livejasmin.com/en/chat/sophiavega',
                    'online'         => true,
                ],
            ],
        ];
    }

    /** AWE response with no bio fields. */
    private function no_bio_response(): array {
        return [
            'models' => [
                [
                    'performerName' => 'NoBioModel',
                    'performerId'   => '11111',
                    'username'      => 'nobiomodel',
                    'tags'          => [ 'redhead' ],
                    'profileImage'  => 'https://example.com/img/nobio.jpg',
                    'targetUrl'     => 'https://www.livejasmin.com/en/chat/nobiomodel',
                ],
            ],
        ];
    }

    /** Completely empty response. */
    private function empty_response(): array {
        return [];
    }

    // ── probe_fields() ────────────────────────────────────────────────────────

    public function test_probe_detects_bio_field_when_present(): void {
        $probe = AweProfileEvidence::probe_fields( $this->full_response() );
        $this->assertTrue( $probe['has_bio'], 'has_bio should be true when bio field is present' );
        $this->assertContains( 'bio', $probe['found'] );
    }

    public function test_probe_reports_missing_bio_when_absent(): void {
        $probe = AweProfileEvidence::probe_fields( $this->no_bio_response() );
        $this->assertFalse( $probe['has_bio'], 'has_bio should be false when no bio fields present' );
        $this->assertContains( 'bio', $probe['missing'] );
        $this->assertContains( 'description', $probe['missing'] );
    }

    public function test_probe_returns_found_and_missing_lists(): void {
        $probe = AweProfileEvidence::probe_fields( $this->full_response() );
        $this->assertArrayHasKey( 'found', $probe );
        $this->assertArrayHasKey( 'missing', $probe );
        $this->assertIsArray( $probe['found'] );
        $this->assertIsArray( $probe['missing'] );
    }

    public function test_probe_on_empty_response_returns_all_missing(): void {
        $probe = AweProfileEvidence::probe_fields( $this->empty_response() );
        $this->assertFalse( $probe['has_bio'] );
        $this->assertEmpty( $probe['found'] );
        $this->assertNotEmpty( $probe['missing'] );
    }

    public function test_probe_detects_profile_image(): void {
        $probe = AweProfileEvidence::probe_fields( $this->full_response() );
        $this->assertContains( 'profileImage', $probe['found'] );
    }

    public function test_probe_detects_target_url(): void {
        $probe = AweProfileEvidence::probe_fields( $this->full_response() );
        $this->assertContains( 'targetUrl', $probe['found'] );
    }

    public function test_probe_detects_tags(): void {
        $probe = AweProfileEvidence::probe_fields( $this->full_response() );
        $this->assertContains( 'tags', $probe['found'] );
    }

    // ── normalize() ───────────────────────────────────────────────────────────

    public function test_normalize_returns_required_shape(): void {
        $evidence = AweProfileEvidence::normalize( $this->full_response(), 'SophiaVega' );

        $required_keys = [
            'source_type', 'source_label', 'source_url', 'performer_name',
            'profile_url', 'raw_bio_candidate', 'has_bio_candidate',
            'bio_excerpt_admin', 'tags', 'profile_image',
            'fetched_at', 'confidence', 'available_fields', 'missing_fields', 'has_bio',
        ];

        foreach ( $required_keys as $key ) {
            $this->assertArrayHasKey( $key, $evidence, "Key '{$key}' missing from normalized evidence" );
        }
    }

    public function test_normalize_source_type_is_awe_api(): void {
        $evidence = AweProfileEvidence::normalize( $this->full_response(), 'SophiaVega' );
        $this->assertSame( 'awe_api', $evidence['source_type'] );
    }

    public function test_normalize_extracts_performer_name(): void {
        $evidence = AweProfileEvidence::normalize( $this->full_response(), 'FallbackName' );
        $this->assertSame( 'SophiaVega', $evidence['performer_name'] );
    }

    public function test_normalize_falls_back_to_provided_name(): void {
        $evidence = AweProfileEvidence::normalize( $this->empty_response(), 'FallbackName' );
        $this->assertSame( 'FallbackName', $evidence['performer_name'] );
    }

    public function test_normalize_extracts_tags_as_array(): void {
        $evidence = AweProfileEvidence::normalize( $this->full_response(), 'SophiaVega' );
        $this->assertIsArray( $evidence['tags'] );
        $this->assertContains( 'brunette', $evidence['tags'] );
    }

    public function test_normalize_extracts_tags_from_comma_string(): void {
        $response = [ 'models' => [ [ 'performerName' => 'TagModel', 'tags' => 'redhead, petite, lingerie' ] ] ];
        $evidence = AweProfileEvidence::normalize( $response, 'TagModel' );
        $this->assertIsArray( $evidence['tags'] );
        $this->assertContains( 'redhead', $evidence['tags'] );
    }

    public function test_normalize_extracts_profile_image(): void {
        $evidence = AweProfileEvidence::normalize( $this->full_response(), 'SophiaVega' );
        $this->assertSame( 'https://example.com/img/sophia.jpg', $evidence['profile_image'] );
    }

    public function test_normalize_has_bio_candidate_true_when_bio_present(): void {
        $evidence = AweProfileEvidence::normalize( $this->full_response(), 'SophiaVega' );
        $this->assertTrue( $evidence['has_bio_candidate'] );
        $this->assertNotEmpty( $evidence['raw_bio_candidate'] );
    }

    public function test_normalize_has_bio_candidate_false_when_no_bio(): void {
        $evidence = AweProfileEvidence::normalize( $this->no_bio_response(), 'NoBioModel' );
        $this->assertFalse( $evidence['has_bio_candidate'] );
        $this->assertSame( '', $evidence['raw_bio_candidate'] );
    }

    public function test_normalize_available_fields_is_non_empty_for_full_response(): void {
        $evidence = AweProfileEvidence::normalize( $this->full_response(), 'SophiaVega' );
        $this->assertIsArray( $evidence['available_fields'] );
        $this->assertNotEmpty( $evidence['available_fields'] );
    }

    public function test_normalize_available_fields_empty_for_empty_response(): void {
        $evidence = AweProfileEvidence::normalize( $this->empty_response(), 'NoModel' );
        $this->assertIsArray( $evidence['available_fields'] );
        $this->assertEmpty( $evidence['available_fields'] );
    }

    public function test_normalize_source_url_does_not_contain_access_key(): void {
        update_option( 'tmwseo_engine_settings', [
            'tmwseo_awe_access_key' => 'super_secret_key_xyz',
            'tmwseo_awe_base_url'   => 'https://pt.ptawe.com',
        ] );
        $evidence = AweProfileEvidence::normalize( $this->full_response(), 'SophiaVega' );
        $this->assertStringNotContainsString( 'super_secret_key_xyz', $evidence['source_url'],
            'source_url must never contain the access key' );
    }

    // ── get_confidence() ──────────────────────────────────────────────────────

    public function test_confidence_high_when_bio_and_many_fields(): void {
        $probe = [
            'found'   => [ 'bio', 'performerName', 'tags', 'profileImage', 'targetUrl', 'username' ],
            'missing' => [],
            'has_bio' => true,
        ];
        $this->assertSame( 'high', AweProfileEvidence::get_confidence( $probe ) );
    }

    public function test_confidence_medium_without_bio_but_multiple_fields(): void {
        $probe = [
            'found'   => [ 'performerName', 'tags', 'profileImage' ],
            'missing' => [ 'bio', 'description' ],
            'has_bio' => false,
        ];
        $this->assertSame( 'medium', AweProfileEvidence::get_confidence( $probe ) );
    }

    public function test_confidence_low_for_one_field(): void {
        $probe = [
            'found'   => [ 'performerName' ],
            'missing' => [ 'bio', 'tags', 'profileImage', 'targetUrl' ],
            'has_bio' => false,
        ];
        $this->assertSame( 'low', AweProfileEvidence::get_confidence( $probe ) );
    }

    public function test_confidence_none_for_empty(): void {
        $probe = [
            'found'   => [],
            'missing' => [ 'bio', 'performerName', 'tags' ],
            'has_bio' => false,
        ];
        $this->assertSame( 'none', AweProfileEvidence::get_confidence( $probe ) );
    }

    // ── admin_debug_inspect() ─────────────────────────────────────────────────

    public function test_debug_inspect_never_contains_access_key(): void {
        update_option( 'tmwseo_engine_settings', [
            'tmwseo_awe_access_key' => 'must_not_appear_in_debug',
            'tmwseo_awe_base_url'   => 'https://pt.ptawe.com',
        ] );
        $debug = AweProfileEvidence::admin_debug_inspect( $this->full_response(), 200, false );
        $json  = json_encode( $debug );
        $this->assertStringNotContainsString( 'must_not_appear_in_debug', $json,
            'access_key must never appear in admin debug output' );
    }

    public function test_debug_inspect_returns_required_keys(): void {
        $debug = AweProfileEvidence::admin_debug_inspect( $this->full_response(), 200, true );
        foreach ( [ 'endpoint', 'http_status', 'cached', 'top_level_keys', 'data_keys', 'has_bio_field', 'confidence' ] as $k ) {
            $this->assertArrayHasKey( $k, $debug, "Key '{$k}' missing from debug output" );
        }
    }

    public function test_debug_inspect_endpoint_is_redacted(): void {
        $debug = AweProfileEvidence::admin_debug_inspect( $this->full_response(), 200, false );
        $this->assertStringContainsString( 'redacted', strtolower( $debug['endpoint'] ) );
        $this->assertStringNotContainsString( 'accessKey=', $debug['endpoint'] );
    }

    public function test_debug_inspect_has_bio_field_true_for_full_response(): void {
        $debug = AweProfileEvidence::admin_debug_inspect( $this->full_response(), 200, false );
        $this->assertTrue( $debug['has_bio_field'] );
    }

    public function test_debug_inspect_has_bio_field_false_for_no_bio_response(): void {
        $debug = AweProfileEvidence::admin_debug_inspect( $this->no_bio_response(), 200, false );
        $this->assertFalse( $debug['has_bio_field'] );
    }

    // ── Raw bio candidate safety ───────────────────────────────────────────────

    public function test_raw_bio_candidate_is_stripped_of_html(): void {
        $response = [ 'models' => [ [ 'bio' => '<b>Bold bio</b> with <script>alert(1)</script> text.' ] ] ];
        $evidence = AweProfileEvidence::normalize( $response, 'TestModel' );
        $this->assertStringNotContainsString( '<b>', $evidence['raw_bio_candidate'] );
        $this->assertStringNotContainsString( '<script>', $evidence['raw_bio_candidate'] );
        $this->assertStringContainsString( 'Bold bio', $evidence['raw_bio_candidate'] );
    }

    public function test_bio_excerpt_admin_is_truncated_to_200_chars(): void {
        $long_bio  = str_repeat( 'A', 500 );
        $response  = [ 'models' => [ [ 'bio' => $long_bio ] ] ];
        $evidence  = AweProfileEvidence::normalize( $response, 'LongBioModel' );
        $this->assertLessThanOrEqual( 200, mb_strlen( $evidence['bio_excerpt_admin'] ),
            'bio_excerpt_admin must not exceed 200 characters' );
    }
}
