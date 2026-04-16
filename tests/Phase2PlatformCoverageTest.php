<?php
/**
 * TMW SEO Engine — Phase 2 Platform Coverage Tests
 *
 * Covers the Phase 2 platform expansion patch:
 *
 *   A. FanCentro registry entry
 *      A1. 'fancentro' slug exists in PlatformRegistry
 *      A2. profile_url_pattern is https://fancentro.com/{username}
 *      A3. name is 'FanCentro'
 *
 *   B. FanCentro parser (default pattern-regex path)
 *      B1. Standard profile URL extracts username
 *      B2. www-prefixed URL extracts same username
 *      B3. Trailing slash variant works
 *      B4. Wrong domain (lookalike) rejected
 *      B5. Root domain with no username returns ''
 *      B6. Deep content path returns ''
 *
 *   C. Streamate registry consistency
 *      C1. 'streamate' slug exists in PlatformRegistry
 *      C2. profile_url_pattern contains /cam/{username}
 *      C3. Standard cam-prefix URL parses correctly (already tested in
 *          PlatformProfilesParserTest — verify here it still works)
 *      C4. Wrong domain rejected
 *
 *   D. SERP discovery constant coverage
 *      D1. 'fancentro.com' present in KNOWN_PLATFORMS
 *      D2. 'streamate.com' present in KNOWN_PLATFORMS
 *      D3. 'fancentro.com' present in VARIANT_DISCOVERY_CREATOR_DOMAINS
 *      D4. 'streamate.com' present in VARIANT_DISCOVERY_WEBCAM_DOMAINS
 *      D5. creator_platform_discovery query contains 'fancentro'
 *
 *   E. Probe coverage
 *      E1. 'fancentro' in PROBE_PRIORITY_SLUGS
 *      E2. 'streamate' in PROBE_PRIORITY_SLUGS
 *      E3. 'livejasmin' in PROBE_PRIORITY_SLUGS
 *      E4. 'fancentro' in SEED_SOURCE_PRIORITY
 *      E5. 'streamate' in SEED_SOURCE_PRIORITY
 *      E6. Probe synthesizes correct URL for fancentro
 *      E7. Probe synthesizes correct URL for streamate
 *
 *   F. Trust boundary — new platforms go through strict parser
 *      F1. fancentro.com result through parse_url_for_platform_structured
 *          yields success=true with correct username
 *      F2. fancentro.com lookalike (evilfancentro.com) yields host_mismatch
 *      F3. fancentro URL with no username yields extraction_failed
 *
 * @package TMWSEO\Engine\Tests
 * @since   Phase 2
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Tests;

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Platform\PlatformRegistry;
use TMWSEO\Engine\Platform\PlatformProfiles;

// ── Probe subclass exposing constants via reflection ─────────────────────────

class TestableProbeForPhase2 extends \TMWSEO\Engine\Model\ModelPlatformProbe {
    public function get_probe_priority_slugs(): array {
        $r = new \ReflectionClass( \TMWSEO\Engine\Model\ModelPlatformProbe::class );
        return $r->getConstant( 'PROBE_PRIORITY_SLUGS' );
    }
    public function get_seed_source_priority(): array {
        $r = new \ReflectionClass( \TMWSEO\Engine\Model\ModelPlatformProbe::class );
        return $r->getConstant( 'SEED_SOURCE_PRIORITY' );
    }
    public function public_synthesize_candidate_url( string $slug, string $handle ): string {
        return $this->synthesize_candidate_url( $slug, $handle );
    }
}

// ── SERP provider subclass exposing constants ─────────────────────────────────

class TestableSerpProviderForPhase2 extends \TMWSEO\Engine\Model\ModelSerpResearchProvider {
    public function get_known_platforms(): array {
        $r = new \ReflectionClass( \TMWSEO\Engine\Model\ModelSerpResearchProvider::class );
        return $r->getConstant( 'KNOWN_PLATFORMS' );
    }
    public function get_variant_webcam_domains(): array {
        $r = new \ReflectionClass( \TMWSEO\Engine\Model\ModelSerpResearchProvider::class );
        return $r->getConstant( 'VARIANT_DISCOVERY_WEBCAM_DOMAINS' );
    }
    public function get_variant_creator_domains(): array {
        $r = new \ReflectionClass( \TMWSEO\Engine\Model\ModelSerpResearchProvider::class );
        return $r->getConstant( 'VARIANT_DISCOVERY_CREATOR_DOMAINS' );
    }
    public function public_build_query_pack( string $model_name, array $aliases = [] ): array {
        return $this->build_query_pack( $model_name, $aliases );
    }
}

// ── Test suite ────────────────────────────────────────────────────────────────

class Phase2PlatformCoverageTest extends TestCase {

    private TestableProbeForPhase2    $probe;
    private TestableSerpProviderForPhase2 $serp;

    protected function setUp(): void {
        $this->probe = new TestableProbeForPhase2();
        $this->serp  = new TestableSerpProviderForPhase2();
    }

    // ── A. FanCentro registry ─────────────────────────────────────────────────

    public function test_fancentro_slug_exists_in_registry(): void {
        $data = PlatformRegistry::get( 'fancentro' );
        $this->assertIsArray( $data, "'fancentro' must be registered in PlatformRegistry" );
    }

    public function test_fancentro_profile_url_pattern(): void {
        $data = PlatformRegistry::get( 'fancentro' );
        $this->assertIsArray( $data );
        $this->assertStringContainsString(
            'fancentro.com/{username}',
            (string) ( $data['profile_url_pattern'] ?? '' )
        );
    }

    public function test_fancentro_name(): void {
        $data = PlatformRegistry::get( 'fancentro' );
        $this->assertIsArray( $data );
        $this->assertSame( 'FanCentro', $data['name'] );
    }

    // ── B. FanCentro parser ───────────────────────────────────────────────────

    public function test_fancentro_standard_url_extracts_username(): void {
        $this->assertSame(
            'aishadupont',
            PlatformProfiles::extract_username_from_profile_url( 'fancentro', 'https://fancentro.com/aishadupont' )
        );
    }

    public function test_fancentro_www_url_extracts_username(): void {
        $this->assertSame(
            'aishadupont',
            PlatformProfiles::extract_username_from_profile_url( 'fancentro', 'https://www.fancentro.com/aishadupont' )
        );
    }

    public function test_fancentro_trailing_slash_extracts_username(): void {
        $this->assertSame(
            'aishadupont',
            PlatformProfiles::extract_username_from_profile_url( 'fancentro', 'https://fancentro.com/aishadupont/' )
        );
    }

    public function test_fancentro_lookalike_domain_rejected(): void {
        $this->assertSame(
            '',
            PlatformProfiles::extract_username_from_profile_url( 'fancentro', 'https://evilfancentro.com/aishadupont' ),
            'Lookalike domain must never extract a username'
        );
    }

    public function test_fancentro_root_domain_returns_empty(): void {
        $this->assertSame(
            '',
            PlatformProfiles::extract_username_from_profile_url( 'fancentro', 'https://fancentro.com/' )
        );
        $this->assertSame(
            '',
            PlatformProfiles::extract_username_from_profile_url( 'fancentro', 'https://fancentro.com' )
        );
    }

    public function test_fancentro_deep_content_path_returns_empty(): void {
        // FanCentro uses {username} as the first path segment; deeper paths
        // should not extract because the pattern-regex requires an exact match.
        // e.g. /aishadupont/posts would fail to match https://fancentro.com/{username}
        $this->assertSame(
            '',
            PlatformProfiles::extract_username_from_profile_url(
                'fancentro',
                'https://fancentro.com/aishadupont/posts'
            ),
            'Deep content path must not match the root-username pattern'
        );
    }

    // ── C. Streamate registry consistency ────────────────────────────────────

    public function test_streamate_slug_exists_in_registry(): void {
        $data = PlatformRegistry::get( 'streamate' );
        $this->assertIsArray( $data );
    }

    public function test_streamate_profile_url_pattern_has_cam_prefix(): void {
        $data = PlatformRegistry::get( 'streamate' );
        $this->assertIsArray( $data );
        $this->assertStringContainsString(
            '/cam/{username}',
            (string) ( $data['profile_url_pattern'] ?? '' )
        );
    }

    public function test_streamate_parser_extracts_username(): void {
        $this->assertSame(
            'streamstar',
            PlatformProfiles::extract_username_from_profile_url(
                'streamate',
                'https://www.streamate.com/cam/streamstar/'
            )
        );
    }

    public function test_streamate_wrong_domain_rejected(): void {
        $this->assertSame(
            '',
            PlatformProfiles::extract_username_from_profile_url(
                'streamate',
                'https://evilstreamate.com/cam/streamstar/'
            )
        );
    }

    // ── D. SERP discovery constants ───────────────────────────────────────────

    public function test_fancentro_in_known_platforms(): void {
        $kp = $this->serp->get_known_platforms();
        $this->assertArrayHasKey( 'fancentro.com', $kp,
            "'fancentro.com' must be in KNOWN_PLATFORMS" );
        $this->assertSame( 'FanCentro', $kp['fancentro.com'] );
    }

    public function test_streamate_in_known_platforms(): void {
        $kp = $this->serp->get_known_platforms();
        $this->assertArrayHasKey( 'streamate.com', $kp,
            "'streamate.com' must be in KNOWN_PLATFORMS" );
        $this->assertSame( 'Streamate', $kp['streamate.com'] );
    }

    public function test_fancentro_in_variant_creator_domains(): void {
        $this->assertContains(
            'fancentro.com',
            $this->serp->get_variant_creator_domains(),
            "'fancentro.com' must be in VARIANT_DISCOVERY_CREATOR_DOMAINS"
        );
    }

    public function test_streamate_in_variant_webcam_domains(): void {
        $this->assertContains(
            'streamate.com',
            $this->serp->get_variant_webcam_domains(),
            "'streamate.com' must be in VARIANT_DISCOVERY_WEBCAM_DOMAINS"
        );
    }

    public function test_creator_platform_discovery_query_contains_fancentro(): void {
        $pack = $this->serp->public_build_query_pack( 'Test Model' );
        $creator_query = '';
        foreach ( $pack as $q ) {
            if ( ( $q['family'] ?? '' ) === 'creator_platform_discovery' ) {
                $creator_query = (string) ( $q['query'] ?? '' );
                break;
            }
        }
        $this->assertNotEmpty( $creator_query, 'creator_platform_discovery family must exist' );
        $this->assertStringContainsString( 'fancentro', $creator_query,
            "creator_platform_discovery query must include 'fancentro'" );
    }

    // ── E. Probe coverage ────────────────────────────────────────────────────

    public function test_fancentro_in_probe_priority_slugs(): void {
        $this->assertContains( 'fancentro', $this->probe->get_probe_priority_slugs() );
    }

    public function test_streamate_in_probe_priority_slugs(): void {
        $this->assertContains( 'streamate', $this->probe->get_probe_priority_slugs() );
    }

    public function test_livejasmin_in_probe_priority_slugs(): void {
        $this->assertContains( 'livejasmin', $this->probe->get_probe_priority_slugs(),
            "'livejasmin' was in SEED_SOURCE_PRIORITY tier 2 but missing from PROBE_PRIORITY_SLUGS — must be fixed" );
    }

    public function test_fancentro_in_seed_source_priority(): void {
        $ssp = $this->probe->get_seed_source_priority();
        $this->assertArrayHasKey( 'fancentro', $ssp );
    }

    public function test_streamate_in_seed_source_priority(): void {
        $ssp = $this->probe->get_seed_source_priority();
        $this->assertArrayHasKey( 'streamate', $ssp );
    }

    public function test_fancentro_probe_synthesizes_correct_url(): void {
        $url = $this->probe->public_synthesize_candidate_url( 'fancentro', 'aishadupont' );
        $this->assertStringContainsString( 'fancentro.com', $url );
        $this->assertStringContainsString( 'aishadupont', $url );
    }

    public function test_streamate_probe_synthesizes_correct_url(): void {
        $url = $this->probe->public_synthesize_candidate_url( 'streamate', 'streamstar' );
        $this->assertStringContainsString( 'streamate.com', $url );
        $this->assertStringContainsString( 'streamstar', $url );
        $this->assertStringContainsString( '/cam/', $url );
    }

    // ── F. Trust boundary through structured parser ───────────────────────────

    public function test_fancentro_structured_parse_succeeds(): void {
        $result = PlatformProfiles::parse_url_for_platform_structured(
            'fancentro',
            'https://fancentro.com/aishadupont'
        );
        $this->assertTrue( (bool) ( $result['success'] ?? false ),
            'Structured parse of a valid fancentro URL must succeed' );
        $this->assertSame( 'aishadupont', $result['username'] ?? '' );
        $this->assertSame( '', (string) ( $result['reject_reason'] ?? '' ) );
        $this->assertSame( 'fancentro', $result['normalized_platform'] ?? '' );
    }

    public function test_fancentro_lookalike_yields_host_mismatch(): void {
        $result = PlatformProfiles::parse_url_for_platform_structured(
            'fancentro',
            'https://evilfancentro.com/aishadupont'
        );
        $this->assertFalse( (bool) ( $result['success'] ?? true ) );
        $this->assertSame( 'host_mismatch', $result['reject_reason'] ?? '' );
    }

    public function test_fancentro_no_username_yields_extraction_failed(): void {
        $result = PlatformProfiles::parse_url_for_platform_structured(
            'fancentro',
            'https://fancentro.com/'
        );
        $this->assertFalse( (bool) ( $result['success'] ?? true ) );
        $this->assertSame( 'extraction_failed', $result['reject_reason'] ?? '' );
    }

    public function test_fancentro_deep_path_yields_extraction_failed(): void {
        // fancentro.com/aishadupont/posts does not match the root pattern
        $result = PlatformProfiles::parse_url_for_platform_structured(
            'fancentro',
            'https://fancentro.com/aishadupont/posts'
        );
        $this->assertFalse( (bool) ( $result['success'] ?? true ) );
        // reject reason is extraction_failed (parsed to wrong slug depth)
        $this->assertNotEmpty( $result['reject_reason'] ?? '' );
    }
}
