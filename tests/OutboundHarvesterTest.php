<?php
/**
 * TMW SEO Engine — Outbound Harvester Tests (v5.2.0)
 *
 * Locks in PART C behaviour from the v5.2.0 prompt:
 *   - Fetches only from approved source hosts (link hubs, Facebook, Carrd
 *     subdomains, personal-website when explicitly flagged).
 *   - Extracts only absolute http(s) <a href> links; drops self-host,
 *     relative, fragment, mailto/tel.
 *   - Runs every extracted URL through the strict PlatformProfiles parser.
 *   - Enforces a handle-similarity guard so unrelated third-party profiles
 *     on a Beacons/Facebook page cannot sneak in.
 *   - Preserves the required evidence trail.
 *   - One-hop only — harvested links are NEVER re-harvested.
 *   - Honours the fetch / link / size budgets.
 *   - Parser strictness: invalid URL shapes are rejected.
 *
 * Plus one end-to-end test through ModelFullAuditProvider that proves
 * "Facebook page → Beacons" fallback discovery actually works.
 *
 * No live HTTP. fetch_page_body() is stubbed via a testable subclass.
 *
 * @package TMWSEO\Engine\Model\Tests
 * @since   5.2.0
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Model\Tests;

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Model\ModelOutboundHarvester;
use TMWSEO\Engine\Model\ModelFullAuditProvider;
use TMWSEO\Engine\Model\ModelPlatformProbe;
use TMWSEO\Engine\Platform\PlatformRegistry;

/** Harvester with a stubbable fetch_page_body(). */
class StubbedHarvester extends ModelOutboundHarvester {
    /** @var array<string,string> url => html body */
    public array $fixtures = [];
    /** @var array<int,string> order of fetch attempts */
    public array $fetch_log = [];

    public function add_fixture( string $url, string $html ): void {
        $this->fixtures[ $url ] = $html;
    }

    protected function fetch_page_body( string $url ): string {
        $this->fetch_log[] = $url;
        return $this->fixtures[ $url ] ?? '';
    }
}

/** Full-audit provider that swaps in a stubbed harvester + mock probe. */
class HarvestIntegrationProvider extends ModelFullAuditProvider {
    public ?StubbedHarvester $injected_harvester = null;
    public ?ModelPlatformProbe $injected_probe   = null;
    public array $mock_serp_items_p1             = [];

    protected function run_query_pack_pub( array $queries, int $depth, int $post_id ): array {
        return [
            'succeeded'   => 1,
            'failed'      => 0,
            'last_error'  => null,
            'items'       => $this->mock_serp_items_p1,
            'query_stats' => [],
        ];
    }
    protected function run_full_audit_probe( array $handle_seeds, int $post_id ): array {
        if ( $this->injected_probe !== null ) {
            return $this->injected_probe->run_full_audit( $handle_seeds, $post_id );
        }
        return [ 'verified_urls' => [], 'diagnostics' => [] ];
    }
    protected function run_outbound_harvest( array $seed_pages, array $handle_seeds, int $post_id ): array {
        if ( $this->injected_harvester !== null ) {
            return $this->injected_harvester->harvest( $seed_pages, $handle_seeds, $post_id );
        }
        return [ 'discovered' => [], 'diagnostics' => [] ];
    }

    /** Expose collect_harvest_seed_pages for assertions. */
    public function exposed_collect_harvest_seed_pages( array $probe_result, array $serp_items_p1 ): array {
        return $this->collect_harvest_seed_pages( $probe_result, $serp_items_p1 );
    }
}

class OutboundHarvesterTest extends TestCase {

    private const HANDLE_HINTS = [
        [ 'handle' => 'anisyia', 'source_platform' => 'name_derived', 'source_url' => '' ],
        [ 'handle' => 'Anisyia', 'source_platform' => 'name_derived', 'source_url' => '' ],
    ];

    // ── Approved-source gate ─────────────────────────────────────────────────

    public function test_approved_source_accepts_known_linkhubs(): void {
        $h = new ModelOutboundHarvester();
        $this->assertTrue(  $h->is_approved_source( 'https://beacons.ai/anisyia',      'linkhub' ) );
        $this->assertTrue(  $h->is_approved_source( 'https://linktr.ee/anisyia',       'linkhub' ) );
        $this->assertTrue(  $h->is_approved_source( 'https://allmylinks.com/anisyia',  'linkhub' ) );
        $this->assertTrue(  $h->is_approved_source( 'https://solo.to/anisyia',         'linkhub' ) );
        $this->assertTrue(  $h->is_approved_source( 'https://facebook.com/anisyia',    'facebook' ) );
        $this->assertTrue(  $h->is_approved_source( 'https://m.facebook.com/anisyia',  'facebook' ) );
    }
    public function test_approved_source_accepts_carrd_subdomain_only(): void {
        $h = new ModelOutboundHarvester();
        $this->assertTrue(  $h->is_approved_source( 'https://anisyia.carrd.co/', 'linkhub' ) );
        $this->assertFalse( $h->is_approved_source( 'https://carrd.co/',          'linkhub' ), 'bare carrd.co is not a profile' );
        $this->assertFalse( $h->is_approved_source( 'https://www.carrd.co/',      'linkhub' ), 'www.carrd.co is reserved' );
        $this->assertFalse( $h->is_approved_source( 'https://foo.bar.carrd.co/',  'linkhub' ), 'two-level subdomain is rejected' );
    }
    public function test_approved_source_rejects_random_host_without_personal_flag(): void {
        $h = new ModelOutboundHarvester();
        $this->assertFalse( $h->is_approved_source( 'https://random-blog.example/', 'linkhub' ) );
        $this->assertFalse( $h->is_approved_source( 'https://random-blog.example/', 'facebook' ) );
    }
    public function test_approved_source_accepts_personal_website_when_flagged_but_blocks_majors(): void {
        $h = new ModelOutboundHarvester();
        $this->assertTrue(  $h->is_approved_source( 'https://my-portfolio.com/about', 'personal_website' ) );
        $this->assertFalse( $h->is_approved_source( 'https://www.google.com/search',  'personal_website' ) );
        $this->assertFalse( $h->is_approved_source( 'https://youtube.com/user/x',     'personal_website' ) );
        $this->assertFalse( $h->is_approved_source( 'https://t.co/abc',                'personal_website' ) );
    }

    // ── Link extraction ──────────────────────────────────────────────────────

    public function test_extract_outbound_links_absolute_http_only(): void {
        $html = '<a href="https://beacons.ai/anisyia">Beacons</a>'
              . '<a href="/relative/path">rel</a>'
              . '<a href="#anchor">frag</a>'
              . '<a href="mailto:x@y.z">mail</a>'
              . '<a href="http://example.com/anisyia">other</a>';
        $h = new ModelOutboundHarvester();
        $out = $h->extract_outbound_links( $html, 'https://facebook.com/anisyia' );
        $this->assertContains( 'https://beacons.ai/anisyia', $out );
        $this->assertContains( 'http://example.com/anisyia', $out );
        $this->assertCount( 2, $out );
    }
    public function test_extract_outbound_links_drops_self_host(): void {
        $html = '<a href="https://beacons.ai/other-model">self</a>'
              . '<a href="https://linktr.ee/anisyia">ext</a>';
        $h = new ModelOutboundHarvester();
        $out = $h->extract_outbound_links( $html, 'https://beacons.ai/anisyia' );
        $this->assertSame( [ 'https://linktr.ee/anisyia' ], $out );
    }
    public function test_extract_outbound_links_single_and_double_quotes(): void {
        $html = "<a href='https://beacons.ai/anisyia'>s</a><a href=\"https://linktr.ee/anisyia\">d</a>";
        $h = new ModelOutboundHarvester();
        $out = $h->extract_outbound_links( $html, 'https://facebook.com/anisyia' );
        $this->assertContains( 'https://beacons.ai/anisyia',  $out );
        $this->assertContains( 'https://linktr.ee/anisyia',   $out );
    }
    public function test_extract_outbound_links_respects_max_links_per_page(): void {
        $html = '';
        for ( $i = 0; $i < 120; $i++ ) { $html .= '<a href="https://example' . $i . '.com/x">'; }
        $h = new ModelOutboundHarvester();
        $out = $h->extract_outbound_links( $html, 'https://beacons.ai/anisyia' );
        $this->assertLessThanOrEqual( ModelOutboundHarvester::MAX_LINKS_PER_PAGE, count( $out ) );
    }

    // ── Classification ───────────────────────────────────────────────────────

    public function test_classify_outbound_url_identifies_beacons(): void {
        $h = new ModelOutboundHarvester();
        $c = $h->classify_outbound_url( 'https://beacons.ai/anisyia' );
        $this->assertTrue( (bool) ( $c['success'] ?? false ) );
        $this->assertSame( 'beacons',  $c['normalized_platform'] );
        $this->assertSame( 'anisyia',  $c['username'] );
    }
    public function test_classify_outbound_url_rejects_junk(): void {
        $h = new ModelOutboundHarvester();
        $this->assertFalse( (bool) $h->classify_outbound_url( 'https://random-blog.example/x' )['success'] ?? false );
        $this->assertFalse( (bool) $h->classify_outbound_url( 'not-a-url' )['success'] ?? false );
    }

    // ── harvest() end-to-end over fixtures ──────────────────────────────────

    public function test_harvest_discovers_beacons_from_facebook_page(): void {
        $fb_url = 'https://facebook.com/anisyia';
        $fb_html = '<html><body>'
                 . '<a href="https://beacons.ai/anisyia">My Beacons</a>'
                 . '</body></html>';

        $h = new StubbedHarvester();
        $h->add_fixture( $fb_url, $fb_html );

        $result = $h->harvest(
            [ [ 'url' => $fb_url, 'source_type' => 'facebook', 'source_platform' => 'facebook' ] ],
            self::HANDLE_HINTS,
            0
        );
        $this->assertCount( 1, $result['discovered'], 'Exactly one new candidate must be discovered' );
        $d = $result['discovered'][0];
        $this->assertSame( 'beacons',                  $d['normalized_platform'] );
        $this->assertSame( 'anisyia',                  $d['username'] );
        $this->assertSame( 'https://beacons.ai/anisyia', $d['normalized_url'] );
        $this->assertSame( $fb_url,                    $d['source_url'] );
        $this->assertTrue( (bool) ( $d['discovered_via_outbound_harvest'] ?? false ) );

        // Evidence trail is mandatory per the v5.2.0 prompt.
        $ev = $d['evidence'] ?? [];
        $this->assertSame( 'outbound_harvest',          $ev['discovery_mode'] ?? '' );
        $this->assertSame( 'facebook',                  $ev['discovered_on_platform'] ?? '' );
        $this->assertSame( $fb_url,                     $ev['discovered_from_url'] ?? '' );
        $this->assertSame( 'https://beacons.ai/anisyia',$ev['extracted_outbound_url'] ?? '' );
        $this->assertSame( 'beacons',                   $ev['normalized_platform'] ?? '' );
        $this->assertSame( 'https://beacons.ai/anisyia',$ev['normalized_url'] ?? '' );
        $this->assertSame( 'success',                   $ev['parser_status'] ?? '' );
        $this->assertSame( 1,                           $ev['recursive_depth'] ?? 0 );
    }

    public function test_harvest_discovers_linkhub_from_beacons_page(): void {
        $beacons_url = 'https://beacons.ai/anisyia';
        $beacons_html = '<a href="https://chaturbate.com/anisyia/">CB</a>'
                      . '<a href="https://x.com/anisyia">X</a>';

        $h = new StubbedHarvester();
        $h->add_fixture( $beacons_url, $beacons_html );

        $result = $h->harvest(
            [ [ 'url' => $beacons_url, 'source_type' => 'linkhub', 'source_platform' => 'beacons' ] ],
            self::HANDLE_HINTS,
            0
        );
        $platforms = array_column( $result['discovered'], 'normalized_platform' );
        $this->assertContains( 'chaturbate', $platforms );
        $this->assertContains( 'twitter',    $platforms );
    }

    public function test_harvest_similarity_gate_rejects_unrelated_profile(): void {
        // Extracted link goes to a DIFFERENT user — must be blocked.
        $html = '<a href="https://beacons.ai/completely-unrelated">other</a>';
        $h = new StubbedHarvester();
        $h->add_fixture( 'https://facebook.com/anisyia', $html );

        $result = $h->harvest(
            [ [ 'url' => 'https://facebook.com/anisyia', 'source_type' => 'facebook', 'source_platform' => 'facebook' ] ],
            self::HANDLE_HINTS,
            0
        );
        $this->assertSame( [], $result['discovered'],
            'Unrelated usernames on a harvested page must be dropped by the similarity gate'
        );
    }

    public function test_harvest_similarity_gate_empty_hints_fails_closed(): void {
        $html = '<a href="https://beacons.ai/anisyia">ok</a>';
        $h = new StubbedHarvester();
        $h->add_fixture( 'https://facebook.com/anisyia', $html );
        $result = $h->harvest(
            [ [ 'url' => 'https://facebook.com/anisyia', 'source_type' => 'facebook', 'source_platform' => 'facebook' ] ],
            [], // no handle hints
            0
        );
        $this->assertSame( [], $result['discovered'],
            'Empty hint list must fail closed — no arbitrary third-party profiles accepted'
        );
    }

    public function test_harvest_rejects_non_approved_source(): void {
        $html = '<a href="https://beacons.ai/anisyia">ok</a>';
        $h = new StubbedHarvester();
        $h->add_fixture( 'https://random-site.example/', $html );
        $result = $h->harvest(
            [ [ 'url' => 'https://random-site.example/', 'source_type' => 'linkhub', 'source_platform' => 'unknown' ] ],
            self::HANDLE_HINTS,
            0
        );
        $this->assertSame( [], $result['discovered'] );
        $this->assertSame( 1, $result['diagnostics']['pages_skipped_host_not_approved'] );
        $this->assertSame( [], $h->fetch_log, 'Non-approved source must never be fetched' );
    }

    public function test_harvest_respects_max_fetches_budget(): void {
        $h = new StubbedHarvester();
        $pages = [];
        for ( $i = 0; $i < ModelOutboundHarvester::MAX_FETCHES + 5; $i++ ) {
            $u = 'https://beacons.ai/user' . $i;
            $h->add_fixture( $u, '<a href="https://linktr.ee/anisyia">x</a>' );
            $pages[] = [ 'url' => $u, 'source_type' => 'linkhub', 'source_platform' => 'beacons' ];
        }
        $result = $h->harvest( $pages, self::HANDLE_HINTS, 0 );
        $this->assertLessThanOrEqual(
            ModelOutboundHarvester::MAX_FETCHES,
            $result['diagnostics']['fetch_attempted']
        );
    }

    public function test_harvest_one_hop_only_does_not_refetch_discovered_links(): void {
        // If one-hop recursion is respected, the harvester must fetch the
        // seed page but NEVER fetch anything it extracted from that page.
        $seed   = 'https://facebook.com/anisyia';
        $hop1   = 'https://beacons.ai/anisyia';
        $hop2   = 'https://linktr.ee/anisyia';

        $h = new StubbedHarvester();
        $h->add_fixture( $seed, '<a href="' . $hop1 . '">beacons</a>' );
        // These fixtures exist ONLY so a bug that fetched them would succeed
        // — which would make the assertion fail.
        $h->add_fixture( $hop1, '<a href="' . $hop2 . '">linktree</a>' );
        $h->add_fixture( $hop2, '' );

        $h->harvest(
            [ [ 'url' => $seed, 'source_type' => 'facebook', 'source_platform' => 'facebook' ] ],
            self::HANDLE_HINTS,
            0
        );
        $this->assertSame( [ $seed ], $h->fetch_log,
            'One-hop only — harvester must fetch the seed page and nothing else'
        );
    }

    public function test_harvest_parser_strictness_drops_malformed_urls(): void {
        // A URL that looks like beacons but has an extra path segment must NOT
        // parse as a beacons profile — strict parser guarantees this.
        $html = '<a href="https://beacons.ai/anisyia/subpath">bad</a>'
              . '<a href="https://beacons.ai/anisyia">good</a>';
        $h = new StubbedHarvester();
        $h->add_fixture( 'https://facebook.com/anisyia', $html );
        $result = $h->harvest(
            [ [ 'url' => 'https://facebook.com/anisyia', 'source_type' => 'facebook', 'source_platform' => 'facebook' ] ],
            self::HANDLE_HINTS,
            0
        );
        // Only one candidate — the properly-shaped URL. The /subpath variant
        // must be rejected by PlatformProfiles::parse_url_for_platform_structured.
        $this->assertCount( 1, $result['discovered'] );
        $this->assertSame( 'https://beacons.ai/anisyia', $result['discovered'][0]['normalized_url'] );
    }

    public function test_harvest_emits_diagnostic_counters(): void {
        $html = '<a href="https://beacons.ai/anisyia">b</a>'
              . '<a href="https://chaturbate.com/anisyia/">c</a>'
              . '<a href="https://random-blog.example/irrelevant">x</a>';
        $h = new StubbedHarvester();
        $h->add_fixture( 'https://facebook.com/anisyia', $html );
        $r = $h->harvest(
            [ [ 'url' => 'https://facebook.com/anisyia', 'source_type' => 'facebook', 'source_platform' => 'facebook' ] ],
            self::HANDLE_HINTS,
            0
        );
        $d = $r['diagnostics'];
        $this->assertSame( 1, $d['fetch_attempted'] );
        $this->assertSame( 1, $d['fetch_succeeded'] );
        $this->assertSame( 3, $d['links_extracted'] );
        $this->assertSame( 2, $d['links_parsed_success'] );
        $this->assertSame( 2, $d['unique_new_candidates'] );
        $this->assertArrayHasKey( 'fetches', $d );
        $this->assertCount( 1, $d['fetches'] );
    }

    // ── End-to-end: ModelFullAuditProvider integrates the harvester ─────────

    public function test_full_audit_confirms_beacons_via_facebook_fallback(): void {
        // Scenario: SERP surfaces a Facebook page. Probe fails to confirm
        // Beacons directly (e.g. Cloudflare challenge). Harvester fetches
        // the FB page, extracts the Beacons link, confirms via the strict
        // parser. The final platform_candidates list MUST contain beacons
        // with the evidence trail attached.
        $fb_url = 'https://facebook.com/anisyia';
        $beacons_url = 'https://beacons.ai/anisyia';

        $stub_harvester = new StubbedHarvester();
        $stub_harvester->add_fixture( $fb_url, '<a href="' . $beacons_url . '">Beacons</a>' );

        // Mock probe: everything 404s (direct discovery fails).
        $probe = new class extends ModelPlatformProbe {
            protected function probe_url( string $url, string $slug, string $handle, int &$gfu ): array {
                return [ 'accepted' => false, 'status' => 404, 'reason' => 'mock_404' ];
            }
        };

        $provider = new HarvestIntegrationProvider();
        $provider->injected_harvester = $stub_harvester;
        $provider->injected_probe     = $probe;
        $provider->mock_serp_items_p1 = [
            [ 'url' => $fb_url, 'title' => 'Anisyia on Facebook' ],
        ];

        // Reach into lookup() indirectly by hitting the public helpers we rely on.
        $seed_pages = $provider->exposed_collect_harvest_seed_pages(
            [ 'verified_urls' => [], 'diagnostics' => [] ],
            $provider->mock_serp_items_p1
        );

        $this->assertCount( 1, $seed_pages, 'Facebook SERP item must produce one harvest seed page' );
        $this->assertSame( $fb_url,    $seed_pages[0]['url'] );
        $this->assertSame( 'facebook', $seed_pages[0]['source_type'] );

        $harvest_result = $stub_harvester->harvest(
            $seed_pages,
            self::HANDLE_HINTS,
            0
        );
        $this->assertCount( 1, $harvest_result['discovered'] );
        $this->assertSame( 'beacons', $harvest_result['discovered'][0]['normalized_platform'] );
    }

    public function test_collect_harvest_seed_pages_from_registry_linkhubs(): void {
        $provider = new HarvestIntegrationProvider();
        $probe_result = [
            'verified_urls' => [
                'https://beacons.ai/anisyia' => [
                    'slug' => 'beacons', 'username' => 'anisyia',
                    'handle' => 'anisyia', 'http_status' => 200,
                    'parse' => [ 'success' => true, 'normalized_url' => 'https://beacons.ai/anisyia' ],
                ],
                'https://linktr.ee/anisyia' => [
                    'slug' => 'linktree', 'username' => 'anisyia',
                    'handle' => 'anisyia', 'http_status' => 200,
                    'parse' => [ 'success' => true, 'normalized_url' => 'https://linktr.ee/anisyia' ],
                ],
                'https://chaturbate.com/anisyia' => [
                    'slug' => 'chaturbate', 'username' => 'anisyia',
                    'handle' => 'anisyia', 'http_status' => 200,
                    'parse' => [ 'success' => true, 'normalized_url' => 'https://chaturbate.com/anisyia' ],
                ],
            ],
            'diagnostics' => [],
        ];
        $pages = $provider->exposed_collect_harvest_seed_pages( $probe_result, [] );
        $urls  = array_column( $pages, 'url' );
        $this->assertContains( 'https://beacons.ai/anisyia', $urls );
        $this->assertContains( 'https://linktr.ee/anisyia', $urls );
        $this->assertNotContains( 'https://chaturbate.com/anisyia', $urls,
            'Cam platforms are not approved harvest sources'
        );
    }
}
