<?php
/**
 * Smoke checks for keyword candidate classification audit.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__); }

require_once __DIR__ . '/../includes/keywords/class-keyword-candidate-classification-audit.php';

use TMWSEO\Engine\Keywords\KeywordCandidateClassificationAudit;

$report = KeywordCandidateClassificationAudit::audit_rows([
    [ 'keyword' => 'cheapest sex cam sites', 'intent_type' => 'model', 'entity_type' => 'topic_entity' ],
    [ 'keyword' => 'creative live cam chat hd', 'intent_type' => 'model', 'entity_type' => 'topic_entity' ],
    [ 'keyword' => 'anisyia', 'intent_type' => 'category', 'entity_type' => 'topic_entity' ],
    [ 'keyword' => 'anisyia', 'intent_type' => 'video', 'entity_type' => 'model' ],
    [ 'keyword' => 'anisyia livejasmin', 'intent_type' => 'model', 'entity_type' => 'model' ],
]);

$rows = $report['rows'];
$summary = $report['summary'];
$failures = [];

$contains = static function (string $keyword, string $reason) use ($rows): bool {
    foreach ($rows as $row) {
        if (($row['keyword'] ?? '') === $keyword && in_array($reason, (array) ($row['reason_codes'] ?? []), true)) {
            return true;
        }
    }
    return false;
};

if (!$contains('cheapest sex cam sites', 'misclassified_model_intent_candidate')) {
    $failures[] = 'expected cheapest sex cam sites to be flagged';
}
if (!$contains('creative live cam chat hd', 'misclassified_model_intent_candidate')) {
    $failures[] = 'expected creative live cam chat hd to be flagged';
}
if (!$contains('anisyia', 'person_name_in_category_pool')) {
    $failures[] = 'expected category anisyia to be flagged';
}
if (!$contains('anisyia', 'standalone_model_name_in_video_pool')) {
    $failures[] = 'expected video anisyia to be flagged';
}
foreach ($rows as $row) {
    if (($row['keyword'] ?? '') === 'anisyia livejasmin') {
        $failures[] = 'valid model keyword example should not be blocked';
    }
}
if (($summary['suspicious_model_rows'] ?? 0) !== 2) {
    $failures[] = 'expected two suspicious model rows';
}

$source = (string) file_get_contents(__DIR__ . '/../includes/keywords/class-keyword-candidate-classification-audit.php');
foreach ([ '$wpdb->update', '$wpdb->insert', '$wpdb->delete', 'update_post_meta(', 'wp_update_post(', 'wp_insert_post(', 'RankMathMapper', 'ajax_generate_now(', 'ContentEngine' ] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        $failures[] = 'unexpected write/generate call present: ' . $forbidden;
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "keyword candidate classification audit smoke checks passed\n";
