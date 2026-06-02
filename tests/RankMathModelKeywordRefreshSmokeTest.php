<?php
/**
 * Smoke tests for normalized live Rank Math model keyword refresh output.
 */

declare(strict_types=1);

namespace {
    if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', __DIR__ );
    }

    require_once __DIR__ . '/bootstrap/wp-post-stub.php';

    $GLOBALS['_tmw_refresh_meta']   = [];
    $GLOBALS['_tmw_refresh_posts']  = [];
    $GLOBALS['_tmw_refresh_titles'] = [];

    if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $s ) ); } }
    if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); } }
    if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
    if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); } }
    if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $data, $flags = 0, $depth = 512 ) { return json_encode( $data, $flags, $depth ); } }
    if ( ! function_exists( 'get_post_meta' ) ) { function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['_tmw_refresh_meta'][ (int) $id ][ (string) $key ] ?? ''; } }
    if ( ! function_exists( 'update_post_meta' ) ) { function update_post_meta( $id, $key, $value ) { $GLOBALS['_tmw_refresh_meta'][ (int) $id ][ (string) $key ] = $value; return true; } }
    if ( ! function_exists( 'delete_post_meta' ) ) { function delete_post_meta( $id, $key ) { unset( $GLOBALS['_tmw_refresh_meta'][ (int) $id ][ (string) $key ] ); return true; } }
    if ( ! function_exists( 'get_post_field' ) ) { function get_post_field( $field, $id ) { return $GLOBALS['_tmw_refresh_posts'][ (int) $id ]->$field ?? ''; } }
    if ( ! function_exists( 'get_the_title' ) ) { function get_the_title( $id = 0 ) { return $GLOBALS['_tmw_refresh_titles'][ (int) $id ] ?? ( $GLOBALS['_tmw_refresh_posts'][ (int) $id ]->post_title ?? '' ); } }
    if ( ! function_exists( 'get_post' ) ) { function get_post( $id ) { return $GLOBALS['_tmw_refresh_posts'][ (int) $id ] ?? null; } }
    if ( ! function_exists( 'current_time' ) ) { function current_time( $type ) { return '2026-06-02 00:00:00'; } }
    if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
    if ( ! function_exists( 'get_option' ) ) { function get_option( $key, $default = false ) { return $default; } }
    if ( ! function_exists( 'get_object_taxonomies' ) ) { function get_object_taxonomies( $post_type ) { return []; } }
    if ( ! function_exists( 'get_the_terms' ) ) { function get_the_terms( $post, $taxonomy ) { return []; } }
    if ( ! function_exists( 'wp_upload_dir' ) ) { function wp_upload_dir( $time = null, $create_dir = true, $refresh_cache = false ) { return [ 'basedir' => sys_get_temp_dir() ]; } }

    if ( ! class_exists( 'TMWSEO\\Engine\\Logs' ) ) {
        eval( 'namespace TMWSEO\\Engine; class Logs { public static function info($c,$m,$d=[]){} public static function warn($c,$m,$d=[]){} public static function error($c,$m,$d=[]){} public static function debug($c,$m,$d=[]){} }' );
    }
    if ( ! class_exists( 'TMWSEO\\Engine\\Services\\DataForSEO' ) ) {
        eval( 'namespace TMWSEO\\Engine\\Services; class DataForSEO { public static function is_configured(){ return false; } public static function keyword_suggestions($seed,$limit=80){ return ["ok"=>false,"items"=>[]]; } }' );
    }
    if ( ! class_exists( 'TMWSEO\\Engine\\Services\\Settings' ) ) {
        eval( 'namespace TMWSEO\\Engine\\Services; class Settings { public static function get($key,$default=null){ return $default; } }' );
    }

    require_once dirname( __DIR__ ) . '/includes/keywords/class-keyword-library.php';
    require_once dirname( __DIR__ ) . '/includes/keywords/class-page-type-keyword-filter.php';
    require_once dirname( __DIR__ ) . '/includes/keywords/class-model-keyword-pool-classifier.php';
    require_once dirname( __DIR__ ) . '/includes/keywords/class-classified-model-keyword-provider.php';
    require_once dirname( __DIR__ ) . '/includes/model/class-verified-links-families.php';
    require_once dirname( __DIR__ ) . '/includes/model/class-verified-links.php';
    require_once dirname( __DIR__ ) . '/includes/keywords/class-model-keyword-pack.php';
    require_once dirname( __DIR__ ) . '/includes/content/class-audit-trail.php';
    require_once dirname( __DIR__ ) . '/includes/content/class-rank-math-mapper.php';
}

namespace TMWSEO\Engine\Tests {
    use PHPUnit\Framework\TestCase;
    use TMWSEO\Engine\Content\RankMathMapper;
    use TMWSEO\Engine\Model\VerifiedLinks;

    final class RankMathModelKeywordRefreshSmokeTest extends TestCase {
        private const ANISYIA_ID = 91001;

        protected function setUp(): void {
            $GLOBALS['_tmw_refresh_meta']   = [];
            $GLOBALS['_tmw_refresh_posts']  = [];
            $GLOBALS['_tmw_refresh_titles'] = [];
            $GLOBALS['wpdb'] = null;
        }

        public function test_anisyia_old_rank_math_meta_is_replaced_with_normalized_camsoda_pack(): void {
            $this->registerModel( self::ANISYIA_ID, 'Anisyia', [
                [ 'type' => 'camsoda', 'url' => 'https://www.camsoda.com/Anisyia', 'is_active' => true, 'activity_level' => 'active' ],
            ] );
            update_post_meta( self::ANISYIA_ID, 'rank_math_focus_keyword', 'Anisyia,anisyia livejasmin,anisyia live,livejasmin anisyia,Anisyia CamSoda' );

            RankMathMapper::sync_to_rank_math( self::ANISYIA_ID, [ 'primary' => 'stale', 'rankmath_additional' => [ 'anisyia livejasmin' ] ], true );

            $expected = 'Anisyia,Anisyia CamSoda,Anisyia live cam,Anisyia live webcam,Anisyia private live chat';
            $saved = (string) get_post_meta( self::ANISYIA_ID, 'rank_math_focus_keyword', true );
            $this->assertSame( $expected, $saved );
            $this->assertStringNotContainsString( 'anisyia livejasmin', $saved );
            $this->assertStringNotContainsString( 'livejasmin anisyia', $saved );
            $this->assertStringNotContainsString( 'Anisyia LiveJasmin', $saved );
            $this->assertStringNotContainsString( 'anisyia live cam', $saved );
            $this->assertSame( $expected, RankMathMapper::preview_rank_math_csv( self::ANISYIA_ID, [ 'primary' => 'stale' ] ) );
            $this->assertIsArray( get_post_meta( self::ANISYIA_ID, 'tmw_keyword_pack', true ) );
            $this->assertNotEmpty( (string) get_post_meta( self::ANISYIA_ID, '_tmwseo_keyword_pack_json', true ) );
        }

        /** @param array<int,array<string,mixed>> $links */
        private function registerModel( int $postId, string $title, array $links ): void {
            $post = new \WP_Post( [ 'ID' => $postId, 'post_title' => $title, 'post_type' => 'model' ] );
            $GLOBALS['_tmw_refresh_posts'][ $postId ]  = $post;
            $GLOBALS['_tmw_refresh_titles'][ $postId ] = $title;
            update_post_meta( $postId, VerifiedLinks::META_KEY, json_encode( $links ) );
        }
    }
}
