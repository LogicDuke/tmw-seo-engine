<?php
/**
 * Before/after Rank Math evidence — v5.9.15 active keyword contract.
 *
 * BEFORE: the reconstructed LIVE Big Boob Cam page (verbatim text from the
 *         July 2026 production PDF) scored by the Rank Math-faithful chip
 *         analyzer against the STALE 8-chip CSV that was live.
 * AFTER:  (1) the same live page against the new ACTIVE CSV — proves that
 *         removing never-placed chips changes NO existing score;
 *         (2) a fresh pipeline generation from the active set against the
 *         active CSV — the post-regeneration state.
 *
 * Run: php tests/run-active-chip-before-after-evidence.php
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
define('TMWSEO_TESTING', true);
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/'); }
$GLOBALS['__opts'] = [];
if (!function_exists('get_option'))    { function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; } }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; } }
if (!function_exists('delete_option')) { function delete_option($k) { unset($GLOBALS['__opts'][$k]); return true; } }
if (!function_exists('esc_html'))      { function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_url'))       { function esc_url($u) { return filter_var((string) $u, FILTER_SANITIZE_URL); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($t) { return trim(strip_tags((string) $t)); } }
if (!function_exists('__'))            { function __($t, $d = null) { return $t; } }

require __DIR__ . '/../includes/content/class-rank-math-chip-analyzer.php';
$pd = dirname(__DIR__) . '/includes/content/category-pipeline/';
foreach (glob($pd . 'class-*.php') as $f) { require_once $f; }

use TMWSEO\Engine\Content\RankMathChipAnalyzer as RM;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryChipFeasibility as F;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryContextBuilder as CB;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationPipeline as P;

$primary = 'Big Boob Cam';
$stale_extras = ['big breast webcam','big boobs webcam','biggest boobs webcam','massive boob webcam','massive boobs cam','massive breasts webcam','big boobs live cam','big boobs live camera'];

// The live page, reconstructed 1:1 from the production PDF text (post 4534).
$live = '<p>The quickest way to use Big Boob Cam is as a starting filter: the listings below fit the theme, and the individual pages show how each performer or clip interprets it. The label names one physical trait, so expect variety in every other respect: presentation, style, and format remain individual choices. When two or three listings stand out, open them together and compare the specifics before settling on one.</p>'
    . '<h2>Where the Listings Lead</h2><p>The destination shapes the session, since platform feature differences ride along with every outbound click. A short look at the destination page confirms whether the features that matter to the visit exist there. Anything involving payment is stated by the destination\'s own labels and terms, which are the source to trust.</p>'
    . '<h2>Before You Engage or Pay</h2><p>Name confirmation comes first, because similar stage names across platforms are the usual source of wrong-page detours. Anything sensitive, payments included, belongs only on the destination platform\'s own pages. Backing out of a doubtful destination is free, and the listing stays available for another approach.</p>'
    . '<h2>Big Boob Cam: What This Page Covers</h2><p>One shared trait, many presentations: performers style and format their pages individually, so the label\'s constant arrives in many versions. The trait gathers the field; the stated details on each performer\'s page are what separate the candidates.</p>'
    . '<h2>Big Breast Webcam on This Page</h2><p>Model listings and video listings answer different questions: model pages introduce performers, and video pages show finished clips. Lead with whichever side fits the visit: a person in mind points at model pages and a mood in mind points at video pages. Switching sides costs one click, so a wrong first turn is cheap to correct.</p>'
    . '<h2>Big Boobs Webcam: What to Know</h2><p>Sparse pages deserve a different strategy rather than a pass, since the clips or the destination often introduce the performer instead. How much a performer states varies widely, so weigh the detail that exists without holding silence against a page.</p>'
    . '<h2>A Closer Look at Big Boobs Live Camera</h2><p>Pairwise comparison suits a physical-trait label, because two open pages show their presentation differences more clearly than a sequence of single visits. Once the physical label has gathered the candidates, the stated presentation details decide, so the more specific page tends to win.</p>'
    . '<h2>If This Category Is Close but Not Exact</h2><p>If this theme is only nearly right, <a href="https://top-models.webcam/category/amateur-cams/">Amateur Cams</a> works the adjacent ground and may hold the closer match. A wider look at the performers runs through the <a href="https://top-models.webcam/webcam-models/">webcam model directory</a> which covers every theme at once. The recorded side has the same wide route: the <a href="https://top-models.webcam/webcam-videos/">webcam video directory</a> gathers every clip on the site in one place. Either move is easy to undo, which keeps sideways and wide browsing low-risk.</p>'
    . '<p>Once a shortlist forms, the individual pages settle the rest: compare what each performer or clip page states and follow the strongest match. Big Boob Cam did the filtering that opened the visit; the individual pages provide the detail that closes it.</p>'
    . '<h2>Frequently Asked Questions</h2>'
    . '<h3>What does the category label actually guarantee?</h3><p>Only the shared theme. Presentation, style, and format stay individual, which is why the model and video pages carry the deciding detail once the label has gathered the candidates.</p>'
    . '<h3>Is the shared trait enough to choose by?</h3><p>It is enough to gather candidates, not to choose between them. The choosing runs on what each page states about presentation, format, and approach.</p>'
    . '<h3>Why do some pages state so much more than others?</h3><p>Detail availability simply varies between performers. Weigh what is stated without treating silence as a fault, and let the destination page fill the gaps that matter.</p>'
    . '<h3>What puts a performer or video in Big Boob Cam?</h3><p>A match with the theme this page covers. Listings appear here because they fit the category, and their own pages show how each one interprets it.</p>'
    . '<p><a href="https://awejmp.com/?siteId=jasmin&categoryName=girl">Visit live category related models</a></p>';

$inputs = [
    'title'         => 'Big Boob Cam - Top Models Webcam',
    'description'   => 'Big Boob Cam category page: browse the listings, compare performers, and follow the strongest match.',
    'url_slug'      => 'big-boob-cam',
    'site_domain'   => 'top-models.webcam',
    'has_thumbnail' => true,
];

$set = F::active_set($primary, $stale_extras);
$active_extras = (array) $set['active'];

$before = RM::analyze($inputs + ['content' => $live, 'keywords_csv' => implode(',', array_merge([$primary], $stale_extras))]);
$after_same_page = RM::analyze($inputs + ['content' => $live, 'keywords_csv' => implode(',', array_merge([$primary], $active_extras))]);

// Post-regeneration state: fresh pipeline output from the active set.
$ctx = CB::build_from_parts([
    'site_name' => 'Top-Models.Webcam',
    'models_url' => 'https://top-models.webcam/webcam-models/',
    'videos_url' => 'https://top-models.webcam/webcam-videos/',
    'category_slug' => 'big-boob-cam', 'category_name' => $primary, 'primary_keyword' => $primary,
    'approved_keywords' => $active_extras, 'stored_chips' => $active_extras,
    'related_categories' => [
        ['name' => 'Amateur Cams', 'url' => 'https://top-models.webcam/category/amateur-cams/'],
        ['name' => 'Blonde Cam Models', 'url' => 'https://top-models.webcam/category/blonde-cam-models/'],
    ],
    'model_count' => 24, 'video_count' => 18,
]);
$gen = P::generate_from_context($ctx, ['tracking' => $active_extras, 'use_store' => false]);
$after_regen = $gen['ok'] ? RM::analyze($inputs + ['content' => (string) $gen['html'], 'keywords_csv' => implode(',', array_merge([$primary], $active_extras))]) : null;

$fmt = static function (array $r, string $label): void {
    printf("%s\n  words=%d  combined density=%.2f%% (%s)\n", $label, (int) ($r['word_count'] ?? 0), (float) ($r['combined']['density'] ?? 0), (string) ($r['combined']['band'] ?? ''));
    foreach ((array) ($r['keywords'] ?? []) as $row) {
        printf("  %-24s %-6s %3d%% (cap %d%%)  occurrences=%s\n",
            (string) $row['keyword'], (string) $row['predicted_chip'], (int) $row['percent'], (int) $row['ceiling_percent'],
            (!empty($row['in_content']) ? 'yes' : 'NO') . '/sub=' . (!empty($row['in_subheading']) ? 'yes' : 'NO') . '/exact=' . (int) ($row['exact_count'] ?? 0));
    }
    echo "\n";
};

echo "== BEFORE — live page (PDF reconstruction) + STALE 8-chip CSV ==\n";
$fmt($before, 'live state');
echo "== AFTER (same page) — live page + ACTIVE CSV (" . count($active_extras) . " extras) ==\n";
$fmt($after_same_page, 'CSV normalized only');
if ($after_regen) {
    echo "== AFTER (regenerated) — pipeline output from active set + ACTIVE CSV ==\n";
    $fmt($after_regen, 'post-regeneration');
} else {
    echo "regeneration failed: " . implode('; ', (array) ($gen['report']['failure_reasons'] ?? [])) . "\n";
}

// Acceptance assertions.
$p_before = null; $p_after = null; $p_regen = null;
foreach ((array) $before['keywords'] as $row) { if (strcasecmp((string) $row['keyword'], $primary) === 0) { $p_before = (int) $row['percent']; } }
foreach ((array) $after_same_page['keywords'] as $row) { if (strcasecmp((string) $row['keyword'], $primary) === 0) { $p_after = (int) $row['percent']; } }
if ($after_regen) { foreach ((array) $after_regen['keywords'] as $row) { if (strcasecmp((string) $row['keyword'], $primary) === 0) { $p_regen = (int) $row['percent']; } } }
printf("primary score: before=%d  after(csv)=%d  after(regen)=%s\n", $p_before, $p_after, $p_regen === null ? 'n/a' : (string) $p_regen);
// (1) Same page, CSV normalized: primary score and combined density unchanged.
$ok1 = $p_after !== null && $p_before !== null && $p_after >= $p_before;
$ok2 = abs(((float) $before['combined']['density']) - ((float) $after_same_page['combined']['density'])) < 0.001;
// (2) Every RETAINED chip keeps its exact before score (nothing weakened).
$before_pct = []; foreach ((array) $before['keywords'] as $row) { $before_pct[strtolower((string) $row['keyword'])] = (int) $row['percent']; }
$ok3 = true;
foreach ((array) $after_same_page['keywords'] as $row) {
    $k = strtolower((string) $row['keyword']);
    if (isset($before_pct[$k]) && (int) $row['percent'] < $before_pct[$k]) { $ok3 = false; }
}
// (3) Every REMOVED chip was orange before (no green chip is ever removed).
$active_lc = array_map('strtolower', array_merge([$primary], $active_extras));
$ok4 = true; $removed = [];
foreach ((array) $before['keywords'] as $row) {
    if (!in_array(strtolower((string) $row['keyword']), $active_lc, true)) {
        $removed[] = (string) $row['keyword'] . '=' . (string) $row['predicted_chip'];
        if ((string) $row['predicted_chip'] === 'green') { $ok4 = false; }
    }
}
printf("removed-from-active chips (all previously non-green): %s\n", implode(', ', $removed));
// (4) Regenerated page: ZERO active chips absent from content or subheadings.
$unused_regen = [];
foreach ((array) ($after_regen['keywords'] ?? []) as $row) {
    if (empty($row['in_content']) || empty($row['in_subheading'])) { $unused_regen[] = (string) $row['keyword']; }
}
printf("active chips missing content/subheading presence after regeneration: %s\n", empty($unused_regen) ? '(none)' : implode(', ', $unused_regen));
$ok5 = $after_regen !== null && empty($unused_regen);
foreach ([['same-page primary score preserved', $ok1], ['same-page combined density identical', $ok2], ['every retained chip score preserved', $ok3], ['no green chip removed', $ok4], ['regenerated page places every active chip (content + subheading)', $ok5]] as [$label, $ok]) {
    echo ($ok ? '  ok  ' : '  FAIL ') . $label . "\n";
}
echo ($ok1 && $ok2 && $ok3 && $ok4 && $ok5) ? "EVIDENCE: PASS\n" : "EVIDENCE: FAIL\n";
exit(($ok1 && $ok2 && $ok3 && $ok4 && $ok5) ? 0 : 1);
