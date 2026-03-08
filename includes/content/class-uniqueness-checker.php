<?php
/**
 * UniquenessChecker — detects near-duplicate generated content.
 *
 * Tokenises a piece of text and compares it against a random sample
 * of recently published posts to produce a similarity percentage.
 *
 * A score > 70 means the content is likely a near-duplicate and
 * should be regenerated or reviewed before publishing.
 *
 * @package TMWSEO\Engine\Content
 */
namespace TMWSEO\Engine\Content;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class UniquenessChecker {

    /**
     * Returns the maximum similarity % against existing published posts.
     *
     * @param string       $content    HTML or plain text to check.
     * @param string|array $post_type  Post type(s) to compare against.
     * @param int          $sample     How many published posts to sample.
     * @param int          $exclude_id Post ID to exclude (the post being edited).
     * @return float  0.0 – 100.0
     */
    public static function similarity_score(
        string $content,
        $post_type = 'model',
        int $sample = 12,
        int $exclude_id = 0
    ): float {
        $post_types = (array) $post_type;

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
        if ( empty( $posts ) ) {
            return 0.0;
        }

        // Take a random sample to keep it fast
        if ( count( $posts ) > $sample ) {
            shuffle( $posts );
            $posts = array_slice( $posts, 0, $sample );
        }

        $needle_tokens = self::tokenize( $content );
        if ( empty( $needle_tokens ) ) {
            return 0.0;
        }

        $max = 0.0;
        foreach ( $posts as $post_id ) {
            $haystack    = (string) get_post_field( 'post_content', $post_id );
            $hay_excerpt = (string) get_post_field( 'post_excerpt', $post_id );
            $combined    = $haystack . ' ' . $hay_excerpt;
            $hay_tokens  = self::tokenize( $combined );
            if ( empty( $hay_tokens ) ) {
                continue;
            }
            $overlap = count( array_intersect( $needle_tokens, $hay_tokens ) );
            $score   = ( $overlap / max( 1, count( $needle_tokens ) ) ) * 100;
            if ( $score > $max ) {
                $max = $score;
            }
        }

        return round( $max, 2 );
    }

    /**
     * Returns a human-readable verdict for a similarity score.
     *
     * @return array{label:string, color:string, pass:bool}
     */
    public static function verdict( float $score ): array {
        if ( $score <= 30 ) {
            return [ 'label' => 'Unique', 'color' => 'green', 'pass' => true ];
        }
        if ( $score <= 55 ) {
            return [ 'label' => 'Mostly unique', 'color' => 'orange', 'pass' => true ];
        }
        if ( $score <= 70 ) {
            return [ 'label' => 'Borderline', 'color' => 'orange', 'pass' => false ];
        }
        return [ 'label' => 'Near-duplicate', 'color' => 'red', 'pass' => false ];
    }

    // ── Tokeniser ──────────────────────────────────────────────────────────

    /**
     * Converts text into a deduplicated array of meaningful tokens (>3 chars).
     */
    private static function tokenize( string $text ): array {
        $text  = strtolower( strip_tags( $text ) );
        $text  = (string) preg_replace( '/[^a-z0-9]+/i', ' ', $text );
        $parts = preg_split( '/\s+/', trim( $text ) );
        if ( ! $parts ) {
            return [];
        }
        $parts = array_filter( $parts, static function ( $p ) {
            return strlen( $p ) > 3;
        } );
        return array_values( array_unique( $parts ) );
    }
}
