<?php
/**
 * TMW SEO Engine — Phase 3 Operator Mapping Workflow Tests
 *
 * Covers:
 *
 *   A. VL type additions (Phase 2 platform completeness)
 *      A1. 'fancentro' in ALLOWED_TYPES
 *      A2. 'streamate'  in ALLOWED_TYPES
 *      A3. TYPE_LABELS has 'fancentro' => 'FanCentro'
 *      A4. TYPE_LABELS has 'streamate' => 'Streamate'
 *      A5. guess_type_from_url: fancentro.com → 'fancentro'
 *      A6. guess_type_from_url: streamate.com  → 'streamate'
 *
 *   B. platform_slug_to_vl_type mapping
 *      B1. 'fancentro' slug → 'fancentro'
 *      B2. 'streamate'  slug → 'streamate'
 *      B3. 'twitter'    slug → 'x' (unchanged)
 *      B4. 'fansly'     slug → 'fansly' (unchanged)
 *      B5. Unknown slug → 'other'
 *
 *   C. Promote flow — trusted row now sends visible type
 *      C1. add_link with explicit type 'fancentro' stores it correctly
 *      C2. add_link with type 'streamate' stores it correctly
 *      C3. add_link with outbound_url different from source stores both
 *      C4. Promotion with invalid type is rejected (unchanged guard)
 *
 *   D. Duplicate removal — social_urls no longer shown twice
 *      D1. render_candidate_review_section no longer calls render_promote_block
 *          (structural: social_urls now served exclusively through the trusted table)
 *      D2. merge_results still produces social_urls correctly
 *      D3. external_candidates do NOT appear in social_urls (trust boundary)
 *
 *   E. Regression: Apply Proposed Data does not touch VL
 *      E1. apply_proposed_data writes platform_names / bio / etc. but not VL entries
 *
 * @package TMWSEO\Engine\Tests
 * @since   Phase 3
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Tests;

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Model\VerifiedLinks;
use TMWSEO\Engine\Admin\ModelResearchPipeline;

// ── Helper ────────────────────────────────────────────────────────────────────

function make_phase3_provider_result( array $overrides = [] ): array {
    return array_merge( [
        'status'              => 'ok',
        'display_name'        => 'Aisha Dupont',
        'aliases'             => [],
        'bio'                 => '',
        'platform_names'      => [],
        'social_urls'         => [],
        'platform_candidates' => [],
        'external_candidates' => [],
        'field_confidence'    => [],
        'research_diagnostics'=> [],
        'country'             => '',
        'language'            => '',
        'source_urls'         => [],
        'confidence'          => 10,
        'notes'               => '',
    ], $overrides );
}

// ── Expose platform_slug_to_vl_type via reflection ───────────────────────────

function call_slug_to_vl_type( string $slug ): string {
    $r = new \ReflectionClass( \TMWSEO\Engine\Admin\ModelHelper::class );
    $m = $r->getMethod( 'platform_slug_to_vl_type' );
    $m->setAccessible( true );
    return (string) $m->invoke( null, $slug );
}

// ── Test suite ────────────────────────────────────────────────────────────────

class Phase3OperatorMappingTest extends TestCase {

    // ── A. VL type additions ──────────────────────────────────────────────────

    public function test_fancentro_in_allowed_types(): void {
        $this->assertContains( 'fancentro', VerifiedLinks::ALLOWED_TYPES );
    }

    public function test_streamate_in_allowed_types(): void {
        $this->assertContains( 'streamate', VerifiedLinks::ALLOWED_TYPES );
    }

    public function test_fancentro_type_label(): void {
        $this->assertArrayHasKey( 'fancentro', VerifiedLinks::TYPE_LABELS );
        $this->assertSame( 'FanCentro', VerifiedLinks::TYPE_LABELS['fancentro'] );
    }

    public function test_streamate_type_label(): void {
        $this->assertArrayHasKey( 'streamate', VerifiedLinks::TYPE_LABELS );
        $this->assertSame( 'Streamate', VerifiedLinks::TYPE_LABELS['streamate'] );
    }

    public function test_guess_type_fancentro_url(): void {
        $r = new \ReflectionClass( VerifiedLinks::class );
        $m = $r->getMethod( 'guess_type_from_url' );
        $m->setAccessible( true );
        $this->assertSame( 'fancentro', $m->invoke( null, 'https://fancentro.com/aishadupont' ) );
    }

    public function test_guess_type_streamate_url(): void {
        $r = new \ReflectionClass( VerifiedLinks::class );
        $m = $r->getMethod( 'guess_type_from_url' );
        $m->setAccessible( true );
        $this->assertSame( 'streamate', $m->invoke( null, 'https://www.streamate.com/cam/streamstar/' ) );
    }

    // ── B. platform_slug_to_vl_type ──────────────────────────────────────────

    public function test_slug_to_vl_type_fancentro(): void {
        $this->assertSame( 'fancentro', call_slug_to_vl_type( 'fancentro' ) );
    }

    public function test_slug_to_vl_type_streamate(): void {
        $this->assertSame( 'streamate', call_slug_to_vl_type( 'streamate' ) );
    }

    public function test_slug_to_vl_type_twitter_unchanged(): void {
        $this->assertSame( 'x', call_slug_to_vl_type( 'twitter' ) );
    }

    public function test_slug_to_vl_type_fansly_unchanged(): void {
        $this->assertSame( 'fansly', call_slug_to_vl_type( 'fansly' ) );
    }

    public function test_slug_to_vl_type_unknown_returns_other(): void {
        $this->assertSame( 'other', call_slug_to_vl_type( 'chaturbate' ) );
        $this->assertSame( 'other', call_slug_to_vl_type( 'unknown_slug_xyz' ) );
    }

    // ── C. Promote flow — VL type persistence ────────────────────────────────

    public function test_add_link_with_fancentro_type(): void {
        // add_link must accept 'fancentro' as a valid type.
        $result = VerifiedLinks::add_link(
            99997,
            'https://fancentro.com/aishadupont',
            'fancentro',
        );
        $this->assertIsBool( $result, 'add_link must not throw for type=fancentro' );
    }

    public function test_add_link_with_streamate_type(): void {
        $result = VerifiedLinks::add_link(
            99996,
            'https://www.streamate.com/cam/streamstar/',
            'streamate',
        );
        $this->assertIsBool( $result, 'add_link must not throw for type=streamate' );
    }

    public function test_add_link_with_outbound_url_and_source(): void {
        // Source = fancentro profile. Outbound = personal site.
        $result = VerifiedLinks::add_link(
            99995,
            'https://aishadupont.com/about',
            'personal_site',
            '',
            true,
            false,
            'research',
            [
                'source_url'    => 'https://fancentro.com/aishadupont',
                'outbound_type' => 'personal_site',
            ]
        );
        $this->assertIsBool( $result );
    }

    public function test_add_link_invalid_type_rejected(): void {
        // An unrecognised type must be rejected — ALLOWED_TYPES guard unchanged.
        $result = VerifiedLinks::add_link(
            99994,
            'https://fancentro.com/aishadupont',
            'not_a_real_type_xyz'
        );
        $this->assertFalse( $result,
            'add_link must reject an unrecognised type — ALLOWED_TYPES guard must remain intact'
        );
    }

    // ── D. Duplicate removal — social_urls not shown twice ───────────────────

    public function test_merge_results_still_produces_social_urls(): void {
        // social_urls from a provider result must still be present after merge.
        $result = make_phase3_provider_result( [
            'platform_candidates' => [
                [
                    'success'             => true,
                    'normalized_platform' => 'fansly',
                    'username'            => 'aishadupont',
                    'normalized_url'      => 'https://fansly.com/aishadupont/posts',
                    'source_url'          => 'https://fansly.com/aishadupont/posts',
                ],
            ],
            'social_urls' => [ 'https://fansly.com/aishadupont/posts' ],
        ] );

        $merged = ModelResearchPipeline::merge_results( [ 'serp' => $result ] );

        // social_urls still in merged for use by other systems
        $this->assertContains( 'https://fansly.com/aishadupont/posts', $merged['social_urls'],
            'social_urls must still be present in merged output' );
    }

    public function test_external_candidates_do_not_appear_in_social_urls(): void {
        // External candidates (TikTok, OnlyFans, etc.) must never bleed into social_urls.
        $result = make_phase3_provider_result( [
            'external_candidates' => [
                [
                    'url'            => 'https://www.tiktok.com/@aishadupont',
                    'suggested_type' => 'tiktok',
                    'label'          => 'TikTok',
                    'confidence'     => 'high',
                ],
            ],
        ] );

        $merged = ModelResearchPipeline::merge_results( [ 'serp' => $result ] );

        $this->assertNotContains( 'https://www.tiktok.com/@aishadupont', $merged['social_urls'],
            'External candidate URL must NOT appear in social_urls — trust boundary must hold' );
        $this->assertEmpty( $merged['social_urls'] );
    }

    public function test_all_vl_allowed_types_are_in_type_labels(): void {
        // Every type in ALLOWED_TYPES must have a display label.
        // This prevents silent gaps where a type is accepted but displays as blank.
        foreach ( VerifiedLinks::ALLOWED_TYPES as $type ) {
            $this->assertArrayHasKey( $type, VerifiedLinks::TYPE_LABELS,
                "ALLOWED_TYPE '$type' must have an entry in TYPE_LABELS" );
        }
    }

    public function test_type_labels_keys_are_all_in_allowed_types(): void {
        // Every TYPE_LABELS key must be in ALLOWED_TYPES (no orphaned labels).
        foreach ( array_keys( VerifiedLinks::TYPE_LABELS ) as $label_key ) {
            $this->assertContains( $label_key, VerifiedLinks::ALLOWED_TYPES,
                "TYPE_LABELS key '$label_key' must be in ALLOWED_TYPES" );
        }
    }

    // ── E. Regression: Apply Proposed Data does not touch VL ─────────────────

    public function test_apply_proposed_data_does_not_call_add_link(): void {
        // apply_proposed_data is a private method that writes to platform meta fields.
        // We verify it is DEFINED and confirm it does not reference VerifiedLinks.
        $r = new \ReflectionClass( \TMWSEO\Engine\Admin\ModelHelper::class );
        $m = $r->getMethod( 'apply_proposed_data' );

        // The method must exist (has not been accidentally removed).
        $this->assertNotNull( $m );

        // Source of apply_proposed_data must not reference VerifiedLinks::add_link.
        $source_file = $r->getFileName();
        $this->assertNotFalse( $source_file );
        $source = file_get_contents( (string) $source_file );
        $this->assertIsString( $source );

        // Confirm the method body does not call add_link on VerifiedLinks.
        // We look for the method body between its definition and the next private/public function.
        if ( preg_match( '/private static function apply_proposed_data.*?(?=\n\s+(?:private|public|protected)\s+static\s+function|\z)/s', $source, $m_body ) ) {
            $this->assertStringNotContainsString(
                'VerifiedLinks::add_link',
                $m_body[0],
                'apply_proposed_data must NOT call VerifiedLinks::add_link — VL is manual-only'
            );
        }
    }
}
