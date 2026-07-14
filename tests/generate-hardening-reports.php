<?php
/**
 * v5.9.8 verification-report generator. Regenerates the ten categories
 * deterministically and emits the six delivery reports, every figure taken
 * from the SAME run's immutable results (generation IDs included so each
 * report row is traceable to its sample).
 *
 * Usage: php tests/generate-hardening-reports.php <output-dir>
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('ABSPATH', sys_get_temp_dir() . '/');
require __DIR__ . '/bootstrap/wordpress-stubs.php';

$pipeline_dir = dirname(__DIR__) . '/includes/content/category-pipeline/';
foreach ([
    'context-builder', 'intent-classifier', 'keyword-planner', 'content-planner',
    'draft-composer', 'quality-guard', 'factual-safety', 'grammar-guard',
    'paragraph-uniqueness-guard', 'claim-ledger', 'specificity-scorer',
    'faq-reuse-guard', 'generation-result', 'differentiation-scorer',
    'faq-planner', 'final-validator', 'generation-pipeline',
] as $c) { require_once $pipeline_dir . 'class-category-' . $c . '.php'; }

use TMWSEO\Engine\Content\CategoryPipeline\CategoryContextBuilder;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGrammarGuard;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryParagraphUniquenessGuard as UGuard;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryClaimLedger;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationResult;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryDifferentiationScorer;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationPipeline;

$out_dir = rtrim((string) ($argv[1] ?? '.'), '/');
@mkdir($out_dir, 0777, true);

$base = ['site_name' => 'Top-Models.Webcam', 'models_url' => 'https://top-models.webcam/models/', 'videos_url' => 'https://top-models.webcam/videos/'];
$fixtures = [
    'amateur-cams'      => $base + ['category_slug' => 'amateur-cams', 'category_name' => 'Amateur Cams', 'primary_keyword' => 'Amateur Cams', 'approved_keywords' => ['amateur webcam', 'amateur tv cams', 'live amateur sex cams', 'amateur sex chat'], 'related_categories' => ['Big Boob Cam', 'Blonde Cam Models'], 'model_count' => 40, 'video_count' => 25],
    'big-boob-cam'      => $base + ['category_slug' => 'big-boob-cam', 'category_name' => 'Big Boob Cam', 'primary_keyword' => 'Big Boob Cam', 'approved_keywords' => ['big boobs webcam', 'huge tits cam', 'big breast cams'], 'related_categories' => ['Blonde Cam Models', 'Latina Cam Models'], 'model_count' => 33, 'video_count' => 18],
    'blonde-cam-models' => $base + ['category_slug' => 'blonde-cam-models', 'category_name' => 'Blonde Cam Models', 'primary_keyword' => 'Blonde Cam Models', 'approved_keywords' => ['blonde webcam girls', 'blonde cam chat'], 'related_categories' => ['Latina Cam Models', 'Amateur Cams'], 'model_count' => 21, 'video_count' => 12],
    'latina-cam-models' => $base + ['category_slug' => 'latina-cam-models', 'category_name' => 'Latina Cam Models', 'primary_keyword' => 'Latina Cam Models', 'approved_keywords' => ['latina webcam', 'latina cam chat'], 'related_categories' => ['Blonde Cam Models', 'Free Cam Chat'], 'model_count' => 27, 'video_count' => 9],
    'free-cam-chat'     => $base + ['category_slug' => 'free-cam-chat', 'category_name' => 'Free Cam Chat', 'primary_keyword' => 'Free Cam Chat', 'approved_keywords' => ['free webcam chat', 'free adult cams'], 'related_categories' => ['Amateur Cams', 'Latina Cam Models'], 'model_count' => 55, 'video_count' => 30],
    'silver-fox-gentlemen-cams' => $base + ['category_slug' => 'silver-fox-gentlemen-cams', 'category_name' => 'Silver Fox Gentlemen Cams', 'primary_keyword' => 'Silver Fox Gentlemen Cams', 'approved_keywords' => ['silver fox cams', 'gentlemen webcam'], 'related_categories' => ['Amateur Cams'], 'model_count' => 8, 'video_count' => 4],
    'redhead-webcam-models'     => $base + ['category_slug' => 'redhead-webcam-models', 'category_name' => 'Redhead Webcam Models', 'primary_keyword' => 'Redhead Webcam Models', 'approved_keywords' => ['redhead cams', 'ginger webcam'], 'related_categories' => ['Blonde Cam Models'], 'model_count' => 14, 'video_count' => 6],
    'couples-cam-chat'          => $base + ['category_slug' => 'couples-cam-chat', 'category_name' => 'Couples Cam Chat', 'primary_keyword' => 'Couples Cam Chat', 'approved_keywords' => ['couples webcam', 'couple cams live'], 'related_categories' => ['Free Cam Chat'], 'model_count' => 19, 'video_count' => 11],
    'french-speaking-cam-models' => $base + ['category_slug' => 'french-speaking-cam-models', 'category_name' => 'French Speaking Cam Models', 'primary_keyword' => 'French Speaking Cam Models', 'approved_keywords' => ['french cams', 'french webcam chat'], 'related_categories' => ['Latina Cam Models'], 'model_count' => 7, 'video_count' => 3],
    'tattooed-cam-performers'   => $base + ['category_slug' => 'tattooed-cam-performers', 'category_name' => 'Tattooed Cam Performers', 'primary_keyword' => 'Tattooed Cam Performers', 'approved_keywords' => ['tattoo cams', 'inked webcam models'], 'related_categories' => ['Redhead Webcam Models'], 'model_count' => 16, 'video_count' => 22],
];

$results = [];
foreach ($fixtures as $slug => $parts) {
    $ctx = CategoryContextBuilder::build_from_parts($parts);
    $results[$slug] = CategoryGenerationPipeline::generate_from_context($ctx, ['tracking' => []]);
}
$slugs  = array_keys($results);
$window = CategoryGenerationPipeline::UNIQUENESS_WINDOW_PAGES;

$hdr = static function (string $title): string {
    return "TMW SEO Engine v5.9.8 — $title\nGenerated from one deterministic run; generation IDs bind rows to samples.\n" . str_repeat('=', 78) . "\n\n";
};
$id = static fn($r) => $r['result']->generation_id();

// ── 1. paragraph-similarity report ─────────────────────────────────────────
$body = $hdr('PARAGRAPH SIMILARITY REPORT');
$body .= "Enforced limits (within the {$window}-recent-page uniqueness window):\n";
$body .= sprintf("  paragraph <= %.2f   closing <= %.2f   intro <= %.2f   faq answer <= %.2f   shared sentence templates <= %d\n\n",
    UGuard::MAX_PARAGRAPH_SIMILARITY, UGuard::MAX_CLOSING_SIMILARITY, UGuard::MAX_INTRO_SIMILARITY, UGuard::MAX_FAQ_ANSWER_SIMILARITY, UGuard::MAX_SHARED_SENTENCE_TEMPLATES);
$fps = [];
foreach ($slugs as $slug) {
    $mask = [$fixtures[$slug]['category_name'], $fixtures[$slug]['primary_keyword']];
    $u    = $results[$slug]['result']->get('uniqueness');
    $fps[$slug] = UGuard::fingerprint((string) $results[$slug]['html'], $mask, []);
    $body .= sprintf("%-28s gen=%s  max_para=%.3f  max_close=%.3f  max_intro=%.3f  max_faq=%.3f  shared_templates=%d  violations=%d\n",
        $slug, $id($results[$slug]), $u['max_paragraph'], $u['max_closing'], $u['max_intro'], $u['max_faq_answer'], $u['max_shared_templates'], count($u['violations']));
}
$body .= "\nIndependent pairwise recomputation (window pairs):\n";
$gmax = ['para' => 0.0, 'close' => 0.0, 'intro' => 0.0, 'faq' => 0.0];
for ($i = 0; $i < count($slugs); $i++) {
    for ($j = $i + 1; $j < count($slugs); $j++) {
        if (($j - $i) > $window) { continue; }
        $a = $fps[$slugs[$i]]; $b = $fps[$slugs[$j]];
        $mp = 0.0;
        foreach ((array) $a['paragraphs'] as $pa) { foreach ((array) $b['paragraphs'] as $pb) { $mp = max($mp, UGuard::jaccard((array) $pa['s'], (array) $pb['s'])); } }
        $mc = (!empty($a['closing']) && !empty($b['closing'])) ? UGuard::jaccard((array) $a['closing']['s'], (array) $b['closing']['s']) : 0.0;
        $mi = (!empty($a['intro']) && !empty($b['intro'])) ? UGuard::jaccard((array) $a['intro']['s'], (array) $b['intro']['s']) : 0.0;
        $mf = 0.0;
        foreach ((array) $a['faq_answers'] as $fa) { foreach ((array) $b['faq_answers'] as $fb) { $mf = max($mf, UGuard::jaccard((array) $fa['s'], (array) $fb['s'])); } }
        $gmax['para'] = max($gmax['para'], $mp); $gmax['close'] = max($gmax['close'], $mc);
        $gmax['intro'] = max($gmax['intro'], $mi); $gmax['faq'] = max($gmax['faq'], $mf);
        $body .= sprintf("  %-26s vs %-26s para=%.3f close=%.3f intro=%.3f faq=%.3f\n", $slugs[$i], $slugs[$j], $mp, $mc, $mi, $mf);
    }
}
$body .= sprintf("\nWorst observed: para=%.3f close=%.3f intro=%.3f faq=%.3f — ALL WITHIN LIMITS: %s\n",
    $gmax['para'], $gmax['close'], $gmax['intro'], $gmax['faq'],
    ($gmax['para'] <= UGuard::MAX_PARAGRAPH_SIMILARITY && $gmax['close'] <= UGuard::MAX_CLOSING_SIMILARITY && $gmax['intro'] <= UGuard::MAX_INTRO_SIMILARITY && $gmax['faq'] <= UGuard::MAX_FAQ_ANSWER_SIMILARITY) ? 'yes' : 'NO');
file_put_contents("$out_dir/report-paragraph-similarity.txt", $body);

// ── 2. FAQ reuse report ─────────────────────────────────────────────────────
$body = $hdr('FAQ REUSE REPORT');
$body .= "Cooldown window: " . \TMWSEO\Engine\Content\CategoryPipeline\CategoryFaqReuseGuard::COOLDOWN_PAGES . " pages; max generic per page: " . \TMWSEO\Engine\Content\CategoryPipeline\CategoryFaqReuseGuard::MAX_GENERIC_PER_PAGE . ".\n\n";
$all_ids = [];
foreach ($slugs as $slug) {
    $ids = (array) $results[$slug]['result']->get('faq_ids');
    $body .= sprintf("%-28s gen=%s  faqs=%d  ids: %s\n", $slug, $id($results[$slug]), count($ids), implode(', ', $ids));
    foreach ($ids as $fid) { $all_ids[$fid][] = $slug; }
}
$reused = array_filter($all_ids, static fn($u) => count($u) > 1);
$body .= "\nVariant reuse across the ten pages: " . count($reused) . " (zero expected)\n";
foreach ($reused as $fid => $users) { $body .= "  REUSED $fid: " . implode(', ', $users) . "\n"; }
file_put_contents("$out_dir/report-faq-reuse.txt", $body);

// ── 3. claim ledger report ──────────────────────────────────────────────────
$body = $hdr('CLAIM LEDGER REPORT');
$body .= "Classes: context_verified | site_config | plugin_data | safe_general. Unsupported claims are prohibited and fail validation.\n\n";
foreach ($slugs as $slug) {
    $l = $results[$slug]['result']->get('claim_ledger');
    $body .= sprintf("%-28s gen=%s  %s  unsupported=%d  passed=%s\n", $slug, $id($results[$slug]), json_encode($l['counts']), count((array) $l['unsupported']), $l['passed'] ? 'yes' : 'NO');
    foreach ((array) $l['entries'] as $e) {
        $body .= sprintf("    [%-16s] %-22s x%d evidence=%s  \"%s\"\n", $e['class'], $e['claim_type'], $e['matches'], is_array($e['evidence']) ? json_encode($e['evidence']) : $e['evidence'], $e['snippet']);
    }
}
file_put_contents("$out_dir/report-claim-ledger.txt", $body);

// ── 4. grammar repair report ────────────────────────────────────────────────
$body = $hdr('GRAMMAR REPAIR REPORT');
$body .= "Regression checks:\n";
foreach ([['Amateur', 'an'], ['Adult', 'an'], ['Asian', 'an'], ['Ebony', 'an'], ['European', 'a']] as [$w, $want]) {
    $got = CategoryGrammarGuard::article_for($w);
    $body .= sprintf("  article for '%s Cams': %s (expected %s) %s\n", $w, $got, $want, $got === $want ? 'ok' : 'FAIL');
}
$demo = CategoryGrammarGuard::repair('<p>Results from a Amateur Cams search.</p>');
$body .= "  repair demo: 'a Amateur Cams search' -> " . (strpos($demo['html'], 'an Amateur Cams') !== false ? "'an Amateur Cams search' ok" : 'FAIL') . "\n\n";
foreach ($slugs as $slug) {
    $issues  = CategoryGrammarGuard::analyze((string) $results[$slug]['html']);
    $repairs = (array) $results[$slug]['result']->get('grammar_repairs');
    $body .= sprintf("%-28s gen=%s  residual_issues=%d  repairs_applied=%d%s\n", $slug, $id($results[$slug]), count($issues), count($repairs), $repairs ? '  (' . implode(' | ', array_slice($repairs, 0, 3)) . ')' : '');
}
file_put_contents("$out_dir/report-grammar-repair.txt", $body);

// ── 5. provider distinction report ──────────────────────────────────────────
$body = $hdr('PROVIDER DISTINCTION REPORT');
$body .= "Three deliberately distinct drafts (clinical/openai, warm/claude, punchy/template) for the same category, each through the full shared pipeline.\nSee tests/run-category-quality-hardening-smoke.php test 22 for the executable version; figures below are from this run.\n\n";
$voices = ['clinical' => 'openai', 'warm' => 'claude', 'punchy' => 'template'];
// Reuse the canonical drafts from the executable test so the report and the
// test can never diverge.
$smoke = (string) file_get_contents(__DIR__ . '/run-category-quality-hardening-smoke.php');
if (preg_match('/function provider_draft.*?\n\}/s', $smoke, $m)) {
    eval($m[0]); // defines provider_draft() exactly as the test does
}
$finals = [];
foreach ($voices as $voice => $provider) {
    $ctx = CategoryContextBuilder::build_from_parts($base + ['category_slug' => "provider-distinct-$voice", 'category_name' => 'Couples Cam Chat', 'primary_keyword' => 'Couples Cam Chat', 'approved_keywords' => ['couples webcam'], 'related_categories' => ['Free Cam Chat'], 'model_count' => 19, 'video_count' => 11]);
    $res = CategoryGenerationPipeline::generate_from_context($ctx, ['tracking' => [], 'use_store' => false, 'provider' => $provider, 'provider_html' => provider_draft($voice, 'Couples Cam Chat', 'Free Cam Chat')]);
    $finals[$voice] = (string) $res['html'];
    $body .= sprintf("%-9s provider=%-8s gen=%s ok=%s raw=%s repaired=%s final=%s\n", $voice, $provider, $res['result']->generation_id(), $res['ok'] ? 'yes' : 'NO', substr((string) $res['report']['raw_output_hash'], 0, 12), substr((string) $res['report']['repaired_output_hash'], 0, 12), substr((string) $res['report']['final_output_hash'], 0, 12));
    $body .= '          stage paragraph diffs: ' . json_encode($res['result']->get('stage_diffs')) . "\n";
}
$vs = array_keys($finals);
$body .= "\nPairwise final body similarity (limit " . CategoryDifferentiationScorer::MAX_BODY_SIMILARITY . "):\n";
for ($i = 0; $i < count($vs); $i++) {
    for ($j = $i + 1; $j < count($vs); $j++) {
        $fa = CategoryDifferentiationScorer::fingerprint($finals[$vs[$i]], ['Couples Cam Chat'], $vs[$i]);
        $sc = CategoryDifferentiationScorer::score($fa, [CategoryDifferentiationScorer::fingerprint($finals[$vs[$j]], ['Couples Cam Chat'], $vs[$j])]);
        $body .= sprintf("  %-9s vs %-9s body=%.3f %s\n", $vs[$i], $vs[$j], $sc['max_body'], $sc['max_body'] <= CategoryDifferentiationScorer::MAX_BODY_SIMILARITY ? 'ok' : 'FAIL');
    }
}
file_put_contents("$out_dir/report-provider-distinction.txt", $body);

// ── 6. report/sample integrity report ───────────────────────────────────────
$body = $hdr('REPORT / SAMPLE INTEGRITY REPORT');
$body .= "The report is BUILT FROM the immutable CategoryGenerationResult; the checks below verify the binding independently per page.\n\n";
foreach ($slugs as $slug) {
    $r = $results[$slug]['result']; $rep = $results[$slug]['report'];
    $mismatch = $r->verify_against([
        'generation_id' => (string) $rep['generation_id'], 'input_hash' => (string) $rep['input_hash'],
        'intent' => (string) $rep['intent'], 'final_output_hash' => (string) $rep['final_output_hash'],
        'final_status' => (string) $rep['final_status'],
    ]);
    $hash_ok = $r->final_output_hash() === CategoryGenerationResult::hash_output((string) $results[$slug]['html']);
    $gid_ok  = $r->generation_id() === substr(sha1($r->input_hash() . '|' . $r->final_output_hash()), 0, 16);
    $body .= sprintf("%-28s gen=%s intent=%-19s report==result: %s  html-hash: %s  gid-derivation: %s\n",
        $slug, $r->generation_id(), $r->intent(), empty($mismatch) ? 'ok' : 'MISMATCH', $hash_ok ? 'ok' : 'FAIL', $gid_ok ? 'ok' : 'FAIL');
}
$body .= "\nTamper detection demo: feeding intent 'age_style_tampered' into verify_against() -> " . (count($results[$slugs[0]]['result']->verify_against(['intent' => 'age_style_tampered'])) > 0 ? 'detected ok' : 'FAIL') . "\n";
file_put_contents("$out_dir/report-sample-integrity.txt", $body);

echo "reports written to $out_dir\n";
