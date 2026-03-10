<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Services\DataForSEO;

if (!defined('ABSPATH')) { exit; }

class KeywordEngine {

    public static function init(): void {
        // No hooks yet (cron drives the cycle job).
    }

    public static function run_cycle_job(array $job): void {
        global $wpdb;

        Logs::info('keywords', 'Keyword cycle started', ['job_id' => $job['id'] ?? null]);

        $payload = $job['payload'] ?? [];
        if (!is_array($payload)) $payload = [];
        $mode = (string)($payload['mode'] ?? 'full');


        if (!DataForSEO::is_configured()) {
            Logs::warn('keywords', 'DataForSEO not configured; skipping keyword cycle');
            return;
        }

        $job_started = microtime(true);

        $lock_key = 'tmw_dfseo_keyword_lock';

        $lock_time = get_transient($lock_key);

        if ($lock_time) {
            if ((time() - (int)$lock_time) > (10 * MINUTE_IN_SECONDS)) {
                // Stale lock detected — auto release
                delete_transient($lock_key);
                Logs::warn('keywords', 'Stale lock detected and released');
            } else {
                Logs::warn('keywords', 'Seed processing skipped due to active lock');
                return;
            }
        }

        set_transient($lock_key, time(), 10 * MINUTE_IN_SECONDS);

        $breaker = get_option('tmw_keyword_engine_breaker', []);

        if (!empty($breaker['last_triggered'])) {

            $cooldown_seconds = 15 * MINUTE_IN_SECONDS;
            $cooldown_until = (int)$breaker['last_triggered'] + $cooldown_seconds;

            if (time() < $cooldown_until) {
                Logs::warn('keywords', 'Breaker cooldown active, skipping execution', [
                    'cooldown_until' => $cooldown_until,
                ]);
                return;
            }
        }

        $min_volume = (int) Settings::get('keyword_min_volume', 30);
        $max_kd     = (float) Settings::get('keyword_max_kd', 60);
        $new_limit  = (int) Settings::get('keyword_new_limit', 300);
        $kd_limit   = (int) Settings::get('keyword_kd_batch_limit', 300);
        $pages_per_day = (int) Settings::get('keyword_pages_per_day', 3);

        $raw_table = $wpdb->prefix . 'tmw_keyword_raw';
        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';

        // 1) Collect seeds (adaptive, mix of base + your top categories).
        //    Mode 'import_only' skips discovery and only runs KD + clustering + pages.
        $inserted = 0;

        try {
            if ($mode !== 'import_only') {
                        $seeds = self::collect_seeds((int) Settings::get('keyword_seeds_per_run', 5));
                        $max_seeds_per_run = (int) Settings::get('keyword_seed_batch_limit', 10);
                        $adaptive = get_option('tmw_engine_adaptive_state', []);
                        $metrics = get_option('tmw_keyword_engine_metrics', []);

                        $now = time();
                        $recent_failures = $metrics['failures'] ?? 0;

                        $adaptive_active = false;

                        if (!empty($adaptive['reduced_until']) && $now < (int)$adaptive['reduced_until']) {
                            $adaptive_active = true;
                        }
                        elseif ($recent_failures > 2) {
                            // Activate reduced mode for 20 minutes
                            $adaptive = [
                                'mode' => 'reduced',
                                'reduced_until' => $now + (20 * MINUTE_IN_SECONDS),
                            ];
                            update_option('tmw_engine_adaptive_state', $adaptive);
                            $adaptive_active = true;
                        }
                        elseif (!empty($adaptive['reduced_until']) && $now >= (int)$adaptive['reduced_until']) {
                            // Recovery
                            delete_option('tmw_engine_adaptive_state');
                        }

                        if ($adaptive_active) {
                            $max_seeds_per_run = max(1, floor($max_seeds_per_run / 2));
                            Logs::warn('keywords', 'Adaptive throttling active', [
                                'adjusted_seed_limit' => $max_seeds_per_run,
                            ]);
                        }
                        $seeds = array_slice($seeds, 0, max(1, $max_seeds_per_run));
                        Logs::info('keywords', 'Seeds', ['count' => count($seeds), 'seeds' => array_slice($seeds, 0, 10)]);
                        $failures = 0;
                        $max_failures = 3;

                        // 2) Keyword suggestions from DataForSEO
                        foreach ($seeds as $seed) {
                            if ($inserted >= $new_limit) break;

                            $cache_key = 'tmw_seed_suggestions_' . md5($seed);
                            $cached = get_transient($cache_key);

                            if ($cached !== false) {
                                $res = $cached;
                            } else {
                                $suggestions_res = DataForSEO::keyword_suggestions(
                                    $seed,
                                    (int) Settings::get('keyword_suggestions_limit', 200)
                                );
                                $related_res = DataForSEO::related_keywords(
                                    $seed,
                                    1,
                                    (int) Settings::get('keyword_suggestions_limit', 200)
                                );

                                if (!empty($suggestions_res['ok']) || !empty($related_res['ok'])) {
                                    $merged_items = [];
                                    foreach ([$suggestions_res['items'] ?? [], $related_res['items'] ?? []] as $source_items) {
                                        foreach ((array) $source_items as $item) {
                                            if (!is_array($item)) {
                                                continue;
                                            }
                                            $kw = (string) ($item['keyword'] ?? '');
                                            if ($kw === '') {
                                                continue;
                                            }
                                            $merged_items[$kw] = $item;
                                        }
                                    }

                                    $res = [
                                        'ok' => true,
                                        'items' => array_values($merged_items),
                                    ];
                                    set_transient($cache_key, $res, HOUR_IN_SECONDS);
                                } else {
                                    $res = [
                                        'ok' => false,
                                        'error' => $suggestions_res['error'] ?? $related_res['error'] ?? 'keyword_discovery_failed',
                                    ];
                                }
                            }

                            if (!$res['ok']) {
                                $failures++;
                                Logs::warn('keywords', 'DataForSEO failed', ['seed' => $seed]);
                                Logs::warn('keywords', 'DataForSEO keyword_suggestions/related_keywords failed', ['seed' => $seed, 'error' => $res['error'] ?? '']);

                                if ($failures >= $max_failures) {
                                    Logs::error('keywords', 'Circuit breaker triggered');

                                    update_option('tmw_keyword_engine_breaker', [
                                        'last_triggered' => time(),
                                        'failure_count'  => $failures,
                                    ]);

                                    break;
                                }

                                usleep(250000);
                                continue;
                            }

                            $failures = 0;

                        $items = $res['items'] ?? [];
                        $existing_map = [];
                        $lookup_keywords = [];
                        foreach ($items as $it) {
                            $kw = is_array($it) ? (string)($it['keyword'] ?? '') : '';
                            if ($kw !== '') {
                                $lookup_keywords[$kw] = true;
                            }
                        }

                        $lookup_keywords = array_keys($lookup_keywords);
                        if (!empty($lookup_keywords)) {
                            $placeholders = implode(', ', array_fill(0, count($lookup_keywords), '%s'));
                            $existing_rows = $wpdb->get_results(
                                $wpdb->prepare(
                                    "SELECT id, keyword FROM {$cand_table} WHERE keyword IN ({$placeholders})",
                                    ...$lookup_keywords
                                ),
                                ARRAY_A
                            );

                            foreach ($existing_rows as $row) {
                                $existing_map[(string)$row['keyword']] = (int)$row['id'];
                            }
                        }

                        foreach ($items as $it) {
                            if ($inserted >= $new_limit) break;
                            $kw = is_array($it) ? (string)($it['keyword'] ?? '') : '';
                            if ($kw === '') continue;
            
                            $reason = null;
                            if (!KeywordValidator::is_relevant($kw, $reason)) {
                                continue;
                            }
            
                            $metrics = $it['keyword_info'] ?? [];
                            $vol = isset($metrics['search_volume']) ? (int)$metrics['search_volume'] : null;
                            $cpc = isset($metrics['cpc']) ? (float)$metrics['cpc'] : null;
                            $comp = isset($metrics['competition']) ? (float)$metrics['competition'] : null;
            
                            // Raw insert (ignore duplicates)
                            $wpdb->query($wpdb->prepare(
                                "INSERT IGNORE INTO {$raw_table} (keyword, source, source_ref, volume, cpc, competition, raw, discovered_at)
                                 VALUES (%s, %s, %s, %d, %f, %f, %s, %s)",
                                $kw, 'dataforseo_suggest', $seed,
                                (int)($vol ?? 0), (float)($cpc ?? 0), (float)($comp ?? 0),
                                wp_json_encode($it), current_time('mysql')
                            ));
            
                            // Candidate upsert
                            $canonical = KeywordValidator::normalize($kw);
                            $intent = KeywordValidator::infer_intent($kw);
            
                            // skip very low volume early (still store raw)
                            if ($vol !== null && $vol < $min_volume) continue;
            
                            $existing = $existing_map[$kw] ?? null;
                            if ($existing) {
                                // update sources
                                $wpdb->query($wpdb->prepare(
                                    "UPDATE {$cand_table} SET sources = CONCAT(IFNULL(sources,''), %s), updated_at=%s WHERE id=%d",
                                    "\n" . 'dataforseo_suggest:' . $seed,
                                    current_time('mysql'),
                                    (int)$existing
                                ));
                                continue;
                            }
            
                            $wpdb->insert($cand_table, [
                                'keyword' => $kw,
                                'canonical' => $canonical,
                                'status' => 'new',
                                'intent' => $intent,
                                'volume' => $vol,
                                'cpc' => $cpc,
                                'difficulty' => null,
                                'opportunity' => null,
                                'sources' => 'dataforseo_suggest:' . $seed,
                                'notes' => null,
                                'updated_at' => current_time('mysql'),
                            ], [
                                '%s', '%s', '%s', '%s', '%d', '%f', '%f', '%f', '%s', '%s', '%s'
                            ]);
            
                            $inserted++;
                        }

                        usleep(250000);
                    }
            } else {
                Logs::info('keywords', 'Discovery skipped (import_only mode)');
            }
        } finally {
            $runtime = round(microtime(true) - $job_started, 2);

            Logs::info('keywords', 'Cycle metrics', [
                'runtime_seconds' => $runtime,
                'inserted' => $inserted ?? 0,
                'failures' => $failures ?? 0,
            ]);

            update_option('tmw_keyword_engine_metrics', [
                'last_run'        => time(),
                'runtime_seconds' => $runtime,
                'inserted'        => $inserted ?? 0,
                'failures'        => $failures ?? 0,
            ]);

            delete_transient($lock_key);
        }

Logs::info('keywords', 'Inserted candidates', ['count' => $inserted]);

        // 3) KD refresh for candidates missing difficulty
        $to_score = $wpdb->get_col($wpdb->prepare(
            "SELECT keyword FROM {$cand_table} WHERE (difficulty IS NULL OR difficulty=0) AND status IN ('new','approved') ORDER BY updated_at DESC LIMIT %d",
            $kd_limit
        ));

        if (!empty($to_score)) {
            $to_score = array_values(array_unique($to_score));

            $placeholders = implode(',', array_fill(0, count($to_score), '%s'));
            $kw_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT keyword, volume, intent FROM {$cand_table} WHERE keyword IN ({$placeholders})",
                    ...$to_score
                ),
                ARRAY_A
            );

            $kw_map = [];
            foreach ($kw_rows as $kw_row) {
                $kw_map[(string)$kw_row['keyword']] = [
                    'volume' => isset($kw_row['volume']) ? (int)$kw_row['volume'] : 0,
                    'intent' => isset($kw_row['intent']) ? (string)$kw_row['intent'] : 'mixed',
                ];
            }

            $kd_res = DataForSEO::bulk_keyword_difficulty($to_score);
            if ($kd_res['ok']) {
                $map = $kd_res['map'] ?? [];
                $updated = 0;
                foreach ($to_score as $kw) {
                    $kwn = mb_strtolower($kw, 'UTF-8');
                    $kd = $map[$kwn] ?? null;
                    if ($kd === null) continue;

                    $vol = isset($kw_map[$kw]['volume']) ? (int)$kw_map[$kw]['volume'] : 0;
                    $intent = isset($kw_map[$kw]['intent']) ? (string)$kw_map[$kw]['intent'] : 'mixed';

                    // Auto reject too hard keywords early
                    $status = ($kd > $max_kd) ? 'rejected' : 'approved';
                    $opp = KDFilter::opportunity_score((float)$kd, $vol, $intent);

                    $wpdb->update($cand_table, [
                        'difficulty' => (float)$kd,
                        'opportunity' => $opp,
                        'status' => $status,
                        'notes' => ($kd > $max_kd) ? 'auto_reject:kD' : null,
                        'updated_at' => current_time('mysql'),
                    ], ['keyword' => $kw]);

                    $updated++;
                }
                Logs::info('keywords', 'KD refreshed', ['updated' => $updated, 'scored' => count($to_score)]);
            } else {
                Logs::warn('keywords', 'KD refresh failed', ['error' => $kd_res['error'] ?? '']);
            }
        }

        // 4) Build clusters (simple clustering by cluster_key)
        self::rebuild_clusters();

        // 5) Suggestion-first workflow: queue suggested pages only (no auto-creation).
        self::store_suggested_pages_from_clusters($pages_per_day);
        self::store_topic_suggestions($pages_per_day);

        Logs::info('keywords', 'Keyword cycle completed');
    }

    private static function collect_seeds(int $limit): array {
        $base = [
            'adult webcam chat',
            'live cam girls',
            'webcam chat rooms',
            'adult video chat',
            'cam to cam chat',
            'random adult chat',
            'private cam show',
            'live adult chat',
        ];

        // Add your top categories / tax terms as seeds (adaptive to theme).
        $terms = [];
        $taxes = get_taxonomies(['public' => true], 'names');
        foreach ($taxes as $tax) {
            if (strpos($tax, 'cat') === false && strpos($tax, 'category') === false) continue;
            $t = get_terms([
                'taxonomy' => $tax,
                'hide_empty' => false,
                'number' => 10,
                'orderby' => 'count',
                'order' => 'DESC',
            ]);
            if (is_wp_error($t) || empty($t)) continue;
            foreach ($t as $term) {
                if (!isset($term->name)) continue;
                $name = trim((string)$term->name);
                if ($name !== '') $terms[] = $name . ' cam';
            }
        }

        // Competitor rotation: pull 1 competitor's ranked keywords as extra seeds.
        $competitors = Settings::competitor_domains();
        $comp_seeds = [];
        if (!empty($competitors) && DataForSEO::is_configured()) {
            $rot = (int) get_option('tmwseo_engine_competitor_rot', 0);
            $domain = $competitors[$rot % count($competitors)];
            update_option('tmwseo_engine_competitor_rot', $rot + 1, false);

            $rk = DataForSEO::ranked_keywords($domain, 50);
            if ($rk['ok']) {
                foreach (($rk['items'] ?? []) as $it) {
                    $kw = '';
                    if (is_array($it) && isset($it['keyword_data']['keyword'])) {
                        $kw = (string)$it['keyword_data']['keyword'];
                    }
                    if ($kw !== '') $comp_seeds[] = $kw;
                }
            }
        }

        $all = array_merge($base, $terms, $comp_seeds);
        $all = array_values(array_unique(array_filter(array_map('strval', $all))));

        // Keep first N but also mix in some longer-tail (shuffle tail).
        $head = array_slice($all, 0, max(1, $limit));
        $tail = array_slice($all, $limit);
        shuffle($tail);
        $mixed = array_merge($head, array_slice($tail, 0, max(0, $limit - count($head))));
        $mixed = array_slice(array_values(array_unique($mixed)), 0, max(1, $limit));
        return $mixed;
    }

    private static function rebuild_clusters(): void {
        global $wpdb;
        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';

        $rows = $wpdb->get_results(
            "SELECT keyword, volume, difficulty, opportunity, intent
             FROM {$cand_table}
             WHERE status='approved' AND opportunity IS NOT NULL
             ORDER BY opportunity DESC
             LIMIT 2000",
            ARRAY_A
        );

        if (empty($rows)) return;

        $clusters = [];

        foreach ($rows as $r) {
            $kw = (string)($r['keyword'] ?? '');
            if ($kw === '') continue;
            $key = KeywordValidator::cluster_key($kw);

            if (!isset($clusters[$key])) {
                $clusters[$key] = [
                    'keywords' => [],
                    'total_volume' => 0,
                    'sum_kd' => 0.0,
                    'kd_n' => 0,
                    'best_kw' => $kw,
                    'best_opp' => (float)($r['opportunity'] ?? 0),
                ];
            }

            $clusters[$key]['keywords'][] = $kw;
            $clusters[$key]['total_volume'] += (int)($r['volume'] ?? 0);

            if (!empty($r['difficulty'])) {
                $clusters[$key]['sum_kd'] += (float)$r['difficulty'];
                $clusters[$key]['kd_n'] += 1;
            }

            $opp = (float)($r['opportunity'] ?? 0);
            if ($opp > $clusters[$key]['best_opp']) {
                $clusters[$key]['best_opp'] = $opp;
                $clusters[$key]['best_kw'] = $kw;
            }
        }

        $cluster_map = [];
        $cluster_keys = array_values(array_unique(array_keys($clusters)));
        if (!empty($cluster_keys)) {
            $placeholders = implode(', ', array_fill(0, count($cluster_keys), '%s'));
            $existing_clusters = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, cluster_key FROM {$cluster_table} WHERE cluster_key IN ({$placeholders})",
                    ...$cluster_keys
                ),
                ARRAY_A
            );

            foreach ($existing_clusters as $existing_cluster) {
                $cluster_map[(string)$existing_cluster['cluster_key']] = (int)$existing_cluster['id'];
            }
        }

        // Upsert clusters
        foreach ($clusters as $key => $c) {
            $avg_kd = ($c['kd_n'] > 0) ? round($c['sum_kd'] / $c['kd_n'], 2) : null;
            $opportunity = (float)$c['best_opp'];

            $exists = $cluster_map[$key] ?? null;
            if ($exists) {
                $wpdb->update($cluster_table, [
                    'representative' => $c['best_kw'],
                    'keywords' => wp_json_encode(array_values(array_unique($c['keywords']))),
                    'total_volume' => (int)$c['total_volume'],
                    'avg_difficulty' => $avg_kd,
                    'opportunity' => $opportunity,
                    'updated_at' => current_time('mysql'),
                ], ['id' => (int)$exists]);
            } else {
                $wpdb->insert($cluster_table, [
                    'cluster_key' => $key,
                    'representative' => $c['best_kw'],
                    'keywords' => wp_json_encode(array_values(array_unique($c['keywords']))),
                    'total_volume' => (int)$c['total_volume'],
                    'avg_difficulty' => $avg_kd,
                    'opportunity' => $opportunity,
                    'page_id' => null,
                    'status' => 'new',
                    'updated_at' => current_time('mysql'),
                ], [
                    '%s', '%s', '%s', '%d', '%f', '%f', '%d', '%s', '%s'
                ]);
            }
        }

        Logs::info('keywords', 'Clusters rebuilt', ['clusters' => count($clusters)]);
    }


    private static function store_topic_suggestions(int $limit): void {
        $models = get_posts([
            'post_type' => 'model',
            'post_status' => 'publish',
            'posts_per_page' => max(1, $limit),
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids',
        ]);

        if (!is_array($models) || empty($models)) {
            return;
        }

        $engine = new \TMW_Topic_Engine();
        $stored = 0;
        foreach ($models as $model_id) {
            $stored += $engine->queue_topic_suggestions_for_model((int) $model_id);
        }

        Logs::info('keywords', '[TMW-KW] Topic suggestions queued', ['count' => $stored]);
    }

    private static function store_suggested_pages_from_clusters(int $limit): void {
        global $wpdb;
        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';

        if ($limit <= 0) return;

        $clusters = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$cluster_table}
             WHERE status='new'
             ORDER BY opportunity DESC, total_volume DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);

        if (empty($clusters)) {
            Logs::info('keywords', '[TMW-KW] No clusters available for suggested pages');
            return;
        }

        $db = new \TMWSEO\Engine\Opportunities\OpportunityDatabase();
        $rows = [];
        foreach ($clusters as $c) {
            $keyword = (string)($c['representative'] ?? '');
            if ($keyword === '') {
                continue;
            }

            $rows[] = [
                'keyword' => strtolower($keyword),
                'search_volume' => (int) ($c['total_volume'] ?? 0),
                'difficulty' => (float) ($c['avg_difficulty'] ?? 0),
                'opportunity_score' => (float) ($c['opportunity'] ?? 0),
                'competitor_url' => 'cluster:' . (int) ($c['id'] ?? 0),
                'source' => 'keyword_cycle',
                'type' => 'keyword_cluster',
                'recommended_action' => 'Create Draft',
            ];
        }

        $stored = $db->store($rows);

        Logs::info('keywords', '[TMW-KW] Suggested pages queued from keyword clusters', [
            'count' => $stored,
        ]);
    }
}
