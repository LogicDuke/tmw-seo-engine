<?php
namespace TMWSEO\Engine\Content;

use TMWSEO\Engine\Templates\TemplateEngine;
use TMWSEO\Engine\Platform\AffiliateLinkBuilder;
use TMWSEO\Engine\Platform\PlatformProfiles;
use TMWSEO\Engine\Platform\PlatformRegistry;
use TMWSEO\Engine\Keywords\ModelKeywordPack;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Services\TitleFixer;
use TMWSEO\Engine\Model\VerifiedLinks;
use TMWSEO\Engine\Model\VerifiedLinksFamilies;

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
        $editor_seed = self::get_editor_seed_data((int) $post->ID);

        $resolved_destinations = ModelDestinationResolver::resolve((int) $post->ID);
        $cta_links = (array) ($resolved_destinations['watch_cta_destinations'] ?? []);
        $active_platforms = array_values(array_filter(array_map('strval', (array)($resolved_destinations['active_platform_labels'] ?? [])), 'strlen'));
        $primary_platform_label = '';
        foreach ($cta_links as $row) {
            $label = trim((string)($row['label'] ?? ''));
            if ($primary_platform_label === '' && !empty($row['is_primary']) && $label !== '') {
                $primary_platform_label = $label;
            }
        }
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

        $secondary_visible_phrases = self::select_visible_secondary_keyword_phrases($rankmath_keywords, $extra);
        $secondary_heading_phrases = self::select_heading_safe_secondary_keyword_phrases($name, $rankmath_keywords, $extra);
        $secondary_heading_slots = self::build_secondary_heading_slots($secondary_heading_phrases);

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
        $active_platform_count = count($active_platforms);
        $second_intro_pool = $active_platform_count === 1
            ? [
                'Start with the active live-room destination first, then use other verified destinations for follow, backup, and profile checks.',
                'Use this page as a quick routing guide: live-room entry first, then verified non-live destinations for status checks and backup paths.',
                'Open the active room first, and keep verified backup destinations nearby in case status changes before you join.',
                'Everything here is practical: one active room, verified backup destinations, and a quick checklist before you commit.',
                'Use the live-room link for entry, then cross-check the handle on verified non-live destinations if anything looks off.',
            ]
            : [
                'Start with the official watch links first, then use the comparison section to choose between active platforms.',
                'Use this page as a quick decision hub: official links first, platform choice notes second.',
                'Use the direct room buttons first; then compare active platforms to decide where to stay.',
                'Everything here is problem-first: real room access, trusted links, and practical platform choices.',
                'If you are deciding where to watch, open your familiar platform first and then check the second active room.',
            ];
        $second_intro = $second_intro_pool[self::stable_pick_index($seed . '|intro2', count($second_intro_pool))];
        if (!empty($secondary_visible_phrases[0])) {
            $second_intro .= ' Visitors looking for ' . $secondary_visible_phrases[0] . ' can use this guide to stay on verified destinations.';
        }

        $watch_para_pool = [
            'Use the links below to open live-room destinations found active in the latest review pass. This section intentionally excludes fan pages, social channels, and link hubs.',
            'Choose a live platform below to reach a verified destination first, then confirm room status before joining. Listings outside this section are for follow/support access.',
            'Open a verified live profile first, then treat aggregators and copied listings as secondary references only.',
        ];
        if ($primary_platform_label !== self::NEUTRAL_PLATFORM_FALLBACK) {
            $watch_para_pool[] = 'If you already prefer ' . $primary_platform_label . ', start there and compare the backup profile afterward.';
        }
        $watch_para = $watch_para_pool[self::stable_pick_index($seed . '|watch', count($watch_para_pool))];
        $keyword_coverage_html = self::render_rankmath_keyword_coverage($rankmath_keywords, $name);

        $model_data_gate = self::evaluate_model_data_gate($post, array_merge($pack, [
            'name' => $name,
            'cta_links' => $cta_links,
            'tags' => $tags,
            'active_platforms' => $active_platforms,
            'longtail' => $longtail,
            'comparison_copy' => $comparison_copy,
            'editor_seed' => $editor_seed,
            'resolved_destinations' => $resolved_destinations,
        ]));

        $support_payload = self::build_model_renderer_support_payload($post, array_merge($pack, [
            'name' => $name,
            'cta_links' => $cta_links,
            'tags' => $tags,
            'active_platforms' => $active_platforms,
            'longtail' => $longtail,
            'comparison_copy' => $comparison_copy,
            'model_data_gate' => $model_data_gate,
            'editor_seed' => $editor_seed,
            'resolved_destinations' => $resolved_destinations,
            'secondary_visible_phrases' => $secondary_visible_phrases,
            'secondary_heading_slots' => $secondary_heading_slots,
        ]));

        $platform_ref = $primary_platform_label !== self::NEUTRAL_PLATFORM_FALLBACK
            ? $primary_platform_label
            : 'the platform';

        $seed_about = self::build_seed_about_paragraphs($editor_seed, $name);
        $has_specific_about = !empty($seed_about) || self::has_specific_supporting_data($name, $bio, $active_platforms, $tags, $cta_links);
        $features_intro = $model_data_gate['is_sufficient']
            ? 'Use this section to answer one question fast: which platform matches your speed, trust, and mobile needs.'
            : 'Features listed here cover platform access checks only, not unverified performer-specific traits.';
        $intro_paragraphs = self::build_seed_intro_paragraphs($name, $editor_seed, $active_platforms, $intro, $second_intro);
        $comparison_paragraphs = self::build_seed_comparison_paragraphs($editor_seed, $comparison_copy);
        if (empty($comparison_paragraphs)) {
            $comparison_paragraphs = [$comparison_copy];
        }
        $faq_items = self::build_seed_faq_items($editor_seed, $faqs_tpl, $name);

        $renderer_payload = array_merge($support_payload, [
            'focus_keyword' => $name,
            'intro_paragraphs' => $intro_paragraphs,
            'watch_section_paragraphs' => [
                $watch_para,
            ],
            'about_section_paragraphs' => $has_specific_about ? (!empty($seed_about) ? $seed_about : [$bio]) : [],
            'fans_like_section_paragraphs' => self::build_fans_like_paragraphs($context, $name, $model_data_gate, $editor_seed),
            'features_section_paragraphs' => [
                $features_intro
                . ' Check playback, chat clarity, and account controls before joining on ' . $platform_ref . '.'
                . (!empty($secondary_visible_phrases[1]) ? ' If you are comparing ' . $secondary_visible_phrases[1] . ', use verified platform labels before opening a room.' : ''),
            ],
            'features_section_html' => self::join_html_blocks([
                self::render_varied_features($name, $tags, $primary_platform_label, $seed),
                $keyword_coverage_html,
            ]),
            'comparison_section_paragraphs' => $comparison_paragraphs,
            'faq_items' => $faq_items,
            'secondary_heading_slots' => $secondary_heading_slots,
        ]);

        if (!$model_data_gate['is_sufficient']) {
            $renderer_payload = array_merge($renderer_payload, self::build_sparse_model_payload($name, $active_platforms, $model_data_gate, $rankmath_keywords, $extra));
        }

        $content = ModelPageRenderer::render($name, $renderer_payload);
        $content = self::split_long_paragraphs($content);
        $content = self::cleanup_model_content($content, $name);
        $content = self::ensure_minimum_useful_depth($content, $name, $active_platforms, $resolved_destinations, $primary_platform_label, $seed);
        $content = self::apply_lightweight_content_guardrails($content, $name);

        $seo_title = self::build_default_model_seo_title($name, $primary_platform_label, (int) $post->ID);

        $meta_description = 'Join ' . $name . "'s live chat";
        if ($primary_platform_label !== '' && $primary_platform_label !== self::NEUTRAL_PLATFORM_FALLBACK) {
            $meta_description .= ' on ' . $primary_platform_label;
        }
        $meta_description .= '. Find official links, platform comparisons, and practical FAQs to get started.';

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

        $resolved_destinations = is_array($pack['resolved_destinations'] ?? null)
            ? (array) $pack['resolved_destinations']
            : ModelDestinationResolver::resolve((int) $post->ID);

        $cta_links = isset($pack['cta_links']) && is_array($pack['cta_links'])
            ? $pack['cta_links']
            : (array) ($resolved_destinations['watch_cta_destinations'] ?? []);

        $active_platforms = $pack['active_platforms'] ?? [];
        if (!is_array($active_platforms) || empty($active_platforms)) {
            $active_platforms = [];
            $active_platforms = array_values(array_filter(array_map('strval', (array)($resolved_destinations['active_platform_labels'] ?? [])), 'strlen'));
        }
        $active_platforms = array_values(array_unique(array_filter(array_map('strval', $active_platforms), 'strlen')));

        $tags = $pack['tags'] ?? ($pack['sources']['tags'] ?? []);
        if (!is_array($tags) || empty($tags)) {
            $tags = self::discover_model_tags($post);
        }
        $tags = array_values(array_filter(array_map('strval', $tags), 'strlen'));

        $longtail = $pack['longtail'] ?? ($pack['longtail_keywords'] ?? []);
        $longtail = is_array($longtail) ? $longtail : [];
        $extra = is_array($pack['additional'] ?? null) ? $pack['additional'] : [];
        $rankmath_keywords = !empty($pack['rankmath_additional']) && is_array($pack['rankmath_additional'])
            ? array_slice((array) $pack['rankmath_additional'], 0, 4)
            : array_slice($extra, 0, 4);
        $secondary_visible_phrases = isset($pack['secondary_visible_phrases']) && is_array($pack['secondary_visible_phrases'])
            ? array_values(array_filter(array_map('strval', $pack['secondary_visible_phrases']), 'strlen'))
            : self::select_visible_secondary_keyword_phrases($rankmath_keywords, $extra);
        $secondary_heading_slots = isset($pack['secondary_heading_slots']) && is_array($pack['secondary_heading_slots'])
            ? self::normalize_secondary_heading_slot_payload($pack['secondary_heading_slots'])
            : self::build_secondary_heading_slots(self::select_heading_safe_secondary_keyword_phrases($name, $rankmath_keywords, $extra));
        $comparison_copy = trim((string)($pack['comparison_copy'] ?? ''));
        $model_data_gate = is_array($pack['model_data_gate'] ?? null) ? $pack['model_data_gate'] : ['is_sufficient' => true];
        $editor_seed = is_array($pack['editor_seed'] ?? null) ? $pack['editor_seed'] : self::get_editor_seed_data((int) $post->ID);

        // Build guaranteed external link block once; inject into both the watch
        // section (primary anchor) and the explore-more section (secondary anchor)
        // so Rank Math can detect outbound links regardless of which section
        // the tool happens to scan first.
        $guaranteed_targets  = self::build_guaranteed_external_platform_targets($cta_links);
        $guaranteed_outbound = self::render_guaranteed_external_platform_links_from_targets($guaranteed_targets);
        $curated_external    = self::render_curated_verified_links_section((int) $post->ID, $name, $guaranteed_targets, $resolved_destinations);
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
        $ext_info_html = self::join_html_blocks([
            $guaranteed_outbound,
            $curated_external,
        ]);

        $official_destinations_html = self::render_other_official_destinations_section($resolved_destinations);
        $community_destinations_html = self::render_social_channel_destinations_section($resolved_destinations);

        return [
            'watch_section_html' => $watch_html,
            'comparison_section_html' => self::build_platform_comparison($post, $name, $cta_links, $comparison_copy, $editor_seed),
            'official_destinations_section_html' => $official_destinations_html,
            'official_destinations_section_paragraphs' => [
                'These destinations are official and verified, but they are not currently treated as active live-room links.',
                'Use this section for profile verification, follow paths, support pages, and backup navigation when live-room status is inactive or unclear.',
                'Separating these links from the live section keeps routing truthful: a verified destination can be real without being active for room entry right now.',
            ],
            'community_destinations_section_html' => $community_destinations_html,
            'community_destinations_section_paragraphs' => [
                'Use verified social profiles, link hubs, and channels for updates, archives, and cross-checking handles.',
                'These links are useful for identity verification and schedule tracking, but they are not presented as direct live-room shortcuts.',
            ],
            'related_models_html' => '',
            'explore_more_html' => '',
            // All visible outbound links consolidated here — Explore More is the
            // only place in rendered content where real external links appear.
            'external_info_html' => $ext_info_html,
            'official_links_section_paragraphs' => [
                self::build_official_links_summary($name, $cta_links, (int) $post->ID, $resolved_destinations),
                self::build_verification_process_paragraph($resolved_destinations)
                    . (!empty($secondary_visible_phrases[2]) ? ' This also helps when checking ' . $secondary_visible_phrases[2] . ' across verified destinations.' : ''),
            ],
            'secondary_heading_slots' => $secondary_heading_slots,
            'questions_section_paragraphs' => [],
            'longtail_keywords' => $longtail,
            'model_data_gate' => $model_data_gate,
            'active_platforms' => $active_platforms,
            'editor_seed_summary' => (string) ($editor_seed['summary'] ?? ''),
            'editor_seed_platform_notes' => (array) ($editor_seed['platform_notes'] ?? []),
            'editor_seed_confirmed_facts' => (array) ($editor_seed['confirmed_facts'] ?? []),
            'editor_seed_known_for_tags' => (array) ($editor_seed['known_for_tags'] ?? []),
            'resolved_destination_summary' => (array) ($resolved_destinations['source_of_truth_summary'] ?? []),
            'verified_destination_families' => [
                'social' => (array) ($resolved_destinations['social_destinations'] ?? []),
                'link_hubs' => (array) ($resolved_destinations['link_hub_destinations'] ?? []),
                'personal' => (array) ($resolved_destinations['personal_site_destinations'] ?? []),
                'fan_platforms' => (array) ($resolved_destinations['fan_platform_destinations'] ?? []),
                'tube' => (array) ($resolved_destinations['tube_destinations'] ?? []),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $pack
     * @return array{is_sufficient:bool,confidence:float,signals:array<string,int>,reason:string}
     */
    public static function evaluate_model_data_gate(\WP_Post $post, array $pack): array {
        $cta_links = isset($pack['cta_links']) && is_array($pack['cta_links'])
            ? $pack['cta_links']
            : (array) ((is_array($pack['resolved_destinations'] ?? null) ? $pack['resolved_destinations'] : ModelDestinationResolver::resolve((int) $post->ID))['watch_cta_destinations'] ?? []);
        $tags = $pack['tags'] ?? ($pack['sources']['tags'] ?? []);
        $tags = is_array($tags) ? array_values(array_filter(array_map('strval', $tags), 'strlen')) : [];
        $faq_items = $pack['faq_items'] ?? [];

        $comparison_copy = trim((string)($pack['comparison_copy'] ?? ''));
        $active_platforms = $pack['active_platforms'] ?? [];
        if (!is_array($active_platforms) || empty($active_platforms)) {
            $active_platforms = (array) ((is_array($pack['resolved_destinations'] ?? null) ? $pack['resolved_destinations'] : ModelDestinationResolver::resolve((int) $post->ID))['active_platform_labels'] ?? []);
        }
        $active_platforms = array_values(array_filter(array_map('strval', $active_platforms), 'strlen'));
        $editor_seed = isset($pack['editor_seed']) && is_array($pack['editor_seed'])
            ? $pack['editor_seed']
            : self::get_editor_seed_data((int) $post->ID);
        $seed_fact_count = count((array)($editor_seed['confirmed_facts'] ?? []))
            + count((array)($editor_seed['known_for_tags'] ?? []))
            + (trim((string)($editor_seed['summary'] ?? '')) !== '' ? 1 : 0);

        $signals = [
            'platform_links' => count($cta_links),
            'tags' => count($tags),
            'additional_keywords' => count(is_array($pack['additional'] ?? null) ? $pack['additional'] : []),
            'faq_items' => count(is_array($faq_items) ? $faq_items : []),
            'active_platforms' => count($active_platforms),
            'comparison_copy' => ($comparison_copy !== '' ? 1 : 0),
            'editor_seed_facts' => $seed_fact_count,
        ];
        $specific_fact_count =
            min(3, $signals['platform_links']) +
            min(2, $signals['active_platforms']) +
            min(2, $signals['tags']) +
            min(1, $signals['comparison_copy']) +
            min(1, $signals['faq_items']) +
            min(3, $signals['editor_seed_facts']);

        $confidence = ($signals['platform_links'] * 28.0) + ($signals['tags'] * 10.0) + ($signals['additional_keywords'] * 4.0) + ($signals['comparison_copy'] * 8.0) + ($signals['editor_seed_facts'] * 9.0);
        $is_sufficient =
            $signals['platform_links'] >= 1
            && $signals['active_platforms'] >= 1
            && $specific_fact_count >= 4
            && ($signals['tags'] >= 1 || $signals['comparison_copy'] >= 1 || $signals['active_platforms'] >= 2);

        return [
            'is_sufficient' => $is_sufficient,
            'confidence' => min(100.0, $confidence),
            'signals' => $signals,
            'reason' => $is_sufficient ? 'sufficient_performer_data' : 'insufficient_performer_data',
        ];
    }

    /**
     * @param array<string,mixed> $gate
     * @return array<string,mixed>
     */
    public static function build_sparse_model_payload(string $name, array $active_platforms, array $gate, array $rankmath_additional = [], array $extra = []): array {
        $platform_text = self::format_platform_list($active_platforms, 'available platforms');
        $secondary_visible_phrases = self::select_visible_secondary_keyword_phrases($rankmath_additional, $extra);
        $secondary_heading_slots = self::build_secondary_heading_slots(self::select_heading_safe_secondary_keyword_phrases($name, $rankmath_additional, $extra));
        $reason = (string) ($gate['reason'] ?? 'insufficient_performer_data');
        $signals = is_array($gate['signals'] ?? null) ? $gate['signals'] : [];
        $active_platform_count = count($active_platforms);
        $has_meaningful_structure = ((int) ($signals['platform_links'] ?? 0) >= 1)
            && (
                (int) ($signals['active_platforms'] ?? 0) >= 1
                || (int) ($signals['comparison_copy'] ?? 0) >= 1
                || (int) ($signals['tags'] ?? 0) >= 1
            );

        if ($active_platform_count === 1) {
            $intro_first = 'In this review pass, ' . $platform_text . ' was the only live-room destination confirmed active. Start there first for room entry.';
            $comparison_lines = [
                'Use this checklist before you join: verify handle match, confirm recent room activity, check chat readability, and confirm mobile playback stability.',
            ];
        } elseif ($active_platform_count >= 2) {
            $intro_first = $name . ' currently has verified active live-room destinations on ' . $platform_text . '.';
            $comparison_lines = [
                'If multiple platforms are active, start with your familiar platform and compare load speed, chat controls, and privacy settings before choosing where to watch.',
            ];
        } else {
            $intro_first = 'Verified destinations exist, but no live-room destination is currently confirmed active in this review snapshot.';
            $comparison_lines = [
                'When live-room status is unclear, verify the handle first and use official destinations for follow and backup access until activity updates.',
            ];
        }

        $faq_items = $has_meaningful_structure
            ? [
                [
                    'q' => 'Which link should I open first?',
                    'a' => 'Open an active live-room destination first. If room status changes, use verified non-live destinations for follow, backup, and handle verification.',
                ],
                [
                    'q' => 'What does non-active mean on this page?',
                    'a' => 'It means the destination is verified but not currently treated as an active room entry. It can still be useful for profile checks, support, or backup navigation.',
                ],
                [
                    'q' => 'Why are fan pages not in the live section?',
                    'a' => 'Fan pages and support platforms are kept separate so live-room routing stays truthful. They are verified destinations, but not direct room-entry links.',
                ],
                [
                    'q' => 'Why should I recheck status before joining?',
                    'a' => 'Room availability can change quickly. A fast recheck helps you avoid stale links and land on the right destination.',
                ],
            ]
            : [
                [
                    'q' => 'Why is this page short right now?',
                    'a' => 'It is short because only verified details are published here first. Deeper profile sections are added when performer-specific data is strong enough to trust.',
                ],
                [
                    'q' => 'What is already verified on this page?',
                    'a' => 'Verified links and active platform availability are confirmed here first. Personality and style claims stay out until reliable performer-specific signals are available.',
                ],
            ];

        return [
            'intro_paragraphs' => [
                $intro_first,
                'Use verified destinations in priority order: live-room entry first, then official non-live links for backup and profile checks.'
                    . (!empty($secondary_visible_phrases[0]) ? ' This is especially useful when you are researching ' . $secondary_visible_phrases[0] . '.' : ''),
                'Status can change between visits, so recheck activity right before joining.',
            ],
            'about_section_paragraphs' => [],
            'fans_like_section_paragraphs' => [],
            'features_section_paragraphs' => [
                'Platform notes below describe platform-level features only, not confirmed performer-specific traits.'
                    . (!empty($secondary_visible_phrases[1]) ? ' Keep ' . $secondary_visible_phrases[1] . ' comparisons anchored to verified platform behavior.' : ''),
            ],
            'comparison_section_paragraphs' => $comparison_lines,
            'questions_section_paragraphs' => [],
            'faq_items' => self::inject_sparse_secondary_keyword_into_faq($faq_items, $secondary_visible_phrases[2] ?? ''),
            'model_data_notice' => $reason,
            'secondary_heading_slots' => $secondary_heading_slots,
        ];
    }

    /**
     * Deterministically choose up to four secondary keyword phrases for visible prose.
     *
     * @param string[] $rankmath_additional
     * @param string[] $extra
     * @return string[]
     */
    private static function select_visible_secondary_keyword_phrases(array $rankmath_additional, array $extra): array {
        $combined = array_merge($rankmath_additional, $extra);
        $selected = [];
        $seen = [];
        foreach ($combined as $phrase) {
            $phrase = trim((string) $phrase);
            if ($phrase === '') {
                continue;
            }
            $phrase = (string) preg_replace('/\s+/u', ' ', $phrase);
            $key = function_exists('mb_strtolower') ? mb_strtolower($phrase, 'UTF-8') : strtolower($phrase);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $selected[] = $phrase;
            if (count($selected) >= 4) {
                break;
            }
        }

        return $selected;
    }

    /**
     * Deterministically build heading plans for secondary keyword phrases.
     *
     * @param string[] $rankmath_additional
     * @param string[] $extra
     * @return array<int,array{keyword:string,normalized:string,slot:string,status:string,reason:string}>
     */
    private static function select_heading_safe_secondary_keyword_phrases(string $name, array $rankmath_additional, array $extra): array {
        $combined = array_merge($rankmath_additional, $extra);
        $selected = [];
        $seen = [];
        $slot_counters = [
            'features' => 0,
            'official_links' => 0,
            'comparison' => 0,
            'faq' => 0,
        ];

        foreach ($combined as $phrase) {
            $candidate = trim((string) $phrase);
            if ($candidate === '') {
                continue;
            }
            $candidate = (string) preg_replace('/\s+/u', ' ', $candidate);
            $candidate_lower = function_exists('mb_strtolower') ? mb_strtolower($candidate, 'UTF-8') : strtolower($candidate);
            if ($candidate_lower === '' || isset($seen[$candidate_lower])) {
                continue;
            }
            $seen[$candidate_lower] = true;

            $normalized = self::normalize_secondary_heading_phrase($candidate, $name);
            if ($normalized === '') {
                $selected[] = [
                    'keyword' => $candidate,
                    'normalized' => '',
                    'slot' => 'unusable',
                    'status' => 'unusable',
                    'reason' => 'unsafe_or_unreadable',
                ];
                continue;
            }

            $slot = self::resolve_secondary_heading_slot($normalized);
            if ($slot_counters[$slot] >= 2) {
                $slot = self::next_available_secondary_slot($slot_counters);
            }
            $slot_counters[$slot]++;

            $selected[] = [
                'keyword' => $candidate,
                'normalized' => $normalized,
                'slot' => $slot,
                'status' => 'placed',
                'reason' => '',
            ];
        }

        return $selected;
    }

    private static function is_heading_safe_secondary_phrase(string $phrase, string $phrase_lower, string $name_lower): bool {
        $word_count = preg_match_all('/[\p{L}\p{N}]+/u', $phrase);
        if ($word_count === false || $word_count < 2 || $word_count > 10) {
            return false;
        }
        if (mb_strlen($phrase, 'UTF-8') > 72) {
            return false;
        }
        if (preg_match('/[,;:|\/]/u', $phrase)) {
            return false;
        }
        if (preg_match('/\b(and|or|with|for|the|a|an)\b(?:\s+\1){1,}/iu', $phrase)) {
            return false;
        }
        if (preg_match('/\b(?:free|cheap|best|top|ultimate|instant|no\s+1|guaranteed|xxx|porn|sex)\b/iu', $phrase_lower)) {
            return false;
        }
        if ($name_lower !== '' && preg_match('/\b' . preg_quote($name_lower, '/') . '\b/u', $phrase_lower)) {
            return false;
        }
        return true;
    }

    private static function normalize_secondary_heading_phrase(string $phrase, string $name): string {
        $candidate = self::cleanup_visible_text($phrase, $name, true);
        $candidate = (string) preg_replace('/\s+/u', ' ', trim($candidate));
        if ($candidate === '') {
            return '';
        }

        $candidate = (string) preg_replace('/\b(today|now|instantly)\b/iu', '', $candidate);
        $candidate = (string) preg_replace('/\s+/u', ' ', trim($candidate));
        if ($candidate === '') {
            return '';
        }

        $candidate_lower = function_exists('mb_strtolower') ? mb_strtolower($candidate, 'UTF-8') : strtolower($candidate);
        $name_lower = function_exists('mb_strtolower') ? mb_strtolower(trim($name), 'UTF-8') : strtolower(trim($name));

        if (!self::is_heading_safe_secondary_phrase($candidate, $candidate_lower, $name_lower)) {
            return '';
        }

        return $candidate;
    }

    /**
     * @param array<string,int> $slot_counters
     */
    private static function next_available_secondary_slot(array $slot_counters): string {
        $slot_order = ['features', 'official_links', 'comparison', 'faq'];
        foreach ($slot_order as $slot) {
            if (($slot_counters[$slot] ?? 0) < 2) {
                return $slot;
            }
        }
        return 'faq';
    }

    /**
     * @param array<int,array{keyword:string,normalized:string,slot:string,status:string,reason:string}> $phrases
     * @return array<string,mixed>
     */
    private static function build_secondary_heading_slots(array $phrases): array {
        $slots = [];
        $unusable = [];
        foreach ($phrases as $phrase) {
            if (!is_array($phrase)) {
                continue;
            }
            $status = (string) ($phrase['status'] ?? '');
            $slot = (string) ($phrase['slot'] ?? '');
            $normalized = trim((string) ($phrase['normalized'] ?? ''));
            $keyword = trim((string) ($phrase['keyword'] ?? ''));

            if ($status !== 'placed' || $slot === '' || $normalized === '') {
                if ($keyword !== '') {
                    $unusable[] = $keyword;
                }
                continue;
            }
            if (!isset($slots[$slot])) {
                $slots[$slot] = [];
            }
            if (!is_array($slots[$slot])) {
                $slots[$slot] = [(string) $slots[$slot]];
            }
            if (!in_array($normalized, $slots[$slot], true)) {
                $slots[$slot][] = $normalized;
            }
        }

        if (!empty($unusable)) {
            $slots['unusable'] = array_values(array_unique($unusable));
        }

        return $slots;
    }

    /**
     * @param array<string,mixed> $slots
     * @return array<string,mixed>
     */
    private static function normalize_secondary_heading_slot_payload(array $slots): array {
        $normalized = [];
        foreach ($slots as $slot => $phrases) {
            if (!is_string($slot)) {
                continue;
            }
            if (is_string($phrases)) {
                $phrase = trim($phrases);
                if ($phrase !== '') {
                    $normalized[$slot] = [$phrase];
                }
                continue;
            }
            if (!is_array($phrases)) {
                continue;
            }
            $clean = array_values(array_filter(array_map('strval', $phrases), 'strlen'));
            if (!empty($clean)) {
                $normalized[$slot] = $clean;
            }
        }

        return $normalized;
    }

    private static function resolve_secondary_heading_slot(string $phrase): string {
        if (preg_match('/\b(profile|links?|verified|official|backup|access)\b/iu', $phrase)) {
            return 'official_links';
        }
        if (preg_match('/\b(schedule|status|updates?|stream)\b/iu', $phrase)) {
            return 'comparison';
        }

        return 'features';
    }

    /**
     * @param array<int,array<string,string>> $faq_items
     * @return array<int,array<string,string>>
     */
    private static function inject_sparse_secondary_keyword_into_faq(array $faq_items, string $keyword_phrase): array {
        $keyword_phrase = trim($keyword_phrase);
        if ($keyword_phrase === '' || empty($faq_items)) {
            return $faq_items;
        }

        foreach ($faq_items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $answer = trim((string) ($item['a'] ?? ''));
            if ($answer === '') {
                continue;
            }
            $faq_items[$index]['a'] = $answer . ' That includes quick checks for ' . $keyword_phrase . ' using verified destinations only.';
            break;
        }

        return $faq_items;
    }

    /**
     * @param array<string,mixed> $pack
     * @return array<string,mixed>
     */
    public static function hydrate_model_keyword_pack(\WP_Post $post, array $pack): array {
        $pack['editor_seed'] = self::get_editor_seed_data((int) $post->ID);
        return $pack;
    }

    public static function build_editor_seed_prompt_block(array $editor_seed, array $resolved_destinations = []): string {
        if (empty($editor_seed)) {
            return "Editor seed facts: none provided.\n";
        }
        $lines = [ "EDITOR SEED (authoritative, highest trust)" ];
        $summary = trim((string)($editor_seed['summary'] ?? ''));
        if ($summary !== '') {
            $lines[] = '- Summary: ' . $summary;
        }
        $tags = isset($editor_seed['known_for_tags']) && is_array($editor_seed['known_for_tags']) ? $editor_seed['known_for_tags'] : [];
        if (!empty($tags)) {
            $lines[] = '- Known-for tags: ' . implode(', ', array_slice(array_map('strval', $tags), 0, 6));
        }
        $notes = isset($editor_seed['platform_notes']) && is_array($editor_seed['platform_notes']) ? $editor_seed['platform_notes'] : [];
        if (!empty($notes)) {
            $lines[] = '- Platform notes: ' . implode(' | ', array_slice(array_map('strval', $notes), 0, 6));
        }
        $facts = isset($editor_seed['confirmed_facts']) && is_array($editor_seed['confirmed_facts']) ? $editor_seed['confirmed_facts'] : [];
        if (!empty($facts)) {
            $lines[] = '- Confirmed facts: ' . implode(' | ', array_slice(array_map('strval', $facts), 0, 6));
        }
        if (!empty($resolved_destinations)) {
            $summary = (array) ($resolved_destinations['source_of_truth_summary'] ?? []);
            $watch_count = (int) ($summary['watch_cta_count'] ?? 0);
            $verified_count = (int) ($summary['verified_count'] ?? 0);
            $verified_active = (int) ($summary['verified_active_count'] ?? 0);
            $verified_inactive = (int) ($summary['verified_inactive_or_unknown_count'] ?? 0);
            $lines[] = '- Verified destination summary: ' . $watch_count . ' active watch/live CTA destination(s), ' . $verified_count . ' verified external destination(s) total (' . $verified_active . ' active, ' . $verified_inactive . ' inactive/unknown).';
            $activity_notes = [];
            foreach (array_slice((array) ($resolved_destinations['all_verified_destinations'] ?? []), 0, 8) as $dest) {
                if (!is_array($dest)) { continue; }
                $note = trim((string)($dest['activity_note'] ?? ''));
                if ($note === '') { continue; }
                $activity_notes[] = trim((string)($dest['label'] ?? 'link')) . ': ' . $note;
            }
            if (!empty($activity_notes)) {
                $lines[] = '- Operator activity notes: ' . implode(' | ', array_slice($activity_notes, 0, 5));
            }
        }
        $avoid = isset($editor_seed['avoid_claims']) && is_array($editor_seed['avoid_claims']) ? $editor_seed['avoid_claims'] : [];
        if (!empty($avoid)) {
            $lines[] = '- Claims to avoid / unknowns: ' . implode(' | ', array_slice(array_map('strval', $avoid), 0, 6));
        }
        $tone = trim((string)($editor_seed['tone_hint'] ?? ''));
        if ($tone !== '') {
            $lines[] = '- Tone hint: ' . $tone;
        }
        $lines[] = '- Safety rule: do not present any claim as true unless it appears in editor seed, reviewed research, or verified platform/link data.';
        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_editor_seed_data(int $post_id): array {
        $summary = trim((string) get_post_meta($post_id, '_tmwseo_editor_seed_summary', true));
        $tags_csv = (string) get_post_meta($post_id, '_tmwseo_editor_seed_tags', true);
        $known_for_tags = array_values(array_filter(array_map('trim', explode(',', $tags_csv)), 'strlen'));
        $platform_notes = self::normalize_multiline_list((string) get_post_meta($post_id, '_tmwseo_editor_seed_platform_notes', true));
        $confirmed_facts = self::normalize_multiline_list((string) get_post_meta($post_id, '_tmwseo_editor_seed_confirmed_facts', true));
        $avoid_claims = self::normalize_multiline_list((string) get_post_meta($post_id, '_tmwseo_editor_seed_avoid_claims', true));
        $tone_hint = trim((string) get_post_meta($post_id, '_tmwseo_editor_seed_tone_hint', true));

        return [
            'summary' => $summary,
            'known_for_tags' => $known_for_tags,
            'platform_notes' => $platform_notes,
            'confirmed_facts' => $confirmed_facts,
            'avoid_claims' => $avoid_claims,
            'tone_hint' => $tone_hint,
            'is_populated' => ($summary !== '' || !empty($known_for_tags) || !empty($platform_notes) || !empty($confirmed_facts)),
        ];
    }

    /** @return string[] */
    private static function normalize_multiline_list(string $raw): array {
        if (trim($raw) === '') {
            return [];
        }
        $parts = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $parts = array_map(static fn($v) => trim((string) $v), $parts);
        return array_values(array_filter($parts, 'strlen'));
    }

    /**
     * Render a natural "elsewhere online" section using editor-curated verified links.
     * These links are saved on the post and must not depend on research ingestion.
     */
    /**
     * @param array<int,array{platform:string,label:string,url:string}> $exclude_targets
     */
    private static function render_curated_verified_links_section(int $post_id, string $name, array $exclude_targets = [], array $resolved_destinations = []): string {
        if (empty($resolved_destinations)) {
            $resolved_destinations = ModelDestinationResolver::resolve($post_id);
        }

        $type_labels = VerifiedLinksFamilies::type_labels();
        $family_labels = [
            VerifiedLinksFamilies::FAMILY_CAM => 'Cam platform profiles',
            VerifiedLinksFamilies::FAMILY_PERSONAL => 'Official and personal sites',
            VerifiedLinksFamilies::FAMILY_FANSITE => 'Fan pages',
            VerifiedLinksFamilies::FAMILY_SOCIAL => 'Social profiles',
            VerifiedLinksFamilies::FAMILY_LINK_HUB => 'More Links',
            VerifiedLinksFamilies::FAMILY_TUBE => 'Video channels',
            VerifiedLinksFamilies::FAMILY_UNMAPPED => 'Elsewhere online',
        ];

        $exclude_urls = [];
        foreach ($exclude_targets as $target) {
            $url = strtolower(rtrim(trim((string) ($target['url'] ?? '')), '/'));
            if ($url !== '') {
                $exclude_urls[$url] = true;
            }
        }

        $grouped = [];
        $seen = [];
        foreach ((array) ($resolved_destinations['all_verified_destinations'] ?? []) as $entry) {
            if (!is_array($entry)) { continue; }
            $url = trim((string)($entry['routed_url'] ?? $entry['url'] ?? ''));
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) { continue; }
            $url_key = strtolower(rtrim($url, '/'));
            if (isset($seen[$url_key])) { continue; }
            $seen[$url_key] = true;
            $type = sanitize_key((string)($entry['type'] ?? 'other'));
            $family = VerifiedLinksFamilies::family_for($type);
            if ($family === VerifiedLinksFamilies::FAMILY_CAM && isset($exclude_urls[$url_key])) { continue; }
            $label = trim((string)($entry['label'] ?? ''));
            if ($label === '') { $label = (string) ($type_labels[$type] ?? ucfirst(str_replace('_', ' ', $type))); }
            $activity_note = trim((string)($entry['activity_note'] ?? ''));
            if ($activity_note !== '') {
                $label .= ' — ' . $activity_note;
            }
            $grouped[$family][] = [
                'label' => $label,
                'url' => $url,
                'family' => $family,
                'activity_level' => sanitize_key((string) ($entry['activity_level'] ?? 'unknown')),
            ];
        }

        if (empty($grouped)) {
            return '';
        }

        $chunks = [];
        foreach (VerifiedLinksFamilies::display_order() as $family) {
            $rows = $grouped[$family] ?? [];
            if (empty($rows)) {
                continue;
            }

            $rows = self::disambiguate_curated_link_labels($rows);

            $items = '';
            foreach ($rows as $row) {
                $anchor_text = self::build_family_specific_cta_text($row);
                if ($anchor_text === '') {
                    continue;
                }
                $items .= '<li><a href="' . esc_url((string) $row['url']) . '" target="_blank" rel="noopener external nofollow">' . esc_html($anchor_text) . '</a></li>';
            }
            if ($items === '') {
                continue;
            }

            $family_heading = (string) ($family_labels[$family] ?? 'Elsewhere online');
            $chunks[] = '<h3>' . esc_html($family_heading) . '</h3><ul>' . $items . '</ul>';
        }

        if (empty($chunks)) {
            return '';
        }

        return '<h3>' . esc_html('Find ' . $name . ' elsewhere') . '</h3>'
            . '<p>' . esc_html('Verified destinations grouped by platform family so each link reflects its real purpose.') . '</p>'
            . implode('', $chunks);
    }

    /**
     * @param array{label?:string,family?:string,activity_level?:string} $row
     */
    private static function build_family_specific_cta_text(array $row): string {
        $label = trim((string) ($row['label'] ?? ''));
        if ($label === '') {
            return '';
        }
        $family = sanitize_key((string) ($row['family'] ?? VerifiedLinksFamilies::FAMILY_UNMAPPED));
        $activity = sanitize_key((string) ($row['activity_level'] ?? 'unknown'));

        if ($family === VerifiedLinksFamilies::FAMILY_CAM) {
            if (in_array($activity, ['active', 'very_active'], true)) {
                return 'Watch Live on ' . $label;
            }
            return 'Visit Profile on ' . $label;
        }
        if ($family === VerifiedLinksFamilies::FAMILY_FANSITE) {
            return 'Visit Fan Page on ' . $label;
        }
        if ($family === VerifiedLinksFamilies::FAMILY_PERSONAL) {
            return 'Visit Official Site on ' . $label;
        }
        if ($family === VerifiedLinksFamilies::FAMILY_SOCIAL) {
            return 'Follow on ' . $label;
        }
        if ($family === VerifiedLinksFamilies::FAMILY_LINK_HUB) {
            return 'Open Link Hub on ' . $label;
        }
        if ($family === VerifiedLinksFamilies::FAMILY_TUBE) {
            return 'Visit Channel on ' . $label;
        }

        return 'Open Profile: ' . $label;
    }

    /**
     * @param array<int,array{label:string,url:string,family?:string,activity_level?:string}> $rows
     * @return array<int,array{label:string,url:string,family?:string,activity_level?:string}>
     */
    private static function disambiguate_curated_link_labels(array $rows): array {
        $counts = [];
        foreach ($rows as $row) {
            $label = trim((string)($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $key = mb_strtolower($label, 'UTF-8');
            $counts[$key] = (int)($counts[$key] ?? 0) + 1;
        }

        foreach ($rows as $idx => $row) {
            $label = trim((string)($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $key = mb_strtolower($label, 'UTF-8');
            if (($counts[$key] ?? 0) < 2) {
                continue;
            }

            $host = (string)wp_parse_url((string)($row['url'] ?? ''), PHP_URL_HOST);
            $host = strtolower(preg_replace('/^www\./', '', $host) ?? '');
            if ($host === '') {
                continue;
            }

            $rows[$idx]['label'] = $label . ' (' . $host . ')';
        }

        return $rows;
    }

    /**
     * @param array<int,array{platform:string,label:string,go_url:string,is_primary:bool,username:string}> $cta_links
     */
    private static function build_official_links_summary(string $name, array $cta_links, int $post_id, array $resolved_destinations = []): string {
        $types = [];
        if (!empty($cta_links)) {
            $types[] = 'active live cam platforms';
        }
        $resolved = !empty($resolved_destinations) ? $resolved_destinations : ModelDestinationResolver::resolve($post_id);
        if (!empty($resolved['personal_site_destinations'])) { $types[] = 'official and personal sites'; }
        if (!empty($resolved['fan_platform_destinations'])) { $types[] = 'fan pages'; }
        if (!empty($resolved['tube_destinations'])) { $types[] = 'video channels'; }
        if (!empty($resolved['social_destinations'])) { $types[] = 'social profiles'; }
        if (!empty($resolved['link_hub_destinations'])) { $types[] = 'link hubs'; }
        if (!empty($resolved['source_of_truth_summary']['seed_platform_notes'])) {
            $types[] = 'editor platform notes';
        }
        $types = array_values(array_unique($types));
        if (empty($types)) {
            return 'This section lists verified destinations for ' . $name . ' so visitors can open accurate profile links quickly.';
        }
        return 'This section lists verified destinations for ' . $name . ', including ' . self::format_platform_list($types, 'verified sources') . '.';
    }

    /**
     * @param array<int,string> $active_platforms
     * @param array<int,string> $tags
     * @param array<int,array<string,mixed>> $cta_links
     */
    private static function has_specific_supporting_data(string $name, string $text, array $active_platforms, array $tags, array $cta_links): bool {
        $haystack = mb_strtolower($text, 'UTF-8');
        $needles = [mb_strtolower($name, 'UTF-8')];

        foreach (array_slice($active_platforms, 0, 3) as $platform) {
            $platform = trim((string)$platform);
            if ($platform !== '') {
                $needles[] = mb_strtolower($platform, 'UTF-8');
            }
        }
        foreach (array_slice($tags, 0, 3) as $tag) {
            $tag = trim(str_replace('-', ' ', (string)$tag));
            if ($tag !== '') {
                $needles[] = mb_strtolower($tag, 'UTF-8');
            }
        }
        foreach (array_slice($cta_links, 0, 3) as $row) {
            $username = trim((string)($row['username'] ?? ''));
            if ($username !== '') {
                $needles[] = mb_strtolower($username, 'UTF-8');
            }
        }

        $matches = 0;
        foreach (array_unique($needles) as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                $matches++;
            }
        }

        return $matches >= 2;
    }

    /** @return string[] */
    private static function build_seed_intro_paragraphs(string $name, array $editor_seed, array $active_platforms, string $fallback_intro, string $fallback_second): array {
        $summary = trim((string) ($editor_seed['summary'] ?? ''));
        $active_platform_count = count($active_platforms);
        if ($active_platform_count === 1) {
            $platform_text = self::format_platform_list($active_platforms, 'the active platform');
            $answer_line = 'In this review pass, ' . $platform_text . ' was the only live-room destination confirmed active. Start there first, then use verified non-live destinations for backup and profile checks.';
        } elseif ($active_platform_count > 1) {
            $platform_text = self::format_platform_list($active_platforms, 'verified live platforms');
            $answer_line = 'This review currently confirms active live-room destinations on ' . $platform_text . '. Open those links first, then use other sections for follow and backup access.';
        } else {
            $answer_line = 'Verified destinations exist, but no live-room entry is currently confirmed active in this review snapshot.';
        }
        if ($summary === '') {
            return [
                $answer_line,
                $fallback_intro,
                $fallback_second,
                'Before you commit to one room, run a quick check: username match, recent room activity, chat readability, and mobile playback stability.',
                'Use the live section for room entry, then use non-live verified destinations for schedule checks, backup access, and handle verification.',
            ];
        }
        return [
            $answer_line,
            $summary,
            'Use this page as a routing layer: live destinations for room entry first, then official non-live destinations for follow, support, or verification tasks.',
            'When activity changes, keep the same workflow: verify handle match, compare room quality, and use backup destinations instead of copied mirrors.',
        ];
    }

    /** @return string[] */
    private static function build_seed_about_paragraphs(array $editor_seed, string $name): array {
        $summary = trim((string) ($editor_seed['summary'] ?? ''));
        $facts = isset($editor_seed['confirmed_facts']) && is_array($editor_seed['confirmed_facts']) ? $editor_seed['confirmed_facts'] : [];
        $paragraphs = [];
        if ($summary !== '') {
            $paragraphs[] = $summary;
        }
        if (!empty($facts)) {
            $paragraphs[] = 'Confirmed details: ' . implode(' ', array_slice(array_map(static fn($f) => trim((string) $f) . '.', $facts), 0, 3));
        }
        return array_values(array_filter(array_map(static fn($p) => self::cleanup_visible_text((string) $p, $name, false), $paragraphs), 'strlen'));
    }

    /** @return string[] */
    private static function build_seed_comparison_paragraphs(array $editor_seed, string $fallback_copy): array {
        $notes = isset($editor_seed['platform_notes']) && is_array($editor_seed['platform_notes']) ? $editor_seed['platform_notes'] : [];
        if (!empty($notes)) {
            $base = array_slice(array_values(array_filter(array_map('strval', $notes), 'strlen')), 0, 3);
            $base[] = 'Compare platforms with one repeatable method: room uptime, mobile playback, chat readability, moderation tone, and account/login friction.';
            $base[] = 'Keep the same checklist each visit so platform choice stays evidence-based even when a room goes inactive later.';
            return $base;
        }
        if ($fallback_copy === '') {
            return [];
        }
        return [
            $fallback_copy,
            'Run the same one-minute test on each active room so platform choice is based on practical use rather than brand familiarity.',
            'If one room is inactive later, use verified backup destinations and recheck status instead of relying on scraped mirror pages.',
            'Fair comparison starts with equal conditions: same device, similar time window, and the same trust checks before spending.',
        ];
    }

    /**
     * @param array<int,array{q:string,a:string}> $fallback
     * @return array<int,array{q:string,a:string}>
     */
    private static function build_seed_faq_items(array $editor_seed, array $fallback, string $name): array {
        $facts = isset($editor_seed['confirmed_facts']) && is_array($editor_seed['confirmed_facts']) ? $editor_seed['confirmed_facts'] : [];
        $avoid = isset($editor_seed['avoid_claims']) && is_array($editor_seed['avoid_claims']) ? $editor_seed['avoid_claims'] : [];
        $items = [];
        if (!empty($facts)) {
            $items[] = [
                'q' => 'What details are currently confirmed?',
                'a' => 'Confirmed details for this model are: ' . implode('; ', array_slice(array_map('strval', $facts), 0, 3)) . '.',
            ];
        }
        if (!empty($avoid)) {
            $items[] = [
                'q' => 'Are there details still unconfirmed?',
                'a' => 'Yes. The following claims are treated as unconfirmed and are intentionally not presented as facts: ' . implode('; ', array_slice(array_map('strval', $avoid), 0, 2)) . '.',
            ];
        }
        $utility = self::default_utility_faq_items();
        $merged = array_merge($items, $fallback, $utility);
        $seen_questions = [];
        $deduped = [];
        foreach ($merged as $item) {
            if (!is_array($item)) {
                continue;
            }
            $q = trim((string) ($item['q'] ?? ''));
            $a = trim((string) ($item['a'] ?? ''));
            if ($q === '' || $a === '') {
                continue;
            }
            $fingerprint = strtolower(preg_replace('/[^a-z0-9]+/', ' ', $q) ?? $q);
            if (isset($seen_questions[$fingerprint])) {
                continue;
            }
            $seen_questions[$fingerprint] = true;
            $deduped[] = ['q' => $q, 'a' => $a];
        }
        $merged = $deduped;
        $merged = array_slice($merged, 0, 5);
        foreach ($merged as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }
            $answer = self::normalize_faq_answer((string) ($item['a'] ?? ''));
            $merged[$idx]['a'] = self::limit_exact_name_mentions($answer, $name, 1);
        }
        return $merged;
    }

    /**
     * @return array<int,array{q:string,a:string}>
     */
    private static function default_utility_faq_items(): array {
        return [
            [
                'q' => 'How should I compare two platforms without bias?',
                'a' => 'Use the same checklist on each room: uptime signals, mobile playback, chat readability, moderation tone, and login friction. Keep whichever room performs better for your setup.',
            ],
            [
                'q' => 'Why are some verified links outside the live section?',
                'a' => 'Because verification and live availability are different states. A destination can be official and still be better for follow/support access than direct room entry.',
            ],
            [
                'q' => 'What is the safest way to avoid copied profiles?',
                'a' => 'Start from verified links on this page, confirm the handle after click-through, and leave immediately when usernames or branding do not match.',
            ],
        ];
    }

    private static function normalize_faq_answer(string $answer): string {
        $answer = trim($answer);
        $answer = preg_replace('/\bIf you are checking\b/iu', '', $answer) ?: $answer;
        $answer = preg_replace('/\bA query like\b/iu', '', $answer) ?: $answer;
        $answer = preg_replace('/\bPeople looking for\b/iu', '', $answer) ?: $answer;
        $answer = preg_replace('/\bThis usually means\b/iu', '', $answer) ?: $answer;
        $answer = preg_replace('/\bPeople looking up\b/iu', '', $answer) ?: $answer;
        $answer = preg_replace('/\bSearches for\b/iu', '', $answer) ?: $answer;
        return trim((string) preg_replace('/\s+/', ' ', $answer));
    }

    private static function limit_exact_name_mentions(string $text, string $name, int $max_mentions): string {
        $name = trim($name);
        if ($name === '') {
            return $text;
        }
        $count = 0;
        return preg_replace_callback(
            '/\b' . preg_quote($name, '/') . '\b/iu',
            static function (array $m) use (&$count, $max_mentions): string {
                $count++;
                return $count <= $max_mentions ? $m[0] : 'this performer';
            },
            $text
        ) ?: $text;
    }

    /** @return string[] */
    private static function build_fans_like_paragraphs(array $context, string $name, array $model_data_gate = [], array $editor_seed = []): array {
        if (empty($model_data_gate['is_sufficient'])) {
            return [];
        }
        $seed_tags = isset($editor_seed['known_for_tags']) && is_array($editor_seed['known_for_tags'])
            ? array_values(array_filter(array_map('strval', $editor_seed['known_for_tags']), 'strlen'))
            : [];
        if (!empty($seed_tags)) {
            $lead = array_slice($seed_tags, 0, 3);
            return [
                'Editors consistently tag ' . $name . ' for ' . implode(', ', $lead) . ', so those are the most reliable themes to check first when you join.',
            ];
        }
        return [];
    }

    private static function render_rankmath_keyword_coverage(array $keywords, string $name): string {
        $keywords = array_values(array_filter(array_map('trim', $keywords), 'strlen'));
        if (empty($keywords)) {
            return '';
        }

        return '';
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

    private static function build_platform_comparison(\WP_Post $post, string $name, array $cta_links, string $comparison_copy, array $editor_seed = []): string {
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

        if (count($cta_links) === 1) {
            $single = $cta_links[0];
            $platform = trim((string) ($single['label'] ?? 'the active platform'));
            $url = trim((string) ($single['go_url'] ?? ''));
            $checklist = '<ul>'
                . '<li>Confirm the username shown on the platform matches the verified profile handle.</li>'
                . '<li>Check recent room activity markers before spending credits or tips.</li>'
                . '<li>Review payment and privacy controls before starting chat.</li>'
                . '</ul>';
            $cta = '';
            if ($url !== '') {
                $cta = '<p><a href="' . esc_url($url) . '" target="_blank" rel="sponsored noopener">Open ' . esc_html($platform) . ' profile</a></p>';
            }
            return $alt_username_note
                . '<p>' . esc_html('This review pass found one confirmed active live-room destination (' . $platform . '), so use this quick pre-click checklist before joining.') . '</p>'
                . $checklist
                . $cta;
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
                . '<td><a href="' . esc_url($url) . '" target="_blank" rel="' . esc_attr(!empty($link['is_primary']) ? 'sponsored noopener' : 'sponsored nofollow noopener') . '">Watch Live</a></td>'
                . '</tr>';
        }

        $table = '';
        if ($rows !== '') {
            $table = '<table><thead><tr><th>Platform</th><th>Profile</th><th>Link</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        }

        return $alt_username_note . $table;
    }

    /**
     * @param array<string,mixed> $resolved_destinations
     */
    private static function render_other_official_destinations_section(array $resolved_destinations): string {
        $rows = [];
        foreach ((array) ($resolved_destinations['all_verified_destinations'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $family = sanitize_key((string) ($entry['family'] ?? ''));
            if (!in_array($family, [VerifiedLinksFamilies::FAMILY_CAM, VerifiedLinksFamilies::FAMILY_FANSITE, VerifiedLinksFamilies::FAMILY_PERSONAL], true)) {
                continue;
            }
            if ($family === VerifiedLinksFamilies::FAMILY_CAM && !empty($entry['is_cta_eligible'])) {
                continue;
            }
            $url = trim((string) ($entry['routed_url'] ?? $entry['url'] ?? ''));
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }
            $rows[] = [
                'label' => (string) ($entry['label'] ?? ''),
                'url' => $url,
                'family' => $family,
                'activity_level' => (string) ($entry['activity_level'] ?? 'unknown'),
            ];
        }

        return self::render_truthful_destination_list($rows, true);
    }

    /**
     * @param array<string,mixed> $resolved_destinations
     */
    private static function render_social_channel_destinations_section(array $resolved_destinations): string {
        $rows = [];
        foreach ((array) ($resolved_destinations['all_verified_destinations'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $family = sanitize_key((string) ($entry['family'] ?? ''));
            if (!in_array($family, [VerifiedLinksFamilies::FAMILY_SOCIAL, VerifiedLinksFamilies::FAMILY_LINK_HUB, VerifiedLinksFamilies::FAMILY_TUBE], true)) {
                continue;
            }
            $url = trim((string) ($entry['routed_url'] ?? $entry['url'] ?? ''));
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }
            $rows[] = [
                'label' => (string) ($entry['label'] ?? ''),
                'url' => $url,
                'family' => $family,
                'activity_level' => (string) ($entry['activity_level'] ?? 'unknown'),
            ];
        }

        return self::render_truthful_destination_list($rows);
    }

    /**
     * @param array<int,array{label?:string,url?:string,family?:string,activity_level?:string}> $rows
     */
    private static function render_truthful_destination_list(array $rows, bool $include_non_active_note = false): string {
        if (empty($rows)) {
            return '';
        }

        $items = '';
        foreach (self::disambiguate_curated_link_labels($rows) as $row) {
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }
            $text = self::build_family_specific_cta_text($row);
            if ($text === '') {
                continue;
            }
            $items .= '<li><a href="' . esc_url($url) . '" target="_blank" rel="noopener external nofollow">' . esc_html($text) . '</a></li>';
        }

        if ($items === '') {
            return '';
        }

        $note = '';
        if ($include_non_active_note) {
            $note = '<p>' . esc_html('Where shown as non-active, the latest operator review marked that destination as not currently active.') . '</p>';
        }

        return $note . '<ul>' . $items . '</ul>';
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

            return '<p><a href="' . esc_url($go_url) . '" target="_blank" rel="sponsored noopener">' . esc_html('Watch Live on ' . $label) . '</a></p>';
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

            $items[] = '<li><a href="' . esc_url($url) . '" target="_blank" rel="sponsored nofollow noopener">' . esc_html('Watch Live on ' . $platform) . '</a></li>';

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
        $targets = self::build_guaranteed_external_platform_targets($links);
        return self::render_guaranteed_external_platform_links_from_targets($targets);
    }

    /**
     * @param array<int,array{platform:string,label:string,go_url:string,is_primary:bool,username:string}> $links
     * @return array<int,array{platform:string,label:string,url:string}>
     */
    private static function build_guaranteed_external_platform_targets(array $links): array {
        if (empty($links)) {
            return [];
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

        $targets = [];
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

            $targets[]       = [
                'platform' => $platform,
                'label' => $label,
                'url' => $external_url,
            ];
            $seen[$platform] = true;
            if (count($targets) >= 2) {
                break;
            }
        }

        return $targets;
    }

    /**
     * @param array<int,array{platform:string,label:string,url:string}> $targets
     */
    private static function render_guaranteed_external_platform_links_from_targets(array $targets): string {
        if (empty($targets)) {
            return '';
        }

        $items = [];
        foreach ($targets as $target) {
            $url   = trim((string) ($target['url'] ?? ''));
            $label = trim((string) ($target['label'] ?? ''));
            if ($url === '' || $label === '') {
                continue;
            }
            $items[] = '<li><a href="' . esc_url($url) . '" target="_blank" rel="noopener external">' . esc_html($label . ' profile') . '</a></li>';
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
                return 'A common question is ' . $kw . '. Start with confirmed profile links, then verify which room is currently live.';
            },
            static function (string $kw): string {
                return 'For this topic (' . $kw . '), compare load speed, alert reliability, and chat controls before settling on one platform.';
            },
            static function (string $kw): string {
                return 'The useful check for ' . $kw . ' is platform-level: stable playback, readable chat, and clear profile routing.';
            },
            static function (string $kw): string {
                return 'When this query appears (' . $kw . '), treat it as a prompt to confirm official links and active room status first.';
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
            'Direct profile access shortens the busywork. Instead of checking several aggregators, you can open active profiles and compare platforms quickly.',
            'The live format changes the experience more than it seems at first. A steady room with readable chat can turn a simple session into something people want to revisit.',
            'Most regular viewers end up caring about the small practical details: whether notifications are dependable, whether mobile playback behaves properly, and whether the room stays manageable once more people join.',
            $focus1 . ' matters because platform differences change how chat feels. Some viewers prefer quieter rooms, others want faster public conversation.',
            $focus2 . ' sounds broad, but it usually points to a simple expectation: a stream that feels active, not canned. That comes from pacing, quick reactions, and a room that does not ignore its own chat.',
            'Scheduling context can save time. ' . $longtail_hint . ' is most useful when it helps you avoid refreshing random profile pages.',
            $tags_text . ' themes help set expectations, but they do not lock every session into the same pattern. The better rooms leave space for mood changes and small detours.',
            'Privacy settings are not glamorous, but they matter. Good platforms make it easy to watch with a little distance, control account visibility, and keep payments separate from the rest of daily browsing.',
            'The best parts of live chat are usually the unscripted ones: a quick reply, a running joke, a shift in pace because the room steered it there.',
            'Comparing platforms is worth a minute or two, especially if you care about stream stability or private-room tools. Those differences do not sound exciting, but they shape the whole experience.',
            'HD video is nice, but it is not the only thing people notice. Clean audio, fast room loading, and moderation that keeps the chat readable do just as much for the overall feel.',
            'When the room has a clear rhythm, new viewers settle in faster. That is part of why consistent performers build repeat audiences even when plenty of other profiles are available.',
            'For viewers moving between ' . $platform_text . ', comparison is mostly about chat pace, controls, and how quickly you can settle into the room.',
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
                $focus_keyword . ' is easier to follow when active links and platform notes are in one place. It helps you compare options without extra searching.',
                'If ' . $focus_keyword . ' is the reason you are here, start with the active profiles and then use the platform notes to pick where to watch.',
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

<p>" . esc_html('For quick comparison, ' . $focus_keyword . ' is listed with active profiles so you can choose a platform without guesswork.') . '</p>';
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
            '<li><strong>Truth-first routing:</strong> Live-room links are limited to destinations currently confirmed active in this review; follow/support pages stay in separate sections.</li>',
            '<li><strong>Pre-click verification:</strong> Compare handle spelling, profile branding, and room freshness before spending credits or tips.</li>',
            '<li><strong>Fair platform testing:</strong> Run a one-minute check for playback stability, chat readability, moderation tone, and login friction.</li>',
            '<li><strong>Backup strategy:</strong> Keep one alternate verified destination ready in case your primary room is offline or geo-limited.</li>',
            '<li><strong>Status can change:</strong> Activity labels reflect a review snapshot, so rechecking before each session prevents stale clicks.</li>',
            '<li><strong>Identity safety:</strong> Avoid mirror listings and copied pages by starting from verified destinations on this page.</li>',
            '<li><strong>Decision clarity:</strong> Platform notes describe utility tradeoffs rather than performer-specific claims that are not verified.</li>',
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
        $content = preg_replace('/\bWatch\s+' . preg_quote($name, '/') . '\b/iu', 'Open the verified live destination', $content) ?: $content;
        $content = preg_replace('/\b' . preg_quote($name, '/') . '\s+is currently active on\b/iu', 'Current review status shows active access on', $content) ?: $content;
        $content = preg_replace('/\bthe profile\b/iu', 'this profile', $content) ?: $content;
        $content = str_replace('This guide covers the practical side:', 'This page focuses on the practical side:', $content);
        $content = str_replace('This guide covers exactly that need:', 'This section covers those basics directly:', $content);
        $content = str_replace('The appeal extends beyond any single session.', 'There is more here than one good session.', $content);
        $content = preg_replace('/\bVisitors searching for\b/iu', 'People coming for', $content) ?: $content;

        // Remove doubled words/phrases at word boundaries (e.g. "the the", "platform platform").
        $content = preg_replace('/\b([A-Za-z]+(?:\s+[A-Za-z]+){0,3})(\s+\1){1,}\b/u', '$1', $content) ?: $content;

        return $content;
    }

    private static function ensure_minimum_useful_depth(string $content, string $name, array $active_platforms, array $resolved_destinations, string $primary_platform_label, string $seed): string {
        $plain = trim((string) wp_strip_all_tags($content));
        $word_count = str_word_count($plain);
        if ($word_count >= 640) {
            return $content;
        }

        $platform_text = self::format_platform_list($active_platforms, $primary_platform_label !== '' ? $primary_platform_label : 'verified platforms');
        $extra_blocks = [
            '<h2>How to Decide Where to Start</h2>'
            . '<p>Start with the platform you already trust, then test one alternate room with the same checklist: uptime signals, chat readability, playback stability, moderation flow, and login friction. A repeatable method prevents brand bias and makes it easier to pick the better room for your device and connection.</p>'
            . '<p>If both rooms perform similarly, keep the one with clearer moderation and fewer account hurdles. If neither room works well, use the other verified destinations on this page to confirm handles and return later when status changes.</p>',
            '<h2>Verification and Review Method</h2>'
            . '<p>This page prioritizes verified destinations and manual review notes. Verification confirms ownership and routing quality; it does not guarantee continuous uptime. Activity labels represent a snapshot and can change after platform updates or schedule shifts.</p>'
            . '<p>For that reason, recheck status each time you visit. Starting from a verified destination is still the safest path to avoid copied pages, stale mirrors, or impersonation profiles.</p>',
            '<h2>Practical Use of Non-Live Destinations</h2>'
            . '<p>Non-live destinations remain useful even when they are not room-entry links. Use them for follow actions, backup profile validation, archived media, and link-hub navigation when the live section is temporarily inactive.</p>'
            . '<p>This separation keeps the page truthful: live access appears only in the live section, while other official destinations support planning and verification tasks.</p>',
            '<h2>How to Use Backup Destinations Safely</h2>'
            . '<p>When a preferred room is offline, move to a verified backup destination instead of random search results. Confirm handle spelling, brand cues, and profile history before clicking onward to any paid flow.</p>'
            . '<p>This approach reduces impersonation risk and keeps your routing consistent: trusted destination first, status check second, and spending decisions only after room quality is clear.</p>',
        ];

        $need = min(4, (int) ceil((640 - $word_count) / 110));
        $selected = [];
        for ($i = 0; $i < $need; $i++) {
            $idx = self::stable_pick_index($seed . '|depth|' . $i . '|' . $platform_text, count($extra_blocks));
            while (in_array($idx, $selected, true)) {
                $idx = ($idx + 1) % count($extra_blocks);
            }
            $selected[] = $idx;
            $content .= "\n\n" . $extra_blocks[$idx];
        }

        return $content;
    }

    private static function apply_lightweight_content_guardrails(string $content, string $name): string {
        $content = preg_replace('/<p>\s*Watch Live on\s+([^<]+)\.<\/p>/iu', '<p>Open the verified live room on $1.</p>', $content) ?: $content;
        $content = preg_replace('/\b' . preg_quote($name, '/') . '\s+' . preg_quote($name, '/') . '\b/iu', $name, $content) ?: $content;
        $content = preg_replace('/(<p>\s*Use this section\b[^<]*<\/p>)(\s*<p>\s*Use this section\b[^<]*<\/p>)+/iu', '$1', $content) ?: $content;
        return $content;
    }

    private static function build_verification_process_paragraph(array $resolved_destinations): string {
        $summary = (array) ($resolved_destinations['source_of_truth_summary'] ?? []);
        $verified_total = (int) ($summary['verified_count'] ?? 0);
        $active_live = (int) ($summary['watch_cta_count'] ?? 0);
        $active_label = $active_live === 1 ? 'live-room destination' : 'live-room destinations';
        return 'Verification notes: this page prioritizes checked destinations (' . $verified_total . ' verified links total, ' . $active_live . ' ' . $active_label . ' confirmed active in the latest review pass). Status can change, so recheck before each session.';
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
        $text = preg_replace('/\bthe profile\b/iu', 'this profile', $text) ?: $text;
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
