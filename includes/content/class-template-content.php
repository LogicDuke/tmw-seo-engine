<?php
namespace TMWSEO\Engine\Content;

use TMWSEO\Engine\Templates\TemplateEngine;
use TMWSEO\Engine\Platform\AffiliateLinkBuilder;
use TMWSEO\Engine\Platform\PlatformProfiles;
use TMWSEO\Engine\Platform\PlatformRegistry;
use TMWSEO\Engine\Keywords\ModelKeywordPack;
use TMWSEO\Engine\Services\Settings;

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
        $cta_links = self::build_platform_cta_links($post->ID, $platform_links);
        $active_platforms = [];
        $primary_platform_label = '';
        foreach ($cta_links as $r) {
            $label = (string)($r['label'] ?? '');
            if ($label === '') {
                continue;
            }
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

        $primary_cta_html = self::render_primary_watch_cta($cta_links, $name);
        $links_html = self::render_platform_links($cta_links, $name);
        $watch_cta_section_html = self::render_watch_cta_section($cta_links, $name);

        $internal_links = self::render_internal_links($post);
        $external_link_html = self::render_contextual_external_link($name);

        $content_parts = [];

        // H1
        $content_parts[] = '<h1>' . esc_html($name) . ' Live Chat</h1>';

        // Intro
        $content_parts[] = '<p>' . esc_html($intro) . '</p>';

        // Quick value props
        $content_parts[] = '<h2>Watch ' . esc_html($name) . ' Live on ' . esc_html($primary_platform_label) . '</h2>';
        $content_parts[] = '<p>Fans searching for <strong>' . esc_html($name) . ' live shows</strong> usually want a fast, safe way to join a real-time room. Below you’ll find trusted links, what to expect, and tips to get the most out of your chat.</p>';

        if ($primary_cta_html !== '') {
            $content_parts[] = '<h2>Watch ' . esc_html($name) . ' Live</h2>';
            $content_parts[] = $primary_cta_html;
        }

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

        // Watch CTA section
        if ($watch_cta_section_html !== '') {
            $content_parts[] = '<h2>Watch ' . esc_html($name) . ' on top platforms</h2>';
            $content_parts[] = $watch_cta_section_html;
        }

        // Internal links
        if ($internal_links !== '') {
            $content_parts[] = '<h2>Explore related tags & categories</h2>';
            $content_parts[] = $internal_links;
        }

        // Contextual external link (optional)
        if ($external_link_html !== '') {
            $content_parts[] = '<h2>Learn more</h2>';
            $content_parts[] = $external_link_html;
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

    /**
     * @param array<int,array{platform:string,label:string,go_url:string,is_primary:bool,username:string}> $links
     */
    private static function render_primary_watch_cta(array $links, string $name): string {
        foreach ($links as $link) {
            if (empty($link['is_primary'])) {
                continue;
            }

            $go_url = (string)($link['go_url'] ?? '');
            if ($go_url === '') {
                continue;
            }

            $label = (string)($link['label'] ?? '');
            if ($label === '') {
                $label = 'live cam';
            }

            return '<p><a href="' . esc_url($go_url) . '" target="_blank" rel="nofollow sponsored noopener">' . esc_html('Watch ' . $name . ' Live on ' . $label) . '</a></p>';
        }

        return '';
    }

    /**
     * @param array<int,array{platform:string,label:string,go_url:string,is_primary:bool,username:string}> $links
     */
    private static function render_platform_links(array $links, string $name): string {
        if (count($links) < 2) return '';
        $lis = '';
        foreach ($links as $l) {
            $url = (string)($l['go_url'] ?? '');
            $label = (string)($l['label'] ?? '');
            if ($url === '' || $label === '') continue;

            $lis .= '<li><a href="' . esc_url($url) . '" target="_blank" rel="nofollow sponsored noopener">' . esc_html($name . ' on ' . $label) . '</a></li>';
        }
        if ($lis === '') return '';
        return '<ul>' . $lis . '</ul>';
    }

    /**
     * @param array<int,array{platform?:string,is_primary?:string|int}> $links
     * @return array<int,array{platform:string,label:string,go_url:string,is_primary:bool,username:string}>
     */
    private static function build_platform_cta_links(int $post_id, array $links): array {
        $out = [];

        foreach ($links as $link) {
            $platform = sanitize_key((string)($link['platform'] ?? ''));
            if ($platform === '') {
                continue;
            }

            $username = trim((string)get_post_meta($post_id, '_tmwseo_platform_username_' . $platform, true));
            if ($username === '') {
                continue;
            }

            $go_url = AffiliateLinkBuilder::go_url($platform, $username);
            if ($go_url === '') {
                continue;
            }

            $platform_data = PlatformRegistry::get($platform);
            $label = (string)($platform_data['name'] ?? ucfirst($platform));

            $out[] = [
                'platform' => $platform,
                'label' => $label,
                'go_url' => $go_url,
                'is_primary' => !empty($link['is_primary']),
                'username' => $username,
            ];
        }

        return $out;
    }

    private static function render_internal_links(\WP_Post $post): string {
        $links = [];
        $seen = [];

        $taxonomies = ['category', 'post_tag'];
        $taxonomies = array_unique(array_merge($taxonomies, get_object_taxonomies($post->post_type, 'names')));

        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($post, $taxonomy);
            if (!is_array($terms)) {
                continue;
            }

            foreach ($terms as $term) {
                if (!($term instanceof \WP_Term)) {
                    continue;
                }

                $term_link = get_term_link($term);
                if (is_wp_error($term_link)) {
                    continue;
                }

                if (isset($seen[$term->term_id])) {
                    continue;
                }

                $seen[$term->term_id] = true;
                $label_prefix = ($term->taxonomy === 'post_tag') ? 'Tag' : 'Category';
                $links[] = '<li><a href="' . esc_url($term_link) . '">' . esc_html($label_prefix . ': ' . $term->name) . '</a></li>';

                if (count($links) >= 6) {
                    break 2;
                }
            }
        }

        if (empty($links)) {
            return '';
        }

        return '<ul>' . implode('', $links) . '</ul>';
    }

    /**
     * @param array<int,array{platform:string,label:string,go_url:string,is_primary:bool,username:string}> $links
     */
    private static function render_watch_cta_section(array $links, string $name): string {
        if (empty($links)) {
            return '';
        }

        $items = [];
        foreach ($links as $link) {
            $url = (string)($link['go_url'] ?? '');
            $platform = (string)($link['label'] ?? '');
            if ($url === '' || $platform === '') {
                continue;
            }

            $items[] = '<li><a href="' . esc_url($url) . '" target="_blank" rel="nofollow sponsored noopener">' . esc_html('Watch ' . $name . ' on ' . $platform) . '</a></li>';

            if (count($items) >= 4) {
                break;
            }
        }

        if (empty($items)) {
            return '';
        }

        return '<ul>' . implode('', $items) . '</ul>';
    }

    private static function render_contextual_external_link(string $name): string {
        if (!Settings::get('template_external_link_enabled', 0)) {
            return '';
        }

        $clean_name = trim($name);
        if ($clean_name === '') {
            return '';
        }

        $query_url = 'https://en.wikipedia.org/wiki/Special:Search?search=' . rawurlencode($clean_name);

        return '<p><a href="' . esc_url($query_url) . '" target="_blank" rel="noopener noreferrer">Read more background about ' . esc_html($clean_name) . ' on Wikipedia</a></p>';
    }

}
