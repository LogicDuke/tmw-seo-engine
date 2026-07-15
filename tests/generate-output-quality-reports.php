<?php
/**
 * Deterministic before/after samples + v5.9.9 quality reports.
 * Writes into codex-reports/. Run: php tests/generate-output-quality-reports.php
 */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/'); }
$GLOBALS['__opts'] = [];
function get_option($k, $d = false) { return $GLOBALS['__opts'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['__opts'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['__opts'][$k]); return true; }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_url($u) { return filter_var((string) $u, FILTER_SANITIZE_URL); }

$pd = dirname(__DIR__) . '/includes/content/category-pipeline/';
foreach (glob($pd . 'class-*.php') as $f) { require_once $f; }
use TMWSEO\Engine\Content\CategoryPipeline as CP;

// The "before" side is the July 2026 live audit (PDF): real measured output.
$before = [
    'big-boob-cam'      => ['words' => 539, 'primary_uses' => 2, 'primary_in_subheading' => true,  'internal_links' => 0, 'supporting_in_headings' => 0],
    'blonde-cam-models' => ['words' => 572, 'primary_uses' => 3, 'primary_in_subheading' => true,  'internal_links' => 0, 'supporting_in_headings' => 0],
    'latina-cam-models' => ['words' => 574, 'primary_uses' => 1, 'primary_in_subheading' => false, 'internal_links' => 0, 'supporting_in_headings' => 0],
    'free-cam-chat'     => ['words' => 580, 'primary_uses' => 2, 'primary_in_subheading' => true,  'internal_links' => 0, 'supporting_in_headings' => 0],
    'amateur-cams'      => ['words' => 590, 'primary_uses' => 2, 'primary_in_subheading' => true,  'internal_links' => 0, 'supporting_in_headings' => 0],
];

$base = ['site_name' => 'Top-Models.Webcam', 'models_url' => 'https://top-models.webcam/webcam-models/', 'videos_url' => 'https://top-models.webcam/webcam-videos/', 'model_count' => 3244, 'video_count' => 2871];
$rel  = static fn(array $n) => array_map(static fn($x) => ['name' => $x, 'url' => 'https://top-models.webcam/category/' . strtolower(str_replace(' ', '-', $x)) . '/'], $n);
$fixtures = [
    'big-boob-cam'      => $base + ['category_slug' => 'big-boob-cam', 'category_name' => 'Big Boob Cam', 'primary_keyword' => 'Big Boob Cam', 'approved_keywords' => ['big breast webcam', 'big boobs webcam', 'biggest boobs webcam', 'massive boob webcam', 'massive boobs cam', 'massive breasts webcam'], 'related_categories' => $rel(['Blonde Cam Models', 'Latina Cam Models'])],
    'blonde-cam-models' => $base + ['category_slug' => 'blonde-cam-models', 'category_name' => 'Blonde Cam Models', 'primary_keyword' => 'Blonde Cam Models', 'approved_keywords' => ['blonde cams', 'blonde live sex', 'blonde sex cam', 'blonde live webcam'], 'related_categories' => $rel(['Big Boob Cam', 'Latina Cam Models'])],
    'latina-cam-models' => $base + ['category_slug' => 'latina-cam-models', 'category_name' => 'Latina Cam Models', 'primary_keyword' => 'Latina Cam Models', 'approved_keywords' => ['latina sex cam', 'latina live sex', 'live latina cams', 'latina nude webcam'], 'related_categories' => $rel(['Blonde Cam Models', 'Amateur Cams'])],
    'free-cam-chat'     => $base + ['category_slug' => 'free-cam-chat', 'category_name' => 'Free Cam Chat', 'primary_keyword' => 'Free Cam Chat', 'approved_keywords' => ['free cam to cam chat', 'free live cams', 'free webcam chat', 'cam to cam chat', 'free live cam chat', 'cam chat sites', 'webcam chat rooms', 'free webcam shows'], 'related_categories' => $rel(['Amateur Cams', 'Blonde Cam Models'])],
    'amateur-cams'      => $base + ['category_slug' => 'amateur-cams', 'category_name' => 'Amateur Cams', 'primary_keyword' => 'Amateur Cams', 'approved_keywords' => ['amateur webcam', 'amateur tv cams', 'live amateur sex cams', 'amateur sex chat'], 'related_categories' => $rel(['Free Cam Chat', 'Latina Cam Models'])],
];

$out = dirname(__DIR__) . '/codex-reports/';
@mkdir($out, 0777, true);
$kw_report = []; $link_report = []; $claims_report = []; $nl_report = []; $ba = [];

foreach ($fixtures as $slug => $fx) {
    $ctx = CP\CategoryContextBuilder::build_from_parts($fx);
    $res = CP\CategoryGenerationPipeline::generate_from_context($ctx, ['tracking' => array_slice((array) $fx['approved_keywords'], 0, 8)]);
    if (!$res['ok']) { fwrite(STDERR, "$slug FAILED\n"); exit(1); }
    $html = (string) $res['html']; $r = (array) $res['report'];
    file_put_contents($out . 'after-' . $slug . '.html', $html);

    $pl = CP\CategoryKeywordPlacement::analyze($html, (string) $fx['primary_keyword']);
    preg_match_all('/<h2[^>]*>(.*?)<\/h2>/isu', $html, $hm);
    $sup_in_headings = 0;
    foreach ((array) ($r['keyword_plan']['body_use'] ?? []) as $kw) {
        foreach ($hm[1] as $h) { if (stripos(strip_tags($h), (string) $kw) !== false) { $sup_in_headings++; break; } }
    }
    $ba[$slug] = [
        'before' => $before[$slug],
        'after'  => [
            'words' => (int) ($r['metrics']['word_count'] ?? 0),
            'primary_uses' => (int) $pl['count'],
            'primary_in_first_paragraph' => (bool) $pl['in_first_paragraph'],
            'primary_exact_in_h2' => (bool) $pl['in_h2'],
            'internal_links' => count((array) ($r['internal_links'] ?? [])),
            'supporting_in_headings' => $sup_in_headings,
            'intent' => (string) $r['intent'],
            'intent_confidence' => (string) ($r['intent_confidence'] ?? ''),
            'faq_count' => count((array) ($r['faq_selection'] ?? [])),
            'faq_last' => (bool) preg_match('/<\/(p|h3)>\s*$/i', $html),
        ],
    ];
    $kw_report[$slug] = [
        'primary'  => (string) ($r['keyword_plan']['primary'] ?? ''),
        'tracking' => (array) ($r['keyword_plan']['rankmath_tracking'] ?? []),
        'active'   => (array) ($r['supporting_keyword_map'] ?? []),
        'roles'    => (array) ($r['keyword_plan']['roles'] ?? []),
        'heading_keyword_map' => (array) ($r['heading_keyword_map'] ?? []),
        'unused'   => (array) ($r['keyword_plan']['unused'] ?? []),
        'primary_placement' => $pl,
    ];
    $link_report[$slug]   = (array) ($r['internal_links'] ?? []);
    $claims_report[$slug] = ['claim_ledger' => $r['claim_ledger'] ?? ($r['claims'] ?? null), 'rejected' => $r['rejected_claims'] ?? ($r['repairs'] ?? [])];
    $nl_report[$slug]     = ['grammar_repairs' => $r['grammar_repairs'] ?? [], 'repairs' => $r['repairs'] ?? [], 'faq_selection' => $r['faq_selection'] ?? []];
    echo "$slug ok (words=" . $ba[$slug]['after']['words'] . ", primary=" . $pl['count'] . "x, links=" . $ba[$slug]['after']['internal_links'] . ")\n";
}

file_put_contents($out . 'before-after-summary.json', json_encode($ba, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($out . 'keyword-role-report.json',  json_encode($kw_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($out . 'internal-link-report.json', json_encode($link_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($out . 'claim-ledger-report.json',  json_encode($claims_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($out . 'nl-quality-report.json',    json_encode($nl_report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "reports written to codex-reports/\n";
