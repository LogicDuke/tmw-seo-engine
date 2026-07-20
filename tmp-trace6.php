<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir().'/'); }
$GLOBALS['__opts']=[];
function get_option($k,$d=false){return $GLOBALS['__opts'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['__opts'][$k]=$v;return true;}
function delete_option($k){unset($GLOBALS['__opts'][$k]);return true;}
function esc_html($t){return htmlspecialchars((string)$t,ENT_QUOTES,'UTF-8');}
function esc_url($u){return filter_var((string)$u,FILTER_SANITIZE_URL);}
function esc_attr($t){return htmlspecialchars((string)$t,ENT_QUOTES,'UTF-8');}
function wp_strip_all_tags($t){return trim(strip_tags((string)$t));}
function __($t,$d=null){return $t;}
function sanitize_title($t){return strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',(string)$t),'-'));}
$d=__DIR__.'/includes/content/category-pipeline/';
foreach (glob($d.'class-*.php') as $f) require_once $f;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryChipFeasibility as F;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryContextBuilder as CB;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationPipeline as P;

$cats = [
 'Big Boob Cam'      => ['big breast webcam','big boobs webcam','biggest boobs webcam','massive boob webcam','massive boobs cam','massive breasts webcam','big boobs live cam','big boobs live camera'],
 'Blonde Cam Models' => ['blonde cams','blonde live sex','blonde sex cam','blonde live webcam','live blonde cams','blonde nude cam','free blonde cams','blonde live chat'],
 'Latina Cam Models' => ['latina sex cam','latina live sex','live latina cams','latina nude webcam','latina sex chat','latina live nude','latina live webcam','latina cam nude'],
 'Free Cam Chat'     => ['free cam to cam chat','free live cams','free webcam chat','cam to cam chat','cam chat rooms','webcam chat rooms','free live cam chat','free webcam shows'],
 'Amateur Cams'      => ['amateur webcam','amateur tv cams','live amateur sex cams','amateur sex chat','amateur live cams'],
 'Live Anal Cams'    => ['anal cams','live anal webcam','free anal cams','live anal chat','anal chat cam','best anal cams','free anal webcams','free live anal'],
];
$base=['site_name'=>'Top-Models.Webcam','models_url'=>'https://top-models.webcam/webcam-models/','videos_url'=>'https://top-models.webcam/webcam-videos/'];
$cmp=[]; $pages=[];
foreach ($cats as $name=>$chips){
  $slug=sanitize_title($name);
  $set=F::active_set($name,$chips); $active=$set['active'];
  $ctx=CB::build_from_parts($base+['category_slug'=>$slug,'category_name'=>$name,'primary_keyword'=>$name,'approved_keywords'=>$active,'stored_chips'=>$active,'related_categories'=>[['name'=>'Amateur Cams','url'=>'https://top-models.webcam/category/amateur-cams/'],['name'=>'Free Cam Chat','url'=>'https://top-models.webcam/category/free-cam-chat/']],'model_count'=>24,'video_count'=>18]);
  $out=P::generate_from_context($ctx,['tracking'=>$active,'use_store'=>false,'comparisons'=>$cmp]);
  if(!empty($out['report']['fingerprint'])) $cmp[]=$out['report']['fingerprint'];
  $html=(string)($out['html']??'');
  $pages[$name]=$html;
  preg_match_all('/<h([2-6])[^>]*>(.*?)<\/h\1>/is',$html,$hm);
  $heads=array_map(fn($h)=>trim(strip_tags($h)),$hm[2]);
  echo "\n========================= $name =========================\n";
  echo "active set (".count($active)."): ".implode(' | ',$active)."\n";
  echo "ok=".($out['ok']?'1':'0')."  words=".(int)($out['report']['metrics']['word_count']??str_word_count(strip_tags($html)))."\n";
  echo "HEADINGS:\n"; foreach($heads as $h) echo "   - $h\n";
}
echo "\n\n===================== CROSS-CATEGORY PARAGRAPH REUSE =====================\n";
function paras($html){preg_match_all('/<p[^>]*>(.*?)<\/p>/is',$html,$m);return array_map(fn($p)=>trim(preg_replace('/\s+/',' ',strip_tags($p))),$m[1]);}
$catwords=['big','boob','boobs','breast','breasts','blonde','latina','amateur','anal','free','cam','cams','webcam','live','chat','models','model'];
$catnames=array_keys($cats);
$byshape=[];
foreach($pages as $name=>$html){
  foreach(paras($html) as $p){
    $shape=strtolower($p);
    foreach($catnames as $cn){ $shape=str_ireplace(strtolower($cn),'{CAT}',$shape); }
    $shape=preg_replace('/\b('.implode('|',$catwords).')\b/i','',$shape);
    $shape=preg_replace('/[^a-z0-9 ]+/i',' ',$shape);
    $shape=preg_replace('/\s+/',' ',trim($shape));
    if(strlen($shape)<40) continue;
    $sig=substr(md5($shape),0,10);
    $byshape[$sig][]=[$name,$p];
  }
}
$reused=0; $total_shapes=count($byshape);
foreach($byshape as $sig=>$rows){
  $names=array_unique(array_map(fn($r)=>$r[0],$rows));
  if(count($names)>=2){
    $reused++;
    echo "\n[SHARED PARAGRAPH TEMPLATE across ".count($names)." categories: ".implode(', ',$names)."]\n";
    foreach($rows as $r){ echo "   (".$r[0].") ".substr($r[1],0,140)."...\n"; }
  }
}
echo "\n>>> $reused shared paragraph templates appear across 2+ categories (of $total_shapes distinct paragraph shapes)\n";

// Heading reuse
echo "\n===================== CROSS-CATEGORY HEADING REUSE =====================\n";
$byhead=[];
foreach($pages as $name=>$html){
  preg_match_all('/<h([2-6])[^>]*>(.*?)<\/h\1>/is',$html,$hm);
  foreach($hm[2] as $h){
    $ht=trim(strip_tags($h)); $shape=strtolower($ht);
    foreach($catnames as $cn){ $shape=str_ireplace(strtolower($cn),'{CAT}',$shape); }
    foreach($catwords as $w){ $shape=preg_replace('/\b'.$w.'\b/i','',$shape); }
    $shape=preg_replace('/[^a-z0-9 ]+/i',' ',$shape); $shape=preg_replace('/\s+/',' ',trim($shape));
    if($shape==='') continue;
    $byhead[$shape][]=[$name,$ht];
  }
}
$hreused=0;
foreach($byhead as $shape=>$rows){
  $names=array_unique(array_map(fn($r)=>$r[0],$rows));
  if(count($names)>=2){ $hreused++;
    echo "\n[SHARED HEADING across ".count($names)." categories]\n";
    foreach($rows as $r){ echo "   (".$r[0].") ".$r[1]."\n"; }
  }
}
echo "\n>>> $hreused heading shapes shared across 2+ categories\n";
