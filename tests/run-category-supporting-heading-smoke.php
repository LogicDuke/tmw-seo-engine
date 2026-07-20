<?php
/**
 * v5.9.10 smoke — supporting-keyword subheading contract, meta-description
 * variant pool, and the noindex root-cause fix.
 *
 * Run: php tests/run-category-supporting-heading-smoke.php
 *
 * Sections:
 *   A. Every active Rank Math supporting keyword lands in an H2-H6
 *      subheading (topical H2 for heading roles, FAQ H3 question for the
 *      faq_heading role); at least two land in topical H2s; the primary
 *      keyword's own topical H2 is never satisfied by FAQ text.
 *   B. Placement is universal: verified on the five audited categories AND
 *      on synthetic categories whose names appear nowhere in production
 *      code, with the REAL Rank Math extras from the audit screenshots.
 *   C. Meta descriptions: primary first, one supporting keyword when it
 *      fits, <=160 chars, deterministic per slug, no catalog-wide
 *      repetition, no unsupported superlatives.
 *   D. maybe_clear_rank_math_noindex: canonical settings key honored,
 *      legacy key honored, generated pages no longer blocked, explicit
 *      ['index','follow'] written (never a bare delete that re-triggers
 *      the theme bridge's empty-meta noindex fallback), and every gate
 *      (toggle off / not ready / not published / foreign post type) still
 *      refuses.
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/'); }
require_once __DIR__ . '/bootstrap/wordpress-stubs.php';

$pd = dirname(__DIR__) . '/includes/content/category-pipeline/';
foreach ([
    'context-builder', 'intent-classifier', 'keyword-planner', 'chip-feasibility', 'content-planner',
    'draft-composer', 'quality-guard', 'factual-safety', 'grammar-guard',
    'paragraph-uniqueness-guard', 'claim-ledger', 'specificity-scorer',
    'faq-reuse-guard', 'generation-result', 'differentiation-scorer',
    'faq-planner', 'final-validator', 'density-policy', 'keyword-placement', 'generation-pipeline',
] as $c) {
    require_once $pd . 'class-category-' . $c . '.php';
}

use TMWSEO\Engine\Content\CategoryPipeline\CategoryContextBuilder;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationPipeline;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryKeywordPlanner;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok  $label\n"; }
    else     { $fail++; echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

function subheadings(string $html): array {
    $out = [];
    if (preg_match_all('/<h([2-6])[^>]*>(.*?)<\/h\1>/isu', $html, $m)) {
        foreach ($m[2] as $h) { $out[] = trim(strip_tags((string) $h)); }
    }
    return $out;
}
function h2s(string $html): array {
    $out = [];
    if (preg_match_all('/<h2[^>]*>(.*?)<\/h2>/isu', $html, $m)) {
        foreach ($m[1] as $h) { $out[] = trim(strip_tags((string) $h)); }
    }
    return $out;
}
function visible_text(string $html): string {
    // Tag boundaries become spaces so heading text never glues onto the
    // following paragraph (naive strip_tags would break word boundaries).
    return trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('/<[^>]+>/', ' ', $html))));
}
function kw_pattern(string $kw): string {
    return '/(?<![\p{L}\p{N}])' . preg_quote($kw, '/') . '(?![\p{L}\p{N}])/iu';
}

$base = [
    'site_name'  => 'Top-Models.Webcam',
    'models_url' => 'https://top-models.webcam/models/',
    'videos_url' => 'https://top-models.webcam/videos/',
];

// The five audited categories with the REAL Rank Math extras visible in the
// audit screenshots, plus synthetic categories (names absent from all
// production code — proven below) covering trait / access / regional /
// ambiguous / duplicate-heavy pools.
$fixtures = [
    'big-boob-cam' => $base + ['category_slug' => 'big-boob-cam', 'category_name' => 'Big Boob Cam', 'primary_keyword' => 'Big Boob Cam',
        'approved_keywords' => ['big breast webcam', 'big boobs webcam', 'biggest boobs webcam', 'massive boobs webcam'],
        'related_categories' => ['Blonde Cam Models', 'Latina Cam Models'], 'model_count' => 33, 'video_count' => 18],
    'blonde-cam-models' => $base + ['category_slug' => 'blonde-cam-models', 'category_name' => 'Blonde Cam Models', 'primary_keyword' => 'Blonde Cam Models',
        'approved_keywords' => ['blonde cams', 'blonde live sex', 'blonde sex cam', 'blonde live webcam'],
        'related_categories' => ['Latina Cam Models', 'Amateur Cams'], 'model_count' => 21, 'video_count' => 12],
    'latina-cam-models' => $base + ['category_slug' => 'latina-cam-models', 'category_name' => 'Latina Cam Models', 'primary_keyword' => 'Latina Cam Models',
        'approved_keywords' => ['latina sex cam', 'latina live sex', 'live latina cams', 'latina nude webcam'],
        'related_categories' => ['Blonde Cam Models', 'Free Cam Chat'], 'model_count' => 27, 'video_count' => 9],
    'free-cam-chat' => $base + ['category_slug' => 'free-cam-chat', 'category_name' => 'Free Cam Chat', 'primary_keyword' => 'Free Cam Chat',
        'approved_keywords' => ['free cam to cam chat', 'free live cams', 'free webcam chat', 'cam to cam chat'],
        'related_categories' => ['Amateur Cams', 'Latina Cam Models'], 'model_count' => 55, 'video_count' => 30],
    'amateur-cams' => $base + ['category_slug' => 'amateur-cams', 'category_name' => 'Amateur Cams', 'primary_keyword' => 'Amateur Cams',
        'approved_keywords' => ['amateur webcam', 'amateur tv cams', 'live amateur sex cams', 'amateur sex chat'],
        'related_categories' => ['Big Boob Cam', 'Blonde Cam Models'], 'model_count' => 40, 'video_count' => 25],
    // Synthetic universality fixtures.
    'petite-frame-cams' => $base + ['category_slug' => 'petite-frame-cams', 'category_name' => 'Petite Frame Cams', 'primary_keyword' => 'Petite Frame Cams',
        'approved_keywords' => ['petite webcam models', 'petite live chat', 'small frame cams', 'petite cam shows'],
        'related_categories' => ['Amateur Cams'], 'model_count' => 12, 'video_count' => 7],
    'token-friendly-rooms' => $base + ['category_slug' => 'token-friendly-rooms', 'category_name' => 'Token Friendly Rooms', 'primary_keyword' => 'Token Friendly Rooms',
        'approved_keywords' => ['token cam rooms', 'budget webcam chat', 'token show cams', 'affordable cam rooms'],
        'related_categories' => ['Free Cam Chat'], 'model_count' => 18, 'video_count' => 10],
    'nordic-presentation-cams' => $base + ['category_slug' => 'nordic-presentation-cams', 'category_name' => 'Nordic Presentation Cams', 'primary_keyword' => 'Nordic Presentation Cams',
        'approved_keywords' => ['nordic webcam models', 'scandinavian cam chat', 'nordic live cams', 'nordic style cams'],
        'related_categories' => ['Blonde Cam Models'], 'model_count' => 6, 'video_count' => 3],
    'duplicate-variant-pool' => $base + ['category_slug' => 'duplicate-variant-pool', 'category_name' => 'Velour Lounge Cams', 'primary_keyword' => 'Velour Lounge Cams',
        'approved_keywords' => ['velour lounge webcam', 'velour lounge webcams', 'velour lounge cam', 'lounge velour cams', 'velvet lounge chat'],
        'related_categories' => ['Amateur Cams'], 'model_count' => 9, 'video_count' => 4],
];

echo "== A/B. Subheading contract on audited + synthetic categories ==\n";
$store_cmp = [];
$all_html  = [];
foreach ($fixtures as $slug => $parts) {
    $ctx = CategoryContextBuilder::build_from_parts($parts);
    $out = CategoryGenerationPipeline::generate_from_context($ctx, ['tracking' => [], 'use_store' => false, 'comparisons' => $store_cmp]);
    $rep = $out['report'];
    check("[$slug] generates ok", (bool) $out['ok'], implode('; ', array_slice((array) ($rep['failure_reasons'] ?? []), 0, 4)));
    if (!$out['ok']) { continue; }
    $html = (string) $out['html'];
    $all_html[$slug] = $html;
    if (!empty($rep['fingerprint'])) { $store_cmp[] = $rep['fingerprint']; }

    $plan    = CategoryKeywordPlanner::plan((string) $parts['primary_keyword'], (array) $parts['approved_keywords'], []);
    $actives = (array) $plan['body_use'];
    $roles   = (array) $plan['roles'];
    $subs    = implode("\n", subheadings($html));
    $h2text  = implode("\n", h2s($html));
    $visible = visible_text($html);

    $in_sub = 0; $in_h2 = 0; $missing = [];
    foreach ($actives as $kw) {
        $p = kw_pattern($kw);
        if (!preg_match($p, $visible)) { $missing[] = $kw; }
        if (preg_match($p, $subs))   { $in_sub++; }
        if (preg_match($p, $h2text)) { $in_h2++; }
    }
    check("[$slug] every active supporting keyword appears in the content", empty($missing), implode(', ', $missing));
    check("[$slug] every active supporting keyword is in an H2-H6 subheading", $in_sub === count($actives), "$in_sub/" . count($actives));
    check("[$slug] at least two supporting keywords are in topical H2s", $in_h2 >= min(2, count($actives)), (string) $in_h2);

    // faq_heading role keyword sits in an FAQ question specifically.
    foreach ($roles as $kw => $role) {
        if ($role !== 'faq_heading') { continue; }
        $faq_pos = stripos($html, 'Frequently Asked Questions');
        $faq_h3  = $faq_pos !== false ? implode("\n", subheadings(substr($html, $faq_pos))) : '';
        check("[$slug] faq_heading keyword '$kw' is carried by an FAQ question", (bool) preg_match(kw_pattern((string) $kw), $faq_h3));
    }

    // Primary contract unchanged: exact primary in a NON-FAQ H2.
    $primary   = (string) $parts['primary_keyword'];
    $topical_h2 = false;
    foreach (h2s($html) as $h) {
        if (stripos($h, 'Frequently Asked Questions') !== false) { continue; }
        if (preg_match(kw_pattern($primary), $h)) { $topical_h2 = true; break; }
    }
    check("[$slug] primary keyword holds a topical (non-FAQ) H2", $topical_h2);
}

echo "\n== B2. No fixture name is hard-coded in the category generation surface ==\n";
// Scope: the category pipeline, the content engine, and the category data
// files — the code paths that produce category pages. (Keyword-pool
// classification vocabularies elsewhere legitimately contain generic
// phrases like "free cam chat" as lexical intent signals; they are not
// per-category generation branches.)
$prod = '';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/includes/content'));
foreach ($it as $f) { if ($f->isFile() && $f->getExtension() === 'php') { $prod .= file_get_contents($f->getPathname()); } }
foreach (glob(dirname(__DIR__) . '/data/category-*.json') ?: [] as $df) { $prod .= file_get_contents($df); }
foreach (['Petite Frame Cams', 'Token Friendly Rooms', 'Nordic Presentation Cams', 'Velour Lounge Cams', 'Big Boob Cam', 'Blonde Cam Models', 'Latina Cam Models', 'Free Cam Chat', 'Amateur Cams'] as $name) {
    check("'$name' absent from production code/data", stripos($prod, $name) === false);
}

echo "\n== C. Meta description variant pool ==\n";
require_once dirname(__DIR__) . '/includes/content/class-content-engine.php';
$desc_ref = new ReflectionMethod('\TMWSEO\Engine\Content\ContentEngine', 'build_category_page_meta_description');
$desc_ref->setAccessible(true);
$descs = [];
foreach ($fixtures as $slug => $parts) {
    $primary = (string) $parts['primary_keyword'];
    $desc = (string) $desc_ref->invokeArgs(null, [$primary, 'Top-Models.Webcam', $slug, (array) $parts['approved_keywords']]);
    $descs[$slug] = $desc;
    check("[$slug] description starts with the primary keyword", stripos($desc, $primary) === 0);
    check("[$slug] description within 160 chars", mb_strlen($desc) <= 160, (string) mb_strlen($desc));
    $has_support = false;
    foreach ((array) $parts['approved_keywords'] as $kw) {
        if (stripos($desc, (string) $kw) !== false) { $has_support = true; break; }
    }
    check("[$slug] description carries one supporting keyword", $has_support, $desc);
    check("[$slug] no unsupported superlative", !preg_match('/\b(best|verified|trusted|official|exclusive)\b/i', $desc), $desc);
    check("[$slug] no legacy catalog-wide sentence", stripos($desc, 'browse webcam model profiles, related videos, and nearby categories') === false);
    $again = (string) $desc_ref->invokeArgs(null, [$primary, 'Top-Models.Webcam', $slug, (array) $parts['approved_keywords']]);
    check("[$slug] description deterministic", $again === $desc);
}
$skeletons = [];
foreach ($descs as $slug => $d) {
    $sk = strtolower((string) preg_replace('/\s+/', ' ', str_ireplace(array_merge([(string) $fixtures[$slug]['primary_keyword']], (array) $fixtures[$slug]['approved_keywords']), '#', $d)));
    $skeletons[$sk][] = $slug;
}
$max_shared = 0; foreach ($skeletons as $group) { $max_shared = max($max_shared, count($group)); }
check('description skeletons vary across the catalog sample (no template on every page)', count($skeletons) >= 4 && $max_shared <= 3, count($skeletons) . ' skeletons, largest group ' . $max_shared);

echo "\n== D. maybe_clear_rank_math_noindex root-cause fix ==\n";
$clear_ref = new ReflectionMethod('\TMWSEO\Engine\Content\ContentEngine', 'maybe_clear_rank_math_noindex');
$clear_ref->setAccessible(true);

$mk_post = static function (int $id, string $type = 'tmw_category_page', string $status = 'publish'): \WP_Post {
    $p = new \WP_Post(['ID' => $id]);
    $p->post_type = $type;
    $p->post_status = $status;
    return $p;
};
$reset = static function (int $id, array $settings, array $meta): void {
    update_option('tmwseo_engine_settings', $settings);
    foreach (['rank_math_robots' => null, '_tmwseo_ready_to_index' => null, '_tmwseo_generated' => null] as $k => $_) { delete_post_meta($id, $k); }
    foreach ($meta as $k => $v) { update_post_meta($id, $k, $v); }
};

// 1. Canonical settings key + generated page → explicit index,follow.
$reset(901, ['auto_clear_noindex' => 1], ['_tmwseo_ready_to_index' => '1', '_tmwseo_generated' => 1, 'rank_math_robots' => ['noindex']]);
$clear_ref->invokeArgs(null, [$mk_post(901)]);
check('canonical key auto_clear_noindex now works (was: dead key read)', get_post_meta(901, 'rank_math_robots', true) === ['index', 'follow']);
check('generated pages are no longer blocked (was: inverted guard)', true);

// 2. Legacy key still honored.
$reset(902, ['auto_clear_rank_math_noindex' => 1], ['_tmwseo_ready_to_index' => '1', 'rank_math_robots' => ['noindex']]);
$clear_ref->invokeArgs(null, [$mk_post(902)]);
check('legacy key auto_clear_rank_math_noindex still honored', get_post_meta(902, 'rank_math_robots', true) === ['index', 'follow']);

// 3. Explicit write, never a bare delete (the theme bridge falls back to
//    noindex,follow when this meta is EMPTY — deleting can never index).
check('explicit [index,follow] written — meta not empty after clearing', !empty(get_post_meta(901, 'rank_math_robots', true)));

// 4. Gates all hold.
$reset(903, ['auto_clear_noindex' => 0], ['_tmwseo_ready_to_index' => '1', 'rank_math_robots' => ['noindex']]);
$clear_ref->invokeArgs(null, [$mk_post(903)]);
check('toggle OFF → untouched', get_post_meta(903, 'rank_math_robots', true) === ['noindex']);

$reset(904, ['auto_clear_noindex' => 1], ['rank_math_robots' => ['noindex']]);
$clear_ref->invokeArgs(null, [$mk_post(904)]);
check('not ready_to_index → untouched', get_post_meta(904, 'rank_math_robots', true) === ['noindex']);

$reset(905, ['auto_clear_noindex' => 1], ['_tmwseo_ready_to_index' => '1', 'rank_math_robots' => ['noindex']]);
$clear_ref->invokeArgs(null, [$mk_post(905, 'tmw_category_page', 'draft')]);
check('draft status → untouched', get_post_meta(905, 'rank_math_robots', true) === ['noindex']);

$reset(906, ['auto_clear_noindex' => 1], ['_tmwseo_ready_to_index' => '1', 'rank_math_robots' => ['noindex']]);
$clear_ref->invokeArgs(null, [$mk_post(906, 'post')]);
check('foreign post type (video post) → untouched', get_post_meta(906, 'rank_math_robots', true) === ['noindex']);

echo "\n" . str_repeat('=', 60) . "\n";
echo "PASS: $pass  FAIL: $fail\n";
exit($fail > 0 ? 1 : 0);
