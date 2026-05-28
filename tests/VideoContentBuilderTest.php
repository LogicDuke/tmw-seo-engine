<?php
declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) define('ABSPATH', __DIR__);
    if (!class_exists('WP_Post')) { class WP_Post { public $ID=0; public $post_name=''; public $post_status='publish'; public $post_type='post'; public $post_parent=0; public function __construct($a=[]){foreach($a as $k=>$v){$this->$k=$v;}} } }
    $GLOBALS['_tmw_meta'] = [];
    $GLOBALS['_tmw_posts'] = [];
    if (!function_exists('esc_html')) { function esc_html($s){ return (string)$s; } }
    if (!function_exists('esc_url')) { function esc_url($s){ return (string)$s; } }
    if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s){ return strip_tags((string)$s); } }
    if (!function_exists('get_post_meta')) { function get_post_meta($id,$k,$s=true){ return $GLOBALS['_tmw_meta'][$id][$k] ?? ''; } }
    if (!function_exists('update_post_meta')) { function update_post_meta($id,$k,$v){ $GLOBALS['_tmw_meta'][$id][$k]=$v; return true; } }
    if (!function_exists('delete_post_meta')) { function delete_post_meta($id,$k){ unset($GLOBALS['_tmw_meta'][$id][$k]); return true; } }
    if (!function_exists('current_time')) { function current_time($t){ return '2026-05-28 00:00:00'; } }
    if (!function_exists('get_the_title')) { function get_the_title($id=0){ return 'Title'; } }
    if (!function_exists('get_post')) { function get_post($id){ return $GLOBALS['_tmw_posts'][$id] ?? null; } }
    if (!function_exists('sanitize_title')) { function sanitize_title($s){ return strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',(string)$s),'-')); } }
    if (!function_exists('wp_unique_post_slug')) { function wp_unique_post_slug($slug){ return $slug; } }
    if (!function_exists('wp_update_post')) { function wp_update_post($arr,$err=false){ if(!empty($GLOBALS['_tmw_force_wp_update_error'])) return new 
WP_Error('e','boom'); $id=(int)$arr['ID']; if(isset($GLOBALS['_tmw_posts'][$id])){ foreach($arr as $k=>$v){ if($k!=='ID') $GLOBALS['_tmw_posts'][$id]->$k=$v; } } return $id; } }
    if (!function_exists('is_wp_error')) { function is_wp_error($x){ return $x instanceof \WP_Error; } }
    if (!class_exists('WP_Error')) { class WP_Error { private $m; public function __construct($c='',$m=''){ $this->m=$m; } public function get_error_message(){ return $this->m; } } }
    if (!function_exists('get_post_thumbnail_id')) { function get_post_thumbnail_id($id){ return (int)($GLOBALS['_tmw_thumb'][$id] ?? 0); } }

    $GLOBALS['_tmw_test_options'] = [];
    if (!function_exists('get_option')) { function get_option($k,$d=false){ return array_key_exists($k,$GLOBALS['_tmw_test_options']) ? $GLOBALS['_tmw_test_options'][$k] : $d; } }
    if (!function_exists('update_option')) { function update_option($k,$v){ $GLOBALS['_tmw_test_options'][$k]=$v; return true; } }
    if (!function_exists('delete_option')) { function delete_option($k){ unset($GLOBALS['_tmw_test_options'][$k]); return true; } }
    if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s){ return trim(strip_tags((string)$s)); } }
    if (!function_exists('sanitize_key')) { function sanitize_key($s){ return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$s)); } }
    if (!function_exists('wp_unslash')) { function wp_unslash($v){ return is_string($v) ? stripslashes($v) : $v; } }
    if (!function_exists('esc_url_raw')) { function esc_url_raw($s){ return filter_var((string)$s, FILTER_SANITIZE_URL) ?: ''; } }
    if (!function_exists('wp_http_validate_url')) { function wp_http_validate_url($s){ return (bool) filter_var((string)$s, FILTER_VALIDATE_URL); } }
    if (!function_exists('home_url')) { function home_url($path=''){ return 'https://top-models.webcam' . $path; } }
    if (!function_exists('wp_parse_url')) { function wp_parse_url($url,$component=-1){ return parse_url($url,$component); } }
    if (!function_exists('add_query_arg')) { function add_query_arg($params,$url=''){ return (string)$url . (str_contains((string)$url,'?') ? '&' : '?') . http_build_query((array)$params); } }

    if (!class_exists('TMWSEO\\Engine\\Logs')) { eval('namespace TMWSEO\\Engine; class Logs { public static function info($c,$m,$d=[]){} public static function warn($c,$m,$d=[]){} public static function error($c,$m,$d=[]){} public static function debug($c,$m,$d=[]){} }'); }
    require_once dirname(__DIR__) . '/includes/services/class-settings.php';
    require_once dirname(__DIR__) . '/includes/platform/class-platform-registry.php';
    require_once dirname(__DIR__) . '/includes/platform/class-affiliate-link-builder.php';
    require_once dirname(__DIR__) . '/includes/content/class-video-content-builder.php';
    require_once dirname(__DIR__) . '/includes/admin/class-admin-ajax-handlers.php';
}

namespace TMWSEO\Engine\Tests {
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use TMWSEO\Engine\Admin\AdminAjaxHandlers;
    use TMWSEO\Engine\Content\VideoContentBuilder;
    use TMWSEO\Engine\Platform\AffiliateLinkBuilder;

    final class VideoContentBuilderTest extends TestCase {
        protected function setUp(): void {
            $GLOBALS['_tmw_meta'] = [];
            $GLOBALS['_tmw_posts'] = [10 => new \WP_Post(['ID'=>10,'post_name'=>'old-slug'])];
            $GLOBALS['_tmw_thumb'] = [10 => 55];
            $GLOBALS['_tmw_force_wp_update_error'] = false;
            $GLOBALS['_tmw_test_options'] = [];
        }
        private function callPrivate(string $method, array $args){ $r=new ReflectionMethod(AdminAjaxHandlers::class,$method); $r->setAccessible(true); return $r->invokeArgs(null,$args); }

        public function test_derive_focus_keyword_prefers_video_chat(): void { $this->assertSame('Lexy Ness video chat', VideoContentBuilder::derive_focus_keyword('Lexy Ness Plays — Webcam Video Chat', 'Lexy Ness')); }
        public function test_secondary_keywords_exclude_primary_even_without_model(): void { $this->assertNotContains('video chat', VideoContentBuilder::build_secondary_keywords('', 'video chat')); }
        public function test_secondary_keywords_are_unique_and_exclude_primary(): void { $secondary = VideoContentBuilder::build_secondary_keywords('Lexy Ness', 'Lexy Ness video chat'); $this->assertNotContains('Lexy Ness video chat', $secondary); $this->assertSame($secondary, array_values(array_unique($secondary))); }
        public function test_seo_title_starts_with_focus_keyword_and_has_best_and_year(): void { $title = VideoContentBuilder::build_seo_title('Lexy Ness', 'Lexy Ness video chat', 'X'); $this->assertStringStartsWith('Lexy Ness Video Chat:', $title); $this->assertStringContainsString('Best', $title); $this->assertStringContainsString(gmdate('Y'), $title); }
        public function test_meta_description_starts_with_focus_keyword(): void { $desc = VideoContentBuilder::build_meta_description('Lexy Ness', 'Lexy Ness video chat', 'X'); $this->assertStringStartsWith('Lexy Ness video chat', $desc); }

        public function test_manual_write_rank_math_fields_writes_expected_meta(): void {
            VideoContentBuilder::write_rank_math_fields(10,['focus_keyword'=>'Lexy Ness video chat','seo_title'=>'Lexy Ness Video Chat: Best Webcam Clip Guide 2026','meta_description'=>'Lexy Ness video chat page text','secondary_keywords'=>['Lexy Ness webcam video']],true);
            $this->assertSame('Lexy Ness Video Chat: Best Webcam Clip Guide 2026',$GLOBALS['_tmw_meta'][10]['rank_math_title']);
            $this->assertSame('Lexy Ness video chat page text',$GLOBALS['_tmw_meta'][10]['rank_math_description']);
            $this->assertStringStartsWith('Lexy Ness video chat',$GLOBALS['_tmw_meta'][10]['rank_math_focus_keyword']);
            $this->assertSame('Lexy Ness video chat',$GLOBALS['_tmw_meta'][10]['_tmwseo_keyword']);
            $this->assertSame('1',$GLOBALS['_tmw_meta'][10]['_tmwseo_video_rankmath_managed']);
            $this->assertArrayNotHasKey('rank_math_robots',$GLOBALS['_tmw_meta'][10]);
        }

        public function test_slug_helper_updates_slug(): void { $result=$this->callPrivate('maybe_update_video_slug',[10,'Lexy Ness video chat']); $this->assertTrue($result['updated']); $this->assertSame('lexy-ness-video-chat',$GLOBALS['_tmw_posts'][10]->post_name); }
        public function test_slug_helper_skips_when_already_target(): void { $GLOBALS['_tmw_posts'][10]->post_name='lexy-ness-video-chat'; $result=$this->callPrivate('maybe_update_video_slug',[10,'Lexy Ness video chat']); $this->assertFalse($result['updated']); }
        public function test_slug_helper_returns_error_on_wp_error(): void { $GLOBALS['_tmw_force_wp_update_error']=true; $result=$this->callPrivate('maybe_update_video_slug',[10,'Lexy Ness video chat']); $this->assertFalse($result['updated']); $this->assertNotSame('',$result['error']); }
        public function test_alt_helper_writes_and_backs_up_alt(): void { $GLOBALS['_tmw_meta'][55]['_wp_attachment_image_alt']='old'; $this->callPrivate('maybe_update_video_featured_image_alt',[10,'Lexy Ness video chat']); $this->assertSame('Lexy Ness video chat webcam clip',$GLOBALS['_tmw_meta'][55]['_wp_attachment_image_alt']); $this->assertSame('old',$GLOBALS['_tmw_meta'][10]['_tmwseo_prev_video_image_alt']); }
        public function test_alt_helper_noop_without_thumbnail(): void { $GLOBALS['_tmw_thumb'][10]=0; $this->callPrivate('maybe_update_video_featured_image_alt',[10,'Lexy Ness video chat']); $this->assertArrayNotHasKey(10,$GLOBALS['_tmw_meta']); }

        public function test_video_affiliate_resolver_returns_external_approved_url(): void {
            update_option('tmwseo_platform_affiliate_settings',['livejasmin'=>['psid'=>'Topmodels4u','pstool'=>'205_1','psprogram'=>'revs','subaffid'=>'lexy-video']]);
            $GLOBALS['_tmw_meta'][10]['_tmwseo_platform_username_livejasmin']='lexyness';
            $r=new ReflectionMethod(VideoContentBuilder::class,'resolve_model_affiliate_url'); $r->setAccessible(true);
            $url=$r->invoke(null,10,'Lexy Ness');
            $this->assertNotSame('', $url);
            $this->assertSame('ctwmsg.com', (string) wp_parse_url($url, PHP_URL_HOST));
            $this->assertStringNotContainsString('/go/livejasmin/', $url);
            $this->assertStringContainsString('Topmodels4u', $url);
            $this->assertStringContainsString('lexy-video', $url);
        }

        public function test_video_affiliate_resolver_skips_when_config_missing(): void {
            $GLOBALS['_tmw_meta'][10]['_tmwseo_platform_username_livejasmin']='lexyness';
            $r=new ReflectionMethod(VideoContentBuilder::class,'resolve_model_affiliate_url'); $r->setAccessible(true);
            $this->assertSame('', $r->invoke(null,10,'Lexy Ness'));
        }

        public function test_seo_affiliate_builder_never_returns_internal_go_url(): void {
            update_option('tmwseo_platform_affiliate_settings',['livejasmin'=>['psid'=>'Topmodels4u','pstool'=>'205_1','psprogram'=>'revs']]);
            $url=AffiliateLinkBuilder::build_seo_content_affiliate_url('livejasmin','lexyness');
            $this->assertNotSame('', $url);
            $this->assertStringNotContainsString('top-models.webcam/go', $url);
            $this->assertNotSame('top-models.webcam', (string) wp_parse_url($url, PHP_URL_HOST));
        }

        public function test_generated_content_includes_sponsored_blank_external_link(): void {
            update_option('tmwseo_platform_affiliate_settings',['livejasmin'=>['psid'=>'Topmodels4u','pstool'=>'205_1','psprogram'=>'revs']]);
            $GLOBALS['_tmw_meta'][10]['_tmwseo_platform_username_livejasmin']='lexyness';
            $r=new ReflectionMethod(VideoContentBuilder::class,'build_content_html'); $r->setAccessible(true);
            $html=$r->invoke(null,10,'Lexy Ness Plays With Her Amazing Body','', 'Lexy Ness','https://top-models.webcam/model/lexy-ness/',['Webcam Videos'],['video chat'],'LiveJasmin','Lexy Ness Plays With Her Amazing Body',['Lexy Ness webcam video']);
            $this->assertStringContainsString('Watch Lexy Ness Live on LiveJasmin', $html);
            $this->assertStringContainsString('rel="sponsored nofollow noopener"', $html);
            $this->assertStringContainsString('target="_blank"', $html);
            $this->assertStringContainsString('href="https://ctwmsg.com/', $html);
            $this->assertStringNotContainsString('/go/livejasmin/', $html);
        }

        public function test_generated_content_skips_affiliate_section_without_approved_url(): void {
            $GLOBALS['_tmw_meta'][10]['_tmwseo_platform_username_livejasmin']='lexyness';
            $r=new ReflectionMethod(VideoContentBuilder::class,'build_content_html'); $r->setAccessible(true);
            $html=$r->invoke(null,10,'Lexy Ness Plays With Her Amazing Body','', 'Lexy Ness','https://top-models.webcam/model/lexy-ness/',['Webcam Videos'],['video chat'],'LiveJasmin','Lexy Ness Plays With Her Amazing Body',['Lexy Ness webcam video']);
            $this->assertStringNotContainsString('Official Profile Access', $html);
            $this->assertStringNotContainsString('Watch Lexy Ness Live on LiveJasmin', $html);
            $this->assertStringNotContainsString('https://www.livejasmin.com/en/chat/lexyness', $html);
        }
    }
}
