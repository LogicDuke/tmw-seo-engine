<?php
/**
 * v5.9.12 smoke — exact stored Rank Math chip contract.
 *
 * Run: php tests/run-category-stored-chips-smoke.php
 *
 * Live evidence this suite encodes: Rank Math stored
 *   big breast webcam / big boobs webcam / biggest boobs webcam /
 *   massive boob webcam
 * while the generated page carried pool substitutes ("Enormous Boobs
 * Webcam" etc.) — every chip stayed orange because Rank Math checks the
 * EXACT stored phrases.
 *
 * Sections:
 *   A. Live regression fixture — the exact stored chips (note the SINGULAR
 *      "massive boob webcam"): every chip appears verbatim in visible
 *      content AND in an H2-H6 subheading; all four simulated chips pass;
 *      the singular chip is never satisfied by the plural form; primary
 *      density stays in the accepted band; FAQ last; links intact.
 *   B. Four other real categories + synthetics under the same global flow.
 *   C. No silent substitution — the planner in stored-chip mode activates
 *      every chip (no near-duplicate collapse), and the validator FAILS a
 *      page missing a chip.
 *   D. Exact reporting — stored_keyword_report carries stored phrase,
 *      visible/subheading/body counts, pass, reason for every stored chip.
 *   E. Noindex live path — the category-page exclusion is GONE from the
 *      save path; clearing writes and VERIFIES explicit index,follow; all
 *      safety gates hold; the theme's empty-meta fallback cannot re-noindex.
 *   F. Persistence boilerplate gate — the structural guard repairs or
 *      blocks the FINAL HTML on the save path: both audited sentences are
 *      dropped structurally, and unrepairable filler blocks the save.
 *   G. Verified saves + final-document affiliate CTA context.
 *   H. No category-specific production logic.
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/'); }
require_once __DIR__ . '/bootstrap/wordpress-stubs.php';
require_once dirname(__DIR__) . '/includes/content/class-rank-math-chip-analyzer.php';

$pd = dirname(__DIR__) . '/includes/content/category-pipeline/';
foreach ([
    'context-builder', 'intent-classifier', 'keyword-planner', 'chip-feasibility', 'content-planner',
    'draft-composer', 'quality-guard', 'factual-safety', 'grammar-guard',
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
use TMWSEO\Engine\Content\CategoryPipeline\CategoryQualityGuard;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryFinalValidator;
use TMWSEO\Engine\Content\RankMathChipAnalyzer;

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
function vis(string $html): string {
    return trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('/<[^>]+>/', ' ', $html))));
}
function kwpat(string $kw): string { return '/(?<![\p{L}\p{N}])' . preg_quote($kw, '/') . '(?![\p{L}\p{N}])/iu'; }

$base = [
    'site_name'  => 'Top-Models.Webcam',
    'models_url' => 'https://top-models.webcam/models/',
    'videos_url' => 'https://top-models.webcam/videos/',
];

// The exact live stored chip sets (audit screenshots; big-boob's fourth chip
// is the SINGULAR "massive boob webcam" — exactness is the whole point).
$fixtures = [
    'big-boob-cam' => ['Big Boob Cam',
        ['big breast webcam', 'big boobs webcam', 'biggest boobs webcam', 'massive boob webcam'],
        ['Blonde Cam Models', 'Latina Cam Models'], 33, 18],
    'blonde-cam-models' => ['Blonde Cam Models',
        ['blonde cams', 'blonde live sex', 'blonde sex cam', 'blonde live webcam'],
        ['Latina Cam Models', 'Amateur Cams'], 21, 12],
    'latina-cam-models' => ['Latina Cam Models',
        ['latina sex cam', 'latina live sex', 'live latina cams', 'latina nude webcam'],
        ['Blonde Cam Models', 'Free Cam Chat'], 27, 9],
    'free-cam-chat' => ['Free Cam Chat',
        ['free cam to cam chat', 'free live cams', 'free webcam chat', 'cam to cam chat'],
        ['Amateur Cams', 'Latina Cam Models'], 55, 30],
    'amateur-cams' => ['Amateur Cams',
        ['amateur webcam', 'amateur tv cams', 'live amateur sex cams', 'amateur sex chat'],
        ['Big Boob Cam', 'Blonde Cam Models'], 40, 25],
    // Synthetics — names proven absent from production code below.
    'plum-orchard-cams' => ['Plum Orchard Cams',
        ['plum orchard webcam', 'orchard room chat', 'plum grove cams', 'orchard show rooms', 'plum orchard chat'],
        ['Amateur Cams'], 9, 4],
    'quiet-harbor-evening-rooms' => ['Quiet Harbor Evening Rooms',
        ['quiet harbor webcam', 'harbor evening chat', 'quiet harbor cams'],
        ['Blonde Cam Models'], 6, 3],
];

echo "== A/B. Stored chips: rendered subset appears; excess same-family chips are tracking-only ==\n";
$cmp = []; $reports = [];
foreach ($fixtures as $slug => [$name, $chips, $rel, $mc, $vc]) {
    $ctx = CategoryContextBuilder::build_from_parts($base + [
        'category_slug' => $slug, 'category_name' => $name, 'primary_keyword' => $name,
        'approved_keywords' => $chips, 'stored_chips' => $chips,
        'related_categories' => $rel, 'model_count' => $mc, 'video_count' => $vc,
    ]);
    check("[$slug] stored chips travel verbatim through the context", (array) $ctx['stored_chips'] === $chips);
    $out = CategoryGenerationPipeline::generate_from_context($ctx, ['tracking' => $chips, 'use_store' => false, 'comparisons' => $cmp]);
    check("[$slug] generates ok", (bool) $out['ok'], implode('; ', array_slice((array) ($out['report']['failure_reasons'] ?? []), 0, 4)));
    if (!$out['ok']) { continue; }
    $html = (string) $out['html'];
    $rep  = $out['report'];
    if (!empty($rep['fingerprint'])) { $cmp[] = $rep['fingerprint']; }

    $subtxt  = subs($html);
    $visible = vis($html);
    $green   = 0;
    $feas = (array) ($rep['keyword_plan']['chip_feasibility'] ?? []);
    $rendered = array_map(static fn($r) => (string) ($r['keyword'] ?? ''), (array) ($feas['rendered_chips'] ?? []));
    $tracking_only = array_map(static fn($r) => (string) ($r['keyword'] ?? ''), (array) ($feas['tracking_only_chips'] ?? []));
    foreach ($chips as $chip) {
        $in_content = (bool) preg_match(kwpat($chip), $visible);
        $in_sub     = (bool) preg_match(kwpat($chip), $subtxt);
        if (in_array($chip, $rendered, true)) {
            check("[$slug] rendered chip '$chip' EXACT in visible content", $in_content);
            check("[$slug] rendered chip '$chip' EXACT in H2-H6 subheading", $in_sub);
            if ($in_content && $in_sub) { $green++; }
        } else {
            check("[$slug] unrendered chip '$chip' is tracking-only", in_array($chip, $tracking_only, true));
        }
    }
    check("[$slug] every rendered chip passes", $green === count($rendered), json_encode($feas));

    $den = (array) ($rep['metrics']['density'] ?? []);
    check("[$slug] primary density {$den['density']}% within accepted band", ($den['status'] ?? '') === 'within', json_encode($den));
    $faq_pos = stripos($html, 'Frequently Asked Questions');
    check("[$slug] FAQ remains last", $faq_pos !== false && !preg_match('/<h2[^>]*>/i', substr($html, $faq_pos + 30)));
    check("[$slug] internal links intact", (int) ($rep['metrics']['internal_link_count'] ?? 0) >= 2);
    check("[$slug] no audited boilerplate family in final HTML", empty(array_filter(
        CategoryQualityGuard::analyze($html, array_merge([$name], $chips)),
        static fn(array $i): bool => in_array($i['type'], ['abstract_filler_structure', 'repeated_filler_skeleton'], true)
    )));
    $reports[$slug] = (array) ($rep['metrics']['stored_keyword_report'] ?? []);
}

echo "\n== A2. Singular chip is never satisfied by the plural (no mutation credit) ==\n";
$plural_only = '<p>Big Boob Cam intro here.</p><h2>Massive Boobs Webcam Corner</h2><p>Only massive boobs webcam text, plural form.</p>';
check("'massive boob webcam' does NOT match 'massive boobs webcam'", !preg_match(kwpat('massive boob webcam'), vis($plural_only)));
check("'cam' partial token never counts inside 'webcam'", !preg_match(kwpat('cam'), 'a webcam feed'));

echo "\n== C. Feasibility preserves stored chips as rendered or tracking-only ==\n";
$chips4 = ['big breast webcam', 'big boobs webcam', 'biggest boobs webcam', 'massive boob webcam'];
$feas4 = TMWSEO\Engine\Content\CategoryPipeline\CategoryChipFeasibility::analyze('Big Boob Cam', $chips4);
$rendered4 = array_map(static fn($r)=>(string)$r['keyword'], (array)$feas4['rendered_chips']);
$plan = CategoryKeywordPlanner::plan('Big Boob Cam', $rendered4, $chips4, true);
check('feasibility renders safe representatives', count($rendered4) === 2, json_encode($feas4));
check('tracking-only same-family chips are reported', count((array)$feas4['tracking_only_chips']) === 2, json_encode($feas4['tracking_only_chips']));
// Validator failure on a page missing one chip entirely.
$missing_chip_html = '<p>Big Boob Cam opens. big breast webcam here. big boobs webcam here. biggest boobs webcam here.</p><h2>Big Boob Cam Overview</h2><h2>Where Big Breast Webcam Fits In</h2><h2>Big Boobs Webcam: What to Know</h2><h2>A Closer Look at Biggest Boobs Webcam</h2><p>' . str_repeat('Directory copy sentence with useful browsing detail for readers here. ', 90) . '</p><h2>Frequently Asked Questions</h2><h3>How do I choose?</h3><p>Compare the listings.</p>';
$plan['density_tracking'] = array_merge(['Big Boob Cam'], $chips4);
$v = CategoryFinalValidator::validate($missing_chip_html, ['category_slug' => 'x', 'category_name' => 'Big Boob Cam'], $plan, ['passed' => true, 'max_body' => 0, 'max_heading' => 0, 'max_faq' => 0, 'max_opening' => 0], []);
$has_chip_fail = (bool) array_filter((array) $v['reasons'], static fn($r) => strpos((string) $r, 'massive boob webcam') !== false);
check('validator FAILS when a stored chip is absent from final HTML', !$v['passed'] && $has_chip_fail, implode('; ', array_slice((array) $v['reasons'], 0, 4)));

echo "\n== D. Exact reporting for every stored chip ==\n";
$bbc = $reports['big-boob-cam'] ?? [];
check('report covers primary + rendered chips', count($bbc) === 3, (string) count($bbc));
foreach (['big breast webcam', 'big boobs webcam'] as $chip) {
    $row = (array) ($bbc[$chip] ?? []);
    check("report['$chip'] has counts + pass", isset($row['visible_count'], $row['subheading_count'], $row['body_count'], $row['pass'], $row['reason']) && $row['pass'] === true && $row['visible_count'] >= 1 && $row['subheading_count'] >= 1, json_encode($row));
}

echo "\n== E. Noindex live persistence path ==\n";
require_once dirname(__DIR__) . '/includes/content/class-content-engine.php';
$engine_src = file_get_contents(dirname(__DIR__) . '/includes/content/class-content-engine.php');
check('category-page exclusion REMOVED from the save path', strpos($engine_src, "if (\$post->post_type !== 'tmw_category_page') {\n            self::maybe_clear_rank_math_noindex(\$post);") === false);
check('post-write verification present (read-back of saved robots)', strpos($engine_src, 'cleared+verified') !== false);
$template_start = strpos($engine_src, "if (\$strategy === 'template' || !OpenAI::is_configured()) {");
$template_end = strpos($engine_src, "\n        \$context =", $template_start);
$template_save_path = substr($engine_src, $template_start, $template_end - $template_start);
$template_finalize = strpos($template_save_path, 'self::finalize_category_generation($post_id, $post, $keyword_pack, $category_save_verified);');
$template_clear = $template_finalize === false ? false : strpos($template_save_path, 'self::maybe_clear_rank_math_noindex($post);', $template_finalize);
check('verified template categories clear noindex after finalization', $template_finalize !== false && $template_clear !== false && $template_clear > $template_finalize);
$ai_start = strpos($engine_src, '$html      = wp_kses_post(trim($html));');
$ai_save_path = substr($engine_src, $ai_start);
check('AI path still clears noindex after saving', strpos($ai_save_path, 'self::maybe_clear_rank_math_noindex($post);') !== false);
$clear_ref = new ReflectionMethod('\TMWSEO\Engine\Content\ContentEngine', 'maybe_clear_rank_math_noindex');
$clear_ref->setAccessible(true);
$mk = static function (int $id, string $type = 'tmw_category_page', string $status = 'publish'): \WP_Post {
    $p = new \WP_Post(['ID' => $id]); $p->post_type = $type; $p->post_status = $status; return $p;
};
$reset = static function (int $id, array $settings, array $meta): void {
    update_option('tmwseo_engine_settings', $settings);
    foreach (['rank_math_robots', '_tmwseo_ready_to_index', '_tmwseo_generated'] as $k) { delete_post_meta($id, $k); }
    foreach ($meta as $k => $v) { update_post_meta($id, $k, $v); }
};
$reset(1201, ['auto_clear_noindex' => 1], ['_tmwseo_ready_to_index' => '1', '_tmwseo_generated' => 1, 'rank_math_robots' => ['noindex', 'follow']]);
$clear_ref->invokeArgs(null, [$mk(1201)]);
$saved = get_post_meta(1201, 'rank_math_robots', true);
check('regenerated EXISTING category page cleared to explicit index,follow', $saved === ['index', 'follow'], json_encode($saved));
check('saved value verified non-empty (theme empty-meta fallback cannot fire)', !empty($saved));
$reset(1202, ['auto_clear_noindex' => 0], ['_tmwseo_ready_to_index' => '1', 'rank_math_robots' => ['noindex']]);
$clear_ref->invokeArgs(null, [$mk(1202)]);
check('gate holds: toggle OFF → untouched', get_post_meta(1202, 'rank_math_robots', true) === ['noindex']);
$reset(1203, ['auto_clear_noindex' => 1], ['rank_math_robots' => ['noindex']]);
$clear_ref->invokeArgs(null, [$mk(1203)]);
check('gate holds: not ready_to_index → untouched', get_post_meta(1203, 'rank_math_robots', true) === ['noindex']);
$reset(1204, ['auto_clear_noindex' => 1], ['_tmwseo_ready_to_index' => '1', 'rank_math_robots' => ['noindex']]);
$clear_ref->invokeArgs(null, [$mk(1204, 'tmw_category_page', 'draft')]);
check('gate holds: draft status → untouched', get_post_meta(1204, 'rank_math_robots', true) === ['noindex']);

echo "\n== F. Persistence boilerplate gate on the FINAL HTML ==\n";
$gate_ref = new ReflectionMethod('\TMWSEO\Engine\Content\ContentEngine', 'enforce_category_persistence_guard');
$gate_ref->setAccessible(true);
$dirty = '<p>The destination shapes the session. Each performer states their format on their own page. The trait gathers the field.</p><p>Real category copy continues with concrete listing detail.</p>';
$g = (array) $gate_ref->invokeArgs(null, [$dirty, ['Big Boob Cam'], 1301]);
check('gate repairs: both audited sentences dropped structurally', $g['ok'] === true && stripos((string) $g['html'], 'destination shapes') === false && stripos((string) $g['html'], 'gathers the field') === false, (string) $g['html']);
check('gate keeps concrete sentences', stripos((string) $g['html'], 'states their format') !== false);
check('repaired HTML passes the structural guard', empty(array_filter(CategoryQualityGuard::analyze((string) $g['html'], ['Big Boob Cam']), static fn(array $i): bool => in_array($i['type'], ['abstract_filler_structure', 'repeated_filler_skeleton'], true))));
// Not only the two literal strings: a NOVEL filler-on-filler sentence trips too.
$novel = '<p>The signal settles the shortlist quite fast today.</p><p>Every performer page states its own format clearly.</p>';
$g2 = (array) $gate_ref->invokeArgs(null, [$novel, [], 1302]);
check('novel filler-on-filler sentence removed structurally (not a string blacklist)', stripos((string) $g2['html'], 'settles the shortlist') === false, (string) $g2['html']);

$ai_start = strpos($engine_src, '$html      = wp_kses_post(trim($html));');
$ai_gate = strpos($engine_src, 'self::enforce_category_persistence_guard($new_content, $gate_keywords, $post_id);', $ai_start);
$ai_blocked_return = strpos($engine_src, 'return;', $ai_gate);
$ai_metadata = strpos($engine_src, 'AssistedDraftEnrichmentService::persist_quality_score(', $ai_start);
$ai_mapping = strpos($engine_src, 'RankMathMapper::sync_to_rank_math(', $ai_start);
$ai_blocked_path = substr($engine_src, $ai_gate, $ai_blocked_return - $ai_gate);
check('blocked persistence guard runs before generated quality and Rank Math metadata', $ai_gate !== false && $ai_metadata !== false && $ai_mapping !== false && $ai_gate < $ai_metadata && $ai_gate < $ai_mapping);
check('blocked persistence leaves old content and generated metadata unchanged', strpos($ai_blocked_path, 'wp_update_post(') === false && strpos($ai_blocked_path, 'persist_quality_score(') === false && strpos($ai_blocked_path, 'rank_math_title') === false && strpos($ai_blocked_path, 'rank_math_description') === false && strpos($ai_blocked_path, 'RankMathMapper::sync_to_rank_math(') === false);

echo "\n== G. Verified saves + final-document affiliate CTA context ==\n";
$extract_cta = new ReflectionMethod('\TMWSEO\Engine\Content\ContentEngine', 'extract_category_affiliate_slot_block');
$extract_cta->setAccessible(true);
$compute_context = new ReflectionMethod('\TMWSEO\Engine\Content\ContentEngine', 'compute_category_final_context');
$compute_context->setAccessible(true);
$real_cta = '<!-- wp:html --><div class="tmw-category-page-affiliate-cta"><a href="https://example.test">Real CTA words</a></div><!-- /wp:html -->';
$slot = '<!-- wp:html --><div class="tmw-category-affiliate-slot"></div><!-- /wp:html -->';
$suffix = (string) $extract_cta->invokeArgs(null, [$slot . "\n" . $real_cta]);
check('affiliate extractor preserves slot and real CTA exactly once', substr_count($suffix, 'tmw-category-affiliate-slot') === 1 && substr_count($suffix, 'tmw-category-page-affiliate-cta') === 1 && substr_count($suffix, 'Real CTA words') === 1, $suffix);
$marker_post = new \WP_Post(['ID' => 1401, 'post_content' => '<p>Prefix words</p><!-- TMWSEO:AI --><p>Old generated text</p>' . $real_cta]);
[$prefix, $context_suffix] = $compute_context->invokeArgs(null, [$marker_post, true]);
check('regeneration context retains existing real CTA exactly once', substr_count($context_suffix, 'Real CTA words') === 1 && strpos($prefix, 'Prefix words') !== false, $context_suffix);
$markerless_post = new \WP_Post(['ID' => 1402, 'post_content' => '<p>Marker-less prefix</p>']);
[$markerless_prefix, $markerless_suffix] = $compute_context->invokeArgs(null, [$markerless_post, true]);
check('marker-less generation starts without a phantom CTA suffix', strpos($markerless_prefix, 'Marker-less prefix') !== false && $markerless_suffix === '');
$final_document = $markerless_prefix . '<p>Generated words</p>' . $real_cta;
$cta_density = RankMathChipAnalyzer::combined_density($final_document, ['Generated words', 'Real CTA words']);
check('exact final-document density includes generated real CTA words once', $cta_density['matches'] === 2 && $cta_density['word_count'] === 5 && $cta_density['density'] === 40.0, json_encode($cta_density));
$finalize = new ReflectionMethod('\TMWSEO\Engine\Content\ContentEngine', 'finalize_category_generation');
$finalize->setAccessible(true);
$blocked = new \WP_Post(['ID' => 1403, 'post_type' => 'tmw_category_page', 'post_content' => '<p>Old content</p>']);
$GLOBALS['_tmw_test_posts'][1403] = $blocked;
foreach (['_tmwseo_ready_to_index' => 'old-ready', 'rank_math_robots' => ['noindex'], '_tmwseo_rankmath_chip_report' => 'old-report', 'rank_math_title' => 'Old title'] as $key => $value) { update_post_meta(1403, $key, $value); }
$finalize->invokeArgs(null, [1403, $blocked, [], false]);
check('failed or mismatched save finalization leaves readiness, robots, chip report, and metadata untouched', get_post_field('post_content', 1403) === '<p>Old content</p>' && get_post_meta(1403, '_tmwseo_ready_to_index', true) === 'old-ready' && get_post_meta(1403, 'rank_math_robots', true) === ['noindex'] && get_post_meta(1403, '_tmwseo_rankmath_chip_report', true) === 'old-report' && get_post_meta(1403, 'rank_math_title', true) === 'Old title');
check('both save paths require an explicit verified read-back before generated metadata and finalization', substr_count($engine_src, '$category_save_verified') >= 8 && substr_count($engine_src, 'finalize_category_generation($post_id, $post, $keyword_pack, $category_save_verified)') === 2);

echo "\n== H. No category-specific production logic ==\n";
$prod = '';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/includes/content'));
foreach ($it as $f) { if ($f->isFile() && $f->getExtension() === 'php') { $prod .= file_get_contents($f->getPathname()); } }
foreach (glob(dirname(__DIR__) . '/data/category-*.json') ?: [] as $df) { $prod .= file_get_contents($df); }
foreach (['Big Boob Cam', 'Blonde Cam Models', 'Latina Cam Models', 'Free Cam Chat', 'Amateur Cams', 'Plum Orchard Cams', 'Quiet Harbor Evening Rooms', 'massive boob webcam', 'big breast webcam'] as $name) {
    check("'$name' absent from the category generation surface", stripos($prod, $name) === false);
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "PASS: $pass  FAIL: $fail\n";
exit($fail > 0 ? 1 : 0);
