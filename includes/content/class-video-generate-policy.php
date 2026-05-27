<?php
/**
 * TMW SEO Engine — Video Generate Policy
 *
 * Separate gate and keyword policy for video post content generation.
 * Completely independent of ContentGenerationGate and OwnershipEnforcer.
 *
 * Why a separate class?
 * ─────────────────────
 * ContentGenerationGate was built around model pages. Its keyword resolver
 * reads `_tmwseo_keyword` first — for imported video posts this field holds
 * the base model name (e.g. "lexy ness"), not the video title. OwnershipEnforcer
 * then sees an exact model-page match and fires `model_page_owns_keyword`, which
 * is correct for model pages but wrong for video pages.
 *
 * Video pages have a different SEO role:
 *   – They are long-tail supporting pages, not entity pages.
 *   – The model name CAN and SHOULD appear inside the video keyword.
 *   – Ownership conflict applies only to bare base-name keywords.
 *   – Confidence is bootstrapped from title-rewrite state (not from the keyword pipeline).
 *
 * This class is called ONLY from AdminAjaxHandlers for post_type = 'post'.
 * All model, category, and queue paths remain unchanged.
 *
 * Logging prefixes:
 *   [TMW-VIDEO-GATE]     gate evaluation decisions
 *   [TMW-VIDEO-KEYWORD]  keyword resolution
 *   [TMW-VIDEO-GENERATE] generate entry / success
 *
 * @package TMWSEO\Engine\Content
 * @since   5.8.12
 */

namespace TMWSEO\Engine\Content;

use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VideoGeneratePolicy {

    // ── Video modifier terms that mark a non-base, video-intent keyword ──

    /** @var string[] */
    private const VIDEO_MODIFIERS = [
        'video',
        'webcam video',
        'live webcam clip',
        'clip',
        'cam show',
        'video chat',
        'webcam session',
        'cam session',
        'preview',
        'recording',
        'scene',
        'show',
        'webcam',
    ];

    /**
     * Minimum word-count suffix after the model name for a starts-with keyword
     * to be considered long-tail enough (without a video modifier).
     */
    private const MIN_SUFFIX_WORDS_WITHOUT_MODIFIER = 3;

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Evaluate whether a video post is allowed to generate content.
     *
     * This is the ONLY gate called for post_type = 'post' pages from the
     * right-sidebar Generate button.  ContentGenerationGate is NOT called.
     *
     * @param int $post_id
     * @return array{
     *   allowed: bool,
     *   reasons: string[],
     *   keyword: string,
     *   details: array<string, mixed>
     * }
     */
    public static function evaluate( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return self::fail( [ 'post_not_found' ], '', [] );
        }

        $reasons = [];
        $details = [
            'post_id'   => $post_id,
            'post_type' => $post->post_type,
            'checked'   => current_time( 'mysql' ),
        ];

        // ── Gate 0: System-wide pause (shared option with ContentGenerationGate) ──
        if ( (int) get_option( ContentGenerationGate::PAUSE_OPTION, 0 ) === 1 ) {
            $reasons[] = 'content_generation_paused_system_wide';
        }

        // ── Gate 1: Must be a video-eligible post ─────────────────────────────
        $detection = self::detect_video_signals( $post_id );
        $details['video_signals'] = $detection;
        if ( ! $detection['is_video_eligible'] ) {
            $reasons[] = 'not_a_video_eligible_post';
        }

        // ── Gate 2: Resolve keyword (video-aware, title-first) ────────────────
        $keyword = self::resolve_video_keyword( $post_id );
        $details['resolved_keyword'] = $keyword;

        Logs::info( 'video_gate', '[TMW-VIDEO-KEYWORD] Resolved keyword', [
            'post_id' => $post_id,
            'keyword' => $keyword,
            'source'  => $details['keyword_source'] ?? 'unknown',
        ] );

        if ( $keyword === '' ) {
            $reasons[] = 'missing_video_keyword';
        }

        // ── Gate 3: Video ownership policy ───────────────────────────────────
        if ( $keyword !== '' ) {
            $model_name = self::get_linked_model_name( $post_id );
            $ownership  = self::check_video_ownership( $keyword, $model_name );
            $details['ownership'] = $ownership;

            Logs::info( 'video_gate', '[TMW-VIDEO-GATE] Ownership check', [
                'post_id'    => $post_id,
                'keyword'    => $keyword,
                'model_name' => $model_name,
                'allowed'    => $ownership['allowed'],
                'reason'     => $ownership['reason'],
            ] );

            if ( ! $ownership['allowed'] ) {
                $reasons[] = 'ownership_conflict:' . $ownership['reason'];
            }
        }

        // ── Gate 4: Confidence (bootstrapped for video posts) ─────────────────
        $confidence = self::get_or_bootstrap_confidence( $post_id );
        $details['confidence'] = $confidence;
        if ( $confidence < 10.0 ) {
            $reasons[] = 'video_keyword_confidence_too_low:' . round( $confidence );
        }

        // ── Evaluate ──────────────────────────────────────────────────────────
        $allowed = empty( $reasons );

        if ( ! $allowed ) {
            Logs::info( 'video_gate', '[TMW-VIDEO-GATE] Video content generation blocked', [
                'post_id' => $post_id,
                'keyword' => $keyword,
                'reasons' => $reasons,
            ] );
        } else {
            Logs::info( 'video_gate', '[TMW-VIDEO-GATE] Video content generation allowed', [
                'post_id' => $post_id,
                'keyword' => $keyword,
            ] );
        }

        return [
            'allowed' => $allowed,
            'reasons' => $reasons,
            'keyword' => $keyword,
            'details' => $details,
        ];
    }

    // ── Video eligibility detection ───────────────────────────────────────

    /**
     * Detect whether a post is eligible for video generation using three signals.
     *
     * Signal 1 (strongest): WordPress post_format explicitly set to 'video'.
     * Signal 2: Title has been rewritten by VideoTitleRewriter (_tmwseo_title_rewritten=1).
     * Signal 3: Post was imported via the video importer (has a linked model meta).
     *
     * NOT requiring post_format alone avoids silently skipping imported posts that
     * were saved before the format was applied.
     *
     * @return array{is_video_eligible: bool, signals: string[]}
     */
    public static function detect_video_signals( int $post_id ): array {
        $signals = [];

        if ( has_post_format( 'video', $post_id ) ) {
            $signals[] = 'post_format_video';
        }

        if ( (string) get_post_meta( $post_id, '_tmwseo_title_rewritten', true ) === '1' ) {
            $signals[] = 'title_rewritten';
        }

        $model_name = self::get_linked_model_name( $post_id );
        if ( $model_name !== '' ) {
            $signals[] = 'has_linked_model';
        }

        return [
            'is_video_eligible' => ! empty( $signals ),
            'signals'           => $signals,
        ];
    }

    // ── Keyword resolution ────────────────────────────────────────────────

    /**
     * Resolve the primary keyword to use for video ownership evaluation.
     *
     * Priority order:
     * 1. Post title — if it contains a video modifier (rewritten title is the
     *    canonical video keyword source).
     * 2. _tmwseo_keyword — only if it is already video long-tail.
     * 3. rank_math_focus_keyword first segment — only if video long-tail.
     * 4. Post title as-is — even if not long-tail; ownership gate will decide.
     *
     * We intentionally do NOT fall back to a bare model-name keyword because
     * that is exactly what ContentGenerationGate does and what causes the block.
     *
     * @return string Resolved keyword, or empty string if no title available.
     */
    public static function resolve_video_keyword( int $post_id ): string {
        // 1. Post title with video modifier → canonical rewritten title path
        $title = trim( (string) get_the_title( $post_id ) );
        if ( $title !== '' && self::is_video_long_tail_keyword( $title ) ) {
            return $title;
        }

        // 2. Stored _tmwseo_keyword if already video long-tail
        $stored = trim( (string) get_post_meta( $post_id, '_tmwseo_keyword', true ) );
        if ( $stored !== '' && self::is_video_long_tail_keyword( $stored ) ) {
            return $stored;
        }

        // 3. Rank Math focus keyword first segment if video long-tail
        $rm_raw = trim( (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ) );
        if ( $rm_raw !== '' ) {
            $rm_parts = array_values(
                array_filter( array_map( 'trim', explode( ',', $rm_raw ) ) )
            );
            if ( ! empty( $rm_parts ) && self::is_video_long_tail_keyword( $rm_parts[0] ) ) {
                return (string) $rm_parts[0];
            }
        }

        // 4. Post title as-is (ownership gate evaluates; will block if base name only)
        return $title;
    }

    // ── Ownership policy ──────────────────────────────────────────────────

    /**
     * Video-specific keyword ownership check.
     *
     * Rules (in order):
     * – BLOCK  → keyword is exactly the model base name
     * – BLOCK  → keyword starts with model name + fewer than 3 words without
     *             a video modifier in the suffix (e.g. "lexy ness webcam")
     * – ALLOW  → keyword starts with model name + has video modifier in suffix
     * – ALLOW  → keyword starts with model name + has 3+ words after it
     * – ALLOW  → keyword contains model name but does not start with it
     * – ALLOW  → no model name found in keyword
     *
     * @return array{allowed: bool, reason: string}
     */
    public static function check_video_ownership( string $keyword, string $model_name ): array {
        $kw = mb_strtolower( trim( $keyword ), 'UTF-8' );
        $mn = mb_strtolower( trim( $model_name ), 'UTF-8' );

        if ( $mn === '' ) {
            return [ 'allowed' => true, 'reason' => 'no_model_name_to_check' ];
        }

        // ── Exact base match → block ──────────────────────────────────────
        if ( $kw === $mn ) {
            return [ 'allowed' => false, 'reason' => 'video_keyword_is_base_model_name' ];
        }

        // ── Starts-with model name → evaluate suffix ──────────────────────
        if ( strpos( $kw, $mn . ' ' ) === 0 ) {
            $suffix     = trim( substr( $kw, strlen( $mn ) ) );
            $word_count = ( $suffix === '' ) ? 0 : ( substr_count( trim( $suffix ), ' ' ) + 1 );

            // Suffix has a video modifier → long-tail → allow
            if ( self::is_video_long_tail_keyword( $suffix ) ) {
                return [ 'allowed' => true, 'reason' => 'video_modifier_in_suffix' ];
            }

            // Suffix is 3+ words without a modifier → still allow (long-tail by word count)
            if ( $word_count >= self::MIN_SUFFIX_WORDS_WITHOUT_MODIFIER ) {
                return [ 'allowed' => true, 'reason' => 'video_long_tail_by_word_count' ];
            }

            // Short suffix, no modifier → too close to base keyword → block
            return [ 'allowed' => false, 'reason' => 'video_keyword_too_close_to_model_base' ];
        }

        // ── Model name inside keyword (not at start) → allow ─────────────
        if ( strpos( $kw, $mn ) !== false ) {
            return [ 'allowed' => true, 'reason' => 'model_name_inside_video_keyword' ];
        }

        // ── No model name present → allow ─────────────────────────────────
        return [ 'allowed' => true, 'reason' => 'no_model_name_conflict' ];
    }

    // ── Confidence bootstrap ──────────────────────────────────────────────

    /**
     * Get keyword confidence for a video post, bootstrapping when not set.
     *
     * Video posts are never passed through the keyword confidence pipeline that
     * runs for model pages, so the meta is almost always empty on import.
     * We assign conservative defaults rather than blocking all video generation.
     *
     * Bootstrap values:
     * – 50.0  title has been explicitly rewritten by operator (strongest signal)
     * – 30.0  post has a linked model name (imported via video importer)
     * – 0.0   no signals → will produce keyword_confidence_too_low
     *
     * @return float
     */
    public static function get_or_bootstrap_confidence( int $post_id ): float {
        $raw = get_post_meta( $post_id, '_tmwseo_keyword_confidence', true );

        if ( $raw !== '' && $raw !== false ) {
            $stored = (float) $raw;
            if ( $stored >= 10.0 ) {
                return $stored;
            }
        }

        // Bootstrap from video signals
        if ( (string) get_post_meta( $post_id, '_tmwseo_title_rewritten', true ) === '1' ) {
            return 50.0;
        }

        if ( self::get_linked_model_name( $post_id ) !== '' ) {
            return 30.0;
        }

        return (float) ( $raw ?: 0 );
    }

    // ── Public helper: is_video_long_tail_keyword ─────────────────────────

    /**
     * Return true if the keyword contains at least one video modifier.
     *
     * Used by both the resolve step and the ownership policy.
     *
     * @param string $keyword
     * @return bool
     */
    public static function is_video_long_tail_keyword( string $keyword ): bool {
        $kw = mb_strtolower( trim( $keyword ), 'UTF-8' );
        if ( $kw === '' ) {
            return false;
        }
        foreach ( self::VIDEO_MODIFIERS as $modifier ) {
            if ( strpos( $kw, $modifier ) !== false ) {
                return true;
            }
        }
        return false;
    }

    // ── Internal helpers ──────────────────────────────────────────────────

    /**
     * Return the linked model name stored on a video post.
     *
     * Checks both _tmw_model_name (direct import field) and
     * _tmw_linked_model_name (relation field) with the same fallback order
     * used by build_inline_video_content_html().
     */
    private static function get_linked_model_name( int $post_id ): string {
        $name = trim( (string) get_post_meta( $post_id, '_tmw_model_name', true ) );
        if ( $name === '' ) {
            $name = trim( (string) get_post_meta( $post_id, '_tmw_linked_model_name', true ) );
        }
        return $name;
    }

    /** Build a consistent failed-evaluation result array. */
    private static function fail( array $reasons, string $keyword, array $details ): array {
        return [
            'allowed' => false,
            'reasons' => $reasons,
            'keyword' => $keyword,
            'details' => $details,
        ];
    }
}
