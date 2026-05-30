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
        $model_name = trim((string)$post->post_title);
        $keyword_context_name = $model_name !== '' ? $model_name : 'live cam model';
        $primary = $keyword_context_name;
        $allow_generic_tag_queries = self::allow_generic_tag_queries();
        $is_model_page = $post->post_type === 'model';

        $platform_slugs = self::active_platform_slugs($post->ID);
        $safe_tags = self::safe_tag_slugs_for_post($post);
        $top_tags = self::top_model_tags($safe_tags);

        $context = [
            'page_type' => ($post->post_type === 'model') ? 'model' : (string)$post->post_type,
            'name' => $keyword_context_name,
            'tags' => $safe_tags,
            'platforms' => $platform_slugs,
        ];

        $seed = $keyword_context_name . '-' . $post->ID;

        $classified_fragment = $is_model_page
            ? (new ClassifiedModelKeywordProvider())->build_for_model((int) $post->ID, $model_name)
            : self::empty_classified_fragment();
        if ($is_model_page && !empty($classified_fragment['primary_candidates'][0])) {
            $primary = (string) $classified_fragment['primary_candidates'][0];
        }
        $classified_exclusions = $is_model_page
            ? self::classified_exclusion_lookup($classified_fragment)
            : [];

        // 1) DataForSEO suggestions (best-effort).
        $dfseo = [];
        if (DataForSEO::is_configured()) {
            $res = DataForSEO::keyword_suggestions($keyword_context_name, 80);
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
            foreach ($top_tags as $tag_slug) {
                $tag_phrase = str_replace('-', ' ', (string)$tag_slug);
                if ($tag_phrase === '') continue;
                $tag_seed = $tag_phrase . ' cam girl';
                $tag_res = DataForSEO::keyword_suggestions($tag_seed, 60);
                if (!empty($tag_res['ok']) && !empty($tag_res['items']) && is_array($tag_res['items'])) {
                    foreach ($tag_res['items'] as $it) {
                        if (!is_array($it)) continue;
                        $kw = (string)($it['keyword'] ?? '');
                        $kw = self::normalize_keyword($kw);
                        if ($kw === '') continue;
                        if ($is_model_page && !$allow_generic_tag_queries && !self::keyword_contains_name($kw, $model_name)) continue;
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
        $fallback_additional = self::fallback_additional($keyword_context_name, $platform_slugs, $top_tags);
        $fallback_longtail = self::fallback_longtail($keyword_context_name, $platform_slugs, $top_tags);

        // 4) Merge, score, and pick.
        $additional_pool = [];
        foreach ($fallback_additional as $kw) {
            $kw = self::normalize_keyword($kw);
            if ($kw === '') continue;
            $additional_pool[$kw] = KeywordLibrary::score($kw, $context);
        }
        foreach ($fallback_longtail as $kw) {
            $kw = self::normalize_keyword($kw);
            if ($kw === '') continue;
            $wc = count(array_filter(preg_split('/\s+/u', trim($kw)), 'strlen'));
            if ($wc > 6) continue;
            $additional_pool[$kw] = max($additional_pool[$kw] ?? 0, KeywordLibrary::score($kw, $context));
        }
        foreach ($lib_extra as $kw) {
            $kw = self::normalize_keyword($kw);
            if ($kw === '') continue;
            if ($is_model_page && !$allow_generic_tag_queries && !self::keyword_contains_name($kw, $model_name)) continue;
            $additional_pool[$kw] = max($additional_pool[$kw] ?? 0, KeywordLibrary::score($kw, $context));
        }
        foreach ($dfseo as $kw) {
            // Only take shorter items into "additional".
            $wc = count(array_filter(preg_split('/\s+/u', trim($kw)), 'strlen'));
            if ($wc > 6) continue;
            $kw = self::normalize_keyword($kw);
            if ($kw === '') continue;
            if ($is_model_page && !$allow_generic_tag_queries && !self::keyword_contains_name($kw, $model_name)) continue;
            $additional_pool[$kw] = max($additional_pool[$kw] ?? 0, KeywordLibrary::score($kw, $context));
        }

        if ($is_model_page) {
            $additional_pool = self::filter_scored_pool_for_model_page($additional_pool);
            $additional_pool = self::filter_scored_pool_against_classified_exclusions($additional_pool, $classified_exclusions);
            $fallback_additional = PageTypeKeywordFilter::filter_for_model_page($fallback_additional);
            $fallback_additional = self::filter_keywords_against_classified_exclusions($fallback_additional, $classified_exclusions);
        }

        // Model pages expose exactly 4 additional keywords. Approved classified
        // personal model keywords are allowed to lead this list; generated
        // fallbacks stay name-free so they do not repeat the exact model name or
        // push density over the safe range later on.
        $additional_target = $is_model_page
            ? 4
            : self::dynamic_additional_count($additional_pool, $platform_slugs, $safe_tags);

        if ($is_model_page) {
            $additional = self::pick_name_free_top(
                $additional_pool,
                $additional_target,
                $model_name,
                $fallback_additional
            );
            $additional = self::merge_preferred_keywords(
                (array) ($classified_fragment['extra_focus_candidates'] ?? []),
                $additional,
                $additional_target
            );
            $additional = self::filter_keywords_against_classified_exclusions($additional, $classified_exclusions);
            self::debug_assert_model_additional_keywords($additional, $model_name);
        } else {
            $additional = self::pick_top($additional_pool, $additional_target, $primary, $additional_target);
        }

        $longtail_pool = [];
        foreach ($fallback_longtail as $kw) {
            $kw = self::normalize_keyword($kw);
            if ($kw === '') continue;
            $longtail_pool[$kw] = KeywordLibrary::score($kw, $context);
        }
        foreach ($lib_long as $kw) {
            $kw = self::normalize_keyword($kw);
            if ($kw === '') continue;
            if ($is_model_page && !$allow_generic_tag_queries && !self::keyword_contains_name($kw, $model_name)) continue;
            $longtail_pool[$kw] = max($longtail_pool[$kw] ?? 0, KeywordLibrary::score($kw, $context));
        }
        foreach ($dfseo as $kw) {
            $kw = self::normalize_keyword($kw);
            if ($kw === '') continue;
            $wc = count(array_filter(preg_split('/\s+/u', trim($kw)), 'strlen'));
            if ($wc < 4) continue;
            if ($is_model_page && !$allow_generic_tag_queries && !self::keyword_contains_name($kw, $model_name)) continue;
            $longtail_pool[$kw] = max($longtail_pool[$kw] ?? 0, KeywordLibrary::score($kw, $context));
        }

        if ($is_model_page) {
            $longtail_pool = self::filter_scored_pool_for_model_page($longtail_pool);
            $longtail_pool = self::filter_scored_pool_against_classified_exclusions($longtail_pool, $classified_exclusions);
            $fallback_longtail = PageTypeKeywordFilter::filter_for_model_page($fallback_longtail);
            $fallback_longtail = self::filter_keywords_against_classified_exclusions($fallback_longtail, $classified_exclusions);
            $longtail = self::pick_name_free_top(
                $longtail_pool,
                8,
                $model_name,
                $fallback_longtail
            );
            $longtail = self::merge_preferred_keywords(
                array_merge(
                    (array) ($classified_fragment['body_semantic_candidates'] ?? []),
                    (array) ($classified_fragment['modifier_candidates'] ?? [])
                ),
                $longtail,
                8
            );
        } else {
            $longtail = self::pick_top($longtail_pool, 8, $primary, 8);
        }

        // Patch 2.1: compute keyword confidence from real scoring data.
        // Confidence = how well the final selected additional keywords scored.
        $confidence = self::compute_confidence($additional, $additional_pool, $platform_slugs, $safe_tags, DataForSEO::is_configured());

        // Dedicated Rank Math chips: model-name-led, varied per post.
        // These replace the old name-free generic fallback as the Rank Math chip source.
        $rankmath_chips = $is_model_page
            ? self::merge_preferred_keywords(
                (array) ($classified_fragment['extra_focus_candidates'] ?? []),
                self::build_rankmath_chips($model_name, $post->ID, $platform_slugs),
                4
            )
            : [];
        if ($is_model_page) {
            $rankmath_chips = self::filter_keywords_against_classified_exclusions($rankmath_chips, $classified_exclusions);
        }

        return [
            'primary'             => $primary,
            'additional'          => $additional,
            'longtail'            => $longtail,
            'rankmath_additional' => $rankmath_chips,
            'confidence'          => $confidence,
            'sources' => [
                'platforms' => $platform_slugs,
                'tags' => $top_tags,
                'dfseo' => DataForSEO::is_configured() ? 1 : 0,
                'keyword_pack_dirs' => $categories,
                'classified_model_keywords' => $classified_fragment['sources'] ?? [],
            ],
        ];
    }


    /** @return array{primary_candidates:array<int,string>,extra_focus_candidates:array<int,string>,body_semantic_candidates:array<int,string>,modifier_candidates:array<int,string>,excluded_candidates:array<int,string>,sources:array<string,mixed>} */
    private static function empty_classified_fragment(): array {
        return [
            'primary_candidates' => [],
            'extra_focus_candidates' => [],
            'body_semantic_candidates' => [],
            'modifier_candidates' => [],
            'excluded_candidates' => [],
            'sources' => [],
        ];
    }

    /** @param array<string,mixed> $fragment @return array<string,bool> */
    private static function classified_exclusion_lookup(array $fragment): array {
        $lookup = [];
        foreach ((array) ($fragment['excluded_candidates'] ?? []) as $keyword) {
            $clean = self::normalize_keyword((string) $keyword);
            if ($clean !== '') {
                $lookup[strtolower($clean)] = true;
            }
        }
        return $lookup;
    }

    /** @param string[] $preferred @param string[] $fallback @return string[] */
    private static function merge_preferred_keywords(array $preferred, array $fallback, int $limit): array {
        $merged = [];
        $seen = [];
        foreach (array_merge($preferred, $fallback) as $keyword) {
            $clean = self::normalize_keyword((string) $keyword);
            if ($clean === '') {
                continue;
            }
            $key = strtolower($clean);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $clean;
            if (count($merged) >= $limit) {
                break;
            }
        }
        return $merged;
    }

    /** @param array<string,int> $pool @param array<string,bool> $excluded @return array<string,int> */
    private static function filter_scored_pool_against_classified_exclusions(array $pool, array $excluded): array {
        if (empty($excluded)) {
            return $pool;
        }
        $filtered = [];
        foreach ($pool as $keyword => $score) {
            $clean = self::normalize_keyword((string) $keyword);
            if ($clean === '' || isset($excluded[strtolower($clean)])) {
                continue;
            }
            $filtered[$clean] = (int) $score;
        }
        return $filtered;
    }

    /** @param string[] $keywords @param array<string,bool> $excluded @return string[] */
    private static function filter_keywords_against_classified_exclusions(array $keywords, array $excluded): array {
        if (empty($excluded)) {
            return $keywords;
        }
        $out = [];
        foreach ($keywords as $keyword) {
            $clean = self::normalize_keyword((string) $keyword);
            if ($clean === '' || isset($excluded[strtolower($clean)])) {
                continue;
            }
            $out[] = $clean;
        }
        return self::dedupe_keywords($out);
    }

    /**
     * Determine how many additional keywords to assign based on data richness.
     *
     * Replaces the old hardcoded "always 4" rule.
     *
     * Minimum: 2 (even thin models get at least 2 compound keywords).
     * Maximum: 6 (rich models with many tags/platforms/DFSEO results).
     *
     * @param array<string,int> $pool     Scored keyword pool.
     * @param string[]          $platforms Active platform slugs.
     * @param string[]          $tags      Safe tag slugs.
     * @return int
     */
    private static function dynamic_additional_count(array $pool, array $platforms, array $tags): int {
        $quality_threshold = 30; // minimum score to count as a viable candidate
        $viable = 0;
        foreach ($pool as $score) {
            if ((int) $score >= $quality_threshold) {
                $viable++;
            }
        }

        // Base count from viable pool size.
        if ($viable >= 12) {
            $count = 6;
        } elseif ($viable >= 8) {
            $count = 5;
        } elseif ($viable >= 5) {
            $count = 4;
        } elseif ($viable >= 3) {
            $count = 3;
        } else {
            $count = 2;
        }

        // Bonus for multi-platform models.
        if (count($platforms) >= 3) {
            $count = min(6, $count + 1);
        }

        // Bonus for tag-rich models.
        if (count($tags) >= 6) {
            $count = min(6, $count + 1);
        }

        return max(2, min(6, $count));
    }

    /**
     * Compute keyword confidence from real scoring data.
     *
     * Patch 2.1: confidence reflects how strong the keyword assignment actually is.
     * NOT a placeholder — derived from: selected keyword scores, data source richness,
     * platform count, tag count.
     *
     * Scale: 0–100 (float).
     *
     * @param string[]          $selected   Chosen additional keywords.
     * @param array<string,int> $pool       Full scored keyword pool.
     * @param string[]          $platforms  Active platform slugs.
     * @param string[]          $tags       Safe tag slugs.
     * @param bool              $has_dfseo  Whether DataForSEO was available.
     * @return float
     */
    private static function compute_confidence(array $selected, array $pool, array $platforms, array $tags, bool $has_dfseo): float {
        if (empty($selected)) {
            return 10.0; // bare minimum — we have a primary keyword but nothing else
        }

        // Average score of selected keywords (max possible ~80 from KeywordLibrary::score).
        $scores = [];
        $pool_lower = [];
        foreach ($pool as $kw => $score) {
            $pool_lower[strtolower((string) $kw)] = (int) $score;
        }
        foreach ($selected as $kw) {
            $key = strtolower((string) $kw);
            $scores[] = $pool_lower[$key] ?? 0;
        }

        $avg_score = count($scores) > 0 ? array_sum($scores) / count($scores) : 0;

        // Normalize avg_score from 0–80 range to 0–50 contribution.
        $score_component = min(50.0, ($avg_score / 80.0) * 50.0);

        // Data richness bonuses.
        $richness = 0.0;
        if ($has_dfseo)              $richness += 15.0; // real demand data available
        if (count($platforms) >= 1)  $richness += 10.0;
        if (count($platforms) >= 3)  $richness += 5.0;
        if (count($tags) >= 3)       $richness += 10.0;
        if (count($tags) >= 6)       $richness += 5.0;
        if (count($selected) >= 3)   $richness += 5.0;

        return round(max(5.0, min(100.0, $score_component + $richness)), 2);
    }

    /** @param string[] $tags @return string[] */
    private static function top_model_tags(array $tags): array {
        $generic = [
            'girl' => true,
            'cam' => true,
            'webcam' => true,
            'live' => true,
            'chat' => true,
            'model' => true,
            'show' => true,
        ];

        $scored = [];
        foreach (array_values($tags) as $idx => $tag_slug) {
            $tag_slug = sanitize_key((string)$tag_slug);
            if ($tag_slug === '') continue;
            $phrase = str_replace('-', ' ', $tag_slug);
            $len = strlen($phrase);
            if ($len < 3) continue;

            $is_generic = isset($generic[strtolower($phrase)]);
            $scored[] = [
                'tag' => $tag_slug,
                'len' => $len,
                'generic' => $is_generic ? 1 : 0,
                'idx' => (int)$idx,
            ];
        }

        usort($scored, function($a, $b){
            if ($a['generic'] !== $b['generic']) {
                return ($a['generic'] < $b['generic']) ? -1 : 1;
            }
            if ($a['len'] !== $b['len']) {
                return ($a['len'] > $b['len']) ? -1 : 1;
            }
            return ($a['idx'] < $b['idx']) ? -1 : 1;
        });

        $out = [];
        foreach ($scored as $it) {
            $tag = (string)$it['tag'];
            if ($tag === '') continue;
            $out[] = $tag;
            if (count($out) >= 2) break;
        }

        return $out;
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
        $labels = array_values(array_filter(array_map([self::class, 'platform_keyword_label'], $platforms), 'strlen'));
        $primary_platform = $labels[0] ?? '';
        $secondary_platform = $labels[1] ?? '';

        $out = [];
        if ($primary_platform !== '') {
            $out[] = $primary_platform . ' schedule';
        }
        if ($secondary_platform !== '') {
            $out[] = $secondary_platform . ' profile';
        } elseif ($primary_platform !== '') {
            $out[] = $primary_platform . ' profile';
        }

        $out[] = 'verified profile links';
        $out[] = 'private live chat';
        $out[] = 'HD live stream';
        $out[] = 'real-time chat features';
        $out[] = 'live webcam chat tips';
        $out[] = 'live chat schedule';

        foreach (array_slice($tags, 0, 1) as $tag) {
            $tag_phrase = trim(str_replace('-', ' ', (string) $tag));
            if ($tag_phrase !== '') {
                $out[] = $tag_phrase . ' live chat';
            }
        }

        return self::dedupe_keywords($out);
    }

    /** @return string[] */
    private static function fallback_longtail(string $name, array $platforms, array $tags): array {
        $labels = array_values(array_filter(array_map([self::class, 'platform_keyword_label'], $platforms), 'strlen'));
        $primary_platform = $labels[0] ?? '';
        $secondary_platform = $labels[1] ?? '';

        $out = [
            'how to join live cam chat',
            'live chat schedule',
            'private live chat tips',
            'HD live stream experience',
            'real-time chat features',
            'how to join a live session',
        ];

        if ($primary_platform !== '') {
            $out[] = $primary_platform . ' live chat schedule';
            $out[] = $primary_platform . ' profile guide';
        }
        if ($secondary_platform !== '') {
            $out[] = $secondary_platform . ' profile guide';
        }

        foreach (array_slice($tags, 0, 2) as $tag) {
            $tag_phrase = trim(str_replace('-', ' ', (string) $tag));
            if ($tag_phrase === '') {
                continue;
            }
            $out[] = $tag_phrase . ' chat ideas';
            $out[] = $tag_phrase . ' chat style';
        }

        return self::dedupe_keywords($out);
    }


    /**
     * @param array<string,int> $pool
     * @return array<string,int>
     */
    private static function filter_scored_pool_for_model_page(array $pool): array {
        if (empty($pool)) {
            return [];
        }

        $allowed = PageTypeKeywordFilter::filter_for_model_page(array_keys($pool));
        $allowed_keys = [];
        foreach ($allowed as $kw) {
            $allowed_keys[strtolower(self::normalize_keyword((string) $kw))] = true;
        }

        $filtered = [];
        foreach ($pool as $kw => $score) {
            $clean = self::normalize_keyword((string) $kw);
            if ($clean === '') {
                continue;
            }
            if (!isset($allowed_keys[strtolower($clean)])) {
                continue;
            }
            $filtered[$clean] = max((int) ($filtered[$clean] ?? 0), (int) $score);
        }

        return $filtered;
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
     * @param array<string,int> $pool
     * @param string[]          $fallbacks
     * @return string[]
     */
    private static function pick_name_free_top(array $pool, int $count, string $name, array $fallbacks): array {
        $filtered = [];
        foreach ($pool as $kw => $score) {
            $kw = self::normalize_keyword((string) $kw);
            if ($kw === '' || self::keyword_contains_name($kw, $name)) {
                continue;
            }
            $filtered[$kw] = max((int) ($filtered[$kw] ?? 0), (int) $score);
        }

        $picked = self::pick_top($filtered, $count, '', $count);
        $ordered = [];
        foreach (array_merge($picked, $fallbacks) as $kw) {
            $clean = self::normalize_keyword((string) $kw);
            if ($clean === '' || self::keyword_contains_name($clean, $name) || in_array($clean, $ordered, true)) {
                continue;
            }
            $ordered[] = $clean;
            if (count($ordered) >= $count) {
                break;
            }
        }

        return array_slice($ordered, 0, $count);
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
     * Build Rank Math-specific keyword chips for a model page.
     *
     * Chips are always model-name-led (e.g. "Arianna webcam"),
     * varied deterministically per post ID, and safe for Rank Math.
     * Replaces the old name-free generic fallback as the Rank Math chip source.
     *
     * @param string   $name          Exact model name.
     * @param int      $post_id       Post ID used as deterministic seed.
     * @param string[] $platform_slugs Active platform slugs for optional platform chip.
     * @return string[]               Up to 4 chips.
     */
    private static function build_rankmath_chips(string $name, int $post_id, array $platform_slugs): array {
        if ($name === '') {
            return [];
        }

        // Modifier pool — compact, readable, 1–4 word suffixes.
        $modifiers = [
            'webcam model',
            'live cam chat',
            'cam profile',
            'webcam chat',
            'cam model',
            'cam girl',
        ];

        // Deterministic Fisher-Yates shuffle seeded by name + post_id.
        $pool = $modifiers;
        $lcg  = abs((int) crc32($name . '-' . $post_id));
        for ($i = count($pool) - 1; $i > 0; $i--) {
            $lcg   = ($lcg * 1664525 + 1013904223) & 0xFFFFFFFF;
            $j     = $lcg % ($i + 1);
            $tmp   = $pool[$i];
            $pool[$i] = $pool[$j];
            $pool[$j] = $tmp;
        }

        // Build 4 name-led chips from shuffled modifiers.
        $chips = [];
        foreach ($pool as $mod) {
            $chips[] = $name . ' ' . $mod;
            if (count($chips) >= 4) {
                break;
            }
        }

        // Optionally replace the last chip with a platform-specific one
        // (e.g. "Arianna LiveJasmin") when a primary platform is known.
        $labels = array_values(array_filter(
            array_map([self::class, 'platform_keyword_label'], $platform_slugs),
            'strlen'
        ));
        if (!empty($labels)) {
            $platform_chip   = $name . ' ' . $labels[0];
            $platform_chip_l = strtolower($platform_chip);
            $already         = false;
            foreach ($chips as $c) {
                if (strtolower($c) === $platform_chip_l) {
                    $already = true;
                    break;
                }
            }
            if (!$already && count($chips) >= 4) {
                array_pop($chips);
                $chips[] = $platform_chip;
            }
        }

        return array_slice(PageTypeKeywordFilter::filter_for_model_page(self::dedupe_keywords($chips)), 0, 4);
    }

    private static function platform_keyword_label(string $platform): string {
        $platform = sanitize_key($platform);
        $map = [
            'livejasmin' => 'LiveJasmin',
            'stripchat' => 'Stripchat',
            'myfreecams' => 'MyFreeCams',
            'camsoda' => 'CamSoda',
            'cam4' => 'CAM4',
            'chaturbate' => 'Chaturbate',
            'bonga' => 'Bonga',
        ];
        if (isset($map[$platform])) {
            return $map[$platform];
        }

        $platform = trim(str_replace(['-', '_'], ' ', $platform));
        return $platform !== '' ? ucwords($platform) : '';
    }

    /**
     * @param array<string,int> $pool
     * @return string[]
     */
    private static function pick_top(array $pool, int $count, string $name, int $max_non_name = PHP_INT_MAX): array {
        // Normalize pool keys.
        $scored = [];
        foreach ($pool as $kw => $score) {
            $kw = self::normalize_keyword((string)$kw);
            if ($kw === '') continue;
            $key = strtolower($kw);
            if (!isset($scored[$key])) {
                $scored[$key] = ['kw' => $kw, 'score' => (int)$score];
            } else {
                $scored[$key]['score'] = max((int)$scored[$key]['score'], (int)$score);
            }
        }

        // Sort by score.
        uasort($scored, function($a, $b){
            $sa = (int)($a['score'] ?? 0);
            $sb = (int)($b['score'] ?? 0);
            if ($sa === $sb) return strcmp((string)($a['kw'] ?? ''), (string)($b['kw'] ?? ''));
            return ($sa > $sb) ? -1 : 1;
        });

        $picked = [];
        $name_l = strtolower($name);

        // First pass: try to take those containing the name.
        foreach ($scored as $item) {
            if (count($picked) >= $count) break;
            $kw = (string)($item['kw'] ?? '');
            if ($name_l !== '' && strpos(strtolower($kw), $name_l) !== false) {
                $picked[] = $kw;
            }
        }

        // Second pass: fill remaining with best-scoring.
        $non_name_added = 0;
        foreach ($scored as $item) {
            if (count($picked) >= $count) break;
            $kw = (string)($item['kw'] ?? '');
            if (in_array($kw, $picked, true)) continue;
            if ($name_l !== '' && strpos(strtolower($kw), $name_l) === false) {
                if ($non_name_added >= $max_non_name) continue;
                $non_name_added++;
            }
            $picked[] = $kw;
        }

        return array_slice($picked, 0, $count);
    }

    private static function normalize_keyword(string $keyword): string {
        $keyword = KeywordLibrary::clean_keyword($keyword);
        if ($keyword === '') return '';

        $parts = preg_split('/\s+/u', trim($keyword));
        $parts = is_array($parts) ? array_values(array_filter($parts, 'strlen')) : [];
        if (empty($parts)) return '';
        if (count($parts) > 7) {
            $parts = array_slice($parts, 0, 7);
        }
        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    /** @param string[] $keywords @return string[] */
    private static function dedupe_keywords(array $keywords): array {
        $out = [];
        $seen = [];
        foreach ($keywords as $kw) {
            $clean = self::normalize_keyword((string)$kw);
            if ($clean === '') continue;
            $key = strtolower($clean);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $clean;
        }
        return $out;
    }

    /** @param string[] $additional */
    private static function debug_assert_model_additional_keywords(array $additional, string $name): void {
        if (!(defined('TMWSEO_DEBUG') && TMWSEO_DEBUG)) {
            return;
        }

        foreach ($additional as $i => $kw) {
            $kw = (string) $kw;
            if ($kw !== '' && self::keyword_contains_name($kw, $name)) {
                Logs::warn('keywords', '[TMW-KEYWORDS] Model additional keyword still contains exact name', [
                    'index' => $i,
                    'keyword' => $kw,
                    'name' => $name,
                ]);
            }
        }
    }
}
