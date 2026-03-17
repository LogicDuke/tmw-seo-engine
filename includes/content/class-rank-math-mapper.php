<?php
/**
 * RankMathMapper — maps internal dynamic keyword packs to Rank Math fields.
 *
 * Internal engine rule:
 *   - additional keywords: 2–6 (dynamic, based on data richness)
 *   - long-tail keywords: stored separately
 *   - full pack snapshot: always persisted
 *
 * Rank Math output rule:
 *   - focus keyword = primary keyword only (1)
 *   - extra keywords = top 4 from additional pack only
 *
 * This mapper is the ONLY place that should translate internal packs to Rank Math.
 * All other code should call this instead of writing rank_math_focus_keyword directly.
 *
 * @package TMWSEO\Engine\Content
 * @since   4.4.1
 */
namespace TMWSEO\Engine\Content;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class RankMathMapper {

    /** Maximum extra keywords Rank Math receives. */
    private const RANK_MATH_EXTRA_CAP = 4;

    /**
     * Map a keyword pack to Rank Math post meta.
     *
     * Writes:
     *   rank_math_focus_keyword  — "primary,extra1,extra2,extra3,extra4"
     *   (Rank Math stores focus + secondary as a CSV in one field)
     *
     * Also persists the full internal pack via AuditTrail for traceability.
     *
     * @param int   $post_id
     * @param array $keyword_pack {primary:string, additional?:string[], secondary?:string[], longtail?:string[], sources?:array}
     * @param bool  $persist_audit Also call AuditTrail::persist_keyword_pack(). Default true.
     */
    public static function sync_to_rank_math( int $post_id, array $keyword_pack, bool $persist_audit = true ): void {
        $primary = self::extract_primary( $post_id, $keyword_pack );
        $extras  = self::extract_extras( $keyword_pack );

        // Build Rank Math CSV: primary first, then up to 4 extras.
        $focus_list = array_merge( [ $primary ], $extras );
        $focus_list = array_values( array_unique( array_filter( array_map( 'trim', $focus_list ), 'strlen' ) ) );

        // Cap: 1 primary + 4 extras = 5 total max.
        $focus_list = array_slice( $focus_list, 0, 1 + self::RANK_MATH_EXTRA_CAP );

        if ( ! empty( $focus_list ) ) {
            update_post_meta( $post_id, 'rank_math_focus_keyword', implode( ',', $focus_list ) );
        }

        // Always persist the engine's primary keyword separately.
        if ( $primary !== '' ) {
            update_post_meta( $post_id, '_tmwseo_keyword', $primary );
        }

        // Persist full internal pack for auditability.
        if ( $persist_audit && class_exists( '\\TMWSEO\\Engine\\Content\\AuditTrail' ) ) {
            AuditTrail::persist_keyword_pack( $post_id, $keyword_pack );
        }
    }

    /**
     * Extract the primary keyword from a pack, enforcing page-type rules.
     *
     * Model pages: always use bare model name (post title).
     * Video pages: use pack primary (model_name + descriptor).
     */
    public static function extract_primary( int $post_id, array $keyword_pack ): string {
        $primary = trim( (string) ( $keyword_pack['primary'] ?? '' ) );

        // Model pages: force bare model name.
        $post_type = (string) get_post_field( 'post_type', $post_id );
        if ( $post_type === 'model' ) {
            $model_name = trim( (string) get_the_title( $post_id ) );
            if ( $model_name !== '' ) {
                return $model_name;
            }
        }

        return $primary;
    }

    /**
     * Extract the top N extras from a keyword pack, capped to RANK_MATH_EXTRA_CAP.
     *
     * @return string[]
     */
    public static function extract_extras( array $keyword_pack ): array {
        $additional = $keyword_pack['additional'] ?? $keyword_pack['secondary'] ?? [];
        if ( ! is_array( $additional ) ) {
            $additional = [];
        }

        $extras = array_values( array_filter( array_map( 'trim', array_map( 'strval', $additional ) ), 'strlen' ) );

        return array_slice( $extras, 0, self::RANK_MATH_EXTRA_CAP );
    }

    /**
     * Get the Rank Math focus keyword CSV that WOULD be written, without writing it.
     * Useful for preview/debug.
     *
     * @return string
     */
    public static function preview_rank_math_csv( int $post_id, array $keyword_pack ): string {
        $primary = self::extract_primary( $post_id, $keyword_pack );
        $extras  = self::extract_extras( $keyword_pack );
        $list    = array_merge( [ $primary ], $extras );
        $list    = array_values( array_unique( array_filter( array_map( 'trim', $list ), 'strlen' ) ) );
        return implode( ',', array_slice( $list, 0, 1 + self::RANK_MATH_EXTRA_CAP ) );
    }
}
