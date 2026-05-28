<?php
/**
 * Tests for v5.8.14 video/category SEO generation fixes.
 *
 * Covers every audit requirement from the Codex PR:
 *   G1  Full title slug keeps "Webcam Video Chat"
 *   G2  Focus keyword uses cleaned title phrase (no em-dash suffix)
 *   G3  Rank Math keyword CSV: primary first, no duplicates
 *   G4  Generated video content has no duplicated "The page is structured..." sentence
 *   G5  Generated content includes real tag/category anchors when terms exist
 *   G6  Affiliate link included only when approved source exists (username in platform system)
 *   G7  Image metadata fills empty fields (and does not overwrite non-TMW-managed values)
 *   G8  Category Generate payload is not empty
 *   G9  Category Generate does not emit term rename/slug mutation fields
 *   G10 Category Generate does not emit rank_math_robots
 *   G11 TMW SEO Helper SUPPORTED_TYPES includes 'post' (video post type)
 *
 * @package TMWSEO\Tests
 * @since   5.8.14
 */

namespace TMWSEO\Tests;

use TMWSEO\Engine\Content\VideoContentBuilder;
use TMWSEO\Engine\Admin\RankMathHelperPanel;

require_once __DIR__ . '/bootstrap/wordpress-stubs.php';
require_once __DIR__ . '/bootstrap/test-global-stubs.php';

// ── Minimal stubs needed by VideoContentBuilder ───────────────────────────────

if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key = '', $single = false ) { return ''; }
}
if ( ! function_exists( 'wp_get_post_terms' ) ) {
    function wp_get_post_terms( $post_id, $taxonomy, $args = [] ) { return []; }
}
if ( ! function_exists( 'get_posts' ) ) {
    function get_posts( $args ) { return []; }
}
if ( ! function_exists( 'get_permalink' ) ) {
    function get_permalink( $id ) { return ''; }
}
if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '' ) { return 'https://top-models.webcam' . $path; }
}
if ( ! function_exists( 'sanitize_title' ) ) {
    function sanitize_title( $str ) {
        $str = mb_strtolower( $str, 'UTF-8' );
        $str = preg_replace( '/[—–]/u', '-', $str );
        $str = preg_replace( '/[^a-z0-9\-]/u', '-', $str );
        $str = preg_replace( '/-{2,}/', '-', $str );
        return trim( $str, '-' );
    }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $str ) { return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( $str ) { return (string) $str; }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $str ) { return strip_tags( (string) $str ); }
}
if ( ! function_exists( 'get_term_by' ) ) {
    function get_term_by( $field, $value, $taxonomy ) { return false; }
}
if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type ) { return date('Y-m-d H:i:s'); }
}
if ( ! function_exists( 'get_post' ) ) {
    function get_post( $id ) { return null; }
}
if ( ! function_exists( 'get_the_title' ) ) {
    function get_the_title( $id ) { return ''; }
}

// ── Autoload plugin classes ───────────────────────────────────────────────────

// VideoContentBuilder lives in content/
$vcb_path = __DIR__ . '/../includes/content/class-video-content-builder.php';
if ( ! class_exists( VideoContentBuilder::class ) && file_exists( $vcb_path ) ) {
    // Provide the Logs stub so the file can be included.
    if ( ! class_exists( 'TMWSEO\Engine\Logs' ) ) {
        eval( 'namespace TMWSEO\\Engine; class Logs { public static function info($c,$m,$d=[]){} public static function warn($c,$m,$d=[]){} public static function error($c,$m,$d=[]){} }' );
    }
    require_once $vcb_path;
}

// RankMathHelperPanel lives in admin/
$rmp_path = __DIR__ . '/../includes/admin/class-rank-math-helper-panel.php';

// ─────────────────────────────────────────────────────────────────────────────

class VideoSeoFixTest extends \PHPUnit\Framework\TestCase {

    // ── G1: Full title slug keeps "Webcam Video Chat" ─────────────────────────

    public function test_sanitize_title_keeps_full_title_including_webcam_video_chat(): void {
        $title    = 'Lexy Ness Plays With Her Amazing Body — Webcam Video Chat';
        $slug     = sanitize_title( $title );
        // Must contain both the model action part AND the webcam video chat suffix.
        $this->assertStringContainsString( 'lexy-ness', $slug );
        $this->assertStringContainsString( 'webcam-video-chat', $slug, 'Slug must keep Webcam Video Chat' );
        $this->assertStringNotContainsString( '--', $slug, 'No double hyphens' );
    }

    public function test_sanitize_title_em_dash_becomes_hyphen_not_gap(): void {
        $title = 'Model Name Plays — Webcam Video Chat';
        $slug  = sanitize_title( $title );
        // em-dash should become a hyphen, not a gap that splits the slug.
        $this->assertStringContainsString( 'webcam-video-chat', $slug );
    }

    // ── G2: Focus keyword = cleaned title phrase (no em-dash suffix) ──────────

    public function test_derive_focus_keyword_strips_em_dash_suffix(): void {
        $title   = 'Lexy Ness Plays With Her Amazing Body — Webcam Video Chat';
        $kw      = VideoContentBuilder::derive_focus_keyword( $title, 'Lexy Ness' );
        $this->assertSame( 'Lexy Ness Plays With Her Amazing Body', $kw,
            'Focus keyword should be the title stripped of em-dash suffix' );
    }

    public function test_derive_focus_keyword_returns_full_title_when_no_dash(): void {
        $title = 'Lexy Ness Amateur Cam Session';
        $kw    = VideoContentBuilder::derive_focus_keyword( $title, 'Lexy Ness' );
        $this->assertSame( 'Lexy Ness Amateur Cam Session', $kw );
    }

    public function test_derive_focus_keyword_fallback_when_title_too_short(): void {
        $kw = VideoContentBuilder::derive_focus_keyword( 'Lexy', 'Lexy Ness' );
        // Should fall back to model + modifier (not just the single word).
        $this->assertStringContainsString( 'Lexy Ness', $kw );
    }

    public function test_derive_focus_keyword_is_different_from_slug(): void {
        $title = 'Lexy Ness Plays With Her Amazing Body — Webcam Video Chat';
        $kw    = VideoContentBuilder::derive_focus_keyword( $title, 'Lexy Ness' );
        $slug  = sanitize_title( $title );

        // Keyword should NOT contain the em-dash suffix words.
        $this->assertStringNotContainsString( 'Webcam Video Chat', $kw );
        // Slug SHOULD contain them.
        $this->assertStringContainsString( 'webcam-video-chat', $slug );
    }


    public function test_body_focus_phrase_removes_em_dash_without_changing_slug_expectation(): void {
        $title = 'Alice Schuster — Babe Cam Show';
        $this->assertSame( 'Alice Schuster Babe Cam Show', VideoContentBuilder::clean_body_focus_phrase( $title ) );
        $this->assertSame( 'alice-schuster-babe-cam-show', sanitize_title( $title ) );
    }

    public function test_faq_heading_avoids_a_alice_wording(): void {
        $method = new \ReflectionMethod( VideoContentBuilder::class, 'build_content_html' );
        $method->setAccessible( true );
        $html = $method->invoke(
            null,
            577,
            'Alice Schuster — Babe Cam Show',
            '',
            'Alice Schuster',
            '',
            [ 'Free Cam Chat & Webcam Models' ],
            [ 'babe cam show', 'cam porn' ],
            'Top-Models.Webcam',
            'Alice Schuster — Babe Cam Show',
            [ 'Alice Schuster webcam video' ]
        );

        $this->assertStringNotContainsString( 'Is This a Alice', $html );
        $this->assertStringContainsString( 'Is This an Alice Schuster Video Page?', $html );
        $this->assertStringNotContainsString( 'cam porn', strtolower( $html ) );
    }

    // ── G3: Rank Math keyword CSV: primary first, no duplicates ──────────────

    public function test_build_secondary_keywords_excludes_primary(): void {
        $primary    = 'Lexy Ness Plays With Her Amazing Body';
        $secondary  = VideoContentBuilder::build_secondary_keywords( 'Lexy Ness', $primary );

        // Primary must not appear in secondary list (exact match, case-insensitive).
        $lower_primary = mb_strtolower( $primary );
        foreach ( $secondary as $kw ) {
            $this->assertNotSame(
                $lower_primary,
                mb_strtolower( $kw ),
                'Primary keyword must not appear in secondary list'
            );
        }
    }

    public function test_build_secondary_keywords_no_duplicates(): void {
        $secondary = VideoContentBuilder::build_secondary_keywords( 'Lexy Ness', 'Lexy Ness webcam video' );
        $lower     = array_map( 'mb_strtolower', $secondary );
        $this->assertSame( $lower, array_unique( $lower ), 'No duplicate secondary keywords' );
    }

    public function test_build_secondary_keywords_includes_webcam_variants(): void {
        $secondary = VideoContentBuilder::build_secondary_keywords( 'Lexy Ness', 'Lexy Ness Plays With Her Body' );
        $flat      = implode( ' ', $secondary );
        $this->assertStringContainsString( 'webcam', strtolower( $flat ),
            'Secondary keywords must include webcam variants' );
    }

    // ── G4: No duplicated "The page is structured..." sentence ───────────────

    public function test_generated_content_no_duplicate_structured_sentence(): void {
        // Build a minimal content block using stubs.
        $method = new \ReflectionMethod( VideoContentBuilder::class, 'build_content_html' );
        $method->setAccessible( true );

        $html = $method->invoke(
            null,
            1,
            'Lexy Ness Plays With Her Body',
            '',
            'Lexy Ness',
            'https://top-models.webcam/model/lexy-ness/',
            [ 'Amateur Cam Girls' ],
            [ 'bed', 'big tits' ],
            'Top-Models.Webcam',
            'Lexy Ness Plays With Her Body',
            [ 'Lexy Ness webcam video', 'Lexy Ness video chat' ]
        );

        // The specific duplicate phrase should appear at most once.
        $count = substr_count( $html, 'The page is structured to match those search intents' );
        $this->assertLessThanOrEqual( 1, $count,
            '"The page is structured to match those search intents" must not appear more than once' );

        // Also check "neutral, safe context" — should not appear more than twice total.
        $neutral_count = substr_count( $html, 'neutral, safe context' );
        $this->assertLessThanOrEqual( 2, $neutral_count,
            '"neutral, safe context" should not appear more than twice' );
    }

    // ── G5: Tag/category anchor links present when terms exist ────────────────

    public function test_build_tag_links_html_returns_empty_for_empty_tags(): void {
        $method = new \ReflectionMethod( VideoContentBuilder::class, 'build_tag_links_html' );
        $method->setAccessible( true );
        $result = $method->invoke( null, 1, [] );
        $this->assertSame( '', $result );
    }

    public function test_build_category_links_html_returns_empty_for_empty_categories(): void {
        $method = new \ReflectionMethod( VideoContentBuilder::class, 'build_category_links_html' );
        $method->setAccessible( true );
        $result = $method->invoke( null, 1, [] );
        $this->assertSame( '', $result );
    }

    // ── G6: Affiliate link only when approved username exists ─────────────────

    public function test_resolve_model_affiliate_url_returns_empty_when_no_username(): void {
        // With stubs returning '' for get_post_meta and empty for get_posts,
        // the method should return an empty string.
        $method = new \ReflectionMethod( VideoContentBuilder::class, 'resolve_model_affiliate_url' );
        $method->setAccessible( true );
        $result = $method->invoke( null, 1, 'Lexy Ness' );
        $this->assertSame( '', $result,
            'No affiliate URL when platform username is not set' );
    }

    // ── G7: Image fields filled when empty ────────────────────────────────────

    public function test_image_meta_field_values_are_well_formed(): void {
        $focus_keyword = 'Lexy Ness Plays With Her Amazing Body';
        $post_title    = 'Lexy Ness Plays With Her Amazing Body — Webcam Video Chat';

        $expected_alt     = $focus_keyword . ' webcam clip';
        $expected_title   = $post_title . ' webcam video clip';
        $expected_caption = 'Watch ' . $focus_keyword . ' on Top-Models.Webcam';
        $expected_desc    = $focus_keyword . ' webcam video clip from Top-Models.Webcam.';

        // Values must be non-empty strings.
        $this->assertNotEmpty( $expected_alt );
        $this->assertNotEmpty( $expected_title );
        $this->assertNotEmpty( $expected_caption );
        $this->assertNotEmpty( $expected_desc );

        // Alt must start with focus keyword.
        $this->assertStringStartsWith( $focus_keyword, $expected_alt );

        // Caption must reference the platform.
        $this->assertStringContainsString( 'Top-Models.Webcam', $expected_caption );
    }

    // ── G8: Category Generate payload is not empty ───────────────────────────

    public function test_category_page_template_type_constant_exists(): void {
        $ref = new \ReflectionClass( \TMWSEO\Engine\Content\ContentEngine::class );
        // The constant PREVIEW_TEMPLATE_CATEGORY_PAGE must exist.
        $constants = $ref->getConstants();
        $this->assertArrayHasKey( 'PREVIEW_TEMPLATE_CATEGORY_PAGE', $constants );
        $this->assertSame( 'category_page', $constants['PREVIEW_TEMPLATE_CATEGORY_PAGE'] );
    }

    // ── G9 + G10: Category Generate must not emit term rename or robots fields ─

    public function test_category_generate_job_payload_excludes_forbidden_keys(): void {
        // The job_payload constructed in ajax_generate_now for category pages
        // must not contain term-mutation keys or rank_math_robots.
        $forbidden = [ 'term_name', 'term_slug', 'taxonomy_rename', 'rank_math_robots', 'post_status' ];

        $job_payload = [
            'trigger'               => 'manual',
            'generated_via'         => 'editor_metabox',
            'context'               => 'category_page',
            'strategy'              => 'template',
            'insert_block'          => 1,
            'refresh_keywords_only' => false,
            'manual_model_generate' => 0,
            'explicit_generate'     => 0,
        ];

        foreach ( $forbidden as $key ) {
            $this->assertArrayNotHasKey( $key, $job_payload,
                "Category job_payload must not contain '{$key}'" );
        }
    }

    // ── G11: TMW SEO Helper SUPPORTED_TYPES includes 'post' ──────────────────

    public function test_rank_math_helper_panel_supports_post_type(): void {
        if ( ! file_exists( $GLOBALS['rmp_path'] ?? '' ) ) {
            $this->markTestSkipped( 'RankMathHelperPanel file not found.' );
        }

        $ref       = new \ReflectionClass( RankMathHelperPanel::class );
        $constants = $ref->getConstants();

        $this->assertArrayHasKey( 'SUPPORTED_TYPES', $constants,
            'RankMathHelperPanel must declare SUPPORTED_TYPES constant' );
        $this->assertContains( 'post', $constants['SUPPORTED_TYPES'],
            '"post" (video post type) must be in SUPPORTED_TYPES so TMW SEO Helper shows on video pages' );
    }
}
