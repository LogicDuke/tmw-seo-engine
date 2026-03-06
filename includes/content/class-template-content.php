<?php
namespace TMWSEO\Engine\Content;

use TMWSEO\Engine\Templates\TemplateEngine;
use TMWSEO\Engine\Platform\PlatformProfiles;
use TMWSEO\Engine\Keywords\ModelKeywordPack;

if (!defined('ABSPATH')) { exit; }

/**
 * Template-mode content generator.
 *
 * Produces SEO-friendly content without OpenAI, using deterministic templates
 * (ported from the legacy Autopilot) + the Engine keyword pack.
 */
class TemplateContent {

    /**
     * @param array{primary:string,additional:string[],longtail:string[],sources:array} $pack
     * @return array{content:string, seo_title:string, meta_description:string}
     */
    public static function build_model(\WP_Post $post, array $pack): array {
        $name = trim((string)$pack['primary']);
        if ($name === '') $name = trim((string)$post->post_title);
        if ($name === '') $name = 'Live Cam Model';

        $seed = $name . '-' . $post->ID;

        $platform_links = PlatformProfiles::get_links($post->ID);
        $active_platforms = [];
        $primary_platform_label = '';
        foreach ($platform_links as $r) {
            $slug = (string)($r['platform'] ?? '');
            $label = ucfirst($slug);
            // Try to map label from PlatformProfiles internal map by using rendered metabox labels.
            // Keep slug-based label for now.
            $active_platforms[] = $label;
            if (!empty($r['is_primary'])) {
                $primary_platform_label = $label;
            }
        }
        if ($primary_platform_label === '' && !empty($active_platforms)) {
            $primary_platform_label = $active_platforms[0];
        }
        if ($primary_platform_label === '') {
            $primary_platform_label = 'live webcam';
        }

        $tags = $pack['sources']['tags'] ?? [];
        if (!is_array($tags)) $tags = [];
        $tags_text = !empty($tags) ? implode(', ', array_slice(array_map(fn($t)=>str_replace('-', ' ', (string)$t), $tags), 0, 6)) : 'live webcam shows';

        $extra = $pack['additional'] ?? [];
        if (!is_array($extra)) $extra = [];
        $extra = array_values(array_filter(array_map('trim', $extra), 'strlen'));

        $longtail = $pack['longtail'] ?? [];
        if (!is_array($longtail)) $longtail = [];
        $longtail = array_values(array_filter(array_map('trim', $longtail), 'strlen'));

        $context = [
            'name' => $name,
            'site' => get_bloginfo('name'),
            'tags' => $tags_text,
            'platform_a' => $primary_platform_label,
            'platform_b' => $active_platforms[1] ?? '',
            'live_brand' => $primary_platform_label,
            'extra_focus_1' => $extra[0] ?? ($name . ' live chat'),
            'extra_focus_2' => $extra[1] ?? ($name . ' webcam'),
            'extra_focus_3' => $extra[2] ?? ('watch ' . $name . ' live'),
            'extra_focus_4' => $extra[3] ?? ($name . ' live'),
            'longtail_keywords' => $longtail,
            'active_platforms' => $active_platforms,
        ];

        // Choose intro/faqs based on number of platforms.
        $intro_slug = (!empty($active_platforms) && count($active_platforms) > 1) ? 'model-intros-multi' : 'model-intros';
        $faq_slug   = (!empty($active_platforms) && count($active_platforms) > 1) ? 'model-faqs-multi' : 'model-faqs';

        $intro = TemplateEngine::render(TemplateEngine::pick($intro_slug, $seed), $context);
        $bio   = TemplateEngine::render(TemplateEngine::pick('model-bios', $seed, 1), $context);
        $compare = TemplateEngine::render(TemplateEngine::pick('model-comparisons', $seed), $context);

        $faqs_tpl = TemplateEngine::pick_faq($faq_slug, $seed, 5);
        $faqs_html = self::render_faqs($faqs_tpl, $context);

        $links_html = self::render_platform_links($platform_links, $name);

        $internal_links = self::render_internal_links($post);

        $content_parts = [];

        // H1
        $content_parts[] = '<h1>' . esc_html($name) . ' Live Chat</h1>';

        // Intro
        $content_parts[] = '<p>' . esc_html($intro) . '</p>';

        // Quick value props
        $content_parts[] = '<h2>Watch ' . esc_html($name) . ' Live on ' . esc_html($primary_platform_label) . '</h2>';
        $content_parts[] = '<p>Fans searching for <strong>' . esc_html($name) . ' live shows</strong> usually want a fast, safe way to join a real-time room. Below you’ll find trusted links, what to expect, and tips to get the most out of your chat.</p>';

        // About section
        $content_parts[] = '<h2>About ' . esc_html($name) . '</h2>';
        $content_parts[] = '<p>' . esc_html($bio) . '</p>';

        // Features section
        $content_parts[] = '<h2>' . esc_html($name) . ' Live Chat Features</h2>';
        $content_parts[] = '<ul>'
            . '<li><strong>Real-time interaction:</strong> A friendly chat flow that keeps the room engaging.</li>'
            . '<li><strong>HD streaming:</strong> Clear video and audio so moments feel smooth.</li>'
            . '<li><strong>Custom requests:</strong> Options to tailor your session while staying within platform rules.</li>'
            . '<li><strong>Privacy controls:</strong> Tips to keep personal info protected.</li>'
            . '</ul>';

        // Keyword coverage block (extra focuses)
        if (!empty($extra)) {
            $content_parts[] = '<h2>Popular searches related to ' . esc_html($name) . '</h2>';
            $lis = '';
            foreach (array_slice($extra, 0, 6) as $kw) {
                $lis .= '<li>' . esc_html($kw) . '</li>';
            }
            $content_parts[] = '<ul>' . $lis . '</ul>';
        }

        // Links
        if ($links_html !== '') {
            $content_parts[] = '<h2>Official ' . esc_html($name) . ' Profile Links</h2>';
            $content_parts[] = $links_html;
        }

        // Comparison / context
        if ($compare !== '') {
            $content_parts[] = '<h2>Choosing the best place to chat</h2>';
            $content_parts[] = '<p>' . esc_html($compare) . '</p>';
        }

        // Longtail section
        if (!empty($longtail)) {
            $content_parts[] = '<h2>Long-tail queries we answer</h2>';
            $lis = '';
            foreach (array_slice($longtail, 0, 8) as $kw) {
                $lis .= '<li>' . esc_html($kw) . '</li>';
            }
            $content_parts[] = '<ul>' . $lis . '</ul>';
        }

        // FAQs
        if ($faqs_html !== '') {
            $content_parts[] = '<h2>FAQ</h2>';
            $content_parts[] = $faqs_html;
        }

        // Internal links
        if ($internal_links !== '') {
            $content_parts[] = '<h2>Explore more</h2>';
            $content_parts[] = $internal_links;
        }

        $content = implode("\n\n", $content_parts);

        // Ensure length ~700+ words by padding with a short safe paragraph.
        $wc = str_word_count(strip_tags($content));
        if ($wc < 700) {
            $pad = '<p>Tip: If you’re new, start with a short hello and follow the room etiquette. Staying respectful and keeping personal details private makes every ' . esc_html($name) . ' live chat session smoother.</p>';
            while ($wc < 720) {
                $content .= "\n\n" . $pad;
                $wc = str_word_count(strip_tags($content));
            }
        }

        $seo_title = $name . ' Live Chat - Connect Now';
        if ($primary_platform_label !== '' && $primary_platform_label !== 'live webcam') {
            $seo_title = $name . ' Live Chat on ' . $primary_platform_label . ' - Connect Now';
        }

        $meta_description = 'Join ' . $name . "'s live chat for a real-time experience. Find trusted links, features, privacy tips, and FAQs to get started.";

        return [
            'content' => wp_kses_post($content),
            'seo_title' => $seo_title,
            'meta_description' => $meta_description,
        ];
    }

    /**
     * @param array<int,array{q?:string,a?:string}> $faqs
     */
    private static function render_faqs(array $faqs, array $context): string {
        if (empty($faqs)) return '';
        $out = '';
        foreach ($faqs as $faq) {
            if (!is_array($faq)) continue;
            $q = trim((string)($faq['q'] ?? ''));
            $a = trim((string)($faq['a'] ?? ''));
            if ($q === '' || $a === '') continue;
            $q = TemplateEngine::render($q, $context);
            $a = TemplateEngine::render($a, $context);
            $out .= '<h3>' . esc_html($q) . '</h3>';
            $out .= '<p>' . esc_html($a) . '</p>';
        }
        return $out;
    }

    private static function render_platform_links(array $links, string $name): string {
        if (empty($links)) return '';
        $lis = '';
        foreach ($links as $l) {
            $platform = (string)($l['platform'] ?? '');
            $url = (string)($l['profile_url'] ?? '');
            if ($platform === '' || $url === '') continue;
            $label = ucfirst($platform);
            // For affiliate URLs you might want rel="sponsored". Keep it safe by default.
            $lis .= '<li><a href="' . esc_url($url) . '" target="_blank" rel="sponsored nofollow">' . esc_html($name . ' on ' . $label) . '</a></li>';
        }
        if ($lis === '') return '';
        return '<ul>' . $lis . '</ul>';
    }

    private static function render_internal_links(\WP_Post $post): string {
        // Keep internal linking simple and safe.
        $models_url = home_url('/models/');
        $videos_url = home_url('/videos/');

        $out = '<ul>';
        $out .= '<li><a href="' . esc_url($models_url) . '">Browse all models</a></li>';
        $out .= '<li><a href="' . esc_url($videos_url) . '">Latest videos</a></li>';

        // Add 1-3 category term links if available.
        $taxes = get_object_taxonomies($post->post_type);
        $added = 0;
        foreach ((array)$taxes as $tax) {
            $terms = get_the_terms($post, $tax);
            if (!is_array($terms)) continue;
            foreach ($terms as $t) {
                if (!($t instanceof \WP_Term)) continue;
                $link = get_term_link($t);
                if (is_wp_error($link)) continue;
                $out .= '<li><a href="' . esc_url($link) . '">More ' . esc_html($t->name) . ' content</a></li>';
                $added++;
                if ($added >= 3) break 2;
            }
        }

        $out .= '</ul>';
        return $out;
    }
}
