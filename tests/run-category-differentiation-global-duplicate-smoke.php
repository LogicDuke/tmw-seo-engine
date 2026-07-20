<?php
/** Regression: exact duplicates are checked across the full retained store. */
declare(strict_types=1);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__); }
$dir = dirname(__DIR__) . '/includes/content/category-pipeline/';
require_once $dir . 'class-category-quality-guard.php';
require_once $dir . 'class-category-differentiation-scorer.php';
use TMWSEO\Engine\Content\CategoryPipeline\CategoryDifferentiationScorer;

$pass=0;$fail=0;function ok($label,$ok,$detail=''){global $pass,$fail; echo ($ok?'  ok  ':'  FAIL ').$label.($detail!==''?' — '.$detail:'')."\n"; $ok?$pass++:$fail++;}
function page_html(string $name, string $slug, int $seed): string {
    $topic = strtolower($name);
    $paras = [];
    for ($i=0;$i<7;$i++) {
        $paras[] = "<p>{$name} guide paragraph {$i} explains {$topic} browsing cues, safety checks, profile review habits, and comparison details for readers using seed {$seed} with durable wording.</p>";
    }
    return "<h2>{$name} Overview</h2>".implode('', $paras)."<h2>Frequently Asked Questions</h2><h3>How should readers compare {$topic}?</h3><p>Readers can compare profile context, update cadence, visible details, and navigation signals before choosing where to continue.</p>";
}
function fp(string $html, string $slug): array { return CategoryDifferentiationScorer::fingerprint($html, [], $slug); }

$aHtml = page_html('Ancient Duplicate Cams','ancient-duplicate-cams',1);
$a = fp($aHtml, 'source-a');
$store = [$a];
for ($i=0;$i<14;$i++) { $store[] = fp(page_html('Newer Matrix '.$i, 'newer-'.$i, $i+10), 'newer-'.$i); }
$windowOnly = array_slice($store, -8);
$oldOutsideWindow = !in_array('source-a', array_map(static fn($x)=>(string)$x['slug'], $windowOnly), true);
ok('source A falls outside the adaptive soft window', $oldOutsideWindow);

$dup = CategoryDifferentiationScorer::score(fp($aHtml, 'candidate-duplicate'), $store);
ok('older exact duplicate is rejected globally', empty($dup['passed']), json_encode($dup));
ok('duplicate failure identifies source A', ($dup['duplicate_source'] ?? '') === 'source-a' && ($dup['worst_source'] ?? '') === 'source-a', json_encode($dup));
ok('duplicate failure uses exact body reason', ($dup['duplicate_type'] ?? '') === 'exact_body_reuse' && strpos((string)($dup['failure_reason'] ?? ''), 'source-a') !== false, json_encode($dup));

$nearHtml = str_replace(['Ancient Duplicate Cams guide paragraph 0','seed 1'], ['Older Similar Cams opener paragraph zero','seed 77'], $aHtml);
$near = CategoryDifferentiationScorer::score(fp($nearHtml, 'candidate-near'), $store);
ok('near-but-not-exact older page is not failed by global exact scan', ($near['duplicate_type'] ?? '') === '', json_encode($near));
ok('near older page follows adaptive soft-similarity rules', !empty($near['passed']), json_encode($near));

$distinct = CategoryDifferentiationScorer::score(fp(page_html('Distinct Orchard Rooms','distinct-orchard-rooms',300), 'candidate-distinct'), $store);
ok('genuinely distinct page passes', !empty($distinct['passed']), json_encode($distinct));

$reordered = array_merge(array_slice($store, 1, 7), [$a], array_slice($store, 8));
$dupReordered = CategoryDifferentiationScorer::score(fp($aHtml, 'candidate-duplicate-reordered'), $reordered);
ok('store order does not change exact duplicate protection', empty($dupReordered['passed']) && ($dupReordered['duplicate_source'] ?? '') === 'source-a', json_encode($dupReordered));

if($fail){echo "FAILED {$fail} checks\n"; exit(1);} echo "PASS {$pass} checks\n";
