<?php
/**
 * TMW SEO Engine — Full-Audit Direct-Recall Tests (v5.2.0)
 *
 * Locks in the fix for the "Anisyia on Beacons was missed directly" bug:
 *
 *   ROOT CAUSE (before v5.2.0):
 *     build_handle_seeds_audit() added the name-derived seed with post-title
 *     case preserved — "Anisyia". The probe synthesized
 *     https://beacons.ai/Anisyia, which 404s on case-sensitive link hubs.
 *     Beacons is not in PLATFORM_404_CONFIRM_SLUGS, so no GET fallback ran.
 *
 *   FIX:
 *     build_handle_seeds_audit() now generates bounded variants of the
 *     name-derived handle via generate_audit_seed_variants(). For a single-
 *     token mixed-case handle ("Anisyia") the lowercase variant ("anisyia")
 *     is emitted as an additional seed.
 *
 * These tests verify:
 *   1. The lowercase variant is present in the audit seed list for single-
 *      token title-case names like "Anisyia".
 *   2. Multi-token names ("Abby Murray") emit the full bounded variant set.
 *   3. When only the lowercase seed would match (case-sensitive probe),
 *      the full-audit probe confirms the platform — proving the fix is
 *      wired end-to-end.
 *   4. Already-lowercase names ("anisyia") do NOT duplicate themselves.
 *   5. Existing seed-cap / dedup / priority-sort behaviour is preserved.
 *
 * @package TMWSEO\Engine\Model\Tests
 * @since   5.2.0
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Model\Tests;

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Model\ModelPlatformProbe;
use TMWSEO\Engine\Model\ModelSerpResearchProvider;
use TMWSEO\Engine\Platform\PlatformRegistry;

/** Expose the protected audit helper for inspection. */
class TestableRecallSerpProvider extends ModelSerpResearchProvider {
    public function call_build_handle_seeds_audit( array $successful, string $name ): array {
        return $this->build_handle_seeds_audit( $successful, $name );
    }
    public function call_generate_audit_seed_variants( string $handle ): array {
        return $this->generate_audit_seed_variants( $handle );
    }
}

/**
 * Case-aware mock probe. Returns 200 only when the synthesized URL exactly
 * equals the "live" URL we register (case-sensitive), else 404 — the simplest
 * simulation of a case-sensitive link hub like beacons.ai.
 */
class CaseSensitiveFullAuditProbe extends ModelPlatformProbe {
    /** @var array<string,true> case-sensitive URLs that should return 200 */
    private array $live_urls = [];

    public function set_live_url( string $url ): void {
        // Normalize host case (hosts are case-insensitive), preserve path case.
        $parts = parse_url( rtrim( $url, '/' ) );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) { return; }
        $norm = ( $parts['scheme'] ?? 'https' ) . '://' . strtolower( $parts['host'] ) . ( $parts['path'] ?? '' );
        $this->live_urls[ $norm ] = true;
    }

    protected function probe_url( string $url, string $slug, string $handle, int &$get_fallbacks_used ): array {
        $parts = parse_url( rtrim( $url, '/' ) );
        if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
            $norm = ( $parts['scheme'] ?? 'https' ) . '://' . strtolower( $parts['host'] ) . ( $parts['path'] ?? '' );
            if ( isset( $this->live_urls[ $norm ] ) ) {
                return [ 'accepted' => true, 'status' => 200, 'reason' => 'mock_case_exact' ];
            }
        }
        return [ 'accepted' => false, 'status' => 404, 'reason' => 'mock_case_miss' ];
    }
}

class FullAuditRecallTest extends TestCase {

    // ── Variant generator — direct unit tests ─────────────────────────────────

    public function test_variant_generator_single_title_case_emits_lowercase(): void {
        $p = new TestableRecallSerpProvider();
        $variants = $p->call_generate_audit_seed_variants( 'Anisyia' );
        $this->assertContains( 'anisyia', $variants,
            'Title-case "Anisyia" must emit "anisyia" as a bounded variant'
        );
    }

    public function test_variant_generator_single_lowercase_emits_nothing(): void {
        $p = new TestableRecallSerpProvider();
        $variants = $p->call_generate_audit_seed_variants( 'anisyia' );
        $this->assertSame( [], $variants,
            'Single-token all-lowercase handle must not produce speculative variants'
        );
    }

    public function test_variant_generator_multi_token_emits_full_set(): void {
        $p = new TestableRecallSerpProvider();
        $variants = $p->call_generate_audit_seed_variants( 'AbbyMurray' );
        // Full set minus original: abbymurray, abby-murray, abby_murray, abbyMurray.
        $this->assertContains( 'abbymurray', $variants );
        $this->assertContains( 'abby-murray', $variants );
        $this->assertContains( 'abby_murray', $variants );
        $this->assertContains( 'abbyMurray',  $variants );
        $this->assertNotContains( 'AbbyMurray', $variants,
            'Input handle must never be included in the variant set'
        );
    }

    // ── build_handle_seeds_audit integration ─────────────────────────────────

    public function test_audit_seeds_include_lowercase_for_title_case_name(): void {
        $p = new TestableRecallSerpProvider();
        // No pass-one successful extractions — the only seeds come from the
        // name and its variants. This is the canonical Anisyia scenario.
        $seeds = $p->call_build_handle_seeds_audit( [], 'Anisyia' );

        $handles = array_map( static fn( array $s ): string => (string) $s['handle'], $seeds );
        $this->assertContains( 'Anisyia', $handles, 'Case-preserved name seed must be present' );
        $this->assertContains( 'anisyia', $handles, 'Lowercase variant must now be present (v5.2.0 fix)' );
    }

    public function test_audit_seeds_do_not_duplicate_when_name_already_lowercase(): void {
        $p = new TestableRecallSerpProvider();
        $seeds = $p->call_build_handle_seeds_audit( [], 'anisyia' );

        $handles = array_map( static fn( array $s ): string => (string) $s['handle'], $seeds );
        $this->assertContains( 'anisyia', $handles );
        // Only one occurrence of 'anisyia' — no duplicates.
        $this->assertSame( 1, count( array_keys( $handles, 'anisyia', true ) ),
            'Lowercase-only name must not duplicate itself as a variant'
        );
    }

    public function test_audit_seeds_preserve_priority_order_before_variants(): void {
        $p = new TestableRecallSerpProvider();
        $successful = [
            [
                'success'             => true,
                'username'            => 'anisyiaxxx',
                'normalized_platform' => 'twitter',
                'source_url'          => 'https://x.com/anisyiaxxx',
            ],
        ];
        $seeds = $p->call_build_handle_seeds_audit( $successful, 'Anisyia' );

        // twitter has priority 12; the name_derived seed is added after it.
        // The lowercase "anisyia" variant comes AFTER the name_derived "Anisyia".
        $order = array_map( static fn( array $s ): string => (string) $s['handle'], $seeds );
        $idx_twitter = array_search( 'anisyiaxxx', $order, true );
        $idx_name    = array_search( 'Anisyia',    $order, true );
        $idx_variant = array_search( 'anisyia',    $order, true );

        $this->assertNotFalse( $idx_twitter );
        $this->assertNotFalse( $idx_name );
        $this->assertNotFalse( $idx_variant );
        $this->assertLessThan( $idx_name,    $idx_twitter, 'SERP-found seed must precede name_derived' );
        $this->assertLessThan( $idx_variant, $idx_name,    'name_derived must precede its variants' );
    }

    public function test_audit_seeds_respect_seed_cap_when_variants_exceed_budget(): void {
        $p = new TestableRecallSerpProvider();
        // Fabricate 12 successful candidates → fills AUDIT_SEED_CAP=12 before
        // any variant is emitted. This is the regression guard for the cap.
        $successful = [];
        $slugs = array_slice( PlatformRegistry::get_slugs(), 0, 12 );
        foreach ( $slugs as $i => $slug ) {
            $successful[] = [
                'success'             => true,
                'username'            => 'h' . $i,
                'normalized_platform' => $slug,
                'source_url'          => 'https://example.com/' . $i,
            ];
        }
        $seeds = $p->call_build_handle_seeds_audit( $successful, 'Anisyia' );
        $this->assertLessThanOrEqual( 12, count( $seeds ), 'AUDIT_SEED_CAP=12 must not be exceeded' );
    }

    // ── End-to-end: case-sensitive probe confirms via lowercase variant ──────

    public function test_case_sensitive_beacons_confirmed_via_lowercase_variant(): void {
        $p = new TestableRecallSerpProvider();
        $seeds = $p->call_build_handle_seeds_audit( [], 'Anisyia' );

        // Pre-condition: BEFORE the fix, only "Anisyia" was in the seed list.
        $handles = array_map( static fn( array $s ): string => (string) $s['handle'], $seeds );
        $this->assertContains( 'anisyia', $handles,
            'fix pre-condition: lowercase seed must exist'
        );

        $probe = new CaseSensitiveFullAuditProbe();
        // Only the lowercase Beacons URL is live — mirrors real beacons.ai behavior.
        $probe->set_live_url( 'https://beacons.ai/anisyia' );

        $result   = $probe->run_full_audit( $seeds, 0 );
        $coverage = $result['diagnostics']['platform_coverage'] ?? [];
        $beacons  = $coverage['beacons'] ?? null;

        $this->assertNotNull( $beacons, 'beacons must appear in platform_coverage' );
        $this->assertSame( 'confirmed', $beacons['status'] ?? '',
            'Beacons MUST be confirmed now that the lowercase variant is probed'
        );
        $this->assertSame( 'anisyia', $beacons['handle'] ?? '',
            'Confirmation must use the lowercase handle, not the title-case one'
        );
    }

    public function test_before_fix_only_uppercase_seed_would_have_failed_beacons(): void {
        // Defensive guard: verify that if someone reverts the fix, the
        // lowercase URL is NOT reachable from the title-case seed alone.
        // We simulate "pre-fix" by probing only the single uppercase seed.
        $probe = new CaseSensitiveFullAuditProbe();
        $probe->set_live_url( 'https://beacons.ai/anisyia' );

        $only_uppercase = [
            [ 'handle' => 'Anisyia', 'source_platform' => 'name_derived', 'source_url' => '' ],
        ];
        $result   = $probe->run_full_audit( $only_uppercase, 0 );
        $coverage = $result['diagnostics']['platform_coverage'] ?? [];
        $this->assertNotSame( 'confirmed', $coverage['beacons']['status'] ?? '',
            'Regression guard: uppercase-only seed must not confirm beacons'
        );
    }
}
