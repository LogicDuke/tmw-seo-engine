<?php
/**
 * Model Content Draft Service
 *
 * Shared payload builder for model content draft workflows.
 *
 * This service is intentionally read-only and side-effect free:
 *  - no writes
 *  - no remote API calls
 *  - no schema changes
 *
 * build_longform_preview_draft() now delegates to ModelContentGenerationFacade,
 * which reuses the same proven template strategy as the sidebar "Generate" button.
 * The sidebar Generate workflow is completely unchanged.
 *
 * @package TMWSEO\Engine\Model
 * @since   5.9.0
 */

namespace TMWSEO\Engine\Model;

use TMWSEO\Engine\Platform\PlatformProfiles;
use TMWSEO\Engine\Services\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ModelContentDraftService {

    /** @var string[] */
    private static array $blocked_tags = [
        'teen', 'teens', 'schoolgirl', 'school girl', 'young', 'virgin', 'underage',
    ];

    /** @var string[] */
    private static array $generic_tags = [
        'girl', 'hot', 'sexy', 'cute', 'naked', 'erotic', 'solo', 'sologirl', 'live sex', 'hd',
        'watching', 'wet', 'romantic', 'sensual', 'teasing', 'flirting',
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Build a shared, normalized basic draft payload for a model post.
     * Used by the sidebar Generate path; DO NOT change behaviour.
     *
     * @param int   $post_id Model post ID.
     * @param array $context Optional future-facing context.
     * @return array<string,mixed>
     */
    public static function build_basic_draft_payload( int $post_id, array $context = [] ): array {
        $post = get_post( $post_id );
        if ( ! ( $post instanceof \WP_Post ) ) {
            return [];
        }

        $name = trim( (string) $post->post_title );
        if ( $name === '' ) {
            $name = 'Model';
        }

        $tags_all = self::collect_model_tags( $post );
        $filtered = self::filter_tags( $tags_all );
        $tags         = $filtered['used'];
        $tags_blocked = $filtered['blocked'];

        $platform_profiles = self::collect_platform_profiles( $post->ID );
        $platforms = array_values( array_unique( array_filter( array_map(
            static fn( array $profile ): string => (string) ( $profile['platform'] ?? '' ),
            $platform_profiles
        ) ) ) );

        return [
            'post_id'             => (int) $post->ID,
            'model_name'          => $name,
            'post_title'          => $name,
            'tags_all'            => $tags_all,
            'tags_filtered'       => $tags,
            'tags_top'            => array_slice( $tags, 0, 6 ),
            'tags_blocked'        => $tags_blocked,
            'platform_profiles'   => $platform_profiles,
            'platforms'           => $platforms,
            'internal_link_targets' => self::default_internal_link_targets(),
            'provider_inputs'     => self::collect_provider_inputs(),
            'context'             => is_array( $context ) ? $context : [],
        ];
    }

    /**
     * Build a high-quality, preview-only long-form draft payload.
     *
     * Delegates to ModelContentGenerationFacade, which uses the same proven
     * template strategy as the sidebar "Generate" button.
     *
     * Safety guarantees (enforced by facade):
     *  - NEVER writes post_content
     *  - NEVER writes Rank Math meta
     *  - NEVER changes noindex / canonical
     *  - NEVER calls any remote API
     *
     * @param int   $post_id Model post ID.
     * @param array $context Rich context from ModelDraftContextBuilder::build().
     * @return array<string,mixed>
     */
    public static function build_longform_preview_draft( int $post_id, array $context = [] ): array {
        // Prefer the facade (high-quality, same logic as sidebar Generate).
        if (
            class_exists( '\\TMWSEO\\Engine\\Model\\ModelContentGenerationFacade' )
            && method_exists( '\\TMWSEO\\Engine\\Model\\ModelContentGenerationFacade', 'build_preview_draft' )
        ) {
            return ModelContentGenerationFacade::build_preview_draft( $post_id, $context );
        }

        // Hard fallback: basic payload only (facade missing — should not happen in production).
        $payload = self::build_basic_draft_payload( $post_id, $context );
        if ( empty( $payload ) ) {
            return [ 'ok' => false, 'post_id' => $post_id ];
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[TMW-MODEL-DRAFT] WARNING: ModelContentGenerationFacade not found — using basic fallback. post_id=' . $post_id );
        }

        return [
            'ok'                 => false,
            'post_id'            => $post_id,
            'model_name'         => $payload['model_name'] ?? 'Model',
            'word_count_estimate'=> 0,
            'title_suggestion'   => '',
            'primary_keyword'    => strtolower( (string) ( $payload['model_name'] ?? 'model' ) ),
            'safe_keywords'      => [],
            'platform_keywords'  => [],
            'excluded_keywords'  => $payload['tags_blocked'] ?? [],
            'sections'           => [],
            'faq'                => [],
            'html_preview'       => '',
            '_source'            => 'ModelContentDraftService::fallback',
        ];
    }

    // ── Private helpers (used by build_basic_draft_payload — DO NOT MODIFY) ──

    private static function normalize_tag( string $tag ): string {
        $tag = trim( $tag );
        $tag = (string) preg_replace( '/\s+/', ' ', $tag );
        return rtrim( $tag, ", \t\n\r\0\x0B" );
    }

    /** @return string[] */
    private static function collect_model_tags( \WP_Post $post ): array {
        $taxes = get_object_taxonomies( $post->post_type, 'names' );
        if ( ! is_array( $taxes ) ) {
            $taxes = [];
        }

        $all = [];
        foreach ( $taxes as $tax ) {
            if ( ! is_string( $tax ) || $tax === '' || $tax === 'post_format' ) {
                continue;
            }

            $names = wp_get_post_terms( $post->ID, $tax, [ 'fields' => 'names' ] );
            if ( is_wp_error( $names ) || ! is_array( $names ) ) {
                continue;
            }

            foreach ( $names as $name ) {
                if ( ! is_string( $name ) ) {
                    continue;
                }
                $name = self::normalize_tag( $name );
                if ( $name === '' ) {
                    continue;
                }
                $all[] = $name;
            }
        }

        return array_values( array_unique( $all ) );
    }

    /** @return array{used: string[], blocked: string[]} */
    private static function filter_tags( array $tags ): array {
        $used    = [];
        $blocked = [];

        foreach ( $tags as $tag ) {
            $normalized = strtolower( self::normalize_tag( (string) $tag ) );
            if ( $normalized === '' ) {
                continue;
            }

            foreach ( self::$blocked_tags as $blocked_tag ) {
                if ( $normalized === $blocked_tag ) {
                    $blocked[] = (string) $tag;
                    continue 2;
                }
            }

            if ( in_array( $normalized, self::$generic_tags, true ) ) {
                continue;
            }

            $used[] = (string) $tag;
        }

        $used    = array_values( array_unique( array_map( [ __CLASS__, 'normalize_tag' ], $used    ) ) );
        $blocked = array_values( array_unique( array_map( [ __CLASS__, 'normalize_tag' ], $blocked ) ) );

        return [ 'used' => $used, 'blocked' => $blocked ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function collect_platform_profiles( int $post_id ): array {
        if ( ! class_exists( '\\TMWSEO\\Engine\\Platform\\PlatformProfiles' ) ) {
            return [];
        }

        $links = PlatformProfiles::get_links( $post_id );
        return is_array( $links ) ? $links : [];
    }

    /** @return array<string,string> */
    private static function default_internal_link_targets(): array {
        return [
            '/models/' => 'Browse All Models',
            '/videos/' => 'Videos',
            '/photos/' => 'Photos',
            '/blog/'   => 'Blog',
        ];
    }

    /** @return array<string,mixed> */
    private static function collect_provider_inputs(): array {
        return [
            'safe_mode'                => (bool) Settings::is_safe_mode(),
            'dry_run_mode'             => (int) Settings::get( 'tmwseo_dry_run_mode', 0 ),
            'openai_configured'        => class_exists( '\\TMWSEO\\Engine\\Services\\OpenAI' )
                ? (bool) \TMWSEO\Engine\Services\OpenAI::is_configured()
                : false,
            'openai_model_for_quality' => method_exists( Settings::class, 'openai_model_for_quality' )
                ? (string) Settings::openai_model_for_quality()
                : '',
        ];
    }
}
