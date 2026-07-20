<?php
/**
 * RankMathReader — read-only access to Rank Math stored data.
 *
 * This is the ONLY place in TMW that reads Rank Math meta/state.
 * All other code should call this instead of get_post_meta('rank_math_*') directly.
 *
 * Design rules:
 *   - Read-only. Never writes to Rank Math meta (use RankMathMapper for writes).
 *   - Graceful degradation when Rank Math is inactive.
 *   - Returns normalized, typed data — no raw mixed-type surprises.
 *
 * @package TMWSEO\Engine\Content
 * @since   4.5.0
 */
namespace TMWSEO\Engine\Content;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class RankMathReader {

    /* ──────────────────────────────────────────────
     * Plugin detection
     * ────────────────────────────────────────────── */

    /**
     * Whether the Rank Math plugin is active and loaded.
     */
    public static function is_active(): bool {
        return defined( 'RANK_MATH_VERSION' ) && class_exists( 'RankMath\\Helper', false );
    }

    /**
     * Rank Math version string, or empty if inactive.
     */
    public static function version(): string {
        return defined( 'RANK_MATH_VERSION' ) ? (string) RANK_MATH_VERSION : '';
    }

    /* ──────────────────────────────────────────────
     * Post-level meta readers
     * ────────────────────────────────────────────── */

    /**
     * Focus keyword CSV stored by Rank Math.
     * Format: "primary,extra1,extra2,extra3,extra4"
     */
    public static function get_focus_keyword_csv( int $post_id ): string {
        return trim( (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ) );
    }

    /**
     * Primary focus keyword only (first item in CSV).
     */
    public static function get_primary_keyword( int $post_id ): string {
        $csv = self::get_focus_keyword_csv( $post_id );
        if ( $csv === '' ) {
            return '';
        }
        $parts = explode( ',', $csv );
        return trim( $parts[0] ?? '' );
    }

    /**
     * All focus keywords as an array.
     *
     * @return string[]
     */
    public static function get_all_keywords( int $post_id ): array {
        $csv = self::get_focus_keyword_csv( $post_id );
        if ( $csv === '' ) {
            return [];
        }
        return array_values( array_filter( array_map( 'trim', explode( ',', $csv ) ), 'strlen' ) );
    }

    /**
     * SEO title override (empty if using template default).
     */
    public static function get_seo_title( int $post_id ): string {
        return trim( (string) get_post_meta( $post_id, 'rank_math_title', true ) );
    }

    /**
     * Meta description override.
     */
    public static function get_meta_description( int $post_id ): string {
        return trim( (string) get_post_meta( $post_id, 'rank_math_description', true ) );
    }

    /**
     * Saved SEO score (0–100). Returns 0 if never computed.
     */
    public static function get_seo_score( int $post_id ): int {
        return (int) get_post_meta( $post_id, 'rank_math_seo_score', true );
    }

    /**
     * Score rating label based on thresholds:
     *   0     → 'none'
     *   1–50  → 'bad'
     *   51–80 → 'good'
     *   81+   → 'great'
     */
    public static function get_score_label( int $post_id ): string {
        $score = self::get_seo_score( $post_id );
        if ( $score <= 0 )  return 'none';
        if ( $score <= 50 ) return 'bad';
        if ( $score <= 80 ) return 'good';
        return 'great';
    }

    /**
     * Robots meta directives as an array (e.g. ['index', 'follow']).
     *
     * @return string[]
     */
    public static function get_robots( int $post_id ): array {
        $raw = get_post_meta( $post_id, 'rank_math_robots', true );
        $robots = self::normalize_robots_meta( $raw );

        if ( empty( $robots ) ) {
            $fallback_fields = [
                'rank_math_robots_index',
                'rank_math_robots_follow',
                'rank_math_robots_advanced',
            ];

            foreach ( $fallback_fields as $meta_key ) {
                $meta_value = get_post_meta( $post_id, $meta_key, true );
                $robots     = array_merge( $robots, self::normalize_robots_meta( $meta_value ) );
            }

            $robots = array_values( array_unique( array_filter( array_map( 'trim', $robots ), 'strlen' ) ) );
        }

        return $robots;
    }

    /**
     * Whether Rank Math has noindex set for this post.
     */
    public static function is_noindex( int $post_id ): bool {
        return in_array( 'noindex', self::get_robots( $post_id ), true );
    }

    /**
     * Canonical URL override (empty if using default).
     */
    public static function get_canonical_url( int $post_id ): string {
        return trim( (string) get_post_meta( $post_id, 'rank_math_canonical_url', true ) );
    }

    /**
     * Whether post is marked as pillar content.
     */
    public static function is_pillar_content( int $post_id ): bool {
        return get_post_meta( $post_id, 'rank_math_pillar_content', true ) === 'on';
    }

    /**
     * OpenGraph title override.
     */
    public static function get_og_title( int $post_id ): string {
        return trim( (string) get_post_meta( $post_id, 'rank_math_facebook_title', true ) );
    }

    /**
     * OpenGraph description override.
     */
    public static function get_og_description( int $post_id ): string {
        return trim( (string) get_post_meta( $post_id, 'rank_math_facebook_description', true ) );
    }

    /* ──────────────────────────────────────────────
     * Composite snapshot
     * ────────────────────────────────────────────── */

    /**
     * Returns a complete normalized snapshot of all Rank Math data for a post.
     *
     * @return array{
     *   active: bool,
     *   version: string,
     *   focus_keyword: string,
     *   all_keywords: string[],
     *   seo_title: string,
     *   meta_description: string,
     *   seo_score: int,
     *   score_label: string,
     *   robots: string[],
     *   is_noindex: bool,
     *   canonical_url: string,
     *   is_pillar: bool,
     *   og_title: string,
     *   og_description: string,
     * }
     */
    public static function get_snapshot( int $post_id ): array {
        return [
            'active'           => self::is_active(),
            'version'          => self::version(),
            'focus_keyword'    => self::get_primary_keyword( $post_id ),
            'all_keywords'     => self::get_all_keywords( $post_id ),
            'seo_title'        => self::get_seo_title( $post_id ),
            'meta_description' => self::get_meta_description( $post_id ),
            'seo_score'        => self::get_seo_score( $post_id ),
            'score_label'      => self::get_score_label( $post_id ),
            'robots'           => self::get_robots( $post_id ),
            'is_noindex'       => self::is_noindex( $post_id ),
            'canonical_url'    => self::get_canonical_url( $post_id ),
            'is_pillar'        => self::is_pillar_content( $post_id ),
            'og_title'         => self::get_og_title( $post_id ),
            'og_description'   => self::get_og_description( $post_id ),
        ];
    }

    /* ──────────────────────────────────────────────
     * Global settings (require RM active)
     * ────────────────────────────────────────────── */

    /**
     * Default SEO title template for a post type.
     */
    public static function get_title_template( string $post_type ): string {
        if ( ! self::is_active() ) {
            return '';
        }
        return (string) \RankMath\Helper::get_settings( 'titles.pt_' . $post_type . '_title', '%title% %sep% %sitename%' );
    }

    /**
     * Default meta description template for a post type.
     */
    public static function get_description_template( string $post_type ): string {
        if ( ! self::is_active() ) {
            return '';
        }
        return (string) \RankMath\Helper::get_settings( 'titles.pt_' . $post_type . '_description', '' );
    }

    /**
     * Normalize Rank Math robots meta from array/string/serialized/json variants.
     *
     * @param mixed $raw
     * @return string[]
     */
    private static function normalize_robots_meta( $raw ): array {
        if ( is_array( $raw ) ) {
            return self::normalize_robots_tokens( $raw );
        }

        if ( ! is_string( $raw ) ) {
            return [];
        }

        $value = trim( $raw );
        if ( $value === '' ) {
            return [];
        }

        if ( is_serialized( $value ) ) {
            $unserialized = maybe_unserialize( $value );
            if ( is_array( $unserialized ) ) {
                return self::normalize_robots_tokens( $unserialized );
            }
        }

        if ( ( strpos( $value, '[' ) === 0 || strpos( $value, '{' ) === 0 ) ) {
            $decoded = json_decode( $value, true );
            if ( is_array( $decoded ) ) {
                return self::normalize_robots_tokens( $decoded );
            }
        }

        $parts = preg_split( '/[\s,|]+/', $value );
        if ( ! is_array( $parts ) ) {
            return [];
        }

        return self::normalize_robots_tokens( $parts );
    }

    /**
     * @param array<int|string,mixed> $tokens
     * @return string[]
     */
    private static function normalize_robots_tokens( array $tokens ): array {
        $out = [];

        foreach ( $tokens as $token ) {
            if ( ! is_scalar( $token ) ) {
                continue;
            }

            $value = strtolower( trim( (string) $token ) );
            if ( $value === '' ) {
                continue;
            }

            if ( in_array( $value, [ '1', 'yes', 'on', 'true' ], true ) ) {
                $value = 'index';
            } elseif ( in_array( $value, [ '0', 'no', 'off', 'false' ], true ) ) {
                $value = 'noindex';
            }

            $out[] = $value;
        }

        return array_values( array_unique( $out ) );
    }
}
