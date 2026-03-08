<?php
/**
 * Rollback — snapshot and restore SEO/content fields before generation runs.
 *
 * Usage:
 *   // Before writing anything:
 *   Rollback::snapshot( $post_id );
 *
 *   // If the user clicks "Rollback":
 *   Rollback::restore( $post_id );
 *
 * Fields snapshotted:
 *   - rank_math_title
 *   - rank_math_description
 *   - rank_math_focus_keyword
 *   - rank_math_secondary_keywords
 *   - post_title
 *   - post_excerpt
 *
 * @package TMWSEO\Engine\Model
 */
namespace TMWSEO\Engine\Model;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Rollback {

    const META_SNAPSHOT = '_tmwseo_pre_generation_snapshot';
    const META_HAS_SNAP = '_tmwseo_has_snapshot';

    /** RankMath + WP post fields to snapshot. */
    private static function fields(): array {
        return [
            'rank_math_title',
            'rank_math_description',
            'rank_math_focus_keyword',
            'rank_math_secondary_keywords',
        ];
    }

    // ── Public API ─────────────────────────────────────────────────────────

    /**
     * Saves current field values for the post.
     * Safe to call multiple times — only saves once per post (first call wins).
     * Pass $force = true to overwrite an existing snapshot.
     */
    public static function snapshot( int $post_id, bool $force = false ): void {
        if ( $post_id <= 0 ) {
            return;
        }
        if ( ! $force && get_post_meta( $post_id, self::META_HAS_SNAP, true ) ) {
            return; // Already have a snapshot — don't overwrite
        }

        $post  = get_post( $post_id );
        $saved = [];

        foreach ( self::fields() as $field ) {
            $saved[ $field ] = get_post_meta( $post_id, $field, true );
        }

        // WP post fields
        if ( $post instanceof \WP_Post ) {
            $saved['_wp_post_title']   = $post->post_title;
            $saved['_wp_post_excerpt'] = $post->post_excerpt;
        }

        $saved['_snapshot_at'] = current_time( 'mysql' );

        update_post_meta( $post_id, self::META_SNAPSHOT, wp_json_encode( $saved ) );
        update_post_meta( $post_id, self::META_HAS_SNAP, 1 );
    }

    /**
     * Restores all snapshotted field values and removes the snapshot.
     *
     * @return array{ok:bool, message:string}
     */
    public static function restore( int $post_id ): array {
        if ( $post_id <= 0 ) {
            return [ 'ok' => false, 'message' => 'Invalid post ID.' ];
        }

        $raw = get_post_meta( $post_id, self::META_SNAPSHOT, true );
        if ( empty( $raw ) ) {
            return [ 'ok' => false, 'message' => 'No snapshot found for this post.' ];
        }

        $saved = json_decode( $raw, true );
        if ( ! is_array( $saved ) ) {
            return [ 'ok' => false, 'message' => 'Snapshot data is corrupt.' ];
        }

        // Restore RankMath / SEO meta fields
        foreach ( self::fields() as $field ) {
            if ( isset( $saved[ $field ] ) ) {
                if ( $saved[ $field ] !== '' ) {
                    update_post_meta( $post_id, $field, $saved[ $field ] );
                } else {
                    delete_post_meta( $post_id, $field );
                }
            }
        }

        // Restore WP post fields if they were captured
        $post_update = [ 'ID' => $post_id ];
        if ( isset( $saved['_wp_post_title'] ) && $saved['_wp_post_title'] !== '' ) {
            $post_update['post_title'] = $saved['_wp_post_title'];
        }
        if ( isset( $saved['_wp_post_excerpt'] ) ) {
            $post_update['post_excerpt'] = $saved['_wp_post_excerpt'];
        }
        if ( count( $post_update ) > 1 ) {
            wp_update_post( $post_update );
        }

        // Clear snapshot
        delete_post_meta( $post_id, self::META_SNAPSHOT );
        delete_post_meta( $post_id, self::META_HAS_SNAP );

        // Remove keyword usage record for this post if any was logged
        \TMWSEO\Engine\Keywords\KeywordUsage::maybe_upgrade(); // ensure tables exist
        self::clear_keyword_usage_for_post( $post_id );

        return [
            'ok'      => true,
            'message' => 'Restored to pre-generation state (snapshot taken ' . ( $saved['_snapshot_at'] ?? 'unknown' ) . ').',
        ];
    }

    /**
     * Returns whether a snapshot exists for the post.
     */
    public static function has_snapshot( int $post_id ): bool {
        return (bool) get_post_meta( $post_id, self::META_HAS_SNAP, true );
    }

    /**
     * Returns the snapshot timestamp if available.
     */
    public static function snapshot_time( int $post_id ): string {
        $raw = get_post_meta( $post_id, self::META_SNAPSHOT, true );
        if ( ! $raw ) {
            return '';
        }
        $data = json_decode( $raw, true );
        return (string) ( $data['_snapshot_at'] ?? '' );
    }

    // ── AJAX handler ───────────────────────────────────────────────────────

    public static function handle_rollback(): void {
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( $post_id <= 0 ) {
            wp_send_json_error( [ 'message' => 'Invalid post ID.' ] );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorised.' ] );
        }

        check_ajax_referer( 'tmwseo_rollback_' . $post_id, 'nonce' );

        $result = self::restore( $post_id );
        if ( $result['ok'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    // ── Internal ───────────────────────────────────────────────────────────

    /**
     * Removes keyword usage log entries for a specific post (on rollback).
     */
    private static function clear_keyword_usage_for_post( int $post_id ): void {
        global $wpdb;
        $log_table = \TMWSEO\Engine\Keywords\KeywordUsage::log_table();
        $wpdb->delete( $log_table, [ 'post_id' => $post_id ], [ '%d' ] );
    }
}
