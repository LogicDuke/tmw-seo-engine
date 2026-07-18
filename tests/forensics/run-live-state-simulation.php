<?php
/**
 * PRODUCTION-STATE SIMULATION.
 *
 * The sandbox suites run each category against an EMPTY differentiation
 * store. Production runs against tmwseo_cat_diff_recent holding up to 12
 * fingerprints accumulated from prior generations/regenerations, whose
 * variant/sentence/FAQ IDs also feed the cooldown avoid-lists.
 *
 * This harness replays a realistic live sequence: the five real categories
 * generated (and re-generated) in order with ONE persistent store — exactly
 * what use_store=true does in production — logging per-attempt validator
 * reasons for every failure and near-failure.
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

$base = [
    'site_name'  => 'Top-Models.Webcam',
    'models_url' => 'https://top-models.webcam/webcam-models/',
    'videos_url' => 'https://top-models.webcam/webcam-videos/',
    'model_count' => 3244, 'video_count' => 2871,
];
$rel = static function (array $names): array {
    return array_map(static fn($n) => ['name' => $n, 'url' => 'https://top-models.webcam/category/' . strtolower(str_replace(' ', '-', $n)) . '/'], $names);
};

$cats = [
    'big-boob-cam'      => ['Big Boob Cam', ['big breast webcam', 'big boobs webcam', 'biggest boobs webcam', 'massive boob webcam', 'massive boobs cam', 'massive breasts webcam']],
    'amateur-cams'      => ['Amateur Cams', ['amateur webcam', 'amateur tv cams', 'live amateur sex cams', 'amateur sex chat']],
    'free-cam-chat'     => ['Free Cam Chat', ['free cam to cam chat', 'free live cams', 'free webcam chat', 'cam to cam chat', 'free live cam chat', 'cam chat sites', 'webcam chat rooms', 'free webcam shows']],
    'latina-cam-models' => ['Latina Cam Models', ['latina sex cam', 'latina live sex', 'live latina cams', 'latina nude webcam']],
    'blonde-cam-models' => ['Blonde Cam Models', ['blonde cams', 'blonde live cam', 'blonde sex cam', 'blonde live webcam', 'live blonde cams', 'blonde nude cam', 'free blonde cams']],
];

// Two full passes over the five categories (regeneration history), then a
// final Latina + Blonde round — mirroring the live evidence order where
// Latina succeeded and Blonde was retried last.
$sequence = array_merge(array_keys($cats), array_keys($cats), ['latina-cam-models', 'blonde-cam-models']);

$round = [];
foreach ($sequence as $i => $slug) {
    [$name, $kws] = $cats[$slug];
    $round[$slug] = ($round[$slug] ?? 0) + 1;
    $fx = $base + [
        'category_slug' => $slug, 'category_name' => $name, 'primary_keyword' => $name,
        'approved_keywords' => $kws, 'stored_chips' => $kws,
        'related_categories' => $rel(array_slice(array_column(array_values(array_diff_key($cats, [$slug => 1])), 0), 0, 2)),
    ];
    $ctx = CategoryContextBuilder::build_from_parts($fx);
    CategoryDensityPolicy::set_final_context('', ''); // production entry point always registers a context
    $res = CategoryGenerationPipeline::generate_from_context($ctx, ['tracking' => array_slice($kws, 0, 8)]);
    CategoryDensityPolicy::clear_final_context();
    $rep = (array) $res['report'];
    printf("#%02d %-18s gen%-2d ok=%-3s attempts=%d words=%s\n", $i + 1, $slug, $round[$slug],
        !empty($res['ok']) ? 'YES' : 'NO', (int) ($rep['attempt_count'] ?? 0), (string) ($rep['metrics']['word_count'] ?? '?'));
    if (empty($res['ok']) || (int) ($rep['attempt_count'] ?? 0) >= 6) {
        foreach ((array) ($rep['attempt_log'] ?? []) as $a) {
            printf("      attempt salt=%d passed=%s reasons=%s\n", (int) $a['salt'], !empty($a['passed']) ? 'y' : 'n',
                implode(' | ', array_slice((array) $a['reasons'], 0, 5)));
        }
    }
}
