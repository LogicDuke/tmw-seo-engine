<?php
declare(strict_types=1);

namespace {
if (!defined('ABSPATH')) define('ABSPATH', __DIR__);
require_once __DIR__ . '/bootstrap/wp-post-stub.php';
if (!function_exists('get_post')) { function get_post($id){ return $GLOBALS['_rb_posts'][$id] ?? null; } }
if (!function_exists('get_post_meta')) { function get_post_meta($id,$k,$s=true){ return $GLOBALS['_rb_meta'][$id][$k] ?? ''; } }
if (!function_exists('update_post_meta')) { function update_post_meta($id,$k,$v){ $GLOBALS['_rb_meta'][$id][$k]=$v; return true; } }
if (!function_exists('delete_post_meta')) { function delete_post_meta($id,$k){ unset($GLOBALS['_rb_meta'][$id][$k]); return true; } }
if (!function_exists('current_time')) { function current_time($t){ return '2026-05-28 00:00:00'; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v){ return json_encode($v); } }
if (!function_exists('wp_update_post')) { function wp_update_post($a){ $id=(int)$a['ID']; foreach($a as $k=>$v){ if($k!=='ID') $GLOBALS['_rb_posts'][$id]->$k=$v; } return $id; } }
if (!function_exists('get_post_thumbnail_id')) { function get_post_thumbnail_id($id){ return (int)($GLOBALS['_rb_thumb'][$id] ?? 0); } }
$GLOBALS['wpdb'] = new class { public function delete($table,$where,$format){ return true; } };
}

namespace TMWSEO\Engine\Keywords {
    class KeywordUsage { public static function maybe_upgrade(): void {} public static function log_table(): string { return 'kw'; } }
}

namespace {
require_once dirname(__DIR__) . '/includes/model/class-rollback.php';
}

namespace TMWSEO\Engine\Tests {
use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Model\Rollback;

final class RollbackVideoTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['_rb_meta']=[];
        $GLOBALS['_rb_thumb']=[22=>77];
        $GLOBALS['_rb_posts']=[22=>new \WP_Post(['ID'=>22,'post_title'=>'t','post_excerpt'=>'e','post_name'=>'old-slug'])];
        $GLOBALS['_rb_meta'][77]['_wp_attachment_image_alt']='old alt';
    }
    public function test_snapshot_and_restore_include_slug_and_thumb_alt(): void {
        Rollback::snapshot(22,true);
        $GLOBALS['_rb_posts'][22]->post_name='new-slug';
        $GLOBALS['_rb_meta'][77]['_wp_attachment_image_alt']='new alt';
        $result=Rollback::restore(22);
        $this->assertTrue($result['ok']);
        $this->assertSame('old-slug',$GLOBALS['_rb_posts'][22]->post_name);
        $this->assertSame('old alt',$GLOBALS['_rb_meta'][77]['_wp_attachment_image_alt']);
    }
}
}
