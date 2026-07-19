<?php
/**
 * Active keyword contract suite — v5.9.15.
 *
 * Proves the universal selected-keyword contract end to end on the pure
 * (WordPress-free) layer:
 *
 *   A. active_set() on the SIX real July-2026 production chip sets matches
 *      the live PDF present/absent classification keyword-by-keyword; the
 *      active set contains no duplicates even when fed the duplicated
 *      Selected-Keywords UI listing; covered_by_primary triggers only on
 *      genuine contiguous containment.
 *   B. Full pipeline generation for each real category with stored_chips =
 *      the active set: every active keyword appears EXACT in visible content
 *      AND in an H2-H6 subheading (or is covered by the primary), density
 *      stays within the accepted band, readiness-style coverage passes.
 *   C. IndexReadinessGate::category_active_chip_coverage(): passes on the
 *      generated pages, fails with a named keyword+reason when an active
 *      chip is missing, credits containment only when the primary itself is
 *      placed, and passes trivially for primary-only CSVs.
 *   D. Future-category shape matrix (2/4/8 keywords, same-family-heavy,
 *      contained, no-containment, long multi-word, sparse, synthetic
 *      unknown): the active set always generates with full coverage.
 *   E. No category-name or slug-specific branches exist in the changed
 *      pipeline/gate sources.
 *
 * Run: php tests/run-category-active-chip-contract.php
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/'); }

// ── Minimal WP shims (mirrors the other category smokes) ────────────────────
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
    'class-category-context-builder', 'class-category-intent-classifier', 'class-category-keyword-planner',
    'class-category-chip-feasibility',
    'class-category-content-planner', 'class-category-draft-composer', 'class-category-quality-guard',
    'class-category-factual-safety', 'class-category-grammar-guard', 'class-category-density-policy', 'class-category-keyword-placement',
    'class-category-paragraph-uniqueness-guard', 'class-category-claim-ledger', 'class-category-specificity-scorer',
    'class-category-faq-reuse-guard', 'class-category-generation-result', 'class-category-differentiation-scorer',
    'class-category-faq-planner', 'class-category-final-validator', 'class-category-generation-pipeline',
] as $c) { require_once $pipeline_dir . $c . '.php'; }

use TMWSEO\Engine\Content\CategoryPipeline\CategoryChipFeasibility;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryContextBuilder;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationPipeline;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryQualityGuard;

$pass = 0; $fail = 0; $failures = [];
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  ok  $label\n"; }
    else { $fail++; $failures[] = $label . ($detail !== '' ? " — $detail" : ''); echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function kwpat(string $kw): string { return '/(?<![\p{L}\p{N}])' . preg_quote($kw, '/') . '(?![\p{L}\p{N}])/iu'; }
function vis(string $html): string {
    $t = (string) preg_replace(['/<script\b[^>]*>.*?<\/script>/isu', '/<style\b[^>]*>.*?<\/style>/isu'], ' ', $html);
    $t = (string) preg_replace('/<[^>]+>/u', ' ', $t);
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}
function subs(string $html): string {
    if (!preg_match_all('/<h([2-6])[^>]*>(.*?)<\/h\1>/isu', $html, $m)) { return ''; }
    $t = (string) preg_replace('/<[^>]+>/u', ' ', implode("\n", $m[2]));
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

// The pure coverage helper lives in the WP-coupled gate class; extract and
// evaluate it standalone so this suite needs no WordPress.
$gate_src = (string) file_get_contents(dirname(__DIR__) . '/includes/content/class-index-readiness-gate.php');
check('gate exposes category_active_chip_coverage()', strpos($gate_src, 'public static function category_active_chip_coverage') !== false);
if (!preg_match('/public static function category_active_chip_coverage\(.*?\n    \}/s', $gate_src, $gm)) {
    echo "FATAL: cannot extract coverage helper\n"; exit(1);
}
eval('namespace TMWSEO\Engine\Content; class IndexReadinessGateCoverageOnly { ' . $gm[0] . ' }');
$coverage = static function (string $html, string $csv): array {
    return \TMWSEO\Engine\Content\IndexReadinessGateCoverageOnly::category_active_chip_coverage($html, $csv);
};

// ── Fixtures: the six real production categories (July 2026 PDF) ────────────
$real = [
    'big-boob-cam' => ['Big Boob Cam',
        ['big breast webcam','big boobs webcam','biggest boobs webcam','massive boob webcam','massive boobs cam','massive breasts webcam','big boobs live cam','big boobs live camera'],
        // Expected classification proven identical to the live PDF pages.
        ['active' => ['big breast webcam','big boobs webcam','big boobs live camera'],
         'excluded' => ['biggest boobs webcam','massive boob webcam','massive boobs cam','massive breasts webcam','big boobs live cam'],
         'covered' => []]],
    'blonde-cam-models' => ['Blonde Cam Models',
        ['blonde cams','blonde live sex','blonde sex cam','blonde live webcam','live blonde cams','blonde nude cam','free blonde cams','blonde live chat'],
        ['active' => ['blonde cams','blonde live sex','blonde sex cam','blonde live webcam','blonde nude cam','blonde live chat'],
         'excluded' => ['live blonde cams','free blonde cams'],
         'covered' => []]],
    'latina-cam-models' => ['Latina Cam Models',
        ['latina sex cam','latina live sex','live latina cams','latina nude webcam','latina sex chat','latina live nude','latina live webcam','latina cam nude'],
        ['active' => ['latina sex cam','latina live sex','live latina cams','latina nude webcam','latina sex chat','latina live webcam','latina cam nude'],
         'excluded' => ['latina live nude'],
         'covered' => []]],
    'free-cam-chat' => ['Free Cam Chat',
        ['free cam to cam chat','free live cams','free webcam chat','cam to cam chat','cam chat rooms','webcam chat rooms','free live cam chat','free webcam shows'],
        ['active' => ['free cam to cam chat','free live cams','free webcam chat','free webcam shows'],
         'excluded' => ['cam to cam chat','cam chat rooms','webcam chat rooms','free live cam chat'],
         'covered' => []]],
    'amateur-cams' => ['Amateur Cams',
        ['amateur webcam','amateur tv cams','live amateur sex cams','amateur sex chat','amateur live cams'],
        ['active' => ['amateur webcam','amateur tv cams','live amateur sex cams','amateur sex chat','amateur live cams'],
         'excluded' => [],
         'covered' => []]],
    'live-anal-cams' => ['Live Anal Cams',
        ['anal cams','live anal webcam','free anal cams','live anal chat','anal chat cam','best anal cams','free anal webcams','free live anal'],
        ['active' => ['anal cams','live anal webcam','free anal cams','live anal chat','anal chat cam','free live anal'],
         'excluded' => ['best anal cams','free anal webcams'],
         'covered' => ['anal cams']]],
];

echo "== A. active_set() reproduces the live production classification ==\n";
foreach ($real as $slug => [$name, $chips, $expect]) {
    $set = CategoryChipFeasibility::active_set($name, $chips);
    check("[$slug] active set matches live classification", $set['active'] === $expect['active'], json_encode($set['active']));
    $excluded = array_map(static fn($r) => (string) $r['keyword'], (array) $set['excluded']);
    check("[$slug] excluded set matches live classification", $excluded === $expect['excluded'], json_encode($excluded));
    foreach ((array) $set['excluded'] as $row) {
        check("[$slug] excluded '{$row['keyword']}' carries a machine reason", (string) $row['reason'] !== '');
    }
    $covered = array_map(static fn($r) => (string) $r['keyword'], (array) $set['covered']);
    check("[$slug] covered-by-primary set matches", $covered === $expect['covered'], json_encode($covered));
    check("[$slug] active + excluded partition the selected set (nothing silently lost)",
        count($set['active']) + count($excluded) === count(array_unique(array_map('strtolower', $chips))));
    check("[$slug] active set has no duplicates",
        count($set['active']) === count(array_unique(array_map('strtolower', $set['active']))));
}

echo "\n== A2. Duplicated Selected-Keywords UI input de-duplicates ==\n";
$dup_input = array_merge($real['big-boob-cam'][1], array_slice($real['big-boob-cam'][1], 0, 6)); // the PDF's 8+6 listing
$dup_set   = CategoryChipFeasibility::active_set('Big Boob Cam', $dup_input);
check('duplicated selection collapses to the same active set', $dup_set['active'] === $real['big-boob-cam'][2]['active'], json_encode($dup_set['active']));
check('duplicated selection produces no duplicate exclusions',
    count(array_map(static fn($r) => strtolower((string) $r['keyword']), (array) $dup_set['excluded']))
    === count(array_unique(array_map(static fn($r) => strtolower((string) $r['keyword']), (array) $dup_set['excluded']))));

echo "\n== A3. covered_by_primary requires genuine contiguous containment ==\n";
$anal = CategoryChipFeasibility::active_set('Live Anal Cams', ['anal cams', 'live anal', 'cams live']);
$anal_cov = array_map(static fn($r) => (string) $r['keyword'], (array) $anal['covered']);
check("'anal cams' is covered by 'Live Anal Cams' (contiguous)", in_array('anal cams', $anal_cov, true), json_encode($anal_cov));
check("'live anal' is covered by 'Live Anal Cams' (contiguous)", in_array('live anal', $anal_cov, true));
check("'cams live' is NOT covered (non-contiguous token order)", !in_array('cams live', $anal_cov, true));
$bbc_cov = CategoryChipFeasibility::active_set('Big Boob Cam', ['massive boobs cam']);
check("'massive boobs cam' is NOT covered by 'Big Boob Cam' (plural mutation forbidden)",
    empty($bbc_cov['covered']), json_encode($bbc_cov['covered']));

echo "\n== B. Full pipeline: every ACTIVE keyword lands exact (content + H2-H6) ==\n";
$base = [
    'site_name'  => 'Top-Models.Webcam',
    'models_url' => 'https://top-models.webcam/webcam-models/',
    'videos_url' => 'https://top-models.webcam/webcam-videos/',
];
$rel = static fn(array $names): array => array_map(static fn($n) => ['name' => $n, 'url' => 'https://top-models.webcam/category/' . strtolower(str_replace(' ', '-', $n)) . '/'], $names);
$related = [
    'big-boob-cam' => ['Amateur Cams', 'Blonde Cam Models'],
    'blonde-cam-models' => ['Amateur Cams', 'Big Boob Cam'],
    'latina-cam-models' => ['Amateur Cams', 'Big Boob Cam'],
    'free-cam-chat' => ['Amateur Cams', 'Live Anal Cams'],
    'amateur-cams' => ['Big Boob Cam', 'Free Cam Chat'],
    'live-anal-cams' => ['Amateur Cams', 'Big Boob Cam'],
];
$cmp = []; $generated_pages = [];
foreach ($real as $slug => [$name, $chips, $expect]) {
    $set = CategoryChipFeasibility::active_set($name, $chips);
    $active = (array) $set['active'];
    $ctx = CategoryContextBuilder::build_from_parts($base + [
        'category_slug' => $slug, 'category_name' => $name, 'primary_keyword' => $name,
        'approved_keywords' => $active, 'stored_chips' => $active,
        'related_categories' => $rel($related[$slug]), 'model_count' => 24, 'video_count' => 18,
    ]);
    $out = CategoryGenerationPipeline::generate_from_context($ctx, ['tracking' => $active, 'use_store' => false, 'comparisons' => $cmp]);
    check("[$slug] generates ok with the active set", (bool) $out['ok'], implode('; ', array_slice((array) ($out['report']['failure_reasons'] ?? []), 0, 4)));
    if (!$out['ok']) { continue; }
    $html = (string) $out['html'];
    if (!empty($out['report']['fingerprint'])) { $cmp[] = $out['report']['fingerprint']; }
    $generated_pages[$slug] = $html;
    $visible = vis($html); $subtxt = subs($html);
    $covered_names = array_map(static fn($r) => strtolower((string) $r['keyword']), (array) $set['covered']);
    foreach ($active as $kw) {
        $is_covered = in_array(strtolower($kw), $covered_names, true);
        $in_content = (bool) preg_match(kwpat($kw), $visible);
        $in_sub     = (bool) preg_match(kwpat($kw), $subtxt);
        check("[$slug] active '$kw' EXACT in visible content" . ($is_covered ? ' (via primary containment)' : ''), $in_content);
        check("[$slug] active '$kw' present in an H2-H6 subheading" . ($is_covered ? ' (via primary containment)' : ''), $in_sub);
    }
    $den = (array) (($out['report']['metrics'] ?? [])['density'] ?? []);
    check("[$slug] density within accepted band", ($den['status'] ?? '') === 'within', json_encode($den));
    // Rank Math score guard: the chip report set == exact active CSV set.
    $stored_report = (array) (($out['report']['metrics'] ?? [])['stored_keyword_report'] ?? []);
    $report_set = array_map('strtolower', array_keys($stored_report));
    $active_csv = array_map('strtolower', array_merge([$name], $active));
    sort($report_set); sort($active_csv);
    check("[$slug] stored-keyword report covers EXACTLY the active CSV set", $report_set === $active_csv, json_encode([$report_set, $active_csv]));
    foreach ($stored_report as $kw => $row) {
        check("[$slug] report row '$kw' passes (no unused active keyword)", !empty($row['pass']), json_encode($row));
    }
}

echo "\n== C. Readiness coverage gate (fail-closed on unused active chips) ==\n";
foreach ($generated_pages as $slug => $html) {
    [$name, $chips, $expect] = $real[$slug];
    $set = CategoryChipFeasibility::active_set($name, $chips);
    $csv = implode(',', array_merge([$name], (array) $set['active']));
    $cov = $coverage($html, $csv);
    check("[$slug] readiness coverage PASSES on the generated page", (bool) $cov['passed'], json_encode($cov['failures']));
}
// Negative: the OLD live state — full 8-chip CSV against the page generated
// from the active set — must FAIL with the demoted chips named.
$old_csv = implode(',', array_merge(['Big Boob Cam'], $real['big-boob-cam'][1]));
$old_cov = $coverage($generated_pages['big-boob-cam'] ?? '', $old_csv);
$named   = array_map(static fn($f) => (string) $f['keyword'], (array) $old_cov['failures']);
check('stale 8-chip CSV FAILS readiness coverage (hidden third state impossible)', !$old_cov['passed'] && count($named) >= 4, json_encode($named));
check("failure names 'massive boobs cam' with a reason", in_array('massive boobs cam', $named, true));
// Covered credit requires the primary itself placed.
$cov_html = '<p>Live Anal Cams opens here with detail.</p><h2>Live Anal Cams Overview</h2><p>Body text.</p>';
$cov_ok   = $coverage($cov_html, 'Live Anal Cams,anal cams');
check('contained chip passes via primary in content + subheading', (bool) $cov_ok['passed'], json_encode($cov_ok));
$cov_html2 = '<p>General text without the phrase.</p><h2>Browsing Overview</h2>';
$cov_bad   = $coverage($cov_html2, 'Live Anal Cams,anal cams');
check('contained chip FAILS when the primary is absent', !$cov_bad['passed']);
check('primary-only CSV passes trivially', (bool) $coverage('<p>Anything.</p>', 'Big Boob Cam')['passed']);
check('empty CSV passes trivially', (bool) $coverage('<p>Anything.</p>', '')['passed']);

echo "\n== D. Future-category shape matrix (active set always generates) ==\n";
$matrix = [
    'two-keywords' => ['Plum Orchard Cams', ['plum orchard webcam', 'orchard room chat']],
    'four-keywords' => ['Quiet Harbor Rooms', ['quiet harbor webcam', 'harbor room chat', 'quiet harbor shows', 'harbor evening cams']],
    'eight-keywords' => ['Copper Valley Cams', ['copper valley webcam', 'valley room chat', 'copper valley shows', 'valley evening cams', 'copper ridge chat', 'valley morning shows', 'copper valley rooms', 'ridge evening webcam']],
    'same-family-heavy' => ['Willow Creek Cam', ['willow creek webcam', 'willow creek cams', 'big willow creek cam', 'massive willow creek webcam', 'willow creek live cam']],
    'contained-set' => ['Live Cedar Grove Cams', ['cedar grove cams', 'live cedar grove', 'cedar grove webcam']],
    'no-containment' => ['Aspen Meadow Chat', ['meadow evening rooms', 'aspen morning shows', 'meadow valley webcam']],
    'long-multiword' => ['Free Amber Hollow Evening Chat Rooms', ['amber hollow chat', 'amber evening rooms', 'hollow evening webcam']],
    'sparse' => ['Fern Hollow Cams', []],
    'synthetic-unknown' => ['Zylkor Prism Rooms', ['zylkor prism webcam', 'prism room chat', 'zylkor evening shows']],
];
$cmp2 = [];
foreach ($matrix as $shape => [$name, $chips]) {
    $slug = strtolower(str_replace(' ', '-', $name));
    $set = CategoryChipFeasibility::active_set($name, $chips);
    check("[$shape] no selected keyword is lost from the contract",
        count($set['active']) + count($set['excluded']) === count(array_unique(array_map('strtolower', $chips))));
    $active = (array) $set['active'];
    $ctx = CategoryContextBuilder::build_from_parts($base + [
        'category_slug' => $slug, 'category_name' => $name, 'primary_keyword' => $name,
        'approved_keywords' => $active, 'stored_chips' => $active,
        'related_categories' => $rel(['Amateur Cams', 'Free Cam Chat']), 'model_count' => 12, 'video_count' => 9,
    ]);
    $out = CategoryGenerationPipeline::generate_from_context($ctx, ['tracking' => $active, 'use_store' => false, 'comparisons' => $cmp2]);
    check("[$shape] generates ok", (bool) $out['ok'], implode('; ', array_slice((array) ($out['report']['failure_reasons'] ?? []), 0, 4)));
    if (!$out['ok']) { continue; }
    if (!empty($out['report']['fingerprint'])) { $cmp2[] = $out['report']['fingerprint']; }
    $html = (string) $out['html'];
    $csv  = implode(',', array_merge([$name], $active));
    $cov  = $coverage($html, $csv);
    check("[$shape] readiness coverage passes for the full active CSV", (bool) $cov['passed'], json_encode($cov['failures']));
}

echo "\n== E. No category-specific branches in the contract sources ==\n";
$sources = [
    'includes/content/category-pipeline/class-category-chip-feasibility.php',
    'includes/content/category-pipeline/class-category-keyword-planner.php',
    'includes/content/category-pipeline/class-category-generation-pipeline.php',
    'includes/content/class-index-readiness-gate.php',
];
$slugs = ['big-boob-cam', 'blonde-cam-models', 'latina-cam-models', 'free-cam-chat', 'amateur-cams', 'live-anal-cams',
          'Big Boob Cam', 'Blonde Cam Models', 'Latina Cam Models', 'Free Cam Chat', 'Amateur Cams', 'Live Anal Cams'];
foreach ($sources as $file) {
    $src = (string) file_get_contents(dirname(__DIR__) . '/' . $file);
    $hits = array_values(array_filter($slugs, static fn($s) => stripos($src, $s) !== false));
    check(basename($file) . ' contains no category name or slug', empty($hits), json_encode($hits));
}

echo "\n============================================================\n";
echo 'PASS: ' . $pass . '  FAIL: ' . $fail . "\n";
if ($fail > 0) { foreach ($failures as $f) { echo " - $f\n"; } exit(1); }
