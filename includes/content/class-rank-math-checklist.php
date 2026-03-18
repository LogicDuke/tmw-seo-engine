<?php
/**
 * RankMathChecklist — TMW-computed SEO checklist mirroring Rank Math's analysis.
 *
 * This class evaluates a post against the same checks Rank Math runs in its JS
 * analyzer, but computed server-side from raw post data + stored Rank Math meta.
 *
 * Design rules:
 *   - Never reads from Rank Math's JS/DOM/sidebar. PHP-only.
 *   - Uses RankMathReader for Rank Math meta access.
 *   - Returns structured, deterministic results.
 *   - Page-type-aware severity mapping.
 *   - Each check produces: id, label, status, fix, severity.
 *
 * @package TMWSEO\Engine\Content
 * @since   4.5.0
 */
namespace TMWSEO\Engine\Content;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class RankMathChecklist {

    /** Status constants. */
    public const STATUS_PASS    = 'pass';
    public const STATUS_FAIL    = 'fail';
    public const STATUS_WARNING = 'warning';

    /** Severity constants. */
    public const SEV_MUST_FIX              = 'must_fix';
    public const SEV_RECOMMENDED           = 'recommended';
    public const SEV_OPTIONAL              = 'optional';
    public const SEV_IGNORE_FOR_PAGE_TYPE  = 'ignore_for_page_type';

    /* ──────────────────────────────────────────────
     * Main API
     * ────────────────────────────────────────────── */

    /**
     * Run all checks for a post and return structured results.
     *
     * @return array{
     *   post_id: int,
     *   post_type: string,
     *   focus_keyword: string,
     *   checks: array<array{id:string, label:string, status:string, fix:string, severity:string}>,
     *   summary: array{pass:int, fail:int, warning:int, score_estimate:int},
     * }
     */
    public static function evaluate( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return self::empty_result( $post_id, '' );
        }

        $post_type = $post->post_type;

        // Build analysis context.
        $ctx = self::build_context( $post );

        // Run all checks.
        $checks = [];
        $checks[] = self::check_focus_keyword_exists( $ctx );
        $checks[] = self::check_keyword_in_title( $ctx );
        $checks[] = self::check_keyword_starts_title( $ctx );
        $checks[] = self::check_keyword_in_meta_description( $ctx );
        $checks[] = self::check_keyword_in_permalink( $ctx );
        $checks[] = self::check_keyword_in_first_10_percent( $ctx );
        $checks[] = self::check_keyword_in_content( $ctx );
        $checks[] = self::check_keyword_in_subheadings( $ctx );
        $checks[] = self::check_keyword_in_image_alt( $ctx );
        $checks[] = self::check_keyword_density( $ctx );
        $checks[] = self::check_content_length( $ctx );
        $checks[] = self::check_internal_links( $ctx );
        $checks[] = self::check_external_links( $ctx );
        $checks[] = self::check_media_present( $ctx );
        $checks[] = self::check_title_has_number( $ctx );
        $checks[] = self::check_title_has_power_word( $ctx );
        $checks[] = self::check_title_readability( $ctx );
        $checks[] = self::check_meta_description_length( $ctx );
        $checks[] = self::check_permalink_length( $ctx );
        $checks[] = self::check_paragraph_length( $ctx );

        // Apply page-type severity overrides.
        $checks = self::apply_severity_overrides( $checks, $post_type );

        // Summarize.
        $pass    = count( array_filter( $checks, fn( $c ) => $c['status'] === self::STATUS_PASS ) );
        $fail    = count( array_filter( $checks, fn( $c ) => $c['status'] === self::STATUS_FAIL ) );
        $warning = count( array_filter( $checks, fn( $c ) => $c['status'] === self::STATUS_WARNING ) );
        $total   = count( $checks );
        $score   = $total > 0 ? (int) round( ( $pass / $total ) * 100 ) : 0;

        return [
            'post_id'       => $post_id,
            'post_type'     => $post_type,
            'focus_keyword' => $ctx['keyword'],
            'checks'        => $checks,
            'summary'       => [
                'pass'           => $pass,
                'fail'           => $fail,
                'warning'        => $warning,
                'score_estimate' => $score,
            ],
        ];
    }

    /* ──────────────────────────────────────────────
     * Context builder
     * ────────────────────────────────────────────── */

    /**
     * Build a flat context array from the post, for all checks to use.
     */
    private static function build_context( \WP_Post $post ): array {
        $post_id   = (int) $post->ID;
        $post_type = $post->post_type;

        // Rank Math stored meta.
        $keyword       = RankMathReader::get_primary_keyword( $post_id );
        $seo_title_raw = RankMathReader::get_seo_title( $post_id );
        $meta_desc     = RankMathReader::get_meta_description( $post_id );

        // Effective SEO title: stored override or post title.
        $seo_title = $seo_title_raw !== '' ? $seo_title_raw : trim( $post->post_title );

        // Content analysis.
        $content_raw   = $post->post_content;
        $content_text  = wp_strip_all_tags( strip_shortcodes( $content_raw ) );
        $content_lower = mb_strtolower( $content_text, 'UTF-8' );
        $word_count    = str_word_count( $content_text );

        // Slug.
        $slug = $post->post_name;

        // Parse content for structured elements.
        $headings    = self::extract_headings( $content_raw );
        $images      = self::extract_images( $content_raw );
        $links       = self::extract_links( $content_raw );
        $paragraphs  = self::extract_paragraphs( $content_raw );

        return [
            'post_id'       => $post_id,
            'post_type'     => $post_type,
            'keyword'       => $keyword,
            'keyword_lower' => mb_strtolower( $keyword, 'UTF-8' ),
            'seo_title'     => $seo_title,
            'seo_title_raw' => $seo_title_raw,
            'meta_desc'     => $meta_desc,
            'slug'          => $slug,
            'content_raw'   => $content_raw,
            'content_text'  => $content_text,
            'content_lower' => $content_lower,
            'word_count'    => $word_count,
            'headings'      => $headings,
            'images'        => $images,
            'links'         => $links,
            'paragraphs'    => $paragraphs,
        ];
    }

    /* ──────────────────────────────────────────────
     * Individual checks
     * ────────────────────────────────────────────── */

    private static function check_focus_keyword_exists( array $ctx ): array {
        $pass = $ctx['keyword'] !== '';
        return [
            'id'       => 'focus_keyword_exists',
            'label'    => 'Focus keyword is set',
            'status'   => $pass ? self::STATUS_PASS : self::STATUS_FAIL,
            'fix'      => $pass ? '' : 'Set a focus keyword in Rank Math or sync from TMW keyword pack.',
            'severity' => self::SEV_MUST_FIX,
        ];
    }

    private static function check_keyword_in_title( array $ctx ): array {
        if ( $ctx['keyword'] === '' ) {
            return self::skip( 'keyword_in_title', 'Keyword in SEO title', 'Set a focus keyword first.' );
        }
        $pass = self::str_contains_i( $ctx['seo_title'], $ctx['keyword'] );
        return [
            'id'       => 'keyword_in_title',
            'label'    => 'Focus keyword in SEO title',
            'status'   => $pass ? self::STATUS_PASS : self::STATUS_FAIL,
            'fix'      => $pass ? '' : sprintf( 'Add "%s" to the SEO title.', $ctx['keyword'] ),
            'severity' => self::SEV_MUST_FIX,
        ];
    }

    private static function check_keyword_starts_title( array $ctx ): array {
        if ( $ctx['keyword'] === '' ) {
            return self::skip( 'keyword_starts_title', 'Keyword near beginning of SEO title', 'Set a focus keyword first.' );
        }
        $title_lower = mb_strtolower( $ctx['seo_title'], 'UTF-8' );
        $pos = mb_strpos( $title_lower, $ctx['keyword_lower'] );
        // "Near beginning" = keyword starts within first 40% of title length or first 30 chars.
        $threshold = max( 30, (int) ( mb_strlen( $ctx['seo_title'] ) * 0.4 ) );
        $pass = ( $pos !== false && $pos <= $threshold );
        return [
            'id'       => 'keyword_starts_title',
            'label'    => 'Focus keyword near beginning of SEO title',
            'status'   => $pass ? self::STATUS_PASS : ( $pos !== false ? self::STATUS_WARNING : self::STATUS_FAIL ),
            'fix'      => $pass ? '' : sprintf( 'Move "%s" closer to the beginning of the SEO title.', $ctx['keyword'] ),
            'severity' => self::SEV_RECOMMENDED,
        ];
    }

    private static function check_keyword_in_meta_description( array $ctx ): array {
        if ( $ctx['keyword'] === '' ) {
            return self::skip( 'keyword_in_meta_desc', 'Keyword in meta description', 'Set a focus keyword first.' );
        }
        $pass = $ctx['meta_desc'] !== '' && self::str_contains_i( $ctx['meta_desc'], $ctx['keyword'] );
        $fix  = '';
        if ( $ctx['meta_desc'] === '' ) {
            $fix = 'Write a meta description that includes the focus keyword.';
        } elseif ( ! $pass ) {
            $fix = sprintf( 'Add "%s" to the meta description.', $ctx['keyword'] );
        }
        return [
            'id'       => 'keyword_in_meta_desc',
            'label'    => 'Focus keyword in meta description',
            'status'   => $pass ? self::STATUS_PASS : self::STATUS_FAIL,
            'fix'      => $fix,
            'severity' => self::SEV_MUST_FIX,
        ];
    }

    private static function check_keyword_in_permalink( array $ctx ): array {
        if ( $ctx['keyword'] === '' ) {
            return self::skip( 'keyword_in_url', 'Keyword in URL', 'Set a focus keyword first.' );
        }
        // Normalize: replace hyphens with spaces for matching.
        $slug_text = str_replace( '-', ' ', $ctx['slug'] );
        $pass = self::str_contains_i( $slug_text, $ctx['keyword'] );
        return [
            'id'       => 'keyword_in_url',
            'label'    => 'Focus keyword in URL/slug',
            'status'   => $pass ? self::STATUS_PASS : self::STATUS_WARNING,
            'fix'      => $pass ? '' : sprintf( 'Consider including "%s" in the URL slug.', $ctx['keyword'] ),
            'severity' => self::SEV_RECOMMENDED,
        ];
    }

    private static function check_keyword_in_first_10_percent( array $ctx ): array {
        if ( $ctx['keyword'] === '' || $ctx['word_count'] === 0 ) {
            return self::skip( 'keyword_in_first_10pct', 'Keyword in first 10% of content', 'Need content and a focus keyword.' );
        }
        $target_words = max( 1, (int) ceil( $ctx['word_count'] * 0.1 ) );
        $words = preg_split( '/\s+/', $ctx['content_text'], $target_words + 1 );
        $first_chunk = mb_strtolower( implode( ' ', array_slice( $words, 0, $target_words ) ), 'UTF-8' );
        $pass = mb_strpos( $first_chunk, $ctx['keyword_lower'] ) !== false;
        return [
            'id'       => 'keyword_in_first_10pct',
            'label'    => 'Focus keyword in first 10% of content',
            'status'   => $pass ? self::STATUS_PASS : self::STATUS_FAIL,
            'fix'      => $pass ? '' : sprintf( 'Use "%s" within the first ~%d words of the content.', $ctx['keyword'], $target_words ),
            'severity' => self::SEV_MUST_FIX,
        ];
    }

    private static function check_keyword_in_content( array $ctx ): array {
        if ( $ctx['keyword'] === '' ) {
            return self::skip( 'keyword_in_content', 'Keyword appears in content', 'Set a focus keyword first.' );
        }
        $pass = mb_strpos( $ctx['content_lower'], $ctx['keyword_lower'] ) !== false;
        return [
            'id'       => 'keyword_in_content',
            'label'    => 'Focus keyword appears in content',
            'status'   => $pass ? self::STATUS_PASS : self::STATUS_FAIL,
            'fix'      => $pass ? '' : sprintf( 'Use "%s" at least once in the page content.', $ctx['keyword'] ),
            'severity' => self::SEV_MUST_FIX,
        ];
    }

    private static function check_keyword_in_subheadings( array $ctx ): array {
        if ( $ctx['keyword'] === '' || empty( $ctx['headings'] ) ) {
            $status = $ctx['keyword'] === '' ? self::STATUS_WARNING : self::STATUS_WARNING;
            return [
                'id'       => 'keyword_in_subheadings',
                'label'    => 'Focus keyword in subheadings',
                'status'   => $status,
                'fix'      => empty( $ctx['headings'] ) ? 'Add subheadings (H2/H3) to structure the content.' : 'Set a focus keyword first.',
                'severity' => self::SEV_RECOMMENDED,
            ];
        }
        $found = false;
        foreach ( $ctx['headings'] as $h ) {
            if ( self::str_contains_i( $h['text'], $ctx['keyword'] ) ) {
                $found = true;
                break;
            }
        }
        return [
            'id'       => 'keyword_in_subheadings',
            'label'    => 'Focus keyword in subheadings',
            'status'   => $found ? self::STATUS_PASS : self::STATUS_WARNING,
            'fix'      => $found ? '' : sprintf( 'Include "%s" in at least one H2 or H3 heading.', $ctx['keyword'] ),
            'severity' => self::SEV_RECOMMENDED,
        ];
    }

    private static function check_keyword_in_image_alt( array $ctx ): array {
        if ( $ctx['keyword'] === '' || empty( $ctx['images'] ) ) {
            return [
                'id'       => 'keyword_in_image_alt',
                'label'    => 'Focus keyword in image alt text',
                'status'   => empty( $ctx['images'] ) ? self::STATUS_WARNING : self::STATUS_WARNING,
                'fix'      => empty( $ctx['images'] ) ? 'Add images with descriptive alt text.' : 'Set a focus keyword first.',
                'severity' => self::SEV_RECOMMENDED,
            ];
        }
        $found = false;
        foreach ( $ctx['images'] as $img ) {
            if ( self::str_contains_i( $img['alt'], $ctx['keyword'] ) ) {
                $found = true;
                break;
            }
        }
        return [
            'id'       => 'keyword_in_image_alt',
            'label'    => 'Focus keyword in image alt text',
            'status'   => $found ? self::STATUS_PASS : self::STATUS_WARNING,
            'fix'      => $found ? '' : sprintf( 'Add "%s" to the alt text of at least one image.', $ctx['keyword'] ),
            'severity' => self::SEV_RECOMMENDED,
        ];
    }

    private static function check_keyword_density( array $ctx ): array {
        if ( $ctx['keyword'] === '' || $ctx['word_count'] < 100 ) {
            return self::skip( 'keyword_density', 'Keyword density', 'Need at least 100 words and a focus keyword.' );
        }
        $kw_words    = str_word_count( $ctx['keyword'] );
        $occurrences = mb_substr_count( $ctx['content_lower'], $ctx['keyword_lower'] );
        $density     = ( $occurrences * $kw_words / $ctx['word_count'] ) * 100;

        if ( $density >= 0.5 && $density <= 2.5 ) {
            $status = self::STATUS_PASS;
            $fix    = '';
        } elseif ( $density < 0.5 ) {
            $status = self::STATUS_FAIL;
            $fix    = sprintf( 'Keyword density is %.1f%%. Use "%s" a few more times (target: 0.5–2.5%%).', $density, $ctx['keyword'] );
        } else {
            $status = self::STATUS_WARNING;
            $fix    = sprintf( 'Keyword density is %.1f%%. Consider reducing usage of "%s" (target: 0.5–2.5%%).', $density, $ctx['keyword'] );
        }
        return [
            'id'       => 'keyword_density',
            'label'    => sprintf( 'Keyword density (%.1f%%)', $density ),
            'status'   => $status,
            'fix'      => $fix,
            'severity' => self::SEV_RECOMMENDED,
        ];
    }

    private static function check_content_length( array $ctx ): array {
        $wc = $ctx['word_count'];
        if ( $wc >= 600 ) {
            $status = self::STATUS_PASS;
            $fix    = '';
        } elseif ( $wc >= 300 ) {
            $status = self::STATUS_WARNING;
            $fix    = sprintf( 'Content is %d words. Add ~%d more words for better ranking potential (target: 600+).', $wc, 600 - $wc );
        } else {
            $status = self::STATUS_FAIL;
            $fix    = sprintf( 'Content is only %d words. Aim for at least 600 words.', $wc );
        }
        return [
            'id'       => 'content_length',
            'label'    => sprintf( 'Content length (%d words)', $wc ),
            'status'   => $status,
            'fix'      => $fix,
            'severity' => self::SEV_MUST_FIX,
        ];
    }

    private static function check_internal_links( array $ctx ): array {
        $home = home_url();
        $internal = array_filter( $ctx['links'], function( $link ) use ( $home ) {
            return self::is_internal_url( $link['href'], $home );
        });
        $pass = count( $internal ) > 0;
        return [
            'id'       => 'internal_links',
            'label'    => 'Internal links present',
            'status'   => $pass ? self::STATUS_PASS : self::STATUS_FAIL,
            'fix'      => $pass ? '' : 'Add at least one internal link to another page on this site.',
            'severity' => self::SEV_MUST_FIX,
        ];
    }

    private static function check_external_links( array $ctx ): array {
        $home = home_url();
        $external = array_filter( $ctx['links'], function( $link ) use ( $home ) {
            return ! self::is_internal_url( $link['href'], $home ) && self::is_absolute_url( $link['href'] );
        });
        $pass = count( $external ) > 0;
        return [
            'id'       => 'external_links',
            'label'    => 'External links present',
            'status'   => $pass ? self::STATUS_PASS : self::STATUS_WARNING,
            'fix'      => $pass ? '' : 'Consider adding at least one external link to a relevant, authoritative source.',
            'severity' => self::SEV_RECOMMENDED,
        ];
    }

    private static function check_media_present( array $ctx ): array {
        $has_img   = ! empty( $ctx['images'] );
        $has_video = (bool) preg_match( '/<(video|iframe|embed)\b/i', $ctx['content_raw'] );
        $pass = $has_img || $has_video;
        return [
            'id'       => 'media_present',
            'label'    => 'Images or video present',
            'status'   => $pass ? self::STATUS_PASS : self::STATUS_WARNING,
            'fix'      => $pass ? '' : 'Add at least one image or video to enrich the content.',
            'severity' => self::SEV_RECOMMENDED,
        ];
    }

    private static function check_title_has_number( array $ctx ): array {
        $pass = (bool) preg_match( '/\d/', $ctx['seo_title'] );
        return [
            'id'       => 'title_has_number',
            'label'    => 'SEO title contains a number',
            'status'   => $pass ? self::STATUS_PASS : self::STATUS_WARNING,
            'fix'      => $pass ? '' : 'Titles with numbers tend to get more clicks (e.g. "Top 5…", "2026 Guide").',
            'severity' => self::SEV_OPTIONAL,
        ];
    }

    private static function check_title_has_power_word( array $ctx ): array {
        $power_words = self::get_power_words();
        $title_lower = mb_strtolower( $ctx['seo_title'], 'UTF-8' );
        $found = false;
        foreach ( $power_words as $pw ) {
            if ( mb_strpos( $title_lower, mb_strtolower( $pw, 'UTF-8' ) ) !== false ) {
                $found = true;
                break;
            }
        }
        return [
            'id'       => 'title_has_power_word',
            'label'    => 'SEO title contains a power/sentiment word',
            'status'   => $found ? self::STATUS_PASS : self::STATUS_WARNING,
            'fix'      => $found ? '' : 'Add a power word to the title (e.g. Exclusive, Guide, Verified, Top, Best).',
            'severity' => self::SEV_OPTIONAL,
        ];
    }

    private static function check_title_readability( array $ctx ): array {
        $len = mb_strlen( $ctx['seo_title'] );
        if ( $len >= 15 && $len <= 65 ) {
            $status = self::STATUS_PASS;
            $fix    = '';
        } elseif ( $len < 15 ) {
            $status = self::STATUS_FAIL;
            $fix    = sprintf( 'SEO title is only %d characters. Aim for 15–65 characters.', $len );
        } else {
            $status = self::STATUS_WARNING;
            $fix    = sprintf( 'SEO title is %d characters — it may get truncated in search results. Aim for under 65 characters.', $len );
        }
        return [
            'id'       => 'title_readability',
            'label'    => sprintf( 'SEO title length (%d chars)', $len ),
            'status'   => $status,
            'fix'      => $fix,
            'severity' => self::SEV_RECOMMENDED,
        ];
    }

    private static function check_meta_description_length( array $ctx ): array {
        $len = mb_strlen( $ctx['meta_desc'] );
        if ( $len === 0 ) {
            return [
                'id'       => 'meta_desc_length',
                'label'    => 'Meta description is empty',
                'status'   => self::STATUS_FAIL,
                'fix'      => 'Write a meta description between 120–160 characters.',
                'severity' => self::SEV_MUST_FIX,
            ];
        }
        if ( $len >= 120 && $len <= 160 ) {
            $status = self::STATUS_PASS;
            $fix    = '';
        } elseif ( $len < 80 ) {
            $status = self::STATUS_FAIL;
            $fix    = sprintf( 'Meta description is only %d characters. Aim for 120–160 characters.', $len );
        } elseif ( $len < 120 ) {
            $status = self::STATUS_WARNING;
            $fix    = sprintf( 'Meta description is %d characters. Try to reach 120+ characters.', $len );
        } else {
            $status = self::STATUS_WARNING;
            $fix    = sprintf( 'Meta description is %d characters — it may get truncated. Aim for under 160 characters.', $len );
        }
        return [
            'id'       => 'meta_desc_length',
            'label'    => sprintf( 'Meta description length (%d chars)', $len ),
            'status'   => $status,
            'fix'      => $fix,
            'severity' => self::SEV_RECOMMENDED,
        ];
    }

    private static function check_permalink_length( array $ctx ): array {
        $len = mb_strlen( $ctx['slug'] );
        if ( $len > 0 && $len <= 75 ) {
            $status = self::STATUS_PASS;
            $fix    = '';
        } elseif ( $len === 0 ) {
            $status = self::STATUS_WARNING;
            $fix    = 'Post slug is empty. Save or publish to generate one.';
        } else {
            $status = self::STATUS_WARNING;
            $fix    = sprintf( 'URL slug is %d characters. Shorter URLs (under 75 chars) tend to rank better.', $len );
        }
        return [
            'id'       => 'permalink_length',
            'label'    => sprintf( 'URL slug length (%d chars)', $len ),
            'status'   => $status,
            'fix'      => $fix,
            'severity' => self::SEV_OPTIONAL,
        ];
    }

    private static function check_paragraph_length( array $ctx ): array {
        $long = 0;
        foreach ( $ctx['paragraphs'] as $p ) {
            if ( str_word_count( $p ) > 150 ) {
                $long++;
            }
        }
        if ( $long === 0 ) {
            $status = self::STATUS_PASS;
            $fix    = '';
        } else {
            $status = self::STATUS_WARNING;
            $fix    = sprintf( '%d paragraph(s) exceed 150 words. Break them up for better readability.', $long );
        }
        return [
            'id'       => 'paragraph_length',
            'label'    => 'Short paragraphs',
            'status'   => $status,
            'fix'      => $fix,
            'severity' => self::SEV_OPTIONAL,
        ];
    }

    /* ──────────────────────────────────────────────
     * Page-type severity overrides
     * ────────────────────────────────────────────── */

    /**
     * Adjust severity based on page type. For instance, model pages may not
     * need external links, and video pages may have shorter content.
     */
    private static function apply_severity_overrides( array $checks, string $post_type ): array {
        $overrides = self::get_severity_overrides( $post_type );
        if ( empty( $overrides ) ) {
            return $checks;
        }

        foreach ( $checks as &$check ) {
            if ( isset( $overrides[ $check['id'] ] ) ) {
                $check['severity'] = $overrides[ $check['id'] ];
            }
        }
        unset( $check );

        return $checks;
    }

    /**
     * Return page-type-specific severity overrides.
     */
    private static function get_severity_overrides( string $post_type ): array {
        switch ( $post_type ) {
            case 'model':
                return [
                    'external_links'        => self::SEV_IGNORE_FOR_PAGE_TYPE,
                    'content_length'        => self::SEV_RECOMMENDED, // models may be shorter
                    'title_has_number'      => self::SEV_IGNORE_FOR_PAGE_TYPE,
                    'keyword_in_subheadings' => self::SEV_OPTIONAL,
                    'paragraph_length'      => self::SEV_IGNORE_FOR_PAGE_TYPE,
                ];

            case 'post': // video pages
                return [
                    'content_length'        => self::SEV_RECOMMENDED, // video pages lean on embeds
                    'external_links'        => self::SEV_OPTIONAL,
                    'keyword_in_subheadings' => self::SEV_OPTIONAL,
                    'paragraph_length'      => self::SEV_IGNORE_FOR_PAGE_TYPE,
                ];

            case 'tmw_category_page':
                return [
                    'title_has_number'      => self::SEV_OPTIONAL,
                    'keyword_in_image_alt'  => self::SEV_OPTIONAL,
                ];

            default:
                return [];
        }
    }

    /* ──────────────────────────────────────────────
     * Content parsers
     * ────────────────────────────────────────────── */

    /**
     * Extract H2–H6 headings from HTML content.
     *
     * @return array<array{level:int, text:string}>
     */
    private static function extract_headings( string $html ): array {
        $headings = [];
        if ( preg_match_all( '/<h([2-6])[^>]*>(.*?)<\/h\1>/si', $html, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $m ) {
                $headings[] = [
                    'level' => (int) $m[1],
                    'text'  => trim( wp_strip_all_tags( $m[2] ) ),
                ];
            }
        }
        return $headings;
    }

    /**
     * Extract images from HTML content.
     *
     * @return array<array{src:string, alt:string}>
     */
    private static function extract_images( string $html ): array {
        $images = [];
        if ( preg_match_all( '/<img\b[^>]*>/si', $html, $img_tags ) ) {
            foreach ( $img_tags[0] as $tag ) {
                $src = '';
                $alt = '';
                if ( preg_match( '/\bsrc=["\']([^"\']*)["\']/', $tag, $sm ) ) {
                    $src = $sm[1];
                }
                if ( preg_match( '/\balt=["\']([^"\']*)["\']/', $tag, $am ) ) {
                    $alt = $am[1];
                }
                $images[] = [ 'src' => $src, 'alt' => $alt ];
            }
        }
        return $images;
    }

    /**
     * Extract anchor links from HTML content.
     *
     * @return array<array{href:string, text:string, rel:string}>
     */
    private static function extract_links( string $html ): array {
        $links = [];
        if ( preg_match_all( '/<a\b([^>]*)>(.*?)<\/a>/si', $html, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $m ) {
                $attrs = $m[1];
                $text  = trim( wp_strip_all_tags( $m[2] ) );
                $href  = '';
                $rel   = '';
                if ( preg_match( '/\bhref=["\']([^"\']*)["\']/', $attrs, $hm ) ) {
                    $href = $hm[1];
                }
                if ( preg_match( '/\brel=["\']([^"\']*)["\']/', $attrs, $rm ) ) {
                    $rel = $rm[1];
                }
                if ( $href !== '' && $href !== '#' ) {
                    $links[] = [ 'href' => $href, 'text' => $text, 'rel' => $rel ];
                }
            }
        }
        return $links;
    }

    /**
     * Extract paragraphs as plain-text strings.
     *
     * @return string[]
     */
    private static function extract_paragraphs( string $html ): array {
        $paragraphs = [];
        if ( preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/si', $html, $matches ) ) {
            foreach ( $matches[1] as $p_html ) {
                $text = trim( wp_strip_all_tags( $p_html ) );
                if ( $text !== '' ) {
                    $paragraphs[] = $text;
                }
            }
        }
        return $paragraphs;
    }

    /* ──────────────────────────────────────────────
     * Utility helpers
     * ────────────────────────────────────────────── */

    private static function str_contains_i( string $haystack, string $needle ): bool {
        if ( $needle === '' ) return false;
        return mb_strpos( mb_strtolower( $haystack, 'UTF-8' ), mb_strtolower( $needle, 'UTF-8' ) ) !== false;
    }

    private static function is_internal_url( string $url, string $home ): bool {
        if ( $url === '' ) return false;
        // Relative URLs are internal.
        if ( strpos( $url, '/' ) === 0 && strpos( $url, '//' ) !== 0 ) return true;
        return strpos( $url, $home ) === 0;
    }

    private static function is_absolute_url( string $url ): bool {
        return (bool) preg_match( '#^https?://#i', $url );
    }

    /**
     * Get combined power/sentiment words from TMW data file.
     *
     * @return string[]
     */
    private static function get_power_words(): array {
        static $words = null;
        if ( $words !== null ) {
            return $words;
        }

        $file = TMWSEO_ENGINE_PATH . 'data/snippet-power-words.php';
        if ( ! file_exists( $file ) ) {
            $words = [ 'best', 'top', 'guide', 'review', 'ultimate', 'exclusive', 'free', 'new', 'proven', 'complete' ];
            return $words;
        }

        $data = include $file;
        $words = array_merge(
            $data['sentiments'] ?? [],
            $data['power_words'] ?? []
        );
        // Add generic fallbacks.
        $words = array_merge( $words, [ 'best', 'top', 'guide', 'review', 'how to', 'ultimate', 'free', 'new', 'proven', 'complete' ] );
        $words = array_unique( array_filter( $words ) );
        return $words;
    }

    private static function skip( string $id, string $label, string $fix ): array {
        return [
            'id'       => $id,
            'label'    => $label,
            'status'   => self::STATUS_WARNING,
            'fix'      => $fix,
            'severity' => self::SEV_RECOMMENDED,
        ];
    }

    private static function empty_result( int $post_id, string $post_type ): array {
        return [
            'post_id'       => $post_id,
            'post_type'     => $post_type,
            'focus_keyword' => '',
            'checks'        => [],
            'summary'       => [ 'pass' => 0, 'fail' => 0, 'warning' => 0, 'score_estimate' => 0 ],
        ];
    }
}
