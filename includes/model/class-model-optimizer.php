<?php
namespace TMWSEO\Engine\Model;

use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Services\OpenAI;
use TMWSEO\Engine\Platform\PlatformProfiles;

if (!defined('ABSPATH')) { exit; }

/**
 * Phase 2 — Model SEO Optimizer (manual only).
 * - Generates suggestions (SEO title, RankMath title/description, focus+4 keywords, short intro).
 * - Never runs automatically. Only when you click buttons.
 */
class ModelOptimizer {

    const META_KEY = '_tmwseo_model_optimizer_suggestions';

    /** Tags that should never be used in generated copy (safety). */
    private static array $blocked_tags = [
        'teen', 'teens', 'schoolgirl', 'school girl', 'young', 'virgin', 'underage',
    ];

    /** Very generic tags that add little SEO value and tend to look spammy. */
    private static array $generic_tags = [
        'girl', 'hot', 'sexy', 'cute', 'naked', 'erotic', 'solo', 'sologirl', 'live sex', 'hd',
        'watching', 'wet', 'romantic', 'sensual', 'teasing', 'flirting',
    ];

    public static function init(): void {
        if (!is_admin()) return;

        add_action('admin_menu', [__CLASS__, 'register_menu'], 98);
        add_action('add_meta_boxes', [__CLASS__, 'register_metabox']);
        add_action('admin_post_tmwseo_modelopt_generate', [__CLASS__, 'handle_generate']);
        add_action('admin_post_tmwseo_modelopt_apply', [__CLASS__, 'handle_apply']);
        add_action('wp_ajax_tmwseo_rollback', ['\TMWSEO\Engine\Model\Rollback', 'handle_rollback']);
    }

    private static function model_post_types(): array {
        $types = ['model'];
        /**
         * Allow customizing which post types should show the Model Optimizer metabox.
         */
        $types = apply_filters('tmwseo_model_post_types', $types);
        if (!is_array($types)) $types = ['model'];
        return array_values(array_unique(array_filter(array_map('sanitize_key', $types))));
    }

    public static function register_metabox(): void {
        foreach (self::model_post_types() as $pt) {
            add_meta_box(
                'tmwseo_model_optimizer',
                __('TMW Model SEO Optimizer (Phase 2)', 'tmwseo'),
                [__CLASS__, 'render_metabox'],
                $pt,
                'normal',
                'high'
            );
        }
    }



    public static function register_menu(): void {
        // Keep the old slug registered as a hidden redirect alias so any bookmarks
        // or Tools → Advanced links that still reference tmwseo-model-optimizer
        // land on the canonical Models page (tmwseo-models) rather than a 404.
        add_submenu_page(
            null,                                           // hidden — not in sidebar
            __( 'Model Helper', 'tmwseo' ),
            __( 'Model Helper', 'tmwseo' ),
            'edit_posts',
            'tmwseo-model-optimizer',
            static function (): void {
                if ( headers_sent() ) {
                    // HTML already started — render a minimal forwarding page.
                    echo '<div class="wrap">';
                    echo '<h1>' . esc_html__( 'Model Helper', 'tmwseo' ) . '</h1>';
                    echo '<p>' . esc_html__( 'Redirecting to the canonical Models page…', 'tmwseo' ) . '</p>';
                    $url = esc_url( admin_url( 'admin.php?page=tmwseo-models' ) );
                    echo '<p><a class="button button-primary" href="' . $url . '">'
                        . esc_html__( 'Go to Models', 'tmwseo' ) . '</a></p>';
                    echo '<script>window.location.href=' . wp_json_encode( admin_url( 'admin.php?page=tmwseo-models' ) ) . ';</script>';
                    echo '</div>';
                    return;
                }
                wp_safe_redirect( admin_url( 'admin.php?page=tmwseo-models' ) );
                exit;
            }
        );
    }

    public static function render_admin_page(): void {
        if (!current_user_can('edit_posts')) {
            wp_die(__('Insufficient permissions', 'tmwseo'));
        }

        echo '<div class="wrap">';
        echo '<h1>TMW SEO Engine — Model Optimizer (Phase 2)</h1>';
        echo '<p><strong>Manual only:</strong> This tool never auto-edits posts. It generates suggestions for model pages when you click.</p>';
        echo '<ol>';
        echo '<li>Open a model.</li>';
        echo '<li>Click <em>Generate Suggestions</em> in the Model Optimizer box.</li>';
        echo '<li>Review and click <em>Apply Selected</em> (optional).</li>';
        echo '</ol>';

        echo '<h2>Models</h2>';

        $models = get_posts([
            'post_type' => 'model',
            'posts_per_page' => 50,
            'post_status' => ['publish','draft','pending','private'],
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);

        if (empty($models)) {
            echo '<p><em>No models found yet.</em></p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Model</th><th>Status</th><th>Last suggestions</th><th>Action</th></tr></thead><tbody>';

        foreach ($models as $m) {
            if (!($m instanceof \WP_Post)) continue;
            $meta = (string) get_post_meta($m->ID, self::META_KEY, true);
            $last = '';
            if ($meta !== '') {
                $d = json_decode($meta, true);
                if (is_array($d) && !empty($d['generated_at'])) $last = (string)$d['generated_at'];
            }
            $edit = get_edit_post_link($m->ID, 'url');
            echo '<tr>';
            echo '<td><a href="' . esc_url($edit) . '">' . esc_html(get_the_title($m)) . '</a></td>';
            echo '<td>' . esc_html($m->post_status) . '</td>';
            echo '<td>' . esc_html($last ?: '—') . '</td>';
            echo '<td><a class="button" href="' . esc_url($edit . '#tmwseo_model_optimizer') . '">Open Optimizer</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public static function render_metabox(\WP_Post $post): void {
        if (!current_user_can('edit_post', $post->ID)) {
            echo '<p>' . esc_html__('You do not have permission to edit this content.', 'tmwseo') . '</p>';
            return;
        }

        $suggestions = self::get_suggestions($post->ID);

        $generate_url = wp_nonce_url(
            admin_url('admin-post.php?action=tmwseo_modelopt_generate&post_id=' . $post->ID),
            'tmwseo_modelopt_generate_' . $post->ID
        );

        echo '<p><strong>Manual only.</strong> This tool never runs automatically. Click generate to preview suggestions, then optionally apply.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url($generate_url) . '">Generate Suggestions</a></p>';

        if (isset($_GET['tmwseo_modelopt_done']) && (string)$_GET['tmwseo_modelopt_done'] === '1') {
            echo '<div class="notice notice-success inline"><p>Suggestions generated.</p></div>';
        }
        if (isset($_GET['tmwseo_modelopt_applied']) && (string)$_GET['tmwseo_modelopt_applied'] === '1') {
            echo '<div class="notice notice-success inline"><p>Suggestions applied (selected fields).</p></div>';
        }
        if (isset($_GET['tmwseo_modelopt_error'])) {
            echo '<div class="notice notice-error inline"><p>Error: <code>' . esc_html((string)$_GET['tmwseo_modelopt_error']) . '</code></p></div>';
        }

        if (empty($suggestions)) {
            echo '<p><em>No suggestions saved yet.</em></p>';
            self::render_longform_preview($post);
            return;
        }

        $last = isset($suggestions['generated_at']) ? (string)$suggestions['generated_at'] : '';
        if ($last !== '') {
            echo '<p><small>Last generated: ' . esc_html($last) . '</small></p>';
        }

        $seo_title = (string)($suggestions['seo_title'] ?? '');
        $meta_title = (string)($suggestions['meta_title'] ?? '');
        $meta_desc  = (string)($suggestions['meta_description'] ?? '');
        $intro = (string)($suggestions['intro'] ?? '');

        // For model pages, primary keyword must always be the model name.
        $kw_csv = (string) get_the_title($post->ID);

        // Apply form
        echo '<hr />';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="tmwseo_modelopt_apply" />';
        echo '<input type="hidden" name="post_id" value="' . (int)$post->ID . '" />';
        wp_nonce_field('tmwseo_modelopt_apply_' . $post->ID);

        echo '<table class="form-table" style="margin-top:0;">';

        echo '<tr><th style="width:160px;">SEO Title (suggested)</th><td>';
        echo '<input type="text" class="large-text" name="seo_title" value="' . esc_attr($seo_title) . '" />';
        echo '<p class="description">Used as recommended title. You can optionally apply to WP title below.</p>';
        echo '</td></tr>';

        echo '<tr><th>RankMath Title</th><td>';
        echo '<input type="text" class="large-text" name="rankmath_title" value="' . esc_attr($seo_title !== '' ? $seo_title : $meta_title) . '" />';
        echo '</td></tr>';

        echo '<tr><th>RankMath Description</th><td>';
        echo '<textarea class="large-text" rows="3" name="rankmath_description">' . esc_textarea($meta_desc) . '</textarea>';
        echo '</td></tr>';

        echo '<tr><th>Focus + 4 keywords</th><td>';
        echo '<input type="text" class="large-text" name="rankmath_focus_keyword" value="' . esc_attr($kw_csv) . '" />';
        echo '<p class="description">RankMath expects comma-separated keywords. We recommend 5 total.</p>';
        echo '</td></tr>';

        echo '<tr><th>Short intro (150–250 words)</th><td>';
        echo '<textarea class="large-text" rows="6" name="intro">' . esc_textarea($intro) . '</textarea>';
        echo '<p class="description">We keep this non-graphic and based only on tags assigned to this model (and her content).</p>';
        echo '</td></tr>';

        echo '</table>';

        echo '<p><label><input type="checkbox" name="apply_rankmath" value="1" checked> Apply RankMath Title/Description/Keywords</label></p>';
        echo '<p><label><input type="checkbox" name="apply_wp_title" value="1"> Apply SEO Title to WP Post Title</label></p>';
        echo '<p><label><input type="checkbox" name="apply_excerpt" value="1"> Save intro into Post Excerpt (safe)</label></p>';

        echo '<p><button class="button button-primary" type="submit">Apply Selected</button></p>';
        echo '</form>';

        // Also show tags used
        $used = $suggestions['tags_used'] ?? [];
        if (is_array($used) && !empty($used)) {
            echo '<p><small><strong>Tags used:</strong> ' . esc_html(implode(', ', array_slice($used, 0, 12))) . '</small></p>';
        }
        if (!empty($suggestions['tags_blocked'])) {
            $blocked = $suggestions['tags_blocked'];
            if (is_array($blocked) && !empty($blocked)) {
                echo '<p><small><strong>Excluded for safety:</strong> ' . esc_html(implode(', ', array_slice($blocked, 0, 12))) . '</small></p>';
            }
        }

        // Patch 2: Cannibalization warnings in model review flow.
        if (class_exists('\\TMWSEO\\Engine\\Keywords\\CannibalizationDetector')) {
            $conflicts = \TMWSEO\Engine\Keywords\CannibalizationDetector::check_post($post->ID);
            if (!empty($conflicts)) {
                echo '<div class="notice notice-warning inline" style="margin-top:10px"><p><strong>Cannibalization:</strong> ' . count($conflicts) . ' keyword conflict(s):</p><ul>';
                foreach (array_slice($conflicts, 0, 5) as $c) {
                    echo '<li>' . esc_html($c['keyword']) . ' — ' . esc_html($c['conflicting_post_type']) . ' #' . (int)$c['conflicting_post_id'] . ' (' . esc_html($c['severity']) . ')</li>';
                }
                echo '</ul></div>';
            }
        }

        // Patch 2: Readiness display.
        if (class_exists('\\TMWSEO\\Engine\\Content\\IndexReadinessGate')) {
            $readiness = \TMWSEO\Engine\Content\IndexReadinessGate::evaluate_post($post->ID);
            echo '<p style="margin-top:8px"><strong>Readiness:</strong> ';
            if ($readiness['ready']) {
                echo '<span style="color:green">READY</span>';
            } else {
                echo '<span style="color:red">NOT READY</span> — ' . esc_html(implode('; ', array_slice($readiness['reasons'], 0, 3)));
            }
            echo '</p>';
        }

        self::render_longform_preview($post);
    }

    private static function render_longform_preview(\WP_Post $post): void {
        if (!class_exists('\\TMWSEO\\Engine\\Model\\ModelContentDraftService')) {
            return;
        }

        $longform_context = ModelDraftContextBuilder::build((int) $post->ID);
        $longform_context = apply_filters('tmwseo_modelopt_longform_preview_context', $longform_context, (int) $post->ID, $post);
        if (!is_array($longform_context)) {
            $longform_context = [];
        }

        $longform = ModelContentDraftService::build_longform_preview_draft((int) $post->ID, $longform_context);
        if (empty($longform['ok'])) {
            return;
        }

        echo '<hr />';
        echo '<h3>Long-Form SEO Draft Preview</h3>';
        echo '<p><strong>Preview only. This does not modify post content.</strong></p>';
        echo '<p><em>This preview is read-only and uses normalized model context data.</em></p>';
        echo '<p><strong>Title suggestion:</strong> ' . esc_html((string) ($longform['title_suggestion'] ?? '')) . '</p>';
        echo '<p><strong>Word count estimate:</strong> ' . (int) ($longform['word_count_estimate'] ?? 0) . '</p>';
        echo '<p><strong>Primary keyword:</strong> ' . esc_html((string) ($longform['primary_keyword'] ?? '')) . '</p>';
        echo '<p><strong>Safe keywords:</strong> ' . esc_html(implode(', ', (array) ($longform['safe_keywords'] ?? []))) . '</p>';
        echo '<p><strong>Platform keywords:</strong> ' . esc_html(implode(', ', (array) ($longform['platform_keywords'] ?? []))) . '</p>';
        echo '<p><strong>Excluded keywords:</strong> ' . esc_html(implode(', ', (array) ($longform['excluded_keywords'] ?? []))) . '</p>';
        echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:350px;overflow:auto;">' . wp_kses_post((string) ($longform['html_preview'] ?? '')) . '</div>';
    }

    public static function handle_generate(): void {
        if (!current_user_can('edit_posts')) {
            wp_die('Unauthorized');
        }

        $post_id = (int)($_GET['post_id'] ?? 0);
        if ($post_id <= 0) {
            wp_die('Invalid post');
        }

        if (
            !isset($_GET['_wpnonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash((string)$_GET['_wpnonce'])), 'tmwseo_modelopt_generate_' . $post_id)
        ) {
            wp_die('Invalid or expired nonce');
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_die('Post not found');
        }

        try {
            $suggestions = self::generate_suggestions($post);
            update_post_meta($post_id, self::META_KEY, wp_json_encode($suggestions));
            wp_safe_redirect(get_edit_post_link($post_id, 'url') . '&tmwseo_modelopt_done=1#tmwseo_model_optimizer');
            exit;
        } catch (\Throwable $e) {
            wp_safe_redirect(get_edit_post_link($post_id, 'url') . '&tmwseo_modelopt_error=' . rawurlencode('generate_failed') . '#tmwseo_model_optimizer');
            exit;
        }
    }

    public static function handle_apply(): void {
        $post_id = (int)($_POST['post_id'] ?? 0);
        if ($post_id <= 0) wp_die('Invalid post');

        if (!current_user_can('edit_post', $post_id)) {
            wp_die('Unauthorized');
        }

        check_admin_referer('tmwseo_modelopt_apply_' . $post_id);

        // ── Snapshot before ANY changes (enables rollback) ─────────────────
        \TMWSEO\Engine\Model\Rollback::snapshot($post_id);

        $apply_rankmath = !empty($_POST['apply_rankmath']);
        $apply_wp_title = !empty($_POST['apply_wp_title']);
        $apply_excerpt  = !empty($_POST['apply_excerpt']);

        $seo_title = sanitize_text_field((string)wp_unslash($_POST['seo_title'] ?? ''));
        $rm_title  = sanitize_text_field((string)wp_unslash($_POST['rankmath_title'] ?? ''));
        $rm_desc   = sanitize_text_field((string)wp_unslash($_POST['rankmath_description'] ?? ''));
        $rm_kw     = sanitize_text_field((string)wp_unslash($_POST['rankmath_focus_keyword'] ?? ''));
        $model_name = sanitize_text_field((string)get_the_title($post_id));
        if ($model_name !== '') {
            $rm_kw = $model_name;
        }
        $intro     = wp_kses_post((string)wp_unslash($_POST['intro'] ?? ''));

        if ($apply_rankmath) {
            if ($model_name !== '') {
                $rm_desc = sprintf(
                    "Watch %1\$s live on webcam. Join %1\$s's live chat, explore photos, videos and real-time streaming. Start watching now.",
                    $model_name
                );
            }

            $rankmath_title = $seo_title !== '' ? $seo_title : $rm_title;
            if ($rankmath_title !== '') update_post_meta($post_id, 'rank_math_title', $rankmath_title);
            if ($rm_desc !== '') update_post_meta($post_id, 'rank_math_description', $rm_desc);
            if ($rm_kw !== '') {
                // Rebuild fresh so Rank Math never reuses a stale stored keyword pack
                // after approved linked Model Pool rows have changed.
                $model_pack = [];
                if (class_exists('\\TMWSEO\\Engine\\Keywords\\ModelKeywordPack')) {
                    $post_for_pack = get_post($post_id);
                    if ($post_for_pack instanceof \WP_Post) {
                        $model_pack = \TMWSEO\Engine\Keywords\ModelKeywordPack::build($post_for_pack);
                    }
                }
                if (empty($model_pack) || empty($model_pack['primary'])) {
                    $rm_kw_lc = function_exists('mb_strtolower') ? mb_strtolower($rm_kw, 'UTF-8') : strtolower($rm_kw);
                    $model_pack = [
                        'primary' => $rm_kw,
                        'rankmath_additional' => [
                            $rm_kw_lc . ' livejasmin',
                            'livejasmin ' . $rm_kw_lc,
                            $rm_kw_lc . ' live',
                            $rm_kw_lc . ' cam',
                        ],
                    ];
                }
                if (class_exists('\\TMWSEO\\Engine\\Content\\RankMathMapper')) {
                    \TMWSEO\Engine\Content\RankMathMapper::sync_to_rank_math($post_id, $model_pack, true);
                } else {
                    update_post_meta($post_id, 'rank_math_focus_keyword', $rm_kw);
                }

                // Patch 2.1: persist confidence from stored pack or compute a basic one.
                $pack_confidence = (float) ($model_pack['confidence'] ?? 0);
                if ($pack_confidence <= 0) {
                    // Derive confidence from what we know: model name exists + we have keywords.
                    $pack_confidence = 40.0; // base: model name is the focus keyword
                    if (count($model_pack['additional'] ?? []) >= 2) $pack_confidence += 15.0;
                    if (count($model_pack['additional'] ?? []) >= 4) $pack_confidence += 10.0;
                    if (!empty($model_pack['sources']['dfseo']))     $pack_confidence += 15.0;
                    if (count($model_pack['sources']['platforms'] ?? []) >= 1) $pack_confidence += 10.0;
                    if (count($model_pack['sources']['tags'] ?? []) >= 3)      $pack_confidence += 10.0;
                    $pack_confidence = min(100.0, $pack_confidence);
                }
                update_post_meta($post_id, '_tmwseo_keyword_confidence', round($pack_confidence, 2));
            }
        }

        // Patch 2: run cannibalization check and persist via AuditTrail.
        if (class_exists('\\TMWSEO\\Engine\\Keywords\\CannibalizationDetector')) {
            $conflicts = \TMWSEO\Engine\Keywords\CannibalizationDetector::check_post($post_id);
            if (class_exists('\\TMWSEO\\Engine\\Content\\AuditTrail')) {
                \TMWSEO\Engine\Content\AuditTrail::persist_cannibalization($post_id, $conflicts);
            }
        }

        // Patch 2: evaluate readiness gates after apply.
        if (class_exists('\\TMWSEO\\Engine\\Content\\IndexReadinessGate')) {
            \TMWSEO\Engine\Content\IndexReadinessGate::evaluate_post($post_id);
        }

        if ($apply_wp_title && $seo_title !== '') {
            // Store original title once for safety.
            if (!get_post_meta($post_id, '_tmwseo_original_title', true)) {
                $orig = get_the_title($post_id);
                update_post_meta($post_id, '_tmwseo_original_title', $orig);
            }
            wp_update_post([
                'ID' => $post_id,
                'post_title' => $seo_title,
            ]);
        }

        if ($apply_excerpt && trim($intro) !== '') {
            wp_update_post([
                'ID' => $post_id,
                'post_excerpt' => $intro,
            ]);
        }

        $image_id = get_post_thumbnail_id($post_id);
        if ($image_id) {
            update_post_meta($image_id, '_wp_attachment_image_alt', $model_name . ' webcam model');
        }

        update_post_meta($post_id, '_tmwseo_modelopt_applied_at', current_time('mysql'));

        wp_safe_redirect(get_edit_post_link($post_id, 'url') . '&tmwseo_modelopt_applied=1#tmwseo_model_optimizer');
        exit;
    }

    private static function get_suggestions(int $post_id): array {
        $raw = (string) get_post_meta($post_id, self::META_KEY, true);
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function normalize_tag(string $t): string {
        $t = trim($t);
        $t = preg_replace('/\s+/', ' ', $t);
        $t = rtrim($t, ", \t\n\r\0\x0B");
        return $t;
    }

    private static function collect_model_tags(\WP_Post $post): array {
        $taxes = get_object_taxonomies($post->post_type, 'names');
        if (!is_array($taxes)) $taxes = [];

        $all = [];
        foreach ($taxes as $tax) {
            if (!is_string($tax) || $tax === '') continue;
            if (in_array($tax, ['post_format'], true)) continue;

            $names = wp_get_post_terms($post->ID, $tax, ['fields' => 'names']);
            if (is_wp_error($names) || !is_array($names)) continue;

            foreach ($names as $n) {
                if (!is_string($n)) continue;
                $n = self::normalize_tag($n);
                if ($n === '') continue;
                $all[] = $n;
            }
        }

        $all = array_values(array_unique($all));
        return $all;
    }

    private static function filter_tags(array $tags): array {
        $used = [];
        $blocked = [];

        foreach ($tags as $t) {
            $t_norm = strtolower(self::normalize_tag((string)$t));
            if ($t_norm === '') continue;

            // Blocked (safety)
            foreach (self::$blocked_tags as $b) {
                if ($t_norm === $b) {
                    $blocked[] = (string)$t;
                    continue 2;
                }
            }

            // Generic
            if (in_array($t_norm, self::$generic_tags, true)) {
                continue;
            }

            $used[] = (string)$t;
        }

        $used = array_values(array_unique(array_map([__CLASS__, 'normalize_tag'], $used)));
        $blocked = array_values(array_unique(array_map([__CLASS__, 'normalize_tag'], $blocked)));

        return ['used' => $used, 'blocked' => $blocked];
    }

    /**
     * Generate suggestions for the given model post.
     * Prefers OpenAI if configured and not in dry run; otherwise deterministic templates.
     */
    private static function generate_suggestions(\WP_Post $post): array {
        $name = trim((string)$post->post_title);
        if ($name === '') $name = 'Model';

        $draft_payload = ModelContentDraftService::build_basic_draft_payload((int) $post->ID);

        $tags_all = self::collect_model_tags($post);
        $ft = self::filter_tags($tags_all);
        $tags = $ft['used'];
        $blocked = $ft['blocked'];

        // Keep a small set of tags for titles.
        $top_tags = array_slice($tags, 0, 6);

        $platforms = [];
        if (is_array($draft_payload['platforms'] ?? null)) {
            $platforms = array_values(array_filter(array_map('strval', $draft_payload['platforms'])));
        }
        if (empty($platforms) && class_exists('\\TMWSEO\\Engine\\Platform\\PlatformProfiles')) {
            $links = PlatformProfiles::get_links((int)$post->ID);
            if (is_array($links)) {
                foreach ($links as $l) {
                    $p = (string)($l['platform'] ?? '');
                    if ($p !== '') $platforms[] = $p;
                }
            }
        }

        // If OpenAI configured and dry-run off, try to generate.
        $dry = (int) Settings::get('tmwseo_dry_run_mode', 0);
        if ($dry === 0 && OpenAI::is_configured() && !Settings::is_safe_mode()) {
            $res = self::generate_with_openai((int) $post->ID, $name, $top_tags, $platforms);
            if (($res['ok'] ?? false) && isset($res['suggestions']) && is_array($res['suggestions'])) {
                $s = $res['suggestions'];
                $s['seo_title'] = self::build_model_seo_title($name);
                $s['meta_title'] = self::trim_len($s['seo_title'], 60);
                $s['generated_at'] = current_time('mysql');
                $s['tags_used'] = $top_tags;
                $s['tags_blocked'] = $blocked;
                return $s;
            }
        }

        // Fallback deterministic.
        $s = self::generate_with_templates($name, $top_tags, $platforms);
        $s['generated_at'] = current_time('mysql');
        $s['tags_used'] = $top_tags;
        $s['tags_blocked'] = $blocked;
        return $s;
    }

    private static function trim_len(string $s, int $max): string {
        $s = trim($s);
        if (mb_strlen($s) <= $max) return $s;
        return trim(mb_substr($s, 0, $max - 1)) . '…';
    }

    private static function build_model_seo_title(string $name): string {
        $name = trim($name);
        if ($name === '') {
            return 'Live Chat – Watch Webcam Now';
        }

        return $name . ' Live Chat – Watch ' . $name . ' Webcam Now';
    }

    private static function generate_with_templates(string $name, array $tags, array $platforms): array {
        $t1 = $tags[0] ?? '';
        $t2 = $tags[1] ?? '';
        $t3 = $tags[2] ?? '';

        $tag_phrase = '';
        $tag_bits = array_values(array_filter([$t1, $t2, $t3], static fn($x) => trim((string)$x) !== ''));
        if (!empty($tag_bits)) {
            $tag_phrase = implode(', ', $tag_bits);
        }

        $seo_title = self::build_model_seo_title($name);

        // Meta title should be ~60 chars.
        $meta_title = self::trim_len($seo_title, 60);

        // Meta description ~155 chars.
        $desc = 'Watch ' . $name . ' live in private webcam chat. ';
        if ($tag_phrase !== '') {
            $desc .= 'Explore ' . $tag_phrase . ' shows and more. ';
        } else {
            $desc .= 'Discover premium live cam shows and new videos. ';
        }
        $desc .= '18+ only.';
        $meta_description = self::trim_len($desc, 155);

        $focus = $name;

        $extras = [];
        foreach (array_slice($tags, 0, 4) as $tg) {
            $tg = trim((string)$tg);
            if ($tg === '') continue;
            $extras[] = strtolower($tg);
        }

        $intro = self::build_intro($name, $tags, $platforms);

        return [
            'seo_title' => $seo_title,
            'meta_title' => $meta_title,
            'meta_description' => $meta_description,
            'focus_keyword' => $focus,
            'extra_keywords' => array_slice(array_values(array_unique($extras)), 0, 4),
            'intro' => $intro,
        ];
    }

    private static function build_intro(string $name, array $tags, array $platforms): string {
        $tag_bits = self::template_safe_tag_bits($tags, 8);
        $private_items = self::template_safe_private_chat_items($tags);
        $platform_labels = self::template_platform_labels($platforms);
        $platform_phrase = !empty($platform_labels) ? self::template_human_list(array_slice($platform_labels, 0, 3)) : '';
        $tag_phrase = !empty($tag_bits) ? self::template_human_list(array_slice($tag_bits, 0, 6)) : '';
        $private_phrase = !empty($private_items) ? self::template_human_list(array_slice($private_items, 0, 8)) : '';

        $paragraphs = [];
        $paragraphs[] = '<p>Meet ' . esc_html($name) . ', a live cam model profile built to help adult visitors understand where to watch live, how to read the current room context, and which listed tags may be useful before opening the official room. This profile keeps the copy practical and non-graphic: it focuses on access, browsing signals, session availability, and related pages rather than guessing personal details that are not confirmed.</p>';

        if ($tag_phrase !== '') {
            $paragraphs[] = '<p>The listed tags give useful browsing context for this profile. Current safe tags include ' . esc_html($tag_phrase) . ', which can help visitors compare the listing with nearby model pages and decide whether the room fits the kind of live session they want to check. Tags should be treated as discovery signals, not promises that every option is available at all times.</p>';
        } else {
            $paragraphs[] = '<p>This profile is designed for quick discovery even when only limited model data is available. Visitors can use the live-room link, internal category paths, and related pages to compare current availability without relying on unsupported biographical claims.</p>';
        }

        if ($private_phrase !== '') {
            $paragraphs[] = '<h2>Private Chat Options and Session Context</h2>';
            $paragraphs[] = '<p>Private chat options can include safe interactive requests such as ' . esc_html($private_phrase) . ' when those options are available in the live room. Availability can change by session, so visitors should confirm the current room status before expecting a specific request. This keeps the page useful while avoiding copied raw wording or explicit descriptions.</p>';
            $paragraphs[] = '<p>For browsing, these safe items are best understood as room-style clues. A visitor looking for ' . esc_html(self::template_human_list(array_slice($private_items, 0, 2))) . ' context, for example, can use the tags to decide whether to open the live-room page, then check the current menu, schedule, and performer status directly on the platform. If a request is not shown during a session, related model pages can help continue the search without forcing unsupported assumptions into this profile.</p>';
        }

        $paragraphs[] = '<h2>Official Profile Access</h2>';
        if ($platform_phrase !== '') {
            $paragraphs[] = '<p>The official live-room link is the best place to confirm availability. This listing includes platform context for ' . esc_html($platform_phrase) . ', so visitors should use the active room destination first and then review any current platform notices, live status, or session menu before joining. Platform labels are included only as access context and should not be read as personal claims about the model.</p>';
        } else {
            $paragraphs[] = '<p>The official live-room link is the best place to confirm availability. Use the active room destination first, then review current platform notices, live status, or session menus before joining. This profile avoids unsupported platform claims when platform data is not present.</p>';
        }

        $paragraphs[] = '<h2>Where to Watch Live</h2>';
        $paragraphs[] = '<p>This profile helps visitors understand where to watch live and what to check before entering a room. Live-room pages can change quickly: a model may be online, offline, busy in a private session, or using different room options than the tags suggest. The safest approach is to open the official access point, confirm the room status, and use the visible platform information for the latest availability.</p>';
        $paragraphs[] = '<p>If the live room is offline, the listing still has value as a browsing hub. Visitors can compare the tags, follow related model pages, check recent videos or photos, and return later when the room is active. That makes the page useful without inventing age, country, relationship status, measurements, real identity, or other personal facts that are not supplied by the source data.</p>';

        $paragraphs[] = '<h2>More Pages and Internal Links</h2>';
        $paragraphs[] = '<p>Explore more models: <a href="/models/">Browse All Models</a>, check fresh clips in <a href="/videos/">Videos</a>, discover galleries in <a href="/photos/">Photos</a>, and read updates on our <a href="/blog/">Blog</a>. These links keep browsing simple when a live room is unavailable or when visitors want to compare similar tags before choosing a room.</p>';

        $paragraphs[] = '<h2>Similar Models and Browsing Context</h2>';
        if ($tag_phrase !== '') {
            $paragraphs[] = '<p>Similar model pages are most useful when they share safe browsing tags such as ' . esc_html($tag_phrase) . '. Use those signals to compare room style, current availability, and recent media without assuming that two profiles offer the same session menu. The goal is to help visitors continue browsing with clear context, not to overstate what the available data proves.</p>';
        } else {
            $paragraphs[] = '<p>Similar model pages help visitors continue browsing when this listing has limited context or when the live room is offline. Compare current room status, recent media, and platform access points before deciding where to watch.</p>';
        }

        $paragraphs[] = '<h2>FAQ</h2>';
        $paragraphs[] = '<p><strong>How should visitors use this profile?</strong> Start with the official live-room link, then use tags and internal links for comparison. The page is a directory-style guide for adult browsing, not a biography.</p>';
        $paragraphs[] = '<p><strong>Can private-chat options change?</strong> Yes. Private-chat options can change by session, platform status, and the model’s current room settings. Confirm availability in the live room before expecting a specific request.</p>';
        $paragraphs[] = '<p><strong>What if the room is offline?</strong> Use related model pages, videos, photos, and blog updates to keep browsing. Returning later may show a different live status or a different set of available room options.</p>';

        $text = self::weave_template_secondary_keywords(implode("\n", $paragraphs), $name, $tag_bits);
        $text = self::expand_template_model_body_if_short($text, $name, $tag_bits, $private_items, $platform_labels);
        $text = self::reduce_template_focus_keyword_density($text, $name);

        return self::ensure_internal_links(trim($text));
    }

    private static function template_safe_tag_bits(array $tags, int $limit = 8): array {
        $safe = [];
        foreach ($tags as $tag) {
            $tag = self::normalize_tag((string) $tag);
            if ($tag === '') continue;
            $key = strtolower($tag);
            if (in_array($key, self::$blocked_tags, true) || in_array($key, self::$generic_tags, true)) continue;
            if (preg_match('/\b(anal|deepthroat|cumshot|squirt|dildo|fetish|xxx|hardcore|incest|rape|forced)\b/i', $tag)) continue;
            $safe[] = $tag;
        }
        return array_slice(array_values(array_unique($safe)), 0, max(1, $limit));
    }

    private static function template_safe_private_chat_items(array $tags): array {
        $allowed = [
            'striptease' => 'Striptease',
            'dancing' => 'Dancing',
            'close up' => 'Close up',
            'close-up' => 'Close up',
            'roleplay' => 'Roleplay',
            'oil' => 'Oil',
            'twerk' => 'Twerk',
            'cosplay' => 'Cosplay',
            'asmr' => 'ASMR',
            'high heels' => 'High Heels',
            'stockings' => 'Stockings',
        ];

        $items = [];
        foreach ($tags as $tag) {
            $key = strtolower(trim((string) $tag));
            $key = preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $key)) ?? $key;
            if (isset($allowed[$key])) {
                $items[] = $allowed[$key];
            }
        }

        return array_values(array_unique($items));
    }

    private static function template_platform_labels(array $platforms): array {
        $map = [
            'livejasmin' => 'LiveJasmin',
            'stripchat' => 'Stripchat',
            'chaturbate' => 'Chaturbate',
            'myfreecams' => 'MyFreeCams',
            'camsoda' => 'CamSoda',
            'bonga' => 'BongaCams',
            'cam4' => 'Cam4',
        ];

        $labels = [];
        foreach ($platforms as $platform) {
            $key = strtolower(trim((string) $platform));
            if ($key === '') continue;
            $labels[] = $map[$key] ?? ucwords(str_replace(['_', '-'], ' ', $key));
        }

        return array_values(array_unique(array_filter($labels)));
    }

    private static function template_human_list(array $items): string {
        $items = array_values(array_filter(array_map('trim', array_map('strval', $items))));
        $count = count($items);
        if ($count === 0) return '';
        if ($count === 1) return $items[0];
        if ($count === 2) return $items[0] . ' and ' . $items[1];
        return implode(', ', array_slice($items, 0, -1)) . ', and ' . $items[$count - 1];
    }

    private static function template_word_count(string $html): int {
        $text = trim(strip_tags($html));
        if ($text === '') return 0;
        $words = preg_split('/\s+/', $text);
        return is_array($words) ? count(array_filter($words)) : 0;
    }

    private static function expand_template_model_body_if_short(string $text, string $name, array $tag_bits, array $private_items, array $platform_labels): string {
        $has_safe_context = !empty($tag_bits) || !empty($private_items) || !empty($platform_labels);
        $target_words = (count($private_items) >= 4 || count($tag_bits) >= 6) ? 760 : 600;
        if (!$has_safe_context || self::template_word_count($text) >= $target_words) {
            return $text;
        }

        $tag_phrase = self::template_human_list(array_slice($tag_bits, 0, 6));
        $private_phrase = self::template_human_list(array_slice($private_items, 0, 8));
        $platform_phrase = self::template_human_list(array_slice($platform_labels, 0, 3));
        $extras = [];

        if ($private_phrase !== '') {
            $extras[] = '<p>When safe private-chat-style items are listed, visitors should read them as session-dependent options. The room may show ' . esc_html($private_phrase) . ' as browsing context, but the current live page remains the source for what is actually available. This wording keeps those useful items visible while avoiding graphic detail or invented promises.</p>';
        }
        if ($tag_phrase !== '') {
            $extras[] = '<p>The tag set also helps with comparison. Someone browsing for ' . esc_html($tag_phrase) . ' can open this profile, check whether the room is live, and then compare related listings if the current session does not match what they want. The tags are there to make navigation easier, not to create unsupported facts about the performer.</p>';
        }
        if ($platform_phrase !== '') {
            $extras[] = '<p>Platform context for ' . esc_html($platform_phrase) . ' is useful because live-room access can differ from one destination to another. If more than one platform label appears, visitors should start with the current official room link and treat every platform page as a place to confirm status, not as a guarantee that the same options are active everywhere.</p>';
        }
        $extras[] = '<p>Related model pages help visitors continue browsing if the room is offline. Use the internal model directory for alternatives, the video and photo areas for recent media, and the blog for broader updates. This gives the page enough helpful context for search visitors while staying grounded in visible tags, platform labels, and live-room availability.</p>';

        foreach ($extras as $extra) {
            if (self::template_word_count($text) >= $target_words) break;
            $text .= "\n" . $extra;
        }

        return $text;
    }

    private static function weave_template_secondary_keywords(string $text, string $name, array $tag_bits): string {
        $keywords = array_slice(array_values(array_filter($tag_bits, static fn($tag) => stripos((string) $tag, $name) === false)), 0, 3);
        if (empty($keywords)) {
            return $text;
        }

        $keyword = array_shift($keywords);
        if ($keyword !== null && stripos($text, 'room style') !== false && substr_count(strtolower(strip_tags($text)), strtolower((string) $keyword)) < 2) {
            $text = preg_replace('/room style/', esc_html((string) $keyword) . ' room style', $text, 1) ?? $text;
        }

        return $text;
    }

    private static function reduce_template_focus_keyword_density(string $text, string $name): string {
        $name = trim($name);
        if ($name === '') return $text;

        $word_count = max(1, self::template_word_count($text));
        $limit = max(1, (int) floor(($word_count * 0.019)));
        $seen = 0;

        return preg_replace_callback('/(?<![\w-])' . preg_quote($name, '/') . '(?![\w-])/', static function (array $matches) use (&$seen, $limit): string {
            $seen++;
            if ($seen <= $limit) {
                return (string) ($matches[0] ?? '');
            }
            $alternates = ['the model', 'her profile', 'this profile', 'the live-room page', 'the listing'];
            return $alternates[($seen - $limit - 1) % count($alternates)];
        }, $text) ?? $text;
    }

    private static function ensure_internal_links(string $intro): string {
        $required_links = [
            '/models/' => 'Browse All Models',
            '/videos/' => 'Videos',
            '/photos/' => 'Photos',
            '/blog/' => 'Blog',
        ];

        $missing = [];
        foreach ($required_links as $href => $label) {
            if (stripos($intro, 'href="' . $href . '"') === false && stripos($intro, "href='" . $href . "'") === false) {
                $missing[$href] = $label;
            }
        }

        if (empty($missing)) {
            return $intro;
        }

        $parts = [];
        foreach ($missing as $href => $label) {
            $parts[] = '<a href="' . esc_attr($href) . '">' . esc_html($label) . '</a>';
        }

        return trim($intro) . ' Explore more models: ' . implode(', ', $parts) . '.';
    }

    private static function generate_with_openai(int $post_id, string $name, array $tags, array $platforms): array {
        $model = Settings::openai_model_for_quality();

        $tag_list = implode(', ', array_values(array_filter(array_map('strval', $tags))));
        $platform_list = implode(', ', array_values(array_filter(array_map('strval', $platforms))));

        $evidence_block = '';
        if ($post_id > 0 && class_exists('\\TMWSEO\\Engine\\Content\\ModelResearchEvidence')) {
            $evidence_block = trim(\TMWSEO\Engine\Content\ModelResearchEvidence::build_prompt_block($post_id));
        }

        $evidence_section = '';
        if ($evidence_block !== '') {
            $evidence_section = "
Cleaned ModelResearchEvidence context for the intro only:
{$evidence_block}

Evidence rules for the intro:
- Use this as trusted context only when writing the intro; keep titles, metadata, and keywords aligned to the normal fields above.
- Expand safe facts naturally in original wording, but do not copy raw or unsafe wording verbatim.
- Do not invent facts, personal claims, private-chat options, or platform details that are not supported by the tags, platforms, or evidence.
- Keep the intro compact, excerpt-safe, and non-graphic even when evidence is available.
";
        }

        $system = [
            'role' => 'system',
            'content' => 'You write safe, non-graphic SEO copy for adult live-cam profile pages. You must avoid explicit descriptions of sexual acts. Do not mention minors or age-play. Keep the tone natural and professional.',
        ];

        $user = [
            'role' => 'user',
            'content' => "Create SEO suggestions for a cam model profile page.

Model name: {$name}
Allowed tags (use only if relevant): {$tag_list}
Platforms (optional mention): {$platform_list}
{$evidence_section}
Requirements:
- Output compact JSON only with keys: seo_title, meta_title, meta_description, focus_keyword, extra_keywords (array of 4), intro.
- Do not add required JSON keys and do not include body copy outside the intro field.
- Intro must be 150-250 words, non-graphic, excerpt-safe, suitable for the metabox short-intro field, and safe for possible post_excerpt storage.
- Intro should read naturally for adults without explicit act descriptions or unsupported personal claims.
- Avoid robotic phrases such as: The verified notes point to, personable cam delivery, do you accept, Use these notes as profile context.
- Meta title ~60 chars, meta description ~155 chars.
- Focus keyword must be exactly the model name: {$name}.
- Extra keywords should be relevant tag variations only (no appended intent words).
",
        ];

        $res = OpenAI::chat_json([$system, $user], $model, [
            'temperature' => 0.5,
            'max_tokens' => 950,
        ]);

        if (!($res['ok'] ?? false)) {
            return ['ok' => false, 'error' => $res['error'] ?? 'openai_failed'];
        }

        $json = $res['json'] ?? null;
        if (!is_array($json)) return ['ok' => false, 'error' => 'bad_json'];

        $suggestions = [
            'seo_title' => sanitize_text_field((string)($json['seo_title'] ?? '')),
            'meta_title' => sanitize_text_field((string)($json['meta_title'] ?? '')),
            'meta_description' => sanitize_text_field((string)($json['meta_description'] ?? '')),
            'focus_keyword' => sanitize_text_field((string)($json['focus_keyword'] ?? '')),
            'extra_keywords' => is_array($json['extra_keywords'] ?? null) ? array_values(array_map('sanitize_text_field', $json['extra_keywords'])) : [],
            'intro' => isset($json['intro']) ? self::ensure_internal_links(wp_kses_post((string)$json['intro'])) : '',
        ];

        // Model pages always use the model name as the focus keyword.
        $suggestions['focus_keyword'] = $name;

        // Clamp extra keywords to 4.
        $suggestions['extra_keywords'] = array_slice(array_values(array_unique(array_filter(array_map('trim', $suggestions['extra_keywords'])))), 0, 4);

        // Ensure lengths.
        if ($suggestions['meta_title'] !== '') {
            $suggestions['meta_title'] = self::trim_len($suggestions['meta_title'], 60);
        }
        if ($suggestions['meta_description'] !== '') {
            $suggestions['meta_description'] = self::trim_len($suggestions['meta_description'], 155);
        }

        return ['ok' => true, 'suggestions' => $suggestions];
    }
}
