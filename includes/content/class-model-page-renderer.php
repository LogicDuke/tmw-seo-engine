<?php
namespace TMWSEO\Engine\Content;

if (!defined('ABSPATH')) { exit; }

class ModelPageRenderer {
    public const NEUTRAL_FALLBACK = 'official live profile';

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

        $intro = self::render_paragraphs($payload['intro_paragraphs'] ?? [], $name);
        if ($intro !== '') {
            $sections[] = $intro;
        }

        $watch = self::render_section('Watch ' . $name . ' Live', $payload['watch_section_paragraphs'] ?? [], $name, $payload['watch_section_html'] ?? '');
        if ($watch !== '') {
            $sections[] = $watch;
        }

        $about = self::render_section('About ' . $name, $payload['about_section_paragraphs'] ?? [], $name, $payload['about_section_html'] ?? '');
        if ($about !== '') {
            $sections[] = $about;
        }

        $fans_like = self::render_section('What Fans Like About ' . $name, $payload['fans_like_section_paragraphs'] ?? [], $name, $payload['fans_like_section_html'] ?? '');
        if ($fans_like !== '') {
            $sections[] = $fans_like;
        }

        $features_heading = self::heading_with_focus('Features and Platform Experience', $focus_keyword, $name);
        $features = self::render_section($features_heading, $payload['features_section_paragraphs'] ?? [], $name, $payload['features_section_html'] ?? '');
        if ($features !== '') {
            $sections[] = $features;
        }

        $compare = self::render_section('Compare Platforms', $payload['comparison_section_paragraphs'] ?? [], $name, $payload['comparison_section_html'] ?? '');
        if ($compare !== '') {
            $sections[] = $compare;
        }

        $questions = self::render_questions('Questions About ' . $name, $payload['questions_section_paragraphs'] ?? [], $payload['faq_items'] ?? [], $name);
        if ($questions !== '') {
            $sections[] = $questions;
        }

        $related = self::render_section('Related Models', $payload['related_models_paragraphs'] ?? [], $name, $payload['related_models_html'] ?? '');
        if ($related !== '') {
            $sections[] = $related;
        }

        $explore = self::render_section('Explore More', $payload['explore_more_paragraphs'] ?? [], $name, self::join_html_blocks([
            $payload['explore_more_html'] ?? '',
            $payload['external_info_html'] ?? '',
        ]));
        if ($explore !== '') {
            $sections[] = $explore;
        }

        $html = implode("\n\n", $sections);
        return self::final_cleanup($html, $name);
    }

    private static function heading_with_focus(string $base, string $focus_keyword, string $name): string {
        $needle = trim($focus_keyword);
        if ($needle === '') {
            return $base;
        }
        if (!preg_match('/\b' . preg_quote($needle, '/') . '\b/iu', $base)) {
            return $base . ' for ' . $name;
        }
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
        $text = self::strip_unresolved_template_tokens($text);
        $text = preg_replace('/\bthis model\b/iu', $name, $text) ?: $text;
        $text = preg_replace('/\bthe profile\b/iu', $name . ' profile', $text) ?: $text;
        $text = preg_replace('/\blive webcam\b/iu', self::NEUTRAL_FALLBACK, $text) ?: $text;
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
        $html = preg_replace('/<h1\b[^>]*>.*?<\/h1>/isu', '', $html) ?: $html;
        $html = self::strip_unresolved_template_tokens($html);
        $html = preg_replace('/\bthis model\b/iu', $name, $html) ?: $html;
        $html = preg_replace('/\bthe profile\b/iu', $name . ' profile', $html) ?: $html;
        $html = preg_replace('/\blive webcam\b/iu', self::NEUTRAL_FALLBACK, $html) ?: $html;
        $html = preg_replace('/&lt;\/?h[1-6]&gt;/iu', '', $html) ?: $html;
        $html = preg_replace('/\b(official live profile)(\s+official live profile)+\b/iu', '$1', $html) ?: $html;
        $html = preg_replace('/\b([A-Za-z]+(?:\s+[A-Za-z]+){0,3})(\s+\1){1,}\b/u', '$1', $html) ?: $html;

        return trim($html);
    }

    private static function strip_unresolved_template_tokens(string $text): string {
        $text = preg_replace('/\{[a-z0-9_]+\}/iu', '', $text) ?: $text;
        $text = preg_replace('/\s+([,.!?;:])/u', '$1', $text) ?: $text;
        return preg_replace('/\s+/', ' ', trim($text)) ?: trim($text);
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
