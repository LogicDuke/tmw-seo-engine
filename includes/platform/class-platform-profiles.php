<?php
namespace TMWSEO\Engine\Platform;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class PlatformProfiles {

    private static array $platforms = [
        'livejasmin' => 'LiveJasmin',
        'stripchat' => 'Stripchat',
        'chaturbate' => 'Chaturbate',
        'myfreecams' => 'MyFreeCams',
        'camsoda' => 'CamSoda',
        'bonga' => 'BongaCams',
        'cam4' => 'Cam4',
    ];

    public static function init(): void {
        add_shortcode('tmw_model_links', [__CLASS__, 'shortcode_model_links']);

        add_action('add_meta_boxes', [__CLASS__, 'register_metabox']);
        add_action('save_post_model', [__CLASS__, 'save_metabox'], 10, 2);
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

        echo "<p style=\"margin-top:0\">Add your model's profile URLs on other platforms. Used for multi-platform linking.</p>";

        foreach (self::$platforms as $key => $label) {
            $val = (string) get_post_meta($post->ID, '_tmwseo_platform_' . $key, true);
            echo '<p><label style="font-weight:600">' . esc_html($label) . '</label><br>';
            echo '<input type="url" style="width:100%" name="tmwseo_platform[' . esc_attr($key) . ']" value="' . esc_attr($val) . '" placeholder="https://..." /></p>';
        }

        $primary = (string) get_post_meta($post->ID, '_tmwseo_platform_primary', true);
        echo '<p><label style="font-weight:600">Primary platform</label><br>';
        echo '<select name="tmwseo_platform_primary" style="width:100%">';
        echo '<option value="">— none —</option>';
        foreach (self::$platforms as $key => $label) {
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

        $platforms = $_POST['tmwseo_platform'] ?? [];
        if (!is_array($platforms)) $platforms = [];

        foreach (self::$platforms as $key => $label) {
            $val = isset($platforms[$key]) ? esc_url_raw((string)$platforms[$key]) : '';
            update_post_meta($post_id, '_tmwseo_platform_' . $key, $val);
        }

        $primary = isset($_POST['tmwseo_platform_primary']) ? sanitize_text_field((string)$_POST['tmwseo_platform_primary']) : '';
        update_post_meta($post_id, '_tmwseo_platform_primary', $primary);

        self::sync_to_table($post_id);

        Logs::info('platform', 'Saved platform profiles', ['model_id' => $post_id]);
    }

    public static function sync_to_table(int $model_id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_platform_profiles';

        // Clear existing
        $wpdb->delete($table, ['model_id' => $model_id]);

        $primary = (string) get_post_meta($model_id, '_tmwseo_platform_primary', true);

        foreach (self::$platforms as $key => $label) {
            $url = (string) get_post_meta($model_id, '_tmwseo_platform_' . $key, true);
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
        return $rows;
    }

    public static function shortcode_model_links($atts): string {
        $atts = shortcode_atts([
            'model_id' => 0,
        ], $atts, 'tmw_model_links');

        $model_id = (int)($atts['model_id'] ?: get_the_ID());
        if ($model_id <= 0) return '';

        $links = self::get_links($model_id);
        if (empty($links)) return '';

        $out = '<div class="tmw-model-links">';
        $out .= '<ul>';
        foreach ($links as $l) {
            $platform = (string)($l['platform'] ?? '');
            $url = (string)($l['profile_url'] ?? '');
            if ($platform === '' || $url === '') continue;

            $label = self::$platforms[$platform] ?? ucfirst($platform);
            $out .= '<li><a href="' . esc_url($url) . '" rel="nofollow" target="_blank">' . esc_html($label) . '</a></li>';
        }
        $out .= '</ul></div>';

        return $out;
    }
}
