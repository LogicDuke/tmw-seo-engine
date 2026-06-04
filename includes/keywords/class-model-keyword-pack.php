<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Platform\PlatformProfiles;
use TMWSEO\Engine\Model\VerifiedLinks;
use TMWSEO\Engine\Admin\ModelHelper;

if (!defined('ABSPATH')) { exit; }

class ModelKeywordPack {

    private const RANKMATH_CAM_PLATFORM_ALLOWLIST = [
        'livejasmin' => 'livejasmin',
        'jasmin' => 'livejasmin',
        'stripchat' => 'stripchat',
        'chaturbate' => 'chaturbate',
        'streamate' => 'streamate',
        'bonga' => 'bongacams',
        'bongacams' => 'bongacams',
        'camsoda' => 'camsoda',
        'cam4' => 'cam4',
        'myfreecams' => 'myfreecams',
        'flirt4free' => 'flirt4free',
        'imlive' => 'imlive',
        'jerkmate' => 'jerkmate',
    ];

    private const RANKMATH_SEO_ACTIVITY_LEVELS = [ 'active', 'very_active' ];

    /**
     * Body-type, ethnicity, and demographic terms that must NEVER appear as suffixes
     * in model Rank Math keyword chips, regardless of taxonomy tags or pool-row source.
     *
     * These terms are global category descriptors, not profile-specific verified facts.
     * A model having a taxonomy tag 'bbw' does not mean the chip "{Name} bbw cam model"
     * is safe or accurate for that individual model's Rank Math focus keyword set.
     *
     * Platform terms are handled separately via RANKMATH_CAM_PLATFORM_ALLOWLIST and
     * verified_cam_platform_records(), so they are not repeated here.
     *
     * @var string[]
     */
    private const RANKMATH_CHIP_SUFFIX_DENYLIST = [
        // Body type
        'bbw',
        'big boobs',
        'big boob',
        'busty',
        'curvy',
        'chubby',
        'slim',
        'petite',
        'thick',
        'big ass',
        'big butt',
        'big tits',
        // Ethnicity / demographic
        'ebony',
        'asian',
        'latina',
        'latin',
        'milf',
        'mature',
        'teen',
        'teens',
        'blonde',
        'brunette',
        'redhead',
        'ginger',
        'white',
        'black',
        'mixed',
        'arab',
        // Unverified / stale platforms (not in ALLOWLIST, but may appear in old rows)
        'streamate',
        'streammate',
        'liveprivates',
        'live privates',
        'sexier',
        'ifriends',
    ];

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
        // keywords first, then model-name combinations for verified saved
        // platforms and assigned tags, with safe model-name formulas only
        // filling any remaining slots.
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

        if ($is_model_page) {
            $rankmath_focus_list = array_values(array_filter(array_merge([ $model_name !== '' ? $model_name : $primary ], $rankmath_chips), 'strlen'));
            Logs::info('keywords', '[TMW-SEO-RM-KW-ROTATE-FIX] ModelKeywordPack::build selected model-specific Rank Math keyword pack', [
                'post_id' => (int) $post->ID,
                'model_name' => $model_name,
                'approved_personal_extras_used' => $rankmath_rotation['personal_used'] ?? [],
                'skipped_raw_pool_keywords' => $rankmath_rotation['skipped_raw_pool_keywords'] ?? [],
                'verified_platforms_used' => $rankmath_rotation['verified_platforms_used'] ?? [],
                'verified_tags_used' => $rankmath_rotation['verified_tags_used'] ?? [],
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
        if ( in_array($normalized, [
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
        ], true) ) {
            return true;
        }
        // Also block bare denylist suffix terms used as standalone chips.
        return self::chip_suffix_contains_denylist_term( $normalized );
    }

    /**
     * Returns true if any token in $text exactly matches a denylist term.
     *
     * Used to block body-type/ethnicity/demographic terms from appearing as
     * (part of) a Rank Math keyword chip suffix, regardless of input source.
     */
    private static function chip_suffix_contains_denylist_term( string $text ): bool {
        $text_lc = function_exists('mb_strtolower') ? mb_strtolower( self::normalize_keyword($text), 'UTF-8' ) : strtolower( self::normalize_keyword($text) );
        if ( $text_lc === '' ) {
            return false;
        }
        foreach ( self::RANKMATH_CHIP_SUFFIX_DENYLIST as $term ) {
            $term_lc = function_exists('mb_strtolower') ? mb_strtolower( $term, 'UTF-8' ) : strtolower( $term );
            // Match as whole word(s) anywhere in the text.
            if ( preg_match( '/(?:^|\s)' . preg_quote( $term_lc, '/' ) . '(?:\s|$)/u', $text_lc ) === 1 ) {
                return true;
            }
        }
        return false;
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
        $verified = self::verified_cam_platform_records($model_id);
        if (!empty($verified)) {
            return self::dedupe_platform_slugs(array_map(static function(array $record): string {
                return (string) ($record['platform'] ?? '');
            }, $verified));
        }

        return self::legacy_rankmath_cam_platform_slugs($model_id);
    }

    /** @return string[] */
    private static function dedupe_platform_slugs(array $slugs): array {
        $out = [];
        foreach ($slugs as $slug) {
            $slug = sanitize_key((string) $slug);
            if ($slug !== '' && isset(self::RANKMATH_CAM_PLATFORM_ALLOWLIST[$slug])) {
                $out[] = self::RANKMATH_CAM_PLATFORM_ALLOWLIST[$slug];
            }
        }
        return array_values(array_unique(array_filter($out, 'strlen')));
    }

    /** @return array<int,array<string,mixed>> */
    private static function verified_cam_platform_records(int $model_id): array {
        if (!class_exists(VerifiedLinks::class)) {
            return [];
        }

        $records = [];
        foreach (VerifiedLinks::get_links($model_id) as $link) {
            if (!is_array($link)) {
                continue;
            }
            $type = sanitize_key((string) ($link['type'] ?? ''));
            if (!isset(self::RANKMATH_CAM_PLATFORM_ALLOWLIST[$type])) {
                continue;
            }
            $platform = self::RANKMATH_CAM_PLATFORM_ALLOWLIST[$type];
            $is_active = self::verified_link_active_checkbox_value($link);
            $activity = self::normalize_verified_link_activity($link['activity_level'] ?? '');
            if (!self::verified_link_is_active_enough_for_rankmath($link)) {
                continue;
            }
            $records[] = [
                'platform' => $platform,
                'raw_platform_type' => $type,
                'raw_activity_level' => (string) ($link['activity_level'] ?? ''),
                'activity_level' => $activity,
                'is_active' => $is_active,
                'eligible_for_rankmath' => true,
                'profile_slug' => self::extract_verified_link_profile_slug((string) ($link['url'] ?? ''), $platform),
                'url_log' => self::safe_debug_url((string) ($link['url'] ?? '')),
            ];
        }

        $seen = [];
        $out = [];
        foreach ($records as $record) {
            $platform = (string) ($record['platform'] ?? '');
            if ($platform === '' || isset($seen[$platform])) {
                continue;
            }
            $seen[$platform] = true;
            $out[] = $record;
        }
        return $out;
    }

    /**
     * Rank Math platform extras use activity_level as the status source of
     * truth. Legacy is_active values are retained for diagnostics only and must
     * not make unknown/inactive rows eligible or block active/very_active rows.
     *
     * @param array<string,mixed> $link
     */
    private static function verified_link_is_active_enough_for_rankmath(array $link): bool {
        $activity = self::normalize_verified_link_activity($link['activity_level'] ?? '');
        return in_array($activity, self::RANKMATH_SEO_ACTIVITY_LEVELS, true);
    }

    private static function verified_link_active_checkbox_value(array $link): bool {
        if (!array_key_exists('is_active', $link)) {
            return true;
        }
        $raw = $link['is_active'];
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_numeric($raw)) {
            return (int) $raw === 1;
        }
        $value = strtolower(trim((string) $raw));
        if ($value === '') {
            return false;
        }
        if (in_array($value, [ '0', 'false', 'no', 'off', 'inactive' ], true)) {
            return false;
        }
        return true;
    }

    private static function normalize_verified_link_activity($value): string {
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return 'unknown';
        }

        $normalized = str_replace([ '-', ' ' ], '_', $raw);
        $normalized = preg_replace('/[^a-z0-9_]+/i', '_', (string) $normalized);
        $normalized = trim((string) $normalized, '_');
        if (in_array($normalized, [ 'unknown', 'inactive', 'active', 'very_active' ], true)) {
            return $normalized;
        }
        return 'unknown';
    }

    /** @return string[] */
    private static function legacy_rankmath_cam_platform_slugs(int $model_id): array {
        $slugs = [];
        if (class_exists(PlatformProfiles::class)) {
            $rows = PlatformProfiles::get_links($model_id);
            foreach ($rows as $r) {
                $p = isset($r['platform']) ? sanitize_key((string)$r['platform']) : '';
                if ($p !== '') $slugs[] = $p;
            }
        }
        // If primary is set but there is no row yet, keep it as cam-only fallback evidence.
        $primary = sanitize_key((string)get_post_meta($model_id, '_tmwseo_platform_primary', true));
        if ($primary !== '') $slugs[] = $primary;
        return self::dedupe_platform_slugs($slugs);
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
     * 2. verified saved platform keywords transformed to model-name combinations,
     * 3. verified assigned tag/attribute keywords transformed to model-name combinations,
     * 4. safe deterministic model-name formulas.
     *
     * Approved pool rows are never inserted raw for model pages; generic pool
     * rows are logged and skipped until they can be used outside Rank Math extras.
     *
     * @param string[]          $personal_keywords
     * @param string[]          $platform_slugs
     * @param string[]          $tag_slugs
     * @param array<string,bool> $classified_exclusions
     * @return array{final:string[],personal_used:string[],platform_considered:string[],platform_used:string[],tag_attribute_considered:string[],tag_attribute_used:string[],generic_considered:string[],generic_used:string[],fallback_used:string[],skipped_raw_pool_keywords:string[],verified_platforms_used:string[],verified_tags_used:string[]}
     */
    private static function select_rotating_rankmath_extras(int $post_id, string $model_name, array $personal_keywords, array $platform_slugs, array $tag_slugs, array $classified_exclusions): array {
        $result = self::empty_rankmath_rotation_result();
        $seed = $post_id . '|' . self::normalize_keyword($model_name) . '|' . implode(',', $platform_slugs) . '|' . implode(',', $tag_slugs);
        $model_prefix = self::normalize_keyword($model_name);

        $append = function(array $keywords, string $bucket) use (&$result, $classified_exclusions, $model_name, $platform_slugs, $post_id): void {
            foreach ($keywords as $keyword) {
                if (count($result['final']) >= 4) {
                    break;
                }
                $clean = self::normalize_model_rankmath_extra((string) $keyword, $model_name, $platform_slugs);
                if ($clean === '') {
                    continue;
                }
                if (self::is_unsafe_model_seo_phrase($clean)) {
                    self::debug_model_seo_copy_guard($post_id, $model_name, $clean, 'instructional_model_seo_phrase', $bucket, $model_name, $result['final']);
                    continue;
                }
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

        $platform_candidates = [];
        $tag_candidates = [];
        $generic_candidates = [];

        $platform_keyword_plan = self::verified_rankmath_platform_keyword_plan($post_id, $model_name, $platform_slugs);
        foreach ((array) ($platform_keyword_plan['alias_keywords'] ?? []) as $candidate) {
            $candidate = self::normalize_keyword((string) $candidate);
            if ($candidate !== '') {
                $platform_candidates[$candidate] = max((int) ($platform_candidates[$candidate] ?? 0), 950);
                $result['platform_considered'][] = $candidate;
            }
        }
        foreach ((array) ($platform_keyword_plan['main_keywords'] ?? []) as $candidate) {
            $candidate = self::normalize_keyword((string) $candidate);
            if ($candidate !== '') {
                $platform_candidates[$candidate] = max((int) ($platform_candidates[$candidate] ?? 0), 900);
                $result['platform_considered'][] = $candidate;
            }
        }

        $pool_rows = self::approved_model_pool_rows();
        foreach ($pool_rows as $row) {
            $raw_keyword = self::normalize_keyword((string) ($row['keyword'] ?? ''));
            if ($raw_keyword === '') {
                continue;
            }
            $result['skipped_raw_pool_keywords'][] = $raw_keyword;

            $sources = is_array($row['sources'] ?? null) ? $row['sources'] : self::decode_json_field($row['sources'] ?? null);
            if (trim((string) self::source_value($sources, 'model_keyword_owner')) !== '') {
                continue;
            }
            $class = (string) self::source_value($sources, 'keyword_class');
            $usage = (string) self::source_value($sources, 'suggested_usage');
            if ($class === '' || $usage === '') {
                $classification = (new ModelKeywordPoolClassifier())->classify($raw_keyword);
                $class = $class !== '' ? $class : (string) ($classification['keyword_class'] ?? '');
                $usage = $usage !== '' ? $usage : (string) ($classification['suggested_usage'] ?? '');
            }
            $score = self::approved_pool_row_score($row, $sources);

            $platform_keyword = self::verified_platform_keyword_from_pool_keyword($raw_keyword, $platform_slugs, $class);
            if ($platform_keyword !== '') {
                $candidate = self::model_name_phrase($model_prefix, $platform_keyword);
                if ($candidate !== '') {
                    $platform_candidates[$candidate] = max((int) ($platform_candidates[$candidate] ?? 0), $score);
                    $result['platform_considered'][] = $candidate;
                }
                continue;
            }

            $attribute_phrase = self::verified_attribute_phrase_from_pool_keyword($raw_keyword, $tag_slugs, $class);
            if ($attribute_phrase !== '') {
                $candidate = self::model_name_phrase($model_prefix, $attribute_phrase . ' model');
                if ($candidate !== '') {
                    $tag_candidates[$candidate] = max((int) ($tag_candidates[$candidate] ?? 0), $score);
                    $result['tag_attribute_considered'][] = $candidate;
                }
                continue;
            }

            if (self::is_generic_model_intent_pool_keyword($raw_keyword, $class, $usage)) {
                $candidate = self::model_name_phrase($model_prefix, $raw_keyword);
                if ($candidate !== '') {
                    $generic_candidates[$candidate] = max((int) ($generic_candidates[$candidate] ?? 0), $score);
                }
            }
        }

        $result['platform_considered'] = self::dedupe_keywords($result['platform_considered']);
        $result['tag_attribute_considered'] = self::dedupe_keywords($result['tag_attribute_considered']);
        $result['generic_considered'] = array_keys($generic_candidates);
        $result['skipped_raw_pool_keywords'] = self::dedupe_keywords($result['skipped_raw_pool_keywords']);

        $append(self::rankmath_ordered_platform_candidates($platform_keyword_plan, $platform_candidates, $seed), 'platform_used');
        $append(self::rotate_scored_keywords($tag_candidates, 4, $seed . '|attribute'), 'tag_attribute_used');
        $append(self::rotate_scored_keywords($generic_candidates, 4, $seed . '|generic-intent'), 'generic_used');
        $append(self::safe_rankmath_fallback_formulas($model_name, $platform_slugs), 'fallback_used');

        $result['verified_platforms_used'] = self::verified_tokens_used_by_keywords($result['platform_used'], self::verified_rankmath_platform_keywords($platform_slugs));
        $result['verified_tags_used'] = self::verified_tokens_used_by_keywords($result['tag_attribute_used'], self::verified_rankmath_attribute_phrases($tag_slugs));
        self::debug_rankmath_platform_keywords($post_id, $model_name, $platform_keyword_plan, $result);
        self::debug_model_seo_copy_guard($post_id, $model_name, '', 'final_selection', 'rankmath extras', $model_name, $result['final']);

        unset($result['_seen']);
        return $result;
    }

    /** @return array{final:string[],personal_used:string[],platform_considered:string[],platform_used:string[],tag_attribute_considered:string[],tag_attribute_used:string[],generic_considered:string[],generic_used:string[],fallback_used:string[],skipped_raw_pool_keywords:string[],verified_platforms_used:string[],verified_tags_used:string[],_seen:array<string,bool>} */
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
            'skipped_raw_pool_keywords' => [],
            'verified_platforms_used' => [],
            'verified_tags_used' => [],
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

    /** @param string[] $platform_slugs @return string[] */
    private static function safe_rankmath_fallback_formulas(string $name, array $platform_slugs): array {
        $clean_name = self::normalize_keyword($name);
        if ($clean_name === '') {
            return [];
        }
        $fallbacks = [];
        foreach ($platform_slugs as $platform) {
            $platform_label = self::platform_keyword_label((string) $platform);
            if ($platform_label !== '') {
                $fallbacks[] = self::model_name_phrase($clean_name, $platform_label);
            }
        }
        $fallbacks[] = $clean_name . ' live cam';
        $fallbacks[] = $clean_name . ' live webcam';
        $fallbacks[] = $clean_name . ' private live chat';
        $fallbacks[] = $clean_name . ' cam model';
        $fallbacks[] = $clean_name . ' HD live stream';
        $fallbacks[] = $clean_name . ' live chat';
        $fallbacks[] = $clean_name . ' webcam chat';
        return self::dedupe_keywords($fallbacks);
    }

    /** @param string[] $platform_slugs */
    private static function normalize_model_rankmath_extra(string $keyword, string $model_name, array $platform_slugs): string {
        $clean = self::normalize_keyword($keyword);
        $model = self::normalize_keyword($model_name);
        if ($clean === '' || $model === '') {
            return '';
        }

        // Reject any keyword whose full text contains a denylist term.
        // This catches stale personal rows like "abby murray bbw cam model"
        // before suffix extraction, so they never reach model_name_phrase().
        if ( self::chip_suffix_contains_denylist_term( $clean ) ) {
            return '';
        }

        $eligible_platforms = self::dedupe_platform_slugs($platform_slugs);
        $mentioned_platforms = self::rankmath_platforms_mentioned_by_keyword($clean);
        if (!empty($mentioned_platforms)) {
            foreach ($mentioned_platforms as $platform) {
                if (!in_array($platform, $eligible_platforms, true)) {
                    return '';
                }
            }
            $platform = $mentioned_platforms[0] ?? '';
            return $platform !== '' ? self::model_name_phrase($model, $platform) : '';
        }

        $suffix = self::model_keyword_suffix($clean, $model);
        if ($suffix === '') {
            return '';
        }
        // Block trivially generic single-word suffixes.
        if (in_array(self::keyword_key($suffix), [ 'live', 'online', 'show', 'chat' ], true)) {
            return '';
        }
        // Block suffixes containing any denylist term (body-type / ethnicity / stale platform).
        if ( self::chip_suffix_contains_denylist_term( $suffix ) ) {
            return '';
        }
        return self::model_name_phrase($model, $suffix);
    }

    private static function model_keyword_suffix(string $keyword, string $model_name): string {
        $key = self::keyword_key($keyword);
        $model_key = self::keyword_key($model_name);
        if ($key === '' || $model_key === '') {
            return '';
        }
        if ($key === $model_key) {
            return '';
        }
        if (preg_match('/^' . preg_quote($model_key, '/') . '\s+(.+)$/u', $key, $matches) === 1) {
            return self::normalize_keyword((string) $matches[1]);
        }
        return '';
    }

    /** @return string[] */
    private static function rankmath_platforms_mentioned_by_keyword(string $keyword): array {
        $key = self::keyword_key($keyword);
        if ($key === '') {
            return [];
        }
        $platforms = [];
        foreach (array_unique(array_values(self::RANKMATH_CAM_PLATFORM_ALLOWLIST)) as $platform) {
            $needles = array_filter(array_unique([
                self::keyword_key($platform),
                self::keyword_key(self::platform_keyword_label($platform)),
                $platform === 'bongacams' ? 'bonga' : '',
            ]));
            foreach ($needles as $needle) {
                if ($needle !== '' && preg_match('/(?:^|\s)' . preg_quote($needle, '/') . '(?:\s|$)/u', $key) === 1) {
                    $platforms[] = $platform;
                    break;
                }
            }
        }
        return array_values(array_unique($platforms));
    }

    /** @param string[] $platform_slugs */
    private static function fallback_platform_keyword(array $platform_slugs): string {
        foreach ($platform_slugs as $platform) {
            $slug = sanitize_key((string) $platform);
            if ($slug === '') {
                continue;
            }
            $map = [
                'livejasmin' => 'livejasmin',
                'jasmin' => 'livejasmin',
                'stripchat' => 'stripchat',
                'chaturbate' => 'chaturbate',
                'streamate' => 'streamate',
                'bonga' => 'bongacams',
                'bongacams' => 'bongacams',
                'camsoda' => 'camsoda',
                'cam4' => 'cam4',
                'myfreecams' => 'myfreecams',
                'flirt4free' => 'flirt4free',
                'imlive' => 'imlive',
                'jerkmate' => 'jerkmate',
            ];
            if (isset($map[$slug])) {
                return $map[$slug];
            }
            $keyword = self::keyword_key(str_replace('-', ' ', $slug));
            if ($keyword !== '') {
                return $keyword;
            }
        }
        return '';
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
        if (self::is_broad_standalone_rankmath_extra($clean) || self::is_unsafe_page_type_keyword($clean) || self::is_unsafe_model_seo_phrase($clean)) {
            return false;
        }
        return PageTypeKeywordFilter::filter_for_model_page([ $clean ]) !== [];
    }

    private static function is_unsafe_page_type_keyword(string $keyword): bool {
        return method_exists(PageTypeKeywordFilter::class, 'is_unsafe') && PageTypeKeywordFilter::is_unsafe($keyword);
    }

    private static function is_unsafe_model_seo_phrase(string $keyword): bool {
        return method_exists(PageTypeKeywordFilter::class, 'is_unsafe_model_seo_phrase') && PageTypeKeywordFilter::is_unsafe_model_seo_phrase($keyword);
    }

    private static function model_name_phrase(string $model_prefix, string $suffix): string {
        $model_prefix = self::normalize_keyword($model_prefix);
        $suffix = self::rankmath_suffix_label($suffix);
        if ($model_prefix === '' || $suffix === '') {
            return '';
        }
        return self::normalize_keyword($model_prefix . ' ' . $suffix);
    }

    private static function rankmath_suffix_label(string $suffix): string {
        $suffix_key = self::keyword_key($suffix);
        if ($suffix_key === '') {
            return '';
        }
        foreach (array_unique(array_values(self::RANKMATH_CAM_PLATFORM_ALLOWLIST)) as $platform) {
            if ($suffix_key === self::keyword_key($platform)) {
                return self::platform_keyword_label($platform);
            }
        }
        $known_phrases = [
            'hd live stream' => 'HD live stream',
            'live cam' => 'live cam',
            'live webcam' => 'live webcam',
            'cam model' => 'cam model',
            'private live chat' => 'private live chat',
            'live chat' => 'live chat',
            'webcam chat' => 'webcam chat',
            'lingerie' => 'lingerie',
        ];
        return $known_phrases[$suffix_key] ?? $suffix_key;
    }

    /**
     * @param string[] $platform_slugs
     * @return array{alias_keywords:string[],main_keywords:string[],saved_aliases:string[],verified_cam_links:array<int,array<string,mixed>>,excluded_verified_link_types:string[],legacy_platform_slugs:string[],source:string}
     */
    private static function verified_rankmath_platform_keyword_plan(int $post_id, string $model_name, array $platform_slugs): array {
        $saved_aliases = self::saved_model_aliases($post_id, $model_name);
        $alias_lookup = [];
        foreach ($saved_aliases as $alias) {
            $norm = self::normalize_alias_for_platform_compare($alias);
            if ($norm !== '') {
                $alias_lookup[$norm] = $alias;
            }
        }

        $verified_records = self::verified_cam_platform_records($post_id);
        $all_cam_link_debug = self::all_verified_cam_platform_debug_records($post_id, $alias_lookup, $model_name);
        $excluded_types = self::excluded_verified_link_types_for_rankmath($post_id);
        $legacy_slugs = self::legacy_rankmath_cam_platform_slugs($post_id);
        $source = !empty($verified_records) ? 'verified_links' : 'legacy_platform_profiles';
        $records = $verified_records;
        if (empty($records)) {
            foreach ($platform_slugs as $slug) {
                $slug = sanitize_key((string) $slug);
                if ($slug !== '' && isset(self::RANKMATH_CAM_PLATFORM_ALLOWLIST[$slug])) {
                    $records[] = [
                        'platform' => self::RANKMATH_CAM_PLATFORM_ALLOWLIST[$slug],
                        'raw_activity_level' => '',
                        'activity_level' => '',
                        'is_active' => null,
                        'eligible_for_rankmath' => true,
                        'profile_slug' => '',
                    ];
                }
            }
        }

        $alias_keywords = [];
        $main_keywords = [];
        $debug_records = [];
        $used_platforms = [];
        foreach ($records as $record) {
            $platform = sanitize_key((string) ($record['platform'] ?? ''));
            if ($platform === '' || !isset(self::RANKMATH_CAM_PLATFORM_ALLOWLIST[$platform]) || isset($used_platforms[$platform])) {
                continue;
            }
            $used_platforms[$platform] = true;
            $profile_slug = (string) ($record['profile_slug'] ?? '');
            $slug_norm = self::normalize_alias_for_platform_compare($profile_slug);
            $matched_alias = ($slug_norm !== '' && isset($alias_lookup[$slug_norm])) ? (string) $alias_lookup[$slug_norm] : '';
            $alias_candidate = $matched_alias !== '' ? self::model_name_phrase($matched_alias, $platform) : '';
            $main_candidate = self::model_name_phrase($model_name, $platform);

            if ($alias_candidate !== '') {
                $alias_keywords[] = $alias_candidate;
            } elseif ($main_candidate !== '') {
                // Use one Rank Math extra per platform. Main-name platform extras
                // fill platforms that do not have a verified saved-alias match.
                $main_keywords[] = $main_candidate;
            }

            $debug_records[] = [
                'platform' => $platform,
                'raw_platform_type' => (string) ($record['raw_platform_type'] ?? $platform),
                'raw_activity_level' => (string) ($record['raw_activity_level'] ?? ''),
                'activity_level' => (string) ($record['activity_level'] ?? ''),
                'is_active' => $record['is_active'] ?? null,
                'eligible_for_rankmath' => $record['eligible_for_rankmath'] ?? true,
                'profile_slug' => $profile_slug,
                'alias_match' => $matched_alias !== '',
                'matched_alias' => $matched_alias,
                'alias_candidate' => $alias_candidate,
                'main_candidate' => $main_candidate,
            ];
        }

        return [
            'alias_keywords' => self::dedupe_keywords($alias_keywords),
            'main_keywords' => self::dedupe_keywords($main_keywords),
            'saved_aliases' => $saved_aliases,
            'verified_cam_links' => !empty($all_cam_link_debug) ? $all_cam_link_debug : $debug_records,
            'excluded_verified_link_types' => $excluded_types,
            'legacy_platform_slugs' => $legacy_slugs,
            'source' => $source,
        ];
    }

    /** @param array<string,int> $platform_candidates @param array<string,mixed> $plan @return string[] */
    private static function rankmath_ordered_platform_candidates(array $plan, array $platform_candidates, string $seed): array {
        $ordered = [];
        $selected_platforms = [];
        $append = function(string $keyword) use (&$ordered, &$selected_platforms, $platform_candidates): void {
            $clean = self::normalize_keyword($keyword);
            if ($clean === '' || !array_key_exists($clean, $platform_candidates)) {
                return;
            }
            $platform = self::rankmath_platform_from_keyword($clean);
            if ($platform !== '' && isset($selected_platforms[$platform])) {
                return;
            }
            if ($platform !== '') {
                $selected_platforms[$platform] = true;
            }
            $ordered[] = $clean;
        };

        foreach (array_merge((array) ($plan['alias_keywords'] ?? []), (array) ($plan['main_keywords'] ?? [])) as $keyword) {
            $append((string) $keyword);
        }
        foreach (self::rotate_scored_keywords($platform_candidates, 4, $seed . '|platform-pool') as $keyword) {
            $append((string) $keyword);
        }
        return self::dedupe_keywords($ordered);
    }

    private static function rankmath_platform_from_keyword(string $keyword): string {
        $key = self::keyword_key($keyword);
        if ($key === '') {
            return '';
        }
        foreach (self::RANKMATH_CAM_PLATFORM_ALLOWLIST as $platform) {
            $platform_key = self::keyword_key($platform);
            if ($platform_key !== '' && preg_match('/(^|\s)' . preg_quote($platform_key, '/') . '(\s|$)/u', $key) === 1) {
                return $platform;
            }
        }
        return '';
    }

    /** @return string[] */
    private static function saved_model_aliases(int $post_id, string $model_name): array {
        $meta_key = class_exists(ModelHelper::class) ? ModelHelper::META_ALIASES : '_tmwseo_research_aliases';
        $raw = get_post_meta($post_id, $meta_key, true);
        $values = is_array($raw) ? $raw : preg_split('/\s*,\s*/u', (string) $raw);
        $aliases = [];
        $model_norm = self::normalize_alias_for_platform_compare($model_name);
        foreach ((array) $values as $alias) {
            $alias = trim((string) $alias, " \t\n\r\0\x0B,;@/\\()[]{}<>\"'");
            if ($alias === '') {
                continue;
            }
            if (self::normalize_alias_for_platform_compare($alias) === $model_norm) {
                continue;
            }
            $aliases[] = $alias;
        }
        return array_values(array_unique($aliases));
    }

    private static function normalize_alias_for_platform_compare(string $value): string {
        $value = trim($value, " \t\n\r\0\x0B,;@/\\()[]{}<>\"'");
        $value = preg_replace('/[?#].*$/', '', $value);
        $value = preg_replace('/[^a-z0-9]+/i', '', (string) $value);
        return strtolower((string) $value);
    }

    private static function extract_verified_link_profile_slug(string $url, string $platform): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (class_exists(PlatformProfiles::class) && method_exists(PlatformProfiles::class, 'extract_username_from_profile_url')) {
            $username = PlatformProfiles::extract_username_from_profile_url($platform, $url);
            if (is_string($username) && trim($username) !== '') {
                return trim($username);
            }
        }
        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (!is_array($parts)) {
            return '';
        }
        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($path === '') {
            return '';
        }
        $segments = array_values(array_filter(explode('/', $path), 'strlen'));
        if (empty($segments)) {
            return '';
        }
        return rawurldecode((string) end($segments));
    }


    private static function safe_debug_url(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (!is_array($parts)) {
            return '';
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if ($host === '') {
            return trim($path, '/');
        }
        return $host . ($path !== '' ? $path : '');
    }

    /** @param array<string,string> $alias_lookup @return array<int,array<string,mixed>> */
    private static function all_verified_cam_platform_debug_records(int $post_id, array $alias_lookup, string $model_name): array {
        if (!class_exists(VerifiedLinks::class)) {
            return [];
        }
        $records = [];
        foreach (VerifiedLinks::get_links($post_id) as $link) {
            if (!is_array($link)) {
                continue;
            }
            $type = sanitize_key((string) ($link['type'] ?? ''));
            if (!isset(self::RANKMATH_CAM_PLATFORM_ALLOWLIST[$type])) {
                continue;
            }
            $platform = self::RANKMATH_CAM_PLATFORM_ALLOWLIST[$type];
            $is_active = self::verified_link_active_checkbox_value($link);
            $activity = self::normalize_verified_link_activity($link['activity_level'] ?? '');
            $eligible = self::verified_link_is_active_enough_for_rankmath($link);
            $profile_slug = self::extract_verified_link_profile_slug((string) ($link['url'] ?? ''), $platform);
            $slug_norm = self::normalize_alias_for_platform_compare($profile_slug);
            $matched_alias = ($slug_norm !== '' && isset($alias_lookup[$slug_norm])) ? (string) $alias_lookup[$slug_norm] : '';
            $records[] = [
                'platform' => $platform,
                'raw_platform_type' => $type,
                'raw_activity_level' => (string) ($link['activity_level'] ?? ''),
                'activity_level' => $activity,
                'is_active' => $is_active,
                'eligible_for_rankmath' => $eligible,
                'url_log' => self::safe_debug_url((string) ($link['url'] ?? '')),
                'profile_slug' => $profile_slug,
                'alias_match' => $matched_alias !== '',
                'matched_alias' => $matched_alias,
                'alias_candidate' => $matched_alias !== '' ? self::model_name_phrase($matched_alias, $platform) : '',
                'main_candidate' => self::model_name_phrase($model_name, $platform),
            ];
        }
        return $records;
    }

    /** @return string[] */
    private static function excluded_verified_link_types_for_rankmath(int $post_id): array {
        if (!class_exists(VerifiedLinks::class)) {
            return [];
        }
        $excluded = [];
        foreach (VerifiedLinks::get_links($post_id) as $link) {
            if (!is_array($link)) {
                continue;
            }
            $type = sanitize_key((string) ($link['type'] ?? ''));
            if ($type === '') {
                continue;
            }
            $normalized = $type === 'jasmin' ? 'livejasmin' : $type;
            if (!isset(self::RANKMATH_CAM_PLATFORM_ALLOWLIST[$normalized])) {
                $excluded[] = $type;
            }
        }
        return array_values(array_unique($excluded));
    }

    /** @param string[] $final_extras */
    private static function debug_model_seo_copy_guard(int $post_id, string $model_title, string $phrase, string $reason, string $source_bucket, string $final_heading_keyword, array $final_extras): void {
        if (!class_exists(Settings::class) || !(bool) Settings::get('debug_mode', false)) {
            return;
        }
        Logs::debug('keywords', '[TMW-SEO-COPY-GUARD] Model SEO keyword guard', [
            'post_id' => $post_id,
            'model_title' => $model_title,
            'rejected_keyword_phrase' => $phrase,
            'rejection_reason' => $reason,
            'source_bucket' => $source_bucket,
            'final_selected_heading_keyword' => $final_heading_keyword,
            'final_rank_math_extras' => $final_extras,
        ]);
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $result */
    private static function debug_rankmath_platform_keywords(int $post_id, string $model_name, array $plan, array $result): void {
        if (!class_exists(Settings::class) || !(bool) Settings::get('debug_mode', false)) {
            return;
        }
        Logs::debug('keywords', '[TMW-SEO-PLATFORM-KW] Rank Math model platform keyword candidates', [
            'post_id' => $post_id,
            'model_title' => $model_name,
            'saved_aliases_found' => (array) ($plan['saved_aliases'] ?? []),
            'verified_cam_platform_links_found' => (array) ($plan['verified_cam_links'] ?? []),
            'excluded_verified_link_types' => (array) ($plan['excluded_verified_link_types'] ?? []),
            'legacy_platform_slugs_found' => (array) ($plan['legacy_platform_slugs'] ?? []),
            'platform_source' => (string) ($plan['source'] ?? ''),
            'alias_based_platform_keyword_candidates' => (array) ($plan['alias_keywords'] ?? []),
            'main_name_platform_keyword_candidates' => (array) ($plan['main_keywords'] ?? []),
            'final_platform_keywords_used' => (array) ($result['platform_used'] ?? []),
            'platform_candidates_before_capping' => (array) ($result['platform_considered'] ?? []),
            'fallbacks_before_capping' => self::safe_rankmath_fallback_formulas($model_name, []),
            'fallback_keywords_used' => (array) ($result['fallback_used'] ?? []),
            'final_rank_math_extras' => (array) ($result['final'] ?? []),
            'final_rank_math_csv_written' => implode(',', array_values(array_filter(array_merge([ $model_name ], (array) ($result['final'] ?? [])), 'strlen'))),
        ]);
    }

    /** @param string[] $platform_slugs @return string[] */
    private static function verified_rankmath_platform_keywords(array $platform_slugs): array {
        $allowed = self::RANKMATH_CAM_PLATFORM_ALLOWLIST;
        $out = [];
        foreach ($platform_slugs as $platform) {
            $slug = sanitize_key((string) $platform);
            if ($slug !== '' && isset($allowed[$slug])) {
                $out[] = $allowed[$slug];
            }
        }
        return self::dedupe_keywords($out);
    }

    /** @param string[] $platform_slugs */
    private static function verified_platform_keyword_from_pool_keyword(string $keyword, array $platform_slugs, string $keyword_class): string {
        if (!in_array($keyword_class, [ ModelKeywordPoolClassifier::CLASS_PLATFORM_TERM, ModelKeywordPoolClassifier::CLASS_PLATFORM_INTENT_TERM, ModelKeywordPoolClassifier::CLASS_CORE_MODEL_TERM ], true)) {
            return '';
        }
        $keyword_lc = self::keyword_key($keyword);
        foreach (self::verified_rankmath_platform_keywords($platform_slugs) as $platform_keyword) {
            $needles = [ $platform_keyword ];
            if ($platform_keyword === 'bongacams') {
                $needles[] = 'bonga';
            }
            if ($platform_keyword === 'livejasmin') {
                $needles[] = 'jasmin';
            }
            foreach ($needles as $needle) {
                $needle_lc = self::keyword_key($needle);
                if ($needle_lc !== '' && preg_match('/(?:^|\s)' . preg_quote($needle_lc, '/') . '(?:\s|$)/u', $keyword_lc) === 1) {
                    return $platform_keyword;
                }
            }
        }
        return '';
    }

    /** @param string[] $tag_slugs @return string[] */
    private static function verified_rankmath_attribute_phrases(array $tag_slugs): array {
        $out = [];
        foreach ($tag_slugs as $tag) {
            $phrase = self::keyword_key(str_replace('-', ' ', (string) $tag));
            if ($phrase === '' || in_array($phrase, [ 'cam', 'webcam', 'live', 'model', 'girl', 'show', 'chat' ], true)) {
                continue;
            }
            // Block body-type / ethnicity / demographic terms from becoming
            // tag-attribute chips. A taxonomy tag of 'bbw' or 'ebony' is a
            // site-wide category label, not a profile-specific verified fact
            // that belongs in a model's Rank Math focus keyword set.
            if ( self::chip_suffix_contains_denylist_term( $phrase ) ) {
                continue;
            }
            $out[] = $phrase;
        }
        return self::dedupe_keywords($out);
    }

    /** @param string[] $tag_slugs */
    private static function verified_attribute_phrase_from_pool_keyword(string $keyword, array $tag_slugs, string $keyword_class): string {
        $keyword_lc = self::keyword_key($keyword);
        foreach (self::verified_rankmath_attribute_phrases($tag_slugs) as $attribute_phrase) {
            if (preg_match('/(?:^|\s)' . preg_quote($attribute_phrase, '/') . '(?:\s|$)/u', $keyword_lc) === 1) {
                return $attribute_phrase;
            }
        }
        return '';
    }

    /** @param string[] $keywords @param string[] $tokens @return string[] */
    private static function verified_tokens_used_by_keywords(array $keywords, array $tokens): array {
        $used = [];
        foreach ($keywords as $keyword) {
            $keyword_lc = self::keyword_key((string) $keyword);
            foreach ($tokens as $token) {
                $token_lc = self::keyword_key((string) $token);
                if ($token_lc !== '' && preg_match('/(?:^|\s)' . preg_quote($token_lc, '/') . '(?:\s|$)/u', $keyword_lc) === 1) {
                    $used[] = $token_lc;
                }
            }
        }
        return self::dedupe_keywords($used);
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
        return false;
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
            'bonga' => 'BongaCams',
            'bongacams' => 'BongaCams',
            'flirt4free' => 'Flirt4Free',
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
