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
        $platform_slug = sanitize_key((string) $platform);
        if (!PlatformRegistry::get($platform_slug)) {
            return '';
        }

        $clean_username = self::sanitize_username((string) $username);
        if ($clean_username === '') {
            return '';
        }

        $profile_url = self::build_profile_url($platform_slug, $clean_username);
        if ($profile_url === '') {
            return '';
        }

        $settings = self::get_platform_affiliate_settings($platform_slug);
        if (!empty($settings['enabled']) && !empty($settings['template'])) {
            $built = self::build_from_template((string) $settings['template'], $platform_slug, $clean_username, $profile_url, $settings);
            if ($built !== '') {
                return $built;
            }
        }

        $platform_data = PlatformRegistry::get($platform_slug);
        $registry_pattern = is_array($platform_data) ? (string) ($platform_data['affiliate_link_pattern'] ?? '') : '';
        if ($registry_pattern !== '') {
            $built = self::build_from_template($registry_pattern, $platform_slug, $clean_username, $profile_url, []);
            if ($built !== '') {
                return $built;
            }
        }

        $legacy_pattern = (string) Settings::get('affiliate_link_pattern', '');
        if ($legacy_pattern !== '') {
            $built = self::build_from_template($legacy_pattern, $platform_slug, $clean_username, $profile_url, []);
            if ($built !== '') {
                return $built;
            }
        }

        return $profile_url;
    }

    public static function go_url($platform, $username): string {
        $platform_slug = sanitize_key((string) $platform);
        $clean_username = self::sanitize_username((string) $username);

        return home_url('/go/' . rawurlencode($platform_slug) . '/' . rawurlencode($clean_username) . '/');
    }

    /**
     * Build an affiliate URL for an arbitrary approved target URL.
     *
     * Used by the Verified External Links affiliate routing layer, where the
     * link being routed is an already-approved outbound URL (not necessarily
     * a username-based platform profile).
     *
     * Lookup order:
     *   1. Per-platform affiliate settings for $network_key (enabled + template).
     *   2. If $network_key is empty or not configured, returns $target_url unchanged.
     *
     * The $target_url is passed as both {profile_url} and {encoded_profile_url}
     * in the template — existing template infrastructure handles it correctly.
     *
     * Returns $target_url on any failure so callers always get a valid URL.
     *
     * @param string $target_url   Operator-approved outbound URL (the VL `url` field).
     * @param string $network_key  Affiliate network key, e.g. 'crack_revenue' or a
     *                             platform slug. Must be a key in
     *                             tmwseo_platform_affiliate_settings option.
     * @return string              Routed affiliate URL, or $target_url as fallback.
     */
    public static function build_affiliate_url_for_target( string $target_url, string $network_key ): string {
        $target_url  = esc_url_raw( trim( $target_url ) );
        $network_key = sanitize_key( $network_key );

        if ( $target_url === '' || $network_key === '' ) {
            return $target_url;
        }

        $settings = self::get_platform_affiliate_settings( $network_key );
        if ( empty( $settings['enabled'] ) || empty( $settings['template'] ) ) {
            return $target_url;
        }

        $built = self::build_from_template(
            (string) $settings['template'],
            $network_key,
            '',           // no username in this path — template should use {profile_url}
            $target_url,  // profile_url = approved outbound target
            $settings
        );

        if ( $built !== '' && wp_http_validate_url( $built ) ) {
            return $built;
        }

        return $target_url;
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

        if (!wp_http_validate_url($url)) {
            Logs::warning('platform', '[TMW-AFF] Rejected invalid redirect URL', [
                'platform' => $platform_slug,
                'username' => $clean_username,
                'url' => $url,
            ]);
            return;
        }

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

    private static function get_platform_affiliate_settings(string $platform): array {
        $all = get_option('tmwseo_platform_affiliate_settings', []);
        if (!is_array($all)) {
            return [];
        }

        $settings = $all[$platform] ?? [];
        return is_array($settings) ? $settings : [];
    }

    private static function build_from_template(string $template, string $platform, string $username, string $profile_url, array $settings): string {
        $template = trim($template);
        if ($template === '') {
            return '';
        }

        $campaign = (string) ($settings['campaign'] ?? Settings::get('affiliate_campaign', ''));
        $source = (string) ($settings['source'] ?? Settings::get('affiliate_source', ''));
        $subaffid = (string) ($settings['subaffid'] ?? '');
        $psid = (string) ($settings['psid'] ?? '');
        $pstool = (string) ($settings['pstool'] ?? '');
        $psprogram = (string) ($settings['psprogram'] ?? '');
        $campaign_id = (string) ($settings['campaign_id'] ?? '');
        $siteid = (string) ($settings['siteid'] ?? '');
        $categoryname = (string) ($settings['categoryname'] ?? '');
        $pagename = (string) ($settings['pagename'] ?? '');

        $replacements = [
            '{username}' => rawurlencode($username),
            '{profile_url}' => $profile_url,
            '{encoded_profile_url}' => rawurlencode($profile_url),
            '{campaign}' => rawurlencode($campaign),
            '{source}' => rawurlencode($source),
            '{subaffid}' => rawurlencode($subaffid),
            '{psid}' => rawurlencode($psid),
            '{pstool}' => rawurlencode($pstool),
            '{psprogram}' => rawurlencode($psprogram),
            '{campaign_id}' => rawurlencode($campaign_id),
            '{siteid}' => rawurlencode($siteid),
            '{categoryname}' => rawurlencode($categoryname),
            '{pagename}' => rawurlencode($pagename),
            '{platform}' => rawurlencode($platform),
            '{siteId}' => rawurlencode($siteid),
            '{categoryName}' => rawurlencode($categoryname),
            '{pageName}' => rawurlencode($pagename),
            '{subAffId}' => rawurlencode($subaffid),
        ];

        $url = strtr($template, $replacements);
        $url = esc_url_raw($url);
        return wp_http_validate_url($url) ? $url : '';
    }
}
