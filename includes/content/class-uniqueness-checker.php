<?php
/**
 * UniquenessChecker — detects near-duplicate generated content.
 *
 * Uses 4-gram shingling for robust detection of template-heavy content.
 * Stores fingerprints in tmw_content_fingerprints for fast comparison.
 *
 * Audit fix: previous version used bag-of-words unigrams on a random
 * sample of 12 posts, which was too weak for templated pages.
 *
 * @package TMWSEO\Engine\Content
 * @since   4.4.0 — rewritten with n-gram shingling
 */
namespace TMWSEO\Engine\Content;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class UniquenessChecker {

    private const SHINGLE_N   = 4;
    private const MAX_COMPARE = 60;

    /**
     * Returns the maximum similarity % against existing published posts.
     *
     * API-compatible with the previous version.
     */
    public static function similarity_score(
        string $content,
        $post_type = 'model',
        int $sample = 30,
        int $exclude_id = 0
    ): float {
        $post_types      = (array) $post_type;
        $needle_shingles = self::shingle( $content );

        if ( empty( $needle_shingles ) ) {
            return 0.0;
        }

        // Try fingerprint-table fast path.
        $fp_score = self::compare_via_fingerprints( $needle_shingles, $post_types, $exclude_id );
        if ( $fp_score !== null ) {
            return $fp_score;
        }

        // Fallback: direct comparison with shingles (larger sample than old version).
        return self::compare_via_posts( $needle_shingles, $post_types, max( 30, $sample ), $exclude_id );
    }

    /**
     * Store/update the fingerprint for a post.
     */
    public static function store_fingerprint( int $post_id, string $content, string $post_type = '' ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_content_fingerprints';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return false;
        }

        if ( $post_type === '' ) {
            $post_type = (string) get_post_field( 'post_type', $post_id );
        }

        $shingles = self::shingle( $content );
        $hash     = self::fingerprint_hash( $shingles );
        $count    = count( $shingles );
        $now      = current_time( 'mysql' );

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE post_id = %d", $post_id
        ) );

        $data = [
            'post_type'        => $post_type,
            'fingerprint_hash' => $hash,
            'shingle_count'    => $count,
            'updated_at'       => $now,
        ];

        if ( $existing ) {
            $wpdb->update( $table, $data, [ 'post_id' => $post_id ] );
        } else {
            $wpdb->insert( $table, array_merge( $data, [
                'post_id'              => $post_id,
                'max_similarity'       => 0,
                'most_similar_post_id' => 0,
                'created_at'           => $now,
            ] ) );
        }

        return true;
    }

    /**
     * Human-readable verdict for a similarity score.
     *
     * @return array{label:string, color:string, pass:bool}
     */
    public static function verdict( float $score ): array {
        if ( $score <= 30 ) {
            return [ 'label' => 'Unique', 'color' => 'green', 'pass' => true ];
        }
        if ( $score <= 50 ) {
            return [ 'label' => 'Mostly unique', 'color' => 'orange', 'pass' => true ];
        }
        if ( $score <= 65 ) {
            return [ 'label' => 'Borderline', 'color' => 'orange', 'pass' => false ];
        }
        return [ 'label' => 'Near-duplicate', 'color' => 'red', 'pass' => false ];
    }

    /**
     * Generate 4-gram shingle set from content.
     *
     * @return string[]
     */
    public static function shingle( string $content ): array {
        $text  = strtolower( strip_tags( $content ) );
        $text  = (string) preg_replace( '/[^a-z0-9]+/i', ' ', $text );
        $words = preg_split( '/\s+/', trim( $text ) );

        if ( ! is_array( $words ) ) {
            return [];
        }

        $stop = [
            'the','and','for','are','but','not','you','all','can','her','was',
            'one','our','out','has','have','with','this','that','from','they',
            'been','will','more','when','who','also','than','them','its',
            'your','each','into','some','she','him','his',
        ];

        $words = array_values( array_filter( $words, static function ( $w ) use ( $stop ) {
            return strlen( $w ) > 2 && ! in_array( $w, $stop, true );
        } ) );

        if ( count( $words ) < self::SHINGLE_N ) {
            return $words;
        }

        $shingles = [];
        $limit    = count( $words ) - self::SHINGLE_N + 1;
        for ( $i = 0; $i < $limit; $i++ ) {
            $shingles[] = implode( ' ', array_slice( $words, $i, self::SHINGLE_N ) );
        }

        return array_values( array_unique( $shingles ) );
    }

    /**
     * Compute a fingerprint hash for a shingle set.
     */
    public static function fingerprint_hash( array $shingles ): string {
        if ( empty( $shingles ) ) return md5( '' );
        sort( $shingles );
        return md5( implode( '|', $shingles ) );
    }

    /**
     * Jaccard similarity between two shingle sets.
     *
     * @return float 0.0–100.0
     */
    public static function jaccard( array $a, array $b ): float {
        if ( empty( $a ) || empty( $b ) ) return 0.0;

        $intersection = count( array_intersect( $a, $b ) );
        $union        = count( array_unique( array_merge( $a, $b ) ) );

        return $union === 0 ? 0.0 : round( ( $intersection / $union ) * 100, 2 );
    }

    // ── Internal ──────────────────────────────────────────────────────────

    private static function compare_via_fingerprints( array $needle_shingles, array $post_types, int $exclude_id ): ?float {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_content_fingerprints';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return null;
        }

        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        $params       = $post_types;

        $query = "SELECT post_id FROM $table WHERE post_type IN ($placeholders)";
        if ( $exclude_id > 0 ) {
            $query .= ' AND post_id != %d';
            $params[] = $exclude_id;
        }
        $query .= ' ORDER BY updated_at DESC LIMIT ' . self::MAX_COMPARE;

        $candidate_ids = $wpdb->get_col( $wpdb->prepare( $query, ...$params ) );
        if ( empty( $candidate_ids ) ) {
            return 0.0;
        }

        $max = 0.0;
        foreach ( $candidate_ids as $cand_id ) {
            $cand_content = (string) get_post_field( 'post_content', (int) $cand_id );
            if ( $cand_content === '' ) continue;

            $cand_shingles = self::shingle( $cand_content );
            if ( empty( $cand_shingles ) ) continue;

            $sim = self::jaccard( $needle_shingles, $cand_shingles );
            if ( $sim > $max ) $max = $sim;
        }

        return $max;
    }

    private static function compare_via_posts( array $needle_shingles, array $post_types, int $sample, int $exclude_id ): float {
        $query_args = [
            'post_type'      => $post_types,
            'posts_per_page' => 200,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ];
        if ( $exclude_id > 0 ) {
            $query_args['post__not_in'] = [ $exclude_id ];
        }

        $posts = get_posts( $query_args );
        if ( empty( $posts ) ) return 0.0;

        if ( count( $posts ) > $sample ) {
            shuffle( $posts );
            $posts = array_slice( $posts, 0, $sample );
        }

        $max = 0.0;
        foreach ( $posts as $post_id ) {
            $combined = (string) get_post_field( 'post_content', $post_id ) . ' '
                      . (string) get_post_field( 'post_excerpt', $post_id );

            $hay_shingles = self::shingle( $combined );
            if ( empty( $hay_shingles ) ) continue;

            $sim = self::jaccard( $needle_shingles, $hay_shingles );
            if ( $sim > $max ) $max = $sim;
        }

        return round( $max, 2 );
    }
}
