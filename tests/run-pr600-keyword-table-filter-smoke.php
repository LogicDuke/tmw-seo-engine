<?php
/**
 * Smoke checks for PR 600 keyword table model metadata filters.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

if (!class_exists('WP_List_Table')) {
    class WP_List_Table {
        public array $items = [];
        public array $_column_headers = [];
        public function __construct(array $args = []) {}
        public function get_items_per_page(string $option, int $default = 20): int { return $default; }
        public function set_pagination_args(array $args): void {}
        public function current_action() { return false; }
        public function row_actions(array $actions, bool $always_visible = false): string { return ''; }
    }
}

if (!function_exists('sanitize_key')) { function sanitize_key($key): string { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', (string) $key)); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($str): string { return trim(strip_tags((string) $str)); } }
if (!function_exists('wp_unslash')) { function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($value) { return json_encode($value); } }
if (!function_exists('__')) { function __($text, $domain = '') { return $text; } }
if (!function_exists('esc_html__')) { function esc_html__($text, $domain = '') { return $text; } }
if (!function_exists('esc_attr')) { function esc_attr($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_html')) { function esc_html($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_url')) { function esc_url($url): string { return (string) $url; } }
if (!function_exists('admin_url')) { function admin_url($path = ''): string { return 'http://example.test/wp-admin/' . ltrim((string) $path, '/'); } }
if (!function_exists('add_query_arg')) { function add_query_arg($args, $url = ''): string { return (string) $url . '?' . http_build_query((array) $args); } }
if (!function_exists('wp_nonce_url')) { function wp_nonce_url($url, $action = -1): string { return (string) $url; } }
if (!function_exists('check_admin_referer')) { function check_admin_referer($action = -1): int { return 1; } }

final class Pr600KeywordTableFilterSmokeWpdb {
    public string $prefix = 'wp_';
    public array $prepare_args = [];
    /** @var array<int,array<string,mixed>> */
    private array $rows;

    public function __construct() {
        $this->rows = [
            [
                'id' => 1,
                'keyword' => 'anisyia',
                'volume' => 12100,
                'difficulty' => 0,
                'intent' => 'model',
                'intent_type' => 'model',
                'entity_type' => 'model',
                'entity_id' => 900,
                'status' => 'approved',
                'sources' => '{"personal_model_keyword_csv":true,"model_keyword_primary_candidate":"yes","model_keyword_usage_scope":"model_bio_only"}',
                'notes' => '',
                'created_at' => '2026-05-29 00:00:00',
            ],
            [
                'id' => 2,
                'keyword' => 'random yes row',
                'volume' => 1,
                'difficulty' => 0,
                'intent' => 'model',
                'intent_type' => 'model',
                'entity_type' => 'model',
                'entity_id' => 0,
                'status' => 'queued_for_review',
                'sources' => '{"model_keyword_primary_candidate":"no","other_field":"yes","model_keyword_usage_scope":"model_bio_only"}',
                'notes' => '',
                'created_at' => '2026-05-29 00:00:00',
            ],
        ];
    }

    public function esc_like($text): string { return addcslashes((string) $text, '_%\\'); }

    public function prepare(string $sql, ...$args): string {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        $this->prepare_args[] = $args;
        $index = 0;
        return (string) preg_replace_callback('/%[sdf]/', static function () use (&$index, $args) {
            $value = $args[$index++] ?? '';
            return is_string($value) ? "'" . addslashes($value) . "'" : (string) $value;
        }, $sql);
    }

    public function get_results(string $sql, string $output = 'OBJECT'): array {
        if (str_starts_with($sql, 'SHOW COLUMNS FROM')) {
            return array_map(static fn($field) => [ 'Field' => $field ], [
                'id', 'keyword', 'volume', 'difficulty', 'intent', 'intent_type', 'entity_type', 'entity_id', 'status', 'sources', 'notes', 'created_at',
            ]);
        }
        return $this->filter_rows();
    }

    public function get_var(string $sql) { return count($this->filter_rows()); }

    /** @return array<int,array<string,mixed>> */
    private function filter_rows(): array {
        $filter = (string) ($_GET['model_keyword_filter'] ?? '');
        if ('personal_model_csv' === $filter) {
            return [ $this->rows[0] ];
        }
        if ('primary_model_bio' === $filter) {
            return [ $this->rows[0] ];
        }
        if ('unlinked_model' === $filter) {
            return [ $this->rows[1] ];
        }
        return $this->rows;
    }
}

require_once dirname(__DIR__) . '/includes/admin/tables/class-keywords-table.php';

use TMWSEO\Engine\Admin\Tables\KeywordsTable;

function pr600_prepare_args_flatten(array $calls): array {
    $flat = [];
    foreach ($calls as $args) { foreach ($args as $arg) { $flat[] = $arg; } }
    return $flat;
}

$GLOBALS['wpdb'] = new Pr600KeywordTableFilterSmokeWpdb();
$_GET = [ 'model_keyword_filter' => 'personal_model_csv', 'intent_type' => 'model' ];
$table = new KeywordsTable(null, 'candidates');
$table->prepare_items();
if (count($table->items) !== 1 || $table->items[0]['keyword'] !== 'anisyia') {
    throw new RuntimeException('Personal Model CSV filter did not return the expected row.');
}
$args = pr600_prepare_args_flatten($GLOBALS['wpdb']->prepare_args);
if (!in_array('%personal\_model\_keyword\_csv%', $args, true)) {
    throw new RuntimeException('Personal Model CSV LIKE pattern was not escaped.');
}

$GLOBALS['wpdb'] = new Pr600KeywordTableFilterSmokeWpdb();
$_GET = [ 'model_keyword_filter' => 'primary_model_bio', 'intent_type' => 'model' ];
$table = new KeywordsTable(null, 'candidates');
$table->prepare_items();
if (count($table->items) !== 1 || $table->items[0]['keyword'] !== 'anisyia') {
    throw new RuntimeException('Primary Model Bio filter did not return the expected primary row.');
}
$args = pr600_prepare_args_flatten($GLOBALS['wpdb']->prepare_args);
if (!in_array('%"model\_keyword\_primary\_candidate":"yes"%', $args, true)) {
    throw new RuntimeException('Primary candidate LIKE pattern was not escaped/tight.');
}
if (!in_array('%"model\_keyword\_usage\_scope":"model\_bio\_only"%', $args, true)) {
    throw new RuntimeException('Model bio scope LIKE pattern was not escaped/tight.');
}

$GLOBALS['wpdb'] = new Pr600KeywordTableFilterSmokeWpdb();
$_GET = [ 'model_keyword_filter' => 'unlinked_model', 'intent_type' => 'model' ];
$table = new KeywordsTable(null, 'candidates');
$table->prepare_items();
if (count($table->items) !== 1 || $table->items[0]['keyword'] !== 'random yes row') {
    throw new RuntimeException('Unlinked Model Keywords filter was broken.');
}

echo "pr600 keyword table filter smoke checks passed\n";
