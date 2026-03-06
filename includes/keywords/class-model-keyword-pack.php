<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Platform\PlatformProfiles;

if (!defined('ABSPATH')) { exit; }

class ModelKeywordPack {

    /**
     * Build a keyword pack for a given post.
     *
     * @return array{primary:string, additional: string[], longtail: string[], sources: array}
     */
    public static function build(\WP_Post $post): array {
        $name = trim((string)$post->post_title);
        $primary = $name !== '' ? $name : 'live cam model';
        $allow_generic_tag_queries = self::allow_generic_tag_queries();

        $platform_slugs = self::active_platform_slugs($post->ID);
        $safe_tags = self::safe_tag_slugs_for_post($post);

        $context = [
            'page_type' => ($post->post_type === 'model') ? 'model' : (string)$post->post_type,
            'name' => $primary,
            'tags' => $safe_tags,
            'platforms' => $platform_slugs,
        ];

        $seed = $primary . '-' . $post->ID;

        // 1) DataForSEO suggestions (best-effort).
        $dfseo = [];
        if (DataForSEO::is_configured()) {
            $res = DataForSEO::keyword_suggestions($primary, 80);
            if (!empty($res['ok']) && !empty($res['items']) && is_array($res['items'])) {
                foreach ($res['items'] as $it) {
                    if (!is_array($it)) continue;
                    $kw = (string)($it['keyword'] ?? '');
                    $kw = KeywordLibrary::clean_keyword($kw);
                    if ($kw === '') continue;
                    $score = KeywordLibrary::score($kw, $context);
                    if ($score <= 0) continue;
                    $dfseo[$score . ':' . sprintf('%u', crc32($seed . '|' . $kw)) . ':' . $kw] = $kw;
                }
            }

            // Also enrich with 1-2 top tag seeds (helps long-tail coverage without off-topic spam).
            foreach (array_slice($safe_tags, 0, 2) as $tag_slug) {
                $tag_phrase = str_replace('-', ' ', (string)$tag_slug);
                if ($tag_phrase === '') continue;
                $tag_seed = $tag_phrase . ' cam girl';
                $tag_res = DataForSEO::keyword_suggestions($tag_seed, 60);
                if (!empty($tag_res['ok']) && !empty($tag_res['items']) && is_array($tag_res['items'])) {
                    foreach ($tag_res['items'] as $it) {
                        if (!is_array($it)) continue;
                        $kw = (string)($it['keyword'] ?? '');
                        $kw = KeywordLibrary::clean_keyword($kw);
                        if ($kw === '') continue;
                        if (!$allow_generic_tag_queries && !self::keyword_contains_name($kw, $primary)) continue;
                        $score = KeywordLibrary::score($kw, $context);
                        if ($score <= 0) continue;
                        $dfseo[$score . ':' . sprintf('%u', crc32($seed . '|tag|' . $tag_slug . '|' . $kw)) . ':' . $kw] = $kw;
                    }
                }
            }
        }
        if (!empty($dfseo)) {
            uksort($dfseo, function($a, $b){
                $sa = (int)explode(':', $a, 2)[0];
                $sb = (int)explode(':', $b, 2)[0];
                if ($sa === $sb) return strcmp($a, $b);
                return ($sa > $sb) ? -1 : 1;
            });
        }

        // 2) Keyword pack library from uploads (extra + longtail).
        $categories = array_values(array_unique(array_filter(array_merge(
            $platform_slugs,
            $safe_tags
        ), 'strlen')));

        $lib_extra = KeywordLibrary::pick_multi($categories, 'extra', 40, $seed, [], $context);
        $lib_long = KeywordLibrary::pick_multi($categories, 'longtail', 40, $seed, [], $context);

        // 3) Deterministic, always-relevant fallbacks.
        $fallback_additional = self::fallback_additional($primary, $platform_slugs, $safe_tags);
        $fallback_longtail = self::fallback_longtail($primary, $platform_slugs, $safe_tags);

        // 4) Merge, score, and pick.
        $additional_pool = [];
        foreach ($fallback_additional as $kw) {
            $kw = KeywordLibrary::clean_keyword($kw);
            if ($kw === '') continue;
            $additional_pool[$kw] = KeywordLibrary::score($kw, $context);
        }
        foreach ($lib_extra as $kw) {
            $kw = KeywordLibrary::clean_keyword($kw);
            if ($kw === '') continue;
            if (!$allow_generic_tag_queries && self::is_tag_only_query($kw, $primary, $safe_tags)) continue;
            $additional_pool[$kw] = max($additional_pool[$kw] ?? 0, KeywordLibrary::score($kw, $context));
        }
        foreach ($dfseo as $kw) {
            // Only take shorter items into "additional".
            $wc = count(array_filter(preg_split('/\s+/u', trim($kw)), 'strlen'));
            if ($wc > 6) continue;
            if (!$allow_generic_tag_queries && self::is_tag_only_query($kw, $primary, $safe_tags)) continue;
            $additional_pool[$kw] = max($additional_pool[$kw] ?? 0, KeywordLibrary::score($kw, $context));
        }

        // pick top 4, preferring those with name.
        $additional = self::pick_top($additional_pool, 4, $primary);
        $additional = self::ensure_name_in_additional($additional, $primary, $platform_slugs, $safe_tags);

        $longtail_pool = [];
        foreach ($fallback_longtail as $kw) {
            $kw = KeywordLibrary::clean_keyword($kw);
            if ($kw === '') continue;
            $longtail_pool[$kw] = KeywordLibrary::score($kw, $context);
        }
        foreach ($lib_long as $kw) {
            $kw = KeywordLibrary::clean_keyword($kw);
            if ($kw === '') continue;
            if (!$allow_generic_tag_queries && self::is_tag_only_query($kw, $primary, $safe_tags)) continue;
            $longtail_pool[$kw] = max($longtail_pool[$kw] ?? 0, KeywordLibrary::score($kw, $context));
        }
        foreach ($dfseo as $kw) {
            $wc = count(array_filter(preg_split('/\s+/u', trim($kw)), 'strlen'));
            if ($wc < 4) continue;
            if (!$allow_generic_tag_queries && self::is_tag_only_query($kw, $primary, $safe_tags)) continue;
            $longtail_pool[$kw] = max($longtail_pool[$kw] ?? 0, KeywordLibrary::score($kw, $context));
        }

        $longtail = self::pick_top($longtail_pool, 8, $primary);

        return [
            'primary' => $primary,
            'additional' => $additional,
            'longtail' => $longtail,
            'sources' => [
                'platforms' => $platform_slugs,
                'tags' => $safe_tags,
                'dfseo' => DataForSEO::is_configured() ? 1 : 0,
                'keyword_pack_dirs' => $categories,
            ],
        ];
    }

    /** @return string[] */
    private static function active_platform_slugs(int $model_id): array {
        $rows = PlatformProfiles::get_links($model_id);
        $slugs = [];
        foreach ($rows as $r) {
            $p = isset($r['platform']) ? sanitize_key((string)$r['platform']) : '';
            if ($p !== '') $slugs[] = $p;
        }
        // If primary is set but there is no row yet, keep it.
        $primary = sanitize_key((string)get_post_meta($model_id, '_tmwseo_platform_primary', true));
        if ($primary !== '') $slugs[] = $primary;
        return array_values(array_unique(array_filter($slugs, 'strlen')));
    }

    /** @return string[] */
    private static function safe_tag_slugs_for_post(\WP_Post $post): array {
        $out = [];
        $taxes = get_object_taxonomies($post->post_type);
        foreach ((array)$taxes as $tax) {
            $terms = get_the_terms($post, $tax);
            if (!is_array($terms)) continue;
            foreach ($terms as $t) {
                if (!($t instanceof \WP_Term)) continue;
                $slug = sanitize_key((string)$t->slug);
                if ($slug === '') continue;
                if (in_array($slug, ['uncategorized'], true)) continue;
                // Block super risky/irrelevant labels.
                if (preg_match('/\b(teen|underage|school)\b/i', $slug)) continue;
                $out[] = $slug;
            }
        }
        // Prefer tag-like terms.
        $out = array_values(array_unique($out));

        // Keep only tags that have a keyword pack directory.
        $out = array_values(array_filter($out, function($slug){
            return KeywordLibrary::has_category($slug);
        }));

        return $out;
    }

    /** @return string[] */
    private static function fallback_additional(string $name, array $platforms, array $tags): array {
        $name = trim($name);
        $p1 = $platforms[0] ?? '';

        $out = [
            $name . ' live chat',
            $name . ' webcam',
            $name . ' live',
            'watch ' . $name . ' live',
        ];

        if ($p1 !== '') {
            $out[] = $name . ' on ' . $p1;
            $out[] = 'watch ' . $name . ' on ' . $p1;
        }

        foreach (array_slice($tags, 0, 2) as $t) {
            $t = str_replace('-', ' ', $t);
            $out[] = $name . ' ' . $t . ' cam';
        }

        return array_values(array_unique(array_filter(array_map('trim', $out), 'strlen')));
    }

    /** @return string[] */
    private static function fallback_longtail(string $name, array $platforms, array $tags): array {
        $name = trim($name);
        $p1 = $platforms[0] ?? '';

        $out = [
            'how to chat with ' . $name . ' live',
            'where to watch ' . $name . ' live',
            $name . ' live webcam chat tips',
            'is ' . $name . ' online now',
        ];

        if ($p1 !== '') {
            $out[] = 'where to watch ' . $name . ' on ' . $p1;
            $out[] = $name . ' official ' . $p1 . ' profile';
        }

        foreach (array_slice($tags, 0, 2) as $t) {
            $t = str_replace('-', ' ', $t);
            if ($t === '') continue;
            // Strict model+tag longtails for top tags.
            $out[] = $name . ' ' . $t . ' live cam';
            $out[] = 'watch ' . $name . ' ' . $t . ' webcam';
            $out[] = $name . ' ' . $t . ' live chat';
            $out[] = $name . ' ' . $t . ' live cam show';
        }

        return array_values(array_unique(array_filter(array_map('trim', $out), 'strlen')));
    }

    private static function allow_generic_tag_queries(): bool {
        $opts = get_option('tmwseo_engine_settings', []);
        if (!is_array($opts)) return false;
        return !empty($opts['keyword_allow_generic_tag_queries']);
    }

    private static function keyword_contains_name(string $keyword, string $name): bool {
        $name = trim(strtolower($name));
        if ($name === '') return false;
        return strpos(strtolower($keyword), $name) !== false;
    }

    /**
     * @param string[] $additional
     * @param string[] $platforms
     * @param string[] $tags
     * @return string[]
     */
    private static function ensure_name_in_additional(array $additional, string $name, array $platforms, array $tags): array {
        $safe = array_values(array_unique(array_filter(array_map(function($kw){
            return KeywordLibrary::clean_keyword((string)$kw);
        }, $additional), 'strlen')));

        $fallbacks = self::fallback_additional($name, $platforms, $tags);

        for ($i = 0; $i < 4; $i++) {
            $kw = $safe[$i] ?? '';
            if ($kw !== '' && self::keyword_contains_name($kw, $name)) {
                continue;
            }

            $replacement = $fallbacks[$i] ?? ($name . ' live cam');
            $replacement = KeywordLibrary::clean_keyword($replacement);
            if ($replacement === '') {
                $replacement = $name . ' live cam';
            }

            if ($kw !== '' && $kw === $replacement) {
                continue;
            }

            $existing_idx = array_search($replacement, $safe, true);
            if ($existing_idx !== false) {
                array_splice($safe, (int)$existing_idx, 1);
            }

            $safe[$i] = $replacement;
        }

        $safe = array_values(array_filter($safe, 'strlen'));
        while (count($safe) < 4) {
            $next = $fallbacks[count($safe)] ?? ($name . ' live cam');
            $next = KeywordLibrary::clean_keyword($next);
            if ($next === '') $next = $name . ' live cam';
            if (!in_array($next, $safe, true)) {
                $safe[] = $next;
            } else {
                break;
            }
        }

        return array_slice($safe, 0, 4);
    }

    private static function is_tag_only_query(string $keyword, string $name, array $tags): bool {
        if (self::keyword_contains_name($keyword, $name)) return false;

        $keyword_l = strtolower($keyword);
        foreach (array_slice($tags, 0, 2) as $tag_slug) {
            $tag_phrase = trim(strtolower(str_replace('-', ' ', (string)$tag_slug)));
            if ($tag_phrase === '') continue;
            if (strpos($keyword_l, $tag_phrase) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,int> $pool
     * @return string[]
     */
    private static function pick_top(array $pool, int $count, string $name): array {
        // Normalize pool keys.
        $scored = [];
        foreach ($pool as $kw => $score) {
            $kw = KeywordLibrary::clean_keyword((string)$kw);
            if ($kw === '') continue;
            $scored[$kw] = (int)$score;
        }

        // Sort by score.
        uksort($scored, function($a, $b) use ($scored){
            $sa = $scored[$a] ?? 0;
            $sb = $scored[$b] ?? 0;
            if ($sa === $sb) return strcmp($a, $b);
            return ($sa > $sb) ? -1 : 1;
        });

        $picked = [];
        $name_l = strtolower($name);

        // First pass: try to take those containing the name.
        foreach ($scored as $kw => $score) {
            if (count($picked) >= $count) break;
            if ($name_l !== '' && strpos(strtolower($kw), $name_l) !== false) {
                $picked[] = $kw;
            }
        }

        // Second pass: fill remaining with best-scoring.
        foreach ($scored as $kw => $score) {
            if (count($picked) >= $count) break;
            if (in_array($kw, $picked, true)) continue;
            $picked[] = $kw;
        }

        return array_slice($picked, 0, $count);
    }
}
