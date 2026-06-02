<?php
/**
 * Smoke checks for Template model body length and keyword-density guards.
 */

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

    if (!function_exists('esc_html')) {
        function esc_html($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    }
    if (!function_exists('esc_attr')) {
        function esc_attr($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    }

    require_once dirname(__DIR__) . '/includes/model/class-model-optimizer.php';

    use TMWSEO\Engine\Model\ModelOptimizer;

    function tmw_template_assert(bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function tmw_template_word_count(string $html): int {
        $words = preg_split('/\s+/u', trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
        return is_array($words) ? count(array_filter($words, static fn($word) => trim((string) $word) !== '')) : 0;
    }

    function tmw_template_focus_density(string $html, string $focus): float {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $word_count = max(1, tmw_template_word_count($html));
        preg_match_all('/\b' . preg_quote($focus, '/') . '\b/iu', $text, $matches);
        return (count($matches[0] ?? []) * max(1, tmw_template_word_count($focus)) / $word_count) * 100;
    }

    $generate = new ReflectionMethod(ModelOptimizer::class, 'generate_with_templates');
    $generate->setAccessible(true);

    $suggestions = $generate->invoke(null, 'Abby Murray', [
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

    $html = (string) ($suggestions['intro'] ?? '');

    tmw_template_assert(tmw_template_word_count($html) >= 600, 'Template body should reach at least 600 words with tags/platform data.');
    tmw_template_assert(tmw_template_focus_density($html, 'Abby Murray') < 2.0, 'Template body focus keyword density should stay below 2%.');
    tmw_template_assert(strpos($html, '<h2>Official Profile Access</h2>') !== false, 'Template body should include Official Profile Access context.');
    tmw_template_assert(strpos($html, '<h2>Where to Watch Live</h2>') !== false, 'Template body should include Where to Watch Live context.');
    tmw_template_assert(strpos($html, '<a href="/models/">Browse All Models</a>') !== false, 'Template body should preserve internal model link.');
    tmw_template_assert(strpos($html, 'Private chat options should be read as session-dependent') !== false, 'Private chat context should read editorially, not as a raw list.');

    foreach ([
        'The verified notes ' . 'point to',
        'personable cam ' . 'delivery',
        'do you ' . 'accept',
        'Use these notes as ' . 'profile context',
    ] as $forbidden) {
        tmw_template_assert(stripos($html, $forbidden) === false, 'Forbidden phrase introduced: ' . $forbidden);
    }

    echo "Template model body length/density smoke passed.\n";
}
