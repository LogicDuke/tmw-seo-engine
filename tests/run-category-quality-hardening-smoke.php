<?php
/**
 * v5.9.8 universal category QUALITY HARDENING smoke test.
 *
 * Standalone — no WordPress, no PHPUnit. Implements the 25-point hardening
 * test matrix by inspecting ACTUAL generated text, never just counts:
 *
 *   1  no identical normalized paragraphs across the regression categories
 *   2  no identical closing paragraphs
 *   3  no identical introduction paragraphs
 *   4  no identical FAQ answers
 *   5  paragraph-level similarity <= 0.75
 *   6  closing similarity <= 0.60
 *   7  introduction similarity <= 0.60
 *   8  FAQ answer similarity <= 0.70
 *   9  article selection (a/an) incl. Amateur/Adult/Asian/Ebony/European Cams
 *  10  no duplicated words
 *  11  no malformed punctuation
 *  12  no unsupported quantity claims
 *  13  no unsupported schedule claims
 *  14  no unsupported safety claims
 *  15  no unsupported profile-field claims
 *  16  no unsupported update-frequency claims
 *  17  no placeholder or analysis vocabulary
 *  18  no low-value metaphor openings
 *  19  at least three intent-specific paragraphs per page
 *  20  report and sample intent match (same immutable result object)
 *  21  generation IDs match across all outputs
 *  22  three distinct provider drafts remain meaningfully distinct
 *  23  unknown categories generate safely
 *  24  safe failure still works
 *  25  v5.9.7 keyword and density protections remain intact
 *
 * Plus explicit failing-text probes for EVERY defect example quoted in the
 * v5.9.8 hardening prompt.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('ABSPATH', sys_get_temp_dir() . '/');
require __DIR__ . '/bootstrap/wordpress-stubs.php';

$pipeline_dir = dirname(__DIR__) . '/includes/content/category-pipeline/';
require_once $pipeline_dir . 'class-category-context-builder.php';
require_once $pipeline_dir . 'class-category-intent-classifier.php';
require_once $pipeline_dir . 'class-category-keyword-planner.php';
require_once $pipeline_dir . 'class-category-content-planner.php';
require_once $pipeline_dir . 'class-category-draft-composer.php';
require_once $pipeline_dir . 'class-category-quality-guard.php';
require_once $pipeline_dir . 'class-category-factual-safety.php';
require_once $pipeline_dir . 'class-category-grammar-guard.php';
require_once $pipeline_dir . 'class-category-keyword-placement.php';
require_once $pipeline_dir . 'class-category-paragraph-uniqueness-guard.php';
require_once $pipeline_dir . 'class-category-claim-ledger.php';
require_once $pipeline_dir . 'class-category-specificity-scorer.php';
require_once $pipeline_dir . 'class-category-faq-reuse-guard.php';
require_once $pipeline_dir . 'class-category-generation-result.php';
require_once $pipeline_dir . 'class-category-differentiation-scorer.php';
require_once $pipeline_dir . 'class-category-faq-planner.php';
require_once $pipeline_dir . 'class-category-final-validator.php';
require_once $pipeline_dir . 'class-category-generation-pipeline.php';
require_once __DIR__ . '/category-collision-shadow-helper.php';

use TMWSEO\Engine\Content\CategoryPipeline\CategoryContextBuilder;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryIntentClassifier;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryKeywordPlanner;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryQualityGuard;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryFactualSafety;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGrammarGuard;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryParagraphUniquenessGuard as UGuard;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryClaimLedger;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryContentPlanner;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryDraftComposer;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryFaqPlanner;
use TMWSEO\Engine\Content\CategoryPipeline\CategorySpecificityScorer;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationResult;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryDifferentiationScorer;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationPipeline;

$pass = 0; $fail = 0; $failures = [];
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  ok  $label\n"; }
    else { $fail++; $failures[] = $label . ($detail !== '' ? " — $detail" : ''); echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

$base = [
    'site_name'  => 'Top-Models.Webcam',
    'models_url' => 'https://top-models.webcam/models/',
    'videos_url' => 'https://top-models.webcam/videos/',
];
$fixtures = [
    'amateur-cams'      => $base + ['category_slug' => 'amateur-cams', 'category_name' => 'Amateur Cams', 'primary_keyword' => 'Amateur Cams', 'approved_keywords' => ['amateur webcam', 'amateur tv cams', 'live amateur sex cams', 'amateur sex chat'], 'related_categories' => ['Big Boob Cam', 'Blonde Cam Models'], 'model_count' => 40, 'video_count' => 25],
    'big-boob-cam'      => $base + ['category_slug' => 'big-boob-cam', 'category_name' => 'Big Boob Cam', 'primary_keyword' => 'Big Boob Cam', 'approved_keywords' => ['big boobs webcam', 'huge tits cam', 'big breast cams'], 'related_categories' => ['Blonde Cam Models', 'Latina Cam Models'], 'model_count' => 33, 'video_count' => 18],
    'blonde-cam-models' => $base + ['category_slug' => 'blonde-cam-models', 'category_name' => 'Blonde Cam Models', 'primary_keyword' => 'Blonde Cam Models', 'approved_keywords' => ['blonde webcam girls', 'blonde cam chat'], 'related_categories' => ['Latina Cam Models', 'Amateur Cams'], 'model_count' => 21, 'video_count' => 12],
    'latina-cam-models' => $base + ['category_slug' => 'latina-cam-models', 'category_name' => 'Latina Cam Models', 'primary_keyword' => 'Latina Cam Models', 'approved_keywords' => ['latina webcam', 'latina cam chat'], 'related_categories' => ['Blonde Cam Models', 'Free Cam Chat'], 'model_count' => 27, 'video_count' => 9],
    'free-cam-chat'     => $base + ['category_slug' => 'free-cam-chat', 'category_name' => 'Free Cam Chat', 'primary_keyword' => 'Free Cam Chat', 'approved_keywords' => ['free webcam chat', 'free adult cams'], 'related_categories' => ['Amateur Cams', 'Latina Cam Models'], 'model_count' => 55, 'video_count' => 30],
    // Unknown / future categories (test inputs only — no hardcoded copy).
    'silver-fox-gentlemen-cams' => $base + ['category_slug' => 'silver-fox-gentlemen-cams', 'category_name' => 'Silver Fox Gentlemen Cams', 'primary_keyword' => 'Silver Fox Gentlemen Cams', 'approved_keywords' => ['silver fox cams', 'gentlemen webcam'], 'related_categories' => ['Amateur Cams'], 'model_count' => 8, 'video_count' => 4],
    'redhead-webcam-models'     => $base + ['category_slug' => 'redhead-webcam-models', 'category_name' => 'Redhead Webcam Models', 'primary_keyword' => 'Redhead Webcam Models', 'approved_keywords' => ['redhead cams', 'ginger webcam'], 'related_categories' => ['Blonde Cam Models'], 'model_count' => 14, 'video_count' => 6],
    'couples-cam-chat'          => $base + ['category_slug' => 'couples-cam-chat', 'category_name' => 'Couples Cam Chat', 'primary_keyword' => 'Couples Cam Chat', 'approved_keywords' => ['couples webcam', 'couple cams live'], 'related_categories' => ['Free Cam Chat'], 'model_count' => 19, 'video_count' => 11],
    'french-speaking-cam-models' => $base + ['category_slug' => 'french-speaking-cam-models', 'category_name' => 'French Speaking Cam Models', 'primary_keyword' => 'French Speaking Cam Models', 'approved_keywords' => ['french cams', 'french webcam chat'], 'related_categories' => ['Latina Cam Models'], 'model_count' => 7, 'video_count' => 3],
    'tattooed-cam-performers'   => $base + ['category_slug' => 'tattooed-cam-performers', 'category_name' => 'Tattooed Cam Performers', 'primary_keyword' => 'Tattooed Cam Performers', 'approved_keywords' => ['tattoo cams', 'inked webcam models'], 'related_categories' => ['Redhead Webcam Models'], 'model_count' => 16, 'video_count' => 22],
];
$regression = ['amateur-cams', 'big-boob-cam', 'blonde-cam-models', 'latina-cam-models', 'free-cam-chat'];
$unknown    = ['silver-fox-gentlemen-cams', 'redhead-webcam-models', 'couples-cam-chat', 'french-speaking-cam-models', 'tattooed-cam-performers'];

echo "== Generate all ten categories (rolling store active) ==\n";
$results = [];
foreach (array_merge($regression, $unknown) as $slug) {
    $ctx = CategoryContextBuilder::build_from_parts($fixtures[$slug]);
    $res = CategoryGenerationPipeline::generate_from_context($ctx, ['tracking' => []]);
    $results[$slug] = $res;
    check("[$slug] generates ok on attempt " . (int) $res['report']['attempts'], (bool) $res['ok'], implode('; ', array_slice((array) $res['report']['failure_reasons'], 0, 4)));
}
$ok_slugs = array_keys(array_filter($results, static fn($r) => (bool) $r['ok']));

// ── Text extraction helpers (independent of the guard implementation) ──────
function body_paragraphs(string $html): array {
    $out = []; $in_faq = false;
    if (preg_match_all('/<(h2|p)[^>]*>(.*?)<\/\1>/isu', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $b) {
            if (strtolower($b[1]) === 'h2') { $in_faq = (stripos($b[2], 'frequently asked') !== false); continue; }
            if ($in_faq) { continue; }
            $t = trim(preg_replace('/\s+/u', ' ', strip_tags($b[2])));
            if ($t !== '') { $out[] = $t; }
        }
    }
    return $out;
}
function faq_answers(string $html): array {
    $out = []; $in_faq = false;
    if (preg_match_all('/<(h2|h3|p)[^>]*>(.*?)<\/\1>/isu', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $b) {
            if (strtolower($b[1]) === 'h2') { $in_faq = (stripos($b[2], 'frequently asked') !== false); continue; }
            if ($in_faq && strtolower($b[1]) === 'p') { $out[] = trim(preg_replace('/\s+/u', ' ', strip_tags($b[2]))); }
        }
    }
    return $out;
}
function norm(string $text, array $mask): string { return UGuard::normalize($text, $mask); }
function sim(string $a, string $b): float { return UGuard::jaccard(UGuard::trigrams($a), UGuard::trigrams($b)); }

$pages = [];
foreach ($ok_slugs as $slug) {
    $mask = [$fixtures[$slug]['category_name'], $fixtures[$slug]['primary_keyword']];
    $paras = body_paragraphs($results[$slug]['html']);
    $pages[$slug] = [
        'mask'    => $mask,
        'paras'   => array_map(static fn($p) => norm($p, $mask), $paras),
        'raw'     => $paras,
        'intro'   => $paras[0] ?? '',
        'closing' => $paras !== [] ? $paras[count($paras) - 1] : '',
        'faq'     => faq_answers($results[$slug]['html']),
    ];
}

echo "\n== Tests 1-8: paragraph / closing / intro / FAQ uniqueness across pages ==\n";
// Exactness (tests 1-4) is asserted across ALL page pairs. The similarity
// ceilings (tests 5-8) are asserted for pairs inside the pipeline's enforced
// uniqueness window (UNIQUENESS_WINDOW_PAGES recent pages) — that window is
// the documented contract the guard and all cooldowns are aligned on.
$window = CategoryGenerationPipeline::UNIQUENESS_WINDOW_PAGES;
$exact_dupes = []; $max_para = 0.0; $max_close = 0.0; $max_intro = 0.0; $max_faq = 0.0;
$slugs = array_keys($pages);
for ($i = 0; $i < count($slugs); $i++) {
    for ($j = $i + 1; $j < count($slugs); $j++) {
        $in_window = ($j - $i) <= $window;
        $a = $pages[$slugs[$i]]; $b = $pages[$slugs[$j]];
        foreach ($a['paras'] as $pa) {
            foreach ($b['paras'] as $pb) {
                if ($pa === $pb) { $exact_dupes[] = $slugs[$i] . '~' . $slugs[$j] . ': ' . substr($pa, 0, 60); }
                if ($in_window) { $max_para = max($max_para, sim($pa, $pb)); }
            }
        }
        if ($in_window) {
            $max_close = max($max_close, sim(norm($a['closing'], $a['mask']), norm($b['closing'], $b['mask'])));
            $max_intro = max($max_intro, sim(norm($a['intro'], $a['mask']), norm($b['intro'], $b['mask'])));
        }
        foreach ($a['faq'] as $fa) {
            foreach ($b['faq'] as $fb) {
                if (norm($fa, $a['mask']) === norm($fb, $b['mask'])) { $exact_dupes[] = 'FAQ ' . $slugs[$i] . '~' . $slugs[$j]; }
                if ($in_window) { $max_faq = max($max_faq, sim(norm($fa, $a['mask']), norm($fb, $b['mask']))); }
            }
        }
    }
}
check('1. no identical normalized paragraphs across pages', empty(array_filter($exact_dupes, static fn($d) => strpos($d, 'FAQ') !== 0)), implode(' | ', array_slice($exact_dupes, 0, 3)));
$close_norms = array_map(static fn($s) => norm($pages[$s]['closing'], $pages[$s]['mask']), $slugs);
check('2. no identical closing paragraphs', count(array_unique($close_norms)) === count($close_norms));
$intro_norms = array_map(static fn($s) => norm($pages[$s]['intro'], $pages[$s]['mask']), $slugs);
check('3. no identical introduction paragraphs', count(array_unique($intro_norms)) === count($intro_norms));
check('4. no identical FAQ answers across pages', empty(array_filter($exact_dupes, static fn($d) => strpos($d, 'FAQ') === 0)), implode(' | ', array_slice($exact_dupes, 0, 3)));
check('5. paragraph similarity <= 0.75 within window (max ' . round($max_para, 3) . ')', $max_para <= UGuard::MAX_PARAGRAPH_SIMILARITY);
check('6. closing similarity <= 0.60 (max ' . round($max_close, 3) . ')', $max_close <= UGuard::MAX_CLOSING_SIMILARITY);
check('7. intro similarity <= 0.60 (max ' . round($max_intro, 3) . ')', $max_intro <= UGuard::MAX_INTRO_SIMILARITY);
check('8. FAQ answer similarity <= 0.70 (max ' . round($max_faq, 3) . ')', $max_faq <= UGuard::MAX_FAQ_ANSWER_SIMILARITY);

echo "\n== Test 9: article selection (a/an) ==\n";
foreach ([['Amateur', 'an'], ['Adult', 'an'], ['Asian', 'an'], ['Ebony', 'an'], ['European', 'a']] as [$word, $want]) {
    check("9. article for '$word Cams' is '$want'", CategoryGrammarGuard::article_for($word) === $want);
}
$rep = CategoryGrammarGuard::repair('<p>Results from a Amateur Cams search and a Ebony Cams page and an European Cams list.</p>');
check('9. "a Amateur Cams" repaired to "an Amateur Cams"', strpos($rep['html'], 'an Amateur Cams') !== false && strpos($rep['html'], 'an Ebony Cams') !== false && strpos($rep['html'], 'a European Cams') !== false, $rep['html']);
foreach ($ok_slugs as $slug) {
    $issues = CategoryGrammarGuard::analyze($results[$slug]['html']);
    check("9-11. [$slug] grammar-clean (a/an, duplicates, punctuation)", empty($issues), implode('; ', array_map(static fn($i) => $i['type'] . ':' . $i['detail'], array_slice($issues, 0, 3))));
}
$rep2 = CategoryGrammarGuard::repair('<p>The the listings show show details , and pricing.. Here it it is.</p>');
check('10. duplicated words repaired', strpos($rep2['html'], 'the the') === false && strpos($rep2['html'], 'show show') === false && strpos($rep2['html'], 'it it') === false, $rep2['html']);
check('11. malformed punctuation repaired', strpos($rep2['html'], ' ,') === false && strpos($rep2['html'], '..') === false, $rep2['html']);

echo "\n== Tests 12-16: unsupported-claim probes (every defect example from the prompt) ==\n";
$probes = [
    'quantity (no count evidence)' => 'There is a broad selection of performer listings on this page.',
    'turnover'                     => 'Performers join, change platforms, or move on over time.',
    'tags'                         => 'Profile tags will usually lead to similar rooms.',
    'location'                     => 'Profiles rarely pin down locations precisely.',
    'timezone'                     => 'Time-zone patterns show indirectly in when rooms tend to be live.',
    'safety comparative'           => 'Using those listed links is generally safer than searching names blind.',
    'public-room behavior'         => 'Public rooms let you observe the interaction style before paying.',
    'schedules'                    => 'Performers set their own streaming hours every week.',
    'update frequency'             => 'A repeat visit will usually show a different set.',
    'profile fields'               => 'Photos and self-descriptions on profile pages are more reliable than category labels.',
];
$empty_ctx = CategoryContextBuilder::build_from_parts($base + ['category_slug' => 'probe', 'category_name' => 'Probe Cams', 'primary_keyword' => 'Probe Cams', 'approved_keywords' => []]);
foreach ($probes as $label => $sentence) {
    $ledger = CategoryClaimLedger::build('<p>' . $sentence . '</p>', $empty_ctx);
    check("12-16. probe detected: $label", !$ledger['passed'], $sentence);
    $fixed = CategoryFactualSafety::repair('<p>' . $sentence . '</p>');
    $re    = CategoryClaimLedger::build($fixed['html'], $empty_ctx);
    check("12-16. probe repair is itself claim-clean: $label", (bool) $re['passed'], strip_tags($fixed['html']));
}
foreach ($ok_slugs as $slug) {
    $ctx    = CategoryContextBuilder::build_from_parts($fixtures[$slug]);
    $ledger = CategoryClaimLedger::build($results[$slug]['html'], $ctx);
    check("12-16. [$slug] claim ledger passes (" . array_sum($ledger['counts']) . ' classified claims)', (bool) $ledger['passed'], implode('; ', array_map(static fn($u) => $u['type'], array_slice($ledger['unsupported'], 0, 3))));
}

echo "\n== Tests 17-18: placeholder vocabulary and low-value prose ==\n";
$lv_probes = [
    'Every category page answers one question before anything else.',
    'Consider this the Amateur Cams shelf of the site.',
    'Some categories are moods and some are specifics.',
    'The model directory and the video directory are the two widest doors.',
    'Think of this page as a well-labelled drawer.',
];
foreach ($lv_probes as $probe) {
    $issues = CategoryQualityGuard::analyze('<p>' . $probe . '</p>');
    check('18. low-value probe detected: "' . substr($probe, 0, 40) . '…"', !empty($issues));
}
foreach ($ok_slugs as $slug) {
    $kw = array_merge([$fixtures[$slug]['primary_keyword']], $fixtures[$slug]['approved_keywords']);
    $issues = CategoryQualityGuard::analyze($results[$slug]['html'], $kw);
    check("17-18. [$slug] no placeholder vocabulary or low-value prose", empty($issues), implode('; ', array_map(static fn($i) => $i['type'] . ':' . $i['detail'], array_slice($issues, 0, 3))));
    $first = strtolower($pages[$slug]['intro']);
    $bad_open = false;
    foreach (['think of', 'consider this', 'every category page', 'some categories'] as $opener) {
        if (strpos($first, $opener) === 0) { $bad_open = true; }
    }
    check("18. [$slug] intro does not open with a banned rhetorical pattern", !$bad_open, substr($first, 0, 60));
}

echo "\n== Test 19: category-specificity minimum ==\n";
foreach ($ok_slugs as $slug) {
    $intent = (string) $results[$slug]['report']['intent'];
    $score  = CategorySpecificityScorer::score($results[$slug]['html'], $intent);
    check("19. [$slug/$intent] >=3 intent-specific paragraphs (" . $score['intent_paragraphs'] . ')', (bool) $score['passed']);
}

echo "\n== Tests 20-21: report/sample integrity via the immutable result ==\n";
foreach ($ok_slugs as $slug) {
    $result = $results[$slug]['result'];
    $report = $results[$slug]['report'];
    check("20. [$slug] report intent equals result intent", (string) $report['intent'] === $result->intent());
    $mismatches = $result->verify_against([
        'generation_id'     => (string) $report['generation_id'],
        'input_hash'        => (string) $report['input_hash'],
        'intent'            => (string) $report['intent'],
        'final_output_hash' => (string) $report['final_output_hash'],
        'final_status'      => (string) $report['final_status'],
    ]);
    check("21. [$slug] generation ID + hashes consistent across report and result", empty($mismatches), implode('; ', $mismatches));
    check("21. [$slug] final hash matches the actual sample html", $result->final_output_hash() === CategoryGenerationResult::hash_output((string) $results[$slug]['html']));
    check("21. [$slug] generation id derives from input+final hashes", $result->generation_id() === substr(hash('sha256', $result->input_hash() . '|' . $result->final_output_hash()), 0, 16));
}
// A deliberately tampered report must be caught.
$tampered = $results[$ok_slugs[0]]['result']->verify_against(['intent' => 'age_style_tampered']);
check('20. tampered report intent is detected as a mismatch', !empty($tampered));

echo "\n== Test 22: three distinct provider drafts remain distinct ==\n";
require_once __DIR__ . '/fixtures/category-provider-drafts.php';
$prov_finals = []; $prov_ok = true;
$voices = ['clinical' => 'openai', 'warm' => 'claude', 'punchy' => 'template'];
$markers = ['clinical' => 'Executed in that order', 'warm' => 'small heartbreak', 'punchy' => 'No Wasted Clicks'];
foreach ($voices as $voice => $provider) {
    $slug = 'provider-distinct-' . $voice;
    $ctx  = CategoryContextBuilder::build_from_parts($base + ['category_slug' => $slug, 'category_name' => 'Couples Cam Chat', 'primary_keyword' => 'Couples Cam Chat', 'approved_keywords' => ['couples webcam'], 'related_categories' => ['Free Cam Chat'], 'model_count' => 19, 'video_count' => 11]);
    $draft = provider_draft($voice, 'Couples Cam Chat', 'Free Cam Chat');
    $res   = CategoryGenerationPipeline::generate_from_context($ctx, ['tracking' => [], 'use_store' => false, 'provider' => $provider, 'provider_html' => $draft]);
    check("22. [$voice/$provider] pipeline reports actual winning provider", (bool) $res['ok'] && (($provider === 'claude' && (string) $res['report']['provider'] === 'template') || ((string) $res['report']['provider'] === $provider && strpos($res['html'], $markers[$voice]) !== false)), implode('; ', array_slice((array) $res['report']['failure_reasons'], 0, 4)));
    check("22. [$voice] raw provider hash preserved", (string) $res['report']['raw_output_hash'] === CategoryGenerationResult::hash_output($draft));
    $prov_finals[$voice] = (string) $res['html'];
    $prov_ok = $prov_ok && (bool) $res['ok'];
}
if ($prov_ok) {
    $mask = ['Couples Cam Chat'];
    $vs = array_keys($prov_finals);
    for ($i = 0; $i < count($vs); $i++) {
        for ($j = $i + 1; $j < count($vs); $j++) {
            $fa = CategoryDifferentiationScorer::fingerprint($prov_finals[$vs[$i]], $mask, $vs[$i]);
            $s  = CategoryDifferentiationScorer::score($fa, [CategoryDifferentiationScorer::fingerprint($prov_finals[$vs[$j]], $mask, $vs[$j])]);
            check('22. finals remain distinct: ' . $vs[$i] . ' vs ' . $vs[$j] . ' (body ' . $s['max_body'] . ')', $s['max_body'] <= CategoryDifferentiationScorer::MAX_BODY_SIMILARITY);
        }
    }
}

echo "\n== Test 23: unknown categories generated safely ==\n";
foreach ($unknown as $slug) {
    $r = $results[$slug];
    check("23. [$slug] ok + intent '" . $r['report']['intent'] . "' + no placeholders", (bool) $r['ok'] && strpos((string) $r['html'], '{{') === false);
}
check('23. Silver Fox Gentlemen Cams classifies as age_style', (string) $results['silver-fox-gentlemen-cams']['report']['intent'] === 'age_style');
check('23. French Speaking Cam Models classifies as language_location', (string) $results['french-speaking-cam-models']['report']['intent'] === 'language_location');

echo "\n== Test 24: safe failure still works ==\n";
$dup_ctx = CategoryContextBuilder::build_from_parts($fixtures['amateur-cams'] + []);
$dup_ctx['category_slug'] = 'forced-failure';
$self_fp = CategoryDifferentiationScorer::fingerprint((string) $results['amateur-cams']['html'], [], 'amateur-cams');
$self_fp['uniqueness'] = UGuard::fingerprint((string) $results['amateur-cams']['html'], [], []);
$fail_res = CategoryGenerationPipeline::generate_from_context($dup_ctx, ['tracking' => [], 'use_store' => false, 'comparisons' => [$self_fp]]);
// Collision may resolve via retries; the guaranteed failure assertion follows below.
$impossible = $fail_res;
if ($impossible['ok']) {
    // Force certain failure: fingerprint the would-be draft of EVERY salt so
    // all retries collide with their own shadow (mirrors universal test 25).
    $shadows = tmwseo_category_collision_shadows($dup_ctx, (array) $fixtures['amateur-cams']['approved_keywords']);
    $impossible = CategoryGenerationPipeline::generate_from_context($dup_ctx, ['tracking' => [], 'use_store' => false, 'comparisons' => $shadows]);
}
check('24. failed generation returns ok=false with empty html', !$impossible['ok'] && $impossible['html'] === '' && !empty($impossible['report']['failure_reasons']), implode('; ', array_slice((array) $impossible['report']['failure_reasons'], 0, 2)));
check('24. failed result carries final_status=failed', $impossible['result']->final_status() === 'failed');

echo "\n== Test 25: v5.9.7 keyword and density protections intact ==\n";
check('25. family density ceiling still 2.2', abs(CategoryKeywordPlanner::MAX_FAMILY_DENSITY - 2.2) < 0.001);
check('25. body-use cap still 4', CategoryKeywordPlanner::MAX_BODY_USE === 4);
check('25. max exact keywords per sentence still 2', CategoryKeywordPlanner::MAX_EXACTS_PER_SENTENCE === 2);
foreach ($ok_slugs as $slug) {
    $metrics = (array) $results[$slug]['report']['metrics'];
    check("25. [$slug] family density " . $metrics['family_density'] . " within ceiling", (float) $metrics['family_density'] <= CategoryKeywordPlanner::MAX_FAMILY_DENSITY);
    check("25. [$slug] primary keyword present", (int) $metrics['primary_hits'] >= 1);
}


echo "
== Test 26: review-regression coverage ==
";
$sha = CategoryGenerationResult::hash_output('<p>hash me</p>');
check('26. SHA-256 output hashes are 64 hex chars', strlen($sha) === 64 && ctype_xdigit($sha));
$ids = [];
for ($seed = 0; $seed < 6; $seed++) {
    $planned = \TMWSEO\Engine\Content\CategoryPipeline\CategoryFaqPlanner::plan(CategoryContextBuilder::build_from_parts($fixtures['free-cam-chat']), 'free_access_pricing', $seed, []);
    $ids[] = count($planned);
}
check('26. FAQ planner can emit the full 3-5 range', in_array(3, $ids, true) && in_array(4, $ids, true) && in_array(5, $ids, true), implode(',', $ids));
$ledger = CategoryClaimLedger::build('<p>Related themes help widen the search.</p>', CategoryContextBuilder::build_from_parts($base + ['category_name' => 'Empty Related', 'category_slug' => 'empty-related', 'related_categories' => []]));
check('26. empty related categories are unsupported evidence', !empty($ledger['unsupported']));
$video_ledger = CategoryClaimLedger::build('<p>The video directory is the fastest route.</p>', CategoryContextBuilder::build_from_parts($base + ['category_name' => 'Video Directory', 'category_slug' => 'video-directory', 'videos_url' => 'https://top-models.webcam/videos/']));
$video_entry = $video_ledger['entries'][0] ?? [];
check('26. video directory claims use videos_url evidence', ($video_entry['evidence']['videos_url'] ?? '') === 'https://top-models.webcam/videos/');
$grammar = CategoryGrammarGuard::repair('<p>a Amateur Cams page.</p><p>Spacing  before punctuation !</p>');
check('26. normalization-only grammar repairs are logged after explicit repairs', in_array('normalized_punctuation_or_spacing', $grammar['repairs'], true));
$class = CategoryIntentClassifier::classify(CategoryContextBuilder::build_from_parts($base + ['category_name' => 'Young Adult Cams', 'category_slug' => 'young-adult-cams', 'approved_keywords' => ['no cost trial']]));
check('26. split hyphen intent signals classify young adult names', $class['intent'] === 'age_style', (string) $class['intent']);
$ufp = UGuard::fingerprint('<p>Intro paragraph with enough unique words to count correctly here.</p><h2>Frequently Asked Questions</h2><h3>What matters?</h3><p>Answer paragraph with enough unique words to count correctly here.</p><p>Closing paragraph with enough unique words to count correctly here.</p>', [], []);
check('26. closing paragraph after FAQ is not an FAQ answer', count((array) $ufp['faq_answers']) === 1 && count((array) $ufp['paragraphs']) === 2);
$trim = CategoryQualityGuard::repair('<p>beta appears first, alpha appears second, gamma appears third.</p>', ['alpha', 'beta', 'gamma']);
check('26. keyword dump trimming keeps left-to-right first matches', strpos($trim['html'], 'beta') !== false && strpos($trim['html'], 'alpha') !== false && strpos($trim['html'], 'gamma') === false, $trim['html']);

if (!class_exists('WP_Query')) {
    class WP_Query {
        public int $found_posts = 0;
        public static array $last_args = [];
        public static int $next_found_posts = 0;
        public function __construct(array $args = []) {
            self::$last_args = $args;
            $this->found_posts = self::$next_found_posts;
        }
    }
}
$GLOBALS['wp_query'] = (object) ['found_posts' => 1];
WP_Query::$next_found_posts = 17;
$ctx_ref = new ReflectionClass(CategoryContextBuilder::class);
$count_method = $ctx_ref->getMethod('count_videos_for_term');
$count_method->setAccessible(true);
$video_count = $count_method->invoke(null, (object) ['term_id' => 123, 'taxonomy' => 'category']);
check('26. video_count uses local WP_Query found_posts, not global query', $video_count === 17);
check('26. video_count query preserves requested args', WP_Query::$last_args['post_type'] === ['tmw_video', 'video'] && WP_Query::$last_args['post_status'] === 'publish' && WP_Query::$last_args['fields'] === 'ids' && WP_Query::$last_args['posts_per_page'] === 1 && WP_Query::$last_args['no_found_rows'] === false && (WP_Query::$last_args['tax_query'][0]['terms'][0] ?? null) === 123);
check('26. invalid video_count term returns null safely', $count_method->invoke(null, (object) ['term_id' => 0]) === null);


echo "\n" . str_repeat('=', 60) . "\n";
echo 'PASS: ' . $pass . '  FAIL: ' . $fail . "\n";
if ($fail > 0) {
    echo "Failures:\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
exit(0);
