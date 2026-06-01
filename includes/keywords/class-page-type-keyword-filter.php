<?php
namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Filters generated SEO keyword suggestions by page intent.
 *
 * This class is intentionally narrow: it only filters generated keyword arrays
 * before Rank Math/content builders consume them. It never creates, deletes, or
 * changes WordPress terms, slugs, indexing state, or manual content.
 */
class PageTypeKeywordFilter {

    /** @var string[] */
    private const UNSAFE_TERMS = [
        'cam porn',
        'porn',
        'porno',
        'adult',
        'sex',
        'xxx',
        'nude',
        'nudes',
        'naked',
        'leak',
        'leaked',
        'onlyfans',
        'fuck',
    ];

    /** @var string[] */
    private const MODEL_BLOCKED_PATTERNS = [
        'webcam video',
        'live webcam clip',
        'live webcam session',
        'cam show',
        'online cam',
        'adult webcam',
        'cam show clip',
        'cam session',
        'webcam session',
        'private cam show',
        'live cam show',
        'webcam clip',
        'clip',
    ];

    /** @var string[] */
    private const VIDEO_BLOCKED_PATTERNS = [
        'adult webcam',
        'webcam earnings',
        'cam profile',
    ];

    /** @var string[] */
    private const CATEGORY_BLOCKED_PATTERNS = [
        'webcam video',
        'video chat',
        'live webcam clip',
        'webcam clip',
        'cam show',
        'online cam',
        'adult webcam',
        'cam show clip',
        'live webcam session',
        'cam session',
        'webcam session',
        'cam profile',
        'profile links',
        'webcam earnings',
        'adult webcam',
        'watch ',
    ];

    /**
     * Filter keyword suggestions for a supported page type.
     *
     * @param string[] $keywords
     * @return string[]
     */
    public static function filter( array $keywords, string $page_type ): array {
        $page_type = strtolower( trim( $page_type ) );
        if ( $page_type === 'model' ) {
            return self::filter_for_model_page( $keywords );
        }
        if ( $page_type === 'video' || $page_type === 'post' ) {
            return self::filter_for_video_page( $keywords );
        }
        return self::filter_for_category_page( $keywords );
    }

    /**
     * @param string[] $keywords
     * @return string[]
     */
    public static function filter_unsafe( array $keywords ): array {
        return self::filter_keywords( $keywords, [] );
    }

    public static function is_unsafe( string $keyword ): bool {
        return self::contains_any_pattern( $keyword, self::UNSAFE_TERMS );
    }

    /**
     * Model pages keep entity/profile intent and drop video/session/show intent.
     *
     * @param string[] $keywords
     * @return string[]
     */
    public static function filter_for_model_page( array $keywords ): array {
        return self::filter_keywords( $keywords, self::MODEL_BLOCKED_PATTERNS, true );
    }

    /**
     * Video pages keep clip/session/long-tail intent and drop profile/earnings intent.
     *
     * @param string[] $keywords
     * @return string[]
     */
    public static function filter_for_video_page( array $keywords ): array {
        return self::filter_keywords( $keywords, self::VIDEO_BLOCKED_PATTERNS );
    }

    /**
     * Category pages keep archive/topic/browse intent and drop model/video intent.
     *
     * @param string[] $keywords
     * @return string[]
     */
    public static function filter_for_category_page( array $keywords ): array {
        return self::filter_keywords( $keywords, self::CATEGORY_BLOCKED_PATTERNS, true );
    }

    /**
     * @param string[] $keywords
     * @param string[] $blocked_patterns
     * @return string[]
     */
    private static function filter_keywords( array $keywords, array $blocked_patterns, bool $block_watch_prefix = false ): array {
        $out  = [];
        $seen = [];

        foreach ( $keywords as $keyword ) {
            $clean = self::clean_keyword( (string) $keyword );
            if ( $clean === '' || self::is_unsafe( $clean ) ) {
                continue;
            }

            $normalized = self::normalize( $clean );
            if ( $block_watch_prefix && preg_match( '/^watch\s+\S+/u', $normalized ) === 1 ) {
                continue;
            }
            if ( self::contains_any_pattern( $clean, $blocked_patterns ) ) {
                continue;
            }

            $key = strtolower( $normalized );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $out[]        = $clean;
        }

        return $out;
    }

    /** @param string[] $patterns */
    private static function contains_any_pattern( string $keyword, array $patterns ): bool {
        $normalized = self::normalize( $keyword );
        if ( $normalized === '' ) {
            return false;
        }

        foreach ( $patterns as $pattern ) {
            $needle = self::normalize( $pattern );
            if ( $needle === '' ) {
                continue;
            }
            if ( preg_match( '/(^|\s)' . preg_quote( $needle, '/' ) . '(\s|$)/u', $normalized ) === 1 ) {
                return true;
            }
        }

        return false;
    }

    private static function clean_keyword( string $keyword ): string {
        $keyword = wp_strip_all_tags( $keyword );
        $keyword = preg_replace( '/\s+/u', ' ', trim( $keyword ) );
        return is_string( $keyword ) ? $keyword : '';
    }

    private static function normalize( string $keyword ): string {
        $keyword = strtolower( wp_strip_all_tags( $keyword ) );
        $keyword = preg_replace( '/[\-_\/\.]+/u', ' ', $keyword );
        $keyword = preg_replace( '/[^a-z0-9\s]+/u', ' ', (string) $keyword );
        $keyword = preg_replace( '/\s+/u', ' ', (string) $keyword );
        return trim( (string) $keyword );
    }
}
