<?php
namespace TMWSEO\Engine\Services;

if (!defined('ABSPATH')) { exit; }

class TitleFixer {

    /**
     * Lightweight, deterministic title cleaner used BEFORE AI generation.
     * Keeps brand-safe adult context, but removes garbage tags, file-like text, etc.
     */
    public static function fix(string $title): string {
        $t = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = wp_strip_all_tags($t);

        // Convert slug-ish titles to normal text.
        if (preg_match('/^[a-z0-9\-\_\s]+$/i', $t) && substr_count($t, '-') >= 3) {
            $t = str_replace(['-', '_'], ' ', $t);
        }

        // Remove common junk tokens.
        $patterns = [
            '/\b(1080p|720p|4k|uhd|hd)\b/i',
            '/\b(full\s*hd|ultra\s*hd)\b/i',
            '/\b(mp4|mkv|avi|wmv|mov)\b/i',
            '/\b(download|watch\s*now|stream)\b/i',
            '/\b(official)\b/i',
            '/\b(free\s*download)\b/i',
            '/\b(top\-?models\.?webcam)\b/i',
            '/\b(top\s*models\s*webcam)\b/i',
            '/\b(adult\s*video)\b/i',
            '/\s*\(.*?\)\s*/',  // parentheses (often junk)
            '/\s*\[.*?\]\s*/',  // brackets
        ];
        foreach ($patterns as $p) {
            $t = preg_replace($p, ' ', $t);
        }

        // Normalize separators.
        $t = preg_replace('/\s*[\|\–\—\-]+\s*/u', ' — ', $t);
        $t = preg_replace('/\s{2,}/', ' ', $t);
        $t = trim($t, " \t\n\r\0\x0B—-|");
        if ($t === '') $t = $title;

        // Title case-ish if it's all caps.
        if (mb_strtoupper($t, 'UTF-8') === $t) {
            $t = mb_convert_case($t, MB_CASE_TITLE, 'UTF-8');
        }

        return $t;
    }

    public static function shorten(string $title, int $max = 70): string {
        $t = trim($title);
        if (mb_strlen($t, 'UTF-8') <= $max) return $t;
        return trim(mb_substr($t, 0, $max - 1, 'UTF-8')) . '…';
    }
}
