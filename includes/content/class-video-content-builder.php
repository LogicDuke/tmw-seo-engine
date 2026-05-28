<?php
/**
 * TMW SEO Engine — Video Content Builder
 *
 * Builds complete, SEO-ready content for video posts when the operator clicks
 * Generate in the right-sidebar TMW AI Generator metabox.
 *
 * Produces:
 *   – 600–800 words of neutral, non-graphic video-page copy
 *   – H2/H3 heading structure that satisfies Rank Math's subheading check
 *   – Focus keyword in the first paragraph, in subheadings, and throughout body
 *   – Rank Math SEO title, meta description, and keyword CSV
 *   – Safe guard: RankMath title/description only overwrite empty or default values
 *   – RankMath focus keyword always written (backup saved first)
 *
 * Architecture:
 *   VideoGeneratePolicy  → gate (decides whether generation is allowed)
 *   VideoContentBuilder  → builder (this class, produces content + meta)
 *   AdminAjaxHandlers    → router (writes HTML block and RankMath fields)
 *
 * Content style:
 *   – Video-page copy, NOT model bio copy
 *   – Neutral, non-graphic
 *   – Deterministic for Template strategy (no randomness, same output on retry)
 *   – Includes internal link to model page when the model post is found
 *
 * Logging:
 *   [TMW-VIDEO-BUILD]  content assembly steps
 *   [TMW-VIDEO-META]   Rank Math field write decisions
 *
 * @package TMWSEO\Engine\Content
 * @since   5.8.13
 */

namespace TMWSEO\Engine\Content;

use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VideoContentBuilder {

    /** Site brand name used in SEO title suffix. */
    private const SITE_BRAND = 'Top-Models.Webcam';

    /** Target word count range. */
    private const TARGET_WORDS_MIN = 600;
    private const TARGET_WORDS_MAX = 800;
    private const EXPLICIT_SKIP_EXACT = [
        'girl', 'girls', 'hot', 'sexy', 'cute', 'naked', 'cam', 'webcam',
        'live', 'model', 'hd', 'solo', 'amateur', 'xxx', 'porn', 'sex',
        'ass', 'pussy', 'dildo', 'fuck', 'fucking', 'fucked', 'blowjob',
        'cum', 'cumshot', 'cock', 'penis', 'vagina', 'nude', 'horny',
        'orgasm', 'squirt', 'fingering', 'masturbation', 'anal',
    ];
    private const EXPLICIT_SKIP_PATTERN = '/\b(ass|puss(?:y|ies)|dildo(?:s)?|fuck(?:ed|ing|er|ers)?|blow\s*job(?:s)?|cum(?:shot|shots)?|cock(?:s)?|penis(?:es)?|vagina(?:s)?|nude|naked|horny|orgasm(?:s|ic)?|squirt(?:ing)?|finger(?:ing|ed)?|masturbat(?:e|es|ed|ing|ion)|anal)\b/i';

    /**
     * Build complete video content: HTML body + Rank Math field recommendations.
     *
     * @param int $post_id
     * @return array{
     *   html:               string,
     *   seo_title:          string,
     *   meta_description:   string,
     *   focus_keyword:      string,
     *   secondary_keywords: string[],
     *   keyword_pack:       array,
     *   word_count:         int,
     * }
     */
    public static function build( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            Logs::warn( 'video_build', '[TMW-VIDEO-BUILD] post not found', [ 'post_id' => $post_id ] );
            return self::empty_result();
        }

        // ── Gather source data ────────────────────────────────────────────────
        $title          = trim( (string) get_the_title( $post_id ) );
        $imported_title = trim( (string) get_post_meta( $post_id, '_tmw_original_title', true ) );
        $model_name     = self::resolve_model_name( $post_id );
        $model_url      = self::find_model_page_url( $model_name );
        $categories     = self::collect_categories( $post_id );
        $tags           = self::collect_safe_tags( $post_id );
        $platform       = self::resolve_platform( $post_id );

        // ── Derive video focus keyword ────────────────────────────────────────
        $focus_kw  = self::derive_focus_keyword( $title, $model_name );
        $secondary = self::build_secondary_keywords( $model_name, $focus_kw );

        Logs::info( 'video_build', '[TMW-VIDEO-BUILD] Building video content', [
            'post_id'    => $post_id,
            'model_name' => $model_name,
            'focus_kw'   => $focus_kw,
            'model_url'  => $model_url,
        ] );

        // ── Build HTML content ────────────────────────────────────────────────
        $html = self::build_content_html(
            $post_id,
            $title,
            $imported_title,
            $model_name,
            $model_url,
            $categories,
            $tags,
            $platform,
            $focus_kw,
            $secondary
        );

        $html = self::enforce_minimum_word_count( $html, $model_name, $focus_kw, $model_url );

        $word_count = str_word_count( wp_strip_all_tags( $html ) );

        Logs::info( 'video_build', '[TMW-VIDEO-BUILD] Content assembled', [
            'post_id'    => $post_id,
            'word_count' => $word_count,
            'focus_kw'   => $focus_kw,
        ] );

        // ── Build Rank Math fields ────────────────────────────────────────────
        $seo_title   = self::build_seo_title( $model_name, $focus_kw, $title );
        $meta_desc   = self::build_meta_description( $model_name, $focus_kw, $title );
        $keyword_pack = [
            'primary'    => $focus_kw,
            'secondary'  => $secondary,
            'additional' => $secondary,
            'longtail'   => [],
        ];

        return [
            'html'               => $html,
            'seo_title'          => $seo_title,
            'meta_description'   => $meta_desc,
            'focus_keyword'      => $focus_kw,
            'secondary_keywords' => $secondary,
            'keyword_pack'       => $keyword_pack,
            'word_count'         => $word_count,
        ];
    }

    /**
     * Ensure content reaches minimum words by appending deterministic, neutral sections.
     *
     * Appends one or more safe blocks while preserving H2/H3 structure and avoiding
     * keyword stuffing. Keeps total length near TARGET_WORDS_MAX when feasible.
     */
    private static function enforce_minimum_word_count(
        string $html,
        string $model_name,
        string $focus_kw,
        string $model_url
    ): string {
        $word_count = str_word_count( wp_strip_all_tags( $html ) );
        if ( $word_count >= self::TARGET_WORDS_MIN ) {
            return $html;
        }

        $mn      = $model_name !== '' ? $model_name : 'the featured model';
        $mn_safe = esc_html( $mn );
        $fk_safe = esc_html( $focus_kw !== '' ? $focus_kw : 'this webcam video' );

        $model_link = '';
        if ( $model_url !== '' && $model_name !== '' ) {
            $model_link = '<a href="' . esc_url( $model_url ) . '">'
                . esc_html( $model_name ) . ' webcam model profile</a>';
        }

        $sections = [
            [
                '<h2>More Context for This Webcam Video Page</h2>',
                '<p>This page is designed as a browsing guide for visitors who want a quick overview before opening a webcam clip. It keeps the description neutral, explains where the clip fits in the site structure, and helps users move between related pages without confusion. The content is intentionally informational and avoids explicit language while still describing the page topic clearly for readers and search engines.</p>',
                '<p>When someone searches for ' . $fk_safe . ', they often want to confirm they have landed on the right video page. This section supports that goal by clarifying how this post connects with tags, categories, and related model pages. It also helps first-time visitors understand that video posts are part of a wider library of webcam sessions and discovery pages.</p>',
            ],
            [
                '<h2>How to Discover Related Video Pages</h2>',
                '<p>A practical way to continue browsing is to open related tags and category links from this page. Those navigation paths group similar webcam sessions and make it easier to compare different clips by theme, format, and model relationship. This improves internal discovery without changing the original video metadata or post intent.</p>',
                '<p>Visitors can move from one video page to another using internal links and archive pages, then return when needed. This creates a clear browsing flow that supports quick scanning and deeper exploration. The approach is simple: start with this video, follow relevant topics, and use model-oriented pages to refine what you want to watch next.</p>',
            ],
            [
                '<h3>FAQ: How Does This Video Relate to the Model Page?</h3>',
                '<p>This video page focuses on one session, while the model page provides broader context such as profile details, related clips, and navigation to additional content. '
                    . ( $model_link !== ''
                        ? 'If you want a fuller overview, visit the ' . $model_link . ' and then return to this post for the specific session reference. '
                        : 'If a dedicated profile link is not available, use tags and categories on this page to find similar posts connected to ' . $mn_safe . '. ' )
                    . 'Together, these page types help organize browsing in a consistent, user-friendly format.</p>',
            ],
        ];

        foreach ( $sections as $section_parts ) {
            if ( $word_count >= self::TARGET_WORDS_MIN ) {
                break;
            }
            $candidate_html  = $html . "\n\n" . implode( "\n\n", $section_parts );
            $candidate_words = str_word_count( wp_strip_all_tags( $candidate_html ) );

            // Prefer staying near the target max when possible.
            if ( $word_count < self::TARGET_WORDS_MIN && $word_count < self::TARGET_WORDS_MAX ) {
                $html       = $candidate_html;
                $word_count = $candidate_words;
            }
        }

        return $html;
    }

    /**
     * Write Rank Math fields after content has been saved.
     *
     * Guards:
     * – rank_math_title       only overwritten when empty or appears to be a default
     * – rank_math_description only overwritten when empty
     * – rank_math_focus_keyword always written (previous value backed up first)
     *
     * Never touches rank_math_robots or any indexing state.
     *
     * @param int    $post_id
     * @param array  $build   Output from self::build()
     * @param bool   $is_manual_generate Allow explicit sidebar Generate to overwrite existing SEO title/description.
     */
    public static function write_rank_math_fields( int $post_id, array $build, bool $is_manual_generate = false ): void {
        $focus_kw    = trim( (string) ( $build['focus_keyword'] ?? '' ) );
        $seo_title   = trim( (string) ( $build['seo_title'] ?? '' ) );
        $meta_desc   = trim( (string) ( $build['meta_description'] ?? '' ) );
        $secondary   = is_array( $build['secondary_keywords'] ?? null ) ? $build['secondary_keywords'] : [];

        // ── SEO title (guarded) ───────────────────────────────────────────────
        if ( $seo_title !== '' && ( $is_manual_generate || self::is_rank_math_title_writable( $post_id ) ) ) {
            update_post_meta( $post_id, 'rank_math_title', $seo_title );
            Logs::info( 'video_meta', '[TMW-VIDEO-META] Wrote rank_math_title', [
                'post_id' => $post_id,
                'value'   => $seo_title,
            ] );
        } else {
            Logs::info( 'video_meta', '[TMW-VIDEO-META] Skipped rank_math_title (non-empty or no value)', [
                'post_id'   => $post_id,
                'writable'  => self::is_rank_math_title_writable( $post_id ),
                'has_value' => $seo_title !== '',
            ] );
        }

        // ── Meta description (guarded) ────────────────────────────────────────
        if ( $meta_desc !== '' && ( $is_manual_generate || self::is_rank_math_description_writable( $post_id ) ) ) {
            update_post_meta( $post_id, 'rank_math_description', $meta_desc );
            Logs::info( 'video_meta', '[TMW-VIDEO-META] Wrote rank_math_description', [
                'post_id' => $post_id,
                'value'   => $meta_desc,
            ] );
        }

        // ── Focus keyword (always written — this is the main fix) ─────────────
        if ( $focus_kw !== '' ) {
            // Backup previous value before overwriting
            $previous_kw = trim( (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ) );
            if ( $previous_kw !== '' && $previous_kw !== $focus_kw ) {
                if ( (string) get_post_meta( $post_id, '_tmwseo_prev_rank_math_focus_keyword', true ) === '' ) {
                    update_post_meta( $post_id, '_tmwseo_prev_rank_math_focus_keyword', $previous_kw );
                    update_post_meta( $post_id, '_tmwseo_prev_rank_math_focus_keyword_at', current_time( 'mysql' ) );
                }
            }

            // Write focus keyword CSV: primary + up to 4 secondary
            $kw_list = array_values( array_unique( array_filter( array_map( 'trim', array_merge(
                [ $focus_kw ],
                array_slice( $secondary, 0, 4 )
            ) ) ) ) );
            $kw_csv = implode( ',', array_slice( $kw_list, 0, 5 ) );

            update_post_meta( $post_id, 'rank_math_focus_keyword', $kw_csv );
            update_post_meta( $post_id, '_tmwseo_keyword', $focus_kw );
            update_post_meta( $post_id, '_tmwseo_video_rankmath_managed', '1' );
            update_post_meta( $post_id, '_tmwseo_video_rankmath_managed_at', current_time( 'mysql' ) );

            Logs::info( 'video_meta', '[TMW-VIDEO-META] Wrote rank_math_focus_keyword', [
                'post_id'  => $post_id,
                'csv'      => $kw_csv,
                'previous' => $previous_kw,
            ] );
        }
    }

    // ── Focus keyword derivation ──────────────────────────────────────────────

    /**
     * Derive a short, search-friendly video focus keyword from the post title.
     *
     * Strategy: look for a video modifier in the title and combine it with the
     * model name to produce a scannable, 3-4 word phrase.
     *
     * Example: "Lexy Ness Plays With Her Amazing Body — Webcam Video Chat"
     *   → title contains "video chat" → "Lexy Ness video chat"
     *   → falls through to "Lexy Ness webcam video" (default)
     */
    public static function derive_focus_keyword( string $title, string $model_name ): string {
        $title_lc = mb_strtolower( trim( $title ), 'UTF-8' );
        $mn       = trim( $model_name );

        // Ordered preference: check which modifier appears first in the title
        $modifier_map = [
            'video chat'      => 'video chat',
            'webcam video'    => 'webcam video',
            'live webcam clip' => 'live webcam clip',
            'cam show'        => 'cam show',
            'cam session'     => 'webcam session',
            'webcam session'  => 'webcam session',
            'video'           => 'webcam video',
            'clip'            => 'live webcam clip',
            'webcam'          => 'webcam video',
        ];

        foreach ( $modifier_map as $needle => $modifier ) {
            if ( strpos( $title_lc, $needle ) !== false ) {
                if ( $mn !== '' ) {
                    return $mn . ' ' . $modifier;
                }
                return $modifier;
            }
        }

        // Default
        if ( $mn !== '' ) {
            return $mn . ' webcam video';
        }

        return 'webcam video';
    }

    /**
     * Build secondary keywords around the model name + video modifiers.
     *
     * @return string[]
     */
    public static function build_secondary_keywords( string $model_name, string $primary_kw ): array {
        $base = [];
        if ( $model_name === '' ) {
            $base = [
                'webcam video',
                'live webcam clip',
                'video chat',
                'cam show',
            ];
        } else {
            $base = [
                $model_name . ' webcam video',
                $model_name . ' video chat',
                $model_name . ' live webcam clip',
                $model_name . ' cam show',
                'watch ' . $model_name . ' webcam video',
            ];
        }

        // Remove exact primary keyword to avoid duplication
        $primary_lc = mb_strtolower( trim( $primary_kw ), 'UTF-8' );
        $filtered   = array_values( array_filter( $base, static function ( $kw ) use ( $primary_lc ) {
            return mb_strtolower( trim( $kw ), 'UTF-8' ) !== $primary_lc;
        } ) );

        $deduped = [];
        $seen    = [];
        foreach ( $filtered as $kw ) {
            $normalized = mb_strtolower( trim( (string) $kw ), 'UTF-8' );
            if ( $normalized === '' || isset( $seen[ $normalized ] ) ) {
                continue;
            }
            $seen[ $normalized ] = true;
            $deduped[]           = trim( (string) $kw );
        }

        return array_slice( $deduped, 0, 4 );
    }

    // ── SEO title + meta description ─────────────────────────────────────────

    /**
     * Build an SEO title for video posts.
     *
     * Pattern: [Focus Keyword] — [Brand]
     * Example: Lexy Ness Video Chat Clip | Top-Models.Webcam
     */
    public static function build_seo_title( string $model_name, string $focus_kw, string $title ): string {
        $brand = self::SITE_BRAND;
        if ( $focus_kw !== '' ) {
            $year    = gmdate( 'Y' );
            $display = ucwords( $focus_kw );
            $title   = $display . ': Best Webcam Clip Guide ' . $year;
            if ( mb_strlen( $title, 'UTF-8' ) > 70 ) {
                $title = mb_substr( $title, 0, 70, 'UTF-8' );
            }
            return rtrim( $title, " \t\n\r\0\x0B-:|" );
        }
        if ( $model_name !== '' ) {
            return $model_name . ' Webcam Video | ' . $brand;
        }
        // Shorten long raw title to 65 chars
        $short = mb_substr( $title, 0, 40, 'UTF-8' );
        return rtrim( $short ) . ' | ' . $brand;
    }

    /**
     * Build a meta description for video posts.
     *
     * Pattern: Watch the [focus_kw] with neutral scene context and related
     *          webcam video pages on [Brand].
     */
    public static function build_meta_description( string $model_name, string $focus_kw, string $title ): string {
        $brand = self::SITE_BRAND;
        if ( $focus_kw !== '' ) {
            $desc = $focus_kw . ' page with neutral webcam clip context, model links, related tags, and similar live cam videos on ' . $brand . '.';
        } elseif ( $model_name !== '' ) {
            $desc = 'Browse the ' . $model_name . ' webcam video page with neutral context, safe category browsing, and related live webcam clips on ' . $brand . '.';
        } else {
            $desc = 'Browse this webcam video page with neutral context, safe category browsing, and related live webcam clips on ' . $brand . '.';
        }
        // Cap at 160 chars
        if ( mb_strlen( $desc, 'UTF-8' ) > 160 ) {
            $desc = mb_substr( $desc, 0, 157, 'UTF-8' ) . '...';
        }
        return $desc;
    }

    // ── HTML content builder ──────────────────────────────────────────────────

    /**
     * Build the full 600-800 word HTML content block.
     *
     * Structure:
     *   Intro paragraph (focus keyword first sentence)
     *   H2: About This [model] Webcam Video
     *   2 paragraphs
     *   H2: What Viewers Can Expect From This Live Webcam Clip
     *   2 paragraphs
     *   H3: [model] and Top-Models.Webcam
     *   1 paragraph (internal link if available)
     *   H2: Related Webcam Videos and Model Clips
     *   1 paragraph (browsing language)
     *   H2: Why This Video Page Is Useful
     *   1 paragraph
     *   H2: Frequently Asked Questions
     *   H3: Is This a [model] Webcam Video?
     *   H3: What Kind of Video Page Is This?
     *   H3: Where Can I Find More Webcam Clips Like This?
     */
    private static function build_content_html(
        int $post_id,
        string $title,
        string $imported_title,
        string $model_name,
        string $model_url,
        array $categories,
        array $tags,
        string $platform,
        string $focus_kw,
        array $secondary
    ): string {
        $mn         = $model_name !== '' ? $model_name : 'the featured model';
        $safe_title = self::sanitize_visible_video_phrase( $title, 'this webcam video page' );
        $safe_imported_title = self::sanitize_visible_video_phrase( $imported_title, '' );
        $mn_safe    = esc_html( $mn );
        $fk_safe    = esc_html( $focus_kw !== '' ? $focus_kw : 'this webcam video' );
        $title_safe = esc_html( $safe_title );
        $brand      = esc_html( self::SITE_BRAND );

        // Tags list for body text (max 4, safe)
        $tag_list = ! empty( $tags )
            ? implode( ', ', array_map( 'esc_html', array_slice( $tags, 0, 4 ) ) )
            : 'live webcam clip, cam show, video chat';

        // Category list for body text (max 3)
        $cat_list = ! empty( $categories )
            ? implode( ', ', array_map( 'esc_html', array_slice( $categories, 0, 3 ) ) )
            : 'webcam videos';

        // Original import title reference (if different from current title)
        $origin_note = '';
        if ( $safe_imported_title !== '' && strtolower( $safe_imported_title ) !== strtolower( $safe_title ) ) {
            $origin_note = ' The original imported scene title was <em>' . esc_html( $safe_imported_title ) . '</em>.';
        }

        // Internal link to model page
        $model_link = '';
        if ( $model_url !== '' && $model_name !== '' ) {
            $model_link = '<a href="' . esc_url( $model_url ) . '">'
                . esc_html( $model_name ) . ' webcam model profile</a>';
        }

        $parts = [];

        // ── Intro paragraph ───────────────────────────────────────────────────
        $parts[] = '<p>'
            . ucfirst( $fk_safe ) . ' is a live webcam video page on ' . $brand . ' dedicated to '
            . $title_safe . '. '
            . 'This page provides neutral browsing context for visitors who want quick background before opening the clip, '
            . 'along with safe category browsing, related video links, and discovery language for search engines.'
            . $origin_note
            . '</p>';

        // ── H2: About This Webcam Video ───────────────────────────────────────
        $parts[] = '<h2>About This ' . $mn_safe . ' Webcam Video</h2>';

        $parts[] = '<p>'
            . 'The ' . $fk_safe . ' page covers a single webcam session connected to ' . $mn_safe . ' on ' . $brand . '. '
            . 'Webcam video pages like this one exist to give search visitors a neutral landing point — describing '
            . 'what kind of content to expect, linking to related clips, and supporting safe browsing across the site. '
            . 'The page does not reproduce graphic material; it provides context for the video only.'
            . '</p>';

        $parts[] = '<p>'
            . 'Video pages on ' . $brand . ' are connected to model pages, category pages, and tag archives. '
            . 'Each page targets a specific long-tail webcam video keyword so that visitors searching for '
            . esc_html( $focus_kw ) . ' or related terms find exactly the right page. '
            . 'The content on this page is non-graphic, non-invasive, and designed to meet safe search standards.'
            . '</p>';

        // ── H2: What Viewers Can Expect ───────────────────────────────────────
        $parts[] = '<h2>What Viewers Can Expect From This Live Webcam Clip</h2>';

        $parts[] = '<p>'
            . 'This live webcam clip page gives visitors a clear description before they click through to the video. '
            . 'The clip is connected to the ' . esc_html( $platform ) . ' platform and tagged with '
            . $tag_list . '. '
            . 'The page is categorised under ' . $cat_list . ', making it discoverable through both keyword search '
            . 'and site category browsing.'
            . '</p>';

        $parts[] = '<p>'
            . 'Visitors arriving on this page are typically searching for terms like '
            . esc_html( $focus_kw ) . ', '
            . esc_html( $secondary[0] ?? ( $model_name !== '' ? $model_name . ' webcam video' : 'live webcam video' ) ) . ', '
            . esc_html( $secondary[1] ?? 'live webcam clip' ) . ', or '
            . esc_html( $secondary[2] ?? 'cam show content' ) . '. '
            . 'The page is structured to match those search intents and to provide neutral, safe context that helps '
            . 'visitors decide whether this webcam session matches what they are looking for.'
            . '</p>';

        // ── H3: Model and Site Context ────────────────────────────────────────
        $parts[] = '<h3>' . $mn_safe . ' on ' . $brand . '</h3>';

        if ( $model_link !== '' ) {
            $parts[] = '<p>'
                . $mn_safe . ' is a live cam model with a dedicated profile page on ' . $brand . '. '
                . 'Visit the ' . $model_link . ' for platform links, related webcam clips, session schedules, and more content from this model. '
                . 'The video page you are viewing now is one of several ' . $mn_safe . ' webcam video pages on the site.'
                . '</p>';
        } else {
            $parts[] = '<p>'
                . $mn_safe . ' is a live cam model featured on ' . $brand . '. '
                . 'Browse the tags and categories on this page to find related ' . $mn_safe . ' webcam clips, cam shows, and live webcam video content from similar models.'
                . '</p>';
        }

        // ── H2: Related Videos ────────────────────────────────────────────────
        $parts[] = '<h2>Related Webcam Videos and Model Clips</h2>';

        $parts[] = '<p>'
            . 'Top-Models.Webcam publishes hundreds of webcam video pages covering live cam sessions, '
            . 'video chat clips, cam show recordings, and webcam session previews. '
            . 'Use the tag and category links on this page to browse more '
            . esc_html( $focus_kw ) . ' content, find similar clips from ' . $mn_safe . ', '
            . 'or discover related models and sessions across the site.'
            . '</p>';

        // ── H2: Why This Page Is Useful ───────────────────────────────────────
        $parts[] = '<h2>Why This Video Page Is Useful</h2>';

        $parts[] = '<p>'
            . 'Video pages on ' . $brand . ' serve two purposes. For visitors, they provide neutral, safe context '
            . 'before opening a live webcam clip or cam show recording — useful when browsing from search results '
            . 'and wanting to confirm the content matches the search query. '
            . 'For search engines, these pages target specific long-tail keywords like '
            . $fk_safe . ' and help connect model profiles, category pages, and individual clips into a coherent '
            . 'site structure that supports webcam model discovery.'
            . '</p>';

        // ── H2: FAQ ───────────────────────────────────────────────────────────
        $parts[] = '<h2>Frequently Asked Questions</h2>';

        // FAQ 1
        $parts[] = '<h3>Is This a ' . $mn_safe . ' Webcam Video?</h3>';
        $parts[] = '<p>'
            . 'Yes. This page is connected to ' . $mn_safe . ' and covers a specific webcam video session. '
            . 'The title and tags on this page reflect the original scene. '
            . 'For the full ' . $mn_safe . ' profile and live cam links, see the model page on ' . $brand . '.'
            . '</p>';

        // FAQ 2
        $parts[] = '<h3>What Kind of Video Page Is This?</h3>';
        $parts[] = '<p>'
            . 'This is a webcam video discovery page — a neutral content page designed to give visitors context '
            . 'about a specific live cam session. It describes the video, links to related content, and helps '
            . 'search engines understand the page\'s topic without reproducing graphic material.'
            . '</p>';

        // FAQ 3
        $parts[] = '<h3>Where Can I Find More Webcam Clips Like This?</h3>';
        $parts[] = '<p>'
            . 'Use the category and tag links on this page to browse similar webcam video clips and cam show pages. '
            . ( $model_link !== ''
                ? 'You can also visit the ' . $model_link . ' for more content from ' . $mn_safe . '. '
                : '' )
            . 'Top-Models.Webcam publishes regular video pages covering a wide range of live webcam sessions, '
            . 'video chat recordings, and cam show clips.'
            . '</p>';

        return implode( "\n\n", $parts );
    }

    // ── RankMath write guards ─────────────────────────────────────────────────

    /**
     * Return true if rank_math_title is safe to overwrite.
     *
     * Safe to write when:
     * – field is empty
     * – field contains only the Rank Math variable %%title%%
     * – field is exactly the raw post title (no SEO enhancement)
     * – field equals the WordPress default "%title% - %sitename%"
     */
    public static function is_rank_math_title_writable( int $post_id ): bool {
        $current = trim( (string) get_post_meta( $post_id, 'rank_math_title', true ) );
        if ( $current === '' ) {
            return true;
        }
        // Rank Math variable patterns
        if ( strpos( $current, '%%title%%' ) !== false ) {
            return true;
        }
        if ( strpos( $current, '%title%' ) !== false ) {
            return true;
        }
        // Equals the raw post title exactly (never enhanced)
        $raw_title = trim( (string) get_the_title( $post_id ) );
        if ( $raw_title !== '' && $current === $raw_title ) {
            return true;
        }
        return false;
    }

    /**
     * Return true if rank_math_description is safe to overwrite.
     *
     * Safe to write when field is empty or contains Rank Math variable patterns.
     */
    public static function is_rank_math_description_writable( int $post_id ): bool {
        $current = trim( (string) get_post_meta( $post_id, 'rank_math_description', true ) );
        if ( $current === '' ) {
            return true;
        }
        if ( strpos( $current, '%%excerpt%%' ) !== false || strpos( $current, '%excerpt%' ) !== false ) {
            return true;
        }
        return false;
    }

    // ── Data resolution helpers ───────────────────────────────────────────────

    private static function resolve_model_name( int $post_id ): string {
        // 1. Direct meta (fastest, most reliable for imported posts)
        $name = trim( (string) get_post_meta( $post_id, '_tmw_model_name', true ) );
        if ( $name !== '' ) {
            return $name;
        }
        $name = trim( (string) get_post_meta( $post_id, '_tmw_linked_model_name', true ) );
        if ( $name !== '' ) {
            return $name;
        }
        // 2. 'models' taxonomy term
        $terms = wp_get_post_terms( $post_id, 'models', [ 'fields' => 'names' ] );
        if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
            return (string) $terms[0];
        }
        return '';
    }

    private static function find_model_page_url( string $model_name ): string {
        if ( $model_name === '' ) {
            return '';
        }
        $slug  = sanitize_title( $model_name );
        $posts = get_posts( [
            'post_type'      => 'model',
            'name'           => $slug,
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );
        if ( ! empty( $posts ) ) {
            return (string) get_permalink( (int) $posts[0] );
        }
        return '';
    }

    private static function collect_categories( int $post_id ): array {
        $terms = wp_get_post_terms( $post_id, 'category', [ 'fields' => 'names' ] );
        if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
            return [];
        }
        $names = array_values( array_filter( array_map( 'strval', $terms ), 'strlen' ) );
        // Exclude default WordPress 'Uncategorized'
        return array_values( array_filter( $names, static fn( $n ) => strtolower( $n ) !== 'uncategorized' ) );
    }

    private static function collect_safe_tags( int $post_id ): array {
        $terms = wp_get_post_terms( $post_id, 'post_tag', [ 'fields' => 'names' ] );
        if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
            return [];
        }

        $safe = [];
        foreach ( $terms as $t ) {
            $raw = trim( (string) $t );
            $tl  = strtolower( $raw );
            if ( $tl === '' || strlen( $tl ) < 3 ) {
                continue;
            }

            if ( in_array( $tl, self::EXPLICIT_SKIP_EXACT, true ) ) {
                continue;
            }

            // Normalize separators so phrase and partial variants are caught.
            $normalized = str_replace( [ '-', '_', '/', '\\', '.' ], ' ', $tl );
            if ( preg_match( self::EXPLICIT_SKIP_PATTERN, $normalized ) === 1 ) {
                continue;
            }

            if ( preg_match( '/^[a-z0-9\s\-]+$/i', $raw ) !== 1 && preg_match( '/\p{L}/u', $raw ) !== 1 ) {
                continue;
            }

            if ( ! in_array( $raw, $safe, true ) ) {
                $safe[] = (string) $t;
            }
        }
        return array_slice( $safe, 0, 5 );
    }

    private static function sanitize_visible_video_phrase( string $phrase, string $fallback ): string {
        $clean = trim( $phrase );
        if ( $clean === '' ) {
            return $fallback;
        }
        $lower = strtolower( $clean );
        if ( in_array( $lower, self::EXPLICIT_SKIP_EXACT, true ) ) {
            return $fallback;
        }
        $normalized = str_replace( [ '-', '_', '/', '\\', '.' ], ' ', $lower );
        if ( preg_match( self::EXPLICIT_SKIP_PATTERN, $normalized ) === 1 ) {
            return $fallback;
        }
        return $clean;
    }

    private static function resolve_platform( int $post_id ): string {
        $platform = trim( (string) get_post_meta( $post_id, '_tmw_source_platform', true ) );
        if ( $platform !== '' ) {
            return ucfirst( $platform );
        }
        $cats  = wp_get_post_terms( $post_id, 'category', [ 'fields' => 'slugs' ] );
        $known = [ 'livejasmin' => 'LiveJasmin', 'stripchat' => 'Stripchat', 'chaturbate' => 'Chaturbate' ];
        if ( is_array( $cats ) ) {
            foreach ( $cats as $slug ) {
                if ( isset( $known[ (string) $slug ] ) ) {
                    return $known[ (string) $slug ];
                }
            }
        }
        return 'Top-Models.Webcam';
    }

    private static function empty_result(): array {
        return [
            'html'               => '',
            'seo_title'          => '',
            'meta_description'   => '',
            'focus_keyword'      => '',
            'secondary_keywords' => [],
            'keyword_pack'       => [],
            'word_count'         => 0,
        ];
    }
}
