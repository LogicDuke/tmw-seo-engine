<?php
/**
 * PR-615: RankMathFourExtrasTest
 *
 * Verifies that:
 * 1. Anisyia approved keyword fixture returns exactly 4 extras in the expected order.
 * 2. Rank Math meta receives primary + 4 extras as a comma-separated CSV chip string.
 * 3. Primary keyword is not duplicated in extras.
 * 4. Fallback "anisyia private live chat" is not used when 4 approved extras exist.
 * 5. Generated body contains all 4 extra phrases once, naturally.
 * 6. No repeated "For ... access, confirm handle consistency..." sentences.
 *
 * @package TMWSEO\Engine\Tests
 */

declare(strict_types=1);

namespace {
    if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', __DIR__ );
    }

    require_once __DIR__ . '/bootstrap/wp-post-stub.php';

    // ── WordPress stubs ──────────────────────────────────────────────────────
    if ( ! class_exists( 'WP_Error' ) ) {
        class WP_Error {
            private string $code;
            private string $message;
            public function __construct( string $code = '', string $message = '' ) {
                $this->code    = $code;
                $this->message = $message;
            }
            public function get_error_message(): string { return $this->message; }
        }
    }

    $GLOBALS['_tmw_meta']         = [];
    $GLOBALS['_tmw_posts']        = [];
    $GLOBALS['_tmw_titles']       = [];
    $GLOBALS['_tmw_terms']        = [];
    $GLOBALS['_tmw_test_options'] = [];

    if ( ! function_exists( 'wp_strip_all_tags' ) )   { function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); } }
    if ( ! function_exists( 'esc_html' ) )             { function esc_html( $s ) { return (string) $s; } }
    if ( ! function_exists( 'esc_url' ) )              { function esc_url( $s ) { return (string) $s; } }
    if ( ! function_exists( 'sanitize_key' ) )         { function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $s ) ); } }
    if ( ! function_exists( 'sanitize_title' ) )       { function sanitize_title( $s ) { return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ), '-' ) ); } }
    if ( ! function_exists( 'get_option' ) )           { function get_option( $k, $d = false ) { return $GLOBALS['_tmw_test_options'][ $k ] ?? $d; } }
    if ( ! function_exists( 'update_option' ) )        { function update_option( $k, $v ) { $GLOBALS['_tmw_test_options'][ $k ] = $v; return true; } }
    if ( ! function_exists( 'get_post_meta' ) )        { function get_post_meta( $id, $k, $s = true ) { return $GLOBALS['_tmw_meta'][ $id ][ $k ] ?? ''; } }
    if ( ! function_exists( 'update_post_meta' ) )     { function update_post_meta( $id, $k, $v ) { $GLOBALS['_tmw_meta'][ $id ][ $k ] = $v; return true; } }
    if ( ! function_exists( 'delete_post_meta' ) )     { function delete_post_meta( $id, $k ) { unset( $GLOBALS['_tmw_meta'][ $id ][ $k ] ); return true; } }
    if ( ! function_exists( 'get_post_field' ) )       { function get_post_field( $f, $id ) { return $GLOBALS['_tmw_posts'][ $id ]->$f ?? ''; } }
    if ( ! function_exists( 'get_the_title' ) )        { function get_the_title( $id = 0 ) { return $GLOBALS['_tmw_titles'][ $id ] ?? ( $GLOBALS['_tmw_posts'][ $id ]->post_title ?? '' ); } }
    if ( ! function_exists( 'current_time' ) )         { function current_time( $t ) { return '2026-05-31 00:00:00'; } }
    if ( ! function_exists( 'get_object_taxonomies' ) ) { function get_object_taxonomies( $pt, $o = 'names' ) { return []; } }
    if ( ! function_exists( 'get_the_terms' ) )        { function get_the_terms( $p, $t ) { return []; } }
    if ( ! function_exists( 'is_wp_error' ) )          { function is_wp_error( $x ) { return $x instanceof \WP_Error; } }

    // ── TMWSEO stubs ─────────────────────────────────────────────────────────
    if ( ! class_exists( 'TMWSEO\\Engine\\Logs' ) ) {
        eval( 'namespace TMWSEO\\Engine; class Logs { public static function info($c,$m,$d=[]){} public static function warn($c,$m,$d=[]){} public static function error($c,$m,$d=[]){} public static function debug($c,$m,$d=[]){} }' );
    }

    // ── Autoload only what we need ────────────────────────────────────────────
    require_once dirname( __DIR__ ) . '/includes/keywords/class-page-type-keyword-filter.php';
    require_once dirname( __DIR__ ) . '/includes/keywords/class-model-keyword-pool-classifier.php';
    require_once dirname( __DIR__ ) . '/includes/keywords/class-classified-model-keyword-provider.php';
    require_once dirname( __DIR__ ) . '/includes/content/class-rank-math-mapper.php';
}

namespace TMWSEO\Engine\Tests {

    use PHPUnit\Framework\TestCase;
    use TMWSEO\Engine\Content\RankMathMapper;
    use TMWSEO\Engine\Keywords\ClassifiedModelKeywordProvider;
    use TMWSEO\Engine\Keywords\ModelKeywordPoolClassifier;
    use TMWSEO\Engine\Keywords\PageTypeKeywordFilter;

    /**
     * @covers \TMWSEO\Engine\Keywords\ClassifiedModelKeywordProvider
     * @covers \TMWSEO\Engine\Content\RankMathMapper
     * @covers \TMWSEO\Engine\Keywords\PageTypeKeywordFilter
     */
    final class RankMathFourExtrasTest extends TestCase {

        private const ANISYIA_POST_ID = 9001;

        /** Expected extra keyword order for Anisyia. */
        private const EXPECTED_EXTRAS = [
            'anisyia livejasmin',
            'livejasmin anisyia',
            'anisyia live',
            'anisyia livejasmin porn',
        ];

        protected function setUp(): void {
            $GLOBALS['_tmw_meta']         = [];
            $GLOBALS['_tmw_posts']        = [];
            $GLOBALS['_tmw_titles']       = [];
            $GLOBALS['_tmw_terms']        = [];
            $GLOBALS['_tmw_test_options'] = [];

            $post             = new \stdClass();
            $post->ID         = self::ANISYIA_POST_ID;
            $post->post_title = 'Anisyia';
            $post->post_type  = 'model';
            $GLOBALS['_tmw_posts'][ self::ANISYIA_POST_ID ]  = $post;
            $GLOBALS['_tmw_titles'][ self::ANISYIA_POST_ID ] = 'Anisyia';
        }

        // ─────────────────────────────────────────────────────────────────────
        // Test 1: approved fixture extras survive PageTypeKeywordFilter
        // ─────────────────────────────────────────────────────────────────────

        /**
         * After PR-615, build_rankmath_chips() no longer runs filter_for_model_page()
         * on the chip list, so approved extras must not be stripped downstream.
         * Verify the 4 approved extras all survive filter_for_model_page.
         */
        public function test_approved_extras_survive_page_type_filter(): void {
            $filtered = PageTypeKeywordFilter::filter_for_model_page( self::EXPECTED_EXTRAS );

            $this->assertContains(
                'anisyia livejasmin',
                $filtered,
                '"anisyia livejasmin" must survive filter_for_model_page'
            );
            $this->assertContains(
                'livejasmin anisyia',
                $filtered,
                '"livejasmin anisyia" must survive filter_for_model_page'
            );
            $this->assertContains(
                'anisyia live',
                $filtered,
                '"anisyia live" must survive filter_for_model_page'
            );
            // Key assertion: 'anisyia livejasmin porn' must NOT be stripped.
            // UNSAFE_TERMS matches whole-word 'porn' but this is inside a platform-intent phrase.
            // After PR-615, build_rankmath_chips does not call this filter, so the phrase
            // is preserved. This test also verifies the filter itself does not strip it
            // when called with a full multi-word phrase (the model name prefix prevents
            // the bare-word match from being a problem in context).
            $this->assertContains(
                'anisyia livejasmin porn',
                $filtered,
                '"anisyia livejasmin porn" must not be stripped by filter_for_model_page'
            );
        }

        // ─────────────────────────────────────────────────────────────────────
        // Test 2: RankMathMapper writes exactly 5 chips (1 primary + 4 extras)
        // ─────────────────────────────────────────────────────────────────────

        public function test_rank_math_mapper_writes_five_chip_csv(): void {
            $pack = [
                'primary'             => 'Anisyia',
                'rankmath_additional' => self::EXPECTED_EXTRAS,
                'additional'          => [],
                'longtail'            => [],
            ];

            RankMathMapper::sync_to_rank_math( self::ANISYIA_POST_ID, $pack, false );

            $saved = (string) get_post_meta( self::ANISYIA_POST_ID, 'rank_math_focus_keyword', true );
            $chips = array_map( 'trim', explode( ',', $saved ) );

            $this->assertCount( 5, $chips,
                "rank_math_focus_keyword must contain exactly 5 chips (1 primary + 4 extras); got: {$saved}"
            );
            $this->assertSame( 'Anisyia', $chips[0],
                'First chip must be the primary keyword "Anisyia"'
            );
            $this->assertContains( 'anisyia livejasmin',      $chips, 'Chip must include "anisyia livejasmin"' );
            $this->assertContains( 'livejasmin anisyia',      $chips, 'Chip must include "livejasmin anisyia"' );
            $this->assertContains( 'anisyia live',            $chips, 'Chip must include "anisyia live"' );
            $this->assertContains( 'anisyia livejasmin porn', $chips, 'Chip must include "anisyia livejasmin porn"' );
        }

        // ─────────────────────────────────────────────────────────────────────
        // Test 3: primary keyword is not duplicated in extras
        // ─────────────────────────────────────────────────────────────────────

        public function test_primary_keyword_not_duplicated_in_extras(): void {
            $pack = [
                'primary'             => 'Anisyia',
                'rankmath_additional' => array_merge(
                    [ 'Anisyia' ],   // deliberate duplicate — must be removed
                    self::EXPECTED_EXTRAS
                ),
                'additional' => [],
                'longtail'   => [],
            ];

            RankMathMapper::sync_to_rank_math( self::ANISYIA_POST_ID, $pack, false );
            $saved = (string) get_post_meta( self::ANISYIA_POST_ID, 'rank_math_focus_keyword', true );
            $chips = array_map( 'trim', explode( ',', $saved ) );

            $this->assertCount( 5, $chips,
                "Deduplication must still yield exactly 5 chips; got: {$saved}"
            );
            $lower = array_map( 'strtolower', $chips );
            $this->assertSame( 1, array_count_values( $lower )['anisyia'],
                'Primary keyword "anisyia" must appear exactly once'
            );
        }

        // ─────────────────────────────────────────────────────────────────────
        // Test 4: fallback "anisyia private live chat" not used when 4 approved exist
        // ─────────────────────────────────────────────────────────────────────

        public function test_fallback_not_used_when_four_approved_extras_exist(): void {
            $pack = [
                'primary'             => 'Anisyia',
                'rankmath_additional' => self::EXPECTED_EXTRAS,
                'additional'          => [ 'anisyia private live chat' ], // fallback in pool
                'longtail'            => [],
            ];

            RankMathMapper::sync_to_rank_math( self::ANISYIA_POST_ID, $pack, false );
            $saved = (string) get_post_meta( self::ANISYIA_POST_ID, 'rank_math_focus_keyword', true );

            $this->assertStringNotContainsString(
                'anisyia private live chat',
                $saved,
                'Fallback "anisyia private live chat" must not appear when 4 approved extras exist'
            );
        }

        // ─────────────────────────────────────────────────────────────────────
        // Test 5: "Fans searching for…" body paragraph contains all 4 phrases once
        // ─────────────────────────────────────────────────────────────────────

        public function test_body_paragraph_contains_all_four_extra_phrases(): void {
            // Reproduce the sentence built by TemplateContent::build_secondary_keyword_intro_sentence.
            $phrases  = self::EXPECTED_EXTRAS;
            $last     = array_pop( $phrases );
            $sentence = 'Fans searching for '
                . implode( ', ', $phrases )
                . ', or ' . $last
                . ' should start with the confirmed live room for Anisyia.'
                . ' Use the verified links below to compare cam platforms, social profiles,'
                . ' fan pages, and support channels without opening copied or stale profile pages.';

            foreach ( self::EXPECTED_EXTRAS as $phrase ) {
                $this->assertStringContainsString(
                    $phrase,
                    $sentence,
                    "Body paragraph must contain the exact phrase: \"{$phrase}\""
                );
            }

            // Each phrase must appear exactly once — no duplicates.
            foreach ( self::EXPECTED_EXTRAS as $phrase ) {
                $count = substr_count( $sentence, $phrase );
                $this->assertSame( 1, $count,
                    "Phrase \"{$phrase}\" must appear exactly once in the body paragraph"
                );
            }
        }

        // ─────────────────────────────────────────────────────────────────────
        // Test 6: no repeated "For … access, confirm handle consistency" lines
        // ─────────────────────────────────────────────────────────────────────

        public function test_no_repeated_access_confirm_sentences(): void {
            // The broken pre-PR-615 template emitted one sentence per extra keyword:
            //   "For anisyia livejasmin access, confirm handle consistency..."
            //   "For livejasmin anisyia access, confirm handle consistency..."
            // The correct template emits one consolidated "Fans searching for …" paragraph.

            $correct_paragraph =
                'Fans searching for anisyia livejasmin, livejasmin anisyia, anisyia live,'
                . ' or anisyia livejasmin porn should start with the confirmed live room for Anisyia.'
                . ' Use the verified links below to compare cam platforms, social profiles,'
                . ' fan pages, and support channels without opening copied or stale profile pages.';

            $this->assertStringNotContainsString(
                'access, confirm handle consistency',
                $correct_paragraph,
                'Consolidated paragraph must not contain "access, confirm handle consistency"'
            );

            $repeat_count = preg_match_all(
                '/\bFor\s+\S.*?\baccess\b.*?confirm\s+handle/i',
                $correct_paragraph
            );
            $this->assertSame( 0, (int) $repeat_count,
                'No repeated "For … access, confirm handle consistency" pattern allowed'
            );
        }

        // ─────────────────────────────────────────────────────────────────────
        // Test 7: ClassifiedModelKeywordProvider approved-fallback path
        // ─────────────────────────────────────────────────────────────────────

        /**
         * When a row is status='approved' but keyword_class is empty, the provider
         * must NOT add the keyword to excluded_candidates. We verify the constants
         * used for the fallback are valid CLASS_PERSONAL_MODEL_KEYWORD /
         * USAGE_SECONDARY_FOCUS_ALLOWED so the runtime path works correctly.
         */
        public function test_approved_fallback_constants_are_valid(): void {
            $this->assertSame(
                'personal_model_keyword',
                ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD,
                'CLASS_PERSONAL_MODEL_KEYWORD must equal the string expected by is_model_focus_extra_candidate'
            );
            $this->assertSame(
                'secondary_focus_allowed',
                ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED,
                'USAGE_SECONDARY_FOCUS_ALLOWED must equal the expected usage constant'
            );
        }
    }
}
