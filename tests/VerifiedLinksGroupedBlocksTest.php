<?php
/**
 * TMW SEO Engine — Verified External Links: Grouped Blocks Tests (5.1.0+)
 *
 * Pattern note
 * ────────────
 * The base WP stubs in tests/bootstrap/wordpress-stubs.php declare global
 * no-op versions of update_post_meta() / get_post_meta(). To intercept the
 * meta writes that VerifiedLinks::save_metabox() performs, we shadow those
 * functions in the SAME namespace as the class under test
 * (TMWSEO\Engine\Model). PHP resolves unqualified function calls from inside
 * that namespace to our overrides first, falling back to the global stub
 * for everything else (esc_url_raw, sanitize_key, etc.). Same approach as
 * tests/ModelHelperResearchPersistenceTest.php.
 *
 * Coverage:
 *   A. Family registry mapping correctness + completeness against ALLOWED_TYPES.
 *   B. save_metabox bucket-sorts rows by family display order.
 *   C. Within-family submission order is preserved.
 *   D. Legacy flat post-meta (with no family info) maps to the right bucket.
 *   E. Unknown / 'other' types route to the unmapped bucket — never dropped.
 *   F. Existing dedup, single-primary, MAX_LINKS contracts still hold.
 *   G. get_schema_urls() (sameAs) is unaffected by the grouping change.
 *
 * @package TMWSEO\Engine\Tests
 * @since   5.1.0
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Model;

use PHPUnit\Framework\TestCase;

// ── Namespace-scoped meta store + overrides ─────────────────────────────
$GLOBALS['_tmw_vl_grouped_meta'] = [];
$GLOBALS['_tmw_vl_ajax_success'] = null;
$GLOBALS['_tmw_vl_ajax_error']   = null;

/**
 * Namespace-scoped get_post_meta(). Resolved before the global stub when
 * called from inside TMWSEO\Engine\Model (i.e. from VerifiedLinks itself).
 */
function get_post_meta( int $id, string $key = '', bool $single = false ) {
    $value = $GLOBALS['_tmw_vl_grouped_meta'][ $id ][ $key ] ?? '';
    return $single ? $value : ( $value === '' ? [] : [ $value ] );
}

/**
 * Namespace-scoped update_post_meta(). Captures the JSON payload that
 * save_metabox() writes so the test can decode and assert on it.
 */
function update_post_meta( int $id, string $key, $value, $prev_value = '' ): bool {
    if ( is_string( $value ) ) {
        $value = stripslashes( $value );
    }
    $GLOBALS['_tmw_vl_grouped_meta'][ $id ][ $key ] = $value;
    return true;
}

/**
 * Namespace-scoped get_post_type(): treat all test posts as model posts.
 */
function get_post_type( $post = null ): string {
    return 'model';
}

/**
 * Namespace-scoped check_ajax_referer(): no-op pass in unit tests.
 */
function check_ajax_referer( $action = -1, $query_arg = false, $die = true ): bool {
    return true;
}

/**
 * Namespace-scoped wp_send_json_success() capture helper.
 *
 * @param mixed $data
 * @param int   $status_code
 * @throws AjaxSuccessException Always thrown to short-circuit execution.
 */
function wp_send_json_success( $data = null, int $status_code = 200 ): void {
    $GLOBALS['_tmw_vl_ajax_success'] = [ 'data' => $data, 'status' => $status_code ];
    throw new AjaxSuccessException( 'ajax_success' );
}

/**
 * Namespace-scoped wp_send_json_error() capture helper.
 *
 * @param mixed $data
 * @param int   $status_code
 * @throws AjaxErrorException Always thrown to short-circuit execution.
 */
function wp_send_json_error( $data = null, int $status_code = 200 ): void {
    $GLOBALS['_tmw_vl_ajax_error'] = [ 'data' => $data, 'status' => $status_code ];
    throw new AjaxErrorException( 'ajax_error' );
}

class AjaxSuccessException extends \RuntimeException {}
class AjaxErrorException extends \RuntimeException {}

class VerifiedLinksGroupedBlocksTest extends TestCase {

    /**
     * Reset in-memory stores before each test.
     */
    protected function setUp(): void {
        $GLOBALS['_tmw_vl_grouped_meta'] = [];
        $GLOBALS['_tmw_vl_ajax_success'] = null;
        $GLOBALS['_tmw_vl_ajax_error']   = null;
        $_POST = [];
    }

    // ── A. Family registry ────────────────────────────────────────────

    /**
     * Ensures visible family blocks remain in fixed UI order.
     */
    public function test_block_order_is_fixed_with_link_hubs_block(): void {
        $this->assertSame(
            [ 'cam_platform', 'personal_site', 'fansite', 'tube_site', 'social', 'link_hub' ],
            VerifiedLinksFamilies::block_order(),
            'Block display order must include the dedicated Link Hubs family in this order.'
        );
    }

    /**
     * Verifies the legacy/unmapped family is always rendered last in display order.
     */
    public function test_display_order_appends_unmapped_last(): void {
        $order = VerifiedLinksFamilies::display_order();
        $this->assertCount( 7, $order );
        $this->assertSame( 'unmapped', end( $order ) );
    }

    public function test_link_hub_types_resolve_to_link_hub_family(): void {
        $this->assertSame( 'link_hub', VerifiedLinksFamilies::family_for( 'linktree' ) );
        $this->assertSame( 'link_hub', VerifiedLinksFamilies::family_for( 'beacons' ) );
        $this->assertSame( 'link_hub', VerifiedLinksFamilies::family_for( 'allmylinks' ) );
    }

    public function test_social_family_excludes_link_hub_types(): void {
        $social = array_keys( VerifiedLinksFamilies::types_in_family( VerifiedLinksFamilies::FAMILY_SOCIAL ) );
        $this->assertSame( [ 'instagram', 'tiktok', 'x', 'facebook', 'youtube' ], $social );
        $this->assertNotContains( 'linktree', $social );
        $this->assertNotContains( 'beacons', $social );
        $this->assertNotContains( 'allmylinks', $social );
    }

    /**
     * Confirms every allowed type slug maps to a valid family bucket.
     */
    public function test_every_allowed_type_resolves_to_a_known_family(): void {
        foreach ( VerifiedLinks::ALLOWED_TYPES as $type ) {
            $family = VerifiedLinksFamilies::family_for( $type );
            $this->assertContains(
                $family,
                VerifiedLinksFamilies::display_order(),
                "ALLOWED_TYPES slug '{$type}' has no family in the registry."
            );
        }
    }

    /**
     * Asserts family registry types exactly match VerifiedLinks::ALLOWED_TYPES.
     */
    public function test_known_types_match_allowed_types_set(): void {
        $known   = VerifiedLinksFamilies::all_known_types();
        $allowed = VerifiedLinks::ALLOWED_TYPES;
        sort( $known );
        sort( $allowed );
        $this->assertSame(
            $allowed,
            $known,
            'Families registry and ALLOWED_TYPES must define the exact same slug set.'
        );
    }

    /**
     * Unknown/empty type values must resolve into the unmapped family.
     */
    public function test_unknown_or_empty_type_resolves_to_unmapped(): void {
        $this->assertSame( 'unmapped', VerifiedLinksFamilies::family_for( 'totally_unknown_slug' ) );
        $this->assertSame( 'unmapped', VerifiedLinksFamilies::family_for( '' ) );
    }

    /**
     * The explicit legacy "other" type is handled by the unmapped family.
     */
    public function test_other_routes_to_unmapped(): void {
        $this->assertSame( 'unmapped', VerifiedLinksFamilies::family_for( 'other' ) );
    }

    /**
     * Each family's configured default type must belong to that same family.
     */
    public function test_default_type_for_each_family_belongs_to_that_family(): void {
        foreach ( VerifiedLinksFamilies::block_order() as $family ) {
            $default = VerifiedLinksFamilies::default_type_for( $family );
            $this->assertSame(
                $family,
                VerifiedLinksFamilies::family_for( $default ),
                "Default type '{$default}' for family '{$family}' must belong to that family."
            );
        }
    }

    /**
     * Type slugs must not be shared across families.
     */
    public function test_types_in_families_are_pairwise_disjoint(): void {
        $seen = [];
        foreach ( VerifiedLinksFamilies::block_order() as $family ) {
            foreach ( array_keys( VerifiedLinksFamilies::types_in_family( $family ) ) as $slug ) {
                $this->assertArrayNotHasKey(
                    $slug,
                    $seen,
                    "Type '{$slug}' belongs to two families: '" . ( $seen[ $slug ] ?? '?' ) . "' and '{$family}'."
                );
                $seen[ $slug ] = $family;
            }
        }
    }

    // ── B. save_metabox bucket-sort ──────────────────────────────────

    /**
     * Save flow must bucket rows by family display order.
     */
    public function test_save_buckets_rows_by_family_display_order(): void {
        $post_id = 4001;
        $this->seed_post_for_save( $post_id );

        $_POST['tmwseo_vl'] = [
            0 => [ 'type' => 'instagram',     'url' => 'https://instagram.com/a',  'is_active' => '1' ],
            1 => [ 'type' => 'chaturbate',    'url' => 'https://chaturbate.com/a', 'is_active' => '1' ],
            2 => [ 'type' => 'fansly',        'url' => 'https://fansly.com/a',     'is_active' => '1' ],
            3 => [ 'type' => 'tiktok',        'url' => 'https://tiktok.com/@a',    'is_active' => '1' ],
            4 => [ 'type' => 'streamate',     'url' => 'https://streamate.com/a',  'is_active' => '1' ],
            5 => [ 'type' => 'personal_site', 'url' => 'https://example.com',      'is_active' => '1' ],
        ];

        VerifiedLinks::save_metabox( $post_id, $this->fake_post( $post_id ) );

        $stored = $this->read_stored( $post_id );
        $types  = array_map( static fn( $r ) => $r['type'], $stored );

        // Expected:
        //   cam_platform: chaturbate (1), streamate (4)
        //   personal_site: personal_site (5)
        //   fansite: fansly (2)
        //   tube_site: (none)
        //   social: instagram (0), tiktok (3)
        $this->assertSame(
            [ 'chaturbate', 'streamate', 'personal_site', 'fansly', 'instagram', 'tiktok' ],
            $types
        );
    }

    /**
     * Save flow must preserve row order within each family bucket.
     */
    public function test_within_family_submission_order_is_preserved(): void {
        $post_id = 4002;
        $this->seed_post_for_save( $post_id );

        $_POST['tmwseo_vl'] = [
            0 => [ 'type' => 'instagram', 'url' => 'https://instagram.com/three', 'is_active' => '1' ],
            1 => [ 'type' => 'instagram', 'url' => 'https://instagram.com/one',   'is_active' => '1' ],
            2 => [ 'type' => 'instagram', 'url' => 'https://instagram.com/two',   'is_active' => '1' ],
        ];

        VerifiedLinks::save_metabox( $post_id, $this->fake_post( $post_id ) );

        $urls = array_map( static fn( $r ) => $r['url'], $this->read_stored( $post_id ) );
        $this->assertSame(
            [
                'https://instagram.com/three',
                'https://instagram.com/one',
                'https://instagram.com/two',
            ],
            $urls
        );
    }

    /**
     * Saved link-hub rows should be bucketed into the dedicated Link Hubs block.
     */
    public function test_save_places_link_hub_rows_after_social_block(): void {
        $post_id = 4014;
        $this->seed_post_for_save( $post_id );

        $_POST['tmwseo_vl'] = [
            0 => [ 'type' => 'linktree',  'url' => 'https://linktr.ee/a',   'is_active' => '1' ],
            1 => [ 'type' => 'instagram', 'url' => 'https://instagram.com/a','is_active' => '1' ],
            2 => [ 'type' => 'beacons',   'url' => 'https://beacons.ai/a',  'is_active' => '1' ],
            3 => [ 'type' => 'youtube',   'url' => 'https://youtube.com/@a','is_active' => '1' ],
        ];

        VerifiedLinks::save_metabox( $post_id, $this->fake_post( $post_id ) );

        $types = array_map( static fn( $r ) => $r['type'], $this->read_stored( $post_id ) );
        $this->assertSame( [ 'instagram', 'youtube', 'linktree', 'beacons' ], $types );
    }

    // ── D. Legacy flat data still resolves correctly ─────────────────

    /**
     * Legacy flat arrays should still resolve to the expected family labels.
     */
    public function test_legacy_flat_post_meta_resolves_to_correct_families(): void {
        $legacy = [
            [ 'type' => 'youtube',       'url' => 'https://youtube.com/x',   'is_active' => true ],
            [ 'type' => 'linktree',      'url' => 'https://linktr.ee/x',     'is_active' => true ],
            [ 'type' => 'fancentro',     'url' => 'https://fancentro.com/x', 'is_active' => true ],
            [ 'type' => 'pornhub',       'url' => 'https://pornhub.com/x',   'is_active' => true ],
            [ 'type' => 'personal_site', 'url' => 'https://example.com',     'is_active' => true ],
        ];

        $resolved = array_map(
            static fn( $row ) => [
                'type'   => $row['type'],
                'family' => VerifiedLinksFamilies::family_for( $row['type'] ),
            ],
            $legacy
        );

        $this->assertSame(
            [
                [ 'type' => 'youtube',       'family' => 'social' ],
                [ 'type' => 'linktree',      'family' => 'link_hub' ],
                [ 'type' => 'fancentro',     'family' => 'fansite' ],
                [ 'type' => 'pornhub',       'family' => 'tube_site' ],
                [ 'type' => 'personal_site', 'family' => 'personal_site' ],
            ],
            $resolved
        );
    }

    /**
     * get_links() must round-trip pre-grouped legacy JSON payloads intact.
     */
    public function test_get_links_round_trips_legacy_array(): void {
        $post_id = 4007;
        // Simulate a pre-5.1.0 stored payload written before grouping existed.
        $legacy = [
            [
                'url' => 'https://instagram.com/legacy',
                'type' => 'instagram',
                'label' => '',
                'is_active' => true,
                'is_primary' => false,
                'added_at' => '2025-01-01',
                'promoted_from' => 'manual',
            ],
        ];
        $GLOBALS['_tmw_vl_grouped_meta'][ $post_id ][ VerifiedLinks::META_KEY ] = json_encode( $legacy );

        $links = VerifiedLinks::get_links( $post_id );
        $this->assertSame( 'active', $links[0]['activity_level'] ?? '' );
    }

    // ── E. Unknown legacy types never dropped ────────────────────────

    /**
     * Unmapped/legacy "other" entries are retained and sorted after mapped families.
     */
    public function test_other_type_routes_to_unmapped_bucket_at_end(): void {
        $post_id = 4003;
        $this->seed_post_for_save( $post_id );

        $_POST['tmwseo_vl'] = [
            0 => [ 'type' => 'instagram', 'url' => 'https://instagram.com/a', 'is_active' => '1' ],
            1 => [ 'type' => 'other',     'url' => 'https://weirdcam.io/x',   'is_active' => '1' ],
            2 => [ 'type' => 'pornhub',   'url' => 'https://pornhub.com/a',   'is_active' => '1' ],
        ];

        VerifiedLinks::save_metabox( $post_id, $this->fake_post( $post_id ) );

        $types = array_map( static fn( $r ) => $r['type'], $this->read_stored( $post_id ) );
        // Expected: pornhub (tube_site) → instagram (social) → other (unmapped)
        $this->assertSame( [ 'pornhub', 'instagram', 'other' ], $types );
    }

    // ── F. Dedup / Primary / MAX_LINKS still hold ────────────────────

    /**
     * URL normalization dedup should remain active after grouped bucket sorting.
     */
    public function test_dedup_still_applies_after_bucket_sort(): void {
        $post_id = 4004;
        $this->seed_post_for_save( $post_id );

        $_POST['tmwseo_vl'] = [
            0 => [ 'type' => 'instagram', 'url' => 'https://instagram.com/dup',  'is_active' => '1' ],
            1 => [ 'type' => 'tiktok',    'url' => 'https://tiktok.com/x',       'is_active' => '1' ],
            2 => [ 'type' => 'instagram', 'url' => 'https://instagram.com/dup/', 'is_active' => '1' ],
        ];

        VerifiedLinks::save_metabox( $post_id, $this->fake_post( $post_id ) );

        $stored = $this->read_stored( $post_id );
        $this->assertCount( 2, $stored, 'Trailing-slash duplicate must be deduped.' );
    }

    /**
     * Only one primary entry may survive even when multiple families submit primary rows.
     */
    public function test_single_primary_enforced_across_buckets(): void {
        $post_id = 4005;
        $this->seed_post_for_save( $post_id );

        $_POST['tmwseo_vl'] = [
            0 => [ 'type' => 'instagram',  'url' => 'https://instagram.com/a',  'is_active' => '1', 'is_primary' => '1' ],
            1 => [ 'type' => 'chaturbate', 'url' => 'https://chaturbate.com/a', 'is_active' => '1', 'is_primary' => '1' ],
            2 => [ 'type' => 'fansly',     'url' => 'https://fansly.com/a',     'is_active' => '1', 'is_primary' => '1' ],
        ];

        VerifiedLinks::save_metabox( $post_id, $this->fake_post( $post_id ) );

        $primary = array_filter( $this->read_stored( $post_id ), static fn( $r ) => ! empty( $r['is_primary'] ) );
        $this->assertCount( 1, $primary, 'At most one row may be primary across all buckets.' );
    }

    // ── G. Schema sameAs unaffected ──────────────────────────────────

    /**
     * sameAs output must include active URLs only, in final stored order.
     */
    public function test_get_schema_urls_returns_active_urls_in_stored_order(): void {
        $post_id = 4006;
        $this->seed_post_for_save( $post_id );

        $_POST['tmwseo_vl'] = [
            0 => [ 'type' => 'instagram',  'url' => 'https://instagram.com/a',  'is_active' => '1' ],
            1 => [ 'type' => 'chaturbate', 'url' => 'https://chaturbate.com/a', 'is_active' => '1' ],
            2 => [ 'type' => 'tiktok',     'url' => 'https://tiktok.com/x',     'is_active' => '0' ],
        ];

        VerifiedLinks::save_metabox( $post_id, $this->fake_post( $post_id ) );

        $urls = VerifiedLinks::get_schema_urls( $post_id );
        $this->assertSame(
            [
                'https://chaturbate.com/a', // cam_platform first
                'https://instagram.com/a',  // social second
            ],
            $urls
        );
        $this->assertNotContains( 'https://tiktok.com/x', $urls );
    }

    // ── H. Gutenberg-compatible AJAX persistence path ────────────────

    /**
     * AJAX fallback save path persists rows and respects canonical family ordering.
     */
    public function test_ajax_save_persists_new_rows_and_reorder(): void {
        $post_id = 4010;

        $_POST = [
            '_ajax_nonce' => 'test_nonce',
            'post_id'     => $post_id,
            'rows'        => wp_json_encode( [
                [ 'type' => 'instagram',  'url' => 'https://instagram.com/zeta', 'is_active' => '1' ],
                [ 'type' => 'chaturbate', 'url' => 'https://chaturbate.com/alpha', 'is_active' => '1', 'is_primary' => '1' ],
                [ 'type' => 'fansly',     'url' => 'https://fansly.com/beta', 'is_active' => '1' ],
            ] ),
        ];

        try {
            VerifiedLinks::ajax_save_verified_links();
            $this->fail( 'Expected ajax handler to terminate via wp_send_json_success().' );
        } catch ( AjaxSuccessException $e ) {
            $this->assertNotNull( $GLOBALS['_tmw_vl_ajax_success'] );
        }

        $stored = $this->read_stored( $post_id );
        $types  = array_map( static fn( $r ) => $r['type'], $stored );

        // Family order after save: cam -> fansite -> social.
        $this->assertSame( [ 'chaturbate', 'fansly', 'instagram' ], $types );
    }

    /**
     * AJAX fallback save path supports clearing previously saved rows.
     */
    public function test_ajax_save_allows_clearing_existing_rows(): void {
        $post_id = 4011;
        $GLOBALS['_tmw_vl_grouped_meta'][ $post_id ][ VerifiedLinks::META_KEY ] = wp_json_encode( [
            [ 'type' => 'instagram', 'url' => 'https://instagram.com/existing', 'is_active' => true ],
        ] );

        $_POST = [
            '_ajax_nonce' => 'test_nonce',
            'post_id'     => $post_id,
            'rows'        => wp_json_encode( [] ),
        ];

        try {
            VerifiedLinks::ajax_save_verified_links();
            $this->fail( 'Expected ajax handler to terminate via wp_send_json_success().' );
        } catch ( AjaxSuccessException $e ) {
            $this->assertSame( 0, (int) ( $GLOBALS['_tmw_vl_ajax_success']['data']['count'] ?? -1 ) );
        }

        $this->assertSame( [], $this->read_stored( $post_id ) );
    }

    public function test_save_persists_activity_fields_when_provided(): void {
        $post_id = 4012;
        $this->seed_post_for_save( $post_id );

        $_POST['tmwseo_vl'] = [
            0 => [
                'type' => 'instagram',
                'url' => 'https://instagram.com/a',
                'is_active' => '1',
                'activity_level' => 'very_active',
                'activity_note' => 'Posted twice this week',
            ],
        ];
        VerifiedLinks::save_metabox( $post_id, $this->fake_post( $post_id ) );
        $stored = $this->read_stored( $post_id );
        $this->assertSame( 'very_active', $stored[0]['activity_level'] ?? '' );
        $this->assertSame( 'Posted twice this week', $stored[0]['activity_note'] ?? '' );
    }

    public function test_save_persists_activity_audit_fields_when_provided(): void {
        $post_id = 4013;
        $this->seed_post_for_save( $post_id );

        $_POST['tmwseo_vl'] = [
            0 => [
                'type' => 'instagram',
                'url' => 'https://instagram.com/a',
                'is_active' => '1',
                'activity_level' => 'active',
                'activity_checked_at' => '2026-04-24',
                'activity_evidence_url' => 'https://evidence.example/audit',
            ],
        ];
        VerifiedLinks::save_metabox( $post_id, $this->fake_post( $post_id ) );
        $stored = $this->read_stored( $post_id );
        $this->assertSame( '2026-04-24', $stored[0]['activity_checked_at'] ?? '' );
        $this->assertSame( 'https://evidence.example/audit', $stored[0]['activity_evidence_url'] ?? '' );
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Seed POST with a valid nonce for save_metabox() tests.
     */
    private function seed_post_for_save( int $post_id ): void {
        $_POST = [
            'tmwseo_verified_links_nonce' => 'test_nonce_' . $post_id,
        ];
    }

    /**
     * Build a minimal WP_Post instance with the provided post ID.
     */
    private function fake_post( int $post_id ): \WP_Post {
        $p = new \WP_Post();
        $p->ID = $post_id;
        return $p;
    }

    /**
     * Read decoded stored verified-links payload from the in-memory meta store.
     *
     * @return array<int,array<string,mixed>>
     */
    private function read_stored( int $post_id ): array {
        $raw = (string) ( $GLOBALS['_tmw_vl_grouped_meta'][ $post_id ][ VerifiedLinks::META_KEY ] ?? '' );
        if ( $raw === '' ) {
            return [];
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }
}
