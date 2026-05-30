<?php
/**
 * Smoke checks for PR 605 default model Generate strategy.
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Services {
    final class OpenAI { public static bool $configured = true; public static function is_configured(): bool { return self::$configured; } }
    final class Anthropic { public static bool $configured = false; public static function is_configured(): bool { return self::$configured; } }
    final class Settings { public static function get(string $key, $default = null) { return $key === 'tmwseo_ai_primary' ? 'openai' : $default; } public static function is_safe_mode(): bool { return false; } }
}

namespace TMWSEO\Engine\Keywords {
    final class UnifiedKeywordWorkflowService {
        public static array $pack = [ 'additional' => [], 'longtail' => [] ];
        public static function get_pack_with_legacy_fallback(int $post_id): array { return self::$pack; }
    }
}

namespace TMWSEO\Engine\Content {
    final class AssistedDraftEnrichmentService {
        public static function preview_meta_keys(): array { return []; }
    }
}

namespace TMWSEO\Engine\Model {
    final class Rollback { public static function has_snapshot(int $post_id): bool { return false; } public static function snapshot_time(int $post_id): string { return ''; } }
}

namespace TMWSEO\Engine {
    final class Logs { public static function info(string $channel, string $message, array $context = []): void {} public static function warn(string $channel, string $message, array $context = []): void {} }
    final class Jobs {}
}

namespace {
    if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
    if (!defined('TMWSEO_ENGINE_URL')) { define('TMWSEO_ENGINE_URL', 'https://example.test/wp-content/plugins/tmw-seo-engine/'); }

    $GLOBALS['pr605_db_writes'] = [];
    $GLOBALS['pr605_generate_executed'] = false;

    function pr605_assert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
    function add_action(...$args): void {}
    function add_shortcode(...$args): void {}
    function wp_nonce_field(...$args): void { echo '<input type="hidden" name="_wpnonce" value="nonce">'; }
    function get_post_meta(int $post_id, string $key, bool $single = false) { return ''; }
    function get_post_type(int $post_id): string { return $GLOBALS['pr605_post_types'][$post_id] ?? 'post'; }
    function get_post($post_id) { return $GLOBALS['pr605_posts'][$post_id] ?? null; }
    function get_post_field(string $field, int $post_id) { return ''; }
    function update_post_meta(...$args): void { $GLOBALS['pr605_db_writes'][] = [ 'update_post_meta', $args ]; }
    function delete_post_meta(...$args): void { $GLOBALS['pr605_db_writes'][] = [ 'delete_post_meta', $args ]; }
    function wp_update_post(...$args): void { $GLOBALS['pr605_db_writes'][] = [ 'wp_update_post', $args ]; }
    function esc_html($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    function esc_attr($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    function esc_url($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    function esc_js($text): string { return addslashes((string) $text); }
    function esc_html__($text, string $domain = ''): string { return (string) $text; }
    function __($text, string $domain = ''): string { return (string) $text; }
    function selected($selected, $current = true, bool $display = true): string { $result = ((string) $selected === (string) $current) ? ' selected="selected"' : ''; if ($display) { echo $result; } return $result; }
    function checked($checked, $current = true, bool $display = true): string { $result = ((string) $checked === (string) $current) ? ' checked="checked"' : ''; if ($display) { echo $result; } return $result; }
    function wp_create_nonce(string $action): string { return 'nonce-' . $action; }
    function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . ltrim($path, '/'); }
    function sanitize_key($key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)) ?? ''; }
    function apply_filters($hook, $value) { return $value; }

    require_once dirname(__DIR__) . '/includes/admin/class-editor-ai-metabox.php';
    require_once dirname(__DIR__) . '/includes/content/class-content-engine.php';

    use TMWSEO\Engine\Admin\Editor_AI_Metabox;
    use TMWSEO\Engine\Content\ContentEngine;
    use TMWSEO\Engine\Keywords\UnifiedKeywordWorkflowService;
    use TMWSEO\Engine\Services\OpenAI;

    $render_metabox = static function (string $post_type): string {
        $post = (object) [ 'ID' => $post_type === 'model' ? 6051 : 6052, 'post_type' => $post_type, 'post_title' => 'Smoke Test', 'post_status' => 'publish' ];
        $GLOBALS['pr605_posts'][$post->ID] = $post;
        $GLOBALS['pr605_post_types'][$post->ID] = $post_type;
        ob_start();
        Editor_AI_Metabox::render($post);
        return (string) ob_get_clean();
    };

    UnifiedKeywordWorkflowService::$pack = [
        'additional' => [ 'approved classified model keyword' ],
        'longtail' => [ 'approved classified model keyword longtail' ],
    ];

    $model_html = $render_metabox('model');
    pr605_assert((bool) preg_match('/<option value="template"[^>]*selected="selected"[^>]*>Template<\/option>/', $model_html), 'Model metabox should render Template as the selected strategy.');
    pr605_assert(str_contains($model_html, '<option value="openai"'), 'OpenAI strategy option should remain available when configured.');
    pr605_assert((bool) preg_match('/id="tmwseo-generate-insert-block"[^>]*checked/', $model_html), 'Insert content block should remain checked by default.');
    pr605_assert(str_contains($model_html, 'For model pages, keep this checked'), 'Model Generate help text should remain visible.');
    pr605_assert(str_contains($model_html, 'Selected Keywords') && str_contains($model_html, 'approved classified model keyword'), 'Classified keyword pack reference/help text should remain visible.');

    UnifiedKeywordWorkflowService::$pack = [ 'additional' => [], 'longtail' => [] ];
    $post_html = $render_metabox('post');
    pr605_assert((bool) preg_match('/<option value="openai"[^>]*selected="selected"[^>]*>OpenAI \(if configured\)<\/option>/', $post_html), 'Non-model post types should keep the existing OpenAI default when configured.');

    $ajax_source = (string) file_get_contents(dirname(__DIR__) . '/includes/admin/class-admin-ajax-handlers.php');
    pr605_assert(str_contains($ajax_source, 'isset( $_POST[\'dry\'] )'), 'AJAX handler should read dry context before strategy normalization.');
    pr605_assert((bool) preg_match('/normalize_generate_strategy\(\s*\(string\) \( \$_POST\[\'strategy\'\] \?\? \'\' \),\s*\(string\) \$post_type,\s*\$dry/s', $ajax_source), 'AJAX handler should pass dry context into strategy normalization.');

    pr605_assert(ContentEngine::normalize_generate_strategy('openai', 'post', 1) === 'template', 'dry=1 should force Template.');
    foreach ([ '', 'bogus', 'not-a-provider' ] as $raw_strategy) {
        pr605_assert(ContentEngine::normalize_generate_strategy($raw_strategy, 'model', 0) === 'template', 'Model invalid/missing strategy should normalize to Template.');
    }
    pr605_assert(ContentEngine::normalize_generate_strategy('openai', 'model', 0) === 'openai', 'Model explicit valid OpenAI strategy should be preserved when OpenAI is configured.');
    pr605_assert(ContentEngine::normalize_generate_strategy('', 'post', 0) === 'openai', 'Non-model empty strategy should preserve existing configured-provider fallback when OpenAI is configured.');

    OpenAI::$configured = false;
    pr605_assert(ContentEngine::normalize_generate_strategy('openai', 'model', 0) === 'template', 'Model explicit OpenAI should fall back to Template when OpenAI is not configured.');
    pr605_assert(ContentEngine::normalize_generate_strategy('openai', 'post', 0) !== 'openai', 'dry=0 should not return OpenAI when OpenAI is not configured.');
    pr605_assert(ContentEngine::normalize_generate_strategy('', 'post', 0) !== 'openai', 'Non-model dry=0 fallback should not return OpenAI when OpenAI is not configured.');
    OpenAI::$configured = true;

    pr605_assert($GLOBALS['pr605_generate_executed'] === false, 'Smoke test must not execute Generate.');
    pr605_assert($GLOBALS['pr605_db_writes'] === [], 'Smoke test must not perform database writes.');

    echo "✓ PR 605 default model Generate strategy smoke checks passed\n";
}
