<?php
/**
 * Smoke checks for PR 604 classified model keywords in manual Generate packs.
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Services { final class DataForSEO { public static function is_configured(): bool { return false; } public static function keyword_suggestions(string $seed, int $limit = 80): array { return [ 'ok' => false, 'items' => [] ]; } } }
namespace TMWSEO\Engine\Platform { final class PlatformProfiles { public static function get_links(int $model_id): array { return [ [ 'platform' => 'livejasmin' ] ]; } } }
namespace TMWSEO\Engine { final class Logs { public static function warn(string $channel, string $message, array $context = []): void {} } }
namespace TMWSEO\Engine\Keywords {
    final class KeywordLibrary {
        public static function clean_keyword(string $keyword): string { $keyword = strtolower(strip_tags($keyword)); $keyword = preg_replace('/[^a-z0-9\s-]+/', ' ', $keyword) ?? $keyword; return trim(preg_replace('/\s+/', ' ', $keyword) ?? $keyword); }
        public static function score(string $keyword, array $context = []): int { return str_contains(strtolower($keyword), strtolower((string) ($context['name'] ?? ''))) ? 80 : 40; }
        public static function pick_multi(array $categories, string $type, int $limit, string $seed, array $exclude = [], array $context = []): array { return $type === 'extra' ? [ 'generated fallback extra', 'live chat schedule' ] : [ 'generated fallback longtail phrase', 'how to join live cam chat' ]; }
        public static function has_category(string $slug): bool { return true; }
    }
    final class PageTypeKeywordFilter { public static function filter_for_model_page(array $keywords): array { return array_values(array_filter(array_map('strval', $keywords), 'strlen')); } public static function filter(array $keywords, string $page_type): array { return $keywords; } }
}
namespace {
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

if (!class_exists('WP_Post')) {
    class WP_Post { public int $ID; public string $post_type; public string $post_title; public function __construct(int $id = 0, string $post_type = 'post', string $post_title = '') { $this->ID = $id; $this->post_type = $post_type; $this->post_title = $post_title; } }
}
if (!class_exists('WP_Term')) { class WP_Term { public string $slug = ''; } }

function pr604_assert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function wp_strip_all_tags(string $text): string { return strip_tags($text); }
function sanitize_key(string $key): string { $key = strtolower($key); $key = preg_replace('/[^a-z0-9_-]/', '', $key) ?? $key; return $key; }
function get_option(string $name, $default = false) { return $default; }
function get_post_meta(int $post_id, string $key = '', bool $single = false) { return ''; }
function get_object_taxonomies(string $post_type): array { return []; }
function get_the_terms($post, string $taxonomy) { return false; }

require_once dirname(__DIR__) . '/includes/keywords/class-model-keyword-pool-classifier.php';
require_once dirname(__DIR__) . '/includes/keywords/class-classified-model-keyword-provider.php';
require_once dirname(__DIR__) . '/includes/keywords/class-model-keyword-pack.php';

use TMWSEO\Engine\Keywords\ClassifiedModelKeywordProvider;
use TMWSEO\Engine\Keywords\ModelKeywordPack;
use TMWSEO\Engine\Keywords\ModelKeywordPoolClassifier;

final class Pr604SmokeWpdb {
    public string $prefix = 'wp_';
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];
    public array $queries = [];
    public array $updates = [];
    public array $inserts = [];

    public function esc_like(string $text): string { return addcslashes($text, '_%\\'); }
    public function prepare(string $sql, ...$args): string {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        $i = 0;
        return preg_replace_callback('/%[sdf]/', static function () use ($args, &$i) {
            $value = $args[$i++] ?? '';
            return is_string($value) ? "'" . addslashes($value) . "'" : (string) $value;
        }, $sql);
    }
    public function get_var(string $sql) { $this->queries[] = $sql; return str_starts_with($sql, 'SHOW TABLES LIKE') ? $this->prefix . 'tmw_keyword_candidates' : null; }
    public function get_col(string $sql, int $column = 0): array {
        $this->queries[] = $sql;
        return [ 'id', 'keyword', 'intent_type', 'entity_type', 'entity_id', 'status', 'sources', 'target_type', 'target_id', 'target_name', 'target_slug', 'model_keyword_usage_scope' ];
    }
    public function get_results(string $sql, string $output = 'OBJECT'): array {
        $this->queries[] = $sql;
        if (!str_contains($sql, 'FROM ' . $this->prefix . 'tmw_keyword_candidates')) { return []; }
        $entity_id = 0;
        if (preg_match('/entity_id = (\d+)/', $sql, $m)) { $entity_id = (int) $m[1]; }
        $rows = array_values(array_filter($this->rows, static function (array $row) use ($entity_id): bool {
            return ($row['intent_type'] ?? '') === 'model'
                && ($row['entity_type'] ?? '') === 'model'
                && (int) ($row['entity_id'] ?? 0) === $entity_id
                && ($row['status'] ?? '') === 'approved';
        }));
        usort($rows, static fn($a, $b): int => ((int) $a['id']) <=> ((int) $b['id']));
        return $rows;
    }
    public function update(string $table, array $data, array $where, array $format = [], array $where_format = []) { $this->updates[] = compact('table', 'data', 'where'); return 1; }
    public function insert(string $table, array $data, array $format = []) { $this->inserts[] = compact('table', 'data'); return 1; }
}

$meta = static function (string $class, string $usage, bool $standalone, string $owner = 'Anisyia', string $scope = 'model_bio_only'): array {
    return [
        'keyword_class' => $class,
        'suggested_usage' => $usage,
        'standalone_allowed' => $standalone,
        'keyword_class_reason_codes' => [ 'smoke' ],
        'keyword_class_confidence' => 'high',
        'model_keyword_owner' => $owner,
        'model_keyword_usage_scope' => $scope,
    ];
};
$row = static function (int $id, string $keyword, int $entity_id, string $status, array $sources): array {
    return [ 'id' => $id, 'keyword' => $keyword, 'intent_type' => 'model', 'entity_type' => 'model', 'entity_id' => $entity_id, 'status' => $status, 'sources' => json_encode($sources) ];
};

$wpdb = new Pr604SmokeWpdb();
$GLOBALS['wpdb'] = $wpdb;
$wpdb->rows = [
    0 => $row(0, 'live', 4457, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_SUPPORTING_MODEL_TERM, ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, true)),
    1 => $row(1, 'anisyia', 4457, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_PRIMARY_FOCUS_ALLOWED, true)),
    2 => $row(2, 'anisyia livejasmin', 4457, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_PRIMARY_FOCUS_ALLOWED, true)),
    3 => $row(3, 'livejasmin anisyia', 4457, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, true)),
    12 => $row(12, 'anisyia live', 4457, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_UNKNOWN_REVIEW, ModelKeywordPoolClassifier::USAGE_REVIEW_REQUIRED, false)),
    13 => $row(13, 'anisyia livejasmin porn', 4457, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_PRIMARY_FOCUS_ALLOWED, true)),
    14 => $row(14, 'anisyia porn', 4457, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_UNKNOWN_REVIEW, ModelKeywordPoolClassifier::USAGE_REVIEW_REQUIRED, false)),
    4 => $row(4, 'video', 4457, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_UNSAFE_STANDALONE, ModelKeywordPoolClassifier::USAGE_MODIFIER_ONLY, false)),
    5 => $row(5, 'chat', 4457, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_UNSAFE_STANDALONE, ModelKeywordPoolClassifier::USAGE_MODIFIER_ONLY, false)),
    6 => $row(6, 'mystery phrase', 4457, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_UNKNOWN_REVIEW, ModelKeywordPoolClassifier::USAGE_REVIEW_REQUIRED, false)),
    7 => $row(7, 'wrong entity anisyia', 9999, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_PRIMARY_FOCUS_ALLOWED, true)),
    8 => $row(8, 'othermodel livejasmin', 4457, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_PRIMARY_FOCUS_ALLOWED, true, 'OtherModel')),
    9 => $row(9, 'anisyia pending', 4457, 'queued_for_review', $meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_PRIMARY_FOCUS_ALLOWED, true)),
    10 => $row(10, 'livejasmin video chat', 4457, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_PLATFORM_INTENT_TERM, ModelKeywordPoolClassifier::USAGE_BODY_SEMANTIC_ONLY, false)),
    11 => $row(11, 'brunette', 4457, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_ATTRIBUTE_TERM, ModelKeywordPoolClassifier::USAGE_MODIFIER_ONLY, false)),
];

$provider = new ClassifiedModelKeywordProvider();
$fragment = $provider->build_for_model(4457, 'Anisyia');
pr604_assert($fragment['primary_candidates'] === [ 'anisyia', 'anisyia livejasmin', 'anisyia livejasmin porn' ], 'Provider should return Anisyia approved personal primary rows in order.');
pr604_assert(array_slice($fragment['extra_focus_candidates'], 0, 5) === [ 'anisyia', 'anisyia livejasmin', 'livejasmin anisyia', 'anisyia live', 'anisyia livejasmin porn' ], 'Provider should return approved personal extra focus rows before conditional supporting terms.');
pr604_assert(in_array('live', $fragment['extra_focus_candidates'], true), 'Provider should allow approved standalone live as a supporting extra.');
pr604_assert(array_search('live', $fragment['extra_focus_candidates'], true) > array_search('anisyia livejasmin porn', $fragment['extra_focus_candidates'], true), 'Standalone live should stay behind stronger model-profile keywords.');
foreach ([ 'anisyia porn', 'video', 'chat', 'mystery phrase', 'othermodel livejasmin' ] as $excluded) { pr604_assert(in_array($excluded, $fragment['excluded_candidates'], true), 'Provider should exclude ' . $excluded . '.'); }
foreach ([ 'wrong entity anisyia', 'anisyia pending', 'anisyia porn', 'video', 'chat', 'mystery phrase', 'othermodel livejasmin' ] as $bad) { pr604_assert(!in_array($bad, $fragment['primary_candidates'], true) && !in_array($bad, $fragment['extra_focus_candidates'], true), 'Provider should not include bad focus candidate ' . $bad . '.'); }

$before_queries = count($wpdb->queries);
$pack = ModelKeywordPack::build(new WP_Post(4457, 'model', 'Anisyia'));
pr604_assert(array_slice($pack['additional'], 0, 3) === [ 'anisyia', 'anisyia livejasmin', 'livejasmin anisyia' ], 'ModelKeywordPack additional should put approved personal rows before generated fallbacks.');
pr604_assert($pack['rankmath_additional'] === [ 'anisyia livejasmin', 'livejasmin anisyia', 'anisyia live', 'anisyia livejasmin porn' ], 'ModelKeywordPack rankmath_additional should keep four approved non-primary Rank Math chips before fallbacks.');
pr604_assert(count($pack['rankmath_additional']) <= 4, 'ModelKeywordPack rankmath_additional should be capped to four extras.');
pr604_assert(!in_array('anisyia', $pack['rankmath_additional'], true), 'ModelKeywordPack rankmath_additional must not include the primary focus keyword.');
foreach ([ 'anisyia porn', 'video', 'chat', 'mystery phrase' ] as $bad) { pr604_assert(!in_array($bad, $pack['rankmath_additional'], true) && !in_array($bad, $pack['additional'], true), 'Unsafe/review terms should not appear in focus extras: ' . $bad . '.'); }
pr604_assert(implode(',', array_merge([ 'Anisyia' ], $pack['rankmath_additional'])) === 'Anisyia,anisyia livejasmin,livejasmin anisyia,anisyia live,anisyia livejasmin porn', 'Rank Math CSV preview should include approved live-intent review keyword and exclude unsafe porn fallback.');
pr604_assert(count($wpdb->queries) > $before_queries, 'Pack build should read the provider rows.');
pr604_assert($wpdb->updates === [] && $wpdb->inserts === [], 'Provider and pack build smoke must not perform database writes.');


$wpdb->rows = [
    21 => $row(21, 'blocked fallback', 8891, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_PRIMARY_FOCUS_ALLOWED, true, 'Blocked Fallback')),
    22 => $row(22, 'blocked fallback livejasmin', 8891, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, true, 'Blocked Fallback')),
    23 => $row(23, 'livejasmin blocked fallback', 8891, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, true, 'Blocked Fallback')),
    24 => $row(24, 'blocked fallback private live chat', 8891, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_UNKNOWN_REVIEW, ModelKeywordPoolClassifier::USAGE_REVIEW_REQUIRED, false, 'Blocked Fallback')),
];
$blocked_fallback_pack = ModelKeywordPack::build(new WP_Post(8891, 'model', 'Blocked Fallback'));
pr604_assert(!in_array('blocked fallback private live chat', $blocked_fallback_pack['rankmath_additional'], true), 'Final fallback merge must not re-add classified excluded fallback chips.');
pr604_assert(count($blocked_fallback_pack['rankmath_additional']) <= 4, 'Filtered fallback pack Rank Math chips must stay capped to four extras.');
pr604_assert(!in_array('blocked fallback', $blocked_fallback_pack['rankmath_additional'], true), 'Filtered fallback pack Rank Math chips must not include the primary focus keyword.');

$wpdb->rows = [
    12 => $row(12, 'anisyia livejasmin', 6666, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_PRIMARY_FOCUS_ALLOWED, true)),
];
$name_context_pack = ModelKeywordPack::build(new WP_Post(6666, 'model', 'Anisyia'));
pr604_assert($name_context_pack['primary'] === 'Anisyia', 'SEO primary should remain the model name when no approved exact-name primary row exists.');
pr604_assert(in_array('anisyia livejasmin', $name_context_pack['rankmath_additional'], true), 'Approved classified personal phrase should still lead Rank Math extras.');
pr604_assert(count(array_filter($name_context_pack['rankmath_additional'], static fn($kw): bool => str_starts_with((string) $kw, 'anisyia '))) >= 1, 'Generated Rank Math chips should still use Anisyia as the model-name context.');

$wpdb->rows = [
    13 => $row(13, 'webcam model', 7777, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_CORE_MODEL_TERM, ModelKeywordPoolClassifier::USAGE_PRIMARY_FOCUS_ALLOWED, true, 'Core Only')),
];
$core_context_pack = ModelKeywordPack::build(new WP_Post(7777, 'model', 'Core Only'));
pr604_assert($core_context_pack['primary'] === 'Core Only', 'SEO primary should remain the model name instead of falling back to a core model term.');
pr604_assert(count(array_filter($core_context_pack['rankmath_additional'], static fn($kw): bool => str_starts_with((string) $kw, 'core only '))) >= 1, 'Generated Rank Math chips should use the title when the SEO primary is a core term.');
pr604_assert(count(array_filter($core_context_pack['rankmath_additional'], static fn($kw): bool => str_starts_with((string) $kw, 'webcam model '))) === 0, 'Generated Rank Math chips must not use a core term as the model-name context.');

$wpdb->rows = [ 20 => $row(20, 'only pending', 5555, 'queued_for_review', $meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_PRIMARY_FOCUS_ALLOWED, true)) ];
$fallback_pack = ModelKeywordPack::build(new WP_Post(5555, 'model', 'Fallback Model'));
pr604_assert($fallback_pack['primary'] === 'Fallback Model', 'Existing post-title fallback should remain when no approved classified rows exist.');
pr604_assert(!empty($fallback_pack['additional']) && !empty($fallback_pack['rankmath_additional']), 'Existing generated fallback extras should still work without approved classified rows.');
pr604_assert(!in_array('fallback model livejasmin porn', $fallback_pack['rankmath_additional'], true), 'Safe deterministic fallback must not emit livejasmin porn.');
pr604_assert($fallback_pack['rankmath_additional'] === [ 'fallback model profile', 'fallback model livejasmin profile', 'fallback model live cam', 'fallback model private chat' ], 'Safe deterministic fallback should use safe model chips only.');
pr604_assert($wpdb->updates === [] && $wpdb->inserts === [], 'Fallback pack build must not perform database writes.');

$rankmath_chip_method = new ReflectionMethod(ModelKeywordPack::class, 'build_rankmath_chips');
$rankmath_chip_method->setAccessible(true);
$streamate_fallback_chips = $rankmath_chip_method->invoke(null, 'Streamate Fallback', [ 'streamate' ]);
foreach ($streamate_fallback_chips as $chip) { pr604_assert(stripos((string) $chip, 'streamate') === false, 'Deterministic fallback must not emit denied platform term streamate: ' . (string) $chip); }
pr604_assert(count($streamate_fallback_chips) <= 4, 'Denied-platform fallback chips must stay capped to four extras.');

$global_row = static function (int $id, string $keyword, string $status, array $sources, array $extra = []) use ($row): array {
    return array_merge($row($id, $keyword, 0, $status, $sources), [
        'target_type' => 'global',
        'target_id' => null,
        'target_name' => 'Global Model Pool',
        'target_slug' => '',
        'model_keyword_usage_scope' => 'global_model_pool',
    ], $extra);
};
$wpdb->rows = [
    31 => $global_row(31, 'fallback model livejasmin profile', 'approved', array_merge($meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, true, '', 'global_model_pool'), [ 'platform_key' => 'livejasmin' ])),
    32 => $global_row(32, 'global model live cam', 'approved', $meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, true, '', 'global_model_pool')),
    33 => $global_row(33, 'global model private chat', 'approved', $meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, true, '', 'global_model_pool')),
    34 => $global_row(34, 'global model webcam profile', 'approved', $meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, true, '', 'global_model_pool')),
    35 => $global_row(35, 'queued global model', 'queued_for_review', $meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, true, '', 'global_model_pool')),
    36 => $global_row(36, 'rejected global model', 'rejected', $meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, true, '', 'global_model_pool')),
    37 => $global_row(37, 'blocked global model', 'blocked', $meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, true, '', 'global_model_pool')),
    38 => $global_row(38, 'collision global model', 'approved', array_merge($meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, true, '', 'global_model_pool'), [ 'blocked_collision' => true ])),
];
$global_pack = ModelKeywordPack::build(new WP_Post(5555, 'model', 'Fallback Model'));
pr604_assert($global_pack['rankmath_additional'] === [ 'fallback model livejasmin profile', 'global model live cam', 'global model private chat', 'global model webcam profile' ], 'Approved global model pool rows should fill Rank Math extras before deterministic fallback.');
foreach ([ 'queued global model', 'rejected global model', 'blocked global model', 'collision global model', 'fallback model livejasmin porn', 'fallback model profile' ] as $bad) { pr604_assert(!in_array($bad, $global_pack['rankmath_additional'], true), 'Global pool selection should exclude disallowed/fallback keyword: ' . $bad . '.'); }
pr604_assert(count($global_pack['rankmath_additional']) === 4, 'Global pool Rank Math extras should be capped at four extras.');

$wpdb->rows = [
    41 => $row(41, 'specific model live cam', 6001, 'approved', $meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, true, 'Specific Model')),
    42 => $global_row(42, 'global model live cam', 'approved', $meta(ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD, ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, true, '', 'global_model_pool')),
];
$specific_pack = ModelKeywordPack::build(new WP_Post(6001, 'model', 'Specific Model'));
pr604_assert($specific_pack['rankmath_additional'][0] === 'specific model live cam', 'Model-specific approved rows should win before approved global rows.');
pr604_assert($specific_pack['rankmath_additional'][1] === 'global model live cam', 'Approved global rows should follow model-specific rows before fallback.');

echo "✓ PR 604 classified model keywords for Generate smoke checks passed\n";
}
