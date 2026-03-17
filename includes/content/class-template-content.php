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
        $external_link_html = self::render_contextual_external_link();

        $content_parts = [];

        // H1: bare model name — audit fix: no forced suffix.
        $content_parts[] = '<h1>' . esc_html($name) . '</h1>';

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

        // Features section — audit fix: tag-aware, not repeated static bullets.
        $content_parts[] = '<h2>' . esc_html($name) . ' on ' . esc_html($primary_platform_label) . '</h2>';
        $content_parts[] = self::render_varied_features($name, $tags, $primary_platform_label, $seed);

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
            $content_parts[] = '<h2>Explore more</h2>';
            $content_parts[] = $internal_links;
        }

        // Contextual external link (optional)
        if ($external_link_html !== '') {
            $content_parts[] = '<h2>Learn more</h2>';
            $content_parts[] = $external_link_html;
        }

        $content = implode("\n\n", $content_parts);

        // Audit fix: removed repeated filler paragraph that created detectable patterns.
        // Only add one unique closing paragraph if content is very short.
        $wc = str_word_count(strip_tags($content));
        if ($wc < 400) {
            $content .= "\n\n" . '<p>Browse ' . esc_html($name) . '\'s latest videos, explore the tags on this page, and use the official links to connect directly.</p>';
        }

        // Audit fix: SEO title uses entity-name-first pattern, not forced suffix.
        $seo_title = $name . ' — Live Cam Profile';
        if ($primary_platform_label !== '' && $primary_platform_label !== 'live webcam') {
            $seo_title = $name . ' on ' . $primary_platform_label . ' — Live Cam Profile';
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

            return '<p><a href="' . esc_url($go_url) . '" target="_blank" rel="sponsored nofollow noopener">' . esc_html('Watch ' . $name . ' on ' . $label) . '</a></p>';
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

            $lis .= '<li><a href="' . esc_url($url) . '" target="_blank" rel="sponsored nofollow noopener">' . esc_html($name . ' on ' . $label) . '</a></li>';
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
        $links = [
            '<li><a href="' . esc_url(home_url('/models/')) . '">Models</a></li>',
            '<li><a href="' . esc_url(home_url('/categories/')) . '">Categories</a></li>',
        ];

        $top_terms = [];
        $tags = get_the_terms($post, 'post_tag');
        if (is_array($tags)) {
            usort($tags, static function (\WP_Term $a, \WP_Term $b): int {
                if ((int) $a->count === (int) $b->count) {
                    return strnatcasecmp((string) $a->name, (string) $b->name);
                }

                return (int) $b->count <=> (int) $a->count;
            });

            foreach ($tags as $tag) {
                $term_link = get_term_link($tag);
                if (is_wp_error($term_link)) {
                    continue;
                }

                $top_terms['post_tag:' . $tag->term_id] = [
                    'name' => (string) $tag->name,
                    'url' => (string) $term_link,
                ];

                if (count($top_terms) >= 2) {
                    break;
                }
            }
        }

        if (count($top_terms) < 2) {
            $categories = get_the_terms($post, 'category');
            if (is_array($categories)) {
                usort($categories, static function (\WP_Term $a, \WP_Term $b): int {
                    if ((int) $a->count === (int) $b->count) {
                        return strnatcasecmp((string) $a->name, (string) $b->name);
                    }

                    return (int) $b->count <=> (int) $a->count;
                });

                foreach ($categories as $category) {
                    $key = 'category:' . $category->term_id;
                    if (isset($top_terms[$key])) {
                        continue;
                    }

                    $term_link = get_term_link($category);
                    if (is_wp_error($term_link)) {
                        continue;
                    }

                    $top_terms[$key] = [
                        'name' => (string) $category->name,
                        'url' => (string) $term_link,
                    ];

                    if (count($top_terms) >= 2) {
                        break;
                    }
                }
            }
        }

        foreach (array_slice(array_values($top_terms), 0, 2) as $term) {
            $links[] = '<li><a href="' . esc_url($term['url']) . '">' . esc_html($term['name']) . '</a></li>';
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

            $items[] = '<li><a href="' . esc_url($url) . '" target="_blank" rel="sponsored nofollow noopener">' . esc_html('Watch ' . $name . ' on ' . $platform) . '</a></li>';

            if (count($items) >= 4) {
                break;
            }
        }

        if (empty($items)) {
            return '';
        }

        return '<ul>' . implode('', $items) . '</ul>';
    }

    private static function render_contextual_external_link(): string {
        $enabled = (bool) Settings::get('include_external_info_link', 0);
        if (!$enabled) {
            return '';
        }

        return '<h3>What is a webcam model?</h3><p><a href="https://en.wikipedia.org/wiki/Webcam_model" target="_blank" rel="nofollow noopener">Read this informational overview on Wikipedia</a>.</p>';
    }

    /**
     * Generate tag-aware feature descriptions instead of a static repeated list.
     *
     * Audit fix: every model page previously had the identical 4-bullet feature
     * list, creating a detectable template pattern. This method selects features
     * based on the model's actual tags, making each page more unique.
     */
    private static function render_varied_features(string $name, array $tags, string $platform, string $seed): string {
        $tag_phrases = array_map(fn($t) => str_replace('-', ' ', (string)$t), array_slice($tags, 0, 6));
        $tag_phrases = array_filter($tag_phrases, fn($t) => $t !== '' && strlen($t) >= 3);

        // Feature pool — each model page picks a subset based on seed + tags.
        $pool = [
            '<li><strong>Live interaction:</strong> ' . esc_html($name) . ' responds to chat in real time, creating a personal feel.</li>',
            '<li><strong>HD video quality:</strong> Streams on ' . esc_html($platform) . ' are delivered in high definition with stable audio.</li>',
            '<li><strong>Privacy-first browsing:</strong> Platform controls let you watch without sharing personal details.</li>',
            '<li><strong>Mobile-friendly:</strong> Join ' . esc_html($name) . '\'s room from any device with a modern browser.</li>',
            '<li><strong>Notification alerts:</strong> Follow ' . esc_html($name) . ' on ' . esc_html($platform) . ' to get pinged when a new session starts.</li>',
            '<li><strong>Schedule flexibility:</strong> Sessions rotate, so check back regularly for updated times.</li>',
            '<li><strong>Respectful community:</strong> Moderation keeps the chat positive and on-topic.</li>',
            '<li><strong>Interactive features:</strong> Polls, tip-triggered actions, and two-way conversation keep sessions engaging.</li>',
        ];

        // Add tag-specific features.
        foreach (array_slice($tag_phrases, 0, 2) as $tag) {
            $pool[] = '<li><strong>' . esc_html(ucfirst($tag)) . ' content:</strong> Fans of ' . esc_html($tag) . ' will find ' . esc_html($name) . '\'s shows match that style.</li>';
        }

        // Deterministic selection based on seed (same model = same features).
        $hash = abs(crc32($seed));
        $selected = [];
        $count = min(5, count($pool));
        $indices = [];
        for ($i = 0; $i < $count; $i++) {
            $idx = ($hash + $i * 3) % count($pool);
            while (in_array($idx, $indices, true)) {
                $idx = ($idx + 1) % count($pool);
            }
            $indices[] = $idx;
            $selected[] = $pool[$idx];
        }

        return '<ul>' . implode("\n", $selected) . '</ul>';
    }

}
