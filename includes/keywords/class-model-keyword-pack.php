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
        if (defined('TMWSEO_DEBUG') && TMWSEO_DEBUG) {
            Logs::info('keywords', '[TMW-KW-PACK] build_start post_id=' . (int) $post->ID . ' model=' . $model_name);
        }
        self::debug_log_direct('[TMW-KW-PACK] build_enter post_id=' . (int) $post->ID . ' model="' . $model_name . '"');
        $keyword_context_name = $model_name !== '' ? $model_name : 'live cam model';
        $primary = $keyword_context_name;
        $allow_generic_tag_queries = self::allow_generic_tag_queries();
        $is_model_page = $post->post_type === 'model';

        $platform_slugs = self::active_platform_slugs($post->ID);
        if (defined('TMWSEO_DEBUG') && TMWSEO_DEBUG) {
            $inactive_platforms = self::debug_inactive_platform_slugs($platform_slugs);
            $kw_source_start_msg = '[TMW-KW-SOURCE] post_id=' . (int) $post->ID
                . ' model="' . $model_name . '"'
                . ' active_platforms=' . self::debug_json($platform_slugs)
                . ' inactive_platforms=' . self::debug_json($inactive_platforms);
            Logs::info('keywords', $kw_source_start_msg, [
                'post_id'            => (int) $post->ID,
                'model'              => $model_name,
                'active_platforms'   => $platform_slugs,
                'inactive_platforms' => $inactive_platforms,
            ]);
            self::debug_log_direct($kw_source_start_msg);
            Logs::info('keywords', '[TMW-KW-PACK] active_platforms=' . self::debug_json($platform_slugs), [
                'post_id' => (int) $post->ID,
                'active_platforms' => $platform_slugs,
            ]);
        }
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
        if (defined('TMWSEO_DEBUG') && TMWSEO_DEBUG) {
            $model_specific_candidates = self::debug_model_specific_candidates($classified_fragment);
            $global_pool_count = count((array) ($classified_fragment['global_pool_candidates'] ?? []));
            Logs::info('keywords', '[TMW-KW-PACK] model_specific_count=' . count($model_specific_candidates) . ' candidates=' . self::debug_json($model_specific_candidates), [
                'post_id' => (int) $post->ID,
                'model_specific_count' => count($model_specific_candidates),
                'candidates' => $model_specific_candidates,
                'sources' => $classified_fragment['sources'] ?? [],
            ]);
            $kw_source_counts_msg = '[TMW-KW-SOURCE] post_id=' . (int) $post->ID
                . ' model_specific_count=' . count($model_specific_candidates)
                . ' global_pool_candidates=' . $global_pool_count;
            Logs::info('keywords', $kw_source_counts_msg, [
                'post_id'                => (int) $post->ID,
                'model_specific_count'   => count($model_specific_candidates),
                'global_pool_candidates' => $global_pool_count,
            ]);
            self::debug_log_direct($kw_source_counts_msg);
            self::debug_log_global_model_pool_lookup((int) $post->ID);
        }
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
        if (defined('TMWSEO_DEBUG') && TMWSEO_DEBUG) {
            Logs::info('keywords', '[TMW-KW-PACK] fallback_candidates=' . self::debug_json([
                'additional' => $fallback_additional,
                'longtail' => $fallback_longtail,
            ]), [
                'post_id' => (int) $post->ID,
                'fallback_additional' => $fallback_additional,
                'fallback_longtail' => $fallback_longtail,
            ]);
        }

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

        $confidence = self::compute_confidence($additional, $additional_pool, $platform_slugs, $safe_tags, DataForSEO::is_configured());

        // ── Rank Math extra keyword selection ─────────────────────────────────
        //
        // Priority order for model pages:
        //   P1: approved personal/model-specific CSV keywords (explicit human sign-off)
        //   P2: bucketed template-expanded model Rank Math extras (Git-approved, deterministic)
        //   P3: filtered old global DB pool (safety-gated fallback)
        //   P4: existing deterministic fallback chips
        //
        // P1 must never be overridden by P2, P3, or P4.
        // P3 has a model-rankmath-specific safety gate blocking unsafe generic terms.
        // ─────────────────────────────────────────────────────────────────────
        $rankmath_chips                = [];
        $approved_model_keywords       = [];
        $template_expanded_chips       = [];
        $approved_global_pool_keywords = [];
        $rankmath_fallback_candidates  = [];

        if ($is_model_page) {
            // P1: approved personal/model-specific CSV keywords, ordered by preference.
            $approved_model_keywords = self::order_model_rankmath_candidates(
                (array) ($classified_fragment['extra_focus_candidates'] ?? []),
                $model_name
            );

            // P2: bucketed template-expanded model Rank Math extras.
            // Git-approved config, deterministic, safe, varied across 3,500+ models.
            $template_expanded_result = ModelKeywordPoolTemplateExpander::expand_for_pool_bucketed(
                $model_name,
                (int) $post->ID,
                $platform_slugs,
                4
            );
            $template_expanded_chips = (array) ($template_expanded_result['accepted'] ?? []);

            // P3: filtered old global DB pool.
            // Apply model-rankmath-only safety gate before using as fallback.
            $raw_global_pool           = (array) ($classified_fragment['global_pool_candidates'] ?? []);
            $approved_global_pool_keywords = self::filter_global_pool_for_model_rankmath($raw_global_pool);

            // P4: existing deterministic fallback chips.
            $rankmath_fallback_candidates = self::build_rankmath_chips($model_name, $platform_slugs);

            if (defined('TMWSEO_DEBUG') && TMWSEO_DEBUG) {
                $removed_count = count($raw_global_pool) - count($approved_global_pool_keywords);
                $pool_gate_msg = '[TMW-KW-PACK] global_pool_after_model_rankmath_gate'
                    . ' count=' . count($approved_global_pool_keywords)
                    . ' removed=' . $removed_count;
                Logs::info('keywords', $pool_gate_msg, [
                    'post_id'      => (int) $post->ID,
                    'count_after'  => count($approved_global_pool_keywords),
                    'count_before' => count($raw_global_pool),
                    'removed'      => $removed_count,
                ]);
                self::debug_log_direct($pool_gate_msg);

                $tpl_chips_msg = '[TMW-KW-PACK] template_expanded_chips'
                    . ' count=' . count($template_expanded_chips)
                    . ' chips=' . self::debug_json($template_expanded_chips);
                Logs::info('keywords', $tpl_chips_msg, [
                    'post_id' => (int) $post->ID,
                    'count'   => count($template_expanded_chips),
                    'chips'   => $template_expanded_chips,
                ]);
                self::debug_log_direct($tpl_chips_msg);

                Logs::info('keywords', '[TMW-KW-PACK] global_pool_candidates=' . self::debug_json($approved_global_pool_keywords), [
                    'post_id'                => (int) $post->ID,
                    'global_pool_count'      => count($approved_global_pool_keywords),
                    'global_pool_candidates' => $approved_global_pool_keywords,
                    'global_pool_source'     => 'classified_provider_global_pool_candidates_filtered',
                ]);
                Logs::info('keywords', '[TMW-KW-PACK] fallback_candidates=' . self::debug_json($rankmath_fallback_candidates), [
                    'post_id'              => (int) $post->ID,
                    'fallback_type'        => 'rankmath_chips',
                    'fallback_candidates'  => $rankmath_fallback_candidates,
                ]);
            }

            // Merge P1+P2 as preferred, P3+P4 as fallback.
            $rankmath_chips = self::merge_preferred_keywords(
                array_merge($approved_model_keywords, $template_expanded_chips),
                array_merge($approved_global_pool_keywords, $rankmath_fallback_candidates),
                12
            );
            $rankmath_chips = self::finalize_rankmath_additional_keywords(
                $rankmath_chips,
                $classified_exclusions,
                $model_name
            );

            if (defined('TMWSEO_DEBUG') && TMWSEO_DEBUG) {
                self::debug_log_kw_source_candidates(
                    (int) $post->ID,
                    $approved_model_keywords,
                    $template_expanded_chips,
                    $approved_global_pool_keywords,
                    $rankmath_fallback_candidates,
                    $rankmath_chips,
                    $classified_exclusions,
                    $primary,
                    $platform_slugs
                );
            }
        }

        if (defined('TMWSEO_DEBUG') && TMWSEO_DEBUG) {
            $fallback_lookup_lc = [];
            foreach ($rankmath_fallback_candidates as $kw) {
                $clean = self::normalize_keyword((string) $kw);
                if ($clean !== '') {
                    $fallback_lookup_lc[strtolower($clean)] = true;
                }
            }
            $fallback_count = 0;
            foreach ($rankmath_chips as $chip) {
                $key = strtolower(self::normalize_keyword((string) $chip));
                if ($key !== '' && isset($fallback_lookup_lc[$key])) {
                    $fallback_count++;
                }
            }
            $fallback_used = $fallback_count > 0;

            $kw_pack_selected_msg = '[TMW-KW-PACK] post_id=' . (int) $post->ID
                . ' selected_focus="' . $primary . '"'
                . ' selected_extras=' . self::debug_json($rankmath_chips)
                . ' selected_extra_sources=' . self::debug_json(
                    self::debug_rankmath_chip_sources(
                        $rankmath_chips,
                        $approved_model_keywords,
                        $rankmath_fallback_candidates,
                        $approved_global_pool_keywords,
                        $template_expanded_chips
                    )
                )
                . ' fallback_used=' . ($fallback_used ? 'yes' : 'no')
                . ' fallback_count=' . $fallback_count;

            Logs::info('keywords', '[TMW-KW-PACK] selected_focus=' . $primary . ' selected_extras=' . self::debug_json($rankmath_chips), [
                'post_id' => (int) $post->ID,
                'selected_focus' => $primary,
                'selected_focus_source' => self::debug_primary_source($primary, $classified_fragment, $model_name),
                'selected_extras' => $rankmath_chips,
                'selected_extra_sources' => self::debug_rankmath_chip_sources(
                    $rankmath_chips,
                    $approved_model_keywords,
                    $rankmath_fallback_candidates,
                    $approved_global_pool_keywords,
                    $template_expanded_chips
                ),
                'approved_model_keywords'       => $approved_model_keywords,
                'template_expanded_chips'       => $template_expanded_chips,
                'approved_global_pool_keywords' => $approved_global_pool_keywords,
                'rankmath_fallback_candidates'  => $rankmath_fallback_candidates,
                'fallback_used'  => $fallback_used,
                'fallback_count' => $fallback_count,
            ]);
            self::debug_log_direct($kw_pack_selected_msg);
            Logs::info('keywords', '[TMW-SEO-RMKW] ModelKeywordPack::build completed', [
                'post_id'             => $post->ID,
                'primary'             => $primary,
                'rankmath_additional' => $rankmath_chips,
                'extra_focus_from_db' => $classified_fragment['extra_focus_candidates'] ?? [],
                'global_pool_from_db' => $classified_fragment['global_pool_candidates'] ?? [],
                'template_expanded'   => $template_expanded_chips,
                'rebuilt_model_pack'  => !empty($approved_model_keywords) || !empty($template_expanded_chips) || !empty($approved_global_pool_keywords),
            ]);
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


    /** @param mixed $value */
    private static function debug_json($value): string {
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);
        return is_string($encoded) ? $encoded : '[]';
    }

    /** @param array<string,mixed> $fragment @return string[] */
    private static function debug_model_specific_candidates(array $fragment): array {
        return self::dedupe_keywords(array_merge(
            (array) ($fragment['primary_candidates'] ?? []),
            (array) ($fragment['extra_focus_candidates'] ?? []),
            (array) ($fragment['body_semantic_candidates'] ?? []),
            (array) ($fragment['modifier_candidates'] ?? [])
        ));
    }

    /** @param array<string,mixed> $fragment */
    private static function debug_primary_source(string $primary, array $fragment, string $model_name): string {
        $primary_lc = strtolower(self::normalize_keyword($primary));
        foreach ((array) ($fragment['primary_candidates'] ?? []) as $candidate) {
            if ($primary_lc !== '' && strtolower(self::normalize_keyword((string) $candidate)) === $primary_lc) {
                return 'classified_model_specific_primary';
            }
        }
        $model_lc = strtolower(self::normalize_keyword($model_name));
        return $primary_lc !== '' && $primary_lc === $model_lc ? 'model_title_fallback' : 'keyword_context_fallback';
    }

    /**
     * @param string[] $chips
     * @param string[] $approved
     * @param string[] $fallback
     * @param string[] $global_pool
     * @param string[] $template_expanded
     * @return array<string,string>
     */
    private static function debug_rankmath_chip_sources(
        array $chips,
        array $approved,
        array $fallback,
        array $global_pool = [],
        array $template_expanded = []
    ): array {
        $approved_lookup = [];
        foreach ($approved as $keyword) {
            $clean = self::normalize_keyword((string) $keyword);
            if ($clean !== '') {
                $approved_lookup[strtolower($clean)] = true;
            }
        }
        $template_lookup = [];
        foreach ($template_expanded as $keyword) {
            $clean = self::normalize_keyword((string) $keyword);
            if ($clean !== '') {
                $template_lookup[strtolower($clean)] = true;
            }
        }
        $global_pool_lookup = [];
        foreach ($global_pool as $keyword) {
            $clean = self::normalize_keyword((string) $keyword);
            if ($clean !== '') {
                $global_pool_lookup[strtolower($clean)] = true;
            }
        }
        $fallback_lookup = [];
        foreach ($fallback as $keyword) {
            $clean = self::normalize_keyword((string) $keyword);
            if ($clean !== '') {
                $fallback_lookup[strtolower($clean)] = true;
            }
        }
        $sources = [];
        foreach ($chips as $chip) {
            $clean = self::normalize_keyword((string) $chip);
            if ($clean === '') {
                continue;
            }
            $key = strtolower($clean);
            if (isset($approved_lookup[$key])) {
                $sources[$clean] = 'classified_model_specific_approved';
            } elseif (isset($template_lookup[$key])) {
                $sources[$clean] = 'template_expanded_approved';
            } elseif (isset($global_pool_lookup[$key])) {
                $sources[$clean] = 'global_pool_approved_filtered';
            } elseif (isset($fallback_lookup[$key])) {
                $sources[$clean] = 'rankmath_generated_fallback';
            } else {
                $sources[$clean] = 'post_filter_unknown';
            }
        }
        return $sources;
    }

    private static function debug_log_direct(string $message): void {
        if (
            (defined('TMW_DEBUG') && TMW_DEBUG)
            || (defined('TMWSEO_DEBUG') && TMWSEO_DEBUG)
            || (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG)
        ) {
            error_log($message);
        }
    }

    /** @param string[] $active_slugs @return string[] */
    private static function debug_inactive_platform_slugs(array $active_slugs): array {
        $known = ['livejasmin', 'stripchat', 'myfreecams', 'camsoda', 'cam4', 'chaturbate', 'bonga'];
        $active_lc = array_fill_keys(array_map('strtolower', $active_slugs), true);
        return array_values(array_filter($known, static fn(string $slug): bool => !isset($active_lc[$slug])));
    }

    /**
     * @param string[]          $approved_model     model-specific extra_focus_candidates after ordering
     * @param string[]          $template_expanded  template-expanded bucketed chips (P2)
     * @param string[]          $global_pool        global_pool_candidates from provider (filtered, P3)
     * @param string[]          $fallback            build_rankmath_chips() output (P4)
     * @param string[]          $final_chips        rankmath_chips after finalize (the 4 chosen)
     * @param array<string,bool>$exclusions         classified_exclusion_lookup
     * @param string            $primary            the selected primary focus keyword
     * @param string[]          $active_platforms   active platform slugs
     */
    private static function debug_log_kw_source_candidates(
        int    $post_id,
        array  $approved_model,
        array  $template_expanded,
        array  $global_pool,
        array  $fallback,
        array  $final_chips,
        array  $exclusions,
        string $primary,
        array  $active_platforms
    ): void {
        $primary_lc = strtolower(self::normalize_keyword($primary));

        $selected_lc = [];
        foreach ($final_chips as $chip) {
            $key = strtolower(self::normalize_keyword((string) $chip));
            if ($key !== '') {
                $selected_lc[$key] = true;
            }
        }

        $skip_reason = static function (string $kw_lc) use ($exclusions, $primary_lc, $selected_lc): string {
            if (isset($exclusions[$kw_lc])) {
                return 'classified_excluded';
            }
            if ($primary_lc !== '' && $kw_lc === $primary_lc) {
                return 'matches_primary';
            }
            return 'cap_exceeded';
        };

        $log_candidate = static function (string $keyword, string $source) use (
            $post_id, $selected_lc, $skip_reason
        ): void {
            $kw_lc   = strtolower(self::normalize_keyword($keyword));
            $selected = isset($selected_lc[$kw_lc]) ? 'yes' : 'no';
            $reason   = $selected === 'no' ? $skip_reason($kw_lc) : '';

            $msg = '[TMW-KW-SOURCE] candidate post_id=' . $post_id
                . ' keyword="' . $keyword . '"'
                . ' source="' . $source . '"'
                . ' selected=' . $selected
                . ($reason !== '' ? ' skip_reason="' . $reason . '"' : '');

            $ctx = [
                'post_id'  => $post_id,
                'keyword'  => $keyword,
                'source'   => $source,
                'selected' => $selected,
            ];
            if ($reason !== '') {
                $ctx['skip_reason'] = $reason;
            }
            Logs::info('keywords', $msg, $ctx);
            self::debug_log_direct($msg);
        };

        // 1. Model-specific approved rows (P1).
        foreach ($approved_model as $kw) {
            $log_candidate((string) $kw, 'model_specific_approved');
        }

        // 2. Template-expanded bucketed chips (P2).
        foreach ($template_expanded as $kw) {
            $log_candidate((string) $kw, 'template_expanded_approved');
        }

        // 3. Global pool approved rows, safety-filtered (P3).
        foreach ($global_pool as $kw) {
            $log_candidate((string) $kw, 'global_pool_approved_filtered');
        }

        // 4. Deterministic fallback chips (P4).
        foreach ($fallback as $kw) {
            $log_candidate((string) $kw, 'deterministic_fallback');
        }

        // 5. Hypothetical chips for inactive platforms — logged as skipped/platform_inactive.
        $active_lc = array_fill_keys(array_map('strtolower', $active_platforms), true);
        $known_platforms = ['livejasmin', 'stripchat', 'myfreecams', 'camsoda', 'cam4', 'chaturbate', 'bonga'];
        $name_lc = strtolower(self::normalize_keyword($primary !== '' ? $primary : ''));
        if ($name_lc !== '') {
            foreach ($known_platforms as $slug) {
                if (isset($active_lc[$slug])) {
                    continue;
                }
                $label = self::platform_keyword_label($slug);
                if ($label === '') {
                    continue;
                }
                $hypothetical_chip = $name_lc . ' ' . strtolower($label);
                $msg = '[TMW-KW-SOURCE] candidate post_id=' . $post_id
                    . ' keyword="' . $hypothetical_chip . '"'
                    . ' source="platform"'
                    . ' platform="' . $slug . '"'
                    . ' selected=no'
                    . ' skip_reason="platform_inactive"';
                Logs::info('keywords', $msg, [
                    'post_id'     => $post_id,
                    'keyword'     => $hypothetical_chip,
                    'source'      => 'platform',
                    'platform'    => $slug,
                    'selected'    => 'no',
                    'skip_reason' => 'platform_inactive',
                ]);
                self::debug_log_direct($msg);
            }
        }
    }

    private static function debug_log_global_model_pool_lookup(int $post_id): void {
        if (!(defined('TMWSEO_DEBUG') && TMWSEO_DEBUG)) {
            return;
        }
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->prefix) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'esc_like')) {
            Logs::info('keywords', '[TMW-KW-PACK] global_pool_lookup=missing reason=wpdb_unavailable', [ 'post_id' => $post_id ]);
            return;
        }

        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if (!is_string($found) || strtolower($found) !== strtolower($table)) {
            Logs::info('keywords', '[TMW-KW-PACK] global_pool_lookup=missing reason=keyword_candidate_table_unavailable', [ 'post_id' => $post_id ]);
            return;
        }

        if (!method_exists($wpdb, 'get_col')) {
            Logs::info('keywords', '[TMW-KW-PACK] global_pool_lookup=missing reason=wpdb_get_col_unavailable', [ 'post_id' => $post_id ]);
            return;
        }

        $columns = $wpdb->get_col('SHOW COLUMNS FROM ' . $table, 0);
        $columns = is_array($columns) ? array_map('strval', $columns) : [];
        $column_lookup = array_fill_keys($columns, true);
        Logs::info('keywords', '[TMW-KW-PACK] global_pool_columns=' . self::debug_json($columns), [
            'post_id'           => $post_id,
            'available_columns' => $columns,
        ]);

        $base_supported = isset($column_lookup['intent_type'], $column_lookup['status']);
        if (!$base_supported) {
            Logs::info('keywords', '[TMW-KW-PACK] global_pool_lookup=missing reason=base_columns_absent', [
                'post_id'           => $post_id,
                'available_columns' => $columns,
            ]);
            return;
        }

        $strategy_results = [];
        $selected_strategy = '';
        $selected_count    = 0;

        if (isset($column_lookup['model_keyword_usage_scope'])) {
            $cnt = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $table
                . ' WHERE intent_type = %s AND status = %s AND model_keyword_usage_scope = %s',
                'model', 'approved', 'global_model_pool'
            ));
            $strategy_results['s1_scope_column'] = $cnt;
            if ($selected_strategy === '' && $cnt > 0) {
                $selected_strategy = 's1_scope_column';
                $selected_count    = $cnt;
            }
        } else {
            $strategy_results['s1_scope_column'] = 'column_absent';
        }

        if (isset($column_lookup['target_type'], $column_lookup['target_name'])) {
            $cnt = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $table
                . ' WHERE intent_type = %s AND status = %s AND target_type = %s AND target_name = %s',
                'model', 'approved', 'global', 'Global Model Pool'
            ));
            $strategy_results['s2_target_type_name'] = $cnt;
            if ($selected_strategy === '' && $cnt > 0) {
                $selected_strategy = 's2_target_type_name';
                $selected_count    = $cnt;
            }
        } else {
            $strategy_results['s2_target_type_name'] = 'columns_absent';
        }

        if (isset($column_lookup['target_type'], $column_lookup['target_slug'])) {
            $cnt = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $table
                . ' WHERE intent_type = %s AND status = %s AND target_type = %s AND target_slug = %s',
                'model', 'approved', 'global', 'global-model-pool'
            ));
            $strategy_results['s3_target_type_slug'] = $cnt;
            if ($selected_strategy === '' && $cnt > 0) {
                $selected_strategy = 's3_target_type_slug';
                $selected_count    = $cnt;
            }
        } else {
            $strategy_results['s3_target_type_slug'] = 'columns_absent';
        }

        Logs::info('keywords', '[TMW-KW-PACK] global_pool_strategies=' . self::debug_json($strategy_results), [
            'post_id'          => $post_id,
            'strategy_results' => $strategy_results,
            'selected_strategy'=> $selected_strategy ?: 'none',
            'selected_count'   => $selected_count,
            'global_pool_usage'=> 'loaded_via_classified_provider_global_pool_candidates',
        ]);

        $kw_source_strategy_counts = [];
        if (isset($column_lookup['model_keyword_usage_scope'])) {
            $kw_source_strategy_counts['scope'] = is_int($strategy_results['s1_scope_column']) ? $strategy_results['s1_scope_column'] : 0;
        }
        if (isset($column_lookup['target_type'], $column_lookup['target_name'])) {
            $kw_source_strategy_counts['target_name'] = is_int($strategy_results['s2_target_type_name']) ? $strategy_results['s2_target_type_name'] : 0;
        }
        if (isset($column_lookup['target_type'], $column_lookup['target_slug'])) {
            $kw_source_strategy_counts['target_slug'] = is_int($strategy_results['s3_target_type_slug']) ? $strategy_results['s3_target_type_slug'] : 0;
        }
        Logs::info('keywords', '[TMW-KW-SOURCE] post_id=' . $post_id
            . ' global_strategy_counts=' . self::debug_json($kw_source_strategy_counts), [
            'post_id'                 => $post_id,
            'global_strategy_counts'  => $kw_source_strategy_counts,
            'selected_strategy'       => $selected_strategy ?: 'none',
            'selected_count'          => $selected_count,
        ]);
        self::debug_log_direct('[TMW-KW-SOURCE] post_id=' . $post_id
            . ' global_strategy_counts=' . self::debug_json($kw_source_strategy_counts));

        if ($selected_strategy !== '') {
            Logs::info('keywords', '[TMW-KW-PACK] global_pool_count=' . $selected_count, [
                'post_id'            => $post_id,
                'global_pool_count'  => $selected_count,
                'selected_strategy'  => $selected_strategy,
                'strategy_results'   => $strategy_results,
                'global_pool_usage'  => 'loaded_via_classified_provider_global_pool_candidates',
            ]);
        } else {
            Logs::info('keywords', '[TMW-KW-PACK] global_pool_count=0 reason=all_strategies_empty', [
                'post_id'          => $post_id,
                'strategy_results' => $strategy_results,
            ]);
        }
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
        $keywords = self::dedupe_keywords($keywords);
        return self::dedupe_reordered_keywords($keywords, 4);
    }

    /**
     * Remove keywords that are reordered duplicates of an earlier keyword.
     *
     * "anisyia livejasmin" and "livejasmin anisyia" share the same sorted
     * token set and are treated as duplicates; the first occurrence wins.
     *
     * @param  string[] $keywords  Already exact-deduped list.
     * @return string[]
     */
    private static function dedupe_reordered_keywords(array $keywords): array {
        $out = [];
        $seen_fingerprints = [];
        foreach ($keywords as $kw) {
            $clean = self::normalize_keyword((string) $kw);
            if ($clean === '') {
                continue;
            }
            $lower = function_exists('mb_strtolower') ? mb_strtolower($clean, 'UTF-8') : strtolower($clean);
            $tokens = preg_split('/\s+/u', $lower);
            if (!is_array($tokens)) {
                $tokens = [$lower];
            }
            $tokens = array_values(array_filter($tokens, 'strlen'));
            sort($tokens, SORT_STRING);
            $fingerprint = implode('|', $tokens);
            if (isset($seen_fingerprints[$fingerprint])) {
                continue;
            }
            $seen_fingerprints[$fingerprint] = true;
            $out[] = $kw;
        }
        return $out;
    }

    /** @param string[] $keywords @return string[] */
    private static function order_model_rankmath_candidates(array $keywords, string $model_name): array {
        $model = self::normalize_keyword($model_name);
        $model_lc = function_exists('mb_strtolower') ? mb_strtolower($model, 'UTF-8') : strtolower($model);
        if ($model_lc === '') {
            return self::dedupe_keywords($keywords);
        }

        $available = [];
        foreach ($keywords as $keyword) {
            $clean = self::normalize_keyword((string) $keyword);
            if ($clean === '') {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($clean, 'UTF-8') : strtolower($clean);
            $available[$key] = $clean;
        }

        // Preferred ordering for model-specific CSV keywords.
        // Note: porn-related patterns removed — they must not be promoted by fallback ordering.
        // If an approved personal CSV row contains those terms, it still flows through the
        // existing personal CSV gate in ClassifiedModelKeywordProvider but is not preferentially ordered.
        $preferred_keys = [
            $model_lc . ' livejasmin',
            $model_lc . ' live',
        ];

        $ordered = [];
        $seen = [];
        foreach ($preferred_keys as $key) {
            if (isset($available[$key]) && !isset($seen[$key])) {
                $ordered[] = $available[$key];
                $seen[$key] = true;
            }
        }
        foreach ($available as $key => $keyword) {
            if (!isset($seen[$key])) {
                $ordered[] = $keyword;
                $seen[$key] = true;
            }
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
     * Safety gate for old global DB pool keywords entering model Rank Math slots.
     *
     * Applied only to $approved_global_pool_keywords (P3). Never applied to
     * personal/model-specific CSV keywords (P1) — those have explicit human sign-off.
     * Does not globally delete or reclassify these keywords in the DB.
     * Video and category pool policies are separate and not affected here.
     *
     * @param  string[] $keywords Raw global pool keywords.
     * @return string[] Filtered keywords safe for model Rank Math slots.
     */
    private static function filter_global_pool_for_model_rankmath(array $keywords): array {
        $exact_blocked = [
            'adult video chat',
            'video chat room',
            'live cam show',
            'webcam model',
            'cam girl',
            'hot model',
            'sexy model',
        ];
        $exact_lookup = array_fill_keys($exact_blocked, true);

        $fragment_blocked = [
            'porn', 'sex', 'xxx', 'nude', 'underage',
            'teen', 'teens', 'schoolgirl', 'school girl', 'virgin', 'young',
        ];

        $out = [];
        foreach ($keywords as $keyword) {
            $clean = self::normalize_keyword((string) $keyword);
            if ($clean === '') { continue; }
            $lc = strtolower($clean);

            // Exact match block.
            if (isset($exact_lookup[$lc])) { continue; }

            // Word-boundary fragment block.
            $blocked = false;
            foreach ($fragment_blocked as $fragment) {
                if (preg_match(
                    '/(?:^|\s)' . preg_quote($fragment, '/') . '(?:\s|$)/u',
                    $lc
                ) === 1) {
                    $blocked = true;
                    break;
                }
            }
            if ($blocked) { continue; }

            $out[] = $clean;
        }

        return self::dedupe_keywords($out);
    }

    /** @return int */
    private static function dynamic_additional_count(array $pool, array $platforms, array $tags): int {
        $quality_threshold = 30;
        $viable = 0;
        foreach ($pool as $score) {
            if ((int) $score >= $quality_threshold) {
                $viable++;
            }
        }

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

        if (count($platforms) >= 3) {
            $count = min(6, $count + 1);
        }

        if (count($tags) >= 6) {
            $count = min(6, $count + 1);
        }

        return max(2, min(6, $count));
    }

    /**
     * @param string[]          $selected
     * @param array<string,int> $pool
     * @param string[]          $platforms
     * @param string[]          $tags
     * @param bool              $has_dfseo
     * @return float
     */
    private static function compute_confidence(array $selected, array $pool, array $platforms, array $tags, bool $has_dfseo): float {
        if (empty($selected)) {
            return 10.0;
        }

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
        $score_component = min(50.0, ($avg_score / 80.0) * 50.0);

        $richness = 0.0;
        if ($has_dfseo)              $richness += 15.0;
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
            'girl' => true, 'cam' => true, 'webcam' => true,
            'live' => true, 'chat' => true, 'model' => true, 'show' => true,
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
                'tag' => $tag_slug, 'len' => $len,
                'generic' => $is_generic ? 1 : 0, 'idx' => (int)$idx,
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
                if (preg_match('/\b(teen|underage|school)\b/i', $slug)) continue;
                $out[] = $slug;
            }
        }
        $out = array_values(array_unique($out));
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
            if ($tag_phrase === '') { continue; }
            $out[] = $tag_phrase . ' chat ideas';
            $out[] = $tag_phrase . ' chat style';
        }

        return self::dedupe_keywords($out);
    }


    /** @param array<string,int> $pool @return array<string,int> */
    private static function filter_scored_pool_for_model_page(array $pool): array {
        if (empty($pool)) { return []; }

        $allowed = PageTypeKeywordFilter::filter_for_model_page(array_keys($pool));
        $allowed_keys = [];
        foreach ($allowed as $kw) {
            $allowed_keys[strtolower(self::normalize_keyword((string) $kw))] = true;
        }

        $filtered = [];
        foreach ($pool as $kw => $score) {
            $clean = self::normalize_keyword((string) $kw);
            if ($clean === '') { continue; }
            if (!isset($allowed_keys[strtolower($clean)])) { continue; }
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
            if ($kw === '' || self::keyword_contains_name($kw, $name)) { continue; }
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
            if (count($ordered) >= $count) { break; }
        }

        return array_slice($ordered, 0, $count);
    }

    private static function is_tag_only_query(string $keyword, string $name, array $tags): bool {
        if (self::keyword_contains_name($keyword, $name)) return false;
        $keyword_l = strtolower($keyword);
        foreach (array_slice($tags, 0, 2) as $tag_slug) {
            $tag_phrase = trim(strtolower(str_replace('-', ' ', (string)$tag_slug)));
            if ($tag_phrase === '') continue;
            if (strpos($keyword_l, $tag_phrase) !== false) { return true; }
        }
        return false;
    }

    /**
     * Build Rank Math-specific keyword chips for a model page (P4 fallback).
     *
     * @param string   $name          Exact model name.
     * @param string[] $platform_slugs Active platform slugs for optional platform chip.
     * @return string[]               Up to 4 chips.
     */
    private static function build_rankmath_chips(string $name, array $platform_slugs): array {
        $clean_name = self::normalize_keyword($name);
        if ($clean_name === '') { return []; }

        $name_lc = function_exists('mb_strtolower') ? mb_strtolower($clean_name, 'UTF-8') : strtolower($clean_name);
        $platform_keys = array_values(array_unique(array_filter(array_map(
            static fn($slug): string => sanitize_key((string) $slug),
            $platform_slugs
        ), 'strlen')));
        $has_livejasmin = in_array('livejasmin', $platform_keys, true) || in_array('jasmin', $platform_keys, true);
        $has_camsoda = in_array('camsoda', $platform_keys, true);

        $chips = [];
        if ($has_livejasmin) {
            $chips[] = $name_lc . ' livejasmin';
            $chips[] = 'livejasmin ' . $name_lc;
            $chips[] = $name_lc . ' live';
            $chips[] = $has_camsoda ? $name_lc . ' camsoda' : $name_lc . ' live cam';
        }

        foreach ($platform_keys as $platform_key) {
            if ($platform_key === 'livejasmin' || $platform_key === 'jasmin') { continue; }
            $label = self::platform_keyword_label($platform_key);
            if ($label === '') { continue; }
            $chips[] = $name_lc . ' ' . (function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label));
        }

        foreach ([
            'profile',
            'live cam',
            'private chat',
            'webcam profile',
            'webcam model',
            'live cam chat',
            'cam profile',
        ] as $mod) {
            $chips[] = $name_lc . ' ' . $mod;
        }

        $filtered = [];
        foreach (self::dedupe_keywords($chips) as $chip) {
            $chip_lc = function_exists('mb_strtolower') ? mb_strtolower($chip, 'UTF-8') : strtolower($chip);
            if ($chip_lc === $name_lc) { continue; }
            $filtered[] = $chip;
            if (count($filtered) >= 4) { break; }
        }

        return array_slice($filtered, 0, 4);
    }

    private static function platform_keyword_label(string $platform): string {
        $platform = sanitize_key($platform);
        $map = [
            'livejasmin' => 'LiveJasmin',
            'stripchat'  => 'Stripchat',
            'myfreecams' => 'MyFreeCams',
            'camsoda'    => 'CamSoda',
            'cam4'       => 'CAM4',
            'chaturbate' => 'Chaturbate',
            'bonga'      => 'Bonga',
        ];
        if (isset($map[$platform])) { return $map[$platform]; }
        $platform = trim(str_replace(['-', '_'], ' ', $platform));
        return $platform !== '' ? ucwords($platform) : '';
    }

    /** @param array<string,int> $pool @return string[] */
    private static function pick_top(array $pool, int $count, string $name, int $max_non_name = PHP_INT_MAX): array {
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

        uasort($scored, function($a, $b){
            $sa = (int)($a['score'] ?? 0);
            $sb = (int)($b['score'] ?? 0);
            if ($sa === $sb) return strcmp((string)($a['kw'] ?? ''), (string)($b['kw'] ?? ''));
            return ($sa > $sb) ? -1 : 1;
        });

        $picked = [];
        $name_l = strtolower($name);

        foreach ($scored as $item) {
            if (count($picked) >= $count) break;
            $kw = (string)($item['kw'] ?? '');
            if ($name_l !== '' && strpos(strtolower($kw), $name_l) !== false) {
                $picked[] = $kw;
            }
        }

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
        if (count($parts) > 7) { $parts = array_slice($parts, 0, 7); }
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
    if (!(defined('TMWSEO_DEBUG') && TMWSEO_DEBUG)) { return; }
    foreach ($additional as $i => $kw) {
        $kw = (string) $kw;
        if ($kw !== '' && self::keyword_contains_name($kw, $name)) {
            if (class_exists(Logs::class) && method_exists(Logs::class, 'warn')) {
                Logs::warn('keywords', '[TMW-KEYWORDS] Model additional keyword still contains exact name', [
                    'index' => $i, 'keyword' => $kw, 'name' => $name,
                ]);
            }
        }
    }
}}

