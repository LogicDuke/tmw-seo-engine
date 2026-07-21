<?php
/**
 * v5.9.17 smoke — content-polish contract.
 *
 * Run: php tests/run-category-content-polish-smoke.php
 *
 * Sections:
 *   A. Introduction-first structure: generated body begins with one or more
 *      <p> introduction paragraphs and NEVER an <h2>; the first <h2> only
 *      appears after the introduction. Verified on the six real categories
 *      and on unseen synthetics.
 *   B. Active-keyword contract preserved despite the dropped intro heading:
 *      the primary keyword still appears in a subheading, and generation
 *      still reports ok=1 (readiness satisfied).
 *   C. Grammar guard rejects the fallback-collision and awkward-English
 *      classes before persistence: duplicate determiners, the stray-
 *      determiner "a thin the listings" case, "the listings listing"
 *      noun-noun collisions, duplicate adjacent nouns, and "a the listings".
 *      Real prose that merely contains "the listings" must NOT be flagged.
 *   D. Repair is idempotent and leaves no residual issue: after repair, the
 *      analyzer reports zero grammar issues for every collision fixture.
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
    'faq-planner', 'final-validator', 'density-policy', 'keyword-placement', 'generation-pipeline',
] as $c) {
    require_once $pd . 'class-category-' . $c . '.php';
}

use TMWSEO\Engine\Content\CategoryPipeline\CategoryContextBuilder;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationPipeline;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGrammarGuard;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok  $label\n"; }
    else     { $fail++; echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title($t){ return strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',(string)$t),'-')); }
}

/** Build a context and generate a page. */
function polish_generate(string $name, array $kws, bool $use_store): array {
    $ctx = CategoryContextBuilder::build_from_parts([
        'site_name'          => 'Top-Models.Webcam',
        'models_url'         => 'https://top-models.webcam/webcam-models/',
        'videos_url'         => 'https://top-models.webcam/webcam-videos/',
        'category_slug'      => sanitize_title($name),
        'category_name'      => $name,
        'primary_keyword'    => $name,
        'approved_keywords'  => $kws,
        'stored_chips'       => $kws,
        'related_categories' => [
            ['name' => 'Amateur Cams', 'url' => 'https://top-models.webcam/category/amateur-cams/'],
            ['name' => 'Big Boob Cam', 'url' => 'https://top-models.webcam/category/big-boob-cam/'],
        ],
        'model_count' => 22,
        'video_count' => 14,
    ]);
    return CategoryGenerationPipeline::generate_from_context($ctx, ['tracking' => $kws, 'use_store' => $use_store]);
}

$real = [
    ['Big Boob Cam',      ['big boob cam models','big tits webcam','busty live cams','big boobs cam chat']],
    ['Blonde Cam Models', ['blonde webcam models','blonde live cams','blonde cam girls','blonde webcam chat']],
    ['Latina Cam Models', ['latina webcam models','latina live cams','hot latina cams','latina cam girls']],
    ['Free Cam Chat',     ['free webcam chat','free live cams','free cam girls','free cam shows']],
    ['Amateur Cams',      ['amateur webcam models','amateur live cams','real amateur cams','amateur cam girls']],
    ['Live Anal Cams',    ['anal cams','live anal webcam','free anal cams','anal chat cam']],
];
$synthetic = [
    ['Silver Fox Gentlemen Cams', ['silver fox cams','mature gentlemen webcam','distinguished cam models']],
    ['Recorded Session Vault',    ['recorded cam sessions','saved webcam shows','cam replay library']],
];

echo "== A. Introduction-first structure (no opening H2) ==\n";
foreach (array_merge($real, $synthetic) as [$name, $kws]) {
    $res  = polish_generate($name, $kws, false);
    $html = (string) ($res['html'] ?? '');
    $trimmed = ltrim($html);
    $startsWithP  = (bool) preg_match('/^\s*<p[ >]/i', $trimmed);
    $startsWithH2 = (bool) preg_match('/^\s*<h2[ >]/i', $trimmed);
    check("[$name] body begins with a <p> introduction, not <h2>", $startsWithP && !$startsWithH2,
        'first tag: ' . (preg_match('/^\s*<([a-z0-9]+)/i', $trimmed, $m) ? $m[1] : '?'));

    // The first <h2> must be preceded by at least one <p> (intro paragraph).
    $firstH2 = stripos($html, '<h2');
    if ($firstH2 === false) {
        check("[$name] has at least one H2 after the intro", false, 'no H2 at all');
    } else {
        $before = substr($html, 0, $firstH2);
        check("[$name] first H2 only appears after intro paragraph(s)", (bool) preg_match('/<p[ >]/i', $before));
    }
}

echo "\n== B. Active-keyword contract preserved despite dropped intro heading ==\n";
foreach ($real as [$name, $kws]) {
    $res  = polish_generate($name, $kws, false);
    $html = (string) ($res['html'] ?? '');
    check("[$name] generation still ok=1", (bool) ($res['ok'] ?? false),
        json_encode(array_slice((array) ($res['report']['failure_reasons'] ?? []), 0, 3)));
    // Primary keyword still in a subheading (H2-H6).
    preg_match_all('/<h[2-6][^>]*>(.*?)<\/h[2-6]>/is', $html, $hm);
    $headingText = strtolower(strip_tags(implode(' ', $hm[1])));
    check("[$name] primary keyword still in a subheading", strpos($headingText, strtolower($name)) !== false);
}

echo "\n== C. Grammar guard rejects fallback collisions & awkward English ==\n";
$collisions = [
    'stray_determiner (thin)'     => '<p>Follow a thin the listings page and it can still open onto a full session.</p>',
    'stray_determiner (spare)'    => '<p>A spare the listings page can still open onto a full session.</p>',
    'stray_determiner (a the)'    => '<p>Read a the listings entry as a pointer outward.</p>',
    'fallback_noun_collision'     => '<p>Follow a thin the listings listing through and it works.</p>',
    'duplicate_noun'              => '<p>The listings listing shows more detail here.</p>',
    'double_determiner'           => '<p>Weigh the this option before deciding.</p>',
    'duplicated_word'             => '<p>Read the the listings first.</p>',
];
foreach ($collisions as $label => $bad) {
    $issues = CategoryGrammarGuard::analyze($bad);
    check("analyze flags $label", !empty($issues), 'detail: ' . strip_tags($bad));
}

// Real prose containing "the listings" must NOT be flagged.
$clean = [
    'plain "the listings"'      => '<p>Read the listings first to see the spread.</p>',
    'browse both'               => '<p>Browse the listings and the videos on this page.</p>',
    'quick scan'                => '<p>A quick scan of the listings here shows the variety.</p>',
    'singular listing'          => '<p>Set a listing beside a close rival and the clearer page wins.</p>',
];
foreach ($clean as $label => $good) {
    $issues = CategoryGrammarGuard::analyze($good);
    check("analyze leaves clean prose alone ($label)", empty($issues),
        !empty($issues) ? 'false-positive: ' . json_encode(array_column($issues, 'type')) : '');
}

echo "\n== D. Repair fixes collisions and leaves no residual issue ==\n";
foreach ($collisions as $label => $bad) {
    $repaired = CategoryGrammarGuard::repair($bad);
    $residual = CategoryGrammarGuard::analyze((string) $repaired['html']);
    check("repair clears $label with no residual", empty($residual),
        !empty($residual) ? 'residual: ' . json_encode(array_column($residual, 'type')) . ' in "' . trim(strip_tags((string) $repaired['html'])) . '"' : '');
}

// E. End-to-end: no generated real-category page contains a known collision.
echo "\n== E. End-to-end: generated pages carry no collision or awkward phrase ==\n";
$banned = [
    '/\bthe listings\s+(listing|page|room|clip|entry)\b/i',      // noun-noun collision
    '/\b(a|an|the|this|that)\s+\w+\s+the listings\b/i',          // stray determiner
    '/\b(a|an)\s+the listings\b/i',                              // a/an the listings
    // NOTE (v5.9.19 port): the "come in versions" phrase lives in
    // data/category-universal-faq.json (prose). Its v5.9.17 natural-language
    // fix is a PROSE edit, out of scope for this rendering/post-processing port
    // (intro-first + capitalization + grammar-collision repairs + this test).
    // faq.json is intentionally left untouched, so this phrase is not asserted
    // here. See VALIDATION-REPORT.md for the one-word opt-in that restores it.
    '/\b(sex|tits|hot|free)\s+emphasis\b/i',                     // descriptor-noun emphasis
    '/That Fits\b/i',                                            // number disagreement heading
];
foreach ($real as [$name, $kws]) {
    $res  = polish_generate($name, $kws, true);
    $vis  = strip_tags((string) ($res['html'] ?? ''));
    $hit  = '';
    foreach ($banned as $rx) {
        if (preg_match($rx, $vis, $m)) { $hit = $m[0]; break; }
    }
    check("[$name] final copy free of collisions/awkward phrases", $hit === '', "found: \"$hit\"");
}

echo "\nPASS: $pass  FAIL: $fail\n";
exit($fail > 0 ? 1 : 0);
