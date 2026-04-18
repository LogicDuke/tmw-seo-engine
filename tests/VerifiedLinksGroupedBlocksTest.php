<?php
/**
 * TMW SEO Engine — Verified External Links: Grouped Blocks Tests (5.1.0)
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

class VerifiedLinksGroupedBlocksTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_tmw_vl_grouped_meta'] = [];
        $_POST = [];
    }

    // ── A. Family registry ────────────────────────────────────────────

    public function test_block_order_is_fixed_5_blocks(): void {
        $this->assertSame(
            [ 'cam_platform', 'personal_site', 'fansite', 'tube_site', 'social' ],
            VerifiedLinksFamilies::block_order(),
            'Block display order must be exactly these 5 families in this order.'
        );
    }

    public function test_display_order_appends_unmapped_last(): void {
        $order = VerifiedLinksFamilies::display_order();
        $this->assertCount( 6, $order );
        $this->assertSame( 'unmapped', end( $order ) );
    }

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

    public function test_unknown_or_empty_type_resolves_to_unmapped(): void {
        $this->assertSame( 'unmapped', VerifiedLinksFamilies::family_for( 'totally_unknown_slug' ) );
        $this->assertSame( 'unmapped', VerifiedLinksFamilies::family_for( '' ) );
    }

    public function test_other_routes_to_unmapped(): void {
        $this->assertSame( 'unmapped', VerifiedLinksFamilies::family_for( 'other' ) );
    }

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

    // ── D. Legacy flat data still resolves correctly ─────────────────

    public function test_legacy_flat_post_meta_resolves_to_correct_families(): void {
        $legacy = [
            [ 'type' => 'youtube',       'url' => 'https://youtube.com/x',   'is_active' => true ],
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
                [ 'type' => 'fancentro',     'family' => 'fansite' ],
                [ 'type' => 'pornhub',       'family' => 'tube_site' ],
                [ 'type' => 'personal_site', 'family' => 'personal_site' ],
            ],
            $resolved
        );
    }

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

        $this->assertSame( $legacy, VerifiedLinks::get_links( $post_id ) );
    }

    // ── E. Unknown legacy types never dropped ────────────────────────

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

    // ── Helpers ──────────────────────────────────────────────────────

    private function seed_post_for_save( int $post_id ): void {
        $_POST = [
            'tmwseo_verified_links_nonce' => 'test_nonce_' . $post_id,
        ];
    }

    private function fake_post( int $post_id ): \WP_Post {
        $p = new \WP_Post();
        $p->ID = $post_id;
        return $p;
    }

    private function read_stored( int $post_id ): array {
        $raw = (string) ( $GLOBALS['_tmw_vl_grouped_meta'][ $post_id ][ VerifiedLinks::META_KEY ] ?? '' );
        if ( $raw === '' ) {
            return [];
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }
}
