<?php
namespace TMWSEO\Engine\Content;

if (!defined('ABSPATH')) { exit; }

class ModelPageRenderer {
    public const PLATFORM_FALLBACK_LABEL = 'official live profile';

    /**
     * @param array<string,mixed> $sections
     * @return array{content:string,seo_title:string,meta_description:string}
     */
    public static function render(\WP_Post $post, array $sections): array {
        $name = trim((string)($sections['name'] ?? $post->post_title));
        if ($name === '') {
            $name = 'Live Cam Model';
        }

        $primary_platform_label = self::clean_visible_text((string)($sections['primary_platform_label'] ?? ''));
        if ($primary_platform_label === '') {
            $primary_platform_label = self::PLATFORM_FALLBACK_LABEL;
        }

        $tags_text = self::clean_visible_text((string)($sections['tags_text'] ?? 'live shows'));
        $active_platforms = is_array($sections['active_platforms'] ?? null) ? array_values($sections['active_platforms']) : [];
        $extra = is_array($sections['extra_keywords'] ?? null) ? array_values($sections['extra_keywords']) : [];
        $longtail = is_array($sections['longtail_keywords'] ?? null) ? array_values($sections['longtail_keywords']) : [];

        $assembled = [
            self::build_watch_intro($name, $primary_platform_label, (string)($sections['intro'] ?? '')),
            self::build_primary_cta((string)($sections['primary_cta_html'] ?? ''), $name),
            self::build_about($name, (string)($sections['bio'] ?? '')),
            (string)($sections['focus_blocks_html'] ?? ''),
            self::build_features($name, $primary_platform_label, (string)($sections['features_html'] ?? '')),
            (string)($sections['keyword_coverage_html'] ?? ''),
            (string)($sections['platform_comparison_html'] ?? ''),
            (string)($sections['longtail_html'] ?? ''),
            (string)($sections['faqs_html'] ?? ''),
            self::build_where_to_watch($name, (string)($sections['watch_cta_section_html'] ?? '')),
            (string)($sections['related_models_html'] ?? ''),
            self::build_explore_more((string)($sections['internal_links_html'] ?? '')),
            self::build_learn_more((string)($sections['external_link_html'] ?? '')),
        ];

        $content = implode("\n\n", array_values(array_filter($assembled, static fn($part) => trim((string)$part) !== '')));
        $content = self::split_long_paragraphs($content);
        $content = self::balance_focus_density($content, $name, $active_platforms, $extra);
        $content = self::pad_model_content($content, $name, $active_platforms, $extra, $longtail, $tags_text);

        if (self::similarity_score($content, (int)$post->ID) > 70.0) {
            $content .= "\n\n" . '<h2>Why ' . esc_html($name) . ' stands out</h2><p>This profile highlights ' . esc_html($name) . ' with current platform availability, related search intent, and a curated mix of ' . esc_html($tags_text) . ' cues tailored to this page.</p>';
            $content = self::split_long_paragraphs($content);
        }

        $content = self::pad_model_content($content, $name, $active_platforms, $extra, $longtail, $tags_text);
        $content = self::cleanup_model_content($content, $name, $primary_platform_label);

        $seo_title = $name . ' — Live Cam Profile';
        if ($primary_platform_label !== '' && $primary_platform_label !== self::PLATFORM_FALLBACK_LABEL) {
            $seo_title = $name . ' on ' . $primary_platform_label . ' — Live Cam Profile';
        }

        $meta_description = 'Join ' . $name . "'s live chat";
        if ($primary_platform_label !== '' && $primary_platform_label !== self::PLATFORM_FALLBACK_LABEL) {
            $meta_description .= ' on ' . $primary_platform_label;
        }
        $meta_description .= '. Find trusted links, top features, privacy tips, FAQs, and related searches to get started.';

        return [
            'content' => wp_kses_post($content),
            'seo_title' => $seo_title,
            'meta_description' => $meta_description,
        ];
    }

    private static function build_watch_intro(string $name, string $platform, string $intro): string {
        $intro = self::clean_visible_text($intro);
        $out = '<h2>Watch ' . esc_html($name) . ' Live</h2>';
        if ($intro !== '') {
            $out .= '<p>' . esc_html($intro) . '</p>';
        }
        $out .= '<p>Fans searching for <strong>' . esc_html($name) . ' live shows</strong> usually want a fast, safe way to join a real-time room. This guide highlights official profile links, what to expect, and the best ways to enjoy a quality live chat experience on ' . esc_html($platform) . '.</p>';
        return $out;
    }

    private static function build_primary_cta(string $html, string $name): string {
        $html = trim($html);
        return $html !== '' ? '<h2>Watch ' . esc_html($name) . ' Live</h2>' . $html : '';
    }

    private static function build_about(string $name, string $bio): string {
        $bio = self::clean_visible_text($bio);
        return '<h2>About ' . esc_html($name) . '</h2><p>' . esc_html($bio) . '</p>';
    }

    private static function build_features(string $name, string $platform, string $features_html): string {
        $features_html = trim($features_html);
        return $features_html !== ''
            ? '<h2>Features and platform experience</h2>' . $features_html
            : '<h2>Features and platform experience</h2><p>This page highlights how to compare platforms, follow official profile links, and choose the right place to watch ' . esc_html($name) . ' on ' . esc_html($platform) . '.</p>';
    }

    private static function build_where_to_watch(string $name, string $html): string {
        $html = trim($html);
        return $html !== '' ? '<h2>Where to watch ' . esc_html($name) . '</h2>' . $html : '';
    }

    private static function build_explore_more(string $html): string {
        $html = trim($html);
        return $html !== '' ? '<h2>Explore more</h2>' . $html : '';
    }

    private static function build_learn_more(string $html): string {
        $html = trim($html);
        return $html !== '' ? '<h2>Learn more</h2>' . $html : '';
    }

    public static function clean_visible_text(string $text): string {
        $text = trim(wp_strip_all_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/\bthis model\b/iu', 'this profile', $text) ?: $text;
        $text = preg_replace('/\bthe profile\b/iu', 'this profile', $text) ?: $text;
        $text = preg_replace('/\blive webcam\b/iu', self::PLATFORM_FALLBACK_LABEL, $text) ?: $text;
        $text = preg_replace('/<\/?h[1-6][^>]*>/iu', ' ', $text) ?: $text;
        $text = preg_replace('/\s{2,}/u', ' ', $text) ?: $text;
        return trim((string)$text);
    }

    public static function readable_heading_for_phrase(string $phrase, string $name, string $fallback): string {
        $phrase = self::clean_visible_text($phrase);
        if ($phrase === '') {
            return $fallback;
        }
        $lower = mb_strtolower($phrase, 'UTF-8');
        $bad_starts = ['why fans who like', 'watch ', 'where to watch', 'how to chat', 'is ', 'the profile', 'this profile'];
        foreach ($bad_starts as $bad) {
            if (str_starts_with($lower, $bad)) {
                return $fallback;
            }
        }
        if (mb_strlen($phrase, 'UTF-8') < 8 || mb_strlen($phrase, 'UTF-8') > 70) {
            return $fallback;
        }
        if (preg_match('/\b(chat tips|live chat|live stream|official live profile)\b/iu', $phrase)) {
            return $fallback;
        }
        return ucfirst($phrase);
    }

    public static function cleanup_model_content(string $content, string $name, string $platform): string {
        $replacements = [
            '/\bthis model\b/iu' => 'this profile',
            '/\bthe profile\b/iu' => $name,
            '/\blive webcam\b/iu' => self::PLATFORM_FALLBACK_LABEL,
            '/official live profileofficial live profile/iu' => self::PLATFORM_FALLBACK_LABEL,
            '/<h([2-6])>\s*(Why fans who like|Watch\s+.*\s+with|where to watch|how to chat|is\s+the profile|the profile)\b.*?<\/h\1>/iu' => '<h$1>' . esc_html($name) . ' live overview</h$1>',
        ];
        foreach ($replacements as $pattern => $replacement) {
            $content = preg_replace($pattern, $replacement, $content) ?: $content;
        }
        $content = preg_replace('/\s{2,}/u', ' ', $content) ?: $content;
        $content = preg_replace('/(<\/h[2-6]>)\s*<p>\s*<\/p>/iu', '$1', $content) ?: $content;
        return trim((string)$content);
    }

    private static function pad_model_content(string $content, string $name, array $active_platforms, array $extra_keywords, array $longtail, string $tags_text): string {
        $word_count = str_word_count(wp_strip_all_tags($content));
        if ($word_count >= 600) {
            return $content;
        }
        $focus1 = $extra_keywords[0] ?? ($name . ' live chat');
        $focus2 = $extra_keywords[1] ?? ($name . ' webcam');
        $platform_text = self::format_platform_list($active_platforms, $active_platforms[0] ?? self::PLATFORM_FALLBACK_LABEL);
        $longtail_hint = $longtail[0] ?? ($name . ' schedule');
        $paragraphs = [
            'Fans returning to ' . $name . ' usually do so because the page makes it easy to compare platforms, understand the live-chat style, and jump directly into the right room without unnecessary friction.',
            $name . ' works well for visitors interested in ' . $focus1 . ' because the profile combines practical context, trusted links, and a cleaner overview of what to expect before joining a live room.',
            'Another reason this page is useful is that it covers ' . $focus2 . ' in a more complete way, helping visitors move from search intent to a real destination instead of bouncing between incomplete profile fragments.',
            'Platform availability matters too. Whether you prefer ' . $platform_text . ', the goal is to help you compare room quality, convenience, and profile visibility before choosing where to watch ' . $name . ' live.',
            'Searchers often arrive with very specific needs such as ' . $longtail_hint . '. By addressing those questions directly, the page becomes more useful for both first-time visitors and repeat fans.',
            $name . ' also stands out because the content stays focused on real user intent: better profile discovery, easier room comparison, and clearer guidance around safety, privacy, and navigation.',
            'The mix of ' . $tags_text . ' cues on this page gives readers more context about the performer style while keeping the copy readable and relevant for general live-chat search intent.',
        ];
        foreach ($paragraphs as $paragraph) {
            if (str_word_count(wp_strip_all_tags($content)) >= 620) {
                break;
            }
            $content .= "\n\n<p>" . esc_html($paragraph) . '</p>';
        }
        return $content;
    }

    private static function balance_focus_density(string $content, string $focus_keyword, array $active_platforms, array $extra_keywords): string {
        $focus_keyword = trim($focus_keyword);
        if ($focus_keyword === '') {
            return $content;
        }
        $density = self::keyword_density_percent($content, $focus_keyword);
        $platform_text = self::format_platform_list($active_platforms, $active_platforms[0] ?? self::PLATFORM_FALLBACK_LABEL);
        $extra_text = $extra_keywords[0] ?? ($focus_keyword . ' live chat');
        while ($density < 1.0) {
            $content .= "\n\n<p>" . esc_html($focus_keyword) . ' is the core topic of this page, with extra attention on ' . esc_html($extra_text) . ' and practical tips for viewers who want to watch on ' . esc_html($platform_text) . '.</p>';
            $density = self::keyword_density_percent($content, $focus_keyword);
            if ($density >= 1.0 || str_word_count(wp_strip_all_tags($content)) > 900) {
                break;
            }
        }
        if ($density > 3.2) {
            $seen = 0;
            $content = preg_replace_callback('/' . preg_quote($focus_keyword, '/') . '/iu', static function (array $matches) use (&$seen): string {
                $seen++;
                return $seen > 8 ? 'this profile' : $matches[0];
            }, $content) ?: $content;
        }
        return $content;
    }

    private static function similarity_score(string $content, int $post_id, int $limit = 10): float {
        $posts = get_posts([
            'post_type' => 'model',
            'posts_per_page' => $limit,
            'post_status' => 'publish',
            'post__not_in' => [$post_id],
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
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
            $hay = self::tokenize((string)get_post_field('post_content', (int)$compare_id));
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
            $text = trim(wp_strip_all_tags($matches[1]));
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
}
