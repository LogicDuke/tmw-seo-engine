<?php
namespace TMWSEO\Engine\Content;

use TMWSEO\Engine\Templates\TemplateEngine;
use TMWSEO\Engine\Platform\AffiliateLinkBuilder;
use TMWSEO\Engine\Platform\PlatformProfiles;
use TMWSEO\Engine\Platform\PlatformRegistry;
use TMWSEO\Engine\Keywords\ModelKeywordPack;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Services\TitleFixer;

if (!defined('ABSPATH')) { exit; }

/**
 * Template-mode content generator.
 *
 * Produces SEO-friendly content without OpenAI, using deterministic templates
 * (ported from the legacy Autopilot) + the Engine keyword pack.
 */
class TemplateContent {

    private const NEUTRAL_PLATFORM_FALLBACK = 'official profile links';
    // Rank Math validated (manual live sidebar testing): confirmed as BOTH
    // positive/sentiment + power words for model title auto-generation.
    private const MODEL_TITLE_POWER_WORDS_FALLBACK = ['Best', 'Amazing', 'Proven', 'Safe', 'Secure', 'Powerful', 'Trustworthy', 'Exclusive', 'Popular', 'Remarkable'];
    // Reserve list (power-only in manual Rank Math testing). Keep for manual use only.
    private const MODEL_TITLE_RESERVE_POWER_ONLY_FALLBACK = ['Secret', 'Expert', 'Official', 'Latest', 'New'];
    private const MODEL_TITLE_DENYLIST_FALLBACK = ['Bloody', 'Corpse', 'Murder', 'Bomb', 'Nazi', 'Jail', 'Toxic', 'Doom', 'Deadly', 'Hoax', 'Scam', 'Trap', 'Victim', 'Brutal'];

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
        $keyword_coverage_html = self::render_rankmath_keyword_coverage(array_slice($extra, 0, 6), $name);
        $support_payload = self::build_model_renderer_support_payload($post, array_merge($pack, [
            'name' => $name,
            'cta_links' => $cta_links,
            'tags' => $tags,
            'active_platforms' => $active_platforms,
            'longtail' => $longtail,
            'comparison_copy' => $comparison_copy,
        ]));

        $renderer_payload = array_merge($support_payload, [
            'focus_keyword' => $name,
            'intro_paragraphs' => [
                $intro,
                'Fans searching for live shows usually want a fast, safe way to join a real-time room. This guide highlights verified profile links, what to expect, and practical ways to enjoy a quality live chat experience.',
            ],
            'watch_section_paragraphs' => [
                $primary_platform_label === self::NEUTRAL_PLATFORM_FALLBACK
                    ? 'Use the official profile links below to access current rooms and choose where to watch live safely.'
                    : 'Use the verified links below to watch on ' . $primary_platform_label . ' and compare available rooms safely.',
            ],
            'about_section_paragraphs' => [$bio],
            'fans_like_section_paragraphs' => self::build_fans_like_paragraphs($context, $name),
            'features_section_paragraphs' => [
                'Viewers often compare platform quality, stream stability, and room access speed before joining. The details below make those checks quicker and clearer.',
            ],
            'features_section_html' => self::join_html_blocks([
                self::render_varied_features($name, $tags, $primary_platform_label, $seed),
                $keyword_coverage_html,
            ]),
            'comparison_section_paragraphs' => [$comparison_copy],
            'faq_items' => $faqs_tpl,
        ]);

        $content = ModelPageRenderer::render($name, $renderer_payload);
        $content = self::split_long_paragraphs($content);
        $content = self::balance_focus_density($content, $name, $active_platforms, $extra);
        $content = self::pad_model_content($content, $name, $active_platforms, $extra, $longtail, $tags_text);

        if (self::similarity_score($content, (int)$post->ID) > 70.0) {
            $content .= "\n\n" . '<h2>Why ' . esc_html($name) . ' stands out</h2><p>This profile highlights ' . esc_html($name) . ' with current platform availability, related search intent, and a curated mix of ' . esc_html($tags_text) . ' cues tailored to this page.</p>';
            $content = self::split_long_paragraphs($content);
        }

        $content = self::pad_model_content($content, $name, $active_platforms, $extra, $longtail, $tags_text);
        $content = self::cleanup_model_content($content, $name);

        $seo_title = self::build_default_model_seo_title($name, $primary_platform_label, (int) $post->ID);

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

    /**
     * @param array<string,mixed> $pack
     * @return array<string,mixed>
     */
    public static function build_model_renderer_support_payload(\WP_Post $post, array $pack): array {
        $name = trim((string)($pack['name'] ?? $pack['primary'] ?? ''));
        if ($name === '') {
            $name = trim((string)$post->post_title);
        }
        if ($name === '') {
            $name = 'Live Cam Model';
        }

        $source_links = $pack['cta_links'] ?? PlatformProfiles::get_links($post->ID);
        $cta_links = self::build_platform_cta_links((int)$post->ID, is_array($source_links) ? $source_links : []);

        $active_platforms = $pack['active_platforms'] ?? [];
        if (!is_array($active_platforms) || empty($active_platforms)) {
            $active_platforms = [];
            foreach ($cta_links as $row) {
                $label = trim((string)($row['label'] ?? ''));
                if ($label !== '') {
                    $active_platforms[] = $label;
                }
            }
        }
        $active_platforms = array_values(array_unique(array_filter(array_map('strval', $active_platforms), 'strlen')));

        $tags = $pack['tags'] ?? ($pack['sources']['tags'] ?? []);
        if (!is_array($tags) || empty($tags)) {
            $tags = self::discover_model_tags($post);
        }
        $tags = array_values(array_filter(array_map('strval', $tags), 'strlen'));

        $longtail = $pack['longtail'] ?? ($pack['longtail_keywords'] ?? []);
        $longtail = is_array($longtail) ? $longtail : [];
        $comparison_copy = trim((string)($pack['comparison_copy'] ?? ''));

        return [
            'watch_section_html' => self::join_html_blocks([
                self::render_primary_watch_cta($cta_links, $name),
                self::render_watch_cta_section($cta_links, $name),
            ]),
            'comparison_section_html' => self::build_platform_comparison($post, $name, $cta_links, $comparison_copy),
            'related_models_html' => self::render_related_models($post, $name, $tags, $active_platforms),
            'explore_more_html' => self::render_internal_links($post),
            'external_info_html' => self::join_html_blocks([
                self::render_detectable_outbound_platform_link($cta_links, $name),
                self::render_contextual_external_link(),
            ]),
            'questions_section_paragraphs' => self::build_longtail_paragraphs($longtail, $name),
        ];
    }

    /** @return string[] */
    private static function build_fans_like_paragraphs(array $context, string $name): array {
        $focuses = [
            trim((string)($context['extra_focus_1'] ?? '')),
            trim((string)($context['extra_focus_2'] ?? '')),
            trim((string)($context['extra_focus_3'] ?? '')),
            trim((string)($context['extra_focus_4'] ?? '')),
        ];

        $paragraphs = [];
        foreach ($focuses as $focus) {
            if ($focus === '') {
                continue;
            }
            $clean = self::cleanup_visible_text($focus, $name, false);
            if ($clean === '') {
                continue;
            }
            $paragraphs[] = 'Viewers interested in ' . $clean . ' usually want a room that feels interactive, respectful, and easy to join. The performer matches that intent with a more personal live-chat experience.';
            if (count($paragraphs) >= 3) {
                break;
            }
        }

        if (empty($paragraphs)) {
            $paragraphs[] = 'Returning fans often mention a consistent live-chat style, clear room access, and practical guidance for finding active sessions quickly.';
        }

        return $paragraphs;
    }

    private static function render_rankmath_keyword_coverage(array $keywords, string $name): string {
        $keywords = array_values(array_filter(array_map('trim', $keywords), 'strlen'));
        if (empty($keywords)) {
            return '';
        }

        $out  = '<p>People looking for ' . esc_html($name) . ' often search for these related phrases before choosing a room or platform:</p><ul>';
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
            return '<p>' . esc_html($comparison_copy !== '' ? $comparison_copy : $fallback) . '</p>';
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
                . '<td><a href="' . esc_url($url) . '" target="_blank" rel="' . esc_attr(!empty($link['is_primary']) ? 'sponsored noopener' : 'sponsored nofollow noopener') . '">Watch live</a></td>'
                . '</tr>';
        }

        $table = '';
        if ($rows !== '') {
            $table = '<table><thead><tr><th>Platform</th><th>Profile</th><th>Link</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        }

        return $table;
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

        return '<p>' . esc_html($intro) . '</p><ul>' . $items . '</ul>';
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

            return '<p><a href="' . esc_url($go_url) . '" target="_blank" rel="sponsored noopener">' . esc_html('Watch ' . $name . ' on ' . $label) . '</a></p>';
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

            $items[] = '<li><a href="' . esc_url($url) . '" target="_blank" rel="' . esc_attr(!empty($link['is_primary']) ? 'sponsored noopener' : 'sponsored nofollow noopener') . '">' . esc_html('Watch ' . $name . ' on ' . $platform) . '</a></li>';

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

        return '<h3>What is a webcam model?</h3><p><a href="https://en.wikipedia.org/wiki/Webcam_model" target="_blank" rel="noopener">Read this informational overview on Wikipedia</a>.</p>';
    }

    /**
     * Ensure at least one real outbound link is visible for SEO tools
     * while keeping affiliate /go/ CTA links intact.
     *
     * @param array<int,array{platform:string,label:string,go_url:string,is_primary:bool,username:string}> $links
     */
    private static function render_detectable_outbound_platform_link(array $links, string $name): string {
        foreach ($links as $link) {
            $platform = sanitize_key((string) ($link['platform'] ?? ''));
            $username = trim((string) ($link['username'] ?? ''));
            $label = trim((string) ($link['label'] ?? ''));
            if ($platform === '' || $username === '' || $label === '') {
                continue;
            }

            $profile_url = AffiliateLinkBuilder::build_profile_url($platform, $username);
            if ($profile_url === '') {
                continue;
            }

            return '<p>Need direct platform details first? Visit <a href="' . esc_url($profile_url) . '" target="_blank" rel="noopener">' . esc_html($label . ' profile for ' . $name) . '</a> before choosing a watch link.</p>';
        }

        return '<p>Need direct platform details first? Visit <a href="https://en.wikipedia.org/wiki/Webcam_model" target="_blank" rel="noopener">this webcam-model overview</a> for neutral background information.</p>';
    }

    public static function build_default_model_seo_title(string $name, string $primary_platform_label = '', int $post_id = 0): string {
        $name = trim($name);
        if ($name === '') {
            $name = 'Live Cam Model';
        }

        $year = gmdate('Y');
        $words = self::model_title_allow_words();
        $denied_tokens = self::model_title_deny_tokens();
        $patterns = [
            '{name} — {power} Live Cam Guide {year}',
            '{name} — {power} Live Chat Guide {year}',
            '{name} — {power} Webcam Guide {year}',
            '{name} — {power} Live Cam Profile {year}',
        ];

        $seed = strtolower($name) . '|' . $post_id;
        $word = $words[self::stable_pick_index($seed . '|word', count($words))];
        $pattern = $patterns[self::stable_pick_index($seed . '|pattern', count($patterns))];

        $title = strtr($pattern, [
            '{name}' => $name,
            '{power}' => $word,
            '{year}' => $year,
        ]);

        if (self::contains_denylisted_token($title, $denied_tokens)) {
            $title = $name . ' — Safe Live Cam Guide ' . $year;
        }

        return TitleFixer::shorten($title, 65);
    }

    /** @return string[] */
    public static function model_title_allow_words(): array {
        $fallback = self::MODEL_TITLE_POWER_WORDS_FALLBACK;
        $file = TMWSEO_ENGINE_PATH . 'data/snippet-power-words.php';
        if (!is_readable($file)) {
            return $fallback;
        }

        $raw = include $file;
        $list = is_array($raw['model_title_allowlist'] ?? null) ? $raw['model_title_allowlist'] : [];
        $clean = [];
        foreach ($list as $word) {
            $token = trim((string) $word);
            if ($token === '') {
                continue;
            }
            if (!preg_match('/^[a-z][a-z -]*$/i', $token)) {
                continue;
            }
            $clean[] = ucfirst(strtolower($token));
        }

        $clean = array_values(array_unique($clean));
        if (empty($clean)) {
            return $fallback;
        }

        return $clean;
    }

    /** @return string[] */
    public static function model_title_reserve_power_only_words(): array {
        $fallback = self::MODEL_TITLE_RESERVE_POWER_ONLY_FALLBACK;
        $file = TMWSEO_ENGINE_PATH . 'data/snippet-power-words.php';
        if (!is_readable($file)) {
            return $fallback;
        }

        $raw = include $file;
        $list = is_array($raw['model_title_reserve_power_only'] ?? null) ? $raw['model_title_reserve_power_only'] : [];
        $clean = [];
        foreach ($list as $word) {
            $token = trim((string) $word);
            if ($token === '') {
                continue;
            }
            if (!preg_match('/^[a-z][a-z -]*$/i', $token)) {
                continue;
            }
            $clean[] = ucfirst(strtolower($token));
        }

        $clean = array_values(array_unique($clean));
        if (empty($clean)) {
            return $fallback;
        }

        return $clean;
    }

    /** @return string[] */
    public static function model_title_deny_tokens(): array {
        $tokens = self::MODEL_TITLE_DENYLIST_FALLBACK;

        $file = TMWSEO_ENGINE_PATH . 'data/snippet-power-words.php';
        if (is_readable($file)) {
            $raw = include $file;
            if (is_array($raw['model_title_denylist'] ?? null)) {
                foreach ($raw['model_title_denylist'] as $word) {
                    $token = trim((string) $word);
                    if ($token !== '') {
                        $tokens[] = $token;
                    }
                }
            }
        }

        $negative_filters = (string) Settings::get('keyword_negative_filters', '');
        foreach (preg_split('/\R+/', $negative_filters) ?: [] as $line) {
            $token = trim((string) $line);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        return array_values(array_unique(array_map(static fn(string $item): string => strtolower($item), $tokens)));
    }

    public static function is_weak_auto_model_title(string $title, string $name = ''): bool {
        $clean = trim(wp_strip_all_tags($title));
        if ($clean === '') {
            return true;
        }

        $normalized = strtolower($clean);
        if ($name !== '') {
            $normalized = preg_replace('/\b' . preg_quote(strtolower(trim($name)), '/') . '\b/u', '', $normalized) ?: $normalized;
        }
        $normalized = preg_replace('/\b(19|20)\d{2}\b/', '', $normalized) ?: $normalized;
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?: $normalized);

        $legacy_patterns = [
            '— live cam profile',
            '- live cam profile',
            '— verified live cam profile',
            '- verified live cam profile',
            '— live cam model profile & schedule',
            '- live cam model profile & schedule',
        ];

        return in_array($normalized, $legacy_patterns, true);
    }

    private static function stable_pick_index(string $seed, int $count): int {
        if ($count <= 1) {
            return 0;
        }

        return (int) (sprintf('%u', crc32($seed)) % $count);
    }

    private static function contains_denylisted_token(string $title, array $deny_tokens): bool {
        $haystack = strtolower($title);
        foreach ($deny_tokens as $token) {
            $needle = trim(strtolower((string) $token));
            if ($needle === '') {
                continue;
            }
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    private static function build_longtail_paragraphs(array $longtail_keywords, string $name): array {
        $items = array_slice(array_values(array_unique(array_filter(array_map('trim', $longtail_keywords), 'strlen'))), 0, 4);
        $paragraphs = [];
        foreach ($items as $kw) {
            $body_kw = self::cleanup_visible_text($kw, $name, false);
            if ($body_kw === '') {
                continue;
            }
            $paragraphs[] = 'People looking up ' . $body_kw . ' usually want current room links, a quick sense of show style, and confidence they are choosing the right place to watch.';
        }

        return $paragraphs;
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
            'Fans returning here usually do so because the page makes it easy to compare platforms, understand the live-chat style, and jump directly into the right room without unnecessary friction.',
            'This guide works well for visitors interested in ' . $focus1 . ' because it combines practical context, trusted links, and a cleaner overview of what to expect before joining a live room.',
            'Another reason this page is useful is that it covers ' . $focus2 . ' in a more complete way, helping visitors move from search intent to a real destination instead of bouncing between incomplete profile fragments.',
            'Platform availability matters too. Whether you prefer ' . $platform_text . ', the goal is to help you compare room quality, convenience, and profile visibility before choosing where to watch live.',
            'Searchers often arrive with very specific needs such as ' . $longtail_hint . '. By addressing those questions directly, the page becomes more useful for both first-time visitors and repeat fans.',
            'The coverage also stands out because it stays focused on real user intent: better profile discovery, easier room comparison, and clearer guidance around safety, privacy, and navigation.',
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
            $content .= "\n\n<p>" . esc_html($focus_keyword) . ' remains the core topic of this page, with extra attention on ' . esc_html($extra_text) . ' and practical tips for viewers who want to watch on ' . esc_html($platform_text) . '.</p>';
            $density = self::keyword_density_percent($content, $focus_keyword);
            if ($density >= 1.0 || str_word_count(wp_strip_all_tags($content)) > 900) {
                break;
            }
        }

        if ($density > 2.2) {
            $seen = 0;
            $fallbacks = ['she', 'the performer', 'her room', 'her profile links'];
            $content = preg_replace_callback(
                '/' . preg_quote($focus_keyword, '/') . '/iu',
                static function (array $matches) use (&$seen, $fallbacks): string {
                    $seen++;
                    if ($seen <= 6) {
                        return $matches[0];
                    }
                    return $fallbacks[($seen - 7) % count($fallbacks)];
                },
                $content
            ) ?: $content;
        }

        return $content;
    }

    /** @param array<int,string> $blocks */
    private static function join_html_blocks(array $blocks): string {
        $parts = [];
        foreach ($blocks as $block) {
            $clean = trim((string)$block);
            if ($clean !== '') {
                $parts[] = $clean;
            }
        }

        return implode("\n", $parts);
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
            return $name . ' official profile links';
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
        $content = str_replace(['this model', 'This model'], [$name, $name], $content);
        $content = str_replace(['live webcam', 'Live webcam'], [self::NEUTRAL_PLATFORM_FALLBACK, ucfirst(self::NEUTRAL_PLATFORM_FALLBACK)], $content);

        $content = preg_replace('/&lt;\/?h[1-6]&gt;/i', '', $content) ?: $content;
        $content = preg_replace('/<h([2-6])>\s*(Why fans who like|Watch\s+.+\s+with\s+|.+\s+and the live chat experience)(.*?)<\/h\1>/iu', '<h$1>' . $name . '</h$1>', $content) ?: $content;
        $content = preg_replace('/\b(' . preg_quote($name, '/') . '\s+)(\1)+/iu', '$1', $content) ?: $content;
        $content = preg_replace('/\b(official (?:live )?profile links)(\s+official (?:live )?profile links)+\b/iu', '$1', $content) ?: $content;
        $content = preg_replace('/\bofficial live profile\b/iu', self::stable_fallback_variant($name . '|ofp'), $content) ?: $content;
        $content = preg_replace('/\bofficial profile links\b/iu', self::stable_fallback_variant($name . '|opl'), $content) ?: $content;
        $content = preg_replace('/\bVisitors searching for\b/iu', 'People looking up', $content) ?: $content;

        return $content;
    }

    private static function stable_fallback_variant(string $seed): string {
        $variants = [
            'official profile links',
            'verified profile links',
            'trusted room links',
            'official room access',
        ];
        return $variants[self::stable_pick_index($seed, count($variants))];
    }

    private static function cleanup_visible_text(string $text, string $name, bool $for_heading): string {
        $text = wp_strip_all_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\bthis model\b/iu', $name !== '' ? $name : 'this profile', $text) ?: $text;
        $text = preg_replace('/\bthe profile\b/iu', $name . ' profile', $text) ?: $text;
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
