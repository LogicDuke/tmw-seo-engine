<?php
namespace TMWSEO\Engine\Content;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Jobs;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Services\TitleFixer;
use TMWSEO\Engine\Services\OpenAI;

if (!defined('ABSPATH')) { exit; }

class ContentEngine {

    private const MODEL_MIN_WORDS = 1510;
    private const MODEL_MIN_KEYWORD_DENSITY = 1.0;
    private const MODEL_MAX_KEYWORD_DENSITY = 2.0;

    /** Post types we auto-optimize on first publish */
    private static array $auto_types = [
        'post',
        'page',
        'model',
        'tmw_category_page',
    ];

    public static function init(): void {
        add_action('transition_post_status', [__CLASS__, 'on_transition_post_status'], 10, 3);

        // Shortcode: show generated keyword (for templates)
        add_shortcode('tmwseo_keyword', function($atts){
            $post_id = get_the_ID();
            $kw = get_post_meta($post_id, '_tmwseo_keyword', true);
            return esc_html((string)$kw);
        });
    }

    public static function on_transition_post_status(string $new_status, string $old_status, \WP_Post $post): void {
        if ($new_status !== 'publish' || $old_status === 'publish') return;
        if (!in_array($post->post_type, self::$auto_types, true)) return;

        // Only auto-optimize once.
        if (get_post_meta($post->ID, '_tmwseo_optimize_done', true)) return;
        if (get_post_meta($post->ID, '_tmwseo_optimize_enqueued', true)) return;
        if ((int) Settings::get('manual_control_mode', 1) === 1) return;

        update_post_meta($post->ID, '_tmwseo_optimize_enqueued', 1);

        Jobs::enqueue('optimize_post', $post->post_type, (int)$post->ID, [
            'context' => self::infer_context($post),
            'trigger' => 'publish',
        ]);

        Logs::info('content', 'Enqueued optimize_post on publish', ['post_id' => $post->ID, 'post_type' => $post->post_type]);
    }

    private static function infer_context(\WP_Post $post): string {
        if ($post->post_type === 'model') return 'model';
        if ($post->post_type === 'tmw_category_page') return 'category_page';
        if ($post->post_type === 'page' && get_post_meta($post->ID, '_tmwseo_generated', true)) return 'keyword_page';
        return 'video_or_post';
    }

    private static function normalize_focus_keyword_for_post(\WP_Post $post, string $focus_kw): string {
        if ($post->post_type === 'model') {
            $model_name = trim((string)get_the_title($post->ID));
            return $model_name !== '' ? $model_name : $focus_kw;
        }

        return $focus_kw;
    }

    private static function build_model_secondary_keywords(string $primary_keyword): array {
        $primary_keyword = trim($primary_keyword);
        if ($primary_keyword === '') return [];

        return [
            $primary_keyword . ' webcam',
            $primary_keyword . ' live',
            $primary_keyword . ' cam',
            $primary_keyword . ' stream',
        ];
    }

    private static function update_model_secondary_keywords_for_post(\WP_Post $post, string $primary_keyword): void {
        if ($post->post_type !== 'model') return;

        $secondary_keywords = self::build_model_secondary_keywords($primary_keyword);
        if (empty($secondary_keywords)) return;

        update_post_meta($post->ID, 'rank_math_secondary_keywords', implode(',', $secondary_keywords));
    }

    public static function run_optimize_job(array $job): void {
        error_log('TMW run_optimize_job ENTERED');
        $post_id = (int)($job['entity_id'] ?? 0);
        error_log('TMW run_optimize_job POST_ID=' . $post_id);
        $dry = (int) Settings::get('tmwseo_dry_run_mode', 0);
        error_log('TMW run_optimize_job DRY_MODE=' . $dry);
        if ($post_id <= 0) {
            Logs::warn('content', 'optimize_post missing entity_id');
            error_log('TMW run_optimize_job EARLY RETURN');
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            Logs::warn('content', 'Post not found', ['post_id' => $post_id]);
            error_log('TMW run_optimize_job EARLY RETURN');
            return;
        }

        if (Settings::is_safe_mode()) {
            Logs::info('content', 'Safe mode enabled; skipping AI generation', ['post_id' => $post_id]);
            delete_post_meta($post_id, '_tmwseo_optimize_enqueued');
            update_post_meta($post_id, '_tmwseo_optimize_done', 'skipped_safe_mode');
            error_log('TMW run_optimize_job EARLY RETURN');
            return;
        }

        $dry_run = $dry;
        if ($dry_run) {
            error_log('TMW DRY RUN EXECUTED FOR POST ID: ' . $post_id);
            Logs::info('content', 'Dry run branch reached', [
                'post_id' => $post_id
            ]);

            error_log('TMW run_optimize_job GENERATING CONTENT');

            if ($post->post_type === 'model') {
                $model_name = trim((string)$post->post_title);
                $placeholder_content = "\n" .
                    "<h1>{$model_name} Live Chat</h1>\n" .
                    "<p>{$model_name} live chat gives fans a direct way to connect in real time, watch streams, and discover the latest updates in one place.</p>\n\n" .
                    "<h2>Watch {$model_name} Live on Webcam</h2>\n" .
                    "<p>Browse current streams and see when {$model_name} is online for live webcam sessions.</p>\n\n" .
                    "<h2>Why Fans Love {$model_name}</h2>\n" .
                    "<p>Fans return for engaging interactions, consistent schedules, and a friendly on-camera style.</p>\n\n" .
                    "<h2>{$model_name} Live Chat Features</h2>\n" .
                    "<p>Find chat options, profile highlights, and fast access to the most important updates.</p>\n\n" .
                    "<h2>{$model_name} Webcam Shows</h2>\n" .
                    "<p>Explore show formats, stream themes, and related content to match viewer preferences.</p>\n\n" .
                    "<h2>FAQ About {$model_name}</h2>\n" .
                    "<p><strong>Q:</strong> How can I watch {$model_name} live?<br><strong>A:</strong> Use the live section above to join available streams.</p>\n";
            } else {
                $placeholder_content = "\n" .
                    "<h2>About {$post->post_title}</h2>\n" .
                    "<p>This is structured SEO placeholder content generated in Dry Run Mode.</p>\n\n" .
                    "<h2>Why Watch {$post->post_title}</h2>\n" .
                    "<p>Detailed keyword-rich description would appear here.</p>\n\n" .
                    "<h2>Related Models & Scenes</h2>\n" .
                    "<p>Internal linking structure placeholder.</p>\n\n" .
                    "<p><strong>SEO Meta Description:</strong> Optimized preview for {$post->post_title}.</p>\n";
            }

            $generated_content = $placeholder_content;
            $seo_title = TitleFixer::shorten(trim((string)$post->post_title), 60);
            $meta_desc = trim(wp_strip_all_tags($post->post_excerpt !== '' ? $post->post_excerpt : 'Optimized preview for ' . $post->post_title . '.'));
            $meta_desc = TitleFixer::shorten($meta_desc, 160);
            $focus_kw  = trim((string)get_post_meta($post_id, '_tmwseo_keyword', true));
            if ($focus_kw === '') {
                $focus_kw = trim((string)$post->post_title);
            }
            $focus_kw = self::normalize_focus_keyword_for_post($post, $focus_kw);
            error_log('TMW run_optimize_job CONTENT_LENGTH=' . strlen($generated_content));
            error_log('TMW run_optimize_job UPDATING POST');

            wp_update_post([
                'ID'           => $post_id,
                'post_content' => $placeholder_content,
            ]);

            if ($seo_title !== '') update_post_meta($post_id, 'rank_math_title', $seo_title);
            if ($meta_desc !== '') update_post_meta($post_id, 'rank_math_description', $meta_desc);
            if ($focus_kw !== '') {
                update_post_meta($post_id, 'rank_math_focus_keyword', $focus_kw);
                update_post_meta($post_id, '_tmwseo_keyword', $focus_kw);
                self::update_model_secondary_keywords_for_post($post, $focus_kw);
            }

            self::maybe_clear_rank_math_noindex($post);
            error_log('TMW run_optimize_job UPDATE COMPLETE');

            delete_post_meta($post_id, '_tmwseo_optimize_enqueued');
            update_post_meta($post_id, '_tmwseo_optimize_done', 'dry_run');

            Logs::info('content', 'Dry run content generated', [
                'post_id' => $post_id,
            ]);

            error_log('TMW run_optimize_job EARLY RETURN');
            return;
        }

        if (!OpenAI::is_configured()) {

            error_log('TMW OFFLINE DRY MODE ACTIVE');

            $seo_title = 'SEO Title for Post ' . $post_id;
            $meta_desc = 'This is a generated meta description for post ' . $post_id . '.';
            $focus_kw  = 'sample keyword ' . $post_id;
            $focus_kw = self::normalize_focus_keyword_for_post($post, $focus_kw);

            $generated_content = '<h2>SEO Optimized Content</h2>';
            $generated_content .= '<p>This content was generated in offline dry mode.</p>';
            $generated_content .= '<p>Post ID: ' . $post_id . '</p>';
            error_log('TMW run_optimize_job CONTENT_LENGTH=' . strlen($generated_content));

            error_log('TMW run_optimize_job UPDATING POST');

            wp_update_post([
                'ID' => $post_id,
                'post_content' => $generated_content,
            ]);

            if ($seo_title !== '') update_post_meta($post_id, 'rank_math_title', $seo_title);
            if ($meta_desc !== '') update_post_meta($post_id, 'rank_math_description', $meta_desc);
            if ($focus_kw !== '') {
                update_post_meta($post_id, 'rank_math_focus_keyword', $focus_kw);
                update_post_meta($post_id, '_tmwseo_keyword', $focus_kw);
                self::update_model_secondary_keywords_for_post($post, $focus_kw);
            }

            self::maybe_clear_rank_math_noindex($post);
            error_log('TMW run_optimize_job UPDATE COMPLETE');

            delete_post_meta($post_id, '_tmwseo_optimize_enqueued');
            update_post_meta($post_id, '_tmwseo_optimize_done', 'offline_dry');

            Logs::info('content', 'Offline fallback content generated', [
                'post_id' => $post_id,
            ]);

            error_log('TMW OFFLINE MODE COMPLETE');

            return;
        }

        $payload = $job['payload'] ?? [];
        if (!is_array($payload)) $payload = [];

        $context = (string)($payload['context'] ?? self::infer_context($post));
        $keyword = (string)($payload['keyword'] ?? get_post_meta($post_id, '_tmwseo_keyword', true));
        $secondary_keywords = [];
        if ($post->post_type === 'model') {
            $primary_keyword = self::normalize_focus_keyword_for_post($post, $keyword);
            $secondary_keywords = self::build_model_secondary_keywords($primary_keyword);
        }

        $clean_title = TitleFixer::fix((string)$post->post_title);
        $clean_title_short = TitleFixer::shorten($clean_title, 70);

        $model = Settings::openai_model_for_quality();

        $length_hint = ($context === 'keyword_page' || $context === 'model' || $context === 'category_page') ? '800-1000 words' : '250-400 words';

        $system = [
            'role' => 'system',
            'content' =>
                "You are an SEO copywriter for top-models.webcam.\n" .
                "Write informative, helpful content about adult webcam / live video chat.\n" .
                "Keep language non-explicit and safe: do NOT describe graphic sexual acts.\n" .
                "Focus on user intent (features, safety, privacy, etiquette, what to expect).\n" .
                "Output STRICT JSON with keys: seo_title, meta_description, focus_keyword, content_html.\n" .
                "seo_title <= 60 characters. meta_description 150-160 characters.\n" .
                "content_html must be valid HTML (h1, h2, h3, p, ul, li).\n"
        ];

        $user_content =
            "PAGE CONTEXT\n" .
            "- Post type: {$post->post_type}\n" .
            "- Context: {$context}\n" .
            "- Current title (cleaned): {$clean_title_short}\n" .
            ($keyword ? "- Primary keyword: {$keyword}\n" : '') .
            (!empty($secondary_keywords) ? "- Secondary keywords: " . implode(', ', $secondary_keywords) . "\n" : '') .
            "- Target length: {$length_hint}\n" .
            "\n" .
            "WRITE:\n" .
            "1) SEO title that matches the page and includes the keyword naturally.\n" .
            "2) Meta description with a clear value proposition.\n" .
            "3) One focus keyword (short).\n" .
            "4) content_html with structured headings and an FAQ section (3-5 Q&As).\n";

        $is_model_page = ($post->post_type === 'model');

        if ($is_model_page) {
            $primary_keyword = self::normalize_focus_keyword_for_post($post, $keyword !== '' ? $keyword : (string)$post->post_title);
            $user_content .= "\nMODEL PAGE TEMPLATE (required):\n" .
                "- Use this exact heading structure in content_html:\n" .
                "  H1: {$primary_keyword} Live Chat\n" .
                "  Intro paragraph including the exact primary keyword: {$primary_keyword}\n" .
                "  H2: Watch {$primary_keyword} Live on Webcam\n" .
                "  H2: Why Fans Love {$primary_keyword}\n" .
                "  H2: {$primary_keyword} Live Chat Features\n" .
                "  H2: {$primary_keyword} Webcam Shows\n" .
                "  H2: FAQ About {$primary_keyword}\n" .
                "- Ensure the primary keyword appears in the H1, intro, and at least 3 H2 headings.\n" .
                "- content_html must contain at least " . self::MODEL_MIN_WORDS . " words.\n" .
                "- Expand each section with descriptive paragraphs and practical details.\n" .
                "- Keep keyword density for the exact primary keyword between " . self::MODEL_MIN_KEYWORD_DENSITY . "% and " . self::MODEL_MAX_KEYWORD_DENSITY . "%.\n";
        }

        $user = [
            'role' => 'user',
            'content' => $user_content,
        ];

        error_log('TMW run_optimize_job GENERATING CONTENT');
        $max_tokens = $is_model_page ? 5200 : 2200;

        $res = OpenAI::chat_json([$system, $user], $model, [
            'temperature' => 0.6,
            'max_tokens' => $max_tokens,
        ]);

        if (!$res['ok']) {
            Logs::error('content', 'OpenAI generation failed', ['post_id' => $post_id, 'error' => $res['error'] ?? '']);
            delete_post_meta($post_id, '_tmwseo_optimize_enqueued');
            error_log('TMW run_optimize_job EARLY RETURN');
            return;
        }

        $j = $res['json'] ?? [];
        $seo_title = isset($j['seo_title']) ? (string)$j['seo_title'] : '';
        $meta_desc = isset($j['meta_description']) ? (string)$j['meta_description'] : '';
        $focus_kw  = isset($j['focus_keyword']) ? (string)$j['focus_keyword'] : '';
        $html      = (isset($j['content_html']) && is_string($j['content_html'])) ? $j['content_html'] : '';

        $seo_title = TitleFixer::shorten(trim($seo_title), 60);
        $meta_desc = trim($meta_desc);
        $focus_kw  = trim($focus_kw);
        $focus_kw  = self::normalize_focus_keyword_for_post($post, $focus_kw);

        if ($is_model_page) {
            $validated = self::enforce_model_content_constraints([$system, $user], $model, $max_tokens, $focus_kw, $html);
            $html = $validated['html'];
            $focus_kw = $validated['focus_keyword'];
        }

        $html      = wp_kses_post(trim($html));
        $generated_content = $html;
        error_log('TMW run_optimize_job CONTENT_LENGTH=' . strlen($generated_content));

        if ($seo_title !== '') update_post_meta($post_id, 'rank_math_title', $seo_title);
        if ($meta_desc !== '') update_post_meta($post_id, 'rank_math_description', $meta_desc);
        if ($focus_kw !== '') {
            update_post_meta($post_id, 'rank_math_focus_keyword', $focus_kw);
            update_post_meta($post_id, '_tmwseo_keyword', $focus_kw);
            self::update_model_secondary_keywords_for_post($post, $focus_kw);
        }

        // Update content via a dedicated marker block.
        $new_content = self::upsert_ai_block((string)$post->post_content, $html);

        // Only update post if content actually changed.
        if ($new_content !== (string)$post->post_content) {
            error_log('TMW run_optimize_job UPDATING POST');
            wp_update_post([
                'ID' => $post_id,
                'post_content' => $new_content,
            ]);
            error_log('TMW run_optimize_job UPDATE COMPLETE');
        }

        delete_post_meta($post_id, '_tmwseo_optimize_enqueued');
        update_post_meta($post_id, '_tmwseo_optimize_done', current_time('mysql'));

        self::maybe_clear_rank_math_noindex($post);

        Logs::info('content', 'Optimized', ['post_id' => $post_id, 'context' => $context, 'model' => $model]);
    }

    private static function enforce_model_content_constraints(array $messages, string $model, int $max_tokens, string $focus_kw, string $html): array {
        $focus_kw = trim($focus_kw);
        $attempts = 0;

        while ($attempts < 2) {
            $word_count = self::count_words($html);
            $density = self::keyword_density_percent($html, $focus_kw);

            if ($word_count >= self::MODEL_MIN_WORDS && $density >= self::MODEL_MIN_KEYWORD_DENSITY && $density <= self::MODEL_MAX_KEYWORD_DENSITY) {
                break;
            }

            $feedback = "Rewrite content_html only and return full JSON again while preserving SEO intent.\n" .
                "- Minimum words: " . self::MODEL_MIN_WORDS . " (current: {$word_count}).\n" .
                "- Expand sections with additional descriptive paragraphs.\n" .
                "- Keep exact focus keyword density between " . self::MODEL_MIN_KEYWORD_DENSITY . "% and " . self::MODEL_MAX_KEYWORD_DENSITY . "% (current: " . round($density, 2) . "%).\n" .
                "- Focus keyword must remain: {$focus_kw}.\n";

            $retry_messages = $messages;
            $retry_messages[] = [
                'role' => 'assistant',
                'content' => wp_json_encode([
                    'focus_keyword' => $focus_kw,
                    'content_html' => $html,
                ]),
            ];
            $retry_messages[] = [
                'role' => 'user',
                'content' => $feedback,
            ];

            $retry = OpenAI::chat_json($retry_messages, $model, [
                'temperature' => 0.5,
                'max_tokens' => $max_tokens,
            ]);

            if (!$retry['ok']) {
                break;
            }

            $json = $retry['json'] ?? [];
            $new_html = (isset($json['content_html']) && is_string($json['content_html'])) ? trim($json['content_html']) : '';
            $new_focus = isset($json['focus_keyword']) ? trim((string)$json['focus_keyword']) : '';

            if ($new_html !== '') {
                $html = $new_html;
            }
            if ($new_focus !== '') {
                $focus_kw = $new_focus;
            }

            $attempts++;
        }

        return [
            'html' => $html,
            'focus_keyword' => $focus_kw,
        ];
    }

    private static function count_words(string $html): int {
        $text = trim((string) wp_strip_all_tags($html));
        if ($text === '') return 0;

        preg_match_all('/\b[\p{L}\p{N}\']+\b/u', $text, $matches);
        return isset($matches[0]) && is_array($matches[0]) ? count($matches[0]) : 0;
    }

    private static function keyword_density_percent(string $html, string $keyword): float {
        $keyword = trim($keyword);
        $word_count = self::count_words($html);
        if ($keyword === '' || $word_count === 0) return 0.0;

        $text = mb_strtolower((string) wp_strip_all_tags($html), 'UTF-8');
        $needle = mb_strtolower($keyword, 'UTF-8');
        $pattern = '/\b' . preg_quote($needle, '/') . '\b/u';
        preg_match_all($pattern, $text, $matches);
        $occurrences = isset($matches[0]) && is_array($matches[0]) ? count($matches[0]) : 0;

        return ($occurrences / $word_count) * 100;
    }

    private static function maybe_clear_rank_math_noindex(\WP_Post $post): void {
        if ($post->post_status !== 'publish') return;
        if (!in_array($post->post_type, ['model', 'tmw_category_page'], true)) return;
        if (get_post_meta($post->ID, '_tmwseo_generated', true)) return;

        delete_post_meta($post->ID, 'rank_math_robots');
    }

    private static function upsert_ai_block(string $content, string $html): string {
        $marker = "<!-- TMWSEO:AI -->";
        $html = trim($html);

        if ($html === '') return $content;

        if (strpos($content, $marker) !== false) {
            $parts = explode($marker, $content, 2);
            $before = rtrim($parts[0]);
            return $before . "\n" . $marker . "\n" . $html . "\n";
        }

        // Append at end if marker doesn't exist.
        $content = rtrim($content);
        if ($content !== '') $content .= "\n\n";
        return $content . $marker . "\n" . $html . "\n";
    }
}
