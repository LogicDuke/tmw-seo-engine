<?php
/**
 * Unit tests for ModelSerpResearchProvider — research precision (v4.6.4).
 *
 * Scope: validates strict domain matching and source URL evidence filtering.
 *
 * These tests do NOT make live DataForSEO API calls. They exercise private
 * pure helpers via Reflection on the final provider class.
 *
 * @package TMWSEO\Engine\Model
 * @since   4.6.4
 */

namespace TMWSEO\Engine\Model\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TMWSEO\Engine\Model\ModelSerpResearchProvider;

class ModelSerpResearchProviderTest extends TestCase {

    private ModelSerpResearchProvider $provider;

    protected function setUp(): void {
        $this->provider = new ModelSerpResearchProvider();
    }

    private function invokeMatchDomainLabelStrict( string $domain, array $map ): string {
        $reflection = new ReflectionClass( $this->provider );
        $method = $reflection->getMethod( 'match_domain_label_strict' );
        $method->setAccessible( true );

        /** @var string $label */
        $label = $method->invoke( $this->provider, $domain, $map );
        return $label;
    }

    private function invokeIsEvidenceUrl( string $url ): bool {
        $reflection = new ReflectionClass( $this->provider );
        $method = $reflection->getMethod( 'is_evidence_url' );
        $method->setAccessible( true );

        /** @var bool $isEvidence */
        $isEvidence = $method->invoke( $this->provider, $url );
        return $isEvidence;
    }

    /** @test */
    public function test_exact_domain_match_cams(): void {
        $map = [ 'cams.com' => 'Cams.com', 'xcams.com' => 'Xcams', 'olecams.com' => 'OleCams' ];
        $this->assertSame( 'Cams.com', $this->invokeMatchDomainLabelStrict( 'cams.com', $map ) );
    }

    /** @test */
    public function test_xcams_does_not_match_cams_key(): void {
        $map = [ 'cams.com' => 'Cams.com', 'xcams.com' => 'Xcams' ];
        $this->assertSame( 'Xcams', $this->invokeMatchDomainLabelStrict( 'xcams.com', $map ) );
    }

    /** @test */
    public function test_olecams_does_not_match_cams_key(): void {
        $map = [ 'cams.com' => 'Cams.com', 'olecams.com' => 'OleCams' ];
        $this->assertSame( 'OleCams', $this->invokeMatchDomainLabelStrict( 'olecams.com', $map ) );
    }

    /** @test */
    public function test_bongacams_does_not_match_cams_key(): void {
        $map = [ 'cams.com' => 'Cams.com', 'bongacams.com' => 'BongaCams' ];
        $this->assertSame( 'BongaCams', $this->invokeMatchDomainLabelStrict( 'bongacams.com', $map ) );
    }

    /** @test */
    public function test_subdomain_matches_root_domain(): void {
        $map = [ 'chaturbate.com' => 'Chaturbate' ];
        $this->assertSame( 'Chaturbate', $this->invokeMatchDomainLabelStrict( 'en.chaturbate.com', $map ) );
    }

    /** @test */
    public function test_unrelated_domain_returns_empty(): void {
        $map = [ 'cams.com' => 'Cams.com', 'chaturbate.com' => 'Chaturbate' ];
        $this->assertSame( '', $this->invokeMatchDomainLabelStrict( 'example.com', $map ) );
    }

    /** @test */
    public function test_lookalike_domain_does_not_match(): void {
        $map = [ 'fansly.com' => 'Fansly' ];
        $this->assertSame( '', $this->invokeMatchDomainLabelStrict( 'evilfansly.com', $map ) );
    }

    /** @test */
    public function test_www_prefix_stripped_before_matching(): void {
        $map = [ 'stripchat.com' => 'Stripchat' ];
        $this->assertSame( 'Stripchat', $this->invokeMatchDomainLabelStrict( 'www.stripchat.com', $map ) );
    }

    /** @test */
    public function test_profile_url_passes_filter(): void {
        $this->assertTrue( $this->invokeIsEvidenceUrl( 'https://chaturbate.com/modelname/' ) );
    }

    /** @test */
    public function test_search_url_blocked(): void {
        $this->assertFalse( $this->invokeIsEvidenceUrl( 'https://google.com/search?q=anisyia' ) );
    }

    /** @test */
    public function test_tag_page_blocked(): void {
        $this->assertFalse( $this->invokeIsEvidenceUrl( 'https://example.com/tag/blonde' ) );
    }

    /** @test */
    public function test_category_page_blocked(): void {
        $this->assertFalse( $this->invokeIsEvidenceUrl( 'https://example.com/category/cam-girls' ) );
    }

    /** @test */
    public function test_performers_listing_blocked(): void {
        $this->assertFalse( $this->invokeIsEvidenceUrl( 'https://stripchat.com/performers/new' ) );
    }

    /** @test */
    public function test_models_listing_blocked(): void {
        $this->assertFalse( $this->invokeIsEvidenceUrl( 'https://livejasmin.com/models/latin' ) );
    }

    /** @test */
    public function test_browse_page_blocked(): void {
        $this->assertFalse( $this->invokeIsEvidenceUrl( 'https://chaturbate.com/browse/' ) );
    }

    /** @test */
    public function test_discover_page_blocked(): void {
        $this->assertFalse( $this->invokeIsEvidenceUrl( 'https://fansly.com/discover/' ) );
    }

    /** @test */
    public function test_linktree_profile_passes_filter(): void {
        $this->assertTrue( $this->invokeIsEvidenceUrl( 'https://linktr.ee/modelname' ) );
    }

    /** @test */
    public function test_empty_url_blocked(): void {
        $this->assertFalse( $this->invokeIsEvidenceUrl( '' ) );
    }
}
