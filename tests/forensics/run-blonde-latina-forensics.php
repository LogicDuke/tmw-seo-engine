<?php
/**
 * FORENSIC REPRODUCTION — Blonde Cam Models (fail) vs Latina Cam Models (ok).
 *
 * Runs the exact deployed v5.9.13 pipeline classes with:
 *   1. the regression-fixture keyword sets (known green), and
 *   2. the LIVE keyword sets read from the reproduction PDF sidebar,
 * each with and without the v5.9.13 final-document density context that the
 * production entry point (run_optimize_job) registers but the shipped
 * regression suites never set.
 */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/'); }

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
    'class-category-factual-safety', 'class-category-grammar-guard', 'class-category-density-policy', 'class-category-keyword-placement',
    'class-category-paragraph-uniqueness-guard', 'class-category-claim-ledger', 'class-category-specificity-scorer',
    'class-category-faq-reuse-guard', 'class-category-generation-result', 'class-category-differentiation-scorer',
    'class-category-faq-planner', 'class-category-final-validator', 'class-category-generation-pipeline',
] as $c) { require_once $pipeline_dir . $c . '.php'; }
require_once dirname(__DIR__) . '/includes/content/class-rank-math-chip-analyzer.php';

use TMWSEO\Engine\Content\CategoryPipeline\CategoryContextBuilder;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationPipeline;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryDensityPolicy;
use TMWSEO\Engine\Content\RankMathChipAnalyzer;

$base = [
    'site_name'  => 'Top-Models.Webcam',
    'models_url' => 'https://top-models.webcam/webcam-models/',
    'videos_url' => 'https://top-models.webcam/webcam-videos/',
    'model_count' => 3244, 'video_count' => 2871,
];
$rel = static function (array $names): array {
    return array_map(static fn($n) => ['name' => $n, 'url' => 'https://top-models.webcam/category/' . strtolower(str_replace(' ', '-', $n)) . '/'], $names);
};

// LIVE keyword sets, transcribed from the reproduction PDF sidebars.
$live_blonde = ['blonde cams', 'blonde live cam', 'blonde sex cam', 'blonde live webcam', 'live blonde cams', 'blonde nude cam', 'free blonde cams'];
$fixture_blonde = ['blonde cams', 'blonde live sex', 'blonde sex cam', 'blonde live webcam'];
$live_latina = ['latina sex cam', 'latina live sex', 'live latina cams', 'latina nude webcam'];

// The live affiliate CTA block preserved as suffix by compute_category_final_context.
$cta_suffix = '<!-- wp:html --><div class="tmw-category-page-affiliate-cta"><p>Ready for more? <a href="https://ctwmsg.com/x" rel="sponsored nofollow">Open the live rooms</a> and keep browsing.</p></div><!-- /wp:html -->';

$cases = [
    ['blonde-fixture-kws / no-final-ctx', 'Blonde Cam Models', 'blonde-cam-models', $fixture_blonde, false, '', ''],
    ['blonde-LIVE-kws    / no-final-ctx', 'Blonde Cam Models', 'blonde-cam-models', $live_blonde, false, '', ''],
    ['blonde-LIVE-kws    / final-ctx empty post', 'Blonde Cam Models', 'blonde-cam-models', $live_blonde, true, '', ''],
    ['blonde-LIVE-kws    / final-ctx + CTA', 'Blonde Cam Models', 'blonde-cam-models', $live_blonde, true, '', $cta_suffix],
    ['blonde-fixture-kws / final-ctx empty post', 'Blonde Cam Models', 'blonde-cam-models', $fixture_blonde, true, '', ''],
    ['latina-LIVE-kws    / no-final-ctx', 'Latina Cam Models', 'latina-cam-models', $live_latina, false, '', ''],
    ['latina-LIVE-kws    / final-ctx empty post', 'Latina Cam Models', 'latina-cam-models', $live_latina, true, '', ''],
    ['latina-LIVE-kws    / final-ctx + CTA', 'Latina Cam Models', 'latina-cam-models', $live_latina, true, '', $cta_suffix],
];

foreach ($cases as [$label, $name, $slug, $kws, $set_ctx, $prefix, $suffix]) {
    // Fresh option store per case so cooldown/uniqueness state never bleeds between cases.
    $GLOBALS['__opts'] = [];
    CategoryDensityPolicy::clear_final_context();
    if ($set_ctx) { CategoryDensityPolicy::set_final_context($prefix, $suffix); }

    $fx = $base + [
        'category_slug' => $slug, 'category_name' => $name, 'primary_keyword' => $name,
        'approved_keywords' => $kws,
        'stored_chips' => $kws,
        'related_categories' => $rel(['Big Boob Cam', 'Free Cam Chat']),
    ];
    $ctx = CategoryContextBuilder::build_from_parts($fx);
    $tracking = array_slice($kws, 0, 8);
    $res = CategoryGenerationPipeline::generate_from_context($ctx, ['tracking' => $tracking]);
    $rep = (array) $res['report'];
    $den = (array) ($rep['metrics']['density'] ?? []);
    printf("%-45s ok=%-3s attempts=%d words=%s combined=%s/%s-%s density=%s status=%s\n",
        $label,
        !empty($res['ok']) ? 'YES' : 'NO',
        (int) ($rep['attempt_count'] ?? 0),
        (string) ($rep['metrics']['word_count'] ?? '?'),
        (string) ($den['combined_count'] ?? '?'), (string) ($den['min_count'] ?? '?'), (string) ($den['max_count'] ?? '?'),
        (string) ($den['density'] ?? '?'),
        (string) ($den['status'] ?? '?')
    );
    if (empty($res['ok'])) {
        echo "    FAILURE REASONS:\n";
        foreach ((array) ($rep['failure_reasons'] ?? []) as $r) { echo "      - $r\n"; }
        // Per-attempt validator detail
        foreach ((array) ($rep['attempt_log'] ?? []) as $i => $a) {
            echo "    attempt " . ($i + 1) . " provider=" . ($a['provider'] ?? '?') . " reasons=" . implode('; ', array_slice((array) ($a['validation_reasons'] ?? $a['reasons'] ?? []), 0, 6)) . "\n";
        }
    }
    CategoryDensityPolicy::clear_final_context();
}
