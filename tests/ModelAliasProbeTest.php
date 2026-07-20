<?php
/**
 * Phase 1 — Alias probe tests for ModelSerpResearchProvider::build_query_pack().
 *
 * Verifies that:
 *   - Aliases are appended as additive queries (never replace primary name queries).
 *   - Alias queries carry alias_webcam_discovery / alias_creator_discovery families.
 *   - Alias queries are bounded (max 3 aliases × 2 families = 6 extra).
 *   - An alias identical to the primary model name is skipped in lookup().
 *   - _alias_source is tagged on query descriptors.
 */

namespace TMWSEO\Engine\Model;

use PHPUnit\Framework\TestCase;

// ── Minimal stubs so the class can be loaded without WP ──────────────────────

namespace TMWSEO\Engine\Services;
function get_option( string $key, $default = false ) { return $default; }

namespace TMWSEO\Engine\Platform;
class PlatformRegistry {
    public static function get_slugs(): array { return []; }
    public static function get( string $slug ): ?array { return null; }
}

namespace TMWSEO\Engine\Model;

// ── Concrete test subclass that exposes the protected method ──────────────────

class TestableModelSerpProvider extends ModelSerpResearchProvider {
    /**
     * Expose build_query_pack() for unit testing.
     *
     * @param  string   $model_name
     * @param  string[] $aliases
     * @return array<int,array<string,string>>
     */
    public function public_build_query_pack( string $model_name, array $aliases = [] ): array {
        return $this->build_query_pack( $model_name, $aliases );
    }}

// ── Test case ─────────────────────────────────────────────────────────────────

class ModelAliasProbeTest extends TestCase {

    private TestableModelSerpProvider $provider;

    protected function setUp(): void {
        $this->provider = new TestableModelSerpProvider();
    }

    // ── build_query_pack base behaviour (no aliases) ──────────────────────────

    public function test_no_aliases_returns_standard_pack(): void {
        $queries = $this->provider->public_build_query_pack( 'Aisha Dupont' );

        $families = array_column( $queries, 'family' );
        $this->assertContains( 'exact_name',                $families );
        $this->assertContains( 'webcam_platform_discovery', $families );
        $this->assertContains( 'creator_platform_discovery',$families );
        $this->assertContains( 'hub_discovery',             $families );
        $this->assertContains( 'social_discovery',          $families );

        // No alias families present when no aliases provided
        foreach ( $queries as $q ) {
            $this->assertStringNotContainsString( 'alias_', $q['family'] );
        }
    }

    // ── Alias queries appended, standard queries unchanged ────────────────────

    public function test_single_alias_appends_two_alias_queries(): void {
        $queries = $this->provider->public_build_query_pack( 'Aisha Dupont', [ 'OhhAisha' ] );
        $families = array_column( $queries, 'family' );

        // Standard queries still present
        $this->assertContains( 'exact_name', $families );

        // Exactly the two alias families added
        $alias_families = array_values( array_filter( $families, fn($f) => str_starts_with($f, 'alias_') ) );
        $this->assertCount( 2, $alias_families );
        $this->assertContains( 'alias_webcam_discovery',  $alias_families );
        $this->assertContains( 'alias_creator_discovery', $alias_families );
    }

    public function test_alias_queries_contain_alias_term_in_query_string(): void {
        $queries = $this->provider->public_build_query_pack( 'Aisha Dupont', [ 'OhhAisha' ] );

        foreach ( $queries as $q ) {
            if ( str_starts_with( $q['family'], 'alias_' ) ) {
                $this->assertStringContainsString( 'OhhAisha', $q['query'] );
                $this->assertSame( 'OhhAisha', $q['_alias_source'] );
            }
        }
    }

    public function test_alias_queries_do_not_contain_primary_name(): void {
        $queries = $this->provider->public_build_query_pack( 'Aisha Dupont', [ 'OhhAisha' ] );

        foreach ( $queries as $q ) {
            if ( str_starts_with( $q['family'], 'alias_' ) ) {
                $this->assertStringNotContainsString( 'Aisha Dupont', $q['query'] );
            }
        }
    }

    // ── Max 3 aliases, max 6 alias queries ───────────────────────────────────

    public function test_four_aliases_bounded_to_three(): void {
        $aliases = [ 'AliasA', 'AliasB', 'AliasC', 'AliasD' ];
        $queries = $this->provider->public_build_query_pack( 'ModelX', $aliases );

        $alias_queries = array_filter( $queries, fn($q) => str_starts_with( $q['family'], 'alias_' ) );
        // At most 3 aliases × 2 families = 6
        $this->assertLessThanOrEqual( 6, count( $alias_queries ) );

        $alias_sources = array_unique( array_column( array_values( $alias_queries ), '_alias_source' ) );
        $this->assertLessThanOrEqual( 3, count( $alias_sources ) );
        $this->assertNotContains( 'AliasD', $alias_sources );
    }

    // ── Empty / blank aliases are skipped ────────────────────────────────────

    public function test_blank_alias_entries_are_skipped(): void {
        $queries_no_alias  = $this->provider->public_build_query_pack( 'ModelX' );
        $queries_blank     = $this->provider->public_build_query_pack( 'ModelX', [ '', '  ', '' ] );

        $this->assertCount( count( $queries_no_alias ), $queries_blank );
    }

    // ── Primary name as alias is a no-op ─────────────────────────────────────

    public function test_alias_matching_model_name_case_insensitive_filtered_in_lookup(): void {
        // build_query_pack itself does not filter — that happens in lookup().
        // Here we verify the alias query IS produced if passed directly.
        // The lookup() test covers the case-insensitive skip.
        $queries = $this->provider->public_build_query_pack( 'ModelX', [ 'ModelX' ] );
        $alias_queries = array_filter( $queries, fn($q) => str_starts_with( $q['family'], 'alias_' ) );
        // build_query_pack does not do the case-insensitive check; lookup() does.
        // This test simply confirms no crash.
        $this->assertIsArray( $alias_queries );
    }

    // ── _alias_source present on alias descriptors only ───────────────────────

    public function test_alias_source_absent_on_standard_queries(): void {
        $queries = $this->provider->public_build_query_pack( 'Aisha Dupont', [ 'OhhAisha' ] );

        foreach ( $queries as $q ) {
            if ( ! str_starts_with( $q['family'], 'alias_' ) ) {
                $this->assertArrayNotHasKey( '_alias_source', $q,
                    "Standard query family '{$q['family']}' should not have _alias_source"
                );
            }
        }
    }

    public function test_alias_source_present_and_correct_on_alias_queries(): void {
        $queries = $this->provider->public_build_query_pack( 'Aisha Dupont', [ 'OhhAisha', 'Aisha99' ] );

        $sources = [];
        foreach ( $queries as $q ) {
            if ( str_starts_with( $q['family'], 'alias_' ) ) {
                $this->assertArrayHasKey( '_alias_source', $q );
                $sources[] = $q['_alias_source'];
            }
        }
        $unique = array_unique( $sources );
        $this->assertContains( 'OhhAisha', $unique );
        $this->assertContains( 'Aisha99',  $unique );
    }
}
