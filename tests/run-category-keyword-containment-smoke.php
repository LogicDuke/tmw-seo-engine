<?php
declare(strict_types=1);
error_reporting(E_ALL);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__); }
if (!defined('TMWSEO_ENGINE_DATA_DIR')) { define('TMWSEO_ENGINE_DATA_DIR', dirname(__DIR__) . '/data'); }
if (!function_exists('esc_html')) { function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } }
if (!function_exists('esc_url')) { function esc_url($u) { return (string) $u; } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s) { return strip_tags((string) $s); } }
$GLOBALS['_tmw_options'] = [];
if (!function_exists('get_option')) { function get_option($key, $default = false) { return $GLOBALS['_tmw_options'][$key] ?? $default; } }
if (!function_exists('update_option')) { function update_option($key, $value, $autoload = null) { $GLOBALS['_tmw_options'][$key] = $value; return true; } }
$pd = dirname(__DIR__) . '/includes/content/category-pipeline/';
foreach (['class-category-keyword-planner','class-category-chip-feasibility','class-category-quality-guard','class-category-factual-safety','class-category-density-policy','class-category-keyword-placement','class-category-grammar-guard','class-category-final-validator'] as $c) { require_once $pd . $c . '.php'; }
require_once dirname(__DIR__) . '/includes/content/class-rank-math-chip-analyzer.php';
use TMWSEO\Engine\Content\CategoryPipeline\CategoryQualityGuard;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryChipFeasibility;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryKeywordPlanner;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryFinalValidator;
use TMWSEO\Engine\Content\RankMathChipAnalyzer;
$pass=0; $fail=0;
function ok($label,$cond,$detail=''){global $pass,$fail; if($cond){$pass++; echo "  ok  $label\n";}else{$fail++; echo "  FAIL $label".($detail?" — $detail":"")."\n";}}
$keywords=['Live Anal Cams','anal cams'];
ok('contained phrase is not double counted at same position', CategoryQualityGuard::count_exact_keywords('Live Anal Cams', $keywords) === 1);
$rm = RankMathChipAnalyzer::analyze(['content'=>'Live Anal Cams','keywords_csv'=>implode(',', $keywords)]);
$chipCounts = array_column($rm['keywords'], 'exact_count', 'keyword');
ok('Rank Math chip counts remain unchanged', (int)($chipCounts['Live Anal Cams'] ?? 0) === 1 && (int)($chipCounts['anal cams'] ?? 0) === 1, json_encode($rm));
$feas=CategoryChipFeasibility::analyze('Live Anal Cams',['anal cams','free anal cams']);
ok('contained chip marked covered_by_primary', ($feas['covered_by_primary_chips'][0]['keyword'] ?? '') === 'anal cams');
$rendered=array_column($feas['rendered_chips'],'keyword');
ok('covered chip receives no separate placement role', !in_array('anal cams',$rendered,true));
$html='<h2>What to Expect From Live Anal Cams</h2><p>Live Anal Cams sets the context for comparing listings and individual pages. Model pages explain format and approach for each performer.</p><h2>Browse Listings</h2><p>Model listings and video listings answer different questions.</p><h2>Compare Profiles</h2><p>Compare stated details before following a link.</p><h2>Frequently Asked Questions</h2><h3>What matters first?</h3><p>Read each listing carefully.</p><h3>How should visitors compare?</h3><p>Compare format and approach.</p><h3>Where do details live?</h3><p>Individual pages carry the detail.</p>';
$words=str_repeat(' detail', 630); $html=str_replace('</p><h2>Browse', $words.'</p><h2>Browse', $html);
$plan=['primary'=>'Live Anal Cams','rankmath_tracking'=>['Live Anal Cams','anal cams'],'body_use'=>[],'roles'=>[],'enforced_stored_chips'=>true,'density_tracking'=>['Live Anal Cams','anal cams'],'chip_feasibility'=>$feas];
$ctx=['primary_keyword'=>'Live Anal Cams','verified_flags'=>[]];
$res=CategoryFinalValidator::validate($html,$ctx,$plan,[],['specificity'=>['passed'=>true,'intent_paragraphs'=>3],'claim_ledger'=>['passed'=>true],'uniqueness'=>['passed'=>true]]);
$report=$res['metrics']['stored_keyword_report']['anal cams'] ?? [];
ok('final validator accepts contained chip coverage via primary', !empty($report['pass']) && !empty($report['covered_by_primary']), json_encode($report));
$issues=CategoryQualityGuard::analyze('<h2>What to Expect From German Live Cams</h2><p>Within German Live Cams, compare stated details.</p>',['German Live Cams']);
ok('German X within X rejected by explicit duplicate phrase rule', in_array('duplicate_tracked_phrase', array_column($issues,'type'), true), json_encode($issues));
$issues=CategoryQualityGuard::analyze('<h2>What to Expect From German Live Cams Within German Live Cams</h2><p>Compare stated details.</p>',['German Live Cams']);
ok('nested redundant heading construction rejected', in_array('duplicate_tracked_phrase', array_column($issues,'type'), true), json_encode($issues));
echo "Keyword containment smoke: $pass passed, $fail failed\n";
exit($fail>0?1:0);
