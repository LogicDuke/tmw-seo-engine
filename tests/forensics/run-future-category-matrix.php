<?php
/** PHASE 4 — synthetic future-category reliability matrix (v5.9.13 live code). */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/'); }
$GLOBALS['__opts'] = [];
if (!function_exists('get_option'))    { function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; } }
if (!function_exists('delete_option')) { function delete_option($k) { unset($GLOBALS['__opts'][$k]); return true; } }
if (!function_exists('esc_html'))      { function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_url'))       { function esc_url($u) { return filter_var((string) $u, FILTER_SANITIZE_URL); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($t) { return trim(strip_tags((string) $t)); } }
if (!function_exists('__'))            { function __($t, $d = null) { return $t; } }
$pipeline_dir = dirname(__DIR__) . '/includes/content/category-pipeline/';
foreach ([
    'class-category-context-builder','class-category-intent-classifier','class-category-keyword-planner',
    'class-category-content-planner','class-category-draft-composer','class-category-quality-guard',
    'class-category-factual-safety','class-category-grammar-guard','class-category-density-policy','class-category-keyword-placement',
    'class-category-paragraph-uniqueness-guard','class-category-claim-ledger','class-category-specificity-scorer',
    'class-category-faq-reuse-guard','class-category-generation-result','class-category-differentiation-scorer',
    'class-category-faq-planner','class-category-final-validator','class-category-generation-pipeline',
] as $c) { require_once $pipeline_dir . $c . '.php'; }
require_once dirname(__DIR__) . '/includes/content/class-rank-math-chip-analyzer.php';
use TMWSEO\Engine\Content\CategoryPipeline\CategoryContextBuilder;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationPipeline;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryDensityPolicy;

$base = ['site_name'=>'Top-Models.Webcam','models_url'=>'https://top-models.webcam/webcam-models/','videos_url'=>'https://top-models.webcam/webcam-videos/','model_count'=>3244,'video_count'=>2871];
$rel = static fn(array $n): array => array_map(static fn($x) => ['name'=>$x,'url'=>'https://top-models.webcam/category/'.strtolower(str_replace(' ','-',$x)).'/'], $n);
$std_rel = $rel(['Amateur Cams','Free Cam Chat']);

$matrix = [
 // label, name, chips, mode(chip|pool), overrides
 ['A1 body type distinct fams',        'Curvy Cam Models', ['curvy webcam girls','plus size cam chat','thick model shows','bbw live video'], 'chip', []],
 ['A2 body type 4-same-family',        'Curvy Cam Models', ['curvy cams','curvy live cam','live curvy cams','free curvy cams'], 'chip', []],
 ['A3 body type 5-same-family',        'Curvy Cam Models', ['curvy cams','curvy live cam','live curvy cams','free curvy cams','curvy webcam'], 'chip', []],
 ['A4 trait 6-same-family (blonde-like)','Redhead Cam Models',['redhead cams','redhead live cam','live redhead cams','free redhead cams','redhead webcam','best redhead cams'], 'chip', []],
 ['B1 ethnicity distinct',             'Ebony Cam Models', ['ebony sex chat','black webcam girls','ebony live shows','dark skin cam video'], 'chip', []],
 ['B2 language/location',              'German Speaking Cams', ['german webcam models','german live chat','deutsch cam shows','german speaking models'], 'chip', []],
 ['C1 pricing 8 chips mixed',          'Cheap Cam Shows', ['cheap live cams','cheap webcam chat','budget cam sites','low cost cam shows','cheap private shows','affordable cam girls','cheap cam2cam','discount webcam shows'], 'chip', []],
 ['C2 fetish',                         'Latex Fetish Cams', ['latex webcam shows','latex model chat','shiny outfit cams','fetish latex video'], 'chip', []],
 ['C3 couples',                        'Couple Cam Shows', ['couples webcam','live couple chat','real couple video','two model shows'], 'chip', []],
 ['D1 broad discovery',                'Live Cam Girls', ['live webcam girls','cam girl shows','live girl chat','webcam girl video'], 'chip', []],
 ['D2 niche ambiguous',                'Mirror Room Cams', ['mirror room webcam','mirror show chat'], 'chip', []],
 ['D3 invented plausible',             'Neon Loft Cams', ['neon loft webcam','loft show chat','neon room video'], 'chip', []],
 ['D4 long multiword',                 'Big Curvy Latina Webcam Models Online', ['curvy latina webcam','big latina cams','latina model shows','curvy webcam chat'], 'chip', []],
 ['D5 singular',                       'Blonde Cam Model', ['blonde model chat','single model cams'], 'chip', []],
 ['D6 contains webcam',                'Webcam Couples', ['webcam couple shows','couple live chat','duo cam video'], 'chip', []],
 ['E1 2-chip small pool',              'Petite Cam Models', ['petite webcam','small model chat'], 'chip', []],
 ['E2 1-chip minimal',                 'Tattoo Cam Models', ['tattooed webcam girls'], 'chip', []],
 ['E3 zero chips selective pool',      'Piercing Cam Models', ['pierced webcam girls','pierced model chat','alt girl cams','piercing show video'], 'pool', []],
 ['E4 empty keyword pool',             'Mystery Cam Models', [], 'pool', []],
 ['F1 no related categories',          'Solo Cam Models', ['solo webcam shows','single performer chat','solo model video','one on one cams'], 'chip', ['related_categories'=>[]]],
 ['F2 zero listings',                  'Ginger Cam Models', ['ginger webcam girls','ginger model chat','redhair cam video','ginger live shows'], 'chip', ['model_count'=>0,'video_count'=>0]],
 ['F3 models only',                    'Curly Hair Cams', ['curly hair webcam','curly model chat','natural hair cams','curl show video'], 'chip', ['video_count'=>0]],
];

// Populate the production store first with the five real categories (round 1).
$cats = [
 'big-boob-cam'=>['Big Boob Cam',['big breast webcam','big boobs webcam','biggest boobs webcam','massive boob webcam','massive boobs cam','massive breasts webcam']],
 'amateur-cams'=>['Amateur Cams',['amateur webcam','amateur tv cams','live amateur sex cams','amateur sex chat']],
 'free-cam-chat'=>['Free Cam Chat',['free cam to cam chat','free live cams','free webcam chat','cam to cam chat','free live cam chat','cam chat sites','webcam chat rooms','free webcam shows']],
 'latina-cam-models'=>['Latina Cam Models',['latina sex cam','latina live sex','live latina cams','latina nude webcam']],
 'blonde-cam-models'=>['Blonde Cam Models',['blonde cams','blonde live sex','blonde sex cam','blonde live webcam']],
];
foreach ($cats as $slug=>[$name,$kws]) {
    $fx=$base+['category_slug'=>$slug,'category_name'=>$name,'primary_keyword'=>$name,'approved_keywords'=>$kws,'stored_chips'=>$kws,'related_categories'=>$std_rel];
    CategoryGenerationPipeline::generate_from_context(CategoryContextBuilder::build_from_parts($fx),['tracking'=>array_slice($kws,0,8)]);
}
echo "store primed with 5 real categories\n\n";

$okc=0;$failc=0;$fragile=0;
foreach ($matrix as [$label,$name,$kws,$mode,$ov]) {
    $slug=strtolower(str_replace(' ','-',$name));
    $fx=$base+['category_slug'=>$slug,'category_name'=>$name,'primary_keyword'=>$name,'approved_keywords'=>$kws,'related_categories'=>$std_rel]+$ov;
    if ($mode==='chip' && !empty($kws)) { $fx['stored_chips']=$kws; }
    CategoryDensityPolicy::set_final_context('', '');
    $res=CategoryGenerationPipeline::generate_from_context(CategoryContextBuilder::build_from_parts($fx),['tracking'=>array_slice($kws,0,8)]);
    CategoryDensityPolicy::clear_final_context();
    $rep=(array)$res['report'];
    $att=(int)($rep['attempt_count']??0);
    $ok=!empty($res['ok']);
    if($ok){$okc++;}else{$failc++;}
    if($ok&&$att>=5){$fragile++;}
    printf("%-38s ok=%-3s attempts=%d words=%-4s intent=%-20s conf=%s\n",$label,$ok?'YES':'NO',$att,(string)($rep['metrics']['word_count']??'?'),(string)($rep['intent']??'?'),(string)($rep['intent_confidence']??'?'));
    if(!$ok||$att>=5){
        foreach(array_slice((array)($rep['attempt_log']??[]),-3) as $a){
            printf("    salt=%d reasons=%s\n",(int)$a['salt'],implode(' | ',array_slice((array)$a['reasons'],0,4)));
        }
    }
}
printf("\nTOTAL ok=%d fail=%d fragile(>=5 attempts)=%d of %d\n",$okc,$failc,$fragile,count($matrix));
