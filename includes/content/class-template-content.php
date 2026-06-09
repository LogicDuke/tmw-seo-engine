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
use TMWSEO\Engine\Model\ModelBodySafety;
use TMWSEO\Engine\Logs;

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
        $verified_destination_rows = (array) ($resolved_destinations['all_verified_destinations'] ?? []);
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
        $link_evidence_summary = self::build_link_evidence_summary($resolved_destinations, $cta_links);
        $has_extra_link_evidence = !empty($link_evidence_summary['has_extra_links']);

        $tags = $pack['sources']['tags'] ?? [];
        if (!is_array($tags) || empty($tags)) {
            $tags = self::discover_model_tags($post);
        }
        $tags = array_values(array_filter(array_map('strval', $tags), 'strlen'));
        $tags_text = self::format_model_tags_for_body($tags);

        $extra = is_array($pack['additional'] ?? null) ? $pack['additional'] : [];
        $extra = self::filter_name_free_keywords($extra, $name);
        $extra = ModelBodySafety::filter_body_phrases($extra, $name, $verified_destination_rows);
        $extra = array_values(array_unique(array_merge(
            $extra,
            self::default_model_additional_keywords($primary_platform_label, $active_platforms)
        )));
        $extra = ModelBodySafety::filter_body_phrases($extra, $name, $verified_destination_rows);
        $extra = array_slice($extra, 0, 5);

        $longtail = is_array($pack['longtail'] ?? null) ? $pack['longtail'] : [];
        $longtail = self::filter_name_free_keywords($longtail, $name);
        $longtail = ModelBodySafety::filter_body_phrases($longtail, $name, $verified_destination_rows);
        $longtail = array_values(array_unique(array_merge(
            $longtail,
            self::default_model_longtail_keywords($primary_platform_label, $active_platforms)
        )));
        $longtail = ModelBodySafety::filter_body_phrases($longtail, $name, $verified_destination_rows);
        $longtail = array_slice($longtail, 0, 8);

        // Single source of truth for Rank Math chips and the on-page keyword
        // coverage block. Prefer the dedicated model-name-led list when available
        // (set by ModelKeywordPack::build() for model pages); fall back to $extra
        // for non-model pages and legacy packs that do not carry the key.
        // Rank Math receives one focus keyword plus four secondary chips.
        // Keep the visible secondary-keyword pool aligned with that limit.
        $rankmath_keywords = self::select_body_safe_rankmath_keywords($pack, $extra, $name, $verified_destination_rows);

        $secondary_visible_phrases = self::select_visible_secondary_keyword_phrases($rankmath_keywords, $extra);
        $secondary_heading_phrases = self::select_heading_safe_secondary_keyword_phrases($name, $rankmath_keywords, $extra);
        $secondary_heading_slots = self::build_secondary_heading_slots($secondary_heading_phrases);

        $context = [
            'name' => $name,
            'site' => get_bloginfo('name'),
            'tags' => $tags_text,
            'platform_a' => $primary_platform_label,
            'platform_b' => $active_platforms[1] ?? '',
            // live_brand must always be readable prose ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â never "official profile links".
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
        // Render {name}/{live_brand}/{tags}/etc. inside each Q&A now ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â the page
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
                'Start with the live-room button, then use additional verified destinations for updates or backup access when they are listed.',
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
        if (!empty($secondary_visible_phrases)) {
            $secondary_intro = self::build_secondary_keyword_intro_sentence($name, $secondary_visible_phrases);
            if ($secondary_intro !== '') {
                $second_intro .= ' ' . $secondary_intro;
            }
        }

        if (!$has_extra_link_evidence) {
            $watch_para_pool = [
                'Open the confirmed live profile below. ' . self::build_confirmed_live_profile_only_sentence($primary_platform_label),
                'Use the confirmed live-room link below as the primary access point, then confirm the room status after click-through.',
            ];
        } else {
            $watch_para_pool = [
                'Open the confirmed live profile below. Use the additional links below for profile checks, updates, fan pages, and support channels; they are separate from the live-room button.',
                'Choose a live platform below first. Use any additional verified destinations only for follow-up checks.',
                'Open a live profile first, then use verified non-live destinations if you need backups or updates.',
            ];
            if ($primary_platform_label !== self::NEUTRAL_PLATFORM_FALLBACK) {
                $watch_para_pool[] = 'If you already prefer ' . $primary_platform_label . ', start there and review other verified destinations afterward.';
            }
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

        // v5.8.19: gate diagnostic log ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â fires on manual Generate only.
        // Logs every signal the gate evaluated so we can see exactly which
        // condition failed without guessing. WP_DEBUG-gated; manual only.
        if (defined('WP_DEBUG') && WP_DEBUG && !empty($pack['_manual_generate'])) {
            $gate_sigs = $model_data_gate['signals'] ?? [];
            $missing   = [];
            if ((int)($gate_sigs['platform_links']  ?? 0) < 1) { $missing[] = 'platform_links<1'; }
            if ((int)($gate_sigs['active_platforms'] ?? 0) < 1) { $missing[] = 'active_platforms<1'; }
            // specific_fact_count components
            $sfc = min(3, (int)($gate_sigs['platform_links']  ?? 0))
                 + min(2, (int)($gate_sigs['active_platforms'] ?? 0))
                 + min(2, (int)($gate_sigs['tags']            ?? 0))
                 + min(1, (int)($gate_sigs['comparison_copy'] ?? 0))
                 + min(1, (int)($gate_sigs['faq_items']       ?? 0))
                 + min(3, (int)($gate_sigs['editor_seed_facts'] ?? 0));
            if ($sfc < 4) { $missing[] = 'specific_fact_count=' . $sfc . '<4'; }
            $or_condition_ok = (int)($gate_sigs['tags'] ?? 0) >= 1
                || (int)($gate_sigs['comparison_copy'] ?? 0) >= 1
                || (int)($gate_sigs['active_platforms'] ?? 0) >= 2;
            if (!$or_condition_ok) { $missing[] = 'tags=0_AND_comparison_copy=0_AND_active_platforms<2'; }

            error_log(sprintf(
                '[TMW-POOL-GATE] post_id=%d platform_links=%d active_platforms=%d tags=%d ' .
                'comparison_copy=%d faq_items=%d editor_seed_facts=%d additional_keywords=%d ' .
                'specific_fact_count=%d sufficient=%d reason=%s',
                (int) $post->ID,
                (int)($gate_sigs['platform_links']    ?? 0),
                (int)($gate_sigs['active_platforms']  ?? 0),
                (int)($gate_sigs['tags']              ?? 0),
                (int)($gate_sigs['comparison_copy']   ?? 0),
                (int)($gate_sigs['faq_items']         ?? 0),
                (int)($gate_sigs['editor_seed_facts'] ?? 0),
                (int)($gate_sigs['additional_keywords'] ?? 0),
                $sfc,
                (int)(!empty($model_data_gate['is_sufficient'])),
                (string)($model_data_gate['reason'] ?? 'unknown')
            ));

            if (!empty($missing)) {
                error_log(sprintf(
                    '[TMW-POOL-GATE] missing post_id=%d keys=%s',
                    (int) $post->ID,
                    implode(', ', $missing)
                ));
            }

            // Additional detail: activity_level on each verified link, so we can
            // see whether the single Abby verified link is blocking the gate.
            $vl_detail = [];
            foreach ((array)($resolved_destinations['all_verified_destinations'] ?? []) as $vl_row) {
                $vl_detail[] = ($vl_row['type'] ?? '?') . ':' .
                               ($vl_row['activity_level'] ?? 'none') . ':' .
                               ($vl_row['is_cta_eligible'] ? 'cta_yes' : 'cta_no');
            }
            if (!empty($vl_detail)) {
                error_log(sprintf(
                    '[TMW-POOL-GATE] verified_link_detail post_id=%d links=%s',
                    (int) $post->ID,
                    implode('; ', $vl_detail)
                ));
            }
        }

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
        $intro_paragraphs = self::build_seed_intro_paragraphs($name, $editor_seed, $active_platforms, $intro, $second_intro, $link_evidence_summary);

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Reviewed bio evidence layer ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
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
            // generic model-bios.php template ($bio) ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â that text is not a real bio.
            // Reviewed performer bios are injected into intro_paragraphs above.
            'about_section_paragraphs' => $has_specific_about ? (!empty($seed_about) ? $seed_about : []) : [],
            'fans_like_section_paragraphs' => self::build_fans_like_paragraphs($context, $name, $model_data_gate, $editor_seed),
            'features_section_paragraphs' => [
                $features_intro
                . ' Focus on room freshness, handle consistency, playback quality, and chat readability.'
                . (!empty($secondary_visible_phrases[1]) ? ' For ' . $secondary_visible_phrases[1] . ' comparisons, focus on chat usability and room quality on your device.' : ''),
            ],
            'features_section_html' => self::join_html_blocks([
                self::render_varied_features($name, $tags, $primary_platform_label, $seed, count($active_platforms)),
                $keyword_coverage_html,
            ]),
            'comparison_section_paragraphs' => $comparison_paragraphs,
            'faq_items' => $faq_items,
            'secondary_heading_slots' => $secondary_heading_slots,
            'link_evidence_summary' => $link_evidence_summary,
        ]);

        if (!$model_data_gate['is_sufficient']) {
            $renderer_payload = array_merge($renderer_payload, self::build_sparse_model_payload($name, $active_platforms, $model_data_gate, $rankmath_keywords, $extra, $link_evidence_summary));
        }
        $renderer_payload = self::maybe_add_sparse_wordcount_support_paragraph($renderer_payload, $name, $active_platforms, !$model_data_gate['is_sufficient']);

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Phrase deduplication (plain-string bags only, before render) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
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

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ v5.8.17: TemplatePool primary mode ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // When the admin manually clicked Generate AND the data gate passed,
        // replace the legacy paragraph bags in $renderer_payload with content
        // built from TemplatePool sections. The HTML blocks (CTA buttons,
        // affiliate links, external_info_html) are kept unchanged from
        // $support_payload so no affiliate routing is affected.

        // v5.8.18: guard_check log ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â fires on every manual Generate regardless
        // of whether TemplatePool runs. Confirms _manual_generate flag arrives
        // and reports the data-gate state for diagnostics.
        if (defined('WP_DEBUG') && WP_DEBUG && !empty($pack['_manual_generate'])) {
            error_log(sprintf(
                '[TMW-POOL-WIRE] guard_check post_id=%d manual=%d sufficient=%d',
                (int) $post->ID,
                (int) !empty($pack['_manual_generate']),
                (int) !empty($model_data_gate['is_sufficient'])
            ));
        }

        if (!empty($pack['_manual_generate']) && !empty($model_data_gate['is_sufficient'])) {
            // v5.8.22: pass $rankmath_keywords and $extra so the primary builder
            // can use dynamic secondary keywords for heading synthesis and intro placement.
            $renderer_payload = self::build_template_pool_primary_payload(
                $post,
                $renderer_payload,
                $name,
                $primary_platform_label,
                $active_platforms,
                $resolved_destinations,
                $cta_links,
                $verified_destination_rows,
                $faq_items,
                $rankmath_keywords,
                $extra
            );
        }
        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ End TemplatePool primary mode injection ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬

        $content = ModelPageRenderer::render($name, $renderer_payload);

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ v5.8.21: Inject keyword-rich H2 for TemplatePool-primary pages ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // The renderer always uses "Official Profile Access" as the first H2.
        // For manual Generate with TemplatePool, we replace that heading with a
        // model-name + platform + evidence-signal heading that naturally contains
        // the focus keyword and a Rank Math secondary chip.
        // This runs ONLY on manual Generate (pack has _manual_generate).
        if (!empty($pack['_manual_generate']) && !empty($model_data_gate['is_sufficient'])) {
            // Read evidence availability directly for heading variant selection.
            $h2_has_turn_ons     = class_exists(\TMWSEO\Engine\Content\ModelResearchEvidence::class)
                ? trim((string)(\TMWSEO\Engine\Content\ModelResearchEvidence::get_raw_fields((int)$post->ID)['turn_ons'] ?? '')) !== ''
                : false;
            $h2_has_private_chat = class_exists(\TMWSEO\Engine\Content\ModelResearchEvidence::class)
                ? !empty(\TMWSEO\Engine\Content\ModelResearchEvidence::filter_private_chat_items(
                    (string)(\TMWSEO\Engine\Content\ModelResearchEvidence::get_raw_fields((int)$post->ID)['private_chat'] ?? '')
                  ))
                : false;
            // v5.8.22: pass rankmath_keywords for dynamic concept detection in heading
            $tp_intro_h2 = self::build_templatepool_intro_h2(
                $name,
                $primary_platform_label,
                $h2_has_turn_ons,
                $h2_has_private_chat,
                (int) $post->ID,
                $rankmath_keywords
            );
            if ($tp_intro_h2 !== '') {
                // Replace the renderer's first H2 (Official Profile Access) with
                // the keyword-rich heading. Only replaces the first match.
                $content = preg_replace(
                    '/<h2>\s*Official Profile Access\s*<\/h2>/iu',
                    $tp_intro_h2,
                    $content,
                    1
                ) ?: $content;
            }

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ v5.8.25/v5.8.27: Extra-keyword H2 post-render replacements ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            // ModelPageRenderer hard-codes its H2 text and does not read payload
            // *_h2 keys. We apply name-bearing H2 overrides here via preg_replace
            // on the fully rendered HTML, reading actual target strings stored in
            // _extra_kw_h2_overrides by build_template_pool_primary_payload().
            // v5.8.27: also targets evidence-prepend headings "Turn Ons" and
            // "Private Chat Options" injected by ModelResearchEvidence::prepend_sections()
            // which run after the renderer and bypass all previous H2 logic.
            // Only runs when TemplatePool primary ran (flag set in payload).
            $h2_overrides = is_array($renderer_payload['_extra_kw_h2_overrides'] ?? null)
                ? $renderer_payload['_extra_kw_h2_overrides']
                : [];
            if (!empty($h2_overrides)) {
                // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Turn-ons H2: apply only once ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
                // Evidence-prepend heading (<h2>Turn Ons</h2>) takes priority.
                // At this point evidence prepend has NOT run yet, so we detect
                // its future presence via $h2_has_turn_ons (evidence field exists).
                // If evidence exists, skip renderer fallback here ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â the
                // post-evidence-prepend block will replace <h2>Turn Ons</h2> instead.
                // If evidence does NOT exist, apply renderer fallback now.
                $turn_ons_h2 = trim((string) ($h2_overrides['turn_ons_h2'] ?? ''));
                if ($turn_ons_h2 !== '' && !$h2_has_turn_ons) {
                    // No evidence ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ evidence-prepend will not add "Turn Ons" H2 ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢
                    // replace the renderer's "About {name}" heading as the only target.
                    $content = preg_replace(
                        '/<h2>\s*About\s+' . preg_quote($name, '/') . '\s*<\/h2>/iu',
                        '<h2>' . esc_html($turn_ons_h2) . '</h2>',
                        $content,
                        1
                    ) ?: $content;
                }
                // (When $h2_has_turn_ons is true, the post-evidence-prepend block
                //  handles <h2>Turn Ons</h2> ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â nothing to do here.)

                // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Private-chat H2: apply only once ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
                // Same logic: if private-chat evidence exists, evidence-prepend will
                // inject <h2>Private Chat Options</h2>; post-evidence block handles it.
                // If no evidence, replace renderer's "Where to Watch Live" now.
                $private_chat_h2 = trim((string) ($h2_overrides['private_chat_h2'] ?? ''));
                if ($private_chat_h2 !== '' && !$h2_has_private_chat) {
                    $content = preg_replace(
                        '/<h2>\s*Where to Watch Live\s*<\/h2>/iu',
                        '<h2>' . esc_html($private_chat_h2) . '</h2>',
                        $content,
                        1
                    ) ?: $content;
                }
                // (When $h2_has_private_chat is true, the post-evidence-prepend block
                //  handles <h2>Private Chat Options</h2> ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â nothing to do here.)

                // "Before You ClickÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦" ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ before_click_h2
                $before_click_h2 = trim((string) ($h2_overrides['before_click_h2'] ?? ''));
                $before_click_h2 = $before_click_h2 !== ''
                    ? $before_click_h2
                    : "Before You Click " . $name . "'s Confirmed Profile";
                $content = preg_replace(
                    '/<h2>\s*Before You Click\b[^<]*<\/h2>/iu',
                    '<h2>' . esc_html($before_click_h2) . '</h2>',
                    $content,
                    1
                ) ?: $content;
                // "Common Profile Questions" ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ questions_h2
                $questions_h2 = trim((string) ($h2_overrides['questions_h2'] ?? ''));
                $questions_h2 = $questions_h2 !== ''
                    ? $questions_h2
                    : 'Common ' . $name . ' Profile Questions';
                $content = preg_replace(
                    '/<h2>\s*Common Profile Questions\s*<\/h2>/iu',
                    '<h2>' . esc_html($questions_h2) . '</h2>',
                    $content,
                    1
                ) ?: $content;
            }
            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ End v5.8.27 extra-keyword H2 post-render replacements ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        }
        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ End keyword-rich H2 injection ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬

        $content = self::split_long_paragraphs($content);
        $content = self::cleanup_model_content($content, $name);
        $content = self::ensure_minimum_useful_depth($content, $name, $active_platforms, $resolved_destinations, $primary_platform_label, $seed);
        $content = self::apply_lightweight_content_guardrails($content, $name);

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Model Research Evidence prepend (v5.8.7) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // Single insertion point for the 3 operator-pasted seed sections
        // (About / Turn Ons / Private Chat Options). The helper:
        //   - reads the 3 _tmwseo_seed_external_* meta fields
        //   - humanizes them (denylist + canonicaliser + entity decode)
        //   - strips any prior wrapper-marker block (idempotent re-generation)
        //   - prepends a fresh block above the existing generated body
        // Existing body is NEVER modified ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â this is purely additive.
        if ( class_exists( \TMWSEO\Engine\Content\ModelResearchEvidence::class ) ) {
            $content = \TMWSEO\Engine\Content\ModelResearchEvidence::prepend_sections( (int) $post->ID, $content, (string) $post->post_title );
        }
        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ End Model Research Evidence prepend ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ v5.8.27: Evidence-prepend H2 keyword overrides ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // ModelResearchEvidence::prepend_sections() injects bare headings such as
        // <h2>Turn Ons</h2> and <h2>Private Chat Options</h2> above the generated
        // body. The earlier post-render H2 block (before evidence prepend) cannot
        // reach these headings because they do not exist yet at that point.
        // This pass runs immediately after evidence prepend and targets those exact
        // heading strings, replacing them with the keyword-enriched versions already
        // computed by build_template_pool_primary_payload() and stored in
        // $renderer_payload['_extra_kw_h2_overrides'].
        // Only fires on manual Generate when TemplatePool primary ran.
        if (!empty($pack['_manual_generate']) && !empty($model_data_gate['is_sufficient'])) {
            $ev_h2_overrides = is_array($renderer_payload['_extra_kw_h2_overrides'] ?? null)
                ? $renderer_payload['_extra_kw_h2_overrides']
                : [];
            if (!empty($ev_h2_overrides)) {
                $ev_turn_ons_h2 = trim((string) ($ev_h2_overrides['turn_ons_h2'] ?? ''));
                if ($ev_turn_ons_h2 !== '') {
                    $content = preg_replace(
                        '/<h2>\s*Turn Ons\s*<\/h2>/iu',
                        '<h2>' . esc_html($ev_turn_ons_h2) . '</h2>',
                        $content,
                        1
                    ) ?: $content;
                }
                $ev_private_chat_h2 = trim((string) ($ev_h2_overrides['private_chat_h2'] ?? ''));
                if ($ev_private_chat_h2 !== '') {
                    $content = preg_replace(
                        '/<h2>\s*Private Chat Options\s*<\/h2>/iu',
                        '<h2>' . esc_html($ev_private_chat_h2) . '</h2>',
                        $content,
                        1
                    ) ?: $content;
                }
            }
        }
        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ End v5.8.27 evidence-prepend H2 keyword overrides ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Final-pass deterministic copy cleanup (v5.8.8) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // Runs immediately after evidence prepend so it sees the full
        // generated body. The cleanup helper splits out the evidence block
        // before processing and restores it verbatim, so nothing inside the
        // <!-- tmwseo-seed-evidence:start --> markers is touched.
        if ( class_exists( \TMWSEO\Engine\Content\ModelCopyCleanup::class ) ) {
            $content = \TMWSEO\Engine\Content\ModelCopyCleanup::cleanup( $content, (string) $post->post_title );
        }
        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ End Final-pass deterministic copy cleanup ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Active Generate safeguards (right-sidebar Template path) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // These final TemplateContent guards run after evidence/copy cleanup but
        // before keyword heading placement. They preserve the operator evidence
        // block verbatim by only appending neutral support copy when needed.
        $content = self::expand_model_content_word_count($content, $active_platforms, $link_evidence_summary, $seed);
        $content = self::guard_model_focus_keyword_density($content, $name, $seed);

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Keyword heading enforcement (all modes share this post-render step) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        $enforcement = self::enforce_keyword_heading_placement($content, $rankmath_keywords, $name);
        $content = $enforcement['html'];
        $content = self::final_template_copy_cleanup($content);

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ v5.8.21: TemplatePool paragraph deduplication ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // When TemplatePool primary ran, the post-processing chain
        // (ensure_minimum_useful_depth, expand_model_content, guard_keyword_density)
        // can still append blocks whose opening sentences duplicate content
        // already in the TemplatePool sections. Remove exact-duplicate
        // <p> blocks and near-duplicate repeated safety paragraphs.
        if (!empty($pack['_manual_generate'])) {
            $content = self::dedupe_templatepool_output($content);
        }
        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ End TemplatePool deduplication ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ v5.8.24: Final-render cleanup (manual Generate only) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // Runs LAST on the fully assembled $content HTML, after all previous
        // passes (render, evidence prepend, copy cleanup, dedup) complete.
        // Fixes three issues that survive the payload/template layer:
        //   1. FAQ H3 grammar: "Report a fake X?" ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ "Can I report a fake X?"
        //   2. Duplicate "Live Chat Experience" H2 removal (keep better one)
        //   3. Page-level model-name density budget (target: 12 mentions)
        // Protected: first <p>, first <h2>, <a> anchors, evidence block, SEO fields.
        // Logs [TMW-POOL-DENSITY] always when WP_DEBUG is on.
        if (!empty($pack['_manual_generate'])) {
            $content = self::templatepool_final_render_cleanup(
                $content,
                $name,
                $primary_platform_label,
                (int) $post->ID
            );
        }

        // v5.8.32: Extra Keyword Coverage Guard — runs after density reduction so
        // inserted phrases are never substituted away by the density reducer.
        // Only fires on manual Generate with at least one Rank Math extra keyword.
        // Covers all 4 selected extras (Rank Math free supports 1 focus + 4 extras).
        if (!empty($pack['_manual_generate']) && !empty($rankmath_keywords)) {
            $content = self::guard_extra_keyword_coverage(
                $content,
                $rankmath_keywords,
                $name,
                $primary_platform_label,
                (int) $post->ID
            );
        }
        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ End v5.8.24 final-render cleanup ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ v5.8.17: TemplatePool primary (manual Generate only) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // No-op: TemplatePool primary mode ran before ModelPageRenderer::render()
        // above (see build_template_pool_primary_payload). If it ran successfully,
        // $content is already TemplatePool-primary output. If it fell back, $content
        // is the legacy output. Either way there is nothing to do here.
        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ End TemplatePool primary ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬

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

        // Fallback to Wikipedia ONLY when cta_links is empty ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â meaning no
        // platform username exists in either the profiles table or post meta
        // (build_platform_cta_links already tried both). If cta_links has
        // entries but render_guaranteed returned '' (URL resolution edge case),
        // do NOT substitute Wikipedia ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â that would put a generic webcam-model
        // link on a page that has real performer usernames.
        if ($guaranteed_outbound === '' && empty($cta_links)) {
            $guaranteed_outbound   = '<p>For background on live-cam performers, see <a href="https://en.wikipedia.org/wiki/Webcam_model" target="_blank" rel="noopener">this overview</a>.</p>';
            $wikipedia_fallback_used = true;
        }

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Watch section: confirmed outbound CTA + routed /go/ CTAs ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // Rank Math scans generated post_content, so include one real confirmed
        // outbound profile anchor when verified evidence provides a URL.
        $watch_html = self::join_html_blocks( [
            self::render_confirmed_outbound_watch_cta( $cta_links, $name ),
            self::render_primary_watch_cta( $cta_links, $name ),
            self::render_watch_cta_section( $cta_links, $name ),
        ] );

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Explore More / end section: ONE consolidated outbound link block ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // Generated SEO body content must use AffiliateLinkBuilder::build_seo_content_affiliate_url()
        // so Rank Math sees a real approved external affiliate href, not an
        // internal /go/ redirect. Do not add raw profile/registry fallbacks here.
        $ext_info_html = self::join_html_blocks([
            $guaranteed_outbound,
            $curated_external,
        ]);

        $link_evidence_summary = self::build_link_evidence_summary($resolved_destinations, $cta_links);
        $official_destination_paragraphs = self::build_official_destination_paragraphs($link_evidence_summary);
        $community_destination_paragraphs = self::build_community_destination_paragraphs($link_evidence_summary);
        $internal_links_html = self::join_html_blocks([
            self::render_internal_links($post),
            self::render_related_models($post, $name, $tags, $active_platforms),
        ]);

        return [
            'watch_section_html' => $watch_html,
            'comparison_section_html' => self::build_platform_comparison($post, $name, $cta_links, $comparison_copy, $editor_seed),
            // Middle destination sections stay text-only so non-live outbound
            // links appear only once in the final Official Links section.
            'official_destinations_section_html' => '',
            'official_destinations_section_paragraphs' => $official_destination_paragraphs,
            'community_destinations_section_html' => '',
            'community_destinations_section_paragraphs' => $community_destination_paragraphs,
            'internal_links_section_paragraphs' => [
                'Use these internal pages to continue to the model video archive, model directory, and category pages on this site.',
            ],
            'internal_links_html' => $internal_links_html,
            'related_models_html' => $internal_links_html,
            'explore_more_html' => '',
            // All visible outbound links consolidated here ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â Explore More is the
            // only place in rendered content where real external links appear.
            'external_info_html' => $ext_info_html,
            // v5.8.11-final-copy: official_links_section_paragraphs no longer
            // glues build_verification_process_paragraph() to a secondary-
            // keyword tail. We assemble three independent paragraphs:
            //   1. build_official_links_summary() ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â count + grouped families
            //      (the only place "Latest check:" appears).
            //   2. build_verification_process_paragraph() ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â short status note
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
                    ? self::build_secondary_link_keyword_paragraph($secondary_visible_phrases[2], $link_evidence_summary)
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
            'link_evidence_summary' => $link_evidence_summary,
            'verified_destination_families' => [
                'social' => (array) ($resolved_destinations['social_destinations'] ?? []),
                'link_hubs' => (array) ($resolved_destinations['link_hub_destinations'] ?? []),
                'personal' => (array) ($resolved_destinations['personal_site_destinations'] ?? []),
                'fan_platforms' => (array) ($resolved_destinations['fan_platform_destinations'] ?? []),
                'tube' => (array) ($resolved_destinations['tube_destinations'] ?? []),
            ],
        ];
        // External evidence payload bridge REMOVED in v5.8.7 ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â Model Research
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
        // v5.8.20: two-tier gate.
        //
        // Tier A (strict) ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â original threshold, unchanged.
        //   Requires specific_fact_count >= 4 and the OR-condition.
        //   Covers models with tags, comparison copy, or seed facts.
        //
        // Tier B (active-platform relaxed) ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â new path.
        //   Allows TemplatePool when a model has a confirmed active live platform
        //   (platform_links >= 1, active_platforms >= 1) but only 3 specific facts
        //   (e.g. 1 platform link + 1 active platform + 1 comparison_copy = 3).
        //   Strictly requires the live-platform evidence; never triggers for
        //   models with platform_links=0 or active_platforms=0.
        $is_sufficient_strict = $signals['platform_links'] >= 1
            && $signals['active_platforms'] >= 1
            && $specific_fact_count >= 4
            && ($signals['tags'] >= 1 || $signals['comparison_copy'] >= 1 || $signals['active_platforms'] >= 2);

        $is_sufficient_active_platform = $signals['platform_links'] >= 1
            && $signals['active_platforms'] >= 1
            && $specific_fact_count >= 3;

        $is_sufficient = $is_sufficient_strict || $is_sufficient_active_platform;

        // v5.8.19 diagnostic: log the relaxed gate path when it is the deciding factor.
        if (defined('WP_DEBUG') && WP_DEBUG && !$is_sufficient_strict && $is_sufficient_active_platform) {
            error_log(sprintf(
                '[TMW-POOL-GATE] relaxed_active_platform_gate post_id=%d specific_fact_count=%d',
                (int) $post->ID,
                $specific_fact_count
            ));
        }

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
    public static function build_sparse_model_payload(string $name, array $active_platforms, array $gate, array $rankmath_additional = [], array $extra = [], array $link_evidence_summary = []): array {
        $platform_text = self::format_platform_list($active_platforms, 'available platforms');
        $secondary_visible_phrases = self::select_visible_secondary_keyword_phrases($rankmath_additional, $extra);
        $secondary_heading_slots = self::build_secondary_heading_slots(self::select_heading_safe_secondary_keyword_phrases($name, $rankmath_additional, $extra));
        $reason = (string) ($gate['reason'] ?? 'insufficient_performer_data');
        $signals = is_array($gate['signals'] ?? null) ? $gate['signals'] : [];
        $active_platform_count = count($active_platforms);
        $has_extra_link_evidence = !empty($link_evidence_summary['has_extra_links']);
        $has_meaningful_structure = ((int) ($signals['platform_links'] ?? 0) >= 1)
            && (
                (int) ($signals['active_platforms'] ?? 0) >= 1
                || (int) ($signals['comparison_copy'] ?? 0) >= 1
                || (int) ($signals['tags'] ?? 0) >= 1
            );

        if ($active_platform_count === 1) {
            $intro_first = $platform_text . ' is the confirmed live-room option from this check. Start there for live access.';
            $comparison_lines = [
                $has_extra_link_evidence
                    ? 'Confirm the handle and check recent room activity before choosing any destination.'
                    : self::build_confirmed_live_profile_only_sentence($platform_text),
            ];
        } elseif ($active_platform_count >= 2) {
            $intro_first = 'Live profiles are currently available on ' . $platform_text . '. Open one live room first, then compare the rest if needed.';
            $comparison_lines = [
                'If multiple platforms are active, start with your familiar platform and compare load speed, chat controls, and privacy settings before choosing where to watch.',
            ];
        } else {
            $intro_first = 'No live-room profile is confirmed active in this check.';
            $comparison_lines = [
                $has_extra_link_evidence
                    ? 'When live status is unclear, verify the handle on the available verified destinations.'
                    : 'No confirmed profile links are available in this check, so this page avoids routing claims until evidence is added.',
            ];
        }

        if ($active_platform_count === 1) {
            $first_link_answer = $has_extra_link_evidence
                ? 'Open the ' . $platform_text . ' room first; use additional verified destinations only for updates.'
                : 'Open the ' . $platform_text . ' room first; it is the only confirmed profile link currently listed.';
        } elseif ($active_platform_count >= 2) {
            $first_link_answer = 'Open one of the confirmed live rooms first, then compare the others if needed.';
        } else {
            $first_link_answer = $has_extra_link_evidence
                ? 'No live room is confirmed active right now; use verified destinations for checks and updates.'
                : 'No confirmed profile links are available right now.';
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

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ v5.8.11-final-copy: sparse intro/features no longer carry secondary
        //    keyword tails. Tails were causing enforce_keyword_heading_placement()
        //    to inject awkward H3s ("Anisyia Cam Show") inside Official Profile
        //    Access. Each secondary phrase still surfaces in body via the
        //    Features prose paragraphs below (Rank Math coverage preserved) and,
        //    for [2], via inject_sparse_secondary_keyword_into_faq() / the
        //    Official Links keyword paragraph in build_model_renderer_support_payload().
        $sparse_features_paragraphs = [];
        $seen_feature_sentences = [];
        $max_feature_sentences = 1;
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
            'intro_paragraphs' => array_values(array_filter([
                $intro_first,
                $has_extra_link_evidence ? 'Use the additional links below only for updates and profile checks.' : '',
            ], 'strlen')),
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

        $evidence = is_array($payload['link_evidence_summary'] ?? null) ? $payload['link_evidence_summary'] : [];
        $has_extra_link_evidence = !empty($evidence['has_extra_links']);
        $support_line = $has_extra_link_evidence
            ? 'Confirm the profile handle, recent activity, playback on your device, and payment/privacy controls before choosing where to chat. A quick check also helps you spot stale mirrors or copied profile pages.'
            : 'Confirm the profile handle, recent activity, playback on your device, and payment/privacy controls before choosing where to chat. Keep the first click focused on the confirmed live profile.';
        $faq_items = is_array($payload['faq_items'] ?? null) ? $payload['faq_items'] : [];
        $stale_profile_faq_question = 'How do I avoid stale or copied profile links?';
        $stale_profile_faq_answer = $has_extra_link_evidence
            ? 'Start from the live profile shown on this page, then use the additional links below only for updates and profile checks. Match the handle, look for recent activity, and avoid mirror pages that copy names or photos without a clear platform profile.'
            : 'Start from the confirmed live profile shown on this page. Match the handle, look for recent activity, and avoid mirror pages that copy names or photos without a clear platform profile.';
        $has_stale_profile_faq = false;
        foreach ($faq_items as $idx => $faq_item) {
            if (!is_array($faq_item)) {
                continue;
            }
            if (trim((string) ($faq_item['q'] ?? '')) === $stale_profile_faq_question) {
                $faq_items[$idx]['a'] = $stale_profile_faq_answer;
                $has_stale_profile_faq = true;
                break;
            }
        }
        if (!$has_stale_profile_faq) {
            array_splice($faq_items, 1, 0, [[
                'q' => $stale_profile_faq_question,
                'a' => $stale_profile_faq_answer,
            ]]);
        }
        $payload['faq_items'] = $faq_items;

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
            'internal_links_section_paragraphs',
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
        foreach (['watch_section_html', 'official_destinations_section_html', 'community_destinations_section_html', 'internal_links_html', 'external_info_html', 'explore_more_html'] as $html_key) {
            $html = trim((string) ($payload[$html_key] ?? ''));
            if ($html !== '') {
                $parts[] = $html;
            }
        }

        return self::estimate_rankmath_word_count(implode("\n", $parts));
    }

    /**
     * @param array<string,mixed> $pack
     * @param string[] $extra
     * @param array<int,array<string,mixed>> $verified_destination_rows
     * @return string[]
     */
    private static function select_body_safe_rankmath_keywords(array $pack, array $extra, string $name, array $verified_destination_rows): array {
        $rankmath_source = !empty($pack['rankmath_additional'])
            ? (array) $pack['rankmath_additional']
            : $extra;

        return array_slice(
            ModelBodySafety::filter_body_phrases($rankmath_source, $name, $verified_destination_rows),
            0,
            4
        );
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
        // PR-615: 'porn' removed from heading-safe ban list. Platform-intent phrases like
        // "anisyia livejasmin porn" are approved SEO keywords for this adult cam directory.
        // xxx and sex remain blocked as non-specific spam terms.
        if (preg_match('/\b(?:free|cheap|best|top|ultimate|instant|no\s+1|guaranteed|xxx|sex)\b/iu', $phrase_lower)) {
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

    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
    // BIO EVIDENCE LAYER
    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬

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
     * manual review ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â this layer reads only the reviewed copy, never raw
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

        // Word-count sanity: bio should be 30ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“250 words (editor responsible for
        // the 60ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“110 target; we accept a wider range to avoid silently dropping
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

    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
    // EXTERNAL PROFILE EVIDENCE (v5.8.0ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“v5.8.6) ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â REMOVED in v5.8.7.
    // The 3-field model-research evidence flow is now handled directly by
    // \TMWSEO\Engine\Content\ModelResearchEvidence::prepend_sections() called
    // from each generation save path. There is no longer a renderer-payload
    // bridge.
    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
    // KEYWORD HEADING ENFORCEMENT  (post-render, all modes)
    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬

    /**
     * Ensure every configured Rank Math additional keyword gets a deterministic
     * placement result in the rendered HTML.
     *
     * For each keyword:
     *   1. Already in a heading?          ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ placed_heading
     *   2. Not in heading but in body?    ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ placed_body_only  (H3 injected
     *                                       only inside SAFE editorial
     *                                       sections ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â see ALLOWED_H3_SECTIONS
     *                                       below)
     *   3. Too awkward for heading?       ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ skipped (reason recorded)
     *
     * v5.8.11-final-copy hardening:
     *   - Name-bearing keywords (phrase contains the model name as a word) are
     *     never injected as an H3 anywhere. They mirror the rejection rule in
     *     select_heading_safe_secondary_keyword_phrases() so the two systems
     *     no longer disagree. If the phrase is in body, status =
     *     placed_body_only; otherwise skipped (no fallback append).
     *   - Section-context guard: H3 is injected only inside Features and FAQ
     *     sections. Matches in Official Profile Access, Where to Watch Live,
     *     Other Official Destinations, Social ProfilesÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦, and Official Links
     *     and Profiles / Where Are the Official LinksÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ are downgraded to
     *     placed_body_only with no H3.
     *   - Fallback ("not in body") still appends a single H3 + sentence to
     *     the Features section, but only for safe non-name-bearing phrases.
     *
     * The method returns modified HTML and a placement report for diagnostics.
     *
     * @param  string   $html              Rendered model page HTML.
     * @param  string[] $rankmath_keywords Up-to-5 Rank Math additional keywords.
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
        // Links and Profiles" ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â both forms are listed.
        $disallowed_section_patterns = [
            '/^Official\s+Profile\s+Access\b/iu',
            '/^Where\s+to\s+Watch\s+Live\b/iu',
            '/^Other\s+Official\s+Destinations\b/iu',
            '/^Social\s+Profiles\b/iu',
            '/^Where\s+Are\s+the\s+Official\s+Links\b/iu',
            '/^Official\s+Links\s+and\s+Profiles\b/iu',
            // v5.8.22: prevent duplicate "Live Chat Experience for X and Y" H3 injection
            // into the features section which already has a "Live Chat Experience" H2.
            '/^Live\s+Chat\s+Experience\b/iu',
            '/^Before\s+You\s+Click\b/iu',
            '/^More\s+Pages\s+for\b/iu',
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
            // v5.8.11-final-copy: name-bearing rejection ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â mirrors
            // select_heading_safe_secondary_keyword_phrases() line for
            // is_heading_safe_secondary_phrase() ("name is a word in phrase").
            $is_name_bearing = (
                $name_lower !== ''
                && (bool) preg_match('/\b' . preg_quote($name_lower, '/') . '\b/u', $kw_lower)
            );
            $too_short      = ($word_count_kw !== false && $word_count_kw < 2);
            $too_long       = (mb_strlen($kw, 'UTF-8') > 72);
            $has_punct      = (bool) preg_match('/[,;:|\/]/', $kw);
            // PR-615: 'porn' removed ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â platform-intent phrases ("model livejasmin porn") are valid for this site.
            $has_banned     = (bool) preg_match('/\b(xxx|sex|free|cheap|instant|guaranteed)\b/iu', $kw);

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

            // 3. Not in body or heading ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ append a minimal H3 + one-sentence
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
        $lines[] = 'If a keyword cannot fit naturally in a heading, weave it into body prose instead ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â do NOT skip it silently.';

        $has_placeable = false;
        foreach ($slot_section_map as $slot => $label) {
            $phrases = array_values(array_filter(array_map('trim', (array)($heading_slots[$slot] ?? [])), 'strlen'));
            if (empty($phrases)) {
                continue;
            }
            foreach ($phrases as $phrase) {
                $lines[]      = 'ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¢ ' . $label . ': "' . $phrase . '"';
                $has_placeable = true;
            }
        }

        $unusable = array_values(array_filter(array_map('trim', (array)($heading_slots['unusable'] ?? [])), 'strlen'));
        if (!empty($unusable)) {
            $lines[] = 'ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¢ Body-only (heading not possible): "' . implode('", "', $unusable) . '" ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â use in paragraph prose only.';
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
     * @param  array<string,string[]> $paragraph_bags  Map of section key ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ string[].
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
        $excluded_primary_cam_rows = [];
        foreach ($exclude_targets as $target) {
            $url = strtolower(rtrim(trim((string) ($target['url'] ?? '')), '/'));
            if ($url !== '') {
                $exclude_urls[$url] = true;
            }
            $platform = sanitize_key((string) ($target['platform'] ?? ''));
            $label = trim((string) ($target['label'] ?? ''));
            if (in_array($platform, ['livejasmin', 'jasmin'], true)) {
                // Do not synthesize a CAM row from an already-rendered target.
                // Final live wording must be based on the exact verified row with
                // explicit is_active + active/very_active metadata.
                $excluded_primary_cam_rows['livejasmin'] = null;
            }
        }

        $grouped = [];
        $excluded_primary_cam_rows = array_filter($excluded_primary_cam_rows, 'is_array');
        if (!empty($excluded_primary_cam_rows)) {
            $grouped[VerifiedLinksFamilies::FAMILY_CAM] = array_values($excluded_primary_cam_rows);
        }
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
            if ($family === VerifiedLinksFamilies::FAMILY_CAM && in_array($type, ['livejasmin', 'jasmin'], true) && isset($excluded_primary_cam_rows['livejasmin'])) {
                continue;
            }
            $frontend_url = self::get_frontend_verified_link_href($entry);
            $frontend_key = strtolower(rtrim($frontend_url, '/'));
            if ($family === VerifiedLinksFamilies::FAMILY_CAM && (isset($exclude_urls[$url_key]) || ($frontend_key !== '' && isset($exclude_urls[$frontend_key])))) {
                if (!in_array($type, ['livejasmin', 'jasmin'], true)) {
                    // Keep secondary cam platforms visible in the grouped cam-profile section.
                } else {
                    continue;
                }
            }
            if ($frontend_key !== '' && isset($seen['href:' . $frontend_key])) { continue; }
            if ($frontend_key !== '') { $seen['href:' . $frontend_key] = true; }
            $label = trim((string)($entry['label'] ?? ''));
            if ($label === '') { $label = (string) ($type_labels[$type] ?? ucfirst(str_replace('_', ' ', $type))); }
            $activity_note = trim((string)($entry['activity_note'] ?? ''));
            if ($activity_note !== '') {
                $label .= ' ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â ' . $activity_note;
            }
            $grouped[$family][] = array_merge($entry, [
                'type' => self::canonical_body_platform_slug($entry),
                'label' => $label,
                'url' => $frontend_url !== '' ? $frontend_url : $clean_url,
                'verified_url' => $clean_url,
                'family' => $family,
                'activity_level' => sanitize_key((string) ($entry['activity_level'] ?? 'unknown')),
            ]);
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
                if (!empty($row['is_static_label'])) {
                    $items .= '<li>' . esc_html($anchor_text) . '</li>';
                    continue;
                }
                $items .= '<li><a href="' . esc_url((string) $row['url']) . '" target="_blank" rel="' . esc_attr(self::verified_external_link_rel($row)) . '">' . esc_html($anchor_text) . '</a></li>';
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

        return '<h3>' . esc_html('Find This Profile Elsewhere') . '</h3>'
            . implode('', $chunks);
    }


    /**
     * Canonical platform identity used by the final body-render live gate.
     */
    private static function canonical_body_platform_slug(array $row): string {
        $raw = (string) ($row['type'] ?? $row['platform'] ?? $row['platform_key'] ?? '');
        if (class_exists(AffiliateLinkBuilder::class) && method_exists(AffiliateLinkBuilder::class, 'canonical_platform_slug')) {
            $canonical = AffiliateLinkBuilder::canonical_platform_slug($raw);
            if ($canonical !== '') {
                return $canonical === 'jasmin' ? 'livejasmin' : sanitize_key($canonical);
            }
        }

        $slug = sanitize_key($raw);
        if ($slug === 'jasmin') {
            return 'livejasmin';
        }
        return $slug;
    }

    /**
     * Final fail-closed live gate for any model-body live-link wording.
     *
     * @param array<string,mixed> $row Exact verified destination row.
     */
    private static function verified_body_live_link_is_renderable(array $row): bool {
        $type = self::canonical_body_platform_slug($row);
        if ($type === '') {
            return false;
        }

        $family = sanitize_key((string) ($row['family'] ?? ''));
        if ($family === '') {
            $family = VerifiedLinksFamilies::family_for($type);
        }
        if ($family !== VerifiedLinksFamilies::FAMILY_CAM) {
            return false;
        }

        $has_http_url = false;
        foreach (['verified_url', 'confirmed_url', 'profile_url', 'url'] as $key) {
            $url = trim((string) ($row[$key] ?? ''));
            if (self::is_confirmed_external_http_url($url)) {
                $has_http_url = true;
                break;
            }
        }
        if (!$has_http_url) {
            return false;
        }

        $gate_row = $row;
        $gate_row['type'] = $type;
        return ModelBodySafety::verified_link_is_live_eligible($gate_row);
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
            if (self::verified_body_live_link_is_renderable($row)) {
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

    private static function build_confirmed_live_profile_only_sentence(string $platform_text): string {
        $platform_text = trim((string) preg_replace('/\s+/', ' ', $platform_text));
        if ($platform_text === '' || in_array(strtolower($platform_text), ['available platforms', 'verified live platforms', 'the active platform', self::NEUTRAL_PLATFORM_FALLBACK], true)) {
            return 'This page currently lists one confirmed live profile only. Use that link as the primary access point and confirm the room status before joining.';
        }
        return 'This page currently lists the confirmed ' . $platform_text . ' profile only. Use that link as the primary access point and confirm the room status before joining.';
    }

    /**
     * @param string[] $tags
     */
    private static function format_model_tags_for_body(array $tags): string {
        $clean = array_values(array_filter(array_map(static function ($tag): string {
            return trim(str_replace('-', ' ', (string) $tag));
        }, $tags), 'strlen'));
        if (empty($clean)) {
            return 'live webcam shows';
        }

        $explicit = array_values(array_filter($clean, static fn(string $tag): bool => self::is_explicit_model_tag($tag)));
        if (count($explicit) >= 4) {
            return 'private-chat themes, interactive show options, roleplay-style requests, and media/chat features';
        }
        if (!empty($explicit) && count($clean) > 3) {
            $examples = array_slice($explicit, 0, 3);
            return 'private-chat themes such as ' . implode(', ', $examples) . ', plus related room features';
        }

        return implode(', ', array_slice($clean, 0, 6));
    }

    private static function is_explicit_model_tag(string $tag): bool {
        $tag = strtolower(trim(str_replace(['_', '-'], ' ', $tag)));
        if ($tag === '') {
            return false;
        }
        $needles = [
            'butt', 'plug', 'dildo', 'fingering', 'love bead', 'vibrator', 'joi', 'jerk', 'fetish',
            'foot fetish', 'close up', 'oil', 'striptease', 'pov', 'snapshot', 'nude', 'anal', 'cum',
        ];
        foreach ($needles as $needle) {
            if (strpos($tag, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $resolved_destinations
     * @param array<int,array<string,mixed>> $cta_links
     * @return array<string,mixed>
     */
    private static function build_link_evidence_summary(array $resolved_destinations, array $cta_links): array {
        $summary = is_array($resolved_destinations['source_of_truth_summary'] ?? null)
            ? (array) $resolved_destinations['source_of_truth_summary']
            : [];

        $live_rows = [];
        $live_keys = [];
        foreach ((array) ($resolved_destinations['all_verified_destinations'] ?? []) as $entry) {
            if (!is_array($entry) || !self::verified_body_live_link_is_renderable($entry)) {
                continue;
            }
            $key = self::canonical_body_platform_slug($entry) . '|' . strtolower(rtrim((string) ($entry['url'] ?? ''), '/'));
            if (isset($live_keys[$key])) {
                continue;
            }
            $live_keys[$key] = true;
            $live_rows[] = $entry;
        }

        foreach ($cta_links as $entry) {
            if (!is_array($entry) || !self::verified_body_live_link_is_renderable($entry)) {
                continue;
            }
            $key = self::canonical_body_platform_slug($entry) . '|' . strtolower(rtrim((string) ($entry['url'] ?? $entry['verified_url'] ?? ''), '/'));
            if (isset($live_keys[$key])) {
                continue;
            }
            $live_keys[$key] = true;
            $live_rows[] = $entry;
        }

        $live_platform_labels = array_values(array_unique(array_filter(array_map(static function (array $row): string {
            return trim((string) ($row['label'] ?? ''));
        }, $live_rows), 'strlen')));
        $live_count = count($live_platform_labels);
        $family_counts = [
            'cam_extra_count' => 0,
            'camsoda_count' => 0,
            'personal_site_count' => count((array) ($resolved_destinations['personal_site_destinations'] ?? [])),
            'fan_platform_count' => count((array) ($resolved_destinations['fan_platform_destinations'] ?? [])),
            'social_count' => count((array) ($resolved_destinations['social_destinations'] ?? [])),
            'link_hub_count' => count((array) ($resolved_destinations['link_hub_destinations'] ?? [])),
            'tube_count' => count((array) ($resolved_destinations['tube_destinations'] ?? [])),
        ];

        foreach ((array) ($resolved_destinations['all_verified_destinations'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $family = sanitize_key((string) ($entry['family'] ?? ''));
            $type = self::canonical_body_platform_slug($entry);
            if ($family === VerifiedLinksFamilies::FAMILY_CAM) {
                if (!self::verified_body_live_link_is_renderable($entry)) {
                    $family_counts['cam_extra_count']++;
                    if ($type === 'camsoda') {
                        $family_counts['camsoda_count']++;
                    }
                }
            }
        }

        $extra_count = (int) $family_counts['cam_extra_count']
            + (int) $family_counts['personal_site_count']
            + (int) $family_counts['fan_platform_count']
            + (int) $family_counts['social_count']
            + (int) $family_counts['link_hub_count']
            + (int) $family_counts['tube_count'];
        $verified_count = (int) ($summary['verified_count'] ?? 0);
        if ($verified_count <= 0) {
            $verified_count = count((array) ($resolved_destinations['all_verified_destinations'] ?? []));
        }
        $total_count = max($verified_count, $live_count + $extra_count);

        return array_merge($family_counts, [
            'live_count' => $live_count,
            'live_platform_labels' => $live_platform_labels,
            'extra_count' => $extra_count,
            'total_count' => $total_count,
            'has_live_profile' => $live_count > 0,
            'has_extra_links' => $extra_count > 0,
            'has_any_links' => ($live_count + $extra_count) > 0,
        ]);
    }

    /** @param array<string,mixed> $evidence */
    private static function build_official_destination_paragraphs(array $evidence): array {
        $parts = [];
        if ((int) ($evidence['camsoda_count'] ?? 0) > 0) {
            $parts[] = 'CamSoda';
        } elseif ((int) ($evidence['cam_extra_count'] ?? 0) > 0) {
            $parts[] = 'additional cam platforms';
        }
        if ((int) ($evidence['personal_site_count'] ?? 0) > 0) {
            $parts[] = 'personal sites';
        }
        if ((int) ($evidence['fan_platform_count'] ?? 0) > 0) {
            $parts[] = 'fan/support pages';
        }
        if (empty($parts)) {
            return [];
        }
        return [self::format_platform_list($parts, 'verified destinations') . ' are listed in the Official Links and Profiles section below. They are useful for following or support, but they are not live-room buttons.'];
    }

    /** @param array<string,mixed> $evidence */
    private static function build_community_destination_paragraphs(array $evidence): array {
        $parts = [];
        if ((int) ($evidence['tube_count'] ?? 0) > 0) {
            $parts[] = 'video channels';
        }
        if ((int) ($evidence['social_count'] ?? 0) > 0) {
            $parts[] = 'social profiles';
        }
        if ((int) ($evidence['link_hub_count'] ?? 0) > 0) {
            $parts[] = 'link hubs';
        }
        if (empty($parts)) {
            return [];
        }
        return [self::format_platform_list($parts, 'community destinations') . ' are listed below for updates, archives, and handle checks.'];
    }

    /** @param array<string,mixed> $evidence */
    private static function build_secondary_link_keyword_paragraph(string $phrase, array $evidence): string {
        $phrase = trim($phrase);
        if ($phrase === '') {
            return '';
        }
        // v5.8.31: Split by link-count context.
        // has_extra_links = false → single confirmed live profile only (e.g. Alice Schuster
        //   with 1 link). Neutral wording avoids a low-value model-name mention on pages
        //   that have no extra destinations to navigate toward anyway.
        // has_extra_links = true → multiple confirmed profiles present. $phrase is used so
        //   the Rank Math secondary keyword chip gets a natural body-text occurrence in the
        //   Official Links section (primary purpose of this helper).
        if (empty($evidence['has_extra_links'])) {
            return "When checking this profile's cam links, start with the confirmed live profile and avoid assuming extra destinations exist until they are verified.";
        }
        return 'When checking ' . $phrase . ' links, use the additional links below for profile checks, updates, fan pages, and support channels; they are separate from the live-room button.';
    }

    /**
     * @param array<int,array{platform:string,label:string,go_url:string,is_primary:bool,username:string}> $cta_links
     */
    private static function build_official_links_summary(string $name, array $cta_links, int $post_id, array $resolved_destinations = []): string {
        $resolved = !empty($resolved_destinations) ? $resolved_destinations : ModelDestinationResolver::resolve($post_id);
        $evidence = self::build_link_evidence_summary($resolved, $cta_links);
        $live_count = (int) ($evidence['live_count'] ?? 0);
        $extra_count = (int) ($evidence['extra_count'] ?? 0);
        $total_count = (int) ($evidence['total_count'] ?? 0);

        if ($live_count === 0 && $extra_count === 0) {
            return 'Latest check: no confirmed profile links found.';
        }
        if ($live_count === 1 && $extra_count === 0) {
            return 'Latest check: 1 confirmed live profile found.';
        }

        $types = [];
        if ($live_count > 0) {
            $types[] = $live_count . ' live profile' . ($live_count === 1 ? '' : 's');
        }
        if ((int) ($evidence['cam_extra_count'] ?? 0) > 0) {
            $types[] = (int) ($evidence['cam_extra_count'] ?? 0) . ' additional cam platform' . ((int) ($evidence['cam_extra_count'] ?? 0) === 1 ? '' : 's');
        }
        if ((int) ($evidence['personal_site_count'] ?? 0) > 0) {
            $types[] = (int) ($evidence['personal_site_count'] ?? 0) . ' personal site' . ((int) ($evidence['personal_site_count'] ?? 0) === 1 ? '' : 's');
        }
        if ((int) ($evidence['fan_platform_count'] ?? 0) > 0) {
            $types[] = (int) ($evidence['fan_platform_count'] ?? 0) . ' fan/support page' . ((int) ($evidence['fan_platform_count'] ?? 0) === 1 ? '' : 's');
        }
        if ((int) ($evidence['tube_count'] ?? 0) > 0) {
            $types[] = (int) ($evidence['tube_count'] ?? 0) . ' video channel' . ((int) ($evidence['tube_count'] ?? 0) === 1 ? '' : 's');
        }
        if ((int) ($evidence['social_count'] ?? 0) > 0) {
            $types[] = (int) ($evidence['social_count'] ?? 0) . ' social profile' . ((int) ($evidence['social_count'] ?? 0) === 1 ? '' : 's');
        }
        if ((int) ($evidence['link_hub_count'] ?? 0) > 0) {
            $types[] = (int) ($evidence['link_hub_count'] ?? 0) . ' link hub' . ((int) ($evidence['link_hub_count'] ?? 0) === 1 ? '' : 's');
        }

        return 'Latest check: ' . $total_count . ' confirmed profile link' . ($total_count === 1 ? '' : 's') . ' found, including ' . self::format_platform_list($types, 'verified categories') . '.';
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
    private static function build_seed_intro_paragraphs(string $name, array $editor_seed, array $active_platforms, string $fallback_intro, string $fallback_second, array $link_evidence_summary = []): array {
        $summary = trim((string) ($editor_seed['summary'] ?? ''));
        $active_platform_count = count($active_platforms);
        $has_extra_link_evidence = !empty($link_evidence_summary['has_extra_links']);
        if ($active_platform_count === 1) {
            $platform_text = self::format_platform_list($active_platforms, 'the active platform');
            $answer_line = $has_extra_link_evidence
                ? $platform_text . ' is the confirmed live-room option from this check. Start there for live access, then use verified non-live destinations only for follow-up or backup checks.'
                : $platform_text . ' is the confirmed live-room option from this check. Start there for live access.';
        } elseif ($active_platform_count > 1) {
            $platform_text = self::format_platform_list($active_platforms, 'verified live platforms');
            $answer_line = 'Confirmed live-room options are available on ' . $platform_text . '. Start with one verified room, then compare status after click-through.';
        } else {
            $answer_line = 'No live-room profile is confirmed active in this check.';
        }
        if ($summary === '') {
            if ($active_platform_count === 1 && !$has_extra_link_evidence) {
                return [
                    $answer_line,
                    self::build_confirmed_live_profile_only_sentence($platform_text),
                    'Confirm the username match, recent room activity, chat readability, and mobile playback stability after opening the room.',
                ];
            }
            return array_values(array_filter([
                $answer_line,
                $fallback_intro,
                $fallback_second,
                'Before you commit to one room, run a quick check: username match, recent room activity, chat readability, and mobile playback stability.',
                $has_extra_link_evidence ? 'Use the live section for room entry, then use verified non-live destinations for schedule checks, backup access, and handle verification.' : '',
            ], 'strlen'));
        }
        return array_values(array_filter([
            $answer_line,
            $summary,
            $has_extra_link_evidence ? 'Use verified non-live destinations for follow-up, support, or backup checks.' : '',
            'If activity changes, verify handle match and room quality before spending.',
        ], 'strlen'));
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

        $phrases = self::normalize_visible_secondary_keywords($keywords, $name, 4);
        if (empty($phrases)) {
            return '';
        }

        return '<p>Start with the confirmed live-room link first, then use the additional verified profiles below for platform checks, updates, fan pages, and support channels.</p>';
    }

    /**
     * @param string[] $phrases
     * @return string[]
     */
    private static function normalize_visible_secondary_keywords(array $phrases, string $name, int $limit = 4): array {
        $name_lc = mb_strtolower(trim($name), 'UTF-8');
        $out = [];
        $seen = [];
        foreach ($phrases as $phrase) {
            $clean = trim((string) $phrase);
            $clean = (string) preg_replace('/\s+/u', ' ', $clean);
            if ($clean === '') {
                continue;
            }
            $key = mb_strtolower($clean, 'UTF-8');
            if ($key === $name_lc || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $clean;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** @param string[] $phrases */
    private static function build_secondary_keyword_intro_sentence(string $name, array $phrases): string {
        $phrases = self::normalize_visible_secondary_keywords($phrases, $name, 4);
        if (empty($phrases)) {
            return '';
        }

        $keyword_list = self::format_human_list($phrases);
        return 'Fans searching for ' . $keyword_list . ' should start with the confirmed live room for ' . $name . '. Start with the confirmed live-room link first, then use the additional verified profiles below for platform checks, updates, fan pages, and support channels.';
    }

    /** @param string[] $items */
    private static function format_human_list(array $items): string {
        $items = array_values(array_filter(array_map('trim', $items), 'strlen'));
        $count = count($items);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $items[0];
        }
        if ($count === 2) {
            return $items[0] . ' or ' . $items[1];
        }
        $last = array_pop($items);
        return implode(', ', $items) . ', or ' . $last;
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

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Detect alternate Stripchat username ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
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
        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ End alternate username detection ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬

        if (empty($cta_links)) {
            $fallback = 'Use trusted official profile links and compare room features before you join ' . $name . '.';
            return $alt_username_note . '<p>' . esc_html($comparison_copy !== '' ? $comparison_copy : $fallback) . '</p>';
        }

        $usable_live_platforms = 0;
        foreach ($cta_links as $link) {
            $label = trim((string) ($link['label'] ?? ''));
            $username = trim((string) ($link['username'] ?? ''));
            if ($label !== '' && $username !== '') {
                $usable_live_platforms++;
            }
        }

        $guidance = $usable_live_platforms > 1
            ? 'When more than one live platform is available, compare them with the same checklist: room freshness, handle consistency, mobile playback, chat readability, and login friction.'
            : 'Use the confirmed live-room button first. Additional verified profiles can help with profile checks, updates, fan pages, and support channels, but they should not be treated as separate live-room entries unless the current status confirms it.';

        return $alt_username_note . '<p>' . esc_html($guidance) . '</p>';
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
            $rows[] = array_merge($entry, [
                'label' => (string) ($entry['label'] ?? ''),
                'url' => $url,
                'family' => $family,
                'activity_level' => (string) ($entry['activity_level'] ?? 'unknown'),
            ]);
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
            $rows[] = array_merge($entry, [
                'label' => (string) ($entry['label'] ?? ''),
                'url' => $url,
                'family' => $family,
                'activity_level' => (string) ($entry['activity_level'] ?? 'unknown'),
            ]);
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
            $items .= '<li><a href="' . esc_url($url) . '" target="_blank" rel="' . esc_attr(self::verified_external_link_rel($row)) . '">' . esc_html($text) . '</a></li>';
        }

        if ($items === '') {
            return '';
        }

        return '<ul>' . $items . '</ul>';
    }

    /**
     * Rel policy for generated verified external-profile links.
     *
     * The generated model body must not turn every outbound destination into
     * nofollow. Verified social, personal, tube and link-hub URLs are editorial
     * citations, while cam/fan platforms are commercial destinations. None of
     * these should carry nofollow in the generated SEO text.
     *
     * @param array<string,mixed> $row
     */
    private static function verified_external_link_rel(array $row): string {
        $family = sanitize_key((string) ($row['family'] ?? ''));
        if (in_array($family, [VerifiedLinksFamilies::FAMILY_CAM, VerifiedLinksFamilies::FAMILY_FANSITE], true)) {
            return 'sponsored noopener external';
        }

        return 'noopener external';
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
                $seo_url = AffiliateLinkBuilder::build_seo_content_affiliate_url('livejasmin', $username);
                if ($seo_url !== '') {
                    return $seo_url;
                }
            }

            return $url;
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

        $intro = 'If you enjoy this performer, you may also want to browse similar profile pages for more live-chat options.';
        if (!empty($active_platforms)) {
            $intro = 'If you enjoy this performer on ' . self::format_platform_list($active_platforms, $active_platforms[0]) . ', you may also want to compare a few similar model profiles.';
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

    /**
     * Render one confirmed outbound profile anchor directly in generated content.
     *
     * Rank Math cannot see later metabox/widget rendering, and internal /go/
     * routes do not satisfy its outbound-link check. Only use evidence-backed
     * external URLs already attached to CTA rows; never synthesize profile URLs.
     *
     * @param array<int,array<string,mixed>> $links
     */
    private static function render_confirmed_outbound_watch_cta(array $links, string $name): string {
        $target = self::pick_confirmed_outbound_watch_target($links);
        if (empty($target)) {
            return '';
        }

        $url = trim((string) ($target['url'] ?? ''));
        $label = trim((string) ($target['label'] ?? ''));
        if ($url === '' || $label === '') {
            return '';
        }

        $anchor_text = 'Watch ' . $name . ' on ' . $label;
        return '<p><a href="' . esc_url($url) . '" target="_blank" rel="sponsored noopener">' . esc_html($anchor_text) . '</a></p>';
    }

    /**
     * @param array<int,array<string,mixed>> $links
     * @return array{url:string,label:string}|array{}
     */
    private static function pick_confirmed_outbound_watch_target(array $links): array {
        $candidates = [];
        foreach ($links as $link) {
            if (!is_array($link) || !self::verified_body_live_link_is_renderable($link)) {
                continue;
            }

            $label = trim((string) ($link['label'] ?? ''));
            if ($label === '') {
                $label = 'live cam';
            }

            foreach (['seo_affiliate_url', 'confirmed_affiliate_url', 'verified_url', 'confirmed_url', 'profile_url', 'url'] as $key) {
                $url = trim((string) ($link[$key] ?? ''));
                if (!self::is_confirmed_external_http_url($url)) {
                    continue;
                }
                $candidates[] = [
                    'url' => $url,
                    'label' => $label,
                    'is_primary' => !empty($link['is_primary']),
                    'source' => (string) ($link['source'] ?? ''),
                ];
                break;
            }
        }

        if (empty($candidates)) {
            return [];
        }

        usort($candidates, static function (array $a, array $b): int {
            return (!empty($b['is_primary']) <=> !empty($a['is_primary']));
        });

        return [
            'url' => (string) $candidates[0]['url'],
            'label' => (string) $candidates[0]['label'],
        ];
    }

    private static function is_confirmed_external_http_url(string $url): bool {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        $site_host = function_exists('home_url') ? strtolower((string) wp_parse_url((string) home_url('/'), PHP_URL_HOST)) : '';
        return $site_host === '' || $host !== $site_host;
    }

    /**
     * Pick the best href for generated model-page watch anchors.
     *
     * For LiveJasmin, seo_affiliate_url resolves to the official AWEmpire
     * ctwmsg.com link. We prefer it over internal /go/ routes because Rank Math
     * evaluates saved post content, not the final redirect target.
     *
     * @param array<string,mixed> $link
     */
    private static function generated_watch_href(array $link): string {
        foreach (['seo_affiliate_url', 'confirmed_affiliate_url', 'verified_url', 'confirmed_url', 'profile_url', 'url'] as $key) {
            $url = trim((string) ($link[$key] ?? ''));
            if (self::is_confirmed_external_http_url($url)) {
                return $url;
            }
        }

        return trim((string) ($link['go_url'] ?? ''));
    }

    private static function render_primary_watch_cta(array $links, string $name): string {
        $confirmed = self::pick_confirmed_outbound_watch_target($links);
        $confirmed_url = strtolower(rtrim((string) ($confirmed['url'] ?? ''), '/'));
        foreach ($links as $link) {
            if (!is_array($link) || !self::verified_body_live_link_is_renderable($link)) {
                continue;
            }
            if (empty($link['is_primary'])) {
                continue;
            }

            $go_url = self::generated_watch_href($link);
            if ($go_url === '') {
                continue;
            }

            if ($confirmed_url !== '' && strtolower(rtrim($go_url, '/')) === $confirmed_url) {
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
            $url = self::generated_watch_href($l);
            $label = (string)($l['label'] ?? '');
            if ($url === '' || $label === '') continue;

            $lis .= '<li><a href="' . esc_url($url) . '" target="_blank" rel="sponsored noopener">' . esc_html($name . ' on ' . $label) . '</a></li>';
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

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Meta-only fallback ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
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
        $model_slug = '';
        if (function_exists('get_post_field')) {
            $model_slug = trim((string) get_post_field('post_name', $post->ID));
        }
        if ($model_slug === '') {
            $raw_slug_source = trim((string) ($post->post_name ?? $post->post_title ?? ''));
            $model_slug = function_exists('sanitize_title_with_dashes')
                ? sanitize_title_with_dashes($raw_slug_source)
                : strtolower((string) preg_replace('/[^A-Za-z0-9-]+/', '-', $raw_slug_source));
        }
        $model_slug = trim($model_slug, '-');

        $model_title = function_exists('get_the_title') ? trim((string) get_the_title($post->ID)) : '';
        if ($model_title === '') {
            $model_title = trim((string) ($post->post_title ?? 'this model'));
        }

        $links = [];
        $video_links = self::get_real_model_video_links((int) $post->ID, $model_title, $model_slug);
        if (!empty($video_links)) {
            $video = $video_links[0];
            $links[] = '<li><a href="' . esc_url($video['url']) . '">' . esc_html(self::model_video_anchor_text($model_title)) . '</a></li>';
        } elseif ($model_slug !== '') {
            self::log_suppressed_fake_video_archive_link((int) $post->ID, $model_title, $model_slug);
        }
        $links[] = '<li><a href="' . esc_url(home_url('/models/')) . '">Browse all models</a></li>';
        $links[] = '<li><a href="' . esc_url(home_url('/categories/')) . '">Browse categories</a></li>';

        $top_terms = [];
        $tags = function_exists('get_the_terms') ? get_the_terms($post, 'post_tag') : false;
        if (is_array($tags)) {
            usort($tags, static function (\WP_Term $a, \WP_Term $b): int {
                if ((int) $a->count === (int) $b->count) {
                    return strnatcasecmp((string) $a->name, (string) $b->name);
                }

                return (int) $b->count <=> (int) $a->count;
            });

            foreach ($tags as $tag) {
                $term_link = function_exists('get_term_link') ? get_term_link($tag) : '';
                if ((function_exists('is_wp_error') && is_wp_error($term_link)) || $term_link === '') {
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
            $categories = function_exists('get_the_terms') ? get_the_terms($post, 'category') : false;
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

                    $term_link = function_exists('get_term_link') ? get_term_link($category) : '';
                    if ((function_exists('is_wp_error') && is_wp_error($term_link)) || $term_link === '') {
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
     * Return real published video post permalinks connected to a model.
     *
     * The old model-page internal-link block guessed /videos/?model={slug}; that
     * query-string archive is not guaranteed to exist. This helper only returns
     * links backed by published video posts related through relation signals the
     * plugin already recognizes for video ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬Â model linking.
     *
     * @return array<int,array{title:string,url:string,post_id:int}>
     */
    private static function get_real_model_video_links(int $model_post_id, string $model_name, string $model_slug, int $limit = 2): array {
        $limit = max(1, min(5, $limit));
        $model_slug = trim($model_slug, '-');
        if ($model_slug === '' && $model_name !== '') {
            $model_slug = function_exists('sanitize_title_with_dashes')
                ? sanitize_title_with_dashes($model_name)
                : strtolower((string) preg_replace('/[^A-Za-z0-9-]+/', '-', $model_name));
            $model_slug = trim($model_slug, '-');
        }

        if ($model_post_id <= 0 && $model_slug === '') {
            return [];
        }

        $post_types = self::model_video_post_types();
        if (empty($post_types) || !function_exists('get_posts')) {
            return [];
        }

        $candidates = [];
        $seen_ids = [];
        foreach (self::model_video_relation_queries($model_post_id, $model_slug, $post_types) as $query_args) {
            $posts = get_posts($query_args);
            if (!is_array($posts) || empty($posts)) {
                continue;
            }

            foreach ($posts as $candidate) {
                $candidate_id = is_object($candidate) ? (int) ($candidate->ID ?? 0) : (int) $candidate;
                if ($candidate_id <= 0 || isset($seen_ids[$candidate_id])) {
                    continue;
                }
                $seen_ids[$candidate_id] = true;
                $candidates[] = $candidate;
            }
        }

        if (empty($candidates)) {
            return [];
        }

        usort($candidates, static function ($a, $b): int {
            $a_date = is_object($a) ? strtotime((string) ($a->post_date ?? $a->post_modified ?? '')) : 0;
            $b_date = is_object($b) ? strtotime((string) ($b->post_date ?? $b->post_modified ?? '')) : 0;
            if ($a_date === $b_date) {
                $a_id = is_object($a) ? (int) ($a->ID ?? 0) : (int) $a;
                $b_id = is_object($b) ? (int) ($b->ID ?? 0) : (int) $b;
                return $b_id <=> $a_id;
            }

            return $b_date <=> $a_date;
        });

        $links = [];
        foreach ($candidates as $candidate) {
            $video_id = is_object($candidate) ? (int) ($candidate->ID ?? 0) : (int) $candidate;
            if ($video_id <= 0 || !function_exists('get_permalink')) {
                continue;
            }

            $url = get_permalink($video_id);
            $url = is_string($url) ? trim($url) : '';
            if ($url === '' || strpos($url, '/videos/?model=') !== false) {
                continue;
            }

            $title = function_exists('get_the_title') ? trim((string) get_the_title($video_id)) : '';
            if ($title === '' && is_object($candidate)) {
                $title = trim((string) ($candidate->post_title ?? ''));
            }
            if ($title === '') {
                $title = trim($model_name) !== '' ? trim($model_name) . ' video' : 'Model video';
            }

            $links[] = [
                'title'   => $title,
                'url'     => $url,
                'post_id' => $video_id,
            ];

            if (count($links) >= $limit) {
                break;
            }
        }

        return $links;
    }

    /**
     * @return array<int,string>
     */
    private static function model_video_post_types(): array {
        $post_types = ['video', 'tmw_video', 'livejasmin_video', 'post'];
        if (function_exists('post_type_exists')) {
            $post_types = array_values(array_filter($post_types, static function (string $post_type): bool {
                return post_type_exists($post_type);
            }));
        }

        return array_values(array_unique($post_types));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function model_video_relation_queries(int $model_post_id, string $model_slug, array $post_types): array {
        $base = [
            'post_type'           => $post_types,
            'post_status'         => 'publish',
            'posts_per_page'      => 5,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'ignore_sticky_posts' => true,
        ];

        $queries = [];
        if ($model_slug !== '' && (!function_exists('taxonomy_exists') || taxonomy_exists('models'))) {
            $queries[] = $base + [
                'tax_query' => [[
                    'taxonomy' => 'models',
                    'field'    => 'slug',
                    'terms'    => [$model_slug],
                ]],
            ];
        }

        $meta_or = ['relation' => 'OR'];
        if ($model_post_id > 0) {
            foreach (['_tmw_model_id', '_tmwseo_model_id', 'model_id'] as $key) {
                $meta_or[] = [
                    'key'     => $key,
                    'value'   => (string) $model_post_id,
                    'compare' => '=',
                ];
            }
        }
        if ($model_slug !== '') {
            foreach (['_tmw_model_slug', '_tmwseo_model_slug', 'model_slug'] as $key) {
                $meta_or[] = [
                    'key'     => $key,
                    'value'   => $model_slug,
                    'compare' => '=',
                ];
            }
        }

        if (count($meta_or) > 1) {
            $queries[] = $base + ['meta_query' => $meta_or];
        }

        return $queries;
    }

    private static function model_video_anchor_text(string $model_title): string {
        return 'Watch a video from this model';
    }

    private static function log_suppressed_fake_video_archive_link(int $model_post_id, string $model_title, string $model_slug): void {
        $data = [
            'model_id'   => $model_post_id,
            'model_name' => $model_title,
            'model_slug' => $model_slug,
            'suppressed' => '/videos/?model=' . $model_slug,
        ];

        if (class_exists('\TMWSEO\Engine\Logs')) {
            Logs::info('internal_links', '[TMW-SEO-LINKS] Suppressed fake model video archive link; no real published video permalink found.', $data);
            return;
        }

        if (function_exists('error_log')) {
            error_log('[TMW-SEO-LINKS] Suppressed fake model video archive link; no real published video permalink found. ' . json_encode($data));
        }
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
            if (!is_array($link) || !self::verified_body_live_link_is_renderable($link)) {
                continue;
            }
            // Skip the primary platform ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â render_primary_watch_cta() already outputs
            // it as a prominent <p> CTA above this section. Including it again here
            // would produce a duplicate "Watch {name} on {platform}" entry.
            if (!empty($link['is_primary'])) {
                continue;
            }

            $url = self::generated_watch_href($link);
            $platform = (string)($link['label'] ?? '');
            if ($url === '' || $platform === '') {
                continue;
            }

            $items[] = '<li><a href="' . esc_url($url) . '" target="_blank" rel="sponsored noopener">' . esc_html('Watch Live on ' . $platform) . '</a></li>';

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
     * Build the generated-SEO outbound affiliate block from platform usernames.
     *
     * This block is the model-page counterpart to the video generated-content
     * affiliate link. It intentionally uses the global SEO-content resolver only:
     * no /go/ URL, no raw profile URL, and no registry-pattern fallback belongs
     * in generated body text used for Rank Math outbound scoring.
     *
     * Returns empty string unless an approved external affiliate URL exists.
     *
     * @param array<int,array{platform:string,label:string,go_url:string,is_primary:bool,username:string}> $links
     */
    private static function render_guaranteed_external_platform_links(array $links, string $name): string {
        $targets = self::build_guaranteed_external_platform_targets($links);
        return self::render_guaranteed_external_platform_links_from_targets($targets);
    }

    /**
     * @param array<int,array{platform:string,label:string,go_url:string,is_primary:bool,username:string}> $links
     * @return array<int,array{platform:string,label:string,url:string,render:bool}>
     */
    private static function build_guaranteed_external_platform_targets(array $links): array {
        if (empty($links)) {
            return [];
        }

        $has_livejasmin = false;
        foreach ($links as $link) {
            if (sanitize_key((string) ($link['platform'] ?? '')) === 'livejasmin') {
                $has_livejasmin = true;
                break;
            }
        }

        $priority = ['camsoda' => 0, 'livejasmin' => 1, 'stripchat' => 2];
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
        $renderable_count = 0;
        foreach ($links as $link) {
            $platform = sanitize_key((string) ($link['platform'] ?? ''));
            $username = trim((string) ($link['username'] ?? ''));
            $label    = trim((string) ($link['label'] ?? ''));
            if ($platform === '' || $username === '' || $label === '' || isset($seen[$platform])) {
                continue;
            }

            $external_url = AffiliateLinkBuilder::build_seo_content_affiliate_url($platform, $username);
            if ($external_url === '') {
                self::log_model_affiliate_event('skipped_missing_affiliate_config', [
                    'platform' => $platform,
                    'username' => $username,
                ]);
                continue;
            }

            self::log_model_affiliate_event('external_link_added', [
                'platform' => $platform,
                'username' => $username,
                'host' => (string) wp_parse_url($external_url, PHP_URL_HOST),
            ]);

            $render_target = $platform !== 'livejasmin';
            $targets[]       = [
                'platform' => $platform,
                'label' => $label,
                'url' => $external_url,
                'render' => $render_target,
            ];
            $seen[$platform] = true;
            if ($render_target) {
                $renderable_count++;
            }
            if ($renderable_count >= 2 && (!$has_livejasmin || !empty($seen['livejasmin']))) {
                break;
            }
        }

        return $targets;
    }

    /**
     * Caller-level audit logging for generated model SEO affiliate decisions.
     *
     * URL decision logic stays centralized in AffiliateLinkBuilder; this method
     * only adds model-generation context to the global result.
     *
     * @param array<string,mixed> $data
     */
    private static function log_model_affiliate_event(string $event, array $data = []): void {
        if (!class_exists(Logs::class)) {
            return;
        }

        Logs::info('model_affiliate', '[TMW-MODEL-AFFILIATE] ' . $event, $data);
    }

    /**
     * @param array<int,array{platform:string,label:string,url:string,render?:bool}> $targets
     */
    private static function render_guaranteed_external_platform_links_from_targets(array $targets): string {
        if (empty($targets)) {
            return '';
        }

        $items = [];
        foreach ($targets as $target) {
            if (array_key_exists('render', $target) && empty($target['render'])) {
                continue;
            }

            $url   = trim((string) ($target['url'] ?? ''));
            $label = trim((string) ($target['label'] ?? ''));
            if ($url === '' || $label === '') {
                continue;
            }
            $platform = sanitize_key((string) ($target['platform'] ?? ''));
            if ($platform === 'livejasmin') {
                $anchor = $label . ' official profile';
            } else {
                $anchor = $label . ' profile';
            }
            $items[] = '<li><a href="' . esc_url($url) . '" target="_blank" rel="sponsored noopener">' . esc_html($anchor) . '</a></li>';
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
     * Note: Wikipedia fallback removed ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â when real platform usernames exist
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
            '{name} ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â {power} Live Cam Guide {year}',
            '{name} ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â {power} Live Chat Guide {year}',
            '{name} ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â {power} Webcam Guide {year}',
            '{name} ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â {power} Live Cam Profile {year}',
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
            $title = $name . ' ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â Safe Live Cam Guide ' . $year;
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

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Legacy exact-match patterns (kept for back-compat) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        $legacy_patterns = [
            'ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â live cam profile',
            '- live cam profile',
            'ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â verified live cam profile',
            '- verified live cam profile',
            'ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â live cam model profile & schedule',
            '- live cam model profile & schedule',
        ];

        if (in_array($normalized_no_year, $legacy_patterns, true)) {
            return true;
        }

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Structural requirements ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â title is weak if EITHER is missing ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬

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

        $content = self::dedupe_exact_heading_text($content, 'Before You Click', 'Safety Checklist');

        return $content;
    }

    private static function dedupe_exact_heading_text(string $content, string $heading, string $replacement): string {
        $pattern = '/(?:<!--\s*wp:heading[^>]*-->\s*)?<h([2-6])([^>]*)>\s*' . preg_quote($heading, '/') . '\s*<\/h\1>(?:\s*<!--\s*\/wp:heading\s*-->)?/iu';
        if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return $content;
        }

        $keep_index = 0;
        foreach ($matches[1] as $index => $level_match) {
            if ((string) $level_match[0] === '2') {
                $keep_index = (int) $index;
                break;
            }
        }

        $out = '';
        $pos = 0;
        foreach ($matches[0] as $index => $match) {
            $text = (string) $match[0];
            $offset = (int) $match[1];
            $out .= substr($content, $pos, $offset - $pos);
            if ($index === $keep_index) {
                $out .= $text;
            }
            $pos = $offset + strlen($text);
        }

        return $out . substr($content, $pos);
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
            '<li>Review account requirements before starting chat.</li>',
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
            return 'For ' . $phrase . ' searches, start with the confirmed live room, then compare current status and profile details before spending credits.';
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
        $content = preg_replace('/<p>\s*For\s+[^<.]+?\s+access,\s*confirm handle consistency and recent room activity before joining\.\s*<\/p>/iu', '', $content) ?: $content;
        $content = preg_replace('/For\s+([^<.]+?)\s+access,\s*confirm handle consistency and recent room activity before joining\./iu', 'For $1 searches, start with the confirmed live room and use the verified links below for profile checks.', $content) ?: $content;
        $content = str_replace(['Additional the links', 'additional the links', 'use additional the links', 'Use additional the links'], ['The additional links', 'the additional links', 'use the additional links', 'Use the additional links'], $content);
        // Do NOT replace "live webcam" ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â it is valid English and the old replacement
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
        $content = str_replace('Official Links and Profiles and LiveJasmin profile', 'Official Links and Profiles', $content);
        $content = str_replace('This guide covers the practical side:', 'This page focuses on the practical side:', $content);
        $content = str_replace('This guide covers exactly that need:', 'Here are the basics you need:', $content);
        $content = str_replace('The appeal extends beyond any single session.', 'There is more here than one good session.', $content);
        $content = preg_replace('/\bVisitors searching for\b/iu', 'People coming for', $content) ?: $content;

        // Remove doubled words/phrases at word boundaries (e.g. "the the", "platform platform").
        $content = preg_replace('/\b([A-Za-z]+(?:\s+[A-Za-z]+){0,3})(\s+\1){1,}\b/u', '$1', $content) ?: $content;
        $content = ModelBodySafety::clean_body_text($content);

        $content = self::dedupe_exact_heading_text($content, 'Before You Click', 'Safety Checklist');

        return $content;
    }


    private static function ensure_minimum_useful_depth(string $content, string $name, array $active_platforms, array $resolved_destinations, string $primary_platform_label, string $seed): string {
        $plain = trim((string) wp_strip_all_tags($content));
        $word_count = str_word_count($plain);
        // v5.8.21: raised threshold from 640ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢720 so TemplatePool pages (750ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“900 words)
        // skip the generic depth-padding blocks entirely.
        if ($word_count >= 720) {
            return $content;
        }

        $active_platform_count = count(array_values(array_filter(array_map('strval', $active_platforms), 'strlen')));
        if ($active_platform_count <= 1) {
            return $content;
        }

        $platform_text = self::format_platform_list($active_platforms, $primary_platform_label !== '' ? $primary_platform_label : 'verified platforms');
        $depth_evidence = self::build_link_evidence_summary($resolved_destinations, array_fill(0, $active_platform_count, []));
        if (!empty($depth_evidence['has_extra_links'])) {
            $neither_room_line = 'If neither room works well, use any additional verified destinations on this page to confirm handles and return later when status changes.';
        } elseif ($active_platform_count > 1) {
            $neither_room_line = 'If neither room works well, recheck the listed live profiles later and confirm handles before returning when status changes.';
        } else {
            $neither_room_line = 'If neither room works well, recheck the listed live profile later and confirm the handle before returning when status changes.';
        }

        $compare_block = '<h2>How to Decide Where to Start</h2>'
            . '<p>Start with the platform you already trust, then test one alternate room with the same checklist: uptime signals, chat readability, playback stability, moderation flow, and login friction. A repeatable method prevents brand bias and makes it easier to pick the better room for your device and connection.</p>'
            . '<p>If both rooms perform similarly, keep the one with clearer moderation and fewer account hurdles. ' . $neither_room_line . '</p>';

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

        $content = self::dedupe_exact_heading_text($content, 'Before You Click', 'Safety Checklist');

        return $content;
    }


    private static function expand_model_content_word_count(string $content, array $active_platforms, array $link_evidence_summary, string $seed, int $minimum_words = 600): string {
        $word_count = self::count_visible_words($content);
        if ($word_count >= $minimum_words) {
            return $content;
        }

        $platform_text = self::format_platform_list($active_platforms, 'verified live rooms');
        if ($platform_text === '') {
            $platform_text = 'verified live rooms';
        }

        $has_extra_links = !empty($link_evidence_summary['has_extra_links']);
        $extra_destination_sentence = $has_extra_links
            ? 'When additional verified destinations are listed, treat them as support routes for profile checks, updates, follow actions, and backup navigation rather than automatic room-entry links.'
            : 'When no additional verified destinations are listed, avoid random mirrors and return to the confirmed live-room area for status checks.';

        $blocks = [
            '<h2>Practical Viewing Checklist</h2>'
            . '<p>Before joining any room, check the basics in a consistent order: page ownership, current room status, playback stability, chat readability, account prompts, and privacy controls. A steady checklist keeps the page useful without relying on hype or repeated keyword phrasing.</p>'
            . '<p>Use the visible profile information as a starting point, then confirm that the destination still matches the expected handle and platform cues after click-through. If the room is offline, wait for a clearer status signal instead of chasing copied profiles.</p>',

            '<h2>Profile Verification Notes</h2>'
            . '<p>Verified links are most useful when they separate live-room access from supporting profile evidence. Start with the live section for room entry, then use supporting destinations only when they help confirm identity, activity, or a recent update.</p>'
            . '<p>' . $extra_destination_sentence . '</p>',

            '<h2>How to Compare Access Options</h2>'
            . '<p>If more than one live option appears, compare them with practical signals rather than brand preference alone. Look at loading speed, chat moderation, account requirements, payment prompts, and how clearly the room communicates availability.</p>'
            . '<p>The safest choice is usually the destination that combines a verified profile, readable room state, and predictable navigation. Keep ' . esc_html($platform_text) . ' in mind, but let the current room experience decide where to stay.</p>',

            '<h2>Safer Navigation Reminders</h2>'
            . '<p>Keep browsing decisions deliberate. Avoid copied pages, shortened links from unknown sources, and off-page claims that are not reflected by the verified profile details shown here. If something looks inconsistent, pause and recheck the official destination list.</p>'
            . '<p>This measured approach supports better search quality too: clear sections, useful context, and fewer repeated names make the page easier to read while preserving the important evidence and link blocks already generated above.</p>',
        ];

        $selected = [];
        for ($i = 0; $word_count < $minimum_words && $i < count($blocks); $i++) {
            $idx = self::stable_pick_index($seed . '|word-expansion|' . $i, count($blocks));
            while (in_array($idx, $selected, true)) {
                $idx = ($idx + 1) % count($blocks);
            }
            $selected[] = $idx;
            $content .= "\n\n" . $blocks[$idx];
            $word_count = self::count_visible_words($content);
        }

        return $content;
    }


    private static function guard_model_focus_keyword_density(string $content, string $focus_keyword, string $seed, float $maximum_density = 2.0): string {
        $focus_keyword = trim($focus_keyword);
        if ($focus_keyword === '') {
            return $content;
        }

        $word_count = self::count_visible_words($content);
        $focus_hits = self::count_exact_visible_phrase_hits($content, $focus_keyword);
        if ($word_count <= 0 || $focus_hits <= 0) {
            return $content;
        }

        // Leave a small margin below the public 2.0% ceiling so later heading
        // placement cannot accidentally push borderline content over the cap.
        $target_density = min($maximum_density, 1.85);
        if (($focus_hits / $word_count) * 100 <= $target_density) {
            return $content;
        }

        $blocks = [
            '<h2>Reader-Friendly Summary</h2>'
            . '<p>The strongest model pages balance concise access details with enough practical context for a real reader. Neutral supporting copy helps explain how to use the links, how to compare platform signals, and when to wait for a clearer live-room status.</p>'
            . '<p>That balance also prevents overuse of the focus phrase. The goal is a readable guide with verified destinations, useful safety notes, and natural headings rather than a page that repeats the same name in every paragraph.</p>',

            '<h2>Quality and Safety Context</h2>'
            . '<p>Use room quality, identity consistency, and navigation safety as the main decision points. Confirm the destination, scan the visible profile cues, and avoid any page that asks for unnecessary redirects before showing a stable room or profile state.</p>'
            . '<p>When the current status is unclear, returning later is better than following unverified mirrors. A careful route protects the reader and keeps the generated page focused on evidence-backed information.</p>',

            '<h2>What to Check After Opening a Link</h2>'
            . '<p>After opening a destination, check that the platform label, handle cues, room status, and account prompts match expectations. If the profile appears inactive, use the rest of the verified context for follow-up checks instead of assuming every destination is live.</p>'
            . '<p>These checks make the content more useful without adding repeated focus-keyword mentions, which keeps density under control while preserving the evidence block and verified-link sections.</p>',
        ];

        $selected = [];
        for ($i = 0; $i < count($blocks); $i++) {
            $idx = self::stable_pick_index($seed . '|density-guard|' . $i, count($blocks));
            while (in_array($idx, $selected, true)) {
                $idx = ($idx + 1) % count($blocks);
            }
            $selected[] = $idx;
            $content .= "\n\n" . $blocks[$idx];
            $word_count = self::count_visible_words($content);
            if ($word_count > 0 && (($focus_hits / $word_count) * 100) <= $target_density) {
                break;
            }
        }

        $neutral_fillers = [
            'Keep the evaluation simple: confirm the link source, compare the live status, review account prompts, and leave the page if the destination does not match the expected platform cues.',
            'Reliable pages should explain access, verification, and safety in plain language, with enough context for a reader to make a careful decision without repeated focus-keyword stuffing.',
            'If a room or profile appears stale, wait for a better status signal and use only verified destinations for follow-up checks.',
        ];
        // v5.8.21: cap neutral filler additions at 1 cycle (3 paragraphs max).
        // The old limit of 12 caused the same 3 sentences to repeat 4 times,
        // producing thin-looking duplicate copy at the bottom of the page.
        $filler_cap = min(count($neutral_fillers), 3);
        for ($i = 0; $word_count > 0 && (($focus_hits / $word_count) * 100) > $target_density && $i < $filler_cap; $i++) {
            $content .= "\n\n<p>" . $neutral_fillers[$i % count($neutral_fillers)] . '</p>';
            $word_count = self::count_visible_words($content);
        }

        return $content;
    }


    private static function count_visible_words(string $html): int {
        $plain = trim((string) wp_strip_all_tags($html));
        if ($plain === '') {
            return 0;
        }

        return str_word_count($plain);
    }


    private static function count_exact_visible_phrase_hits(string $html, string $phrase): int {
        $plain = (string) wp_strip_all_tags($html);
        if ($plain === '' || $phrase === '') {
            return 0;
        }

        $hits = preg_match_all('/(?<![\p{L}\p{N}_-])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}_-])/iu', $plain, $matches);

        return is_int($hits) ? $hits : 0;
    }


    private static function apply_lightweight_content_guardrails(string $content, string $name): string {
        $content = preg_replace('/<p>\s*Watch Live on\s+([^<]+)\.<\/p>/iu', '<p>Open the verified live room on $1.</p>', $content) ?: $content;
        $content = preg_replace('/\b' . preg_quote($name, '/') . '\s+' . preg_quote($name, '/') . '\b/iu', $name, $content) ?: $content;
        $content = preg_replace('/(<p>\s*Use this section\b[^<]*<\/p>)(\s*<p>\s*Use this section\b[^<]*<\/p>)+/iu', '$1', $content) ?: $content;
        $content = self::dedupe_exact_heading_text($content, 'Before You Click', 'Safety Checklist');

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


    private static function final_template_copy_cleanup(string $html): string {
        if (trim($html) === '') {
            return $html;
        }

        $parts = preg_split('/(<[^>]+>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return $html;
        }

        $inside_anchor = false;
        foreach ($parts as $idx => $part) {
            if ($part === '') {
                continue;
            }
            if ($part[0] === '<') {
                if (preg_match('/^<\s*a\b/iu', $part)) {
                    $inside_anchor = true;
                } elseif (preg_match('/^<\s*\/\s*a\s*>/iu', $part)) {
                    $inside_anchor = false;
                }
                continue;
            }
            if ($inside_anchor) {
                continue;
            }
            $parts[$idx] = self::cleanup_template_text_node($part);
        }

        return implode('', $parts);
    }

    private static function cleanup_template_text_node(string $text): string {
        $text = preg_replace('/\b(links\s+below)(?:\s+below)+\b/iu', '$1', $text) ?: $text;
        $text = preg_replace('/\b(the|below)(?:\s+\1)+\b/iu', '$1', $text) ?: $text;
        $text = preg_replace('/([!?]){2,}/u', '$1', $text) ?: $text;
        $text = preg_replace('/\.{4,}/u', '...', $text) ?: $text;
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?: $text;
        $text = preg_replace('/\s+([,.;:!?])/u', '$1', $text) ?: $text;
        return $text;
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
            $text = preg_replace('/\s+[ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â-]\s+what to expect$/iu', '', $text) ?: $text;
            $text = trim($text, " \t\n\r\0\x0B:;-ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â");
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
    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
    // v5.8.17: TemplatePool primary model builder ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â manual Generate only
    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬

    /**
     * Build a TemplatePool-primary renderer payload for manual model Generate.
     *
     * Replaces the legacy paragraph bags in $renderer_payload with TemplatePool
     * section bodies. All HTML blocks (CTA, affiliate links, external_info_html,
     * comparison_section_html) are preserved unchanged from $support_payload so
     * no affiliate routing, Rank Math, or indexing logic is affected.
     *
     * Safety contract:
     *  - Runs ONLY when admin manually clicks Generate AND data gate passes.
     *  - Falls back to the untouched $renderer_payload on any exception.
     *  - Skips any section whose resolved body contains {{...}} tokens.
     *  - Skips private_chat_options entirely when no private-chat evidence exists.
     *  - Skips turn_ons entirely when no turn-on evidence exists.
     *  - Never produces duplicate H2 headings.
     *  - Exactly one FAQ block, drawn from the TemplatePool faq-pool.
     *  - Logs [TMW-POOL-WIRE] entries when WP_DEBUG is enabled.
     *
     * @param \WP_Post $post
     * @param array    $renderer_payload         Current renderer payload assembled by build_model().
     * @param string   $name                     Model display name / focus keyword.
     * @param string   $primary_platform_label   Primary resolved platform label.
     * @param string[] $active_platforms         All active platform labels.
     * @param array    $resolved_destinations    From ModelDestinationResolver::resolve().
     * @param array    $cta_links                Watch CTA rows.
     * @param array    $verified_destination_rows All verified destination rows.
     * @param array    $legacy_faq_items         FAQ items from legacy build_seed_faq_items().
     * @return array Modified renderer payload.
     */
    private static function build_template_pool_primary_payload(
        \WP_Post $post,
        array $renderer_payload,
        string $name,
        string $primary_platform_label,
        array $active_platforms,
        array $resolved_destinations,
        array $cta_links,
        array $verified_destination_rows,
        array $legacy_faq_items,
        array $rankmath_keywords = [],
        array $extra_keywords = []
    ): array {
        // Guard: TemplatePool class must be available.
        if (!class_exists(\TMWSEO\Engine\Model\TemplatePool::class)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('[TMW-POOL-WIRE] fallback legacy post_id=%d reason=class_missing', (int) $post->ID));
            }
            return $renderer_payload;
        }

        try {
            $pool = new \TMWSEO\Engine\Model\TemplatePool();

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Gather evidence fields ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            $evidence = class_exists(\TMWSEO\Engine\Content\ModelResearchEvidence::class)
                ? \TMWSEO\Engine\Content\ModelResearchEvidence::get_raw_fields((int) $post->ID)
                : ['bio' => '', 'turn_ons' => '', 'private_chat' => ''];

            $raw_turn_ons     = trim((string) ($evidence['turn_ons'] ?? ''));
            $raw_private_chat = trim((string) ($evidence['private_chat'] ?? ''));

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Private-chat evidence gate ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            // Never render private_chat_options or live_chat_experience (which
            // references chat_options_count) unless operator evidence exists.
            $private_chat_items        = [];
            $private_chat_count        = 0;
            $has_private_chat_evidence = false;
            if ($raw_private_chat !== '' && class_exists(\TMWSEO\Engine\Content\ModelResearchEvidence::class)) {
                $private_chat_items = \TMWSEO\Engine\Content\ModelResearchEvidence::filter_private_chat_items($raw_private_chat);
                if (!empty($private_chat_items)) {
                    $has_private_chat_evidence = true;
                    $private_chat_count        = count($private_chat_items);
                }
            }

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Turn-on evidence gate ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            $has_turn_on_evidence   = ($raw_turn_ons !== '');
            $turn_ons_text          = '';   // noun-phrase form for {{turn_ons}} in templates
            $turn_ons_sentence      = '';   // full-sentence form for standalone paragraph
            if ($has_turn_on_evidence && class_exists(\TMWSEO\Engine\Content\ModelResearchEvidence::class)) {
                $raw_humanized = \TMWSEO\Engine\Content\ModelResearchEvidence::humanize_turn_ons($raw_turn_ons);
                if ($raw_humanized === '') {
                    $has_turn_on_evidence = false;
                } else {
                    // v5.8.21: detect full-sentence output (starts uppercase, ends '.').
                    // humanize_turn_ons() fallback returns a complete sentence like:
                    // "Her profile notes highlight an interactive approach..."
                    // Injecting that into "built around {{turn_ons}}" breaks grammar.
                    // Convert to a short noun phrase for template use; keep the full
                    // sentence as a standalone paragraph appended after the intro.
                    // v5.8.21: detect full-sentence output (starts uppercase, ends '.').
                    // humanize_turn_ons() fallback returns a complete sentence like:
                    // "Her profile notes highlight an interactive approach..."
                    // Injecting that inline into "built around {{turn_ons}}" breaks
                    // grammar and produces unreadable copy.
                    //
                    // Solution: when output is a full sentence, set turn_ons_text=''
                    // so the TemplatePool {{turn_ons}} placeholder stays unresolved,
                    // causing the pool to skip variants 0,1,3,4,5,7 (which use
                    // {{turn_ons}}) and naturally select variant 2 or 6 (no {{turn_ons}}).
                    // The full sentence is stored in turn_ons_sentence and appended
                    // as a clean standalone paragraph after the intro.
                    $is_full_sentence = preg_match('/^[A-Z].+\.$/u', trim($raw_humanized)) === 1;
                    if ($is_full_sentence) {
                        $turn_ons_text     = '';             // leave {{turn_ons}} unresolved ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â pool skips those variants
                        $turn_ons_sentence = $raw_humanized; // clean standalone paragraph appended after intro
                    } else {
                        // Short phrase ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â safe for inline template injection.
                        $turn_ons_text     = $raw_humanized;
                        $turn_ons_sentence = '';
                    }
                }
            }

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Platform / link scalars ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            $verified_count = count($verified_destination_rows);
            $platform_count = count($active_platforms);
            $platform_label = ($primary_platform_label !== self::NEUTRAL_PLATFORM_FALLBACK)
                ? $primary_platform_label
                : ((!empty($active_platforms[0])) ? $active_platforms[0] : 'the platform');
            $platform_list_text = self::format_platform_list($active_platforms, $platform_label);
            $site_name          = (string) get_bloginfo('name');

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Similar model names for more_pages section ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            $similar_1 = '';
            $similar_2 = '';
            $related_q = get_posts([
                'post_type'      => 'model',
                'posts_per_page' => 2,
                'post_status'    => 'publish',
                'post__not_in'   => [(int) $post->ID],
                'orderby'        => 'rand',
                'fields'         => 'ids',
            ]);
            if (!empty($related_q[0])) {
                $similar_1 = trim((string) get_the_title((int) $related_q[0]));
            }
            if (!empty($related_q[1])) {
                $similar_2 = trim((string) get_the_title((int) $related_q[1]));
            }

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ model_data for TemplatePool placeholder resolution ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            // Evidence-gated fields are empty strings when evidence is absent.
            // TemplatePool leaves unresolved {{tokens}} in the output, which
            // the guard below will catch and skip.
            // v5.8.22: Never set 'turn_ons', 'chat_options', or 'chat_options_count' to
            // empty string. TemplatePool::resolve() replaces a key that exists with value ''
            // rather than leaving the {{placeholder}} visible, which makes the placeholder
            // guard fail to skip variants like "built around {{turn_ons}} and a consistent...".
            // Omitting the key entirely makes array_key_exists() return false, causing
            // resolve() to leave {{turn_ons}} in the body, which the guard then catches.
            $model_data = array_filter([
                'name'               => $name,
                'platform'           => $platform_label,
                'platform_list'      => $platform_list_text,
                'platform_count'     => (string) $platform_count,
                'link_count'         => (string) ($verified_count > 0 ? $verified_count : 'verified'),
                'site_name'          => $site_name,
                'similar_1'          => $similar_1,
                'similar_2'          => $similar_2,
                // Evidence-gated: only set when value is non-empty.
                // Empty string ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ key omitted ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ placeholder stays ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ guard skips variant.
                'turn_ons'           => $has_turn_on_evidence ? $turn_ons_text : null,
                'chat_options'       => $has_private_chat_evidence
                    ? implode(', ', array_slice($private_chat_items, 0, 6))
                    : null,
                'chat_options_count' => $has_private_chat_evidence ? (string) $private_chat_count : null,
                'handle'             => $name,
            ], static fn($v): bool => $v !== null && $v !== '');

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Helper: resolve + validate one section ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            // Returns the resolved body string, or empty string if it fails any guard.
            // v5.8.25: when the deterministic variant has unresolved {{placeholders}},
            // rotate through up to 7 more variants to find one that resolves cleanly.
            // This fixes the intro/turn_ons skip when turn_ons_text='' and variant 0
            // requires {{turn_ons}} (e.g. Abby Murray post_id=4432: pick_index=0).
            $resolve_section = function(string $section_key) use ($pool, $post, $model_data, $private_chat_count): string {
                $post_id_int = (int) $post->ID;

                $section = $pool->get_section($section_key, $post_id_int, $model_data);
                if ($section === null) {
                    return '';
                }
                $body = trim((string) ($section['body'] ?? ''));
                if ($body === '') {
                    return '';
                }

                $unresolved_before = (int) preg_match_all('/\{\{\s*[a-zA-Z0-9_\-]+\s*\}\}/', $body);

                if ($unresolved_before > 0) {
                    // Primary pick has unresolved tokens ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â rotate through alternates.
                    $primary_offset = $post_id_int;
                    $found_clean    = false;
                    $fallback_from  = $primary_offset % 8; // approximate variant index for log
                    $fallback_to    = $fallback_from;

                    for ($offset = 1; $offset <= 7; $offset++) {
                        $alt_seed    = $post_id_int + $offset;
                        $alt_section = $pool->get_section($section_key, $alt_seed, $model_data);
                        if ($alt_section === null) {
                            continue;
                        }
                        $alt_body = trim((string) ($alt_section['body'] ?? ''));
                        if ($alt_body === '') {
                            continue;
                        }
                        $alt_unresolved = (int) preg_match_all('/\{\{\s*[a-zA-Z0-9_\-]+\s*\}\}/', $alt_body);
                        if ($alt_unresolved === 0) {
                            $body        = $alt_body;
                            $fallback_to = ($post_id_int + $offset) % 8;
                            $found_clean = true;
                            break;
                        }
                    }

                    if (!$found_clean) {
                        // All variants unresolved ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â log and skip as before.
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log(sprintf(
                                '[TMW-POOL-PLACEHOLDER] post_id=%d section=%s unresolved_before=%d unresolved_after=%d action=all_variants_unresolved fallback_from=%d fallback_to=%d',
                                $post_id_int, $section_key, $unresolved_before, $unresolved_before, $fallback_from, $fallback_from
                            ));
                            error_log(sprintf('[TMW-POOL-WIRE] skipping section=%s post_id=%d reason=unresolved_placeholder', $section_key, $post_id_int));
                        }
                        return '';
                    }

                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log(sprintf(
                            '[TMW-POOL-PLACEHOLDER] post_id=%d section=%s unresolved_before=%d unresolved_after=0 action=resolved_variant fallback_from=%d fallback_to=%d',
                            $post_id_int, $section_key, $unresolved_before, $fallback_from, $fallback_to
                        ));
                    }
                }

                // Private-chat safety: skip "confirmed" language when evidence is thin
                if ($section_key === 'private_chat_options' && $private_chat_count < 2) {
                    if (stripos($body, 'confirmed') !== false) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log(sprintf('[TMW-POOL-WIRE] skipping private_chat variant post_id=%d reason=confirmed_insufficient_evidence', $post_id_int));
                        }
                        return '';
                    }
                }

                return $body;
            };

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Helper: resolve + validate the H2 for a section ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            // v5.8.25: rotate through alternates when primary H2 has unresolved tokens.
            $resolve_h2 = function(string $section_key) use ($pool, $post, $model_data): string {
                $post_id_int = (int) $post->ID;
                $section = $pool->get_section($section_key, $post_id_int, $model_data);
                if ($section === null) {
                    return '';
                }
                $h2 = trim((string) ($section['h2'] ?? ''));
                if (preg_match('/\{\{\s*[a-zA-Z0-9_\-]+\s*\}\}/', $h2)) {
                    // Rotate through alternates
                    for ($offset = 1; $offset <= 7; $offset++) {
                        $alt = $pool->get_section($section_key, $post_id_int + $offset, $model_data);
                        if ($alt === null) {
                            continue;
                        }
                        $alt_h2 = trim((string) ($alt['h2'] ?? ''));
                        if (!preg_match('/\{\{\s*[a-zA-Z0-9_\-]+\s*\}\}/', $alt_h2)) {
                            return $alt_h2;
                        }
                    }
                    return '';
                }
                return $h2;
            };

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Section 1: Intro (replaces intro_paragraphs) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            // TemplatePool intro variants 0ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“7 all use {{name}} and {{platform}}
            // which are always supplied. Variants that also use {{turn_ons}} will
            // be skipped if turn_ons is empty (unresolved placeholder guard).
            // We pick a variant that works: try get_section which is deterministic
            // per post_id, but if it uses turn_ons and we have no evidence, the
            // guard will skip it, so we fall back to the legacy intro_paragraphs.
            $pool_intro = $resolve_section('intro');
            if ($pool_intro !== '') {
                // All TemplatePool intro variants start with {{name}} ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â model name
                // is already in the first sentence.
                // v5.8.21: when turn_ons produced a full sentence that was converted
                // to a noun phrase for inline use, append the original sentence here
                // as a standalone second paragraph so the evidence is not lost.
                $intro_paragraphs_new = [$pool_intro];
                if (!empty($turn_ons_sentence)) {
                    $intro_paragraphs_new[] = $turn_ons_sentence;
                }
            } else {
                // Fallback: keep legacy intro paragraphs unchanged.
                $intro_paragraphs_new = (array) ($renderer_payload['intro_paragraphs'] ?? []);
                if (!empty($turn_ons_sentence)) {
                    $intro_paragraphs_new[] = $turn_ons_sentence;
                }
            }

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Section 2: Official profile access (replaces watch_section_paragraphs) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            $pool_official_access = $resolve_section('official_profile_access');
            $pool_where_to_watch  = $resolve_section('where_to_watch');
            if ($pool_official_access !== '') {
                $watch_paragraphs_new = array_values(array_filter([
                    $pool_official_access,
                    $pool_where_to_watch !== '' ? $pool_where_to_watch : '',
                ], 'strlen'));
            } else {
                $watch_paragraphs_new = (array) ($renderer_payload['watch_section_paragraphs'] ?? []);
            }

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Section 3: Turn-ons (replaces about_section_paragraphs) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            // Only populated when turn-on evidence exists.
            if ($has_turn_on_evidence && $turn_ons_text !== '') {
                $pool_turn_ons = $resolve_section('turn_ons');
                $about_paragraphs_new = $pool_turn_ons !== '' ? [$pool_turn_ons] : [];
            } else {
                $about_paragraphs_new = [];
            }

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Section 4 + 5: Features and Comparison ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            // before_you_click is resolved ONCE and assigned to exactly one slot.
            // This prevents the same body from appearing in both Features and
            // Comparison when live_chat_experience has no usable variant.
            // Priority order:
            //   Features ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ live_chat_experience (preferred) OR private_chat_options
            //               OR legacy features paragraphs (never before_you_click)
            //   Comparison ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ before_you_click (preferred) OR legacy comparison

            $pool_live_chat = '';
            if (!empty($active_platforms)) {
                $pool_live_chat = $resolve_section('live_chat_experience');
            }
            $pool_private_chat = $has_private_chat_evidence ? $resolve_section('private_chat_options') : '';

            // Resolve before_you_click exactly once.
            $pool_before_click = $resolve_section('before_you_click');

            if ($pool_live_chat !== '' || $pool_private_chat !== '') {
                // Preferred path: live-chat content fills Features.
                $features_paragraphs_new = array_values(array_filter([
                    $pool_live_chat,
                    $pool_private_chat,
                ], 'strlen'));
                // before_you_click is still available for Comparison.
            } else {
                // Fallback path: no live-chat content ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â use legacy Features paragraph
                // bags (non-empty check), NOT before_you_click, so Comparison can
                // still use it independently.
                $legacy_features = (array) ($renderer_payload['features_section_paragraphs'] ?? []);
                $legacy_features = array_values(array_filter($legacy_features, 'strlen'));
                $features_paragraphs_new = !empty($legacy_features)
                    ? $legacy_features
                    : (array) ($renderer_payload['features_section_paragraphs'] ?? []);
                // before_you_click is still available for Comparison below.
            }

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Section 5: Before you click (Comparison) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            // Uses the single $pool_before_click resolved above.
            if ($pool_before_click !== '') {
                $comparison_paragraphs_new = [$pool_before_click];
            } else {
                $comparison_paragraphs_new = (array) ($renderer_payload['comparison_section_paragraphs'] ?? []);
            }

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Section 6: Official links summary (replaces official_links_section_paragraphs) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            $pool_links_summary = $resolve_section('official_links_summary');
            if ($pool_links_summary !== '') {
                // Prepend the TemplatePool body before the existing links summary paragraphs.
                $existing_links_paras = (array) ($renderer_payload['official_links_section_paragraphs'] ?? []);
                $official_links_paragraphs_new = array_merge([$pool_links_summary], $existing_links_paras);
            } else {
                $official_links_paragraphs_new = (array) ($renderer_payload['official_links_section_paragraphs'] ?? []);
            }

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Section 7: FAQ (replaces faq_items) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            // Use TemplatePool FAQs. If any are unusable, fall back to legacy.
            $pool_faqs_raw = $pool->get_faqs((int) $post->ID, $model_data, 5);
            $pool_faq_items = [];
            foreach ($pool_faqs_raw as $faq) {
                $q = trim((string) ($faq['q'] ?? $faq['question'] ?? ''));
                $a = trim((string) ($faq['a'] ?? $faq['answer'] ?? ''));
                if ($q === '' || $a === '') {
                    continue;
                }
                // Skip items with unresolved placeholders.
                if (preg_match('/\{\{\s*[a-zA-Z0-9_\-]+\s*\}\}/', $q . $a)) {
                    continue;
                }
                // v5.8.21: Fix FAQ question grammar ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â questions that start with an
                // imperative verb and end with '?' should begin with "Can I".
                // Covers the case where e.g. "Report a fake X profile if I find one?"
                // should be "Can I report a fake X profile if I find one?".
                if (preg_match('/^(Report|Find|Tell|Show|Check|Verify|View|Open|Use|Start|Get|See|Track|Follow|Block|Access)\b/u', $q)
                    && substr($q, -1) === '?') {
                    $q = 'Can I ' . lcfirst($q);
                }
                $pool_faq_items[] = ['q' => $q, 'a' => $a];
            }
            // Use pool FAQs when we have at least 2; otherwise keep legacy.
            $faq_items_new = count($pool_faq_items) >= 2 ? $pool_faq_items : $legacy_faq_items;

            // v5.8.22: Fix FAQ grammar on ALL sources (pool and legacy).
            // Imperatives like "Report a fake X profile?" must be "Can I report...?".
            $faq_items_new = array_map(static function(array $item): array {
                $q = trim((string) ($item['q'] ?? ''));
                if ($q !== '' && substr($q, -1) === '?'
                    && preg_match('/^(Report|Find|Tell|Show|Check|Verify|View|Open|Use|Start|Get|See|Track|Follow|Block|Access)\b/u', $q)) {
                    $item['q'] = 'Can I ' . lcfirst($q);
                }
                return $item;
            }, $faq_items_new);

            // v5.8.31: Neutralise low-value model-name repetitions in FAQ H3 questions
            // and paired answer sentences. Applied after grammar fix so all FAQ items
            // are fully resolved. Only specific known patterns are rewritten; questions
            // that benefit from the model name for clarity or SEO are preserved.
            if ($name !== '') {
                $faq_items_new = self::neutralize_low_value_faq_name_mentions($faq_items_new, $name);
            }

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Count how many sections actually resolved ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            $resolved_count = (int) ($pool_intro !== '')
                + (int) ($pool_official_access !== '')
                + (int) ($pool_live_chat !== '')
                + (int) ($pool_before_click !== '')
                + count($pool_faq_items);

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Require minimum resolved sections to proceed ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            // If fewer than 3 sections resolved, TemplatePool primary mode
            // cannot produce adequate output ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â fall back to legacy payload.
            if ($resolved_count < 3) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf(
                        '[TMW-POOL-WIRE] fallback legacy post_id=%d reason=insufficient_resolved_sections count=%d',
                        (int) $post->ID,
                        $resolved_count
                    ));
                }
                return $renderer_payload;
            }

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Build the H2 for the "About" / turn-ons section ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            // We use the turn_ons section H2 from TemplatePool when evidence exists,
            // otherwise fall back to the standard "About {name}" heading.
            // NOTE: The renderer controls H2 headings for its named sections.
            // We only override the paragraph *content* here; the renderer's
            // heading logic (render_section calls) is preserved.

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Assemble the TemplatePool-primary payload ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            // Start from the existing renderer_payload (which carries all HTML
            // blocks: watch_section_html, external_info_html, comparison_section_html,
            // internal_links_html, etc.) and override only the paragraph bags.
            $tp_payload = array_merge($renderer_payload, [
                'intro_paragraphs'               => $intro_paragraphs_new,
                'watch_section_paragraphs'        => $watch_paragraphs_new,
                'about_section_paragraphs'        => $about_paragraphs_new,
                'fans_like_section_paragraphs'    => [],
                'features_section_paragraphs'     => $features_paragraphs_new,
                'comparison_section_paragraphs'   => $comparison_paragraphs_new,
                'faq_items'                       => $faq_items_new,
                'official_links_section_paragraphs' => $official_links_paragraphs_new,
                'questions_section_paragraphs'    => [],
            ]);

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ v5.8.22: Dynamic keyword-aware opening paragraph ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            // Build a rich opening paragraph using focus keyword + selected extras.
            // Only overwrites $tp_payload['intro_paragraphs'] when we can produce
            // something better than the TemplatePool default.
            $kw_intro = self::build_keyword_aware_intro_paragraph(
                $name,
                $platform_label,
                $has_turn_on_evidence,
                $turn_ons_sentence,
                $has_private_chat_evidence,
                $private_chat_items,
                $rankmath_keywords,
                $extra_keywords,
                (int) $post->ID
            );
            if ($kw_intro !== '') {
                // Prepend the keyword-rich intro before the TemplatePool intro text.
                // The TemplatePool intro (variant 2 or 6) stays as a second paragraph.
                $existing_intro = (array) ($tp_payload['intro_paragraphs'] ?? []);
                $tp_payload['intro_paragraphs'] = array_merge([$kw_intro], $existing_intro);
            }

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ v5.8.27: Name-bearing H2 overrides for Rank Math extra keyword placement ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            // Rank Math marks extra keywords green only when they appear in a subheading.
            // Concept detection MUST use $rankmath_keywords as primary source because
            // $extra_keywords was already stripped by filter_name_free_keywords() ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â all
            // name-bearing keywords like "Abby Murray LiveJasmin" are removed from $extra
            // before this point and will never appear in $extra_keywords.
            // $rankmath_keywords comes from pack['rankmath_additional'] via
            // select_body_safe_rankmath_keywords(), which preserves name-bearing chips.
            $h2_overrides_used = [];

            // Merge $rankmath_keywords (primary) + $extra_keywords (fallback) for detection.
            $kw_for_h2_detection = array_values(array_unique(array_merge(
                array_map('strval', $rankmath_keywords),
                array_map('strval', $extra_keywords)
            )));
            $all_kw_h2_lower = array_map(
                static fn(string $k): string => mb_strtolower(trim($k), 'UTF-8'),
                array_filter($kw_for_h2_detection, 'strlen')
            );

            // Detect concepts from the merged keyword pool
            $has_livecam_extra    = false;
            $has_privatechat_extra = false;
            $has_webcam_extra     = false;
            $has_livejasmin_extra  = false;
            foreach ($all_kw_h2_lower as $ekw) {
                if (str_contains($ekw, 'live cam') && !str_contains($ekw, 'webcam')) {
                    $has_livecam_extra = true;
                }
                if (str_contains($ekw, 'private') && str_contains($ekw, 'chat')) {
                    $has_privatechat_extra = true;
                }
                if (str_contains($ekw, 'webcam') || str_contains($ekw, 'live webcam')) {
                    $has_webcam_extra = true;
                }
                if (str_contains($ekw, 'livejasmin') || str_contains($ekw, 'live jasmin')) {
                    $has_livejasmin_extra = true;
                }
            }

            // Build H2 override display list for log
            if ($has_livejasmin_extra || $has_livecam_extra) {
                $h2_overrides_used[] = $name . ' LiveJasmin Profile and Live Cam Access';
            }
            if ($has_privatechat_extra) {
                $h2_overrides_used[] = $has_private_chat_evidence
                    ? $name . ' Live Cam Private Chat Options'
                    : $name . ' Live Cam Profile Checks';
            }
            if ($has_webcam_extra) {
                // Suppressed: avoid keyword-stuffed generated webcam heading.
            }

            // Store actual target H2 strings for the post-render preg_replace block.
            // Always set the payload key so the post-render block runs (before_click_h2
            // and questions_h2 apply unconditionally when TemplatePool primary ran).
            $tp_payload['_extra_kw_h2_overrides'] = [
                'live_access_h2'  => ($has_livejasmin_extra || $has_livecam_extra)
                    ? $name . ' LiveJasmin Profile and Live Cam Access'
                    : '',
                'private_chat_h2' => $has_privatechat_extra
                    ? ($has_private_chat_evidence
                        ? $name . ' Live Cam Private Chat Options'
                        : $name . ' Live Cam Profile Checks')
                    : '',
                'turn_ons_h2'     => $has_turn_on_evidence
                    ? ($has_livejasmin_extra
                        ? 'Turn Ons for ' . $name . ' LiveJasmin'
                        : ($has_livecam_extra
                            ? $name . ' Live Cam Turn Ons and Session Notes'
                            : 'Turn Ons and Session Notes for ' . $name))
                    : '',
                'before_click_h2' => 'Before You Click the Confirmed Profile',
                'questions_h2'    => 'Common Profile Questions',
            ];

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[TMW-POOL-EXTRA-KW] post_id=%d intro_used="%s" headings_used="%s"',
                    (int) $post->ID,
                    implode(', ', array_slice(array_values(array_filter(array_map('strval', $extra_keywords), 'strlen')), 0, 4)),
                    implode(', ', $h2_overrides_used)
                ));
            }

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ v5.8.23: Keyword density reduction (page-level budget) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            // Applies page-level exact-name budget (target: 10 mentions).
            // Operator-reviewed bio paragraphs are never mutated.
            // Headings, the first intro paragraph, and CTA HTML are untouched.
            $protected_bio_text = class_exists(\TMWSEO\Engine\Content\ModelResearchEvidence::class)
                ? ''
                : '';
            // Read reviewed bio summary for protection: if bio_review_status='reviewed'
            // and bio_summary is non-empty, protect that exact text from substitution.
            if (class_exists('\\TMWSEO\\Engine\\Content\\TemplateContent')) {
                $bio_ev_for_density = self::get_bio_evidence_data((int) $post->ID);
                $protected_bio_text = ($bio_ev_for_density['is_reviewable'] ?? false)
                    ? trim((string) ($bio_ev_for_density['summary'] ?? ''))
                    : '';
            }
            $tp_payload = self::reduce_focus_keyword_density_in_payload(
                $tp_payload,
                $name,
                $platform_label,
                $protected_bio_text
            );

            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ v5.8.22: [TMW-POOL-KEYWORDS] diagnostic log ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            if (defined('WP_DEBUG') && WP_DEBUG && !empty($rankmath_keywords)) {
                $extras_used = implode(', ', array_slice($rankmath_keywords, 0, 4));
                $intro_first = trim(wp_strip_all_tags((string)(($tp_payload['intro_paragraphs'] ?? [''])[0] ?? '')));
                $intro_preview = mb_substr($intro_first, 0, 80, 'UTF-8');
                $h2_candidates = array_filter(array_map('wp_strip_all_tags', [
                    $intro_first,
                    implode(' ', array_slice($tp_payload['watch_section_paragraphs'] ?? [], 0, 1)),
                ]));
                error_log(sprintf(
                    '[TMW-POOL-KEYWORDS] post_id=%d focus="%s" extras="%s" used_intro="%s..."',
                    (int) $post->ID,
                    $name,
                    $extras_used,
                    $intro_preview
                ));
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[TMW-POOL-WIRE] using TemplatePool primary post_id=%d resolved_sections=%d faq_count=%d',
                    (int) $post->ID,
                    $resolved_count,
                    count($faq_items_new)
                ));
            }

            return $tp_payload;

        } catch (\Throwable $e) {
            // TemplatePool failure must never break generation.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[TMW-POOL-WIRE] fallback legacy post_id=%d reason=exception message=%s',
                    (int) $post->ID,
                    $e->getMessage()
                ));
            }
            return $renderer_payload;
        }
    }

    /**
     * Legacy apply_template_pool_enrichment ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â kept as dead code guard.
     *
     * @deprecated Replaced by build_template_pool_primary_payload in v5.8.17.
     *             Never called; preserved so any stale references fail loudly
     *             with a PHP deprecation rather than silently.
     */
    private static function apply_template_pool_enrichment(
        \WP_Post $post,
        string $content,
        string $name,
        string $primary_platform_label,
        array $active_platforms,
        array $resolved_destinations,
        array $cta_links,
        array $verified_destination_rows
    ): string {
        trigger_error(
            '[TMW-POOL-WIRE] apply_template_pool_enrichment() is deprecated in v5.8.17. Use build_template_pool_primary_payload() instead.',
            E_USER_DEPRECATED
        );
        return $content;
    }

    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
    // v5.8.21: TemplatePool output cleanup helpers
    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬

    /**
     * Remove exact-duplicate and near-duplicate paragraphs from TemplatePool output.
     *
     * Targets three specific repetition patterns:
     *  1. Exact <p>ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦</p> duplicates (same normalised text).
     *  2. Near-duplicates where the first 70 characters of two <p> blocks match.
     *  3. Repeated generic safety H2 sections (by heading text match).
     *
     * Only touches <p> and H2-guarded duplicate blocks; never touches CTAs,
     * affiliate links, verified link groups, or H3 FAQ items.
     *
     * @param string $html
     * @return string
     */
    private static function dedupe_templatepool_output(string $html): string {
        if (trim($html) === '') {
            return $html;
        }

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Step 1: Remove exact-duplicate and near-duplicate <p> blocks ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        $seen_p     = [];
        $html = (string) preg_replace_callback(
            '/<p(\b[^>]*)>(.*?)<\/p>/isu',
            static function (array $m) use (&$seen_p): string {
                $inner  = trim((string) wp_strip_all_tags($m[2]));
                $inner  = (string) preg_replace('/\s+/', ' ', $inner);
                if ($inner === '') {
                    return $m[0]; // keep empty structural paragraphs
                }
                $key60  = mb_strtolower(mb_substr($inner, 0, 70, 'UTF-8'), 'UTF-8');
                $key_full = md5(mb_strtolower($inner, 'UTF-8'));
                if (isset($seen_p[$key_full]) || isset($seen_p[$key60])) {
                    return ''; // duplicate ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â remove
                }
                $seen_p[$key_full] = true;
                $seen_p[$key60]    = true;
                return $m[0];
            },
            $html
        ) ?: $html;

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Step 2: Remove repeated generic safety / depth-padding H2 sections ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // These headings are added by ensure_minimum_useful_depth(),
        // expand_model_content_word_count(), and guard_model_focus_keyword_density().
        // TemplatePool content is already long enough ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â these sections add no value.
        $generic_h2_patterns = [
            'How to Decide Where to Start',
            'Verification and Review Method',
            'How to Use Backup Destinations Safely',
            'Practical Use of Non-Live Destinations',
            'Practical Viewing Checklist',
            'Profile Verification Notes',
            'How to Compare Access Options',
            'Safer Navigation Reminders',
            'Reader-Friendly Summary',
            'Quality and Safety Context',
            'What to Check After Opening a Link',
        ];

        foreach ($generic_h2_patterns as $heading) {
            // Remove the H2 + all following <p> blocks up to the next H2 or end.
            $escaped = preg_quote($heading, '/');
            $html = (string) preg_replace(
                '/<h2>\s*' . $escaped . '\s*<\/h2>\s*(?:<p[^>]*>.*?<\/p>\s*)*/isu',
                '',
                $html
            );
        }

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Step 3: Collapse excess blank lines left by removals ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        $html = (string) preg_replace('/\n{3,}/', "\n\n", $html);

        return trim($html);
    }

    /**
     * Build a keyword-rich H2 heading for the TemplatePool-primary intro section.
     *
     * Uses the model name + primary platform + available signal (turn-ons or private
     * chat) to produce one natural-language H2 that:
     *  - contains the focus keyword (model name) naturally
     *  - contains the primary platform name
     *  - references the highest-value secondary keyword signal available
     *  - reads as a genuine model-profile heading, not keyword stuffing
     *
     * Returns empty string if name or platform are missing.
     *
     * @param string $name            Model display name.
     * @param string $platform_label  Primary platform label (e.g. "LiveJasmin").
     * @param bool   $has_turn_ons    Whether turn-on evidence exists.
     * @param bool   $has_private_chat Whether private chat evidence exists.
     * @param int    $post_id         Post ID for deterministic variant selection.
     * @return string  H2 HTML tag or empty string.
     */
    private static function build_templatepool_intro_h2(
        string $name,
        string $platform_label,
        bool $has_turn_ons,
        bool $has_private_chat,
        int $post_id,
        array $rankmath_keywords = []
    ): string {
        if ($name === '' || $platform_label === '' || $platform_label === self::NEUTRAL_PLATFORM_FALLBACK) {
            return '';
        }

        // Build a pool of natural model-profile headings appropriate to the
        // evidence available. Using the model name + platform guarantees the
        // focus keyword and a secondary keyword chip are both in the heading.
        // v5.8.22: also detect extra keyword concepts from dynamic pack
        $all_kws_lower = array_map('mb_strtolower', array_values((array) $rankmath_keywords));
        $has_livecam_in_kw = false;
        foreach ($all_kws_lower as $kw) {
            if (str_contains($kw, 'live cam') || str_contains($kw, 'webcam') || str_contains($kw, 'live webcam')) {
                $has_livecam_in_kw = true;
            }
        }

        if ($has_turn_ons && $has_private_chat) {
            $variants = [
                $name . ' ' . $platform_label . ' Profile, Turn Ons and Private Chat Options',
                $name . ' Live Cam Access and Private Chat Options on ' . $platform_label,
                $name . ': ' . $platform_label . ' Turn Ons, Private Chat and Verified Profile',
            ];
        } elseif ($has_turn_ons) {
            $variants = [
                $name . ' ' . $platform_label . ' Turn Ons and Live Cam Session Notes',
                $name . ' Live Cam Access and Turn Ons on ' . $platform_label,
                $name . ': ' . $platform_label . ' Profile, Turn Ons and Live Room Access',
            ];
        } elseif ($has_private_chat) {
            $variants = [
                $name . ' Private Chat Options and Live Room Access on ' . $platform_label,
                $name . ' ' . $platform_label . ' Profile and Private Chat Options',
                $name . ': Verified ' . $platform_label . ' Profile and Private Chat Notes',
            ];
        } elseif ($has_livecam_in_kw) {
            $variants = [
                $name . ' ' . $platform_label . ' Live Cam Access and Verified Profile',
                $name . ' Live Cam Profile on ' . $platform_label . ': Verified Links and Access Notes',
                'Where to Watch ' . $name . ' Live on ' . $platform_label,
            ];
        } else {
            $variants = [
                $name . ' Live Cam Access and Verified Profile Details on ' . $platform_label,
                $name . ' ' . $platform_label . ' Profile: Verified Links and Live Room Access',
                'Where to Watch ' . $name . ' Live on ' . $platform_label,
            ];
        }

        $idx = abs($post_id) % count($variants);
        return '<h2>' . esc_html($variants[$idx]) . '</h2>';
    }


    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
    // v5.8.22: Dynamic keyword-aware helpers
    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬

    /**
     * Build a keyword-aware opening paragraph for TemplatePool-primary pages.
     *
     * Uses the focus keyword (model name), confirmed platform, and dynamic
     * secondary keywords from the model keyword pack to produce a first
     * paragraph that:
     *  - mentions the model name naturally (focus keyword in first 10%)
     *  - mentions the confirmed platform
     *  - weaves in concepts from the secondary keyword pack where they fit
     *    (live cam access, private chat options, turn ons, etc.)
     *  - does not repeat the model name more than twice
     *  - reads as human-authored model-profile copy
     *
     * All keyword concepts are drawn dynamically from $rankmath_keywords and
     * $extra_keywords ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â no hardcoded model or site names.
     *
     * @param string   $name                Model display name (focus keyword).
     * @param string   $platform_label      Primary platform label.
     * @param bool     $has_turn_ons        Turn-on evidence present.
     * @param string   $turn_ons_sentence   Full turn-on sentence (if is_full_sentence).
     * @param bool     $has_private_chat    Private-chat evidence present.
     * @param string[] $private_chat_items  Private-chat option list.
     * @param string[] $rankmath_keywords   Dynamic Rank Math secondary chips.
     * @param string[] $extra_keywords      Dynamic extra keyword pool.
     * @param int      $post_id             Post ID for deterministic variant.
     * @return string  Paragraph text (plain, no HTML tags).
     */
    /**
     * Build a keyword-aware opening paragraph for TemplatePool-primary pages.
     *
     * EVIDENCE RULE (CodeRabbit Issue 1 ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â v5.8.23):
     * Feature-specific claims (private chat options, listed turn ons) may only
     * appear in the opening paragraph when the corresponding operator-evidence
     * boolean is TRUE. SEO keywords alone do NOT constitute evidence and must
     * NOT produce factual claims in prose copy.
     *
     * Keywords ($rankmath_keywords, $extra_keywords) are used only to detect
     * whether a concept is worth mentioning in a GENERIC way (e.g. noting that
     * this is a live cam profile) ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â never to claim a specific feature exists.
     *
     * Safe patterns:
     *   - "{Name} is listed with a confirmed {Platform} profile." ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â always safe.
     *   - "This page covers her verified profile access and session notes." ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â always safe.
     *   - "Private chat options are available in her live room." ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â ONLY when $has_private_chat=true.
     *   - "Her listed turn ons are noted below." ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â ONLY when $has_turn_ons=true.
     *
     * @param string   $name                Model display name (focus keyword).
     * @param string   $platform_label      Primary platform label.
     * @param bool     $has_turn_ons        Turn-on EVIDENCE present (operator field filled).
     * @param string   $turn_ons_sentence   Full turn-on sentence when evidence is a prose sentence.
     * @param bool     $has_private_chat    Private-chat EVIDENCE present (operator field filled).
     * @param string[] $private_chat_items  Filtered private-chat option list from evidence.
     * @param string[] $rankmath_keywords   Dynamic Rank Math secondary chips (keyword-only, no claims).
     * @param string[] $extra_keywords      Dynamic extra keyword pool (keyword-only, no claims).
     * @param int      $post_id             Post ID for deterministic variant selection.
     * @return string  Paragraph text (plain, no HTML tags).
     */
    private static function build_keyword_aware_intro_paragraph(
        string $name,
        string $platform_label,
        bool $has_turn_ons,
        string $turn_ons_sentence,
        bool $has_private_chat,
        array $private_chat_items,
        array $rankmath_keywords,
        array $extra_keywords,
        int $post_id
    ): string {
        if ($name === '' || $platform_label === '' || $platform_label === self::NEUTRAL_PLATFORM_FALLBACK) {
            return '';
        }

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Detect keyword concepts from extra_keywords ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // Used to decide which natural access-method phrases to include in the intro.
        // We never claim features beyond what evidence supports ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â these keyword signals
        // only affect generic navigation framing, not feature claims.
        $all_kw_lower = array_map(
            static fn(string $k): string => mb_strtolower(trim($k), 'UTF-8'),
            array_values(array_filter(array_map('strval', array_merge($rankmath_keywords, $extra_keywords)), 'strlen'))
        );
        $has_livecam_kw    = false;
        $has_webcam_kw     = false;
        $has_privatechat_kw = false;
        foreach ($all_kw_lower as $kw) {
            if (str_contains($kw, 'live cam') || str_contains($kw, 'live webcam') || str_contains($kw, 'webcam')) {
                $has_livecam_kw = true;
            }
            if (str_contains($kw, 'webcam') || str_contains($kw, 'live webcam')) {
                $has_webcam_kw = true;
            }
            if (str_contains($kw, 'private') && str_contains($kw, 'chat')) {
                $has_privatechat_kw = true;
            }
        }

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Opening sentence: always includes focus keyword + confirmed platform ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // v5.8.25: weave live cam / webcam / private chat access concepts from
        // extra keywords into the opening sentence naturally. This gives Rank Math
        // the first-10% occurrence it needs for extra keyword chips while keeping
        // the sentence factual and readable.
        //
        // Evidence safety: "private chat options" / "private live chat options" may only
        // appear when $has_private_chat is TRUE (operator evidence field is populated).
        // When the keyword contains "private chat" but evidence is absent, we use
        // the safe navigation phrase "private live chat access checks" instead ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â
        // this describes what the page offers (access notes) not what the model offers.
        $private_chat_phrase = $has_private_chat
            ? 'private live chat options'
            : 'private live chat access checks';

        if ($has_livecam_kw && $has_privatechat_kw && $has_webcam_kw) {
            $opening = $name . ' is listed with a confirmed ' . $platform_label
                . ' profile, giving visitors a checked starting point for '
                . $name . ' live cam access, ' . $private_chat_phrase . ', and current live webcam room-status checks before opening the room.';
        } elseif ($has_livecam_kw && $has_privatechat_kw) {
            $opening = $name . ' is listed with a confirmed ' . $platform_label
                . ' profile. This page covers ' . $name . ' live cam access, ' . $private_chat_phrase . ', and practical room-access checks.';
        } elseif ($has_livecam_kw && $has_webcam_kw) {
            $opening = $name . ' is listed with a confirmed ' . $platform_label
                . ' live cam profile. Check the ' . $name . ' live cam room and live webcam status before opening the room.';
        } elseif ($has_livecam_kw) {
            $opening = $name . ' is listed with a confirmed ' . $platform_label
                . ' live cam profile. This page covers ' . $name . ' live cam access and verified profile checks.';
        } else {
            $opening = $name . ' is listed with a confirmed ' . $platform_label . ' live cam profile.';
        }

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Evidence-gated feature signals ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // These phrases only appear when the operator evidence boolean is TRUE.
        // Keywords alone do NOT constitute evidence and must NOT produce feature claims.
        $evidence_signals = [];
        if ($has_private_chat) {
            $evidence_signals[] = 'private chat options';
        }
        if ($has_turn_ons) {
            $evidence_signals[] = 'listed session interests';
        }

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Second sentence: navigation context, using evidence signals only ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // Skip when opening already covers the content naturally.
        if (!empty($evidence_signals) && !($has_livecam_kw && $has_privatechat_kw)) {
            $signal_str = self::natural_keyword_list($evidence_signals);
            $second = 'This page covers her ' . $signal_str
                . ', verified live-room access, and profile checks before visitors open the room.';
            return trim($opening . ' ' . $second);
        }

        return trim($opening);
    }

    /**
     * Format a short list of keyword concept strings naturally.
     *
     * "a, b, c" ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ "a, b, and c"
     * "a, b" ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ "a and b"
     * "a" ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ "a"
     *
     * @param string[] $items
     * @return string
     */
    private static function natural_keyword_list(array $items): string {
        $items = array_values(array_filter(array_map('trim', $items), 'strlen'));
        if (empty($items)) {
            return '';
        }
        if (count($items) === 1) {
            return $items[0];
        }
        $last = array_pop($items);
        return implode(', ', $items) . ', and ' . $last;
    }

    /**
     * Reduce exact focus-keyword repetition in TemplatePool paragraph bags.
     *
     * v5.8.23 redesign ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â three CodeRabbit issues resolved:
     *
     * Issue 2 (operator bio protection):
     *   Any paragraph whose text exactly matches $protected_bio_text is skipped
     *   entirely. Operator-reviewed bio copy is never mutated.
     *
     * Issue 3 (page-level budget instead of per-paragraph keep-first):
     *   The old per-paragraph "keep first occurrence" strategy kept every single
     *   {{name}}-resolved mention because each TemplatePool paragraph has exactly
     *   one model name. This produced ~21 exact mentions unchanged.
     *   The new approach uses a PAGE-LEVEL budget (TARGET_EXACT_MENTIONS = 10):
     *   - First pass: count how many exact mentions exist across ALL paragraphs.
     *   - Keep the first TARGET_EXACT_MENTIONS occurrences (in document order).
     *   - Replace every occurrence beyond the budget with a round-robin substitution.
     *   This guarantees the focus keyword appears in the first paragraph, key
     *   headings, and CTAs while reducing overall density to the target range.
     *
     * Protected slots (never replaced regardless of budget):
     *   - intro_paragraphs[0]: must contain focus keyword for Rank Math ÃƒÂ¢Ã…â€œÃ¢â‚¬Å“
     *   - watch_section_html, external_info_html: CTA affiliate HTML, never touched
     *   - faq_items[*]['q']: FAQ H3 question headings keep the name for context
     *
     * @param array  $tp_payload         The assembled TemplatePool renderer payload.
     * @param string $name               Model display name (focus keyword).
     * @param string $platform_label     Primary platform label.
     * @param string $protected_bio_text Operator-reviewed bio text to protect (may be empty).
     * @return array Modified payload.
     */
    private static function reduce_focus_keyword_density_in_payload(
        array $tp_payload,
        string $name,
        string $platform_label,
        string $protected_bio_text = ''
    ): array {
        if ($name === '') {
            return $tp_payload;
        }

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Page-level budget ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // Target: 10 exact model-name mentions on a 750ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Å“900 word page.
        // Below 8 would hurt readability; above 12 risks the Rank Math warning.
        $target_exact_mentions = 10;

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Substitution pool ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // v5.8.25 revised: name-FREE substitutes so the density reducer actually
        // lowers the exact focus-keyword count. Name-bearing strings (e.g.
        // "$name . "'s profile'") still match the focus-keyword regex and would
        // not reduce density. These generic phrases operate on body paragraph text
        // only (reducible_bag_keys); headings, intro[0], and CTAs are protected.
        // Known bad artifacts ("The confirmed profile", bare "She" etc.) are caught
        // downstream by sanitize_placeholder_artifacts() ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â not produced here.
        $subs = [
            'this profile',
            'the profile',
            'this live room',
            'the live room',
            'the confirmed room',
            'the verified room',
            'the performer profile',
        ];
        if ($platform_label !== '' && $platform_label !== self::NEUTRAL_PLATFORM_FALLBACK) {
            $subs[] = 'the confirmed ' . $platform_label . ' room';
        }

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Build the protected bio fingerprint ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // Normalise whitespace for comparison.
        $bio_fingerprint = '';
        if ($protected_bio_text !== '') {
            $bio_fingerprint = (string) preg_replace('/\s+/u', ' ', trim($protected_bio_text));
        }

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Reducible paragraph bag keys ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // intro_paragraphs[0] is protected (processed separately).
        // HTML-bearing keys (watch_section_html, comparison_section_html, etc.) are never included.
        // faq_items (both q and a) are fully excluded ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â FAQ must keep the model name.
        // questions_section_paragraphs excluded ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â may contain FAQ/heading copy.
        // v5.8.26: only plain descriptive paragraph bags are reducible.
        $reducible_bag_keys = [
            'watch_section_paragraphs',
            'about_section_paragraphs',
            'features_section_paragraphs',
            'comparison_section_paragraphs',
            'official_links_section_paragraphs',
        ];

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Step 1: count exact mentions across all reducible content ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // This gives us the page-level total to compare against the budget.
        $name_pattern = '/\b' . preg_quote($name, '/') . '\b/iu';
        $total_mentions = 0;

        // intro_paragraphs[0] is a protected slot ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â always kept, counted but not reduced.
        $intro_0_text = '';
        if (!empty($tp_payload['intro_paragraphs']) && is_array($tp_payload['intro_paragraphs'])) {
            $intro_0_text = (string) ($tp_payload['intro_paragraphs'][0] ?? '');
            $hits = preg_match_all($name_pattern, $intro_0_text);
            $total_mentions += (int) $hits;
        }
        foreach ($reducible_bag_keys as $key) {
            if (empty($tp_payload[$key]) || !is_array($tp_payload[$key])) {
                continue;
            }
            foreach ($tp_payload[$key] as $para) {
                $para_text = (string) $para;
                // Skip protected bio paragraphs from counting too
                $para_norm = (string) preg_replace('/\s+/u', ' ', trim($para_text));
                if ($bio_fingerprint !== '' && $para_norm === $bio_fingerprint) {
                    continue;
                }
                $hits = preg_match_all($name_pattern, $para_text);
                $total_mentions += (int) $hits;
            }
        }
        // Also count intro_paragraphs[1..n]
        if (!empty($tp_payload['intro_paragraphs']) && is_array($tp_payload['intro_paragraphs'])) {
            for ($i = 1; $i < count($tp_payload['intro_paragraphs']); $i++) {
                $para_text = (string) ($tp_payload['intro_paragraphs'][$i] ?? '');
                $para_norm = (string) preg_replace('/\s+/u', ' ', trim($para_text));
                if ($bio_fingerprint !== '' && $para_norm === $bio_fingerprint) {
                    continue;
                }
                $hits = preg_match_all($name_pattern, $para_text);
                $total_mentions += (int) $hits;
            }
        }

        // If already at or below budget, nothing to do.
        if ($total_mentions <= $target_exact_mentions) {
            return $tp_payload;
        }

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Step 2: apply page-level budget reduction ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // We walk all reducible paragraphs in document order, tracking a shared
        // counter. The first $target_exact_mentions occurrences page-wide are
        // kept; everything beyond that is replaced.
        // intro_paragraphs[0] is always fully preserved (counted in budget,
        // never passed through the replacer).
        $kept_so_far = (int) preg_match_all($name_pattern, $intro_0_text); // pre-spend budget on intro[0]
        $sub_idx = 0;

        $apply_budget = function(string $text) use (
            $name_pattern, $subs, $target_exact_mentions, &$kept_so_far, &$sub_idx
        ): string {
            if ($text === '') {
                return $text;
            }
            return (string) preg_replace_callback(
                $name_pattern,
                static function (array $m) use ($subs, $target_exact_mentions, &$kept_so_far, &$sub_idx): string {
                    if ($kept_so_far < $target_exact_mentions) {
                        $kept_so_far++;
                        return $m[0]; // still within budget ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â keep exact name
                    }
                    // Budget exhausted ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â substitute
                    $replacement = $subs[$sub_idx % count($subs)];
                    $sub_idx++;
                    // Preserve leading capitalisation
                    if (mb_strlen($m[0]) > 0 && mb_strtolower(mb_substr($m[0], 0, 1, 'UTF-8'), 'UTF-8') !== mb_substr($m[0], 0, 1, 'UTF-8')) {
                        return ucfirst($replacement);
                    }
                    return $replacement;
                },
                $text
            ) ?: $text;
        };

        // Apply to intro_paragraphs[1..n] (skip [0])
        if (!empty($tp_payload['intro_paragraphs']) && is_array($tp_payload['intro_paragraphs'])) {
            $paras = $tp_payload['intro_paragraphs'];
            for ($i = 1; $i < count($paras); $i++) {
                $para_text = (string) $paras[$i];
                $para_norm = (string) preg_replace('/\s+/u', ' ', trim($para_text));
                // Issue 2: skip operator-reviewed bio paragraphs
                if ($bio_fingerprint !== '' && $para_norm === $bio_fingerprint) {
                    continue;
                }
                $paras[$i] = $apply_budget($para_text);
            }
            $tp_payload['intro_paragraphs'] = $paras;
        }

        // Apply to all reducible paragraph bag keys
        foreach ($reducible_bag_keys as $key) {
            if (empty($tp_payload[$key]) || !is_array($tp_payload[$key])) {
                continue;
            }
            $reduced = [];
            foreach ($tp_payload[$key] as $para) {
                $para_text = (string) $para;
                $para_norm = (string) preg_replace('/\s+/u', ' ', trim($para_text));
                // Issue 2: skip operator-reviewed bio paragraphs
                if ($bio_fingerprint !== '' && $para_norm === $bio_fingerprint) {
                    $reduced[] = $para_text; // keep verbatim
                    continue;
                }
                $reduced[] = $apply_budget($para_text);
            }
            $tp_payload[$key] = $reduced;
        }

        return $tp_payload;
    }




    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
    // v5.8.24: Final-render HTML cleanup for manual model Generate
    // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬

    /**
     * Final-render cleanup ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â runs on the fully assembled $content HTML string.
     *
     * Called from build_model() after ALL previous passes complete:
     * ModelPageRenderer::render ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ evidence prepend ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ ModelCopyCleanup ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢
     * expand_word_count ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ guard_keyword_density ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ enforce_keyword_headings ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢
     * final_template_copy_cleanup ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ dedupe_templatepool_output ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ THIS.
     *
     * Three fixes applied in this order:
     *
     * 1. FAQ H3 grammar
     *    <h3>Report a fake {model} profile if I find one?</h3>
     *    ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ <h3>Can I report a fake {model} profile if I find one?</h3>
     *    Matches any <h3> starting with an imperative verb and ending with '?'.
     *
     * 2. Duplicate heading removal
     *    Detects two H2 headings with the same 4-word prefix.
     *    Keeps the longer/better one (keyword-enriched by heading enforcement).
     *    Removes the shorter generic duplicate.
     *
     * 3. Page-level model-name density reduction
     *    Walks HTML tag-by-tag. Only mutates TEXT NODES.
     *    Protected zones (never modified):
     *      - <!-- tmwseo-seed-evidence:start/end --> block
     *      - Inside <a>...</a> (CTA/affiliate links)
     *      - First <h2>...</h2> (focus-keyword heading)
     *      - First <p>...</p> (Rank Math first-10% check)
     *    Budget: 12 exact model-name mentions.
     *    Beyond budget: round-robin natural substitutions.
     *
     * Always logs [TMW-POOL-DENSITY] when WP_DEBUG is on.
     *
     * @param string $html           Fully rendered model page HTML.
     * @param string $name           Model display name (focus keyword).
     * @param string $platform_label Primary platform label.
     * @param int    $post_id        Post ID for debug logging.
     * @return string
     */
    private static function templatepool_final_render_cleanup(
        string $html,
        string $name,
        string $platform_label,
        int $post_id
    ): string {
        $budget = 12;

        // Empty-content guard ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â log and return unchanged.
        if (trim($html) === '' || $name === '') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[TMW-POOL-DENSITY] post_id=%d before=0 after=0 budget=%d mode=empty_content',
                    $post_id, $budget
                ));
            }
            return $html;
        }

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Fix 1: FAQ / H3 grammar ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // Converts imperative-verb H3 question headings to first-person form.
        // Operates on the rendered <h3> tags so it catches any FAQ source path.
        $html = (string) preg_replace_callback(
            '/<h3(\b[^>]*)>\s*(Report|Find|Tell|Show|Check|Verify|View|Open|Use|Start|Get|See|Track|Follow|Block|Access)\b([^<]*\?)\s*<\/h3>/iu',
            static function (array $m): string {
                return '<h3' . $m[1] . '>Can I ' . lcfirst($m[2]) . $m[3] . '</h3>';
            },
            $html
        ) ?: $html;

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Fix 2: Duplicate H2 heading removal (offset-based) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // Detects H2 pairs sharing a 4-word prefix; marks the shorter/worse
        // one for removal. Uses byte offsets from PREG_OFFSET_CAPTURE so that
        // only the specific duplicate occurrence is excised, never the kept one.
        // Removals are applied right-to-left (highest offset first) so earlier
        // offsets remain valid after each substr_replace call.
        if (preg_match_all('/<h2\b[^>]*>(.*?)<\/h2>/isu', $html, $h2m, PREG_OFFSET_CAPTURE)) {
            // $h2m[0] = [[full_tag, byte_offset], ...]
            // $h2m[1] = [[inner_text, byte_offset], ...]
            $seen_prefixes   = []; // prefix ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢ ['inner' => ..., 'offset' => ..., 'len' => ...]
            $offsets_to_drop = []; // [[offset, len], ...] ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â sorted desc before applying

            foreach ($h2m[0] as $idx => $match) {
                $full_tag    = (string) $match[0];
                $byte_offset = (int)   $match[1];
                $tag_len     = strlen($full_tag);  // byte length, not char length
                $inner       = trim(wp_strip_all_tags((string) ($h2m[1][$idx][0] ?? '')));
                $words       = preg_split('/\s+/u', $inner, 5, PREG_SPLIT_NO_EMPTY);
                $prefix      = mb_strtolower(implode(' ', array_slice((array) $words, 0, 4)), 'UTF-8');
                if ($prefix === '') {
                    continue;
                }

                if (isset($seen_prefixes[$prefix])) {
                    $prev = $seen_prefixes[$prefix];
                    if (strlen($inner) >= strlen($prev['inner'])) {
                        // Current is better ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â drop the previously seen occurrence.
                        $offsets_to_drop[] = [$prev['offset'], $prev['len']];
                        $seen_prefixes[$prefix] = [
                            'inner'  => $inner,
                            'offset' => $byte_offset,
                            'len'    => $tag_len,
                        ];
                    } else {
                        // Previous is better ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â drop current occurrence.
                        $offsets_to_drop[] = [$byte_offset, $tag_len];
                    }
                } else {
                    $seen_prefixes[$prefix] = [
                        'inner'  => $inner,
                        'offset' => $byte_offset,
                        'len'    => $tag_len,
                    ];
                }
            }

            if (!empty($offsets_to_drop)) {
                // Sort descending by offset ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â process from end to start so that
                // earlier byte positions are not invalidated by each removal.
                usort($offsets_to_drop, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
                foreach ($offsets_to_drop as [$off, $len]) {
                    $html = substr_replace($html, '', $off, $len);
                }
            }
        }

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Fix 3: Page-level model-name density reduction ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // Count exact mentions BEFORE reduction (in visible text only).
        $name_pattern = '/\b' . preg_quote($name, '/') . '\b/iu';
        $before_count = (int) preg_match_all($name_pattern, wp_strip_all_tags($html));

        if ($before_count <= $budget) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[TMW-POOL-DENSITY] post_id=%d before=%d after=%d budget=%d mode=no_reduction_needed',
                    $post_id, $before_count, $before_count, $budget
                ));
            }
            return $html;
        }

        // Substitution pool ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â v5.8.25 revised: name-FREE phrases so the HTML-level
        // density reducer actually lowers the exact focus-keyword count.
        // Name-bearing strings still match the focus-keyword regex and would not
        // reduce density. These phrases operate on text nodes only; protected zones
        // (first <p>, first <h2>, <a> anchors, evidence block) are never touched.
        // sanitize_placeholder_artifacts() runs after this pass and corrects any
        // known bad artifact strings that survive substitution.
        $subs = [
            'this profile',
            'the profile',
            'this live room',
            'the live room',
            'the confirmed room',
            'the verified room',
            'the performer profile',
        ];
        if ($platform_label !== '' && $platform_label !== self::NEUTRAL_PLATFORM_FALLBACK) {
            $subs[] = 'the confirmed ' . $platform_label . ' room';
        }

        // Split evidence block away ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â never touch operator-reviewed content.
        $ev_start_marker = '<!-- tmwseo-seed-evidence:start -->';
        $ev_end_marker   = '<!-- tmwseo-seed-evidence:end -->';
        $has_ev_block    = (strpos($html, $ev_start_marker) !== false);

        if ($has_ev_block) {
            $pos_start = (int) strpos($html, $ev_start_marker);
            $pos_end   = strpos($html, $ev_end_marker);
            if ($pos_end !== false) {
                $pos_end  += strlen($ev_end_marker);
                $seg_before = substr($html, 0, $pos_start);
                $seg_ev     = substr($html, $pos_start, $pos_end - $pos_start);
                $seg_after  = substr($html, $pos_end);
            } else {
                $seg_before = $html;
                $seg_ev     = '';
                $seg_after  = '';
                $has_ev_block = false;
            }
        } else {
            $seg_before = $html;
            $seg_ev     = '';
            $seg_after  = '';
        }

        // Shared page-level counters across all segments.
        $kept_so_far = 0;
        $sub_idx     = 0;

        /**
         * Walk one HTML segment, replacing model-name in TEXT NODES only.
         * v5.8.27 protected zones (never substituted, only counted toward budget):
         *   - inside <a>ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦</a>  (CTA/affiliate anchor text, internal links)
         *   - inside ANY <h2>ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦</h2>  (all section headings)
         *   - inside ANY <h3>ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦</h3>  (FAQ questions, sub-headings)
         *   - inside <li>ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦</li>  (More Pages list items, platform lists)
         *   - first <p>ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦</p>  (Rank Math first-10% check)
         *   - inside FAQ block: all <p> tags between the FAQ section H2
         *     (matching "Common ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ Profile Questions") and the next <h2> or end.
         *     This protects FAQ answer paragraphs which live in <p> not <h3>.
         *   - any <p> whose text contains "When checking" and "webcam" ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â the
         *     build_secondary_link_keyword_paragraph() output must not be mutated.
         * Uses shared &$kept_so_far and &$sub_idx counters.
         */
        $reduce_segment = static function (
            string $segment,
            string $name_pattern,
            array  $subs,
            int    $budget,
            int    &$kept_so_far,
            int    &$sub_idx,
            bool   $protect_first_h2,
            bool   $protect_first_para
        ): string {
            if ($segment === '') {
                return $segment;
            }
            $parts = preg_split('/(<[^>]+>)/u', $segment, -1, PREG_SPLIT_DELIM_CAPTURE);
            if (!is_array($parts)) {
                return $segment;
            }

            $in_anchor     = false;
            $in_any_h2     = false;
            $in_any_h3     = false;
            $in_any_li     = false;
            $in_first_h2   = false;
            $first_h2_done = !$protect_first_h2;
            $in_first_p    = false;
            $first_p_done  = !$protect_first_para;

            // v5.8.27: FAQ block protection.
            // Once we see an H2 that looks like "Common ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ Profile Questions",
            // every subsequent <p> until the next <h2> is protected.
            $in_faq_block  = false;  // true from FAQ H2 to next H2
            $in_faq_p      = false;  // true while inside a <p> inside the FAQ block

            // v5.8.27: "When checking" paragraph protection.
            // Paragraphs whose text starts with or contains "When checking" and
            // "webcam" must not be reduced (produced by build_secondary_link_keyword_paragraph).
            $in_when_checking_p  = false;
            $when_checking_buf   = '';  // accumulate text of current <p> for detection

            foreach ($parts as $i => $part) {
                if ($part === '') {
                    continue;
                }
                if ($part[0] === '<') {
                    // Tag node ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â update state flags only.
                    if (preg_match('/^<\s*a\b/iu', $part)) {
                        $in_anchor = true;
                    } elseif (preg_match('/^<\s*\/\s*a\s*>/iu', $part)) {
                        $in_anchor = false;
                    }
                    // H2 handling ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â also tracks FAQ block entry/exit
                    if (preg_match('/^<\s*h2\b/iu', $part)) {
                        $in_any_h2   = true;
                        $in_first_h2 = !$first_h2_done;
                        // A new H2 ends any active FAQ block
                        if ($in_faq_block) {
                            $in_faq_block = false;
                            $in_faq_p     = false;
                        }
                    } elseif (preg_match('/^<\s*\/\s*h2\s*>/iu', $part)) {
                        $in_any_h2 = false;
                        if ($in_first_h2) {
                            $in_first_h2   = false;
                            $first_h2_done = true;
                        }
                    }
                    // H3 protection (FAQ questions, sub-headings)
                    if (preg_match('/^<\s*h3\b/iu', $part)) {
                        $in_any_h3 = true;
                    } elseif (preg_match('/^<\s*\/\s*h3\s*>/iu', $part)) {
                        $in_any_h3 = false;
                    }
                    // LI protection (More Pages / link lists)
                    if (preg_match('/^<\s*li\b/iu', $part)) {
                        $in_any_li = true;
                    } elseif (preg_match('/^<\s*\/\s*li\s*>/iu', $part)) {
                        $in_any_li = false;
                    }
                    // First-para protection
                    if (!$first_p_done) {
                        if (preg_match('/^<\s*p\b/iu', $part)) {
                            $in_first_p = true;
                        } elseif (preg_match('/^<\s*\/\s*p\s*>/iu', $part) && $in_first_p) {
                            $in_first_p   = false;
                            $first_p_done = true;
                        }
                    }
                    // FAQ block: <p> open/close
                    if ($in_faq_block) {
                        if (preg_match('/^<\s*p\b/iu', $part)) {
                            $in_faq_p = true;
                        } elseif (preg_match('/^<\s*\/\s*p\s*>/iu', $part) && $in_faq_p) {
                            $in_faq_p = false;
                        }
                    }
                    // "When checking" paragraph: <p> open/close
                    if (preg_match('/^<\s*p\b/iu', $part)) {
                        $in_when_checking_p = false; // reset; we will decide after reading text
                        $when_checking_buf  = '';
                    } elseif (preg_match('/^<\s*\/\s*p\s*>/iu', $part)) {
                        $in_when_checking_p = false;
                        $when_checking_buf  = '';
                    }
                    continue;
                }

                // Text node: check if we just entered a "When checking" paragraph.
                // We detect it on the first text node of the <p>.
                if ($when_checking_buf === '' && stripos($part, 'When checking') !== false) {
                    // Mark this whole paragraph as protected if it also contains 'webcam' or 'live'
                    if (stripos($part, 'webcam') !== false || stripos($part, 'live') !== false) {
                        $in_when_checking_p = true;
                    }
                }
                $when_checking_buf .= $part;

                // Check if current H2 text marks the start of the FAQ block.
                // The FAQ H2 always matches "Common ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ Profile Questions".
                if ($in_any_h2 && preg_match('/Common\b.*\bProfile\s+Questions/iu', $part)) {
                    $in_faq_block = true;
                }

                // Text node ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â apply protection check.
                if ($in_anchor || $in_any_h2 || $in_any_h3 || $in_any_li || $in_first_p
                    || $in_faq_p || $in_when_checking_p) {
                    // Protected zone ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â count toward budget but do not replace.
                    $kept_so_far += (int) preg_match_all($name_pattern, $part);
                    continue;
                }

                // Apply budget.
                $parts[$i] = (string) preg_replace_callback(
                    $name_pattern,
                    static function (array $m) use ($subs, $budget, &$kept_so_far, &$sub_idx): string {
                        if ($kept_so_far < $budget) {
                            $kept_so_far++;
                            return $m[0];
                        }
                        $r = $subs[$sub_idx % count($subs)];
                        $sub_idx++;
                        // Preserve title-case when original started with uppercase.
                        $first_char = mb_substr($m[0], 0, 1, 'UTF-8');
                        if ($first_char !== mb_strtolower($first_char, 'UTF-8')) {
                            return ucfirst($r);
                        }
                        return $r;
                    },
                    $part
                ) ?: $part;
            }

            return implode('', $parts);
        };

        // Process before-evidence segment (both H2 and first-para protected).
        $seg_before = $reduce_segment(
            $seg_before, $name_pattern, $subs, $budget,
            $kept_so_far, $sub_idx, true, true
        );

        // Process after-evidence segment.
        if ($has_ev_block && $seg_after !== '') {
            // Determine whether the first <h2> and first
            // <p> were already consumed while processing $seg_before. If the
            // evidence block is prepended at the very start and $seg_before is
            // empty (or has no headings/paragraphs), the first real <h2> and <p>
            // live in $seg_after and still need protection.
            $before_had_h2   = (bool) preg_match('/<h2\\b/iu', $seg_before);
            $before_had_para = (bool) preg_match('/<p\\b/iu',  $seg_before);
            // Protect in $seg_after only when NOT already seen in $seg_before.
            $seg_after = $reduce_segment(
                $seg_after, $name_pattern, $subs, $budget,
                $kept_so_far, $sub_idx,
                !$before_had_h2,   // protect first H2  if $seg_before had none
                !$before_had_para  // protect first <p> if $seg_before had none
            );
        }

        $html = $seg_before . $seg_ev . $seg_after;

        $after_count = (int) preg_match_all($name_pattern, wp_strip_all_tags($html));

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[TMW-POOL-DENSITY] post_id=%d before=%d after=%d budget=%d mode=%s',
                $post_id,
                $before_count,
                $after_count,
                $budget,
                $after_count < $before_count ? 'safe_body_only' : ($before_count > $budget ? 'protected_zones_only' : 'no_reduction_needed')
            ));
        }

        // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Fix 4: Placeholder artifact sanitizer ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
        // v5.8.33: Structural low-value model-name softening.
        // Runs after the density reducer as a final targeted pass. Catches
        // known low-value structural phrases that the budget-based reducer
        // may not have reached (because protected zones consumed the budget).
        // These phrases exist in section-template variants that use {{name}}
        // but where the model name adds no SEO or clarity value.
        // Logs [TMW-KW-DENSITY-BALANCE] when WP_DEBUG is on.
        $n_esc = preg_quote( $name, '/' );

        // Pattern A: "joining any {Name} room" → "joining any live room"
        // Source: before_you_click section template variants [0] and [2].
        $html = (string) preg_replace(
            '/\bjoining\s+any\s+' . $n_esc . '\s+room\b/iu',
            'joining any live room',
            $html
        );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG
            && preg_match( '/joining any live room/i', $html )
        ) {
            error_log( sprintf(
                '[TMW-KW-DENSITY-BALANCE] replaced post_id=%d phrase="joining any [name] room" reason="low_value_model_name"',
                $post_id
            ) );
        }

        // Pattern B: "Continue exploring {Name}'s content on…" → "Continue exploring this model's content on…"
        // Source: more_pages section template variants [0] and [7].
        $html = (string) preg_replace(
            '/\bContinue\s+exploring\s+' . $n_esc . "'s\s+content\b/iu",
            "Continue exploring this model's content",
            $html
        );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG
            && preg_match( "/Continue exploring this model's content/i", $html )
        ) {
            error_log( sprintf(
                '[TMW-KW-DENSITY-BALANCE] replaced post_id=%d phrase="Continue exploring [name] content" reason="low_value_model_name"',
                $post_id
            ) );
        }

        // v5.8.25: A final deterministic string-replace pass removes any known
        // bad artifact phrases that survive density reduction. These originate
        // from TemplatePool template literals that were rendered before the
        // density-reducer ran, e.g. "the confirmed profile" in anchor text or
        // FAQ answers that the token-based reducer did not mutate.
        // All replacements use plain ASCII apostrophes. Case-insensitive.
        // Protected zones (evidence block, <a> anchors) already handled above.
        $html = self::sanitize_placeholder_artifacts($html, $name, $platform_label);

        return $html;
    }

    /**
     * Deterministic placeholder-artifact sanitizer (v5.8.25 + v5.8.26 additions).
     *
     * Removes known bad phrases produced when density-reducer substitution strings
     * survived into headings, FAQ text, anchor text, or link labels.
     *
     * Operates on the fully assembled HTML string. Does NOT touch:
     *   - <!-- tmwseo-seed-evidence:start/end --> blocks (split out before processing)
     *   - <a href="..."> href attribute values
     *
     * Uses plain ASCII apostrophes throughout (no curly/smart quotes).
     *
     * @param string $html           Fully assembled model page HTML.
     * @param string $name           Model display name (focus keyword).
     * @param string $platform_label Primary platform label.
     * @return string
     */
    private static function sanitize_placeholder_artifacts(
        string $html,
        string $name,
        string $platform_label
    ): string {
        if (trim($html) === '' || $name === '') {
            return $html;
        }

        $n  = $name;
        $pl = ($platform_label !== '' && $platform_label !== self::NEUTRAL_PLATFORM_FALLBACK)
            ? $platform_label
            : 'LiveJasmin';

        // Split out evidence block so operator-reviewed content is never touched.
        $ev_start = '<!-- tmwseo-seed-evidence:start -->';
        $ev_end   = '<!-- tmwseo-seed-evidence:end -->';
        $has_ev   = (strpos($html, $ev_start) !== false);
        $seg_ev   = '';
        $seg_body = $html;

        if ($has_ev) {
            $ps = (int) strpos($html, $ev_start);
            $pe = strpos($html, $ev_end);
            if ($pe !== false) {
                $pe        += strlen($ev_end);
                $seg_before = substr($html, 0, $ps);
                $seg_ev     = substr($html, $ps, $pe - $ps);
                $seg_after  = substr($html, $pe);
                $seg_body   = $seg_before . "\x02EVBLOCK\x03" . $seg_after;
            }
        }

        // Ordered replacement table ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â most specific first to avoid partial matches.
        // Plain ASCII apostrophes used throughout.
        // v5.8.26: expanded with new density-reducer artifact strings.
        $replacements = [
            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ v5.8.26: New density-reducer artifacts ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            // Heading artifacts
            'Common This profile Profile Questions'         => 'Common ' . $n . ' Profile Questions',
            'Common The profile Profile Questions'          => 'Common ' . $n . ' Profile Questions',
            'Common The performer profile Profile Questions' => 'Common ' . $n . ' Profile Questions',
            // FAQ question artifacts from name-free reducer
            'How do I find the correct The profile profile' => 'How do I find the correct ' . $n . ' profile',
            'How do I find the correct This profile profile' => 'How do I find the correct ' . $n . ' profile',
            'Does This live room offer'                     => 'Does ' . $n . ' offer',
            'Does The live room offer'                      => 'Does ' . $n . ' offer',
            'Does This profile offer'                       => 'Does ' . $n . ' offer',
            'Does The profile offer'                        => 'Does ' . $n . ' offer',
            'Does The confirmed room offer'                 => 'Does ' . $n . ' offer',
            'Does The verified room offer'                  => 'Does ' . $n . ' offer',
            'for The live room'                             => 'for ' . $n,
            'for This live room'                            => 'for ' . $n,
            'for This profile'                              => 'for ' . $n . "'s profile",
            'for The profile'                               => 'for ' . $n . "'s profile",
            "The confirmed room's video archive"            => $n . "'s video archive",
            "This profile's video archive"                  => $n . "'s video archive",
            "The profile's video archive"                   => $n . "'s video archive",
            // Internal link / anchor text artifacts
            'Watch a The verified room Video'               => 'Watch ' . $n . "'s videos",
            'Watch a The verified room video'               => 'Watch ' . $n . "'s videos",
            'The Watch a The verified room Video link'      => 'the ' . self::model_video_anchor_text($n) . ' link',
            'The Watch a The verified room video link'      => 'the ' . self::model_video_anchor_text($n) . ' link',
            'The performer profile video archive'           => $n . ' video archive',
            'This profile video archive'                    => $n . ' video archive',
            'The profile video archive'                     => $n . ' video archive',
            'fake The confirmed ' . $pl . ' room profile'   => 'fake ' . $n . ' profile',
            'fake The confirmed LiveJasmin room profile'    => 'fake ' . $n . ' profile',
            'fake The confirmed room profile'               => 'fake ' . $n . ' profile',
            'fake The live room profile'                    => 'fake ' . $n . ' profile',
            'When checking This profile live webcam'        => 'When checking ' . $n . ' live webcam',
            'When checking The profile live webcam'         => 'When checking ' . $n . ' live webcam',
            'Find The profile elsewhere'                    => 'Find ' . $n . ' elsewhere',
            'Find This profile elsewhere'                   => 'Find ' . $n . ' elsewhere',
            'Find The confirmed room elsewhere'             => 'Find ' . $n . ' elsewhere',
            'Find The verified room elsewhere'              => 'Find ' . $n . ' elsewhere',
            // ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ v5.8.25: Original artifact strings (kept) ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬
            'Watch a The verified live room Video'           => 'Watch ' . $n . "'s videos",
            'Watch a The verified live room video'           => 'Watch ' . $n . "'s videos",
            'Find The confirmed ' . $pl . ' room elsewhere'  => 'Find ' . $n . "'s confirmed " . $pl . ' room',
            'Find The confirmed LiveJasmin room elsewhere'   => 'Find ' . $n . "'s confirmed LiveJasmin room",
            'Find The confirmed live room elsewhere'         => 'Find ' . $n . "'s confirmed live room",
            'Can I find The confirmed profile\'s video archive'  => 'Can I find ' . $n . "'s video archive",
            "Can I find The confirmed profile's video archive"   => 'Can I find ' . $n . "'s video archive",
            'Can I report a fake The live room profile if I find one?' => 'Can I report a fake ' . $n . ' profile if I find one?',
            'Can I report a fake The live room profile'          => 'Can I report a fake ' . $n . ' profile',
            'Does She offer'   => 'Does ' . $n . ' offer',
            'Does she offer'   => 'Does ' . $n . ' offer',
            'for Her profile'  => 'for ' . $n . "'s profile",
            'for her profile'  => 'for ' . $n . "'s profile",
            'The confirmed profile\'s'   => $n . "'s",
            "The confirmed profile's"    => $n . "'s",
            'the confirmed profile\'s'   => $n . "'s",
            "the confirmed profile's"    => $n . "'s",
            'The confirmed profile'      => $n . "'s confirmed profile",
            'the confirmed profile'      => $n . "'s confirmed profile",
            'The live room profile'      => $n . "'s profile",
            'the live room profile'      => $n . "'s profile",
            'The verified live room'     => $n . "'s verified live room",
            'the verified live room'     => $n . "'s live room",
        ];

        foreach ($replacements as $bad => $good) {
            $seg_body = str_replace($bad, $good, $seg_body);
        }

        // Restore evidence block.
        if ($has_ev && $seg_ev !== '') {
            $seg_body = str_replace("\x02EVBLOCK\x03", $seg_ev, $seg_body);
        }

        return $seg_body;
    }

    /**
     * Neutralise low-value model-name repetitions in resolved FAQ questions and answers.
     *
     * Called after TemplatePool FAQ resolution and grammar-fix pass, so {{name}} is
     * already substituted with the real model name. Only specific known patterns are
     * rewritten — questions and answer sentences where the model name adds no SEO or
     * clarity value and only inflates focus-keyword density.
     *
     * Preserved (not touched):
     *   - "How much does a private show with {Name} cost?" (high-intent SEO question)
     *   - "How do I find the correct {Name} profile on {Platform}?" (handle-verification Q)
     *   - Any FAQ item not matching a known low-value pattern
     *
     * @param  array<int,array{q:string,a:string}> $faq_items  Resolved FAQ items.
     * @param  string                              $name        Model display name.
     * @return array<int,array{q:string,a:string}>
     */
    private static function neutralize_low_value_faq_name_mentions( array $faq_items, string $name ): array {
        if ( $name === '' || empty( $faq_items ) ) {
            return $faq_items;
        }

        $n = preg_quote( $name, '/' );

        $out = [];
        foreach ( $faq_items as $item ) {
            $q = (string) ( $item['q'] ?? '' );
            $a = (string) ( $item['a'] ?? '' );

            // ── Question rewrites ─────────────────────────────────────────────
            // Pattern 1: "Is {Name} available on multiple platforms?"
            $q = (string) preg_replace(
                '/^Is\s+' . $n . '\s+available\s+on\s+multiple\s+platforms\?$/iu',
                'Is this model available on multiple platforms?',
                $q
            );
            // Pattern 2: "Does {Name} have a fan page or subscription content?"
            $q = (string) preg_replace(
                '/^Does\s+' . $n . '\s+have\s+a\s+fan\s+page\s+or\s+subscription\s+content\?$/iu',
                'Does this model have a fan page or subscription content?',
                $q
            );
            // Pattern 3: "How do I protect my privacy when watching {Name} on {Platform}?"
            $q = (string) preg_replace(
                '/^How\s+do\s+I\s+protect\s+my\s+privacy\s+when\s+watching\s+' . $n . '\s+on\s+(.+?)\?$/iu',
                'How do I protect my privacy on $1?',
                $q
            );
            // Pattern 4: "Who are similar models to {Name} on {SiteName}?"
            $q = (string) preg_replace(
                '/^Who\s+are\s+similar\s+models\s+to\s+' . $n . '\s+on\s+(.+?)\?$/iu',
                'Who are similar models on $1?',
                $q
            );
            // Pattern 5: "How often is the {Name} profile page … updated?"
            $q = (string) preg_replace(
                '/^How\s+often\s+is\s+the\s+' . $n . '\s+profile\s+page\s+(?:on\s+.+?\s+)?updated\?$/iu',
                'How often is this profile page updated?',
                $q
            );

            // ── Answer rewrites ───────────────────────────────────────────────
            // Answer A1: "This page lists all confirmed platforms for {Name}."
            $a = (string) preg_replace(
                '/\bThis\s+page\s+lists\s+all\s+confirmed\s+platforms\s+for\s+' . $n . '\b/iu',
                'This page lists all confirmed platforms for this model',
                $a
            );
            // Answer A2: "Fan and subscription pages for {Name} are listed..."
            $a = (string) preg_replace(
                '/\bFan\s+and\s+subscription\s+pages\s+for\s+' . $n . '\s+are\s+listed\b/iu',
                'Fan and subscription pages are listed',
                $a
            );
            // Answer A3: "The Similar Models section … to {Name}."
            $a = (string) preg_replace(
                '/\bThe\s+Similar\s+Models\s+section\s+(?:on\s+this\s+page\s+)?lists\s+performers\s+with\s+comparable\s+styles\s+or\s+platforms\s+to\s+' . $n . '\b\.?/iu',
                'The Similar Models section lists performers with comparable styles or platforms.',
                $a
            );

            // Answer A4: cam-to-cam FAQ answer — "Check the private chat options
            // listed on this page for {Name} and confirm with the room controls."
            // Low-value structural mention; model name adds nothing here.
            $a = (string) preg_replace(
                '/\\bCheck\\s+the\\s+private\\s+chat\\s+options\\s+listed\\s+on\\s+this\\s+page\\s+for\\s+' . $n . '\\s+and\\s+confirm\\b/iu',
                'Check the private chat options listed on this page for this model and confirm',
                $a
            );

            $out[] = [ 'q' => $q, 'a' => $a ];
        }

        return $out;
    }


    /**
     * Extra Keyword Coverage Guard (v5.8.32).
     *
     * Runs as the very last content-shaping step inside build_model(), after
     * templatepool_final_render_cleanup() (density reducer + FAQ neutralizer).
     * Guarantees that each Rank Math extra keyword chip appears at least once as
     * an exact case-insensitive substring in the final generated body.
     *
     * When a keyword is missing the guard appends one short, natural sentence to
     * the end of the best-matching existing <p> block. It never:
     *   - creates new headings (H1–H4)
     *   - modifies <a> anchors or affiliate links
     *   - inserts inside <li> items
     *   - touches the evidence marker block
     *   - touches the SEO title or meta description
     *   - inserts the same keyword more than once
     *
     * All decisions are logged under [TMW-KW-COVERAGE] when WP_DEBUG is on.
     *
     * @param  string   $html                 Fully assembled model page HTML.
     * @param  string[] $rankmath_keywords     Up-to-4 Rank Math extra keyword chips.
     * @param  string   $name                 Model display name.
     * @param  string   $primary_platform     Primary platform label (e.g. "LiveJasmin").
     * @param  int      $post_id              Post ID (for log output only).
     * @return string
     */
    private static function guard_extra_keyword_coverage(
        string $html,
        array  $rankmath_keywords,
        string $name,
        string $primary_platform,
        int    $post_id
    ): string {

        $debug = defined('WP_DEBUG') && WP_DEBUG;

        // Normalise and deduplicate the keyword list.
        $keywords = array_values(array_unique(array_filter(
            array_map('trim', $rankmath_keywords),
            'strlen'
        )));

        if (empty($keywords) || trim($html) === '') {
            return $html;
        }

        $focus = trim($name);
        if ($debug) {
            error_log(sprintf(
                '[TMW-KW-COVERAGE] start post_id=%d focus="%s" extras_count=%d',
                $post_id,
                $focus,
                count($keywords)
            ));
        }

        // Evidence block boundaries — never insert inside these markers.
        $ev_start = '<!-- tmwseo-seed-evidence:start -->';
        $ev_end   = '<!-- tmwseo-seed-evidence:end -->';
        $ev_start_pos = mb_strpos($html, $ev_start, 0, 'UTF-8');
        $ev_end_pos   = ($ev_start_pos !== false)
            ? mb_strpos($html, $ev_end, $ev_start_pos, 'UTF-8')
            : false;

        $inserted_count = 0;
        $skipped_count  = 0;
        $max_insertions = count($keywords); // Rank Math free: 1 focus + up to 4 extras

        foreach ($keywords as $kw) {
            if ($kw === '') {
                continue;
            }

            $kw_lower = mb_strtolower($kw, 'UTF-8');

            // 1. Check if the exact phrase already appears anywhere in the HTML
            //    (case-insensitive, including headings and paragraphs).
            $html_lower = mb_strtolower($html, 'UTF-8');
            if (mb_strpos($html_lower, $kw_lower, 0, 'UTF-8') !== false) {
                if ($debug) {
                    error_log(sprintf(
                        '[TMW-KW-COVERAGE] found post_id=%d keyword="%s"',
                        $post_id,
                        $kw
                    ));
                }
                continue;
            }

            // 2. Hard stop: do not exceed max_insertions.
            if ($inserted_count >= $max_insertions) {
                if ($debug) {
                    error_log(sprintf(
                        '[TMW-KW-COVERAGE] skipped post_id=%d keyword="%s" reason="density_limit"',
                        $post_id,
                        $kw
                    ));
                }
                $skipped_count++;
                continue;
            }

            // 3. Classify the keyword type to select the best insertion section.
            //
            //    Priority order mirrors the preferred placement mapping in the audit:
            //      (a) platform  — live profile / access section
            //      (b) live cam  — where to watch / live room section
            //      (c) private chat / live chat — live chat section
            //      (d) model/cam profile — intro / profile section (first body <p>)
            //      (e) fallback — first suitable body <p> after the first <p>
            $kw_lower_trim = $kw_lower;
            $platform_lc   = mb_strtolower(trim($primary_platform), 'UTF-8');

            $is_platform    = (
                ($platform_lc !== '' && mb_strpos($kw_lower_trim, $platform_lc, 0, 'UTF-8') !== false)
                || (bool) preg_match('/\b(livejasmin|stripchat|chaturbate|camsoda|bongacams|flirt4free|myfreecams|cam4|streamate)\b/iu', $kw)
            );
            $is_live_cam    = (bool) preg_match('/\blive\s+cam\b/iu', $kw);
            $is_private     = (bool) preg_match('/\bprivate\s+(?:chat|webcam|show|session)\b|\blive\s+chat\b/iu', $kw);
            $is_profile     = (bool) preg_match('/\b(?:model|cam|webcam)\s+profile\b/iu', $kw);

            // Build the insertion sentence from approved patterns.
            $sentence = '';
            $section_hint = 'body';

            if ($is_platform) {
                $sentence     = 'Visitors searching for ' . esc_html($kw) . ' should start with the verified live-room link before checking any secondary profile.';
                $section_hint = 'platform';
            } elseif ($is_live_cam) {
                $sentence     = 'The verified room link is the safest starting point for ' . esc_html($kw) . ' access and current live status.';
                $section_hint = 'live_cam';
            } elseif ($is_private) {
                $sentence     = 'Before starting ' . esc_html($kw) . ', review the available session options and confirm the room is active.';
                $section_hint = 'private_chat';
            } elseif ($is_profile) {
                $sentence     = 'Use this page as a checked ' . esc_html($kw) . ' reference with profile notes, links, and room-status details.';
                $section_hint = 'model_profile';
            } else {
                // Generic fallback — still uses the keyword naturally.
                $sentence     = 'Visitors searching for ' . esc_html($kw) . ' should start with the verified live-room link before checking any secondary profile.';
                $section_hint = 'generic';
            }

            // 4. Find the best <p> target for appending the sentence.
            //    Strategy: locate the H2 whose inner text best matches the
            //    section_hint, then find the first <p> after that H2.
            //    Fall back to the second <p> in the document (never the first,
            //    which is the Rank Math first-10% paragraph).
            $target_h2_patterns = [];
            switch ($section_hint) {
                case 'platform':
                    $target_h2_patterns = [
                        '/livejasmin|stripchat|chaturbate|camsoda|official.*profile|access.*profile|profile.*access|verified.*profile/iu',
                        '/where\s+to\s+watch/iu',
                        '/live\s+cam\s+access/iu',
                    ];
                    break;
                case 'live_cam':
                    $target_h2_patterns = [
                        '/where\s+to\s+watch/iu',
                        '/live\s+cam\s+access/iu',
                        '/live\s+cam\s+private/iu',
                    ];
                    break;
                case 'private_chat':
                case 'model_profile':
                    $target_h2_patterns = [
                        '/live\s+chat\s+experience/iu',
                        '/private\s+chat/iu',
                    ];
                    break;
                default:
                    $target_h2_patterns = [
                        '/live\s+chat\s+experience/iu',
                        '/live\s+cam\s+access/iu',
                    ];
                    break;
            }

            // Build H2→content index.
            preg_match_all('/<h2\b[^>]*>(.*?)<\/h2>/isu', $html, $h2m, PREG_OFFSET_CAPTURE);
            $h2_positions = [];
            foreach ($h2m[0] as $i => $m) {
                $h2_positions[] = [
                    'offset' => (int) $m[1],
                    'end'    => (int) $m[1] + mb_strlen($m[0], 'UTF-8'),
                    'text'   => wp_strip_all_tags((string) $h2m[1][$i][0]),
                ];
            }

            // Find the best H2 match for this section hint.
            $best_h2_end = -1;
            foreach ($target_h2_patterns as $pat) {
                foreach ($h2_positions as $h2) {
                    if (preg_match($pat, $h2['text'])) {
                        $best_h2_end = $h2['end'];
                        break 2;
                    }
                }
            }

            // Find a suitable <p>...</p> to append to.
            // Rules: must be after $best_h2_end (or after first <p> if no H2 matched),
            //        must not be inside the evidence block, must not contain <a href,
            //        must not be inside a <li>.
            $appended    = false;
            $search_from = ($best_h2_end >= 0) ? $best_h2_end : 0;

            // Collect all <p>...</p> offsets starting from search_from.
            $p_pattern = '/<p>((?:(?!<\/p>).)+)<\/p>/isu';
            preg_match_all($p_pattern, $html, $pm, PREG_OFFSET_CAPTURE);

            $p_candidates = [];
            $skipped_first = ($best_h2_end < 0); // skip first <p> only when no H2 matched
            foreach ($pm[0] as $i => $m) {
                $p_offset = (int) $m[1];
                if ($p_offset < $search_from) {
                    continue;
                }
                if (!$skipped_first) {
                    // Skip the very first <p> when no H2 guide is available — it is
                    // the intro paragraph Rank Math uses for the first-10% check.
                    $skipped_first = true;
                    continue;
                }
                $p_candidates[] = ['offset' => $p_offset, 'match' => $m[0], 'inner' => $pm[1][$i][0]];
            }

            foreach ($p_candidates as $pc) {
                $p_offset = $pc['offset'];
                $p_full   = $pc['match'];
                $p_inner  = $pc['inner'];

                // Skip if inside evidence block.
                if (
                    $ev_start_pos !== false
                    && $ev_end_pos !== false
                    && $p_offset > $ev_start_pos
                    && $p_offset < $ev_end_pos
                ) {
                    continue;
                }

                // Skip if paragraph contains a link (affiliate/CTA protection).
                if (mb_strpos($p_inner, '<a ', 0, 'UTF-8') !== false
                    || mb_strpos($p_inner, '<a	', 0, 'UTF-8') !== false) {
                    continue;
                }

                // Skip very short paragraphs (bullets, labels) — less than 20 chars.
                if (mb_strlen(wp_strip_all_tags($p_inner), 'UTF-8') < 20) {
                    continue;
                }

                // Skip if the paragraph looks like it is already inside a <li>
                // (rough heuristic: no <li> tag should start within 5 chars before).
                $pre = mb_substr($html, max(0, $p_offset - 5), 5, 'UTF-8');
                if (mb_strpos($pre, '<li', 0, 'UTF-8') !== false) {
                    continue;
                }

                // Good candidate — append the sentence inside the closing </p>.
                $new_p = '<p>' . trim($p_inner) . ' ' . $sentence . '</p>';
                $html  = substr_replace($html, $new_p, $p_offset, strlen($p_full));

                if ($debug) {
                    error_log(sprintf(
                        '[TMW-KW-COVERAGE] inserted post_id=%d keyword="%s" section="%s"',
                        $post_id,
                        $kw,
                        $section_hint
                    ));
                }
                $inserted_count++;
                $appended = true;
                break;
            }

            if (!$appended) {
                if ($debug) {
                    error_log(sprintf(
                        '[TMW-KW-COVERAGE] skipped post_id=%d keyword="%s" reason="no_safe_section"',
                        $post_id,
                        $kw
                    ));
                }
                $skipped_count++;
            }
        } // end foreach $keywords

        if ($debug) {
            error_log(sprintf(
                '[TMW-KW-COVERAGE] done post_id=%d inserted=%d skipped=%d',
                $post_id,
                $inserted_count,
                $skipped_count
            ));
        }

        return $html;
    }


}
