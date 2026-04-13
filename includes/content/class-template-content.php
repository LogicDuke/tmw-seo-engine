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
        $extra = self::filter_name_free_keywords($extra, $name);
        $extra = array_values(array_unique(array_merge(
            $extra,
            self::default_model_additional_keywords($primary_platform_label, $active_platforms)
        )));
        $extra = array_slice($extra, 0, 4);

        $longtail = is_array($pack['longtail'] ?? null) ? $pack['longtail'] : [];
        $longtail = self::filter_name_free_keywords($longtail, $name);
        $longtail = array_values(array_unique(array_merge(
            $longtail,
            self::default_model_longtail_keywords($primary_platform_label, $active_platforms)
        )));
        $longtail = array_slice($longtail, 0, 8);

        // Single source of truth for Rank Math chips and the on-page keyword
        // coverage block. Prefer the dedicated model-name-led list when available
        // (set by ModelKeywordPack::build() for model pages); fall back to $extra
        // for non-model pages and legacy packs that do not carry the key.
        $rankmath_keywords = !empty($pack['rankmath_additional'])
            ? array_slice((array) $pack['rankmath_additional'], 0, 4)
            : array_slice($extra, 0, 4);

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
            'extra_focus_1' => $extra[0] ?? 'live show schedule',
            'extra_focus_2' => $extra[1] ?? 'verified profile links',
            'extra_focus_3' => $extra[2] ?? 'private live chat',
            'extra_focus_4' => $extra[3] ?? 'HD live stream',
            'extra_keywords' => $extra,
            'longtail_keywords' => $longtail,
            'rankmath_additional_keywords' => $rankmath_keywords,
            'active_platforms' => $active_platforms,
            'active_platforms_text' => self::format_platform_list($active_platforms, $primary_platform_label),
        ];

        $intro_slug = (!empty($active_platforms) && count($active_platforms) > 1) ? 'model-intros-multi' : 'model-intros';
        $faq_slug   = (!empty($active_platforms) && count($active_platforms) > 1) ? 'model-faqs-multi' : 'model-faqs';

        $intro = self::cleanup_visible_text(TemplateEngine::render(TemplateEngine::pick($intro_slug, $seed), $context), $name, false);
        $bio = self::cleanup_visible_text(TemplateEngine::render(TemplateEngine::pick('model-bios', $seed, 1), $context), $name, false);
        $comparison_copy = self::cleanup_visible_text(TemplateEngine::render(TemplateEngine::pick('model-comparisons', $seed), $context), $name, false);

        $faqs_raw = TemplateEngine::pick_faq($faq_slug, $seed, 4);
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
            'Most people land here because search results for live rooms are cluttered with copies, stale embeds, or half-finished profile pages. Keeping ' . $name . ' in one place makes the choice easier and cuts down on guesswork.',
            'Finding the actual room should not take five tabs. The goal here is simple: point to the current profiles, explain the general room feel, and help you pick a platform for ' . $name . ' without wasting time.',
            'A useful ' . $name . ' page does two jobs well. It shows the right links, and it gives a realistic sense of what the room feels like before you click through.',
            'If you are mainly trying to work out where ' . $name . ' is live, start with the buttons below. The rest of the page fills in the practical details that usually matter once someone is ready to join.',
            'Search results for live profiles are often noisy. Keeping the verified rooms for ' . $name . ' in one place saves time and makes the platform choice a lot easier.',
        ];
        $second_intro = $second_intro_pool[self::stable_pick_index($seed . '|intro2', count($second_intro_pool))];

        $watch_para_pool = [
            'Use the buttons below to open ' . $name . "'s" . ' current rooms directly.',
            'Choose a platform below and you will land on ' . $name . "'s" . ' active room without bouncing through copied pages first.',
            'Opening ' . $name . "'s" . ' room a few minutes early is usually the easiest way to catch the start and settle into chat before it gets busy.',
        ];
        if ($primary_platform_label !== self::NEUTRAL_PLATFORM_FALLBACK) {
            $watch_para_pool[] = 'If you already prefer ' . $primary_platform_label . ', start there and compare the backup profile afterward.';
        }
        $watch_para = $watch_para_pool[self::stable_pick_index($seed . '|watch', count($watch_para_pool))];
        $keyword_coverage_html = self::render_rankmath_keyword_coverage($rankmath_keywords, $name);

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
                // No exact name in this paragraph — section headings already carry
                // the name; repeating it here inflates keyword density unnecessarily.
                'Watching ' . $name . ' live usually comes down to a few practical details: clear video, a chat that stays readable, and room tools that do not get in the way. The breakdown below covers the things worth checking before you join on ' . $platform_ref . '.',
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
            $content .= "\n\n" . '<h2>Why ' . esc_html($name) . ' stands out</h2><p>This page keeps the practical bits together: active platforms, a sense of the room style, and a quick way to compare verified options without extra searching.</p>';
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

        // When cta_links are not pre-built (OpenAI/Claude paths), sync the
        // platform table first so get_links() returns up-to-date rows.
        if (isset($pack['cta_links'])) {
            $source_links = $pack['cta_links'];
        } else {
            PlatformProfiles::sync_to_table((int) $post->ID);
            $source_links = PlatformProfiles::get_links($post->ID);
        }

        $cta_links = self::build_platform_cta_links((int) $post->ID, is_array($source_links) ? $source_links : []);

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

        // Build guaranteed external link block once; inject into both the watch
        // section (primary anchor) and the explore-more section (secondary anchor)
        // so Rank Math can detect outbound links regardless of which section
        // the tool happens to scan first.
        $guaranteed_outbound = self::render_guaranteed_external_platform_links($cta_links, $name);
        $wikipedia_fallback_used = false;

        // Fallback to Wikipedia ONLY when cta_links is empty — meaning no
        // platform username exists in either the profiles table or post meta
        // (build_platform_cta_links already tried both). If cta_links has
        // entries but render_guaranteed returned '' (URL resolution edge case),
        // do NOT substitute Wikipedia — that would put a generic webcam-model
        // link on a page that has real performer usernames.
        if ($guaranteed_outbound === '' && empty($cta_links)) {
            $guaranteed_outbound   = '<p>For background on live-cam performers, see <a href="https://en.wikipedia.org/wiki/Webcam_model" target="_blank" rel="noopener">this overview</a>.</p>';
            $wikipedia_fallback_used = true;
        }

        // ── Watch section: /go/ CTAs only — NO visible external links ────────
        // Visible external affiliate/profile links must appear ONLY in the Explore
        // More / external_info_html end section so Rank Math detects them at the
        // bottom rather than mid-body (requirement: links only at end of content).
        $watch_html = self::join_html_blocks( [
            self::render_primary_watch_cta( $cta_links, $name ),
            self::render_watch_cta_section( $cta_links, $name ),
            // $guaranteed_outbound intentionally excluded here — see below.
        ] );

        // ── Explore More / end section: ONE consolidated outbound link block ──
        // render_guaranteed_external_platform_links() already resolves affiliate
        // → profile → registry URLs in priority order and covers all active
        // platforms. Combining it with render_preferred_external_platform_links()
        // produced two near-identical LiveJasmin + Stripchat link blocks.
        // Use only the guaranteed block so Rank Math sees exactly one outbound
        // link group at the end of content — no duplicates.
        $ext_info_html = $guaranteed_outbound;

        return [
            'watch_section_html' => $watch_html,
            'comparison_section_html' => self::build_platform_comparison($post, $name, $cta_links, $comparison_copy),
            'related_models_html' => self::render_related_models($post, $name, $tags, $active_platforms),
            'explore_more_html' => self::render_internal_links($post),
            // All visible outbound links consolidated here — Explore More is the
            // only place in rendered content where real external links appear.
            'external_info_html' => $ext_info_html,
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

        $openers = [
            'Regulars usually talk about pacing before anything else. Nothing feels rushed, and the room has enough back-and-forth to keep even quieter sessions from going flat.',
            'The pull here is not a single gimmick. People stick around because the room stays attentive, the mood stays readable, and the live chat never feels like background noise.',
            'Across different sessions, the same qualities show up again: steady energy, clear reactions to the room, and a style that feels present instead of automatic.',
            $name . ' tends to hold attention through timing rather than noise. The room settles into a rhythm quickly, and that makes repeat visits feel easier instead of random.',
            'What keeps viewers coming back is consistency. The room feels awake, the chat matters, and the overall tone does not swing wildly from one session to the next.',
        ];

        $paragraphs = [$openers[self::stable_pick_index($seed . '|opener', count($openers))]];

        $clean_focuses = [];
        foreach ($focuses as $focus) {
            $focus = self::cleanup_visible_text($focus, $name, false);
            if ($focus !== '' && mb_strlen($focus, 'UTF-8') > 4 && !in_array($focus, $clean_focuses, true)) {
                $clean_focuses[] = $focus;
            }
        }

        if (!empty($clean_focuses)) {
            $focus = $clean_focuses[0];
            $pool = [
                'If ' . $focus . ' is what brought someone here, the main difference they notice is responsiveness. The room has give-and-take, not just a performer pushing forward regardless of chat.',
                'A phrase like ' . $focus . ' usually sounds broad, but the real appeal is simple. The room reacts, adjusts, and keeps enough space for the audience to shape the mood a little.',
                'People arriving through ' . $focus . ' are usually looking for a room that feels active without turning chaotic. That balance tends to hold up well here.',
            ];
            $paragraphs[] = $pool[self::stable_pick_index($seed . '|focus1', count($pool))];
        }

        $theme = $clean_focuses[1] ?? ($tags !== '' ? $tags : 'live room themes');
        $theme_pool = [
            'The mood around ' . $theme . ' comes through naturally rather than being pushed like a slogan. That makes the room easier to drop into at any point without feeling behind.',
            $theme . ' works best here as part of the atmosphere. It sets expectations without locking every session into the exact same pattern.',
            'Even when ' . $theme . ' is part of the draw, the room still leaves space for smaller detours, jokes, and quick shifts driven by chat.',
        ];
        $paragraphs[] = $theme_pool[self::stable_pick_index($seed . '|theme', count($theme_pool))];

        $platform_pool = [
            'That style translates well on ' . $platform . ' because the room tools stay manageable. Video is clear, chat stays readable, and the session does not get buried under clutter.',
            'On ' . $platform . ', the practical side helps more than people expect. Stable playback, decent moderation, and useful alerts make it easier to enjoy the room over time.',
            'People comparing platforms usually notice the same thing on ' . $platform . ': it keeps the room usable, which gives the performer more room to actually interact.',
        ];
        $paragraphs[] = $platform_pool[self::stable_pick_index($seed . '|platform', count($platform_pool))];

        return array_values(array_slice(array_filter($paragraphs), 0, 4));
    }

    private static function render_rankmath_keyword_coverage(array $keywords, string $name): string {
        $keywords = array_values(array_filter(array_map('trim', $keywords), 'strlen'));
        if (empty($keywords)) {
            return '';
        }

        $out  = '<p>Related searches people use before picking a room:</p><ul>';
        foreach ($keywords as $keyword) {
            // IMPORTANT: do NOT run literal keyword phrases through cleanup_visible_text().
            // That function transforms token patterns in ways that corrupt the phrase —
            // stripping "watch", replacing pronouns, etc. Keyword phrases must be preserved
            // verbatim; only trim() and esc_html() are safe here.
            $out .= '<li>' . esc_html(trim($keyword)) . '</li>';
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

        // ── Detect alternate Stripchat username ───────────────────────────────
        // When a model's Stripchat username differs from their primary (LiveJasmin)
        // username, add ONE short natural sentence so readers know where to look.
        $lj_username = '';
        $sc_username = '';
        foreach ($cta_links as $link) {
            $plat = sanitize_key((string) ($link['platform'] ?? ''));
            $user = trim((string) ($link['username'] ?? ''));
            if ($plat === 'livejasmin' && $user !== '') {
                $lj_username = $user;
            }
            if ($plat === 'stripchat' && $user !== '') {
                $sc_username = $user;
            }
        }
        $alt_username_note = '';
        if (
            $sc_username !== ''
            && strtolower($sc_username) !== strtolower($name)
            && ($lj_username === '' || strtolower($sc_username) !== strtolower($lj_username))
        ) {
            $alt_username_note = '<p>On Stripchat, this profile uses the username ' . esc_html($sc_username) . '.</p>';
        }
        // ── End alternate username detection ─────────────────────────────────

        if (empty($cta_links)) {
            $fallback = 'Use trusted official profile links and compare room features before you join ' . $name . '.';
            return $alt_username_note . '<p>' . esc_html($comparison_copy !== '' ? $comparison_copy : $fallback) . '</p>';
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

        return $alt_username_note . $table;
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

            return '<p><a href="' . esc_url($go_url) . '" target="_blank" rel="sponsored noopener">' . esc_html('Watch on ' . $label) . '</a></p>';
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
    /**
     * Canonical list of platforms we read username meta for.
     * Used by build_platform_cta_links() and the Wikipedia-fallback guard.
     */
    private const KNOWN_PLATFORM_SLUGS = [
        'livejasmin', 'stripchat', 'chaturbate',
        'myfreecams', 'camsoda', 'bonga', 'cam4',
    ];

    /**
     * Build CTA link rows from a platform-profile source array.
     *
     * v3 fix: when the PlatformProfiles table returns zero rows (not yet synced
     * or table empty), fall back to reading username meta directly so that
     * cta_links is never empty when valid usernames exist in post meta.
     *
     * @param array<int,array{platform?:string,is_primary?:string|int,username?:string,url?:string}> $links
     * @return array<int,array{platform:string,label:string,go_url:string,is_primary:bool,username:string}>
     */
    private static function build_platform_cta_links(int $post_id, array $links): array {
        $out  = [];
        $seen = [];

        foreach ($links as $link) {
            $platform = sanitize_key((string) ($link['platform'] ?? ''));
            if ($platform === '' || isset($seen[$platform])) {
                continue;
            }

            $username = trim((string) get_post_meta($post_id, '_tmwseo_platform_username_' . $platform, true));
            if ($username === '') {
                $username = trim((string) ($link['username'] ?? ''));
            }
            if ($username === '') {
                continue;
            }

            $go_url = AffiliateLinkBuilder::go_url($platform, $username);
            if ($go_url === '') {
                $go_url = trim((string) ($link['url'] ?? ''));
            }
            if ($go_url === '') {
                continue;
            }

            $platform_data = PlatformRegistry::get($platform);
            $label         = (string) ($platform_data['name'] ?? ucfirst($platform));

            $out[]          = [
                'platform'   => $platform,
                'label'      => $label,
                'go_url'     => $go_url,
                'is_primary' => ! empty($link['is_primary']),
                'username'   => $username,
            ];
            $seen[$platform] = true;
        }

        // ── Meta-only fallback ──────────────────────────────────────────────
        // PlatformProfiles table returned zero rows (sync not yet run / table
        // empty). Build CTA rows directly from the saved username meta keys so
        // that outbound link rendering is never blocked by table state.
        if (empty($out)) {
            $meta_first = true;
            foreach (self::KNOWN_PLATFORM_SLUGS as $meta_platform) {
                if (isset($seen[$meta_platform])) {
                    continue;
                }
                $meta_username = trim((string) get_post_meta($post_id, '_tmwseo_platform_username_' . $meta_platform, true));
                if ($meta_username === '') {
                    continue;
                }
                $meta_go_url = AffiliateLinkBuilder::go_url($meta_platform, $meta_username);
                if ($meta_go_url === '') {
                    continue;
                }
                $meta_pdata = PlatformRegistry::get($meta_platform);
                $meta_label = (string) ($meta_pdata['name'] ?? ucfirst($meta_platform));
                $out[] = [
                    'platform'   => $meta_platform,
                    'label'      => $meta_label,
                    'go_url'     => $meta_go_url,
                    'is_primary' => $meta_first,
                    'username'   => $meta_username,
                ];
                $seen[$meta_platform] = true;
                $meta_first           = false;
            }
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
            // Skip the primary platform — render_primary_watch_cta() already outputs
            // it as a prominent <p> CTA above this section. Including it again here
            // would produce a duplicate "Watch {name} on {platform}" entry.
            if (!empty($link['is_primary'])) {
                continue;
            }

            $url = (string)($link['go_url'] ?? '');
            $platform = (string)($link['label'] ?? '');
            if ($url === '' || $platform === '') {
                continue;
            }

            $items[] = '<li><a href="' . esc_url($url) . '" target="_blank" rel="sponsored nofollow noopener">' . esc_html('Watch on ' . $platform) . '</a></li>';

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
     * @deprecated Superseded by render_guaranteed_external_platform_links().
     * Kept as a tombstone so any stale call-site produces no output instead of
     * duplicating the guaranteed outbound link block that lives in external_info_html.
     *
     * @param array<int,array{platform:string,label:string,go_url:string,is_primary:bool,username:string}> $links
     */
    private static function render_preferred_external_platform_links(array $links, string $name): string {
        // Intentionally returns empty string.
        // The single outbound link block is rendered exclusively by
        // render_guaranteed_external_platform_links() via $ext_info_html.
        // Returning anything here would produce a duplicate link block.
        return '';
    }

    /**
     * Build a guaranteed visible outbound link block using platform usernames.
     *
     * This is a last-resort guarantee layer: it constructs profile URLs directly
     * from PlatformRegistry patterns, bypassing all affiliate settings. Called
     * separately from render_preferred_external_platform_links() so that even if
     * the Explore More section is empty, at least one detectable external link
     * exists in the Watch section HTML.
     *
     * Returns empty string only when no platform username exists at all.
     *
     * @param array<int,array{platform:string,label:string,go_url:string,is_primary:bool,username:string}> $links
     */
    /**
     * Build a guaranteed visible outbound link block using platform usernames.
     *
     * v3 URL resolution order (corrected):
     *   1. AffiliateLinkBuilder::build_affiliate_url()  — uses configured partner template
     *   2. AffiliateLinkBuilder::build_profile_url()    — bare profile URL via AffiliateLinkBuilder
     *   3. PlatformRegistry profile_url_pattern direct  — last-resort, always produces a real URL
     *
     * Returns empty string ONLY when no platform username exists at all.
     *
     * @param array<int,array{platform:string,label:string,go_url:string,is_primary:bool,username:string}> $links
     */
    private static function render_guaranteed_external_platform_links(array $links, string $name): string {
        if (empty($links)) {
            return '';
        }

        $priority = ['livejasmin' => 0, 'stripchat' => 1];
        usort($links, static function (array $a, array $b) use ($priority): int {
            $ap = sanitize_key((string) ($a['platform'] ?? ''));
            $bp = sanitize_key((string) ($b['platform'] ?? ''));
            $ai = $priority[$ap] ?? 50;
            $bi = $priority[$bp] ?? 50;
            if ($ai === $bi) {
                return (! empty($b['is_primary']) <=> ! empty($a['is_primary']));
            }
            return $ai <=> $bi;
        });

        $items = [];
        $seen  = [];
        foreach ($links as $link) {
            $platform = sanitize_key((string) ($link['platform'] ?? ''));
            $username = trim((string) ($link['username'] ?? ''));
            $label    = trim((string) ($link['label'] ?? ''));
            if ($platform === '' || $username === '' || $label === '' || isset($seen[$platform])) {
                continue;
            }

            $external_url = AffiliateLinkBuilder::build_affiliate_url($platform, $username);
            if ($external_url === '') {
                $external_url = AffiliateLinkBuilder::build_profile_url($platform, $username);
            }
            if ($external_url === '') {
                $platform_data = PlatformRegistry::get($platform);
                $pattern       = is_array($platform_data) ? (string) ($platform_data['profile_url_pattern'] ?? '') : '';
                if ($pattern !== '') {
                    $candidate = str_replace('{username}', rawurlencode($username), $pattern);
                    if (wp_http_validate_url($candidate)) {
                        $external_url = $candidate;
                    }
                }
            }

            if ($external_url === '') {
                continue;
            }

            $items[]         = '<li><a href="' . esc_url($external_url) . '" target="_blank" rel="noopener external">' . esc_html($label . ' profile') . '</a></li>';
            $seen[$platform] = true;
            if (count($items) >= 2) {
                break;
            }
        }

        if (empty($items)) {
            return '';
        }

        return '<p>Official platform profiles are listed here if you want to compare the rooms directly before choosing a watch link:</p><ul>' . implode('', $items) . '</ul>';
    }

    /**
     * Ensure at least one real outbound link is visible for SEO tools
     * while keeping affiliate /go/ CTA links intact.
     *
     * Note: Wikipedia fallback removed — when real platform usernames exist
     * we always output a real external link. Wikipedia fallback would produce
     * a generic nofollow link Rank Math cannot attribute to this model.
     *
     * @param array<int,array{platform:string,label:string,go_url:string,is_primary:bool,username:string}> $links
     */
    private static function render_detectable_outbound_platform_link(array $links, string $name): string {
        // Use the guaranteed builder rather than AffiliateLinkBuilder only.
        $block = self::render_guaranteed_external_platform_links($links, $name);
        if ($block !== '') {
            return $block;
        }
        // Only fall back to Wikipedia when no platform username exists at all.
        return '<p>For background on live-cam performers, see <a href="https://en.wikipedia.org/wiki/Webcam_model" target="_blank" rel="noopener">this overview</a>.</p>';
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
        $normalized_no_year = preg_replace('/\b(19|20)\d{2}\b/', '', $normalized) ?: $normalized;
        $normalized_no_year = trim(preg_replace('/\s+/', ' ', $normalized_no_year) ?: $normalized_no_year);

        // ── Legacy exact-match patterns (kept for back-compat) ───────────────
        $legacy_patterns = [
            '— live cam profile',
            '- live cam profile',
            '— verified live cam profile',
            '- verified live cam profile',
            '— live cam model profile & schedule',
            '- live cam model profile & schedule',
        ];

        if (in_array($normalized_no_year, $legacy_patterns, true)) {
            return true;
        }

        // ── Structural requirements — title is weak if EITHER is missing ─────

        // Requirement 1: must contain a 4-digit year or a standalone number.
        $has_number = (bool) preg_match('/\b(?:19|20)\d{2}\b|\b\d+\b/', $clean);

        // Requirement 2: must contain at least one power / sentiment word from
        // the canonical allow-list (same list used by build_default_model_seo_title).
        $power_words = self::model_title_allow_words();
        $has_power_word = false;
        foreach ($power_words as $word) {
            if (str_contains($normalized, strtolower($word))) {
                $has_power_word = true;
                break;
            }
        }

        if (!$has_number || !$has_power_word) {
            return true;
        }

        return false;
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
            0,
            4
        );

        $patterns = [
            static function (string $kw): string {
                return $kw . ' usually comes from people who want a straight answer on where to click and what kind of room they are opening. That is why the page keeps the live links, platform notes, and basic expectations together.';
            },
            static function (string $kw): string {
                return 'With ' . $kw . ', the useful questions are practical ones: does the stream load quickly, are notifications reliable, and is the room easy to follow on mobile? Those details matter more than hype once someone is ready to join.';
            },
            static function (string $kw): string {
                return 'A query like ' . $kw . ' usually means the browsing stage is over. People want a cleaner route into the room, a sense of the schedule, and enough context to decide whether the platform fits.';
            },
            static function (string $kw): string {
                return 'When someone searches ' . $kw . ', they are often comparing convenience more than hype. Stable video, clear navigation, and a readable chat make more difference than flashy copy.';
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

        return array_values(array_filter($paragraphs));
    }

    private static function pad_model_content(string $content, string $name, array $active_platforms, array $extra_keywords, array $longtail, string $tags_text): string {
        $word_count = str_word_count(wp_strip_all_tags($content));

        if ($word_count >= 1001) {
            return $content;
        }

        $platform_text = self::format_platform_list($active_platforms, $active_platforms[0] ?? self::NEUTRAL_PLATFORM_FALLBACK);
        $focus1 = self::cleanup_visible_text($extra_keywords[0] ?? 'live show schedule', $name, false);
        $focus2 = self::cleanup_visible_text($extra_keywords[1] ?? 'private live chat', $name, false);
        $longtail_hint = self::cleanup_visible_text($longtail[0] ?? 'live show schedule', $name, false);
        $seed = $name . '|pad';

        $expansion_pool = [
            'A page like this is most useful when it shortens the boring part of the process. Instead of checking several profile aggregators, you can see which platforms are active and go straight to ' . $name . ' on the platform that matches how you like to watch.',
            'The live format changes the experience more than it seems at first. A steady room with readable chat can turn a simple session into something people want to revisit.',
            'Most regular viewers end up caring about the small practical details: whether notifications are dependable, whether mobile playback behaves properly, and whether the room stays manageable once more people join.',
            $focus1 . ' fits the page because the real choice usually comes down to interaction. Some viewers want a quieter room, some want more public chat, and platform differences matter once that preference becomes clear.',
            $focus2 . ' sounds broad, but it usually points to a simple expectation: a stream that feels active, not canned. That comes from pacing, quick reactions, and a room that does not ignore its own chat.',
            $longtail_hint . ' matters for a practical reason. People would rather know when a room tends to open than keep refreshing random profile pages and hoping they got the right one.',
            $tags_text . ' themes help set expectations, but they do not lock every session into the same pattern. The better rooms leave space for mood changes and small detours.',
            'Privacy settings are not glamorous, but they matter. Good platforms make it easy to watch with a little distance, control account visibility, and keep payments separate from the rest of daily browsing.',
            'The best parts of live chat are usually the unscripted ones: a quick reply, a running joke, a shift in pace because the room steered it there.',
            'Comparing platforms is worth a minute or two, especially if you care about stream stability or private-room tools. Those differences do not sound exciting, but they shape the whole experience.',
            'HD video is nice, but it is not the only thing people notice. Clean audio, fast room loading, and moderation that keeps the chat readable do just as much for the overall feel.',
            'When the room has a clear rhythm, new viewers settle in faster. That is part of why consistent performers build repeat audiences even when plenty of other profiles are available.',
            'For viewers moving between ' . $platform_text . ', the comparison usually comes down to room feel. One platform may feel quieter, another may feel busier, and having both listed saves time.',
        ];

        $pool_size  = count($expansion_pool);
        $pool_order = range(0, $pool_size - 1);
        usort($pool_order, static function (int $a, int $b) use ($seed, $pool_size): int {
            $ha = (int) sprintf('%u', crc32($seed . '-pa-' . $a)) % $pool_size;
            $hb = (int) sprintf('%u', crc32($seed . '-pb-' . $b)) % $pool_size;
            return $ha <=> $hb;
        });

        foreach ($pool_order as $idx) {
            $current_wc = str_word_count(wp_strip_all_tags($content));
            if ($current_wc >= 1001) {
                break;
            }
            $content .= "

<p>" . esc_html($expansion_pool[$idx]) . '</p>';
        }

        return self::split_long_paragraphs($content);
    }

    private static function balance_focus_density(string $content, string $focus_keyword, array $active_platforms, array $extra_keywords): string {
        $focus_keyword = trim($focus_keyword);
        if ($focus_keyword === '') {
            return $content;
        }

        $density = self::keyword_density_percent($content, $focus_keyword);

        if ($density < 1.15) {
            $anchor_pool = [
                'Anyone landing here for ' . $focus_keyword . ' will find the essentials in one place. Keeping ' . $focus_keyword . ' tied to the current room links saves time and cuts down on guesswork.',
                'This page keeps ' . $focus_keyword . ' easy to follow with live links, room notes, and scheduling cues. It also makes ' . $focus_keyword . ' easier to compare across the active platforms without extra searching.',
                'If ' . $focus_keyword . ' is the reason you are here, the page is built to save time. The active profiles for ' . $focus_keyword . ' are listed first, with enough context to choose a room confidently.',
            ];
            $anchor = $anchor_pool[self::stable_pick_index($focus_keyword . '|anchor', count($anchor_pool))];
            $content .= "

<p>" . esc_html($anchor) . '</p>';
        }

        if (self::keyword_density_percent($content, $focus_keyword) < 1.0) {
            $content .= "

<p>" . esc_html('For quick reference, ' . $focus_keyword . ' is listed with the current platform usernames so the right room is easier to find.') . '</p>';
        }

        if (self::keyword_density_percent($content, $focus_keyword) < 1.3) {
            $content .= "

<p>" . esc_html('People usually open a page like this to find ' . $focus_keyword . ' quickly and compare the active rooms without guesswork. Keeping ' . $focus_keyword . ' tied to verified profiles makes ' . $focus_keyword . ' easier to follow across platforms.') . '</p>';
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
            $raw = (string) ($matches[1] ?? '');
            if (preg_match('/<[^>]+>/', $raw)) {
                return $matches[0];
            }

            $text = trim(wp_strip_all_tags($raw));
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


    /** @return string[] */
    private static function filter_name_free_keywords(array $keywords, string $name): array {
        $name = trim($name);
        $out = [];
        foreach ($keywords as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword === '') {
                continue;
            }
            if ($name !== '' && mb_stripos($keyword, $name, 0, 'UTF-8') !== false) {
                continue;
            }
            $out[] = $keyword;
        }

        return array_values(array_unique($out));
    }

    /** @return string[] */
    private static function default_model_additional_keywords(string $primary_platform_label, array $active_platforms): array {
        $platforms = array_values(array_unique(array_filter(array_map('trim', $active_platforms), 'strlen')));
        $keywords = [];

        if ($primary_platform_label !== '' && $primary_platform_label !== self::NEUTRAL_PLATFORM_FALLBACK) {
            $keywords[] = $primary_platform_label . ' schedule';
        }

        $secondary_platform = '';
        foreach ($platforms as $platform) {
            if ($platform !== '' && $platform !== $primary_platform_label) {
                $secondary_platform = $platform;
                break;
            }
        }

        if ($secondary_platform !== '') {
            $keywords[] = $secondary_platform . ' profile';
        } elseif ($primary_platform_label !== '' && $primary_platform_label !== self::NEUTRAL_PLATFORM_FALLBACK) {
            $keywords[] = $primary_platform_label . ' profile';
        }

        $keywords[] = 'verified profile links';
        $keywords[] = 'private live chat';
        $keywords[] = 'HD live stream';
        $keywords[] = 'real-time chat features';
        $keywords[] = 'live webcam chat tips';
        $keywords[] = 'live show schedule';

        return array_values(array_unique(array_filter(array_map('trim', $keywords), 'strlen')));
    }

    /** @return string[] */
    private static function default_model_longtail_keywords(string $primary_platform_label, array $active_platforms): array {
        $platforms = array_values(array_unique(array_filter(array_map('trim', $active_platforms), 'strlen')));
        $keywords = [
            'how to watch live webcam shows',
            'live show schedule',
            'private live chat tips',
            'HD live stream experience',
            'real-time chat features',
            'how to join a live session',
        ];

        if ($primary_platform_label !== '' && $primary_platform_label !== self::NEUTRAL_PLATFORM_FALLBACK) {
            $keywords[] = $primary_platform_label . ' live show schedule';
            $keywords[] = $primary_platform_label . ' profile guide';
        }

        foreach ($platforms as $platform) {
            if ($platform === '' || $platform === $primary_platform_label) {
                continue;
            }
            $keywords[] = $platform . ' profile guide';
            break;
        }

        return array_values(array_unique(array_filter(array_map('trim', $keywords), 'strlen')));
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
            '<li><strong>Mobile-friendly:</strong> Join the live room from any device with a modern browser.</li>',
            '<li><strong>Notification alerts:</strong> Enable follow alerts on ' . esc_html($platform) . ' to get pinged when a new session starts.</li>',
            '<li><strong>Schedule flexibility:</strong> Sessions rotate, so check back regularly for updated times.</li>',
            '<li><strong>Respectful community:</strong> Moderation keeps the chat positive and on-topic.</li>',
            '<li><strong>Interactive features:</strong> Polls, tip-triggered actions, and two-way conversation keep sessions engaging.</li>',
        ];

        foreach (array_slice($tag_phrases, 0, 2) as $tag) {
            $pool[] = '<li><strong>' . esc_html(ucfirst($tag)) . ' content:</strong> Fans of ' . esc_html($tag) . ' will find sessions here match that style.</li>';
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
        $content = str_replace('This guide covers the practical side:', 'This page focuses on the practical side:', $content);
        $content = str_replace('This guide covers exactly that need:', 'That is why the page keeps the basics together:', $content);
        $content = str_replace('The appeal extends beyond any single session.', 'There is more here than one good session.', $content);
        $content = preg_replace('/\bVisitors searching for\b/iu', 'People coming for', $content) ?: $content;

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
