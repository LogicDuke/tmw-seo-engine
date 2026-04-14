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

        $username = self::extract_username_from_url($platform, $legacy_url);
        if ($username === '') {
            return '';
        }

        update_post_meta($post_id, '_tmwseo_platform_username_' . $platform, $username);
        return $username;
    }

    public static function extract_username_from_profile_url(string $platform, string $url): string {
        return self::extract_username_from_url($platform, $url);
    }

    /**
     * Parse a profile URL and return a structured parse-audit result.
     *
     * Always returns an array with all five keys regardless of outcome.
     *
     * @return array{
     *   success: bool,
     *   username: string,
     *   normalized_platform: string,
     *   normalized_url: string,
     *   reject_reason: string,
     * }
     */
    public static function parse_url_for_platform_structured(string $platform, string $url): array {
        $base = [
            'success'             => false,
            'username'            => '',
            'normalized_platform' => $platform,
            'normalized_url'      => '',
            'reject_reason'       => '',
        ];

        $platform_data = PlatformRegistry::get($platform);
        if (!is_array($platform_data)) {
            $base['reject_reason'] = 'unknown_platform';
            return $base;
        }

        // Fast host guard: reject before attempting extraction when host clearly
        // does not belong to this platform. Produces 'host_mismatch' reason.
        if (!self::url_host_matches_platform($platform, $url)) {
            $base['reject_reason'] = 'host_mismatch';
            return $base;
        }

        $username = self::extract_username_from_url($platform, $url);
        if ($username === '') {
            $base['reject_reason'] = 'extraction_failed';
            return $base;
        }

        return [
            'success'             => true,
            'username'            => $username,
            'normalized_platform' => $platform,
            'normalized_url'      => self::build_profile_url($platform, $username),
            'reject_reason'       => '',
        ];
    }

    private static function extract_username_from_url(string $platform, string $url): string {
        $platform_data = PlatformRegistry::get($platform);
        if (!is_array($platform_data)) {
            return '';
        }

        $url   = trim($url);
        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $host     = strtolower((string)($parts['host'] ?? ''));
        $path     = trim((string)($parts['path'] ?? ''), '/');
        $query    = (string)($parts['query'] ?? '');
        $fragment = trim((string)($parts['fragment'] ?? ''), '/');

        // ── Explicit handlers for platforms with non-standard URL shapes ──────
        //
        // All host checks use self::host_equals_or_subdomain_of() — NOT strpos —
        // to prevent lookalike-domain attacks (e.g. evilfansly.com matching fansly.com).

        if ($platform === 'myfreecams') {
            if (!self::host_equals_or_subdomain_of($host, 'myfreecams.com')) {
                return '';
            }
            return $fragment !== '' ? sanitize_text_field(urldecode($fragment)) : '';
        }

        if ($platform === 'flirt4free') {
            if (!self::host_equals_or_subdomain_of($host, 'flirt4free.com')) {
                return '';
            }
            // Shape 1: ?model={username}  (canonical output form)
            parse_str($query, $params);
            if (!empty($params['model'])) {
                return sanitize_text_field(urldecode((string)$params['model']));
            }
            // Shape 2: /videos/girls/models/{username}/  (common SERP variant)
            // MUST require all three prefix segments — never extract first segment alone.
            $segments = $path !== '' ? explode('/', $path) : [];
            if (
                count($segments) >= 4
                && $segments[0] === 'videos'
                && $segments[1] === 'girls'
                && $segments[2] === 'models'
                && $segments[3] !== ''
            ) {
                return sanitize_text_field(urldecode($segments[3]));
            }
            return '';
        }

        if ($platform === 'sakuralive') {
            if (!self::host_equals_or_subdomain_of($host, 'sakuralive.com')) {
                return '';
            }
            return $query !== '' ? sanitize_text_field(urldecode($query)) : '';
        }

        if ($platform === 'stripchat') {
            if (!self::host_equals_or_subdomain_of($host, 'stripchat.com')) {
                return '';
            }
            $segments = $path !== '' ? explode('/', $path) : [];
            return !empty($segments[0]) ? sanitize_text_field(urldecode((string)$segments[0])) : '';
        }

        if ($platform === 'carrd') {
            // Carrd profiles live at exactly {username}.carrd.co.
            // We need a single-level true subdomain:
            //   ✓ janecam.carrd.co
            //   ✗ carrd.co (no subdomain = no username)
            //   ✗ www.carrd.co (reserved subdomain)
            //   ✗ foo.bar.carrd.co (two-level, ambiguous)
            //   ✗ foo.carrd.co.evil.com (lookalike — does NOT end with .carrd.co)
            if ($host === '' || !self::host_equals_or_subdomain_of($host, 'carrd.co')) {
                return '';
            }
            // Extract the single subdomain segment
            $subdomain = substr($host, 0, strlen($host) - strlen('.carrd.co'));
            if ($subdomain === '' || $subdomain === 'www' || strpos($subdomain, '.') !== false) {
                return ''; // empty, reserved, or multi-level subdomain
            }
            return sanitize_text_field(urldecode($subdomain));
        }

        if ($platform === 'fansly') {
            if (!self::host_equals_or_subdomain_of($host, 'fansly.com')) {
                return '';
            }
            $segments = $path !== '' ? explode('/', $path) : [];
            if (!empty($segments[0])) {
                $candidate = (string)$segments[0];
                // Guard: reject known non-username path components on fansly.com
                $reserved = ['about', 'login', 'signup', 'feed', 'discover', 'live', 'shop', 'search'];
                if (!in_array(strtolower($candidate), $reserved, true)) {
                    return sanitize_text_field(urldecode($candidate));
                }
            }
            return '';
        }

        if ($platform === 'twitter') {
            // Accept both x.com (canonical) and twitter.com (legacy).
            // Canonical output URL always uses x.com (via build_profile_url).
            $is_x       = self::host_equals_or_subdomain_of($host, 'x.com');
            $is_twitter = self::host_equals_or_subdomain_of($host, 'twitter.com');
            if (!$is_x && !$is_twitter) {
                return '';
            }

            // Reject tweet/content URLs — /status/ anywhere in the path means
            // this is a tweet reference, not a profile.
            if (strpos('/' . $path . '/', '/status/') !== false) {
                return '';
            }

            $segments = $path !== '' ? explode('/', $path) : [];

            // Must have exactly one path segment (the username).
            // Two or more segments = /handle/following, /handle/likes, /handle/status/xyz, etc.
            if (count($segments) !== 1 || $segments[0] === '') {
                return '';
            }

            // Strip leading @ (sometimes present in crawled/linked URLs)
            $candidate = ltrim((string)$segments[0], '@');

            // Reserved first-level paths — these are Twitter UI routes, not profiles.
            static $twitter_reserved = [
                'home', 'explore', 'notifications', 'messages', 'search', 'i',
                'settings', 'login', 'logout', 'intent', 'share', 'hashtag',
                'compose', 'privacy', 'tos', 'help', 'about', 'en', 'download',
                'status', 'following', 'followers', 'media', 'likes', 'replies',
                'highlights', 'lists', 'moments', 'bookmarks', 'communities',
                'verified_followers', 'who_to_follow', 'connect_people', 'jobs',
            ];
            if (in_array(strtolower($candidate), $twitter_reserved, true)) {
                return '';
            }

            // Twitter usernames: 1–15 chars, alphanumeric + underscore only.
            // Rejects hyphens, dots, and other characters that Twitter does not allow.
            if (!preg_match('/^[A-Za-z0-9_]{1,15}$/', $candidate)) {
                return '';
            }

            return sanitize_text_field($candidate);
        }

        // ── Pattern-derived safe regex (all remaining platforms) ─────────────
        //
        // Build a regex directly from the platform's canonical profile_url_pattern.
        // Because the regex encodes the full domain + path prefix, a URL can only
        // match if its structure is exactly right for this platform.
        //
        // Both URL and pattern are normalized before matching to handle:
        //   • www. prefix variants  (stripped from both sides)
        //   • trailing slashes      (stripped from path end)
        //
        // THERE IS NO GENERIC FALLBACK. If the regex does not match, return ''.
        // An unrecognised URL shape for a platform produces no username — the
        // caller (parse_url_for_platform_structured) records it as 'extraction_failed'.

        $pattern = (string)($platform_data['profile_url_pattern'] ?? '');
        if ($pattern === '' || strpos($pattern, '{username}') === false) {
            return '';
        }

        // Normalize the pattern through the same function used for the URL,
        // using a safe placeholder so {username} survives normalization intact.
        $normalized_pattern = self::normalize_url_for_matching(
            str_replace('{username}', '__TMW_U__', $pattern)
        );
        $normalized_pattern = str_replace('__TMW_U__', '{username}', $normalized_pattern);
        $normalized_url     = self::normalize_url_for_matching($url);

        $escaped = preg_quote($normalized_pattern, '#');
        $regex   = str_replace('\\{username\\}', '([^/?\\#&]+)', $escaped);

        // \/?$ — allow optional trailing slash at URL end after the username
        if (!preg_match('#^' . $regex . '\/?$#i', $normalized_url, $matches)) {
            return '';
        }

        return sanitize_text_field(urldecode((string)($matches[1] ?? '')));
    }

    /**
     * Normalize a URL for pattern-matching purposes:
     *   - lowercase scheme + host
     *   - strip leading www. from host (so patterns and inputs are www-agnostic)
     *   - strip trailing slash from path end only
     *   - preserve query string (needed for ?model= canonical forms)
     *
     * Only the www prefix and trailing path slash are modified.
     * All other path segments are left untouched — this is what keeps parsing safe.
     */
    private static function normalize_url_for_matching(string $url): string {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            return strtolower(rtrim($url, '/'));
        }

        // Normalize scheme to https: all registry patterns use https://, and the
        // http vs https distinction is irrelevant for username extraction purposes.
        // Without this, http://chaturbate.com/user would fail to match the
        // https://chaturbate.com/{username} pattern regex.
        $scheme = 'https';
        $host   = strtolower($parts['host'] ?? '');

        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        $path  = rtrim($parts['path'] ?? '', '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $path . $query;
    }

    /**
     * Check whether a URL's host plausibly belongs to the given platform.
     *
     * Uses host_equals_or_subdomain_of() for strict matching — substring checks
     * like strpos() would allow lookalike domains (e.g. evilfansly.com matching
     * fansly.com as a substring).
     *
     * Used as a fast guard in parse_url_for_platform_structured() so that
     * iterating all platform slugs against a URL pool is O(n) cheap — the
     * vast majority of platform/URL combinations are rejected here before
     * the more expensive extraction logic runs.
     */
    private static function url_host_matches_platform(string $platform, string $url): bool {
        $parts = wp_parse_url(trim($url));
        if (!is_array($parts)) {
            return false;
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        // Carrd: host must be a single-level true subdomain of carrd.co
        if ($platform === 'carrd') {
            if (!self::host_equals_or_subdomain_of($host, 'carrd.co')) {
                return false;
            }
            $sub = substr($host, 0, strlen($host) - strlen('.carrd.co'));
            return $sub !== '' && $sub !== 'www' && strpos($sub, '.') === false;
        }

        // Twitter/X: canonical host is x.com; twitter.com is an accepted legacy host.
        // Both are valid for the 'twitter' slug — neither is a subdomain of the other.
        if ($platform === 'twitter') {
            return self::host_equals_or_subdomain_of($host, 'x.com')
                || self::host_equals_or_subdomain_of($host, 'twitter.com');
        }

        $platform_data = PlatformRegistry::get($platform);
        if (!is_array($platform_data)) {
            return false;
        }
        $pattern = (string)($platform_data['profile_url_pattern'] ?? '');
        if ($pattern === '') {
            return false;
        }

        $p_parts     = parse_url($pattern);
        $raw_pat     = strtolower((string)($p_parts['host'] ?? ''));
        // Strip leading www. from both sides to get comparable root domains
        $root_domain = preg_replace('/^www\\./', '', $raw_pat);
        $url_host    = preg_replace('/^www\\./', '', $host);

        return $root_domain !== '' && self::host_equals_or_subdomain_of($url_host, $root_domain);
    }

    /**
     * Strict host matching helper.
     *
     * Returns true only when $host is EXACTLY $root_domain or is a true
     * subdomain of it (i.e. ends with '.' . $root_domain).
     *
     * This prevents substring-based lookalike attacks:
     *   host_equals_or_subdomain_of('evilfansly.com', 'fansly.com')  → false
     *   host_equals_or_subdomain_of('notstripchat.com', 'stripchat.com') → false
     *   host_equals_or_subdomain_of('foo.carrd.co.evil.com', 'carrd.co') → false
     *   host_equals_or_subdomain_of('fansly.com', 'fansly.com')        → true
     *   host_equals_or_subdomain_of('sub.fansly.com', 'fansly.com')    → true
     *   host_equals_or_subdomain_of('es.stripchat.com', 'stripchat.com') → true
     */
    private static function host_equals_or_subdomain_of(string $host, string $root): bool {
        $host = strtolower($host);
        $root = strtolower($root);

        if ($host === $root) {
            return true;
        }

        $suffix = '.' . $root;
        return substr($host, -strlen($suffix)) === $suffix;
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
