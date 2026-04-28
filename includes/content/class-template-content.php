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
        $bio_evidence = self::get_bio_evidence_data((int) $post->ID);

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
                'Start with the live-room button, then use the other listed profiles for updates or backup access.',
                'Open the active room first, then use the non-live profiles for status checks and follow-up.',
                'Use the direct room link first, and keep backup profiles nearby in case room status changes.',
                'Start with one live-room option, then check other profiles before you commit.',
                'Use the live-room link for entry, then cross-check the handle on listed non-live profiles if anything looks off.',
            ]
            : [
                'Start with the live-room button first, then use the comparison section to choose between active platforms.',
                'Use the room buttons first, then compare platforms to decide where to stay.',
                'Use the direct room buttons first; then compare active platforms to decide where to stay.',
                'Everything here is practical: real room access first, then platform choice notes.',
                'If you are deciding where to watch, open your familiar platform first, then check the second active room.',
            ];
        $second_intro = $second_intro_pool[self::stable_pick_index($seed . '|intro2', count($second_intro_pool))];
        if (!empty($secondary_visible_phrases[0])) {
            $second_intro .= ' Visitors looking for ' . $secondary_visible_phrases[0] . ' can use this guide to start with the confirmed room and compare listed profiles quickly.';
        }

        $watch_para_pool = [
            'Open the confirmed live profile below. Fan, social, and link-hub profiles are listed separately.',
            'Choose a live platform below first. Use the other profile groups for follow-up and support.',
            'Open a live profile first, then use other listed profiles if you need backups or updates.',
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
            ? 'Check this section to see which platform matches your speed, trust, and mobile needs.'
            : 'Features listed here cover platform access checks only, not unverified performer-specific traits.';
        $intro_paragraphs = self::build_seed_intro_paragraphs($name, $editor_seed, $active_platforms, $intro, $second_intro);

        // ── Reviewed bio evidence layer ──────────────────────────────────────
        // Only inject when bio_review_status === 'reviewed' and bio_summary is
        // populated. Never use model-bios.php template output as an intro bio.
        if ($bio_evidence['is_reviewable']) {
            $reviewed_bio_text = self::cleanup_visible_text($bio_evidence['summary'], $name, false);
            if ($reviewed_bio_text !== '') {
                // Place bio as the second paragraph (after the direct-answer line).
                $first_para = array_shift($intro_paragraphs) ?? '';
                $intro_paragraphs = array_merge(
                    $first_para !== '' ? [$first_para] : [],
                    [$reviewed_bio_text],
                    $intro_paragraphs
                );
            }
        }
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
            // about_section: use seed_about if available; do NOT fall back to the
            // generic model-bios.php template ($bio) — that text is not a real bio.
            // Reviewed performer bios are injected into intro_paragraphs above.
            'about_section_paragraphs' => $has_specific_about ? (!empty($seed_about) ? $seed_about : []) : [],
            'fans_like_section_paragraphs' => self::build_fans_like_paragraphs($context, $name, $model_data_gate, $editor_seed),
            'features_section_paragraphs' => [
                $features_intro
                . ' Focus on room freshness, handle consistency, playback quality, chat readability, and payment/privacy controls before joining.'
                . (!empty($secondary_visible_phrases[1]) ? ' For ' . $secondary_visible_phrases[1] . ' comparisons, focus on chat usability and room quality on your device.' : ''),
            ],
            'features_section_html' => self::join_html_blocks([
                self::render_varied_features($name, $tags, $primary_platform_label, $seed, count($active_platforms)),
                $keyword_coverage_html,
            ]),
            'comparison_section_paragraphs' => $comparison_paragraphs,
            'faq_items' => $faq_items,
            'secondary_heading_slots' => $secondary_heading_slots,
        ]);

        if (!$model_data_gate['is_sufficient']) {
            $renderer_payload = array_merge($renderer_payload, self::build_sparse_model_payload($name, $active_platforms, $model_data_gate, $rankmath_keywords, $extra));
        }
        $renderer_payload = self::maybe_add_sparse_wordcount_support_paragraph($renderer_payload, $name, $active_platforms, !$model_data_gate['is_sufficient']);

        // ── Phrase deduplication (plain-string bags only, before render) ─────
        $dedup_bags = self::deduplicate_payload_phrases([
            'intro'      => $renderer_payload['intro_paragraphs']      ?? [],
            'watch'      => $renderer_payload['watch_section_paragraphs'] ?? [],
            'about'      => $renderer_payload['about_section_paragraphs'] ?? [],
            'features'   => $renderer_payload['features_section_paragraphs'] ?? [],
            'comparison' => $renderer_payload['comparison_section_paragraphs'] ?? [],
        ]);
        $renderer_payload['intro_paragraphs']           = $dedup_bags['intro'];
        $renderer_payload['watch_section_paragraphs']   = $dedup_bags['watch'];
        $renderer_payload['about_section_paragraphs']   = $dedup_bags['about'];
        $renderer_payload['features_section_paragraphs'] = $dedup_bags['features'];
        $renderer_payload['comparison_section_paragraphs'] = $dedup_bags['comparison'];

        $content = ModelPageRenderer::render($name, $renderer_payload);
        $content = self::split_long_paragraphs($content);
        $content = self::cleanup_model_content($content, $name);
        $content = self::ensure_minimum_useful_depth($content, $name, $active_platforms, $resolved_destinations, $primary_platform_label, $seed);
        $content = self::apply_lightweight_content_guardrails($content, $name);

        // ── Model Research Evidence prepend (v5.8.7) ────────────────────────
        // Single insertion point for the 3 operator-pasted seed sections
        // (About / Turn Ons / Private Chat Options). The helper:
        //   - reads the 3 _tmwseo_seed_external_* meta fields
        //   - humanizes them (denylist + canonicaliser + entity decode)
        //   - strips any prior wrapper-marker block (idempotent re-generation)
        //   - prepends a fresh block above the existing generated body
        // Existing body is NEVER modified — this is purely additive.
        if ( class_exists( \TMWSEO\Engine\Content\ModelResearchEvidence::class ) ) {
            $content = \TMWSEO\Engine\Content\ModelResearchEvidence::prepend_sections( (int) $post->ID, $content, (string) $post->post_title );
        }
        // ── End Model Research Evidence prepend ─────────────────────────────

        // ── Final-pass deterministic copy cleanup (v5.8.8) ──────────────────
        // Runs immediately after evidence prepend so it sees the full
        // generated body. The cleanup helper splits out the evidence block
        // before processing and restores it verbatim, so nothing inside the
        // <!-- tmwseo-seed-evidence:start --> markers is touched.
        if ( class_exists( \TMWSEO\Engine\Content\ModelCopyCleanup::class ) ) {
            $content = \TMWSEO\Engine\Content\ModelCopyCleanup::cleanup( $content, (string) $post->post_title );
        }
        // ── End Final-pass deterministic copy cleanup ───────────────────────

        // ── Keyword heading enforcement (all modes share this post-render step) ─
        $enforcement = self::enforce_keyword_heading_placement($content, $rankmath_keywords, $name);
        $content = $enforcement['html'];

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

        return [
            'watch_section_html' => $watch_html,
            'comparison_section_html' => self::build_platform_comparison($post, $name, $cta_links, $comparison_copy, $editor_seed),
            // Middle destination sections stay text-only so non-live outbound
            // links appear only once in the final Official Links section.
            'official_destinations_section_html' => '',
            'official_destinations_section_paragraphs' => [
                'CamSoda, personal sites, and fan/support pages are listed in the Official Links and Profiles section below. They are useful for following or support, but they are not live-room buttons.',
            ],
            'community_destinations_section_html' => '',
            'community_destinations_section_paragraphs' => [
                'Video channels, social profiles, and link hubs are listed below for updates, archives, and handle checks.',
            ],
            'related_models_html' => '',
            'explore_more_html' => '',
            // All visible outbound links consolidated here — Explore More is the
            // only place in rendered content where real external links appear.
            'external_info_html' => $ext_info_html,
            // v5.8.11-final-copy: official_links_section_paragraphs no longer
            // glues build_verification_process_paragraph() to a secondary-
            // keyword tail. We assemble three independent paragraphs:
            //   1. build_official_links_summary() — count + grouped families
            //      (the only place "Latest check:" appears).
            //   2. build_verification_process_paragraph() — short status note
            //      (rewritten to avoid "latest grouped link check" wording).
            //      Returns '' when the summary already conveys the count, so
            //      it can be omitted cleanly in that case.
            //   3. A standalone "When checking {keyword} links, use the
            //      grouped profiles below..." sentence built from
            //      $secondary_visible_phrases[2]. This is body-only keyword
            //      coverage; enforce_keyword_heading_placement() must NOT
            //      inject an H3 here (see Part 6 section guard).
            'official_links_section_paragraphs' => array_values(array_filter([
                self::build_official_links_summary($name, $cta_links, (int) $post->ID, $resolved_destinations),
                self::build_verification_process_paragraph($resolved_destinations),
                !empty($secondary_visible_phrases[2])
                    ? 'When checking ' . $secondary_visible_phrases[2] . ' links, use the grouped profiles below to separate live access from fan, social, and link-hub pages.'
                    : '',
            ], 'strlen')),
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
        // External evidence payload bridge REMOVED in v5.8.7 — Model Research
        // Evidence is now applied via ModelResearchEvidence::prepend_sections()
        // at the generation save points, not via renderer-payload keys.
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
            $intro_first = $platform_text . ' is the confirmed live-room option from this check. Start there for live access.';
            $comparison_lines = [
                'Before joining, confirm the handle and check recent room activity.',
            ];
        } elseif ($active_platform_count >= 2) {
            $intro_first = 'Live profiles are currently available on ' . $platform_text . '. Open one live room first, then compare the rest if needed.';
            $comparison_lines = [
                'If multiple platforms are active, start with your familiar platform and compare load speed, chat controls, and privacy settings before choosing where to watch.',
            ];
        } else {
            $intro_first = 'No live-room profile is confirmed active in this check.';
            $comparison_lines = [
                'When live status is unclear, verify the handle and use other listed profiles for updates.',
            ];
        }

        if ($active_platform_count === 1) {
            $first_link_answer = 'Open the ' . $platform_text . ' room first; use the other profiles only for updates.';
        } elseif ($active_platform_count >= 2) {
            $first_link_answer = 'Open one of the confirmed live rooms first, then compare the others if needed.';
        } else {
            $first_link_answer = 'No live room is confirmed active right now; use the listed profiles for checks and updates.';
        }

        $faq_items = $has_meaningful_structure
            ? [
                [
                    'q' => 'Which link should I open first?',
                    'a' => $first_link_answer,
                ],
                [
                    'q' => 'How do I avoid stale or copied profile links?',
                    'a' => 'Start from the live profile shown on this page, then use the grouped profiles below for follow-up checks. Match the handle, look for recent activity, and avoid mirror pages that copy names or photos without a clear platform profile.',
                ],
                [
                    'q' => 'What does non-active mean on this page?',
                    'a' => 'It means the profile is useful for checks, but not for entering a live room right now.',
                ],
                [
                    'q' => 'Why are fan pages not in the live section?',
                    'a' => 'Fan pages are useful for following or support, but they are not direct live-room links.',
                ],
                [
                    'q' => 'Why should I recheck status before joining?',
                    'a' => 'Room availability can change quickly. A quick recheck helps you avoid stale links.',
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

        // ── v5.8.11-final-copy: sparse intro/features no longer carry secondary
        //    keyword tails. Tails were causing enforce_keyword_heading_placement()
        //    to inject awkward H3s ("Anisyia Cam Show") inside Official Profile
        //    Access. Each secondary phrase still surfaces in body via the
        //    Features prose paragraphs below (Rank Math coverage preserved) and,
        //    for [2], via inject_sparse_secondary_keyword_into_faq() / the
        //    Official Links keyword paragraph in build_model_renderer_support_payload().
        $sparse_features_paragraphs = [];
        $seen_feature_sentences = [];
        $max_feature_sentences = 3;
        $primary_platform_label = $active_platform_count > 0 ? trim((string) ($active_platforms[0] ?? '')) : '';
        $ordered_phrases = self::order_sparse_feature_phrases($secondary_visible_phrases, $primary_platform_label);
        foreach ($ordered_phrases as $phrase) {
            if (count($sparse_features_paragraphs) >= $max_feature_sentences) {
                break;
            }
            $sentence = self::build_sparse_features_sentence((string) $phrase, $primary_platform_label);
            if ($sentence === '') {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($sentence, 'UTF-8') : strtolower($sentence);
            if (isset($seen_feature_sentences[$key])) {
                continue;
            }
            $seen_feature_sentences[$key] = true;
            $sparse_features_paragraphs[] = $sentence;
        }

        return [
            'intro_paragraphs' => [
                $intro_first,
                'Use the other listed profiles only when you need updates or support.',
            ],
            'about_section_paragraphs' => [],
            'fans_like_section_paragraphs' => [],
            'features_section_paragraphs' => $sparse_features_paragraphs,
            'comparison_section_paragraphs' => $comparison_lines,
            'questions_section_paragraphs' => [],
            'faq_items' => $faq_items,
            'model_data_notice' => $reason,
            'secondary_heading_slots' => $secondary_heading_slots,
        ];
    }

    /**
     * Add one short practical paragraph for sparse one-active-platform pages
     * when rendered content remains below Rank Math's 680-word safety threshold.
     *
     * @param array<string,mixed> $payload
     * @param string[] $active_platforms
     * @return array<string,mixed>
     */
    public static function maybe_add_sparse_wordcount_support_paragraph(array $payload, string $name, array $active_platforms, bool $is_sparse, int $minimum_words = 680): array {
        if (!$is_sparse) {
            return $payload;
        }

        $active_platform_count = count(array_values(array_filter(array_map('strval', $active_platforms), 'strlen')));
        if ($active_platform_count !== 1) {
            return $payload;
        }

        $word_count = self::estimate_sparse_payload_word_count($payload);
        if ($word_count >= max(1, $minimum_words)) {
            return $payload;
        }

        $support_line = 'Before spending credits, confirm the profile handle, check for recent activity, test playback on your device, and review payment and privacy controls before starting chat. A quick check also helps you spot stale mirrors, copied profile pages, or room listings that no longer match the active platform. Keep the first click focused on the confirmed live profile.';
        $faq_items = is_array($payload['faq_items'] ?? null) ? $payload['faq_items'] : [];
        $stale_profile_faq_question = 'How do I avoid stale or copied profile links?';
        $stale_profile_faq_answer = 'Start from the live profile shown on this page, then use the grouped profiles below for follow-up checks. Match the handle, look for recent activity, and avoid mirror pages that copy names or photos without a clear platform profile.';
        $has_stale_profile_faq = false;
        foreach ($faq_items as $faq_item) {
            if (!is_array($faq_item)) {
                continue;
            }
            if (trim((string) ($faq_item['q'] ?? '')) === $stale_profile_faq_question) {
                $has_stale_profile_faq = true;
                break;
            }
        }
        if (!$has_stale_profile_faq) {
            array_splice($faq_items, 1, 0, [[
                'q' => $stale_profile_faq_question,
                'a' => $stale_profile_faq_answer,
            ]]);
            $payload['faq_items'] = $faq_items;
        }

        $questions_paragraphs = is_array($payload['questions_section_paragraphs'] ?? null) ? $payload['questions_section_paragraphs'] : [];
        foreach ($questions_paragraphs as $line) {
            if (trim((string) $line) === $support_line) {
                return $payload;
            }
        }

        $questions_paragraphs[] = $support_line;
        $payload['questions_section_paragraphs'] = $questions_paragraphs;
        return $payload;
    }

    private static function estimate_rankmath_word_count(string $html): int {
        $text = trim((string) wp_strip_all_tags($html));
        if ($text === '') {
            return 0;
        }
        $matches = [];
        preg_match_all('/[\p{L}\p{N}]{2,}/u', $text, $matches);
        return isset($matches[0]) && is_array($matches[0]) ? count($matches[0]) : 0;
    }

    /** @param array<string,mixed> $payload */
    private static function estimate_sparse_payload_word_count(array $payload): int {
        $parts = [];
        foreach ([
            'intro_paragraphs',
            'watch_section_paragraphs',
            'official_destinations_section_paragraphs',
            'community_destinations_section_paragraphs',
            'about_section_paragraphs',
            'fans_like_section_paragraphs',
            'features_section_paragraphs',
            'comparison_section_paragraphs',
            'questions_section_paragraphs',
            'official_links_section_paragraphs',
        ] as $key) {
            $value = $payload[$key] ?? [];
            if (is_string($value)) {
                $parts[] = $value;
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $line) {
                    $parts[] = (string) $line;
                }
            }
        }
        foreach ((array) ($payload['faq_items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $parts[] = (string) ($item['q'] ?? '');
            $parts[] = (string) ($item['a'] ?? '');
        }
        foreach (['watch_section_html', 'official_destinations_section_html', 'community_destinations_section_html', 'external_info_html', 'explore_more_html'] as $html_key) {
            $html = trim((string) ($payload[$html_key] ?? ''));
            if ($html !== '') {
                $parts[] = $html;
            }
        }

        return self::estimate_rankmath_word_count(implode("\n", $parts));
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
            $faq_items[$index]['a'] = $answer . ' That includes quick checks for ' . $keyword_phrase . ' using the listed profiles only.';
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

    // ─────────────────────────────────────────────────────────────────────────
    // BIO EVIDENCE LAYER
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Read the reviewed bio evidence fields from post meta.
     *
     * Gate: only bio_review_status === 'reviewed' with a non-empty bio_summary
     * unlocks bio display. All other states produce is_reviewable = false and
     * must NEVER trigger a generic fallback or invented performer text.
     *
     * WPS LiveJasmin hook: if the WPS plugin stores performer data in post meta
     * (e.g. _wps_performer_desc, _wps_performer_intro, _wps_performer_tags)
     * those values should be copied into bio_source_facts by an editor after
     * manual review — this layer reads only the reviewed copy, never raw
     * third-party import text.
     *
     * @return array{summary:string,source_type:string,status:string,reviewed_at:string,source_url:string,source_label:string,source_facts:string[],is_reviewable:bool}
     */
    public static function get_bio_evidence_data(int $post_id): array {
        $summary      = trim((string) get_post_meta($post_id, '_tmwseo_bio_summary', true));
        $source_type  = trim((string) get_post_meta($post_id, '_tmwseo_bio_source_type', true));
        $status       = trim((string) get_post_meta($post_id, '_tmwseo_bio_review_status', true));
        $reviewed_at  = trim((string) get_post_meta($post_id, '_tmwseo_bio_reviewed_at', true));
        $source_url   = trim((string) get_post_meta($post_id, '_tmwseo_bio_source_url', true));
        $source_label = trim((string) get_post_meta($post_id, '_tmwseo_bio_source_label', true));
        $source_facts = self::normalize_multiline_list(
            (string) get_post_meta($post_id, '_tmwseo_bio_source_facts', true)
        );

        // Word-count sanity: bio should be 30–250 words (editor responsible for
        // the 60–110 target; we accept a wider range to avoid silently dropping
        // edge-trimmed summaries).
        $word_count    = str_word_count($summary);
        $length_ok     = ($summary !== '' && $word_count >= 20 && $word_count <= 300);
        $is_reviewable = ($status === 'reviewed' && $summary !== '' && $length_ok);

        return [
            'summary'       => $summary,
            'source_type'   => $source_type,
            'status'        => $status,
            'reviewed_at'   => $reviewed_at,
            'source_url'    => $source_url,
            'source_label'  => $source_label,
            'source_facts'  => $source_facts,
            'is_reviewable' => $is_reviewable,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EXTERNAL PROFILE EVIDENCE (v5.8.0–v5.8.6) — REMOVED in v5.8.7.
    // The 3-field model-research evidence flow is now handled directly by
    // \TMWSEO\Engine\Content\ModelResearchEvidence::prepend_sections() called
    // from each generation save path. There is no longer a renderer-payload
    // bridge.
    // ─────────────────────────────────────────────────────────────────────────
    // KEYWORD HEADING ENFORCEMENT  (post-render, all modes)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Ensure every configured Rank Math additional keyword gets a deterministic
     * placement result in the rendered HTML.
     *
     * For each keyword:
     *   1. Already in a heading?          → placed_heading
     *   2. Not in heading but in body?    → placed_body_only  (H3 injected
     *                                       only inside SAFE editorial
     *                                       sections — see ALLOWED_H3_SECTIONS
     *                                       below)
     *   3. Too awkward for heading?       → skipped (reason recorded)
     *
     * v5.8.11-final-copy hardening:
     *   - Name-bearing keywords (phrase contains the model name as a word) are
     *     never injected as an H3 anywhere. They mirror the rejection rule in
     *     select_heading_safe_secondary_keyword_phrases() so the two systems
     *     no longer disagree. If the phrase is in body, status =
     *     placed_body_only; otherwise skipped (no fallback append).
     *   - Section-context guard: H3 is injected only inside Features and FAQ
     *     sections. Matches in Official Profile Access, Where to Watch Live,
     *     Other Official Destinations, Social Profiles…, and Official Links
     *     and Profiles / Where Are the Official Links… are downgraded to
     *     placed_body_only with no H3.
     *   - Fallback ("not in body") still appends a single H3 + sentence to
     *     the Features section, but only for safe non-name-bearing phrases.
     *
     * The method returns modified HTML and a placement report for diagnostics.
     *
     * @param  string   $html              Rendered model page HTML.
     * @param  string[] $rankmath_keywords Up-to-4 Rank Math additional keywords.
     * @param  string   $model_name        Model name (used for safety checks).
     * @return array{html:string, placement_report:array<int,array{keyword:string,status:string,reason:string}>}
     */
    public static function enforce_keyword_heading_placement(string $html, array $rankmath_keywords, string $model_name): array {
        $report = [];
        if (empty($rankmath_keywords) || trim($html) === '') {
            return ['html' => $html, 'placement_report' => $report];
        }

        // Sections where H3 injection is banned. Patterns are matched
        // case-insensitively against H2 inner text. ModelCopyCleanup rewrites
        // "Where Are the Official Links and Other Profiles?" to "Official
        // Links and Profiles" — both forms are listed.
        $disallowed_section_patterns = [
            '/^Official\s+Profile\s+Access\b/iu',
            '/^Where\s+to\s+Watch\s+Live\b/iu',
            '/^Other\s+Official\s+Destinations\b/iu',
            '/^Social\s+Profiles\b/iu',
            '/^Where\s+Are\s+the\s+Official\s+Links\b/iu',
            '/^Official\s+Links\s+and\s+Profiles\b/iu',
        ];

        // Collect existing heading text (H2 + H3 + H4) for fast lookup.
        preg_match_all('/<h[234][^>]*>(.*?)<\/h[234]>/isu', $html, $heading_matches);
        $heading_texts = array_map(
            static fn(string $h): string => mb_strtolower(wp_strip_all_tags($h), 'UTF-8'),
            $heading_matches[1] ?? []
        );

        // Strip-tags body text for body-placement check.
        $body_text_lower = mb_strtolower(wp_strip_all_tags($html), 'UTF-8');
        $name_lower      = mb_strtolower(trim($model_name), 'UTF-8');

        // Build an index of <h2> positions so we can resolve which section a
        // given byte offset lives in. Used by section_at_offset() below.
        preg_match_all('/<h2\b[^>]*>(.*?)<\/h2>/isu', $html, $h2_matches, PREG_OFFSET_CAPTURE);
        $section_index = [];
        foreach ($h2_matches[0] as $i => $m) {
            $section_index[] = [
                'offset' => (int) $m[1],
                'text'   => trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $h2_matches[1][$i][0]))),
            ];
        }
        $section_at_offset = static function (int $offset) use ($section_index): string {
            $current = '';
            foreach ($section_index as $entry) {
                if ($entry['offset'] <= $offset) {
                    $current = (string) $entry['text'];
                } else {
                    break;
                }
            }
            return $current;
        };
        $is_disallowed_section = static function (string $heading_text) use ($disallowed_section_patterns): bool {
            $heading_text = trim($heading_text);
            if ($heading_text === '') {
                return false;
            }
            foreach ($disallowed_section_patterns as $pattern) {
                if (preg_match($pattern, $heading_text)) {
                    return true;
                }
            }
            return false;
        };

        foreach ($rankmath_keywords as $kw) {
            $kw = trim((string) $kw);
            if ($kw === '') {
                continue;
            }
            $kw_lower = mb_strtolower($kw, 'UTF-8');

            // 1. Already in a heading?
            $in_heading = false;
            foreach ($heading_texts as $ht) {
                if (str_contains($ht, $kw_lower)) {
                    $in_heading = true;
                    break;
                }
            }
            if ($in_heading) {
                $report[] = ['keyword' => $kw, 'status' => 'placed_heading', 'reason' => 'found_in_existing_heading'];
                continue;
            }

            // Safety: refuse to inject headings for nonsense or name-bearing phrases.
            $word_count_kw  = preg_match_all('/[\p{L}\p{N}]+/u', $kw);
            $is_name_only   = ($name_lower !== '' && $kw_lower === $name_lower);
            // v5.8.11-final-copy: name-bearing rejection — mirrors
            // select_heading_safe_secondary_keyword_phrases() line for
            // is_heading_safe_secondary_phrase() ("name is a word in phrase").
            $is_name_bearing = (
                $name_lower !== ''
                && (bool) preg_match('/\b' . preg_quote($name_lower, '/') . '\b/u', $kw_lower)
            );
            $too_short      = ($word_count_kw !== false && $word_count_kw < 2);
            $too_long       = (mb_strlen($kw, 'UTF-8') > 72);
            $has_punct      = (bool) preg_match('/[,;:|\/]/', $kw);
            $has_banned     = (bool) preg_match('/\b(xxx|porn|sex|free|cheap|instant|guaranteed)\b/iu', $kw);

            if ($is_name_only || $is_name_bearing || $too_short || $too_long || $has_punct || $has_banned) {
                $reason = match(true) {
                    $is_name_only    => 'same_as_model_name',
                    $is_name_bearing => 'contains_model_name',
                    $too_short       => 'too_few_words',
                    $too_long        => 'phrase_too_long',
                    $has_punct       => 'contains_punctuation',
                    default          => 'contains_banned_term',
                };
                // Still check body placement even if heading is refused.
                if (str_contains($body_text_lower, $kw_lower)) {
                    $report[] = ['keyword' => $kw, 'status' => 'placed_body_only', 'reason' => 'heading_skipped:' . $reason . ',body_found'];
                } else {
                    $report[] = ['keyword' => $kw, 'status' => 'skipped', 'reason' => $reason];
                }
                continue;
            }

            // Normalize to a heading-safe title-case phrase.
            $heading_phrase = ucwords(mb_strtolower($kw, 'UTF-8'));

            // 2. In body? Determine the H2 section the first body match is in.
            //    If it lives in a disallowed section, downgrade to body-only.
            if (str_contains($body_text_lower, $kw_lower)) {
                $first_match_offset = -1;
                if (preg_match(
                    '/<p>((?:(?!<\/p>).)*' . preg_quote($kw_lower, '/') . '(?:(?!<\/p>).)*)<\/p>/iu',
                    $html,
                    $p_match,
                    PREG_OFFSET_CAPTURE
                )) {
                    $first_match_offset = (int) $p_match[0][1];
                }

                $section_text = $first_match_offset >= 0 ? $section_at_offset($first_match_offset) : '';
                if ($first_match_offset >= 0 && $is_disallowed_section($section_text)) {
                    $report[] = ['keyword' => $kw, 'status' => 'placed_body_only', 'reason' => 'section_disallowed:' . $section_text];
                    continue;
                }

                // Inject H3 before the first paragraph that contains the keyword.
                $inserted = false;
                $html = preg_replace_callback(
                    '/<p>((?:(?!<\/p>).)*' . preg_quote($kw_lower, '/') . '(?:(?!<\/p>).)*)<\/p>/iu',
                    static function (array $m) use ($heading_phrase, &$inserted): string {
                        if ($inserted) {
                            return $m[0];
                        }
                        $inserted = true;
                        return '<h3>' . esc_html($heading_phrase) . '</h3>' . "\n" . $m[0];
                    },
                    $html,
                    1
                ) ?: $html;

                if ($inserted) {
                    $report[] = ['keyword' => $kw, 'status' => 'placed_heading', 'reason' => 'h3_injected_before_body_paragraph'];
                } else {
                    $report[] = ['keyword' => $kw, 'status' => 'placed_body_only', 'reason' => 'regex_insert_failed'];
                }
                continue;
            }

            // 3. Not in body or heading → append a minimal H3 + one-sentence
            //    paragraph to the Features section (or end of content if
            //    Features not found). Only safe non-name-bearing phrases
            //    reach this branch.
            $kw_sentence = 'For ' . $kw . ', compare room freshness, handle match, and chat usability before you join.';
            $inject_block = "\n<h3>" . esc_html($heading_phrase) . "</h3>\n<p>" . esc_html($kw_sentence) . "</p>";

            // Try to inject after Features H2 section.
            if (preg_match('/<h2[^>]*>.*?Features.*?<\/h2>/isu', $html)) {
                $html = preg_replace(
                    '/(<h2[^>]*>.*?Features.*?<\/h2>)/isu',
                    '$1' . $inject_block,
                    $html,
                    1
                ) ?: $html . $inject_block;
            } else {
                $html .= $inject_block;
            }

            $report[] = ['keyword' => $kw, 'status' => 'placed_heading', 'reason' => 'injected_new_h3_block'];
        }

        return ['html' => $html, 'placement_report' => $report];
    }

    /**
     * Build a heading-slot plan summary string for AI prompts.
     *
     * @param  array<string,mixed> $heading_slots Output of build_secondary_heading_slots().
     * @return string
     */
    public static function build_keyword_heading_slot_prompt_block(array $heading_slots): string {
        if (empty($heading_slots)) {
            return '';
        }
        $slot_section_map = [
            'features'       => 'Features and Platform Experience section',
            'comparison'     => 'Before You Click / Platform Comparison section',
            'official_links' => 'Official Links section',
            'faq'            => 'FAQ section',
        ];

        $lines = ['KEYWORD HEADING PLACEMENT PLAN'];
        $lines[] = 'For each keyword below, place it in the named section heading or as a natural H3 subheading in that section.';
        $lines[] = 'If a keyword cannot fit naturally in a heading, weave it into body prose instead — do NOT skip it silently.';

        $has_placeable = false;
        foreach ($slot_section_map as $slot => $label) {
            $phrases = array_values(array_filter(array_map('trim', (array)($heading_slots[$slot] ?? [])), 'strlen'));
            if (empty($phrases)) {
                continue;
            }
            foreach ($phrases as $phrase) {
                $lines[]      = '• ' . $label . ': "' . $phrase . '"';
                $has_placeable = true;
            }
        }

        $unusable = array_values(array_filter(array_map('trim', (array)($heading_slots['unusable'] ?? [])), 'strlen'));
        if (!empty($unusable)) {
            $lines[] = '• Body-only (heading not possible): "' . implode('", "', $unusable) . '" — use in paragraph prose only.';
        }

        if (!$has_placeable && empty($unusable)) {
            return '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Reduce repeated status/routing phrases in paragraph string arrays
     * BEFORE they are handed to the renderer.
     *
     * This operates only on plain-string paragraph arrays, never on HTML.
     * Each capped phrase is allowed at most $max_per_phrase occurrences
     * across ALL paragraph arrays combined.
     *
     * @param  array<string,string[]> $paragraph_bags  Map of section key → string[].
     * @return array<string,string[]>
     */
    public static function deduplicate_payload_phrases(array $paragraph_bags, int $max_per_phrase = 2): array {
        // Phrases to cap and their approved rotation replacements.
        $cap_phrases = [
            'review pass'          => ['latest check', 'current snapshot', 'most recent check'],
            'confirmed active'     => ['currently live', 'verified live', 'confirmed live'],
            'live-room destination' => ['live platform', 'active room', 'live room'],
            'verified destinations' => ['checked links', 'official links', 'verified links'],
        ];

        // Count occurrences across all bags.
        $counts   = [];
        $replace  = [];

        // Two passes: count first, then replace.
        foreach ($paragraph_bags as $bag) {
            foreach ($bag as $para) {
                $lower = mb_strtolower((string) $para, 'UTF-8');
                foreach (array_keys($cap_phrases) as $phrase) {
                    $counts[$phrase] = ($counts[$phrase] ?? 0) + substr_count($lower, $phrase);
                }
            }
        }

        // Build replacement map for phrases that exceed the cap.
        foreach ($cap_phrases as $phrase => $alternatives) {
            if (($counts[$phrase] ?? 0) > $max_per_phrase) {
                $replace[$phrase] = $alternatives;
            }
        }

        if (empty($replace)) {
            return $paragraph_bags;
        }

        // Replace excess occurrences in bag strings (case-insensitive).
        $result = [];
        $hit_counts = array_fill_keys(array_keys($replace), 0);

        foreach ($paragraph_bags as $section => $bag) {
            $new_bag = [];
            foreach ($bag as $para) {
                $para = (string) $para;
                foreach ($replace as $phrase => $alts) {
                    $para = preg_replace_callback(
                        '/\b' . preg_quote($phrase, '/') . '\b/iu',
                        static function (array $m) use ($phrase, $alts, $max_per_phrase, &$hit_counts): string {
                            $hit_counts[$phrase]++;
                            if ($hit_counts[$phrase] <= $max_per_phrase) {
                                return $m[0]; // keep original
                            }
                            // Rotate through alternatives.
                            $idx = ($hit_counts[$phrase] - $max_per_phrase - 1) % count($alts);
                            // Preserve original capitalisation.
                            $replacement = $alts[$idx];
                            if (ctype_upper(substr($m[0], 0, 1))) {
                                $replacement = ucfirst($replacement);
                            }
                            return $replacement;
                        },
                        $para
                    ) ?? $para;
                }
                $new_bag[] = $para;
            }
            $result[$section] = $new_bag;
        }

        return $result;
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
            $clean_url = trim((string)($entry['url'] ?? ''));
            if ($clean_url === '' || !filter_var($clean_url, FILTER_VALIDATE_URL)) { continue; }
            $url_key = strtolower(rtrim($clean_url, '/'));
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
                'url' => self::get_frontend_verified_link_href($entry),
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
        $live_count = !empty($cta_links) ? count($cta_links) : 0;
        $resolved = !empty($resolved_destinations) ? $resolved_destinations : ModelDestinationResolver::resolve($post_id);
        $summary = is_array($resolved['source_of_truth_summary'] ?? null) ? $resolved['source_of_truth_summary'] : [];
        $total_count = (int) ($summary['verified_count'] ?? 0);
        if ($total_count <= 0) {
            $total_count = count((array) ($resolved['all_verified_destinations'] ?? []));
        }
        $types = ['cam platforms', 'official sites', 'fan pages', 'video channels', 'socials', 'link hubs'];
        return 'Below are the grouped profiles found for ' . $name . ': ' . self::format_platform_list($types, 'profile groups') . '. Latest check: ' . $total_count . ' profile links found, including ' . $live_count . ' live profile' . ($live_count === 1 ? '' : 's') . '.';
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
            $answer_line = $platform_text . ' is the confirmed live-room option from this check. Start there for live access, then use other listed profiles for follow-up or backup checks.';
        } elseif ($active_platform_count > 1) {
            $platform_text = self::format_platform_list($active_platforms, 'verified live platforms');
            $answer_line = 'Live profiles are available on ' . $platform_text . '. Open a live room first, then use the other sections for updates.';
        } else {
            $answer_line = 'No live-room profile is confirmed active in this check.';
        }
        if ($summary === '') {
            return [
                $answer_line,
                $fallback_intro,
                $fallback_second,
                'Before you commit to one room, run a quick check: username match, recent room activity, chat readability, and mobile playback stability.',
                'Use the live section for room entry, then use non-live profiles for schedule checks, backup access, and handle verification.',
            ];
        }
        return [
            $answer_line,
            $summary,
            'Use the other listed profiles for follow-up, support, or backup checks.',
            'If activity changes, verify handle match and room quality before spending.',
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
            'If one room goes offline later, use another listed profile instead of scraped mirror pages.',
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
            // v5.8.11-final-copy: removed the standalone intro <p> ("Before
            // joining, confirm the handle, check recent room activity, and
            // review payment/privacy controls.") because it duplicated the
            // sparse comparison_section_paragraphs intro that always renders
            // immediately above this block. Trimmed checklist to two bullets
            // that do not restate "check recent room activity" — that is
            // already covered by the section's intro paragraph.
            $checklist = '<ul>'
                . '<li>Confirm the username shown on the platform matches the listed profile.</li>'
                . '<li>Review payment and privacy controls before starting chat.</li>'
                . '</ul>';
            $cta = '';
            if ($url !== '') {
                $cta = '<p><a href="' . esc_url($url) . '" target="_blank" rel="sponsored noopener">Open ' . esc_html($platform) . ' profile</a></p>';
            }
            return $alt_username_note
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
            $url = self::get_frontend_verified_link_href($entry);
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
            $url = self::get_frontend_verified_link_href($entry);
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

        return '<ul>' . $items . '</ul>';
    }

    /**
     * Build frontend href for a verified destination row.
     *
     * Uses the clean verified URL as source-of-truth, then applies
     * CrakRevenue routing only for eligible verified platform rows.
     *
     * @param array<string,mixed> $link
     */
    private static function get_frontend_verified_link_href(array $link): string {
        $url = trim((string) ($link['url'] ?? ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }

        $type = AffiliateLinkBuilder::canonical_platform_slug((string) ($link['type'] ?? $link['platform_key'] ?? ''));
        if ($type === '') {
            return $url;
        }

        if ($type === 'livejasmin') {
            $username = PlatformProfiles::extract_username_from_profile_url('livejasmin', $url);
            if ($username === '') {
                $parts = wp_parse_url($url);
                $host = strtolower((string) ($parts['host'] ?? ''));
                if ($host !== '' && (str_contains($host, '.livejasmin.com') || $host === 'livejasmin.com')) {
                    $path = trim((string) ($parts['path'] ?? ''), '/');
                    $segments = $path !== '' ? explode('/', $path) : [];
                    $username = trim((string) end($segments));
                }
            }

            if ($username !== '') {
                $go = AffiliateLinkBuilder::go_url('livejasmin', $username);
                if ($go !== '') {
                    return $go;
                }
            }
        }

        if (!class_exists(\TMWSEO\Engine\Affiliates\CrakRevenueCamManager::class)) {
            return $url;
        }

        return \TMWSEO\Engine\Affiliates\CrakRevenueCamManager::maybe_route_verified_link([
            'type' => $type,
            'url' => $url,
        ]);
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
     *
     * v5.8.11-final-copy: pool reduced to 4 practical access checks; the meta
     * "Platform notes here focus on observed access behavior" bullet has been
     * removed because it duplicated the features-section intro paragraph.
     */
    private static function render_varied_features(string $name, array $tags, string $platform, string $seed, int $active_platform_count = 0): string {
        $bullets = [
            '<li>Test playback stability and chat readability on your device.</li>',
            '<li>Review payment, privacy, and account requirements before starting chat.</li>',
        ];

        if ($active_platform_count > 1) {
            $bullets[1] = '<li>Compare login friction and mobile usability when more than one room is available.</li>';
        }

        return '<ul>' . implode("\n", $bullets) . '</ul>';
    }

    private static function build_sparse_features_sentence(string $phrase, string $primary_platform_label): string {
        $phrase = trim((string) preg_replace('/\s+/u', ' ', $phrase));
        if ($phrase === '') {
            return '';
        }

        $phrase_lower = function_exists('mb_strtolower') ? mb_strtolower($phrase, 'UTF-8') : strtolower($phrase);
        $platform_label = trim((string) $primary_platform_label);
        $platform_lower = function_exists('mb_strtolower') ? mb_strtolower($platform_label, 'UTF-8') : strtolower($platform_label);
        $fallback_lower = strtolower(self::NEUTRAL_PLATFORM_FALLBACK);

        if ($platform_lower !== '' && $platform_lower !== $fallback_lower && strpos($phrase_lower, $platform_lower) !== false) {
            return 'For ' . $phrase . ' access, confirm handle consistency and recent room activity before joining.';
        }
        if (strpos($phrase_lower, 'webcam chat') !== false) {
            return 'For ' . $phrase . ' searches, check playback quality, mobile usability, and payment/privacy controls before spending credits.';
        }
        if (strpos($phrase_lower, 'cam show') !== false || strpos($phrase_lower, 'live cam') !== false) {
            return 'For ' . $phrase . ' searches, compare room freshness, handle match, and chat usability before joining.';
        }

        return 'For ' . $phrase . ' searches, verify profile consistency and room usability before joining.';
    }

    /** @param string[] $phrases @return string[] */
    private static function order_sparse_feature_phrases(array $phrases, string $primary_platform_label): array {
        $platform_label = trim((string) $primary_platform_label);
        $platform_lower = function_exists('mb_strtolower') ? mb_strtolower($platform_label, 'UTF-8') : strtolower($platform_label);

        $buckets = [
            'platform' => [],
            'webcam' => [],
            'cam_show' => [],
            'live_cam' => [],
            'other' => [],
        ];

        foreach ($phrases as $phrase) {
            $phrase = trim((string) $phrase);
            if ($phrase === '') {
                continue;
            }
            $lower = function_exists('mb_strtolower') ? mb_strtolower($phrase, 'UTF-8') : strtolower($phrase);
            if ($platform_lower !== '' && strpos($lower, $platform_lower) !== false) {
                $buckets['platform'][] = $phrase;
            } elseif (strpos($lower, 'webcam chat') !== false) {
                $buckets['webcam'][] = $phrase;
            } elseif (strpos($lower, 'cam show') !== false) {
                $buckets['cam_show'][] = $phrase;
            } elseif (strpos($lower, 'live cam') !== false) {
                $buckets['live_cam'][] = $phrase;
            } else {
                $buckets['other'][] = $phrase;
            }
        }

        return array_merge(
            $buckets['platform'],
            $buckets['webcam'],
            $buckets['cam_show'],
            $buckets['live_cam'],
            $buckets['other']
        );
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
        $content = str_replace('This guide covers exactly that need:', 'Here are the basics you need:', $content);
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

        $active_platform_count = count(array_values(array_filter(array_map('strval', $active_platforms), 'strlen')));
        if ($active_platform_count <= 1) {
            return $content;
        }

        $platform_text = self::format_platform_list($active_platforms, $primary_platform_label !== '' ? $primary_platform_label : 'verified platforms');

        $compare_block = '<h2>How to Decide Where to Start</h2>'
            . '<p>Start with the platform you already trust, then test one alternate room with the same checklist: uptime signals, chat readability, playback stability, moderation flow, and login friction. A repeatable method prevents brand bias and makes it easier to pick the better room for your device and connection.</p>'
            . '<p>If both rooms perform similarly, keep the one with clearer moderation and fewer account hurdles. If neither room works well, use the other listed profiles on this page to confirm handles and return later when status changes.</p>';

        // Platform-count-agnostic blocks. These are safe on every page.
        $extra_blocks = [
            '<h2>Verification and Review Method</h2>'
            . '<p>This page prioritizes confirmed profiles and manual checks. Confirmation helps with ownership and safer navigation, but it does not guarantee continuous uptime. Activity labels represent a snapshot and can change after platform updates or schedule shifts.</p>'
            . '<p>For that reason, recheck status each time you visit. Starting from a verified destination is still the safest path to avoid copied pages, stale mirrors, or impersonation profiles.</p>',
            '<h2>How to Use Backup Destinations Safely</h2>'
            . '<p>When a preferred room is offline, move to a verified backup destination instead of random search results. Confirm handle spelling, brand cues, and profile history before clicking onward to any paid flow.</p>'
            . '<p>This approach reduces impersonation risk and keeps your routing consistent: trusted destination first, status check second, and spending decisions only after room quality is clear.</p>',
        ];

        array_unshift($extra_blocks, $compare_block);
        $extra_blocks[] = '<h2>Practical Use of Non-Live Destinations</h2>'
            . '<p>Non-live destinations remain useful even when they are not room-entry links. Use them for follow actions, backup profile validation, archived media, and link-hub navigation when the live section is temporarily inactive.</p>'
            . '<p>This separation keeps the page truthful: live access appears only in the live section, while other official destinations support planning and verification tasks.</p>';

        $need = min(count($extra_blocks), (int) ceil((640 - $word_count) / 110));
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

    /**
     * Short status note that complements build_official_links_summary().
     *
     * v5.8.11-final-copy:
     *   - removed the "latest grouped link check" wording (it bypassed
     *     ModelCopyCleanup::dedupe_latest_check_sentences and competed with
     *     build_official_links_summary's "Latest check: N profile links found"
     *     sentence);
     *   - secondary-keyword tail concatenation moved out of this paragraph
     *     into its own standalone sentence (see callsite in
     *     build_model_renderer_support_payload());
     *   - returns '' when the summary already conveys the same status
     *     information so the section avoids two near-identical paragraphs.
     */
    private static function build_verification_process_paragraph(array $resolved_destinations): string {
        $summary = is_array($resolved_destinations['source_of_truth_summary'] ?? null)
            ? $resolved_destinations['source_of_truth_summary']
            : [];
        $verified_count = (int) ($summary['verified_count'] ?? 0);
        if ($verified_count <= 0) {
            $verified_count = count((array) ($resolved_destinations['all_verified_destinations'] ?? []));
        }
        // When build_official_links_summary() will already produce a non-empty
        // count sentence, suppress this paragraph entirely. That keeps the
        // Official Links section single-paragraph (count + grouped link
        // directory) and prevents duplicate latest/check/status wording.
        if ($verified_count > 0) {
            return '';
        }
        // Fallback for the unusual case where there are zero verified
        // destinations: still tell the visitor what the section represents,
        // but without the "latest grouped link check" wording.
        return 'Status reflects this page\'s most recent automated review; activity may shift after platform updates.';
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
