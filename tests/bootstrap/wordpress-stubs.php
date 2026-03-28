<?php
/**
 * TMW SEO Engine — PHPUnit WordPress stub environment.
 *
 * Provides the minimal WordPress function/constant surface needed to instantiate
 * and test plugin classes without a full WordPress installation.
 *
 * Usage: referenced from phpunit.xml as <bootstrap> file.
 */

// ── Core constants ────────────────────────────────────────────────────────────
if ( ! defined( 'ABSPATH' ) )                { define( 'ABSPATH', __DIR__ . '/../../' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) )         { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) )        { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) )      { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'WP_CONTENT_DIR' ) )         { define( 'WP_CONTENT_DIR', '/tmp/wp-content' ); }
if ( ! defined( 'AUTH_KEY' ) )               { define( 'AUTH_KEY', 'test_auth_key_tmwseo_phpunit_stub_32chars!!!' ); }
if ( ! defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' ) ) { define( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES', 32 ); }
if ( ! defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' ) ) { define( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES', 24 ); }


// ── WordPress DB result format constants ─────────────────────────────────────
if ( ! defined( 'ARRAY_A' ) )  { define( 'ARRAY_A',  'ARRAY_A' ); }
if ( ! defined( 'ARRAY_N' ) )  { define( 'ARRAY_N',  'ARRAY_N' ); }
if ( ! defined( 'OBJECT' ) )   { define( 'OBJECT',   'OBJECT'  ); }
if ( ! defined( 'OBJECT_K' ) ) { define( 'OBJECT_K', 'OBJECT_K'); }

// ── In-memory WordPress option store ──────────────────────────────────────────
/** @var array<string,mixed> */
$GLOBALS['_tmw_test_options'] = [];

function get_option( string $key, $default = false ) {
    return array_key_exists( $key, $GLOBALS['_tmw_test_options'] )
        ? $GLOBALS['_tmw_test_options'][ $key ]
        : $default;
}

function update_option( string $key, $value, $autoload = null ): bool {
    $GLOBALS['_tmw_test_options'][ $key ] = $value;
    return true;
}

function add_option( string $key, $value = '', $deprecated = '', $autoload = 'yes' ): bool {
    if ( array_key_exists( $key, $GLOBALS['_tmw_test_options'] ) ) {
        return false;
    }
    $GLOBALS['_tmw_test_options'][ $key ] = $value;
    return true;
}

function delete_option( string $key ): bool {
    unset( $GLOBALS['_tmw_test_options'][ $key ] );
    return true;
}

// ── Transient store ────────────────────────────────────────────────────────────
/** @var array<string,mixed> */
$GLOBALS['_tmw_test_transients'] = [];

function get_transient( string $key ) {
    return array_key_exists( $key, $GLOBALS['_tmw_test_transients'] )
        ? $GLOBALS['_tmw_test_transients'][ $key ]
        : false;
}

function set_transient( string $key, $value, int $expiration = 0 ): bool {
    $GLOBALS['_tmw_test_transients'][ $key ] = $value;
    return true;
}

function delete_transient( string $key ): bool {
    unset( $GLOBALS['_tmw_test_transients'][ $key ] );
    return true;
}

// ── WordPress function stubs ───────────────────────────────────────────────────
function wp_salt( string $scheme = 'auth' ): string {
    return 'test_salt_tmwseo_phpunit_' . $scheme . '_32chars!!!';
}

function current_time( string $type = 'mysql', bool $gmt = false ): string {
    return gmdate( 'Y-m-d H:i:s' );
}

function sanitize_text_field( string $str ): string {
    return trim( strip_tags( $str ) );
}

function sanitize_key( string $key ): string {
    return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) );
}

function wp_json_encode( $data, int $options = 0, int $depth = 512 ): string|false {
    return json_encode( $data, $options, $depth );
}

function wp_strip_all_tags( string $string, bool $remove_breaks = false ): string {
    return strip_tags( $string );
}

function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( string $url ): string   { return $url; }

function __( string $text, string $domain = '' ): string { return $text; }
function _n( string $single, string $plural, int $number, string $domain = '' ): string {
    return $number === 1 ? $single : $plural;
}

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool { return true; }
function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool { return true; }
function apply_filters( string $hook, $value, ...$args ) { return $value; }

function wp_next_scheduled( string $hook, array $args = [] ): int|false { return false; }
function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = [] ): bool { return true; }
function wp_clear_scheduled_hook( string $hook, array $args = [] ): int { return 0; }

function wp_upload_dir(): array {
    return [ 'basedir' => '/tmp/wp-uploads', 'baseurl' => 'http://example.com/wp-content/uploads' ];
}
function wp_mkdir_p( string $dir ): bool { return true; }
function trailingslashit( string $string ): string { return rtrim( $string, '/\\' ) . '/'; }

function size_format( int $bytes, int $decimals = 0 ): string {
    return round( $bytes / 1048576, $decimals ) . ' MB';
}


// ── $wpdb mock — minimal in-memory stub for unit tests ───────────────────────
if ( ! isset( $GLOBALS['wpdb'] ) ) {
    $GLOBALS['wpdb'] = new class {
        public string $prefix  = 'wp_';
        public string $options = 'wp_options';
        public array  $queries = [];
        public int    $insert_id = 0;

        public function prepare( string $sql, ...$args ): string {
            $i = 0;
            return preg_replace_callback( '/%[sdf]/', function() use ( $args, &$i ) {
                $v = $args[ $i++ ] ?? '';
                return is_string( $v ) ? "'".addslashes( $v )."'" : (string) $v;
            }, $sql );
        }

        public function query( string $sql ): int|false   { $this->queries[] = $sql; return 1; }
        public function insert( string $t, array $d, array $f = [] ): int|false { $this->queries[] = $t; $this->insert_id++; return 1; }
        public function update( string $t, array $d, array $w, array $df = [], array $wf = [] ): int|false { return 1; }
        public function delete( string $t, array $w, array $wf = [] ): int|false { $this->queries[] = 'DELETE:' . $t; return 1; }
        public function get_var( string $sql ): mixed     { return null; }
        public function get_row( string $sql, string $o = 'OBJECT' ): mixed { return null; }
        public function get_results( string $sql, string $o = 'OBJECT' ): array { return []; }
        public function esc_like( string $t ): string     { return addcslashes( $t, '_%\\' ); }
        public function get_charset_collate(): string     { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
    };
}

// ── WP_Error stub ─────────────────────────────────────────────────────────────
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private string $code;
        private string $message;
        public function __construct( string $code = '', string $message = '', $data = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }
        public function get_error_message(): string { return $this->message; }
        public function get_error_code(): string    { return $this->code; }
    }
}

function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }

// ── Plugin path constants ──────────────────────────────────────────────────────
define( 'TMWSEO_ENGINE_VERSION', '4.6.3' );
// __DIR__ = tests/bootstrap  →  dirname(__DIR__) = tests  →  dirname(dirname(__DIR__)) = plugin root
define( 'TMWSEO_ENGINE_PATH', dirname( dirname( __DIR__ ) ) . '/' );
define( 'TMWSEO_ENGINE_URL', 'http://example.com/wp-content/plugins/tmw-seo-engine/' );
define( 'TMWSEO_ENGINE_BOOTSTRAPPED', true );

// ── Autoload plugin includes ───────────────────────────────────────────────────
require_once TMWSEO_ENGINE_PATH . 'includes/db/class-logs.php';
require_once TMWSEO_ENGINE_PATH . 'includes/services/class-settings.php';
require_once TMWSEO_ENGINE_PATH . 'includes/services/class-dataforseo.php';
require_once TMWSEO_ENGINE_PATH . 'includes/ai/class-ai-router.php';
require_once TMWSEO_ENGINE_PATH . 'includes/class-discovery-governor.php';
require_once TMWSEO_ENGINE_PATH . 'includes/integrations/class-gsc-api.php';

// ── Additional WP function stubs needed by Admin::sanitize_settings ─────────
if (!function_exists('esc_url_raw'))          { function esc_url_raw(string $url): string { return filter_var($url, FILTER_SANITIZE_URL) ?: ''; } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field(string $str): string { return trim(strip_tags($str)); } }
if (!function_exists('number_format_i18n'))   { function number_format_i18n(float $n, int $d = 0): string { return number_format($n, $d); } }
if (!function_exists('admin_url'))            { function admin_url(string $path = ''): string { return 'http://example.com/wp-admin/' . ltrim($path, '/'); } }
if (!function_exists('get_current_user_id'))  { function get_current_user_id(): int { return 1; } }
if (!function_exists('get_post_type'))        { function get_post_type($post = null) { return 'post'; } }
if (!function_exists('wp_get_referer'))       { function wp_get_referer() { return false; } }
if (!function_exists('wp_safe_redirect'))     { function wp_safe_redirect(string $l, int $s = 302): bool { return true; } }
if (!function_exists('wp_redirect'))          { function wp_redirect(string $l, int $s = 302): bool { return true; } }
if (!function_exists('add_query_arg'))        { function add_query_arg(...$args): string { return ''; } }
if (!function_exists('wp_die'))               { function wp_die($msg = '', $title = '', $args = []): void { throw new \RuntimeException(is_string($msg) ? $msg : 'wp_die'); } }
if (!function_exists('wp_send_json_success')) { function wp_send_json_success($data = null, int $s = 200): void {} }
if (!function_exists('wp_send_json_error'))   { function wp_send_json_error($data = null, int $s = 200): void {} }
if (!function_exists('current_user_can'))     { function current_user_can(string $cap, ...$args): bool { return true; } }
if (!function_exists('check_admin_referer'))  { function check_admin_referer($action = -1, string $qa = '_wpnonce'): int { return 1; } }
if (!function_exists('wp_verify_nonce'))      { function wp_verify_nonce($nonce, $action = -1) { return 1; } }
if (!function_exists('wp_create_nonce'))      { function wp_create_nonce($action = -1): string { return 'test_nonce'; } }
if (!function_exists('checked'))              { function checked($c, $cur = true, bool $echo = true): string { return $c == $cur ? 'checked' : ''; } }
if (!function_exists('selected'))             { function selected($s, $cur = true, bool $echo = true): string { return $s == $cur ? 'selected' : ''; } }
if (!function_exists('disabled'))             { function disabled($d, $cur = true, bool $echo = true): string { return $d == $cur ? 'disabled' : ''; } }
if (!function_exists('get_post'))             { function get_post($post = null): ?object { return null; } }
if (!function_exists('get_the_title'))        { function get_the_title($post = null): string { return ''; } }
if (!function_exists('get_post_meta'))        { function get_post_meta(int $id, string $k = '', bool $s = false) { return $s ? '' : []; } }
if (!function_exists('update_post_meta'))     { function update_post_meta(int $id, string $k, $v, $p = ''): bool { return true; } }
if (!function_exists('delete_post_meta'))     { function delete_post_meta(int $id, string $k, $v = ''): bool { return true; } }
if (!function_exists('get_posts'))            { function get_posts(array $args = []): array { return []; } }
if (!function_exists('get_terms'))            { function get_terms(array $args = []) { return []; } }
if (!function_exists('get_permalink'))        { function get_permalink($post = 0) { return false; } }
if (!function_exists('wp_insert_post'))       { function wp_insert_post(array $p, bool $e = false): int { return 0; } }
if (!function_exists('wp_unique_filename'))   { function wp_unique_filename(string $d, string $f): string { return $f; } }
if (!function_exists('sanitize_file_name'))   { function sanitize_file_name(string $f): string { return preg_replace('/[^a-zA-Z0-9._-]/', '-', $f); } }
if (!function_exists('get_current_screen'))   { function get_current_screen(): ?object { return null; } }
if (!function_exists('do_settings_sections')) { function do_settings_sections(string $p): void {} }
if (!function_exists('settings_fields'))      { function settings_fields(string $g): void {} }
if (!function_exists('submit_button'))        { function submit_button(string $t = null): void {} }
if (!function_exists('wp_remote_post'))       { function wp_remote_post(string $url, array $args = []): array { return []; } }
if (!function_exists('wp_date'))              { function wp_date(string $fmt, int $ts = null): string { return gmdate($fmt, $ts); } }
if (!function_exists('wp_unslash'))           { function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; } }
if (!function_exists('wp_kses_post'))         { function wp_kses_post(string $s): string { return strip_tags($s, '<a><strong><em><p>'); } }

// ── v5.1.1: New handler classes (required for Admin class to load) ─────────────
// Admin class has `use` statements for these; PHP needs them resolvable at parse time.
require_once TMWSEO_ENGINE_PATH . 'includes/admin/class-admin-ui.php';
require_once TMWSEO_ENGINE_PATH . 'includes/admin/class-admin-ajax-handlers.php';
require_once TMWSEO_ENGINE_PATH . 'includes/admin/class-admin-form-handlers.php';
require_once TMWSEO_ENGINE_PATH . 'includes/admin/class-admin.php';
