<?php
/**
 * Model Content Generation Facade
 *
 * Preview-only adapter that exposes the same high-quality template strategy used
 * by the sidebar "Generate" button to the Long-Form SEO Draft Preview.
 *
 * Safety guarantees (identical to sidebar Generate path):
 *  - NEVER writes post_content
 *  - NEVER writes Rank Math meta
 *  - NEVER changes noindex / canonical
 *  - NEVER calls OpenAI or any remote API
 *  - NEVER modifies any WordPress option, meta, or post field
 *
 * @package TMWSEO\Engine\Model
 * @since   5.9.0
 */

namespace TMWSEO\Engine\Model;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class ModelContentGenerationFacade
 *
 * Thin facade around the proven template strategy already used by
 * ModelOptimizer::generate_with_templates() and ModelOptimizer::build_intro().
 *
 * Accepts the structured context produced by ModelDraftContextBuilder and
 * returns a rich, structured payload suitable for the Long-Form SEO Draft Preview.
 */
class ModelContentGenerationFacade {

    // ── Safety — same tag lists as ModelOptimizer ─────────────────────────────

    /** @var string[] Tags that must never appear in generated body copy. */
    private static array $blocked_tags = [
        'teen', 'teens', 'schoolgirl', 'school girl', 'young', 'virgin', 'underage',
    ];

    /** @var string[] Generic tags that add no SEO value and look spammy. */
    private static array $generic_tags = [
        'girl', 'hot', 'sexy', 'cute', 'naked', 'erotic', 'solo', 'sologirl', 'live sex', 'hd',
        'watching', 'wet', 'romantic', 'sensual', 'teasing', 'flirting',
    ];

    /** @var string[] Fragments that must never appear in body copy. */
    private static array $risky_fragments = [ 'porn', 'sex', 'nude', 'xxx' ];

    /** @var array<string,string> Canonical display names for known platforms. */
    private static array $platform_labels = [
        'livejasmin'  => 'LiveJasmin',
        'stripchat'   => 'Stripchat',
        'chaturbate'  => 'Chaturbate',
        'myfreecams'  => 'MyFreeCams',
        'camsoda'     => 'CamSoda',
        'bonga'       => 'BongaCams',
        'cam4'        => 'Cam4',
        'imlive'      => 'ImLive',
        'flirt4free'  => 'Flirt4Free',
        'jasmin'      => 'LiveJasmin',
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Build a high-quality, preview-only long-form draft payload.
     *
     * This mirrors the generation quality of ModelOptimizer::generate_suggestions()
     * but is strictly side-effect-free (no writes of any kind).
     *
     * @param int   $post_id Model post ID.
     * @param array $context Rich context from ModelDraftContextBuilder::build().
     * @return array<string,mixed>
     */
    public static function build_preview_draft( int $post_id, array $context ): array {
        $post = get_post( $post_id );
        if ( ! ( $post instanceof \WP_Post ) ) {
            return [ 'ok' => false, 'post_id' => $post_id, 'error' => 'post_not_found' ];
        }

        $name = trim( (string) $post->post_title );
        if ( $name === '' ) {
            $name = 'Model';
        }

        // ── Keyword buckets (from context, already filtered by ModelDraftContextBuilder)
        $safe_keywords     = self::coerce_string_array( $context['safe_keywords'] ?? [] );
        $platform_keywords = self::coerce_string_array( $context['platform_keywords'] ?? [] );
        $excluded_keywords = self::coerce_string_array( $context['excluded_keywords'] ?? [] );

        // Additional safety pass: strip any remaining risky fragments.
        $safe_keywords     = self::filter_risky( $safe_keywords );
        $platform_keywords = self::filter_risky( $platform_keywords );

        // ── Tags (real taxonomy terms from the post)
        $tags_all   = self::collect_model_tags( $post );
        $filtered   = self::filter_tags( $tags_all );
        $tags       = $filtered['used'];          // safe, non-generic tags
        $tags_blocked = $filtered['blocked'];     // safety-blocked tags (kept for audit)

        // Merge tags_blocked into excluded audit list (display only, never in body)
        $excluded_keywords = array_values( array_unique( array_merge( $excluded_keywords, $tags_blocked ) ) );

        // ── Platform profiles (real URLs from context)
        $platform_profiles = self::coerce_profile_array( $context['platform_profiles'] ?? [] );

        // ── Verified external links (real URLs from context)
        $verified_links = self::coerce_link_array( $context['verified_links'] ?? [] );

        // ── Internal link targets (real WordPress URLs from context)
        $internal_links = self::resolve_internal_links( $context['internal_links'] ?? [] );

        // ── Opportunity / Rank Math context
        $opportunity   = is_array( $context['opportunity'] ?? null ) ? (array) $context['opportunity'] : [];
        $rank_math     = is_array( $context['rank_math']    ?? null ) ? (array) $context['rank_math']   : [];
        $primary_keyword = self::resolve_primary_keyword( $name, $opportunity, $rank_math );

        // ── Generate using the proven template strategy
        $seo_title  = self::build_model_seo_title( $name );
        $meta_title = self::trim_len( $seo_title, 60 );
        $meta_desc  = self::build_meta_description( $name, $tags );

        // Intro — same proven approach as ModelOptimizer::build_intro()
        $platform_names = self::platform_names_from_profiles( $platform_profiles );
        $intro = self::build_intro( $name, $tags, $platform_names, $internal_links );

        // Full long-form sections
        $sections = self::build_sections(
            $name,
            $tags,
            $platform_profiles,
            $platform_names,
            $verified_links,
            $internal_links,
            $safe_keywords,
            $platform_keywords,
            $primary_keyword
        );

        // FAQ — real SEO content, not disclaimers
        $faq = self::build_faq( $name, $tags, $platform_names );

        // Assemble HTML preview
        $html = self::render_html( $intro, $sections, $faq, $name );

        $word_count = str_word_count( wp_strip_all_tags( $html ) );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[TMW-MODEL-FACADE] preview_built post_id=%d tags=%d platforms=%d verified_links=%d words=%d',
                $post_id,
                count( $tags ),
                count( $platform_profiles ),
                count( $verified_links ),
                $word_count
            ) );
        }

        return [
            'ok'               => true,
            'post_id'          => $post_id,
            'model_name'       => $name,
            'seo_title'        => $seo_title,
            'meta_title'       => $meta_title,
            'meta_description' => $meta_desc,
            'title_suggestion' => $seo_title,
            'primary_keyword'  => $primary_keyword,
            'safe_keywords'    => $safe_keywords,
            'platform_keywords'=> $platform_keywords,
            'excluded_keywords'=> $excluded_keywords,
            'tags_used'        => $tags,
            'sections'         => $sections,
            'faq'              => $faq,
            'html_preview'     => $html,
            'word_count_estimate' => $word_count,
            '_source'          => 'ModelContentGenerationFacade',
        ];
    }

    // ── Content generation — same quality as ModelOptimizer ──────────────────

    /**
     * Build the intro paragraph.
     * Uses the identical proven approach from ModelOptimizer::build_intro().
     */
    private static function build_intro(
        string $name,
        array $tags,
        array $platform_names,
        array $internal_links
    ): string {
        $tag_bits = array_slice(
            array_values( array_filter( $tags, static fn( $x ) => trim( (string) $x ) !== '' ) ),
            0, 6
        );

        $sentences = [];

        $sentences[] = 'Meet ' . $name . ', a live cam model available for private webcam chat and real-time shows.';

        if ( ! empty( $tag_bits ) ) {
            $sentences[] = 'Popular content areas for ' . $name . ' include ' . implode( ', ', $tag_bits ) . '.';
        }

        $sentences[] = 'Browse the latest videos, explore related categories, and use the tags on this page to find the exact style you like.';

        if ( ! empty( $platform_names ) ) {
            $labels = array_slice( array_values( array_unique( $platform_names ) ), 0, 3 );
            $sentences[] = 'You can also find ' . $name . ' on ' . implode( ', ', $labels ) . '.';
        }

        // Real internal links
        $sentences[] = self::build_internal_link_sentence( $internal_links );

        $sentences[] = 'This page is for adults only (18+).';

        $text = implode( ' ', $sentences );

        // Filler sentences (same as ModelOptimizer) to reach minimum word count
        $fillers = [
            'Use the navigation and tag filters to discover more performers and videos that match your preferences.',
            'If you are new here, start with the most popular tags and then explore deeper combinations for long-tail discoveries.',
            'For best results, look at both live sessions and recent uploads — this helps you find fresh content faster.',
            'Bookmark this model page and come back later for updates, new videos, and featured highlights.',
            'We keep descriptions focused on categories so the page stays clear, useful, and SEO-friendly.',
        ];

        $words = preg_split( '/\s+/', trim( strip_tags( $text ) ) );
        $count = is_array( $words ) ? count( array_filter( $words ) ) : 0;
        $i = 0;
        while ( $count < 150 && $i < count( $fillers ) ) {
            $text .= ' ' . $fillers[ $i ];
            $i++;
            $words = preg_split( '/\s+/', trim( strip_tags( $text ) ) );
            $count = is_array( $words ) ? count( array_filter( $words ) ) : 0;
        }

        if ( $count < 150 ) {
            $text .= ' Explore similar tags to compare styles, and check the model profile links for alternate platforms if available.';
        }

        // Hard cap at ~250 words
        $words = preg_split( '/\s+/', trim( strip_tags( $text ) ) );
        if ( is_array( $words ) && count( $words ) > 250 ) {
            $text = implode( ' ', array_slice( $words, 0, 240 ) ) . '…';
        }

        return trim( $text );
    }

    /**
     * Build all long-form sections using the same quality level as the sidebar generator.
     * Each section uses real data: actual tags, actual platform links, actual archive URLs.
     *
     * @return array<int,array{heading:string,level:string,body:string,html?:string}>
     */
    private static function build_sections(
        string $name,
        array $tags,
        array $platform_profiles,
        array $platform_names,
        array $verified_links,
        array $internal_links,
        array $safe_keywords,
        array $platform_keywords,
        string $primary_keyword
    ): array {
        $t1 = (string) ( $tags[0] ?? '' );
        $t2 = (string) ( $tags[1] ?? '' );
        $t3 = (string) ( $tags[2] ?? '' );
        $tag_phrase = implode( ', ', array_filter( [ $t1, $t2, $t3 ], static fn( $x ) => trim( $x ) !== '' ) );

        $sections = [];

        // ── Section 1: About {name}
        $about_body = 'Meet ' . $name . ', a live cam model known for ' . ( $tag_phrase !== '' ? $tag_phrase . ' content' : 'engaging live shows' ) . '. ';
        $about_body .= 'This profile page is your starting point for discovering ' . $name . '\'s content, live room schedule, and available platforms. ';
        if ( ! empty( $tags ) ) {
            $about_body .= 'Her profile is tagged with ' . implode( ', ', array_slice( $tags, 0, 5 ) ) . ', which reflects the style and content areas she covers. ';
        }
        $about_body .= 'Use the navigation on this page to browse related models, filter by tag, and explore the video archive. ';
        $about_body .= 'New content is added regularly, so check back often for fresh updates and new shows. This page is for adults only (18+).';

        $sections[] = [
            'heading' => 'About ' . $name,
            'level'   => 'h2',
            'body'    => $about_body,
        ];

        // ── Section 2: Live Cam Style
        $style_body = $name . '\'s live cam shows cover ' . ( $tag_phrase !== '' ? $tag_phrase : 'a range of styles' ) . '. ';

        if ( ! empty( $safe_keywords ) ) {
            $kw1 = (string) ( $safe_keywords[0] ?? '' );
            if ( $kw1 !== '' ) {
                $style_body .= 'If you are searching for ' . $kw1 . ', this profile is a strong match based on the model\'s current tags and content history. ';
            }
        }

        $style_body .= 'Live shows can vary in pace and format depending on the session, audience mix, and available platform features. ';
        $style_body .= 'Check the room notes and profile description for the most up-to-date style details, scheduled themes, and interaction formats. ';

        if ( ! empty( $tags ) && count( $tags ) > 3 ) {
            $style_body .= 'Additional content areas include ' . implode( ', ', array_slice( $tags, 3, 4 ) ) . '. ';
        }

        $style_body .= 'Browse the video archive to get a better sense of ' . $name . '\'s content range before joining a live session.';

        $sections[] = [
            'heading' => $name . ' Live Cam Style',
            'level'   => 'h2',
            'body'    => $style_body,
        ];

        // ── Section 3: Chat Experience
        $chat_body = 'Viewer interaction with ' . $name . ' is available via private chat, group shows, and platform-specific features depending on which room you join. ';

        if ( ! empty( $safe_keywords ) && count( $safe_keywords ) >= 2 ) {
            $kw2 = (string) ( $safe_keywords[1] ?? $primary_keyword );
            if ( $kw2 !== '' ) {
                $chat_body .= 'Searches for ' . $kw2 . ' often lead to profiles like this one, where live interaction and real-time chat are the main draw. ';
            }
        }

        $chat_body .= 'To get the most from a session, review the profile notes, check any pinned room guidance, and confirm which interaction options are currently available. ';
        $chat_body .= 'Interaction patterns can change depending on the time of day and platform settings, so a quick profile check before joining saves time. ';
        $chat_body .= 'This page lists available platforms and profile links below to help you get started quickly.';

        $sections[] = [
            'heading' => 'Chat Experience and Viewer Interaction',
            'level'   => 'h2',
            'body'    => $chat_body,
        ];

        // ── Section 4: Where to Watch (real platform links)
        $platform_html = self::build_platform_links_html( $name, $platform_profiles, $platform_keywords );
        $where_body = $name . ' is available on the platforms listed below. ';

        if ( ! empty( $platform_names ) ) {
            $where_body .= 'Current platforms include ' . implode( ', ', array_slice( $platform_names, 0, 3 ) ) . '. ';
        } else {
            $where_body .= 'Platform availability is listed in the profile links section below. ';
        }

        $where_body .= 'Click through to verify current room status, since live sessions depend on the model\'s schedule and platform availability. ';

        if ( ! empty( $platform_keywords ) ) {
            $pkw = (string) ( $platform_keywords[0] ?? '' );
            if ( $pkw !== '' ) {
                $where_body .= 'This profile is also associated with ' . $pkw . ' for platform-level discovery. ';
            }
        }

        $where_body .= 'Profile links are kept up to date — use them to navigate directly to the model\'s active room.';

        $sections[] = [
            'heading' => 'Where to Watch ' . $name,
            'level'   => 'h2',
            'body'    => $where_body,
            'html'    => $platform_html,
        ];

        // ── Section 5: Verified External Links (if any)
        if ( ! empty( $verified_links ) ) {
            $vel_html  = self::build_verified_links_html( $verified_links );
            $vel_body  = 'Additional verified links for ' . $name . ' are listed below. ';
            $vel_body .= 'These include direct profile links, fan pages, and other verified sources. ';
            $vel_body .= 'All links have been manually reviewed and are updated when changes are detected.';

            $sections[] = [
                'heading' => 'Verified Profile Links',
                'level'   => 'h2',
                'body'    => $vel_body,
                'html'    => $vel_html,
            ];
        }

        // ── Section 6: Related Models and Internal Links (real archive URLs)
        $browse_body = 'Explore more content using the links below. ';
        $browse_body .= 'The model archive, video section, photo gallery, and blog are the main discovery paths on this site. ';

        if ( ! empty( $internal_links['terms'] ) && is_array( $internal_links['terms'] ) ) {
            $term_names = array_column( array_slice( $internal_links['terms'], 0, 4 ), 'name' );
            if ( ! empty( $term_names ) ) {
                $browse_body .= 'Related categories for ' . $name . ' include ' . implode( ', ', $term_names ) . '. ';
                $browse_body .= 'Click the category links below to browse other models with matching content areas. ';
            }
        }

        $browse_body .= 'Use tag filters to combine categories and find exactly the content style you are looking for.';

        $internal_links_html = self::build_internal_links_html( $internal_links );

        $sections[] = [
            'heading' => 'Browse Related Content',
            'level'   => 'h2',
            'body'    => $browse_body,
            'html'    => $internal_links_html,
        ];

        return $sections;
    }

    /**
     * Build a real, SEO-focused FAQ.
     * Same quality goal as the sidebar generator; no generic disclaimers.
     *
     * @return array<int,array{question:string,answer:string}>
     */
    private static function build_faq( string $name, array $tags, array $platform_names ): array {
        $faq = [];

        $faq[] = [
            'question' => 'Who is ' . $name . '?',
            'answer'   => $name . ' is a live cam model available for private webcam chat and real-time shows. This profile page provides tags, platform links, videos, and photos to help you explore her content.',
        ];

        if ( ! empty( $tags ) ) {
            $faq[] = [
                'question' => 'What kind of shows does ' . $name . ' do?',
                'answer'   => $name . ' is tagged with ' . implode( ', ', array_slice( $tags, 0, 5 ) ) . '. Browse the video archive and photo gallery on this page for examples of her content style.',
            ];
        }

        if ( ! empty( $platform_names ) ) {
            $faq[] = [
                'question' => 'Where can I watch ' . $name . ' live?',
                'answer'   => 'You can find ' . $name . ' on ' . implode( ', ', array_slice( $platform_names, 0, 3 ) ) . '. Use the platform links on this page to navigate directly to her active room and check her current live status.',
            ];
        } else {
            $faq[] = [
                'question' => 'Where can I find ' . $name . '\'s live room?',
                'answer'   => 'Use the platform links on this profile page to navigate directly to ' . $name . '\'s active room. Check her profile for the most current schedule and live status.',
            ];
        }

        $faq[] = [
            'question' => 'Are there videos and photos of ' . $name . '?',
            'answer'   => 'Yes. Browse ' . $name . '\'s video archive and photo gallery using the links on this page. New content is added regularly.',
        ];

        $faq[] = [
            'question' => 'How do I find models similar to ' . $name . '?',
            'answer'   => 'Use the tag links on this page to browse other models with matching content areas. The model archive is also a great starting point for discovering new performers.',
        ];

        return $faq;
    }

    // ── HTML assembly ─────────────────────────────────────────────────────────

    /**
     * Render the final preview HTML.
     * All user-supplied content is properly escaped.
     */
    private static function render_html(
        string $intro,
        array $sections,
        array $faq,
        string $name
    ): string {
        $html = '';

        // Intro paragraph
        $html .= '<p>' . wp_kses_post( $intro ) . '</p>' . "\n";

        foreach ( $sections as $section ) {
            $level   = in_array( (string) ( $section['level'] ?? 'h2' ), [ 'h2', 'h3' ], true ) ? $section['level'] : 'h2';
            $heading = (string) ( $section['heading'] ?? '' );
            $body    = (string) ( $section['body']    ?? '' );
            $extra   = (string) ( $section['html']    ?? '' );

            $html .= '<' . $level . '>' . esc_html( $heading ) . '</' . $level . '>' . "\n";
            $html .= '<p>' . esc_html( $body ) . '</p>' . "\n";

            if ( $extra !== '' ) {
                // Extra HTML is assembled internally with proper escaping; use wp_kses_post
                $html .= wp_kses_post( $extra ) . "\n";
            }
        }

        // FAQ section
        $html .= '<h2>' . esc_html( 'FAQ About ' . $name ) . '</h2>' . "\n";
        $html .= '<p>Common questions about ' . esc_html( $name ) . ' are answered below.</p>' . "\n";
        foreach ( $faq as $item ) {
            $html .= '<h3>' . esc_html( (string) ( $item['question'] ?? '' ) ) . '</h3>' . "\n";
            $html .= '<p>' . esc_html( (string) ( $item['answer'] ?? '' ) ) . '</p>' . "\n";
        }

        return $html;
    }

    // ── Link builders (real URLs, properly escaped) ───────────────────────────

    /**
     * Build a plain-text sentence with real internal archive links.
     * Mirrors the link sentence in ModelOptimizer::build_intro().
     */
    private static function build_internal_link_sentence( array $internal_links ): string {
        $parts = [];

        if ( ! empty( $internal_links['models_archive'] ) ) {
            $parts[] = '<a href="' . esc_url( $internal_links['models_archive'] ) . '">Browse All Models</a>';
        } else {
            $parts[] = '<a href="' . esc_url( home_url( '/models/' ) ) . '">Browse All Models</a>';
        }

        if ( ! empty( $internal_links['videos_archive'] ) ) {
            $parts[] = '<a href="' . esc_url( $internal_links['videos_archive'] ) . '">Videos</a>';
        } else {
            $parts[] = '<a href="' . esc_url( home_url( '/videos/' ) ) . '">Videos</a>';
        }

        if ( ! empty( $internal_links['photos_archive'] ) ) {
            $parts[] = '<a href="' . esc_url( $internal_links['photos_archive'] ) . '">Photos</a>';
        } else {
            $parts[] = '<a href="' . esc_url( home_url( '/photos/' ) ) . '">Photos</a>';
        }

        if ( ! empty( $internal_links['blog_archive'] ) ) {
            $parts[] = '<a href="' . esc_url( $internal_links['blog_archive'] ) . '">Blog</a>';
        } else {
            $parts[] = '<a href="' . esc_url( home_url( '/blog/' ) ) . '">Blog</a>';
        }

        return 'Explore more: ' . implode( ', ', $parts ) . '.';
    }

    /**
     * Build an HTML block of real platform profile links.
     * Uses go_url > affiliate_url > profile_url, in that priority order.
     */
    private static function build_platform_links_html(
        string $name,
        array $platform_profiles,
        array $platform_keywords
    ): string {
        if ( empty( $platform_profiles ) ) {
            return '';
        }

        $html = '<ul class="tmwseo-platform-links">' . "\n";

        foreach ( $platform_profiles as $profile ) {
            $platform    = sanitize_key( (string) ( $profile['platform'] ?? '' ) );
            $label       = self::$platform_labels[ $platform ] ?? ucfirst( $platform );
            $go_url      = trim( (string) ( $profile['go_url'] ?? '' ) );
            $aff_url     = trim( (string) ( $profile['affiliate_url'] ?? '' ) );
            $profile_url = trim( (string) ( $profile['profile_url'] ?? '' ) );
            $is_primary  = ! empty( $profile['is_primary'] );

            // Priority: go_url > affiliate_url > profile_url
            $href = '';
            if ( $go_url !== '' && filter_var( $go_url, FILTER_VALIDATE_URL ) ) {
                $href = $go_url;
            } elseif ( $aff_url !== '' && filter_var( $aff_url, FILTER_VALIDATE_URL ) ) {
                $href = $aff_url;
            } elseif ( $profile_url !== '' && filter_var( $profile_url, FILTER_VALIDATE_URL ) ) {
                $href = $profile_url;
            }

            if ( $href === '' ) {
                continue;
            }

            $link_text = $is_primary
                ? 'Watch ' . esc_html( $name ) . ' on ' . esc_html( $label ) . ' (Primary)'
                : 'Watch ' . esc_html( $name ) . ' on ' . esc_html( $label );

            $html .= '<li><a href="' . esc_url( $href ) . '" rel="nofollow noopener" target="_blank">'
                   . $link_text
                   . '</a></li>' . "\n";
        }

        $html .= '</ul>' . "\n";

        // Avoid empty list
        if ( $html === '<ul class="tmwseo-platform-links">' . "\n" . '</ul>' . "\n" ) {
            return '';
        }

        return $html;
    }

    /**
     * Build an HTML block of verified external links.
     */
    private static function build_verified_links_html( array $verified_links ): string {
        if ( empty( $verified_links ) ) {
            return '';
        }

        $html = '<ul class="tmwseo-verified-links">' . "\n";

        foreach ( $verified_links as $link ) {
            $url   = trim( (string) ( $link['url']   ?? '' ) );
            $label = trim( (string) ( $link['label'] ?? '' ) );
            $type  = sanitize_key( (string) ( $link['type'] ?? '' ) );

            if ( $url === '' || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
                continue;
            }

            if ( $label === '' ) {
                $label = $type !== '' ? ucfirst( $type ) . ' Profile' : 'External Profile';
            }

            $html .= '<li><a href="' . esc_url( $url ) . '" rel="nofollow noopener" target="_blank">'
                   . esc_html( $label )
                   . '</a></li>' . "\n";
        }

        $html .= '</ul>' . "\n";

        if ( $html === '<ul class="tmwseo-verified-links">' . "\n" . '</ul>' . "\n" ) {
            return '';
        }

        return $html;
    }

    /**
     * Build an HTML block of real internal archive/term links.
     */
    private static function build_internal_links_html( array $internal_links ): string {
        $items = [];

        $archive_map = [
            'models_archive' => 'Browse All Models',
            'videos_archive' => 'Videos',
            'photos_archive' => 'Photos',
            'blog_archive'   => 'Blog',
        ];

        foreach ( $archive_map as $key => $label ) {
            $url = trim( (string) ( $internal_links[ $key ] ?? '' ) );
            if ( $url === '' ) {
                // Fallback to home_url slugs
                $slug_map = [
                    'models_archive' => '/models/',
                    'videos_archive' => '/videos/',
                    'photos_archive' => '/photos/',
                    'blog_archive'   => '/blog/',
                ];
                $url = home_url( $slug_map[ $key ] ?? '/' );
            }
            $items[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }

        // Term links
        $terms = is_array( $internal_links['terms'] ?? null ) ? (array) $internal_links['terms'] : [];
        foreach ( array_slice( $terms, 0, 8 ) as $term ) {
            $term_url  = trim( (string) ( $term['url']  ?? '' ) );
            $term_name = trim( (string) ( $term['name'] ?? '' ) );
            if ( $term_url === '' || $term_name === '' ) {
                continue;
            }
            $items[] = '<a href="' . esc_url( $term_url ) . '">' . esc_html( $term_name ) . '</a>';
        }

        if ( empty( $items ) ) {
            return '';
        }

        return '<p class="tmwseo-internal-links">' . implode( ' · ', $items ) . '</p>';
    }

    // ── Meta generation — same as ModelOptimizer ─────────────────────────────

    private static function build_model_seo_title( string $name ): string {
        $name = trim( $name );
        if ( $name === '' ) {
            return 'Live Chat – Watch Webcam Now';
        }
        return $name . ' Live Chat – Watch ' . $name . ' Webcam Now';
    }

    private static function build_meta_description( string $name, array $tags ): string {
        $tag_bits  = array_slice( array_values( array_filter( $tags, static fn( $x ) => trim( (string) $x ) !== '' ) ), 0, 3 );
        $tag_phrase = implode( ', ', $tag_bits );

        $desc = 'Watch ' . $name . ' live in private webcam chat. ';
        if ( $tag_phrase !== '' ) {
            $desc .= 'Explore ' . $tag_phrase . ' shows and more. ';
        } else {
            $desc .= 'Discover live cam shows, videos, and photos. ';
        }
        $desc .= '18+ only.';

        return self::trim_len( $desc, 155 );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function resolve_primary_keyword( string $name, array $opportunity, array $rank_math ): string {
        $opp_primary = trim( (string) ( $opportunity['primary_keyword'] ?? '' ) );
        if ( $opp_primary !== '' ) {
            return $opp_primary;
        }

        $rm_focus = is_array( $rank_math['focus_keywords'] ?? null )
            ? (string) ( $rank_math['focus_keywords'][0] ?? '' )
            : '';
        if ( $rm_focus !== '' ) {
            return $rm_focus;
        }

        return strtolower( $name );
    }

    private static function platform_names_from_profiles( array $platform_profiles ): array {
        $names = [];
        foreach ( $platform_profiles as $profile ) {
            $slug = sanitize_key( (string) ( $profile['platform'] ?? '' ) );
            if ( $slug === '' ) {
                continue;
            }
            $names[] = self::$platform_labels[ $slug ] ?? ucfirst( $slug );
        }
        return array_values( array_unique( array_filter( $names ) ) );
    }

    /**
     * Resolve internal link targets.
     * Normalises the array from ModelDraftContextBuilder (which has real WP URLs)
     * or falls back to home_url slugs.
     */
    private static function resolve_internal_links( $raw ): array {
        if ( ! is_array( $raw ) ) {
            $raw = [];
        }

        $out = [
            'models_archive' => trim( (string) ( $raw['models_archive'] ?? '' ) ),
            'videos_archive' => trim( (string) ( $raw['videos_archive'] ?? '' ) ),
            'photos_archive' => trim( (string) ( $raw['photos_archive'] ?? '' ) ),
            'blog_archive'   => trim( (string) ( $raw['blog_archive']   ?? '' ) ),
            'terms'          => is_array( $raw['terms'] ?? null ) ? (array) $raw['terms'] : [],
        ];

        // Fill empties with fallback slugs
        if ( $out['models_archive'] === '' ) { $out['models_archive'] = home_url( '/models/' ); }
        if ( $out['videos_archive'] === '' ) { $out['videos_archive'] = home_url( '/videos/' ); }
        if ( $out['photos_archive'] === '' ) { $out['photos_archive'] = home_url( '/photos/' ); }
        if ( $out['blog_archive']   === '' ) { $out['blog_archive']   = home_url( '/blog/'   ); }

        return $out;
    }

    /** Filter out any keyword containing a risky fragment. */
    private static function filter_risky( array $keywords ): array {
        return array_values( array_filter( $keywords, static function ( $kw ): bool {
            $low = strtolower( trim( (string) $kw ) );
            foreach ( self::$risky_fragments as $frag ) {
                if ( str_contains( $low, $frag ) ) {
                    return false;
                }
            }
            return true;
        } ) );
    }

    /** Collect all taxonomy terms assigned to the post (same as ModelOptimizer). */
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
            foreach ( $names as $n ) {
                if ( ! is_string( $n ) ) {
                    continue;
                }
                $n = self::normalize_tag( $n );
                if ( $n === '' ) {
                    continue;
                }
                $all[] = $n;
            }
        }

        return array_values( array_unique( $all ) );
    }

    /** Filter tags into used/blocked buckets (same logic as ModelOptimizer). */
    private static function filter_tags( array $tags ): array {
        $used    = [];
        $blocked = [];

        foreach ( $tags as $t ) {
            $norm = strtolower( self::normalize_tag( (string) $t ) );
            if ( $norm === '' ) {
                continue;
            }

            // Safety check
            foreach ( self::$blocked_tags as $b ) {
                if ( $norm === $b ) {
                    $blocked[] = (string) $t;
                    continue 2;
                }
            }

            // Generic check
            if ( in_array( $norm, self::$generic_tags, true ) ) {
                continue;
            }

            $used[] = (string) $t;
        }

        $used    = array_values( array_unique( array_map( [ __CLASS__, 'normalize_tag' ], $used    ) ) );
        $blocked = array_values( array_unique( array_map( [ __CLASS__, 'normalize_tag' ], $blocked ) ) );

        return [ 'used' => $used, 'blocked' => $blocked ];
    }

    private static function normalize_tag( string $t ): string {
        $t = trim( $t );
        $t = (string) preg_replace( '/\s+/', ' ', $t );
        return rtrim( $t, ", \t\n\r\0\x0B" );
    }

    private static function trim_len( string $s, int $max ): string {
        $s = trim( $s );
        if ( mb_strlen( $s ) <= $max ) {
            return $s;
        }
        return trim( mb_substr( $s, 0, $max - 1 ) ) . '…';
    }

    /** Coerce a mixed input into a flat array of strings. */
    private static function coerce_string_array( $value ): array {
        if ( ! is_array( $value ) ) {
            return [];
        }
        $out = [];
        foreach ( $value as $item ) {
            if ( is_string( $item ) && trim( $item ) !== '' ) {
                $out[] = trim( $item );
            }
        }
        return array_values( array_unique( $out ) );
    }

    /** Coerce platform profile rows to a safe array. */
    private static function coerce_profile_array( $value ): array {
        if ( ! is_array( $value ) ) {
            return [];
        }
        $out = [];
        foreach ( $value as $row ) {
            if ( is_array( $row ) ) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** Coerce verified link rows to a safe array. */
    private static function coerce_link_array( $value ): array {
        if ( ! is_array( $value ) ) {
            return [];
        }
        $out = [];
        foreach ( $value as $row ) {
            if ( is_array( $row ) ) {
                $out[] = $row;
            }
        }
        return $out;
    }
}
