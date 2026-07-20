<?php
/**
 * TMW SEO Engine — Full Audit Mode Tests (v4.7.0)
 *
 * Covers:
 *   A. Full-registry probe iteration (run_full_audit): all slugs attempted
 *   B. No CORE_PRIORITY_SLUGS favoritism in run_full_audit
 *   C. fansly is probed (was missing from PROBE_PRIORITY_SLUGS)
 *   D. Platform coverage map returned: confirmed / rejected / not_probed
 *   E. Alias-based discovery via build_query_pack_audit (all 3 families)
 *   F. Raised seed cap (build_handle_seeds_audit returns up to AUDIT_SEED_CAP)
 *   G. Manual usernames never overwritten by audit results
 *   H. Confirmed vs audit-only (probe-rejected) classification
 *   I. Probe-incompatible slugs (myfreecams fragment) silently skipped
 *   J. Ambiguous/host-mismatch URLs rejected with explicit reasons
 *   K. FULL_AUDIT constants present and higher than SYNC constants
 *   L. ModelFullAuditProvider::make() instantiation
 *   M. build_query_pack_audit includes full_registry_sweep family
 *   N. run_full_audit returns per-platform coverage for all registry slugs
 *
 * No live HTTP or DataForSEO calls are made. All network calls are intercepted
 * by test doubles that inject configurable mock responses.
 *
 * @package TMWSEO\Engine\Model\Tests
 * @since   4.7.0
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Model\Tests;

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Model\ModelPlatformProbe;
use TMWSEO\Engine\Model\ModelSerpResearchProvider;
use TMWSEO\Engine\Model\ModelFullAuditProvider;
use TMWSEO\Engine\Platform\PlatformRegistry;
use TMWSEO\Engine\Platform\PlatformProfiles;

// ── Test doubles ─────────────────────────────────────────────────────────────

/**
 * Testable subclass of ModelPlatformProbe for full-audit tests.
 *
 * Overrides probe_url() to return configurable mock responses keyed by slug.
 * All safety logic (redirect checks, extraction trust gate) remains live.
 */
class TestableFullAuditProbe extends ModelPlatformProbe {

    /** @var array<string,array{accepted:bool,status:int,reason:string}> */
    private array $mock_responses_by_slug = [];

    /** Default response when no specific mock is registered. */
    private array $default_response = [ 'accepted' => false, 'status' => 404, 'reason' => 'mock_default_404' ];

    public function set_response_for_slug( string $slug, bool $accepted, int $status, string $reason ): void {
        $this->mock_responses_by_slug[ $slug ] = [ 'accepted' => $accepted, 'status' => $status, 'reason' => $reason ];
    }

    public function set_default_response( bool $accepted, int $status, string $reason ): void {
        $this->default_response = [ 'accepted' => $accepted, 'status' => $status, 'reason' => $reason ];
    }

    /** @param int $get_fallbacks_used (by reference) */
    protected function probe_url( string $url, string $slug, string $handle, int &$get_fallbacks_used ): array {
        if ( isset( $this->mock_responses_by_slug[ $slug ] ) ) {
            return $this->mock_responses_by_slug[ $slug ];
        }
        return $this->default_response;
    }
}

/**
 * Testable subclass of ModelFullAuditProvider.
 *
 * Overrides run_full_audit_probe() and run_query_pack_pub() to prevent live HTTP.
 */
class TestableFullAuditProvider extends ModelFullAuditProvider {

    /** Probe instance to inject. */
    private ?TestableFullAuditProbe $mock_probe = null;

    /** Canned SERP pack result. */
    private array $mock_serp_pack = [];

    public function inject_probe( TestableFullAuditProbe $probe ): void {
        $this->mock_probe = $probe;
    }

    public function set_mock_serp_pack( array $pack ): void {
        $this->mock_serp_pack = $pack;
    }

    protected function run_full_audit_probe( array $handle_seeds, int $post_id ): array {
        if ( $this->mock_probe !== null ) {
            return $this->mock_probe->run_full_audit( $handle_seeds, $post_id );
        }
        // Empty verified_urls, empty diagnostics
        return [ 'verified_urls' => [], 'diagnostics' => [] ];
    }

    protected function run_query_pack_pub( array $queries, int $depth, int $post_id ): array {
        if ( ! empty( $this->mock_serp_pack ) ) {
            return $this->mock_serp_pack;
        }
        return [ 'succeeded' => 0, 'failed' => count( $queries ), 'last_error' => 'mock_no_serp', 'items' => [], 'query_stats' => [] ];
    }
}

/**
 * Testable ModelSerpResearchProvider exposing protected audit helpers.
 */
class TestableAuditSerpProvider extends ModelSerpResearchProvider {
    public function call_build_query_pack_audit( string $name, array $aliases = [] ): array {
        return $this->build_query_pack_audit( $name, $aliases );
    }
    public function call_build_handle_seeds_audit( array $successful, string $name ): array {
        return $this->build_handle_seeds_audit( $successful, $name );
    }
}

// ── Test class ────────────────────────────────────────────────────────────────

class FullAuditModeTest extends TestCase {

    // ── K: Constants are higher than sync values ──────────────────────────────

    public function test_full_audit_probe_budget_higher_than_sync(): void {
        $this->assertGreaterThan(
            6,   // SYNC MAX_PROBES
            ModelPlatformProbe::FULL_AUDIT_MAX_PROBES,
            'FULL_AUDIT_MAX_PROBES must exceed sync MAX_PROBES=6'
        );
    }

    public function test_full_audit_probe_budget_covers_registry(): void {
        $registry_count = count( PlatformRegistry::get_slugs() );
        $this->assertGreaterThanOrEqual(
            $registry_count,
            ModelPlatformProbe::FULL_AUDIT_MAX_PROBES,
            'FULL_AUDIT_MAX_PROBES must be >= registry slug count (' . $registry_count . ')'
        );
    }

    // ── A: All registry slugs are iterated in run_full_audit ─────────────────

    public function test_run_full_audit_attempts_all_registry_slugs(): void {
        // Accept all probes so every slug that synthesizes a URL gets accepted.
        $probe = new TestableFullAuditProbe();
        $probe->set_default_response( true, 200, 'mock_ok' );

        $seeds = [
            [ 'handle' => 'TestModel', 'source_platform' => 'name_derived', 'source_url' => '' ],
        ];

        $result      = $probe->run_full_audit( $seeds, 0 );
        $probe_log   = $result['diagnostics']['probe_log'] ?? [];
        $coverage    = $result['diagnostics']['platform_coverage'] ?? [];

        // Every registry slug that can synthesize a URL should appear in coverage.
        $probe_able_slugs = [];
        foreach ( PlatformRegistry::get_slugs() as $slug ) {
            $url = $probe->synthesize_candidate_url( $slug, 'TestModel' );
            if ( $url !== '' ) {
                $probe_able_slugs[] = $slug;
            }
        }

        foreach ( $probe_able_slugs as $slug ) {
            $this->assertArrayHasKey(
                $slug,
                $coverage,
                "Platform slug '{$slug}' should appear in platform_coverage"
            );
            $this->assertNotEquals(
                'not_probed',
                $coverage[ $slug ]['status'] ?? 'not_probed',
                "Platform slug '{$slug}' should have been attempted (not left as not_probed)"
            );
        }
    }

    // ── B: No CORE_PRIORITY favoritism — non-core platforms probed ─────────

    public function test_run_full_audit_probes_non_core_platforms(): void {
        $probe = new TestableFullAuditProbe();
        $probe->set_default_response( false, 404, 'mock_404' );
        // Make fansly accept so we can confirm it was probed.
        $probe->set_response_for_slug( 'fansly', true, 200, 'mock_ok' );

        $seeds = [
            [ 'handle' => 'Anisyia', 'source_platform' => 'name_derived', 'source_url' => '' ],
        ];

        $result   = $probe->run_full_audit( $seeds, 0 );
        $coverage = $result['diagnostics']['platform_coverage'] ?? [];

        $this->assertArrayHasKey( 'fansly', $coverage,
            'fansly must appear in platform_coverage (was missing from PROBE_PRIORITY_SLUGS)'
        );

        // fansly should be confirmed because we returned 200 for it.
        // Note: the mock returns 200 but parse_url_for_platform_structured must also succeed.
        // If extraction fails the probe is still "attempted", just rejected at extraction stage.
        $this->assertNotEquals(
            'not_probed',
            $coverage['fansly']['status'] ?? 'not_probed',
            'fansly must be attempted in run_full_audit'
        );
    }

    // ── C: fansly is probed (critical regression check) ──────────────────────

    public function test_fansly_not_silently_skipped_in_full_audit(): void {
        $probe = new TestableFullAuditProbe();
        $probe->set_default_response( false, 404, 'mock_404' );

        $seeds = [ [ 'handle' => 'TestUser', 'source_platform' => 'name_derived', 'source_url' => '' ] ];
        $result   = $probe->run_full_audit( $seeds, 0 );
        $coverage = $result['diagnostics']['platform_coverage'] ?? [];

        $this->assertArrayHasKey( 'fansly', $coverage,
            'fansly must appear in platform_coverage — it was completely absent in sync mode'
        );
    }

    // ── D: Coverage map has confirmed / rejected / not_probed keys ───────────

    public function test_coverage_map_statuses(): void {
        $probe = new TestableFullAuditProbe();
        $probe->set_default_response( false, 404, 'mock_404' );

        $seeds  = [ [ 'handle' => 'CovTest', 'source_platform' => 'name_derived', 'source_url' => '' ] ];
        $result = $probe->run_full_audit( $seeds, 0 );

        $coverage = $result['diagnostics']['platform_coverage'] ?? [];
        $this->assertNotEmpty( $coverage, 'platform_coverage must not be empty' );

        $valid_statuses = [ 'confirmed', 'rejected', 'not_probed' ];
        foreach ( $coverage as $slug => $entry ) {
            $this->assertContains(
                $entry['status'] ?? '',
                $valid_statuses,
                "Coverage entry for '{$slug}' has invalid status"
            );
        }
    }

    // ── E: build_query_pack_audit uses all 3 alias families ──────────────────

    public function test_audit_query_pack_generates_3_families_per_alias(): void {
        $provider = new TestableAuditSerpProvider();
        $aliases  = [ 'OhhAisha', 'AishaX' ];
        $queries  = $provider->call_build_query_pack_audit( 'Aisha Dupont', $aliases );

        $families_for_ohhAisha = array_filter( $queries, static fn( $q ) =>
            ( $q['_alias_source'] ?? '' ) === 'OhhAisha'
        );
        $this->assertGreaterThanOrEqual( 3, count( $families_for_ohhAisha ),
            'Each alias should generate at least 3 query families in audit mode'
        );

        $family_names = array_column( array_values( $families_for_ohhAisha ), 'family' );
        $this->assertContains( 'alias_webcam_discovery',     $family_names );
        $this->assertContains( 'alias_creator_discovery',    $family_names );
        $this->assertContains( 'alias_hub_social_discovery', $family_names );
    }

    // ── E cont: audit query pack includes the full_registry_sweep family ──────

    public function test_audit_query_pack_includes_registry_sweep(): void {
        $provider = new TestableAuditSerpProvider();
        $queries  = $provider->call_build_query_pack_audit( 'Allysa Quinn' );

        $families = array_column( $queries, 'family' );
        $this->assertContains( 'full_registry_sweep', $families,
            'Audit query pack must include a full_registry_sweep query'
        );
    }

    // ── M: full_registry_sweep query includes all registry domains ────────────

    public function test_registry_sweep_query_contains_all_platform_domains(): void {
        $provider = new TestableAuditSerpProvider();
        $queries  = $provider->call_build_query_pack_audit( 'Test Model' );

        $sweep_queries = array_filter( $queries, static fn( $q ) =>
            ( $q['family'] ?? '' ) === 'full_registry_sweep'
        );
        $this->assertNotEmpty( $sweep_queries );

        $sweep_query = (string) ( reset( $sweep_queries )['query'] ?? '' );

        // Spot-check: at least fansly.com and chaturbate.com must be in the sweep
        $this->assertStringContainsString( 'fansly.com', $sweep_query );
        $this->assertStringContainsString( 'chaturbate.com', $sweep_query );
        $this->assertStringContainsString( 'stripchat.com', $sweep_query );
    }

    // ── F: build_handle_seeds_audit returns more than 5 seeds ────────────────

    public function test_audit_seed_cap_higher_than_sync(): void {
        $provider = new TestableAuditSerpProvider();

        // Build 10 fake successful candidates with distinct handles
        $successful = [];
        $slugs      = array_slice( PlatformRegistry::get_slugs(), 0, 10 );
        foreach ( $slugs as $i => $slug ) {
            $successful[] = [
                'success'             => true,
                'username'            => 'handle_' . $i,
                'normalized_platform' => $slug,
                'source_url'          => 'https://example.com/' . $i,
            ];
        }

        $seeds = $provider->call_build_handle_seeds_audit( $successful, 'Test Model' );

        // In sync mode build_handle_seeds() caps at 5. Audit mode allows AUDIT_SEED_CAP=12.
        $this->assertGreaterThan( 5, count( $seeds ),
            'build_handle_seeds_audit must return more than 5 seeds (sync cap was 5)'
        );
    }

    // ── G: Manual usernames are never overwritten ─────────────────────────────

    public function test_manual_usernames_not_overwritten(): void {
        // run_full_audit only returns verified_urls — it never writes post meta.
        // Writing is done by the AJAX handler which only updates META_PROPOSED,
        // not _tmwseo_platform_username_*.
        // This test verifies that run_full_audit does not touch any meta keys.

        $probe = new TestableFullAuditProbe();
        $probe->set_default_response( true, 200, 'mock_ok' );

        $seeds  = [ [ 'handle' => 'TestUser', 'source_platform' => 'name_derived', 'source_url' => '' ] ];
        $result = $probe->run_full_audit( $seeds, 0 );

        // run_full_audit should only return verified_urls + diagnostics.
        // No update_post_meta, no platform_username writes.
        $this->assertArrayHasKey( 'verified_urls', $result );
        $this->assertArrayHasKey( 'diagnostics', $result );
        $this->assertArrayNotHasKey( 'meta_writes', $result,
            'run_full_audit must not include a meta_writes key — it never touches post meta'
        );
    }

    // ── H: Confirmed vs rejected classification ───────────────────────────────

    public function test_confirmed_vs_rejected_classification(): void {
        $probe = new TestableFullAuditProbe();
        // Accept chaturbate, reject stripchat
        $probe->set_response_for_slug( 'chaturbate', true, 200, 'mock_ok' );
        $probe->set_response_for_slug( 'stripchat',  false, 404, 'mock_rejected_404_unconfirmed' );
        $probe->set_default_response( false, 404, 'mock_404' );

        $seeds  = [ [ 'handle' => 'AnisyiaTest', 'source_platform' => 'name_derived', 'source_url' => '' ] ];
        $result = $probe->run_full_audit( $seeds, 0 );

        $coverage = $result['diagnostics']['platform_coverage'] ?? [];

        // chaturbate: accepted by HTTP mock. It will be 'confirmed' only if the
        // extraction trust gate also passes. The trust gate requires the synthesized
        // URL to parse correctly — for chaturbate/AnisyiaTest it should.
        // We only assert it is NOT 'not_probed'.
        $this->assertNotEquals( 'not_probed', $coverage['chaturbate']['status'] ?? 'not_probed',
            'chaturbate must have been attempted'
        );

        // stripchat: rejected by mock.
        $this->assertEquals( 'rejected', $coverage['stripchat']['status'] ?? '',
            'stripchat must show rejected when probe returns 404'
        );
    }

    // ── I: Fragment-only platforms are silently skipped ───────────────────────

    public function test_fragment_only_platform_synthesizes_empty_url(): void {
        $probe = new TestableFullAuditProbe();
        $url   = $probe->synthesize_candidate_url( 'myfreecams', 'TestUser' );
        $this->assertSame( '', $url,
            'myfreecams uses fragment URL — synthesize_candidate_url must return empty string'
        );
    }

    public function test_fragment_only_platform_skipped_in_full_audit(): void {
        $probe = new TestableFullAuditProbe();
        $probe->set_default_response( false, 404, 'mock' );

        $seeds  = [ [ 'handle' => 'TestUser', 'source_platform' => 'name_derived', 'source_url' => '' ] ];
        $result = $probe->run_full_audit( $seeds, 0 );

        $coverage = $result['diagnostics']['platform_coverage'] ?? [];

        // myfreecams is in the registry but its profile URL uses #fragment.
        // synthesize_candidate_url returns '' for it → it should NOT appear in coverage
        // (the loop skips it), OR it appears as 'not_probed'.
        // Either outcome is acceptable; the key requirement is it was NOT "confirmed".
        $myfreecams_status = $coverage['myfreecams']['status'] ?? 'not_present';
        $this->assertNotEquals( 'confirmed', $myfreecams_status,
            'myfreecams (fragment-only) must never be marked confirmed'
        );
    }

    // ── J: Ambiguous/host-mismatch URLs rejected with explicit reason ─────────

    public function test_host_mismatch_url_rejected_with_reason(): void {
        $result = PlatformProfiles::parse_url_for_platform_structured(
            'chaturbate',
            'https://stripchat.com/SomeModel'
        );
        $this->assertFalse( (bool) ( $result['success'] ?? true ),
            'A stripchat.com URL must be rejected when parsed against chaturbate slug'
        );
        $this->assertSame( 'host_mismatch', $result['reject_reason'] ?? '',
            'Reject reason must be host_mismatch, not empty'
        );
    }

    public function test_path_prefix_listing_url_rejected(): void {
        // A /models/ listing page must NOT produce a valid username extraction.
        $result = PlatformProfiles::parse_url_for_platform_structured(
            'stripchat',
            'https://stripchat.com/models/new'
        );
        // 'models' as a username should fail the reserved-path guard.
        // The extraction result should either fail or produce no username at all.
        if ( ! empty( $result['success'] ) ) {
            // If it somehow "succeeded", the username must not be 'models' — that would
            // be a generic listing segment misidentified as a profile.
            $this->assertNotSame( 'models', $result['username'] ?? '',
                'Listing path segment "models" must not be extracted as a username'
            );
        } else {
            $this->assertNotEmpty( $result['reject_reason'],
                'Rejected extraction must carry a non-empty reject_reason'
            );
        }
    }

    // ── L: ModelFullAuditProvider::make() returns a proper instance ───────────

    public function test_full_audit_provider_make(): void {
        $provider = ModelFullAuditProvider::make();
        $this->assertInstanceOf( ModelFullAuditProvider::class, $provider );
        $this->assertInstanceOf( ModelSerpResearchProvider::class, $provider );
        $this->assertSame( 'full_audit', $provider->provider_name() );
    }

    // ── N: run_full_audit returns coverage for all probeable registry slugs ───

    public function test_coverage_map_contains_all_probeable_slugs(): void {
        $probe = new TestableFullAuditProbe();
        $probe->set_default_response( false, 404, 'mock_404' );

        $seeds  = [ [ 'handle' => 'CovModel', 'source_platform' => 'name_derived', 'source_url' => '' ] ];
        $result = $probe->run_full_audit( $seeds, 0 );

        $coverage = $result['diagnostics']['platform_coverage'] ?? [];

        // Every slug that synthesizes a probing URL must appear in coverage.
        foreach ( PlatformRegistry::get_slugs() as $slug ) {
            $url = $probe->synthesize_candidate_url( $slug, 'CovModel' );
            if ( $url === '' ) {
                continue; // Fragment-only / synthesize failure — skip.
            }
            $this->assertArrayHasKey( $slug, $coverage,
                "Slug '{$slug}' with probeable URL must appear in platform_coverage"
            );
        }
    }

    // ── Regression: existing sync PROBE_PRIORITY_SLUGS still work ────────────

    public function test_sync_probe_still_works_for_chaturbate(): void {
        // run() (sync) should still probe chaturbate via the CORE_PRIORITY path.
        $probe = new TestableFullAuditProbe();
        $probe->set_default_response( false, 404, 'mock_404' );

        $seeds = [ [ 'handle' => 'Anisyia', 'source_platform' => 'name_derived', 'source_url' => '' ] ];
        $result = $probe->run( $seeds, [], 0 );

        $log   = $result['diagnostics']['probe_log'] ?? [];
        $slugs = array_column( $log, 'slug' );

        $this->assertContains( 'chaturbate', $slugs,
            'chaturbate must still be in sync probe log (regression check)'
        );
    }

    public function test_sync_probe_still_works_for_stripchat(): void {
        $probe = new TestableFullAuditProbe();
        $probe->set_default_response( false, 404, 'mock_404' );

        $seeds  = [ [ 'handle' => 'Anisyia', 'source_platform' => 'name_derived', 'source_url' => '' ] ];
        $result = $probe->run( $seeds, [], 0 );
        $log    = $result['diagnostics']['probe_log'] ?? [];
        $slugs  = array_column( $log, 'slug' );

        $this->assertContains( 'stripchat', $slugs,
            'stripchat must still be in sync probe log (regression check)'
        );
    }
    // ── Tests for ajax_run_full_audit wiring correctness ─────────────────────

    // REMOVED: test_ajax_run_full_audit_calls_run_research_now
    // This asserted the OLD architecture (ajax_run_full_audit -> run_research_now + filter
    // injection). The current, correct architecture routes through run_full_audit_now() ->
    // run_with_provider(), asserted by test_ajax_run_full_audit_calls_run_full_audit_now
    // and test_run_full_audit_now_uses_run_with_provider below. Keeping both would be a
    // direct contradiction — they can't simultaneously pass.

    public function test_ajax_run_full_audit_does_not_call_lookup_directly(): void {
        $ref    = new \ReflectionClass( \TMWSEO\Engine\Admin\ModelHelper::class );
        $method = $ref->getMethod( 'ajax_run_full_audit' );
        $file   = $method->getFileName();
        $start  = $method->getStartLine();
        $end    = $method->getEndLine();

        $lines  = file( (string) $file );
        $body   = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

        // Must NOT call ->lookup() directly (that was the broken pattern)
        $this->assertStringNotContainsString(
            '->lookup(',
            $body,
            'ajax_run_full_audit must not call provider->lookup() directly — that bypasses all error handling'
        );
    }

    public function test_ajax_run_full_audit_guard_against_queued_success(): void {
        $ref    = new \ReflectionClass( \TMWSEO\Engine\Admin\ModelHelper::class );
        $method = $ref->getMethod( 'ajax_run_full_audit' );
        $file   = $method->getFileName();
        $start  = $method->getStartLine();
        $end    = $method->getEndLine();

        $lines  = file( (string) $file );
        $body   = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

        // Must explicitly guard against 'queued' being returned as success
        $this->assertStringContainsString(
            "'queued'",
            $body,
            'ajax_run_full_audit must guard against final_status=queued to prevent silent broken state'
        );
        $this->assertStringContainsString(
            'wp_send_json_error',
            $body,
            'ajax_run_full_audit must call wp_send_json_error when status remains queued'
        );
    }

    // REMOVED: test_ajax_run_full_audit_uses_filter_injection_not_direct_instantiation
    // This asserted the OLD architecture (ajax_run_full_audit must inject via the
    // tmwseo_research_providers filter). The current, correct architecture explicitly
    // bypasses that shared filter by instantiating ModelFullAuditProvider directly and
    // passing it to run_with_provider(). That's asserted by
    // test_run_full_audit_now_uses_run_with_provider below. Keeping both contradicts.

    public function test_ajax_trigger_research_unmodified(): void {
        // Research Now handler must still exist and still call run_research_now directly.
        $ref    = new \ReflectionClass( \TMWSEO\Engine\Admin\ModelHelper::class );
        $method = $ref->getMethod( 'ajax_trigger_research' );
        $file   = $method->getFileName();
        $start  = $method->getStartLine();
        $end    = $method->getEndLine();

        $lines  = file( (string) $file );
        $body   = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

        $this->assertStringContainsString( 'run_research_now', $body,
            'ajax_trigger_research must still call run_research_now (regression check)'
        );
        $this->assertStringNotContainsString( 'tmwseo_research_providers', $body,
            'ajax_trigger_research must NOT inject any provider filter — it uses the normal pipeline'
        );
    }

    public function test_full_audit_js_uses_shared_silent_reload(): void {
        // The Full Audit JS click handler must call silentReload() on success,
        // which includes wp.data.dispatch("core/editor").resetPost().
        // Verify by checking the PHP that renders the JS.
        $ref    = new \ReflectionClass( \TMWSEO\Engine\Admin\ModelHelper::class );
        $method = $ref->getMethod( 'render_metabox' );
        $file   = $method->getFileName();
        $start  = $method->getStartLine();
        $end    = $method->getEndLine();

        $lines  = file( (string) $file );
        $body   = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

        // silentReload must be defined once (shared)
        $this->assertStringContainsString( 'function silentReload', $body,
            'silentReload() must be defined in the metabox JS'
        );
        $this->assertStringContainsString( 'resetPost', $body,
            'silentReload() must call resetPost() to clear Gutenberg dirty state'
        );
        // Full Audit handler must call silentReload on success
        $this->assertStringContainsString( 'tmwseo_run_full_audit', $body,
            'render_metabox must wire the Full Audit button to tmwseo_run_full_audit action'
        );
        $this->assertStringContainsString( 'silentReload', $body,
            'Full Audit JS path must call silentReload() on success'
        );
    }


    // ── Tests for run_with_provider isolation ─────────────────────────────────

    public function test_run_with_provider_bypasses_filter(): void {
        // Verify ModelResearchPipeline::run_with_provider() exists
        $this->assertTrue(
            method_exists( \TMWSEO\Engine\Admin\ModelResearchPipeline::class, 'run_with_provider' ),
            'ModelResearchPipeline must have run_with_provider() to isolate the audit provider'
        );
    }

    public function test_run_with_provider_method_does_not_call_apply_filters(): void {
        $ref    = new \ReflectionClass( \TMWSEO\Engine\Admin\ModelResearchPipeline::class );
        $method = $ref->getMethod( 'run_with_provider' );
        $file   = $method->getFileName();
        $start  = $method->getStartLine();
        $end    = $method->getEndLine();
        $lines  = file( (string) $file );
        $body   = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

        $this->assertStringNotContainsString( 'apply_filters', $body,
            'run_with_provider must NOT call apply_filters — that is the whole point of this method'
        );
        $this->assertStringNotContainsString( 'get_providers', $body,
            'run_with_provider must NOT call get_providers() — that would re-introduce the priority bug'
        );
    }

    public function test_ajax_run_full_audit_calls_run_full_audit_now(): void {
        $ref    = new \ReflectionClass( \TMWSEO\Engine\Admin\ModelHelper::class );
        $method = $ref->getMethod( 'ajax_run_full_audit' );
        $file   = $method->getFileName();
        $start  = $method->getStartLine();
        $end    = $method->getEndLine();
        $lines  = file( (string) $file );
        $body   = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

        $this->assertStringContainsString( 'run_full_audit_now', $body,
            'ajax_run_full_audit must call run_full_audit_now(), not run_research_now()'
        );
        $this->assertStringNotContainsString( 'run_research_now', $body,
            'ajax_run_full_audit must not call run_research_now() — that uses the shared filter path'
        );
    }

    public function test_run_full_audit_now_uses_run_with_provider(): void {
        $ref    = new \ReflectionClass( \TMWSEO\Engine\Admin\ModelHelper::class );
        $method = $ref->getMethod( 'run_full_audit_now' );
        $file   = $method->getFileName();
        $start  = $method->getStartLine();
        $end    = $method->getEndLine();
        $lines  = file( (string) $file );
        $body   = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

        $this->assertStringContainsString( 'run_with_provider', $body,
            'run_full_audit_now must call run_with_provider() to bypass the shared filter'
        );
        $this->assertStringNotContainsString( 'apply_filters', $body,
            'run_full_audit_now must not call apply_filters directly'
        );
        $this->assertStringNotContainsString( 'tmwseo_research_providers', $body,
            'run_full_audit_now must not use tmwseo_research_providers filter'
        );
    }

    public function test_run_full_audit_now_has_full_lock_and_error_handling(): void {
        $ref    = new \ReflectionClass( \TMWSEO\Engine\Admin\ModelHelper::class );
        $method = $ref->getMethod( 'run_full_audit_now' );
        $file   = $method->getFileName();
        $start  = $method->getStartLine();
        $end    = $method->getEndLine();
        $lines  = file( (string) $file );
        $body   = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

        $this->assertStringContainsString( 'acquire_research_lock', $body );
        $this->assertStringContainsString( 'release_research_lock', $body );
        $this->assertStringContainsString( 'finally', $body,
            'run_full_audit_now must release lock in finally block'
        );
        $this->assertStringContainsString( 'Throwable', $body,
            'run_full_audit_now must catch Throwable to prevent status staying queued on exception'
        );
    }

    // ── Tests proving audit provider uses audit-mode constants ────────────────

    public function test_audit_provider_lookup_uses_audit_serp_depth(): void {
        // Verify that ModelFullAuditProvider::lookup() passes AUDIT_SERP_DEPTH (20)
        // to run_query_pack_pub(), not SYNC_SERP_DEPTH (8).
        $ref    = new \ReflectionClass( ModelFullAuditProvider::class );
        $method = $ref->getMethod( 'lookup' );
        $file   = $method->getFileName();
        $start  = $method->getStartLine();
        $end    = $method->getEndLine();
        $lines  = file( (string) $file );
        $body   = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

        $this->assertStringContainsString( 'AUDIT_SERP_DEPTH', $body,
            'lookup() must pass AUDIT_SERP_DEPTH to the query runner, not SYNC_SERP_DEPTH'
        );
        $this->assertStringNotContainsString( 'SYNC_SERP_DEPTH', $body,
            'lookup() must not use SYNC_SERP_DEPTH in audit mode'
        );
    }

    public function test_audit_provider_lookup_uses_build_query_pack_audit(): void {
        $ref    = new \ReflectionClass( ModelFullAuditProvider::class );
        $method = $ref->getMethod( 'lookup' );
        $file   = $method->getFileName();
        $start  = $method->getStartLine();
        $end    = $method->getEndLine();
        $lines  = file( (string) $file );
        $body   = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

        $this->assertStringContainsString( 'build_query_pack_audit', $body,
            'lookup() must call build_query_pack_audit(), not the sync build_query_pack()'
        );
    }

    public function test_audit_provider_lookup_emits_audit_config_diagnostics(): void {
        $ref    = new \ReflectionClass( ModelFullAuditProvider::class );
        $method = $ref->getMethod( 'lookup' );
        $file   = $method->getFileName();
        $start  = $method->getStartLine();
        $end    = $method->getEndLine();
        $lines  = file( (string) $file );
        $body   = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

        $required_diag_keys = [
            'audit_mode', 'audit_config', 'serp_depth_used', 'pass_two_enabled',
            'probes_attempted', 'duration_ms', 'full_registry_sweep_included',
            'platforms_confirmed',
        ];
        foreach ( $required_diag_keys as $key ) {
            $this->assertStringContainsString( $key, $body,
                "lookup() must emit '{$key}' in diagnostics"
            );
        }
    }

    public function test_normal_research_now_unchanged(): void {
        // run_research_now() must still call ModelResearchPipeline::run() (the shared filter path)
        $ref    = new \ReflectionClass( \TMWSEO\Engine\Admin\ModelHelper::class );
        $method = $ref->getMethod( 'run_research_now' );
        $file   = $method->getFileName();
        $start  = $method->getStartLine();
        $end    = $method->getEndLine();
        $lines  = file( (string) $file );
        $body   = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

        $this->assertStringContainsString( 'ModelResearchPipeline::run(', $body,
            'run_research_now() must still call ModelResearchPipeline::run() — not run_with_provider()'
        );
        $this->assertStringNotContainsString( 'run_with_provider', $body,
            'run_research_now() must NOT call run_with_provider() — Full Audit only'
        );
    }


    // ── Classification fix tests ──────────────────────────────────────────────

    public function test_run_with_provider_returns_run_completed_true_on_partial(): void {
        // run_with_provider must set run_completed=true when provider returns 'partial'
        // (zero candidates = still a completed run, not a failure)
        $ref    = new \ReflectionClass( \TMWSEO\Engine\Admin\ModelResearchPipeline::class );
        $method = $ref->getMethod( 'run_with_provider' );
        $file   = $method->getFileName();
        $start  = $method->getStartLine();
        $end    = $method->getEndLine();
        $lines  = file( (string) $file );
        $body   = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

        $this->assertStringContainsString( 'run_completed', $body,
            'run_with_provider must include run_completed in its return value'
        );
        $this->assertStringContainsString( "'partial'", $body,
            'run_with_provider must check for partial status in run_completed evaluation'
        );
    }

    public function test_run_full_audit_now_uses_run_completed_flag(): void {
        $ref    = new \ReflectionClass( \TMWSEO\Engine\Admin\ModelHelper::class );
        $method = $ref->getMethod( 'run_full_audit_now' );
        $file   = $method->getFileName();
        $start  = $method->getStartLine();
        $end    = $method->getEndLine();
        $lines  = file( (string) $file );
        $body   = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

        $this->assertStringContainsString( 'run_completed', $body,
            'run_full_audit_now must read run_completed from the pipeline result'
        );
    }

    public function test_run_full_audit_now_does_not_set_error_for_zero_candidates(): void {
        $ref    = new \ReflectionClass( \TMWSEO\Engine\Admin\ModelHelper::class );
        $method = $ref->getMethod( 'run_full_audit_now' );
        $file   = $method->getFileName();
        $start  = $method->getStartLine();
        $end    = $method->getEndLine();
        $lines  = file( (string) $file );
        $body   = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

        // The 'researched' write must be triggered by run_completed, not just pipeline='ok'
        $this->assertStringContainsString( '|| $run_completed', $body,
            'run_full_audit_now must set researched when run_completed=true, even if pipeline_status=error'
        );
        // Valid audit outcome comment
        $this->assertStringContainsString( 'valid result', $body,
            'run_full_audit_now must document that zero candidates is a valid completed result'
        );
    }

    public function test_probe_only_audit_returns_audit_completed_true(): void {
        $ref    = new \ReflectionClass( ModelFullAuditProvider::class );
        $method = $ref->getMethod( 'probe_only_audit' );
        $file   = $method->getFileName();
        $start  = $method->getStartLine();
        $end    = $method->getEndLine();
        $lines  = file( (string) $file );
        $body   = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

        $this->assertStringContainsString( 'audit_completed', $body,
            'probe_only_audit must include audit_completed in diagnostics'
        );
        $this->assertStringContainsString( 'no_matches_found', $body,
            'probe_only_audit must include no_matches_found in diagnostics'
        );
    }

    public function test_run_full_audit_probe_zero_hits_does_not_produce_error_status(): void {
        // Simulate a probe that finds nothing — all probes rejected.
        $probe = new TestableFullAuditProbe();
        $probe->set_default_response( false, 404, 'mock_404' );

        $seeds  = [ [ 'handle' => 'UnknownModel', 'source_platform' => 'name_derived', 'source_url' => '' ] ];
        $result = $probe->run_full_audit( $seeds, 0 );

        // Probe found nothing. That is a valid completed result.
        $this->assertArrayHasKey( 'verified_urls', $result );
        $this->assertEmpty( $result['verified_urls'],
            'Zero-hit probe should have empty verified_urls'
        );
        // The diagnostics should show attempts were made
        $this->assertGreaterThan( 0,
            (int) ( $result['diagnostics']['probes_attempted'] ?? 0 ),
            'Probes must have been attempted even when all return 404'
        );
    }

    public function test_corrupt_json_still_sets_error(): void {
        // Verify that run_full_audit_now only writes 'error' for real failures
        $ref    = new \ReflectionClass( \TMWSEO\Engine\Admin\ModelHelper::class );
        $method = $ref->getMethod( 'run_full_audit_now' );
        $file   = $method->getFileName();
        $start  = $method->getStartLine();
        $end    = $method->getEndLine();
        $lines  = file( (string) $file );
        $body   = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

        // Must still set error on encode failure
        $this->assertStringContainsString( 'wp_json_encode failed', $body );
        // Must still set error on round-trip failure
        $this->assertStringContainsString( 'round-trip decode failed', $body );
        // Must still set error in catch block
        $this->assertStringContainsString( 'Throwable', $body );
    }

    public function test_no_provider_sets_not_researched_not_error(): void {
        $ref    = new \ReflectionClass( \TMWSEO\Engine\Admin\ModelHelper::class );
        $method = $ref->getMethod( 'run_full_audit_now' );
        $file   = $method->getFileName();
        $start  = $method->getStartLine();
        $end    = $method->getEndLine();
        $lines  = file( (string) $file );
        $body   = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

        // 'no_provider' path must set 'not_researched', not 'error' or 'researched'
        $this->assertStringContainsString( "'not_researched'", $body );
        $this->assertStringContainsString( "no_provider", $body );
    }

    public function test_run_research_now_status_logic_unchanged(): void {
        // run_research_now should NOT have run_completed logic — that is audit-only
        $ref    = new \ReflectionClass( \TMWSEO\Engine\Admin\ModelHelper::class );
        $method = $ref->getMethod( 'run_research_now' );
        $file   = $method->getFileName();
        $start  = $method->getStartLine();
        $end    = $method->getEndLine();
        $lines  = file( (string) $file );
        $body   = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

        $this->assertStringNotContainsString( 'run_completed', $body,
            'run_research_now must not use run_completed flag — that is full-audit-specific'
        );
    }

    // ── Regression: audit-constant visibility & child-access safety ──────────
    //
    // Background: Full Audit silently failed because the parent class declared
    // AUDIT_* as `private const`, while the child (ModelFullAuditProvider)
    // referenced them via `self::AUDIT_*`. PHP does not inherit private
    // constants, so the child's `self::AUDIT_*` threw
    //   \Error: Undefined constant ModelFullAuditProvider::AUDIT_SERP_DEPTH
    // inside lookup(). The pipeline caught it as \Throwable and converted it
    // into a generic status=error, which the admin UI rendered as a red Error
    // banner alongside the empty merged skeleton ("No platform candidates").
    //
    // These tests lock in both halves of the fix so the bug can't silently
    // regress:
    //   1. All six AUDIT_* constants stay at protected (or public) visibility.
    //   2. Neither ModelFullAuditProvider nor the parent uses `self::AUDIT_*`
    //      anywhere in its source — the explicit `parent::AUDIT_*` form is
    //      required in the child for clarity and because reintroducing `self::`
    //      after someone changes visibility back to `private` would resurrect
    //      the original bug.

    private const AUDIT_CONST_NAMES = [
        'AUDIT_SERP_DEPTH',
        'AUDIT_MAX_HUB_PAGES',
        'AUDIT_PASS_TWO',
        'AUDIT_MAX_HANDLE_VARIANTS',
        'AUDIT_ALIAS_CAP',
        'AUDIT_SEED_CAP',
    ];

    public function test_parent_audit_constants_are_not_private(): void {
        // If any AUDIT_* is `private const` on the parent, the child cannot
        // read it — the exact runtime bug we are patching.
        $ref = new \ReflectionClass( ModelSerpResearchProvider::class );
        foreach ( self::AUDIT_CONST_NAMES as $name ) {
            $this->assertTrue(
                $ref->hasConstant( $name ),
                "ModelSerpResearchProvider must declare {$name}."
            );
            $rc = $ref->getReflectionConstant( $name );
            $this->assertNotFalse( $rc, "Reflection could not resolve {$name}." );
            $this->assertFalse(
                $rc->isPrivate(),
                "{$name} must NOT be `private const` on ModelSerpResearchProvider. "
                . "Private constants are not inherited; ModelFullAuditProvider would "
                . "throw \\Error: Undefined constant at runtime. Use `protected const`."
            );
        }
    }

    public function test_audit_constants_are_readable_from_child_scope(): void {
        // Positive reflection: the child can actually read the constants.
        // If they are private on the parent, ReflectionClass::getConstant on the
        // child will not surface them — this is the direct test of inheritance.
        $child = new \ReflectionClass( ModelFullAuditProvider::class );
        foreach ( self::AUDIT_CONST_NAMES as $name ) {
            $this->assertTrue(
                $child->hasConstant( $name ),
                "ModelFullAuditProvider must inherit {$name} from its parent. "
                . "If this fails, the parent's `private const` declarations have "
                . "been reintroduced or the inheritance chain was broken."
            );
        }
    }

    public function test_full_audit_provider_source_uses_parent_not_self_for_audit_constants(): void {
        // Static source check: ModelFullAuditProvider must not reach for
        // AUDIT_* constants via `self::`. Even if today's visibility is
        // protected, `self::AUDIT_*` in the child resolves correctly only
        // because the inheritance path happens to work; `parent::AUDIT_*`
        // states intent and makes the test above meaningful. If someone
        // switches the parent back to `private const`, `self::AUDIT_*` in the
        // child would silently break again; `parent::AUDIT_*` makes that
        // failure much easier to reason about.
        $src = file_get_contents(
            ( new \ReflectionClass( ModelFullAuditProvider::class ) )->getFileName()
        );
        $this->assertIsString( $src );
        $this->assertDoesNotMatchRegularExpression(
            '/\bself::AUDIT_[A-Z_]+/',
            $src,
            'ModelFullAuditProvider must reference inherited audit constants via '
            . '`parent::AUDIT_*`, not `self::AUDIT_*`. The latter silently breaks '
            . 'if the parent ever reverts those constants to `private const`.'
        );
        // And parent:: is actually used — not just "no self::".
        $this->assertMatchesRegularExpression(
            '/\bparent::AUDIT_[A-Z_]+/',
            $src,
            'ModelFullAuditProvider must use parent::AUDIT_* at least once — '
            . 'otherwise it is not touching the audit constants at all.'
        );
    }

    public function test_full_audit_provider_lookup_does_not_throw_on_audit_constant_access(): void {
        // Simulates the very first executable audit-constant access inside
        // lookup(), outside the WP request lifecycle. If the private/self::
        // bug ever comes back, this test is the one that would have caught
        // it without needing a live DataForSEO key or an AJAX round-trip.
        $provider = new class extends ModelFullAuditProvider {
            public function poke_audit_constants(): array {
                // Mirrors every audit-constant access point in lookup() and
                // probe_only_audit().
                return [
                    'serp_depth'    => parent::AUDIT_SERP_DEPTH,
                    'hub_pages'     => parent::AUDIT_MAX_HUB_PAGES,
                    'pass_two'      => parent::AUDIT_PASS_TWO,
                    'handle_cap'    => parent::AUDIT_MAX_HANDLE_VARIANTS,
                    'alias_cap'     => parent::AUDIT_ALIAS_CAP,
                    'seed_cap'      => parent::AUDIT_SEED_CAP,
                ];
            }
        };

        $dump = null;
        try {
            $dump = $provider->poke_audit_constants();
        } catch ( \Throwable $t ) {
            $this->fail(
                'Audit constant access from ModelFullAuditProvider scope threw '
                . get_class( $t ) . ': ' . $t->getMessage()
                . ' — this is the exact runtime failure that made Full Audit end with '
                . 'research_status=error in production.'
            );
        }

        $this->assertIsArray( $dump );
        $this->assertSame( 20,   $dump['serp_depth'] );
        $this->assertSame( 3,    $dump['hub_pages'] );
        $this->assertSame( true, $dump['pass_two'] );
        $this->assertSame( 10,   $dump['handle_cap'] );
        $this->assertSame( 10,   $dump['alias_cap'] );
        $this->assertSame( 12,   $dump['seed_cap'] );
    }
}
