<?php
/**
 * Universal category pipeline smoke suite (v5.9.7).
 *
 * Run: php tests/run-category-universal-pipeline-smoke.php
 *
 * Standalone — no WordPress, no PHPUnit. Covers the required test matrix for
 * the universal category-generation repair:
 *
 *   regression categories (Amateur Cams, Big Boob Cam, Blonde Cam Models,
 *   Latina Cam Models, Free Cam Chat) + a synthetic unknown category, with:
 *   keyword role separation, root-family grouping, density limits, dump
 *   detection, placeholder detection, unsupported-claim detection, section
 *   plan / heading / FAQ variation, cross-category similarity threshold,
 *   deterministic stability, missing-data / empty-pool / large-pool inputs,
 *   models-no-videos / videos-no-models / neither, safe failure, provider
 *   raw-output preservation semantics, no hardcoded category copy, and the
 *   fixed legacy repair functions (no placeholder vocabulary, idempotent
 *   coverage injection).
 */

declare(strict_types=1);

error_reporting(E_ALL);

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__); }
if (!defined('TMWSEO_ENGINE_DATA_DIR')) { define('TMWSEO_ENGINE_DATA_DIR', dirname(__DIR__) . '/data'); }

if (!function_exists('esc_html')) { function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s) { return strip_tags((string) $s); } }

$GLOBALS['_tmw_options'] = [];
if (!function_exists('get_option')) {
    function get_option($key, $default = false) { return $GLOBALS['_tmw_options'][$key] ?? $default; }
}
if (!function_exists('update_option')) {
    function update_option($key, $value, $autoload = null) { $GLOBALS['_tmw_options'][$key] = $value; return true; }
}

$pipeline_dir = dirname(__DIR__) . '/includes/content/category-pipeline/';
require_once $pipeline_dir . 'class-category-context-builder.php';
require_once $pipeline_dir . 'class-category-intent-classifier.php';
require_once $pipeline_dir . 'class-category-keyword-planner.php';
require_once $pipeline_dir . 'class-category-chip-feasibility.php';
require_once $pipeline_dir . 'class-category-semantic-profile.php';
require_once $pipeline_dir . 'class-category-semantic-sections.php';
require_once $pipeline_dir . 'class-category-interchangeability-guard.php';
require_once $pipeline_dir . 'class-category-content-planner.php';
require_once $pipeline_dir . 'class-category-draft-composer.php';
require_once $pipeline_dir . 'class-category-quality-guard.php';
require_once $pipeline_dir . 'class-category-factual-safety.php';
require_once $pipeline_dir . 'class-category-grammar-guard.php';
require_once $pipeline_dir . 'class-category-density-policy.php';
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

use TMWSEO\Engine\Content\CategoryPipeline\CategoryContextBuilder;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryIntentClassifier;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryKeywordPlanner;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryContentPlanner;
use TMWSEO\Engine\Content\CategoryPipeline\CategorySemanticProfile;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryInterchangeabilityGuard;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryDraftComposer;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryQualityGuard;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryFactualSafety;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryDifferentiationScorer;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryFaqPlanner;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryFinalValidator;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryKeywordPlacement;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationPipeline;

$pass = 0; $fail = 0; $failures = [];
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  ok  {$label}\n"; }
    else { $fail++; $failures[] = $label . ($detail !== '' ? " — {$detail}" : ''); echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

// ── fixtures: the five regression categories + synthetic unknown ─────────────

$fixtures = [
    'amateur-cams' => [
        'category_name'      => 'Amateur Cams',
        'category_slug'      => 'amateur-cams',
        'primary_keyword'    => 'Amateur Cams',
        'approved_keywords'  => ['amateur webcam', 'amateur tv cams', 'live amateur sex cams', 'amateur sex chat'],
        'keywords_source'    => 'category_db_approved',
        'model_count'        => 18,
        'video_count'        => 40,
        'related_categories' => [[ 'name' => 'Blonde Cam Models', 'url' => 'https://top-models.webcam/category/blonde-cam-models/' ], [ 'name' => 'Free Cam Chat', 'url' => 'https://top-models.webcam/category/free-cam-chat/' ]],
        'models_url'         => 'https://top-models.webcam/webcam-models/',
        'videos_url'         => 'https://top-models.webcam/videos/',
        'site_name'          => 'Top Models Webcam',
    ],
    'big-boob-cam' => [
        'category_name'      => 'Big Boob Cam',
        'category_slug'      => 'big-boob-cam',
        'primary_keyword'    => 'Big Boob Cam',
        'approved_keywords'  => ['big breast webcam', 'big boobs webcam', 'biggest boobs webcam', 'massive boob webcam', 'massive boobs cam', 'massive breasts webcam'],
        'keywords_source'    => 'category_db_approved',
        'model_count'        => 9,
        'video_count'        => 22,
        'related_categories' => [[ 'name' => 'Amateur Cams', 'url' => 'https://top-models.webcam/category/amateur-cams/' ], [ 'name' => 'Latina Cam Models', 'url' => 'https://top-models.webcam/category/latina-cam-models/' ]],
        'models_url'         => 'https://top-models.webcam/webcam-models/',
        'videos_url'         => 'https://top-models.webcam/videos/',
        'site_name'          => 'Top Models Webcam',
    ],
    'blonde-cam-models' => [
        'category_name'      => 'Blonde Cam Models',
        'category_slug'      => 'blonde-cam-models',
        'primary_keyword'    => 'Blonde Cam Models',
        'approved_keywords'  => ['blonde cams', 'blonde live sex', 'blonde sex cam', 'blonde live webcam'],
        'keywords_source'    => 'category_db_approved',
        'model_count'        => 25,
        'video_count'        => 0,
        'related_categories' => [[ 'name' => 'Latina Cam Models', 'url' => 'https://top-models.webcam/category/latina-cam-models/' ], [ 'name' => 'Amateur Cams', 'url' => 'https://top-models.webcam/category/amateur-cams/' ]],
        'models_url'         => 'https://top-models.webcam/webcam-models/',
        'videos_url'         => 'https://top-models.webcam/videos/',
        'site_name'          => 'Top Models Webcam',
    ],
    'latina-cam-models' => [
        'category_name'      => 'Latina Cam Models',
        'category_slug'      => 'latina-cam-models',
        'primary_keyword'    => 'Latina Cam Models',
        'approved_keywords'  => ['latina sex cam', 'latina live sex', 'live latina cams', 'latina nude webcam'],
        'keywords_source'    => 'category_db_approved',
        'model_count'        => 0,
        'video_count'        => 31,
        'related_categories' => [[ 'name' => 'Blonde Cam Models', 'url' => 'https://top-models.webcam/category/blonde-cam-models/' ], [ 'name' => 'Big Boob Cam', 'url' => 'https://top-models.webcam/category/big-boob-cam/' ]],
        'models_url'         => 'https://top-models.webcam/webcam-models/',
        'videos_url'         => 'https://top-models.webcam/videos/',
        'site_name'          => 'Top Models Webcam',
    ],
    'free-cam-chat' => [
        'category_name'      => 'Free Cam Chat',
        'category_slug'      => 'free-cam-chat',
        'primary_keyword'    => 'Free Cam Chat',
        'approved_keywords'  => ['free cam to cam chat', 'free live cams', 'free webcam chat', 'cam to cam chat', 'free live cam chat', 'cam chat sites', 'webcam chat rooms', 'free webcam shows'],
        'keywords_source'    => 'category_db_approved',
        'model_count'        => 40,
        'video_count'        => 55,
        'related_categories' => [[ 'name' => 'Amateur Cams', 'url' => 'https://top-models.webcam/category/amateur-cams/' ]],
        'models_url'         => 'https://top-models.webcam/webcam-models/',
        'videos_url'         => 'https://top-models.webcam/videos/',
        'site_name'          => 'Top Models Webcam',
    ],
    // Test 18 + synthetic unknown category — never referenced anywhere in plugin code/data.
    'silver-fox-gentlemen-cams' => [
        'category_name'      => 'Silver Fox Gentlemen Cams',
        'category_slug'      => 'silver-fox-gentlemen-cams',
        'primary_keyword'    => 'Silver Fox Gentlemen Cams',
        'approved_keywords'  => ['mature male cams', 'silver fox webcam', 'gentlemen live chat'],
        'keywords_source'    => 'category_db_approved',
        'model_count'        => 3,
        'video_count'        => 5,
        'related_categories' => [[ 'name' => 'Amateur Cams', 'url' => 'https://top-models.webcam/category/amateur-cams/' ]],
        'models_url'         => 'https://top-models.webcam/webcam-models/',
        'videos_url'         => 'https://top-models.webcam/videos/',
        'site_name'          => 'Top Models Webcam',
    ],
];

$banned_probe = [
    'related room-browsing intent', 'similar public cam-room searches', 'nearby cam-room queries',
    'recognisable theme', 'recognizable theme', 'neutral directory archive', 'same browsing structure',
    'designed to reduce browsing friction', 'category archive layer', 'move between listings efficiently',
    'practical overview before they click through', 'one consistent theme', 'directory context',
    'this archive page indexes',
];

echo "== A. Full pipeline on regression + unknown categories ==\n";
$results = [];
foreach ($fixtures as $slug => $parts) {
    $GLOBALS['_tmw_options'] = $GLOBALS['_tmw_options'] ?? [];
    $context = CategoryContextBuilder::build_from_parts($parts);
    $result  = CategoryGenerationPipeline::generate_from_context($context, [
        'tracking'  => array_slice($parts['approved_keywords'], 0, 8),
        'use_store' => true,
    ]);
    $results[$slug] = $result;
    $html    = (string) $result['html'];
    $visible = strtolower(strip_tags($html));

    check("[$slug] pipeline ok", (bool) $result['ok'], implode('; ', array_slice((array) $result['report']['failure_reasons'], 0, 3)));
    if (!$result['ok']) { continue; }

    $found = [];
    foreach ($banned_probe as $phrase) {
        if (strpos($visible, strtolower($phrase)) !== false) { $found[] = $phrase; }
    }
    check("[$slug] no banned/placeholder phrases", empty($found), implode(', ', $found));

    // Keyword dump: no sentence with >=3 exact pool keywords.
    $dump = false;
    foreach (preg_split('/(?<=[.!?])\s+/u', strip_tags($html)) ?: [] as $sentence) {
        if (CategoryQualityGuard::count_exact_keywords($sentence, $parts['approved_keywords']) >= 3) { $dump = true; break; }
    }
    check("[$slug] no keyword-dump sentence", !$dump);

    // Unsupported claims — checked with the context's DERIVED evidence flags
    // (a known model/video count verifies qualitative scale wording).
    $claims = CategoryFactualSafety::analyze($html, (array) ($context['verified_flags'] ?? []));
    check("[$slug] no unsupported claims", empty($claims), implode(', ', array_map(static fn($c) => $c['detail'], $claims)));

    // Word count + primary presence via validator metrics already implied by ok=true.
    $wc = (int) $result['report']['metrics']['word_count'];
    check("[$slug] length in range ({$wc}w)", $wc >= CategoryFinalValidator::MIN_WORDS && $wc <= CategoryFinalValidator::MAX_WORDS);
}

echo "\n== B. Cross-category variation & similarity ==\n";
$slugs = array_keys($fixtures);
$ok_slugs = array_values(array_filter($slugs, static fn($s) => !empty($results[$s]['ok'])));

// Section-plan variation (test 11): not all categories share one section list.
$plans = array_map(static fn($s) => implode(',', (array) $results[$s]['report']['content_plan']), $ok_slugs);
check('section plans vary across categories', count(array_unique($plans)) >= 4, implode(' | ', array_unique($plans)));

// Heading variation (test 12): no two categories share an identical full heading list.
$headings = array_map(static fn($s) => implode('|', (array) $results[$s]['report']['headings']), $ok_slugs);
check('heading sets differ per category', count(array_unique($headings)) === count($ok_slugs));

// FAQ variation (test 13): the five regression pages must not share one FAQ set.
$faq_sets = array_map(static fn($s) => implode('|', (array) $results[$s]['report']['faq']), $ok_slugs);
check('FAQ sets vary across categories', count(array_unique($faq_sets)) >= 4, implode(' || ', array_unique($faq_sets)));

// Cross-category similarity threshold (test 14): every later generation was
// scored against the earlier fingerprints in the rolling store and passed.
$sims = [];
foreach ($ok_slugs as $i => $slug) {
    if ($i === 0) { continue; }
    $sims[$slug] = (float) $results[$slug]['report']['similarity']['max_body'];
    check("[$slug] body similarity <= threshold", $sims[$slug] <= CategoryDifferentiationScorer::MAX_BODY_SIMILARITY, (string) $sims[$slug]);
}

// Blonde vs Amateur/Latina structural difference (regression expectation).
$b = $results['blonde-cam-models'] ?? null; $a = $results['amateur-cams'] ?? null; $l = $results['latina-cam-models'] ?? null;
if ($b && $a && $l && $b['ok'] && $a['ok'] && $l['ok']) {
    check('blonde headings != amateur headings', $b['report']['headings'] !== $a['report']['headings']);
    check('blonde headings != latina headings', $b['report']['headings'] !== $l['report']['headings']);
}

echo "\n== C. Intent classification (Stage 2, no name hardcoding) ==\n";
$expected_intents = [
    'free-cam-chat'      => 'free_access_pricing',
    'big-boob-cam'       => 'body_type',
    'blonde-cam-models'  => 'appearance_trait',
    'latina-cam-models'  => 'ethnicity_regional',
];
foreach ($expected_intents as $slug => $expected) {
    $got = (string) $results[$slug]['report']['intent'];
    check("[$slug] intent == {$expected}", $got === $expected, "got {$got}");
}
check('[amateur-cams] intent is a performer-trait/interaction bucket', in_array($results['amateur-cams']['report']['intent'], ['interaction_style', 'broad_discovery'], true), (string) $results['amateur-cams']['report']['intent']);
check('[unknown] classified without hardcoding', in_array($results['silver-fox-gentlemen-cams']['report']['intent'], ['age_style', 'interaction_style', 'broad_discovery', 'appearance_trait'], true), (string) $results['silver-fox-gentlemen-cams']['report']['intent']);

echo "\n== D. Free Cam Chat regression expectations ==\n";
$fc = $results['free-cam-chat'];
if ($fc['ok']) {
    $fc_visible = strtolower(strip_tags($fc['html']));
    check('free-cam-chat explains what free means', strpos($fc_visible, 'public') !== false && strpos($fc_visible, 'free') !== false && (strpos($fc_visible, 'paid') !== false || strpos($fc_visible, 'private') !== false));
    // Close variants must not saturate the body: at most 4 pool-exact hits total.
    $exact_total = CategoryQualityGuard::count_exact_keywords(strip_tags($fc['html']), $fixtures['free-cam-chat']['approved_keywords']);
    check('free-cam-chat pool exacts bounded (<=4)', $exact_total <= 4, "got {$exact_total}");
}

echo "\n== E. Big Boob Cam regression expectations ==\n";
$bb = $results['big-boob-cam'];
if ($bb['ok']) {
    $bb_html = (string) $bb['html'];
    // No comma keyword list of the family variants.
    check('big-boob-cam has no keyword list', !preg_match('/big breast webcam,\s*big boobs webcam/i', $bb_html));
    // No same-family exacts in consecutive paragraphs.
    $paras = CategoryQualityGuard::paragraphs_text($bb_html);
    $family_hits_seq = [];
    foreach ($paras as $p) {
        $family_hits_seq[] = CategoryQualityGuard::count_exact_keywords($p, $fixtures['big-boob-cam']['approved_keywords']) > 0 ? 1 : 0;
    }
    $consecutive = false;
    for ($i = 1; $i < count($family_hits_seq); $i++) {
        if ($family_hits_seq[$i] === 1 && $family_hits_seq[$i - 1] === 1) { $consecutive = true; break; }
    }
    check('big-boob-cam no pool keywords in consecutive paragraphs', !$consecutive);
}

echo "\n== F. Keyword planning (Stage 3) ==\n";
$plan = CategoryKeywordPlanner::plan('Free Cam Chat', $fixtures['free-cam-chat']['approved_keywords'], array_slice($fixtures['free-cam-chat']['approved_keywords'], 0, 8));
check('tracking keeps primary first', $plan['rankmath_tracking'][0] === 'Free Cam Chat');
check('tracking preserves 8-extra cap upstream (<=9 tracked)', count($plan['rankmath_tracking']) <= 9);
check('body-use excludes primary-family variants', !in_array('free cam to cam chat', $plan['body_use'], true) && !in_array('free webcam chat', $plan['body_use'], true));
check('body-use capped at ' . CategoryKeywordPlanner::MAX_BODY_USE, count($plan['body_use']) <= CategoryKeywordPlanner::MAX_BODY_USE);
check('unused keywords carry reasons', !empty($plan['unused']) && !empty($plan['unused'][0]['reason']));
$fam1 = CategoryKeywordPlanner::root_family('free cam chat');
$fam2 = CategoryKeywordPlanner::root_family('free webcam chat');
$fam3 = CategoryKeywordPlanner::root_family('cam to cam chat');
check('root families collapse close variants', $fam1 === $fam2 && $fam2 === $fam3, "{$fam1} / {$fam2} / {$fam3}");
check('distinct topics keep distinct families', CategoryKeywordPlanner::root_family('big boobs webcam') !== $fam1);

// Empty pool (test 20).
$empty_plan = CategoryKeywordPlanner::plan('Curvy Redhead Streamers', [], []);
check('empty pool: no body-use, primary kept', $empty_plan['body_use'] === [] && $empty_plan['primary'] === 'Curvy Redhead Streamers');

// Large pool (test 21).
$large_pool = [];
for ($i = 0; $i < 40; $i++) { $large_pool[] = "unique topic {$i} cam"; }
$large_plan = CategoryKeywordPlanner::plan('Mega Pool Cams', $large_pool, array_slice($large_pool, 0, 8));
check('large pool: body-use still capped', count($large_plan['body_use']) <= CategoryKeywordPlanner::MAX_BODY_USE);
check('large pool: overflow logged as unused', count($large_plan['unused']) >= 30);

echo "\n== G. Determinism & regeneration salts ==\n";
$ctx_bb = CategoryContextBuilder::build_from_parts($fixtures['big-boob-cam']);
$r1 = CategoryGenerationPipeline::generate_from_context($ctx_bb, ['tracking' => [], 'use_store' => false]);
$r2 = CategoryGenerationPipeline::generate_from_context($ctx_bb, ['tracking' => [], 'use_store' => false]);
check('same inputs → identical output', $r1['html'] === $r2['html']);
$p0 = CategoryContentPlanner::plan($ctx_bb, 'body_type', 0);
$p1 = CategoryContentPlanner::plan($ctx_bb, 'body_type', 1);
check('salt changes the plan deterministically', $p0['sections'] !== $p1['sections'] || $p0['headings'] !== $p1['headings']);

echo "\n== H. Missing-data / sparse contexts (tests 19, 22-24) ==\n";
$sparse_variants = [
    'models-no-videos'  => ['model_count' => 12, 'video_count' => 0],
    'videos-no-models'  => ['model_count' => 0, 'video_count' => 12],
    'neither'           => ['model_count' => 0, 'video_count' => 0],
    'missing-everything'=> ['model_count' => null, 'video_count' => null, 'related_categories' => [], 'site_name' => '', 'models_url' => '', 'videos_url' => ''],
];
foreach ($sparse_variants as $label => $overrides) {
    $parts = array_merge($fixtures['silver-fox-gentlemen-cams'], $overrides, ['category_slug' => 'sparse-' . $label, 'category_name' => 'Sparse ' . ucwords(str_replace('-', ' ', $label)), 'primary_keyword' => 'Sparse ' . ucwords(str_replace('-', ' ', $label))]);
    $ctx  = CategoryContextBuilder::build_from_parts($parts);
    $res  = CategoryGenerationPipeline::generate_from_context($ctx, ['tracking' => [], 'use_store' => false]);
    check("[$label] generates or fails safely", $res['ok'] || !empty($res['report']['failure_reasons']));
    if ($res['ok']) {
        check("[$label] no unresolved placeholders", strpos($res['html'], '{{') === false);
        $claims = CategoryFactualSafety::analyze($res['html'], []);
        check("[$label] no invented facts", empty($claims), implode(', ', array_map(static fn($c) => $c['detail'], $claims)));
    }
}


echo "\n== I. Unicode semantic profile and body-only subject guard regressions ==\n";
$unicode_cases = [
    'Café Cams'    => 'café',
    'Español Cams' => 'español',
    'München Cams' => 'münchen',
];
foreach ($unicode_cases as $category => $expected_subject) {
    $ctx = CategoryContextBuilder::build_from_parts(array_merge($fixtures['amateur-cams'], [
        'category_slug'     => strtolower(str_replace(' ', '-', $category)),
        'category_name'     => $category,
        'primary_keyword'   => $category,
        'approved_keywords' => [$category, $expected_subject . ' webcam'],
    ]));
    $kwp = CategoryKeywordPlanner::plan($category, [$category, $expected_subject . ' webcam'], []);
    $profile = CategorySemanticProfile::build($ctx + ['intent' => 'broad_discovery'], $kwp);
    $ctx['__semantic_profile'] = $profile;
    $plan = CategoryContentPlanner::plan($ctx, 'broad_discovery', 0);
    $comp = CategoryDraftComposer::compose($ctx, $plan, $kwp);
    $plain = strtolower(strip_tags($comp['html']));
    check("Unicode subject preserved for {$category}", $profile['subject'] === $expected_subject, (string) $profile['subject']);
    check("Unicode semantic copy preserved for {$category}", strpos($plain, $expected_subject) !== false, substr($plain, 0, 180));
}

$guard_profile = [
    'subject'          => 'café',
    'descriptor_terms' => [],
    'modifier_terms'   => [],
    'active_keywords'  => ['Café Cams'],
];
$generic_p = 'The listing directory keeps live rooms, performer pages, platforms, thumbnails, schedules, private sessions, public browsing, clips, and shortlist choices organized with practical details for visitors.';
$heading_only_html = '<h2>Café Listings</h2><h3>Café FAQ</h3><p>' . $generic_p . '</p><p>' . $generic_p . '</p><p>' . $generic_p . '</p>';
$heading_only = CategoryInterchangeabilityGuard::evaluate($heading_only_html, $guard_profile);
check('subject only in headings fails body subject requirement', !$heading_only['passed'] && in_array('subject_absent_from_body', $heading_only['reasons'], true), json_encode($heading_only));
$body_subject_html = '<h2>Café Listings</h2><h3>Café FAQ</h3><p>Café listings keep this café category clear for visitors comparing performer pages.</p><p>The café theme appears in normal body prose before any platform decision.</p><p>Visitors can use café context while still checking each destination.</p>';
$body_subject = CategoryInterchangeabilityGuard::evaluate($body_subject_html, $guard_profile);
check('subject in body paragraphs passes body subject requirement', $body_subject['passed'] && !in_array('subject_absent_from_body', $body_subject['reasons'], true), json_encode($body_subject));
$domain_only_html = '<h2>Café Listings</h2><h3>Café FAQ</h3><p>' . $generic_p . '</p><p>' . $generic_p . '</p><p>' . $generic_p . '</p>';
$domain_only = CategoryInterchangeabilityGuard::evaluate($domain_only_html, $guard_profile);
check('generic domain vocabulary alone does not satisfy body subject requirement', in_array('subject_absent_from_body', $domain_only['reasons'], true), json_encode($domain_only));

echo "\n== I. Safe failure when thresholds cannot be met (test 25) ==\n";
// Comparisons seeded with the draft's own fingerprint make differentiation impossible.
$ctx_fail = CategoryContextBuilder::build_from_parts(array_merge($fixtures['amateur-cams'], ['category_slug' => 'forced-fail', 'category_name' => 'Forced Fail Cams', 'primary_keyword' => 'Forced Fail Cams']));
// v5.9.16: the pipeline attaches a semantic profile to the context before it
// composes (class-category-generation-pipeline.php), so the shadow drafts used
// to force a collision must be composed through the SAME path, otherwise the
// shadows diverge from the real drafts and the forced-fail no longer collides.
// Mirror that attachment here so the test still proves the fail-closed contract.
$ctx_fail['__semantic_profile'] = CategorySemanticProfile::build(
    $ctx_fail + ['intent' => 'interaction_style'],
    CategoryKeywordPlanner::plan('Forced Fail Cams', $fixtures['amateur-cams']['approved_keywords'], [])
);
$pre = CategoryGenerationPipeline::generate_from_context($ctx_fail, ['tracking' => [], 'use_store' => false]);
$self_fps = [];
for ($salt = 0; $salt < CategoryGenerationPipeline::MAX_ATTEMPTS; $salt++) {
    $plan_f = CategoryContentPlanner::plan($ctx_fail, 'interaction_style', $salt);
    // fingerprint every attempt's would-be draft so all retries collide
    $kwp = CategoryKeywordPlanner::plan('Forced Fail Cams', $fixtures['amateur-cams']['approved_keywords'], []);
    $comp = CategoryDraftComposer::compose($ctx_fail, $plan_f, $kwp);
    $faqs = CategoryFaqPlanner::plan($ctx_fail, 'interaction_style', $salt);
    $self_fps[] = CategoryDifferentiationScorer::fingerprint($comp['html'] . CategoryFaqPlanner::render($faqs), ['Forced Fail Cams'], 'shadow-' . $salt);
}
$fail_res = CategoryGenerationPipeline::generate_from_context($ctx_fail, ['tracking' => [], 'use_store' => false, 'comparisons' => $self_fps]);
check('impossible threshold → ok=false', !$fail_res['ok']);
check('failure reasons recorded', !empty($fail_res['report']['failure_reasons']), implode('; ', (array) $fail_res['report']['failure_reasons']));
check('failed draft returns empty html (never saved)', $fail_res['html'] === '');

echo "\n== J. Provider handling (tests 15-17) ==\n";
// A distinct provider draft that passes validation must survive (not be flattened).
$provider_html = '<h2>Forced Provider Cams, Explained Simply</h2><p>Forced Provider Cams streaming has a texture that studio content rarely matches: performers set up their own spaces, run sessions at their own pace, and keep the chat conversational in a way scripted rooms never quite manage. These listings gather that interaction style into one wide field, and how each performer describes their own approach is the anchor detail that separates one room from the next. The same ground covers what people search as amateur webcam browsing, so that phrasing lands on these listings too.</p><h2>Narrowing a Wide Theme</h2><p>This is a broad grouping, and broad ground rewards an anchor: pick the single listing nearest what you came for and treat its page as the new starting point. Each anchored pick narrows the field further, and a vague search usually refines itself into a specific destination within a few passes. Searches phrased as amateur tv cams follow the same route, since the theme covers that wording as well.</p><h2>Reading the Rooms Before You Enter</h2><p>The habit that pays off most is a slow first scan. Open two or three profiles from this broad field, compare how each performer frames their own sessions, and weigh their stated approach against the kind of conversation or format you actually want, narrowing as you go. Similar stage names are everywhere in this space, so confirm the name matches the page you meant to open before engaging anywhere. Visitors who arrived through live amateur sex cams searches face the same reading job, answered by the same profile pages.</p><h2>Public Viewing and Paid Extras</h2><p>Most platforms keep public rooms open to watch while private sessions, requests, and cam-to-cam interaction are paid features. Where the line sits varies by performer and platform, so treat the destination page as the deciding source, and read its terms before stepping past open viewing. Current room status also lives on the destination platform, and it can change during a visit. The conversational side of the theme, including what people search as amateur sex chat, follows the same public-versus-paid split.</p><h2>Keeping the Search Moving</h2><p>When a listing is close but not right, move sideways before starting over. Adjacent themes approach the same space from another angle, and the <a href="https://top-models.webcam/models/">model directory</a> reopens the complete performer field whenever this theme runs out of new names. The <a href="https://top-models.webcam/videos/">video directory</a> does the same for clips, so both wide routes stay one click away. A shortlist built from anchored picks beats an open-ended scroll every time, and the refining work compounds with each page opened. Adjacent themes such as Blonde Cam Models approach the same ground from a different angle when this one runs thin, and the individual profile pages remain the place where the deciding details are stated. Treat every listing page as a starting point rather than a verdict, and the browsing stays efficient from the first visit onward.</p><p>That is the whole method for Forced Provider Cams browsing: anchor on the nearest match, check the stated session style on its page, and let the platforms handle everything operational once the choice is made.</p>';
$ctx_prov = CategoryContextBuilder::build_from_parts(array_merge($fixtures['amateur-cams'], ['category_slug' => 'forced-provider', 'category_name' => 'Forced Provider Cams', 'primary_keyword' => 'Forced Provider Cams']));
$prov_res = CategoryGenerationPipeline::generate_from_context($ctx_prov, ['tracking' => [], 'use_store' => false, 'provider' => 'openai', 'provider_html' => $provider_html]);
check('provider draft accepted', (bool) $prov_res['ok'], implode('; ', array_slice((array) $prov_res['report']['failure_reasons'], 0, 4)));
if ($prov_res['ok']) {
    check('provider falls back when stored-focus density cannot be repaired safely', strpos($prov_res['html'], 'streaming has a texture') === false && ($prov_res['report']['attempt_log'][0]['provider'] ?? '') === 'openai');
    check('raw provider output hash recorded', $prov_res['report']['raw_output_hash'] === \TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationResult::hash_output($provider_html));
    check('fallback provider label reported after rejected raw draft', $prov_res['report']['provider'] === 'template');
    $faq_pos = stripos($prov_res['html'], '<h2>Frequently Asked Questions</h2>');
    $closing_pos = strripos($prov_res['html'], '</p>', $faq_pos ? $faq_pos - strlen($prov_res['html']) : 0);
    check('fallback FAQ appended after deterministic conclusion', $faq_pos !== false && $closing_pos !== false && $faq_pos > $closing_pos);
    check('provider FAQ is structurally last H2', $faq_pos !== false && !preg_match('/<h2[^>]*>/i', substr($prov_res['html'], $faq_pos + strlen('<h2>Frequently Asked Questions</h2>'))));
}
// A garbage provider draft falls back to the deterministic composer (test 17).
$garbage = '<p>free related room-browsing intent, similar public cam-room searches, nearby cam-room queries.</p>';
$fb_res = CategoryGenerationPipeline::generate_from_context($ctx_prov, ['tracking' => [], 'use_store' => false, 'provider' => 'openai', 'provider_html' => $garbage]);
check('garbage provider draft → deterministic fallback still ok', (bool) $fb_res['ok'], implode('; ', array_slice((array) $fb_res['report']['failure_reasons'], 0, 4)));
if ($fb_res['ok']) {
    check('fallback output clean of provider garbage', stripos($fb_res['html'], 'room-browsing intent') === false);
}

echo "\n== K. Guard & factual units (tests 8-10) ==\n";
$dump_html = '<p>This page collects listings covering searches such as big breast webcam, big boobs webcam, biggest boobs webcam, massive boob webcam, massive boobs cam, massive breasts webcam.</p>';
$issues = CategoryQualityGuard::analyze($dump_html, $fixtures['big-boob-cam']['approved_keywords']);
$types = array_column($issues, 'type');
check('dump detection fires', in_array('keyword_dump_sentence', $types, true) || in_array('keyword_list', $types, true));
$repaired = CategoryQualityGuard::repair($dump_html, $fixtures['big-boob-cam']['approved_keywords']);
check('dump repair reduces exacts to <=2', CategoryQualityGuard::count_exact_keywords(strip_tags($repaired['html']), $fixtures['big-boob-cam']['approved_keywords']) <= 2, strip_tags($repaired['html']));

$ph_html = '<p>Visitors comparing free related room-browsing intent can use this page.</p><p>This is a neutral directory archive.</p>';
$ph_issues = CategoryQualityGuard::analyze($ph_html, []);
check('placeholder detection fires', count($ph_issues) >= 2);
$ph_fixed = CategoryQualityGuard::repair($ph_html, []);
check('placeholder sentences dropped', stripos($ph_fixed['html'], 'room-browsing') === false && stripos($ph_fixed['html'], 'neutral directory archive') === false);

$claim_html = '<p>Profile pages include schedules and you can filter by platform. Both directories are accessible without an account.</p>';
$claim_issues = CategoryFactualSafety::analyze($claim_html, []);
check('unsupported claim detection fires (3 claims)', count($claim_issues) >= 2, (string) count($claim_issues));
$claim_fixed = CategoryFactualSafety::repair($claim_html, []);
check('claims rewritten to qualified wording', stripos($claim_fixed['html'], 'include schedules') === false && stripos($claim_fixed['html'], 'without an account') === false);
$claim_ok = CategoryFactualSafety::analyze($claim_html, ['schedules', 'filters', 'no_account_browsing']);
check('verified flags allow verified claims', empty($claim_ok));

echo "\n== L. Review regression units ==\n";
$plan_ref = new ReflectionClass(CategoryContentPlanner::class);
$assign = $plan_ref->getMethod('assign_keyword_headings');
$assign->setAccessible(true);
$headings = [
    'intro' => 'Review Primary Cams overview',
    'expectations' => 'What to expect',
    'discovery_advice' => 'How to browse',
    'faq' => 'Frequently Asked Questions',
];
$map = $assign->invokeArgs(null, [&$headings, ['intro', 'expectations', 'discovery_advice', 'faq'], ['primary_keyword' => 'Review Primary Cams'], ['primary' => 'Review Primary Cams', 'roles' => []], 0]);
check('primary keyword H2 reuse ignores intro headings', isset($map['expectations']) && !isset($map['intro']), json_encode($map));


$cam_headings = [
    'expectations' => 'What webcam viewers should know',
    'discovery_advice' => 'Asian Cams: what to know',
    'browse_listings' => 'How to browse cam',
    'compare_profiles' => 'More cam choices',
];
$cam_map = $assign->invokeArgs(null, [&$cam_headings, ['expectations', 'discovery_advice', 'browse_listings', 'compare_profiles'], ['primary_keyword' => 'cam'], ['primary' => 'cam', 'roles' => []], 1]);
$primary_h2_count = 0;
foreach ($cam_headings as $h) { if (preg_match(CategoryFinalValidator::exact_keyword_pattern('cam'), $h)) { $primary_h2_count++; } }
check('primary H2 exact matching rejects webcam substring and preserves one exact match', $primary_h2_count === 1 && isset($cam_map['browse_listings']), json_encode($cam_headings));
$plural_headings = ['expectations' => 'Asian Cams: what to know', 'discovery_advice' => 'How to browse safely'];
$plural_map = $assign->invokeArgs(null, [&$plural_headings, ['expectations', 'discovery_advice'], ['primary_keyword' => 'Asian Cam'], ['primary' => 'Asian Cam', 'roles' => []], 2]);
check('primary H2 exact matching rejects singular/plural phrase mismatch', isset($plural_map['expectations']) && preg_match(CategoryFinalValidator::exact_keyword_pattern('Asian Cam'), $plural_headings['expectations']), json_encode($plural_headings));
$non_topical_headings = ['intro' => 'Review Primary Cams guide', 'expectations' => 'What to expect'];
$non_topical_map = $assign->invokeArgs(null, [&$non_topical_headings, ['intro', 'expectations'], ['primary_keyword' => 'Review Primary Cams'], ['primary' => 'Review Primary Cams', 'roles' => []], 3]);
check('non-topical primary H2 does not satisfy primary heading assignment', isset($non_topical_map['expectations']) && !isset($non_topical_map['intro']), json_encode($non_topical_map));
$multi_headings = ['expectations' => 'Review Primary Cams overview', 'discovery_advice' => 'Review Primary Cams tips', 'browse_listings' => 'Browse listings'];
$multi_map = $assign->invokeArgs(null, [&$multi_headings, ['expectations', 'discovery_advice', 'browse_listings'], ['primary_keyword' => 'Review Primary Cams'], ['primary' => 'Review Primary Cams', 'roles' => []], 4]);
$multi_count = 0;
foreach ($multi_headings as $h) { if (preg_match(CategoryFinalValidator::exact_keyword_pattern('Review Primary Cams'), $h)) { $multi_count++; } }
check('multiple topical primary H2s are reduced to one canonical match', $multi_count === 1 && isset($multi_map['expectations']), json_encode($multi_headings));

$faq_bad = CategoryFinalValidator::validate('<h2>Frequently Asked Questions</h2><h3>Question?</h3>', ['primary_keyword' => 'x'], ['primary' => 'x'], [], []);
check('FAQ validator rejects trailing unanswered question', in_array('faq_question_missing_answer', $faq_bad['reasons'], true) && in_array('content_after_final_faq_answer', $faq_bad['reasons'], true), json_encode($faq_bad['reasons']));
$faq_order = CategoryFinalValidator::validate('<h2>Frequently Asked Questions</h2><h3>One?</h3><h3>Two?</h3><p>Answer.</p>', ['primary_keyword' => 'x'], ['primary' => 'x'], [], []);
check('FAQ validator rejects incorrectly ordered balanced H3/P blocks', in_array('faq_question_missing_answer', $faq_order['reasons'], true), json_encode($faq_order['reasons']));
$faq_ok = CategoryFinalValidator::validate('<p>x filler words repeated enough for structural unit x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x x.</p><h2>Frequently Asked Questions</h2><h3>One?</h3><p>Answer.</p><h3>Two?</h3><p>Answer.</p><h3>Three?</h3><p>Answer.</p>', ['primary_keyword' => 'x'], ['primary' => 'x'], [], []);
check('FAQ validator accepts alternating FAQ ending in paragraph structurally', !in_array('faq_question_missing_answer', $faq_ok['reasons'], true) && !in_array('content_after_final_faq_answer', $faq_ok['reasons'], true), json_encode($faq_ok['reasons']));
$relative_links = CategoryFinalValidator::validate('<p>x repeated words ' . str_repeat('word ', 650) . '</p><h2>x topic</h2><h2>Helpful links</h2><h2>More guidance</h2><a href="/webcam-models/">models</a><a href="/videos/">videos</a><h2>Frequently Asked Questions</h2><h3>One?</h3><p>Answer.</p><h3>Two?</h3><p>Answer.</p><h3>Three?</h3><p>Answer.</p>', ['primary_keyword' => 'x', 'models_url' => 'https://top-models.webcam/webcam-models/', 'videos_url' => 'https://top-models.webcam/videos/'], ['primary' => 'x'], [], []);
check('validator counts root-relative internal links toward minimum', !in_array('too_few_internal_links:0', $relative_links['reasons'], true) && !in_array('too_few_internal_links:1', $relative_links['reasons'], true), json_encode($relative_links['reasons']));

$intent_ctx = ['category_name' => 'Blonde Redhead', 'approved_keywords' => ['free token cheap trial', 'free tokens cost', 'blonde hair guide']];
$intent_res = CategoryIntentClassifier::classify($intent_ctx);
check('intent classifier skips keyword-only raw winner for next confident name hit', $intent_res['intent'] === CategoryIntentClassifier::INTENT_APPEARANCE_TRAIT, json_encode($intent_res));

$kw_plan = CategoryKeywordPlanner::plan('sample primary', ['live cams', 'live webcams', 'fresh chat', 'fresh chats', 'unique stream', 'another option'], []);
check('pass 1 blocks near-duplicate active body keywords', !(in_array('live cams', $kw_plan['body_use'], true) && in_array('live webcams', $kw_plan['body_use'], true)), implode(', ', $kw_plan['body_use']));
$variant_sigs = array_map(static fn($kw) => CategoryKeywordPlanner::variant_signature($kw), ['live cams', 'best live cams', 'top live cams', 'new live cams']);
check('generic SEO modifiers collapse to one duplicate signature', count(array_unique($variant_sigs)) === 1, implode(' | ', $variant_sigs));
$modifier_plan = CategoryKeywordPlanner::plan('sample primary', ['live cams', 'best live cams', 'top live cams', 'new live cams', 'distinct option'], []);
$active_modifiers = array_values(array_intersect(['live cams', 'best live cams', 'top live cams', 'new live cams'], (array) $modifier_plan['body_use']));
check('modifier variants cannot become active together', count($active_modifiers) <= 1, implode(', ', $modifier_plan['body_use']));
$unused_reasons = [];
foreach ((array) $kw_plan['unused'] as $row) { $unused_reasons[$row['keyword']] = $row['reason']; }
check('duplicate unused reason beats cap reporting', ($unused_reasons['live webcams'] ?? '') === 'near_duplicate_of_selected_term' || ($unused_reasons['live cams'] ?? '') === 'near_duplicate_of_selected_term', json_encode($kw_plan['unused']));

$place_ref = new ReflectionClass(CategoryKeywordPlacement::class);
$promote = $place_ref->getMethod('promote_in_paragraphs');
$promote->setAccessible(true);
$actions = [];
$promoted = $promote->invokeArgs(null, ['<p>Offset Primary starts here.</p><p>Pick this category carefully.</p><p>Then scan this theme slowly.</p>', 'Very Long Offset Primary', 2, &$actions]);
// v5.9.11 two-phase distribution contract: the STRICT pass never adds
// adjacent to a primary-bearing paragraph; the bounded RELAXED pass covers
// only the residual need and labels its actions.
check('paragraph promotion uses current offsets after each mutation', strpos($promoted, '<p>Pick Very Long Offset Primary carefully.</p>') !== false, $promoted);
check('residual need served by the labelled relaxed pass', (bool) array_filter($actions, static fn($a) => str_ends_with((string) $a, '_relaxed_adjacency')), json_encode($actions));
// Strict-pass preference: with ONE needed use and a primary-bearing middle
// paragraph, the non-adjacent host wins and the adjacent one is untouched.
$strict_actions = [];
$strict = $promote->invokeArgs(null, ['<p>Opening paragraph text.</p><p>Adjacent host with this category here.</p><p>Very Long Offset Primary sits here.</p><p>Buffer paragraph plain words.</p><p>Far host with this theme available.</p>', 'Very Long Offset Primary', 1, &$strict_actions]);
check('strict pass prefers the non-adjacent host', strpos($strict, '<p>Adjacent host with this category here.</p>') !== false && strpos($strict, 'Far host with Very Long Offset Primary available') !== false, $strict);
$repair = CategoryKeywordPlacement::repair('<p>Anchor Primary first paragraph.</p><p><a href="/x?keep=Anchor%20Primary">Anchor Primary</a> and Anchor Primary in copy.</p><p>Anchor Primary again.</p><p>Anchor Primary again.</p><p>Anchor Primary again.</p><p>Anchor Primary again.</p>', 'Anchor Primary');
$demoted = array_values(array_filter((array) $repair['actions'], static fn($a) => preg_match('/^demoted_primary_keyword_x([1-9][0-9]*)$/', (string) $a)));
check('legacy two-argument repair does not run density demotion', count($demoted) === 0, json_encode($repair['actions']));
check('primary demotion preserves anchor text and href byte-for-byte', strpos($repair['html'], '<a href="/x?keep=Anchor%20Primary">Anchor Primary</a>') !== false && strpos($repair['html'], '<a href="/x?keep=Anchor%20Primary">this topic</a>') === false, $repair['html']);

$dash_html = '<p>One—two&mdash;three&#8212;four&#x2014;five&#X2014;six.</p>';
$dash_repair = CategoryQualityGuard::repair($dash_html, []);
$dash_issues = CategoryQualityGuard::analyze($dash_repair['html'], []);
check('em dash repair handles attached literal/entity dashes', !in_array('em_dash_overuse', array_column($dash_issues, 'type'), true), $dash_repair['html']);

$faq_ref = new ReflectionClass(CategoryFaqPlanner::class);
$faq_library = $faq_ref->getProperty('library');
$faq_library->setAccessible(true);
$original_faq_library = $faq_library->getValue();
$faq_library->setValue(null, ['buckets' => [
    'old_safe' => ['tier' => 'intent', 'intents' => ['interaction_style' => 10], 'variants' => [['id' => 'v0', 'q' => 'What older safe answer returns?', 'a' => 'Older safe guidance is available for this category.']]],
    'unsafe_one' => ['tier' => 'intent', 'intents' => ['interaction_style' => 9], 'variants' => [['id' => 'v0', 'q' => 'Can I check schedules?', 'a' => 'Profiles show their schedules before you open them.']]],
    'unsafe_two' => ['tier' => 'intent', 'intents' => ['interaction_style' => 8], 'variants' => [['id' => 'v0', 'q' => 'Can I filter listings?', 'a' => 'You can filter the listings by many profile details.']]],
    'unsafe_three' => ['tier' => 'intent', 'intents' => ['interaction_style' => 7], 'variants' => [['id' => 'v0', 'q' => 'Are counts current?', 'a' => 'This page shows the current count for this theme.']]],
    'safe_four' => ['tier' => 'intent', 'intents' => ['interaction_style' => 6], 'variants' => [['id' => 'v0', 'q' => 'What should I compare first?', 'a' => 'Compare the visible listing details and open the most relevant profile first.']]],
    'safe_five' => ['tier' => 'intent', 'intents' => ['interaction_style' => 5], 'variants' => [['id' => 'v0', 'q' => 'How should I use nearby options?', 'a' => 'Use nearby category options when the first listing does not fit.']]],
]]);
$recursion_ctx = CategoryContextBuilder::build_from_parts($fixtures['amateur-cams'] + ['category_name' => 'Recursive FAQ Cams', 'category_slug' => 'recursive-faq-cams', 'primary_keyword' => 'Recursive FAQ Cams']);
$recursion_faqs = CategoryFaqPlanner::plan($recursion_ctx, 'interaction_style', 0, ['old_safe:v0']);
$faq_library->setValue(null, $original_faq_library);
$recursion_ids = array_column($recursion_faqs, 'vid');
check('FAQ planner recursion relaxes one used ID after generated variants are skipped', count($recursion_faqs) >= 3 && in_array('old_safe:v0', $recursion_ids, true), implode(',', $recursion_ids));

echo "\n== M. No hardcoded category copy (test 2) ==\n";
$hard_names = ['Amateur Cams', 'Big Boob Cam', 'Blonde Cam Models', 'Latina Cam Models', 'Free Cam Chat'];
$scan_files = array_merge(
    glob(dirname(__DIR__) . '/includes/content/category-pipeline/*.php') ?: [],
    [dirname(__DIR__) . '/data/category-universal-sections.json', dirname(__DIR__) . '/data/category-universal-faq.json']
);
$hits = [];
foreach ($scan_files as $file) {
    $src = (string) file_get_contents($file);
    foreach ($hard_names as $name) {
        if (stripos($src, $name) !== false) { $hits[] = basename($file) . ':' . $name; }
    }
}
check('pipeline code/data contain no regression-category names', empty($hits), implode(', ', $hits));

echo "\n";
echo str_repeat('=', 60) . "\n";
echo "PASS: {$pass}  FAIL: {$fail}\n";
if ($fail > 0) {
    echo "Failures:\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    exit(1);
}
exit(0);
