<?php
/**
 * TMW SEO Engine — Full Audit Link-Hub Recall Tests (v5.3.0)
 *
 * Locks in the recall fix for the v5.2.0 "supported Beacons link missed"
 * bug.
 *
 *   ROOT CAUSE (v5.2.0):
 *     ModelFullAuditProvider::collect_harvest_seed_pages() only treated
 *     a link-hub URL as a harvest seed when it had ALREADY appeared in
 *     the probe phase's verified_urls. If the probe synthesized a
 *     different candidate URL for the slug — or skipped it because of
 *     a case-sensitivity miss — the SERP-surfaced Beacons URL never
 *     became a harvest source, so its outbound links were never picked
 *     up.
 *
 *   FIX:
 *     collect_harvest_seed_pages() now ALSO walks the SERP pass-1 items
 *     and seeds any URL whose host matches a registered link-hub
 *     (beacons.ai, linktr.ee, allmylinks.com, solo.to, carrd.co), even
 *     if the probe phase missed it. Source type is recorded as
 *     'linkhub_serp' so the operator can audit which path discovered it.
 *
 * @package TMWSEO\Engine\Model\Tests
 * @since   5.3.0
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Model\Tests;

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Model\ModelFullAuditProvider;

/**
 * Test subclass exposing the protected collect_harvest_seed_pages()
 * method without booting the full SERP / probe pipeline.
 */
class HarvestSeedExposingProvider extends ModelFullAuditProvider {
    public function call_collect_harvest_seed_pages( array $probe_result, array $serp_items_p1 ): array {
        return $this->collect_harvest_seed_pages( $probe_result, $serp_items_p1 );
    }
}

final class FullAuditLinkHubRecallTest extends TestCase {

    private HarvestSeedExposingProvider $provider;

    protected function setUp(): void {
        $this->provider = new HarvestSeedExposingProvider();
    }

    /**
     * R-LH-1. THE BUG REPLAY — a SERP-surfaced Beacons URL becomes a
     * harvest seed even when the probe phase has zero verified Beacons URLs.
     *
     * Before v5.3.0: this returned [].
     * After v5.3.0:  the Beacons URL is in the seed list with source_type='linkhub_serp'.
     */
    public function test_serp_surfaced_beacons_url_becomes_harvest_seed(): void {
        $probe_result = [
            'verified_urls' => [],   // probe missed Beacons entirely
            'diagnostics'   => [],
        ];
        $serp_items = [
            [ 'url' => 'https://beacons.ai/anisyia',  'title' => 'Anisyia | Beacons' ],
            [ 'url' => 'https://chaturbate.com/anisyia/' ],   // not a link hub
            [ 'url' => 'https://twitter.com/anisyia' ],       // not a link hub
        ];

        $seeds = $this->provider->call_collect_harvest_seed_pages( $probe_result, $serp_items );

        $beacons = array_values( array_filter( $seeds, static fn ( $s ) =>
            ( $s['source_platform'] ?? '' ) === 'beacons'
        ) );

        $this->assertCount( 1, $beacons, 'Beacons URL must be surfaced as a harvest seed' );
        $this->assertSame( 'https://beacons.ai/anisyia', $beacons[0]['url'] );
        $this->assertSame( 'linkhub_serp', $beacons[0]['source_type'],
            'SERP-derived link-hub URLs must be tagged linkhub_serp for diagnostics' );
    }

    /**
     * R-LH-2. All five registered link hubs (beacons, linktree,
     * allmylinks, solo_to, carrd) get the SERP-surfaced treatment.
     */
    public function test_all_link_hubs_picked_up_from_serp(): void {
        $serp_items = [
            [ 'url' => 'https://beacons.ai/anisyia' ],
            [ 'url' => 'https://linktr.ee/anisyia' ],
            [ 'url' => 'https://allmylinks.com/anisyia' ],
            [ 'url' => 'https://solo.to/anisyia' ],
            [ 'url' => 'https://anisyia.carrd.co' ],
        ];

        $seeds = $this->provider->call_collect_harvest_seed_pages(
            [ 'verified_urls' => [] ],
            $serp_items
        );

        $by_platform = [];
        foreach ( $seeds as $s ) {
            $by_platform[ $s['source_platform'] ?? '' ] = $s;
        }

        $this->assertArrayHasKey( 'beacons',    $by_platform );
        $this->assertArrayHasKey( 'linktree',   $by_platform );
        $this->assertArrayHasKey( 'allmylinks', $by_platform );
        $this->assertArrayHasKey( 'solo_to',    $by_platform );
        $this->assertArrayHasKey( 'carrd',      $by_platform,
            'wildcard subdomain {handle}.carrd.co must match the carrd link-hub' );
    }

    /**
     * R-LH-3. SERP-surfaced and probe-confirmed pages for the SAME URL
     * are deduplicated — the operator should not see two harvest tasks
     * for one Beacons page.
     */
    public function test_dedup_when_probe_and_serp_both_surface_the_same_url(): void {
        $probe_result = [
            'verified_urls' => [
                'https://beacons.ai/anisyia' => [
                    'slug'  => 'beacons',
                    'parse' => [ 'normalized_url' => 'https://beacons.ai/anisyia' ],
                ],
            ],
        ];
        $serp_items = [
            [ 'url' => 'https://beacons.ai/anisyia' ],
        ];

        $seeds = $this->provider->call_collect_harvest_seed_pages( $probe_result, $serp_items );

        $beacons = array_values( array_filter( $seeds, static fn ( $s ) =>
            ( $s['source_platform'] ?? '' ) === 'beacons'
        ) );

        $this->assertCount( 1, $beacons,
            'identical link-hub URL must not produce duplicate harvest seeds' );
    }

    /**
     * R-LH-4. NON-link-hub hosts surfaced by SERP do NOT get added as
     * link-hub seeds — the probe still owns those.
     */
    public function test_non_linkhub_hosts_are_not_pulled_into_linkhub_lane(): void {
        $serp_items = [
            [ 'url' => 'https://chaturbate.com/anisyia/' ],
            [ 'url' => 'https://onlyfans.com/anisyia' ],
            [ 'url' => 'https://twitter.com/anisyia' ],
        ];

        $seeds = $this->provider->call_collect_harvest_seed_pages(
            [ 'verified_urls' => [] ],
            $serp_items
        );

        // None of these hosts is a registered link-hub or facebook, so
        // the harvest lane must be empty.
        $this->assertCount( 0, $seeds,
            'non-link-hub, non-facebook hosts must produce zero harvest seeds' );

        // Defence-in-depth — even if a regression added them with a
        // different source_type, none can carry the linkhub_serp tag.
        foreach ( $seeds as $s ) {
            $this->assertNotSame( 'linkhub_serp', $s['source_type'] ?? '',
                'non-link-hub hosts must not be tagged linkhub_serp' );
        }
    }

    /**
     * R-LH-5. Facebook pages are still surfaced (regression guard for
     * the v5.2.0 facebook recall lane). The new link-hub lane must not
     * have removed it.
     */
    public function test_facebook_recall_lane_preserved(): void {
        $serp_items = [
            [ 'url' => 'https://www.facebook.com/anisyia' ],
            [ 'url' => 'https://m.facebook.com/anisyia' ],
        ];

        $seeds = $this->provider->call_collect_harvest_seed_pages(
            [ 'verified_urls' => [] ],
            $serp_items
        );

        $facebook = array_values( array_filter( $seeds, static fn ( $s ) =>
            ( $s['source_platform'] ?? '' ) === 'facebook'
        ) );

        $this->assertGreaterThanOrEqual( 1, count( $facebook ),
            'Facebook recall lane must still be present in v5.3.0' );
    }

    /**
     * R-LH-6. Empty SERP + empty probe returns an empty seed list —
     * no false positives sneak in.
     */
    public function test_empty_inputs_return_empty(): void {
        $seeds = $this->provider->call_collect_harvest_seed_pages(
            [ 'verified_urls' => [] ],
            []
        );
        $this->assertSame( [], $seeds );
    }
}
