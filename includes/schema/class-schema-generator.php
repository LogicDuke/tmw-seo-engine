<?php
/**
 * SchemaGenerator — outputs JSON-LD structured data for model and video pages.
 *
 * Types generated:
 *   - Person (model profile pages)
 *   - VideoObject (video post types)
 *   - FAQPage (when FAQ blocks are detected)
 *   - WebPage fallback for all other post types
 *
 * @package TMWSEO\Engine\Schema
 */
namespace TMWSEO\Engine\Schema;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Logs;

class SchemaGenerator {

    // ── Hooks ──────────────────────────────────────────────────────────────

    public static function init(): void {
        add_action( 'wp_head', [ __CLASS__, 'output_schema' ], 5 );
    }

    public static function output_schema(): void {
        if ( ! is_singular() ) return;

        $post_id = get_the_ID();
        if ( ! $post_id ) return;

        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) return;

        $schemas = self::build_schemas( $post );
        if ( empty( $schemas ) ) return;

        self::lint_schemas( $schemas, $post_id );

        foreach ( $schemas as $schema ) {
            echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . '</script>' . "\n";
        }
    }

    // ── Builder ────────────────────────────────────────────────────────────

    public static function build_schemas( \WP_Post $post ): array {
        $schemas = [];

        $post_type = $post->post_type;

        if ( in_array( $post_type, [ 'model', 'post' ], true ) ) {
            $schemas[] = self::person_schema( $post );
        } elseif ( in_array( $post_type, [ 'video', 'tmw_video', 'livejasmin_video' ], true ) ) {
            $schemas[] = self::video_schema( $post );
        } else {
            $schemas[] = self::webpage_schema( $post );
        }

        // FAQ schema if post content contains FAQ heading patterns
        $faq = self::maybe_faq_schema( $post );
        if ( $faq ) {
            $schemas[] = $faq;
        }

        return array_filter( $schemas );
    }

    // ── Person schema ──────────────────────────────────────────────────────

    private static function person_schema( \WP_Post $post ): array {
        $name        = strip_tags( $post->post_title );
        $description = self::get_description( $post );
        $url         = get_permalink( $post->ID );
        $image       = self::get_image_url( $post->ID );
        $site_name   = get_bloginfo( 'name' );

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Person',
            'name'        => $name,
            'url'         => $url,
            'description' => $description,
            'image'       => $image ?: null,
            'worksFor'    => [
                '@type' => 'Organization',
                'name'  => $site_name,
                'url'   => home_url( '/' ),
            ],
        ];

        // sameAs: verified platform profile URLs + verified external links.
        // Research social_urls are NEVER used here.
        $platform_links = self::get_platform_links( $post->ID );
        $verified_links = class_exists( '\\TMWSEO\\Engine\\Model\\VerifiedLinks' )
            ? \TMWSEO\Engine\Model\VerifiedLinks::get_schema_urls( $post->ID )
            : [];
        $same_as = array_values( array_unique( array_merge( $platform_links, $verified_links ) ) );
        if ( ! empty( $same_as ) ) {
            $schema['sameAs'] = $same_as;
        }

        // Tags as keywords
        $tags = wp_get_post_terms( $post->ID, 'post_tag', [ 'fields' => 'names' ] );
        if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
            $schema['knowsAbout'] = array_values( (array) $tags );
        }

        return array_filter( $schema, static fn( $v ) => $v !== null && $v !== '' && $v !== [] );
    }

    // ── VideoObject schema ─────────────────────────────────────────────────

    private static function video_schema( \WP_Post $post ): array {
        $name         = strip_tags( $post->post_title );
        $description  = self::get_description( $post );
        $url          = get_permalink( $post->ID );
        $image        = self::get_image_url( $post->ID );
        $upload_date  = date( 'c', strtotime( $post->post_date ) );
        $site_name    = get_bloginfo( 'name' );

        $schema = [
            '@context'    => 'https://schema.org',
            '@type'       => 'VideoObject',
            'name'        => $name,
            'description' => $description ?: $name,
            'thumbnailUrl'=> $image ?: null,
            'uploadDate'  => $upload_date,
            'url'         => $url,
            'publisher'   => [
                '@type' => 'Organization',
                'name'  => $site_name,
                'logo'  => [
                    '@type' => 'ImageObject',
                    'url'   => self::get_site_logo(),
                ],
            ],
        ];

        // Duration if stored in post meta
        $duration = (string) get_post_meta( $post->ID, '_tmw_video_duration', true );
        if ( $duration !== '' ) {
            $schema['duration'] = 'PT' . (int) $duration . 'S';
        }

        return array_filter( $schema, static fn( $v ) => $v !== null && $v !== '' && $v !== [] );
    }

    // ── WebPage fallback schema ────────────────────────────────────────────

    private static function webpage_schema( \WP_Post $post ): array {
        return [
            '@context'    => 'https://schema.org',
            '@type'       => 'WebPage',
            'name'        => strip_tags( $post->post_title ),
            'url'         => get_permalink( $post->ID ),
            'description' => self::get_description( $post ),
            'datePublished' => date( 'c', strtotime( $post->post_date ) ),
            'dateModified'  => date( 'c', strtotime( $post->post_modified ) ),
        ];
    }

    // ── FAQPage schema ─────────────────────────────────────────────────────

    private static function maybe_faq_schema( \WP_Post $post ): ?array {
        $content = $post->post_content;
        if ( strpos( $content, '<!-- wp:faq' ) === false && ! preg_match( '/<h[23][^>]*>(?:faq|frequently asked|questions)/i', $content ) ) {
            return null;
        }

        // Extract Q&A pairs from RankMath FAQ blocks or heading+paragraph patterns
        $pairs = self::extract_faq_pairs( $content );
        if ( count( $pairs ) < 2 ) return null;

        $entities = [];
        foreach ( $pairs as $pair ) {
            $entities[] = [
                '@type'          => 'Question',
                'name'           => $pair['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $pair['answer'],
                ],
            ];
        }

        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $entities,
        ];
    }

    private static function extract_faq_pairs( string $content ): array {
        $pairs = [];
        // Match RankMath/Yoast FAQ block JSON
        if ( preg_match_all( '/"question"\s*:\s*"([^"]+)".*?"answer"\s*:\s*"([^"]+)"/s', $content, $m ) ) {
            for ( $i = 0; $i < count( $m[1] ); $i++ ) {
                $pairs[] = [
                    'question' => wp_strip_all_tags( $m[1][ $i ] ),
                    'answer'   => wp_strip_all_tags( $m[2][ $i ] ),
                ];
            }
        }
        // Match <h3>Q</h3><p>A</p> patterns
        if ( preg_match_all( '/<h[23][^>]*>(.*?)<\/h[23]>\s*<p>(.*?)<\/p>/is', $content, $m ) ) {
            for ( $i = 0; $i < count( $m[1] ); $i++ ) {
                $q = trim( wp_strip_all_tags( $m[1][ $i ] ) );
                $a = trim( wp_strip_all_tags( $m[2][ $i ] ) );
                if ( strlen( $q ) > 10 && strlen( $a ) > 10 ) {
                    $pairs[] = [ 'question' => $q, 'answer' => $a ];
                }
            }
        }

        return array_slice( $pairs, 0, 10 );
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private static function get_description( \WP_Post $post ): string {
        // Try RankMath meta description first
        $rm = trim( (string) get_post_meta( $post->ID, 'rank_math_description', true ) );
        if ( $rm !== '' ) return $rm;

        // Post excerpt
        if ( $post->post_excerpt !== '' ) {
            return trim( wp_strip_all_tags( $post->post_excerpt ) );
        }

        // First 155 chars of content
        $text = trim( wp_strip_all_tags( $post->post_content ) );
        return $text !== '' ? mb_substr( $text, 0, 155, 'UTF-8' ) : '';
    }

    private static function get_image_url( int $post_id ): string {
        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( ! $thumb_id ) return '';
        $src = wp_get_attachment_image_src( (int) $thumb_id, 'large' );
        return $src ? (string) $src[0] : '';
    }

    private static function get_platform_links( int $post_id ): array {
        if ( ! class_exists( '\\TMWSEO\\Engine\\Platform\\PlatformProfiles' ) ) return [];
        $links = \TMWSEO\Engine\Platform\PlatformProfiles::get_links( $post_id );
        if ( ! is_array( $links ) ) return [];
        $urls = [];
        foreach ( $links as $link ) {
            // PlatformProfiles::get_links() returns 'profile_url', not 'url'.
            $url = trim( (string) ( $link['profile_url'] ?? '' ) );
            if ( $url !== '' && filter_var( $url, FILTER_VALIDATE_URL ) ) {
                $urls[] = $url;
            }
        }
        return array_values( array_unique( $urls ) );
    }

    private static function get_site_logo(): string {
        $custom_logo_id = get_theme_mod( 'custom_logo' );
        if ( $custom_logo_id ) {
            $src = wp_get_attachment_image_src( (int) $custom_logo_id, 'full' );
            if ( $src ) return (string) $src[0];
        }
        return '';
    }

    // ── Schema lint ────────────────────────────────────────────────────────
    // Info/warn only. Never blocks output. Never modifies schema payloads.

    /**
     * Checks emitted schemas for deprecated @type values. Advisory only —
     * does not alter schema output or block rendering.
     *
     * @param array<int,array<string,mixed>> $schemas
     */
    private static function lint_schemas( array $schemas, int $post_id ): void {
        $deprecated_types = [
            'HowTo'               => 'Removed from Google rich results (Sep 2023).',
            'SpecialAnnouncement' => 'Removed from Google rich results (Oct 2022).',
            'ClaimReview'         => 'Removed from Google rich results (Sep 2023).',
            'CourseInfo'          => 'Removed from Google rich results (Sep 2023).',
            'EstimatedSalary'     => 'Removed from Google rich results (Sep 2023).',
            'LearningVideo'       => 'Removed from Google rich results (Sep 2023).',
            'VehicleListing'      => 'Removed from Google rich results (Sep 2023).',
            'Dataset'             => 'Removed from Google rich results (Sep 2023).',
            'PracticeProblem'     => 'Removed from Google rich results (Sep 2023).',
        ];

        foreach ( $schemas as $schema ) {
            $type = (string) ( $schema['@type'] ?? '' );
            if ( isset( $deprecated_types[ $type ] ) ) {
                Logs::warn( 'schema_lint', "Deprecated schema type '{$type}': " . $deprecated_types[ $type ], [ 'post_id' => $post_id ] );
            }
        }
    }

}
