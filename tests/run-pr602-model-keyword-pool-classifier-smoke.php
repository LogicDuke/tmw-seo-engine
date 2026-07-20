<?php
/**
 * Smoke checks for PR 602 model keyword pool classifier and fallback packs.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

if (!class_exists('WP_Post')) {
    class WP_Post {
        public int $ID;
        public string $post_type;
        public string $post_title;
        public function __construct(int $id = 0, string $post_type = 'post', string $post_title = '') {
            $this->ID = $id;
            $this->post_type = $post_type;
            $this->post_title = $post_title;
        }
    }
}

function pr602_assert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function __(string $text, string $domain = ''): string { return $text; }
function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }
function sanitize_key($value): string { return strtolower(preg_replace('/[^a-z0-9_-]/', '', (string) $value)); }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes((string) $value); }
function wp_verify_nonce($nonce, string $action): bool { return $nonce === 'valid_nonce' && $action === 'tmwseo_save_model_fallback_pack'; }
function current_user_can(string $capability): bool { return $capability === 'manage_options'; }
function absint($value): int { return max(0, (int) $value); }
function current_time(string $type = 'mysql', bool $gmt = false): string { return '2026-05-30 00:00:00'; }
function wp_strip_all_tags($text): string { return strip_tags((string) $text); }
function wp_json_encode($value): string { return json_encode($value); }
function get_post($post_id) { return $GLOBALS['pr602_posts'][(int) $post_id] ?? null; }

final class Pr602JsonResponse extends RuntimeException {
    public bool $success;
    public array $payload;
    public int $status;
    public function __construct(bool $success, array $payload, int $status = 200) {
        parent::__construct('json_response');
        $this->success = $success;
        $this->payload = $payload;
        $this->status = $status;
    }
}
function wp_send_json_success($data = null, int $status_code = 200): void { throw new Pr602JsonResponse(true, [ 'success' => true, 'data' => $data ], $status_code); }
function wp_send_json_error($data = null, int $status_code = 400): void { throw new Pr602JsonResponse(false, [ 'success' => false, 'data' => $data ], $status_code); }

require_once dirname(__DIR__) . '/includes/keywords/class-model-keyword-pool-classifier.php';
require_once dirname(__DIR__) . '/includes/keywords/class-keyword-pool-candidate-repository.php';
require_once dirname(__DIR__) . '/includes/keywords/class-model-fallback-keyword-pack-builder.php';
require_once dirname(__DIR__) . '/includes/admin/class-admin-ajax-handlers.php';

use TMWSEO\Engine\Admin\AdminAjaxHandlers;
use TMWSEO\Engine\Keywords\KeywordPoolCandidateRepository;
use TMWSEO\Engine\Keywords\ModelFallbackKeywordPackBuilder;
use TMWSEO\Engine\Keywords\ModelKeywordPoolClassifier;

function pr602_check_class(ModelKeywordPoolClassifier $classifier, string $phrase, string $class, ?bool $standalone, array $context = []): void {
    $result = $classifier->classify($phrase, $context);
    pr602_assert($result['keyword_class'] === $class, $phrase . ' expected ' . $class . ' got ' . $result['keyword_class']);
    if (null !== $standalone) { pr602_assert($result['standalone_allowed'] === $standalone, $phrase . ' standalone mismatch'); }
}

final class Pr602SmokeWpdb {
    public string $prefix = 'wp602_';
    public int $insert_id = 1000;
    public array $rows = [];
    public array $inserts = [];
    public array $updates = [];
    public array $queries = [];
    private array $columns = [ 'id', 'keyword', 'canonical', 'intent', 'intent_type', 'entity_type', 'entity_id', 'status', 'volume', 'cpc', 'difficulty', 'competition', 'opportunity', 'seo_score', 'traffic_value', 'trend', 'ad_difficulty', 'difficulty_proxy', 'sources', 'notes', 'created_at', 'updated_at' ];

    public function esc_like(string $text): string { return addcslashes($text, '_%\\'); }
    public function prepare(string $sql, ...$args): string {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        $i = 0;
        return preg_replace_callback('/%[sdf]/', static function () use ($args, &$i) {
            $value = $args[$i++] ?? '';
            return is_string($value) ? "'" . addslashes($value) . "'" : (string) $value;
        }, $sql);
    }
    public function get_var(string $sql) {
        $this->queries[] = $sql;
        if (str_starts_with($sql, 'SHOW TABLES LIKE')) { return $this->prefix . 'tmw_keyword_candidates'; }
        return null;
    }
    public function get_results(string $sql, string $output = 'OBJECT'): array {
        $this->queries[] = $sql;
        if (str_starts_with($sql, 'SHOW COLUMNS FROM')) { return array_map(static fn($field) => [ 'Field' => $field ], $this->columns); }
        return [];
    }
    public function get_row(string $sql, string $output = 'OBJECT') {
        $this->queries[] = $sql;
        if (preg_match("/WHERE keyword = '([^']+)'/", $sql, $m)) {
            $keyword = stripslashes($m[1]);
            foreach ($this->rows as $row) { if (($row['keyword'] ?? '') === $keyword) { return $row; } }
        }
        if (preg_match("/WHERE \(keyword = '([^']+)'(?: OR canonical = '([^']+)')?\) AND entity_id = (\d+)/", $sql, $m)) {
            $keyword = stripslashes($m[1]);
            $canonical = isset($m[2]) && $m[2] !== '' ? stripslashes($m[2]) : $keyword;
            $entity_id = (int) $m[3];
            foreach ($this->rows as $row) {
                if ((int) ($row['entity_id'] ?? 0) === $entity_id && (($row['keyword'] ?? '') === $keyword || ($row['canonical'] ?? '') === $canonical)) { return $row; }
            }
        }
        return null;
    }
    public function insert(string $table, array $data, array $format = []) {
        $data['id'] = ++$this->insert_id;
        $this->rows[$data['id']] = $data;
        $this->inserts[] = [ 'table' => $table, 'data' => $data ];
        return 1;
    }
    public function update(string $table, array $data, array $where, array $format = [], array $where_format = []) {
        $id = (int) ($where['id'] ?? 0);
        $this->updates[] = [ 'table' => $table, 'data' => $data, 'where' => $where ];
        if (isset($this->rows[$id])) { $this->rows[$id] = array_merge($this->rows[$id], $data); }
        return 1;
    }
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

$wpdb = new Pr602SmokeWpdb();
$GLOBALS['wpdb'] = $wpdb;
$GLOBALS['pr602_posts'] = [
    4457 => new WP_Post(4457, 'model', 'Anisyia'),
    99 => new WP_Post(99, 'post', 'Not a model'),
];
$_POST = [
    'nonce' => 'valid_nonce',
    'entity_id' => 4457,
    'model_name' => 'Anisyia',
    'keywords' => [ 'Anisyia LiveJasmin Model', 'anisyia private chat', 'livejasmin', 'brunette', 'cam2cam', 'private chat', 'video' ],
];
try {
    AdminAjaxHandlers::ajax_save_model_fallback_pack();
    pr602_assert(false, 'AJAX save should terminate with JSON response.');
} catch (Pr602JsonResponse $response) {
    pr602_assert($response->success === true, 'Fallback save should return success.');
    $data = $response->payload['data'];
    pr602_assert($data['inserted'] === 2, 'Expected exactly two generated fallback inserts.');
    pr602_assert($data['skipped_not_generated'] === 4, 'Expected four non-generated direct phrases skipped.');
    pr602_assert($data['skipped_unsafe'] === 1, 'Expected video skipped as unsafe.');
}
pr602_assert(count($wpdb->inserts) === 2, 'Only allowed generated fallback phrases should be inserted.');
foreach ($wpdb->inserts as $insert) {
    $row = $insert['data'];
    pr602_assert(($row['status'] ?? '') === 'queued_for_review', 'Generated fallback rows must be queued_for_review.');
    $sources = json_decode((string) ($row['sources'] ?? '{}'), true) ?: [];
    pr602_assert(($sources['keyword_class'] ?? '') === ModelKeywordPoolClassifier::CLASS_GENERATED_LONGTAIL, 'Inserted fallback must be generated_longtail.');
    pr602_assert(($sources['source'] ?? '') === 'generated_model_fallback', 'Inserted fallback must preserve generated provenance.');
}

$before_invalid_entity_inserts = count($wpdb->inserts);
$_POST = [ 'nonce' => 'valid_nonce', 'entity_id' => 99, 'model_name' => 'Anisyia', 'keywords' => [ 'anisyia livejasmin model' ] ];
try {
    AdminAjaxHandlers::ajax_save_model_fallback_pack();
    pr602_assert(false, 'Invalid entity should return JSON error.');
} catch (Pr602JsonResponse $response) {
    pr602_assert($response->success === false && $response->status === 400, 'Invalid entity must be rejected before writes.');
}
pr602_assert(count($wpdb->inserts) === $before_invalid_entity_inserts, 'Invalid model entity must not write fallback rows.');

$repository = new KeywordPoolCandidateRepository();
$canonical_result = $repository->save([
    'keyword' => 'canonical normalization check',
    'canonical' => '  AnIsYia LIVEJASMIN MODEL!! ',
    'intent_type' => 'model',
    'entity_type' => 'model',
    'entity_id' => 4457,
    'status' => 'queued_for_review',
    'provenance' => [ 'source' => 'pr602_smoke' ],
]);
pr602_assert($canonical_result['action'] === 'inserted', 'Canonical normalization row should insert.');
$canonical_row = end($wpdb->inserts)['data'];
pr602_assert(($canonical_row['canonical'] ?? '') === 'anisyia livejasmin model', 'Canonical should be normalized on save.');

print "✓ PR 602 model keyword pool classifier and fallback pack smoke checks passed\n";
exit(0);
