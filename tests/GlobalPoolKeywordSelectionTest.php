<?php
/**
 * PR-683: GlobalPoolKeywordSelectionTest
 *
 * Verifies that the approved Global Model Pool rows are wired into the
 * Rank Math extra keyword chip path, and that unsafe deterministic fallback
 * chips are never emitted.
 *
 * Tests covered:
 *  1. Approved model-specific rows are used first (beat global pool).
 *  2. Global pool rows fill extras when no model-specific rows exist.
 *  3. queued_for_review global rows are excluded.
 *  4. rejected / blocked global rows are excluded (SQL status filter).
 *  5. CLASS_UNSAFE_STANDALONE global rows are excluded even if approved.
 *  6. "{model} livejasmin porn" is NOT emitted from deterministic fallback.
 *  7. "{model} porn" is NOT emitted from deterministic fallback.
 *  8. Rank Math CSV = 1 focus keyword + max 4 extras.
 *  9. Safe neutral fallback fills when fewer than 4 extras exist.
 * 10. RankMathMapper rebuild path still receives corrected extras.
 *
 * @package TMWSEO\Engine\Tests
 */

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__);
    }
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    require_once __DIR__ . '/bootstrap/wp-post-stub.php';

    // ── WordPress stubs ──────────────────────────────────────────────────────
    if (!class_exists('WP_Error')) {
        class WP_Error {
            private string $code;
            private string $message;
            public function __construct(string $code = '', string $message = '') {
                $this->code    = $code;
                $this->message = $message;
            }
            public function get_error_message(): string { return $this->message; }
        }
    }

    $GLOBALS['_tmw_meta']         = [];
    $GLOBALS['_tmw_posts']        = [];
    $GLOBALS['_tmw_titles']       = [];
    $GLOBALS['_tmw_terms']        = [];
    $GLOBALS['_tmw_test_options'] = [];

    if (!function_exists('wp_strip_all_tags'))    { function wp_strip_all_tags($s) { return strip_tags((string) $s); } }
    if (!function_exists('esc_html'))             { function esc_html($s) { return (string) $s; } }
    if (!function_exists('esc_url'))              { function esc_url($s) { return (string) $s; } }
    if (!function_exists('sanitize_key'))         { function sanitize_key($s) { $s = strtolower($s); $s = preg_replace('/[^a-z0-9_-]/', '', $s) ?? $s; return $s; } }
    if (!function_exists('sanitize_title'))       { function sanitize_title($s) { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string) $s), '-')); } }
    if (!function_exists('get_option'))           { function get_option($k, $d = false) { return $GLOBALS['_tmw_test_options'][$k] ?? $d; } }
    if (!function_exists('update_option'))        { function update_option($k, $v) { $GLOBALS['_tmw_test_options'][$k] = $v; return true; } }
    if (!function_exists('get_post_meta'))        { function get_post_meta($id, $k, $s = true) { return $GLOBALS['_tmw_meta'][$id][$k] ?? ''; } }
    if (!function_exists('update_post_meta'))     { function update_post_meta($id, $k, $v) { $GLOBALS['_tmw_meta'][$id][$k] = $v; return true; } }
    if (!function_exists('delete_post_meta'))     { function delete_post_meta($id, $k) { unset($GLOBALS['_tmw_meta'][$id][$k]); return true; } }
    if (!function_exists('get_post_field'))       { function get_post_field($f, $id) { return $GLOBALS['_tmw_posts'][$id]->$f ?? ''; } }
    if (!function_exists('get_the_title'))        { function get_the_title($id = 0) { return $GLOBALS['_tmw_titles'][$id] ?? ($GLOBALS['_tmw_posts'][$id]->post_title ?? ''); } }
    if (!function_exists('current_time'))         { function current_time($t) { return '2026-06-01 00:00:00'; } }
    if (!function_exists('get_object_taxonomies')) { function get_object_taxonomies($pt, $o = 'names') { return []; } }
    if (!function_exists('get_the_terms'))        { function get_the_terms($p, $t) { return []; } }
    if (!function_exists('is_wp_error'))          { function is_wp_error($x) { return $x instanceof \WP_Error; } }

    // ── TMWSEO stubs ─────────────────────────────────────────────────────────
    if (!class_exists('TMWSEO\\Engine\\Logs')) {
        eval('namespace TMWSEO\\Engine; class Logs { public static function info($c,$m,$d=[]){} public static function warn($c,$m,$d=[]){} public static function error($c,$m,$d=[]){} public static function debug($c,$m,$d=[]){} }');
    }
    // Stubs required by ModelKeywordPack::build().
    if (!class_exists('TMWSEO\\Engine\\Services\\DataForSEO')) {
        eval('namespace TMWSEO\\Engine\\Services; class DataForSEO { public static function is_configured(): bool { return false; } public static function keyword_suggestions(string $seed, int $limit = 80): array { return [\'ok\' => false, \'items\' => []]; } }');
    }
    if (!class_exists('TMWSEO\\Engine\\Platform\\PlatformProfiles')) {
        eval('namespace TMWSEO\\Engine\\Platform; class PlatformProfiles { public static function get_links(int $model_id): array { return [[\'platform\' => \'livejasmin\']]; } }');
    }
    if (!class_exists('TMWSEO\\Engine\\Keywords\\KeywordLibrary')) {
        eval('namespace TMWSEO\\Engine\\Keywords; class KeywordLibrary { public static function clean_keyword(string $keyword): string { $keyword = strtolower(strip_tags($keyword)); $keyword = preg_replace(\'/[^a-z0-9\\s-]+/\', \' \', $keyword) ?? $keyword; return trim(preg_replace(\'/\\s+/\', \' \', $keyword) ?? $keyword); } public static function score(string $keyword, array $context = []): int { return str_contains(strtolower($keyword), strtolower((string) ($context[\'name\'] ?? \'\'))) ? 80 : 40; } public static function pick_multi(array $categories, string $type, int $limit, string $seed, array $exclude = [], array $context = []): array { return $type === \'extra\' ? [\'generated fallback extra\', \'live chat schedule\'] : [\'generated fallback longtail\', \'how to join live cam chat\']; } public static function has_category(string $slug): bool { return true; } }');
    }

    // ── Autoload ──────────────────────────────────────────────────────────────
    require_once dirname(__DIR__) . '/includes/keywords/class-page-type-keyword-filter.php';
    require_once dirname(__DIR__) . '/includes/keywords/class-model-keyword-pool-classifier.php';
    require_once dirname(__DIR__) . '/includes/keywords/class-classified-model-keyword-provider.php';
    require_once dirname(__DIR__) . '/includes/keywords/class-model-keyword-pack.php';
    require_once dirname(__DIR__) . '/includes/content/class-rank-math-mapper.php';
}

namespace TMWSEO\Engine\Tests {

    use PHPUnit\Framework\TestCase;
    use TMWSEO\Engine\Content\RankMathMapper;
    use TMWSEO\Engine\Keywords\ClassifiedModelKeywordProvider;
    use TMWSEO\Engine\Keywords\ModelKeywordPack;
    use TMWSEO\Engine\Keywords\ModelKeywordPoolClassifier;

    // ─────────────────────────────────────────────────────────────────────────
    // Shared fake wpdb with global pool support
    // ─────────────────────────────────────────────────────────────────────────
    final class Pr683Wpdb {
        public string $prefix = 'wp_';
        /** @var array<int,array<string,mixed>> Model-specific rows (entity_id > 0). */
        public array $model_rows = [];
        /** @var array<int,array<string,mixed>> Global pool rows (entity_id = 0 / model_keyword_usage_scope). */
        public array $global_rows = [];
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
            }, $sql) ?? $sql;
        }

        /** Returns table name for SHOW TABLES, or scalar for COUNT/col-detect; routes results queries. */
        public function get_var(string $sql) {
            $this->queries[] = $sql;
            if (str_starts_with($sql, 'SHOW TABLES LIKE')) {
                return $this->prefix . 'tmw_keyword_candidates';
            }
            return null;
        }

        /** Returns column list for SHOW COLUMNS; routes SELECT queries to model or global rows. */
        public function get_col(string $sql, int $x = 0): array {
            $this->queries[] = $sql;
            if (str_contains($sql, 'SHOW COLUMNS')) {
                // Report all columns including the scope discriminator.
                return [
                    'id', 'keyword', 'intent_type', 'entity_type', 'entity_id',
                    'status', 'sources', 'model_keyword_usage_scope',
                    'target_type', 'target_name', 'target_slug',
                ];
            }
            return [];
        }

        public function get_results(string $sql, string $output = 'OBJECT'): array {
            $this->queries[] = $sql;
            if (!str_contains($sql, 'FROM ' . $this->prefix . 'tmw_keyword_candidates')) {
                return [];
            }

            // Route global pool queries — identified by model_keyword_usage_scope token.
            if (str_contains($sql, "model_keyword_usage_scope = 'global_model_pool'")) {
                $rows = array_values(array_filter(
                    $this->global_rows,
                    static fn(array $row): bool =>
                        ($row['intent_type'] ?? '') === 'model'
                        && ($row['status'] ?? '') === 'approved'
                        && ($row['model_keyword_usage_scope'] ?? '') === 'global_model_pool'
                ));
                usort($rows, static fn($a, $b): int => ((int) $a['id']) <=> ((int) $b['id']));
                return $rows;
            }

            // Route model-specific queries — identified by entity_id binding.
            $entity_id = 0;
            if (preg_match("/entity_id = '?(\d+)'?/", $sql, $m)) {
                $entity_id = (int) $m[1];
            }
            $rows = array_values(array_filter(
                $this->model_rows,
                static fn(array $row): bool =>
                    ($row['intent_type'] ?? '') === 'model'
                    && ($row['entity_type'] ?? '') === 'model'
                    && (int) ($row['entity_id'] ?? 0) === $entity_id
                    && ($row['status'] ?? '') === 'approved'
            ));
            usort($rows, static fn($a, $b): int => ((int) $a['id']) <=> ((int) $b['id']));
            return $rows;
        }

        public function update(string $table, array $data, array $where, array $format = [], array $where_format = []) {
            $this->updates[] = compact('table', 'data', 'where');
            return 1;
        }

        public function insert(string $table, array $data, array $format = []) {
            $this->inserts[] = compact('table', 'data');
            return 1;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Row factories
    // ─────────────────────────────────────────────────────────────────────────
    /** Build a model-specific row (entity_id > 0). */
    function model_row(
        int    $id,
        string $keyword,
        int    $entity_id,
        string $status,
        string $klass,
        string $usage,
        bool   $standalone,
        string $owner = 'TestModel',
        string $scope = 'model_specific'
    ): array {
        return [
            'id'                         => $id,
            'keyword'                    => $keyword,
            'intent_type'                => 'model',
            'entity_type'                => 'model',
            'entity_id'                  => $entity_id,
            'status'                     => $status,
            'model_keyword_usage_scope'  => $scope,
            'sources'                    => json_encode([
                'keyword_class'           => $klass,
                'suggested_usage'         => $usage,
                'standalone_allowed'      => $standalone,
                'model_keyword_owner'     => $owner,
                'model_keyword_usage_scope' => $scope,
            ]),
        ];
    }

    /** Build a global pool row (scope = global_model_pool). */
    function global_row(
        int    $id,
        string $keyword,
        string $status,
        string $klass = ModelKeywordPoolClassifier::CLASS_SUPPORTING_MODEL_TERM,
        string $usage = ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED,
        bool   $standalone = true
    ): array {
        return [
            'id'                        => $id,
            'keyword'                   => $keyword,
            'intent_type'               => 'model',
            'entity_type'               => 'model',
            'entity_id'                 => 0,
            'status'                    => $status,
            'model_keyword_usage_scope' => 'global_model_pool',
            'target_type'               => 'global',
            'target_name'               => 'Global Model Pool',
            'target_slug'               => 'global-model-pool',
            'sources'                   => json_encode([
                'keyword_class'             => $klass,
                'suggested_usage'           => $usage,
                'standalone_allowed'        => $standalone,
                'model_keyword_usage_scope' => 'global_model_pool',
            ]),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test class
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @covers \TMWSEO\Engine\Keywords\ClassifiedModelKeywordProvider
     * @covers \TMWSEO\Engine\Keywords\ModelKeywordPack
     * @covers \TMWSEO\Engine\Content\RankMathMapper
     */
    final class GlobalPoolKeywordSelectionTest extends TestCase {

        private Pr683Wpdb $wpdb;

        protected function setUp(): void {
            $GLOBALS['_tmw_meta']         = [];
            $GLOBALS['_tmw_posts']        = [];
            $GLOBALS['_tmw_titles']       = [];
            $GLOBALS['_tmw_terms']        = [];
            $GLOBALS['_tmw_test_options'] = [];

            $this->wpdb           = new Pr683Wpdb();
            $GLOBALS['wpdb']      = $this->wpdb;
        }

        private function makePost(int $id, string $title): \WP_Post {
            $post = new \WP_Post(['ID' => $id, 'post_type' => 'model', 'post_title' => $title]);
            $GLOBALS['_tmw_posts'][$id]  = $post;
            $GLOBALS['_tmw_titles'][$id] = $title;
            return $post;
        }

        // ─────────────────────────────────────────────────────────────────────
        // Test 1: Approved model-specific rows beat global pool rows.
        // ─────────────────────────────────────────────────────────────────────

        public function test_model_specific_rows_beat_global_pool(): void {
            $this->wpdb->model_rows = [
                model_row(1, 'abby murray livejasmin', 4432, 'approved',
                    ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD,
                    ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, true, 'Abby Murray'),
                model_row(2, 'livejasmin abby murray', 4432, 'approved',
                    ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD,
                    ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, true, 'Abby Murray'),
                model_row(3, 'abby murray live', 4432, 'approved',
                    ModelKeywordPoolClassifier::CLASS_PERSONAL_MODEL_KEYWORD,
                    ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, true, 'Abby Murray'),
            ];
            $this->wpdb->global_rows = [
                global_row(100, 'live cam profile', 'approved'),
                global_row(101, 'private chat webcam', 'approved'),
                global_row(102, 'webcam model live', 'approved'),
            ];

            $provider = new ClassifiedModelKeywordProvider();
            $fragment = $provider->build_for_model(4432, 'Abby Murray');

            // Model-specific extras must appear in extra_focus_candidates.
            $this->assertContains('abby murray livejasmin', $fragment['extra_focus_candidates'],
                'Model-specific "abby murray livejasmin" must be in extra_focus_candidates');
            // Global pool must be in global_pool_candidates, not mixed into model extras.
            $this->assertContains('live cam profile', $fragment['global_pool_candidates'],
                'Global pool row must appear in global_pool_candidates');
            $this->assertNotContains('live cam profile', $fragment['extra_focus_candidates'],
                'Global pool rows must NOT be mixed into model-specific extra_focus_candidates');

            $pack = ModelKeywordPack::build($this->makePost(4432, 'Abby Murray'));

            // Model-specific rows must appear first.
            $this->assertContains('abby murray livejasmin', $pack['rankmath_additional'],
                'Approved model-specific keyword must appear in rankmath_additional');
            $this->assertContains('livejasmin abby murray', $pack['rankmath_additional'],
                'Approved model-specific keyword must appear in rankmath_additional');

            // When 4 model-specific extras fill the cap, global pool does not expand it.
            $this->assertLessThanOrEqual(4, count($pack['rankmath_additional']),
                'rankmath_additional must be capped at 4 extras');

            $this->assertSame([], $this->wpdb->updates,
                'Build must not write to the database');
            $this->assertSame([], $this->wpdb->inserts,
                'Build must not write to the database');
        }

        // ─────────────────────────────────────────────────────────────────────
        // Test 2: Global pool rows fill extras when model-specific rows absent.
        // ─────────────────────────────────────────────────────────────────────

        public function test_global_pool_fills_when_no_model_specific_rows(): void {
            // No model-specific rows for this model.
            $this->wpdb->model_rows  = [];
            $this->wpdb->global_rows = [
                global_row(200, 'live cam profile', 'approved'),
                global_row(201, 'private chat webcam', 'approved'),
                global_row(202, 'webcam model live', 'approved'),
                global_row(203, 'cam girl profile', 'approved'),
            ];

            $provider = new ClassifiedModelKeywordProvider();
            $fragment = $provider->build_for_model(4432, 'Abby Murray');

            $this->assertCount(4, $fragment['global_pool_candidates'],
                'All 4 approved global pool rows must appear in global_pool_candidates');

            $pack = ModelKeywordPack::build($this->makePost(4432, 'Abby Murray'));

            $this->assertNotEmpty($pack['rankmath_additional'],
                'rankmath_additional must be non-empty when global pool rows exist');

            // The global pool terms must appear in rankmath_additional.
            $this->assertContains('live cam profile', $pack['rankmath_additional'],
                'Global pool row "live cam profile" must appear in rankmath_additional');
            $this->assertContains('private chat webcam', $pack['rankmath_additional'],
                'Global pool row "private chat webcam" must appear in rankmath_additional');

            $this->assertLessThanOrEqual(4, count($pack['rankmath_additional']),
                'rankmath_additional must be capped at 4 extras');

            // Abby Murray has no approved model rows — confirm no porn fallback chip.
            $this->assertNotContains('abby murray livejasmin porn', $pack['rankmath_additional'],
                '"abby murray livejasmin porn" must NOT come from deterministic fallback');
            $this->assertNotContains('abby murray porn', $pack['rankmath_additional'],
                '"abby murray porn" must NOT come from deterministic fallback');
        }

        // ─────────────────────────────────────────────────────────────────────
        // Test 3: queued_for_review global rows are excluded.
        // ─────────────────────────────────────────────────────────────────────

        public function test_queued_for_review_global_rows_excluded(): void {
            $this->wpdb->model_rows  = [];
            $this->wpdb->global_rows = [
                global_row(300, 'live cam profile', 'approved'),
                global_row(301, 'pending global term', 'queued_for_review'),
                global_row(302, 'another pending', 'queued_for_review'),
            ];

            $provider = new ClassifiedModelKeywordProvider();
            $fragment = $provider->build_for_model(9100, 'Queued Model');

            $this->assertContains('live cam profile', $fragment['global_pool_candidates'],
                'Approved global row must appear in global_pool_candidates');
            $this->assertNotContains('pending global term', $fragment['global_pool_candidates'],
                'queued_for_review global row must be excluded from global_pool_candidates');
            $this->assertNotContains('another pending', $fragment['global_pool_candidates'],
                'queued_for_review global row must be excluded from global_pool_candidates');
        }

        // ─────────────────────────────────────────────────────────────────────
        // Test 4: rejected / blocked global rows are excluded (SQL status filter).
        // ─────────────────────────────────────────────────────────────────────

        public function test_rejected_and_blocked_global_rows_excluded(): void {
            $this->wpdb->model_rows  = [];
            // The stub only returns rows where status='approved'.
            // Rows with rejected/blocked/ignored/archived must never be returned by the stub,
            // simulating the SQL WHERE status='approved' guard.
            $this->wpdb->global_rows = [
                global_row(400, 'approved global', 'approved'),
                // The following rows have non-approved status — stub excludes them from get_results.
                global_row(401, 'rejected global', 'rejected'),
                global_row(402, 'blocked global', 'blocked'),
                global_row(403, 'ignored global', 'ignored'),
                global_row(404, 'archived global', 'archived'),
            ];

            $provider = new ClassifiedModelKeywordProvider();
            $fragment = $provider->build_for_model(9200, 'Rejected Model');

            $this->assertContains('approved global', $fragment['global_pool_candidates'],
                'Approved global row must appear in global_pool_candidates');
            foreach (['rejected global', 'blocked global', 'ignored global', 'archived global'] as $bad) {
                $this->assertNotContains($bad, $fragment['global_pool_candidates'],
                    "Non-approved status row \"{$bad}\" must not appear in global_pool_candidates");
            }
        }

        // ─────────────────────────────────────────────────────────────────────
        // Test 5: CLASS_UNSAFE_STANDALONE global rows excluded even if approved.
        // ─────────────────────────────────────────────────────────────────────

        public function test_unsafe_standalone_global_rows_excluded_even_if_approved(): void {
            $this->wpdb->model_rows  = [];
            $this->wpdb->global_rows = [
                global_row(500, 'live cam profile', 'approved',
                    ModelKeywordPoolClassifier::CLASS_SUPPORTING_MODEL_TERM,
                    ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, true),
                global_row(501, 'porn', 'approved',
                    ModelKeywordPoolClassifier::CLASS_UNSAFE_STANDALONE,
                    ModelKeywordPoolClassifier::USAGE_MODIFIER_ONLY, false),
                global_row(502, 'chat', 'approved',
                    ModelKeywordPoolClassifier::CLASS_UNSAFE_STANDALONE,
                    ModelKeywordPoolClassifier::USAGE_MODIFIER_ONLY, false),
                global_row(503, 'cam girl live', 'approved',
                    ModelKeywordPoolClassifier::CLASS_SUPPORTING_MODEL_TERM,
                    ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, true),
            ];

            $provider = new ClassifiedModelKeywordProvider();
            $fragment = $provider->build_for_model(9300, 'Unsafe Global Model');

            $this->assertContains('live cam profile', $fragment['global_pool_candidates'],
                'Non-unsafe approved global row must appear in global_pool_candidates');
            $this->assertContains('cam girl live', $fragment['global_pool_candidates'],
                'Non-unsafe approved global row must appear in global_pool_candidates');
            $this->assertNotContains('porn', $fragment['global_pool_candidates'],
                'CLASS_UNSAFE_STANDALONE row "porn" must be excluded even if approved');
            $this->assertNotContains('chat', $fragment['global_pool_candidates'],
                'CLASS_UNSAFE_STANDALONE row "chat" must be excluded even if approved');
        }

        // ─────────────────────────────────────────────────────────────────────
        // Test 6: "{model} livejasmin porn" NOT emitted from deterministic fallback.
        // ─────────────────────────────────────────────────────────────────────

        public function test_livejasmin_porn_not_emitted_from_fallback(): void {
            // No approved rows at all — force pure fallback.
            $this->wpdb->model_rows  = [];
            $this->wpdb->global_rows = [];

            $pack = ModelKeywordPack::build($this->makePost(4432, 'Abby Murray'));

            $this->assertNotEmpty($pack['rankmath_additional'],
                'Fallback must still produce some Rank Math extras');
            foreach ($pack['rankmath_additional'] as $chip) {
                $this->assertStringNotContainsString('porn', strtolower((string) $chip),
                    "Deterministic fallback chip \"{$chip}\" must NOT contain \"porn\"");
            }
            $this->assertNotContains('abby murray livejasmin porn', $pack['rankmath_additional'],
                '"abby murray livejasmin porn" must never come from deterministic fallback');
        }

        // ─────────────────────────────────────────────────────────────────────
        // Test 7: "{model} porn" NOT emitted from deterministic fallback.
        // ─────────────────────────────────────────────────────────────────────

        public function test_plain_porn_not_emitted_from_fallback(): void {
            $this->wpdb->model_rows  = [];
            $this->wpdb->global_rows = [];

            $pack = ModelKeywordPack::build($this->makePost(4432, 'Abby Murray'));

            $this->assertNotContains('abby murray porn', $pack['rankmath_additional'],
                '"abby murray porn" must never come from deterministic fallback');
            foreach ($pack['rankmath_additional'] as $chip) {
                $chip_lc = strtolower((string) $chip);
                $this->assertFalse(
                    $chip_lc === 'abby murray porn' || $chip_lc === 'abby murray livejasmin porn',
                    "Unsafe chip \"{$chip}\" must not appear in deterministic fallback"
                );
            }
        }

        // ─────────────────────────────────────────────────────────────────────
        // Test 8: Rank Math CSV = 1 focus + max 4 extras.
        // ─────────────────────────────────────────────────────────────────────

        public function test_rank_math_csv_capped_at_one_focus_plus_four_extras(): void {
            // Provide 6 global pool rows — only 4 may end up as Rank Math extras.
            $this->wpdb->model_rows  = [];
            $this->wpdb->global_rows = [
                global_row(600, 'live cam profile', 'approved'),
                global_row(601, 'private chat webcam', 'approved'),
                global_row(602, 'webcam model live', 'approved'),
                global_row(603, 'cam girl profile', 'approved'),
                global_row(604, 'live sex cam', 'approved'),
                global_row(605, 'adult webcam', 'approved'),
            ];

            $pack = ModelKeywordPack::build($this->makePost(4432, 'Abby Murray'));

            $this->assertLessThanOrEqual(4, count($pack['rankmath_additional']),
                'rankmath_additional must never exceed 4 extras');

            // Simulate RankMathMapper CSV.
            $focus  = $pack['primary'];
            $extras = $pack['rankmath_additional'];
            $csv    = implode(',', array_merge([$focus], $extras));
            $chips  = explode(',', $csv);

            $this->assertLessThanOrEqual(5, count($chips),
                'Rank Math CSV must contain at most 5 chips total (1 focus + 4 extras)');
            $this->assertSame('Abby Murray', $chips[0],
                'First chip must be the model name focus keyword');
        }

        // ─────────────────────────────────────────────────────────────────────
        // Test 9: Safe neutral fallback fills when fewer than 4 extras exist.
        // ─────────────────────────────────────────────────────────────────────

        public function test_safe_fallback_fills_sparse_extras(): void {
            // Only 1 global pool row — fallback must pad to 4 with safe chips.
            $this->wpdb->model_rows  = [];
            $this->wpdb->global_rows = [
                global_row(700, 'live cam profile', 'approved'),
            ];

            $pack = ModelKeywordPack::build($this->makePost(4432, 'Abby Murray'));

            $this->assertGreaterThanOrEqual(1, count($pack['rankmath_additional']),
                'At least 1 extra must be present (global pool row)');
            $this->assertLessThanOrEqual(4, count($pack['rankmath_additional']),
                'rankmath_additional must be capped at 4 extras');

            // None of the fallback chips may contain unsafe terms.
            foreach ($pack['rankmath_additional'] as $chip) {
                $chip_lc = strtolower((string) $chip);
                $this->assertStringNotContainsString('porn', $chip_lc,
                    "Safe fallback chip \"{$chip}\" must not contain \"porn\"");
                $this->assertStringNotContainsString(' porn', $chip_lc,
                    "Safe fallback chip \"{$chip}\" must not contain \" porn\"");
            }

            // At least one of the safe fallback terms must be present when the pool is sparse.
            $all_lc = array_map('strtolower', array_map('strval', $pack['rankmath_additional']));
            $safe_patterns = ['profile', 'live cam', 'private chat', 'webcam', 'cam profile'];
            $has_safe = false;
            foreach ($all_lc as $chip) {
                foreach ($safe_patterns as $pattern) {
                    if (str_contains($chip, $pattern)) {
                        $has_safe = true;
                        break 2;
                    }
                }
            }
            $this->assertTrue($has_safe,
                'Safe fallback must contribute at least one neutral chip when global pool is sparse');
        }

        // ─────────────────────────────────────────────────────────────────────
        // Test 10: RankMathMapper rebuild path still receives corrected extras.
        // ─────────────────────────────────────────────────────────────────────

        public function test_rank_math_mapper_receives_corrected_extras(): void {
            $this->wpdb->model_rows  = [];
            $this->wpdb->global_rows = [
                global_row(800, 'abby murray live cam', 'approved'),
                global_row(801, 'abby murray webcam', 'approved'),
            ];

            $post = $this->makePost(4432, 'Abby Murray');
            $pack = ModelKeywordPack::build($post);

            // Simulate what RankMathMapper would do.
            RankMathMapper::sync_to_rank_math((int) $post->ID, $pack, false);

            $saved = (string) get_post_meta((int) $post->ID, 'rank_math_focus_keyword', true);
            $this->assertNotEmpty($saved,
                'RankMathMapper must write rank_math_focus_keyword');

            $chips = array_map('trim', explode(',', $saved));
            $this->assertSame('Abby Murray', $chips[0],
                'First chip must remain the model name primary keyword');

            // Global pool rows must be in the saved CSV.
            $this->assertContains('abby murray live cam', $chips,
                'Global pool row "abby murray live cam" must appear in saved rank_math_focus_keyword');
            $this->assertContains('abby murray webcam', $chips,
                'Global pool row "abby murray webcam" must appear in saved rank_math_focus_keyword');

            // No unsafe chips allowed in the saved CSV.
            foreach ($chips as $chip) {
                $this->assertStringNotContainsString('porn', strtolower($chip),
                    "Saved chip \"{$chip}\" must not contain \"porn\"");
            }

            $this->assertLessThanOrEqual(5, count($chips),
                'Saved rank_math_focus_keyword must contain at most 5 chips');

            $this->assertSame([], $this->wpdb->updates,
                'Test must not write to the database via $wpdb');
            $this->assertSame([], $this->wpdb->inserts,
                'Test must not write to the database via $wpdb');
        }
    }
}
