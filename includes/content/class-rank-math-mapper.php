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

use TMWSEO\Engine\Keywords\ModelKeywordPack;
use TMWSEO\Engine\Keywords\PageTypeKeywordFilter;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class RankMathMapper {

    /** Maximum extra keywords Rank Math receives (model/video pages). */
    private const RANK_MATH_EXTRA_CAP = 4;

    /**
     * v5.9.5: category pages may carry up to 8 extras when the approved
     * category keyword pool supports it (Rank Math stores the full focus
     * keyword list as one CSV, so this is purely a plugin-side cap).
     */
    private const RANK_MATH_EXTRA_CAP_CATEGORY = 8;

    /** Page-type aware extras cap. */
    private static function extras_cap_for_page_type( string $page_type ): int {
        return $page_type === 'category' ? self::RANK_MATH_EXTRA_CAP_CATEGORY : self::RANK_MATH_EXTRA_CAP;
    }

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
        $rebuilt_model_pack = false;
        if ( self::page_type_for_post( $post_id ) === 'model' && class_exists( ModelKeywordPack::class ) ) {
            $post = get_post( $post_id );
            if ( $post instanceof \WP_Post ) {
                if ( defined( 'TMWSEO_DEBUG' ) && TMWSEO_DEBUG ) {
                    \TMWSEO\Engine\Logs::info( 'keywords', '[TMW-RM-MAP] rebuilt_model_pack=true post_id=' . $post_id );
                }
                $keyword_pack       = ModelKeywordPack::build( $post );
                $rebuilt_model_pack = true;
            }
        }

        if ( $rebuilt_model_pack ) {
            self::persist_model_keyword_pack_sources( $post_id, $keyword_pack );
        }

        $primary = self::extract_primary( $post_id, $keyword_pack );
        $extras  = self::extract_extras( $keyword_pack, $post_id );

        // Build Rank Math CSV: primary first, then up to 4 extras.
        $focus_list = array_merge( [ $primary ], $extras );
        $focus_list = array_values( array_unique( array_filter( array_map( 'trim', $focus_list ), 'strlen' ) ) );

        // Cap: 1 primary + N extras (N = 4 for model/video, 8 for category).
        $focus_list = array_slice( $focus_list, 0, 1 + self::extras_cap_for_page_type( self::page_type_for_post( $post_id ) ) );

        $focus_csv = implode( ',', $focus_list );
        if ( ! empty( $focus_list ) ) {
            update_post_meta( $post_id, 'rank_math_focus_keyword', $focus_csv );
        } else {
            delete_post_meta( $post_id, 'rank_math_focus_keyword' );
        }

        if ( defined( 'TMWSEO_DEBUG' ) && TMWSEO_DEBUG ) {
            \TMWSEO\Engine\Logs::info( 'keywords', '[TMW-RM-MAP] post_id=' . $post_id . ' focus=' . $primary . ' extras=' . self::debug_json( $extras ) . ' final_csv=' . $focus_csv . ' rebuilt_model_pack=' . ( $rebuilt_model_pack ? 'true' : 'false' ), [
                'post_id' => $post_id,
                'focus' => $primary,
                'extras' => $extras,
                'final_csv' => $focus_csv,
                'rebuilt_model_pack' => $rebuilt_model_pack,
                'model_name' => self::page_type_for_post( $post_id ) === 'model' ? trim( (string) get_the_title( $post_id ) ) : '',
                'rankmath_additional' => $keyword_pack['rankmath_additional'] ?? [],
                'additional' => $keyword_pack['additional'] ?? [],
                'sources' => $keyword_pack['sources'] ?? [],
            ] );
            \TMWSEO\Engine\Logs::info( 'keywords', '[TMW-SEO-RM-KW-PACK] RankMathMapper::sync_to_rank_math wrote Rank Math keyword CSV', [
                'post_id' => $post_id,
                'model_name' => self::page_type_for_post( $post_id ) === 'model' ? trim( (string) get_the_title( $post_id ) ) : '',
                'approved_linked_extras_found' => $keyword_pack['rankmath_approved_linked_extras'] ?? [],
                'generated_fallback_candidates_before_filtering' => $keyword_pack['rankmath_fallback_candidates'] ?? [],
                'final_rank_math_csv' => $focus_csv,
                'stored_pack_bypassed_or_rebuilt' => $rebuilt_model_pack,
            ] );
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

    /** @param mixed $value */
    private static function debug_json( $value ): string {
        $encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
        return is_string( $encoded ) ? $encoded : '[]';
    }


    /** @param array<string,mixed> $keyword_pack */
    private static function persist_model_keyword_pack_sources( int $post_id, array $keyword_pack ): void {
        update_post_meta( $post_id, 'tmw_keyword_pack', $keyword_pack );
        if ( function_exists( 'wp_json_encode' ) ) {
            update_post_meta( $post_id, '_tmwseo_keyword_pack', wp_json_encode( $keyword_pack ) );
        } else {
            update_post_meta( $post_id, '_tmwseo_keyword_pack', json_encode( $keyword_pack ) );
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
     * Priority for model pages:
     *   1. rankmath_additional — dedicated model-name-led chips (set by ModelKeywordPack)
     *   2. additional          — legacy generic pool
     *   3. secondary           — oldest fallback
     *
     * @return string[]
     */
    public static function extract_extras( array $keyword_pack, int $post_id = 0 ): array {
        // Prefer the dedicated Rank Math chip field when present and non-empty.
        if ( ! empty( $keyword_pack['rankmath_additional'] ) && is_array( $keyword_pack['rankmath_additional'] ) ) {
            $additional = $keyword_pack['rankmath_additional'];
        } else {
            $additional = $keyword_pack['additional'] ?? $keyword_pack['secondary'] ?? [];
        }

        if ( ! is_array( $additional ) ) {
            $additional = [];
        }

        $extras = array_values( array_filter( array_map( 'trim', array_map( 'strval', $additional ) ), 'strlen' ) );
        // PR-615: Do NOT run PageTypeKeywordFilter on rankmath_additional. Keywords in that
        // field were curated by ModelKeywordPack from approved DB rows (human sign-off) and
        // must not be stripped by the automated UNSAFE_TERMS filter. PageTypeKeywordFilter
        // is applied only when falling back to the legacy 'additional' / 'secondary' fields.
        $source = ! empty( $keyword_pack['rankmath_additional'] ) && is_array( $keyword_pack['rankmath_additional'] )
            ? 'rankmath_additional'
            : 'legacy';
        if ( $post_id > 0 && $source === 'legacy' ) {
            $extras = PageTypeKeywordFilter::filter( $extras, self::page_type_for_post( $post_id ) );
        }

        // Remove the primary keyword from extras before capping, so a duplicate primary
        // does not occupy one of the 4 extra slots.
        $primary_lc = '';
        if ( $post_id > 0 ) {
            $primary_lc = function_exists( 'mb_strtolower' )
                ? mb_strtolower( self::extract_primary( $post_id, $keyword_pack ), 'UTF-8' )
                : strtolower( self::extract_primary( $post_id, $keyword_pack ) );
        }
        if ( $primary_lc !== '' ) {
            $extras = array_values( array_filter( $extras, static function ( string $e ) use ( $primary_lc ): bool {
                $e_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $e, 'UTF-8' ) : strtolower( $e );
                return $e_lc !== $primary_lc;
            } ) );
        }

        $cap = $post_id > 0 ? self::extras_cap_for_page_type( self::page_type_for_post( $post_id ) ) : self::RANK_MATH_EXTRA_CAP;
        return array_slice( $extras, 0, $cap );
    }

    /**
     * Get the Rank Math focus keyword CSV that WOULD be written, without writing it.
     * Useful for preview/debug.
     *
     * @return string
     */
    public static function preview_rank_math_csv( int $post_id, array $keyword_pack ): string {
        if ( self::page_type_for_post( $post_id ) === 'model' && class_exists( ModelKeywordPack::class ) ) {
            $post = get_post( $post_id );
            if ( $post instanceof \WP_Post ) {
                $keyword_pack = ModelKeywordPack::build( $post );
            }
        }
        $primary = self::extract_primary( $post_id, $keyword_pack );
        $extras  = self::extract_extras( $keyword_pack, $post_id );
        $list    = array_merge( [ $primary ], $extras );
        $list    = array_values( array_unique( array_filter( array_map( 'trim', $list ), 'strlen' ) ) );
        return implode( ',', array_slice( $list, 0, 1 + self::extras_cap_for_page_type( self::page_type_for_post( $post_id ) ) ) );
    }

    private static function page_type_for_post( int $post_id ): string {
        $post_type = (string) get_post_field( 'post_type', $post_id );
        if ( $post_type === 'model' ) {
            return 'model';
        }
        if ( $post_type === 'post' ) {
            return 'video';
        }
        return 'category';
    }

    /**
     * Apply a reviewed focus/supporting keyword pack to Rank Math with backup.
     *
     * @param int      $post_id
     * @param string   $focus_keyword
     * @param string[] $supporting_keywords
     */
    public static function apply_reviewed_keyword_pack( int $post_id, string $focus_keyword, array $supporting_keywords = [] ): bool {
        $focus = trim( $focus_keyword );
        if ( $post_id <= 0 || $focus === '' ) {
            return false;
        }

        $cap    = self::extras_cap_for_page_type( self::page_type_for_post( $post_id ) );
        $extras = array_values( array_filter( array_map( 'trim', array_map( 'strval', $supporting_keywords ) ), 'strlen' ) );
        $extras = array_slice( $extras, 0, $cap );

        $focus_list = array_merge( [ $focus ], $extras );
        $focus_list = array_values( array_unique( array_filter( array_map( 'trim', $focus_list ), 'strlen' ) ) );
        $focus_csv  = implode( ',', array_slice( $focus_list, 0, 1 + $cap ) );

        $previous = (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true );
        if ( $previous === $focus_csv ) {
            return true;
        }

        if ( (string) get_post_meta( $post_id, '_tmwseo_prev_rank_math_focus_keyword', true ) === '' ) {
            update_post_meta( $post_id, '_tmwseo_prev_rank_math_focus_keyword', $previous );
            update_post_meta( $post_id, '_tmwseo_prev_rank_math_focus_keyword_at', current_time( 'mysql' ) );
        }

        return update_post_meta( $post_id, 'rank_math_focus_keyword', $focus_csv ) !== false;
    }
}
