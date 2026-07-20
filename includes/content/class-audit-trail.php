<?php
/**
 * AuditTrail — centralized persistence for engine SEO decisions.
 *
 * Ensures all keyword, quality, uniqueness, readiness, and approval data
 * is stored in post meta for admin inspection and traceability.
 *
 * Audit fix: some data was previously only in transients or ephemeral caches.
 *
 * @package TMWSEO\Engine\Content
 * @since   4.4.0
 */
namespace TMWSEO\Engine\Content;

use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class AuditTrail {

    /** Standard meta keys used across the engine. */
    public const META_PRIMARY_KW       = '_tmwseo_keyword';
    public const META_SECONDARY_KW     = '_tmwseo_secondary_keywords';
    public const META_LONGTAIL_KW      = '_tmwseo_longtail_keywords';
    public const META_KW_CONFIDENCE    = '_tmwseo_keyword_confidence';
    public const META_QUALITY_SCORE    = '_tmwseo_content_quality_score';
    public const META_UNIQUENESS_SCORE = '_tmwseo_uniqueness_score';
    public const META_APPROVAL_STATUS  = '_tmwseo_approval_status';
    public const META_READY_TO_INDEX   = '_tmwseo_ready_to_index';
    public const META_KW_PACK_JSON     = '_tmwseo_keyword_pack_json';
    public const META_CONTENT_FP       = '_tmwseo_content_fingerprint';
    public const META_GATE_LOG         = '_tmwseo_gate_log';
    public const META_CANNIBAL_FLAGS   = '_tmwseo_cannibalization_flags';

    /**
     * Persist a full keyword pack snapshot for a post.
     *
     * @param array{primary:string, additional?:string[], secondary?:string[], longtail?:string[], sources?:array} $pack
     */
    public static function persist_keyword_pack( int $post_id, array $pack ): void {
        $primary = trim( (string) ( $pack['primary'] ?? '' ) );
        if ( $primary !== '' ) {
            update_post_meta( $post_id, self::META_PRIMARY_KW, $primary );
        }

        $secondary = $pack['additional'] ?? $pack['secondary'] ?? [];
        if ( is_array( $secondary ) && ! empty( $secondary ) ) {
            update_post_meta( $post_id, self::META_SECONDARY_KW, wp_json_encode( array_values( $secondary ) ) );
        }

        $longtail = $pack['longtail'] ?? [];
        if ( is_array( $longtail ) && ! empty( $longtail ) ) {
            update_post_meta( $post_id, self::META_LONGTAIL_KW, wp_json_encode( array_values( $longtail ) ) );
        }

        // Full snapshot for audit.
        update_post_meta( $post_id, self::META_KW_PACK_JSON, wp_json_encode( $pack ) );

        // Patch 2.1: persist keyword confidence when present in the pack.
        $confidence = (float) ( $pack['confidence'] ?? 0 );
        if ( $confidence > 0 ) {
            update_post_meta( $post_id, self::META_KW_CONFIDENCE, $confidence );
        }
    }

    /**
     * Persist quality and uniqueness scores.
     */
    public static function persist_quality( int $post_id, array $quality_result, float $uniqueness_score = 0.0 ): void {
        $score = (int) ( $quality_result['score'] ?? 0 );
        update_post_meta( $post_id, self::META_QUALITY_SCORE, $score );

        if ( $uniqueness_score > 0 ) {
            update_post_meta( $post_id, self::META_UNIQUENESS_SCORE, round( $uniqueness_score, 2 ) );
        }
    }

    /**
     * Persist content fingerprint after content generation.
     */
    public static function persist_fingerprint( int $post_id, string $content, string $post_type = '' ): void {
        $shingles = UniquenessChecker::shingle( $content );
        $hash     = UniquenessChecker::fingerprint_hash( $shingles );
        update_post_meta( $post_id, self::META_CONTENT_FP, $hash );

        // Also store in fingerprint table for cross-post comparison.
        UniquenessChecker::store_fingerprint( $post_id, $content, $post_type );
    }

    /**
     * Set approval status.
     *
     * @param string $status One of: pending, approved, rejected.
     */
    public static function set_approval_status( int $post_id, string $status ): void {
        $valid = [ 'pending', 'approved', 'rejected' ];
        if ( ! in_array( $status, $valid, true ) ) {
            $status = 'pending';
        }
        update_post_meta( $post_id, self::META_APPROVAL_STATUS, $status );
    }

    /**
     * Store cannibalization flags for a post.
     *
     * @param array<int, array{keyword:string, conflicting_post_id:int, severity:string}> $conflicts
     */
    public static function persist_cannibalization( int $post_id, array $conflicts ): void {
        if ( empty( $conflicts ) ) {
            delete_post_meta( $post_id, self::META_CANNIBAL_FLAGS );
            return;
        }
        update_post_meta( $post_id, self::META_CANNIBAL_FLAGS, wp_json_encode( $conflicts ) );
    }

    /**
     * Get a readable summary of all engine decisions for a post.
     *
     * @return array<string,mixed>
     */
    public static function get_summary( int $post_id ): array {
        return [
            'primary_keyword'       => (string) get_post_meta( $post_id, self::META_PRIMARY_KW, true ),
            'secondary_keywords'    => json_decode( (string) get_post_meta( $post_id, self::META_SECONDARY_KW, true ), true ) ?: [],
            'longtail_keywords'     => json_decode( (string) get_post_meta( $post_id, self::META_LONGTAIL_KW, true ), true ) ?: [],
            'keyword_confidence'    => (float) get_post_meta( $post_id, self::META_KW_CONFIDENCE, true ),
            'quality_score'         => (int) get_post_meta( $post_id, self::META_QUALITY_SCORE, true ),
            'uniqueness_score'      => (float) get_post_meta( $post_id, self::META_UNIQUENESS_SCORE, true ),
            'approval_status'       => (string) get_post_meta( $post_id, self::META_APPROVAL_STATUS, true ),
            'ready_to_index'        => (string) get_post_meta( $post_id, self::META_READY_TO_INDEX, true ),
            'content_fingerprint'   => (string) get_post_meta( $post_id, self::META_CONTENT_FP, true ),
            'gate_log'              => json_decode( (string) get_post_meta( $post_id, self::META_GATE_LOG, true ), true ) ?: [],
            'cannibalization_flags' => json_decode( (string) get_post_meta( $post_id, self::META_CANNIBAL_FLAGS, true ), true ) ?: [],
            'keyword_pack_snapshot' => json_decode( (string) get_post_meta( $post_id, self::META_KW_PACK_JSON, true ), true ) ?: [],
        ];
    }
}
