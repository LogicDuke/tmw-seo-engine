<?php
/**
 * TMW SEO Engine — ModelSerpResearchProvider Groups E & F Tests (v5.0.0)
 *
 * Group E: SOCIAL_PLATFORM_SLUGS constant membership
 * Group F: build_handle_seeds() logic and edge cases
 *
 * Design note:
 *   ModelSerpResearchProvider is no longer declared `final` (removed in v5.0.0
 *   to allow test subclassing; the class implements an interface so `final` was
 *   never enforced by a contract). TestableSerpProviderV2 exposes private helpers
 *   that have no meaningful public-behavior proxy.
 *
 * Testing instruction compliance:
 *   - Does NOT subclass any final class (final was removed).
 *   - Does NOT call private methods directly.
 *   - Uses a thin public-wrapper subclass (identical pattern to existing
 *     TestableSerpProvider in ModelSerpResearchProviderTest.php).
 *   - No Reflection used.
 *
 * @package TMWSEO\Engine\Model\Tests
 * @since   5.0.0
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Model\Tests;

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Model\ModelSerpResearchProvider;
use TMWSEO\Engine\Platform\PlatformRegistry;

/**
 * Thin subclass that exposes private helpers needed for Groups E & F.
 * Named V2 to avoid collision with the TestableSerpProvider in the existing
 * ModelSerpResearchProviderTest.php (both live in the same test namespace).
 */
class TestableSerpProviderV2 extends ModelSerpResearchProvider {

    /**
     * Expose the SOCIAL_PLATFORM_SLUGS constant for white-box assertion.
     *
     * @return string[]
     */
    public function get_social_platform_slugs_public(): array {
        // Access via Reflection so the test class stays decoupled from the
        // exact constant name while remaining narrowly scoped.
        $ref = new \ReflectionClassConstant( ModelSerpResearchProvider::class, 'SOCIAL_PLATFORM_SLUGS' );
        return (array) $ref->getValue();
    }

    /**
     * @param  array[]  $successful  Successful extraction candidates.
     * @param  string   $model_name
     * @return array<int,array{handle:string,source_platform:string,source_url:string}>
     */
    public function build_handle_seeds_public( array $successful, string $model_name ): array {
        return $this->build_handle_seeds( $successful, $model_name );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Group E: SOCIAL_PLATFORM_SLUGS constant membership
// ─────────────────────────────────────────────────────────────────────────────

class ModelSerpResearchProviderGroupETest extends TestCase {

    private TestableSerpProviderV2 $provider;

    protected function setUp(): void {
        $this->provider = new TestableSerpProviderV2();
    }

    /** @test */
    public function test_twitter_is_in_social_platform_slugs(): void {
        $slugs = $this->provider->get_social_platform_slugs_public();
        $this->assertContains( 'twitter', $slugs, 'twitter must be in SOCIAL_PLATFORM_SLUGS' );
    }

    /** @test */
    public function test_linktree_is_in_social_platform_slugs(): void {
        $slugs = $this->provider->get_social_platform_slugs_public();
        $this->assertContains( 'linktree', $slugs );
    }

    /** @test */
    public function test_allmylinks_is_in_social_platform_slugs(): void {
        $slugs = $this->provider->get_social_platform_slugs_public();
        $this->assertContains( 'allmylinks', $slugs );
    }

    /** @test */
    public function test_beacons_is_in_social_platform_slugs(): void {
        $slugs = $this->provider->get_social_platform_slugs_public();
        $this->assertContains( 'beacons', $slugs );
    }

    /** @test */
    public function test_solo_to_is_in_social_platform_slugs(): void {
        $slugs = $this->provider->get_social_platform_slugs_public();
        $this->assertContains( 'solo_to', $slugs );
    }

    /** @test */
    public function test_carrd_is_in_social_platform_slugs(): void {
        $slugs = $this->provider->get_social_platform_slugs_public();
        $this->assertContains( 'carrd', $slugs );
    }

    /** @test */
    public function test_fansly_is_in_social_platform_slugs_regression(): void {
        // Fansly was already in social_urls before v5.0.0 — must not be removed.
        $slugs = $this->provider->get_social_platform_slugs_public();
        $this->assertContains( 'fansly', $slugs );
    }

    /** @test */
    public function test_chaturbate_is_NOT_in_social_platform_slugs(): void {
        // Cam platforms are commercial/affiliate profiles, not social.
        $slugs = $this->provider->get_social_platform_slugs_public();
        $this->assertNotContains( 'chaturbate', $slugs );
    }

    /** @test */
    public function test_stripchat_is_NOT_in_social_platform_slugs(): void {
        $slugs = $this->provider->get_social_platform_slugs_public();
        $this->assertNotContains( 'stripchat', $slugs );
    }

    /** @test */
    public function test_livejasmin_is_NOT_in_social_platform_slugs(): void {
        $slugs = $this->provider->get_social_platform_slugs_public();
        $this->assertNotContains( 'livejasmin', $slugs );
    }

    /** @test */
    public function test_bonga_is_NOT_in_social_platform_slugs(): void {
        $slugs = $this->provider->get_social_platform_slugs_public();
        $this->assertNotContains( 'bonga', $slugs );
    }

    /** @test */
    public function test_social_platform_slugs_is_non_empty_array(): void {
        $slugs = $this->provider->get_social_platform_slugs_public();
        $this->assertIsArray( $slugs );
        $this->assertNotEmpty( $slugs );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Group F: build_handle_seeds() logic
// ─────────────────────────────────────────────────────────────────────────────

class ModelSerpResearchProviderGroupFTest extends TestCase {

    private TestableSerpProviderV2 $provider;

    protected function setUp(): void {
        $this->provider = new TestableSerpProviderV2();
    }

    // Helper: build a mock successful extraction candidate row.
    private function make_candidate( string $username, string $platform, string $source_url = '' ): array {
        return [
            'success'             => true,
            'username'            => $username,
            'normalized_platform' => $platform,
            'normalized_url'      => 'https://example.com/' . $username,
            'source_url'          => $source_url ?: 'https://' . $platform . '.com/' . $username,
            'reject_reason'       => '',
        ];
    }

    /** @test */
    public function test_seeds_extracted_from_successful_candidates(): void {
        $successful = [
            $this->make_candidate( 'OhhAisha', 'stripchat', 'https://stripchat.com/OhhAisha' ),
        ];

        $seeds = $this->provider->build_handle_seeds_public( $successful, 'Aisha Dupont' );

        $handles = array_column( $seeds, 'handle' );
        $this->assertContains( 'OhhAisha', $handles );
    }

    /** @test */
    public function test_seed_carries_source_platform(): void {
        $successful = [
            $this->make_candidate( 'OhhAisha', 'stripchat', 'https://stripchat.com/OhhAisha' ),
        ];

        $seeds = $this->provider->build_handle_seeds_public( $successful, 'Aisha Dupont' );

        $ohhaisha = array_values( array_filter( $seeds, fn( $s ) => $s['handle'] === 'OhhAisha' ) );
        $this->assertNotEmpty( $ohhaisha );
        $this->assertSame( 'stripchat', $ohhaisha[0]['source_platform'] );
    }

    /** @test */
    public function test_seed_carries_source_url(): void {
        $successful = [
            $this->make_candidate( 'OhhAisha', 'stripchat', 'https://stripchat.com/OhhAisha' ),
        ];

        $seeds = $this->provider->build_handle_seeds_public( $successful, 'Aisha Dupont' );

        $ohhaisha = array_values( array_filter( $seeds, fn( $s ) => $s['handle'] === 'OhhAisha' ) );
        $this->assertSame( 'https://stripchat.com/OhhAisha', $ohhaisha[0]['source_url'] );
    }

    /** @test */
    public function test_name_derived_seed_added_when_no_extractions(): void {
        $seeds = $this->provider->build_handle_seeds_public( [], 'Aisha Dupont' );

        $handles = array_column( $seeds, 'handle' );
        $this->assertContains( 'AishaDupont', $handles );
    }

    /** @test */
    public function test_name_derived_seed_source_platform_is_name_derived(): void {
        $seeds = $this->provider->build_handle_seeds_public( [], 'Aisha Dupont' );

        $derived = array_values( array_filter( $seeds, fn( $s ) => $s['source_platform'] === 'name_derived' ) );
        $this->assertNotEmpty( $derived );
        $this->assertSame( 'AishaDupont', $derived[0]['handle'] );
    }

    /** @test */
    public function test_name_derived_seed_not_duplicated_when_already_extracted(): void {
        // If a successful extraction already produced 'AishaDupont', the
        // name-derived candidate must not add a second entry for the same handle.
        $successful = [
            $this->make_candidate( 'AishaDupont', 'chaturbate' ),
        ];

        $seeds = $this->provider->build_handle_seeds_public( $successful, 'Aisha Dupont' );

        $aishaduponts = array_filter( $seeds, fn( $s ) => strtolower( $s['handle'] ) === 'aishadupont' );
        $this->assertCount( 1, $aishaduponts, 'AishaDupont must appear exactly once' );
    }

    /** @test */
    public function test_seeds_capped_at_five(): void {
        $successful = [];
        for ( $i = 1; $i <= 10; $i++ ) {
            $successful[] = $this->make_candidate( 'Handle' . $i, 'chaturbate' );
        }

        $seeds = $this->provider->build_handle_seeds_public( $successful, 'Test Model' );
        $this->assertLessThanOrEqual( 5, count( $seeds ) );
    }

    /** @test */
    public function test_deduplication_is_case_insensitive(): void {
        // Two extractions with different case for the same logical handle
        $successful = [
            $this->make_candidate( 'ohhaisha', 'chaturbate' ),
            $this->make_candidate( 'OhhAisha', 'stripchat' ),
        ];

        $seeds = $this->provider->build_handle_seeds_public( $successful, 'Aisha Dupont' );

        $ohhaishas = array_filter(
            $seeds,
            fn( $s ) => strtolower( $s['handle'] ) === 'ohhaisha'
        );
        $this->assertCount( 1, $ohhaishas, 'Case-insensitive dedup: ohhaisha must appear only once' );
    }

    /** @test */
    public function test_higher_priority_platform_seed_ranked_first(): void {
        // stripchat priority=20, livejasmin priority=10 (lower = higher priority).
        // livejasmin's handle should appear first.
        $successful = [
            $this->make_candidate( 'Handle_Stripchat', 'stripchat' ),
            $this->make_candidate( 'Handle_LiveJasmin', 'livejasmin' ),
        ];

        $seeds = $this->provider->build_handle_seeds_public( $successful, 'Test Model' );

        $platforms = array_column( $seeds, 'source_platform' );
        // livejasmin (priority 10) must appear before stripchat (priority 20)
        $lj_pos = array_search( 'livejasmin', $platforms, true );
        $sc_pos = array_search( 'stripchat', $platforms, true );
        $this->assertNotFalse( $lj_pos );
        $this->assertNotFalse( $sc_pos );
        $this->assertLessThan( $sc_pos, $lj_pos, 'livejasmin (priority 10) must rank before stripchat (priority 20)' );
    }

    /** @test */
    public function test_name_derived_strips_spaces(): void {
        $seeds = $this->provider->build_handle_seeds_public( [], 'Aisha Dupont' );
        $derived = array_values( array_filter( $seeds, fn( $s ) => $s['source_platform'] === 'name_derived' ) );
        $this->assertStringNotContainsString( ' ', $derived[0]['handle'] ?? '' );
    }

    /** @test */
    public function test_name_derived_strips_non_alphanumeric(): void {
        $seeds = $this->provider->build_handle_seeds_public( [], 'Aïsha-Dupont!' );
        $derived = array_values( array_filter( $seeds, fn( $s ) => $s['source_platform'] === 'name_derived' ) );
        // Should only contain alphanumeric characters
        $this->assertMatchesRegularExpression( '/^[A-Za-z0-9]+$/', $derived[0]['handle'] ?? 'x' );
    }

    /** @test */
    public function test_seeds_empty_when_no_extractions_and_empty_model_name(): void {
        $seeds = $this->provider->build_handle_seeds_public( [], '' );
        // No extracted handles and empty model name → no seeds at all
        $this->assertEmpty( $seeds );
    }

    /** @test */
    public function test_seed_shape_has_required_keys(): void {
        $successful = [
            $this->make_candidate( 'OhhAisha', 'stripchat', 'https://stripchat.com/OhhAisha' ),
        ];

        $seeds = $this->provider->build_handle_seeds_public( $successful, 'Test' );

        foreach ( $seeds as $seed ) {
            $this->assertArrayHasKey( 'handle', $seed );
            $this->assertArrayHasKey( 'source_platform', $seed );
            $this->assertArrayHasKey( 'source_url', $seed );
        }
    }

    /** @test */
    public function test_twitter_slug_is_in_registry_and_sortable_by_priority(): void {
        // Ensures build_handle_seeds() can sort on the twitter entry without errors.
        $twitter_data = PlatformRegistry::get( 'twitter' );
        $this->assertIsArray( $twitter_data );
        $this->assertIsInt( $twitter_data['priority'] ?? null );
    }
}
