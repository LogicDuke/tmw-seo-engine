<?php
namespace TMWSEO\Engine\Platform;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class PlatformProfiles {

    public static function init(): void {
        add_shortcode('tmw_model_links', [__CLASS__, 'shortcode_model_links']);

        add_action('add_meta_boxes', [__CLASS__, 'register_metabox']);
        add_action('save_post_model', [__CLASS__, 'save_metabox'], 10, 2);

        // Gutenberg/REST fallback: ensure these fields save even when the block editor
        // doesn't submit classic metabox POST data (varies by host/plugins).
        add_action('enqueue_block_editor_assets', [__CLASS__, 'enqueue_editor_assets']);
        add_action('wp_ajax_tmwseo_save_platform_profiles', [__CLASS__, 'ajax_save_platform_profiles']);
    }

    public static function register_metabox(): void {
        add_meta_box(
            'tmwseo_platform_profiles',
            'TMW Platform Profiles',
            [__CLASS__, 'render_metabox'],
            'model',
            'side',
            'default'
        );
    }

    public static function render_metabox(\WP_Post $post): void {
        wp_nonce_field('tmwseo_platform_profiles_save', 'tmwseo_platform_profiles_nonce');

        echo "<p style=\"margin-top:0\">Add your model usernames on other platforms. Used for multi-platform linking.</p>";

        foreach (self::get_platform_labels() as $key => $label) {
            $val = self::get_username_with_migration($post->ID, $key);
            echo '<p><label style="font-weight:600">' . esc_html($label) . '</label><br>';
            echo '<input type="text" style="width:100%" name="tmwseo_platform_username[' . esc_attr($key) . ']" value="' . esc_attr($val) . '" placeholder="username" /></p>';
        }

        echo '<p><em>' . esc_html__("Tip: Enter only the username, not the full URL.", 'tmw-seo-engine') . '</em></p>';

        $primary = (string) get_post_meta($post->ID, '_tmwseo_platform_primary', true);
        echo '<p><label style="font-weight:600">Primary platform</label><br>';
        echo '<select name="tmwseo_platform_primary" style="width:100%">';
        echo '<option value="">— none —</option>';
        foreach (self::get_platform_labels() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($primary, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></p>';
    }

    public static function save_metabox(int $post_id, \WP_Post $post): void {
        if (!isset($_POST['tmwseo_platform_profiles_nonce']) || !wp_verify_nonce($_POST['tmwseo_platform_profiles_nonce'], 'tmwseo_platform_profiles_save')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $platform_usernames = $_POST['tmwseo_platform_username'] ?? [];
        if (!is_array($platform_usernames)) $platform_usernames = [];

        foreach (self::get_platform_labels() as $key => $label) {
            $val = isset($platform_usernames[$key]) ? sanitize_text_field(trim((string) wp_unslash($platform_usernames[$key]))) : '';
            update_post_meta($post_id, '_tmwseo_platform_username_' . $key, $val);

            if ($val === '') {
                self::migrate_username_from_legacy_url($post_id, $key);
            }
        }

        $primary = isset($_POST['tmwseo_platform_primary']) ? sanitize_text_field((string)$_POST['tmwseo_platform_primary']) : '';
        update_post_meta($post_id, '_tmwseo_platform_primary', $primary);

        self::sync_to_table($post_id);

        Logs::info('platform', '[TMW-PLATFORM] Saved platform profiles', ['model_id' => $post_id]);
    }


    public static function enqueue_editor_assets(): void {
        // Only enqueue in the block editor.
        if (!function_exists('get_current_screen')) return;
        $screen = get_current_screen();
        if (!$screen || ($screen->base ?? '') !== 'post') return;
        if (($screen->post_type ?? '') !== 'model') return;

        wp_enqueue_script(
            'tmwseo-platform-profiles-editor',
            TMWSEO_ENGINE_URL . 'assets/js/platform-profiles-editor.js',
            ['wp-data'],
            TMWSEO_ENGINE_VERSION,
            true
        );

        wp_localize_script('tmwseo-platform-profiles-editor', 'TMWSEOPlatformProfiles', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('tmwseo_platform_profiles_ajax'),
        ]);
    }

    public static function ajax_save_platform_profiles(): void {
        check_ajax_referer('tmwseo_platform_profiles_ajax');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (!$post_id) {
            wp_send_json_error(['message' => 'Missing post_id'], 400);
        }

        if (get_post_type($post_id) !== 'model') {
            wp_send_json_error(['message' => 'Invalid post type'], 400);
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $usernames_raw = isset($_POST['usernames']) ? wp_unslash((string) $_POST['usernames']) : '{}';
        $usernames = json_decode($usernames_raw, true);
        if (!is_array($usernames)) {
            $usernames = [];
        }

        foreach (self::get_platform_labels() as $key => $label) {
            $val = isset($usernames[$key]) ? sanitize_text_field(trim((string) $usernames[$key])) : '';
            update_post_meta($post_id, '_tmwseo_platform_username_' . $key, $val);

            if ($val === '') {
                self::migrate_username_from_legacy_url($post_id, $key);
            }
        }

        $primary = isset($_POST['primary']) ? sanitize_text_field((string) wp_unslash($_POST['primary'])) : '';
        update_post_meta($post_id, '_tmwseo_platform_primary', $primary);

        self::sync_to_table($post_id);

        wp_send_json_success(['saved' => true]);
    }

    public static function sync_to_table(int $model_id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_platform_profiles';

        // Clear existing
        $wpdb->delete($table, ['model_id' => $model_id]);

        $primary = (string) get_post_meta($model_id, '_tmwseo_platform_primary', true);

        foreach (self::get_platform_labels() as $key => $label) {
            $username = self::get_username_with_migration($model_id, $key);
            if ($username === '') continue;

            $url = self::build_profile_url($key, $username);
            $url = trim($url);
            if ($url === '') continue;

            $wpdb->insert($table, [
                'model_id' => $model_id,
                'platform' => $key,
                'profile_url' => $url,
                'is_primary' => ($primary === $key) ? 1 : 0,
                'updated_at' => current_time('mysql'),
            ]);
        }
    }

    public static function get_links(int $model_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_platform_profiles';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT platform, profile_url, is_primary FROM {$table} WHERE model_id=%d ORDER BY is_primary DESC, platform ASC", $model_id), ARRAY_A);
        if (!is_array($rows)) return [];

        $primary = (string) get_post_meta($model_id, '_tmwseo_platform_primary', true);
        $enriched = [];
        foreach ($rows as $row) {
            $platform = (string) ($row['platform'] ?? '');
            $profile_url = (string) ($row['profile_url'] ?? '');
            if ($platform === '' || $profile_url === '') {
                continue;
            }

            $username = self::get_username_with_migration($model_id, $platform);
            $affiliate_url = $username !== '' ? AffiliateLinkBuilder::build_affiliate_url($platform, $username) : '';

            $enriched[] = [
                'platform' => $platform,
                'profile_url' => $profile_url,
                'affiliate_url' => $affiliate_url,
                'go_url' => $username !== '' ? AffiliateLinkBuilder::go_url($platform, $username) : '',
                'is_primary' => !empty($row['is_primary']) ? 1 : (($primary === $platform) ? 1 : 0),
            ];
        }

        return $enriched;
    }

    public static function shortcode_model_links($atts): string {
        $atts = shortcode_atts([
            'model_id' => 0,
        ], $atts, 'tmw_model_links');

        $model_id = (int)($atts['model_id'] ?: get_the_ID());
        if ($model_id <= 0) return '';

        $links = self::get_links($model_id);
        if (empty($links)) return '';
        $labels = self::get_platform_labels();

        $out = '<div class="tmw-model-links">';
        $out .= '<ul>';
        foreach ($links as $l) {
            $platform = (string)($l['platform'] ?? '');
            $url = (string)($l['profile_url'] ?? '');
            if ($platform === '' || $url === '') continue;

            $label = $labels[$platform] ?? ucfirst($platform);
            $out .= '<li><a href="' . esc_url($url) . '" rel="nofollow" target="_blank">' . esc_html($label) . '</a></li>';
        }
        $out .= '</ul></div>';

        return $out;
    }

    private static function get_username_with_migration(int $post_id, string $platform): string {
        $username = (string) get_post_meta($post_id, '_tmwseo_platform_username_' . $platform, true);
        $username = trim($username);
        if ($username !== '') {
            return $username;
        }

        return self::migrate_username_from_legacy_url($post_id, $platform);
    }

    private static function migrate_username_from_legacy_url(int $post_id, string $platform): string {
        $legacy_url = (string) get_post_meta($post_id, '_tmwseo_platform_' . $platform, true);
        $legacy_url = trim($legacy_url);
        if ($legacy_url === '') {
            return '';
        }

        $parsed = self::parse_profile_candidate($platform, $legacy_url);
        $username = !empty($parsed['success']) ? (string) ($parsed['username'] ?? '') : '';
        if ($username === '') {
            return '';
        }

        update_post_meta($post_id, '_tmwseo_platform_username_' . $platform, $username);
        return $username;
    }

    public static function extract_username_from_profile_url(string $platform, string $url): string {
        $parsed = self::parse_profile_candidate($platform, $url);
        return !empty($parsed['success']) ? (string) ($parsed['username'] ?? '') : '';
    }

    public static function parse_profile_candidate(string $platform, string $url): array {
        $platform = sanitize_key($platform);
        $url = trim($url);
        $base = [
            'success' => false,
            'username' => '',
            'normalized_platform' => $platform,
            'normalized_url' => '',
            'reject_reason' => '',
        ];

        $platform_data = PlatformRegistry::get($platform);
        if (!is_array($platform_data) || $url === '') {
            $base['reject_reason'] = 'unsupported_platform';
            return $base;
        }

        $normalized_url = self::normalize_input_url($url);
        $parts = wp_parse_url($normalized_url);
        if (!is_array($parts)) {
            $base['reject_reason'] = 'invalid_url';
            return $base;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $query = (string) ($parts['query'] ?? '');
        $fragment = trim((string) ($parts['fragment'] ?? ''), '/');

        $base['normalized_url'] = $normalized_url;

        $host_ok = self::matches_platform_host($platform, $host);
        if (!$host_ok) {
            $base['reject_reason'] = 'host_mismatch';
            return $base;
        }

        $username = self::extract_username_by_rule($platform, $host, $path, $query, $fragment);
        if ($username === '') {
            $base['reject_reason'] = 'unsupported_shape';
            return $base;
        }

        $base['success'] = true;
        $base['username'] = $username;
        $base['normalized_url'] = self::build_profile_url($platform, $username);
        return $base;
    }

    private static function normalize_input_url(string $url): string {
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        return trim($url);
    }

    private static function matches_platform_host(string $platform, string $host): bool {
        $rule = PlatformRegistry::get_parser_rule($platform);
        $host = strtolower(preg_replace('/^www\./', '', $host));
        $allowed = [];
        foreach ((array) ($rule['input_hosts'] ?? []) as $candidate) {
            $allowed[] = strtolower(preg_replace('/^www\./', '', (string) $candidate));
        }

        if (!empty($allowed) && in_array($host, $allowed, true)) {
            return true;
        }

        if (!empty($rule['locale_host']) && str_ends_with($host, '.stripchat.com')) {
            return true;
        }

        foreach ((array) ($rule['input_aliases'] ?? []) as $alias) {
            if (str_ends_with($host, strtolower((string) $alias))) {
                return true;
            }
        }

        $canonical_slug = PlatformRegistry::find_slug_by_host($host);
        return $canonical_slug === $platform;
    }

    private static function extract_username_by_rule(string $platform, string $host, string $path, string $query, string $fragment): string {
        $segments = $path === '' ? [] : explode('/', $path);
        $host = strtolower(preg_replace('/^www\./', '', $host));

        if ($platform === 'myfreecams') {
            return $fragment !== '' ? sanitize_text_field(urldecode($fragment)) : '';
        }
        if ($platform === 'flirt4free') {
            parse_str($query, $params);
            if (!empty($params['model'])) {
                return sanitize_text_field(urldecode((string) $params['model']));
            }
            if (count($segments) >= 4 && $segments[0] === 'videos' && $segments[1] === 'girls' && $segments[2] === 'models') {
                return sanitize_text_field(urldecode((string) $segments[3]));
            }
            if (count($segments) >= 2 && $segments[0] === 'model') {
                return sanitize_text_field(urldecode((string) $segments[1]));
            }
            return '';
        }
        if ($platform === 'sakuralive') {
            return $query !== '' ? sanitize_text_field(urldecode($query)) : '';
        }
        if ($platform === 'stripchat') {
            return !empty($segments[0]) ? sanitize_text_field(urldecode((string) $segments[0])) : '';
        }
        if ($platform === 'carrd') {
            if (!str_ends_with($host, '.carrd.co')) {
                return '';
            }
            $subdomain = str_replace('.carrd.co', '', $host);
            return $subdomain !== '' ? sanitize_text_field(urldecode($subdomain)) : '';
        }
        if ($platform === 'fansly') {
            if (count($segments) >= 2 && strtolower((string) $segments[1]) === 'posts' && $segments[0] !== '') {
                return sanitize_text_field(urldecode((string) $segments[0]));
            }
            if (count($segments) >= 2 && in_array(strtolower((string) $segments[0]), ['u', '@'], true) && $segments[1] !== '') {
                return sanitize_text_field(urldecode((string) $segments[1]));
            }
            if (!empty($segments[0]) && !in_array(strtolower((string) $segments[0]), ['explore', 'posts', 'messages', 'login', 'signup'], true)) {
                return sanitize_text_field(urldecode((string) $segments[0]));
            }
            return '';
        }
        if ($platform === 'livejasmin') {
            if (count($segments) >= 3 && in_array(strtolower((string) $segments[1]), ['chat', 'model', 'profile'], true)) {
                return sanitize_text_field(urldecode((string) $segments[2]));
            }
            if (count($segments) >= 2 && in_array(strtolower((string) $segments[0]), ['chat', 'model', 'profile'], true)) {
                return sanitize_text_field(urldecode((string) $segments[1]));
            }
            if (count($segments) >= 1 && preg_match('/^[a-z]{2}$/i', (string) $segments[0]) && in_array(strtolower((string) ($segments[1] ?? '')), ['chat', 'model', 'profile', 'chat-html5'], true) && !empty($segments[2])) {
                return sanitize_text_field(urldecode((string) $segments[2]));
            }
            return '';
        }

        return !empty($segments[0]) ? sanitize_text_field(urldecode((string) $segments[0])) : '';
    }

    private static function build_profile_url(string $platform, string $username): string {
        $platform_data = PlatformRegistry::get($platform);
        if (!is_array($platform_data)) {
            return '';
        }

        $pattern = (string) ($platform_data['profile_url_pattern'] ?? '');
        if ($pattern === '' || strpos($pattern, '{username}') === false) {
            return '';
        }

        if ($platform === 'myfreecams' || $platform === 'sakuralive') {
            return esc_url_raw(str_replace('{username}', rawurlencode($username), $pattern));
        }
        if ($platform === 'carrd') {
            $safe = sanitize_title_with_dashes($username);
            return esc_url_raw(str_replace('{username}', $safe, $pattern));
        }

        return esc_url_raw(str_replace('{username}', rawurlencode($username), $pattern));
    }

    private static function get_platform_labels(): array {
        $labels = [];
        foreach (PlatformRegistry::get_platforms() as $platform) {
            $slug = sanitize_key((string) ($platform['slug'] ?? ''));
            $name = sanitize_text_field((string) ($platform['name'] ?? ''));
            if ($slug === '' || $name === '') {
                continue;
            }
            $labels[$slug] = $name;
        }

        return $labels;
    }
}
