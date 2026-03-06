<?php
namespace TMWSEO\Engine\Admin;

if (!defined('ABSPATH')) { exit; }

class Editor_AI_Metabox {

    public static function init(): void {
        add_action('add_meta_boxes', [__CLASS__, 'register_metabox']);
    }

    public static function register_metabox(): void {
        $screens = ['model', 'post', 'tmw_category_page'];

        foreach ($screens as $screen) {
            add_meta_box(
                'tmwseo_ai_generate',
                __('TMW AI Generator', 'tmwseo'),
                [__CLASS__, 'render'],
                $screen,
                'side',
                'high'
            );
        }
    }

    public static function render($post): void {
        $pack_raw = get_post_meta($post->ID, '_tmwseo_keyword_pack', true);
        $pack = [];
        if (is_string($pack_raw) && $pack_raw !== '') {
            $decoded = json_decode($pack_raw, true);
            if (is_array($decoded)) $pack = $decoded;
        } elseif (is_array($pack_raw)) {
            $pack = $pack_raw;
        }

        $additional = is_array($pack['additional'] ?? null) ? array_values(array_filter(array_map('strval', $pack['additional']))) : [];
        $longtail   = is_array($pack['longtail'] ?? null) ? array_values(array_filter(array_map('strval', $pack['longtail']))) : [];

        $has_openai = class_exists('TMWSEO\\Engine\\Services\\OpenAI') && \TMWSEO\Engine\Services\OpenAI::is_configured();

        echo '<p><strong>' . esc_html__('TMW SEO Engine', 'tmwseo') . '</strong></p>';
        echo '<p style="margin-top:0">' . esc_html__('Generate RankMath fields + intro/bio/FAQ using Template or OpenAI.', 'tmwseo') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="tmwseo_optimize_post_now">';
        echo '<input type="hidden" name="post_id" value="' . esc_attr($post->ID) . '">';
        wp_nonce_field('tmwseo_optimize_post_' . $post->ID);

        echo '<p><label style="font-weight:600">' . esc_html__('Strategy', 'tmwseo') . '</label><br>';
        echo '<select name="strategy" style="width:100%">';
        echo '<option value="template">' . esc_html__('Template', 'tmwseo') . '</option>';
        if ($has_openai) {
            echo '<option value="openai" selected>' . esc_html__('OpenAI (if configured)', 'tmwseo') . '</option>';
        } else {
            echo '<option value="openai">' . esc_html__('OpenAI (not configured)', 'tmwseo') . '</option>';
        }
        echo '</select></p>';

        echo '<p><label><input type="checkbox" name="insert_block" value="1" checked> ' . esc_html__('Insert content block', 'tmwseo') . '</label></p>';

        echo '<p style="margin:0"><button type="submit" class="button button-primary" style="width:100%">' . esc_html__('Generate', 'tmwseo') . '</button></p>';
        echo '<p style="margin:8px 0 0; font-size:12px; opacity:.85">' . esc_html__('This runs in the background. After a few seconds, refresh the editor to see the updated content & RankMath fields.', 'tmwseo') . '</p>';
        echo '</form>';

        if (!empty($additional) || !empty($longtail)) {
            echo '<hr style="margin:12px 0">';
            echo '<p style="margin:0 0 6px"><strong>' . esc_html__('Selected Keywords', 'tmwseo') . '</strong></p>';
            echo '<div style="max-height:160px; overflow:auto; border:1px solid #ddd; padding:8px; background:#fff">';
            echo '<ul style="margin:0 0 0 18px">';
            foreach (array_slice($additional, 0, 10) as $kw) {
                echo '<li>' . esc_html($kw) . '</li>';
            }
            if (!empty($longtail)) {
                foreach (array_slice($longtail, 0, 6) as $kw) {
                    echo '<li style="opacity:.85">' . esc_html($kw) . '</li>';
                }
            }
            echo '</ul>';
            echo '</div>';
        }
    }
}
