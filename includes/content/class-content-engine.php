<?php
namespace TMWSEO\Engine\Content;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Jobs;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Services\TitleFixer;
use TMWSEO\Engine\Services\OpenAI;

if (!defined('ABSPATH')) { exit; }

class ContentEngine {

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

    public static function run_optimize_job(array $job): void {
        $post_id = (int)($job['entity_id'] ?? 0);
        if ($post_id <= 0) {
            Logs::warn('content', 'optimize_post missing entity_id');
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            Logs::warn('content', 'Post not found', ['post_id' => $post_id]);
            return;
        }

        if (Settings::is_safe_mode()) {
            Logs::info('content', 'Safe mode enabled; skipping AI generation', ['post_id' => $post_id]);
            delete_post_meta($post_id, '_tmwseo_optimize_enqueued');
            update_post_meta($post_id, '_tmwseo_optimize_done', 'skipped_safe_mode');
            return;
        }

        $dry_run = get_option('tmwseo_dry_run_mode', 0);
        if ($dry_run) {
            $placeholder_content = "\n" .
                "<h2>About {$post->post_title}</h2>\n" .
                "<p>This is structured SEO placeholder content generated in Dry Run Mode.</p>\n\n" .
                "<h2>Why Watch {$post->post_title}</h2>\n" .
                "<p>Detailed keyword-rich description would appear here.</p>\n\n" .
                "<h2>Related Models & Scenes</h2>\n" .
                "<p>Internal linking structure placeholder.</p>\n\n" .
                "<p><strong>SEO Meta Description:</strong> Optimized preview for {$post->post_title}.</p>\n";

            wp_update_post([
                'ID'           => $post_id,
                'post_content' => $placeholder_content,
            ]);

            delete_post_meta($post_id, '_tmwseo_optimize_enqueued');
            update_post_meta($post_id, '_tmwseo_optimize_done', 'dry_run');

            Logs::info('content', 'Dry run content generated', [
                'post_id' => $post_id,
            ]);

            return;
        }

        if (!OpenAI::is_configured()) {
            Logs::warn('content', 'OpenAI not configured; skipping', ['post_id' => $post_id]);
            delete_post_meta($post_id, '_tmwseo_optimize_enqueued');
            return;
        }

        $payload = $job['payload'] ?? [];
        if (!is_array($payload)) $payload = [];

        $context = (string)($payload['context'] ?? self::infer_context($post));
        $keyword = (string)($payload['keyword'] ?? get_post_meta($post_id, '_tmwseo_keyword', true));

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
                "content_html must be valid HTML (p, h2, h3, ul, li).\n"
        ];

        $user = [
            'role' => 'user',
            'content' =>
                "PAGE CONTEXT\n" .
                "- Post type: {$post->post_type}\n" .
                "- Context: {$context}\n" .
                "- Current title (cleaned): {$clean_title_short}\n" .
                ($keyword ? "- Primary keyword: {$keyword}\n" : '') .
                "- Target length: {$length_hint}\n" .
                "\n" .
                "WRITE:\n" .
                "1) SEO title that matches the page and includes the keyword naturally.\n" .
                "2) Meta description with a clear value proposition.\n" .
                "3) One focus keyword (short).\n" .
                "4) content_html with structured headings and an FAQ section (3-5 Q&As).\n"
        ];

        $res = OpenAI::chat_json([$system, $user], $model, [
            'temperature' => 0.6,
            'max_tokens' => 2200,
        ]);

        if (!$res['ok']) {
            Logs::error('content', 'OpenAI generation failed', ['post_id' => $post_id, 'error' => $res['error'] ?? '']);
            delete_post_meta($post_id, '_tmwseo_optimize_enqueued');
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
        $html      = wp_kses_post(trim($html));

        if ($seo_title !== '') update_post_meta($post_id, 'rank_math_title', $seo_title);
        if ($meta_desc !== '') update_post_meta($post_id, 'rank_math_description', $meta_desc);
        if ($focus_kw !== '') update_post_meta($post_id, 'rank_math_focus_keyword', $focus_kw);

        // Update content via a dedicated marker block.
        $new_content = self::upsert_ai_block((string)$post->post_content, $html);

        // Only update post if content actually changed.
        if ($new_content !== (string)$post->post_content) {
            wp_update_post([
                'ID' => $post_id,
                'post_content' => $new_content,
            ]);
        }

        delete_post_meta($post_id, '_tmwseo_optimize_enqueued');
        update_post_meta($post_id, '_tmwseo_optimize_done', current_time('mysql'));

        Logs::info('content', 'Optimized', ['post_id' => $post_id, 'context' => $context, 'model' => $model]);
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
