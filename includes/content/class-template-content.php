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

        PlatformProfiles::sync_to_table((int) $post->ID);
        $platform_links = PlatformProfiles::get_links($post->ID);
        $cta_links = self::build_platform_cta_links($post->ID, is_array($platform_links) ? $platform_links : []);
        \TMWSEO\Engine\Logs::info('content', '[TMW-FIX] Synced platform rows before model CTA generation', [
            'post_id' => (int) $post->ID,
            'platform_rows_count' => is_array($platform_links) ? count($platform_links) : 0,
            'cta_links_count' => count($cta_links),
        ]);
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
            // live_brand must always be readable prose — never "official profile links".
            'live_brand' => $primary_platform_label !== self::NEUTRAL_PLATFORM_FALLBACK
                ? $primary_platform_label
                : 'live cam',
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

        $faqs_raw = TemplateEngine::pick_faq($faq_slug, $seed, 5);
        // Render {name}/{live_brand}/{tags}/etc. inside each Q&A now — the page
        // renderer's clean_text() does not call TemplateEngine::render(), so raw
        // placeholders like {name} would bleed into final output without this step.
        $faqs_tpl = [];
        foreach ($faqs_raw as $faq) {
            if (!is_array($faq)) continue;
            $q = trim(TemplateEngine::render((string)($faq['q'] ?? ''), $context));
            $a = trim(TemplateEngine::render((string)($faq['a'] ?? ''), $context));
            $q = self::cleanup_visible_text($q, $name, true);
            $a = self::cleanup_visible_text($a, $name, false);
            if ($q !== '' && $a !== '') {
                $faqs_tpl[] = ['q' => $q, 'a' => $a];
            }
        }
        $second_intro_pool = [
            'Most visitors arrive here looking for a direct route to a live session — a verified link, a sense of what the show is like, and enough context to make the room feel familiar before joining.',
            'This guide covers the practical side: which platforms are active, what to expect from the live-chat style, and how to find the right room quickly without landing on unverified third-party pages.',
            'Finding a performer\'s actual room can take longer than it should when search results mix verified profiles with aggregator pages. This page links directly to the official sources and explains what to expect once inside.',
            'Fans searching for live shows usually want a fast, safe way to join a real-time room. This guide highlights verified profile links, what to expect, and practical ways to enjoy a quality live chat experience.',
            'The combination of verified links, show context, and platform comparison on this page is designed to shorten the gap between "searching" and "watching" — without the usual detour through unrelated results.',
        ];
        $second_intro = $second_intro_pool[self::stable_pick_index($seed . '|intro2', count($second_intro_pool))];

        $watch_para_pool = [
            'Use the verified links below to access current rooms and choose where to watch live safely.',
            'The links below connect directly to verified rooms — no third-party redirects, no guesswork about which page is official.',
            'Select a platform from the links below to open the live room directly. Arriving a few minutes early is the best way to catch the session from the start.',
        ];
        if ($primary_platform_label !== self::NEUTRAL_PLATFORM_FALLBACK) {
            $watch_para_pool[] = 'Use the verified links below to watch on ' . $primary_platform_label . ' and compare any additional platforms available for this performer.';
        }
        $watch_para = $watch_para_pool[self::stable_pick_index($seed . '|watch', count($watch_para_pool))];
        $keyword_coverage_html = self::render_rankmath_keyword_coverage(array_slice($extra, 0, 6), $name);

        $support_payload = self::build_model_renderer_support_payload($post, array_merge($pack, [
            'name' => $name,
            'cta_links' => $cta_links,
            'tags' => $tags,
            'active_platforms' => $active_platforms,
            'longtail' => $longtail,
            'comparison_copy' => $comparison_copy,
        ]));

        $platform_ref = $primary_platform_label !== self::NEUTRAL_PLATFORM_FALLBACK
            ? $primary_platform_label
            : 'the platform';

        $renderer_payload = array_merge($support_payload, [
            'focus_keyword' => $name,
            'intro_paragraphs' => [
                $intro,
                $second_intro,
            ],
            'watch_section_paragraphs' => [
                $watch_para,
            ],
            'about_section_paragraphs' => [$bio],
            'fans_like_section_paragraphs' => self::build_fans_like_paragraphs($context, $name),
            'features_section_paragraphs' => [
                'Stream quality, room access speed, and interactive features all factor into how enjoyable a session turns out to be. The breakdown below covers the main things worth checking before joining ' . $name . ' on ' . $platform_ref . '.',
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
            'external_info_html' => self::render_visible_affiliate_links($cta_links, $name),
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
        $platform = trim((string)($context['platform_a'] ?? ''));
        if ($platform === '' || $platform === self::NEUTRAL_PLATFORM_FALLBACK) {
            $platform = 'the platform';
        }
        $tags = trim((string)($context['tags'] ?? ''));
        $seed = $name . '|fans';

        // ── Opener pool: varied sentence starters, no name repetition ──────
        $openers = [
            'Returning viewers tend to describe the same qualities when explaining why they come back: a consistent live-chat style, genuine responsiveness to the room, and pacing that does not feel rushed or mechanical.',
            'Community members who have watched across multiple platforms tend to settle here because the live format feels less scripted and more responsive than most broadcast-style shows.',
            'Long-time followers point to a sense of session continuity — each broadcast has a recognisable rhythm, which makes the experience easier to settle into than unpredictable free-form rooms.',
            'What draws regular viewers back is harder to summarise than a single feature: it is the combination of attentiveness, pacing, and a consistent on-screen presence that makes repeat visits feel worthwhile.',
            'Fans who browse multiple performers tend to describe the draw here as something structural — the show has a shape, a warm-up, and a conversational arc that more casual broadcasts tend to skip.',
            'The appeal extends beyond any single session. Viewers who engage with chat consistently report that the interaction feels more personal than standard viewer-performer dynamics on most platforms.',
        ];
        $opener_idx = self::stable_pick_index($seed . '|opener', count($openers));
        $paragraphs = [$openers[$opener_idx]];

        // ── Second paragraph: weave in first clean focus naturally ──────────
        $focus1 = '';
        foreach ($focuses as $f) {
            $clean = self::cleanup_visible_text($f, $name, false);
            if ($clean !== '' && mb_strlen($clean) > 4) {
                $focus1 = $clean;
                break;
            }
        }

        if ($focus1 !== '') {
            $p1_pool = [
                'Visitors arriving from searches for ' . $focus1 . ' find that the live-chat format here offers more personalisation than auto-play video alternatives. The show adjusts based on what the room is asking for, which keeps sessions from feeling like a one-way broadcast.',
                'People who arrive via searches for ' . $focus1 . ' often stay longer than planned because the interactive element turns passive watching into a genuine back-and-forth — which is exactly what most live-cam viewers are looking for.',
                'The connection between ' . $focus1 . ' and the performer\'s natural style shows up quickly in any session: the pacing is deliberate, the conversation moves in both directions, and the camera work stays steady rather than drifting.',
                'Searches for ' . $focus1 . ' land here because the page covers both the discovery side and the practical side — verified links, a breakdown of show style, and enough context to understand what to expect before committing to a session.',
            ];
            $paragraphs[] = $p1_pool[self::stable_pick_index($seed . '|p1', count($p1_pool))];
        }

        // ── Third paragraph: second focus or tags/platform angle ────────────
        $focus2 = '';
        $skipped = false;
        foreach ($focuses as $f) {
            $clean = self::cleanup_visible_text($f, $name, false);
            if ($clean !== '' && mb_strlen($clean) > 4) {
                if (!$skipped) { $skipped = true; continue; }
                $focus2 = $clean;
                break;
            }
        }
        $theme = $focus2 !== '' ? $focus2 : ($tags !== '' ? $tags : $name . ' live shows');

        $p2_pool = [
            'For viewers drawn in by ' . $theme . ', the show style translates naturally: topics are introduced through conversation rather than announced like agenda items, which keeps the atmosphere relaxed and easy to enter at any point in the session.',
            'Searches around ' . $theme . ' land on this page because it addresses both the platform context and the viewer experience — not just a room link, but a sense of what the session will actually feel like once inside.',
            'The ' . $theme . ' dimension of these sessions is handled with care: it informs the mood without dominating the room, which gives first-time viewers a comfortable entry point and gives returning viewers something to build on.',
            'Viewers interested in ' . $theme . ' find that the format here rewards participation more than most. The show responds to what the room brings rather than following a fixed script, which creates a different experience each time.',
        ];
        $paragraphs[] = $p2_pool[self::stable_pick_index($seed . '|p2', count($p2_pool))];

        // ── Fourth paragraph: platform-specific or community angle ──────────
        $p3_pool = [
            'On ' . $platform . ', the combination of HD streaming and responsive moderation keeps the technical side from interfering with what makes a live session worth attending in the first place.',
            'The interactive features on ' . $platform . ' — tip-triggered responses, two-way chat, and private session options — give viewers more ways to shape the experience than a standard video stream allows.',
            'Fans who have tried multiple platforms consistently describe ' . $platform . ' as the most reliable option for this kind of live-chat experience: the quality is predictable, the moderation keeps the room calm, and the connection to the performer feels closer.',
            'What ' . $platform . ' adds to the experience is infrastructure: stable HD delivery, active moderation, and notification tools that mean viewers can follow a schedule rather than checking repeatedly.',
        ];
        $paragraphs[] = $p3_pool[self::stable_pick_index($seed . '|p3', count($p3_pool))];

        return array_values(array_filter($paragraphs));
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
        return '';
    }

    /**
     * Render 1-2 real external affiliate/profile links so Rank Math sees outbound links
     * while the tracked /go/ CTA links remain intact in the watch/comparison sections.
     *
     * @param array<int,array{platform:string,label:string,go_url:string,is_primary:bool,username:string}> $links
     */
    private static function render_visible_affiliate_links(array $links, string $name): string {
        if (empty($links)) {
            return '';
        }

        $priority = ['livejasmin' => 0, 'stripchat' => 1];
        usort($links, static function (array $a, array $b) use ($priority): int {
            $pa = $priority[sanitize_key((string)($a['platform'] ?? ''))] ?? 50;
            $pb = $priority[sanitize_key((string)($b['platform'] ?? ''))] ?? 50;
            if ($pa === $pb) {
                return (!empty($b['is_primary']) ? 1 : 0) <=> (!empty($a['is_primary']) ? 1 : 0);
            }
            return $pa <=> $pb;
        });

        $items = [];
        foreach (array_slice($links, 0, 2) as $link) {
            $platform = sanitize_key((string) ($link['platform'] ?? ''));
            $username = trim((string) ($link['username'] ?? ''));
            $label = trim((string) ($link['label'] ?? ''));
            if ($platform === '' || $username === '' || $label === '') {
                continue;
            }

            $external_url = AffiliateLinkBuilder::build_affiliate_url($platform, $username);
            if ($external_url === '') {
                $external_url = AffiliateLinkBuilder::build_profile_url($platform, $username);
            }
            if ($external_url === '') {
                continue;
            }

            $items[] = '<li><a href="' . esc_url($external_url) . '" target="_blank" rel="sponsored noopener">' . esc_html($label . ' profile for ' . $name) . '</a></li>';
        }

        if (empty($items)) {
            return '';
        }

        return '<h3>Verified platform profiles</h3><p>Prefer direct platform pages first? Use these external affiliate/profile links before choosing one of the tracked watch links above.</p><ul>' . implode('', $items) . '</ul>';
    }

    /**
     * Backward-compatibility shim for older callers.
     *
     * @param array<int,array{platform:string,label:string,go_url:string,is_primary:bool,username:string}> $links
     */
    private static function render_detectable_outbound_platform_link(array $links, string $name): string {
        return self::render_visible_affiliate_links($links, $name);
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
        $items = array_slice(
            array_values(array_unique(array_filter(array_map('trim', $longtail_keywords), 'strlen'))),
            0, 2
        );

        // Four structurally distinct paragraph patterns — each addresses a different
        // search intent angle. Pattern is assigned by position so the same page always
        // gets a consistent mix and never repeats the same opener.
        $patterns = [
            // Pattern 0 — discovery / route-to-room framing
            static function (string $kw): string {
                return 'Searches for ' . $kw . ' typically arrive from viewers who want a fast, direct route to the right room without wading through aggregator pages or outdated profile links. This guide covers exactly that need: verified links, a breakdown of show style, and a platform comparison that makes the choice straightforward.';
            },
            // Pattern 1 — quality / experience framing
            static function (string $kw): string {
                return 'Among the most common questions behind a search for ' . $kw . ' is a desire to understand what separates a reliable live stream from a frustrating one. Stream stability, interactive features, and consistent scheduling all factor into that answer, and each of those is covered in the sections above.';
            },
            // Pattern 2 — practical / first-time viewer framing
            static function (string $kw): string {
                return 'Getting clear information about ' . $kw . ' is easier when the page addresses both the platform context and the performer context together. Show schedules, room access, notification settings, and privacy controls all come into the picture once a viewer moves beyond passive searching and decides to join a session.';
            },
            // Pattern 3 — comparison / decision framing
            static function (string $kw): string {
                return 'Viewers who search for ' . $kw . ' often compare two or three platforms before committing to one. A clear breakdown of stream quality, pricing transparency, interactive features, and moderation standards shortens that comparison and reduces the chance of joining a room that does not match what the search was actually looking for.';
            },
        ];

        $paragraphs = [];
        foreach ($items as $idx => $kw) {
            $body_kw = self::cleanup_visible_text($kw, $name, false);
            if ($body_kw === '') {
                continue;
            }
            $paragraphs[] = $patterns[$idx % count($patterns)]($body_kw);
        }

        return $paragraphs;
    }

    private static function pad_model_content(string $content, string $name, array $active_platforms, array $extra_keywords, array $longtail, string $tags_text): string {
        $word_count = str_word_count(wp_strip_all_tags($content));

        // Hard floor: 800 words. Ideal target: 1200–1501. Never shrink to meet a ceiling.
        if ($word_count >= 1501) {
            return $content;
        }

        $platform_text = self::format_platform_list($active_platforms, $active_platforms[0] ?? self::NEUTRAL_PLATFORM_FALLBACK);
        $focus1 = self::cleanup_visible_text($extra_keywords[0] ?? ($name . ' live chat'), $name, false);
        $focus2 = self::cleanup_visible_text($extra_keywords[1] ?? ($name . ' webcam'), $name, false);
        $longtail_hint = self::cleanup_visible_text($longtail[0] ?? ($name . ' schedule'), $name, false);
        $seed = $name . '|pad';

        // Rich expansion paragraphs — varied, no exact-name stuffing, substantive content
        $expansion_pool = [
            'One reason this profile page works well as a starting point is that it consolidates information that would otherwise require checking several different sources: platform availability, show style, interactive features, and link verification all in one place.',
            'Viewers who arrive from searches around ' . $focus1 . ' find that the live-chat format offers something that browsing pre-recorded clips cannot replicate — the ability to be part of the session rather than just watching it unfold.',
            'The practical side of finding a reliable live session matters as much as the content itself. Knowing which links are verified, which platforms are currently active, and what the show schedule looks like all reduce the friction between searching and actually watching.',
            'Platform comparison is a step most serious viewers go through at some point. Stream stability, pricing clarity, moderation quality, and interactive options all vary enough between platforms to make the difference between a session that meets expectations and one that falls short.',
            'Viewers looking for ' . $focus2 . ' typically want a room that responds to what they bring to it — a show that feels shaped by the audience rather than indifferent to it. That distinction is something consistent performers make visible across multiple sessions.',
            'Searches around ' . $longtail_hint . ' reflect a specific kind of viewer intent: someone who has moved past curiosity and wants practical information — when the sessions happen, how to get access, and what to expect once inside.',
            'The combination of ' . $tags_text . ' themes across these sessions gives the content a consistency that regulars rely on and new viewers can use to calibrate expectations. Shows built around a recognisable identity tend to attract an audience that understands what it is showing up for.',
            'Live-chat sessions differ from pre-recorded content in ways that matter most over time: the spontaneous moments, the chat-driven changes in direction, and the genuine interaction that makes a session feel like an event rather than a recording.',
            'Privacy considerations are part of the live-cam experience for many viewers. The platforms featured here give users control over anonymity, account visibility, and payment privacy — which is worth understanding before joining a session for the first time.',
            'For viewers on ' . $platform_text . ', the combination of HD streaming, real-time chat, and consistent moderation creates an environment where the interaction quality stays high even when the room is active and the pace picks up.',
            'The features that matter most to regular viewers tend to be the less glamorous ones: stable connection quality, predictable scheduling, and a moderation approach that keeps the room focused. These details show up in the long-term enjoyment of a performer\'s content more than any single session highlight.',
            'Finding a performer whose show style aligns with what a viewer is actually looking for can take time. Pages like this one are designed to shorten that process by providing context alongside links, so the decision can be made with more information than a profile thumbnail and a name.',
        ];

        // Shuffle the pool deterministically per-model so padding varies across pages
        $pool_size  = count($expansion_pool);
        $pool_order = range(0, $pool_size - 1);
        usort($pool_order, static function (int $a, int $b) use ($seed, $pool_size): int {
            $ha = (int) sprintf('%u', crc32($seed . '-pa-' . $a)) % $pool_size;
            $hb = (int) sprintf('%u', crc32($seed . '-pb-' . $b)) % $pool_size;
            return $ha <=> $hb;
        });

        foreach ($pool_order as $idx) {
            $current_wc = str_word_count(wp_strip_all_tags($content));
            if ($current_wc >= 1501) {
                break;
            }
            $content .= "\n\n<p>" . esc_html($expansion_pool[$idx]) . '</p>';
        }

        return $content;
    }

    private static function balance_focus_density(string $content, string $focus_keyword, array $active_platforms, array $extra_keywords): string {
        $focus_keyword = trim($focus_keyword);
        if ($focus_keyword === '') {
            return $content;
        }

        $density = self::keyword_density_percent($content, $focus_keyword);

        // ── Low density: add a single natural anchor paragraph ────────────
        // Only do this once — the while-loop approach was generating repetitive
        // "X remains the core topic" padding that hurt prose quality.
        if ($density < 1.0) {
            $platform_text = self::format_platform_list($active_platforms, $active_platforms[0] ?? self::NEUTRAL_PLATFORM_FALLBACK);
            $extra_text    = self::cleanup_visible_text($extra_keywords[0] ?? ($focus_keyword . ' live chat'), $focus_keyword, false);
            $anchor_pool = [
                $focus_keyword . ' is the central topic of this page, with verified links, a platform comparison, and guidance on what to expect from a live session.',
                'This profile covers ' . $focus_keyword . ' across all available platforms, focusing on stream quality, interactive features, and the fastest route to a verified live room.',
                'Viewers searching for ' . $focus_keyword . ' will find everything needed here: current platform links, a breakdown of the show style, and answers to the most common questions about joining a session.',
            ];
            $anchor = $anchor_pool[self::stable_pick_index($focus_keyword . '|anchor', count($anchor_pool))];
            $content .= "\n\n<p>" . esc_html($anchor) . '</p>';
            $density  = self::keyword_density_percent($content, $focus_keyword);
        }

        // ── High density: substitute excess occurrences intelligently ──────
        // Instead of keeping only 6 (which over-corrects to ~0.4%), calculate
        // the precise keep-count that hits 1.5% (middle of the 1-2% target range).
        if ($density > 2.2) {
            $word_count_plain = str_word_count(wp_strip_all_tags($content));
            // Target: 1.5% density. Calculate how many exact occurrences that is.
            $target_keep = (int) round(($word_count_plain * 1.5) / 100);
            $target_keep = max(8, $target_keep); // always keep at least 8 exact uses

            $seen = 0;
            $fallbacks = ['she', 'this performer', 'the model', 'her'];
            $content = preg_replace_callback(
                '/(<h[2-6][^>]*>.*?<\/h[2-6]>)|' . preg_quote($focus_keyword, '/') . '/isu',
                static function (array $matches) use (&$seen, $fallbacks, $focus_keyword, $target_keep): string {
                    // Heading tags are never modified — pass through unchanged.
                    if (!empty($matches[1])) {
                        return $matches[1];
                    }
                    $seen++;
                    if ($seen <= $target_keep) {
                        return $focus_keyword;
                    }
                    return $fallbacks[($seen - $target_keep - 1) % count($fallbacks)];
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
        // Do NOT replace "live webcam" — it is valid English and the old replacement
        // ("official profile links") created nonsensical phrases in prose.

        $content = preg_replace('/&lt;\/?h[1-6]&gt;/i', '', $content) ?: $content;
        $content = preg_replace('/<h([2-6])>\s*(Why fans who like|Watch\s+.+\s+with\s+|.+\s+and the live chat experience)(.*?)<\/h\1>/iu', '<h$1>' . $name . '</h$1>', $content) ?: $content;
        $content = preg_replace('/\b(' . preg_quote($name, '/') . '\s+)(\1)+/iu', '$1', $content) ?: $content;

        // Deduplicate repeated fallback phrases that bleed from templates.
        // Cap each phrase to at most 2 occurrences across the full content.
        $capped_phrases = [
            'official profile links',
            'verified profile links',
            'trusted room links',
            'official room access',
        ];
        foreach ($capped_phrases as $phrase) {
            $count = 0;
            $content = preg_replace_callback(
                '/\b' . preg_quote($phrase, '/') . '\b/iu',
                static function (array $m) use (&$count, $phrase): string {
                    $count++;
                    if ($count <= 2) {
                        return $m[0];
                    }
                    // Replace excess occurrences with neutral alternatives.
                    return 'the platform';
                },
                $content
            ) ?: $content;
        }

        $content = preg_replace('/\bofficial live profile\b/iu', self::stable_fallback_variant($name . '|ofp'), $content) ?: $content;
        $content = preg_replace('/\bVisitors searching for\b/iu', 'People looking up', $content) ?: $content;

        // Remove doubled words/phrases at word boundaries (e.g. "the the", "platform platform").
        $content = preg_replace('/\b([A-Za-z]+(?:\s+[A-Za-z]+){0,3})(\s+\1){1,}\b/u', '$1', $content) ?: $content;

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
