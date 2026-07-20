<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__); }
$dir = dirname(__DIR__) . '/includes/content/category-pipeline/';
require_once $dir . 'class-category-keyword-planner.php';
require_once $dir . 'class-category-chip-feasibility.php';
require_once $dir . 'class-category-semantic-profile.php';
require_once $dir . 'class-category-semantic-sections.php';
require_once $dir . 'class-category-interchangeability-guard.php';
use TMWSEO\Engine\Content\CategoryPipeline\CategoryChipFeasibility;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryKeywordPlanner;
$pass=0;$fail=0;function ok($l,$b,$d=''){global $pass,$fail; echo ($b?"  ok  ":"  FAIL ").$l.($d?" — $d":"")."\n"; $b?$pass++:$fail++;}
$blonde=['blonde cams','blonde live cam','blonde live webcam','live blonde cams','free blonde cams','blonde cam girls','blonde model cams'];
$r=CategoryChipFeasibility::analyze('Blonde Cam Models',$blonde);
ok('Blonde seven-chip set feasible via rendered subset',(bool)$r['feasible']);
ok('Blonde demotes same-family variants',count($r['tracking_only_chips'])>=3);
ok('Blonde family collapses live/free/webcam variants',count($r['family_groups']['blonde cam'] ?? [])>=5);
ok('Required fields present',empty(array_diff(['feasible','failure_code','reasons','rendered_chips','tracking_only_chips','family_groups','family_limits','projected_min_matches','projected_density','heading_demand','faq_demand'],array_keys($r))));
$latina=CategoryChipFeasibility::analyze('Latina Cam Models',['latina cams','latina live cam','latina webcam','spanish cam girls']);
ok('Latina four-chip set renders safely',count($latina['rendered_chips'])>=3);
$curvy=CategoryChipFeasibility::analyze('Curvy Cam Models',['curvy cams','curvy live cam','curvy webcam','free curvy cams']);
ok('Curvy same-family tracking reported',count($curvy['tracking_only_chips'])>=2);
$red=CategoryChipFeasibility::analyze('Redhead Cam Models',['redhead cams','redhead live cam','redhead webcam','free redhead cams','live redhead cams','redhead cam girls']);
ok('Redhead six-chip same-family capped',count($red['rendered_chips'])<=4 && count($red['tracking_only_chips'])>=2);
$distinct=CategoryChipFeasibility::analyze('Free Cam Chat',['free cam chat','live webcam rooms','private cam shows','adult video chat','mobile cam sites','cam model tips']);
ok('Distinct family set keeps broad coverage',count($distinct['rendered_chips'])>=4);
ok('Zero-chip set is feasible',CategoryChipFeasibility::analyze('Zero',[])['feasible']);
ok('One-chip set is feasible',CategoryChipFeasibility::analyze('One',['single cam'])['feasible']);
ok('Long multi-word family normalizes deterministically',CategoryKeywordPlanner::root_family('Best Long Multi Word Category Live Webcams')===CategoryKeywordPlanner::root_family('Long Multi Word Category Webcam'));
if($fail){echo "FAILED {$fail} checks\n"; exit(1);} echo "PASS {$pass} checks\n";
