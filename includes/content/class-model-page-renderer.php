<?php
namespace TMWSEO\Engine\Content;

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

        $sections = [];

        $intro_paragraphs = $payload['intro_paragraphs'] ?? [];
        $seed_summary = trim((string)($payload['editor_seed_summary'] ?? ''));
        if ($seed_summary !== '') {
            array_unshift($intro_paragraphs, $seed_summary);
        }
        $intro = self::render_paragraphs($intro_paragraphs, $name);
        if ($intro !== '') {
            $sections[] = $intro;
        }

        $watch = self::render_section('Watch ' . $name . ' Live', $payload['watch_section_paragraphs'] ?? [], $name, $payload['watch_section_html'] ?? '');
        if ($watch !== '') {
            $sections[] = $watch;
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

        $features_heading = self::heading_with_focus('Features and Platform Experience', $focus_keyword, $name);
        $features = self::render_section($features_heading, $payload['features_section_paragraphs'] ?? [], $name, $payload['features_section_html'] ?? '');
        if ($features !== '') {
            $sections[] = $features;
        }

        $comparison_paragraphs = is_array($payload['comparison_section_paragraphs'] ?? null) ? $payload['comparison_section_paragraphs'] : [];
        $seed_notes = is_array($payload['editor_seed_platform_notes'] ?? null) ? $payload['editor_seed_platform_notes'] : [];
        if (!empty($seed_notes)) {
            $comparison_paragraphs = array_merge(array_slice($seed_notes, 0, 3), $comparison_paragraphs);
        }
        $compare = self::render_section('Compare Platforms', $comparison_paragraphs, $name, $payload['comparison_section_html'] ?? '');
        if ($compare !== '') {
            $sections[] = $compare;
        }

        $questions = self::render_questions('Questions About ' . $name, $payload['questions_section_paragraphs'] ?? [], $payload['faq_items'] ?? [], $name);
        if ($questions !== '') {
            $sections[] = $questions;
        }

        $links = self::render_section('Official Links and Elsewhere Online', [], $name, self::join_html_blocks([
            $payload['external_info_html'] ?? '',
            $payload['explore_more_html'] ?? '',
        ]));
        if ($links !== '' && !self::looks_like_nav_chrome($links)) {
            $sections[] = $links;
        }

        $html = implode("\n\n", $sections);
        return self::final_cleanup($html, $name);
    }

    private static function heading_with_focus(string $base, string $focus_keyword, string $name): string {
        // Do not append "for {name}" to the heading — it inflates exact-name density
        // and makes the Features section heading look machine-generated.
        // The section heading "Features and Platform Experience" is already descriptive.
        return $base;
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

    /** @param mixed $paragraphs */
    private static function render_section(string $heading, $paragraphs, string $name, string $html_block = ''): string {
        $parts = [];
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

    /** @param mixed $paragraphs @param mixed $faq_items */
    private static function render_questions(string $heading, $paragraphs, $faq_items, string $name): string {
        $parts = [];

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
        $text = preg_replace('/\bthe profile\b/iu', $name . ' profile', $text) ?: $text;
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

    private static function final_cleanup(string $html, string $name): string {
        $html = preg_replace('/<h2>\s*Table of Contents\s*<\/h2>\s*<ul>.*?<\/ul>/isu', '', $html) ?: $html;
        $html = preg_replace('/<h1\b[^>]*>.*?<\/h1>/isu', '', $html) ?: $html;
        $html = preg_replace('/\bthis model\b/iu', $name, $html) ?: $html;
        $html = preg_replace('/\bthe profile\b/iu', $name . ' profile', $html) ?: $html;
        $html = preg_replace('/&lt;\/?h[1-6]&gt;/iu', '', $html) ?: $html;
        $html = preg_replace('/<h2>\s*Related (?:search(?:es)?|queries|keywords)\s*<\/h2>\s*(?:<p>.*?<\/p>|<ul>.*?<\/ul>)/isu', '', $html) ?: $html;
        $html = preg_replace('/<h2>\s*(?:Explore More|Models|Categories)\s*<\/h2>\s*(?:<p>.*?<\/p>|<ul>.*?<\/ul>|<table>.*?<\/table>)*/isu', '', $html) ?: $html;
        $html = preg_replace('/<p>\s*(?:Related searches people use before picking a room:)\s*<\/p>\s*<ul>.*?<\/ul>/isu', '', $html) ?: $html;
        $html = preg_replace('/<h2>\s*Quick recap.*?<\/h2>\s*<p>.*?<\/p>/isu', '', $html) ?: $html;
        $html = preg_replace('/<p>\s*(People usually open a page like this.*?|A page like this.*?|Finding the real room should not take.*?|This page keeps.*?|The room tends to work because.*?|The atmosphere is settled.*?|The practical side.*?|The useful part of .*?|The main advantage here is .*?|What changes most .*?|One practical detail is .*?|What helps most is .*?|The biggest shift .*?)\s*<\/p>/iu', '', $html) ?: $html;
        $html = preg_replace('/<p>\s*(?:Viewers looking for|A query like|How to join .*? usually|LiveJasmin live show schedule matters).*?<\/p>/iu', '', $html) ?: $html;
        $html = preg_replace('/(<h2>\s*Verified Links\s*<\/h2>.*?)(?:<p>\s*(?:In short|Overall|To wrap up|That said|Finally).*?<\/p>)+$/isu', '$1', $html) ?: $html;
        $html = preg_replace('/\b(official (?:live )?profile links)(\s+official (?:live )?profile links)+\b/iu', '$1', $html) ?: $html;
        $html = preg_replace('/\b([A-Za-z]+(?:\s+[A-Za-z]+){0,3})(\s+\1){1,}\b/u', '$1', $html) ?: $html;
        $html = preg_replace('/\n{3,}/', "\n\n", $html) ?: $html;
        return trim($html);
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

        $seed_summary = trim((string)($payload['editor_seed_summary'] ?? ''));
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
