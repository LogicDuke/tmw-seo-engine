<?php
/**
 * VideoTitleRewriter — generates scored, reviewable title candidates for video posts.
 *
 * Uses template patterns with metadata (model name, tags, platform) instead of
 * uncontrolled AI generation. Persists 2–3 candidates for human review.
 *
 * Audit fix: previously no video title rewriting pipeline existed.
 *
 * @package TMWSEO\Engine\Content
 * @since   4.4.0
 */
namespace TMWSEO\Engine\Content;

use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class VideoTitleRewriter {

    /** Post meta keys. */
    public const META_REWRITTEN    = '_tmwseo_title_rewritten';
    public const META_ORIGINAL     = '_tmwseo_original_title';
    public const META_SELECTED     = '_tmwseo_selected_title_id';

    /** Title patterns — {model}, {tag}, {platform}, {descriptor} are replaced. */
    private const PATTERNS = [
        '{model} {tag} — {descriptor} Live Cam',
        'Watch {model} in {tag} — {platform} Highlights',
        '{model} {tag} Session — Live Webcam Clip',
        '{model} — {tag} {descriptor} on {platform}',
        '{model} {descriptor} — {tag} Cam Show',
        'Live {tag} with {model} — {platform}',
        '{model} {tag} Video — Watch on {platform}',
        '{model} Live {tag} — {descriptor} Session',
    ];

    /** Descriptors used as filler when metadata is sparse. */
    private const DESCRIPTORS = [
        'HD', 'Full', 'Latest', 'New', 'Featured', 'Exclusive', 'Premium',
    ];

    /**
     * Generate title candidates for a video post.
     *
     * @return array{candidates: array<int, array{title:string, score:float, source:string}>, stored:bool}
     */
    public static function generate_candidates( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return [ 'candidates' => [], 'stored' => false ];
        }

        // Preserve original title.
        $original = trim( (string) $post->post_title );
        if ( get_post_meta( $post_id, self::META_ORIGINAL, true ) === '' ) {
            update_post_meta( $post_id, self::META_ORIGINAL, $original );
        }

        // Gather metadata.
        $model_name = self::extract_model_name( $post );
        $tags       = self::extract_tags( $post );
        $platform   = self::extract_platform( $post );
        $primary_tag = ! empty( $tags ) ? $tags[0] : 'webcam';
        $secondary_tag = isset( $tags[1] ) ? $tags[1] : '';

        $seed = $post_id . '-' . $model_name;
        $hash = abs( crc32( $seed ) );

        // Generate candidates from patterns.
        $candidates = [];
        $used_patterns = [];

        // Select 3 diverse patterns.
        $pattern_count = count( self::PATTERNS );
        for ( $i = 0; $i < 3; $i++ ) {
            $idx = ( $hash + $i * 3 ) % $pattern_count;
            while ( in_array( $idx, $used_patterns, true ) ) {
                $idx = ( $idx + 1 ) % $pattern_count;
            }
            $used_patterns[] = $idx;

            $descriptor_idx = ( $hash + $i ) % count( self::DESCRIPTORS );
            $tag_to_use     = ( $i === 0 ) ? $primary_tag : ( $secondary_tag ?: $primary_tag );

            $title = strtr( self::PATTERNS[ $idx ], [
                '{model}'      => $model_name,
                '{tag}'        => ucfirst( $tag_to_use ),
                '{platform}'   => $platform,
                '{descriptor}' => self::DESCRIPTORS[ $descriptor_idx ],
            ] );

            // Clean up double spaces, trailing dashes.
            $title = trim( preg_replace( '/\s+/', ' ', $title ) );
            $title = rtrim( $title, ' —-' );

            // Truncate to 60 chars.
            if ( mb_strlen( $title ) > 60 ) {
                $title = mb_substr( $title, 0, 57 ) . '...';
            }

            $score = self::score_title( $title, $model_name, $primary_tag, $post_id );

            $candidates[] = [
                'title'  => $title,
                'score'  => $score,
                'source' => 'template_pattern_' . $idx,
            ];
        }

        // Sort by score descending.
        usort( $candidates, fn( $a, $b ) => $b['score'] <=> $a['score'] );

        // Persist to database.
        $stored = self::store_candidates( $post_id, $candidates );

        Logs::info( 'video_title', '[TMW-VIDEO-TITLE] Generated candidates', [
            'post_id'    => $post_id,
            'candidates' => count( $candidates ),
            'top_score'  => $candidates[0]['score'] ?? 0,
        ] );

        return [ 'candidates' => $candidates, 'stored' => $stored ];
    }

    /**
     * Apply a selected title candidate to a video post.
     *
     * @return bool
     */
    public static function apply_title( int $post_id, int $candidate_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_video_title_candidates';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return false;
        }

        $candidate = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND post_id = %d",
            $candidate_id, $post_id
        ), ARRAY_A );

        if ( ! $candidate ) {
            return false;
        }

        $new_title = trim( (string) $candidate['candidate_title'] );
        if ( $new_title === '' ) {
            return false;
        }

        // Update the post title.
        wp_update_post( [
            'ID'         => $post_id,
            'post_title' => $new_title,
        ] );

        // Mark as selected.
        $wpdb->update( $table,
            [ 'is_selected' => 0 ],
            [ 'post_id' => $post_id ]
        );
        $wpdb->update( $table,
            [ 'is_selected' => 1 ],
            [ 'id' => $candidate_id ]
        );

        // Set rewritten flag.
        update_post_meta( $post_id, self::META_REWRITTEN, '1' );
        update_post_meta( $post_id, self::META_SELECTED, $candidate_id );

        Logs::info( 'video_title', '[TMW-VIDEO-TITLE] Title applied', [
            'post_id'      => $post_id,
            'candidate_id' => $candidate_id,
            'new_title'    => $new_title,
        ] );

        return true;
    }

    /**
     * Check if a video still has its original imported title.
     */
    public static function is_original_title( int $post_id ): bool {
        return (string) get_post_meta( $post_id, self::META_REWRITTEN, true ) !== '1';
    }

    /**
     * Get stored candidates for a post.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function get_candidates( int $post_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_video_title_candidates';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE post_id = %d ORDER BY score DESC",
            $post_id
        ), ARRAY_A );

        return is_array( $rows ) ? $rows : [];
    }

    // ── Scoring ───────────────────────────────────────────────────────────

    private static function score_title( string $title, string $model_name, string $primary_tag, int $post_id ): float {
        $score = 50.0; // base

        // Contains model name.
        if ( stripos( $title, $model_name ) !== false ) {
            $score += 20.0;
        }

        // Contains primary tag.
        if ( $primary_tag !== '' && stripos( $title, $primary_tag ) !== false ) {
            $score += 10.0;
        }

        // Length: ideal 40–60 chars.
        $len = mb_strlen( $title );
        if ( $len >= 40 && $len <= 60 ) {
            $score += 10.0;
        } elseif ( $len >= 30 && $len <= 70 ) {
            $score += 5.0;
        }

        // Uniqueness: check against existing titles.
        $existing_titles = self::get_recent_titles( $post_id, 20 );
        $is_unique = true;
        $title_lower = mb_strtolower( $title );
        foreach ( $existing_titles as $existing ) {
            if ( mb_strtolower( $existing ) === $title_lower ) {
                $is_unique = false;
                break;
            }
            // Substring check.
            if ( mb_strlen( $existing ) > 10 && mb_stripos( $title, $existing ) !== false ) {
                $score -= 5.0;
            }
        }
        if ( $is_unique ) {
            $score += 10.0;
        } else {
            $score -= 15.0;
        }

        return max( 0.0, min( 100.0, $score ) );
    }

    // ── Data extraction ───────────────────────────────────────────────────

    private static function extract_model_name( \WP_Post $post ): string {
        // Try models taxonomy.
        $model_terms = wp_get_post_terms( $post->ID, 'models' );
        if ( ! is_wp_error( $model_terms ) && ! empty( $model_terms ) && isset( $model_terms[0] ) ) {
            return (string) $model_terms[0]->name;
        }

        // Try post meta.
        $meta_name = trim( (string) get_post_meta( $post->ID, '_tmw_model_name', true ) );
        if ( $meta_name !== '' ) {
            return $meta_name;
        }

        // Fallback: first word of title (common import pattern).
        $parts = explode( ' ', $post->post_title, 3 );
        return $parts[0] ?? 'Model';
    }

    private static function extract_tags( \WP_Post $post ): array {
        $generic = [ 'girl', 'hot', 'sexy', 'cute', 'naked', 'cam', 'webcam', 'live', 'model', 'show', 'hd' ];
        $tags = wp_get_post_terms( $post->ID, 'post_tag', [ 'fields' => 'names' ] );
        if ( is_wp_error( $tags ) || ! is_array( $tags ) ) {
            return [];
        }

        $filtered = [];
        foreach ( $tags as $tag ) {
            $tag = strtolower( trim( (string) $tag ) );
            if ( $tag !== '' && ! in_array( $tag, $generic, true ) && strlen( $tag ) >= 3 ) {
                $filtered[] = $tag;
            }
        }

        return array_slice( $filtered, 0, 5 );
    }

    private static function extract_platform( \WP_Post $post ): string {
        $platform = trim( (string) get_post_meta( $post->ID, '_tmw_source_platform', true ) );
        if ( $platform !== '' ) {
            return ucfirst( $platform );
        }

        // Check categories for platform names.
        $cats = wp_get_post_terms( $post->ID, 'category', [ 'fields' => 'slugs' ] );
        $known = [ 'livejasmin' => 'LiveJasmin', 'stripchat' => 'Stripchat', 'chaturbate' => 'Chaturbate' ];
        if ( is_array( $cats ) ) {
            foreach ( $cats as $slug ) {
                if ( isset( $known[ $slug ] ) ) {
                    return $known[ $slug ];
                }
            }
        }

        return 'Webcam';
    }

    /** @return string[] */
    private static function get_recent_titles( int $exclude_id, int $count ): array {
        $posts = get_posts( [
            'post_type'      => 'post',
            'posts_per_page' => $count,
            'post_status'    => 'publish',
            'exclude'        => [ $exclude_id ],
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $titles = [];
        foreach ( $posts as $pid ) {
            $titles[] = (string) get_the_title( (int) $pid );
        }
        return $titles;
    }

    // ── Storage ───────────────────────────────────────────────────────────

    /**
     * @param array<int, array{title:string, score:float, source:string}> $candidates
     */
    private static function store_candidates( int $post_id, array $candidates ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_video_title_candidates';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return false;
        }

        // Remove old candidates for this post (keep clean).
        $wpdb->delete( $table, [ 'post_id' => $post_id, 'is_selected' => 0 ] );

        $now = current_time( 'mysql' );
        foreach ( $candidates as $c ) {
            $wpdb->insert( $table, [
                'post_id'         => $post_id,
                'candidate_title' => mb_substr( (string) $c['title'], 0, 255 ),
                'score'           => (float) $c['score'],
                'source'          => (string) $c['source'],
                'is_selected'     => 0,
                'created_at'      => $now,
            ] );
        }

        return true;
    }
}
