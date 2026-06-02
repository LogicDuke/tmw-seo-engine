<?php
/**
 * Smoke checks for Template model body length, density, links, sections, and safe item visibility.
 */

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

    function tmw_template_assert(bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    if (!function_exists('esc_html')) { function esc_html($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); } }
    if (!function_exists('esc_attr')) { function esc_attr($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); } }
    if (!function_exists('wp_kses_post')) { function wp_kses_post($html): string { return (string) $html; } }
    if (!function_exists('sanitize_text_field')) { function sanitize_text_field($text): string { return trim(strip_tags((string) $text)); } }
    if (!function_exists('sanitize_key')) { function sanitize_key($key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)) ?? ''; } }

    require_once dirname(__DIR__) . '/includes/model/class-model-optimizer.php';

    $method = new ReflectionMethod(\TMWSEO\Engine\Model\ModelOptimizer::class, 'generate_with_templates');
    $method->setAccessible(true);

    $suggestions = $method->invoke(null, 'Abby Murray', [
        'Striptease',
        'Dancing',
        'Close up',
        'Roleplay',
        'Oil',
        'Twerk',
    ], [
        'livejasmin',
        'stripchat',
    ]);

    $body = (string) ($suggestions['intro'] ?? '');
    $plain = trim(strip_tags($body));
    $words = preg_split('/\s+/', $plain);
    $word_count = is_array($words) ? count(array_filter($words)) : 0;

    $focus_hits = preg_match_all('/(?<![\w-])Abby Murray(?![\w-])/i', $plain, $matches);
    $density = $word_count > 0 ? (($focus_hits ?: 0) / $word_count) * 100 : 0.0;

    tmw_template_assert($word_count >= 600, 'Template body should be at least 600 words; got ' . $word_count . '.');
    tmw_template_assert($density < 2.0, 'Focus keyword density should be below 2%; got ' . number_format($density, 2) . '%.');
    tmw_template_assert(str_contains($body, '<a href="/models/">Browse All Models</a>'), 'Internal model link should be preserved.');
    tmw_template_assert(str_contains($body, '<h2>Official Profile Access</h2>'), 'Official Profile Access section should exist.');
    tmw_template_assert(str_contains($body, '<h2>Where to Watch Live</h2>'), 'Where to Watch Live section should exist.');

    $safe_items = ['Striptease', 'Dancing', 'Close up', 'Roleplay', 'Oil', 'Twerk'];
    $visible = 0;
    foreach ($safe_items as $item) {
        if (preg_match('/(?<![A-Za-z])' . preg_quote($item, '/') . '(?![A-Za-z])/i', $plain)) {
            $visible++;
        }
    }
    tmw_template_assert($visible >= 4, 'At least 4 Abby-style safe items should remain visible; got ' . $visible . '.');

    tmw_template_assert(strpos($body, 'Private chat options should be read as session-dependent') === false, 'Template body should not rely on the old generic-only private-chat fallback.');

    foreach ([
        'The verified notes point to',
        'personable cam delivery',
        'do you accept',
        'Use these notes as profile context',
    ] as $forbidden) {
        tmw_template_assert(stripos($body, $forbidden) === false, 'Forbidden phrase should be absent: ' . $forbidden);
    }

    echo "Template body length/density smoke passed. Words={$word_count}; Density=" . number_format($density, 2) . "%; SafeItems={$visible}\n";
}
