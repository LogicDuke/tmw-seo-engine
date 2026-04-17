<?php
/**
 * TMW SEO Engine — Full Audit Mode Tests (v4.7.1)
 *
 * Single coherent architecture: Architecture 2.
 *
 *   ajax_run_full_audit()
 *     → run_full_audit_now()
 *       → ModelResearchPipeline::run_with_provider( $post_id, ModelFullAuditProvider )
 *         → ModelFullAuditProvider::lookup()
 *
 * No filter injection. No tmwseo_research_providers filter used by Full Audit.
 * No apply_filters in the Full Audit execution path.
 *
 * @package TMWSEO\Engine\Model\Tests
 * @since   4.7.1
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Model\Tests;

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Model\ModelPlatformProbe;
use TMWSEO\Engine\Model\ModelSerpResearchProvider;
use TMWSEO\Engine\Model\ModelFullAuditProvider;
use TMWSEO\Engine\Platform\PlatformRegistry;
use TMWSEO\Engine\Platform\PlatformProfiles;

// ── Test doubles ──────────────────────────────────────────────────────────────

class TestableFullAuditProbe extends ModelPlatformProbe {
    private array $mock_by_slug = [];
    private array $default_mock = ['accepted' => false, 'status' => 404, 'reason' => 'mock_default_404'];

    public function set_response_for_slug(string $slug, bool $accepted, int $status, string $reason): void {
        $this->mock_by_slug[$slug] = compact('accepted', 'status', 'reason');
    }
    public function set_default_response(bool $accepted, int $status, string $reason): void {
        $this->default_mock = compact('accepted', 'status', 'reason');
    }
    protected function probe_url(string $url, string $slug, string $handle, int &$get_fallbacks_used): array {
        return $this->mock_by_slug[$slug] ?? $this->default_mock;
    }
}

class TestableFullAuditProvider extends ModelFullAuditProvider {
    private ?TestableFullAuditProbe $mock_probe = null;
    private array $mock_serp_pack = [];

    public function inject_probe(TestableFullAuditProbe $probe): void { $this->mock_probe = $probe; }
    public function set_mock_serp_pack(array $pack): void { $this->mock_serp_pack = $pack; }

    protected function run_full_audit_probe(array $handle_seeds, int $post_id): array {
        if ($this->mock_probe !== null) {
            return $this->mock_probe->run_full_audit($handle_seeds, $post_id);
        }
        return ['verified_urls' => [], 'diagnostics' => []];
    }
    protected function run_query_pack_pub(array $queries, int $depth, int $post_id): array {
        if (!empty($this->mock_serp_pack)) { return $this->mock_serp_pack; }
        return ['succeeded' => 0, 'failed' => count($queries), 'last_error' => 'mock_no_serp', 'items' => [], 'query_stats' => []];
    }
}

class TestableAuditSerpProvider extends ModelSerpResearchProvider {
    public function call_build_query_pack_audit(string $name, array $aliases = []): array {
        return $this->build_query_pack_audit($name, $aliases);
    }
    public function call_build_handle_seeds_audit(array $successful, string $name): array {
        return $this->build_handle_seeds_audit($successful, $name);
    }
}

// ── Helper ────────────────────────────────────────────────────────────────────

function fa_method_body(string $class, string $method): string {
    $ref   = new \ReflectionClass($class);
    $m     = $ref->getMethod($method);
    $lines = file((string) $m->getFileName());
    return implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
}

// ── Test class ────────────────────────────────────────────────────────────────

class FullAuditModeTest extends TestCase {

    // ── J. Architecture 2 wiring: ajax_run_full_audit ────────────────────────

    /** ajax_run_full_audit must call run_full_audit_now() — not run_research_now(). */
    public function test_ajax_run_full_audit_calls_run_full_audit_now(): void {
        $body = fa_method_body(\TMWSEO\Engine\Admin\ModelHelper::class, 'ajax_run_full_audit');
        $this->assertStringContainsString('run_full_audit_now', $body,
            'ajax_run_full_audit must call run_full_audit_now() — Architecture 2');
        $this->assertStringNotContainsString('run_research_now', $body,
            'ajax_run_full_audit must NOT call run_research_now() — that is the Research Now path');
    }

    /** ajax_run_full_audit must not call provider->lookup() directly (old broken pattern). */
    public function test_ajax_run_full_audit_does_not_call_lookup_directly(): void {
        $body = fa_method_body(\TMWSEO\Engine\Admin\ModelHelper::class, 'ajax_run_full_audit');
        $this->assertStringNotContainsString('->lookup(', $body);
    }

    /** ajax_run_full_audit must NOT use filter injection (Architecture 1 was abandoned). */
    public function test_ajax_run_full_audit_does_not_use_filter_injection(): void {
        $body = fa_method_body(\TMWSEO\Engine\Admin\ModelHelper::class, 'ajax_run_full_audit');
        $this->assertStringNotContainsString('add_filter', $body,
            'No filter injection in Architecture 2');
        $this->assertStringNotContainsString('remove_filter', $body);
        $this->assertStringNotContainsString('tmwseo_research_providers', $body);
    }

    /** ajax_run_full_audit must guard against final_status staying queued. */
    public function test_ajax_run_full_audit_guards_against_queued_success(): void {
        $body = fa_method_body(\TMWSEO\Engine\Admin\ModelHelper::class, 'ajax_run_full_audit');
        $this->assertStringContainsString("'queued'", $body);
        $this->assertStringContainsString('wp_send_json_error', $body);
    }

    // ── J. Architecture 2 wiring: run_full_audit_now ─────────────────────────

    /** run_full_audit_now must call run_with_provider() — no apply_filters. */
    public function test_run_full_audit_now_calls_run_with_provider(): void {
        $body = fa_method_body(\TMWSEO\Engine\Admin\ModelHelper::class, 'run_full_audit_now');
        $this->assertStringContainsString('run_with_provider', $body);
        $this->assertStringNotContainsString('apply_filters', $body);
        $this->assertStringNotContainsString('tmwseo_research_providers', $body);
    }

    /** run_full_audit_now must own the full safety contract. */
    public function test_run_full_audit_now_has_complete_safety_contract(): void {
        $body = fa_method_body(\TMWSEO\Engine\Admin\ModelHelper::class, 'run_full_audit_now');
        $this->assertStringContainsString('acquire_research_lock', $body);
        $this->assertStringContainsString('release_research_lock', $body);
        $this->assertStringContainsString('finally', $body);
        $this->assertStringContainsString('Throwable', $body);
        $this->assertStringContainsString('wp_json_encode', $body);
        $this->assertStringContainsString('round-trip', $body);
    }

    // ── J. Architecture 2 wiring: run_with_provider ──────────────────────────

    /** run_with_provider must exist and never call apply_filters. */
    public function test_run_with_provider_exists_and_bypasses_filter(): void {
        $this->assertTrue(
            method_exists(\TMWSEO\Engine\Admin\ModelResearchPipeline::class, 'run_with_provider')
        );
        $body = fa_method_body(\TMWSEO\Engine\Admin\ModelResearchPipeline::class, 'run_with_provider');
        $this->assertStringNotContainsString('apply_filters', $body);
        $this->assertStringNotContainsString('get_providers', $body);
    }

    /** run_with_provider must include run_completed flag to distinguish zero-hit from crash. */
    public function test_run_with_provider_returns_run_completed_flag(): void {
        $body = fa_method_body(\TMWSEO\Engine\Admin\ModelResearchPipeline::class, 'run_with_provider');
        $this->assertStringContainsString('run_completed', $body);
        $this->assertStringContainsString("'partial'", $body,
            'partial must be treated as a completed run');
    }

    // ── K. Status classification ──────────────────────────────────────────────

    /** Zero-candidate completed audit must set researched, not error. */
    public function test_run_full_audit_now_sets_researched_for_zero_candidates(): void {
        $body = fa_method_body(\TMWSEO\Engine\Admin\ModelHelper::class, 'run_full_audit_now');
        $this->assertStringContainsString('run_completed', $body);
        $this->assertStringContainsString('|| $run_completed', $body,
            'researched must be set when run_completed=true even with zero candidates');
        $this->assertStringContainsString('valid result', $body);
    }

    /** Hard failures still set error. */
    public function test_hard_failures_still_set_error_status(): void {
        $body = fa_method_body(\TMWSEO\Engine\Admin\ModelHelper::class, 'run_full_audit_now');
        $this->assertStringContainsString('wp_json_encode failed', $body);
        $this->assertStringContainsString('round-trip decode failed', $body);
        $this->assertStringContainsString('Throwable', $body);
    }

    /** no_provider must set not_researched — not error, not researched. */
    public function test_no_provider_sets_not_researched(): void {
        $body = fa_method_body(\TMWSEO\Engine\Admin\ModelHelper::class, 'run_full_audit_now');
        $this->assertStringContainsString("'not_researched'", $body);
        $this->assertStringContainsString('no_provider', $body);
    }

    /** probe_only_audit must emit audit_completed and no_matches_found. */
    public function test_probe_only_audit_emits_completion_diagnostics(): void {
        $body = fa_method_body(ModelFullAuditProvider::class, 'probe_only_audit');
        $this->assertStringContainsString('audit_completed', $body);
        $this->assertStringContainsString('no_matches_found', $body);
    }

    /** Zero-hit run_full_audit has empty verified_urls but probes_attempted > 0. */
    public function test_zero_hit_probe_run_is_a_completed_run(): void {
        $probe = new TestableFullAuditProbe();
        $probe->set_default_response(false, 404, 'mock_404');
        $seeds  = [['handle' => 'UnknownModel', 'source_platform' => 'name_derived', 'source_url' => '']];
        $result = $probe->run_full_audit($seeds, 0);

        $this->assertEmpty($result['verified_urls']);
        $this->assertGreaterThan(0, (int)($result['diagnostics']['probes_attempted'] ?? 0),
            'Probes must be attempted even when all reject');
    }

    // ── L. Research Now unchanged ─────────────────────────────────────────────

    /** ajax_trigger_research must call run_research_now — no run_with_provider. */
    public function test_ajax_trigger_research_unchanged(): void {
        $body = fa_method_body(\TMWSEO\Engine\Admin\ModelHelper::class, 'ajax_trigger_research');
        $this->assertStringContainsString('run_research_now', $body);
        $this->assertStringNotContainsString('run_with_provider', $body);
        $this->assertStringNotContainsString('run_full_audit_now', $body);
    }

    /** run_research_now must use ModelResearchPipeline::run() — no run_completed. */
    public function test_run_research_now_uses_pipeline_run(): void {
        $body = fa_method_body(\TMWSEO\Engine\Admin\ModelHelper::class, 'run_research_now');
        $this->assertStringContainsString('ModelResearchPipeline::run(', $body);
        $this->assertStringNotContainsString('run_with_provider', $body);
        $this->assertStringNotContainsString('run_completed', $body,
            'run_completed flag is Full Audit-specific');
    }

    // ── M. Audit-mode provider behavior ──────────────────────────────────────

    /** lookup() must use AUDIT_SERP_DEPTH and build_query_pack_audit. */
    public function test_audit_provider_uses_audit_mode_constants(): void {
        $body = fa_method_body(ModelFullAuditProvider::class, 'lookup');
        $this->assertStringContainsString('AUDIT_SERP_DEPTH', $body);
        $this->assertStringNotContainsString('SYNC_SERP_DEPTH', $body);
        $this->assertStringContainsString('build_query_pack_audit', $body);
    }

    /** lookup() must emit required audit_config diagnostics. */
    public function test_audit_provider_emits_audit_config_diagnostics(): void {
        $body = fa_method_body(ModelFullAuditProvider::class, 'lookup');
        foreach (['audit_mode', 'audit_config', 'serp_depth_used', 'pass_two_enabled',
                  'probes_attempted', 'duration_ms', 'full_registry_sweep_included',
                  'platforms_confirmed'] as $key) {
            $this->assertStringContainsString($key, $body,
                "lookup() must emit '{$key}' in diagnostics");
        }
    }

    /** ModelFullAuditProvider::make() returns correct type and provider_name. */
    public function test_full_audit_provider_make(): void {
        $p = ModelFullAuditProvider::make();
        $this->assertInstanceOf(ModelFullAuditProvider::class, $p);
        $this->assertInstanceOf(ModelSerpResearchProvider::class, $p);
        $this->assertSame('full_audit', $p->provider_name());
    }

    // ── D. build_query_pack_audit ─────────────────────────────────────────────

    /** Audit query pack includes full_registry_sweep. */
    public function test_audit_query_pack_includes_registry_sweep(): void {
        $p = new TestableAuditSerpProvider();
        $families = array_column($p->call_build_query_pack_audit('Allysa Quinn'), 'family');
        $this->assertContains('full_registry_sweep', $families);
    }

    /** Registry sweep query contains fansly, chaturbate, stripchat. */
    public function test_registry_sweep_contains_platform_domains(): void {
        $p   = new TestableAuditSerpProvider();
        $qs  = $p->call_build_query_pack_audit('Test Model');
        $sw  = array_values(array_filter($qs, static fn($q) => ($q['family'] ?? '') === 'full_registry_sweep'));
        $this->assertNotEmpty($sw);
        $q   = (string)($sw[0]['query'] ?? '');
        $this->assertStringContainsString('fansly.com',     $q);
        $this->assertStringContainsString('chaturbate.com', $q);
        $this->assertStringContainsString('stripchat.com',  $q);
    }

    /** Each alias generates 3 query families in audit mode. */
    public function test_audit_query_pack_generates_3_families_per_alias(): void {
        $p   = new TestableAuditSerpProvider();
        $qs  = $p->call_build_query_pack_audit('Aisha Dupont', ['OhhAisha', 'AishaX']);
        $for = array_filter($qs, static fn($q) => ($q['_alias_source'] ?? '') === 'OhhAisha');
        $this->assertGreaterThanOrEqual(3, count($for));
        $fam = array_column(array_values($for), 'family');
        $this->assertContains('alias_webcam_discovery',     $fam);
        $this->assertContains('alias_creator_discovery',    $fam);
        $this->assertContains('alias_hub_social_discovery', $fam);
    }

    // ── E. Raised caps vs sync ────────────────────────────────────────────────

    /** FULL_AUDIT_MAX_PROBES > 6 and >= registry count. */
    public function test_full_audit_probe_budget_covers_registry(): void {
        $n = count(PlatformRegistry::get_slugs());
        $this->assertGreaterThan(6, ModelPlatformProbe::FULL_AUDIT_MAX_PROBES);
        $this->assertGreaterThanOrEqual($n, ModelPlatformProbe::FULL_AUDIT_MAX_PROBES);
    }

    /** build_handle_seeds_audit returns more than 5 seeds. */
    public function test_audit_seed_cap_higher_than_sync(): void {
        $p = new TestableAuditSerpProvider();
        $successful = [];
        foreach (array_slice(PlatformRegistry::get_slugs(), 0, 10) as $i => $slug) {
            $successful[] = ['success' => true, 'username' => 'h_' . $i,
                             'normalized_platform' => $slug, 'source_url' => ''];
        }
        $seeds = $p->call_build_handle_seeds_audit($successful, 'Test Model');
        $this->assertGreaterThan(5, count($seeds));
    }

    // ── A. Full-registry probe coverage ──────────────────────────────────────

    /** run_full_audit attempts every probeable registry slug. */
    public function test_run_full_audit_attempts_all_registry_slugs(): void {
        $probe = new TestableFullAuditProbe();
        $probe->set_default_response(true, 200, 'mock_ok');
        $seeds  = [['handle' => 'TestModel', 'source_platform' => 'name_derived', 'source_url' => '']];
        $result = $probe->run_full_audit($seeds, 0);
        $cov    = $result['diagnostics']['platform_coverage'] ?? [];

        foreach (PlatformRegistry::get_slugs() as $slug) {
            if ($probe->synthesize_candidate_url($slug, 'TestModel') === '') continue;
            $this->assertArrayHasKey($slug, $cov, "'{$slug}' must appear in coverage");
            $this->assertNotEquals('not_probed', $cov[$slug]['status'] ?? 'not_probed',
                "'{$slug}' must be attempted");
        }
    }

    /** Coverage map statuses are valid. */
    public function test_coverage_map_statuses_are_valid(): void {
        $probe = new TestableFullAuditProbe();
        $probe->set_default_response(false, 404, 'mock_404');
        $seeds  = [['handle' => 'CovTest', 'source_platform' => 'name_derived', 'source_url' => '']];
        $result = $probe->run_full_audit($seeds, 0);
        $cov    = $result['diagnostics']['platform_coverage'] ?? [];
        $this->assertNotEmpty($cov);
        foreach ($cov as $slug => $entry) {
            $this->assertContains($entry['status'] ?? '', ['confirmed', 'rejected', 'not_probed'],
                "Invalid status for '{$slug}'");
        }
    }

    // ── B. fansly regression ─────────────────────────────────────────────────

    /** fansly must be probed (was absent from sync PROBE_PRIORITY_SLUGS). */
    public function test_fansly_is_probed_in_full_audit(): void {
        $probe = new TestableFullAuditProbe();
        $probe->set_default_response(false, 404, 'mock_404');
        $seeds  = [['handle' => 'TestUser', 'source_platform' => 'name_derived', 'source_url' => '']];
        $result = $probe->run_full_audit($seeds, 0);
        $cov    = $result['diagnostics']['platform_coverage'] ?? [];
        $this->assertArrayHasKey('fansly', $cov);
        $this->assertNotEquals('not_probed', $cov['fansly']['status'] ?? 'not_probed');
    }

    // ── G. Confirmed vs rejected ──────────────────────────────────────────────

    public function test_confirmed_vs_rejected_classification(): void {
        $probe = new TestableFullAuditProbe();
        $probe->set_response_for_slug('chaturbate', true,  200, 'mock_ok');
        $probe->set_response_for_slug('stripchat',  false, 404, 'mock_rejected');
        $probe->set_default_response(false, 404, 'mock_404');
        $seeds  = [['handle' => 'AnisyiaTest', 'source_platform' => 'name_derived', 'source_url' => '']];
        $result = $probe->run_full_audit($seeds, 0);
        $cov    = $result['diagnostics']['platform_coverage'] ?? [];
        $this->assertNotEquals('not_probed', $cov['chaturbate']['status'] ?? 'not_probed');
        $this->assertEquals('rejected', $cov['stripchat']['status'] ?? '');
    }

    // ── F. Manual usernames ───────────────────────────────────────────────────

    public function test_run_full_audit_does_not_write_post_meta(): void {
        $probe = new TestableFullAuditProbe();
        $probe->set_default_response(true, 200, 'mock_ok');
        $seeds  = [['handle' => 'TestUser', 'source_platform' => 'name_derived', 'source_url' => '']];
        $result = $probe->run_full_audit($seeds, 0);
        $this->assertArrayHasKey('verified_urls', $result);
        $this->assertArrayNotHasKey('meta_writes', $result);
    }

    // ── H. Fragment-only platforms ────────────────────────────────────────────

    public function test_fragment_only_platform_synthesizes_empty_url(): void {
        $probe = new TestableFullAuditProbe();
        $this->assertSame('', $probe->synthesize_candidate_url('myfreecams', 'TestUser'));
    }

    public function test_fragment_only_platform_never_confirmed(): void {
        $probe = new TestableFullAuditProbe();
        $probe->set_default_response(false, 404, 'mock');
        $seeds  = [['handle' => 'TestUser', 'source_platform' => 'name_derived', 'source_url' => '']];
        $result = $probe->run_full_audit($seeds, 0);
        $cov    = $result['diagnostics']['platform_coverage'] ?? [];
        $this->assertNotEquals('confirmed', $cov['myfreecams']['status'] ?? 'not_present');
    }

    // ── I. Ambiguous URLs ─────────────────────────────────────────────────────

    public function test_host_mismatch_url_rejected_with_reason(): void {
        $r = PlatformProfiles::parse_url_for_platform_structured('chaturbate', 'https://stripchat.com/SomeModel');
        $this->assertFalse((bool)($r['success'] ?? true));
        $this->assertSame('host_mismatch', $r['reject_reason'] ?? '');
    }

    public function test_listing_path_not_extracted_as_username(): void {
        $r = PlatformProfiles::parse_url_for_platform_structured('stripchat', 'https://stripchat.com/models/new');
        if (!empty($r['success'])) {
            $this->assertNotSame('models', $r['username'] ?? '');
        } else {
            $this->assertNotEmpty($r['reject_reason']);
        }
    }

    // ── N. probe_only_audit is valid when DataForSEO absent ──────────────────

    public function test_probe_only_audit_is_valid_completed_outcome(): void {
        $body = fa_method_body(ModelFullAuditProvider::class, 'probe_only_audit');
        $this->assertStringContainsString('audit_completed', $body);
        $this->assertStringContainsString("'partial'", $body);
    }

    // ── O. Safe Mode ─────────────────────────────────────────────────────────

    public function test_lookup_returns_no_provider_in_safe_mode(): void {
        $body = fa_method_body(ModelFullAuditProvider::class, 'lookup');
        $this->assertStringContainsString('no_provider', $body);
        $this->assertStringContainsString('safe_mode', strtolower($body));
    }

    // ── Regression: sync probe ────────────────────────────────────────────────

    public function test_sync_probe_still_reaches_chaturbate(): void {
        $probe = new TestableFullAuditProbe();
        $probe->set_default_response(false, 404, 'mock_404');
        $result = $probe->run([['handle' => 'Anisyia', 'source_platform' => 'name_derived', 'source_url' => '']], [], 0);
        $this->assertContains('chaturbate', array_column($result['diagnostics']['probe_log'] ?? [], 'slug'));
    }

    public function test_sync_probe_still_reaches_stripchat(): void {
        $probe = new TestableFullAuditProbe();
        $probe->set_default_response(false, 404, 'mock_404');
        $result = $probe->run([['handle' => 'Anisyia', 'source_platform' => 'name_derived', 'source_url' => '']], [], 0);
        $this->assertContains('stripchat', array_column($result['diagnostics']['probe_log'] ?? [], 'slug'));
    }

    // ── Gutenberg / JS ────────────────────────────────────────────────────────

    public function test_full_audit_js_uses_shared_silent_reload(): void {
        $body = fa_method_body(\TMWSEO\Engine\Admin\ModelHelper::class, 'render_metabox');
        $this->assertStringContainsString('function silentReload', $body);
        $this->assertStringContainsString('resetPost', $body);
        $this->assertStringContainsString('tmwseo_run_full_audit', $body);
        $this->assertStringContainsString('silentReload', $body);
    }
}
