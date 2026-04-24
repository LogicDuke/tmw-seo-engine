<?php
/**
 * TMW SEO Engine — Verified External Links: Family Registry
 *
 * Single source of truth that maps every Verified External Link `type` slug
 * to one of six family blocks, plus an `unmapped` bucket for legacy/unknown
 * slugs.
 *
 * Block display order is fixed:
 *   1. cam_platform   (Cam Platforms)
 *   2. personal_site  (Personal Website)
 *   3. fansite        (Fansites)
 *   4. tube_site      (Tube Sites)
 *   5. social         (Social Media)
 *   6. link_hub       (Link Hubs)
 *   7. unmapped       (Other / Legacy — only rendered when populated)
 *
 * The registry is intentionally small and pure (no WP calls in static maps)
 * so it is safe to consume from PHPUnit without a full WP bootstrap.
 *
 * @package TMWSEO\Engine\Model
 * @since   5.1.0
 */
namespace TMWSEO\Engine\Model;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class VerifiedLinksFamilies {

    const FAMILY_CAM      = 'cam_platform';
    const FAMILY_PERSONAL = 'personal_site';
    const FAMILY_FANSITE  = 'fansite';
    const FAMILY_TUBE     = 'tube_site';
    const FAMILY_SOCIAL   = 'social';
    const FAMILY_LINK_HUB = 'link_hub';
    const FAMILY_UNMAPPED = 'unmapped';

    /**
     * Display order for the six operator-facing blocks.
     * `unmapped` is appended at runtime by display_order() and only rendered
     * when at least one row falls into it.
     *
     * @return string[]
     */
    public static function block_order(): array {
        return [
            self::FAMILY_CAM,
            self::FAMILY_PERSONAL,
            self::FAMILY_FANSITE,
            self::FAMILY_TUBE,
            self::FAMILY_SOCIAL,
            self::FAMILY_LINK_HUB,
        ];
    }

    /**
     * Full display order including the unmapped bucket (always last).
     *
     * @return string[]
     */
    public static function display_order(): array {
        return array_merge( self::block_order(), [ self::FAMILY_UNMAPPED ] );
    }

    /**
     * Type slug → family. Anything not present here is treated as 'unmapped'.
     *
     * Mirrors the additions made to VerifiedLinks::ALLOWED_TYPES in 5.1.0.
     * Adding a new platform: add the slug here AND in VerifiedLinks::ALLOWED_TYPES
     * AND in VerifiedLinks::TYPE_LABELS — all three must agree.
     *
     * @return array<string,string>
     */
    public static function type_to_family_map(): array {
        return [
            // Cam platforms
            'streamate'    => self::FAMILY_CAM,
            'chaturbate'   => self::FAMILY_CAM,
            'stripchat'    => self::FAMILY_CAM,
            'livejasmin'   => self::FAMILY_CAM,
            'camsoda'      => self::FAMILY_CAM,
            'bongacams'    => self::FAMILY_CAM,
            'cam4'         => self::FAMILY_CAM,
            'myfreecams'   => self::FAMILY_CAM,

            // Personal website
            'personal_site' => self::FAMILY_PERSONAL,

            // Fansites
            'onlyfans'     => self::FAMILY_FANSITE,
            'fansly'       => self::FAMILY_FANSITE,
            'fancentro'    => self::FAMILY_FANSITE,

            // Tube sites
            'pornhub'      => self::FAMILY_TUBE,

            // Social media
            'instagram'    => self::FAMILY_SOCIAL,
            'tiktok'       => self::FAMILY_SOCIAL,
            'x'            => self::FAMILY_SOCIAL,
            'facebook'     => self::FAMILY_SOCIAL,
            'youtube'      => self::FAMILY_SOCIAL,

            // Link hubs
            'linktree'     => self::FAMILY_LINK_HUB,
            'beacons'      => self::FAMILY_LINK_HUB,
            'allmylinks'   => self::FAMILY_LINK_HUB,
            'solo_to'      => self::FAMILY_LINK_HUB,
            'carrd'        => self::FAMILY_LINK_HUB,
            'link_me'      => self::FAMILY_LINK_HUB,
            'friendsbio'   => self::FAMILY_LINK_HUB,

            // Catch-all for legacy data — rendered in the Unmapped block.
            'other'        => self::FAMILY_UNMAPPED,
        ];
    }

    /**
     * Resolve a type slug to its family. Unknown / empty types → 'unmapped'.
     */
    public static function family_for( string $type ): string {
        $type = strtolower( trim( $type ) );
        $map  = self::type_to_family_map();
        return $map[ $type ] ?? self::FAMILY_UNMAPPED;
    }

    /**
     * Return the full set of allowed type slugs across all families.
     * Useful for sync with VerifiedLinks::ALLOWED_TYPES in tests.
     *
     * @return string[]
     */
    public static function all_known_types(): array {
        return array_keys( self::type_to_family_map() );
    }

    /**
     * Return [slug => label] for all types that belong to a given family,
     * preserving the order they appear in type_to_family_map().
     *
     * @param  string $family
     * @return array<string,string>
     */
    public static function types_in_family( string $family ): array {
        $labels = self::type_labels();
        $out    = [];
        foreach ( self::type_to_family_map() as $slug => $fam ) {
            if ( $fam === $family ) {
                $out[ $slug ] = $labels[ $slug ] ?? ucfirst( str_replace( '_', ' ', $slug ) );
            }
        }
        return $out;
    }

    /**
     * Default type slug for a brand-new row in a given family
     * (the first slug listed for that family). Returns 'other' for unmapped.
     */
    public static function default_type_for( string $family ): string {
        if ( $family === self::FAMILY_UNMAPPED ) {
            return 'other';
        }
        $candidates = array_keys( self::types_in_family( $family ) );
        return $candidates[0] ?? 'other';
    }

    /**
     * Operator-facing block label.
     */
    public static function family_label( string $family ): string {
        $labels = [
            self::FAMILY_CAM      => __( 'Cam Platforms',    'tmwseo' ),
            self::FAMILY_PERSONAL => __( 'Personal Website', 'tmwseo' ),
            self::FAMILY_FANSITE  => __( 'Fansites',         'tmwseo' ),
            self::FAMILY_TUBE     => __( 'Tube Sites',       'tmwseo' ),
            self::FAMILY_SOCIAL   => __( 'Social Media',     'tmwseo' ),
            self::FAMILY_LINK_HUB => __( 'Link Hubs',        'tmwseo' ),
            self::FAMILY_UNMAPPED => __( 'Other / Legacy',   'tmwseo' ),
        ];
        return $labels[ $family ] ?? ucfirst( str_replace( '_', ' ', $family ) );
    }

    /**
     * Header accent color per block (kept consistent with the Model Research
     * grouped-candidates panel for visual parity).
     */
    public static function family_color( string $family ): string {
        $colors = [
            self::FAMILY_CAM      => '#1a5276', // deep blue
            self::FAMILY_PERSONAL => '#117a65', // teal
            self::FAMILY_FANSITE  => '#a93226', // crimson
            self::FAMILY_TUBE     => '#7d3c98', // purple
            self::FAMILY_SOCIAL   => '#b9770e', // amber
            self::FAMILY_LINK_HUB => '#148f77', // green-teal
            self::FAMILY_UNMAPPED => '#566573', // slate
        ];
        return $colors[ $family ] ?? '#444';
    }

    /**
     * Compact label set used by the JS layer (kept here so PHP and JS read
     * from the same authority).
     *
     * @return array<string,string>
     */
    public static function type_labels(): array {
        return [
            'streamate'     => 'Streamate',
            'chaturbate'    => 'Chaturbate',
            'stripchat'     => 'Stripchat',
            'livejasmin'    => 'LiveJasmin',
            'camsoda'       => 'CamSoda',
            'bongacams'     => 'BongaCams',
            'cam4'          => 'Cam4',
            'myfreecams'    => 'MyFreeCams',
            'personal_site' => 'Personal Site',
            'onlyfans'      => 'OnlyFans',
            'fansly'        => 'Fansly',
            'fancentro'     => 'FanCentro',
            'pornhub'       => 'Pornhub',
            'instagram'     => 'Instagram',
            'tiktok'        => 'TikTok',
            'x'             => 'X (Twitter)',
            'facebook'      => 'Facebook',
            'youtube'       => 'YouTube',
            'linktree'      => 'Linktree',
            'beacons'       => 'Beacons',
            'allmylinks'    => 'AllMyLinks',
            'solo_to'       => 'Solo.to',
            'carrd'         => 'Carrd',
            'link_me'       => 'Link.me',
            'friendsbio'    => 'Friends Bio',
            'other'         => 'Other',
        ];
    }
}
