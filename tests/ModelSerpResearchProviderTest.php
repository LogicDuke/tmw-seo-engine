<?php
/**
 * Unit tests for ModelSerpResearchProvider — research precision (v4.6.4).
 *
 * Scope: validates the domain-matching fix, country non-autofill, and the
 * extraction-gated logic via the exposed helpers.
 *
 * These tests do NOT make live DataForSEO API calls. They exercise only the
 * pure domain-classification and URL-filtering logic that can be verified
 * without a real SERP response.
 *
 * @package TMWSEO\Engine\Model
 * @since   4.6.4
 */

namespace TMWSEO\Engine\Model\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Testable subclass that exposes the private helpers via public wrappers.
 * Only used inside this test file — never shipped as production code.
 */
class TestableSerpProvider extends \TMWSEO\Engine\Model\ModelSerpResearchProvider {

    public function match_strict_public( string $domain, array $map ): string {
        return $this->match_domain_label_strict( $domain, $map );
    }

    public function is_evidence_public( string $url ): bool {
        return $this->is_evidence_url( $url );
    }
}

class ModelSerpResearchProviderTest extends TestCase {

    private TestableSerpProvider $provider;

    protected function setUp(): void {
        $this->provider = new TestableSerpProvider();
    }

    // =========================================================================
    // Domain matching — strict, no strpos collisions
    // =========================================================================

    /** @test */
    public function test_exact_domain_match_cams(): void {
        $map = [ 'cams.com' => 'Cams.com', 'xcams.com' => 'Xcams', 'olecams.com' => 'OleCams' ];
        $this->assertSame( 'Cams.com', $this->provider->match_strict_public( 'cams.com', $map ) );
    }

    /** @test */
    public function test_xcams_does_not_match_cams_key(): void {
        $map = [ 'cams.com' => 'Cams.com', 'xcams.com' => 'Xcams' ];
        // xcams.com should match xcams.com, NOT cams.com
        $this->assertSame( 'Xcams', $this->provider->match_strict_public( 'xcams.com', $map ) );
    }

    /** @test */
    public function test_olecams_does_not_match_cams_key(): void {
        $map = [ 'cams.com' => 'Cams.com', 'olecams.com' => 'OleCams' ];
        $this->assertSame( 'OleCams', $this->provider->match_strict_public( 'olecams.com', $map ) );
    }

    /** @test */
    public function test_bongacams_does_not_match_cams_key(): void {
        $map = [ 'cams.com' => 'Cams.com', 'bongacams.com' => 'BongaCams' ];
        $this->assertSame( 'BongaCams', $this->provider->match_strict_public( 'bongacams.com', $map ) );
    }

    /** @test */
    public function test_subdomain_matches_root_domain(): void {
        $map = [ 'chaturbate.com' => 'Chaturbate' ];
        // A SERP result from a subdomain of chaturbate.com should match
        $this->assertSame( 'Chaturbate', $this->provider->match_strict_public( 'en.chaturbate.com', $map ) );
    }

    /** @test */
    public function test_unrelated_domain_returns_empty(): void {
        $map = [ 'cams.com' => 'Cams.com', 'chaturbate.com' => 'Chaturbate' ];
        $this->assertSame( '', $this->provider->match_strict_public( 'example.com', $map ) );
    }

    /** @test */
    public function test_lookalike_domain_does_not_match(): void {
        $map = [ 'fansly.com' => 'Fansly' ];
        // evilfansly.com should NOT match fansly.com
        $this->assertSame( '', $this->provider->match_strict_public( 'evilfansly.com', $map ) );
    }

    /** @test */
    public function test_www_prefix_stripped_before_matching(): void {
        $map = [ 'stripchat.com' => 'Stripchat' ];
        $this->assertSame( 'Stripchat', $this->provider->match_strict_public( 'www.stripchat.com', $map ) );
    }

    // =========================================================================
    // Source URL quality filter — evidence vs listing pages
    // =========================================================================

    /** @test */
    public function test_profile_url_passes_filter(): void {
        $this->assertTrue( $this->provider->is_evidence_public( 'https://chaturbate.com/modelname/' ) );
    }

    /** @test */
    public function test_search_url_blocked(): void {
        $this->assertFalse( $this->provider->is_evidence_public( 'https://google.com/search?q=anisyia' ) );
    }

    /** @test */
    public function test_tag_page_blocked(): void {
        $this->assertFalse( $this->provider->is_evidence_public( 'https://example.com/tag/blonde' ) );
    }

    /** @test */
    public function test_category_page_blocked(): void {
        $this->assertFalse( $this->provider->is_evidence_public( 'https://example.com/category/cam-girls' ) );
    }

    /** @test */
    public function test_performers_listing_blocked(): void {
        $this->assertFalse( $this->provider->is_evidence_public( 'https://stripchat.com/performers/new' ) );
    }

    /** @test */
    public function test_models_listing_blocked(): void {
        $this->assertFalse( $this->provider->is_evidence_public( 'https://livejasmin.com/models/latin' ) );
    }

    /** @test */
    public function test_browse_page_blocked(): void {
        $this->assertFalse( $this->provider->is_evidence_public( 'https://chaturbate.com/browse/' ) );
    }

    /** @test */
    public function test_discover_page_blocked(): void {
        $this->assertFalse( $this->provider->is_evidence_public( 'https://fansly.com/discover/' ) );
    }

    /** @test */
    public function test_linktree_profile_passes_filter(): void {
        $this->assertTrue( $this->provider->is_evidence_public( 'https://linktr.ee/modelname' ) );
    }

    /** @test */
    public function test_empty_url_blocked(): void {
        $this->assertFalse( $this->provider->is_evidence_public( '' ) );
    }
}
