<?php
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Keywords\UnifiedKeywordWorkflowService;
use TMWSEO\Engine\Content\AssistedDraftEnrichmentService;

if (!defined('ABSPATH')) { exit; }

class Editor_AI_Metabox {

    public static function init(): void {
        add_action('add_meta_boxes', [__CLASS__, 'register_metabox']);
        add_action('enqueue_block_editor_assets', [__CLASS__, 'enqueue_editor_assets']);
        add_action('save_post', [__CLASS__, 'save_metabox']);
        add_action('admin_post_tmwseo_generate_draft_content_preview', [__CLASS__, 'handle_generate_draft_content_preview']);
        add_action('admin_post_tmwseo_apply_draft_content_preview', [__CLASS__, 'handle_apply_draft_content_preview']);
        add_action('admin_post_tmwseo_prepare_draft_review_bundle', [__CLASS__, 'handle_prepare_draft_review_bundle']);
        add_action('admin_post_tmwseo_export_draft_review_handoff', [__CLASS__, 'handle_export_draft_review_handoff']);
        add_action('admin_post_tmwseo_update_draft_review_signoff', [__CLASS__, 'handle_update_draft_review_signoff']);
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

        $ready_to_index = (string) get_post_meta($post->ID, '_tmwseo_ready_to_index', true) === '1';
        $pack = UnifiedKeywordWorkflowService::get_pack_with_legacy_fallback((int) $post->ID);

        $additional = is_array($pack['additional'] ?? null) ? array_values(array_filter(array_map('strval', $pack['additional']))) : [];
        $longtail   = is_array($pack['longtail'] ?? null) ? array_values(array_filter(array_map('strval', $pack['longtail']))) : [];

        $has_openai = class_exists('TMWSEO\\Engine\\Services\\OpenAI') && \TMWSEO\Engine\Services\OpenAI::is_configured();

        $quality_score = (int) get_post_meta($post->ID, '_tmwseo_quality_score', true);
        $quality_warning = (string) get_post_meta($post->ID, '_tmwseo_quality_warning', true) === '1';

        // ── Scoped metabox CSS ────────────────────────────────────────────────
        echo '<style>
.tmwseo-mb { font-size: 13px; }
.tmwseo-mb-zone {
    margin-bottom: 14px;
}
.tmwseo-mb-zone-title {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #50575e;
    margin: 0 0 8px;
    padding-bottom: 5px;
    border-bottom: 1px solid #e5e7eb;
}
.tmwseo-mb-trust {
    font-size: 11px;
    color: #166534;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 4px;
    padding: 5px 8px;
    margin: 0 0 14px;
    line-height: 1.4;
}
.tmwseo-mb-quality {
    border-radius: 6px;
    padding: 8px 10px;
    margin-bottom: 14px;
    font-size: 12px;
}
.tmwseo-mb-quality-ok  { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }
.tmwseo-mb-quality-warn{ background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
.tmwseo-mb-quality strong { display:block; margin-bottom:2px; font-size:13px; }
.tmwseo-mb-field { margin-bottom: 10px; }
.tmwseo-mb-field label { font-weight: 600; display: block; margin-bottom: 3px; }
.tmwseo-mb-field select,
.tmwseo-mb-field textarea { width: 100%; margin-top: 2px; }
.tmwseo-mb-btn-stack { display: flex; flex-direction: column; gap: 5px; }
.tmwseo-mb-btn-stack .button { margin: 0 !important; width: 100%; text-align: center; box-sizing: border-box; }
.tmwseo-mb-btn-stack form { margin: 0; }
.tmwseo-mb-rollback-btn { border-color: #c0392b !important; color: #c0392b !important; }
.tmwseo-mb-help {
    font-size: 11px;
    color: #6b7280;
    margin: 6px 0 0;
    line-height: 1.45;
}
.tmwseo-mb-kw-list {
    max-height: 140px;
    overflow: auto;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    padding: 6px 8px;
    background: #fff;
    margin-top: 6px;
}
.tmwseo-mb-kw-list ul { margin: 0 0 0 16px; padding: 0; }
.tmwseo-mb-kw-list li { font-size: 12px; line-height: 1.45; margin-bottom: 2px; }
.tmwseo-mb-kw-list li.tmwseo-mb-kw-lt { opacity: .75; }
.tmwseo-mb-divider { border: none; border-top: 1px solid #e5e7eb; margin: 14px 0; }
/* preview + signoff panels */
.tmwseo-mb-panel {
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 10px;
    background: #fafafa;
    margin-bottom: 10px;
    font-size: 12px;
}
.tmwseo-mb-panel-title {
    font-weight: 700;
    font-size: 12px;
    margin: 0 0 6px;
    color: #1e293b;
}
.tmwseo-mb-panel-meta { color: #6b7280; margin: 0 0 6px; line-height: 1.4; }
.tmwseo-mb-panel-row { margin: 0 0 5px; }
.tmwseo-mb-info-box {
    border: 1px solid #bfdbfe;
    background: #eff6ff;
    border-radius: 5px;
    padding: 8px 10px;
    margin-bottom: 8px;
    font-size: 12px;
}
.tmwseo-mb-info-box p { margin: 0 0 4px; }
.tmwseo-mb-info-box p:last-child { margin: 0; }
.tmwseo-mb-handoff-box {
    border: 1px solid #e5e7eb;
    background: #f8faff;
    border-radius: 5px;
    padding: 8px 10px;
    margin-bottom: 8px;
    font-size: 12px;
}
.tmwseo-mb-apply-fields {
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    background: #fff;
    padding: 8px;
    max-height: 160px;
    overflow: auto;
    margin-bottom: 8px;
}
.tmwseo-mb-apply-fields p { margin: 0 0 5px; }
.tmwseo-mb-preset-scope {
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    background: #fff;
    padding: 8px;
    margin-bottom: 8px;
    font-size: 12px;
}
.tmwseo-mb-preset-scope p { margin: 0 0 4px; }
.tmwseo-mb-rec-box {
    border: 1px solid #e5e7eb;
    background: #f6f7f7;
    border-radius: 4px;
    padding: 8px;
    margin-bottom: 8px;
    font-size: 12px;
}
.tmwseo-mb-rec-box p { margin: 0 0 4px; }
.tmwseo-mb-signoff-status {
    border: 1px solid #e5e7eb;
    background: #f6f7f7;
    border-radius: 4px;
    padding: 8px;
    margin-bottom: 8px;
    font-size: 12px;
}
.tmwseo-mb-signoff-status p { margin: 0 0 3px; }
.tmwseo-mb-checklist {
    border: 1px solid #e5e7eb;
    background: #fff;
    border-radius: 4px;
    padding: 8px;
    margin-bottom: 8px;
    font-size: 12px;
}
.tmwseo-mb-checklist p { margin: 0 0 6px; }
.tmwseo-mb-review-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 5px;
    margin-bottom: 8px;
}
.tmwseo-mb-review-actions .button { margin: 0 !important; text-align: center; }
</style>';

        echo '<div class="tmwseo-mb">';

        // ── Zone 1: Trust / status reminder ──────────────────────────────────
        echo '<div class="tmwseo-mb-trust">&#128274; ' . esc_html__('Manual-only · No auto-publish · Draft-only', 'tmwseo') . '</div>';

        // ── Zone 2: Quality score (if present) ───────────────────────────────
        if ($quality_score > 0) {
            $q_class = $quality_warning ? 'tmwseo-mb-quality-warn' : 'tmwseo-mb-quality-ok';
            echo '<div class="tmwseo-mb-quality ' . esc_attr($q_class) . '">';
            echo '<strong>' . esc_html__('SEO Quality Score', 'tmwseo') . ': ' . esc_html((string) $quality_score) . '/100</strong>';
            if ($quality_warning) {
                echo esc_html__('This draft may need improvement before publishing.', 'tmwseo');
            } else {
                echo esc_html__('Draft quality looks good. Publishing is still your decision.', 'tmwseo');
            }
            echo '</div>';
        }

        // ── Zone 3: Generate ─────────────────────────────────────────────────
        echo '<div class="tmwseo-mb-zone">';
        echo '<p class="tmwseo-mb-zone-title">' . esc_html__('Generate', 'tmwseo') . '</p>';

        echo '<div class="tmwseo-mb-field">';
        echo '<label for="tmwseo-generate-strategy">' . esc_html__('Strategy', 'tmwseo') . '</label>';
        echo '<select id="tmwseo-generate-strategy" style="width:100%">';
        echo '<option value="template">' . esc_html__('Template', 'tmwseo') . '</option>';
        if ($has_openai) {
            echo '<option value="openai" selected>' . esc_html__('OpenAI (if configured)', 'tmwseo') . '</option>';
        } else {
            echo '<option value="openai">' . esc_html__('OpenAI (not configured)', 'tmwseo') . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<p style="margin:0 0 8px"><label><input type="checkbox" id="tmwseo-generate-insert-block" value="1" checked> ' . esc_html__('Insert content block', 'tmwseo') . '</label></p>';

        echo '<div class="tmwseo-mb-btn-stack">';
        echo '<button '
            . 'type="button" '
            . 'id="tmwseo-generate-btn" '
            . 'class="button button-primary" '
            . 'data-post-id="' . esc_attr((string)$post->ID) . '" '
            . 'data-nonce="' . esc_attr(wp_create_nonce('tmwseo_generate_' . $post->ID)) . '" '
            . 'data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '"'
            . '>' . esc_html__('Generate', 'tmwseo') . '</button>';
        echo '</div>';

        echo '<p style="margin:8px 0 0"><label><input type="checkbox" name="_tmwseo_ready_to_index" value="1" ' . checked($ready_to_index, true, false) . '> ' . esc_html__('Ready to index', 'tmwseo') . '</label></p>';
        echo '</div>'; // .tmwseo-mb-zone (generate)

        // ── Zone 4: Maintenance (Rollback + Refresh Keywords) ────────────────
        $has_rollback = \TMWSEO\Engine\Model\Rollback::has_snapshot((int)$post->ID);
        echo '<div class="tmwseo-mb-zone">';
        echo '<p class="tmwseo-mb-zone-title">' . esc_html__('Maintenance', 'tmwseo') . '</p>';
        echo '<div class="tmwseo-mb-btn-stack">';

        // Refresh Keywords
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_refresh_keywords_' . $post->ID);
        echo '<input type="hidden" name="action" value="tmwseo_refresh_keywords_now">';
        echo '<input type="hidden" name="post_id" value="' . esc_attr((string)$post->ID) . '">';
        echo '<button type="submit" class="button">' . esc_html__('Refresh Keywords', 'tmwseo') . '</button>';
        echo '</form>';

        // Rollback (only if snapshot exists)
        if ($has_rollback) {
            $snap_time = \TMWSEO\Engine\Model\Rollback::snapshot_time((int)$post->ID);
            echo '<button '
                . 'type="button" '
                . 'id="tmwseo-rollback-btn" '
                . 'class="button tmwseo-mb-rollback-btn" '
                . 'data-post-id="' . esc_attr((string)$post->ID) . '" '
                . 'data-nonce="' . esc_attr(wp_create_nonce('tmwseo_rollback_' . $post->ID)) . '" '
                . 'data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '"'
                . '>' . esc_html__('↩ Rollback to Pre-Generation', 'tmwseo') . '</button>';
            if ($snap_time) {
                echo '<span class="tmwseo-mb-help">'
                    . esc_html__('Snapshot taken: ', 'tmwseo')
                    . esc_html($snap_time)
                    . '</span>';
            }
        }

        echo '</div>'; // .tmwseo-mb-btn-stack
        echo '<p class="tmwseo-mb-help">' . esc_html__('Refresh Keywords runs in the background. Reload the editor after a few seconds to see updated fields.', 'tmwseo') . '</p>';
        echo '</div>'; // .tmwseo-mb-zone (maintenance)

        // Rollback JS (unchanged logic, moved below the button it targets)
        if ($has_rollback) {
            echo '<script>
(function(){
    var btn = document.getElementById("tmwseo-rollback-btn");
    if (!btn) return;
    btn.addEventListener("click", function(){
        if (!confirm("' . esc_js(__('Are you sure? This will restore the RankMath title, description, focus keyword, and post title to their values before the last Generate.', 'tmwseo')) . '")) return;
        btn.disabled = true;
        btn.textContent = "Rolling back...";
        jQuery.post(btn.dataset.ajaxUrl, {
            action: "tmwseo_rollback",
            post_id: btn.dataset.postId,
            nonce: btn.dataset.nonce
        }, function(r){
            if (r && r.success) {
                alert("Rollback complete: " + r.data.message);
                window.location.reload();
            } else {
                alert("Rollback failed: " + (r && r.data ? r.data.message : "Unknown error"));
                btn.disabled = false;
                btn.textContent = "↩ Rollback to Pre-Generation";
            }
        });
    });
})();
</script>';
        }

        // ── Zone 5: Assisted Draft review (draft posts only) ─────────────────
        if ((string) $post->post_status === 'draft') {
            echo '<hr class="tmwseo-mb-divider">';
            echo '<div class="tmwseo-mb-zone">';
            echo '<p class="tmwseo-mb-zone-title">' . esc_html__('Assisted Draft', 'tmwseo') . '</p>';
            echo '<p class="tmwseo-mb-help" style="margin-bottom:8px">' . esc_html__('Preview-only assist for explicit drafts. Stores proposed SEO/content in preview metadata only. Never auto-publishes, never writes post content.', 'tmwseo') . '</p>';

            echo '<div class="tmwseo-mb-btn-stack">';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('tmwseo_generate_draft_content_preview_' . $post->ID);
            echo '<input type="hidden" name="action" value="tmwseo_generate_draft_content_preview">';
            echo '<input type="hidden" name="post_id" value="' . esc_attr((string) $post->ID) . '">';
            echo '<button type="submit" class="button button-primary">' . esc_html__('Generate Draft Content Preview', 'tmwseo') . '</button>';
            echo '</form>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('tmwseo_prepare_draft_review_bundle_' . $post->ID);
            echo '<input type="hidden" name="action" value="tmwseo_prepare_draft_review_bundle">';
            echo '<input type="hidden" name="post_id" value="' . esc_attr((string) $post->ID) . '">';
            echo '<button type="submit" class="button button-secondary">' . esc_html__('Prepare for Human Review', 'tmwseo') . '</button>';
            echo '</form>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('tmwseo_export_draft_review_handoff_' . $post->ID);
            echo '<input type="hidden" name="action" value="tmwseo_export_draft_review_handoff">';
            echo '<input type="hidden" name="post_id" value="' . esc_attr((string) $post->ID) . '">';
            echo '<button type="submit" class="button">' . esc_html__('Export Review Handoff', 'tmwseo') . '</button>';
            echo '</form>';

            echo '</div>'; // .tmwseo-mb-btn-stack
            echo '</div>'; // .tmwseo-mb-zone (assisted draft)

            self::render_preview_panel((int) $post->ID);
            self::render_reviewer_signoff_panel((int) $post->ID, (string) $post->post_type);
        }

        // ── Zone 6: Keyword reference ─────────────────────────────────────────
        if (!empty($additional) || !empty($longtail)) {
            echo '<hr class="tmwseo-mb-divider">';
            echo '<div class="tmwseo-mb-zone">';
            echo '<p class="tmwseo-mb-zone-title">' . esc_html__('Selected Keywords', 'tmwseo') . '</p>';
            echo '<div class="tmwseo-mb-kw-list">';
            echo '<ul>';
            foreach (array_slice($additional, 0, 10) as $kw) {
                echo '<li>' . esc_html($kw) . '</li>';
            }
            if (!empty($longtail)) {
                foreach (array_slice($longtail, 0, 6) as $kw) {
                    echo '<li class="tmwseo-mb-kw-lt">' . esc_html($kw) . '</li>';
                }
            }
            echo '</ul>';
            echo '</div>';
            echo '</div>'; // .tmwseo-mb-zone (keywords)
        }

        echo '</div>'; // .tmwseo-mb
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

    public static function handle_generate_draft_content_preview(): void {
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if ($post_id <= 0) {
            wp_die('Missing post ID');
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_generate_draft_content_preview_' . $post_id);

        $result = AssistedDraftEnrichmentService::generate_preview_for_explicit_draft($post_id);
        $notice = !empty($result['ok']) ? 'draft_preview_generated' : 'draft_preview_refused';
        $reason = sanitize_key((string) ($result['reason'] ?? ''));
        $strategy = sanitize_key((string) ($result['strategy'] ?? ''));

        $redirect = add_query_arg([
            'post' => $post_id,
            'action' => 'edit',
            'tmwseo_notice' => $notice,
            'reason' => $reason,
            'preview_strategy' => $strategy,
        ], admin_url('post.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    public static function handle_apply_draft_content_preview(): void {
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if ($post_id <= 0) {
            wp_die('Missing post ID');
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_apply_draft_content_preview_' . $post_id);

        $requested_fields = isset($_POST['tmwseo_apply_preview_fields']) && is_array($_POST['tmwseo_apply_preview_fields'])
            ? array_values(array_map('strval', wp_unslash($_POST['tmwseo_apply_preview_fields'])))
            : [];

        $template_type = sanitize_key((string) get_post_meta($post_id, '_tmwseo_preview_template_type', true));
        if ($template_type === '') {
            $template_type = 'generic_post';
        }

        $requested_preset = isset($_POST['tmwseo_apply_preview_preset']) ? sanitize_key((string) wp_unslash($_POST['tmwseo_apply_preview_preset'])) : '';
        $resolved = AssistedDraftEnrichmentService::resolve_preview_apply_fields($requested_fields, $template_type, $requested_preset);

        $result = AssistedDraftEnrichmentService::apply_reviewed_preview_to_explicit_draft(
            $post_id,
            (array) ($resolved['fields'] ?? []),
            (string) ($resolved['preset_key'] ?? '')
        );
        $notice = !empty($result['ok']) ? 'draft_preview_applied' : 'draft_preview_apply_refused';
        $reason = sanitize_key((string) ($result['reason'] ?? ''));

        $redirect = add_query_arg([
            'post' => $post_id,
            'action' => 'edit',
            'tmwseo_notice' => $notice,
            'reason' => $reason,
        ], admin_url('post.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    public static function handle_prepare_draft_review_bundle(): void {
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if ($post_id <= 0) {
            wp_die('Missing post ID');
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_prepare_draft_review_bundle_' . $post_id);

        $template_type = sanitize_key((string) get_post_meta($post_id, '_tmwseo_preview_template_type', true));
        if ($template_type === '') {
            $template_type = sanitize_key((string) get_post_meta($post_id, '_tmwseo_suggestion_destination_type', true));
        }
        if ($template_type === '') {
            $template_type = 'generic_post';
        }

        $result = AssistedDraftEnrichmentService::prepare_review_bundle_for_explicit_draft($post_id, [
            'destination_type' => $template_type,
        ]);
        $notice = !empty($result['ok']) ? 'review_bundle_prepared' : 'review_bundle_refused';
        $reason = sanitize_key((string) ($result['reason'] ?? ''));

        $redirect = add_query_arg([
            'post' => $post_id,
            'action' => 'edit',
            'tmwseo_notice' => $notice,
            'reason' => $reason,
        ], admin_url('post.php'));

        wp_safe_redirect($redirect);
        exit;
    }


    public static function handle_export_draft_review_handoff(): void {
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if ($post_id <= 0) {
            wp_die('Missing post ID');
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_export_draft_review_handoff_' . $post_id);

        $template_type = sanitize_key((string) get_post_meta($post_id, '_tmwseo_preview_template_type', true));
        if ($template_type === '') {
            $template_type = sanitize_key((string) get_post_meta($post_id, '_tmwseo_suggestion_destination_type', true));
        }
        if ($template_type === '') {
            $template_type = 'generic_post';
        }

        $result = AssistedDraftEnrichmentService::export_review_handoff_for_explicit_draft($post_id, [
            'destination_type' => $template_type,
        ]);
        $notice = !empty($result['ok']) ? 'review_handoff_exported' : 'review_handoff_export_refused';
        $reason = sanitize_key((string) ($result['reason'] ?? ''));

        $redirect = add_query_arg([
            'post' => $post_id,
            'action' => 'edit',
            'tmwseo_notice' => $notice,
            'reason' => $reason,
        ], admin_url('post.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    public static function handle_update_draft_review_signoff(): void {
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if ($post_id <= 0) {
            wp_die('Missing post ID');
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_update_draft_review_signoff_' . $post_id);

        $action_key = isset($_POST['tmwseo_review_action']) ? sanitize_key((string) wp_unslash($_POST['tmwseo_review_action'])) : 'save_review_state';
        $state = isset($_POST['tmwseo_review_state']) ? sanitize_key((string) wp_unslash($_POST['tmwseo_review_state'])) : 'not_reviewed';
        $review_notes = isset($_POST['tmwseo_review_notes']) ? (string) wp_unslash($_POST['tmwseo_review_notes']) : '';

        $raw_checklist = isset($_POST['tmwseo_review_checklist']) && is_array($_POST['tmwseo_review_checklist'])
            ? (array) wp_unslash($_POST['tmwseo_review_checklist'])
            : [];
        $checklist = [];
        foreach ($raw_checklist as $item_key => $item_value) {
            $safe_key = sanitize_key((string) $item_key);
            if ($safe_key === '') {
                continue;
            }

            $checklist[$safe_key] = (string) $item_value === '1';
        }

        $result = AssistedDraftEnrichmentService::update_reviewer_signoff_for_explicit_draft($post_id, [
            'action' => $action_key,
            'state' => $state,
            'checklist' => $checklist,
            'review_notes' => $review_notes,
        ]);

        $notice = !empty($result['ok']) ? 'review_signoff_updated' : 'review_signoff_refused';
        $reason = sanitize_key((string) ($result['reason'] ?? ''));

        $redirect = add_query_arg([
            'post' => $post_id,
            'action' => 'edit',
            'tmwseo_notice' => $notice,
            'reason' => $reason,
        ], admin_url('post.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    private static function render_preview_panel(int $post_id): void {
        $keys = AssistedDraftEnrichmentService::preview_meta_keys();
        $seo_title = (string) get_post_meta($post_id, $keys['seo_title'], true);
        $meta_desc = (string) get_post_meta($post_id, $keys['meta_description'], true);
        $focus_kw = (string) get_post_meta($post_id, $keys['focus_keyword'], true);
        $outline = (string) get_post_meta($post_id, $keys['outline'], true);
        $content_html = (string) get_post_meta($post_id, $keys['content_html'], true);
        $strategy = (string) get_post_meta($post_id, $keys['strategy'], true);
        $template_type = (string) get_post_meta($post_id, $keys['template_type'], true);
        $generated_at = (string) get_post_meta($post_id, $keys['generated_at'], true);
        $applied_at = (string) get_post_meta($post_id, $keys['applied_at'], true);
        $last_reviewed_at = (string) get_post_meta($post_id, $keys['last_reviewed_at'], true);
        $applied_preset = sanitize_key((string) get_post_meta($post_id, '_tmwseo_preview_apply_preset', true));
        $applied_preset_at = (string) get_post_meta($post_id, '_tmwseo_preview_apply_preset_at', true);

        $applied_fields_raw = (string) get_post_meta($post_id, $keys['applied_fields'], true);
        $applied_fields = json_decode($applied_fields_raw, true);
        $applied_fields = is_array($applied_fields) ? array_values(array_map('strval', $applied_fields)) : [];

        if ($seo_title === '' && $meta_desc === '' && $content_html === '') {
            return;
        }

        echo '<hr class="tmwseo-mb-divider">';

        // ── Preview data card ─────────────────────────────────────────────────
        echo '<div class="tmwseo-mb-panel">';
        echo '<p class="tmwseo-mb-panel-title">' . esc_html__('Draft Content Preview', 'tmwseo') . ' <span style="font-weight:400;opacity:.7">(' . esc_html__('review-only', 'tmwseo') . ')</span></p>';

        if ($strategy !== '' || $generated_at !== '') {
            echo '<p class="tmwseo-mb-panel-meta">';
            echo esc_html__('Strategy:', 'tmwseo') . ' ' . esc_html($strategy !== '' ? $strategy : 'n/a') . ' &middot; ';
            echo esc_html__('Template:', 'tmwseo') . ' ' . esc_html(self::human_preview_template_label($template_type)) . ' &middot; ';
            echo esc_html__('Generated:', 'tmwseo') . ' ' . esc_html($generated_at !== '' ? $generated_at : 'n/a');
            echo '</p>';
        }
        if ($seo_title !== '') {
            echo '<p class="tmwseo-mb-panel-row"><strong>' . esc_html__('Proposed SEO Title:', 'tmwseo') . '</strong> ' . esc_html($seo_title) . '</p>';
        }
        if ($meta_desc !== '') {
            echo '<p class="tmwseo-mb-panel-row"><strong>' . esc_html__('Proposed Meta Description:', 'tmwseo') . '</strong> ' . esc_html($meta_desc) . '</p>';
        }
        if ($focus_kw !== '') {
            echo '<p class="tmwseo-mb-panel-row"><strong>' . esc_html__('Proposed Focus Keyword:', 'tmwseo') . '</strong> ' . esc_html($focus_kw) . '</p>';
        }
        if ($outline !== '') {
            echo '<details style="margin:6px 0"><summary><strong>' . esc_html__('Proposed Outline', 'tmwseo') . '</strong></summary><pre style="white-space:pre-wrap;font-size:11px;margin:6px 0 0">' . esc_html($outline) . '</pre></details>';
        }
        if ($content_html !== '') {
            echo '<details style="margin:6px 0"><summary><strong>' . esc_html__('Proposed Content Preview', 'tmwseo') . '</strong></summary>';
            echo '<div style="max-height:200px;overflow:auto;border:1px solid #e5e7eb;border-radius:4px;padding:8px;background:#fff;margin-top:4px">' . wp_kses_post($content_html) . '</div>';
            echo '</details>';
        }
        if ($applied_at !== '' || $last_reviewed_at !== '') {
            echo '<p class="tmwseo-mb-panel-meta" style="margin-top:8px">';
            if ($applied_at !== '') {
                echo esc_html__('Applied:', 'tmwseo') . ' ' . esc_html($applied_at);
                if (!empty($applied_fields)) {
                    echo ' &middot; ' . esc_html__('Fields:', 'tmwseo') . ' ' . esc_html(implode(', ', $applied_fields));
                }
                if ($applied_preset !== '') {
                    echo ' &middot; ' . esc_html__('Preset:', 'tmwseo') . ' ' . esc_html($applied_preset);
                    if ($applied_preset_at !== '') {
                        echo ' (' . esc_html($applied_preset_at) . ')';
                    }
                }
            }
            if ($last_reviewed_at !== '') {
                if ($applied_at !== '') { echo '<br>'; }
                echo esc_html__('Last reviewed:', 'tmwseo') . ' ' . esc_html($last_reviewed_at);
            }
            echo '</p>';
        }
        echo '</div>'; // .tmwseo-mb-panel

        // ── Review bundle card ────────────────────────────────────────────────
        $review_bundle = AssistedDraftEnrichmentService::get_review_bundle_for_explicit_draft($post_id);
        if (!empty($review_bundle['ok'])) {
            echo '<div class="tmwseo-mb-info-box">';
            echo '<p><strong>' . esc_html__('Prepared for Human Review', 'tmwseo') . '</strong></p>';
            echo '<p><strong>' . esc_html__('Destination:', 'tmwseo') . '</strong> ' . esc_html(self::human_preview_template_label((string) ($review_bundle['destination_type'] ?? ''))) . ' &middot; <strong>' . esc_html__('Template:', 'tmwseo') . '</strong> ' . esc_html(self::human_preview_template_label((string) ($review_bundle['template_type'] ?? ''))) . '</p>';
            echo '<p><strong>' . esc_html__('Readiness:', 'tmwseo') . '</strong> ' . esc_html((string) ($review_bundle['readiness_label'] ?? '')) . ' (' . esc_html((string) ($review_bundle['readiness_score'] ?? 0)) . '/100)</p>';
            echo '<p><strong>' . esc_html__('Recommended preset:', 'tmwseo') . '</strong> ' . esc_html((string) ($review_bundle['recommended_preset_label'] ?? 'n/a')) . '</p>';
            echo '<p><strong>' . esc_html__('Missing pieces:', 'tmwseo') . '</strong> ' . esc_html((string) ($review_bundle['missing_summary'] ?? '')) . '</p>';
            if ((string) ($review_bundle['destination_type'] ?? '') === 'category_page') {
                $category_ready = (array) ($review_bundle['category_readiness'] ?? []);
                echo '<p><strong>' . esc_html__('Category-page readiness:', 'tmwseo') . '</strong> '
                    . esc_html__('SEO metadata', 'tmwseo') . ': ' . esc_html(!empty($category_ready['seo_metadata_ready']) ? 'ready' : 'missing') . ' &middot; '
                    . esc_html__('Outline', 'tmwseo') . ': ' . esc_html(!empty($category_ready['outline_ready']) ? 'ready' : 'missing') . ' &middot; '
                    . esc_html__('Content preview', 'tmwseo') . ': ' . esc_html(!empty($category_ready['content_preview_ready']) ? 'ready' : 'missing')
                    . '</p>';
            }
            echo '<p style="opacity:.85">' . esc_html__('Nothing applied automatically. Draft remains draft-only / noindex. Review and apply manually.', 'tmwseo') . '</p>';
            echo '</div>';
        }

        // ── Review handoff export ─────────────────────────────────────────────
        $review_handoff = AssistedDraftEnrichmentService::get_review_handoff_export_for_explicit_draft($post_id);
        if (!empty($review_handoff['ok'])) {
            echo '<div class="tmwseo-mb-handoff-box">';
            echo '<p class="tmwseo-mb-panel-title">' . esc_html__('Review Handoff Export', 'tmwseo') . '</p>';
            echo '<p style="color:#6b7280">' . esc_html__('Nothing applied automatically. Draft remains draft-only / noindex. Review and apply manually.', 'tmwseo') . '</p>';
            echo '<p><strong>' . esc_html__('Exported:', 'tmwseo') . '</strong> ' . esc_html((string) ($review_handoff['exported_at'] ?? 'n/a')) . '</p>';
            echo '<textarea readonly rows="10" style="width:100%;font-family:monospace;font-size:11px;white-space:pre;border:1px solid #e5e7eb;border-radius:4px">' . esc_textarea((string) ($review_handoff['export_text'] ?? '')) . '</textarea>';
            echo '</div>';
        }

        // ── Apply Reviewed Preview form ───────────────────────────────────────
        $field_labels = AssistedDraftEnrichmentService::preview_apply_field_labels();
        $apply_presets = AssistedDraftEnrichmentService::preview_apply_presets_for_destination($template_type);
        $recommendation = AssistedDraftEnrichmentService::build_review_recommendation_for_explicit_draft($post_id, [
            'destination_type' => $template_type,
        ]);

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_apply_draft_content_preview_' . $post_id);
        echo '<input type="hidden" name="action" value="tmwseo_apply_draft_content_preview">';
        echo '<input type="hidden" name="post_id" value="' . esc_attr((string) $post_id) . '">';

        if (!empty($recommendation['ok'])) {
            echo '<div class="tmwseo-mb-rec-box">';
            echo '<p><strong>' . esc_html__('Recommended preset (advisory):', 'tmwseo') . '</strong> ' . esc_html((string) ($recommendation['recommended_preset_label'] ?? 'n/a')) . '</p>';
            echo '<p><strong>' . esc_html__('Why:', 'tmwseo') . '</strong> ' . esc_html((string) ($recommendation['reason_summary'] ?? '')) . '</p>';
            echo '<p><strong>' . esc_html__('Readiness:', 'tmwseo') . '</strong> ' . esc_html((string) ($recommendation['readiness_label'] ?? '')) . ' (' . esc_html((string) ($recommendation['readiness_score'] ?? 0)) . '/100)</p>';
            echo '<p><strong>' . esc_html__('Missing before apply:', 'tmwseo') . '</strong> ' . esc_html((string) ($recommendation['missing_summary'] ?? '')) . '</p>';
            echo '</div>';
        }

        if (!empty($apply_presets)) {
            echo '<div class="tmwseo-mb-field">';
            echo '<label><strong>' . esc_html__('Apply preset', 'tmwseo') . '</strong>';
            echo '<select name="tmwseo_apply_preview_preset" style="width:100%;margin-top:4px">';
            echo '<option value="">' . esc_html__('Manual field selection (no preset)', 'tmwseo') . '</option>';
            foreach ($apply_presets as $preset_key => $preset_meta) {
                echo '<option value="' . esc_attr((string) $preset_key) . '">' . esc_html((string) ($preset_meta['label'] ?? $preset_key)) . '</option>';
            }
            echo '</select></label>';
            echo '</div>';

            echo '<div class="tmwseo-mb-preset-scope">';
            echo '<p style="margin:0 0 4px;font-weight:600">' . esc_html__('Preset scope preview', 'tmwseo') . '</p>';
            foreach ($apply_presets as $preset_meta) {
                $preset_label = (string) ($preset_meta['label'] ?? '');
                $preset_fields = !empty($preset_meta['fields']) && is_array($preset_meta['fields'])
                    ? array_values(array_map('strval', $preset_meta['fields']))
                    : [];
                $field_names = [];
                foreach ($preset_fields as $field) {
                    $field_names[] = (string) ($field_labels[$field] ?? $field);
                }
                echo '<p><strong>' . esc_html($preset_label) . ':</strong> ' . esc_html(implode(', ', $field_names)) . '</p>';
            }
            echo '</div>';
        }

        echo '<div class="tmwseo-mb-apply-fields">';
        foreach ($field_labels as $field => $label) {
            echo '<p><label><input type="checkbox" name="tmwseo_apply_preview_fields[]" value="' . esc_attr($field) . '"> ' . esc_html($label) . '</label></p>';
        }
        echo '</div>';

        echo '<p class="tmwseo-mb-help" style="margin-bottom:8px">' . esc_html__('If a preset is selected, the preset field bundle is used as-is. Manual checkboxes apply only when no preset is selected.', 'tmwseo') . '</p>';
        echo '<button type="submit" class="button button-primary" style="width:100%">' . esc_html__('Apply Reviewed Preview to Draft', 'tmwseo') . '</button>';
        echo '</form>';
        echo '<p class="tmwseo-mb-help">' . esc_html__('Manual operator action only. Applies selected preview fields into this draft only. Never publishes, never mutates live posts, and does not clear noindex.', 'tmwseo') . '</p>';
    }

    private static function render_reviewer_signoff_panel(int $post_id, string $post_type): void {
        $signoff = AssistedDraftEnrichmentService::get_reviewer_signoff_for_explicit_draft($post_id);
        if (empty($signoff['ok'])) {
            return;
        }

        $state = sanitize_key((string) ($signoff['state'] ?? 'not_reviewed'));
        $state_labels = [
            'not_reviewed' => __('Not reviewed', 'tmwseo'),
            'in_review' => __('In review', 'tmwseo'),
            'reviewed_signed_off' => __('Signed off for manual next step', 'tmwseo'),
            'needs_changes' => __('Needs changes', 'tmwseo'),
        ];

        $signed_off_at = (string) ($signoff['signed_off_at'] ?? '');
        $signed_off_by = (int) ($signoff['signed_off_by'] ?? 0);
        $signed_off_user = $signed_off_by > 0 ? get_userdata($signed_off_by) : false;
        $notes = (string) ($signoff['review_notes'] ?? '');
        $last_updated_at = (string) ($signoff['last_updated_at'] ?? '');
        $checklist_items = is_array($signoff['checklist_items'] ?? null) ? $signoff['checklist_items'] : [];
        $checklist = is_array($signoff['checklist'] ?? null) ? $signoff['checklist'] : [];
        $is_category = sanitize_key((string) ($signoff['destination_type'] ?? '')) === 'category_page' || $post_type === 'tmw_category_page';

        echo '<hr class="tmwseo-mb-divider">';
        echo '<div class="tmwseo-mb-zone">';
        echo '<p class="tmwseo-mb-zone-title">' . esc_html__('Reviewer Checklist & Signoff', 'tmwseo') . '</p>';

        echo '<p class="tmwseo-mb-trust" style="margin-bottom:10px">' . esc_html__('Signoff does not publish or apply anything automatically. Draft remains draft-only / noindex.', 'tmwseo') . '</p>';

        // Current signoff status
        echo '<div class="tmwseo-mb-signoff-status">';
        echo '<p><strong>' . esc_html__('Review status:', 'tmwseo') . '</strong> ' . esc_html((string) ($state_labels[$state] ?? $state)) . '</p>';
        if ($last_updated_at !== '') {
            echo '<p><strong>' . esc_html__('Last updated:', 'tmwseo') . '</strong> ' . esc_html($last_updated_at) . '</p>';
        }
        if ($signed_off_at !== '') {
            $reviewer_label = $signed_off_user instanceof \WP_User ? (string) $signed_off_user->display_name : __('Unknown reviewer', 'tmwseo');
            echo '<p><strong>' . esc_html__('Signed off:', 'tmwseo') . '</strong> ' . esc_html($signed_off_at . ' · ' . $reviewer_label) . '</p>';
        }
        echo '</div>';

        if ($is_category) {
            echo '<p class="tmwseo-mb-help" style="color:#0a4b78;margin-bottom:8px"><strong>' . esc_html__('Category-page review path:', 'tmwseo') . '</strong> ' . esc_html__('Confirm SEO metadata readiness, outline readiness, content preview readiness, recommended preset review, and category intent/destination fit before manual next step.', 'tmwseo') . '</p>';
        }

        // Signoff form
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tmwseo_update_draft_review_signoff_' . $post_id);
        echo '<input type="hidden" name="action" value="tmwseo_update_draft_review_signoff">';
        echo '<input type="hidden" name="post_id" value="' . esc_attr((string) $post_id) . '">';

        // Checklist
        if (!empty($checklist_items)) {
            echo '<div class="tmwseo-mb-checklist">';
            echo '<p style="margin:0 0 6px;font-weight:600">' . esc_html__('Reviewer Checklist', 'tmwseo') . '</p>';
            foreach ($checklist_items as $item) {
                $item_key = sanitize_key((string) ($item['key'] ?? ''));
                if ($item_key === '') { continue; }
                $item_label = (string) ($item['label'] ?? $item_key);
                $item_desc = (string) ($item['description'] ?? '');
                $checked = !empty($checklist[$item_key]);
                echo '<p><label><input type="checkbox" name="tmwseo_review_checklist[' . esc_attr($item_key) . ']" value="1" ' . checked($checked, true, false) . '> ' . esc_html($item_label) . '</label>';
                if ($item_desc !== '') {
                    echo '<br><span class="tmwseo-mb-help" style="margin-left:18px;display:inline-block">' . esc_html($item_desc) . '</span>';
                }
                echo '</p>';
            }
            echo '</div>';
        }

        // Reviewer state dropdown
        echo '<div class="tmwseo-mb-field">';
        echo '<label><strong>' . esc_html__('Reviewer state', 'tmwseo') . '</strong>';
        echo '<select name="tmwseo_review_state" style="width:100%;margin-top:4px">';
        foreach ((array) ($signoff['states'] ?? []) as $state_key) {
            $safe_state_key = sanitize_key((string) $state_key);
            echo '<option value="' . esc_attr($safe_state_key) . '" ' . selected($safe_state_key, $state, false) . '>' . esc_html((string) ($state_labels[$safe_state_key] ?? $safe_state_key)) . '</option>';
        }
        echo '</select></label>';
        echo '</div>';

        // Notes textarea
        echo '<div class="tmwseo-mb-field">';
        echo '<label><strong>' . esc_html__('Review notes (optional)', 'tmwseo') . '</strong>';
        echo '<textarea name="tmwseo_review_notes" rows="3" style="width:100%;margin-top:4px" placeholder="' . esc_attr__('Short operator notes only. No implementation implied.', 'tmwseo') . '">' . esc_textarea($notes) . '</textarea></label>';
        echo '</div>';

        // Action buttons
        echo '<div class="tmwseo-mb-review-actions">';
        echo '<button type="submit" class="button" name="tmwseo_review_action" value="mark_in_review">' . esc_html__('Mark In Review', 'tmwseo') . '</button>';
        echo '<button type="submit" class="button" name="tmwseo_review_action" value="save_review_state">' . esc_html__('Save Notes', 'tmwseo') . '</button>';
        echo '<button type="submit" class="button button-primary" name="tmwseo_review_action" value="sign_off_manual_next_step">' . esc_html__('Sign Off — Manual Next Step', 'tmwseo') . '</button>';
        echo '<button type="submit" class="button button-secondary" name="tmwseo_review_action" value="reset_review_state">' . esc_html__('Reset State', 'tmwseo') . '</button>';
        echo '</div>';

        echo '<p class="tmwseo-mb-help">' . esc_html__('Trust-safe reminder: this review state never publishes, never auto-applies, never clears noindex, and never mutates live content.', 'tmwseo') . '</p>';
        echo '</form>';
        echo '</div>'; // .tmwseo-mb-zone
    }

    private static function human_preview_template_label(string $template_type): string {
        $key = sanitize_key($template_type);
        if ($key === 'category_page') {
            return 'Category Page';
        }

        if ($key === 'model_page') {
            return 'Model Page';
        }

        if ($key === 'video_page') {
            return 'Video Page';
        }

        if ($key === 'generic_post') {
            return 'Generic Post';
        }

        return 'n/a';
    }
}
