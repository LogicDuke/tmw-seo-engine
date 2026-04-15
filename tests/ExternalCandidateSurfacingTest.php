<?php
/**
 * TMW SEO Engine — External Candidate Surfacing Tests
 *
 * Covers the Phase 1 follow-up patch:
 *
 *   A. classify_external_candidate() domain classification
 *      A1. TikTok URL → tiktok / high
 *      A2. Facebook URL → facebook / medium
 *      A3. OnlyFans URL → onlyfans / high
 *      A4. Pornhub URL → pornhub / medium
 *      A5. www-prefixed domain → same result as bare domain
 *      A6. Unknown domain → null
 *      A7. KNOWN_PLATFORMS domain (chaturbate) → null (not double-collected)
 *      A8. .xxx shallow path → personal_site / medium
 *      A9. .xxx deep path → null (excluded)
 *
 *   B. external_candidates survive merge_results() union + dedup
 *      B1. Single provider result with external_candidates → present in merged
 *      B2. Two providers with same URL → deduplicated, first provider wins
 *      B3. external_candidates absent from provider → merged stays empty
 *      B4. _provider tag added by merge_results
 *
 *   C. Trust boundary — external_candidates never pollute trusted outputs
 *      C1. external_candidates do NOT appear in platform_names
 *      C2. external_candidates do NOT appear in social_urls
 *      C3. platform_candidates (strict) still work correctly alongside external
 *
 *   D. pornhub in VerifiedLinks
 *      D1. 'pornhub' present in ALLOWED_TYPES
 *      D2. 'pornhub' => 'Pornhub' in TYPE_LABELS
 *      D3. guess_type_from_url for pornhub.com URL → 'pornhub'
 *
 * @package TMWSEO\Engine\Tests
 * @since   Phase 1 follow-up
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Tests;

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Admin\ModelResearchPipeline;
use TMWSEO\Engine\Model\VerifiedLinks;

// ── Testable subclass exposing classify_external_candidate() ─────────────────

class TestableSerpProviderForExternal extends \TMWSEO\Engine\Model\ModelSerpResearchProvider {
    public function public_classify( string $domain, string $url ): ?array {
        return $this->classify_external_candidate( $domain, $url );
    }
}

// ── Helper: build a minimal provider result ───────────────────────────────────

function make_ext_provider_result( array $external_candidates, array $extra = [] ): array {
    return array_merge( [
        'status'              => 'ok',
        'display_name'        => 'Test Model',
        'aliases'             => [],
        'bio'                 => '',
        'platform_names'      => [],
        'social_urls'         => [],
        'platform_candidates' => [],
        'external_candidates' => $external_candidates,
        'field_confidence'    => [],
        'research_diagnostics'=> [],
        'country'             => '',
        'language'            => '',
        'source_urls'         => [],
        'confidence'          => 10,
        'notes'               => '',
    ], $extra );
}

// ── Test suite ────────────────────────────────────────────────────────────────

class ExternalCandidateSurfacingTest extends TestCase {

    private TestableSerpProviderForExternal $provider;

    protected function setUp(): void {
        $this->provider = new TestableSerpProviderForExternal();
    }

    // ── A. classify_external_candidate() ─────────────────────────────────────

    public function test_tiktok_classified_correctly(): void {
        $r = $this->provider->public_classify( 'tiktok.com', 'https://www.tiktok.com/@aishadupont' );
        $this->assertNotNull( $r );
        $this->assertSame( 'tiktok',   $r['suggested_type'] );
        $this->assertSame( 'TikTok',   $r['label'] );
        $this->assertSame( 'high',     $r['confidence'] );
    }

    public function test_facebook_classified_correctly(): void {
        $r = $this->provider->public_classify( 'facebook.com', 'https://www.facebook.com/aishadupont' );
        $this->assertNotNull( $r );
        $this->assertSame( 'facebook', $r['suggested_type'] );
        $this->assertSame( 'medium',   $r['confidence'] );
    }

    public function test_onlyfans_classified_correctly(): void {
        $r = $this->provider->public_classify( 'onlyfans.com', 'https://onlyfans.com/aishadupont' );
        $this->assertNotNull( $r );
        $this->assertSame( 'onlyfans', $r['suggested_type'] );
        $this->assertSame( 'high',     $r['confidence'] );
    }

    public function test_pornhub_classified_correctly(): void {
        $r = $this->provider->public_classify( 'pornhub.com', 'https://www.pornhub.com/model/aishadupont' );
        $this->assertNotNull( $r );
        $this->assertSame( 'pornhub',  $r['suggested_type'] );
        $this->assertSame( 'Pornhub',  $r['label'] );
        $this->assertSame( 'medium',   $r['confidence'] );
    }

    public function test_www_prefix_stripped_before_classification(): void {
        $bare = $this->provider->public_classify( 'tiktok.com',     'https://tiktok.com/@x' );
        $www  = $this->provider->public_classify( 'www.tiktok.com', 'https://www.tiktok.com/@x' );
        $this->assertNotNull( $bare );
        $this->assertNotNull( $www );
        $this->assertSame( $bare['suggested_type'], $www['suggested_type'] );
    }

    public function test_unknown_domain_returns_null(): void {
        $this->assertNull( $this->provider->public_classify( 'example.com', 'https://example.com/model' ) );
        $this->assertNull( $this->provider->public_classify( 'randomsite.net', 'https://randomsite.net/' ) );
    }

    public function test_known_platform_domain_not_double_collected(): void {
        // chaturbate.com is in KNOWN_PLATFORMS — should NOT also be an external candidate.
        // The caller checks !$is_platform_candidate before calling classify_external_candidate.
        // classify_external_candidate itself returns null for chaturbate.com.
        $this->assertNull( $this->provider->public_classify( 'chaturbate.com', 'https://chaturbate.com/model' ) );
    }

    public function test_xxx_shallow_path_classified_as_personal_site(): void {
        $r = $this->provider->public_classify( 'aishadupont.xxx', 'https://aishadupont.xxx/about' );
        $this->assertNotNull( $r );
        $this->assertSame( 'personal_site', $r['suggested_type'] );
        $this->assertSame( 'medium',        $r['confidence'] );
    }

    public function test_xxx_deep_path_excluded(): void {
        // Deep gallery/video paths on .xxx domains should not be collected.
        $r = $this->provider->public_classify( 'aishadupont.xxx', 'https://aishadupont.xxx/videos/category/blonde/page/2' );
        $this->assertNull( $r, '.xxx deep content path must not be collected as external candidate' );
    }

    // ── B. merge_results() union + dedup ─────────────────────────────────────

    public function test_external_candidates_present_after_merge(): void {
        $ec = [
            'url'               => 'https://www.tiktok.com/@aishadupont',
            'detected_platform' => 'tiktok.com',
            'label'             => 'TikTok',
            'suggested_type'    => 'tiktok',
            'confidence'        => 'high',
            'query_family'      => 'social_discovery',
            '_alias_source'     => '',
        ];

        $result = make_ext_provider_result( [ $ec ] );
        $merged = ModelResearchPipeline::merge_results( [ 'serp' => $result ] );

        $this->assertArrayHasKey( 'external_candidates', $merged );
        $this->assertCount( 1, $merged['external_candidates'] );
        $this->assertSame( 'https://www.tiktok.com/@aishadupont', $merged['external_candidates'][0]['url'] );
    }

    public function test_external_candidates_deduplicated_by_url_across_providers(): void {
        $ec = [
            'url'            => 'https://www.tiktok.com/@aishadupont',
            'suggested_type' => 'tiktok',
            'label'          => 'TikTok',
            'confidence'     => 'high',
        ];

        $r1 = make_ext_provider_result( [ $ec ] );
        $r2 = make_ext_provider_result( [ $ec ] );  // same URL, second provider

        $merged = ModelResearchPipeline::merge_results( [ 'serp' => $r1, 'probe' => $r2 ] );

        $this->assertCount( 1, $merged['external_candidates'],
            'Same URL from two providers must be deduplicated to one entry' );
        // First provider wins
        $this->assertSame( 'serp', $merged['external_candidates'][0]['_provider'] );
    }

    public function test_provider_tag_added_to_external_candidates(): void {
        $ec = [ 'url' => 'https://onlyfans.com/model', 'suggested_type' => 'onlyfans', 'label' => 'OnlyFans', 'confidence' => 'high' ];
        $merged = ModelResearchPipeline::merge_results( [ 'serp' => make_ext_provider_result( [ $ec ] ) ] );

        $this->assertArrayHasKey( '_provider', $merged['external_candidates'][0] );
        $this->assertSame( 'serp', $merged['external_candidates'][0]['_provider'] );
    }

    public function test_missing_external_candidates_in_provider_stays_empty(): void {
        // Provider result without external_candidates key
        $result = [
            'status'         => 'ok',
            'platform_names' => [],
            'social_urls'    => [],
            'platform_candidates' => [],
            'confidence'     => 0,
        ];
        $merged = ModelResearchPipeline::merge_results( [ 'serp' => $result ] );
        $this->assertArrayHasKey( 'external_candidates', $merged );
        $this->assertSame( [], $merged['external_candidates'] );
    }

    // ── C. Trust boundary ─────────────────────────────────────────────────────

    public function test_external_candidates_do_not_pollute_platform_names(): void {
        $ec = [ 'url' => 'https://www.tiktok.com/@aishadupont', 'suggested_type' => 'tiktok', 'label' => 'TikTok', 'confidence' => 'high' ];
        $merged = ModelResearchPipeline::merge_results( [ 'serp' => make_ext_provider_result( [ $ec ] ) ] );

        $this->assertNotContains( 'TikTok', $merged['platform_names'],
            'External candidate label must NOT bleed into platform_names' );
        $this->assertEmpty( $merged['platform_names'] );
    }

    public function test_external_candidates_do_not_pollute_social_urls(): void {
        $ec = [ 'url' => 'https://www.tiktok.com/@aishadupont', 'suggested_type' => 'tiktok', 'label' => 'TikTok', 'confidence' => 'high' ];
        $merged = ModelResearchPipeline::merge_results( [ 'serp' => make_ext_provider_result( [ $ec ] ) ] );

        $this->assertNotContains( 'https://www.tiktok.com/@aishadupont', $merged['social_urls'],
            'External candidate URL must NOT bleed into social_urls' );
        $this->assertEmpty( $merged['social_urls'] );
    }

    public function test_strict_platform_candidates_and_external_candidates_coexist(): void {
        $trusted_candidate = [
            'success'             => true,
            'normalized_platform' => 'chaturbate',
            'username'            => 'aishadupont',
            'normalized_url'      => 'https://chaturbate.com/aishadupont',
            'source_url'          => 'https://chaturbate.com/aishadupont',
        ];
        $ec = [
            'url'            => 'https://www.tiktok.com/@aishadupont',
            'suggested_type' => 'tiktok',
            'label'          => 'TikTok',
            'confidence'     => 'high',
        ];

        $result = make_ext_provider_result(
            [ $ec ],
            [
                'platform_candidates' => [ $trusted_candidate ],
                'platform_names'      => [ 'Chaturbate' ],
                'social_urls'         => [ 'https://chaturbate.com/aishadupont' ],
            ]
        );
        $merged = ModelResearchPipeline::merge_results( [ 'serp' => $result ] );

        // Trusted platform still in platform_names
        $this->assertContains( 'Chaturbate', $merged['platform_names'] );
        // External candidate present in its own lane
        $this->assertCount( 1, $merged['external_candidates'] );
        $this->assertSame( 'https://www.tiktok.com/@aishadupont', $merged['external_candidates'][0]['url'] );
        // TikTok NOT in platform_names or social_urls
        $this->assertNotContains( 'TikTok', $merged['platform_names'] );
        $this->assertNotContains( 'https://www.tiktok.com/@aishadupont', $merged['social_urls'] );
    }

    // ── D. pornhub in VerifiedLinks ───────────────────────────────────────────

    public function test_pornhub_in_allowed_types(): void {
        $this->assertContains( 'pornhub', VerifiedLinks::ALLOWED_TYPES,
            "'pornhub' must be a member of ALLOWED_TYPES" );
    }

    public function test_pornhub_in_type_labels(): void {
        $this->assertArrayHasKey( 'pornhub', VerifiedLinks::TYPE_LABELS );
        $this->assertSame( 'Pornhub', VerifiedLinks::TYPE_LABELS['pornhub'] );
    }
}
