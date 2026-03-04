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
        $optimize_url = wp_nonce_url(
            admin_url('admin-post.php?action=tmwseo_optimize_post_now&post_id=' . $post->ID),
            'tmwseo_optimize_post_' . $post->ID
        );

        echo '<p><strong>' . esc_html__('AI Content Optimization', 'tmwseo') . '</strong></p>';
        echo '<p><a class="button button-primary" href="' . esc_url($optimize_url) . '">' . esc_html__('Generate / Refresh AI', 'tmwseo') . '</a></p>';
    }
}
