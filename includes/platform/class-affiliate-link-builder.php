<?php
namespace TMWSEO\Engine\Platform;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\Settings;

if (!defined('ABSPATH')) { exit; }

class AffiliateLinkBuilder {

    public static function init(): void {
        add_action('init', [__CLASS__, 'register_rewrite_rule']);
        add_filter('query_vars', [__CLASS__, 'register_query_vars']);
        add_action('template_redirect', [__CLASS__, 'maybe_handle_redirect']);
    }

    public static function register_rewrite_rule(): void {
        add_rewrite_rule('^go/([^/]+)/([^/]+)/?$', 'index.php?tmw_go_platform=$matches[1]&tmw_go_username=$matches[2]', 'top');
    }

    public static function register_query_vars(array $vars): array {
        $vars[] = 'tmw_go_platform';
        $vars[] = 'tmw_go_username';

        return $vars;
    }

    public static function build_profile_url($platform, $username): string {
        $platform_data = PlatformRegistry::get((string) $platform);
        if (!$platform_data) {
            return '';
        }

        $clean_username = self::sanitize_username((string) $username);
        if ($clean_username === '') {
            return '';
        }

        $pattern = (string) ($platform_data['profile_url_pattern'] ?? '');
        if ($pattern === '') {
            return '';
        }

        return str_replace('{username}', rawurlencode($clean_username), $pattern);
    }

    public static function build_affiliate_url($platform, $username): string {
        $profile_url = self::build_profile_url((string) $platform, (string) $username);
        if ($profile_url === '') {
            return '';
        }

        $pattern = (string) Settings::get('affiliate_link_pattern', '');
        if ($pattern === '') {
            return $profile_url;
        }

        $replacements = [
            '{username}' => rawurlencode(self::sanitize_username((string) $username)),
            '{profile_url}' => $profile_url,
            '{encoded_profile_url}' => rawurlencode($profile_url),
            '{campaign}' => rawurlencode((string) Settings::get('affiliate_campaign', '')),
            '{source}' => rawurlencode((string) Settings::get('affiliate_source', '')),
        ];

        return strtr($pattern, $replacements);
    }

    public static function go_url($platform, $username): string {
        $platform_slug = sanitize_key((string) $platform);
        $clean_username = self::sanitize_username((string) $username);

        return home_url('/go/' . rawurlencode($platform_slug) . '/' . rawurlencode($clean_username) . '/');
    }

    public static function maybe_handle_redirect(): void {
        $platform = (string) get_query_var('tmw_go_platform', '');
        $username = (string) get_query_var('tmw_go_username', '');

        if ($platform === '' || $username === '') {
            return;
        }

        $platform_slug = sanitize_key($platform);
        if (!PlatformRegistry::get($platform_slug)) {
            return;
        }

        $clean_username = self::sanitize_username($username);
        if ($clean_username === '') {
            return;
        }

        $url = self::build_affiliate_url($platform_slug, $clean_username);
        if ($url === '') {
            return;
        }

        self::log_click($platform_slug, $clean_username, $url);

        Logs::info('platform', '[TMW-AFF] Redirecting affiliate click', [
            'platform' => $platform_slug,
            'username' => $clean_username,
            'url' => $url,
        ]);

        wp_redirect($url, 302);
        exit;
    }

    private static function sanitize_username(string $username): string {
        $username = wp_unslash($username);
        $username = trim($username);
        $username = sanitize_text_field($username);

        return preg_replace('/[^A-Za-z0-9._-]/', '', $username) ?? '';
    }

    private static function log_click(string $platform, string $username, string $url): void {
        global $wpdb;

        if (!isset($wpdb)) {
            return;
        }

        $table = $wpdb->prefix . 'tmw_aff_clicks';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert($table, [
            'platform' => $platform,
            'username' => $username,
            'target_url' => $url,
            'clicked_at' => current_time('mysql'),
            'ip_address' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_USER_AGENT'])) : '',
        ], [
            '%s', '%s', '%s', '%s', '%s', '%s',
        ]);
    }
}
