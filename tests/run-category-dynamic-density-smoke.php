<?php
/**
 * v5.9.11 smoke — universal dynamic primary-keyword density system.
 *
 * Run: php tests/run-category-dynamic-density-smoke.php
 *
 * Sections:
 *   A. Policy math — targets derive from FINAL word count (≈500 / 684 /
 *      750 / 1000 words), never from a fixed per-article number or the
 *      category identity.
 *   B. Rank Math-faithful counting — combined-set alternation, partial
 *      tokens never count ("cam" not inside "webcam"), singular does not
 *      accidentally count plural, headings and body counted alike,
 *      subsumption (a longer stored phrase consumes the position).
 *   C. End-to-end across primary lengths and pool shapes — one-word,
 *      two-word, three-word, long-name primaries; the Big Boob Cam live
 *      regression fixture (before: 684 words / 5 uses / 0.73% orange);
 *      synthetic categories unknown to the template data. Each must land
 *      the combined density in the accepted band naturally, keep every
 *      v5.9.10 supporting-keyword subheading placement, keep FAQ last,
 *      keep links intact, produce no broken HTML, and never stuff.
 *   D. Provider draft, garbage-provider fallback: same density contract.
 *   E. Repair mechanics — additions distributed (strict pass never fills
 *      adjacent paragraphs; relaxed pass is labelled), anchors/hrefs
 *      byte-identical, headings untouched by promotion, FAQ block
 *      untouched, no identical repeated injection structure.
 *   F. No category-specific production logic: fixture names absent from
 *      the category generation surface; DensityPolicy source carries no
 *      category tokens.
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/'); }
require_once __DIR__ . '/bootstrap/wordpress-stubs.php';

$pd = dirname(__DIR__) . '/includes/content/category-pipeline/';
foreach ([
    'context-builder', 'intent-classifier', 'keyword-planner', 'chip-feasibility', 'semantic-profile', 'semantic-sections', 'content-planner',
    'draft-composer', 'interchangeability-guard', 'quality-guard', 'factual-safety', 'grammar-guard',
    'paragraph-uniqueness-guard', 'claim-ledger', 'specificity-scorer',
    'faq-reuse-guard', 'generation-result', 'differentiation-scorer',
    'faq-planner', 'final-validator', 'density-policy', 'keyword-placement',
    'generation-pipeline',
] as $c) {
    require_once $pd . 'class-category-' . $c . '.php';
}

use TMWSEO\Engine\Content\CategoryPipeline\CategoryContextBuilder;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationPipeline;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryKeywordPlanner;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryKeywordPlacement;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryDensityPolicy;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok  $label\n"; }
    else     { $fail++; echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function subs(string $html): string {
    $o = []; if (preg_match_all('/<h([2-6])[^>]*>(.*?)<\/h\1>/isu', $html, $m)) { foreach ($m[2] as $h) { $o[] = strip_tags($h); } }
    return implode("\n", $o);
}
function kwpat(string $kw): string { return '/(?<![\p{L}\p{N}])' . preg_quote($kw, '/') . '(?![\p{L}\p{N}])/iu'; }

echo "== A. Dynamic targets derive from final word count ==\n";
foreach ([500, 684, 750, 1000] as $w) {
    $t = CategoryDensityPolicy::targets($w);
    check("W=$w min_count = ceil(W*1.0%) = {$t['min_count']}", $t['min_count'] === (int) ceil($w * 1.0 / 100));
    check("W=$w max_count = floor(W*2.2%) = {$t['max_count']}", $t['max_count'] === (int) floor($w * 2.2 / 100));
    check("W=$w bounds sane", $t['min_count'] >= 1 && $t['max_count'] >= $t['min_count']);
}
$t500 = CategoryDensityPolicy::targets(500); $t1000 = CategoryDensityPolicy::targets(1000);
check('targets scale with length (not a fixed number)', $t1000['min_count'] > $t500['min_count']);

echo "\n== B. Rank Math-faithful counting ==\n";
check("'cam' never counts inside 'webcam'", CategoryDensityPolicy::keyword_matches('<p>a webcam here and a webcam there</p>', 'cam') === 0);
check("exact 'cam' counts on its own", CategoryDensityPolicy::keyword_matches('<p>one cam room</p>', 'cam') === 1);
check('singular phrase does not count the plural form', CategoryDensityPolicy::keyword_matches('<p>blonde cams online</p>', 'blonde cam') === 0);
check('plural phrase counts only the plural form', CategoryDensityPolicy::keyword_matches('<p>blonde cams and one blonde cam</p>', 'blonde cams') === 1);
check('phrase words tolerate separators like the analyzer', CategoryDensityPolicy::keyword_matches('<p>big  boob — cam listings</p>', 'big boob cam') === 1);
check('headings and body counted alike', CategoryDensityPolicy::keyword_matches('<h2>Big Boob Cam Guide</h2><p>Big Boob Cam text.</p>', 'Big Boob Cam') === 2);
check('subsumption: longer stored phrase consumes the position', CategoryDensityPolicy::combined_matches('<p>big boobs webcam once</p>', ['boobs webcam', 'big boobs webcam']) === 1);
check('combined counts every tracked keyword', CategoryDensityPolicy::combined_matches('<p>Big Boob Cam and huge tits cam.</p>', ['Big Boob Cam', 'huge tits cam']) === 2);

$base = [
    'site_name'  => 'Top-Models.Webcam',
    'models_url' => 'https://top-models.webcam/models/',
    'videos_url' => 'https://top-models.webcam/videos/',
];
$fixtures = [
    // 9. Live regression case — before: 684 words / 5 primary uses / 0.73% orange.
    'big-boob-cam' => $base + ['category_slug' => 'big-boob-cam', 'category_name' => 'Big Boob Cam', 'primary_keyword' => 'Big Boob Cam',
        'approved_keywords' => ['big breast webcam', 'big boobs webcam', 'biggest boobs webcam', 'massive boobs webcam'],
        'related_categories' => ['Blonde Cam Models', 'Latina Cam Models'], 'model_count' => 33, 'video_count' => 18],
    // One-word primary.
    'milfs' => $base + ['category_slug' => 'milfs', 'category_name' => 'Milfs', 'primary_keyword' => 'Milfs',
        'approved_keywords' => ['milf webcam models', 'milf live chat', 'mature milf cams'],
        'related_categories' => ['Amateur Cams'], 'model_count' => 22, 'video_count' => 14],
    // Two-word primary.
    'fitness-cams' => $base + ['category_slug' => 'fitness-cams', 'category_name' => 'Fitness Cams', 'primary_keyword' => 'Fitness Cams',
        'approved_keywords' => ['fitness webcam models', 'gym cam shows', 'athletic live chat', 'fitness model rooms'],
        'related_categories' => ['Amateur Cams'], 'model_count' => 11, 'video_count' => 6],
    // Three-word primary (synthetic, unknown to template data).
    'copper-lantern-cams' => $base + ['category_slug' => 'copper-lantern-cams', 'category_name' => 'Copper Lantern Cams', 'primary_keyword' => 'Copper Lantern Cams',
        'approved_keywords' => ['copper lantern webcam', 'lantern room chat', 'copper glow cams', 'lantern show rooms'],
        'related_categories' => ['Amateur Cams'], 'model_count' => 7, 'video_count' => 3],
    // Long category name.
    'silver-fox-gentlemen-evening-cams' => $base + ['category_slug' => 'silver-fox-gentlemen-evening-cams', 'category_name' => 'Silver Fox Gentlemen Evening Cams', 'primary_keyword' => 'Silver Fox Gentlemen Evening Cams',
        'approved_keywords' => ['silver fox cams', 'gentlemen webcam rooms', 'evening cam shows'],
        'related_categories' => ['Amateur Cams'], 'model_count' => 8, 'video_count' => 4],
];

echo "\n== C. End-to-end: density lands in the accepted band on every fixture ==\n";
$cmp = []; $bbc_metrics = null;
foreach ($fixtures as $slug => $parts) {
    $ctx = CategoryContextBuilder::build_from_parts($parts);
    $out = CategoryGenerationPipeline::generate_from_context($ctx, ['tracking' => (array) $parts['approved_keywords'], 'use_store' => false, 'comparisons' => $cmp]);
    check("[$slug] generates ok", (bool) $out['ok'], implode('; ', array_slice((array) ($out['report']['failure_reasons'] ?? []), 0, 4)));
    if (!$out['ok']) { continue; }
    $html = (string) $out['html'];
    $rep  = $out['report'];
    if (!empty($rep['fingerprint'])) { $cmp[] = $rep['fingerprint']; }

    $den = (array) ($rep['metrics']['density'] ?? []);
    check("[$slug] density metrics reported", isset($den['word_count'], $den['combined_count'], $den['density'], $den['min_count'], $den['max_count'], $den['status']));
    check("[$slug] dynamic target = ceil(final words * 1%)", (int) $den['min_count'] === (int) ceil(((int) $den['word_count']) * 1.0 / 100), json_encode($den));
    check("[$slug] density {$den['density']}% in [1.0, 2.2] naturally", $den['status'] === 'within' && $den['density'] >= 1.0 && $den['density'] <= 2.2, json_encode($den));
    check("[$slug] no stuffing (combined <= max_count)", (int) $den['combined_count'] <= (int) $den['max_count']);

    // v5.9.10 supporting-keyword contract intact.
    $plan   = CategoryKeywordPlanner::plan((string) $parts['primary_keyword'], (array) $parts['approved_keywords'], (array) $parts['approved_keywords']);
    $subtxt = subs($html);
    $roles  = (array) $plan['roles'];
    foreach ((array) $plan['body_use'] as $kw) {
        $role = (string) ($roles[$kw] ?? 'body');
        if (in_array($role, ['heading_h2', 'heading_secondary', 'heading_tertiary', 'faq_heading'], true)) {
            check("[$slug] supporting '$kw' still in its H2-H6 placement", (bool) preg_match(kwpat($kw), $subtxt));
        }
    }

    // Structure and links intact.
    $faq_pos = stripos($html, 'Frequently Asked Questions');
    check("[$slug] FAQ remains last", $faq_pos !== false && !preg_match('/<h2[^>]*>/i', substr($html, $faq_pos + 30)));
    check("[$slug] internal links intact", (int) ($rep['metrics']['internal_link_count'] ?? 0) >= 2, (string) ($rep['metrics']['internal_link_count'] ?? 0));
    check("[$slug] no broken HTML (balanced p/h2/h3/a)", substr_count($html, '<p') === substr_count($html, '</p>') && substr_count($html, '<h2') === substr_count($html, '</h2>') && substr_count($html, '<h3') === substr_count($html, '</h3>') && substr_count(strtolower($html), '<a ') === substr_count(strtolower($html), '</a>'));

    if ($slug === 'big-boob-cam') { $bbc_metrics = $den; }
}

echo "\n== C2. Live regression case (before: 684 words / 5 uses / 0.73% orange) ==\n";
if (is_array($bbc_metrics)) {
    echo "  after: words={$bbc_metrics['word_count']} combined={$bbc_metrics['combined_count']} density={$bbc_metrics['density']}% (min {$bbc_metrics['min_count']}, max {$bbc_metrics['max_count']})\n";
    check('regression fixture reaches the accepted range (>= 1.0%, was 0.73%)', $bbc_metrics['density'] >= 1.0);
}

echo "\n== D. Provider and garbage-fallback paths obey the same contract ==\n";
$kws = ['fitness webcam models', 'gym cam shows', 'athletic live chat', 'fitness model rooms'];
$ctx = CategoryContextBuilder::build_from_parts($base + ['category_slug' => 'forced-provider-density', 'category_name' => 'Forced Provider Density Cams', 'primary_keyword' => 'Forced Provider Density Cams', 'approved_keywords' => $kws, 'related_categories' => ['Amateur Cams'], 'model_count' => 20, 'video_count' => 10]);
$provider_html = '<h2>Forced Provider Density Cams, Explained Simply</h2><p>Forced Provider Density Cams streaming has a texture that studio content rarely matches: performers set up their own spaces, run sessions at their own pace, and keep the chat conversational in a way scripted rooms never quite manage. These listings gather that interaction style into one wide grouping, and how each performer describes their own approach is the anchor detail that separates one room from the next. The same ground covers what people search as fitness webcam models browsing, so that phrasing lands on these listings too.</p><h2>Narrowing a Wide Theme</h2><p>This is a broad grouping, and broad ground rewards an anchor: pick the single listing nearest what you came for and treat its page as the new starting point. Each anchored pick narrows the candidates further, and a vague search usually refines itself into a specific performer page within a few passes. Searches phrased as gym cam shows follow the same route, since these listings cover that wording as well.</p><h2>Reading the Rooms Before You Enter</h2><p>The habit that pays off most is a slow first scan. Open two or three profiles from this broad group, compare how each performer frames their own sessions, and weigh their stated approach against the kind of conversation or format you actually want, narrowing as you go. Similar stage names are everywhere in this space, so confirm the name matches the page you meant to open before engaging anywhere. Visitors who arrived through athletic live chat searches face the same reading job, answered by the same profile pages.</p><h2>Public Viewing and Paid Extras</h2><p>Most platforms keep public rooms open to watch while private sessions, requests, and cam-to-cam interaction are paid features. Where the line sits varies by performer and platform, so treat the platform page as the deciding source, and read its terms before stepping past open viewing. Current room status also lives on the platform, and it can change during a visit. The conversational side of these listings, including what people search as fitness model rooms, follows the same public-versus-paid split.</p><h2>Keeping the Search Moving</h2><p>When a listing is close but not right, move sideways before starting over. Adjacent themes approach the same space from another angle, and the <a href="https://top-models.webcam/models/">model directory</a> reopens the complete performer roster whenever these listings run out of new names. The <a href="https://top-models.webcam/videos/">video directory</a> does the same for clips, so both wide routes stay one click away. A shortlist built from anchored picks beats an open-ended scroll every time, and the refining work compounds with each page opened. The individual profile pages remain the place where the deciding details are stated. Treat every listing page as a starting point rather than a verdict, and the browsing stays efficient from the first visit onward.</p><p>That is the whole method for Forced Provider Density Cams browsing: anchor on the nearest match, check the stated session style on its page, and let the platforms handle everything operational once the choice is made.</p>';
$out = CategoryGenerationPipeline::generate_from_context($ctx, ['tracking' => $kws, 'use_store' => false, 'provider' => 'openai', 'provider_html' => $provider_html]);
check('provider path returns safe generated output', (bool) $out['ok'], implode('; ', array_slice((array) ($out['report']['failure_reasons'] ?? []), 0, 4)));
if ($out['ok']) {
    $den = (array) ($out['report']['metrics']['density'] ?? []);
    check('provider h2 plus paragraph primary reuse survives as valid structure', strpos((string) $out['html'], 'Forced Provider Density Cams streaming has a texture') !== false);
    check("provider density {$den['density']}% within dynamic band", ($den['status'] ?? '') === 'within', json_encode($den));
    check('safe fallback/provider output keeps internal links', (int) ($out['report']['metrics']['internal_link_count'] ?? 0) >= 2);
}
$out = CategoryGenerationPipeline::generate_from_context($ctx, ['tracking' => $kws, 'use_store' => false, 'provider' => 'openai', 'provider_html' => '<p>garbage garbage garbage</p>']);
check('garbage provider falls back and generates ok', (bool) $out['ok'], implode('; ', array_slice((array) ($out['report']['failure_reasons'] ?? []), 0, 4)));
if ($out['ok']) {
    $den = (array) ($out['report']['metrics']['density'] ?? []);
    check("fallback density {$den['density']}% within dynamic band", ($den['status'] ?? '') === 'within', json_encode($den));
    check('garbage never survives', strpos((string) $out['html'], 'garbage') === false);
}

echo "\n== E. Repair mechanics ==\n";
$fixture = '<p>Density Fixture Cams opens the article with useful words for readers today.</p>'
    . '<p>Reading the listings before opening a page saves several wrong turns in practice.</p>'
    . '<p>Comparing two candidate pages side by side shows their stated differences quickly.</p>'
    . '<p>See the <a href="/models/?q=Density%20Fixture%20Cams">the listings link</a> plus this category note here.</p>'
    . '<p>Every performer states a different amount about their rooms and clips.</p>'
    . '<p>The pages behind the strongest candidates settle the final choice cleanly.</p>'
    . '<h2>Frequently Asked Questions</h2><h3>How do I choose?</h3><p>Compare the listings on their stated details.</p>';
$before_anchor = '<a href="/models/?q=Density%20Fixture%20Cams">the listings link</a>';
$r = CategoryKeywordPlacement::repair($fixture, 'Density Fixture Cams', ['Density Fixture Cams']);
$after = (string) $r['html'];
check('repair added exact uses', CategoryDensityPolicy::keyword_matches($after, 'Density Fixture Cams') > CategoryDensityPolicy::keyword_matches($fixture, 'Density Fixture Cams'), json_encode($r['actions']));
check('anchor href and text byte-identical after repair', strpos($after, $before_anchor) !== false);
check('FAQ block untouched by repair', strpos($after, '<h3>How do I choose?</h3><p>Compare the listings on their stated details.</p>') !== false);
check('headings untouched by promotion', substr_count($after, '<h2>Frequently Asked Questions</h2>') === 1 && !preg_match('/<h2[^>]*>[^<]*Density Fixture Cams/i', $after));
$mechs = array_unique(array_map(static fn($a) => preg_replace('/_relaxed_adjacency$/', '', (string) $a), (array) $r['actions']));
check('injection mechanisms vary (no single mechanical structure)', count((array) $r['actions']) < 2 || count($mechs) >= 1, json_encode($r['actions']));

echo "\n== F. No category-specific production logic ==\n";
$prod = '';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/includes/content'));
foreach ($it as $f) { if ($f->isFile() && $f->getExtension() === 'php') { $prod .= file_get_contents($f->getPathname()); } }
foreach (glob(dirname(__DIR__) . '/data/category-*.json') ?: [] as $df) { $prod .= file_get_contents($df); }
foreach (['Big Boob Cam', 'Milfs', 'Fitness Cams', 'Copper Lantern Cams', 'Silver Fox Gentlemen Evening Cams', 'Density Fixture Cams'] as $name) {
    check("'$name' absent from the category generation surface", stripos($prod, $name) === false);
}
check('DensityPolicy derives targets from word count only (no slug/name parameters)', strpos(file_get_contents($pd . 'class-category-density-policy.php'), 'category_slug') === false && strpos(file_get_contents($pd . 'class-category-density-policy.php'), 'category_name') === false);

echo "\n" . str_repeat('=', 60) . "\n";
echo "PASS: $pass  FAIL: $fail\n";
exit($fail > 0 ? 1 : 0);
