<?php
declare(strict_types=1);

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__); }
$GLOBALS['_tmw_smoke_meta'] = [];
$GLOBALS['_tmw_smoke_posts'] = [];
$GLOBALS['_tmw_smoke_titles'] = [];
$GLOBALS['wpdb'] = null;
require_once __DIR__ . '/bootstrap/wp-post-stub.php';

function smoke_assert(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
if (!function_exists('sanitize_key')) { function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string)$s)); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s) { return strip_tags((string)$s); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(strip_tags((string)$s)); } }
if (!function_exists('wp_parse_url')) { function wp_parse_url($url, $component = -1) { return parse_url((string)$url, $component); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($data, $flags = 0, $depth = 512) { return json_encode($data, $flags, $depth); } }
function get_post_meta($id, $key = '', $single = false) { return $GLOBALS['_tmw_smoke_meta'][(int)$id][(string)$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['_tmw_smoke_meta'][(int)$id][(string)$key] = $value; return true; }
function delete_post_meta($id, $key) { unset($GLOBALS['_tmw_smoke_meta'][(int)$id][(string)$key]); return true; }
function get_post_field($field, $id) { return $GLOBALS['_tmw_smoke_posts'][(int)$id]->$field ?? ''; }
function get_the_title($id = 0) { return $GLOBALS['_tmw_smoke_titles'][(int)$id] ?? ($GLOBALS['_tmw_smoke_posts'][(int)$id]->post_title ?? ''); }
function get_post($id) { return $GLOBALS['_tmw_smoke_posts'][(int)$id] ?? null; }
function current_time($type) { return '2026-06-02 00:00:00'; }
function apply_filters($tag, $value) { return $value; }
function get_option($key, $default = false) { return $default; }
function get_object_taxonomies($post_type) { return []; }
function get_the_terms($post, $taxonomy) { return []; }
function wp_upload_dir($time = null, $create_dir = true, $refresh_cache = false) { return [ 'basedir' => sys_get_temp_dir() ]; }

if (!class_exists('TMWSEO\\Engine\\Logs')) { eval('namespace TMWSEO\\Engine; class Logs { public static function info($c,$m,$d=[]){} public static function warn($c,$m,$d=[]){} public static function error($c,$m,$d=[]){} public static function debug($c,$m,$d=[]){} }'); }
if (!class_exists('TMWSEO\\Engine\\Services\\DataForSEO')) { eval('namespace TMWSEO\\Engine\\Services; class DataForSEO { public static function is_configured(){ return false; } public static function keyword_suggestions($seed,$limit=80){ return ["ok"=>false,"items"=>[]]; } }'); }
if (!class_exists('TMWSEO\\Engine\\Services\\Settings')) { eval('namespace TMWSEO\\Engine\\Services; class Settings { public static function get($key,$default=null){ return $default; } }'); }

require_once dirname(__DIR__) . '/includes/keywords/class-keyword-library.php';
require_once dirname(__DIR__) . '/includes/keywords/class-page-type-keyword-filter.php';
require_once dirname(__DIR__) . '/includes/keywords/class-model-keyword-pool-classifier.php';
require_once dirname(__DIR__) . '/includes/keywords/class-classified-model-keyword-provider.php';
require_once dirname(__DIR__) . '/includes/model/class-verified-links-families.php';
require_once dirname(__DIR__) . '/includes/model/class-verified-links.php';
require_once dirname(__DIR__) . '/includes/keywords/class-model-keyword-pack.php';
require_once dirname(__DIR__) . '/includes/content/class-audit-trail.php';
require_once dirname(__DIR__) . '/includes/content/class-rank-math-mapper.php';

use TMWSEO\Engine\Content\RankMathMapper;
use TMWSEO\Engine\Model\VerifiedLinks;

$postId = 91001;
$post = new WP_Post([ 'ID' => $postId, 'post_title' => 'Anisyia', 'post_type' => 'model' ]);
$GLOBALS['_tmw_smoke_posts'][$postId] = $post;
$GLOBALS['_tmw_smoke_titles'][$postId] = 'Anisyia';
update_post_meta($postId, VerifiedLinks::META_KEY, json_encode([[ 'type' => 'camsoda', 'url' => 'https://www.camsoda.com/Anisyia', 'is_active' => true, 'activity_level' => 'active' ]]));
update_post_meta($postId, 'rank_math_focus_keyword', 'Anisyia,anisyia livejasmin,anisyia live,livejasmin anisyia,Anisyia CamSoda');

RankMathMapper::sync_to_rank_math($postId, [ 'primary' => 'stale', 'rankmath_additional' => [ 'anisyia livejasmin' ] ], true);
$saved = (string) get_post_meta($postId, 'rank_math_focus_keyword', true);
$expected = 'Anisyia,Anisyia CamSoda,Anisyia live cam,Anisyia live webcam,Anisyia private live chat';
smoke_assert($saved === $expected, 'old Anisyia Rank Math meta should be replaced; got ' . $saved);
foreach ([ 'anisyia livejasmin', 'livejasmin anisyia', 'Anisyia LiveJasmin', 'anisyia live cam' ] as $bad) { smoke_assert(strpos($saved, $bad) === false, 'forbidden chip remains: ' . $bad); }
smoke_assert(is_array(get_post_meta($postId, 'tmw_keyword_pack', true)), 'tmw_keyword_pack should be replaced');
smoke_assert((string) get_post_meta($postId, '_tmwseo_keyword_pack_json', true) !== '', '_tmwseo_keyword_pack_json should be replaced');
echo "Rank Math model keyword refresh smoke passed\n";
