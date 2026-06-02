<?php
namespace TMWSEO\Engine\Content;

use TMWSEO\Engine\Keywords\PageTypeKeywordFilter;
use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\Settings;

if (!defined('ABSPATH')) { exit; }

class ModelPageRenderer {
    public const NEUTRAL_FALLBACK = 'official profile links';

    /**
     * @param array<string,mixed> $payload
     */
    public static function render(string $model_name, array $payload): string {
        $name = trim($model_name) !== '' ? trim($model_name) : 'Live Cam Model';
        $focus_keyword = trim((string)($payload['focus_keyword'] ?? ''));
        if ($focus_keyword === '') {
            $focus_keyword = $name;
        }
        $post_id = max(0, (int) ($payload['post_id'] ?? $payload['model_post_id'] ?? 0));
        if (self::is_unsafe_model_seo_phrase($focus_keyword)) {
            self::debug_copy_guard($post_id, $name, $focus_keyword, 'instructional_model_seo_phrase', 'content heading', $name, []);
            $focus_keyword = $name;
        }

        $sections = [];
        $secondary_heading_slots = self::normalize_secondary_heading_slots($payload['secondary_heading_slots'] ?? [], $name, $post_id);
        $has_link_evidence_guard = array_key_exists('link_evidence_summary', $payload);
        $link_evidence = self::normalize_link_evidence_summary($payload['link_evidence_summary'] ?? []);
        if ($has_link_evidence_guard) {
            $payload = self::apply_link_evidence_rendering_guards($payload, $link_evidence);
        }

        // External evidence sections (v5.8.0–v5.8.6) REMOVED in v5.8.7.
        // Model Research Evidence is now applied at the generation save points
        // via ModelResearchEvidence::prepend_sections() — the renderer no
        // longer reads evidence-payload keys.

        $intro_paragraphs = $payload['intro_paragraphs'] ?? [];
        $seed_summary = trim((string)($payload['editor_seed_summary'] ?? ''));
        if ($seed_summary !== '') {
            array_unshift($intro_paragraphs, $seed_summary);
        }
        $intro_paragraphs = self::with_direct_intro_answer($intro_paragraphs, $name, $payload);
        $intro = self::render_section('Official Profile Access', $intro_paragraphs, $name);
        if ($intro !== '') {
            $sections[] = $intro;
        }

        $watch = self::render_section('Where to Watch Live', $payload['watch_section_paragraphs'] ?? [], $name, $payload['watch_section_html'] ?? '');
        if ($watch !== '') {
            $sections[] = $watch;
        }

        $other_official = self::render_section(
            'Other Official Destinations',
            $payload['official_destinations_section_paragraphs'] ?? [],
            $name,
            $payload['official_destinations_section_html'] ?? ''
        );
        if ($other_official !== '') {
            $sections[] = $other_official;
        }

        $social_channels = self::render_section(
            'Social Profiles, Link Hubs, and Channels',
            $payload['community_destinations_section_paragraphs'] ?? [],
            $name,
            $payload['community_destinations_section_html'] ?? ''
        );
        if ($social_channels !== '') {
            $sections[] = $social_channels;
        }

        $internal_links = self::render_section(
            'More Pages for ' . $name,
            $payload['internal_links_section_paragraphs'] ?? [],
            $name,
            $payload['internal_links_html'] ?? ''
        );
        if ($internal_links !== '') {
            $sections[] = $internal_links;
        }

        $about_allowed = self::should_render_editorial_section('about', $payload, $name);
        $about = $about_allowed
            ? self::render_section('About ' . $name, $payload['about_section_paragraphs'] ?? [], $name, $payload['about_section_html'] ?? '')
            : '';
        if ($about !== '') {
            $sections[] = $about;
        }

        $fans_allowed = self::should_render_editorial_section('fans_like', $payload, $name);
        $fans_like = $fans_allowed
            ? self::render_section('What Fans Like About ' . $name, $payload['fans_like_section_paragraphs'] ?? [], $name, $payload['fans_like_section_html'] ?? '')
            : '';
        if ($fans_like !== '') {
            $sections[] = $fans_like;
        }

        $features_heading = self::heading_with_focus('Live Chat Experience', $focus_keyword, $name);
        $features_heading = self::append_secondary_heading_phrase($features_heading, $secondary_heading_slots['features'][0] ?? '');
        $features = self::render_section(
            $features_heading,
            $payload['features_section_paragraphs'] ?? [],
            $name,
            $payload['features_section_html'] ?? '',
            self::build_secondary_subheadings($secondary_heading_slots['features'] ?? [], 1, 'Live Chat Experience for')
        );
        if ($features !== '') {
            $sections[] = $features;
        }

        $comparison_paragraphs = is_array($payload['comparison_section_paragraphs'] ?? null) ? $payload['comparison_section_paragraphs'] : [];
        $comparison_paragraphs = self::with_direct_compare_answer($comparison_paragraphs, $payload, $name);
        $comparison_heading = 'Before You Click';
        $comparison_heading = self::append_secondary_heading_phrase($comparison_heading, $secondary_heading_slots['comparison'][0] ?? '');
        $compare = self::render_section(
            $comparison_heading,
            $comparison_paragraphs,
            $name,
            $payload['comparison_section_html'] ?? '',
            self::build_secondary_subheadings($secondary_heading_slots['comparison'] ?? [], 1, 'Profile check for')
        );
        if ($compare !== '') {
            $sections[] = $compare;
        }

        $questions = self::render_questions(
            'Common Profile Questions',
            $payload['questions_section_paragraphs'] ?? [],
            $payload['faq_items'] ?? [],
            $name,
            self::build_secondary_subheadings($secondary_heading_slots['faq'] ?? [], 0, 'Verification steps for')
        );
        if ($questions !== '') {
            $sections[] = $questions;
        }

        $official_links_base_heading = (!empty($link_evidence) && empty($link_evidence['has_extra_links']))
            ? (!empty($link_evidence['has_live_profile']) ? 'Confirmed Live Profile' : 'Confirmed Profile Link Status')
            : 'Official Links and Profiles';
        $official_links_heading = self::append_secondary_heading_phrase($official_links_base_heading, $secondary_heading_slots['official_links'][0] ?? '');
        $links = self::render_section(
            $official_links_heading,
            $payload['official_links_section_paragraphs'] ?? [],
            $name,
            self::join_html_blocks([
                $payload['external_info_html'] ?? '',
                $payload['explore_more_html'] ?? '',
            ]),
            self::build_secondary_subheadings($secondary_heading_slots['official_links'] ?? [], 1, 'Official-link check for')
        );
        if ($links !== '' && !self::looks_like_nav_chrome($links)) {
            $sections[] = $links;
        }

        $html = implode("\n\n", $sections);
        return self::final_cleanup($html, $name, $has_link_evidence_guard ? $link_evidence : []);
    }

    /** @param mixed $raw @return array<string,mixed> */
    private static function normalize_link_evidence_summary($raw): array {
        $evidence = is_array($raw) ? $raw : [];
        $live = max(0, (int) ($evidence['live_count'] ?? 0));
        $extra = max(0, (int) ($evidence['extra_count'] ?? 0));
        $total = max(0, (int) ($evidence['total_count'] ?? ($live + $extra)));
        return array_merge($evidence, [
            'live_count' => $live,
            'extra_count' => $extra,
            'total_count' => $total,
            'has_live_profile' => !empty($evidence['has_live_profile']) || $live > 0,
            'has_extra_links' => !empty($evidence['has_extra_links']) || $extra > 0,
            'has_any_links' => !empty($evidence['has_any_links']) || ($live + $extra) > 0,
        ]);
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $evidence @return array<string,mixed> */
    private static function apply_link_evidence_rendering_guards(array $payload, array $evidence): array {
        if (!empty($evidence['has_extra_links'])) {
            return $payload;
        }

        $payload['official_destinations_section_paragraphs'] = [];
        $payload['official_destinations_section_html'] = '';
        $payload['community_destinations_section_paragraphs'] = [];
        $payload['community_destinations_section_html'] = '';

        foreach ([
            'intro_paragraphs',
            'watch_section_paragraphs',
            'features_section_paragraphs',
            'comparison_section_paragraphs',
            'questions_section_paragraphs',
            'official_links_section_paragraphs',
        ] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $payload[$key] = self::filter_no_extra_link_lines($payload[$key]);
            }
        }

        if (isset($payload['faq_items']) && is_array($payload['faq_items'])) {
            $faq_items = [];
            foreach ($payload['faq_items'] as $faq) {
                if (!is_array($faq)) {
                    continue;
                }
                $q = trim((string) ($faq['q'] ?? ''));
                $a = trim((string) ($faq['a'] ?? ''));
                if ($q === '' || $a === '' || self::contains_no_extra_link_forbidden_terms($q . ' ' . $a)) {
                    continue;
                }
                $faq_items[] = $faq;
            }
            $payload['faq_items'] = $faq_items;
        }

        return $payload;
    }

    /** @param array<int,mixed> $lines @return array<int,string> */
    private static function filter_no_extra_link_lines(array $lines): array {
        $out = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || self::contains_no_extra_link_forbidden_terms($line)) {
                continue;
            }
            $out[] = $line;
        }
        return $out;
    }

    private static function contains_no_extra_link_forbidden_terms(string $text): bool {
        return (bool) preg_match('/\b(other listed profiles|grouped profiles|fan\/support pages|fan pages|fansites?|personal sites?|video channels?|social profiles?|link hubs?|non-live profiles|backup profiles|archive links|0 profile links found, including 1 live profile)\b/iu', $text);
    }

    private static function strip_no_extra_link_forbidden_paragraphs(string $html): string {
        $html = preg_replace_callback('/<p\b[^>]*>.*?<\/p>/is', static function (array $m): string {
            $plain = trim((string) wp_strip_all_tags($m[0]));
            return self::contains_no_extra_link_forbidden_terms($plain) ? '' : $m[0];
        }, $html) ?: $html;
        $html = preg_replace('/<h2>Other Official Destinations<\/h2>\s*(?=<h2>|$)/iu', '', $html) ?: $html;
        $html = preg_replace('/<h2>Social Profiles, Link Hubs, and Channels<\/h2>\s*(?=<h2>|$)/iu', '', $html) ?: $html;
        return trim((string) preg_replace('/\n{3,}/', "\n\n", $html));
    }

    public static function is_unsafe_model_seo_phrase(string $phrase): bool {
        if (class_exists(PageTypeKeywordFilter::class)) {
            return PageTypeKeywordFilter::is_unsafe_model_seo_phrase($phrase);
        }
        $normalized = strtolower(wp_strip_all_tags($phrase));
        $normalized = preg_replace('/[\-_\/\.]+/u', ' ', $normalized);
        $normalized = preg_replace('/[^a-z0-9\s]+/u', ' ', (string) $normalized);
        $normalized = trim((string) preg_replace('/\s+/u', ' ', (string) $normalized));
        foreach (['how to', 'join a live session', 'live session', 'live show schedule', 'schedule', 'pricing', 'earnings', 'requirements', 'account setup', 'payment method', 'free credits', 'customer support'] as $needle) {
            if (preg_match('/(^|\s)' . preg_quote($needle, '/') . '(\s|$)/u', $normalized) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @param string[] $final_extras */
    private static function debug_copy_guard(int $post_id, string $model_title, string $phrase, string $reason, string $source_bucket, string $final_heading_keyword, array $final_extras): void {
        if (!class_exists(Settings::class) || !(bool) Settings::get('debug_mode', false)) {
            return;
        }
        Logs::debug('keywords', '[TMW-SEO-COPY-GUARD] Rejected model SEO copy keyword phrase', [
            'post_id' => $post_id,
            'model_title' => $model_title,
            'rejected_keyword_phrase' => $phrase,
            'rejection_reason' => $reason,
            'source_bucket' => $source_bucket,
            'final_selected_heading_keyword' => $final_heading_keyword,
            'final_rank_math_extras' => $final_extras,
        ]);
    }

    private static function heading_with_focus(string $base, string $focus_keyword, string $name): string {
        $focus_keyword = trim($focus_keyword);
        if ($focus_keyword === '' || mb_strtolower($focus_keyword, 'UTF-8') === mb_strtolower($name, 'UTF-8')) {
            return $base . ' for ' . $name;
        }
        return $base . ' for ' . $focus_keyword;
    }

    private static function append_secondary_heading_phrase(string $heading, string $phrase): string {
        $phrase = trim($phrase);
        if ($phrase === '' || self::is_unsafe_model_seo_phrase($phrase)) {
            return $heading;
        }
        if (preg_match('/\b' . preg_quote($phrase, '/') . '\b/iu', $heading)) {
            return $heading;
        }
        if (preg_match('/^Official Links and Profiles\b/iu', $heading) && preg_match('/\blivejasmin\s+profile\b/iu', $phrase)) {
            return $heading;
        }
        return $heading . ' and ' . $phrase;
    }

    /**
     * @param mixed $raw
     * @return array<string,array<int,string>>
     */
    private static function normalize_secondary_heading_slots($raw, string $name = '', int $post_id = 0): array {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $slot => $phrases) {
            if (!is_string($slot)) {
                continue;
            }
            if (is_string($phrases)) {
                $phrases = [$phrases];
            }
            if (!is_array($phrases)) {
                continue;
            }
            $clean = [];
            foreach ($phrases as $phrase) {
                $phrase = trim((string) $phrase);
                if ($phrase === '') {
                    continue;
                }
                if (self::is_unsafe_model_seo_phrase($phrase)) {
                    self::debug_copy_guard($post_id, $name, $phrase, 'instructional_model_seo_phrase', 'content heading', $name, []);
                    continue;
                }
                $clean[] = $phrase;
            }
            if (!empty($clean)) {
                $out[$slot] = array_values(array_unique($clean));
            }
        }

        return $out;
    }

    /**
     * @param string[] $phrases
     * @return string[]
     */
    private static function build_secondary_subheadings(array $phrases, int $offset, string $prefix): array {
        $out = [];
        foreach (array_slice($phrases, $offset) as $phrase) {
            if (self::is_unsafe_model_seo_phrase((string) $phrase)) {
                continue;
            }
            $out[] = trim($prefix . ' ' . $phrase);
        }
        return $out;
    }

    /** @param mixed $paragraphs */
    private static function render_paragraphs($paragraphs, string $name): string {
        $normalized = self::normalize_lines($paragraphs, $name);
        if (empty($normalized)) {
            return '';
        }

        $out = [];
        foreach ($normalized as $line) {
            $out[] = '<p>' . esc_html($line) . '</p>';
        }

        return implode("\n", $out);
    }

    /** @param mixed $paragraphs @param string[] $secondary_subheadings */
    private static function render_section(string $heading, $paragraphs, string $name, string $html_block = '', array $secondary_subheadings = []): string {
        $parts = [];
        foreach ($secondary_subheadings as $subheading) {
            $parts[] = '<h3>' . esc_html(self::clean_text($subheading, $name, true)) . '</h3>';
        }
        $paras = self::render_paragraphs($paragraphs, $name);
        if ($paras !== '') {
            $parts[] = $paras;
        }

        $html_block = self::sanitize_html_block($html_block);
        if ($html_block !== '') {
            $parts[] = $html_block;
        }

        if (empty($parts)) {
            return '';
        }

        return '<h2>' . esc_html(self::clean_text($heading, $name, true)) . '</h2>' . "\n" . implode("\n", $parts);
    }

    /** @param mixed $paragraphs @param mixed $faq_items @param string[] $secondary_subheadings */
    private static function render_questions(string $heading, $paragraphs, $faq_items, string $name, array $secondary_subheadings = []): string {
        $parts = [];
        foreach ($secondary_subheadings as $subheading) {
            $parts[] = '<h3>' . esc_html(self::clean_text($subheading, $name, true)) . '</h3>';
        }

        $paras = self::render_paragraphs($paragraphs, $name);
        if ($paras !== '') {
            $parts[] = $paras;
        }

        if (is_array($faq_items)) {
            foreach ($faq_items as $item) {
                if (!is_array($item)) continue;
                $q = self::clean_text((string)($item['q'] ?? ''), $name, true);
                $a = self::clean_text((string)($item['a'] ?? ''), $name, false);
                if ($q === '' || $a === '') continue;
                $parts[] = '<h3>' . esc_html($q) . '</h3>';
                $parts[] = '<p>' . esc_html($a) . '</p>';
            }
        }

        if (empty($parts)) {
            return '';
        }

        return '<h2>' . esc_html(self::clean_text($heading, $name, true)) . '</h2>' . "\n" . implode("\n", $parts);
    }

    /** @param mixed $value @return string[] */
    private static function normalize_lines($value, string $name): array {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $lines = [];
        foreach ($value as $line) {
            $clean = self::clean_text((string)$line, $name, false);
            if ($clean !== '') {
                $lines[] = $clean;
            }
        }

        return array_values(array_unique($lines));
    }

    private static function sanitize_html_block(string $html): string {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $html = preg_replace('/<h1\b[^>]*>.*?<\/h1>/isu', '', $html) ?: $html;
        $html = preg_replace('/<h2\b[^>]*>.*?<\/h2>/isu', '', $html) ?: $html;

        return trim((string) wp_kses_post($html));
    }

    private static function clean_text(string $text, string $name, bool $heading): string {
        $text = wp_strip_all_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\bthis model\b/iu', $name, $text) ?: $text;
        $text = preg_replace('/\bthe profile\b/iu', 'this profile', $text) ?: $text;
        $text = preg_replace('/&lt;\/?h[1-6]&gt;/iu', '', $text) ?: $text;
        $text = preg_replace('/\s+/', ' ', trim($text)) ?: trim($text);

        if ($heading) {
            $text = trim($text, " \t\n\r\0\x0B:;-—");
            if ($text === '') {
                return '';
            }
        }

        return $text;
    }

    /** @param mixed $paragraphs @return string[] */
    private static function with_direct_intro_answer($paragraphs, string $name, array $payload): array {
        $lines  = self::normalize_lines($paragraphs, $name);
        if (!empty($lines)) {
            return $lines;
        }
        $active = is_array($payload['active_platforms'] ?? null) ? $payload['active_platforms'] : [];
        $active = array_values(array_filter(array_map('strval', $active), 'strlen'));

        if (!empty($active)) {
            $evidence = self::normalize_link_evidence_summary($payload['link_evidence_summary'] ?? []);
            $answer = $active[0] . ' is the confirmed live-room profile in this check. Start there for live access.';
            if (!array_key_exists('link_evidence_summary', $payload) || !empty($evidence['has_extra_links'])) {
                $answer .= ' Use additional verified destinations only if needed.';
            }
        } else {
            $answer = 'No live-room profile is confirmed active right now.';
            if (!array_key_exists('link_evidence_summary', $payload) || !empty(($payload['link_evidence_summary'] ?? [])['has_extra_links'])) {
                $answer .= ' Use verified destinations for handle checks and updates.';
            }
        }

        return [$answer];
    }

    /** @param mixed $paragraphs @return string[] */
    private static function with_direct_compare_answer($paragraphs, array $payload, string $name): array {
        $lines = self::normalize_lines($paragraphs, $name);
        if (!empty($lines)) {
            return $lines;
        }
        $active = is_array($payload['active_platforms'] ?? null) ? $payload['active_platforms'] : [];
        $active = array_values(array_filter(array_map('strval', $active), 'strlen'));
        if (count($active) >= 2) {
            $answer = 'Start with ' . $active[0] . ' if it is your usual platform, then compare ' . $active[1] . ' for chat controls, mobile playback, and moderation flow.';
        } elseif (count($active) === 1) {
            $answer = 'Before joining, confirm the handle and check recent room activity.';
        } else {
            $answer = 'Compare confirmed platforms by room stability, chat readability, trust signals, and mobile usability before choosing a default room.';
        }
        return [$answer];
    }

    /** @param array<int,string> $items */
    private static function format_list(array $items): string {
        $items = array_values(array_filter(array_map('trim', $items), 'strlen'));
        if (empty($items)) return '';
        if (count($items) === 1) return $items[0];
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }

    private static function final_cleanup(string $html, string $name, array $link_evidence = []): string {
        $html = preg_replace('/<h2>\s*Table of Contents\s*<\/h2>\s*<ul>.*?<\/ul>/isu', '', $html) ?: $html;
        $html = preg_replace('/<h1\b[^>]*>.*?<\/h1>/isu', '', $html) ?: $html;
        $html = preg_replace('/\bthis model\b/iu', $name, $html) ?: $html;
        $html = preg_replace('/\bthe profile\b/iu', 'this profile', $html) ?: $html;
        $html = preg_replace('/&lt;\/?h[1-6]&gt;/iu', '', $html) ?: $html;
        $html = preg_replace('/<h2>\s*Related (?:search(?:es)?|queries|keywords)\s*<\/h2>\s*(?:<p>.*?<\/p>|<ul>.*?<\/ul>)/isu', '', $html) ?: $html;
        $html = preg_replace('/<h2>\s*(?:Explore More|Models|Categories)\s*<\/h2>\s*(?:<p>.*?<\/p>|<ul>.*?<\/ul>|<table>.*?<\/table>)*/isu', '', $html) ?: $html;
        $html = preg_replace('/<p>\s*(?:Related searches people use before picking a room:)\s*<\/p>\s*<ul>.*?<\/ul>/isu', '', $html) ?: $html;
        $html = preg_replace('/<h2>\s*Quick recap.*?<\/h2>\s*<p>.*?<\/p>/isu', '', $html) ?: $html;
        $html = preg_replace('/<p>\s*(People usually open a page like this.*?|A page like this.*?|Finding the real room should not take.*?|This page keeps.*?|The room tends to work because.*?|The atmosphere is settled.*?|The practical side.*?|The useful part of .*?|The main advantage here is .*?|What changes most .*?|One practical detail is .*?|What helps most is .*?|The biggest shift .*?|The room feel.*?|The tone.*?|The rhythm.*?|The energy.*?)\s*<\/p>/iu', '', $html) ?: $html;
        $html = preg_replace('/<p>\s*(?:Viewers looking for|A query like|How to join .*? usually|LiveJasmin live show schedule matters).*?<\/p>/iu', '', $html) ?: $html;
        $html = preg_replace('/(<h2>\s*Verified Links\s*<\/h2>.*?)(?:<p>\s*(?:In short|Overall|To wrap up|That said|Finally).*?<\/p>)+$/isu', '$1', $html) ?: $html;
        $html = preg_replace('/<p>\s*For\s+[^<.]+?\s+access,\s*confirm handle consistency and recent room activity before joining\.\s*<\/p>/iu', '', $html) ?: $html;
        $html = preg_replace('/For\s+([^<.]+?)\s+access,\s*confirm handle consistency and recent room activity before joining\./iu', 'For $1 searches, start with the confirmed live room and use the verified links below for profile checks.', $html) ?: $html;
        $html = str_replace('Official Links and Profiles and LiveJasmin profile', 'Official Links and Profiles', $html);
        $html = str_replace(['use additional the links', 'Use additional the links', 'Use the the links below below', 'use the the links below below'], ['use the additional links', 'Use the additional links', 'Use the links below', 'use the links below'], $html);
        $html = preg_replace('/\b(official (?:live )?profile links)(\s+official (?:live )?profile links)+\b/iu', '$1', $html) ?: $html;
        $html = preg_replace('/\b([A-Za-z]+(?:\s+[A-Za-z]+){0,3})(\s+\1){1,}\b/u', '$1', $html) ?: $html;
        $html = self::remove_duplicate_heading_text($html, 'Before You Click');
        $html = preg_replace('/\n{3,}/', "\n\n", $html) ?: $html;
        return trim($html);
    }

    private static function remove_duplicate_heading_text(string $content, string $heading): string {
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

    private static function looks_like_nav_chrome(string $html): bool {
        $text = strtolower(trim((string) wp_strip_all_tags($html)));
        if ($text === '') {
            return true;
        }

        return (bool) preg_match('/^(explore more\s*)?(models|categories)(\s*(models|categories))*$/', $text);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function should_render_editorial_section(string $section, array $payload, string $name): bool {
        $gate = is_array($payload['model_data_gate'] ?? null) ? $payload['model_data_gate'] : [];
        if (isset($gate['is_sufficient']) && !$gate['is_sufficient']) {
            return false;
        }

        $paragraphs = [];
        if ($section === 'about') {
            $paragraphs = self::normalize_lines($payload['about_section_paragraphs'] ?? [], $name);
        } elseif ($section === 'fans_like') {
            $paragraphs = self::normalize_lines($payload['fans_like_section_paragraphs'] ?? [], $name);
        }
        if (empty($paragraphs)) {
            return false;
        }

        $seed_summary = trim((string)($payload['editor_seed_summary'] ?? ''));
        $seed_facts = is_array($payload['editor_seed_confirmed_facts'] ?? null) ? $payload['editor_seed_confirmed_facts'] : [];
        $seed_tags = is_array($payload['editor_seed_known_for_tags'] ?? null) ? $payload['editor_seed_known_for_tags'] : [];
        $platform_notes = is_array($payload['editor_seed_platform_notes'] ?? null) ? $payload['editor_seed_platform_notes'] : [];
        $signals = is_array($gate['signals'] ?? null) ? $gate['signals'] : [];
        $platform_links = (int)($signals['platform_links'] ?? 0);
        $active_platforms_signal = (int)($signals['active_platforms'] ?? 0);
        $editor_seed_fact_signal = (int)($signals['editor_seed_facts'] ?? 0);

        $evidence_points = 0;
        if ($platform_links > 0) { $evidence_points++; }
        if ($active_platforms_signal > 0) { $evidence_points++; }
        if ($editor_seed_fact_signal > 0 || !empty($seed_facts)) { $evidence_points++; }
        if ($seed_summary !== '') { $evidence_points++; }
        if (!empty($platform_notes)) { $evidence_points++; }

        if ($section === 'fans_like') {
            if (empty($seed_tags)) {
                return false;
            }
            return $evidence_points >= 2;
        }

        if ($section === 'about') {
            if ($seed_summary === '' && empty($seed_facts)) {
                return false;
            }
            return $evidence_points >= 2;
        }

        $text = mb_strtolower(implode(' ', $paragraphs), 'UTF-8');
        $active_platforms = $payload['active_platforms'] ?? [];
        $active_platforms = is_array($active_platforms) ? $active_platforms : [];
        $has_platform_ref = false;
        foreach ($active_platforms as $platform) {
            $p = trim(mb_strtolower((string)$platform, 'UTF-8'));
            if ($p !== '' && str_contains($text, $p)) {
                $has_platform_ref = true;
                break;
            }
        }

        $has_name_ref = str_contains($text, mb_strtolower($name, 'UTF-8'));
        if ($has_name_ref || $has_platform_ref) {
            return true;
        }

        return $seed_summary !== '';
    }

    /** @param array<int,string> $blocks */
    private static function join_html_blocks(array $blocks): string {
        $out = [];
        foreach ($blocks as $block) {
            $clean = self::sanitize_html_block((string)$block);
            if ($clean !== '') {
                $out[] = $clean;
            }
        }

        return implode("\n", $out);
    }
}
