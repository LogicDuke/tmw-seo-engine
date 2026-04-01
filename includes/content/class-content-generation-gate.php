<?php
/**
 * TMW SEO Engine — Content Generation Gate
 *
 * Enforces prerequisites before content generation is allowed for any page.
 * This is the architectural boundary between keyword approval and content creation.
 *
 * No content is generated unless:
 * - the page has an assigned primary keyword
 * - ownership is valid (no conflicts with higher-priority pages)
 * - keyword confidence exists
 * - page-type-specific prerequisites are met
 * - the system is not in reset/rebuild mode
 *
 * @package TMWSEO\Engine\Content
 * @since   5.0.0
 */

namespace TMWSEO\Engine\Content;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Keywords\OwnershipEnforcer;
use TMWSEO\Engine\Platform\PlatformProfiles;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ContentGenerationGate {

    /** Option key: when set to 1, all content generation is paused (reset mode). */
    public const PAUSE_OPTION = 'tmwseo_content_generation_paused';

    /** Post meta key: stores the last gate evaluation result. */
    public const META_GATE_RESULT = '_tmwseo_content_gate_result';

    /**
     * Check whether content generation is allowed for a specific post.
     *
     * @return array{allowed:bool, reasons:string[], gate_details:array}
     */
    public static function evaluate( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return self::fail( [ 'post_not_found' ], [] );
        }

        $reasons = [];
        $details = [
            'post_id'   => $post_id,
            'post_type' => $post->post_type,
            'checked'   => current_time( 'mysql' ),
        ];

        // ── Gate 0: System-wide pause (reset mode) ────────────────
        if ( (int) get_option( self::PAUSE_OPTION, 0 ) === 1 ) {
            $reasons[] = 'content_generation_paused_system_wide';
        }

        // ── Gate 1: Must have assigned primary keyword ────────────
        $primary_kw = self::resolve_primary_keyword( $post_id );
        $details['primary_keyword'] = $primary_kw;
        if ( $primary_kw === '' ) {
            $reasons[] = 'missing_primary_keyword';
        }

        // ── Gate 2: Ownership must be valid ───────────────────────
        if ( $primary_kw !== '' ) {
            $ownership = OwnershipEnforcer::check_assignment( $primary_kw, $post_id, 'primary' );
            $details['ownership_check'] = $ownership['reason'];
            if ( ! $ownership['allowed'] ) {
                $reasons[] = 'ownership_conflict:' . $ownership['reason'];
            }
        }

        // ── Gate 3: Keyword confidence must exist ─────────────────
        $confidence_raw = get_post_meta( $post_id, '_tmwseo_keyword_confidence', true );
        $details['confidence'] = $confidence_raw;
        if ( $confidence_raw === '' || $confidence_raw === false ) {
            $reasons[] = 'keyword_confidence_not_set';
        } elseif ( (float) $confidence_raw < 10.0 ) {
            $reasons[] = 'keyword_confidence_too_low:' . round( (float) $confidence_raw );
        }

        // ── Gate 4: Page-type-specific prerequisites ──────────────
        $type_reasons = self::check_page_type_prerequisites( $post );
        $reasons = array_merge( $reasons, $type_reasons );

        // ── Store result ──────────────────────────────────────────
        $allowed = empty( $reasons );
        $result  = [
            'allowed'      => $allowed,
            'reasons'      => $reasons,
            'gate_details' => $details,
        ];

        update_post_meta( $post_id, self::META_GATE_RESULT, wp_json_encode( [
            'allowed' => $allowed,
            'reasons' => $reasons,
            'checked' => current_time( 'mysql' ),
        ] ) );

        if ( ! $allowed ) {
            Logs::info( 'content_gate', '[TMW-CONTENT-GATE] Content generation blocked', [
                'post_id' => $post_id,
                'reasons' => $reasons,
            ] );
        }

        return $result;
    }

    /**
     * Quick check: is content generation allowed for this post?
     */
    public static function is_allowed( int $post_id ): bool {
        $result = self::evaluate( $post_id );
        return $result['allowed'];
    }

    /**
     * Pause all content generation system-wide (reset mode).
     */
    public static function pause_all(): void {
        update_option( self::PAUSE_OPTION, 1 );
        Logs::info( 'content_gate', '[TMW-CONTENT-GATE] Content generation paused system-wide', [
            'user' => get_current_user_id(),
        ] );
    }

    /**
     * Resume content generation system-wide.
     */
    public static function resume_all(): void {
        update_option( self::PAUSE_OPTION, 0 );
        Logs::info( 'content_gate', '[TMW-CONTENT-GATE] Content generation resumed system-wide', [
            'user' => get_current_user_id(),
        ] );
    }

    /**
     * Is content generation paused system-wide?
     */
    public static function is_paused(): bool {
        return (int) get_option( self::PAUSE_OPTION, 0 ) === 1;
    }

    // ── Page-type prerequisites ───────────────────────────────

    private static function check_page_type_prerequisites( \WP_Post $post ): array {
        $reasons = [];

        switch ( $post->post_type ) {
            case 'model':
                $reasons = self::check_model_prerequisites( $post->ID );
                break;

            case 'post': // video posts
                $reasons = self::check_video_prerequisites( $post->ID );
                break;

            case 'tmw_category_page':
                $reasons = self::check_category_prerequisites( $post->ID );
                break;
        }

        return $reasons;
    }

    private static function check_model_prerequisites( int $post_id ): array {
        $reasons = [];

        // Must have at least one platform profile.
        // Support both legacy per-platform username metas and the newer
        // PlatformProfiles service so editor-side saved profiles are recognised.
        if ( ! self::model_has_platform_profile( $post_id ) ) {
            $reasons[] = 'model_missing_platform_profile';
        }

        // Model name must not be empty
        $title = trim( (string) get_the_title( $post_id ) );
        if ( $title === '' ) {
            $reasons[] = 'model_missing_title';
        }

        return $reasons;
    }

    private static function resolve_primary_keyword( int $post_id ): string {
        $primary_kw = trim( (string) get_post_meta( $post_id, '_tmwseo_keyword', true ) );
        if ( $primary_kw !== '' ) {
            return $primary_kw;
        }

        $rank_math_focus = trim( (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ) );
        if ( $rank_math_focus !== '' ) {
            $parts = array_values( array_filter( array_map( 'trim', explode( ',', $rank_math_focus ) ) ) );
            if ( ! empty( $parts ) ) {
                return (string) $parts[0];
            }
        }

        return '';
    }

    private static function model_has_platform_profile( int $post_id ): bool {
        $platforms = [ 'livejasmin', 'stripchat', 'chaturbate', 'myfreecams', 'camsoda', 'bonga', 'cam4' ];
        foreach ( $platforms as $p ) {
            if ( trim( (string) get_post_meta( $post_id, '_tmwseo_platform_username_' . $p, true ) ) !== '' ) {
                return true;
            }
        }

        if ( class_exists( PlatformProfiles::class ) && method_exists( PlatformProfiles::class, 'get_links' ) ) {
            $links = PlatformProfiles::get_links( $post_id );
            if ( is_array( $links ) ) {
                foreach ( $links as $link ) {
                    if ( ! is_array( $link ) ) {
                        continue;
                    }
                    $platform = sanitize_key( (string) ( $link['platform'] ?? '' ) );
                    $url      = trim( (string) ( $link['url'] ?? '' ) );
                    if ( $platform !== '' || $url !== '' || ! empty( $link['is_primary'] ) ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function check_video_prerequisites( int $post_id ): array {
        $reasons = [];

        // Must have rewritten title
        $is_rewritten = (string) get_post_meta( $post_id, '_tmwseo_title_rewritten', true ) === '1';
        if ( ! $is_rewritten ) {
            $reasons[] = 'video_title_not_rewritten';
        }

        return $reasons;
    }

    private static function check_category_prerequisites( int $post_id ): array {
        // Category pages have lighter prerequisites for now
        return [];
    }

    private static function fail( array $reasons, array $details ): array {
        return [
            'allowed'      => false,
            'reasons'      => $reasons,
            'gate_details' => $details,
        ];
    }
}
