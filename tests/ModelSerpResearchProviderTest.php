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

    /**
     * @return array<int,array{query:string,family:string}>
     */
    public function build_query_pack_public( string $model_name ): array {
        return $this->build_query_pack( $model_name );
    }

    /**
     * @return string[]
     */
    public function build_handle_variants_public( string $model_name ): array {
        return $this->build_handle_variants( $model_name );
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

    /** @test */
    public function test_handle_variants_include_required_normalized_forms(): void {
        $variants = $this->provider->build_handle_variants_public( 'Abby Murray' );

        $this->assertSame( [
            'abbymurray',
            'abby-murray',
            'abby_murray',
            'AbbyMurray',
            'abbyMurray',
        ], $variants );
    }

    /** @test */
    public function test_handle_variants_are_case_insensitive_and_bounded(): void {
        $variants = $this->provider->build_handle_variants_public( 'Anisyia' );

        $this->assertSame( [ 'anisyia' ], $variants );
    }

    /** @test */
    public function test_handle_variants_empty_or_punctuation_only_name_returns_empty(): void {
        $this->assertSame( [], $this->provider->build_handle_variants_public( '' ) );
        $this->assertSame( [], $this->provider->build_handle_variants_public( '!!! --- ___' ) );
    }

    /** @test */
    public function test_query_pack_preserves_existing_broad_families(): void {
        $pack = $this->provider->build_query_pack_public( 'Anisyia' );
        $families = array_column( $pack, 'family' );

        $this->assertContains( 'exact_name', $families );
        $this->assertContains( 'webcam_platform_discovery', $families );
        $this->assertContains( 'creator_platform_discovery', $families );
        $this->assertContains( 'hub_discovery', $families );
        $this->assertContains( 'social_discovery', $families );
    }

    /** @test */
    public function test_query_pack_adds_compact_variant_grouped_families(): void {
        $pack = $this->provider->build_query_pack_public( 'Abby Murray' );
        $families = array_column( $pack, 'family' );

        $this->assertContains( 'webcam_platform_variant_discovery', $families );
        $this->assertContains( 'creator_hub_variant_discovery', $families );
        $variant_families = array_values( array_filter(
            $families,
            static fn( string $family ): bool => str_contains( $family, 'variant_discovery' )
        ) );
        $this->assertCount( 2, $variant_families, 'Exactly two grouped variant families should be added.' );
        $this->assertCount( 7, $pack, 'Variant discovery should add exactly two bounded synchronous queries.' );
    }

    /** @test */
    public function test_query_pack_has_no_variant_families_when_variant_terms_are_empty(): void {
        $pack = $this->provider->build_query_pack_public( '!!! --- ___' );
        $families = array_column( $pack, 'family' );

        $this->assertCount( 5, $pack, 'Punctuation-only model names should keep only the original pass-one families.' );
        $this->assertNotContains( 'webcam_platform_variant_discovery', $families );
        $this->assertNotContains( 'creator_hub_variant_discovery', $families );
    }

    /** @test */
    public function test_webcam_variant_query_covers_target_platform_domains(): void {
        $pack = $this->provider->build_query_pack_public( 'Abby Murray' );
        $row  = array_values( array_filter( $pack, static fn( $q ) => $q['family'] === 'webcam_platform_variant_discovery' ) )[0] ?? [];
        $query = (string) ( $row['query'] ?? '' );

        $this->assertStringContainsString( 'camsoda.com', $query );
        $this->assertStringContainsString( 'stripchat.com', $query );
        $this->assertStringContainsString( 'chaturbate.com', $query );
        $this->assertStringContainsString( 'livejasmin.com', $query );
        $this->assertStringContainsString( 'sinparty.com', $query );
        $this->assertStringNotContainsString( 'Abby Murray', $query );
    }

    /** @test */
    public function test_creator_hub_variant_query_covers_target_hub_domains(): void {
        $pack = $this->provider->build_query_pack_public( 'Abby Murray' );
        $row  = array_values( array_filter( $pack, static fn( $q ) => $q['family'] === 'creator_hub_variant_discovery' ) )[0] ?? [];
        $query = (string) ( $row['query'] ?? '' );

        $this->assertStringContainsString( 'fansly.com', $query );
        $this->assertStringContainsString( 'linktr.ee', $query );
        $this->assertStringContainsString( 'allmylinks.com', $query );
        $this->assertStringNotContainsString( 'Abby Murray', $query );
    }
}
