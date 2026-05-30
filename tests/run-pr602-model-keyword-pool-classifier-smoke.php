<?php
/**
 * Smoke checks for PR 602 model keyword pool classifier and fallback packs.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

require_once dirname(__DIR__) . '/includes/keywords/class-model-keyword-pool-classifier.php';
require_once dirname(__DIR__) . '/includes/keywords/class-model-fallback-keyword-pack-builder.php';

use TMWSEO\Engine\Keywords\ModelFallbackKeywordPackBuilder;
use TMWSEO\Engine\Keywords\ModelKeywordPoolClassifier;

function pr602_assert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function pr602_check_class(ModelKeywordPoolClassifier $classifier, string $phrase, string $class, ?bool $standalone, array $context = []): void {
    $result = $classifier->classify($phrase, $context);
    pr602_assert($result['keyword_class'] === $class, $phrase . ' expected ' . $class . ' got ' . $result['keyword_class']);
    if (null !== $standalone) { pr602_assert($result['standalone_allowed'] === $standalone, $phrase . ' standalone mismatch'); }
}

$classifier = new ModelKeywordPoolClassifier();
$builder = new ModelFallbackKeywordPackBuilder($classifier);

pr602_check_class($classifier, 'video', ModelKeywordPoolClassifier::CLASS_UNSAFE_STANDALONE, false);
pr602_check_class($classifier, 'chat', ModelKeywordPoolClassifier::CLASS_UNSAFE_STANDALONE, false);
pr602_check_class($classifier, 'live', ModelKeywordPoolClassifier::CLASS_UNSAFE_STANDALONE, false);
pr602_check_class($classifier, 'webcam model', ModelKeywordPoolClassifier::CLASS_CORE_MODEL_TERM, true);
pr602_check_class($classifier, 'adult video chat model', ModelKeywordPoolClassifier::CLASS_CORE_MODEL_TERM, true);
pr602_check_class($classifier, 'livejasmin', ModelKeywordPoolClassifier::CLASS_PLATFORM_TERM, false);
pr602_check_class($classifier, 'jasmin', ModelKeywordPoolClassifier::CLASS_PLATFORM_TERM, false);
pr602_check_class($classifier, 'brunette', ModelKeywordPoolClassifier::CLASS_ATTRIBUTE_TERM, false);
pr602_check_class($classifier, 'latina', ModelKeywordPoolClassifier::CLASS_GEO_LANGUAGE_TERM, false);
pr602_check_class($classifier, 'colombian', ModelKeywordPoolClassifier::CLASS_GEO_LANGUAGE_TERM, false);
pr602_check_class($classifier, 'cam2cam', ModelKeywordPoolClassifier::CLASS_FEATURE_MODIFIER, false);
pr602_check_class($classifier, 'anisyia', ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, true, [ 'model_name' => 'Anisyia' ]);
pr602_assert($classifier->classify('anisyia')['keyword_class'] !== ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, 'Anisyia without context should not be personal.');
pr602_check_class($classifier, 'anisyia livejasmin model', ModelKeywordPoolClassifier::CLASS_GENERATED_LONGTAIL, false, [ 'is_generated' => true ]);
pr602_check_class($classifier, 'video', ModelKeywordPoolClassifier::CLASS_UNSAFE_STANDALONE, false, [ 'is_generated' => true ]);
pr602_check_class($classifier, 'webcam model', ModelKeywordPoolClassifier::CLASS_CORE_MODEL_TERM, true, [ 'is_generated' => true ]);
pr602_check_class($classifier, 'private chat', ModelKeywordPoolClassifier::CLASS_INTENT_TERM, false, [ 'is_generated' => true ]);

$strong = $builder->build_preview(4457, 'Anisyia', [ 'anisyia', 'anisyia livejasmin', 'livejasmin anisyia' ], [ 'webcam model', 'private chat' ]);
pr602_assert($strong['keyword_data_strength'] === 'strong', 'Anisyia should be strong.');
pr602_assert($strong['fallback_generated_patterns'] === [], 'Strong pack should not have generated patterns.');

$medium = $builder->build_preview(1, 'TestModel', [ 'testmodel' ], [ 'webcam model', 'private chat' ]);
pr602_assert($medium['keyword_data_strength'] === 'medium', 'TestModel should be medium.');
pr602_assert(!empty($medium['fallback_generated_patterns']), 'Medium pack should include generated patterns.');

$low = $builder->build_preview(2, 'NoDataModel', [], [ 'webcam model', 'private chat' ]);
pr602_assert($low['keyword_data_strength'] === 'low', 'NoDataModel should be low.');
pr602_assert(strpos((string) $low['primary_keyword_recommendation'], 'nodatamodel') !== false, 'Low primary should include nodatamodel.');

foreach ([ $strong, $medium, $low ] as $pack) {
    pr602_assert($pack['preview_only'] === true, 'All packs must be preview_only.');
}
foreach ($low['fallback_generated_patterns'] as $pattern) {
    $result = $classifier->classify($pattern, [ 'is_generated' => true ]);
    pr602_assert($result['keyword_class'] === ModelKeywordPoolClassifier::CLASS_GENERATED_LONGTAIL, 'Generated pattern should classify as generated_longtail: ' . $pattern);
}

print "✓ PR 602 model keyword pool classifier and fallback pack smoke checks passed\n";
exit(0);
