<?php
namespace TMWSEO\Engine\Admin;

if (!defined('ABSPATH')) { exit; }

class Editor_AI_Metabox {

    public static function init(): void {
        add_action('add_meta_boxes', [__CLASS__, 'register_metabox']);
        add_action('enqueue_block_editor_assets', [__CLASS__, 'enqueue_editor_assets']);
        add_action('save_post', [__CLASS__, 'save_metabox']);
    }

    public static function enqueue_editor_assets(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->post_type, ['model', 'post', 'tmw_category_page'], true)) {
            return;
        }

        wp_enqueue_script(
            'tmwseo-editor-ai-metabox',
            TMWSEO_ENGINE_URL . 'assets/js/editor-ai-metabox.js',
            ['wp-data', 'wp-notices', 'wp-edit-post'],
            defined('TMWSEO_ENGINE_VERSION') ? TMWSEO_ENGINE_VERSION : null,
            true
        );
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
        wp_nonce_field('tmwseo_editor_ai_metabox_' . $post->ID, 'tmwseo_editor_ai_metabox_nonce');

        $pack_raw = get_post_meta($post->ID, '_tmwseo_keyword_pack', true);
        $ready_to_index = (string) get_post_meta($post->ID, '_tmwseo_ready_to_index', true) === '1';
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

        $quality_score = (int) get_post_meta($post->ID, '_tmwseo_quality_score', true);
        $quality_warning = (string) get_post_meta($post->ID, '_tmwseo_quality_warning', true) === '1';

        echo '<p><strong>' . esc_html__('TMW SEO Engine', 'tmwseo') . '</strong></p>';
        echo '<p style="margin-top:0">' . esc_html__('Generate RankMath fields + intro/bio/FAQ using Template or OpenAI.', 'tmwseo') . '</p>';

        echo '<p><label style="font-weight:600">' . esc_html__('Strategy', 'tmwseo') . '</label><br>';
        echo '<select id="tmwseo-generate-strategy" style="width:100%">';
        echo '<option value="template">' . esc_html__('Template', 'tmwseo') . '</option>';
        if ($has_openai) {
            echo '<option value="openai" selected>' . esc_html__('OpenAI (if configured)', 'tmwseo') . '</option>';
        } else {
            echo '<option value="openai">' . esc_html__('OpenAI (not configured)', 'tmwseo') . '</option>';
        }
        echo '</select></p>';

        echo '<p><label><input type="checkbox" id="tmwseo-generate-insert-block" value="1" checked> ' . esc_html__('Insert content block', 'tmwseo') . '</label></p>';
        echo '<p><label><input type="checkbox" name="_tmwseo_ready_to_index" value="1" ' . checked($ready_to_index, true, false) . '> ' . esc_html__('Ready to index', 'tmwseo') . '</label></p>';

        echo '<p style="margin:0"><button '
            . 'type="button" '
            . 'id="tmwseo-generate-btn" '
            . 'class="button button-primary" '
            . 'style="width:100%" '
            . 'data-post-id="' . esc_attr((string)$post->ID) . '" '
            . 'data-nonce="' . esc_attr(wp_create_nonce('tmwseo_generate_' . $post->ID)) . '" '
            . 'data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '"'
            . '>' . esc_html__('Generate', 'tmwseo') . '</button></p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:8px 0 0">';
        wp_nonce_field('tmwseo_refresh_keywords_' . $post->ID);
        echo '<input type="hidden" name="action" value="tmwseo_refresh_keywords_now">';
        echo '<input type="hidden" name="post_id" value="' . esc_attr((string)$post->ID) . '">';
        echo '<button type="submit" class="button" style="width:100%">' . esc_html__('Refresh Keywords', 'tmwseo') . '</button>';
        echo '</form>';
        echo '<p style="margin:8px 0 0; font-size:12px; opacity:.85">' . esc_html__('This runs in the background. After a few seconds, refresh the editor to see the updated content & RankMath fields.', 'tmwseo') . '</p>';


        if ($quality_score > 0) {
            echo '<hr style="margin:12px 0">';
            echo '<p style="margin:0 0 6px"><strong>' . esc_html__('SEO Quality Score', 'tmwseo') . '</strong>: ' . esc_html((string) $quality_score) . '/100</p>';
            if ($quality_warning) {
                echo '<p style="margin:0; color:#b32d2e; font-weight:600">' . esc_html__('This draft may need improvement before publishing.', 'tmwseo') . '</p>';
            } else {
                echo '<p style="margin:0; color:#0a7a2f; font-weight:600">' . esc_html__('Draft quality looks good. Publishing is still your decision.', 'tmwseo') . '</p>';
            }
        }

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

    public static function save_metabox(int $post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;

        $post_type = get_post_type($post_id);
        if (!in_array($post_type, ['model', 'post', 'tmw_category_page'], true)) return;

        $nonce = $_POST['tmwseo_editor_ai_metabox_nonce'] ?? '';
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'tmwseo_editor_ai_metabox_' . $post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['_tmwseo_ready_to_index']) && (string) $_POST['_tmwseo_ready_to_index'] === '1') {
            update_post_meta($post_id, '_tmwseo_ready_to_index', '1');
            return;
        }

        delete_post_meta($post_id, '_tmwseo_ready_to_index');
    }
}
