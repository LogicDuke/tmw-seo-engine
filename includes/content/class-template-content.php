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

    private const NEUTRAL_PLATFORM_FALLBACK = 'official live profile';

    /**
     * @param array{primary:string,additional:string[],longtail:string[],sources:array} $pack
     * @return array{content:string, seo_title:string, meta_description:string}
     */
    public static function build_model(\WP_Post $post, array $pack): array {
        $name = trim((string)($pack['primary'] ?? ''));
        if ($name === '') {
            $name = trim((string)$post->post_title);
        }
        if ($name === '') {
            $name = 'Live Cam Model';
        }

        $seed = $name . '-' . $post->ID;

        $platform_links = PlatformProfiles::get_links($post->ID);
        $cta_links = self::build_platform_cta_links($post->ID, is_array($platform_links) ? $platform_links : []);
        $active_platforms = [];
        $primary_platform_label = '';
        foreach ($cta_links as $row) {
            $label = trim((string)($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $active_platforms[] = $label;
            if ($primary_platform_label === '' && !empty($row['is_primary'])) {
                $primary_platform_label = $label;
            }
        }
        $active_platforms = array_values(array_unique($active_platforms));
        if ($primary_platform_label === '' && !empty($active_platforms)) {
            $primary_platform_label = $active_platforms[0];
        }
        if ($primary_platform_label === '') {
            $primary_platform_label = self::NEUTRAL_PLATFORM_FALLBACK;
        }

        $tags = $pack['sources']['tags'] ?? [];
        if (!is_array($tags) || empty($tags)) {
            $tags = self::discover_model_tags($post);
        }
        $tags = array_values(array_filter(array_map('strval', $tags), 'strlen'));
        $tags_text = !empty($tags)
            ? implode(', ', array_slice(array_map(static fn($t) => str_replace('-', ' ', (string)$t), $tags), 0, 6))
            : 'live webcam shows';

        $extra = is_array($pack['additional'] ?? null) ? $pack['additional'] : [];
        $extra = array_values(array_filter(array_map('trim', $extra), 'strlen'));
        if (empty($extra)) {
            $extra = [
                $name . ' live chat',
                $name . ' webcam',
                'watch ' . $name . ' live',
                $name . ' cam model',
            ];
            if ($primary_platform_label !== self::NEUTRAL_PLATFORM_FALLBACK) {
                $extra[] = $name . ' on ' . $primary_platform_label;
            }
        }
        $extra = array_slice(array_values(array_unique($extra)), 0, 8);

        $longtail = is_array($pack['longtail'] ?? null) ? $pack['longtail'] : [];
        $longtail = array_values(array_filter(array_map('trim', $longtail), 'strlen'));
        if (empty($longtail)) {
            $longtail = [
                $name . ' live shows',
                $name . ' schedule',
                $name . ' profile links',
                $name . ' live stream',
            ];
            if ($primary_platform_label !== self::NEUTRAL_PLATFORM_FALLBACK) {
                $longtail[] = $name . ' ' . $primary_platform_label . ' live';
            }
        }
        $longtail = array_slice(array_values(array_unique($longtail)), 0, 8);

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
            'extra_keywords' => $extra,
            'longtail_keywords' => $longtail,
            'rankmath_additional_keywords' => array_slice($extra, 0, 6),
            'active_platforms' => $active_platforms,
            'active_platforms_text' => self::format_platform_list($active_platforms, $primary_platform_label),
        ];

        $intro_slug = (!empty($active_platforms) && count($active_platforms) > 1) ? 'model-intros-multi' : 'model-intros';
        $faq_slug   = (!empty($active_platforms) && count($active_platforms) > 1) ? 'model-faqs-multi' : 'model-faqs';

        $intro = self::cleanup_visible_text(TemplateEngine::render(TemplateEngine::pick($intro_slug, $seed), $context), $name, false);
        $bio = self::cleanup_visible_text(TemplateEngine::render(TemplateEngine::pick('model-bios', $seed, 1), $context), $name, false);
        $comparison_copy = self::cleanup_visible_text(TemplateEngine::render(TemplateEngine::pick('model-comparisons', $seed), $context), $name, false);

        $faqs_tpl = TemplateEngine::pick_faq($faq_slug, $seed, 5);
        $faqs_html = self::render_faqs($faqs_tpl, $context);

        $primary_cta_html = self::render_primary_watch_cta($cta_links, $name);
        $platform_comparison_html = self::build_platform_comparison($post, $name, $cta_links, $comparison_copy);
        $keyword_coverage_html = self::render_rankmath_keyword_coverage(array_slice($extra, 0, 6), $name);
        $longtail_html = self::render_longtail_section($longtail, $name);
        $focus_blocks_html = self::render_focus_blocks($context, $name);
        $related_models_html = self::render_related_models($post, $name, $tags, $active_platforms);
        $internal_links = self::render_internal_links($post);
        $external_link_html = self::render_contextual_external_link();
        $watch_cta_section_html = self::render_watch_cta_section($cta_links, $name);

        $platform_heading = $primary_platform_label === self::NEUTRAL_PLATFORM_FALLBACK
            ? 'Watch ' . $name . ' Live'
            : 'Watch ' . $name . ' Live on ' . $primary_platform_label;

        $sections = [
            '<p>' . esc_html($intro) . '</p>',
            '<h2>' . esc_html($platform_heading) . '</h2>',
            '<p>Fans searching for <strong>' . esc_html($name) . ' live shows</strong> usually want a fast, safe way to join a real-time room. This guide highlights official profile links, what to expect, and the best ways to enjoy a quality live chat experience.</p>',
            $primary_cta_html !== '' ? '<h2>Watch ' . esc_html($name) . ' Live</h2>' . $primary_cta_html : '',
            '<h2>About ' . esc_html($name) . '</h2><p>' . esc_html($bio) . '</p>',
            $focus_blocks_html,
            '<h2>' . esc_html(self::build_clean_platform_section_heading($name, $primary_platform_label)) . '</h2>' . self::render_varied_features($name, $tags, $primary_platform_label, $seed),
            $keyword_coverage_html,
            $platform_comparison_html,
            $longtail_html,
            $faqs_html,
            $watch_cta_section_html !== '' ? '<h2>Where to watch ' . esc_html($name) . '</h2>' . $watch_cta_section_html : '',
            $related_models_html,
            $internal_links !== '' ? '<h2>Explore more</h2>' . $internal_links : '',
            $external_link_html !== '' ? '<h2>Learn more</h2>' . $external_link_html : '',
        ];

        $content = implode("\n\n", array_values(array_filter($sections, static fn($part) => trim((string)$part) !== '')));
        $content = self::split_long_paragraphs($content);
        $content = self::balance_focus_density($content, $name, $active_platforms, $extra);
        $content = self::pad_model_content($content, $name, $active_platforms, $extra, $longtail, $tags_text);

        if (self::similarity_score($content, (int)$post->ID) > 70.0) {
            $content .= "\n\n" . '<h2>Why ' . esc_html($name) . ' stands out</h2><p>This profile highlights ' . esc_html($name) . ' with current platform availability, related search intent, and a curated mix of ' . esc_html($tags_text) . ' cues tailored to this page.</p>';
            $content = self::split_long_paragraphs($content);
        }

        $content = self::pad_model_content($content, $name, $active_platforms, $extra, $longtail, $tags_text);
        $content = self::cleanup_model_content($content, $name);

        $seo_title = $name . ' — Live Cam Profile';
        if ($primary_platform_label !== '' && $primary_platform_label !== self::NEUTRAL_PLATFORM_FALLBACK) {
            $seo_title = $name . ' on ' . $primary_platform_label . ' — Live Cam Profile';
        }

        $meta_description = 'Join ' . $name . "'s live chat";
        if ($primary_platform_label !== '' && $primary_platform_label !== self::NEUTRAL_PLATFORM_FALLBACK) {
            $meta_description .= ' on ' . $primary_platform_label;
        }
        $meta_description .= '. Find trusted links, top features, privacy tips, FAQs, and related searches to get started.';

        return [
            'content' => wp_kses_post($content),
            'seo_title' => $seo_title,
            'meta_description' => $meta_description,
        ];
    }

    private static function render_focus_blocks(array $context, string $name): string {
        $focus1 = trim((string)($context['extra_focus_1'] ?? ''));
        $focus2 = trim((string)($context['extra_focus_2'] ?? ''));
        $focus3 = trim((string)($context['extra_focus_3'] ?? ''));
        $focus4 = trim((string)($context['extra_focus_4'] ?? ''));

        $blocks = [];
        if ($focus1 !== '') {
            $heading = self::build_focus_heading($focus1, $name, 2, 'Why viewers choose ' . $name);
            $body_focus = self::cleanup_visible_text($focus1, $name, false);
            $blocks[] = '<h2>' . esc_html($heading) . '</h2>';
            $blocks[] = '<p>Viewers interested in ' . esc_html($body_focus) . ' usually want a room that feels interactive, respectful, and easy to join. ' . esc_html($name) . ' matches that intent with a more personal live-chat experience.</p>';
        }
        if ($focus2 !== '') {
            $heading = self::build_focus_heading($focus2, $name, 3, $name . ' live chat experience');
            $body_focus = self::cleanup_visible_text($focus2, $name, false);
            $blocks[] = '<h3>' . esc_html($heading) . '</h3>';
            $blocks[] = '<p>Expect a chat style that keeps the focus on ' . esc_html($body_focus) . ' without feeling repetitive. That makes the page useful for both first-time visitors and returning fans.</p>';
        }
        if ($focus3 !== '') {
            $heading = self::build_focus_heading($focus3, $name, 3, 'How to watch ' . $name . ' live');
            $body_focus = self::cleanup_visible_text($focus3, $name, false);
            $blocks[] = '<h3>' . esc_html($heading) . '</h3>';
            $blocks[] = '<p>Fans searching for ' . esc_html($body_focus) . ' usually want a direct route to a live room and clear profile links. This guide is designed to satisfy that exact intent.</p>';
        }
        if ($focus4 !== '') {
            $heading = self::build_focus_heading($focus4, $name, 3, 'What to expect from ' . $name);
            $body_focus = self::cleanup_visible_text($focus4, $name, false);
            $blocks[] = '<h3>' . esc_html($heading) . '</h3>';
            $blocks[] = '<p>Sessions built around ' . esc_html($body_focus) . ' work best when the viewer can compare platforms quickly and choose the right room with confidence.</p>';
        }

        return implode("\n", $blocks);
    }

    private static function render_rankmath_keyword_coverage(array $keywords, string $name): string {
        $keywords = array_values(array_filter(array_map('trim', $keywords), 'strlen'));
        if (empty($keywords)) {
            return '';
        }

        $out  = '<h2>Popular searches related to ' . esc_html($name) . '</h2>';
        $out .= '<p>People looking for ' . esc_html($name) . ' often search for these related phrases before choosing a room or platform:</p><ul>';
        foreach ($keywords as $keyword) {
            $out .= '<li>' . esc_html(self::cleanup_visible_text($keyword, $name, false)) . '</li>';
        }
        $out .= '</ul>';

        return $out;
    }

    /**
     * @param array<int,array{q?:string,a?:string}> $faqs
     */
    private static function render_faqs(array $faqs, array $context): string {
        if (empty($faqs)) return '';

        $name = trim((string)($context['name'] ?? ''));
        if ($name === '') {
            $name = 'the performer';
        }

        $out = '<h2>FAQ About ' . esc_html($name) . '</h2>';
        foreach ($faqs as $faq) {
            if (!is_array($faq)) continue;
            $q = trim((string)($faq['q'] ?? ''));
            $a = trim((string)($faq['a'] ?? ''));
            if ($q === '' || $a === '') continue;
            $q = self::cleanup_visible_text(TemplateEngine::render($q, $context), $name, true);
            $a = self::cleanup_visible_text(TemplateEngine::render($a, $context), $name, false);
            if ($q === '' || $a === '') {
                continue;
            }
            $out .= '<h3>' . esc_html($q) . '</h3>';
            $out .= '<p>' . esc_html($a) . '</p>';
        }
        return $out;
    }

    private static function build_platform_comparison(\WP_Post $post, string $name, array $cta_links, string $comparison_copy): string {
        $comparison_copy = self::cleanup_visible_text($comparison_copy, $name, false);

        if (empty($cta_links)) {
            $fallback = 'Use trusted official profile links and compare room features before you join ' . $name . '.';
            return '<h2>Choosing the best place to chat</h2><p>' . esc_html($comparison_copy !== '' ? $comparison_copy : $fallback) . '</p>';
        }

        $rows = '';
        foreach (array_slice($cta_links, 0, 4) as $link) {
            $label = trim((string)($link['label'] ?? ''));
            $username = trim((string)($link['username'] ?? ''));
            $url = trim((string)($link['go_url'] ?? ''));
            if ($label === '' || $username === '') {
                continue;
            }
            $rows .= '<tr>'
                . '<td>' . esc_html($label) . '</td>'
                . '<td>@' . esc_html($username) . '</td>'
                . '<td><a href="' . esc_url($url) . '" target="_blank" rel="sponsored nofollow noopener">Watch live</a></td>'
                . '</tr>';
        }

        $table = '';
        if ($rows !== '') {
            $table = '<table><thead><tr><th>Platform</th><th>Profile</th><th>Link</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        }

        $fallback = 'Check where ' . $name . ' is active, compare room features, and use the official watch links below.';

        return '<h2>Compare ' . esc_html($name) . ' across platforms</h2>'
            . '<p>' . esc_html($comparison_copy !== '' ? $comparison_copy : $fallback) . '</p>'
            . $table;
    }

    private static function render_related_models(\WP_Post $post, string $name, array $tags, array $active_platforms): string {
        $query_args = [
            'post_type'      => 'model',
            'posts_per_page' => 4,
            'post_status'    => 'publish',
            'post__not_in'   => [(int)$post->ID],
            'orderby'        => 'rand',
        ];

        $related = get_posts($query_args);
        if (empty($related)) {
            return '';
        }

        $intro = 'If you enjoy ' . $name . ', you may also want to browse similar profile pages for more live-chat options.';
        if (!empty($active_platforms)) {
            $intro = 'If you enjoy ' . $name . ' on ' . self::format_platform_list($active_platforms, $active_platforms[0]) . ', you may also want to compare a few similar model profiles.';
        }

        $items = '';
        foreach ($related as $rel) {
            $title = trim((string)get_the_title($rel->ID));
            if ($title === '') {
                continue;
            }
            $items .= '<li><a href="' . esc_url(get_permalink($rel->ID)) . '">' . esc_html($title) . '</a></li>';
        }

        if ($items === '') {
            return '';
        }

        return '<h2>Related models</h2><p>' . esc_html($intro) . '</p><ul>' . $items . '</ul>';
    }

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
     * @param array<int,array{platform?:string,is_primary?:string|int,username?:string,url?:string}> $links
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
                $username = trim((string)($link['username'] ?? ''));
            }
            if ($username === '') {
                continue;
            }

            $go_url = AffiliateLinkBuilder::go_url($platform, $username);
            if ($go_url === '') {
                $go_url = trim((string)($link['url'] ?? ''));
            }
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

    private static function render_longtail_section(array $longtail_keywords, string $name): string {
        $items = array_slice(array_values(array_unique(array_filter(array_map('trim', $longtail_keywords), 'strlen'))), 0, 4);
        if (empty($items)) {
            return '';
        }

        $out  = '<h2>Questions fans ask about ' . esc_html($name) . '</h2>';
        foreach ($items as $kw) {
            $heading = self::build_longtail_heading($kw, $name);
            $body_kw = self::cleanup_visible_text($kw, $name, false);
            $out .= '<h3>' . esc_html($heading) . '</h3>';
            $out .= '<p>Visitors searching for ' . esc_html($body_kw) . ' usually want a direct route to current profile links, a quick understanding of the show style, and confidence that they are choosing the right live room.</p>';
        }

        return $out;
    }

    private static function pad_model_content(string $content, string $name, array $active_platforms, array $extra_keywords, array $longtail, string $tags_text): string {
        $word_count = str_word_count(wp_strip_all_tags($content));
        if ($word_count >= 600) {
            return $content;
        }

        $focus1 = self::cleanup_visible_text($extra_keywords[0] ?? ($name . ' live chat'), $name, false);
        $focus2 = self::cleanup_visible_text($extra_keywords[1] ?? ($name . ' webcam'), $name, false);
        $platform_text = self::format_platform_list($active_platforms, $active_platforms[0] ?? self::NEUTRAL_PLATFORM_FALLBACK);
        $longtail_hint = self::cleanup_visible_text($longtail[0] ?? ($name . ' schedule'), $name, false);

        $paragraphs = [
            'Fans returning to ' . $name . ' usually do so because the page makes it easy to compare platforms, understand the live-chat style, and jump directly into the right room without unnecessary friction.',
            $name . ' works well for visitors interested in ' . $focus1 . ' because the profile combines practical context, trusted links, and a cleaner overview of what to expect before joining a live room.',
            'Another reason this page is useful is that it covers ' . $focus2 . ' in a more complete way, helping visitors move from search intent to a real destination instead of bouncing between incomplete profile fragments.',
            'Platform availability matters too. Whether you prefer ' . $platform_text . ', the goal is to help you compare room quality, convenience, and profile visibility before choosing where to watch ' . $name . ' live.',
            'Searchers often arrive with very specific needs such as ' . $longtail_hint . '. By addressing those questions directly, the page becomes more useful for both first-time visitors and repeat fans.',
            $name . ' also stands out because the content stays focused on real user intent: better profile discovery, easier room comparison, and clearer guidance around safety, privacy, and navigation.',
            'The mix of ' . $tags_text . ' cues on this page gives readers more context about the performer style while keeping the copy readable and relevant for general live-webcam search intent.',
        ];

        foreach ($paragraphs as $paragraph) {
            if (str_word_count(wp_strip_all_tags($content)) >= 620) {
                break;
            }
            $content .= "\n\n<p>" . esc_html($paragraph) . '</p>';
        }

        return $content;
    }

    private static function balance_focus_density(string $content, string $focus_keyword, array $active_platforms, array $extra_keywords): string {
        $focus_keyword = trim($focus_keyword);
        if ($focus_keyword === '') {
            return $content;
        }

        $density = self::keyword_density_percent($content, $focus_keyword);
        $platform_text = self::format_platform_list($active_platforms, $active_platforms[0] ?? self::NEUTRAL_PLATFORM_FALLBACK);
        $extra_text = self::cleanup_visible_text($extra_keywords[0] ?? ($focus_keyword . ' live chat'), $focus_keyword, false);

        while ($density < 1.0) {
            $content .= "\n\n<p>" . esc_html($focus_keyword) . ' is the core topic of this page, with extra attention on ' . esc_html($extra_text) . ' and practical tips for viewers who want to watch on ' . esc_html($platform_text) . '.</p>';
            $density = self::keyword_density_percent($content, $focus_keyword);
            if ($density >= 1.0 || str_word_count(wp_strip_all_tags($content)) > 900) {
                break;
            }
        }

        if ($density > 3.2) {
            $seen = 0;
            $replacement = 'the profile';
            $content = preg_replace_callback(
                '/' . preg_quote($focus_keyword, '/') . '/iu',
                static function (array $matches) use (&$seen, $replacement): string {
                    $seen++;
                    return $seen > 8 ? $replacement : $matches[0];
                },
                $content
            ) ?: $content;
        }

        return $content;
    }

    private static function similarity_score(string $content, int $post_id, int $limit = 10): float {
        $posts = get_posts([
            'post_type'      => 'model',
            'posts_per_page' => $limit,
            'post_status'    => 'publish',
            'post__not_in'   => [$post_id],
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ]);
        if (empty($posts)) {
            return 0.0;
        }

        $needle = self::tokenize($content);
        if (empty($needle)) {
            return 0.0;
        }

        $max = 0.0;
        foreach ($posts as $compare_id) {
            $haystack = get_post_field('post_content', (int)$compare_id);
            $hay = self::tokenize((string)$haystack);
            if (empty($hay)) {
                continue;
            }
            $overlap = array_intersect($needle, $hay);
            $score = (count($overlap) / max(1, count($needle))) * 100;
            if ($score > $max) {
                $max = $score;
            }
        }

        return round($max, 2);
    }

    /** @return string[] */
    private static function tokenize(string $text): array {
        $text = strtolower(strip_tags($text));
        $text = preg_replace('/[^a-z0-9]+/i', ' ', $text);
        $parts = preg_split('/\s+/', (string)$text);
        $parts = array_filter($parts, static fn($p) => strlen((string)$p) > 3);
        return array_values(array_unique($parts));
    }

    private static function keyword_density_percent(string $html, string $keyword): float {
        $keyword = trim($keyword);
        $text = trim((string)wp_strip_all_tags($html));
        if ($keyword === '' || $text === '') {
            return 0.0;
        }

        preg_match_all('/\b[\p{L}\p{N}\']+\b/u', $text, $words);
        $word_count = isset($words[0]) && is_array($words[0]) ? count($words[0]) : 0;
        if ($word_count === 0) {
            return 0.0;
        }

        $pattern = '/\b' . preg_quote(mb_strtolower($keyword, 'UTF-8'), '/') . '\b/u';
        preg_match_all($pattern, mb_strtolower($text, 'UTF-8'), $hits);
        $occurrences = isset($hits[0]) && is_array($hits[0]) ? count($hits[0]) : 0;

        return ($occurrences / $word_count) * 100;
    }

    private static function split_long_paragraphs(string $html, int $max_chars = 320): string {
        return preg_replace_callback('/<p>(.*?)<\/p>/s', static function (array $matches) use ($max_chars): string {
            $text = trim(wp_strip_all_tags($matches[1]));
            if ($text === '' || mb_strlen($text) <= $max_chars) {
                return '<p>' . esc_html($text) . '</p>';
            }

            $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
            if (empty($sentences)) {
                return '<p>' . esc_html($text) . '</p>';
            }

            $chunks = [];
            $current = '';
            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);
                if ($sentence === '') {
                    continue;
                }
                $candidate = $current === '' ? $sentence : $current . ' ' . $sentence;
                if ($current !== '' && mb_strlen($candidate) > $max_chars) {
                    $chunks[] = $current;
                    $current = $sentence;
                    continue;
                }
                $current = $candidate;
            }
            if ($current !== '') {
                $chunks[] = $current;
            }

            $out = '';
            foreach ($chunks as $chunk) {
                $out .= '<p>' . esc_html($chunk) . '</p>';
            }
            return $out;
        }, $html) ?: $html;
    }

    /** @return string[] */
    private static function discover_model_tags(\WP_Post $post): array {
        $slugs = [];
        foreach (['post_tag', 'category'] as $taxonomy) {
            $terms = get_the_terms($post, $taxonomy);
            if (!is_array($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                if ($term instanceof \WP_Term) {
                    $slugs[] = (string)$term->slug;
                }
            }
        }

        return array_values(array_unique(array_filter($slugs)));
    }

    private static function format_platform_list(array $platforms, string $fallback): string {
        $platforms = array_values(array_filter(array_map('trim', $platforms), 'strlen'));
        if (empty($platforms)) {
            return $fallback;
        }
        if (count($platforms) === 1) {
            return $platforms[0];
        }
        $last = array_pop($platforms);
        return implode(', ', $platforms) . ' and ' . $last;
    }

    /**
     * Generate tag-aware feature descriptions instead of a static repeated list.
     */
    private static function render_varied_features(string $name, array $tags, string $platform, string $seed): string {
        $tag_phrases = array_map(fn($t) => str_replace('-', ' ', (string)$t), array_slice($tags, 0, 6));
        $tag_phrases = array_filter($tag_phrases, fn($t) => $t !== '' && strlen($t) >= 3);

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

        foreach (array_slice($tag_phrases, 0, 2) as $tag) {
            $pool[] = '<li><strong>' . esc_html(ucfirst($tag)) . ' content:</strong> Fans of ' . esc_html($tag) . ' will find ' . esc_html($name) . '\'s shows match that style.</li>';
        }

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

    private static function build_clean_platform_section_heading(string $name, string $platform_label): string {
        if ($platform_label === '' || $platform_label === self::NEUTRAL_PLATFORM_FALLBACK) {
            return $name . ' official live profile';
        }

        return $name . ' on ' . $platform_label;
    }

    private static function build_focus_heading(string $phrase, string $name, int $level, string $fallback): string {
        $clean = self::cleanup_visible_text($phrase, $name, true);
        $normalized = trim(mb_strtolower($clean, 'UTF-8'));

        if ($normalized === '' || !self::is_readable_heading_phrase($clean, $name)) {
            return $fallback;
        }

        if (preg_match('/^(why fans who like|watch\s+.+\s+with\s+|.+\s+and the live chat experience|this model\b)/iu', $clean)) {
            return $fallback;
        }

        $heading = $clean;
        if (!preg_match('/\b' . preg_quote($name, '/') . '\b/iu', $heading)) {
            if ($level <= 2) {
                return 'Why viewers choose ' . $name . ' for ' . $heading;
            }
            return $name . ': ' . $heading;
        }

        return $heading;
    }

    private static function build_longtail_heading(string $phrase, string $name): string {
        $clean = self::cleanup_visible_text($phrase, $name, true);
        if (self::is_readable_heading_phrase($clean, $name)) {
            return $clean;
        }

        if (preg_match('/schedule|times|when/iu', $phrase)) {
            return $name . ' schedule and availability';
        }
        if (preg_match('/profile|links/iu', $phrase)) {
            return $name . ' profile links and access';
        }
        if (preg_match('/chat/iu', $phrase)) {
            return $name . ' live chat experience';
        }
        if (preg_match('/stream|live|show/iu', $phrase)) {
            return 'How to watch ' . $name . ' live';
        }

        return 'More about ' . $name;
    }

    private static function cleanup_model_content(string $content, string $name): string {
        $content = str_replace(['this model', 'This model'], ['this profile', 'This profile'], $content);
        $content = str_replace(['live webcam', 'Live webcam'], [self::NEUTRAL_PLATFORM_FALLBACK, ucfirst(self::NEUTRAL_PLATFORM_FALLBACK)], $content);

        $content = preg_replace('/&lt;\/?h[1-6]&gt;/i', '', $content) ?: $content;
        $content = preg_replace('/<h([2-6])>\s*(Why fans who like|Watch\s+.+\s+with\s+|.+\s+and the live chat experience)(.*?)<\/h\1>/iu', '<h$1>' . $name . '</h$1>', $content) ?: $content;
        $content = preg_replace('/\b(' . preg_quote($name, '/') . '\s+)(\1)+/iu', '$1', $content) ?: $content;
        $content = preg_replace('/\b(official live profile)(\s+official live profile)+\b/iu', '$1', $content) ?: $content;

        return $content;
    }

    private static function cleanup_visible_text(string $text, string $name, bool $for_heading): string {
        $text = wp_strip_all_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\bthis model\b/iu', $name !== '' ? $name : 'this profile', $text) ?: $text;
        $text = preg_replace('/\blive webcam\b/iu', self::NEUTRAL_PLATFORM_FALLBACK, $text) ?: $text;
        $text = preg_replace('/\s+/', ' ', trim($text)) ?: trim($text);

        if ($for_heading) {
            $text = preg_replace('/^(why fans who like|watch)\s+/iu', '', $text) ?: $text;
            $text = preg_replace('/\s+and the live chat experience$/iu', '', $text) ?: $text;
            $text = preg_replace('/\s+with\s+' . preg_quote($name, '/') . '$/iu', '', $text) ?: $text;
            $text = preg_replace('/\s+[—-]\s+what to expect$/iu', '', $text) ?: $text;
            $text = trim($text, " \t\n\r\0\x0B:;-—");
        }

        return $text;
    }

    private static function is_readable_heading_phrase(string $phrase, string $name): bool {
        $phrase = trim($phrase);
        if ($phrase === '') {
            return false;
        }

        if (mb_strlen($phrase, 'UTF-8') < 8 || mb_strlen($phrase, 'UTF-8') > 80) {
            return false;
        }

        if (substr_count($phrase, '...') > 0) {
            return false;
        }

        if (preg_match('/[<>]|&lt;|&gt;/i', $phrase)) {
            return false;
        }

        if (preg_match('/^(why fans who like|watch\b|this model\b)/iu', $phrase)) {
            return false;
        }

        if (preg_match('/\band the live chat experience$/iu', $phrase)) {
            return false;
        }

        $tokens = preg_split('/\s+/', $phrase);
        $tokens = is_array($tokens) ? array_values(array_filter($tokens, 'strlen')) : [];
        if (count($tokens) < 2 || count($tokens) > 10) {
            return false;
        }

        $lower = mb_strtolower($phrase, 'UTF-8');
        $name_lower = mb_strtolower($name, 'UTF-8');
        if ($name_lower !== '' && $lower === $name_lower) {
            return false;
        }

        return true;
    }
}
