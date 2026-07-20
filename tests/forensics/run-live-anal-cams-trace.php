<?php
/**
 * FORENSIC TRACE — "Live Anal Cams" keyword_dump_sentence after PR #763,
 * plus the 10-category unseen-name universality matrix.
 *
 * For every attempt: the chosen section list, variant IDs, each H2 with the
 * first sentence that follows it, the exact-keyword count of the GLUED
 * heading+sentence pseudo-sentence (exactly as CategoryQualityGuard::sentences()
 * sees it), and the validator reasons — proving whether retries change the
 * failing geometry or only the salt.
 */
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
$pd = dirname(__DIR__, 2) . '/includes/content/category-pipeline/';
foreach ([
    'class-category-context-builder','class-category-intent-classifier','class-category-keyword-planner',
    'class-category-chip-feasibility', 'class-category-semantic-profile', 'class-category-semantic-sections', 'class-category-interchangeability-guard','class-category-content-planner','class-category-draft-composer',
    'class-category-quality-guard','class-category-factual-safety','class-category-grammar-guard',
    'class-category-density-policy','class-category-keyword-placement','class-category-paragraph-uniqueness-guard',
    'class-category-claim-ledger','class-category-specificity-scorer','class-category-faq-reuse-guard',
    'class-category-generation-result','class-category-differentiation-scorer','class-category-faq-planner',
    'class-category-final-validator','class-category-generation-pipeline',
] as $c) { require_once $pd . $c . '.php'; }
require_once dirname(__DIR__, 2) . '/includes/content/class-rank-math-chip-analyzer.php';
use TMWSEO\Engine\Content\CategoryPipeline\CategoryContextBuilder;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationPipeline;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryChipFeasibility;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryQualityGuard;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryDensityPolicy;

$base = ['site_name'=>'Top-Models.Webcam','models_url'=>'https://top-models.webcam/webcam-models/','videos_url'=>'https://top-models.webcam/webcam-videos/','model_count'=>3244,'video_count'=>2871];
$rel = [['name'=>'Amateur Cams','url'=>'https://top-models.webcam/category/amateur-cams/'],['name'=>'Free Cam Chat','url'=>'https://top-models.webcam/category/free-cam-chat/']];

/** Heading + glued first sentence report for a rendered draft. */
function glued_pairs(string $html, array $keywords): array {
    $out = [];
    if (!preg_match_all('/<h2[^>]*>(.*?)<\/h2>\s*<p[^>]*>(.*?)<\/p>/isu', $html, $m, PREG_SET_ORDER)) { return $out; }
    foreach ($m as $pair) {
        $h = trim(strip_tags($pair[1]));
        $p = trim(strip_tags($pair[2]));
        $first = preg_split('/(?<=[.!?])\s+/u', $p)[0] ?? '';
        $glued = $h . ' ' . $first;
        $out[] = ['h2'=>$h, 'first'=>$first, 'glued_exacts'=>CategoryQualityGuard::count_exact_keywords($glued, $keywords)];
    }
    return $out;
}

$cats = [
    'live-anal-cams'   => ['Live Anal Cams',   ['anal cams','live anal webcam','free anal cams','live anal chat','anal chat cam','best anal cams','free anal webcams']],
    'free-anal-cams'   => ['Free Anal Cams',   ['anal cams','free anal webcam','live anal cams','anal cam chat']],
    'live-fetish-cams' => ['Live Fetish Cams', ['fetish cams','live fetish chat','fetish webcam shows','free fetish cams']],
    'live-blonde-cams' => ['Live Blonde Cams', ['blonde cams','live blonde chat','blonde webcam shows','free blonde cams']],
    'webcam-couples'   => ['Webcam Couples',   ['webcam couple shows','couple cams','live couple chat','couples webcam']],
    'german-live-cams' => ['German Live Cams', ['german cams','live german chat','german webcam models','free german cams']],
    'curvy-live-cams'  => ['Curvy Live Cams',  ['curvy cams','live curvy chat','curvy webcam models','free curvy cams']],
    'free-webcam-chat' => ['Free Webcam Chat', ['webcam chat','free cam chat','live webcam chat rooms','webcam chat sites']],
    // full category name inside a supporting keyword:
    'anal-cams'        => ['Anal Cams',        ['live anal cams','free anal cams','anal cams online','best anal cams']],
    // singular/plural + webcam/cam collapse to one family:
    'blonde-webcams'   => ['Blonde Webcams',   ['blonde webcam','blonde cams','blonde cam','blonde webcams live']],
];

foreach ($cats as $slug => [$name, $kws]) {
    $GLOBALS['__opts'] = []; // isolate: this audit targets template geometry, not store saturation
    $feas = CategoryChipFeasibility::analyze($name, $kws);
    $fx = $base + ['category_slug'=>$slug,'category_name'=>$name,'primary_keyword'=>$name,'approved_keywords'=>$kws,'stored_chips'=>$kws,'related_categories'=>$rel];
    $ctx = CategoryContextBuilder::build_from_parts($fx);
    CategoryDensityPolicy::set_final_context('', '');
    $res = CategoryGenerationPipeline::generate_from_context($ctx, ['tracking'=>array_slice($kws,0,8)]);
    CategoryDensityPolicy::clear_final_context();
    $rep = (array) $res['report'];
    $tracked = array_merge([$name], $kws);
    printf("== %-18s ok=%-3s attempts=%d intent=%s feasible=%s rendered=%d tracking_only=%d\n",
        $name, !empty($res['ok'])?'YES':'NO', (int)($rep['attempt_count']??0), (string)($rep['intent']??'?'),
        $feas['feasible']?'yes':'NO', count($feas['rendered_chips']), count($feas['tracking_only_chips']));
    foreach ((array)($rep['attempt_log'] ?? []) as $a) {
        $reasons = (array) $a['reasons'];
        $dump = implode('; ', array_filter($reasons, static fn($r) => str_contains((string)$r, 'keyword_dump')));
        printf("   salt=%d passed=%s sections=%s variants=%s\n", (int)$a['salt'], !empty($a['passed'])?'y':'n',
            implode(',', (array)($a['sections'] ?? [])), implode(',', array_slice((array)($a['variant_ids'] ?? []),0,6)));
        if ($reasons) { printf("     reasons: %s\n", implode(' | ', array_slice($reasons,0,4))); }
    }
    // Geometry of the LAST draft (pass or fail): the glued pairs the guard saw.
    // Re-compose the failing salt deterministically for a fail, or use final html.
    if (!empty($res['ok'])) {
        foreach (glued_pairs((string)$res['html'], $tracked) as $g) {
            if ($g['glued_exacts'] >= 3) printf("   !! surviving glued pair exacts=%d h2=\"%s\" first=\"%s\"\n", $g['glued_exacts'], $g['h2'], substr($g['first'],0,70));
        }
    }
    echo "\n";
}
