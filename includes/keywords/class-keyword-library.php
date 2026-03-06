<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

/**
 * Loads keyword packs from wp-content/uploads/tmwseo-keywords/{category}/{type}.csv
 *
 * This is inspired by the older TMW SEO Autopilot keyword packs, but the Engine
 * adds relevance scoring + cleanup so we don't inject off-topic keywords.
 */
class KeywordLibrary {

    /** @var array<string, array<string, array<int, string>>> */
    private static array $cache = [];

    public static function base_dir(): string {
        $uploads = wp_upload_dir(null, false);
        $base = (string)($uploads['basedir'] ?? '');
        if ($base === '') {
            // Fallback to ABSPATH-based guess.
            $base = rtrim(ABSPATH, '/') . '/wp-content/uploads';
        }
        return rtrim($base, '/') . '/tmwseo-keywords';
    }

    public static function category_dir(string $category_slug): string {
        $category_slug = sanitize_key($category_slug);
        return rtrim(self::base_dir(), '/') . '/' . $category_slug;
    }

    public static function has_category(string $category_slug): bool {
        $dir = self::category_dir($category_slug);
        return is_dir($dir);
    }

    /**
     * @return string[]
     */
    public static function load(string $category_slug, string $type): array {
        $category_slug = sanitize_key($category_slug);
        $type = sanitize_key($type);

        if (isset(self::$cache[$category_slug][$type])) {
            return self::$cache[$category_slug][$type];
        }

        $file = self::category_dir($category_slug) . '/' . $type . '.csv';
        if (!file_exists($file)) {
            self::$cache[$category_slug][$type] = [];
            return [];
        }

        $out = [];
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            self::$cache[$category_slug][$type] = [];
            return [];
        }

        $row_i = 0;
        while (($row = fgetcsv($fh)) !== false) {
            $row_i++;
            if ($row_i === 1) {
                // header.
                continue;
            }
            if (!is_array($row) || empty($row)) {
                continue;
            }
            $kw = (string)($row[0] ?? '');
            $kw = trim($kw, " \t\n\r\0\x0B\"");
            $kw = self::clean_keyword($kw);
            if ($kw === '') {
                continue;
            }
            $out[] = $kw;
        }
        fclose($fh);

        // De-dupe.
        $out = array_values(array_unique($out));

        self::$cache[$category_slug][$type] = $out;
        return $out;
    }

    /**
     * Remove obvious noise introduced by autosuggest pipelines.
     */
    public static function clean_keyword(string $kw): string {
        $kw = trim(preg_replace('/\s+/u', ' ', $kw));
        if ($kw === '') return '';

        // Remove double words like "best best".
        $kw = preg_replace('/\b(\w+)\s+\1\b/i', '$1', $kw);

        // Strip repeated "best" spam.
        $kw = preg_replace('/\bbest\b(\s+\bbest\b)+/i', 'best', $kw);

        // Normalize quotes.
        $kw = trim($kw, " \t\n\r\0\x0B\"");
        $kw = trim(preg_replace('/\s+/u', ' ', $kw));
        return $kw;
    }

    /**
     * Deterministic multi-pick across categories.
     *
     * @param string[] $categories
     * @param string   $type       extra|longtail
     * @param int      $count
     * @param string   $seed
     * @param string[] $exclude
     * @param array    $context    for relevance scoring
     * @return string[]
     */
    public static function pick_multi(array $categories, string $type, int $count, string $seed, array $exclude = [], array $context = []): array {
        $categories = array_values(array_unique(array_filter(array_map('sanitize_key', $categories), 'strlen')));
        $exclude_map = [];
        foreach ($exclude as $e) {
            $e = strtolower(trim((string)$e));
            if ($e !== '') $exclude_map[$e] = true;
        }

        $candidates = [];
        foreach ($categories as $cat) {
            $kws = self::load($cat, $type);
            foreach ($kws as $kw) {
                $l = strtolower($kw);
                if (isset($exclude_map[$l])) continue;
                $score = self::score($kw, $context);
                if ($score <= 0) continue;
                $hash = sprintf('%u', crc32($seed . '|' . $type . '|' . $kw));
                $candidates[$score . ':' . $hash . ':' . $kw] = $kw;
            }
        }

        if (empty($candidates)) {
            return [];
        }

        // Sort by score desc then stable hash.
        uksort($candidates, function($a, $b){
            [$sa] = explode(':', $a, 2);
            [$sb] = explode(':', $b, 2);
            $sa = (int)$sa; $sb = (int)$sb;
            if ($sa === $sb) return strcmp($a, $b);
            return ($sa > $sb) ? -1 : 1;
        });

        $picked = [];
        foreach ($candidates as $kw) {
            $picked[] = $kw;
            if (count($picked) >= $count) break;
        }

        return $picked;
    }

    /**
     * Relevance scoring.
     *
     * Context keys (optional):
     * - page_type: model|video|category|tag
     * - name: model name
     * - tags: array of safe tags
     * - platforms: array of platform slugs user has links for
     */
    public static function score(string $kw, array $context = []): int {
        $kw_l = strtolower($kw);
        $page_type = (string)($context['page_type'] ?? '');
        $name = strtolower(trim((string)($context['name'] ?? '')));
        $tags = $context['tags'] ?? [];
        $platforms = $context['platforms'] ?? [];

        $score = 1;

        if ($name !== '') {
            // Boost if the keyword contains the model name (full or partial tokens).
            if (strpos($kw_l, $name) !== false) {
                $score += 120;
            } else {
                $parts = preg_split('/\s+/u', $name);
                $parts = is_array($parts) ? array_filter($parts, 'strlen') : [];
                $hits = 0;
                foreach ($parts as $p) {
                    if (strlen($p) < 3) continue;
                    if (preg_match('/\b' . preg_quote($p, '/') . '\b/i', $kw_l)) $hits++;
                }
                $score += min(60, $hits * 20);
            }
        }

        // Intent words.
        if (preg_match('/\b(live|webcam|cam|chat|stream|shows?)\b/i', $kw_l)) {
            $score += 20;
        }

        // Platform relevance.
        if (!empty($platforms) && is_array($platforms)) {
            foreach ($platforms as $p) {
                $p = strtolower((string)$p);
                if ($p === '') continue;
                if (preg_match('/\b' . preg_quote($p, '/') . '\b/i', $kw_l)) {
                    $score += 10;
                }
            }
        }

        // Tag relevance.
        if (!empty($tags) && is_array($tags)) {
            $tag_hits = 0;
            foreach ($tags as $t) {
                $t = strtolower((string)$t);
                if ($t === '' || strlen($t) < 3) continue;
                if (preg_match('/\b' . preg_quote($t, '/') . '\b/i', $kw_l)) {
                    $tag_hits++;
                }
            }
            $score += min(30, $tag_hits * 6);
        }

        // Penalize obvious off-topic queries on model pages.
        if ($page_type === 'model') {
            if (preg_match('/\b(best\s+cam\s+sites|webcam\s+sites|free\s+cams|202\d|sites\b)\b/i', $kw_l)) {
                $score -= 40;
            }
        }

        // Prefer reasonable lengths.
        $wc = count(array_filter(preg_split('/\s+/u', trim($kw)), 'strlen'));
        if ($wc >= 4) $score += 5;
        if ($wc >= 6) $score += 3;

        if ($score < 1) $score = 0;
        return (int)$score;
    }
}
