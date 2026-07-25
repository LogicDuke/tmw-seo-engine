<?php
/**
 * Phase 1 — Alphabetical ordering test for PlatformProfiles::get_platform_labels().
 *
 * Verifies the production contract:
 *   - Only cam and fansite registry members are returned.
 *   - Slugs and labels are sanitized and the slug→label mapping is preserved.
 *   - Labels use PHP's case-sensitive default asort() ordering.
 *   - Link hubs and social platforms are excluded.
 */

namespace TMWSEO\Engine\Platform;

use PHPUnit\Framework\TestCase;

// ── Minimal stubs ─────────────────────────────────────────────────────────────

if ( ! function_exists( 'TMWSEO\Engine\Platform\sanitize_key' ) ) {
    function sanitize_key( string $s ): string {
        return strtolower( preg_replace( '/[^a-z0-9_]/', '', $s ) );
    }
}
if ( ! function_exists( 'TMWSEO\Engine\Platform\sanitize_text_field' ) ) {
    function sanitize_text_field( string $s ): string { return trim( $s ); }
}

// PlatformRegistry and PlatformProfiles are loaded by the shared bootstrap.

// ── Load the real PlatformProfiles class under test ───────────────────────────

require_once __DIR__ . '/../includes/platform/class-platform-profiles.php';

// ── Test case ─────────────────────────────────────────────────────────────────

class PlatformProfilesAlphaOrderTest extends TestCase {

    private array $labels;

    protected function setUp(): void {
        $method = new \ReflectionMethod( PlatformProfiles::class, 'get_platform_labels' );
        $this->labels = $method->invoke( null );
    }

    public function test_returns_non_empty_array(): void {
        $this->assertNotEmpty( $this->labels );
    }

    public function test_keys_are_slugs(): void {
        foreach ( $this->labels as $slug => $name ) {
            $this->assertIsString( $slug );
            $this->assertIsString( $name );
            $this->assertNotEmpty( $slug );
            $this->assertNotEmpty( $name );
        }
    }

    public function test_labels_use_production_asort_order(): void {
        $expected = $this->expected_sidebar_labels();

        $this->assertSame(
            $expected,
            $this->labels,
            'Labels should use the same case-sensitive SORT_REGULAR asort contract as production.'
        );
    }

    public function test_entries_match_cam_and_fansite_registry_membership(): void {
        $registry_by_slug = [];
        foreach ( PlatformRegistry::get_platforms() as $platform ) {
            $registry_by_slug[ sanitize_key( (string) ( $platform['slug'] ?? '' ) ) ] = $platform;
        }

        foreach ( $this->labels as $slug => $label ) {
            $this->assertArrayHasKey( $slug, $registry_by_slug );
            $this->assertContains( $registry_by_slug[ $slug ]['group'], [ 'cam', 'fansite' ] );
            $this->assertSame( sanitize_text_field( (string) $registry_by_slug[ $slug ]['name'] ), $label );
        }
    }

    public function test_non_sidebar_registry_groups_are_excluded(): void {
        $this->assertArrayNotHasKey( 'allmylinks', $this->labels );
        $this->assertArrayNotHasKey( 'linktree', $this->labels );
        $this->assertArrayNotHasKey( 'twitter', $this->labels );
    }

    public function test_slug_to_name_mapping_preserved(): void {
        // asort() must preserve the sanitized registry slug keys.
        $this->assertSame( 'Chaturbate', $this->labels['chaturbate'] );
        $this->assertSame( 'Fansly', $this->labels['fansly'] );
        $this->assertSame( 'Cams.com', $this->labels['camscom'] );
    }

    public function test_case_sensitive_order_differs_from_natural_case_insensitive_order(): void {
        $values = array_values( $this->labels );
        $natural = $values;
        natcasesort( $natural );

        $this->assertNotSame( array_values( $natural ), $values );
        $this->assertSame( 'BongaCams', reset( $values ) );
    }

    /**
     * Reproduce the production membership, sanitization, and sorting contract
     * from the canonical registry without hard-coding a stale fixture.
     *
     * @return array<string,string>
     */
    private function expected_sidebar_labels(): array {
        $expected = [];

        foreach ( PlatformRegistry::get_platforms() as $platform ) {
            $slug  = sanitize_key( (string) ( $platform['slug'] ?? '' ) );
            $label = sanitize_text_field( (string) ( $platform['name'] ?? '' ) );
            $group = (string) ( $platform['group'] ?? 'other' );

            if ( $slug === '' || $label === '' || ! in_array( $group, [ 'cam', 'fansite' ], true ) ) {
                continue;
            }

            $expected[ $slug ] = $label;
        }

        asort( $expected );
        return $expected;
    }

}
