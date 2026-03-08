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
        add_submenu_page(
            null,
            __('Model Optimizer', 'tmwseo'),
            __('Model Optimizer', 'tmwseo'),
            'edit_posts',
            'tmwseo-model-optimizer',
            [__CLASS__, 'render_admin_page']
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
                update_post_meta($post_id, 'rank_math_focus_keyword', $rm_kw);

                $secondary_keywords = [
                    $rm_kw . ' webcam',
                    $rm_kw . ' live',
                    $rm_kw . ' cam',
                    $rm_kw . ' stream',
                ];
                update_post_meta($post_id, 'rank_math_secondary_keywords', implode(',', $secondary_keywords));
            }
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

        $tags_all = self::collect_model_tags($post);
        $ft = self::filter_tags($tags_all);
        $tags = $ft['used'];
        $blocked = $ft['blocked'];

        // Keep a small set of tags for titles.
        $top_tags = array_slice($tags, 0, 6);

        $platforms = [];
        if (class_exists('\\TMWSEO\\Engine\\Platform\\PlatformProfiles')) {
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
            $res = self::generate_with_openai($name, $top_tags, $platforms);
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
        $tag_bits = array_slice(array_values(array_filter($tags, static fn($x) => trim((string)$x) !== '')), 0, 6);

        $p_sentence = '';
        if (!empty($platforms)) {
            // Convert keys to labels if possible.
            $labels = [];
            $map = [
                'livejasmin' => 'LiveJasmin',
                'stripchat' => 'Stripchat',
                'chaturbate' => 'Chaturbate',
                'myfreecams' => 'MyFreeCams',
                'camsoda' => 'CamSoda',
                'bonga' => 'BongaCams',
                'cam4' => 'Cam4',
            ];
            foreach ($platforms as $p) {
                $p = (string)$p;
                $labels[] = $map[$p] ?? ucfirst($p);
            }
            $labels = array_values(array_unique(array_filter($labels)));
            if (!empty($labels)) {
                $p_sentence = 'You can also find ' . $name . ' on ' . implode(', ', array_slice($labels, 0, 3)) . '.';
            }
        }

        $sentences = [];

        $sentences[] = 'Meet ' . $name . ', a live cam model available for private webcam chat and real-time shows.';

        if (!empty($tag_bits)) {
            $sentences[] = 'Popular tags for ' . $name . ' include ' . implode(', ', $tag_bits) . '.';
        }

        $sentences[] = 'Browse the latest videos, explore related categories, and use the tags on this page to find the exact vibe you like.';

        if ($p_sentence !== '') {
            $sentences[] = $p_sentence;
        }

        $sentences[] = 'Explore more models: <a href="/models/">Browse All Models</a>, check fresh clips in <a href="/videos/">Videos</a>, discover galleries in <a href="/photos/">Photos</a>, and read updates on our <a href="/blog/">Blog</a>.';

        $sentences[] = 'This page is for adults only (18+).';

        $text = implode(' ', $sentences);

        $fillers = [
            'Use the navigation and tag filters to discover more performers and clips that match your preferences.',
            'If you are new here, start with the most popular tags and then explore deeper combinations for long‑tail discoveries.',
            'For best results, look at both live sessions and recent uploads — this helps you find fresh content faster.',
            'Bookmark this model page and come back later for updates, new videos, and featured highlights.',
            'We keep descriptions non‑graphic and focused on categories so the page stays clear, useful, and SEO‑friendly.',
        ];

        $words = preg_split('/\s+/', trim(strip_tags($text)));
        $count = is_array($words) ? count(array_filter($words)) : 0;

        $i = 0;
        while ($count < 150 && $i < count($fillers)) {
            $text .= ' ' . $fillers[$i];
            $i++;
            $words = preg_split('/\s+/', trim(strip_tags($text)));
            $count = is_array($words) ? count(array_filter($words)) : 0;
        }

        // If still too short (very rare), add a final safe filler paragraph.
        if ($count < 150) {
            $text .= ' Explore similar tags to compare styles, and check the model’s profile links for alternate platforms if available.';
        }

        // Hard cap to ~250 words.
        $words = preg_split('/\s+/', trim(strip_tags($text)));
        if (is_array($words) && count($words) > 250) {
            $text = implode(' ', array_slice($words, 0, 240)) . '…';
        }

        return self::ensure_internal_links(trim($text));
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

    private static function generate_with_openai(string $name, array $tags, array $platforms): array {
        $model = Settings::openai_model_for_quality();

        $tag_list = implode(', ', array_values(array_filter(array_map('strval', $tags))));
        $platform_list = implode(', ', array_values(array_filter(array_map('strval', $platforms))));

        $system = [
            'role' => 'system',
            'content' => 'You write safe, non-graphic SEO copy for adult live-cam profile pages. You must avoid explicit descriptions of sexual acts. Do not mention minors or age-play. Keep the tone natural and professional.',
        ];

        $user = [
            'role' => 'user',
            'content' => "Create SEO suggestions for a cam model profile page.\n\nModel name: {$name}\nAllowed tags (use only if relevant): {$tag_list}\nPlatforms (optional mention): {$platform_list}\n\nRequirements:\n- Output JSON object with keys: seo_title, meta_title, meta_description, focus_keyword, extra_keywords (array of 4), intro.\n- Intro must be 150-250 words, non-graphic, no explicit act descriptions.\n- Meta title ~60 chars, meta description ~155 chars.\n- Focus keyword must be exactly the model name: {$name}.\n- Extra keywords should be relevant tag variations only (no appended intent words).\n",
        ];

        $res = OpenAI::chat_json([$system, $user], $model, [
            'temperature' => 0.5,
            'max_tokens' => 700,
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
