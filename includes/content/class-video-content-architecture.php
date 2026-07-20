<?php
/**
 * VideoContentArchitecture — keyword packs and content previews for video posts.
 *
 * Replaces the placeholder video template with metadata-driven content:
 * - model name + scene descriptor keyword packs
 * - tag/category-aware content sections
 * - model backlink
 * - quality gates
 *
 * Audit fix: previously video page templates were generic placeholders.
 *
 * @package TMWSEO\Engine\Content
 * @since   4.4.0
 */
namespace TMWSEO\Engine\Content;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\TitleFixer;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class VideoContentArchitecture {

    /**
     * Build a keyword pack for a video post.
     *
     * Centers on model_name + scene_descriptor, not generic terms.
     *
     * @return array{primary:string, secondary:string[], longtail:string[], sources:array}
     */
    public static function build_keyword_pack( \WP_Post $post ): array {
        $model_name  = self::extract_model_name( $post );
        $tags        = self::extract_meaningful_tags( $post );
        $platform    = self::extract_platform( $post );
        $primary_tag = $tags[0] ?? 'webcam';

        // Primary: model name + primary scene descriptor.
        $primary = trim( $model_name . ' ' . $primary_tag );
        if ( $primary === '' ) {
            $primary = trim( (string) $post->post_title );
        }

        // Secondary: model + tag/platform combinations (2-4).
        $secondary = [];
        if ( $model_name !== '' ) {
            $secondary[] = $model_name . ' ' . $primary_tag . ' video';
            if ( $platform !== '' && $platform !== 'Webcam' ) {
                $secondary[] = $model_name . ' ' . $platform . ' ' . $primary_tag;
            }
            if ( isset( $tags[1] ) ) {
                $secondary[] = $model_name . ' ' . $tags[1] . ' cam';
            }
            $secondary[] = 'watch ' . $model_name . ' ' . $primary_tag;
        }
        $secondary = array_slice( array_unique( array_filter( $secondary ) ), 0, 4 );

        // Long-tail: more specific combinations.
        $longtail = [];
        if ( $model_name !== '' ) {
            $longtail[] = 'watch ' . $model_name . ' ' . $primary_tag . ' live cam video';
            if ( $platform !== '' && $platform !== 'Webcam' ) {
                $longtail[] = $model_name . ' ' . $primary_tag . ' ' . strtolower( $platform ) . ' highlights';
            }
            foreach ( array_slice( $tags, 0, 2 ) as $tag ) {
                $longtail[] = $model_name . ' ' . $tag . ' webcam session';
            }
        }
        $longtail = array_slice( array_unique( array_filter( $longtail ) ), 0, 4 );

        // Patch 2.1: compute video keyword confidence from metadata availability.
        $confidence = self::compute_video_confidence( $model_name, $tags, $platform, $secondary );

        return [
            'primary'    => $primary,
            'secondary'  => $secondary,
            'longtail'   => $longtail,
            'confidence' => $confidence,
            'sources'    => [
                'model_name' => $model_name,
                'tags'       => $tags,
                'platform'   => $platform,
            ],
        ];
    }

    /**
     * Build a content preview for a video post using real metadata.
     *
     * Replaces the old placeholder skeleton.
     *
     * @return array{seo_title:string, meta_description:string, content_html:string, outline:string, keyword_pack:array}
     */
    public static function build_preview( \WP_Post $post ): array {
        $pack       = self::build_keyword_pack( $post );
        $model_name = $pack['sources']['model_name'] ?? '';
        $tags       = $pack['sources']['tags'] ?? [];
        $platform   = $pack['sources']['platform'] ?? 'Webcam';
        $primary    = $pack['primary'];
        $primary_tag = $tags[0] ?? 'webcam';
        $tags_text  = ! empty( $tags ) ? implode( ', ', array_slice( $tags, 0, 4 ) ) : 'live webcam';

        // SEO title.
        $seo_title = TitleFixer::shorten(
            $model_name !== '' ? $model_name . ' ' . ucfirst( $primary_tag ) . ' — Watch on ' . $platform : (string) $post->post_title,
            60
        );

        // Meta description.
        $meta_desc = TitleFixer::shorten(
            'Watch ' . ( $model_name ?: 'this model' ) . ' in a ' . $primary_tag
            . ' webcam session. ' . ucfirst( $platform ) . ' highlights, tags: ' . $tags_text . '.',
            160
        );

        // Content HTML — metadata-driven, not placeholder.
        $parts = [];

        // H1: rewritten title or primary keyword.
        $h1 = $model_name !== '' ? esc_html( $model_name . ' ' . ucfirst( $primary_tag ) . ' Video' ) : esc_html( $post->post_title );
        $parts[] = '<h1>' . $h1 . '</h1>';

        // Intro.
        $parts[] = '<p>This ' . esc_html( $primary_tag ) . ' session features '
            . esc_html( $model_name ?: 'a live cam model' )
            . ' in a ' . esc_html( $platform ) . ' webcam clip.'
            . ( ! empty( $tags ) ? ' Tagged: ' . esc_html( $tags_text ) . '.' : '' )
            . '</p>';

        // H2: About this video.
        $parts[] = '<h2>About This ' . esc_html( ucfirst( $primary_tag ) ) . ' Video</h2>';
        $about_sentences = [];
        $about_sentences[] = 'This clip showcases ' . esc_html( $model_name ?: 'the performer' ) . ' in a ' . esc_html( $primary_tag ) . ' themed session.';
        if ( count( $tags ) >= 2 ) {
            $about_sentences[] = 'The video also features elements of ' . esc_html( implode( ' and ', array_slice( $tags, 1, 2 ) ) ) . '.';
        }
        $duration = (string) get_post_meta( $post->ID, '_tmw_video_duration', true );
        if ( $duration !== '' ) {
            $about_sentences[] = 'Duration: approximately ' . esc_html( $duration ) . ' seconds.';
        }
        $about_sentences[] = 'Streamed on ' . esc_html( $platform ) . ' with HD video quality.';
        $parts[] = '<p>' . implode( ' ', $about_sentences ) . '</p>';

        // H2: About the model (with backlink placeholder).
        if ( $model_name !== '' ) {
            $parts[] = '<h2>About ' . esc_html( $model_name ) . '</h2>';
            $model_url = self::find_model_url( $model_name );
            if ( $model_url ) {
                $parts[] = '<p>' . esc_html( $model_name ) . ' is a live cam model featured on ' . esc_html( $platform ) . '. '
                    . 'See <a href="' . esc_url( $model_url ) . '">' . esc_html( $model_name ) . '\'s full profile</a> '
                    . 'for live show schedules, platform links, and more content.</p>';
            } else {
                $parts[] = '<p>' . esc_html( $model_name ) . ' is a live cam model featured on ' . esc_html( $platform ) . '. '
                    . 'Browse the tags and categories on this page to find related content.</p>';
            }
        }

        // H2: Related content.
        $parts[] = '<h2>Related ' . esc_html( ucfirst( $primary_tag ) ) . ' Videos</h2>';
        $parts[] = '<p>Explore more ' . esc_html( $primary_tag ) . ' webcam clips and live sessions. '
            . 'Use the tags below to discover similar content from other models.</p>';

        $content_html = implode( "\n\n", $parts );

        // Outline.
        preg_match_all( '/<h[1-3][^>]*>(.*?)<\/h[1-3]>/is', $content_html, $matches );
        $outline = '';
        if ( ! empty( $matches[1] ) ) {
            $outline = implode( "\n", array_map( fn( $h ) => '- ' . trim( strip_tags( $h ) ), $matches[1] ) );
        }

        return [
            'seo_title'        => $seo_title,
            'meta_description' => $meta_desc,
            'content_html'     => $content_html,
            'outline'          => $outline,
            'keyword_pack'     => $pack,
        ];
    }

    /**
     * Evaluate video page quality for readiness gating.
     *
     * @return array{passes:bool, issues:string[]}
     */
    public static function evaluate_quality( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return [ 'passes' => false, 'issues' => [ 'post_not_found' ] ];
        }

        $issues = [];

        // Title must be rewritten.
        if ( VideoTitleRewriter::is_original_title( $post_id ) ) {
            $issues[] = 'title_not_rewritten';
        }

        // Must have a model association.
        $model_name = self::extract_model_name( $post );
        if ( $model_name === '' || $model_name === 'Model' ) {
            $issues[] = 'no_model_association';
        }

        // Must have at least one meaningful tag.
        $tags = self::extract_meaningful_tags( $post );
        if ( empty( $tags ) ) {
            $issues[] = 'no_meaningful_tags';
        }

        // Content word count.
        $word_count = str_word_count( strip_tags( $post->post_content ) );
        if ( $word_count < 60 ) {
            $issues[] = 'content_too_thin:' . $word_count;
        }

        // Must link back to model.
        if ( $model_name !== '' && stripos( $post->post_content, $model_name ) === false ) {
            $issues[] = 'model_not_mentioned_in_content';
        }

        return [
            'passes' => empty( $issues ),
            'issues' => $issues,
        ];
    }

    // ── Data extraction (shared with VideoTitleRewriter) ──────────────────

    private static function extract_model_name( \WP_Post $post ): string {
        $model_terms = wp_get_post_terms( $post->ID, 'models' );
        if ( ! is_wp_error( $model_terms ) && ! empty( $model_terms ) && isset( $model_terms[0] ) ) {
            return (string) $model_terms[0]->name;
        }

        $meta_name = trim( (string) get_post_meta( $post->ID, '_tmw_model_name', true ) );
        if ( $meta_name !== '' ) {
            return $meta_name;
        }

        // Fallback: first capitalized word from title.
        $parts = explode( ' ', $post->post_title, 3 );
        $first = $parts[0] ?? '';
        if ( $first !== '' && $first === ucfirst( strtolower( $first ) ) && strlen( $first ) >= 3 ) {
            return $first;
        }

        return '';
    }

    private static function extract_meaningful_tags( \WP_Post $post ): array {
        $generic = [ 'girl', 'girls', 'hot', 'sexy', 'cute', 'naked', 'cam', 'webcam', 'live', 'model', 'show', 'hd', 'solo' ];
        $tags    = wp_get_post_terms( $post->ID, 'post_tag', [ 'fields' => 'names' ] );
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

        $cats  = wp_get_post_terms( $post->ID, 'category', [ 'fields' => 'slugs' ] );
        $known = [ 'livejasmin' => 'LiveJasmin', 'stripchat' => 'Stripchat', 'chaturbate' => 'Chaturbate' ];
        if ( is_array( $cats ) ) {
            foreach ( $cats as $slug ) {
                if ( isset( $known[ $slug ] ) ) return $known[ $slug ];
            }
        }

        return 'Webcam';
    }

    private static function find_model_url( string $model_name ): ?string {
        $slug = sanitize_title( $model_name );
        if ( $slug === '' ) return null;

        $model_posts = get_posts( [
            'post_type'      => 'model',
            'name'           => $slug,
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ] );

        if ( ! empty( $model_posts ) ) {
            return (string) get_permalink( (int) $model_posts[0] );
        }

        return null;
    }

    /**
     * Compute keyword confidence for a video post based on metadata availability.
     *
     * Patch 2.1: real confidence signal — not a placeholder.
     * Measures how much real data we had to build the keyword pack.
     *
     * @return float 0–100
     */
    private static function compute_video_confidence( string $model_name, array $tags, string $platform, array $secondary ): float {
        $confidence = 10.0; // base: we at least have a post

        // Model name is the strongest signal.
        if ( $model_name !== '' && $model_name !== 'Model' ) {
            $confidence += 30.0;
        }

        // Tags provide topic context.
        if ( count( $tags ) >= 1 ) $confidence += 15.0;
        if ( count( $tags ) >= 3 ) $confidence += 10.0;

        // Known platform (not generic "Webcam").
        if ( $platform !== '' && $platform !== 'Webcam' ) {
            $confidence += 15.0;
        }

        // Secondary keywords could be generated.
        if ( count( $secondary ) >= 2 ) $confidence += 10.0;
        if ( count( $secondary ) >= 4 ) $confidence += 5.0;

        // Bonus if model name appears in secondary keywords (cross-validated).
        if ( $model_name !== '' ) {
            $name_in_secondary = false;
            foreach ( $secondary as $kw ) {
                if ( stripos( (string) $kw, $model_name ) !== false ) {
                    $name_in_secondary = true;
                    break;
                }
            }
            if ( $name_in_secondary ) $confidence += 5.0;
        }

        return round( max( 5.0, min( 100.0, $confidence ) ), 2 );
    }
}
