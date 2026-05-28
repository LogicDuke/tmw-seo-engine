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

    require_once dirname(__DIR__) . '/includes/logs/class-logs.php';
    require_once dirname(__DIR__) . '/includes/content/class-video-content-builder.php';
    require_once dirname(__DIR__) . '/includes/admin/class-admin-ajax-handlers.php';
}

namespace TMWSEO\Engine\Tests {
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use TMWSEO\Engine\Admin\AdminAjaxHandlers;
    use TMWSEO\Engine\Content\VideoContentBuilder;

    final class VideoContentBuilderTest extends TestCase {
        protected function setUp(): void {
            $GLOBALS['_tmw_meta'] = [];
            $GLOBALS['_tmw_posts'] = [10 => new \WP_Post(['ID'=>10,'post_name'=>'old-slug'])];
            $GLOBALS['_tmw_thumb'] = [10 => 55];
            $GLOBALS['_tmw_force_wp_update_error'] = false;
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
    }
}
