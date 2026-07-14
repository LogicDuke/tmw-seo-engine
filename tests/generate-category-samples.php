<?php
/**
 * v5.9.8 sample generator — regenerates the ten verification categories
 * deterministically (fresh rolling store per run) and prints every page
 * FROM the immutable CategoryGenerationResult, so the samples file and the
 * verification report cannot describe different runs: the generation ID on
 * each sample is the binding reference.
 *
 * Usage: php tests/generate-category-samples.php > samples.txt
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
] as $c) {
    require_once $pipeline_dir . 'class-category-' . $c . '.php';
}

use TMWSEO\Engine\Content\CategoryPipeline\CategoryContextBuilder;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationPipeline;

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
    'silver-fox-gentlemen-cams' => $base + ['category_slug' => 'silver-fox-gentlemen-cams', 'category_name' => 'Silver Fox Gentlemen Cams', 'primary_keyword' => 'Silver Fox Gentlemen Cams', 'approved_keywords' => ['silver fox cams', 'gentlemen webcam'], 'related_categories' => ['Amateur Cams'], 'model_count' => 8, 'video_count' => 4],
    'redhead-webcam-models'     => $base + ['category_slug' => 'redhead-webcam-models', 'category_name' => 'Redhead Webcam Models', 'primary_keyword' => 'Redhead Webcam Models', 'approved_keywords' => ['redhead cams', 'ginger webcam'], 'related_categories' => ['Blonde Cam Models'], 'model_count' => 14, 'video_count' => 6],
    'couples-cam-chat'          => $base + ['category_slug' => 'couples-cam-chat', 'category_name' => 'Couples Cam Chat', 'primary_keyword' => 'Couples Cam Chat', 'approved_keywords' => ['couples webcam', 'couple cams live'], 'related_categories' => ['Free Cam Chat'], 'model_count' => 19, 'video_count' => 11],
    'french-speaking-cam-models' => $base + ['category_slug' => 'french-speaking-cam-models', 'category_name' => 'French Speaking Cam Models', 'primary_keyword' => 'French Speaking Cam Models', 'approved_keywords' => ['french cams', 'french webcam chat'], 'related_categories' => ['Latina Cam Models'], 'model_count' => 7, 'video_count' => 3],
    'tattooed-cam-performers'   => $base + ['category_slug' => 'tattooed-cam-performers', 'category_name' => 'Tattooed Cam Performers', 'primary_keyword' => 'Tattooed Cam Performers', 'approved_keywords' => ['tattoo cams', 'inked webcam models'], 'related_categories' => ['Redhead Webcam Models'], 'model_count' => 16, 'video_count' => 22],
];

echo "TMW SEO Engine v5.9.8 — universal category pipeline OUTPUT SAMPLES\n";
echo "Every field below is printed from the immutable CategoryGenerationResult\n";
echo "of the same run; the generation ID binds sample, report, and debug meta.\n";
echo str_repeat('=', 78) . "\n";

foreach ($fixtures as $slug => $parts) {
    $context = CategoryContextBuilder::build_from_parts($parts);
    $out     = CategoryGenerationPipeline::generate_from_context($context, ['tracking' => []]);
    $r       = $out['result'];
    $rep     = $out['report'];

    echo "\n### {$parts['category_name']}  ({$slug})\n";
    echo 'generation_id: ' . $r->generation_id() . "\n";
    echo 'input_hash:    ' . $r->input_hash() . "\n";
    echo 'intent:        ' . $r->intent() . '   provider: ' . $r->provider() . '   attempts: ' . $r->attempts() . "\n";
    echo 'final_status:  ' . $r->final_status() . '   final_hash: ' . $r->final_output_hash() . "\n";
    echo 'words: ' . (int) ($rep['metrics']['word_count'] ?? 0)
        . '   intent_paragraphs: ' . (int) ($rep['metrics']['intent_paragraphs'] ?? 0)
        . '   max_paragraph_similarity: ' . var_export($rep['metrics']['max_paragraph_similarity'] ?? null, true) . "\n";
    echo 'claim ledger: ' . json_encode($r->get('claim_ledger')['counts'] ?? []) . ' unsupported: ' . count((array) ($r->get('claim_ledger')['unsupported'] ?? [])) . "\n";
    echo 'faq_ids: ' . implode(', ', (array) $r->get('faq_ids')) . "\n";
    echo str_repeat('-', 78) . "\n";
    if ($out['ok']) {
        $readable = preg_replace('/<\/(p|h2|h3)>/i', "</$1>\n\n", (string) $out['html']);
        echo trim((string) $readable) . "\n";
    } else {
        echo "GENERATION FAILED (never saved). Reasons: " . implode('; ', (array) $rep['failure_reasons']) . "\n";
    }
    echo str_repeat('=', 78) . "\n";
}
