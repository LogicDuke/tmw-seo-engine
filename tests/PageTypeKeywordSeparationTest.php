<?php
declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) define('ABSPATH', __DIR__);
    require_once __DIR__ . '/bootstrap/wp-post-stub.php';
    if (!class_exists('WP_Term')) { class WP_Term { public $name=''; public $slug=''; public $taxonomy=''; public function __construct($a=[]){foreach($a as $k=>$v){$this->$k=$v;}} } }
    if (!class_exists('WP_Error')) { class WP_Error { private $m; public function __construct($c='',$m=''){ $this->m=$m; } public function get_error_message(){ return $this->m; } } }

    $GLOBALS['_tmw_meta'] = [];
    $GLOBALS['_tmw_posts'] = [];
    $GLOBALS['_tmw_titles'] = [];
    $GLOBALS['_tmw_terms'] = [];
    $GLOBALS['_tmw_test_options'] = [];

    if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s){ return strip_tags((string)$s); } }
    if (!function_exists('esc_html')) { function esc_html($s){ return (string)$s; } }
    if (!function_exists('esc_url')) { function esc_url($s){ return (string)$s; } }
    if (!function_exists('sanitize_title')) { function sanitize_title($s){ return strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',(string)$s),'-')); } }
    if (!function_exists('sanitize_key')) { function sanitize_key($s){ return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$s)); } }
    if (!function_exists('get_option')) { function get_option($k,$d=false){ return array_key_exists($k,$GLOBALS['_tmw_test_options']) ? $GLOBALS['_tmw_test_options'][$k] : $d; } }
    if (!function_exists('update_option')) { function update_option($k,$v){ $GLOBALS['_tmw_test_options'][$k]=$v; return true; } }
    if (!function_exists('delete_option')) { function delete_option($k){ unset($GLOBALS['_tmw_test_options'][$k]); return true; } }
    if (!function_exists('get_post_meta')) { function get_post_meta($id,$k,$s=true){ return $GLOBALS['_tmw_meta'][$id][$k] ?? ''; } }
    if (!function_exists('update_post_meta')) { function update_post_meta($id,$k,$v){ $GLOBALS['_tmw_meta'][$id][$k]=$v; return true; } }
    if (!function_exists('current_time')) { function current_time($t){ return '2026-05-28 00:00:00'; } }
    if (!function_exists('get_post_field')) { function get_post_field($field,$id){ return $GLOBALS['_tmw_posts'][$id]->$field ?? ''; } }
    if (!function_exists('get_the_title')) { function get_the_title($id=0){ return $GLOBALS['_tmw_titles'][$id] ?? ($GLOBALS['_tmw_posts'][$id]->post_title ?? ''); } }
    if (!function_exists('get_post')) { function get_post($id){ return $GLOBALS['_tmw_posts'][$id] ?? null; } }
    if (!function_exists('get_posts')) { function get_posts($args=[]){ return []; } }
    if (!function_exists('get_object_taxonomies')) { function get_object_taxonomies($post_type,$output='names'){ return ['post_tag','category']; } }
    if (!function_exists('taxonomy_exists')) { function taxonomy_exists($taxonomy){ return true; } }
    if (!function_exists('get_taxonomy')) { function get_taxonomy($taxonomy){ return (object)['hierarchical'=>$taxonomy === 'category']; } }
    if (!function_exists('get_the_terms')) { function get_the_terms($post,$taxonomy){ $id = is_object($post) ? (int)$post->ID : (int)$post; return $GLOBALS['_tmw_terms'][$id][$taxonomy] ?? []; } }
    if (!function_exists('wp_get_post_terms')) { function wp_get_post_terms($post_id,$taxonomy,$args=[]){ return $GLOBALS['_tmw_terms'][$post_id][$taxonomy] ?? []; } }
    if (!function_exists('is_wp_error')) { function is_wp_error($x){ return $x instanceof \WP_Error; } }
    if (!function_exists('wp_parse_url')) { function wp_parse_url($url,$component=-1){ return parse_url($url,$component); } }
    if (!function_exists('add_query_arg')) { function add_query_arg($params,$url=''){ return (string)$url . (str_contains((string)$url,'?') ? '&' : '?') . http_build_query((array)$params); } }
    if (!function_exists('home_url')) { function home_url($path=''){ return 'https://top-models.webcam' . $path; } }
    if (!function_exists('esc_url_raw')) { function esc_url_raw($s){ return filter_var((string)$s, FILTER_SANITIZE_URL) ?: ''; } }
    if (!function_exists('wp_http_validate_url')) { function wp_http_validate_url($s){ return (bool) filter_var((string)$s, FILTER_VALIDATE_URL); } }
    if (!function_exists('wp_upload_dir')) { function wp_upload_dir($time=null,$create_dir=true,$refresh_cache=false){ return ['basedir'=>sys_get_temp_dir()]; } }

    if (!class_exists('WP_Query')) { class WP_Query { public $posts = []; public function __construct($args=[]){} } }
    if (!class_exists('TMWSEO\\Engine\\Logs')) { eval('namespace TMWSEO\\Engine; class Logs { public static function info($c,$m,$d=[]){} public static function warn($c,$m,$d=[]){} public static function error($c,$m,$d=[]){} public static function debug($c,$m,$d=[]){} }'); }

    require_once dirname(__DIR__) . '/includes/keywords/class-page-type-keyword-filter.php';
    require_once dirname(__DIR__) . '/includes/keywords/class-category-page-keyword-generator.php';
    require_once dirname(__DIR__) . '/includes/keywords/class-keyword-library.php';
    require_once dirname(__DIR__) . '/includes/keywords/class-model-keyword-pack.php';
    require_once dirname(__DIR__) . '/includes/keywords/class-model-keyword-suggestion-generator.php';
    require_once dirname(__DIR__) . '/includes/services/class-settings.php';
    require_once dirname(__DIR__) . '/includes/platform/class-platform-registry.php';
    require_once dirname(__DIR__) . '/includes/platform/class-affiliate-link-builder.php';
    require_once dirname(__DIR__) . '/includes/content/class-video-content-builder.php';
    require_once dirname(__DIR__) . '/includes/content/class-rank-math-mapper.php';
}

namespace TMWSEO\Engine\Tests {
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use TMWSEO\Engine\Content\RankMathMapper;
    use TMWSEO\Engine\Content\VideoContentBuilder;
    use TMWSEO\Engine\Keywords\CategoryPageKeywordGenerator;
    use TMWSEO\Engine\Keywords\ModelKeywordPack;
    use TMWSEO\Engine\Keywords\ModelKeywordSuggestionGenerator;
    use TMWSEO\Engine\Keywords\PageTypeKeywordFilter;
    use TMWSEO\Engine\Platform\AffiliateLinkBuilder;

    final class PageTypeKeywordSeparationTest extends TestCase {
        protected function setUp(): void {
            $GLOBALS['_tmw_meta'] = [];
            $GLOBALS['_tmw_posts'] = [];
            $GLOBALS['_tmw_titles'] = [];
            $GLOBALS['_tmw_terms'] = [];
            $GLOBALS['_tmw_test_options'] = [];
        }

        public function test_unsafe_terms_are_filtered(): void {
            $this->assertSame(['safe webcam model'], PageTypeKeywordFilter::filter_unsafe(['safe webcam model','cam porn','Lexy xxx','naked cam']));
        }

        public function test_model_filter_removes_video_session_modifiers(): void {
            $filtered = PageTypeKeywordFilter::filter_for_model_page(['Lexy Ness webcam model','Lexy Ness cam show','Lexy Ness live webcam clip','watch Lexy Ness','Lexy Ness cam profile']);
            $this->assertSame(['Lexy Ness webcam model','Lexy Ness cam profile'], $filtered);
        }

        public function test_video_filter_removes_profile_and_earnings_modifiers(): void {
            $filtered = PageTypeKeywordFilter::filter_for_video_page(['Lexy Ness webcam video','adult webcam','Lexy Ness cam profile','webcam earnings','Lexy Ness cam show']);
            $this->assertSame(['Lexy Ness webcam video','Lexy Ness cam show'], $filtered);
        }

        public function test_category_filter_removes_video_and_profile_modifiers(): void {
            $filtered = PageTypeKeywordFilter::filter_for_category_page(['amateur webcam models','Lexy Ness webcam video','cam profile','best amateur webcam models']);
            $this->assertSame(['amateur webcam models','best amateur webcam models'], $filtered);
        }

        public function test_category_generator_creates_archive_keywords_from_label(): void {
            $pack = CategoryPageKeywordGenerator::generate('Amateur Cam Girls & Webcam Models');
            $this->assertSame('amateur webcam models', $pack['primary']);
            $this->assertSame(['amateur live cam','amateur cam girls','best amateur webcam models','live amateur chat'], $pack['additional']);
        }

        public function test_category_generator_does_not_use_model_names_or_video_titles(): void {
            $pack = CategoryPageKeywordGenerator::generate('Amateur Cam Girls & Webcam Models');
            $csv = strtolower($pack['primary'] . ' ' . implode(' ', $pack['additional']));
            $this->assertStringNotContainsString('lexy ness', $csv);
            $this->assertStringNotContainsString('plays with her amazing body', $csv);
        }

        public function test_model_generator_no_longer_emits_cam_show_patterns(): void {
            $post = new \WP_Post(['ID'=>581,'post_title'=>'Lexy Ness','post_name'=>'lexy-ness','post_type'=>'model']);
            $generator = new ModelKeywordSuggestionGenerator();
            $pack = $generator->generate_for_model($post, false, false);
            $keywords = array_map(static fn($row): string => strtolower((string)($row['keyword'] ?? '')), $pack['extra_keywords']);
            $joined = implode('|', $keywords);
            $this->assertStringNotContainsString('cam show', $joined);
            $this->assertStringNotContainsString('private cam show', $joined);
            $this->assertStringNotContainsString('live cam show', $joined);
            $actual_attribute_count = count(array_filter($pack['extra_keywords'], static fn($row): bool => (string)($row['source'] ?? '') !== 'model_name_pattern'));
            $this->assertSame($actual_attribute_count, $pack['selected_attribute_count']);
        }

        public function test_model_rank_math_chips_do_not_include_livejasmin_without_verified_platform(): void {
            $chips = $this->buildModelRankMathChips('Alice Schuster', 581, []);
            $this->assertNotContains('Alice Schuster LiveJasmin', $chips);
            $this->assertNotEmpty($chips);
            $this->assertSame($chips, PageTypeKeywordFilter::filter_for_model_page($chips));
        }

        public function test_model_rank_math_chips_allow_livejasmin_when_verified_platform_present(): void {
            $chips = $this->buildModelRankMathChips('Alice Schuster', 581, ['livejasmin']);
            $this->assertContains('Alice Schuster LiveJasmin', $chips);
        }

        public function test_model_suggestion_generator_does_not_emit_unverified_livejasmin_pattern(): void {
            $post = new \WP_Post(['ID'=>582,'post_title'=>'Alice Schuster','post_name'=>'alice-schuster','post_type'=>'model']);
            $generator = new ModelKeywordSuggestionGenerator();
            $pack = $generator->generate_for_model($post, false, false);
            $keywords = array_map(static fn($row): string => (string)($row['keyword'] ?? ''), $pack['extra_keywords']);
            $this->assertNotContains('Alice Schuster LiveJasmin', $keywords);
            $this->assertNotEmpty($keywords);
        }

        public function test_video_secondary_keywords_do_not_include_profile_or_earnings_modifiers(): void {
            $secondary = VideoContentBuilder::build_secondary_keywords('Lexy Ness', 'Lexy Ness video chat');
            $joined = strtolower(implode('|', $secondary));
            $this->assertStringNotContainsString('cam profile', $joined);
            $this->assertStringNotContainsString('webcam earnings', $joined);
            $this->assertContains('Lexy Ness webcam video', $secondary);
        }

        public function test_rank_math_csv_keeps_primary_first_and_max_five(): void {
            $GLOBALS['_tmw_posts'][44] = new \WP_Post(['ID'=>44,'post_type'=>'post','post_title'=>'Video']);
            $csv = RankMathMapper::preview_rank_math_csv(44, [
                'primary' => 'Lexy Ness video chat',
                'additional' => ['adult webcam','Lexy Ness webcam video','Lexy Ness cam show','webcam earnings','Lexy Ness live webcam clip','Lexy Ness video chat','extra allowed'],
            ]);
            $parts = explode(',', $csv);
            $this->assertSame('Lexy Ness video chat', $parts[0]);
            $this->assertLessThanOrEqual(5, count($parts));
            $this->assertNotContains('adult webcam', $parts);
            $this->assertSame($parts, array_values(array_unique($parts)));
        }

        public function test_video_slug_behavior_remains_unchanged(): void {
            $slug = sanitize_title('Alice Schuster — Babe Cam Show');
            $this->assertSame('alice-schuster-babe-cam-show', $slug);
            $this->assertStringContainsString('babe-cam-show', $slug);
        }

        public function test_affiliate_behavior_from_pr_576_remains_unchanged(): void {
            update_option('tmwseo_platform_affiliate_settings',['livejasmin'=>['psid'=>'Topmodels4u','pstool'=>'205_1','psprogram'=>'revs','subaffid'=>'lexy-video']]);
            $url = AffiliateLinkBuilder::build_seo_content_affiliate_url('livejasmin','lexyness');
            $this->assertNotSame('', $url);
            $this->assertStringNotContainsString('/go/', $url);
            $this->assertStringContainsString('livejasmin.com', $url);
        }

        /** @param string[] $platform_slugs */
        private function buildModelRankMathChips(string $name, int $post_id, array $platform_slugs): array {
            $method = new ReflectionMethod(ModelKeywordPack::class, 'build_rankmath_chips');
            $method->setAccessible(true);
            return $method->invoke(null, $name, $post_id, $platform_slugs);
        }
    }
}
