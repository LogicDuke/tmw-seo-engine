<?php
/**
 * TagQualityEngine — scores, classifies, and gates tag promotion.
 *
 * Imported tags must pass quality checks before becoming keyword seeds.
 * Tags are classified as: blocked, generic, low_volume, unscored, scored, qualified, promoted.
 *
 * Audit fix: previously, imported tags had no quality gate before entering SEO pipelines.
 *
 * @package TMWSEO\Engine\Keywords
 * @since   4.4.0
 */
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class TagQualityEngine {

    /** Tags that must never be used in SEO contexts. */
    private const BLOCKED_TAGS = [
        'teen', 'teens', 'schoolgirl', 'school girl', 'young', 'virgin', 'underage',
        'child', 'minor', 'kid', 'kids', 'loli', 'preteen',
    ];

    /** Tags too generic to provide SEO value. */
    private const GENERIC_TAGS = [
        'girl', 'girls', 'hot', 'sexy', 'cute', 'naked', 'erotic', 'solo',
        'sologirl', 'live sex', 'hd', 'watching', 'wet', 'romantic', 'sensual',
        'teasing', 'flirting', 'model', 'cam', 'webcam', 'live', 'chat', 'show',
    ];

    /** Minimum posts required for a tag to be promotable. */
    private const MIN_POSTS_FOR_PROMOTION  = 5;
    private const MIN_POSTS_FOR_CANDIDATE  = 3;
    private const MIN_QUALITY_FOR_PROMOTION = 60.0;
    private const MIN_QUALITY_FOR_CANDIDATE = 40.0;
    private const MIN_TAG_LENGTH            = 3;

    /**
     * Score all unscored tags (or rescore all if $force = true).
     *
     * @return array{scored:int, blocked:int, generic:int, qualified:int}
     */
    public static function score_all_tags( bool $force = false ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'tmw_tag_quality';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [ 'scored' => 0, 'blocked' => 0, 'generic' => 0, 'qualified' => 0 ];
        }

        $terms = get_terms( [
            'taxonomy'   => 'post_tag',
            'hide_empty' => false,
            'fields'     => 'all',
        ] );

        if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
            return [ 'scored' => 0, 'blocked' => 0, 'generic' => 0, 'qualified' => 0 ];
        }

        $category_slugs = self::load_category_slugs();
        $stats = [ 'scored' => 0, 'blocked' => 0, 'generic' => 0, 'qualified' => 0 ];

        foreach ( $terms as $term ) {
            if ( ! $term instanceof \WP_Term ) continue;

            // Skip already-scored unless forcing.
            if ( ! $force ) {
                $existing = $wpdb->get_var( $wpdb->prepare(
                    "SELECT status FROM $table WHERE term_id = %d",
                    $term->term_id
                ) );
                if ( $existing && $existing !== 'unscored' ) {
                    continue;
                }
            }

            $result = self::score_tag( $term, $category_slugs );
            $stats[ $result['classification'] ] = ( $stats[ $result['classification'] ] ?? 0 ) + 1;
            $stats['scored']++;
        }

        Logs::info( 'tag_quality', '[TMW-TAG-QUALITY] Scoring complete', $stats );
        return $stats;
    }

    /**
     * Score a single tag term.
     *
     * @param string[] $category_slugs Known category slugs for overlap detection.
     * @return array{term_id:int, quality_score:float, status:string, classification:string}
     */
    public static function score_tag( \WP_Term $term, array $category_slugs = [] ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_tag_quality';

        $name       = mb_strtolower( trim( $term->name ), 'UTF-8' );
        $slug       = $term->slug;
        $post_count = (int) $term->count;

        // Classification.
        $is_blocked  = self::is_blocked( $name );
        $is_generic  = self::is_generic( $name );
        $has_overlap = in_array( $slug, $category_slugs, true );
        $too_short   = mb_strlen( $name ) < self::MIN_TAG_LENGTH;

        if ( $is_blocked ) {
            $status         = 'blocked';
            $quality_score  = 0.0;
            $classification = 'blocked';
        } elseif ( $is_generic || $too_short ) {
            $status         = 'generic';
            $quality_score  = 5.0;
            $classification = 'generic';
        } else {
            // Quality score: weighted combination.
            $post_score    = min( 1.0, $post_count / 10.0 ) * 40;  // up to 40 points
            $length_score  = min( 1.0, mb_strlen( $name ) / 15.0 ) * 15; // longer = more specific
            $overlap_penalty = $has_overlap ? -20.0 : 0.0;
            $multi_word_bonus = ( substr_count( $name, ' ' ) >= 1 ) ? 10.0 : 0.0;
            $no_post_penalty = $post_count < self::MIN_POSTS_FOR_CANDIDATE ? -15.0 : 0.0;

            $quality_score = max( 0.0, min( 100.0,
                $post_score + $length_score + $overlap_penalty + $multi_word_bonus + $no_post_penalty + 20 // base
            ) );

            if ( $quality_score >= self::MIN_QUALITY_FOR_PROMOTION && $post_count >= self::MIN_POSTS_FOR_PROMOTION ) {
                $status         = 'qualified';
                $classification = 'qualified';
            } elseif ( $quality_score >= self::MIN_QUALITY_FOR_CANDIDATE && $post_count >= self::MIN_POSTS_FOR_CANDIDATE ) {
                $status         = 'candidate';
                $classification = 'scored';
            } else {
                $status         = 'low_quality';
                $classification = 'scored';
            }
        }

        // Upsert.
        $data = [
            'term_id'          => $term->term_id,
            'tag_name'         => mb_substr( $term->name, 0, 200 ),
            'post_count'       => $post_count,
            'quality_score'    => $quality_score,
            'status'           => $status,
            'category_overlap' => $has_overlap ? 1 : 0,
            'is_blocked'       => $is_blocked ? 1 : 0,
            'is_generic'       => $is_generic ? 1 : 0,
            'scored_at'        => current_time( 'mysql' ),
        ];

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT term_id FROM $table WHERE term_id = %d",
            $term->term_id
        ) );

        if ( $existing ) {
            $wpdb->update( $table, $data, [ 'term_id' => $term->term_id ] );
        } else {
            $wpdb->insert( $table, $data );
        }

        return [
            'term_id'        => $term->term_id,
            'quality_score'  => $quality_score,
            'status'         => $status,
            'classification' => $classification,
        ];
    }

    /**
     * Promote qualified tags to seed registry (preview layer).
     *
     * @return int Number of tags promoted.
     */
    public static function promote_qualified_tags(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_tag_quality';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return 0;
        }

        $qualified = $wpdb->get_results(
            "SELECT term_id, tag_name FROM $table
             WHERE status = 'qualified'
               AND promoted_at IS NULL
               AND is_blocked = 0
               AND is_generic = 0
             ORDER BY quality_score DESC
             LIMIT 50",
            ARRAY_A
        );

        if ( ! is_array( $qualified ) || empty( $qualified ) ) {
            return 0;
        }

        $promoted = 0;
        foreach ( $qualified as $row ) {
            $phrase = str_replace( '-', ' ', strtolower( trim( (string) $row['tag_name'] ) ) );
            if ( $phrase === '' ) continue;

            // Route to preview layer (expansion candidates), not directly to trusted seeds.
            $ok = SeedRegistry::register_candidate_phrase(
                $phrase,
                'tag_qualified',
                'tag_quality_promotion',
                'tag',
                (int) $row['term_id'],
                'tag_quality_' . date( 'Ymd' ),
                [ 'source' => 'TagQualityEngine', 'term_id' => (int) $row['term_id'] ]
            );

            if ( $ok ) {
                $wpdb->update( $table, [
                    'status'      => 'promoted',
                    'promoted_at' => current_time( 'mysql' ),
                ], [ 'term_id' => (int) $row['term_id'] ] );
                $promoted++;
            }
        }

        Logs::info( 'tag_quality', '[TMW-TAG-QUALITY] Promotion batch complete', [ 'promoted' => $promoted ] );
        return $promoted;
    }

    /**
     * Check whether a specific tag is usable for SEO purposes.
     */
    public static function is_tag_usable( int $term_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_tag_quality';

        $status = $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM $table WHERE term_id = %d",
            $term_id
        ) );

        return in_array( $status, [ 'qualified', 'promoted', 'candidate' ], true );
    }

    /**
     * Get summary stats for admin display.
     *
     * @return array<string,int>
     */
    public static function get_stats(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_tag_quality';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }

        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS cnt FROM $table GROUP BY status",
            ARRAY_A
        );

        $stats = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $stats[ (string) $row['status'] ] = (int) $row['cnt'];
            }
        }

        return $stats;
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private static function is_blocked( string $name ): bool {
        foreach ( self::BLOCKED_TAGS as $blocked ) {
            if ( $name === $blocked || strpos( $name, $blocked ) !== false ) {
                return true;
            }
        }
        return false;
    }

    private static function is_generic( string $name ): bool {
        return in_array( $name, self::GENERIC_TAGS, true );
    }

    /** @return string[] */
    private static function load_category_slugs(): array {
        $terms = get_terms( [
            'taxonomy'   => 'category',
            'hide_empty' => false,
            'fields'     => 'slugs',
        ] );
        return ( is_array( $terms ) && ! is_wp_error( $terms ) ) ? $terms : [];
    }
}
