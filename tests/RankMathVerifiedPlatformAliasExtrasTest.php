<?php
/**
 * Verifies Rank Math model extras use active verified cam links and only saved aliases.
 */

declare(strict_types=1);

namespace {
    if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', __DIR__ );
    }

    $GLOBALS['_tmw_rm_alias_meta'] = [];

    require_once __DIR__ . '/bootstrap/wp-post-stub.php';

    if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $s ) ); } }
    if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); } }
    if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
    if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); } }
    if ( ! function_exists( 'get_post_meta' ) ) { function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['_tmw_rm_alias_meta'][ (int) $id ][ (string) $key ] ?? ''; } }
    if ( ! function_exists( 'wp_upload_dir' ) ) { function wp_upload_dir( $time = null, $create_dir = true, $refresh_cache = false ) { return [ 'basedir' => sys_get_temp_dir() ]; } }
    if ( ! function_exists( 'current_time' ) ) { function current_time( $type ) { return '2026-06-02 00:00:00'; } }
    if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
    if ( ! function_exists( 'get_option' ) ) { function get_option( $key, $default = false ) { return $default; } }
    if ( ! function_exists( 'get_object_taxonomies' ) ) { function get_object_taxonomies( $post_type ) { return []; } }
    if ( ! function_exists( 'get_the_terms' ) ) { function get_the_terms( $post, $taxonomy ) { return []; } }

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
}

namespace TMWSEO\Engine\Tests {

    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use TMWSEO\Engine\Keywords\ModelKeywordPack;
    use TMWSEO\Engine\Model\VerifiedLinks;

    final class RankMathVerifiedPlatformAliasExtrasTest extends TestCase {
        private const POST_ID = 77001;

        protected function setUp(): void {
            $GLOBALS['_tmw_rm_alias_meta'] = [];

    require_once __DIR__ . '/bootstrap/wp-post-stub.php';
            $GLOBALS['wpdb'] = null;
        }

        public function test_active_livejasmin_and_very_active_stripchat_use_verified_saved_alias(): void {
            $this->setAliases( 'OhhAisha' );
            $this->setVerifiedLinks( [
                [ 'type' => 'livejasmin', 'url' => 'https://www.livejasmin.com/en/free/chat/AishaDupont', 'is_active' => true, 'activity_level' => 'active' ],
                [ 'type' => 'stripchat', 'url' => 'https://nl.stripchat.com/OhhAisha', 'is_active' => true, 'activity_level' => 'very_active' ],
            ] );

            $extras = $this->selectExtras( 'Aisha Dupont' );

            $this->assertContains( 'Aisha Dupont LiveJasmin', $extras );
            $this->assertContains( 'OhhAisha Stripchat', $extras );
            $this->assertLessThanOrEqual( 4, count( $extras ) );
        }

        public function test_inactive_chaturbate_is_excluded_even_when_active_checkbox_checked(): void {
            $this->setAliases( 'OhhAisha' );
            $this->setVerifiedLinks( [
                [ 'type' => 'chaturbate', 'url' => 'https://chaturbate.com/ohhaisha/', 'is_active' => true, 'activity_level' => 'inactive' ],
            ] );

            $extras = $this->selectExtras( 'Aisha Dupont' );

            $this->assertNotContains( 'OhhAisha Chaturbate', $extras );
            $this->assertNotContains( 'Aisha Dupont Chaturbate', $extras );
        }

        public function test_active_chaturbate_can_use_matching_saved_alias(): void {
            $this->setAliases( 'OhhAisha' );
            $this->setVerifiedLinks( [
                [ 'type' => 'chaturbate', 'url' => 'https://chaturbate.com/ohhaisha/', 'is_active' => true, 'activity_level' => 'active' ],
            ] );

            $extras = $this->selectExtras( 'Aisha Dupont' );

            $this->assertContains( 'OhhAisha Chaturbate', $extras );
        }

        public function test_social_and_bio_links_are_excluded_from_platform_extras(): void {
            $this->setAliases( 'OhhAisha' );
            $this->setVerifiedLinks( [
                [ 'type' => 'instagram', 'url' => 'https://instagram.com/OhhAisha', 'is_active' => true, 'activity_level' => 'active' ],
                [ 'type' => 'x', 'url' => 'https://x.com/OhhAisha', 'is_active' => true, 'activity_level' => 'active' ],
                [ 'type' => 'friendsbio', 'url' => 'https://friends.bio/OhhAisha', 'is_active' => true, 'activity_level' => 'active' ],
            ] );

            $extras = $this->selectExtras( 'Aisha Dupont' );
            $csv = implode( ',', $extras );

            $this->assertStringNotContainsString( 'instagram', $csv );
            $this->assertStringNotContainsString( ' x', $csv );
            $this->assertStringNotContainsString( 'twitter', $csv );
            $this->assertStringNotContainsString( 'friendsbio', $csv );
        }

        public function test_approved_personal_keyword_still_precedes_platform_keywords(): void {
            $this->setAliases( 'OhhAisha' );
            $this->setVerifiedLinks( [
                [ 'type' => 'stripchat', 'url' => 'https://nl.stripchat.com/OhhAisha', 'is_active' => true, 'activity_level' => 'very_active' ],
            ] );

            $extras = $this->selectExtras( 'Aisha Dupont', [ 'Aisha Dupont official bio' ] );

            $this->assertSame( 'Aisha Dupont official bio', $extras[0] );
            $this->assertContains( 'OhhAisha Stripchat', $extras );
            $this->assertLessThanOrEqual( 4, count( $extras ) );
        }

        public function test_url_slug_is_not_invented_as_alias_without_saved_alias(): void {
            $this->setAliases( '' );
            $this->setVerifiedLinks( [
                [ 'type' => 'stripchat', 'url' => 'https://nl.stripchat.com/OhhAisha', 'is_active' => true, 'activity_level' => 'very_active' ],
            ] );

            $extras = $this->selectExtras( 'Aisha Dupont' );

            $this->assertNotContains( 'OhhAisha Stripchat', $extras );
            $this->assertContains( 'Aisha Dupont Stripchat', $extras );
        }

        public function test_legacy_platform_primary_is_only_fallback_when_no_eligible_verified_cam_exists(): void {
            $this->setAliases( '' );
            $this->setVerifiedLinks( [] );
            $GLOBALS['_tmw_rm_alias_meta'][ self::POST_ID ]['_tmwseo_platform_primary'] = 'livejasmin';

            $extras = $this->selectExtras( 'Aisha Dupont' );
            $this->assertContains( 'Aisha Dupont LiveJasmin', $extras );

            $this->setVerifiedLinks( [
                [ 'type' => 'stripchat', 'url' => 'https://nl.stripchat.com/OhhAisha', 'is_active' => true, 'activity_level' => 'active' ],
            ] );
            $extras = $this->selectExtras( 'Aisha Dupont' );
            $this->assertContains( 'Aisha Dupont Stripchat', $extras );
            $this->assertNotContains( 'Aisha Dupont LiveJasmin', $extras );
        }


        public function test_single_platform_livejasmin_model_uses_cased_platform_and_live_cam_intent(): void {
            $this->setAliases( '' );
            $this->setVerifiedLinks( [
                [ 'type' => 'livejasmin', 'url' => 'https://www.livejasmin.com/en/free/chat/AbbyMurray', 'is_active' => true, 'activity_level' => 'active' ],
            ] );

            $extras = $this->selectExtras( 'Abby Murray' );

            $this->assertContains( 'Abby Murray LiveJasmin', $extras );
            $this->assertContains( 'Abby Murray live cam', $extras );
            $this->assertNoDuplicateChips( $extras );
            $this->assertLessThanOrEqual( 4, count( $extras ) );
            foreach ( $extras as $extra ) {
                $this->assertStringNotContainsString( 'abby murray', $extra );
            }
        }

        public function test_single_platform_camsoda_model_uses_cased_platform_and_intent_mix(): void {
            $this->setAliases( '' );
            $this->setVerifiedLinks( [
                [ 'type' => 'camsoda', 'url' => 'https://www.camsoda.com/Anisyia', 'is_active' => true, 'activity_level' => 'active' ],
            ] );

            $extras = $this->selectExtras( 'Anisyia' );

            $this->assertContains( 'Anisyia CamSoda', $extras );
            $this->assertTrue(
                in_array( 'Anisyia live cam', $extras, true )
                || in_array( 'Anisyia live webcam', $extras, true )
                || in_array( 'Anisyia private live chat', $extras, true )
                || in_array( 'Anisyia HD live stream', $extras, true ),
                'Single-platform CamSoda extras should include a useful live-cam/tag-intent keyword.'
            );
            $this->assertNoDuplicateChips( $extras );
            $this->assertLessThanOrEqual( 4, count( $extras ) );
            foreach ( $extras as $extra ) {
                $this->assertStringNotContainsString( 'anisyia', $extra );
            }
        }

        public function test_multi_platform_model_preserves_platform_and_model_casing(): void {
            $this->setAliases( '' );
            $this->setVerifiedLinks( [
                [ 'type' => 'stripchat', 'url' => 'https://stripchat.com/AishaDupont', 'is_active' => true, 'activity_level' => 'active' ],
                [ 'type' => 'chaturbate', 'url' => 'https://chaturbate.com/AishaDupont/', 'is_active' => true, 'activity_level' => 'active' ],
            ] );

            $extras = $this->selectExtras( 'Aisha Dupont' );

            $this->assertContains( 'Aisha Dupont Stripchat', $extras );
            $this->assertContains( 'Aisha Dupont Chaturbate', $extras );
            $this->assertNoDuplicateChips( $extras );
            $this->assertLessThanOrEqual( 4, count( $extras ) );
            foreach ( $extras as $extra ) {
                $this->assertStringNotContainsString( 'aisha dupont', $extra );
            }
        }


        public function test_build_keeps_focus_keyword_clean_and_caps_rankmath_extras(): void {
            $this->setAliases( '' );
            $this->setVerifiedLinks( [
                [ 'type' => 'livejasmin', 'url' => 'https://www.livejasmin.com/en/free/chat/AbbyMurray', 'is_active' => true, 'activity_level' => 'active' ],
            ] );
            $post = new \WP_Post( [ 'ID' => self::POST_ID, 'post_title' => 'Abby Murray', 'post_type' => 'model' ] );

            $pack = ModelKeywordPack::build( $post );

            $this->assertSame( 'Abby Murray', $pack['primary'] );
            $this->assertContains( 'Abby Murray LiveJasmin', $pack['rankmath_additional'] );
            $this->assertLessThanOrEqual( 4, count( $pack['rankmath_additional'] ) );
            $this->assertNoDuplicateChips( $pack['rankmath_additional'] );
        }

        /** @param array<int,array<string,mixed>> $links */
        private function setVerifiedLinks( array $links ): void {
            $GLOBALS['_tmw_rm_alias_meta'][ self::POST_ID ][ VerifiedLinks::META_KEY ] = json_encode( $links );
        }

        private function setAliases( string $aliases ): void {
            $GLOBALS['_tmw_rm_alias_meta'][ self::POST_ID ]['_tmwseo_research_aliases'] = $aliases;
        }


        /** @param string[] $extras */
        private function assertNoDuplicateChips( array $extras ): void {
            $keys = array_map( static fn( string $keyword ): string => strtolower( trim( $keyword ) ), $extras );
            $this->assertSame( count( $keys ), count( array_unique( $keys ) ), 'Rank Math extras must not contain duplicate chips.' );
        }

        /** @param string[] $personal */
        private function selectExtras( string $modelName, array $personal = [] ): array {
            $activePlatforms = new ReflectionMethod( ModelKeywordPack::class, 'active_platform_slugs' );
            $activePlatforms->setAccessible( true );
            $platforms = $activePlatforms->invoke( null, self::POST_ID );

            $select = new ReflectionMethod( ModelKeywordPack::class, 'select_rotating_rankmath_extras' );
            $select->setAccessible( true );
            $result = $select->invoke( null, self::POST_ID, $modelName, $personal, $platforms, [], [] );
            return $result['final'];
        }
    }
}
