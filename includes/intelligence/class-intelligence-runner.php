<?php
namespace TMWSEO\Engine\Intelligence;

use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Keywords\KeywordValidator;
use TMWSEO\Engine\Keywords\KDFilter;
use TMWSEO\Engine\Keywords\ExpansionCandidateRepository;
use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class KeywordIntelligenceRunner {

    /**
     * Run a manual keyword intelligence pass.
     *
     * @param string[] $seeds
     * @param array $options
     * @return array
     */
    public static function run(array $seeds, array $options = []): array {
        $seeds = array_values(array_unique(array_filter(array_map('trim', $seeds), static fn($s) => $s !== '')));
        $seeds = array_slice($seeds, 0, (int)($options['max_seeds'] ?? 10));

        if (empty($seeds)) {
            return ['ok' => false, 'error' => 'no_seeds'];
        }

        $max_total = (int)($options['max_total_keywords'] ?? 1000);
        $max_total = max(50, min(2000, $max_total));

        $sources = $options['sources'] ?? [];
        if (!is_array($sources)) $sources = [];

        $use_dataforseo = !empty($sources['dataforseo']);
        $use_google     = !empty($sources['google']);
        $use_bing       = !empty($sources['bing']);
        $use_reddit     = !empty($sources['reddit']);
        $use_serper     = !empty($sources['serper']);

        $serper_key = trim((string) Settings::get('serper_api_key', ''));
        if ($use_serper && $serper_key === '') {
            $use_serper = false;
        }

        $collected = [];
        $errors = [];

        // 1) DataForSEO keyword suggestions (strongest structured seed expansion)
        if ($use_dataforseo) {
            if (!DataForSEO::is_configured()) {
                $errors[] = 'DataForSEO not configured — skipping DataForSEO sources.';
            } else {
                $limit = (int)($options['dataforseo_limit'] ?? 200);
                $limit = max(10, min(1000, $limit));

                foreach ($seeds as $seed) {
                    $res = DataForSEO::keyword_suggestions($seed, $limit);
                    if (!($res['ok'] ?? false)) {
                        $errors[] = 'DataForSEO keyword_suggestions failed for seed: ' . $seed;
                        continue;
                    }
                    $items = $res['items'] ?? [];
                    if (!is_array($items)) $items = [];

                    foreach ($items as $it) {
                        if (!is_array($it)) continue;
                        $kw = trim((string)($it['keyword'] ?? ''));
                        if ($kw === '') continue;

                        $vol = null;
                        if (!empty($it['keyword_info']) && is_array($it['keyword_info'])) {
                            if (isset($it['keyword_info']['search_volume'])) {
                                $vol = (int)$it['keyword_info']['search_volume'];
                            }
                        }

                        self::add_keyword($collected, $kw, 'dataforseo_suggest', $seed, $vol);
                    }
                }
            }
        }

        // 2) Suggest sources (Google + Bing)
        $suggest_queries = self::build_suggest_queries($seeds, (bool)($options['suggest_expand_modifiers'] ?? true));

        if ($use_google) {
            foreach ($suggest_queries as [$seed, $q]) {
                $list = self::fetch_google_suggest($q);
                foreach ($list as $kw) {
                    self::add_keyword($collected, $kw, 'google_suggest', $seed, null);
                }
            }
        }

        if ($use_bing) {
            foreach ($suggest_queries as [$seed, $q]) {
                $list = self::fetch_bing_suggest($q);
                foreach ($list as $kw) {
                    self::add_keyword($collected, $kw, 'bing_suggest', $seed, null);
                }
            }
        }

        // 3) Reddit discovery (titles only; safe + lightweight)
        if ($use_reddit) {
            foreach ($seeds as $seed) {
                $list = self::fetch_reddit_titles($seed, (int)($options['reddit_limit'] ?? 10));
                foreach ($list as $kw) {
                    self::add_keyword($collected, $kw, 'reddit', $seed, null);
                }
            }
        }

        // 4) Serper (Related searches + People Also Ask)
        // Counters are initialized here and referenced after filtering so the
        // accepted phrases can be routed into the preview/candidate layer.
        $serper_paa_seen     = 0;
        $serper_related_seen = 0;

        if ($use_serper) {
            foreach ($seeds as $seed) {
                $fetched = self::fetch_serper_suggestions($serper_key, $seed);

                $paa_raw     = $fetched['paa']     ?? [];
                $related_raw = $fetched['related'] ?? [];

                $serper_paa_seen     += count($paa_raw);
                $serper_related_seen += count($related_raw);

                foreach ($paa_raw as $raw_kw) {
                    $kw = self::normalize_serper_phrase($raw_kw);
                    if ($kw !== '') {
                        self::add_keyword($collected, $kw, 'serper_paa', $seed, null);
                    }
                }

                foreach ($related_raw as $raw_kw) {
                    $kw = self::normalize_serper_phrase($raw_kw);
                    if ($kw !== '') {
                        self::add_keyword($collected, $kw, 'serper_related', $seed, null);
                    }
                }
            }
        }

        // Normalize + filter relevancy.
        $filtered = [];
        foreach ($collected as $k_norm => $row) {
            $kw = (string)($row['keyword'] ?? '');
            $reason = null;
            if (!KeywordValidator::is_relevant($kw, $reason)) {
                continue;
            }
            $filtered[$k_norm] = $row;
        }

        // ── Route accepted Serper phrases into the preview/candidate layer ──────
        // These are research inputs only — no write to tmwseo_seeds, no auto-approval.
        $serper_inserted     = 0;
        $serper_filtered_out = 0;
        $serper_skipped      = 0;

        if ($use_serper) {
            $paa_accepted     = [];
            $related_accepted = [];

            foreach ($collected as $k_norm => $row) {
                $sources = $row['sources'] ?? [];
                $has_paa = in_array('serper_paa', $sources, true);
                $has_rel = in_array('serper_related', $sources, true);

                if (!$has_paa && !$has_rel) {
                    continue;
                }

                if (!isset($filtered[$k_norm])) {
                    // Rejected by KeywordValidator::is_relevant() — off-lane or adult-filtered.
                    $serper_filtered_out++;
                    continue;
                }

                $phrase = (string)($row['keyword'] ?? '');
                if ($phrase === '') {
                    continue;
                }

                // A phrase that appeared in both PAA and related is tagged as serper_paa
                // (preferred provenance). skipped count from insert_batch covers dedup.
                if ($has_paa) {
                    $paa_accepted[] = $phrase;
                } else {
                    $related_accepted[] = $phrase;
                }
            }

            $run_ts    = gmdate('Y-m-d H:i:s');
            $prov_base = ['trigger_seeds' => $seeds, 'run_timestamp' => $run_ts];

            if (!empty($paa_accepted)) {
                $batch = ExpansionCandidateRepository::insert_batch(
                    $paa_accepted,
                    'serper_paa',
                    'serper_question_expansion',
                    'system',
                    0,
                    $prov_base
                );
                $serper_inserted += $batch['inserted'];
                $serper_skipped  += $batch['skipped'];
            }

            if (!empty($related_accepted)) {
                $batch = ExpansionCandidateRepository::insert_batch(
                    $related_accepted,
                    'serper_related',
                    'serper_related_expansion',
                    'system',
                    0,
                    $prov_base
                );
                $serper_inserted += $batch['inserted'];
                $serper_skipped  += $batch['skipped'];
            }

            Logs::info('keywords', '[TMW-SERPER] PAA/related candidates routed to preview', [
                'serper_paa_seen'             => $serper_paa_seen,
                'serper_related_seen'         => $serper_related_seen,
                'inserted_preview_candidates' => $serper_inserted,
                'filtered_out'                => $serper_filtered_out,
                'skipped_duplicates'          => $serper_skipped,
            ]);
        }

        // Hard cap total keywords BEFORE expensive enrichment.
        if (count($filtered) > $max_total) {
            // Prefer keywords that already have a volume signal (DataForSEO suggest) first.
            uasort($filtered, static function ($a, $b) {
                $av = (int)($a['volume_hint'] ?? 0);
                $bv = (int)($b['volume_hint'] ?? 0);
                if ($av === $bv) return 0;
                return ($av > $bv) ? -1 : 1;
            });
            $filtered = array_slice($filtered, 0, $max_total, true);
        }

        $keywords_list = array_values(array_map(static fn($r) => (string)$r['keyword'], $filtered));

        // Enrich with KD + Search Volume (DataForSEO).
        $kd_map = [];
        $sv_map = [];

        if (DataForSEO::is_configured()) {
            $kd_res = DataForSEO::bulk_keyword_difficulty($keywords_list);
            if (($kd_res['ok'] ?? false) && isset($kd_res['map']) && is_array($kd_res['map'])) {
                foreach ($kd_res['map'] as $kw => $kd) {
                    $kd_map[mb_strtolower((string)$kw, 'UTF-8')] = (float)$kd;
                }
            } else {
                $errors[] = 'DataForSEO bulk_keyword_difficulty failed.';
            }

            // Search volume enrichment (keywords_data endpoint).
            $sv_res = DataForSEO::search_volume($keywords_list);
            if (($sv_res['ok'] ?? false) && isset($sv_res['map']) && is_array($sv_res['map'])) {
                foreach ($sv_res['map'] as $kw => $metrics) {
                    $sv_map[mb_strtolower((string)$kw, 'UTF-8')] = $metrics;
                }
            } else {
                $errors[] = 'DataForSEO search_volume failed (optional).';
            }
        } else {
            $errors[] = 'DataForSEO not configured — KD/Volume will be empty.';
        }

        // Build final rows.
        $rows = [];
        $buckets = [
            'low' => 0,
            'medium' => 0,
            'high' => 0,
            'very_high' => 0,
            'unknown' => 0,
        ];

        foreach ($filtered as $k_norm => $row) {
            $kw = (string)$row['keyword'];
            $key = mb_strtolower($kw, 'UTF-8');

            $kd = $kd_map[$key] ?? null;

            $volume = null;
            if (isset($sv_map[$key]['search_volume'])) {
                $volume = is_null($sv_map[$key]['search_volume']) ? null : (int)$sv_map[$key]['search_volume'];
            } elseif (isset($row['volume_hint'])) {
                $volume = is_null($row['volume_hint']) ? null : (int)$row['volume_hint'];
            }

            $intent = KeywordValidator::infer_intent($kw);
            $opportunity = KDFilter::opportunity_score(is_null($kd) ? null : (float)$kd, is_null($volume) ? null : (int)$volume, $intent);

            $bucket = 'unknown';
            if ($kd !== null) {
                if ($kd <= 30) $bucket = 'low';
                elseif ($kd <= 50) $bucket = 'medium';
                elseif ($kd <= 70) $bucket = 'high';
                else $bucket = 'very_high';
            }

            if (isset($buckets[$bucket])) $buckets[$bucket]++;

            $rows[] = [
                'keyword' => $kw,
                'source' => implode(',', $row['sources'] ?? []),
                'seed' => (string)($row['seed'] ?? ''),
                'volume' => $volume,
                'kd' => $kd,
                'intent' => $intent,
                'kd_bucket' => $bucket,
                'opportunity' => $opportunity,
            ];
        }

        // Sort for reporting: opportunity desc, then volume desc.
        usort($rows, static function ($a, $b) {
            $ao = $a['opportunity'];
            $bo = $b['opportunity'];
            $ao = is_null($ao) ? -1 : (float)$ao;
            $bo = is_null($bo) ? -1 : (float)$bo;
            if ($ao === $bo) {
                $av = (int)($a['volume'] ?? 0);
                $bv = (int)($b['volume'] ?? 0);
                return $bv <=> $av;
            }
            return $bo <=> $ao;
        });

        // Recommended picks using Growth Mix 70/20/10 across low/medium/high.
        $recommended_limit = (int)($options['recommended_limit'] ?? 120);
        $recommended_limit = max(30, min(500, $recommended_limit));

        $recommended = self::select_growth_mix($rows, $recommended_limit);

        // Cluster preview (computed, not stored yet).
        $clusters = self::build_cluster_preview($rows, (int)($options['cluster_preview_limit'] ?? 25));

        $totals = [
            'seeds' => $seeds,
            'total_collected' => count($collected),
            'total_relevant' => count($filtered),
            'bucket_counts' => $buckets,
            'recommended_limit' => $recommended_limit,
            'sources_used' => [
                'dataforseo' => $use_dataforseo,
                'google' => $use_google,
                'bing' => $use_bing,
                'reddit' => $use_reddit,
                'serper' => $use_serper,
            ],
            'serper_accounting' => $use_serper ? [
                'serper_paa_seen'             => $serper_paa_seen,
                'serper_related_seen'         => $serper_related_seen,
                'inserted_preview_candidates' => $serper_inserted,
                'filtered_out'                => $serper_filtered_out,
                'skipped_duplicates'          => $serper_skipped,
            ] : null,
        ];

        return [
            'ok' => true,
            'rows' => $rows,
            'recommended' => $recommended,
            'clusters' => $clusters,
            'totals' => $totals,
            'errors' => $errors,
        ];
    }

    private static function add_keyword(array &$collected, string $keyword, string $source, string $seed, ?int $volume_hint): void {
        $kw = trim($keyword);
        if ($kw === '') return;

        // Normalize for map key (but keep original keyword).
        $k_norm = mb_strtolower(KeywordValidator::normalize($kw), 'UTF-8');
        if ($k_norm === '') return;

        if (!isset($collected[$k_norm])) {
            $collected[$k_norm] = [
                'keyword' => $kw,
                'sources' => [$source],
                'seed' => $seed,
                'volume_hint' => $volume_hint,
            ];
            return;
        }

        if (!in_array($source, $collected[$k_norm]['sources'], true)) {
            $collected[$k_norm]['sources'][] = $source;
        }

        // Keep a stronger volume hint if we get one.
        $existing = $collected[$k_norm]['volume_hint'];
        if ($existing === null && $volume_hint !== null) {
            $collected[$k_norm]['volume_hint'] = $volume_hint;
        }
    }

    /**
     * Build suggest query variations.
     *
     * Uses category-specific seed patterns from the curated library when
     * a category context is available in $options, otherwise falls back
     * to the generic modifiers.
     *
     * @param string[] $seeds
     * @param bool $expand_modifiers
     * @return array<int, array{0:string,1:string}>
     */
    private static function build_suggest_queries(array $seeds, bool $expand_modifiers): array {
        $queries      = [];
        $generic_mods = ['free', 'no signup', 'no registration', 'private', 'best'];

        foreach ($seeds as $seed) {
            $queries[] = [$seed, $seed];

            if (!$expand_modifiers) {
                continue;
            }

            // Try to get category-specific modifiers from the curated library
            $cat_mods     = [];
            $cat_suffixes = [];

            if (class_exists('\\TMWSEO\\Engine\\Keywords\\CuratedKeywordLibrary')) {
                // Infer category from the seed phrase itself (best-effort)
                $categories = \TMWSEO\Engine\Keywords\CuratedKeywordLibrary::categories();
                foreach ($categories as $cat) {
                    if (stripos($seed, str_replace('-', ' ', $cat)) !== false
                        || stripos($seed, $cat) !== false) {
                        $patterns     = \TMWSEO\Engine\Keywords\CuratedKeywordLibrary::get_seed_patterns($cat);
                        $cat_mods     = $patterns['modifiers'] ?? [];
                        $cat_suffixes = $patterns['suffixes'] ?? [];
                        break;
                    }
                }
            }

            $mods = !empty($cat_mods) ? $cat_mods : $generic_mods;

            foreach ($mods as $m) {
                $queries[] = [$seed, trim($seed . ' ' . $m)];
            }
            foreach ($cat_suffixes as $s) {
                $queries[] = [$seed, trim($seed . ' ' . $s)];
            }
        }

        // Dedupe queries while preserving seed association.
        $seen = [];
        $out  = [];
        foreach ($queries as [$seed, $q]) {
            $key = mb_strtolower($q, 'UTF-8');
            if (isset($seen[$seed . '|' . $key])) continue;
            $seen[$seed . '|' . $key] = true;
            $out[] = [$seed, $q];
        }
        return $out;
    }

    private static function fetch_google_suggest(string $query): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        // ── Transient cache (1 hour) ─────────────────────────────────────
        $cache_key = 'tmwseo_gs_suggest_' . md5(strtolower($query));
        $cached    = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        // ── Multiple endpoints with retry + backoff ──────────────────────
        $endpoints = [
            'https://suggestqueries.google.com/complete/search',
            'https://clients1.google.com/complete/search',
            'https://www.google.com/complete/search',
        ];
        $backoff_ms = [300, 900, 1800];
        // FIX BUG-14: Removed home_url() from User-Agent. The previous string
        // 'TMWSEO-Engine/4.1; +{site_url}' broadcast the live site URL to Google's
        // autocomplete infrastructure on every keyword discovery call, which could
        // associate automated crawl patterns with the site domain.
        $user_agent = 'Mozilla/5.0 (compatible; TMWSEOBot/4.6; SEO research tool)';
        $out        = [];

        foreach ($endpoints as $base_url) {
            $url  = $base_url . '?client=firefox&q=' . rawurlencode($query);
            $done = false;

            for ($attempt = 0; $attempt <= 2; $attempt++) {
                if ($attempt > 0 && isset($backoff_ms[$attempt - 1])) {
                    usleep($backoff_ms[$attempt - 1] * 1000);
                }

                $resp = wp_remote_get($url, [
                    'timeout' => 12,
                    'headers' => [
                        'User-Agent' => $user_agent,
                        'Accept'     => 'application/json',
                    ],
                ]);

                if (is_wp_error($resp)) {
                    continue;
                }

                $code = (int) wp_remote_retrieve_response_code($resp);
                if ($code === 429 || $code >= 500) {
                    continue; // rate-limited or server error — retry
                }

                $body = (string) wp_remote_retrieve_body($resp);
                $json = json_decode($body, true);
                if (is_array($json) && isset($json[1]) && is_array($json[1])) {
                    foreach ($json[1] as $s) {
                        $s = trim((string)$s);
                        if ($s !== '') $out[] = $s;
                    }
                    $done = true;
                }
                break;
            }

            if ($done) {
                break; // Got results — no need to try next endpoint
            }
        }

        $out = array_values(array_unique($out));
        set_transient($cache_key, $out, HOUR_IN_SECONDS);
        return $out;
    }

    private static function fetch_bing_suggest(string $query): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        // FIX BUG-14: Added transient cache — was missing, causing unthrottled Bing requests.
        $cache_key = 'tmwseo_bing_suggest_' . md5(strtolower($query));
        $cached    = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
        $url  = 'https://api.bing.com/osjson.aspx?query=' . rawurlencode($query);
        $resp = wp_remote_get($url, [
            'timeout' => 12,
            'headers' => [
                // FIX BUG-14: Neutral user-agent — does not broadcast site URL
                'User-Agent' => 'Mozilla/5.0 (compatible; TMWSEOBot/4.6; SEO research tool)',
                'Accept'     => 'application/json',
            ],
        ]);
        if (is_wp_error($resp)) {
            return [];
        }
        $body = (string) wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json[1]) || !is_array($json[1])) {
            return [];
        }
        $out = [];
        foreach ($json[1] as $s) {
            $s = trim((string)$s);
            if ($s !== '') $out[] = $s;
        }
        $out = array_values(array_unique($out));
        set_transient($cache_key, $out, HOUR_IN_SECONDS);
        return $out;
    }

    private static function fetch_reddit_titles(string $query, int $limit = 10): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $limit = max(1, min(25, $limit));
        // FIX BUG-14: Added transient cache — was missing, causing unthrottled Reddit requests.
        $cache_key = 'tmwseo_reddit_' . md5(strtolower($query) . '_' . $limit);
        $cached    = get_transient($cache_key);
        if ($cached !== false && is_array($cached)) {
            return $cached;
        }
        $url  = 'https://www.reddit.com/search.json?q=' . rawurlencode($query) . '&limit=' . $limit . '&sort=relevance&t=all';
        $resp = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                // FIX BUG-14: Neutral user-agent
                'User-Agent' => 'Mozilla/5.0 (compatible; TMWSEOBot/4.6; SEO research tool)',
                'Accept'     => 'application/json',
            ],
        ]);
        if (is_wp_error($resp)) {
            return [];
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            return [];
        }
        $body = (string) wp_remote_retrieve_body($resp);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return [];
        }
        $children = $json['data']['children'] ?? [];
        if (!is_array($children)) $children = [];
        $out = [];
        foreach ($children as $child) {
            if (!is_array($child) || !isset($child['data']) || !is_array($child['data'])) continue;
            $title = trim((string)($child['data']['title'] ?? ''));
            if ($title !== '') {
                $out[] = $title;
            }
        }
        $out = array_values(array_unique($out));
        set_transient($cache_key, $out, HOUR_IN_SECONDS);
        return $out;
    }

    /**
     * Light normalization for phrases returned by Serper PAA / related searches.
     *
     * Intentionally minimal:
     * - Trims outer whitespace
     * - Collapses internal whitespace runs to a single space
     * - Collapses duplicate trailing punctuation (e.g. "??" → "?")
     *
     * Does NOT rewrite question form to keyword form; PAA questions are kept as-is
     * so the reviewer can see original search-intent phrasing.
     */
    private static function normalize_serper_phrase(string $phrase): string {
        $phrase = trim($phrase);
        if ($phrase === '') {
            return '';
        }
        // Collapse internal whitespace.
        $phrase = (string) preg_replace('/\s+/', ' ', $phrase);
        // Collapse duplicate trailing punctuation (e.g. "??" → "?", "!!" → "!").
        $phrase = (string) preg_replace('/([?!.])(?:[?!.])+$/', '$1', $phrase);
        return $phrase;
    }

    private static function fetch_serper_suggestions(string $api_key, string $query): array {
        $api_key = trim($api_key);
        $query = trim($query);
        if ($api_key === '' || $query === '') {
            return ['paa' => [], 'related' => []];
        }

        $body = [
            'q' => $query,
            'gl' => 'us',
            'hl' => 'en',
            'num' => 10,
        ];

        $resp = wp_remote_post('https://google.serper.dev/search', [
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-KEY' => $api_key,
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($resp)) {
            return ['paa' => [], 'related' => []];
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            return ['paa' => [], 'related' => []];
        }

        $json = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($json)) {
            return ['paa' => [], 'related' => []];
        }

        $paa     = [];
        $related = [];

        if (!empty($json['relatedSearches']) && is_array($json['relatedSearches'])) {
            foreach ($json['relatedSearches'] as $item) {
                if (!is_array($item)) continue;
                $phrase = trim((string)($item['query'] ?? ($item['text'] ?? '')));
                if ($phrase !== '') $related[] = $phrase;
            }
        }

        if (!empty($json['peopleAlsoAsk']) && is_array($json['peopleAlsoAsk'])) {
            foreach ($json['peopleAlsoAsk'] as $item) {
                if (!is_array($item)) continue;
                $phrase = trim((string)($item['question'] ?? ''));
                if ($phrase !== '') $paa[] = $phrase;
            }
        }

        return [
            'paa'     => array_values(array_unique(array_filter($paa,     static fn($v) => $v !== ''))),
            'related' => array_values(array_unique(array_filter($related, static fn($v) => $v !== ''))),
        ];
    }

    /**
     * Select recommended keywords using Growth Mix 70/20/10 across low/medium/high.
     *
     * @param array $rows
     * @param int $limit
     * @return array
     */
    private static function select_growth_mix(array $rows, int $limit): array {
        $low = [];
        $med = [];
        $high = [];

        foreach ($rows as $r) {
            $bucket = $r['kd_bucket'] ?? 'unknown';
            if ($bucket === 'low') $low[] = $r;
            elseif ($bucket === 'medium') $med[] = $r;
            elseif ($bucket === 'high') $high[] = $r;
        }

        $pick_low = (int) round($limit * 0.7);
        $pick_med = (int) round($limit * 0.2);
        $pick_high = max(0, $limit - $pick_low - $pick_med);

        $out = [];

        $out = array_merge($out, array_slice($low, 0, $pick_low));
        $out = array_merge($out, array_slice($med, 0, $pick_med));
        $out = array_merge($out, array_slice($high, 0, $pick_high));

        // If we couldn't fill, top-up from remaining (low->med->high).
        if (count($out) < $limit) {
            $remaining = [];
            $used = [];
            foreach ($out as $r) {
                $used[mb_strtolower((string)$r['keyword'], 'UTF-8')] = true;
            }
            foreach (array_merge($low, $med, $high) as $r) {
                $k = mb_strtolower((string)$r['keyword'], 'UTF-8');
                if (isset($used[$k])) continue;
                $remaining[] = $r;
            }
            $out = array_merge($out, array_slice($remaining, 0, $limit - count($out)));
        }

        return $out;
    }

    /**
     * Build a lightweight cluster preview.
     *
     * @param array $rows
     * @param int $limit
     * @return array
     */
    private static function build_cluster_preview(array $rows, int $limit = 25): array {
        $limit = max(5, min(100, $limit));

        $clusters = [];
        foreach ($rows as $r) {
            $kw = (string)($r['keyword'] ?? '');
            if ($kw === '') continue;
            $ck = KeywordValidator::cluster_key($kw);
            $ck_norm = mb_strtolower($ck, 'UTF-8');

            if (!isset($clusters[$ck_norm])) {
                $clusters[$ck_norm] = [
                    'cluster' => $ck,
                    'count' => 0,
                    'total_volume' => 0,
                    'kd_sum' => 0.0,
                    'kd_count' => 0,
                    'best_opportunity' => null,
                    'examples' => [],
                ];
            }

            $clusters[$ck_norm]['count']++;
            $vol = (int)($r['volume'] ?? 0);
            $clusters[$ck_norm]['total_volume'] += $vol;

            if (!is_null($r['kd'])) {
                $clusters[$ck_norm]['kd_sum'] += (float)$r['kd'];
                $clusters[$ck_norm]['kd_count']++;
            }

            if (!is_null($r['opportunity'])) {
                $bo = $clusters[$ck_norm]['best_opportunity'];
                if (is_null($bo) || (float)$r['opportunity'] > (float)$bo) {
                    $clusters[$ck_norm]['best_opportunity'] = (float)$r['opportunity'];
                }
            }

            if (count($clusters[$ck_norm]['examples']) < 5) {
                $clusters[$ck_norm]['examples'][] = $kw;
            }
        }

        $list = array_values($clusters);
        foreach ($list as &$c) {
            $c['avg_kd'] = $c['kd_count'] > 0 ? round($c['kd_sum'] / $c['kd_count'], 2) : null;
            unset($c['kd_sum'], $c['kd_count']);
        }
        unset($c);

        usort($list, static function ($a, $b) {
            $ao = is_null($a['best_opportunity']) ? -1 : (float)$a['best_opportunity'];
            $bo = is_null($b['best_opportunity']) ? -1 : (float)$b['best_opportunity'];
            if ($ao === $bo) {
                return (int)$b['total_volume'] <=> (int)$a['total_volume'];
            }
            return $bo <=> $ao;
        });

        return array_slice($list, 0, $limit);
    }
}
