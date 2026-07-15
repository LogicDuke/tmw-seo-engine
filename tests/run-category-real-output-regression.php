<?php
/**
 * Real-output regression — v5.9.9 universal category output quality.
 *
 * Generates the five REAL categories from the July 2026 live audit (with
 * their approved Rank Math keyword pools) plus three synthetic categories
 * (activity, language, ambiguous), and asserts the full on-page contract
 * that the audit showed the live pages failing:
 *
 *   words 620-950 · primary 3-5 exact + first paragraph + exact-in-H2 ·
 *   four active supporting keywords where four valid ones exist, each
 *   rendered, two in headings · ≥2 real internal <a href> links, natural
 *   anchors, zero raw URLs · FAQ 3-5 and LAST (no orphan paragraph after
 *   it) · zero audit metaphor phrases · neutral intent for ambiguous and
 *   production-style names · complete per-page report · safe failure.
 *
 * Run: php tests/run-category-real-output-regression.php
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
    'class-category-content-planner', 'class-category-draft-composer', 'class-category-quality-guard',
    'class-category-factual-safety', 'class-category-grammar-guard', 'class-category-keyword-placement',
    'class-category-paragraph-uniqueness-guard', 'class-category-claim-ledger', 'class-category-specificity-scorer',
    'class-category-faq-reuse-guard', 'class-category-generation-result', 'class-category-differentiation-scorer',
    'class-category-faq-planner', 'class-category-final-validator', 'class-category-generation-pipeline',
] as $c) { require_once $pipeline_dir . $c . '.php'; }

use TMWSEO\Engine\Content\CategoryPipeline\CategoryContextBuilder;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryDifferentiationScorer;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryDraftComposer;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryContentPlanner;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryFaqPlanner;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationPipeline;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryIntentClassifier;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryKeywordPlacement;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryKeywordPlanner;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryParagraphUniquenessGuard as UGuard;

$pass = 0; $fail = 0; $failures = [];
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  ok  $label\n"; }
    else { $fail++; $failures[] = $label . ($detail !== '' ? " — $detail" : ''); echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

// ── Fixtures: 5 real (audit keyword pools) + 3 synthetic ────────────────────
$base = [
    'site_name'  => 'Top-Models.Webcam',
    'models_url' => 'https://top-models.webcam/webcam-models/',
    'videos_url' => 'https://top-models.webcam/webcam-videos/',
    'model_count' => 3244, 'video_count' => 2871,
];
$rel = static function (array $names): array {
    return array_map(static fn($n) => ['name' => $n, 'url' => 'https://top-models.webcam/category/' . strtolower(str_replace(' ', '-', $n)) . '/'], $names);
};
$fixtures = [
    'big-boob-cam' => $base + [
        'category_slug' => 'big-boob-cam', 'category_name' => 'Big Boob Cam', 'primary_keyword' => 'Big Boob Cam',
        'approved_keywords' => ['big breast webcam', 'big boobs webcam', 'biggest boobs webcam', 'massive boob webcam', 'massive boobs cam', 'massive breasts webcam'],
        'related_categories' => $rel(['Blonde Cam Models', 'Latina Cam Models']),
        'expect_intent' => 'body_type', 'expect_min_active' => 2,
    ],
    'blonde-cam-models' => $base + [
        'category_slug' => 'blonde-cam-models', 'category_name' => 'Blonde Cam Models', 'primary_keyword' => 'Blonde Cam Models',
        'approved_keywords' => ['blonde cams', 'blonde live sex', 'blonde sex cam', 'blonde live webcam'],
        'related_categories' => $rel(['Big Boob Cam', 'Latina Cam Models']),
        'expect_intent' => 'appearance_trait', 'expect_min_active' => 4,
    ],
    'latina-cam-models' => $base + [
        'category_slug' => 'latina-cam-models', 'category_name' => 'Latina Cam Models', 'primary_keyword' => 'Latina Cam Models',
        'approved_keywords' => ['latina sex cam', 'latina live sex', 'live latina cams', 'latina nude webcam'],
        'related_categories' => $rel(['Blonde Cam Models', 'Amateur Cams']),
        'expect_intent' => 'ethnicity_regional', 'expect_min_active' => 4,
    ],
    'free-cam-chat' => $base + [
        'category_slug' => 'free-cam-chat', 'category_name' => 'Free Cam Chat', 'primary_keyword' => 'Free Cam Chat',
        'approved_keywords' => ['free cam to cam chat', 'free live cams', 'free webcam chat', 'cam to cam chat', 'free live cam chat', 'cam chat sites', 'webcam chat rooms', 'free webcam shows'],
        'related_categories' => $rel(['Amateur Cams', 'Blonde Cam Models']),
        'expect_intent' => 'free_access_pricing', 'expect_min_active' => 4,
    ],
    'amateur-cams' => $base + [
        'category_slug' => 'amateur-cams', 'category_name' => 'Amateur Cams', 'primary_keyword' => 'Amateur Cams',
        'approved_keywords' => ['amateur webcam', 'amateur tv cams', 'live amateur sex cams', 'amateur sex chat'],
        'related_categories' => $rel(['Free Cam Chat', 'Latina Cam Models']),
        'expect_intent' => 'broad_discovery', 'expect_min_active' => 3, // 'amateur webcam' is a spelling variant of the primary
    ],
    // ── synthetic
    'foot-fetish-cams' => $base + [
        'category_slug' => 'foot-fetish-cams', 'category_name' => 'Foot Fetish Cams', 'primary_keyword' => 'Foot Fetish Cams',
        'approved_keywords' => ['foot fetish webcam', 'live foot worship cams', 'feet cam shows', 'foot model live chat'],
        'related_categories' => $rel(['Amateur Cams']),
        'expect_intent' => 'activity_fetish', 'expect_min_active' => 3,
    ],
    'spanish-speaking-cams' => $base + [
        'category_slug' => 'spanish-speaking-cams', 'category_name' => 'Spanish Speaking Cams', 'primary_keyword' => 'Spanish Speaking Cams',
        'approved_keywords' => ['spanish webcam models', 'spanish live chat', 'latina spanish cams', 'spanish speaking models'],
        'related_categories' => $rel(['Latina Cam Models']),
        'expect_intent' => 'language_location', 'expect_min_active' => 3,
    ],
    'velvet-room-cams' => $base + [
        'category_slug' => 'velvet-room-cams', 'category_name' => 'Velvet Room Cams', 'primary_keyword' => 'Velvet Room Cams',
        'approved_keywords' => ['velvet room webcam', 'velvet cam shows'],
        'related_categories' => $rel(['Amateur Cams']),
        'expect_intent' => 'broad_discovery', 'expect_min_active' => 1,
        'expect_low_confidence' => true,
    ],
];

// Audit phrases that must never render again (subset regression anchors).
$audit_phrases = [
    'discovery currency', 'attention buys', 'timing tail', 'taste dog',
    'trait assembles', 'pages sort, platforms operate', 'never runs dry',
    'survives contact with the room', 'relevance came free', 'stands down',
    'overlap is doing real work', 'the open side knowingly', 'shortlist mostly builds itself',
];

$host = 'top-models.webcam';
$results = [];

echo "== Generate all eight categories (production store semantics) ==\n";
foreach ($fixtures as $slug => $fx) {
    $ctx = CategoryContextBuilder::build_from_parts($fx);
    $res = CategoryGenerationPipeline::generate_from_context($ctx, ['tracking' => array_slice((array) $fx['approved_keywords'], 0, 8)]);
    $results[$slug] = $res;
    check("[$slug] generates ok", (bool) $res['ok'], implode('; ', array_slice((array) $res['report']['failure_reasons'], 0, 4)));
}

echo "\n== Per-page contract ==\n";
foreach ($fixtures as $slug => $fx) {
    $res = $results[$slug];
    if (!$res['ok']) { check("[$slug] (skipped contract: generation failed)", false); continue; }
    $html    = (string) $res['html'];
    $report  = (array) $res['report'];
    $primary = (string) $fx['primary_keyword'];
    // Space-preserving tag strip: naive strip_tags() glues a heading's last
    // word to the next paragraph's first, which breaks word-boundary keyword
    // matching. Mirror the validator's whitespace behaviour.
    $visible = trim(preg_replace('/\s+/u', ' ', strip_tags(preg_replace('/<[^>]+>/u', ' ', $html))));
    $words   = str_word_count($visible);

    // 1. Intent + confidence
    check("[$slug] intent {$report['intent']}", (string) $report['intent'] === (string) $fx['expect_intent']);
    if (!empty($fx['expect_low_confidence'])) {
        check("[$slug] ambiguous name routes to LOW confidence neutral copy", (string) ($report['intent_confidence'] ?? '') === 'low');
    }

    // 2. Word band
    $v_words = (int) ($report['metrics']['word_count'] ?? $words);
    check("[$slug] words $v_words in 620-950", $v_words >= 620 && $v_words <= 950);

    // 3. Primary keyword placement
    $pl = CategoryKeywordPlacement::analyze($html, $primary);
    check("[$slug] primary {$pl['count']}x in 3-5", $pl['count'] >= 3 && $pl['count'] <= 5);
    check("[$slug] primary in first paragraph", (bool) $pl['in_first_paragraph']);
    check("[$slug] primary exact in >=1 H2", (bool) $pl['in_h2']);
    check("[$slug] primary in body beyond opening", (bool) $pl['in_body_beyond_opening']);
    // ...but not in EVERY heading
    preg_match_all('/<h2[^>]*>(.*?)<\/h2>/isu', $html, $hm);
    $h2_with = 0;
    foreach ($hm[1] as $h) { if (stripos(strip_tags($h), $primary) !== false) { $h2_with++; } }
    check("[$slug] primary not in every H2 ($h2_with/" . count($hm[1]) . ')', $h2_with < count($hm[1]));

    // 4. Supporting keywords: active set rendered, roles assigned, headings used
    $body_use = (array) ($report['keyword_plan']['body_use'] ?? []);
    check("[$slug] active supporting >= {$fx['expect_min_active']} (got " . count($body_use) . ')', count($body_use) >= (int) $fx['expect_min_active']);
    $all_present = true; $missing = '';
    foreach ($body_use as $kw) {
        if (!preg_match('/(?<![\p{L}\p{N}])' . preg_quote((string) $kw, '/') . '(?![\p{L}\p{N}])/iu', $visible)) { $all_present = false; $missing = (string) $kw; break; }
    }
    check("[$slug] every active supporting keyword rendered", $all_present, $missing);
    $roles = (array) ($report['keyword_plan']['roles'] ?? []);
    $heading_roles = array_keys(array_filter($roles, static fn($r) => $r === 'heading_h2' || $r === 'heading_secondary'));
    if (count($body_use) >= 2) {
        check("[$slug] >=2 supporting keywords hold heading roles", count($heading_roles) >= 2, json_encode($roles));
        $missing_heading_role = '';
        foreach ($heading_roles as $hkw) {
            $found_heading = false;
            foreach (array_merge($hm[1], []) as $h) {
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote((string) $hkw, '/') . '(?![\p{L}\p{N}])/iu', strip_tags($h))) { $found_heading = true; break; }
            }
            if (!$found_heading && preg_match('/<h3[^>]*>[^<]*' . preg_quote((string) $hkw, '/') . '/iu', $html)) { $found_heading = true; }
            if (!$found_heading) { $missing_heading_role = (string) $hkw; break; }
        }
        check("[$slug] every heading-role keyword actually appears in a heading", $missing_heading_role === '', $missing_heading_role);
    }
    // unused keywords carry logged reasons
    $unused = (array) ($report['keyword_plan']['unused'] ?? []);
    $reasons_ok = true;
    foreach ($unused as $u) { if (trim((string) ($u['reason'] ?? '')) === '') { $reasons_ok = false; break; } }
    check("[$slug] every unused keyword has a logged reason", $reasons_ok);

    // 5. Internal links
    preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu', $html, $lm, PREG_SET_ORDER);
    $internal = 0; $anchors_ok = true;
    foreach ($lm as $l) {
        $h = strtolower((string) (parse_url((string) $l[1], PHP_URL_HOST) ?: ''));
        if ($h === $host) {
            $internal++;
            $a = trim(strip_tags((string) $l[2]));
            if ($a === '' || stripos($a, 'http') === 0 || stripos($a, $host) !== false) { $anchors_ok = false; }
        }
    }
    check("[$slug] >=2 internal links (got $internal)", $internal >= 2);
    check("[$slug] anchor text natural (never a URL)", $anchors_ok);
    $prose = preg_replace('/' . preg_quote((string) $fx['site_name'], '/') . '/iu', '', $visible);
    check("[$slug] no raw URLs in visible text", !preg_match('/https?:\/\/\S+/iu', $prose) && !preg_match('/(?<![\p{L}\p{N}@.])[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:com|net|org|webcam|cam|xxx|tv)(?![\p{L}\p{N}])\/?/iu', $prose));
    check("[$slug] report lists the rendered internal links", count((array) ($report['internal_links'] ?? [])) >= 2);

    // 6. FAQ last, 3-5, no orphan paragraph after it
    $faq_heading = '<h2>Frequently Asked Questions</h2>';
    $fp = stripos($html, $faq_heading);
    check("[$slug] FAQ section present", $fp !== false);
    if ($fp !== false) {
        $after = substr($html, $fp + strlen($faq_heading));
        check("[$slug] no H2 after the FAQ heading", !preg_match('/<h2[^>]*>/i', $after));
        $q = preg_match_all('/<h3[^>]*>/i', $after);
        check("[$slug] FAQ count $q in 3-5", $q >= 3 && $q <= 5);
        preg_match_all('/<(h3|p)[^>]*>/i', $after, $faq_tags);
        $sequence_ok = count($faq_tags[1]) === $q * 2;
        foreach ($faq_tags[1] as $idx => $tag) {
            $expected = ($idx % 2 === 0) ? 'h3' : 'p';
            if (strtolower((string) $tag) !== $expected) { $sequence_ok = false; break; }
        }
        check("[$slug] FAQ alternates h3/p through the final answer", $sequence_ok, implode(',', $faq_tags[1] ?? []));
        check("[$slug] page ends on the FAQ block", (bool) preg_match('/<\/p>\s*$/i', $html));
        $faq_selection = (array) ($report['faq_selection'] ?? []);
        $buckets_ok = count($faq_selection) === $q;
        foreach ($faq_selection as $sel) { if (trim((string) ($sel['bucket'] ?? '')) === '') { $buckets_ok = false; break; } }
        check("[$slug] FAQ selection logged with buckets", $buckets_ok);
    }

    // 7. Audit phrases + placeholders + evidence
    $hit = '';
    foreach ($audit_phrases as $ph) { if (stripos($visible, $ph) !== false) { $hit = $ph; break; } }
    check("[$slug] zero audit metaphor phrases", $hit === '', $hit);
    check("[$slug] zero unresolved placeholders", strpos($html, '{{') === false);
    check("[$slug] link replacement preserves following whitespace", !preg_match('/<\/a>(?=[A-Za-z])/', $html));
    check("[$slug] claim ledger recorded in report", isset($report['claim_ledger']) || isset($report['evidence']) || isset($report['claims']));
    check("[$slug] provider recorded", (string) ($report['provider'] ?? '') !== '');
}

echo "\n== Cross-page differentiation ==\n";
$plans = [];
foreach ($results as $slug => $res) { if ($res['ok']) { $plans[$slug] = implode('|', (array) ($res['report']['sections'] ?? $res['report']['attempt_log'][count((array) $res['report']['attempt_log']) - 1]['sections'] ?? [])); } }
check('not all pages share one section plan', count(array_unique($plans)) > 1, (string) count(array_unique($plans)));
$ok_htmls = array_map(static fn($r) => (string) $r['html'], array_filter($results, static fn($r) => (bool) $r['ok']));
$dupes = '';
$seen = [];
foreach ($ok_htmls as $slug => $h) {
    preg_match_all('/<p[^>]*>(.*?)<\/p>/isu', $h, $pm);
    foreach ($pm[1] as $p) {
        $norm = strtolower(trim(preg_replace('/\s+/', ' ', strip_tags($p))));
        foreach ($fixtures as $fslug => $fx) { $norm = str_ireplace(strtolower((string) $fx['category_name']), 'topic', $norm); }
        if ($norm === '') { continue; }
        if (isset($seen[$norm]) && $seen[$norm] !== $slug) { $dupes = $seen[$norm] . '~' . $slug . ': ' . substr($norm, 0, 60); break 2; }
        $seen[$norm] = $slug;
    }
}
check('no identical normalized paragraph appears on two pages', $dupes === '', $dupes);

echo "\n== No hardcoded category handling ==\n";
$code = '';
foreach (glob($pipeline_dir . '*.php') as $f) { $code .= file_get_contents($f); }
$code .= file_get_contents(dirname(__DIR__) . '/data/category-universal-sections.json');
$code .= file_get_contents(dirname(__DIR__) . '/data/category-universal-faq.json');
$named = '';
foreach (array_keys($fixtures) as $slug) {
    $name = (string) $fixtures[$slug]['category_name'];
    if (stripos($code, $name) !== false) { $named = $name; break; }
}
check('pipeline code and data contain no fixture category names', $named === '', $named);

echo "\n== Safe failure ==\n";
$dup = $fixtures['amateur-cams'];
$dup['category_slug'] = 'forced-failure';
$ctx = CategoryContextBuilder::build_from_parts($dup);
$cls = CategoryIntentClassifier::classify($ctx);
$kwp = CategoryKeywordPlanner::plan((string) $dup['primary_keyword'], (array) $dup['approved_keywords'], []);
$shadows = [];
for ($salt = 0; $salt < CategoryGenerationPipeline::MAX_ATTEMPTS; $salt++) {
    $plan = CategoryContentPlanner::plan($ctx, (string) $cls['intent'], $salt, $kwp);
    $comp = CategoryDraftComposer::compose($ctx, $plan, $kwp);
    $h    = (string) $comp['html'] . CategoryFaqPlanner::render(CategoryFaqPlanner::plan($ctx, (string) $cls['intent'], $salt));
    $fp   = CategoryDifferentiationScorer::fingerprint($h, [], 'shadow-' . $salt);
    $fp['uniqueness'] = UGuard::fingerprint($h, [], (array) $comp['sentence_ids']);
    $shadows[] = $fp;
}
$failr = CategoryGenerationPipeline::generate_from_context($ctx, ['tracking' => array_slice((array) $dup['approved_keywords'], 0, 8), 'use_store' => false, 'comparisons' => $shadows]);
check('impossible constraints fail safely (ok=false, empty html, reasons logged)', !$failr['ok'] && (string) $failr['html'] === '' && !empty($failr['report']['failure_reasons']));
check('failed report still carries attempt log', count((array) ($failr['report']['attempt_log'] ?? [])) === CategoryGenerationPipeline::MAX_ATTEMPTS);

echo "\n============================================================\n";
echo "PASS: $pass  FAIL: $fail\n";
if ($fail > 0) { echo "Failures:\n"; foreach ($failures as $f) { echo "  - $f\n"; } exit(1); }
exit(0);
