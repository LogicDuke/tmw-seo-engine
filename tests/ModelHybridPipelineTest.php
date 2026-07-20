<?php
/**
 * TMW SEO Engine — Hybrid Pipeline Tests (v4.6.8)
 *
 * Covers the hybrid provider architecture requirements:
 *
 *   A. merge_results() field-aware merge behaviour
 *      A1. display_name / bio / country / language: first non-empty wins
 *      A2. platform_names: union + dedupe + sorted
 *      A3. social_urls / source_urls / aliases: union + dedupe (insertion order)
 *      A4. platform_candidates: append + dedupe by canonical key; _provider tag
 *      A5. field_confidence: per-key maximum
 *      A6. research_diagnostics: nested per-provider, not overwritten
 *      A7. confidence: corroboration bonus, capped at 90
 *      A8. notes: prefixed, pipe-joined
 *
 *   B. ModelDirectProbeProvider
 *      B1. provider_name() returns 'direct_probe'
 *      B2. build_name_seeds() derives correct seed from display name
 *      B3. lookup() returns correct shape when probe finds platforms
 *      B4. lookup() returns partial when probe finds nothing
 *      B5. platform_candidates tagged discovered_via_probe
 *      B6. safe_mode suppresses provider
 *      B7. make_probe() returns a ModelPlatformProbe instance
 *
 *   C. Pipeline integration (provider_results preserved; single-provider compat)
 *      C1. Single-provider result still works with new merge
 *      C2. provider_results keyed by provider_name
 *      C3. Failed/no_provider results stored in diagnostics; not merged into data
 *      C4. Merge is manual-review safe (no auto-apply behaviour)
 *
 *   D. Round-trip: two providers, smart merge produces correct combined output
 *
 * No live HTTP calls. Test doubles are used throughout.
 *
 * @package TMWSEO\Engine\Admin\Tests
 * @since   4.6.8
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Admin\Tests;

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Admin\ModelResearchPipeline;
use TMWSEO\Engine\Model\ModelDirectProbeProvider;
use TMWSEO\Engine\Model\ModelPlatformProbe;

// ── Test doubles ─────────────────────────────────────────────────────────────

/**
 * Stub ModelDirectProbeProvider that injects a configurable probe result
 * without making any live HTTP calls.
 */
class TestableDirectProbeProvider extends ModelDirectProbeProvider {

    /** @var array{verified_urls:array,diagnostics:array} */
    private array $mock_probe_return;

    /**
     * @param array{verified_urls:array,diagnostics:array} $probe_return
     */
    public function __construct( array $probe_return ) {
        $this->mock_probe_return = $probe_return;
    }

    /**
     * Override make_probe() to return a MockProbeAlwaysReturns instance
     * that yields the configured result from run().
     */
    protected function make_probe(): ModelPlatformProbe {
        return new MockProbeReturning( $this->mock_probe_return );
    }

    /**
     * Expose build_seeds_for_probe() as public for direct assertions.
     *
     * @param string $model_name
     * @param array  $shared_handles
     * @return array<int,array{handle:string,source_platform:string,source_url:string}>
     */
    public function build_seeds_for_probe_public( string $model_name, array $shared_handles = [] ): array {
        return $this->build_seeds_for_probe( $model_name, $shared_handles );
    }

    /**
     * Expose collect_shared_handles() as public for direct assertions.
     */
    public function collect_shared_handles_public(): array {
        return $this->collect_shared_handles();
    }
}

/**
 * Minimal ModelPlatformProbe subclass that always returns a preconfigured result.
 */
class MockProbeReturning extends ModelPlatformProbe {

    private array $fixed_result;

    public function __construct( array $fixed_result ) {
        $this->fixed_result = $fixed_result;
    }

    public function run( array $handle_seeds, array $already_confirmed, int $post_id ): array {
        return $this->fixed_result;
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Build a minimal successful SERP provider result fixture.
 *
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function make_serp_result( array $overrides = [] ): array {
    return array_merge( [
        'status'               => 'ok',
        'display_name'         => 'Anisyia',
        'aliases'              => [ 'Anisya' ],
        'bio'                  => 'Live cam performer.',
        'platform_names'       => [ 'Chaturbate', 'Fansly' ],
        'social_urls'          => [ 'https://x.com/anisyia', 'https://fansly.com/anisyia/posts' ],
        'platform_candidates'  => [
            [
                'source_url'          => 'https://chaturbate.com/anisyia/',
                'success'             => true,
                'username'            => 'anisyia',
                'normalized_platform' => 'chaturbate',
                'normalized_url'      => 'https://chaturbate.com/anisyia/',
                'reject_reason'       => '',
            ],
        ],
        'field_confidence'     => [ 'platform_names' => 45, 'social_urls' => 25, 'bio' => 35 ],
        'research_diagnostics' => [ 'query_stats' => [], 'source_class_counts' => [] ],
        'country'              => 'Romania',
        'language'             => 'ro',
        'source_urls'          => [ 'https://chaturbate.com/anisyia/' ],
        'confidence'           => 45,
        'notes'                => 'SERP: 5/5 queries succeeded.',
    ], $overrides );
}

/**
 * Build a minimal successful direct-probe provider result fixture.
 *
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function make_probe_result( array $overrides = [] ): array {
    return array_merge( [
        'status'               => 'ok',
        'display_name'         => 'Anisyia',
        'aliases'              => [],
        'bio'                  => '',
        'platform_names'       => [ 'CamSoda', 'Stripchat' ],
        'social_urls'          => [],
        'platform_candidates'  => [
            [
                'source_url'          => 'https://www.camsoda.com/Anisyia',
                'success'             => true,
                'username'            => 'Anisyia',
                'normalized_platform' => 'camsoda',
                'normalized_url'      => 'https://www.camsoda.com/Anisyia',
                'reject_reason'       => '',
                'discovered_via_probe'=> true,
            ],
            [
                'source_url'          => 'https://stripchat.com/Anisyia',
                'success'             => true,
                'username'            => 'Anisyia',
                'normalized_platform' => 'stripchat',
                'normalized_url'      => 'https://stripchat.com/Anisyia',
                'reject_reason'       => '',
                'discovered_via_probe'=> true,
            ],
        ],
        'field_confidence'     => [ 'platform_names' => 35, 'social_urls' => 0, 'bio' => 0 ],
        'research_diagnostics' => [ 'platform_probe' => [ 'probes_attempted' => 6, 'probes_accepted' => 2 ] ],
        'country'              => '',
        'language'             => '',
        'source_urls'          => [],
        'confidence'           => 35,
        'notes'                => 'Direct probe: 2 platform(s) found.',
    ], $overrides );
}

// ── Test class ────────────────────────────────────────────────────────────────

/**
 * Unit and integration tests for the hybrid provider architecture (v4.6.8).
 */
class ModelHybridPipelineTest extends TestCase {

    /**
     * Ensure safe mode is off for all provider tests (Settings::defaults() has safe_mode=1).
     * Settings::get() reads from the 'tmwseo_engine_settings' option array, so we must
     * override the nested key, not a top-level 'tmwseo_safe_mode' option.
     */
    protected function setUp(): void {
        $GLOBALS['_tmw_test_options']['tmwseo_engine_settings'] = [ 'safe_mode' => 0 ];
    }

    protected function tearDown(): void {
        unset( $GLOBALS['_tmw_test_options']['tmwseo_engine_settings'] );
    }

    // =========================================================================
    // A1. Scalar fields: first non-empty wins
    // =========================================================================

    /** @test */
    public function test_display_name_first_non_empty_wins(): void {
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result( [ 'display_name' => 'Anisyia' ] ),
            'direct_probe'    => make_probe_result( [ 'display_name' => 'AnisyiaXXX' ] ),
        ] );

        // SERP runs first (priority 10) → its value is kept.
        $this->assertSame( 'Anisyia', $merged['display_name'],
            'display_name must keep the first non-empty value (SERP).' );
    }

    /** @test */
    public function test_display_name_fallback_when_serp_blank(): void {
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result( [ 'display_name' => '' ] ),
            'direct_probe'    => make_probe_result( [ 'display_name' => 'AnisyiaFallback' ] ),
        ] );

        $this->assertSame( 'AnisyiaFallback', $merged['display_name'],
            'display_name must fall through to second provider when first is blank.' );
    }

    /** @test */
    public function test_bio_first_non_empty_wins_serp_preferred(): void {
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result( [ 'bio' => 'SERP bio text.' ] ),
            'direct_probe'    => make_probe_result( [ 'bio' => 'Probe bio text.' ] ),
        ] );

        $this->assertSame( 'SERP bio text.', $merged['bio'],
            'bio must prefer SERP over probe.' );
    }

    /** @test */
    public function test_bio_falls_through_when_serp_blank(): void {
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result( [ 'bio' => '' ] ),
            'direct_probe'    => make_probe_result( [ 'bio' => 'Probe bio fallback.' ] ),
        ] );

        $this->assertSame( 'Probe bio fallback.', $merged['bio'] );
    }

    /** @test */
    public function test_country_first_non_empty_wins(): void {
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result( [ 'country' => 'Romania' ] ),
            'direct_probe'    => make_probe_result( [ 'country' => 'France' ] ),
        ] );

        $this->assertSame( 'Romania', $merged['country'] );
    }

    // =========================================================================
    // A2. platform_names: union + dedupe + sorted
    // =========================================================================

    /** @test */
    public function test_platform_names_union_dedupe_sorted(): void {
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result( [ 'platform_names' => [ 'Chaturbate', 'Fansly' ] ] ),
            'direct_probe'    => make_probe_result( [ 'platform_names' => [ 'CamSoda', 'Chaturbate' ] ] ),
        ] );

        // Chaturbate appears in both → deduped to once. Sorted alphabetically.
        $this->assertSame(
            [ 'CamSoda', 'Chaturbate', 'Fansly' ],
            $merged['platform_names'],
            'platform_names must be unioned, deduped, and sorted.'
        );
    }

    /** @test */
    public function test_platform_names_from_single_provider_no_duplicates(): void {
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result( [ 'platform_names' => [ 'Stripchat', 'Stripchat' ] ] ),
        ] );

        $this->assertSame( [ 'Stripchat' ], $merged['platform_names'] );
    }

    // =========================================================================
    // A3. social_urls / source_urls / aliases: union + dedupe (insertion order)
    // =========================================================================

    /** @test */
    public function test_social_urls_union_dedupe_insertion_order(): void {
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result( [
                'social_urls' => [ 'https://x.com/anisyia', 'https://fansly.com/anisyia/posts' ],
            ] ),
            'direct_probe' => make_probe_result( [
                'social_urls' => [ 'https://fansly.com/anisyia/posts', 'https://linktr.ee/anisyia' ],
            ] ),
        ] );

        // Fansly URL deduped; Linktree appended at end.
        $this->assertCount( 3, $merged['social_urls'] );
        $this->assertContains( 'https://x.com/anisyia', $merged['social_urls'] );
        $this->assertContains( 'https://fansly.com/anisyia/posts', $merged['social_urls'] );
        $this->assertContains( 'https://linktr.ee/anisyia', $merged['social_urls'] );
    }

    /** @test */
    public function test_aliases_union_dedupe(): void {
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result( [ 'aliases' => [ 'Anisya', 'AnisCam' ] ] ),
            'direct_probe'    => make_probe_result( [ 'aliases' => [ 'AnisCam', 'AnisXXX' ] ] ),
        ] );

        $this->assertCount( 3, $merged['aliases'] );
        $this->assertNotContains( '', $merged['aliases'] );
    }

    // =========================================================================
    // A4. platform_candidates: append + dedupe by canonical key; _provider tag
    // =========================================================================

    /** @test */
    public function test_platform_candidates_append_all_providers(): void {
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result(),   // 1 candidate (chaturbate)
            'direct_probe'    => make_probe_result(),   // 2 candidates (camsoda, stripchat)
        ] );

        // All three distinct platform candidates should be present.
        $this->assertCount( 3, $merged['platform_candidates'] );
    }

    /** @test */
    public function test_platform_candidates_deduped_on_success_key(): void {
        // Both providers found Chaturbate with the same username — dedup to one row.
        $shared_candidate = [
            'source_url'          => 'https://chaturbate.com/anisyia/',
            'success'             => true,
            'username'            => 'anisyia',
            'normalized_platform' => 'chaturbate',
            'normalized_url'      => 'https://chaturbate.com/anisyia/',
            'reject_reason'       => '',
        ];

        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result( [ 'platform_candidates' => [ $shared_candidate ] ] ),
            'direct_probe'    => make_probe_result( [ 'platform_candidates' => [ $shared_candidate ] ] ),
        ] );

        $chaturbate_rows = array_filter(
            $merged['platform_candidates'],
            static fn( array $c ): bool => ( $c['normalized_platform'] ?? '' ) === 'chaturbate'
        );
        $this->assertCount( 1, $chaturbate_rows,
            'Successful candidates with same platform+username must be deduped to one row.' );
    }

    /** @test */
    public function test_platform_candidates_tagged_with_provider_name(): void {
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result(),
            'direct_probe'    => make_probe_result(),
        ] );

        foreach ( $merged['platform_candidates'] as $candidate ) {
            $this->assertArrayHasKey( '_provider', $candidate,
                'Every platform_candidate must be tagged with a _provider key.' );
            $this->assertContains(
                $candidate['_provider'],
                [ 'dataforseo_serp', 'direct_probe' ],
                '_provider must match a registered provider name.'
            );
        }
    }

    /** @test */
    public function test_first_provider_row_wins_on_dedup(): void {
        // SERP candidate should survive dedup (first occurrence wins).
        $serp_candidate  = [
            'source_url' => 'https://chaturbate.com/anisyia/',
            'success'    => true, 'username' => 'anisyia',
            'normalized_platform' => 'chaturbate', 'normalized_url' => 'https://chaturbate.com/anisyia/',
            'reject_reason' => '',
        ];
        $probe_candidate = array_merge( $serp_candidate, [ 'discovered_via_probe' => true ] );

        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result( [ 'platform_candidates' => [ $serp_candidate ] ] ),
            'direct_probe'    => make_probe_result( [ 'platform_candidates' => [ $probe_candidate ] ] ),
        ] );

        $kept = array_values( array_filter(
            $merged['platform_candidates'],
            static fn( array $c ): bool => ( $c['normalized_platform'] ?? '' ) === 'chaturbate'
        ) );
        $this->assertCount( 1, $kept );
        $this->assertSame( 'dataforseo_serp', $kept[0]['_provider'],
            'First provider\'s row must survive deduplication.' );
    }

    // =========================================================================
    // A5. field_confidence: per-key maximum
    // =========================================================================

    /** @test */
    public function test_field_confidence_per_key_maximum(): void {
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result( [ 'field_confidence' => [ 'platform_names' => 45, 'social_urls' => 25, 'bio' => 35 ] ] ),
            'direct_probe'    => make_probe_result( [ 'field_confidence' => [ 'platform_names' => 35, 'social_urls' => 0,  'bio' => 0  ] ] ),
        ] );

        // platform_names: max(45, 35) = 45
        $this->assertSame( 45, $merged['field_confidence']['platform_names'] );
        // social_urls: max(25, 0) = 25
        $this->assertSame( 25, $merged['field_confidence']['social_urls'] );
        // bio: max(35, 0) = 35
        $this->assertSame( 35, $merged['field_confidence']['bio'] );
    }

    /** @test */
    public function test_field_confidence_probe_can_win_for_its_fields(): void {
        // If probe has higher platform confidence than SERP (e.g. SERP returned partial)
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result( [ 'field_confidence' => [ 'platform_names' => 10 ] ] ),
            'direct_probe'    => make_probe_result( [ 'field_confidence' => [ 'platform_names' => 50 ] ] ),
        ] );

        $this->assertSame( 50, $merged['field_confidence']['platform_names'] );
    }

    // =========================================================================
    // A6. research_diagnostics: nested per-provider
    // =========================================================================

    /** @test */
    public function test_research_diagnostics_nested_per_provider(): void {
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result(),
            'direct_probe'    => make_probe_result(),
        ] );

        $diag = $merged['research_diagnostics'];
        $this->assertArrayHasKey( 'providers', $diag,
            'research_diagnostics must have a "providers" key.' );
        $this->assertArrayHasKey( 'dataforseo_serp', $diag['providers'],
            'SERP diagnostics must be nested under providers.dataforseo_serp.' );
        $this->assertArrayHasKey( 'direct_probe', $diag['providers'],
            'Probe diagnostics must be nested under providers.direct_probe.' );
    }

    /** @test */
    public function test_research_diagnostics_second_provider_does_not_overwrite_first(): void {
        $serp_diag  = [ 'query_stats' => [ 'q1' => 'ok' ], 'custom_serp_key' => 'serp_value' ];
        $probe_diag = [ 'platform_probe' => [ 'probes_attempted' => 6 ] ];

        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result( [ 'research_diagnostics' => $serp_diag ] ),
            'direct_probe'    => make_probe_result( [ 'research_diagnostics' => $probe_diag ] ),
        ] );

        $serp_stored  = $merged['research_diagnostics']['providers']['dataforseo_serp']['data'] ?? [];
        $probe_stored = $merged['research_diagnostics']['providers']['direct_probe']['data'] ?? [];

        $this->assertArrayHasKey( 'custom_serp_key', $serp_stored,
            'SERP diagnostics must be preserved intact.' );
        $this->assertArrayHasKey( 'platform_probe', $probe_stored,
            'Probe diagnostics must be preserved intact.' );
    }

    /** @test */
    public function test_research_diagnostics_summary_present(): void {
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result(),
            'direct_probe'    => make_probe_result(),
        ] );

        $this->assertArrayHasKey( 'summary', $merged['research_diagnostics'] );
        $summary = $merged['research_diagnostics']['summary'];
        $this->assertSame( 2, $summary['providers_run'] );
        $this->assertSame( 2, $summary['providers_with_data'] );
    }

    // =========================================================================
    // A7. confidence: corroboration bonus, capped at 90
    // =========================================================================

    /** @test */
    public function test_confidence_corroboration_bonus_when_both_providers_have_data(): void {
        // SERP confidence 45, probe confidence 35.
        // Both have platforms → base=45, +5 bonus → 50.
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result( [ 'confidence' => 45 ] ),
            'direct_probe'    => make_probe_result( [ 'confidence' => 35 ] ),
        ] );

        $this->assertSame( 50, $merged['confidence'],
            'Confidence must be max(45,35) + 5 corroboration bonus = 50.' );
    }

    /** @test */
    public function test_confidence_no_bonus_when_only_one_provider_has_data(): void {
        // Only SERP has platforms (confidence > 0). Probe confidence = 0.
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result( [ 'confidence' => 45 ] ),
            'direct_probe'    => make_probe_result( [ 'confidence' => 0, 'platform_names' => [] ] ),
        ] );

        // Probe has 0 confidence → not counted as "provider with data".
        $this->assertSame( 45, $merged['confidence'],
            'No bonus when only one provider has non-zero confidence.' );
    }

    /** @test */
    public function test_confidence_capped_at_90(): void {
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result( [ 'confidence' => 88 ] ),
            'direct_probe'    => make_probe_result( [ 'confidence' => 50 ] ),
        ] );

        $this->assertSame( 90, $merged['confidence'],
            'Confidence must be capped at 90.' );
    }

    // =========================================================================
    // A8. notes: prefixed, pipe-joined
    // =========================================================================

    /** @test */
    public function test_notes_prefixed_and_pipe_joined(): void {
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result( [ 'notes' => 'SERP note.' ] ),
            'direct_probe'    => make_probe_result( [ 'notes' => 'Probe note.' ] ),
        ] );

        $this->assertStringContainsString( '[dataforseo_serp]', $merged['notes'] );
        $this->assertStringContainsString( '[direct_probe]',    $merged['notes'] );
        $this->assertStringContainsString( 'SERP note.',        $merged['notes'] );
        $this->assertStringContainsString( 'Probe note.',       $merged['notes'] );
        $this->assertStringContainsString( '|',                 $merged['notes'] );
    }

    // =========================================================================
    // B. ModelDirectProbeProvider
    // =========================================================================

    /** @test */
    public function test_provider_name_is_direct_probe(): void {
        $provider = $this->make_provider_with_empty_probe();
        $this->assertSame( 'direct_probe', $provider->provider_name() );
    }

    /** @test */
    public function test_build_name_seeds_strips_non_alphanumeric(): void {
        $provider = $this->make_provider_with_empty_probe();
        // No shared handles → falls through to name-derived
        $seeds = $provider->build_seeds_for_probe_public( 'Abby Murray' );

        $this->assertNotEmpty( $seeds );
        $handles = array_column( $seeds, 'handle' );
        // Name-derived seed must contain the stripped name
        $this->assertContains( 'AbbyMurray', $handles );
    }

    /** @test */
    public function test_build_name_seeds_preserves_camel_case(): void {
        $provider = $this->make_provider_with_empty_probe();
        $seeds    = $provider->build_seeds_for_probe_public( 'Anisyia' );

        $handles = array_column( $seeds, 'handle' );
        $this->assertContains( 'Anisyia', $handles );
    }

    /** @test */
    public function test_build_name_seeds_empty_for_punctuation_only(): void {
        $provider = $this->make_provider_with_empty_probe();
        $seeds    = $provider->build_seeds_for_probe_public( '--- !!!' );
        $this->assertSame( [], $seeds );
    }

    /** @test */
    public function test_lookup_returns_ok_when_probe_finds_platforms(): void {
        $verified_urls = [
            'https://chaturbate.com/anisyia' => [
                'slug'        => 'chaturbate',
                'username'    => 'anisyia',
                'http_status' => 200,
                'handle'      => 'Anisyia',
                'parse'       => [
                    'success'             => true,
                    'username'            => 'anisyia',
                    'normalized_platform' => 'chaturbate',
                    'normalized_url'      => 'https://chaturbate.com/anisyia',
                    'reject_reason'       => '',
                ],
            ],
        ];
        $probe_return  = [
            'verified_urls' => $verified_urls,
            'diagnostics'   => [
                'seeds_used' => 1, 'probes_attempted' => 6, 'probes_accepted' => 1,
                'probes_rejected' => 5, 'get_fallbacks_used' => 0,
                'seed_priorities' => [ 'Anisyia' => 1 ], 'probe_log' => [],
            ],
        ];

        $provider = new TestableDirectProbeProvider( $probe_return );
        $result   = $provider->lookup( 1, 'Anisyia' );

        $this->assertSame( 'ok', $result['status'] );
        $this->assertContains( 'Chaturbate', $result['platform_names'] );
        $this->assertNotEmpty( $result['platform_candidates'] );
        $this->assertSame( 'Anisyia', $result['display_name'] );
    }

    /** @test */
    public function test_lookup_returns_partial_when_probe_finds_nothing(): void {
        $probe_return = [
            'verified_urls' => [],
            'diagnostics'   => [
                'seeds_used' => 1, 'probes_attempted' => 6, 'probes_accepted' => 0,
                'probes_rejected' => 6, 'get_fallbacks_used' => 0,
                'seed_priorities' => [], 'probe_log' => [],
            ],
        ];

        $provider = new TestableDirectProbeProvider( $probe_return );
        $result   = $provider->lookup( 1, 'Anisyia' );

        $this->assertSame( 'partial', $result['status'] );
        $this->assertSame( [], $result['platform_names'] );
        $this->assertSame( [], $result['platform_candidates'] );
        $this->assertSame( 0, $result['confidence'] );
    }

    /** @test */
    public function test_lookup_platform_candidates_tagged_discovered_via_probe(): void {
        $probe_return = [
            'verified_urls' => [
                'https://chaturbate.com/anisyia' => [
                    'slug' => 'chaturbate', 'username' => 'anisyia', 'http_status' => 200,
                    'handle' => 'Anisyia',
                    'parse' => [
                        'success' => true, 'username' => 'anisyia',
                        'normalized_platform' => 'chaturbate',
                        'normalized_url' => 'https://chaturbate.com/anisyia',
                        'reject_reason' => '',
                    ],
                ],
            ],
            'diagnostics' => [
                'seeds_used' => 1, 'probes_attempted' => 1, 'probes_accepted' => 1,
                'probes_rejected' => 0, 'get_fallbacks_used' => 0,
                'seed_priorities' => [], 'probe_log' => [],
            ],
        ];

        $provider = new TestableDirectProbeProvider( $probe_return );
        $result   = $provider->lookup( 1, 'Anisyia' );

        foreach ( $result['platform_candidates'] as $candidate ) {
            $this->assertTrue(
                $candidate['discovered_via_probe'] ?? false,
                'All probe-sourced candidates must be tagged discovered_via_probe = true.'
            );
        }
    }

    /** @test */
    public function test_lookup_leaves_bio_social_country_blank(): void {
        $probe_return = [
            'verified_urls' => [],
            'diagnostics'   => [
                'seeds_used' => 0, 'probes_attempted' => 0, 'probes_accepted' => 0,
                'probes_rejected' => 0, 'get_fallbacks_used' => 0,
                'seed_priorities' => [], 'probe_log' => [],
            ],
        ];

        $provider = new TestableDirectProbeProvider( $probe_return );
        $result   = $provider->lookup( 1, 'SomeModel' );

        $this->assertSame( '', $result['bio'],      'Probe provider must leave bio blank.' );
        $this->assertSame( [], $result['social_urls'], 'Probe provider must leave social_urls blank.' );
        $this->assertSame( '', $result['country'],  'Probe provider must leave country blank.' );
        $this->assertSame( '', $result['language'], 'Probe provider must leave language blank.' );
    }

    /** @test */
    public function test_lookup_safe_mode_returns_no_provider(): void {
        // Override with safe_mode = 1 inside the nested settings array.
        $GLOBALS['_tmw_test_options']['tmwseo_engine_settings'] = [ 'safe_mode' => 1 ];

        $provider = $this->make_provider_with_empty_probe();
        $result   = $provider->lookup( 1, 'Anisyia' );

        // Restore safe mode off for subsequent tests in this class.
        $GLOBALS['_tmw_test_options']['tmwseo_engine_settings'] = [ 'safe_mode' => 0 ];

        $this->assertSame( 'no_provider', $result['status'],
            'Safe mode must suppress the direct probe provider.' );
    }

    /** @test */
    public function test_make_probe_returns_platform_probe_instance(): void {
        $provider = new class extends ModelDirectProbeProvider {
            public function make_probe_public(): ModelPlatformProbe {
                return $this->make_probe();
            }
        };

        $this->assertInstanceOf( ModelPlatformProbe::class, $provider->make_probe_public() );
    }

    // =========================================================================
    // C. Pipeline integration
    // =========================================================================

    /** @test */
    public function test_single_provider_merge_still_works(): void {
        // Regression: the new merge must not break single-provider behaviour.
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result(),
        ] );

        $this->assertSame( 'Anisyia', $merged['display_name'] );
        $this->assertContains( 'Chaturbate', $merged['platform_names'] );
        $this->assertNotEmpty( $merged['social_urls'] );
        $this->assertSame( 45, $merged['confidence'] );
    }

    /** @test */
    public function test_failed_provider_result_stored_in_diagnostics_not_merged(): void {
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result(),
            'direct_probe'    => [ 'status' => 'error', 'message' => 'Connection timeout.' ],
        ] );

        // Failed provider stored in diagnostics
        $probe_diag = $merged['research_diagnostics']['providers']['direct_probe'] ?? [];
        $this->assertSame( 'error', $probe_diag['status'] ?? '' );
        $this->assertSame( 'Connection timeout.', $probe_diag['message'] ?? '' );

        // Failed provider's empty data must NOT overwrite SERP results
        $this->assertSame( 'Anisyia', $merged['display_name'],
            'display_name must not be cleared by a failed provider.' );
        $this->assertNotEmpty( $merged['platform_names'],
            'platform_names must not be cleared by a failed provider.' );
    }

    /** @test */
    public function test_no_provider_result_stored_in_diagnostics(): void {
        $merged = ModelResearchPipeline::merge_results( [
            'stub' => [ 'status' => 'no_provider', 'message' => 'No creds.' ],
        ] );

        $this->assertSame( 'no_provider',
            $merged['research_diagnostics']['providers']['stub']['status'] ?? '' );
        $this->assertSame( '', $merged['display_name'] );
        $this->assertSame( [], $merged['platform_names'] );
    }

    /** @test */
    public function test_merged_output_does_not_auto_apply(): void {
        // The merged result is proposed data only. Verify it has no "live" keys
        // that would indicate auto-application (it's just an array returned for review).
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result(),
            'direct_probe'    => make_probe_result(),
        ] );

        // These keys must exist (proposed data blob is complete)
        $required_keys = [
            'display_name', 'aliases', 'bio', 'platform_names', 'social_urls',
            'platform_candidates', 'field_confidence', 'research_diagnostics',
            'country', 'language', 'source_urls', 'confidence', 'notes',
        ];
        foreach ( $required_keys as $key ) {
            $this->assertArrayHasKey( $key, $merged, "Merged result must have key '{$key}'." );
        }

        // Verify no auto-applied "post_id" or "applied_at" fields sneak in
        $this->assertArrayNotHasKey( 'post_id',    $merged );
        $this->assertArrayNotHasKey( 'applied_at', $merged );
    }

    // =========================================================================
    // D. Round-trip: SERP + probe, smart merge
    // =========================================================================

    /** @test */
    public function test_round_trip_two_providers_complement_each_other(): void {
        // SERP: found Chaturbate + Fansly + social URLs + bio + country
        // Probe: found CamSoda + Stripchat (missed by SERP)
        // Expected merged: all four platforms, both social URLs, SERP bio/country
        $merged = ModelResearchPipeline::merge_results( [
            'dataforseo_serp' => make_serp_result(),
            'direct_probe'    => make_probe_result(),
        ] );

        // All four platforms present
        $this->assertContains( 'CamSoda',    $merged['platform_names'] );
        $this->assertContains( 'Chaturbate', $merged['platform_names'] );
        $this->assertContains( 'Fansly',     $merged['platform_names'] );
        $this->assertContains( 'Stripchat',  $merged['platform_names'] );

        // SERP wins on scalar fields
        $this->assertSame( 'Live cam performer.', $merged['bio'] );
        $this->assertSame( 'Romania', $merged['country'] );

        // Social URLs from SERP preserved (probe adds none)
        $this->assertCount( 2, $merged['social_urls'] );

        // All 3 platform candidates (1 SERP + 2 probe) present
        $this->assertCount( 3, $merged['platform_candidates'] );

        // Diagnostics nested per provider
        $this->assertArrayHasKey( 'dataforseo_serp', $merged['research_diagnostics']['providers'] );
        $this->assertArrayHasKey( 'direct_probe',    $merged['research_diagnostics']['providers'] );

        // Corroboration bonus applied (both providers have data)
        $this->assertGreaterThan( 45, $merged['confidence'],
            'Confidence must be boosted above SERP-only baseline when probe also finds data.' );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a TestableDirectProbeProvider that returns an empty verified_urls result.
     */
    private function make_provider_with_empty_probe(): TestableDirectProbeProvider {
        return new TestableDirectProbeProvider( [
            'verified_urls' => [],
            'diagnostics'   => [
                'seeds_used' => 0, 'probes_attempted' => 0, 'probes_accepted' => 0,
                'probes_rejected' => 0, 'get_fallbacks_used' => 0,
                'seed_priorities' => [], 'probe_log' => [],
            ],
        ] );
    }
}
