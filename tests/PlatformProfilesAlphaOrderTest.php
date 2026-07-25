<?php
/**
 * Phase 1 — Alphabetical ordering test for PlatformProfiles::get_platform_labels().
 *
 * Verifies:
 *   - Labels are returned in A→Z order by display name.
 *   - At minimum the first label alphabetically precedes the last alphabetically.
 *   - The slug→label mapping is preserved (asort does not discard keys).
 *   - The function returns a non-empty array.
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

// ── Expose the private get_platform_labels() via a test subclass ──────────────

class TestablePlatformProfiles extends PlatformProfiles {
    public static function public_get_platform_labels(): array {
        return self::get_platform_labels();
    }
}

// ── Test case ─────────────────────────────────────────────────────────────────

class PlatformProfilesAlphaOrderTest extends TestCase {

    private array $labels;

    protected function setUp(): void {
        $this->labels = TestablePlatformProfiles::public_get_platform_labels();
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

    public function test_labels_are_in_alphabetical_order(): void {
        $values  = array_values( $this->labels );
        $sorted  = $values;
        natcasesort( $sorted );
        $this->assertSame( array_values( $sorted ), $values,
            'PlatformProfiles::get_platform_labels() should return labels sorted A→Z'
        );
    }

    public function test_first_label_alphabetically_before_last(): void {
        $values = array_values( $this->labels );
        $first  = reset( $values );
        $last   = end( $values );
        $this->assertLessThanOrEqual(
            0,
            strcasecmp( $first, $last ),
            "First label '{$first}' should come before or equal last label '{$last}' alphabetically"
        );
    }

    public function test_allmylinks_before_xcams(): void {
        $values = array_values( $this->labels );
        $pos_all  = array_search( 'AllMyLinks', $values, true );
        $pos_xc   = array_search( 'Xcams', $values, true );
        $this->assertNotFalse( $pos_all );
        $this->assertNotFalse( $pos_xc );
        $this->assertLessThan( $pos_xc, $pos_all,
            "AllMyLinks (A) should appear before Xcams (X)"
        );
    }

    public function test_slug_to_name_mapping_preserved(): void {
        // asort() must not discard keys
        $this->assertArrayHasKey( 'chaturbate', $this->labels );
        $this->assertSame( 'Chaturbate', $this->labels['chaturbate'] );
        $this->assertArrayHasKey( 'fansly', $this->labels );
        $this->assertSame( 'Fansly', $this->labels['fansly'] );
    }

    public function test_priority_order_is_not_preserved(): void {
        // Linktree (priority 5) should NOT be first if alphabetical; AllMyLinks is.
        $values = array_values( $this->labels );
        $first  = reset( $values );
        // AllMyLinks comes before Linktree alphabetically
        $this->assertSame( 'AllMyLinks', $first,
            "AllMyLinks should be first alphabetically, not priority-first platform"
        );
    }
}
