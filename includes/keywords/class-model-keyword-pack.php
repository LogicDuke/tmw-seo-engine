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
        if ($is_model_page && !empty($classified_fragment['primary_candidates'])) {
            $primary = self::select_model_primary_keyword((array) $classified_fragment['primary_candidates'], $model_name, $primary);
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

        // Dedicated Rank Math extras for model pages use approved linked personal
        // keywords first, then deterministic slices of the approved model pool that
        // match this model's saved platforms and assigned tags, with safe formulas
        // only filling any remaining slots.
        $rankmath_chips = [];
        $rankmath_rotation = self::empty_rankmath_rotation_result();
        if ($is_model_page) {
            $rankmath_rotation = self::select_rotating_rankmath_extras(
                (int) $post->ID,
                $model_name,
                (array) ($classified_fragment['extra_focus_candidates'] ?? []),
                $platform_slugs,
                self::rankmath_attribute_slugs_for_post($post),
                $classified_exclusions
            );
            $rankmath_chips = $rankmath_rotation['final'];
        }

        if ($is_model_page && defined('TMWSEO_DEBUG') && TMWSEO_DEBUG) {
            $rankmath_focus_list = array_values(array_filter(array_merge([ $model_name !== '' ? $model_name : $primary ], $rankmath_chips), 'strlen'));
            Logs::info('keywords', '[TMW-SEO-RM-KW-ROTATE] ModelKeywordPack::build selected rotating model Rank Math keyword pack', [
                'post_id' => (int) $post->ID,
                'model_name' => $model_name,
                'approved_personal_extras_used' => $rankmath_rotation['personal_used'] ?? [],
                'platform_extras_considered' => $rankmath_rotation['platform_considered'] ?? [],
                'platform_extras_used' => $rankmath_rotation['platform_used'] ?? [],
                'tag_attribute_extras_considered' => $rankmath_rotation['tag_attribute_considered'] ?? [],
                'tag_attribute_extras_used' => $rankmath_rotation['tag_attribute_used'] ?? [],
                'generic_pool_extras_considered' => $rankmath_rotation['generic_considered'] ?? [],
                'generic_pool_extras_used' => $rankmath_rotation['generic_used'] ?? [],
                'fallback_extras_used' => $rankmath_rotation['fallback_used'] ?? [],
                'final_rank_math_csv' => implode(',', array_slice($rankmath_focus_list, 0, 5)),
                'stored_pack_bypassed_or_rebuilt' => true,
            ]);
        }

        return [
            'primary'             => $primary,
            'additional'          => $additional,
            'longtail'            => $longtail,
            'rankmath_additional' => $rankmath_chips,
            'rankmath_approved_linked_extras' => $is_model_page ? ($rankmath_rotation['personal_used'] ?? []) : [],
            'rankmath_fallback_candidates' => $is_model_page ? ($rankmath_rotation['fallback_used'] ?? []) : [],
            'rankmath_rotation' => $is_model_page ? $rankmath_rotation : [],
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

    /** @param string[] $candidates */
    private static function select_model_primary_keyword(array $candidates, string $model_name, string $fallback): string {
        $model = self::normalize_keyword($model_name);
        $model_lc = function_exists('mb_strtolower') ? mb_strtolower($model, 'UTF-8') : strtolower($model);
        foreach ($candidates as $candidate) {
            $clean = self::normalize_keyword((string) $candidate);
            $clean_lc = function_exists('mb_strtolower') ? mb_strtolower($clean, 'UTF-8') : strtolower($clean);
            if ($model_lc !== '' && $clean_lc === $model_lc) {
                return $clean;
            }
        }
        return $fallback;
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


    /** @param string[] $keywords @param array<string,bool> $excluded @return string[] */
    private static function finalize_rankmath_additional_keywords(array $keywords, array $excluded, string $primary): array {
        $keywords = self::filter_keywords_against_classified_exclusions($keywords, $excluded);
        $keywords = self::remove_primary_keyword_from_extras($keywords, $primary);
        $keywords = PageTypeKeywordFilter::filter_for_model_page($keywords);

        $final = [];
        $seen = [];
        foreach ($keywords as $keyword) {
            $clean = self::normalize_keyword((string) $keyword);
            if ($clean === '' || self::is_broad_standalone_rankmath_extra($clean)) {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($clean, 'UTF-8') : strtolower($clean);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $final[] = $clean;
            if (count($final) >= 4) {
                break;
            }
        }

        return $final;
    }

    private static function is_broad_standalone_rankmath_extra(string $keyword): bool {
        $normalized = self::normalize_keyword($keyword);
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower($normalized);
        return in_array($normalized, [
            'webcam',
            'cam',
            'bio',
            'profile',
            'gallery',
            'jasmin',
            'live cam',
            'live webcam',
            'online cam',
            'adult webcam',
            'cam show',
        ], true);
    }

    /** @param string[] $keywords @return string[] */
    private static function order_model_rankmath_candidates(array $keywords, string $model_name): array {
        $model = self::normalize_keyword($model_name);
        $model_lc = function_exists('mb_strtolower') ? mb_strtolower($model, 'UTF-8') : strtolower($model);
        if ($model_lc === '') {
            return self::dedupe_keywords($keywords);
        }

        $ordered = [];
        $seen = [];
        foreach ($keywords as $keyword) {
            $clean = self::normalize_keyword((string) $keyword);
            if ($clean === '') {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($clean, 'UTF-8') : strtolower($clean);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $ordered[] = $clean;
        }

        return $ordered;
    }

    /** @param string[] $keywords @return string[] */
    private static function remove_primary_keyword_from_extras(array $keywords, string $primary): array {
        $primary = self::normalize_keyword($primary);
        $primary_lc = function_exists('mb_strtolower') ? mb_strtolower($primary, 'UTF-8') : strtolower($primary);
        $out = [];
        foreach ($keywords as $keyword) {
            $clean = self::normalize_keyword((string) $keyword);
            if ($clean === '') {
                continue;
            }
            $clean_lc = function_exists('mb_strtolower') ? mb_strtolower($clean, 'UTF-8') : strtolower($clean);
            if ($primary_lc !== '' && $clean_lc === $primary_lc) {
                continue;
            }
            $out[] = $clean;
        }
        return self::dedupe_keywords($out);
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
    private static function rankmath_attribute_slugs_for_post(\WP_Post $post): array {
        $out = [];
        $taxes = get_object_taxonomies($post->post_type);
        foreach ((array) $taxes as $tax) {
            $terms = get_the_terms($post, $tax);
            if (!is_array($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                if (!($term instanceof \WP_Term)) {
                    continue;
                }
                foreach ([ (string) $term->slug, (string) $term->name ] as $raw) {
                    $slug = sanitize_key($raw);
                    if ($slug === '' || in_array($slug, [ 'uncategorized' ], true)) {
                        continue;
                    }
                    if (preg_match('/\b(teen|underage|school)\b/i', $slug)) {
                        continue;
                    }
                    $out[] = $slug;
                }
            }
        }
        return array_values(array_unique($out));
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
     * Select up to four Rank Math extras for a model page from approved sources.
     *
     * Priority order:
     * 1. approved linked personal model keywords,
     * 2. approved platform pool keywords matching saved model platforms,
     * 3. approved tag/attribute pool keywords matching assigned tags,
     * 4. approved generic model-intent keywords with deterministic rotation,
     * 5. safe deterministic model-name formulas.
     *
     * @param string[]          $personal_keywords
     * @param string[]          $platform_slugs
     * @param string[]          $tag_slugs
     * @param array<string,bool> $classified_exclusions
     * @return array{final:string[],personal_used:string[],platform_considered:string[],platform_used:string[],tag_attribute_considered:string[],tag_attribute_used:string[],generic_considered:string[],generic_used:string[],fallback_used:string[]}
     */
    private static function select_rotating_rankmath_extras(int $post_id, string $model_name, array $personal_keywords, array $platform_slugs, array $tag_slugs, array $classified_exclusions): array {
        $result = self::empty_rankmath_rotation_result();
        $seed = $post_id . '|' . self::normalize_keyword($model_name) . '|' . implode(',', $platform_slugs) . '|' . implode(',', $tag_slugs);

        $append = function(array $keywords, string $bucket) use (&$result, $classified_exclusions, $model_name): void {
            foreach ($keywords as $keyword) {
                if (count($result['final']) >= 4) {
                    break;
                }
                $clean = self::normalize_keyword((string) $keyword);
                if (!self::is_safe_rankmath_extra($clean, $classified_exclusions, $model_name)) {
                    continue;
                }
                $key = self::keyword_key($clean);
                if (isset($result['_seen'][$key])) {
                    continue;
                }
                $result['_seen'][$key] = true;
                $result['final'][] = $clean;
                $result[$bucket][] = $clean;
            }
        };

        $personal = self::order_model_rankmath_candidates($personal_keywords, $model_name);
        $append($personal, 'personal_used');

        $pool_rows = self::approved_model_pool_rows();
        $platform_candidates = [];
        $tag_candidates = [];
        $generic_candidates = [];
        foreach ($pool_rows as $row) {
            $keyword = self::normalize_keyword((string) ($row['keyword'] ?? ''));
            if (!self::is_safe_rankmath_extra($keyword, $classified_exclusions, $model_name, false)) {
                continue;
            }
            $sources = is_array($row['sources'] ?? null) ? $row['sources'] : self::decode_json_field($row['sources'] ?? null);
            if (trim((string) self::source_value($sources, 'model_keyword_owner')) !== '') {
                continue;
            }
            $class = (string) self::source_value($sources, 'keyword_class');
            $usage = (string) self::source_value($sources, 'suggested_usage');
            if ($class === '' || $usage === '') {
                $classification = (new ModelKeywordPoolClassifier())->classify($keyword);
                $class = $class !== '' ? $class : (string) ($classification['keyword_class'] ?? '');
                $usage = $usage !== '' ? $usage : (string) ($classification['suggested_usage'] ?? '');
            }
            $score = self::approved_pool_row_score($row, $sources);

            if (self::keyword_matches_model_platform($keyword, $platform_slugs, $class)) {
                $platform_candidates[$keyword] = max((int) ($platform_candidates[$keyword] ?? 0), $score);
                continue;
            }
            if (self::keyword_matches_model_attribute($keyword, $tag_slugs, $class)) {
                $tag_candidates[$keyword] = max((int) ($tag_candidates[$keyword] ?? 0), $score);
                continue;
            }
            if (self::is_generic_model_intent_pool_keyword($keyword, $class, $usage)) {
                $generic_candidates[$keyword] = max((int) ($generic_candidates[$keyword] ?? 0), $score);
            }
        }

        $result['platform_considered'] = array_keys($platform_candidates);
        $result['tag_attribute_considered'] = array_keys($tag_candidates);
        $result['generic_considered'] = array_keys($generic_candidates);

        $append(self::rotate_scored_keywords($platform_candidates, 4, $seed . '|platform'), 'platform_used');
        $append(self::rotate_scored_keywords($tag_candidates, 4, $seed . '|attribute'), 'tag_attribute_used');
        $append(self::rotate_scored_keywords($generic_candidates, 4, $seed . '|generic'), 'generic_used');
        $append(self::safe_rankmath_fallback_formulas($model_name), 'fallback_used');

        unset($result['_seen']);
        return $result;
    }

    /** @return array{final:string[],personal_used:string[],platform_considered:string[],platform_used:string[],tag_attribute_considered:string[],tag_attribute_used:string[],generic_considered:string[],generic_used:string[],fallback_used:string[],_seen:array<string,bool>} */
    private static function empty_rankmath_rotation_result(): array {
        return [
            'final' => [],
            'personal_used' => [],
            'platform_considered' => [],
            'platform_used' => [],
            'tag_attribute_considered' => [],
            'tag_attribute_used' => [],
            'generic_considered' => [],
            'generic_used' => [],
            'fallback_used' => [],
            '_seen' => [],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function approved_model_pool_rows(): array {
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->prefix) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'prepare')) {
            return [];
        }
        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        if (method_exists($wpdb, 'get_var') && method_exists($wpdb, 'esc_like')) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if (!is_string($found) || strtolower($found) !== strtolower($table)) {
                return [];
            }
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, keyword, intent_type, entity_type, entity_id, status, volume, opportunity, trend_score, sources FROM ' . $table . ' WHERE intent_type = %s AND status = %s AND entity_id = %d ORDER BY id ASC',
                'model',
                'approved',
                0
            ),
            defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'
        );
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $sources */
    private static function approved_pool_row_score(array $row, array $sources): int {
        $score = 1000;
        $tmw_score = self::source_value($sources, 'tmw_score');
        if (is_numeric($tmw_score)) {
            $score += (int) $tmw_score;
        }
        if (is_numeric($row['opportunity'] ?? null)) {
            $score += (int) round(((float) $row['opportunity']) * 10);
        }
        if (is_numeric($row['volume'] ?? null)) {
            $score += min(250, (int) round(((float) $row['volume']) / 10));
        }
        if (is_numeric($row['trend_score'] ?? null)) {
            $score += (int) round((float) $row['trend_score']);
        }
        return $score;
    }

    /** @param array<string,int> $keywords @return string[] */
    private static function rotate_scored_keywords(array $keywords, int $limit, string $seed): array {
        $items = [];
        foreach ($keywords as $keyword => $score) {
            $clean = self::normalize_keyword((string) $keyword);
            if ($clean === '') {
                continue;
            }
            $items[] = [
                'keyword' => $clean,
                'score' => (int) $score,
                'slot' => sprintf('%u', crc32($seed . '|' . self::keyword_key($clean))),
            ];
        }
        usort($items, function($a, $b) {
            $sa = (int) ($a['score'] ?? 0);
            $sb = (int) ($b['score'] ?? 0);
            if ($sa !== $sb) {
                return ($sa > $sb) ? -1 : 1;
            }
            return strcmp((string) ($a['slot'] ?? ''), (string) ($b['slot'] ?? ''));
        });
        $top = array_slice($items, 0, max($limit * 3, $limit));
        usort($top, function($a, $b) {
            return strcmp((string) ($a['slot'] ?? ''), (string) ($b['slot'] ?? ''));
        });
        return array_values(array_map(static function($item) { return (string) ($item['keyword'] ?? ''); }, array_slice($top, 0, $limit)));
    }

    /** @return string[] */
    private static function safe_rankmath_fallback_formulas(string $name): array {
        $clean_name = self::normalize_keyword($name);
        if ($clean_name === '') {
            return [];
        }
        $name_lc = function_exists('mb_strtolower') ? mb_strtolower($clean_name, 'UTF-8') : strtolower($clean_name);
        return self::dedupe_keywords([
            $name_lc . ' livejasmin',
            $name_lc . ' cam',
            $name_lc . ' webcam',
            $name_lc . ' live cam',
        ]);
    }

    /** @param array<string,bool> $excluded */
    private static function is_safe_rankmath_extra(string $keyword, array $excluded, string $primary, bool $allow_name_match = true): bool {
        $clean = self::normalize_keyword($keyword);
        if ($clean === '') {
            return false;
        }
        $key = self::keyword_key($clean);
        if (isset($excluded[$key])) {
            return false;
        }
        if (self::keyword_key($primary) !== '' && $key === self::keyword_key($primary)) {
            return false;
        }
        if (self::is_broad_standalone_rankmath_extra($clean) || PageTypeKeywordFilter::is_unsafe($clean)) {
            return false;
        }
        return PageTypeKeywordFilter::filter_for_model_page([ $clean ]) !== [];
    }

    private static function keyword_matches_model_platform(string $keyword, array $platform_slugs, string $keyword_class): bool {
        if (empty($platform_slugs)) {
            return false;
        }
        $keyword_lc = self::keyword_key($keyword);
        foreach ($platform_slugs as $platform) {
            $slug = sanitize_key((string) $platform);
            if ($slug === '') {
                continue;
            }
            $needles = array_filter(array_unique([
                str_replace('-', ' ', $slug),
                self::platform_keyword_label($slug),
                $slug === 'bonga' ? 'bongacams' : '',
            ]));
            foreach ($needles as $needle) {
                $needle_lc = self::keyword_key((string) $needle);
                if ($needle_lc !== '' && preg_match('/(?:^|\\s)' . preg_quote($needle_lc, '/') . '(?:\\s|$)/u', $keyword_lc) === 1) {
                    return true;
                }
            }
        }
        return in_array($keyword_class, [ ModelKeywordPoolClassifier::CLASS_PLATFORM_TERM, ModelKeywordPoolClassifier::CLASS_PLATFORM_INTENT_TERM ], true)
            && self::keyword_mentions_any_platform($keyword, $platform_slugs);
    }

    private static function keyword_mentions_any_platform(string $keyword, array $platform_slugs): bool {
        foreach ($platform_slugs as $platform) {
            if (self::keyword_matches_model_platform($keyword, [ (string) $platform ], '')) {
                return true;
            }
        }
        return false;
    }

    private static function keyword_matches_model_attribute(string $keyword, array $tag_slugs, string $keyword_class): bool {
        if (empty($tag_slugs)) {
            return false;
        }
        $keyword_lc = self::keyword_key($keyword);
        foreach ($tag_slugs as $tag) {
            $tag_phrase = self::keyword_key(str_replace('-', ' ', (string) $tag));
            if ($tag_phrase !== '' && preg_match('/(?:^|\s)' . preg_quote($tag_phrase, '/') . '(?:\s|$)/u', $keyword_lc) === 1) {
                return true;
            }
        }
        return false && in_array($keyword_class, [ ModelKeywordPoolClassifier::CLASS_ATTRIBUTE_TERM, ModelKeywordPoolClassifier::CLASS_GEO_LANGUAGE_TERM, ModelKeywordPoolClassifier::CLASS_FEATURE_MODIFIER ], true);
    }

    private static function is_generic_model_intent_pool_keyword(string $keyword, string $keyword_class, string $suggested_usage): bool {
        if ($keyword === '' || self::keyword_mentions_any_platform($keyword, [ 'livejasmin', 'jasmin', 'stripchat', 'chaturbate', 'camsoda', 'cam4', 'bonga', 'bongacams', 'myfreecams', 'flirt4free', 'imlive', 'jerkmate' ])) {
            return false;
        }
        if (in_array($keyword_class, [ ModelKeywordPoolClassifier::CLASS_CORE_MODEL_TERM, ModelKeywordPoolClassifier::CLASS_INTENT_TERM, ModelKeywordPoolClassifier::CLASS_SUPPORTING_MODEL_TERM ], true)) {
            return true;
        }
        return in_array($suggested_usage, [ ModelKeywordPoolClassifier::USAGE_SECONDARY_FOCUS_ALLOWED, ModelKeywordPoolClassifier::USAGE_BODY_SEMANTIC_ONLY ], true)
            && preg_match('/(?:^|\s)model(?:\s|$)/u', self::keyword_key($keyword)) === 1;
    }

    private static function keyword_key(string $keyword): string {
        $clean = self::normalize_keyword($keyword);
        return function_exists('mb_strtolower') ? mb_strtolower($clean, 'UTF-8') : strtolower($clean);
    }

    /** @return array<string,mixed> */
    private static function decode_json_field($value): array {
        if (is_array($value)) {
            return $value;
        }
        if (null === $value || trim((string) $value) === '') {
            return [];
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $sources */
    private static function source_value(array $sources, string $key) {
        if (array_key_exists($key, $sources) && is_scalar($sources[$key])) {
            return $sources[$key];
        }
        foreach ($sources as $value) {
            if (!is_array($value)) {
                continue;
            }
            $found = self::source_value($value, $key);
            if ($found !== null && $found !== '') {
                return $found;
            }
        }
        return null;
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
